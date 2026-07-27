// Cliente de la REST API del plugin.
// apiBase y nonce son inyectados por WordPress via wp_localize_script.

const getBase  = () => window.amirBooking?.apiUrl  ?? '/wp-json/amir/v1/';
const getNonce = () => window.amirBooking?.nonce    ?? '';

async function request( path, options = {} ) {
  const url  = getBase() + path;
  const resp = await fetch( url, {
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce':   getNonce(),
      ...( options.headers ?? {} ),
    },
    ...options,
  } );

  // Si la respuesta no es JSON válido (ej. un WAF/proxy devolviendo HTML,
  // o un 500 sin cuerpo), no dejar que resp.json() reviente sin control —
  // eso es lo que produce errores crípticos tipo "X is not a function" en
  // vez de un mensaje legible en el banner de error del widget.
  let data = null;
  try {
    data = await resp.json();
  } catch {
    if ( ! resp.ok ) {
      throw new Error( `HTTP ${resp.status}` );
    }
  }

  if ( ! resp.ok ) {
    const msg = data && typeof data === 'object' ? ( data.message ?? data.error ) : null;
    throw new Error( typeof msg === 'string' && msg !== '' ? msg : `HTTP ${resp.status}` );
  }
  return data;
}

// ── Tours ─────────────────────────────────────────────────────────────────────

export function getTour( id, lang = 'es' ) {
  return request( `tours/${id}?lang=${lang}` );
}

export function getTours( lang = 'es' ) {
  return request( `tours?lang=${lang}` );
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
