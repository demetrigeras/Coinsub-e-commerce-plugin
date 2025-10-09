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
        error_log('🏗️ CoinSub - Gateway constructor called');
        
        $this->id = 'coinsub';
        $this->icon = COINSUB_PLUGIN_URL . 'assets/images/coinsub-logo.png';
        $this->has_fields = true; // Enable custom payment box
        $this->method_title = __('CoinSub', 'coinsub');
        $this->method_description = __('Accept cryptocurrency payments with CoinSub', 'coinsub');
        
        // Declare supported features
        $this->supports = array(
            'products'
        );
        
        error_log('🏗️ CoinSub - Supports: ' . json_encode($this->supports));
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Get settings for debugging
        $this->title = $this->get_option('title', 'CoinSub');
        $this->description = $this->get_option('description', 'Pay with cryptocurrency');
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
        add_action('init', array($this, 'add_coinsub_order_status'));
        add_filter('wc_order_statuses', array($this, 'add_coinsub_order_status_to_woocommerce'));
        add_filter('woocommerce_order_button_text', array($this, 'get_order_button_text'));
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
                'label' => __('Enable CoinSub', 'coinsub'),
                'default' => 'yes'  // ← Changed to 'yes' for testing
            ),
            'title' => array(
                'title' => __('Title', 'coinsub'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'coinsub'),
                'default' => __('CoinSub', 'coinsub'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'coinsub'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'coinsub'),
                'default' => __('Pay with cryptocurrency using CoinSub', 'coinsub'),
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'coinsub'),
                'type' => 'text',
                'description' => __('Your CoinSub Merchant ID (required)', 'coinsub'),
                'default' => '',
                'desc_tip' => true,
                'required' => true,
            ),
            'api_key' => array(
                'title' => __('API Key', 'coinsub'),
                'type' => 'password',
                'description' => __('Your CoinSub API Key (required)', 'coinsub'),
                'default' => '',
                'desc_tip' => true,
                'required' => true,
            ),
            'webhook_url' => array(
                'title' => __('Webhook URL', 'coinsub'),
                'type' => 'text',
                'description' => __('Your webhook URL for CoinSub to send payment notifications (auto-generated)', 'coinsub'),
                'default' => home_url('/wp-json/coinsub/v1/webhook'),
                'custom_attributes' => array('readonly' => 'readonly'),
                'desc_tip' => true,
            ),
            'webhook_instructions' => array(
                'title' => __('Webhook Setup Instructions', 'coinsub'),
                'type' => 'title',
                'description' => sprintf(
                    __('<strong>Important:</strong> Copy the webhook URL above and configure it in your CoinSub merchant dashboard. This URL will receive payment notifications and automatically update your WooCommerce orders.<br><br><strong>Webhook URL:</strong> <code>%s</code>', 'coinsub'),
                    home_url('/wp-json/coinsub/v1/webhook')
                ),
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
            // Get CoinSub order ID from session (created by cart sync)
            $coinsub_order_id = WC()->session->get('coinsub_order_id');
            
            if (!$coinsub_order_id) {
                error_log('⚠️ CoinSub - No order from cart sync, creating now...');
                $order_data = $this->prepare_order_data($order);
                $coinsub_order = $this->api_client->create_order($order_data);
                
                if (is_wp_error($coinsub_order)) {
                    throw new Exception($coinsub_order->get_error_message());
                }
                
                $coinsub_order_id = $coinsub_order['id'];
            } else {
                error_log('✅ CoinSub - Using existing order from cart: ' . $coinsub_order_id);
            }
            
            // Create purchase session with order details
            error_log('💳 CoinSub - Step 2: Creating purchase session...');
            $purchase_session_data = $this->prepare_purchase_session_data($order, array('id' => $coinsub_order_id));
            error_log('CoinSub - Session data: ' . json_encode(['amount' => $purchase_session_data['amount'], 'currency' => $purchase_session_data['currency']]));
            $purchase_session = $this->api_client->create_purchase_session($purchase_session_data);
            error_log('✅ CoinSub - Purchase session created: ' . ($purchase_session['purchase_session_id'] ?? 'unknown'));
            
            if (is_wp_error($purchase_session)) {
                throw new Exception($purchase_session->get_error_message());
            }
            
            // Checkout the order (link to purchase session)
            error_log('🔗 CoinSub - Step 3: Linking order to session...');
            $checkout_result = $this->api_client->checkout_order(
                $coinsub_order_id,
                $purchase_session['purchase_session_id']
            );
            error_log('✅ CoinSub - Order linked to session!');
            
            if (is_wp_error($checkout_result)) {
                throw new Exception($checkout_result->get_error_message());
            }
            
            // Store CoinSub data in order meta
            $order->update_meta_data('_coinsub_order_id', $coinsub_order_id);
            $order->update_meta_data('_coinsub_purchase_session_id', $purchase_session['purchase_session_id']);
            $order->update_meta_data('_coinsub_checkout_url', $purchase_session['checkout_url']);
            $order->update_meta_data('_coinsub_merchant_id', $this->get_option('merchant_id'));
            $order->save();
            
            error_log('🔗 CoinSub - Checkout URL stored: ' . $purchase_session['checkout_url']);
            
            // Update order status
            $order->update_status('pending-coinsub', __('⏳ Waiting for crypto payment confirmation', 'coinsub'));
            
            // Empty cart
            WC()->cart->empty_cart();
            
            $checkout_url = $purchase_session['checkout_url'];
            error_log('🎉 CoinSub - Payment process complete! Checkout URL: ' . $checkout_url);
            
            // Redirect directly to CoinSub checkout page
            // Order stays "pending-coinsub" until webhook confirms payment
            return array(
                'result' => 'success',
                'redirect' => $checkout_url
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
    
    /**
     * Prepare order data for CoinSub
     */
    private function prepare_order_data($order) {
        $items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Merchant should map WooCommerce products to CoinSub products in their dashboard
            // We send WooCommerce product ID and the merchant's CoinSub system will map it
            $items[] = array(
                'product_id' => (string) $product->get_id(), // WooCommerce product ID
                'name' => $item->get_name(), // Product name
                'quantity' => (int) $item->get_quantity(),
                'price' => (float) $item->get_total() / $item->get_quantity() // Price per unit
            );
        }
        
        return array(
            'items' => $items,
            'subtotal' => (float) $order->get_subtotal(),
            'shipping' => (float) $order->get_shipping_total(),
            'tax' => (float) $order->get_total_tax(),
            'total' => (float) $order->get_total(),
            'currency' => $order->get_currency()
        );
    }
    
    /**
     * Prepare purchase session data
     */
    private function prepare_purchase_session_data($order, $coinsub_order) {
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
        
        return array(
            'name' => $order_name,
            'details' => $details_string,
            'currency' => $order->get_currency(),
            'amount' => $total_amount,
            'recurring' => false,
            'metadata' => array(
                'woocommerce_order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'source' => 'woocommerce_plugin',
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
            'cancel_url' => wc_get_checkout_url() // Return to checkout if cancelled
        );
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
     * Display payment fields (custom button in payment box)
     */
    public function payment_fields() {
        error_log('🎨 CoinSub - payment_fields() called! Gateway is being rendered!');
        error_log('🎨 CoinSub - Description: ' . $this->description);
        error_log('🎨 CoinSub - Title: ' . $this->title);
        
        // Show description
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }
        
        // Show custom styled content with JavaScript debug
        ?>
        <script>
        console.log('🚀 CoinSub payment_fields() rendered!');
        console.log('CoinSub gateway is displaying on the page');
        </script>
        <div class="coinsub-payment-box" id="coinsub-payment-box-visible" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 10px; border-radius: 4px; text-align: center; margin: 8px 0; display: none; border: 1px solid #3b82f6; max-width: 300px;">
            <!-- Simple CoinSub Button -->
            <div style="color: white; font-size: 13px; font-weight: bold; margin-bottom: 3px;">
                Pay with Crypto
            </div>
            <div style="color: rgba(255,255,255,0.8); font-size: 10px; margin-bottom: 8px;">
                by Coinsub
            </div>
            <!-- Custom CoinSub Payment Button -->
            <button type="button" id="coinsub-place-order" style="background: white; color: #1e3a8a; border: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 13px; display: none;">
                Continue to Payment
            </button>
            
        </div>
        <script>
        console.log('CoinSub payment box HTML rendered with ID: coinsub-payment-box-visible');
        
        // Handle CoinSub custom button click
        jQuery(document).ready(function($) {
            $('#coinsub-place-order').on('click', function(e) {
                e.preventDefault();
                console.log('🚀 CoinSub custom button clicked!');
                
                // First, validate the checkout form
                if (!$('form.checkout').length) {
                    console.log('❌ No checkout form found');
                    alert('Checkout form not found. Please refresh the page.');
                    return;
                }
                
                // Check if required fields are filled
                var hasErrors = false;
                $('form.checkout .validate-required input, form.checkout .validate-required select').each(function() {
                    if (!$(this).val()) {
                        hasErrors = true;
                        $(this).addClass('woocommerce-invalid');
                    } else {
                        $(this).removeClass('woocommerce-invalid');
                    }
                });
                
                if (hasErrors) {
                    console.log('❌ Form validation failed');
                    alert('Please fill in all required fields.');
                    return;
                }
                
                console.log('✅ Form validation passed, proceeding with CoinSub payment...');
                
                // Trigger WooCommerce checkout process for CoinSub
                $('input[name="payment_method"][value="coinsub"]').prop('checked', true);
                
                // Submit the form to trigger process_payment
                $('form.checkout').submit();
            });
        });
        </script>
        <?php
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
        $icon_html = '<img src="' . esc_url(COINSUB_PLUGIN_URL . 'assets/images/coinsub-logo.png') . '" alt="' . esc_attr($this->get_title()) . '" style="max-width: 50px; height: auto;" />';
        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }
    
    /**
     * Customize the payment button text
     */
    public function get_order_button_text() {
        return __('Pay with Crypto', 'coinsub');
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
            
            .woocommerce-checkout .payment_method_coinsub .payment_box {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white;
                border: 1px solid #3b82f6;
                border-radius: 6px;
                padding: 15px;
                margin: 8px 0;
                display: block !important;
            }
            
            .woocommerce-checkout .payment_method_coinsub .payment_box::before {
                content: "🚀";
                font-size: 24px;
                margin-right: 10px;
            }
            
            .woocommerce-checkout .payment_method_coinsub label {
                font-weight: bold;
                font-size: 16px;
            }
            
            .woocommerce-checkout .payment_method_coinsub .payment_box p {
                color: white;
                margin: 10px 0;
            }
            
            #place_order {
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
            
            #place_order:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 20px rgba(59, 130, 246, 0.6) !important;
            }
            
            .woocommerce-checkout .payment_method_coinsub {
                background: #f8f9fa !important;
                border: 1px solid #3b82f6 !important;
                border-radius: 6px;
                margin: 8px 0;
                padding: 12px !important;
                display: list-item !important;
            }
            </style>
            <script>
            console.log('🔍 CoinSub Debug - Styles loaded');
            
            // Force traditional checkout if block checkout is detected
            jQuery(document).ready(function($) {
                console.log('🔍 CoinSub - Initializing checkout compatibility...');
                
                // Check if we're on a block-based checkout
                var isBlockCheckout = $('.wp-block-woocommerce-checkout').length > 0;
                console.log('Is block checkout:', isBlockCheckout);
                
                if (isBlockCheckout) {
                    console.log('🚨 BLOCK CHECKOUT DETECTED - Attempting to force traditional checkout...');
                    
                    // Try to replace block checkout with traditional
                    setTimeout(function() {
                        var blockCheckout = $('.wp-block-woocommerce-checkout');
                        if (blockCheckout.length > 0) {
                            console.log('🔄 Replacing block checkout with traditional...');
                            blockCheckout.html('<div class="woocommerce-checkout">' + 
                                '<form name="checkout" method="post" class="checkout woocommerce-checkout" action="" enctype="multipart/form-data">' +
                                '<div class="woocommerce-checkout-payment" id="payment">' +
                                '<ul class="wc_payment_methods payment_methods methods">' +
                                '<li class="wc_payment_method payment_method_cod">' +
                                '<input id="payment_method_cod" type="radio" class="input-radio" name="payment_method" value="cod" checked="checked">' +
                                '<label for="payment_method_cod">Cash on delivery</label>' +
                                '</li>' +
                                '<li class="wc_payment_method payment_method_coinsub">' +
                                '<input id="payment_method_coinsub" type="radio" class="input-radio" name="payment_method" value="coinsub">' +
                                '<label for="payment_method_coinsub">CoinSub</label>' +
                                '<div class="payment_box payment_method_coinsub">' +
                                '<p>Pay with cryptocurrency using CoinSub</p>' +
                                '<div class="coinsub-payment-box" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 20px; border-radius: 8px; text-align: center; margin: 10px 0; color: white;">' +
                                '<div style="font-size: 18px; font-weight: bold; margin-bottom: 10px;">CoinSub</div>' +
                                '<div>Pay with Cryptocurrency</div>' +
                                '<div style="font-size: 14px; margin-top: 10px;">Accept USDC and USDT payments</div>' +
                                '</div>' +
                                '</div>' +
                                '</li>' +
                                '</ul>' +
                                '<div class="form-row place-order">' +
                                '<button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="Place order" data-value="Place order">Place order</button>' +
                                '</div>' +
                                '</div>' +
                                '</form>' +
                                '</div>');
                            console.log('✅ Traditional checkout forced!');
                        }
                    }, 2000);
                }
                
                // Regular payment method checking
                setTimeout(function() {
                    console.log('🔍 CoinSub Debug - Checking for payment method...');
                    
                    // Find the payment methods container with multiple selectors
                    var paymentUL = $('ul.payment_methods, ul.wc_payment_methods, .woocommerce-checkout-payment ul');
                    console.log('Payment methods UL found:', paymentUL.length);
                    
                    if (paymentUL.length > 0) {
                        console.log('Payment UL HTML:', paymentUL.html().substring(0, 500));
                        
                        var allMethods = paymentUL.find('li');
                        console.log('Total payment method items:', allMethods.length);
                        
                        allMethods.each(function(index) {
                            var className = $(this).attr('class') || 'no-class';
                            var inputVal = $(this).find('input[type=radio]').val();
                            console.log('Method ' + index + ':', className, 'Input value:', inputVal);
                        });
                        
                        // Check specifically for coinsub
                        var coinsubMethod = paymentUL.find('li.payment_method_coinsub, li[class*="coinsub"], input[value="coinsub"]');
                        console.log('CoinSub elements found:', coinsubMethod.length);
                        
                        if (coinsubMethod.length > 0) {
                            console.log('✅ FOUND CoinSub!', coinsubMethod.html());
                        } else {
                            console.log('❌ CoinSub NOT in list');
                        }
                    } else {
                        console.log('❌ Could not find payment methods UL container at all!');
                        console.log('Page HTML sample:', $('body').html().substring(0, 1000));
                    }
                    
                    // Check entire page for "coinsub" string
                    var pageHTML = $('body').html();
                    var coinsubMentions = (pageHTML.match(/coinsub/gi) || []).length;
                    console.log('Times "coinsub" appears in page HTML:', coinsubMentions);
                }, 1000);
                
                // Also check on AJAX complete
                $(document.body).on('updated_checkout', function() {
                    console.log('🔄 Checkout updated (AJAX) - rechecking...');
                    setTimeout(function() {
                        var paymentUL = $('ul.payment_methods, ul.wc_payment_methods');
                        var coinsubMethod = paymentUL.find('li.payment_method_coinsub');
                        console.log('CoinSub after AJAX:', coinsubMethod.length > 0 ? 'YES ✅' : 'NO ❌');
                        if (coinsubMethod.length === 0) {
                            console.log('Available after AJAX:', paymentUL.find('li').length);
                        }
                    }, 500);
                });
                
                // Debug form submission
                $('form.checkout').on('submit', function(e) {
                    console.log('🚀 Form submitted!');
                    console.log('Selected payment method:', $('input[name="payment_method"]:checked').val());
                    console.log('Form data:', $(this).serialize());
                    
                    var selectedPayment = $('input[name="payment_method"]:checked').val();
                    if (selectedPayment === 'coinsub') {
                        console.log('✅ CoinSub payment method is selected - form should process normally');
                    } else {
                        console.log('❌ CoinSub NOT selected. Selected:', selectedPayment);
                    }
                });
                
                // Debug when place order button is clicked
                $(document).on('click', '#place_order', function(e) {
                    console.log('🎯 Place Order button clicked!');
                    console.log('Selected payment method:', $('input[name="payment_method"]:checked').val());
                    
                    var selectedPayment = $('input[name="payment_method"]:checked').val();
                    if (selectedPayment === 'coinsub') {
                        console.log('✅ CoinSub selected - should trigger process_payment()');
                    } else {
                        console.log('❌ CoinSub NOT selected. Selected:', selectedPayment);
                    }
                });
                
                // Show/hide buttons based on payment method selection
                $('input[name="payment_method"]').on('change', function() {
                    var selectedPayment = $(this).val();
                    console.log('Payment method changed to:', selectedPayment);
                    
                    if (selectedPayment === 'coinsub') {
                        console.log('🔄 CoinSub selected - Hiding standard button, showing CoinSub button');
                        // Hide standard WooCommerce place order button
                        $('#place_order').hide();
                        $('.form-row.place-order').hide();
                        // Show CoinSub button and timing note
                        $('#coinsub-place-order').show();
                        $('#coinsub-timing-note').show();
                        $('.coinsub-payment-box').show();
                    } else {
                        console.log('🔄 Other payment method selected - Showing standard button, hiding CoinSub button');
                        // Show standard WooCommerce place order button
                        $('#place_order').show();
                        $('.form-row.place-order').show();
                        // Hide CoinSub button and timing note
                        $('#coinsub-place-order').hide();
                        $('#coinsub-timing-note').hide();
                        $('.coinsub-payment-box').hide();
                    }
                });
                
                // Initial check on page load
                var initialPayment = $('input[name="payment_method"]:checked').val();
                if (initialPayment === 'coinsub') {
                    $('#place_order').hide();
                    $('.form-row.place-order').hide();
                    $('#coinsub-place-order').show();
                    $('#coinsub-timing-note').show();
                    $('.coinsub-payment-box').show();
                } else {
                    $('#place_order').show();
                    $('.form-row.place-order').show();
                    $('#coinsub-place-order').hide();
                    $('#coinsub-timing-note').hide();
                    $('.coinsub-payment-box').hide();
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
     * Add custom order status
     */
    public function add_coinsub_order_status() {
        register_post_status('wc-pending-coinsub', array(
            'label' => _x('Pending CoinSub Payment', 'Order status', 'coinsub'),
            'public' => false,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Pending CoinSub Payment <span class="count">(%s)</span>', 'Pending CoinSub Payment <span class="count">(%s)</span>', 'coinsub')
        ));
        
        register_post_status('wc-refund-pending', array(
            'label' => _x('Refund Pending', 'Order status', 'coinsub'),
            'public' => false,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Refund Pending <span class="count">(%s)</span>', 'Refund Pending <span class="count">(%s)</span>', 'coinsub')
        ));
    }
    
    /**
     * Add custom order status to WooCommerce
     */
    public function add_coinsub_order_status_to_woocommerce($order_statuses) {
        $order_statuses['wc-pending-coinsub'] = _x('Pending CoinSub Payment', 'Order status', 'coinsub');
        $order_statuses['wc-refund-pending'] = _x('Refund Pending', 'Order status', 'coinsub');
        return $order_statuses;
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
}
