<?php
/**
 * CoinSub API Test Script - Multiple Products (PHP Version)
 * Tests the CoinSub API with multiple products in a single order
 */

// Configuration
$API_BASE_URL = "https://test-api.coinsub.io/v1";
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

function createMultipleProducts() {
    global $API_BASE_URL;
    
    echo "📦 Creating Multiple Test Products...\n";
    
    $products = [
        [
            "name" => "Premium T-Shirt",
            "description" => "High-quality cotton t-shirt",
            "price" => 0.03,
            "currency" => "USD",
            "metadata" => [
                "woocommerce_product_id" => "prod_001",
                "sku" => "TSHIRT-PREM-001",
                "type" => "simple"
            ]
        ],
        [
            "name" => "Wireless Headphones",
            "description" => "Bluetooth wireless headphones with noise cancellation",
            "price" => 0.02,
            "currency" => "USD",
            "metadata" => [
                "woocommerce_product_id" => "prod_002",
                "sku" => "HEADPHONES-WIRE-002",
                "type" => "simple"
            ]
        ]
    ];
    
    $createdProducts = [];
    
    foreach ($products as $index => $productData) {
        echo "\n   Creating product " . ($index + 1) . ": " . $productData['name'] . "...\n";
        
        $result = makeApiRequest($API_BASE_URL . "/commerce/products", $productData, 'POST');
        
        echo "   Status: " . $result['status_code'] . "\n";
        
        if (in_array($result['status_code'], [200, 201])) {
            $responseData = json_decode($result['body'], true);
            $productId = $responseData['id'] ?? null;
            echo "   ✅ Product created with ID: " . $productId . "\n";
            
            $createdProducts[] = [
                'id' => $productId,
                'name' => $productData['name'],
                'price' => $productData['price']
            ];
        } else {
            echo "   ❌ Product creation failed: " . $result['body'] . "\n";
            return null;
        }
    }
    
    return $createdProducts;
}

