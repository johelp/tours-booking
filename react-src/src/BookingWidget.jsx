import { useState, useEffect, useCallback, useRef } from 'react';
import { loadStripe }          from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { useT }               from './i18n.js';
import * as API               from './api.js';
import { trackInitiateCheckout, trackPurchase, getCouponFromUrl } from './marketing.js';
import { useDebouncedValue, useScrollToErrorOnMobile, useScrollToTopOnChange } from './hooks.js';
import './styles/widget.css';

// ── Constants ─────────────────────────────────────────────────────────────────
const STEPS = [ 'date', 'schedule', 'people', 'extras', 'details', 'summary', 'payment', 'confirm' ];

// Skip the date/calendar step entirely for tours con fecha fija
// (tour.fixed_date, "YYYY-MM-DD" o null) — pedido explícito del cliente
// 2026-08-06: para un evento de fecha única el cliente no debería tener
// que "encontrar" el único día habilitado en un calendario completo.
// tour.custom_quote ("armá tu tour", pedido 2026-08-16) tampoco tiene
// calendario — el tour no tiene horarios cargados, así que no hay nada
// que mostrar ahí tampoco (ver el useEffect que auto-completa form.date
// más abajo, en BookingFlow).
const needsDateStep = ( tour ) => ! tour?.fixed_date && ! tour?.custom_quote;

// ?date=YYYY-MM-DD en la URL — [flow_tour_dates] (§ 16.54 CONTRIBUTING.md)
// linkea acá con ese parámetro para que un pill de fecha en una landing de
// promoción lleve directo a esa fecha ya elegida, sin repetir el paso de
// calendario. Mismo criterio laxo que getCouponFromUrl() (marketing.js):
// si no matchea el formato, se ignora sin romper nada.
function getDateFromUrl() {
  if ( typeof window === 'undefined' ) return '';
  const d = new URLSearchParams( window.location.search ).get( 'date' ) ?? '';
  return /^\d{4}-\d{2}-\d{2}$/.test( d ) ? d : '';
}

// Steps that require a schedule selector (skip if tour has only 1 schedule)
const needsScheduleStep = ( schedules ) => schedules?.length > 1;

// Skip the extras step entirely if the tour has no add-ons configured —
// custom_quote tours nunca tienen catálogo de extras con precio (todo se
// cotiza junto), aunque tengan addons cargados por error.
const needsExtrasStep = ( tour ) => ( tour?.addons?.length ?? 0 ) > 0 && ! tour?.custom_quote;

// "YYYY-MM-DD" de hoy, hora local — usado como placeholder de tour_date
// para reservas custom_quote (no hay fecha real de tour, la columna es
// NOT NULL). Ver create_custom_quote_request() en class-booking-manager.php.
function todayISO() {
  const d = new Date();
  const pad = ( n ) => String( n ).padStart( 2, '0' );
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
}

// Si no hay horarios, usar schedule_id=0 como placeholder
const resolveScheduleId = ( form, schedules, tourSchedules ) => {
  if ( form.scheduleId !== null ) return form.scheduleId;
  const all = schedules ?? tourSchedules ?? [];
  if ( all.length === 0 ) return 0; // Sin horarios configurados
  if ( all.length === 1 ) return all[0].id;
  return null;
};

// ── Root: mounts the widget from a DOM element injected by the shortcode ──────
export default function BookingWidget({ tourId, lang: initLang, stripeKey }) {
  const [ lang,    setLang    ] = useState( initLang ?? 'es' );
  const [ tour,    setTour    ] = useState( null );
  const [ loading, setLoading ] = useState( true );
  const [ error,   setError   ] = useState( '' );
  const stripeRef = useRef( null );

  const t = useT( lang );

  useEffect( () => {
    if ( stripeKey && ! stripeRef.current ) {
      stripeRef.current = loadStripe( stripeKey );
    }
  }, [ stripeKey ] );

  // Cargar tour solo una vez al montar — el idioma NO recarga el tour
  // (el idioma de la UI se maneja por separado via setLang)
  useEffect( () => {
    setLoading( true );
    API.getTour( tourId, initLang ?? 'es' )
      .then( data => { setTour( data ); setLoading( false ); } )
      .catch( e => { setError( e.message || t('err_generic') ); setLoading( false ); } );
  }, [ tourId ] );  // ← solo tourId, NO lang

  if ( loading ) return (
    <div className="ab-widget">
      <div className="ab-loading"><div className="ab-spinner" />{t('loading')}</div>
    </div>
  );

  if ( error || ! tour ) return (
    <div className="ab-widget">
      <div className="ab-panel">
        <div className="ab-error-banner">⚠ {error || t('err_generic')}</div>
      </div>
    </div>
  );

  return (
    <BookingFlow
      tour={tour}
      lang={lang}
      setLang={setLang}
      stripePromise={stripeRef.current}
      t={t}
    />
  );
}

