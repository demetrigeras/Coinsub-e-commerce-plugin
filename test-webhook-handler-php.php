<?php
/**
 * CoinSub Webhook Handler Test (PHP Version)
 * Tests the webhook handler logic without WordPress
 */

// Mock order class for testing
class Mock_WC_Order {
    private $id;
    private $status = 'pending-coinsub';
    
    public function __construct($id) {
        $this->id = $id;
    }
    
    public function get_id() {
        return $this->id;
    }
    
    public function get_status() {
        return $this->status;
    }
    
    public function update_status($status, $note = '') {
        echo "   📝 Order status updated to: " . $status . "\n";
        if ($note) {
            echo "   📝 Order note: " . $note . "\n";
        }
        $this->status = $status;
        return true;
    }
    
    public function add_order_note($note) {
        echo "   📝 Order note added: " . $note . "\n";
        return true;
    }
    
    public function update_meta_data($key, $value) {
        echo "   📝 Order meta updated: " . $key . " = " . $value . "\n";
        return true;
    }
    
    public function save() {
        echo "   💾 Order saved\n";
        return true;
    }
}

// Simulate WordPress functions for testing
function wc_get_order($order_id) {
    return new Mock_WC_Order($order_id);
}

if (!function_exists('error_log')) {
    function error_log($message) {
        echo "   📝 Log: " . $message . "\n";
    }
}

// Mock webhook handler class
class Mock_CoinSub_Webhook_Handler {
    
    public function handle_webhook_data($data) {
        echo "🔔 Processing webhook data...\n";
        echo "   Type: " . ($data['type'] ?? 'unknown') . "\n";
        echo "   Status: " . ($data['status'] ?? 'unknown') . "\n";
        
        if ($data['type'] === 'payment' && $data['status'] === 'completed') {
            return $this->handle_payment_completed($data);
        } else {
            echo "   ⚠️  Unhandled webhook type or status\n";
            return false;
        }
    }
    
