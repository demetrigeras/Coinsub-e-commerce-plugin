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
    
    // Initialize components
    new CoinSub_Webhook_Handler();
    new CoinSub_Order_Manager();
    
    // Initialize admin log viewer (only in admin)
    if (is_admin()) {
        new CoinSub_Admin_Logs();
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
    $methods[] = 'WC_Gateway_CoinSub';
    error_log('🔧 CoinSub - Gateway added to methods array. Total gateways: ' . count($methods));
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
