<?php
/**
 * CoinSub API Test Script - PHP Version
 * Tests the CoinSub API endpoints for WooCommerce integration
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

function testApiConnection() {
    global $API_BASE_URL;
    
    echo "🔗 Testing API Connection...\n";
    
    $testData = [
        "name" => "API Connection Test",
        "price" => 1.00,
        "currency" => "USD"
    ];
    
    $result = makeApiRequest($API_BASE_URL . "/commerce/products", $testData, 'POST');
    
    echo "   Status: " . $result['status_code'] . "\n";
    
    if (in_array($result['status_code'], [200, 201])) {
        echo "   ✅ API Connection successful\n";
        return true;
    } else {
        echo "   ❌ API Connection failed: " . $result['body'] . "\n";
        return false;
    }
}

function createTestProduct() {
    global $API_BASE_URL;
    
    echo "\n📦 Creating Test Product...\n";
    
    $productData = [
        "name" => "Test WooCommerce Product",
        "description" => "A test product created from WooCommerce integration",
        "price" => 0.01,
        "currency" => "USD",
        "image_url" => "https://via.placeholder.com/300x300",
        "metadata" => [
            "woocommerce_product_id" => "12345",
            "sku" => "TEST-SKU-001",
            "type" => "simple"
        ]
    ];
    
    echo "   Sending data: " . json_encode($productData, JSON_PRETTY_PRINT) . "\n";
    
    $result = makeApiRequest($API_BASE_URL . "/commerce/products", $productData, 'POST');
    
    echo "   Status: " . $result['status_code'] . "\n";
    echo "   Response: " . $result['body'] . "\n";
    
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
    
    echo "\n🛒 Creating Test Order for Product " . $productId . "...\n";
    
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
            "woocommerce_order_id" => "67890",
            "customer_email" => "test@example.com"
        ]
    ];
    
    echo "   Sending data: " . json_encode($orderData, JSON_PRETTY_PRINT) . "\n";
    
    $result = makeApiRequest($API_BASE_URL . "/commerce/orders", $orderData, 'POST');
    
    echo "   Status: " . $result['status_code'] . "\n";
    echo "   Response: " . $result['body'] . "\n";
    
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
    
    echo "\n💳 Creating Purchase Session for Order " . $orderId . "...\n";
    
    $sessionData = [
        "name" => "WooCommerce Order Payment",
        "details" => "Payment for WooCommerce order",
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
    
    echo "   Sending data: " . json_encode($sessionData, JSON_PRETTY_PRINT) . "\n";
    
    $result = makeApiRequest($API_BASE_URL . "/purchase/session/start", $sessionData, 'POST');
    
    echo "   Status: " . $result['status_code'] . "\n";
    echo "   Response: " . $result['body'] . "\n";
    
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
        echo "\n🔗 Linking Order " . $orderId . " to Purchase Session " . $purchaseSessionId . "...\n";
        
        $checkoutData = [
            "purchase_session_id" => $purchaseSessionId
        ];
        
        $checkoutResult = makeApiRequest($API_BASE_URL . "/commerce/orders/" . $orderId . "/checkout", $checkoutData, 'PUT');
        
        echo "   Checkout Status: " . $checkoutResult['status_code'] . "\n";
        echo "   Checkout Response: " . $checkoutResult['body'] . "\n";
        
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

function testWebhookEndpoint() {
    echo "\n🔔 Testing webhook endpoint...\n";
    
    $webhookUrl = "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "   Status: " . $httpCode . "\n";
    
    if ($httpCode == 200) {
        echo "   ✅ Webhook endpoint accessible\n";
        return true;
    } else {
        echo "   ⚠️ Webhook endpoint returned status " . $httpCode . "\n";
        return false;
    }
}

function main() {
    echo "🚀 Starting CoinSub API Test (PHP Version)\n";
    echo str_repeat("=", 50) . "\n";
    
    // Validate credentials
    if ($GLOBALS['MERCHANT_ID'] === "your_merchant_id_here" || $GLOBALS['API_KEY'] === "your_api_key_here") {
        echo "❌ Please update MERCHANT_ID and API_KEY in the script\n";
        exit(1);
    }
    
    // Test API connection
    if (!testApiConnection()) {
        echo "\n❌ API connection failed. Please check your credentials and network.\n";
        exit(1);
    }
    
    // Create product
    $productId = createTestProduct();
    if (!$productId) {
        echo "\n❌ Product creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Create order
    $orderId = createTestOrder($productId);
    if (!$orderId) {
        echo "\n❌ Order creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Create purchase session
    $sessionResult = createPurchaseSession($orderId);
    if (!$sessionResult) {
        echo "\n❌ Purchase session creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Test webhook
    testWebhookEndpoint();
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "🎉 All tests completed!\n";
    echo "📦 Product ID: " . $productId . "\n";
    echo "🛒 Order ID: " . $orderId . "\n";
    echo "💳 Purchase Session ID: " . $sessionResult['purchase_session_id'] . "\n";
    echo "🔗 Checkout URL: " . $sessionResult['checkout_url'] . "\n";
    echo "🔔 Webhook URL: https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f\n";
}

// Run the test
main();
?>