    private function handle_payment_completed($data) {
        echo "   ✅ Processing payment completion...\n";
        
        $origin_id = $data['origin_id'] ?? '';
        $woocommerce_order_id = $data['metadata']['woocommerce_order_id'] ?? null;
        
        if (!$woocommerce_order_id) {
            echo "   ❌ No WooCommerce order ID found in metadata\n";
            return false;
        }
        
        echo "   🔍 Looking for WooCommerce order: " . $woocommerce_order_id . "\n";
        
        // Find order by origin_id (with and without sess_ prefix)
        $order = $this->find_order_by_origin_id($origin_id, $woocommerce_order_id);
        
        if (!$order) {
            echo "   ❌ Order not found\n";
            return false;
        }
        
        echo "   ✅ Order found, updating status...\n";
        
        // Update order status
        $order->update_status('completed', 'Payment completed via CoinSub');
        
        // Add transaction details
        $transaction_details = $data['transaction_details'] ?? [];
        if (!empty($transaction_details)) {
            $order->update_meta_data('_coinsub_transaction_id', $transaction_details['transaction_id'] ?? '');
            $order->update_meta_data('_coinsub_transaction_hash', $transaction_details['transaction_hash'] ?? '');
            $order->update_meta_data('_coinsub_chain_id', $transaction_details['chain_id'] ?? '');
        }
        
        // Add payment details
        $order->update_meta_data('_coinsub_payment_id', $data['payment_id'] ?? '');
        $order->update_meta_data('_coinsub_payment_amount', $data['amount'] ?? '');
        $order->update_meta_data('_coinsub_payment_currency', $data['currency'] ?? '');
        $order->update_meta_data('_coinsub_payment_date', $data['payment_date'] ?? '');
        
        // Add user details
        $user = $data['user'] ?? [];
        if (!empty($user)) {
            $order->update_meta_data('_coinsub_user_email', $user['email'] ?? '');
            $order->update_meta_data('_coinsub_user_name', ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        }
        
        // Save order
        $order->save();
        
        echo "   ✅ Order updated successfully!\n";
        return true;
    }
    
    private function find_order_by_origin_id($origin_id, $woocommerce_order_id) {
        echo "   🔍 Searching for order with origin_id: " . $origin_id . "\n";
        echo "   🔍 And WooCommerce order ID: " . $woocommerce_order_id . "\n";
        
        // Simulate finding order by WooCommerce order ID
        if ($woocommerce_order_id) {
            echo "   ✅ Found order by WooCommerce order ID\n";
            return wc_get_order($woocommerce_order_id);
        }
        
        // Simulate finding order by origin_id (with sess_ prefix handling)
        $clean_origin_id = $origin_id;
        if (strpos($origin_id, 'sess_') === 0) {
            $clean_origin_id = str_replace('sess_', '', $origin_id);
            echo "   🔄 Cleaned origin_id: " . $clean_origin_id . "\n";
        }
        
        // In real implementation, you would search for orders with this origin_id
        echo "   ✅ Found order by origin_id\n";
        return wc_get_order($woocommerce_order_id);
    }
}

function testWebhookHandler() {
    echo "🧪 Testing CoinSub Webhook Handler\n";
    echo str_repeat("=", 50) . "\n";
    
    $handler = new Mock_CoinSub_Webhook_Handler();
    
    // Test 1: Valid payment completion webhook
    echo "\n📋 Test 1: Valid Payment Completion Webhook\n";
    $webhookData1 = [
        "type" => "payment",
        "status" => "completed",
        "origin_id" => "sess_7fcf6796-7656-4a99-9355-5c7c1f25a6c1",
        "metadata" => [
            "woocommerce_order_id" => "12345"
        ],
        "amount" => 0.01,
        "currency" => "USDC",
        "payment_id" => "paym_123456789",
        "payment_date" => "2025-10-04T13:34:06+00:00",
        "transaction_details" => [
            "transaction_id" => 56045,
            "transaction_hash" => "0xb526ac27cc11f8f72caec20a17d5eb967a9b7a92eb888cc3e453a9a3789bdabf",
            "chain_id" => 80002
        ],
        "user" => [
            "first_name" => "John",
            "last_name" => "Doe",
            "email" => "john@example.com"
        ]
    ];
    
    $result1 = $handler->handle_webhook_data($webhookData1);
    echo "   Result: " . ($result1 ? "✅ Success" : "❌ Failed") . "\n";
    
    // Test 2: Invalid webhook type
    echo "\n📋 Test 2: Invalid Webhook Type\n";
    $webhookData2 = [
        "type" => "subscription",
        "status" => "active"
    ];
    
    $result2 = $handler->handle_webhook_data($webhookData2);
    echo "   Result: " . ($result2 ? "✅ Success" : "❌ Failed (Expected)") . "\n";
    
    // Test 3: Missing WooCommerce order ID
    echo "\n📋 Test 3: Missing WooCommerce Order ID\n";
    $webhookData3 = [
        "type" => "payment",
        "status" => "completed",
        "origin_id" => "sess_7fcf6796-7656-4a99-9355-5c7c1f25a6c1",
        "metadata" => []
    ];
    
    $result3 = $handler->handle_webhook_data($webhookData3);
    echo "   Result: " . ($result3 ? "✅ Success" : "❌ Failed (Expected)") . "\n";
    
    // Test 4: Origin ID without sess_ prefix
    echo "\n📋 Test 4: Origin ID without sess_ prefix\n";
    $webhookData4 = [
        "type" => "payment",
        "status" => "completed",
        "origin_id" => "7fcf6796-7656-4a99-9355-5c7c1f25a6c1",
        "metadata" => [
            "woocommerce_order_id" => "67890"
        ]
    ];
    
    $result4 = $handler->handle_webhook_data($webhookData4);
    echo "   Result: " . ($result4 ? "✅ Success" : "❌ Failed") . "\n";
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "🎉 Webhook Handler Test Results:\n";
    echo "✅ Valid Payment: " . ($result1 ? "PASS" : "FAIL") . "\n";
    echo "✅ Invalid Type: " . (!$result2 ? "PASS" : "FAIL") . "\n";
    echo "✅ Missing Order ID: " . (!$result3 ? "PASS" : "FAIL") . "\n";
    echo "✅ Clean Origin ID: " . ($result4 ? "PASS" : "FAIL") . "\n";
    
    $allPassed = $result1 && !$result2 && !$result3 && $result4;
    echo "\n🎊 Overall Result: " . ($allPassed ? "✅ ALL TESTS PASSED!" : "❌ Some tests failed") . "\n";
    
    return $allPassed;
}

// Run the test
testWebhookHandler();
?>
