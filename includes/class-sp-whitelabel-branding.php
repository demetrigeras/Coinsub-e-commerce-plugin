<?php
/**
 * CoinSub Whitelabel Branding Manager
 * 
 * Handles fetching and matching whitelabel branding based on merchant credentials
 */

if (!defined('ABSPATH')) {
    exit;
}

class CoinSub_Whitelabel_Branding {

    /**
     * Relative path to whitelabel config file (plugin root). When set, we fetch branding by environment_id instead of merchant_id.
     */
    const WHITELABEL_CONFIG_FILE = 'coinsub-whitelabel-config.php';

    /**
     * Database option key for branding data (persists in database, not transient)
     */
    const BRANDING_OPTION_KEY = 'coinsub_whitelabel_branding';
    const BRANDING_FETCH_LOCK_KEY = 'coinsub_whitelabel_fetching'; // Prevent multiple simultaneous fetches

    /**
     * No default branding - if branding is not found, return null/empty
     * This ensures the gateway doesn't show incorrect branding
     */

    /**
     * API client instance
     */
    private $api_client;

    /**
     * Get environment_id from whitelabel config file (if present). When set, we look up config by this id and whitelabel from there.
     *
     * @return string|null environment_id (e.g. bxnk.com) or null for Stablecoin Pay (no whitelabel)
     */
    public static function get_whitelabel_env_id_from_config() {
        if (!defined('COINSUB_PLUGIN_DIR')) {
            return null;
        }
        $path = COINSUB_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        return $config['environment_id'];
    }

    /**
     * Get plugin display name from whitelabel config (e.g. "Payment Servers").
     * Used so the only hardcoded partner name is in coinsub-whitelabel-config.php.
     *
     * @return string|null plugin_name when config has environment_id, else null
     */
    public static function get_whitelabel_plugin_name_from_config() {
        if (!defined('COINSUB_PLUGIN_DIR')) {
            return null;
        }
        $path = COINSUB_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        return isset($config['plugin_name']) && $config['plugin_name'] !== '' ? $config['plugin_name'] : null;
    }

    /**
     * Get dashboard URL from whitelabel config (where merchants log in and get credentials).
     * Used in setup instructions and field descriptions so merchants know where to go.
     *
     * @return string|null Full URL (e.g. https://app.paymentservers.com) when config has environment_id, else null
     */
    public static function get_whitelabel_dashboard_url_from_config() {
        if (!defined('COINSUB_PLUGIN_DIR')) {
            return null;
        }
        $path = COINSUB_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        if (!empty($config['dashboard_url']) && is_string($config['dashboard_url'])) {
            return rtrim($config['dashboard_url'], '/');
        }
        return 'https://app.' . $config['environment_id'];
    }

    /**
     * Get favicon URL for admin (WooCommerce payment settings list) from whitelabel config.
     * Optional: set favicon_url in config for a custom icon; otherwise null (caller uses local/default).
     *
     * @return string|null Full URL when config has favicon_url set, else null
     */
    public static function get_whitelabel_favicon_url_from_config() {
        if (!defined('COINSUB_PLUGIN_DIR')) {
            return null;
        }
        $path = COINSUB_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        if (!empty($config['favicon_url']) && is_string($config['favicon_url'])) {
            return trim($config['favicon_url']);
        }
        return null;
    }

    /**
     * Get checkout logo URL from whitelabel config (for button/icon on checkout).
     * When set, checkout uses config only — no API/database lookup.
     *
     * @return string|null checkout_logo_url if set, else favicon_url if set, else null
     */
    public static function get_whitelabel_checkout_logo_url_from_config() {
        if (!defined('COINSUB_PLUGIN_DIR')) {
            return null;
        }
        $path = COINSUB_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        if (!empty($config['checkout_logo_url']) && is_string($config['checkout_logo_url'])) {
            return trim($config['checkout_logo_url']);
        }
        if (!empty($config['favicon_url']) && is_string($config['favicon_url'])) {
            return trim($config['favicon_url']);
        }
        return null;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_client = new CoinSub_API_Client();
        
        // Ensure API client has current credentials
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        
        $api_base_url = 'https://api.coinsub.io/v1';
        
        if (!empty($merchant_id) && !empty($api_key)) {
            $this->api_client->update_settings($api_base_url, $merchant_id, $api_key);
        } elseif (!empty($merchant_id)) {
            // For merchant-info endpoint, API key not required
            $this->api_client->update_settings($api_base_url, $merchant_id, '');
        }
    }
    
