<?php
/**
 * Plugin Name: Meal Plan Custom Checkout
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/meal-plan-checkout
 * Description: A companion plugin that provides a streamlined custom checkout wizard with login, auto-fill, direct payment routing, and coupon-based discount tiers. (Post-Payment Subscription Provisioning, Options L1/L3 Login UI & Per-Plan Logistics)
 * Version: 4.3.0
 * Author: By RM Dev Team | Customised by Fareed M Rifaideen
 * Author URI: https://fareed-rifaideen.netlify.app
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 0. PER-PLAN SUBSCRIPTION LOGISTICS SCHEMA & PROFILE HELPER
// ==========================================
function mpc_ensure_subscription_logistics_columns() {
    if ( function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
        cmp_ensure_subscription_logistics_columns();
    }
    if ( function_exists( 'cmp_ensure_prep_timing_column' ) ) {
        cmp_ensure_prep_timing_column();
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_subs ) );
    if ( $table_exists !== $table_subs ) {
        return;
    }

    $existing_cols = $wpdb->get_col( "DESC {$table_subs}", 0 );
    if ( ! is_array( $existing_cols ) ) {
        return;
    }

    $columns_to_add = array(
        'delivery_method'  => "ALTER TABLE {$table_subs} ADD COLUMN delivery_method VARCHAR(100) DEFAULT ''",
        'pickup_location'  => "ALTER TABLE {$table_subs} ADD COLUMN pickup_location VARCHAR(255) DEFAULT ''",
        'delivery_timing'  => "ALTER TABLE {$table_subs} ADD COLUMN delivery_timing VARCHAR(100) DEFAULT ''",
        'time_slot'        => "ALTER TABLE {$table_subs} ADD COLUMN time_slot VARCHAR(100) DEFAULT ''",
        'allergies'        => "ALTER TABLE {$table_subs} ADD COLUMN allergies TEXT NULL",
        'delivery_address' => "ALTER TABLE {$table_subs} ADD COLUMN delivery_address TEXT NULL",
        'prep_timing'      => "ALTER TABLE {$table_subs} ADD COLUMN prep_timing VARCHAR(30) DEFAULT 'auto'",
    );

    foreach ( $columns_to_add as $col_name => $alter_sql ) {
        if ( ! in_array( $col_name, $existing_cols, true ) ) {
            $wpdb->query( $alter_sql );
        }
    }
}

function mpc_record_user_logistics_profile( $user_id, $sub_id, $profile_data = array(), $update_default_meta = true ) {
    if ( function_exists( 'cmp_save_user_logistics_profile_history' ) ) {
        cmp_save_user_logistics_profile_history( $user_id, $sub_id, $profile_data, $update_default_meta );
        return;
    }

    $user_id = intval( $user_id );
    $sub_id  = intval( $sub_id );
    if ( $user_id <= 0 ) {
        return;
    }

    $timing    = sanitize_text_field( $profile_data['timing'] ?? '' );
    $time_slot = sanitize_text_field( $profile_data['time_slot'] ?? '' );
    $allergies = sanitize_textarea_field( $profile_data['allergies'] ?? '' );
    $method    = sanitize_text_field( $profile_data['method'] ?? '' );
    $pickup    = sanitize_text_field( $profile_data['pickup'] ?? '' );
    $address   = sanitize_text_field( $profile_data['address'] ?? '' );
    $plan_name = sanitize_text_field( $profile_data['plan_name'] ?? '' );

    if ( $update_default_meta ) {
        if ( ! empty( $timing ) )    update_user_meta( $user_id, 'delivery_timing', $timing );
        if ( ! empty( $time_slot ) ) update_user_meta( $user_id, 'time_slot', $time_slot );
        update_user_meta( $user_id, 'allergies', $allergies );
        if ( ! empty( $method ) )    update_user_meta( $user_id, 'delivery_method', $method );
        if ( ! empty( $pickup ) )    update_user_meta( $user_id, 'pickup_location', $pickup );
    }

    $profiles = get_user_meta( $user_id, '_cmp_saved_logistics_profiles', true );
    if ( ! is_array( $profiles ) ) {
        $profiles = array();
    }

    $profile_key = 'sub_' . ( $sub_id > 0 ? $sub_id : md5( $plan_name . '|' . $timing . '|' . $time_slot ) );
    $profiles[ $profile_key ] = array(
        'sub_id'     => $sub_id,
        'plan_name'  => $plan_name ?: ( 'Plan #' . $sub_id ),
        'method'     => $method,
        'pickup'     => $pickup,
        'timing'     => $timing,
        'time_slot'  => $time_slot,
        'allergies'  => $allergies,
        'address'    => $address,
        'updated_at' => current_time( 'mysql' ),
    );

    if ( count( $profiles ) > 15 ) {
        $profiles = array_slice( $profiles, -15, 15, true );
    }

    update_user_meta( $user_id, '_cmp_saved_logistics_profiles', $profiles );
}

// ==========================================
// 0.5 WP ADMIN: MEAL PORTAL > CHECKOUT SETTINGS (OPTIONS L1 & L3 TOGGLE)
// ==========================================
add_action( 'admin_menu', 'mpc_register_checkout_settings_submenu', 65 );
function mpc_register_checkout_settings_submenu() {
    $parent_slug = 'cmp-menu-manager';
    if ( function_exists( 'cmp_get_meal_portal_parent_slug' ) ) {
        $parent_slug = cmp_get_meal_portal_parent_slug();
    }

    add_submenu_page(
        $parent_slug,
        'Meal Plan Checkout Settings',
        'Checkout Settings',
        'read',
        'cmp-checkout-settings',
        'mpc_render_checkout_settings_admin_page'
    );
}

function mpc_render_checkout_settings_admin_page() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) {
        wp_die( 'Access Denied: You do not have permission to manage Checkout Settings.' );
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $notice     = '';

    // 1. Save Checkout Settings
    if ( isset( $_POST['mpc_save_checkout_settings'] ) && check_admin_referer( 'mpc_checkout_settings_action', 'mpc_checkout_settings_nonce' ) ) {
        $login_mode = sanitize_text_field( wp_unslash( $_POST['mpc_checkout_login_mode'] ?? 'l1' ) );
        if ( ! in_array( $login_mode, array( 'l1', 'l3', 'both', 'step2' ), true ) ) {
            $login_mode = 'l1';
        }
        $autofill_default = isset( $_POST['mpc_checkout_autofill_default'] ) ? '1' : '0';
        $email_guard      = isset( $_POST['mpc_checkout_email_guard'] ) ? '1' : '0';

        update_option( 'mpc_checkout_login_mode', $login_mode );
        update_option( 'mpc_checkout_autofill_default', $autofill_default );
        update_option( 'mpc_checkout_email_guard', $email_guard );

        $notice = '<div class="notice notice-success is-dismissible"><p><strong>Checkout Settings saved successfully.</strong></p></div>';
    }

    // 2. Purge Orphaned Pending Subscriptions (from legacy unpaid/failed/cancelled checkouts)
    if ( isset( $_POST['mpc_purge_pending_subs'] ) && check_admin_referer( 'mpc_purge_pending_action', 'mpc_purge_pending_nonce' ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            $deleted_count = $wpdb->query( "DELETE FROM {$table_subs} WHERE status = 'pending'" );
            $notice        = '<div class="notice notice-success is-dismissible"><p>Cleaned up <strong>' . intval( $deleted_count ) . '</strong> unpaid/pending subscription record(s).</p></div>';
        }
    }

    $current_login_mode = get_option( 'mpc_checkout_login_mode', 'l1' );
    $autofill_default   = get_option( 'mpc_checkout_autofill_default', '1' );
    $email_guard        = get_option( 'mpc_checkout_email_guard', '1' );

    $table_exists        = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_subs ) ) === $table_subs );
    $pending_subs_count  = $table_exists ? intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$table_subs} WHERE status = 'pending'" ) ) : 0;
    ?>
    <style>
        .mpc-admin-wrap { max-width: 960px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .mpc-admin-header { background: linear-gradient(135deg, #1B5E20 0%, #379237 100%); color: #fff; padding: 22px 28px; border-radius: 12px; margin-bottom: 22px; box-shadow: 0 4px 14px rgba(27,94,32,0.14); }
        .mpc-admin-header h1 { color: #fff; margin: 0; font-size: 1.6rem; font-weight: 800; }
        .mpc-admin-header p { color: #DCECC9; margin: 5px 0 0 0; font-size: 0.93rem; }
        .mpc-admin-card { background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 24px 28px; margin-bottom: 22px; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
        .mpc-admin-card h2 { margin-top: 0; color: #1e293b; font-size: 1.2rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 18px; }
        .mpc-radio-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
        @media (max-width: 768px) { .mpc-radio-grid { grid-template-columns: 1fr; } }
        .mpc-radio-card { border: 2px solid #e2e8f0; border-radius: 10px; padding: 15px 16px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: flex-start; gap: 12px; background: #f8fafc; }
        .mpc-radio-card:hover { border-color: #68B946; background: #fff; }
        .mpc-radio-card.is-selected { border-color: #379237; background: #f4fbf0; box-shadow: 0 4px 12px rgba(55,146,55,0.10); }
        .mpc-radio-card input[type="radio"] { margin-top: 3px; accent-color: #379237; transform: scale(1.15); }
        .mpc-radio-title { font-weight: 800; color: #0f172a; font-size: 0.96rem; display: block; margin-bottom: 4px; }
        .mpc-radio-desc { font-size: 0.84rem; color: #475569; line-height: 1.45; display: block; }
        .mpc-toggle-row { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 12px; }
        .mpc-toggle-row input[type="checkbox"] { margin-top: 3px; accent-color: #379237; transform: scale(1.2); }
    </style>

    <div class="wrap mpc-admin-wrap">
        <?php echo $notice; ?>

        <div class="mpc-admin-header">
            <h1>Meal Plan Checkout Settings</h1>
            <p>Configure Customer Login UI/UX (Options L1 &amp; L3), Auto-Fill Behavior &amp; Post-Payment Subscription Provisioning.</p>
        </div>

        <form method="POST" action="">
            <?php wp_nonce_field( 'mpc_checkout_settings_action', 'mpc_checkout_settings_nonce' ); ?>

            <div class="mpc-admin-card">
                <h2>1. Checkout Login UI/UX Mode (Step 1 &amp; Persistent Header)</h2>
                <p style="color:#475569; margin-top:0; margin-bottom:16px;">Choose how returning subscribers sign in during the checkout wizard. Signing in on Step 1 immediately waives the AED 150 Thermal Bag Deposit (if held) and unlocks email-restricted coupons.</p>

                <div class="mpc-radio-grid" id="mpc-login-mode-cards">
                    <label class="mpc-radio-card <?php echo $current_login_mode === 'l1' ? 'is-selected' : ''; ?>">
                        <input type="radio" name="mpc_checkout_login_mode" value="l1" <?php checked( $current_login_mode, 'l1' ); ?>>
                        <span>
                            <span class="mpc-radio-title">Option L1: Persistent Top Account Bar (Recommended)</span>
                            <span class="mpc-radio-desc">Displays a sleek, collapsible sign-in &amp; account status bar above the progress steps so it stays accessible across Steps 1, 2, and 3.</span>
                        </span>
                    </label>

                    <label class="mpc-radio-card <?php echo $current_login_mode === 'l3' ? 'is-selected' : ''; ?>">
                        <input type="radio" name="mpc_checkout_login_mode" value="l3" <?php checked( $current_login_mode, 'l3' ); ?>>
                        <span>
                            <span class="mpc-radio-title">Option L3: Step 1 Two-Tab Switcher (New vs. Returning)</span>
                            <span class="mpc-radio-desc">Displays prominent "✨ New Subscriber" and "👤 Returning Customer — Sign In" pill tabs at the top of Step 1 above the meal plans.</span>
                        </span>
                    </label>

                    <label class="mpc-radio-card <?php echo $current_login_mode === 'both' ? 'is-selected' : ''; ?>">
                        <input type="radio" name="mpc_checkout_login_mode" value="both" <?php checked( $current_login_mode, 'both' ); ?>>
                        <span>
                            <span class="mpc-radio-title">Option L1 + L3 Combined</span>
                            <span class="mpc-radio-desc">Shows the Two-Tab Switcher on Step 1 AND keeps the Persistent Top Account Bar active across Steps 2 and 3.</span>
                        </span>
                    </label>

                    <label class="mpc-radio-card <?php echo $current_login_mode === 'step2' ? 'is-selected' : ''; ?>">
                        <input type="radio" name="mpc_checkout_login_mode" value="step2" <?php checked( $current_login_mode, 'step2' ); ?>>
                        <span>
                            <span class="mpc-radio-title">Legacy Mode (Step 2 Only)</span>
                            <span class="mpc-radio-desc">Keeps the customer login prompt strictly inside Step 2 (Delivery Information) only.</span>
                        </span>
                    </label>
                </div>

                <h2>2. Smart Checkout Convenience &amp; Safeguards</h2>

                <label class="mpc-toggle-row">
                    <input type="checkbox" name="mpc_checkout_autofill_default" value="1" <?php checked( $autofill_default, '1' ); ?>>
                    <span>
                        <strong style="color:#0f172a; display:block; margin-bottom:2px;">Auto-Fill Saved Delivery Details on Sign-In</strong>
                        <span style="font-size:0.85rem; color:#64748b;">When enabled, signing in automatically populates the customer's saved name, phone, address, delivery timing, and allergies into Step 2 (they can still edit any field for a second or family plan).</span>
                    </span>
                </label>

                <label class="mpc-toggle-row">
                    <input type="checkbox" name="mpc_checkout_email_guard" value="1" <?php checked( $email_guard, '1' ); ?>>
                    <span>
                        <strong style="color:#0f172a; display:block; margin-bottom:2px;">Smart Existing-Email Prompt on Step 2</strong>
                        <span style="font-size:0.85rem; color:#64748b;">When a logged-out guest types an email in Step 2 that already belongs to a registered customer, show an instant 1-click prompt to sign in and waive their bag deposit.</span>
                    </span>
                </label>

                <div style="margin-top: 22px;">
                    <button type="submit" name="mpc_save_checkout_settings" class="button button-primary" style="background:#379237; border-color:#1B5E20; font-weight:800; padding:6px 24px; height:auto; font-size:0.95rem; border-radius:8px;">
                        Save Checkout Settings
                    </button>
                </div>
            </div>
        </form>

        <div class="mpc-admin-card" style="border-left: 5px solid #379237;">
            <h2>3. Post-Payment Subscription Creation Status</h2>
            <p style="color:#334155; line-height:1.55; margin-top:0;">
                <strong>✓ Active Protection:</strong> Subscriptions are created in <code>wp_cmp_subscriptions</code> <strong>only after payment is captured</strong> and the WooCommerce order status reaches <strong>Processing</strong> (or <strong>Completed</strong>). Failed, Cancelled, or Pending Payment orders never create a subscription record, never burn coupon usage limits, and never consume single-use VIP magic links.
            </p>
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-top:14px;">
                    <div>
                        <strong style="color:#0f172a;">Legacy Orphaned Pending Subscriptions in Database:</strong>
                        <span style="display:inline-block; background:<?php echo $pending_subs_count > 0 ? '#fef3c7' : '#dcfce7'; ?>; color:<?php echo $pending_subs_count > 0 ? '#92400e' : '#166534'; ?>; font-weight:800; padding:2px 10px; border-radius:999px; font-size:0.85rem; margin-left:6px;">
                            <?php echo intval( $pending_subs_count ); ?> found
                        </span>
                        <div style="font-size:0.82rem; color:#64748b; margin-top:3px;">Removes any historical <code>status = 'pending'</code> rows created before this update from abandoned or failed checkouts.</div>
                    </div>
                    <?php if ( $pending_subs_count > 0 ) : ?>
                        <form method="POST" action="" style="margin:0;" onsubmit="return confirm('Remove all unpaid/pending subscription rows from wp_cmp_subscriptions? Active, Paused, and Completed plans will not be touched.');">
                            <?php wp_nonce_field( 'mpc_purge_pending_action', 'mpc_purge_pending_nonce' ); ?>
                            <button type="submit" name="mpc_purge_pending_subs" class="button" style="background:#dc2626; color:#fff; border:none; font-weight:700; padding:6px 16px; height:auto; border-radius:6px; cursor:pointer;">
                                Purge <?php echo intval( $pending_subs_count ); ?> Pending Row(s)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.querySelectorAll('#mpc-login-mode-cards input[type="radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('#mpc-login-mode-cards .mpc-radio-card').forEach(function(c) {
                c.classList.remove('is-selected');
            });
            if (this.checked) {
                this.closest('.mpc-radio-card').classList.add('is-selected');
            }
        });
    });
    </script>
    <?php
}

// ==========================================
// 1. ENQUEUE SCRIPTS & STYLES
// ==========================================
add_action( 'wp_enqueue_scripts', 'mpc_enqueue_assets' );
function mpc_enqueue_assets() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'meal_plan_checkout' ) ) {
        $css_file = plugin_dir_path( __FILE__ ) . 'assets/mpc-style.css';
        $version  = file_exists( $css_file ) ? filemtime( $css_file ) : '4.3.0';
        wp_enqueue_style( 'mpc-wizard-styles', plugin_dir_url( __FILE__ ) . 'assets/mpc-style.css', array(), $version );
    }
}

// ==========================================
// 2. AJAX: FRESH NONCE (Cache-Safe)
// ==========================================
add_action( 'wp_ajax_nopriv_mpc_get_nonce', 'mpc_get_fresh_nonce' );
add_action( 'wp_ajax_mpc_get_nonce',        'mpc_get_fresh_nonce' );
function mpc_get_fresh_nonce() {
    wp_send_json_success( array( 'nonce' => wp_create_nonce( 'mpc_checkout_nonce' ) ) );
}

// ==========================================
// 3. HELPER: STRICT WOOCOMMERCE COUPON VALIDATION
// ==========================================
function mpc_validate_woocommerce_coupon_rules( $coupon, $product_id, $price, $user_email ) {
    $expiry = $coupon->get_date_expires();
    if ( $expiry && $expiry->getTimestamp() < time() ) {
        return 'This coupon has expired.';
    }

    $usage_limit = $coupon->get_usage_limit();
    if ( $usage_limit > 0 && $coupon->get_usage_count() >= $usage_limit ) {
        return 'This coupon has reached its global usage limit.';
    }

    $usage_limit_per_user = $coupon->get_usage_limit_per_user();
    if ( $usage_limit_per_user > 0 ) {
        $used_by     = $coupon->get_used_by();
        $user_id     = get_current_user_id();
        $usage_count = 0;
        foreach ( $used_by as $used ) {
            if ( ( $user_id > 0 && intval( $used ) === $user_id ) || ( ! empty( $user_email ) && strcasecmp( $used, $user_email ) === 0 ) ) {
                $usage_count++;
            }
        }
        if ( $usage_count >= $usage_limit_per_user ) {
            return 'You have already reached the usage limit for this coupon.';
        }
    }

    $discount_type = $coupon->get_discount_type();
    if ( ! in_array( $discount_type, array( 'percent', 'fixed_cart' ), true ) ) {
        return 'This coupon type is not supported in the custom checkout.';
    }

    $min_spend = $coupon->get_minimum_amount();
    if ( $min_spend > 0 && $price < $min_spend ) {
        return 'Minimum spend for this coupon is AED ' . $min_spend;
    }

    $max_spend = $coupon->get_maximum_amount();
    if ( $max_spend > 0 && $price > $max_spend ) {
        return 'Maximum spend for this coupon is AED ' . $max_spend;
    }

    $valid_products = $coupon->get_product_ids();
    if ( ! empty( $valid_products ) && ! in_array( $product_id, $valid_products ) ) {
        return 'This coupon is not applicable to the selected plan.';
    }

    $excluded_products = $coupon->get_excluded_product_ids();
    if ( ! empty( $excluded_products ) && in_array( $product_id, $excluded_products ) ) {
        return 'The selected plan is excluded from this coupon.';
    }

    $valid_categories    = $coupon->get_product_categories();
    $excluded_categories = $coupon->get_excluded_product_categories();
    if ( ! empty( $valid_categories ) || ! empty( $excluded_categories ) ) {
        $product_cats = wc_get_product_term_ids( $product_id, 'product_cat' );
        if ( ! empty( $valid_categories ) && count( array_intersect( $valid_categories, $product_cats ) ) === 0 ) {
            return 'This coupon is not applicable to this plan category.';
        }
        if ( ! empty( $excluded_categories ) && count( array_intersect( $excluded_categories, $product_cats ) ) > 0 ) {
            return 'This plan category is excluded from this coupon.';
        }
    }

    $restricted_emails = $coupon->get_email_restrictions();
    if ( ! empty( $restricted_emails ) ) {
        if ( empty( $user_email ) ) {
            return 'This coupon is restricted to specific accounts. Please sign in at the top of this page (or enter your email in Step 2) before applying.';
        }
        $email_valid = false;
        foreach ( $restricted_emails as $restricted_email ) {
            $regex = '/^' . str_replace( '\*', '.*', preg_quote( $restricted_email, '/' ) ) . '$/i';
            if ( preg_match( $regex, $user_email ) ) {
                $email_valid = true;
                break;
            }
        }
        if ( ! $email_valid ) {
            return 'This coupon is not valid for your email address.';
        }
    }

    return true;
}

// ==========================================
// 4. AJAX: COUPON VALIDATION FRONTEND
// ==========================================
add_action( 'wp_ajax_nopriv_mpc_validate_coupon', 'mpc_ajax_validate_coupon' );
add_action( 'wp_ajax_mpc_validate_coupon',        'mpc_ajax_validate_coupon' );
function mpc_ajax_validate_coupon() {
    check_ajax_referer( 'mpc_checkout_nonce', 'nonce' );

    $code       = strtoupper( sanitize_text_field( $_POST['coupon_code'] ?? '' ) );
    $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
    $user_email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';

    if ( is_user_logged_in() && empty( $user_email ) ) {
        $user_email = wp_get_current_user()->user_email;
    }

    if ( empty( $code ) ) {
        wp_send_json_error( 'Please enter a coupon code.' );
    }
    if ( empty( $product_id ) ) {
        wp_send_json_error( 'Please select a meal plan first.' );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        wp_send_json_error( 'Invalid meal plan selected.' );
    }
    $price = (float) $product->get_price();

    $coupon = new WC_Coupon( $code );
    if ( ! $coupon->get_id() ) {
        wp_send_json_error( 'Invalid coupon code.' );
    }

    $validation_result = mpc_validate_woocommerce_coupon_rules( $coupon, $product_id, $price, $user_email );
    if ( $validation_result !== true ) {
        wp_send_json_error( $validation_result );
    }

    $discount_type = $coupon->get_discount_type();
    $label         = trim( $coupon->get_description() );
    if ( empty( $label ) ) {
        $amount_display = ( $discount_type === 'percent' )
            ? $coupon->get_amount() . '% Off'
            : 'AED ' . number_format( (float) $coupon->get_amount(), 2 ) . ' Off';
        $label = ucwords( strtolower( $code ) ) . ' - ' . $amount_display;
    }

    wp_send_json_success( array(
        'code'         => $code,
        'discountType' => $discount_type,
        'amount'       => (float) $coupon->get_amount(),
        'label'        => $label,
    ) );
}

// ==========================================
// 5. AJAX: VIP MAGIC LINK LIVE VALIDATOR
// ==========================================
add_action( 'wp_ajax_nopriv_mpc_validate_vip_token', 'mpc_ajax_validate_vip_token' );
add_action( 'wp_ajax_mpc_validate_vip_token',        'mpc_ajax_validate_vip_token' );
function mpc_ajax_validate_vip_token() {
    $token = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
    if ( empty( $token ) ) {
        wp_send_json_error();
    }

    $active_links = get_option( 'cmp_active_magic_links', array() );
    if ( isset( $active_links[ $token ] ) ) {
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

// ==========================================
// 6. AJAX: SECURE USER LOGIN, FORGOT PASSWORD & EMAIL CHECK
// ==========================================
add_action( 'wp_ajax_nopriv_mpc_login_user', 'mpc_ajax_login' );
add_action( 'wp_ajax_mpc_login_user',        'mpc_ajax_login' );
function mpc_ajax_login() {
    check_ajax_referer( 'mpc_checkout_nonce', 'nonce' );
    global $wpdb;

    if ( function_exists( 'cmp_intercept_failed_portal_login' ) ) {
        remove_action( 'wp_login_failed', 'cmp_intercept_failed_portal_login' );
    }

    $login_input = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
    $password    = $_POST['pwd'] ?? '';

    if ( empty( $login_input ) || empty( $password ) ) {
        wp_send_json_error( 'Please enter both your email/username and password.' );
    }

    $creds = array(
        'user_login'    => $login_input,
        'user_password' => $password,
        'remember'      => true,
    );

    $user = wp_signon( $creds, is_ssl() );

    if ( is_wp_error( $user ) ) {
        $err_code = $user->get_error_code();
        if ( in_array( $err_code, array( 'invalid_username', 'invalid_email' ), true ) ) {
            wp_send_json_error( 'No account found with that email address.' );
        } elseif ( $err_code === 'incorrect_password' ) {
            wp_send_json_error( 'Incorrect password. Please try again or click "Forgot Password?".' );
        } else {
            wp_send_json_error( wp_strip_all_tags( $user->get_error_message() ) );
        }
    } else {
        $user_id = $user->ID;

        // DEPOSIT WALLET LOGIC WITH "OWN BAG" THIRD STATE (EXCLUDES UNPAID PENDING ROWS)
        $deposit_meta = get_user_meta( $user_id, '_cmp_deposit_held', true );
        if ( $deposit_meta === '' ) {
            $past_plans = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d AND status IN ('active', 'paused', 'completed')",
                $user_id
            ) );
            if ( intval( $past_plans ) > 0 ) {
                update_user_meta( $user_id, '_cmp_deposit_held', 'yes' );
                $deposit_meta     = 'yes';
                $requires_deposit = false;
            } else {
                $requires_deposit = true;
            }
        } else {
            $requires_deposit = ( $deposit_meta !== 'yes' && $deposit_meta !== 'own_bag' );
        }

        $fname = $user->first_name ?: get_user_meta( $user_id, 'billing_first_name', true );
        $lname = $user->last_name ?: get_user_meta( $user_id, 'billing_last_name', true );

        $data = array(
            'first_name'       => $fname ?: $user->display_name,
            'last_name'        => $lname,
            'email'            => $user->user_email,
            'phone'            => get_user_meta( $user_id, 'billing_phone', true ),
            'address_1'        => get_user_meta( $user_id, 'billing_address_1', true ),
            'address_2'        => get_user_meta( $user_id, 'billing_address_2', true ),
            'delivery_method'  => get_user_meta( $user_id, 'delivery_method', true ),
            'delivery_timing'  => get_user_meta( $user_id, 'delivery_timing', true ),
            'time_slot'        => get_user_meta( $user_id, 'time_slot', true ),
            'pickup_location'  => get_user_meta( $user_id, 'pickup_location', true ),
            'allergies'        => get_user_meta( $user_id, 'allergies', true ),
            'new_nonce'        => wp_create_nonce( 'mpc_checkout_nonce' ),
            'requires_deposit' => $requires_deposit,
            'deposit_status'   => $deposit_meta,
        );
        wp_send_json_success( $data );
    }
}

// 6.2 AJAX: INLINE FORGOT PASSWORD IN CHECKOUT
add_action( 'wp_ajax_nopriv_mpc_forgot_password', 'mpc_ajax_forgot_password' );
add_action( 'wp_ajax_mpc_forgot_password',        'mpc_ajax_forgot_password' );
function mpc_ajax_forgot_password() {
    check_ajax_referer( 'mpc_checkout_nonce', 'nonce' );

    $user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
    if ( empty( $user_login ) ) {
        wp_send_json_error( 'Please enter your email address or username.' );
    }

    $user_data = is_email( $user_login ) ? get_user_by( 'email', $user_login ) : get_user_by( 'login', $user_login );
    if ( ! $user_data ) {
        wp_send_json_error( 'No customer account was found matching that email address.' );
    }

    $errors = retrieve_password( $user_data->user_login );
    if ( true === $errors ) {
        wp_send_json_success( 'Password reset link sent! Please check your email inbox (and spam folder).' );
    } else {
        $err_msg = is_wp_error( $errors ) ? wp_strip_all_tags( $errors->get_error_message() ) : 'Unable to send reset email right now. Please contact support.';
        wp_send_json_error( $err_msg );
    }
}

// 6.3 AJAX: SMART EXISTING-EMAIL DETECTOR ON STEP 2
add_action( 'wp_ajax_nopriv_mpc_check_email_exists', 'mpc_ajax_check_email_exists' );
add_action( 'wp_ajax_mpc_check_email_exists',        'mpc_ajax_check_email_exists' );
function mpc_ajax_check_email_exists() {
    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    if ( empty( $email ) || is_user_logged_in() ) {
        wp_send_json_success( array( 'exists' => false ) );
    }
    $exists = email_exists( $email ) ? true : false;
    wp_send_json_success( array( 'exists' => $exists ) );
}

// ==========================================
// 7. AJAX: ORDER PROCESSING (PRE-PAYMENT BLUEPRINT ONLY — NO EARLY SUBSCRIPTION INSERT)
// ==========================================
add_action( 'wp_ajax_nopriv_mpc_process_order', 'mpc_process_order' );
add_action( 'wp_ajax_mpc_process_order',        'mpc_process_order' );

function mpc_process_order() {
    check_ajax_referer( 'mpc_checkout_nonce', 'nonce' );
    global $wpdb;

    mpc_ensure_subscription_logistics_columns();

    $product_id      = intval( $_POST['product_id'] ?? 0 );
    $first_name      = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
    $last_name       = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
    $email           = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    $phone           = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
    $password        = isset( $_POST['password'] ) ? $_POST['password'] : '';
    $address_1       = sanitize_text_field( wp_unslash( $_POST['address_1'] ?? '' ) );
    $address_2       = sanitize_text_field( wp_unslash( $_POST['address_2'] ?? '' ) );
    $delivery_method = sanitize_text_field( wp_unslash( $_POST['delivery_method'] ?? '' ) );
    $delivery_timing = sanitize_text_field( wp_unslash( $_POST['delivery_timing'] ?? '' ) );
    $time_slot       = sanitize_text_field( wp_unslash( $_POST['time_slot'] ?? '' ) );
    $pickup_location = isset( $_POST['pickup_location'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_location'] ) ) : '';
    $allergies       = sanitize_textarea_field( wp_unslash( $_POST['allergies'] ?? '' ) );
    $recipient_name  = isset( $_POST['recipient_name'] ) ? sanitize_text_field( wp_unslash( $_POST['recipient_name'] ) ) : '';
    $coupon_code_raw = isset( $_POST['coupon_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) ) : '';

    if ( ! $product_id || ! $email || ! $first_name || ! $address_1 ) {
        wp_send_json_error( 'Missing mandatory fields.' );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        wp_send_json_error( 'Selected meal plan product was not found.' );
    }
    $plan_title      = $product->get_name();
    $base_plan_price = (float) $product->get_price();

    if ( stripos( $plan_title, 'juice' ) !== false || stripos( $plan_title, 'cleanse' ) !== false ) {
        $categories = array( 'Juices' );
    } else {
        $categories = array( 'Breakfast', 'Lunch', 'Dinner', 'Snacks' );
    }

    // ---- MAGIC LINK: OWN BAG TOKEN CHECK (VALIDATED NOW, CONSUMED UPON PAYMENT) ----
    $vip_token        = isset( $_POST['vip_token'] ) ? sanitize_text_field( $_POST['vip_token'] ) : '';
    $is_own_bag_token = false;

    if ( ! empty( $vip_token ) ) {
        $active_links = get_option( 'cmp_active_magic_links', array() );
        if ( isset( $active_links[ $vip_token ] ) ) {
            $is_own_bag_token = true;
        }
    }

    // ---- SERVER-SIDE COUPON VALIDATION ----
    $discount_type          = '';
    $discount_label         = '';
    $coupon_used            = '';
    $is_100_percent_free    = false;
    $final_discount_to_save = 0.00;

    if ( ! empty( $coupon_code_raw ) ) {
        $wc_coupon = new WC_Coupon( $coupon_code_raw );
        if ( $wc_coupon->get_id() ) {
            $validation_result = mpc_validate_woocommerce_coupon_rules( $wc_coupon, $product_id, $base_plan_price, $email );
            if ( $validation_result === true ) {
                $discount_type  = $wc_coupon->get_discount_type();
                $coupon_used    = $coupon_code_raw;
                $label_raw      = trim( $wc_coupon->get_description() );
                $discount_label = ! empty( $label_raw ) ? $label_raw : ucwords( strtolower( $coupon_code_raw ) ) . ' Discount';

                if ( $discount_type === 'percent' && floatval( $wc_coupon->get_amount() ) >= 100 ) {
                    $is_100_percent_free = true;
                }
            } else {
                wp_send_json_error( 'Coupon Error: ' . $validation_result );
            }
        } else {
            wp_send_json_error( 'Coupon Error: Invalid coupon code.' );
        }
    }

    // ---- USER CREATION & DEPOSIT WALLET LOGIC ----
    $user_id          = get_current_user_id();
    $requires_deposit = false;
    $deposit_meta     = '';
    $table_subs       = $wpdb->prefix . 'cmp_subscriptions';

    if ( ! $user_id ) {
        if ( email_exists( $email ) ) {
            wp_send_json_error( 'An account with this email already exists. Please sign in at the top of the page to continue.' );
        }
        $user_id = wp_create_user( $email, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( $user_id->get_error_message() );
        }
        wp_clear_auth_cookie();
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        $requires_deposit = ! $is_own_bag_token;
    } else {
        $deposit_meta = get_user_meta( $user_id, '_cmp_deposit_held', true );
        if ( $is_own_bag_token ) {
            $requires_deposit = false;
        } else {
            if ( $deposit_meta === '' ) {
                $past_plans = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active', 'paused', 'completed')",
                    $user_id
                ) );
                if ( intval( $past_plans ) > 0 ) {
                    update_user_meta( $user_id, '_cmp_deposit_held', 'yes' );
                    $requires_deposit = false;
                    $deposit_meta     = 'yes';
                } else {
                    $requires_deposit = true;
                }
            } else {
                $requires_deposit = ( $deposit_meta !== 'yes' && $deposit_meta !== 'own_bag' );
            }
        }
    }

    // Check if the customer already has another active/paused subscription
    $existing_active_subs = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active','paused')",
        $user_id
    ) ) );

    // --- SAVE USER META (Preserves existing active plan's user_meta if multi-plan customer) ---
    wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
    update_user_meta( $user_id, 'billing_phone',     $phone );
    update_user_meta( $user_id, 'billing_address_1', $address_1 );
    update_user_meta( $user_id, 'billing_address_2', $address_2 );
    update_user_meta( $user_id, 'billing_city',      'Dubai' );
    update_user_meta( $user_id, 'billing_country',   'AE' );

    if ( $existing_active_subs === 0 ) {
        update_user_meta( $user_id, 'delivery_method', $delivery_method );
        update_user_meta( $user_id, 'delivery_timing', $delivery_timing );
        update_user_meta( $user_id, 'time_slot',       $time_slot );
        update_user_meta( $user_id, 'pickup_location', $pickup_location );
        update_user_meta( $user_id, 'allergies',       $allergies );
    }

    // --- CALCULATE PLAN DAYS & FINAL PLAN NAME FOR ORDER BLUEPRINT ---
    $days = intval( get_post_meta( $product_id, '_cmp_total_days', true ) );
    if ( $days <= 0 ) {
        $days = 30;
        if ( stripos( $plan_title, '7' )  !== false ) $days = 7;
        if ( stripos( $plan_title, '20' ) !== false ) $days = 20;
        if ( stripos( $plan_title, '24' ) !== false ) $days = 24;
        if ( stripos( $plan_title, '5' )  !== false ) $days = 5;
        if ( stripos( $plan_title, '3' )  !== false && stripos( $plan_title, 'juice' ) !== false ) $days = 3;
    }

    $final_plan_name = $plan_title;
    if ( ! empty( $recipient_name ) ) {
        $final_plan_name .= ' - ' . $recipient_name;
    }

    $full_delivery_address = implode( ', ', array_filter( array( $address_1, $address_2, 'Dubai' ) ) );

    // --- CREATE WOOCOMMERCE ORDER (STATUS: PENDING PAYMENT) ---
    $order = wc_create_order( array( 'customer_id' => $user_id ) );
    $order->add_product( $product, 1 );

    $address = array(
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'email'      => $email,
        'phone'      => $phone,
        'address_1'  => $address_1,
        'address_2'  => $address_2,
        'city'       => 'Dubai',
        'country'    => 'AE',
    );
    $order->set_address( $address, 'billing' );
    $order->set_address( $address, 'shipping' );
    $order->set_customer_note( $allergies );

    // Store complete Subscription Blueprint in Order Meta (Provisioned ONLY when order reaches Processing / Completed)
    $order->update_meta_data( '_mpc_is_custom_checkout', '1' );
    $order->update_meta_data( '_cmp_plan_name',          $final_plan_name );
    $order->update_meta_data( '_cmp_total_days',         $days );
    $order->update_meta_data( '_cmp_plan_price',         $base_plan_price );
    $order->update_meta_data( '_cmp_allowed_categories', implode( ',', $categories ) );
    $order->update_meta_data( '_cmp_recipient_name',     $recipient_name );
    $order->update_meta_data( '_cmp_delivery_address',   $full_delivery_address );
    $order->update_meta_data( '_cmp_prep_timing',        'auto' );

    $order->update_meta_data( 'delivery_method',         $delivery_method );
    $order->update_meta_data( '_cmp_logistics_method',   $delivery_method );
    $order->update_meta_data( 'delivery_timing',         $delivery_timing );
    $order->update_meta_data( '_cmp_delivery_timing',    $delivery_timing );
    $order->update_meta_data( 'time_slot',               $time_slot );
    $order->update_meta_data( '_cmp_time_slot',          $time_slot );
    $order->update_meta_data( 'pickup_location',         ( $delivery_method === 'Pickup' ) ? $pickup_location : '' );
    $order->update_meta_data( '_cmp_pickup_location',    ( $delivery_method === 'Pickup' ) ? $pickup_location : '' );
    $order->update_meta_data( 'allergies',               $allergies );

    // Logistics Note for Own Bag
    if ( $is_own_bag_token || $deposit_meta === 'own_bag' ) {
        $order->update_meta_data( '_mpc_is_own_bag', '1' );
        if ( ! empty( $vip_token ) ) {
            $order->update_meta_data( '_mpc_vip_token', $vip_token );
        }
        $order->add_order_note( 'Logistics Alert: Customer is using their OWN BAG. Do not issue a new thermal bag.' );
    }

    // --- APPLY COUPON FEE (IF APPLICABLE) ---
    if ( ! empty( $coupon_used ) ) {
        $order->calculate_totals();
        $subtotal        = $order->get_subtotal();
        $wc_coupon_obj   = new WC_Coupon( $coupon_used );
        $discount_amount = 0;

        if ( $discount_type === 'percent' ) {
            $discount_amount = round( $subtotal * ( (float) $wc_coupon_obj->get_amount() / 100 ), 2 );
        } elseif ( $discount_type === 'fixed_cart' ) {
            $discount_amount = min( round( (float) $wc_coupon_obj->get_amount(), 2 ), $subtotal );
            if ( $discount_amount >= $subtotal ) {
                $is_100_percent_free = true;
            }
        }

        if ( $discount_amount > 0 ) {
            $final_discount_to_save = $discount_amount;

            $fee = new WC_Order_Item_Fee();
            $fee->set_name( $discount_label . ' (' . $coupon_used . ')' );
            $fee->set_amount( -$discount_amount );
            $fee->set_total( -$discount_amount );
            $fee->set_tax_status( 'none' );
            $order->add_item( $fee );

            $order->update_meta_data( '_mpc_coupon_used',     $coupon_used );
            $order->update_meta_data( '_mpc_discount_type',   $discount_type );
            $order->update_meta_data( '_mpc_discount_amount', $discount_amount );
        }
    }

    $order->update_meta_data( '_cmp_discount_amount', $final_discount_to_save );

    // --- NATIVE DEPOSIT INJECTION ---
    if ( $requires_deposit && ! $is_100_percent_free ) {
        $deposit_fee = new WC_Order_Item_Fee();
        $deposit_fee->set_name( 'Thermal Bag Deposit (Refundable)' );
        $deposit_fee->set_amount( 150 );
        $deposit_fee->set_total( 150 );
        $deposit_fee->set_tax_status( 'none' );
        $order->add_item( $deposit_fee );
    }

    $order->calculate_totals();
    $order->save();

    // --- VIP 100% FREE BYPASS vs. N-GENIUS PAYMENT GATEWAY ---
    $final_order_total = (float) $order->get_total();

    if ( $final_order_total <= 0 ) {
        // 100% Free order: payment_complete() transitions order to Processing/Completed, which triggers mpc_activate_subscription_on_payment()
        $order->payment_complete();
        $order->add_order_note( '100% Free VIP Coupon applied. Payment gateway bypassed and subscription provisioned.' );
        mpc_activate_subscription_on_payment( $order->get_id() );

        wp_send_json_success( array( 'payment_url' => esc_url_raw( $order->get_checkout_order_received_url() ) ) );
    } else {
        // Paid order: Route to N-Genius Payment Bridge. Subscription will ONLY be created once payment succeeds and order becomes Processing.
        $main_site_url = 'https://thecyclehub.com';
        $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/pay';

        $payload = array(
            'order_id'   => $order->get_id(),
            'amount'     => $final_order_total,
            'currency'   => $order->get_currency(),
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'return_url' => $order->get_checkout_order_received_url(),
        );

        $response = wp_remote_post( $endpoint, array(
            'headers' => array( 'Content-Type' => 'application/json', 'x-bistro-token' => defined( 'BISTRO_BRIDGE_SECRET' ) ? BISTRO_BRIDGE_SECRET : '' ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            $order->update_status( 'failed', 'Payment bridge connection error: ' . $response->get_error_message() );
            wp_send_json_error( 'Payment bridge error: ' . $response->get_error_message() );
        }

        $raw_body = wp_remote_retrieve_body( $response );
        $body     = json_decode( $raw_body, true );

        if ( ! is_array( $body ) ) {
            $order->update_status( 'failed', 'Invalid JSON response from payment bridge.' );
            wp_send_json_error( 'Gateway Error: Invalid response from payment bridge.' );
        }

        if ( isset( $body['success'] ) && $body['success'] === true ) {
            $order->update_meta_data( '_ngenius_reference', sanitize_text_field( $body['reference'] ) );
            $order->save();
            wp_send_json_success( array( 'payment_url' => esc_url_raw( $body['payment_url'] ) ) );
        } else {
            $error_message = isset( $body['message'] ) ? $body['message'] : 'Failed to retrieve payment link.';
            $order->update_status( 'failed', 'Gateway error: ' . $error_message );
            wp_send_json_error( 'Gateway Error: ' . $error_message );
        }
    }
}

// ==========================================
// 8. FRONTEND WIZARD RENDERER (OPTIONS L1 & L3 + INLINE FORGOT PASSWORD)
// ==========================================
add_shortcode( 'meal_plan_checkout', 'mpc_render_checkout_wizard' );

function mpc_render_checkout_wizard() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return '<p>WooCommerce is required.</p>';
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $map_url    = get_option( 'cmp_map_url', '#' );

    // Load Admin Checkout Settings
    $login_ui_mode    = get_option( 'mpc_checkout_login_mode', 'l1' );
    $autofill_default = ( get_option( 'mpc_checkout_autofill_default', '1' ) === '1' );
    $email_guard_on   = ( get_option( 'mpc_checkout_email_guard', '1' ) === '1' );

    $show_l1_bar      = in_array( $login_ui_mode, array( 'l1', 'both' ), true );
    $show_l3_tabs     = in_array( $login_ui_mode, array( 'l3', 'both' ), true );
    $show_step2_login = ( $login_ui_mode === 'step2' );

    $args     = array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC' );
    $products = wc_get_products( $args );

    $grouped_plans = array(
        '7-days'  => array( 'label' => '7-Day Plans',      'items' => array() ),
        '20-days' => array( 'label' => '20-Day Plans',     'items' => array() ),
        '24-days' => array( 'label' => '24-Day Plans',     'items' => array() ),
        'juice'   => array( 'label' => 'Cleanse Boosters', 'items' => array() ),
        'other'   => array( 'label' => 'Other Plans',      'items' => array() ),
    );

    foreach ( $products as $product ) {
        $tag_slugs = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'slugs' ) );
        if ( is_wp_error( $tag_slugs ) ) {
            $tag_slugs = array();
        }
        $assigned = false;
        foreach ( array( '7-days', '20-days', '24-days', 'juice' ) as $target_tag ) {
            if ( in_array( $target_tag, $tag_slugs, true ) ) {
                $grouped_plans[ $target_tag ]['items'][] = $product;
                $assigned = true;
                break;
            }
        }
        if ( ! $assigned ) {
            $grouped_plans['other']['items'][] = $product;
        }
    }

    // --- MAGIC LINK CHECK ---
    $vip_token          = isset( $_GET['vip_token'] ) ? sanitize_text_field( $_GET['vip_token'] ) : '';
    $is_valid_vip_token = false;
    if ( ! empty( $vip_token ) ) {
        $active_links = get_option( 'cmp_active_magic_links', array() );
        if ( isset( $active_links[ $vip_token ] ) ) {
            $is_valid_vip_token = true;
        }
    }

    // DETERMINE DEPOSIT REQUIREMENT ON LOAD (EXCLUDES UNPAID PENDING ROWS)
    $requires_deposit_init = 'true';
    $deposit_meta_init     = '';
    $saved_user_details    = array();

    if ( $is_valid_vip_token ) {
        $requires_deposit_init = 'false';
    } elseif ( is_user_logged_in() ) {
        $uid               = get_current_user_id();
        $current_user      = wp_get_current_user();
        $deposit_meta_init = get_user_meta( $uid, '_cmp_deposit_held', true );

        if ( $deposit_meta_init === '' ) {
            $past_plans = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active', 'paused', 'completed')",
                $uid
            ) );
            if ( intval( $past_plans ) > 0 ) {
                $requires_deposit_init = 'false';
                $deposit_meta_init     = 'yes';
                update_user_meta( $uid, '_cmp_deposit_held', 'yes' );
            }
        } elseif ( $deposit_meta_init === 'yes' || $deposit_meta_init === 'own_bag' ) {
            $requires_deposit_init = 'false';
        }

        $saved_user_details = array(
            'first_name'      => $current_user->first_name ?: get_user_meta( $uid, 'billing_first_name', true ),
            'last_name'       => $current_user->last_name ?: get_user_meta( $uid, 'billing_last_name', true ),
            'email'           => $current_user->user_email,
            'phone'           => get_user_meta( $uid, 'billing_phone', true ),
            'address_1'       => get_user_meta( $uid, 'billing_address_1', true ),
            'address_2'       => get_user_meta( $uid, 'billing_address_2', true ),
            'delivery_method' => get_user_meta( $uid, 'delivery_method', true ),
            'delivery_timing' => get_user_meta( $uid, 'delivery_timing', true ),
            'time_slot'       => get_user_meta( $uid, 'time_slot', true ),
            'pickup_location' => get_user_meta( $uid, 'pickup_location', true ),
            'allergies'       => get_user_meta( $uid, 'allergies', true ),
        );
    }

    ob_start();
    ?>
    <style>
        /* Option L1 & Option L3 Scoped Checkout Login Styles */
        .mpc-top-account-bar {
            background: linear-gradient(135deg, #f8fafc 0%, #f0fdf4 100%);
            border: 1.5px solid #DCECC9;
            border-left: 5px solid #379237;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 22px;
            box-shadow: 0 4px 12px rgba(27, 94, 32, 0.05);
            transition: all 0.2s ease;
        }
        .mpc-top-account-bar.is-logged-in {
            background: linear-gradient(135deg, #f0fdf4 0%, #EEF7E3 100%);
            border-color: #86efac;
            border-left: 5px solid #1B5E20;
        }
        .mpc-account-bar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .mpc-account-bar-title {
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 700;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .mpc-account-toggle-btn {
            background: #379237;
            color: #ffffff !important;
            border: none;
            border-radius: 8px;
            padding: 7px 15px;
            font-size: 0.85rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .mpc-account-toggle-btn:hover {
            background: #1B5E20;
            transform: translateY(-1px);
        }
        .mpc-inline-auth-drawer {
            display: none;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px dashed #cbd5e1;
        }
        .mpc-inline-auth-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            align-items: center;
        }
        @media (max-width: 768px) {
            .mpc-inline-auth-grid {
                grid-template-columns: 1fr;
            }
        }
        .mpc-auth-links-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
            font-size: 0.84rem;
        }
        .mpc-deposit-waived-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
        }

        /* Option L3: Step 1 Two-Tab Mode Switcher */
        .mpc-l3-mode-tabs {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            background: #f1f5f9;
            padding: 6px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            margin-bottom: 20px;
        }
        .mpc-l3-tab-btn {
            background: transparent;
            color: #475569;
            border: none;
            border-radius: 9px;
            padding: 11px 14px;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            text-align: center;
        }
        .mpc-l3-tab-btn.active {
            background: #ffffff;
            color: #1B5E20;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.08);
            border: 1px solid #DCECC9;
        }
        .mpc-l3-returning-panel {
            display: none;
            background: #ffffff;
            border: 1.5px solid #DCECC9;
            border-top: 4px solid #379237;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 8px 22px rgba(27, 94, 32, 0.06);
        }
        .mpc-email-exists-hint {
            display: none;
            margin-top: 6px;
            padding: 8px 12px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            font-size: 0.83rem;
            color: #92400e;
            font-weight: 600;
        }
    </style>

    <?php if ( is_user_logged_in() ) : ?>
        <script>var mpcSavedDetails = <?php echo wp_json_encode( $saved_user_details ); ?>;</script>
    <?php endif; ?>

    <div class="mpc-checkout-container">

        <div class="mpc-wizard-area">

            <!-- =========================================================
                 OPTION L1: PERSISTENT TOP ACCOUNT & SIGN-IN BAR (STEPS 1-3)
                 ========================================================= -->
            <?php if ( $show_l1_bar ) : ?>
                <div id="mpc-top-account-bar" class="mpc-top-account-bar <?php echo is_user_logged_in() ? 'is-logged-in' : ''; ?>">
                    <?php if ( ! is_user_logged_in() ) : ?>
                        <div class="mpc-account-bar-header">
                            <div class="mpc-account-bar-title">
                                <span>&#128100; <strong>Already a subscriber?</strong></span>
                                <span style="font-weight:500; color:#475569; font-size:0.88rem;">Sign in to waive your bag deposit &amp; auto-fill your saved delivery details.</span>
                            </div>
                            <button type="button" id="mpc-top-login-toggle" class="mpc-account-toggle-btn">
                                <span>Sign In</span>
                                <span id="mpc-top-login-arrow">&#9662;</span>
                            </button>
                        </div>

                        <div id="mpc-top-auth-drawer" class="mpc-inline-auth-drawer">
                            <!-- Sign In View -->
                            <div id="mpc-top-signin-view">
                                <div class="mpc-inline-auth-grid">
                                    <input type="email" id="mpc_top_login_email" class="mpc-form-control" placeholder="Email Address" autocomplete="username">
                                    <input type="password" id="mpc_top_login_pwd" class="mpc-form-control" placeholder="Password" autocomplete="current-password">
                                    <button type="button" id="mpc-top-login-submit" class="mpc-btn" style="background:#379237; color:#fff; height:44px; padding:0 22px; font-weight:800; white-space:nowrap;">Sign In</button>
                                </div>
                                <div class="mpc-auth-links-row">
                                    <span id="mpc-top-login-msg" style="color:#e11d48; font-weight:700;"></span>
                                    <a href="#forgot" id="mpc-top-show-forgot" style="color:#1B5E20; font-weight:700; text-decoration:underline;">Forgot Password?</a>
                                </div>
                            </div>

                            <!-- Inline Forgot Password View -->
                            <div id="mpc-top-forgot-view" style="display:none;">
                                <p style="margin:0 0 10px 0; font-size:0.86rem; color:#334155;">Enter your registered email address and we will send you a password reset link:</p>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <input type="email" id="mpc_top_forgot_email" class="mpc-form-control" placeholder="Enter your email address" style="flex:1; min-width:220px;">
                                    <button type="button" id="mpc-top-forgot-submit" class="mpc-btn" style="background:#334155; color:#fff; height:44px; padding:0 20px; font-weight:800;">Send Reset Link</button>
                                </div>
                                <div class="mpc-auth-links-row">
                                    <span id="mpc-top-forgot-msg" style="font-weight:700;"></span>
                                    <a href="#signin" id="mpc-top-back-signin" style="color:#1B5E20; font-weight:700; text-decoration:underline;">&larr; Back to Sign In</a>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="mpc-account-bar-header">
                            <div class="mpc-account-bar-title">
                                <span style="color:#1B5E20;">&#10003; <strong>Welcome back, <?php echo esc_html( $saved_user_details['first_name'] ); ?>!</strong></span>
                                <?php if ( $requires_deposit_init === 'false' ) : ?>
                                    <span class="mpc-deposit-waived-pill">&#10003; Thermal Bag Deposit Waived</span>
                                <?php endif; ?>
                            </div>
                            <label style="cursor:pointer; display:inline-flex; align-items:center; gap:7px; font-size:0.86rem; font-weight:700; color:#1B5E20; margin:0;">
                                <input type="checkbox" id="mpc_use_saved_details" <?php echo $autofill_default ? 'checked' : ''; ?> style="accent-color:#379237; transform:scale(1.15);">
                                <span>Auto-fill my saved delivery details</span>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mpc-progress">
                <div class="mpc-step-indicator active" data-step="1"><div class="mpc-step-circle">1</div><span>Select Plan</span></div>
                <div class="mpc-step-indicator" data-step="2"><div class="mpc-step-circle">2</div><span>Delivery Details</span></div>
                <div class="mpc-step-indicator" data-step="3" id="mpc-indicator-meals"><div class="mpc-step-circle">3</div><span>Dietary Info</span></div>
            </div>

            <div id="mpc-step-1" class="mpc-step-content active">
                <h2 style="margin-top: 0; color: #222;">Choose Your Plan</h2>
                <p style="color: #666; margin-bottom: 18px;">Select a meal plan to get started. You will choose your specific daily meals within your flexible quota through the Customer Portal after your subscription is confirmed.</p>

                <!-- =========================================================
                     OPTION L3: STEP 1 TWO-TAB SWITCHER (NEW vs. RETURNING)
                     ========================================================= -->
                <?php if ( $show_l3_tabs && ! is_user_logged_in() ) : ?>
                    <div id="mpc-l3-wrapper">
                        <div class="mpc-l3-mode-tabs">
                            <button type="button" class="mpc-l3-tab-btn active" data-l3-tab="new">
                                <span>&#10024; New Subscriber</span>
                            </button>
                            <button type="button" class="mpc-l3-tab-btn" data-l3-tab="returning">
                                <span>&#128100; Returning Customer &mdash; Sign In</span>
                            </button>
                        </div>

                        <div id="mpc-l3-returning-panel" class="mpc-l3-returning-panel">
                            <div id="mpc-l3-signin-view">
                                <h3 style="margin:0 0 6px 0; color:#1B5E20; font-size:1.15rem;">Welcome Back to The Cycle Bistro</h3>
                                <p style="margin:0 0 14px 0; color:#475569; font-size:0.88rem;">Sign in to automatically waive your AED 150 Thermal Bag Deposit and pre-fill your delivery preferences.</p>
                                <div class="mpc-inline-auth-grid">
                                    <input type="email" id="mpc_l3_login_email" class="mpc-form-control" placeholder="Email Address" autocomplete="username">
                                    <input type="password" id="mpc_l3_login_pwd" class="mpc-form-control" placeholder="Password" autocomplete="current-password">
                                    <button type="button" id="mpc-l3-login-submit" class="mpc-btn" style="background:#379237; color:#fff; height:44px; padding:0 22px; font-weight:800; white-space:nowrap;">Sign In &amp; Continue</button>
                                </div>
                                <div class="mpc-auth-links-row">
                                    <span id="mpc-l3-login-msg" style="color:#e11d48; font-weight:700;"></span>
                                    <a href="#forgot-l3" id="mpc-l3-show-forgot" style="color:#1B5E20; font-weight:700; text-decoration:underline;">Forgot Password?</a>
                                </div>
                            </div>

                            <div id="mpc-l3-forgot-view" style="display:none;">
                                <h4 style="margin:0 0 6px 0; color:#1e293b;">Reset Your Password</h4>
                                <p style="margin:0 0 12px 0; font-size:0.86rem; color:#475569;">Enter your account email and we will send you a password reset link:</p>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <input type="email" id="mpc_l3_forgot_email" class="mpc-form-control" placeholder="Enter your email address" style="flex:1; min-width:220px;">
                                    <button type="button" id="mpc-l3-forgot-submit" class="mpc-btn" style="background:#334155; color:#fff; height:44px; padding:0 20px; font-weight:800;">Send Reset Link</button>
                                </div>
                                <div class="mpc-auth-links-row">
                                    <span id="mpc-l3-forgot-msg" style="font-weight:700;"></span>
                                    <a href="#signin-l3" id="mpc-l3-back-signin" style="color:#1B5E20; font-weight:700; text-decoration:underline;">&larr; Back to Sign In</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ( $show_l3_tabs && is_user_logged_in() && ! $show_l1_bar ) : ?>
                    <div class="mpc-top-account-bar is-logged-in" style="margin-bottom:20px;">
                        <div class="mpc-account-bar-header">
                            <div class="mpc-account-bar-title">
                                <span style="color:#1B5E20;">&#10003; <strong>Welcome back, <?php echo esc_html( $saved_user_details['first_name'] ); ?>!</strong></span>
                                <?php if ( $requires_deposit_init === 'false' ) : ?>
                                    <span class="mpc-deposit-waived-pill">&#10003; Thermal Bag Deposit Waived</span>
                                <?php endif; ?>
                            </div>
                            <label style="cursor:pointer; display:inline-flex; align-items:center; gap:7px; font-size:0.86rem; font-weight:700; color:#1B5E20; margin:0;">
                                <input type="checkbox" id="mpc_use_saved_details" <?php echo $autofill_default ? 'checked' : ''; ?> style="accent-color:#379237; transform:scale(1.15);">
                                <span>Auto-fill my saved delivery details</span>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <div id="mpc-step-1-plans-container">
                    <?php
                    if ( empty( $products ) ) {
                        echo '<p>No products found.</p>';
                    } else {
                        foreach ( $grouped_plans as $group_data ) {
                            if ( empty( $group_data['items'] ) ) {
                                continue;
                            }
                            echo '<h3 class="mpc-cat-header">' . esc_html( $group_data['label'] ) . '</h3>';
                            echo '<div class="mpc-grid">';
                            foreach ( $group_data['items'] as $product ) {
                                $id            = $product->get_id();
                                $title         = $product->get_name();
                                $price_html    = $product->get_price_html();
                                $raw_price     = $product->get_price();
                                $desc          = $product->get_short_description();
                                $is_juice      = ( stripos( $title, 'juice' ) !== false || stripos( $title, 'cleanse' ) !== false ) ? 'true' : 'false';
                                preg_match( '/(\d+)\s*Meal/i', $title, $m );
                                $allowed_meals = isset( $m[1] ) ? intval( $m[1] ) : 0;
                                $display_title = str_replace( ' - ', ' - <br>', esc_html( $title ) );
                                echo '<div class="mpc-tile" onclick="mpcSelectPlan(this, \'' . esc_attr( $title ) . '\', \'' . esc_attr( $raw_price ) . '\', ' . $is_juice . ', ' . $id . ', ' . $allowed_meals . ')">';
                                echo '<div><div class="mpc-tile-title">' . $display_title . '</div>';
                                echo '<div class="mpc-tile-price">' . wp_kses_post( $price_html ) . '</div></div>';
                                if ( ! empty( $desc ) ) {
                                    echo '<div style="font-size: 0.9em; color: #666; margin-top: 10px;">' . wp_kses_post( $desc ) . '</div>';
                                }
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
                
                <div class="mpc-form-group" id="mpc-coupon-section" style="display: none; border-top: 1px solid #eee; padding-top: 25px; margin-top: 20px;">
                    <label style="font-weight: 600; color: #334155;">Have a Discount Code?</label>
                    <div style="display: flex; gap: 10px; align-items: flex-start; margin-top: 8px;">
                        <input type="text" id="mpc_coupon_input" class="mpc-form-control" placeholder="Enter coupon code" style="text-transform: uppercase; max-width: 280px; letter-spacing: 1px;">
                        <button type="button" id="mpc-apply-coupon-btn" class="mpc-btn" style="background: #334155; color: #fff; height: 44px; padding: 0 20px; font-size: 0.9em; white-space: nowrap;">Apply Code</button>
                    </div>
                    <div id="mpc-coupon-feedback" style="margin-top: 10px; font-size: 0.9em; font-weight: bold;"></div>
                </div>

                <div class="mpc-nav-buttons">
                    <button class="mpc-btn mpc-btn-next" onclick="mpcChangeStep(1)" id="btn-next-1" disabled>Next: Delivery Details &rarr;</button>
                </div>
            </div>

            <div id="mpc-step-2" class="mpc-step-content">
                <h2 style="margin-top: 0; color: #222;">Delivery Information</h2>

                <?php if ( $show_step2_login ) : ?>
                    <?php if ( ! is_user_logged_in() ) : ?>
                        <div id="mpc-login-section" style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #cbd5e1; margin-bottom: 25px;">
                            <p style="margin: 0;"><strong>Already a customer?</strong> <a href="#" id="mpc-show-login" style="color: #379237; text-decoration: underline;">Click here to log in</a></p>
                            <div id="mpc-login-form" style="display: none; margin-top: 15px;">
                                <div class="mpc-form-row">
                                    <div class="mpc-form-col"><input type="email" id="mpc_login_email" class="mpc-form-control" placeholder="Email Address"></div>
                                    <div class="mpc-form-col"><input type="password" id="mpc_login_pwd" class="mpc-form-control" placeholder="Password"></div>
                                </div>
                                <button type="button" id="mpc-do-login-btn" class="mpc-btn" style="background: #334155; color: #fff; margin-top: 15px; height: 40px; padding: 0 20px; font-size: 0.9em;">Secure Log In</button>
                                <span id="mpc-login-msg" style="color: #e11d48; margin-left: 15px; font-size: 0.9em; font-weight: bold;"></span>
                            </div>
                        </div>
                    <?php else : ?>
                        <div id="mpc-logged-in-section" style="background: #f4fdf4; padding: 20px; border-radius: 8px; border: 1px solid #379237; margin-bottom: 25px;">
                            <p style="margin: 0 0 10px 0; color: #222;"><strong>Welcome back, <?php echo esc_html( $saved_user_details['first_name'] ); ?>!</strong></p>
                            <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: normal;">
                                <input type="checkbox" id="mpc_use_saved_details" <?php echo $autofill_default ? 'checked' : ''; ?> style="transform: scale(1.2);"> Use my saved delivery details (you can customize delivery timing or allergies for this specific plan)
                            </label>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="mpc-form-row">
                    <div class="mpc-form-col mpc-form-group">
                        <label>First Name *</label>
                        <input type="text" class="mpc-form-control" id="mpc_first_name" placeholder="Enter Your First Name" required>
                    </div>
                    <div class="mpc-form-col mpc-form-group">
                        <label>Last Name *</label>
                        <input type="text" class="mpc-form-control" id="mpc_last_name" placeholder="Enter Your Last Name" required>
                    </div>
                </div>

                <div class="mpc-form-row">
                    <div class="mpc-form-col mpc-form-group">
                        <label>Email Address *</label>
                        <input type="email" class="mpc-form-control" id="mpc_email" placeholder="john@example.com" required <?php if ( is_user_logged_in() ) echo 'readonly style="background: #f1f5f9; cursor: not-allowed;"'; ?>>
                        <div id="mpc-email-exists-hint" class="mpc-email-exists-hint">
                            &#128100; An account with this email already exists. <a href="#signin" id="mpc-email-hint-trigger" style="color:#1B5E20; font-weight:800; text-decoration:underline;">Click here to sign in</a> and waive your bag deposit.
                        </div>
                    </div>
                    <div class="mpc-form-col mpc-form-group">
                        <label>Phone Number *</label>
                        <input type="tel" class="mpc-form-control" id="mpc_phone" placeholder="+971 50 000 0000" required>
                    </div>
                </div>

                <div class="mpc-form-row" id="mpc_password_group" <?php if ( is_user_logged_in() ) echo 'style="display:none;"'; ?>>
                    <div class="mpc-form-col mpc-form-group">
                        <label>Create Account Password *</label>
                        <div style="position: relative; display: flex; align-items: center; width: 100%;">
                            <input type="password" class="mpc-form-control" id="mpc_password" placeholder="Required for Account Creation" style="padding-right: 45px; width: 100%;">
                            <span id="mpc_toggle_password" style="position: absolute; right: 12px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; height: 100%; z-index: 50;">
                                <svg id="icon-eye-open" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                <svg id="icon-eye-closed" style="display:none;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                            </span>
                        </div>
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid #eee; margin: 25px 0;">

                <div class="mpc-form-row">
                    <div class="mpc-form-col mpc-form-group">
                        <label>Address Line 1 *</label>
                        <input type="text" class="mpc-form-control" id="mpc_address_1" placeholder="House number and street name" required>
                    </div>
                    <div class="mpc-form-col mpc-form-group">
                        <label>Address Line 2 (Optional)</label>
                        <input type="text" class="mpc-form-control" id="mpc_address_2" placeholder="Apartment, suite, unit, etc.">
                    </div>
                </div>

                <div class="mpc-form-row">
                    <div class="mpc-form-col mpc-form-group" style="flex: unset; width: 100%;">
                        <label>City &amp; Country</label>
                        <input type="text" class="mpc-form-control" value="Dubai, UAE" disabled style="background: #f8fafc; color: #94a3b8; cursor: not-allowed;">
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid #eee; margin: 25px 0;">

                <div class="mpc-form-row">
                    <div class="mpc-form-col mpc-form-group">
                        <label>Delivery Method *</label>
                        <select class="mpc-form-control" id="mpc_delivery_method">
                            <option value="Delivery">Home/Office Delivery</option>
                            <option value="Pickup">Store Pick-up</option>
                        </select>
                    </div>
                    <div class="mpc-form-col mpc-form-group">
                        <label>Receive by *</label>
                        <select class="mpc-form-control" id="mpc_delivery_timing">
                            <option value="Deliver Day Before">Deliver Day Before</option>
                            <option value="Deliver Same Day">Deliver Same Day</option>
                        </select>
                    </div>
                </div>

                <div class="mpc-form-row" id="mpc_dynamic_logistics_row" style="margin-bottom: 20px;">
                    <div class="mpc-form-col mpc-form-group" style="margin-bottom: 0;">
                        <div id="mpc_delivery_zone_container" class="mpc-logistics-box" style="margin-top: 0;">
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-weight: normal; margin: 0; line-height: 1.4;">
                                <input type="checkbox" id="mpc_delivery_zone_check" style="transform: scale(1.3); margin-top: 2px;">
                                <span>I confirm I am within the delivery zone. (<a href="<?php echo esc_url( $map_url ); ?>" target="_blank" style="color: #379237; text-decoration: underline;">View Map</a>) *</span>
                            </label>
                        </div>
                        <div id="mpc_pickup_branch_container" class="mpc-logistics-box" style="display: none; margin-top: 0; padding-bottom: 5px;">
                            <label style="margin-bottom: 5px;">Select Pickup Branch *</label>
                            <select class="mpc-form-control" id="mpc_pickup_branch">
                                <option value="">- Select Branch -</option>
                                <option value="Jumeirah">Jumeirah</option>
                                <option value="Motor City">Motor City</option>
                            </select>
                        </div>
                    </div>
                    <div class="mpc-form-col mpc-form-group" style="margin-bottom: 0;">
                        <label>Delivery/Pickup Timing *</label>
                        <select class="mpc-form-control" id="mpc_time_slot">
                            <?php
                            for ( $i = 5; $i < 20; $i++ ) {
                                $start_time = date( 'g:i A', strtotime( "$i:00" ) );
                                $end_time   = date( 'g:i A', strtotime( ( $i + 1 ) . ':00' ) );
                                echo '<option value="' . esc_attr( $start_time . ' to ' . $end_time ) . '">' . esc_html( $start_time . ' to ' . $end_time ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="mpc-nav-buttons">
                    <button class="mpc-btn mpc-btn-back" onclick="mpcChangeStep(-1)">&larr; Back</button>
                    <button class="mpc-btn mpc-btn-next" onclick="mpcChangeStep(1)" id="btn-next-2">Next Step &rarr;</button>
                </div>
            </div>

            <div id="mpc-step-3" class="mpc-step-content">
                <h2 style="margin-top: 0; color: #222;">Dietary Info &amp; Recipient</h2>
                <p id="mpc-meals-subtitle" style="color: #666; font-weight: bold;">Who is this plan for, and do they have any food allergies?</p>

                <!-- RECIPIENT FIELD FOR CUSTOM CHECKOUT -->
                <div class="mpc-form-group" style="margin-bottom: 20px;">
                    <label style="font-weight:bold; color: #334155; margin-bottom: 5px; display: block;">
                        Recipient Name (Optional)
                        <span title="If buying for a family member or a second plan, enter a name here so it gets its own dedicated calendar tab and independent delivery settings in the dashboard." style="cursor:help; font-size:0.85em; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:50%; margin-left:5px; vertical-align:middle; display:inline-block;">?</span>
                    </label>
                    <input type="text" class="mpc-form-control" id="mpc_recipient_name" placeholder="e.g., Sarah, John, Office Plan, etc.">
                </div>

                <div class="mpc-form-group">
                    <label style="font-weight:bold; color: #334155; margin-bottom: 5px; display: block;">Allergies (Saved Specifically to This Plan)</label>
                    <textarea class="mpc-form-control" id="mpc_allergies" rows="4" placeholder="e.g., Nuts, Shellfish, Gluten... (Leave blank if no allergies for this plan)"></textarea>
                </div>

                <div class="mpc-nav-buttons">
                    <button class="mpc-btn mpc-btn-back" onclick="mpcChangeStep(-1)">&larr; Back</button>
                    <button class="mpc-btn mpc-btn-next" onclick="mpcChangeStep(1)" id="btn-next-3" style="background: #46b450;">Next: Place Order &rarr;</button>
                </div>
            </div>
        </div>

        <div class="mpc-summary-area">
            <h3 style="margin-top: 0; border-bottom: 2px solid #ddd; padding-bottom: 10px;">Plan Summary</h3>
            <div id="mpc-summary-content"><p style="color: #666; font-style: italic;">Please select a plan from Step 1.</p></div>

            <div id="mpc-summary-logistics" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ddd; font-size: 0.9em;">
                <strong>Method:</strong> <span id="sum-method"></span><br>
                <strong>Receive By:</strong> <span id="sum-timing"></span>
            </div>
        </div>
    </div>

    <script>
        let currentStep  = 1;
        let totalSteps   = 3;
        let checkoutData = { productId: null, planName: '', planPrice: 0, isJuice: false, allowedMeals: 0 };
        
        let isUserLoggedIn    = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
        let requiresDeposit   = <?php echo $requires_deposit_init; ?>;
        let userDepositStatus = '<?php echo esc_js( $deposit_meta_init ); ?>';
        let activeVipToken    = '';
        let autoFillOnLogin   = <?php echo $autofill_default ? 'true' : 'false'; ?>;
        let emailGuardEnabled = <?php echo $email_guard_on ? 'true' : 'false'; ?>;
        
        let appliedCoupon = { code: '', discountType: '', amount: 0, label: '' };

        // Non-Blocking Toast Notification Helper
        function mpcShowNotice(message, type) {
            document.querySelectorAll('.mpc-toast-notice').forEach(el => el.remove());
            const toast = document.createElement('div');
            toast.className = 'mpc-toast-notice';
            const isSuccess = (type === 'success');
            Object.assign(toast.style, {
                position: 'fixed',
                top: '22px',
                left: '50%',
                transform: 'translateX(-50%)',
                background: isSuccess ? '#1B5E20' : '#dc2626',
                color: '#ffffff',
                border: '1.5px solid ' + (isSuccess ? '#68B946' : '#fca5a5'),
                borderRadius: '10px',
                padding: '12px 20px',
                fontSize: '0.9rem',
                fontWeight: '800',
                boxShadow: '0 10px 25px rgba(15, 23, 42, 0.25)',
                zIndex: '100099',
                maxWidth: '92vw',
                textAlign: 'center'
            });
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.transition = 'opacity 0.25s ease';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 260);
            }, 3800);
        }

        let _mpcFreshNonce = null;
        fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', {
            method: 'POST',
            body: new URLSearchParams({ action: 'mpc_get_nonce' })
        })
        .then(res => res.json())
        .then(response => {
            if (response.success && response.data.nonce) {
                _mpcFreshNonce = response.data.nonce;
            }
        });

        // LIVE VIP TOKEN VALIDATION (Bypasses Page Cache)
        const urlParams = new URLSearchParams(window.location.search);
        const urlToken  = urlParams.get('vip_token');

        if (urlToken) {
            let formData = new URLSearchParams();
            formData.append('action', 'mpc_validate_vip_token');
            formData.append('token', urlToken);

            fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    activeVipToken = urlToken;
                    requiresDeposit = false;
                    mpcRenderSummary();
                } else {
                    mpcShowNotice("This VIP link is invalid. It may have expired, been used already, or been revoked by the FOH team.", "error");
                    window.history.replaceState({}, document.title, window.location.pathname);
                    mpcRenderSummary();
                }
            });
        }

        // ---- NATIVE MATH SUMMARY UI ----
        function mpcRenderSummary() {
            if (!checkoutData.planPrice) {
                document.getElementById('mpc-summary-content').innerHTML = '<p style="color: #666; font-style: italic;">Please select a plan from Step 1.</p>';
                return;
            }

            let basePrice = parseFloat(checkoutData.planPrice);
            let discountAmt = 0;
            let is100PercentFree = false;

            if (appliedCoupon.code) {
                if (appliedCoupon.discountType === 'percent') {
                    discountAmt = parseFloat((basePrice * appliedCoupon.amount / 100).toFixed(2));
                    if (appliedCoupon.amount >= 100) is100PercentFree = true;
                } else if (appliedCoupon.discountType === 'fixed_cart') {
                    discountAmt = parseFloat(Math.min(appliedCoupon.amount, basePrice).toFixed(2));
                    if (discountAmt >= basePrice) is100PercentFree = true;
                }
            }

            let discountedPrice = basePrice - discountAmt;
            let depositAmt = (requiresDeposit && !is100PercentFree) ? 150 : 0;
            let newTotal = discountedPrice + depositAmt;

            let html = `<div style="margin-bottom: 15px;"><strong>Plan:</strong><br><span style="color: #379237; font-size: 1.1em;">${checkoutData.planName}</span></div>`;
            html += `<div style="padding-top: 15px; border-top: 1px dashed #ddd; font-size: 0.95em;">`;

            html += `<div style="display:flex; justify-content:space-between; margin-bottom: 5px;"><span>Plan Price:</span> <strong>AED ${basePrice.toFixed(2)}</strong></div>`;

            if (discountAmt > 0) {
                html += `<div style="display:flex; justify-content:space-between; margin-bottom: 5px; color: #16a34a;"><span>Discount (${appliedCoupon.label}):</span> <strong>- AED ${discountAmt.toFixed(2)}</strong></div>`;
                html += `<div style="display:flex; justify-content:space-between; margin-bottom: 5px; border-bottom: 1px solid #eee; padding-bottom: 5px;"><span>Discounted Price:</span> <strong>AED ${discountedPrice.toFixed(2)}</strong></div>`;
                html += `<div style="text-align: right; margin-bottom: 10px;"><a href="#" id="mpc-remove-coupon" style="color: #dc2626; font-size: 0.85em; text-decoration: underline;">Remove Coupon</a></div>`;
            }

            if (depositAmt > 0) {
                html += `<div style="display:flex; justify-content:space-between; margin-bottom: 2px; color: #b45309;"><span>Thermal Bag Deposit:</span> <strong>+ AED ${depositAmt.toFixed(2)}</strong></div>`;
                html += `<div style="font-size: 0.8em; color: #b45309; margin-bottom: 10px; text-align: right; opacity: 0.9;">Only applicable for new subscribers. Sign in above if you are an existing customer.</div>`;
            } else if (activeVipToken !== '' || userDepositStatus === 'own_bag') {
                html += `<div style="display:flex; justify-content:space-between; margin-bottom: 10px; color: #8b5cf6;"><span>Thermal Bag Deposit:</span> <strong>Waived (Customer's Own Bag)</strong></div>`;
            } else if (!requiresDeposit && !is100PercentFree) {
                html += `<div style="display:flex; justify-content:space-between; margin-bottom: 10px; color: #16a34a;"><span>Thermal Bag Deposit:</span> <strong>Waived (Account Held)</strong></div>`;
            } else if (requiresDeposit && is100PercentFree) {
                html += `<div style="display:flex; justify-content:space-between; margin-bottom: 10px; color: #16a34a;"><span>Thermal Bag Deposit:</span> <strong>Waived (100% Free)</strong></div>`;
            }

            html += `<div style="display:flex; justify-content:space-between; border-top: 2px solid #222; padding-top: 10px; margin-top: 5px; font-size: 1.2em;"><span><strong>New Total:</strong></span> <strong style="color: #222;">AED ${newTotal.toFixed(2)}</strong></div>`;
            html += `</div>`;

            document.getElementById('mpc-summary-content').innerHTML = html;

            let removeBtn = document.getElementById('mpc-remove-coupon');
            if (removeBtn) {
                removeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    appliedCoupon = { code: '', discountType: '', amount: 0, label: '' };
                    document.getElementById('mpc_coupon_input').value    = '';
                    document.getElementById('mpc_coupon_input').readOnly = false;
                    document.getElementById('mpc-apply-coupon-btn').style.display = '';
                    document.getElementById('mpc-coupon-feedback').innerText      = '';
                    mpcSaveState();
                    mpcRenderSummary(); 
                });
            }
        }

        document.getElementById('mpc-apply-coupon-btn').addEventListener('click', function() {
            let code     = document.getElementById('mpc_coupon_input').value.trim().toUpperCase();
            let feedback = document.getElementById('mpc-coupon-feedback');
            
            if (!checkoutData.productId) { 
                feedback.style.color = '#dc2626'; feedback.innerText = 'Please select a plan from Step 1 first.'; return; 
            }
            if (!code) { 
                feedback.style.color = '#dc2626'; feedback.innerText = 'Please enter a coupon code.'; return; 
            }
            
            let userEmail = document.getElementById('mpc_email').value;

            this.innerText = 'Checking...'; this.disabled = true; feedback.innerText = '';
            
            let formData = new URLSearchParams();
            formData.append('action', 'mpc_validate_coupon');
            formData.append('nonce', _mpcFreshNonce || '<?php echo esc_js( wp_create_nonce( "mpc_checkout_nonce" ) ); ?>');
            formData.append('coupon_code', code);
            formData.append('product_id', checkoutData.productId);
            formData.append('email', userEmail);
            
            fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    appliedCoupon = {
                        code:         response.data.code,
                        discountType: response.data.discountType,
                        amount:       parseFloat(response.data.amount),
                        label:        response.data.label
                    };
                    feedback.style.color = '#16a34a';
                    feedback.innerText   = '\u2713 ' + response.data.label + ' applied!';
                    document.getElementById('mpc_coupon_input').readOnly = true;
                    document.getElementById('mpc-apply-coupon-btn').style.display = 'none';
                    mpcRenderSummary(); 
                    mpcSaveState();
                } else {
                    appliedCoupon = { code: '', discountType: '', amount: 0, label: '' };
                    feedback.style.color = '#dc2626';
                    feedback.innerText   = '\u2717 ' + response.data;
                }
                document.getElementById('mpc-apply-coupon-btn').innerText = 'Apply Code';
                document.getElementById('mpc-apply-coupon-btn').disabled  = false;
            })
            .catch(() => {
                feedback.style.color = '#dc2626';
                feedback.innerText   = 'Connection error. Please try again.';
                document.getElementById('mpc-apply-coupon-btn').innerText = 'Apply Code';
                document.getElementById('mpc-apply-coupon-btn').disabled  = false;
            });
        });

        let pwdToggleEl = document.getElementById('mpc_toggle_password');
        if (pwdToggleEl) {
            pwdToggleEl.addEventListener('click', function(e) {
                e.preventDefault();
                let pwdInput   = document.getElementById('mpc_password');
                let iconOpen   = document.getElementById('icon-eye-open');
                let iconClosed = document.getElementById('icon-eye-closed');
                if (pwdInput.type === 'password') {
                    pwdInput.type = 'text'; iconOpen.style.display = 'none'; iconClosed.style.display = 'block';
                } else {
                    pwdInput.type = 'password'; iconOpen.style.display = 'block'; iconClosed.style.display = 'none';
                }
            });
        }

        function populateFields(data) {
            if (!data) return;
            if (data.first_name) document.getElementById('mpc_first_name').value = data.first_name;
            if (data.last_name)  document.getElementById('mpc_last_name').value  = data.last_name;
            if (data.email) {
                let ef = document.getElementById('mpc_email');
                ef.value = data.email; ef.readOnly = true;
                ef.style.background = '#f1f5f9'; ef.style.cursor = 'not-allowed';
                let hintEl = document.getElementById('mpc-email-exists-hint');
                if (hintEl) hintEl.style.display = 'none';
            }
            if (data.phone)     document.getElementById('mpc_phone').value     = data.phone;
            if (data.address_1) document.getElementById('mpc_address_1').value = data.address_1;
            if (data.address_2) document.getElementById('mpc_address_2').value = data.address_2;
            if (data.delivery_method) {
                document.getElementById('mpc_delivery_method').value = data.delivery_method;
                document.getElementById('mpc_delivery_method').dispatchEvent(new Event('change'));
            }
            if (data.delivery_timing) document.getElementById('mpc_delivery_timing').value = data.delivery_timing;
            if (data.time_slot)       document.getElementById('mpc_time_slot').value       = data.time_slot;
            if (data.pickup_location && data.delivery_method === 'Pickup') {
                document.getElementById('mpc_pickup_branch').value = data.pickup_location;
            }
            if (typeof data.allergies !== 'undefined' && data.allergies !== null) {
                document.getElementById('mpc_allergies').value = data.allergies;
            }
            mpcSaveState();
            updateLogisticsSummary();
        }

        // Unified Post-Login UI Updater (Works for Option L1, Option L3, and Step 2 Login)
        function handleSuccessfulAjaxLogin(userData) {
            isUserLoggedIn    = true;
            requiresDeposit   = userData.requires_deposit;
            userDepositStatus = userData.deposit_status;
            window.mpcSavedDetails = userData;

            if (userData.new_nonce) {
                _mpcFreshNonce = userData.new_nonce;
            }

            let pwdGroup = document.getElementById('mpc_password_group');
            if (pwdGroup) pwdGroup.style.display = 'none';

            let hintEl = document.getElementById('mpc-email-exists-hint');
            if (hintEl) hintEl.style.display = 'none';

            // Always lock verified account email
            if (userData.email) {
                let ef = document.getElementById('mpc_email');
                ef.value = userData.email;
                ef.readOnly = true;
                ef.style.background = '#f1f5f9';
                ef.style.cursor = 'not-allowed';
            }

            let depositBadgeHtml = (!requiresDeposit)
                ? '<span class="mpc-deposit-waived-pill">&#10003; Thermal Bag Deposit Waived</span>'
                : '';

            let checkedAttr = autoFillOnLogin ? 'checked' : '';
            let barHtml = `
                <div class="mpc-account-bar-header">
                    <div class="mpc-account-bar-title">
                        <span style="color:#1B5E20;">&#10003; <strong>Welcome back, ${userData.first_name}!</strong></span>
                        ${depositBadgeHtml}
                    </div>
                    <label style="cursor:pointer; display:inline-flex; align-items:center; gap:7px; font-size:0.86rem; font-weight:700; color:#1B5E20; margin:0;">
                        <input type="checkbox" id="mpc_use_saved_details_dynamic" ${checkedAttr} style="accent-color:#379237; transform:scale(1.15);">
                        <span>Auto-fill my saved delivery details</span>
                    </label>
                </div>`;

            let topBar = document.getElementById('mpc-top-account-bar');
            if (topBar) {
                topBar.classList.add('is-logged-in');
                topBar.innerHTML = barHtml;
            }

            let l3Wrap = document.getElementById('mpc-l3-wrapper');
            if (l3Wrap) {
                let plansCont = document.getElementById('mpc-step-1-plans-container');
                if (plansCont) plansCont.style.display = 'block';
                if (!topBar) {
                    l3Wrap.innerHTML = `<div class="mpc-top-account-bar is-logged-in" style="margin-bottom:20px;">${barHtml}</div>`;
                } else {
                    l3Wrap.style.display = 'none';
                }
            }

            let step2LoginSec = document.getElementById('mpc-login-section');
            if (step2LoginSec) {
                step2LoginSec.innerHTML = `<div style="background:#f4fdf4; padding:16px 20px; border-radius:8px; border:1px solid #379237;">${barHtml}</div>`;
            }

            if (autoFillOnLogin) {
                populateFields(userData);
            }

            document.querySelectorAll('#mpc_use_saved_details_dynamic').forEach(chk => {
                chk.addEventListener('change', function() {
                    if (this.checked) populateFields(userData);
                });
            });

            mpcRenderSummary();
            mpcShowNotice('Signed in as ' + userData.first_name + '!', 'success');
        }

        function executeAjaxLogin(emailInputId, pwdInputId, msgElId, btnEl) {
            let log = document.getElementById(emailInputId).value.trim();
            let pwd = document.getElementById(pwdInputId).value;
            let msg = document.getElementById(msgElId);
            if (!log || !pwd) {
                if (msg) msg.innerText = 'Please enter both email and password.';
                return;
            }
            let origText = btnEl.innerText;
            btnEl.innerText = 'Signing in...';
            btnEl.disabled  = true;
            if (msg) msg.innerText = '';

            let formData = new URLSearchParams();
            formData.append('action', 'mpc_login_user');
            formData.append('nonce', _mpcFreshNonce || '<?php echo esc_js( wp_create_nonce( "mpc_checkout_nonce" ) ); ?>');
            formData.append('log', log);
            formData.append('pwd', pwd);

            fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    handleSuccessfulAjaxLogin(response.data);
                } else {
                    if (msg) msg.innerText = response.data || 'Sign-in failed.';
                    btnEl.innerText = origText;
                    btnEl.disabled  = false;
                }
            })
            .catch(() => {
                if (msg) msg.innerText = 'Connection error. Please try again.';
                btnEl.innerText = origText;
                btnEl.disabled  = false;
            });
        }

        function executeAjaxForgotPassword(emailInputId, msgElId, btnEl) {
            let userLogin = document.getElementById(emailInputId).value.trim();
            let msg       = document.getElementById(msgElId);
            if (!userLogin) {
                if (msg) { msg.style.color = '#e11d48'; msg.innerText = 'Please enter your email address.'; }
                return;
            }
            let origText = btnEl.innerText;
            btnEl.innerText = 'Sending...';
            btnEl.disabled  = true;
            if (msg) msg.innerText = '';

            let formData = new URLSearchParams();
            formData.append('action', 'mpc_forgot_password');
            formData.append('nonce', _mpcFreshNonce || '<?php echo esc_js( wp_create_nonce( "mpc_checkout_nonce" ) ); ?>');
            formData.append('user_login', userLogin);

            fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    if (msg) { msg.style.color = '#16a34a'; msg.innerText = '\u2713 ' + response.data; }
                    document.getElementById(emailInputId).value = '';
                } else {
                    if (msg) { msg.style.color = '#e11d48'; msg.innerText = response.data || 'Error sending reset link.'; }
                }
                btnEl.innerText = origText;
                btnEl.disabled  = false;
            })
            .catch(() => {
                if (msg) { msg.style.color = '#e11d48'; msg.innerText = 'Network error. Please try again.'; }
                btnEl.innerText = origText;
                btnEl.disabled  = false;
            });
        }

        // Bind Option L1 Top Account Bar Controls
        let topToggleBtn = document.getElementById('mpc-top-login-toggle');
        if (topToggleBtn) {
            topToggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                let drawer = document.getElementById('mpc-top-auth-drawer');
                let arrow  = document.getElementById('mpc-top-login-arrow');
                if (drawer.style.display === 'block') {
                    drawer.style.display = 'none';
                    if (arrow) arrow.innerHTML = '&#9662;';
                } else {
                    drawer.style.display = 'block';
                    if (arrow) arrow.innerHTML = '&#9652;';
                    let em = document.getElementById('mpc_top_login_email');
                    if (em) em.focus();
                }
            });
        }

        let topLoginBtn = document.getElementById('mpc-top-login-submit');
        if (topLoginBtn) {
            topLoginBtn.addEventListener('click', function() {
                executeAjaxLogin('mpc_top_login_email', 'mpc_top_login_pwd', 'mpc-top-login-msg', this);
            });
        }

        let topShowForgot = document.getElementById('mpc-top-show-forgot');
        if (topShowForgot) {
            topShowForgot.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('mpc-top-signin-view').style.display = 'none';
                document.getElementById('mpc-top-forgot-view').style.display = 'block';
                let loginEm = document.getElementById('mpc_top_login_email').value.trim();
                if (loginEm) document.getElementById('mpc_top_forgot_email').value = loginEm;
            });
        }

        let topBackSignin = document.getElementById('mpc-top-back-signin');
        if (topBackSignin) {
            topBackSignin.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('mpc-top-forgot-view').style.display = 'none';
                document.getElementById('mpc-top-signin-view').style.display = 'block';
            });
        }

        let topForgotBtn = document.getElementById('mpc-top-forgot-submit');
        if (topForgotBtn) {
            topForgotBtn.addEventListener('click', function() {
                executeAjaxForgotPassword('mpc_top_forgot_email', 'mpc-top-forgot-msg', this);
            });
        }

        // Bind Option L3 Two-Tab Mode Switcher Controls
        document.querySelectorAll('.mpc-l3-tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.mpc-l3-tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                let mode = this.getAttribute('data-l3-tab');
                let retPanel  = document.getElementById('mpc-l3-returning-panel');
                let plansCont = document.getElementById('mpc-step-1-plans-container');
                if (mode === 'returning') {
                    if (retPanel) retPanel.style.display = 'block';
                    if (plansCont) plansCont.style.display = 'none';
                    let em = document.getElementById('mpc_l3_login_email');
                    if (em) em.focus();
                } else {
                    if (retPanel) retPanel.style.display = 'none';
                    if (plansCont) plansCont.style.display = 'block';
                }
            });
        });

        let l3LoginBtn = document.getElementById('mpc-l3-login-submit');
        if (l3LoginBtn) {
            l3LoginBtn.addEventListener('click', function() {
                executeAjaxLogin('mpc_l3_login_email', 'mpc_l3_login_pwd', 'mpc-l3-login-msg', this);
            });
        }

        let l3ShowForgot = document.getElementById('mpc-l3-show-forgot');
        if (l3ShowForgot) {
            l3ShowForgot.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('mpc-l3-signin-view').style.display = 'none';
                document.getElementById('mpc-l3-forgot-view').style.display = 'block';
                let loginEm = document.getElementById('mpc_l3_login_email').value.trim();
                if (loginEm) document.getElementById('mpc_l3_forgot_email').value = loginEm;
            });
        }

        let l3BackSignin = document.getElementById('mpc-l3-back-signin');
        if (l3BackSignin) {
            l3BackSignin.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('mpc-l3-forgot-view').style.display = 'none';
                document.getElementById('mpc-l3-signin-view').style.display = 'block';
            });
        }

        let l3ForgotBtn = document.getElementById('mpc-l3-forgot-submit');
        if (l3ForgotBtn) {
            l3ForgotBtn.addEventListener('click', function() {
                executeAjaxForgotPassword('mpc_l3_forgot_email', 'mpc-l3-forgot-msg', this);
            });
        }

        // Bind Legacy Step 2 Login Controls
        let showLogin = document.getElementById('mpc-show-login');
        if (showLogin) {
            showLogin.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('mpc-login-form').style.display = 'block';
                this.style.display = 'none';
            });
        }

        let doLoginBtn = document.getElementById('mpc-do-login-btn');
        if (doLoginBtn) {
            doLoginBtn.addEventListener('click', function() {
                executeAjaxLogin('mpc_login_email', 'mpc_login_pwd', 'mpc-login-msg', this);
            });
        }

        let useSavedCheckbox = document.getElementById('mpc_use_saved_details');
        if (useSavedCheckbox) {
            useSavedCheckbox.addEventListener('change', function() {
                if (this.checked && typeof mpcSavedDetails !== 'undefined') {
                    populateFields(mpcSavedDetails);
                }
            });
        }

        // Smart Existing-Email Detector on Step 2
        let step2EmailInput = document.getElementById('mpc_email');
        if (step2EmailInput && emailGuardEnabled) {
            step2EmailInput.addEventListener('blur', function() {
                if (isUserLoggedIn) return;
                let typedEmail = this.value.trim();
                let hintBox    = document.getElementById('mpc-email-exists-hint');
                if (!typedEmail || typedEmail.indexOf('@') === -1) {
                    if (hintBox) hintBox.style.display = 'none';
                    return;
                }
                let fd = new URLSearchParams();
                fd.append('action', 'mpc_check_email_exists');
                fd.append('email', typedEmail);
                fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(response => {
                    if (response.success && response.data.exists && !isUserLoggedIn) {
                        if (hintBox) hintBox.style.display = 'block';
                    } else if (hintBox) {
                        hintBox.style.display = 'none';
                    }
                });
            });
        }

        let emailHintTrigger = document.getElementById('mpc-email-hint-trigger');
        if (emailHintTrigger) {
            emailHintTrigger.addEventListener('click', function(e) {
                e.preventDefault();
                let typedEmail = document.getElementById('mpc_email').value.trim();
                let topDrawer  = document.getElementById('mpc-top-auth-drawer');
                if (topDrawer) {
                    topDrawer.style.display = 'block';
                    let topEm = document.getElementById('mpc_top_login_email');
                    if (topEm && typedEmail) topEm.value = typedEmail;
                    document.getElementById('mpc-top-account-bar').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => {
                        let pwdEl = document.getElementById('mpc_top_login_pwd');
                        if (pwdEl) pwdEl.focus();
                    }, 350);
                } else if (document.getElementById('mpc-login-form')) {
                    document.getElementById('mpc-login-form').style.display = 'block';
                    if (document.getElementById('mpc-show-login')) document.getElementById('mpc-show-login').style.display = 'none';
                    if (typedEmail) document.getElementById('mpc_login_email').value = typedEmail;
                    document.getElementById('mpc_login_pwd').focus();
                } else {
                    mpcChangeStep(-1);
                    let retTabBtn = document.querySelector('.mpc-l3-tab-btn[data-l3-tab="returning"]');
                    if (retTabBtn) retTabBtn.click();
                    let l3Em = document.getElementById('mpc_l3_login_email');
                    if (l3Em && typedEmail) l3Em.value = typedEmail;
                }
            });
        }

        function mpcSaveState() {
            const state = {
                firstName:         document.getElementById('mpc_first_name').value,
                lastName:          document.getElementById('mpc_last_name').value,
                email:             document.getElementById('mpc_email').value,
                phone:             document.getElementById('mpc_phone').value,
                address1:          document.getElementById('mpc_address_1').value,
                address2:          document.getElementById('mpc_address_2').value,
                deliveryMethod:    document.getElementById('mpc_delivery_method').value,
                deliveryTiming:    document.getElementById('mpc_delivery_timing').value,
                timeSlot:          document.getElementById('mpc_time_slot').value,
                pickupBranch:      document.getElementById('mpc_pickup_branch').value,
                deliveryZoneCheck: document.getElementById('mpc_delivery_zone_check').checked,
                allergies:         document.getElementById('mpc_allergies').value,
                recipientName:     document.getElementById('mpc_recipient_name').value,
                couponCode:        appliedCoupon.code,
            };
            localStorage.setItem('mpcCheckoutState', JSON.stringify(state));
        }

        document.querySelectorAll('.mpc-form-control, #mpc_delivery_zone_check').forEach(el => {
            if (el) { el.addEventListener('input', mpcSaveState); el.addEventListener('change', mpcSaveState); }
        });

        document.addEventListener('DOMContentLoaded', function() {
            if (isUserLoggedIn && autoFillOnLogin && typeof mpcSavedDetails !== 'undefined') {
                populateFields(mpcSavedDetails);
                return;
            }
            const stateJSON = localStorage.getItem('mpcCheckoutState');
            if (stateJSON) {
                try {
                    const state = JSON.parse(stateJSON);
                    if (state.firstName)  document.getElementById('mpc_first_name').value = state.firstName;
                    if (state.lastName)   document.getElementById('mpc_last_name').value  = state.lastName;
                    if (state.email && !isUserLoggedIn) document.getElementById('mpc_email').value = state.email;
                    if (state.phone)      document.getElementById('mpc_phone').value      = state.phone;
                    if (state.address1)   document.getElementById('mpc_address_1').value  = state.address1;
                    if (state.address2)   document.getElementById('mpc_address_2').value  = state.address2;
                    if (state.deliveryMethod) {
                        document.getElementById('mpc_delivery_method').value = state.deliveryMethod;
                        document.getElementById('mpc_delivery_method').dispatchEvent(new Event('change'));
                    }
                    if (state.deliveryTiming)    document.getElementById('mpc_delivery_timing').value       = state.deliveryTiming;
                    if (state.timeSlot)          document.getElementById('mpc_time_slot').value             = state.timeSlot;
                    if (state.pickupBranch)      document.getElementById('mpc_pickup_branch').value         = state.pickupBranch;
                    if (state.deliveryZoneCheck) document.getElementById('mpc_delivery_zone_check').checked = state.deliveryZoneCheck;
                    if (state.allergies)         document.getElementById('mpc_allergies').value             = state.allergies;
                    if (state.recipientName)     document.getElementById('mpc_recipient_name').value        = state.recipientName;
                    
                    document.getElementById('mpc_delivery_method').dispatchEvent(new Event('change'));
                } catch(e) {}
            }
        });

        function mpcSelectPlan(tileElement, planName, planPrice, isJuice, productId, allowedMeals) {
            checkoutData = { productId, planName, planPrice, isJuice, allowedMeals };
            document.querySelectorAll('.mpc-tile').forEach(t => t.classList.remove('selected'));
            tileElement.classList.add('selected');
            document.getElementById('btn-next-1').disabled = false;
            
            if (appliedCoupon.code) {
                appliedCoupon = { code: '', discountType: '', amount: 0, label: '' };
                document.getElementById('mpc_coupon_input').value    = '';
                document.getElementById('mpc_coupon_input').readOnly = false;
                document.getElementById('mpc-apply-coupon-btn').style.display = '';
                document.getElementById('mpc-coupon-feedback').innerText      = '';
                mpcSaveState();
            }

            document.getElementById('mpc-coupon-section').style.display = 'block';

            if (isJuice) {
                document.getElementById('mpc-indicator-meals').style.display = 'none';
                document.querySelectorAll('.mpc-step-indicator').forEach(el => el.style.width = '50%');
                totalSteps = 2;
                let btn2 = document.getElementById('btn-next-2');
                btn2.innerText = 'Proceed to Checkout \u2192'; btn2.style.background = '#46b450'; btn2.style.color = '#fff';
            } else {
                document.getElementById('mpc-indicator-meals').style.display = 'block';
                document.querySelectorAll('.mpc-step-indicator').forEach(el => el.style.width = '33.33%');
                totalSteps = 3;
                let btn2 = document.getElementById('btn-next-2');
                btn2.innerText = 'Next Step \u2192'; btn2.style.background = '#379237'; btn2.style.color = '#fff';
            }
            
            mpcRenderSummary(); 
            updateLogisticsSummary();
            
            setTimeout(function() {
                let nextBtn = document.getElementById('btn-next-1');
                if (nextBtn) { window.scrollTo({ top: nextBtn.getBoundingClientRect().top - 150, behavior: 'smooth' }); }
            }, 250);
        }

        function mpcAdjustTimingOptions() {
            const method         = document.getElementById('mpc_delivery_method').value;
            const timeSlotSelect = document.getElementById('mpc_time_slot');
            const options        = timeSlotSelect.options;
            let resetNeeded      = false;
            for (let i = 0; i < options.length; i++) {
                const isEarlySlot = /^(5|6|7):00 AM/.test(options[i].value);
                if (method === 'Pickup' && isEarlySlot) {
                    options[i].disabled = true; options[i].hidden = true;
                    if (options[i].selected) resetNeeded = true;
                } else {
                    options[i].disabled = false; options[i].hidden = false;
                }
            }
            if (resetNeeded) timeSlotSelect.value = "8:00 AM to 9:00 AM";
        }

        document.getElementById('mpc_delivery_method').addEventListener('change', function() {
            if (this.value === 'Delivery') {
                document.getElementById('mpc_delivery_zone_container').style.display = 'block';
                document.getElementById('mpc_pickup_branch_container').style.display = 'none';
            } else {
                document.getElementById('mpc_delivery_zone_container').style.display = 'none';
                document.getElementById('mpc_pickup_branch_container').style.display = 'block';
            }
            mpcAdjustTimingOptions(); updateLogisticsSummary();
        });

        document.getElementById('mpc_delivery_timing').addEventListener('change', updateLogisticsSummary);
        document.getElementById('mpc_pickup_branch').addEventListener('change',   updateLogisticsSummary);
        document.getElementById('mpc_time_slot').addEventListener('change',       updateLogisticsSummary);

        function updateLogisticsSummary() {
            let method = document.getElementById('mpc_delivery_method').value;
            let displayMethod = method === 'Pickup' ? 'Pickup (' + document.getElementById('mpc_pickup_branch').value + ')' : method;
            document.getElementById('mpc-summary-logistics').style.display = 'block';
            document.getElementById('sum-method').innerText = displayMethod;
            document.getElementById('sum-timing').innerHTML = document.getElementById('mpc_delivery_timing').value +
                '<br><strong>Time:</strong> ' + document.getElementById('mpc_time_slot').value;
        }

        function mpcChangeStep(direction) {
            if (direction === 1 && currentStep === 2) {
                let pwdVal = document.getElementById('mpc_password').value;
                if (!document.getElementById('mpc_first_name').value || !document.getElementById('mpc_email').value ||
                    (!isUserLoggedIn && !pwdVal) || !document.getElementById('mpc_phone').value || !document.getElementById('mpc_address_1').value) {
                    mpcShowNotice('Please fill out all mandatory fields (Name, Email, Phone, Address, Password).', 'error');
                    return;
                }
                let method = document.getElementById('mpc_delivery_method').value;
                if (method === 'Delivery' && !document.getElementById('mpc_delivery_zone_check').checked) {
                    mpcShowNotice('Please confirm you are within the delivery zone to proceed.', 'error');
                    return;
                }
                if (method === 'Pickup' && !document.getElementById('mpc_pickup_branch').value) {
                    mpcShowNotice('Please select a pickup branch.', 'error');
                    return;
                }
                if (checkoutData.isJuice) {
                    mpcSubmitOrder(document.getElementById('btn-next-2'));
                    return;
                }
            }
            
            if (direction === 1 && currentStep === 3 && !checkoutData.isJuice) {
                mpcSubmitOrder(document.getElementById('btn-next-3'));
                return;
            }

            document.getElementById('mpc-step-' + currentStep).classList.remove('active');
            currentStep += direction;
            if (currentStep < 1) currentStep = 1;
            if (currentStep > totalSteps) currentStep = totalSteps;
            document.getElementById('mpc-step-' + currentStep).classList.add('active');

            let indicators = document.getElementsByClassName('mpc-step-indicator');
            for (let i = 0; i < indicators.length; i++) {
                let stepNum = parseInt(indicators[i].getAttribute('data-step'));
                indicators[i].classList.remove('active', 'completed');
                if (stepNum === currentStep) indicators[i].classList.add('active');
                else if (stepNum < currentStep && indicators[i].style.display !== 'none') indicators[i].classList.add('completed');
            }
            document.querySelector('.mpc-checkout-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function mpcSubmitOrder(btn) {
            btn.innerText = 'Redirecting to Checkout...'; btn.disabled = true;

            let activeNonce = _mpcFreshNonce || '<?php echo esc_js( wp_create_nonce( "mpc_checkout_nonce" ) ); ?>';
            let formData = new URLSearchParams();
            formData.append('action',          'mpc_process_order');
            formData.append('nonce',           activeNonce);
            formData.append('product_id',      checkoutData.productId);
            formData.append('first_name',      document.getElementById('mpc_first_name').value);
            formData.append('last_name',       document.getElementById('mpc_last_name').value);
            formData.append('email',           document.getElementById('mpc_email').value);
            formData.append('phone',           document.getElementById('mpc_phone').value);
            if (!isUserLoggedIn) formData.append('password', document.getElementById('mpc_password').value);
            formData.append('address_1',       document.getElementById('mpc_address_1').value);
            formData.append('address_2',       document.getElementById('mpc_address_2').value);
            formData.append('delivery_method', document.getElementById('mpc_delivery_method').value);
            formData.append('delivery_timing', document.getElementById('mpc_delivery_timing').value);
            formData.append('time_slot',       document.getElementById('mpc_time_slot').value);
            formData.append('pickup_location', document.getElementById('mpc_pickup_branch').value);
            formData.append('allergies',       document.getElementById('mpc_allergies').value);
            formData.append('recipient_name',  document.getElementById('mpc_recipient_name').value);
            formData.append('coupon_code',     appliedCoupon.code);
            formData.append('vip_token',       activeVipToken);

            fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    localStorage.removeItem('mpcCheckoutState');
                    window.location.href = response.data.payment_url;
                } else {
                    mpcShowNotice('Error: ' + (response.data || 'Unknown error. Please contact support.'), 'error');
                    btn.innerText = 'Proceed to Checkout \u2192'; btn.disabled = false;
                }
            })
            .catch(error => {
                mpcShowNotice('Error: ' + (error.message || 'An unexpected error occurred. Please try again.'), 'error');
                btn.innerText = 'Proceed to Checkout \u2192'; btn.disabled = false;
            });
        }

        mpcAdjustTimingOptions();
        updateLogisticsSummary();
    </script>
    <?php
    return ob_get_clean();
}

