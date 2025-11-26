<?php
/**
 * CoinSub Admin Subscriptions Page
 * 
 * Merchant-facing subscriptions management page in WooCommerce admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Admin_Subscriptions {
    
    private $api_client;
    
    public function __construct() {
        // Add menu item to WooCommerce
        add_action('admin_menu', array($this, 'add_admin_menu'), 56);
        
        // Handle subscription cancellation
        add_action('wp_ajax_coinsub_admin_cancel_subscription_bulk', array($this, 'ajax_cancel_subscription'));
        
        // Add admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }
    
    /**
     * Get API client instance
     */
    private function get_api_client() {
        if ($this->api_client === null) {
            if (!class_exists('CoinSub_API_Client')) {
                require_once plugin_dir_path(__FILE__) . 'class-coinsub-api-client.php';
            }
            $this->api_client = new CoinSub_API_Client();
        }
        return $this->api_client;
    }
    
    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Coinsub Subscriptions', 'coinsub'),
            __('Coinsub Subscriptions', 'coinsub'),
            'manage_woocommerce',
            'coinsub-subscriptions',
            array($this, 'render_subscriptions_page')
        );
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'woocommerce_page_coinsub-subscriptions') {
            return;
        }
        
        wp_enqueue_style('coinsub-admin', plugins_url('../assets/admin.css', __FILE__), array(), '1.0.0');
    }
    
    /**
     * Render subscriptions management page
     */
    public function render_subscriptions_page() {
        // Get all subscription orders
        $subscriptions = $this->get_all_subscriptions();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <img src="<?php echo esc_url(COINSUB_PLUGIN_URL . 'images/coinsub.svg'); ?>" 
                     style="height: 30px; vertical-align: middle; margin-right: 10px;" 
                     alt="Stablecoin Pay" />
                <?php _e('Coinsub Subscriptions', 'coinsub'); ?>
            </h1>
            
            <hr class="wp-header-end">
            
            <?php if (empty($subscriptions)): ?>
                <div class="notice notice-info" style="margin: 20px 0;">
                    <p><?php _e('No subscriptions found. Subscriptions will appear here after customers complete subscription payments.', 'coinsub'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th><?php _e('Order', 'coinsub'); ?></th>
                            <th><?php _e('Customer', 'coinsub'); ?></th>
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
                        <?php foreach ($subscriptions as $sub): ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(admin_url('post.php?post=' . $sub['order_id'] . '&action=edit')); ?>">
                                    #<?php echo esc_html($sub['order_number']); ?>
                                </a>
                            </td>
                            <td>
                                <?php echo esc_html($sub['customer_name']); ?><br>
                                <small><?php echo esc_html($sub['customer_email']); ?></small>
                            </td>
                            <td><?php echo esc_html($sub['product_name']); ?></td>
                            <td><?php echo wc_price($sub['amount']); ?></td>
                            <td><?php echo esc_html($sub['frequency_text']); ?></td>
                            <td><?php echo esc_html($sub['created_at'] ?? '—'); ?></td>
                            <td><?php echo esc_html($sub['next_processing'] ?? '—'); ?></td>
                            <td><?php echo esc_html($sub['cancelled_at'] ?? '—'); ?></td>
                            <td>
                                <span class="subscription-status <?php echo esc_attr($sub['status_class']); ?>">
                                    <?php echo esc_html($sub['status_text']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($sub['status'] === 'active'): ?>
                                    <button class="button button-small coinsub-cancel-sub" 
                                            data-order-id="<?php echo esc_attr($sub['order_id']); ?>"
                                            data-agreement-id="<?php echo esc_attr($sub['agreement_id']); ?>">
                                        <?php _e('Cancel', 'coinsub'); ?>
                                    </button>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <style>
        .subscription-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.coinsub-cancel-sub').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('<?php _e('Are you sure you want to cancel this subscription? This cannot be undone.', 'coinsub'); ?>')) {
                    return;
                }
                
                var button = $(this);
                var orderId = button.data('order-id');
                var agreementId = button.data('agreement-id');
                
                button.prop('disabled', true).text('<?php _e('Cancelling...', 'coinsub'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'coinsub_admin_cancel_subscription_bulk',
                        order_id: orderId,
                        agreement_id: agreementId,
                        nonce: '<?php echo wp_create_nonce('coinsub_admin_cancel_bulk'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php _e('Subscription cancelled successfully', 'coinsub'); ?>');
                            location.reload();
                        } else {
                            alert(response.data.message || 'Failed to cancel subscription');
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
        <?php
    }
    
    /**
     * Get all subscriptions for the merchant
     */
    private function get_all_subscriptions() {
        $subscriptions = array();
        
        // Get all orders with Coinsub payment method that are subscriptions
        $orders = wc_get_orders(array(
            'payment_method' => 'coinsub',
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        foreach ($orders as $order) {
            // Check if this is a subscription order
            $is_subscription = $order->get_meta('_coinsub_is_subscription');
            
            if ($is_subscription !== 'yes') {
                continue; // Not a subscription
            }
            
            // Get agreement ID (set by webhook after payment)
            $agreement_id = $order->get_meta('_coinsub_agreement_id');
            
            if (empty($agreement_id)) {
                continue; // Subscription product but no agreement yet (payment not complete)
            }
            
            $subscription_status = $order->get_meta('_coinsub_subscription_status');
            $status = ($subscription_status === 'cancelled') ? 'cancelled' : 'active';
            
            // Fetch agreement details from API to get dates
            $agreement_data = null;
            $created_at = $order->get_date_created()->date('Y-m-d H:i:s');
            $next_processing = '';
            $cancelled_at = '';
            
            $api_client = $this->get_api_client();
            if ($api_client) {
                $agreement_response = $api_client->retrieve_agreement($agreement_id);
                if (!is_wp_error($agreement_response)) {
                    $agreement_data = isset($agreement_response['data']) ? $agreement_response['data'] : $agreement_response;
                    
                    // Log for debugging
                    error_log('🔍 Agreement data for ' . $agreement_id . ': ' . json_encode($agreement_data));
                    
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
                        $next_processing = $this->format_date($agreement_data['next_process_date']);
                    } elseif (isset($agreement_data['next_processing'])) {
                        $next_processing = $this->format_date($agreement_data['next_processing']);
                    } elseif (isset($agreement_data['nextProcessDate'])) {
                        $next_processing = $this->format_date($agreement_data['nextProcessDate']);
                    } elseif (isset($agreement_data['nextProcess'])) {
                        $next_processing = $this->format_date($agreement_data['nextProcess']);
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
                } else {
                    error_log('❌ Error retrieving agreement: ' . $agreement_response->get_error_message());
                }
            }
            
            $subscriptions[] = array(
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'agreement_id' => $agreement_id,
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'product_name' => $this->get_subscription_product_name($order),
                'amount' => $order->get_total(),
                'frequency_text' => isset($frequency_text_override) ? $frequency_text_override : $this->get_subscription_frequency_text($order),
                'status' => $status,
                'status_text' => ucfirst($status),
                'status_class' => 'status-' . $status,
                'created_at' => $created_at,
                'next_processing' => $next_processing ?: '—',
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
            '0' => 'Day',
            '1' => 'Week',
            '2' => 'Month',
            '3' => 'Year',
        );
        
        // Get subscription data from order items
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_coinsub_subscription') === 'yes') {
                $frequency = $product->get_meta('_coinsub_frequency');
                $interval = $product->get_meta('_coinsub_interval');
                $duration = $product->get_meta('_coinsub_duration');
                
                $frequency_text = isset($frequency_map[$frequency]) ? $frequency_map[$frequency] : 'Every';
                $interval_text = isset($interval_map[$interval]) ? $interval_map[$interval] : 'Month';
                
                $result = $frequency_text . ' ' . $interval_text;
                
                // Add duration if set
                if (!empty($duration) && $duration !== '0') {
                    $result .= ' (' . $duration . ' payments)';
                } else {
                    $result .= ' (Until Cancelled)';
                }
                
                return $result;
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
        check_ajax_referer('coinsub_admin_cancel_bulk', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'coinsub')));
            return;
        }
        
        $agreement_id = sanitize_text_field($_POST['agreement_id']);
        $order_id = absint($_POST['order_id']);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order', 'coinsub')));
            return;
        }
        
        // Call Coinsub API to cancel
        $api_client = $this->get_api_client();
        if (!$api_client) {
            wp_send_json_error(array('message' => __('API client not available', 'coinsub')));
            return;
        }
        
        $result = $api_client->cancel_agreement($agreement_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Update order meta
        $order->update_meta_data('_coinsub_subscription_status', 'cancelled');
        // Stamp local cancelled_at timestamp
        $order->update_meta_data('_coinsub_cancelled_at', current_time('mysql'));
        $order->add_order_note(__('Subscription cancelled by merchant from Subscriptions page', 'coinsub'));
        $order->save();
        
        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'coinsub')));
    }
}

// Initialize
new CoinSub_Admin_Subscriptions();

