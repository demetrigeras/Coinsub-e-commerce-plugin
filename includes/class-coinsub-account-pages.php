<?php
/**
 * CoinSub Account Pages
 * 
 * Adds custom account pages for Coinsub orders and subscriptions
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Account_Pages {
    
    public function __construct() {
        // Add custom endpoints to My Account
        add_action('init', array($this, 'add_endpoints'));
        
        // Add menu items
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_items'), 10, 1);
        
        // Display content for endpoints
        add_action('woocommerce_account_coinsub-orders_endpoint', array($this, 'display_coinsub_orders'));
        
        // Handle AJAX subscription cancellation
        add_action('wp_ajax_coinsub_cancel_subscription_from_orders', array($this, 'ajax_cancel_subscription'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Register custom endpoints
     */
    public function add_endpoints() {
        add_rewrite_endpoint('coinsub-orders', EP_ROOT | EP_PAGES);
        
        // Flush rewrite rules on activation (only once)
        if (get_option('coinsub_flush_rewrite_rules') !== 'done') {
            flush_rewrite_rules();
            update_option('coinsub_flush_rewrite_rules', 'done');
        }
    }
    
    /**
     * Add menu items to My Account
     */
    public function add_menu_items($items) {
        // Insert "Coinsub Orders" after "Orders"
        $new_items = array();
        
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            
            // Add after "orders"
            if ($key === 'orders') {
                $new_items['coinsub-orders'] = __('Coinsub Payments', 'coinsub');
            }
        }
        
        return $new_items;
    }
    
    /**
     * Display Coinsub orders page
     */
    public function display_coinsub_orders() {
        $customer_id = get_current_user_id();
        
        // Get all orders paid with Coinsub
        $orders = wc_get_orders(array(
            'customer_id' => $customer_id,
            'payment_method' => 'coinsub',
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        ?>
        <div class="coinsub-orders-page">
            <h2><?php _e('Crypto Payments', 'coinsub'); ?></h2>
            
            <?php if (empty($orders)): ?>
                <div class="woocommerce-Message woocommerce-Message--info woocommerce-info">
                    <p><?php _e('You haven\'t made any crypto payments yet.', 'coinsub'); ?></p>
                </div>
            <?php else: ?>
                <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
                    <thead>
                        <tr>
                            <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-number">
                                <span class="nobr"><?php _e('Order', 'coinsub'); ?></span>
                            </th>
                            <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-date">
                                <span class="nobr"><?php _e('Date', 'coinsub'); ?></span>
                            </th>
                            <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-status">
                                <span class="nobr"><?php _e('Status', 'coinsub'); ?></span>
                            </th>
                            <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-total">
                                <span class="nobr"><?php _e('Total', 'coinsub'); ?></span>
                            </th>
                            <th class="woocommerce-orders-table__header woocommerce-orders-table__header-transaction">
                                <span class="nobr"><?php _e('Transaction', 'coinsub'); ?></span>
                            </th>
                            <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-actions">
                                <span class="nobr"><?php _e('Actions', 'coinsub'); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): 
                            $order_id = $order->get_id();
                            $tx_hash = $order->get_meta('_coinsub_transaction_hash');
                            $chain_id = $order->get_meta('_coinsub_chain_id');
                            $agreement_id = $order->get_meta('_coinsub_agreement_id');
                            $is_subscription = $agreement_id !== '';
                            $subscription_status = $order->get_meta('_coinsub_subscription_status');
                            $can_cancel = $is_subscription && ($subscription_status === 'active' || $subscription_status === '');
                            ?>
                            <tr class="woocommerce-orders-table__row order" data-order-id="<?php echo esc_attr($order_id); ?>">
                                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e('Order', 'coinsub'); ?>">
                                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                        #<?php echo $order_id; ?>
                                    </a>
                                    <?php if ($is_subscription): ?>
                                        <span class="coinsub-subscription-badge" style="background: #7e57c2; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px;">
                                            <?php _e('SUBSCRIPTION', 'coinsub'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e('Date', 'coinsub'); ?>">
                                    <time datetime="<?php echo esc_attr($order->get_date_created()->date('c')); ?>">
                                        <?php echo esc_html($order->get_date_created()->date_i18n(wc_date_format())); ?>
                                    </time>
                                </td>
                                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e('Status', 'coinsub'); ?>">
                                    <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
                                </td>
                                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php esc_attr_e('Total', 'coinsub'); ?>">
                                    <?php 
                                    /* translators: 1: formatted order total 2: total order items */
                                    printf(_n('%1$s for %2$s item', '%1$s for %2$s items', $order->get_item_count(), 'coinsub'), 
                                        $order->get_formatted_order_total(), 
                                        $order->get_item_count()
                                    ); 
                                    ?>
                                </td>
                                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-transaction" data-title="<?php esc_attr_e('Transaction', 'coinsub'); ?>">
                                    <?php if ($tx_hash): ?>
                                        <?php
                                        $explorer_url = $this->get_explorer_url($chain_id, $tx_hash);
                                        $short_hash = substr($tx_hash, 0, 6) . '...' . substr($tx_hash, -4);
                                        ?>
                                        <a href="<?php echo esc_url($explorer_url); ?>" target="_blank" rel="noopener noreferrer" style="color: #7e57c2; text-decoration: none;">
                                            <?php echo esc_html($short_hash); ?>
                                            <span style="font-size: 12px;">↗</span>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">
                                            <?php 
                                            if ($order->get_status() === 'on-hold') {
                                                _e('Pending...', 'coinsub');
                                            } else {
                                                _e('N/A', 'coinsub');
                                            }
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php esc_attr_e('Actions', 'coinsub'); ?>">
                                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="woocommerce-button button view">
                                        <?php _e('View', 'coinsub'); ?>
                                    </a>
                                    <?php if ($can_cancel): ?>
                                        <button 
                                            type="button" 
                                            class="woocommerce-button button coinsub-cancel-subscription-btn" 
                                            data-agreement-id="<?php echo esc_attr($agreement_id); ?>"
                                            data-order-id="<?php echo esc_attr($order_id); ?>"
                                            style="background: #dc3545; color: white; margin-left: 5px;">
                                            <?php _e('Cancel Subscription', 'coinsub'); ?>
                                        </button>
                                    <?php elseif ($is_subscription && $subscription_status === 'cancelled'): ?>
                                        <span style="color: #999; font-size: 12px;">
                                            <?php _e('Cancelled', 'coinsub'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <style>
        .coinsub-orders-page {
            margin-bottom: 2em;
        }
        .coinsub-orders-page h2 {
            margin-bottom: 1.5em;
        }
        .coinsub-cancel-subscription-btn {
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .coinsub-cancel-subscription-btn:hover {
            background: #c82333 !important;
        }
        .coinsub-cancel-subscription-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        @media screen and (max-width: 768px) {
            .coinsub-subscription-badge {
                display: block;
                margin-top: 5px;
                margin-left: 0 !important;
            }
            .coinsub-cancel-subscription-btn {
                display: block;
                margin-top: 5px;
                margin-left: 0 !important;
                width: 100%;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Get blockchain explorer URL
     */
    private function get_explorer_url($chain_id, $tx_hash) {
        $explorers = array(
            '1' => 'https://www.oklink.com/eth',
            '137' => 'https://www.oklink.com/polygon',
            '8453' => 'https://www.oklink.com/base',
            '42161' => 'https://www.oklink.com/arbitrum',
            '10' => 'https://www.oklink.com/optimism',
            '56' => 'https://www.oklink.com/bsc',
            '43114' => 'https://www.oklink.com/avax',
            '250' => 'https://www.oklink.com/fantom',
            '42220' => 'https://www.oklink.com/celo',
            '100' => 'https://www.oklink.com/gnosis',
        );
        
        $base_url = isset($explorers[$chain_id]) ? $explorers[$chain_id] : 'https://www.oklink.com';
        return $base_url . '/tx/' . $tx_hash;
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if (is_account_page()) {
            wp_enqueue_script('jquery');
            
            wp_add_inline_script('jquery', "
                jQuery(document).ready(function($) {
                    $('.coinsub-cancel-subscription-btn').on('click', function(e) {
                        e.preventDefault();
                        
                        var button = $(this);
                        var agreementId = button.data('agreement-id');
                        var orderId = button.data('order-id');
                        var row = button.closest('tr');
                        
                        if (!confirm('" . esc_js(__('Are you sure you want to cancel this subscription? This cannot be undone.', 'coinsub')) . "')) {
                            return;
                        }
                        
                        // Disable button
                        button.prop('disabled', true).text('" . esc_js(__('Cancelling...', 'coinsub')) . "');
                        
                        $.ajax({
                            url: '" . admin_url('admin-ajax.php') . "',
                            type: 'POST',
                            data: {
                                action: 'coinsub_cancel_subscription_from_orders',
                                agreement_id: agreementId,
                                order_id: orderId,
                                nonce: '" . wp_create_nonce('coinsub_cancel_subscription') . "'
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Replace button with 'Cancelled' text
                                    button.replaceWith('<span style=\"color: #999; font-size: 12px;\">" . esc_js(__('Cancelled', 'coinsub')) . "</span>');
                                    
                                    // Show success message
                                    row.before('<tr><td colspan=\"6\" style=\"background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb;\">" . esc_js(__('Subscription cancelled successfully!', 'coinsub')) . "</td></tr>');
                                    
                                    // Remove success message after 3 seconds
                                    setTimeout(function() {
                                        row.prev().fadeOut(function() {
                                            $(this).remove();
                                        });
                                    }, 3000);
                                } else {
                                    alert('" . esc_js(__('Error: ', 'coinsub')) . "' + (response.data || '" . esc_js(__('Failed to cancel subscription', 'coinsub')) . "'));
                                    button.prop('disabled', false).text('" . esc_js(__('Cancel Subscription', 'coinsub')) . "');
                                }
                            },
                            error: function() {
                                alert('" . esc_js(__('An error occurred. Please try again.', 'coinsub')) . "');
                                button.prop('disabled', false).text('" . esc_js(__('Cancel Subscription', 'coinsub')) . "');
                            }
                        });
                    });
                });
            ");
        }
    }
    
    /**
     * AJAX handler for subscription cancellation
     */
    public function ajax_cancel_subscription() {
        check_ajax_referer('coinsub_cancel_subscription', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in to cancel a subscription.', 'coinsub'));
        }
        
        $agreement_id = isset($_POST['agreement_id']) ? sanitize_text_field($_POST['agreement_id']) : '';
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        if (empty($agreement_id) || empty($order_id)) {
            wp_send_json_error(__('Invalid request.', 'coinsub'));
        }
        
        // Get order and verify ownership
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(__('Invalid order or permission denied.', 'coinsub'));
        }
        
        // Verify agreement ID matches
        if ($order->get_meta('_coinsub_agreement_id') !== $agreement_id) {
            wp_send_json_error(__('Agreement ID mismatch.', 'coinsub'));
        }
        
        // Check if already cancelled
        if ($order->get_meta('_coinsub_subscription_status') === 'cancelled') {
            wp_send_json_error(__('This subscription is already cancelled.', 'coinsub'));
        }
        
        // Call API to cancel
        if (!class_exists('CoinSub_API_Client')) {
            wp_send_json_error(__('API client not available.', 'coinsub'));
        }
        
        $api_client = new CoinSub_API_Client();
        $result = $api_client->cancel_agreement($agreement_id);
        
        if (is_wp_error($result)) {
            error_log('CoinSub: Failed to cancel subscription - ' . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
        }
        
        // Update order meta
        $order->update_meta_data('_coinsub_subscription_status', 'cancelled');
        $order->add_order_note(__('Subscription cancelled by customer from account page.', 'coinsub'));
        $order->save();
        
        wp_send_json_success(array(
            'message' => __('Subscription cancelled successfully!', 'coinsub')
        ));
    }
}

