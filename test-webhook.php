<?php
/**
 * Test webhook endpoint
 * This simulates the webhook data you received
 */

// Simulate the webhook data you received
$webhook_data = [
    "type" => "payment",
    "merchant_id" => "mrch_ca875a80-9b10-40ce-85c0-5af81856733a",
    "origin_id" => "sess_0b3863d2-e5d5-4738-9135-a9a43c27e5b1",
    "origin" => "purchase_sessions",
    "name" => "WooCommerce Order: Premium T-Shirt + Wireless Headphones",
    "currency" => "USDC",
    "amount" => 0.03,
    "metadata" => [
        "currency" => "USD",
        "individual_products" => ["Premium T-Shirt", "Wireless Headphones"],
        "product_count" => 2,
        "products" => [
            [
                "id" => "bdca0f1f-6c14-4ffe-b8c7-b64f2ca7d94d",
                "name" => "Premium T-Shirt",
                "price" => 0.01
            ],
            [
                "id" => "bae95db1-46e1-4fd0-8f8b-a7c929e143ec",
                "name" => "Wireless Headphones",
                "price" => 0.02
            ]
        ],
        "source" => "woocommerce_plugin",
        "total_amount" => 0.03,
        "total_items" => 2,
        "woocommerce_order_id" => "c53e29df-6a01-4fd4-a697-98c398c143f9"
    ],
    "payment_date" => "2025-10-03T15:33:59.91439Z",
    "last_updated" => "2025-10-03T15:36:37.232596Z",
    "status" => "completed",
    "transaction_details" => [
        "transaction_id" => 14415,
        "transaction_hash" => "0x7a33e5708ea9dac1c9d26ca8cab586b7759fa7a1199f3918e6662524d45f336d",
        "chain_id" => 80002
    ],
    "user" => [
        "first_name" => "Testzsxdcfvgbhnj",
        "last_name" => "Testdcfvgbhnjmkl",
        "email" => "demetri+500@coinsub.io",
        "subscriber_id" => "2d8aa67e-add5-4b7f-902f-a6d09f23270f"
    ],
    "payment_id" => "paym_50fb734f-5325-4482-b9a9-463339817c23",
    "agreement_id" => "agre_36b3d327-78d6-4338-817b-2921275434de"
];

// Test the webhook endpoint
$webhook_url = "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f";

echo "🧪 Testing CoinSub Webhook\n";
echo "========================\n\n";

echo "📡 Webhook URL: $webhook_url\n";
echo "📦 Webhook Data:\n";
echo json_encode($webhook_data, JSON_PRETTY_PRINT) . "\n\n";

// Send the webhook data
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhook_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhook_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'User-Agent: CoinSub-Webhook-Test/1.0'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "📤 Webhook Response:\n";
echo "   Status Code: $http_code\n";
echo "   Response: $response\n";

if ($error) {
    echo "   Error: $error\n";
}

if ($http_code == 200) {
    echo "\n✅ Webhook test successful!\n";
} else {
    echo "\n❌ Webhook test failed!\n";
}

echo "\n🔍 Key Information:\n";
echo "   Origin ID: " . $webhook_data['origin_id'] . "\n";
echo "   Event Type: " . $webhook_data['type'] . "\n";
echo "   Status: " . $webhook_data['status'] . "\n";
echo "   Amount: " . $webhook_data['amount'] . " " . $webhook_data['currency'] . "\n";
echo "   WooCommerce Order ID: " . $webhook_data['metadata']['woocommerce_order_id'] . "\n";
echo "   Transaction ID: " . $webhook_data['transaction_details']['transaction_id'] . "\n";
echo "   Transaction Hash: " . $webhook_data['transaction_details']['transaction_hash'] . "\n";
?>
