import { useState, useEffect, useRef } from 'react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import * as API      from './api.js';
import * as RoomsAPI from './roomsApi.js';
import { useDebouncedValue, useScrollToErrorOnMobile, useScrollToTopOnChange } from './hooks.js';
import { Stepper, GalleryLightbox, CardSkeleton, TourCard, RoomCard, SHARED_CARD_CSS, fmtMoney } from './components/shared.jsx';

/**
 * Flujo continuo de descubrimiento (Pro Max — CONTRIBUTING.md § 16.15,
 * crítico para un cliente real). Un componente único para los dos flujos
 * (decisión del cliente 2026-08-03) — comparten carrito, modal de detalle,
 * paso de extras y checkout, evitando duplicar el árbol completo.
 *
 * mode="experience" → Flujo A: tour destacado → habitaciones alrededor de
 *   esa fecha → extras → checkout único → voucher general.
 * mode="room"        → Flujo B: habitación → catálogo de experiencias ±5
 *   días de la estadía → checkout único → voucher general.
 *
 * tourId/roomId (opcionales): preseleccionan un ítem y saltan el paso de
 * descubrimiento inicial — es lo que usan single-amir_tour.php/
 * single-flow_room.php al reemplazar el widget individual por este flujo
 * (decisión del cliente 2026-08-03: reservar desde la ficha de un tour o de
 * una habitación también tiene que continuar con el upsell, no terminar en
 * una confirmación simple — "upsell siempre").
 */
const CATALOG_WINDOW_DAYS = 5;        // Flujo B, paso 2 — fijo, decisión cerrada 2026-08-03
const EXTRAS_CATALOG_WINDOW_DAYS = 60; // "Otros tours" del paso de extras — ventana abierta a propósito, no restringida a la estadía
// Flujo A, paso 1 (Experiencias destacadas) — antes no se pasaba explícito,
// así que dependía en silencio del default del backend
// (DiscoveryController::featured(), hoy 4) sin que el texto de la pantalla
// lo aclarara — el cliente pidió que se avise que las fechas mostradas son
// una ventana acotada, no toda la disponibilidad del tour (2026-08-05).
// Pasarlo explícito acá evita que el número mostrado se desincronice si el
// default del backend cambia alguna vez sin tocar este archivo.
const FEATURED_WINDOW_DAYS = 4;

// Pedido del cliente (2026-08-21): "±4 días" no dice de verdad qué rango se
// está mostrando — mostrar las fechas concretas. Aritmética con
// getDate()/setDate() en hora LOCAL a propósito (no toISOString(), que
// convierte a UTC y puede correr un día en timezones adelantados — mismo
// bug de fondo que ya se corrigió en ExploreFlow.jsx, addDaysISO()).
function featuredRangeLabel( anchorISO, windowDays, lang ) {
	if ( ! anchorISO ) return '';
	const center = new Date( anchorISO + 'T00:00:00' );
	const from  = new Date( center ); from.setDate( from.getDate() - windowDays );
	const until = new Date( center ); until.setDate( until.getDate() + windowDays );
	const fmt = d => d.toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { month: 'short', day: 'numeric' } );
	return `${fmt( from )} – ${fmt( until )}`;
}