// ==========================================
// 9. POST-PAYMENT SUBSCRIPTION PROVISIONING & CLEANUP HANDLERS
// ==========================================
add_action( 'woocommerce_order_status_processing', 'mpc_activate_subscription_on_payment', 10, 1 );
add_action( 'woocommerce_order_status_completed',  'mpc_activate_subscription_on_payment', 10, 1 );

function mpc_activate_subscription_on_payment( $order_id ) {
    global $wpdb;
    $order_id = absint( $order_id );
    if ( ! $order_id ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Strictly verify that the order is Processing or Completed
    if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
        return;
    }

    mpc_ensure_subscription_logistics_columns();

    $table_subs  = $wpdb->prefix . 'cmp_subscriptions';
    $customer_id = intval( $order->get_customer_id() );

    // 1. Check if a subscription record already exists for this WooCommerce Order ID (Idempotency Guard)
    $existing_sub = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table_subs} WHERE wc_order_id = %d ORDER BY id DESC LIMIT 1",
        $order_id
    ) );

    $provisioned_sub_id = 0;

    if ( $existing_sub ) {
        // Legacy fallback: If a pre-update 'pending' subscription exists, activate it now
        if ( $existing_sub->status === 'pending' ) {
            $days = max( 1, intval( $existing_sub->total_days ) );
            $wpdb->update(
                $table_subs,
                array(
                    'status'      => 'active',
                    'start_date'  => date( 'Y-m-d H:i:s' ),
                    'expiry_date' => date( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) ),
                ),
                array( 'id' => intval( $existing_sub->id ) )
            );
        }
        $provisioned_sub_id = intval( $existing_sub->id );
    } else {
        // 2. Provision Brand-New Active Subscription from Order Meta Blueprint
        $plan_name   = $order->get_meta( '_cmp_plan_name' );
        $total_days  = intval( $order->get_meta( '_cmp_total_days' ) );
        $categories  = $order->get_meta( '_cmp_allowed_categories' );
        $plan_price  = (float) $order->get_meta( '_cmp_plan_price' );
        $discount    = (float) $order->get_meta( '_cmp_discount_amount' );
        $method      = $order->get_meta( '_cmp_logistics_method' ) ?: $order->get_meta( 'delivery_method' );
        $pickup      = $order->get_meta( '_cmp_pickup_location' ) ?: $order->get_meta( 'pickup_location' );
        $timing      = $order->get_meta( '_cmp_delivery_timing' ) ?: $order->get_meta( 'delivery_timing' );
        $time_slot   = $order->get_meta( '_cmp_time_slot' ) ?: $order->get_meta( 'time_slot' );
        $allergies   = $order->get_meta( 'allergies' );
        $del_address = $order->get_meta( '_cmp_delivery_address' );
        $prep_timing = $order->get_meta( '_cmp_prep_timing' ) ?: 'auto';

        // Fallback extraction from order items if order was placed outside the custom wizard
        if ( empty( $plan_name ) ) {
            foreach ( $order->get_items() as $item ) {
                $plan_name  = $item->get_name();
                $plan_price = (float) $order->get_subtotal();
                break;
            }
        }

        // Only create a subscription if this order actually contains a meal plan
        if ( empty( $plan_name ) || ( empty( $timing ) && ! $order->get_meta( '_mpc_is_custom_checkout' ) ) ) {
            return;
        }

        if ( $total_days <= 0 ) {
            $total_days = 30;
            if ( stripos( $plan_name, '7' )  !== false ) $total_days = 7;
            if ( stripos( $plan_name, '20' ) !== false ) $total_days = 20;
            if ( stripos( $plan_name, '24' ) !== false ) $total_days = 24;
            if ( stripos( $plan_name, '5' )  !== false ) $total_days = 5;
            if ( stripos( $plan_name, '3' )  !== false && stripos( $plan_name, 'juice' ) !== false ) $total_days = 3;
        }

        if ( empty( $categories ) ) {
            $categories = ( stripos( $plan_name, 'juice' ) !== false || stripos( $plan_name, 'cleanse' ) !== false )
                ? 'Juices'
                : 'Breakfast,Lunch,Dinner,Snacks';
        }

        if ( empty( $del_address ) ) {
            $addr_parts  = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city() ) );
            if ( empty( $addr_parts ) ) {
                $addr_parts = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) );
            }
            $del_address = implode( ', ', $addr_parts );
        }

        $insert_data = array(
            'user_id'            => $customer_id,
            'wc_order_id'        => $order_id,
            'plan_name'          => $plan_name,
            'total_days'         => $total_days,
            'allowed_categories' => $categories,
            'plan_price'         => $plan_price,
            'discount_amount'    => $discount,
            'status'             => 'active',
            'start_date'         => date( 'Y-m-d H:i:s' ),
            'expiry_date'        => date( 'Y-m-d H:i:s', strtotime( "+{$total_days} days" ) ),
        );

        $existing_cols = $wpdb->get_col( "DESC {$table_subs}", 0 );
        if ( is_array( $existing_cols ) ) {
            if ( in_array( 'delivery_method', $existing_cols, true ) )  $insert_data['delivery_method']  = $method;
            if ( in_array( 'pickup_location', $existing_cols, true ) )  $insert_data['pickup_location']  = ( $method === 'Pickup' ) ? $pickup : '';
            if ( in_array( 'delivery_timing', $existing_cols, true ) )  $insert_data['delivery_timing']  = $timing;
            if ( in_array( 'time_slot', $existing_cols, true ) )        $insert_data['time_slot']        = $time_slot;
            if ( in_array( 'allergies', $existing_cols, true ) )        $insert_data['allergies']        = ( $allergies !== '' && $allergies !== null ) ? $allergies : '__NONE__';
            if ( in_array( 'delivery_address', $existing_cols, true ) ) $insert_data['delivery_address'] = $del_address;
            if ( in_array( 'prep_timing', $existing_cols, true ) )      $insert_data['prep_timing']      = $prep_timing;
        }

        $inserted = $wpdb->insert( $table_subs, $insert_data );
        if ( $inserted ) {
            $provisioned_sub_id = intval( $wpdb->insert_id );

            if ( $customer_id > 0 ) {
                $active_user_subs = intval( $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active','paused')",
                    $customer_id
                ) ) );

                mpc_record_user_logistics_profile(
                    $customer_id,
                    $provisioned_sub_id,
                    array(
                        'plan_name' => $plan_name,
                        'method'    => $method,
                        'pickup'    => ( $method === 'Pickup' ) ? $pickup : '',
                        'timing'    => $timing,
                        'time_slot' => $time_slot,
                        'allergies' => $allergies,
                        'address'   => $del_address,
                    ),
                    ( $active_user_subs <= 1 )
                );
            }

            if ( function_exists( 'cmp_record_customer_activity_log' ) ) {
                cmp_record_customer_activity_log( array(
                    'subscription_id' => $provisioned_sub_id,
                    'wc_order_id'     => $order_id,
                    'user_id'         => $customer_id,
                    'plan_name'       => $plan_name,
                    'action_type'     => 'Subscription Activated (Paid)',
                    'old_status'      => 'Order #' . $order_id . ' Payment Verified',
                    'new_status'      => 'Active (' . $total_days . ' Days) | ' . $timing . ' (' . $time_slot . ')',
                ) );
            }
        }
    }

    // 3. Finalize Deposit Wallet, VIP Magic Link Consumption & Coupon Usage Count (Once per Order)
    $meta_needs_save = false;

    if ( $customer_id > 0 ) {
        $is_own_bag   = ( $order->get_meta( '_mpc_is_own_bag' ) === '1' );
        $current_meta = get_user_meta( $customer_id, '_cmp_deposit_held', true );

        if ( $is_own_bag ) {
            update_user_meta( $customer_id, '_cmp_deposit_held', 'own_bag' );
        } elseif ( $current_meta !== 'own_bag' ) {
            foreach ( $order->get_items( 'fee' ) as $item ) {
                if ( strpos( $item->get_name(), 'Thermal Bag Deposit' ) !== false ) {
                    update_user_meta( $customer_id, '_cmp_deposit_held', 'yes' );
                    break;
                }
            }
        }
    }

    // Consume single-use VIP token now that payment succeeded
    $vip_token = $order->get_meta( '_mpc_vip_token' );
    if ( ! empty( $vip_token ) ) {
        $active_links = get_option( 'cmp_active_magic_links', array() );
        if ( isset( $active_links[ $vip_token ] ) ) {
            unset( $active_links[ $vip_token ] );
            update_option( 'cmp_active_magic_links', $active_links );
        }
        $order->delete_meta_data( '_mpc_vip_token' );
        $meta_needs_save = true;
    }

    // Increment WooCommerce coupon usage count once upon confirmed payment
    $coupon_used = $order->get_meta( '_mpc_coupon_used' );
    if ( ! empty( $coupon_used ) && ! $order->get_meta( '_mpc_coupon_usage_counted' ) ) {
        $wc_coupon_obj = new WC_Coupon( $coupon_used );
        if ( $wc_coupon_obj->get_id() ) {
            $wc_coupon_obj->increase_usage_count( $order->get_billing_email() );
        }
        $order->update_meta_data( '_mpc_coupon_usage_counted', '1' );
        $meta_needs_save = true;
    }

    if ( $meta_needs_save ) {
        $order->save();
    }
}

