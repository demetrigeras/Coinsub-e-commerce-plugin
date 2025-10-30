<?php
/**
 * CoinSub Cart Synchronization
 * 
 * Tracks cart changes in real-time and keeps CoinSub order updated
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_CoinSub_Cart_Sync {
    
    private $api_client;
    
    public function __construct() {
        // Hook into cart events
        add_action('woocommerce_add_to_cart', array($this, 'on_cart_changed'), 10);
        add_action('woocommerce_cart_item_removed', array($this, 'on_cart_changed'), 10);
        add_action('woocommerce_cart_item_restored', array($this, 'on_cart_changed'), 10);
        add_action('woocommerce_after_cart_item_quantity_update', array($this, 'on_cart_changed'), 10);
        
        // Hook into checkout updates (when shipping/taxes calculated)
        add_action('woocommerce_checkout_update_order_review', array($this, 'on_checkout_update'), 10);
        
        error_log('🔄 CoinSub Cart Sync - Initialized');
    }
    
    /**
     * Get API client instance (lazy initialization)
     */
    private function get_api_client() {
        if ($this->api_client === null) {
            // Check if class exists before instantiating
            if (!class_exists('CoinSub_API_Client')) {
                error_log('❌ CoinSub Cart Sync - API Client class not loaded yet');
                return null;
            }
            $this->api_client = new CoinSub_API_Client();
        }
        return $this->api_client;
    }
    
    /**
     * When cart changes (item added, removed, quantity changed)
     */
    public function on_cart_changed() {
        error_log('🛒 CoinSub Cart Sync - Cart changed, updating order...');
        $this->sync_cart_to_order();
    }
    
    /**
     * When checkout is updated (shipping/taxes calculated)
     */
    public function on_checkout_update($post_data) {
        error_log('🛒 CoinSub Cart Sync - Checkout updated (shipping/taxes), updating order...');
        $this->sync_cart_to_order();
    }
    
    /**
     * Validate cart contents and store cart data in session
     */
    private function sync_cart_to_order() {
        // Check if WooCommerce is fully loaded
        if (!function_exists('WC') || !WC()->cart || !WC()->session) {
            error_log('🛒 CoinSub Cart Sync - WooCommerce not ready, skipping');
            return;
        }
        
        $cart = WC()->cart;
        
        if ($cart->is_empty()) {
            error_log('🛒 CoinSub Cart Sync - Cart is empty, skipping');
            return;
        }
        
        // Validate cart contents (subscriptions vs single products)
        $this->validate_cart_contents();
        
        // Calculate totals from WooCommerce cart
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
            $is_sub = $product->get_meta('_coinsub_subscription');
            
            if ($is_sub === 'yes') {
                $has_subscription = true;
                $subscription_data = array(
                    'frequency' => $product->get_meta('_coinsub_frequency'),
                    'interval' => $product->get_meta('_coinsub_interval'),
                    'duration' => $product->get_meta('_coinsub_duration')
                );
                
                break;
            }
        }
        
        // Store cart data in WooCommerce session for later use
        $cart_data = array(
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'tax' => $tax,
            'total' => $total,
            'currency' => get_woocommerce_currency(),
            'has_subscription' => $has_subscription,
            'subscription_data' => $subscription_data,
            'items' => $this->get_cart_items_data()
        );
        
        WC()->session->set('coinsub_cart_data', $cart_data);
        
        error_log('🛒 CoinSub Cart Sync - Cart data stored in session:');
        error_log('  Subtotal: $' . $subtotal);
        error_log('  Shipping: $' . $shipping);
        error_log('  Tax: $' . $tax);
        error_log('  TOTAL: $' . $total);
        error_log('  Has Subscription: ' . ($has_subscription ? 'YES' : 'NO'));
        
        return true;
    }
    
    /**
     * Validate cart contents - prevent mixing subscriptions and single products
     */
    private function validate_cart_contents() {
        $cart = WC()->cart;
        $has_subscription = false;
        $has_regular = false;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $is_subscription = $product->get_meta('_coinsub_subscription') === 'yes';
            
            if ($is_subscription) {
                $has_subscription = true;
            } else {
                $has_regular = true;
            }
        }
        
        // If both types exist, remove regular products and show notice
        if ($has_subscription && $has_regular) {
            $this->remove_regular_products_from_cart();
            wc_add_notice(__('Subscriptions must be purchased separately. Regular products have been removed from your cart.', 'coinsub'), 'notice');
        }
    }
    
    /**
     * Remove regular products from cart when subscription is present
     */
    private function remove_regular_products_from_cart() {
        $cart = WC()->cart;
        $cart_items = $cart->get_cart();
        
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $is_subscription = $product->get_meta('_coinsub_subscription') === 'yes';
            
            if (!$is_subscription) {
                $cart->remove_cart_item($cart_item_key);
                error_log('🛒 Removed regular product from cart: ' . $product->get_name());
            }
        }
    }
    
    /**
     * Get cart items data for metadata
     */
    private function get_cart_items_data() {
        $items = array();
        $cart = WC()->cart;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $items[] = array(
                'product_id' => $product->get_id(),
                'name' => $product->get_name(),
                'quantity' => (int) $cart_item['quantity'],
                'price' => (float) $product->get_price(),
                'is_subscription' => $product->get_meta('_coinsub_subscription') === 'yes'
            );
        }
        
        return $items;
    }
    
    /**
     * Get current cart data from session
     */
    public function get_cart_data() {
        return WC()->session->get('coinsub_cart_data');
    }
    
    /**
     * Clear cart data from session
     */
    public function clear_cart_data() {
        WC()->session->set('coinsub_cart_data', null);
    }
}
