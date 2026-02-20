<?php
/**
 * Uninstall CoinSub Commerce Plugin
 * 
 * This file is executed when the plugin is deleted from WordPress.
 * It cleans up all plugin data including options, pages, and transients.
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clean up all plugin data
 */
function coinsub_uninstall_plugin() {
    global $wpdb;
    
    // Delete dedicated checkout page
    $checkout_page_id = get_option('coinsub_checkout_page_id');
    if ($checkout_page_id) {
        wp_delete_post($checkout_page_id, true); // true = force delete (bypass trash)
        delete_option('coinsub_checkout_page_id');
    }
    
    // Delete plugin options
    delete_option('woocommerce_coinsub_settings');
    delete_option('coinsub_webhook_secret');
    delete_option('coinsub_checkout_page_id');
    
    // Delete transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_coinsub_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_coinsub_%'");
    
    // Delete session data (if using custom session table)
    // Note: WooCommerce session data is usually cleaned automatically
    
    // Optional: Clean up order meta data
    // Uncomment the lines below if you want to remove all CoinSub meta data from orders
    // WARNING: This will remove payment information from orders permanently
    /*
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_coinsub_%'");
    */
    
    // Clean up any scheduled events
    wp_clear_scheduled_hook('coinsub_cleanup_expired_sessions');
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Run uninstall
coinsub_uninstall_plugin();
