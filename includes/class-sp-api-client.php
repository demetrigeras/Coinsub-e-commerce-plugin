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

        // Get environment setting (default to production)
        $environment = isset($gateway_settings['environment']) ? $gateway_settings['environment'] : 'production';
        
        // Set API URL based on environment
        if ($environment === 'test') {
            $this->api_base_url = 'https://test-api.coinsub.io/v1'; // Test environment
        } else {
            $this->api_base_url = 'https://api.coinsub.io/v1'; // Production
        }
        
        // Get merchant credentials from settings
        $this->merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $this->api_key = isset($gateway_settings['refunds_api_key']) && !empty($gateway_settings['refunds_api_key']) ? $gateway_settings['refunds_api_key'] : (isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '');
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
        // Purchase session uses base v1 URL
        $endpoint = rtrim($this->api_base_url, '/') . '/purchase/session/start';
        
        error_log('API Base URL: ' . $this->api_base_url);
        error_log('Full Endpoint: ' . $endpoint);
        error_log('Order Amount: ' . $order_data['amount'] . ' ' . $order_data['currency']);
        
        $payload = array(
            'name' => $order_data['name'],
            'details' => $order_data['details'],
            'currency' => $order_data['currency'],
            'amount' => $order_data['amount'],
            'recurring' => $order_data['recurring'] ?? false,
            'metadata' => $order_data['metadata'],
            'success_url' => $order_data['success_url'],
            'cancel_url' => $order_data['cancel_url'],
            'failure_url' => $order_data['failure_url'] ?? $order_data['cancel_url'] // Use cancel_url as fallback if failure_url not provided
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
        error_log('🌐 CoinSub API - Success URL being sent: ' . ($payload['success_url'] ?? 'NOT SET'));
      
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        if (!empty($this->api_key)) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }
        
        // Log timing for API call
        $start_time = microtime(true);
        error_log('⏱️ CoinSub API - Starting purchase session API call at ' . date('H:i:s'));
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 60 // Increased to 60 seconds for slow networks
        ));
        
        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);
        error_log('⏱️ CoinSub API - Purchase session API call completed in ' . $duration . ' seconds');
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub API - Error after ' . $duration . ' seconds: ' . $response->get_error_message());
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
        
        error_log('✅ API RESPONSE RECEIVED');
        error_log('   Session ID: ' . $purchase_session_id);
        error_log('   Checkout URL from API: ' . $checkout_url);
        error_log('   Using API checkout URL directly (no reconstruction)');
     
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
    
    // REMOVED: create_order - using WooCommerce-only approach
    
    // REMOVED: update_order - using WooCommerce-only approach
    
    // REMOVED: checkout_order - using WooCommerce-only approach
    
    // REMOVED: create_product - using WooCommerce-only approach
    
    // REMOVED: get_product_by_woocommerce_id - using WooCommerce-only approach
    
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
    
    // REMOVED: update_order_status - using WooCommerce-only approach
    
    /**
     * Cancel a subscription agreement
     */
    public function cancel_agreement($agreement_id) {
        // Agreements endpoint is at /v1/agreements, not /v1/commerce
        $endpoint = rtrim($this->api_base_url, '/') . '/agreements/cancel/' . $agreement_id;
        
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
     * Retrieve agreement data
     */
    public function retrieve_agreement($agreement_id) {
        $endpoint = rtrim($this->api_base_url, '/') . '/agreements/' . $agreement_id . '/retrieve_agreement';
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
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
     * Initiate a refund transfer request
     */
    public function refund_transfer_request($to_address, $amount, $chain_id, $token_symbol) {
        $endpoint = rtrim($this->api_base_url, '/') . '/merchants/transfer/request';
        
        // Debug API key and endpoint
        error_log('🔑 CoinSub Refund API - Full URL: ' . $endpoint);
        error_log('🔑 CoinSub Refund API - API Key: ' . ($this->api_key ? 'SET' : 'NOT SET'));
        error_log('🔑 CoinSub Refund API - Merchant ID: ' . ($this->merchant_id ?: 'NOT SET'));
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        $payload = array(
            'to_address' => $to_address,
            'amount' => (float)$amount,
            'chainId' => (int)$chain_id,
            'token' => $token_symbol
        );
        $response = wp_remote_post($endpoint, array('headers' => $headers, 'body' => json_encode($payload), 'timeout' => 30));
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
     * Get all payments for a merchant
     */
    public function get_all_payments() {
        $endpoint = rtrim($this->api_base_url, '/') . '/payments/all';
        
        // Log API request details
        error_log('🔍 CoinSub API - Get All Payments');
        error_log('🔍 Endpoint: ' . $endpoint);
        error_log('🔍 Merchant ID: ' . (empty($this->merchant_id) ? 'EMPTY!' : substr($this->merchant_id, 0, 8) . '...'));
        error_log('🔍 API Key: ' . (empty($this->api_key) ? 'EMPTY!' : 'SET'));
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub API - WP Error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('🔍 Response Code: ' . $response_code);
        error_log('🔍 Response Body: ' . substr($body, 0, 500));
        
        if ($response_code !== 200) {
            $error_message = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('❌ CoinSub API - Error: ' . $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        return $data;
    }
    
    /**
     * Get payment details for a specific payment
     */
    public function get_payment_details($payment_id) {
        $endpoint = rtrim($this->api_base_url, '/') . '/payments/' . $payment_id;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
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
     * Get merchant info (checks if submerchant and returns parent merchant ID)
     * Route: GET /v1/environment-variables/merchant-info
     * Only requires Merchant-ID header (no API key needed)
     */
    public function get_merchant_info($merchant_id) {
        $endpoint = rtrim($this->api_base_url, '/') . '/environment-variables/merchant-info';
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 🌐🌐🌐 MERCHANT INFO API CALL 🌐🌐🌐');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📍 Endpoint: ' . $endpoint);
        error_log('CoinSub API: 🔑 Merchant-ID header: ' . $merchant_id);
        error_log('CoinSub API: ℹ️  No API key required for this endpoint');
        error_log('CoinSub API: 📤 Request Method: GET');
        error_log('CoinSub API: 📤 Request Headers: ' . json_encode(array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $merchant_id
        )));
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $merchant_id
            // No API-Key header needed!
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log('CoinSub API: ❌❌❌ WP_Error getting merchant info ❌❌❌');
            error_log('CoinSub API: Error message: ' . $response->get_error_message());
            error_log('CoinSub API: Error code: ' . $response->get_error_code());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📥📥📥 MERCHANT INFO API RESPONSE 📥📥📥');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📊 Response Code: ' . $response_code);
        error_log('CoinSub API: 📦 Response Body (raw): ' . $body);
        error_log('CoinSub API: 📦 Response Body (pretty): ' . json_encode($data, JSON_PRETTY_PRINT));
        error_log('CoinSub API: 🔑 Response Keys: ' . (is_array($data) ? implode(', ', array_keys($data)) : 'NOT AN ARRAY'));
        
        if ($response_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('CoinSub API: ❌❌❌ ERROR RESPONSE ❌❌❌');
            error_log('CoinSub API: Error message: ' . $error_msg);
            error_log('═══════════════════════════════════════════════════════════');
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('CoinSub API: ✅✅✅ SUCCESS - Merchant info retrieved ✅✅✅');
        error_log('CoinSub API: 📊 Is Submerchant: ' . (isset($data['is_submerchant']) ? ($data['is_submerchant'] ? 'YES' : 'NO') : 'UNKNOWN'));
        error_log('CoinSub API: 📊 Parent Merchant ID: ' . (isset($data['parent_merchant_id']) ? $data['parent_merchant_id'] : 'N/A'));
        error_log('═══════════════════════════════════════════════════════════');
        
        return $data;
    }
    
    /**
     * Get submerchant data (includes parent merchant ID) - DEPRECATED
     * Use get_merchant_info() instead - it doesn't require API key
     * Route: GET /v1/merchants/:merchant_id (submerchant routes registered under /v1/merchants)
     */
    public function get_submerchant($merchant_id) {
        // Based on Go routes structure:
        // merchantsGroup := r.Group("/v1/merchants")
        // registerSubmerchantRoutes(merchantsGroup) with r.GET("/:merchant_id", ...)
        // So the full path is: /v1/merchants/:merchant_id
        // NOT /v1/merchants/submerchants/:merchant_id
        $endpoint = rtrim($this->api_base_url, '/') . '/merchants/' . $merchant_id;
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 🌐🌐🌐 SUBMERCHANT API CALL 🌐🌐🌐');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📍 Endpoint: ' . $endpoint);
        error_log('CoinSub API: 🔑 Merchant-ID header: ' . $this->merchant_id);
        error_log('CoinSub API: 🔑 API-Key header: ' . (strlen($this->api_key) > 0 ? substr($this->api_key, 0, 10) . '...' : 'EMPTY'));
        error_log('CoinSub API: 📤 Request Method: GET');
        error_log('CoinSub API: 📤 Request Headers: ' . json_encode(array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => (strlen($this->api_key) > 0 ? substr($this->api_key, 0, 10) . '...' : 'EMPTY')
        )));
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log('CoinSub API: ❌❌❌ WP_Error getting submerchant ❌❌❌');
            error_log('CoinSub API: Error message: ' . $response->get_error_message());
            error_log('CoinSub API: Error code: ' . $response->get_error_code());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📥📥📥 SUBMERCHANT API RESPONSE 📥📥📥');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📊 Response Code: ' . $response_code);
        error_log('CoinSub API: 📦 Response Body (raw): ' . $body);
        error_log('CoinSub API: 📦 Response Body (pretty): ' . json_encode($data, JSON_PRETTY_PRINT));
        error_log('CoinSub API: 🔑 Response Keys: ' . (is_array($data) ? implode(', ', array_keys($data)) : 'NOT AN ARRAY'));
        
        if ($response_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('CoinSub API: ❌❌❌ ERROR RESPONSE ❌❌❌');
            error_log('CoinSub API: Error message: ' . $error_msg);
            error_log('═══════════════════════════════════════════════════════════');
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('CoinSub API: ✅✅✅ SUCCESS - Submerchant data retrieved ✅✅✅');
        error_log('CoinSub API: 📊 Data structure: ' . json_encode(array_keys($data)));
        error_log('═══════════════════════════════════════════════════════════');
        
        return $data;
    }
    
    /**
     * Get environment configs (whitelabel branding data)
     * Note: This endpoint does not require authentication headers
     * Endpoint: GET /v1/environment-variables/domain-logo
     */
    public function get_environment_configs() {
        $endpoint = rtrim($this->api_base_url, '/') . '/environment-variables/domain-logo';
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 🌐🌐🌐 ENVIRONMENT CONFIGS API CALL 🌐🌐🌐');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📍 Endpoint: ' . $endpoint);
        error_log('CoinSub API: 📤 Request Method: GET');
        error_log('CoinSub API: 📤 Request Headers: ' . json_encode(array('Content-Type' => 'application/json')));
        error_log('CoinSub API: ℹ️  No authentication required for this endpoint');
        
        // No headers needed for this endpoint
        $headers = array(
            'Content-Type' => 'application/json'
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log('CoinSub API: ❌❌❌ WP_Error getting environment configs ❌❌❌');
            error_log('CoinSub API: Error message: ' . $response->get_error_message());
            error_log('CoinSub API: Error code: ' . $response->get_error_code());
            error_log('═══════════════════════════════════════════════════════════');
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📥📥📥 ENVIRONMENT CONFIGS API RESPONSE 📥📥📥');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📊 Response Code: ' . $response_code);
        error_log('CoinSub API: 📦 Response Body (raw, first 2000 chars): ' . substr($body, 0, 2000));
        error_log('CoinSub API: 📦 Response Body (pretty, first 5000 chars): ' . substr(json_encode($data, JSON_PRETTY_PRINT), 0, 5000));
        error_log('CoinSub API: 🔑 Response Keys: ' . (is_array($data) ? implode(', ', array_keys($data)) : 'NOT AN ARRAY'));
        
        if ($response_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('CoinSub API: ❌❌❌ ERROR RESPONSE ❌❌❌');
            error_log('CoinSub API: Error message: ' . $error_msg);
            error_log('═══════════════════════════════════════════════════════════');
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('CoinSub API: ✅✅✅ SUCCESS - Environment configs retrieved ✅✅✅');
        if (isset($data['environment_configs'])) {
            error_log('CoinSub API: 📊 Found ' . count($data['environment_configs']) . ' environment configs');
            foreach ($data['environment_configs'] as $index => $config) {
                error_log('CoinSub API: 📋 Config #' . $index . ' - environment_id: ' . (isset($config['environment_id']) ? $config['environment_id'] : 'N/A'));
            }
        }
        error_log('═══════════════════════════════════════════════════════════');
        
        return $data;
    }
    
    /**
     * Get merchant config (merchant info + environment configs + domains in one call)
     * Endpoint: GET /v1/environment-variables/config
     * No API key required - only Merchant-ID header
     * 
     * @param string $merchant_id Merchant ID to check
     * @return array|WP_Error Merchant config response with is_submerchant, parent_merchant_id, environment_configs, merchant_domains
     */
    public function get_merchant_config($merchant_id) {
        $endpoint = rtrim($this->api_base_url, '/') . '/environment-variables/config';
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 🌐🌐🌐 MERCHANT CONFIG API CALL 🌐🌐🌐');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📍 Endpoint: ' . $endpoint);
        error_log('CoinSub API: 📤 Request Method: GET');
        error_log('CoinSub API: 📤 Merchant-ID Header: ' . $merchant_id);
        error_log('CoinSub API: ℹ️  No API key required for this endpoint');
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $merchant_id
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log('CoinSub API: ❌❌❌ WP_Error getting merchant config ❌❌❌');
            error_log('CoinSub API: Error message: ' . $response->get_error_message());
            error_log('CoinSub API: Error code: ' . $response->get_error_code());
            error_log('═══════════════════════════════════════════════════════════');
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📥📥📥 MERCHANT CONFIG API RESPONSE 📥📥📥');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('CoinSub API: 📊 Response Code: ' . $response_code);
        error_log('CoinSub API: 📦 Response Body (pretty, first 5000 chars): ' . substr(json_encode($data, JSON_PRETTY_PRINT), 0, 5000));
        
        if ($response_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('CoinSub API: ❌❌❌ ERROR RESPONSE ❌❌❌');
            error_log('CoinSub API: Error message: ' . $error_msg);
            error_log('═══════════════════════════════════════════════════════════');
            return new WP_Error('api_error', $error_msg);
        }
        
        error_log('CoinSub API: ✅✅✅ SUCCESS - Merchant config retrieved ✅✅✅');
        if (isset($data['is_submerchant'])) {
            error_log('CoinSub API: 📊 Is Submerchant: ' . ($data['is_submerchant'] ? 'YES' : 'NO'));
            if (isset($data['parent_merchant_id'])) {
                error_log('CoinSub API: 📊 Parent Merchant ID: ' . $data['parent_merchant_id']);
            }
        }
        if (isset($data['environment_configs'])) {
            error_log('CoinSub API: 📊 Found ' . count($data['environment_configs']) . ' environment configs');
        }
        error_log('═══════════════════════════════════════════════════════════');
        
        return $data;
    }
    
    /**
     * Create a webhook for the merchant
     * 
     * @param string $webhook_url The webhook URL to register
     * @param string $submerchant_id Optional: Submerchant ID to use in URL path (if merchant is submerchant)
     * @param string $parent_merchant_id Optional: Parent merchant ID to use in Merchant-ID header (if merchant is submerchant)
     * @return array|WP_Error Webhook data or error
     */
    public function create_webhook($webhook_url, $submerchant_id = null, $parent_merchant_id = null) {
        if (empty($this->merchant_id) || empty($this->api_key)) {
            return new WP_Error('missing_credentials', 'Merchant ID and API key are required to create webhook');
        }
        
        // For submerchants: URL uses submerchant_id, header uses parent_merchant_id
        // For regular merchants: URL and header both use merchant_id
        $merchant_id_for_url = $submerchant_id ? $submerchant_id : $this->merchant_id;
        $merchant_id_for_header = $parent_merchant_id ? $parent_merchant_id : $this->merchant_id;
        
        // Webhook endpoint: POST /v1/merchants/:merchant_id/webhooks
        $endpoint = rtrim($this->api_base_url, '/') . '/merchants/' . $merchant_id_for_url . '/webhooks';
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $merchant_id_for_header,
            'API-Key' => $this->api_key
        );
        
        $payload = array(
            'url' => $webhook_url
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('🔔 CoinSub API - Webhook creation failed: ' . $response->get_error_message());
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($response_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'Webhook creation failed';
            error_log('CoinSub API - Webhook creation error: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        return $data;
    }
    
    /**
     * List webhooks for the merchant
     * 
     * @param string $status Optional: 'active', 'disabled', or 'all'
     * @param string $submerchant_id Optional: Submerchant ID to use in URL path (if merchant is submerchant)
     * @param string $parent_merchant_id Optional: Parent merchant ID to use in Merchant-ID header (if merchant is submerchant)
     * @return array|WP_Error Webhooks list or error
     */
    public function list_webhooks($status = 'all', $submerchant_id = null, $parent_merchant_id = null) {
        if (empty($this->merchant_id) || empty($this->api_key)) {
            return new WP_Error('missing_credentials', 'Merchant ID and API key are required to list webhooks');
        }
        
        // For submerchants: URL uses submerchant_id, header uses parent_merchant_id
        // For regular merchants: URL and header both use merchant_id
        $merchant_id_for_url = $submerchant_id ? $submerchant_id : $this->merchant_id;
        $merchant_id_for_header = $parent_merchant_id ? $parent_merchant_id : $this->merchant_id;
        
        // Webhook endpoint: GET /v1/merchants/:merchant_id/webhooks?status=active|disabled|all
        $endpoint = rtrim($this->api_base_url, '/') . '/merchants/' . $merchant_id_for_url . '/webhooks';
        if ($status !== 'all') {
            $endpoint .= '?status=' . urlencode($status);
        }
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $merchant_id_for_header,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('🔔 CoinSub API - List webhooks failed: ' . $response->get_error_message());
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($response_code !== 200) {
            $error_msg = isset($data['error']) ? $data['error'] : 'Failed to list webhooks';
            error_log('CoinSub API - List webhooks error: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        return $data;
    }

    // REMOVED: update_commerce_order_from_webhook - using WooCommerce-only approach
}
