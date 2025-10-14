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
        $this->api_base_url = 'https://dev-api.coinsub.io/v1/commerce'; // Development API with v1/commerce prefix
        // For production, use: 'https://api.coinsub.io/v1/commerce'
        
        // Get merchant credentials from settings
        $this->merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $this->api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
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
        // Purchase session uses base v1 URL, not /commerce
        $endpoint = 'https://dev-api.coinsub.io/v1/purchase/session/start';
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
        
        // Add subscription fields if recurring
        if (!empty($order_data['recurring']) && $order_data['recurring'] === true) {
            if (isset($order_data['frequency'])) {
                $payload['frequency'] = $order_data['frequency'];
            }
            if (isset($order_data['interval'])) {
                $payload['interval'] = $order_data['interval'];
            }
            if (isset($order_data['duration'])) {
                $payload['duration'] = $order_data['duration'];
            }
        }
        
        error_log('🌐 CoinSub API - Full Payload: ' . json_encode($payload));
        
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
        
        error_log('🌐 CoinSub API - Purchase session response data: ' . json_encode($data));
        error_log('🌐 CoinSub API - Extracted session ID: ' . $purchase_session_id);
        error_log('🌐 CoinSub API - Extracted checkout URL: ' . $checkout_url);
        
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
        $endpoint = $this->api_base_url . '/orders';
        error_log('🌐 CoinSub API - Creating order at: ' . $endpoint);
        error_log('🌐 CoinSub API - Order total: ' . $order_data['total']);
        
        $payload = array(
            'items' => $order_data['items'],
            'total' => $order_data['total'],
            'currency' => $order_data['currency'],
            'status' => isset($order_data['status']) ? $order_data['status'] : 'cart',
            'shipping_cost' => isset($order_data['shipping_cost']) ? $order_data['shipping_cost'] : (isset($order_data['shipping']) ? $order_data['shipping'] : 0),
            'tax_cost' => isset($order_data['tax_cost']) ? $order_data['tax_cost'] : (isset($order_data['tax']) ? $order_data['tax'] : 0),
            'product_price' => isset($order_data['product_price']) ? $order_data['product_price'] : (isset($order_data['subtotal']) ? $order_data['subtotal'] : $order_data['total']),
            'commerce_company_type' => 'woocommerce'  // Always woocommerce
        );
        
        // Add purchase_session_id if provided (WooCommerce session ID)
        if (isset($order_data['purchase_session_id'])) {
            $payload['purchase_session_id'] = $order_data['purchase_session_id'];
        }
        
        // Add recurring flag if present
        if (isset($order_data['recurring'])) {
            $payload['recurring'] = $order_data['recurring'];
        }
        
        // Add metadata if present
        if (isset($order_data['metadata'])) {
            $payload['metadata'] = $order_data['metadata'];
        }
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
            
        );
        
        error_log('🌐 CoinSub API - Request payload: ' . json_encode($payload));
        error_log('🌐 CoinSub API - Request headers: ' . json_encode($headers));
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub API - Order creation network error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($body, true);
        
        error_log('🌐 CoinSub API - Order response status: ' . $status_code);
        error_log('🌐 CoinSub API - Order response body: ' . substr($body, 0, 500));
        
        if ($status_code !== 201) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('❌ CoinSub API - Order creation failed: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('✅ CoinSub API - Order created successfully with ID: ' . ($data['id'] ?? 'unknown'));
        return $data;
    }
    
    /**
     * Update an existing order in CoinSub
     */
    public function update_order($order_id, $order_data) {
        $endpoint = $this->api_base_url . '/orders/update/' . $order_id;
        error_log('🌐 CoinSub API - Updating order: ' . $order_id);
        error_log('🌐 CoinSub API - New total: ' . $order_data['total']);
        
        $payload = array(
            'items' => $order_data['items'],
            'total' => $order_data['total'],
            'currency' => $order_data['currency'],
            'status' => $order_data['status'],
            'shipping_cost' => isset($order_data['shipping_cost']) ? $order_data['shipping_cost'] : 0,
            'tax_cost' => isset($order_data['tax_cost']) ? $order_data['tax_cost'] : 0,
            'product_price' => isset($order_data['product_price']) ? $order_data['product_price'] : $order_data['total'],
            'commerce_company_type' => 'woocommerce'  // Always woocommerce
        );
        
        // Add purchase_session_id if provided (WooCommerce session ID initially)
        if (isset($order_data['purchase_session_id'])) {
            $payload['purchase_session_id'] = $order_data['purchase_session_id'];
        }
        
        // Add recurring flag if present
        if (isset($order_data['recurring'])) {
            $payload['recurring'] = $order_data['recurring'];
        }
        
        // Add metadata if present
        if (isset($order_data['metadata'])) {
            $payload['metadata'] = $order_data['metadata'];
        }
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
        );
        
        $response = wp_remote_request($endpoint, array(
            'method' => 'PUT',
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub API - Order update network error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($body, true);
        
        error_log('🌐 CoinSub API - Update response status: ' . $status_code);
        error_log('🌐 CoinSub API - Update response body: ' . substr($body, 0, 500));
        
        if ($status_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('❌ CoinSub API - Order update failed: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('✅ CoinSub API - Order updated successfully');
        return $data;
    }
    
    /**
     * Checkout an order (convert to purchase session)
     */
    public function checkout_order($order_id, $purchase_session_id) {
        $endpoint = $this->api_base_url . '/orders/' . $order_id . '/checkout';
        
        $payload = array(
            'purchase_session_id' => $purchase_session_id
        );
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
           
        );
        
        error_log('🌐 CoinSub API - Checkout order: ' . $order_id);
        error_log('🌐 CoinSub API - Purchase session: ' . $purchase_session_id);
        error_log('🌐 CoinSub API - Endpoint: ' . $endpoint);
        
        $response = wp_remote_request($endpoint, array(
            'method' => 'PUT',
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub API - Checkout order network error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($body, true);
        
        error_log('🌐 CoinSub API - Checkout response status: ' . $status_code);
        error_log('🌐 CoinSub API - Checkout response body: ' . substr($body, 0, 500));
        
        if ($status_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('❌ CoinSub API - Checkout order failed: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('✅ CoinSub API - Order checkout successful!');
        return $data;
    }
    
    /**
     * Create a product in CoinSub commerce_products table
     */
    public function create_product($product_data) {
        $endpoint = $this->api_base_url . '/products';
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
            error_log('❌ CoinSub API - Product creation network error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($body, true);
        
        error_log('🌐 CoinSub API - Product response status: ' . $status_code);
        error_log('🌐 CoinSub API - Product response body: ' . substr($body, 0, 500));
        
        if ($status_code !== 201) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('❌ CoinSub API - Product creation failed: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('✅ CoinSub API - Product created successfully with ID: ' . ($data['id'] ?? 'unknown'));
        return $data;
    }
    
    /**
     * Get product by WooCommerce product ID
     */
    public function get_product_by_woocommerce_id($woocommerce_product_id) {
        $endpoint = $this->api_base_url . '/products?woocommerce_id=' . $woocommerce_product_id;
        
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
    
    /**
     * Update commerce order status
     */
    public function update_order_status($order_id, $status) {
        $endpoint = $this->api_base_url . '/orders/updatestatus/' . $order_id;
        
        $payload = array(
            'status' => $status
        );
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_request($endpoint, array(
            'method' => 'PUT',
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('api_error', 'Failed to update status');
        }
        
        return true;
    }
    
    /**
     * Cancel a subscription agreement
     */
    public function cancel_agreement($agreement_id) {
        // Agreements endpoint is at /v1/agreements, not /v1/commerce
        $endpoint = 'https://dev-api.coinsub.io/v1/agreements/cancel/' . $agreement_id;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'Failed to cancel subscription');
        }
        
        return $data;
    }
    
    /**
     * Update commerce order status from webhook
     * ONLY UPDATES STATUS - DOES NOT TOUCH ITEMS!
     */
    public function update_commerce_order_from_webhook($order_id, $update_data) {
        // Use the STATUS-ONLY endpoint to avoid wiping items!
        $endpoint = $this->api_base_url . '/orders/updatestatus/' . $order_id;
        
        $payload = array(
            'status' => $update_data['status']
        );
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        error_log('🌐 CoinSub API - Updating commerce order STATUS from webhook: ' . $order_id);
        error_log('🌐 CoinSub API - New status: ' . $update_data['status']);
        
        $response = wp_remote_request($endpoint, array(
            'method' => 'PUT',
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub API - Webhook update network error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($body, true);
        
        error_log('🌐 CoinSub API - Webhook update response status: ' . $status_code);
        error_log('🌐 CoinSub API - Webhook update response body: ' . substr($body, 0, 500));
        
        if ($status_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('❌ CoinSub API - Webhook update failed: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('✅ CoinSub API - Commerce order status updated to: ' . $update_data['status']);
        return $data;
    }
}
