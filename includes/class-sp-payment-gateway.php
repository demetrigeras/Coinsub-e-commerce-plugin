<?php
/**
 * Stablecoin Pay Payment Gateway
 * 
 * Simple cryptocurrency payment gateway for WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_CoinSub extends WC_Payment_Gateway {
    
    private $api_client;
    private $brand_company = ''; // No default - will be set from branding API
    private $button_logo_url = ''; // Logo URL for button (injected via JS)
    private $button_company_name = ''; // Company name for button
    private $checkout_title = ''; // Whitelabel title for checkout only (not admin)
    private $checkout_icon = ''; // Whitelabel icon for checkout only (not admin)
    
    /**
     * Constructor
     */
    public function __construct() {
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('🏗️ Coinsub - Gateway constructor called');
        }
        
        $this->id = 'coinsub';
        // Icon set dynamically in get_icon() - no default icon for whitelabel compatibility (CoinSub logo only in checkout as fallback)
        $this->icon = '';
        $this->has_fields = true; // Enable custom payment box
        $this->method_title = __('Stablecoin Pay', 'coinsub');
        $this->method_description = __('Accept Crypto payments with Stablecoin Pay', 'coinsub');
        
        // Declare supported features
        $this->supports = array(
            'products',
            'refunds'
        );
        
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('🏗️ Coinsub - Supports: ' . json_encode($this->supports));
        }
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Set default title for admin (always "Stablecoin Pay" in admin)
        // Whitelabel branding will only be applied on checkout (frontend)
        $this->title = 'Pay with Coinsub'; // Default for admin display
        $this->description = '';
        $this->enabled = $this->get_option('enabled', 'yes');
        
        // Initialize API client
        $this->api_client = new CoinSub_API_Client();
        
        // CRITICAL: Only load whitelabel branding on frontend (checkout), NOT in admin
        // Admin/settings page should always show "Stablecoin Pay"
        if (!is_admin()) {
            // Check if we need to refresh branding (deferred from previous save)
            // This prevents timeout during save - branding fetch happens on next page load
            $refresh_branding = get_transient('coinsub_refresh_branding_on_load');
            if ($refresh_branding) {
                error_log('CoinSub Whitelabel: 🔄 Deferred branding fetch triggered - fetching now...');
                delete_transient('coinsub_refresh_branding_on_load');
                // Load branding with force refresh (this will make API calls)
                $this->load_whitelabel_branding(true);
            } else {
                // Load whitelabel branding from cache only (no API calls)
                $this->load_whitelabel_branding(false);
            }
        } else {
            // In admin, always use default branding (no whitelabel)
            $this->checkout_title = 'Pay with Coinsub';
            $this->checkout_icon = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            $this->button_logo_url = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            $this->button_company_name = 'Coinsub';
            // Don't log in admin (reduces log noise)
        }
        
        // Only log constructor details on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('🏗️ CoinSub - Constructor - ID: ' . $this->id);
        error_log('🏗️ CoinSub - Constructor - Title: ' . $this->title);
        error_log('🏗️ CoinSub - Constructor - Description: ' . $this->description);
        error_log('🏗️ CoinSub - Constructor - Enabled: ' . $this->enabled);
        error_log('🏗️ CoinSub - Constructor - Merchant ID: ' . $this->get_option('merchant_id'));
        error_log('🏗️ CoinSub - Constructor - Method Title: ' . $this->method_title);
        error_log('🏗️ CoinSub - Constructor - Has fields: ' . ($this->has_fields ? 'YES' : 'NO'));
        }
        
        // Add hooks
        // CRITICAL: This hook fires when settings are saved - it's the primary way WooCommerce saves gateway settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'update_api_client_settings'), 10);
        
        // ALSO hook into admin_init to catch form submission early (backup method for debugging)
        add_action('admin_init', array($this, 'maybe_process_admin_options'), 5);
        
        // Automatically ensure checkout page has shortcode when gateway is enabled
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'ensure_checkout_shortcode_on_save'), 20);
        
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        add_action('wp_footer', array($this, 'add_checkout_script'));
        add_action('wp_head', array($this, 'add_payment_button_styles'));
        
        // Check and restore cart if user returns with pending order
        add_action('woocommerce_checkout_init', array($this, 'maybe_restore_cart_from_pending_order'), 5);
        // Removed woocommerce_order_button_text filter - using default "Place order" for all payment methods
        
        // Customize refund UI for CoinSub orders (hide manual refund, only show CoinSub API refund)
        add_action('admin_head', array($this, 'hide_manual_refund_ui_for_coinsub'));
        add_action('admin_footer', array($this, 'hide_manual_refund_js_for_coinsub'));
        add_filter('woocommerce_order_item_display_meta_key', array($this, 'customize_refund_meta_key'), 10, 3);
        
        // Simple approach: Just completely hide and disable manual refund button via CSS/JS only
        // No complex interception - just hide it so it can't be clicked
        // IMPORTANT: This ONLY affects CoinSub orders - other payment gateways (Stripe, PayPal, etc.) are unaffected
        
        // Add AJAX actions
        add_action('wp_ajax_coinsub_redirect_after_payment', array($this, 'redirect_after_payment_ajax'));
        add_action('wp_ajax_nopriv_coinsub_redirect_after_payment', array($this, 'redirect_after_payment_ajax'));
        
    }
    
    /**
     * Admin panel options
     */
    public function admin_options() {
        /**
         * CRITICAL FIX: We MUST call parent::admin_options() FIRST!
         * 
         * THE PROBLEM:
         * - When we output HTML before calling parent::admin_options(), it breaks WooCommerce's form structure
         * - WooCommerce's parent method expects to output the <form> tag from scratch
         * - If we output HTML first, the form action attribute ends up empty
         * - Without a form action, the form can't submit and settings can't be saved
         * 
         * THE SOLUTION:
         * - Call parent::admin_options() FIRST to generate the complete form structure
         * - Then inject instructions via JavaScript AFTER the form is rendered
         * - This preserves the form structure and ensures the action attribute is set correctly
         */
        
        // Call parent FIRST to generate the form with proper action attribute
        parent::admin_options();
        
        // Now inject instructions at the top using JavaScript (after form is rendered)
        // Get Meld URL and webhook URL first (PHP) so we can properly escape them for JavaScript
        $meld_url = esc_js($this->get_meld_onramp_url());
        $secret = get_option('coinsub_webhook_secret');
        $webhook_base = home_url('/wp-json/stablecoin/v1/webhook');
        $webhook_url = $secret ? add_query_arg('secret', $secret, $webhook_base) : $webhook_base;
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Inject instructions box at the top (after the h2 title, before the form table)
            var meldUrl = <?php echo json_encode($this->get_meld_onramp_url()); ?>;
            var webhookUrl = <?php echo json_encode($webhook_url); ?>;
            var instructions = $('<div style="background:#fff;border-left:4px solid #3b82f6;padding:20px;margin:20px 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327"><h3 style=margin-top:0;font-size:1.3em>Setup Instructions</h3><h4 style="margin:1.5em 0 .5em">Step 1. Select Environment & Get Your Stablecoin Pay Credentials</h4><ol style=line-height:1.6;margin-top:0><li>Log in to your account<li>Navigate to <strong>Settings</strong> in your dashboard<li>Copy your <strong>Merchant ID</strong><li>Create and copy your <strong>API Key</strong><li>Paste both into the fields below</ol><h4 style="margin:1.5em 0 .5em">Step 2: Configure Webhook (CRITICAL)</h4><ol style=line-height:1.6;margin-top:0><li>Copy the <strong>Webhook URL</strong> shown below (it will look like: <code>https://yoursite.com/wp-json/stablecoin/v1/webhook</code>)<li>Go back to your dashboard <strong>Settings</strong><li>Find the <strong>Webhook URL</strong> field<li><strong>Paste your webhook URL</strong> into that field and save<li><em>This is essential</em> - without this, orders won\'t update when payments complete!</ol><h4 style="margin:1.5em 0 .5em">Step 3: Fix WordPress Checkout Page (If Needed)</h4><ol style=line-height:1.6;margin-top:0><li>Go to <strong>Pages</strong> → Find your <strong>Checkout</strong> page → Click <strong>Edit</strong><li>In the page editor, click the <strong style=font-size:1.2em;line-height:1>⋮</strong> (three vertical dots) in the top right<li>Select <strong>Code Editor</strong><li>Replace any block content with: <code style="background:#f0f0f1;padding:1px 3px">[woocommerce_checkout]</code><li>Click <strong>Update</strong> to save</ol><h4 style="margin:1.5em 0 .5em">Step 4: Enable Stablecoin Pay</h4><ol style=line-height:1.6;margin-top:0><li>Check the <strong>"Enable Stablecoin Pay Crypto Payments"</strong> box below<li>Click <strong>Save changes</strong><li>Done! Customers will now see the payment option at checkout!</ol><p style="margin-bottom:0;padding:10px;background:#fef3c7;border-radius:4px;border:1px solid #998843"><strong>⚠️ Important:</strong> Stablecoin Pay works alongside other payment methods. Make sure to complete ALL steps above, especially the webhook configuration!<div style="margin-top:20px;padding:15px;background:#e8f5e9;border-radius:4px;border:1px solid #4caf50"><h3 style=margin-top:0>💳 Setting Up Subscription Products</h3><p><strong>To enable recurring payments for a product:</strong><ol style=line-height:1.6;margin-top:10px><li>Go to <strong>Products</strong> → Select the product you want to make a subscription<li>Click <strong>Edit</strong> and scroll to the <strong>Product Data</strong> section<li>Check the <strong>"Stablecoin Pay Subscription"</strong> checkbox<li>Configure the subscription settings:<ul style=margin-top:8px><li><strong>Frequency:</strong> How often it repeats (Every, Every Other, Every Third, etc.)<li><strong>Interval:</strong> Time period (Day, Week, Month, Year)<li><strong>Duration:</strong> Number of payments (0 = Until Cancelled)</ul><li>Click <strong>Update</strong> to save the product</ol><p style=margin-bottom:0;font-size:13px;color:#2e7d32><strong>Note:</strong> Each product must be configured individually. Customers can manage their subscriptions from their account page.</div><div style="margin-top:20px;padding:15px;background:#fff3cd;border-radius:4px;border:1px solid #ffc107"><h3 style=margin-top:0>⚠️ Refund Requirements & Limitations</h3><p style=margin-bottom:10px><strong>Important Refund Disclaimer:</strong></p><ul style=margin-top:8px;margin-bottom:15px;line-height:1.6><li><strong>Refunds are only available for customers who paid using stablecoin wallets or supported payment providers.</strong><li><strong>Your merchant account must have refund capabilities enabled.</strong><li><strong>Refunds use the same network and token as the original payment.</strong> If the original payment information is not available, refunds default to <strong>USDC on Polygon</strong>.<li>Customers must have a compatible wallet to receive refunds.</ul><p style=margin-bottom:10px;padding:10px;background:#fff;border-left:3px solid #ff9800;font-size:13px><strong>⚠️ Before processing refunds:</strong> Verify that the customer\'s payment method supports refunds and that your merchant account has refund functionality enabled. Contact support if you\'re unsure.</p></div><div style="margin-top:20px;padding:15px;background:#eef7fe;border-radius:4px;border:1px solid #0284c7"><h3 style=margin-top:0>Add Tokens for Refunds</h3><p><strong>Refunds use the same network and token as the original payment (defaults to USDC on Polygon if unavailable).</strong><p>To process refunds, you\'ll need sufficient tokens in your merchant wallet on the same network as the original payment. If you don\'t have enough tokens, you can purchase them through Meld.<p style=margin-bottom:10px><a class="button button-primary"href="' + meldUrl + '"style=background:#2271b1;border-color:#2271b1 target=_blank>Buy Tokens via Meld</a><p style=margin-bottom:0;font-size:12px;color:#666><strong>Tip:</strong> Keep a small reserve of tokens (especially USDC on Polygon as the default fallback) to cover refunds quickly. Click the button above to add funds via Meld.</div></div>');
            
            // Insert after the h2 title (which is the first h2 in the form)
            $('h2').first().after(instructions);
            
            // CRITICAL FIX: Ensure form action is set (run multiple times to catch dynamic form generation)
            function ensureFormAction() {
                var $form = $('form');
                if ($form.length > 0) {
                    var currentAction = $form.attr('action');
                    console.log('🔔 CoinSub: Form action check:', currentAction || 'EMPTY - THIS IS THE PROBLEM!');
                    console.log('🔔 CoinSub: Form method:', $form.attr('method'));
                    
                    // If form action is empty, set it to the FULL current URL with query params
                    // WooCommerce needs the full URL with page, tab, and section parameters
                    if (!currentAction || currentAction === '' || currentAction === '#') {
                        // Get the FULL current URL including query parameters
                        var currentUrl = window.location.href;
                        $form.attr('action', currentUrl);
                        console.log('🔔 CoinSub: ⚠️ Form action was empty! Fixed to:', currentUrl);
                        return true; // Fixed
                    } else {
                        console.log('🔔 CoinSub: ✅ Form action is set correctly:', currentAction);
                        return false; // Already set
                    }
                }
                return false; // Form not found
            }
            
            // Run immediately
            ensureFormAction();
            
            // Run again after a short delay (in case form is generated dynamically)
            setTimeout(function() {
                if (ensureFormAction()) {
                    console.log('🔔 CoinSub: ✅ Form action fixed on delayed check');
                }
            }, 100);
            
            // Run one more time after a longer delay (for very slow form generation)
            setTimeout(function() {
                if (ensureFormAction()) {
                    console.log('🔔 CoinSub: ✅ Form action fixed on final delayed check');
                }
            }, 500);
            
            // CRITICAL: WooCommerce may use AJAX or regular form submission
            // We need to catch BOTH scenarios
            
            // Method 1: Listen for form submit (regular POST)
            $('form').on('submit', function(e) {
                var $submitForm = $(this);
                console.log('🔔 CoinSub: ✅✅✅ FORM SUBMIT EVENT FIRED! ✅✅✅');
                console.log('🔔 CoinSub: Form action:', $submitForm.attr('action'));
                console.log('🔔 CoinSub: Form method:', $submitForm.attr('method'));
                console.log('🔔 CoinSub: Merchant ID value:', $('#woocommerce_coinsub_merchant_id').val());
                console.log('🔔 CoinSub: API Key value:', $('#woocommerce_coinsub_api_key').val() ? '***SET***' : 'EMPTY');
                
                // Ensure form action is set before submission
                if (!$submitForm.attr('action') || $submitForm.attr('action') === '') {
                    var currentUrl = window.location.href;
                    $submitForm.attr('action', currentUrl);
                    console.log('🔔 CoinSub: ⚠️ Form action was empty on submit! Fixed to:', currentUrl);
                }
                
                // CRITICAL: Verify all required fields are present
                var merchantId = $('#woocommerce_coinsub_merchant_id').val();
                var apiKey = $('#woocommerce_coinsub_api_key').val();
                console.log('🔔 CoinSub: Pre-submit check - Merchant ID:', merchantId ? 'SET (' + merchantId.length + ' chars)' : 'EMPTY');
                console.log('🔔 CoinSub: Pre-submit check - API Key:', apiKey ? 'SET (' + apiKey.length + ' chars)' : 'EMPTY');
                
                // Ensure enabled checkbox is included
                var enabledCheckbox = $('#woocommerce_coinsub_enabled');
                if (enabledCheckbox.length > 0) {
                    console.log('🔔 CoinSub: Enabled checkbox found, checked:', enabledCheckbox.is(':checked'));
                }
                
                console.log('🔔 CoinSub: Form will submit now...');
                // Don't prevent default - let form submit normally
            });
            
            // Also listen for form submission via AJAX (WooCommerce might use AJAX)
            $(document).on('submit', 'form', function(e) {
                console.log('🔔 CoinSub: 🔄 Form submit event (document level) - Form action:', $(this).attr('action'));
            });
            
            // Method 2: Listen for save button clicks (BEFORE form submit)
            $(document).on('click', 'button[name="save"], input[name="save"], .button-primary[name="save"]', function(e) {
                var $form = $('form');
                var $button = $(this);
                console.log('🔔 CoinSub: ✅✅✅ SAVE BUTTON CLICKED! ✅✅✅');
                console.log('🔔 CoinSub: Button type:', $button.attr('type'));
                console.log('🔔 CoinSub: Button name:', $button.attr('name'));
                console.log('🔔 CoinSub: Merchant ID value:', $('#woocommerce_coinsub_merchant_id').val());
                console.log('🔔 CoinSub: API Key value:', $('#woocommerce_coinsub_api_key').val() ? '***SET***' : 'EMPTY');
                console.log('🔔 CoinSub: Form exists:', $form.length > 0);
                console.log('🔔 CoinSub: Form action:', $form.attr('action'));
                
                // CRITICAL: Ensure form action is set before button click submits
                if ($form.length > 0) {
                    var currentAction = $form.attr('action');
                    if (!currentAction || currentAction === '' || currentAction === '#') {
                        var currentUrl = window.location.href;
                        $form.attr('action', currentUrl);
                        console.log('🔔 CoinSub: ⚠️ Form action was empty on button click! Fixed to:', currentUrl);
                    }
                    
                    // CRITICAL: Also ensure the form has the correct method
                    if ($form.attr('method') !== 'post') {
                        $form.attr('method', 'post');
                        console.log('🔔 CoinSub: ⚠️ Form method was not POST! Fixed to POST');
                    }
                    
                    // CRITICAL: Ensure nonce field exists (WooCommerce requires this)
                    if ($form.find('input[name="_wpnonce"]').length === 0) {
                        console.error('🔔 CoinSub: ⚠️ WARNING: No nonce field found! This might prevent form submission.');
                    } else {
                        console.log('🔔 CoinSub: ✅ Nonce field found');
                    }
                    
                    // Verify form will submit
                    console.log('🔔 CoinSub: Final form action:', $form.attr('action'));
                    console.log('🔔 CoinSub: Final form method:', $form.attr('method'));
                    console.log('🔔 CoinSub: Form will submit in 100ms...');
                    
                    // Force form submission if it doesn't happen automatically
                    setTimeout(function() {
                        if ($form.length > 0 && $form.attr('action')) {
                            console.log('🔔 CoinSub: 🔄 Ensuring form submission...');
                            // Don't actually force submit - let WooCommerce handle it
                            // But log that we're ready
                        }
                    }, 100);
                } else {
                    console.error('🔔 CoinSub: ❌❌❌ NO FORM FOUND! This is a critical error!');
                }
                
                // Don't prevent default - let button submit form normally
            });
            
            // Method 3: Listen for WooCommerce AJAX submission (if it uses AJAX)
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (settings.url && settings.url.indexOf('wc-settings') !== -1) {
                    console.log('🔔 CoinSub: ✅✅✅ WOOCOMMERCE AJAX REQUEST DETECTED! ✅✅✅');
                    console.log('🔔 CoinSub: AJAX URL:', settings.url);
                    console.log('🔔 CoinSub: AJAX Method:', settings.type);
                }
            });
            
            // Also check for any JavaScript errors that might prevent submission
            window.addEventListener('error', function(e) {
                console.error('🔔 CoinSub: ❌ JavaScript Error detected:', e.message, e.filename, e.lineno);
            });
            
            // DIAGNOSTIC: Check form structure after page load
            setTimeout(function() {
                var $form = $('form');
                console.log('🔔 CoinSub: 🔍 FORM DIAGNOSTICS:');
                console.log('🔔 CoinSub: - Form exists:', $form.length > 0);
                if ($form.length > 0) {
                    console.log('🔔 CoinSub: - Form action:', $form.attr('action'));
                    console.log('🔔 CoinSub: - Form method:', $form.attr('method'));
                    console.log('🔔 CoinSub: - Form ID:', $form.attr('id'));
                    console.log('🔔 CoinSub: - Form class:', $form.attr('class'));
                    console.log('🔔 CoinSub: - Has nonce:', $form.find('input[name="_wpnonce"]').length > 0);
                    console.log('🔔 CoinSub: - Has merchant_id field:', $('#woocommerce_coinsub_merchant_id').length > 0);
                    console.log('🔔 CoinSub: - Has api_key field:', $('#woocommerce_coinsub_api_key').length > 0);
                    console.log('🔔 CoinSub: - Has enabled checkbox:', $('#woocommerce_coinsub_enabled').length > 0);
                    console.log('🔔 CoinSub: - Has save button:', $('button[name="save"], input[name="save"]').length > 0);
                }
            }, 1000);
        });
        </script>
        <?php
    }
    

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('🏗️ CoinSub - init_form_fields() called');
        }
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'coinsub'),
                'type' => 'checkbox',
                'label' => __('Enable Stablecoin Pay Crypto Payments', 'coinsub'),
                'default' => 'no'
            ),
            // Environment selection removed for production plugin; base URL fixed to dev-api in code
            'merchant_id' => array(
                'title' => __('Merchant ID', 'coinsub'),
                'type' => 'text',
                'description' => __('Get this from your merchant dashboard', 'coinsub'),
                'default' => '',
                'placeholder' => 'e.g., 12345678-abcd-1234-abcd-123456789abc',
            ),
            'api_key' => array(
                'title' => __('API Key', 'coinsub'),
                'type' => 'password',
                'description' => __('Get this from your merchant dashboard', 'coinsub'),
                'default' => '',
            ),
            'webhook_url' => array(
                'title' => __('Webhook URL', 'coinsub'),
                'type' => 'text',
                'description' => __('Copy this URL and add it to your merchant dashboard. This URL receives payment confirmations and automatically updates order status to "Processing" when payment is complete.', 'coinsub'),
                'default' => (function() {
                    $secret = get_option('coinsub_webhook_secret');
                    $base = home_url('/wp-json/stablecoin/v1/webhook');
                    return $secret ? add_query_arg('secret', $secret, $base) : $base;
                })(),
                'custom_attributes' => array('readonly' => 'readonly'),
                'css' => 'background: #f0f0f0;',
            ),
            
        );
    }
    
    /**
     * Get API base URL - centralized for all merchants
     * Default: api.coinsub.io/v1
     */
    public function get_api_base_url() {
        // Centralized CoinSub API - ALL merchants use this endpoint
        // The API determines whitelabel branding based on Merchant ID
        // return 'https://api.coinsub.io/v1'; // Production
        return 'https://test-api.coinsub.io/v1'; // Test environment
    }
    
    /**
     * Load whitelabel branding and update gateway display
     */
    /**
     * Load whitelabel branding
     * 
     * CRITICAL: This method ONLY affects checkout (frontend), NOT admin!
     * Admin/settings page always shows "Stablecoin Pay" regardless of whitelabel.
     * 
     * @param bool $force_refresh If true, force API call to refresh branding. If false, use cache only.
     */
    private function load_whitelabel_branding($force_refresh = false) {
        // Prevent multiple loads in the same request (gateway is instantiated multiple times)
        static $branding_loaded = false;
        static $cached_branding = null;
        
        error_log('CoinSub Whitelabel: 🔍 Cache check - branding_loaded: ' . ($branding_loaded ? 'YES' : 'NO') . ', force_refresh: ' . ($force_refresh ? 'YES' : 'NO'));
        
        if ($branding_loaded && !$force_refresh) {
            error_log('CoinSub Whitelabel: ⚡ Using cached branding from previous load in same request');
            // Restore cached values
            if ($cached_branding) {
                $this->brand_company = $cached_branding['brand_company'];
                $this->checkout_title = $cached_branding['checkout_title'];
                $this->checkout_icon = $cached_branding['checkout_icon'];
                $this->button_logo_url = $cached_branding['button_logo_url'];
                $this->button_company_name = $cached_branding['button_company_name'];
                error_log('CoinSub Whitelabel: ⚡ Restored branding - Title: "' . $this->checkout_title . '", Company: "' . $this->brand_company . '"');
            }
            return;
        }
        
        error_log('CoinSub Whitelabel: Loading branding for CHECKOUT ONLY (force_refresh: ' . ($force_refresh ? 'yes' : 'no') . ')...');
        
        // CRITICAL FIX: Check if credentials exist before loading branding
        // If no credentials, clear any old branding and use defaults
        // This prevents old branding (e.g., "Vantack") from showing when credentials are removed
        $merchant_id = $this->get_option('merchant_id');
        $api_key = $this->get_option('api_key');
        
        if (empty($merchant_id) && empty($api_key)) {
            error_log('CoinSub Whitelabel: ⚠️ No credentials - clearing old branding and using defaults');
        $branding = new CoinSub_Whitelabel_Branding();
            $branding->clear_cache(); // Clear any old branding from previous merchant
            $this->brand_company = 'Coinsub';
            // Store checkout-specific data (NOT $this->title which is for admin)
            $this->checkout_title = 'Pay with Coinsub';
            $this->checkout_icon = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            $this->button_logo_url = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            $this->button_company_name = 'Coinsub';
            error_log('CoinSub Whitelabel: ✅ Using default branding (no credentials, no payment provider) - Checkout Title: "Pay with Coinsub"');
            
            // Cache this for subsequent loads
            $branding_loaded = true;
            $cached_branding = array(
                'brand_company' => $this->brand_company,
                'checkout_title' => $this->checkout_title,
                'checkout_icon' => $this->checkout_icon,
                'button_logo_url' => $this->button_logo_url,
                'button_company_name' => $this->button_company_name,
            );
            error_log('CoinSub Whitelabel: 💾 Cached default branding');
            return;
        }
        
        // Credentials exist - proceed with branding load
        $branding = new CoinSub_Whitelabel_Branding();
        $branding_data = $branding->get_branding($force_refresh);
        
        // Only update if branding data exists and has company name
        if (!empty($branding_data) && isset($branding_data['company']) && !empty($branding_data['company'])) {
        $company_name = $branding_data['company'];
            $this->brand_company = $company_name;
            // Store checkout-specific title (NOT $this->title which is for admin)
            $this->checkout_title = 'Pay with ' . $company_name;
        
            error_log('CoinSub Whitelabel: ✅ CHECKOUT TITLE SET - Title: "' . $this->checkout_title . '" | Company: "' . $company_name . '" | brand_company property: "' . $this->brand_company . '"');
            
            // Update checkout icon - prefer favicon (smaller, better for payment method icon)
            $favicon_url = $branding->get_favicon_url();
            if ($favicon_url) {
                $this->checkout_icon = $favicon_url;
                error_log('CoinSub Whitelabel: 🖼️ ✅ Set checkout icon to favicon: ' . $favicon_url);
            } else {
                // Fallback to default logo
                $logo_url = $branding->get_logo_url('default', 'light');
                if ($logo_url) {
                    $this->checkout_icon = $logo_url;
                    error_log('CoinSub Whitelabel: 🖼️ ✅ Set checkout icon to logo: ' . $logo_url);
                } else {
                    error_log('CoinSub Whitelabel: 🖼️ ⚠️ No logo URL returned, keeping default icon');
                    $this->checkout_icon = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
                }
            }
            
            // For button logo (larger), use the default logo
            $logo_url = $branding->get_logo_url('default', 'light');
            $this->button_logo_url = $logo_url ?: COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            $this->button_company_name = $company_name;
            error_log('CoinSub Whitelabel: 🔘 Button logo URL set: ' . $this->button_logo_url);
        } else {
            // No branding found - use default "Pay with Coinsub" and CoinSub logo
            error_log('CoinSub Whitelabel: ⚠️ No branding data found - using default "Pay with Coinsub" and CoinSub logo');
            $this->brand_company = 'Coinsub';
            $this->checkout_title = 'Pay with Coinsub';
            $this->checkout_icon = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            // Set default button logo URL
            $this->button_logo_url = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            $this->button_company_name = 'Coinsub';
            error_log('CoinSub Whitelabel: ✅ Set default checkout title: "' . $this->checkout_title . '" and default icon: ' . $this->checkout_icon);
            error_log('CoinSub Whitelabel: 🔘 Button logo URL set to default: ' . $this->button_logo_url);
        }
        
        // Cache the branding for subsequent gateway instances in the same request
        // This prevents the second instance from clearing the branding
        $branding_loaded = true;
        $cached_branding = array(
            'brand_company' => $this->brand_company,
            'checkout_title' => $this->checkout_title,
            'checkout_icon' => $this->checkout_icon,
            'button_logo_url' => $this->button_logo_url,
            'button_company_name' => $this->button_company_name,
        );
        error_log('CoinSub Whitelabel: 💾 Cached branding for subsequent loads - Title: "' . $this->checkout_title . '", Company: "' . $this->brand_company . '"');
    }
    
    /**
     * Backup method: Try to catch form submission via admin_init hook
     * This is a fallback in case process_admin_options() isn't being called
     */
    public function maybe_process_admin_options() {
        // Only run on WooCommerce settings page for this gateway
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
            return;
        }
        if (!isset($_GET['section']) || $_GET['section'] !== $this->id) {
            return;
        }
        
        // Check if form was submitted (save button clicked)
        if (isset($_POST['save']) && isset($_POST['woocommerce_' . $this->id . '_enabled'])) {
            error_log('CoinSub Whitelabel: 🔔🔔🔔 maybe_process_admin_options() DETECTED FORM SUBMISSION! 🔔🔔🔔');
            error_log('CoinSub Whitelabel: POST data keys: ' . implode(', ', array_keys($_POST)));
            error_log('CoinSub Whitelabel: Merchant ID in POST: ' . (isset($_POST['woocommerce_coinsub_merchant_id']) ? 'YES - Value: ' . substr($_POST['woocommerce_coinsub_merchant_id'], 0, 20) . '...' : 'NO'));
            error_log('CoinSub Whitelabel: API Key in POST: ' . (isset($_POST['woocommerce_coinsub_api_key']) ? 'YES - Length: ' . strlen($_POST['woocommerce_coinsub_api_key']) : 'NO'));
            
            // CRITICAL FIX: WooCommerce's process_admin_options() isn't being called automatically
            // So we need to call it manually as a backup to ensure settings are saved
            error_log('CoinSub Whitelabel: ⚠️ WooCommerce process_admin_options() not called automatically - calling manually as backup...');
            $this->process_admin_options();
            error_log('CoinSub Whitelabel: ✅ process_admin_options() called manually - settings should now be saved');
        }
    }
    
    /**
     * Update API client settings when gateway settings are saved
     * This is called by the hook: woocommerce_update_options_payment_gateways_coinsub
     * This hook fires AFTER WooCommerce has saved the settings to the database
     * 
     * NOTE: This is also called directly from process_admin_options() to ensure it runs
     * We use a static flag to prevent duplicate execution
     */
    public function update_api_client_settings() {
        // Prevent duplicate execution (could be called from hook AND process_admin_options)
        static $executed = false;
        if ($executed) {
            error_log('CoinSub Whitelabel: ⚠️ update_api_client_settings() already executed, skipping duplicate call');
            return;
        }
        $executed = true;
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub Whitelabel: 🔔🔔🔔 SETTINGS SAVE DETECTED! 🔔🔔🔔');
        error_log('CoinSub Whitelabel: update_api_client_settings() CALLED');
        error_log('═══════════════════════════════════════════════════════════');
        
        // Reload settings from database (they were just saved by WooCommerce)
        $this->init_settings();
        
        $merchant_id = $this->get_option('merchant_id', '');
        $api_key = $this->get_option('api_key', '');
        // Use centralized API URL
        $api_base_url = $this->get_api_base_url();
        
        error_log('CoinSub Whitelabel: 📝 Settings - Merchant ID: ' . (empty($merchant_id) ? 'EMPTY' : substr($merchant_id, 0, 20) . '...'));
        error_log('CoinSub Whitelabel: 📝 Settings - API Key: ' . (strlen($api_key) > 0 ? substr($api_key, 0, 10) . '...' : 'EMPTY'));
        error_log('CoinSub Whitelabel: 📝 Settings - API Base URL: ' . $api_base_url);
        
        // Update API client if we have credentials
        if (!empty($merchant_id) && !empty($api_key)) {
            $this->api_client->update_settings($api_base_url, $merchant_id, $api_key);
            error_log('CoinSub Whitelabel: ✅ API client updated with credentials');
        } else {
            // No credentials - skip everything
            error_log('CoinSub Whitelabel: ⚠️ Skipping - no credentials');
            return;
        }
        
        // Clear any stuck fetch locks from previous attempts
        delete_transient('coinsub_whitelabel_fetching');
        delete_transient('coinsub_whitelabel_fetching_time');
        error_log('CoinSub Whitelabel: 🔓 Cleared any existing fetch locks');
        
        // CRITICAL FIX: Try to fetch branding immediately, but don't block if it fails
        // If immediate fetch fails, it will be retried on next page load
        error_log('CoinSub Whitelabel: 🔄 Attempting immediate branding fetch...');
        
        try {
            $branding = new CoinSub_Whitelabel_Branding();
            $branding->clear_cache();
            
            // Try immediate fetch with force_refresh=true
            $branding_data = $branding->get_branding(true);
            
            if (!empty($branding_data) && isset($branding_data['company'])) {
                error_log('CoinSub Whitelabel: ✅✅✅ Branding fetched immediately - Company: "' . $branding_data['company'] . '"');
            } else {
                error_log('CoinSub Whitelabel: ⚠️ Immediate fetch returned empty - will retry on next page load');
                // Set flag to retry on next page load as fallback
                set_transient('coinsub_refresh_branding_on_load', true, 60);
            }
            
        } catch (Exception $e) {
            error_log('CoinSub Whitelabel: ❌ ERROR fetching branding immediately: ' . $e->getMessage() . ' - Will retry on next page load');
            // Set flag to retry on next page load as fallback
            set_transient('coinsub_refresh_branding_on_load', true, 60);
        } catch (Error $e) {
            error_log('CoinSub Whitelabel: ❌ FATAL ERROR fetching branding immediately: ' . $e->getMessage() . ' - Will retry on next page load');
            // Set flag to retry on next page load as fallback
            set_transient('coinsub_refresh_branding_on_load', true, 60);
        }
        
        // Automatically create webhook when settings are saved
        try {
            $this->auto_create_webhook();
        } catch (Exception $e) {
            error_log('CoinSub Webhook: ❌ ERROR creating webhook: ' . $e->getMessage());
            // Continue - don't break the save process
        } catch (Error $e) {
            error_log('CoinSub Webhook: ❌ FATAL ERROR creating webhook: ' . $e->getMessage());
            // Continue - don't break the save process
        }
    }
    
    /**
     * Automatically create webhook for the merchant
     * Called when settings are saved
     */
    private function auto_create_webhook() {
        error_log('═══════════════════════════════════════════════════════════');
        error_log('🔔 CoinSub Webhook: Starting automatic webhook creation');
        error_log('═══════════════════════════════════════════════════════════');
        
        // Check credentials first
        $merchant_id = $this->get_option('merchant_id', '');
        $api_key = $this->get_option('api_key', '');
        
        error_log('🔔 CoinSub Webhook: Merchant ID: ' . (empty($merchant_id) ? '❌ EMPTY' : '✅ SET (' . substr($merchant_id, 0, 8) . '...)'));
        error_log('🔔 CoinSub Webhook: API Key: ' . (empty($api_key) ? '❌ EMPTY' : '✅ SET'));
        
        if (empty($merchant_id) || empty($api_key)) {
            error_log('🔔 CoinSub Webhook: ❌ Cannot create webhook - missing credentials');
            error_log('═══════════════════════════════════════════════════════════');
            return;
        }
        
        // Build webhook URL: https://{site_url}/wp-json/stablecoin/v1/webhook
        $webhook_url = home_url('/wp-json/stablecoin/v1/webhook');
        error_log('🔔 CoinSub Webhook: Webhook URL: ' . $webhook_url);
        
        // Check if merchant is a submerchant - if so, we need parent merchant ID for authentication
        // NOTE: This handles both scenarios:
        // 1. Whitelabel submerchants (Payment Servers, Vantack, etc.) - have parent_merchant_id
        // 2. Regular CoinSub merchants - is_submerchant = false, no parent_merchant_id needed
        error_log('🔔 CoinSub Webhook: Checking if merchant is a submerchant...');
        $merchant_info = $this->api_client->get_merchant_info($merchant_id);
        $parent_merchant_id = null;
        $is_submerchant = false;
        
        if (!is_wp_error($merchant_info)) {
            error_log('🔔 CoinSub Webhook: Merchant info retrieved successfully');
            error_log('   Full response: ' . json_encode($merchant_info));
            
            $is_submerchant = isset($merchant_info['is_submerchant']) ? $merchant_info['is_submerchant'] : false;
            error_log('   is_submerchant: ' . ($is_submerchant ? 'YES' : 'NO'));
            
            if ($is_submerchant) {
                if (isset($merchant_info['parent_merchant_id']) && !empty($merchant_info['parent_merchant_id'])) {
                    $parent_merchant_id = $merchant_info['parent_merchant_id'];
                    error_log('🔔 CoinSub Webhook: ✅ Merchant is a submerchant (whitelabel)');
                    error_log('   Submerchant ID: ' . $merchant_id);
                    error_log('   Parent Merchant ID: ' . $parent_merchant_id);
                    error_log('   Will use parent merchant ID in header for webhook creation');
                } else {
                    error_log('🔔 CoinSub Webhook: ⚠️ Merchant is marked as submerchant but parent_merchant_id is missing!');
                    error_log('   Available keys: ' . json_encode(array_keys($merchant_info)));
                    $is_submerchant = false; // Can't proceed as submerchant without parent ID
                }
            } else {
                error_log('🔔 CoinSub Webhook: ✅ Merchant is NOT a submerchant (regular CoinSub merchant)');
                error_log('   Will use merchant ID in both URL and header (standard flow)');
            }
        } else {
            error_log('🔔 CoinSub Webhook: ⚠️ Could not check merchant info: ' . $merchant_info->get_error_message());
            error_log('   Will proceed with regular merchant flow (assume not a submerchant)');
        }
        
        // Check if webhook already exists
        error_log('🔔 CoinSub Webhook: Checking for existing webhooks...');
        error_log('   Submerchant ID to use in URL: ' . ($is_submerchant ? $merchant_id : 'N/A (regular merchant)'));
        error_log('   Parent Merchant ID to use in header: ' . ($is_submerchant && $parent_merchant_id ? $parent_merchant_id : 'N/A (regular merchant)'));
        
        // For submerchants, use parent merchant ID in header but submerchant ID in URL
        $existing_webhooks = $this->api_client->list_webhooks('all', $is_submerchant ? $merchant_id : null, $is_submerchant && $parent_merchant_id ? $parent_merchant_id : null);
        
        if (is_wp_error($existing_webhooks)) {
            error_log('🔔 CoinSub Webhook: ⚠️ Failed to list existing webhooks: ' . $existing_webhooks->get_error_message());
            error_log('🔔 CoinSub Webhook: Will attempt to create webhook anyway...');
        } else {
            error_log('🔔 CoinSub Webhook: ✅ Successfully retrieved webhook list');
            
            // Handle different response structures
            $webhooks = array();
            if (isset($existing_webhooks['data']['webhooks'])) {
                $webhooks = $existing_webhooks['data']['webhooks'];
                error_log('🔔 CoinSub Webhook: Found webhooks in data.webhooks structure');
            } elseif (isset($existing_webhooks['webhooks'])) {
                $webhooks = $existing_webhooks['webhooks'];
                error_log('🔔 CoinSub Webhook: Found webhooks in webhooks structure');
            } elseif (isset($existing_webhooks['data']) && is_array($existing_webhooks['data'])) {
                $webhooks = $existing_webhooks['data'];
                error_log('🔔 CoinSub Webhook: Found webhooks in data structure');
            } else {
                error_log('🔔 CoinSub Webhook: ⚠️ Unexpected response structure: ' . json_encode(array_keys($existing_webhooks)));
            }
            
            error_log('🔔 CoinSub Webhook: Found ' . count($webhooks) . ' existing webhook(s)');
            
            // Check if our webhook URL already exists
            foreach ($webhooks as $index => $webhook) {
                $existing_url = isset($webhook['url']) ? $webhook['url'] : 'N/A';
                error_log('🔔 CoinSub Webhook: Checking webhook #' . ($index + 1) . ': ' . $existing_url);
                
                if (isset($webhook['url']) && $webhook['url'] === $webhook_url) {
                    $webhook_id = isset($webhook['webhook_id']) ? $webhook['webhook_id'] : (isset($webhook['id']) ? $webhook['id'] : 'N/A');
                    $webhook_status = isset($webhook['status']) ? $webhook['status'] : 'unknown';
                    error_log('🔔 CoinSub Webhook: ✅ Webhook already exists!');
                    error_log('   Webhook ID: ' . $webhook_id);
                    error_log('   Status: ' . $webhook_status);
                    error_log('   URL: ' . $webhook['url']);
                    error_log('═══════════════════════════════════════════════════════════');
                    return; // Webhook already exists, no need to create
                }
            }
            
            error_log('🔔 CoinSub Webhook: No matching webhook found - will create new one');
        }
        
        // Create the webhook
        error_log('🔔 CoinSub Webhook: Creating new webhook...');
        error_log('   URL: ' . $webhook_url);
        error_log('   Submerchant ID to use in URL: ' . ($is_submerchant ? $merchant_id : 'N/A (regular merchant)'));
        error_log('   Parent Merchant ID to use in header: ' . ($is_submerchant && $parent_merchant_id ? $parent_merchant_id : 'N/A (regular merchant)'));
        
        // For submerchants, use parent merchant ID in header but submerchant ID in URL
        $result = $this->api_client->create_webhook(
            $webhook_url, 
            $is_submerchant ? $merchant_id : null, 
            $is_submerchant && $parent_merchant_id ? $parent_merchant_id : null
        );
        
        if (is_wp_error($result)) {
            error_log('🔔 CoinSub Webhook: ❌ FAILED to create webhook');
            error_log('   Error Code: ' . $result->get_error_code());
            error_log('   Error Message: ' . $result->get_error_message());
            error_log('═══════════════════════════════════════════════════════════');
            // Don't throw - just log the error
        } else {
            error_log('🔔 CoinSub Webhook: ✅ API call successful');
            error_log('   Full Response: ' . json_encode($result));
            
            // Handle different response structures
            $webhook_id = null;
            if (isset($result['data']['webhook_id'])) {
                $webhook_id = $result['data']['webhook_id'];
                error_log('🔔 CoinSub Webhook: Found webhook_id in data.webhook_id');
            } elseif (isset($result['webhook_id'])) {
                $webhook_id = $result['webhook_id'];
                error_log('🔔 CoinSub Webhook: Found webhook_id in webhook_id');
            } elseif (isset($result['data']) && isset($result['data']['webhook_id'])) {
                $webhook_id = $result['data']['webhook_id'];
                error_log('🔔 CoinSub Webhook: Found webhook_id in data.data.webhook_id');
            } else {
                error_log('🔔 CoinSub Webhook: ⚠️ Webhook ID not found in expected locations');
                error_log('   Response keys: ' . json_encode(array_keys($result)));
                if (isset($result['data'])) {
                    error_log('   Data keys: ' . json_encode(array_keys($result['data'])));
                }
            }
            
            if ($webhook_id) {
                error_log('🔔 CoinSub Webhook: ✅✅✅ WEBHOOK CREATED SUCCESSFULLY! ✅✅✅');
                error_log('   Webhook ID: ' . $webhook_id);
                error_log('   Webhook URL: ' . $webhook_url);
                // Store webhook ID in settings for reference
                $this->update_option('webhook_id', $webhook_id);
                error_log('   Webhook ID saved to settings');
            } else {
                error_log('🔔 CoinSub Webhook: ⚠️ Webhook created but ID not found in response');
                error_log('   Full response structure: ' . json_encode($result, JSON_PRETTY_PRINT));
            }
            error_log('═══════════════════════════════════════════════════════════');
        }
    }
    
    /**
     * Override process_admin_options to ensure our method is called
     * This is called automatically by WooCommerce when settings are saved
     */
    public function process_admin_options() {
        // Prevent duplicate execution (could be called from WooCommerce AND maybe_process_admin_options)
        static $executed = false;
        if ($executed) {
            error_log('CoinSub Whitelabel: ⚠️ process_admin_options() already executed, skipping duplicate call');
            return parent::process_admin_options(); // Still call parent to save, but skip our custom logic
        }
        $executed = true;
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub Whitelabel: 🔔🔔🔔 process_admin_options() CALLED - Settings are being saved! 🔔🔔🔔');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub Whitelabel: POST data keys: ' . implode(', ', array_keys($_POST)));
        
        // Log POST data for merchant_id and api_key
        if (isset($_POST['woocommerce_coinsub_merchant_id'])) {
            $merchant_id_preview = substr($_POST['woocommerce_coinsub_merchant_id'], 0, 20);
            error_log('CoinSub Whitelabel: 📝 POST merchant_id: ' . $merchant_id_preview . '... (length: ' . strlen($_POST['woocommerce_coinsub_merchant_id']) . ')');
        } else {
            error_log('CoinSub Whitelabel: ⚠️ POST merchant_id NOT SET');
        }
        
        if (isset($_POST['woocommerce_coinsub_api_key'])) {
            $api_key_length = strlen($_POST['woocommerce_coinsub_api_key']);
            error_log('CoinSub Whitelabel: 📝 POST api_key: ' . ($api_key_length > 0 ? substr($_POST['woocommerce_coinsub_api_key'], 0, 10) . '... (length: ' . $api_key_length . ')' : 'EMPTY'));
        } else {
            error_log('CoinSub Whitelabel: ⚠️ POST api_key NOT SET - This is normal for password fields if unchanged');
        }
        
        // IMPORTANT: For password fields, WooCommerce only sends them in POST if they're changed
        // If api_key is not in POST, we need to preserve the existing value
        $existing_api_key = $this->get_option('api_key', '');
        if (!isset($_POST['woocommerce_coinsub_api_key']) && !empty($existing_api_key)) {
            // Password field not in POST means user didn't change it - preserve existing value
            $_POST['woocommerce_coinsub_api_key'] = $existing_api_key;
            error_log('CoinSub Whitelabel: 🔒 Preserving existing API key (password field unchanged)');
        }
        
        // Call parent to save settings first
        $result = parent::process_admin_options();
        
        error_log('CoinSub Whitelabel: 🔔 Parent process_admin_options() returned. Result: ' . ($result ? 'SUCCESS (true)' : 'FAILED (false)'));
        
        // Verify settings were saved
        $saved_merchant_id = $this->get_option('merchant_id', '');
        $saved_api_key = $this->get_option('api_key', '');
        error_log('CoinSub Whitelabel: ✅ Saved merchant_id: ' . (empty($saved_merchant_id) ? 'EMPTY' : substr($saved_merchant_id, 0, 20) . '... (length: ' . strlen($saved_merchant_id) . ')'));
        error_log('CoinSub Whitelabel: ✅ Saved api_key: ' . (empty($saved_api_key) ? 'EMPTY' : substr($saved_api_key, 0, 10) . '... (length: ' . strlen($saved_api_key) . ')'));
        
        // Now fetch branding (if we have credentials)
        // Wrap in try-catch to prevent fatal errors from breaking the save process
        if (!empty($saved_merchant_id) && !empty($saved_api_key)) {
            try {
                error_log('CoinSub Whitelabel: 🔔 Calling update_api_client_settings() to fetch branding...');
                $this->update_api_client_settings();
            } catch (Exception $e) {
                error_log('CoinSub Whitelabel: ❌ ERROR fetching branding: ' . $e->getMessage());
                error_log('CoinSub Whitelabel: ❌ Stack trace: ' . $e->getTraceAsString());
                // Don't break the save process - settings were saved successfully
            } catch (Error $e) {
                error_log('CoinSub Whitelabel: ❌ FATAL ERROR fetching branding: ' . $e->getMessage());
                error_log('CoinSub Whitelabel: ❌ Stack trace: ' . $e->getTraceAsString());
                // Don't break the save process - settings were saved successfully
            }
        } else {
            error_log('CoinSub Whitelabel: ⚠️ Skipping branding fetch - no credentials AND no payment provider name');
        }
        
        error_log('═══════════════════════════════════════════════════════════');
        
        return $result;
    }
    
    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', COINSUB_PLUGIN_FILE, true);
        }
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        error_log('🚀🚀🚀 CoinSub - process_payment() called for order #' . $order_id . ' 🚀🚀🚀');
        error_log('🎯 CoinSub - Payment method selected: ' . ($_POST['payment_method'] ?? 'none'));
        error_log('🎯 CoinSub - Order total: $' . wc_get_order($order_id)->get_total());
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('❌ CoinSub - Order not found: ' . $order_id);
            return array(
                'result' => 'failure',
                'messages' => __('Order not found', 'coinsub')
            );
        }
        
        error_log('✅ CoinSub - Order found. Starting payment process...');
        
        try {
            // Get cart data from session (calculated by cart sync)
            $cart_data = WC()->session->get('coinsub_cart_data');
            
            if (!$cart_data) {
                error_log('⚠️ CoinSub - No cart data from session, calculating now...');
                $cart_data = $this->calculate_cart_totals();
            }
            
            error_log('✅ CoinSub - Using cart data from session:');
            error_log('  Total: $' . $cart_data['total']);
            error_log('  Currency: ' . $cart_data['currency']);
            error_log('  Has Subscription: ' . ($cart_data['has_subscription'] ? 'YES' : 'NO'));
            
            // Create purchase session directly with cart totals
            $session_start_time = microtime(true);
            error_log('💳 CoinSub - Creating purchase session...');
            error_log('⏱️ CoinSub - Purchase session API call started at ' . date('H:i:s'));
            
            $purchase_session_data = $this->prepare_purchase_session_from_cart($order, $cart_data);
            
            $purchase_session = $this->api_client->create_purchase_session($purchase_session_data);
            
            $session_end_time = microtime(true);
            $session_duration = round($session_end_time - $session_start_time, 2);
            error_log('⏱️ CoinSub - Purchase session creation took ' . $session_duration . ' seconds');
            
            // Check for errors BEFORE trying to access as array
            if (is_wp_error($purchase_session)) {
                error_log('❌ CoinSub - Purchase session failed: ' . $purchase_session->get_error_message());
                throw new Exception($purchase_session->get_error_message());
            }
            
            error_log('✅ CoinSub - Purchase session created: ' . ($purchase_session['purchase_session_id'] ?? 'unknown'));
            
            // Get checkout URL from purchase session
            $checkout_url = isset($purchase_session['checkout_url']) ? $purchase_session['checkout_url'] : '';
            
            if (empty($checkout_url)) {
                error_log('❌ CoinSub - CRITICAL: Checkout URL is empty in purchase session response!');
                error_log('📦 Purchase session data: ' . json_encode($purchase_session));
                throw new Exception('Checkout URL not received from API');
            }
            
            error_log('🔗 CoinSub - Checkout URL from API (original): ' . $checkout_url);
            
            // Replace checkout URL domain with whitelabel buyurl if available
            $branding_data = get_option('coinsub_whitelabel_branding', array());
            if (!empty($branding_data['buyurl'])) {
                $buyurl = $branding_data['buyurl'];
                error_log('🎨 CoinSub - Whitelabel buyurl found: ' . $buyurl);
                
                // Extract domain from buyurl (e.g., https://buy.paymentservers.com)
                $buyurl_parts = parse_url($buyurl);
                if ($buyurl_parts && isset($buyurl_parts['scheme']) && isset($buyurl_parts['host'])) {
                    $whitelabel_domain = $buyurl_parts['scheme'] . '://' . $buyurl_parts['host'];
                    error_log('🎨 CoinSub - Whitelabel domain: ' . $whitelabel_domain);
                    
                    // Extract domain from original checkout URL
                    $checkout_url_parts = parse_url($checkout_url);
                    if ($checkout_url_parts && isset($checkout_url_parts['scheme']) && isset($checkout_url_parts['host'])) {
                        $original_domain = $checkout_url_parts['scheme'] . '://' . $checkout_url_parts['host'];
                        error_log('🔗 CoinSub - Original checkout domain: ' . $original_domain);
                        
                        // Replace the domain in checkout URL
                        $checkout_url = str_replace($original_domain, $whitelabel_domain, $checkout_url);
                        error_log('✅ CoinSub - Checkout URL replaced with whitelabel domain: ' . $checkout_url);
                    } else {
                        error_log('⚠️ CoinSub - Could not parse original checkout URL, using as-is');
                    }
                } else {
                    error_log('⚠️ CoinSub - Could not parse buyurl, using original checkout URL');
                }
            } else {
                error_log('ℹ️ CoinSub - No whitelabel buyurl found, using original checkout URL from API');
            }
            
            error_log('🔗 CoinSub - Final checkout URL (after whitelabel replacement): ' . $checkout_url);
            
            // Store CoinSub data in order meta
            $order->update_meta_data('_coinsub_purchase_session_id', $purchase_session['purchase_session_id']);
            $order->update_meta_data('_coinsub_checkout_url', $checkout_url);
            $order->update_meta_data('_coinsub_merchant_id', $this->get_option('merchant_id'));
            
            error_log('✅ CoinSub - Stored purchase session ID: ' . $purchase_session['purchase_session_id']);
            error_log('✅ CoinSub - Stored checkout URL in order meta: ' . $checkout_url);
            
            // Store subscription data if applicable
            if ($cart_data['has_subscription']) {
                $order->update_meta_data('_coinsub_is_subscription', 'yes');
                $order->update_meta_data('_coinsub_subscription_data', $cart_data['subscription_data']);
            } else {
                $order->update_meta_data('_coinsub_is_subscription', 'no');
            }
            
            // Store cart items in order meta
            $order->update_meta_data('_coinsub_cart_items', $cart_data['items']);
            $order->save();
            
            // Verify it was stored
            $stored_url = $order->get_meta('_coinsub_checkout_url');
            if ($stored_url !== $checkout_url) {
                error_log('⚠️ CoinSub - WARNING: Checkout URL mismatch! Stored: ' . $stored_url . ' vs Expected: ' . $checkout_url);
            } else {
                error_log('✅ CoinSub - Verified checkout URL stored correctly in order meta');
            }
            
            // Update order status - awaiting payment confirmation
            $order->update_status('on-hold', __('Awaiting crypto payment. Customer redirected to Stablecoin Pay checkout.', 'coinsub'));
            
            // Store order ID in session (used for tracking, not cart restoration)
            // Note: We intentionally DON'T restore cart on return - fresh checkout each time
            WC()->session->set('coinsub_pending_order_id', $order->get_id());
            
            // Store checkout URL in session to avoid long URLs (use order ID as key)
            WC()->session->set('coinsub_checkout_url_' . $order->get_id(), $checkout_url);
            error_log('✅ CoinSub - Stored checkout URL in session with key: coinsub_checkout_url_' . $order->get_id());
            
            // Verify session storage
            $session_url = WC()->session->get('coinsub_checkout_url_' . $order->get_id());
            if ($session_url !== $checkout_url) {
                error_log('⚠️ CoinSub - WARNING: Checkout URL mismatch in session! Stored: ' . $session_url . ' vs Expected: ' . $checkout_url);
            } else {
                error_log('✅ CoinSub - Verified checkout URL stored correctly in session');
            }
            
            // Empty cart (cart will NOT be restored on return - fresh checkout required)
            // This ensures new purchase session is created if user adds items and returns
            WC()->cart->empty_cart();
            
            error_log('🎉 CoinSub - Payment process complete! Checkout URL: ' . $checkout_url);
            
            // Get dedicated checkout page URL
            $checkout_page_id = get_option('coinsub_checkout_page_id');
            if ($checkout_page_id) {
                $checkout_page_url = get_permalink($checkout_page_id);
                // Use order ID instead of full URL to keep URL short
                $redirect_url = add_query_arg('order_id', $order->get_id(), $checkout_page_url);
                error_log('🎯 CoinSub - Redirecting to dedicated checkout page (short URL): ' . $redirect_url);
                
                return array(
                    'result' => 'success',
                    'redirect' => $redirect_url,
                    'coinsub_checkout_url' => $checkout_url
                );
            } else {
                // Fallback: redirect directly to checkout URL (external)
                error_log('⚠️ CoinSub - Checkout page not found, redirecting directly to checkout URL');
                return array(
                    'result' => 'success',
                    'redirect' => $checkout_url,
                    'coinsub_checkout_url' => $checkout_url
                );
            }
            
        } catch (Exception $e) {
            error_log('❌ CoinSub - Payment error: ' . $e->getMessage());
            wc_add_notice(__('Payment error: ', 'coinsub') . $e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }
    
    /**
     * Ensure products exist in CoinSub
     */
    private function ensure_products_exist($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Check if we already have a CoinSub product ID for this WooCommerce product
            $existing_coinsub_id = $order->get_meta('_coinsub_product_' . $product->get_id());
            
            if ($existing_coinsub_id) {
                continue; // Already exists
            }
            
            // Create product in CoinSub
            $product_data = array(
                'name' => $product->get_name(),
                'description' => $product->get_description() ?: $product->get_short_description(),
                'price' => (float) $product->get_price(),
                'currency' => get_woocommerce_currency(),
                'sku' => $product->get_sku(),
                'metadata' => array(
                    'woocommerce_product_id' => $product->get_id(),
                    'product_type' => $product->get_type(),
                    'source' => 'woocommerce_plugin'
                )
            );
            
            $coinsub_product = $this->api_client->create_product($product_data);
            
            if (!is_wp_error($coinsub_product)) {
                // Store the CoinSub product ID in order meta for future reference
                $order->update_meta_data('_coinsub_product_' . $product->get_id(), $coinsub_product['id']);
                $order->save();
            }
        }
    }
    
    // REMOVED: prepare_order_data - using WooCommerce-only approach
    
    /**
     * Prepare purchase session data
     */
    private function prepare_purchase_session_data($order, $coinsub_order) {
        // Check if this is a subscription order
        $is_subscription = false;
        $subscription_data = null;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_coinsub_subscription') === 'yes') {
                $is_subscription = true;
                $subscription_data = array(
                    'frequency' => $product->get_meta('_coinsub_frequency'),
                    'interval' => $product->get_meta('_coinsub_interval'),
                    'duration' => $product->get_meta('_coinsub_duration')
                );
                error_log('🔄 SUBSCRIPTION ORDER DETECTED!');
                error_log('  Frequency: ' . $subscription_data['frequency']);
                error_log('  Interval: ' . $subscription_data['interval']);
                error_log('  Duration: ' . $subscription_data['duration']);
                break;
            }
        }
        
        if (!$is_subscription) {
            error_log('📦 Regular order (not subscription)');
        }
        
        // Prepare product information
        $product_names = array();
        $product_details = array();
        $total_items = 0;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $item_name = $item->get_name();
            $quantity = $item->get_quantity();
            $total_items += $quantity;
            
            $product_names[] = $item_name;
            
            // Get CoinSub product ID from order meta if available
            $coinsub_product_id = $order->get_meta('_coinsub_product_' . $product->get_id());
            
            $product_details[] = array(
                'woocommerce_product_id' => $product->get_id(),
                'coinsub_product_id' => $coinsub_product_id ?: null,
                'name' => $item_name,
                'price' => (float) $item->get_total() / $quantity, // Price per unit
                'quantity' => $quantity,
                'total' => (float) $item->get_total(),
                'sku' => $product->get_sku(),
                'type' => $product->get_type()
            );
        }
        
        // Create order name with product details
        $order_name = count($product_names) > 1 
            ? 'WooCommerce Order: ' . implode(' + ', array_slice($product_names, 0, 3)) . (count($product_names) > 3 ? ' + ' . (count($product_names) - 3) . ' more' : '')
            : 'WooCommerce Order: ' . ($product_names[0] ?? 'Payment');
        
        // Get order totals breakdown
        $subtotal = (float) $order->get_subtotal();
        $shipping_total = (float) $order->get_shipping_total();
        $tax_total = (float) $order->get_total_tax();
        $total_amount = (float) $order->get_total();
        
        // Build details string with breakdown
        $details_parts = ['Payment for WooCommerce order #' . $order->get_order_number() . ' with ' . count($product_details) . ' product(s)'];
        if ($shipping_total > 0) {
            $details_parts[] = 'Shipping: $' . number_format($shipping_total, 2);
        }
        if ($tax_total > 0) {
            $details_parts[] = 'Tax: $' . number_format($tax_total, 2);
        }
        $details_string = implode(' | ', $details_parts);
        
        $success_url = $this->get_return_url($order);
        error_log('🔗 CoinSub - Success URL: ' . $success_url);
        
        $session_data = array(
            'name' => $order_name,
            'details' => $details_string,
            'currency' => $order->get_currency(),
            'amount' => $total_amount,
            'recurring' => $is_subscription,
            'metadata' => array(
                'woocommerce_order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'source' => 'woocommerce_plugin',
                'is_subscription' => $is_subscription,
                'individual_products' => $product_names,
                'product_count' => count($product_details),
                'total_items' => $total_items,
                'products' => $product_details,
                'currency' => $order->get_currency(),
                'order_breakdown' => array(
                    'subtotal' => $subtotal,
                    'shipping' => array(
                        'method' => $order->get_shipping_method(),
                        'cost' => $shipping_total
                    ),
                    'tax' => array(
                        'amount' => $tax_total
                    ),
                    'total' => $total_amount
                ),
                'subtotal_amount' => $subtotal,
                'shipping_cost' => $shipping_total,
                'tax_amount' => $tax_total,
                'total_amount' => $total_amount,
                'billing_address' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'company' => $order->get_billing_company(),
                    'address_1' => $order->get_billing_address_1(),
                    'address_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone()
                ),
                'shipping_address' => array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name' => $order->get_shipping_last_name(),
                    'company' => $order->get_shipping_company(),
                    'address_1' => $order->get_shipping_address_1(),
                    'address_2' => $order->get_shipping_address_2(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country()
                )
            ),
            'success_url' => $this->get_return_url($order), // Return to order received page after payment
            'cancel_url' => $this->get_return_url($order), // Return to order received page if cancelled
            'failure_url' => $this->get_return_url($order) // Return to order received page if failed
        );
        
        // Add subscription data if this is a subscription
        if ($is_subscription && $subscription_data) {
            error_log('🔍 Raw subscription data from product:');
            error_log('  Frequency: ' . var_export($subscription_data['frequency'], true));
            error_log('  Interval: ' . var_export($subscription_data['interval'], true));
            error_log('  Duration: ' . var_export($subscription_data['duration'], true));
            
            // Map interval number to capitalized string (matching Go API)
            $interval_map = array(
                '0' => 'Day', 0 => 'Day',
                '1' => 'Week', 1 => 'Week',
                '2' => 'Month', 2 => 'Month',
                '3' => 'Year', 3 => 'Year'
            );
            
            $interval_value = $subscription_data['interval'];
            
            // Don't default - let it error if interval is invalid
            if (!isset($interval_map[$interval_value])) {
                error_log('❌ Invalid interval value: ' . var_export($interval_value, true));
                throw new Exception('Invalid subscription interval. Please check product settings.');
            }
            
            $session_data['interval'] = $interval_map[$interval_value];
            $session_data['frequency'] = (string) $subscription_data['frequency'];
            $session_data['duration'] = (string) ($subscription_data['duration'] ?: '0');
            
            error_log('✅ Mapped subscription fields:');
            error_log('  interval: ' . $session_data['interval']);
            error_log('  frequency: ' . $session_data['frequency']);
            error_log('  duration: ' . $session_data['duration']);
            
            // Mark in metadata for tracking
            $session_data['metadata']['is_subscription'] = true;
            $session_data['metadata']['subscription_settings'] = $subscription_data;
        }
        
        return $session_data;
    }
    
    /**
     * Add checkout script to automatically open CoinSub checkout in new tab
     */
    public function add_checkout_script() {
        // Check if we're on the order received page
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }
        
        // Get order ID from URL
        global $wp;
        $order_id = absint($wp->query_vars['order-received']);
        
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if this is a CoinSub order with pending redirect
        $checkout_url = $order->get_meta('_coinsub_pending_redirect');
        
        if (!empty($checkout_url)) {
            // Delete the meta to prevent duplicate redirects
            $order->delete_meta_data('_coinsub_pending_redirect');
            $order->save();
            
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Open CoinSub checkout in new tab
                var coinsubWindow = window.open('<?php echo esc_js($checkout_url); ?>', '_blank');
                
                // Show notice to user
                $('body').prepend('<div id="coinsub-checkout-notice" style="position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 20px; border-radius: 8px; z-index: 9999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 350px;"><strong style="font-size: 16px;">🚀 Complete Your Payment</strong><br><br>A new tab has opened with your Stablecoin Pay checkout.<br><br><small>Your order will be confirmed once payment is received.</small><br><br><button onclick="window.open(\'<?php echo esc_js($checkout_url); ?>\', \'_blank\')" style="background: white; color: #1e3a8a; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin-top: 10px; font-weight: bold;">Reopen Payment Page</button></div>');
                
                // Remove notice after 30 seconds
                setTimeout(function() {
                    $('#coinsub-checkout-notice').fadeOut();
                }, 30000);
            });
            </script>
            <?php
        }
    }
    
    /**
     * Display payment fields with modal checkout
     */
    public function payment_fields() {
        echo '<div id="coinsub-payment-description">';
        echo '<p>' . __('Pay securely with cryptocurrency using CoinSub.', 'coinsub') . '</p>';
        echo '</div>';
        
        // Initialize empty checkout URL for the template
        $checkout_url = '';
        
        // Get CoinSub button text for JavaScript
        $coinsub_button_text = $this->get_order_button_text();
        
        // Include the modal template
        include plugin_dir_path(__FILE__) . 'sp-checkout-modal.php';
    }
    
    /**
     * Process refunds (Automatic API refund for single payments)
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        error_log('🔄 CoinSub Refund - process_refund called');
        error_log('🔄 CoinSub Refund - Order ID: ' . $order_id);
        error_log('🔄 CoinSub Refund - Amount parameter: ' . ($amount ?? 'NULL'));
        error_log('🔄 CoinSub Refund - Reason: ' . $reason);
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('❌ CoinSub Refund - Order not found: ' . $order_id);
            return new WP_Error('invalid_order', __('Invalid order.', 'coinsub'));
        }
        
        error_log('🔄 CoinSub Refund - Order total: ' . $order->get_total());
        error_log('🔄 CoinSub Refund - Order status: ' . $order->get_status());
        error_log('🔄 CoinSub Refund - Payment method: ' . $order->get_payment_method());
        
        // If amount is null or 0, use the order total
        if ($amount === null || $amount == 0) {
            $amount = $order->get_total();
            error_log('🔄 CoinSub Refund - Using order total as refund amount: ' . $amount);
        }
        
        // Check if this is a subscription order (for logging only)
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        error_log('🔄 CoinSub Refund - Is subscription: ' . ($is_subscription ? 'YES' : 'NO'));
        
        // Process automatic refund for ALL orders (including subscriptions) via API
        // IMPORTANT: All refunds are processed as USDC on Polygon for simplicity and wide acceptance
        // Get required payment details from order meta
        $customer_wallet = $order->get_meta('_customer_wallet_address');
        
        // Get customer email address for refund
        $customer_email = $order->get_billing_email();
        
        // Get agreement message data (stored from webhook) - for logging only
        $agreement_message_json = $order->get_meta('_coinsub_agreement_message');
        $agreement_message = $agreement_message_json ? json_decode($agreement_message_json, true) : null;
        
        error_log('🔄 CoinSub Refund - Customer wallet: ' . ($customer_wallet ?: 'NOT FOUND'));
        error_log('🔄 CoinSub Refund - Customer email: ' . ($customer_email ?: 'NOT FOUND'));
        error_log('🔄 CoinSub Refund - Agreement message: ' . ($agreement_message_json ?: 'NOT FOUND'));
        
        // Debug: Show all order meta
        $all_meta = $order->get_meta_data();
        error_log('🔄 CoinSub Refund - All order meta keys: ' . implode(', ', array_map(function($meta) { return $meta->key; }, $all_meta)));
        
        // Use customer email as to_address (preferred) or fallback to wallet address
        $to_address = $customer_email ?: $customer_wallet;
        
        // Validate required data for automatic refund
        if (empty($to_address)) {
            error_log('❌ CoinSub Refund - No customer email or wallet found, cannot process refund');
            
            // Fallback to manual refund for orders without customer data
            $refund_note = sprintf(
                __('AUTOMATIC REFUND FAILED - MANUAL REFUND REQUIRED: %s. Reason: %s. Customer email or wallet address not found. Please contact customer and process refund manually.', 'coinsub'),
                wc_price($amount),
                $reason
            );
            $order->add_order_note($refund_note);
            $order->update_status('refund-pending', __('Refund pending - manual processing required.', 'coinsub'));
            
            // Return error so WooCommerce doesn't mark as refunded
            return new WP_Error('missing_customer_data', __('Customer email or wallet address not found. Manual refund required.', 'coinsub'));
        }
        
        // Use the same chain and token from the original payment (stored from webhook)
        // Fallback to USDC if token not available (keep same chain)
        // Fallback to Polygon Mainnet with USDC if chain not available
        $chain_id = $order->get_meta('_coinsub_chain_id');
        $token_symbol = $order->get_meta('_coinsub_token_symbol');
        
        // If chain ID is missing, fallback to Polygon Mainnet (Production)
        if (empty($chain_id)) {
            $chain_id = '137'; // Polygon Mainnet (Production)
            error_log('🔄 CoinSub Refund - Chain ID not found in order, using fallback: Polygon Mainnet Production (137)');
        }
        
        // If token symbol is missing, fallback to USDC (on the same chain)
        if (empty($token_symbol)) {
            $token_symbol = 'USDC';
            error_log('🔄 CoinSub Refund - Token symbol not found in order, using fallback: USDC on chain_id ' . $chain_id);
        }
        
        error_log('🔄 CoinSub Refund - Using refund chain/token: ' . $token_symbol . ' on chain_id ' . $chain_id);
        
        error_log('🔄 CoinSub Refund - Processing automatic refund for order #' . $order_id);
        error_log('🔄 CoinSub Refund - Amount: ' . $amount);
        error_log('🔄 CoinSub Refund - To Address (email/wallet): ' . $to_address);
        error_log('🔄 CoinSub Refund - Chain ID: ' . $chain_id);
        error_log('🔄 CoinSub Refund - Token: ' . $token_symbol);
        
        // Initialize API client
        $api_client = new CoinSub_API_Client();
        
        error_log('🔄 CoinSub Refund - About to call refund API...');
        
        // Call refund API using customer email or wallet address
        $refund_result = $api_client->refund_transfer_request(
            $to_address,
            $amount,
            $chain_id,
            $token_symbol
        );
        
        error_log('🔄 CoinSub Refund - API call completed. Result: ' . (is_wp_error($refund_result) ? 'ERROR' : 'SUCCESS'));
        
        if (is_wp_error($refund_result)) {
            $error_message = $refund_result->get_error_message();
            error_log('❌ CoinSub Refund - API returned WP_Error: ' . $error_message);
            error_log('❌ CoinSub Refund - Error code: ' . $refund_result->get_error_code());
            error_log('❌ CoinSub Refund - Error data: ' . json_encode($refund_result->get_error_data()));
            
            // Check for insufficient funds error
            if (strpos(strtolower($error_message), 'insufficient') !== false || 
                strpos(strtolower($error_message), 'balance') !== false) {
                
                $insufficient_funds_note = sprintf(
                    __('REFUND FAILED - INSUFFICIENT FUNDS: %s. Reason: %s. Error: %s', 'coinsub'),
                    wc_price($amount),
                    $reason,
                    $error_message
                );
                
                $coinsub_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=coinsub');
                
                $insufficient_funds_note .= '<br><br><strong>🔧 Action Required - Add USDC to Polygon:</strong><br>';
                $insufficient_funds_note .= 'You need ' . $amount . ' USDC on Polygon to process this refund.<br><br>';
                
                $insufficient_funds_note .= '<strong>To add funds:</strong><br>';
                $insufficient_funds_note .= '1. Go to <strong>WooCommerce → Settings → Payments → Stablecoin Pay</strong><br>';
                $insufficient_funds_note .= '2. Click <strong>"Manage"</strong> or scroll down<br>';
                $insufficient_funds_note .= '3. Click the <strong>"Onramp USDC Polygon via Meld"</strong> button<br>';
                $insufficient_funds_note .= '4. Complete the onramp process<br>';
                $insufficient_funds_note .= '5. Retry the refund once funds are available<br><br>';
                
                $insufficient_funds_note .= '<a href="' . esc_url($coinsub_settings_url) . '" class="button button-primary" style="background: #0284c7; border-color: #0284c7;">Go to Payment ProviderSettings</a>';
                
                $order->add_order_note($insufficient_funds_note);
                $order->update_status('refund-pending', __('Refund pending - insufficient funds. Please add USDC to Polygon wallet.', 'coinsub'));
                
                error_log('❌ CoinSub Refund - Insufficient funds: ' . $error_message);
                return new WP_Error('insufficient_funds', $error_message);
            }
            
            // Other API errors
            $refund_note = sprintf(
                __('REFUND FAILED: %s. Reason: %s. API Error: %s', 'coinsub'),
                wc_price($amount),
                $reason,
                $error_message
            );
            $order->add_order_note($refund_note);
            error_log('❌ CoinSub Refund - API Error: ' . $error_message);
            return $refund_result;
        }
        
        // Validate API response
        if (!is_array($refund_result) || empty($refund_result)) {
            error_log('❌ CoinSub Refund - API returned invalid response: ' . json_encode($refund_result));
            $refund_note = sprintf(
                __('REFUND FAILED: %s. Reason: %s. API returned invalid response. Please try again or process manually.', 'coinsub'),
                wc_price($amount),
                $reason
            );
            $order->add_order_note($refund_note);
            $order->update_status('refund-pending', __('Refund pending - API error. Please retry.', 'coinsub'));
            return new WP_Error('invalid_api_response', __('API returned invalid response. Please try again.', 'coinsub'));
        }
        
        error_log('✅ CoinSub Refund - API response received: ' . json_encode($refund_result));
        
        // Success - add order note and update status
        $refund_id = $refund_result['refund_id'] ?? $refund_result['transfer_id'] ?? 'N/A';
        $transaction_hash = $refund_result['transaction_hash'] ?? $refund_result['hash'] ?? 'N/A';
        
        // Get network name for display
        $network_name = $this->get_network_name($chain_id);
        
        // Note: Refund uses the same chain/token as original payment (or USDC fallback)
        $refund_note = sprintf(
            __('REFUND INITIATED: %s. Reason: %s. Customer wallet: %s. Refund ID: %s. Refund will be sent as %s on %s (same as original payment). Refund initiated via Stablecoin Pay API. Waiting for transfer confirmation...', 'coinsub'),
            wc_price($amount),
            $reason,
            $customer_wallet ?: $to_address,
            $refund_id,
            $token_symbol,
            $network_name
        );
        
        // Add note if using fallback
        $stored_chain_id = $order->get_meta('_coinsub_chain_id');
        $stored_token = $order->get_meta('_coinsub_token_symbol');
        if (empty($stored_chain_id) || empty($stored_token)) {
            $refund_note .= '<br><br><strong>ℹ️ Note:</strong> Original payment chain/token not found, using fallback: ' . $token_symbol . ' on ' . $network_name . '.';
        }
        
        $order->add_order_note($refund_note);
        
        // Store refund details and mark as pending
        $order->update_meta_data('_coinsub_refund_pending', 'yes');
        $order->update_meta_data('_coinsub_refund_status', 'pending');
        
        if (!empty($refund_id)) {
            $order->update_meta_data('_coinsub_refund_id', $refund_id);
            error_log('✅ CoinSub Refund - Stored refund ID: ' . $refund_id);
        }
        if (!empty($transaction_hash) && $transaction_hash !== 'N/A') {
            $order->update_meta_data('_coinsub_refund_transaction_hash', $transaction_hash);
            error_log('✅ CoinSub Refund - Stored transaction hash: ' . $transaction_hash);
        }
        
        // Don't mark as refunded yet - wait for transfer webhook confirmation
        // WooCommerce will mark it as refunded when we return true, but we'll track status separately
        $order->save();
        
        error_log('✅ CoinSub Refund - Refund initiated for order #' . $order_id . ' - waiting for transfer confirmation via webhook');
        error_log('✅ CoinSub Refund - Refund ID: ' . $refund_id . ', Transaction Hash: ' . $transaction_hash);
        
        // Return true to WooCommerce so it shows the refund UI, but we'll update status when transfer webhook arrives
        return true;
    }
    
    /**
     * Generate Meld onramp URL for USDC Polygon
     * Format: https://meldcrypto.com/?publicKey=...&destinationCurrencyCodeLocked=USDC_POLYGON&walletAddressLocked=...&transactionType=BUY&sourceAmount=...&externalSessionId=...&redirectUrl=...
     */
    private function get_meld_onramp_url($wallet_address = '', $amount = '') {
        // Meld base URL
        $meld_base_url = 'https://meldcrypto.com';
        
        // Get Meld public key from settings (if configured)
        // For now, we'll use a placeholder - you may want to add this as a setting field
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $meld_public_key = isset($gateway_settings['meld_public_key']) ? $gateway_settings['meld_public_key'] : '';
        
        // Get merchant wallet address - try to get from CoinSub API or use provided
        if (empty($wallet_address)) {
            // TODO: Could fetch from CoinSub API if available
            // For now, leave empty - Meld can handle it
        }
        
        // Generate session ID (UUID v4 format)
        $session_id = $this->generate_uuid4();
        
        // Get redirect URL (WordPress admin - CoinSub settings page)
        $redirect_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=coinsub');
        
        // Build URL parameters
        $url_params = array();
        
        // Required/Main parameters
        $url_params['destinationCurrencyCodeLocked'] = 'USDC_POLYGON';
        $url_params['transactionType'] = 'BUY';
        $url_params['externalSessionId'] = $session_id;
        $url_params['redirectUrl'] = $redirect_url; // http_build_query will encode it automatically
        
        // Optional parameters
        if (!empty($meld_public_key)) {
            $url_params['publicKey'] = $meld_public_key;
        }
        if (!empty($wallet_address)) {
            $url_params['walletAddressLocked'] = $wallet_address;
        }
        if (!empty($amount)) {
            $url_params['sourceAmount'] = $amount;
        }
        
        // Build final URL
        $final_url = $meld_base_url . '/?' . http_build_query($url_params);
        
        return $final_url;
    }
    
    /**
     * Generate UUID v4
     */
    private function generate_uuid4() {
        // Generate UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10
        
        return sprintf('%08s-%04s-%04s-%04s-%12s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }
    
    /**
     * Get token symbol for currency
     */
    private function get_token_symbol_for_currency($currency) {
        $currency_token_map = array(
            'USD' => 'USDC',
            'EUR' => 'USDC', // Default to USDC for EUR
            'GBP' => 'USDC', // Default to USDC for GBP
            'CAD' => 'USDC', // Default to USDC for CAD
            'AUD' => 'USDC', // Default to USDC for AUD
            'JPY' => 'USDC', // Default to USDC for JPY
            'CHF' => 'USDC', // Default to USDC for CHF
            'CNY' => 'USDC', // Default to USDC for CNY
        );
        
        return isset($currency_token_map[$currency]) ? $currency_token_map[$currency] : 'USDC';
    }
    
    /**
     * Get network name for chain ID
     */
    private function get_network_name($chain_id) {
        $networks = array(
            '1' => 'Ethereum Mainnet',
            '137' => 'Polygon',
            '80002' => 'Polygon Amoy Testnet',
            '11155111' => 'Sepolia Testnet',
            '56' => 'BSC',
            '97' => 'BSC Testnet',
            '42161' => 'Arbitrum One',
            '421614' => 'Arbitrum Sepolia',
            '10' => 'Optimism',
            '420' => 'Optimism Sepolia',
            '8453' => 'Base',
            '84532' => 'Base Sepolia',
            '295' => 'Hedera Mainnet',
            '296' => 'Hedera Testnet'
        );
        
        return isset($networks[$chain_id]) ? $networks[$chain_id] : 'Chain ID ' . $chain_id;
    }

    /**
     * Override can_refund to always allow refunds for CoinSub orders
     */
    public function can_refund($order) {
        error_log('🔍 CoinSub Refund - can_refund() called for order #' . $order->get_id());
        error_log('🔍 CoinSub Refund - Order payment method: ' . $order->get_payment_method());
        error_log('🔍 CoinSub Refund - Order status: ' . $order->get_status());
        error_log('🔍 CoinSub Refund - Gateway supports: ' . json_encode($this->supports));
        
        // Always allow refunds for CoinSub orders that have been paid
        if ($order->get_payment_method() === 'coinsub') {
            $paid_statuses = array('processing', 'completed', 'on-hold');
            $can_refund = in_array($order->get_status(), $paid_statuses);
            error_log('🔍 CoinSub Refund - can_refund result: ' . ($can_refund ? 'YES' : 'NO'));
            return $can_refund;
        }
        
        // For other payment methods, use default behavior
        $result = parent::can_refund($order);
        error_log('🔍 CoinSub Refund - can_refund (parent) result: ' . ($result ? 'YES' : 'NO'));
        return $result;
    }


    /**
     * Validate the payment form
     */
    public function validate_fields() {
        return true;
    }
    
    /**
     * Get payment method title
     * CRITICAL: Returns "Stablecoin Pay" in admin, whitelabel name on checkout
     */
    public function get_title() {
        // In admin, always return "Stablecoin Pay" (no whitelabel)
        if (is_admin()) {
            return __('Stablecoin Pay', 'coinsub');
        }
        
        // On checkout (frontend), use whitelabel title if available
        if (!empty($this->checkout_title)) {
            return $this->checkout_title;
        }
        
        // Fallback to default
        return $this->title ?: __('Pay with Coinsub', 'coinsub');
    }
    
    /**
     * Get payment method icon
     * CRITICAL: Returns default CoinSub logo in admin, whitelabel logo on checkout
     */
    public function get_icon() {
        $icon_url = '';
        
        // Normalize company name once for all checks
        $normalized_company = !empty($this->brand_company) ? strtolower(str_replace(' ', '', $this->brand_company)) : '';
        
        // In admin, don't show CoinSub logo (whitelabel compatibility - logo only in checkout as default fallback)
        if (is_admin()) {
            $icon_url = ''; // No icon in admin
        } else {
            // SPECIAL CASE: Payment Servers - use local high-res PNG (300x300)
            if ($normalized_company === 'paymentservers') {
                $icon_url = COINSUB_PLUGIN_URL . 'images/paymentservers-logo.png';
                if (is_checkout()) {
                    error_log('CoinSub Whitelabel: 🖼️ 📌 Using LOCAL Payment Servers PNG logo (300x300): ' . $icon_url);
                }
            } else {
                // On checkout (frontend), use whitelabel icon if available
                $icon_url = !empty($this->checkout_icon) ? $this->checkout_icon : COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            }
        }
        
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
            error_log('CoinSub Whitelabel: 🖼️ get_icon() called - Context: CHECKOUT - Using icon URL: ' . $icon_url);
        }
        
        // Ensure we have a valid URL before creating HTML (only in checkout - admin should not show CoinSub logo)
        if (empty($icon_url) && !is_admin()) {
            // Fallback to CoinSub logo in checkout only (for non-whitelabel merchants)
            $icon_url = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
            error_log('CoinSub Whitelabel: ⚠️ Empty icon URL detected, using default');
        }
        
        // In admin, return empty if no icon (don't show CoinSub logo in admin)
        if (is_admin() && empty($icon_url)) {
            return '';
        }
        
        // Standard size for all payment methods (30px)
        $icon_size = '30px';
        
        if (is_checkout()) {
            error_log('CoinSub Whitelabel: 🖼️ Icon size: ' . $icon_size . ' for company: "' . $this->brand_company . '"');
        }
        
        $icon_html = '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($this->get_title()) . '" style="max-width: ' . $icon_size . '; max-height: ' . $icon_size . '; height: auto; vertical-align: middle; margin-left: 8px;" />';
        
        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }
    
    /**
     * Customize the payment button text
     * CRITICAL: Only used on checkout (frontend), uses whitelabel data
     */
    public function get_order_button_text() {
        // Get logo URL and company name from checkout-specific data
        $logo_url = !empty($this->button_logo_url) ? $this->button_logo_url : COINSUB_PLUGIN_URL . 'images/coinsub.svg';
        $company_name = !empty($this->button_company_name) ? $this->button_company_name : 'Coinsub';
        
        // If we have checkout title, extract company name from it
        if (!empty($this->checkout_title) && empty($this->button_company_name)) {
            // Extract company name from "Pay with CompanyName"
            if (preg_match('/Pay with (.+)/', $this->checkout_title, $matches)) {
                $company_name = $matches[1];
                $this->button_company_name = $company_name;
            }
        }
        
        // Use checkout icon if available
        if (!empty($this->checkout_icon)) {
            $logo_url = $this->checkout_icon;
            $this->button_logo_url = $logo_url;
        }
        
        error_log('CoinSub Whitelabel: 🔘 Button text (CHECKOUT) - Company: "' . $company_name . '" | Logo URL: ' . $logo_url);
        
        // Return text only - logo will be added via JavaScript
        return sprintf(__('Pay with %s', 'coinsub'), $company_name);
    }
    
    /**
     * Hide manual refund UI for CoinSub orders - only show CoinSub API refund
     * Works with both HPOS and traditional order storage
     */
    public function hide_manual_refund_ui_for_coinsub() {
        // Only run on order edit pages
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Check if we're on an order edit page (HPOS uses 'woocommerce_page_wc-orders', traditional uses 'shop_order')
        $is_order_page = ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order' || $screen->post_type === 'shop_order');
        
        if (!$is_order_page) {
            return;
        }
        
        // Get order ID - try HPOS first, then fallback to traditional
        $order_id = 0;
        if (isset($_GET['id'])) {
            $order_id = absint($_GET['id']); // HPOS uses ?id= in URL
        } elseif (isset($_GET['post'])) {
            $order_id = absint($_GET['post']); // Traditional uses ?post= in URL
        } elseif (isset($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            $order_id = absint($GLOBALS['post']->ID);
        }
        
        if (!$order_id) {
            // On order list page, just hide for all - JavaScript will check individual orders
            ?>
            <style type="text/css">
            /* Hide manual refund button globally - JavaScript will handle per-order */
            .woocommerce-order-refund .refund-actions .do-manual-refund,
            .woocommerce-order-refund .refund-actions button[class*="manual"],
            .woocommerce-order-refund .refund-actions a[class*="manual"],
            .woocommerce-order-refund .refund-actions input[value*="manual"],
            .woocommerce-order-refund .refund-actions input[type="radio"][value="manual"],
            .woocommerce-order-refund .refund-actions label[for*="manual"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                height: 0 !important;
                width: 0 !important;
                overflow: hidden !important;
            }
            </style>
            <?php
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'coinsub') {
            return;
        }
        
        // Add class to body so CSS only applies to CoinSub orders
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            $('body').addClass('coinsub-order-page');
        });
        </script>
        <style type="text/css">
        /* Completely hide manual refund button ONLY for CoinSub orders */
        body.coinsub-order-page .woocommerce-order-refund .refund-actions .do-manual-refund,
        body.coinsub-order-page .woocommerce-order-refund .refund-actions button[class*="manual"],
        body.coinsub-order-page .woocommerce-order-refund .refund-actions a[class*="manual"],
        body.coinsub-order-page .woocommerce-order-refund .refund-actions input[value*="manual"],
        body.coinsub-order-page .woocommerce-order-refund .refund-actions input[type="radio"][value="manual"],
        body.coinsub-order-page .woocommerce-order-refund .refund-actions label[for*="manual"],
        body.coinsub-order-page .woocommerce-order-refund .manual-refund-actions,
        body.coinsub-order-page .woocommerce-order-refund .refund-form .manual-refund {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            height: 0 !important;
            width: 0 !important;
            overflow: hidden !important;
        }
        
        /* Ensure automatic refund is selected by default for CoinSub orders */
        body.coinsub-order-page .woocommerce-order-refund input[type="radio"][value="api"]:checked,
        body.coinsub-order-page .woocommerce-order-refund .do-api-refund {
            display: inline-block !important;
        }
        </style>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Only run for CoinSub orders
            var paymentMethod = '<?php echo esc_js($order->get_payment_method()); ?>';
            if (paymentMethod !== 'coinsub') {
                return;
            }
            
            // Function to disable manual refund options
            function disableManualRefund() {
                var $section = $('.woocommerce-order-refund');
                if ($section.length === 0) return;
                
                // Completely hide manual refund button
                $section.find('.do-manual-refund, button.do-manual-refund, a.do-manual-refund').hide().remove();
                
                // Hide manual refund radio option and all related elements
                var $manualRadio = $section.find('input[type="radio"][value="manual"]');
                $manualRadio.closest('li, div, p, label, tr').hide().remove();
                
                // Hide any buttons with "manual" in text or class
                $section.find('button, a').each(function() {
                    var $btn = $(this);
                    var text = $btn.text().toLowerCase();
                    var classes = $btn.attr('class') || '';
                    if (text.indexOf('manual') !== -1 || classes.indexOf('manual') !== -1) {
                        $btn.hide().remove();
                    }
                });
                
                // Select automatic refund if available
                var apiRefund = $('.woocommerce-order-refund input[type="radio"][value="api"]');
                if (apiRefund.length && !apiRefund.is(':checked')) {
                    apiRefund.prop('checked', true).trigger('change');
                }
                
                // Inject notice if not present
                if ($section.find('.coinsub-manual-refund-disabled').length === 0) {
                    $section.find('.refund-actions').prepend('<div class="notice notice-warning coinsub-manual-refund-disabled" style="margin-bottom:8px;">⚠️ Manual refund is disabled for Stablecoin Pay payments. Use the API refund button.</div>');
                }
            }
            
            // Run immediately
            disableManualRefund();
            
            // Also run when refund modal/interface is opened
            $(document).on('click', '.refund-items', function() {
                setTimeout(disableManualRefund, 100);
            });
            
            // Watch for dynamically loaded content
            var observer = new MutationObserver(function(mutations) {
                disableManualRefund();
            });
            
            // Observe changes to the refund section
            var refundContainer = document.querySelector('.woocommerce-order-refund');
            if (refundContainer) {
                observer.observe(refundContainer, {
                    childList: true,
                    subtree: true
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * Additional JavaScript to hide manual refund button (runs in footer for better timing)
     * Works with both HPOS and traditional order storage
     */
    public function hide_manual_refund_js_for_coinsub() {
        // Only run on order edit pages
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Check if we're on an order edit page
        $is_order_page = ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order' || $screen->post_type === 'shop_order');
        
        if (!$is_order_page) {
            return;
        }
        
        // Get order ID - try HPOS first, then fallback to traditional
        $order_id = 0;
        if (isset($_GET['id'])) {
            $order_id = absint($_GET['id']);
        } elseif (isset($_GET['post'])) {
            $order_id = absint($_GET['post']);
        } elseif (isset($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            $order_id = absint($GLOBALS['post']->ID);
        }
        
        // If we have an order ID, check if it's CoinSub. Otherwise, JS will check dynamically
        $is_coinsub = false;
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_payment_method() === 'coinsub') {
                $is_coinsub = true;
            }
        }
        
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            // Check if this is a CoinSub order - only hide manual refund for CoinSub orders
            var isCoinsubOrder = <?php echo $is_coinsub ? 'true' : 'false'; ?>;
            var orderId = <?php echo $order_id ? absint($order_id) : 'null'; ?>;
            
            // Function to check if order is CoinSub (for dynamic content)
            function checkIfCoinsubOrder() {
                // If we already know it's CoinSub from PHP, use that
                if (isCoinsubOrder) {
                    return true;
                }
                
                // Try to find payment method from WooCommerce order data
                // WooCommerce stores this in various places - check them all
                var paymentMethod = '';
                
                // Method 1: Check order details meta box
                var $orderDetails = $('.woocommerce-order-data, .order_data_column, .woocommerce-order-items');
                if ($orderDetails.length > 0) {
                    var orderText = $orderDetails.text().toLowerCase();
                    if (orderText.indexOf('coinsub') !== -1) {
                        return true;
                    }
                }
                
                // Method 2: Check if there's a "Refund via CoinSub" button - if so, it's CoinSub
                if ($('.button.refund-items[data-refund-id], button.do-api-refund').length > 0) {
                    // Check if gateway is coinsub by looking for gateway-specific elements
                    var $gatewayElements = $('[data-gateway="coinsub"], [data-payment-method="coinsub"]');
                    if ($gatewayElements.length > 0) {
                        return true;
                    }
                }
                
                // Method 3: Check order edit form fields
                var $paymentField = $('select[name*="payment_method"], input[name*="payment_method"], .payment_method');
                if ($paymentField.length > 0) {
                    $paymentField.each(function() {
                        var val = $(this).val() || $(this).text() || '';
                        if (val.toLowerCase() === 'coinsub') {
                            return true;
                        }
                    });
                }
                
                return false;
            }
            
            // Simple aggressive approach: Remove manual refund buttons ONLY for CoinSub orders
            function hideManualRefundButtons() {
                // Only hide if this is a CoinSub order
                if (!checkIfCoinsubOrder()) {
                    return; // Not a CoinSub order - leave manual refund buttons alone
                }
                
                // Remove all manual refund buttons and radios
                $('.do-manual-refund, button.do-manual-refund, a.do-manual-refund').hide().remove();
                
                // Remove manual refund radio buttons and their containers
                $('input[type="radio"][value="manual"], input[type="radio"][id*="manual"], input[type="radio"][name*="manual"]').each(function() {
                    $(this).closest('li, div, p, label, tr, td').hide().remove();
                });
                
                // Remove any buttons with "manual refund" in text or class
                $('.woocommerce-order-refund, #woocommerce-order-refund, .refund-actions').find('button, a, input[type="button"]').each(function() {
                    var $btn = $(this);
                    var text = ($btn.text() || '').toLowerCase();
                    var classes = ($btn.attr('class') || '').toLowerCase();
                    if ((text.indexOf('manual') !== -1 && text.indexOf('refund') !== -1) || classes.indexOf('manual') !== -1) {
                        $btn.hide().remove();
                    }
                });
            }
            
            // Run immediately and repeatedly
            hideManualRefundButtons();
            setInterval(hideManualRefundButtons, 500); // Run every 500ms to catch dynamic content
            
            // Watch for refund section opening
            $(document).on('click', '.refund-items, #refund-items, button[data-action="refund"]', function() {
                setTimeout(hideManualRefundButtons, 50);
                setTimeout(hideManualRefundButtons, 200);
                setTimeout(hideManualRefundButtons, 500);
            });
            
            // Watch for AJAX completion
            $(document).ajaxComplete(function() {
                setTimeout(hideManualRefundButtons, 50);
            });
            
            // Use MutationObserver for dynamically added content
            if (typeof MutationObserver !== 'undefined') {
                var observer = new MutationObserver(function() {
                    hideManualRefundButtons();
                });
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        });
        </script>
        <?php
    }
    
    // All interception methods removed - using simple CSS/JS approach only
    
    /**
     * Customize refund meta key display (if needed)
     */
    public function customize_refund_meta_key($display_key, $meta, $order) {
        // Be defensive: $order can be a WC_Order, item, or other context in email templates
        if (is_object($order) && method_exists($order, 'get_payment_method')) {
            if ($order->get_payment_method() === 'coinsub') {
                // Customize any refund-related meta keys if needed
            }
        }
        return $display_key;
    }
    
    /**
     * Add custom CSS for the payment button
     */
    public function add_payment_button_styles() {
        if (is_checkout()) {
            ?>
            <style>
            /* Force display CoinSub payment method */
            .payment_method_coinsub {
                display: list-item !important;
                visibility: visible !important;
                opacity: 1 !important;
                position: relative !important;
            }
            
            /* Hide the payment box - we don't need it */
            .woocommerce-checkout .payment_method_coinsub .payment_box {
                display: none !important;
            }
            
            /* Style the "Place Order" button when Coinsub is selected */
            .payment_method_coinsub input[type="radio"]:checked ~ #place_order,
            body.woocommerce-checkout.payment_method_coinsub #place_order {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
                border: none !important;
                color: white !important;
                font-weight: bold !important;
                font-size: 18px !important;
                padding: 15px 30px !important;
                border-radius: 8px !important;
                text-transform: uppercase !important;
                letter-spacing: 1px !important;
                box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4) !important;
                transition: all 0.3s ease !important;
            }
            
            body.woocommerce-checkout.payment_method_coinsub #place_order:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 20px rgba(59, 130, 246, 0.6) !important;
            }
            </style>
            <script>
            // Simple debugging - no complex workarounds needed since you're using traditional checkout
            jQuery(document).ready(function($) {
                console.log('✅ Coinsub payment gateway loaded');
                
                // Get logo URL and company name from PHP
                var coinsubLogoUrl = '<?php echo esc_js($this->button_logo_url); ?>';
                var coinsubCompanyName = '<?php echo esc_js($this->button_company_name); ?>';
                
                // Function to inject logo into button
                function injectButtonLogo() {
                    var $button = $('#place_order');
                    if ($button.length === 0) return;
                    
                    // Only inject if CoinSub is selected
                    var selectedMethod = $('input[name="payment_method"]:checked').val();
                    if (selectedMethod !== 'coinsub') {
                        // Remove logo if another method is selected
                        $button.find('img.coinsub-button-logo').remove();
                        return;
                    }
                    
                    // Check if logo already injected
                    if ($button.find('img.coinsub-button-logo').length > 0) {
                        return;
                    }
                    
                    // Inject logo if we have a URL
                    if (coinsubLogoUrl && coinsubLogoUrl.trim() !== '') {
                        var $logo = $('<img>', {
                            src: coinsubLogoUrl,
                            alt: coinsubCompanyName || 'Coinsub',
                            class: 'coinsub-button-logo',
                            css: {
                                'max-width': '20px',
                                'height': 'auto',
                                'vertical-align': 'middle',
                                'margin-right': '8px',
                                'display': 'inline-block'
                            }
                        });
                        
                        // Prepend logo to button text
                        var buttonText = $button.html();
                        // Remove existing logo if any
                        buttonText = buttonText.replace(/<img[^>]*class="coinsub-button-logo"[^>]*>/gi, '');
                        $button.html($logo[0].outerHTML + buttonText);
                        
                        console.log('✅ CoinSub logo injected into button:', coinsubLogoUrl);
                    }
                }
                
                // Inject logo on page load
                injectButtonLogo();
                
                // Style the Place Order button when Coinsub is selected
                $('input[name="payment_method"]').on('change', function() {
                    var selectedMethod = $(this).val();
                    if (selectedMethod === 'coinsub') {
                        console.log('✅ Coinsub selected');
                        $('body').addClass('payment_method_coinsub');
                        // Inject logo when CoinSub is selected
                        setTimeout(injectButtonLogo, 100);
                    } else {
                        $('body').removeClass('payment_method_coinsub');
                        // Remove logo when another method is selected
                        $('#place_order').find('img.coinsub-button-logo').remove();
                    }
                });
                
                // Check initial state
                var initialMethod = $('input[name="payment_method"]:checked').val();
                if (initialMethod === 'coinsub') {
                    $('body').addClass('payment_method_coinsub');
                }
            });
            </script>
            <?php
        }
    }
    
    /**
     * Add refund transaction hash (for manual refunds)
     */
    public function add_refund_transaction_hash($order_id, $transaction_hash) {
        $order = wc_get_order($order_id);
        
        if ($order) {
            $order->add_order_note(__('Refund processed', 'coinsub'));
            $order->update_meta_data('_refund_transaction_hash', $transaction_hash);
            $order->save();
        }
    }
    
    /**
     * Get refund instructions for merchants
     */
    public function get_refund_instructions() {
        return array(
            'title' => __('Manual Refund Process', 'coinsub'),
            'steps' => array(
                __('1. Customer requests refund', 'coinsub'),
                __('2. Approve refund in WooCommerce', 'coinsub'),
                __('3. Open your crypto wallet (MetaMask, etc.)', 'coinsub'),
                __('4. Send crypto back to customer wallet address', 'coinsub'),
                __('5. Update order status to "Refunded"', 'coinsub'),
            ),
            'note' => __('Remember: You pay gas fees for the refund transaction', 'coinsub')
        );
    }
    
    
    /**
     * Check if gateway needs setup
     */
    public function needs_setup() {
        $needs_setup = empty($this->get_option('merchant_id'));
        error_log('🔧 CoinSub - needs_setup() called. Result: ' . ($needs_setup ? 'YES' : 'NO'));
        return $needs_setup;
    }
    
    /**
     * Check if the gateway is available
     */
    public function is_available() {
        // Only log availability checks on checkout page (not admin) to reduce log noise
        $context = is_checkout() ? 'CHECKOUT PAGE' : (is_admin() ? 'ADMIN' : 'OTHER');
        
        // Only log detailed debug info on checkout page, not admin
        if (is_checkout()) {
        error_log('=== CoinSub Gateway - Availability Check [' . $context . '] ===');
        error_log('CoinSub - Enabled setting: ' . $this->get_option('enabled'));
        error_log('CoinSub - Merchant ID: ' . $this->get_option('merchant_id'));
        error_log('CoinSub - API Key exists: ' . (!empty($this->get_option('api_key')) ? 'Yes' : 'No'));
        }
        
        // Check cart (only on frontend)
        if (!is_admin() && WC()->cart) {
            error_log('CoinSub - Cart total: $' . WC()->cart->get_total('edit'));
            error_log('CoinSub - Cart has items: ' . (WC()->cart->get_cart_contents_count() > 0 ? 'YES' : 'NO'));
            error_log('CoinSub - Cart currency: ' . get_woocommerce_currency());
            
            if (WC()->cart->needs_shipping()) {
                error_log('CoinSub - Cart needs shipping: YES');
                
                // Check if shipping is chosen
                $chosen_shipping = WC()->session ? WC()->session->get('chosen_shipping_methods') : array();
                error_log('CoinSub - Chosen shipping methods: ' . json_encode($chosen_shipping));
                
                // Check if customer has entered shipping info
                $customer = WC()->customer;
                if ($customer) {
                    error_log('CoinSub - Customer country: ' . $customer->get_shipping_country());
                    error_log('CoinSub - Customer postcode: ' . $customer->get_shipping_postcode());
                }
            } else {
                error_log('CoinSub - Cart needs shipping: NO');
            }
        }
        
        // Check if this is actually the checkout page context
        if (is_checkout() && !is_wc_endpoint_url('order-pay')) {
            error_log('CoinSub - Context: Regular checkout page ✅');
        } elseif (is_wc_endpoint_url('order-pay')) {
            error_log('CoinSub - Context: Order pay page');
        }
        
        // Basic validation - always check these first
        if ($this->get_option('enabled') !== 'yes') {
            // Only log on checkout, not in admin (reduces log noise)
            if (is_checkout()) {
            error_log('CoinSub - UNAVAILABLE: Gateway is disabled in settings ❌');
            }
            return false;
        }
        
        if (empty($this->get_option('merchant_id'))) {
            // Only log on checkout, not in admin (reduces log noise)
            if (is_checkout()) {
            error_log('CoinSub - UNAVAILABLE: No merchant ID configured ❌');
            }
            return false;
        }
        
        if (empty($this->get_option('api_key'))) {
            // Only log on checkout, not in admin (reduces log noise)
            if (is_checkout()) {
            error_log('CoinSub - UNAVAILABLE: No API key configured ❌');
            }
            return false;
        }
        
        // Call parent method to ensure WooCommerce core checks pass
        $parent_available = parent::is_available();
        // Only log on checkout, not in admin
        if (is_checkout()) {
        error_log('CoinSub - Parent is_available(): ' . ($parent_available ? 'TRUE' : 'FALSE'));
        }
        
        if (!$parent_available) {
            // Only log on checkout, not in admin (reduces log noise)
            if (is_checkout()) {
            error_log('CoinSub - UNAVAILABLE: Parent class returned false (WooCommerce core filtering) ❌');
            error_log('CoinSub - Common reasons: cart empty, order total 0, shipping required but not selected, terms & conditions page not set');
            
            // Check specifically for terms & conditions issue
            $terms_page_id = wc_get_page_id('terms');
            if (empty($terms_page_id)) {
                error_log('CoinSub - DIAGNOSIS: Terms & Conditions page is not set! This often blocks payment gateways.');
                error_log('CoinSub - SOLUTION: Set a Terms & Conditions page in WooCommerce > Settings > Advanced');
                }
            }
            
            return false;
        }
        
        // Only log on checkout, not in admin
        if (is_checkout()) {
        error_log('CoinSub - AVAILABLE: Gateway ready for checkout! ✅✅✅');
        }
        return true;
    }
    
    /**
     * Simple function: Got payment? Redirect to orders page outside modal
     */
    public function redirect_after_payment() {
        // Get the most recent order
        $user_id = get_current_user_id();
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => array('completed'),
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (!empty($orders)) {
            $order = $orders[0];
            $redirect_url = $order->get_checkout_order_received_url();
            
            error_log('🎯 CoinSub - Payment completed! Redirecting to: ' . $redirect_url);
            
            // Return redirect URL for JavaScript to use
            return array(
                'success' => true,
                'redirect_url' => $redirect_url,
                'order_id' => $order->get_id()
            );
        }
        
        return array(
            'success' => false,
            'message' => 'No completed orders found'
        );
    }
    
    /**
     * AJAX handler for redirect after payment
     */
    public function redirect_after_payment_ajax() {
        $result = $this->redirect_after_payment();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Calculate cart totals from WooCommerce cart
     * Includes discounts and coupons
     */
    private function calculate_cart_totals() {
        $cart = WC()->cart;
        
        $subtotal = (float) $cart->get_subtotal();
        $shipping = (float) $cart->get_shipping_total();
        $tax = (float) $cart->get_total_tax();
        
        // Get discount information (coupons/discounts)
        $discount_total = (float) $cart->get_discount_total();
        $discount_tax = (float) $cart->get_discount_tax();
        $applied_coupons = $cart->get_applied_coupons();
        
        // Get coupon details
        $coupon_details = array();
        if (!empty($applied_coupons)) {
            foreach ($applied_coupons as $coupon_code) {
                $coupon = new WC_Coupon($coupon_code);
                if ($coupon->get_id()) {
                    $coupon_discount = $cart->get_coupon_discount_amount($coupon_code, $cart->display_prices_including_tax());
                    $coupon_details[] = array(
                        'code' => $coupon_code,
                        'discount' => (float) $coupon_discount,
                        'type' => $coupon->get_discount_type(),
                        'description' => $coupon->get_description()
                    );
                }
            }
        }
        
        // Get cart fees (additional charges like handling fees, processing fees, etc.)
        $fees = $cart->get_fees();
        $fee_total = 0;
        $fee_details = array();
        if (!empty($fees)) {
            foreach ($fees as $fee) {
                $fee_amount = (float) $fee->amount;
                $fee_total += $fee_amount;
                $fee_details[] = array(
                    'name' => $fee->name,
                    'amount' => $fee_amount,
                    'taxable' => $fee->taxable,
                    'tax_class' => $fee->tax_class
                );
            }
        }
        
        // Calculate final total (WooCommerce already applies discounts and fees to get_total())
        $total = (float) $cart->get_total('edit');
        
        // Ensure total is never 0
        if ($total <= 0) {
            $total = $subtotal > 0 ? $subtotal : 0.01; // Minimum $0.01
        }
        
        // Check if cart contains subscription
        $has_subscription = false;
        $subscription_data = null;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $is_sub = $product->get_meta('_coinsub_subscription') === 'yes';
            
            if ($is_sub) {
                $has_subscription = true;
                $subscription_data = array(
                    'frequency' => $product->get_meta('_coinsub_frequency'),
                    'interval' => $product->get_meta('_coinsub_interval'),
                    'duration' => $product->get_meta('_coinsub_duration')
                );
                break;
            }
        }
        
        return array(
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'tax' => $tax,
            'discount' => $discount_total,
            'discount_tax' => $discount_tax,
            'fees' => $fee_total,
            'fee_details' => $fee_details,
            'total' => $total,
            'currency' => get_woocommerce_currency(),
            'has_subscription' => $has_subscription,
            'subscription_data' => $subscription_data,
            'applied_coupons' => $applied_coupons,
            'coupon_details' => $coupon_details,
            'items' => $this->get_cart_items_data()
        );
    }
    
    /**
     * Get cart items data for purchase session
     * Includes discount information for each item
     */
    private function get_cart_items_data() {
        $items = array();
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            // Get original price and discounted price
            $original_price = (float) $product->get_price();
            $line_subtotal = (float) $cart_item['line_subtotal']; // Price before discount
            $line_total = (float) $cart_item['line_total']; // Price after discount
            $line_discount = $line_subtotal - $line_total; // Discount amount for this line
            
            $items[] = array(
                'name' => $product->get_name(),
                'quantity' => $cart_item['quantity'],
                'price' => $original_price,
                'line_subtotal' => $line_subtotal, // Before discount
                'line_total' => $line_total, // After discount (what customer pays)
                'line_discount' => $line_discount, // Discount amount
                'total' => $line_total // Alias for backward compatibility
            );
        }
        
        return $items;
    }
    
    /**
     * Prepare purchase session data from cart (WooCommerce-only approach)
     */
    private function prepare_purchase_session_from_cart($order, $cart_data) {
        // We'll store the purchase session ID after we get it from CoinSub API
        // For now, just prepare the session data
        
        // Store subscription info in order meta
        if ($cart_data['has_subscription']) {
            $order->update_meta_data('_coinsub_is_subscription', 'yes');
            $order->update_meta_data('_coinsub_subscription_data', $cart_data['subscription_data']);
        } else {
            $order->update_meta_data('_coinsub_is_subscription', 'no');
        }
        
        // Store cart items in order meta
        $order->update_meta_data('_coinsub_cart_items', $cart_data['items']);
        $order->save();
        
        // Prepare purchase session data
        $session_data = array(
            'name' => 'Order #' . $order->get_id(),
            'details' => $this->get_order_details_text($order, $cart_data),
            'currency' => $cart_data['currency'],
            'amount' => $cart_data['total'],
            'recurring' => $cart_data['has_subscription'],
            'metadata' => array(
                'woocommerce_order_id' => $order->get_id(),
                'cart_items' => $cart_data['items'],
                'subtotal' => $cart_data['subtotal'],
                'shipping' => $cart_data['shipping'],
                'tax' => $cart_data['tax'],
                'discount' => isset($cart_data['discount']) ? $cart_data['discount'] : 0,
                'discount_tax' => isset($cart_data['discount_tax']) ? $cart_data['discount_tax'] : 0,
                'fees' => isset($cart_data['fees']) ? $cart_data['fees'] : 0,
                'fee_details' => isset($cart_data['fee_details']) ? $cart_data['fee_details'] : array(),
                'applied_coupons' => isset($cart_data['applied_coupons']) ? $cart_data['applied_coupons'] : array(),
                'coupon_details' => isset($cart_data['coupon_details']) ? $cart_data['coupon_details'] : array(),
                'total' => $cart_data['total'],
                'billing_address' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                    'address_1' => $order->get_billing_address_1(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country()
                ),
                'shipping_address' => array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name' => $order->get_shipping_last_name(),
                    'address_1' => $order->get_shipping_address_1(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country()
                )
            ),
            'success_url' => $this->get_return_url($order),
            'cancel_url' => wc_get_checkout_url(),
            'failure_url' => wc_get_checkout_url()
        );
        
        // Add subscription fields if recurring
        if ($cart_data['has_subscription'] && $cart_data['subscription_data']) {
            $freq = $cart_data['subscription_data']['frequency'];
            $intr = $cart_data['subscription_data']['interval'];
            $dur = $cart_data['subscription_data']['duration'];

            // Map frequency number -> label (example expects labels like "Every", "Every Other")
            $frequency_map = array(
                '1' => 'Every',
                '2' => 'Every Other',
                '3' => 'Every Third',
                '4' => 'Every Fourth',
                '5' => 'Every Fifth',
                '6' => 'Every Sixth',
                '7' => 'Every Seventh',
            );
            $freq_label = isset($frequency_map[(string)$freq]) ? $frequency_map[(string)$freq] : 'Every';

            // Normalize interval to Capitalized label for API (Day/Week/Month/Year) per working example
            $interval_cap_map = array(
                '0' => 'Day', 'day' => 'Day', 'Day' => 'Day',
                '1' => 'Week', 'week' => 'Week', 'Week' => 'Week',
                '2' => 'Month', 'month' => 'Month', 'Month' => 'Month',
                '3' => 'Year', 'year' => 'Year', 'Year' => 'Year',
            );
            $intr_key = (string) $intr;
            $intr_key = isset($interval_cap_map[$intr_key]) ? $intr_key : strtolower(trim($intr_key));
            $intr_out = isset($interval_cap_map[$intr_key]) ? $interval_cap_map[$intr_key] : 'Month';

            // Build payload matching the working example
            $session_data['frequency'] = $freq_label;          // e.g., "Every"
            $session_data['interval'] = $intr_out;             // e.g., "Week"
            $session_data['Duration'] = (string) $dur;         // capital D per example
            $session_data['duration'] = (string) $dur;         // keep lowercase for backward compat
            $session_data['metadata']['subscription_data'] = $cart_data['subscription_data'];
        }
        
        return $session_data;
    }
    
    /**
     * Get order details text for purchase session
     * Includes discount information if applicable
     */
    private function get_order_details_text($order, $cart_data) {
        $details = array();
        
        foreach ($cart_data['items'] as $item) {
            // Use discounted price if available, otherwise use original price
            $item_price = isset($item['line_total']) ? $item['line_total'] : (isset($item['total']) ? $item['total'] : $item['price']);
            $details[] = $item['quantity'] . 'x ' . $item['name'] . ' ($' . number_format($item_price, 2) . ')';
        }
        
        // Add discount information if coupons were applied
        if (isset($cart_data['discount']) && $cart_data['discount'] > 0) {
            $discount_text = 'Discount: -$' . number_format($cart_data['discount'], 2);
            if (isset($cart_data['applied_coupons']) && !empty($cart_data['applied_coupons'])) {
                $discount_text .= ' (' . implode(', ', $cart_data['applied_coupons']) . ')';
            }
            $details[] = $discount_text;
        }
        
        // Add fees if any
        if (isset($cart_data['fees']) && $cart_data['fees'] > 0) {
            if (isset($cart_data['fee_details']) && !empty($cart_data['fee_details'])) {
                foreach ($cart_data['fee_details'] as $fee) {
                    $details[] = $fee['name'] . ': $' . number_format($fee['amount'], 2);
                }
            } else {
                $details[] = 'Fees: $' . number_format($cart_data['fees'], 2);
            }
        }
        
        if ($cart_data['shipping'] > 0) {
            $details[] = 'Shipping: $' . number_format($cart_data['shipping'], 2);
        }
        
        if ($cart_data['tax'] > 0) {
            $details[] = 'Tax: $' . number_format($cart_data['tax'], 2);
        }
        
        return implode(', ', $details);
    }
    
    /**
     * Ensure WooCommerce checkout page has [woocommerce_checkout] shortcode
     * Called automatically when gateway settings are saved
     * Only runs if gateway is being enabled
     */
    public function ensure_checkout_shortcode_on_save() {
        // Check if gateway is being enabled (from POST or current setting)
        $enabled = isset($_POST['woocommerce_coinsub_enabled']) 
            ? sanitize_text_field($_POST['woocommerce_coinsub_enabled']) 
            : $this->enabled;
        
        if ($enabled === 'yes') {
            // Call the function from main plugin file
            if (function_exists('coinsub_ensure_checkout_shortcode')) {
                coinsub_ensure_checkout_shortcode();
            }
        }
    }
    
    /**
     * Cart restoration DISABLED
     * 
     * Previously attempted to restore cart from pending order when user returns to checkout.
     * DISABLED because:
     * 1. Checkout URLs are one-time use only
     * 2. We clear session when user leaves checkout page
     * 3. User should get fresh order and purchase session on return
     * 4. Prevents reuse of expired purchase sessions
     * 
     * If cart restoration is needed in future, it would require:
     * - NOT clearing coinsub_pending_order_id when user leaves checkout page
     * - Keeping checkout URL valid for reuse (which defeats one-time use requirement)
     */
    public function maybe_restore_cart_from_pending_order() {
        // Cart restoration disabled - user gets fresh checkout each time
        // This prevents reuse of one-time purchase session URLs
        return;
    }
}
