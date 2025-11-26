<?php
/**
 * CoinSub Payment Gateway
 * 
 * Simple cryptocurrency payment gateway for WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_CoinSub extends WC_Payment_Gateway {
    
    private $api_client;
    private $brand_company = ''; // No default - will be set from branding API
    
    /**
     * Constructor
     */
    public function __construct() {
        error_log('🏗️ Coinsub - Gateway constructor called');
        
        $this->id = 'coinsub';
        $this->icon = COINSUB_PLUGIN_URL . 'images/coinsub.png';
        $this->has_fields = true; // Enable custom payment box
        $this->method_title = __('Coinsub', 'coinsub');
        $this->method_description = __('Accept Crypto payments with Coinsub', 'coinsub');
        
        // Declare supported features
        $this->supports = array(
            'products',
            'refunds'
        );
        
        error_log('🏗️ Coinsub - Supports: ' . json_encode($this->supports));
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Get settings for debugging
        $this->title = 'Pay with Coinsub';
        $this->description = '';
        $this->enabled = $this->get_option('enabled', 'yes');
        
        // Initialize API client
        $this->api_client = new CoinSub_API_Client();
        
        // Load whitelabel branding from cache only (no API calls)
        // API calls only happen when settings are saved
        $this->load_whitelabel_branding(false);
        
        error_log('🏗️ CoinSub - Constructor - ID: ' . $this->id);
        error_log('🏗️ CoinSub - Constructor - Title: ' . $this->title);
        error_log('🏗️ CoinSub - Constructor - Description: ' . $this->description);
        error_log('🏗️ CoinSub - Constructor - Enabled: ' . $this->enabled);
        error_log('🏗️ CoinSub - Constructor - Merchant ID: ' . $this->get_option('merchant_id'));
        error_log('🏗️ CoinSub - Constructor - Method Title: ' . $this->method_title);
        error_log('🏗️ CoinSub - Constructor - Has fields: ' . ($this->has_fields ? 'YES' : 'NO'));
        
        // Add hooks
        // Hook into settings save - this is the standard WooCommerce way
        // Priority 99 to run after settings are saved
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'update_api_client_settings'), 99);
        
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        add_action('wp_footer', array($this, 'add_checkout_script'));
        add_action('wp_head', array($this, 'add_payment_button_styles'));
        add_filter('woocommerce_order_button_text', array($this, 'get_order_button_text'));
        
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
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?></h2>
        <p><?php echo esc_html($this->get_method_description()); ?></p>
        
        <div style="background: #f9fafb; border-left: 4px solid #3b82f6; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0;">📋 Setup Instructions</h3>
            
            <h4>Step 1: Select Environment & Get Your Coinsub Credentials</h4>
            <ol style="line-height: 1.8;">
                <li>Select your <strong>Environment</strong> from the dropdown below (Development, Test, Staging, or Production)</li>
                <li>Go to the appropriate Coinsub environment URL and sign up or log in</li>
                <li>Navigate to <strong>Settings</strong> in your Coinsub dashboard</li>
                <li>Copy your <strong>Merchant ID</strong></li>
                <li>Create and copy your <strong>API Key</strong></li>
                <li>Paste both into the fields below</li>
            </ol>
            
            <h4>Step 2: Configure Webhook (CRITICAL)</h4>
            <ol style="line-height: 1.8;">
                <li>Copy the <strong>Webhook URL</strong> shown below (it will look like: <code>https://yoursite.com/wp-json/coinsub/v1/webhook</code>)</li>
                <li>Go back to your Coinsub dashboard <strong>Settings</strong></li>
                <li>Find the <strong>Webhook URL</strong> field</li>
                <li><strong>Paste your webhook URL</strong> into that field and save</li>
                <li><em>This is essential - without this, orders won't update when payments complete!</em></li>
            </ol>
            
            <h4>Step 3: Fix WordPress Checkout Page</h4>
            <ol style="line-height: 1.8;">
                <li>Go to <strong>Pages</strong> → Find your <strong>Checkout</strong> page → Click <strong>Edit</strong></li>
                <li>In the page editor, click the <strong>⋮</strong> (three vertical dots) in the top right</li>
                <li>Select <strong>Code Editor</strong></li>
                <li>Replace any block content with: <code>[woocommerce_checkout]</code></li>
                <li>Click <strong>Update</strong> to save</li>
            </ol>
            
            <h4>Step 4: Remove Payment Blocks (Important)</h4>
            <ol style="line-height: 1.8;">
                <li>In the page editor, click the <strong>⋮</strong> (three vertical dots) again</li>
                <li>Select <strong>Preferences</strong></li>
                <li>In the search bar, type <strong>"payments"</strong></li>
                <li><strong>Uncheck all blocks related to payments</strong></li>
                <li>Close preferences and update the page</li>
            </ol>
            
            <h4>Step 5: Enable Coinsub</h4>
            <ol style="line-height: 1.8;">
                <li>Check the <strong>"Enable Coinsub Crypto Payments"</strong> box below</li>
                <li>Click <strong>Save changes</strong></li>
                <li>Done! Customers will now see "Pay with Coinsub" at checkout</li>
            </ol>
            
            <p style="margin-bottom: 0; padding: 10px; background: #fef3c7; border-radius: 4px;"><strong>⚠️ Important:</strong> Coinsub works alongside other payment methods. Make sure to complete ALL steps above, especially the webhook configuration!</p>
            
            <div style="margin-top: 20px; padding: 15px; background: #e0f2fe; border-left: 4px solid #0284c7; border-radius: 4px;">
                <h3 style="margin-top: 0;">💰 Add USDC Polygon for Refunds</h3>
                <p><strong>All refunds are processed as USDC on Polygon.</strong></p>
                <p>To process refunds, you'll need USDC tokens on the Polygon network in your merchant wallet.</p>
                <p style="margin-bottom: 10px;">
                    <a href="<?php echo esc_url($this->get_meld_onramp_url()); ?>" target="_blank" class="button button-primary" style="background: #0284c7; border-color: #0284c7;">
                        💳 Onramp USDC Polygon via Meld
                    </a>
                </p>
                <p style="margin-bottom: 0; font-size: 12px; color: #666;">
                    💡 <strong>Tip:</strong> Keep a small reserve of USDC on Polygon to cover refunds quickly. Click the button above to add funds via Meld.
                </p>
            </div>
        </div>
        
        <table class="form-table">
        <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
    

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        error_log('🏗️ CoinSub - init_form_fields() called');
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'coinsub'),
                'type' => 'checkbox',
                'label' => __('Enable Coinsub Crypto Payments', 'coinsub'),
                'default' => 'no'
            ),
            // Environment selection removed for production plugin; base URL fixed to dev-api in code
            'merchant_id' => array(
                'title' => __('Merchant ID', 'coinsub'),
                'type' => 'text',
                'description' => __('Get this from your Coinsub merchant dashboard', 'coinsub'),
                'default' => '',
                'placeholder' => 'e.g., 12345678-abcd-1234-abcd-123456789abc',
                'required' => true,
            ),
            'api_key' => array(
                'title' => __('API Key', 'coinsub'),
                'type' => 'password',
                'description' => __('Get this from your Coinsub merchant dashboard', 'coinsub'),
                'default' => '',
                'required' => true,
            ),
            'webhook_url' => array(
                'title' => __('Webhook URL', 'coinsub'),
                'type' => 'text',
                'description' => __('Copy this URL and add it to your Coinsub merchant dashboard. This URL receives payment confirmations and automatically updates order status to "Processing" when payment is complete.', 'coinsub'),
                'default' => (function() {
                    $secret = get_option('coinsub_webhook_secret');
                    $base = home_url('/wp-json/coinsub/v1/webhook');
                    return $secret ? add_query_arg('secret', $secret, $base) : $base;
                })(),
                'custom_attributes' => array('readonly' => 'readonly'),
                'css' => 'background: #f0f0f0;',
            ),
            
        );
    }
    
    /**
     * Get API base URL based on selected environment
     */
    public function get_api_base_url() {
        return 'https://dev-api.coinsub.io/v1';
    }
    
    /**
     * Load whitelabel branding and update gateway display
     */
    /**
     * Load whitelabel branding
     * 
     * @param bool $force_refresh If true, force API call to refresh branding. If false, use cache only.
     */
    private function load_whitelabel_branding($force_refresh = false) {
        error_log('CoinSub Whitelabel: Loading branding (force_refresh: ' . ($force_refresh ? 'yes' : 'no') . ')...');
        $branding = new CoinSub_Whitelabel_Branding();
        $branding_data = $branding->get_branding($force_refresh);
        
        // Only update if branding data exists and has company name (no default)
        if (!empty($branding_data) && isset($branding_data['company']) && !empty($branding_data['company'])) {
            $company_name = $branding_data['company'];
            $this->brand_company = $company_name;
            $this->title = 'Pay with ' . $company_name;
            
            error_log('CoinSub Whitelabel: ✅ NAME SET - Title: "' . $this->title . '" | Company: "' . $company_name . '" | brand_company property: "' . $this->brand_company . '"');
            
            // Update icon with whitelabel logo (use default light logo)
            $logo_url = $branding->get_logo_url('default', 'light');
            if ($logo_url) {
                $this->icon = $logo_url;
                error_log('CoinSub Whitelabel: Set icon to: ' . $logo_url);
            }
        } else {
            // No branding found - don't set title/icon (gateway will use its default or be hidden)
            error_log('CoinSub Whitelabel: ⚠️ No branding data found - not updating title/icon (no default)');
            $this->brand_company = '';
            // Keep title as "Coinsub" (the method_title) - don't set "Pay with" if no branding
        }
    }
    
    /**
     * Update API client settings when gateway settings are saved
     * This can be called by:
     * 1. process_admin_options() override (when WooCommerce saves settings)
     * 2. Direct hook: woocommerce_update_options_payment_gateways_coinsub
     */
    public function update_api_client_settings() {
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub Whitelabel: 🔔🔔🔔 SETTINGS SAVE DETECTED! 🔔🔔🔔');
        error_log('CoinSub Whitelabel: update_api_client_settings() CALLED');
        error_log('═══════════════════════════════════════════════════════════');
        
        $merchant_id = $this->get_option('merchant_id', '');
        $api_key = $this->get_option('api_key', '');
        $api_base_url = 'https://dev-api.coinsub.io/v1';
        
        error_log('CoinSub Whitelabel: 📝 Settings - Merchant ID: ' . $merchant_id);
        error_log('CoinSub Whitelabel: 📝 Settings - API Key: ' . (strlen($api_key) > 0 ? substr($api_key, 0, 10) . '...' : 'EMPTY'));
        error_log('CoinSub Whitelabel: 📝 Settings - API Base URL: ' . $api_base_url);
        
        $this->api_client->update_settings($api_base_url, $merchant_id, $api_key);
        
        // Clear whitelabel branding cache when credentials change
        error_log('CoinSub Whitelabel: ⚙️ Settings saved - Clearing cache and fetching fresh branding from API');
        $branding = new CoinSub_Whitelabel_Branding();
        $branding->clear_cache();
        
        // Reload branding with force refresh (only when settings are saved)
        // This will make API calls to get submerchant data and environment configs
        error_log('CoinSub Whitelabel: 🔄 Calling load_whitelabel_branding(true) to fetch from API...');
        $this->load_whitelabel_branding(true);
        
        // Log the result
        error_log('CoinSub Whitelabel: ✅ Branding refresh complete. Title: ' . $this->title . ' | Company: ' . $this->brand_company);
    }
    
    /**
     * Override process_admin_options to ensure our method is called
     * This is called automatically by WooCommerce when settings are saved
     */
    public function process_admin_options() {
        error_log('CoinSub Whitelabel: 🔔🔔🔔 process_admin_options() CALLED - Settings are being saved! 🔔🔔🔔');
        error_log('CoinSub Whitelabel: POST data keys: ' . implode(', ', array_keys($_POST)));
        
        // Call parent to save settings first
        $result = parent::process_admin_options();
        
        error_log('CoinSub Whitelabel: 🔔 Parent process_admin_options() returned, now calling update_api_client_settings()');
        
        // Now fetch branding
        $this->update_api_client_settings();
        
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
            error_log('💳 CoinSub - Creating purchase session...');
            $purchase_session_data = $this->prepare_purchase_session_from_cart($order, $cart_data);
            
            $purchase_session = $this->api_client->create_purchase_session($purchase_session_data);
            error_log('✅ CoinSub - Purchase session created: ' . ($purchase_session['purchase_session_id'] ?? 'unknown'));
            
            if (is_wp_error($purchase_session)) {
                throw new Exception($purchase_session->get_error_message());
            }
            
            // Store CoinSub data in order meta
            $order->update_meta_data('_coinsub_purchase_session_id', $purchase_session['purchase_session_id']);
            $order->update_meta_data('_coinsub_checkout_url', $purchase_session['checkout_url']);
            $order->update_meta_data('_coinsub_merchant_id', $this->get_option('merchant_id'));
            
            error_log('✅ CoinSub - Stored purchase session ID: ' . $purchase_session['purchase_session_id']);
            
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
            
            error_log('🔗 CoinSub - Checkout URL stored: ' . $purchase_session['checkout_url']);
            
            // Update order status - awaiting payment confirmation
            $order->update_status('on-hold', __('Awaiting crypto payment. Customer redirected to Coinsub checkout.', 'coinsub'));
            
            // Empty cart and clear CoinSub order from session
            WC()->cart->empty_cart();
            WC()->session->set('coinsub_order_id', null);  // Clear for next order
            
            $checkout_url = $purchase_session['checkout_url'];
            error_log('🎉 CoinSub - Payment process complete! Checkout URL: ' . $checkout_url);
            
            // Return checkout URL for iframe display
            return array(
                'result' => 'success',
                'redirect' => $checkout_url,
                'coinsub_checkout_url' => $checkout_url
            );
            
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
     * Store checkout URL for automatic opening
     */
    private function store_checkout_url($checkout_url) {
        // Use WordPress transient instead of PHP session (more reliable)
        $user_id = get_current_user_id();
        $session_id = $user_id ? $user_id : session_id();
        
        set_transient('coinsub_checkout_url_' . $session_id, $checkout_url, 300); // 5 minutes
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
                $('body').prepend('<div id="coinsub-checkout-notice" style="position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 20px; border-radius: 8px; z-index: 9999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 350px;"><strong style="font-size: 16px;">🚀 Complete Your Payment</strong><br><br>A new tab has opened with your CoinSub checkout.<br><br><small>Your order will be confirmed once payment is received.</small><br><br><button onclick="window.open(\'<?php echo esc_js($checkout_url); ?>\', \'_blank\')" style="background: white; color: #1e3a8a; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin-top: 10px; font-weight: bold;">Reopen Payment Page</button></div>');
                
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
        
        // Include the modal template
        include plugin_dir_path(__FILE__) . 'coinsub-checkout-modal.php';
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
        
        // ALL refunds use USDC on Polygon Amoy Testnet (chain_id 80002) for testing
        $chain_id = '80002'; // Polygon Amoy Testnet
        $token_symbol = 'USDC';
        
        error_log('🔄 CoinSub Refund - Using standardized refund: USDC on Polygon Amoy Testnet (chain_id: 80002)');
        error_log('🔄 CoinSub Refund - Original payment may have been on different chain/token, but refund will be USDC Polygon Amoy');
        
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
                $insufficient_funds_note .= '1. Go to <strong>WooCommerce → Settings → Payments → CoinSub</strong><br>';
                $insufficient_funds_note .= '2. Click <strong>"Manage"</strong> or scroll down<br>';
                $insufficient_funds_note .= '3. Click the <strong>"Onramp USDC Polygon via Meld"</strong> button<br>';
                $insufficient_funds_note .= '4. Complete the onramp process<br>';
                $insufficient_funds_note .= '5. Retry the refund once funds are available<br><br>';
                
                $insufficient_funds_note .= '<a href="' . esc_url($coinsub_settings_url) . '" class="button button-primary" style="background: #0284c7; border-color: #0284c7;">Go to CoinSub Settings</a>';
                
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
        
        // Note: All refunds are processed as USDC on Polygon regardless of original payment method
        $refund_note = sprintf(
            __('REFUND INITIATED: %s. Reason: %s. Customer wallet: %s. Refund ID: %s. Refund will be sent as USDC on Polygon (widely accepted). Refund initiated via CoinSub API. Waiting for transfer confirmation...', 'coinsub'),
            wc_price($amount),
            $reason,
            $customer_wallet ?: $to_address,
            $refund_id
        );
        
        // Add warning if original payment was on different chain/token
        $original_chain_id = $order->get_meta('_coinsub_chain_id');
        $original_token = $order->get_meta('_coinsub_token_symbol');
        if ($original_chain_id && $original_chain_id !== '80002') {
            $refund_note .= '<br><br><strong>ℹ️ Note:</strong> Original payment was on a different chain/token, but refund will be processed as USDC on Polygon Amoy Testnet for simplicity and wide acceptance.';
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
            '84532' => 'Base Sepolia'
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
     * Get payment method icon
     */
    public function get_icon() {
        $icon_html = '<img src="' . esc_url(COINSUB_PLUGIN_URL . 'images/coinsub.png') . '" alt="' . esc_attr($this->get_title()) . '" style="max-width: 50px; height: auto;" />';
        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }
    
    /**
     * Customize the payment button text
     */
    public function get_order_button_text() {
        // Only show "Pay with {Company}" if branding is available (no default)
        if (!empty($this->brand_company)) {
            $button_text = sprintf(__('Pay with %s', 'coinsub'), $this->brand_company);
            error_log('CoinSub Whitelabel: 🔘 Button text: "' . $button_text . '" (using brand_company: "' . $this->brand_company . '")');
            return $button_text;
        }
        
        // No branding - use default WooCommerce button text
        error_log('CoinSub Whitelabel: 🔘 No branding - using default button text');
        return __('Place order', 'woocommerce');
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
                    $section.find('.refund-actions').prepend('<div class="notice notice-warning coinsub-manual-refund-disabled" style="margin-bottom:8px;">⚠️ Manual refund is disabled for CoinSub payments. Use the API refund button.</div>');
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
                
                // Style the Place Order button when Coinsub is selected
                $('input[name="payment_method"]').on('change', function() {
                    var selectedMethod = $(this).val();
                    if (selectedMethod === 'coinsub') {
                        console.log('✅ Coinsub selected');
                        $('body').addClass('payment_method_coinsub');
                    } else {
                        $('body').removeClass('payment_method_coinsub');
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
            $order->add_order_note(sprintf(__('Refund processed: Transaction hash %s', 'coinsub'), $transaction_hash));
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
                __('5. Add transaction hash to order notes', 'coinsub'),
                __('6. Update order status to "Refunded"', 'coinsub'),
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
        // Debug: Log availability check with context
        $context = is_checkout() ? 'CHECKOUT PAGE' : (is_admin() ? 'ADMIN' : 'OTHER');
        error_log('=== CoinSub Gateway - Availability Check [' . $context . '] ===');
        error_log('CoinSub - Enabled setting: ' . $this->get_option('enabled'));
        error_log('CoinSub - Merchant ID: ' . $this->get_option('merchant_id'));
        error_log('CoinSub - API Key exists: ' . (!empty($this->get_option('api_key')) ? 'Yes' : 'No'));
        
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
            error_log('CoinSub - UNAVAILABLE: Gateway is disabled in settings ❌');
            return false;
        }
        
        if (empty($this->get_option('merchant_id'))) {
            error_log('CoinSub - UNAVAILABLE: No merchant ID configured ❌');
            return false;
        }
        
        if (empty($this->get_option('api_key'))) {
            error_log('CoinSub - UNAVAILABLE: No API key configured ❌');
            return false;
        }
        
        // Call parent method to ensure WooCommerce core checks pass
        $parent_available = parent::is_available();
        error_log('CoinSub - Parent is_available(): ' . ($parent_available ? 'TRUE' : 'FALSE'));
        
        if (!$parent_available) {
            error_log('CoinSub - UNAVAILABLE: Parent class returned false (WooCommerce core filtering) ❌');
            error_log('CoinSub - Common reasons: cart empty, order total 0, shipping required but not selected, terms & conditions page not set');
            
            // Check specifically for terms & conditions issue
            $terms_page_id = wc_get_page_id('terms');
            if (empty($terms_page_id)) {
                error_log('CoinSub - DIAGNOSIS: Terms & Conditions page is not set! This often blocks payment gateways.');
                error_log('CoinSub - SOLUTION: Set a Terms & Conditions page in WooCommerce > Settings > Advanced');
            }
            
            return false;
        }
        
        error_log('CoinSub - AVAILABLE: Gateway ready for checkout! ✅✅✅');
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
     */
    private function calculate_cart_totals() {
        $cart = WC()->cart;
        
        $subtotal = (float) $cart->get_subtotal();
        $shipping = (float) $cart->get_shipping_total();
        $tax = (float) $cart->get_total_tax();
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
            'total' => $total,
            'currency' => get_woocommerce_currency(),
            'has_subscription' => $has_subscription,
            'subscription_data' => $subscription_data,
            'items' => $this->get_cart_items_data()
        );
    }
    
    /**
     * Get cart items data for purchase session
     */
    private function get_cart_items_data() {
        $items = array();
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $items[] = array(
                'name' => $product->get_name(),
                'quantity' => $cart_item['quantity'],
                'price' => (float) $product->get_price(),
                'total' => (float) $cart_item['line_total']
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
     */
    private function get_order_details_text($order, $cart_data) {
        $details = array();
        
        foreach ($cart_data['items'] as $item) {
            $details[] = $item['quantity'] . 'x ' . $item['name'] . ' ($' . number_format($item['price'], 2) . ')';
        }
        
        if ($cart_data['shipping'] > 0) {
            $details[] = 'Shipping: $' . number_format($cart_data['shipping'], 2);
        }
        
        if ($cart_data['tax'] > 0) {
            $details[] = 'Tax: $' . number_format($cart_data['tax'], 2);
        }
        
        return implode(', ', $details);
    }
}