    /**
     * Get whitelabel branding for current merchant
     * 
     * @param bool $force_refresh If true, force API call even if cache exists. If false, use cache only (no API calls).
     * @return array Branding data (company, logo, etc.)
     */
    public function get_branding($force_refresh = false) {
        // CRITICAL FIX: Check if credentials exist before using stored branding
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        
        if (empty($merchant_id) || empty($api_key)) {
            error_log('Stablecoin Pay Whitelabel: ⚠️ No credentials - returning empty');
            return array(); // Return empty array, no default
        }
        
        // If not forcing refresh, get branding from database (no API calls)
        if (!$force_refresh) {
            $stored_branding = get_option(self::BRANDING_OPTION_KEY, false);
            
            if ($stored_branding !== false && is_array($stored_branding)) {
                error_log('Stablecoin Pay Whitelabel: 📦 Found branding in database - Structure: ' . json_encode(array_keys($stored_branding)));
                
                if (isset($stored_branding['company']) && !empty($stored_branding['company'])) {
                    error_log('Stablecoin Pay Whitelabel: ✅ Using stored branding from database - Company: "' . $stored_branding['company'] . '"');
                    return $stored_branding;
                } else {
                    error_log('Stablecoin Pay Whitelabel: ⚠️ Stored branding missing company field');
                }
            }
            
            error_log('Stablecoin Pay Whitelabel: Checking database... Result: NOT FOUND');
            
            // No branding in database - return empty array (no default)
            // Branding will ONLY be fetched when settings are saved (to avoid rate limits)
            // Go to WooCommerce → Settings → Payments → CoinSub and click "Save changes"
            error_log('Stablecoin Pay Whitelabel: ⚠️ No branding in database - returning empty (no default)');
            error_log('Stablecoin Pay Whitelabel: 💡 TIP: Go to WooCommerce → Settings → Payments → CoinSub and click "Save changes" to fetch branding from API');
            return array(); // Return empty array, no default
        }
        
        // Force refresh - fetch fresh data from API and store in database
        error_log('Stablecoin Pay Whitelabel: 🔄🔄🔄 FORCE REFRESH - Fetching branding from API and storing in database 🔄🔄🔄');
        
        // Check for stuck lock (older than 30 seconds) and clear it
        $lock_time = get_transient(self::BRANDING_FETCH_LOCK_KEY . '_time');
        if ($lock_time && (time() - $lock_time) > 30) {
            error_log('Stablecoin Pay Whitelabel: 🔓 Clearing stuck fetch lock (older than 30 seconds)');
            delete_transient(self::BRANDING_FETCH_LOCK_KEY);
            delete_transient(self::BRANDING_FETCH_LOCK_KEY . '_time');
        }
        
        // Acquire a lock to prevent multiple simultaneous fetches
        if (get_transient(self::BRANDING_FETCH_LOCK_KEY)) {
            error_log('Stablecoin Pay Whitelabel: 🔒 Fetch lock active. Another process is already fetching branding. Returning empty.');
            return array(); // Return empty array, no default
        }
        set_transient(self::BRANDING_FETCH_LOCK_KEY, true, 30); // Lock for 30 seconds
        set_transient(self::BRANDING_FETCH_LOCK_KEY . '_time', time(), 30); // Track when lock was set
        error_log('Stablecoin Pay Whitelabel: 🔒 Acquired fetch lock for 30 seconds');

        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';

        if (empty($merchant_id) || empty($api_key)) {
            error_log('Stablecoin Pay Whitelabel: ❌ No merchant ID or API key in settings - cannot fetch branding');
            return array(); // Return empty array, no default
        }
        
        $api_base_url = 'https://api.coinsub.io/v1';
        
        // Note: We don't need to set API key for merchant_info endpoint - it's headerless!
        $this->api_client->update_settings($api_base_url, $merchant_id, ''); // Empty API key is fine
        error_log('Stablecoin Pay Whitelabel: Updated API client - Merchant ID: ' . $merchant_id . ' (no API key needed for merchant-info endpoint)');

        // Fetch merchant info to check if submerchant and get parent merchant ID
        // NEW: Use headerless endpoint that only requires Merchant-ID (no API key needed)
        error_log('Stablecoin Pay Whitelabel: Attempting to fetch merchant info for merchant ID: ' . $merchant_id);
        error_log('Stablecoin Pay Whitelabel: Using NEW headerless endpoint (no API key required)');
        $merchant_info = $this->api_client->get_merchant_info($merchant_id);
        
        $parent_merchant_id = null;
        
        if (is_wp_error($merchant_info)) {
            $error_message = $merchant_info->get_error_message();
            error_log('Stablecoin Pay Whitelabel: ❌ Failed to get merchant info: ' . $error_message);
            
            // Clear fetch lock on error
            delete_transient(self::BRANDING_FETCH_LOCK_KEY);
            delete_transient(self::BRANDING_FETCH_LOCK_KEY . '_time');
            
            // Handle rate limit errors - use stored branding if available
            if (strpos($error_message, 'Rate limit') !== false || strpos($error_message, 'rate limit') !== false) {
                error_log('Stablecoin Pay Whitelabel: Rate limit exceeded. Checking database for stored branding...');
                $stored_branding = get_option(self::BRANDING_OPTION_KEY, false);
                if ($stored_branding !== false && is_array($stored_branding) && isset($stored_branding['company'])) {
                    error_log('Stablecoin Pay Whitelabel: ✅ Using stored branding from database due to rate limit - Company: "' . $stored_branding['company'] . '"');
                    return $stored_branding;
                }
                error_log('Stablecoin Pay Whitelabel: ❌ No stored branding available - returning empty (no default)');
                return array(); // Return empty array, no default
            }
            
            error_log('Stablecoin Pay Whitelabel: ❌ Merchant info API error - returning empty (no default)');
            return array(); // Return empty array, no default
        }
        
        // Extract parent merchant ID from merchant info response
        // Response structure: { "submerchant_id": "...", "is_submerchant": true/false, "parent_merchant_id": "..." }
        error_log('Stablecoin Pay Whitelabel: 📦📦📦 MERCHANT INFO RESPONSE 📦📦📦');
        error_log('Stablecoin Pay Whitelabel: Merchant info response (pretty): ' . json_encode($merchant_info, JSON_PRETTY_PRINT));
        
        // Check if merchant is a submerchant
        $is_submerchant = isset($merchant_info['is_submerchant']) ? $merchant_info['is_submerchant'] : false;
        error_log('Stablecoin Pay Whitelabel: Is Submerchant: ' . ($is_submerchant ? 'YES' : 'NO'));
        
        if ($is_submerchant && isset($merchant_info['parent_merchant_id']) && !empty($merchant_info['parent_merchant_id'])) {
            $parent_merchant_id = $merchant_info['parent_merchant_id'];
            error_log('Stablecoin Pay Whitelabel: ✅ Found parent merchant ID: ' . $parent_merchant_id);
        } else {
            error_log('Stablecoin Pay Whitelabel: ⚠️ Merchant is NOT a submerchant OR parent_merchant_id is missing');
            error_log('Stablecoin Pay Whitelabel: Response structure: ' . print_r($merchant_info, true));
            // Clear fetch lock
            delete_transient(self::BRANDING_FETCH_LOCK_KEY);
            delete_transient(self::BRANDING_FETCH_LOCK_KEY . '_time');
            // If not a submerchant, we can't get branding - return empty
            return array(); // Return empty array, no default
        }
        
        if (empty($parent_merchant_id)) {
            // No parent merchant ID found, return empty
            error_log('Stablecoin Pay Whitelabel: ❌ No parent merchant ID found - returning empty (no default)');
            // Clear fetch lock
            delete_transient(self::BRANDING_FETCH_LOCK_KEY);
            delete_transient(self::BRANDING_FETCH_LOCK_KEY . '_time');
            return array(); // Return empty array, no default
        }
        
        error_log('Stablecoin Pay Whitelabel: ✅✅✅ Parent merchant ID extracted: ' . $parent_merchant_id);
        
        // Fetch environment configs
        error_log('Stablecoin Pay Whitelabel: Fetching environment configs from API...');
        $env_configs = $this->api_client->get_environment_configs();
        
        if (is_wp_error($env_configs)) {
            error_log('Stablecoin Pay Whitelabel: ❌ Failed to get environment configs: ' . $env_configs->get_error_message());
            // Clear fetch lock on error
            delete_transient(self::BRANDING_FETCH_LOCK_KEY);
            delete_transient(self::BRANDING_FETCH_LOCK_KEY . '_time');
            return array(); // Return empty array, no default
        }
        
        error_log('Stablecoin Pay Whitelabel: ✅ Got environment configs. Structure: ' . json_encode(array_keys($env_configs)));
        
        // Match parent merchant ID to config_data
        $branding = $this->match_merchant_to_branding($parent_merchant_id, $env_configs);
        
        // Store branding in WordPress database (persists until manually updated)
        $stored = update_option(self::BRANDING_OPTION_KEY, $branding);
        
        error_log('Stablecoin Pay Whitelabel: 💾 Storing branding in database... Result: ' . ($stored ? 'SUCCESS' : 'FAILED'));
        error_log('Stablecoin Pay Whitelabel: 📦 Branding data being stored: ' . json_encode($branding));
        error_log('Stablecoin Pay Whitelabel: ✅✅✅ BRANDING STORED IN DATABASE - Company Name: "' . $branding['company'] . '" | Title will be: "Pay with ' . $branding['company'] . '"');
        
        // Clear fetch lock (always clear, even if there was an error)
        delete_transient(self::BRANDING_FETCH_LOCK_KEY);
        delete_transient(self::BRANDING_FETCH_LOCK_KEY . '_time');
        error_log('Stablecoin Pay Whitelabel: 🔓 Cleared fetch lock');
        
        // Verify it was stored correctly
        $verify = get_option(self::BRANDING_OPTION_KEY, false);
        if ($verify !== false && isset($verify['company'])) {
            error_log('Stablecoin Pay Whitelabel: ✅ Verified - Branding in database has company: "' . $verify['company'] . '"');
        } else {
            error_log('Stablecoin Pay Whitelabel: ⚠️ WARNING - Could not verify branding was stored correctly!');
        }
        
        return $branding;
    }
    
