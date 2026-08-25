// Cliente de la REST API del plugin.
// apiBase y nonce son inyectados por WordPress via wp_localize_script.

const getBase  = () => window.amirBooking?.apiUrl  ?? '/wp-json/amir/v1/';
const getNonce = () => window.amirBooking?.nonce    ?? '';

async function request( path, options = {} ) {
  const url = getBase() + path;
  let resp;
  try {
    resp = await fetch( url, {
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce':   getNonce(),
        ...( options.headers ?? {} ),
      },
      ...options,
    } );
  } catch ( networkErr ) {
    // fetch() en sí falló (sin conexión, timeout, WAF cortando la conexión
    // antes de responder) — sin este catch, el cliente veía el mensaje
    // nativo del navegador ("Failed to fetch"), sin traducir y poco claro.
    // Mensaje vacío a propósito: cada call site ya hace
    // `e.message || t('err_generic'|'something_wrong')`, así que un
    // mensaje vacío deja que el texto traducido tome el control solo.
    console.error( 'TourFlow: fetch failed', networkErr );
    throw new Error( '' );
  }

  // Si la respuesta no es JSON válido (ej. un WAF/proxy devolviendo HTML,
  // o un 500 sin cuerpo), no dejar que resp.json() reviente sin control —
  // eso es lo que produce errores crípticos tipo "X is not a function" en
  // vez de un mensaje legible en el banner de error del widget. Mismo
  // criterio que el catch de arriba: mensaje vacío, no "HTTP 500" crudo
  // sin traducir — el status queda en consola para debug.
  let data = null;
  try {
    data = await resp.json();
  } catch {
    if ( ! resp.ok ) {
      console.error( `TourFlow: HTTP ${resp.status} sin cuerpo JSON` );
      throw new Error( '' );
    }
  }

  if ( ! resp.ok ) {
    const msg = data && typeof data === 'object' ? ( data.message ?? data.error ) : null;
    if ( typeof msg === 'string' && msg !== '' ) {
      throw new Error( msg );
    }
    console.error( `TourFlow: HTTP ${resp.status}`, data );
    throw new Error( '' );
  }
  return data;
}

// ── Tours ─────────────────────────────────────────────────────────────────────

export function getTour( id, lang = 'es' ) {
  return request( `tours/${id}?lang=${lang}` );
}

export function getTours( lang = 'es', filters = {} ) {
  const params = new URLSearchParams( { lang } );
  if ( filters.category )    params.set( 'category', filters.category );
  if ( filters.source && filters.source !== 'all' ) params.set( 'source', filters.source );
  if ( filters.providerId )  params.set( 'provider_id', filters.providerId );
  return request( `tours?${params.toString()}` );
}

// ── Lista de interés ("avísame cuando abra") ────────────────────────────────

export function getUpcomingTours( lang = 'es' ) {
  return request( `tours/upcoming?lang=${lang}` );
}

export function registerInterest( tourId, payload ) {
  return request( `tours/${tourId}/wishlist`, {
    method: 'POST',
    body: JSON.stringify( payload ),
  } );
}

export function getTourSchedules( tourId, lang = 'es' ) {
  return request( `tours/${tourId}/schedules?lang=${lang}` );
}

// ── Disponibilidad ────────────────────────────────────────────────────────────

export function getMonthAvailability( tourId, year, month ) {
  return request( `availability/month?tour_id=${tourId}&year=${year}&month=${month}` );
}

export function getDaySchedules( tourId, date ) {
  return request( `availability/day?tour_id=${tourId}&date=${date}` );
}

// ── Precios ───────────────────────────────────────────────────────────────────

export function getQuote( { tourId, scheduleId, date, adults, children, babies, couponCode, addons, lang } ) {
  return request( 'bookings/quote', {
    method: 'POST',
    body: JSON.stringify( {
      tour_id: tourId, schedule_id: scheduleId, date, adults, children, babies,
      coupon_code: couponCode ?? '', addons: addons ?? [], lang: lang ?? 'es',
    } ),
  } );
}

// ── Reservas ──────────────────────────────────────────────────────────────────

export function createBooking( payload ) {
  return request( 'bookings', {
    method: 'POST',
    body: JSON.stringify( payload ),
  } );
}

export function getBooking( ref ) {
  return request( `bookings/${ref}` );
}

// Solicitar una fecha distinta a la fija de un tour de fecha fija — ver
// BookingController::request_date() / BookingManager::create_date_request().
// Sin cobro: si el operador aprueba, llega un email con el link de pago.
export function requestDate( tourId, payload ) {
  return request( `tours/${tourId}/request-date`, {
    method: 'POST',
    body: JSON.stringify( payload ),
  } );
}
