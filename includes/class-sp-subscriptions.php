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
        
        // Orders list: no Subscription column; subscription details + cancel only on the single order (view-order) page
        add_action('woocommerce_order_details_after_order_table', array($this, 'view_order_subscription_section'), 10, 1);
        add_action('wp_footer', array($this, 'coinsub_cancel_script'));
        // Only for CoinSub: hide on-hold rows; show "Completed" instead of "Processing" for customers
        add_filter('woocommerce_my_account_my_orders_query', array($this, 'my_account_orders_query_passthrough'));
        add_action('woocommerce_my_account_my_orders_column_order-total', array($this, 'orders_list_mark_coinsub'), 20, 1);
        add_action('wp_footer', array($this, 'my_account_hide_coinsub_on_hold_rows'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'view_order_show_completed_for_coinsub'), 5, 1);
        
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
        
        $name = class_exists('CoinSub_Whitelabel_Branding') ? CoinSub_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        $sub_label = $name ? sprintf(__('%s Subscription', 'coinsub'), $name) : __('Subscription', 'coinsub');
        woocommerce_wp_checkbox(array(
            'id' => '_coinsub_subscription',
            'label' => $sub_label,
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
     * Do not change the orders query. On-hold hiding for CoinSub only is done in my_account_hide_coinsub_on_hold_rows (JS)
     * so other payment methods (Visa, etc.) are not affected.
     *
     * @param array $args Query args for wc_get_orders
     * @return array
     */
    public function my_account_orders_query_passthrough($args) {
        return $args;
    }

    /**
     * Append hidden marker in orders list for CoinSub orders (so JS can show "Completed" instead of "Processing").
     */
    public function orders_list_mark_coinsub($order) {
        if (!$order || $order->get_payment_method() !== 'coinsub') {
            return;
        }
        echo '<span class="coinsub-order" style="display:none;"></span>';
    }

    /**
     * On view-order page: for CoinSub orders with status Processing, show "Completed" to the customer.
     */
    public function view_order_show_completed_for_coinsub($order) {
        if (is_admin() || !is_account_page()) {
            return;
        }
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        if (!$order || !$order instanceof WC_Order || $order->get_payment_method() !== 'coinsub' || $order->get_status() !== 'processing') {
            return;
        }
        ?>
        <script>
        (function() {
            var label = <?php echo json_encode(esc_html__('Completed', 'coinsub')); ?>;
            function run() {
                document.querySelectorAll('.order-status.status-processing, mark.status-processing').forEach(function(el) {
                    if (/Processing/.test(el.textContent)) el.textContent = label;
                });
            }
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
            else run();
        })();
        </script>
        <?php
    }

    /**
     * On My Account > Orders: hide only rows for CoinSub orders that are on-hold (awaiting payment).
     * Also show "Completed" instead of "Processing" for CoinSub paid orders (customer view only).
     */
    public function my_account_hide_coinsub_on_hold_rows() {
        if (!is_account_page() || !is_wc_endpoint_url('orders')) {
            return;
        }
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }
        $order_ids = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => 'on-hold',
            'payment_method' => 'coinsub',
            'return' => 'ids',
            'limit' => -1,
        ));
        $order_ids = array_map('intval', (array) $order_ids);
        $completed_label = esc_js(__('Completed', 'coinsub'));
        ?>
        <script>
        (function() {
            var hideOrderIds = <?php echo json_encode(array_values($order_ids)); ?>;
            var completedLabel = <?php echo json_encode($completed_label); ?>;
            function run() {
                var table = document.querySelector('.woocommerce-orders-table, .shop_table.my_account_orders');
                if (!table) return;
                var rows = table.querySelectorAll('tbody tr');
                rows.forEach(function(tr) {
                    var link = tr.querySelector('a[href*="view-order"]');
                    if (!link || !link.href) return;
                    var m = link.href.match(/view-order\/(\d+)/);
                    if (!m) return;
                    var id = parseInt(m[1], 10);
                    if (hideOrderIds.indexOf(id) !== -1) {
                        tr.style.display = 'none';
                        return;
                    }
                    if (tr.querySelector('.coinsub-order')) {
                        var statusEl = tr.querySelector('.order-status.status-processing');
                        if (statusEl && /Processing/.test(statusEl.textContent)) statusEl.textContent = completedLabel;
                    }
                });
            }
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
            else run();
        })();
        </script>
        <?php
    }
    
    /**
     * On single order page (view-order): show subscription details in a row + Cancel button (CoinSub subscription orders only).
     *
     * @param WC_Order $order
     */
    public function view_order_subscription_section($order) {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        if (!$order || !$order instanceof WC_Order || $order->get_payment_method() !== 'coinsub') {
            return;
        }
        $is_subscription = $order->get_meta('_coinsub_is_subscription');
        $agreement_id = $order->get_meta('_coinsub_agreement_id');
        if ($is_subscription !== 'yes' || empty($agreement_id)) {
            return;
        }
        $status = $order->get_meta('_coinsub_subscription_status');
        $frequency_text = $this->get_subscription_frequency_text($order);
        $duration_text = $this->get_subscription_duration_text($order);
        $duration_raw = $this->get_subscription_duration_raw($order);
        $start_date = $order->get_date_created() ? $order->get_date_created()->date_i18n(wc_date_format()) : '—';
        $next_payment = $order->get_meta('_coinsub_next_payment');
        if (empty($next_payment)) {
            // Same source as merchant: fetch from agreement API (retrieve_agreement) and save to order meta
            $api_client = $this->get_api_client();
            if ($api_client) {
                $agreement_response = $api_client->retrieve_agreement($agreement_id);
                if (!is_wp_error($agreement_response)) {
                    $agreement_data = isset($agreement_response['data']) ? $agreement_response['data'] : $agreement_response;
                    $next_payment_raw = $this->get_next_payment_from_agreement_data($agreement_data);
                    if (!empty($next_payment_raw)) {
                        $order->update_meta_data('_coinsub_next_payment', $next_payment_raw);
                        $order->save();
                        $next_payment = $this->format_date($next_payment_raw);
                    }
                }
            }
            if (empty($next_payment)) {
                $next_payment = '—';
            }
        } else {
            $next_payment = $this->format_date($next_payment);
        }
        if (empty($duration_raw) || $duration_raw === '0') {
            $regularity_text = $frequency_text;
        } else {
            $regularity_text = $frequency_text . ' ' . sprintf(__('for %s', 'coinsub'), $duration_text);
        }
        ?>
        <section class="coinsub-subscription-details" style="margin: 1.5em 0; padding: 1em 1.25em; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
            <h3 style="margin: 0 0 1em; font-size: 1em;"><?php esc_html_e('Subscription', 'coinsub'); ?></h3>
            <div class="coinsub-subscription-fields" style="display: flex; flex-wrap: wrap; gap: 1.5em 2em;">
                <div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e('Start date', 'coinsub'); ?></div>
                    <div><?php echo esc_html($start_date); ?></div>
                </div>
                <div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e('Next payment', 'coinsub'); ?></div>
                    <div><?php echo esc_html($next_payment); ?></div>
                </div>
                <div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e('Regularity', 'coinsub'); ?></div>
                    <div><?php echo esc_html($regularity_text); ?></div>
                </div>
                <?php if ($status !== 'cancelled') : ?>
                    <div style="align-self: flex-end; margin-left: auto;">
                        <button type="button" class="button coinsub-cancel-subscription" data-agreement-id="<?php echo esc_attr($agreement_id); ?>" data-order-id="<?php echo esc_attr($order->get_id()); ?>"><?php esc_html_e('Cancel subscription', 'coinsub'); ?></button>
                    </div>
                <?php else : ?>
                    <div style="align-self: flex-end; margin-left: auto; color: #6c757d;"><em><?php esc_html_e('Cancelled', 'coinsub'); ?></em></div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Cancel-subscription script (runs on My Account so Cancel works on view-order page).
     */
    public function coinsub_cancel_script() {
        if (!is_account_page()) {
            return;
        }
        $nonce = wp_create_nonce('coinsub_cancel_subscription');
        ?>
        <script>
        jQuery(function($) {
            $(document.body).on('click', '.coinsub-cancel-subscription', function(e) {
                e.preventDefault();
                if (!confirm('<?php echo esc_js(__('Are you sure you want to cancel this subscription?', 'coinsub')); ?>')) {
                    return;
                }
                var btn = $(this);
                btn.prop('disabled', true).text('<?php echo esc_js(__('Cancelling...', 'coinsub')); ?>');
                $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    action: 'coinsub_cancel_subscription',
                    agreement_id: btn.data('agreement-id'),
                    order_id: btn.data('order-id'),
                    nonce: '<?php echo esc_js($nonce); ?>'
                }).done(function(res) {
                    if (res.success) {
                        alert('<?php echo esc_js(__('Subscription cancelled successfully', 'coinsub')); ?>');
                        location.reload();
                    } else {
                        alert(res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error cancelling subscription', 'coinsub')); ?>');
                        btn.prop('disabled', false).text('<?php echo esc_js(__('Cancel subscription', 'coinsub')); ?>');
                    }
                }).fail(function() {
                    alert('<?php echo esc_js(__('Error cancelling subscription', 'coinsub')); ?>');
                    btn.prop('disabled', false).text('<?php echo esc_js(__('Cancel subscription', 'coinsub')); ?>');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get raw subscription duration from order ('0' = until cancelled, or number of payments).
     */
    private function get_subscription_duration_raw($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_coinsub_subscription') === 'yes') {
                $duration = $product->get_meta('_coinsub_duration');
                return $duration === '' ? '0' : (string) $duration;
            }
        }
        return '0';
    }

    /**
     * Get subscription duration text from order (e.g. "12 payments" or "Until cancelled")
     */
    private function get_subscription_duration_text($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_coinsub_subscription') === 'yes') {
                $duration = $product->get_meta('_coinsub_duration');
                if (empty($duration) || $duration === '0') {
                    return __('Until cancelled', 'coinsub');
                }
                return sprintf(_n('%s payment', '%s payments', (int) $duration, 'coinsub'), (int) $duration);
            }
        }
        return '';
    }
    
    /**
     * Extract next payment date from agreement API data.
     * API returns e.g. next_process_date (ISO string) or next_payment_date (timestamp).
     *
     * @param array $agreement_data Agreement data from retrieve_agreement (or nested under 'data').
     * @return string Raw value (timestamp or date string) or empty string.
     */
    private function get_next_payment_from_agreement_data($agreement_data) {
        if (!is_array($agreement_data) || empty($agreement_data)) {
            return '';
        }
        foreach (array('next_process_date', 'next_payment_date') as $key) {
            if (isset($agreement_data[$key]) && $agreement_data[$key] !== '' && $agreement_data[$key] !== null) {
                return $agreement_data[$key];
            }
        }
        return '';
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

