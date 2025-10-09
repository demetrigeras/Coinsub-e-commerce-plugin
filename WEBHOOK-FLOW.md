# 🔔 CoinSub Webhook Flow - Complete Guide

## 📡 **How WooCommerce Gets Notified of Payment**

### **The Webhook URL**
```
https://coinsubcommerce.com/wp-json/coinsub/v1/webhook
```

**This is the URL you configure in your CoinSub dashboard** so CoinSub knows where to send payment notifications.

---

## 🔄 **Complete Payment Flow**

```
┌─────────────────────────────────────────────────────────────────┐
│  STEP 1: User Completes Checkout                                │
│  ─────────────────────────────────────────────────────────────  │
│  • User clicks "Continue to Payment" on WooCommerce             │
│  • WooCommerce creates Order #159                               │
│  • Order status: "Pending Crypto Payment" 🟡                    │
│  • Order metadata stored:                                       │
│    - _coinsub_order_id: "abc-123"                              │
│    - _coinsub_purchase_session_id: "session-xyz-789"           │
│    - _coinsub_checkout_url: "https://checkout.coinsub.io/..."  │
│    - _coinsub_merchant_id: "your-merchant-id"                  │
│  • User redirected to CoinSub checkout page                     │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│  STEP 2: User Pays on CoinSub                                   │
│  ─────────────────────────────────────────────────────────────  │
│  • User sends crypto payment                                    │
│  • CoinSub confirms payment on blockchain                       │
│  • CoinSub redirects user back to WooCommerce                   │
│  • User sees "Order Received" page (still pending)              │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│  STEP 3: CoinSub Sends Webhook to WooCommerce                   │
│  ─────────────────────────────────────────────────────────────  │
│  POST https://coinsubcommerce.com/wp-json/coinsub/v1/webhook   │
│                                                                  │
│  Headers:                                                        │
│    Content-Type: application/json                               │
│    X-CoinSub-Signature: <signature>                            │
│                                                                  │
│  Body:                                                           │
│  {                                                               │
│    "type": "payment",                                           │
│    "origin_id": "session-xyz-789",  ← Matches session ID       │
│    "merchant_id": "your-merchant-id",                           │
│    "status": "completed",                                        │
│    "amount": 0.40,                                              │
│    "currency": "USD",                                            │
│    "payment_id": "pay_123",                                     │
│    "transaction_details": {                                      │
│      "transaction_id": "tx_456",                                │
│      "transaction_hash": "0x123abc...",                         │
│      "chain_id": "1",                                            │
│      "wallet_address": "0xabc..."                               │
│    }                                                             │
│  }                                                               │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│  STEP 4: WooCommerce Plugin Receives Webhook                    │
│  ─────────────────────────────────────────────────────────────  │
│  File: includes/class-coinsub-webhook-handler.php               │
│                                                                  │
│  1. Verify webhook signature (security) ✅                      │
│  2. Extract origin_id: "session-xyz-789"                        │
│  3. Search WooCommerce orders for matching session ID           │
│  4. Find Order #159 ✅                                          │
│  5. Verify merchant_id matches ✅                               │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│  STEP 5: Update WooCommerce Order                               │
│  ─────────────────────────────────────────────────────────────  │
│  • Order status: "Pending Crypto Payment" → "Processing" ✅     │
│  • Add order note: "Payment Complete - Hash: 0x123abc..."       │
│  • Store transaction metadata:                                  │
│    - _coinsub_transaction_id: "tx_456"                         │
│    - _coinsub_transaction_hash: "0x123abc..."                  │
│    - _coinsub_chain_id: "1"                                     │
│  • Send customer email: "Your order is processing"              │
│  • Trigger Printful fulfillment (if integrated)                 │
│  • Return 200 OK to CoinSub ✅                                  │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│  STEP 6: Customer & Merchant Notified                           │
│  ─────────────────────────────────────────────────────────────  │
│  • Customer receives email: "Order #159 is being processed"     │
│  • Merchant sees order in WooCommerce dashboard                 │
│  • Order status: "Processing" ✅                                │
│  • Payment details visible in order notes                       │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔍 **How the Plugin Finds the Order**

### **Code Flow**

```php
// 1. Webhook received
$data = $request->get_json_params();
$origin_id = $data['origin_id']; // "session-xyz-789"

// 2. Search WooCommerce orders
$orders = wc_get_orders(array(
    'meta_key' => '_coinsub_purchase_session_id',
    'meta_value' => $origin_id,
    'limit' => 1
));

// 3. Found Order #159!
$order = $orders[0];

