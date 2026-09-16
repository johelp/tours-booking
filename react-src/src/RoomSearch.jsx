import { useState, useEffect, useRef } from 'react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import * as RoomsAPI from './roomsApi.js';
import { fmtMoney } from './components/shared.jsx';
import { useScrollToErrorOnMobile, useScrollToTopOnChange } from './hooks.js';

/**
 * Shortcode de descubrimiento por fecha + carrito multi-ítem (Pro Max —
 * CONTRIBUTING.md § 16). Alcance de esta primera versión: solo habitaciones
 * (búsqueda por fecha + huéspedes → tarjetas → carrito → checkout). Tours en
 * el mismo carrito y el paso de extras quedan para una siguiente pasada —
 * ver CONTRIBUTING.md, "sin probar en vivo todavía".
 *
 * Carrito armado 100% en el cliente (useState, sin persistir fila por fila)
 * — decisión del cliente 2026-07-31 — el checkout final (POST
 * /flow/v1/cart/checkout) crea todas las reservas reales de una.
 *
 * roomId (opcional): cuando se monta desde la página individual de UNA
 * habitación (templates/single-flow_room.php, 2026-08-01), filtra los
 * resultados a esa habitación sola — mismo componente, mismo flujo de
 * carrito/checkout/pago, sin duplicar nada.
 */
