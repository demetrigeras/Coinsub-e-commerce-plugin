<?php
/**
 * Whitelabel config: set only environment_id. The plugin uses this to fetch
 * full config from your API (env_config table). Match is done by environment_id.
 *
 * - For Stablecoin Pay (no whitelabel): leave environment_id null.
 * - For whitelabel (e.g. Payment Servers): set environment_id to the partner's env.
 */
if (!defined('ABSPATH')) {
    exit;
}

return array(
    // 'environment_id' => null,
    // 'slug'          => null,
    // ⬇️ MANUALLY PUT THE ENVIRONMENT_ID HERE (e.g. paymentservers.com, bxnk.com). Use null for Stablecoin Pay.
    'environment_id' => 'paymentservers.com',
    'slug'          => 'payment-servers',
);