    /**
     * Try to match branding by submerchant ID (fallback when parent lookup fails)
     * 
     * @param string $submerchant_id Submerchant ID to match
     * @param array $env_configs Environment configs from API
     * @return array|null Branding data or null if no match
     */
    private function try_match_by_submerchant_id($submerchant_id, $env_configs) {
        // This is a fallback - usually we match by parent merchant ID
        // But if API doesn't have submerchant, we can't get parent ID
        // So this returns null for now - the real fix is ensuring submerchant exists in API
        return null;
    }
    
    /**
     * Match parent merchant ID to whitelabel branding config
     * 
     * @param string $parent_merchant_id Parent merchant ID to match
     * @param array $env_configs Environment configs from API
     * @return array Branding data
     */
    private function match_merchant_to_branding($parent_merchant_id, $env_configs) {
        if (!isset($env_configs['environment_configs']) || !is_array($env_configs['environment_configs'])) {
            error_log('Stablecoin Pay Whitelabel: Invalid environment_configs structure. Response: ' . json_encode($env_configs));
            return array(); // Return empty array, no default
        }
        
        error_log('Stablecoin Pay Whitelabel: Searching for parent merchant ID: ' . $parent_merchant_id);
        error_log('Stablecoin Pay Whitelabel: Checking ' . count($env_configs['environment_configs']) . ' environment configs...');
        
        // Loop through environment configs to find matching merchantID
        foreach ($env_configs['environment_configs'] as $index => $config) {
            if (!isset($config['config_data'])) {
                continue; // Skip silently
            }
            
            // Parse config_data JSON (it's stored as JSONB in the database)
            $config_data = is_string($config['config_data']) 
                ? json_decode($config['config_data'], true) 
                : $config['config_data'];
            
            if (!is_array($config_data)) {
                continue; // Skip silently
            }
            
            // Check if config_data has 'app' key (based on user's example structure)
            if (!isset($config_data['app'])) {
                // Try alternative structure: maybe config_data is directly the app data
                if (isset($config_data['merchantID'])) {
                    // This might be the app data directly
                    $config_data = array('app' => $config_data);
                } else {
                    continue; // Skip silently
                }
            }
            
            $app_data = $config_data['app'];
            
            // Check if merchantID matches (case-insensitive comparison for safety)
            $config_merchant_id = null;
            if (isset($app_data['merchantID'])) {
                $config_merchant_id = $app_data['merchantID'];
            } elseif (isset($app_data['merchant_id'])) {
                $config_merchant_id = $app_data['merchant_id'];
            }
            
            if (empty($config_merchant_id)) {
                continue; // Skip silently
            }
            
            // Compare merchant IDs (case-insensitive, trim whitespace)
            $parent_id_normalized = strtolower(trim($parent_merchant_id));
            $config_id_normalized = strtolower(trim($config_merchant_id));
            
            if ($config_id_normalized === $parent_id_normalized) {
                // Found match! Extract branding data from app.company and app.logo
                $company_name = isset($app_data['company']) && !empty($app_data['company']) 
                    ? $app_data['company'] 
                    : ''; // No default company name
                
                // Get environment_id from config (CRITICAL for white-label API URLs)
                $environment_id = isset($config['environment_id']) ? $config['environment_id'] : '';
                
                error_log('Stablecoin Pay Whitelabel: ✅✅✅ MATCH FOUND! Company: "' . $company_name . '"');
                if (!empty($environment_id)) {
                    error_log('Stablecoin Pay Whitelabel: 📋 Environment ID: ' . $environment_id);
                }
                
                error_log('Stablecoin Pay Whitelabel: 📦 Extracted company name from app.company: "' . $company_name . '"');
                
                // Extract logo data
                $logo_data = $this->extract_logo_data($app_data);
                error_log('Stablecoin Pay Whitelabel: 🖼️ Extracted logo data: ' . json_encode($logo_data));
                
                // Get company slug for URL construction
                $company_slug = $this->get_company_slug($company_name);
                error_log('Stablecoin Pay Whitelabel: 🔧 Generated company slug: "' . $company_slug . '" from company name: "' . $company_name . '"');
                
                $branding = array(
                    'company' => $company_name, // Use company name from app.company
                    'company_slug' => $company_slug, // CRITICAL: Needed for buy URL reconstruction!
                    'environment_id' => $environment_id, // CRITICAL: Needed for white-label API URL construction!
                    'powered_by' => 'Powered by CoinSub', // Always show this
                    'logo' => $logo_data,
                    'favicon' => isset($app_data['favicon']) ? $app_data['favicon'] : '',
                    'buyurl' => isset($app_data['buyurl']) ? $app_data['buyurl'] : '',
                    'documentation_url' => isset($app_data['documentation_url']) ? $app_data['documentation_url'] : '',
                    'privacy_policy_url' => isset($app_data['privacy_policy_url']) ? $app_data['privacy_policy_url'] : '',
                    'terms_of_service_url' => isset($app_data['terms_of_service_url']) ? $app_data['terms_of_service_url'] : '',
                    'copyright' => isset($app_data['copyright']) ? $app_data['copyright'] : ''
                );
                
                // Convert relative logo URLs to absolute if needed
                $branding['logo'] = $this->normalize_logo_urls($branding['logo'], $company_name);
                
                error_log('Stablecoin Pay Whitelabel: ✅✅✅ Final branding data - Company: "' . $branding['company'] . '" | Company Slug: "' . $branding['company_slug'] . '" | Logo: ' . json_encode($branding['logo']));
                
                return $branding;
            }
        }
        
        // No match found, return empty
        error_log('Stablecoin Pay Whitelabel: ❌ No matching branding found for parent merchant ID: ' . $parent_merchant_id);
        
        return array(); // Return empty array, no default
    }

