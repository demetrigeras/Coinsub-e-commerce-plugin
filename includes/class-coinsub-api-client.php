<?php
/**
 * CoinSub API Client
 * 
 * Handles communication with the CoinSub API
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_API_Client {
    
    /**
     * API base URL
     */
    private $api_base_url;
    
    /**
     * Merchant ID
     */
    private $merchant_id;
    
    /**
     * API key (if required)
     */
    private $api_key;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_settings();
    }
    
    /**
     * Load settings from payment gateway or global options
     */
    private function load_settings() {
        // Try to get settings from payment gateway first, then fallback to global options
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());

        // CoinSub API base URL - using development environment
        $this->api_base_url = 'https://dev-api.coinsub.io/v1'; // Development API with v1 prefix
        // For production, use: 'https://api.coinsub.io/v1'
        
        // Use working credentials as defaults
        $this->merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : 'ca875a80-9b10-40ce-85c0-5af81856733a';
        $this->api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : 'abf3e9e5-0140-4fda-abc9-7dd87a358852';
    }
    
    /**
     * Update settings (called when gateway settings change)
     */
    public function update_settings($api_base_url, $merchant_id, $api_key) {
        $this->api_base_url = $api_base_url;
        $this->merchant_id = $merchant_id;
        $this->api_key = $api_key;
    }
    
    /**
     * Create a purchase session
     */
    public function create_purchase_session($order_data) {
        $endpoint = $this->api_base_url . '/purchase/session/start';
        error_log('🌐 CoinSub API - Calling: ' . $endpoint);
        error_log('🌐 CoinSub API - Amount: ' . $order_data['amount']);
        
        $payload = array(
            'name' => $order_data['name'],
            'details' => $order_data['details'],
            'currency' => $order_data['currency'],
            'amount' => $order_data['amount'],
            'recurring' => $order_data['recurring'] ?? false,
            'metadata' => $order_data['metadata'],
            'success_url' => $order_data['success_url'],
            'cancel_url' => $order_data['cancel_url']
        );
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        if (!empty($this->api_key)) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        
        // Extract purchase session ID and URL from response
        $purchase_session_id = $data['data']['purchase_session_id'] ?? null;
        $checkout_url = $data['data']['url'] ?? null;
        
        // Remove 'sess_' prefix if present (CoinSub returns sess_UUID but checkout needs just UUID)
        if ($purchase_session_id && strpos($purchase_session_id, 'sess_') === 0) {
            $purchase_session_id = substr($purchase_session_id, 5); // Remove 'sess_' prefix
        }
        
        return array(
            'purchase_session_id' => $purchase_session_id,
            'checkout_url' => $checkout_url,
            'raw_data' => $data
        );
    }
    
    /**
     * Get purchase session status
     */
    public function get_purchase_session_status($purchase_session_id) {
        $endpoint = $this->api_base_url . '/purchase/status/' . $purchase_session_id;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
            
        );
        
        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['message']) ? $data['message'] : 'API request failed');
        }
        
        return $data;
    }
    
    /**
     * Create an order in CoinSub
     */
    public function create_order($order_data) {
        $endpoint = $this->api_base_url . '/commerce/orders';
        error_log('🌐 CoinSub API - Creating order at: ' . $endpoint);
        error_log('🌐 CoinSub API - Order total: ' . $order_data['total']);
        
        $payload = array(
            'items' => $order_data['items'],
            'total' => $order_data['total'],
            'currency' => $order_data['currency'],
            'status' => 'cart'
        );
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
            
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 201) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        
        return $data;
    }
    
    /**
     * Checkout an order (convert to purchase session)
     */
    public function checkout_order($order_id, $purchase_session_id) {
        $endpoint = $this->api_base_url . '/commerce/orders/' . $order_id . '/checkout';
        
        $payload = array(
            'purchase_session_id' => $purchase_session_id
        );
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
           
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        
        return $data;
    }
    
    /**
     * Create a product in CoinSub commerce_products table
     */
    public function create_product($product_data) {
        $endpoint = $this->api_base_url . '/commerce/products';
        error_log('🌐 CoinSub API - Creating product: ' . $product_data['name']);
        error_log('🌐 CoinSub API - Endpoint: ' . $endpoint);
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
           
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($product_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 201) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        
        return $data;
    }
    
    /**
     * Get product by WooCommerce product ID
     */
    public function get_product_by_woocommerce_id($woocommerce_product_id) {
        $endpoint = $this->api_base_url . '/commerce/products?woocommerce_id=' . $woocommerce_product_id;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
            
        );
        
        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        
        return $data;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $endpoint = $this->api_base_url . '/purchase/status/test';
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
            
        );
        
        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        return wp_remote_retrieve_response_code($response) === 200;
    }
}
