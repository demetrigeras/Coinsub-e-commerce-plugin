<?php
/**
 * Complete Order Test with Multiple Products, Shipping, and Tax
 * Tests the full CoinSub integration with realistic order data
 */

// Configuration
define('MERCHANT_ID', 'ca875a80-9b10-40ce-85c0-5af81856733a');
define('API_KEY', 'abf3e9e5-0140-4fda-abc9-7dd87a358852');
define('API_BASE_URL', 'https://test-api.coinsub.io/v1');

echo "<h1>🛒 Complete Order Test - Multiple Products + Shipping + Tax</h1>";
echo "<style>
    body{font-family:system-ui;padding:20px;max-width:1000px;margin:0 auto;} 
    pre{background:#f5f5f5;padding:15px;border-radius:5px;overflow-x:auto;} 
    .success{color:green;font-weight:bold;} 
    .error{color:red;font-weight:bold;}
    .info{background:#e3f2fd;padding:15px;border-radius:5px;margin:15px 0;}
    table{border-collapse:collapse;width:100%;margin:15px 0;}
    th,td{border:1px solid #ddd;padding:8px;text-align:left;}
    th{background:#f5f5f5;}
    .total-row{background:#fff3cd;font-weight:bold;}
</style>";

// Order Details
$products = array(
    array(
        'name' => 'Premium T-Shirt',
        'price' => 29.99,
        'quantity' => 2,
        'sku' => 'TSHIRT-001'
    ),
    array(
        'name' => 'Classic Hoodie',
        'price' => 59.99,
        'quantity' => 1,
        'sku' => 'HOODIE-001'
    ),
    array(
        'name' => 'Baseball Cap',
        'price' => 19.99,
        'quantity' => 3,
        'sku' => 'CAP-001'
    )
);

$shipping_cost = 15.00;
$tax_rate = 0.0825; // 8.25% tax rate

// Calculate totals
$subtotal = 0;
foreach ($products as $product) {
    $subtotal += $product['price'] * $product['quantity'];
}
$tax_amount = round($subtotal * $tax_rate, 2);
$total = $subtotal + $shipping_cost + $tax_amount;

// Display Order Summary
echo "<div class='info'>";
echo "<h2>📋 Order Summary</h2>";
echo "<table>";
echo "<tr><th>Product</th><th>SKU</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr>";

foreach ($products as $product) {
    $line_total = $product['price'] * $product['quantity'];
    echo "<tr>";
    echo "<td>{$product['name']}</td>";
    echo "<td>{$product['sku']}</td>";
    echo "<td>$" . number_format($product['price'], 2) . "</td>";
    echo "<td>{$product['quantity']}</td>";
    echo "<td>$" . number_format($line_total, 2) . "</td>";
    echo "</tr>";
}

echo "<tr class='total-row'><td colspan='4'>Subtotal</td><td>$" . number_format($subtotal, 2) . "</td></tr>";
echo "<tr><td colspan='4'>Shipping</td><td>$" . number_format($shipping_cost, 2) . "</td></tr>";
echo "<tr><td colspan='4'>Tax (8.25%)</td><td>$" . number_format($tax_amount, 2) . "</td></tr>";
echo "<tr class='total-row'><td colspan='4'><strong>TOTAL</strong></td><td><strong>$" . number_format($total, 2) . "</strong></td></tr>";
echo "</table>";
echo "</div>";

// Step 1: Create Products in CoinSub
echo "<h2>Step 1: Creating Products in CoinSub</h2>";
$coinsub_products = array();

foreach ($products as $index => $product) {
    echo "<h3>Creating: {$product['name']}</h3>";
    
    $product_data = array(
        'merchant_id' => MERCHANT_ID,
        'name' => $product['name'],
        'price' => $product['price'],
        'currency' => 'USD',
        'metadata' => array(
            'sku' => $product['sku'],
            'woocommerce_test' => true
        )
    );
    
    $ch = curl_init(API_BASE_URL . '/commerce/products');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($product_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Merchant-ID: ' . MERCHANT_ID,
        'API-Key: ' . API_KEY,
        'Authorization: Bearer ' . API_KEY
    ));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($http_code === 201 && isset($result['id'])) {
        echo "<p class='success'>✅ Created - ID: {$result['id']}</p>";
        $coinsub_products[] = array(
            'product_id' => $result['id'],
            'quantity' => $product['quantity'],
            'name' => $product['name'],
            'price' => $product['price']
        );
    } else {
        echo "<p class='error'>❌ Failed to create product</p>";
        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
        exit;
    }
}

// Step 2: Create Order
echo "<h2>Step 2: Creating Order in CoinSub</h2>";

$order_items = array();
foreach ($coinsub_products as $cp) {
    $order_items[] = array(
        'product_id' => $cp['product_id'],
        'quantity' => $cp['quantity'],
        'price' => (string)$cp['price']
    );
}

$order_data = array(
    'items' => $order_items,
    'total' => (string)$total,
    'currency' => 'USD',
    'metadata' => array(
        'customer_email' => 'customer@example.com',
        'customer_name' => 'John Doe',
        'subtotal' => $subtotal,
        'shipping' => $shipping_cost,
        'tax' => $tax_amount,
        'total' => $total,
        'order_breakdown' => array(
            'products' => array_map(function($p) {
                return array(
                    'name' => $p['name'],
                    'quantity' => $p['quantity'],
                    'price' => $p['price']
                );
            }, $coinsub_products)
        )
    )
);

$ch = curl_init(API_BASE_URL . '/commerce/orders');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Merchant-ID: ' . MERCHANT_ID,
    'API-Key: ' . API_KEY,
    'Authorization: Bearer ' . API_KEY
));

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>Status:</strong> $http_code</p>";
echo "<pre>" . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "</pre>";

$order = json_decode($response, true);
if ($http_code === 201 && isset($order['id'])) {
    echo "<p class='success'>✅ Order created - ID: {$order['id']}</p>";
    $order_id = $order['id'];
} else {
    echo "<p class='error'>❌ Failed to create order</p>";
    exit;
}

// Step 3: Create Purchase Session with Complete Details
echo "<h2>Step 3: Creating Purchase Session</h2>";

// Build product names string
$product_names = array();
foreach ($coinsub_products as $cp) {
    $product_names[] = $cp['name'] . " (x" . $cp['quantity'] . ")";
}
$products_string = implode(', ', $product_names);

$session_data = array(
    'name' => 'Order #TEST-' . time() . ' - ' . count($products) . ' items',
    'details' => $products_string . ' | Shipping: $' . number_format($shipping_cost, 2) . ' | Tax: $' . number_format($tax_amount, 2),
    'currency' => 'USD',
    'amount' => $total, // TOTAL including products + shipping + tax
    'recurring' => false,
    'metadata' => array(
        'order_id' => $order_id,
        'order_breakdown' => array(
            'products' => array_map(function($p) {
                return array(
                    'name' => $p['name'],
                    'quantity' => $p['quantity'],
                    'price' => $p['price'],
                    'line_total' => $p['price'] * $p['quantity']
                );
            }, $coinsub_products),
            'subtotal' => $subtotal,
            'shipping' => array(
                'method' => 'Standard Shipping',
                'cost' => $shipping_cost
            ),
            'tax' => array(
                'rate' => ($tax_rate * 100) . '%',
                'amount' => $tax_amount
            ),
            'total' => $total
        ),
        'customer' => array(
            'email' => 'customer@example.com',
            'name' => 'John Doe'
        )
    ),
    'success_url' => '',
    'cancel_url' => ''
);

echo "<div class='info'>";
echo "<h3>📦 Purchase Session Data:</h3>";
echo "<pre>" . json_encode($session_data, JSON_PRETTY_PRINT) . "</pre>";
echo "</div>";

$ch = curl_init(API_BASE_URL . '/purchase/session/start');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($session_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Merchant-ID: ' . MERCHANT_ID,
    'API-Key: ' . API_KEY,
    'Authorization: Bearer ' . API_KEY
));

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>Status:</strong> $http_code</p>";
echo "<pre>" . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "</pre>";

$session = json_decode($response, true);
if ($http_code === 200 && isset($session['data']['purchase_session_id'])) {
    echo "<p class='success'>✅ Purchase session created!</p>";
    echo "<p><strong>Session ID:</strong> {$session['data']['purchase_session_id']}</p>";
    echo "<p><strong>Amount:</strong> $" . number_format($total, 2) . " USD</p>";
    echo "<p><strong>Checkout URL:</strong> {$session['data']['url']}</p>";
    
    // Use the data from response
    $session_id = $session['data']['purchase_session_id'];
    $session_url = $session['data']['url'];
} else {
    echo "<p class='error'>❌ Failed to create purchase session</p>";
    echo "<p>HTTP Code: $http_code</p>";
    echo "<pre>" . print_r($session, true) . "</pre>";
    exit;
}

// Step 4: Checkout Order
echo "<h2>Step 4: Checking Out Order</h2>";

// Strip sess_ prefix if present
$purchase_session_id = $session_id;
if (strpos($purchase_session_id, 'sess_') === 0) {
    $purchase_session_id = str_replace('sess_', '', $purchase_session_id);
    echo "<p>🔄 Stripped 'sess_' prefix from session ID: $purchase_session_id</p>";
}

$checkout_data = array(
    'purchase_session_id' => $purchase_session_id
);

$ch = curl_init(API_BASE_URL . '/commerce/orders/' . $order_id . '/checkout');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($checkout_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Merchant-ID: ' . MERCHANT_ID,
    'API-Key: ' . API_KEY,
    'Authorization: Bearer ' . API_KEY
));

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>Status:</strong> $http_code</p>";

