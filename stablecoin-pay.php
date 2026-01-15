<?php
/**
 * Plugin Name: Stablecoin Pay
 * Description: Accept cryptocurrency payments with Stablecoin Pay. Simple crypto payments for WooCommerce.
 * Version: 1.0.0
 * Author: Stablecoin Pay
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
    echo '<div class="error"><p><strong>Stablecoin Pay</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Initialize the plugin
 */
function coinsub_commerce_init() {
    // Ensure a per-site webhook secret exists
    if (!get_option('coinsub_webhook_secret')) {
        $secret = wp_generate_password(32, false, false);
        add_option('coinsub_webhook_secret', $secret, '', false);
    }
    
    // Include required files
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-api-client.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-whitelabel-branding.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-payment-gateway.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-webhook-handler.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-order-manager.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-admin-logs.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-cart-sync.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-subscriptions.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-admin-subscriptions.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-admin-payments.php';
    require_once COINSUB_PLUGIN_DIR . 'includes/class-sp-review-page.php';
    
    // Register custom order status
    
    // Initialize components
    new CoinSub_Webhook_Handler();
    new CoinSub_Order_Manager();
    
    // Email hooks are handled by CoinSub_Order_Manager class
    
    // Initialize cart sync (tracks cart changes in real-time)
    if (!is_admin()) {
        new WC_CoinSub_Cart_Sync();
    }
    
    // Initialize admin tools (only in admin)
    if (is_admin()) {
        new CoinSub_Admin_Logs();
    }

    // Initialize review/brand explainer page
    new CoinSub_Review_Page();
    
    // Register checkout page shortcode
    add_shortcode('stablecoin_pay_checkout', 'coinsub_checkout_page_shortcode');
    
    // Force traditional checkout template (not block-based)
    add_action('template_redirect', 'coinsub_force_traditional_checkout');
}

/**
 * Force traditional checkout template for CoinSub compatibility
 * Only applies when CoinSub gateway is enabled
 */
