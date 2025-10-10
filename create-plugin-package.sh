#!/bin/bash

# CoinSub Commerce Plugin Package Creator
# This script creates a deployable WordPress plugin package

echo "🚀 Creating Coinsub Commerce Plugin Package..."

# Create package directory
PACKAGE_DIR="coinsub-commerce-plugin"
mkdir -p "$PACKAGE_DIR"

# Copy main plugin file
echo "📦 Copying main plugin file..."
cp coinsub-commerce.php "$PACKAGE_DIR/"

# Copy includes directory
echo "📦 Copying includes directory..."
cp -r includes "$PACKAGE_DIR/"

# Copy images directory (if it exists)
if [ -d "images" ]; then
    echo "📦 Copying images directory..."
    cp -r images "$PACKAGE_DIR/"
else
    echo "📦 Creating images directory..."
    mkdir -p "$PACKAGE_DIR/images"
fi

# Copy README
echo "📦 Copying documentation..."
cp README.md "$PACKAGE_DIR/"

# Create uninstall.php
echo "📦 Creating uninstall script..."
cat > "$PACKAGE_DIR/uninstall.php" << 'EOF'
<?php
/**
 * Uninstall script for CoinSub Commerce
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up plugin data
delete_option('woocommerce_coinsub_settings');

// Flush rewrite rules
flush_rewrite_rules();
EOF

# Create languages directory
echo "📦 Creating languages directory..."
mkdir -p "$PACKAGE_DIR/languages"

# Create .gitignore
echo "📦 Creating .gitignore..."
cat > "$PACKAGE_DIR/.gitignore" << 'EOF'
# WordPress
wp-config.php
wp-content/uploads/
wp-content/blogs.dir/
wp-content/upgrade/
wp-content/backup-db/
wp-content/advanced-cache.php
wp-content/wp-cache-config.php
wp-content/cache/
wp-content/cache/supercache/

# Logs
*.log

# OS
.DS_Store
Thumbs.db

# IDE
.vscode/
.idea/
*.swp
*.swo

# Node
node_modules/
npm-debug.log

# Composer
vendor/
composer.lock
EOF

# Create zip package
echo "📦 Creating ZIP package..."
cd "$PACKAGE_DIR"
zip -r "../coinsub-commerce.zip" . -x "*.DS_Store" "*.git*"
cd ..

# Clean up
echo "🧹 Cleaning up..."
rm -rf "$PACKAGE_DIR"

echo "✅ Plugin package created successfully!"
echo "📁 Package: coinsub-commerce.zip"
echo "📋 Size: $(du -h coinsub-commerce.zip | cut -f1)"
echo ""
echo "🚀 Ready for deployment!"
echo "1. Upload coinsub-commerce.zip to WordPress"
echo "2. Activate the plugin"
echo "3. Configure settings in WooCommerce"
echo "4. Set up webhook in Coinsub dashboard"
