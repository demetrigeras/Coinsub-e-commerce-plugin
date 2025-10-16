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
        error_log('🔔 CoinSub Webhook - Received webhook request');
        error_log('🔔 CoinSub Webhook - Request headers: ' . json_encode($request->get_headers()));
        error_log('🔔 CoinSub Webhook - Raw body: ' . $request->get_body());
        
        // Get the request body
        $data = $request->get_json_params();
        
        if (!$data) {
            error_log('❌ CoinSub Webhook - Invalid JSON data');
            return new WP_REST_Response(array('error' => 'Invalid JSON data'), 400);
        }
        
        error_log('🔔 CoinSub Webhook - Data: ' . json_encode($data));
        
        // Verify webhook signature if configured
        $raw_data = $request->get_body();
        if (!$this->verify_webhook_signature($raw_data)) {
            error_log('❌ CoinSub Webhook - Invalid signature');
            return new WP_REST_Response(array('error' => 'Invalid signature'), 401);
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
        $order = $this->find_order_by_origin_id($origin_id);
        
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
            return;
        }
        
        error_log('✅ CoinSub Webhook: Found order ID: ' . $order->get_id() . ' for origin ID: ' . $origin_id);
        error_log('CoinSub Webhook: Order status before update: ' . $order->get_status());
        
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
        
        // Update WooCommerce order status - payment is complete!
        $order->update_status('processing', __('Payment Complete', 'coinsub'));
        error_log('CoinSub Webhook: Updated order status to processing');
        
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
        
        $order->save();
        
        // ✅ UPDATE COINSUB COMMERCE ORDER STATUS TO "PAID"
        $coinsub_order_id = $order->get_meta('_coinsub_order_id');
        if (!empty($coinsub_order_id)) {
            error_log('🔄 Updating CoinSub commerce order status to PAID for order: ' . $coinsub_order_id);
            
            // Get API client
            if (!class_exists('CoinSub_API_Client')) {
                require_once plugin_dir_path(__FILE__) . 'class-coinsub-api-client.php';
            }
            $api_client = new CoinSub_API_Client();
            
            // Update ONLY status - nothing else!
            $update_data = array(
                'status' => 'paid'
            );
            
            $result = $api_client->update_commerce_order_from_webhook($coinsub_order_id, $update_data);
            
            if (is_wp_error($result)) {
                error_log('⚠️ Failed to update CoinSub commerce order: ' . $result->get_error_message());
            } else {
                error_log('✅ CoinSub commerce order status updated to PAID');
            }
        }
        
        // Clear cart and session data since payment is now complete
        WC()->cart->empty_cart();
        WC()->session->set('coinsub_order_id', null);
        WC()->session->set('coinsub_purchase_session_id', null);
        error_log('✅ CoinSub Webhook - Cart and session cleared after successful payment');
        
        // Set a flag to trigger redirect to order-received page
        $order->update_meta_data('_coinsub_redirect_to_received', 'yes');
        $order->save();
        
        // Send order completion emails
        WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
        
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
     * Find order by origin ID (purchase session ID)
     */
    private function find_order_by_origin_id($origin_id) {
        // First try with the exact origin_id
        $orders = wc_get_orders(array(
            'meta_key' => '_coinsub_purchase_session_id',
            'meta_value' => $origin_id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        // If not found, try with sess_ prefix removed
        if (strpos($origin_id, 'sess_') === 0) {
            $uuid_part = substr($origin_id, 5); // Remove 'sess_' prefix
            $orders = wc_get_orders(array(
                'meta_key' => '_coinsub_purchase_session_id',
                'meta_value' => $uuid_part,
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        // If still not found, try with sess_ prefix added
        if (strpos($origin_id, 'sess_') !== 0) {
            $sess_id = 'sess_' . $origin_id;
            $orders = wc_get_orders(array(
                'meta_key' => '_coinsub_purchase_session_id',
                'meta_value' => $sess_id,
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
}
