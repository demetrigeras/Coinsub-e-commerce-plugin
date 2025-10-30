<?php
/**
 * CoinSub Admin Payments Page
 * 
 * Merchant-facing payments management page in WooCommerce admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Admin_Payments {
    
    private $api_client;
    
    public function __construct() {
        // Add menu item to WooCommerce
        add_action('admin_menu', array($this, 'add_admin_menu'), 55);
        
        // Add admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // AJAX handler for payment details
        add_action('wp_ajax_coinsub_get_payment_details', array($this, 'ajax_get_payment_details'));
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
            __('Coinsub Payments', 'coinsub'),
            __('Coinsub Payments', 'coinsub'),
            'manage_woocommerce',
            'coinsub-payments',
            array($this, 'render_payments_page')
        );
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'woocommerce_page_coinsub-payments') {
            return;
        }
        
        wp_enqueue_style('coinsub-admin', plugins_url('../assets/admin.css', __FILE__), array(), '1.0.0');
    }
    
    /**
     * Render payments management page
     */
    public function render_payments_page() {
        // Get payments from API
        $api_client = $this->get_api_client();
        $payments_response = $api_client ? $api_client->get_all_payments() : null;
        
        // Log API response structure for debugging
        if ($payments_response && !is_wp_error($payments_response)) {
            error_log('🔍 Payments API response structure: ' . json_encode($payments_response));
        }
        
        $payments = array();
        if (!is_wp_error($payments_response) && isset($payments_response['data']) && is_array($payments_response['data'])) {
            $payments = $payments_response['data'];
        } elseif (!is_wp_error($payments_response) && is_array($payments_response)) {
            // Sometimes the API might return the array directly without 'data' wrapper
            $payments = $payments_response;
        }
        
        // Match payments with WooCommerce orders
        $payments_with_orders = $this->match_payments_with_orders($payments);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <img src="<?php echo esc_url(COINSUB_PLUGIN_URL . 'images/coinsub.png'); ?>" 
                     style="height: 30px; vertical-align: middle; margin-right: 10px;" 
                     alt="Coinsub" />
                <?php _e('Coinsub Payments', 'coinsub'); ?>
            </h1>
            
            <hr class="wp-header-end">
            
            <?php if (is_wp_error($payments_response)): ?>
                <div class="notice notice-error" style="margin: 20px 0;">
                    <p><strong><?php _e('Error loading payments:', 'coinsub'); ?></strong> <?php echo esc_html($payments_response->get_error_message()); ?></p>
                    <p><?php _e('Please check your API credentials in WooCommerce > Settings > Payments > CoinSub', 'coinsub'); ?></p>
                </div>
            <?php elseif (empty($payments_with_orders)): ?>
                <div class="notice notice-info" style="margin: 20px 0;">
                    <p><?php _e('No payments found.', 'coinsub'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th><?php _e('Order', 'coinsub'); ?></th>
                            <th><?php _e('Customer', 'coinsub'); ?></th>
                            <th><?php _e('Amount', 'coinsub'); ?></th>
                            <th><?php _e('Status', 'coinsub'); ?></th>
                            <th><?php _e('Transaction Hash', 'coinsub'); ?></th>
                            <th><?php _e('Date', 'coinsub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments_with_orders as $payment): ?>
                        <tr>
                            <td>
                                <?php if ($payment['order']): ?>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $payment['order']->get_id() . '&action=edit')); ?>">
                                        #<?php echo esc_html($payment['order']->get_order_number()); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($payment['order']): ?>
                                    <?php echo esc_html($payment['customer_name']); ?><br>
                                    <small><?php echo esc_html($payment['customer_email']); ?></small>
                                <?php else: ?>
                                    <?php echo esc_html($payment['customer_email'] ?? $payment['customer_name'] ?? '—'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $amount = isset($payment['amount']) ? (float)$payment['amount'] : 0;
                                $currency = isset($payment['currency']) ? $payment['currency'] : 'USD';
                                echo wc_price($amount, array('currency' => $currency)); 
                                ?>
                            </td>
                            <td>
                                <span class="payment-status status-<?php echo esc_attr(strtolower($payment['status'] ?? 'unknown')); ?>">
                                    <?php echo esc_html(ucfirst($payment['status'] ?? 'Unknown')); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($payment['transaction_hash'])): ?>
                                    <code style="font-size: 11px;"><?php echo esc_html(substr($payment['transaction_hash'], 0, 20)) . '...'; ?></code>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if (!empty($payment['created_at'])) {
                                    $ts = is_numeric($payment['created_at']) ? (int)$payment['created_at'] : strtotime($payment['created_at']);
                                    echo esc_html($ts ? date_i18n('Y-m-d h:i:s A', $ts) : $payment['created_at']);
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <style>
        .payment-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-completed, .status-success, .status-paid {
            background: #d4edda;
            color: #155724;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-failed, .status-error {
            background: #f8d7da;
            color: #721c24;
        }
        .status-unknown {
            background: #e2e3e5;
            color: #383d41;
        }
        
        </style>
        
        <?php
    }
    
    /**
     * Match payments from API with WooCommerce orders
     */
    private function match_payments_with_orders($payments) {
        $matched_payments = array();
        
        foreach ($payments as $payment) {
            $order = null;
            $customer_name = '';
            $customer_email = '';
            
            // Try to match by order ID in payment metadata
            if (isset($payment['metadata']) && isset($payment['metadata']['woocommerce_order_id'])) {
                $order_id = absint($payment['metadata']['woocommerce_order_id']);
                $order = wc_get_order($order_id);
            }
            
            // Try to match by purchase session ID
            if (!$order && isset($payment['purchase_session_id'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_coinsub_purchase_session_id',
                    'meta_value' => $payment['purchase_session_id'],
                    'limit' => 1
                ));
                
                if (!empty($orders)) {
                    $order = $orders[0];
                }
            }
            
            // Try to match by payment ID stored in order meta
            if (!$order && isset($payment['payment_id'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_coinsub_payment_id',
                    'meta_value' => $payment['payment_id'],
                    'limit' => 1
                ));
                
                if (!empty($orders)) {
                    $order = $orders[0];
                }
            }
            
            // Get customer info
            if ($order) {
                $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $customer_email = $order->get_billing_email();
            } elseif (isset($payment['customer_email'])) {
                $customer_email = $payment['customer_email'];
                $customer_name = $payment['customer_name'] ?? '';
            }
            
            // Extract created_at from payment - check multiple possible field names and locations
            $created_at = '';
            
            // Check direct field
            if (isset($payment['created_at']) && !empty($payment['created_at'])) {
                $created_at = $payment['created_at'];
            }
            // Check alternative field names
            elseif (isset($payment['createdAt']) && !empty($payment['createdAt'])) {
                $created_at = $payment['createdAt'];
            }
            elseif (isset($payment['date']) && !empty($payment['date'])) {
                $created_at = $payment['date'];
            }
            elseif (isset($payment['timestamp']) && !empty($payment['timestamp'])) {
                $created_at = $payment['timestamp'];
            }
            // Check if nested in data object
            elseif (isset($payment['data']['created_at']) && !empty($payment['data']['created_at'])) {
                $created_at = $payment['data']['created_at'];
            } elseif (isset($payment['data']['createdAt']) && !empty($payment['data']['createdAt'])) {
                $created_at = $payment['data']['createdAt'];
            } elseif (isset($payment['created']) && !empty($payment['created'])) { // some APIs use 'created'
                $created_at = $payment['created'];
            } elseif (isset($payment['attributes']['created_at']) && !empty($payment['attributes']['created_at'])) {
                $created_at = $payment['attributes']['created_at'];
            } elseif (isset($payment['attributes']['createdAt']) && !empty($payment['attributes']['createdAt'])) {
                $created_at = $payment['attributes']['createdAt'];
            }

            // Fallback to transaction_date variants if created_at not present
            if (empty($created_at)) {
                if (isset($payment['transaction_date']) && !empty($payment['transaction_date'])) {
                    $created_at = $payment['transaction_date'];
                } elseif (isset($payment['transactionDate']) && !empty($payment['transactionDate'])) {
                    $created_at = $payment['transactionDate'];
                } elseif (isset($payment['data']['transaction_date']) && !empty($payment['data']['transaction_date'])) {
                    $created_at = $payment['data']['transaction_date'];
                } elseif (isset($payment['attributes']['transaction_date']) && !empty($payment['attributes']['transaction_date'])) {
                    $created_at = $payment['attributes']['transaction_date'];
                }
            }
            
            // Log for debugging if still empty
            if (empty($created_at)) {
                error_log('🔍 Payment data keys: ' . json_encode(array_keys($payment)));
                error_log('🔍 Full payment data: ' . json_encode($payment));
            }
            
            // Normalize milliseconds timestamps to seconds if needed
            if (is_numeric($created_at)) {
                $created_num = (int)$created_at;
                if ($created_num > 20000000000) { // > year 2603 in seconds -> likely ms
                    $created_at = (int) floor($created_num / 1000);
                }
            }

            $matched_payments[] = array(
                'payment_id' => $payment['payment_id'] ?? $payment['id'] ?? '',
                'order' => $order,
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'amount' => $payment['amount'] ?? 0,
                'currency' => $payment['currency'] ?? 'USD',
                'status' => $payment['status'] ?? 'unknown',
                'transaction_hash' => $payment['transaction_hash'] ?? $payment['tx_hash'] ?? '',
                'created_at' => $created_at
            );
        }
        
        // Sort by date (newest first)
        usort($matched_payments, function($a, $b) {
            $date_a = !empty($a['created_at']) ? (is_numeric($a['created_at']) ? $a['created_at'] : strtotime($a['created_at'])) : 0;
            $date_b = !empty($b['created_at']) ? (is_numeric($b['created_at']) ? $b['created_at'] : strtotime($b['created_at'])) : 0;
            return $date_b - $date_a;
        });
        
        return $matched_payments;
    }
    
    /**
     * AJAX handler to get payment details
     */
    public function ajax_get_payment_details() {
        check_ajax_referer('coinsub_payment_details', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'coinsub')));
            return;
        }
        
        $payment_id = sanitize_text_field($_POST['payment_id']);
        
        $api_client = $this->get_api_client();
        if (!$api_client) {
            wp_send_json_error(array('message' => __('API client not available', 'coinsub')));
            return;
        }
        
        $result = $api_client->get_payment_details($payment_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Format payment details for display
        $payment_data = isset($result['data']) ? $result['data'] : $result;
        $html = $this->format_payment_details_html($payment_data);
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * Format payment details as HTML table
     */
    private function format_payment_details_html($payment_data) {
        $html = '<table>';
        
        $fields = array(
            'payment_id' => 'Payment ID',
            'id' => 'Payment ID',
            'amount' => 'Amount',
            'currency' => 'Currency',
            'status' => 'Status',
            'transaction_hash' => 'Transaction Hash',
            'tx_hash' => 'Transaction Hash',
            'chain_id' => 'Chain ID',
            'token' => 'Token',
            'customer_email' => 'Customer Email',
            'customer_wallet' => 'Customer Wallet',
            'created_at' => 'Created At',
            'date' => 'Date',
            'purchase_session_id' => 'Purchase Session ID'
        );
        
        foreach ($fields as $key => $label) {
            if (isset($payment_data[$key])) {
                $value = $payment_data[$key];
                
                // Format dates
                if (in_array($key, array('created_at', 'date')) && is_numeric($value)) {
                    $value = date('Y-m-d H:i:s', $value);
                }
                
                // Format amounts
                if ($key === 'amount' && is_numeric($value)) {
                    $currency = isset($payment_data['currency']) ? $payment_data['currency'] : 'USD';
                    $value = wc_price($value, array('currency' => $currency));
                }
                
                $html .= '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($value) . '</td></tr>';
            }
        }
        
        // Add metadata if present
        if (isset($payment_data['metadata']) && is_array($payment_data['metadata'])) {
            $html .= '<tr><td colspan="2"><strong>Metadata:</strong></td></tr>';
            foreach ($payment_data['metadata'] as $key => $value) {
                $html .= '<tr><td style="padding-left: 20px;">' . esc_html($key) . '</td><td>' . esc_html(is_array($value) ? json_encode($value) : $value) . '</td></tr>';
            }
        }
        
        $html .= '</table>';
        
        return $html;
    }
}

// Initialize
new CoinSub_Admin_Payments();

