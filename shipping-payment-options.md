# Shipping Payment Options for Crypto E-commerce

## Current Implementation
Your CoinSub integration currently handles shipping costs by including them in the crypto payment total. This means customers pay for shipping in crypto (USDC) along with their products.

## Recommended Approaches

### 1. **Hybrid Payment (Recommended)**
- **Products**: Paid in crypto via CoinSub
- **Shipping**: Paid separately in fiat (credit card, PayPal)
- **Implementation**: 
  - Calculate shipping costs separately
  - Show crypto total for products
  - Show fiat total for shipping
  - Process two separate payments

### 2. **Crypto-Only with Conversion**
- **Everything**: Paid in crypto via CoinSub
- **Shipping**: Converted to fiat automatically
- **Implementation**:
  - Include shipping in crypto total
  - Use conversion service (Coinbase, BitPay)
  - Pay shipping company in fiat

### 3. **Merchant-Covered Shipping**
- **Products**: Customer pays in crypto
- **Shipping**: Merchant covers from crypto revenue
- **Implementation**:
  - Customer pays only product cost in crypto
  - Merchant pays shipping from crypto earnings
  - Simplest for customers

## Technical Implementation

### Option 1: Hybrid Payment
```php
// In your WooCommerce integration
$product_total = $order->get_subtotal(); // Crypto payment
$shipping_total = $order->get_shipping_total(); // Fiat payment

// Process crypto payment for products
$crypto_payment = createPurchaseSession([
    'amount' => $product_total,
    'currency' => 'USDC',
    'description' => 'Product payment'
]);

// Process fiat payment for shipping
$fiat_payment = processFiatPayment([
    'amount' => $shipping_total,
    'currency' => 'USD',
    'description' => 'Shipping payment'
]);
```

### Option 2: Crypto-Only with Conversion
```php
// Include shipping in crypto total
$total_amount = $order->get_total(); // Products + Shipping

$crypto_payment = createPurchaseSession([
    'amount' => $total_amount,
    'currency' => 'USDC',
    'description' => 'Products + Shipping'
]);

// After payment, convert shipping portion to fiat
$shipping_amount = $order->get_shipping_total();
convertCryptoToFiat($shipping_amount, 'USDC', 'USD');
```

## Shipping Company Payment Methods

### Traditional Fiat Payment
- **Method**: Bank transfer, check, credit card
- **Currency**: USD, EUR, GBP, etc.
- **Timing**: After order fulfillment
- **Process**: Manual or automated via API

### Crypto Payment (Limited)
- **Method**: USDC, USDT, Bitcoin
- **Platforms**: Some logistics companies accept crypto
- **Timing**: Real-time or batch
- **Process**: Direct crypto transfer

## Recommended Flow

1. **Customer places order** → WooCommerce calculates totals
2. **Products paid in crypto** → Via CoinSub (USDC)
3. **Shipping handled separately** → Fiat payment or merchant-covered
4. **Order fulfillment** → Products shipped
5. **Shipping company paid** → In fiat or crypto (depending on option)

## Integration Points

### WooCommerce Settings
- Add shipping payment method selection
- Configure crypto vs fiat payment options
- Set up conversion rates if needed

### CoinSub Integration
- Modify purchase session to handle shipping options
- Add metadata for shipping payment method
- Update webhook handling for different payment types

### Shipping Integration
- Connect with shipping APIs (UPS, FedEx, DHL)
- Handle shipping label generation
- Process shipping payments
