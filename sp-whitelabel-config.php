<?php
/**
 * BRANDING / DOWNLOADABLE BUILD CONFIG
 *
 * Single source for partner identity. When this file exists and returns a non-empty array,
 * the plugin uses plugin_name and logo_url for the gateway title, button, and admin (no API lookup).
 *
 * - File missing or plugin_name not set: build uses default name "CoinSub" and no logo URL.
 * - File present with plugin_name (and optional logo_url, dashboard_url): partner build.
 *
 * Run ./create-plugin-package.sh to build the zip. Set logo_url to full URL; leave empty for no icon.
 */
if (!defined('ABSPATH')) {
    exit;
}

return array(
    // Partner API environment (e.g. paymentservers.com). Optional; used by build script.
    'environment_id' => 'paymentservers.com',

    // Display name used everywhere (admin, gateway title, checkout button).
    'plugin_name' => 'Payment Servers',

    // Where merchants sign up and manage their account (setup instructions, field descriptions).
    'dashboard_url' => 'https://app.paymentservers.com',

    // Logo: full URL. Empty = no logo in gateway list or on button.
    'logo_url' => 'https://app.paymentservers.com/img/domain/paymentservers/paymentservers.square.dark.png',

    // Zip filename produced by create-plugin-package.sh when this config is present.
    'zip_name' => 'payment-servers-plugin.zip',
);
