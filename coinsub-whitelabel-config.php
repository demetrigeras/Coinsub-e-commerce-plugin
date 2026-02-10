<?php
/**
 * WHITELABEL / DOWNLOADABLE BUILD CONFIG
 *
 * This file (together with create-plugin-package.sh) is the ONLY place to
 * hardcode partner-specific values for a downloadable plugin build.
 *
 * - For Stablecoin Pay (default): leave environment_id null or omit this file.
 * - For a partner build (e.g. Payment Servers): set the values below, then run
 *   ./create-plugin-package.sh to produce the zip.
 */
if (!defined('ABSPATH')) {
    exit;
}

return array(
    // Partner API environment (e.g. paymentservers.com). Null = Stablecoin Pay.
    'environment_id' => 'paymentservers.com',

    // Slug for URLs/assets (e.g. payment-servers).
    'slug' => 'payment-servers',

    // Display name used everywhere in the plugin (admin, gateway, Plugins list).
    'plugin_name' => 'Payment Servers',

    // Where merchants sign up and manage their account (used in setup instructions and field descriptions).
    'dashboard_url' => 'https://app.paymentservers.com',

    // Logo/icon shown next to the gateway name on the Payments list (WooCommerce → Settings → Payments). Optional; if empty, Payment Servers uses images/paymentservers-favicon.png, default build uses coinsub.svg.
    'favicon_url' => '',

    // Checkout: logo/icon and button (optional). If set, checkout uses this instead of any API/database lookup. If empty, uses favicon_url then fallbacks (Payment Servers: images/paymentservers-logo.png, default: coinsub.svg).
    'checkout_logo_url' => '',

    // Zip filename produced by create-plugin-package.sh when this config is present.
    'zip_name' => 'payment-servers-plugin.zip',
);
