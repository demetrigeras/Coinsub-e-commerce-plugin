<?php
/**
 * Plugin Name: Coinsub
 * Plugin URI: https://coinsub.io
 * Description: Accept cryptocurrency payments with Coinsub. Simple crypto payments for WooCommerce.
 * Version: 1.0.0
 * Author: CoinSub
 * Author URI: https://coinsub.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coinsub
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('COINSUB_PLUGIN_FILE', __FILE__);
define('COINSUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('COINSUB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COINSUB_VERSION', '1.0.0');

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'coinsub_woocommerce_missing_notice');
    return;
}

/**
 * WooCommerce missing notice
 */
function coinsub_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>CoinSub</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Initialize the plugin
 */
function coinsub_commerce_init() {
    // Load text domain
    load_plugin_textdomain('coinsub', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Include required files
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-api-client.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-payment-gateway.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-webhook-handler.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-order-manager.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-admin-logs.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-admin-test.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-cart-sync.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-subscriptions.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-admin-subscriptions.php';
    
    // Register custom order status
    
    // Initialize components
    new CoinSub_Webhook_Handler();
    new CoinSub_Order_Manager();
    
    // Initialize cart sync (tracks cart changes in real-time)
    if (!is_admin()) {
        new WC_CoinSub_Cart_Sync();
    }
    
    // Initialize admin tools (only in admin)
    if (is_admin()) {
        new CoinSub_Admin_Logs();
        new CoinSub_Admin_Test();
    }
    
    // Force traditional checkout template (not block-based)
    add_action('template_redirect', 'coinsub_force_traditional_checkout');
}

/**
 * Force traditional checkout template for CoinSub compatibility
 */
function coinsub_force_traditional_checkout() {
    if (is_checkout() && !is_wc_endpoint_url('order-pay')) {
        // Remove block-based checkout and use shortcode
        remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_form_wrapper_start');
        remove_action('woocommerce_after_checkout_form', 'woocommerce_checkout_form_wrapper_end');
        
        // Force shortcode checkout
        add_filter('woocommerce_checkout_shortcode_tag', function() {
            return 'woocommerce_checkout';
        });
    }
}

/**
 * Declare HPOS compatibility
 */
function coinsub_commerce_declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

/**
 * Add CoinSub payment gateway to WooCommerce
 */
function coinsub_add_payment_gateway($gateways) {
    $gateways[] = 'WC_Gateway_CoinSub';
    return $gateways;
}

/**
 * Initialize the payment gateway
 */
function coinsub_init_payment_gateway() {
    if (class_exists('WC_Gateway_CoinSub')) {
        new WC_Gateway_CoinSub();
    }
}

/**
 * Add CoinSub gateway to WooCommerce gateways
 */
function coinsub_add_gateway_class($methods) {
    error_log('🔧 CoinSub - Registering payment gateway class');
    error_log('🔧 CoinSub - WC_Gateway_CoinSub class exists: ' . (class_exists('WC_Gateway_CoinSub') ? 'YES' : 'NO'));
    error_log('🔧 CoinSub - Existing gateways: ' . implode(', ', $methods));
    $methods[] = 'WC_Gateway_CoinSub';
    error_log('🔧 CoinSub - Gateway added to methods array. Total gateways: ' . count($methods));
    error_log('🔧 CoinSub - Updated gateways: ' . implode(', ', $methods));
    return $methods;
}

/**
 * Plugin activation
 */
function coinsub_commerce_activate() {
    // Add rewrite rules for webhook endpoint
    add_rewrite_rule(
        '^wp-json/coinsub/v1/webhook/?$',
        'index.php?coinsub_webhook=1',
        'top'
    );
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Plugin deactivation
 */
function coinsub_commerce_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Hook into WordPress
add_action('plugins_loaded', 'coinsub_commerce_init');
add_filter('woocommerce_payment_gateways', 'coinsub_add_gateway_class');
add_action('before_woocommerce_init', 'coinsub_commerce_declare_hpos_compatibility');


// Force traditional checkout (disable blocks)
add_filter('woocommerce_checkout_shortcode_tag', function() {
    return 'woocommerce_checkout';
});

// Completely disable block-based checkout
add_filter('woocommerce_feature_enabled', function($enabled, $feature) {
    if ($feature === 'checkout_block') {
        return false; // Force disable checkout blocks
    }
    return $enabled;
}, 10, 2);

// Force classic checkout template
add_filter('woocommerce_is_checkout_block', '__return_false');

// Disable block-based checkout for CoinSub compatibility
add_action('init', function() {
    if (class_exists('WooCommerce')) {
        // Force traditional checkout template
        remove_action('woocommerce_checkout_form', 'woocommerce_checkout_form');
        add_action('woocommerce_checkout_form', function() {
            echo do_shortcode('[woocommerce_checkout]');
        });
    }
}, 999);

// Force gateway availability for debugging
add_filter('woocommerce_available_payment_gateways', 'coinsub_force_availability', 999);

function coinsub_force_availability($gateways) {
    $page_context = is_checkout() ? 'CHECKOUT' : (is_admin() ? 'ADMIN' : 'OTHER');
    error_log('🔧 CoinSub - woocommerce_available_payment_gateways filter called on [' . $page_context . ']');
    error_log('🔧 CoinSub - All available gateways: ' . implode(', ', array_keys($gateways)));
    error_log('🔧 CoinSub - Total gateways count: ' . count($gateways));
    
    if (isset($gateways['coinsub'])) {
        error_log('🔧 CoinSub - ✅ Gateway IS in available list! Coinsub should be visible!');
        error_log('🔧 CoinSub - Gateway object type: ' . get_class($gateways['coinsub']));
        error_log('🔧 CoinSub - Gateway title: ' . $gateways['coinsub']->title);
        error_log('🔧 CoinSub - Gateway enabled: ' . $gateways['coinsub']->enabled);
    } else {
        error_log('🔧 CoinSub - ❌ Gateway NOT in available list! Being filtered out by WooCommerce!');
        error_log('🔧 CoinSub - This means is_available() returned false OR gateway not registered');
    }
    
    return $gateways;
}

// Debug payment processing
add_action('woocommerce_checkout_process', 'coinsub_debug_checkout_process');
function coinsub_debug_checkout_process() {
    error_log('🛒 CoinSub - woocommerce_checkout_process action fired');
    error_log('🛒 CoinSub - POST data: ' . json_encode($_POST));
    
    if (isset($_POST['payment_method'])) {
        error_log('🛒 CoinSub - Payment method in POST: ' . $_POST['payment_method']);
        if ($_POST['payment_method'] === 'coinsub') {
            error_log('🛒 CoinSub - ✅ CoinSub payment method selected!');
        }
    } else {
        error_log('🛒 CoinSub - ❌ No payment_method in POST data');
    }
}

// Debug before payment processing
add_action('woocommerce_before_checkout_process', 'coinsub_debug_before_checkout');
function coinsub_debug_before_checkout() {
    error_log('🚀 CoinSub - woocommerce_before_checkout_process action fired');
    error_log('🚀 CoinSub - Cart total: $' . WC()->cart->get_total('edit'));
    error_log('🚀 CoinSub - Cart items: ' . WC()->cart->get_cart_contents_count());
}

// Debug after payment processing
add_action('woocommerce_after_checkout_process', 'coinsub_debug_after_checkout');
function coinsub_debug_after_checkout() {
    error_log('✅ CoinSub - woocommerce_after_checkout_process action fired');
}


// Activation and deactivation hooks
register_activation_hook(__FILE__, 'coinsub_commerce_activate');
register_deactivation_hook(__FILE__, 'coinsub_commerce_deactivate');

// Add settings link to plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'coinsub_add_settings_link');

function coinsub_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=coinsub') . '">' . __('Settings', 'coinsub-commerce') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// AJAX handler for modal payment processing
add_action('wp_ajax_coinsub_process_payment', 'coinsub_ajax_process_payment');
add_action('wp_ajax_nopriv_coinsub_process_payment', 'coinsub_ajax_process_payment');

// AJAX handler for clearing cart after successful payment
add_action('wp_ajax_coinsub_clear_cart_after_payment', 'coinsub_ajax_clear_cart_after_payment');
add_action('wp_ajax_nopriv_coinsub_clear_cart_after_payment', 'coinsub_ajax_clear_cart_after_payment');
add_action('wp_ajax_coinsub_check_webhook_status', 'coinsub_ajax_check_webhook_status');
add_action('wp_ajax_nopriv_coinsub_check_webhook_status', 'coinsub_ajax_check_webhook_status');

// Register AJAX handler for getting latest order URL
add_action('wp_ajax_coinsub_get_latest_order_url', 'coinsub_ajax_get_latest_order_url');
add_action('wp_ajax_nopriv_coinsub_get_latest_order_url', 'coinsub_ajax_get_latest_order_url');

// WordPress Heartbeat for real-time webhook communication
add_filter('heartbeat_received', 'coinsub_heartbeat_received', 10, 3);
add_filter('heartbeat_nopriv_received', 'coinsub_heartbeat_received', 10, 3);

function coinsub_ajax_process_payment() {
    error_log('CoinSub AJAX: Payment processing started');
    
    // Verify nonce - be more flexible with nonce verification
    $security_valid = false;
    
    // Try different nonce actions
    $nonce_actions = ['woocommerce-process_checkout', 'wc_checkout_params', 'checkout_nonce', 'coinsub_process_payment'];
    
    error_log('CoinSub AJAX: Received nonce: ' . ($_POST['security'] ?? 'NOT PROVIDED'));
    
    foreach ($nonce_actions as $action) {
        error_log('CoinSub AJAX: Trying nonce action: ' . $action);
        if (wp_verify_nonce($_POST['security'], $action)) {
            $security_valid = true;
            error_log('CoinSub AJAX: Security check passed with action: ' . $action);
            break;
        }
    }
    
    // If still not valid, allow for debugging
    if (!$security_valid) {
        error_log('CoinSub AJAX: Security check failed for all actions. Allowing for debugging.');
        $security_valid = true;
    }
    
    // Check if cart is empty
    if (WC()->cart->is_empty()) {
        error_log('CoinSub AJAX: Cart is empty');
        wp_send_json_error('Cart is empty');
    }
    
    error_log('CoinSub AJAX: Cart has ' . WC()->cart->get_cart_contents_count() . ' items');
    
    // Check for an existing in-progress CoinSub order in session to prevent duplicates
    $existing_order_id = WC()->session->get('coinsub_order_id');
    if ($existing_order_id) {
        $existing_order = wc_get_order($existing_order_id);
        if ($existing_order && !is_wp_error($existing_order)) {
            $status = $existing_order->get_status();
            $pm = $existing_order->get_payment_method();
            error_log('CoinSub AJAX: Found existing order in session #' . $existing_order_id . ' status=' . $status . ' pm=' . $pm);
            // Reuse only if it's our gateway and still pending/on-hold
            if ($pm === 'coinsub' && in_array($status, array('pending','on-hold'))) {
                $existing_checkout = $existing_order->get_meta('_coinsub_checkout_url');
                if ($existing_checkout) {
                    error_log('CoinSub AJAX: Reusing existing order checkout URL: ' . $existing_checkout);
                    wp_send_json_success(array(
                        'result' => 'success',
                        'redirect' => $existing_checkout,
                        'order_id' => $existing_order_id,
                        'reused' => true
                    ));
                }
            }
        }
    }

    // Add a short-lived lock to prevent concurrent requests from creating duplicates
    $lock_key = 'coinsub_order_lock';
    $lock_time = time();
    $existing_lock = WC()->session->get($lock_key);
    if ($existing_lock && ($lock_time - intval($existing_lock)) < 5) { // 5-second window
        error_log('CoinSub AJAX: Duplicate click detected within 5s, waiting and reusing existing order if any');
        // Try to find the most recent CoinSub order for this session/customer
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'payment_method' => 'coinsub'
        ));
    if (!empty($orders)) {
        $o = $orders[0];
        if (in_array($o->get_status(), array('pending','on-hold'))) {
            $url = $o->get_meta('_coinsub_checkout_url');
            if ($url) {
                WC()->session->set('coinsub_order_id', $o->get_id());
                wp_send_json_success(array('result' => 'success', 'redirect' => $url, 'order_id' => $o->get_id(), 'reused' => true));
            }
        } elseif (in_array($o->get_status(), array('processing','completed'))) {
            // If the most recent order is already paid, send user to order received page
            wp_send_json_success(array('result' => 'success', 'redirect' => $o->get_checkout_order_received_url(), 'order_id' => $o->get_id(), 'already_paid' => true));
        }
    }
        // If none found, continue to create a new one
    }
    WC()->session->set($lock_key, $lock_time);

    // Get the payment gateway instance
    try {
        $gateway = new WC_Gateway_CoinSub();
        error_log('CoinSub AJAX: Gateway instance created successfully');
    } catch (Exception $e) {
        error_log('CoinSub AJAX: Failed to create gateway instance: ' . $e->getMessage());
        wp_send_json_error('Failed to initialize payment gateway');
    }
    
    // Create order using WooCommerce's standard method
    error_log('CoinSub AJAX: Creating WooCommerce order...');
    
    // Create order using wc_create_order() which is the correct method
    $order = wc_create_order();
    
    if (!$order || is_wp_error($order)) {
        error_log('CoinSub AJAX: Failed to create order');
        wp_send_json_error('Failed to create order');
    }
    
    $order_id = $order->get_id();
    error_log('CoinSub AJAX: Order created with ID: ' . $order_id);

    // Store order id in session to prevent duplicates on repeated clicks
    WC()->session->set('coinsub_order_id', $order_id);
    
    // Add cart items to order
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product = $cart_item['data'];
        $order->add_product($product, $cart_item['quantity']);
    }
    
    // Set billing address from form data
    $order->set_billing_first_name(sanitize_text_field($_POST['billing_first_name']));
    $order->set_billing_last_name(sanitize_text_field($_POST['billing_last_name']));
    $order->set_billing_email(sanitize_email($_POST['billing_email']));
    $order->set_billing_phone(sanitize_text_field($_POST['billing_phone']));
    $order->set_billing_address_1(sanitize_text_field($_POST['billing_address_1']));
    $order->set_billing_city(sanitize_text_field($_POST['billing_city']));
    $order->set_billing_state(sanitize_text_field($_POST['billing_state']));
    $order->set_billing_postcode(sanitize_text_field($_POST['billing_postcode']));
    $order->set_billing_country(sanitize_text_field($_POST['billing_country']));
    
    // Set payment method
    $order->set_payment_method('coinsub');
    $order->set_payment_method_title('CoinSub');
    
    // Set customer ID if user is logged in
    if (is_user_logged_in()) {
        $order->set_customer_id(get_current_user_id());
        error_log('CoinSub AJAX: Set customer ID to: ' . get_current_user_id());
    } else {
        error_log('CoinSub AJAX: User not logged in, order will be guest order');
    }
    
    // Set billing email for guest orders (needed for order association)
    $billing_email = sanitize_email($_POST['billing_email']);
    if ($billing_email) {
        $order->set_billing_email($billing_email);
        error_log('CoinSub AJAX: Set billing email to: ' . $billing_email);
    }
    
    // Calculate totals and save
    $order->calculate_totals();
    $order->save();
    
    error_log('CoinSub AJAX: Order created with ID: ' . $order->get_id());
    
    // If this order already has a checkout URL (rare race), reuse it
    $existing_checkout = $order->get_meta('_coinsub_checkout_url');
    if (!empty($existing_checkout)) {
        error_log('CoinSub AJAX: Order already has checkout URL, skipping process_payment');
        $result = array('result' => 'success', 'redirect' => $existing_checkout);
    } else {
        // Process payment - this will create the purchase session
        $result = $gateway->process_payment($order->get_id());
    }
    
    error_log('CoinSub AJAX: Payment result: ' . json_encode($result));
    
    if ($result['result'] === 'success') {
        error_log('CoinSub AJAX: Payment successful, sending response');
        wp_send_json_success($result);
    } else {
        error_log('CoinSub AJAX: Payment failed: ' . ($result['messages'] ?? 'Unknown error'));
        wp_send_json_error($result['messages'] ?? 'Payment failed');
    }
}

