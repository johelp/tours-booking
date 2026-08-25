import { createRoot } from 'react-dom/client';
import BookingWidget  from './BookingWidget.jsx';
import BookingVariants from './BookingVariants.jsx';
import ProductOrder    from './ProductOrder.jsx';
import TourList       from './TourList.jsx';
import WishlistList   from './WishlistList.jsx';
import PayBooking      from './PayBooking.jsx';
import RoomSearch      from './RoomSearch.jsx';
import RoomList        from './RoomList.jsx';
import DiscoveryFlow   from './DiscoveryFlow.jsx';
import ExploreFlow     from './ExploreFlow.jsx';
import SpotsLeft         from './SpotsLeft.jsx';
import TourDates         from './TourDates.jsx';
import SearchBarStandalone from './SearchBarStandalone.jsx';

document.addEventListener( 'DOMContentLoaded', () => {
  // Widgets de reserva individuales
  document.querySelectorAll( '[data-amir-booking]' ).forEach( el => {
    const tourId    = parseInt( el.dataset.tourId, 10 );
    const lang      = el.dataset.lang ?? 'es';
    const stripeKey = window.amirBooking?.stripePk ?? '';
    if ( ! tourId ) return;
    createRoot( el ).render(
      <BookingWidget tourId={tourId} lang={lang} stripeKey={stripeKey} />
    );
  });

  // Selector de variantes — [flow_booking_variants] (CONTRIBUTING.md § 16.93)
  document.querySelectorAll( '[data-flow-booking-variants]' ).forEach( el => {
    const lang    = el.dataset.lang ?? 'es';
    const tourIds = ( el.dataset.tourIds ?? '' ).split( ',' ).map( n => parseInt( n, 10 ) ).filter( Boolean );
    if ( ! tourIds.length ) return;
    createRoot( el ).render(
      <BookingVariants tourIds={tourIds} lang={lang}
        titleEs={el.dataset.titleEs} titleEn={el.dataset.titleEn}
        columns={parseInt( el.dataset.columns, 10 ) || 2} />
    );
  });

  // Venta suelta de un producto digital — [flow_product] (CONTRIBUTING.md § 16.9x)
  document.querySelectorAll( '[data-flow-product]' ).forEach( el => {
    const lang    = el.dataset.lang ?? 'es';
    const addonId = parseInt( el.dataset.addonId, 10 );
    if ( ! addonId ) return;
    createRoot( el ).render(
      <ProductOrder lang={lang} addonId={addonId} />
    );
  });

  // Grillas de tours
  document.querySelectorAll( '[data-amir-tour-list]' ).forEach( el => {
    const lang = el.dataset.lang ?? 'es';
    createRoot( el ).render(
      <TourList lang={lang} rootEl={el} />
    );
  });

  // Grillas de "Próximamente" (lista de interés)
  document.querySelectorAll( '[data-amir-wishlist]' ).forEach( el => {
    const lang = el.dataset.lang ?? 'es';
    createRoot( el ).render(
      <WishlistList lang={lang} rootEl={el} />
    );
  });

  // Pagar una reserva ya cargada (link de email) — montado dentro de
  // [amir_verify_booking] cuando el estado es 'awaiting_payment'/'pending'
  document.querySelectorAll( '[data-amir-pay-booking]' ).forEach( el => {
    const ref   = el.dataset.ref;
    const token = el.dataset.token;
    const lang  = el.dataset.lang ?? 'es';
    if ( ! ref ) return;
    createRoot( el ).render(
      <PayBooking bookingRef={ref} token={token} lang={lang} />
    );
  });

  // Buscador de habitaciones por fecha + carrito (Pro Max — CONTRIBUTING.md § 16)
  // data-room-id (opcional): montado desde single-flow_room.php, filtra a
  // esa única habitación en vez del catálogo completo.
  document.querySelectorAll( '[data-flow-room-search]' ).forEach( el => {
    const lang   = el.dataset.lang ?? 'es';
    const roomId = el.dataset.roomId ? parseInt( el.dataset.roomId, 10 ) : null;
    createRoot( el ).render(
      <RoomSearch lang={lang} roomId={roomId} />
    );
  });

  // Grilla de habitaciones sin buscador previo (Pro Max — CONTRIBUTING.md § 16)
  document.querySelectorAll( '[data-flow-room-list]' ).forEach( el => {
    const lang    = el.dataset.lang ?? 'es';
    const columns = el.dataset.columns ? parseInt( el.dataset.columns, 10 ) : 3;
    createRoot( el ).render(
      <RoomList lang={lang} columns={columns} />
    );
  });

  // Flujo continuo de descubrimiento (Pro Max — CONTRIBUTING.md § 16.15)
  // data-tour-id/data-room-id (opcionales): arrancan el flujo con ese ítem
  // ya preseleccionado — usado desde single-amir_tour.php/single-flow_room.php
  // para que reservar desde la ficha de un tour o habitación continúe con
  // el upsell en vez de terminar en una confirmación simple.
  document.querySelectorAll( '[data-flow-discovery]' ).forEach( el => {
    const lang   = el.dataset.lang ?? 'es';
    const mode   = el.dataset.mode === 'room' ? 'room' : 'experience';
    const tourId = el.dataset.tourId ? parseInt( el.dataset.tourId, 10 ) : null;
    const roomId = el.dataset.roomId ? parseInt( el.dataset.roomId, 10 ) : null;
    const showCatalog = el.dataset.showCatalog !== 'no';
    createRoot( el ).render(
      <DiscoveryFlow lang={lang} mode={mode} tourId={tourId} roomId={roomId} showCatalog={showCatalog} />
    );
  });

  // Flujo Explorar, búsqueda-primero (Pro Max — CONTRIBUTING.md § 16.46)
  // — standalone, sin tour_id/room_id preseleccionado a propósito, convive
  // con [flow_discovery] sin reemplazarlo.
  document.querySelectorAll( '[data-flow-explore]' ).forEach( el => {
    const lang = el.dataset.lang ?? 'es';
    createRoot( el ).render(
      <ExploreFlow lang={lang} />
    );
  });

  // Chip de urgencia real — [flow_spots_left] (CONTRIBUTING.md § 16.54)
  document.querySelectorAll( '[data-flow-spots-left]' ).forEach( el => {
    const lang = el.dataset.lang ?? 'es';
    createRoot( el ).render(
      <SpotsLeft
        lang={lang}
        tourId={parseInt( el.dataset.tourId, 10 )}
        date={el.dataset.date || ''}
        threshold={parseInt( el.dataset.threshold, 10 ) || 5}
        onlyIfLow={el.dataset.onlyIfLow === 'yes'}
      />
    );
  });

  // Fechas disponibles de un tour, para landings de promoción —
  // [flow_tour_dates] (CONTRIBUTING.md § 16.54)
  document.querySelectorAll( '[data-flow-tour-dates]' ).forEach( el => {
    const lang = el.dataset.lang ?? 'es';
    createRoot( el ).render(
      <TourDates
        lang={lang}
        tourId={parseInt( el.dataset.tourId, 10 )}
        month={el.dataset.month || ''}
        limit={parseInt( el.dataset.limit, 10 ) || 6}
        title={el.dataset.title || ''}
        accent={el.dataset.accent || 'var(--ab-teal, #1D9E75)'}
        ctaEs={el.dataset.ctaEs || 'Reservar'}
        ctaEn={el.dataset.ctaEn || 'Book'}
      />
    );
  });

  // Barra de búsqueda standalone para el hero de un home (Pro Max) —
  // [flow_search_bar] (CONTRIBUTING.md § 16.54)
  document.querySelectorAll( '[data-flow-search-bar]' ).forEach( el => {
    const lang = el.dataset.lang ?? 'en';
    createRoot( el ).render(
      <SearchBarStandalone lang={lang} redirectUrl={el.dataset.redirectUrl || ''} />
    );
  });
});
