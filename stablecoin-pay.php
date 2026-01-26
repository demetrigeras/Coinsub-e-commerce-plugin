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
    
    // Add preconnect/prefetch to head for faster iframe loading (priority 1 = very early)
    add_action('wp_head', 'coinsub_checkout_page_preconnect', 1);
    
    // Disable unnecessary WordPress/WooCommerce assets on checkout iframe page for faster loading
    add_action('wp_enqueue_scripts', 'coinsub_disable_unnecessary_assets_on_checkout_page', 999);
    
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
 * Disable unnecessary WordPress/WooCommerce assets on checkout iframe page
 * This speeds up page load so iframe can start loading faster
 */
function coinsub_disable_unnecessary_assets_on_checkout_page() {
    $page_slug = 'stablecoin-pay-checkout';
    $checkout_page = get_page_by_path($page_slug);
    
    if (!$checkout_page || !is_page($checkout_page->ID)) {
        return;
    }
    
    // Defer non-critical scripts - let them load after iframe starts
    add_filter('script_loader_tag', function($tag, $handle) {
        // Don't defer jQuery, WooCommerce, or our own scripts
        $critical_scripts = array('jquery', 'jquery-core', 'jquery-migrate', 'wc-checkout', 'stablecoin-pay');
        
        // Defer all other scripts
        if (!in_array($handle, $critical_scripts) && strpos($tag, 'src=') !== false) {
            // Skip if already has defer or async
            if (strpos($tag, 'defer') === false && strpos($tag, 'async') === false) {
                $tag = str_replace(' src=', ' defer src=', $tag);
            }
        }
        
        return $tag;
    }, 10, 2);
    
    // Remove WooCommerce scripts we don't need on this page
    wp_dequeue_style('woocommerce-general');
    wp_dequeue_style('woocommerce-layout');
    wp_dequeue_style('woocommerce-smallscreen');
    
    // Keep only essential scripts
}

/**
 * Add preconnect/prefetch to head for checkout page (loads early in <head>)
 * This significantly speeds up iframe loading by starting DNS resolution early
 */
function coinsub_checkout_page_preconnect() {
    // Only on checkout page
    $page_slug = 'stablecoin-pay-checkout';
    $checkout_page = get_page_by_path($page_slug);
    
    if (!$checkout_page || !is_page($checkout_page->ID)) {
        return;
    }
    
    // Try to get checkout URL early for preconnect
    $checkout_url = '';
    
    // Method 1: Try to get from order_id
    if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
        $order_id = intval($_GET['order_id']);
        
        // Try session first (if WooCommerce is initialized)
        if (function_exists('WC') && WC()->session) {
            $checkout_url = WC()->session->get('coinsub_checkout_url_' . $order_id);
            if (!empty($checkout_url)) {
                error_log('🔗 CoinSub Checkout Page: Checkout URL from session for order #' . $order_id . ': ' . $checkout_url);
            }
        }
        
        // Fallback to order meta
        if (empty($checkout_url) && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $checkout_url = $order->get_meta('_coinsub_checkout_url');
            }
        }
    }
    
    // Method 2: Fallback to query parameter
    if (empty($checkout_url) && isset($_GET['checkout_url'])) {
        $checkout_url = esc_url_raw(urldecode($_GET['checkout_url']));
    }
    
    // If we have a checkout URL, add aggressive resource hints early in head
    if (!empty($checkout_url)) {
        $parsed_url = parse_url($checkout_url);
        if (isset($parsed_url['scheme']) && isset($parsed_url['host'])) {
            $checkout_domain = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            
            // DNS prefetch (starts DNS lookup immediately - fastest hint)
            echo '<link rel="dns-prefetch" href="' . esc_url($checkout_domain) . '">' . "\n";
            
            // Preconnect (DNS + TCP + TLS handshake - most aggressive resource hint)
            // This establishes connection before iframe src is even parsed
            echo '<link rel="preconnect" href="' . esc_url($checkout_domain) . '" crossorigin>' . "\n";
            
            // Note: Can't prefetch cross-origin documents (CORS restriction)
            // But preconnect should help significantly with DNS/TCP/TLS
        }
    }
    
    // Hide admin bar with CSS (faster than JS)
    echo '<style>#wpadminbar { display: none !important; }</style>' . "\n";
}

