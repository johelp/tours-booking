import { useState, useEffect, useRef } from 'react';
import { loadStripe }          from '@stripe/stripe-js';
import { Elements }            from '@stripe/react-stripe-js';
import { StepPayment, StepPaymentMP, StepPaymentRedsys } from './BookingWidget.jsx';
import { useT }                from './i18n.js';
import { trackPurchase }       from './marketing.js';
import './styles/widget.css';

/**
 * Paga una reserva ya cargada (lista de interés convertida al abrir el
 * tour, o "cargar reserva + link de pago" desde el admin) — [amir_pay_booking]
 *
 * Monta en la misma página de verificación de reserva (verificar-reserva/)
 * cuando el estado de la reserva es 'awaiting_payment' o 'pending'. Reutiliza
 * StepPayment/StepPaymentMP de BookingWidget.jsx tal cual — el backend
 * (POST /bookings/{ref}/init-payment) devuelve la misma forma de respuesta
 * que crear una reserva nueva, así que no hace falta un flujo aparte.
 */
export default function PayBooking({ bookingRef, token, lang: initLang }) {
  const [ lang  ] = useState( initLang ?? 'es' );
  const [ phase, setPhase ] = useState( 'loading' ); // loading | pay | confirm | error
  const [ error, setError ] = useState( '' );
  const [ gateway, setGateway ] = useState( 'stripe' );
  const [ clientSecret, setClientSecret ] = useState( '' );
  const [ mpData, setMpData ] = useState( null );
  const [ redsysData, setRedsysData ] = useState( null );
  const [ bookingId, setBookingId ] = useState( null );
  const [ finalRef, setFinalRef ] = useState( bookingRef );
  const [ totalMxn, setTotalMxn ] = useState( 0 );
  const stripeRef = useRef( null );
  const purchaseFired = useRef( false );
  const t = useT( lang );

  useEffect( () => {
    if ( window.amirBooking?.stripePk && ! stripeRef.current ) {
      stripeRef.current = loadStripe( window.amirBooking.stripePk );
    }
  }, [] );

  useEffect( () => {
    const base = window.amirBooking?.apiUrl ?? '/wp-json/amir/v1/';
    fetch( `${base}bookings/${encodeURIComponent(bookingRef)}/init-payment?token=${encodeURIComponent(token)}`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.amirBooking?.nonce ?? '' },
    } )
      .then( async r => {
        const data = await r.json().catch( () => null );
        if ( ! r.ok ) throw new Error( ( data && data.error ) || `HTTP ${r.status}` );
        return data;
      } )
      .then( data => {
        setBookingId( data.booking_id );
        setTotalMxn( data.total_mxn ?? 0 );
        if ( data.gateway === 'mercadopago' ) {
          setGateway( 'mercadopago' );
          setMpData({ preference_id: data.preference_id, init_point: data.init_point, sandbox_init_point: data.sandbox_init_point });
        } else if ( data.gateway === 'redsys' ) {
          setGateway( 'redsys' );
          setRedsysData({
            action_url:          data.redsys_action_url,
            signature_version:   data.redsys_signature_version,
            merchant_parameters: data.redsys_merchant_parameters,
            signature:           data.redsys_signature,
          });
        } else {
          setGateway( 'stripe' );
          setClientSecret( data.client_secret );
        }
        setPhase( 'pay' );
      } )
      .catch( e => { setError( e.message || t('err_generic') ); setPhase( 'error' ); } );
  }, [] );

  // Purchase/purchase — cable suelto real corregido 2026-08-04 (documentado
  // en CONTRIBUTING.md § 5.3): BookingWidget.jsx ya dispara este evento al
  // confirmar una reserva NUEVA, pero pagar una reserva YA CARGADA (lista de
  // interés convertida, o "cargar reserva + link de pago" desde el admin)
  // nunca lo hacía — esas conversiones se perdían en Meta Pixel/GA4 en
  // silencio. Mismo criterio que BookingWidget: una sola vez por reserva.
  useEffect( () => {
    if ( phase !== 'confirm' || purchaseFired.current ) return;
    purchaseFired.current = true;
    trackPurchase({
      bookingRef: finalRef,
      value:      totalMxn,
      currency:   window.amirBooking?.currency ?? 'USD',
    });
  }, [ phase, finalRef, totalMxn ] );

  if ( phase === 'loading' ) return (
    <div className="ab-widget">
      <div className="ab-loading"><div className="ab-spinner" />{t('loading')}</div>
    </div>
  );

  if ( phase === 'error' ) return (
    <div className="ab-widget">
      <div className="ab-panel"><div className="ab-error-banner">⚠ {error}</div></div>
    </div>
  );

  if ( phase === 'confirm' ) return (
    <div className="ab-widget">
      <div className="ab-panel" style={{textAlign:'center'}}>
        <div className="ab-confirm-icon">🎉</div>
        <h2 className="ab-confirm-title">{t('confirmed_title')}</h2>
        <p className="ab-confirm-sub">{t('confirmed_sub')}</p>
        <div className="ab-ref-box">
          <div className="ab-ref-label">{t('booking_ref')}</div>
          <div className="ab-ref-value">{finalRef || '—'}</div>
        </div>
      </div>
    </div>
  );

  const stepProps = { t, goBack: () => {}, goNext: () => setPhase('confirm'), setBookingRef: setFinalRef, bookingRef: finalRef, bookingId, mpData, redsysData };

  return (
    <div className="ab-widget">
      { gateway === 'mercadopago' && <StepPaymentMP {...stepProps} /> }
      { gateway === 'redsys' && <StepPaymentRedsys {...stepProps} /> }
      { gateway === 'stripe' && (
          <Elements stripe={stripeRef.current} options={{ clientSecret, locale: lang }}>
            <StepPayment {...stepProps} />
          </Elements>
        )
      }
    </div>
  );
}
