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
| Content / payment flow | `src/blocks/content.js` (`onPaymentSetup`) | ✅ done — inline iframe + redirect detection |
| Enabling flag | `COINSUB_BLOCKS_CHECKOUT_ENABLED` constant in `stablecoin-pay.php` | ✅ set to `true` |

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

## Payment flow in `src/blocks/content.js`

The block-checkout flow mirrors the classic-checkout flow from
`includes/sp-checkout-modal.php` — inline iframe on the checkout page, no
top-level redirect to a separate payment page. Specifically:

1. `onPaymentSetup` POSTs the customer's billing/shipping address to
   `admin-ajax.php?action=coinsub_process_payment` (same endpoint the
   classic flow uses), which creates the WooCommerce order, opens a
   Coinsub purchase session, and returns the hosted-checkout URL.

2. The component mounts that URL in an inline `<iframe>` styled the
   same as the classic flow's `#coinsub-checkout-container`. The
   `onPaymentSetup` Promise deliberately **never resolves** — block
   checkout's "Processing…" state on the Place Order button is the
   correct in-flight indicator while the iframe is open.

3. A `postMessage` listener and a polling fallback watch for the
   hosted checkout to signal completion (same logic as
   `setupCoinSubIframeRedirectDetection()` in the classic flow). When
   the customer finishes paying, the top-level browser is navigated
   to `/checkout/order-received/<id>/?key=…`, which unmounts the
   block-checkout React tree entirely. The server-side webhook is
   what actually marks the order `processing` — that's identical
   between the two flows.

### Why the Promise never resolves

Resolving `onPaymentSetup` with `SUCCESS` would let block checkout
submit to its own `/wp-json/wc/store/v1/checkout` endpoint, which
would create a **second** WooCommerce order on top of the one our
AJAX already created. Keeping the Promise pending and doing the
top-level navigation ourselves avoids the duplicate-order problem
and keeps the order lifecycle byte-for-byte identical to classic
checkout.

---

## Testing checklist

1. Run `npm run build` (writes `build/index.js` + `build/index.asset.php`).
2. Build the plugin zip: `./create-plugin-package.sh` — it runs the
   JS build internally so the production zip ships with `build/`.
3. Upload to a test WordPress site whose Checkout page contains
   `<!-- wp:woocommerce/checkout -->` (the native block checkout, not the
   classic `[woocommerce_checkout]` shortcode).
4. Confirm "Pay with Crypto" appears in the payment methods list
   alongside any other gateways.
5. Click Place Order and verify:
   - The iframe mounts inline on the same /checkout/ page.
   - The Place Order button shows the "Processing…" spinner.
   - Completing payment inside the iframe navigates the top-level
     browser to /checkout/order-received/<id>/?key=… without an
     intermediate redirect to a separate page.
6. Confirm the admin notice in
   `includes/class-sp-checkout-page-checker.php` stops warning about
   block-based checkout pages (it self-silences when the constant is `true`).
7. Confirm the server-side flow is unchanged:
   - The WooCommerce order moves from `pending` → `on-hold` (set in
     `process_payment`) → `processing` (set by the webhook).
   - The Coinsub webhook fires and marks the order `processing` exactly
     once.

---

## Reference reading

- WooCommerce Blocks payment-method integration docs:
  https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/checkout-payment-methods/payment-method-integration.md
- `AbstractPaymentMethodType` source:
  https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Blocks/Payments/Integrations/AbstractPaymentMethodType.php
- `@wordpress/scripts` build tooling:
  https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