    /**
     * Build branding array from a single env_config row (used when environment_id is set in whitelabel config file).
     * config_data shape: { "db": {...}, "app": { "company", "logo", "favicon", "merchantID", ... } }
     *
     * @param array $config One entry from environment_configs (environment_id + config_data)
     * @return array Branding array or empty if invalid
     */
    private function build_branding_from_config_row($config) {
        if (!isset($config['config_data'])) {
            return array();
        }
        $config_data = is_string($config['config_data'])
            ? json_decode($config['config_data'], true)
            : $config['config_data'];
        if (!is_array($config_data)) {
            return array();
        }
        if (!isset($config_data['app'])) {
            if (isset($config_data['merchantID']) || isset($config_data['company'])) {
                $config_data = array('app' => $config_data);
            } else {
                return array();
            }
        }
        $app_data = $config_data['app'];
        $company_name = isset($app_data['company']) && $app_data['company'] !== '' ? $app_data['company'] : '';
        if ($company_name === '') {
            return array();
        }
        $environment_id = isset($config['environment_id']) ? $config['environment_id'] : '';
        $logo_data = $this->extract_logo_data($app_data);
        $company_slug = $this->get_company_slug($company_name);
        $branding = array(
            'company' => $company_name,
            'company_slug' => $company_slug,
            'environment_id' => $environment_id,
            'powered_by' => isset($app_data['powered_by']) && $app_data['powered_by'] !== '' ? $app_data['powered_by'] : 'Powered by ' . $company_name,
            'logo' => $this->normalize_logo_urls($logo_data, $company_name),
            'favicon' => isset($app_data['favicon']) ? $app_data['favicon'] : '',
            'buyurl' => isset($app_data['buyurl']) ? $app_data['buyurl'] : '',
            'documentation_url' => isset($app_data['documentation_url']) ? $app_data['documentation_url'] : '',
            'privacy_policy_url' => isset($app_data['privacy_policy_url']) ? $app_data['privacy_policy_url'] : '',
            'terms_of_service_url' => isset($app_data['terms_of_service_url']) ? $app_data['terms_of_service_url'] : '',
            'copyright' => isset($app_data['copyright']) ? $app_data['copyright'] : '',
        );
        return $branding;
    }
    
