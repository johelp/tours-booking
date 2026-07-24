import { useState, useEffect } from 'react';
import { getTours } from './api.js';
import { useT }     from './i18n.js';

/**
 * Grilla de tours — [amir_tour_list]
 *
 * Shortcode atributos:
 *   columns="3"           columnas (1-3)
 *   accent="#1D9E75"      color de acento hex
 *   show_excerpt="yes"    mostrar descripción
 *   show_price="yes"      mostrar precio
 *   show_age="yes"        mostrar edad mínima
 *   show_duration="yes"   mostrar duración
 *   cta_text_es="..."     texto botón ES
 *   cta_text_en="..."     texto botón EN
 *   ids="1,2,3"           filtrar tours por ID
 */
export default function TourList({ lang = 'es', rootEl = null }) {
  const [ tours,   setTours   ] = useState( [] );
  const [ loading, setLoading ] = useState( true );
  const [ error,   setError   ] = useState( '' );
  const t = useT( lang );

  const cfg = {
    columns      : Math.min( 3, Math.max( 1, parseInt( rootEl?.dataset?.columns || '3', 10 ) ) ),
    accent       : rootEl?.dataset?.accent       || '#1D9E75',
    showExcerpt  : rootEl?.dataset?.showExcerpt  !== 'no',
    showPrice    : rootEl?.dataset?.showPrice    !== 'no',
    showAge      : rootEl?.dataset?.showAge      !== 'no',
    showDuration : rootEl?.dataset?.showDuration !== 'no',
    ctaEs        : rootEl?.dataset?.ctaEs        || 'Reservar ahora',
    ctaEn        : rootEl?.dataset?.ctaEn        || 'Book now',
    ids          : rootEl?.dataset?.ids
      ? rootEl.dataset.ids.split(',').map(Number).filter(Boolean)
      : [],
  };

  const partnerRef = document.cookie
    .split(';').map( c => c.trim() )
    .find( c => c.startsWith('amir_partner_ref=') )
    ?.split('=')[1] ?? '';

  useEffect( () => {
    getTours( lang )
      .then( data => {
        const filtered = cfg.ids.length
          ? data.filter( tour => cfg.ids.includes( tour.id ) )
          : data;
        setTours( filtered );
        setLoading( false );
      } )
      .catch( e => { setError( e.message ); setLoading( false ); } );
  }, [ lang ] );

  const tourUrl = ( tour ) => {
    const base = window.amirBooking?.siteUrl ?? '';
    const url  = `${base}/tour/${tour.slug}/`;
    return partnerRef ? `${url}?ref=${partnerRef}` : url;
  };

  const fmtDuration = ( mins ) => {
    if ( ! mins ) return '';
    const h = Math.floor( mins / 60 );
    const m = mins % 60;
    return h ? ( m ? `${h}h ${m}min` : `${h}h` ) : `${m}min`;
  };

  // Utilidad: oscurecer/aclarar color
  const shade = ( hex, pct ) => {
    const n = parseInt( hex.replace('#',''), 16 );
    const clamp = v => Math.min(255, Math.max(0, v));
    const r = clamp( (n>>16) + pct );
    const g = clamp( ((n>>8)&0xff) + pct );
    const b = clamp( (n&0xff) + pct );
    return '#' + [r,g,b].map(v=>v.toString(16).padStart(2,'0')).join('');
  };

  const accentDark  = shade( cfg.accent, -20 );
  const accentLight = shade( cfg.accent, 90 );

  if ( loading ) return (
    <div style={{ display:'flex', alignItems:'center', justifyContent:'center', padding:'48px 20px', gap:'12px', color:'#5a7068', fontFamily:'inherit' }}>
      <div style={{ width:'22px', height:'22px', border:`2.5px solid ${accentLight}`, borderTopColor:cfg.accent, borderRadius:'50%', animation:'al-spin .7s linear infinite', flexShrink:0 }} />
      <span>{t('loading') || 'Cargando tours…'}</span>
    </div>
  );

  if ( error ) return (
    <div style={{ padding:'20px', color:'#e24b4a', fontFamily:'inherit' }}>⚠ {error}</div>
  );

  if ( ! tours.length ) return null;

  return (
    <div style={{ fontFamily:'inherit', '--al-accent':cfg.accent, '--al-dark':accentDark, '--al-light':accentLight }}>
      <style>{`
        @keyframes al-spin    { to { transform:rotate(360deg); } }
        @keyframes al-fade-up { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
        @keyframes al-shimmer { 0%,100%{opacity:.6} 50%{opacity:.3} }

        .al-root { font-family:inherit; }

        /* Grid */
        .al-grid { display:grid; gap:26px; }
        @media(min-width:901px)  { .al-grid.c3{grid-template-columns:repeat(3,1fr)} .al-grid.c2{grid-template-columns:repeat(2,1fr)} }
        @media(min-width:601px) and (max-width:900px) { .al-grid.c3,.al-grid.c2{grid-template-columns:repeat(2,1fr)} }
        @media(max-width:600px) { .al-grid{grid-template-columns:1fr!important;gap:18px} }
        .al-grid.c1 { grid-template-columns:1fr; max-width:500px; margin:0 auto; }

        /* Card */
        .al-card {
          background:#fff; border-radius:16px; overflow:hidden;
          border:1px solid #eef6f2; box-shadow:0 2px 14px rgba(0,0,0,.055);
          display:flex; flex-direction:column;
          transition:transform .22s ease,box-shadow .22s ease;
          animation:al-fade-up .45s ease both;
        }
        .al-card:hover { transform:translateY(-5px); box-shadow:0 14px 36px rgba(0,0,0,.095); }

        /* Imagen */
        .al-img-wrap { position:relative; overflow:hidden; }
        .al-img-wrap a { display:block; }
        .al-img { width:100%; aspect-ratio:4/3; object-fit:cover; display:block; transition:transform .38s ease; }
        .al-card:hover .al-img { transform:scale(1.07); }
        .al-img-ph { width:100%; aspect-ratio:4/3; display:flex; align-items:center; justify-content:center; font-size:52px; background:${accentLight}; }

        /* Badges */
        .al-badges { position:absolute; top:10px; left:10px; right:10px; display:flex; justify-content:space-between; gap:6px; pointer-events:none; }
        .al-badge { font-size:11px; font-weight:700; padding:4px 11px; border-radius:20px; white-space:nowrap; backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); }
        .al-bdg-dark  { background:rgba(10,20,15,.58); color:#fff; }
        .al-bdg-light { background:rgba(255,255,255,.9); color:#1a2e24; }

        /* Cuerpo */
        .al-body { padding:18px 20px 20px; display:flex; flex-direction:column; flex:1; }
        .al-title { font-size:17px; font-weight:800; color:#1a2e24; margin:0 0 8px; line-height:1.25; }
        .al-title a { color:inherit; text-decoration:none; transition:color .15s; }
        .al-title a:hover { color:${cfg.accent}; }
        .al-excerpt { font-size:13px; color:#556760; line-height:1.6; margin:0 0 14px; flex:1;
          display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

        /* Chips */
        .al-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; }
        .al-chip { font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;
          background:${accentLight}; color:${accentDark}; display:inline-flex; align-items:center; gap:4px; }
        .al-chip-group { background:#f0f4ff; color:#3b4ea6; }

        /* Precio */
        .al-price { display:flex; align-items:baseline; gap:4px; margin-bottom:16px; }
        .al-price-from { font-size:11px; color:#7a8f87; font-weight:500; }
        .al-price-val  { font-size:26px; font-weight:900; color:#1a2e24; line-height:1; }
        .al-price-cur  { font-size:12px; color:#7a8f87; font-weight:600; }
        .al-price-sk   { height:30px; width:110px; border-radius:6px; background:#f0f0f0; animation:al-shimmer 1.3s infinite; margin-bottom:16px; }

        /* Botón CTA */
        .al-cta {
          display:block; width:100%; padding:13px 20px; box-sizing:border-box;
          background:${cfg.accent}; color:#fff!important; text-align:center; border-radius:10px;
          font-size:15px; font-weight:700; text-decoration:none; border:none; cursor:pointer;
          transition:background .15s,transform .1s; margin-top:auto; font-family:inherit;
        }
        .al-cta:hover { background:${accentDark}; color:#fff!important; }
        .al-cta:active { transform:scale(.98); }
      `}</style>

      <div className={`al-grid c${cfg.columns}`}>
        { tours.map( ( tour, i ) => (
          <article
            key={tour.id}
            className="al-card"
            style={{ animationDelay:`${i * 70}ms` }}
          >
            {/* Imagen */}
            <div className="al-img-wrap">
              { tour.cover_image ? (
                <a href={tourUrl(tour)}>
                  <img src={tour.cover_image} alt={tour.name} className="al-img" loading="lazy" />
                </a>
              ) : (
                <div className="al-img-ph">⛵</div>
              )}
              <div className="al-badges">
                <div style={{ display:'flex', gap:'6px' }}>
                  { cfg.showDuration && tour.duration_minutes > 0 && (
                    <span className="al-badge al-bdg-dark">⏱ {fmtDuration(tour.duration_minutes)}</span>
                  )}
                </div>
                <div style={{ display:'flex', gap:'6px' }}>
                  { tour.max_capacity > 0 && (
                    <span className="al-badge al-bdg-light">👥 {lang==='en'?'Max':'Máx'} {tour.max_capacity}</span>
                  )}
                </div>
              </div>
            </div>

            {/* Cuerpo */}
            <div className="al-body">
              <h3 className="al-title">
                <a href={tourUrl(tour)}>{tour.name}</a>
              </h3>

              { cfg.showExcerpt && tour.excerpt && (
                <p className="al-excerpt">{tour.excerpt}</p>
              )}

              <div className="al-chips">
                { cfg.showAge && tour.min_age > 0 && (
                  <span className="al-chip">
                    👤 {lang==='en'?'Age':'Edad'} {tour.min_age}+
                  </span>
                )}
                { tour.languages?.length > 0 && (
                  <span className="al-chip">
                    🌐 {tour.languages.join(' · ')}
                  </span>
                )}
                { tour.price_model === 'group' && (
                  <span className="al-chip al-chip-group">
                    🎯 {lang==='en'?'Private group':'Grupo privado'}
                  </span>
                )}
              </div>

              { cfg.showPrice && (
                <PriceTag tourId={tour.id} lang={lang} priceModel={tour.price_model} />
              )}

              <a href={tourUrl(tour)} className="al-cta">
                {lang === 'en' ? cfg.ctaEn : cfg.ctaEs} →
              </a>
            </div>
          </article>
        ))}
      </div>
    </div>
  );
}

