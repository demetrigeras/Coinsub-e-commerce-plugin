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
}