    /**
     * Extract logo data from app config
     * 
     * @param array $app_data App config data
     * @return array Logo URLs
     */
    private function extract_logo_data($app_data) {
        // Initialize empty logo structure
        $logo = array(
            'default' => array('light' => '', 'dark' => ''),
            'square' => array('light' => '', 'dark' => '')
        );
        
        error_log('Stablecoin Pay Whitelabel: 🖼️ Extracting logo data from app_data');
        error_log('Stablecoin Pay Whitelabel: 🖼️ app_data logo structure: ' . json_encode(isset($app_data['logo']) ? $app_data['logo'] : 'NOT SET', JSON_PRETTY_PRINT));
        
        if (isset($app_data['logo']) && is_array($app_data['logo'])) {
            $logo_config = $app_data['logo'];
            
            // Extract default logos
            if (isset($logo_config['default']) && is_array($logo_config['default'])) {
                if (isset($logo_config['default']['light']) && !empty($logo_config['default']['light'])) {
                    $logo['default']['light'] = $logo_config['default']['light'];
                    error_log('Stablecoin Pay Whitelabel: 🖼️ Found default.light logo: ' . $logo['default']['light']);
                }
                if (isset($logo_config['default']['dark']) && !empty($logo_config['default']['dark'])) {
                    $logo['default']['dark'] = $logo_config['default']['dark'];
                    error_log('Stablecoin Pay Whitelabel: 🖼️ Found default.dark logo: ' . $logo['default']['dark']);
                }
            }
            
            // Extract square logos
            if (isset($logo_config['square']) && is_array($logo_config['square'])) {
                if (isset($logo_config['square']['light']) && !empty($logo_config['square']['light'])) {
                    $logo['square']['light'] = $logo_config['square']['light'];
                    error_log('Stablecoin Pay Whitelabel: 🖼️ Found square.light logo: ' . $logo['square']['light']);
                }
                if (isset($logo_config['square']['dark']) && !empty($logo_config['square']['dark'])) {
                    $logo['square']['dark'] = $logo_config['square']['dark'];
                    error_log('Stablecoin Pay Whitelabel: 🖼️ Found square.dark logo: ' . $logo['square']['dark']);
                }
            }
        } else {
            error_log('Stablecoin Pay Whitelabel: 🖼️ ⚠️ No logo data found in app_data');
        }
        
        error_log('Stablecoin Pay Whitelabel: 🖼️ Extracted logo structure: ' . json_encode($logo, JSON_PRETTY_PRINT));
        
        return $logo;
    }
    
