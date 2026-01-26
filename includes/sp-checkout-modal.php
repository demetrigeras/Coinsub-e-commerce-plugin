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
<style>
#coinsub-checkout-container {
	margin: 20px 0;
	background: white;
	border-radius: 16px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
	overflow: hidden;
	display: none; /* Hidden by default */
}

#coinsub-checkout-iframe {
	width: 100%;
	height: 800px;
	border: none;
}

/* Hide button when checkout iframe is visible - don't interfere with other payment methods */
body.coinsub-iframe-visible .woocommerce-checkout .form-row.place-order,
body.coinsub-iframe-visible .woocommerce-checkout #place_order {
	display: none !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
	#coinsub-checkout-container {
		margin: 10px 0;
	}
	
	#coinsub-checkout-iframe {
		height: 600px;
	}
}
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
    
    // Handle CoinSub button visibility based on iframe state
    function ensurePlaceOrderButtonVisibility() {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        // Only handle CoinSub - completely ignore other payment methods
        if (paymentMethod !== 'coinsub') {
            // For non-CoinSub methods, just hide CoinSub iframe
            $('#coinsub-checkout-container').hide();
            $('body').removeClass('coinsub-iframe-visible');
            removeCoinSubLogo();
            return;
        }
        
        // For CoinSub, handle button visibility based on iframe state
        var $placeOrderRow = $('.woocommerce-checkout .form-row.place-order');
        var $placeOrderButton = $('#place_order');
        
        if ($('#coinsub-checkout-container').is(':visible')) {
            // Hide button when CoinSub iframe is visible
            $placeOrderRow.hide();
            $placeOrderButton.hide();
            $('body').addClass('coinsub-iframe-visible');
            console.log('🔒 CoinSub: Hiding Place Order button (CoinSub iframe visible)');
        } else {
            // Show button when CoinSub is selected but iframe not visible yet
            $placeOrderRow.show();
            $placeOrderButton.show();
            $('body').removeClass('coinsub-iframe-visible');
            console.log('✅ CoinSub: Showing Place Order button (CoinSub selected, no iframe)');
        }
    }
    
    // Watch for payment method changes
    $('body').on('change', 'input[name="payment_method"]', function() {
        var newMethod = $(this).val();
        console.log('🔄 CoinSub: Payment method changed to: ' + newMethod);
        
        if (newMethod === 'coinsub') {
            ensurePlaceOrderButtonVisibility();
        } else {
            // For other payment methods, remove CoinSub logo and hide iframe
            removeCoinSubLogo();
            $('#coinsub-checkout-container').hide();
            $('body').removeClass('coinsub-iframe-visible');
            ensurePlaceOrderButtonVisibility();
        }
    });
    
    // Initialize button visibility
    setTimeout(function() {
        ensurePlaceOrderButtonVisibility();
    }, 100);
    
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
                    // Get the checkout URL from the response
                    // The response should include coinsub_checkout_url (the API checkout URL)
                    // NOTE: Do not log checkout URL in console for security (one-time use URL)
                    var checkoutUrl = null;
                    
                    if (response.success && response.data) {
                        // PRIORITY: Get coinsub_checkout_url (the actual API checkout URL for iframe)
                        if (response.data.coinsub_checkout_url) {
                            checkoutUrl = response.data.coinsub_checkout_url;
                            // Security: Don't log checkout URL in console (sensitive one-time use URL)
                        } else {
                            console.error('❌ CoinSub - No coinsub_checkout_url in response!');
                            // Don't log full response data - may contain sensitive info
                        }
                    }
                    
                    if (checkoutUrl) {
                        // Security: Checkout URL is sensitive (one-time use) - don't log it
                        
                        // Remove any existing iframe to prevent duplicates
                        $('#coinsub-checkout-iframe').remove();
                        $('#coinsub-checkout-container').remove();
                        
                        // Create iframe container above the payment button
                        var iframeContainer = $('<div id="coinsub-checkout-container" style="margin: 20px 0; background: white; border-radius: 16px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); overflow: hidden;"><iframe id="coinsub-checkout-iframe" src="' + checkoutUrl + '" style="width: 100%; height: 800px; border: none;" allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *; clipboard-write *" onload="handleCoinSubIframeLoad()"></iframe></div>');
                        
                        // Insert above the payment button
                        $('.woocommerce-checkout .form-row.place-order').before(iframeContainer);
                        
                        // Show the iframe container
                        $('#coinsub-checkout-container').show();
                        $('body').addClass('coinsub-iframe-visible');
                        
                        // Hide the payment button
                        $('.woocommerce-checkout .form-row.place-order').hide();
                        $('#place_order').hide();
                        
                        // Set up iframe redirect detection
                        setupCoinSubIframeRedirectDetection();
                        
                        // Security: Don't log that iframe was embedded (URL is sensitive)
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
    
    // Set up iframe redirect detection
    function setupCoinSubIframeRedirectDetection() {
        // Listen for postMessage events from the iframe
        window.addEventListener('message', function(event) {
            // Security: Don't log message data - may contain sensitive URLs
            
            // Check if this is a redirect message
            if (event.data && typeof event.data === 'object') {
                if (event.data.type === 'redirect' && event.data.url) {
                    // Security: Don't log redirect URL (sensitive)
                    window.location.href = event.data.url;
                }
            }
            
            // Also check for URL changes in the iframe
            if (event.data && typeof event.data === 'string' && event.data.includes('order-received')) {
                // Security: Don't log order-received URL (sensitive)
                window.location.href = event.data;
            }
        });
        
        // Check iframe URL periodically for redirects
        var checkInterval = setInterval(function() {
            try {
                var iframe = document.getElementById('coinsub-checkout-iframe');
                if (iframe && iframe.contentWindow) {
                    var iframeUrl = iframe.contentWindow.location.href;
                    
                    // Check if iframe has redirected to order-received page
                    if (iframeUrl.includes('order-received')) {
                        // Security: Don't log iframe URL (sensitive)
                        clearInterval(checkInterval);
                        window.location.href = iframeUrl;
                        return;
                    }
                }
            } catch(e) {
                // Cross-origin restrictions - this is expected
                // The iframe may have redirected to a different domain
            }
        }, 1000);
        
        // Stop checking after 5 minutes
        setTimeout(function() {
            clearInterval(checkInterval);
        }, 300000);
    }
    
    // Handle iframe load
    function handleCoinSubIframeLoad() {
        // Security: Don't log iframe load (URL is sensitive)
        setupCoinSubIframeRedirectDetection();
    }
    
    // Make functions available globally
    window.handleCoinSubIframeLoad = handleCoinSubIframeLoad;
    window.ensurePlaceOrderButtonVisibility = ensurePlaceOrderButtonVisibility;
    
    // Also check when WooCommerce updates checkout (AJAX)
    $(document.body).on('updated_checkout', function() {
        console.log('🔄 CoinSub: WooCommerce checkout updated via AJAX');
        ensurePlaceOrderButtonVisibility();
    });
    
    // Also watch for when payment methods are loaded/updated
    $(document.body).on('payment_method_selected', function() {
        console.log('🔄 CoinSub: Payment method selected event fired');
        ensurePlaceOrderButtonVisibility();
    });
});
</script>