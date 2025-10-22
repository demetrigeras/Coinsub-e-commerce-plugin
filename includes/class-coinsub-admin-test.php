<?php
/**
 * CoinSub Admin Test Page
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Admin_Test {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'CoinSub API Test',
            'CoinSub API Test',
            'manage_woocommerce',
            'coinsub-api-test',
            array($this, 'display_test_page')
        );
        
        add_submenu_page(
            'woocommerce',
            'CoinSub Email Status',
            'CoinSub Email Status',
            'manage_woocommerce',
            'coinsub-email-status',
            array($this, 'display_email_status_page')
        );
    }
    
    /**
     * Display API test page
     */
    public function display_test_page() {
        // Handle test action
        $test_result = null;
        if (isset($_POST['run_test']) && check_admin_referer('coinsub-api-test')) {
            $test_result = $this->run_api_test();
        }
        
        if (isset($_POST['test_email']) && check_admin_referer('coinsub-api-test')) {
            $test_result = $this->test_email_system();
        }
        
        ?>
        <div class="wrap">
            <h1>🧪 CoinSub API Test</h1>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin: 20px 0;">
                <h2>Test Your CoinSub API Connection</h2>
                <p>This will create a test product and order in your <strong>DEV</strong> environment.</p>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('coinsub-api-test'); ?>
                    <input type="submit" name="run_test" class="button button-primary button-large" value="🚀 Run API Test" />
                </form>
                
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('coinsub-api-test'); ?>
                    <input type="submit" name="test_email" class="button button-secondary button-large" value="📧 Test Email System" />
                </form>
                
                <?php if ($test_result): ?>
                <div style="margin-top: 20px;">
                    <?php echo $test_result; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Run API test
     */
    private function run_api_test() {
        $output = '<div style="background: #1e1e1e; color: #00ff00; padding: 20px; border-radius: 5px; font-family: monospace; font-size: 13px; max-height: 500px; overflow-y: scroll;">';
        
        $output .= '<div style="color: #ffff00;">🚀 RUNNING COINSUB API TEST...</div>';
        
        // Test API connection
        $api_client = new CoinSub_API_Client();
        $test_result = $api_client->test_connection();
        
        if ($test_result) {
            $output .= '<div style="color: #00ff00;">✅ API Connection: SUCCESS</div>';
            
            // Create test product
            $product_data = array(
                'name' => 'Test Product - ' . current_time('Y-m-d H:i:s'),
                'price' => 0.01,
                'description' => 'Test product created by CoinSub plugin',
                'type' => 'simple'
            );
            
            $product_result = $api_client->create_test_product($product_data);
            
            if ($product_result && isset($product_result['id'])) {
                $output .= '<div style="color: #00ff00;">✅ Test Product Created: ID ' . $product_result['id'] . '</div>';
                
                // Create test order
                $order_data = array(
                    'product_id' => $product_result['id'],
                    'quantity' => 1,
                    'customer_email' => 'test@example.com'
                );
                
                $order_result = $api_client->create_test_order($order_data);
                
                if ($order_result && isset($order_result['id'])) {
                    $output .= '<div style="color: #00ff00;">✅ Test Order Created: ID ' . $order_result['id'] . '</div>';
                    
                    // Generate checkout URL
                    $checkout_url = $api_client->get_checkout_url($order_result['id']);
                    if ($checkout_url) {
                        $output .= '<div style="color: #00ff00;">✅ Checkout URL Generated</div>';
                        $output .= '<br>🔗 Test checkout URL: <a href="' . esc_url($checkout_url) . '" target="_blank" style="color: #00ff00;">' . esc_html($checkout_url) . '</a>';
                    }
                } else {
                    $output .= '<div style="color: #ff5555;">❌ Failed to create test order</div>';
                }
            } else {
                $output .= '<div style="color: #ff5555;">❌ Failed to create test product</div>';
            }
        } else {
            $output .= '<div style="color: #ff5555;">❌ API Connection: FAILED</div>';
            $output .= '<div style="color: #ffaa00;">Check your API credentials in WooCommerce > Settings > Payments > Coinsub</div>';
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Test email system
     */
    private function test_email_system() {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $output = '<div style="background: #1e1e1e; color: #00ff00; padding: 20px; border-radius: 5px; font-family: monospace; font-size: 13px; max-height: 500px; overflow-y: scroll;">';
        
        if (empty($admin_email)) {
            $output .= '<div style="color: #ff5555;">❌ FAILED: No admin email configured</div>';
            $output .= '</div>';
            return $output;
        }
        
        $output .= '<div style="color: #ffff00;">📧 TESTING EMAIL SYSTEM...</div>';
        $output .= '<div style="color: #00ff00;">Admin Email: ' . esc_html($admin_email) . '</div>';
        $output .= '<div style="color: #00ff00;">Site Name: ' . esc_html($site_name) . '</div>';
        
        $subject = '[CoinSub Test] Email System Test';
        $message = "This is a test email from CoinSub plugin.\n\n";
        $message .= "Site: " . $site_name . "\n";
        $message .= "Admin Email: " . $admin_email . "\n";
        $message .= "Time: " . current_time('mysql') . "\n\n";
        $message .= "If you receive this email, the email system is working correctly.";
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $admin_email,
        );
        
        error_log('📧 CoinSub Test: Sending test email to: ' . $admin_email);
        
        // Test the robust email system
        $order_manager = new CoinSub_Order_Manager();
        $result = $this->test_robust_email_system($admin_email, $subject, $message);
        
        if ($result) {
            $output .= '<div style="color: #00ff00; margin-top: 10px;">✅ SUCCESS! Test email sent to ' . esc_html($admin_email) . '</div>';
            $output .= '<div style="color: #00ff00;">Check your email inbox for the test message.</div>';
        } else {
            $output .= '<div style="color: #ff5555; margin-top: 10px;">❌ FAILED: Could not send email</div>';
            $output .= '<div style="color: #ffaa00;">This might be due to server email configuration or credits.</div>';
            $output .= '<div style="color: #ffaa00;">Check the debug log for detailed error information.</div>';
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Test the robust email system
     */
    private function test_robust_email_system($to, $subject, $message) {
        // Test wp_mail first
        $result = wp_mail($to, $subject, $message, array(
            'Content-Type' => 'text/plain; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email'),
        ));
        
        if ($result) {
            error_log('📧 CoinSub Test: wp_mail succeeded');
            return true;
        }
        
        // Test alternative method
        error_log('📧 CoinSub Test: wp_mail failed, trying alternative method');
        
        if (function_exists('mail')) {
            $from_email = get_option('admin_email');
            $site_name = get_bloginfo('name');
            
            $headers = "From: {$site_name} <{$from_email}>\r\n";
            $headers .= "Reply-To: {$from_email}\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            $result = @mail($to, $subject, $message, $headers);
            
            if ($result) {
                error_log('📧 CoinSub Test: Alternative mail() method succeeded');
                return true;
            } else {
                error_log('📧 CoinSub Test: Alternative mail() method also failed');
            }
        }
        
        error_log('📧 CoinSub Test: All email methods failed');
        return false;
    }
    
    /**
     * Display email status page
     */
    public function display_email_status_page() {
        $failed_emails = get_option('coinsub_failed_emails', array());
        $log_file = WP_CONTENT_DIR . '/coinsub-email-failures.log';
        $log_file_exists = file_exists($log_file);
        $log_content = $log_file_exists ? file_get_contents($log_file) : '';
        
        ?>
        <div class="wrap">
            <h1>📧 CoinSub Email Status</h1>
            
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2>📊 Email Statistics</h2>
                    <p><strong>Failed Emails (Last 10):</strong> <?php echo count($failed_emails); ?></p>
                    <p><strong>Log File Status:</strong> 
                        <?php if ($log_file_exists): ?>
                            <span style="color: green;">✅ Exists</span>
                        <?php else: ?>
                            <span style="color: red;">❌ Not Found</span>
                        <?php endif; ?>
                    </p>
                    <p><strong>Log File Size:</strong> 
                        <?php echo $log_file_exists ? size_format(filesize($log_file)) : 'N/A'; ?>
                    </p>
                </div>
                
                <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2>🔧 Server Mail Configuration</h2>
                    <p><strong>mail() Function:</strong> 
                        <?php echo function_exists('mail') ? '<span style="color: green;">✅ Available</span>' : '<span style="color: red;">❌ Not Available</span>'; ?>
                    </p>
                    <p><strong>Sendmail Path:</strong> <?php echo ini_get('sendmail_path') ?: 'Not Set'; ?></p>
                    <p><strong>SMTP Host:</strong> <?php echo ini_get('SMTP') ?: 'Not Set'; ?></p>
                    <p><strong>SMTP Port:</strong> <?php echo ini_get('smtp_port') ?: 'Not Set'; ?></p>
                </div>
            </div>
            
            <?php if (!empty($failed_emails)): ?>
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px;">
                <h2>📋 Recent Failed Emails</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Order ID</th>
                            <th>Message Preview</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($failed_emails) as $email): ?>
                        <tr>
                            <td><?php echo esc_html($email['timestamp']); ?></td>
                            <td><?php echo esc_html($email['to']); ?></td>
                            <td><?php echo esc_html($email['subject']); ?></td>
                            <td><?php echo esc_html($email['order_id'] ?: 'N/A'); ?></td>
                            <td><?php echo esc_html(substr($email['message'], 0, 100) . '...'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if ($log_file_exists && !empty($log_content)): ?>
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2>📄 Email Failure Log</h2>
                <div style="background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto;">
                    <pre><?php echo esc_html($log_content); ?></pre>
                </div>
            </div>
            <?php endif; ?>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-top: 20px;">
                <h2>🛠️ Troubleshooting</h2>
                <p><strong>If emails are failing, try these solutions:</strong></p>
                <ul>
                    <li>Contact your hosting provider to enable the mail() function</li>
                    <li>Configure SMTP settings in wp-config.php or use an SMTP plugin</li>
                    <li>Check if your server has sendmail or postfix installed</li>
                    <li>Verify that your domain has proper SPF/DKIM records</li>
                </ul>
                
                <p><strong>Quick Test:</strong></p>
                <form method="post">
                    <?php wp_nonce_field('coinsub-api-test'); ?>
                    <input type="submit" name="test_email" class="button button-primary" value="📧 Test Email System" />
                </form>
            </div>
        </div>
        <?php
    }
}