# CoinSub Checkout & Order Flow Analysis

## Current Flow Overview

### 1. **User Places Order on Checkout Page**
```
Checkout Page → AJAX: coinsub_ajax_process_payment()
├─ Clear existing order from session (if any)
├─ Create NEW order
├─ Store: coinsub_order_id in session
├─ Call: process_payment()
│   ├─ Create purchase session via API
│   ├─ Get checkout URL (one-time use)
│   ├─ Store in order meta: _coinsub_checkout_url
│   ├─ Store in session: coinsub_checkout_url_{order_id}
│   ├─ Store in session: coinsub_pending_order_id
│   ├─ Empty cart
│   └─ Redirect to: stablecoin-pay-checkout/?order_id={order_id}
```

### 2. **User on Dedicated Checkout Page (Iframe)**
```
stablecoin-pay-checkout/?order_id={order_id}
├─ Shortcode: coinsub_checkout_page_shortcode()
├─ Check session for: coinsub_checkout_url_{order_id}
│   ├─ ✅ Found: Load iframe with checkout URL
│   └─ ❌ Not found: Redirect to checkout (expired session)
└─ JavaScript:
    ├─ Back button click → Clear session → Redirect to checkout
    ├─ Page unload → Clear session (beforeunload/pagehide)
    └─ Iframe load → Show checkout UI
```

### 3. **User Leaves Checkout Page**
```
User Action: Back button / Close tab / Navigate away
├─ AJAX: coinsub_ajax_clear_checkout_session()
├─ Clear from session:
│   ├─ coinsub_order_id
│   ├─ coinsub_checkout_url_{order_id}
│   ├─ coinsub_pending_order_id  ← ⚠️ ISSUE: This breaks cart restoration
│   └─ coinsub_purchase_session_id
└─ Result: Fresh checkout required on return
```

### 4. **User Returns to Checkout Page**
```
Checkout Page Load
├─ Hook: maybe_restore_cart_from_pending_order() ← ⚠️ WON'T WORK
│   └─ Checks: coinsub_pending_order_id
│       └─ ❌ NULL (cleared when user left)
└─ User places order again → Creates NEW order (correct behavior)
```

### 5. **Payment Completes (Webhook)**
```
Webhook: /wp-json/stablecoin/v1/webhook
├─ Process payment confirmation
├─ Update order status to "processing"
├─ Clear session:
│   ├─ coinsub_order_id
│   ├─ coinsub_purchase_session_id
│   └─ coinsub_pending_order_id
└─ Clear cart
```

## 🔴 Issues Identified

### **Issue 1: Cart Restoration Won't Work (But That's OK)**
**Location:** `class-sp-payment-gateway.php:2588-2638`

**Problem:**
- `maybe_restore_cart_from_pending_order()` tries to restore cart from `coinsub_pending_order_id`
- But we clear `coinsub_pending_order_id` when user leaves checkout page
- Result: Cart restoration never happens

**Recommendation:** 
Since you want fresh orders each time (per your requirements), this is actually **correct behavior**. But the code has misleading comments:
- Line 844: "Store order ID in session BEFORE clearing cart (so we can restore if user goes back)"
- Line 859: "Empty cart (will be restored if user goes back and order is still pending)"
- Line 2586: "Restore cart from pending order if user returns to checkout"

**Action:** Remove cart restoration code OR update comments to reflect that it's intentionally disabled.

### **Issue 2: Unused Method**
**Location:** `class-sp-payment-gateway.php:1127-1133`

**Problem:**
- `store_checkout_url()` method uses transients (not session)
- But we're using session everywhere else
- This method is never called

**Action:** Delete this method - it's dead code.

### **Issue 3: Incomplete Session Clearing**
**Location:** `class-sp-payment-gateway.php:845`

**Problem:**
- We store `coinsub_pending_order_id` in session
- But when clearing session on checkout page leave, we clear it
- Webhook also clears it (correct)
- However, we don't clear it when creating a new order (should clear old one first)

**Action:** Already handled in AJAX handler (line 913), but verify consistency.

### **Issue 4: Missing Session Clear on Order Failure**
**Location:** `class-sp-payment-gateway.php:887-894`

**Problem:**
- If `process_payment()` throws exception, session data remains
- Could cause confusion on next attempt

**Action:** Add session clearing in catch block OR ensure AJAX handler clears on next attempt (already does this).

## ✅ What's Working Correctly

1. **Fresh Order Creation:** ✅ AJAX handler clears old order before creating new one
2. **One-Time URL Protection:** ✅ Checks session before loading iframe
3. **Session Clearing on Leave:** ✅ Back button and page unload both clear session
4. **Webhook Cleanup:** ✅ Clears all session data after payment

## 📋 Recommended Changes

### Change 1: Remove Dead Code
Delete unused `store_checkout_url()` method.

### Change 2: Fix Misleading Comments
Update comments to reflect that cart restoration is intentionally disabled for fresh checkout requirement.

### Change 3: Disable Cart Restoration Hook (Optional)
Since cart restoration conflicts with your requirement, consider removing or disabling `maybe_restore_cart_from_pending_order()` hook.

### Change 4: Ensure Consistent Session Clearing
Verify that all paths clear session correctly (already mostly done, but verify edge cases).

## 📊 Session Variables Lifecycle

| Variable | Set When | Cleared When | Purpose |
|----------|----------|--------------|---------|
| `coinsub_order_id` | Order created (AJAX) | User leaves checkout OR new order created | Track current order |
| `coinsub_checkout_url_{order_id}` | Payment processed | User leaves checkout | One-time checkout URL |
| `coinsub_pending_order_id` | Payment processed | User leaves checkout OR webhook completes | ~~Cart restoration~~ (not used) |
| `coinsub_purchase_session_id` | Payment processed | User leaves checkout OR webhook completes | Track purchase session |

## 🎯 Current Flow Summary

**Intended Behavior (Per Your Requirements):**
1. User places order → New order + purchase session created
2. User goes to checkout page → Iframe loads with one-time URL
3. User leaves checkout page → Session cleared (no reuse)
4. User returns → Fresh order created (no cart restoration)

**Actual Behavior:** ✅ Matches intended behavior (cart restoration is disabled, which is correct)

## 🔧 Action Items

1. ✅ **Remove unused `store_checkout_url()` method** (dead code)
2. ⚠️ **Update misleading comments** about cart restoration
3. ⚠️ **Consider removing `maybe_restore_cart_from_pending_order()` hook** (doesn't work anyway)
4. ✅ **Verify all session clearing is consistent** (mostly correct)
