#!/bin/bash

# Plugin Package Creator
#
# Partner-specific values: ONLY in sp-whitelabel-config.php (and this script
# reading that file). To build a partner zip, set environment_id + plugin_name + zip_name
# in the config, then run this script. For Stablecoin Pay (default), omit the config
# or set environment_id null.

# Always run from the directory where this script lives
cd "$(dirname "$0")"

echo "🚀 Creating Plugin Package..."
echo "📂 Working directory: $(pwd)"

# Resolve zip name early so we can remove old build artifacts
ZIP_NAME="stablecoin-pay.zip"
if [ -f "sp-whitelabel-config.php" ]; then
    EXTRACTED=$(grep -E "'zip_name'|\"zip_name\"" sp-whitelabel-config.php | sed -n "s/.*['\"]zip_name['\"][^'\"]*['\"]\\([^'\"]*\\)['\"].*/\1/p" | tr -d ' ')
    [ -n "$EXTRACTED" ] && ZIP_NAME="$EXTRACTED"
fi

# Clean previous build so we always ship latest (avoids stale/cached zip)
PACKAGE_DIR="stablecoin-pay-plugin"
rm -rf "$PACKAGE_DIR"
rm -f "$ZIP_NAME"
echo "🧹 Cleaned previous build (fresh package)"

mkdir -p "$PACKAGE_DIR"

# Copy main plugin file
echo "📦 Copying main plugin file..."
cp stablecoin-pay.php "$PACKAGE_DIR/"

# Whitelabel build: config file is the single source (zip name, plugin name in header, etc.)
WHITELABEL_BUILD=0

if [ -f "sp-whitelabel-config.php" ]; then
    echo "📦 Copying whitelabel config (partner build)..."
    cp sp-whitelabel-config.php "$PACKAGE_DIR/"
    WHITELABEL_BUILD=1
    echo "📦 Partner zip name: $ZIP_NAME"
    # Rewrite Plugin Name in main file header so it shows whitelabel name when plugin is inactive/deactivated
    PLUGIN_NAME=$(grep -E "'plugin_name'|\"plugin_name\"" sp-whitelabel-config.php | sed -n "s/.*=> *['\"]\\([^'\"]*\\)['\"].*/\1/p" | sed 's/^ *//;s/ *$//')
    if [ -n "$PLUGIN_NAME" ]; then
        # Use # delimiter so plugin names containing / are safe
        if [[ "$(uname)" = "Darwin" ]]; then
            sed -i '' "s#^ \* Plugin Name: .*# * Plugin Name: $PLUGIN_NAME#" "$PACKAGE_DIR/stablecoin-pay.php"
        else
            sed -i "s#^ \* Plugin Name: .*# * Plugin Name: $PLUGIN_NAME#" "$PACKAGE_DIR/stablecoin-pay.php"
        fi
        echo "📦 Plugin header set to: $PLUGIN_NAME (shows correctly when deactivated)"
    fi
else
    echo "📦 No whitelabel config - package will run as Stablecoin Pay (default)"
fi

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

# Copy bundled plugins (required dependencies)
if [ -d "bundled-plugins" ]; then
    echo "📦 Copying bundled required plugins..."
    cp -r bundled-plugins "$PACKAGE_DIR/"
fi

# Copy README
echo "📦 Copying documentation..."
cp README.md "$PACKAGE_DIR/"

# Create uninstall.php
echo "📦 Creating uninstall script..."
cat > "$PACKAGE_DIR/uninstall.php" << 'EOF'
<?php
/**
 * Uninstall script for Stablecoin Pay
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

# Build info (so you can verify you have the latest when extracting)
echo "📦 Adding build info..."
BUILD_DATE=$(date -u +"%Y-%m-%d %H:%M:%S UTC")
GIT_REV=$(git rev-parse --short HEAD 2>/dev/null || echo "n/a")
echo "Build date: $BUILD_DATE" > "$PACKAGE_DIR/build-info.txt"
echo "Git commit: $GIT_REV" >> "$PACKAGE_DIR/build-info.txt"

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

# Create plugin .zip: prefer `zip` (Unix/macOS). Git for Windows Bash often has no `zip` — use PowerShell.
create_plugin_zip() {
    local pkg_dir="$1"
    local zip_name="$2"
    local root_dir
    root_dir="$(pwd)"

    if command -v zip >/dev/null 2>&1; then
        (cd "$pkg_dir" && zip -r "../$zip_name" . -x "*.DS_Store" "*.git*") || return 1
        [ -f "$zip_name" ] || return 1
        return 0
    fi

    if command -v powershell.exe >/dev/null 2>&1 && command -v cygpath >/dev/null 2>&1; then
        local src_win dst_win ps1_win
        src_win=$(cygpath -w "$root_dir/$pkg_dir")
        dst_win=$(cygpath -w "$root_dir/$zip_name")
        ps1_win=$(cygpath -w "$root_dir/zip-package.ps1")
        if [ ! -f "$root_dir/zip-package.ps1" ]; then
            echo "❌ Missing zip-package.ps1 next to create-plugin-package.sh" >&2
            return 1
        fi
        # Do not use Compress-Archive: it stores \ in entry names; Linux hosts then lack includes/foo.php
        MSYS2_ARG_CONV_EXCL='*' powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$ps1_win" \
            -SourceDir "$src_win" -DestinationZip "$dst_win" \
            || return 1
        [ -f "$zip_name" ] || return 1
        return 0
    fi

    echo "❌ No zip tool: install zip in Git Bash (e.g. pacman -S zip) or run on Windows with PowerShell." >&2
    return 1
}

# Create zip package
echo "📦 Creating ZIP package: $ZIP_NAME"
if ! create_plugin_zip "$PACKAGE_DIR" "$ZIP_NAME"; then
    echo "❌ ZIP creation failed." >&2
    exit 1
fi

# Clean up
echo "🧹 Cleaning up..."
rm -rf "$PACKAGE_DIR"

echo "✅ Plugin package created successfully!"
echo "📁 Package: $ZIP_NAME"
echo "📋 Size: $(du -h "$ZIP_NAME" | cut -f1)"

# Copy fixed-name zip to Downloads (overwrites previous - saves storage; no zips left in repo)
DOWNLOADS_DIR="$HOME/Downloads"
if [ -d "$DOWNLOADS_DIR" ]; then
    echo "📥 Copying to Downloads folder (overwrites previous)..."
    cp "$ZIP_NAME" "$DOWNLOADS_DIR/"
    echo "✅ Saved: $DOWNLOADS_DIR/$ZIP_NAME"
    rm -f "$ZIP_NAME"
else
    echo "⚠️  Downloads folder not found - zip left in project"
fi

echo ""
echo "🚀 Ready for deployment!"
echo "1. Upload the zip to WordPress (Plugins → Add New → Upload)"
echo "2. Activate the plugin"
echo "3. Configure settings in WooCommerce"
echo "4. Set up webhook in your dashboard"