export default function DiscoveryFlow( { lang: initLang, mode = 'experience', tourId, roomId, showCatalog = true } ) {
	const [ lang ] = useState( initLang || 'en' );
	const currency = window.amirBooking?.currency ?? 'MXN';
	const t = ( es, en ) => ( lang === 'en' ? en : es );

	const [ step, setStep ] = useState( () => {
		if ( mode === 'experience' ) return tourId ? 'pick-tour' : 'start';
		return 'rooms';
	} );

	const [ cart, setCart ]   = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( false );

	// Paso "start" (Flujo A sin tour preseleccionado)
	const [ startDate, setStartDate ]     = useState( '' );
	const [ startPeople, setStartPeople ] = useState( 2 );
	const [ featuredTours, setFeaturedTours ] = useState( [] );

	// Ventana de estadía — se fija apenas entra el primer tour u la primera
	// habitación al carrito, alimenta el paso de habitaciones del Flujo A
	// (−1/+1 día, § 16.2/16.3 CONTRIBUTING.md) y el catálogo del Flujo B.
	const [ stay, setStay ] = useState( null ); // { checkIn, checkOut, guests }

	const tourCacheRef = useRef( {} );

	// Paneles de detalle (tour y habitación) — pantalla completa DENTRO del
	// flujo, no modales flotantes. Rediseño real 2026-08-04, pedido explícito
	// del cliente: los modales tipo "hoja inferior" sobre la grilla se sentían
	// como una capa más encima de otra en mobile, y el CTA de agregar quedaba
	// al fondo del contenido en vez de siempre visible. Al no tocar `step`
	// para abrirlos, cerrarlos vuelve exactamente a donde estaba el usuario
	// sin necesidad de trackear un "paso de retorno" aparte.
	const [ detailTour, setDetailTour ]         = useState( null ); // tour completo + available_dates, o null
	// Fecha ya elegida ANTES de abrir el panel (clic en una pill puntual) —
	// evita que el panel vuelva a mostrar el selector completo de fechas
	// (bug real corregido 2026-08-04, ver TourDetailStep).
	const [ detailTourDate, setDetailTourDate ] = useState( '' );
	const [ detailRoom, setDetailRoom ]         = useState( null );

	// Autoscroll en mobile (hooks.js) — un solo ref reusado en los 3 puntos
	// de retorno del componente (detalle de tour, detalle de habitación, y
	// el flujo normal) ya que nunca hay más de uno montado a la vez. El
	// "paso" que dispara el scroll-al-inicio combina `step` (start/
	// experiences/rooms/etc.) con si hay un panel de detalle abierto —
	// abrir/cerrar un detalle reemplaza toda la vista, es tan "paso nuevo"
	// como cualquier cambio de `step` real.
	const wrapRef = useRef( null );
	useScrollToErrorOnMobile( wrapRef );
	useScrollToTopOnChange( wrapRef, `${step}|${detailTour?.id ?? ''}|${detailRoom?.id ?? ''}` );

	// Galería de fotos de un tour — overlay liviano SOBRE la grilla (no un
	// paso más del flujo, a diferencia del panel de detalle): pedido
	// explícito del cliente 2026-08-05, tocar la foto de una tarjeta debe
	// mostrar la galería, no el panel de reserva completo. null = cerrada.
	const [ galleryImages, setGalleryImages ] = useState( null );
	function openGallery( tour ) {
		const images = ( tour.gallery_images?.length ? tour.gallery_images : ( tour.cover_image ? [ tour.cover_image ] : [] ) );
		if ( images.length ) setGalleryImages( images );
	}

	// Entrada directa a un tour puntual (ficha del tour, tourId prop) — el
	// tour se precarga acá pero el modal de confirmación NUNCA se abre solo:
	// tiene que quedar una tarjeta de reserva visible en todo momento, con
	// un botón explícito para abrirlo. Bug real corregido (2026-08-04): el
	// modal abría automáticamente al montar, tapaba el contenido de la
	// ficha (overlay a pantalla completa) y si se cerraba no quedaba nada
	// para poder reservar — el paso "pick-tour" no tenía contenido propio.
	const [ preselectedTour, setPreselectedTour ] = useState( null );

	// Paso de habitaciones
	const [ roomCheckIn, setRoomCheckIn ]   = useState( '' );
	const [ roomCheckOut, setRoomCheckOut ] = useState( '' );
	const [ roomGuests, setRoomGuests ]     = useState( 2 );
	const [ roomResults, setRoomResults ]   = useState( [] );
	const [ roomsSearched, setRoomsSearched ] = useState( false );

	// Catálogo (Flujo B paso 2, y "otros tours" del paso de extras)
	const [ catalogTours, setCatalogTours ] = useState( [] );

	// Checkout / pago
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

	// Entrada directa a un tour puntual (ficha del tour) — precarga el tour
	// para mostrar la tarjeta de reserva del paso "pick-tour", pero NUNCA
	// abre el modal solo (ver comentario en preselectedTour más arriba).
	useEffect( () => {
		if ( mode !== 'experience' || ! tourId ) return;
		setError( '' );
		setLoading( true );
		fetchTourDetail( tourId )
			.then( full => setPreselectedTour( full ) )
			.catch( err => setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) ) )
			.finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Paso "pick-tour" — fecha que el usuario elige + disponibilidad de ESTE
	// tour ±5 días alrededor (arranca en "hoy", o en ?date= de la URL si
	// [flow_tour_dates] mandó a alguien acá con una fecha puntual en mente —
	// § 16.54 CONTRIBUTING.md. A propósito NO abre el panel de detalle solo
	// con eso: sigue centrando la búsqueda nada más — abrir el modal
	// automático al montar ya se probó y se sacó, ver el comentario en
	// preselectedTour más arriba). Reemplaza el diseño previo (lista fija de
	// hasta 14 fechas de los próximos 3 meses, sin relación a nada) —
	// bug/fricción real reportada probando en vivo 2026-08-04: era más
	// práctico dejar que el usuario proponga una fecha y mostrarle
	// disponibilidad alrededor, mismo criterio que ya usa el paso "start" del
	// Flujo A (fecha + personas → destacados ±window_days).
	const [ pickAnchorDate, setPickAnchorDate ] = useState( () => {
		const params = new URLSearchParams( typeof window !== 'undefined' ? window.location.search : '' );
		const urlDate = params.get( 'date' ) ?? '';
		return /^\d{4}-\d{2}-\d{2}$/.test( urlDate ) ? urlDate : new Date().toISOString().slice( 0, 10 );
	} );
	const [ pickWindowDates, setPickWindowDates ] = useState( null ); // null = todavía no buscó
	const PICK_WINDOW_DAYS = 5;

	useEffect( () => {
		if ( preselectedTour && pickWindowDates === null ) {
			searchPickDates( pickAnchorDate );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ preselectedTour ] );

	async function searchPickDates( centerDate ) {
		if ( ! preselectedTour ) return;
		setLoading( true );
		try {
			const dates = await fetchAvailableDatesAround( preselectedTour.id, centerDate, PICK_WINDOW_DAYS );
			setPickWindowDates( dates );
		} finally {
			setLoading( false );
		}
	}

	function nightsBetween( a, b ) {
		if ( ! a || ! b ) return 0;
		return Math.round( ( new Date( b ) - new Date( a ) ) / 86400000 );
	}

	async function fetchTourDetail( id ) {
		if ( tourCacheRef.current[ id ] ) return tourCacheRef.current[ id ];
		const data = await API.getTour( id, lang );
		tourCacheRef.current[ id ] = data;
		return data;
	}

	/** Fechas disponibles de un tour dentro de ±windowDays alrededor de centerDate (paso "pick-tour"). */
	async function fetchAvailableDatesAround( id, centerDate, windowDays ) {
		const center = new Date( centerDate + 'T00:00:00' );
		const from   = new Date( center ); from.setDate( from.getDate() - windowDays );
		const until  = new Date( center ); until.setDate( until.getDate() + windowDays );

		const months = [];
		const cursor = new Date( from.getFullYear(), from.getMonth(), 1 );
		while ( cursor <= until ) {
			months.push( { year: cursor.getFullYear(), month: cursor.getMonth() + 1 } );
			cursor.setMonth( cursor.getMonth() + 1 );
		}
		const fromStr  = from.toISOString().slice( 0, 10 );
		const untilStr = until.toISOString().slice( 0, 10 );

		const results = await Promise.all( months.map( ( { year, month } ) => API.getMonthAvailability( id, year, month ).catch( () => ( {} ) ) ) );
		const dates = [];
		results.forEach( monthData => {
			Object.entries( monthData || {} ).forEach( ( [ date, info ] ) => {
				if ( date >= fromStr && date <= untilStr && info?.available ) dates.push( date );
			} );
		} );
		dates.sort();
		return dates.slice( 0, 14 );
	}

	/**
	 * Abre el panel de detalle para un tour de un grid (featured/catálogo) —
	 * esos ya vienen con available_dates precomputadas por el backend.
	 * pickedDate (opcional): la fecha exacta que se tocó en la tarjeta — si
	 * viene, el panel la usa directo y no vuelve a mostrar el selector.
	 */
	async function openTourDetail( tourSummary, pickedDate ) {
		setError( '' );
		setLoading( true );
		try {
			const full = await fetchTourDetail( tourSummary.id );
			setDetailTourDate( pickedDate || '' );
			setDetailTour( { ...full, available_dates: tourSummary.available_dates ?? [] } );
		} catch ( err ) {
			setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) );
		} finally {
			setLoading( false );
		}
	}

	async function handleStartSearch( e ) {
		e.preventDefault();
		setError( '' );
		if ( ! startDate ) {
			setError( t( 'Elegí una fecha.', 'Pick a date.' ) );
			return;
		}
		setLoading( true );
		try {
			const results = await RoomsAPI.getFeaturedTours( { date: startDate, lang, windowDays: FEATURED_WINDOW_DAYS } );
			setFeaturedTours( results );
			setStep( 'experiences' );
		} catch ( err ) {
			setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) );
		} finally {
			setLoading( false );
		}
	}

	// Catálogo visible por defecto (show_catalog="yes", default del shortcode)
	// — pedido explícito del cliente: antes [flow_discovery] siempre arrancaba
	// en el formulario suelto de "start", sin ningún tour a la vista hasta que
	// alguien lo enviara a mano, lo que se sentía como una página vacía.
	// show_catalog="no" restaura ese comportamiento anterior. Solo aplica al
	// arranque en frío (sin tour_id) — con tourId ya hay un flujo propio
	// (pick-tour/preselectedTour) que no toca esto.
	useEffect( () => {
		if ( showCatalog === false || mode !== 'experience' || tourId || startDate ) return;
		const today = new Date().toISOString().slice( 0, 10 );
		setStartDate( today );
		setLoading( true );
		RoomsAPI.getFeaturedTours( { date: today, lang, windowDays: FEATURED_WINDOW_DAYS } )
			.then( results => { setFeaturedTours( results ); setStep( 'experiences' ); } )
			.catch( err => setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) ) )
			.finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	function addTourToCart( { tour, date, scheduleId, scheduleLabel, adults, children, babies, totalMxn, depositPct, depositMxn, requireParticipantNames } ) {
		const uiId = `tour-${tour.id}-${date}-${scheduleId}-${Date.now()}`;
		setCart( c => [ ...c, {
			uiId, type: 'tour', tour_id: tour.id, schedule_id: scheduleId, date,
			adults, children, babies, addons: [], addonsSubtotal: 0,
			name: tour.name,
			sub: `${date}${scheduleLabel ? ' · ' + scheduleLabel : ''} · ${adults + children + babies} pax`,
			total: totalMxn,
			depositPct: depositPct || 0,
			depositMxn: depositMxn || 0,
			// "Requiere nombre de cada integrante" (2026-08-24) — bebés
			// afuera, mismo criterio que el widget clásico (StepDetails,
			// BookingWidget.jsx) y la validación server-side.
			requireParticipantNames: !! requireParticipantNames,
			participantNames: [],
		} ] );
		setDetailTour( null );
		setDetailTourDate( '' );

		if ( ! stay ) {
			const d = new Date( date + 'T00:00:00' );
			const inD  = new Date( d ); inD.setDate( d.getDate() - 1 );
			const outD = new Date( d ); outD.setDate( d.getDate() + 1 );
			setStay( { checkIn: inD.toISOString().slice( 0, 10 ), checkOut: outD.toISOString().slice( 0, 10 ), guests: adults + children } );
		}

		if ( mode === 'experience' && step !== 'extras' && step !== 'experiences-catalog' ) {
			setStep( 'rooms' );
		}
	}

	// Precarga el paso de habitaciones con la ventana sugerida (Flujo A) y
	// dispara la búsqueda automáticamente — el cliente ya editó las fechas
	// si hace falta desde acá antes de buscar de nuevo.
	useEffect( () => {
		if ( step === 'rooms' && stay && ! roomsSearched && mode === 'experience' ) {
			setRoomCheckIn( stay.checkIn );
			setRoomCheckOut( stay.checkOut );
			setRoomGuests( stay.guests || 2 );
			searchRooms( stay.checkIn, stay.checkOut, stay.guests || 2 );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ step ] );

	// Mismo criterio que el catálogo de "start" arriba, para el arranque en
	// frío del Flujo B ([flow_discovery mode="room"] sin room_id) — sin esto,
	// mode="room" siempre caía en el formulario de check-in/check-out vacío.
	// Con roomId preseleccionado (ficha de una habitación puntual) se deja
	// el comportamiento manual de siempre, a propósito.
	useEffect( () => {
		if ( showCatalog === false || mode !== 'room' || roomId || roomCheckIn ) return;
		const inD  = new Date(); inD.setDate( inD.getDate() + 1 );
		const outD = new Date(); outD.setDate( outD.getDate() + 2 );
		const checkIn  = inD.toISOString().slice( 0, 10 );
		const checkOut = outD.toISOString().slice( 0, 10 );
		setRoomCheckIn( checkIn );
		setRoomCheckOut( checkOut );
		searchRooms( checkIn, checkOut, roomGuests );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	async function searchRooms( checkIn, checkOut, guests ) {
		setError( '' );
		const n = nightsBetween( checkIn, checkOut );
		if ( ! checkIn || ! checkOut || n <= 0 ) {
			setError( t( 'Elegí fechas de check-in y check-out válidas.', 'Pick valid check-in/check-out dates.' ) );
			return;
		}
		setLoading( true );
		try {
			const all = await RoomsAPI.getRooms();
			const filtered = roomId ? all.filter( r => String( r.id ) === String( roomId ) ) : all;
			// Bug real corregido 2026-08-04 (mismo criterio que RoomSearch.jsx):
			// la capacidad de una habitación es solo informativa, nunca
			// descalifica la búsqueda — si no, nunca se podría cubrir un
			// grupo grande entre varias habitaciones más chicas.
			// 1 solo request batch para todas las habitaciones a la vez (antes
			// era 1 request POR habitación en paralelo — hallazgo real de
			// rendimiento, "perfeccionamiento del flujo combinado" 2026-08-08).
			const needsCheck = filtered.filter( r => n >= r.min_nights );
			const { availability = {} } = needsCheck.length
				? await RoomsAPI.checkAvailabilityBatch( needsCheck.map( r => r.id ), checkIn, checkOut )
				: {};
			const withAvailability = filtered.map( ( r ) => {
				if ( n < r.min_nights ) return { ...r, available: false, reason: t( `Mínimo ${r.min_nights} noche(s)`, `Min ${r.min_nights} night(s)` ) };
				const available = !! availability[ r.id ];
				return { ...r, available, reason: available ? '' : t( 'No disponible en esas fechas', 'Not available for those dates' ) };
			} );
			setRoomResults( withAvailability );
			setRoomsSearched( true );
		} catch ( err ) {
			setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) );
		} finally {
			setLoading( false );
		}
	}

	function handleRoomSearchSubmit( e ) {
		e.preventDefault();
		setRoomsSearched( false );
		searchRooms( roomCheckIn, roomCheckOut, roomGuests );
	}

	function addRoomToCart( room ) {
		if ( cart.some( c => c.type === 'room' && c.room_id === room.id ) ) return;
		const n = nightsBetween( roomCheckIn, roomCheckOut );
		const uiId = `room-${room.id}-${Date.now()}`;
		// Mismo fix que RoomSearch.jsx: nunca mandar más que la capacidad de
		// ESTA habitación puntual — si no, RoomBookingManager la rechaza en
		// el backend cuando el grupo se cubre entre varias habitaciones.
		const roomGuestsCapped = Math.min( roomGuests, room.capacity_max );
		setCart( c => [ ...c, {
			uiId, type: 'room', room_id: room.id, check_in: roomCheckIn, check_out: roomCheckOut, guests: roomGuestsCapped,
			capacity_max: room.capacity_max,
			name: lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es,
			sub: `${roomCheckIn} → ${roomCheckOut} (${n} ${t( 'noches', 'nights' )}) · ${t( `hasta ${room.capacity_max} huéspedes`, `up to ${room.capacity_max} guests` )}`,
			total: room.price_per_night * n,
		} ] );
		if ( ! stay ) {
			setStay( { checkIn: roomCheckIn, checkOut: roomCheckOut, guests: roomGuests } );
		}
		setDetailRoom( null );
	}

	function removeFromCart( uiId ) {
		setCart( c => c.filter( i => i.uiId !== uiId ) );
	}

	// "Requiere nombre de cada integrante" (2026-08-24) — a diferencia del
	// widget clásico (un solo tour, form local alcanza), acá el carrito
	// puede tener varios tours a la vez, cada uno con su propio conteo de
	// adultos/niños — el estado de los nombres vive en el ítem del carrito
	// en sí, no en un form aparte.
	function setParticipantName( uiId, index, value ) {
		setCart( c => c.map( item => {
			if ( item.uiId !== uiId ) return item;
			const next = [ ...( item.participantNames || [] ) ];
			next[ index ] = value;
			return { ...item, participantNames: next };
		} ) );
	}

	// Cobertura de huéspedes vía habitaciones en el carrito — pedido del
	// cliente 2026-08-04: hoy el único feedback era "cantidad de ítems +
	// precio", nada decía si ya alcanza para todo el grupo. Asume cada
	// habitación llena hasta su capacidad máxima (mismo criterio que
	// "contador manual" ya cerrado, § 16.3 CONTRIBUTING.md).
	const roomGuestsCovered = cart.filter( c => c.type === 'room' ).reduce( ( sum, c ) => sum + ( c.capacity_max || 0 ), 0 );
	const roomGuestsTarget  = roomGuests;
	const roomsInCart       = cart.filter( c => c.type === 'room' ).length;

	// Depósito parcial ("Depósito parcial por tour") — el total mostrado acá
	// (barra sticky + formulario de pago) es lo que se cobra AHORA, no el
	// precio completo del tour: un ítem con depósito activo aporta solo
	// depositMxn, no total. Mismo criterio que CartController::checkout()
	// suma charge_mxn en vez de total_mxn en el backend — tienen que coincidir.
	const cartTotal = cart.reduce( ( sum, i ) => sum + ( i.depositPct > 0 ? i.depositMxn : i.total ) + ( i.addonsSubtotal || 0 ), 0 );

	// context: 'list' (Flujo B paso 2, catálogo completo) vs 'suggestion'
	// (paso de extras, "otros tours sugeridos") — dos flags de visibilidad
	// independientes en cada tour (§ 16.21 CONTRIBUTING.md, 2026-08-04).
	async function loadCatalog( days, context ) {
		setError( '' );
		setLoading( true );
		try {
			const base   = stay?.checkIn ? new Date( stay.checkIn + 'T00:00:00' ) : new Date();
			const endRef = stay?.checkOut ? new Date( stay.checkOut + 'T00:00:00' ) : base;
			const from  = new Date( base );  from.setDate( from.getDate() - days );
			const until = new Date( endRef ); until.setDate( until.getDate() + days );
			const capped = new Date( Math.min( until.getTime(), from.getTime() + 60 * 86400000 ) );
			const results = await RoomsAPI.getCatalogWindow( {
				from: from.toISOString().slice( 0, 10 ), until: capped.toISOString().slice( 0, 10 ), lang, context,
			} );
			return results;
		} finally {
			setLoading( false );
		}
	}

	useEffect( () => {
		if ( step === 'experiences-catalog' ) {
			loadCatalog( CATALOG_WINDOW_DAYS, 'list' ).then( setCatalogTours ).catch( err => setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) ) );
		}
		if ( step === 'extras' ) {
			loadCatalog( EXTRAS_CATALOG_WINDOW_DAYS, 'suggestion' ).then( setCatalogTours ).catch( () => {} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ step ] );

	function nextStepFrom( current ) {
		if ( current === 'rooms' ) return mode === 'room' ? 'experiences-catalog' : 'extras';
		if ( current === 'experiences-catalog' ) return 'extras';
		if ( current === 'extras' ) return 'checkout';
		return current;
	}

	async function handleCheckout( customer ) {
		setError( '' );
		setLoading( true );
		setCustomerEmail( customer.customer_email );
		try {
			// El mismo código de cupón se manda en CADA ítem — cada manager
			// (BookingManager/RoomBookingManager) lo valida contra SU propio
			// tour/habitación (o lo acepta si es un cupón global) y no hace
			// nada si no aplica, sin bloquear el resto del carrito.
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
				setStep( 'payment' );
			} else {
				// Antes esto no traía booking_refs — ConfirmationPanel
				// siempre mostraba el texto tentativo "reserva recibida",
				// sin referencia, aunque el carrito ya hubiera quedado
				// confirmado de una (ej. cupón que cubre el 100%, ver
				// CartController::checkout()).
				setBookingRefs( resp.booking_refs ?? [] );
				setPendingProviderRefs( resp.pending_provider_refs ?? [] );
				setStep( 'confirmation' );
			}
		} catch ( err ) {
			setError( err.message || t( 'No pudimos completar la solicitud. Intenta de nuevo.', "We couldn't complete the request. Please try again." ) );
		} finally {
			setLoading( false );
		}
	}

	const cartItemsInCurrentStep = [ 'rooms', 'experiences-catalog', 'extras' ].includes( step );

	// Paneles de detalle (tour/habitación) reemplazan TODA la vista en vez de
	// flotar encima — evita la sensación de "una capa más" en mobile que
	// tenían los modales, y no requiere trackear un paso de retorno (no
	// tocan `step`, así que cerrarlos vuelve exactamente a donde estaba).
	if ( detailTour ) {
		return (
			<div className="df-wrap" ref={wrapRef}>
				<style>{ DISCOVERY_CSS }</style>
				{ error && <div className="ab-error-banner">⚠ { error }</div> }
				<TourDetailStep tour={ detailTour } initialDate={ detailTourDate } initialAdults={ startPeople } t={ t } lang={ lang } currency={ currency }
					onAdd={ addTourToCart } onBack={ () => { setDetailTour( null ); setDetailTourDate( '' ); } } />
			</div>
		);
	}
	if ( detailRoom ) {
		return (
			<div className="df-wrap" ref={wrapRef}>
				<style>{ DISCOVERY_CSS }</style>
				{ error && <div className="ab-error-banner">⚠ { error }</div> }
				<RoomDetailStep room={ detailRoom } lang={ lang } t={ t } currency={ currency }
					nights={ nightsBetween( roomCheckIn, roomCheckOut ) }
					canAdd={ roomsSearched }
					inCart={ cart.some( c => c.type === 'room' && c.room_id === detailRoom.id ) }
					onAdd={ () => addRoomToCart( detailRoom ) }
					onRemove={ () => { const item = cart.find( c => c.type === 'room' && c.room_id === detailRoom.id ); if ( item ) removeFromCart( item.uiId ); } }
					onBack={ () => setDetailRoom( null ) } />
			</div>
		);
	}

	return (
		<div className="df-wrap" ref={wrapRef}>
			<style>{ DISCOVERY_CSS }</style>

			{ error && <div className="ab-error-banner">⚠ { error }</div> }

			{ step === 'start' && (
				<form className="df-search" onSubmit={ handleStartSearch }>
					<div className="df-field">
						<label>{ t( 'Fecha', 'Date' ) }</label>
						<input type="date" required value={ startDate } min={ new Date().toISOString().slice( 0, 10 ) } onChange={ e => setStartDate( e.target.value ) } />
					</div>
					<div className="df-field">
						<label>{ t( 'Personas', 'People' ) }</label>
						<Stepper value={ startPeople } min={ 1 } onChange={ setStartPeople } ariaLabel={ t( 'Personas', 'People' ) } />
					</div>
					<button type="submit" className="ab-btn ab-btn-primary" disabled={ loading }>
						{ loading ? t( 'Buscando…', 'Searching…' ) : t( 'Ver experiencias →', 'See experiences →' ) }
					</button>
				</form>
			) }

			{ step === 'pick-tour' && (
				preselectedTour ? (
					<div className="ab-panel">
						<div className="df-date-hero">
							<div className="df-date-hero-icon" aria-hidden="true">📅</div>
							<div className="df-date-hero-text">
								<p className="df-date-hero-title">{ t( '¿Cuándo querés ir?', 'When would you like to go?' ) }</p>
								<p className="df-date-hero-sub">{ t( 'Elegí una fecha y te mostramos la disponibilidad real', "Pick a date and we'll show you real availability" ) }</p>
							</div>
						</div>
						<div className="df-field df-date-hero-field">
							<label>{ t( 'Fecha de referencia', 'Reference date' ) }</label>
							<input type="date" className="df-date-hero-input" value={ pickAnchorDate } min={ new Date().toISOString().slice( 0, 10 ) }
								onChange={ e => { setPickAnchorDate( e.target.value ); searchPickDates( e.target.value ); } } />
							<p style={ { fontSize: 11, color: '#5a7068', margin: '4px 0 0' } }>
								{ t( `Te mostramos disponibilidad real ±${PICK_WINDOW_DAYS} días alrededor de esa fecha.`, `We'll show real availability ±${PICK_WINDOW_DAYS} days around that date.` ) }
							</p>
						</div>

						{ pickWindowDates === null || loading ? (
							<p style={ { fontSize: 13, color: '#5a7068' } }>{ t( 'Buscando disponibilidad…', 'Searching availability…' ) }</p>
						) : pickWindowDates.length > 0 ? (
							<div className="df-pills">
								{ pickWindowDates.map( d => (
									<button key={ d } type="button" className="df-pill" onClick={ () => { setDetailTourDate( d ); setDetailTour( preselectedTour ); } }>
										{ new Date( d + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { month: 'short', day: 'numeric' } ) }
									</button>
								) ) }
							</div>
						) : (
							<p style={ { fontSize: 13, color: '#5a7068' } }>{ t( 'Sin disponibilidad en esa ventana — probá con otra fecha, o elegí una desde el calendario.', 'No availability in that window — try another date, or pick one from the calendar.' ) }</p>
						) }

						<button className="ab-btn ab-btn-ghost df-inline-btn" style={ { marginTop: 14 } } onClick={ () => setDetailTour( preselectedTour ) }>
							{ t( 'Ver calendario completo →', 'See full calendar →' ) }
						</button>
					</div>
				) : (
					<div className="df-loading">{ loading ? t( 'Cargando…', 'Loading…' ) : '' }</div>
				)
			) }

			{ step === 'experiences' && (
				<>
					<button className="ab-btn ab-btn-ghost df-inline-btn" onClick={ () => setStep( 'start' ) }>← { t( 'Cambiar fecha', 'Change date' ) }</button>
					<p className="df-step-title">
						{ t( `Experiencias destacadas (±${FEATURED_WINDOW_DAYS} días)`, `Featured experiences (±${FEATURED_WINDOW_DAYS} days)` ) }
						{ startDate && <span className="df-step-subtitle">{ featuredRangeLabel( startDate, FEATURED_WINDOW_DAYS, lang ) }</span> }
					</p>
					<div className="df-grid">
						{ featuredTours.map( tour => (
							<TourCard key={ tour.id } tour={ tour } lang={ lang } t={ t } currency={ currency }
								onPick={ ( date ) => openTourDetail( tour, date ) } onOpenGallery={ openGallery } />
						) ) }
						{ featuredTours.length === 0 && <p>{ t( 'No hay experiencias destacadas configuradas.', 'No featured experiences configured.' ) }</p> }
					</div>
				</>
			) }

			{ step === 'rooms' && (
				<>
					<p className="df-step-title">🏡 { t( 'Completá tu estadía', 'Complete your stay' ) }</p>
					<form className="df-search" onSubmit={ handleRoomSearchSubmit }>
						<div className="df-field">
							<label>{ t( 'Check-in', 'Check-in' ) }</label>
							<input type="date" required value={ roomCheckIn } min={ new Date().toISOString().slice( 0, 10 ) } onChange={ e => setRoomCheckIn( e.target.value ) } />
						</div>
						<div className="df-field">
							<label>{ t( 'Check-out', 'Check-out' ) }</label>
							<input type="date" required value={ roomCheckOut } min={ roomCheckIn || new Date().toISOString().slice( 0, 10 ) } onChange={ e => setRoomCheckOut( e.target.value ) } />
						</div>
						<div className="df-field">
							<label>{ t( 'Huéspedes', 'Guests' ) }</label>
							<Stepper value={ roomGuests } min={ 1 } onChange={ setRoomGuests } ariaLabel={ t( 'Huéspedes', 'Guests' ) } />
						</div>
						<button type="submit" className="ab-btn ab-btn-primary" disabled={ loading }>
							{ loading ? t( 'Buscando…', 'Searching…' ) : t( 'Buscar', 'Search' ) }
						</button>
					</form>

					{ roomsInCart > 0 && (
						<GuestCoverage t={ t } covered={ roomGuestsCovered } target={ roomGuestsTarget } rooms={ roomsInCart } />
					) }

					{ loading && ! roomsSearched && (
						<div className="df-grid"><CardSkeleton /></div>
					) }

					{ roomsSearched && (
						<div className="df-grid">
							{ roomResults.map( room => {
								const cartItem = cart.find( c => c.type === 'room' && c.room_id === room.id );
								return (
									<RoomCard key={ room.id } room={ room } lang={ lang } t={ t } currency={ currency }
										nights={ nightsBetween( roomCheckIn, roomCheckOut ) }
										inCart={ !! cartItem }
										onAdd={ () => addRoomToCart( room ) }
										onRemove={ () => removeFromCart( cartItem.uiId ) }
										onDetail={ () => setDetailRoom( room ) } />
								);
							} ) }
							{ roomResults.length === 0 && <p>{ t( 'No hay habitaciones cargadas.', 'No rooms configured.' ) }</p> }
						</div>
					) }

					{ /* Sin botón "Omitir" acá — llegar a este paso ya implica que
					     el tour se agregó al carrito (ver addTourToCart()), así
					     que la barra sticky del carrito (abajo) YA muestra
					     "Continuar →" con la misma acción exacta. Tenerlos los
					     dos a la vez era un botón duplicado (bug real reportado
					     2026-08-04, "botones que hacen lo mismo"). */ }
				</>
			) }

			{ step === 'experiences-catalog' && (
				<>
					<p className="df-step-title">{ t( `Experiencias cerca de tu estadía (±${CATALOG_WINDOW_DAYS} días)`, `Experiences near your stay (±${CATALOG_WINDOW_DAYS} days)` ) }</p>
					<div className="df-grid">
						{ loading && catalogTours.length === 0 && <CardSkeleton /> }
						{ catalogTours.filter( tr => ! cart.some( c => c.type === 'tour' && c.tour_id === tr.id ) ).map( tour => (
							<TourCard key={ tour.id } tour={ tour } lang={ lang } t={ t } currency={ currency }
								onPick={ ( date ) => openTourDetail( tour, date ) } onOpenGallery={ openGallery } />
						) ) }
						{ catalogTours.length === 0 && ! loading && <p>{ t( 'No hay experiencias disponibles en ese rango.', 'No experiences available in that range.' ) }</p> }
					</div>
					{ /* Sin botón "Omitir" acá — se llega a este paso con una
					     habitación ya en el carrito (única forma de avanzar
					     desde "rooms" en Flujo B), así que la barra sticky ya
					     muestra "Continuar →" con la misma acción. */ }
				</>
			) }

			{ step === 'extras' && (
				<ExtrasStep
					t={ t } lang={ lang } currency={ currency }
					cart={ cart } setCart={ setCart }
					catalogTours={ catalogTours.filter( tr => ! cart.some( c => c.type === 'tour' && c.tour_id === tr.id ) ) }
					tourCacheRef={ tourCacheRef }
					fetchTourDetail={ fetchTourDetail }
					onPickTour={ ( tour, date ) => openTourDetail( tour, date ) }
					onOpenGallery={ openGallery }
				/>
			) }

			{ step === 'checkout' && (
				<CheckoutForm
					t={ t } lang={ lang } currency={ currency }
					cart={ cart } cartTotal={ cartTotal }
					removeFromCart={ removeFromCart }
					setParticipantName={ setParticipantName }
					onSubmit={ handleCheckout }
					onBack={ () => setStep( 'extras' ) }
					loading={ loading }
				/>
			) }

			{ step === 'payment' && clientSecret && stripeRef.current && (
				<Elements stripe={ stripeRef.current } options={ { clientSecret, locale: lang } }>
					<CartPaymentStep t={ t } cartGroupId={ cartGroupId }
						onSuccess={ ( refs, pendingRefs ) => { setBookingRefs( refs ); setPendingProviderRefs( pendingRefs ?? [] ); setStep( 'confirmation' ); } } />
				</Elements>
			) }

			{ step === 'confirmation' && (
				<div className="ab-panel" style={ { textAlign: 'center' } }>
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
				</div>
			) }

			{ cart.length > 0 && cartItemsInCurrentStep && (
				<CartBar t={ t } currency={ currency } cart={ cart } cartTotal={ cartTotal }
					removeFromCart={ removeFromCart }
					roomsInCart={ roomsInCart } roomGuestsCovered={ roomGuestsCovered } roomGuestsTarget={ roomGuestsTarget }
					onContinue={ () => setStep( nextStepFrom( step ) ) } />
			) }

			{ galleryImages && (
				<GalleryLightbox images={ galleryImages } t={ t } onClose={ () => setGalleryImages( null ) } />
			) }
		</div>
	);
}

