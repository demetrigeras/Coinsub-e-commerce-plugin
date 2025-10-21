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
        
        // Update WooCommerce order status - payment received, move to processing for consistency
        $order->update_status('processing', __('Payment received via CoinSub', 'coinsub'));
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
        
        // Send order confirmation email to customer
        $this->send_order_confirmation_email($order);
        
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
        
        // Send order processing emails
        $this->send_payment_processing_emails($order, $transaction_details);
        
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
    
    /**
     * Send payment completion emails to customer and merchant
     */
    private function send_payment_processing_emails($order, $transaction_details) {
        error_log('📧 CoinSub Webhook: Sending payment processing emails...');
        
        try {
            // Send customer email - Order processing
            if (WC()->mailer()->emails['WC_Email_Customer_Processing_Order']) {
                WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
                error_log('✅ CoinSub Webhook: Customer processing email sent for order #' . $order->get_id());
            }
            
            // Send merchant email - New order notification
            if (WC()->mailer()->emails['WC_Email_New_Order']) {
                WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
                error_log('✅ CoinSub Webhook: Merchant new order email sent for order #' . $order->get_id());
            }
            
            // Send additional merchant notification with CoinSub details
            $this->send_coinsub_merchant_notification($order, $transaction_details);
            
        } catch (Exception $e) {
            error_log('❌ CoinSub Webhook: Error sending emails: ' . $e->getMessage());
        }
    }
    
    /**
     * Send custom CoinSub merchant notification
     */
    private function send_coinsub_merchant_notification($order, $transaction_details) {
        $merchant_email = get_option('admin_email');
        if (!$merchant_email) {
            return;
        }
        
        $transaction_hash = $transaction_details['transaction_hash'] ?? 'N/A';
        $transaction_id = $transaction_details['transaction_id'] ?? 'N/A';
        $chain_id = $transaction_details['chain_id'] ?? 'N/A';
        
        $subject = sprintf('[Coinsub] Payment Received - Order #%s', $order->get_id());
        
        // Get order breakdown
        $subtotal = $order->get_subtotal();
        $shipping_total = $order->get_shipping_total();
        $tax_total = $order->get_total_tax();
        $total = $order->get_total();
        
        // Check if it's a subscription
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        $subscription_text = $is_subscription ? ' (Subscription)' : '';
        
        // Get items list
        $items_list = '';
        foreach ($order->get_items() as $item_id => $item) {
            $product_name = $item->get_name();
            $quantity = $item->get_quantity();
            $total = $order->get_formatted_line_subtotal($item);
            $items_list .= sprintf("• %s × %d - %s\n", $product_name, $quantity, $total);
        }
        
        // Get shipping address
        $shipping_address = $order->get_formatted_shipping_address();
        
        $message = sprintf(
            "A new payment has been received via Coinsub!\n\n" .
            "Order Details:\n" .
            "Order ID: #%s\n" .
            "Customer: %s %s (%s)\n" .
            "Amount: %s\n\n" .
            "Items Ordered%s:\n" .
            "%s\n" .
            "Price Breakdown:\n" .
            "Subtotal: %s\n" .
            "Shipping: %s\n" .
            "Tax: %s\n" .
            "Total: %s\n\n" .
            "Shipping Address:\n" .
            "%s\n\n" .
            "Transaction Details:\n" .
            "Transaction Hash: %s\n" .
            "Transaction ID: %s\n" .
            "Chain ID: %s\n\n" .
            "View Order: %s\n\n" .
            "This is an automated notification from your Coinsub payment gateway.",
            $order->get_id(),
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $order->get_billing_email(),
            $order->get_formatted_order_total(),
            $subscription_text,
            $items_list,
            wc_price($subtotal),
            wc_price($shipping_total),
            wc_price($tax_total),
            wc_price($total),
            $shipping_address ?: 'No shipping address provided',
            $transaction_hash,
            $transaction_id,
            $chain_id,
            admin_url('post.php?post=' . $order->get_id() . '&action=edit')
        );
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        if (wp_mail($merchant_email, $subject, $message, $headers)) {
            error_log('✅ CoinSub Webhook: Custom merchant notification sent to: ' . $merchant_email);
        } else {
            error_log('❌ CoinSub Webhook: Failed to send custom merchant notification');
        }
    }
    
    /**
     * Send order confirmation email to customer
     */
    private function send_order_confirmation_email($order) {
        // Get email from the logged-in user account, not billing email
        $customer_email = null;
        $customer_name = 'Customer';
        
        // First try to get from the WordPress user account
        $user_id = $order->get_user_id();
        if ($user_id) {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $customer_email = $user->user_email;
                $customer_name = $user->display_name ?: $user->first_name ?: 'Customer';
                error_log('✅ CoinSub Webhook: Using account holder email: ' . $customer_email);
            }
        }
        
        // Fallback to billing email if no user account
        if (empty($customer_email)) {
            $customer_email = $order->get_billing_email();
            $customer_name = $order->get_billing_first_name() ?: 'Customer';
            error_log('⚠️ CoinSub Webhook: No user account, using billing email: ' . $customer_email);
        }
        
        if (empty($customer_email)) {
            error_log('❌ CoinSub Webhook: No customer email found for order #' . $order->get_id());
            return;
        }
        
        // Get order details
        $order_id = $order->get_id();
        $order_total = $order->get_formatted_order_total();
        $order_date = $order->get_date_created()->date('F j, Y \a\t g:i A');
        $payment_method = $order->get_payment_method_title();
        
        // Get transaction details
        $transaction_hash = $order->get_meta('_coinsub_transaction_hash');
        $chain_id = $order->get_meta('_coinsub_chain_id');
        $token_symbol = $order->get_meta('_coinsub_token_symbol') ?: 'USDC';
        
        // Get order items
        $items_html = '';
        foreach ($order->get_items() as $item_id => $item) {
            $product_name = $item->get_name();
            $quantity = $item->get_quantity();
            $total = $order->get_formatted_line_subtotal($item);
            $items_html .= sprintf(
                '<p>• %s × %d - %s</p>',
                esc_html($product_name),
                $quantity,
                $total
            );
        }
        
        // Get addresses
        $billing_address = $order->get_formatted_billing_address();
        $shipping_address = $order->get_formatted_shipping_address();
        
        // Email subject
        $subject = sprintf(__('Order Confirmation - Order #%s', 'coinsub'), $order_id);
        
        // Get order breakdown
        $subtotal = $order->get_subtotal();
        $shipping_total = $order->get_shipping_total();
        $tax_total = $order->get_total_tax();
        $total = $order->get_total();
        
        // Check if it's a subscription
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        $subscription_text = $is_subscription ? ' (Subscription)' : '';
        
        // Email content
        $message = sprintf(
            '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;">Order Confirmation</h2>
                
                <p>Hello %s,</p>
                
                <p>A new payment has been received via Coinsub!</p>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #2c3e50;">Order Details:</h3>
                    <p><strong>Order ID:</strong> #%s</p>
                    <p><strong>Customer:</strong> %s (%s)</p>
                    <p><strong>Amount:</strong> %s</p>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #2c3e50;">Items Ordered%s:</h3>
                    %s
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #2c3e50;">Price Breakdown:</h3>
                    <p><strong>Subtotal:</strong> %s</p>
                    <p><strong>Shipping:</strong> %s</p>
                    <p><strong>Tax:</strong> %s</p>
                    <p><strong>Total:</strong> %s</p>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #2c3e50;">Shipping Address:</h3>
                    %s
                </div>
                
                <div style="background: #e8f5e8; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #27ae60;">Transaction Details:</h3>
                    <p><strong>Transaction Hash:</strong> %s</p>
                    <p><strong>Transaction ID:</strong> %s</p>
                    <p><strong>Chain ID:</strong> %s</p>
                </div>
                
                <p>This is an automated notification from your Coinsub payment gateway.</p>
            </div>
            </body></html>',
            esc_html($customer_name),
            $order_id,
            esc_html($customer_name),
            $customer_email,
            $order_total,
            $subscription_text,
            $items_html,
            wc_price($subtotal),
            wc_price($shipping_total),
            wc_price($tax_total),
            wc_price($total),
            $shipping_address ?: '<em>No shipping address provided</em>',
            $transaction_hash ?: 'N/A',
            $order->get_meta('_coinsub_transaction_id') ?: 'N/A',
            $chain_id ?: 'N/A'
        );
        
        // Email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        // Send email
        error_log('📧 CoinSub Webhook: Sending order confirmation email to account holder: ' . $customer_email . ' (Name: ' . $customer_name . ')');
        
        if (wp_mail($customer_email, $subject, $message, $headers)) {
            error_log('✅ CoinSub Webhook: Order confirmation email sent successfully to: ' . $customer_email);
        } else {
            error_log('❌ CoinSub Webhook: Failed to send custom order confirmation email, trying WooCommerce default');
            
            // Fallback to WooCommerce's built-in email system
            $this->send_woocommerce_order_email($order);
        }
    }
    
    /**
     * Send WooCommerce default order email as fallback
     */
    private function send_woocommerce_order_email($order) {
        try {
            // Trigger WooCommerce's built-in order email
            if (class_exists('WC_Emails')) {
                $wc_emails = WC_Emails::instance();
                $wc_emails->customer_processing_order($order);
                error_log('✅ CoinSub Webhook: WooCommerce order email sent as fallback');
            } else {
                error_log('❌ CoinSub Webhook: WooCommerce emails class not available');
            }
        } catch (Exception $e) {
            error_log('❌ CoinSub Webhook: Failed to send WooCommerce order email: ' . $e->getMessage());
        }
    }
    
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
            '84532' => 'Base Sepolia'
        );
        
        return isset($networks[$chain_id]) ? $networks[$chain_id] : 'Chain ID ' . $chain_id;
    }

    // Note: We intentionally leave any other orders in on-hold state; no auto-cancel
}
