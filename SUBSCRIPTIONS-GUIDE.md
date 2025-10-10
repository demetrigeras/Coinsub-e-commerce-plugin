# 🔄 Coinsub Subscriptions Guide

## Overview

This plugin now supports **recurring subscriptions** with Coinsub! Merchants can create subscription products that automatically bill customers at regular intervals.

---

## 🎯 Key Features

### ✅ Subscription Rules
- **One subscription per checkout** - Customers can only buy one subscription at a time
- **No mixing** - Cannot mix subscriptions with regular products
- **Separate checkouts** - Each subscription requires its own checkout
- **Flat-rate shipping** - Shipping costs are constant for recurring payments

### ✅ Customer Features
- View all subscriptions in "My Account" → "Subscriptions"
- Cancel subscriptions with one click
- See next payment date and frequency
- Automatic recurring billing

---

## 📋 How to Create a Subscription Product

### Step 1: Edit Product
1. Go to **Products** → **Add New** (or edit existing)
2. Scroll to **Product Data** section
3. Check **"Coinsub Subscription"** checkbox

### Step 2: Configure Subscription
- **Frequency**: How often it renews
  - Every (1x)
  - Every Other (2x)
  - Every Third (3x)
  - Every Fourth (4x)

- **Interval**: Time period
  - Day
  - Week
  - Month
  - Year

- **Duration**: How long it lasts
  - `0` = Until Cancelled
  - `12` = 12 payments then stops

### Examples:
- **Monthly subscription**: Frequency = "Every", Interval = "Month", Duration = "0"
- **Bi-weekly for 6 months**: Frequency = "Every Other", Interval = "Week", Duration = "12"
- **Annual**: Frequency = "Every", Interval = "Year", Duration = "0"

---

## 🛒 Cart Validation

The plugin automatically enforces these rules:

### ❌ Blocked Actions:
- Adding a second subscription when one is in cart
- Adding regular products when subscription is in cart
- Adding subscription when regular products are in cart

### ✅ Required Flow:
1. Customer adds subscription to cart
2. Customer must checkout subscription first
3. After checkout, customer can shop for other items

---

## 👤 Customer Subscription Management

### My Account → Subscriptions

Customers can:
- View all active subscriptions
- See product name, amount, frequency
- See next payment date
- Cancel subscriptions

### Cancellation Flow:
1. Customer clicks "Cancel" button
2. Confirms cancellation
3. Plugin calls Coinsub API: `POST /v1/agreements/cancel/{id}`
4. Subscription is cancelled
5. No more recurring charges

---

## 🔧 Technical Details

### API Integration

**Cancel Subscription:**
```
POST https://dev-api.coinsub.io/v1/agreements/cancel/{agreement_id}
Headers:
  - Merchant-ID: {merchant_id}
  - API-Key: {api_key}
```

### Order Meta Data

Subscription orders store:
- `_coinsub_agreement_id` - The subscription agreement ID
- `_coinsub_subscription_status` - active/cancelled
- `_coinsub_frequency_text` - Human-readable frequency
- `_coinsub_next_payment` - Next payment date

### Product Meta Data

Subscription products store:
- `_coinsub_subscription` - yes/no
- `_coinsub_frequency` - 1-4 (Every, Every Other, etc.)
- `_coinsub_interval` - 0-3 (Day, Week, Month, Year)
- `_coinsub_duration` - 0 = Until Cancelled, or number of payments

---

## 🎨 Frontend Display

### Cart Page
- Shows subscription details
- Blocks adding other items
- Clear messaging about subscription rules

### Checkout Page
- Standard Coinsub checkout
- Passes `recurring: true` to API
- Includes frequency, interval, duration

### My Account
- New "Subscriptions" tab
- Table showing all subscriptions
- Cancel button for each active subscription

---

## 🔔 Webhook Events

### `recurrence_signup`
Fired when subscription is created:
```json
{
  "type": "recurrence_signup",
  "agreement_id": "agr_xxx",
  "merchant_id": "mrch_xxx",
  "frequency": "Every",
  "interval": "Month",
  "duration": "Until Cancelled",
  "next_process_date": "2025-11-10"
}
```

### `cancellation`
Fired when subscription is cancelled:
```json
{
  "type": "cancellation",
  "agreement_id": "agr_xxx",
  "cancellation_date": "2025-10-10",
  "active_until": "2025-11-10"
}
```

---

## 🚀 Next Steps

### For Merchants:
1. Create subscription products
2. Set frequency, interval, duration
3. Customers can subscribe and manage from their account

### For Customers:
1. Add subscription to cart
2. Checkout (one subscription at a time)
3. Manage subscriptions in My Account → Subscriptions
4. Cancel anytime

---

## ⚠️ Important Notes

- **Shipping**: Only flat-rate shipping supported for subscriptions
- **Taxes**: Tax amount is included in recurring total
- **Mixing**: Cannot mix subscriptions with regular products
- **Multiple**: Cannot buy multiple subscriptions in one checkout
- **Cancellation**: Customers can cancel anytime from My Account

---

## 🐛 Troubleshooting

### Subscription not showing in My Account
- Check order has `_coinsub_agreement_id` meta
- Check `_coinsub_subscription_status` is not "cancelled"

### Cancel button not working
- Check API credentials are correct
- Check agreement ID is valid
- Check webhook endpoint is accessible

### Cart validation not working
- Clear cart and try again
- Check product has `_coinsub_subscription` = "yes"
- Ensure WooCommerce is updated

---

**Ready to use subscriptions!** 🎉

