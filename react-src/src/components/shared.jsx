import { useState, useEffect } from 'react';

/**
 * Componentes de tarjeta/UI compartidos entre los flujos de descubrimiento
 * de Pro Max — extraídos de DiscoveryFlow.jsx (§ 16.15 CONTRIBUTING.md) para
 * que ExploreFlow.jsx (§ 16.46) no duplique esta lógica. Comportamiento y
 * salida visual sin cambios respecto al original — DiscoveryFlow.jsx ahora
 * importa desde acá en vez de definirlos inline.
 *
 * SHARED_CARD_CSS son las reglas que estos componentes necesitan (mismos
 * selectores df-* de siempre, sin renombrar — cambiar el prefijo hubiera
 * significado tocar también el CSS que queda en DiscoveryFlow.jsx sin
 * ganar nada funcional). Cada flujo que use estos componentes tiene que
 * inyectar este CSS una vez (ver DISCOVERY_CSS en DiscoveryFlow.jsx y
 * EXPLORE_CSS en ExploreFlow.jsx) — inyectarlo desde los dos flujos en la
 * misma página no es un problema: es el mismo texto, así que no hay
 * colisión, solo una regla duplicada sin efecto visible.
 */

// ── Formato de moneda ─────────────────────────────────────────────────────
// Antes cada pantalla tenía su propio formato (widget clásico: "$1,500" sin
// código de moneda en algunos lugares y "$1,500 MXN" en otros; Discovery/
// Explore/RoomSearch: "1500.00 MXN" via .toFixed(2), sin separador de miles
// ni signo $) — mismo cliente viendo dos estilos distintos según qué
// superficie usara. Auditoría de UX pre-empaquetado v5.7.14, CONTRIBUTING.md
// § 16.91. Sin decimales a propósito (mismo criterio que fmtMXN de
// BookingWidget.jsx — los precios de este plugin son pesos enteros, no
// centavos con significado real).
export function fmtMoney( n, currency, lang = 'es' ) {
	const locale = lang === 'en' ? 'en-US' : 'es-MX';
	return `$${ Number( n ).toLocaleString( locale, { minimumFractionDigits: 0, maximumFractionDigits: 0 } ) } ${ currency }`;
}

// ── Contador −/valor/+ ───────────────────────────────────────────────────
export function Stepper( { value, min = 0, max = 99, onChange, ariaLabel } ) {
	return (
		<div className="df-stepper" role="group" aria-label={ ariaLabel }>
			<button type="button" className="df-stepper-btn" onClick={ () => onChange( Math.max( min, value - 1 ) ) }
				disabled={ value <= min } aria-label={ `− ${ ariaLabel ?? '' }` }>−</button>
			<span className="df-stepper-value">{ value }</span>
			<button type="button" className="df-stepper-btn" onClick={ () => onChange( Math.min( max, value + 1 ) ) }
				disabled={ value >= max } aria-label={ `+ ${ ariaLabel ?? '' }` }>+</button>
		</div>
	);
}

// ── Galería de fotos en overlay ──────────────────────────────────────────
export function GalleryLightbox( { images, t, onClose } ) {
	const [ index, setIndex ] = useState( 0 );
	const max = images.length - 1;

	useEffect( () => {
		function onKey( e ) {
			if ( e.key === 'Escape' ) onClose();
			if ( e.key === 'ArrowRight' ) setIndex( i => Math.min( max, i + 1 ) );
			if ( e.key === 'ArrowLeft' ) setIndex( i => Math.max( 0, i - 1 ) );
		}
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ max, onClose ] );

	return (
		<div className="df-lightbox" onClick={ onClose }>
			<button type="button" className="df-lightbox-close" onClick={ onClose } aria-label={ t( 'Cerrar', 'Close' ) }>✕</button>
			<div className="df-lightbox-body" onClick={ e => e.stopPropagation() }>
				{ max > 0 && (
					<button type="button" className="df-lightbox-nav df-lightbox-prev" disabled={ index === 0 }
						onClick={ () => setIndex( i => Math.max( 0, i - 1 ) ) } aria-label={ t( 'Anterior', 'Previous' ) }>‹</button>
				) }
				<img src={ images[ index ] } alt="" className="df-lightbox-img" />
				{ max > 0 && (
					<button type="button" className="df-lightbox-nav df-lightbox-next" disabled={ index === max }
						onClick={ () => setIndex( i => Math.min( max, i + 1 ) ) } aria-label={ t( 'Siguiente', 'Next' ) }>›</button>
				) }
			</div>
			{ max > 0 && <div className="df-lightbox-counter">{ index + 1 } / { images.length }</div> }
		</div>
	);
}

