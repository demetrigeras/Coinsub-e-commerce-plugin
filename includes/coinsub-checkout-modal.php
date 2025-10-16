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
    // Override the place order button for CoinSub
    $('body').on('click', '#place_order', function(e) {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod === 'coinsub') {
            e.preventDefault();
            e.stopPropagation();
            
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
                    billing_country: $('select[name="billing_country"]').val()
                },
                success: function(response) {
                    console.log('AJAX Success Response:', response);
                    
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
                        var iframeContainer = $('<div id="coinsub-checkout-container" style="margin: 20px 0; background: white; border-radius: 16px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); overflow: hidden;"><iframe id="coinsub-checkout-iframe" src="' + checkoutUrl + '" style="width: 100%; height: 800px; border: none;" allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *"></iframe></div>');
                        
                        // Insert above the payment button
                        $('.woocommerce-checkout .form-row.place-order').before(iframeContainer);
                        
                        // Show the iframe container
                        $('#coinsub-checkout-container').show();
                        
                        // Hide the payment button since iframe is now visible
                        $('.woocommerce-checkout .form-row.place-order').hide();
                        
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
                        $('#place_order').prop('disabled', false).text('Place order');
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
                    $('#place_order').prop('disabled', false).text('Place order');
                }
            });
            
            return false;
        }
    });
    
    // Add a way to show payment button again (for debugging or if needed)
    function showPaymentButton() {
        $('.woocommerce-checkout .form-row.place-order').show();
        $('#coinsub-checkout-container').remove();
    }
    
    // Make function available globally for debugging
    window.showPaymentButton = showPaymentButton;
    
    console.log('✅ CoinSub checkout integration loaded');
});
</script>