// 4. Update status
$order->update_status('processing', 'Payment Complete');
```

---

## 🔐 **Security: Webhook Signature Verification**

To prevent fake webhooks, the plugin verifies the signature:

```php
private function verify_webhook_signature($raw_data) {
    $gateway = WC()->payment_gateways->payment_gateways()['coinsub'];
    $webhook_secret = $gateway->get_option('webhook_secret');
    
    if (empty($webhook_secret)) {
        return true; // No secret configured, allow all
    }
    
    $signature = $_SERVER['HTTP_X_COINSUB_SIGNATURE'] ?? '';
    $expected_signature = hash_hmac('sha256', $raw_data, $webhook_secret);
    
    return hash_equals($expected_signature, $signature);
}
```

**To enable signature verification**:
1. Go to **WooCommerce > Settings > Payments > CoinSub**
2. Enter your **Webhook Secret** from CoinSub dashboard
3. Save settings

---

## 🧪 **Testing the Webhook**

### **1. Test Endpoint Accessibility**
```bash
curl https://coinsubcommerce.com/wp-json/coinsub/v1/webhook/test
```

**Expected Response**:
```json
{
  "status": "success",
  "message": "CoinSub webhook endpoint is working!",
  "endpoint": "https://coinsubcommerce.com/wp-json/coinsub/v1/webhook",
  "timestamp": "2025-10-09 15:50:21"
}
```

### **2. Simulate a Webhook (Manual Test)**
```bash
curl -X POST https://coinsubcommerce.com/wp-json/coinsub/v1/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "type": "payment",
    "origin_id": "YOUR_SESSION_ID_HERE",
    "merchant_id": "e470c0fa-6909-4942-be73-4fbc504893a1",
    "status": "completed",
    "amount": 0.40,
    "currency": "USD",
    "payment_id": "test_payment_123",
    "transaction_details": {
      "transaction_id": "test_tx_456",
      "transaction_hash": "0xtest123abc",
      "chain_id": "1"
    }
  }'
```

**To get the session ID**:
1. Create a test order
2. Go to **WooCommerce > Orders > [Your Order]**
3. Scroll down to **Custom Fields**
4. Copy the value of `_coinsub_purchase_session_id`
5. Use that in the curl command above

### **3. Check Logs**
After sending the webhook, check:
```
WooCommerce > CoinSub Logs
```

**Expected Logs**:
```
🔔 CoinSub Webhook - Received webhook request
🔔 CoinSub Webhook - Data: {"type":"payment","origin_id":"..."}
CoinSub Webhook: Found order ID: 159 for origin ID: session-xyz-789
CoinSub Webhook: PAYMENT COMPLETE for order #159 | Transaction Hash: 0xtest123abc
✅ CoinSub Webhook - Processed successfully
```

---

## 📋 **Webhook Event Types**

Your plugin handles these webhook types:

| Event Type | Description | Order Status |
|------------|-------------|--------------|
| `payment` | Payment completed | `processing` ✅ |
| `failed_payment` | Payment failed | `failed` ❌ |
| `cancellation` | User cancelled | `cancelled` 🚫 |
| `transfer` | Funds transferred to merchant | `completed` 💰 |
| `failed_transfer` | Transfer failed | `failed` ❌ |

---

## 🎯 **Configure in CoinSub Dashboard**

1. Log into your CoinSub merchant dashboard
2. Go to **Settings > Webhooks** (or similar)
3. Add webhook URL:
   ```
   https://coinsubcommerce.com/wp-json/coinsub/v1/webhook
   ```
4. Select events to send:
   - ✅ Payment Completed
   - ✅ Payment Failed
   - ✅ Payment Cancelled
   - ✅ Transfer Completed
   - ✅ Transfer Failed
5. Copy the **Webhook Secret**
6. Paste it in **WooCommerce > Settings > Payments > CoinSub > Webhook Secret**
7. Save settings

---

## 🐛 **Troubleshooting**

### **Webhook Not Received?**

1. **Test endpoint accessibility**:
   ```bash
   curl https://coinsubcommerce.com/wp-json/coinsub/v1/webhook/test
   ```
   If this fails, flush permalinks: **Settings > Permalinks > Save Changes**

2. **Check firewall/security plugins**:
   - Wordfence, Sucuri, etc. might block webhooks
   - Add `wp-json/coinsub/v1/webhook` to whitelist

3. **Check CoinSub dashboard**:
   - Verify webhook URL is configured
   - Check webhook logs for delivery status
   - Look for error messages

4. **Check WordPress debug logs**:
   ```
   /wp-content/debug.log
   ```
   Look for lines starting with `🔔 CoinSub Webhook`

### **Order Not Updating?**

1. **Verify session ID matches**:
   - Check order meta: `_coinsub_purchase_session_id`
   - Compare with webhook `origin_id`
   - They must match exactly

2. **Check merchant ID**:
   - Order meta: `_coinsub_merchant_id`
   - Webhook: `merchant_id`
   - They must match

3. **Check webhook signature**:
   - If webhook secret is configured, signature must be valid
   - Temporarily disable signature verification for testing

---

## 📞 **Support**

If webhooks still aren't working:

1. Share the output of:
   ```bash
   curl https://coinsubcommerce.com/wp-json/coinsub/v1/webhook/test
   ```

2. Share logs from:
   - **WooCommerce > CoinSub Logs**
   - `/wp-content/debug.log` (lines with "CoinSub Webhook")

3. Share CoinSub dashboard webhook delivery logs

---

## ✅ **Summary**

**Yes, the webhook URL is how WooCommerce gets notified!**

```
CoinSub → Sends webhook → Your WooCommerce site
         (payment complete)
                ↓
         WooCommerce updates order status
                ↓
         Customer gets email
                ↓
         Printful receives order
```

**Configure this URL in CoinSub dashboard**:
```
https://coinsubcommerce.com/wp-json/coinsub/v1/webhook
```

