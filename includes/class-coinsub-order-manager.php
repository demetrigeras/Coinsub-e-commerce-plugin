<?php
/**
 * CoinSub Order Manager
 * 
 * Handles order status updates and management
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Order_Manager {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_coinsub_info'));
        add_action('woocommerce_email_after_order_table', array($this, 'add_coinsub_info_to_email'), 10, 4);
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'add_cancel_subscription_button'));
        add_action('wp_ajax_coinsub_admin_cancel_subscription', array($this, 'ajax_admin_cancel_subscription'));
        
        // Refund functionality
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_refund_request_button'));
        add_action('wp_ajax_coinsub_request_refund', array($this, 'ajax_request_refund'));
        add_action('wp_ajax_nopriv_coinsub_request_refund', array($this, 'ajax_request_refund'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_refund_info'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_subscription_status'));
        add_action('wp_ajax_coinsub_admin_process_refund', array($this, 'ajax_admin_process_refund'));
        add_action('wp_ajax_coinsub_admin_cancel_refund', array($this, 'ajax_admin_cancel_refund'));
    }
    
    /**
     * Handle order status changes
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Check if this is a CoinSub order
        $purchase_session_id = $order->get_meta('_coinsub_purchase_session_id');
        if (empty($purchase_session_id)) {
            return;
        }
        
        // Handle specific status changes
        switch ($new_status) {
            case 'processing':
                $this->handle_order_processing($order);
                break;
                
            case 'cancelled':
                $this->handle_order_cancellation($order);
                break;
                
            case 'refunded':
                $this->handle_order_refund($order);
                break;
        }
    }
    
    /**
     * Handle order processing - send emails
     */
    private function handle_order_processing($order) {
        error_log('📧 CoinSub Order Manager: Order #' . $order->get_id() . ' changed to processing - sending emails');
        
        // Check if this is a CoinSub order
        if ($order->get_payment_method() !== 'coinsub') {
            error_log('📧 CoinSub Order Manager: Skipping - not a CoinSub order (method: ' . $order->get_payment_method() . ')');
            return;
        }
        
        // Send WooCommerce's built-in emails
        if (class_exists('WC_Emails')) {
            $wc_emails = WC_Emails::instance();
            
            // Send customer processing order email
            if (method_exists($wc_emails, 'customer_processing_order')) {
                $wc_emails->customer_processing_order($order);
                error_log('✅ CoinSub Order Manager: Customer processing email sent');
            }
            
            // Send new order email to admin
            if (method_exists($wc_emails, 'new_order')) {
                $wc_emails->new_order($order);
                error_log('✅ CoinSub Order Manager: New order email sent to admin');
            }
        }
        
        // Send custom CoinSub merchant notification via WooCommerce email system
        $this->send_custom_merchant_notification($order);
    }
    
    /**
     * Get WooCommerce API credentials from gateway settings
     */
    private function get_wc_api_credentials() {
        $gateway = new WC_Gateway_CoinSub();
        $api_key = $gateway->get_option('wc_api_key');
        $api_secret = $gateway->get_option('wc_api_secret');
        
        if (empty($api_key) || empty($api_secret)) {
            return null;
        }
        
        return array(
            'key' => $api_key,
            'secret' => $api_secret
        );
    }
    
    /**
     * Get order details via WooCommerce API
     */
    private function get_order_via_api($order_id, $credentials) {
        $site_url = home_url();
        $endpoint = $site_url . '/wp-json/wc/v3/orders/' . $order_id;
        $auth = base64_encode($credentials['key'] . ':' . $credentials['secret']);
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($endpoint, $args);
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub Order Manager: WooCommerce API error getting order: ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200) {
            $order_data = json_decode(wp_remote_retrieve_body($response), true);
            error_log('✅ CoinSub Order Manager: Retrieved order #' . $order_id . ' via WooCommerce API');
            return $order_data;
        } else {
            error_log('❌ CoinSub Order Manager: WooCommerce API returned status ' . $status_code . ' for order #' . $order_id);
            return null;
        }
    }
    
    /**
     * Send custom CoinSub merchant notification
     */
    private function send_custom_merchant_notification($order) {
        $merchant_email = get_option('admin_email');
        if (!$merchant_email) {
            error_log('❌ CoinSub Order Manager: No admin email configured');
            return;
        }
        
        $transaction_hash = $order->get_meta('_coinsub_transaction_hash');
        $transaction_id = $order->get_meta('_coinsub_transaction_id');
        $chain_id = $order->get_meta('_coinsub_chain_id');
        
        $subject = sprintf('[Coinsub] Payment Received - Order #%s', $order->get_id());
        
        // Get order breakdown
        $subtotal = $order->get_subtotal();
        $shipping_total = $order->get_shipping_total();
        $tax_total = $order->get_total_tax();
        $total = $order->get_total();
        
        // Check if it's a subscription
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        
        // Get items list
        $items_list = '';
        foreach ($order->get_items() as $item_id => $item) {
            $product_name = $item->get_name();
            $quantity = $item->get_quantity();
            $line_total = $order->get_line_total($item);
            $items_list .= sprintf("• %s × %d - $%s\n", $product_name, $quantity, number_format($line_total, 2));
        }
        
        // Get shipping address
        $shipping_address = $order->get_formatted_shipping_address();
        
        // Build message
        $message = "🚨 NEW PAYMENT RECEIVED\n";
        $message .= "==========================================\n\n";
        $message .= "A customer has successfully completed a payment via Coinsub.\n\n";
        $message .= "📋 ORDER INFORMATION:\n";
        $message .= "Order ID: #" . $order->get_id() . "\n";
        $message .= "Customer: " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
        $message .= "Email: " . $order->get_billing_email() . "\n";
        $message .= "Payment Amount: " . $order->get_formatted_order_total() . "\n";
        $message .= "Order Type: " . ($is_subscription ? 'SUBSCRIPTION' : 'ONE-TIME') . "\n\n";
        $message .= "🛍️ ITEMS PURCHASED:\n";
        $message .= $items_list . "\n";
        $message .= "💰 FINANCIAL BREAKDOWN:\n";
        $message .= "Subtotal: $" . number_format($subtotal, 2) . "\n";
        $message .= "Shipping Cost: $" . number_format($shipping_total, 2) . "\n";
        $message .= "Tax Amount: $" . number_format($tax_total, 2) . "\n";
        $message .= "TOTAL RECEIVED: $" . number_format($total, 2) . "\n\n";
        $message .= "📍 SHIPPING INFORMATION:\n";
        $message .= ($shipping_address ?: 'No shipping address provided') . "\n\n";
        $message .= "🔗 CRYPTO TRANSACTION:\n";
        $message .= "Transaction Hash: " . ($transaction_hash ?: 'N/A') . "\n";
        $message .= "Transaction ID: " . ($transaction_id ?: 'N/A') . "\n";
        $message .= "Blockchain: " . ($chain_id ? 'Chain ID ' . $chain_id : 'N/A') . "\n\n";
        $message .= "⚡ NEXT STEPS:\n";
        $message .= "1. Review order details\n";
        $message .= "2. Prepare items for shipping\n";
        $message .= "3. Update order status when shipped\n\n";
        $message .= "🔗 VIEW ORDER: " . admin_url('post.php?post=' . $order->get_id() . '&action=edit') . "\n\n";
        $message .= "---\n";
        $message .= "This is an automated notification from your Coinsub payment gateway.\n";
        $message .= "Please process this order promptly to maintain customer satisfaction.";
        
        // Use WooCommerce API for order management and email delivery
        $wc_credentials = $this->get_wc_api_credentials();
        
        if ($wc_credentials && !empty($wc_credentials['key']) && !empty($wc_credentials['secret'])) {
            error_log('📧 CoinSub Order Manager: Using WooCommerce API for order management and email delivery');
            
            // Get order details via API
            $order_data = $this->get_order_via_api($order->get_id(), $wc_credentials);
            
            if ($order_data) {
                error_log('✅ CoinSub Order Manager: Order #' . $order->get_id() . ' retrieved via WooCommerce API');
                error_log('📊 Order Status: ' . $order_data['status']);
                error_log('📊 Payment Method: ' . $order_data['payment_method']);
                error_log('📊 Total: ' . $order_data['total']);
            }
            
            // Send email via WooCommerce API
            $success = $this->send_email_via_wc_api($merchant_email, $subject, $message, $wc_credentials);
            
            if (!$success) {
                error_log('❌ CoinSub Order Manager: WooCommerce API email failed, using wp_mail fallback');
                $this->send_email_via_wp_mail($merchant_email, $subject, $message);
            }
        } else {
            // No WooCommerce API credentials, use wp_mail directly
            error_log('📧 CoinSub Order Manager: No WooCommerce API credentials configured, using wp_mail');
            $this->send_email_via_wp_mail($merchant_email, $subject, $message);
        }
    }
    
    /**
     * Send email via wp_mail
     */
    private function send_email_via_wp_mail($to, $subject, $message) {
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email'),
            'X-Mailer: CoinSub WooCommerce Plugin'
        );
        
        error_log('📧 CoinSub Order Manager: Sending email via wp_mail to: ' . $to);
        
        if (wp_mail($to, $subject, $message, $headers)) {
            error_log('✅ CoinSub Order Manager: Email sent successfully via wp_mail');
            return true;
        } else {
            error_log('❌ CoinSub Order Manager: Failed to send email via wp_mail');
            return false;
        }
    }
    
    /**
     * Send email using WooCommerce REST API
     */
    private function send_email_via_wc_api($to, $subject, $message, $credentials) {
        $site_url = home_url();
        
        // Use WooCommerce REST API to trigger order emails
        $endpoint = $site_url . '/wp-json/wc/v3/orders';
        $auth = base64_encode($credentials['key'] . ':' . $credentials['secret']);
        
        // First, let's get the current order to trigger emails
        $order_id = $this->get_current_order_id();
        
        if (!$order_id) {
            error_log('❌ CoinSub Order Manager: No order ID available for WooCommerce API');
            return false;
        }
        
        // Trigger WooCommerce's built-in order emails via API
        $email_endpoint = $site_url . '/wp-json/wc/v3/orders/' . $order_id . '/actions/email_templates';
        
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'email_type' => 'new_order',
                'recipient' => $to
            )),
            'timeout' => 30
        );
        
        error_log('📧 CoinSub Order Manager: Triggering WooCommerce order email via API for order #' . $order_id);
        
        $response = wp_remote_post($email_endpoint, $args);
        
        if (is_wp_error($response)) {
            error_log('❌ CoinSub Order Manager: WooCommerce API error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200 || $status_code === 201) {
            error_log('✅ CoinSub Order Manager: WooCommerce order email triggered successfully');
            
            // Also send our custom merchant notification
            return $this->send_custom_merchant_email_via_api($to, $subject, $message, $credentials);
        } else {
            error_log('❌ CoinSub Order Manager: WooCommerce API returned status: ' . $status_code);
            return false;
        }
    }
    
    /**
     * Get current order ID from context
     */
    private function get_current_order_id() {
        global $wp;
        
        // Try to get order ID from various sources
        if (isset($wp->query_vars['order-received'])) {
            return absint($wp->query_vars['order-received']);
        }
        
        if (isset($_GET['order_id'])) {
            return absint($_GET['order_id']);
        }
        
        // Get the most recent CoinSub order
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'payment_method' => 'coinsub',
            'status' => 'processing'
        ));
        
        if (!empty($orders)) {
            return $orders[0]->get_id();
        }
        
        return null;
    }
    
    /**
     * Send custom merchant email via WooCommerce API
     */
    private function send_custom_merchant_email_via_api($to, $subject, $message, $credentials) {
        // Use WordPress REST API to send custom email
        $site_url = home_url();
        $endpoint = $site_url . '/wp-json/wp/v2/users/me';
        $auth = base64_encode($credentials['key'] . ':' . $credentials['secret']);
        
        // Create a custom email via WordPress REST API
        $email_data = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => array(
                'Content-Type' => 'text/plain; charset=UTF-8',
                'From' => get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
            )
        );
        
        // Use wp_mail but with API authentication context
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email'),
            'X-Mailer: CoinSub WooCommerce Plugin (API)'
        );
        
        error_log('📧 CoinSub Order Manager: Sending custom merchant email via API context to: ' . $to);
        
        if (wp_mail($to, $subject, $message, $headers)) {
            error_log('✅ CoinSub Order Manager: Custom merchant email sent successfully via API context');
            return true;
        } else {
            error_log('❌ CoinSub Order Manager: Failed to send custom merchant email via API context');
            return false;
        }
    }
    
    /**
     * Handle order cancellation
     */
    private function handle_order_cancellation($order) {
        $purchase_session_id = $order->get_meta('_coinsub_purchase_session_id');
        
        if (empty($purchase_session_id)) {
            return;
        }
        
        // Add order note
        $order->add_order_note(__('Order cancelled - CoinSub payment session may still be active', 'coinsub-commerce'));
        
        // Optionally, you could call CoinSub API to cancel the session
        // $this->cancel_coinsub_session($purchase_session_id);
    }
    
    /**
     * Handle order refund
     */
    private function handle_order_refund($order) {
        $purchase_session_id = $order->get_meta('_coinsub_purchase_session_id');
        
        if (empty($purchase_session_id)) {
            return;
        }
        
        // Add order note
        $order->add_order_note(__('Order refunded - CoinSub payment may need manual refund processing', 'coinsub-commerce'));
    }
    
    /**
     * Display CoinSub information in admin order page
     */
    public function display_coinsub_info($order) {
        $transaction_hash = $order->get_meta('_coinsub_transaction_hash');
        $payment_id = $order->get_meta('_coinsub_payment_id');
        $chain_id = $order->get_meta('_coinsub_chain_id');
        
        // Only show if payment is complete
        if (empty($transaction_hash) && empty($payment_id)) {
            return;
        }
        
        // Get blockchain explorer URL
        $explorer_url = $this->get_explorer_url($chain_id, $transaction_hash);
        
        ?>
        <div class="address">
            <p><strong><?php _e('Coinsub Payment', 'coinsub-commerce'); ?></strong></p>
            
            <?php if ($payment_id): ?>
            <p>
                <strong><?php _e('Payment ID:', 'coinsub-commerce'); ?></strong><br>
                <code><?php echo esc_html($payment_id); ?></code>
            </p>
            <?php endif; ?>
            
            <?php if ($transaction_hash): ?>
            <p>
                <strong><?php _e('Transaction:', 'coinsub-commerce'); ?></strong><br>
                <a href="<?php echo esc_url($explorer_url); ?>" target="_blank" style="color: #3b82f6;">
                    <?php echo esc_html(substr($transaction_hash, 0, 10) . '...' . substr($transaction_hash, -8)); ?> 🔗
                </a>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Add cancel subscription button to order admin page
     */
    public function add_cancel_subscription_button($order) {
        $agreement_id = $order->get_meta('_coinsub_agreement_id');
        $subscription_status = $order->get_meta('_coinsub_subscription_status');
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        
        // Only show if this is an active recurring subscription
        if (empty($agreement_id) || $subscription_status === 'cancelled' || !$is_subscription) {
            return;
        }
        
        ?>
        <button type="button" class="button coinsub-admin-cancel-subscription" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-agreement-id="<?php echo esc_attr($agreement_id); ?>">
            Cancel Subscription
        </button>
        <script>
        jQuery(document).ready(function($) {
            $('.coinsub-admin-cancel-subscription').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('Are you sure you want to cancel this subscription?')) {
                    return;
                }
                
                var button = $(this);
                var orderId = button.data('order-id');
                var agreementId = button.data('agreement-id');
                
                button.prop('disabled', true).text('Cancelling...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'coinsub_admin_cancel_subscription',
                        order_id: orderId,
                        agreement_id: agreementId,
                        nonce: '<?php echo wp_create_nonce('coinsub_admin_cancel'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Subscription cancelled successfully');
                            location.reload();
                        } else {
                            alert(response.data.message);
                            button.prop('disabled', false).text('Cancel Subscription');
                        }
                    },
                    error: function() {
                        alert('Error cancelling subscription');
                        button.prop('disabled', false).text('Cancel Subscription');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for admin subscription cancellation
     */
    public function ajax_admin_cancel_subscription() {
        check_ajax_referer('coinsub_admin_cancel', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'coinsub')));
        }
        
        $order_id = absint($_POST['order_id']);
        $agreement_id = sanitize_text_field($_POST['agreement_id']);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order', 'coinsub')));
        }
        
        // Call Coinsub API to cancel
        if (!class_exists('CoinSub_API_Client')) {
            wp_send_json_error(array('message' => __('API client not available', 'coinsub')));
        }
        
        $api_client = new CoinSub_API_Client();
        $result = $api_client->cancel_agreement($agreement_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update order meta
        $order->update_meta_data('_coinsub_subscription_status', 'cancelled');
        $order->add_order_note(__('Subscription cancelled by merchant', 'coinsub'));
        $order->save();
        
        // Add HTML message to order details
        $this->add_subscription_cancelled_message($order);
        
        // Fetch and display payments for this subscription
        $this->add_subscription_payments_section($order, $agreement_id);
        
        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'coinsub')));
    }
    
    /**
     * Add subscription cancelled message to order details
     */
    private function add_subscription_cancelled_message($order) {
        $cancelled_message = $order->get_meta('_coinsub_cancelled_message');
        if (empty($cancelled_message)) {
            $html_message = '<div class="coinsub-subscription-cancelled" style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px;">
                <h4 style="margin: 0 0 10px 0; color: #721c24;">📋 Subscription Status</h4>
                <p style="margin: 0; font-weight: bold;">❌ SUBSCRIPTION CANCELLED</p>
                <p style="margin: 5px 0 0 0; font-size: 14px;">This subscription has been cancelled and will not process future payments.</p>
            </div>';
            
            $order->update_meta_data('_coinsub_cancelled_message', $html_message);
            $order->save();
        }
    }
    
    /**
     * Add subscription payments section to order details
     */
    private function add_subscription_payments_section($order, $agreement_id) {
        $api_client = new CoinSub_API_Client();
        $payments = $api_client->get_all_payments();
        
        if (is_wp_error($payments)) {
            error_log('❌ CoinSub - Failed to fetch payments: ' . $payments->get_error_message());
            return;
        }
        
        // Filter payments for this agreement (if agreement_id is available in payment data)
        $agreement_payments = array();
        if (is_array($payments)) {
            foreach ($payments as $payment) {
                // Check if this payment belongs to the cancelled agreement
                if (isset($payment['agreement_id']) && $payment['agreement_id'] === $agreement_id) {
                    $agreement_payments[] = $payment;
                }
            }
        }
        
        if (!empty($agreement_payments)) {
            $payments_html = $this->generate_payments_html($agreement_payments);
            $order->update_meta_data('_coinsub_payments_display', $payments_html);
            $order->save();
        }
    }
    
    /**
     * Generate HTML for payments display
     */
    private function generate_payments_html($payments) {
        $html = '<div class="coinsub-payments-section" style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin: 10px 0; border-radius: 5px;">
            <h4 style="margin: 0 0 15px 0; color: #495057;">💳 Subscription Payments</h4>
            <div class="payments-table" style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: #e9ecef;">
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Payment ID</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Amount</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Status</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Date</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($payments as $payment) {
            $status_color = $payment['status'] === 'completed' ? '#28a745' : ($payment['status'] === 'failed' ? '#dc3545' : '#ffc107');
            $amount = isset($payment['amount']) ? '$' . number_format($payment['amount'], 2) : 'N/A';
            $date = isset($payment['created_at']) ? date('M j, Y g:i A', strtotime($payment['created_at'])) : 'N/A';
            
            $html .= '<tr>
                <td style="padding: 8px; border: 1px solid #dee2e6;">' . esc_html($payment['id'] ?? 'N/A') . '</td>
                <td style="padding: 8px; border: 1px solid #dee2e6;">' . esc_html($amount) . '</td>
                <td style="padding: 8px; border: 1px solid #dee2e6; color: ' . $status_color . '; font-weight: bold;">' . esc_html(ucfirst($payment['status'] ?? 'Unknown')) . '</td>
                <td style="padding: 8px; border: 1px solid #dee2e6;">' . esc_html($date) . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
                </table>
            </div>
        </div>';
        
        return $html;
    }
    
    /**
     * Display subscription status and payments in order details
     */
    public function display_subscription_status($order) {
        if ($order->get_payment_method() !== 'coinsub') {
            return;
        }
        
        // Display cancelled message if exists
        $cancelled_message = $order->get_meta('_coinsub_cancelled_message');
        if (!empty($cancelled_message)) {
            echo $cancelled_message;
        }
        
        // Display payments if exists
        $payments_display = $order->get_meta('_coinsub_payments_display');
        if (!empty($payments_display)) {
            echo $payments_display;
        } else {
            // For active subscriptions, try to fetch and display payments
            $this->maybe_display_subscription_payments($order);
        }
    }

    /**
     * Maybe display subscription payments for active subscriptions
     */
    private function maybe_display_subscription_payments($order) {
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        $agreement_id = $order->get_meta('_coinsub_agreement_id');
        
        if (!$is_subscription || empty($agreement_id)) {
            return;
        }
        
        // Only fetch once per page load to avoid multiple API calls
        static $fetched_orders = array();
        if (in_array($order->get_id(), $fetched_orders)) {
            return;
        }
        $fetched_orders[] = $order->get_id();
        
        $api_client = new CoinSub_API_Client();
        $payments = $api_client->get_all_payments();
        
        if (is_wp_error($payments)) {
            return;
        }
        
        // Filter payments for this agreement
        $agreement_payments = array();
        if (is_array($payments)) {
            foreach ($payments as $payment) {
                if (isset($payment['agreement_id']) && $payment['agreement_id'] === $agreement_id) {
                    $agreement_payments[] = $payment;
                }
            }
        }
        
        if (!empty($agreement_payments)) {
            $payments_html = $this->generate_payments_html($agreement_payments);
            echo $payments_html;
        }
    }

    /**
     * Get blockchain explorer URL using OKLink (supports all networks)
     */
    private function get_explorer_url($chain_id, $transaction_hash) {
        // Map chain IDs to OKLink network names
        $networks = array(
            '1' => 'eth',           // Ethereum Mainnet
            '137' => 'polygon',     // Polygon
            '80002' => 'amoy',      // Polygon Amoy Testnet
            '11155111' => 'sepolia', // Sepolia Testnet
            '56' => 'bsc',          // BSC
            '43114' => 'avaxc',     // Avalanche C-Chain
            '42161' => 'arbitrum',  // Arbitrum One
            '10' => 'optimism',     // Optimism
            '8453' => 'base',       // Base
            '421613' => 'sepolia',  // Sepolia Testnet
            
        );
        
        // Get network name or default to polygon
        $network = isset($networks[$chain_id]) ? $networks[$chain_id] : 'polygon';
        
        // Return OKLink explorer URL
        return 'https://www.oklink.com/' . $network . '/tx/' . $transaction_hash;
    }
    
    /**
     * Add CoinSub information to order emails
     */
    public function add_coinsub_info_to_email($order, $sent_to_admin, $plain_text, $email) {
        $purchase_session_id = $order->get_meta('_coinsub_purchase_session_id');
        
        if (empty($purchase_session_id)) {
            return;
        }
        
        if ($plain_text) {
            echo "\n" . __('CoinSub Payment Information:', 'coinsub-commerce') . "\n";
            echo __('Purchase Session ID: ', 'coinsub-commerce') . $purchase_session_id . "\n";
            
            $checkout_url = $order->get_meta('_coinsub_checkout_url');
            if ($checkout_url) {
                echo __('Payment URL: ', 'coinsub-commerce') . $checkout_url . "\n";
            }
        } else {
            ?>
            <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba;">
                <h3 style="margin-top: 0;"><?php _e('CoinSub Payment Information', 'coinsub-commerce'); ?></h3>
                <p>
                    <strong><?php _e('Purchase Session ID:', 'coinsub-commerce'); ?></strong><br>
                    <code><?php echo esc_html($purchase_session_id); ?></code>
                </p>
                
                <?php
                $checkout_url = $order->get_meta('_coinsub_checkout_url');
                if ($checkout_url):
                ?>
                <p>
                    <a href="<?php echo esc_url($checkout_url); ?>" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; display: inline-block;">
                        <?php _e('Complete Payment', 'coinsub-commerce'); ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>
            <?php
        }
    }
    
    /**
     * Get order status from CoinSub
     */
    public function get_coinsub_order_status($order) {
        $purchase_session_id = $order->get_meta('_coinsub_purchase_session_id');
        
        if (empty($purchase_session_id)) {
            return null;
        }
        
        $api_client = new CoinSub_API_Client();
        $status = $api_client->get_purchase_session_status($purchase_session_id);
        
        if (is_wp_error($status)) {
            return null;
        }
        
        return $status;
    }
    
    /**
     * Sync order status with CoinSub
     */
    public function sync_order_status($order) {
        $status = $this->get_coinsub_order_status($order);
        
        if (!$status) {
            return;
        }
        
        $coinsub_status = $status['purchase_session_status'] ?? 'unknown';
        
        switch ($coinsub_status) {
            case 'completed':
                if ($order->get_status() !== 'processing' && $order->get_status() !== 'completed') {
                    $order->update_status('processing', __('Payment confirmed via CoinSub', 'coinsub-commerce'));
                }
                break;
                
            case 'failed':
                if ($order->get_status() !== 'failed') {
                    $order->update_status('failed', __('Payment failed via CoinSub', 'coinsub-commerce'));
                }
                break;
                
            case 'expired':
                if ($order->get_status() !== 'cancelled') {
                    $order->update_status('cancelled', __('Payment session expired', 'coinsub-commerce'));
                }
                break;
        }
    }
    
    /**
     * Add refund request button for customers
     */
    public function add_refund_request_button($order) {
        // Only show for CoinSub orders
        $purchase_session_id = $order->get_meta('_coinsub_purchase_session_id');
        if (empty($purchase_session_id)) {
            return;
        }
        // Show for paid orders (processing or completed)
        if (!in_array($order->get_status(), array('processing','completed'), true)) {
            return;
        }
        // Always allow customer refund request for paid orders
        // Check if refund already requested
        $refund_requested = $order->get_meta('_coinsub_refund_requested');
        if ($refund_requested === 'yes') {
            echo '<div class="coinsub-refund-info" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;">';
            echo '<h3>Refund Requested</h3>';
            echo '<p>You have requested a refund for this order.</p>';
            echo '</div>';
            return;
        }
        
        // Check if refund already processed
        $refund_processed = $order->get_meta('_coinsub_refund_processed');
        if ($refund_processed === 'yes') {
            $refund_amount = $order->get_meta('_coinsub_refund_amount') ?: $order->get_total();
            $processed_date = $order->get_meta('_coinsub_refund_processed_date');
            echo '<div class="coinsub-refund-info" style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 20px 0; border-radius: 5px;">';
            echo '<h3>Refund Processed</h3>';
            echo '<p>Your refund of ' . wc_price($refund_amount) . ' has been processed and sent to your wallet on ' . $processed_date . '.</p>';
            echo '</div>';
            return;
        }
        
        ?>
        <div class="coinsub-refund-section" style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h3>Request Refund</h3>
            <p>If you need to request a refund for this order, please click the button below.</p>
            
            <button type="button" id="coinsub-request-refund" class="button" 
                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                    data-purchase-session="<?php echo esc_attr($purchase_session_id); ?>">
                Request Refund
            </button>
            
            <div id="coinsub-refund-message" style="margin-top: 10px; display: none;"></div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#coinsub-request-refund').on('click', function() {
                var orderId = $(this).data('order-id');
                var purchaseSession = $(this).data('purchase-session');
                var button = $(this);
                var messageDiv = $('#coinsub-refund-message');
                
                button.prop('disabled', true).text('Processing...');
                messageDiv.hide();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'coinsub_request_refund',
                        order_id: orderId,
                        purchase_session_id: purchaseSession,
                        nonce: '<?php echo wp_create_nonce('coinsub_refund_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            messageDiv.removeClass('error').addClass('success').html('<p style="color: green;">' + response.data.message + '</p>').show();
                            button.text('Refund Requested').prop('disabled', true);
                            location.reload(); // Refresh to show updated status
                        } else {
                            messageDiv.removeClass('success').addClass('error').html('<p style="color: red;">' + response.data + '</p>').show();
                            button.prop('disabled', false).text('Request Refund');
                        }
                    },
                    error: function() {
                        messageDiv.removeClass('success').addClass('error').html('<p style="color: red;">An error occurred. Please try again.</p>').show();
                        button.prop('disabled', false).text('Request Refund');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for refund requests
     */
    public function ajax_request_refund() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'coinsub_refund_nonce')) {
            wp_die('Security check failed');
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        // Check if user owns this order
        if (is_user_logged_in()) {
            $current_user_id = get_current_user_id();
            if ($order->get_customer_id() !== $current_user_id) {
                wp_send_json_error('You do not have permission to request a refund for this order');
            }
        }
        
        // Mark refund as requested
        $order->update_meta_data('_coinsub_refund_requested', 'yes');
        $order->update_meta_data('_coinsub_refund_requested_date', current_time('mysql'));
        $order->add_order_note('Customer requested refund via CoinSub');
        $order->save();
        
        // Send notification to admin
        $this->send_refund_notification($order, $_POST['purchase_session_id']);
        
        wp_send_json_success(array(
            'message' => 'Refund request submitted.'
        ));
    }
    
    /**
     * Send refund notification to admin
     */
    private function send_refund_notification($order, $purchase_session_id) {
        $admin_email = get_option('admin_email');
        $subject = 'CoinSub Refund Request - Order #' . $order->get_id();
        $message = 'A customer has requested a refund for order #' . $order->get_id() . "\n\n";
        $message .= 'Customer: ' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . "\n";
        $message .= 'Email: ' . $order->get_billing_email() . "\n";
        $message .= 'Amount: ' . wc_price($order->get_total()) . "\n";
        $message .= 'Purchase Session ID: ' . $purchase_session_id . "\n\n";
        $message .= 'Please process this refund in the WooCommerce admin panel.';
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Display refund information in admin
     */
    public function display_refund_info($order) {
        // Only show for CoinSub orders
        $purchase_session_id = $order->get_meta('_coinsub_purchase_session_id');
        if (empty($purchase_session_id)) {
            return;
        }
        
        $refund_requested = $order->get_meta('_coinsub_refund_requested');
        $refund_processed = $order->get_meta('_coinsub_refund_processed');
        $is_subscription = $order->get_meta('_coinsub_is_subscription') === 'yes';
        $order_total = $order->get_total();
        
        // Custom refund status section removed - using WooCommerce standard refund interface
    }
    
    // AJAX handlers removed - using WooCommerce standard refund system
}
