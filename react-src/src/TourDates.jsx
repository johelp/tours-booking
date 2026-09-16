import { useState, useEffect } from 'react';
import * as API from './api.js';

/**
 * [flow_tour_dates] — fechas disponibles de un tour puntual, para landings
 * de promoción tipo "últimas fechas de agosto" (§ 16.54 CONTRIBUTING.md,
 * pedido explícito del cliente 2026-08-12). Cada pill linkea a la ficha del
 * tour con `?date=YYYY-MM-DD` — BookingWidget.jsx/DiscoveryFlow.jsx ya
 * toman ese parámetro para arrancar con la fecha preseleccionada.
 */
export default function TourDates( { lang = 'es', tourId, month = '', limit = 6, title = '', accent = 'var(--ab-teal, #1D9E75)', ctaEs = 'Reservar', ctaEn = 'Book' } ) {
	const [ dates, setDates ] = useState( null ); // null = cargando
	const [ permalink, setPermalink ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const t = ( es, en ) => ( lang === 'en' ? en : es );

	useEffect( () => {
		let cancelled = false;

		async function load() {
			try {
				const [ y, m ] = month
					? month.split( '-' ).map( Number )
					: [ new Date().getFullYear(), new Date().getMonth() + 1 ];

				const [ tour, avail ] = await Promise.all( [
					API.getTour( tourId, lang ),
					API.getMonthAvailability( tourId, y, m ),
				] );
				if ( cancelled ) return;

				const found = Object.keys( avail )
					.sort()
					.filter( d => avail[ d ].available && avail[ d ].slots > 0 )
					.slice( 0, limit );

				setPermalink( tour?.permalink || '' );
				setDates( found );
			} catch ( e ) {
				if ( ! cancelled ) setError( e.message || t( 'No se pudieron cargar las fechas.', "Couldn't load dates." ) );
			}
		}

		load();
		return () => { cancelled = true; };
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tourId, month, limit ] );

	if ( error ) return <p className="ftd-error">⚠ { error }</p>;
	if ( dates === null ) return null; // cargando en silencio, sin layout shift visible
	if ( dates.length === 0 ) return null; // sin fechas próximas — nada que promocionar

	const fmtDate = ( iso ) => new Date( iso + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { weekday: 'short', month: 'short', day: 'numeric' } );

	return (
		<div className="ftd-wrap" style={ { '--ftd-accent': accent } }>
			<style>{ TOUR_DATES_CSS }</style>
			{ title && <p className="ftd-title">{ title }</p> }
			<div className="ftd-pills">
				{ dates.map( d => (
					permalink ? (
						<a key={ d } className="ftd-pill" href={ `${permalink}?date=${d}` }>
							<span className="ftd-pill-date">{ fmtDate( d ) }</span>
							<span className="ftd-pill-cta">{ lang === 'en' ? ctaEn : ctaEs } →</span>
						</a>
					) : (
						<span key={ d } className="ftd-pill ftd-pill--static">
							<span className="ftd-pill-date">{ fmtDate( d ) }</span>
						</span>
					)
				) ) }
			</div>
		</div>
	);
}

const TOUR_DATES_CSS = `
.ftd-wrap { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
.ftd-title { font-size:15px; font-weight:700; color:#1a2e24; margin:0 0 10px; }
.ftd-pills { display:flex; flex-wrap:wrap; gap:10px; }
.ftd-pill {
  display:flex; flex-direction:column; align-items:center; gap:2px; text-decoration:none!important;
  width:auto!important; height:auto!important;
  background-color:transparent!important; background-image:none!important; box-shadow:none!important;
  border:1.5px solid var(--ftd-accent,#1D9E75); border-radius:12px; padding:10px 16px;
  min-width:84px; transition:background .15s,color .15s;
}
a.ftd-pill { cursor:pointer; }
a.ftd-pill:hover { background-color:var(--ftd-accent,#1D9E75)!important; }
a.ftd-pill:hover .ftd-pill-date, a.ftd-pill:hover .ftd-pill-cta { color:#fff!important; }
.ftd-pill-date { font-size:13px; font-weight:700; color:#1a2e24!important; text-transform:capitalize; }
.ftd-pill-cta { font-size:11px; font-weight:600; color:var(--ftd-accent,#1D9E75)!important; }
.ftd-pill--static { cursor:default; opacity:.85; }
.ftd-error { font-size:13px; color:#e24b4a; }
`;
