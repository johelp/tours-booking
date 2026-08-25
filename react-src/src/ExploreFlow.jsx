import { useState, useEffect, useRef, useMemo } from 'react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import * as API      from './api.js';
import * as RoomsAPI from './roomsApi.js';
import { useDebouncedValue, useScrollToErrorOnMobile, useScrollToTopOnChange } from './hooks.js';
import { Stepper, GalleryLightbox, CardSkeleton, TourCard, RoomCard, SHARED_CARD_CSS, fmtMoney } from './components/shared.jsx';

/**
 * [flow_explore] — segundo flujo de reserva de Pro Max, búsqueda-primero
 * (§ 16.46 CONTRIBUTING.md), pedido explícito del cliente 2026-08-09 tras
 * encontrar el flujo combinado (DiscoveryFlow.jsx, [flow_discovery]) lento
 * en cada interacción. Ese componente NO se toca en esta ronda — este es un
 * flujo nuevo y aparte, con la dinámica de sitios tipo Booking.com: barra de
 * búsqueda persistente (nunca un paso que se abandona), grilla filtrada
 * 100% en el cliente después de una sola carga, panel deslizable para el
 * detalle (la grilla nunca se desmonta detrás), y carrito/checkout en una
 * barra inferior persistente con los extras siempre visibles adentro.
 *
 * Principio central (ver PROMPT-FLUJO-EXPLORAR.md): "buscar una vez,
 * filtrar en el cliente" — cambiar fechas/huéspedes dispara UN fetch
 * (debounced), tocar un filtro nunca dispara nada (solo array.filter()
 * sobre lo ya cargado).
 */
const SEARCH_WINDOW_PAD_DAYS = 3; // padding alrededor de [checkIn,checkOut] para tours/catalog-window
const MAX_CATALOG_WINDOW_DAYS = 55; // margen bajo el tope de 60 días del backend

// Formatea a partir de los getters LOCALES del Date (nunca toISOString(),
// que convierte a UTC — en timezones adelantadas a UTC eso puede restar un
// día a una fecha construida como medianoche local, bug real encontrado
// probando este flujo: el check-out por defecto salía un día antes de lo
// esperado). nightsBetween() no tiene este problema porque resta dos Date
// construidos con el mismo criterio, así el corrimiento se cancela.
function pad2( n ) {
	return String( n ).padStart( 2, '0' );
}
function toISO( d ) {
	return `${d.getFullYear()}-${pad2( d.getMonth() + 1 )}-${pad2( d.getDate() )}`;
}
function todayISO() {
	return toISO( new Date() );
}
function addDaysISO( iso, days ) {
	const d = new Date( iso + 'T00:00:00' );
	d.setDate( d.getDate() + days );
	return toISO( d );
}
function nightsBetween( a, b ) {
	if ( ! a || ! b ) return 0;
	return Math.round( ( new Date( b + 'T00:00:00' ) - new Date( a + 'T00:00:00' ) ) / 86400000 );
}

// [flow_search_bar] (§ 16.54 CONTRIBUTING.md) redirige acá con
// ?checkin=&checkout=&guests= — se toman una sola vez al montar para
// prellenar la búsqueda, sin repreguntar lo que el visitante ya eligió en
// el hero del home. Validación laxa a propósito (mismo criterio que
// getCouponFromUrl() en marketing.js): si algo no matchea el formato
// esperado, se ignora y cae al default de siempre, nunca rompe el flujo.
function searchParamsFromUrl() {
	if ( typeof window === 'undefined' ) return {};
	const params = new URLSearchParams( window.location.search );
	const out = {};
	const checkin = params.get( 'checkin' );
	const checkout = params.get( 'checkout' );
	const guests = parseInt( params.get( 'guests' ), 10 );
	if ( checkin && /^\d{4}-\d{2}-\d{2}$/.test( checkin ) ) out.checkIn = checkin;
	if ( checkout && /^\d{4}-\d{2}-\d{2}$/.test( checkout ) ) out.checkOut = checkout;
	if ( guests > 0 ) out.guests = guests;
	return out;
}

