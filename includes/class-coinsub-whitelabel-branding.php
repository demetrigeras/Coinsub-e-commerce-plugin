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
     * Cache key for branding data
     */
    const BRANDING_CACHE_KEY = 'coinsub_whitelabel_branding';
    const BRANDING_CACHE_EXPIRY = 3600; // 1 hour
    
    /**
     * Default branding fallback
     */
    private $default_branding = array(
        'company' => 'Stablecoin Pay',
        'powered_by' => 'Powered by CoinSub',
        'logo' => array(
            'default' => array(
                'light' => COINSUB_PLUGIN_URL . 'images/coinsub.png',
                'dark' => COINSUB_PLUGIN_URL . 'images/coinsub.png'
            ),
            'square' => array(
                'light' => COINSUB_PLUGIN_URL . 'images/coinsub.png',
                'dark' => COINSUB_PLUGIN_URL . 'images/coinsub.png'
            )
        ),
        'favicon' => '',
        'buyurl' => '',
        'documentation_url' => '',
        'privacy_policy_url' => '',
        'terms_of_service_url' => '',
        'copyright' => ''
    );
    
    /**
     * API client instance
     */
    private $api_client;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_client = new CoinSub_API_Client();
    }
    
    /**
     * Get whitelabel branding for current merchant
     * 
     * @return array Branding data (company, logo, etc.)
     */
    public function get_branding() {
        // Check cache first
        $cached = get_transient(self::BRANDING_CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }
        
        // Get merchant ID from settings
        $gateway_settings = get_option('woocommerce_coinsub_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $api_key = isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';
        
        if (empty($merchant_id) || empty($api_key)) {
            // No credentials, return default
            return $this->default_branding;
        }
        
        // Fetch submerchant data to get parent merchant ID
        $submerchant_data = $this->api_client->get_submerchant($merchant_id);
        
        if (is_wp_error($submerchant_data)) {
            error_log('CoinSub Whitelabel: Failed to get submerchant data: ' . $submerchant_data->get_error_message());
            return $this->default_branding;
        }
        
        // Extract parent merchant ID from submerchant data
        // The submerchant API response should include the parent merchant ID
        // Based on the Go code, the response structure may vary, so check multiple locations
        $parent_merchant_id = null;
        
        // Log full response for debugging
        error_log('CoinSub Whitelabel: Submerchant data response: ' . json_encode($submerchant_data));
        
        // Check various possible response structures (handle both wrapped and unwrapped responses)
        $response_data = isset($submerchant_data['data']) ? $submerchant_data['data'] : $submerchant_data;
        
        // Try to find parent merchant ID in different possible locations
        // 1. Direct field in response data
        if (isset($response_data['MerchantID']) && !empty($response_data['MerchantID'])) {
            // If MerchantID exists and is different from the submerchant ID, it's likely the parent
            if ($response_data['MerchantID'] !== $merchant_id) {
                $parent_merchant_id = $response_data['MerchantID'];
            }
        }
        
        // 2. Check for parent_merchant_id or parentMerchantID fields
        if (empty($parent_merchant_id)) {
            if (isset($response_data['parent_merchant_id']) && !empty($response_data['parent_merchant_id'])) {
                $parent_merchant_id = $response_data['parent_merchant_id'];
            } elseif (isset($response_data['parentMerchantID']) && !empty($response_data['parentMerchantID'])) {
                $parent_merchant_id = $response_data['parentMerchantID'];
            } elseif (isset($response_data['ParentMerchantID']) && !empty($response_data['ParentMerchantID'])) {
                $parent_merchant_id = $response_data['ParentMerchantID'];
            }
        }
        
        // 3. Check in submerchant relationship data if present
        if (empty($parent_merchant_id) && isset($response_data['submerchant_relationship'])) {
            $relationship = $response_data['submerchant_relationship'];
            if (isset($relationship['parent_merchant_id'])) {
                $parent_merchant_id = $relationship['parent_merchant_id'];
            } elseif (isset($relationship['ParentMerchantID'])) {
                $parent_merchant_id = $relationship['ParentMerchantID'];
            }
        }
        
        // 4. Fallback: check top-level fields
        if (empty($parent_merchant_id)) {
            if (isset($submerchant_data['MerchantID']) && $submerchant_data['MerchantID'] !== $merchant_id) {
                $parent_merchant_id = $submerchant_data['MerchantID'];
            } elseif (isset($submerchant_data['parent_merchant_id'])) {
                $parent_merchant_id = $submerchant_data['parent_merchant_id'];
            }
        }
        
        error_log('CoinSub Whitelabel: Extracted parent merchant ID: ' . ($parent_merchant_id ?: 'NOT FOUND'));
        error_log('CoinSub Whitelabel: Submerchant ID (from settings): ' . $merchant_id);
        
        if (empty($parent_merchant_id)) {
            // No parent merchant ID found, return default
            error_log('CoinSub Whitelabel: No parent merchant ID found in submerchant data. Using default branding.');
            return $this->default_branding;
        }
        
        // Fetch environment configs
        $env_configs = $this->api_client->get_environment_configs();
        
        if (is_wp_error($env_configs)) {
            error_log('CoinSub Whitelabel: Failed to get environment configs: ' . $env_configs->get_error_message());
            return $this->default_branding;
        }
        
        // Match parent merchant ID to config_data
        $branding = $this->match_merchant_to_branding($parent_merchant_id, $env_configs);
        
        // Cache the result
        set_transient(self::BRANDING_CACHE_KEY, $branding, self::BRANDING_CACHE_EXPIRY);
        
        return $branding;
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
            return $this->default_branding;
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
            
            if (!is_array($config_data)) {
                error_log('CoinSub Whitelabel: Config #' . $index . ' config_data is not an array');
                continue;
            }
            
            // Check if config_data has 'app' key (based on user's example structure)
            if (!isset($config_data['app'])) {
                // Try alternative structure: maybe config_data is directly the app data
                if (isset($config_data['merchantID'])) {
                    // This might be the app data directly
                    $config_data = array('app' => $config_data);
                } else {
                    error_log('CoinSub Whitelabel: Config #' . $index . ' missing app key in config_data');
                    continue;
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
                error_log('CoinSub Whitelabel: Config #' . $index . ' missing merchantID in app data');
                continue;
            }
            
            // Compare merchant IDs (case-insensitive, trim whitespace)
            $parent_id_normalized = strtolower(trim($parent_merchant_id));
            $config_id_normalized = strtolower(trim($config_merchant_id));
            
            error_log('CoinSub Whitelabel: Comparing parent ID "' . $parent_id_normalized . '" with config ID "' . $config_id_normalized . '"');
            
            if ($config_id_normalized === $parent_id_normalized) {
                // Found match! Extract branding data
                $branding = array(
                    'company' => isset($app_data['company']) ? $app_data['company'] : $this->default_branding['company'],
                    'powered_by' => 'Powered by CoinSub', // Always show this
                    'logo' => $this->extract_logo_data($app_data),
                    'favicon' => isset($app_data['favicon']) ? $app_data['favicon'] : '',
                    'buyurl' => isset($app_data['buyurl']) ? $app_data['buyurl'] : '',
                    'documentation_url' => isset($app_data['documentation_url']) ? $app_data['documentation_url'] : '',
                    'privacy_policy_url' => isset($app_data['privacy_policy_url']) ? $app_data['privacy_policy_url'] : '',
                    'terms_of_service_url' => isset($app_data['terms_of_service_url']) ? $app_data['terms_of_service_url'] : '',
                    'copyright' => isset($app_data['copyright']) ? $app_data['copyright'] : ''
                );
                
                // Convert relative logo URLs to absolute if needed
                $branding['logo'] = $this->normalize_logo_urls($branding['logo']);
                
                error_log('CoinSub Whitelabel: ✅ Matched branding for merchant ' . $parent_merchant_id . ' - Company: ' . $branding['company']);
                
                return $branding;
            }
        }
        
        // No match found, return default
        error_log('CoinSub Whitelabel: ❌ No matching branding found for parent merchant ID: ' . $parent_merchant_id);
        error_log('CoinSub Whitelabel: Available merchant IDs in configs: ' . json_encode(array_map(function($config) {
            $config_data = is_string($config['config_data']) ? json_decode($config['config_data'], true) : $config['config_data'];
            return isset($config_data['app']['merchantID']) ? $config_data['app']['merchantID'] : 'N/A';
        }, $env_configs['environment_configs'])));
        
        return $this->default_branding;
    }
    
    /**
     * Extract logo data from app config
     * 
     * @param array $app_data App config data
     * @return array Logo URLs
     */
    private function extract_logo_data($app_data) {
        $logo = $this->default_branding['logo'];
        
        if (isset($app_data['logo'])) {
            $logo_config = $app_data['logo'];
            
            // Extract default logos
            if (isset($logo_config['default'])) {
                if (isset($logo_config['default']['light'])) {
                    $logo['default']['light'] = $logo_config['default']['light'];
                }
                if (isset($logo_config['default']['dark'])) {
                    $logo['default']['dark'] = $logo_config['default']['dark'];
                }
            }
            
            // Extract square logos
            if (isset($logo_config['square'])) {
                if (isset($logo_config['square']['light'])) {
                    $logo['square']['light'] = $logo_config['square']['light'];
                }
                if (isset($logo_config['square']['dark'])) {
                    $logo['square']['dark'] = $logo_config['square']['dark'];
                }
            }
        }
        
        return $logo;
    }
    
    /**
     * Normalize logo URLs (convert relative to absolute if needed)
     * 
     * @param array $logo Logo data
     * @return array Normalized logo data
     */
    private function normalize_logo_urls($logo) {
        $api_base = 'https://test-api.coinsub.io'; // Base URL for API assets
        
        foreach ($logo as $type => &$variants) {
            foreach ($variants as $theme => &$url) {
                if (!empty($url) && strpos($url, 'http') !== 0) {
                    // Relative URL, make it absolute
                    if (strpos($url, '/') === 0) {
                        $url = $api_base . $url;
                    } else {
                        $url = $api_base . '/' . $url;
                    }
                }
            }
        }
        
        return $logo;
    }
    
    /**
     * Clear branding cache (call when merchant credentials change)
     */
    public function clear_cache() {
        delete_transient(self::BRANDING_CACHE_KEY);
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
        
        if (isset($branding['logo'][$type][$theme])) {
            return $branding['logo'][$type][$theme];
        }
        
        // Fallback to default logo
        return $this->default_branding['logo']['default']['light'];
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

