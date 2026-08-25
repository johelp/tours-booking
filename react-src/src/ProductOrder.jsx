import { useState, useEffect, useRef } from 'react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import * as RoomsAPI from './roomsApi.js';
import { fmtMoney } from './components/shared.jsx';
import { useScrollToErrorOnMobile, useScrollToTopOnChange } from './hooks.js';

/**
 * [flow_product addon_id="X"] — venta suelta de un producto digital (ej.
 * guía PDF) sin reservar ningún tour, CONTRIBUTING.md § 16.9x. Pensado para
 * incrustarse en una página de venta propia del operador (landing, texto
 * de marketing alrededor) — este componente es solo el "comprar ahora":
 * nombre + foto (si tiene) + precio + checkout + pago, sin ningún paso de
 * fecha/personas/calendario, porque un producto digital no tiene eso.
 *
 * Reusa TAL CUAL el checkout de carrito ya existente (POST /cart/checkout,
 * POST /cart/{id}/confirm-payment) con un ítem nuevo `type:'product'` — ver
 * CartController::create_product_order(). Cero endpoints nuevos.
 *
 * Deliberadamente sin política de cancelación/términos (a diferencia de
 * BookingWidget/DiscoveryFlow/RoomSearch) — no aplica a una compra de
 * producto digital, y una página de venta necesita fricción mínima.
 */
