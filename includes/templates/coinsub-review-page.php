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

$default_branding = array(
    'title'        => __('Stablecoin Pay Review', 'coinsub'),
    'subtitle'     => __('Your dedicated crypto payments experience', 'coinsub'),
    'logo_url'     => COINSUB_PLUGIN_URL . 'images/coinsub.png',
    'powered_by'   => __('Powered by CoinSub', 'coinsub'),
    'description'  => __('Stablecoin Pay delivers a white-label checkout that inherits your brand while leveraging CoinSub’s settlement rails and compliance tooling.', 'coinsub'),
    'highlights'   => array(
        __('Branded checkout flows for every white-label partner', 'coinsub'),
        __('USDC on/off ramps, subscriptions, and automated reconciliation', 'coinsub'),
        __('CoinSub’s infrastructure keeps custody and token routing secure', 'coinsub'),
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

        <?php if (!empty($branding['highlights']) && is_array($branding['highlights'])) : ?>
            <ul class="coinsub-review__highlights">
                <?php foreach ($branding['highlights'] as $highlight) : ?>
                    <li><?php echo esc_html($highlight); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="coinsub-review__support"><?php echo esc_html($branding['support_text']); ?></p>

        <?php if (!empty($branding['cta_url'])) : ?>
            <a class="coinsub-review__cta button" href="<?php echo esc_url($branding['cta_url']); ?>">
                <?php echo esc_html($branding['cta_text']); ?>
            </a>
        <?php endif; ?>
    </section>
</main>

<style>
    .coinsub-review {
        max-width: 720px;
        margin: 0 auto;
        padding: 4rem 1.5rem;
        text-align: center;
    }
    .coinsub-review__hero {
        margin-bottom: 3rem;
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
    }
    .coinsub-review__subtitle {
        font-size: 1.2rem;
        color: #444;
    }
    .coinsub-review__description {
        font-size: 1.1rem;
        line-height: 1.6;
        margin-bottom: 2rem;
    }
    .coinsub-review__highlights {
        list-style: none;
        padding: 0;
        margin: 0 0 2rem 0;
    }
    .coinsub-review__highlights li {
        margin-bottom: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        background: #f6f8fb;
        border: 1px solid #e1e5ee;
    }
    .coinsub-review__support {
        color: #555;
        margin-bottom: 2rem;
    }
    .coinsub-review__cta.button {
        display: inline-block;
        padding: 0.85rem 1.75rem;
        font-size: 1rem;
        border-radius: 999px;
        color: #fff;
        background: #111827;
        text-decoration: none;
    }
    .coinsub-review__cta.button:hover {
        opacity: 0.9;
    }
</style>

<?php
get_footer();

