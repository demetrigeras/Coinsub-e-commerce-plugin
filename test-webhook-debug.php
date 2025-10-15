<?php
/**
 * CoinSub Webhook Debug Test
 * 
 * This script helps debug webhook issues by testing the webhook endpoint
 * and checking recent orders.
 */

// Include WordPress
require_once('../../../wp-load.php');

echo "=== CoinSub Webhook Debug Test ===\n\n";

// Test 1: Check if webhook endpoint is accessible
echo "1. Testing webhook endpoint accessibility...\n";
$test_url = home_url('/wp-json/coinsub/v1/webhook/test');
$response = wp_remote_get($test_url);

if (is_wp_error($response)) {
    echo "❌ Error: " . $response->get_error_message() . "\n";
} else {
    $body = wp_remote_retrieve_body($response);
    $status = wp_remote_retrieve_response_code($response);
    echo "✅ Status: $status\n";
    echo "Response: $body\n";
}

echo "\n";

// Test 2: Check recent orders
echo "2. Checking recent CoinSub orders...\n";
$orders = wc_get_orders(array(
    'limit' => 5,
    'orderby' => 'date',
    'order' => 'DESC',
    'meta_query' => array(
        array(
            'key' => '_payment_method',
            'value' => 'coinsub',
            'compare' => '='
        )
    )
));

echo "Found " . count($orders) . " CoinSub orders:\n";
foreach ($orders as $order) {
    echo "- Order #" . $order->get_id() . " - Status: " . $order->get_status() . " - Total: $" . $order->get_total() . "\n";
    echo "  - CoinSub Order ID: " . $order->get_meta('_coinsub_order_id') . "\n";
    echo "  - Purchase Session ID: " . $order->get_meta('_coinsub_purchase_session_id') . "\n";
    echo "  - Redirect Flag: " . $order->get_meta('_coinsub_redirect_to_received') . "\n";
    echo "  - Payment ID: " . $order->get_meta('_coinsub_payment_id') . "\n";
    echo "\n";
}

echo "\n";

// Test 3: Simulate webhook call
echo "3. Simulating webhook call...\n";
$webhook_data = array(
    'type' => 'payment',
    'origin_id' => 'sess_test_123',
    'merchant_id' => 'mrch_4901980e-73e3-4b7e-a22f-2dd18cfa1285',
    'payment_id' => 'paym_test_123',
    'transaction_details' => array(
        'transaction_id' => 'txn_test_123',
        'transaction_hash' => '0x1234567890abcdef',
        'chain_id' => '1'
    )
);

// Create a test order first
$test_order = wc_create_order();
$test_order->set_payment_method('coinsub');
$test_order->set_payment_method_title('CoinSub');
$test_order->update_meta_data('_coinsub_purchase_session_id', 'sess_test_123');
$test_order->update_meta_data('_coinsub_merchant_id', '4901980e-73e3-4b7e-a22f-2dd18cfa1285');
$test_order->save();

echo "Created test order #" . $test_order->get_id() . "\n";

// Now test the webhook handler
$webhook_handler = new CoinSub_Webhook_Handler();
$request = new WP_REST_Request('POST', '/coinsub/v1/webhook');
$request->set_body(json_encode($webhook_data));
$request->set_header('content-type', 'application/json');

echo "Calling webhook handler...\n";
$response = $webhook_handler->handle_webhook($request);
echo "Response status: " . $response->get_status() . "\n";
echo "Response data: " . json_encode($response->get_data()) . "\n";

// Check if the test order was updated
$test_order = wc_get_order($test_order->get_id());
echo "Test order status after webhook: " . $test_order->get_status() . "\n";
echo "Redirect flag: " . $test_order->get_meta('_coinsub_redirect_to_received') . "\n";

echo "\n=== Test Complete ===\n";
?>
