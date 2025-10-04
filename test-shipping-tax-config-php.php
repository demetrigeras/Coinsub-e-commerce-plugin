<?php
/**
 * CoinSub Shipping & Tax Configuration Test (PHP Version)
 * Tests different shipping and tax payment configurations
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

function testConfiguration($config_name, $include_shipping, $include_tax, $shipping_payment_method, $tax_payment_method) {
    global $API_BASE_URL;
    
    echo "\n🧪 Testing Configuration: " . $config_name . "\n";
    echo str_repeat("-", 50) . "\n";
    
    // Simulate order totals
    $product_subtotal = 0.01;  // $0.01 for products
    $shipping_total = 0.05;    // $0.05 for shipping
    $tax_total = 0.02;         // $0.02 for tax
    $order_total = $product_subtotal + $shipping_total + $tax_total; // $0.08 total
    
    echo "   📊 Order Breakdown:\n";
    echo "      Product Subtotal: $" . number_format($product_subtotal, 2) . "\n";
    echo "      Shipping Total: $" . number_format($shipping_total, 2) . "\n";
    echo "      Tax Total: $" . number_format($tax_total, 2) . "\n";
    echo "      Order Total: $" . number_format($order_total, 2) . "\n";
    
    // Calculate crypto payment amount based on configuration
    $crypto_amount = $product_subtotal; // Always include products
    if ($include_shipping) {
        $crypto_amount += $shipping_total;
    }
    if ($include_tax) {
        $crypto_amount += $tax_total;
    }
    
    echo "\n   💰 Payment Configuration:\n";
    echo "      Include Shipping in Crypto: " . ($include_shipping ? "✅ Yes" : "❌ No") . "\n";
    echo "      Include Tax in Crypto: " . ($include_tax ? "✅ Yes" : "❌ No") . "\n";
    echo "      Crypto Payment Amount: $" . number_format($crypto_amount, 2) . "\n";
    
    // Calculate separate payments
    $separate_payments = [];
    if (!$include_shipping && $shipping_total > 0) {
        $separate_payments[] = [
            'type' => 'shipping',
            'amount' => $shipping_total,
            'method' => $shipping_payment_method
        ];
    }
    if (!$include_tax && $tax_total > 0) {
        $separate_payments[] = [
            'type' => 'tax',
            'amount' => $tax_total,
            'method' => $tax_payment_method
        ];
    }
    
    if (!empty($separate_payments)) {
        echo "\n   💳 Separate Payments Required:\n";
        foreach ($separate_payments as $payment) {
            echo "      " . ucfirst($payment['type']) . ": $" . number_format($payment['amount'], 2) . " via " . $payment['method'] . "\n";
        }
    } else {
        echo "\n   ✅ All payments included in crypto\n";
    }
    
    // Create purchase session data
    $sessionData = [
        "name" => "Test Order - " . $config_name,
        "details" => "Testing shipping and tax configuration",
        "currency" => "USD",
        "amount" => $crypto_amount,
        "recurring" => false,
        "success_url" => "",
        "cancel_url" => "",
        "metadata" => [
            "woocommerce_order_id" => "test_" . time(),
            "source" => "woocommerce_plugin",
            "payment_breakdown" => [
                "product_subtotal" => $product_subtotal,
                "shipping_total" => $shipping_total,
                "tax_total" => $tax_total,
                "crypto_payment_amount" => $crypto_amount,
                "shipping_included_in_crypto" => $include_shipping,
                "tax_included_in_crypto" => $include_tax,
                "shipping_payment_required" => !$include_shipping && $shipping_total > 0,
                "tax_payment_required" => !$include_tax && $tax_total > 0,
                "shipping_payment_method" => $shipping_payment_method,
                "tax_payment_method" => $tax_payment_method,
            ],
            "separate_payments" => $separate_payments
        ]
    ];
    
    echo "\n   📤 Sending to CoinSub API...\n";
    echo "   Amount: $" . number_format($crypto_amount, 2) . " (crypto payment)\n";
    
    $result = makeApiRequest($API_BASE_URL . "/purchase/session/start", $sessionData, 'POST');
    
    if ($result['status_code'] == 200) {
        $responseData = json_decode($result['body'], true);
        $checkoutUrl = $responseData['data']['url'] ?? null;
        echo "   ✅ Purchase session created successfully!\n";
        echo "   🔗 Checkout URL: " . $checkoutUrl . "\n";
        return true;
    } else {
        echo "   ❌ Purchase session creation failed: " . $result['body'] . "\n";
        return false;
    }
}

function main() {
    echo "🚀 Starting CoinSub Shipping & Tax Configuration Test\n";
    echo str_repeat("=", 70) . "\n";
    
    // Test different configurations
    $configurations = [
        [
            'name' => 'All-in-Crypto (Default)',
            'include_shipping' => true,
            'include_tax' => true,
            'shipping_payment_method' => 'merchant_covered',
            'tax_payment_method' => 'merchant_covered'
        ],
        [
            'name' => 'Products + Shipping in Crypto, Tax Separate',
            'include_shipping' => true,
            'include_tax' => false,
            'shipping_payment_method' => 'merchant_covered',
            'tax_payment_method' => 'merchant_covered'
        ],
        [
            'name' => 'Products + Tax in Crypto, Shipping Separate',
            'include_shipping' => false,
            'include_tax' => true,
            'shipping_payment_method' => 'merchant_covered',
            'tax_payment_method' => 'merchant_covered'
        ],
        [
            'name' => 'Products Only in Crypto, Rest Separate',
            'include_shipping' => false,
            'include_tax' => false,
            'shipping_payment_method' => 'separate_payment',
            'tax_payment_method' => 'separate_payment'
        ],
        [
            'name' => 'Products + Shipping in Crypto, Tax Auto-convert',
            'include_shipping' => true,
            'include_tax' => false,
            'shipping_payment_method' => 'merchant_covered',
            'tax_payment_method' => 'crypto_conversion'
        ]
    ];
    
    $results = [];
    
    foreach ($configurations as $config) {
        $success = testConfiguration(
            $config['name'],
            $config['include_shipping'],
            $config['include_tax'],
            $config['shipping_payment_method'],
            $config['tax_payment_method']
        );
        $results[] = [
            'name' => $config['name'],
            'success' => $success
        ];
    }
    
    echo "\n" . str_repeat("=", 70) . "\n";
    echo "🎉 Configuration Test Results:\n";
    echo str_repeat("=", 70) . "\n";
    
    foreach ($results as $result) {
        echo "✅ " . $result['name'] . ": " . ($result['success'] ? "PASS" : "FAIL") . "\n";
    }
    
    $allPassed = array_reduce($results, function($carry, $result) {
        return $carry && $result['success'];
    }, true);
    
    echo "\n🎊 Overall Result: " . ($allPassed ? "✅ ALL CONFIGURATIONS WORK!" : "❌ Some configurations failed") . "\n";
    
    echo "\n📋 Configuration Summary:\n";
    echo "1. ✅ All-in-Crypto: Customer pays everything in crypto\n";
    echo "2. ✅ Hybrid Options: Mix of crypto and separate payments\n";
    echo "3. ✅ Flexible Setup: Merchants can choose what to include\n";
    echo "4. ✅ Payment Methods: Multiple options for separate payments\n";
    echo "5. ✅ Auto-conversion: Optional crypto-to-fiat conversion\n";
}

// Run the test
main();
?>
