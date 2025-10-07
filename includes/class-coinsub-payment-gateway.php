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
        $this->id = 'coinsub';
        $this->icon = COINSUB_PLUGIN_URL . 'assets/images/coinsub-logo.png';
        $this->has_fields = false;
        $this->method_title = __('CoinSub', 'coinsub');
        $this->method_description = __('Accept cryptocurrency payments with CoinSub', 'coinsub');
        
        // Declare supported features
        $this->supports = array(
            'products',
            'refunds', // Manual refunds only via smart contracts
        );
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
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
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'coinsub'),
                'type' => 'checkbox',
                'label' => __('Enable CoinSub', 'coinsub'),
                'default' => 'no'
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
            'testmode' => array(
                'title' => __('Test Mode', 'coinsub'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'coinsub'),
                'default' => 'yes',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'coinsub'),
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
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return array(
                'result' => 'failure',
                'messages' => __('Order not found', 'coinsub')
            );
        }
        
        try {
            // First, ensure products exist in CoinSub commerce_products table
            $this->ensure_products_exist($order);
            
            // Prepare order data for CoinSub commerce_orders table
            $order_data = $this->prepare_order_data($order);
            
            // Create order in CoinSub commerce_orders table
            $coinsub_order = $this->api_client->create_order($order_data);
            
            if (is_wp_error($coinsub_order)) {
                throw new Exception($coinsub_order->get_error_message());
            }
            
            // Create purchase session with order details
            $purchase_session_data = $this->prepare_purchase_session_data($order, $coinsub_order);
            $purchase_session = $this->api_client->create_purchase_session($purchase_session_data);
            
            if (is_wp_error($purchase_session)) {
                throw new Exception($purchase_session->get_error_message());
            }
            
            // Checkout the order (link to purchase session)
            $checkout_result = $this->api_client->checkout_order(
                $coinsub_order['id'],
                $purchase_session['purchase_session_id']
            );
            
            if (is_wp_error($checkout_result)) {
                throw new Exception($checkout_result->get_error_message());
            }
            
            // Store CoinSub data in order meta
            $order->update_meta_data('_coinsub_order_id', $coinsub_order['id']);
            $order->update_meta_data('_coinsub_purchase_session_id', $purchase_session['purchase_session_id']);
            $order->update_meta_data('_coinsub_checkout_url', $purchase_session['url']);
            $order->update_meta_data('_coinsub_merchant_id', $this->get_option('merchant_id'));
            $order->save();
            
            // Update order status
            $order->update_status('pending-coinsub', __('⏳ Waiting for crypto payment confirmation', 'coinsub'));
            
            // Empty cart
            WC()->cart->empty_cart();
            
            // Store checkout URL for automatic opening
            $this->store_checkout_url($purchase_session['url']);
            
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
            
        } catch (Exception $e) {
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
        return array(
            'merchant_id' => $this->get_option('merchant_id'),
            'customer_email' => $order->get_billing_email(),
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
            'success_url' => "",
            'cancel_url' => ""
        );
    }
    
    /**
     * Store checkout URL for automatic opening
     */
    private function store_checkout_url($checkout_url) {
        // Store in PHP session
        if (!session_id()) {
            session_start();
        }
        $_SESSION['coinsub_checkout_url'] = $checkout_url;
        
        // Also store in transient as backup
        set_transient('coinsub_checkout_url_' . get_current_user_id(), $checkout_url, 300); // 5 minutes
    }
    
    /**
     * Add checkout script to automatically open CoinSub checkout
     */
    public function add_checkout_script() {
        if (!session_id()) {
            session_start();
        }
        
        $checkout_url = isset($_SESSION['coinsub_checkout_url']) ? $_SESSION['coinsub_checkout_url'] : '';
        
        if (empty($checkout_url)) {
            // Try transient as backup
            $checkout_url = get_transient('coinsub_checkout_url_' . get_current_user_id());
        }
        
        if (!empty($checkout_url)) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Open CoinSub checkout in new tab
                window.open('<?php echo esc_js($checkout_url); ?>', '_blank');
                
                // Show notice to user
                $('body').prepend('<div id="coinsub-checkout-notice" style="position: fixed; top: 20px; right: 20px; background: #0073aa; color: white; padding: 15px; border-radius: 5px; z-index: 9999; box-shadow: 0 2px 10px rgba(0,0,0,0.3);"><strong>CoinSub Payment</strong><br>Please complete your payment in the new tab that opened.</div>');
                
                // Remove notice after 10 seconds
                setTimeout(function() {
                    $('#coinsub-checkout-notice').fadeOut();
                }, 10000);
                
                // Clear the stored URL
                <?php
                unset($_SESSION['coinsub_checkout_url']);
                delete_transient('coinsub_checkout_url_' . get_current_user_id());
                ?>
            });
            </script>
            <?php
        }
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
            .woocommerce-checkout .payment_method_coinsub .payment_box {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: 2px solid #667eea;
                border-radius: 8px;
                padding: 20px;
                margin: 10px 0;
            }
            
            .woocommerce-checkout .payment_method_coinsub .payment_box::before {
                content: "🚀";
                font-size: 24px;
                margin-right: 10px;
            }
            
            .woocommerce-checkout .payment_method_coinsub label {
                font-weight: bold;
                font-size: 16px;
                color: white;
            }
            
            .woocommerce-checkout .payment_method_coinsub .payment_box p {
                color: white;
                margin: 10px 0;
            }
            
            #place_order {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                border: none !important;
                color: white !important;
                font-weight: bold !important;
                font-size: 18px !important;
                padding: 15px 30px !important;
                border-radius: 8px !important;
                text-transform: uppercase !important;
                letter-spacing: 1px !important;
                box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4) !important;
                transition: all 0.3s ease !important;
            }
            
            #place_order:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6) !important;
            }
            
            .woocommerce-checkout .payment_method_coinsub {
                background: #f8f9fa;
                border: 2px solid #e9ecef;
                border-radius: 8px;
                margin: 10px 0;
            }
            </style>
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
     * Check if the gateway is available
     */
    public function is_available() {
        // Debug: Log availability check
        error_log('CoinSub Gateway - is_available() called');
        error_log('CoinSub Gateway - enabled: ' . $this->enabled);
        error_log('CoinSub Gateway - merchant_id: ' . $this->get_option('merchant_id'));
        
        if ($this->enabled === 'no') {
            error_log('CoinSub Gateway - Not available: disabled');
            return false;
        }
        
        if (empty($this->get_option('merchant_id'))) {
            error_log('CoinSub Gateway - Not available: no merchant ID');
            return false;
        }
        
        error_log('CoinSub Gateway - Available: true');
        return true;
    }
}
