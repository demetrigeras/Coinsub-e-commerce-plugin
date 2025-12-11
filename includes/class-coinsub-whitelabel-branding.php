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
     * Constructor
     */
    public function __construct() {
        $this->api_client = new CoinSub_API_Client();
        
        // Ensure API client has current credentials
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        $api_base_url = 'https://dev-api.coinsub.io/v1';
        
        if (!empty($merchant_id) && !empty($api_key)) {
            $this->api_client->update_settings($api_base_url, $merchant_id, $api_key);
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
        // If no credentials, don't use stored branding (it might be from a different merchant)
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        
        if (empty($merchant_id) || empty($api_key)) {
            error_log('CoinSub Whitelabel: ⚠️ No credentials in settings - not using stored branding (might be from different merchant)');
            return array(); // Return empty array, no default
        }
        
        // If not forcing refresh, get branding from database (no API calls)
        if (!$force_refresh) {
            $stored_branding = get_option(self::BRANDING_OPTION_KEY, false);
            
            if ($stored_branding !== false && is_array($stored_branding)) {
                error_log('CoinSub Whitelabel: 📦 Found branding in database - Structure: ' . json_encode(array_keys($stored_branding)));
                
                if (isset($stored_branding['company']) && !empty($stored_branding['company'])) {
                    error_log('CoinSub Whitelabel: ✅ Using stored branding from database - Company: "' . $stored_branding['company'] . '"');
                    return $stored_branding;
                } else {
                    error_log('CoinSub Whitelabel: ⚠️ Stored branding missing company field');
                }
            }
            
            error_log('CoinSub Whitelabel: Checking database... Result: NOT FOUND');
            
            // No branding in database - return empty array (no default)
            // Branding will ONLY be fetched when settings are saved (to avoid rate limits)
            // Go to WooCommerce → Settings → Payments → CoinSub and click "Save changes"
            error_log('CoinSub Whitelabel: ⚠️ No branding in database - returning empty (no default)');
            error_log('CoinSub Whitelabel: 💡 TIP: Go to WooCommerce → Settings → Payments → CoinSub and click "Save changes" to fetch branding from API');
            return array(); // Return empty array, no default
        }
        
        // Force refresh - fetch fresh data from API and store in database
        error_log('CoinSub Whitelabel: 🔄🔄🔄 FORCE REFRESH - Fetching branding from API and storing in database 🔄🔄🔄');
        
        // Acquire a lock to prevent multiple simultaneous fetches
        if (get_transient(self::BRANDING_FETCH_LOCK_KEY)) {
            error_log('CoinSub Whitelabel: 🔒 Fetch lock active. Another process is already fetching branding. Returning empty.');
            return array(); // Return empty array, no default
        }
        set_transient(self::BRANDING_FETCH_LOCK_KEY, true, 30); // Lock for 30 seconds
        error_log('CoinSub Whitelabel: 🔒 Acquired fetch lock for 30 seconds');
        
        // Get merchant ID from settings
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        
        if (empty($merchant_id) || empty($api_key)) {
            // No credentials, return empty
            error_log('CoinSub Whitelabel: ❌ No merchant ID or API key in settings - cannot fetch branding');
            return array(); // Return empty array, no default
        }
        
        // Ensure API client has the latest base URL (merchant ID is passed directly to the method)
        $api_base_url = 'https://dev-api.coinsub.io/v1';
        // Note: We don't need to set API key for merchant_info endpoint - it's headerless!
        $this->api_client->update_settings($api_base_url, $merchant_id, ''); // Empty API key is fine
        error_log('CoinSub Whitelabel: Updated API client - Merchant ID: ' . $merchant_id . ' (no API key needed for merchant-info endpoint)');
        
        // Fetch merchant info to check if submerchant and get parent merchant ID
        // NEW: Use headerless endpoint that only requires Merchant-ID (no API key needed)
        error_log('CoinSub Whitelabel: Attempting to fetch merchant info for merchant ID: ' . $merchant_id);
        error_log('CoinSub Whitelabel: Using NEW headerless endpoint (no API key required)');
        $merchant_info = $this->api_client->get_merchant_info($merchant_id);
        
        $parent_merchant_id = null;
        
        if (is_wp_error($merchant_info)) {
            $error_message = $merchant_info->get_error_message();
            error_log('CoinSub Whitelabel: ❌ Failed to get merchant info: ' . $error_message);
            
            // Handle rate limit errors - use stored branding if available
            if (strpos($error_message, 'Rate limit') !== false || strpos($error_message, 'rate limit') !== false) {
                error_log('CoinSub Whitelabel: Rate limit exceeded. Checking database for stored branding...');
                $stored_branding = get_option(self::BRANDING_OPTION_KEY, false);
                if ($stored_branding !== false && is_array($stored_branding) && isset($stored_branding['company'])) {
                    error_log('CoinSub Whitelabel: ✅ Using stored branding from database due to rate limit - Company: "' . $stored_branding['company'] . '"');
                    return $stored_branding;
                }
                error_log('CoinSub Whitelabel: ❌ No stored branding available - returning empty (no default)');
                return array(); // Return empty array, no default
            }
            
            error_log('CoinSub Whitelabel: ❌ Merchant info API error - returning empty (no default)');
            return array(); // Return empty array, no default
        }
        
        // Extract parent merchant ID from merchant info response
        // Response structure: { "submerchant_id": "...", "is_submerchant": true/false, "parent_merchant_id": "..." }
        error_log('CoinSub Whitelabel: 📦📦📦 MERCHANT INFO RESPONSE 📦📦📦');
        error_log('CoinSub Whitelabel: Merchant info response (pretty): ' . json_encode($merchant_info, JSON_PRETTY_PRINT));
        
        // Check if merchant is a submerchant
        $is_submerchant = isset($merchant_info['is_submerchant']) ? $merchant_info['is_submerchant'] : false;
        error_log('CoinSub Whitelabel: Is Submerchant: ' . ($is_submerchant ? 'YES' : 'NO'));
        
        if ($is_submerchant && isset($merchant_info['parent_merchant_id']) && !empty($merchant_info['parent_merchant_id'])) {
            $parent_merchant_id = $merchant_info['parent_merchant_id'];
            error_log('CoinSub Whitelabel: ✅ Found parent merchant ID: ' . $parent_merchant_id);
        } else {
            error_log('CoinSub Whitelabel: ⚠️ Merchant is NOT a submerchant OR parent_merchant_id is missing');
            error_log('CoinSub Whitelabel: Response structure: ' . print_r($merchant_info, true));
            // If not a submerchant, we can't get branding - return empty
            return array(); // Return empty array, no default
        }
        
        if (empty($parent_merchant_id)) {
            // No parent merchant ID found, return empty
            error_log('CoinSub Whitelabel: ❌ No parent merchant ID found - returning empty (no default)');
            return array(); // Return empty array, no default
        }
        
        error_log('CoinSub Whitelabel: ✅✅✅ Parent merchant ID extracted: ' . $parent_merchant_id);
        
        // Fetch environment configs
        error_log('CoinSub Whitelabel: Fetching environment configs from API...');
        $env_configs = $this->api_client->get_environment_configs();
        
        if (is_wp_error($env_configs)) {
            error_log('CoinSub Whitelabel: ❌ Failed to get environment configs: ' . $env_configs->get_error_message());
            return array(); // Return empty array, no default
        }
        
        error_log('CoinSub Whitelabel: ✅ Got environment configs. Structure: ' . json_encode(array_keys($env_configs)));
        
        // Match parent merchant ID to config_data
        $branding = $this->match_merchant_to_branding($parent_merchant_id, $env_configs);
        
        // Store branding in WordPress database (persists until manually updated)
        $stored = update_option(self::BRANDING_OPTION_KEY, $branding);
        
        error_log('CoinSub Whitelabel: 💾 Storing branding in database... Result: ' . ($stored ? 'SUCCESS' : 'FAILED'));
        error_log('CoinSub Whitelabel: 📦 Branding data being stored: ' . json_encode($branding));
        error_log('CoinSub Whitelabel: ✅✅✅ BRANDING STORED IN DATABASE - Company Name: "' . $branding['company'] . '" | Title will be: "Pay with ' . $branding['company'] . '"');
        
        // Clear fetch lock
        delete_transient(self::BRANDING_FETCH_LOCK_KEY);
        
        // Verify it was stored correctly
        $verify = get_option(self::BRANDING_OPTION_KEY, false);
        if ($verify !== false && isset($verify['company'])) {
            error_log('CoinSub Whitelabel: ✅ Verified - Branding in database has company: "' . $verify['company'] . '"');
        } else {
            error_log('CoinSub Whitelabel: ⚠️ WARNING - Could not verify branding was stored correctly!');
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
            error_log('CoinSub Whitelabel: Invalid environment_configs structure. Response: ' . json_encode($env_configs));
            return array(); // Return empty array, no default
        }
        
        error_log('CoinSub Whitelabel: Searching for parent merchant ID: ' . $parent_merchant_id);
        error_log('CoinSub Whitelabel: Total environment configs to check: ' . count($env_configs['environment_configs']));
        
        // Loop through environment configs to find matching merchantID
        foreach ($env_configs['environment_configs'] as $index => $config) {
            if (!isset($config['config_data'])) {
                error_log('CoinSub Whitelabel: Config #' . $index . ' missing config_data');
                continue;
            }
            
            // Parse config_data JSON (it's stored as JSONB in the database)
            $config_data = is_string($config['config_data']) 
                ? json_decode($config['config_data'], true) 
                : $config['config_data'];
            
            // Log the full config_data structure for debugging
            error_log('CoinSub Whitelabel: 📋 Config #' . $index . ' - Full config_data: ' . json_encode($config_data, JSON_PRETTY_PRINT));
            
            if (!is_array($config_data)) {
                error_log('CoinSub Whitelabel: Config #' . $index . ' config_data is not an array');
                continue;
            }
            
            // Check if config_data has 'app' key (based on user's example structure)
            if (!isset($config_data['app'])) {
                // Try alternative structure: maybe config_data is directly the app data
                if (isset($config_data['merchantID'])) {
                    // This might be the app data directly
                    error_log('CoinSub Whitelabel: Config #' . $index . ' - config_data has merchantID directly, wrapping in app key');
                    $config_data = array('app' => $config_data);
                } else {
                    error_log('CoinSub Whitelabel: Config #' . $index . ' missing app key in config_data. Keys: ' . implode(', ', array_keys($config_data)));
                    continue;
                }
            }
            
            $app_data = $config_data['app'];
            error_log('CoinSub Whitelabel: Config #' . $index . ' - app data keys: ' . implode(', ', array_keys($app_data)));
            error_log('CoinSub Whitelabel: Config #' . $index . ' - app data: ' . json_encode($app_data, JSON_PRETTY_PRINT));
            
            // Check if merchantID matches (case-insensitive comparison for safety)
            $config_merchant_id = null;
            if (isset($app_data['merchantID'])) {
                $config_merchant_id = $app_data['merchantID'];
            } elseif (isset($app_data['merchant_id'])) {
                $config_merchant_id = $app_data['merchant_id'];
            }
            
            if (empty($config_merchant_id)) {
                error_log('CoinSub Whitelabel: Config #' . $index . ' missing merchantID in app data');
                continue;
            }
            
            // Compare merchant IDs (case-insensitive, trim whitespace)
            $parent_id_normalized = strtolower(trim($parent_merchant_id));
            $config_id_normalized = strtolower(trim($config_merchant_id));
            
            error_log('CoinSub Whitelabel: 🔍 Comparing parent merchant ID "' . $parent_id_normalized . '" with config merchantID "' . $config_id_normalized . '"');
            error_log('CoinSub Whitelabel: Config #' . $index . ' - Company: "' . (isset($app_data['company']) ? $app_data['company'] : 'N/A') . '" | merchantID: "' . $config_merchant_id . '"');
            
            if ($config_id_normalized === $parent_id_normalized) {
                // Found match! Extract branding data from app.company and app.logo
                error_log('CoinSub Whitelabel: ✅✅✅ MATCH FOUND! Parent ID matches config merchantID');
                
                // Get company name from app.company (e.g., "Vantack")
                $company_name = isset($app_data['company']) && !empty($app_data['company']) 
                    ? $app_data['company'] 
                    : ''; // No default company name
                
                error_log('CoinSub Whitelabel: 📦 Extracted company name from app.company: "' . $company_name . '"');
                
                // Extract logo data
                $logo_data = $this->extract_logo_data($app_data);
                error_log('CoinSub Whitelabel: 🖼️ Extracted logo data: ' . json_encode($logo_data));
                
                $branding = array(
                    'company' => $company_name, // Use company name from app.company
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
                $branding['logo'] = $this->normalize_logo_urls($branding['logo']);
                
                error_log('CoinSub Whitelabel: ✅✅✅ Final branding data - Company: "' . $branding['company'] . '" | Logo: ' . json_encode($branding['logo']));
                
                return $branding;
            }
        }
        
        // No match found, return empty
        error_log('CoinSub Whitelabel: ❌ No matching branding found for parent merchant ID: ' . $parent_merchant_id);
        error_log('CoinSub Whitelabel: Available merchant IDs in configs: ' . json_encode(array_map(function($config) {
            $config_data = is_string($config['config_data']) ? json_decode($config['config_data'], true) : $config['config_data'];
            return isset($config_data['app']['merchantID']) ? $config_data['app']['merchantID'] : 'N/A';
        }, $env_configs['environment_configs'])));
        
        return array(); // Return empty array, no default
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
        
        error_log('CoinSub Whitelabel: 🖼️ Extracting logo data from app_data');
        error_log('CoinSub Whitelabel: 🖼️ app_data logo structure: ' . json_encode(isset($app_data['logo']) ? $app_data['logo'] : 'NOT SET', JSON_PRETTY_PRINT));
        
        if (isset($app_data['logo']) && is_array($app_data['logo'])) {
            $logo_config = $app_data['logo'];
            
            // Extract default logos
            if (isset($logo_config['default']) && is_array($logo_config['default'])) {
                if (isset($logo_config['default']['light']) && !empty($logo_config['default']['light'])) {
                    $logo['default']['light'] = $logo_config['default']['light'];
                    error_log('CoinSub Whitelabel: 🖼️ Found default.light logo: ' . $logo['default']['light']);
                }
                if (isset($logo_config['default']['dark']) && !empty($logo_config['default']['dark'])) {
                    $logo['default']['dark'] = $logo_config['default']['dark'];
                    error_log('CoinSub Whitelabel: 🖼️ Found default.dark logo: ' . $logo['default']['dark']);
                }
            }
            
            // Extract square logos
            if (isset($logo_config['square']) && is_array($logo_config['square'])) {
                if (isset($logo_config['square']['light']) && !empty($logo_config['square']['light'])) {
                    $logo['square']['light'] = $logo_config['square']['light'];
                    error_log('CoinSub Whitelabel: 🖼️ Found square.light logo: ' . $logo['square']['light']);
                }
                if (isset($logo_config['square']['dark']) && !empty($logo_config['square']['dark'])) {
                    $logo['square']['dark'] = $logo_config['square']['dark'];
                    error_log('CoinSub Whitelabel: 🖼️ Found square.dark logo: ' . $logo['square']['dark']);
                }
            }
        } else {
            error_log('CoinSub Whitelabel: 🖼️ ⚠️ No logo data found in app_data');
        }
        
        error_log('CoinSub Whitelabel: 🖼️ Extracted logo structure: ' . json_encode($logo, JSON_PRETTY_PRINT));
        
        return $logo;
    }
    
    /**
     * Normalize logo URLs (convert relative to absolute if needed)
     * Logo URLs from config_data are relative paths like "/img/domain/vantack/vantack.light.svg"
     * Need to convert to full URL: "https://dev-api.coinsub.io/img/domain/vantack/vantack.light.svg"
     * 
     * @param array $logo Logo data
     * @return array Normalized logo data
     */
    private function normalize_logo_urls($logo) {
        // $api_base = 'https://dev-api.coinsub.io'; // Base URL for API assets
        $abi_base = 'https://app.coinsub.io/';
        
        error_log('CoinSub Whitelabel: 🖼️ Normalizing logo URLs with base: ' . $api_base);
        error_log('CoinSub Whitelabel: 🖼️ Logo data before normalization: ' . json_encode($logo, JSON_PRETTY_PRINT));
        
        foreach ($logo as $type => &$variants) {
            if (!is_array($variants)) {
                continue;
            }
            
            foreach ($variants as $theme => &$url) {
                if (!empty($url) && is_string($url)) {
                    // If URL doesn't start with http, it's relative - make it absolute
                    if (strpos($url, 'http') !== 0) {
                        // Relative URL, make it absolute
                        if (strpos($url, '/') === 0) {
                            $url = $api_base . $url;
                        } else {
                            $url = $api_base . '/' . $url;
                        }
                        error_log('CoinSub Whitelabel: 🖼️ Converted relative logo URL to: ' . $url);
                    } else {
                        error_log('CoinSub Whitelabel: 🖼️ Logo URL already absolute: ' . $url);
                    }
                }
            }
        }
        
        error_log('CoinSub Whitelabel: 🖼️ Logo data after normalization: ' . json_encode($logo, JSON_PRETTY_PRINT));
        
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
        
        error_log('CoinSub Whitelabel: 🖼️ get_logo_url() called - Type: ' . $type . ', Theme: ' . $theme);
        error_log('CoinSub Whitelabel: 🖼️ Branding data: ' . json_encode($branding, JSON_PRETTY_PRINT));
        
        if (!empty($branding) && isset($branding['logo'][$type][$theme]) && !empty($branding['logo'][$type][$theme])) {
            $logo_url = $branding['logo'][$type][$theme];
            error_log('CoinSub Whitelabel: 🖼️ ✅ Found logo URL: ' . $logo_url);
            return $logo_url;
        }
        
        // Try fallback to dark theme if light not found
        if ($theme === 'light' && !empty($branding) && isset($branding['logo'][$type]['dark']) && !empty($branding['logo'][$type]['dark'])) {
            $logo_url = $branding['logo'][$type]['dark'];
            error_log('CoinSub Whitelabel: 🖼️ ✅ Found logo URL (dark fallback): ' . $logo_url);
            return $logo_url;
        }
        
        // No logo found - return default CoinSub logo
        $default_logo = COINSUB_PLUGIN_URL . 'images/coinsub.svg';
        error_log('CoinSub Whitelabel: 🖼️ ⚠️ No logo found in branding, using default: ' . $default_logo);
        return $default_logo;
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

