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
        add_action('init', array($this, 'add_webhook_endpoint'));
        add_action('template_redirect', array($this, 'handle_webhook'));
        add_action('wp_ajax_coinsub_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_nopriv_coinsub_test_connection', array($this, 'test_connection'));
    }
    
    /**
     * Add webhook endpoint
     */
    public function add_webhook_endpoint() {
        add_rewrite_rule(
            '^wp-json/coinsub/v1/webhook/?$',
            'index.php?coinsub_webhook=1',
            'top'
        );
        
        add_rewrite_tag('%coinsub_webhook%', '([^&]+)');
    }
    
    /**
     * Handle webhook requests
     */
    public function handle_webhook() {
        if (!get_query_var('coinsub_webhook')) {
            return;
        }
        
        // Set content type
        header('Content-Type: application/json');
        
        // Get the raw POST data
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid JSON data'));
            exit;
        }
        
        // Verify webhook signature if configured
        if (!$this->verify_webhook_signature($raw_data)) {
            http_response_code(401);
            echo json_encode(array('error' => 'Invalid signature'));
            exit;
        }
        
        // Process the webhook
        $this->process_webhook($data);
        
        // Return success response
        http_response_code(200);
        echo json_encode(array('status' => 'success'));
        exit;
    }
    
    /**
     * Process webhook data
     */
    private function process_webhook($data) {
        $event_type = $data['type'] ?? 'unknown';
        $origin_id = $data['origin_id'] ?? null;
        $merchant_id = $data['merchant_id'] ?? null;
        
        if (!$origin_id) {
            error_log('CoinSub Webhook: No origin ID provided');
            return;
        }
        
        // Find the order by origin ID (purchase session ID)
        $order = $this->find_order_by_origin_id($origin_id);
        
        if (!$order) {
            error_log('CoinSub Webhook: Order not found for origin ID: ' . $origin_id);
            error_log('CoinSub Webhook: Event type: ' . $event_type);
            error_log('CoinSub Webhook: Merchant ID: ' . $merchant_id);
            return;
        }
        
        error_log('CoinSub Webhook: Found order ID: ' . $order->get_id() . ' for origin ID: ' . $origin_id);
        
        // Verify merchant ID matches
        $order_merchant_id = $order->get_meta('_coinsub_merchant_id');
        if ($order_merchant_id && $merchant_id && $order_merchant_id !== $merchant_id) {
            error_log('CoinSub Webhook: Merchant ID mismatch for order: ' . $order->get_id());
            return;
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
        $order->update_status('processing', __('Payment completed via CoinSub', 'coinsub-commerce'));
        
        // Add order note with transaction details
        $transaction_details = $data['transaction_details'] ?? array();
        $transaction_id = $transaction_details['transaction_id'] ?? 'N/A';
        $transaction_hash = $transaction_details['transaction_hash'] ?? 'N/A';
        
        $order->add_order_note(
            sprintf(
                __('CoinSub payment completed. Transaction ID: %s, Hash: %s', 'coinsub-commerce'),
                $transaction_id,
                $transaction_hash
            )
        );
        
        // Store transaction details
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
        
        // Send order completion emails
        WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
    }
    
    /**
     * Handle payment failed
     */
    private function handle_payment_failed($order, $data) {
        $order->update_status('failed', __('Payment failed via CoinSub', 'coinsub-commerce'));
        
        // Add order note
        $failure_reason = $data['failure_reason'] ?? 'Unknown';
        $order->add_order_note(
            sprintf(
                __('CoinSub payment failed. Reason: %s', 'coinsub-commerce'),
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
        $order->update_status('cancelled', __('Payment cancelled via CoinSub', 'coinsub-commerce'));
        
        // Add order note
        $order->add_order_note(__('CoinSub payment was cancelled by customer', 'coinsub-commerce'));
        
        $order->save();
    }
    
    /**
     * Handle transfer completed
     */
    private function handle_transfer_completed($order, $data) {
        $order->update_status('processing', __('Transfer completed via CoinSub', 'coinsub-commerce'));
        
        // Add order note
        $transfer_id = $data['transfer_id'] ?? 'N/A';
        $hash = $data['hash'] ?? 'N/A';
        
        $order->add_order_note(
            sprintf(
                __('CoinSub transfer completed. Transfer ID: %s, Hash: %s', 'coinsub-commerce'),
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
        $order->update_status('failed', __('Transfer failed via CoinSub', 'coinsub-commerce'));
        
        // Add order note
        $order->add_order_note(__('CoinSub transfer failed', 'coinsub-commerce'));
        
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
}
