<?php
/**
 * Plugin Name: Meal Plan Custom Checkout
 * Description: A companion plugin that provides a streamlined 3-step custom checkout wizard with login, auto-fill, and direct payment routing.
 * Version: 2.8.1
 * Author: RM Dev Team | Customised by Fareed M Rifaideen
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. ENQUEUE SCRIPTS & STYLES (With Dynamic Cache Busting)
// ==========================================
add_action('wp_enqueue_scripts', 'mpc_enqueue_assets');
function mpc_enqueue_assets() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'meal_plan_checkout') ) {
        $css_file_path = plugin_dir_path( __FILE__ ) . 'assets/mpc-style.css';
        // Force browser to reload CSS whenever the file is saved
        $version = file_exists($css_file_path) ? filemtime($css_file_path) : '2.8';
        wp_enqueue_style( 'mpc-wizard-styles', plugin_dir_url( __FILE__ ) . 'assets/mpc-style.css', array(), $version );
    }
}

// ==========================================
// 2. AJAX HANDLER: SECURE USER LOGIN
// ==========================================
add_action('wp_ajax_nopriv_mpc_login_user', 'mpc_ajax_login');
function mpc_ajax_login() {
    check_ajax_referer('mpc_checkout_nonce', 'nonce');
    $creds = array('user_login' => sanitize_text_field($_POST['log']), 'user_password' => $_POST['pwd'], 'remember' => true);
    $user = wp_signon( $creds, is_ssl() );
    if ( is_wp_error( $user ) ) { wp_send_json_error( $user->get_error_message() ); } else {
        $user_id = $user->ID;
        $data = array(
            'first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->user_email,
            'phone' => get_user_meta($user_id, 'billing_phone', true), 'address_1' => get_user_meta($user_id, 'billing_address_1', true),
            'address_2' => get_user_meta($user_id, 'billing_address_2', true), 'delivery_method' => get_user_meta($user_id, 'delivery_method', true),
            'delivery_timing' => get_user_meta($user_id, 'delivery_timing', true), 'time_slot' => get_user_meta($user_id, 'time_slot', true),
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
    $product_id = intval($_POST['product_id']); $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']); $email = sanitize_email($_POST['email']);
    $phone = sanitize_text_field($_POST['phone']); $password = isset($_POST['password']) ? $_POST['password'] : ''; 
    $address_1 = sanitize_text_field($_POST['address_1']); $address_2 = sanitize_text_field($_POST['address_2']);
    $delivery_method = sanitize_text_field($_POST['delivery_method']); $delivery_timing = sanitize_text_field($_POST['delivery_timing']);
    $time_slot = sanitize_text_field($_POST['time_slot']); $pickup_location = isset($_POST['pickup_location']) ? sanitize_text_field($_POST['pickup_location']) : '';
    $allergies = sanitize_textarea_field($_POST['allergies']); $categories = isset($_POST['categories']) ? array_map('sanitize_text_field', $_POST['categories']) : array();

    if (!$product_id || !$email || !$first_name || !$address_1) { wp_send_json_error('Missing mandatory fields.'); }
    $product = wc_get_product($product_id); $plan_title = $product->get_name();
    preg_match('/(\d+)\s*Meal/i', $plan_title, $matches); $allowed_meals = isset($matches[1]) ? intval($matches[1]) : 0;
    if ($allowed_meals > 0 && count($categories) !== $allowed_meals) { wp_send_json_error('Invalid meal selection.'); }
    if ($allowed_meals > 0 && !in_array('Snacks', $categories)) { $categories[] = 'Snacks'; }

    $user_id = get_current_user_id();
    if (!$user_id) {
        if (email_exists($email)) { wp_send_json_error('Account exists. Please log in.'); }
        $user_id = wp_create_user($email, $password, $email);
        if (is_wp_error($user_id)) { wp_send_json_error( $user_id->get_error_message() ); }
        wp_clear_auth_cookie(); wp_set_current_user($user_id); wp_set_auth_cookie($user_id, true);
    }
    
    wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
    update_user_meta($user_id, 'billing_phone', $phone); update_user_meta($user_id, 'billing_address_1', $address_1);
    update_user_meta($user_id, 'billing_address_2', $address_2); update_user_meta($user_id, 'billing_city', 'Dubai');
    update_user_meta($user_id, 'billing_country', 'AE'); update_user_meta($user_id, 'delivery_method', $delivery_method);
    update_user_meta($user_id, 'delivery_timing', $delivery_timing); update_user_meta($user_id, 'time_slot', $time_slot);
    update_user_meta($user_id, 'pickup_location', $pickup_location); update_user_meta($user_id, 'allergies', $allergies);
    
    $order = wc_create_order(array('customer_id' => $user_id)); $order->add_product($product, 1);
    $addr = array('first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'phone' => $phone, 'address_1' => $address_1, 'address_2' => $address_2, 'city' => 'Dubai', 'country' => 'AE');
    $order->set_address($addr, 'billing'); $order->set_address($addr, 'shipping'); $order->set_customer_note($allergies);
    $order->update_meta_data('delivery_method', $delivery_method); $order->update_meta_data('delivery_timing', $delivery_timing);
    $order->update_meta_data('time_slot', $time_slot); $order->update_meta_data('pickup_location', $pickup_location);
    $order->update_meta_data('allergies', $allergies); $order->update_meta_data('_cmp_allowed_categories', implode(',', $categories));
    $order->calculate_totals(); $order->save();

    global $wpdb; $table = $wpdb->prefix . 'cmp_subscriptions';
    $days = 30;
    if (stripos($plan_title, '7') !== false) $days = 7;
    if (stripos($plan_title, '20') !== false) $days = 20;
    if (stripos($plan_title, '24') !== false) $days = 24;
    if (stripos($plan_title, '3') !== false && stripos($plan_title, 'juice') !== false) $days = 3;

    $sub_data = array('user_id' => $user_id, 'wc_order_id' => $order->get_id(), 'plan_name' => $plan_title, 'total_days' => $days, 'allowed_categories' => implode(',', $categories), 'status' => 'pending', 'start_date' => date('Y-m-d H:i:s'), 'expiry_date' => date('Y-m-d H:i:s', strtotime("+$days days")));
    $wpdb->insert($table, $sub_data);
    wp_send_json_success(array('payment_url' => $order->get_checkout_payment_url()));
}

// ==========================================
// 4. FRONTEND WIZARD RENDERER
// ==========================================
add_shortcode( 'meal_plan_checkout', 'mpc_render_checkout_wizard' );
function mpc_render_checkout_wizard() {
    if ( ! class_exists( 'WooCommerce' ) ) { return '<p>WooCommerce required.</p>'; }
    global $wpdb; $table_foods = $wpdb->prefix . 'cmp_foods';
    $food_categories = $wpdb->get_col("SELECT DISTINCT category_name FROM $table_foods WHERE is_active = 1 ORDER BY category_name ASC");
    $map_url = get_option('cmp_map_url', '#');
    $products = wc_get_products( array('status' => 'publish', 'limit' => -1, 'orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC') );
    $grouped_plans = array('7-days' => array('label' => '7-Day Plans', 'items' => array()), '20-days' => array('label' => '20-Day Plans', 'items' => array()), '24-days' => array('label' => '24-Day Plans', 'items' => array()), 'juice' => array('label' => 'Cleanse Boosters', 'items' => array()), 'other' => array('label' => 'Other Plans', 'items' => array()));
    foreach ($products as $product) {
        $tags = wp_get_post_terms( $product->get_id(), 'product_tag', array('fields' => 'slugs') ); $assigned = false;
        foreach (['7-days', '20-days', '24-days', 'juice'] as $t) { if (in_array($t, $tags)) { $grouped_plans[$t]['items'][] = $product; $assigned = true; break; } }
        if (!$assigned) { $grouped_plans['other']['items'][] = $product; }
    }
    ob_start(); ?>
    <div class="mpc-checkout-container"><div class="mpc-wizard-area">
            <div class="mpc-progress"><div class="mpc-step-indicator active" data-step="1"><div class="mpc-step-circle">1</div><span>Select Plan</span></div><div class="mpc-step-indicator" data-step="2"><div class="mpc-step-circle">2</div><span>Delivery Details</span></div><div class="mpc-step-indicator" data-step="3" id="mpc-indicator-meals"><div class="mpc-step-circle">3</div><span>Meal Type</span></div></div>
            <div id="mpc-step-1" class="mpc-step-content active"><h2 style="margin-top:0;">Choose Your Plan</h2><p style="color:#666; margin-bottom:20px;">Select a meal plan to get started. After finalizing, we will be in touch for payment and meal selection.</p>
                <?php foreach ($grouped_plans as $gk => $gd) { if ( empty($gd['items']) ) continue; echo '<h3 class="mpc-cat-header">' . esc_html($gd['label']) . '</h3><div class="mpc-grid">';
                    foreach ( $gd['items'] as $product ) { $id = $product->get_id(); $title = $product->get_name(); $price = $product->get_price_html(); $raw = $product->get_price(); $desc = $product->get_short_description(); $is_juice = (stripos($title, 'juice') !== false) ? 'true' : 'false'; preg_match('/(\d+)\s*Meal/i', $title, $matches); $meals = isset($matches[1]) ? intval($matches[1]) : 0; $display_title = str_replace(' - ', ' - <br>', esc_html($title));
                        echo '<div class="mpc-tile" onclick="mpcSelectPlan(this, \''.esc_attr($title).'\', \''.esc_attr($raw).'\', '.$is_juice.', '.$id.', '.$meals.')"><div><div class="mpc-tile-title">' . $display_title . '</div><div class="mpc-tile-price">' . wp_kses_post($price) . '</div></div>';
                        if (!empty($desc)) echo '<div class="mpc-tile-desc">' . wp_kses_post($desc) . '</div>'; echo '</div>'; } echo '</div>'; } ?>
                <div class="mpc-nav-buttons"><button class="mpc-btn mpc-btn-next" onclick="mpcChangeStep(1)" id="btn-next-1" disabled>Next: Delivery Details &rarr;</button></div></div>
            <div id="mpc-step-2" class="mpc-step-content"><h2 style="margin-top:0;">Delivery Information</h2>
                <?php if ( ! is_user_logged_in() ) : ?>
                    <div id="mpc-login-section" class="mpc-login-box"><p><strong>Already a customer?</strong> <a href="#" id="mpc-show-login">Click here to log in</a></p><div id="mpc-login-form" style="display:none; margin-top:15px;"><div class="mpc-form-row"><div class="mpc-form-col"><input type="email" id="mpc_login_email" class="mpc-form-control" placeholder="Email"></div><div class="mpc-form-col"><input type="password" id="mpc_login_pwd" class="mpc-form-control" placeholder="Password"></div></div><button type="button" id="mpc-do-login-btn" class="mpc-btn" style="height:40px; margin-top:10px;">Log In</button><span id="mpc-login-msg" style="color:red; margin-left:10px;"></span></div></div>
                <?php else: $u = wp_get_current_user(); $sd = array('first_name' => $u->first_name, 'last_name' => $u->last_name, 'email' => $u->user_email, 'phone' => get_user_meta($u->ID, 'billing_phone', true), 'address_1' => get_user_meta($u->ID, 'billing_address_1', true), 'address_2' => get_user_meta($u->ID, 'billing_address_2', true), 'delivery_method' => get_user_meta($u->ID, 'delivery_method', true), 'delivery_timing' => get_user_meta($u->ID, 'delivery_timing', true), 'time_slot' => get_user_meta($u->ID, 'time_slot', true), 'pickup_location' => get_user_meta($u->ID, 'pickup_location', true)); ?>
                    <div id="mpc-logged-in-section" class="mpc-welcome-box"><p><strong>Welcome back, <?php echo esc_html($u->first_name); ?>!</strong></p><label style="cursor:pointer;"><input type="checkbox" id="mpc_use_saved_details"> Use saved delivery details</label><script>var mpcSavedDetails = <?php echo json_encode($sd); ?>;</script></div>
                <?php endif; ?>
                <div class="mpc-form-row"><div class="mpc-form-col mpc-form-group"><label>First Name *</label><input type="text" class="mpc-form-control" id="mpc_first_name" required></div><div class="mpc-form-col mpc-form-group"><label>Last Name *</label><input type="text" class="mpc-form-control" id="mpc_last_name" required></div></div>
                <div class="mpc-form-row"><div class="mpc-form-col mpc-form-group"><label>Email *</label><input type="email" class="mpc-form-control" id="mpc_email" required <?php if(is_user_logged_in()) echo 'readonly'; ?>></div><div class="mpc-form-col mpc-form-group"><label>Phone *</label><input type="tel" class="mpc-form-control" id="mpc_phone" required></div></div>
                <div class="mpc-form-row" id="mpc_password_group" <?php if(is_user_logged_in()) echo 'style="display:none;"'; ?>><div class="mpc-form-col mpc-form-group"><label>Create Password *</label><div style="position:relative;"><input type="password" class="mpc-form-control" id="mpc_password" style="padding-right:45px;"><span id="mpc_toggle_password" style="position:absolute; right:12px; top:12px; cursor:pointer;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></span></div></div></div>
                <hr><div class="mpc-form-row"><div class="mpc-form-col mpc-form-group"><label>Address Line 1 *</label><input type="text" class="mpc-form-control" id="mpc_address_1" required></div><div class="mpc-form-col mpc-form-group"><label>Address Line 2</label><input type="text" class="mpc-form-control" id="mpc_address_2"></div></div>
                <div class="mpc-form-row"><div class="mpc-form-col mpc-form-group"><label>City & Country</label><input type="text" class="mpc-form-control" value="Dubai, UAE" disabled style="background:#f8fafc;"></div></div>
                <hr><div class="mpc-form-row"><div class="mpc-form-col mpc-form-group"><label>Method *</label><select class="mpc-form-control" id="mpc_delivery_method"><option value="Delivery">Home/Office Delivery</option><option value="Pickup">Store Pick-up</option></select></div><div class="mpc-form-col mpc-form-group"><label>Receive by *</label><select class="mpc-form-control" id="mpc_delivery_timing"><option value="Deliver Day Before">Deliver Day Before</option><option value="Deliver Same Day">Deliver Same Day</option></select></div></div>
                <div class="mpc-form-row" id="mpc_dynamic_logistics_row"><div class="mpc-form-col mpc-form-group"><div id="mpc_delivery_zone_container" class="mpc-logistics-box"><label><input type="checkbox" id="mpc_delivery_zone_check"> Within delivery zone (<a href="<?php echo esc_url($map_url); ?>" target="_blank">View Map</a>) *</label></div><div id="mpc_pickup_branch_container" class="mpc-logistics-box" style="display:none;"><label>Select Branch *</label><select class="mpc-form-control" id="mpc_pickup_branch"><option value="">- Select -</option><option value="Jumeirah">Jumeirah</option><option value="Motor City">Motor City</option></select></div></div><div class="mpc-form-col mpc-form-group"><label>Time Slot *</label><select class="mpc-form-control" id="mpc_time_slot"><?php for ($i=5; $i<20; $i++) { $s = date("g:i A", strtotime("$i:00")); $e = date("g:i A", strtotime(($i+1).":00")); echo "<option value='$s to $e'>$s to $e</option>"; } ?></select></div></div>
                <div class="mpc-nav-buttons"><button class="mpc-btn mpc-btn-back" onclick="mpcChangeStep(-1)">&larr; Back</button><button class="mpc-btn mpc-btn-next" onclick="mpcChangeStep(1)" id="btn-next-2">Next Step &rarr;</button></div></div>
            <div id="mpc-step-3" class="mpc-step-content"><h2 style="margin-top:0;">Meal Type</h2><p id="mpc-meals-subtitle" style="font-weight:bold;">Which meal categories would you like included?</p><div class="mpc-form-group" style="background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #cbd5e1;"><?php foreach ($food_categories as $c) { if (strtolower($c)==='juices' || strtolower($c)==='snacks') continue; echo "<label style='display:flex; align-items:center; gap:10px; margin-bottom:15px; cursor:pointer;'><input type='checkbox' class='mpc-cat-checkbox' value='".esc_attr($c)."' style='transform:scale(1.2);'> ".esc_html($c)."</label>"; } ?></div>
                <div class="mpc-form-group"><label>Allergies?</label><textarea class="mpc-form-control" id="mpc_allergies" rows="3"></textarea></div><div class="mpc-nav-buttons"><button class="mpc-btn mpc-btn-back" onclick="mpcChangeStep(-1)">&larr; Back</button><button class="mpc-btn mpc-btn-next" onclick="mpcChangeStep(1)" id="btn-next-3" style="background:#46b450;">Proceed to Checkout &rarr;</button></div></div>
        </div>
        <div class="mpc-summary-area"><h3>Plan Summary</h3><div id="mpc-summary-content"><p style="color:#666; font-style:italic;">Please select a plan.</p></div><div id="mpc-summary-logistics" style="display:none; margin-top:15px; padding-top:15px; border-top:1px dashed #ddd; font-size:0.9em;"><strong>Method:</strong> <span id="sum-method"></span><br><strong>Time:</strong> <span id="sum-timing"></span></div><div id="mpc-summary-meals" style="display:none; margin-top:15px; padding-top:15px; border-top:1px dashed #ddd; font-size:0.9em;"><strong>Categories:</strong><br><span id="sum-cats" style="color:#379237;"></span></div></div>
    </div>
    <script>
        let currentStep = 1; let totalSteps = 3; let checkoutData = { productId: null, isJuice: false, meals: 0 };
        let isUserLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
        document.getElementById('mpc_toggle_password').addEventListener('click', function() { let p = document.getElementById('mpc_password'); p.type = (p.type==='password') ? 'text' : 'password'; });
        function populateFields(d) {
            document.getElementById('mpc_first_name').value = d.first_name; document.getElementById('mpc_last_name').value = d.last_name;
            if(d.email) { let e = document.getElementById('mpc_email'); e.value = d.email; e.readOnly = true; e.style.background = '#f1f5f9'; }
            document.getElementById('mpc_phone').value = d.phone; document.getElementById('mpc_address_1').value = d.address_1; document.getElementById('mpc_address_2').value = d.address_2;
            if(d.delivery_method) { document.getElementById('mpc_delivery_method').value = d.delivery_method; document.getElementById('mpc_delivery_method').dispatchEvent(new Event('change')); }
            document.getElementById('mpc_delivery_timing').value = d.delivery_timing; document.getElementById('mpc_time_slot').value = d.time_slot;
            if(d.pickup_location && d.delivery_method === 'Pickup') document.getElementById('mpc_pickup_branch').value = d.pickup_location;
            updateLogisticsSummary();
        }
        let cb = document.getElementById('mpc_use_saved_details'); if(cb) { cb.addEventListener('change', function() { if(this.checked && typeof mpcSavedDetails !== 'undefined') populateFields(mpcSavedDetails); }); }
        let sl = document.getElementById('mpc-show-login'); if(sl) { sl.addEventListener('click', function(e) { e.preventDefault(); document.getElementById('mpc-login-form').style.display = 'block'; this.style.display = 'none'; }); }
        document.getElementById('mpc-do-login-btn')?.addEventListener('click', function() {
            let l = document.getElementById('mpc_login_email').value; let p = document.getElementById('mpc_login_pwd').value;
            let fd = new URLSearchParams(); fd.append('action', 'mpc_login_user'); fd.append('nonce', '<?php echo wp_create_nonce("mpc_checkout_nonce"); ?>'); fd.append('log', l); fd.append('pwd', p);
            fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                if(res.success) { isUserLoggedIn = true; document.getElementById('mpc_password_group').style.display = 'none';
                    document.getElementById('mpc-login-section').innerHTML = '<div class="mpc-welcome-box"><p><strong>Welcome, ' + res.data.first_name + '!</strong></p><label><input type="checkbox" id="mpc_use_saved_details_ajax"> Use saved details</label></div>';
                    document.getElementById('mpc_use_saved_details_ajax').addEventListener('change', function() { if(this.checked) populateFields(res.data); });
                } else { document.getElementById('mpc-login-msg').innerText = res.data; }
            });
        });
        function mpcSelectPlan(el, name, price, juice, id, meals) {
            checkoutData = { productId: id, isJuice: juice, meals: meals };
            let t = document.getElementsByClassName('mpc-tile'); for(let i=0; i<t.length; i++) t[i].classList.remove('selected'); el.classList.add('selected');
            document.getElementById('btn-next-1').disabled = false;
            document.getElementById('mpc-summary-content').innerHTML = `<div><strong>Plan:</strong><br><span style="color:#379237;">${name}</span></div><div style="margin-top:15px; border-top:1px dashed #ddd; padding-top:15px;"><strong>Total:</strong><br><span style="font-size:1.4em; font-weight:bold;">AED ${price}</span></div>`;
            if (juice) { document.getElementById('mpc-indicator-meals').style.display = 'none'; totalSteps = 2; let b2 = document.getElementById('btn-next-2'); b2.innerText = 'Proceed to Checkout \u2192'; b2.style.background = '#46b450'; document.querySelectorAll('.mpc-step-indicator').forEach(el => el.style.width = '50%'); }
            else { document.getElementById('mpc-indicator-meals').style.display = 'block'; totalSteps = 3; let b2 = document.getElementById('btn-next-2'); b2.innerText = 'Next Step \u2192'; b2.style.background = '#379237'; document.querySelectorAll('.mpc-step-indicator').forEach(el => el.style.width = '33.33%'); if (meals > 0) document.getElementById('mpc-meals-subtitle').innerText = `Pick exactly ${meals} categories.`; }
            updateLogisticsSummary();
        }
        function mpcAdjustTimingOptions() {
            let m = document.getElementById('mpc_delivery_method').value; let s = document.getElementById('mpc_time_slot'); let o = s.options; let r = false;
            for(let i=0; i<o.length; i++) { let e = /^(5|6|7):00 AM/.test(o[i].value); if(m==='Pickup' && e) { o[i].disabled = true; o[i].hidden = true; if(o[i].selected) r=true; } else { o[i].disabled = false; o[i].hidden = false; } }
            if(r) s.value = "8:00 AM to 9:00 AM";
        }
        document.getElementById('mpc_delivery_method').addEventListener('change', function() { if(this.value === 'Delivery') { document.getElementById('mpc_delivery_zone_container').style.display='block'; document.getElementById('mpc_pickup_branch_container').style.display='none'; } else { document.getElementById('mpc_delivery_zone_container').style.display='none'; document.getElementById('mpc_pickup_branch_container').style.display='block'; } mpcAdjustTimingOptions(); updateLogisticsSummary(); });
        document.getElementById('mpc_delivery_timing').addEventListener('change', updateLogisticsSummary); document.getElementById('mpc_pickup_branch').addEventListener('change', updateLogisticsSummary); document.getElementById('mpc_time_slot').addEventListener('change', updateLogisticsSummary);
        function updateLogisticsSummary() { let m = document.getElementById('mpc_delivery_method').value; let dm = m === 'Pickup' ? 'Pickup (' + document.getElementById('mpc_pickup_branch').value + ')' : m; document.getElementById('mpc-summary-logistics').style.display='block'; document.getElementById('sum-method').innerText = dm; document.getElementById('sum-timing').innerHTML = document.getElementById('mpc_delivery_timing').value + '<br><strong>Time:</strong> ' + document.getElementById('mpc_time_slot').value; }
        function mpcChangeStep(dir) {
            if(dir === 1 && currentStep === 2) { if(!document.getElementById('mpc_first_name').value || !document.getElementById('mpc_email').value || (!isUserLoggedIn && !document.getElementById('mpc_password').value) || !document.getElementById('mpc_phone').value || !document.getElementById('mpc_address_1').value) { alert('Required fields missing.'); return; } if (document.getElementById('mpc_delivery_method').value === 'Delivery' && !document.getElementById('mpc_delivery_zone_check').checked) { alert('Confirm zone.'); return; } if(checkoutData.isJuice) { mpcSubmitOrder(document.getElementById('btn-next-2')); return; } }
            if(dir === 1 && currentStep === 3 && !checkoutData.isJuice) { if (checkoutData.meals > 0 && document.querySelectorAll('.mpc-cat-checkbox:checked').length !== checkoutData.meals) { alert(`Pick ${checkoutData.meals} categories.`); return; } mpcSubmitOrder(document.getElementById('btn-next-3')); return; }
            document.getElementById('mpc-step-' + currentStep).classList.remove('active'); currentStep += dir;
            document.getElementById('mpc-step-' + currentStep).classList.add('active');
            let ind = document.getElementsByClassName('mpc-step-indicator'); for(let i=0; i<ind.length; i++) { let n = parseInt(ind[i].getAttribute('data-step')); ind[i].classList.remove('active', 'completed'); if(n===currentStep) ind[i].classList.add('active'); else if(n<currentStep && ind[i].style.display !== 'none') ind[i].classList.add('completed'); }
            window.scrollTo({top: 0, behavior: 'smooth'});
        }
        function mpcSubmitOrder(btn) {
            let cats = []; if (checkoutData.isJuice) cats.push('Juices'); else document.querySelectorAll('.mpc-cat-checkbox:checked').forEach(b => cats.push(b.value));
            btn.innerText = 'Wait...'; btn.disabled = true;
            let fd = new URLSearchParams(); fd.append('action', 'mpc_process_order'); fd.append('nonce', '<?php echo wp_create_nonce("mpc_checkout_nonce"); ?>'); fd.append('product_id', checkoutData.productId); fd.append('first_name', document.getElementById('mpc_first_name').value); fd.append('last_name', document.getElementById('mpc_last_name').value); fd.append('email', document.getElementById('mpc_email').value); fd.append('phone', document.getElementById('mpc_phone').value); if(!isUserLoggedIn) fd.append('password', document.getElementById('mpc_password').value); fd.append('address_1', document.getElementById('mpc_address_1').value); fd.append('address_2', document.getElementById('mpc_address_2').value); fd.append('delivery_method', document.getElementById('mpc_delivery_method').value); fd.append('delivery_timing', document.getElementById('mpc_delivery_timing').value); fd.append('time_slot', document.getElementById('mpc_time_slot').value); fd.append('pickup_location', document.getElementById('mpc_pickup_branch').value); fd.append('allergies', document.getElementById('mpc_allergies').value); cats.forEach(c => fd.append('categories[]', c));
            fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: fd }).then(r => r.json()).then(r => { if(r.success) { window.location.href = r.data.payment_url; } else { alert(r.data); btn.innerText = 'Checkout'; btn.disabled = false; } });
        }
    </script>
    <?php return ob_get_clean();
}
add_action( 'woocommerce_order_status_processing', 'mpc_activate_sub', 10, 1 );
add_action( 'woocommerce_order_status_completed', 'mpc_activate_sub', 10, 1 );
function mpc_activate_sub($order_id) { global $wpdb; $table = $wpdb->prefix . 'cmp_subscriptions'; $wpdb->update($table, array('status' => 'active'), array('wc_order_id' => $order_id, 'status' => 'pending')); }
add_action( 'woocommerce_thankyou', 'mpc_thankyou_btn', 10, 1 );
function mpc_thankyou_btn($order_id) { $url = site_url('/my-meal-portal/'); echo '<div style="margin-top:40px; padding:30px; background:#f8fafc; border:2px solid #379237; border-radius:8px; text-align:center;"><h3 style="color:#379237;">Your Meal Plan is Ready!</h3><a href="'.esc_url($url).'" style="background:#46b450; color:#fff; padding:15px 35px; border-radius:4px; text-decoration:none; font-weight:bold;">Go to My Dashboard &rarr;</a></div>'; }
add_filter( 'woocommerce_pay_order_button_text', function(){ return 'Place the Order'; } );
add_shortcode( 'meal_plan_customer_profile', 'mpc_render_customer_profile' );
function mpc_render_customer_profile() {
    if ( ! is_user_logged_in() ) { return ''; }
    $u = wp_get_current_user(); $user_id = $u->ID;
    $p = get_user_meta($user_id, 'billing_phone', true); $m = get_user_meta($user_id, 'delivery_method', true); $t = get_user_meta($user_id, 'delivery_timing', true); $ts = get_user_meta($user_id, 'time_slot', true); $pk = get_user_meta($user_id, 'pickup_location', true); $al = get_user_meta($user_id, 'allergies', true); $a1 = get_user_meta($user_id, 'billing_address_1', true); $a2 = get_user_meta($user_id, 'billing_address_2', true); $c = get_user_meta($user_id, 'billing_city', true); $fa = array_filter([$a1, $a2, $c]); $ad = !empty($fa) ? implode(', ', $fa) : 'N/A'; $al_d = !empty($al) ? esc_html($al) : 'None'; $md = $m ?: 'N/A'; if ($m === 'Pickup' && !empty($pk)) { $md .= ' (' . esc_html($pk) . ')'; }
    ob_start(); ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:25px; display:flex; flex-wrap:wrap; gap:20px; justify-content:space-between;">
        <div style="flex:1; min-width:250px;"><h3 style="color:#379237;">Account Details</h3><p><strong>Name:</strong> <?php echo esc_html($u->first_name . ' ' . $u->last_name); ?></p><p><strong>Email:</strong> <?php echo esc_html($u->user_email); ?></p><p><strong>Phone:</strong> <?php echo esc_html($p ?: 'N/A'); ?></p><p><strong>Address:</strong> <?php echo esc_html($ad); ?></p></div>
        <div style="flex:1; min-width:250px; background:#f8fafc; padding:15px; border-radius:6px;"><h3 style="color:#0f172a; font-size:1.1em;">Active Logistics</h3><p><strong>Method:</strong> <?php echo esc_html($md); ?></p><p><strong>Receive By:</strong> <?php echo esc_html($t ?: 'N/A'); ?></p><p><strong>Time Slot:</strong> <?php echo esc_html($ts ?: 'N/A'); ?></p><div style="margin-top:15px; border-top:1px dashed #cbd5e1; padding-top:15px;"><p style="color:#b45309;"><strong>Allergies:</strong> <?php echo $al_d; ?></p></div></div>
    </div>
    <?php return ob_get_clean();
}
// END OF FILE