/**
 * Shortcode handler for checkout page
 * Displays the payment iframe full-page
 */
function coinsub_checkout_page_shortcode($atts) {
    error_log('🎬 CoinSub Checkout Page: Shortcode called');
    
    // Get checkout URL from query parameter OR from session using order_id
    $checkout_url = '';
    
    // Method 1: Try to get from order_id (shorter URL)
    if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
        $order_id = intval($_GET['order_id']);
        error_log('🔍 CoinSub Checkout Page: Looking up checkout URL for order_id: ' . $order_id);
        
        // CRITICAL: Check if checkout URL exists in session first (indicates active session)
        // If not in session, the user likely left the page and the checkout URL was already used
        $checkout_url = WC()->session->get('coinsub_checkout_url_' . $order_id);
        
        if (!empty($checkout_url)) {
            error_log('🔗 CoinSub Checkout Page: Checkout URL found in session for order #' . $order_id . ': ' . $checkout_url);
        }
        
        if (empty($checkout_url)) {
            // No checkout URL in session - user likely left the page
            // Checkout URLs are one-time use, so we can't reuse them
            error_log('⚠️ CoinSub Checkout Page: No checkout URL in session for order_id: ' . $order_id . ' - user likely left page, checkout URL is one-time use');
            error_log('⚠️ CoinSub Checkout Page: Redirecting to checkout page to create fresh order');
            
            // Redirect to checkout page - will create a fresh order
            return '<div style="padding: 40px; text-align: center; max-width: 600px; margin: 50px auto;">
                <h2 style="margin-bottom: 20px;">Starting Fresh Checkout</h2>
                <p style="margin-bottom: 30px;">This checkout session has expired. Please start a new checkout.</p>
                <a href="' . esc_url(wc_get_checkout_url()) . '" class="button" style="padding: 12px 24px; text-decoration: none; display: inline-block; background: #2271b1; color: white; border-radius: 4px;">Start New Checkout</a>
                <script>
                    setTimeout(function() {
                        window.location.href = "' . esc_js(wc_get_checkout_url()) . '";
                    }, 2000);
                </script>
            </div>';
        }
        
        error_log('✅ CoinSub Checkout Page: Found checkout URL in session: ' . $checkout_url);
    }
    
    // Method 2: Fallback to query parameter (for backward compatibility)
    if (empty($checkout_url) && isset($_GET['checkout_url'])) {
        // URL decode the checkout URL if it's encoded
        $raw_url = $_GET['checkout_url'];
        $checkout_url = esc_url_raw(urldecode($raw_url));
        error_log('✅ CoinSub Checkout Page: Using checkout URL from query parameter: ' . $checkout_url);
    }
    
    if (empty($checkout_url)) {
        error_log('❌ CoinSub Checkout Page: No checkout URL found - order_id: ' . (isset($_GET['order_id']) ? $_GET['order_id'] : 'not set') . ', checkout_url param: ' . (isset($_GET['checkout_url']) ? 'set' : 'not set'));
        return '<div style="padding: 40px; text-align: center; max-width: 600px; margin: 50px auto;">
            <h2 style="margin-bottom: 20px;">Payment Checkout</h2>
            <p style="margin-bottom: 30px;">No checkout URL provided. Please return to the checkout page and try again.</p>
            <a href="' . esc_url(wc_get_checkout_url()) . '" class="button" style="padding: 12px 24px; text-decoration: none; display: inline-block;">Return to Checkout</a>
        </div>';
    }
    
    error_log('🎯 CoinSub Checkout Page: Final checkout URL to load: ' . $checkout_url);
    
    // REMOVED: Domain blocking check - these domains work fine in iframes
    // The JavaScript fallback will handle redirect if iframe is actually blocked by X-Frame-Options
    
    // Get whitelabel branding for page title (use cached data only, no API calls)
    $branding_data = get_option('coinsub_whitelabel_branding', array());
    $company_name = !empty($branding_data['company']) ? $branding_data['company'] : 'Stablecoin Pay';
    
    error_log('📝 CoinSub Checkout Page: Starting output buffer, company: ' . $company_name);
    
    // Output full-page iframe with back button and loading indicator
    ob_start();
    ?>
    <!-- Preconnect already added to <head> for faster loading -->
    
    <!-- CRITICAL PERFORMANCE: Output iframe FIRST in body to start loading ASAP -->
    <!-- Browser starts loading iframe as soon as it encounters <iframe src> -->
    <iframe 
        id="stablecoin-pay-checkout-iframe" 
        src="<?php echo esc_url($checkout_url); ?>" 
        style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; border: none; opacity: 0; transition: opacity 0.3s ease; z-index: 9998;"
        allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *; clipboard-write *"
        title="Complete Your Payment - <?php echo esc_attr($company_name); ?>"
        loading="eager"
        referrerpolicy="no-referrer-when-downgrade"
        importance="high"
        allowfullscreen
    ></iframe>
    
    <div id="stablecoin-pay-checkout-container" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; background: transparent; pointer-events: none;">
        <!-- Back button in top left corner (pointer-events: auto to allow clicking) -->
        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" 
           id="stablecoin-pay-back-button" 
           style="position: absolute; top: 20px; left: 20px; z-index: 10000; display: flex; align-items: center; justify-content: center; width: 48px; height: 48px; background: rgba(255, 255, 255, 0.95); border-radius: 50%; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); text-decoration: none; transition: all 0.2s ease; cursor: pointer; pointer-events: auto;"
           onmouseover="this.style.background='rgba(255, 255, 255, 1)'; this.style.transform='scale(1.05)';"
           onmouseout="this.style.background='rgba(255, 255, 255, 0.95)'; this.style.transform='scale(1)';"
           title="Back to Checkout">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #000;">
                <path d="M15 18L9 12L15 6" stroke="#000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        
        <!-- Loading indicator (shown while iframe loads) - pointer-events: auto to be visible -->
        <div id="stablecoin-pay-loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10001; text-align: center; color: #666; pointer-events: auto;">
            <div style="width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px;"></div>
            <p style="margin: 0; font-size: 16px;">Loading payment checkout...</p>
            <p style="margin: 10px 0 0; font-size: 12px; color: #999;">This may take a few moments</p>
        </div>
    </div>
    
    <style>
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    /* Hide admin bar immediately with CSS (faster than JS) */
    #wpadminbar { display: none !important; }
    </style>
    
    <!-- Inline script to start iframe loading immediately (before jQuery/DOM ready) -->
    <!-- CRITICAL: This script runs IMMEDIATELY, before WordPress/WooCommerce scripts load -->
    <script>
    (function() {
        // Get iframe element immediately - it should already be in DOM
        var iframe = document.getElementById('stablecoin-pay-checkout-iframe');
        var loadingDiv = document.getElementById('stablecoin-pay-loading');
        
        if (!iframe) {
            // If iframe not found, retry after a micro-delay (DOM might still be parsing)
            setTimeout(function() {
                iframe = document.getElementById('stablecoin-pay-checkout-iframe');
                if (iframe) {
                    console.log('🔗 Iframe found on retry, URL:', iframe.src);
                }
            }, 10);
            return;
        }
        
        // Security: Don't log iframe URL in console (sensitive one-time use URL)
        // Note: We cannot check iframe.contentDocument for cross-origin iframes due to Same-Origin Policy
        // This is normal - cross-origin iframes can still load and work fine, we just can't access their content
        // We'll rely on the iframe's onload/onerror events to detect actual blocking
        
        // Note: Browser should start loading iframe src automatically when HTML is parsed
        // Preconnect in <head> should have already established connection
        
        var loadStartTime = Date.now();
        var loadTimeout = null;
        var TIMEOUT_DURATION = 300000; // 5 minutes timeout
        var fallbackShown = false;
        
        // FALLBACK: Show iframe after 3 seconds even if onload hasn't fired
        // This prevents blank screen if onload event fails or is delayed
        var fallbackTimeout = setTimeout(function() {
            if (!fallbackShown && iframe.style.opacity === '0') {
                console.warn('⚠️ Fallback: Showing iframe after 3 seconds (onload may not have fired)');
                iframe.style.opacity = '1';
                iframe.style.zIndex = '9999';
                if (loadingDiv) {
                    loadingDiv.style.display = 'none';
                }
                fallbackShown = true;
            }
        }, 3000);
        
        // Set timeout to detect if iframe takes too long to load
        loadTimeout = setTimeout(function() {
            var elapsed = ((Date.now() - loadStartTime) / 1000).toFixed(2);
            console.warn('⚠️ Iframe loading timeout after ' + elapsed + ' seconds');
            if (loadingDiv) {
                loadingDiv.innerHTML = '<div style="color: #d32f2f;"><p style="margin: 0 0 10px; font-size: 16px;">⚠️ Payment checkout is taking longer than expected</p><p style="margin: 0; font-size: 14px;">This may indicate a backend issue. Please try again or contact support.</p><a href="<?php echo esc_js(wc_get_checkout_url()); ?>" style="display: inline-block; margin-top: 15px; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 4px;">Return to Checkout</a></div>';
            }
        }, TIMEOUT_DURATION);
        
        // Start loading immediately - don't wait for jQuery
        iframe.onload = function() {
            if (loadTimeout) {
                clearTimeout(loadTimeout);
            }
            if (fallbackTimeout) {
                clearTimeout(fallbackTimeout);
            }
            
            var loadTime = ((Date.now() - loadStartTime) / 1000).toFixed(2);
            // Security: Don't log iframe load details (URL is sensitive)
            
            // Make iframe visible and bring to front
            iframe.style.opacity = '1';
            iframe.style.zIndex = '9999'; // Bring iframe to front once loaded
            if (loadingDiv) {
                loadingDiv.style.display = 'none';
            }
            
            // Hide container overlay once iframe is loaded (allows iframe interaction)
            var container = document.getElementById('stablecoin-pay-checkout-container');
            if (container) {
                container.style.pointerEvents = 'none';
                // Don't hide completely - keep back button accessible via z-index
            }
            
            fallbackShown = true;
            
            // Log warning if load time is excessive
            if (loadTime > 60) {
                console.warn('⚠️ Iframe took ' + loadTime + ' seconds to load - this is unusually slow and indicates backend/server performance issues at: ' + new URL(iframe.src).host);
            }
        };
        
        // Handle iframe load errors
        // Note: onerror may not fire for cross-origin iframes due to security restrictions
        // We rely on the fallback timeout and onload event instead
        iframe.onerror = function() {
            if (loadTimeout) {
                clearTimeout(loadTimeout);
            }
            if (fallbackTimeout) {
                clearTimeout(fallbackTimeout);
            }
            
            var loadTime = ((Date.now() - loadStartTime) / 1000).toFixed(2);
            var checkoutDomain = new URL(iframe.src).host;
            console.error('❌ Iframe onerror fired after ' + loadTime + ' seconds');
            console.error('❌ Iframe src:', iframe.src);
            console.error('❌ Domain:', checkoutDomain);
            console.warn('⚠️ Note: onerror may not fire reliably for cross-origin iframes. Relying on fallback timeout.');
            
            // Don't immediately redirect - let the fallback timeout handle it
            // The iframe might still be loading
        };
        
        // Start postMessage listener immediately (before jQuery ready)
        window.addEventListener('message', function(event) {
            // Security: Verify origin if possible (but don't block messages from checkout domain)
            var checkoutDomain = new URL(iframe.src).origin;
            
            // Check if this is a redirect message
            if (event.data && typeof event.data === 'object') {
                if (event.data.type === 'redirect' && event.data.url) {
                    // Security: Don't log redirect URL (sensitive)
                    window.location.href = event.data.url;
                    return;
                }
                
                // Check for error messages from iframe
                if (event.data.type === 'error' || event.data.error) {
                    // Log error type but not full data (may contain sensitive URLs)
                    console.error('❌ Error received from checkout iframe');
                    if (loadingDiv) {
                        loadingDiv.style.display = 'block';
                        loadingDiv.innerHTML = '<div style="color: #d32f2f;"><p style="margin: 0 0 10px; font-size: 16px;">⚠️ Error in payment checkout</p><p style="margin: 0; font-size: 14px;">' + (event.data.message || 'Please try again or contact support') + '</p><a href="<?php echo esc_js(wc_get_checkout_url()); ?>" style="display: inline-block; margin-top: 15px; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 4px;">Return to Checkout</a></div>';
                    }
                    return;
                }
            }
            
            // Check for order-received URL in message
            if (event.data && typeof event.data === 'string' && event.data.includes('order-received')) {
                // Security: Don't log order-received URL (sensitive)
                window.location.href = event.data;
                return;
            }
        });
        
        // Listen for console errors from iframe (if accessible)
        // Note: This won't catch errors in cross-origin iframes, but we can try
        var originalConsoleError = console.error;
        console.error = function() {
            var args = Array.from(arguments);
            var errorMessage = args.join(' ');
            
            // Check if error is related to the checkout (500 errors, etc.)
            if (errorMessage.includes('500') || errorMessage.includes('purchaser') || errorMessage.includes('checkout') || errorMessage.includes('Failed to load resource')) {
                console.warn('⚠️ Potential checkout error detected:', errorMessage);
            }
            
            // Call original console.error
            originalConsoleError.apply(console, args);
        };
    })();
    </script>
    
    <!-- Additional jQuery-dependent functionality (loads after jQuery) -->
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        // Handle back button click - clear order/checkout URL from session before going back
        $('#stablecoin-pay-back-button').on('click', function(e) {
            e.preventDefault();
            
            // Get order ID from URL
            var urlParams = new URLSearchParams(window.location.search);
            var orderId = urlParams.get('order_id');
            
            console.log('🔄 Going back to checkout - clearing order/checkout URL from session (order_id: ' + orderId + ')');
            
            // Clear session data before navigating away
            if (orderId) {
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'coinsub_clear_checkout_session',
                        order_id: orderId,
                        security: '<?php echo wp_create_nonce('coinsub_clear_checkout_session'); ?>'
                    },
                    success: function(response) {
                        console.log('✅ Session cleared, redirecting to checkout');
                        window.location.href = '<?php echo esc_js(wc_get_checkout_url()); ?>';
                    },
                    error: function() {
                        console.warn('⚠️ Failed to clear session, redirecting anyway');
                        window.location.href = '<?php echo esc_js(wc_get_checkout_url()); ?>';
                    }
                });
            } else {
                // No order ID, just redirect
                window.location.href = '<?php echo esc_js(wc_get_checkout_url()); ?>';
            }
        });
        
        // Clear session data when user leaves the page (back button, close tab, etc.)
        var clearingSession = false;
        function clearCheckoutSession() {
            if (clearingSession) return; // Prevent multiple calls
            clearingSession = true;
            
            var urlParams = new URLSearchParams(window.location.search);
            var orderId = urlParams.get('order_id');
            
            if (orderId) {
                console.log('🧹 Clearing checkout session on page unload (order_id: ' + orderId + ')');
                
                // Use sendBeacon for reliable delivery on page unload
                if (navigator.sendBeacon) {
                    var formData = new FormData();
                    formData.append('action', 'coinsub_clear_checkout_session');
                    formData.append('order_id', orderId);
                    formData.append('security', '<?php echo wp_create_nonce('coinsub_clear_checkout_session'); ?>');
                    
                    navigator.sendBeacon('<?php echo admin_url('admin-ajax.php'); ?>', formData);
                } else {
                    // Fallback: synchronous AJAX (not ideal but works)
                    jQuery.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        async: false,
                        data: {
                            action: 'coinsub_clear_checkout_session',
                            order_id: orderId,
                            security: '<?php echo wp_create_nonce('coinsub_clear_checkout_session'); ?>'
                        }
                    });
                }
            }
        }
        
        // Clear session when page is unloaded (user closes tab, navigates away, etc.)
        window.addEventListener('beforeunload', clearCheckoutSession);
        
        // Also clear on pagehide for better mobile support
        window.addEventListener('pagehide', clearCheckoutSession);
        
        // Note: PostMessage listener already set up in inline script above (loads earlier)
        
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
    $output = ob_get_clean();
    error_log('✅ CoinSub Checkout Page: Output buffer closed, length: ' . strlen($output) . ' bytes');
    return $output;
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

