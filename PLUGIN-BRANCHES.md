# CoinSub Plugin Branches

This repository maintains two separate branches for different plugin versions:

## Branches
//
### `main` branch (Production - FOR MERCHANTS)
- **Plugin Name**: "Coinsub"
- **Features**: Production-ready, no environment selector
- **API**: Always uses `https://app.coinsub.io/v1`
- **Distribution**: ✅ **PUBLIC** - Distribute to merchants
- **ZIP File**: `coinsub-commerce.zip`
//
### `dev` branch (Development - INTERNAL USE ONLY)
- **Plugin Name**: "Coinsub Dev"
- **Features**: Environment selector (dev, test, staging, production)
- **API**: Selectable via settings dropdown
- **Distribution**: ❌ **INTERNAL ONLY** - Never distribute to merchants
- **Use Case**: Internal testing on dev.coinsubcommerce.com
- **ZIP File**: `coinsub-commerce-dev.zip`

## Workflow

### Creating ZIP Files

**For Production (Merchants):**
```bash
git checkout main
zip -r coinsub-commerce.zip . \
  -x "*.git*" \
  -x "PLUGIN-BRANCHES.md" \
  -x "SUBSCRIPTIONS-GUIDE.md" \
  -x "TESTING-GUIDE.md" \
  -x "WEBHOOK-FLOW.md" \
  -x "QUICK-REFERENCE.md" \
  -x "test-*.php" \
  -x "*.sh" \
  -x ".DS_Store"
```

**For Development (Internal Testing):**
```bash
git checkout dev
zip -r coinsub-commerce-dev.zip . \
  -x "*.git*" \
  -x "PLUGIN-BRANCHES.md" \
  -x "SUBSCRIPTIONS-GUIDE.md" \
  -x "TESTING-GUIDE.md" \
  -x "WEBHOOK-FLOW.md" \
  -x "QUICK-REFERENCE.md" \
  -x "test-*.php" \
  -x "*.sh" \
  -x ".DS_Store"
```

**Note:** `README.md` is included in both ZIPs for merchants to read.

### Installing in WooCommerce

1. **Merchant Production Sites** (What merchants download):
   - Upload `coinsub-commerce.zip` (from `main` branch)
   - Plugin appears as "Coinsub"
   - Gateway ID: `coinsub`
   - No environment selector (always production)
   - Simple, clean interface for merchants

2. **Internal Testing Site** (dev.coinsubcommerce.com):
   - Upload `coinsub-commerce-dev.zip` (from `dev` branch)
   - Plugin appears as "Coinsub Dev"
   - Gateway ID: `coinsub-dev`
   - Has environment dropdown for testing dev/test/staging/production
   - Use this to test new features, products, and bug fixes

### Important Notes

- **⚠️ CRITICAL**: Never distribute the `dev` branch ZIP to merchants - it's for internal testing only
- **Merchants only get**: `coinsub-commerce.zip` from `main` branch (production only)
- The dev plugin is only for testing on your internal site (dev.coinsubcommerce.com)
- Both plugins use different gateway IDs (`coinsub` vs `coinsub-dev`) so they won't conflict if both installed
- Always build ZIPs from the correct branch before uploading

