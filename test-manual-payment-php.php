<?php
/**
 * CoinSub Manual Payment Test (PHP Version)
 * Creates checkout URL for manual payment testing
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
        "name" => "Manual Payment Test Product",
        "description" => "Product for manual payment testing with shipping and tax",
        "price" => 0.01,
        "currency" => "USD",
        "metadata" => [
            "woocommerce_product_id" => "manual_test_001",
            "sku" => "MANUAL-TEST-001",
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
    
    echo "\n🛒 Creating Test Order with Shipping & Tax...\n";
    
    $orderData = [
        "total" => 0.08, // $0.01 product + $0.05 shipping + $0.02 tax
        "currency" => "USD",
        "items" => [
            [
                "product_id" => $productId,
                "quantity" => 1,
                "price" => 0.01
            ]
        ],
        "shipping_cost" => 0.05,
        "tax_cost" => 0.02,
        "metadata" => [
            "woocommerce_order_id" => "manual_test_" . time(),
            "customer_email" => "test@example.com",
            "shipping_method" => "flat_rate",
            "shipping_address" => [
                "first_name" => "Test",
                "last_name" => "Customer",
                "address_1" => "123 Test Street",
                "city" => "Test City",
                "state" => "TS",
                "postcode" => "12345",
                "country" => "US"
            ],
            "billing_address" => [
                "first_name" => "Test",
                "last_name" => "Customer",
                "address_1" => "123 Test Street",
                "city" => "Test City",
                "state" => "TS",
                "postcode" => "12345",
                "country" => "US",
                "email" => "test@example.com",
                "phone" => "+1234567890"
            ]
        ]
    ];
    
    echo "   📊 Order Breakdown:\n";
    echo "      Product: $0.01\n";
    echo "      Shipping: $0.05\n";
    echo "      Tax: $0.02\n";
    echo "      Total: $0.08\n";
    
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
    
    echo "\n💳 Creating Purchase Session (All-in-Crypto Configuration)...\n";
    
    // All-in-crypto configuration: include both shipping and tax
    $include_shipping = true;
    $include_tax = true;
    
    $product_subtotal = 0.01;
    $shipping_total = 0.05;
    $tax_total = 0.02;
    
    // Calculate crypto payment amount
    $crypto_amount = $product_subtotal;
    if ($include_shipping) {
        $crypto_amount += $shipping_total;
    }
    if ($include_tax) {
        $crypto_amount += $tax_total;
    }
    
    echo "   💰 Payment Configuration:\n";
    echo "      Include Shipping in Crypto: " . ($include_shipping ? "✅ Yes" : "❌ No") . "\n";
    echo "      Include Tax in Crypto: " . ($include_tax ? "✅ Yes" : "❌ No") . "\n";
    echo "      Crypto Payment Amount: $" . number_format($crypto_amount, 2) . "\n";
    
    $sessionData = [
        "name" => "Manual Payment Test - All-in-Crypto",
        "details" => "Manual payment test with shipping and tax included in crypto",
        "currency" => "USD",
        "amount" => $crypto_amount,
        "recurring" => false,
        "success_url" => "",
        "cancel_url" => "",
        "metadata" => [
            "woocommerce_order_id" => $orderId,
            "source" => "woocommerce_plugin",
            "payment_breakdown" => [
                "product_subtotal" => $product_subtotal,
                "shipping_total" => $shipping_total,
                "tax_total" => $tax_total,
                "crypto_payment_amount" => $crypto_amount,
                "shipping_included_in_crypto" => $include_shipping,
                "tax_included_in_crypto" => $include_tax,
                "shipping_payment_required" => false,
                "tax_payment_required" => false,
                "shipping_payment_method" => "merchant_covered",
                "tax_payment_method" => "merchant_covered",
            ],
            "shipping_address" => [
                "first_name" => "Test",
                "last_name" => "Customer",
                "address_1" => "123 Test Street",
                "city" => "Test City",
                "state" => "TS",
                "postcode" => "12345",
                "country" => "US"
            ],
            "billing_address" => [
                "first_name" => "Test",
                "last_name" => "Customer",
                "address_1" => "123 Test Street",
                "city" => "Test City",
                "state" => "TS",
                "postcode" => "12345",
                "country" => "US",
                "email" => "test@example.com",
                "phone" => "+1234567890"
            ]
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
                'original_id' => $responseData['data']['purchase_session_id'] ?? null,
                'order_id' => $orderId
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
    echo "🚀 Starting CoinSub Manual Payment Test\n";
    echo str_repeat("=", 70) . "\n";
    echo "This will create a real checkout URL for you to test manually.\n";
    echo "You'll complete the actual payment on CoinSub dev environment.\n";
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
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "🎉 CHECKOUT URL READY FOR MANUAL TESTING!\n";
    echo str_repeat("=", 70) . "\n";
    echo "📦 Product ID: " . $productId . "\n";
    echo "🛒 Order ID: " . $sessionResult['order_id'] . "\n";
    echo "💳 Purchase Session ID: " . $sessionResult['purchase_session_id'] . "\n";
    echo "💰 Amount to Pay: $0.08 (includes product + shipping + tax)\n";
    echo "\n🔗 CHECKOUT URL:\n";
    echo $sessionResult['checkout_url'] . "\n";
    echo "\n📝 Instructions:\n";
    echo "1. Copy the checkout URL above\n";
    echo "2. Open it in your browser\n";
    echo "3. Complete the payment process on CoinSub dev\n";
    echo "4. Check if the webhook is received at:\n";
    echo "   https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f\n";
    echo "\n🔔 Webhook URL for CoinSub:\n";
    echo "https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f\n";
    echo "\n✅ Ready for manual testing!\n";
}

// Run the test
main();
?>
