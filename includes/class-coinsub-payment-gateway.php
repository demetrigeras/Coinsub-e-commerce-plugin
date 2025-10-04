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
        
        // Add checkout script to frontend
        add_action('wp_footer', array($this, 'add_checkout_script'));
        
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
            'shipping_tax_section' => array(
                'title' => __('Shipping & Tax Configuration', 'coinsub-commerce'),
                'type' => 'title',
                'description' => __('Configure how shipping and taxes are handled in your crypto payments.', 'coinsub-commerce'),
            ),
            'include_shipping_in_crypto' => array(
                'title' => __('Include Shipping in Crypto Payment', 'coinsub-commerce'),
                'type' => 'checkbox',
                'label' => __('Include shipping costs in crypto payment', 'coinsub-commerce'),
                'default' => 'yes',
                'description' => __('When enabled, customers pay shipping costs in cryptocurrency along with their products. When disabled, shipping will be handled separately.', 'coinsub-commerce'),
            ),
            'include_tax_in_crypto' => array(
                'title' => __('Include Tax in Crypto Payment', 'coinsub-commerce'),
                'type' => 'checkbox',
                'label' => __('Include tax costs in crypto payment', 'coinsub-commerce'),
                'default' => 'yes',
                'description' => __('When enabled, customers pay tax costs in cryptocurrency along with their products. When disabled, tax will be handled separately.', 'coinsub-commerce'),
            ),
            'shipping_payment_method' => array(
                'title' => __('Shipping Payment Method', 'coinsub-commerce'),
                'type' => 'select',
                'description' => __('How will shipping costs be paid when not included in crypto payment?', 'coinsub-commerce'),
                'default' => 'merchant_covered',
                'options' => array(
                    'merchant_covered' => __('Merchant Covers Shipping (Recommended)', 'coinsub-commerce'),
                    'separate_payment' => __('Separate Payment Required', 'coinsub-commerce'),
                    'crypto_conversion' => __('Auto-convert Crypto to Fiat', 'coinsub-commerce'),
                ),
                'desc_tip' => true,
            ),
            'tax_payment_method' => array(
                'title' => __('Tax Payment Method', 'coinsub-commerce'),
                'type' => 'select',
                'description' => __('How will tax costs be paid when not included in crypto payment?', 'coinsub-commerce'),
                'default' => 'merchant_covered',
                'options' => array(
                    'merchant_covered' => __('Merchant Covers Tax (Recommended)', 'coinsub-commerce'),
                    'separate_payment' => __('Separate Payment Required', 'coinsub-commerce'),
                    'crypto_conversion' => __('Auto-convert Crypto to Fiat', 'coinsub-commerce'),
                ),
                'desc_tip' => true,
            ),
            'crypto_conversion_service' => array(
                'title' => __('Crypto Conversion Service', 'coinsub-commerce'),
                'type' => 'select',
                'description' => __('Service to use for automatic crypto-to-fiat conversion (if enabled above)', 'coinsub-commerce'),
                'default' => 'manual',
                'options' => array(
                    'manual' => __('Manual Conversion (You handle conversion)', 'coinsub-commerce'),
                    'coinbase' => __('Coinbase Commerce', 'coinsub-commerce'),
                    'bitpay' => __('BitPay', 'coinsub-commerce'),
                    'custom' => __('Custom API Integration', 'coinsub-commerce'),
                ),
                'desc_tip' => true,
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
            
            // Store checkout URL for automatic opening
            $this->store_checkout_url($purchase_session['url']);
            
            // Return success with redirect
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
            
            $coinsub_product_id = null;
            
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
                if (!is_wp_error($created_product)) {
                    $coinsub_product_id = $created_product['id'] ?? null;
                } else {
                    error_log('Failed to create product in CoinSub: ' . $created_product->get_error_message());
                }
            } else {
                // Product exists, get its ID
                $coinsub_product_id = $existing_product['id'] ?? null;
            }
            
            // Store CoinSub product ID in order meta for later use
            if ($coinsub_product_id) {
                $order->update_meta_data('_coinsub_product_' . $product->get_id(), $coinsub_product_id);
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
        // Prepare detailed product information
        $items = array();
        $product_names = array();
        $product_details = array();
        $total_items = 0;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $item_name = $item->get_name();
            $quantity = $item->get_quantity();
            $total_items += $quantity;
            
            $items[] = $item_name . ' x' . $quantity;
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
        
        // Calculate shipping and tax
        $shipping_total = $order->get_shipping_total();
        $tax_total = $order->get_total_tax();
        $subtotal = $order->get_subtotal();
        
        // Determine what to include in crypto payment based on settings
        $include_shipping = $this->get_option('include_shipping_in_crypto') === 'yes';
        $include_tax = $this->get_option('include_tax_in_crypto') === 'yes';
        
        // Calculate crypto payment amount
        $crypto_amount = $subtotal; // Always include product subtotal
        if ($include_shipping) {
            $crypto_amount += $shipping_total;
        }
        if ($include_tax) {
            $crypto_amount += $tax_total;
        }
        
        // Prepare payment breakdown for metadata
        $payment_breakdown = array(
            'product_subtotal' => $subtotal,
            'shipping_total' => $shipping_total,
            'tax_total' => $tax_total,
            'crypto_payment_amount' => $crypto_amount,
            'shipping_included_in_crypto' => $include_shipping,
            'tax_included_in_crypto' => $include_tax,
        );
        
        // Add separate payment requirements if needed
        if (!$include_shipping && $shipping_total > 0) {
            $payment_breakdown['shipping_payment_required'] = true;
            $payment_breakdown['shipping_payment_method'] = $this->get_option('shipping_payment_method');
        }
        if (!$include_tax && $tax_total > 0) {
            $payment_breakdown['tax_payment_required'] = true;
            $payment_breakdown['tax_payment_method'] = $this->get_option('tax_payment_method');
        }
        
        return array(
            'name' => $order_name,
            'details' => 'Payment for WooCommerce order #' . $order->get_order_number() . ' with ' . count($product_details) . ' product(s)',
            'currency' => $order->get_currency(),
            'amount' => (float) $crypto_amount,
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
                'payment_breakdown' => $payment_breakdown,
                'currency' => $order->get_currency(),
                'individual_products' => $product_names,
                'product_count' => count($product_details),
                'products' => $product_details,
                'total_amount' => (float) $order->get_total(),
                'total_items' => $total_items,
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
            'success_url' => '',
            'cancel_url' => ''
        );
    }
    
    /**
     * Store checkout URL for automatic opening
     */
    private function store_checkout_url($checkout_url) {
        // Store in session for immediate redirect
        if (!session_id()) {
            session_start();
        }
        $_SESSION['coinsub_checkout_url'] = $checkout_url;
        
        // Also store in transient for backup
        set_transient('coinsub_checkout_' . get_current_user_id(), $checkout_url, 300); // 5 minutes
    }
    
    /**
     * Add JavaScript for automatic checkout URL opening
     */
    public function add_checkout_script() {
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_SESSION['coinsub_checkout_url'])) {
            $checkout_url = $_SESSION['coinsub_checkout_url'];
            unset($_SESSION['coinsub_checkout_url']); // Clear after use
            
            ?>
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                // Open CoinSub checkout in new tab
                window.open('<?php echo esc_js($checkout_url); ?>', '_blank');
                
                // Also show a message to the user
                if (typeof wc_add_notice === 'function') {
                    wc_add_notice('<?php echo esc_js(__('Redirecting to CoinSub checkout...', 'coinsub-commerce')); ?>', 'notice');
                }
            });
            </script>
            <?php
        }
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