// ── Iconos placeholder para tarjetas sin foto ────────────────────────────
const TICKET_ICON = (
	<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<path d="M4 8a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v2.3a1.4 1.4 0 0 0 0 2.8V16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-2.9a1.4 1.4 0 0 0 0-2.8V8Z" />
		<path d="M9.5 7v10" strokeDasharray="2 2.4" />
	</svg>
);
const BED_ICON = (
	<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<path d="M3 17v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5" />
		<path d="M3 17v2M21 17v2" />
		<path d="M3 12V8a1 1 0 0 1 1-1h5a1 1 0 0 1 1 1v2" />
		<path d="M13 10h6" />
	</svg>
);

// ── Skeleton de grilla mientras carga ─────────────────────────────────────
export function CardSkeleton( { count = 3 } ) {
	return (
		<>
			{ Array.from( { length: count }, ( _, i ) => (
				<div className="df-card df-skeleton" key={ i } aria-hidden="true">
					<div className="df-card-img" />
					<div className="df-card-body">
						<div className="df-skeleton-line" style={ { width: '70%', height: 16 } } />
						<div className="df-skeleton-line" style={ { width: '90%', height: 12, marginTop: 8 } } />
						<div className="df-skeleton-line" style={ { width: '40%', height: 12, marginTop: 6 } } />
					</div>
				</div>
			) ) }
		</>
	);
}

// ── Tarjeta de tour con pills de fechas disponibles ──────────────────────
// showPrice (opcional, default false): agrega la línea "desde $X" arriba
// del título — decisión cerrada con el cliente para ExploreFlow.jsx
// (§ 16.46 CONTRIBUTING.md), a propósito detrás de un flag en vez de
// mostrarse siempre: DiscoveryFlow.jsx no pasa esta prop, así que su
// tarjeta queda sin cambios (el cliente pidió cero diferencias visuales
// ahí en esta ronda).
export function TourCard( { tour, lang, t, currency, onPick, onOpenGallery, showPrice = false } ) {
	return (
		<div className="df-card">
			<div className="df-card-img" onClick={ () => onOpenGallery( tour ) }
				aria-hidden={ tour.cover_image ? undefined : true }>
				{ tour.cover_image
					? <img src={ tour.cover_image } alt={ tour.name } loading="lazy" decoding="async" />
					: TICKET_ICON }
			</div>
			<div className="df-card-body">
				{ showPrice && tour.from_price_mxn != null && (
					<div className="df-card-price">{ t( 'Desde', 'From' ) } { fmtMoney( tour.from_price_mxn, currency, lang ) }</div>
				) }
				<h3 className="df-card-title" onClick={ () => onPick() }>{ tour.name }</h3>
				{ tour.short_description && <p className="df-card-desc">{ tour.short_description }</p> }
				<div className="df-pills">
					{ ( tour.available_dates ?? [] ).slice( 0, 6 ).map( date => (
						<button key={ date } className="df-pill" onClick={ () => onPick( date ) }>
							{ new Date( date + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { month: 'short', day: 'numeric' } ) }
						</button>
					) ) }
					{ ( tour.available_dates ?? [] ).length === 0 && (
						<button className="df-pill" onClick={ () => onPick() }>{ t( 'Ver fechas', 'See dates' ) }</button>
					) }
				</div>
				{ tour.permalink ? (
					<a href={ tour.permalink } target="_blank" rel="noopener noreferrer"
						className="ab-btn ab-btn-ghost ab-btn-sm df-card-detail-btn">
						{ t( 'Ver detalle →', 'View details →' ) }
					</a>
				) : (
					<button type="button" className="ab-btn ab-btn-ghost ab-btn-sm df-card-detail-btn" onClick={ () => onPick() }>
						{ t( 'Ver detalle →', 'View details →' ) }
					</button>
				) }
			</div>
		</div>
	);
}

