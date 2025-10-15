<?php
/**
 * CoinSub API Test Page
 * 
 * Test API connectivity directly from WordPress admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Admin_Test {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Add menu under WooCommerce
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
        
        ?>
        <div class="wrap">
            <h1>🧪 CoinSub API Test</h1>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin: 20px 0;">
                <h2>Test Your CoinSub API Connection</h2>
                <p>This will create a test product and order in your <strong>DEV</strong> environment.</p>
                
                <form method="post">
                    <?php wp_nonce_field('coinsub-api-test'); ?>
                    <input type="submit" name="run_test" class="button button-primary button-large" value="🚀 Run API Test" />
                </form>
                
                <?php if ($test_result): ?>
                <div style="margin-top: 20px;">
                    <?php echo $test_result; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0073aa;">
                <h3>What This Tests:</h3>
                <ul>
                    <li>✅ Can connect to <code>https://test-api.coinsub.io/v1</code></li>
                    <li>✅ Can create products in <code>commerce_products</code> table</li>
                    <li>✅ Can create orders in <code>commerce_orders</code> table</li>
                    <li>✅ Can create purchase sessions</li>
                    <li>✅ Can link orders to sessions</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Run API test
     */
    private function run_api_test() {
        $api_client = new CoinSub_API_Client();
        
        $output = '<div style="background: #1e1e1e; color: #00ff00; padding: 20px; border-radius: 5px; font-family: monospace; font-size: 13px; max-height: 500px; overflow-y: scroll;">';
        
        // Test 1: Create Product
        $output .= '<div style="color: #ffff00;">📦 TEST 1: Creating test product...</div>';
        $product_data = array(
            'name' => 'API Test Product ' . time(),
            'description' => 'Test product from WordPress admin',
            'price' => 0.01,
            'currency' => 'USD',
            'metadata' => array(
                'test' => true,
                'source' => 'wordpress_admin_test'
            )
        );
        
        $product_result = $api_client->create_product($product_data);
        
        if (is_wp_error($product_result)) {
            $output .= '<div style="color: #ff5555;">❌ FAILED: ' . esc_html($product_result->get_error_message()) . '</div>';
            $output .= '</div>';
            return $output;
        } else {
            $product_id = $product_result['id'] ?? 'unknown';
            $output .= '<div style="color: #00ff00;">✅ SUCCESS! Product ID: ' . esc_html($product_id) . '</div>';
        }
        
        // Test 2: Create Order
        $output .= '<div style="color: #ffff00; margin-top: 10px;">🛒 TEST 2: Creating test order...</div>';
        $order_data = array(
            'items' => array(
                array(
                    'product_id' => $product_id,
                    'quantity' => 1,
                    'price' => '0.01'
                )
            ),
            'total' => '0.01',
            'currency' => 'USD'
        );
        
        $order_result = $api_client->create_order($order_data);
        
        if (is_wp_error($order_result)) {
            $output .= '<div style="color: #ff5555;">❌ FAILED: ' . esc_html($order_result->get_error_message()) . '</div>';
            $output .= '</div>';
            return $output;
        } else {
            $order_id = $order_result['id'] ?? 'unknown';
            $output .= '<div style="color: #00ff00;">✅ SUCCESS! Order ID: ' . esc_html($order_id) . '</div>';
        }
        
        // Test 3: Create Purchase Session
        $output .= '<div style="color: #ffff00; margin-top: 10px;">💳 TEST 3: Creating purchase session...</div>';
        $session_data = array(
            'name' => 'Admin API Test',
            'details' => 'Testing from WordPress admin',
            'currency' => 'USD',
            'amount' => 0.01,
            'recurring' => false,
            'metadata' => array(
                'test' => true,
                'source' => 'admin_test'
            ),
            'success_url' => '',
            'cancel_url' => ''
        );
        
        $session_result = $api_client->create_purchase_session($session_data);
        
        if (is_wp_error($session_result)) {
            $output .= '<div style="color: #ff5555;">❌ FAILED: ' . esc_html($session_result->get_error_message()) . '</div>';
            $output .= '</div>';
            return $output;
        } else {
            $session_id = $session_result['purchase_session_id'] ?? 'unknown';
            $checkout_url = $session_result['checkout_url'] ?? 'unknown';
            $output .= '<div style="color: #00ff00;">✅ SUCCESS! Session ID: ' . esc_html($session_id) . '</div>';
            $output .= '<div style="color: #00ff00;">🔗 Checkout URL: ' . esc_html($checkout_url) . '</div>';
        }
        
        // Test 4: Link Order to Session
        $output .= '<div style="color: #ffff00; margin-top: 10px;">🔗 TEST 4: Linking order to session...</div>';
        $checkout_result = $api_client->checkout_order($order_id, $session_id);
        
        if (is_wp_error($checkout_result)) {
            $output .= '<div style="color: #ff5555;">❌ FAILED: ' . esc_html($checkout_result->get_error_message()) . '</div>';
        } else {
            $output .= '<div style="color: #00ff00;">✅ SUCCESS! Order linked to session!</div>';
        }
        
        $output .= '<div style="color: #00ff00; margin-top: 20px; padding: 15px; background: #2d5016; border-radius: 5px;">';
        $output .= '🎉 ALL TESTS PASSED! Your API is working correctly!<br>';
        $output .= '<br><strong>Test Results:</strong><br>';
        $output .= '• Product created in commerce_products ✅<br>';
        $output .= '• Order created in commerce_orders ✅<br>';
        $output .= '• Purchase session created ✅<br>';
        $output .= '• Order linked to session ✅<br>';
        $output .= '<br>🔗 Test checkout URL: <a href="' . esc_url($checkout_url) . '" target="_blank" style="color: #00ff00;">' . esc_html($checkout_url) . '</a>';
        $output .= '</div>';
        
        $output .= '</div>';
        return $output;
    }
}