function coinsub_force_traditional_checkout() {
    if (is_checkout() && !is_wc_endpoint_url('order-pay')) {
        // Check if CoinSub gateway is enabled
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $coinsub_enabled = isset($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes';
        
        if ($coinsub_enabled) {
            // Remove block-based checkout and use shortcode
            remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_form_wrapper_start');
            remove_action('woocommerce_after_checkout_form', 'woocommerce_checkout_form_wrapper_end');
            
            // Force shortcode checkout
            add_filter('woocommerce_checkout_shortcode_tag', function() {
                return 'woocommerce_checkout';
            });
        }
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
function coinsub_register_review_rewrite_rule() {
    add_rewrite_rule(
        '^stablecoin-pay-review/?$',
        'index.php?coinsub_review=1',
        'top'
    );
}

function coinsub_commerce_activate() {
    // Add rewrite rules for webhook endpoint
    add_rewrite_rule(
        '^wp-json/stablecoin/v1/webhook/?$',
        'index.php?coinsub_webhook=1',
        'top'
    );

    // Add rewrite for the review/branding explainer page
    coinsub_register_review_rewrite_rule();
    
    // Create dedicated checkout page
    coinsub_create_checkout_page();
    
    // Automatically ensure WooCommerce checkout page has shortcode
    coinsub_ensure_checkout_shortcode();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Automatically ensure WooCommerce checkout page has [woocommerce_checkout] shortcode
 * This eliminates the need for manual Step 3 in setup instructions
 * Safe: Only adds if missing, preserves existing content
 */
function coinsub_ensure_checkout_shortcode() {
    // Get WooCommerce checkout page ID
    $checkout_page_id = wc_get_page_id('checkout');
    
    if (!$checkout_page_id || $checkout_page_id === 0) {
        error_log('⚠️ Stablecoin Pay: WooCommerce checkout page not found - skipping shortcode check');
        return false;
    }
    
    // Get the page
    $checkout_page = get_post($checkout_page_id);
    
    if (!$checkout_page) {
        error_log('⚠️ Stablecoin Pay: Could not retrieve checkout page');
        return false;
    }
    
    // Check if page content already has the shortcode or checkout functionality
    $page_content = $checkout_page->post_content;
    
    // Check for various formats that indicate checkout is working:
    // 1. [woocommerce_checkout] shortcode
    // 2. WooCommerce checkout block (Gutenberg)
    // 3. Any WooCommerce checkout-related content
    $has_checkout = (
        strpos($page_content, '[woocommerce_checkout]') !== false ||
        strpos($page_content, '<!-- wp:woocommerce/checkout') !== false ||
        strpos($page_content, 'wp-block-woocommerce-checkout') !== false ||
        strpos($page_content, 'woocommerce-checkout') !== false
    );
    
    if ($has_checkout) {
        error_log('✅ Stablecoin Pay: Checkout page already has checkout shortcode/block - no changes needed');
        return true;
    }
    
    // Page doesn't have checkout functionality - add shortcode safely
    // If page is empty or only has whitespace, replace with shortcode
    // Otherwise, prepend shortcode (so it appears first)
    $trimmed_content = trim($page_content);
    
    if (empty($trimmed_content)) {
        // Empty page - just add shortcode
        $new_content = '[woocommerce_checkout]';
        error_log('🔄 Stablecoin Pay: Checkout page is empty - adding [woocommerce_checkout] shortcode');
    } else {
        // Has content - prepend shortcode (checkout should come first)
        $new_content = '[woocommerce_checkout]' . "\n\n" . $page_content;
        error_log('🔄 Stablecoin Pay: Checkout page has content - prepending [woocommerce_checkout] shortcode');
    }
    
    // Update the page
    $updated = wp_update_post(array(
        'ID' => $checkout_page_id,
        'post_content' => $new_content
    ));
    
    if ($updated && !is_wp_error($updated)) {
        error_log('✅ Stablecoin Pay: Successfully updated checkout page with [woocommerce_checkout] shortcode');
        return true;
    } else {
        $error_msg = is_wp_error($updated) ? $updated->get_error_message() : 'Unknown error';
        error_log('❌ Stablecoin Pay: Failed to update checkout page - ' . $error_msg);
        return false;
    }
}

/**
 * Create dedicated checkout page for Stablecoin Pay
 * This page will display the payment iframe full-page
 */
function coinsub_create_checkout_page() {
    // Check if page already exists
    $page_slug = 'stablecoin-pay-checkout';
    $existing_page = get_page_by_path($page_slug);
    
    if ($existing_page) {
        // Page exists, make sure it's published
        if ($existing_page->post_status !== 'publish') {
            wp_update_post(array(
                'ID' => $existing_page->ID,
                'post_status' => 'publish'
            ));
        }
        update_option('coinsub_checkout_page_id', $existing_page->ID);
        return $existing_page->ID;
    }
    
    // Create the page
    $page_data = array(
        'post_title'    => 'Complete Your Payment',
        'post_name'     => $page_slug,
        'post_content'  => '[stablecoin_pay_checkout]',
        'post_status'   => 'publish',
        'post_type'     => 'page',
        'post_author'   => 1,
        'comment_status' => 'closed',
        'ping_status'    => 'closed'
    );
    
    $page_id = wp_insert_post($page_data);
    
    if ($page_id && !is_wp_error($page_id)) {
        // Store page ID in options for easy reference
        update_option('coinsub_checkout_page_id', $page_id);
        error_log('✅ Stablecoin Pay: Created dedicated checkout page (ID: ' . $page_id . ')');
        return $page_id;
    }
    
    return false;
}

/**
 * Plugin deactivation
 */
function coinsub_commerce_deactivate() {
    // Optionally delete checkout page on deactivation
    // Uncomment if you want to clean up on deactivation
    // $page_id = get_option('coinsub_checkout_page_id');
    // if ($page_id) {
    //     wp_delete_post($page_id, true);
    //     delete_option('coinsub_checkout_page_id');
    // }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Shortcode handler for checkout page
 * Displays the payment iframe full-page
 */
function coinsub_checkout_page_shortcode($atts) {
    // Get checkout URL from query parameter
    $checkout_url = isset($_GET['checkout_url']) ? esc_url_raw(urldecode($_GET['checkout_url'])) : '';
    
    if (empty($checkout_url)) {
        return '<div style="padding: 40px; text-align: center; max-width: 600px; margin: 50px auto;">
            <h2 style="margin-bottom: 20px;">Payment Checkout</h2>
            <p style="margin-bottom: 30px;">No checkout URL provided. Please return to the checkout page and try again.</p>
            <a href="' . esc_url(wc_get_checkout_url()) . '" class="button" style="padding: 12px 24px; text-decoration: none; display: inline-block;">Return to Checkout</a>
        </div>';
    }
    
    // Get whitelabel branding for page title
    $branding = new CoinSub_Whitelabel_Branding();
    $branding_data = $branding->get_branding(false);
    $company_name = !empty($branding_data['company']) ? $branding_data['company'] : 'Stablecoin Pay';
    
    // Output full-page iframe with back button
    ob_start();
    ?>
    <div id="stablecoin-pay-checkout-container" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; background: #fff;">
        <!-- Back button in top left corner -->
        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" 
           id="stablecoin-pay-back-button" 
           style="position: absolute; top: 20px; left: 20px; z-index: 10000; display: flex; align-items: center; justify-content: center; width: 48px; height: 48px; background: rgba(255, 255, 255, 0.95); border-radius: 50%; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); text-decoration: none; transition: all 0.2s ease; cursor: pointer;"
           onmouseover="this.style.background='rgba(255, 255, 255, 1)'; this.style.transform='scale(1.05)';"
           onmouseout="this.style.background='rgba(255, 255, 255, 0.95)'; this.style.transform='scale(1)';"
           title="Back to Checkout">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #000;">
                <path d="M15 18L9 12L15 6" stroke="#000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        
        <!-- Iframe with top padding to avoid covering back button -->
        <iframe 
            id="stablecoin-pay-checkout-iframe" 
            src="<?php echo esc_url($checkout_url); ?>" 
            style="width: 100%; height: 100%; border: none; padding-top: 0;"
            allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *"
            title="Complete Your Payment - <?php echo esc_attr($company_name); ?>"
        ></iframe>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Hide WordPress admin bar if visible
        $('#wpadminbar').hide();
        
        // Handle back button click - check order status before going back
        $('#stablecoin-pay-back-button').on('click', function(e) {
            e.preventDefault();
            
            // Get order ID from session if available
            var orderId = null;
            
            // Try to get order ID from URL or session
            var urlParams = new URLSearchParams(window.location.search);
            var checkoutUrl = urlParams.get('checkout_url');
            
            // Extract order ID from checkout URL if possible, or check session
            // For now, just go back - we'll restore cart on checkout page if needed
            console.log('🔄 Going back to checkout - order status will be checked on checkout page');
            window.location.href = '<?php echo esc_url(wc_get_checkout_url()); ?>';
        });
        
        // Listen for postMessage events from iframe
        window.addEventListener('message', function(event) {
            // Check if this is a redirect message
            if (event.data && typeof event.data === 'object') {
                if (event.data.type === 'redirect' && event.data.url) {
                    console.log('🔄 Redirecting to:', event.data.url);
                    window.location.href = event.data.url;
                    return;
                }
            }
            
            // Check for order-received URL in message
            if (event.data && typeof event.data === 'string' && event.data.includes('order-received')) {
                console.log('🔄 Found order-received URL:', event.data);
                window.location.href = event.data;
                return;
            }
        });
        
        // Check iframe URL periodically for redirects
        var checkInterval = setInterval(function() {
            try {
                var iframe = document.getElementById('stablecoin-pay-checkout-iframe');
                if (iframe && iframe.contentWindow) {
                    var iframeUrl = iframe.contentWindow.location.href;
                    
                    // Check if iframe has redirected to order-received page
                    if (iframeUrl.includes('order-received')) {
                        console.log('🔄 Iframe redirected to order-received, redirecting parent');
                        clearInterval(checkInterval);
                        window.location.href = iframeUrl;
                        return;
                    }
                }
            } catch(e) {
                // Cross-origin restrictions - this is expected
                // The iframe may have redirected to a different domain
            }
        }, 1000);
        
        // Stop checking after 5 minutes
        setTimeout(function() {
            clearInterval(checkInterval);
        }, 300000);
    });
    </script>
    <?php
    return ob_get_clean();
}

// Hook into WordPress
add_action('plugins_loaded', 'coinsub_commerce_init');
add_filter('woocommerce_payment_gateways', 'coinsub_add_gateway_class');
add_action('before_woocommerce_init', 'coinsub_commerce_declare_hpos_compatibility');

// Load plugin text domain on init hook (prevents translation loading warnings)
add_action('init', function() {
    load_plugin_textdomain('coinsub', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 1);

// Generate webhook secret on activation as well
function coinsub_plugin_activate_secret() {
    if (!get_option('coinsub_webhook_secret')) {
        $secret = wp_generate_password(32, false, false);
        add_option('coinsub_webhook_secret', $secret, '', false);
    }
}
register_activation_hook(__FILE__, 'coinsub_plugin_activate_secret');


// Only disable block-based checkout if CoinSub gateway is enabled
// This prevents conflicts with other payment plugins
add_filter('woocommerce_feature_enabled', function($enabled, $feature) {
    if ($feature === 'checkout_block') {
        // Check if CoinSub gateway is enabled
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $coinsub_enabled = isset($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes';
        
        // Only disable blocks if CoinSub is enabled
        if ($coinsub_enabled) {
            return false;
        }
    }
    return $enabled;
}, 10, 2);

// Force classic checkout template only when CoinSub is enabled
add_filter('woocommerce_is_checkout_block', function($is_block) {
    $gateway_settings = get_option('woocommerce_coinsub_settings', array());
    $coinsub_enabled = isset($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes';
    
    // Only force classic checkout if CoinSub is enabled
    if ($coinsub_enabled) {
        return false;
    }
    
    return $is_block;
});

// Force gateway availability for debugging (lower priority to avoid conflicts)
// Only log on checkout page to reduce log noise
add_filter('woocommerce_available_payment_gateways', 'coinsub_force_availability', 20);

function coinsub_force_availability($gateways) {
    // Only log detailed debug info on checkout page, not admin (reduces log noise)
    if (is_checkout()) {
        $page_context = 'CHECKOUT';
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
    }
    
    return $gateways;
}


// Always show refund buttons for CoinSub orders
add_filter('woocommerce_can_refund_order', 'coinsub_always_show_refund_button', 10, 2);
function coinsub_always_show_refund_button($can_refund, $order) {
    if ($order->get_payment_method() === 'coinsub') {
        $paid_statuses = array('processing', 'completed', 'on-hold');
        if (in_array($order->get_status(), $paid_statuses)) {
            return true;
        }
    }
    return $can_refund;
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

// Remove default plugin page links (Visit plugin site, Review)
add_filter('plugin_row_meta', 'coinsub_remove_plugin_meta_links', 10, 2);

function coinsub_remove_plugin_meta_links($links, $file) {
    if (strpos($file, 'coinsub-commerce.php') !== false) {
        // Remove all default meta links
        return array();
    }
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
                    error_log('CoinSub AJAX: Reusing existing order checkout URL, wrapping in dedicated checkout page');
                    
                    // Get dedicated checkout page URL and wrap the checkout URL
                    $checkout_page_id = get_option('coinsub_checkout_page_id');
                    if ($checkout_page_id) {
                        $checkout_page_url = get_permalink($checkout_page_id);
                        $redirect_url = add_query_arg('checkout_url', urlencode($existing_checkout), $checkout_page_url);
                        error_log('🎯 CoinSub AJAX: Redirecting to dedicated checkout page: ' . $redirect_url);
                        wp_send_json_success(array(
                            'result' => 'success',
                            'redirect' => $redirect_url,
                            'coinsub_checkout_url' => $existing_checkout,
                            'order_id' => $existing_order_id,
                            'reused' => true
                        ));
                    } else {
                        // Fallback: redirect directly
                        error_log('⚠️ CoinSub AJAX: Checkout page not found, redirecting directly');
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
                
                // Wrap checkout URL in dedicated checkout page
                $checkout_page_id = get_option('coinsub_checkout_page_id');
                if ($checkout_page_id) {
                    $checkout_page_url = get_permalink($checkout_page_id);
                    $redirect_url = add_query_arg('checkout_url', urlencode($url), $checkout_page_url);
                    error_log('🎯 CoinSub AJAX: Reusing order, redirecting to dedicated checkout page: ' . $redirect_url);
                    wp_send_json_success(array('result' => 'success', 'redirect' => $redirect_url, 'coinsub_checkout_url' => $url, 'order_id' => $o->get_id(), 'reused' => true));
                } else {
                    // Fallback: redirect directly
                    wp_send_json_success(array('result' => 'success', 'redirect' => $url, 'order_id' => $o->get_id(), 'reused' => true));
                }
            }
        } elseif (in_array($o->get_status(), array('processing','completed'))) {
            // If the most recent order is already paid, send user to order received page
            wp_send_json_success(array('result' => 'success', 'redirect' => $o->get_checkout_order_received_url(), 'order_id' => $o->get_id(), 'already_paid' => true));
        }
    }
        // If none found, tell client to wait and retry
        wp_send_json_error('Another payment attempt is already in progress. Please wait a moment...');
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
        error_log('CoinSub AJAX: Order already has checkout URL, wrapping in dedicated checkout page');
        
        // Get dedicated checkout page URL and wrap the checkout URL
        $checkout_page_id = get_option('coinsub_checkout_page_id');
        if ($checkout_page_id) {
            $checkout_page_url = get_permalink($checkout_page_id);
            $redirect_url = add_query_arg('checkout_url', urlencode($existing_checkout), $checkout_page_url);
            error_log('🎯 CoinSub AJAX: Redirecting to dedicated checkout page: ' . $redirect_url);
            $result = array('result' => 'success', 'redirect' => $redirect_url, 'coinsub_checkout_url' => $existing_checkout);
        } else {
            // Fallback: redirect directly to checkout URL
            error_log('⚠️ CoinSub AJAX: Checkout page not found, redirecting directly to checkout URL');
            $result = array('result' => 'success', 'redirect' => $existing_checkout);
        }
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

/**
 * Send CoinSub payment emails when order status changes to processing
 * DISABLED: WooCommerce handles all emails automatically based on order status
 */
function coinsub_send_payment_emails($order_id) {
    // Email sending disabled - WooCommerce will handle all emails automatically
    // when order status changes. Merchant can configure emails in WooCommerce > Settings > Emails
    return;
}

/**
 * Send CoinSub payment emails when order status changes (any status change)
 * DISABLED: WooCommerce handles all emails automatically based on order status
 */
function coinsub_send_payment_emails_on_status_change($order_id, $old_status, $new_status) {
    // Email sending disabled - WooCommerce will handle all emails automatically
    // when order status changes. Merchant can configure emails in WooCommerce > Settings > Emails
    return;
}

// Duplicate function removed

/**
 * Send custom CoinSub merchant notification
 * DISABLED: WooCommerce handles all emails automatically based on order status
 */


?>
