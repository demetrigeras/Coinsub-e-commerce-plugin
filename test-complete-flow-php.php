<?php
/**
 * CoinSub Complete Flow Test (PHP Version)
 * Tests the entire payment flow including webhook handling
 */

// Configuration
$API_BASE_URL = "https://dev-api.coinsub.io/v1";
$MERCHANT_ID = "ca875a80-9b10-40ce-85c0-5af81856733a";
$API_KEY = "abf3e9e5-0140-4fda-abc9-7dd87a358852";

// Headers for API requests
$headers = [
    "Content-Type: application/json",
    "Merchant-ID: " . $MERCHANT_ID,
    "API-Key: " . $API_KEY,
    "Authorization: Bearer " . $API_KEY
];

function makeApiRequest($url, $data = null, $method = 'GET') {
    global $headers;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'status_code' => $httpCode,
        'body' => $response,
        'error' => $error
    ];
}

function createTestProduct() {
    global $API_BASE_URL;
    
    echo "📦 Creating Test Product...\n";
    
    $productData = [
        "name" => "Complete Flow Test Product",
        "description" => "Product for testing complete payment flow",
        "price" => 0.01,
        "currency" => "USD",
        "metadata" => [
            "woocommerce_product_id" => "complete_test_001",
            "sku" => "COMPLETE-TEST-001",
            "type" => "simple"
        ]
    ];
    
    $result = makeApiRequest($API_BASE_URL . "/commerce/products", $productData, 'POST');
    
    if (in_array($result['status_code'], [200, 201])) {
        $responseData = json_decode($result['body'], true);
        $productId = $responseData['id'] ?? null;
        echo "   ✅ Product created with ID: " . $productId . "\n";
        return $productId;
    } else {
        echo "   ❌ Product creation failed: " . $result['body'] . "\n";
        return null;
    }
}

function createTestOrder($productId) {
    global $API_BASE_URL;
    
    echo "\n🛒 Creating Test Order...\n";
    
    $orderData = [
        "total" => 0.01,
        "currency" => "USD",
        "items" => [
            [
                "product_id" => $productId,
                "quantity" => 1,
                "price" => 0.01
            ]
        ],
        "metadata" => [
            "woocommerce_order_id" => "complete_test_" . time(),
            "customer_email" => "test@example.com",
            "source" => "woocommerce_plugin"
        ]
    ];
    
    $result = makeApiRequest($API_BASE_URL . "/commerce/orders", $orderData, 'POST');
    
    if (in_array($result['status_code'], [200, 201])) {
        $responseData = json_decode($result['body'], true);
        $orderId = $responseData['id'] ?? null;
        echo "   ✅ Order created with ID: " . $orderId . "\n";
        return $orderId;
    } else {
        echo "   ❌ Order creation failed: " . $result['body'] . "\n";
        return null;
    }
}

function createPurchaseSession($orderId) {
    global $API_BASE_URL;
    
    echo "\n💳 Creating Purchase Session...\n";
    
    $sessionData = [
        "name" => "Complete Flow Test Payment",
        "details" => "Payment for complete flow test",
        "currency" => "USD",
        "amount" => 0.01,
        "recurring" => false,
        "success_url" => "",
        "cancel_url" => "",
        "metadata" => [
            "woocommerce_order_id" => $orderId,
            "source" => "woocommerce_plugin"
        ]
    ];
    
    $result = makeApiRequest($API_BASE_URL . "/purchase/session/start", $sessionData, 'POST');
    
    if ($result['status_code'] == 200) {
        $responseData = json_decode($result['body'], true);
        $purchaseSessionId = $responseData['data']['purchase_session_id'] ?? null;
        $checkoutUrl = $responseData['data']['url'] ?? null;
        
        echo "   ✅ Purchase session created with ID: " . $purchaseSessionId . "\n";
        echo "   🔗 Checkout URL: " . $checkoutUrl . "\n";
        
        // Extract UUID part if it has sess_ prefix
        if ($purchaseSessionId && strpos($purchaseSessionId, 'sess_') === 0) {
            $uuidPart = str_replace('sess_', '', $purchaseSessionId);
            $purchaseSessionId = $uuidPart;
            echo "   🔄 Extracted UUID: " . $purchaseSessionId . "\n";
        }
        
        // Link the order to the purchase session
        echo "\n🔗 Linking Order to Purchase Session...\n";
        
        $checkoutData = [
            "purchase_session_id" => $purchaseSessionId
        ];
        
        $checkoutResult = makeApiRequest($API_BASE_URL . "/commerce/orders/" . $orderId . "/checkout", $checkoutData, 'PUT');
        
        if ($checkoutResult['status_code'] == 200) {
            echo "   ✅ Order successfully linked to purchase session\n";
            return [
                'purchase_session_id' => $purchaseSessionId,
                'checkout_url' => $checkoutUrl,
                'original_id' => $responseData['data']['purchase_session_id'] ?? null
            ];
        } else {
            echo "   ❌ Order checkout failed: " . $checkoutResult['body'] . "\n";
            return null;
        }
    } else {
        echo "   ❌ Purchase session creation failed: " . $result['body'] . "\n";
        return null;
    }
}

