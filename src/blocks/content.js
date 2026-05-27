/**
 * Stablecoin Pay — block-checkout payment-method content component.
 *
 * Mirrors the classic-checkout iframe flow from
 * `includes/sp-checkout-modal.php` but for the React-based block checkout.
 *
 * Flow when the customer selects "Pay with Crypto" and clicks Place Order:
 *
 *   1. Block checkout fires our `onPaymentSetup` callback.
 *   2. We POST billing/shipping to `admin-ajax.php?action=coinsub_process_payment`,
 *      which creates a WooCommerce order and a Coinsub purchase session
 *      server-side and returns a one-time hosted-checkout URL.
 *   3. We mount that URL in an inline iframe inside this component (same
 *      "above the Place Order button" placement as the classic flow).
 *   4. We deliberately return a Promise that NEVER resolves. Block
 *      checkout's spinner stays on while the customer pays inside the
 *      iframe — that's the correct in-flight state and matches the
 *      classic flow where the Place Order button is hidden.
 *   5. A postMessage listener (and a polling fallback) watches for the
 *      iframe to signal that payment is complete. When that happens we
 *      navigate the TOP-LEVEL browser to `/checkout/order-received/`,
 *      which closes out this React tree entirely. No top-level redirect
 *      to a separate payment page — the customer never leaves /checkout/
 *      until they're done.
 *
 * The classic and block flows share the same server-side handlers
 * (`coinsub_ajax_process_payment` + `WC_Gateway_CoinSub::process_payment`),
 * so the order lifecycle, webhook handling, and refund path are
 * identical.
 */