// ── BookingFlow: manages step state and booking data ─────────────────────────
function BookingFlow({ tour, lang, setLang, stripePromise, t }) {
  const widgetRef = useRef( null );
  const [ step,         setStep        ] = useState( 0 ); // index into STEPS
  const [ availability, setAvailability ] = useState( {} );
  const [ schedules,    setSchedules    ] = useState( null );
  const [ quote,        setQuote        ] = useState( null );
  const [ quoteError,   setQuoteError   ] = useState( '' );
  const [ clientSecret, setClientSecret ] = useState( '' );
  const [ bookingRef,   setBookingRef   ] = useState( '' );
  const [ bookingId,    setBookingId    ] = useState( null );
  const [ mpData,       setMpData       ] = useState( null ); // { preference_id, init_point, sandbox_init_point }
  const [ redsysData,   setRedsysData   ] = useState( null ); // { action_url, signature_version, merchant_parameters, signature }
  const [ gateway,      setGateway      ] = useState( 'stripe' );
  // Monto real que devolvió el backend al crear la reserva (POST /bookings),
  // no el último `quote` del cliente — evita reportar a los píxeles un valor
  // desactualizado si el cupón/precio cambió justo antes de confirmar.
  const [ finalTotalMxn, setFinalTotalMxn ] = useState( 0 );

  const [ form, setForm ] = useState({
    date:            '',
    scheduleId:      null,
    scheduleLabel:   '',
    scheduleTime:    '',
    adults:          1,
    children:        0,
    babies:          0,
    customerName:    '',
    customerEmail:   '',
    customerPhone:   '',
    specialRequests: '',
    participantNames: [], // solo se usa/valida si tour.require_participant_names — ver StepDetails
    couponCode:      getCouponFromUrl(),
    policyAccepted:  false,
    termsAccepted:   false,
    selectedAddons:  {}, // { [addonId]: qty }
  });

  const patchForm = ( patch ) => setForm( f => ( { ...f, ...patch } ) );
  const quoteRequestRef = useRef( 0 );

  // InitiateCheckout/begin_checkout: se dispara una sola vez, cuando el
  // widget de reserva ya está montado e interactivo (no hay un paso previo
  // de "ver tour" separado dentro del widget mismo — eso lo cubre el
  // ViewContent/view_item del template de single-amir_tour.php).
  useEffect( () => {
    trackInitiateCheckout( tour );
  }, [] );

  // Filter applicable steps (skip schedule step if only 1 schedule, skip
  // date step entirely for tours de fecha fija)
  const activeSteps = STEPS.filter( s => {
    if ( s === 'date' )     return needsDateStep( tour );
    if ( s === 'schedule' ) return needsScheduleStep( schedules ?? tour.schedules );
    if ( s === 'extras' )   return needsExtrasStep( tour );
    return true;
  } );
  const stepName    = activeSteps[ step ];
  const totalSteps  = activeSteps.length;
  const stepIndex   = ( name ) => activeSteps.indexOf( name );

  const goNext = () => setStep( s => Math.min( s + 1, totalSteps - 1 ) );
  const goBack = () => setStep( s => Math.max( s - 1, 0 ) );

  // When schedule step is skipped, auto-select the only schedule
  useEffect( () => {
    if ( schedules?.length === 1 && ! form.scheduleId ) {
      const s = schedules[0];
      patchForm({
        scheduleId:    s.id,
        scheduleLabel: s.label,
        scheduleTime:  s.time_start,
      });
    }
  }, [ schedules ] );

  // Tour de fecha fija: cuando se saltea el paso "date" (needsDateStep),
  // nada más carga form.date ni pide los horarios de ese día — es
  // exactamente lo que StepDate.selectDate() hacía al hacer clic en el
  // calendario, replicado acá porque ese componente nunca llega a montarse.
  useEffect( () => {
    if ( tour.fixed_date && ! form.date ) {
      patchForm({ date: tour.fixed_date });
      API.getDaySchedules( tour.id, tour.fixed_date )
        .then( setSchedules )
        .catch( () => setSchedules( [] ) );
    }
  }, [ tour.fixed_date ] );

  // "Armá tu tour" (tour.custom_quote): no hay calendario que consultar —
  // form.date solo necesita CUALQUIER valor no vacío para no bloquear el
  // resto del flujo (queda como placeholder server-side, ver
  // create_custom_quote_request()). Sin schedules que pedir.
  useEffect( () => {
    if ( tour.custom_quote && ! form.date ) {
      patchForm({ date: todayISO() });
      setSchedules( [] );
    }
  }, [ tour.custom_quote ] );

  // Fetch quote whenever people/schedule/date/extras changes — debounce +
  // guard contra respuestas fuera de orden (bug real 2026-08-09: una ráfaga
  // de taps en +/- de personas disparaba un request de cotización POR CLICK,
  // y la respuesta que llegaba última no siempre era la del último click).
  // El debounce baja el volumen de requests; quoteRequestRef descarta
  // cualquier respuesta que ya no sea la última pedida, aunque llegue fuera
  // de orden. Mismo patrón que TourDetailStep en DiscoveryFlow.jsx.
  const addonsKey = JSON.stringify( form.selectedAddons );
  const quoteKey = `${form.date}|${form.scheduleId}|${form.adults}|${form.children}|${form.babies}|${form.couponCode}|${addonsKey}`;
  const debouncedQuoteKey = useDebouncedValue( quoteKey, 350 );

  useEffect( () => {
    // "Armá tu tour": no hay precio que cotizar, StepPeople/StepSummary
    // muestran "A cotizar" en vez de un total — nunca llamar a la API.
    if ( tour.custom_quote ) return;
    if ( ! form.date || ( form.adults + form.children ) === 0 ) return;
    const sid = resolveScheduleId( form, schedules, tour.schedules ) ?? 0;
    const addons = Object.entries( form.selectedAddons )
      .filter( ( [ , qty ] ) => qty > 0 )
      .map( ( [ id, qty ] ) => ( { id: Number( id ), qty } ) );
    const requestId = ++quoteRequestRef.current;
    API.getQuote({
      tourId:     tour.id,
      scheduleId: sid,
      date:       form.date,
      adults:     form.adults,
      children:   form.children,
      babies:     form.babies,
      couponCode: form.couponCode,
      addons,
      lang,
    })
    .then( q => { if ( requestId === quoteRequestRef.current ) { setQuote( q ); setQuoteError( '' ); } } )
    // Bug real y grave 2026-08-12: esto tragaba CUALQUIER falla de cotización
    // en silencio (precio mal configurado, red caída, rate limit) — el
    // widget se quedaba sin el botón de continuar sin ningún aviso, ni para
    // el cliente final ni para el operador. Ahora el mensaje real del
    // backend (ver PricingEngine::quote_percapita()/quote_group()) se
    // muestra en StepPeople.
    .catch( e => { if ( requestId === quoteRequestRef.current ) { setQuote( null ); setQuoteError( e.message || t('err_generic') ); } } );
  }, [ debouncedQuoteKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

  const stepProps = { tour, form, patchForm, lang, setLang, t, goNext, goBack,
    availability, setAvailability, schedules, setSchedules,
    quote, quoteError, clientSecret, setClientSecret, bookingRef, setBookingRef,
    bookingId, setBookingId, mpData, setMpData, redsysData, setRedsysData,
    gateway, setGateway, step, setStep, activeSteps,
    finalTotalMxn, setFinalTotalMxn };

  const stepLabels = activeSteps.map( s => t( `step_${s}` ) );

  // Personalización → Widget de reserva → Estilo del widget. Mismos 7 pasos,
  // mismo estado/lógica — el estilo "fullwidth" es puro CSS (ver
  // .ab-style-fullwidth en widget.css), pedido explícito del cliente
  // 2026-08-16 ("cambio estético... la lógica es igual").
  const widgetStyle = ( typeof window !== 'undefined' ? window.amirBooking?.widgetStyle : undefined ) || 'classic';

  useScrollToErrorOnMobile( widgetRef );
  useScrollToTopOnChange( widgetRef, step );

  return (
    <div className={`ab-widget${widgetStyle === 'fullwidth' ? ' ab-style-fullwidth' : ''}`} ref={widgetRef}>
      <ProgressBar steps={stepLabels} stepKeys={activeSteps} current={step} />

      { stepName === 'date'     && <StepDate     {...stepProps} /> }
      { stepName === 'schedule' && <StepSchedule {...stepProps} /> }
      { stepName === 'people'   && <StepPeople   {...stepProps} /> }
      { stepName === 'extras'   && <StepExtras   {...stepProps} /> }
      { stepName === 'details'  && <StepDetails  {...stepProps} /> }
      { stepName === 'summary'  && <StepSummary  {...stepProps} /> }
      { stepName === 'payment'  && gateway === 'mercadopago' && (
          <StepPaymentMP {...stepProps} />
      )}
      { stepName === 'payment'  && gateway === 'redsys' && (
          <StepPaymentRedsys {...stepProps} />
      )}
      { stepName === 'payment'  && gateway === 'stripe' && (
          <Elements stripe={stripePromise} options={{ clientSecret, locale: lang }}>
            <StepPayment {...stepProps} />
          </Elements>
      )}
      { stepName === 'confirm'  && <StepConfirm  {...stepProps} /> }
    </div>
  );
}

// ── Progress Bar ──────────────────────────────────────────────────────────────
// Un ícono por paso en vez del número — más identificable de un vistazo,
// sobre todo cuando el texto está oculto (ver STEP_ICONS/showLabels abajo).
const STEP_ICONS = {
  date: '📅', schedule: '🕐', people: '👥', extras: '🎁',
  details: '📝', summary: '🧾', payment: '💳', confirm: '✅',
};

function ProgressBar({ steps, stepKeys, current }) {
  // Configuración → Widget de reserva → "Mostrar texto de los pasos".
  // Sin texto, los pasos se reparten parejo (ver .ab-progress-compact en
  // widget.css) y la línea de progreso queda perfectamente alineada.
  const showLabels = ( typeof window !== 'undefined' ? window.amirBooking?.progressLabels : undefined ) ?? true;
  const total = steps.length;
  const progressPct = total > 1 ? ( current / ( total - 1 ) ) * 100 : 0;

  return (
    <div className={`ab-progress${showLabels ? '' : ' ab-progress-compact'}`}>
      <div className="ab-progress-track">
        <div className="ab-progress-track-fill" style={{ width: `${progressPct}%` }} />
      </div>
      {steps.map( ( label, i ) => (
        <div key={i} className={`ab-step-dot ${i === current ? 'active' : i < current ? 'done' : ''}`}>
          <div className="ab-step-dot-circle">{i < current ? '' : ( STEP_ICONS[ stepKeys?.[i] ] || i + 1 )}</div>
          {showLabels && <div className="ab-step-dot-label">{label}</div>}
        </div>
      ))}
    </div>
  );
}

// ── Step 1: Calendar ──────────────────────────────────────────────────────────
function StepDate({ tour, form, patchForm, t, goNext, availability, setAvailability, setSchedules }) {
  const urlDate     = useState( getDateFromUrl )[0]; // una sola lectura, no cambia en la vida del componente
  const today       = new Date();
  const urlDateParts = urlDate ? urlDate.split( '-' ).map( Number ) : null;
  const [ viewYear, setViewYear ] = useState( urlDateParts ? urlDateParts[0] : today.getFullYear() );
  const [ viewMonth, setViewMonth ] = useState( urlDateParts ? urlDateParts[1] : today.getMonth() + 1 ); // 1-based
  const [ loadingMonth, setLoadingMonth ] = useState( false );
  const urlDateAttempted = useRef( false );

  const fetchMonth = useCallback( ( y, m ) => {
    setLoadingMonth( true );
    API.getMonthAvailability( tour.id, y, m )
      .then( data => { setAvailability( a => ( { ...a, ...data } ) ); setLoadingMonth( false ); } )
      .catch( () => setLoadingMonth( false ) );
  }, [ tour.id, setAvailability ] );

  useEffect( () => {
    fetchMonth( viewYear, viewMonth );
  }, [ viewYear, viewMonth ] );

  const navigate = ( dir ) => {
    let m = viewMonth + dir, y = viewYear;
    if ( m > 12 ) { m = 1; y++; }
    if ( m < 1  ) { m = 12; y--; }
    setViewMonth( m );
    setViewYear( y );
  };

  const selectDate = ( dateStr ) => {
    const info = availability[ dateStr ];
    if ( ! info?.available ) return;
    patchForm({ date: dateStr, scheduleId: null, scheduleLabel: '', scheduleTime: '' });

    // Fetch schedules for this date, then advance
    API.getDaySchedules( tour.id, dateStr )
      .then( loaded => {
        setSchedules( loaded );
        if ( loaded && loaded.length === 1 ) {
          // Auto-select single schedule and skip that step
          patchForm({
            scheduleId:    loaded[0].id,
            scheduleLabel: loaded[0].label,
            scheduleTime:  loaded[0].time_start,
          });
        }
        goNext();
      } )
      .catch( () => goNext() );
  };

  // ?date= en la URL ([flow_tour_dates], § 16.54 CONTRIBUTING.md) — apenas
  // llega la disponibilidad del mes que ya centramos arriba (viewYear/
  // viewMonth), seleccionar esa fecha sola si sigue disponible (pudo
  // venderse entre que se generó el link y que el visitante entró). Un
  // solo intento — si ya no está disponible, el calendario queda abierto
  // normal, sin insistir.
  useEffect( () => {
    if ( ! urlDate || urlDateAttempted.current || form.date ) return;
    if ( availability[ urlDate ]?.available ) {
      urlDateAttempted.current = true;
      selectDate( urlDate );
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ availability, urlDate ] );

  const daysInMonth  = new Date( viewYear, viewMonth, 0 ).getDate();
  const firstDow     = new Date( viewYear, viewMonth - 1, 1 ).getDay(); // 0=Sun
  const todayStr     = today.toISOString().slice( 0, 10 );
  const months = t( 'months' ); // t() con una sola clave y sin vars devuelve el array tal cual

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_date')}</p>
      {tour.request_only && (
        <p className="ab-age-notice">🙋 {t('request_only_notice')}</p>
      )}

      <div className="ab-cal-header">
        <button
          className="ab-cal-nav"
          onClick={() => navigate(-1)}
          disabled={ viewYear === today.getFullYear() && viewMonth <= today.getMonth() + 1 }
          aria-label={t('cal_prev')}
        >‹</button>
        <span className="ab-cal-month">
          {months[ viewMonth - 1 ]} {viewYear}
          {loadingMonth && <span style={{marginLeft:8, fontSize:11, opacity:.5}}>{t('loading')}</span>}
        </span>
        <button className="ab-cal-nav" onClick={() => navigate(1)} aria-label={t('cal_next')}>›</button>
      </div>

      <div className="ab-cal-grid">
        {t( 'days' ).map( d => (
          <div key={d} className="ab-cal-dow">{d}</div>
        ))}

        {/* Empty cells for first-row offset */}
        {Array.from({ length: firstDow }, ( _, i ) => (
          <div key={`e${i}`} className="ab-cal-day empty" />
        ))}

        {Array.from({ length: daysInMonth }, ( _, i ) => {
          const day     = i + 1;
          const dateStr = `${viewYear}-${String(viewMonth).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
          const info    = availability[ dateStr ];
          const isPast  = dateStr < todayStr;
          const status  = isPast ? 'past'
            : ! info         ? 'blocked'
            : ! info.available ? ( info.reason === 'full' ? 'full' : 'blocked' )
            : 'available';
          const isLow   = info?.available && info.slots > 0 && info.slots <= 3;
          const isSel   = form.date === dateStr;

          return (
            <button
              type="button"
              key={dateStr}
              className={`ab-cal-day ${status}${isSel ? ' selected' : ''}${isLow ? ' low-slots' : ''}`}
              disabled={ status !== 'available' }
              onClick={() => selectDate( dateStr )}
              aria-label={`${day} ${months[viewMonth-1]}: ${status}`}
              title={ info?.slots > 0 ? t('slots_left', { n: info.slots }) : '' }
            >
              {day}
            </button>
          );
        })}
      </div>

      <div className="ab-cal-legend">
        <span className="ab-cal-legend-item">
          <span className="ab-cal-legend-dot" style={{background:'#e1f5ee', border:'1px solid #1D9E75'}} />
          {t('cal_available')}
        </span>
        <span className="ab-cal-legend-item">
          <span className="ab-cal-legend-dot" style={{background:'#fef9ec', border:'1px solid #BA7517'}} />
          {t('cal_low_slots')}
        </span>
        <span className="ab-cal-legend-item">
          <span className="ab-cal-legend-dot" style={{background:'#f3f4f6'}} />
          {t('cal_blocked')}
        </span>
      </div>
    </div>
  );
}

// ── Step 2: Schedule Selector ─────────────────────────────────────────────────
function StepSchedule({ form, patchForm, t, goNext, goBack, schedules }) {
  const fmtTime = ( t24 ) => {
    const [ h, m ] = t24.split(':');
    const hNum = parseInt( h );
    const ampm = hNum >= 12 ? 'PM' : 'AM';
    const h12  = hNum > 12 ? hNum - 12 : hNum === 0 ? 12 : hNum;
    return `${h12}:${m} ${ampm}`;
  };

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_schedule')}</p>

      <div className="ab-schedules">
        { !schedules || schedules.length === 0 ? (
          <p style={{textAlign:'center',color:'#5a7068',fontSize:'14px',padding:'20px 0'}}>
            { t('no_schedules') || 'No hay salidas disponibles para esta fecha.' }
          </p>
        ) : schedules.map( s => {
          const available = s.available !== false;
          const isSel     = form.scheduleId === s.id;
          const slotsClass = s.slots_remaining <= 0 ? 'full' : s.slots_remaining <= 3 ? 'low' : '';
          return (
            <button
              key={s.id}
              className={`ab-schedule-btn${isSel ? ' selected' : ''}`}
              disabled={!available}
              onClick={() => {
                patchForm({ scheduleId: s.id, scheduleLabel: s.label, scheduleTime: s.time_start });
                goNext();
              }}
            >
              <div>
                <div className="ab-schedule-time">{fmtTime(s.time_start)} – {fmtTime(s.time_end)}</div>
                {s.label && <div className="ab-schedule-label">{s.label}</div>}
              </div>
              {available && s.slots_remaining > 0 && (
                <span className={`ab-schedule-slots ${slotsClass}`}>
                  {s.slots_remaining <= 5 ? t('slots_left', { n: s.slots_remaining }) : ''}
                </span>
              )}
              {!available && <span className="ab-schedule-slots full">{t('cal_full')}</span>}
            </button>
          );
        }) }
      </div>

      <div className="ab-btn-row">
        <button className="ab-btn ab-btn-ghost" onClick={goBack}>← {t('back')}</button>
      </div>
    </div>
  );
}

// ── Step 3: People Counter ────────────────────────────────────────────────────
// ── Solicitar otra fecha (tours de fecha fija) ──────────────────────────────
// Solo se muestra cuando tour.fixed_date está cargado — ver needsDateStep()
// arriba. Sin cobro: crea una reserva 'date_requested' (BookingManager::
// create_date_request()) que el operador aprueba o rechaza manualmente
// desde Reservas; si aprueba, se manda un link de pago por email — mismo
// mecanismo que Lista de interés, no el de proveedores externos.
function RequestDateBanner({ tour, form, lang, t }) {
  const [ open,     setOpen     ] = useState( false );
  const [ sent,     setSent     ] = useState( false );
  const [ sending,  setSending  ] = useState( false );
  const [ error,    setError    ] = useState( '' );
  const [ date,     setDate     ] = useState( '' );
  const [ name,     setName     ] = useState( '' );
  const [ email,    setEmail    ] = useState( '' );
  const [ phone,    setPhone    ] = useState( '' );
  const [ notes,    setNotes    ] = useState( '' );

  if ( ! tour.fixed_date ) return null;

  if ( sent ) {
    return (
      <div className="ab-request-date ab-request-date-sent">
        <strong>{t('request_date_sent_title')}</strong>
        <p>{t('request_date_sent_sub')}</p>
      </div>
    );
  }

  const canSubmit = date !== '' && name.trim() !== '' && email.trim() !== '' && ! sending;

  const submit = ( e ) => {
    e.preventDefault();
    if ( ! canSubmit ) return;
    setSending( true );
    setError( '' );
    API.requestDate( tour.id, {
      date,
      customer_name:  name,
      customer_email: email,
      customer_phone: phone,
      special_requests: notes,
      adults:         form.adults,
      children:       form.children,
      babies:         form.babies,
      lang,
    } )
      .then( () => setSent( true ) )
      .catch( err => setError( err.message || t('something_wrong') ) )
      .finally( () => setSending( false ) );
  };

  return (
    <div className="ab-request-date">
      { ! open ? (
        <button type="button" className="ab-request-date-toggle" onClick={() => setOpen(true)}>
          {t('request_date_link')}
        </button>
      ) : (
        <form className="ab-request-date-form" onSubmit={submit}>
          <p className="ab-request-date-desc">{t('request_date_desc')}</p>
          <div className="ab-form-group">
            <label className="ab-label" htmlFor="ab-req-date">{t('request_date_field')}</label>
            <input
              id="ab-req-date" type="date" className="ab-input"
              min={new Date(Date.now() + 86400000).toISOString().slice(0,10)}
              value={date} onChange={ e => setDate( e.target.value ) } required
            />
          </div>
          <CustomerField name="reqName"  label={t('your_name')}  ph={t('your_name')}  value={name}  onChange={setName} />
          <CustomerField name="reqEmail" label={t('your_email')} ph={t('email_ph')}   type="email"  value={email} onChange={setEmail} />
          <CustomerField name="reqPhone" label={t('phone_optional')} ph={t('phone_ph')} type="tel"   value={phone} onChange={setPhone} />
          <div className="ab-form-group">
            <label className="ab-label" htmlFor="ab-req-notes">{t('special_req')}</label>
            <textarea
              id="ab-req-notes" className="ab-textarea"
              placeholder={t('special_req_ph')}
              value={notes} onChange={ e => setNotes( e.target.value ) }
            />
          </div>
          { error && <p className="ab-field-error">{error}</p> }
          <div style={{display:'flex', gap:8}}>
            <button type="submit" className="ab-btn ab-btn-primary" style={{flex:1}} disabled={!canSubmit}>
              { sending ? t('sending') : t('request_date_submit') }
            </button>
            <button type="button" className="ab-btn ab-btn-outline" style={{flex:'0 0 auto'}} onClick={() => setOpen(false)}>
              {t('request_date_cancel')}
            </button>
          </div>
        </form>
      ) }
    </div>
  );
}

function StepPeople({ tour, form, patchForm, lang, t, goNext, goBack, quote, quoteError }) {
  const isGroup     = tour.price_model === 'group';
  const prices      = tour.prices ?? [];
  const maxCapacity = tour.max_capacity || 99;

  const getPrice = ( type ) => {
    const p = prices.find( p => p.person_type === type );
    return p ? p.price_mxn : null;
  };

  const getGroupPrice = ( total ) => {
    const p = prices.find( p =>
      p.person_type === 'group' && total >= p.group_min && total <= p.group_max
    );
    return p ? p.price_mxn : null;
  };

  const total = form.adults + form.children + form.babies;

  // Mínimo de personas POR RESERVA (amir_tours.min_passengers) — pedido del
  // cliente 2026-08-21. Distinto del mínimo AGREGADO que ya usa el cron
  // (class-cron-manager.php, suma TODAS las reservas de una salida para
  // avisar si conviene cancelar) — este es el piso de UNA reserva puntual.
  // Mismo campo reusado a propósito (ya existe, ya es editable, ya viaja en
  // la API) — se compara contra pax total (adultos+niños+bebés), mismo
  // criterio que ya usa el cron para ese mismo campo.
  const minPax = Math.max( 1, parseInt( tour.min_passengers, 10 ) || 1 );
  const belowMinPax = total < minPax;

  const fmtMXN = ( n ) => n === 0
    ? t('free')
    : `$${n.toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;

  // Bug real 2026-08-12: antes se podía seguir a Resumen/Pago aunque la
  // cotización hubiera fallado (ej. precio mal configurado a $0) — Resumen
  // calculaba un total local de respaldo que también daba $0, sin ningún
  // aviso, y el pago fallaba (o peor, "confirmaba" algo sin cobrar nada).
  // Ahora un error de cotización bloquea seguir, con el mensaje real visible.
  const canContinue = ( isGroup ? total >= 1 : form.adults >= 1 ) && ! belowMinPax && ! quoteError;

  if ( isGroup ) {
    // Group model: single counter 1–4
    const maxGroup = Math.max( ...prices.filter( p => p.person_type === 'group' ).map( p => p.group_max ), 4 );
    const gPrice   = getGroupPrice( total );

    return (
      <div className="ab-panel">
        <p className="ab-panel-title">{t('group_people')}</p>
        {tour.min_age > 0 && (
          <p className="ab-age-notice">👤 {t('min_age_label')}: {tour.min_age}+</p>
        )}

        <div style={{display:'flex', alignItems:'center', justifyContent:'space-between', padding:'20px 0'}}>
          <div>
            <div className="ab-people-label">{t('people_label')}</div>
            <div className="ab-people-sublabel">{t('max_people', { n: maxGroup })}</div>
          </div>
          <Counter
            value={total}
            min={1}
            max={maxGroup}
            onChange={ v => patchForm({ adults: v, children: 0, babies: 0 }) }
          />
        </div>

        {gPrice !== null && (
          <div className="ab-price-total">
            <div className="ab-price-total-label">{t('total')}</div>
            <div className="ab-price-total-amount">
              <div className="ab-price-mxn">{fmtMXN(gPrice)}</div>
              {quote?.usd_reference > 0 && (
                <div className="ab-price-usd">{t('usd_ref', { amount: quote.usd_reference.toFixed(0) })}</div>
              )}
            </div>
          </div>
        )}

        {belowMinPax && <p className="ab-age-notice">👥 {t('min_pax_notice', { n: minPax })}</p>}
        {quoteError && <div className="ab-error-banner">⚠ {quoteError}</div>}

        <RequestDateBanner tour={tour} form={form} lang={lang} t={t} />

        <NavRow t={t} goBack={goBack} onNext={goNext} disabled={!canContinue} />
      </div>
    );
  }

  // Per-capita model — filtrar según configuración del tour
  const allowChildren = tour.allow_children !== false;  // true por defecto
  const allowBabies   = tour.allow_babies   !== false;  // true por defecto
  const minAgeChild   = tour.min_age_child  ?? 4;

  const rows = [
    { key:'adults',   label: t('adults'),   sub: t('adults_age'),   price: getPrice('adult'), min:1, max:maxCapacity },
    allowChildren && { key:'children', label: t('children'), sub: `${minAgeChild}–12 ${t('years') || 'años'}`, price: getPrice('child'), min:0, max:maxCapacity },
    allowBabies   && { key:'babies',   label: t('babies'),   sub: t('babies_age'),  price: 0, min:0, max:5, free:true },
  ].filter( Boolean ).filter( r => r.price !== null || r.free || tour.custom_quote );

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_people')}</p>
      {tour.min_age > 0 && (
        <p className="ab-age-notice">👤 {t('min_age_label')}: {tour.min_age}+</p>
      )}

      <div className="ab-people-list">
        {rows.map( r => (
          <div key={r.key} className="ab-people-row">
            <div className="ab-people-info">
              <div className="ab-people-label">{r.label}</div>
              <div className="ab-people-sublabel">{r.sub}</div>
            </div>
            {! tour.custom_quote && (
              <div className="ab-people-price">
                {r.free ? <span style={{color:'var(--ab-teal)'}}>{t('free')}</span> : `$${r.price?.toLocaleString('es-MX')} ${t('per_person')}`}
              </div>
            )}
            <Counter
              value={form[r.key]}
              min={r.min}
              max={Math.min(r.max, maxCapacity - total + form[r.key])}
              onChange={ v => patchForm({ [r.key]: v }) }
            />
          </div>
        ))}
      </div>

      {tour.custom_quote ? (
        <div className="ab-price-total">
          <div className="ab-price-total-label">{t('total')}</div>
          <div className="ab-price-total-amount">
            <div className="ab-price-mxn">{t('to_be_quoted')}</div>
          </div>
        </div>
      ) : quote?.total_mxn > 0 && (
        <div className="ab-price-total">
          <div className="ab-price-total-label">{t('total')}</div>
          <div className="ab-price-total-amount">
            <div className="ab-price-mxn">{fmtMXN(quote.total_mxn)}</div>
            {quote.usd_reference > 0 && (
              <div className="ab-price-usd">{t('usd_ref', { amount: quote.usd_reference.toFixed(0) })}</div>
            )}
          </div>
        </div>
      )}

      {tour.custom_quote && <p className="ab-age-notice">{t('custom_quote_notice')}</p>}
      {belowMinPax && <p className="ab-age-notice">👥 {t('min_pax_notice', { n: minPax })}</p>}

      {quoteError && <div className="ab-error-banner">⚠ {quoteError}</div>}

      <RequestDateBanner tour={tour} form={form} lang={lang} t={t} />

      <NavRow t={t} goBack={goBack} onNext={goNext} disabled={!canContinue} />
    </div>
  );
}

// ── Step: Servicios extra (add-ons) ─────────────────────────────────────────
function StepExtras({ tour, form, patchForm, t, goNext, goBack }) {
  const addons    = tour.addons ?? [];
  const peopleCap = form.adults + form.children;
  const currency  = ( typeof window !== 'undefined' && window.amirBooking?.currency ) || 'MXN';

  const fmt = ( n ) => `$${n.toLocaleString('es-MX')} ${currency}`;

  const setQty = ( addonId, qty ) => {
    patchForm({ selectedAddons: { ...form.selectedAddons, [addonId]: qty } });
  };

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('extras_title')}</p>

      <div className="ab-people-list">
        {addons.map( a => {
          const qty = form.selectedAddons[ a.id ] ?? 0;
          return (
            <div key={a.id} className={`ab-people-row${qty > 0 ? ' selected' : ''}`}>
              <div className="ab-people-info">
                <div className="ab-people-label">🎁 {a.name}</div>
              </div>
              <div className="ab-people-price">{fmt(a.price_mxn)}</div>
              { a.pricing_type === 'flat' ? (
                <label style={{display:'flex', alignItems:'center', gap:6, cursor:'pointer'}}>
                  <input
                    type="checkbox"
                    checked={qty > 0}
                    onChange={ e => setQty( a.id, e.target.checked ? 1 : 0 ) }
                    style={{width:18, height:18, accentColor:'var(--ab-teal)'}}
                  />
                  {t('extras_included')}
                </label>
              ) : (
                <Counter
                  value={qty}
                  min={0}
                  max={peopleCap}
                  onChange={ v => setQty( a.id, v ) }
                />
              )}
            </div>
          );
        } )}
      </div>

      <NavRow t={t} goBack={goBack} onNext={goNext} />
    </div>
  );
}

// ── Campo de formulario reutilizable ───────────────────────────────────────────
// IMPORTANTE: este componente vive a nivel de módulo, NUNCA dentro de otro
// componente. Si se define dentro de StepDetails (como estaba antes), React lo
// trata como un tipo de componente nuevo en cada render del padre —cada tecla
// tipeada actualiza el form y re-renderiza StepDetails— y desmonta/remonta el
// <input>, perdiendo el foco después de cada carácter. Bug real reportado en
// mobile; ver CONTRIBUTING.md. Recibe value/error/onChange como props en vez
// de capturarlos por closure para que su identidad no cambie entre renders.
function CustomerField({ name, label, ph, type = 'text', autoComplete, value, error, onChange }) {
  return (
    <div className="ab-form-group">
      <label className="ab-label" htmlFor={`ab-${name}`}>{label}</label>
      <input
        id={`ab-${name}`}
        type={type}
        className={`ab-input${error ? ' error' : ''}`}
        placeholder={ph}
        value={value}
        autoComplete={autoComplete}
        inputMode={type === 'tel' ? 'tel' : type === 'email' ? 'email' : 'text'}
        onChange={ e => onChange( e.target.value ) }
      />
      {error && <p className="ab-field-error">{error}</p>}
    </div>
  );
}

// ── Step 4: Customer Details ──────────────────────────────────────────────────
function StepDetails({ tour, form, patchForm, lang, setLang, t, goNext, goBack }) {
  const [ errors, setErrors ] = useState({});

  // "Requiere nombre de cada integrante" (pedido 2026-08-24) — bebés
  // quedan afuera a propósito, mismo criterio que la validación server-side
  // (BookingManager::participant_names_error()). N inputs, uno por adulto+niño.
  const namesNeeded  = tour.require_participant_names ? ( form.adults + form.children ) : 0;
  const participantNames = form.participantNames ?? [];

  const patchParticipantName = ( i, value ) => {
    const next = [ ...participantNames ];
    next[i] = value;
    patchForm({ participantNames: next });
    if ( errors.participantNames ) setErrors( er => ({ ...er, participantNames: '' }) );
  };

  const validate = () => {
    const e = {};
    if ( !form.customerName.trim() )  e.customerName = t('err_required');
    if ( !form.customerEmail.trim() ) e.customerEmail = t('err_required');
    else if ( !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.customerEmail) ) e.customerEmail = t('err_email');
    if ( !form.customerPhone.trim() ) e.customerPhone = t('err_required');
    if ( namesNeeded > 0 ) {
      const filled = participantNames.slice( 0, namesNeeded ).filter( n => (n ?? '').trim() ).length;
      if ( filled < namesNeeded ) e.participantNames = t('err_participant_names');
    }
    // "Armá tu tour" no tiene ningún otro lugar donde el cliente describa lo
    // que quiere — a diferencia del resto de los tours, acá es obligatorio.
    if ( tour.custom_quote && !form.specialRequests.trim() ) e.specialRequests = t('err_required');
    setErrors( e );
    return Object.keys(e).length === 0;
  };

  const handleNext = () => { if ( validate() ) goNext(); };

  const patchField = ( name, value ) => {
    patchForm({ [name]: value });
    if ( errors[name] ) setErrors( er => ({ ...er, [name]: '' }) );
  };

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_details')}</p>

      <CustomerField name="customerName"  label={t('full_name')} ph={t('full_name_ph')} autoComplete="name"
        value={form.customerName} error={errors.customerName} onChange={ v => patchField('customerName', v) } />
      <CustomerField name="customerEmail" label={t('email')}     ph={t('email_ph')}     type="email" autoComplete="email"
        value={form.customerEmail} error={errors.customerEmail} onChange={ v => patchField('customerEmail', v) } />
      <CustomerField name="customerPhone" label={t('phone')}     ph={t('phone_ph')}     type="tel"   autoComplete="tel"
        value={form.customerPhone} error={errors.customerPhone} onChange={ v => patchField('customerPhone', v) } />

      { namesNeeded > 0 && (
        <div className="ab-form-group">
          <label className="ab-label">{t('participant_names_label')}</label>
          <p style={{fontSize:'11px',color:'#5a7068',margin:'0 0 8px'}}>{t('participant_names_hint')}</p>
          { Array.from( { length: namesNeeded }, ( _, i ) => (
            <input
              key={i}
              type="text"
              className="ab-input"
              style={{marginBottom:'8px'}}
              placeholder={t('participant_names_ph', { n: i + 1 })}
              value={participantNames[i] ?? ''}
              onChange={ e => patchParticipantName( i, e.target.value ) }
            />
          ) ) }
          {errors.participantNames && <p className="ab-field-error">{errors.participantNames}</p>}
        </div>
      ) }

      <div className="ab-form-group">
        <label className="ab-label">{t('lang_pref')}</label>
        <div className="ab-lang-toggle">
          { ( window.amirBooking?.activeLanguages ?? [ 'es', 'en' ] ).map( code => (
            <button
              key={code}
              type="button"
              className={`ab-lang-btn${(form.lang ?? lang) === code ? ' active' : ''}`}
              onClick={() => { patchForm({ lang: code }); setLang(code); }}
            >{ code === 'es' ? t('lang_es') : code === 'en' ? t('lang_en') : code.toUpperCase() }</button>
          ) ) }
        </div>
        <p style={{fontSize:'11px',color:'#5a7068',marginTop:'4px'}}>
          {t('lang_pref_hint')}
        </p>
      </div>

      <div className="ab-form-group">
        <label className="ab-label" htmlFor="ab-special">
          {tour.custom_quote ? t('special_req_custom') : t('special_req')}
        </label>
        <textarea
          id="ab-special"
          className="ab-textarea"
          placeholder={tour.custom_quote ? t('special_req_custom_ph') : t('special_req_ph')}
          value={form.specialRequests}
          onChange={ e => { patchForm({ specialRequests: e.target.value } ); if ( errors.specialRequests ) setErrors( er => ({ ...er, specialRequests: '' }) ); } }
        />
        {errors.specialRequests && <p className="ab-field-error">{errors.specialRequests}</p>}
      </div>

      <NavRow t={t} goBack={goBack} onNext={handleNext} />
    </div>
  );
}

// ── Step 5: Summary + Policy ──────────────────────────────────────────────────
function StepSummary({ tour, form, patchForm, t, lang, goNext, goBack, quote,
                        setClientSecret, setBookingId, setBookingRef, setMpData, setGateway, schedules,
                        setFinalTotalMxn, activeSteps, setStep }) {
  const [ creating,    setCreating    ] = useState( false );
  const [ error,       setError       ] = useState( '' );
  // Marketplace de proveedores en modo "cobro diferido" (§ 11.0 CONTRIBUTING.md):
  // la reserva se manda a aprobación sin cobrar nada todavía — no hay pago
  // que completar, así que este paso termina acá mismo en vez de avanzar al
  // paso de pago.
  const [ requestSent, setRequestSent ] = useState( false );
  const [ sentRef,     setSentRef     ] = useState( '' );

  const fmtDate = ( dateStr ) => {
    const [ y, m, d ] = dateStr.split('-');
    const months = t( 'months' ); // sin vars, t() devuelve el array tal cual
    return `${parseInt(d)} ${months?.[parseInt(m)-1]} ${y}`;
  };

  const fmtTime = ( t24 ) => {
    if ( !t24 ) return '';
    const [ h, m ] = t24.split(':');
    const hNum = parseInt(h);
    return `${hNum > 12 ? hNum-12 : hNum || 12}:${m} ${hNum >= 12 ? 'PM' : 'AM'}`;
  };

  const paxSummary = tour.price_model === 'group'
    ? `${form.adults} ${t('people_label')}`
    : [
        form.adults   > 0 ? `${form.adults} ${t('adults')}`   : '',
        form.children > 0 ? `${form.children} ${t('children')}` : '',
        form.babies   > 0 ? `${form.babies} ${t('babies')}`   : '',
      ].filter(Boolean).join(' · ');

  const handleConfirm = async () => {
    if ( !form.policyAccepted || !form.termsAccepted ) return;
    setCreating( true );
    setError( '' );
    try {
      const partnerToken = document.cookie
        .split(';').map(c=>c.trim())
        .find(c=>c.startsWith('amir_partner_ref='))
        ?.split('=')[1] ?? '';

      const result = await API.createBooking({
        tour_id:          tour.id,
        schedule_id:      resolveScheduleId( form, schedules, tour.schedules ) ?? 0,
        date:             form.date,
        adults:           form.adults,
        children:         form.children,
        babies:           form.babies,
        customer_name:    form.customerName,
        customer_email:   form.customerEmail,
        customer_phone:   form.customerPhone,
        lang:             form.lang ?? lang,
        partner_token:    partnerToken,
        special_requests: form.specialRequests,
        participant_names: form.participantNames ?? [],
        coupon_code:      form.couponCode ?? '',
        policy_accepted:  form.policyAccepted,
        terms_accepted:   form.termsAccepted,
        addons: Object.entries( form.selectedAddons ?? {} )
          .filter( ( [ , qty ] ) => qty > 0 )
          .map( ( [ id, qty ] ) => ( { id: Number( id ), qty } ) ),
      });

      setBookingId( result.booking_id );
      setFinalTotalMxn( result.total_mxn ?? 0 );
      // Se guarda ACÁ, apenas se crea la reserva — no esperar a que
      // confirm-payment responda para tener un número de referencia. Si esa
      // llamada falla más adelante (red, 5xx), StepConfirm igual puede
      // mostrar la referencia real en vez de "—" (bug real corregido
      // 2026-08-09, auditoría de pagos pedida por el cliente).
      setBookingRef( result.booking_ref );

      if ( result.requires_payment === false ) {
        if ( result.status === 'confirmed' ) {
          // Cupón que cubre el 100% del total (u otro caso futuro que
          // confirme sin cobrar) — la reserva ya quedó 'confirmed' de una
          // en el backend (ver BookingManager::create_pending()), nada que
          // pagar. Mostrar la confirmación real, no "solicitud enviada".
          setCreating( false );
          setStep( activeSteps.indexOf( 'confirm' ) );
          return;
        }
        // Tour de proveedor en modo "cobro diferido" — nada que pagar
        // todavía, la reserva quedó esperando que el proveedor apruebe.
        setRequestSent( true );
        setSentRef( result.booking_ref );
        setCreating( false );
        return;
      }

      if ( result.gateway === 'mercadopago' ) {
        // MP: redirigir al checkout de MercadoPago
        setGateway( 'mercadopago' );
        setMpData({
          preference_id:      result.preference_id,
          init_point:         result.init_point,
          sandbox_init_point: result.sandbox_init_point,
        });
        goNext();
      } else if ( result.gateway === 'redsys' ) {
        // Redsys: conexión por redirección — a diferencia de MP no hay una
        // URL de checkout lista, hay que armar y enviar un form POST firmado
        // (ver StepPaymentRedsys más abajo).
        setGateway( 'redsys' );
        setRedsysData({
          action_url:          result.redsys_action_url,
          signature_version:   result.redsys_signature_version,
          merchant_parameters: result.redsys_merchant_parameters,
          signature:           result.redsys_signature,
        });
        goNext();
      } else {
        // Stripe: montar el PaymentElement con el client_secret
        setGateway( 'stripe' );
        setClientSecret( result.client_secret );
        goNext();
      }
    } catch ( e ) {
      setError( e.message || t('err_generic') );
    } finally {
      setCreating( false );
    }
  };

  if ( requestSent ) {
    // Dos motivos distintos llegan acá con requires_payment===false: tour de
    // proveedor externo en cobro diferido (ya existía) y tour propio "solo a
    // pedido" (nuevo) — el texto del proveedor menciona "operador local", no
    // aplica a un tour propio.
    return (
      <div className="ab-panel" style={{textAlign:'center'}}>
        <div className="ab-confirm-icon">📨</div>
        <h2 className="ab-confirm-title">
          {t( tour.request_only ? 'own_request_sent_title' : 'provider_request_sent_title' )}
        </h2>
        <p className="ab-confirm-sub">
          {t( tour.request_only ? 'own_request_sent_sub' : 'provider_request_sent_sub' )}
        </p>
        <div className="ab-ref-box">
          <div className="ab-ref-label">{t('booking_ref')}</div>
          <div className="ab-ref-value">{sentRef || '—'}</div>
        </div>
      </div>
    );
  }

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_summary')}</p>

      <div className="ab-summary-card">
        {tour.gallery_images?.[0] && (
          <img src={tour.gallery_images[0]} alt={tour.name} className="ab-summary-cover" loading="lazy" />
        )}
        <div className="ab-summary-body">
          <p className="ab-summary-tour-name">{tour.name}</p>

          {! tour.custom_quote && (
            <>
              <div className="ab-summary-row">
                <span className="ab-summary-row-label">{t('tour_date')}</span>
                <span className="ab-summary-row-value">{fmtDate(form.date)}</span>
              </div>
              <div className="ab-summary-row">
                <span className="ab-summary-row-label">{t('departure_time')}</span>
                <span className="ab-summary-row-value">{fmtTime(form.scheduleTime)}</span>
              </div>
            </>
          )}
          <div className="ab-summary-row">
            <span className="ab-summary-row-label">{t('people_label')}</span>
            <span className="ab-summary-row-value">{paxSummary}</span>
          </div>
          {tour.custom_quote && form.specialRequests && (
            <div className="ab-summary-row">
              <span className="ab-summary-row-label">{t('special_req_custom')}</span>
              <span className="ab-summary-row-value" style={{whiteSpace:'pre-wrap'}}>{form.specialRequests}</span>
            </div>
          )}
          {tour.meeting_point && (
            <div className="ab-summary-row">
              <span className="ab-summary-row-label">{t('meeting_point')}</span>
              <div>
                <span className="ab-summary-row-value" style={{display:'block'}}>{tour.meeting_point}</span>
                {tour.meeting_lat && (
                  <a
                    href={`https://maps.google.com/?q=${tour.meeting_lat},${tour.meeting_lng}`}
                    target="_blank" rel="noopener noreferrer"
                    className="ab-meeting-link"
                  >↗ {t('open_maps')}</a>
                )}
              </div>
            </div>
          )}
          {quote?.breakdown?.map( ( b, i ) => (
            <div key={i} className="ab-summary-row">
              <span className="ab-summary-row-label">
                {b.type === 'group' ? `Grupo (${b.qty} pax)` :
                 b.type === 'adult' ? `${b.qty} × ${t('adults')}` :
                 b.type === 'child' ? `${b.qty} × ${t('children')}` :
                 b.type === 'addon' ? `${b.qty} × ${b.name}` :
                 `${b.qty} × ${t('babies')}`}
              </span>
              <span className="ab-summary-row-value">
                ${b.total_mxn.toLocaleString('es-MX')} {window.amirBooking?.currency ?? 'MXN'}
              </span>
            </div>
          ))}
          { tour.custom_quote ? (
            <div className="ab-summary-row" style={{fontWeight:700,borderTop:'2px solid var(--ab-teal)',marginTop:4,paddingTop:10}}>
              <span className="ab-summary-row-label" style={{color:'var(--ab-text)',fontWeight:700}}>{t('total')}</span>
              <span className="ab-summary-row-value" style={{fontSize:17}}>{t('to_be_quoted')}</span>
            </div>
          ) : (() => {
            // Calcular total localmente si el quote no llegó aún
            const totalMxn = quote?.total_mxn ?? ( () => {
              const prices = tour.prices ?? [];
              const adultP = prices.find(p => p.person_type === 'adult')?.price_mxn ?? 0;
              const childP = prices.find(p => p.person_type === 'child')?.price_mxn ?? 0;
              const babyP  = prices.find(p => p.person_type === 'baby')?.price_mxn  ?? 0;
              return form.adults * adultP + form.children * childP + form.babies * babyP;
            })();
            const usdRef = quote?.usd_reference ?? null;
            // Depósito parcial por tour ("Depósito parcial por tour",
            // CONTRIBUTING.md) — el backend (BookingManager::create_pending())
            // ya cobra solo el % configurado sin tocar nada más (motor
            // agnóstico de edición), esto SOLO es la aclaración visual que
            // faltaba en el widget clásico (antes solo Pro Max la mostraba).
            // Auditoría de UX + pedido explícito del cliente.
            const hasDeposit = ( quote?.deposit_pct ?? 0 ) > 0;
            return (
              <>
                <div className="ab-summary-row" style={{fontWeight:700,borderTop:'2px solid var(--ab-teal)',marginTop:4,paddingTop:10}}>
                  <span className="ab-summary-row-label" style={{color:'var(--ab-text)',fontWeight:700}}>
                    {hasDeposit ? t('deposit_label') : t('total')}
                  </span>
                  <span className="ab-summary-row-value" style={{fontSize:17}}>
                    ${(hasDeposit ? quote.deposit_mxn : totalMxn).toLocaleString('es-MX')} {window.amirBooking?.currency ?? 'MXN'}
                    {usdRef > 0 && !hasDeposit && (
                      <span style={{display:'block', fontSize:11, fontWeight:400, color:'var(--ab-muted)'}}>
                        {t('usd_ref', { amount: usdRef.toFixed(0) })}
                      </span>
                    )}
                  </span>
                </div>
                {hasDeposit && (
                  <div className="ab-summary-row" style={{fontSize:12,color:'var(--ab-muted)',marginTop:2}}>
                    <span>{t('deposit_balance_note', { amount: `$${quote.remaining_mxn.toLocaleString('es-MX')} ${window.amirBooking?.currency ?? 'MXN'}` })}</span>
                  </div>
                )}
              </>
            );
          })() }
        </div>
      </div>

      <div className="ab-form-group" style={{marginTop:12}}>
        <label className="ab-label" htmlFor="ab-coupon">{t('coupon_label')}</label>
        <input
          id="ab-coupon"
          type="text"
          className="ab-input"
          style={{textTransform:'uppercase'}}
          placeholder={t('coupon_ph')}
          value={form.couponCode ?? ''}
          onChange={ e => patchForm({ couponCode: e.target.value.toUpperCase() }) }
        />
        {quote?.coupon_code && (
          <p style={{fontSize:12,color:'var(--ab-teal-dark)',marginTop:4,fontWeight:600}}>
            ✓ {t('coupon_applied')} -${(quote.discount_mxn ?? 0).toLocaleString('es-MX')} {window.amirBooking?.currency ?? 'MXN'}
          </p>
        )}
        {quote?.coupon_error && <p className="ab-field-error">{quote.coupon_error}</p>}
      </div>

      {tour.request_only && (
        <p className="ab-age-notice">🙋 {t( tour.custom_quote ? 'custom_quote_notice' : 'request_only_notice' )}</p>
      )}

      <div className="ab-policy-box">
        <div className="ab-policy-title">📋 {t('policy_title')}</div>
        {(() => {
          const customText = lang === 'en'
            ? window.amirBooking?.policyTextEn
            : window.amirBooking?.policyTextEs;
          if ( customText ) {
            return customText.split('\n').filter(Boolean).map( (line, i) => (
              <div className="ab-policy-line" key={i}>{line}</div>
            ) );
          }
          return (
            <>
              <div className="ab-policy-line">{t('policy_line1')}</div>
              <div className="ab-policy-line">{t('policy_line2')}</div>
              <div className="ab-policy-line">{t('policy_line3')}</div>
            </>
          );
        })()}
      </div>

      <label className="ab-policy-check">
        <input
          type="checkbox"
          checked={form.policyAccepted}
          onChange={ e => patchForm({ policyAccepted: e.target.checked }) }
        />
        <span className="ab-policy-check-label">{t('policy_accept')}</span>
      </label>

      {(() => {
        // Bug real reportado por el cliente: sin texto configurado en
        // Configuración, esto devolvía null — el checkbox de "acepto
        // términos y condiciones" quedaba flotando sin ningún contenido
        // arriba, como si no estuviera "enlazado" a nada. Mismo criterio
        // que la política de cancelación (arriba): nunca queda vacío,
        // cae a un texto genérico por defecto en vez de desaparecer.
        const customTerms = lang === 'en'
          ? window.amirBooking?.termsTextEn
          : window.amirBooking?.termsTextEs;
        const lines = customTerms ? customTerms.split('\n').filter(Boolean) : [ t('terms_default') ];
        return (
          <div className="ab-policy-box">
            <div className="ab-policy-title">📜 {t('terms_title')}</div>
            {lines.map( (line, i) => (
              <div className="ab-policy-line" key={i}>{line}</div>
            ) )}
          </div>
        );
      })()}

      <label className="ab-policy-check">
        <input
          type="checkbox"
          checked={form.termsAccepted}
          onChange={ e => patchForm({ termsAccepted: e.target.checked }) }
        />
        <span className="ab-policy-check-label">{t('terms_accept')}</span>
      </label>

      {error && <div className="ab-error-banner">⚠ {error}</div>}

      <div className="ab-btn-row">
        <button className="ab-btn ab-btn-ghost" onClick={goBack} disabled={creating}>← {t('back')}</button>
        <button
          className="ab-btn ab-btn-primary"
          onClick={handleConfirm}
          disabled={!form.policyAccepted || !form.termsAccepted || creating}
        >
          {creating
            ? <><div className="ab-spinner" style={{width:16,height:16,borderWidth:2}} />{t('processing')}</>
            : t( tour.request_only ? 'send_request' : 'book_now' )}
        </button>
      </div>
    </div>
  );
}

// ── Step 6: Stripe Payment ────────────────────────────────────────────────────
// (exportado: PayBooking.jsx lo reutiliza para pagar una reserva ya cargada
// vía link, fuera del flujo de pasos normal de BookingWidget)
export function StepPayment({ t, goBack, goNext, setBookingRef, bookingId, bookingRef }) {
  const stripe   = useStripe();
  const elements = useElements();
  const [ processing, setProcessing ] = useState( false );
  const [ error,      setError      ] = useState( '' );
  // Pago ya cobrado por Stripe (dinero real, no hay vuelta atrás) pero
  // nuestro backend todavía no lo confirmó — nunca se llega acá fingiendo
  // que ya está todo listo (bug real corregido 2026-08-09, auditoría de
  // pagos pedida explícitamente por el cliente: "no podemos arriesgar en
  // esa parte"). El webhook de Stripe sigue siendo la red de seguridad de
  // fondo — esto es solo para no mentirle al cliente mientras tanto.
  const [ pendingIntentId, setPendingIntentId ] = useState( '' );
  const [ retrying,        setRetrying        ] = useState( false );

  /** true si el backend confirmó — false si hay que reintentar/avisar. */
  const tryConfirm = async ( piId ) => {
    try {
      const base = window.amirBooking?.apiUrl ?? '/wp-json/amir/v1/';
      const resp = await fetch( `${base}bookings/${bookingId}/confirm-payment`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.amirBooking?.nonce ?? '',
        },
        body: JSON.stringify({ payment_intent_id: piId }),
      });
      if ( resp.ok ) {
        const data = await resp.json();
        if ( data.booking_ref ) setBookingRef( data.booking_ref );
        return true;
      }
      return false;
    } catch {
      return false;
    }
  };

  const handlePay = async () => {
    if ( !stripe || !elements ) return;
    setProcessing( true );
    setError( '' );

    const { error: stripeError, paymentIntent } = await stripe.confirmPayment({
      elements,
      redirect: 'if_required',
    });

    if ( stripeError ) {
      setError( stripeError.message || t('err_payment') );
      setProcessing( false );
      return;
    }

    if ( paymentIntent?.status === 'succeeded' ) {
      // El cobro en Stripe ya es un hecho acá — lo que sigue es solo
      // finalizar la reserva de nuestro lado. Un primer reintento inmediato
      // cubre el caso más común (hiccup momentáneo de red/servidor); si
      // ese también falla, se corta y se avisa en vez de simular éxito.
      const ok = await tryConfirm( paymentIntent.id ) || await tryConfirm( paymentIntent.id );
      setProcessing( false );
      if ( ok ) {
        goNext();
      } else {
        setPendingIntentId( paymentIntent.id );
      }
    } else {
      setProcessing( false );
    }
  };

  const handleRetryConfirm = async () => {
    setRetrying( true );
    const ok = await tryConfirm( pendingIntentId );
    setRetrying( false );
    if ( ok ) goNext();
  };

  if ( pendingIntentId ) {
    return (
      <div className="ab-panel" style={{textAlign:'center'}}>
        <div className="ab-confirm-icon">⏳</div>
        <h2 className="ab-confirm-title">{t('payment_confirming_title')}</h2>
        <p className="ab-confirm-sub">{t('payment_confirming_sub')}</p>
        {bookingRef && (
          <div className="ab-ref-box">
            <div className="ab-ref-label">{t('booking_ref')}</div>
            <div className="ab-ref-value">{bookingRef}</div>
          </div>
        )}
        <button className="ab-btn ab-btn-primary" style={{marginTop:16}} onClick={handleRetryConfirm} disabled={retrying}>
          {retrying
            ? <><div className="ab-spinner" style={{width:16,height:16,borderWidth:2}} />{t('processing')}</>
            : t('payment_confirming_retry')}
        </button>
      </div>
    );
  }

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_payment')}</p>

      <div className="ab-stripe-badge">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
          <path d="M12 2L4 6v6c0 5.25 3.4 10.15 8 11.35C16.6 22.15 20 17.25 20 12V6l-8-4z" fill="#1D9E75" opacity=".25"/>
          <path d="M9 12l2 2 4-4" stroke="#1D9E75" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
        </svg>
        {t('pay_secure')} · {t('pay_methods')}
      </div>

      <div className="ab-stripe-wrap">
        <PaymentElement options={{ layout: 'tabs' }} />
      </div>

      {error && <div className="ab-error-banner">⚠ {error}</div>}

      <div className="ab-btn-row">
        <button className="ab-btn ab-btn-ghost" onClick={goBack} disabled={processing}>← {t('back')}</button>
        <button
          className="ab-btn ab-btn-primary"
          onClick={handlePay}
          disabled={processing || !stripe}
        >
          {processing
            ? <><div className="ab-spinner" style={{width:16,height:16,borderWidth:2}} />{t('processing')}</>
            : '🔒 ' + t('book_now')}
        </button>
      </div>
    </div>
  );
}