    /**
     * Normalize logo URLs (convert relative to absolute if needed)
     * Logo URLs from config_data are relative paths like "/img/domain/vantack/vantack.light.svg"
     * Default: https://app.coinsub.io/img/domain/vantack/vantack.light.svg
     * Whitelabel: https://app.{{domain}}/img/domain/vantack/vantack.light.svg (e.g., app.vantack.com)
     * 
     * @param array $logo Logo data
     * @param string $company_name Optional: Company name to determine white-label domain
     * @return array Normalized logo data
     */
    private function normalize_logo_urls($logo, $company_name = '') {
        // Get company slug to determine white-label domain
        $company_slug = '';
        if (!empty($company_name)) {
            $company_slug = $this->get_company_slug($company_name);
        } else {
            // Try to get from stored branding as fallback
            $branding = get_option(self::BRANDING_OPTION_KEY, false);
            if ($branding && isset($branding['company'])) {
                $company_slug = $this->get_company_slug($branding['company']);
            }
        }
        
        // Determine asset base URL based on company slug
        if ($company_slug === 'coinsub' || empty($company_slug)) {
            // Default CoinSub domain
            $asset_base = 'https://app.coinsub.io';
        } else {
            // White-label domain: app.{slug}.com
            $whitelabel_domain = $company_slug . '.com';
            $asset_base = 'https://app.' . $whitelabel_domain;
        }
        
        error_log('Stablecoin Pay Whitelabel: 🖼️ Normalizing logo URLs with base: ' . $asset_base);
        error_log('Stablecoin Pay Whitelabel: 🖼️ Logo data before normalization: ' . json_encode($logo, JSON_PRETTY_PRINT));
        
        foreach ($logo as $type => &$variants) {
            if (!is_array($variants)) {
                continue;
            }
            
            foreach ($variants as $theme => &$url) {
                if (!empty($url) && is_string($url)) {
                    // If URL doesn't start with http, it's relative - make it absolute
                    if (strpos($url, 'http') !== 0) {
                        // Relative URL, make it absolute
                        // Remove leading slash from relative URL to avoid double slash
                        $url = ltrim($url, '/');
                        $url = $asset_base . '/' . $url;
                        // Normalize any double slashes in the path (but preserve http:// or https://)
                        $url = preg_replace('#([^:])//+#', '$1/', $url);
                        error_log('Stablecoin Pay Whitelabel: 🖼️ Converted relative logo URL to: ' . $url);
                    } else {
                        // Normalize any double slashes in absolute URLs (but preserve http:// or https://)
                        $url = preg_replace('#([^:])//+#', '$1/', $url);
                        error_log('Stablecoin Pay Whitelabel: 🖼️ Logo URL already absolute: ' . $url);
                    }
                }
            }
        }
        
        error_log('Stablecoin Pay Whitelabel: 🖼️ Logo data after normalization: ' . json_encode($logo, JSON_PRETTY_PRINT));
        
        return $logo;
    }
    
