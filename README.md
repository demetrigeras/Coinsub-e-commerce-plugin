# CoinSub Commerce - WooCommerce Plugin

Accept cryptocurrency payments with CoinSub. This plugin integrates CoinSub's payment gateway with WooCommerce, supporting flexible shipping and tax configurations.

## 🚀 Quick Start

### 1. Install Plugins
- **Main Plugin**: Upload `coinsub-commerce.zip` to WordPress
- **Shipping Plugin**: Upload `coinsub-shipping-method.zip` to WordPress
- **Activate both plugins**

### 2. Configure Settings
- **Go to**: WooCommerce → Settings → Payments → CoinSub
- **Add your API credentials**:
  - Merchant ID
  - API Key
- **Configure shipping and tax** settings

### 3. Test Your Store
- **Follow**: `merchant-testing-guide.md`
- **Set up local WordPress**: `quick-local-setup.md`

## 📦 What's Included

### **Core Plugin Files:**
- `coinsub-commerce.php` - Main plugin file
- `coinsub-commerce.zip` - Ready-to-install package
- `includes/` - Plugin classes and functionality

### **Shipping Plugin:**
- `coinsub-shipping-method.php` - Shipping method plugin
- `coinsub-shipping-method.zip` - Ready-to-install package

### **Documentation:**
- `README.md` - This file
- `merchant-testing-guide.md` - Complete merchant testing guide
- `quick-local-setup.md` - Local WordPress setup instructions

## 🔧 Features

- ✅ **Cryptocurrency Payments**: Accept USDC and other stablecoins
- ✅ **Flexible Shipping & Tax**: Configure what to include in crypto payments
- ✅ **Multiple Payment Methods**: All-in-crypto, hybrid, or separate payments
- ✅ **Webhook Integration**: Automatic order status updates
- ✅ **Rich Metadata**: Detailed order information in CoinSub
- ✅ **Auto URL Opening**: Checkout URLs open automatically
- ✅ **Low-cost Testing**: Optimized for testing with small amounts

## 📋 Requirements

- WordPress 5.0 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## 🚀 Installation

### Method 1: Upload Plugin Files
1. **Download the plugin files**
2. **Upload to WordPress**:
   - Go to WordPress Admin → Plugins → Add New → Upload Plugin
   - Select the plugin ZIP file
   - Click "Install Now" and then "Activate"

### Method 2: Manual Installation
1. **Upload files** to `/wp-content/plugins/coinsub-commerce/` directory
2. **Activate the plugin** through the 'Plugins' menu in WordPress
3. **Configure settings** in WooCommerce → Settings → Payments → CoinSub

## ⚙️ Configuration

### 1. Basic Settings
1. Go to **WooCommerce → Settings → Payments**
2. Find **CoinSub** and click **Manage**
3. Configure the following:

**API Settings:**
- **Merchant ID**: Your CoinSub Merchant ID
- **API Key**: Your CoinSub API Key
- **Test Mode**: Enable for testing

**Payment Configuration:**
- **Include Shipping in Crypto Payment**: Yes/No
- **Include Tax in Crypto Payment**: Yes/No
- **Shipping Payment Method**: How to handle shipping when not in crypto
- **Tax Payment Method**: How to handle tax when not in crypto

### 2. Webhook Setup
1. **Copy the Webhook URL** from the plugin settings
2. **Configure in CoinSub Dashboard**:
   - Go to your CoinSub merchant dashboard
   - Navigate to Webhooks section
   - Add the webhook URL: `https://yoursite.com/wp-json/coinsub/v1/webhook`
   - Set event type to "Payment Completed"

### 3. Shipping Setup
1. Go to **WooCommerce → Settings → Shipping**
2. **Add shipping zones** (US, Europe, etc.)
3. **Add CoinSub Shipping method** to each zone
4. **Set shipping costs** and thresholds

## 🧪 Testing

### Test with Small Amounts
- Use $0.01 for products
- Use $0.05 for shipping
- Use $0.02 for tax
- Total: $0.08 (perfect for testing)

### Test Payment Flow
1. Add product to cart
2. Proceed to checkout
3. Select CoinSub payment method
4. Complete payment on CoinSub
5. Verify order status updates

## 🚨 Troubleshooting

### Common Issues

**Payment not processing:**
- Check API credentials
- Verify test mode settings
- Check webhook URL configuration

**Order status not updating:**
- Verify webhook URL is accessible
- Check webhook configuration in CoinSub
- Review error logs

**Shipping/tax not included:**
- Check plugin configuration
- Verify WooCommerce shipping/tax settings
- Review payment breakdown in metadata

### Debug Mode

Enable WordPress debug mode to see detailed logs:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## 📞 Support

- **Documentation**: [CoinSub Documentation](https://docs.coinsub.io)
- **Support**: [CoinSub Support](https://support.coinsub.io)
- **GitHub**: [Plugin Repository](https://github.com/coinsub/woocommerce-plugin)

## 📝 Changelog

### Version 1.0.0
- Initial release
- Basic payment processing
- Shipping and tax configuration
- Webhook integration
- Auto URL opening
- Flexible payment methods

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 👥 Credits

Developed by CoinSub for the WooCommerce community.