// ── Step 6b: MercadoPago Payment ──────────────────────────────────────────────
export function StepPaymentMP({ t, goBack, goNext, setBookingRef, bookingId, mpData, form }) {
  const [ processing, setProcessing ] = useState( false );
  const [ error,      setError      ] = useState( '' );

  const mode = window.amirBooking?.mpMode ?? 'sandbox';
  const checkoutUrl = mode === 'live'
    ? mpData?.init_point
    : mpData?.sandbox_init_point;

  const handlePay = () => {
    if ( ! checkoutUrl ) {
      setError( t('err_payment') );
      return;
    }
    setProcessing( true );
    // Abrir MP en nueva pestaña para no perder el widget
    window.open( checkoutUrl, '_blank', 'noopener' );

    // Polling: esperar que el backend confirme el pago
    let attempts = 0;
    const poll = setInterval( async () => {
      attempts++;
      try {
        const base = window.amirBooking?.apiUrl ?? '/wp-json/amir/v1/';
        const resp = await fetch( `${base}bookings/${bookingId}/confirm-mp`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.amirBooking?.nonce ?? '' },
          body:    JSON.stringify({ payment_id: '', email: form?.customerEmail ?? '' }),
        });
        if ( resp.ok ) {
          const data = await resp.json();
          if ( data.confirmed && data.booking_ref ) {
            clearInterval( poll );
            setBookingRef( data.booking_ref );
            goNext();
            return;
          }
        }
      } catch {}
      // Antes 20 intentos (~60s) — muy corto para un pago con 3D-Secure o
      // redirección al banco, que puede tardar más y leerse como "fallido"
      // sin haberlo sido (auditoría de UX pre-empaquetado v5.7.14,
      // CONTRIBUTING.md § 16.91). Reintentar (botón "Pagar con MercadoPago"
      // de nuevo) sigue funcionando como "verificar de nuevo" sin cobrar
      // dos veces — el primer poll ya detecta un pago ya confirmado.
      if ( attempts >= 60 ) { // ~3min de espera máxima
        clearInterval( poll );
        setProcessing( false );
        setError( t('mp_timeout') || 'El pago no se confirmó aún. Si ya pagaste, recibirás tu confirmación por email en breve.' );
      }
    }, 3000 );
  };

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_payment')}</p>

      <div style={{ background:'#f0f6ff', border:'1px solid #c3d9ff', borderRadius:'10px', padding:'16px', marginBottom:'16px', textAlign:'center' }}>
        <div style={{ fontSize:'32px', marginBottom:'8px' }}>💙</div>
        <div style={{ fontSize:'15px', fontWeight:'700', color:'#1a2e24', marginBottom:'6px' }}>MercadoPago</div>
        <div style={{ fontSize:'13px', color:'#5a7068' }}>
          {t('mp_redirect_hint') || 'Al hacer clic se abrirá el checkout seguro de MercadoPago en una nueva pestaña.'}
        </div>
      </div>

      {error && <div className="ab-error-banner">⚠ {error}</div>}

      <div className="ab-btn-row">
        <button className="ab-btn ab-btn-ghost" onClick={goBack} disabled={processing}>← {t('back')}</button>
        <button
          className="ab-btn ab-btn-primary"
          onClick={handlePay}
          disabled={processing}
          style={{ background:'#009ee3' }}
        >
          {processing
            ? <><div className="ab-spinner" style={{width:16,height:16,borderWidth:2}} />{t('waiting_payment') || 'Esperando pago…'}</>
            : '💙 ' + (t('pay_mp') || 'Pagar con MercadoPago')}
        </button>
      </div>
    </div>
  );
}

