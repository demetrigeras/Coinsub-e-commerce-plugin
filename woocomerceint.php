<?php
/**
 * Plugin Name: CoinSub Commerce Integration
 * Plugin URI: https://coinsub.io
 * Description: Integrate CoinSub crypto payments with WooCommerce. Accept cryptocurrency payments for your WooCommerce store.
 * Version: 1.0.0
 * Author: CoinSub
 * Author URI: https://coinsub.io
 * Text Domain: coinsub-commerce
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 *
 * @package CoinSubCommerce
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

/**
 * Main CoinSub Commerce Integration Class
 */
class CoinSubCommerceIntegration {
    
    /**
     * Single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main Instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Load text domain
        load_plugin_textdomain('coinsub-commerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Include required files
        $this->includes();
        
        // Initialize hooks
        $this->init_hooks();
    }
    
    /**
     * Include required files
     */
    private function includes() {
        require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-api-client.php';
        require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-payment-gateway.php';
        require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-webhook-handler.php';
        require_once COINSUB_PLUGIN_DIR . 'includes/class-coinsub-order-manager.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateway'));
        
        // Admin menu removed - all settings are in payment gateway
        
        // Add settings link
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        
        // Initialize webhook handler
        new CoinSub_Webhook_Handler();
        
        // Initialize order manager
        new CoinSub_Order_Manager();
    }
    
    /**
     * Add CoinSub payment gateway
     */
    public function add_payment_gateway($gateways) {
        $gateways[] = 'CoinSub_Payment_Gateway';
        return $gateways;
    }
    
    // Admin menu removed - all settings are in payment gateway
    
    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=coinsub') . '">' . __('Settings', 'coinsub-commerce') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . __('CoinSub Commerce Integration', 'coinsub-commerce') . '</strong> ' . __('requires WooCommerce to be installed and active.', 'coinsub-commerce') . '</p></div>';
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create webhook endpoint
        $this->create_webhook_endpoint();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create webhook endpoint
     */
    private function create_webhook_endpoint() {
        add_rewrite_rule(
            '^coinsub-webhook/?$',
            'index.php?coinsub_webhook=1',
            'top'
        );
    }
}

/**
 * Returns the main instance of CoinSubCommerceIntegration
 */
function coinsub_commerce() {
    return CoinSubCommerceIntegration::instance();
}

// Initialize the plugin
coinsub_commerce();