export default function ExploreFlow( { lang: initLang } ) {
	const [ lang ] = useState( initLang || 'en' );
	const currency = window.amirBooking?.currency ?? 'MXN';
	const t = ( es, en ) => ( lang === 'en' ? en : es );
	const [ urlParams ] = useState( searchParamsFromUrl );

	// ── Barra de búsqueda persistente — nunca un paso que se abandona ──────
	const [ checkIn, setCheckIn ]   = useState( urlParams.checkIn || todayISO() );
	const [ checkOut, setCheckOut ] = useState( urlParams.checkOut || addDaysISO( todayISO(), 2 ) );
	const [ guests, setGuests ]     = useState( urlParams.guests || 2 );
	const [ searchExpanded, setSearchExpanded ] = useState( false ); // solo relevante en contenedor angosto — ver .ex-search-chip

	const [ error, setError ] = useState( '' );

	// ── Catálogo — un solo fetch por cambio real de fechas, filtros aparte ─
	const [ tours, setTours ] = useState( [] );
	const [ toursLoading, setToursLoading ] = useState( false );
	const roomsCatalogRef = useRef( null ); // catálogo completo de habitaciones — no depende de fechas, se cachea tras el primer fetch
	const [ roomsCatalog, setRoomsCatalog ] = useState( null );
	const [ roomAvailability, setRoomAvailability ] = useState( {} );
	const [ roomsLoading, setRoomsLoading ] = useState( false );
	const [ hasSearched, setHasSearched ] = useState( false );

	const tourCacheRef = useRef( {} );
	const searchRequestRef = useRef( 0 );

	const searchKey = `${checkIn}|${checkOut}`;
	const debouncedSearchKey = useDebouncedValue( searchKey, 350 );

	useEffect( () => {
		runSearch();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ debouncedSearchKey ] );

	async function fetchTourDetail( id ) {
		if ( tourCacheRef.current[ id ] ) return tourCacheRef.current[ id ];
		const data = await API.getTour( id, lang );
		tourCacheRef.current[ id ] = data;
		return data;
	}

	async function runSearch() {
		const n = nightsBetween( checkIn, checkOut );
		if ( ! checkIn || ! checkOut || n <= 0 ) {
			setError( t( 'Elegí fechas de check-in y check-out válidas.', 'Pick valid check-in/check-out dates.' ) );
			return;
		}
		setError( '' );
		const requestId = ++searchRequestRef.current;
		setToursLoading( true );
		setRoomsLoading( true );
		try {
			// Habitaciones: catálogo completo se pide UNA sola vez (no depende
			// de fechas), la disponibilidad sí se recalcula por cambio de fecha
			// — pero siempre en 1 solo request batch, nunca uno por habitación.
			let all = roomsCatalogRef.current;
			if ( ! all ) {
				all = await RoomsAPI.getRooms();
				roomsCatalogRef.current = all;
				if ( requestId === searchRequestRef.current ) setRoomsCatalog( all );
			}
			const needsCheck = ( all ?? [] ).filter( r => n >= r.min_nights );

			const base  = new Date( checkIn + 'T00:00:00' );
			const endRef = new Date( checkOut + 'T00:00:00' );
			const from  = new Date( base );   from.setDate( from.getDate() - SEARCH_WINDOW_PAD_DAYS );
			const until = new Date( endRef ); until.setDate( until.getDate() + SEARCH_WINDOW_PAD_DAYS );
			const capped = new Date( Math.min( until.getTime(), from.getTime() + MAX_CATALOG_WINDOW_DAYS * 86400000 ) );

			const [ catalogResults, availResp ] = await Promise.all( [
				RoomsAPI.getCatalogWindow( { from: toISO( from ), until: toISO( capped ), lang, context: 'list' } ),
				needsCheck.length ? RoomsAPI.checkAvailabilityBatch( needsCheck.map( r => r.id ), checkIn, checkOut ) : Promise.resolve( { availability: {} } ),
			] );

			if ( requestId !== searchRequestRef.current ) return; // respuesta obsoleta, ya se disparó otra búsqueda
			setTours( catalogResults ?? [] );
			setRoomAvailability( availResp.availability ?? {} );
			setHasSearched( true );
		} catch ( err ) {
			if ( requestId === searchRequestRef.current ) setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) );
		} finally {
			if ( requestId === searchRequestRef.current ) {
				setToursLoading( false );
				setRoomsLoading( false );
			}
		}
	}

	const nights = nightsBetween( checkIn, checkOut );

	// Habitaciones + su disponibilidad para las fechas actuales — la
	// capacidad nunca descalifica la búsqueda (mismo criterio que
	// DiscoveryFlow.jsx/RoomSearch.jsx: una habitación chica sigue siendo
	// informativa aunque no cubra sola a todo el grupo).
	const roomResults = useMemo( () => {
		if ( ! roomsCatalog ) return [];
		return roomsCatalog.map( r => {
			if ( nights < r.min_nights ) return { ...r, available: false, reason: t( `Mínimo ${r.min_nights} noche(s)`, `Min ${r.min_nights} night(s)` ) };
			const available = !! roomAvailability[ r.id ];
			return { ...r, available, reason: available ? '' : t( 'No disponible en esas fechas', 'Not available for those dates' ) };
		} );
	}, [ roomsCatalog, roomAvailability, nights ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Filtros — SOLO array.filter()/sort() sobre lo ya cargado, nunca un fetch ──
	const [ kindFilter, setKindFilter ]     = useState( 'all' ); // all | tours | rooms
	const [ categoryFilter, setCategoryFilter ] = useState( '' );
	const [ maxPrice, setMaxPrice ]         = useState( '' );

	const categories = useMemo( () => {
		const seen = new Map();
		tours.forEach( tr => ( tr.categories ?? [] ).forEach( c => { if ( c?.slug ) seen.set( c.slug, c.name ?? c.slug ); } ) );
		return Array.from( seen.entries() ).map( ( [ slug, name ] ) => ( { slug, name } ) );
	}, [ tours ] );

	const filteredTours = useMemo( () => {
		const max = maxPrice === '' ? null : Number( maxPrice );
		return tours.filter( tr => {
			if ( categoryFilter && ! ( tr.categories ?? [] ).some( c => c.slug === categoryFilter ) ) return false;
			if ( max !== null && tr.from_price_mxn != null && tr.from_price_mxn > max ) return false;
			return true;
		} );
	}, [ tours, categoryFilter, maxPrice ] );

	const filteredRooms = useMemo( () => {
		const max = maxPrice === '' ? null : Number( maxPrice );
		return roomResults.filter( r => {
			if ( max !== null && ( r.price_per_night * nights ) > max ) return false;
			return true;
		} );
	}, [ roomResults, maxPrice, nights ] );

	const showTours = kindFilter !== 'rooms';
	const showRooms = kindFilter !== 'tours';

	// ── Galería de fotos ─────────────────────────────────────────────────
	const [ galleryImages, setGalleryImages ] = useState( null );
	function openGallery( item ) {
		const images = ( item.gallery_images?.length ? item.gallery_images : ( item.cover_image ? [ item.cover_image ] : [] ) );
		if ( images.length ) setGalleryImages( images );
	}

	// ── Panel de detalle — deslizable, la grilla NUNCA se desmonta detrás ──
	const [ detailTour, setDetailTour ] = useState( null ); // tour completo (fetchTourDetail) + available_dates del catálogo
	const [ detailRoom, setDetailRoom ] = useState( null );

	async function openTourDetail( tourSummary ) {
		setError( '' );
		try {
			const full = await fetchTourDetail( tourSummary.id );
			setDetailTour( { ...full, available_dates: tourSummary.available_dates ?? [] } );
		} catch ( err ) {
			setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) );
		}
	}

	// ── Carrito — mismo shape que DiscoveryFlow.jsx, portado tal cual ──────
	const [ cart, setCart ] = useState( [] );

	// Sugerencia escalonada al agregar el primer ítem (pedido explícito del
	// cliente 2026-08-18): antes, agregar cualquier cosa solo abría el carrito
	// plano con habitaciones/extras mezclados ahí adentro, sin guiar al
	// visitante — acá se dispara UNA vez (hasTriggeredUpsell), no en cada
	// ítem agregado, para no interrumpir a alguien que ya sabe lo que quiere.
	// triggerUpsellIfFirstItem() decide el primer paso relevante y lo
	// encadena a los siguientes desde SuggestRoomsStep/SuggestExtrasStep más
	// abajo — nunca muestra un paso vacío (mismo criterio que DiscoveryFlow).
	const hasTriggeredUpsell = useRef( false );

	function triggerUpsellIfFirstItem( wasEmpty, justAddedType ) {
		if ( ! wasEmpty || hasTriggeredUpsell.current ) return;
		hasTriggeredUpsell.current = true;
		setCartOpen( true );
		const roomsAvailableNow = justAddedType === 'tour' && roomResults.some( r => r.available );
		if ( roomsAvailableNow ) {
			setCheckoutPhase( 'suggest-rooms' );
		} else if ( globalAddons.length > 0 ) {
			setCheckoutPhase( 'suggest-extras' );
		}
	}

	function addTourToCart( { tour, date, scheduleId, scheduleLabel, adults, children, babies, totalMxn, depositPct, depositMxn, requireParticipantNames } ) {
		const wasEmpty = cart.length === 0;
		const uiId = `tour-${tour.id}-${date}-${scheduleId}-${Date.now()}`;
		setCart( c => [ ...c, {
			uiId, type: 'tour', tour_id: tour.id, schedule_id: scheduleId, date,
			adults, children, babies, addons: [], addonsSubtotal: 0,
			name: tour.name,
			sub: `${date}${scheduleLabel ? ' · ' + scheduleLabel : ''} · ${adults + children + babies} pax`,
			total: totalMxn,
			depositPct: depositPct || 0,
			depositMxn: depositMxn || 0,
			// "Requiere nombre de cada integrante" (2026-08-24) — mismo
			// criterio que DiscoveryFlow.jsx/BookingWidget.jsx.
			requireParticipantNames: !! requireParticipantNames,
			participantNames: [],
		} ] );
		setDetailTour( null );
		triggerUpsellIfFirstItem( wasEmpty, 'tour' );
	}

	function addRoomToCart( room ) {
		if ( cart.some( c => c.type === 'room' && c.room_id === room.id ) ) return;
		const wasEmpty = cart.length === 0;
		const uiId = `room-${room.id}-${Date.now()}`;
		const roomGuestsCapped = Math.min( guests, room.capacity_max );
		setCart( c => [ ...c, {
			uiId, type: 'room', room_id: room.id, check_in: checkIn, check_out: checkOut, guests: roomGuestsCapped,
			capacity_max: room.capacity_max,
			name: lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es,
			sub: `${checkIn} → ${checkOut} (${nights} ${t( 'noches', 'nights' )}) · ${t( `hasta ${room.capacity_max} huéspedes`, `up to ${room.capacity_max} guests` )}`,
			total: room.price_per_night * nights,
		} ] );
		setDetailRoom( null );
		triggerUpsellIfFirstItem( wasEmpty, 'room' );
	}

	function removeFromCart( uiId ) {
		setCart( c => c.filter( i => i.uiId !== uiId ) );
	}

	// Ver mismo comentario en DiscoveryFlow.jsx — el carrito puede tener
	// varios tours a la vez, cada uno con su propio conteo de personas.
	function setParticipantName( uiId, index, value ) {
		setCart( c => c.map( item => {
			if ( item.uiId !== uiId ) return item;
			const next = [ ...( item.participantNames || [] ) ];
			next[ index ] = value;
			return { ...item, participantNames: next };
		} ) );
	}

	// Depósito parcial ("Depósito parcial por tour") — mismo criterio que
	// DiscoveryFlow.jsx: el total mostrado es lo que se cobra AHORA, no el
	// precio completo del tour, para que coincida con lo que arma
	// CartController::checkout() en el backend (charge_mxn, no total_mxn).
	const cartTotal = cart.reduce( ( sum, i ) => sum + ( i.depositPct > 0 ? i.depositMxn : i.total ) + ( i.addonsSubtotal || 0 ), 0 );

	// ── Extras — siempre visibles dentro del carrito, sin paso separado ────
	const [ globalAddons, setGlobalAddons ] = useState( [] );
	useEffect( () => {
		RoomsAPI.getGlobalAddons( lang ).then( setGlobalAddons ).catch( () => {} );
	}, [ lang ] );

	const tourItems = cart.filter( c => c.type === 'tour' );
	const [ , forceRender ] = useState( 0 );
	useEffect( () => {
		const missing = tourItems.filter( c => ! tourCacheRef.current[ c.tour_id ] );
		if ( missing.length === 0 ) return;
		Promise.all( missing.map( c => fetchTourDetail( c.tour_id ) ) ).then( () => forceRender( n => n + 1 ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ cart ] );

	function setAddonQty( uiId, addon, qty ) {
		setCart( c => c.map( item => {
			if ( item.uiId !== uiId ) return item;
			const addons = ( item.addons ?? [] ).filter( a => a.id !== addon.id );
			if ( qty > 0 ) addons.push( { id: addon.id, qty } );
			const addonsSubtotal = addons.reduce( ( sum, a ) => {
				const full = tourCacheRef.current[ item.tour_id ];
				const info = full?.addons?.find( x => x.id === a.id );
				return sum + ( info ? info.price_mxn * a.qty : 0 );
			}, 0 );
			return { ...item, addons, addonsSubtotal };
		} ) );
	}

	function setGlobalAddonQty( addon, qty ) {
		const uiId = `addon-${addon.id}`;
		setCart( c => {
			const without = c.filter( i => i.uiId !== uiId );
			if ( qty <= 0 ) return without;
			return [ ...without, {
				uiId, type: 'addon', addon_id: addon.id, qty,
				name: addon.name, sub: qty > 1 ? `× ${qty}` : '',
				total: addon.price_mxn * qty,
			} ];
		} );
	}

	// ── Carrito/checkout — barra inferior persistente ───────────────────────
	const [ cartOpen, setCartOpen ] = useState( false );
	const [ checkoutPhase, setCheckoutPhase ] = useState( null ); // null | 'checkout' | 'payment' | 'confirmation'

	// Autoscroll en mobile (hooks.js) — Explorar es "búsqueda-primero" y no
	// tiene un `step` único como Discovery (CLAUDE.md § Pro Max), así que el
	// disparador de "pantalla nueva" combina las piezas que sí reemplazan la
	// vista completa acá: panel de detalle abierto, drawer del carrito, y
	// fase del checkout.
	const exWrapRef = useRef( null );
	useScrollToErrorOnMobile( exWrapRef );
	useScrollToTopOnChange( exWrapRef, `${detailTour?.id ?? ''}|${detailRoom?.id ?? ''}|${cartOpen}|${checkoutPhase ?? ''}` );
	const [ loading, setLoading ] = useState( false );
	const [ cartGroupId, setCartGroupId ]   = useState( '' );
	const [ clientSecret, setClientSecret ] = useState( '' );
	const [ bookingRefs, setBookingRefs ]   = useState( [] );
	const [ pendingProviderRefs, setPendingProviderRefs ] = useState( [] );
	const [ customerEmail, setCustomerEmail ] = useState( '' );
	const stripeRef = useRef( null );
	const stripeKey = window.amirBooking?.stripePk ?? '';

	useEffect( () => {
		if ( stripeKey && ! stripeRef.current ) stripeRef.current = loadStripe( stripeKey );
	}, [ stripeKey ] );

	// Portado tal cual de DiscoveryFlow.jsx::handleCheckout — mismo mapeo a
	// items[] de POST /cart/checkout, no reinventarlo.
	async function handleCheckout( customer ) {
		setError( '' );
		setLoading( true );
		setCustomerEmail( customer.customer_email );
		try {
			const items = cart.map( c => {
				if ( c.type === 'room' ) {
					return { type: 'room', room_id: c.room_id, check_in: c.check_in, check_out: c.check_out, guests: c.guests, coupon_code: customer.coupon_code ?? '' };
				}
				if ( c.type === 'addon' ) {
					return { type: 'addon', addon_id: c.addon_id, qty: c.qty };
				}
				return { type: 'tour', tour_id: c.tour_id, schedule_id: c.schedule_id, date: c.date, adults: c.adults, children: c.children, babies: c.babies, addons: c.addons ?? [], coupon_code: customer.coupon_code ?? '', participant_names: c.participantNames ?? [] };
			} );
			const resp = await RoomsAPI.cartCheckout( { items, ...customer, lang: customer.lang ?? lang } );
			setCartGroupId( resp.cart_group_id );
			if ( resp.requires_payment && resp.client_secret ) {
				setClientSecret( resp.client_secret );
				setCheckoutPhase( 'payment' );
			} else {
				// Ver mismo comentario en DiscoveryFlow.jsx.
				setBookingRefs( resp.booking_refs ?? [] );
				setPendingProviderRefs( resp.pending_provider_refs ?? [] );
				setCheckoutPhase( 'confirmation' );
			}
		} catch ( err ) {
			setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) );
		} finally {
			setLoading( false );
		}
	}

	function closeCart() {
		setCartOpen( false );
		if ( checkoutPhase !== 'confirmation' ) setCheckoutPhase( null );
	}

	function finishAndReset() {
		setCart( [] );
		setCheckoutPhase( null );
		setCartOpen( false );
		setCartGroupId( '' );
		setBookingRefs( [] );
		setPendingProviderRefs( [] );
	}

	const hasPerTourAddons = tourItems.some( item => ( tourCacheRef.current[ item.tour_id ]?.addons?.length ?? 0 ) > 0 );
	const hasExtras = globalAddons.length > 0 || hasPerTourAddons;

	// Desde el paso "Habitaciones sugeridas" — agregar una no avanza sola
	// (puede querer ver más de una), "Continuar" siempre decide el próximo
	// paso mirando el estado actual, no lo que había al abrir la sugerencia.
	function advanceFromRoomSuggestion() {
		setCheckoutPhase( globalAddons.length > 0 ? 'suggest-extras' : null );
	}
	function advanceFromExtrasSuggestion() {
		setCheckoutPhase( null );
	}

	return (
		<div className="ex-wrap" ref={exWrapRef}>
			<style>{ SHARED_CARD_CSS }</style>
			<style>{ EXPLORE_CSS }</style>

			{ error && <div className="ab-error-banner">⚠ { error }</div> }

			<SearchBar t={ t } checkIn={ checkIn } checkOut={ checkOut } guests={ guests } lang={ lang }
				expanded={ searchExpanded } onToggleExpanded={ () => setSearchExpanded( o => ! o ) }
				onCheckIn={ v => setCheckIn( v ) } onCheckOut={ v => setCheckOut( v ) } onGuests={ setGuests } />

			<FilterBar t={ t } kindFilter={ kindFilter } onKind={ setKindFilter }
				categories={ categories } categoryFilter={ categoryFilter } onCategory={ setCategoryFilter }
				maxPrice={ maxPrice } onMaxPrice={ setMaxPrice } currency={ currency } />

			{ showTours && (
				<section>
					<p className="ex-section-title">🎟 { t( 'Experiencias', 'Experiences' ) }</p>
					<div className="ex-results-grid">
						{ toursLoading && filteredTours.length === 0 && <CardSkeleton /> }
						{ filteredTours.map( tour => (
							<TourCard key={ tour.id } tour={ tour } lang={ lang } t={ t } currency={ currency } showPrice
								onPick={ () => openTourDetail( tour ) } onOpenGallery={ openGallery } />
						) ) }
						{ ! toursLoading && hasSearched && filteredTours.length === 0 && (
							<p className="ex-empty">{ t( 'No hay experiencias disponibles con estos filtros.', 'No experiences match these filters.' ) }</p>
						) }
					</div>
				</section>
			) }

			{ showRooms && (
				<section>
					<p className="ex-section-title">🏡 { t( 'Habitaciones', 'Rooms' ) }</p>
					<div className="ex-results-grid">
						{ roomsLoading && filteredRooms.length === 0 && <CardSkeleton /> }
						{ filteredRooms.map( room => {
							const cartItem = cart.find( c => c.type === 'room' && c.room_id === room.id );
							return (
								<RoomCard key={ room.id } room={ room } lang={ lang } t={ t } currency={ currency } nights={ nights }
									inCart={ !! cartItem }
									onAdd={ () => addRoomToCart( room ) }
									onRemove={ () => removeFromCart( cartItem.uiId ) }
									onDetail={ () => setDetailRoom( room ) } />
							);
						} ) }
						{ ! roomsLoading && hasSearched && filteredRooms.length === 0 && (
							<p className="ex-empty">{ t( 'No hay habitaciones disponibles con estos filtros.', 'No rooms match these filters.' ) }</p>
						) }
					</div>
				</section>
			) }

			{ detailTour && (
				<DetailOverlay onClose={ () => setDetailTour( null ) }>
					<TourDetailPanel tour={ detailTour } t={ t } lang={ lang } currency={ currency }
						onAdd={ addTourToCart } onClose={ () => setDetailTour( null ) } />
				</DetailOverlay>
			) }
			{ detailRoom && (
				<DetailOverlay onClose={ () => setDetailRoom( null ) }>
					<RoomDetailPanel room={ detailRoom } t={ t } lang={ lang } currency={ currency }
						nights={ nights } guests={ guests }
						inCart={ cart.some( c => c.type === 'room' && c.room_id === detailRoom.id ) }
						onAdd={ () => addRoomToCart( detailRoom ) }
						onRemove={ () => { const item = cart.find( c => c.type === 'room' && c.room_id === detailRoom.id ); if ( item ) removeFromCart( item.uiId ); } }
						onClose={ () => setDetailRoom( null ) } />
				</DetailOverlay>
			) }

			{ galleryImages && (
				<GalleryLightbox images={ galleryImages } t={ t } onClose={ () => setGalleryImages( null ) } />
			) }

			{ ( cart.length > 0 || checkoutPhase === 'confirmation' ) && (
				<CartBar t={ t } currency={ currency } cart={ cart } cartTotal={ cartTotal }
					open={ cartOpen } onToggle={ () => setCartOpen( o => ! o ) }
					checkoutPhase={ checkoutPhase }>
					{ checkoutPhase === 'confirmation' ? (
						<ConfirmationPanel t={ t } bookingRefs={ bookingRefs } pendingProviderRefs={ pendingProviderRefs }
							cartGroupId={ cartGroupId } customerEmail={ customerEmail } onDone={ finishAndReset } />
					) : checkoutPhase === 'payment' && clientSecret && stripeRef.current ? (
						<Elements stripe={ stripeRef.current } options={ { clientSecret, locale: lang } }>
							<CartPaymentStep t={ t } cartGroupId={ cartGroupId }
								onSuccess={ ( refs, pendingRefs ) => { setBookingRefs( refs ); setPendingProviderRefs( pendingRefs ?? [] ); setCheckoutPhase( 'confirmation' ); } } />
						</Elements>
					) : checkoutPhase === 'checkout' ? (
						<CheckoutForm t={ t } lang={ lang } currency={ currency } cart={ cart } cartTotal={ cartTotal }
							removeFromCart={ removeFromCart } setParticipantName={ setParticipantName } loading={ loading }
							onBack={ () => setCheckoutPhase( null ) } onSubmit={ handleCheckout } />
					) : checkoutPhase === 'suggest-rooms' ? (
						<SuggestRoomsStep t={ t } lang={ lang } currency={ currency } nights={ nights }
							rooms={ roomResults.filter( r => r.available ) } cart={ cart }
							onAdd={ addRoomToCart } onRemove={ removeFromCart } onContinue={ advanceFromRoomSuggestion } />
					) : checkoutPhase === 'suggest-extras' ? (
						<SuggestExtrasStep t={ t } currency={ currency } globalAddons={ globalAddons } cart={ cart }
							setGlobalAddonQty={ setGlobalAddonQty } onContinue={ advanceFromExtrasSuggestion } />
					) : (
						<CartDrawerBody t={ t } lang={ lang } currency={ currency }
							cart={ cart } removeFromCart={ removeFromCart } cartTotal={ cartTotal }
							hasExtras={ hasExtras } globalAddons={ globalAddons } tourItems={ tourItems }
							tourCacheRef={ tourCacheRef } setAddonQty={ setAddonQty } setGlobalAddonQty={ setGlobalAddonQty }
							onCheckout={ () => setCheckoutPhase( 'checkout' ) } />
					) }
				</CartBar>
			) }
		</div>
	);
}

