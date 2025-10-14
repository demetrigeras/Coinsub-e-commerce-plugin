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
     * Sync current cart to CoinSub order (CREATE or UPDATE)
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
        
        // Get existing CoinSub order ID from session
        $coinsub_order_id = WC()->session->get('coinsub_order_id');
        
        // Prepare order items - ensure products exist in CoinSub first
        $items = array();
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_id = $cart_item['product_id'];
            
            // Get or create CoinSub product ID
            $coinsub_product_id = $this->get_or_create_product($product);
            
            if (!$coinsub_product_id) {
                error_log('❌ CoinSub Cart Sync - Failed to get/create product: ' . $product->get_name());
                continue; // Skip this item
            }
            
            $items[] = array(
                'product_id' => $coinsub_product_id, // CoinSub product ID
                'name' => $product->get_name(),
                'quantity' => (int) $cart_item['quantity'],
                'price' => (float) $product->get_price()
            );
        }
        
        if (empty($items)) {
            error_log('❌ CoinSub Cart Sync - No items to sync');
            return;
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        
        $shipping = (float) $cart->get_shipping_total();
        $tax = (float) $cart->get_total_tax();
        $total = $subtotal + $shipping + $tax;
        
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
            error_log('🔍 Product ' . $product->get_name() . ' - Is subscription: ' . $is_sub);
            
            if ($is_sub === 'yes') {
                $has_subscription = true;
                $subscription_data = array(
                    'frequency' => $product->get_meta('_coinsub_frequency'),
                    'interval' => $product->get_meta('_coinsub_interval'),
                    'duration' => $product->get_meta('_coinsub_duration')
                );
                error_log('✅ Subscription detected! Frequency: ' . $subscription_data['frequency'] . ', Interval: ' . $subscription_data['interval'] . ', Duration: ' . $subscription_data['duration']);
                break;
            }
        }
        
        if ($has_subscription) {
            error_log('🔄 Cart has subscription - will pass to order');
        }
        
        // Get WooCommerce session ID to store in purchase_session_id field
        $wc_session_id = WC()->session ? WC()->session->get_customer_id() : null;
        error_log('🔑 WooCommerce Session ID: ' . ($wc_session_id ? $wc_session_id : 'NOT AVAILABLE'));
        
        $order_data = array(
            'items' => $items,
            'product_price' => $subtotal,
            'shipping_cost' => $shipping,
            'tax_cost' => $tax,
            'total' => $total,
            'currency' => get_woocommerce_currency(),
            'status' => 'cart',
            'purchase_session_id' => $wc_session_id,  // Store WC session ID here!
            'commerce_company_type' => 'woocommerce',  // Always woocommerce
            'recurring' => $has_subscription,
            'metadata' => array(
                'origin' => 'woocommerce',
                'platform' => 'woocommerce',
                'store_url' => get_site_url(),
                'subscription_data' => $subscription_data
            )
        );
        
        error_log('📦 Sending purchase_session_id to API: ' . ($wc_session_id ? $wc_session_id : 'NULL'));
        
        error_log('🛒 CoinSub Cart Sync - Order breakdown:');
        error_log('  Items: ' . count($items));
        error_log('  Subtotal: $' . $subtotal);
        error_log('  Shipping: $' . $shipping);
        error_log('  Tax: $' . $tax);
        error_log('  TOTAL: $' . $total);
        
        try {
            $api_client = $this->get_api_client();
            if (!$api_client) {
                error_log('❌ CoinSub Cart Sync - API client not available, skipping');
                return null;
            }
            
            if ($coinsub_order_id) {
                // UPDATE existing order
                error_log('🔄 CoinSub Cart Sync - Updating existing order: ' . $coinsub_order_id);
                $result = $api_client->update_order($coinsub_order_id, $order_data);
                
                if ($result && !is_wp_error($result)) {
                    error_log('✅ CoinSub Cart Sync - Order updated successfully');
                    return $coinsub_order_id; // Return existing order ID
                } else {
                    error_log('❌ CoinSub Cart Sync - Failed to update order (maybe deleted), creating new one...');
                    // Clear the old order ID from session
                    WC()->session->set('coinsub_order_id', null);
                    $coinsub_order_id = null; // Force create new
                }
            }
            
            if (!$coinsub_order_id) {
                // CREATE new order
                error_log('🆕 CoinSub Cart Sync - Creating new order...');
                $result = $api_client->create_order($order_data);
                
                if ($result && isset($result['id']) && !is_wp_error($result)) {
                    $coinsub_order_id = $result['id'];
                    WC()->session->set('coinsub_order_id', $coinsub_order_id);
                    error_log('✅ CoinSub Cart Sync - Order created: ' . $coinsub_order_id);
                } else {
                    error_log('❌ CoinSub Cart Sync - Failed to create order');
                }
            }
        } catch (Exception $e) {
            error_log('❌ CoinSub Cart Sync - Exception: ' . $e->getMessage());
        } catch (Error $e) {
            error_log('❌ CoinSub Cart Sync - Fatal Error: ' . $e->getMessage());
        }
        
        return $coinsub_order_id;
    }
    
    /**
     * Get or create product in CoinSub
     */
    private function get_or_create_product($product) {
        $wc_product_id = $product->get_id();
        
        // Check if we already have CoinSub product ID stored
        $coinsub_product_id = get_post_meta($wc_product_id, '_coinsub_product_id', true);
        
        if ($coinsub_product_id) {
            error_log('✅ CoinSub Cart Sync - Product already exists: ' . $coinsub_product_id);
            return $coinsub_product_id;
        }
        
        // Try to find product in CoinSub by WooCommerce ID
        $api_client = $this->get_api_client();
        if (!$api_client) {
            return null;
        }
        
        // Create product in CoinSub
        $price = (float) $product->get_price();
        
        // Ensure price is valid (not 0 or negative)
        if ($price <= 0) {
            error_log('❌ CoinSub Cart Sync - Invalid price for product: ' . $product->get_name() . ' (Price: ' . $price . ')');
            return null;
        }
        
        $image_url = wp_get_attachment_url($product->get_image_id());
        $description = $product->get_short_description() ?: $product->get_description();
        
        $product_data = array(
            'name' => $product->get_name(),
            'price' => $price,
            'currency' => get_woocommerce_currency()
        );
        
        // Add optional fields only if they have values
        if ($description) {
            $product_data['description'] = $description;
        }
        if ($image_url) {
            $product_data['image_url'] = $image_url;
        }
        
        // Check if this is a subscription product
        $is_subscription = $product->get_meta('_coinsub_subscription') === 'yes';
        
        // Add metadata as object (not string)
        $product_data['metadata'] = array(
            'woocommerce_id' => $wc_product_id,
            'sku' => $product->get_sku() ?: '',
            'type' => $product->get_type(),
            'is_subscription' => $is_subscription
        );
        
        // Add subscription details if it's a subscription product
        if ($is_subscription) {
            $product_data['metadata']['subscription'] = array(
                'frequency' => $product->get_meta('_coinsub_frequency') ?: '1',
                'interval' => $product->get_meta('_coinsub_interval') ?: '2',
                'duration' => $product->get_meta('_coinsub_duration') ?: '0'
            );
        }
        
        error_log('📦 CoinSub Cart Sync - Creating product: ' . $product->get_name() . ' (Price: $' . $price . ')');
        
        try {
            $result = $api_client->create_product($product_data);
            
            // Check for WP_Error FIRST before accessing as array
            if (is_wp_error($result)) {
                error_log('❌ CoinSub Cart Sync - Product creation failed: ' . $result->get_error_message());
                return null;
            }
            
            if ($result && isset($result['id'])) {
                $coinsub_product_id = $result['id'];
                // Store for future use
                update_post_meta($wc_product_id, '_coinsub_product_id', $coinsub_product_id);
                error_log('✅ CoinSub Cart Sync - Product created: ' . $coinsub_product_id);
                return $coinsub_product_id;
            } else {
                error_log('❌ CoinSub Cart Sync - Failed to create product (no ID returned)');
                return null;
            }
        } catch (Exception $e) {
            error_log('❌ CoinSub Cart Sync - Product creation exception: ' . $e->getMessage());
            return null;
        } catch (Error $e) {
            error_log('❌ CoinSub Cart Sync - Product creation fatal error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get current CoinSub order ID
     */
    public function get_current_order_id() {
        return WC()->session->get('coinsub_order_id');
    }
}
