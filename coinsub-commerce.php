<?php
/**
 * Plugin Name: CoinSub Commerce
 * Plugin URI: https://coinsub.io
 * Description: Accept cryptocurrency payments with CoinSub. Supports shipping, tax, and flexible payment configurations.
 * Version: 1.0.0
 * Author: CoinSub
 * Author URI: https://coinsub.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coinsub-commerce
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
    echo '<div class="error"><p><strong>CoinSub Commerce</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Initialize the plugin
 */
function coinsub_commerce_init() {
    // Load text domain
    load_plugin_textdomain('coinsub-commerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Include required files
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-api-client.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-payment-gateway.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-webhook-handler.php';
    
    // Initialize webhook handler
    new CoinSub_Webhook_Handler();
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
 * Declare HPOS compatibility on init
 */
function coinsub_commerce_declare_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

/**
 * Add CoinSub payment gateway to WooCommerce
 */
function coinsub_add_payment_gateway($gateways) {
    $gateways[] = 'CoinSub_Payment_Gateway';
    return $gateways;
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
add_filter('woocommerce_payment_gateways', 'coinsub_add_payment_gateway');
add_action('before_woocommerce_init', 'coinsub_commerce_declare_hpos_compatibility');
add_action('init', 'coinsub_commerce_declare_compatibility');

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
