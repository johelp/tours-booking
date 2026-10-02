import { useState, useEffect } from 'react';
import { getTours } from './api.js';
import { useT }     from './i18n.js';

/**
 * Grilla de tours — [flow_tour_list] (alias legacy: [amir_tour_list])
 *
 * Shortcode atributos:
 *   columns="3"           columnas (1-4)
 *   limit="0"             máximo de tours a mostrar (0 = sin límite)
 *   accent="#1D9E75"      color de acento (precio, chips, CTA)
 *   bg_color="#ffffff"    color de fondo de la tarjeta
 *   text_color="#1a2e24"  color del título
 *   radius="16"           radio de borde en px
 *   image_ratio="4/3"     proporción de la imagen (ej. "16/9", "1/1")
 *   show_excerpt="yes"    mostrar descripción
 *   show_price="yes"      mostrar precio
 *   show_age="yes"        mostrar edad mínima
 *   show_duration="yes"   mostrar duración
 *   show_languages="yes"  mostrar chip de idiomas del tour
 *   show_capacity="yes"   mostrar badge de capacidad máxima
 *   show_free_cancellation="yes"  mostrar chip de cancelación gratis (política de 7+ días, fija para todos los tours)
 *   cta_text_es="..."     texto botón ES
 *   cta_text_en="..."     texto botón EN
 *   ids="1,2,3"           filtrar tours por ID
 *   category="barco"      filtrar por categoría (slug de amir_tour_category)
 *   source="all|own|provider"  "own" = solo tours propios, "provider" = solo de proveedores externos (marketplace)
 *   provider_id="5"        filtrar a un proveedor puntual (implica source="provider")
 */