// ── Tarjeta de habitación ─────────────────────────────────────────────────
export function RoomCard( { room, lang, t, currency, nights, inCart, onAdd, onRemove, onDetail } ) {
	const name = lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es;
	return (
		<div className={ `df-card ${ room.available === false ? 'unavailable' : '' }` }>
			<div className="df-card-img" onClick={ onDetail }
				aria-hidden={ room.gallery_images?.[0] ? undefined : true }>
				{ room.gallery_images?.[0]
					? <img src={ room.gallery_images[0] } alt={ name } loading="lazy" decoding="async" />
					: BED_ICON }
			</div>
			<div className="df-card-body">
				<h3 className="df-card-title" onClick={ onDetail }>{ name }</h3>
				<p className="df-card-desc">👥 { t( `Hasta ${room.capacity_max}`, `Up to ${room.capacity_max}` ) }</p>
				{ room.available === false ? (
					<p style={ { fontSize: 12, color: '#e24b4a' } }>{ room.reason }</p>
				) : (
					<>
						<div className="df-card-price">{ fmtMoney( room.price_per_night * nights, currency, lang ) }</div>
						<div className="df-card-actions">
							<button className="ab-btn ab-btn-ghost ab-btn-sm" onClick={ onDetail }>{ t( 'Ver más', 'Read more' ) }</button>
							{ inCart ? (
								<button className="ab-btn ab-btn-outline ab-btn-sm" onClick={ onRemove }>
									✓ { t( 'Quitar', 'Remove' ) }
								</button>
							) : (
								<button className="ab-btn ab-btn-primary ab-btn-sm" onClick={ onAdd }>
									{ t( '+ Agregar', '+ Add' ) }
								</button>
							) }
						</div>
					</>
				) }
			</div>
		</div>
	);
}