// ── Indicador de cobertura de huéspedes (habitaciones ya agregadas vs. objetivo) ──
function GuestCoverage( { t, covered, target, rooms } ) {
	const ok = covered >= target;
	return (
		<div className={ `df-guest-coverage ${ ok ? 'ok' : 'short' }` }>
			{ ok ? '✅' : '⚠' } { t(
				`Cubrís ${covered} de ${target} huéspedes con ${rooms} habitación(es)`,
				`Covers ${covered} of ${target} guests with ${rooms} room(s)`
			) }
			{ ! ok && <span className="df-guest-coverage-hint"> — { t( 'agregá otra habitación', 'add another room' ) }</span> }
		</div>
	);
}

// ── Barra de carrito persistente (sticky) ───────────────────────────────────
function CartBar( { t, currency, cart, cartTotal, removeFromCart, roomsInCart, roomGuestsCovered, roomGuestsTarget, onContinue } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<div className="df-cart-bar">
			<div className="df-cart-bar-row" onClick={ () => setOpen( o => ! o ) }>
				<span>🛒 { cart.length } { t( 'ítem(s)', 'item(s)' ) } — <strong>{ fmtMoney( cartTotal, currency ) }</strong> { open ? '▾' : '▸' }</span>
				<button onClick={ ( e ) => { e.stopPropagation(); onContinue(); } }>{ t( 'Continuar →', 'Continue →' ) }</button>
			</div>
			{ !! roomsInCart && (
				<div className="df-cart-bar-coverage">
					{ roomGuestsCovered >= roomGuestsTarget ? '✅' : '⚠' } { t(
						`${roomGuestsCovered} de ${roomGuestsTarget} huéspedes cubiertos`,
						`${roomGuestsCovered} of ${roomGuestsTarget} guests covered`
					) }
				</div>
			) }
			{ open && (
				<ul className="df-cart-list">
					{ cart.map( c => (
						<li key={ c.uiId }>
							<span>{ c.type === 'room' ? '🛏' : c.type === 'addon' ? '🎁' : '🎟' } { c.name } { c.sub ? '— ' + c.sub : '' }</span>
							<span>
								{ c.depositPct > 0
									? t( `Depósito ${fmtMoney(c.depositMxn, currency)} (de ${fmtMoney(c.total, currency)})`, `Deposit ${fmtMoney(c.depositMxn, currency)} (of ${fmtMoney(c.total, currency)})` )
									: fmtMoney( c.total + ( c.addonsSubtotal || 0 ), currency ) }
								<button type="button" onClick={ () => removeFromCart( c.uiId ) }>✕</button>
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

// ── Panel: detalle de habitación ────────────────────────────────────────────
// Ya no es un modal flotante (rediseño 2026-08-04, pedido explícito del
// cliente — "los modales en celular molestan, debe fluir"): ocupa toda la
// vista, con "← Volver" arriba y el precio+CTA anclados abajo, siempre
// visibles sin scrollear.
function RoomDetailStep( { room, lang, t, currency, nights, canAdd, inCart, onAdd, onRemove, onBack } ) {
	const name  = lang === 'en' ? ( room.name_en || room.name_es ) : room.name_es;
	const desc  = lang === 'en' ? ( room.description_en || room.description_es ) : room.description_es;
	const ready = canAdd && nights > 0;
	return (
		<div className="df-detail">
			<button className="ab-btn ab-btn-ghost df-inline-btn" onClick={ onBack }>← { t( 'Volver', 'Back' ) }</button>
			<div className={ `df-detail-body ${ ready ? 'has-cta' : '' }` }>
				{ room.gallery_images?.[0] && <div className="df-detail-img" style={ { backgroundImage: `url(${room.gallery_images[0]})` } } /> }
				<h2 className="df-detail-title">{ name }</h2>
				{ desc && <p className="df-detail-desc">{ desc }</p> }
				{ !! room.amenities?.length && (
					<div className="df-amenities">
						{ room.amenities.map( ( a, i ) => <span key={ i } className="df-amenity">{ a.icon } { lang === 'en' ? ( a.label_en || a.label_es ) : a.label_es }</span> ) }
					</div>
				) }
				<p className="df-detail-fact">👥 { t( `Capacidad máxima: ${room.capacity_max}`, `Max capacity: ${room.capacity_max}` ) }</p>
			</div>
			{ ready && (
				<div className="df-detail-cta">
					<div className="df-detail-cta-price">
						<span className="df-detail-cta-price-label">{ t( 'Total', 'Total' ) }</span>
						<span className="df-detail-cta-price-amount">{ fmtMoney( room.price_per_night * nights, currency ) }</span>
					</div>
					{ inCart ? (
						<button className="ab-btn ab-btn-outline" onClick={ onRemove }>✓ { t( 'Quitar', 'Remove' ) }</button>
					) : (
						<button className="ab-btn ab-btn-primary df-detail-cta-btn" onClick={ onAdd }>{ t( '+ Agregar al carrito', '+ Add to cart' ) }</button>
					) }
				</div>
			) }
		</div>
	);
}

// ── Panel: detalle de tour + selección de fecha/horario/personas ───────────
// Ya no es un modal flotante (rediseño 2026-08-04, pedido explícito del
// cliente — es el paso más pesado del flujo y el que más se sentía como
// "una capa encima de otra" en mobile). Ocupa toda la vista, con "← Volver"
// arriba y el precio+CTA anclados abajo: nunca hay que scrollear para
// encontrar el botón de agregar, y se resalta un instante apenas la
// selección queda completa (en vez de un scroll forzado, que se siente
// brusco cuando el CTA ya está siempre a la vista).
//
// initialDate (opcional): la fecha ya elegida por el usuario ANTES de abrir
// el panel (clic en una pill de una tarjeta, o en el paso "pick-tour").
// Bug real corregido 2026-08-04: antes el modal siempre reabría mostrando
// TODAS las fechas de nuevo (por defecto la primera, ignorando cuál se
// había tocado) — fricción/confusión real reportada probando en vivo. Con
// initialDate, el selector de fecha arranca colapsado (resumen + "Cambiar")
// en vez de mostrar la lista completa otra vez.
// initialAdults (opcional): la cantidad de personas ya elegida en el paso
// "start" del flujo (búsqueda de experiencias destacadas) — bug real
// reportado 2026-08-12 ("pide personas dos veces y la primera no sirve de
// nada"): antes este panel siempre arrancaba en 1 sin importar lo que el
// usuario ya había elegido, obligándolo a repetir el dato.
function TourDetailStep( { tour, t, lang, currency, onAdd, onBack, initialDate, initialAdults } ) {
	const [ date, setDate ] = useState( initialDate || tour.available_dates?.[0] || '' );
	const [ showDatePicker, setShowDatePicker ] = useState( ! initialDate );
	const [ schedules, setSchedules ] = useState( [] );
	const [ scheduleId, setScheduleId ] = useState( null );
	const [ adults, setAdults ] = useState( initialAdults || 1 );
	const [ children, setChildren ] = useState( 0 );
	const [ babies, setBabies ] = useState( 0 );
	const [ quote, setQuote ] = useState( null );
	const [ quoteError, setQuoteError ] = useState( '' );
	const [ loadingSchedules, setLoadingSchedules ] = useState( false );
	const [ justReady, setJustReady ] = useState( false );
	const wasReadyRef = useRef( false );
	const quoteRequestRef = useRef( 0 );

	useEffect( () => {
		if ( ! date ) return;
		setLoadingSchedules( true );
		setScheduleId( null );
		API.getDaySchedules( tour.id, date ).then( list => {
			setSchedules( list );
			if ( list.length === 1 ) setScheduleId( list[0].id );
		} ).catch( () => setSchedules( [] ) ).finally( () => setLoadingSchedules( false ) );
	}, [ date, tour.id ] );

	// Debounce + guard contra respuestas fuera de orden (bug real 2026-08-09:
	// una ráfaga de taps en +/- de personas disparaba un request de cotización
	// POR CLICK, y la respuesta que llegaba última no siempre era la del
	// último click). El debounce baja el volumen de requests; el
	// quoteRequestRef descarta cualquier respuesta que ya no sea la última
	// pedida, aunque llegue fuera de orden.
	const quoteKey = `${date}|${scheduleId}|${adults}|${children}|${babies}`;
	const debouncedQuoteKey = useDebouncedValue( quoteKey, 350 );

	useEffect( () => {
		if ( ! date || adults + children === 0 ) return;
		const sid = scheduleId ?? 0;
		const requestId = ++quoteRequestRef.current;
		API.getQuote( { tourId: tour.id, scheduleId: sid, date, adults, children, babies, lang } )
			.then( q => { if ( requestId === quoteRequestRef.current ) { setQuote( q ); setQuoteError( '' ); } } )
			// Bug real y grave 2026-08-12: esto tragaba cualquier falla de
			// cotización en silencio (precio mal configurado, red caída) — el
			// botón de agregar simplemente nunca aparecía, sin ningún aviso.
			// Ver CONTRIBUTING.md § 16.50.
			.catch( e => { if ( requestId === quoteRequestRef.current ) { setQuote( null ); setQuoteError( e.message || t( 'No pudimos calcular el precio de este tour.', "We couldn't calculate this tour's price." ) ); } } );
	}, [ debouncedQuoteKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Mínimo de personas POR RESERVA (amir_tours.min_passengers) — mismo
	// campo y criterio que StepPeople en BookingWidget.jsx (widget clásico),
	// ver CONTRIBUTING.md § 16.81.
	const minPax = Math.max( 1, parseInt( tour.min_passengers, 10 ) || 1 );
	const belowMinPax = ( adults + children + babies ) < minPax;

	const canAdd = !! date && ( schedules.length === 0 || !! scheduleId ) && adults >= 1 && ! belowMinPax && !! quote;

	// Apenas la selección queda completa (fecha+horario+quote), un pulso
	// breve en el CTA en vez de scrollear la página — el botón ya está
	// anclado abajo, siempre visible, así que no hace falta forzar el
	// scroll; el pulso solo llama la atención hacia él.
	useEffect( () => {
		if ( canAdd && ! wasReadyRef.current ) {
			setJustReady( true );
			const id = setTimeout( () => setJustReady( false ), 900 );
			wasReadyRef.current = true;
			return () => clearTimeout( id );
		}
		if ( ! canAdd ) wasReadyRef.current = false;
	}, [ canAdd ] );

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
		<div className="df-detail">
			<button className="ab-btn ab-btn-ghost df-inline-btn" onClick={ onBack }>← { t( 'Volver', 'Back' ) }</button>
			<div className={ `df-detail-body ${ canAdd ? 'has-cta' : '' }` }>
				{ tour.gallery_images?.[0] && <div className="df-detail-img" style={ { backgroundImage: `url(${tour.gallery_images[0]})` } } /> }
				<h2 className="df-detail-title">{ tour.name }</h2>
				{ tour.description && <p className="df-detail-desc">{ tour.description.replace( /<[^>]+>/g, '' ).slice( 0, 220 ) }</p> }

				<div className="df-field">
					<label>{ t( 'Fecha', 'Date' ) }</label>
					{ ! showDatePicker && date ? (
						<div className="df-date-summary">
							<span>📅 { new Date( date + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { weekday: 'short', month: 'short', day: 'numeric' } ) }</span>
							<button type="button" className="ab-btn ab-btn-ghost ab-btn-sm" onClick={ () => setShowDatePicker( true ) }>{ t( 'Cambiar', 'Change' ) }</button>
						</div>
					) : tour.available_dates?.length > 0 ? (
						<div className="df-pills">
							{ tour.available_dates.map( d => (
								<button key={ d } type="button" className={ `df-pill ${ d === date ? 'active' : '' }` } onClick={ () => { setDate( d ); setShowDatePicker( false ); } }>
									{ new Date( d + 'T00:00:00' ).toLocaleDateString( lang === 'en' ? 'en-US' : 'es-MX', { month: 'short', day: 'numeric' } ) }
								</button>
							) ) }
						</div>
					) : (
						<input type="date" value={ date } min={ new Date().toISOString().slice( 0, 10 ) } onChange={ e => setDate( e.target.value ) } />
					) }
				</div>

				{ date && loadingSchedules && <p className="df-detail-hint">{ t( 'Cargando horarios…', 'Loading schedules…' ) }</p> }
				{ date && ! loadingSchedules && schedules.length > 1 && (
					<div className="df-field">
						<label>{ t( 'Horario', 'Schedule' ) }</label>
						{ schedules.map( s => (
							<label key={ s.id } className="ab-policy-check-label" style={ { display: 'flex', gap: 6 } }>
								<input type="radio" name="df-schedule" disabled={ ! s.available }
									checked={ scheduleId === s.id } onChange={ () => setScheduleId( s.id ) } />
								{ lang === 'en' ? s.label_en : s.label_es } ({ s.time_start }) { ! s.available && `— ${s.reason}` }
							</label>
						) ) }
					</div>
				) }

				<div className="df-people-row">
					<div className="df-field">
						<label>{ t( 'Adultos', 'Adults' ) }</label>
						<Stepper value={ adults } min={ 1 } onChange={ setAdults } ariaLabel={ t( 'Adultos', 'Adults' ) } />
					</div>
					{ tour.allow_children !== false && (
						<div className="df-field">
							<label>{ t( 'Niños', 'Children' ) }</label>
							<Stepper value={ children } min={ 0 } onChange={ setChildren } ariaLabel={ t( 'Niños', 'Children' ) } />
						</div>
					) }
					{ tour.allow_babies !== false && (
						<div className="df-field">
							<label>{ t( 'Bebés', 'Babies' ) }</label>
							<Stepper value={ babies } min={ 0 } onChange={ setBabies } ariaLabel={ t( 'Bebés', 'Babies' ) } />
						</div>
					) }
				</div>
				{ belowMinPax && <p className="ab-age-notice">👥 { t( `Este tour requiere un mínimo de ${minPax} personas por reserva.`, `This tour requires a minimum of ${minPax} people per booking.` ) }</p> }
				{ quoteError && <div className="ab-error-banner">⚠ { quoteError }</div> }
			</div>

			{ canAdd && (
				<div className={ `df-detail-cta ${ justReady ? 'pulse' : '' }` }>
					<div className="df-detail-cta-price">
						{ quote.deposit_pct > 0 ? (
							<>
								<span className="df-detail-cta-price-label">{ t( `Depósito ahora (${quote.deposit_pct}%)`, `Deposit now (${quote.deposit_pct}%)` ) }</span>
								<span className="df-detail-cta-price-amount">{ quote.deposit_mxn?.toLocaleString( 'es-MX' ) } { currency }</span>
								<span className="df-detail-cta-price-note">{ t( `Total ${quote.total_mxn?.toLocaleString('es-MX')} ${currency} — resto se cobra después`, `Total ${quote.total_mxn?.toLocaleString('es-MX')} ${currency} — rest charged later` ) }</span>
							</>
						) : (
							<>
								<span className="df-detail-cta-price-label">{ t( 'Total', 'Total' ) }</span>
								<span className="df-detail-cta-price-amount">{ quote.total_mxn?.toLocaleString( 'es-MX' ) } { currency }</span>
							</>
						) }
					</div>
					<button className="ab-btn ab-btn-primary df-detail-cta-btn" onClick={ handleAdd }>
						{ t( '+ Agregar al carrito', '+ Add to cart' ) }
					</button>
				</div>
			) }
		</div>
	);
}

// ── Paso de extras: addons de catálogo (por tour en el carrito) + otros tours sugeridos ──
function ExtrasStep( { t, lang, currency, cart, setCart, catalogTours, tourCacheRef, fetchTourDetail, onPickTour, onOpenGallery } ) {
	const [ , forceRender ] = useState( 0 );
	const [ globalAddons, setGlobalAddons ] = useState( [] );
	const tourItems = cart.filter( c => c.type === 'tour' );

	useEffect( () => {
		const missing = tourItems.filter( c => ! tourCacheRef.current[ c.tour_id ] );
		if ( missing.length === 0 ) return;
		Promise.all( missing.map( c => fetchTourDetail( c.tour_id ) ) ).then( () => forceRender( n => n + 1 ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ cart ] );

	// Extras globales (§ 16.23 CONTRIBUTING.md) — no pertenecen a ningún
	// tour/habitación del carrito, así que se muestran siempre en este paso,
	// sin importar qué haya en el carrito (a diferencia de los addons de
	// arriba, que solo aparecen si YA hay un tour puntual agregado).
	useEffect( () => {
		RoomsAPI.getGlobalAddons( lang ).then( setGlobalAddons ).catch( () => {} );
	}, [ lang ] );

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

	// Un extra global es su propio ítem de carrito (type:'addon') — no
	// viaja dentro de un tour/habitación puntual, porque puede no haber
	// ninguno en el carrito (ver CartController::attach_global_addons()).
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

	// Pedido explícito del cliente 2026-08-04: "si no hay extras asociados
	// esa pantalla está de más" — nunca un título de sección seguido de
	// "no hay nada", directamente no se muestra la sección si no tiene
	// contenido real.
	const hasPerTourAddons = tourItems.some( item => ( tourCacheRef.current[ item.tour_id ]?.addons?.length ?? 0 ) > 0 );
	const hasExtras = globalAddons.length > 0 || hasPerTourAddons;

	return (
		<div>
			{ hasExtras && <p className="df-step-title">{ t( 'Servicios extra', 'Extras' ) }</p> }

			{ globalAddons.length > 0 && (
				<div className="ab-panel" style={ { marginBottom: 16 } }>
					<div className="ab-people-list">
						{ globalAddons.map( a => {
							const current = cart.find( c => c.type === 'addon' && c.addon_id === a.id );
							const qty = current?.qty ?? 0;
							return (
								<div key={ a.id } className={ `ab-people-row${ qty > 0 ? ' selected' : '' }` }>
									<div className="ab-people-info">
										<div className="ab-people-label">{ a.pricing_type === 'digital' ? '📄 ' : '🎁 ' }{ a.name }</div>
									</div>
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
				</div>
			) }

			{ tourItems.map( item => {
				const full = tourCacheRef.current[ item.tour_id ];
				const addons = full?.addons ?? [];
				if ( addons.length === 0 ) return null;
				return (
					<div key={ item.uiId } className="ab-panel" style={ { marginBottom: 16 } }>
						<p className="ab-panel-title">{ item.name }</p>
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

			{ catalogTours.length > 0 && (
				<>
					<p className="df-step-title">{ t( 'Otros tours sugeridos', 'Other suggested tours' ) }</p>
					<div className="df-grid">
						{ catalogTours.map( tour => (
							<TourCard key={ tour.id } tour={ tour } lang={ lang } t={ t } currency={ currency } onPick={ ( date ) => onPickTour( tour, date ) } onOpenGallery={ onOpenGallery } />
						) ) }
					</div>
				</>
			) }
			{ /* Sin botón "Continuar" propio acá — se llega a este paso con al
			     menos un tour u habitación ya en el carrito, así que la barra
			     sticky (siempre visible con cart.length > 0) ya lo resuelve;
			     tenerlos los dos era un botón duplicado. */ }
		</div>
	);
}

// ── Checkout (datos del cliente + resumen del carrito mixto) ───────────────
function CheckoutForm( { t, lang, currency, cart, cartTotal, removeFromCart, setParticipantName, onSubmit, onBack, loading } ) {
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );
	const [ couponCode, setCouponCode ] = useState( '' );
	const [ policyAccepted, setPolicyAccepted ] = useState( false );
	const [ termsAccepted, setTermsAccepted ] = useState( false );
	const [ namesError, setNamesError ] = useState( '' );

	// "Requiere nombre de cada integrante" — items del carrito que lo piden
	// (bebés no cuentan, ver BookingManager::participant_names_error()).
	const itemsNeedingNames = cart.filter( c => c.type === 'tour' && c.requireParticipantNames );
	const namesComplete = itemsNeedingNames.every( c => {
		const needed = c.adults + c.children;
		const filled = ( c.participantNames || [] ).slice( 0, needed ).filter( n => ( n ?? '' ).trim() ).length;
		return filled >= needed;
	} );
	// Preferencia de idioma para el email de confirmación — el widget
	// clásico ya la tiene (ab-lang-toggle en StepDetails), acá faltaba por
	// completo (auditoría de UX pre-empaquetado v5.7.14, CONTRIBUTING.md
	// § 16.91). Default: el idioma de la UI, editable.
	const [ emailLang, setEmailLang ] = useState( lang ?? 'es' );

	function handleSubmit( e ) {
		e.preventDefault();
		if ( ! namesComplete ) {
			setNamesError( t( 'Completá el nombre de cada integrante para continuar.', "Fill in every participant's name to continue." ) );
			return;
		}
		onSubmit( { customer_name: name, customer_email: email, customer_phone: phone, coupon_code: couponCode.trim(), policy_accepted: policyAccepted, terms_accepted: termsAccepted, lang: emailLang } );
	}

	return (
		<form className="ab-panel" onSubmit={ handleSubmit }>
			<p className="ab-panel-title">{ t( 'Tus datos', 'Your details' ) }</p>

			<ul style={ { listStyle: 'none', padding: 0, margin: '0 0 16px' } }>
				{ cart.map( c => (
					<li key={ c.uiId } style={ { display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #e1f5ee', fontSize: 14 } }>
						<span>{ c.type === 'room' ? '🛏' : c.type === 'addon' ? '🎁' : '🎟' } { c.name } { c.sub ? '— ' + c.sub : '' }</span>
						<span>
							{ c.depositPct > 0
								? t( `Depósito ${fmtMoney(c.depositMxn, currency)} (de ${fmtMoney(c.total, currency)})`, `Deposit ${fmtMoney(c.depositMxn, currency)} (of ${fmtMoney(c.total, currency)})` )
								: fmtMoney( c.total + ( c.addonsSubtotal || 0 ), currency ) }
							<button type="button" onClick={ () => removeFromCart( c.uiId ) } style={ { border: 'none', background: 'none', color: '#e24b4a', cursor: 'pointer', marginLeft: 8 } }>✕</button>
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
				<p style={ { fontSize: 11, color: '#5a7068', margin: '4px 0 0' } }>{ t( 'El descuento se aplica al confirmar — si no aplica a algún ítem, ese ítem se cobra a precio completo.', 'The discount is applied on confirmation — if it doesn\'t apply to an item, that item is charged at full price.' ) }</p>
			</div>

			{/* Bug real reportado por el cliente: las dos casillas aparecían
				una debajo de la otra sin ningún texto de política/términos
				arriba — "no enlazan a ningún contenido". Mismo fix que
				BookingWidget.jsx: mostrar siempre el texto real (configurado
				en Configuración) o, si no hay nada cargado, un texto por
				defecto — nunca una casilla suelta sin nada detrás. */}
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

/** Paso de pago del carrito — idéntico al de RoomSearch.jsx, mismo endpoint (cart/{id}/confirm-payment). */
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
		<div className="ab-panel">
			<p className="ab-panel-title">{ t( 'Pago', 'Payment' ) }</p>
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

// SHARED_CARD_CSS (shared.jsx) trae las reglas de TourCard/RoomCard/
// CardSkeleton/Stepper/GalleryLightbox (df-card*, df-pill*, df-stepper*,
// df-lightbox*, df-grid, df-skeleton*, keyframes df-fade-up/df-pulse) —
// extraídas de acá sin cambiar una sola propiedad, ver § 16.46
// CONTRIBUTING.md. Lo que sigue es solo lo específico de DiscoveryFlow.
const DISCOVERY_CSS = SHARED_CARD_CSS + `
/* Base de texto explícita: sin esto, títulos/etiquetas sin color propio
   heredaban el color de texto del tema del sitio — en algunos temas eso es
   un gris claro/blanco, invisible sobre las tarjetas blancas del flujo (bug
   real reportado 2026-08-04, "tipografía blanca"). */
/* container-type habilita @container más abajo — el layout de 2 columnas
   del panel de detalle tiene que reaccionar al ancho REAL disponible para
   el widget, no al ancho de la pantalla entera (bug real encontrado en
   producción, Caliafarm: el shortcode vive en un sidebar angosto del tema
   de ~420px, pero un @media (min-width:900px) igual activaba el grid de
   2 columnas porque la pantalla SÍ mide ≥900px — el grid se desbordaba,
   180px de contenido + 360px del CTA en un espacio de 420px). */
.df-wrap { container-type:inline-size; max-width:960px; margin:0 auto; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#1a2e24; }
.df-search { display:flex; flex-direction:column; align-items:stretch; gap:12px; background:var(--ab-teal-light,#f8fdfb); border:1px solid #e1f5ee; border-radius:var(--ab-radius,12px); padding:16px; margin-bottom:20px; }
.df-field { display:flex; flex-direction:column; gap:4px; width:100%; }
.df-field label { font-size:12px; font-weight:600; color:#1a2e24; }
.df-field input { border:1px solid #c3d9d0; border-radius:var(--ab-radius-sm,8px); padding:9px 12px; font-size:14px; width:100%; box-sizing:border-box; }
/* "¿Cuándo querés ir?" con tour preseleccionado — es el momento de mayor
   conversión de este flujo (el usuario ya eligió el tour, solo falta la
   fecha) pero antes usaba el mismo .ab-panel-title/.df-field genérico que
   cualquier otro paso, sin ninguna jerarquía visual propia. Clase scopeada
   a propósito (no se toca .ab-panel-title/.df-field, los reusan ~10 pasos
   más de este mismo componente). */
.df-date-hero { display:flex; align-items:flex-start; gap:12px; background:var(--ab-teal-light,#eafbf4); border:1px solid var(--ab-teal-mid,#bfe8d8); border-radius:var(--ab-radius,12px); padding:14px 16px; margin-bottom:16px; }
.df-date-hero-icon { font-size:26px; line-height:1; flex-shrink:0; }
.df-date-hero-title { font-size:19px; font-weight:800; color:#12261d; margin:0 0 2px; }
.df-date-hero-sub { font-size:12.5px; color:#3f5a4f; margin:0; }
.df-date-hero-field { margin-bottom:14px; }
.df-date-hero-input.df-date-hero-input { border:2px solid var(--ab-teal,#1D9E75); border-radius:var(--ab-radius-sm,8px); padding:12px 14px; font-size:16px; font-weight:600; }
/* Botones "de navegación" inline (← Cambiar fecha, Omitir, Ver calendario
   completo…) — sin esto heredaban width:100% de .ab-btn (pensado para la
   card angosta de BookingWidget) y se veían como bloques gigantes en este
   layout más ancho (bug real reportado 2026-08-04, "todo está grande"). */
.df-inline-btn { width:auto !important; display:inline-flex !important; padding:8px 6px !important; }
.df-step-title { font-size:calc(18px * var(--ab-font-scale,1)); font-weight:700; color:#1a2e24; margin:20px 0 12px; }
.df-step-subtitle { display:block; font-size:calc(12.5px * var(--ab-font-scale,1)); font-weight:500; color:#5a7068; margin-top:2px; }
.df-date-summary { display:flex; align-items:center; justify-content:space-between; gap:10px; background:var(--ab-teal-light,#f0f9f5); border-radius:var(--ab-radius-sm,8px); padding:10px 14px; font-size:14px; font-weight:600; color:#1a2e24; }
.df-date-summary .ab-btn { width:auto !important; flex-shrink:0; }
.df-cart-bar { position:sticky; bottom:0; background:#1a2e24; color:#fff; padding:14px 20px; border-radius:var(--ab-radius,12px); margin-top:16px; z-index:20; box-shadow:0 -4px 20px rgba(0,0,0,.15); }
.df-cart-bar-row { display:flex; align-items:center; justify-content:space-between; gap:16px; cursor:pointer; }
.df-cart-bar-row button {
  width:auto !important; height:auto !important; background-color:var(--ab-teal,#1D9E75) !important; background-image:none !important; box-shadow:none !important;
  color:#fff; margin:0; border:none; border-radius:var(--ab-radius-sm,8px); padding:10px 20px; font-weight:700;
  font-family:inherit; cursor:pointer; flex-shrink:0;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
.df-cart-bar-coverage { font-size:12px; opacity:.9; margin-top:8px; }
.df-guest-coverage { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; padding:10px 14px; border-radius:var(--ab-radius-sm,8px); margin:10px 0; }
.df-guest-coverage.ok { background:#eafaf1; color:#0F6E56; }
.df-guest-coverage.short { background:#fff7e6; color:#a15c00; }
.df-guest-coverage-hint { font-weight:500; }
.df-cart-list { list-style:none; margin:12px 0 0; padding:0; border-top:1px solid rgba(255,255,255,.2); }
.df-cart-list li { display:flex; justify-content:space-between; gap:10px; padding:8px 0; font-size:13px; }
.df-cart-list button {
  width:auto !important; height:auto !important; background-color:transparent !important; background-image:none !important; box-shadow:none !important;
  border:none; color:#ff8f8f; font-family:inherit; cursor:pointer; margin:0 0 0 8px; padding:0;
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
}
/* ── Paneles de detalle (tour/habitación) — pantalla completa dentro del
   flujo, reemplazan los modales flotantes de antes (rediseño 2026-08-04,
   pedido explícito del cliente: "los modales en celular molestan, debe
   fluir"). Mobile: una columna, CTA anclado abajo (sticky bottom), siempre
   alcanzable con el pulgar sin scrollear. Desktop (≥900px): dos columnas —
   contenido a la izquierda, tarjeta de reserva fija a la derecha (mismo
   patrón que usan Airbnb/Booking — "podés usar más pantalla en PC", pedido
   del cliente) — sin restructurar el HTML, solo grid-template-areas sobre
   los mismos 3 hijos que ya existían (botón volver, cuerpo, CTA). */
.df-detail { animation:df-fade-up .3s ease both; }
.df-detail-body { padding-bottom:24px; }
.df-detail-body.has-cta { padding-bottom:100px; } /* espacio para no quedar tapado por el CTA sticky en mobile */
.df-detail-img { width:100%; height:220px; border-radius:var(--ab-radius,12px); background:var(--ab-teal-light,#e1f5ee) center/cover no-repeat; margin:12px 0 16px; }
.df-detail-title { margin:0 0 8px; font-size:22px; font-weight:800; color:#1a2e24; }
.df-detail-desc { font-size:14px; color:#5a7068; line-height:1.6; margin:0 0 16px; }
.df-detail-fact { font-size:14px; color:#1a2e24; margin:12px 0; }
.df-detail-hint { font-size:13px; color:#5a7068; }
.df-amenities { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0 16px; }
.df-amenity { background:var(--ab-teal-light,#f0f9f5); border-radius:8px; padding:6px 10px; font-size:12px; color:#1a2e24; }
.df-people-row { display:flex; gap:14px; flex-wrap:wrap; margin:16px 0 4px; }
.df-loading { padding:40px; text-align:center; color:#5a7068; }
/* CTA: barra fija al pie en mobile — precio + botón siempre visibles, sin
   tener que buscarlos scrolleando el contenido de arriba. */
.df-detail-cta { position:sticky; bottom:0; left:0; right:0; display:flex; align-items:center; justify-content:space-between; gap:14px; background:#fff; border-top:1px solid #e1f5ee; padding:14px 18px; margin:0 -1px; box-shadow:0 -6px 20px rgba(0,0,0,.08); z-index:15; }
.df-detail-cta-price { display:flex; flex-direction:column; line-height:1.25; }
.df-detail-cta-price-label { font-size:11px; color:#5a7068; text-transform:uppercase; letter-spacing:.4px; }
.df-detail-cta-price-amount { font-size:19px; font-weight:800; color:#1a2e24; }
.df-detail-cta-price-note { font-size:11px; color:#5a7068; margin-top:2px; }
.df-detail-cta-btn { width:auto; flex-shrink:0; padding:13px 22px; }
/* Pulso breve apenas la selección queda completa — llama la atención hacia
   el CTA en vez de forzar un scroll (ya está siempre a la vista). */
@keyframes df-cta-pulse { 0%,100%{ box-shadow:0 -6px 20px rgba(0,0,0,.08); } 40%{ box-shadow:0 -6px 28px rgba(29,158,117,.35); } }
.df-detail-cta.pulse { animation:df-cta-pulse .9s ease; }
@media (prefers-reduced-motion:reduce) {
  .df-detail-cta.pulse { animation:none; }
  .df-detail { animation:none; }
}
/* @container en vez de @media — el ancho que importa acá es el del propio
   widget (.df-wrap, container-type:inline-size más arriba), no el de la
   pantalla. Un sidebar angosto de 400-500px con la pantalla en desktop
   NUNCA debe activar este grid de 2 columnas; un widget a pantalla
   completa en mobile grande sí puede, si el contenedor lo permite. */
@container (min-width:900px) {
  .df-detail { display:grid; grid-template-columns:1fr 360px; column-gap:32px; align-items:start; }
  .df-detail > .df-inline-btn { grid-column:1 / -1; justify-self:start; }
  .df-detail-body { grid-column:1; padding-bottom:0; }
  .df-detail-body.has-cta { padding-bottom:0; }
  .df-detail-img { height:340px; }
  .df-detail-cta { grid-column:2; position:sticky; top:20px; bottom:auto; left:auto; right:auto; flex-direction:column; align-items:stretch; gap:16px; border:1px solid #e1f5ee; border-top:1px solid #e1f5ee; border-radius:var(--ab-radius,12px); box-shadow:0 10px 30px rgba(0,0,0,.10); margin:0; padding:20px; }
  .df-detail-cta-btn { width:100%; }
}
@media (min-width:640px) {
  .df-search { flex-direction:row; flex-wrap:wrap; align-items:end; }
  .df-search .df-field { width:auto; }
  .df-search .df-field input { width:auto; }
  .df-search > button[type=submit] { width:auto; }
}
`;
