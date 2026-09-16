// ── Meta Pixel / Google Ads / GA4 — funciones auxiliares para el embudo del
// widget de reserva. Los scripts base (fbq/gtag) los inyecta el servidor en
// wp_head (Core\Marketing::print_base_scripts()) solo si hay algún ID
// cargado en Configuración → Marketing — acá nunca se cargan pixeles desde
// el JS, solo se dispara el evento si esas funciones globales ya existen.

export function trackInitiateCheckout( tour ) {
  if ( typeof window === 'undefined' ) return;
  const currency = window.amirBooking?.currency ?? 'USD';

  if ( window.fbq ) {
    window.fbq( 'track', 'InitiateCheckout', {
      content_ids:  [ String( tour?.id ?? '' ) ],
      content_type: 'product',
      currency,
    } );
  }
  if ( window.gtag ) {
    window.gtag( 'event', 'begin_checkout', {
      currency,
      items: [ { item_id: String( tour?.id ?? '' ), item_name: tour?.name ?? '' } ],
    } );
  }
}

export function trackPurchase( { tourId, tourName, bookingRef, value, currency } ) {
  if ( typeof window === 'undefined' ) return;

  if ( window.fbq ) {
    window.fbq( 'track', 'Purchase', {
      content_ids:  [ String( tourId ?? '' ) ],
      content_type: 'product',
      value,
      currency,
    } );
  }
  if ( window.gtag ) {
    window.gtag( 'event', 'purchase', {
      transaction_id: bookingRef,
      value,
      currency,
      items: [ { item_id: String( tourId ?? '' ), item_name: tourName ?? '' } ],
    } );

    // Acción de conversión de Google Ads — necesita el id+label específico
    // (distinto de la config de GA4), separado del evento 'purchase' de arriba.
    const { gadsConversionId, gadsConversionLabel } = window.amirBooking?.marketing ?? {};
    if ( gadsConversionId && gadsConversionLabel ) {
      window.gtag( 'event', 'conversion', {
        send_to: `${gadsConversionId}/${gadsConversionLabel}`,
        value,
        currency,
        transaction_id: bookingRef,
      } );
    }
  }
}

/**
 * ?coupon=CODE en la URL del tour — para links de partners tipo "reservá
 * con 10% con este link", sin que el cliente tenga que escribir el código
 * a mano. No valida nada acá (lo hace el backend al cotizar) — si viene
 * vacío, el campo de cupón queda como siempre (vacío, carga manual).
 */
export function getCouponFromUrl() {
  if ( typeof window === 'undefined' ) return '';
  const params = new URLSearchParams( window.location.search );
  return ( params.get( 'coupon' ) ?? '' ).trim().toUpperCase().slice( 0, 50 );
}
