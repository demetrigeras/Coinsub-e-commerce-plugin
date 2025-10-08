<?php
/**
 * CoinSub Cart Synchronization
 * 
 * Handles real-time synchronization of WooCommerce cart with CoinSub orders
 * This ensures CoinSub orders exist BEFORE checkout, so purchase sessions
 * can use the correct price and name from the existing order.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_CoinSub_Cart_Sync {
    
    private $api_client;
    
    public function __construct() {
        // Initialize API client
        require_once plugin_dir_path(__FILE__) . 'class-coinsub-api-client.php';
        $this->api_client = new WC_CoinSub_API_Client();
        
        // Hook into cart events to sync products only
        add_action('woocommerce_add_to_cart', array($this, 'on_add_to_cart'), 10, 6);
        
        // Hook into checkout page load - this is when we have shipping/taxes
        add_action('woocommerce_checkout_init', array($this, 'on_checkout_init'), 10);
        
        // Hook into checkout order creation - link WC order to CoinSub order
        add_action('woocommerce_checkout_order_processed', array($this, 'on_checkout_processed'), 10, 3);
        
        error_log('🔄 CoinSub Cart Sync - Initialized');
    }
    
    /**
     * When item is added to cart, ensure product exists in CoinSub
     */
    public function on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        error_log('🛒 CoinSub Cart Sync - Item added to cart: Product ID ' . $product_id);
        
        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            error_log('❌ CoinSub Cart Sync - Product not found');
            return;
        }
        
        // Ensure product exists in CoinSub
        $this->sync_product($product);
    }
    
    /**
     * When checkout page loads - create/update order with shipping & taxes
     * This runs AFTER user enters shipping address and totals are calculated
     */
    public function on_checkout_init($checkout) {
        error_log('🛒 CoinSub Cart Sync - Checkout initialized');
        
        // We'll sync the order when checkout updates (AJAX) - that's when we have shipping/taxes
        add_action('woocommerce_checkout_update_order_review', array($this, 'on_checkout_update'), 10, 1);
    }
    
    /**
     * When checkout is updated via AJAX (shipping address entered, totals recalculated)
     */
    public function on_checkout_update($post_data) {
        error_log('🛒 CoinSub Cart Sync - Checkout updated (shipping/taxes calculated), syncing order...');
        $this->sync_cart_to_order();
    }
    
    /**
     * Sync a WooCommerce product to CoinSub
     */
    private function sync_product($product) {
        $product_id = $product->get_id();
        
        // Check if product already exists in CoinSub
        $coinsub_product_id = get_post_meta($product_id, '_coinsub_product_id', true);
        
        if ($coinsub_product_id) {
            error_log('✅ CoinSub Cart Sync - Product already synced: ' . $coinsub_product_id);
            return $coinsub_product_id;
        }
        
        // Create product in CoinSub
        $product_data = array(
            'name' => $product->get_name(),
            'description' => $product->get_short_description() ?: $product->get_description(),
            'price' => (float) $product->get_price(),
            'currency' => get_woocommerce_currency(),
            'woocommerce_id' => $product_id,
            'sku' => $product->get_sku() ?: 'wc-' . $product_id,
            'image_url' => wp_get_attachment_url($product->get_image_id())
        );
        
        error_log('📦 CoinSub Cart Sync - Creating product: ' . $product->get_name());
        
        $result = $this->api_client->create_product($product_data);
        
        if ($result && isset($result['id'])) {
            // Store CoinSub product ID
            update_post_meta($product_id, '_coinsub_product_id', $result['id']);
            error_log('✅ CoinSub Cart Sync - Product created: ' . $result['id']);
            return $result['id'];
        } else {
            error_log('❌ CoinSub Cart Sync - Failed to create product');
            return null;
        }
    }
    
    /**
     * Sync current cart to CoinSub order
     * This is the KEY function - it creates/updates the CoinSub order in real-time
     */
    private function sync_cart_to_order() {
        // Get current cart
        $cart = WC()->cart;
        
        if (!$cart || $cart->is_empty()) {
            error_log('🛒 CoinSub Cart Sync - Cart is empty, skipping sync');
            return;
        }
        
        // Get or create session-based order ID
        $session_order_id = WC()->session->get('coinsub_order_id');
        
        // Prepare order items
        $items = array();
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $cart_item['product_id'];
            
            // Ensure product exists in CoinSub
            $coinsub_product_id = get_post_meta($product_id, '_coinsub_product_id', true);
            if (!$coinsub_product_id) {
                $coinsub_product_id = $this->sync_product($product);
            }
            
            if ($coinsub_product_id) {
                $items[] = array(
                    'product_id' => $coinsub_product_id,
                    'quantity' => $cart_item['quantity'],
                    'price' => (float) $product->get_price()
                );
            }
        }
        
        if (empty($items)) {
            error_log('❌ CoinSub Cart Sync - No valid items to sync');
            return;
        }
        
        // Calculate totals including shipping and taxes
        $subtotal = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $tax_total = (float) $cart->get_total_tax();
        $total = (float) $cart->get_total('edit');
        
        // Prepare order data with full breakdown
        $order_data = array(
            'items' => $items,
            'subtotal' => $subtotal,
            'shipping' => $shipping_total,
            'tax' => $tax_total,
            'total' => $total,
            'currency' => get_woocommerce_currency(),
            'customer_email' => WC()->customer ? WC()->customer->get_email() : '',
            'status' => 'pending'
        );
        
        error_log('🛒 CoinSub Cart Sync - Order breakdown:');
        error_log('  - Subtotal: $' . $subtotal);
        error_log('  - Shipping: $' . $shipping_total);
        error_log('  - Tax: $' . $tax_total);
        error_log('  - TOTAL: $' . $total);
        
        // Create or update order in CoinSub
        if ($session_order_id) {
            // TODO: Add update_order method to API client
            error_log('🔄 CoinSub Cart Sync - Would update existing order: ' . $session_order_id);
            // For now, we'll create a new one each time
        }
        
        // Create new order
        $result = $this->api_client->create_order($order_data);
        
        if ($result && isset($result['id'])) {
            // Store order ID in session
            WC()->session->set('coinsub_order_id', $result['id']);
            error_log('✅ CoinSub Cart Sync - Order created/updated: ' . $result['id']);
            error_log('💰 CoinSub Cart Sync - Order total: $' . $order_data['total']);
            return $result['id'];
        } else {
            error_log('❌ CoinSub Cart Sync - Failed to create order');
            return null;
        }
    }
    
    /**
     * When checkout is processed, ensure we have the final order ready
     */
    public function on_checkout_processed($order_id, $posted_data, $order) {
        error_log('🛒 CoinSub Cart Sync - Checkout processed for WC Order #' . $order_id);
        
        // Get the CoinSub order ID from session
        $coinsub_order_id = WC()->session->get('coinsub_order_id');
        
        if ($coinsub_order_id) {
            // Store CoinSub order ID in WooCommerce order meta
            $order->update_meta_data('_coinsub_order_id', $coinsub_order_id);
            $order->save();
            error_log('✅ CoinSub Cart Sync - Linked WC Order #' . $order_id . ' to CoinSub Order ' . $coinsub_order_id);
        } else {
            error_log('⚠️ CoinSub Cart Sync - No CoinSub order found in session');
        }
    }
    
    /**
     * Get the current CoinSub order ID for the cart
     */
    public function get_current_order_id() {
        return WC()->session->get('coinsub_order_id');
    }
}

// Initialize cart sync
new WC_CoinSub_Cart_Sync();