if ($http_code === 200) {
    echo "<p class='success'>✅ Order checked out successfully!</p>";
    
    echo "<div class='info'>";
    echo "<h2>🎉 Success! Order Complete</h2>";
    echo "<h3>Order Details:</h3>";
    echo "<ul>";
    echo "<li><strong>Order ID:</strong> $order_id</li>";
    echo "<li><strong>Purchase Session ID:</strong> $session_id</li>";
    echo "<li><strong>Products:</strong> " . count($products) . " items</li>";
    echo "<li><strong>Subtotal:</strong> $" . number_format($subtotal, 2) . "</li>";
    echo "<li><strong>Shipping:</strong> $" . number_format($shipping_cost, 2) . "</li>";
    echo "<li><strong>Tax:</strong> $" . number_format($tax_amount, 2) . "</li>";
    echo "<li><strong>TOTAL:</strong> $" . number_format($total, 2) . "</li>";
    echo "</ul>";
    
    echo "<p><a href='" . $session_url . "' target='_blank' style='display:inline-block;background:#7f54b3;color:white;padding:15px 30px;text-decoration:none;border-radius:5px;font-size:18px;margin-top:20px;'>💳 Complete Payment ($" . number_format($total, 2) . ")</a></p>";
    echo "</div>";
} else {
    echo "<p class='error'>❌ Failed to checkout order</p>";
    echo "<pre>" . $response . "</pre>";
}

echo "<hr>";
echo "<p><em>This test demonstrates a complete order with multiple products, shipping, and tax properly included in the purchase session.</em></p>";
?>

