<?php
/**
 * Plugin Name: CoinSub Shipping Method
 * Plugin URI: https://coinsub.io
 * Description: Custom shipping method for CoinSub Commerce integration
 * Version: 1.0.0
 * Author: CoinSub
 * Author URI: https://coinsub.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coinsub-shipping
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

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'coinsub_shipping_woocommerce_missing_notice');
    return;
}

/**
 * WooCommerce missing notice
 */
function coinsub_shipping_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>CoinSub Shipping Method</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Initialize the shipping method
 */
function coinsub_shipping_method_init() {
    // Load text domain
    load_plugin_textdomain('coinsub-shipping', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Include the shipping method class
    require_once plugin_dir_path(__FILE__) . 'includes/class-coinsub-shipping-method.php';
}

/**
 * Add CoinSub shipping method to WooCommerce
 */
function coinsub_add_shipping_method($methods) {
    $methods['coinsub_shipping'] = 'WC_CoinSub_Shipping_Method';
    return $methods;
}

// Hook into WordPress
add_action('woocommerce_shipping_init', 'coinsub_shipping_method_init');
add_filter('woocommerce_shipping_methods', 'coinsub_add_shipping_method');

// Add settings link to plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'coinsub_shipping_add_settings_link');

function coinsub_shipping_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=coinsub_shipping') . '">' . __('Settings', 'coinsub-shipping') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
?>
