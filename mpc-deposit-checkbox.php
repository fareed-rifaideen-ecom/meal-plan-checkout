<?php
/**
 * Plugin Name: MPC Deposit Checkbox
 * Description: Companion plugin for Meal Plan Custom Checkout. Adds a self-declared
 *              AED 150 thermal bag deposit checkbox in Step 1. Checked by default.
 *              Uses a UUID transient to pass the customer's intent to the backend
 *              without modifying meal-plan-checkout.php.
 *              Unchecked orders are flagged with an order note for FOH follow-up.
 * Version:     1.0
 * Author:      Fareed M Rifaideen
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// SECTION 1 — AJAX: Save deposit intent to a short-lived transient
//
// Called by JS before mpcSubmitOrder() fires. The UUID is generated
// once per page load in JS (sessionStorage) and sent here alongside
// the 1/0 intent flag. The transient key is mpc_deposit_{uuid}.
// TTL: 120 seconds — enough for the order AJAX round-trip.
// ============================================================
add_action( 'wp_ajax_nopriv_mpc_save_deposit_intent', 'mpc_save_deposit_intent' );
add_action( 'wp_ajax_mpc_save_deposit_intent',        'mpc_save_deposit_intent' );

function mpc_save_deposit_intent() {
    check_ajax_referer( 'mpc_checkout_nonce', 'nonce' );

    $uuid    = sanitize_text_field( $_POST['uuid'] ?? '' );
    $include = intval( $_POST['include_deposit'] ?? 1 );

    if ( empty( $uuid ) || strlen( $uuid ) > 64 ) {
        wp_send_json_error( 'Invalid UUID.' );
    }

    set_transient( 'mpc_deposit_' . $uuid, $include ? 1 : 0, 120 );
    wp_send_json_success();
}


// ============================================================
// SECTION 2 — BACKEND HOOK: Apply the AED 150 fee during order creation
//
// woocommerce_before_calculate_totals fires DURING $order->calculate_totals()
// inside mpc_process_order(). We read the transient written by Section 1,
// apply the fee if intent=1, or write a FOH flag note if intent=0.
//
// Double-injection guard prevents the fee being added twice if
// calculate_totals() is called more than once in the same request.
// ============================================================
add_action( 'woocommerce_before_calculate_totals', 'mpc_deposit_apply_fee', 10, 1 );

function mpc_deposit_apply_fee( $order ) {

    // Strictly isolate to the Meal Plan checkout AJAX action only.
    if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'mpc_process_order' ) {
        return;
    }

    $uuid = sanitize_text_field( $_POST['deposit_uuid'] ?? '' );
    if ( empty( $uuid ) ) {
        return;
    }

    $transient_key = 'mpc_deposit_' . $uuid;
    $intent        = get_transient( $transient_key );

    // Transient missing — default to 1 (safest: charge deposit, avoids missed payments).
    if ( $intent === false ) {
        $intent = 1;
    }

    // Clean up immediately — single-use.
    delete_transient( $transient_key );

    if ( intval( $intent ) === 0 ) {
        // Customer unchecked — flag for FOH review. Do NOT add fee.
        $order->update_meta_data( '_mpc_deposit_waived', '1' );
        $order->add_order_note(
            '⚠️ FOH ACTION REQUIRED: Customer unchecked the thermal bag deposit checkbox. ' .
            'Please verify if this is a new subscriber and collect AED 150 deposit manually if applicable.'
        );
        return;
    }

    // Double-injection guard.
    foreach ( $order->get_fees() as $existing_fee ) {
        if ( $existing_fee->get_name() === 'Thermal Bag Deposit (Refundable)' ) {
            return;
        }
    }

    // Add the AED 150 deposit fee.
    $fee = new WC_Order_Item_Fee();
    $fee->set_name( 'Thermal Bag Deposit (Refundable)' );
    $fee->set_amount( 150 );
    $fee->set_total( 150 );
    $fee->set_tax_status( 'none' );
    $fee->set_tax_class( '' );

    $order->add_item( $fee );
    $order->update_meta_data( '_mpc_deposit_included', '1' );
}


// ============================================================
// SECTION 3 — WC ADMIN: Deposit status column in Orders list
//
// Shows a quick at-a-glance indicator on the WC Orders screen
// so FOH can instantly spot waived-deposit orders.
// ============================================================
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'mpc_deposit_add_order_column' );
add_filter( 'manage_edit-shop_order_columns',            'mpc_deposit_add_order_column' );

function mpc_deposit_add_order_column( $columns ) {
    $new = array();
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'order_total' ) {
            $new['mpc_deposit_status'] = 'Deposit';
        }
    }
    return $new;
}

add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'mpc_deposit_render_order_column', 10, 2 );
add_action( 'manage_shop_order_posts_custom_column',            'mpc_deposit_render_order_column', 10, 2 );

function mpc_deposit_render_order_column( $column, $order_or_id ) {
    if ( $column !== 'mpc_deposit_status' ) return;

    $order = is_object( $order_or_id ) ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order ) return;

    $included = $order->get_meta( '_mpc_deposit_included' );
    $waived   = $order->get_meta( '_mpc_deposit_waived' );

    if ( $included === '1' ) {
        echo '<span style="color:#16a34a;font-weight:bold;" title="AED 150 deposit included">&#10003; Included</span>';
    } elseif ( $waived === '1' ) {
        echo '<span style="color:#dc2626;font-weight:bold;" title="Customer unchecked deposit — FOH review needed">&#9888; Waived</span>';
    } else {
        echo '<span style="color:#94a3b8;">—</span>';
    }
}


// ============================================================
// SECTION 4 — FRONTEND: Inject checkbox UI + live summary update
//
// Runs only on pages that contain the [meal_plan_checkout] shortcode.
//
// Strategy (zero-edit to meal-plan-checkout.php):
//   1. Generate a UUID once per page load, store in sessionStorage.
//   2. Watch #mpc-summary-content via MutationObserver — when a plan
//      tile is selected, the parent plugin updates this div. We use
//      that as the trigger to show/hide the deposit checkbox row.
//   3. Checkbox is injected above #btn-next-1 inside .mpc-nav-buttons.
//   4. On checkbox change: fire AJAX to save intent transient, and
//      update the summary panel total + badge live.
//   5. Intercept #btn-next-1 click (capture phase, before existing
//      onclick handler) to save the intent synchronously with
//      async/await before the wizard advances.
//   6. Also intercept the final "Place Order" buttons (btn-next-2
//      for juice plans, btn-next-3 for meal plans) the same way,
//      appending deposit_uuid to the mpcSubmitOrder FormData by
//      overriding window.fetch temporarily.
// ============================================================
add_action( 'wp_footer', 'mpc_deposit_inject_ui', 999 );

function mpc_deposit_inject_ui() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'meal_plan_checkout' ) ) {
        return;
    }

    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce    = wp_create_nonce( 'mpc_checkout_nonce' );
    ?>
    <style>
        #mpc-deposit-row {
            display: none;
            margin: 0 0 18px 0;
            padding: 14px 16px;
            background: #f4fdf4;
            border: 1px solid #379237;
            border-radius: 8px;
        }
        #mpc-deposit-row label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            font-weight: normal;
            margin: 0;
            line-height: 1.5;
            color: #166534;
        }
        #mpc-deposit-row input[type="checkbox"] {
            transform: scale(1.3);
            margin-top: 3px;
            flex-shrink: 0;
            accent-color: #379237;
        }
        #mpc-deposit-badge {
            display: none;
            margin-top: 12px;
            padding: 10px 12px;
            background: #f0fdf4;
            border: 1px dashed #379237;
            border-radius: 6px;
            font-size: 0.88em;
            color: #166534;
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {

        // ── UUID: generated once per page load, persisted in sessionStorage ──
        let depositUUID = sessionStorage.getItem('mpc_deposit_uuid');
        if ( ! depositUUID ) {
            depositUUID = (typeof crypto !== 'undefined' && crypto.randomUUID)
                ? crypto.randomUUID()
                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    var r = Math.random() * 16 | 0;
                    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                });
            sessionStorage.setItem('mpc_deposit_uuid', depositUUID);
        }

        let depositChecked = true; // default: checked

        const ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        const nonce   = '<?php echo esc_js( $nonce ); ?>';

        // ── Refs ──
        const summaryDiv  = document.getElementById('mpc-summary-content');
        const navButtons  = document.querySelector('#mpc-step-1 .mpc-nav-buttons');

        if ( ! summaryDiv || ! navButtons ) return;

        // ── Build checkbox row (hidden until plan selected) ──
        const depositRow = document.createElement('div');
        depositRow.id = 'mpc-deposit-row';
        depositRow.innerHTML = `
            <label>
                <input type="checkbox" id="mpc_deposit_checkbox" checked>
                <span>
                    <strong>I am a new customer</strong> — include AED 150 refundable thermal bag deposit in my order.
                </span>
            </label>
        `;
        navButtons.parentNode.insertBefore( depositRow, navButtons );

        const checkbox = document.getElementById('mpc_deposit_checkbox');

        // ── Save intent to backend (fire-and-forget, returns promise) ──
        function saveDepositIntent( include ) {
            const fd = new URLSearchParams();
            fd.append('action',          'mpc_save_deposit_intent');
            fd.append('nonce',           nonce);
            fd.append('uuid',            depositUUID);
            fd.append('include_deposit', include ? '1' : '0');
            return fetch( ajaxUrl, { method: 'POST', body: fd });
        }

        // ── Update summary panel total & badge ──
        function updateSummary() {
            const html = summaryDiv.innerHTML;
            if ( ! html.includes('AED') ) return;

            // Read the base plan price that the parent plugin wrote
            // It is stored on checkoutData.planPrice (set by mpcSelectPlan)
            const basePrice = (typeof checkoutData !== 'undefined') ? parseFloat(checkoutData.planPrice) : 0;
            if ( ! basePrice ) return;

            const total      = depositChecked ? basePrice + 150 : basePrice;
            const totalFormatted = total.toFixed(2);

            // Replace just the price figure in the existing summary HTML
            const newHtml = html.replace(
                /AED\s+[\d,]+(\.\d+)?/,
                'AED ' + totalFormatted
            );

            summaryObserver.disconnect();
            summaryDiv.innerHTML = newHtml;

            // Inject or remove deposit badge
            let badge = document.getElementById('mpc-deposit-badge');
            if ( depositChecked ) {
                if ( ! badge ) {
                    badge = document.createElement('div');
                    badge.id = 'mpc-deposit-badge';
                    badge.innerHTML = '<strong>+ AED 150</strong> Thermal Bag Deposit (Refundable) included';
                    summaryDiv.appendChild(badge);
                }
                badge.style.display = 'block';
            } else {
                if ( badge ) badge.style.display = 'none';
            }

            summaryObserver.observe( summaryDiv, { childList: true, subtree: true });
        }

        // ── Checkbox change handler ──
        checkbox.addEventListener('change', function () {
            depositChecked = this.checked;
            saveDepositIntent( depositChecked );
            updateSummary();
        });

        // ── MutationObserver: show deposit row when plan tile is selected ──
        const summaryObserver = new MutationObserver(function () {
            const html = summaryDiv.innerHTML;
            if ( html.includes('AED') && html.includes('Plan:') ) {
                depositRow.style.display = 'block';
                updateSummary();
            }
        });
        summaryObserver.observe( summaryDiv, { childList: true, subtree: true });

        // ── Fetch interceptor: append deposit_uuid to mpc_process_order calls ──
        // The parent plugin calls fetch() inside mpcSubmitOrder(). We wrap
        // window.fetch once to inject deposit_uuid into that specific call.
        const _originalFetch = window.fetch.bind(window);
        window.fetch = async function ( resource, options ) {

            if ( options && options.body instanceof URLSearchParams ) {
                const action = options.body.get('action');

                if ( action === 'mpc_process_order' ) {
                    // Ensure the latest intent is saved before we append the UUID.
                    try {
                        await saveDepositIntent( depositChecked );
                    } catch(e) {}

                    // Append the UUID so the backend hook can read the transient.
                    options.body.append('deposit_uuid', depositUUID);

                    // Rotate UUID after use so a page-refresh gets a fresh one.
                    depositUUID = (typeof crypto !== 'undefined' && crypto.randomUUID)
                        ? crypto.randomUUID()
                        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                            var r = Math.random() * 16 | 0;
                            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                        });
                    sessionStorage.setItem('mpc_deposit_uuid', depositUUID);
                }
            }

            return _originalFetch( resource, options );
        };

    }); // end DOMContentLoaded
    </script>
    <?php
}
// END OF FILE
