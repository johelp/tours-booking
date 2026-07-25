import { createRoot } from 'react-dom/client';
import BookingWidget  from './BookingWidget.jsx';
import TourList       from './TourList.jsx';
import WishlistList   from './WishlistList.jsx';
import PayBooking      from './PayBooking.jsx';

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
});
