<?php
/**
 * CoinSub Checkout Integration
 * 
 * This file contains the HTML, CSS, and JavaScript for the CoinSub checkout iframe
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- CoinSub Checkout Styles -->
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

/* ONLY hide button when CoinSub iframe is visible - don't interfere with other payment methods */
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

<!-- CoinSub Checkout JavaScript -->
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
    
    // ONLY handle CoinSub button visibility - completely ignore other payment methods
    function ensurePlaceOrderButtonVisibility() {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        // Only handle CoinSub - completely ignore other payment methods
        if (paymentMethod !== 'coinsub') {
            // For non-CoinSub methods, just hide CoinSub iframe
            // DO NOT touch the button at all - let WooCommerce and their plugin handle it completely
            $('#coinsub-checkout-container').hide();
            $('body').removeClass('coinsub-iframe-visible');
            
            // Note: Logo removal is handled in the payment method change handler
            // We don't touch the button here to avoid interfering with WooCommerce's button text updates
            
            return; // Completely step back - don't interfere with other payment methods
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
            // Don't touch button text - let WooCommerce handle it (will be "Place order")
            console.log('✅ CoinSub: Showing Place Order button (CoinSub selected, no iframe)');
        }
    }
    
    // Watch for payment method changes - ONLY handle CoinSub
    $('body').on('change', 'input[name="payment_method"]', function() {
        var newMethod = $(this).val();
        console.log('🔄 CoinSub: Payment method changed to: ' + newMethod);
        
        // Only handle CoinSub iframe visibility, don't touch button text
        if (newMethod === 'coinsub') {
            // Just handle visibility for CoinSub
            ensurePlaceOrderButtonVisibility();
        } else {
            // For other payment methods, remove CoinSub logo and hide iframe
            removeCoinSubLogo();
            $('#coinsub-checkout-container').hide();
            $('body').removeClass('coinsub-iframe-visible');
            // Let WooCommerce handle button text completely (will be "Place order")
        }
        
        // Set up watcher
        setupButtonTextWatcher();
    });
    
    // SIMPLIFIED: No button text watcher needed - let WooCommerce handle button text completely
    // We only need to remove CoinSub logo when switching away
    function setupButtonTextWatcher() {
        // Removed - no longer needed since we're using "Place order" for everything
        // This function kept for compatibility but does nothing
    }
    
    // SIMPLIFIED: Just handle CoinSub iframe visibility, don't touch button text
    function initializeButtonText() {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod === 'coinsub') {
            ensurePlaceOrderButtonVisibility();
        } else {
            // Remove CoinSub logo if present, hide iframe
            removeCoinSubLogo();
            $('#coinsub-checkout-container').hide();
            $('body').removeClass('coinsub-iframe-visible');
            // Let WooCommerce handle button text (will be "Place order")
        }
    }
    
    // Run initialization with delays to catch delayed rendering
    setTimeout(initializeButtonText, 100);
    setTimeout(initializeButtonText, 300);
    setTimeout(initializeButtonText, 600);
    
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
                    
                    if (checkoutUrl) {
                        console.log('Opening CoinSub checkout iframe:', checkoutUrl);
                        
                        // Remove any existing CoinSub iframe to prevent duplicates
                        $('#coinsub-checkout-iframe').remove();
                        $('#coinsub-checkout-container').remove();
                        
                        // Create iframe container above the payment button
                        var iframeContainer = $('<div id="coinsub-checkout-container" style="margin: 20px 0; background: white; border-radius: 16px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); overflow: hidden;"><iframe id="coinsub-checkout-iframe" src="' + checkoutUrl + '" style="width: 100%; height: 800px; border: none;" allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *" onload="handleIframeLoad()"></iframe></div>');
                        
                        // Insert above the payment button
                        $('.woocommerce-checkout .form-row.place-order').before(iframeContainer);
                        
                        // Show the iframe container
                        $('#coinsub-checkout-container').show();
                        $('body').addClass('coinsub-iframe-visible');
                        
                        // Hide the payment button ONLY if CoinSub is still selected
                        var currentPaymentMethod = $('input[name="payment_method"]:checked').val();
                        if (currentPaymentMethod === 'coinsub') {
                            $('.woocommerce-checkout .form-row.place-order').hide();
                            $('#place_order').hide();
                        }
                        
                        // Set up iframe redirect detection
                        setupIframeRedirectDetection();
                        
                        console.log('✅ CoinSub checkout iframe embedded above payment button');
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
    function setupIframeRedirectDetection() {
        console.log('🔄 Setting up iframe redirect detection...');
        
        // Listen for postMessage events from the iframe
        window.addEventListener('message', function(event) {
            console.log('📨 Received message from iframe:', event.data);
            
            // Check if this is a redirect message
            if (event.data && typeof event.data === 'object') {
                if (event.data.type === 'redirect' && event.data.url) {
                    console.log('🔄 Redirecting parent window to:', event.data.url);
                    window.location.href = event.data.url;
                }
            }
            
            // Also check for URL changes in the iframe
            if (event.data && typeof event.data === 'string' && event.data.includes('order-received')) {
                console.log('🔄 Found order-received URL in message:', event.data);
                window.location.href = event.data;
            }
        });
        
        // Check iframe URL and content periodically for redirects
        var checkInterval = setInterval(function() {
            try {
                var iframe = document.getElementById('coinsub-checkout-iframe');
                if (iframe && iframe.contentWindow) {
                    var iframeUrl = iframe.contentWindow.location.href;
                    
                    // Check if iframe has redirected to order-received page
                    if (iframeUrl.includes('order-received')) {
                        console.log('🔄 Iframe redirected to order-received, redirecting parent window');
                        clearInterval(checkInterval);
                        window.location.href = iframeUrl;
                        return;
                    }
                }
                
                // Also check iframe content for payment completion indicators
                if (iframe && iframe.contentDocument) {
                    var iframeDoc = iframe.contentDocument;
                    var iframeBody = iframeDoc.body;
                    
                    if (iframeBody) {
                        var bodyText = iframeBody.innerText || iframeBody.textContent || '';
                        var bodyHTML = iframeBody.innerHTML || '';
                        
                        // Check for various payment completion indicators
                        var completionIndicators = [
                            'Payment Complete', 'Payment Completed', 'Purchase Complete', 'Purchase Completed',
                            'Transaction Complete', 'Transaction Completed', 'Order Complete', 'Order Completed',
                            'Success', 'Thank you', 'Payment successful', 'Payment Successful'
                        ];
                        
                        for (var i = 0; i < completionIndicators.length; i++) {
                            var indicator = completionIndicators[i];
                            if (bodyText.includes(indicator) || bodyHTML.includes(indicator)) {
                                console.log('🎉 Payment completion detected in iframe content: ' + indicator);
                                console.log('🔄 Redirecting parent window to order received page');
                                clearInterval(checkInterval);
                                
                                // Redirect to order received page
                                var orderReceivedUrl = window.location.origin + '/checkout/order-received/';
                                window.location.href = orderReceivedUrl;
                                return;
                            }
                        }
                        
                        // Also check for specific HTML elements that might indicate completion
                        var completionElements = iframeDoc.querySelectorAll('[data-purchase-text-value*="Complete"], [data-purchase-text-value*="Success"], .success, .completed, .payment-complete');
                        if (completionElements.length > 0) {
                            console.log('🎉 Payment completion detected via HTML elements');
                            console.log('🔄 Redirecting parent window to order received page');
                            clearInterval(checkInterval);
                            
                            // Redirect to order received page
                            var orderReceivedUrl = window.location.origin + '/checkout/order-received/';
                            window.location.href = orderReceivedUrl;
                            return;
                        }
                    }
                }
            } catch(e) {
                // Cross-origin restrictions - this is expected
                console.log('🔄 Iframe may have redirected to different domain or cross-origin restrictions');
            }
        }, 1000);
        
        // Stop checking after 5 minutes
        setTimeout(function() {
            clearInterval(checkInterval);
        }, 300000);
    }
    
    // Handle iframe load
    function handleIframeLoad() {
        console.log('🔄 CoinSub iframe loaded');
        setupIframeRedirectDetection();
    }
    
    // Check for checkout URL on page load
    checkForCoinSubCheckout();
    
    // Ensure button visibility after a short delay (only if CoinSub is selected)
    setTimeout(function() {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        if (paymentMethod === 'coinsub') {
            ensurePlaceOrderButtonVisibility();
        }
    }, 500);
    
    // Also check when WooCommerce updates checkout (AJAX)
    // Only update if CoinSub is selected
    $(document.body).on('updated_checkout', function() {
        console.log('🔄 CoinSub: WooCommerce checkout updated via AJAX');
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod === 'coinsub') {
            initializeButtonText();
        } else {
            // Remove CoinSub logo and hide iframe
            removeCoinSubLogo();
            $('#coinsub-checkout-container').hide();
            $('body').removeClass('coinsub-iframe-visible');
            // Let WooCommerce handle button text (will be "Place order")
        }
    });
    
    // Also watch for when payment methods are loaded/updated
    $(document.body).on('payment_method_selected', function() {
        console.log('🔄 CoinSub: Payment method selected event fired');
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod === 'coinsub') {
            initializeButtonText();
        } else {
            // Remove CoinSub logo and hide iframe
            removeCoinSubLogo();
            $('#coinsub-checkout-container').hide();
            $('body').removeClass('coinsub-iframe-visible');
            // Let WooCommerce handle button text (will be "Place order")
        }
    });
    
    // Make functions available globally for debugging
    window.showPaymentButton = function() {
        $('.woocommerce-checkout .form-row.place-order').show();
        $('#place_order').show();
        $('#coinsub-checkout-container').hide();
        $('body').removeClass('coinsub-iframe-visible');
        ensurePlaceOrderButtonVisibility();
    };
    window.handleIframeLoad = handleIframeLoad;
    window.ensurePlaceOrderButtonVisibility = ensurePlaceOrderButtonVisibility;
});
</script>