import {
	createPortal,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';

const IFRAME_DOM_ID = 'coinsub-blocks-iframe';
const REDIRECT_CHECK_INTERVAL_MS = 1000;
const REDIRECT_CHECK_MAX_MS = 5 * 60 * 1000; // give up after 5 minutes

const Content = ( { settings, ...props } ) => {
	const { eventRegistration, emitResponse, billing, shippingData } = props;
	const { onPaymentSetup } = eventRegistration || {};

	const [ checkoutUrl, setCheckoutUrl ] = useState( null );

	// Holds the resolver of the in-flight onPaymentSetup Promise so the
	// "X / ESC / backdrop-click" close handler can cancel the payment by
	// resolving with an ERROR. That unlocks the Place Order button and
	// lets the customer try again (creating a fresh order each time).
	const pendingResolveRef = useRef( null );

	// Keep latest billing/shipping in refs so the onPaymentSetup callback
	// (registered once) can read fresh values when the customer eventually
	// clicks Place Order.
	const billingRef = useRef( billing );
	const shippingRef = useRef( shippingData );
	useEffect( () => {
		billingRef.current = billing;
		shippingRef.current = shippingData;
	}, [ billing, shippingData ] );

	// Top-level navigation to the order-received page. Same effect as
	// `window.location.href = ...` in the classic flow.
	const navigateTopLevel = useCallback( ( url ) => {
		if ( typeof url === 'string' && url ) {
			window.location.href = url;
		}
	}, [] );

	// Close the modal AND cancel the in-flight onPaymentSetup Promise so
	// block checkout unlocks the Place Order button. We deliberately omit
	// a user-visible message: if the customer actually completed payment
	// and closed the modal before the redirect signal arrived, a
	// "canceled" error would be misleading — the webhook will still mark
	// the order as paid. The unlocked button is enough feedback that the
	// modal closed; the customer can click Place Order again (which
	// starts a fresh session) if they truly did cancel.
	//
	// The on-hold order created server-side is intentionally left in WC
	// admin — merchants can clean up abandoned on-hold orders manually
	// or via a future automated cleanup task.
	const handleClose = useCallback( () => {
		setCheckoutUrl( null );
		if ( typeof pendingResolveRef.current === 'function' ) {
			pendingResolveRef.current( {
				type: emitResponse.responseTypes.ERROR,
				message: '',
			} );
			pendingResolveRef.current = null;
		}
	}, [ emitResponse ] );

	// ESC key closes the modal while it's open.
	useEffect( () => {
		if ( ! checkoutUrl ) {
			return undefined;
		}
		const onKey = ( event ) => {
			if ( event.key === 'Escape' ) {
				handleClose();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ checkoutUrl, handleClose ] );

	// Wire up redirect-detection whenever the iframe is mounted.
	useEffect( () => {
		if ( ! checkoutUrl ) {
			return undefined;
		}

		// 1) postMessage from the hosted checkout (cross-origin friendly).
		const onMessage = ( event ) => {
			const data = event?.data;
			if (
				data &&
				typeof data === 'object' &&
				data.type === 'redirect' &&
				data.url
			) {
				navigateTopLevel( data.url );
				return;
			}
			if (
				typeof data === 'string' &&
				data.includes( 'order-received' )
			) {
				navigateTopLevel( data );
			}
		};
		window.addEventListener( 'message', onMessage );

		// 2) Polling fallback that watches the iframe's same-origin URL.
		//    Cross-origin reads throw, which we silently swallow — that's
		//    expected while the customer is still on the Coinsub host.
		//    The check succeeds once the hosted checkout itself navigates
		//    back to the merchant's /checkout/order-received/ page.
		const interval = setInterval( () => {
			try {
				const iframe = document.getElementById( IFRAME_DOM_ID );
				if ( iframe && iframe.contentWindow ) {
					const url = iframe.contentWindow.location.href;
					if ( url && url.includes( 'order-received' ) ) {
						clearInterval( interval );
						navigateTopLevel( url );
					}
				}
			} catch ( _ ) {
				/* cross-origin, expected — wait for postMessage */
			}
		}, REDIRECT_CHECK_INTERVAL_MS );

		const stopper = setTimeout(
			() => clearInterval( interval ),
			REDIRECT_CHECK_MAX_MS
		);

		return () => {
			window.removeEventListener( 'message', onMessage );
			clearInterval( interval );
			clearTimeout( stopper );
		};
	}, [ checkoutUrl, navigateTopLevel ] );

	// Register the onPaymentSetup callback once per mount.
	useEffect( () => {
		if ( typeof onPaymentSetup !== 'function' ) {
			return undefined;
		}

		const unsubscribe = onPaymentSetup( async () => {
			try {
				// Build the same snake_case payload the classic flow uses
				// (see includes/sp-checkout-modal.php around L340).
				const billingAddr =
					billingRef.current?.billingAddress || {};
				const shippingAddr =
					shippingRef.current?.shippingAddress || {};

				const addressFields = [
					'first_name',
					'last_name',
					'company',
					'address_1',
					'address_2',
					'city',
					'state',
					'postcode',
					'country',
				];

				const payload = {
					action: settings.processAction,
					security: settings.nonce,
					payment_method: 'coinsub',
					billing_email:
						billingAddr.email ||
						billingRef.current?.email ||
						'',
					billing_phone:
						billingAddr.phone ||
						billingRef.current?.phone ||
						'',
				};

				addressFields.forEach( ( key ) => {
					payload[ `billing_${ key }` ] =
						billingAddr[ key ] || '';
					// If shipping is empty (e.g. ship-to-same-address on),
					// mirror the billing value so server-side validation
					// passes regardless of which set of fields was visible.
					payload[ `shipping_${ key }` ] =
						shippingAddr[ key ] || billingAddr[ key ] || '';
				} );

				const response = await fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type':
							'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams( payload ).toString(),
				} );

				const data = await response.json();
				const url =
					data?.data?.coinsub_checkout_url ||
					data?.coinsub_checkout_url ||
					null;

				if ( ! data?.success || ! url ) {
					const serverMsg =
						typeof data?.data === 'string'
							? data.data
							: data?.data?.message;
					return {
						type: emitResponse.responseTypes.ERROR,
						message:
							serverMsg ||
							'Could not start the crypto payment session. Please try again.',
					};
				}

				// Mount the iframe — this triggers the JSX below to render
				// the centered modal.
				setCheckoutUrl( url );

				// Wait for one of two outcomes:
				//   (a) Payment completes: the redirect-detection effect
				//       navigates the top-level browser to
				//       /checkout/order-received/ and this component is
				//       unmounted — the Promise never resolves and that's
				//       fine.
				//   (b) Customer dismisses the modal: `handleClose` below
				//       calls the resolver with an ERROR, which unlocks
				//       the Place Order button and surfaces a friendly
				//       cancel message in the block checkout UI.
				const cancellationResult = await new Promise(
					( resolve ) => {
						pendingResolveRef.current = resolve;
					}
				);
				pendingResolveRef.current = null;
				return cancellationResult;
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

	// --- Render -------------------------------------------------------

	// While the iframe is open we render it via a portal to <body> as a
	// centered modal so it escapes block-checkout's narrow payment-method
	// slot. The iframe gets its intended ~600px width on any layout, and
	// we don't touch any styling outside our own modal.
	const iframePanel =
		checkoutUrl && typeof document !== 'undefined'
			? createPortal(
					<div
						className="coinsub-blocks-iframe-portal"
						role="dialog"
						aria-modal="true"
						aria-label="Crypto checkout"
						onClick={ ( event ) => {
							// Only close on backdrop click (not on clicks
							// that bubble up from inside the iframe container).
							if ( event.target === event.currentTarget ) {
								handleClose();
							}
						} }
						style={ {
							position: 'fixed',
							inset: 0,
							zIndex: 999999,
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							padding: '16px',
							background: 'rgba(15, 23, 42, 0.55)',
							boxSizing: 'border-box',
						} }
					>
						<div
							className="coinsub-blocks-iframe-container"
							style={ {
								position: 'relative',
								width: 'min(520px, calc(100vw - 24px))',
								height: 'min(820px, calc(100vh - 24px))',
								background: '#fff',
								borderRadius: '16px',
								boxShadow:
									'0 20px 48px rgba(0, 0, 0, 0.28)',
								overflow: 'hidden',
								display: 'flex',
								flexDirection: 'column',
							} }
						>
							<button
								type="button"
								onClick={ handleClose }
								aria-label="Close crypto checkout"
								style={ {
									position: 'absolute',
									top: '10px',
									right: '10px',
									width: '32px',
									height: '32px',
									padding: 0,
									border: 0,
									borderRadius: '50%',
									background: 'rgba(15, 23, 42, 0.75)',
									color: '#fff',
									fontSize: '18px',
									lineHeight: '32px',
									textAlign: 'center',
									cursor: 'pointer',
									zIndex: 2,
									boxShadow:
										'0 2px 6px rgba(0, 0, 0, 0.18)',
								} }
							>
								×
							</button>
							<iframe
								id={ IFRAME_DOM_ID }
								title="Crypto checkout"
								src={ checkoutUrl }
								style={ {
									width: '100%',
									flex: 1,
									border: 0,
									display: 'block',
								} }
								allow="clipboard-read *; clipboard-write *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *"
							/>
						</div>
					</div>,
					document.body
			  )
			: null;

	return (
		<div className="coinsub-block-payment">
			{ iframePanel }
		</div>
	);
};

export default Content;