export default function TourList({ lang = 'es', rootEl = null }) {
  const [ tours,   setTours   ] = useState( [] );
  const [ loading, setLoading ] = useState( true );
  const [ error,   setError   ] = useState( '' );
  const t = useT( lang );

  const cfg = {
    // Bug real reportado 2026-08-12: el generador de shortcode (Personalización)
    // ya ofrece "4" como opción de columnas — acá quedaba topeado a 3 en
    // silencio, sin CSS para .c4 tampoco (ver más abajo).
    columns      : Math.min( 4, Math.max( 1, parseInt( rootEl?.dataset?.columns || '3', 10 ) ) ),
    // Bug real reportado 2026-08-12: el atributo limit ya lo mandaba el
    // shortcode (class-shortcodes.php) y lo ofrecía el generador, pero acá
    // nunca se leía — se mostraban siempre TODOS los tours devueltos por
    // la API, sin importar el límite pedido.
    limit        : parseInt( rootEl?.dataset?.limit, 10 ) || 0,
    // Bug real reportado 2026-08-21: sin accent="..." explícito en el
    // shortcode, esto caía siempre al teal fijo de fábrica — ignoraba por
    // completo el color de marca configurado en Personalización → 🎨 Widget
    // de reserva. --ab-teal es la misma variable que ya usan TourCard/
    // RoomCard (shared.jsx) para lo mismo — WidgetTheme la inyecta global
    // en cualquier página con el bundle del widget cargado. Se lee el
    // valor YA RESUELTO por el navegador (no el string "var(--ab-teal)"
    // literal) porque shade() de acá abajo hace matemática de color sobre
    // un hex real — pasarle un var() como si fuera hex rompería en NaN.
    accent       : rootEl?.dataset?.accent
                     || getComputedStyle( document.documentElement ).getPropertyValue( '--ab-teal' ).trim()
                     || '#1D9E75',
    bgColor      : rootEl?.dataset?.bgColor      || '#ffffff',
    textColor    : rootEl?.dataset?.textColor    || '#1a2e24',
    radius       : parseInt( rootEl?.dataset?.radius, 10 ) || 16,
    imageRatio   : rootEl?.dataset?.imageRatio   || '4/3',
    showExcerpt  : rootEl?.dataset?.showExcerpt  !== 'no',
    showPrice    : rootEl?.dataset?.showPrice    !== 'no',
    showAge      : rootEl?.dataset?.showAge      !== 'no',
    showDuration : rootEl?.dataset?.showDuration !== 'no',
    showLanguages: rootEl?.dataset?.showLanguages !== 'no',
    showCapacity : rootEl?.dataset?.showCapacity !== 'no',
    showFreeCancellation: rootEl?.dataset?.showFreeCancellation !== 'no',
    ctaEs        : rootEl?.dataset?.ctaEs        || 'Reservar ahora',
    ctaEn        : rootEl?.dataset?.ctaEn        || 'Book now',
    ids          : rootEl?.dataset?.ids
      ? rootEl.dataset.ids.split(',').map(Number).filter(Boolean)
      : [],
    category     : rootEl?.dataset?.category || '',
    source       : rootEl?.dataset?.source    || 'all',
    providerId   : parseInt( rootEl?.dataset?.providerId, 10 ) || 0,
  };

  const partnerRef = document.cookie
    .split(';').map( c => c.trim() )
    .find( c => c.startsWith('amir_partner_ref=') )
    ?.split('=')[1] ?? '';

  useEffect( () => {
    getTours( lang, { category: cfg.category, source: cfg.source, providerId: cfg.providerId } )
      .then( data => {
        const filtered = cfg.ids.length
          ? data.filter( tour => cfg.ids.includes( tour.id ) )
          : data;
        setTours( cfg.limit > 0 ? filtered.slice( 0, cfg.limit ) : filtered );
        setLoading( false );
      } )
      .catch( e => { setError( e.message || t('err_generic') ); setLoading( false ); } );
  }, [ lang, cfg.category, cfg.source, cfg.providerId ] );

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

  // Antes: spinner + "Cargando tours…" — un texto plano mientras el resto
  // de la grilla (tarjetas reales) usa animación/diseño propio, así que el
  // salto de "spinner centrado" a "grilla de tarjetas" se sentía como un
  // parpadeo sin relación. Ahora arma tarjetas fantasma con la MISMA forma
  // real (imagen + título + precio + botón), reusando el shimmer que ya
  // existía para el precio individual (.al-price-sk) — pedido explícito del
  // cliente, auditoría de UX 2026-08-24.
  if ( loading ) return (
    <div style={{ fontFamily:'inherit' }}>
      <style>{`
        @keyframes al-spin    { to { transform:rotate(360deg); } }
        @keyframes al-shimmer { 0%,100%{opacity:.6} 50%{opacity:.3} }
        .al-grid { display:grid; gap:26px; }
        @media(min-width:901px)  { .al-grid.c4,.al-grid.c3{grid-template-columns:repeat(3,1fr)} .al-grid.c2{grid-template-columns:repeat(2,1fr)} }
        @media(min-width:1140px) { .al-grid.c4{grid-template-columns:repeat(4,1fr)} }
        @media(min-width:601px) and (max-width:900px) { .al-grid.c4,.al-grid.c3,.al-grid.c2{grid-template-columns:repeat(2,1fr)} }
        @media(max-width:600px) { .al-grid{grid-template-columns:1fr!important;gap:18px} }
        .al-grid.c1 { grid-template-columns:1fr; max-width:500px; margin:0 auto; }
        .al-card-sk { background:#fff; border-radius:${cfg.radius}px; overflow:hidden; border:1px solid #eef6f2; box-shadow:0 2px 14px rgba(0,0,0,.055); }
        .al-img-sk  { width:100%; aspect-ratio:${cfg.imageRatio}; background:#f0f0f0; animation:al-shimmer 1.3s infinite; }
        .al-body-sk { padding:16px 18px 18px; }
        .al-line-sk { height:14px; border-radius:6px; background:#f0f0f0; animation:al-shimmer 1.3s infinite; }
        .al-cta-sk  { height:44px; border-radius:10px; background:#f0f0f0; animation:al-shimmer 1.3s infinite; margin-top:16px; }
      `}</style>
      <div className={`al-grid c${cfg.columns}`}>
        { Array.from( { length: cfg.limit > 0 ? Math.min( cfg.limit, 6 ) : 6 }, ( _, i ) => (
          <div className="al-card-sk" key={i} aria-hidden="true">
            <div className="al-img-sk" />
            <div className="al-body-sk">
              <div className="al-line-sk" style={{ width:'75%', marginBottom:10 }} />
              <div className="al-line-sk" style={{ width:'45%', height:11, marginBottom:16 }} />
              <div className="al-price-sk" />
              <div className="al-cta-sk" />
            </div>
          </div>
        ) ) }
      </div>
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

        /* Grid — .c4 agregado (bug real 2026-08-12): el generador de shortcode
           ya ofrecía "4 columnas" como opción, pero acá no había ni el tope
           (ver cfg.columns) ni la regla CSS para ese caso. */
        .al-grid { display:grid; gap:26px; }
        @media(min-width:901px)  { .al-grid.c4,.al-grid.c3{grid-template-columns:repeat(3,1fr)} .al-grid.c2{grid-template-columns:repeat(2,1fr)} }
        /* .c4 vuelve a pisarse acá, DESPUÉS del bloque de 901px a propósito —
           ambos media queries matchean a la vez desde 1140px en adelante, y en
           CSS gana la regla que viene última en el código entre dos reglas
           con la misma especificidad (no la que tiene el min-width más alto) —
           bug real que se filtró en el primer intento de este mismo fix. */
        @media(min-width:1140px) { .al-grid.c4{grid-template-columns:repeat(4,1fr)} }
        @media(min-width:601px) and (max-width:900px) { .al-grid.c4,.al-grid.c3,.al-grid.c2{grid-template-columns:repeat(2,1fr)} }
        @media(max-width:600px) { .al-grid{grid-template-columns:1fr!important;gap:18px} }
        .al-grid.c1 { grid-template-columns:1fr; max-width:500px; margin:0 auto; }

        /* Card */
        .al-card {
          background:${cfg.bgColor}; border-radius:${cfg.radius}px; overflow:hidden;
          border:1px solid #eef6f2; box-shadow:0 2px 14px rgba(0,0,0,.055);
          display:flex; flex-direction:column;
          transition:transform .22s ease,box-shadow .22s ease;
          animation:al-fade-up .45s ease both;
        }
        .al-card:hover { transform:translateY(-5px); box-shadow:0 14px 36px rgba(0,0,0,.095); }

        /* Imagen */
        .al-img-wrap { position:relative; overflow:hidden; }
        .al-img-wrap a { display:block; }
        .al-img { width:100%; aspect-ratio:${cfg.imageRatio}; object-fit:cover; display:block; transition:transform .38s ease; }
        .al-card:hover .al-img { transform:scale(1.07); }
        .al-img-ph { width:100%; aspect-ratio:${cfg.imageRatio}; display:flex; align-items:center; justify-content:center; font-size:52px; background:${accentLight}; }

        /* Badges */
        .al-badges { position:absolute; top:10px; left:10px; right:10px; display:flex; justify-content:space-between; gap:6px; pointer-events:none; }
        .al-badge { font-size:11px; font-weight:700; padding:4px 11px; border-radius:20px; white-space:nowrap; backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); }
        .al-bdg-dark  { background:rgba(10,20,15,.58); color:#fff; }
        .al-bdg-light { background:rgba(255,255,255,.9); color:#1a2e24; }

        /* Cuerpo */
        .al-body { padding:18px 20px 20px; display:flex; flex-direction:column; flex:1; }
        .al-title { font-size:17px; font-weight:800; color:${cfg.textColor}; margin:0 0 8px; line-height:1.25; }
        .al-title a { color:inherit; text-decoration:none; transition:color .15s; }
        .al-title a:hover { color:${cfg.accent}; }
        .al-excerpt { font-size:13px; color:#556760; line-height:1.6; margin:0 0 14px; flex:1;
          display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

        /* Chips */
        .al-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; }
        .al-chip { font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;
          background:${accentLight}; color:${accentDark}; display:inline-flex; align-items:center; gap:4px; }
        .al-chip-group { background:#f0f4ff; color:#3b4ea6; }
        .al-chip-free { background:#eafaf1; color:var(--ab-teal,#1D9E75); }

        /* Precio */
        .al-price { display:flex; align-items:baseline; gap:4px; margin-bottom:16px; }
        .al-price-from { font-size:11px; color:#7a8f87; font-weight:500; }
        .al-price-val  { font-size:26px; font-weight:900; color:#1a2e24; line-height:1; }
        .al-price-cur  { font-size:12px; color:#7a8f87; font-weight:600; }
        .al-price-sk   { height:30px; width:110px; border-radius:6px; background:#f0f0f0; animation:al-shimmer 1.3s infinite; margin-bottom:16px; }

        /* Botón CTA */
        .al-cta {
          display:block; width:100% !important; margin-top:auto; padding:13px 20px; box-sizing:border-box;
          background-color:${cfg.accent} !important; background-image:none !important; box-shadow:none !important;
          color:#fff!important; text-align:center; border-radius:10px;
          font-size:15px; font-weight:700; text-decoration:none!important; border:none; cursor:pointer;
          transition:background .15s,transform .1s; font-family:inherit;
        }
        .al-cta:hover { background-color:${accentDark} !important; color:#fff!important; }
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
                  { cfg.showCapacity && tour.max_capacity > 0 && (
                    <span className="al-badge al-bdg-light">👥 {t('max_short')} {tour.max_capacity}</span>
                  )}
                </div>
              </div>
            </div>

            {/* Cuerpo */}
            <div className="al-body">
              <h3 className="al-title">
                <a href={tourUrl(tour)}>{tour.name}</a>
              </h3>

              { cfg.showExcerpt && tour.short_description && (
                <p className="al-excerpt">{tour.short_description}</p>
              )}

              <div className="al-chips">
                { cfg.showFreeCancellation && (
                  <span className="al-chip al-chip-free">
                    ✓ {t('free_cancellation')}
                  </span>
                )}
                { cfg.showAge && tour.min_age > 0 && (
                  <span className="al-chip">
                    👤 {t('age_short')} {tour.min_age}+
                  </span>
                )}
                { cfg.showLanguages && tour.languages?.length > 0 && (
                  <span className="al-chip">
                    🌐 {tour.languages.join(' · ')}
                  </span>
                )}
                { tour.price_model === 'group' && (
                  <span className="al-chip al-chip-group">
                    🎯 {t('private_group')}
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
  const t = useT( lang );

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
  const label = t('from_label');

  return (
    <div className="al-price">
      <span className="al-price-from">{label}</span>
      <span className="al-price-val">{fmt(price)}</span>
      <span className="al-price-cur">{window.amirBooking?.currency ?? 'MXN'}</span>
    </div>
  );
}