// ── Step 6c: Redsys Payment ─────────────────────────────────────────────────
// A diferencia de MP/Stripe, Redsys no da una URL de checkout ni un
// client_secret: exige un POST con 3 campos firmados directo a su endpoint
// (Ds_SignatureVersion, Ds_MerchantParameters, Ds_Signature — ya firmados
// server-side por RedsysGateway::create_payment(), acá solo se arma el form
// y se envía, no hay nada que calcular en el cliente). Es una redirección de
// página completa, no una pestaña nueva — el cliente vuelve a la tienda
// recién cuando Redsys termina la operación, así que no hace falta polling
// como en StepPaymentMP: la confirmación real llega después, por la
// notificación server-to-server, igual que Stripe/MP.
export function StepPaymentRedsys({ t, goBack, redsysData }) {
  const [ error, setError ] = useState( '' );

  const handlePay = () => {
    if ( ! redsysData?.action_url || ! redsysData?.merchant_parameters || ! redsysData?.signature ) {
      setError( t('err_payment') );
      return;
    }

    const form = document.createElement( 'form' );
    form.method = 'POST';
    form.action = redsysData.action_url;

    const fields = {
      Ds_SignatureVersion:   redsysData.signature_version,
      Ds_MerchantParameters: redsysData.merchant_parameters,
      Ds_Signature:          redsysData.signature,
    };
    Object.entries( fields ).forEach( ( [ name, value ] ) => {
      const input = document.createElement( 'input' );
      input.type  = 'hidden';
      input.name  = name;
      input.value = value;
      form.appendChild( input );
    } );

    document.body.appendChild( form );
    form.submit();
  };

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_payment')}</p>

      <div style={{ background:'#fff4e5', border:'1px solid #ffd8a8', borderRadius:'10px', padding:'16px', marginBottom:'16px', textAlign:'center' }}>
        <div style={{ fontSize:'32px', marginBottom:'8px' }}>💳</div>
        <div style={{ fontSize:'15px', fontWeight:'700', color:'#1a2e24', marginBottom:'6px' }}>Redsys</div>
        <div style={{ fontSize:'13px', color:'#5a7068' }}>
          {t('redsys_redirect_hint') || 'Al hacer clic serás redirigido a la pasarela segura de tu banco para completar el pago.'}
        </div>
      </div>

      {error && <div className="ab-error-banner">⚠ {error}</div>}

      <div className="ab-btn-row">
        <button className="ab-btn ab-btn-ghost" onClick={goBack}>← {t('back')}</button>
        <button className="ab-btn ab-btn-primary" onClick={handlePay}>
          💳 {t('pay_redsys') || 'Pagar con tarjeta'}
        </button>
      </div>
    </div>
  );
}