// Automatically clean up any legacy 'pending' subscription rows if an order fails, is cancelled, or is trashed
add_action( 'woocommerce_order_status_failed',    'mpc_cleanup_unpaid_subscription_on_fail', 10, 1 );
add_action( 'woocommerce_order_status_cancelled', 'mpc_cleanup_unpaid_subscription_on_fail', 10, 1 );
add_action( 'woocommerce_order_status_trash',     'mpc_cleanup_unpaid_subscription_on_fail', 10, 1 );

function mpc_cleanup_unpaid_subscription_on_fail( $order_id ) {
    global $wpdb;
    $order_id = absint( $order_id );
    if ( ! $order_id ) {
        return;
    }
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->delete( $table_subs, array( 'wc_order_id' => $order_id, 'status' => 'pending' ), array( '%d', '%s' ) );
}

// ==========================================
// 9.5 THANK-YOU PAGE STATUS GUARD (SUPPRESSES DUPLICATE BANNER & VERIFIES PAYMENT)
// ==========================================
add_action( 'woocommerce_thankyou', 'mpc_add_dashboard_button_to_thankyou', 5, 1 );
function mpc_add_dashboard_button_to_thankyou( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Prevent woo-bridge.php from printing a duplicate or unverified banner on the same page
    if ( function_exists( 'cmp_thankyou_page_button' ) ) {
        remove_action( 'woocommerce_thankyou', 'cmp_thankyou_page_button', 10 );
    }

    $portal_url   = site_url( '/my-meal-portal/' );
    $checkout_url = site_url( '/' );

    if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
        // Ensure subscription is provisioned even if gateway redirect arrived before webhook
        mpc_activate_subscription_on_payment( $order_id );

        echo '<div style="margin-top: 40px; padding: 30px; background: #f8fafc; border: 2px solid #379237; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(27,94,32,0.08);">';
        echo '<h3 style="color: #1B5E20; margin-top: 0; font-size: 1.55em; font-weight: 800;">Your Meal Plan is Ready!</h3>';
        echo '<p style="color: #475569; margin-bottom: 25px; font-size: 1.08em;">Your payment has been confirmed. Head over to your personal dashboard to start scheduling your daily meals and tracking your macros.</p>';
        echo '<a href="' . esc_url( $portal_url ) . '" style="background: linear-gradient(135deg, #379237 0%, #1B5E20 100%); color: #fff; padding: 15px 35px; border-radius: 8px; text-decoration: none; font-weight: 800; font-size: 1.1em; display: inline-block; box-shadow: 0 4px 12px rgba(55,146,55,0.25);">Go to My Dashboard &rarr;</a>';
        echo '</div>';
    } else {
        $status_label = ucfirst( $order->get_status() );
        echo '<div style="margin-top: 40px; padding: 30px; background: #fff1f2; border: 2px solid #f43f5e; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(244,63,94,0.08);">';
        echo '<h3 style="color: #be123c; margin-top: 0; font-size: 1.45em; font-weight: 800;">Payment Not Completed (Order Status: ' . esc_html( $status_label ) . ')</h3>';
        echo '<p style="color: #881337; margin-bottom: 22px; font-size: 1.05em;">Your meal plan subscription will only be activated once payment is completed. No subscription has been billed or created for this order yet.</p>';
        echo '<a href="' . esc_url( $checkout_url ) . '" style="background: #e11d48; color: #fff; padding: 13px 28px; border-radius: 8px; text-decoration: none; font-weight: 800; font-size: 1.05em; display: inline-block;">Return to Meal Plan Checkout &rarr;</a>';
        echo '</div>';
    }
}

