# WooCommerce Blocks (Block Checkout) Integration — Developer Notes

This plugin supports two WooCommerce checkout flavors:

1. **Classic checkout** (shortcode `[woocommerce_checkout]`) — fully working today.
   All logic lives in `includes/sp-checkout-modal.php`.

2. **Block checkout** (`<!-- wp:woocommerce/checkout -->`) — scaffolded but
   currently disabled. Once finished, merchants can use either checkout
   without the plugin caring which.

This document explains how to finish step 2.

---

## Architecture

| Layer | File(s) | Status |
|---|---|---|
| PHP integration | `includes/class-sp-blocks-payment-method.php` | ✅ done |
| Plugin registration | `stablecoin-pay.php` (`woocommerce_blocks_loaded` hook) | ✅ done |
| Build tooling | `package.json` + `@wordpress/scripts` | ✅ done |
| React entry | `src/blocks/index.js` (`registerPaymentMethod`) | ✅ done |
| Label component | `src/blocks/label.js` | ✅ done |
| Editor preview | `src/blocks/edit.js` | ✅ done |
| Content / payment flow | `src/blocks/content.js` (`onPaymentSetup`) | 🟡 skeleton — **needs work** |
| Enabling flag | `COINSUB_BLOCKS_CHECKOUT_ENABLED` constant in `stablecoin-pay.php` | 🟡 set to `false` until ready |

---

## Build the JS bundle (one-time setup + every JS change)

```bash
# One-time
npm install

# Every time JS changes
npm run build         # writes build/index.js + build/index.asset.php
# OR
npm start             # watch mode
```

Requirements:

- Node 18+ (`node --version`)
- npm (bundled with Node)

The build is also run automatically by `./create-plugin-package.sh` before
the zip is produced. If Node isn't installed on the machine running the
build script, the zip still ships — just without the `build/` directory,
which keeps the block integration silently disabled.

---

## What's left to implement in `src/blocks/content.js`

The skeleton is correct: it registers an `onPaymentSetup` callback,
returns a Promise, and hits `admin-ajax.php?action=coinsub_process_payment`
to get a hosted-checkout URL.

Three things to finish, marked `TODO (n/3)` in the file:

1. **Send the customer's billing/shipping address** in the AJAX body. The
   classic flow's payload shape is in `includes/sp-checkout-modal.php`
   around line 340. Pull values from `billingRef.current.billingAddress`
   and `shippingRef.current.shippingAddress`.

2. **Open the hosted-checkout iframe and wait for completion.** The classic
   flow's `setupCoinSubIframeRedirectDetection()` watches the iframe for a
   navigation to `/checkout/order-received/`. Port that to React — likely a
   `postMessage` listener or a polling check against the WooCommerce REST
   API for the order status.

3. **Resolve the Promise** with `{ type: SUCCESS, meta: { paymentMethodData } }`
   once payment is confirmed (or `ERROR` with a customer-visible message
   otherwise).

---

## Turning the block integration ON

Once `content.js` is finished and tested:

1. Run `npm run build` (writes `build/index.js`).
2. In `stablecoin-pay.php`, set:

   ```php
   if (!defined('COINSUB_BLOCKS_CHECKOUT_ENABLED')) {
       define('COINSUB_BLOCKS_CHECKOUT_ENABLED', true);
   }
   ```

3. Test on a fresh WooCommerce install whose Checkout page contains
   `<!-- wp:woocommerce/checkout -->`. The "Pay with Crypto" option should
   appear in the payment methods list alongside Stripe / PayPal / etc.

4. Verify the admin notice in
   `includes/class-sp-checkout-page-checker.php` stops warning about
   block-based checkout pages (it self-silences when the constant is `true`).

5. Run a full purchase end-to-end and confirm the webhook still marks the
   order `processing` (the server-side `process_payment` and webhook flow
   are unchanged — only the front-end UX is new).

---

## Reference reading

- WooCommerce Blocks payment-method integration docs:
  https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/checkout-payment-methods/payment-method-integration.md
- `AbstractPaymentMethodType` source:
  https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Blocks/Payments/Integrations/AbstractPaymentMethodType.php
- `@wordpress/scripts` build tooling:
  https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
