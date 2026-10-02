import { useState } from 'react';
import { Stepper } from './components/shared.jsx';

/**
 * [flow_search_bar] — barra de búsqueda standalone para el hero de un home
 * (§ 16.54 CONTRIBUTING.md, pedido explícito del cliente 2026-08-12,
 * "moderno, funcional" — el patrón de buscador-arriba-de-todo que usa
 * cualquier sitio de viajes). Al enviar, redirige a la página con
 * [flow_explore] pasando fecha/huéspedes por querystring — ExploreFlow.jsx
 * los toma al montar (ver hook useUrlSearchParams ahí) y dispara la
 * búsqueda sola, sin repreguntar nada.
 */
function todayISO() {
	const d = new Date();
	return `${d.getFullYear()}-${String( d.getMonth() + 1 ).padStart( 2, '0' )}-${String( d.getDate() ).padStart( 2, '0' )}`;
}
function addDaysISO( iso, days ) {
	const d = new Date( iso + 'T00:00:00' );
	d.setDate( d.getDate() + days );
	return `${d.getFullYear()}-${String( d.getMonth() + 1 ).padStart( 2, '0' )}-${String( d.getDate() ).padStart( 2, '0' )}`;
}

export default function SearchBarStandalone( { lang = 'en', redirectUrl } ) {
	const [ checkIn, setCheckIn ] = useState( todayISO() );
	const [ checkOut, setCheckOut ] = useState( addDaysISO( todayISO(), 2 ) );
	const [ guests, setGuests ] = useState( 2 );
	const t = ( es, en ) => ( lang === 'en' ? en : es );

	function handleSubmit( e ) {
		e.preventDefault();
		const params = new URLSearchParams( { checkin: checkIn, checkout: checkOut, guests: String( guests ) } );
		const sep = redirectUrl.includes( '?' ) ? '&' : '?';
		window.location.href = `${redirectUrl}${sep}${params.toString()}`;
	}

	return (
		<form className="fsb-bar" onSubmit={ handleSubmit }>
			<style>{ SEARCH_BAR_CSS }</style>
			<div className="fsb-field">
				<label>{ t( 'Check-in', 'Check-in' ) }</label>
				<input type="date" required value={ checkIn } min={ todayISO() } onChange={ e => setCheckIn( e.target.value ) } />
			</div>
			<div className="fsb-field">
				<label>{ t( 'Check-out', 'Check-out' ) }</label>
				<input type="date" required value={ checkOut } min={ checkIn || todayISO() } onChange={ e => setCheckOut( e.target.value ) } />
			</div>
			<div className="fsb-field">
				<label>{ t( 'Personas', 'People' ) }</label>
				<Stepper value={ guests } min={ 1 } onChange={ setGuests } ariaLabel={ t( 'Personas', 'People' ) } />
			</div>
			<button type="submit" className="ab-btn ab-btn-primary fsb-submit">{ t( 'Buscar →', 'Search →' ) }</button>
		</form>
	);
}

const SEARCH_BAR_CSS = `
.fsb-bar {
  display:flex; flex-wrap:wrap; align-items:end; gap:16px;
  background:#fff; border:1px solid #e1f5ee; border-radius:var(--ab-radius,12px);
  padding:18px 20px; box-shadow:0 4px 20px rgba(0,0,0,.06);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}
.fsb-field { display:flex; flex-direction:column; gap:4px; }
.fsb-field label { font-size:12px; font-weight:600; color:#1a2e24; }
.fsb-field input { border:1px solid #c3d9d0; border-radius:var(--ab-radius-sm,8px); padding:9px 12px; font-size:14px; }
.fsb-submit { width:auto; flex-shrink:0; margin-left:auto; }
@media(max-width:640px) {
  .fsb-bar { flex-direction:column; align-items:stretch; }
  .fsb-submit { margin-left:0; width:100%; }
}
`;
