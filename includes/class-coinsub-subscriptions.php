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
        // Enforce subscription quantity limits during cart checks/updates
        add_action('woocommerce_check_cart_items', array($this, 'enforce_subscription_quantities'));
        
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
        $has_same_subscription = false;
        
        foreach ($cart as $cart_item) {
            $cart_product = $cart_item['data'];
            if ($cart_product->get_meta('_coinsub_subscription') === 'yes') {
                $has_subscription = true;
                if ((int)$cart_product->get_id() === (int)$product_id) {
                    $has_same_subscription = true;
                }
            } else {
                $has_regular = true;
            }
        }
        
        // Subscriptions limited to quantity 1
        if ($is_subscription && (int)$quantity > 1) {
            wc_add_notice(__('You can only purchase one of a subscription at a time.', 'coinsub'), 'error');
            return false;
        }
        
        // Prevent adding the same subscription product twice
        if ($is_subscription && $has_same_subscription) {
            wc_add_notice(__('This subscription is already in your cart.', 'coinsub'), 'error');
            return false;
        }
        
        // Enforce rules - prevent mixing subscriptions and regular products
        if ($is_subscription && $has_regular) {
            wc_add_notice(__('Subscriptions must be purchased separately. Regular products have been removed from your cart.', 'coinsub'), 'notice');
            // Remove regular products from cart
            $this->remove_regular_products_from_cart();
            return true; // Allow the subscription to be added
        }
        
        if (!$is_subscription && $has_subscription) {
            wc_add_notice(__('You have a subscription in your cart. Subscriptions must be purchased separately. Please checkout the subscription first.', 'coinsub'), 'error');
            return false;
        }
        
        if ($is_subscription && $has_subscription) {
            wc_add_notice(__('You can only have one subscription in your cart at a time. Please checkout your current subscription first.', 'coinsub'), 'error');
            return false;
        }
        
        return $passed;
    }

    /**
     * Ensure any subscription line items are clamped to quantity 1
     */
    public function enforce_subscription_quantities() {
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            if ($product && $product->get_meta('_coinsub_subscription') === 'yes') {
                if ((int)$cart_item['quantity'] !== 1) {
                    $cart->set_quantity($cart_item_key, 1, true);
                    wc_add_notice(__('Subscription quantity has been set to 1.', 'coinsub'), 'notice');
                }
            }
        }
    }
    
    /**
     * Remove regular products from cart when subscription is present
     */
    private function remove_regular_products_from_cart() {
        $cart = WC()->cart;
        $cart_items = $cart->get_cart();
        
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $is_subscription = $product->get_meta('_coinsub_subscription') === 'yes';
            
            if (!$is_subscription) {
                $cart->remove_cart_item($cart_item_key);
                error_log('🛒 Removed regular product from cart: ' . $product->get_name());
            }
        }
    }
    
    /**
     * Add subscription fields to product edit page
     */
    public function add_subscription_fields() {
        global $post;
        
        echo '<div class="options_group show_if_simple">';
        
        woocommerce_wp_checkbox(array(
            'id' => '_coinsub_subscription',
            'label' => __('Stablecoin Pay Subscription', 'coinsub'),
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
        
        $stored_interval = get_post_meta($post->ID, '_coinsub_interval', true);
        woocommerce_wp_select(array(
            'id' => '_coinsub_interval',
            'label' => __('Interval', 'coinsub'),
            'options' => array(
                'day' => 'Day',
                'week' => 'Week',
                'month' => 'Month',
                'year' => 'Year',
            ),
            'value' => $stored_interval,
            'desc_tip' => true,
            'description' => __('Time period for the subscription', 'coinsub'),
            'custom_attributes' => array('required' => 'required')
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
    //
    /**
     * Save subscription fields
     */
    public function save_subscription_fields($post_id) {
        $is_subscription = isset($_POST['_coinsub_subscription']) ? 'yes' : 'no';
        update_post_meta($post_id, '_coinsub_subscription', $is_subscription);
        
        if ($is_subscription === 'yes') {
            $frequency = isset($_POST['_coinsub_frequency']) ? sanitize_text_field($_POST['_coinsub_frequency']) : '1';
            $interval = isset($_POST['_coinsub_interval']) ? sanitize_text_field($_POST['_coinsub_interval']) : '';
            $duration = isset($_POST['_coinsub_duration']) ? sanitize_text_field($_POST['_coinsub_duration']) : '';

            // Normalize interval to allowed label values
            $allowed_intervals = array('day','week','month','year');
            $interval = strtolower(trim($interval));
            // Map accidental numeric submissions to labels
            $num_to_label = array('0' => 'day', '1' => 'week', '2' => 'month', '3' => 'year');
            if (isset($num_to_label[$interval])) {
                $interval = $num_to_label[$interval];
            }
            if (!in_array($interval, $allowed_intervals, true)) {
                // Require a valid selection; leave as empty and rely on required attribute in UI
                $interval = '';
            }
            
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
                        <th><?php _e('Created At', 'coinsub'); ?></th>
                        <th><?php _e('Next Processing', 'coinsub'); ?></th>
                        <th><?php _e('Cancelled At', 'coinsub'); ?></th>
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
                        <td><?php echo esc_html($subscription['created_at'] ?? '—'); ?></td>
                        <td><?php echo esc_html($subscription['next_processing'] ?? '—'); ?></td>
                        <td><?php echo esc_html($subscription['cancelled_at'] ?? '—'); ?></td>
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
        
        // Get ALL customer orders with Coinsub payment
        $orders = wc_get_orders(array(
            'customer_id' => $customer_id,
            'payment_method' => 'coinsub',
            'limit' => -1,
        ));
        
        foreach ($orders as $order) {
            // Check if this is a subscription order using the metadata flag
            $is_subscription = $order->get_meta('_coinsub_is_subscription');
            
            if ($is_subscription !== 'yes') {
                continue; // Not a subscription, skip
            }
            
            // Get agreement ID (this gets set by webhook after payment)
            $agreement_id = $order->get_meta('_coinsub_agreement_id');
            
            if (empty($agreement_id)) {
                // Subscription product but no agreement yet (payment not complete)
                continue;
            }
            
            $status = $order->get_meta('_coinsub_subscription_status');
            
            // Fetch agreement details from API to get dates
            $created_at = $order->get_date_created()->date('Y-m-d H:i:s');
            $next_process_date = '';
            $cancelled_at = '';
            
            $api_client = $this->get_api_client();
            if ($api_client) {
                $agreement_response = $api_client->retrieve_agreement($agreement_id);
                if (!is_wp_error($agreement_response)) {
                    $agreement_data = isset($agreement_response['data']) ? $agreement_response['data'] : $agreement_response;
                    
                    // Extract dates from agreement data - check multiple possible field names and nests
                    if (isset($agreement_data['created_at'])) {
                        $created_at = $this->format_date($agreement_data['created_at']);
                    } elseif (isset($agreement_data['createdAt'])) {
                        $created_at = $this->format_date($agreement_data['createdAt']);
                    } elseif (isset($agreement_data['agreement']['created_at'])) {
                        $created_at = $this->format_date($agreement_data['agreement']['created_at']);
                    }
                    
                    // Check for next_process_date with multiple variants
                    if (isset($agreement_data['next_process_date'])) {
                        $next_process_date = $this->format_date($agreement_data['next_process_date']);
                    } elseif (isset($agreement_data['next_processing'])) {
                        $next_process_date = $this->format_date($agreement_data['next_processing']);
                    } elseif (isset($agreement_data['nextProcessDate'])) {
                        $next_process_date = $this->format_date($agreement_data['nextProcessDate']);
                    } elseif (isset($agreement_data['nextProcess'])) {
                        $next_process_date = $this->format_date($agreement_data['nextProcess']);
                    }
                    
                    // Cancelled variants (American/British spellings and cases)
                    if (isset($agreement_data['cancelled_at'])) {
                        $cancelled_at = $this->format_date($agreement_data['cancelled_at']);
                    } elseif (isset($agreement_data['canceled_at'])) {
                        $cancelled_at = $this->format_date($agreement_data['canceled_at']);
                    } elseif (isset($agreement_data['cancelledAt'])) {
                        $cancelled_at = $this->format_date($agreement_data['cancelledAt']);
                    } elseif (isset($agreement_data['canceledAt'])) {
                        $cancelled_at = $this->format_date($agreement_data['canceledAt']);
                    } elseif (isset($agreement_data['agreement']['cancelled_at'])) {
                        $cancelled_at = $this->format_date($agreement_data['agreement']['cancelled_at']);
                    } elseif (isset($agreement_data['agreement']['canceled_at'])) {
                        $cancelled_at = $this->format_date($agreement_data['agreement']['canceled_at']);
                    }

                    // Prefer agreement frequency/interval for display if provided
                    $agreement_frequency_text = $this->format_frequency_from_agreement($agreement_data);
                    if (!empty($agreement_frequency_text)) {
                        $frequency_text_override = $agreement_frequency_text;
                    }
                }
            }
            
            // Show both active and cancelled subscriptions
            $subscriptions[] = array(
                'order_id' => $order->get_id(),
                'agreement_id' => $agreement_id,
                'product_name' => $this->get_subscription_product_name($order),
                'amount' => $order->get_total(),
                'frequency_text' => isset($frequency_text_override) ? $frequency_text_override : $this->get_subscription_frequency_text($order),
                'status' => $status === 'cancelled' ? 'Cancelled' : 'Active',
                'created_at' => $created_at,
                'next_process_date' => $next_process_date ?: '—',
                'cancelled_at' => ($cancelled_at ?: ($order->get_meta('_coinsub_cancelled_at') ?: '—'))
            );
        }
        
        return $subscriptions;
    }
    
    /**
     * Format date from API response
     */
    private function format_date($date_value) {
        if (empty($date_value)) {
            return '';
        }
        
        // If it's a timestamp (numeric)
        if (is_numeric($date_value)) {
            return date_i18n('Y-m-d h:i:s A', (int)$date_value);
        }
        
        // If it's a date string, try to parse it
        $timestamp = strtotime($date_value);
        if ($timestamp !== false) {
            return date_i18n('Y-m-d h:i:s A', $timestamp);
        }
        
        // Return as-is if we can't parse it
        return $date_value;
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
     * Get subscription frequency text from order
     */
    private function get_subscription_frequency_text($order) {
        $frequency_map = array(
            '1' => 'Every',
            '2' => 'Every Other',
            '3' => 'Every Third',
            '4' => 'Every Fourth',
            '5' => 'Every Fifth',
            '6' => 'Every Sixth',
            '7' => 'Every Seventh',
        );
        
        $interval_map = array(
            '0' => 'Day', 'day' => 'Day',
            '1' => 'Week', 'week' => 'Week',
            '2' => 'Month', 'month' => 'Month',
            '3' => 'Year', 'year' => 'Year',
        );
        
        // Get subscription data from order items
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_coinsub_subscription') === 'yes') {
                $frequency = $product->get_meta('_coinsub_frequency');
                $interval = $product->get_meta('_coinsub_interval');
                
                $frequency_text = isset($frequency_map[$frequency]) ? $frequency_map[$frequency] : 'Every';
                $interval_text = isset($interval_map[$interval]) ? $interval_map[$interval] : 'Month';
                
                return $frequency_text . ' ' . $interval_text;
            }
        }
        
        return __('N/A', 'coinsub');
    }

    /**
     * Build frequency text from agreement data if it includes numeric frequency/interval
     */
    private function format_frequency_from_agreement($agreement_data) {
        $frequency = null;
        $interval = null;
        if (isset($agreement_data['frequency'])) {
            $frequency = is_numeric($agreement_data['frequency']) ? (int)$agreement_data['frequency'] : null;
        }
        if (isset($agreement_data['interval'])) {
            $interval = is_numeric($agreement_data['interval']) ? (int)$agreement_data['interval'] : null;
        }
        if ($frequency === null || $interval === null) {
            return '';
        }

        // Frequency words
        $frequencyWords = array(
            1 => 'Every',
            2 => 'Every Other',
            3 => 'Every Third',
            4 => 'Every Fourth',
            5 => 'Every Fifth',
            6 => 'Every Sixth',
            7 => 'Every Seventh'
        );
        $freqText = isset($frequencyWords[$frequency]) ? $frequencyWords[$frequency] : 'Every ' . $frequency . 'th';

        // Interval words (backend mapping 0=Day,1=Week,2=Month,3=Year)
        $intervalWords = array(
            0 => 'Day',
            1 => 'Week',
            2 => 'Month',
            3 => 'Year'
        );
        $intervalText = isset($intervalWords[$interval]) ? $intervalWords[$interval] : 'Month';

        return $freqText . ' ' . $intervalText;
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
        // Stamp local cancelled_at timestamp
        $order->update_meta_data('_coinsub_cancelled_at', current_time('mysql'));
        $order->add_order_note(__('Subscription cancelled by customer', 'coinsub'));
        $order->save();
        
        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'coinsub')));
    }
}

// Initialize
new CoinSub_Subscriptions();