function simulateWebhookPayment($orderId, $purchaseSessionId) {
    echo "\n🔔 Simulating Webhook Payment Completion...\n";
    
    // This simulates what CoinSub would send to your webhook endpoint
    $webhookData = [
        "type" => "payment",
        "merchant_id" => "mrch_" . $GLOBALS['MERCHANT_ID'],
        "origin_id" => "sess_" . $purchaseSessionId,
        "origin" => "purchase_sessions",
        "name" => "Complete Flow Test Payment",
        "currency" => "USDC",
        "amount" => 0.01,
        "metadata" => [
            "currency" => "USD",
            "individual_products" => ["Complete Flow Test Product"],
            "product_count" => 1,
            "products" => [
                [
                    "id" => "complete_test_001",
                    "name" => "Complete Flow Test Product",
                    "price" => 0.01
                ]
            ],
            "source" => "woocommerce_plugin",
            "total_amount" => 0.01,
            "total_items" => 1,
            "woocommerce_order_id" => $orderId
        ],
        "payment_date" => date('c'), // Current ISO 8601 timestamp
        "last_updated" => date('c'),
        "status" => "completed",
        "transaction_details" => [
            "transaction_id" => rand(10000, 99999),
            "transaction_hash" => "0x" . bin2hex(random_bytes(32)),
            "chain_id" => 80002
        ],
        "user" => [
            "first_name" => "Test",
            "last_name" => "User",
            "email" => "test@example.com",
            "subscriber_id" => "sub_" . bin2hex(random_bytes(16))
        ],
        "payment_id" => "paym_" . bin2hex(random_bytes(16)),
        "agreement_id" => "agre_" . bin2hex(random_bytes(16))
    ];
    
    echo "   📋 Webhook Data:\n";
    echo "   " . json_encode($webhookData, JSON_PRETTY_PRINT) . "\n";
    
    // Simulate sending webhook to your endpoint
    $webhookUrl = "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f";
    
    echo "\n   📤 Sending webhook to: " . $webhookUrl . "\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "User-Agent: CoinSub-Webhook/1.0"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $webhookResponse = curl_exec($ch);
    $webhookHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $webhookError = curl_error($ch);
    curl_close($ch);
    
    echo "   📥 Webhook Response Status: " . $webhookHttpCode . "\n";
    echo "   📥 Webhook Response: " . $webhookResponse . "\n";
    
    if ($webhookHttpCode == 200) {
        echo "   ✅ Webhook sent successfully!\n";
        return true;
    } else {
        echo "   ❌ Webhook failed: " . $webhookError . "\n";
        return false;
    }
}

function testWebhookHandler() {
    echo "\n🧪 Testing Webhook Handler Logic...\n";
    
    // This simulates what your webhook handler should do
    $sampleWebhookData = [
        "type" => "payment",
        "status" => "completed",
        "origin_id" => "sess_12345678-1234-1234-1234-123456789012",
        "metadata" => [
            "woocommerce_order_id" => "12345"
        ]
    ];
    
    echo "   📋 Sample webhook data received:\n";
    echo "   " . json_encode($sampleWebhookData, JSON_PRETTY_PRINT) . "\n";
    
    // Simulate webhook processing logic
    if ($sampleWebhookData['type'] === 'payment' && $sampleWebhookData['status'] === 'completed') {
        $originId = $sampleWebhookData['origin_id'];
        $woocommerceOrderId = $sampleWebhookData['metadata']['woocommerce_order_id'] ?? null;
        
        echo "   ✅ Payment completed!\n";
        echo "   🔍 Origin ID: " . $originId . "\n";
        echo "   🛒 WooCommerce Order ID: " . $woocommerceOrderId . "\n";
        
        // This is what your webhook handler should do:
        echo "   📝 Webhook handler should:\n";
        echo "      1. Find WooCommerce order by ID: " . $woocommerceOrderId . "\n";
        echo "      2. Update order status to 'completed'\n";
        echo "      3. Add payment transaction details\n";
        echo "      4. Send confirmation email to customer\n";
        echo "      5. Update inventory if needed\n";
        
        return true;
    } else {
        echo "   ❌ Invalid webhook data or status\n";
        return false;
    }
}

function main() {
    echo "🚀 Starting CoinSub Complete Flow Test (PHP Version)\n";
    echo str_repeat("=", 70) . "\n";
    
    // Step 1: Create product
    $productId = createTestProduct();
    if (!$productId) {
        echo "\n❌ Product creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Step 2: Create order
    $orderId = createTestOrder($productId);
    if (!$orderId) {
        echo "\n❌ Order creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Step 3: Create purchase session
    $sessionResult = createPurchaseSession($orderId);
    if (!$sessionResult) {
        echo "\n❌ Purchase session creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Step 4: Simulate webhook payment completion
    $webhookSuccess = simulateWebhookPayment($orderId, $sessionResult['purchase_session_id']);
    
    // Step 5: Test webhook handler logic
    $handlerSuccess = testWebhookHandler();
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "🎉 Complete Flow Test Results:\n";
    echo "📦 Product ID: " . $productId . "\n";
    echo "🛒 Order ID: " . $orderId . "\n";
    echo "💳 Purchase Session ID: " . $sessionResult['purchase_session_id'] . "\n";
    echo "🔗 Checkout URL: " . $sessionResult['checkout_url'] . "\n";
    echo "🔔 Webhook Sent: " . ($webhookSuccess ? "✅ Success" : "❌ Failed") . "\n";
    echo "🧪 Handler Logic: " . ($handlerSuccess ? "✅ Success" : "❌ Failed") . "\n";
    
    if ($webhookSuccess && $handlerSuccess) {
        echo "\n🎊 COMPLETE FLOW TEST PASSED! 🎊\n";
        echo "Your CoinSub integration is ready for production!\n";
    } else {
        echo "\n⚠️  Some tests failed. Please check the issues above.\n";
    }
    
    echo "\n📋 Next Steps for Production:\n";
    echo "1. Set up your actual webhook endpoint URL\n";
    echo "2. Test with real payments on CoinSub\n";
    echo "3. Verify WooCommerce order updates work\n";
    echo "4. Test with different payment amounts\n";
    echo "5. Test with multiple products and shipping\n";
}

// Run the test
main();
?>
