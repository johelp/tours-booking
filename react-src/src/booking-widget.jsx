import { createRoot } from 'react-dom/client';
import BookingWidget from './BookingWidget.jsx';
import TourList      from './TourList.jsx';

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
    const lang    = el.dataset.lang    ?? 'es';
    const columns = parseInt( el.dataset.columns ?? '3', 10 );
    createRoot( el ).render(
      <TourList lang={lang} columns={columns} />
    );
  });
});
