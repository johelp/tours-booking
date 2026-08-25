// Cliente REST para el namespace flow/v1 (habitaciones + carrito, Pro Max —
// CONTRIBUTING.md § 16). Mismo patrón que api.js (amir/v1), namespace
// distinto a propósito (§ 15.13 — no reusa "amir" en desarrollo nuevo).

const getBase = () => window.amirBooking?.flowApiUrl ?? '/wp-json/flow/v1/';

async function request( path, options = {} ) {
  const url  = getBase() + path;
  const resp = await fetch( url, {
    headers: { 'Content-Type': 'application/json', ...( options.headers ?? {} ) },
    ...options,
  } );

  let data = null;
  try {
    data = await resp.json();
  } catch {
    if ( ! resp.ok ) throw new Error( `HTTP ${resp.status}` );
  }

  if ( ! resp.ok ) {
    const msg = data && typeof data === 'object' ? ( data.message ?? data.error ) : null;
    throw new Error( typeof msg === 'string' && msg !== '' ? msg : `HTTP ${resp.status}` );
  }
  return data;
}

export function getRooms() {
  return request( 'rooms' );
}

export function checkAvailability( roomId, checkIn, checkOut ) {
  const params = new URLSearchParams( { check_in: checkIn, check_out: checkOut } );
  return request( `rooms/${roomId}/availability?${params.toString()}` );
}

// 1 request para N habitaciones en vez de N — evita el N+1 que tenía
// DiscoveryFlow.jsx/RoomSearch.jsx al armar la grilla de resultados.
export function checkAvailabilityBatch( roomIds, checkIn, checkOut ) {
  return request( 'rooms/availability-batch', {
    method: 'POST',
    body: JSON.stringify( { room_ids: roomIds, check_in: checkIn, check_out: checkOut } ),
  } );
}

export function cartCheckout( payload ) {
  return request( 'cart/checkout', { method: 'POST', body: JSON.stringify( payload ) } );
}

export function cartConfirmPayment( cartGroupId, paymentIntentId ) {
  return request( `cart/${cartGroupId}/confirm-payment`, {
    method: 'POST',
    body: JSON.stringify( { payment_intent_id: paymentIntentId } ),
  } );
}

// Voucher general (PDF+QR único por cart_group_id) — mismo criterio de
// autorización débil que el resto de los links públicos del plugin (email,
// nunca solo la referencia). No hay endpoint que devuelva JSON acá, es un
// link directo de descarga.
export function cartVoucherUrl( cartGroupId, email ) {
  const params = new URLSearchParams( { email: email ?? '' } );
  return getBase() + `cart/${cartGroupId}/pdf?${params.toString()}`;
}

// ── Flujo continuo de descubrimiento (Pro Max — CONTRIBUTING.md § 16.15) ──

// Flujo A paso 1: tours propios destacados alrededor de una fecha.
export function getFeaturedTours( { date, limit, windowDays, lang } = {} ) {
  const params = new URLSearchParams();
  if ( date )       params.set( 'date', date );
  if ( limit )       params.set( 'limit', limit );
  if ( windowDays )  params.set( 'window_days', windowDays );
  if ( lang )        params.set( 'lang', lang );
  return request( `tours/featured?${params.toString()}` );
}

// Flujo B paso 2 (y "otros tours" del paso de extras): catálogo completo
// (propio + proveedor) con disponibilidad dentro de un rango explícito.
// context: 'list' (default, Flujo B) filtra hide_from_lists; 'suggestion'
// (paso de extras) filtra hide_from_suggestions — dos flags independientes
// (§ 16.21 CONTRIBUTING.md, 2026-08-04).
export function getCatalogWindow( { from, until, lang, context } ) {
  const params = new URLSearchParams( { from, until } );
  if ( lang )    params.set( 'lang', lang );
  if ( context ) params.set( 'context', context );
  return request( `tours/catalog-window?${params.toString()}` );
}

// Extras globales (§ 16.23 CONTRIBUTING.md) — servicios extra/productos
// digitales que no pertenecen a ningún tour/habitación puntual, para el
// paso de extras del flujo continuo (funciona aunque el carrito no tenga
// ningún tour).
export function getGlobalAddons( lang ) {
  const params = new URLSearchParams();
  if ( lang ) params.set( 'lang', lang );
  return request( `addons/global?${params.toString()}` );
}
