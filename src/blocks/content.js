/**
 * Stablecoin Pay — block-checkout payment-method content component.
 *
 * Rendered inside the customer-facing block checkout when the customer
 * selects "Pay with Crypto". Responsible for:
 *
 *   1. Showing a short description / branding.
 *   2. Registering an `onPaymentSetup` callback with the block checkout
 *      runtime. Block checkout calls this callback when the customer
 *      clicks Place Order. The callback returns a Promise that resolves
 *      with `{ type: 'success', meta: { paymentMethodData: {…} } }` to
 *      let the order go through, or `{ type: 'error', message: '…' }` to
 *      block it with a visible error.
 *   3. Hitting `admin-ajax.php?action=coinsub_process_payment` to get a
 *      hosted-checkout URL, opening that in an iframe inside the payment
 *      area, and only resolving the Promise once the customer completes
 *      payment (signaled via redirect-detection or a postMessage from the
 *      hosted checkout, same as the classic flow).
 *
 * Most of step 3 is intentionally left as TODOs because it mirrors the
 * existing classic-checkout flow in `includes/sp-checkout-modal.php` and
 * you'll want to port that iframe lifecycle / redirect-detection here
 * once the rest is wired up. The Promise pattern below is the correct
 * skeleton — fill in the iframe mount + completion detection inside the
 * marked sections.
 */

import { useEffect, useRef, useState } from '@wordpress/element';

const Content = ( { settings, ...props } ) => {
	const { eventRegistration, emitResponse, billing, shippingData } = props;
	const { onPaymentSetup } = eventRegistration || {};

	const [ checkoutUrl, setCheckoutUrl ] = useState( null );
	const [ error, setError ] = useState( null );
	const iframeRef = useRef( null );

	// Register the payment-setup callback ONCE per mount. The callback closes
	// over the latest billing/shipping props via the ref pattern below.
	const billingRef = useRef( billing );
	const shippingRef = useRef( shippingData );
	useEffect( () => {
		billingRef.current = billing;
		shippingRef.current = shippingData;
	}, [ billing, shippingData ] );

	useEffect( () => {
		if ( typeof onPaymentSetup !== 'function' ) {
			return;
		}

		const unsubscribe = onPaymentSetup( async () => {
			try {
				// ----------------------------------------------------------------
				// TODO (1/3): kick off the same `coinsub_process_payment` AJAX as
				// the classic checkout. Use the values from billingRef.current
				// and shippingRef.current to populate billing_* / shipping_*
				// payload fields. See includes/sp-checkout-modal.php for the
				// canonical list of fields.
				// ----------------------------------------------------------------
				const response = await fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams( {
						action: settings.processAction,
						security: settings.nonce,
						payment_method: 'coinsub',
						// TODO: fill in billing_* / shipping_* fields here from
						// billingRef.current.billingAddress and
						// shippingRef.current.shippingAddress
					} ).toString(),
				} );

				const data = await response.json();
				const url =
					data?.data?.coinsub_checkout_url ||
					data?.coinsub_checkout_url ||
					null;

				if ( ! data?.success || ! url ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message:
							data?.data?.message ||
							'Could not start the crypto payment session. Please try again.',
					};
				}

				// ----------------------------------------------------------------
				// TODO (2/3): mount the hosted-checkout iframe inside this
				// component (or as a portal/overlay), then await its completion.
				// The cleanest pattern is:
				//   1. setCheckoutUrl(url)  // triggers iframe render below
				//   2. await a promise that resolves when the iframe redirects
				//      to /checkout/order-received/ — mirror the
				//      setupCoinSubIframeRedirectDetection() logic from
				//      includes/sp-checkout-modal.php.
				//   3. resolve here once payment is confirmed.
				// ----------------------------------------------------------------
				setCheckoutUrl( url );
				await new Promise( ( resolve ) => {
					// TEMP: bail immediately. Replace this with real iframe
					// completion detection (postMessage from buy.* host, or
					// poll the order status via REST, or watch iframe.src for
					// the order-received URL).
					resolve();
				} );

				// ----------------------------------------------------------------
				// TODO (3/3): on real success, resolve with paymentMethodData
				// that the server-side gateway can read. The webhook is what
				// actually confirms payment, but Woo still needs us to return
				// a non-error here so the order is created.
				// ----------------------------------------------------------------
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							coinsub_checkout_url: url,
						},
					},
				};
			} catch ( err ) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message:
						err?.message ||
						'Unexpected error starting crypto payment.',
				};
			}
		} );

		return () => {
			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
			}
		};
	}, [ onPaymentSetup, emitResponse, settings ] );

	return (
		<div className="coinsub-block-payment">
			<p>{ settings?.description || 'Pay securely with cryptocurrency.' }</p>

			{ error && (
				<div
					className="coinsub-block-error"
					style={ { color: '#b81c23', marginTop: '8px' } }
				>
					{ error }
				</div>
			) }

			{ checkoutUrl && (
				<div
					className="coinsub-block-iframe-container"
					style={ {
						marginTop: '16px',
						background: '#fff',
						borderRadius: '12px',
						boxShadow: '0 4px 16px rgba(0,0,0,0.08)',
						overflow: 'hidden',
					} }
				>
					<iframe
						ref={ iframeRef }
						title="Crypto checkout"
						src={ checkoutUrl }
						style={ {
							width: '100%',
							height: '550px',
							border: 0,
						} }
						allow="clipboard-read *; clipboard-write *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *"
					/>
				</div>
			) }
		</div>
	);
};

export default Content;
