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
            case 'cancelled':
                $this->handle_order_cancellation($order);
                break;
                
            case 'refunded':
                $this->handle_order_refund($order);
                break;
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
        
        // Only show if this is an active subscription
        if (empty($agreement_id) || $subscription_status === 'cancelled') {
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
        
        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'coinsub')));
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
        
        // Only show for completed orders
        if ($order->get_status() !== 'completed') {
            return;
        }
        
        // Check if refund already requested
        $refund_requested = $order->get_meta('_coinsub_refund_requested');
        if ($refund_requested === 'yes') {
            echo '<div class="coinsub-refund-info" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;">';
            echo '<h3>Refund Requested</h3>';
            echo '<p>You have requested a refund for this order. We will process it within 1-2 business days.</p>';
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
            <p>If you need to request a refund for this order, please click the button below. Refunds will be processed within 1-2 business days.</p>
            
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
            'message' => 'Refund request submitted. We will process it within 1-2 business days.'
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
        
        if ($refund_requested === 'yes' || $refund_processed === 'yes') {
            echo '<h3>CoinSub Refund Status</h3>';
            echo '<table class="form-table">';
            
            if ($refund_requested === 'yes') {
                $requested_date = $order->get_meta('_coinsub_refund_requested_date');
                echo '<tr><th>Refund Requested:</th><td>Yes (' . $requested_date . ')</td></tr>';
            }
            
            if ($refund_processed === 'yes') {
                $processed_date = $order->get_meta('_coinsub_refund_processed_date');
                $refund_amount = $order->get_meta('_coinsub_refund_amount') ?: $order_total;
                $tx_hash = $order->get_meta('_coinsub_refund_tx_hash');
                echo '<tr><th>Refund Processed:</th><td>Yes (' . $processed_date . ')</td></tr>';
                echo '<tr><th>Refund Amount:</th><td>' . wc_price($refund_amount) . '</td></tr>';
                if ($tx_hash) {
                    echo '<tr><th>Transaction Hash:</th><td>' . esc_html($tx_hash) . '</td></tr>';
                }
            } elseif ($refund_requested === 'yes') {
                // Show process refund form
                echo '<tr><th>Action:</th><td>';
                echo '<div id="coinsub-refund-form" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">';
                
                if ($is_subscription) {
                    echo '<h4>Process Subscription Refund</h4>';
                    echo '<p><strong>Original Amount:</strong> ' . wc_price($order_total) . '</p>';
                    echo '<p><strong>Enter refund amount:</strong></p>';
                    echo '<input type="number" id="refund-amount" step="0.01" min="0" max="' . $order_total . '" value="' . $order_total . '" style="width: 150px; margin-right: 10px;">';
                    echo '<span style="color: #666;">(' . get_woocommerce_currency_symbol() . ')</span>';
                } else {
                    echo '<h4>Process Refund</h4>';
                    echo '<p><strong>Amount:</strong> ' . wc_price($order_total) . '</p>';
                    echo '<input type="hidden" id="refund-amount" value="' . $order_total . '">';
                }
                
                echo '<br><br>';
                echo '<button type="button" class="button button-primary" id="coinsub-process-refund" data-order-id="' . $order->get_id() . '">Process Refund</button>';
                echo '<button type="button" class="button" id="coinsub-cancel-refund" data-order-id="' . $order->get_id() . '" style="margin-left: 10px;">Cancel Request</button>';
                echo '</div>';
                echo '</td></tr>';
            }
            
            echo '</table>';
            
            // Add JavaScript for admin refund processing
            if ($refund_requested === 'yes' && $refund_processed !== 'yes') {
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#coinsub-process-refund').on('click', function() {
                        var orderId = $(this).data('order-id');
                        var refundAmount = $('#refund-amount').val();
                        var button = $(this);
                        
                        if (!refundAmount || refundAmount <= 0) {
                            alert('Please enter a valid refund amount');
                            return;
                        }
                        
                        button.prop('disabled', true).text('Processing...');
                        
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'coinsub_admin_process_refund',
                                order_id: orderId,
                                refund_amount: refundAmount,
                                nonce: '<?php echo wp_create_nonce('coinsub_admin_refund_nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('Refund processed: ' + response.data.message);
                                    location.reload();
                                } else {
                                    alert('Error: ' + response.data);
                                    button.prop('disabled', false).text('Process Refund');
                                }
                            },
                            error: function() {
                                alert('An error occurred. Please try again.');
                                button.prop('disabled', false).text('Process Refund');
                            }
                        });
                    });
                    
                    $('#coinsub-cancel-refund').on('click', function() {
                        if (confirm('Are you sure you want to cancel this refund request?')) {
                            var orderId = $(this).data('order-id');
                            var button = $(this);
                            
                            button.prop('disabled', true).text('Cancelling...');
                            
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'coinsub_admin_cancel_refund',
                                    order_id: orderId,
                                    nonce: '<?php echo wp_create_nonce('coinsub_admin_refund_nonce'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('Refund request cancelled');
                                        location.reload();
                                    } else {
                                        alert('Error: ' + response.data);
                                        button.prop('disabled', false).text('Cancel Request');
                                    }
                                },
                                error: function() {
                                    alert('An error occurred. Please try again.');
                                    button.prop('disabled', false).text('Cancel Request');
                                }
                            });
                        }
                    });
                });
                </script>
                <?php
            }
        }
    }
    
    /**
     * AJAX handler for admin refund processing
     */
    public function ajax_admin_process_refund() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'coinsub_admin_refund_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check admin permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $order_id = intval($_POST['order_id']);
        $refund_amount = floatval($_POST['refund_amount']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        if ($refund_amount <= 0) {
            wp_send_json_error('Invalid refund amount');
        }
        
        // Store refund amount
        $order->update_meta_data('_coinsub_refund_amount', $refund_amount);
        $order->save();
        
        // Mark as processed (simplified for now)
        $order->update_meta_data('_coinsub_refund_processed', 'yes');
        $order->update_meta_data('_coinsub_refund_processed_date', current_time('mysql'));
        $order->add_order_note('Refund processed via CoinSub admin panel');
        $order->save();
        
        wp_send_json_success(array(
            'message' => 'Refund processed successfully'
        ));
    }
    
    /**
     * AJAX handler for admin refund cancellation
     */
    public function ajax_admin_cancel_refund() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'coinsub_admin_refund_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check admin permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        // Remove refund metadata
        $order->delete_meta_data('_coinsub_refund_requested');
        $order->delete_meta_data('_coinsub_refund_requested_date');
        $order->add_order_note('Refund request cancelled by admin');
        $order->save();
        
        wp_send_json_success(array(
            'message' => 'Refund request cancelled successfully'
        ));
    }
}
