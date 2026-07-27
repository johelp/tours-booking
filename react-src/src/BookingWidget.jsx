import { useState, useEffect, useCallback, useRef } from 'react';
import { loadStripe }          from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { useT }               from './i18n.js';
import * as API               from './api.js';
import { trackInitiateCheckout, trackPurchase, getCouponFromUrl } from './marketing.js';
import './styles/widget.css';

// ── Constants ─────────────────────────────────────────────────────────────────
const STEPS = [ 'date', 'schedule', 'people', 'extras', 'details', 'summary', 'payment', 'confirm' ];

// Steps that require a schedule selector (skip if tour has only 1 schedule)
const needsScheduleStep = ( schedules ) => schedules?.length > 1;

// Skip the extras step entirely if the tour has no add-ons configured
const needsExtrasStep = ( tour ) => ( tour?.addons?.length ?? 0 ) > 0;

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
      .catch( e => { setError( e.message ); setLoading( false ); } );
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
  const [ step,         setStep        ] = useState( 0 ); // index into STEPS
  const [ availability, setAvailability ] = useState( {} );
  const [ schedules,    setSchedules    ] = useState( null );
  const [ quote,        setQuote        ] = useState( null );
  const [ clientSecret, setClientSecret ] = useState( '' );
  const [ bookingRef,   setBookingRef   ] = useState( '' );
  const [ bookingId,    setBookingId    ] = useState( null );
  const [ mpData,       setMpData       ] = useState( null ); // { preference_id, init_point, sandbox_init_point }
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
    couponCode:      getCouponFromUrl(),
    policyAccepted:  false,
    selectedAddons:  {}, // { [addonId]: qty }
  });

  const patchForm = ( patch ) => setForm( f => ( { ...f, ...patch } ) );

  // InitiateCheckout/begin_checkout: se dispara una sola vez, cuando el
  // widget de reserva ya está montado e interactivo (no hay un paso previo
  // de "ver tour" separado dentro del widget mismo — eso lo cubre el
  // ViewContent/view_item del template de single-amir_tour.php).
  useEffect( () => {
    trackInitiateCheckout( tour );
  }, [] );

  // Filter applicable steps (skip schedule step if only 1 schedule)
  const activeSteps = STEPS.filter( s => {
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

  // Fetch quote whenever people/schedule/date/extras changes
  const addonsKey = JSON.stringify( form.selectedAddons );
  useEffect( () => {
    if ( ! form.date || ( form.adults + form.children ) === 0 ) return;
    const sid = resolveScheduleId( form, schedules, tour.schedules ) ?? 0;
    const addons = Object.entries( form.selectedAddons )
      .filter( ( [ , qty ] ) => qty > 0 )
      .map( ( [ id, qty ] ) => ( { id: Number( id ), qty } ) );
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
    .then( setQuote )
    .catch( () => {} );
  }, [ form.date, form.scheduleId, form.adults, form.children, form.babies, form.couponCode, addonsKey ] );

  const stepProps = { tour, form, patchForm, lang, setLang, t, goNext, goBack,
    availability, setAvailability, schedules, setSchedules,
    quote, clientSecret, setClientSecret, bookingRef, setBookingRef,
    bookingId, setBookingId, mpData, setMpData, gateway, setGateway, step, activeSteps,
    finalTotalMxn, setFinalTotalMxn };

  const stepLabels = activeSteps.map( s => t( `step_${s}` ) );

  return (
    <div className="ab-widget">
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
      { stepName === 'payment'  && gateway !== 'mercadopago' && (
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
  const today       = new Date();
  const [ viewYear, setViewYear ] = useState( today.getFullYear() );
  const [ viewMonth, setViewMonth ] = useState( today.getMonth() + 1 ); // 1-based
  const [ loadingMonth, setLoadingMonth ] = useState( false );

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

  const daysInMonth  = new Date( viewYear, viewMonth, 0 ).getDate();
  const firstDow     = new Date( viewYear, viewMonth - 1, 1 ).getDay(); // 0=Sun
  const todayStr     = today.toISOString().slice( 0, 10 );
  const months = t( 'months' ); // t() con una sola clave y sin vars devuelve el array tal cual

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_date')}</p>

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
        {['Do','Lu','Ma','Mi','Ju','Vi','Sá'].map( d => (
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
          Últimos lugares
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
function StepPeople({ tour, form, patchForm, t, goNext, goBack, quote }) {
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

  const fmtMXN = ( n ) => n === 0
    ? t('free')
    : `$${n.toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;

  const canContinue = isGroup ? total >= 1 : form.adults >= 1;

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
  ].filter( Boolean ).filter( r => r.price !== null || r.free );

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
            <div className="ab-people-price">
              {r.free ? <span style={{color:'var(--ab-teal)'}}>Gratis</span> : `$${r.price?.toLocaleString('es-MX')} ${t('per_person')}`}
            </div>
            <Counter
              value={form[r.key]}
              min={r.min}
              max={Math.min(r.max, maxCapacity - total + form[r.key])}
              onChange={ v => patchForm({ [r.key]: v }) }
            />
          </div>
        ))}
      </div>

      {quote?.total_mxn > 0 && (
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
            <div key={a.id} className="ab-people-row">
              <div className="ab-people-info">
                <div className="ab-people-label">{a.name}</div>
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
function StepDetails({ form, patchForm, lang, setLang, t, goNext, goBack }) {
  const [ errors, setErrors ] = useState({});

  const validate = () => {
    const e = {};
    if ( !form.customerName.trim() )  e.customerName = t('err_required');
    if ( !form.customerEmail.trim() ) e.customerEmail = t('err_required');
    else if ( !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.customerEmail) ) e.customerEmail = t('err_email');
    if ( !form.customerPhone.trim() ) e.customerPhone = t('err_required');
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
        <label className="ab-label" htmlFor="ab-special">{t('special_req')}</label>
        <textarea
          id="ab-special"
          className="ab-textarea"
          placeholder={t('special_req_ph')}
          value={form.specialRequests}
          onChange={ e => patchForm({ specialRequests: e.target.value }) }
        />
      </div>

      <NavRow t={t} goBack={goBack} onNext={handleNext} />
    </div>
  );
}

// ── Step 5: Summary + Policy ──────────────────────────────────────────────────
function StepSummary({ tour, form, patchForm, t, lang, goNext, goBack, quote,
                        setClientSecret, setBookingId, setMpData, setGateway, schedules,
                        setFinalTotalMxn }) {
  const [ creating, setCreating ] = useState( false );
  const [ error,    setError    ] = useState( '' );

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
    if ( !form.policyAccepted ) return;
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
        coupon_code:      form.couponCode ?? '',
        policy_accepted:  form.policyAccepted,
        addons: Object.entries( form.selectedAddons ?? {} )
          .filter( ( [ , qty ] ) => qty > 0 )
          .map( ( [ id, qty ] ) => ( { id: Number( id ), qty } ) ),
      });

      setBookingId( result.booking_id );
      setFinalTotalMxn( result.total_mxn ?? 0 );

      if ( result.gateway === 'mercadopago' ) {
        // MP: redirigir al checkout de MercadoPago
        setGateway( 'mercadopago' );
        setMpData({
          preference_id:      result.preference_id,
          init_point:         result.init_point,
          sandbox_init_point: result.sandbox_init_point,
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

  return (
    <div className="ab-panel">
      <p className="ab-panel-title">{t('step_summary')}</p>

      <div className="ab-summary-card">
        {tour.gallery_images?.[0] && (
          <img src={tour.gallery_images[0]} alt={tour.name} className="ab-summary-cover" loading="lazy" />
        )}
        <div className="ab-summary-body">
          <p className="ab-summary-tour-name">{tour.name}</p>

          <div className="ab-summary-row">
            <span className="ab-summary-row-label">{t('tour_date')}</span>
            <span className="ab-summary-row-value">{fmtDate(form.date)}</span>
          </div>
          <div className="ab-summary-row">
            <span className="ab-summary-row-label">{t('departure_time')}</span>
            <span className="ab-summary-row-value">{fmtTime(form.scheduleTime)}</span>
          </div>
          <div className="ab-summary-row">
            <span className="ab-summary-row-label">{t('people_label')}</span>
            <span className="ab-summary-row-value">{paxSummary}</span>
          </div>
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
          { (() => {
            // Calcular total localmente si el quote no llegó aún
            const totalMxn = quote?.total_mxn ?? ( () => {
              const prices = tour.prices ?? [];
              const adultP = prices.find(p => p.person_type === 'adult')?.price_mxn ?? 0;
              const childP = prices.find(p => p.person_type === 'child')?.price_mxn ?? 0;
              const babyP  = prices.find(p => p.person_type === 'baby')?.price_mxn  ?? 0;
              return form.adults * adultP + form.children * childP + form.babies * babyP;
            })();
            const usdRef = quote?.usd_reference ?? null;
            return (
              <div className="ab-summary-row" style={{fontWeight:700,borderTop:'2px solid var(--ab-teal)',marginTop:4,paddingTop:10}}>
                <span className="ab-summary-row-label" style={{color:'var(--ab-text)',fontWeight:700}}>{t('total')}</span>
                <span className="ab-summary-row-value" style={{fontSize:17}}>
                  ${totalMxn.toLocaleString('es-MX')} {window.amirBooking?.currency ?? 'MXN'}
                  {usdRef > 0 && (
                    <span style={{display:'block', fontSize:11, fontWeight:400, color:'var(--ab-muted)'}}>
                      {t('usd_ref', { amount: usdRef.toFixed(0) })}
                    </span>
                  )}
                </span>
              </div>
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

      {error && <div className="ab-error-banner">⚠ {error}</div>}

      <div className="ab-btn-row">
        <button className="ab-btn ab-btn-ghost" onClick={goBack} disabled={creating}>← {t('back')}</button>
        <button
          className="ab-btn ab-btn-primary"
          onClick={handleConfirm}
          disabled={!form.policyAccepted || creating}
        >
          {creating ? <><div className="ab-spinner" style={{width:16,height:16,borderWidth:2}} />{t('processing')}</> : t('book_now')}
        </button>
      </div>
    </div>
  );
}

// ── Step 6: Stripe Payment ────────────────────────────────────────────────────
// (exportado: PayBooking.jsx lo reutiliza para pagar una reserva ya cargada
// vía link, fuera del flujo de pasos normal de BookingWidget)
export function StepPayment({ t, goBack, goNext, setBookingRef, bookingId }) {
  const stripe   = useStripe();
  const elements = useElements();
  const [ processing, setProcessing ] = useState( false );
  const [ error,      setError      ] = useState( '' );
  const siteUrl = window.amirBooking?.siteUrl ?? '';

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
      // Confirmar la reserva directo en nuestro backend (no depender del webhook de Stripe)
      try {
        const base = window.amirBooking?.apiUrl ?? '/wp-json/amir/v1/';
        const resp = await fetch( `${base}bookings/${bookingId}/confirm-payment`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.amirBooking?.nonce ?? '',
          },
          body: JSON.stringify({ payment_intent_id: paymentIntent.id }),
        });
        if ( resp.ok ) {
          const data = await resp.json();
          if ( data.booking_ref ) {
            setBookingRef( data.booking_ref );
          }
        }
      } catch {}
      // Avanzar al paso de confirmación pase lo que pase
      goNext();
    }
  };

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
export function StepPaymentMP({ t, goBack, goNext, setBookingRef, bookingId, mpData }) {
  const [ processing, setProcessing ] = useState( false );
  const [ error,      setError      ] = useState( '' );

  const mode = window.amirBooking?.mpMode ?? 'sandbox';
  const checkoutUrl = mode === 'live'
    ? mpData?.init_point
    : mpData?.sandbox_init_point;

  const handlePay = () => {
    if ( ! checkoutUrl ) {
      setError( 'Error: no se pudo obtener el link de pago de MercadoPago.' );
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
          body:    JSON.stringify({ payment_id: '' }),
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
      if ( attempts >= 20 ) { // ~60s de espera máxima
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

// ── Step 7: Confirmation ──────────────────────────────────────────────────────
function StepConfirm({ tour, form, bookingRef, t, lang, finalTotalMxn }) {
  const waPhone  = window.amirBooking?.waPhone ?? '5219831649541';

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
    const title = encodeURIComponent(`${tour.name} — Amir Adventours`);
    const loc   = encodeURIComponent( tour.meeting_point ?? 'Bacalar, México' );
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
