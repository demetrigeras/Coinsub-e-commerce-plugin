<?php
/**
 * Stablecoin Pay review / explainer page template.
 *
 * This page helps merchants understand that Stablecoin Pay is powered by CoinSub.
 *
 * @package CoinSub
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Get whitelabel branding
$whitelabel_branding = new CoinSub_Whitelabel_Branding();
$branding_data = $whitelabel_branding->get_branding();

$default_branding = array(
    'title'        => sprintf(__('About %s', 'coinsub'), $branding_data['company']),
    'subtitle'     => __('Your dedicated crypto payments experience', 'coinsub'),
    'logo_url'     => $whitelabel_branding->get_logo_url('default', 'light'),
    'powered_by'   => $branding_data['powered_by'],
    'description'  => sprintf(__('%s delivers a white-label checkout that inherits your brand while leveraging CoinSub\'s settlement rails and compliance tooling.', 'coinsub'), $branding_data['company']),
    'what_is'      => sprintf(__('%s is a cryptocurrency payment solution that enables your customers to pay with USDC and other stablecoins directly from their crypto wallets. All transactions are processed securely on the blockchain with instant settlement.', 'coinsub'), $branding_data['company']),
    'how_it_works' => array(
        sprintf(__('Customer selects %s at checkout', 'coinsub'), $branding_data['company']) => sprintf(__('Your customers choose %s as their payment method during checkout', 'coinsub'), $branding_data['company']),
        __('Connect crypto wallet', 'coinsub') => __('Customers connect their Web3 wallet (MetaMask, WalletConnect, etc.)', 'coinsub'),
        __('Approve payment', 'coinsub') => __('Customers approve the transaction in their wallet', 'coinsub'),
        __('Instant confirmation', 'coinsub') => __('Payment is confirmed on-chain and your order is automatically processed', 'coinsub'),
    ),
    'features'     => array(
        __('Accept USDC payments', 'coinsub') => __('Receive payments in USDC on multiple networks (Polygon, Ethereum, etc.)', 'coinsub'),
        __('Subscriptions support', 'coinsub') => __('Set up recurring payments for subscription products', 'coinsub'),
        __('Automatic reconciliation', 'coinsub') => __('Orders update automatically when payments are confirmed', 'coinsub'),
        __('Secure & compliant', 'coinsub') => __('CoinSub handles custody, compliance, and security infrastructure', 'coinsub'),
    ),
    'highlights'   => array(
        __('Branded checkout flows for every white-label partner', 'coinsub'),
        __('USDC on/off ramps, subscriptions, and automated reconciliation', 'coinsub'),
        __('CoinSub\'s infrastructure keeps custody and token routing secure', 'coinsub'),
    ),
    'cta_text'     => __('Return to Checkout', 'coinsub'),
    'cta_url'      => wc_get_page_permalink('checkout'),
    'support_text' => __('Need help linking your merchant ID or API key? Reach out to your platform admin or CoinSub support to get branded assets configured.', 'coinsub'),
);

$branding = wp_parse_args(
    apply_filters('coinsub_review_page_branding', array(), get_current_user_id()),
    $default_branding
);
?>

<main id="coinsub-review" class="coinsub-review">
    <div class="coinsub-review__hero">
        <?php if (!empty($branding['logo_url'])) : ?>
            <img class="coinsub-review__logo" src="<?php echo esc_url($branding['logo_url']); ?>" alt="<?php echo esc_attr($branding['powered_by']); ?>" />
        <?php endif; ?>
        <p class="coinsub-review__powered"><?php echo esc_html($branding['powered_by']); ?></p>
        <h1><?php echo esc_html($branding['title']); ?></h1>
        <p class="coinsub-review__subtitle"><?php echo esc_html($branding['subtitle']); ?></p>
    </div>

    <section class="coinsub-review__content">
        <p class="coinsub-review__description"><?php echo esc_html($branding['description']); ?></p>

        <?php if (!empty($branding['what_is'])) : ?>
            <div class="coinsub-review__section">
                <h2><?php _e('What is this?', 'coinsub'); ?></h2>
                <p><?php echo esc_html($branding['what_is']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($branding['how_it_works']) && is_array($branding['how_it_works'])) : ?>
            <div class="coinsub-review__section">
                <h2><?php _e('How it works', 'coinsub'); ?></h2>
                <ol class="coinsub-review__steps">
                    <?php foreach ($branding['how_it_works'] as $step_title => $step_description) : ?>
                        <li>
                            <strong><?php echo esc_html($step_title); ?></strong>
                            <span><?php echo esc_html($step_description); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php if (!empty($branding['features']) && is_array($branding['features'])) : ?>
            <div class="coinsub-review__section">
                <h2><?php _e('Key Features', 'coinsub'); ?></h2>
                <ul class="coinsub-review__features">
                    <?php foreach ($branding['features'] as $feature_title => $feature_description) : ?>
                        <li>
                            <strong><?php echo esc_html($feature_title); ?></strong>
                            <span><?php echo esc_html($feature_description); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($branding['highlights']) && is_array($branding['highlights'])) : ?>
            <div class="coinsub-review__section">
                <h2><?php _e('Why choose this?', 'coinsub'); ?></h2>
                <ul class="coinsub-review__highlights">
                    <?php foreach ($branding['highlights'] as $highlight) : ?>
                        <li><?php echo esc_html($highlight); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="coinsub-review__section coinsub-review__footer">
            <p class="coinsub-review__support"><?php echo esc_html($branding['support_text']); ?></p>

            <?php if (!empty($branding['cta_url'])) : ?>
                <a class="coinsub-review__cta button" href="<?php echo esc_url($branding['cta_url']); ?>">
                    <?php echo esc_html($branding['cta_text']); ?>
                </a>
            <?php endif; ?>
        </div>
    </section>
</main>

<style>
    .coinsub-review {
        max-width: 800px;
        margin: 0 auto;
        padding: 4rem 1.5rem;
    }
    .coinsub-review__hero {
        margin-bottom: 3rem;
        text-align: center;
    }
    .coinsub-review__logo {
        max-height: 64px;
        margin-bottom: 1rem;
    }
    .coinsub-review__powered {
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #666;
        margin-bottom: 0.5rem;
    }
    .coinsub-review__subtitle {
        font-size: 1.2rem;
        color: #444;
    }
    .coinsub-review__content {
        text-align: left;
    }
    .coinsub-review__description {
        font-size: 1.1rem;
        line-height: 1.6;
        margin-bottom: 2rem;
        color: #333;
    }
    .coinsub-review__section {
        margin-bottom: 3rem;
    }
    .coinsub-review__section h2 {
        font-size: 1.5rem;
        margin-bottom: 1.5rem;
        color: #111827;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 0.5rem;
    }
    .coinsub-review__steps {
        list-style: none;
        padding: 0;
        margin: 0;
        counter-reset: step-counter;
    }
    .coinsub-review__steps li {
        counter-increment: step-counter;
        margin-bottom: 1.5rem;
        padding-left: 3rem;
        position: relative;
    }
    .coinsub-review__steps li::before {
        content: counter(step-counter);
        position: absolute;
        left: 0;
        top: 0;
        width: 2rem;
        height: 2rem;
        background: #111827;
        color: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 0.9rem;
    }
    .coinsub-review__steps li strong {
        display: block;
        font-size: 1.1rem;
        margin-bottom: 0.5rem;
        color: #111827;
    }
    .coinsub-review__steps li span {
        display: block;
        color: #555;
        line-height: 1.6;
    }
    .coinsub-review__features {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .coinsub-review__features li {
        margin-bottom: 1.5rem;
        padding: 1rem;
        background: #f9fafb;
        border-left: 4px solid #3b82f6;
        border-radius: 4px;
    }
    .coinsub-review__features li strong {
        display: block;
        font-size: 1.05rem;
        margin-bottom: 0.5rem;
        color: #111827;
    }
    .coinsub-review__features li span {
        display: block;
        color: #555;
        line-height: 1.6;
    }
    .coinsub-review__highlights {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .coinsub-review__highlights li {
        margin-bottom: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        background: #f6f8fb;
        border: 1px solid #e1e5ee;
        position: relative;
        padding-left: 2.5rem;
    }
    .coinsub-review__highlights li::before {
        content: "✓";
        position: absolute;
        left: 1rem;
        color: #10b981;
        font-weight: bold;
        font-size: 1.2rem;
    }
    .coinsub-review__footer {
        text-align: center;
        margin-top: 3rem;
        padding-top: 2rem;
        border-top: 1px solid #e5e7eb;
    }
    .coinsub-review__support {
        color: #555;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }
    .coinsub-review__cta.button {
        display: inline-block;
        padding: 0.85rem 1.75rem;
        font-size: 1rem;
        border-radius: 999px;
        color: #fff;
        background: #111827;
        text-decoration: none;
        transition: opacity 0.2s;
    }
    .coinsub-review__cta.button:hover {
        opacity: 0.9;
    }
</style>

<?php
get_footer();

