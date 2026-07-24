// Traducciones del widget de reserva
// El idioma se toma del atributo data-lang del shortcode o del lang del sitio

const translations = {
  es: {
    // Steps
    step_date:      'Elige tu fecha',
    step_schedule:  'Selecciona horario',
    step_people:    'Personas',
    step_details:   'Tus datos',
    step_summary:   'Resumen',
    step_payment:   'Pago',
    step_confirm:   '¡Reservado!',

    // Calendar
    cal_prev:         'Anterior',
    cal_next:         'Siguiente',
    cal_available:    'Disponible',
    cal_full:         'Sin cupos',
    no_schedules:     'Sin salidas disponibles para esta fecha.',
    cal_blocked:      'No disponible',
    cal_past:         'Fecha pasada',
    cal_select_date:  'Selecciona una fecha en el calendario',
    days:             [ 'Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá' ],
    months:           [ 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre' ],

    // People
    adults:           'Adultos',
    adults_age:       '13+ años',
    children:         'Niños',
    children_age:     '4–12 años',
    babies:           'Bebés',
    babies_age:       '0–3 años',
    babies_free:      'Gratis',
    people_label:     'Total personas',
    max_people:       'Máximo {n} personas',
    slots_left:       '{n} lugares disponibles',
    group_people:     'Personas del grupo',

    // Form
    full_name:        'Nombre completo',
    full_name_ph:     'Tu nombre y apellido',
    email:            'Correo electrónico',
    email_ph:         'tu@correo.com',
    phone:            'WhatsApp / Teléfono',
    phone_ph:         '+52 983 000 0000',
    lang_pref:        'Idioma preferido',
    lang_es:          'Español',
    lang_en:          'English',
    lang_pref_hint:   'Idioma para tu email de confirmación',
    special_req:      'Solicitudes especiales (opcional)',
    special_req_ph:   'Alergias, movilidad reducida, celebración especial…',

    // Cupón
    coupon_label:     '¿Tienes un cupón de descuento?',
    coupon_ph:        'Código de cupón',
    coupon_apply:     'Aplicar',
    coupon_applied:   'Cupón aplicado',

    // Summary
    tour_date:        'Fecha del tour',
    departure_time:   'Hora de salida',
    meeting_point:    'Punto de encuentro',
    open_maps:        'Ver en mapa',
    subtotal:         'Subtotal',
    total:            'Total',
    usd_ref:          '≈ USD {amount} (referencia)',
    policy_title:     'Política de cancelación',
    policy_line1:     '✓ 7+ días antes: reembolso completo',
    policy_line2:     '▸ 3–6 días antes: reembolso del 50 %',
    policy_line3:     '✕ Menos de 3 días: sin reembolso',
    policy_accept:    'He leído y acepto la política de cancelación',
    book_now:         'Confirmar y pagar',

    // Payment
    pay_secure:       'Pago seguro con Stripe',
    pay_methods:      'Tarjeta, Apple Pay, Google Pay',
    processing:       'Procesando…',

    // Confirmation
    confirmed_title:  '¡Tu reserva está confirmada!',
    confirmed_sub:    'Te enviamos todos los detalles a tu correo.',
    booking_ref:      'Número de reserva',
    download_pdf:     'Descargar voucher PDF',
    add_calendar:     'Agregar al calendario',
    share_whatsapp:   'Compartir por WhatsApp',
    need_help:        '¿Necesitas ayuda? Escríbenos por WhatsApp',

    // Errors
    err_required:     'Este campo es obligatorio',
    err_email:        'Ingresa un correo válido',
    err_no_slots:     'No hay cupos disponibles para esta fecha',
    err_payment:      'Error al procesar el pago. Por favor intenta de nuevo.',
    err_generic:      'Ocurrió un error. Por favor intenta de nuevo.',
    err_max_pax:      'Se excede el máximo de {n} personas',

    // Misc
    back:             'Atrás',
    continue:         'Continuar',
    loading:          'Cargando…',
    free:             'Gratis',
    per_person:       'por persona',
    includes:         'Incluye',
    not_included:     'No incluye',
    duration:         'Duración',
    min_age_label:    'Edad mínima',
    languages:        'Idiomas',
    min:              'min',
    years:            'años',
  },

  en: {
    step_date:      'Choose your date',
    step_schedule:  'Select time',
    step_people:    'People',
    step_details:   'Your details',
    step_summary:   'Summary',
    step_payment:   'Payment',
    step_confirm:   'Booked!',

    cal_prev:         'Previous',
    cal_next:         'Next',
    cal_available:    'Available',
    cal_full:         'Full',
    no_schedules:     'No departures available for this date.',
    cal_blocked:      'Not available',
    cal_past:         'Past date',
    cal_select_date:  'Select a date on the calendar',
    days:             [ 'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa' ],
    months:           [ 'January','February','March','April','May','June','July','August','September','October','November','December' ],

    adults:           'Adults',
    adults_age:       '13+ years',
    children:         'Children',
    children_age:     '4–12 years',
    babies:           'Babies',
    babies_age:       '0–3 years',
    babies_free:      'Free',
    people_label:     'Total people',
    max_people:       'Maximum {n} people',
    slots_left:       '{n} spots left',
    group_people:     'Group size',

    full_name:        'Full name',
    full_name_ph:     'Your first and last name',
    email:            'Email address',
    email_ph:         'you@email.com',
    phone:            'WhatsApp / Phone',
    phone_ph:         '+52 983 000 0000',
    lang_pref:        'Preferred language',
    lang_es:          'Español',
    lang_en:          'English',
    lang_pref_hint:   'Language for your confirmation email',
    special_req:      'Special requests (optional)',
    special_req_ph:   'Allergies, reduced mobility, special celebration…',

    // Coupon
    coupon_label:     'Have a discount coupon?',
    coupon_ph:        'Coupon code',
    coupon_apply:     'Apply',
    coupon_applied:   'Coupon applied',

    tour_date:        'Tour date',
    departure_time:   'Departure time',
    meeting_point:    'Meeting point',
    open_maps:        'Open in maps',
    subtotal:         'Subtotal',
    total:            'Total',
    usd_ref:          '≈ USD {amount} (reference)',
    policy_title:     'Cancellation policy',
    policy_line1:     '✓ 7+ days before: full refund',
    policy_line2:     '▸ 3–6 days before: 50 % refund',
    policy_line3:     '✕ Less than 3 days: no refund',
    policy_accept:    'I have read and accept the cancellation policy',
    book_now:         'Confirm & pay',

    pay_secure:       'Secure payment with Stripe',
    pay_methods:      'Card, Apple Pay, Google Pay',
    processing:       'Processing…',

    confirmed_title:  'Your booking is confirmed!',
    confirmed_sub:    'We have sent all the details to your email.',
    booking_ref:      'Booking reference',
    download_pdf:     'Download voucher PDF',
    add_calendar:     'Add to calendar',
    share_whatsapp:   'Share on WhatsApp',
    need_help:        'Need help? Message us on WhatsApp',

    err_required:     'This field is required',
    err_email:        'Enter a valid email address',
    err_no_slots:     'No spots available for this date',
    err_payment:      'Payment failed. Please try again.',
    err_generic:      'An error occurred. Please try again.',
    err_max_pax:      'Exceeds maximum of {n} people',

    back:             'Back',
    continue:         'Continue',
    loading:          'Loading…',
    free:             'Free',
    per_person:       'per person',
    includes:         'Includes',
    not_included:     'Not included',
    duration:         'Duration',
    min_age_label:    'Minimum age',
    languages:        'Languages',
    min:              'min',
    years:            'years',
  },
};

export function useT( lang = 'es' ) {
  const dict = translations[ lang ] ?? translations.es;
  return ( key, vars = {} ) => {
    let str = dict[ key ] ?? key;
    Object.entries( vars ).forEach( ( [ k, v ] ) => {
      str = str.replace( `{${k}}`, v );
    } );
    return str;
  };
}

export default translations;