function createMultiProductOrder($products) {
    global $API_BASE_URL;
    
    echo "\n🛒 Creating Multi-Product Order...\n";
    
    // Calculate total
    $total = 0;
    $items = [];
    $productNames = [];
    
    foreach ($products as $product) {
        $total += $product['price'];
        $items[] = [
            "product_id" => $product['id'],
            "quantity" => 1,
            "price" => $product['price']
        ];
        $productNames[] = $product['name'];
    }
    
    $orderData = [
        "total" => $total,
        "currency" => "USD",
        "items" => $items,
        "metadata" => [
            "woocommerce_order_id" => "wc_multi_" . time(),
            "customer_email" => "test@example.com",
            "individual_products" => $productNames,
            "product_count" => count($products),
            "products" => array_map(function($p) {
                return [
                    "id" => $p['id'],
                    "name" => $p['name'],
                    "price" => $p['price']
                ];
            }, $products)
        ]
    ];
    
    echo "   Total: $" . number_format($total, 2) . "\n";
    echo "   Products: " . implode(", ", $productNames) . "\n";
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

function createMultiProductPurchaseSession($orderId, $products) {
    global $API_BASE_URL;
    
    echo "\n💳 Creating Multi-Product Purchase Session...\n";
    
    // Calculate total and prepare metadata
    $total = 0;
    $productNames = [];
    $productDetails = [];
    
    foreach ($products as $product) {
        $total += $product['price'];
        $productNames[] = $product['name'];
        $productDetails[] = [
            "id" => $product['id'],
            "name" => $product['name'],
            "price" => $product['price']
        ];
    }
    
    // Calculate breakdown with shipping and taxes
    $subtotal = $total;
    $shippingCost = 0.005; // $0.005 USD shipping
    $taxRate = 0.0825; // 8.25% tax rate
    $taxAmount = round($subtotal * $taxRate, 4);
    $grandTotal = $subtotal + $shippingCost + $taxAmount;
    
    $sessionData = [
        "name" => "WooCommerce Order: " . implode(" + ", $productNames),
        "details" => "Payment for WooCommerce order with " . count($products) . " products | Shipping: $" . number_format($shippingCost, 4) . " | Tax: $" . number_format($taxAmount, 4),
        "currency" => "USD",
        "amount" => $grandTotal, // Total including products + shipping + tax
        "recurring" => false,
        "success_url" => "",
        "cancel_url" => "",
        "metadata" => [
            "woocommerce_order_id" => $orderId,
            "source" => "woocommerce_plugin",
            "currency" => "USD",
            "individual_products" => $productNames,
            "product_count" => count($products),
            "products" => $productDetails,
            "order_breakdown" => [
                "subtotal" => $subtotal,
                "shipping" => [
                    "method" => "Standard Shipping",
                    "cost" => $shippingCost
                ],
                "tax" => [
                    "rate" => ($taxRate * 100) . "%",
                    "amount" => $taxAmount
                ],
                "total" => $grandTotal
            ],
            "total_amount" => $grandTotal,
            "subtotal_amount" => $subtotal,
            "shipping_cost" => $shippingCost,
            "tax_amount" => $taxAmount,
            "total_items" => count($products)
        ]
    ];
    
    echo "   Order Name: " . $sessionData['name'] . "\n";
    echo "   Product Subtotal: $" . number_format($subtotal, 4) . "\n";
    echo "   Shipping Cost: $" . number_format($shippingCost, 4) . "\n";
    echo "   Tax Amount: $" . number_format($taxAmount, 4) . "\n";
    echo "   Grand Total: $" . number_format($grandTotal, 4) . "\n";
    echo "   Product Count: " . count($products) . "\n";
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
        echo "\n🔗 Linking Multi-Product Order to Purchase Session...\n";
        
        $checkoutData = [
            "purchase_session_id" => $purchaseSessionId
        ];
        
        $checkoutResult = makeApiRequest($API_BASE_URL . "/commerce/orders/" . $orderId . "/checkout", $checkoutData, 'PUT');
        
        echo "   Checkout Status: " . $checkoutResult['status_code'] . "\n";
        echo "   Checkout Response: " . $checkoutResult['body'] . "\n";
        
        if ($checkoutResult['status_code'] == 200) {
            echo "   ✅ Multi-product order successfully linked to purchase session\n";
            
            // Calculate totals for return
            global $sessionData;
            
            return [
                'purchase_session_id' => $purchaseSessionId,
                'checkout_url' => $checkoutUrl,
                'original_id' => $responseData['data']['purchase_session_id'] ?? null,
                'subtotal' => $total,
                'shipping' => 0.005,
                'tax' => round($total * 0.0825, 4),
                'total' => $total + 0.005 + round($total * 0.0825, 4),
                'product_count' => count($products)
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
    echo "🚀 Starting CoinSub Multi-Product API Test (PHP Version)\n";
    echo str_repeat("=", 60) . "\n";
    
    // Create multiple products
    $products = createMultipleProducts();
    if (!$products) {
        echo "\n❌ Product creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Create multi-product order
    $orderId = createMultiProductOrder($products);
    if (!$orderId) {
        echo "\n❌ Order creation failed. Stopping test.\n";
        exit(1);
    }
    
    // Create purchase session for multi-product order
    $sessionResult = createMultiProductPurchaseSession($orderId, $products);
    if (!$sessionResult) {
        echo "\n❌ Purchase session creation failed. Stopping test.\n";
        exit(1);
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🎉 Multi-Product Test Completed Successfully!\n";
    echo "📦 Products Created: " . $sessionResult['product_count'] . "\n";
    echo "💵 Product Subtotal: $" . number_format($sessionResult['subtotal'], 4) . "\n";
    echo "📦 Shipping Cost: $" . number_format($sessionResult['shipping'], 4) . "\n";
    echo "💸 Tax Amount: $" . number_format($sessionResult['tax'], 4) . "\n";
    echo "💰 Grand Total: $" . number_format($sessionResult['total'], 4) . "\n";
    echo "🛒 Order ID: " . $orderId . "\n";
    echo "💳 Purchase Session ID: " . $sessionResult['purchase_session_id'] . "\n";
    echo "🔗 Checkout URL: " . $sessionResult['checkout_url'] . "\n";
    echo "\n🌐 Open this URL to test the checkout flow:\n";
    echo $sessionResult['checkout_url'] . "\n";
    echo "\n🔔 Webhook URL: https://webhook-test.com/bce9a8b61c28d115aa796fe270d40e9f\n";
}

// Run the test
main();
?>