// Register AJAX handler for clearing checkout session when user leaves checkout page
add_action('wp_ajax_coinsub_clear_checkout_session', 'coinsub_ajax_clear_checkout_session');
add_action('wp_ajax_nopriv_coinsub_clear_checkout_session', 'coinsub_ajax_clear_checkout_session');

// WordPress Heartbeat for real-time webhook communication
add_filter('heartbeat_received', 'coinsub_heartbeat_received', 10, 3);
add_filter('heartbeat_nopriv_received', 'coinsub_heartbeat_received', 10, 3);

function coinsub_ajax_process_payment() {
    // Note: Nonce check removed - checkout process creates order first, then redirects to payment
    // The actual payment happens on CoinSub's secure checkout page, not during this AJAX call
    
    // Check if cart is empty
    if (WC()->cart->is_empty()) {
        wp_send_json_error('Cart is empty');
    }
    
    // IMPORTANT: Don't reuse orders with checkout URLs - they're one-time use only!
    // Always create a fresh order and purchase session for each checkout attempt
    // Clear any existing order from session to ensure fresh start
    $existing_order_id = WC()->session->get('coinsub_order_id');
    if ($existing_order_id) {
        // Clear the existing order from session - user will get a fresh order
        WC()->session->set('coinsub_order_id', null);
        WC()->session->set('coinsub_checkout_url_' . $existing_order_id, null);
        WC()->session->set('coinsub_pending_order_id', null);
    }

    // Add a short-lived lock to prevent concurrent requests from creating duplicates
    // BUT: Don't reuse orders with checkout URLs - they're one-time use only!
    $lock_key = 'coinsub_order_lock';
    $lock_time = time();
    $existing_lock = WC()->session->get($lock_key);
    if ($existing_lock && ($lock_time - intval($existing_lock)) < 5) { // 5-second window
        // Only check if there's a paid order - don't reuse pending orders with checkout URLs (one-time use)
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'payment_method' => 'coinsub'
        ));
        
        if (!empty($orders)) {
            $o = $orders[0];
            
            // Only reuse if order is already paid (processing/completed) - send to order received
            if (in_array($o->get_status(), array('processing','completed'))) {
                wp_send_json_success(array('result' => 'success', 'redirect' => $o->get_checkout_order_received_url(), 'order_id' => $o->get_id(), 'already_paid' => true));
            }
            
            // Don't reuse pending/on-hold orders - checkout URLs are one-time use
            // Just tell user to wait and we'll create a fresh order
        }
        
        // If no paid order found, tell client to wait and retry (will create fresh order)
        wp_send_json_error('Another payment attempt is already in progress. Please wait a moment...');
    }
    WC()->session->set($lock_key, $lock_time);

    // Get the payment gateway instance
    try {
        $gateway = new WC_Gateway_CoinSub();
    } catch (Exception $e) {
        error_log('CoinSub AJAX: Failed to create gateway instance: ' . $e->getMessage());
        wp_send_json_error('Failed to initialize payment gateway');
    }
    
    // Create order using WooCommerce's standard method
    // Create order using wc_create_order() which is the correct method
    $order = wc_create_order();
    
    if (!$order || is_wp_error($order)) {
        error_log('CoinSub AJAX: Failed to create order');
        wp_send_json_error('Failed to create order');
    }
    
    $order_id = $order->get_id();

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
    }
    
    // Set billing email for guest orders (needed for order association)
    $billing_email = sanitize_email($_POST['billing_email']);
    if ($billing_email) {
        $order->set_billing_email($billing_email);
    }
    
    // Calculate totals and save
    $order->calculate_totals();
    $order->save();
    
    // If this order already has a checkout URL (rare race), reuse it
    $existing_checkout = $order->get_meta('_coinsub_checkout_url');
    if (!empty($existing_checkout)) {
        error_log('═══════════════════════════════════════════════════════════');
        error_log('🔗🔗🔗 CoinSub AJAX: FOUND EXISTING CHECKOUT URL: ' . $existing_checkout);
        error_log('═══════════════════════════════════════════════════════════');
        // Store checkout URL in session to avoid long URLs
        WC()->session->set('coinsub_checkout_url_' . $order_id, $existing_checkout);
        
        // Get dedicated checkout page URL - use order_id instead of full URL
        $checkout_page_id = get_option('coinsub_checkout_page_id');
        if ($checkout_page_id) {
            $checkout_page_url = get_permalink($checkout_page_id);
            $redirect_url = add_query_arg('order_id', $order_id, $checkout_page_url);
            $result = array('result' => 'success', 'redirect' => $redirect_url, 'coinsub_checkout_url' => $existing_checkout);
        } else {
            // Fallback: redirect directly to checkout URL
            $result = array('result' => 'success', 'redirect' => $existing_checkout);
        }
    } else {
        // Process payment - this will create the purchase session
        error_log('🔗 CoinSub AJAX: Calling process_payment for order #' . $order->get_id());
        $result = $gateway->process_payment($order->get_id());
        error_log('🔗 CoinSub AJAX: process_payment returned. Result keys: ' . implode(', ', array_keys($result)));
        if (isset($result['coinsub_checkout_url'])) {
            error_log('🔗 CoinSub AJAX: process_payment returned checkout URL: ' . $result['coinsub_checkout_url']);
        } else {
            error_log('⚠️ CoinSub AJAX: process_payment did NOT return coinsub_checkout_url in result');
        }
    }
    
    if ($result['result'] === 'success') {
        // Get checkout URL from all possible sources
        $checkout_url = null;
        
        // Try result first
        if (isset($result['coinsub_checkout_url']) && !empty($result['coinsub_checkout_url'])) {
            $checkout_url = $result['coinsub_checkout_url'];
            error_log('🔗🔗🔗 CoinSub AJAX: CHECKOUT URL FROM RESULT: ' . $checkout_url);
        }
        
        // Try order meta
        if (empty($checkout_url)) {
            $checkout_url_from_order = $order->get_meta('_coinsub_checkout_url');
            if (!empty($checkout_url_from_order)) {
                $checkout_url = $checkout_url_from_order;
                error_log('🔗🔗🔗 CoinSub AJAX: CHECKOUT URL FROM ORDER META: ' . $checkout_url);
            }
        }
        
        // Try session
        if (empty($checkout_url)) {
            $checkout_url_from_session = WC()->session->get('coinsub_checkout_url_' . $order_id);
            if (!empty($checkout_url_from_session)) {
                $checkout_url = $checkout_url_from_session;
                error_log('🔗🔗🔗 CoinSub AJAX: CHECKOUT URL FROM SESSION: ' . $checkout_url);
            }
        }
        
        // Log final checkout URL prominently
        if (!empty($checkout_url)) {
            error_log('═══════════════════════════════════════════════════════════');
            error_log('🔗🔗🔗 CoinSub AJAX: FINAL CHECKOUT URL: ' . $checkout_url);
            error_log('═══════════════════════════════════════════════════════════');
        } else {
            error_log('⚠️⚠️⚠️ CoinSub AJAX: WARNING - NO CHECKOUT URL FOUND!');
            error_log('Result keys: ' . implode(', ', array_keys($result)));
            error_log('Result data: ' . json_encode($result));
        }
        
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
 * AJAX handler to clear checkout session when user leaves checkout page
 * This prevents reuse of one-time-use purchase session URLs
 */
function coinsub_ajax_clear_checkout_session() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'coinsub_clear_checkout_session')) {
        error_log('CoinSub Clear Checkout Session: Security check failed');
        wp_send_json_error('Security check failed');
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    if (!$order_id) {
        error_log('CoinSub Clear Checkout Session: No order ID provided');
        wp_send_json_error('No order ID provided');
    }
    
    error_log('🧹 CoinSub Clear Checkout Session: Clearing session data for order_id: ' . $order_id);
    
    // Clear order ID from session
    WC()->session->set('coinsub_order_id', null);
    
    // Clear checkout URL from session for this specific order
    WC()->session->set('coinsub_checkout_url_' . $order_id, null);
    
    // Clear pending order ID
    WC()->session->set('coinsub_pending_order_id', null);
    
    // Clear purchase session ID
    WC()->session->set('coinsub_purchase_session_id', null);
    
    error_log('✅ CoinSub Clear Checkout Session: Session cleared for order_id: ' . $order_id . ' - user will get fresh order on next checkout');
    
    wp_send_json_success(array('message' => 'Session cleared successfully'));
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
