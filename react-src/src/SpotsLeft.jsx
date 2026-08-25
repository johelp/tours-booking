import { useState, useEffect } from 'react';
import * as API from './api.js';

/**
 * [flow_spots_left] — chip de urgencia real (§ 16.54 CONTRIBUTING.md,
 * 2026-08-12). Nunca inventa un número: usa el mismo AvailabilityEngine que
 * el resto del plugin (GET /availability/month, ya existente). Sin `date`,
 * busca la PRÓXIMA fecha con cupo real (mes actual, y el siguiente si hace
 * falta) — no tiene sentido un chip de urgencia para una fecha ya pasada o
 * agotada.
 */
export default function SpotsLeft( { lang = 'es', tourId, date = '', threshold = 5, onlyIfLow = false } ) {
	const [ state, setState ] = useState( { loading: true, date: '', slots: null, error: false } );
	const t = ( es, en ) => ( lang === 'en' ? en : es );

	useEffect( () => {
		let cancelled = false;

		async function resolve() {
			if ( date ) {
				const [ y, m ] = date.split( '-' ).map( Number );
				try {
					const month = await API.getMonthAvailability( tourId, y, m );
					const info = month[ date ];
					if ( ! cancelled ) {
						setState( { loading: false, date, slots: info?.available ? info.slots : 0, error: false } );
					}
				} catch {
					if ( ! cancelled ) setState( { loading: false, date: '', slots: null, error: true } );
				}
				return;
			}

			// Sin fecha puntual: recorrer el mes actual y el siguiente buscando
			// la primera fecha con cupo — dos requests como mucho, cacheados
			// 5 minutos del lado del servidor (AvailabilityController).
			const today = new Date();
			try {
				for ( let offset = 0; offset <= 1; offset++ ) {
					const d = new Date( today.getFullYear(), today.getMonth() + offset, 1 );
					const month = await API.getMonthAvailability( tourId, d.getFullYear(), d.getMonth() + 1 );
					const found = Object.keys( month ).sort().find( key => month[ key ].available && month[ key ].slots > 0 );
					if ( found ) {
						if ( ! cancelled ) setState( { loading: false, date: found, slots: month[ found ].slots, error: false } );
						return;
					}
				}
				if ( ! cancelled ) setState( { loading: false, date: '', slots: 0, error: false } );
			} catch {
				if ( ! cancelled ) setState( { loading: false, date: '', slots: null, error: true } );
			}
		}

		resolve();
		return () => { cancelled = true; };
	}, [ tourId, date ] );

	if ( state.loading || state.error || state.slots === null ) return null;

	const isLow = state.slots > 0 && state.slots <= threshold;
	if ( onlyIfLow && ! isLow ) return null;

	const fmtDate = ( iso ) => new Date( iso + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { month: 'long', day: 'numeric' } );

	let label;
	if ( state.slots <= 0 || ! state.date ) {
		label = t( 'Sin cupo disponible por ahora', 'No availability right now' );
	} else if ( isLow ) {
		label = t( `⚡ Solo ${state.slots} lugares para el ${fmtDate( state.date )}`, `⚡ Only ${state.slots} spots left for ${fmtDate( state.date )}` );
	} else {
		label = t( `${state.slots} lugares disponibles (${fmtDate( state.date )})`, `${state.slots} spots available (${fmtDate( state.date )})` );
	}

	return (
		<span className={ `fsl-chip ${ isLow ? 'low' : '' } ${ state.slots <= 0 ? 'none' : '' }` }>
			<style>{ SPOTS_LEFT_CSS }</style>
			{ label }
		</span>
	);
}

const SPOTS_LEFT_CSS = `
.fsl-chip {
  display:inline-flex; align-items:center; gap:6px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  font-size:13px; font-weight:700; padding:7px 14px; border-radius:20px;
  background:var(--ab-teal-light,#eafaf1); color:var(--ab-teal-dark,#0F6E56);
}
.fsl-chip.low { background:#fff2e0; color:#a15c00; }
.fsl-chip.none { background:#f5f5f5; color:#888; font-weight:600; }
`;
