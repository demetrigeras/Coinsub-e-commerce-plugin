<?php
/**
 * CoinSub Checkout Modal Template
 * 
 * This file contains the HTML, CSS, and JavaScript for the iframe checkout modal
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get the checkout URL from the payment gateway
$checkout_url = isset($checkout_url) ? $checkout_url : '';
$api_url = get_option('coinsub_api_url', 'https://test-buy.coinsub.io');
$api_scheme = parse_url($api_url, PHP_URL_SCHEME);
$api_host = parse_url($api_url, PHP_URL_HOST);
?>

<!-- CoinSub Checkout Modal Styles -->
<style>
#coinsub-checkout-modal {
    display: none !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(0, 0, 0, 0.5) !important;
    z-index: 99999 !important;
    justify-content: center !important;
    align-items: center !important;
}

#coinsub-checkout-modal.show {
    display: flex !important;
}

.coinsub-modal-content {
    background: #fff !important;
    border-radius: 16px !important;
    width: 420px !important;
    height: 620px !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2) !important;
    overflow: hidden !important;
    position: relative !important;
}

#coinsub-close-modal {
    position: absolute;
    top: 10px;
    right: 15px;
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
    z-index: 10001;
}

#coinsub-checkout-iframe {
    width: 100%;
    height: 100%;
    border: none;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .coinsub-modal-content {
        width: 95%;
        height: 80%;
        margin: 10px;
    }
}
</style>

<!-- CoinSub Checkout Modal HTML -->
<div id="coinsub-checkout-modal">
    <div class="coinsub-modal-content">
        <button id="coinsub-close-modal">×</button>
        <iframe id="coinsub-checkout-iframe" 
                src="" 
                allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *"
                title="CoinSub Checkout">
        </iframe>
    </div>
</div>

<!-- CoinSub Checkout Modal JavaScript -->
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
                        // Check for coinsub_checkout_url (our custom response)
                        if (response.data.coinsub_checkout_url) {
                            checkoutUrl = response.data.coinsub_checkout_url;
                        }
                        // Check for redirect (WooCommerce default response)
                        else if (response.data.result === 'success' && response.data.redirect) {
                            checkoutUrl = response.data.redirect;
                        }
                    }
                    
                    if (checkoutUrl) {
                        console.log('Opening modal with URL:', checkoutUrl);
                        
                        // Try the original modal first
                        $('#coinsub-checkout-iframe').attr('src', checkoutUrl);
                        $('#coinsub-checkout-modal').addClass('show');
                        
                        // Quick check if original modal works, if not create emergency modal immediately
                        setTimeout(function() {
                            var modalElement = $('#coinsub-checkout-modal')[0];
                            var isVisible = modalElement && modalElement.offsetWidth > 0 && modalElement.offsetHeight > 0;
                            
                            if (!isVisible) {
                                console.log('Original modal not visible, creating emergency modal');
                                
                                // Remove original modal
                                $('#coinsub-checkout-modal').remove();
                                
                                // Create emergency modal
                                var emergencyModal = $('<div id="emergency-modal" style="position: fixed !important; top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important; background: rgba(0, 0, 0, 0.5) !important; z-index: 999999 !important; display: flex !important; justify-content: center !important; align-items: center !important;"><div style="background: white !important; width: 420px !important; height: 620px !important; border-radius: 16px !important; position: relative !important; overflow: hidden !important; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2) !important;"><iframe src="' + checkoutUrl + '" style="width: 100% !important; height: 100% !important; border: none !important;" allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *"></iframe><button id="emergency-close" style="position: absolute !important; top: 10px !important; right: 15px !important; background: none !important; border: none !important; font-size: 24px !important; cursor: pointer !important; color: #666 !important; z-index: 1000000 !important;">×</button></div></div>');
                                
                                $('body').append(emergencyModal);
                                console.log('Emergency modal created');
                            } else {
                                console.log('Original modal is visible');
                            }
                        }, 50);
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
    
    // Close modal functionality - works for both regular and emergency modals
    function closeModal() {
        // Close regular modal
        $('#coinsub-checkout-modal').removeClass('show').css('display', 'none');
        $('#coinsub-checkout-iframe').attr('src', '');
        
        // Close emergency modal
        $('#emergency-modal').remove();
        
        // Re-enable place order button
        $('#place_order').prop('disabled', false).text('Place order');
    }
    
    // Close modal button
    $(document).on('click', '#coinsub-close-modal, #emergency-close', function() {
        closeModal();
    });
    
    // Close modal when clicking outside
    $(document).on('click', '#coinsub-checkout-modal, #emergency-modal', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // ESC key to close
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) {
            // Check if either modal is visible
            var regularVisible = $('#coinsub-checkout-modal').is(':visible') || $('#coinsub-checkout-modal').hasClass('show');
            var emergencyVisible = $('#emergency-modal').length > 0;
            
            if (regularVisible || emergencyVisible) {
                closeModal();
            }
        }
    });
    
    // Listen for messages from iframe (payment completion)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        var allowedOrigin = '<?php echo esc_js($api_scheme . '://' . $api_host); ?>';
        if (event.origin !== allowedOrigin) {
            return;
        }
        
        if (event.data.type === 'payment_complete') {
            // Close modal using unified function
            closeModal();
            
            // Show success message
            $('body').prepend('<div id="coinsub-payment-success" style="position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 20px; border-radius: 8px; z-index: 9999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 350px;"><strong style="font-size: 16px;">✅ Payment Successful!</strong><br><br>Your payment has been processed.<br><small>Processing your order...</small></div>');
            
            // Clear cart immediately
            $.ajax({
                url: wc_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'coinsub_clear_cart_after_payment',
                    security: '<?php echo wp_create_nonce('coinsub_clear_cart'); ?>'
                },
                success: function() {
                    console.log('✅ Cart cleared after successful payment');
                    $('#coinsub-payment-success').html('<strong style="font-size: 16px;">✅ Order Complete!</strong><br><br>Your cart has been cleared.<br><small>Redirecting to your order...</small>');
                    
                    // Redirect to orders page after 2 seconds
                    setTimeout(function() {
                        // Redirect to My Account Orders page (or checkout page if not logged in)
                        var ordersUrl = '<?php echo esc_js(get_current_user_id() ? wc_get_account_endpoint_url("orders") : wc_get_checkout_url()); ?>';
                        window.location.href = ordersUrl;
                    }, 2000);
                },
                error: function() {
                    console.log('⚠️ Failed to clear cart - webhook will handle it');
                    $('#coinsub-payment-success').html('<strong style="font-size: 16px;">✅ Payment Successful!</strong><br><br>Your payment has been processed.<br><small>Your order will be confirmed shortly.</small>');
                    setTimeout(function() {
                        $('#coinsub-payment-success').fadeOut();
                    }, 8000);
                }
            });
        } else if (event.data.type === 'payment_failed') {
            // Close modal and show error
            closeModal();
            alert('Payment failed. Please try again.');
        }
    });
});
</script>