    /**
     * Clear branding cache (call when merchant credentials change)
     */
    public function clear_cache() {
        delete_option(self::BRANDING_OPTION_KEY);
    }
    
    /**
     * Get company name for display
     * 
     * @return string Company name
     */
    public function get_company_name() {
        $branding = $this->get_branding();
        return $branding['company'];
    }
    
    /**
     * Get logo URL (prefers light theme, falls back to dark)
     * 
     * @param string $type Logo type: 'default' or 'square'
     * @param string $theme Theme: 'light' or 'dark'
     * @return string Logo URL
     */
    public function get_logo_url($type = 'default', $theme = 'light') {
        $branding = $this->get_branding();
        
        error_log('Stablecoin Pay Whitelabel: 🖼️ get_logo_url() called - Type: ' . $type . ', Theme: ' . $theme);
        error_log('Stablecoin Pay Whitelabel: 🖼️ Branding data: ' . json_encode($branding, JSON_PRETTY_PRINT));
        
        if (!empty($branding) && isset($branding['logo'][$type][$theme]) && !empty($branding['logo'][$type][$theme])) {
            $logo_url = $branding['logo'][$type][$theme];
            error_log('Stablecoin Pay Whitelabel: 🖼️ ✅ Found logo URL: ' . $logo_url);
            return $logo_url;
        }
        
        // Try fallback to dark theme if light not found
        if ($theme === 'light' && !empty($branding) && isset($branding['logo'][$type]['dark']) && !empty($branding['logo'][$type]['dark'])) {
            $logo_url = $branding['logo'][$type]['dark'];
            error_log('Stablecoin Pay Whitelabel: 🖼️ ✅ Found logo URL (dark fallback): ' . $logo_url);
            return $logo_url;
        }
        
        // FALLBACK: Auto-construct logo URL from company name
        // Pattern: https://app.{domain}/img/domain/{slug}/{slug}.{type}.{theme}.svg
        if (!empty($branding) && isset($branding['company']) && !empty($branding['company'])) {
            $company_slug = $this->get_company_slug($branding['company']);
            
            // Construct domain from company slug
            if ($company_slug === 'coinsub') {
                $asset_base = 'https://app.coinsub.io';
            } else {
                // Whitelabel domain: app.{slug}.com
                $whitelabel_domain = $company_slug . '.com';
                $asset_base = 'https://app.' . $whitelabel_domain;
            }
            
            // Construct URL pattern: /img/domain/{slug}/{slug}.{type}.{theme}.svg
            $filename = $company_slug . '.' . $type . '.' . $theme . '.svg';
            $constructed_url = $asset_base . '/img/domain/' . $company_slug . '/' . $filename;
            // Normalize any double slashes in the path (but preserve http:// or https://)
            $constructed_url = preg_replace('#([^:])//+#', '$1/', $constructed_url);
            error_log('Stablecoin Pay Whitelabel: 🖼️ 🔧 Auto-constructed logo URL: ' . $constructed_url . ' (domain: ' . $asset_base . ')');
            return $constructed_url;
        }
        
        // No logo found - return default CoinSub logo
        $default_logo = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
        error_log('Stablecoin Pay Whitelabel: 🖼️ ⚠️ No logo found in branding, using default: ' . $default_logo);
        return $default_logo;
    }
    
