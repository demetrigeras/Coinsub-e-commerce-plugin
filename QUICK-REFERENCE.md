# Quick Reference - CoinSub Plugin

Developer reference for the CoinSub WooCommerce plugin.

---

## 🎯 What It Does

**Payments plugin that:**
1. Takes crypto payments for WooCommerce orders
2. Includes shipping + tax in crypto payment
3. Confirms payment via webhook
4. Updates order status

---

## 📁 Core Files (Only 4 Files Matter)

| File | What It Does |
|------|--------------|
| `class-coinsub-payment-gateway.php` | **Main logic** - handles checkout flow |
| `class-coinsub-api-client.php` | **API calls** - talks to CoinSub API |
| `class-coinsub-webhook-handler.php` | **Webhook** - receives payment confirmations |
| `class-coinsub-order-manager.php` | **Admin UI** - shows payment info |

---

## 🔄 Payment Flow (10 Steps)

```
1. Customer clicks "Place Order"
   ↓
2. Plugin creates products in CoinSub
   ↓
3. Plugin creates order in CoinSub
   ↓
4. Plugin creates purchase session
   ↓
5. Plugin links order to session
   ↓
6. Customer redirected to crypto checkout
   ↓
7. Customer pays with wallet
   ↓
8. Blockchain confirms payment
   ↓
9. CoinSub sends webhook
   ↓
10. Plugin updates order: "Payment Complete"
```

---

## 🔌 API Endpoints Used

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/commerce/products` | Create products |
| `POST` | `/commerce/orders` | Create order |
| `POST` | `/purchase/session/start` | Create checkout |
| `PUT` | `/commerce/orders/{id}/checkout` | Link order to session |

**Webhook receives:** `POST /wp-json/coinsub/v1/webhook`

---

## 💾 Order Metadata Stored

**WooCommerce order meta:**
- `_coinsub_order_id` - CoinSub order UUID
- `_coinsub_purchase_session_id` - Session UUID
- `_coinsub_checkout_url` - Checkout URL
- `_coinsub_transaction_hash` - Blockchain hash (after payment)
- `_coinsub_merchant_id` - Merchant ID

---

## 📊 Order Status Flow

```
Customer places order
  ↓
Status: "Pending CoinSub Payment"
  ↓
Webhook receives "payment" event
  ↓
Status: "Processing" 
Reason: "Payment Complete"
  ↓
Merchant ships order
  ↓
Status: "Completed"
```

**Other statuses:**
- "Failed" - Payment failed
- "Cancelled" - Customer cancelled

---

## 💰 Money Flow Example

**WooCommerce calculates:**
```
Products:  $100.00
Shipping:  $15.00  (from WooCommerce shipping settings)
Tax:       $8.25   (from WooCommerce tax settings)
──────────────────
TOTAL:     $123.25
```

**Sent to CoinSub API:**
```json
{
  "amount": 123.25,
  "currency": "USD",
  "metadata": {
    "subtotal": 100.00,
    "shipping": 15.00,
    "tax": 8.25,
    "total": 123.25
  }
}
```

**Customer pays:** $123.25 in crypto

---

## 🔔 Webhook Events

**Payment Success:**
```json
{
  "type": "payment",
  "origin_id": "sess_abc-123",
  "transaction_details": {
    "transaction_hash": "0x123abc..."
  }
}
```
→ Order status: "Processing"  
→ Status reason: "Payment Complete"

**Payment Failed:**
```json
{
  "type": "failed_payment",
  "origin_id": "sess_abc-123",
  "failure_reason": "Insufficient funds"
}
```
→ Order status: "Failed"  
→ Status reason: "Payment Failed"

**Payment Cancelled:**
```json
{
  "type": "cancellation",
  "origin_id": "sess_abc-123"
}
```
→ Order status: "Cancelled"  
→ Status reason: "Payment Cancelled"

---

## 🧪 Testing

**Test API (without WordPress):**
```bash
cd /path/to/plugin
php test-complete-order.php
```

**Expected output:**
```
✅ Created 3 products
✅ Created order (ID: xxx)
✅ Created purchase session (ID: sess_xxx)
✅ Linked order to session
💳 Checkout URL: https://dev-buy.coinsub.io/checkout/xxx
```

**Test webhook (simulate payment):**
```bash
curl -X POST https://yoursite.com/wp-json/coinsub/v1/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "type": "payment",
    "origin_id": "sess_YOUR_SESSION_ID",
    "transaction_details": {
      "transaction_hash": "0x123test"
    }
  }'
```

---

## 🔧 Configuration

**Production API:**
```php
https://api.coinsub.io/v1
```

**Development API:**
```php
https://dev-api.coinsub.io/v1
```

**Webhook endpoint:**
```
https://yoursite.com/wp-json/coinsub/v1/webhook
```

---

## 📝 Code Examples

### **Get order total with shipping & tax:**
```php
$order = wc_get_order($order_id);
$subtotal = $order->get_subtotal();          // Products only
$shipping = $order->get_shipping_total();    // Shipping cost
$tax = $order->get_total_tax();              // Tax amount
$total = $order->get_total();                // Everything
```

### **Store CoinSub data:**
```php
$order->update_meta_data('_coinsub_order_id', $order_id);
$order->update_meta_data('_coinsub_purchase_session_id', $session_id);
$order->update_meta_data('_coinsub_transaction_hash', $tx_hash);
$order->save();
```

### **Update order status:**
```php
$order->update_status('processing', 'Payment Complete');
$order->add_order_note('Transaction Hash: ' . $tx_hash);
```

---

## 🐛 Debugging

**Enable WordPress debug logs:**
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Check logs:**
```
/wp-content/debug.log
```

**Plugin logs:**
```php
error_log('CoinSub: ' . $message);
```

---

## 🔒 Security

**Webhook verification:**
- Checks merchant ID matches
- Validates order exists
- Verifies session ID

**API authentication:**
- Merchant ID header
- API Key header
- Bearer token

**Data transmission:**
- All communication over HTTPS
- Sensitive data not logged

---

## 💡 Key Points

1. **Plugin = Payments Only** - WooCommerce handles shipping/taxes
2. **Full Total in Crypto** - Customer pays products + shipping + tax
3. **Webhook = Payment Confirmation** - Automatic order status update
4. **Shipping = Merchant's Job** - Plugin doesn't ship anything

---

## 📦 What's Included vs Not

**Plugin DOES:**
- ✅ Process crypto payments
- ✅ Include shipping in payment
- ✅ Include tax in payment
- ✅ Confirm payment via webhook
- ✅ Update order status

**Plugin DOES NOT:**
- ❌ Calculate shipping rates (WooCommerce does this)
- ❌ Calculate taxes (WooCommerce does this)
- ❌ Ship products (merchant does this)
- ❌ Print shipping labels (use ShipStation/etc)
- ❌ Handle refunds (manual process)

---

## 🎯 Integration Points

**With WooCommerce:**
- Registers as payment gateway
- Reads cart data
- Updates order status
- Displays in admin

**With CoinSub API:**
- Creates products
- Creates orders
- Creates checkout sessions
- Receives webhooks

**With Blockchain:**
- Customer sends crypto transaction
- CoinSub detects on-chain
- Webhook confirms payment

---

## 📚 Further Reading

- **Installation:** See `README.md`
- **Testing:** Run `test-complete-order.php`
- **Support:** Check WordPress debug logs

---

**Version:** 1.0.0  
**Last Updated:** October 2025