export default function RoomSearch( { lang: initLang, roomId } ) {
	const [ lang ] = useState( initLang || 'en' );
	const [ step, setStep ] = useState( 'search' ); // search | results | checkout | payment | confirmation
	const [ checkIn, setCheckIn ] = useState( '' );
	const [ checkOut, setCheckOut ] = useState( '' );
	const [ guests, setGuests ] = useState( 2 );
	const [ rooms, setRooms ] = useState( [] );
	const [ cart, setCart ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ cartGroupId, setCartGroupId ] = useState( '' );
	const [ clientSecret, setClientSecret ] = useState( '' );
	const [ bookingRefs, setBookingRefs ] = useState( [] );

	// Autoscroll en mobile (hooks.js) — mismo patrón que DiscoveryFlow/
	// ExploreFlow/BookingWidget.
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

	const t = ( es, en ) => ( lang === 'en' ? en : es );

	function nights() {
		if ( ! checkIn || ! checkOut ) return 0;
		return Math.round( ( new Date( checkOut ) - new Date( checkIn ) ) / 86400000 );
	}

	async function handleSearch( e ) {
		e.preventDefault();
		setError( '' );
		if ( ! checkIn || ! checkOut || nights() <= 0 ) {
			setError( t( 'Elegí fechas de check-in y check-out válidas.', 'Pick valid check-in/check-out dates.' ) );
			return;
		}
		setLoading( true );
		try {
			const all = await RoomsAPI.getRooms();
			const filtered = roomId ? all.filter( r => String( r.id ) === String( roomId ) ) : all;
			const n = nights();
			// Bug real corregido 2026-08-04: "guests > capacity_max" acá
			// descalificaba CUALQUIER habitación que sola no alcanzara para
			// todo el grupo — imposible cubrir 5 huéspedes con 2 habitaciones
			// de 3, porque ninguna calificaba como "disponible" individualmente.
			// La capacidad es solo informativa (se sigue mostrando en la
			// tarjeta) — nunca descalifica la búsqueda; el indicador de
			// cobertura ya construido es lo que le dice al usuario cuándo
			// terminó de cubrir el grupo.
			// 1 solo request batch para todas las habitaciones (antes era 1
			// request POR habitación en paralelo — mismo fix que DiscoveryFlow.jsx).
			const needsCheck = filtered.filter( r => n >= r.min_nights );
			const { availability = {} } = needsCheck.length
				? await RoomsAPI.checkAvailabilityBatch( needsCheck.map( r => r.id ), checkIn, checkOut )
				: {};
			const withAvailability = filtered.map( ( r ) => {
				if ( n < r.min_nights ) return { ...r, available: false, reason: t( `Mínimo ${r.min_nights} noche(s)`, `Min ${r.min_nights} night(s)` ) };
				const available = !! availability[ r.id ];
				return { ...r, available, reason: available ? '' : t( 'No disponible en esas fechas', 'Not available for those dates' ) };
			} );
			setRooms( withAvailability );
			setStep( 'results' );
		} catch ( err ) {
			setError( err.message || t( 'Ocurrió un error. Intenta de nuevo.', 'An error occurred. Please try again.' ) );
		} finally {
			setLoading( false );
		}
	}

	function addToCart( room ) {
		if ( cart.some( c => c.room_id === room.id ) ) return;
		const n = nights();
		// Bug real corregido 2026-08-04: mandar el total buscado (ej. 5) como
		// "guests" de ESTA habitación puntual hacía que RoomBookingManager
		// rechazara la reserva en el backend si esa habitación sola no
		// alcanzaba (ej. capacidad 3) — aunque el cliente ya hubiera pasado
		// el chequeo de disponibilidad. Cada habitación manda como máximo su
		// propia capacidad; el resto del grupo lo cubren las otras del carrito.
		const roomGuests = Math.min( guests, room.capacity_max );
		setCart( [ ...cart, {
			room_id: room.id,
			name: lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es,
			check_in: checkIn, check_out: checkOut, guests: roomGuests, nights: n,
			capacity_max: room.capacity_max,
			total: room.price_per_night * n,
		} ] );
	}

	function removeFromCart( roomId ) {
		setCart( cart.filter( c => c.room_id !== roomId ) );
	}

	const cartTotal = cart.reduce( ( sum, c ) => sum + c.total, 0 );

	// Cobertura de huéspedes — mismo criterio que DiscoveryFlow.jsx (pedido
	// del cliente 2026-08-04): cada habitación cuenta hasta su capacidad
	// máxima, comparado contra el objetivo buscado.
	const guestsCovered = cart.reduce( ( sum, c ) => sum + ( c.capacity_max || 0 ), 0 );

	async function handleCheckout( customer ) {
		setError( '' );
		setLoading( true );
		try {
			const items = cart.map( c => ( {
				type: 'room', room_id: c.room_id, check_in: c.check_in, check_out: c.check_out, guests: c.guests,
				coupon_code: customer.coupon_code ?? '',
			} ) );
			const resp = await RoomsAPI.cartCheckout( { items, ...customer, lang: customer.lang ?? lang } );
			setCartGroupId( resp.cart_group_id );
			if ( resp.requires_payment && resp.client_secret ) {
				setClientSecret( resp.client_secret );
				setStep( 'payment' );
			} else {
				// Cupón que cubre el 100% de la habitación (Dudas de
				// producto, CLAUDE.md) — ya quedó confirmada sin cobrar
				// nada, ver CartController::checkout().
				setBookingRefs( resp.booking_refs ?? [] );
				setStep( 'confirmation' );
			}
		} catch ( err ) {
			setError( err.message || t( 'Ocurrió un error. Intenta de nuevo.', 'An error occurred. Please try again.' ) );
		} finally {
			setLoading( false );
		}
	}

	return (
		<div className="rs-wrap" ref={wrapRef}>
			<style>{`
			.rs-wrap { max-width:900px; margin:0 auto; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#1a2e24; }
			.rs-search { display:flex; flex-direction:column; align-items:stretch; gap:12px; background:#f8fdfb; border:1px solid #e1f5ee; border-radius:12px; padding:16px; margin-bottom:24px; }
			.rs-field { display:flex; flex-direction:column; gap:4px; width:100%; }
			.rs-field label { font-size:12px; font-weight:600; color:#1a2e24; }
			.rs-field input { border:1px solid #c3d9d0; border-radius:8px; padding:9px 12px; font-size:14px; width:100%; box-sizing:border-box; }
			.rs-inline-btn { width:auto !important; display:inline-flex !important; padding:8px 6px !important; }
			.rs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:18px; margin-bottom:80px; }
			.rs-card { border:1px solid #e1f5ee; border-radius:12px; overflow:hidden; background:#fff; }
			.rs-card.unavailable { opacity:.5; }
			.rs-card-img { height:140px; background:#c3d9d0; display:flex; align-items:center; justify-content:center; font-size:28px; overflow:hidden; }
			.rs-card-img img { width:100%; height:100%; object-fit:cover; display:block; }
			.rs-card-body { padding:14px 16px; }
			.rs-card-title { font-weight:700; font-size:15px; margin:0 0 6px; color:#1a2e24; }
			.rs-card-price { font-weight:800; color:var(--ab-teal,#1D9E75); font-size:16px; }
			.rs-cart-bar { position:sticky; bottom:0; background:#1a2e24; color:#fff; padding:14px 20px; border-radius:12px; display:flex; flex-direction:column; gap:8px; margin-top:16px; }
			.rs-cart-bar-row { display:flex; align-items:center; justify-content:space-between; gap:16px; }
			.rs-cart-bar button {
				width:auto !important; height:auto !important; background-color:var(--ab-teal,#1D9E75) !important; background-image:none !important; box-shadow:none !important;
				color:#fff !important; border:none; margin:0; border-radius:8px; padding:10px 20px; font-weight:700; font-family:inherit;
				cursor:pointer; flex-shrink:0; appearance:none; -webkit-appearance:none; -moz-appearance:none;
			}
			.rs-cart-item-remove {
				width:auto !important; height:auto !important; background-color:transparent !important; background-image:none !important; box-shadow:none !important;
				border:none; margin:0 0 0 8px; padding:0 !important; color:#e24b4a !important; font-family:inherit; cursor:pointer;
				appearance:none; -webkit-appearance:none; -moz-appearance:none;
			}
			.rs-guest-coverage { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; padding:10px 14px; border-radius:8px; margin-bottom:12px; }
			.rs-guest-coverage.ok { background:#eafaf1; color:var(--ab-teal-dark,#0F6E56); }
			.rs-guest-coverage.short { background:#fff7e6; color:#a15c00; }
			.rs-checkout-field { margin-bottom:12px; }
			.rs-checkout-field label { display:block; font-size:13px; font-weight:600; margin-bottom:4px; }
			.rs-checkout-field input, .rs-checkout-field textarea { width:100%; border:1px solid #c3d9d0; border-radius:8px; padding:9px 12px; font-size:14px; box-sizing:border-box; }
			@media (min-width:640px) {
			  .rs-search { flex-direction:row; flex-wrap:wrap; align-items:end; }
			  .rs-search .rs-field { width:auto; }
			  .rs-search .rs-field input { width:auto; }
			  .rs-search > button[type=submit] { width:auto; }
			}
			`}</style>

			{ step === 'search' && (
				<form className="rs-search" onSubmit={ handleSearch }>
					<div className="rs-field">
						<label>{ t( 'Check-in', 'Check-in' ) }</label>
						<input type="date" required value={ checkIn } min={ new Date().toISOString().slice(0,10) } onChange={ e => setCheckIn( e.target.value ) } />
					</div>
					<div className="rs-field">
						<label>{ t( 'Check-out', 'Check-out' ) }</label>
						<input type="date" required value={ checkOut } min={ checkIn || new Date().toISOString().slice(0,10) } onChange={ e => setCheckOut( e.target.value ) } />
					</div>
					<div className="rs-field">
						<label>{ t( 'Huéspedes', 'Guests' ) }</label>
						<input type="number" min="1" value={ guests } onChange={ e => setGuests( parseInt( e.target.value, 10 ) || 1 ) } style={ { width: '80px' } } />
					</div>
					<button type="submit" className="ab-btn ab-btn-primary" disabled={ loading }>
						{ loading ? t( 'Buscando…', 'Searching…' ) : t( 'Buscar disponibilidad', 'Search availability' ) }
					</button>
				</form>
			) }

			{ error && <div className="ab-error-banner">⚠ { error }</div> }

			{ step === 'results' && (
				<>
					<button className="ab-btn ab-btn-ghost rs-inline-btn" onClick={ () => setStep( 'search' ) }>← { t( 'Cambiar fechas', 'Change dates' ) }</button>

					{ cart.length > 0 && (
						<div className={ `rs-guest-coverage ${ guestsCovered >= guests ? 'ok' : 'short' }` } style={ { marginTop: 16 } }>
							{ guestsCovered >= guests ? '✅' : '⚠' } { t(
								`Cubrís ${guestsCovered} de ${guests} huéspedes con ${cart.length} habitación(es)`,
								`Covers ${guestsCovered} of ${guests} guests with ${cart.length} room(s)`
							) }
							{ guestsCovered < guests && <span> — { t( 'agregá otra habitación', 'add another room' ) }</span> }
						</div>
					) }

					<div className="rs-grid" style={ { marginTop: 16 } }>
						{ rooms.map( room => (
							<div key={ room.id } className={ `rs-card ${ room.available ? '' : 'unavailable' }` }>
								<div className="rs-card-img">
									{ room.gallery_images?.[0]
										? <img src={ room.gallery_images[0] } alt={ lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es } loading="lazy" decoding="async" />
										: '🛏' }
								</div>
								<div className="rs-card-body">
									<h3 className="rs-card-title">{ lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es }</h3>
									<p style={ { fontSize: 13, color: '#5a7068', margin: '0 0 8px' } }>👥 { t( `Hasta ${room.capacity_max}`, `Up to ${room.capacity_max}` ) }</p>
									{ room.available ? (
										<>
											<div className="rs-card-price">{ fmtMoney( room.price_per_night * nights(), window.amirBooking?.currency ?? 'MXN' ) }</div>
											<p style={ { fontSize: 11, color: '#5a7068', margin: '2px 0 10px' } }>{ nights() } { t( 'noche(s)', 'night(s)' ) }</p>
											{ cart.some( c => c.room_id === room.id ) ? (
												<button className="ab-btn ab-btn-outline ab-btn-sm" onClick={ () => removeFromCart( room.id ) }>
													✓ { t( 'Quitar', 'Remove' ) }
												</button>
											) : (
												<button className="ab-btn ab-btn-primary ab-btn-sm" onClick={ () => addToCart( room ) }>
													{ t( '+ Agregar', '+ Add' ) }
												</button>
											) }
										</>
									) : (
										<p style={ { fontSize: 12, color: '#e24b4a' } }>{ room.reason }</p>
									) }
								</div>
							</div>
						) ) }
						{ rooms.length === 0 && <p>{ t( 'No hay habitaciones cargadas.', 'No rooms configured.' ) }</p> }
					</div>

					{ cart.length > 0 && (
						<div className="rs-cart-bar">
							<div className="rs-cart-bar-row">
								<span>🛒 { cart.length } { t( 'ítem(s)', 'item(s)' ) } — <strong>{ fmtMoney( cartTotal, window.amirBooking?.currency ?? 'MXN' ) }</strong></span>
								<button onClick={ () => setStep( 'checkout' ) }>{ t( 'Continuar →', 'Continue →' ) }</button>
							</div>
							<span style={ { fontSize: 12, opacity: .9 } }>
								{ guestsCovered >= guests ? '✅' : '⚠' } { t( `${guestsCovered} de ${guests} huéspedes cubiertos`, `${guestsCovered} of ${guests} guests covered` ) }
							</span>
						</div>
					) }
				</>
			) }

			{ step === 'checkout' && (
				<CheckoutForm
					t={ t }
					lang={ lang }
					cart={ cart }
					cartTotal={ cartTotal }
					removeFromCart={ removeFromCart }
					onSubmit={ handleCheckout }
					onBack={ () => setStep( 'results' ) }
					loading={ loading }
					currency={ window.amirBooking?.currency ?? 'MXN' }
				/>
			) }

			{ step === 'payment' && clientSecret && stripeRef.current && (
				<Elements stripe={ stripeRef.current } options={ { clientSecret, locale: lang } }>
					<CartPaymentStep
						t={ t }
						cartGroupId={ cartGroupId }
						onSuccess={ ( refs ) => { setBookingRefs( refs ); setStep( 'confirmation' ); } }
					/>
				</Elements>
			) }

			{ step === 'confirmation' && (
				<div className="ab-panel" style={ { textAlign: 'center' } }>
					<div className="ab-confirm-icon">✅</div>
					<h2 className="ab-confirm-title">{ t( '¡Reserva confirmada!', 'Booking confirmed!' ) }</h2>
					{ bookingRefs.length > 0 && (
						<p>{ t( 'Referencia(s)', 'Reference(s)' ) }: <strong>{ bookingRefs.join( ', ' ) }</strong></p>
					) }
					<p className="ab-confirm-sub">{ t( 'Te enviamos un email con los detalles.', 'We sent you an email with the details.' ) }</p>
				</div>
			) }
		</div>
	);
}

