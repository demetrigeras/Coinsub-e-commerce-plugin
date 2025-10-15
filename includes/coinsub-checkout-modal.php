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

#coinsub-checkout-iframe {
    width: 100%;
    height: 100%;
    border: none;
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
                        
                        // Monitor iframe URL changes for regular modal
                        var regularIframe = document.getElementById('coinsub-checkout-iframe');
                        if (regularIframe) {
                            regularIframe.addEventListener('load', function() {
                                try {
                                    var currentUrl = regularIframe.contentWindow.location.href;
                                    console.log('Regular iframe URL:', currentUrl);
                                    
                                    if (currentUrl.includes('order-received')) {
                                        console.log('✅ Payment completed in regular modal - handling redirect');
                                        handlePaymentCompletion();
                                    }
                                } catch (e) {
                                    // Cross-origin restrictions - use message listener instead
                                    console.log('Cross-origin iframe - using message listener');
                                }
                            });
                            
                                    // Add aggressive URL monitoring for regular modal
                                    var regularUrlMonitor = setInterval(function() {
                                        try {
                                            var currentUrl = regularIframe.contentWindow.location.href;
                                            console.log('Regular iframe URL check:', currentUrl);
                                            
                                            if (currentUrl.includes('order-received')) {
                                                console.log('✅ Payment completed in regular modal (timer) - redirecting main page');
                                                clearInterval(regularUrlMonitor);
                                                
                                                // Close modal and redirect main page
                                                closeModal();
                                                
                                                setTimeout(function() {
                                                    console.log('🔄 Redirecting main page to iframe URL:', currentUrl);
                                                    window.top.location.href = currentUrl;
                                                }, 2500);
                                            }
                                        } catch (e) {
                                            // Cross-origin - ignore
                                        }
                                    }, 500); // Check every half second for faster detection
                                    
                                    // Clear timer after 5 minutes
                                    setTimeout(function() {
                                        clearInterval(regularUrlMonitor);
                                    }, 300000);
                        }
                        
                        // Quick check if original modal works, if not create emergency modal immediately
                        setTimeout(function() {
                            var modalElement = $('#coinsub-checkout-modal')[0];
                            var isVisible = modalElement && modalElement.offsetWidth > 0 && modalElement.offsetHeight > 0;
                            
                            if (!isVisible) {
                                console.log('Original modal not visible, creating emergency modal');
                                
                                // Remove original modal
                                $('#coinsub-checkout-modal').remove();
                                
                                // Create emergency modal with iframe load handler
                                var emergencyModal = $('<div id="emergency-modal" style="position: fixed !important; top: 0 !important; left: 0 !important; width: 100vw !important; height: 100vh !important; background: rgba(0, 0, 0, 0.5) !important; z-index: 999999 !important; display: flex !important; justify-content: center !important; align-items: center !important;"><div style="background: white !important; width: 420px !important; height: 620px !important; border-radius: 16px !important; position: relative !important; overflow: hidden !important; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2) !important;"><iframe id="emergency-iframe" src="' + checkoutUrl + '" style="width: 100% !important; height: 100% !important; border: none !important;" allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *"></iframe><button id="emergency-close" style="position: absolute !important; top: 10px !important; right: 15px !important; background: none !important; border: none !important; font-size: 24px !important; cursor: pointer !important; color: #666 !important; z-index: 1000000 !important;">×</button></div></div>');
                                
                                $('body').append(emergencyModal);
                                console.log('Emergency modal created');
                                
                                // Monitor iframe URL changes for emergency modal
                                var emergencyIframe = document.getElementById('emergency-iframe');
                                if (emergencyIframe) {
                                    emergencyIframe.addEventListener('load', function() {
                                        try {
                                            var currentUrl = emergencyIframe.contentWindow.location.href;
                                            console.log('Emergency iframe URL:', currentUrl);
                                            
                                            if (currentUrl.includes('order-received')) {
                                                console.log('✅ Payment completed in emergency modal - handling redirect');
                                                handlePaymentCompletion();
                                            }
                                        } catch (e) {
                                            // Cross-origin restrictions - use message listener instead
                                            console.log('Cross-origin iframe - using message listener');
                                        }
                                    });
                                    
                                    // Add aggressive URL monitoring for emergency modal
                                    var emergencyUrlMonitor = setInterval(function() {
                                        try {
                                            var currentUrl = emergencyIframe.contentWindow.location.href;
                                            console.log('Emergency iframe URL check:', currentUrl);
                                            
                                            if (currentUrl.includes('order-received')) {
                                                console.log('✅ Payment completed in emergency modal (timer) - redirecting main page');
                                                clearInterval(emergencyUrlMonitor);
                                                
                                                // Close modal and redirect main page
                                                closeModal();
                                                
                                                setTimeout(function() {
                                                    console.log('🔄 Redirecting main page to iframe URL:', currentUrl);
                                                    window.top.location.href = currentUrl;
                                                }, 2500);
                                            }
                                        } catch (e) {
                                            // Cross-origin - ignore
                                        }
                                    }, 500); // Check every half second for faster detection
                                    
                                    // Clear timer after 5 minutes
                                    setTimeout(function() {
                                        clearInterval(emergencyUrlMonitor);
                                    }, 300000);
                                }
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
    
    // Handle payment completion - unified function for both modals
    function handlePaymentCompletion() {
        console.log('🎉 Handling payment completion...');
        
        // Close modal immediately
        closeModal();
        
            // Webhook will handle the rest
        
        // Webhook will handle cart clearing and order updates
        console.log('✅ Payment completion detected - webhook will handle cart clearing and order updates');
        
        // Start checking for webhook completion
        checkForWebhookCompletion();
    }
    
    // Function to check for webhook completion and redirect to order-received page
    function checkForWebhookCompletion() {
        var checkCount = 0;
        var maxChecks = 30; // Check for 30 seconds (30 * 1000ms = 30s)
        
        var checkInterval = setInterval(function() {
            checkCount++;
            console.log('🔍 Checking for webhook completion... (' + checkCount + '/' + maxChecks + ')');
            
            $.ajax({
                url: wc_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'coinsub_check_webhook_status',
                    security: '<?php echo wp_create_nonce('coinsub_check_webhook'); ?>'
                },
                success: function(response) {
                        if (response.success && response.data && response.data.redirect_url) {
                            console.log('✅ Webhook completed - redirecting to order-received page');
                            clearInterval(checkInterval);
                            setTimeout(function() {
                                window.location.href = response.data.redirect_url;
                            }, 1500);
                        }
                },
                error: function() {
                    console.log('⚠️ Failed to check webhook status');
                }
            });
            
                if (checkCount >= maxChecks) {
                    console.log('⏰ Webhook check timeout - redirecting to order-received as fallback');
                    clearInterval(checkInterval);
                    
                    // Fallback: redirect to order-received page after 2.5 seconds
                    setTimeout(function() {
                        console.log('🔄 Fallback redirect to order-received page');
                        // Get the most recent order and redirect to its order-received page
                        var fallbackUrl = '<?php echo esc_js(wc_get_checkout_url()); ?>';
                        window.location.href = fallbackUrl;
                    }, 2500);
                }
        }, 1000); // Check every second
    }
    
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
        // Debug: Log all messages to see what's coming through
        console.log('🔍 Message received from origin:', event.origin);
        console.log('🔍 Expected origin:', '<?php echo esc_js($api_scheme . '://' . $api_host); ?>');
        console.log('🔍 Message data:', event.data);
        
        // Allow messages from CoinSub checkout domain
        var allowedOrigins = [
            '<?php echo esc_js($api_scheme . '://' . $api_host); ?>',
            'https://test-buy.coinsub.io',
            'https://buy.coinsub.io'
        ];
        
        if (!allowedOrigins.includes(event.origin)) {
            console.log('❌ Message blocked - origin not allowed:', event.origin);
            return;
        }
        
        console.log('CoinSub iframe message received:', event.data);
        console.log('Message type:', event.data.type);
        console.log('Message data:', JSON.stringify(event.data, null, 2));
        
        // Handle different types of messages from CoinSub
        var isPaymentComplete = false;
        
        // Format 1: Direct payment_complete
        if (event.data.type === 'payment_complete') {
            console.log('✅ Payment completed - direct payment_complete message');
            isPaymentComplete = true;
        }
        
        // Format 2: Redirect event (this is what CoinSub sends!)
        if (event.data.type === 'redirect' && event.data.data && event.data.data.url) {
            console.log('✅ Payment completed - redirect event detected');
            console.log('Redirect URL:', event.data.data.url);
            
            // Check if it's going to order-received page
            if (event.data.data.url.includes('order-received')) {
                console.log('✅ Redirecting to order-received page - payment complete!');
                
                // Close modal immediately
                closeModal();
                
                // Redirect the main page (not the iframe) to the URL CoinSub provided
                setTimeout(function() {
                    console.log('🔄 Redirecting main page to:', event.data.data.url);
                    window.top.location.href = event.data.data.url;
                }, 2500);
                
                return; // Exit early since we handled the redirect
            }
        }
        
        // Format 3: Check if message contains payment success indicators
        if (event.data && (
            (event.data.event && event.data.event.includes('payment')) ||
            (event.data.status && event.data.status === 'completed') ||
            (event.data.payment_status && event.data.payment_status === 'completed') ||
            (typeof event.data === 'string' && event.data.includes('payment_complete'))
        )) {
            console.log('✅ Payment completed - detected from message content');
            isPaymentComplete = true;
        }
        
        if (isPaymentComplete) {
            console.log('🎉 Payment completion detected - handling redirect');
            handlePaymentCompletion();
            
        } else if (event.data.type === 'payment_failed') {
            // Close modal and show error
            closeModal();
            alert('Payment failed. Please try again.');
        }
    });
    
    // BACKUP: Listen for ALL messages without origin checking (for debugging)
    window.addEventListener('message', function(event) {
        console.log('🚨 BACKUP LISTENER - All messages:', {
            origin: event.origin,
            data: event.data,
            type: event.data?.type || 'unknown'
        });
        
        // Handle redirect events regardless of origin
        if (event.data && event.data.type === 'redirect' && event.data.data && event.data.data.url) {
            console.log('🚨 BACKUP LISTENER - Redirect detected:', event.data.data.url);
            
            if (event.data.data.url.includes('order-received')) {
                console.log('🚨 BACKUP LISTENER - Order received URL detected - closing modal and redirecting');
                closeModal();
                setTimeout(function() {
                    console.log('🚨 BACKUP LISTENER - Redirecting main page to:', event.data.data.url);
                    window.top.location.href = event.data.data.url;
                }, 2500);
            }
        }
    });
    
    // PUSHER EVENT LISTENER: Listen for redirect events from CoinSub Pusher
    // The redirect event is sent via Pusher, not window.postMessage
    if (typeof window.Pusher !== 'undefined' || typeof window.pusher !== 'undefined') {
        console.log('🔌 Pusher detected - setting up redirect listener');
        
        // Try to access Pusher instance from global scope
        var pusher = window.Pusher || window.pusher;
        if (pusher) {
            // Subscribe to a channel that might contain redirect events
            // We'll try to catch the redirect event that's being logged
            console.log('🔌 Pusher instance found, setting up redirect detection');
        }
    }
    
    // MONITOR CONSOLE: Listen for redirect events in console logs
    // Since we can see the redirect event in console, let's try to catch it
    var originalConsoleLog = console.log;
    console.log = function(...args) {
        // Call original console.log
        originalConsoleLog.apply(console, args);
        
        // Check if this is a redirect event
        var message = args.join(' ');
        
        // Look for Pusher redirect events - check the exact format from your logs
        if (message.includes('Event recd') && message.includes('redirect') && message.includes('order-received')) {
            console.log('🎯 PUSHER REDIRECT DETECTED in console!');
            console.log('🎯 Full message:', message);
            
            // Try to extract URL from the console message - handle the exact format
            var urlMatch = message.match(/https:\/\/[^\s'"]+order-received[^\s'"]+/);
            if (urlMatch && urlMatch[0]) {
                console.log('🎯 PUSHER REDIRECT - Extracted URL:', urlMatch[0]);
                closeModal();
                setTimeout(function() {
                    console.log('🎯 PUSHER REDIRECT - Redirecting to:', urlMatch[0]);
                    window.top.location.href = urlMatch[0];
                }, 2500);
            } else {
                console.log('🎯 PUSHER REDIRECT - Could not extract URL from:', message);
            }
        }
        
        // Also check for the exact format: ["Event recd",{"event":"redirect","data":{"url":"..."}}]
        if (message.includes('["Event recd"') && message.includes('"event":"redirect"') && message.includes('order-received')) {
            console.log('🎯 PUSHER REDIRECT DETECTED (exact format) in console!');
            
            // Try to extract URL from the exact Pusher format
            var urlMatch = message.match(/https:\/\/[^\s'"]+order-received[^\s'"]+/);
            if (urlMatch && urlMatch[0]) {
                console.log('🎯 PUSHER REDIRECT (exact) - Extracted URL:', urlMatch[0]);
                closeModal();
                setTimeout(function() {
                    console.log('🎯 PUSHER REDIRECT (exact) - Redirecting to:', urlMatch[0]);
                    window.top.location.href = urlMatch[0];
                }, 2500);
            }
        }
        
        // Also check for general redirect events
        if (message.includes('redirect') && message.includes('order-received')) {
            console.log('🎯 CONSOLE MONITOR - Redirect event detected in console!');
            
            // Try to extract URL from the console message
            var urlMatch = message.match(/https:\/\/[^\s'"]+order-received[^\s'"]+/);
            if (urlMatch && urlMatch[0]) {
                console.log('🎯 CONSOLE MONITOR - Extracted URL:', urlMatch[0]);
                closeModal();
                setTimeout(function() {
                    console.log('🎯 CONSOLE MONITOR - Redirecting to:', urlMatch[0]);
                    window.top.location.href = urlMatch[0];
                }, 2500);
            }
        }
    };
    
    // SIMPLE BACKUP REDIRECT: After 30 seconds, redirect to order-received page
    // This is a backup in case webhook processing takes time
    setTimeout(function() {
        console.log('🔄 BACKUP REDIRECT - 30 seconds elapsed, checking for completed order...');
        
        // Check if we have a recent order and redirect to its order-received page
        $.ajax({
            url: wc_checkout_params.ajax_url,
            type: 'POST',
            data: {
                action: 'coinsub_get_latest_order_url',
                security: '<?php echo wp_create_nonce('coinsub_get_order_url'); ?>'
            },
            success: function(response) {
                if (response.success && response.data && response.data.order_url) {
                    console.log('🔄 BACKUP REDIRECT - Found order, redirecting to:', response.data.order_url);
                    closeModal();
                    window.top.location.href = response.data.order_url;
                } else {
                    console.log('🔄 BACKUP REDIRECT - No order found, staying on checkout page');
                }
            },
            error: function() {
                console.log('🔄 BACKUP REDIRECT - Failed to check for order');
            }
        });
    }, 30000); // 30 seconds
});
</script>