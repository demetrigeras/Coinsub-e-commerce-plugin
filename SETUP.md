# CoinSub for WooCommerce - Setup Guide

## Installation

1. Upload `coinsub-commerce.zip` to WordPress: Plugins → Add New → Upload
2. Activate the plugin
3. Go to: WooCommerce → Settings → Payments
4. Click "Manage" next to CoinSub
5. Check "Enable CoinSub"
6. Enter your Merchant ID and API Key
7. Save changes

## Testing

1. Add product to cart
2. Go to checkout
3. Select CoinSub as payment method
4. Click "Pay with Crypto"
5. Complete payment on CoinSub page

## Configuration

- **Merchant ID**: Get from CoinSub dashboard
- **API Key**: Get from CoinSub dashboard
- **Test Mode**: Enable for testing with test credentials
- **Webhook URL**: Automatically generated, add to CoinSub dashboard

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+

## Support

For issues, check error logs at: `wp-content/debug.log`

