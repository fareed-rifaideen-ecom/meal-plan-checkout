<?php
/**
 * Plugin Name: MPC Thermal Bag Deposit
 * Description: Companion plugin for Meal Plan Custom Checkout. Adds a refundable AED 150
 *              thermal bag deposit for first-time PAID subscribers only. Excludes pending/
 *              abandoned checkout rows. Does not modify the Meal Plan Custom Checkout plugin.
 * Version:     1.1
 * Author:      Fareed M Rifaideen
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ============================================================
// SHARED HELPER: Has this user ever completed a paid plan?
//
// Deliberately excludes 'pending' status — a pending row means
// the customer started checkout but never completed payment.
// We only treat someone as a returning subscriber when their
// plan has reached active, paused, or inactive (expired) state,
// all of which require a completed payment.
// ============================================================
function mpc_user_has_paid_subscription( $user_id ) {
    global $wpdb;
    $count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions
         WHERE user_id = %d
         AND status IN ('active', 'paused', 'inactive')",
        intval( $user_id )
    ) );
    return intval( $count ) > 0;
}


// ============================================================
// SECTION 1 — BACKEND: Inject AED 150 Fee Into Order Totals
//
// WHY THIS HOOK:
//   mpc_process_order() calls $order->calculate_totals() after
//   creating the order. woocommerce_before_calculate_totals fires
//   DURING that call — so any fee we add here is automatically
//   locked into the final total that gets sent to N-Genius.
//
//   The original woocommerce_new_order hook fires BEFORE
//   add_product() and calculate_totals(), so the fee was never
//   included in the payment amount. This hook fixes that.
// ============================================================
add_action( 'woocommerce_before_calculate_totals', 'mpc_inject_thermal_bag_deposit', 10, 1 );

function mpc_inject_thermal_bag_deposit( $order ) {

    // 1. Strictly isolate to the Meal Plan Custom Checkout AJAX action only.
    if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'mpc_process_order' ) {
        return;
    }

    // 2. Double-injection guard — prevent fee being added twice if
    //    calculate_totals() is called more than once in the same request.
    foreach ( $order->get_fees() as $existing_fee ) {
        if ( $existing_fee->get_name() === 'Thermal Bag Deposit (Refundable)' ) {
            return;
        }
    }

    // 3. Resolve the customer's user ID.
    //    By the time calculate_totals() is called in mpc_process_order(),
    //    new users have already been created and set via wp_set_current_user().
    $user_id = $order->get_customer_id();
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) return;

    // 4. Only skip the deposit if the user has a PAID subscription history.
    //    Pending rows (abandoned checkouts) do NOT count as prior subscribers.
    if ( mpc_user_has_paid_subscription( $user_id ) ) {
        return;
    }

    // 5. Add the fee. Do NOT call calculate_totals() or save() here —
    //    we are already inside the totals pass. WooCommerce picks this up automatically.
    $fee = new WC_Order_Item_Fee();
    $fee->set_name( 'Thermal Bag Deposit (Refundable)' );
    $fee->set_amount( 150 );
    $fee->set_total( 150 );
    $fee->set_tax_status( 'none' );
    $fee->set_tax_class( '' );

    $order->add_item( $fee );
}


// ============================================================
// SECTION 2 — AJAX ENDPOINT: Check Subscriber Status Post-Login
//
// Called by the frontend JS after a successful AJAX login.
// Returns is_returning: true/false so the UI can update the
// deposit notice without a page reload.
// ============================================================
add_action( 'wp_ajax_mpc_check_subscriber_status', 'mpc_check_subscriber_status' );

function mpc_check_subscriber_status() {
    check_ajax_referer( 'mpc_checkout_nonce', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_success( array( 'is_returning' => false ) );
        return;
    }

    wp_send_json_success( array(
        'is_returning' => mpc_user_has_paid_subscription( get_current_user_id() )
    ) );
}


// ============================================================
// SECTION 3 — FRONTEND: Deposit Notice in Summary Panel
//
// FIXES vs original submitted code:
//   - observer.disconnect() before innerHTML write prevents re-trigger loop
//   - observer.observe() re-connected after write for subsequent plan changes
//   - Stale warning removed before re-injection on plan switch
//   - AJAX login bridge re-checks status and corrects price if returning customer
//   - All three status checks use mpc_user_has_paid_subscription() helper
//     so the logic is consistent across backend, AJAX, and frontend
// ============================================================
add_action( 'wp_footer', 'mpc_inject_thermal_bag_ui', 999 );

function mpc_inject_thermal_bag_ui() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'meal_plan_checkout' ) ) {
        return;
    }

    // Server-side check using the shared helper — consistent with backend logic.
    // Guests default to true (deposit shown). JS corrects this if they log in
    // mid-checkout as a returning customer via the AJAX login form.
    $is_new_user = 'true';
    if ( is_user_logged_in() ) {
        if ( mpc_user_has_paid_subscription( get_current_user_id() ) ) {
            $is_new_user = 'false';
        }
    }
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {

        // PHP-rendered at page load. Updated dynamically via AJAX if the user
        // logs in mid-checkout using the wizard's AJAX login form.
        let isNewSubscriber = <?php echo $is_new_user; ?>;

        const summaryDiv = document.getElementById('mpc-summary-content');
        if (!summaryDiv) return;

        // ---------------------------------------------------
        // injectDepositUI()
        // Reads the current plan price from the summary panel,
        // adds AED 150 to the displayed total, and appends the
        // explanatory deposit notice card below it.
        // ---------------------------------------------------
        function injectDepositUI() {
            if (!isNewSubscriber) return;
            if (document.getElementById('thermal-bag-warning')) return;

            let html = summaryDiv.innerHTML;
            if (!html.includes('AED')) return;

            let priceMatch = html.match(/AED\s+([\d,]+(\.\d+)?)/);
            if (!priceMatch) return;

            let basePrice = parseFloat(priceMatch[1].replace(/,/g, ''));
            let newTotal  = basePrice + 150;
            let newHtml   = html.replace(priceMatch[0], 'AED ' + newTotal.toFixed(2));

            newHtml += `
                <div id="thermal-bag-warning" style="
                    background: #f4fdf4;
                    border: 1px solid #379237;
                    padding: 12px;
                    margin-top: 15px;
                    border-radius: 6px;
                    font-size: 0.9em;
                    color: #166534;
                ">
                    <strong>Includes AED 150 Thermal Bag Deposit</strong><br>
                    <span style="font-size:0.9em;opacity:0.9;">
                        A fully refundable deposit added for first-time subscribers.
                    </span>
                </div>`;

            // Disconnect BEFORE writing back to prevent this observer from
            // firing again on the innerHTML change we are about to make.
            observer.disconnect();
            summaryDiv.innerHTML = newHtml;

            // Re-connect so future plan switches (user clicks a different plan tile)
            // are still observed and the total gets updated with the new plan price.
            observer.observe(summaryDiv, { childList: true, subtree: true });
        }

        // ---------------------------------------------------
        // MutationObserver — watches the summary panel for changes.
        // The Meal Plan plugin updates this div whenever the user
        // selects a plan tile (via mpcSelectPlan()).
        // ---------------------------------------------------
        const observer = new MutationObserver(function () {
            // Remove any stale deposit warning from a previous plan selection
            // so it gets re-injected cleanly with the new plan's correct price.
            let staleWarning = document.getElementById('thermal-bag-warning');
            if (staleWarning) staleWarning.remove();
            injectDepositUI();
        });

        observer.observe(summaryDiv, { childList: true, subtree: true });

        // ---------------------------------------------------
        // AJAX Login Bridge
        // The Meal Plan plugin replaces #mpc-login-section innerHTML
        // on successful AJAX login. We detect that DOM change and
        // re-check the user's subscriber status via a lightweight
        // AJAX call to keep isNewSubscriber accurate in real time.
        // ---------------------------------------------------
        const loginSection = document.getElementById('mpc-login-section');
        if (loginSection) {

            const loginObserver = new MutationObserver(function () {
                // Only needs to run once per login event.
                loginObserver.disconnect();

                fetch('<?php echo admin_url( "admin-ajax.php" ); ?>', {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'mpc_check_subscriber_status',
                        nonce:  '<?php echo wp_create_nonce( "mpc_checkout_nonce" ); ?>'
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.data.is_returning) {
                        // Returning customer logged in — remove the deposit UI.
                        isNewSubscriber = false;

                        let warning = document.getElementById('thermal-bag-warning');
                        if (warning) warning.remove();

                        // Correct the displayed total if it was already inflated by 150.
                        let existingHtml = summaryDiv.innerHTML;
                        let match = existingHtml.match(/AED\s+([\d,]+(\.\d+)?)/);
                        if (match) {
                            let displayed = parseFloat(match[1].replace(/,/g, ''));
                            let corrected = displayed - 150;
                            if (corrected > 0) {
                                observer.disconnect();
                                summaryDiv.innerHTML = existingHtml.replace(
                                    match[0], 'AED ' + corrected.toFixed(2)
                                );
                                observer.observe(summaryDiv, { childList: true, subtree: true });
                            }
                        }
                    }
                    // is_returning = false means still a first-timer — no change needed.
                })
                .catch(function () {
                    // Network failure — backend is the source of truth, fail silently.
                });
            });

            loginObserver.observe(loginSection, { childList: true });
        }

    }); // end DOMContentLoaded
    </script>
    <?php
}
// END OF FILE
