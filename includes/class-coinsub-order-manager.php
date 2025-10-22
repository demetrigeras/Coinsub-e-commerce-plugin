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
        
        // Send custom CoinSub merchant notification
        $this->send_custom_merchant_notification($order);
    }
    
    /**
     * Send custom CoinSub merchant notification
     */
    private function send_custom_merchant_notification($order) {
        // Prevent duplicate calls
        static $processed_orders = array();
        $order_id = $order->get_id();
        
        if (in_array($order_id, $processed_orders)) {
            error_log('📧 CoinSub Order Manager: Order #' . $order_id . ' already processed, skipping duplicate');
            return;
        }
        
        $processed_orders[] = $order_id;
        
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
        
        // Send the email with multiple fallback methods
        $email_sent = $this->send_email_via_wp_mail($merchant_email, $subject, $message);
        
        // If wp_mail fails, try a simple approach
        if (!$email_sent) {
            $this->send_simple_email($merchant_email, $subject, $message);
        }
    }
    
    /**
     * Send email via wp_mail with robust error handling
     */
    private function send_email_via_wp_mail($to, $subject, $message) {
        // Validate email addresses
        $admin_email = get_option('admin_email');
        if (empty($admin_email) || !is_email($admin_email)) {
            error_log('❌ CoinSub Order Manager: Invalid admin email: ' . $admin_email);
            return false;
        }
        
        if (empty($to) || !is_email($to)) {
            error_log('❌ CoinSub Order Manager: Invalid recipient email: ' . $to);
            return false;
        }
        
        error_log('📧 CoinSub Order Manager: Sending email via wp_mail to: ' . $to);
        error_log('📧 CoinSub Order Manager: From: ' . get_bloginfo('name') . ' <' . $admin_email . '>');
        error_log('📧 CoinSub Order Manager: Subject: ' . $subject);
        
        // Configure PHPMailer to use SMTP if available, fallback to mail()
        $this->configure_phpmailer();
        
        $headers = array(
            'Content-Type' => 'text/plain; charset=UTF-8',
            'From' => get_bloginfo('name') . ' <' . $admin_email . '>',
            'Reply-To' => $admin_email,
            'X-Mailer' => 'CoinSub WooCommerce Plugin'
        );
        
        // Try to send the email
        $result = wp_mail($to, $subject, $message, $headers);
        
        if ($result) {
            error_log('✅ CoinSub Order Manager: Email sent successfully via wp_mail');
            return true;
        } else {
            error_log('❌ CoinSub Order Manager: Failed to send email via wp_mail');
            
            // Try alternative method
            return $this->send_email_alternative($to, $subject, $message, $admin_email);
        }
    }
    
    /**
     * Configure PHPMailer for better email delivery
     */
    private function configure_phpmailer() {
        add_action('phpmailer_init', function($phpmailer) {
            // Try to use SMTP if available
            if (function_exists('mail') && !$phpmailer->isSMTP()) {
                // Use mail() function as fallback
                $phpmailer->isMail();
            }
            
            // Set additional headers for better delivery
            $phpmailer->addCustomHeader('X-Mailer', 'CoinSub WooCommerce Plugin');
            $phpmailer->addCustomHeader('X-Priority', '3');
        });
    }
    
    /**
     * Alternative email sending method
     */
    private function send_email_alternative($to, $subject, $message, $from_email) {
        error_log('📧 CoinSub Order Manager: Trying alternative email method');
        
        // Try using mail() function directly
        if (function_exists('mail')) {
            $headers = "From: " . get_bloginfo('name') . " <" . $from_email . ">\r\n";
            $headers .= "Reply-To: " . $from_email . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "X-Mailer: CoinSub WooCommerce Plugin\r\n";
            
            $result = mail($to, $subject, $message, $headers);
            
            if ($result) {
                error_log('✅ CoinSub Order Manager: Email sent successfully via mail() function');
                return true;
            } else {
                error_log('❌ CoinSub Order Manager: Failed to send email via mail() function');
            }
        }
        
        // If all else fails, log the order details for manual processing
        error_log('📧 CoinSub Order Manager: All email methods failed. Order details logged for manual processing:');
        error_log('📧 CoinSub Order Manager: To: ' . $to);
        error_log('📧 CoinSub Order Manager: Subject: ' . $subject);
        error_log('📧 CoinSub Order Manager: Message: ' . substr($message, 0, 200) . '...');
        
        return false;
    }
    
    /**
     * Simple email sending method as final fallback
     */
    private function send_simple_email($to, $subject, $message) {
        error_log('📧 CoinSub Order Manager: Trying simple email method as final fallback');
        
        // Run server diagnostics first
        $this->run_server_mail_diagnostics();
        
        // Try the most basic mail() function
        if (function_exists('mail')) {
            $from_email = get_option('admin_email');
            $site_name = get_bloginfo('name');
            
            $headers = "From: {$site_name} <{$from_email}>\r\n";
            $headers .= "Reply-To: {$from_email}\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            $result = @mail($to, $subject, $message, $headers);
            
            if ($result) {
                error_log('✅ CoinSub Order Manager: Email sent successfully via simple mail() method');
                return true;
            } else {
                error_log('❌ CoinSub Order Manager: Simple mail() method also failed');
            }
        }
        
        // Try alternative notification methods
        $this->send_alternative_notification($to, $subject, $message);
        
        // If everything fails, at least log the important details
        error_log('🚨 CoinSub Order Manager: CRITICAL - All email methods failed!');
        error_log('🚨 CoinSub Order Manager: Manual notification required for order details');
        error_log('🚨 CoinSub Order Manager: Recipient: ' . $to);
        error_log('🚨 CoinSub Order Manager: Subject: ' . $subject);
        
        // Add admin notice for email failure
        $this->add_email_failure_notice($to, $subject);
        
        // Log to database for manual review
        $this->log_failed_email_to_database($to, $subject, $message);
        
        return false;
    }
    
    /**
     * Run server mail diagnostics
     */
    private function run_server_mail_diagnostics() {
        error_log('🔍 CoinSub Order Manager: Running server mail diagnostics...');
        
        // Check if mail function exists
        $mail_function_exists = function_exists('mail');
        error_log('🔍 CoinSub Order Manager: mail() function exists: ' . ($mail_function_exists ? 'YES' : 'NO'));
        
        // Check if sendmail is available
        $sendmail_path = ini_get('sendmail_path');
        error_log('🔍 CoinSub Order Manager: sendmail_path: ' . ($sendmail_path ?: 'NOT SET'));
        
        // Check SMTP settings
        $smtp_host = ini_get('SMTP');
        $smtp_port = ini_get('smtp_port');
        error_log('🔍 CoinSub Order Manager: SMTP host: ' . ($smtp_host ?: 'NOT SET'));
        error_log('🔍 CoinSub Order Manager: SMTP port: ' . ($smtp_port ?: 'NOT SET'));
        
        // Check if we can create a simple test
        if ($mail_function_exists) {
            $test_result = @mail('test@example.com', 'Test', 'Test message', 'From: test@example.com');
            error_log('🔍 CoinSub Order Manager: Basic mail() test result: ' . ($test_result ? 'SUCCESS' : 'FAILED'));
        }
        
        // Check WordPress mail configuration
        global $phpmailer;
        if (isset($phpmailer)) {
            error_log('🔍 CoinSub Order Manager: PHPMailer class: ' . get_class($phpmailer));
            error_log('🔍 CoinSub Order Manager: PHPMailer is SMTP: ' . ($phpmailer->isSMTP() ? 'YES' : 'NO'));
        }
    }
    
    /**
     * Send alternative notification methods
     */
    private function send_alternative_notification($to, $subject, $message) {
        error_log('📧 CoinSub Order Manager: Trying alternative notification methods...');
        
        // Method 1: Log to a file
        $this->log_to_file($to, $subject, $message);
        
        // Method 2: Store in WordPress options for admin review
        $this->store_in_admin_options($to, $subject, $message);
        
        // Method 3: Try to send via external service (if configured)
        $this->try_external_notification($to, $subject, $message);
    }
    
    /**
     * Log email to file
     */
    private function log_to_file($to, $subject, $message) {
        $log_file = WP_CONTENT_DIR . '/coinsub-email-failures.log';
        $log_entry = date('Y-m-d H:i:s') . " | TO: {$to} | SUBJECT: {$subject}\n";
        $log_entry .= "MESSAGE:\n" . $message . "\n";
        $log_entry .= str_repeat('-', 80) . "\n\n";
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        error_log('📧 CoinSub Order Manager: Email details logged to file: ' . $log_file);
    }
    
    /**
     * Store email details in WordPress options
     */
    private function store_in_admin_options($to, $subject, $message) {
        $failed_emails = get_option('coinsub_failed_emails', array());
        
        $failed_emails[] = array(
            'timestamp' => current_time('mysql'),
            'to' => $to,
            'subject' => $subject,
            'message' => substr($message, 0, 500) . '...', // Truncate for storage
            'order_id' => $this->extract_order_id_from_subject($subject)
        );
        
        // Keep only last 10 failed emails
        if (count($failed_emails) > 10) {
            $failed_emails = array_slice($failed_emails, -10);
        }
        
        update_option('coinsub_failed_emails', $failed_emails);
        error_log('📧 CoinSub Order Manager: Email details stored in WordPress options');
    }
    
    /**
     * Try external notification service
     */
    private function try_external_notification($to, $subject, $message) {
        // This could be extended to use services like Slack, Discord, etc.
        // For now, just log that we would try external service
        error_log('📧 CoinSub Order Manager: External notification service not configured');
    }
    
    /**
     * Extract order ID from subject
     */
    private function extract_order_id_from_subject($subject) {
        if (preg_match('/Order #(\d+)/', $subject, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Log failed email to database
     */
    private function log_failed_email_to_database($to, $subject, $message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'coinsub_failed_emails';
        
        // Create table if it doesn't exist
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            recipient_email varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            message text,
            order_id varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Insert the failed email record
        $wpdb->insert(
            $table_name,
            array(
                'recipient_email' => $to,
                'subject' => $subject,
                'message' => substr($message, 0, 1000), // Limit message length
                'order_id' => $this->extract_order_id_from_subject($subject),
                'created_at' => current_time('mysql')
            )
        );
        
        error_log('📧 CoinSub Order Manager: Failed email logged to database');
    }
    
    /**
     * Add admin notice for email failure
     */
    private function add_email_failure_notice($to, $subject) {
        // Store the failure in a transient for display in admin
        $failure_data = array(
            'to' => $to,
            'subject' => $subject,
            'time' => current_time('mysql'),
            'message' => 'CoinSub email notification failed to send. Check server mail configuration.'
        );
        
        set_transient('coinsub_email_failure', $failure_data, 3600); // Store for 1 hour
        
        // Add admin notice hook
        add_action('admin_notices', array($this, 'display_email_failure_notice'));
    }
    
    /**
     * Display email failure notice in admin
     */
    public function display_email_failure_notice() {
        $failure_data = get_transient('coinsub_email_failure');
        
        if ($failure_data) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>CoinSub Email Error:</strong> Failed to send merchant notification email.</p>';
            echo '<p>Recipient: ' . esc_html($failure_data['to']) . '</p>';
            echo '<p>Subject: ' . esc_html($failure_data['subject']) . '</p>';
            echo '<p>Time: ' . esc_html($failure_data['time']) . '</p>';
            echo '<p>Please check your server mail configuration or contact your hosting provider.</p>';
            echo '</div>';
            
            // Clear the transient after displaying
            delete_transient('coinsub_email_failure');
        }
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