// ── Barra de búsqueda persistente ───────────────────────────────────────
function SearchBar( { t, checkIn, checkOut, guests, expanded, onToggleExpanded, onCheckIn, onCheckOut, onGuests, lang } ) {
	const fmt = d => new Date( d + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { month: 'short', day: 'numeric' } );
	return (
		<div className={ `ex-searchbar ${ expanded ? 'expanded' : '' }` }>
			<button type="button" className="ex-search-chip" onClick={ onToggleExpanded }>
				📅 { fmt( checkIn ) } → { fmt( checkOut ) } · 👥 { guests } { expanded ? '▴' : '▾' }
			</button>
			<div className="ex-searchbar-fields">
				<div className="ex-field">
					<label>{ t( 'Check-in', 'Check-in' ) }</label>
					<input type="date" value={ checkIn } min={ todayISO() } onChange={ e => onCheckIn( e.target.value ) } />
				</div>
				<div className="ex-field">
					<label>{ t( 'Check-out', 'Check-out' ) }</label>
					<input type="date" value={ checkOut } min={ checkIn || todayISO() } onChange={ e => onCheckOut( e.target.value ) } />
				</div>
				<div className="ex-field">
					<label>{ t( 'Personas', 'People' ) }</label>
					<Stepper value={ guests } min={ 1 } onChange={ onGuests } ariaLabel={ t( 'Personas', 'People' ) } />
				</div>
			</div>
		</div>
	);
}