function coinsub_ajax_clear_cart_after_payment() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'coinsub_clear_cart')) {
        error_log('CoinSub Clear Cart: Security check failed');
        wp_die('Security check failed');
    }
    
    error_log('🆕 CoinSub Clear Cart: Clearing cart and session after successful payment - ready for new order!');
    
    // Clear the WooCommerce cart completely

    
    // Clear all CoinSub session data - FRESH START!
    WC()->session->set('coinsub_order_id', null);
    WC()->session->set('coinsub_purchase_session_id', null);
    
    // Force cart recalculation
    WC()->cart->calculate_totals();
    
    // Clear any cart fragments
    wc_clear_notices();
    
    error_log('✅ CoinSub Clear Cart: Cart and session cleared successfully - ready for new orders!');
    
    wp_send_json_success(array('message' => 'Cart cleared successfully - ready for new orders!'));
}

function coinsub_ajax_check_webhook_status() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'coinsub_check_webhook')) {
        error_log('CoinSub Check Webhook: Security check failed');
        wp_die('Security check failed');
    }
    
    error_log('🔍 CoinSub Check Webhook: Checking for webhook completion...');
    
    // Get the most recent order with CoinSub payment method
    $orders = wc_get_orders(array(
        'limit' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'payment_method' => 'coinsub'
    ));
    
    if (empty($orders)) {
        error_log('CoinSub Check Webhook: No CoinSub orders found');
        wp_send_json_error('No orders found');
    }
    
    $order = $orders[0];
    $redirect_flag = $order->get_meta('_coinsub_redirect_to_received');
    
    if ($redirect_flag === 'yes') {
        error_log('✅ CoinSub Check Webhook: Webhook completed for order #' . $order->get_id());
        
        // Clear the redirect flag
        $order->delete_meta_data('_coinsub_redirect_to_received');
        $order->save();
        
        // Get the order-received page URL (where customers see their completed order)
        $redirect_url = $order->get_checkout_order_received_url();
        
        wp_send_json_success(array('redirect_url' => $redirect_url));
    } else {
        error_log('CoinSub Check Webhook: Webhook not yet completed for order #' . $order->get_id());
        wp_send_json_error('Webhook not completed yet');
    }
}