export default function ProductOrder( { lang: initLang, addonId } ) {
	const [ lang ] = useState( initLang || 'es' );
	const t = ( es, en ) => ( lang === 'en' ? en : es );
	const currency = window.amirBooking?.currency ?? 'MXN';

	const [ step, setStep ] = useState( 'loading' ); // loading | details | payment | confirmation
	const [ product, setProduct ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ cartGroupId, setCartGroupId ] = useState( '' );
	const [ clientSecret, setClientSecret ] = useState( '' );

	const wrapRef = useRef( null );
	useScrollToErrorOnMobile( wrapRef );
	useScrollToTopOnChange( wrapRef, step );

	const stripeRef = useRef( null );
	const stripeKey = window.amirBooking?.stripePk ?? '';
	useEffect( () => {
		if ( stripeKey && ! stripeRef.current ) {
			stripeRef.current = loadStripe( stripeKey );
		}
	}, [ stripeKey ] );

	useEffect( () => {
		RoomsAPI.getGlobalAddons( lang )
			.then( addons => {
				const found = addons.find( a => a.id === addonId );
				if ( ! found || found.pricing_type !== 'digital' ) {
					setError( t( 'Este producto ya no está disponible.', 'This product is no longer available.' ) );
					setStep( 'details' );
					return;
				}
				setProduct( found );
				setStep( 'details' );
			} )
			.catch( () => {
				setError( t( 'No pudimos cargar el producto. Intenta de nuevo.', "We couldn't load the product. Please try again." ) );
				setStep( 'details' );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- addonId/lang fijos del shortcode
	}, [] );

	async function handleBuy( customer ) {
		setError( '' );
		setLoading( true );
		try {
			const resp = await RoomsAPI.cartCheckout( {
				items: [ { type: 'product', addon_id: addonId } ],
				...customer,
			} );
			setCartGroupId( resp.cart_group_id );
			if ( resp.requires_payment && resp.client_secret ) {
				setClientSecret( resp.client_secret );
				setStep( 'payment' );
			} else {
				setStep( 'confirmation' );
			}
		} catch ( err ) {
			setError( err.message || t( 'Ocurrió un error. Intenta de nuevo.', 'An error occurred. Please try again.' ) );
		} finally {
			setLoading( false );
		}
	}

	return (
		<div className="fpr-wrap" ref={wrapRef}>
			<style>{ PRODUCT_CSS }</style>

			{ error && <div className="ab-error-banner">⚠ { error }</div> }

			{ step === 'loading' && (
				<div className="fpr-card fpr-card-sk" />
			) }

			{ step === 'details' && product && (
				<ProductCheckoutForm product={ product } lang={ lang } t={ t } currency={ currency }
					loading={ loading } onBuy={ handleBuy } />
			) }

			{ step === 'payment' && clientSecret && (
				<Elements stripe={ stripeRef.current } options={ { clientSecret, locale: lang } }>
					<ProductPaymentStep t={ t } cartGroupId={ cartGroupId }
						onSuccess={ () => setStep( 'confirmation' ) } />
				</Elements>
			) }

			{ step === 'confirmation' && (
				<div className="fpr-card" style={ { textAlign: 'center' } }>
					<div style={ { fontSize: 40 } }>✅</div>
					<h2 style={ { margin: '8px 0 4px' } }>{ t( '¡Gracias por tu compra!', 'Thanks for your purchase!' ) }</h2>
					<p style={ { color: '#5a7068' } }>
						{ t( 'Te mandamos un email con el link de descarga.', "We've sent you an email with the download link." ) }
					</p>
				</div>
			) }
		</div>
	);
}

function ProductCheckoutForm( { product, lang, t, currency, loading, onBuy } ) {
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ emailLang, setEmailLang ] = useState( lang );

	function handleSubmit( e ) {
		e.preventDefault();
		onBuy( { customer_name: name, customer_email: email, lang: emailLang } );
	}

	return (
		<div className="fpr-card">
			<h2 className="fpr-title">{ product.name }</h2>
			<div className="fpr-price">{ fmtMoney( product.price_mxn, currency, lang ) }</div>

			<form onSubmit={ handleSubmit }>
				<div className="fpr-field">
					<label>{ t( 'Nombre completo', 'Full name' ) }</label>
					<input type="text" required value={ name } onChange={ e => setName( e.target.value ) } />
				</div>
				<div className="fpr-field">
					<label>Email</label>
					<input type="email" required value={ email } onChange={ e => setEmail( e.target.value ) } />
					<p className="fpr-hint">{ t( 'Te mandamos el link de descarga a este email.', "We'll send the download link to this email." ) }</p>
				</div>
				{ ( window.amirBooking?.activeLanguages ?? [ 'es', 'en' ] ).length > 1 && (
					<div className="fpr-field">
						<label>{ t( 'Idioma del email', 'Email language' ) }</label>
						<div className="ab-lang-toggle">
							{ ( window.amirBooking?.activeLanguages ?? [ 'es', 'en' ] ).map( code => (
								<button key={ code } type="button"
									className={ `ab-lang-btn${ emailLang === code ? ' active' : '' }` }
									onClick={ () => setEmailLang( code ) }
								>{ code === 'es' ? t( 'Español', 'Spanish' ) : code === 'en' ? t( 'English', 'English' ) : code.toUpperCase() }</button>
							) ) }
						</div>
					</div>
				) }
				<button type="submit" className="ab-btn ab-btn-primary fpr-buy-btn" disabled={ loading }>
					{ loading ? t( 'Procesando…', 'Processing…' ) : t( 'Comprar ahora →', 'Buy now →' ) }
				</button>
			</form>
		</div>
	);
}

/** Mismo patrón que CartPaymentStep (RoomSearch.jsx) — apunta al mismo endpoint genérico de carrito. */
function ProductPaymentStep( { t, cartGroupId, onSuccess } ) {
	const stripe = useStripe();
	const elements = useElements();
	const [ processing, setProcessing ] = useState( false );
	const [ error, setError ] = useState( '' );

	async function handlePay() {
		if ( ! stripe || ! elements ) return;
		setProcessing( true );
		setError( '' );

		const { error: stripeError, paymentIntent } = await stripe.confirmPayment( { elements, redirect: 'if_required' } );

		if ( stripeError ) {
			setError( stripeError.message || t( 'Error al procesar el pago.', 'Payment error.' ) );
			setProcessing( false );
			return;
		}

		if ( paymentIntent?.status === 'succeeded' ) {
			try {
				await RoomsAPI.cartConfirmPayment( cartGroupId, paymentIntent.id );
			} finally {
				onSuccess();
			}
		}
	}

	return (
		<div className="fpr-card">
			<h2 className="fpr-title">{ t( 'Pago', 'Payment' ) }</h2>
			<div className="ab-stripe-wrap">
				<PaymentElement options={ { layout: 'tabs' } } />
			</div>
			{ error && <div className="ab-error-banner">⚠ { error }</div> }
			<button type="button" className="ab-btn ab-btn-primary fpr-buy-btn" onClick={ handlePay } disabled={ processing }>
				{ processing ? t( 'Procesando…', 'Processing…' ) : t( 'Pagar', 'Pay' ) }
			</button>
		</div>
	);
}

const PRODUCT_CSS = `
.fpr-wrap { max-width:480px; margin:0 auto; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#1a2e24; }
.fpr-card { background:#fff; border:1px solid #e1f5ee; border-radius:var(--ab-radius,12px); padding:24px; }
.fpr-card-sk { height:220px; background:linear-gradient(90deg,#eef4f1 25%,#f6faf8 37%,#eef4f1 63%); background-size:400% 100%; animation:fpr-shimmer 1.4s ease infinite; }
@keyframes fpr-shimmer { 0%{background-position:100% 50%} 100%{background-position:0 50%} }
.fpr-title { font-size:20px; font-weight:800; margin:0 0 6px; }
.fpr-price { font-size:26px; font-weight:900; color:var(--ab-teal,#1D9E75); margin-bottom:18px; }
.fpr-field { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
.fpr-field label { font-size:12px; font-weight:600; color:#1a2e24; }
.fpr-field input { border:1px solid #c3d9d0; border-radius:var(--ab-radius-sm,8px); padding:9px 12px; font-size:14px; box-sizing:border-box; }
.fpr-hint { font-size:11px; color:#5a7068; margin:2px 0 0; }
.fpr-buy-btn { width:100%; margin-top:6px; }
`;
