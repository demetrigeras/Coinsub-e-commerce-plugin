<?php
/**
 * CoinSub Payment Gateway
 * 
 * Extends WooCommerce payment gateway for CoinSub integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Payment_Gateway extends WC_Payment_Gateway {
    
    /**
     * API client instance
     */
    private $api_client;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'coinsub';
        $this->icon = COINSUB_PLUGIN_URL . 'assets/images/coinsub-logo.png';
        $this->has_fields = false;
        $this->method_title = __('CoinSub', 'coinsub-commerce');
        $this->method_description = __('Accept cryptocurrency payments with CoinSub', 'coinsub-commerce');
        
        // Load the settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        
        // Initialize API client
        $this->api_client = new CoinSub_API_Client();
        
        // Update API client with current gateway settings
        $this->api_client->update_settings(
            $this->get_option('api_base_url'),
            $this->get_option('merchant_id'),
            $this->get_option('api_key')
        );
        
        // Save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        
        // Refresh API client settings when gateway settings are updated
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'refresh_api_client_settings'));
        
        // Add custom order status
        add_action('init', array($this, 'add_coinsub_order_status'));
        add_filter('wc_order_statuses', array($this, 'add_coinsub_order_status_to_woocommerce'));
    }
    
    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'coinsub-commerce'),
                'type' => 'checkbox',
                'label' => __('Enable CoinSub', 'coinsub-commerce'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'coinsub-commerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'coinsub-commerce'),
                'default' => __('Cryptocurrency Payment', 'coinsub-commerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'coinsub-commerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'coinsub-commerce'),
                'default' => __('Pay with cryptocurrency using CoinSub', 'coinsub-commerce'),
            ),
            'testmode' => array(
                'title' => __('Test Mode', 'coinsub-commerce'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'coinsub-commerce'),
                'default' => 'yes',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'coinsub-commerce'),
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'coinsub-commerce'),
                'type' => 'text',
                'description' => __('Your CoinSub Merchant ID (required)', 'coinsub-commerce'),
                'default' => '',
                'desc_tip' => true,
                'required' => true,
            ),
            'api_key' => array(
                'title' => __('API Key', 'coinsub-commerce'),
                'type' => 'password',
                'description' => __('Your CoinSub API Key (optional)', 'coinsub-commerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'success_url' => array(
                'title' => __('Success URL', 'coinsub-commerce'),
                'type' => 'text',
                'description' => __('URL to redirect after successful payment', 'coinsub-commerce'),
                'default' => home_url('/checkout/order-received/'),
                'desc_tip' => true,
            ),
            'cancel_url' => array(
                'title' => __('Cancel URL', 'coinsub-commerce'),
                'type' => 'text',
                'description' => __('URL to redirect after cancelled payment', 'coinsub-commerce'),
                'default' => wc_get_checkout_url(),
                'desc_tip' => true,
            ),
            'webhook_url' => array(
                'title' => __('Webhook URL', 'coinsub-commerce'),
                'type' => 'text',
                'description' => __('Your webhook URL for CoinSub to send payment notifications (auto-generated)', 'coinsub-commerce'),
                'default' => home_url('/wp-json/coinsub/v1/webhook'),
                'custom_attributes' => array('readonly' => 'readonly'),
                'desc_tip' => true,
            ),
            'webhook_instructions' => array(
                'title' => __('Webhook Setup Instructions', 'coinsub-commerce'),
                'type' => 'title',
                'description' => sprintf(
                    __('<strong>Important:</strong> Copy the webhook URL above and configure it in your CoinSub merchant dashboard. This URL will receive payment notifications and automatically update your WooCommerce orders.<br><br><strong>Webhook URL:</strong> <code>%s</code>', 'coinsub-commerce'),
                    home_url('/wp-json/coinsub/v1/webhook')
                ),
            ),
        );
    }
    
    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return array(
                'result' => 'failure',
                'messages' => __('Order not found', 'coinsub-commerce')
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
            $order->update_status('pending-coinsub', __('Awaiting CoinSub payment', 'coinsub-commerce'));
            
            // Empty cart
            WC()->cart->empty_cart();
            
            // Return success
            return array(
                'result' => 'success',
                'redirect' => $purchase_session['url']
            );
            
        } catch (Exception $e) {
            wc_add_notice(__('Payment error: ', 'coinsub-commerce') . $e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }
    
    /**
     * Ensure products exist in CoinSub commerce_products table
     */
    private function ensure_products_exist($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Check if product already exists in CoinSub
            $existing_product = $this->api_client->get_product_by_woocommerce_id($product->get_id());
            
            if (is_wp_error($existing_product)) {
                // Product doesn't exist, create it
                $product_data = array(
                    'name' => $product->get_name(),
                    'description' => $product->get_description(),
                    'price' => (float) $product->get_price(),
                    'currency' => $order->get_currency(),
                    'image_url' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
                    'metadata' => array(
                        'woocommerce_product_id' => $product->get_id(),
                        'sku' => $product->get_sku(),
                        'type' => $product->get_type()
                    )
                );
                
                $created_product = $this->api_client->create_product($product_data);
                if (is_wp_error($created_product)) {
                    error_log('Failed to create product in CoinSub: ' . $created_product->get_error_message());
                }
            }
        }
    }
    
    /**
     * Prepare order data for CoinSub API
     */
    private function prepare_order_data($order) {
        $items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Get the CoinSub product ID (we should have created it earlier)
            $coinsub_product = $this->api_client->get_product_by_woocommerce_id($product->get_id());
            
            if (is_wp_error($coinsub_product)) {
                // Fallback to WooCommerce product ID if CoinSub product not found
                $product_id = (string) $product->get_id();
            } else {
                $product_id = $coinsub_product['id'];
            }
            
            $items[] = array(
                'product_id' => $product_id,
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => (string) $item->get_total()
            );
        }
        
        return array(
            'items' => $items,
            'total' => (string) $order->get_total(),
            'currency' => $order->get_currency()
        );
    }
    
    /**
     * Prepare purchase session data
     */
    private function prepare_purchase_session_data($order, $coinsub_order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        
        // Calculate shipping and tax
        $shipping_total = $order->get_shipping_total();
        $tax_total = $order->get_total_tax();
        $subtotal = $order->get_subtotal();
        
        return array(
            'name' => 'WooCommerce Order Payment',
            'details' => 'Payment for WooCommerce order #' . $order->get_order_number(),
            'currency' => $order->get_currency(),
            'amount' => (float) $order->get_total(),
            'recurring' => false,
            'metadata' => array(
                'woocommerce_order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'shipping_total' => $shipping_total,
                'tax_total' => $tax_total,
                'subtotal' => $subtotal,
                'shipping_method' => $order->get_shipping_method(),
                'source' => 'woocommerce_plugin',
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
            'success_url' => 'https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f/success',
            'cancel_url' => 'https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f/cancel'
        );
    }
    
    /**
     * Add custom order status
     */
    public function add_coinsub_order_status() {
        register_post_status('wc-pending-coinsub', array(
            'label' => _x('Pending CoinSub Payment', 'Order status', 'coinsub-commerce'),
            'public' => false,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Pending CoinSub Payment <span class="count">(%s)</span>', 'Pending CoinSub Payment <span class="count">(%s)</span>', 'coinsub-commerce')
        ));
    }
    
    /**
     * Add custom order status to WooCommerce
     */
    public function add_coinsub_order_status_to_woocommerce($order_statuses) {
        $order_statuses['wc-pending-coinsub'] = _x('Pending CoinSub Payment', 'Order status', 'coinsub-commerce');
        return $order_statuses;
    }
    
    /**
     * Refresh API client settings when gateway settings are updated
     */
    public function refresh_api_client_settings() {
        $this->api_client->update_settings(
            $this->get_option('api_base_url'),
            $this->get_option('merchant_id'),
            $this->get_option('api_key')
        );
    }
    
    /**
     * Check if the gateway is available
     */
    public function is_available() {
        if ($this->enabled === 'no') {
            return false;
        }
        
        if (empty($this->get_option('merchant_id'))) {
            return false;
        }
        
        return true;
    }
}