// ── Step 7: Confirmation ──────────────────────────────────────────────────────
function StepConfirm({ tour, form, bookingRef, t, lang, finalTotalMxn, quote }) {
  const waPhone  = window.amirBooking?.waPhone ?? '';

  // Purchase/purchase + conversión de Google Ads — una sola vez por reserva
  // (el ref evita que un re-render por cambio de idioma, etc. lo dispare de
  // nuevo con la misma referencia). Usa finalTotalMxn (lo que devolvió el
  // backend al crear la reserva), no el último `quote` del cliente — evita
  // reportar un valor desactualizado si el precio cambió justo al confirmar.
  const purchaseFired = useRef( false );
  useEffect( () => {
    if ( purchaseFired.current || ! bookingRef ) return;
    purchaseFired.current = true;
    trackPurchase({
      tourId:     tour?.id,
      tourName:   tour?.name,
      bookingRef,
      value:      finalTotalMxn ?? 0,
      currency:   window.amirBooking?.currency ?? 'USD',
    });
  }, [ bookingRef ] );
  const fmtDate  = ( dateStr ) => {
    const [ y, m, d ] = dateStr.split('-');
    const months = t( 'months' ); // sin vars, t() devuelve el array tal cual
    return `${parseInt(d)} ${months[parseInt(m)-1]} ${y}`;
  };

  const calUrl = () => {
    const start = form.date.replace(/-/g,'') + 'T' + (form.scheduleTime??'').replace(':','') + '00';
    const title = encodeURIComponent(`${tour.name} — ${window.amirBooking?.companyName ?? 'TourFlow'}`);
    const loc   = encodeURIComponent( tour.meeting_point ?? '' );
    return `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${title}&dates=${start}/${start}&location=${loc}`;
  };

  const waMsg = encodeURIComponent(
    lang === 'en'
      ? `Hi! My booking reference is ${bookingRef}. I need help.`
      : `¡Hola! Mi número de reserva es ${bookingRef}. Necesito ayuda.`
  );

  return (
    <div className="ab-panel" style={{textAlign:'center'}}>
      <div className="ab-confirm-icon">🎉</div>
      <h2 className="ab-confirm-title">{t('confirmed_title')}</h2>
      <p className="ab-confirm-sub">{t('confirmed_sub')}</p>

      <div className="ab-ref-box">
        <div className="ab-ref-label">{t('booking_ref')}</div>
        <div className="ab-ref-value">{bookingRef || '—'}</div>
      </div>

      {(quote?.deposit_pct ?? 0) > 0 && (
        <div className="ab-policy-box" style={{textAlign:'left', marginBottom:16, borderLeftColor:'#BA7517'}}>
          <div className="ab-policy-title">💰 {t('balance_pending')}</div>
          <div className="ab-policy-line">
            {t('deposit_balance_note', { amount: `$${quote.remaining_mxn.toLocaleString('es-MX')} ${window.amirBooking?.currency ?? 'MXN'}` })}
          </div>
        </div>
      )}

      <div className="ab-summary-card" style={{textAlign:'left', marginBottom:20}}>
        <div className="ab-summary-body">
          <div className="ab-summary-row">
            <span className="ab-summary-row-label">{t('tour_date')}</span>
            <span className="ab-summary-row-value">{fmtDate(form.date)}</span>
          </div>
          <div className="ab-summary-row">
            <span className="ab-summary-row-label">{t('meeting_point')}</span>
            <span className="ab-summary-row-value">{tour.meeting_point ?? ''}</span>
          </div>
        </div>
      </div>

      <div className="ab-confirm-actions">
        <a
          href={`${window.amirBooking?.siteUrl ?? ''}/wp-json/amir/v1/bookings/${bookingRef}/pdf`}
          className="ab-btn ab-btn-outline ab-btn-sm"
          download
        >
          📄 {t('download_pdf')}
        </a>
        <a href={calUrl()} target="_blank" rel="noopener noreferrer" className="ab-btn ab-btn-outline ab-btn-sm">
          📅 {t('add_calendar')}
        </a>
        <a
          href={`https://wa.me/${waPhone}?text=${waMsg}`}
          target="_blank" rel="noopener noreferrer"
          className="ab-btn ab-btn-ghost ab-btn-sm"
          style={{gridColumn:'1/-1'}}
        >
          💬 {t('need_help')}
        </a>
      </div>
    </div>
  );
}

// ── Shared Components ─────────────────────────────────────────────────────────
function Counter({ value, min, max, onChange }) {
  return (
    <div className="ab-counter">
      <button className="ab-counter-btn" onClick={() => onChange(value-1)} disabled={value<=min} aria-label="Restar">−</button>
      <span className="ab-counter-val">{value}</span>
      <button className="ab-counter-btn" onClick={() => onChange(value+1)} disabled={value>=max} aria-label="Sumar">+</button>
    </div>
  );
}

function NavRow({ t, goBack, onNext, disabled = false }) {
  return (
    <div className="ab-btn-row">
      <button className="ab-btn ab-btn-ghost" onClick={goBack}>← {t('back')}</button>
      <button className="ab-btn ab-btn-primary" onClick={onNext} disabled={disabled}>{t('continue')} →</button>
    </div>
  );
}