function CheckoutForm( { t, lang, cart, cartTotal, removeFromCart, onSubmit, onBack, loading, currency } ) {
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );
	const [ couponCode, setCouponCode ] = useState( '' );
	const [ policyAccepted, setPolicyAccepted ] = useState( false );
	const [ termsAccepted, setTermsAccepted ] = useState( false );
	// Preferencia de idioma para el email de confirmación — mismo fix que
	// DiscoveryFlow.jsx/ExploreFlow.jsx, ver CONTRIBUTING.md § 16.91.
	const [ emailLang, setEmailLang ] = useState( lang ?? 'es' );

	function handleSubmit( e ) {
		e.preventDefault();
		onSubmit( { customer_name: name, customer_email: email, customer_phone: phone, coupon_code: couponCode.trim(), policy_accepted: policyAccepted, terms_accepted: termsAccepted, lang: emailLang } );
	}

	return (
		<form className="ab-panel" onSubmit={ handleSubmit }>
			<p className="ab-panel-title">{ t( 'Tus datos', 'Your details' ) }</p>

			<ul style={ { listStyle: 'none', padding: 0, margin: '0 0 16px' } }>
				{ cart.map( c => (
					<li key={ c.room_id } style={ { display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #e1f5ee', fontSize: 14 } }>
						<span>{ c.name } — { c.check_in } → { c.check_out } ({ c.nights } { t( 'noches', 'nights' ) })</span>
						<span>{ fmtMoney( c.total, currency ) } <button type="button" className="rs-cart-item-remove" onClick={ () => removeFromCart( c.room_id ) }>✕</button></span>
					</li>
				) ) }
			</ul>
			<p className="ab-price-total"><span className="ab-price-total-label">{ t( 'Total', 'Total' ) }</span> <span className="ab-price-total-amount">{ fmtMoney( cartTotal, currency ) }</span></p>

			<div className="rs-checkout-field">
				<label>{ t( 'Nombre completo', 'Full name' ) }</label>
				<input type="text" required value={ name } onChange={ e => setName( e.target.value ) } />
			</div>
			<div className="rs-checkout-field">
				<label>Email</label>
				<input type="email" required value={ email } onChange={ e => setEmail( e.target.value ) } />
			</div>
			<div className="rs-checkout-field">
				<label>{ t( 'Teléfono', 'Phone' ) }</label>
				<input type="tel" value={ phone } onChange={ e => setPhone( e.target.value ) } />
			</div>
			<div className="ab-form-group">
				<label className="ab-label">{ t( 'Idioma para tu email de confirmación', 'Language for your confirmation email' ) }</label>
				<div className="ab-lang-toggle">
					{ ( window.amirBooking?.activeLanguages ?? [ 'es', 'en' ] ).map( code => (
						<button key={ code } type="button"
							className={ `ab-lang-btn${ emailLang === code ? ' active' : '' }` }
							onClick={ () => setEmailLang( code ) }
						>{ code === 'es' ? t( 'Español', 'Spanish' ) : code === 'en' ? t( 'English', 'English' ) : code.toUpperCase() }</button>
					) ) }
				</div>
			</div>
			<div className="rs-checkout-field">
				<label>{ t( 'Código de cupón (opcional)', 'Coupon code (optional)' ) }</label>
				<input type="text" value={ couponCode } onChange={ e => setCouponCode( e.target.value.toUpperCase() ) } placeholder={ t( 'Ej: VERANO10', 'Ex: SUMMER10' ) } />
			</div>

			{/* Mismo bug/fix que BookingWidget.jsx/DiscoveryFlow.jsx/ExploreFlow.jsx:
				nunca una casilla sin texto de política/términos arriba. */}
			<div className="ab-policy-box">
				<div className="ab-policy-title">📋 { t( 'Política de cancelación', 'Cancellation policy' ) }</div>
				{ ( () => {
					const custom = t( window.amirBooking?.policyTextEs, window.amirBooking?.policyTextEn );
					const lines = custom ? custom.split( '\n' ).filter( Boolean ) : [
						t( '✓ 7+ días antes: reembolso completo', '✓ 7+ days before: full refund' ),
						t( '▸ 3–6 días antes: reembolso del 50 %', '▸ 3–6 days before: 50 % refund' ),
						t( '✕ Menos de 3 días: sin reembolso', '✕ Less than 3 days: no refund' ),
					];
					return lines.map( ( line, i ) => <div className="ab-policy-line" key={ i }>{ line }</div> );
				} )() }
			</div>
			<label className="ab-policy-check-label">
				<input type="checkbox" checked={ policyAccepted } onChange={ e => setPolicyAccepted( e.target.checked ) } />
				{ t( 'He leído y acepto la política de cancelación', 'I have read and accept the cancellation policy' ) }
			</label>

			<div className="ab-policy-box">
				<div className="ab-policy-title">📜 { t( 'Términos y condiciones', 'Terms and conditions' ) }</div>
				{ ( () => {
					const custom = t( window.amirBooking?.termsTextEs, window.amirBooking?.termsTextEn );
					const lines = custom ? custom.split( '\n' ).filter( Boolean ) : [
						t( 'Al reservar, aceptás las condiciones de uso y venta de este sitio — datos personales tratados según la política de privacidad del operador.',
						   'By booking, you accept this site\'s terms of use and sale — personal data is handled according to the operator\'s privacy policy.' ),
					];
					return lines.map( ( line, i ) => <div className="ab-policy-line" key={ i }>{ line }</div> );
				} )() }
			</div>
			<label className="ab-policy-check-label">
				<input type="checkbox" checked={ termsAccepted } onChange={ e => setTermsAccepted( e.target.checked ) } />
				{ t( 'He leído y acepto los términos y condiciones', 'I have read and accept the terms and conditions' ) }
			</label>

			<div className="ab-btn-row" style={ { marginTop: 16 } }>
				<button type="button" className="ab-btn ab-btn-ghost" onClick={ onBack } disabled={ loading }>← { t( 'Volver', 'Back' ) }</button>
				<button type="submit" className="ab-btn ab-btn-primary" disabled={ loading || ! policyAccepted || ! termsAccepted }>
					{ loading ? t( 'Procesando…', 'Processing…' ) : t( 'Ir a pagar', 'Go to payment' ) }
				</button>
			</div>
		</form>
	);
}

/** Paso de pago del carrito — variante de BookingWidget's StepPayment, apuntando a cart/{id}/confirm-payment en vez de bookings/{id}/confirm-payment. */
function CartPaymentStep( { t, cartGroupId, onSuccess } ) {
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
				const data = await RoomsAPI.cartConfirmPayment( cartGroupId, paymentIntent.id );
				onSuccess( data.booking_refs ?? [] );
			} catch {
				onSuccess( [] );
			}
		}
	}

	return (
		<div className="ab-panel">
			<p className="ab-panel-title">{ t( 'Pago', 'Payment' ) }</p>
			<div className="ab-stripe-wrap">
				<PaymentElement options={ { layout: 'tabs' } } />
			</div>
			{ error && <div className="ab-error-banner">⚠ { error }</div> }
			<div className="ab-btn-row">
				<button className="ab-btn ab-btn-primary" onClick={ handlePay } disabled={ processing || ! stripe }>
					{ processing ? t( 'Procesando…', 'Processing…' ) : t( 'Pagar', 'Pay' ) }
				</button>
			</div>
		</div>
	);
}