// ── CSS que necesitan los componentes de arriba — inyectar una vez por
// flujo montado (ver comentario de cabecera). Bytes idénticos a las reglas
// que tenía DiscoveryFlow.jsx antes de esta extracción.
export const SHARED_CARD_CSS = `
@keyframes df-fade-up { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
.df-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:16px; margin-bottom:24px; }
.df-skeleton { pointer-events:none; }
.df-skeleton .df-card-img, .df-skeleton-line { background:#eef3f1; animation:df-pulse 1.4s ease-in-out infinite; }
.df-skeleton-line { border-radius:4px; }
@keyframes df-pulse { 0%,100% { opacity:1; } 50% { opacity:.5; } }
.df-card { border:1px solid #eef6f2; border-radius:var(--ab-radius,12px); overflow:hidden; background:#fff; box-shadow:0 2px 14px rgba(0,0,0,.05); transition:transform .22s ease, box-shadow .22s ease; animation:df-fade-up .4s ease both; }
.df-card:hover { transform:translateY(-4px); box-shadow:0 12px 30px rgba(0,0,0,.09); }
.df-card.unavailable { opacity:.5; }
.df-card-img { height:130px; background:var(--ab-teal-light,#e1f5ee); display:flex; align-items:center; justify-content:center; color:var(--ab-teal,#1D9E75); cursor:pointer; overflow:hidden; }
.df-card-img img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .35s ease; }
.df-card:hover .df-card-img img { transform:scale(1.06); }
.df-card-body { padding:16px; }
.df-card-title { font-weight:700; font-size:15px; margin:0 0 6px; color:#1a2e24; cursor:pointer; transition:color .15s ease; }
.df-card:hover .df-card-title { color:var(--ab-teal-dark,#0F6E56); }
.df-card-desc { font-size:12px; color:#5a7068; margin:0 0 10px; }
.df-card-price { font-weight:800; color:var(--ab-teal,#1D9E75); font-size:15px; margin-bottom:8px; }
.df-card-actions { display:flex; gap:8px; }
.df-card-actions .ab-btn { width:auto !important; flex:1; }
.df-pills { display:flex; flex-wrap:wrap; gap:8px; }
.df-pill {
  display:inline-flex; align-items:center; justify-content:center; min-height:36px; box-sizing:border-box;
  width:auto !important; height:auto !important;
  margin:0; border:1px solid var(--ab-teal,#1D9E75);
  background-color:#fff !important; background-image:none !important; box-shadow:none !important;
  color:var(--ab-teal,#1D9E75) !important; border-radius:20px; padding:8px 14px; font-size:13px; font-weight:600;
  font-family:inherit; cursor:pointer; transition:background .15s,color .15s;
  /* Blindaje contra el CSS del theme del sitio — mismo criterio que
     .ab-cal-day (widget.css, bug real 2026-08-21): sin esto, un <button>
     puede heredar el estilo "de sistema" del theme en vez del nuestro.
     Bug real 2026-08-21 (v2): faltaba !important en 'color' acá — un
     theme que fuerza color:#fff con !important en <button> dejaba el
     texto blanco sobre el fondo blanco (invisible salvo en hover, donde
     el fondo pasa a teal y por coincidencia se veía bien). */
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
.df-pill.active, .df-pill:hover { background-color:var(--ab-teal,#1D9E75) !important; color:#fff !important; }
.df-pill:focus-visible { outline:2px solid var(--ab-teal,#1D9E75); outline-offset:2px; }
.df-card-detail-btn { width:auto; margin-top:10px; padding:6px 4px; }
.df-stepper { display:flex; align-items:center; gap:12px; }
.df-stepper-btn {
  width:40px !important; height:40px !important; box-sizing:border-box; min-width:40px; margin:0; padding:0; border-radius:50%;
  border:2px solid var(--ab-teal,#1D9E75) !important; box-shadow:none !important;
  background-color:#fff !important; background-image:none !important;
  color:var(--ab-teal,#1D9E75) !important; font-size:19px; font-weight:700; font-family:inherit; line-height:1;
  cursor:pointer; display:flex; align-items:center; justify-content:center;
  transition:background .15s ease,color .15s ease,transform .1s ease;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
.df-stepper-btn:hover:not(:disabled) { background-color:var(--ab-teal,#1D9E75) !important; color:#fff !important; }
.df-stepper-btn:active:not(:disabled) { transform:scale(.92); }
.df-stepper-btn:disabled { opacity:.35; cursor:not-allowed; }
.df-stepper-value { min-width:24px; text-align:center; font-size:16px; font-weight:700; color:#1a2e24; }
.df-lightbox { position:fixed; inset:0; background:rgba(15,20,18,.92); z-index:100; display:flex; align-items:center; justify-content:center; animation:df-fade-up .2s ease both; }
.df-lightbox-close {
  position:absolute; top:16px; right:16px; width:44px !important; height:44px !important; min-width:0; padding:0 !important; box-sizing:border-box; margin:0; border-radius:50%;
  border:none !important; box-shadow:none !important;
  background-color:rgba(255,255,255,.15) !important; background-image:none !important;
  color:#fff !important; font-size:20px; font-family:inherit; cursor:pointer; display:flex; align-items:center; justify-content:center;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
.df-lightbox-close:hover { background-color:rgba(255,255,255,.28) !important; }
.df-lightbox-body { position:relative; display:flex; align-items:center; justify-content:center; gap:8px; max-width:92vw; max-height:82vh; }
.df-lightbox-img { max-width:min(88vw, 900px); max-height:82vh; border-radius:8px; object-fit:contain; }
.df-lightbox-nav {
  flex-shrink:0; width:44px !important; height:44px !important; min-width:0; padding:0 !important; box-sizing:border-box; margin:0; border-radius:50%;
  border:none !important; box-shadow:none !important;
  background-color:rgba(255,255,255,.15) !important; background-image:none !important;
  color:#fff !important; font-size:26px; font-family:inherit; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
.df-lightbox-nav:hover:not(:disabled) { background-color:rgba(255,255,255,.28) !important; }
.df-lightbox-nav:disabled { opacity:.3; cursor:not-allowed; }
.df-lightbox-counter { position:absolute; bottom:20px; left:50%; transform:translateX(-50%); background:rgba(255,255,255,.15); color:#fff; font-size:13px; font-weight:600; padding:4px 12px; border-radius:20px; }
@media (max-width:640px) {
  .df-lightbox-body { max-width:100vw; flex-direction:column-reverse; gap:16px; }
  .df-lightbox-nav { position:absolute; top:50%; transform:translateY(-50%); }
  .df-lightbox-prev { left:8px; }
  .df-lightbox-next { right:8px; }
}
@media (prefers-reduced-motion:reduce) {
  .df-card, .df-lightbox { animation:none; }
  .df-stepper-btn { transition:none; }
}
`;
