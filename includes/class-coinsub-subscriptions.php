<?php
/**
 * CoinSub Subscriptions Manager
 * 
 * Handles subscription products and customer subscription management
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Subscriptions {
    
    private $api_client;
    
    public function __construct() {
        // Cart validation
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_cart_items'), 10, 3);
        
        // Add subscription tab to My Account
        add_filter('woocommerce_account_menu_items', array($this, 'add_subscriptions_menu'));
        add_action('init', array($this, 'add_subscriptions_endpoint'));
        add_action('woocommerce_account_coinsub-subscriptions_endpoint', array($this, 'subscriptions_content'));
        
        // Handle subscription cancellation
        add_action('wp_ajax_coinsub_cancel_subscription', array($this, 'ajax_cancel_subscription'));
        
        // Add subscription fields to product
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_subscription_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_subscription_fields'));
    }
    
    /**
     * Get API client instance
     */
    private function get_api_client() {
        if ($this->api_client === null) {
            if (!class_exists('CoinSub_API_Client')) {
                return null;
            }
            $this->api_client = new CoinSub_API_Client();
        }
        return $this->api_client;
    }
    
    /**
     * Validate cart items - enforce subscription rules
     */
    public function validate_cart_items($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        $is_subscription = $product->get_meta('_coinsub_subscription') === 'yes';
        
        // Check what's already in cart
        $cart = WC()->cart->get_cart();
        $has_subscription = false;
        $has_regular = false;
        
        foreach ($cart as $cart_item) {
            $cart_product = $cart_item['data'];
            if ($cart_product->get_meta('_coinsub_subscription') === 'yes') {
                $has_subscription = true;
            } else {
                $has_regular = true;
            }
        }
        
        // Enforce rules
        if ($is_subscription && $has_subscription) {
            wc_add_notice(__('You can only have one subscription in your cart at a time. Please checkout your current subscription first.', 'coinsub'), 'error');
            return false;
        }
        
        if ($is_subscription && $has_regular) {
            wc_add_notice(__('Subscriptions must be purchased separately. Please checkout your current items first.', 'coinsub'), 'error');
            return false;
        }
        
        if (!$is_subscription && $has_subscription) {
            wc_add_notice(__('You have a subscription in your cart. Subscriptions must be purchased separately. Please checkout the subscription first.', 'coinsub'), 'error');
            return false;
        }
        
        return $passed;
    }
    
    /**
     * Add subscription fields to product edit page
     */
    public function add_subscription_fields() {
        global $post;
        
        echo '<div class="options_group show_if_simple">';
        
        woocommerce_wp_checkbox(array(
            'id' => '_coinsub_subscription',
            'label' => __('Coinsub Subscription', 'coinsub'),
            'description' => __('Enable this to make this a recurring subscription product', 'coinsub'),
            'value' => get_post_meta($post->ID, '_coinsub_subscription', true)
        ));
        
        woocommerce_wp_select(array(
            'id' => '_coinsub_frequency',
            'label' => __('Frequency', 'coinsub'),
            'options' => array(
                '1' => 'Every',
                '2' => 'Every Other',
                '3' => 'Every Third',
                '4' => 'Every Fourth',
                '5' => 'Every Fifth',
                '6' => 'Every Sixth',
                '7' => 'Every Seventh',
            ),
            'value' => get_post_meta($post->ID, '_coinsub_frequency', true),
            'desc_tip' => true,
            'description' => __('How often the subscription renews', 'coinsub')
        ));
        
        woocommerce_wp_select(array(
            'id' => '_coinsub_interval',
            'label' => __('Interval', 'coinsub'),
            'options' => array(
                '0' => 'Day',
                '1' => 'Week',
                '2' => 'Month',
                '3' => 'Year',
            ),
            'value' => get_post_meta($post->ID, '_coinsub_interval', true),
            'desc_tip' => true,
            'description' => __('Time period for the subscription', 'coinsub')
        ));
        
        $duration_value = get_post_meta($post->ID, '_coinsub_duration', true);
        $duration_display = ($duration_value === '0' || empty($duration_value)) ? '' : $duration_value;
        
        echo '<p class="form-field _coinsub_duration_field">';
        echo '<label for="_coinsub_duration">' . __('Duration', 'coinsub') . '</label>';
        echo '<input type="text" id="_coinsub_duration" name="_coinsub_duration" value="' . esc_attr($duration_display) . '" placeholder="Until Cancelled" style="width: 50%;" />';
        echo '<span class="description" style="display: block; margin-top: 5px;">';
        echo __('Leave blank for <strong>"Until Cancelled"</strong> (subscription continues forever)<br>Or enter a number for limited payments (e.g., <strong>12</strong> = stops after 12 payments)', 'coinsub');
        echo '</span>';
        echo '</p>';
        
        echo '</div>';
    }
    
    /**
     * Save subscription fields
     */
    public function save_subscription_fields($post_id) {
        $is_subscription = isset($_POST['_coinsub_subscription']) ? 'yes' : 'no';
        update_post_meta($post_id, '_coinsub_subscription', $is_subscription);
        
        if ($is_subscription === 'yes') {
            $frequency = isset($_POST['_coinsub_frequency']) ? sanitize_text_field($_POST['_coinsub_frequency']) : '1';
            $interval = isset($_POST['_coinsub_interval']) ? sanitize_text_field($_POST['_coinsub_interval']) : '2';
            $duration = isset($_POST['_coinsub_duration']) ? sanitize_text_field($_POST['_coinsub_duration']) : '';
            
            // Convert empty duration to "0" (Until Cancelled)
            if (empty($duration) || $duration === 'Until Cancelled') {
                $duration = '0';
            }
            
            error_log('💾 Saving subscription product #' . $post_id);
            error_log('  Frequency: ' . $frequency);
            error_log('  Interval: ' . $interval);
            error_log('  Duration: ' . $duration);
            
            update_post_meta($post_id, '_coinsub_frequency', $frequency);
            update_post_meta($post_id, '_coinsub_interval', $interval);
            update_post_meta($post_id, '_coinsub_duration', $duration);
        }
    }
    
    /**
     * Add subscriptions menu item to My Account
     */
    public function add_subscriptions_menu($items) {
        $new_items = array();
        
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            
            // Add after orders
            if ($key === 'orders') {
                $new_items['coinsub-subscriptions'] = __('Subscriptions', 'coinsub');
            }
        }
        
        return $new_items;
    }
    
    /**
     * Register subscriptions endpoint
     */
    public function add_subscriptions_endpoint() {
        add_rewrite_endpoint('coinsub-subscriptions', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Display subscriptions content
     */
    public function subscriptions_content() {
        $customer_id = get_current_user_id();
        
        // Get customer's subscriptions
        $subscriptions = $this->get_customer_subscriptions($customer_id);
        
        ?>
        <h2><?php _e('My Subscriptions', 'coinsub'); ?></h2>
        
        <?php if (empty($subscriptions)): ?>
            <p><?php _e('You have no active subscriptions.', 'coinsub'); ?></p>
        <?php else: ?>
            <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders">
                <thead>
                    <tr>
                        <th><?php _e('Product', 'coinsub'); ?></th>
                        <th><?php _e('Amount', 'coinsub'); ?></th>
                        <th><?php _e('Frequency', 'coinsub'); ?></th>
                        <th><?php _e('Next Payment', 'coinsub'); ?></th>
                        <th><?php _e('Status', 'coinsub'); ?></th>
                        <th><?php _e('Actions', 'coinsub'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subscriptions as $subscription): ?>
                    <tr>
                        <td><?php echo esc_html($subscription['product_name']); ?></td>
                        <td><?php echo wc_price($subscription['amount']); ?></td>
                        <td><?php echo esc_html($subscription['frequency_text']); ?></td>
                        <td><?php echo esc_html($subscription['next_payment']); ?></td>
                        <td><?php echo esc_html($subscription['status']); ?></td>
                        <td>
                            <?php if ($subscription['status'] === 'Active'): ?>
                                <button class="button coinsub-cancel-subscription" 
                                        data-agreement-id="<?php echo esc_attr($subscription['agreement_id']); ?>"
                                        data-order-id="<?php echo esc_attr($subscription['order_id']); ?>">
                                    <?php _e('Cancel', 'coinsub'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <script>
            jQuery(document).ready(function($) {
                $('.coinsub-cancel-subscription').on('click', function(e) {
                    e.preventDefault();
                    
                    if (!confirm('<?php _e('Are you sure you want to cancel this subscription?', 'coinsub'); ?>')) {
                        return;
                    }
                    
                    var button = $(this);
                    var agreementId = button.data('agreement-id');
                    var orderId = button.data('order-id');
                    
                    button.prop('disabled', true).text('<?php _e('Cancelling...', 'coinsub'); ?>');
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'coinsub_cancel_subscription',
                            agreement_id: agreementId,
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('coinsub_cancel_subscription'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('<?php _e('Subscription cancelled successfully', 'coinsub'); ?>');
                                location.reload();
                            } else {
                                alert(response.data.message);
                                button.prop('disabled', false).text('<?php _e('Cancel', 'coinsub'); ?>');
                            }
                        },
                        error: function() {
                            alert('<?php _e('Error cancelling subscription', 'coinsub'); ?>');
                            button.prop('disabled', false).text('<?php _e('Cancel', 'coinsub'); ?>');
                        }
                    });
                });
            });
            </script>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Get customer's subscriptions
     */
    private function get_customer_subscriptions($customer_id) {
        $subscriptions = array();
        
        // Get orders with subscriptions
        $orders = wc_get_orders(array(
            'customer_id' => $customer_id,
            'limit' => -1,
            'meta_key' => '_coinsub_agreement_id',
            'meta_compare' => 'EXISTS'
        ));
        
        foreach ($orders as $order) {
            $agreement_id = $order->get_meta('_coinsub_agreement_id');
            $status = $order->get_meta('_coinsub_subscription_status');
            
            if (empty($agreement_id) || $status === 'cancelled') {
                continue;
            }
            
            $subscriptions[] = array(
                'order_id' => $order->get_id(),
                'agreement_id' => $agreement_id,
                'product_name' => $this->get_subscription_product_name($order),
                'amount' => $order->get_total(),
                'frequency_text' => $order->get_meta('_coinsub_frequency_text'),
                'next_payment' => $order->get_meta('_coinsub_next_payment'),
                'status' => ucfirst($status ?: 'Active')
            );
        }
        
        return $subscriptions;
    }
    
    /**
     * Get subscription product name from order
     */
    private function get_subscription_product_name($order) {
        $items = $order->get_items();
        if (empty($items)) {
            return 'Subscription';
        }
        
        $first_item = reset($items);
        return $first_item->get_name();
    }
    
    /**
     * AJAX handler for subscription cancellation
     */
    public function ajax_cancel_subscription() {
        check_ajax_referer('coinsub_cancel_subscription', 'nonce');
        
        $agreement_id = sanitize_text_field($_POST['agreement_id']);
        $order_id = absint($_POST['order_id']);
        
        // Verify order belongs to current user
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() != get_current_user_id()) {
            wp_send_json_error(array('message' => __('Invalid order', 'coinsub')));
        }
        
        // Call Coinsub API to cancel
        $api_client = $this->get_api_client();
        if (!$api_client) {
            wp_send_json_error(array('message' => __('API client not available', 'coinsub')));
        }
        
        $result = $api_client->cancel_agreement($agreement_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update order meta
        $order->update_meta_data('_coinsub_subscription_status', 'cancelled');
        $order->add_order_note(__('Subscription cancelled by customer', 'coinsub'));
        $order->save();
        
        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'coinsub')));
    }
}

// Initialize
new CoinSub_Subscriptions();

