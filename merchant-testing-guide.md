# 🏪 CoinSub Merchant Testing Guide

## **Real-World Testing as a Merchant**

This guide walks you through setting up and testing your CoinSub e-commerce store **exactly like a real merchant would**.

---

## 🚀 **Step 1: Set Up Your Local Store**

### **Run the Setup Script:**
```bash
./setup-local-wordpress.sh
```

**What this does:**
- ✅ Creates a **real WordPress site** with WooCommerce
- ✅ Installs **Storefront theme** (professional e-commerce theme)
- ✅ Creates **sample products** (CoinSub T-Shirt, Hoodie, Mug)
- ✅ Sets up **shipping zones** and **payment methods**
- ✅ Creates **admin user** (admin/admin)
- ✅ Configures **basic store settings**

### **Access Your Store:**
- **🌐 Store Frontend**: http://localhost:8888
- **👤 Admin Dashboard**: http://localhost:8888/wp-admin
- **👤 Username**: admin
- **🔑 Password**: admin

---

## 🛒 **Step 2: Set Up Your Products (Like a Real Merchant)**

### **A. Customize Your Store:**
1. **Go to**: Appearance → Customize
2. **Set your store name**: "CoinSub Merchandise Store"
3. **Upload logo**: Add your CoinSub logo
4. **Choose colors**: Match your brand colors
5. **Save changes**

### **B. Add Real Products:**
1. **Go to**: Products → Add New
2. **Add product details**:
   - **Name**: "Official CoinSub T-Shirt"
   - **Description**: "High-quality cotton t-shirt with CoinSub logo"
   - **Price**: $25.00
   - **SKU**: COINSUB-TSHIRT-001
   - **Stock**: 100 units
   - **Images**: Upload product photos
3. **Publish product**

### **C. Set Up Product Categories:**
1. **Go to**: Products → Categories
2. **Create categories**:
   - Clothing
   - Accessories
   - Digital Products
3. **Assign products** to categories

---

## 💳 **Step 3: Install CoinSub Payment Plugin**

### **A. Upload Plugin:**
1. **Go to**: Plugins → Add New → Upload Plugin
2. **Select**: `coinsub-commerce.zip`
3. **Click**: Install Now → Activate

### **B. Configure Payment Settings:**
1. **Go to**: WooCommerce → Settings → Payments
2. **Find**: CoinSub payment method
3. **Click**: Manage
4. **Configure**:
   - ✅ **Enable CoinSub**: Check the box
   - 🔑 **Merchant ID**: Your CoinSub Merchant ID
   - 🔑 **API Key**: Your CoinSub API Key
   - 🧪 **Test Mode**: Enable for testing
   - 💰 **Include Shipping in Crypto**: Yes
   - 💰 **Include Tax in Crypto**: Yes
5. **Save changes**

---

## 🚚 **Step 4: Set Up Shipping (Like a Real Merchant)**

### **A. Configure Shipping Zones:**
1. **Go to**: WooCommerce → Settings → Shipping
2. **Add shipping zones**:
   - **United States** (US)
   - **Europe** (EU countries)
   - **Rest of World** (Other countries)

### **B. Add Shipping Methods:**
1. **For US Zone**:
   - **Flat Rate**: $5.00 (Standard shipping)
   - **Free Shipping**: Orders over $50
2. **For Europe Zone**:
   - **Flat Rate**: $10.00 (International shipping)
3. **For Rest of World**:
   - **Flat Rate**: $15.00 (International shipping)

### **C. Test Shipping Calculation:**
1. **Add product to cart**
2. **Go to checkout**
3. **Enter different addresses**
4. **Verify shipping costs** are calculated correctly

---

## 🧪 **Step 5: Test Complete Checkout Process**

### **A. Test as Customer:**
1. **Visit**: http://localhost:8888
2. **Add products to cart**:
   - CoinSub T-Shirt ($25.00)
   - CoinSub Mug ($15.00)
   - **Total**: $40.00 + $5.00 shipping = $45.00
3. **Proceed to checkout**
4. **Fill in customer details**:
   - Name: Test Customer
   - Email: test@example.com
   - Address: 123 Test Street, Test City, TS 12345
5. **Select CoinSub payment method**
6. **Complete payment** (this will open CoinSub checkout)

### **B. Test Payment Flow:**
1. **CoinSub checkout opens** in new tab
2. **Complete payment** with test USDC
3. **Return to store** after payment
4. **Verify order status** updates to "Completed"
5. **Check order details** in WooCommerce admin

---

## 📊 **Step 6: Manage Orders (Like a Real Merchant)**

### **A. View Orders:**
1. **Go to**: WooCommerce → Orders
2. **See completed orders**
3. **Check order details**:
   - Customer information
   - Products ordered
   - Payment amount
   - Shipping address

### **B. Process Orders:**
1. **Print order details**
2. **Pack products** (simulate)
3. **Create shipping label** (simulate)
4. **Update order status** to "Shipped"
5. **Send tracking information** to customer

---

## 🔧 **Step 7: Test Different Scenarios**

### **A. Test Different Products:**
- **Single product** orders
- **Multiple product** orders
- **High-value** orders (free shipping)
- **Low-value** orders (with shipping)

### **B. Test Different Addresses:**
- **US addresses** (standard shipping)
- **International addresses** (higher shipping)
- **Different states** (tax calculations)

### **C. Test Payment Methods:**
- **CoinSub payment** (crypto)
- **Cash on Delivery** (fallback)
- **Different currencies** (if applicable)

---

## 🎯 **Step 8: Real-World Merchant Tasks**

### **A. Daily Operations:**
1. **Check new orders** every morning
2. **Process orders** (pack and ship)
3. **Update inventory** levels
4. **Handle customer inquiries**
5. **Monitor payment status**

### **B. Weekly Tasks:**
1. **Review sales reports**
2. **Check inventory** levels
3. **Update product** information
4. **Test payment** systems
5. **Backup store** data

### **C. Monthly Tasks:**
1. **Analyze sales** performance
2. **Update product** catalog
3. **Review shipping** costs
4. **Optimize store** performance
5. **Plan marketing** campaigns

---

## 🚨 **Common Issues & Solutions**

### **Payment Not Processing:**
- ✅ Check API credentials
- ✅ Verify test mode settings
- ✅ Check webhook configuration
- ✅ Review error logs

### **Shipping Not Calculating:**
- ✅ Check shipping zones
- ✅ Verify shipping methods
- ✅ Test with different addresses
- ✅ Check product weights

### **Orders Not Updating:**
- ✅ Verify webhook URL
- ✅ Check webhook configuration
- ✅ Review payment status
- ✅ Test webhook manually

---

## 🎊 **Success Checklist**

- ✅ **Store is live** and accessible
- ✅ **Products are added** and visible
- ✅ **Payment plugin** is installed and configured
- ✅ **Shipping is calculated** correctly
- ✅ **Checkout process** works end-to-end
- ✅ **Orders are processed** successfully
- ✅ **Webhook notifications** are received
- ✅ **Order status** updates automatically

---

## 🚀 **Next Steps After Testing**

1. **Deploy to production** server
2. **Set up domain** and SSL
3. **Configure production** API keys
4. **Set up real** webhook URLs
5. **Launch store** publicly
6. **Start marketing** and sales

**You're now ready to run a real CoinSub e-commerce store!** 🎉
