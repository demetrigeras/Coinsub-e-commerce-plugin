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
            $order->update_status('pending-coinsub', __('Awaiting CoinSub payment', 'coinsub'));
            
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
        
        // Simple: Use total order amount (merchant handles shipping/tax separately)
        $total_amount = (float) $order->get_total();
        
        return array(
            'name' => $order_name,
            'details' => 'Payment for WooCommerce order #' . $order->get_order_number() . ' with ' . count($product_details) . ' product(s)',
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
                'total_amount' => $total_amount
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
}