add_filter( 'woocommerce_pay_order_button_text', 'mpc_change_pay_button_text' );
function mpc_change_pay_button_text( $text ) {
    return 'Place the Order';
}

// ==========================================
// 10. CUSTOMER DASHBOARD PROFILE WIDGET
// ==========================================
add_shortcode( 'meal_plan_customer_profile', 'mpc_render_customer_profile' );
function mpc_render_customer_profile() {
    if ( ! is_user_logged_in() ) {
        return '';
    }
    $current_user      = wp_get_current_user();
    $user_id           = $current_user->ID;
    $phone             = get_user_meta( $user_id, 'billing_phone', true );
    $method            = get_user_meta( $user_id, 'delivery_method', true );
    $timing            = get_user_meta( $user_id, 'delivery_timing', true );
    $time_slot         = get_user_meta( $user_id, 'time_slot', true );
    $pickup            = get_user_meta( $user_id, 'pickup_location', true );
    $allergies         = get_user_meta( $user_id, 'allergies', true );
    $address_1         = get_user_meta( $user_id, 'billing_address_1', true );
    $address_2         = get_user_meta( $user_id, 'billing_address_2', true );
    $city              = get_user_meta( $user_id, 'billing_city', true );
    $full_address      = array_filter( array( $address_1, $address_2, $city ) );
    $address_display   = ! empty( $full_address ) ? implode( ', ', $full_address ) : 'Address not provided';
    $allergies_display = ! empty( $allergies ) ? esc_html( $allergies ) : 'No Allergies Recorded';
    $method_display    = $method ?: 'N/A';
    if ( $method === 'Pickup' && ! empty( $pickup ) ) {
        $method_display .= ' (' . esc_html( $pickup ) . ')';
    }
    ob_start();
    ?>
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 25px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between;">
        <div style="flex: 1; min-width: 250px;">
            <h3 style="margin-top: 0; color: #379237; font-size: 1.3em; margin-bottom: 15px;">Account Details</h3>
            <p style="margin: 0 0 8px 0; color: #334155;"><strong>Name:</strong> <?php echo esc_html( $current_user->first_name . ' ' . $current_user->last_name ); ?></p>
            <p style="margin: 0 0 8px 0; color: #334155;"><strong>Email:</strong> <?php echo esc_html( $current_user->user_email ); ?></p>
            <p style="margin: 0 0 8px 0; color: #334155;"><strong>Phone:</strong> <?php echo esc_html( $phone ?: 'N/A' ); ?></p>
            <p style="margin: 0; color: #334155;"><strong>Address:</strong> <?php echo esc_html( $address_display ); ?></p>
        </div>
        <div style="flex: 1; min-width: 250px; background: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #f1f5f9;">
            <h3 style="margin-top: 0; color: #0f172a; font-size: 1.1em; margin-bottom: 12px;">Active Logistics</h3>
            <p style="margin: 0 0 8px 0; color: #475569;"><strong>Method:</strong> <?php echo esc_html( $method_display ); ?></p>
            <p style="margin: 0 0 8px 0; color: #475569;"><strong>Receive By:</strong> <?php echo esc_html( $timing ?: 'N/A' ); ?></p>
            <p style="margin: 0 0 8px 0; color: #475569;"><strong>Time Slot:</strong> <?php echo esc_html( $time_slot ?: 'N/A' ); ?></p>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #cbd5e1;">
                <p style="margin: 0; color: #b45309;"><strong>Allergies:</strong> <?php echo $allergies_display; ?></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ==========================================
// 11. PAYMENT VERIFICATION BRIDGE
// ==========================================
add_action( 'template_redirect', 'mpc_verify_ngenius_payment_return' );
function mpc_verify_ngenius_payment_return() {
    if ( is_wc_endpoint_url( 'order-received' ) && isset( $_GET['ref'] ) ) {
        global $wp;
        $order_id = absint( $wp->query_vars['order-received'] );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->is_paid() && $order->has_status( array( 'processing', 'completed' ) ) ) {
            mpc_activate_subscription_on_payment( $order_id );
            return;
        }

        $reference     = sanitize_text_field( $_GET['ref'] );
        $main_site_url = 'https://thecyclehub.com';
        $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/verify';

        $response = wp_remote_post( $endpoint, array(
            'headers' => array( 'Content-Type' => 'application/json', 'x-bistro-token' => defined( 'BISTRO_BRIDGE_SECRET' ) ? BISTRO_BRIDGE_SECRET : '' ),
            'body'    => wp_json_encode( array( 'reference' => $reference ) ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'Bridge Error: ' . $response->get_error_message() );
            return;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $response_code === 200 && isset( $body['success'] ) && $body['success'] === true ) {
            $state = $body['state'] ?? '';
            if ( in_array( $state, array( 'CAPTURED', 'AUTHORISED', 'PURCHASED' ), true ) ) {
                // payment_complete() transitions order to Processing/Completed and triggers mpc_activate_subscription_on_payment()
                $order->payment_complete( $reference );
                $order->add_order_note( 'Payment captured via N-Genius Bridge. State: ' . $state . ' | Ref: ' . $reference );
                mpc_activate_subscription_on_payment( $order_id );
            } else {
                $order->update_status( 'failed', 'Payment declined. State: ' . $state );
                mpc_cleanup_unpaid_subscription_on_fail( $order_id );
            }
        } else {
            $error_msg = isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $response_code;
            $order->add_order_note( 'Bridge Verification Failed: ' . $error_msg );
            $order->update_status( 'failed', 'Bridge Verification Failed: ' . $error_msg );
            mpc_cleanup_unpaid_subscription_on_fail( $order_id );
        }
    }
}
