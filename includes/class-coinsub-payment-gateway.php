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
            'products'
        );
        
        error_log('🏗️ Coinsub - Supports: ' . json_encode($this->supports));
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Get settings for debugging
        $this->title = 'Pay with Coinsub';
        $this->description = '';
        $this->enabled = $this->get_option('enabled', 'yes');
        
        error_log('🏗️ CoinSub - Constructor - ID: ' . $this->id);
        error_log('🏗️ CoinSub - Constructor - Title: ' . $this->title);
        error_log('🏗️ CoinSub - Constructor - Description: ' . $this->description);
        error_log('🏗️ CoinSub - Constructor - Enabled: ' . $this->enabled);
        error_log('🏗️ CoinSub - Constructor - Merchant ID: ' . $this->get_option('merchant_id'));
        error_log('🏗️ CoinSub - Constructor - Method Title: ' . $this->method_title);
        error_log('🏗️ CoinSub - Constructor - Has fields: ' . ($this->has_fields ? 'YES' : 'NO'));
        
        // Initialize API client
        $this->api_client = new CoinSub_API_Client();
        
        // Add hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        add_action('wp_footer', array($this, 'add_checkout_script'));
        add_action('wp_head', array($this, 'add_payment_button_styles'));
        add_filter('woocommerce_order_button_text', array($this, 'get_order_button_text'));
        
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
            
            <h4>Step 1: Get Your Coinsub Credentials</h4>
            <ol style="line-height: 1.8;">
                <li>Go to <a href="https://coinsub.io" target="_blank">coinsub.io</a> and sign up or log in</li>
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
                'default' => home_url('/wp-json/coinsub/v1/webhook'),
                'custom_attributes' => array('readonly' => 'readonly'),
                'css' => 'background: #f0f0f0;',
            ),
        );
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
            error_log('CoinSub - Session data: ' . json_encode(['amount' => $purchase_session_data['amount'], 'currency' => $purchase_session_data['currency']]));
            $purchase_session = $this->api_client->create_purchase_session($purchase_session_data);
            error_log('✅ CoinSub - Purchase session created: ' . ($purchase_session['purchase_session_id'] ?? 'unknown'));
            
            if (is_wp_error($purchase_session)) {
                throw new Exception($purchase_session->get_error_message());
            }
            
            // Store CoinSub data in order meta
            $order->update_meta_data('_coinsub_purchase_session_id', $purchase_session['purchase_session_id']);
            $order->update_meta_data('_coinsub_checkout_url', $purchase_session['checkout_url']);
            $order->update_meta_data('_coinsub_merchant_id', $this->get_option('merchant_id'));
            $order->update_meta_data('_coinsub_environment', $this->environment);
            
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
     * Process refunds (Manual crypto transfer required)
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order.', 'coinsub'));
        }
        
        // Get customer's wallet address from order meta
        $customer_wallet = $order->get_meta('_customer_wallet_address');
        
        if (empty($customer_wallet)) {
            $refund_note = sprintf(
                __('REFUND REQUIRES MANUAL PROCESSING: %s. Reason: %s. Customer wallet address not found. Please contact customer for their wallet address and process refund manually.', 'coinsub'),
                wc_price($amount),
                $reason
            );
        } else {
            $refund_note = sprintf(
                __('REFUND REQUIRES MANUAL PROCESSING: %s. Reason: %s. Customer wallet: %s. Please send crypto from your merchant wallet to customer wallet and update order status.', 'coinsub'),
                wc_price($amount),
                $reason,
                $customer_wallet
            );
        }
        
        $order->add_order_note($refund_note);
        
        // Update order status to indicate refund is pending manual processing
        $order->update_status('refund-pending', __('Refund pending - merchant must send crypto back to customer wallet.', 'coinsub'));
        
        // Return error to indicate this requires manual processing
        return new WP_Error('manual_refund_required', __('Refund requires manual crypto transfer. Please send crypto from your merchant wallet to customer wallet.', 'coinsub'));
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
        return __('Pay with Coinsub', 'coinsub');
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
                'total' => $cart_data['total']
            ),
            'success_url' => $this->get_return_url($order),
            'cancel_url' => wc_get_checkout_url(),
            'failure_url' => wc_get_checkout_url()
        );
        
        // Add subscription fields if recurring
        if ($cart_data['has_subscription'] && $cart_data['subscription_data']) {
            $session_data['frequency'] = $cart_data['subscription_data']['frequency'];
            $session_data['interval'] = $cart_data['subscription_data']['interval'];
            $session_data['duration'] = $cart_data['subscription_data']['duration'];
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
