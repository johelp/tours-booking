import { useState, useEffect } from 'react';
import { getUpcomingTours, registerInterest, getTourSchedules, getQuote } from './api.js';

/**
 * Grilla de tours "Próximamente" — [amir_wishlist]
 *
 * Tours todavía en borrador (lista de interés activada desde el admin, con
 * una fecha ya fija — no hay calendario de disponibilidad mientras el tour
 * sigue en borrador). Completa horario/personas/datos como una reserva
 * normal y crea una reserva real en estado 'wishlist' — sin cobrar. Si el
 * tour se abre, se manda un link de pago por email para esa misma reserva.
 *
 * Shortcode atributos:
 *   columns="3"      columnas (1-3)
 *   accent="#1D9E75" color de acento hex
 */
export default function WishlistList({ lang = 'es', rootEl = null }) {
  const [ tours,   setTours   ] = useState( [] );
  const [ loading, setLoading ] = useState( true );

  const columns = Math.min( 3, Math.max( 1, parseInt( rootEl?.dataset?.columns || '3', 10 ) ) );
  const accent  = rootEl?.dataset?.accent || '#1D9E75';

  useEffect( () => {
    getUpcomingTours( lang )
      .then( data => { setTours( data ); setLoading( false ); } )
      .catch( () => setLoading( false ) );
  }, [ lang ] );

  if ( loading || ! tours.length ) return null;

  return (
    <div style={{ fontFamily:'inherit' }}>
      <style>{`
        .wl-grid { display:grid; gap:22px; }
        @media(min-width:901px) { .wl-grid.c3{grid-template-columns:repeat(3,1fr)} .wl-grid.c2{grid-template-columns:repeat(2,1fr)} }
        @media(min-width:601px) and (max-width:900px) { .wl-grid.c3,.wl-grid.c2{grid-template-columns:repeat(2,1fr)} }
        @media(max-width:600px) { .wl-grid{grid-template-columns:1fr!important} }
        .wl-grid.c1 { grid-template-columns:1fr; max-width:480px; margin:0 auto; }
        .wl-card { background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eef6f2; box-shadow:0 2px 14px rgba(0,0,0,.055); display:flex; flex-direction:column; }
        .wl-img { width:100%; aspect-ratio:4/3; object-fit:cover; display:block; }
        .wl-img-ph { width:100%; aspect-ratio:4/3; display:flex; align-items:center; justify-content:center; font-size:44px; background:#f0f9f5; }
        .wl-soon { position:absolute; top:10px; left:10px; font-size:11px; font-weight:700; padding:4px 11px; border-radius:20px; background:rgba(10,20,15,.6); color:#fff; }
        .wl-body { padding:18px 20px 20px; }
        .wl-title { font-size:17px; font-weight:800; color:#1a2e24; margin:0 0 4px; }
        .wl-date { font-size:12px; color:${accent}; font-weight:700; margin:0 0 8px; text-transform:capitalize; }
        .wl-excerpt { font-size:13px; color:#556760; line-height:1.6; margin:0 0 14px; }
        .wl-count { font-size:12px; color:${accent}; font-weight:700; margin:0 0 14px; }
        .wl-toggle { width:100%; padding:11px 16px; background:${accent}; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; }
        .wl-form { display:flex; flex-direction:column; gap:10px; margin-top:14px; }
        .wl-form input, .wl-form select, .wl-form textarea { padding:10px 12px; border:1px solid #c3d9d0; border-radius:8px; font-size:13px; font-family:inherit; box-sizing:border-box; width:100%; }
        .wl-row { display:flex; gap:8px; }
        .wl-row > * { flex:1; }
        .wl-people-label { font-size:12px; font-weight:700; color:#1a2e24; margin:2px 0; }
        .wl-counter { display:flex; align-items:center; gap:10px; }
        .wl-counter button { width:30px; height:30px; border-radius:50%; border:1px solid #c3d9d0; background:#fff; font-size:16px; cursor:pointer; line-height:1; }
        .wl-counter button:disabled { opacity:.4; cursor:default; }
        .wl-counter span { min-width:20px; text-align:center; font-weight:700; }
        .wl-price { font-size:13px; color:#5a7068; margin-top:2px; }
        .wl-price strong { color:#1a2e24; font-size:16px; }
        .wl-submit { padding:12px 16px; background:${accent}; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; margin-top:4px; }
        .wl-submit:disabled { opacity:.6; cursor:default; }
        .wl-ok { font-size:13px; color:${accent}; font-weight:600; }
        .wl-err { font-size:12px; color:#dc2626; }
      `}</style>

      <div className={`wl-grid c${columns}`}>
        { tours.map( tour => <WishlistCard key={tour.id} tour={tour} lang={lang} /> ) }
      </div>
    </div>
  );
}

