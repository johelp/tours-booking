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

  const data = await resp.json();

  if ( ! resp.ok ) {
    throw new Error( data.message ?? data.error ?? `HTTP ${resp.status}` );
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

// ── Disponibilidad ────────────────────────────────────────────────────────────

export function getMonthAvailability( tourId, year, month ) {
  return request( `availability/month?tour_id=${tourId}&year=${year}&month=${month}` );
}

export function getDaySchedules( tourId, date ) {
  return request( `availability/day?tour_id=${tourId}&date=${date}` );
}

// ── Precios ───────────────────────────────────────────────────────────────────

export function getQuote( { tourId, scheduleId, date, adults, children, babies, couponCode } ) {
  return request( 'bookings/quote', {
    method: 'POST',
    body: JSON.stringify( { tour_id: tourId, schedule_id: scheduleId, date, adults, children, babies, coupon_code: couponCode ?? '' } ),
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
