import { useState, useEffect } from 'react';
import { getTours }            from './api.js';
import { useT }                from './i18n.js';

/**
 * Grilla de tours para el shortcode [amir_tour_list].
 * Carga los tours desde la API REST y los renderiza en una cuadrícula.
 * El CSS de las tarjetas viene de tour-cards.css (encolado por WordPress).
 */
export default function TourList({ lang = 'es', columns = 3 }) {
  const [ tours,   setTours   ] = useState( [] );
  const [ loading, setLoading ] = useState( true );
  const [ error,   setError   ] = useState( '' );
  const t = useT( lang );

  // Token de partner desde cookie
  const partnerRef = document.cookie
    .split(';').map(c => c.trim())
    .find(c => c.startsWith('amir_partner_ref='))
    ?.split('=')[1] ?? '';

  useEffect( () => {
    getTours( lang )
      .then( data => { setTours( data ); setLoading( false ); } )
      .catch( e => { setError( e.message ); setLoading( false ); } );
  }, [ lang ] );

  if ( loading ) return (
    <div style={{display:'flex',alignItems:'center',justifyContent:'center',padding:'40px',gap:'10px',color:'#5a7068',fontFamily:'inherit'}}>
      <div style={{width:'20px',height:'20px',border:'2px solid #9FE1CB',borderTopColor:'#1D9E75',borderRadius:'50%',animation:'ab-spin .7s linear infinite'}} />
      {t('loading')}
    </div>
  );

  if ( error ) return (
    <div style={{padding:'20px',color:'#e24b4a',fontSize:'14px'}}>
      ⚠ {error}
    </div>
  );

  if ( ! tours.length ) return null;

  const gridStyle = {
    display: 'grid',
    gridTemplateColumns: `repeat(${Math.min(columns, 3)}, 1fr)`,
    gap: '24px',
    listStyle: 'none',
    padding: 0,
    margin: 0,
  };

  const fmtDuration = ( mins ) => {
    if ( ! mins ) return '';
    return mins >= 60 ? `${Math.round(mins/60*10)/10}h` : `${mins}min`;
  };

  const fmtPrice = ( price ) =>
    `$${price.toLocaleString('es-MX', {minimumFractionDigits:0,maximumFractionDigits:0})}`;

  const tourUrl = ( tour ) => {
    // La URL del tour viene del CPT — construimos la URL del archive
    const base = window.amirBooking?.siteUrl ?? '';
    const url  = `${base}/tour/${tour.slug}/`;
    return partnerRef ? url + `?ref=${partnerRef}` : url;
  };

  return (
    <div className="amir-tour-list-react" style={{fontFamily:'inherit'}}>
      <style>{`
        @keyframes ab-spin { to { transform: rotate(360deg); } }
        @media (max-width: 640px) {
          .amir-tour-list-react .amir-grid { grid-template-columns: 1fr !important; }
        }
        @media (min-width: 641px) and (max-width: 900px) {
          .amir-tour-list-react .amir-grid { grid-template-columns: repeat(2,1fr) !important; }
        }
      `}</style>
      <div className="amir-tours-grid amir-grid" style={gridStyle}>
        {tours.map( tour => (
          <article key={tour.id} className="amir-tour-card">
            <div className="amir-tour-card__img-wrap">
              {tour.cover_image ? (
                <a href={tourUrl(tour)}>
                  <img
                    src={tour.cover_image}
                    alt={tour.name}
                    className="amir-tour-card__img"
                    loading="lazy"
                  />
                </a>
              ) : (
                <div style={{width:'100%',aspectRatio:'16/9',background:'#e1f5ee',display:'flex',alignItems:'center',justifyContent:'center',fontSize:'32px'}}>⛵</div>
              )}
              {tour.duration_minutes > 0 && (
                <span className="amir-tour-card__duration-badge">
                  ⏱ {fmtDuration(tour.duration_minutes)}
                </span>
              )}
            </div>

            <div className="amir-tour-card__body">
              <h3 className="amir-tour-card__title">
                <a href={tourUrl(tour)}>{tour.name}</a>
              </h3>

              <div className="amir-tour-card__meta">
                {tour.min_age > 0 && (
                  <span className="amir-tour-card__meta-chip">
                    👤 {lang === 'en' ? 'Age' : 'Edad'} {tour.min_age}+
                  </span>
                )}
                {tour.languages?.length > 0 && (
                  <span className="amir-tour-card__meta-chip">
                    🌐 {tour.languages.join(', ')}
                  </span>
                )}
              </div>

              {/* El precio se carga inline desde el endpoint de precios */}
              <PriceTag tourId={tour.id} lang={lang} fmtPrice={fmtPrice} t={t} />

              <a href={tourUrl(tour)} className="amir-tour-card__cta">
                {lang === 'en' ? 'Book now' : 'Reservar ahora'}
              </a>
            </div>
          </article>
        ))}
      </div>
    </div>
  );
}

/** Carga el precio del tour de forma diferida para no bloquear el render inicial */
function PriceTag({ tourId, lang, fmtPrice, t }) {
  const [ price, setPrice ] = useState( null );

  useEffect( () => {
    const base = window.amirBooking?.apiUrl ?? '/wp-json/amir/v1/';
    fetch( `${base}prices?tour_id=${tourId}` )
      .then( r => r.json() )
      .then( data => {
        const prices = data.prices ?? [];
        const min = Math.min( ...prices.filter(p => p.price_mxn > 0).map(p => p.price_mxn) );
        if ( isFinite(min) ) setPrice( min );
      })
      .catch( () => {} );
  }, [ tourId ] );

  if ( price === null ) return <div style={{height:'32px'}} />;

  return (
    <div className="amir-tour-card__price">
      <span className="amir-tour-card__price-from">{lang === 'en' ? 'From' : 'Desde'}</span>
      <span className="amir-tour-card__price-value">{fmtPrice(price)}</span>
      <span className="amir-tour-card__price-currency">MXN</span>
    </div>
  );
}