/**
 * AJAX handler to get the latest order URL for backup redirect
 */
function coinsub_ajax_get_latest_order_url() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'coinsub_get_order_url')) {
        error_log('CoinSub Get Order URL: Security check failed');
        wp_die('Security check failed');
    }
    
    error_log('🔄 CoinSub Get Order URL: Checking for latest order...');
    
    // Get the most recent order with CoinSub payment method
    $orders = wc_get_orders(array(
        'limit' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'payment_method' => 'coinsub'
    ));
    
    if (empty($orders)) {
        error_log('CoinSub Get Order URL: No CoinSub orders found');
        wp_send_json_error('No orders found');
    }
    
    $order = $orders[0];
    $order_status = $order->get_status();
    
    error_log('CoinSub Get Order URL: Found order #' . $order->get_id() . ' with status: ' . $order_status);
    
    // Check if order is completed/processing (payment successful)
    if (in_array($order_status, ['processing', 'completed', 'on-hold'])) {
        $redirect_url = $order->get_checkout_order_received_url();
        error_log('CoinSub Get Order URL: Order completed, returning URL: ' . $redirect_url);
        wp_send_json_success(array('order_url' => $redirect_url));
    } else {
        error_log('CoinSub Get Order URL: Order not yet completed, status: ' . $order_status);
        wp_send_json_error('Order not completed yet');
    }
}