// ── Filtros — nunca disparan un fetch, solo array.filter()/sort() ──────
function FilterBar( { t, kindFilter, onKind, categories, categoryFilter, onCategory, maxPrice, onMaxPrice, currency } ) {
	return (
		<div className="ex-filters">
			<div className="ex-filter-group">
				{ [ [ 'all', t( 'Todo', 'All' ) ], [ 'tours', t( 'Experiencias', 'Experiences' ) ], [ 'rooms', t( 'Habitaciones', 'Rooms' ) ] ].map( ( [ v, label ] ) => (
					<button key={ v } type="button" className={ `ex-chip ${ kindFilter === v ? 'active' : '' }` } onClick={ () => onKind( v ) }>{ label }</button>
				) ) }
			</div>
			{ kindFilter !== 'rooms' && categories.length > 0 && (
				<div className="ex-filter-group">
					<button type="button" className={ `ex-chip ${ categoryFilter === '' ? 'active' : '' }` } onClick={ () => onCategory( '' ) }>{ t( 'Todas las categorías', 'All categories' ) }</button>
					{ categories.map( c => (
						<button key={ c.slug } type="button" className={ `ex-chip ${ categoryFilter === c.slug ? 'active' : '' }` } onClick={ () => onCategory( c.slug ) }>{ c.name }</button>
					) ) }
				</div>
			) }
			<div className="ex-filter-group">
				<input type="number" min="0" className="ex-price-input" placeholder={ t( `Precio máx. (${currency})`, `Max price (${currency})` ) }
					value={ maxPrice } onChange={ e => onMaxPrice( e.target.value ) } />
			</div>
		</div>
	);
}

// ── Overlay del panel de detalle — backdrop + bottom-sheet/side panel ───
function DetailOverlay( { children, onClose } ) {
	return (
		<div className="ex-backdrop" onClick={ onClose }>
			<div className="ex-panel" onClick={ e => e.stopPropagation() }>
				{ children }
			</div>
		</div>
	);
}

