<?php
/**
 * Stablecoin Pay Checkout Integration
 * 
 * This file contains the HTML, CSS, and JavaScript for the checkout iframe
 * The iframe URL is whitelabeled based on merchant credentials
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Stablecoin Pay Checkout Styles -->
<!-- Note: Iframe is now displayed on dedicated checkout page, not on main checkout -->
<style>
/* Styles removed - iframe is now on dedicated checkout page */
</style>

<!-- Stablecoin Pay Checkout JavaScript -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Only load CoinSub checkout functionality if we're on checkout page
    if (!$('body').hasClass('woocommerce-checkout') && !$('body').hasClass('woocommerce-page-checkout')) {
        return; // Exit early if not on checkout page
    }
    
    // Prevent double submission
    if (typeof window.coinsubSubmitting === 'undefined') {
        window.coinsubSubmitting = false;
    }
    
    // SIMPLIFIED: Just remove CoinSub logo when switching away, don't touch button text at all
    // Let WooCommerce handle button text completely - it will use "Place order" for all methods
    function removeCoinSubLogo() {
        var $placeOrderButton = $('#place_order');
        if ($placeOrderButton.length === 0) {
            return;
        }
        
        var currentHtml = $placeOrderButton.html();
        if (currentHtml.includes('coinsub-button-logo')) {
            // Remove CoinSub logo only, preserve everything else
            var textWithoutLogo = currentHtml.replace(/<img[^>]*class="coinsub-button-logo"[^>]*>/gi, '');
            $placeOrderButton.html(textWithoutLogo);
            console.log('✅ CoinSub: Removed CoinSub logo - letting WooCommerce handle button text');
        }
    }
    
    // SIMPLIFIED: No iframe on main checkout page - we redirect to dedicated page
    // Just handle logo removal when switching payment methods
    
    // Watch for payment method changes - just remove logo when switching away
    $('body').on('change', 'input[name="payment_method"]', function() {
        var newMethod = $(this).val();
        
        // If switching away from CoinSub, remove logo
        if (newMethod !== 'coinsub') {
            removeCoinSubLogo();
        }
    });
    
    // Override the place order button ONLY for CoinSub
    // This ensures we don't interfere with other payment gateways like Coinbase, Stripe, etc.
    $('body').on('click', '#place_order', function(e) {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        // Only intercept if CoinSub is selected - let other gateways work normally
        if (paymentMethod === 'coinsub') {
            e.preventDefault();
            e.stopPropagation();
            if (window.coinsubSubmitting) {
                return false;
            }
            window.coinsubSubmitting = true;
            
            // Show loading state
            $(this).prop('disabled', true).text('Processing...');
            
            // Process the payment via AJAX
            $.ajax({
                url: wc_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'coinsub_process_payment',
                    security: wc_checkout_params.checkout_nonce,
                    payment_method: 'coinsub',
                    billing_first_name: $('input[name="billing_first_name"]').val(),
                    billing_last_name: $('input[name="billing_last_name"]').val(),
                    billing_email: $('input[name="billing_email"]').val(),
                    billing_phone: $('input[name="billing_phone"]').val(),
                    billing_address_1: $('input[name="billing_address_1"]').val(),
                    billing_city: $('input[name="billing_city"]').val(),
                    billing_state: $('select[name="billing_state"]').val(),
                    billing_postcode: $('input[name="billing_postcode"]').val(),
                    billing_country: $('select[name="billing_country"]').val(),
                    // Shipping address fields
                    shipping_first_name: $('input[name="shipping_first_name"]').val(),
                    shipping_last_name: $('input[name="shipping_last_name"]').val(),
                    shipping_address_1: $('input[name="shipping_address_1"]').val(),
                    shipping_city: $('input[name="shipping_city"]').val(),
                    shipping_state: $('select[name="shipping_state"]').val(),
                    shipping_postcode: $('input[name="shipping_postcode"]').val(),
                    shipping_country: $('select[name="shipping_country"]').val()
                },
                success: function(response) {
                    
                    
                    // Check for different response structures
                    var checkoutUrl = null;
                    
                    if (response.success && response.data) {
                        // Check for redirect (payment gateway returns this)
                        if (response.data.result === 'success' && response.data.redirect) {
                            checkoutUrl = response.data.redirect;
                            console.log('✅ CoinSub - Found redirect URL:', checkoutUrl);
                        }
                        // Check for coinsub_checkout_url (alternative format)
                        else if (response.data.coinsub_checkout_url) {
                            checkoutUrl = response.data.coinsub_checkout_url;
                            console.log('✅ CoinSub - Found checkout URL:', checkoutUrl);
                        }
                    }
                    
                    if (checkoutUrl || response.data.redirect) {
                        // Redirect to dedicated checkout page
                        // The page will display the payment iframe full-page
                        var redirectUrl = response.data.redirect || checkoutUrl;
                        console.log('🔄 Redirecting to dedicated checkout page:', redirectUrl);
                        window.location.href = redirectUrl;
                    } else {
                        console.log('Payment failed - response details:', response);
                        // Show detailed error
                        var errorMsg = 'Payment error: ';
                        if (response.data) {
                            if (typeof response.data === 'string') {
                                errorMsg += response.data;
                            } else if (response.data.message) {
                                errorMsg += response.data.message;
                            } else {
                                errorMsg += JSON.stringify(response.data);
                            }
                        } else {
                            errorMsg += 'Unknown error - no data received';
                        }
                        alert(errorMsg);
                        $('#place_order').prop('disabled', false);
                        // Let WooCommerce handle button text (will be "Place order")
                        window.coinsubSubmitting = false;
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX Error occurred:');
                    console.log('Status:', status);
                    console.log('Error:', error);
                    console.log('Response:', xhr.responseText);
                    
                    var errorMsg = 'Payment error: Unable to process payment';
                    if (xhr.responseText) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data) {
                                errorMsg = 'Payment error: ' + response.data;
                            }
                        } catch(e) {
                            errorMsg = 'Payment error: ' + xhr.responseText;
                        }
                    }
                    
                    alert(errorMsg);
                    $('#place_order').prop('disabled', false);
                    // Let WooCommerce handle button text (will be "Place order")
                    window.coinsubSubmitting = false;
                }
            });
            
            return false;
        }
    });
    
    // Also check when WooCommerce updates checkout (AJAX)
    // Just remove logo if switching away from CoinSub
    $(document.body).on('updated_checkout', function() {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        if (paymentMethod !== 'coinsub') {
            removeCoinSubLogo();
        }
    });
    
    // Also watch for when payment methods are loaded/updated
    $(document.body).on('payment_method_selected', function() {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        if (paymentMethod !== 'coinsub') {
            removeCoinSubLogo();
        }
    });
});
</script>