function PriceTag({ tourId, lang, priceModel }) {
  const [ price,  setPrice  ] = useState( null );
  const [ loaded, setLoaded ] = useState( false );

  useEffect( () => {
    const base = window.amirBooking?.apiUrl ?? '/wp-json/amir/v1/';
    fetch( `${base}prices?tour_id=${tourId}` )
      .then( r => r.json() )
      .then( data => {
        const valid = (data.prices ?? []).filter( p => p.price_mxn > 0 );
        if ( valid.length ) {
          setPrice( Math.min( ...valid.map( p => p.price_mxn ) ) );
        }
        setLoaded( true );
      } )
      .catch( () => setLoaded( true ) );
  }, [ tourId ] );

  if ( ! loaded ) return <div className="al-price-sk" />;
  if ( price === null ) return <div style={{ height:'16px' }} />;

  const fmt = n => '$' + n.toLocaleString('es-MX', { minimumFractionDigits:0, maximumFractionDigits:0 });
  const label = priceModel === 'group'
    ? ( lang === 'en' ? 'From' : 'Desde' )
    : ( lang === 'en' ? 'From' : 'Desde' );

  return (
    <div className="al-price">
      <span className="al-price-from">{label}</span>
      <span className="al-price-val">{fmt(price)}</span>
      <span className="al-price-cur">MXN</span>
    </div>
  );
}