// ── Panel de detalle de tour — fecha (ya viene del catálogo)/horario/personas + quote ──
function TourDetailPanel( { tour, t, lang, currency, onAdd, onClose } ) {
	const [ date, setDate ] = useState( tour.available_dates?.[ 0 ] || '' );
	const [ schedules, setSchedules ] = useState( [] );
	const [ scheduleId, setScheduleId ] = useState( null );
	const [ adults, setAdults ] = useState( 1 );
	const [ children, setChildren ] = useState( 0 );
	const [ babies, setBabies ] = useState( 0 );
	const [ quote, setQuote ] = useState( null );
	const [ quoteError, setQuoteError ] = useState( '' );
	const [ loadingSchedules, setLoadingSchedules ] = useState( false );
	const quoteRequestRef = useRef( 0 );

	useEffect( () => {
		if ( ! date ) return;
		setLoadingSchedules( true );
		setScheduleId( null );
		API.getDaySchedules( tour.id, date ).then( list => {
			setSchedules( list );
			if ( list.length === 1 ) setScheduleId( list[ 0 ].id );
		} ).catch( () => setSchedules( [] ) ).finally( () => setLoadingSchedules( false ) );
	}, [ date, tour.id ] );

	// Debounce + guard de request-id — mismo criterio que TourDetailStep de
	// DiscoveryFlow.jsx (bug real 2026-08-09, ver hooks.js).
	const quoteKey = `${date}|${scheduleId}|${adults}|${children}|${babies}`;
	const debouncedQuoteKey = useDebouncedValue( quoteKey, 350 );

	useEffect( () => {
		if ( ! date || adults + children === 0 ) return;
		const sid = scheduleId ?? 0;
		const requestId = ++quoteRequestRef.current;
		API.getQuote( { tourId: tour.id, scheduleId: sid, date, adults, children, babies, lang } )
			.then( q => { if ( requestId === quoteRequestRef.current ) { setQuote( q ); setQuoteError( '' ); } } )
			// Bug real y grave 2026-08-12 (ver CONTRIBUTING.md § 16.50) — un
			// error de cotización quedaba completamente invisible, el botón
			// de agregar al carrito simplemente nunca aparecía.
			.catch( e => { if ( requestId === quoteRequestRef.current ) { setQuote( null ); setQuoteError( e.message || t( 'No pudimos calcular el precio de este tour.', "We couldn't calculate this tour's price." ) ); } } );
	}, [ debouncedQuoteKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Mínimo de personas POR RESERVA (amir_tours.min_passengers) — mismo
	// campo y criterio que TourDetailStep de DiscoveryFlow.jsx y StepPeople
	// del widget clásico, ver CONTRIBUTING.md § 16.81.
	const minPax = Math.max( 1, parseInt( tour.min_passengers, 10 ) || 1 );
	const belowMinPax = ( adults + children + babies ) < minPax;

	const canAdd = !! date && ( schedules.length === 0 || !! scheduleId ) && adults >= 1 && ! belowMinPax && !! quote;

	function handleAdd() {
		const s = schedules.find( sc => sc.id === scheduleId );
		onAdd( {
			tour, date, scheduleId: scheduleId ?? 0,
			scheduleLabel: s ? ( lang === 'en' ? s.label_en : s.label_es ) : '',
			adults, children, babies,
			totalMxn: quote?.total_mxn ?? 0,
			depositPct: quote?.deposit_pct ?? 0,
			depositMxn: quote?.deposit_mxn ?? 0,
			requireParticipantNames: tour.require_participant_names,
		} );
	}

	return (
		<>
			<div className="ex-panel-header">
				<button type="button" className="ab-btn ab-btn-ghost ex-panel-back" onClick={ onClose }>← { t( 'Volver', 'Back' ) }</button>
			</div>
			<div className="ex-panel-body">
				{ tour.gallery_images?.[ 0 ] && <div className="ex-panel-img" style={ { backgroundImage: `url(${tour.gallery_images[0]})` } } /> }
				<h2 className="ex-panel-title">{ tour.name }</h2>
				{ tour.description && <p className="ex-panel-desc">{ tour.description.replace( /<[^>]+>/g, '' ).slice( 0, 220 ) }</p> }

				<div className="ex-field">
					<label>{ t( 'Fecha', 'Date' ) }</label>
					{ tour.available_dates?.length > 0 ? (
						<div className="df-pills">
							{ tour.available_dates.map( d => (
								<button key={ d } type="button" className={ `df-pill ${ d === date ? 'active' : '' }` } onClick={ () => setDate( d ) }>
									{ new Date( d + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { month: 'short', day: 'numeric' } ) }
								</button>
							) ) }
						</div>
					) : (
						<input type="date" value={ date } min={ todayISO() } onChange={ e => setDate( e.target.value ) } />
					) }
				</div>

				{ date && loadingSchedules && <p className="ex-hint">{ t( 'Cargando horarios…', 'Loading schedules…' ) }</p> }
				{ date && ! loadingSchedules && schedules.length > 1 && (
					<div className="ex-field">
						<label>{ t( 'Horario', 'Schedule' ) }</label>
						{ schedules.map( s => (
							<label key={ s.id } className="ab-policy-check-label" style={ { display: 'flex', gap: 6 } }>
								<input type="radio" name="ex-schedule" disabled={ ! s.available }
									checked={ scheduleId === s.id } onChange={ () => setScheduleId( s.id ) } />
								{ lang === 'en' ? s.label_en : s.label_es } ({ s.time_start }) { ! s.available && `— ${s.reason}` }
							</label>
						) ) }
					</div>
				) }

				<div className="ex-people-row">
					<div className="ex-field">
						<label>{ t( 'Adultos', 'Adults' ) }</label>
						<Stepper value={ adults } min={ 1 } onChange={ setAdults } ariaLabel={ t( 'Adultos', 'Adults' ) } />
					</div>
					{ tour.allow_children !== false && (
						<div className="ex-field">
							<label>{ t( 'Niños', 'Children' ) }</label>
							<Stepper value={ children } min={ 0 } onChange={ setChildren } ariaLabel={ t( 'Niños', 'Children' ) } />
						</div>
					) }
					{ tour.allow_babies !== false && (
						<div className="ex-field">
							<label>{ t( 'Bebés', 'Babies' ) }</label>
							<Stepper value={ babies } min={ 0 } onChange={ setBabies } ariaLabel={ t( 'Bebés', 'Babies' ) } />
						</div>
					) }
				</div>
				{ belowMinPax && <p className="ab-age-notice">👥 { t( `Este tour requiere un mínimo de ${minPax} personas por reserva.`, `This tour requires a minimum of ${minPax} people per booking.` ) }</p> }
				{ quoteError && <div className="ab-error-banner">⚠ { quoteError }</div> }
			</div>

			{ canAdd && (
				<div className="ex-panel-cta">
					<div className="ex-panel-cta-price">
						{ quote.deposit_pct > 0 ? (
							<>
								<span className="ex-panel-cta-price-label">{ t( `Depósito ahora (${quote.deposit_pct}%)`, `Deposit now (${quote.deposit_pct}%)` ) }</span>
								<span className="ex-panel-cta-price-amount">{ quote.deposit_mxn?.toLocaleString( 'es-MX' ) } { currency }</span>
								<span className="ex-panel-cta-price-note">{ t( `Total ${quote.total_mxn?.toLocaleString('es-MX')} ${currency} — resto se cobra después`, `Total ${quote.total_mxn?.toLocaleString('es-MX')} ${currency} — rest charged later` ) }</span>
							</>
						) : (
							<>
								<span className="ex-panel-cta-price-label">{ t( 'Total', 'Total' ) }</span>
								<span className="ex-panel-cta-price-amount">{ quote.total_mxn?.toLocaleString( 'es-MX' ) } { currency }</span>
							</>
						) }
					</div>
					<button className="ab-btn ab-btn-primary" onClick={ handleAdd }>{ t( '+ Agregar al carrito', '+ Add to cart' ) }</button>
				</div>
			) }
		</>
	);
}

// ── Panel de detalle de habitación — usa las fechas globales de la barra de búsqueda ──
function RoomDetailPanel( { room, t, lang, currency, nights, guests, inCart, onAdd, onRemove, onClose } ) {
	const name = lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es;
	const desc = lang === 'en' ? ( room.description_en || room.description_es ) : room.description_es;
	const ready = nights > 0 && room.available !== false;
	return (
		<>
			<div className="ex-panel-header">
				<button type="button" className="ab-btn ab-btn-ghost ex-panel-back" onClick={ onClose }>← { t( 'Volver', 'Back' ) }</button>
			</div>
			<div className="ex-panel-body">
				{ room.gallery_images?.[ 0 ] && <div className="ex-panel-img" style={ { backgroundImage: `url(${room.gallery_images[0]})` } } /> }
				<h2 className="ex-panel-title">{ name }</h2>
				{ desc && <p className="ex-panel-desc">{ desc }</p> }
				{ !! room.amenities?.length && (
					<div className="df-pills">
						{ room.amenities.map( ( a, i ) => <span key={ i } className="ex-amenity">{ a.icon } { lang === 'en' ? ( a.label_en || a.label_es ) : a.label_es }</span> ) }
					</div>
				) }
				<p className="ex-hint">👥 { t( `Capacidad máxima: ${room.capacity_max}`, `Max capacity: ${room.capacity_max}` ) } · { t( `Mínimo ${room.min_nights} noche(s)`, `Min ${room.min_nights} night(s)` ) }</p>
				{ room.available === false && <p className="ab-error-banner">⚠ { room.reason }</p> }
				<p className="ex-hint">{ t( `${nights} noches para ${Math.min( guests, room.capacity_max )} huésped(es)`, `${nights} nights for ${Math.min( guests, room.capacity_max )} guest(s)` ) }</p>
			</div>
			{ ready && (
				<div className="ex-panel-cta">
					<div className="ex-panel-cta-price">
						<span className="ex-panel-cta-price-label">{ t( 'Total', 'Total' ) }</span>
						<span className="ex-panel-cta-price-amount">{ fmtMoney( room.price_per_night * nights, currency ) }</span>
					</div>
					{ inCart ? (
						<button className="ab-btn ab-btn-outline" onClick={ onRemove }>✓ { t( 'Quitar', 'Remove' ) }</button>
					) : (
						<button className="ab-btn ab-btn-primary" onClick={ onAdd }>{ t( '+ Agregar al carrito', '+ Add to cart' ) }</button>
					) }
				</div>
			) }
		</>
	);
}

// ── Barra de carrito inferior persistente ────────────────────────────────
function CartBar( { t, currency, cart, cartTotal, open, onToggle, checkoutPhase, children } ) {
	const forceOpen = checkoutPhase === 'checkout' || checkoutPhase === 'payment' || checkoutPhase === 'confirmation';
	const isOpen = open || forceOpen;
	return (
		<div className="ex-cartbar">
			{ ! forceOpen && (
				<div className="ex-cartbar-row" onClick={ onToggle }>
					<span>🛒 { cart.length } { t( 'ítem(s)', 'item(s)' ) } — <strong>{ fmtMoney( cartTotal, currency ) }</strong></span>
					<span className="ex-cartbar-toggle">{ isOpen ? '▾' : '▴' } { t( 'Ver carrito', 'View cart' ) }</span>
				</div>
			) }
			{ isOpen && <div className="ex-cartbar-drawer">{ children }</div> }
		</div>
	);
}

function CartDrawerBody( { t, lang, currency, cart, removeFromCart, cartTotal, hasExtras, globalAddons, tourItems, tourCacheRef, setAddonQty, setGlobalAddonQty, onCheckout } ) {
	return (
		<div>
			<ul className="ex-cart-list">
				{ cart.map( c => (
					<li key={ c.uiId }>
						<span>{ c.type === 'room' ? '🛏' : c.type === 'addon' ? '🎁' : '🎟' } { c.name } { c.sub ? '— ' + c.sub : '' }</span>
						<span>
							{ c.depositPct > 0
								? t( `Depósito ${fmtMoney(c.depositMxn, currency)} (de ${fmtMoney(c.total, currency)})`, `Deposit ${fmtMoney(c.depositMxn, currency)} (of ${fmtMoney(c.total, currency)})` )
								: fmtMoney( c.total + ( c.addonsSubtotal || 0 ), currency ) }
							<button type="button" className="ex-cart-remove" onClick={ () => removeFromCart( c.uiId ) }>✕</button>
						</span>
					</li>
				) ) }
				{ cart.length === 0 && <li className="ex-empty">{ t( 'Tu carrito está vacío.', 'Your cart is empty.' ) }</li> }
			</ul>

			{ hasExtras && <p className="ex-section-title">{ t( 'Servicios extra', 'Extras' ) }</p> }
			{ globalAddons.length > 0 && (
				<div className="ab-people-list">
					{ globalAddons.map( a => {
						const current = cart.find( c => c.type === 'addon' && c.addon_id === a.id );
						const qty = current?.qty ?? 0;
						return (
							<div key={ a.id } className={ `ab-people-row${ qty > 0 ? ' selected' : '' }` }>
								<div className="ab-people-info"><div className="ab-people-label">{ a.pricing_type === 'digital' ? '📄 ' : '🎁 ' }{ a.name }</div></div>
								<div className="ab-people-price">${ a.price_mxn?.toLocaleString( 'es-MX' ) } { currency }</div>
								{ a.pricing_type === 'per_unit' ? (
									<input type="number" min="0" value={ qty } style={ { width: 60 } }
										onChange={ e => setGlobalAddonQty( a, Math.max( 0, parseInt( e.target.value, 10 ) || 0 ) ) } />
								) : (
									<label style={ { display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' } }>
										<input type="checkbox" checked={ qty > 0 } onChange={ e => setGlobalAddonQty( a, e.target.checked ? 1 : 0 ) } />
									</label>
								) }
							</div>
						);
					} ) }
				</div>
			) }
			{ tourItems.map( item => {
				const full = tourCacheRef.current[ item.tour_id ];
				const addons = full?.addons ?? [];
				if ( addons.length === 0 ) return null;
				return (
					<div key={ item.uiId } style={ { marginTop: 10 } }>
						<p className="ex-hint"><strong>{ item.name }</strong></p>
						<div className="ab-people-list">
							{ addons.map( a => {
								const current = ( item.addons ?? [] ).find( x => x.id === a.id );
								const qty = current?.qty ?? 0;
								return (
									<div key={ a.id } className={ `ab-people-row${ qty > 0 ? ' selected' : '' }` }>
										<div className="ab-people-info"><div className="ab-people-label">🎁 { a.name }</div></div>
										<div className="ab-people-price">${ a.price_mxn?.toLocaleString( 'es-MX' ) } { currency }</div>
										{ a.pricing_type === 'flat' ? (
											<label style={ { display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' } }>
												<input type="checkbox" checked={ qty > 0 } onChange={ e => setAddonQty( item.uiId, a, e.target.checked ? 1 : 0 ) } />
											</label>
										) : (
											<input type="number" min="0" value={ qty } style={ { width: 60 } }
												onChange={ e => setAddonQty( item.uiId, a, Math.max( 0, parseInt( e.target.value, 10 ) || 0 ) ) } />
										) }
									</div>
								);
							} ) }
						</div>
					</div>
				);
			} ) }

			<p className="ab-price-total" style={ { marginTop: 14 } }><span className="ab-price-total-label">{ t( 'Total', 'Total' ) }</span> <span className="ab-price-total-amount">{ fmtMoney( cartTotal, currency ) }</span></p>
			<button type="button" className="ab-btn ab-btn-primary" disabled={ cart.length === 0 } onClick={ onCheckout }>
				{ t( 'Ir a pagar →', 'Go to payment →' ) }
			</button>
		</div>
	);
}

// ── Sugerencia escalonada tras el primer ítem — habitaciones ─────────────
// Reusa RoomCard tal cual (mismo componente que la grilla de búsqueda), solo
// cambia el contenedor: una lista angosta dentro del carrito en vez de la
// grilla ancha de la página. "Continuar" nunca se deshabilita — agregar acá
// es opcional a propósito, mismo criterio que el resto de los upsells del
// plugin (nunca bloquear el checkout por no sumar un extra).
function SuggestRoomsStep( { t, lang, currency, nights, rooms, cart, onAdd, onRemove, onContinue } ) {
	return (
		<div>
			<p className="ex-section-title">🏡 { t( '¿Sumás dónde quedarte?', 'Want to add a place to stay?' ) }</p>
			<p className="ex-hint">{ t( 'Habitaciones disponibles para tus mismas fechas — es opcional.', 'Rooms available for your same dates — totally optional.' ) }</p>
			<div className="ex-suggest-grid">
				{ rooms.slice( 0, 4 ).map( room => {
					const cartItem = cart.find( c => c.type === 'room' && c.room_id === room.id );
					return (
						<RoomCard key={ room.id } room={ room } lang={ lang } t={ t } currency={ currency } nights={ nights }
							inCart={ !! cartItem }
							onAdd={ () => onAdd( room ) }
							onRemove={ () => onRemove( cartItem.uiId ) }
							onDetail={ () => onAdd( room ) } />
					);
				} ) }
			</div>
			<button type="button" className="ab-btn ab-btn-primary" onClick={ onContinue } style={ { marginTop: 14 } }>
				{ t( 'Continuar →', 'Continue →' ) }
			</button>
		</div>
	);
}

// ── Sugerencia escalonada tras el primer ítem — extras ────────────────────
// Mismo bloque de filas que ya usaba CartDrawerBody para globalAddons — acá
// vive como paso propio en vez de mezclado en el carrito plano.
function SuggestExtrasStep( { t, currency, globalAddons, cart, setGlobalAddonQty, onContinue } ) {
	return (
		<div>
			<p className="ex-section-title">🎁 { t( '¿Algo más para tu experiencia?', 'Anything else for your trip?' ) }</p>
			<div className="ab-people-list">
				{ globalAddons.map( a => {
					const current = cart.find( c => c.type === 'addon' && c.addon_id === a.id );
					const qty = current?.qty ?? 0;
					return (
						<div key={ a.id } className={ `ab-people-row${ qty > 0 ? ' selected' : '' }` }>
							<div className="ab-people-info"><div className="ab-people-label">{ a.pricing_type === 'digital' ? '📄 ' : '🎁 ' }{ a.name }</div></div>
							<div className="ab-people-price">${ a.price_mxn?.toLocaleString( 'es-MX' ) } { currency }</div>
							{ a.pricing_type === 'per_unit' ? (
								<input type="number" min="0" value={ qty } style={ { width: 60 } }
									onChange={ e => setGlobalAddonQty( a, Math.max( 0, parseInt( e.target.value, 10 ) || 0 ) ) } />
							) : (
								<label style={ { display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' } }>
									<input type="checkbox" checked={ qty > 0 } onChange={ e => setGlobalAddonQty( a, e.target.checked ? 1 : 0 ) } />
								</label>
							) }
						</div>
					);
				} ) }
			</div>
			<button type="button" className="ab-btn ab-btn-primary" onClick={ onContinue } style={ { marginTop: 14 } }>
				{ t( 'Continuar →', 'Continue →' ) }
			</button>
		</div>
	);
}

// ── Checkout (datos del cliente + resumen) ───────────────────────────────
function CheckoutForm( { t, lang, currency, cart, cartTotal, removeFromCart, setParticipantName, onSubmit, onBack, loading } ) {
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );
	const [ couponCode, setCouponCode ] = useState( '' );
	const [ policyAccepted, setPolicyAccepted ] = useState( false );
	const [ termsAccepted, setTermsAccepted ] = useState( false );
	const [ namesError, setNamesError ] = useState( '' );
	// Preferencia de idioma para el email de confirmación — mismo fix que
	// DiscoveryFlow.jsx, ver CONTRIBUTING.md § 16.91.
	const [ emailLang, setEmailLang ] = useState( lang ?? 'es' );

	// "Requiere nombre de cada integrante" — ver mismo bloque en DiscoveryFlow.jsx.
	const itemsNeedingNames = cart.filter( c => c.type === 'tour' && c.requireParticipantNames );
	const namesComplete = itemsNeedingNames.every( c => {
		const needed = c.adults + c.children;
		const filled = ( c.participantNames || [] ).slice( 0, needed ).filter( n => ( n ?? '' ).trim() ).length;
		return filled >= needed;
	} );

	function handleSubmit( e ) {
		e.preventDefault();
		if ( ! namesComplete ) {
			setNamesError( t( 'Completá el nombre de cada integrante para continuar.', "Fill in every participant's name to continue." ) );
			return;
		}
		onSubmit( { customer_name: name, customer_email: email, customer_phone: phone, coupon_code: couponCode.trim(), policy_accepted: policyAccepted, terms_accepted: termsAccepted, lang: emailLang } );
	}

	return (
		<form onSubmit={ handleSubmit }>
			<ul className="ex-cart-list">
				{ cart.map( c => (
					<li key={ c.uiId }>
						<span>{ c.type === 'room' ? '🛏' : c.type === 'addon' ? '🎁' : '🎟' } { c.name } { c.sub ? '— ' + c.sub : '' }</span>
						<span>
							{ c.depositPct > 0
								? t( `Depósito ${fmtMoney(c.depositMxn, currency)} (de ${fmtMoney(c.total, currency)})`, `Deposit ${fmtMoney(c.depositMxn, currency)} (of ${fmtMoney(c.total, currency)})` )
								: fmtMoney( c.total + ( c.addonsSubtotal || 0 ), currency ) }
							<button type="button" className="ex-cart-remove" onClick={ () => removeFromCart( c.uiId ) }>✕</button>
						</span>
					</li>
				) ) }
			</ul>
			<p className="ab-price-total"><span className="ab-price-total-label">{ t( 'A pagar ahora', 'Due now' ) }</span> <span className="ab-price-total-amount">{ fmtMoney( cartTotal, currency ) }</span></p>

			{ itemsNeedingNames.map( item => {
				const needed = item.adults + item.children;
				return (
					<div className="ab-form-group" key={ item.uiId }>
						<label className="ab-label">{ t( `Nombre de cada integrante — ${item.name}`, `Name of each participant — ${item.name}` ) }</label>
						<p style={ { fontSize: 11, color: '#5a7068', margin: '0 0 8px' } }>{ t( 'Este tour requiere el nombre completo de cada persona, no solo la cantidad.', 'This tour requires the full name of every participant, not just the headcount.' ) }</p>
						{ Array.from( { length: needed }, ( _, i ) => (
							<input
								key={ i }
								type="text"
								className="ab-input"
								style={ { marginBottom: 8 } }
								placeholder={ t( `Persona ${i + 1}`, `Person ${i + 1}` ) }
								value={ ( item.participantNames || [] )[ i ] ?? '' }
								onChange={ e => { setParticipantName( item.uiId, i, e.target.value ); setNamesError( '' ); } }
							/>
						) ) }
					</div>
				);
			} ) }
			{ namesError && <p className="ab-field-error">{ namesError }</p> }

			<div className="rs-checkout-field">
				<label>{ t( 'Nombre completo', 'Full name' ) }</label>
				<input type="text" required value={ name } onChange={ e => setName( e.target.value ) } />
			</div>
			<div className="rs-checkout-field">
				<label>Email</label>
				<input type="email" required value={ email } onChange={ e => setEmail( e.target.value ) } />
			</div>
			<div className="rs-checkout-field">
				<label>{ t( 'Teléfono', 'Phone' ) }</label>
				<input type="tel" value={ phone } onChange={ e => setPhone( e.target.value ) } />
			</div>
			<div className="ab-form-group">
				<label className="ab-label">{ t( 'Idioma para tu email de confirmación', 'Language for your confirmation email' ) }</label>
				<div className="ab-lang-toggle">
					{ ( window.amirBooking?.activeLanguages ?? [ 'es', 'en' ] ).map( code => (
						<button key={ code } type="button"
							className={ `ab-lang-btn${ emailLang === code ? ' active' : '' }` }
							onClick={ () => setEmailLang( code ) }
						>{ code === 'es' ? t( 'Español', 'Spanish' ) : code === 'en' ? t( 'English', 'English' ) : code.toUpperCase() }</button>
					) ) }
				</div>
			</div>
			<div className="rs-checkout-field">
				<label>{ t( 'Código de cupón (opcional)', 'Coupon code (optional)' ) }</label>
				<input type="text" value={ couponCode } onChange={ e => setCouponCode( e.target.value.toUpperCase() ) } placeholder={ t( 'Ej: VERANO10', 'Ex: SUMMER10' ) } />
			</div>

			{/* Bug real reportado por el cliente: las dos casillas aparecían
				una debajo de la otra sin ningún texto de política/términos
				arriba — "no enlazan a ningún contenido". Mismo fix que
				BookingWidget.jsx/DiscoveryFlow.jsx: mostrar siempre el texto
				real (configurado en Configuración) o, si no hay nada
				cargado, un texto por defecto — nunca una casilla suelta. */}
			<div className="ab-policy-box">
				<div className="ab-policy-title">📋 { t( 'Política de cancelación', 'Cancellation policy' ) }</div>
				{ ( () => {
					const custom = t( window.amirBooking?.policyTextEs, window.amirBooking?.policyTextEn );
					const lines = custom ? custom.split( '\n' ).filter( Boolean ) : [
						t( '✓ 7+ días antes: reembolso completo', '✓ 7+ days before: full refund' ),
						t( '▸ 3–6 días antes: reembolso del 50 %', '▸ 3–6 days before: 50 % refund' ),
						t( '✕ Menos de 3 días: sin reembolso', '✕ Less than 3 days: no refund' ),
					];
					return lines.map( ( line, i ) => <div className="ab-policy-line" key={ i }>{ line }</div> );
				} )() }
			</div>
			<label className="ab-policy-check-label">
				<input type="checkbox" checked={ policyAccepted } onChange={ e => setPolicyAccepted( e.target.checked ) } />
				{ t( 'He leído y acepto la política de cancelación', 'I have read and accept the cancellation policy' ) }
			</label>

			<div className="ab-policy-box">
				<div className="ab-policy-title">📜 { t( 'Términos y condiciones', 'Terms and conditions' ) }</div>
				{ ( () => {
					const custom = t( window.amirBooking?.termsTextEs, window.amirBooking?.termsTextEn );
					const lines = custom ? custom.split( '\n' ).filter( Boolean ) : [
						t( 'Al reservar, aceptás las condiciones de uso y venta de este sitio — datos personales tratados según la política de privacidad del operador.',
						   'By booking, you accept this site\'s terms of use and sale — personal data is handled according to the operator\'s privacy policy.' ),
					];
					return lines.map( ( line, i ) => <div className="ab-policy-line" key={ i }>{ line }</div> );
				} )() }
			</div>
			<label className="ab-policy-check-label">
				<input type="checkbox" checked={ termsAccepted } onChange={ e => setTermsAccepted( e.target.checked ) } />
				{ t( 'He leído y acepto los términos y condiciones', 'I have read and accept the terms and conditions' ) }
			</label>

			<div className="ab-btn-row" style={ { marginTop: 16 } }>
				<button type="button" className="ab-btn ab-btn-ghost" onClick={ onBack } disabled={ loading }>← { t( 'Volver', 'Back' ) }</button>
				<button type="submit" className="ab-btn ab-btn-primary" disabled={ loading || ! policyAccepted || ! termsAccepted || cart.length === 0 }>
					{ loading ? t( 'Procesando…', 'Processing…' ) : t( 'Ir a pagar', 'Go to payment' ) }
				</button>
			</div>
		</form>
	);
}

/** Paso de pago del carrito — idéntico al de DiscoveryFlow.jsx/RoomSearch.jsx, mismo endpoint. */
function CartPaymentStep( { t, cartGroupId, onSuccess } ) {
	const stripe = useStripe();
	const elements = useElements();
	const [ processing, setProcessing ] = useState( false );
	const [ error, setError ] = useState( '' );

	async function handlePay() {
		if ( ! stripe || ! elements ) return;
		setProcessing( true );
		setError( '' );

		const { error: stripeError, paymentIntent } = await stripe.confirmPayment( { elements, redirect: 'if_required' } );

		if ( stripeError ) {
			setError( stripeError.message || t( 'Error al procesar el pago.', 'Payment error.' ) );
			setProcessing( false );
			return;
		}

		if ( paymentIntent?.status === 'succeeded' ) {
			try {
				const data = await RoomsAPI.cartConfirmPayment( cartGroupId, paymentIntent.id );
				onSuccess( data.booking_refs ?? [], data.pending_provider_refs ?? [] );
			} catch {
				onSuccess( [], [] );
			}
		}
	}

	return (
		<div>
			<p className="ex-section-title">{ t( 'Pago', 'Payment' ) }</p>
			<div className="ab-stripe-wrap">
				<PaymentElement options={ { layout: 'tabs' } } />
			</div>
			{ error && <div className="ab-error-banner">⚠ { error }</div> }
			<div className="ab-btn-row">
				<button className="ab-btn ab-btn-primary" onClick={ handlePay } disabled={ processing || ! stripe }>
					{ processing ? t( 'Procesando…', 'Processing…' ) : t( 'Pagar', 'Pay' ) }
				</button>
			</div>
		</div>
	);
}

function ConfirmationPanel( { t, bookingRefs, pendingProviderRefs, cartGroupId, customerEmail, onDone } ) {
	return (
		<div style={ { textAlign: 'center' } }>
			<div className="ab-confirm-icon">✅</div>
			<h2 className="ab-confirm-title">
				{ bookingRefs.length > 0
					? t( '¡Reserva confirmada!', 'Booking confirmed!' )
					: t( '¡Reserva recibida!', 'Booking received!' ) }
			</h2>
			{ bookingRefs.length > 0 && (
				<p>{ t( 'Referencia(s)', 'Reference(s)' ) }: <strong>{ bookingRefs.join( ', ' ) }</strong></p>
			) }
			{ pendingProviderRefs.length > 0 && (
				<p className="ab-confirm-sub" style={ { color: '#BA7517' } }>
					{ t(
						`Tu pago se procesó — estamos confirmando disponibilidad con el operador local para ${pendingProviderRefs.join( ', ' )}. Te avisamos por email en cuanto quede confirmada.`,
						`Your payment went through — we're confirming availability with the local operator for ${pendingProviderRefs.join( ', ' )}. We'll email you as soon as it's confirmed.`
					) }
				</p>
			) }
			<p className="ab-confirm-sub">{ t( 'Te enviamos un email con los detalles.', 'We sent you an email with the details.' ) }</p>
			{ cartGroupId && (
				<a className="ab-btn ab-btn-primary" href={ RoomsAPI.cartVoucherUrl( cartGroupId, customerEmail ) } target="_blank" rel="noopener noreferrer">
					{ t( '📄 Descargar voucher', '📄 Download voucher' ) }
				</a>
			) }
			<div style={ { marginTop: 12 } }>
				<button type="button" className="ab-btn ab-btn-ghost" onClick={ onDone }>{ t( 'Seguir explorando', 'Keep exploring' ) }</button>
			</div>
		</div>
	);
}

const EXPLORE_CSS = `
.ex-wrap { container-type:inline-size; max-width:1100px; margin:0 auto; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#1a2e24; padding-bottom:70px; }

/* Grilla de resultados (tours/habitaciones) — pedido explícito del cliente
   2026-08-20: topear a 3 columnas máximo. Antes reusaba .df-grid
   (compartida con DiscoveryFlow.jsx), que con auto-fill/minmax podía abrir
   4+ columnas en pantallas anchas — acá se resuelve aparte, sin tocar
   DiscoveryFlow, con pasos fijos en vez de auto-fill para que 3 sea
   siempre el techo real, no una coincidencia de ancho. */
.ex-results-grid { display:grid; grid-template-columns:1fr; gap:16px; margin-bottom:24px; }
@container (min-width:560px) {
  .ex-results-grid { grid-template-columns:repeat(2,1fr); }
}
@container (min-width:840px) {
  .ex-results-grid { grid-template-columns:repeat(3,1fr); }
}
.ex-searchbar { position:sticky; top:0; z-index:30; background:#fff; border:1px solid #e1f5ee; border-radius:var(--ab-radius,12px); padding:10px 14px; margin-bottom:16px; box-shadow:0 2px 10px rgba(0,0,0,.04); }
.ex-search-chip {
  display:flex; width:100% !important; align-items:center; justify-content:space-between;
  background-color:transparent !important; background-image:none !important; box-shadow:none !important;
  border:none; margin:0; font-size:14px; font-weight:600; font-family:inherit; color:#1a2e24 !important; padding:6px 2px; cursor:pointer;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
.ex-searchbar-fields { display:none; gap:14px; flex-wrap:wrap; align-items:end; padding-top:10px; }
.ex-searchbar.expanded .ex-searchbar-fields { display:flex; }
.ex-field { display:flex; flex-direction:column; gap:4px; }
.ex-field label { font-size:12px; font-weight:600; color:#1a2e24; }
.ex-field input[type=date] { border:1px solid #c3d9d0; border-radius:var(--ab-radius-sm,8px); padding:9px 12px; font-size:14px; }
.ex-filters { display:flex; flex-direction:column; gap:10px; margin-bottom:18px; }
.ex-filter-group { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.ex-chip {
  width:auto !important; height:auto !important;
  border:1px solid #c3d9d0 !important; box-shadow:none !important;
  background-color:#fff !important; background-image:none !important;
  color:#1a2e24 !important; border-radius:20px; margin:0; padding:7px 14px; font-size:13px; font-weight:600; font-family:inherit;
  cursor:pointer; appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
.ex-chip.active { background-color:var(--ab-teal,#1D9E75) !important; border-color:var(--ab-teal,#1D9E75); color:#fff !important; }
.ex-price-input { border:1px solid #c3d9d0; border-radius:var(--ab-radius-sm,8px); padding:8px 12px; font-size:13px; width:160px; max-width:100%; }
.ex-section-title { font-size:calc(17px * var(--ab-font-scale,1)); font-weight:700; color:#1a2e24; margin:18px 0 12px; }
.ex-empty { font-size:13px; color:#5a7068; grid-column:1/-1; }
.ex-hint { font-size:13px; color:#5a7068; margin:6px 0; }
.ex-amenity { display:inline-flex; background:var(--ab-teal-light,#f0f9f5); border-radius:8px; padding:6px 10px; font-size:12px; color:#1a2e24; }
/* Panel deslizable — bottom-sheet en mobile/contenedor angosto, panel
   lateral cuando el contenedor lo permite (container query, no @media —
   mismo criterio que DiscoveryFlow.jsx, § 16.36 CONTRIBUTING.md: este
   widget puede vivir en un sidebar angosto con la pantalla en desktop). La
   grilla NUNCA se desmonta detrás — es un overlay fixed sobre todo el flujo. */
.ex-backdrop { position:fixed; inset:0; background:rgba(15,20,18,.45); z-index:60; display:flex; align-items:flex-end; justify-content:center; animation:df-fade-up .2s ease both; }
.ex-panel { background:#fff; width:100%; max-width:640px; max-height:88vh; overflow-y:auto; border-radius:16px 16px 0 0; box-shadow:0 -8px 30px rgba(0,0,0,.18); display:flex; flex-direction:column; }
.ex-panel-header { padding:14px 18px 0; }
.ex-panel-back { width:auto !important; display:inline-flex !important; padding:8px 6px !important; }
.ex-panel-body { padding:0 18px 24px; }
.ex-panel-body.has-cta { padding-bottom:100px; }
.ex-panel-img { width:100%; height:200px; border-radius:var(--ab-radius,12px); background:var(--ab-teal-light,#e1f5ee) center/cover no-repeat; margin:10px 0 14px; }
.ex-panel-title { margin:0 0 8px; font-size:20px; font-weight:800; color:#1a2e24; }
.ex-panel-desc { font-size:14px; color:#5a7068; line-height:1.6; margin:0 0 14px; }
.ex-people-row { display:flex; gap:14px; flex-wrap:wrap; margin:14px 0 4px; }
.ex-panel-cta { position:sticky; bottom:0; left:0; right:0; display:flex; align-items:center; justify-content:space-between; gap:14px; background:#fff; border-top:1px solid #e1f5ee; padding:14px 18px; box-shadow:0 -6px 20px rgba(0,0,0,.08); }
.ex-panel-cta-price { display:flex; flex-direction:column; line-height:1.25; }
.ex-panel-cta-price-label { font-size:11px; color:#5a7068; text-transform:uppercase; letter-spacing:.4px; }
.ex-panel-cta-price-amount { font-size:19px; font-weight:800; color:#1a2e24; }
.ex-panel-cta-price-note { font-size:11px; color:#5a7068; margin-top:2px; }
/* Carrito/checkout — barra inferior persistente, mobile y desktop por
   igual (patrón de e-commerce entendido en ambos, decisión cerrada). */
.ex-cartbar { position:fixed; left:0; right:0; bottom:0; z-index:50; background:#1a2e24; color:#fff; box-shadow:0 -4px 20px rgba(0,0,0,.18); }
.ex-cartbar-row { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 20px; cursor:pointer; font-size:14px; }
.ex-cartbar-toggle { font-weight:700; }
.ex-cartbar-drawer { background:#fff; color:#1a2e24; max-height:70vh; overflow-y:auto; padding:16px 20px; border-top:1px solid rgba(0,0,0,.08); }
.ex-cart-list { list-style:none; margin:0 0 10px; padding:0; }
.ex-cart-list li { display:flex; justify-content:space-between; gap:10px; padding:8px 0; font-size:13px; border-bottom:1px solid #eef6f2; }
.ex-cart-remove {
  width:auto !important; height:auto !important; background-color:transparent !important; background-image:none !important; box-shadow:none !important;
  border:none; color:#e24b4a !important; font-family:inherit; cursor:pointer; margin:0 0 0 8px; padding:0;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
/* Sugerencia escalonada (habitaciones) dentro del carrito — una columna en
   angosto, dos en el resto; el drawer del carrito rara vez llega al ancho
   completo de la página, por eso el propio tope en 2 en vez de 3-4. */
.ex-suggest-grid { display:grid; grid-template-columns:1fr; gap:12px; margin-top:12px; }
@container (min-width:520px) {
  .ex-suggest-grid { grid-template-columns:repeat(2,1fr); }
}
@container (min-width:700px) {
  .ex-search-chip { display:none; }
  .ex-searchbar-fields { display:flex !important; }
  .ex-backdrop { align-items:center; justify-content:flex-end; padding-right:0; }
  .ex-panel { max-width:480px; height:100vh; max-height:100vh; border-radius:0; }
}
@media (prefers-reduced-motion:reduce) {
  .ex-backdrop { animation:none; }
}
`;
