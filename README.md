# Stablecoin Pay for WooCommerce

Accept cryptocurrency payments in your WooCommerce store with Stablecoin Pay.

---

## 🚀 Quick Start

### **Installation**

1. Download `stablecoin-pay.zip`
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the zip file and activate
4. Go to WooCommerce → Settings → Payments → Stablecoin Pay
5. Enter your Merchant ID and API Key
6. Copy the Webhook URL to your merchant dashboard
7. Enable the payment method

**Done! ✅**

---

## ⚙️ Configuration

**Required Settings:**
- **Merchant ID** - From your merchant dashboard
- **API Key** - From your merchant dashboard  
- **Webhook URL** - Auto-generated, copy to your merchant dashboard

**Optional:**
- **Title** - What customers see at checkout (default: "Stablecoin Pay")
- **Description** - Payment method description

**Note:** The plugin automatically applies white-label branding based on your merchant credentials. The checkout will display your white-label company name and logo.

---

## 💰 How It Works

### **Customer Flow:**
1. Customer adds products to cart
2. Proceeds to checkout
3. Selects "Stablecoin Pay" (or white-label name) payment method
4. Clicks "Place Order"
5. Redirected to crypto checkout page (white-labeled)
6. Pays with crypto wallet
7. Payment confirmed automatically

### **Merchant Flow:**
1. Receive "Payment Complete" notification
2. See order in WooCommerce admin
3. View transaction hash on blockchain
4. Ship the order
5. Mark as completed

---

## 📊 What Gets Paid in Crypto

**Total amount includes:**
- ✅ Product prices
- ✅ Shipping costs
- ✅ Taxes

**Customer pays the full total in cryptocurrency.**

Example:
```
Products:  $100.00
Shipping:  $15.00
Tax:       $8.25
─────────────────
TOTAL:     $123.25  ← Customer pays this in crypto
```

---

## 🔔 Webhooks

The plugin automatically receives payment confirmations from Stablecoin Pay.

**Webhook URL:** `https://yoursite.com/wp-json/coinsub/v1/webhook`

**Events handled:**
- `payment` - Payment successful → Order status: "Processing"
- `failed_payment` - Payment failed → Order status: "Failed"  
- `cancellation` - Customer cancelled → Order status: "Cancelled"

**When payment succeeds:**
- Order status changes to "Processing"
- Transaction hash stored in order meta
- You and customer receive email notifications
- You can now ship the order

---

## 📦 Shipping

**Shipping is calculated by WooCommerce:**
- Use WooCommerce's built-in shipping zones
- Or install USPS/FedEx/UPS plugins
- Or use flat rate shipping
- Or use ShipStation/Shippo

**The Stablecoin Pay plugin just reads the shipping cost and includes it in the crypto payment.**

---

## 🧪 Testing

### **Test Without WordPress:**
```bash
php test-complete-order.php
```

This creates a test order with products, shipping, and tax.

### **Test With WordPress:**
1. Create a test product
2. Add to cart
3. Checkout with Stablecoin Pay
4. Use test crypto wallet
5. Verify webhook updates order

---

## 📁 File Structure

```
stablecoin-pay/
├── coinsub-commerce.php                    ← Main plugin file
├── includes/
│   ├── class-coinsub-api-client.php        ← API communication
│   ├── class-coinsub-payment-gateway.php   ← Payment logic
│   ├── class-coinsub-webhook-handler.php   ← Webhook receiver
│   └── class-coinsub-order-manager.php     ← Admin UI
├── test-complete-order.php                 ← Test script
└── README.md                               ← This file
```

**See `QUICK-REFERENCE.md` for technical details.**

---

## 🔧 Troubleshooting

**Order not updating after payment?**
- Check webhook URL is correct in your merchant dashboard
- Check WordPress error logs

**Checkout URL not opening?**
- Disable popup blocker
- Check browser console for errors

**Wrong shipping/tax amount?**
- Check WooCommerce tax settings
- Check WooCommerce shipping zones

**Products not syncing?**
- Check API credentials
- Check merchant ID matches

**White-label branding not showing?**
- Ensure Merchant ID and API Key are saved in settings
- Click "Save changes" to trigger branding fetch
- Check that your merchant account has white-label branding configured

---

## 🆘 Support

- **Documentation:** `QUICK-REFERENCE.md`
- **Test Script:** `test-complete-order.php`
- **Logs:** WordPress → Tools → Site Health → Info → Server

---

## 📝 Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- SSL certificate (HTTPS)
- Stablecoin Pay merchant account

---

## 🎯 What This Plugin Does

**IN:**
- Reads WooCommerce order data (products, shipping, tax, discounts, fees)
- Creates Stablecoin Pay checkout session
- Generates crypto payment URL (white-labeled if configured)

**OUT:**
- Receives webhook when payment succeeds
- Updates WooCommerce order status
- Stores transaction hash

**White-Label Support:**
- Automatically applies white-label branding based on merchant credentials
- Displays white-label company name and logo at checkout
- Works with parent white-labelers and their sub-merchants

**That's it!** Simple payments plugin. WooCommerce handles everything else (cart, shipping, taxes, inventory, emails).

---

**Version:** 1.0.0  
**Last Updated:** November 2025
