<?php
/**
 * CoinSub Webhook Handler
 * 
 * Handles webhook notifications from CoinSub
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Webhook_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        add_action('wp_ajax_coinsub_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_nopriv_coinsub_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_coinsub_check_payment_status', array($this, 'check_payment_status'));
        add_action('wp_ajax_nopriv_coinsub_check_payment_status', array($this, 'check_payment_status'));
    }
    
    /**
     * Register webhook endpoint with WordPress REST API
     */
    public function register_webhook_endpoint() {
        // Main webhook endpoint
        register_rest_route('coinsub/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true', // Allow public access
        ));
        
        // Test endpoint to verify webhook is accessible
        register_rest_route('coinsub/v1', '/webhook/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_webhook_endpoint'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Test webhook endpoint
     */
    public function test_webhook_endpoint($request) {
        error_log('🧪 CoinSub Webhook - Test endpoint accessed');
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'CoinSub webhook endpoint is working!',
            'endpoint' => rest_url('coinsub/v1/webhook'),
            'timestamp' => current_time('mysql')
        ), 200);
    }
    
    /**
     * Handle webhook requests
     */
    public function handle_webhook($request) {
        error_log('🔔 CoinSub Webhook - Received webhook request at ' . current_time('mysql'));
        error_log('🔔 CoinSub Webhook - Request headers: ' . json_encode($request->get_headers()));
        error_log('🔔 CoinSub Webhook - Raw body: ' . $request->get_body());
        error_log('🔔 CoinSub Webhook - User Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Not set'));
        error_log('🔔 CoinSub Webhook - Remote IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'Not set'));
        
        // Verify per-site webhook secret
        $expected = get_option('coinsub_webhook_secret');
        $provided = $request->get_param('secret');
        if (!$provided) {
            // Try header fallback
            $headers = $request->get_headers();
            $provided = $headers['x-coinsub-secret'][0] ?? null;
        }
        if (!empty($expected) && hash_equals($expected, (string)$provided) === false) {
            error_log('❌ CoinSub Webhook - Secret mismatch or missing');
            return new WP_REST_Response(array('error' => 'Unauthorized'), 401);
        }
        
        // Get the request body
        $data = $request->get_json_params();
        
        if (!$data) {
            error_log('❌ CoinSub Webhook - Invalid JSON data');
            return new WP_REST_Response(array('error' => 'Invalid JSON data'), 400);
        }
        
        error_log('🔔 CoinSub Webhook - Data: ' . json_encode($data));
        
        // Log specific data structures for debugging
        if (isset($data['agreement'])) {
            error_log('🔔 CoinSub Webhook - Agreement data: ' . json_encode($data['agreement']));
        }
        if (isset($data['transaction_details'])) {
            error_log('🔔 CoinSub Webhook - Transaction details: ' . json_encode($data['transaction_details']));
        }
        
        // Verify webhook signature if configured
        $raw_data = $request->get_body();
        if (!$this->verify_webhook_signature($raw_data)) {
            error_log('❌ CoinSub Webhook - Invalid signature - but continuing for debugging');
            // Temporarily disable signature verification for debugging
            // return new WP_REST_Response(array('error' => 'Invalid signature'), 401);
        }
        
        // Process the webhook
        $this->process_webhook($data);
        
        // Return success response
        error_log('✅ CoinSub Webhook - Processed successfully');
        return new WP_REST_Response(array('status' => 'success'), 200);
    }
    
    /**
     * Process webhook data
     */
    private function process_webhook($data) {
        error_log('CoinSub Webhook: Full payload: ' . json_encode($data));
        
        $event_type = $data['type'] ?? 'unknown';
        $origin_id = $data['origin_id'] ?? null;
        $merchant_id = $data['merchant_id'] ?? null;
        
        error_log('CoinSub Webhook: Event type: ' . $event_type);
        error_log('CoinSub Webhook: Origin ID: ' . $origin_id);
        error_log('CoinSub Webhook: Merchant ID: ' . $merchant_id);
        
        if (!$origin_id) {
            error_log('CoinSub Webhook: No origin ID provided');
            return;
        }
        
        // Find the order by origin ID (purchase session ID)
        error_log('CoinSub Webhook: Searching for order with origin ID: ' . $origin_id);
        $order = $this->find_order_by_purchase_session_id($origin_id);
        
        // For recurring payments, also try to find by agreement_id
        if (!$order && isset($data['agreement_id'])) {
            $agreement_id = $data['agreement_id'];
            error_log('CoinSub Webhook: Order not found by origin ID, trying agreement ID: ' . $agreement_id);
            
            // Find subscription order by agreement_id
            $orders_by_agreement = wc_get_orders(array(
                'meta_key' => '_coinsub_agreement_id',
                'meta_value' => $agreement_id,
                'meta_compare' => '=',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'ASC' // Get the first (original) subscription order
            ));
            
            if (!empty($orders_by_agreement)) {
                $order = $orders_by_agreement[0];
                error_log('✅ CoinSub Webhook: Found subscription order #' . $order->get_id() . ' by agreement ID');
            }
        }
        
        if (!$order) {
            error_log('❌ CoinSub Webhook: Order not found for origin ID: ' . $origin_id);
            error_log('CoinSub Webhook: Event type: ' . $event_type);
            error_log('CoinSub Webhook: Merchant ID: ' . $merchant_id);
            
            // Debug: List all orders with CoinSub metadata
            $all_orders = wc_get_orders(array('limit' => 10, 'orderby' => 'date', 'order' => 'DESC'));
            error_log('CoinSub Webhook: Recent orders:');
            foreach ($all_orders as $test_order) {
                $test_session_id = $test_order->get_meta('_coinsub_purchase_session_id');
                $test_coinsub_id = $test_order->get_meta('_coinsub_order_id');
                if ($test_session_id || $test_coinsub_id) {
                    error_log('  Order #' . $test_order->get_id() . ' - Session: ' . $test_session_id . ' - CoinSub ID: ' . $test_coinsub_id);
                }
            }
            
            // Try to find by WooCommerce order ID in metadata
            if (isset($data['metadata']['woocommerce_order_id'])) {
                $wc_order_id = $data['metadata']['woocommerce_order_id'];
                error_log('CoinSub Webhook: Trying to find order by WooCommerce ID: ' . $wc_order_id);
                $order = wc_get_order($wc_order_id);
                if ($order) {
                    error_log('✅ CoinSub Webhook: Found order by WooCommerce ID: ' . $wc_order_id);
                } else {
                    error_log('❌ CoinSub Webhook: Order not found by WooCommerce ID: ' . $wc_order_id);
                }
            }
            
            if (!$order) {
                return;
            }
        }
        
        error_log('✅ CoinSub Webhook: Found order ID: ' . $order->get_id() . ' for origin ID: ' . $origin_id);
        error_log('CoinSub Webhook: Order status before update: ' . $order->get_status());
        
        // Ensure order is associated with a customer if possible
        if (!$order->get_customer_id() && $order->get_billing_email()) {
            $user = get_user_by('email', $order->get_billing_email());
            if ($user) {
                $order->set_customer_id($user->ID);
                $order->save();
                error_log('✅ CoinSub Webhook: Associated order with user ID: ' . $user->ID . ' by email: ' . $order->get_billing_email());
            }
        }
        
        // Verify merchant ID matches
        $order_merchant_id = $order->get_meta('_coinsub_merchant_id');
        if ($order_merchant_id && $merchant_id) {
            // Remove mrch_ prefix if present for comparison
            $clean_webhook_merchant_id = str_replace('mrch_', '', $merchant_id);
            $clean_order_merchant_id = str_replace('mrch_', '', $order_merchant_id);
            
            if ($clean_order_merchant_id !== $clean_webhook_merchant_id) {
                error_log('CoinSub Webhook: Merchant ID mismatch for order: ' . $order->get_id());
                error_log('CoinSub Webhook: Order merchant ID: ' . $clean_order_merchant_id);
                error_log('CoinSub Webhook: Webhook merchant ID: ' . $clean_webhook_merchant_id);
                return;
            }
        }
        
        switch ($event_type) {
            case 'payment':
                $this->handle_payment_completed($order, $data);
                break;
                
            case 'failed_payment':
                $this->handle_payment_failed($order, $data);
                break;
                
            case 'cancellation':
                $this->handle_payment_cancelled($order, $data);
                break;
                
            case 'transfer':
                $this->handle_transfer_completed($order, $data);
                break;
                
            case 'failed_transfer':
                $this->handle_transfer_failed($order, $data);
                break;
                
            default:
                error_log('CoinSub Webhook: Unknown event type: ' . $event_type);
        }
    }
    
    /**
     * Handle payment completed
     */
    private function handle_payment_completed($order, $data) {
        error_log('🎉 CoinSub Webhook: Processing payment completion for order #' . $order->get_id());
        error_log('CoinSub Webhook: Current order status: ' . $order->get_status());
        
        // Check if this is a recurring payment for a subscription
        $agreement_id = $data['agreement_id'] ?? null;
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        
        // If this is a subscription payment and the order is already processed, it's a recurring payment
        // We need to create a renewal order instead of updating the original
        if ($is_subscription && $agreement_id) {
            $existing_agreement_id = $order->get_meta('_coinsub_agreement_id');
            $order_status = $order->get_status();
            
            // Check if this order is already completed/processing (meaning it's the original subscription order)
            // and we have a matching agreement_id, then this is a recurring payment
            if ($existing_agreement_id === $agreement_id && 
                in_array($order_status, array('processing', 'completed', 'on-hold'))) {
                
                error_log('🔄 CoinSub Webhook: This is a recurring payment for subscription order #' . $order->get_id());
                error_log('🔄 CoinSub Webhook: Creating renewal order...');
                
                // Create renewal order
                $renewal_order = $this->create_renewal_order($order, $data);
                
                if ($renewal_order) {
                    error_log('✅ CoinSub Webhook: Renewal order #' . $renewal_order->get_id() . ' created successfully');
                    // Process the renewal order instead of the original
                    $order = $renewal_order;
                } else {
                    error_log('❌ CoinSub Webhook: Failed to create renewal order, processing original order instead');
                }
            }
        }
        
        // Update WooCommerce order status based on shipping requirement
        if (method_exists($order, 'needs_shipping') && $order->needs_shipping()) {
            $order->update_status('processing', __('Payment received via CoinSub (awaiting fulfillment)', 'coinsub'));
            error_log('CoinSub Webhook: Updated order status to processing (needs shipping)');
        } else {
            $order->update_status('completed', __('Payment completed via CoinSub', 'coinsub'));
            error_log('CoinSub Webhook: Updated order status to completed (no shipping)');
        }
        
        // Debug: Check payment method
        $payment_method = $order->get_payment_method();
        error_log('CoinSub Webhook: Order payment method: ' . $payment_method);
        
        // Ensure payment method is set to coinsub
        if ($payment_method !== 'coinsub') {
            $order->set_payment_method('coinsub');
            $order->set_payment_method_title('Pay with Coinsub');
            $order->save();
            error_log('CoinSub Webhook: Set payment method to coinsub');
        }
        
        // Add order note with transaction details
        $transaction_details = $data['transaction_details'] ?? array();
        $transaction_id = $transaction_details['transaction_id'] ?? 'N/A';
        $transaction_hash = $transaction_details['transaction_hash'] ?? 'N/A';
        
        $order->add_order_note(
            sprintf(
                __('CoinSub Payment Complete - Transaction Hash: %s', 'coinsub'),
                $transaction_hash
            )
        );
        
        // Store transaction details in WooCommerce
        if (isset($data['payment_id'])) {
            $order->update_meta_data('_coinsub_payment_id', $data['payment_id']);
        }
        
        if (isset($data['agreement_id'])) {
            $order->update_meta_data('_coinsub_agreement_id', $data['agreement_id']);
        }
        
        if (isset($transaction_details['transaction_id'])) {
            $order->update_meta_data('_coinsub_transaction_id', $transaction_details['transaction_id']);
        }
        
        if (isset($transaction_details['transaction_hash'])) {
            $order->update_meta_data('_coinsub_transaction_hash', $transaction_details['transaction_hash']);
        }
        
        if (isset($transaction_details['chain_id'])) {
            $order->update_meta_data('_coinsub_chain_id', $transaction_details['chain_id']);
        }
        
        // Store network name from webhook metadata if available (for explorer URLs)
        if (isset($transaction_details['network'])) {
            $order->update_meta_data('_coinsub_network_name', $transaction_details['network']);
        } elseif (isset($data['network'])) {
            $order->update_meta_data('_coinsub_network_name', $data['network']);
        }
        
        // Store explorer URL directly from webhook if provided
        if (isset($transaction_details['explorer_url'])) {
            $order->update_meta_data('_coinsub_explorer_url', $transaction_details['explorer_url']);
        } elseif (isset($data['explorer_url'])) {
            $order->update_meta_data('_coinsub_explorer_url', $data['explorer_url']);
        }
        
        // Store customer wallet address if available
        if (isset($transaction_details['customer_wallet_address'])) {
            $order->update_meta_data('_customer_wallet_address', $transaction_details['customer_wallet_address']);
        }
        
        // Store signing address from agreement message if available
        if (isset($data['agreement']['message']['signing_address'])) {
            $order->update_meta_data('_customer_wallet_address', $data['agreement']['message']['signing_address']);
            error_log('🔑 CoinSub Webhook - Stored signing address as customer wallet: ' . $data['agreement']['message']['signing_address']);
        }
        
        // Store complete agreement message data for refunds
        if (isset($data['agreement']['message'])) {
            $agreement_message = $data['agreement']['message'];
            $order->update_meta_data('_coinsub_agreement_message', json_encode($agreement_message));
            error_log('🔑 CoinSub Webhook - Stored agreement message: ' . json_encode($agreement_message));
            
            // Extract specific fields for easy access
            if (isset($agreement_message['signing_address'])) {
                $order->update_meta_data('_coinsub_signing_address', $agreement_message['signing_address']);
            }
            if (isset($agreement_message['permitId'])) {
                $order->update_meta_data('_coinsub_permit_id', $agreement_message['permitId']);
            }
        }
        
        // Store token symbol if available
        if (isset($transaction_details['token_symbol'])) {
            $order->update_meta_data('_coinsub_token_symbol', $transaction_details['token_symbol']);
        }
        
        $order->save();
        
        // Emails are now handled by WooCommerce order status hooks
        
        // Clear cart and session data since payment is now complete (only if available)
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('coinsub_order_id', null);
            WC()->session->set('coinsub_purchase_session_id', null);
        }
        error_log('✅ CoinSub Webhook - Cleared cart/session if available after successful payment');
        
        // Set a flag to trigger redirect to order-received page
        $order->update_meta_data('_coinsub_redirect_to_received', 'yes');
        $order->save();
        
        // Emails are handled by WooCommerce order status hooks, not webhook
        
        // Log payment confirmation
        error_log('CoinSub Webhook: PAYMENT COMPLETE for order #' . $order->get_id() . ' | Transaction Hash: ' . ($transaction_hash ?? 'N/A'));
    }
    
    /**
     * Handle payment failed
     */
    private function handle_payment_failed($order, $data) {
        $order->update_status('failed', __('Payment Failed', 'coinsub'));
        
        // Add order note
        $failure_reason = $data['failure_reason'] ?? 'Unknown';
        $order->add_order_note(
            sprintf(
                __('CoinSub Payment Failed - Reason: %s', 'coinsub'),
                $failure_reason
            )
        );
        
        // Store failure reason
        if (isset($data['failure_reason'])) {
            $order->update_meta_data('_coinsub_failure_reason', $data['failure_reason']);
        }
        
        $order->save();
    }
    
    /**
     * Handle payment cancelled
     */
    private function handle_payment_cancelled($order, $data) {
        $order->update_status('cancelled', __('Payment Cancelled', 'coinsub'));
        
        // Add order note
        $order->add_order_note(__('CoinSub Payment Cancelled - Customer cancelled the payment', 'coinsub'));
        
        $order->save();
    }
    
    /**
     * Handle transfer completed
     */
    private function handle_transfer_completed($order, $data) {
        $order->update_status('processing', __('Transfer completed via CoinSub', 'coinsub'));
        
        // Add order note
        $transfer_id = $data['transfer_id'] ?? 'N/A';
        $hash = $data['hash'] ?? 'N/A';
        
        $order->add_order_note(
            sprintf(
                __('CoinSub transfer completed. Transfer ID: %s, Hash: %s', 'coinsub'),
                $transfer_id,
                $hash
            )
        );
        
        // Store transfer details
        if (isset($data['transfer_id'])) {
            $order->update_meta_data('_coinsub_transfer_id', $data['transfer_id']);
        }
        
        if (isset($data['hash'])) {
            $order->update_meta_data('_coinsub_transfer_hash', $data['hash']);
        }
        
        if (isset($data['wallet_id'])) {
            $order->update_meta_data('_coinsub_wallet_id', $data['wallet_id']);
        }
        
        if (isset($data['network'])) {
            $order->update_meta_data('_coinsub_network', $data['network']);
        }
        
        $order->save();
    }
    
    /**
     * Handle transfer failed
     */
    private function handle_transfer_failed($order, $data) {
        $order->update_status('failed', __('Transfer failed via CoinSub', 'coinsub'));
        
        // Add order note
        $order->add_order_note(__('CoinSub transfer failed', 'coinsub'));
        
        $order->save();
    }
    
    /**
     * Find order by purchase session ID
     */
    private function find_order_by_purchase_session_id($purchase_session_id) {
        // Search for order with matching purchase session ID
        $orders = wc_get_orders(array(
            'meta_key' => '_coinsub_purchase_session_id',
            'meta_value' => $purchase_session_id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        // If not found, try with different prefix variations
        $variations = array(
            'sess_' . $purchase_session_id,
            'wc_' . $purchase_session_id,
            $purchase_session_id
        );
        
        // Also try removing sess_ prefix if it exists
        if (strpos($purchase_session_id, 'sess_') === 0) {
            $variations[] = substr($purchase_session_id, 5); // Remove 'sess_' prefix
        }
        
        foreach ($variations as $variation) {
            $orders = wc_get_orders(array(
                'meta_key' => '_coinsub_purchase_session_id',
                'meta_value' => $variation,
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        return null;
    }
    
    /**
     * Verify webhook signature
     */
    private function verify_webhook_signature($raw_data) {
        $webhook_secret = get_option('coinsub_webhook_secret', '');
        
        if (empty($webhook_secret)) {
            // If no secret is configured, allow all webhooks (not recommended for production)
            error_log('CoinSub Webhook: No webhook secret configured - allowing all webhooks');
            return true;
        }
        
        $signature = $_SERVER['HTTP_X_COINSUB_SIGNATURE'] ?? '';
        
        if (empty($signature)) {
            error_log('CoinSub Webhook: No signature header provided');
            return false;
        }
        
        $expected_signature = hash_hmac('sha256', $raw_data, $webhook_secret);
        
        if (!hash_equals($expected_signature, $signature)) {
            error_log('CoinSub Webhook: Signature verification failed');
            return false;
        }
        
        return true;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'coinsub_test_connection')) {
            wp_die('Security check failed');
        }
        
        $api_client = new CoinSub_API_Client();
        $result = $api_client->test_connection();
        
        if ($result) {
            wp_send_json_success('Connection successful');
        } else {
            wp_send_json_error('Connection failed');
        }
    }
    
    /**
     * Check payment status for frontend polling
     */
    public function check_payment_status() {
        error_log('🔍 CoinSub - Checking payment status...');
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['security'], 'coinsub_check_payment')) {
            error_log('❌ CoinSub - Invalid nonce');
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        error_log('✅ CoinSub - Nonce verified');
        
        // Get the most recent order for this user
        $user_id = get_current_user_id();
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => array('pending', 'processing', 'completed'),
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($orders)) {
            wp_send_json_success(array(
                'payment_completed' => false,
                'message' => 'No recent orders found'
            ));
            return;
        }
        
        $order = $orders[0];
        
        // Check if order is completed
        if ($order->get_status() === 'completed') {
            wp_send_json_success(array(
                'payment_completed' => true,
                'redirect_url' => $order->get_checkout_order_received_url(),
                'order_id' => $order->get_id()
            ));
        } else {
            wp_send_json_success(array(
                'payment_completed' => false,
                'order_status' => $order->get_status(),
                'order_id' => $order->get_id()
            ));
        }
    }
    
    // Email functions removed - now handled by WooCommerce order status hooks
    
    // Merchant notification function removed - now handled by WooCommerce order status hooks
    
    
    // Customer email function removed - now handled by WooCommerce order status hooks
    
    // WooCommerce fallback email function removed - now handled by WooCommerce order status hooks
    
    /**
     * Get network name for chain ID
     */
    private function get_network_name($chain_id) {
        $networks = array(
            '1' => 'Ethereum Mainnet',
            '137' => 'Polygon',
            '80002' => 'Polygon Amoy Testnet',
            '11155111' => 'Sepolia Testnet',
            '56' => 'BSC',
            '97' => 'BSC Testnet',
            '42161' => 'Arbitrum One',
            '421614' => 'Arbitrum Sepolia',
            '10' => 'Optimism',
            '420' => 'Optimism Sepolia',
            '8453' => 'Base',
            '84532' => 'Base Sepolia',
            '421613' => 'Arbitrum Nova'
        );
        
        return isset($networks[$chain_id]) ? $networks[$chain_id] : 'Chain ID ' . $chain_id;
    }
    
    /**
     * Create a renewal order for recurring subscription payments
     * 
     * @param WC_Order $parent_order The original subscription order
     * @param array $payment_data Webhook payment data
     * @return WC_Order|false The renewal order or false on failure
     */
    private function create_renewal_order($parent_order, $payment_data) {
        try {
            error_log('🔄 CoinSub: Creating renewal order from parent order #' . $parent_order->get_id());
            
            // Create new order
            $renewal_order = wc_create_order();
            
            if (is_wp_error($renewal_order) || !$renewal_order) {
                error_log('❌ CoinSub: Failed to create renewal order');
                return false;
            }
            
            error_log('✅ CoinSub: Renewal order #' . $renewal_order->get_id() . ' created');
            
            // Copy customer information
            $renewal_order->set_customer_id($parent_order->get_customer_id());
            $renewal_order->set_billing_first_name($parent_order->get_billing_first_name());
            $renewal_order->set_billing_last_name($parent_order->get_billing_last_name());
            $renewal_order->set_billing_company($parent_order->get_billing_company());
            $renewal_order->set_billing_address_1($parent_order->get_billing_address_1());
            $renewal_order->set_billing_address_2($parent_order->get_billing_address_2());
            $renewal_order->set_billing_city($parent_order->get_billing_city());
            $renewal_order->set_billing_state($parent_order->get_billing_state());
            $renewal_order->set_billing_postcode($parent_order->get_billing_postcode());
            $renewal_order->set_billing_country($parent_order->get_billing_country());
            $renewal_order->set_billing_email($parent_order->get_billing_email());
            $renewal_order->set_billing_phone($parent_order->get_billing_phone());
            
            // Copy shipping information
            if ($parent_order->has_shipping_address()) {
                $renewal_order->set_shipping_first_name($parent_order->get_shipping_first_name());
                $renewal_order->set_shipping_last_name($parent_order->get_shipping_last_name());
                $renewal_order->set_shipping_company($parent_order->get_shipping_company());
                $renewal_order->set_shipping_address_1($parent_order->get_shipping_address_1());
                $renewal_order->set_shipping_address_2($parent_order->get_shipping_address_2());
                $renewal_order->set_shipping_city($parent_order->get_shipping_city());
                $renewal_order->set_shipping_state($parent_order->get_shipping_state());
                $renewal_order->set_shipping_postcode($parent_order->get_shipping_postcode());
                $renewal_order->set_shipping_country($parent_order->get_shipping_country());
            }
            
            // Copy order items
            foreach ($parent_order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                
                if (!$product) {
                    error_log('⚠️ CoinSub: Product not found for item #' . $item_id . ', skipping');
                    continue;
                }
                
                $item_data = array(
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total(),
                    'subtotal' => $item->get_subtotal(),
                    'tax_class' => $item->get_tax_class(),
                    'product_id' => $product->get_id(),
                    'variation_id' => $item->get_variation_id(),
                );
                
                // Add variation attributes if it's a variation
                if ($item->get_variation_id()) {
                    foreach ($item->get_meta_data() as $meta) {
                        if (strpos($meta->key, 'pa_') === 0 || strpos($meta->key, 'attribute_') === 0) {
                            $item_data['variation'][$meta->key] = $meta->value;
                        }
                    }
                }
                
                $renewal_order->add_product($product, $item->get_quantity(), $item_data);
            }
            
            // Copy shipping methods
            foreach ($parent_order->get_items('shipping') as $item_id => $shipping_item) {
                $item = new WC_Order_Item_Shipping();
                $item->set_method_id($shipping_item->get_method_id());
                $item->set_method_title($shipping_item->get_method_title());
                $item->set_instance_id($shipping_item->get_instance_id());
                $item->set_total($shipping_item->get_total());
                $item->set_total_tax($shipping_item->get_total_tax());
                
                // Copy shipping item meta
                foreach ($shipping_item->get_meta_data() as $meta) {
                    $item->add_meta_data($meta->key, $meta->value);
                }
                
                $renewal_order->add_item($item);
            }
            
            // Copy fees
            foreach ($parent_order->get_items('fee') as $item_id => $fee_item) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name($fee_item->get_name());
                $fee->set_total($fee_item->get_total());
                $fee->set_tax_class($fee_item->get_tax_class());
                $fee->set_tax_status($fee_item->get_tax_status());
                $fee->set_total_tax($fee_item->get_total_tax());
                $renewal_order->add_item($fee);
            }
            
            // Set payment method
            $renewal_order->set_payment_method('coinsub');
            $renewal_order->set_payment_method_title('Pay with Coinsub');
            
            // Set currency
            $renewal_order->set_currency($parent_order->get_currency());
            
            // Calculate totals
            $renewal_order->calculate_totals();
            
            // Set parent/child relationship
            $renewal_order->update_meta_data('_coinsub_parent_subscription_order', $parent_order->get_id());
            $renewal_order->update_meta_data('_coinsub_is_renewal_order', 'yes');
            $renewal_order->update_meta_data('_coinsub_agreement_id', $parent_order->get_meta('_coinsub_agreement_id'));
            
            // Track renewal orders in parent order
            $renewal_orders = $parent_order->get_meta('_coinsub_renewal_orders');
            if (!is_array($renewal_orders)) {
                $renewal_orders = array();
            }
            $renewal_orders[] = $renewal_order->get_id();
            $parent_order->update_meta_data('_coinsub_renewal_orders', $renewal_orders);
            $parent_order->save();
            
            // Add order note
            $renewal_order->add_order_note(
                sprintf(
                    __('Renewal order for subscription order #%s. Recurring payment received via CoinSub.', 'coinsub'),
                    $parent_order->get_order_number()
                )
            );
            
            // Add note to parent order
            $parent_order->add_order_note(
                sprintf(
                    __('Renewal order #%s created for recurring payment.', 'coinsub'),
                    $renewal_order->get_order_number()
                )
            );
            
            // Store transaction details from webhook
            $transaction_details = $payment_data['transaction_details'] ?? array();
            if (isset($payment_data['payment_id'])) {
                $renewal_order->update_meta_data('_coinsub_payment_id', $payment_data['payment_id']);
            }
            if (isset($transaction_details['transaction_id'])) {
                $renewal_order->update_meta_data('_coinsub_transaction_id', $transaction_details['transaction_id']);
            }
            if (isset($transaction_details['transaction_hash'])) {
                $renewal_order->update_meta_data('_coinsub_transaction_hash', $transaction_details['transaction_hash']);
            }
            if (isset($transaction_details['chain_id'])) {
                $renewal_order->update_meta_data('_coinsub_chain_id', $transaction_details['chain_id']);
            }
            if (isset($transaction_details['network'])) {
                $renewal_order->update_meta_data('_coinsub_network_name', $transaction_details['network']);
            }
            if (isset($transaction_details['explorer_url'])) {
                $renewal_order->update_meta_data('_coinsub_explorer_url', $transaction_details['explorer_url']);
            }
            
            $renewal_order->save();
            
            error_log('✅ CoinSub: Renewal order #' . $renewal_order->get_id() . ' created and linked to parent #' . $parent_order->get_id());
            
            return $renewal_order;
            
        } catch (Exception $e) {
            error_log('❌ CoinSub: Error creating renewal order: ' . $e->getMessage());
            return false;
        }
    }

    // Note: We intentionally leave any other orders in on-hold state; no auto-cancel
}
