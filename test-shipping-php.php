<?php
/**
 * CoinSub API Test Script - Shipping Support (PHP Version)
 * Tests the CoinSub API with shipping calculations
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

function createProductWithShipping() {
    global $API_BASE_URL;
    
    echo "📦 Creating Product with Shipping...\n";
    
    $productData = [
        "name" => "Test Product with Shipping",
        "description" => "A test product to demonstrate shipping calculations",
        "price" => 0.01,
        "currency" => "USD",
        "metadata" => [
            "woocommerce_product_id" => "prod_shipping_001",
            "sku" => "SHIPPING-TEST-001",
            "type" => "simple",
            "weight" => "0.5", // 0.5 kg
            "dimensions" => "10x10x5" // cm
        ]
    ];
    
    $result = makeApiRequest($API_BASE_URL . "/commerce/products", $productData, 'POST');
    
    echo "   Status: " . $result['status_code'] . "\n";
    
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

function createOrderWithShipping($productId) {
    global $API_BASE_URL;
    
    echo "\n🛒 Creating Order with Shipping...\n";
    
    // Simulate WooCommerce order with shipping
    $orderData = [
        "total" => 0.06, // $0.01 product + $0.05 shipping
        "currency" => "USD",
        "items" => [
            [
                "product_id" => $productId,
                "quantity" => 1,
                "price" => 0.01
            ]
        ],
        "shipping_cost" => 0.05, // $0.05 shipping
        "tax_cost" => 0.00,
        "metadata" => [
            "woocommerce_order_id" => "wc_shipping_" . time(),
            "customer_email" => "test@example.com",
            "shipping_method" => "flat_rate",
            "shipping_address" => [
                "first_name" => "John",
                "last_name" => "Doe",
                "address_1" => "123 Main St",
                "city" => "New York",
                "state" => "NY",
                "postcode" => "10001",
                "country" => "US"
            ],
            "billing_address" => [
                "first_name" => "John",
                "last_name" => "Doe",
                "address_1" => "123 Main St",
                "city" => "New York",
                "state" => "NY",
                "postcode" => "10001",
                "country" => "US",
                "email" => "test@example.com",
                "phone" => "+1234567890"
            ]
        ]
    ];
    
    echo "   Product Price: $0.01\n";
    echo "   Shipping Cost: $0.05\n";
    echo "   Total: $0.06\n";
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

function createPurchaseSessionWithShipping($orderId) {
    global $API_BASE_URL;
    
    echo "\n💳 Creating Purchase Session with Shipping...\n";
    
    $sessionData = [
        "name" => "WooCommerce Order with Shipping",
        "details" => "Payment for WooCommerce order including shipping",
        "currency" => "USD",
        "amount" => 0.06, // Total including shipping
        "recurring" => false,
        "success_url" => "",
        "cancel_url" => "",
        "metadata" => [
            "woocommerce_order_id" => $orderId,
            "source" => "woocommerce_plugin",
            "currency" => "USD",
            "product_price" => 0.01,
            "shipping_cost" => 0.05,
            "total_amount" => 0.06,
            "shipping_method" => "flat_rate",
            "shipping_address" => [
                "first_name" => "John",
                "last_name" => "Doe",
                "address_1" => "123 Main St",
                "city" => "New York",
                "state" => "NY",
                "postcode" => "10001",
                "country" => "US"
            ],
            "billing_address" => [
                "first_name" => "John",
                "last_name" => "Doe",
                "address_1" => "123 Main St",
                "city" => "New York",
                "state" => "NY",
                "postcode" => "10001",
                "country" => "US",
                "email" => "test@example.com",
                "phone" => "+1234567890"
            ]
        ]
    ];
    
    echo "   Order Name: " . $sessionData['name'] . "\n";
    echo "   Product Price: $0.01\n";
    echo "   Shipping Cost: $0.05\n";
    echo "   Total Amount: $0.06\n";
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
        echo "\n🔗 Linking Order to Purchase Session...\n";
        
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

function main() {
    echo "🚀 Starting CoinSub Shipping Test (PHP Version)\n";
    echo str_repeat("=", 60) . "\n";
    
    // Create product
    $productId = createProductWithShipping();
    if (!$productId) {
        echo "\n❌ Product creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Create order with shipping
    $orderId = createOrderWithShipping($productId);
    if (!$orderId) {
        echo "\n❌ Order creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Create purchase session with shipping
    $sessionResult = createPurchaseSessionWithShipping($orderId);
    if (!$sessionResult) {
        echo "\n❌ Purchase session creation failed. Stopping test.\n";
        exit(1);
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🎉 Shipping Test Completed Successfully!\n";
    echo "📦 Product Price: $0.01\n";
    echo "🚚 Shipping Cost: $0.05\n";
    echo "💰 Total Amount: $0.06\n";
    echo "🛒 Order ID: " . $orderId . "\n";
    echo "💳 Purchase Session ID: " . $sessionResult['purchase_session_id'] . "\n";
    echo "🔗 Checkout URL: " . $sessionResult['checkout_url'] . "\n";
    echo "\n🌐 This URL will automatically open in the WordPress plugin!\n";
    echo $sessionResult['checkout_url'] . "\n";
}

// Run the test
main();
?>
