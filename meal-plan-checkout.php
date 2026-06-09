<?php
/**
 * Plugin Name: Meal Plan Custom Checkout
 * Description: A companion plugin that provides a streamlined 3-step custom checkout wizard with login, auto-fill, and direct payment routing.
 * Version: 2.8
 * Author: RM Dev Team | Customised by Fareed M Rifaideen
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. ENQUEUE SCRIPTS & STYLES (With Cache Busting)
// ==========================================
add_action('wp_enqueue_scripts', 'mpc_enqueue_assets');
function mpc_enqueue_assets() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'meal_plan_checkout') ) {
        $css_file = plugin_dir_path( __FILE__ ) . 'assets/mpc-style.css';
        $version = file_exists($css_file) ? filemtime($css_file) : '2.8';
        wp_enqueue_style( 'mpc-wizard-styles', plugin_dir_url( __FILE__ ) . 'assets/mpc-style.css', array(), $version );
    }
}

// ==========================================
// 2. AJAX HANDLER: SECURE USER LOGIN
// ==========================================
add_action('wp_ajax_nopriv_mpc_login_user', 'mpc_ajax_login');
function mpc_ajax_login() {
    check_ajax_referer('mpc_checkout_nonce', 'nonce');
    
    $creds = array(
        'user_login'    => sanitize_text_field($_POST['log']),
        'user_password' => $_POST['pwd'],
        'remember'      => true
    );
    
    $user = wp_signon( $creds, is_ssl() );
    
    if ( is_wp_error( $user ) ) {
        wp_send_json_error( $user->get_error_message() );
    } else {
        $user_id = $user->ID;
        $data = array(
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->user_email,
            'phone'      => get_user_meta($user_id, 'billing_phone', true),
            'address_1'  => get_user_meta($user_id, 'billing_address_1', true),
            'address_2'  => get_user_meta($user_id, 'billing_address_2', true),
            'delivery_method' => get_user_meta($user_id, 'delivery_method', true),
            'delivery_timing' => get_user_meta($user_id, 'delivery_timing', true),
            'time_slot'       => get_user_meta($user_id, 'time_slot', true),
            'pickup_location' => get_user_meta($user_id, 'pickup_location', true)
        );
        wp_send_json_success($data);
    }
}

// ==========================================
// 3. AJAX HANDLER: ORDER PROCESSING
// ==========================================
add_action('wp_ajax_nopriv_mpc_process_order', 'mpc_process_order');
add_action('wp_ajax_mpc_process_order', 'mpc_process_order');

function mpc_process_order() {
    check_ajax_referer('mpc_checkout_nonce', 'nonce');

    $product_id      = intval($_POST['product_id']);
    $first_name      = sanitize_text_field($_POST['first_name']);
    $last_name       = sanitize_text_field($_POST['last_name']);
    $email           = sanitize_email($_POST['email']);
    $phone           = sanitize_text_field($_POST['phone']);
    $password        = isset($_POST['password']) ? $_POST['password'] : ''; 
    $address_1       = sanitize_text_field($_POST['address_1']);
    $address_2       = sanitize_text_field($_POST['address_2']);
    $delivery_method = sanitize_text_field($_POST['delivery_method']);
    $delivery_timing = sanitize_text_field($_POST['delivery_timing']);
    $time_slot       = sanitize_text_field($_POST['time_slot']);
    $pickup_location = isset($_POST['pickup_location']) ? sanitize_text_field($_POST['pickup_location']) : '';
    $allergies       = sanitize_textarea_field($_POST['allergies']);
    $categories      = isset($_POST['categories']) ? array_map('sanitize_text_field', $_POST['categories']) : array();

    if (!$product_id || !$email || !$first_name || !$address_1) {
        wp_send_json_error('Missing mandatory fields.');
    }

    $product = wc_get_product($product_id);
    $plan_title = $product->get_name();

    preg_match('/(\d+)\s*Meal/i', $plan_title, $matches);
    $allowed_meals = isset($matches[1]) ? intval($matches[1]) : 0;
    
    if ($allowed_meals > 0 && count($categories) !== $allowed_meals) {
        wp_send_json_error('Security Check Failed: Invalid number of meal categories selected for this plan.');
    }

    if ($allowed_meals > 0 && !in_array('Snacks', $categories)) {
        $categories[] = 'Snacks';
    }

    $user_id = get_current_user_id();
    
    if (!$user_id) {
        if (email_exists($email)) {
            wp_send_json_error('An account with this email already exists. Please scroll up and log in.');
        }
        
        $user_id = wp_create_user($email, $password, $email);
        if (is_wp_error($user_id)) {
            wp_send_json_error( $user_id->get_error_message() );
        }
        
        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
    }
    
    wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
    update_user_meta($user_id, 'billing_phone', $phone);
    update_user_meta($user_id, 'billing_address_1', $address_1);
    update_user_meta($user_id, 'billing_address_2', $address_2);
    update_user_meta($user_id, 'billing_city', 'Dubai');
    update_user_meta($user_id, 'billing_country', 'AE');
    
    update_user_meta($user_id, 'delivery_method', $delivery_method);
    update_user_meta($user_id, 'delivery_timing', $delivery_timing);
    update_user_meta($user_id, 'time_slot', $time_slot);
    update_user_meta($user_id, 'pickup_location', $pickup_location);
    update_user_meta($user_id, 'allergies', $allergies);
    
    $order = wc_create_order(array('customer_id' => $user_id));
    $order->add_product($product, 1);

    $address = array(
        'first_name' => $first_name, 'last_name'  => $last_name, 'email'      => $email, 'phone'      => $phone,
        'address_1'  => $address_1, 'address_2'  => $address_2, 'city'       => 'Dubai', 'country'    => 'AE',
    );
    $order->set_address($address, 'billing');
    $order->set_address($address, 'shipping');
    $order->set_customer_note($allergies); 

    $order->update_meta_data('delivery_method', $delivery_method);
    $order->update_meta_data('delivery_timing', $delivery_timing);
    $order->update_meta_data('time_slot', $time_slot);
    $order->update_meta_data('pickup_location', $pickup_location);
    $order->update_meta_data('allergies', $allergies);
    $order->update_meta_data('_cmp_allowed_categories', implode(',', $categories)); 

    $order->calculate_totals();
    $order->save();

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    
    $days = 30;
    if (stripos($plan_title, '7') !== false) $days = 7;
    if (stripos($plan_title, '20') !== false) $days = 20;
    if (stripos($plan_title, '24') !== false) $days = 24;
    if (stripos($plan_title, '5') !== false) $days = 5;
    if (stripos($plan_title, '3') !== false && stripos($plan_title, 'juice') !== false) $days = 3;

    $sub_data = array(
        'user_id' => $user_id, 'wc_order_id' => $order->get_id(), 'plan_name' => $plan_title,
        'total_days' => $days, 'allowed_categories' => implode(',', $categories), 'status' => 'pending', 
        'start_date' => date('Y-m-d H:i:s'), 'expiry_date' => date('Y-m-d H:i:s', strtotime("+$days days"))
    );

    $wpdb->insert($table_subs, $sub_data);

    // --- START OF CUSTOM PAYMENT BRIDGE ---
    $main_site_url = 'https://staging3.thecyclehub.com'; 
    $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/pay';

    $payload = array(
        'order_id'   => $order->get_id(),
        'amount'     => $order->get_total(),
        'currency'   => $order->get_currency(),
        'email'      => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'return_url' => $order->get_checkout_order_received_url()
    );

    $response = wp_remote_post( $endpoint, array(
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-bistro-token' => BISTRO_BRIDGE_SECRET
        ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 20,
    ));

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Payment bridge error: ' . $response->get_error_message() );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $body['success'] ) && $body['success'] === true ) {
        // Save the N-Genius reference to the local order
        $order->update_meta_data( '_ngenius_reference', sanitize_text_field( $body['reference'] ) );
        $order->save();
        
        wp_send_json_success( array( 'payment_url' => esc_url_raw( $body['payment_url'] ) ) );
    } else {
        $error_message = isset( $body['message'] ) ? $body['message'] : 'Failed to retrieve payment link from the main website.';
        wp_send_json_error( 'Gateway Error: ' . $error_message );
    }
    // --- END OF CUSTOM PAYMENT BRIDGE ---
}

// ==========================================
// 4. FRONTEND WIZARD RENDERER
// ==========================================
add_shortcode( 'meal_plan_checkout', 'mpc_render_checkout_wizard' );

function mpc_render_checkout_wizard() {
    if ( ! class_exists( 'WooCommerce' ) ) { return '<p>WooCommerce is required.</p>'; }

    global $wpdb;
    $table_foods = $wpdb->prefix . 'cmp_foods';
    $food_categories = $wpdb->get_col("SELECT DISTINCT category_name FROM $table_foods WHERE is_active = 1 ORDER BY category_name ASC");
    $map_url = get_option('cmp_map_url', '#');

    $args = array('status' => 'publish', 'limit' => -1, 'orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC');
    $products = wc_get_products( $args );

    $grouped_plans = array(
        '7-days'  => array('label' => '7-Day Plans', 'items' => array()),
        '20-days' => array('label' => '20-Day Plans', 'items' => array()),
        '24-days' => array('label' => '24-Day Plans', 'items' => array()),
        'juice'   => array('label' => 'Cleanse Boosters', 'items' => array()),
        'other'   => array('label' => 'Other Plans', 'items' => array()),
    );

    foreach ($products as $product) {
        $tag_slugs = wp_get_post_terms( $product->get_id(), 'product_tag', array('fields' => 'slugs') );
        if ( is_wp_error( $tag_slugs ) ) { $tag_slugs = array(); }

        $assigned = false;
        foreach (['7-days', '20-days', '24-days', 'juice'] as $target_tag) {
            if (in_array($target_tag, $tag_slugs)) {
                $grouped_plans[$target_tag]['items'][] = $product;
                $assigned = true; break; 
            }
        }
        if (!$assigned) { $grouped_plans['other']['items'][] = $product; }
    }

    ob_start();
    ?>
    <div class="mpc-checkout-container">
        
        <div class="mpc-wizard-area">
            <div class="mpc-progress">
                <div class="mpc-step-indicator active" data-step="1"><div class="mpc-step-circle">1</div><span>Select Plan</span></div>
                <div class="mpc-step-indicator" data-step="2"><div class="mpc-step-circle">2</div><span>Delivery Details</span></div>
                <div class="mpc-step-indicator" data-step="3" id="mpc-indicator-meals"><div class="mpc-step-circle">3</div><span>Meal Type</span></div>
            </div>

            <div id="mpc-step-1" class="mpc-step-content active">
                <h2 style="margin-top: 0; color: #222;">Choose Your Plan</h2>
                <p style="color: #666; margin-bottom: 20px;">Select a meal plan to get started. Then go through the process of selecting delivery options followed by selecting the meal types to finalise your order. We will then be in touch for payment and to assist you with meal selection. You will choose your specific meals after your subscription is confirmed. </p>
                
                <?php 
                if ( empty($products) ) {
                    echo '<p>No products found.</p>';
                } else {
                    foreach ($grouped_plans as $group_key => $group_data) {
                        if ( empty($group_data['items']) ) continue; 

                        echo '<h3 class="mpc-cat-header">' . esc_html($group_data['label']) . '</h3>';
                        echo '<div class="mpc-grid">';
                        foreach ( $group_data['items'] as $product ) {
                            $id = $product->get_id();
                            $title = $product->get_name();
                            $price_html = $product->get_price_html(); 
                            $raw_price = $product->get_price();
                            $desc = $product->get_short_description();
                            
                            $is_juice = (stripos($title, 'juice') !== false || stripos($title, 'cleanse') !== false) ? 'true' : 'false';
                            
                            preg_match('/(\d+)\s*Meal/i', $title, $matches);
                            $allowed_meals = isset($matches[1]) ? intval($matches[1]) : 0;
                            
                            $display_title = str_replace(' - ', ' - <br>', esc_html($title));
                            
                            echo '<div class="mpc-tile" onclick="mpcSelectPlan(this, \''.esc_attr($title).'\', \''.esc_attr($raw_price).'\', '.$is_juice.', '.$id.', '.$allowed_meals.')">';
                            echo '<div>';
                            echo '<div class="mpc-tile-title">' . $display_title . '</div>';
                            echo '<div class="mpc-tile-price">' . wp_kses_post($price_html) . '</div>';
                            echo '</div>';
                            if (!empty($desc)) echo '<div style="font-size: 0.9em; color: #666; margin-top: 10px;">' . wp_kses_post($desc) . '</div>';
                            echo '</div>';
                        }
                        echo '</div>'; 
                    }
                }
                ?>
                <div class="mpc-nav-buttons"><button class="mpc-btn mpc-btn-next" onclick="mpcChangeStep(1)" id="btn-next-1" disabled>Next: Delivery Details &rarr;</button></div>
            </div>

            <div id="mpc-step-2" class="mpc-step-content">
                <h2 style="margin-top: 0; color: #222;">Delivery Information</h2>
                
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
                <?php else: 
                    $current_user = wp_get_current_user();
                    $saved_data = array(
                        'first_name' => $current_user->first_name, 'last_name'  => $current_user->last_name, 'email'      => $current_user->user_email,
                        'phone'      => get_user_meta($current_user->ID, 'billing_phone', true), 'address_1'  => get_user_meta($current_user->ID, 'billing_address_1', true), 'address_2'  => get_user_meta($current_user->ID, 'billing_address_2', true),
                        'delivery_method' => get_user_meta($current_user->ID, 'delivery_method', true), 'delivery_timing' => get_user_meta($current_user->ID, 'delivery_timing', true),
                        'time_slot'       => get_user_meta($current_user->ID, 'time_slot', true), 'pickup_location' => get_user_meta($current_user->ID, 'pickup_location', true),
                    );
                ?>
                    <div id="mpc-logged-in-section" style="background: #f4fdf4; padding: 20px; border-radius: 8px; border: 1px solid #379237; margin-bottom: 25px;">
                        <p style="margin: 0 0 10px 0; color: #222;"><strong>Welcome back, <?php echo esc_html($current_user->first_name); ?>!</strong></p>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: normal;">
                            <input type="checkbox" id="mpc_use_saved_details" style="transform: scale(1.2);"> Use my saved delivery details
                        </label>
                        <script>var mpcSavedDetails = <?php echo json_encode($saved_data); ?>;</script>
                    </div>
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
                        <input type="email" class="mpc-form-control" id="mpc_email" placeholder="john@example.com" required <?php if(is_user_logged_in()) echo 'readonly style="background: #f1f5f9; cursor: not-allowed;"'; ?>>
                    </div>
                    <div class="mpc-form-col mpc-form-group">
                        <label>Phone Number *</label>
                        <input type="tel" class="mpc-form-control" id="mpc_phone" placeholder="+971 50 000 0000" required>
                    </div>
                </div>

                <div class="mpc-form-row" id="mpc_password_group" <?php if(is_user_logged_in()) echo 'style="display:none;"'; ?>>
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
                        <label>City & Country</label>
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
                                <span>I confirm I am within the delivery zone. (<a href="<?php echo esc_url($map_url); ?>" target="_blank" style="color: #379237; text-decoration: underline;">View Map</a>) *</span>
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
                            for ($i = 5; $i < 20; $i++) {
                                $start_time = date("g:i A", strtotime("$i:00"));
                                $end_time = date("g:i A", strtotime(($i+1).":00"));
                                echo '<option value="' . esc_attr($start_time . ' to ' . $end_time) . '">' . esc_html($start_time . ' to ' . $end_time) . '</option>';
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
                <h2 style="margin-top: 0; color: #222;">Select Your Meal Type</h2>
                <p id="mpc-meals-subtitle" style="color: #666; font-weight: bold;">Which meal categories would you like included in your daily plan?</p>
                
                <div class="mpc-form-group" style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    <?php 
                    if (!empty($food_categories)) {
                        foreach ($food_categories as $cat) {
                            if (strtolower($cat) === 'juices' || strtolower($cat) === 'snacks') continue; 
                            echo '<label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; cursor: pointer; font-weight: normal;">';
                            echo '<input type="checkbox" class="mpc-cat-checkbox" value="'.esc_attr($cat).'" style="transform: scale(1.3);"> ' . esc_html($cat);
                            echo '</label>';
                        }
                    } else { echo '<p>No categories found in database.</p>'; }
                    ?>
                </div>

                <div class="mpc-form-group">
                    <label>Any Food Allergies?</label>
                    <textarea class="mpc-form-control" id="mpc_allergies" rows="3" placeholder="e.g., Nuts, Shellfish..."></textarea>
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
            <div id="mpc-summary-meals" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ddd; font-size: 0.9em;">
                <strong>Included Categories:</strong><br>
                <span id="sum-cats" style="color: #379237;"></span>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        let totalSteps = 3;
        let checkoutData = { productId: null, planName: '', planPrice: 0, isJuice: false, allowedMeals: 0 };
        let isUserLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;

        document.getElementById('mpc_toggle_password').addEventListener('click', function(e) {
            e.preventDefault();
            let pwdInput = document.getElementById('mpc_password');
            let iconOpen = document.getElementById('icon-eye-open');
            let iconClosed = document.getElementById('icon-eye-closed');
            
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text'; 
                iconOpen.style.display = 'none';
                iconClosed.style.display = 'block';
            } else {
                pwdInput.type = 'password'; 
                iconOpen.style.display = 'block';
                iconClosed.style.display = 'none';
            }
        });

        function populateFields(data) {
            if(data.first_name) document.getElementById('mpc_first_name').value = data.first_name;
            if(data.last_name) document.getElementById('mpc_last_name').value = data.last_name;
            if(data.email) {
                let emailField = document.getElementById('mpc_email');
                emailField.value = data.email;
                emailField.readOnly = true;
                emailField.style.background = '#f1f5f9';
                emailField.style.cursor = 'not-allowed';
            }
            if(data.phone) document.getElementById('mpc_phone').value = data.phone;
            if(data.address_1) document.getElementById('mpc_address_1').value = data.address_1;
            if(data.address_2) document.getElementById('mpc_address_2').value = data.address_2;
            
            if(data.delivery_method) {
                document.getElementById('mpc_delivery_method').value = data.delivery_method;
                document.getElementById('mpc_delivery_method').dispatchEvent(new Event('change'));
            }
            if(data.delivery_timing) document.getElementById('mpc_delivery_timing').value = data.delivery_timing;
            if(data.time_slot) document.getElementById('mpc_time_slot').value = data.time_slot;
            
            if(data.pickup_location && data.delivery_method === 'Pickup') {
                document.getElementById('mpc_pickup_branch').value = data.pickup_location;
            }

            mpcSaveState();
            updateLogisticsSummary();
        }

        let useSavedCheckbox = document.getElementById('mpc_use_saved_details');
        if (useSavedCheckbox) {
            useSavedCheckbox.addEventListener('change', function() {
                if (this.checked && typeof mpcSavedDetails !== 'undefined') {
                    populateFields(mpcSavedDetails);
                }
            });
        }

        let showLogin = document.getElementById('mpc-show-login');
        if(showLogin) {
            showLogin.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('mpc-login-form').style.display = 'block';
                this.style.display = 'none';
            });
        }

        let doLoginBtn = document.getElementById('mpc-do-login-btn');
        if(doLoginBtn) {
            doLoginBtn.addEventListener('click', function() {
                let log = document.getElementById('mpc_login_email').value;
                let pwd = document.getElementById('mpc_login_pwd').value;
                let msg = document.getElementById('mpc-login-msg');
                
                if(!log || !pwd) { msg.innerText = "Please enter email and password."; return; }
                
                let btnText = this.innerText;
                this.innerText = 'Logging in...';
                this.disabled = true;
                
                let formData = new URLSearchParams();
                formData.append('action', 'mpc_login_user');
                formData.append('nonce', '<?php echo wp_create_nonce("mpc_checkout_nonce"); ?>');
                formData.append('log', log);
                formData.append('pwd', pwd);
                
                fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        isUserLoggedIn = true;
                        document.getElementById('mpc_password_group').style.display = 'none';
                        
                        let welcomeHTML = '<div style="background: #f4fdf4; padding: 20px; border-radius: 8px; border: 1px solid #379237; margin-bottom: 25px;">';
                        welcomeHTML += '<p style="margin: 0 0 10px 0; color: #222;"><strong>Welcome back, ' + response.data.first_name + '!</strong></p>';
                        welcomeHTML += '<label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: normal;"><input type="checkbox" id="mpc_use_saved_details_ajax" style="transform: scale(1.2);"> Use my saved delivery details</label></div>';
                        
                        document.getElementById('mpc-login-section').innerHTML = welcomeHTML;
                        
                        document.getElementById('mpc_use_saved_details_ajax').addEventListener('change', function() {
                            if(this.checked) populateFields(response.data);
                        });
                        
                    } else {
                        msg.innerText = response.data;
                        this.innerText = btnText;
                        this.disabled = false;
                    }
                })
                .catch(err => {
                    msg.innerText = "Connection error.";
                    this.innerText = btnText;
                    this.disabled = false;
                });
            });
        }

        function mpcSaveState() {
            const state = {
                firstName: document.getElementById('mpc_first_name').value,
                lastName: document.getElementById('mpc_last_name').value,
                email: document.getElementById('mpc_email').value,
                phone: document.getElementById('mpc_phone').value,
                address1: document.getElementById('mpc_address_1').value,
                address2: document.getElementById('mpc_address_2').value,
                deliveryMethod: document.getElementById('mpc_delivery_method').value,
                deliveryTiming: document.getElementById('mpc_delivery_timing').value,
                timeSlot: document.getElementById('mpc_time_slot').value,
                pickupBranch: document.getElementById('mpc_pickup_branch').value,
                deliveryZoneCheck: document.getElementById('mpc_delivery_zone_check').checked,
                allergies: document.getElementById('mpc_allergies').value,
                categories: Array.from(document.querySelectorAll('.mpc-cat-checkbox:checked')).map(cb => cb.value)
            };
            localStorage.setItem('mpcCheckoutState', JSON.stringify(state));
        }

        document.querySelectorAll('.mpc-form-control, .mpc-cat-checkbox, #mpc_delivery_zone_check').forEach(el => {
            el.addEventListener('input', mpcSaveState);
            el.addEventListener('change', mpcSaveState);
        });

        document.addEventListener('DOMContentLoaded', function() {
            const stateJSON = localStorage.getItem('mpcCheckoutState');
            if(stateJSON) {
                try {
                    const state = JSON.parse(stateJSON);
                    if(state.firstName) document.getElementById('mpc_first_name').value = state.firstName;
                    if(state.lastName) document.getElementById('mpc_last_name').value = state.lastName;
                    
                    if(state.email && !isUserLoggedIn) document.getElementById('mpc_email').value = state.email;
                    
                    if(state.phone) document.getElementById('mpc_phone').value = state.phone;
                    if(state.address1) document.getElementById('mpc_address_1').value = state.address1;
                    if(state.address2) document.getElementById('mpc_address_2').value = state.address2;
                    if(state.deliveryMethod) {
                        document.getElementById('mpc_delivery_method').value = state.deliveryMethod;
                        document.getElementById('mpc_delivery_method').dispatchEvent(new Event('change'));
                    }
                    if(state.deliveryTiming) document.getElementById('mpc_delivery_timing').value = state.deliveryTiming;
                    if(state.timeSlot) document.getElementById('mpc_time_slot').value = state.timeSlot;
                    if(state.pickupBranch) document.getElementById('mpc_pickup_branch').value = state.pickupBranch;
                    if(state.deliveryZoneCheck) document.getElementById('mpc_delivery_zone_check').checked = state.deliveryZoneCheck;
                    if(state.allergies) document.getElementById('mpc_allergies').value = state.allergies;

                    if(state.categories) {
                        document.querySelectorAll('.mpc-cat-checkbox').forEach(cb => {
                            if(state.categories.includes(cb.value)) cb.checked = true;
                        });
                    }
                    document.getElementById('mpc_delivery_method').dispatchEvent(new Event('change'));
                } catch(e) {}
            }
        });

        function mpcSelectPlan(tileElement, planName, planPrice, isJuice, productId, allowedMeals) {
            checkoutData.productId = productId; checkoutData.planName = planName;
            checkoutData.planPrice = planPrice; checkoutData.isJuice = isJuice; checkoutData.allowedMeals = allowedMeals;

            let tiles = document.getElementsByClassName('mpc-tile');
            for(let i=0; i<tiles.length; i++) tiles[i].classList.remove('selected');
            tileElement.classList.add('selected');

            document.getElementById('btn-next-1').disabled = false;
            document.getElementById('mpc-summary-content').innerHTML = `<div style="margin-bottom: 15px;"><strong>Plan:</strong><br><span style="color: #379237; font-size: 1.1em;">${planName}</span></div><div style="margin-bottom: 15px; padding-top: 15px; border-top: 1px dashed #ddd;"><strong>Total:</strong><br><span style="color: #222; font-size: 1.4em; font-weight: bold;">AED ${planPrice}</span></div>`;
            
            if (isJuice) {
                document.getElementById('mpc-indicator-meals').style.display = 'none';
                document.querySelectorAll('.mpc-step-indicator').forEach(el => el.style.width = '50%');
                totalSteps = 2;
                
                let btn2 = document.getElementById('btn-next-2');
                btn2.innerText = 'Proceed to Checkout \u2192';
                btn2.style.background = '#46b450';
                btn2.style.color = '#fff';
            } else {
                document.getElementById('mpc-indicator-meals').style.display = 'block';
                document.querySelectorAll('.mpc-step-indicator').forEach(el => el.style.width = '33.33%');
                totalSteps = 3;
                
                let btn2 = document.getElementById('btn-next-2');
                btn2.innerText = 'Next Step \u2192';
                btn2.style.background = '#379237';
                btn2.style.color = '#fff';
                
                if (allowedMeals > 0) {
                    document.getElementById('mpc-meals-subtitle').innerText = `Your plan includes ${allowedMeals} main meals per day. Please select exactly ${allowedMeals} categories below. (Snacks are included automatically). Meal selection is available after placing your order`;
                }
            }
            updateLogisticsSummary();

            // BULLETPROOF SCROLL: Calculates exact pixel position and scrolls safely
            setTimeout(function() {
                let nextBtn = document.getElementById('btn-next-1');
                if (nextBtn) {
                    // 1. Get the exact Y position of the button on the page
                    let elementPosition = nextBtn.getBoundingClientRect().top + window.scrollY;
                    
                    // 2. Subtract 150 pixels to account for sticky headers and give breathing room
                    let offsetPosition = elementPosition - 150;

                    // 3. Command the window to scroll to that exact pixel
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            }, 250); 
        }
        
        function mpcAdjustTimingOptions() {
            const method = document.getElementById('mpc_delivery_method').value;
            const timeSlotSelect = document.getElementById('mpc_time_slot');
            const options = timeSlotSelect.options;
            let currentSelectionNeedsReset = false;

            for (let i = 0; i < options.length; i++) {
                const optValue = options[i].value;
                const isEarlySlot = /^(5|6|7):00 AM/.test(optValue);

                if (method === 'Pickup' && isEarlySlot) {
                    options[i].disabled = true;
                    options[i].hidden = true;
                    if (options[i].selected) currentSelectionNeedsReset = true;
                } else {
                    options[i].disabled = false;
                    options[i].hidden = false;
                }
            }

            if (currentSelectionNeedsReset) {
                timeSlotSelect.value = "8:00 AM to 9:00 AM";
            }
        }

        document.getElementById('mpc_delivery_method').addEventListener('change', function() {
            if (this.value === 'Delivery') {
                document.getElementById('mpc_delivery_zone_container').style.display = 'block';
                document.getElementById('mpc_pickup_branch_container').style.display = 'none';
            } else {
                document.getElementById('mpc_delivery_zone_container').style.display = 'none';
                document.getElementById('mpc_pickup_branch_container').style.display = 'block';
            }
            mpcAdjustTimingOptions(); 
            updateLogisticsSummary();
        });

        document.getElementById('mpc_delivery_timing').addEventListener('change', updateLogisticsSummary);
        document.getElementById('mpc_pickup_branch').addEventListener('change', updateLogisticsSummary);
        document.getElementById('mpc_time_slot').addEventListener('change', updateLogisticsSummary);

        function updateLogisticsSummary() {
            let method = document.getElementById('mpc_delivery_method').value;
            let displayMethod = method === 'Pickup' ? 'Pickup (' + document.getElementById('mpc_pickup_branch').value + ')' : method;
            document.getElementById('mpc-summary-logistics').style.display = 'block';
            document.getElementById('sum-method').innerText = displayMethod;
            document.getElementById('sum-timing').innerHTML = document.getElementById('mpc_delivery_timing').value + '<br><strong>Time:</strong> ' + document.getElementById('mpc_time_slot').value;
        }
        
        let mealCheckboxes = document.querySelectorAll('.mpc-cat-checkbox');
        mealCheckboxes.forEach(function(box) {
            box.addEventListener('change', function() {
                let checkedBoxes = document.querySelectorAll('.mpc-cat-checkbox:checked');
                if (checkoutData.allowedMeals > 0) {
                    if (checkedBoxes.length >= checkoutData.allowedMeals) {
                        mealCheckboxes.forEach(b => { if (!b.checked) b.disabled = true; });
                    } else {
                        mealCheckboxes.forEach(b => b.disabled = false);
                    }
                }
                let selectedCats = []; checkedBoxes.forEach(b => selectedCats.push(b.value));
                if(selectedCats.length > 0) {
                    document.getElementById('mpc-summary-meals').style.display = 'block';
                    document.getElementById('sum-cats').innerText = selectedCats.join(', ');
                } else {
                    document.getElementById('mpc-summary-meals').style.display = 'none';
                }
            });
        });

        function mpcChangeStep(direction) {
            if (direction === 1 && currentStep === 2) {
                let pwdVal = document.getElementById('mpc_password').value;
                if(!document.getElementById('mpc_first_name').value || !document.getElementById('mpc_email').value || (!isUserLoggedIn && !pwdVal) || !document.getElementById('mpc_phone').value || !document.getElementById('mpc_address_1').value) {
                    alert('Please fill out all mandatory fields (Name, Email, Phone, Address, Password).'); return;
                }
                let method = document.getElementById('mpc_delivery_method').value;
                if (method === 'Delivery' && !document.getElementById('mpc_delivery_zone_check').checked) { alert('Please confirm you are within the delivery zone to proceed.'); return; }
                if (method === 'Pickup' && !document.getElementById('mpc_pickup_branch').value) { alert('Please select a pickup branch.'); return; }
                
                if (checkoutData.isJuice) {
                    mpcSubmitOrder(document.getElementById('btn-next-2'));
                    return;
                }
            }

            if (direction === 1 && currentStep === 3 && !checkoutData.isJuice) {
                let checkedCount = document.querySelectorAll('.mpc-cat-checkbox:checked').length;
                if (checkoutData.allowedMeals > 0 && checkedCount !== checkoutData.allowedMeals) {
                    alert(`Your plan requires exactly ${checkoutData.allowedMeals} main meal categories.`); return;
                }
                
                mpcSubmitOrder(document.getElementById('btn-next-3'));
                return;
            }

            document.getElementById('mpc-step-' + currentStep).classList.remove('active');
            currentStep += direction;
            if(currentStep < 1) currentStep = 1;
            if(currentStep > totalSteps) currentStep = totalSteps;

            document.getElementById('mpc-step-' + currentStep).classList.add('active');

            let indicators = document.getElementsByClassName('mpc-step-indicator');
            for(let i=0; i<indicators.length; i++) {
                let stepNum = parseInt(indicators[i].getAttribute('data-step'));
                indicators[i].classList.remove('active', 'completed');
                if(stepNum === currentStep) indicators[i].classList.add('active');
                else if(stepNum < currentStep && indicators[i].style.display !== 'none') indicators[i].classList.add('completed');
            }
            document.querySelector('.mpc-checkout-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function mpcSubmitOrder(btn) {
            let selectedCats = [];
            if (checkoutData.isJuice) { selectedCats.push('Juices'); } 
            else { document.querySelectorAll('.mpc-cat-checkbox:checked').forEach(b => selectedCats.push(b.value)); }

            btn.innerText = 'Redirecting to Checkout...';
            btn.disabled = true;

            let formData = new URLSearchParams();
            formData.append('action', 'mpc_process_order');
            formData.append('nonce', '<?php echo wp_create_nonce("mpc_checkout_nonce"); ?>');
            formData.append('product_id', checkoutData.productId);
            formData.append('first_name', document.getElementById('mpc_first_name').value);
            formData.append('last_name', document.getElementById('mpc_last_name').value);
            formData.append('email', document.getElementById('mpc_email').value);
            formData.append('phone', document.getElementById('mpc_phone').value);
            if (!isUserLoggedIn) {
                formData.append('password', document.getElementById('mpc_password').value);
            }
            formData.append('address_1', document.getElementById('mpc_address_1').value);
            formData.append('address_2', document.getElementById('mpc_address_2').value);
            formData.append('delivery_method', document.getElementById('mpc_delivery_method').value);
            formData.append('delivery_timing', document.getElementById('mpc_delivery_timing').value);
            formData.append('time_slot', document.getElementById('mpc_time_slot').value);
            formData.append('pickup_location', document.getElementById('mpc_pickup_branch').value);
            formData.append('allergies', document.getElementById('mpc_allergies').value);
            selectedCats.forEach(cat => formData.append('categories[]', cat));

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if(response.success) {
                    localStorage.removeItem('mpcCheckoutState'); 
                    window.location.href = response.data.payment_url;
                } else {
                    alert('Error: ' + response.data);
                    btn.innerText = 'Proceed to Checkout \u2192';
                    btn.disabled = false;
                }
            })
            .catch(error => {
                alert('An unexpected error occurred. Please try again.');
                btn.innerText = 'Proceed to Checkout \u2192';
                btn.disabled = false;
            });
        }
        
        mpcAdjustTimingOptions();
        updateLogisticsSummary();
    </script>
    <?php
    return ob_get_clean();
}

// ==========================================
// 5. WOOCOMMERCE PAYMENT & SUCCESS HANDLERS
// ==========================================
add_action( 'woocommerce_order_status_processing', 'mpc_activate_subscription_on_payment', 10, 1 );
add_action( 'woocommerce_order_status_completed', 'mpc_activate_subscription_on_payment', 10, 1 );

function mpc_activate_subscription_on_payment( $order_id ) {
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $wpdb->update($table_subs, array('status' => 'active'), array('wc_order_id' => $order_id, 'status' => 'pending'));
}

add_action( 'woocommerce_thankyou', 'mpc_add_dashboard_button_to_thankyou', 10, 1 );

function mpc_add_dashboard_button_to_thankyou( $order_id ) {
    $portal_url = site_url('/my-meal-portal/'); 
    echo '<div style="margin-top: 40px; padding: 30px; background: #f8fafc; border: 2px solid #379237; border-radius: 8px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">';
    echo '<h3 style="color: #379237; margin-top: 0; font-size: 1.5em;">Your Meal Plan is Ready!</h3>';
    echo '<p style="color: #475569; margin-bottom: 25px; font-size: 1.1em;">Head over to your personal dashboard to start scheduling your meals and tracking your macros.</p>';
    echo '<a href="' . esc_url($portal_url) . '" style="background: #46b450; color: #fff; padding: 15px 35px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 1.1em; display: inline-block; transition: opacity 0.2s;">Go to My Dashboard &rarr;</a>';
    echo '</div>';
}

// CHANGE THE "PAY FOR ORDER" BUTTON TEXT ON CHECKOUT PAGE
add_filter( 'woocommerce_pay_order_button_text', 'mpc_change_pay_button_text' );
function mpc_change_pay_button_text( $text ) {
    return 'Place the Order';
}

// ==========================================
// 6. CUSTOMER DASHBOARD PROFILE WIDGET
// ==========================================
add_shortcode( 'meal_plan_customer_profile', 'mpc_render_customer_profile' );

function mpc_render_customer_profile() {
    if ( ! is_user_logged_in() ) { return ''; }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    $phone = get_user_meta($user_id, 'billing_phone', true);
    $method = get_user_meta($user_id, 'delivery_method', true);
    $timing = get_user_meta($user_id, 'delivery_timing', true);
    $time_slot = get_user_meta($user_id, 'time_slot', true);
    $pickup = get_user_meta($user_id, 'pickup_location', true);
    $allergies = get_user_meta($user_id, 'allergies', true);

    $address_1 = get_user_meta($user_id, 'billing_address_1', true);
    $address_2 = get_user_meta($user_id, 'billing_address_2', true);
    $city = get_user_meta($user_id, 'billing_city', true);
    
    $full_address = array_filter([$address_1, $address_2, $city]);
    $address_display = !empty($full_address) ? implode(', ', $full_address) : 'Address not provided';

    $allergies_display = !empty($allergies) ? esc_html($allergies) : 'No Allergies Recorded';
    
    // VISUAL FALLBACKS FOR OLD ORDERS
    $method_display = $method ?: 'N/A';
    if ($method === 'Pickup' && !empty($pickup)) {
        $method_display .= ' (' . esc_html($pickup) . ')';
    }
    $timing_display = $timing ?: 'N/A';
    $time_slot_display = $time_slot ?: 'N/A';

    ob_start();
    ?>
    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 25px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between;">
        
        <div style="flex: 1; min-width: 250px;">
            <h3 style="margin-top: 0; color: #379237; font-size: 1.3em; margin-bottom: 15px;">Account Details</h3>
            <p style="margin: 0 0 8px 0; color: #334155;"><strong>Name:</strong> <?php echo esc_html($current_user->first_name . ' ' . $current_user->last_name); ?></p>
            <p style="margin: 0 0 8px 0; color: #334155;"><strong>Email:</strong> <?php echo esc_html($current_user->user_email); ?></p>
            <p style="margin: 0 0 8px 0; color: #334155;"><strong>Phone:</strong> <?php echo esc_html($phone ?: 'N/A'); ?></p>
            <p style="margin: 0; color: #334155;"><strong>Address:</strong> <?php echo esc_html($address_display); ?></p>
        </div>

        <div style="flex: 1; min-width: 250px; background: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #f1f5f9;">
            <h3 style="margin-top: 0; color: #0f172a; font-size: 1.1em; margin-bottom: 12px;">Active Logistics</h3>
            <p style="margin: 0 0 8px 0; color: #475569;"><strong>Method:</strong> <?php echo esc_html($method_display); ?></p>
            <p style="margin: 0 0 8px 0; color: #475569;"><strong>Receive By:</strong> <?php echo esc_html($timing_display); ?></p>
            <p style="margin: 0 0 8px 0; color: #475569;"><strong>Time Slot:</strong> <?php echo esc_html($time_slot_display); ?></p>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #cbd5e1;">
                <p style="margin: 0; color: #b45309;"><strong>Allergies:</strong> <?php echo $allergies_display; ?></p>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

// ==========================================
// 7. PAYMENT VERIFICATION BRIDGE
// ==========================================
add_action('template_redirect', 'mpc_verify_ngenius_payment_return');
function mpc_verify_ngenius_payment_return() {
    // Only run this script if the customer lands on the WooCommerce order-received page with a gateway reference
    if ( is_wc_endpoint_url( 'order-received' ) && isset($_GET['ref']) ) {
        global $wp;
        $order_id = absint( $wp->query_vars['order-received'] );
        $order    = wc_get_order( $order_id );
        
        // Skip if the order is missing or already marked as paid
        if ( ! $order || $order->is_paid() ) {
            return;
        }

        $reference = sanitize_text_field($_GET['ref']);
        
        // Ask the main website to verify the payment status
        $main_site_url = 'https://staging3.thecyclehub.com'; 
        $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/verify';

        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-bistro-token' => BISTRO_BRIDGE_SECRET
            ),
            'body'    => wp_json_encode( array('reference' => $reference) ),
            'timeout' => 15,
        ));

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            
            if ( isset($body['success']) && $body['success'] === true ) {
                $state = $body['state'];
                
                if ( $state === 'CAPTURED' || $state === 'AUTHORISED' ) {
                    // Payment successful! Mark order as processing
                    $order->payment_complete( $reference );
                    $order->add_order_note('Payment successfully captured via N-Genius Bridge. Reference: ' . $reference);
                } else {
                    // Payment failed or declined
                    $order->update_status('failed', 'Payment failed or declined via N-Genius Bridge. State: ' . $state);
                }
            }
        }
    }
}
// END OF FILE