function fmtDate( dateStr, lang ) {
  if ( ! dateStr ) return '';
  const [ y, m, d ] = dateStr.split('-');
  const months = {
    es: ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
    en: ['January','February','March','April','May','June','July','August','September','October','November','December'],
  };
  return `${parseInt(d)} ${months[lang]?.[parseInt(m)-1]} ${y}`;
}

function WishlistCard({ tour, lang }) {
  const isEn    = lang === 'en';
  const isGroup = tour.price_model === 'group';

  const [ open,      setOpen      ] = useState( false );
  const [ schedules, setSchedules ] = useState( null );
  const [ scheduleId, setScheduleId ] = useState( null );
  const [ adults,   setAdults   ] = useState( 1 );
  const [ children, setChildren ] = useState( 0 );
  const [ babies,   setBabies   ] = useState( 0 );
  const [ quote,    setQuote    ] = useState( null );
  const [ name,     setName     ] = useState( '' );
  const [ email,    setEmail    ] = useState( '' );
  const [ phone,    setPhone    ] = useState( '' );
  const [ sending,  setSending  ] = useState( false );
  const [ done,     setDone     ] = useState( false );
  const [ error,    setError    ] = useState( '' );

  useEffect( () => {
    if ( ! open || schedules !== null ) return;
    getTourSchedules( tour.id, lang )
      .then( list => {
        setSchedules( list );
        if ( list.length === 1 ) setScheduleId( list[0].id );
      } )
      .catch( () => setSchedules( [] ) );
  }, [ open ] );

  useEffect( () => {
    if ( ! open || ! tour.date ) return;
    const sid = scheduleId ?? 0;
    const total = isGroup ? adults : adults + children;
    if ( total <= 0 ) return;
    getQuote({ tourId: tour.id, scheduleId: sid, date: tour.date, adults, children, babies })
      .then( setQuote )
      .catch( () => setQuote( null ) );
  }, [ open, scheduleId, adults, children, babies ] );

  const total = adults + children + babies;
  const maxGroup = tour.max_capacity || 4;
  const canSubmit = name.trim() !== '' && email.trim() !== '' && ! sending
    && ( schedules === null || schedules.length <= 1 || scheduleId !== null );

  const submit = ( e ) => {
    e.preventDefault();
    if ( ! canSubmit ) return;
    setSending( true );
    setError( '' );
    registerInterest( tour.id, {
      schedule_id:  scheduleId ?? 0,
      adults, children, babies,
      customer_name:  name,
      customer_email: email,
      customer_phone: phone,
      lang,
    } )
      .then( () => setDone( true ) )
      .catch( err => setError( err.message || ( isEn ? 'Something went wrong.' : 'Algo salió mal.' ) ) )
      .finally( () => setSending( false ) );
  };

  return (
    <article className="wl-card">
      <div style={{ position:'relative' }}>
        { tour.cover_image
          ? <img src={tour.cover_image} alt={tour.name} className="wl-img" loading="lazy" />
          : <div className="wl-img-ph">⛵</div> }
        <span className="wl-soon">{ isEn ? 'Coming soon' : 'Próximamente' }</span>
      </div>
      <div className="wl-body">
        <h3 className="wl-title">{tour.name}</h3>
        { tour.date && <p className="wl-date">📅 {fmtDate(tour.date, lang)}</p> }
        { tour.short_description && <p className="wl-excerpt">{tour.short_description}</p> }

        { tour.interest_count > 0 && (
          <p className="wl-count">
            { isEn
              ? `${tour.interest_count} ${tour.interest_count === 1 ? 'person is' : 'people are'} already interested`
              : `${tour.interest_count} ${tour.interest_count === 1 ? 'persona ya se anotó' : 'personas ya se anotaron'}` }
          </p>
        )}

        { done ? (
          <p className="wl-ok">✓ { isEn ? 'You\'re on the list — we\'ll email you a payment link if this tour opens.' : 'Listo, quedaste anotado — si se abre, te mandamos el link de pago por email.' }</p>
        ) : ! open ? (
          <button type="button" className="wl-toggle" onClick={() => setOpen(true)}>
            { isEn ? 'I\'m interested' : 'Me interesa' }
          </button>
        ) : (
          <form className="wl-form" onSubmit={submit}>
            { schedules === null && <span style={{fontSize:12,color:'#5a7068'}}>{isEn?'Loading…':'Cargando…'}</span> }

            { schedules && schedules.length > 1 && (
              <select value={scheduleId ?? ''} onChange={e => setScheduleId(Number(e.target.value))} required>
                <option value="" disabled>{isEn ? 'Choose a time' : 'Elegí un horario'}</option>
                { schedules.map( s => (
                  <option key={s.id} value={s.id}>{s.label || s.time_start}</option>
                ) ) }
              </select>
            ) }

            <div>
              <div className="wl-people-label">{ isEn ? 'People' : 'Personas' }</div>
              { isGroup ? (
                <div className="wl-counter">
                  <button type="button" onClick={() => setAdults(v => Math.max(1, v-1))} disabled={adults<=1}>−</button>
                  <span>{adults}</span>
                  <button type="button" onClick={() => setAdults(v => Math.min(maxGroup, v+1))} disabled={adults>=maxGroup}>+</button>
                </div>
              ) : (
                <div className="wl-row">
                  <div>
                    <div style={{fontSize:11,color:'#5a7068'}}>{isEn?'Adults':'Adultos'}</div>
                    <div className="wl-counter">
                      <button type="button" onClick={() => setAdults(v => Math.max(1, v-1))} disabled={adults<=1}>−</button>
                      <span>{adults}</span>
                      <button type="button" onClick={() => setAdults(v => v+1)}>+</button>
                    </div>
                  </div>
                  <div>
                    <div style={{fontSize:11,color:'#5a7068'}}>{isEn?'Children':'Niños'}</div>
                    <div className="wl-counter">
                      <button type="button" onClick={() => setChildren(v => Math.max(0, v-1))} disabled={children<=0}>−</button>
                      <span>{children}</span>
                      <button type="button" onClick={() => setChildren(v => v+1)}>+</button>
                    </div>
                  </div>
                  <div>
                    <div style={{fontSize:11,color:'#5a7068'}}>{isEn?'Babies':'Bebés'}</div>
                    <div className="wl-counter">
                      <button type="button" onClick={() => setBabies(v => Math.max(0, v-1))} disabled={babies<=0}>−</button>
                      <span>{babies}</span>
                      <button type="button" onClick={() => setBabies(v => v+1)}>+</button>
                    </div>
                  </div>
                </div>
              ) }
            </div>

            { quote?.total_mxn > 0 && (
              <p className="wl-price">
                { isEn ? 'Estimated total' : 'Total estimado' }: <strong>${quote.total_mxn.toLocaleString('es-MX')}</strong>
              </p>
            ) }

            <input type="text" placeholder={isEn ? 'Your name' : 'Tu nombre'} value={name} onChange={e => setName(e.target.value)} required />
            <input type="email" placeholder={isEn ? 'Your email' : 'Tu email'} value={email} onChange={e => setEmail(e.target.value)} required />
            <input type="tel" placeholder={isEn ? 'Phone (optional)' : 'Teléfono (opcional)'} value={phone} onChange={e => setPhone(e.target.value)} />

            { error && <span className="wl-err">{error}</span> }

            <button type="submit" className="wl-submit" disabled={!canSubmit}>
              { sending ? ( isEn ? 'Sending…' : 'Enviando…' ) : ( isEn ? 'Sign me up — no payment now' : 'Anotarme — sin pagar ahora' ) }
            </button>
          </form>
        )}
      </div>
    </article>
  );
}