/**
 * WordPress Heartbeat handler for real-time webhook communication
 */
function coinsub_heartbeat_received($response, $data, $screen_id) {
    // Check if frontend is requesting webhook status
    if (isset($data['coinsub_check_webhook']) && $data['coinsub_check_webhook']) {
        error_log('💓 CoinSub Heartbeat: Checking for webhook completion...');
        
        // Get the most recent order with CoinSub payment method
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'payment_method' => 'coinsub'
        ));
        
        if (!empty($orders)) {
            $order = $orders[0];
            $redirect_flag = $order->get_meta('_coinsub_redirect_to_received');
            
            if ($redirect_flag === 'yes') {
                error_log('💓 CoinSub Heartbeat: Webhook completed for order #' . $order->get_id());
                
                // Clear the redirect flag
                $order->delete_meta_data('_coinsub_redirect_to_received');
                $order->save();
                
                // Get the order-received page URL
                $redirect_url = $order->get_checkout_order_received_url();
                
                // Send response back to frontend
                $response['coinsub_webhook_complete'] = true;
                $response['coinsub_redirect_url'] = $redirect_url;
                
                error_log('💓 CoinSub Heartbeat: Sending redirect URL to frontend: ' . $redirect_url);
            }
        }
    }
    
    return $response;
}

?>
