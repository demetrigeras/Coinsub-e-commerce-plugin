# CoinSub Commerce Testing Guide

## 🔧 **What Was Fixed**

### **Issue 1: Orders Not Showing in WooCommerce Dashboard**
✅ **Fixed**: Registered custom order status `wc-pending-coinsub` so WooCommerce recognizes it.

**Before**: Orders were created but invisible because WooCommerce didn't know about the custom status.

**After**: Orders now appear in WooCommerce > Orders with status "Pending Crypto Payment" 🟡

---

### **Issue 2: Webhook Not Working**
✅ **Fixed**: Added proper REST API registration and test endpoint.

**Webhook URL**: `https://coinsubcommerce.com/wp-json/coinsub/v1/webhook`

---

## 🧪 **Testing Steps**

### **Step 1: Verify Orders Are Visible**

1. Upload and activate the new plugin
2. Go to **WooCommerce > Orders**
3. You should now see orders with status **"Pending Crypto Payment"** 🟡
4. Click on an order to see CoinSub metadata:
   - `_coinsub_order_id`
   - `_coinsub_purchase_session_id`
   - `_coinsub_checkout_url`
   - `_coinsub_merchant_id`

---

### **Step 2: Test Webhook Endpoint**

#### **A. Test from Browser**
Visit this URL in your browser:
```
https://coinsubcommerce.com/wp-json/coinsub/v1/webhook/test
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

#### **B. Test from Command Line**
```bash
curl https://coinsubcommerce.com/wp-json/coinsub/v1/webhook/test
```

#### **C. Test Webhook POST (Simulate CoinSub)**
```bash
curl -X POST https://coinsubcommerce.com/wp-json/coinsub/v1/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "type": "payment",
    "origin_id": "YOUR_PURCHASE_SESSION_ID",
    "merchant_id": "e470c0fa-6909-4942-be73-4fbc504893a1",
    "status": "completed",
    "amount": 0.40,
    "currency": "USD"
  }'
```

Replace `YOUR_PURCHASE_SESSION_ID` with the actual session ID from a test order.

---

### **Step 3: Complete Payment Flow Test**

1. **Add product to cart**
   - Check logs: Should see "🔄 CoinSub Cart Sync - Product created"
   - Check logs: Should see "✅ CoinSub Cart Sync - Order created"

2. **Go to checkout**
   - Enter shipping address
   - Check logs: Should see "✅ CoinSub Cart Sync - Order updated"

3. **Click "Continue to Payment"**
   - Should redirect to CoinSub checkout page
   - Check WooCommerce > Orders: Order should be "Pending Crypto Payment" 🟡

4. **Complete payment on CoinSub**
   - CoinSub should redirect back to WooCommerce "Order Received" page
   - Order should still be "Pending Crypto Payment" (waiting for webhook)

5. **Webhook fires** (when payment confirms)
   - Check logs: Should see "🔔 CoinSub Webhook - Received webhook request"
   - Check WooCommerce > Orders: Order should change to "Processing" or "Completed" ✅

---

## 📋 **What to Look For in Logs**

### **Cart Sync Logs**
```
🔄 CoinSub Cart Sync - Initialized
✅ CoinSub Cart Sync - Product created: [product_id]
✅ CoinSub Cart Sync - Order created: [order_id]
✅ CoinSub Cart Sync - Order updated: [order_id]
```

### **Payment Process Logs**
```
🚀🚀🚀 CoinSub - process_payment() called for order #[order_id]
✅ CoinSub - Using existing order from cart: [coinsub_order_id]
💳 CoinSub - Step 2: Creating purchase session...
✅ CoinSub - Purchase session created: [session_id]
🔗 CoinSub - Step 3: Linking order to session...
✅ CoinSub - Order linked to session!
🎉 CoinSub - Payment process complete! Checkout URL: [url]
```

### **Webhook Logs**
```
🔔 CoinSub Webhook - Received webhook request
🔔 CoinSub Webhook - Data: {"type":"payment","origin_id":"..."}
✅ CoinSub Webhook - Processed successfully
```

---

## 🐛 **Troubleshooting**

### **Orders Still Not Showing?**
1. Go to **WooCommerce > Orders**
2. Click **"All Statuses"** dropdown at the top
3. Select **"Pending Crypto Payment"**
4. Orders should now be visible

### **Webhook Not Working?**
1. Test the endpoint: `https://coinsubcommerce.com/wp-json/coinsub/v1/webhook/test`
2. Check if you get a 200 response
3. If 404, flush permalinks: **Settings > Permalinks > Save Changes**
4. Check CoinSub dashboard webhook settings

### **Orders Not Updating After Payment?**
1. Check if webhook is configured in CoinSub dashboard
2. Webhook URL should be: `https://coinsubcommerce.com/wp-json/coinsub/v1/webhook`
3. Check WordPress debug logs for webhook errors
4. Verify the `origin_id` in webhook matches `_coinsub_purchase_session_id` in order meta

---

## 🎯 **Expected Flow**

```
1. User adds to cart
   ↓
2. Product & order created in CoinSub DB
   ↓
3. User goes to checkout
   ↓
4. Order updated with shipping/taxes
   ↓
5. User clicks "Continue to Payment"
   ↓
6. Redirected to CoinSub checkout (same tab)
   ↓
7. User pays on CoinSub
   ↓
8. CoinSub redirects back to WooCommerce "Order Received"
   ↓
9. Webhook fires (payment confirmed)
   ↓
10. WooCommerce order status → "Processing" or "Completed" ✅
```

---

## 📞 **Support**

If you encounter issues:
1. Check **WooCommerce > CoinSub Logs**
2. Check WordPress debug logs: `/wp-content/debug.log`
3. Test webhook endpoint: `/wp-json/coinsub/v1/webhook/test`
4. Verify CoinSub API credentials in **WooCommerce > Settings > Payments > CoinSub**

---

## 🚀 **Next Steps**

1. Upload the new plugin ZIP
2. Test the webhook endpoint
3. Complete a test order
4. Verify order appears in WooCommerce dashboard
5. Configure webhook in CoinSub dashboard
6. Test webhook by completing a payment

