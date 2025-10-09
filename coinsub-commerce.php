<?php
/**
 * Plugin Name: CoinSub
 * Plugin URI: https://coinsub.io
 * Description: Accept cryptocurrency payments with CoinSub. Simple crypto payments for WooCommerce.
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
    
    // Register custom order status
    add_action('init', 'coinsub_register_order_status');
    add_filter('wc_order_statuses', 'coinsub_add_order_status');
    
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
        error_log('🔧 CoinSub - ✅ Gateway IS in available list! CoinSub should be visible!');
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

/**
 * Register custom order status for CoinSub
 */
function coinsub_register_order_status() {
    register_post_status('wc-pending-coinsub', array(
        'label'                     => _x('Pending Crypto Payment', 'Order status', 'coinsub'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Pending Crypto Payment <span class="count">(%s)</span>', 'Pending Crypto Payment <span class="count">(%s)</span>', 'coinsub')
    ));
}

/**
 * Add custom order status to WooCommerce order statuses
 */
function coinsub_add_order_status($order_statuses) {
    $new_order_statuses = array();
    
    // Add after pending
    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        
        if ('wc-pending' === $key) {
            $new_order_statuses['wc-pending-coinsub'] = _x('Pending Crypto Payment', 'Order status', 'coinsub');
        }
    }
    
    return $new_order_statuses;
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
?>
