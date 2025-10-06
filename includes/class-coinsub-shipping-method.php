<?php
/**
 * CoinSub Shipping Method Class
 * 
 * This class handles shipping calculations for CoinSub Commerce integration
 * It calculates shipping costs that will be included in the crypto payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_CoinSub_Shipping_Method')) {
    class WC_CoinSub_Shipping_Method extends WC_Shipping_Method {
        
        /**
         * Shipping method cost
         *
         * @var string
         */
        public $cost;

        /**
         * Shipping method type
         *
         * @var string
         */
        public $type;

        /**
         * Constructor for CoinSub shipping method
         *
         * @param int $instance_id Shipping method instance ID
         */
        public function __construct($instance_id = 0) {
            $this->id = 'coinsub_shipping';
            $this->instance_id = absint($instance_id);
            $this->method_title = __('CoinSub Shipping', 'coinsub-shipping');
            $this->method_description = __('Custom shipping method for CoinSub Commerce integration. Shipping costs will be included in the crypto payment.', 'coinsub-shipping');
            
            $this->supports = array(
                'settings',
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );

            $this->init();
        }

        /**
         * Initialize the shipping method
         */
        public function init() {
            // Save settings in admin
            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));

            // Initialize form fields
            $this->init_form_fields();
            $this->init_instance_form_fields();

            // Load settings
            $this->title = $this->get_option('title', __('CoinSub Shipping', 'coinsub-shipping'));
            $this->tax_status = $this->get_option('tax_status', 'taxable');
            $this->cost = $this->get_option('cost', '0');
            $this->type = $this->get_option('type', 'class');
        }

        /**
         * Initialize form fields for standalone settings
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'title' => array(
                    'title' => __('Method Title', 'coinsub-shipping'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'coinsub-shipping'),
                    'default' => __('CoinSub Shipping', 'coinsub-shipping'),
                    'desc_tip' => true,
                ),
                'tax_status' => array(
                    'title' => __('Tax Status', 'coinsub-shipping'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => 'taxable',
                    'options' => array(
                        'taxable' => __('Taxable', 'coinsub-shipping'),
                        'none' => _x('None', 'Tax status', 'coinsub-shipping'),
                    ),
                ),
                'cost' => array(
                    'title' => __('Cost', 'coinsub-shipping'),
                    'type' => 'text',
                    'placeholder' => '0',
                    'description' => __('Enter a cost (excl. tax). This will be included in the crypto payment.', 'coinsub-shipping'),
                    'default' => '0',
                    'desc_tip' => true,
                    'sanitize_callback' => array($this, 'sanitize_cost'),
                ),
                'free_shipping_threshold' => array(
                    'title' => __('Free Shipping Threshold', 'coinsub-shipping'),
                    'type' => 'text',
                    'placeholder' => '0',
                    'description' => __('Minimum order amount for free shipping (leave empty to disable).', 'coinsub-shipping'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'crypto_payment_note' => array(
                    'title' => __('Payment Information', 'coinsub-shipping'),
                    'type' => 'title',
                    'description' => __('<strong>Note:</strong> Shipping costs will be included in the cryptocurrency payment. You will need to convert crypto to fiat to pay shipping companies (UPS, FedEx, USPS) separately.', 'coinsub-shipping'),
                ),
            );
        }

        /**
         * Initialize form fields for instance settings
         */
        private function init_instance_form_fields() {
            $cost_desc = __('Enter a cost (excl. tax). This will be included in the crypto payment.', 'coinsub-shipping');
            
            $fields = array(
                'title' => array(
                    'title' => __('Method Title', 'coinsub-shipping'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'coinsub-shipping'),
                    'default' => __('CoinSub Shipping', 'coinsub-shipping'),
                    'desc_tip' => true,
                ),
                'tax_status' => array(
                    'title' => __('Tax Status', 'coinsub-shipping'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => 'taxable',
                    'options' => array(
                        'taxable' => __('Taxable', 'coinsub-shipping'),
                        'none' => _x('None', 'Tax status', 'coinsub-shipping'),
                    ),
                ),
                'cost' => array(
                    'title' => __('Cost', 'coinsub-shipping'),
                    'type' => 'text',
                    'class' => 'wc-shipping-modal-price',
                    'placeholder' => '0',
                    'description' => $cost_desc,
                    'default' => '0',
                    'desc_tip' => true,
                    'sanitize_callback' => array($this, 'sanitize_cost'),
                ),
                'free_shipping_threshold' => array(
                    'title' => __('Free Shipping Threshold', 'coinsub-shipping'),
                    'type' => 'text',
                    'class' => 'wc-shipping-modal-price',
                    'placeholder' => '0',
                    'description' => __('Minimum order amount for free shipping (leave empty to disable).', 'coinsub-shipping'),
                    'default' => '',
                    'desc_tip' => true,
                ),
            );

            // Add shipping class costs if shipping classes exist
            $shipping_classes = WC()->shipping()->get_shipping_classes();
            if (!empty($shipping_classes)) {
                $fields['class_costs'] = array(
                    'title' => __('Shipping Class Costs', 'coinsub-shipping'),
                    'type' => 'title',
                    'description' => sprintf(__('These costs can optionally be added based on the <a target="_blank" href="%s">product shipping class</a>.', 'coinsub-shipping'), admin_url('admin.php?page=wc-settings&tab=shipping&section=classes')),
                );

                foreach ($shipping_classes as $shipping_class) {
                    if (!isset($shipping_class->term_id)) {
                        continue;
                    }
                    $fields['class_cost_' . $shipping_class->term_id] = array(
                        'title' => sprintf(__('"%s" shipping class cost', 'coinsub-shipping'), esc_html($shipping_class->name)),
                        'type' => 'text',
                        'class' => 'wc-shipping-modal-price',
                        'placeholder' => __('N/A', 'coinsub-shipping'),
                        'description' => $cost_desc,
                        'default' => $this->get_option('class_cost_' . $shipping_class->slug),
                        'desc_tip' => true,
                        'sanitize_callback' => array($this, 'sanitize_cost'),
                    );
                }

                $fields['no_class_cost'] = array(
                    'title' => __('No shipping class cost', 'coinsub-shipping'),
                    'type' => 'text',
                    'class' => 'wc-shipping-modal-price',
                    'placeholder' => __('N/A', 'coinsub-shipping'),
                    'description' => $cost_desc,
                    'default' => '',
                    'desc_tip' => true,
                    'sanitize_callback' => array($this, 'sanitize_cost'),
                );

                $fields['type'] = array(
                    'title' => __('Calculation Type', 'coinsub-shipping'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => 'class',
                    'options' => array(
                        'class' => __('Per class: Charge shipping for each shipping class individually', 'coinsub-shipping'),
                        'order' => __('Per order: Charge shipping for the most expensive shipping class', 'coinsub-shipping'),
                    ),
                );
            }

            $this->instance_form_fields = $fields;
        }

        /**
         * Calculate shipping costs
         *
         * @param array $package Package of items from cart
         */
        public function calculate_shipping($package = array()) {
            // Check if free shipping threshold is met
            $free_shipping_threshold = $this->get_option('free_shipping_threshold');
            if (!empty($free_shipping_threshold) && is_numeric($free_shipping_threshold)) {
                $cart_total = WC()->cart->get_subtotal();
                if ($cart_total >= $free_shipping_threshold) {
                    $this->add_rate(array(
                        'id' => $this->get_rate_id(),
                        'label' => $this->title . ' (' . __('Free', 'coinsub-shipping') . ')',
                        'cost' => 0,
                        'package' => $package,
                    ));
                    return;
                }
            }

            // Calculate shipping cost
            $rate = array(
                'id' => $this->get_rate_id(),
                'label' => $this->title,
                'cost' => 0,
                'package' => $package,
            );

            $has_costs = false;
            $cost = $this->get_option('cost');

            // Add base cost
            if ('' !== $cost) {
                $has_costs = true;
                $rate['cost'] = $cost;
            }

            // Add shipping class costs
            $shipping_classes = WC()->shipping()->get_shipping_classes();
            if (!empty($shipping_classes)) {
                $found_shipping_classes = $this->find_shipping_classes($package);
                $highest_class_cost = 0;

                foreach ($found_shipping_classes as $shipping_class => $products) {
                    $shipping_class_term = get_term_by('slug', $shipping_class, 'product_shipping_class');
                    $class_cost = $shipping_class_term && $shipping_class_term->term_id ? 
                        $this->get_option('class_cost_' . $shipping_class_term->term_id, $this->get_option('class_cost_' . $shipping_class, '')) : 
                        $this->get_option('no_class_cost', '');

                    if ('' === $class_cost) {
                        continue;
                    }

                    $has_costs = true;

                    if ('class' === $this->type) {
                        $rate['cost'] += $class_cost;
                    } else {
                        $highest_class_cost = $class_cost > $highest_class_cost ? $class_cost : $highest_class_cost;
                    }
                }

                if ('order' === $this->type && $highest_class_cost) {
                    $rate['cost'] += $highest_class_cost;
                }
            }

            if ($has_costs) {
                $this->add_rate($rate);
            }

            // Allow other plugins to modify rates
            do_action('woocommerce_' . $this->id . '_shipping_add_rate', $this, $rate);
        }

        /**
         * Find shipping classes in package
         *
         * @param array $package Package of items from cart
         * @return array
         */
        public function find_shipping_classes($package) {
            $found_shipping_classes = array();

            foreach ($package['contents'] as $item_id => $values) {
                if ($values['data']->needs_shipping()) {
                    $found_class = $values['data']->get_shipping_class();

                    if (!isset($found_shipping_classes[$found_class])) {
                        $found_shipping_classes[$found_class] = array();
                    }

                    $found_shipping_classes[$found_class][$item_id] = $values;
                }
            }

            return $found_shipping_classes;
        }

        /**
         * Sanitize cost value
         *
         * @param string $value Unsanitized value
         * @return string
         * @throws Exception If the cost is not numeric
         */
        public function sanitize_cost($value) {
            $value = is_null($value) ? '0' : $value;
            $value = wp_kses_post(trim(wp_unslash($value)));
            $value = str_replace(array(get_woocommerce_currency_symbol(), html_entity_decode(get_woocommerce_currency_symbol())), '', $value);

            $locale = localeconv();
            $decimals = array(wc_get_price_decimal_separator(), $locale['decimal_point'], $locale['mon_decimal_point'], ',');

            $value = preg_replace('/\s+/', '', $value);
            $value = str_replace($decimals, '.', $value);
            $value = rtrim(ltrim($value, "\t\n\r\0\x0B+*/"), "\t\n\r\0\x0B+-*/");

            if (!is_numeric($value)) {
                throw new Exception(__('Invalid cost entered.', 'coinsub-shipping'));
            }

            return $value;
        }
    }
}
?>
