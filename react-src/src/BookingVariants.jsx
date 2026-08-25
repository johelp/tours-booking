import { useState, useEffect } from 'react';
import * as API from './api.js';
import { useT } from './i18n.js';
import { fmtMoney } from './components/shared.jsx';
import BookingWidget from './BookingWidget.jsx';

/**
 * [flow_booking_variants tour_ids="12,13,14,15"] — CONTRIBUTING.md § 16.93.
 *
 * Un mismo producto vendido como N tours separados (ej. 4 habitaciones de
 * un retiro semanal, cada una su propio price_model='group'/capacidad) —
 * muestra un paso de elección (tarjeta por tour) y, al elegir una, monta
 * BookingWidget para ESE tour_id, sin salir de la página. Los tours listados
 * acá suelen tener hide_from_lists=1 (solo alcanzables desde este selector,
 * nunca desde [flow_tour_list]/catálogos normales) — por eso se traen con
 * GET /tours/{id} uno por uno (API.getTour), no con el listado general, que
 * excluye hide_from_lists=1 a propósito.
 */
export default function BookingVariants( { tourIds, lang: initLang, titleEs, titleEn, columns } ) {
  const [ lang ] = useState( initLang || 'es' );
  const t = useT( lang );
  const currency = window.amirBooking?.currency ?? 'MXN';
  const stripeKey = window.amirBooking?.stripePk ?? '';

  const [ variants, setVariants ] = useState( null ); // null = cargando
  const [ error, setError ]       = useState( '' );
  const [ selectedId, setSelectedId ] = useState( null );

  useEffect( () => {
    Promise.all( tourIds.map( id => API.getTour( id, lang ).catch( () => null ) ) )
      .then( results => setVariants( results.filter( Boolean ) ) )
      .catch( () => setError( t( 'No pudimos cargar las opciones. Intenta de nuevo.', "We couldn't load the options. Please try again." ) ) );
  }, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- tourIds/lang vienen fijos del shortcode, no cambian en la vida del componente

  if ( selectedId ) {
    return (
      <div className="fbv-wrap">
        <style>{ FBV_CSS }</style>
        <button type="button" className="ab-btn ab-btn-ghost fbv-back" onClick={ () => setSelectedId( null ) }>
          ← { t( 'Elegir otra opción', 'Choose another option' ) }
        </button>
        <BookingWidget tourId={ selectedId } lang={ lang } stripeKey={ stripeKey } />
      </div>
    );
  }

  return (
    <div className="fbv-wrap">
      <style>{ FBV_CSS }</style>
      <h3 className="fbv-title">{ titleEs || titleEn ? t( titleEs, titleEn ) : t( 'Elegí tu opción', 'Choose your option' ) }</h3>

      { error && <div className="ab-error-banner">⚠ { error }</div> }

      { variants === null && ! error && (
        <div className={ `fbv-grid fbv-cols-${columns}` }>
          { tourIds.map( id => <div key={ id } className="fbv-card fbv-card-sk" /> ) }
        </div>
      ) }

      { variants !== null && (
        <div className={ `fbv-grid fbv-cols-${columns}` }>
          { variants.map( tour => {
            const cover = ( tour.gallery_images ?? [] )[ 0 ] ?? '';
            return (
              <div key={ tour.id } className="fbv-card" onClick={ () => setSelectedId( tour.id ) }>
                <div className="fbv-card-img">
                  { cover ? <img src={ cover } alt={ tour.name } loading="lazy" decoding="async" /> : '🏷️' }
                </div>
                <div className="fbv-card-body">
                  <h4 className="fbv-card-title">{ tour.name }</h4>
                  { tour.max_capacity > 0 && (
                    <div className="fbv-card-cap">👥 { t( `Hasta ${tour.max_capacity} personas`, `Up to ${tour.max_capacity} people` ) }</div>
                  ) }
                  { tour.from_price_mxn != null && (
                    <div className="fbv-card-price">{ fmtMoney( tour.from_price_mxn, currency, lang ) }</div>
                  ) }
                  <button type="button" className="ab-btn ab-btn-primary fbv-card-btn">{ t( 'Reservar', 'Book' ) } →</button>
                </div>
              </div>
            );
          } ) }
        </div>
      ) }
    </div>
  );
}

const FBV_CSS = `
.fbv-wrap { max-width:900px; margin:0 auto; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#1a2e24; }
.fbv-title { font-size:20px; font-weight:800; margin:0 0 16px; }
.fbv-back { margin-bottom:16px; }
.fbv-grid { display:grid; gap:16px; grid-template-columns:repeat(2,1fr); }
.fbv-cols-1 { grid-template-columns:1fr; }
.fbv-cols-3 { grid-template-columns:repeat(3,1fr); }
.fbv-cols-4 { grid-template-columns:repeat(4,1fr); }
@media(max-width:640px) { .fbv-grid { grid-template-columns:1fr!important; } }
.fbv-card { background:#fff; border:1px solid #e1f5ee; border-radius:var(--ab-radius,12px); overflow:hidden; cursor:pointer; transition:box-shadow .15s,transform .15s; display:flex; flex-direction:column; }
.fbv-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.08); transform:translateY(-2px); }
.fbv-card-sk { aspect-ratio:3/4; background:linear-gradient(90deg,#eef4f1 25%,#f6faf8 37%,#eef4f1 63%); background-size:400% 100%; animation:fbv-shimmer 1.4s ease infinite; cursor:default; }
.fbv-card-sk:hover { box-shadow:none; transform:none; }
@keyframes fbv-shimmer { 0%{background-position:100% 50%} 100%{background-position:0 50%} }
.fbv-card-img { aspect-ratio:4/3; background:#eafbf4; display:flex; align-items:center; justify-content:center; font-size:40px; }
.fbv-card-img img { width:100%; height:100%; object-fit:cover; }
.fbv-card-body { padding:14px 16px 16px; display:flex; flex-direction:column; gap:6px; flex:1; }
.fbv-card-title { font-size:16px; font-weight:800; margin:0; }
.fbv-card-cap { font-size:12.5px; color:#5a7068; }
.fbv-card-price { font-size:19px; font-weight:900; color:var(--ab-teal,#1D9E75); margin-top:2px; }
.fbv-card-btn { margin-top:8px; width:100%; }
`;
