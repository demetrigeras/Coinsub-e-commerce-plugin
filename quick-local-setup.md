# 🚀 Quick Local WordPress Setup (No Docker Required)

## **Option 1: Use Local by Flywheel (Recommended)**

### **Download & Install:**
1. **Download Local**: https://localwp.com/
2. **Install Local** (free, no Docker required)
3. **Create new site**:
   - **Site name**: "CoinSub Test Store"
   - **WordPress version**: Latest
   - **PHP version**: 8.0+
   - **Web server**: Nginx
   - **Database**: MySQL

### **Install WooCommerce:**
1. **Open your site** in Local
2. **Go to WP Admin** (click "WP Admin" button)
3. **Install WooCommerce**:
   - Plugins → Add New
   - Search "WooCommerce"
   - Install and activate
   - Run setup wizard

### **Install CoinSub Plugin:**
1. **Upload our plugin**:
   - Plugins → Add New → Upload Plugin
   - Select `coinsub-commerce.zip`
   - Install and activate

---

## **Option 2: Use XAMPP (Traditional)**

### **Download & Install:**
1. **Download XAMPP**: https://www.apachefriends.org/
2. **Install XAMPP**
3. **Start Apache and MySQL**

### **Set Up WordPress:**
1. **Download WordPress**: https://wordpress.org/download/
2. **Extract to XAMPP htdocs**:
   - Mac: `/Applications/XAMPP/htdocs/coinsub-store/`
   - Windows: `C:\xampp\htdocs\coinsub-store\`
3. **Create database**:
   - Open phpMyAdmin: http://localhost/phpmyadmin
   - Create database: `coinsub_store`
4. **Install WordPress**:
   - Visit: http://localhost/coinsub-store
   - Complete installation wizard

### **Install Plugins:**
1. **Install WooCommerce** (same as above)
2. **Install CoinSub plugin** (same as above)

---

## **Option 3: Use MAMP (Mac Only)**

### **Download & Install:**
1. **Download MAMP**: https://www.mamp.info/
2. **Install MAMP**
3. **Start servers**

### **Set Up WordPress:**
1. **Download WordPress**
2. **Extract to MAMP htdocs**: `/Applications/MAMP/htdocs/coinsub-store/`
3. **Create database** in phpMyAdmin
4. **Install WordPress** at http://localhost:8888/coinsub-store

---

## **Option 4: Use WordPress.com (Easiest)**

### **Create Free Site:**
1. **Go to**: https://wordpress.com/
2. **Create free account**
3. **Choose subdomain**: `coinsub-test-store.wordpress.com`
4. **Select e-commerce plan** (free trial)

### **Install Plugins:**
1. **Go to WP Admin**
2. **Install WooCommerce** (if not included)
3. **Upload CoinSub plugin** (if allowed on free plan)

---

## **🎯 My Recommendation: Use Local by Flywheel**

**Why Local is best:**
- ✅ **No Docker required**
- ✅ **One-click WordPress setup**
- ✅ **Built-in WooCommerce support**
- ✅ **Easy plugin installation**
- ✅ **Professional development environment**
- ✅ **Free to use**

---

## **📋 After Setup - Follow This Guide:**

1. **Complete WordPress installation**
2. **Install WooCommerce** and run setup wizard
3. **Install CoinSub Commerce plugin**
4. **Follow the Merchant Testing Guide** I created
5. **Test the complete checkout process**

---

## **🚀 Quick Start Commands:**

```bash
# If you choose Local by Flywheel:
# 1. Download and install Local
# 2. Create new site
# 3. Install WooCommerce
# 4. Upload our plugin
# 5. Start testing!

# If you choose XAMPP:
./setup-local-xampp.sh
# Then follow the manual steps
```

**Which option would you prefer to use?** I recommend **Local by Flywheel** as it's the easiest and most professional approach.