    /**
     * Get favicon URL
     * 
     * @return string Favicon URL (or default if not found)
     */
    public function get_favicon_url() {
        $branding = $this->get_branding();
        
        error_log('Stablecoin Pay Whitelabel: 🖼️ get_favicon_url() called');
        error_log('Stablecoin Pay Whitelabel: 🖼️ Branding data: ' . json_encode($branding, JSON_PRETTY_PRINT));
        
        if (!empty($branding) && isset($branding['favicon']) && !empty($branding['favicon'])) {
            $favicon_url = $branding['favicon'];
            
            // If URL doesn't start with http, it's relative - make it absolute
            // Need to determine which domain to use based on branding
            if (strpos($favicon_url, 'http') !== 0) {
                // Get company slug to determine domain
                $company = isset($branding['company']) ? $branding['company'] : '';
                $company_slug = $this->get_company_slug($company);
                
                if ($company_slug === 'coinsub' || empty($company_slug)) {
                    $asset_base = 'https://app.coinsub.io';
                } else {
                    // Whitelabel domain
                    $whitelabel_domain = $company_slug . '.com';
                    $asset_base = 'https://app.' . $whitelabel_domain;
                }
                
                if (strpos($favicon_url, '/') === 0) {
                    $favicon_url = $asset_base . $favicon_url;
                } else {
                    $favicon_url = $asset_base . '/' . $favicon_url;
                }
                // Normalize any double slashes in the path (but preserve http:// or https://)
                $favicon_url = preg_replace('#([^:])//+#', '$1/', $favicon_url);
                error_log('Stablecoin Pay Whitelabel: 🖼️ Converted relative favicon URL to: ' . $favicon_url . ' (domain: ' . $asset_base . ')');
            }
            
            error_log('Stablecoin Pay Whitelabel: 🖼️ ✅ Found favicon URL: ' . $favicon_url);
            return $favicon_url;
        }
        
        // FALLBACK: Auto-construct favicon URL from company name
        // Pattern: https://app.{domain}/img/domain/{slug}/favicon.{ext}
        // Try multiple extensions (jpg, png, svg, ico)
        if (!empty($branding) && isset($branding['company']) && !empty($branding['company'])) {
            $company_slug = $this->get_company_slug($branding['company']);
            
            // Construct domain from company slug
            // E.g., "Payment Servers" -> "paymentservers" -> "paymentservers.com"
            if ($company_slug === 'coinsub') {
                // CoinSub default favicon
                $favicon_url = 'https://app.coinsub.io/img/favicon.ico';
                error_log('Stablecoin Pay Whitelabel: 🖼️ ✅ Using CoinSub default favicon: ' . $favicon_url);
                return $favicon_url;
            } else {
                // Whitelabel domain: app.{slug}.com
                $whitelabel_domain = $company_slug . '.com';
                $base_url = 'https://app.' . $whitelabel_domain . '/img/domain/' . $company_slug . '/favicon';
                
                // Company-specific favicon extensions (add more as discovered)
                $extension_map = array(
                    'vantack' => 'jpg',          // Vantack uses .jpg
                    'paymentservers' => 'png',   // Payment Servers uses .png
                    'bxnk' => 'png',             // BXNK/Zyrister uses .png       // BXNK/Zyrister alternate name
                    'subscrypt' => 'png',        // Subscrypt uses .png
                    // Add more whitelabels here as needed
                );
                
                // Use mapped extension or default to png
                $extension = isset($extension_map[$company_slug]) ? $extension_map[$company_slug] : 'png';
                $favicon_url = $base_url . '.' . $extension;
                // Normalize any double slashes in the path (but preserve http:// or https://)
                $favicon_url = preg_replace('#([^:])//+#', '$1/', $favicon_url);
                
                error_log('Stablecoin Pay Whitelabel: 🖼️ ✅ Auto-constructed whitelabel favicon: ' . $favicon_url . ' (extension: .' . $extension . ')');
                return $favicon_url;
            }
        }
        
        // No favicon found - return default CoinSub logo
        $default_logo = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
        error_log('Stablecoin Pay Whitelabel: 🖼️ ⚠️ No favicon found in branding, using default: ' . $default_logo);
        return $default_logo;
    }
    
    /**
     * Convert company name to slug for constructing logo URLs
     * Examples: "Payment Servers" -> "paymentservers", "Vantack" -> "vantack"
     * 
     * @param string $company_name Company name from branding
     * @return string Company slug for URL paths
     */
    private function get_company_slug($company_name) {
        // Convert to lowercase and remove spaces
        $slug = strtolower($company_name);
        $slug = str_replace(' ', '', $slug);
        // Remove special characters
        $slug = preg_replace('/[^a-z0-9]/', '', $slug);
        return $slug;
    }
    
    /**
     * Get "powered by" text
     * 
     * @return string Powered by text
     */
    public function get_powered_by_text() {
        $branding = $this->get_branding();
        return $branding['powered_by'];
    }
}

