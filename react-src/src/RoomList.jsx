import { useState, useEffect } from 'react';
import * as RoomsAPI from './roomsApi.js';

/**
 * [flow_room_list] — grid de habitaciones sin buscador previo (Pro Max,
 * CONTRIBUTING.md § 16). [flow_room_search] siempre arranca pidiendo
 * check-in/check-out; esto es lo que hacía falta para una página de
 * catálogo simple ("las habitaciones que tenemos"), mismo espíritu que
 * [flow_tour_list] para tours. Cada tarjeta linkea a la ficha individual
 * (single-flow_room.php), que es la que arranca el flujo continuo (Flujo B)
 * para esa habitación — "reservar" siempre entra al flujo con upsell, nunca
 * a un formulario aislado (decisión del cliente 2026-08-03).
 *
 * Colores/radio: usa las mismas variables CSS que ya inyecta WidgetTheme
 * (--ab-teal, --ab-radius — Configuración → 🎨 Widget de reserva) en vez de
 * hexadecimales fijos, así que ya hereda la personalización por instalación
 * que el cliente pidió (2026-08-03) sin construir nada nuevo — mismo
 * mecanismo que ya usa el resto del widget de reserva, ver CLAUDE.md § 8.
 */
export default function RoomList( { lang = 'en', columns = 3 } ) {
	const [ rooms, setRooms ]     = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ]     = useState( '' );

	const t = ( es, en ) => ( lang === 'en' ? en : es );

	useEffect( () => {
		RoomsAPI.getRooms()
			.then( data => { setRooms( data ); setLoading( false ); } )
			.catch( e => { setError( e.message ); setLoading( false ); } );
	}, [] );

	const roomUrl = ( room ) => {
		const base = window.amirBooking?.siteUrl ?? '';
		return `${base}/room/${room.slug}/`;
	};

	if ( loading ) return (
		<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '48px 20px', gap: 12, color: '#5a7068', fontFamily: 'inherit' } }>
			<div style={ { width: 22, height: 22, border: '2.5px solid var(--ab-teal-light,#e1f5ee)', borderTopColor: 'var(--ab-teal,#1D9E75)', borderRadius: '50%', animation: 'rl-spin .7s linear infinite', flexShrink: 0 } } />
			<span>{ t( 'Cargando habitaciones…', 'Loading rooms…' ) }</span>
		</div>
	);
	if ( error )   return <div style={ { padding: 24, color: '#e24b4a' } }>⚠ { error }</div>;
	if ( ! rooms.length ) return null;

	return (
		<div className="rl-wrap">
			<style>{`
			@keyframes rl-spin { to { transform:rotate(360deg); } }
			@keyframes rl-fade-up { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
			.rl-wrap { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
			.rl-grid { display:grid; grid-template-columns:repeat(${columns},1fr); gap:22px; }
			@media (max-width:900px) { .rl-grid { grid-template-columns:repeat(2,1fr); } }
			@media (max-width:600px) { .rl-grid { grid-template-columns:1fr; gap:18px; } }
			.rl-card {
				border:1px solid #eef6f2; border-radius:var(--ab-radius,14px); overflow:hidden; background:#fff;
				box-shadow:0 2px 14px rgba(0,0,0,.055); transition:transform .22s ease, box-shadow .22s ease;
				animation:rl-fade-up .45s ease both;
			}
			.rl-card:hover { transform:translateY(-5px); box-shadow:0 14px 36px rgba(0,0,0,.095); }
			.rl-card a { text-decoration:none; color:inherit; display:block; }
			.rl-img-wrap { overflow:hidden; }
			.rl-img { height:170px; background:var(--ab-teal-light,#e1f5ee) center/cover no-repeat; display:flex; align-items:center; justify-content:center; font-size:32px; transition:transform .38s ease; }
			.rl-card:hover .rl-img { transform:scale(1.06); }
			.rl-body { padding:18px 20px 20px; }
			.rl-title { font-weight:800; font-size:16px; margin:0 0 8px; color:#1a2e24; line-height:1.25; }
			.rl-meta { font-size:12px; color:#5a7068; margin:0 0 12px; }
			.rl-price { font-weight:800; color:var(--ab-teal,#1D9E75); font-size:16px; }
			.rl-price small { font-weight:600; color:#5a7068; font-size:11px; }
			.rl-cta { display:inline-block; margin-top:14px; background:var(--ab-teal,#1D9E75); color:#fff; border-radius:var(--ab-radius-sm,8px); padding:10px 20px; font-weight:700; font-size:13px; transition:opacity .15s; }
			.rl-card:hover .rl-cta { opacity:.9; }
			`}</style>
			<div className="rl-grid">
				{ rooms.map( ( room, i ) => (
					<div key={ room.id } className="rl-card" style={ { animationDelay: `${Math.min( i, 6 ) * 0.06}s` } }>
						<a href={ roomUrl( room ) }>
							<div className="rl-img-wrap">
								<div className="rl-img" style={ room.gallery_images?.[0] ? { backgroundImage: `url(${room.gallery_images[0]})` } : {} }>
									{ ! room.gallery_images?.[0] && '🛏' }
								</div>
							</div>
							<div className="rl-body">
								<h3 className="rl-title">{ lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es }</h3>
								<p className="rl-meta">👥 { t( `Hasta ${room.capacity_max} huéspedes`, `Up to ${room.capacity_max} guests` ) }</p>
								<div className="rl-price">{ room.price_per_night?.toLocaleString( 'es-MX' ) } { window.amirBooking?.currency ?? 'MXN' } <small>/ { t( 'noche', 'night' ) }</small></div>
								<span className="rl-cta">{ t( 'Ver y reservar →', 'View & book →' ) }</span>
							</div>
						</a>
					</div>
				) ) }
			</div>
		</div>
	);
}
