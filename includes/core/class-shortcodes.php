<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes del plugin.
 *
 * [amir_booking tour_id="3"]
 * [amir_booking tour_id="3" lang="en"]
 *
 * [amir_tour_list]
 *
 * [amir_verify_booking]
 */
class Shortcodes {

    public static function booking_widget( array $atts ): string {
        $atts = shortcode_atts(
            [ 'tour_id' => 0, 'lang' => self::detect_lang() ],
            $atts,
            'amir_booking'
        );

        $tour_id = (int) $atts['tour_id'];
        $lang    = in_array( $atts['lang'], [ 'es', 'en' ], true ) ? $atts['lang'] : 'es';

        if ( $tour_id <= 0 ) {
            return '<p style="color:red">amir_booking: falta el parámetro tour_id</p>';
        }

        // Encolar assets solo cuando se usa el shortcode
        self::enqueue_widget_assets();

        // El div donde React monta el widget
        return sprintf(
            '<div data-amir-booking="1" data-tour-id="%d" data-lang="%s" id="amir-booking-%d"></div>',
            $tour_id,
            esc_attr( $lang ),
            $tour_id
        );
    }

    public static function tour_list( array $atts ): string {
        $atts = shortcode_atts(
            [
                'lang'    => self::detect_lang(),
                'columns' => 3,
                'layout'  => 'grid',
                'limit'   => 0,
            ],
            $atts,
            'amir_tour_list'
        );

        $layout = in_array( $atts['layout'], [ 'grid', 'list' ], true ) ? $atts['layout'] : 'grid';

        self::enqueue_widget_assets();

        return sprintf(
            '<div data-amir-tour-list="1" data-lang="%s" data-columns="%d" data-layout="%s" data-limit="%d" id="amir-tour-list"></div>',
            esc_attr( $atts['lang'] ),
            (int) $atts['columns'],
            esc_attr( $layout ),
            (int) $atts['limit']
        );
    }

    public static function wishlist_list( array $atts ): string {
        $atts = shortcode_atts(
            [
                'lang'    => self::detect_lang(),
                'columns' => 3,
                'accent'  => '',
            ],
            $atts,
            'amir_wishlist'
        );

        self::enqueue_widget_assets();

        return sprintf(
            '<div data-amir-wishlist="1" data-lang="%s" data-columns="%d" data-accent="%s" id="amir-wishlist"></div>',
            esc_attr( $atts['lang'] ),
            (int) $atts['columns'],
            esc_attr( $atts['accent'] )
        );
    }

    // ── Verificación de reserva ───────────────────────────────────────────

    public static function verify_booking( array $atts ): string {
        $ref   = strtoupper( sanitize_text_field( $_GET['ref'] ?? '' ) );
        $token = sanitize_text_field( $_GET['token'] ?? '' );
        $email = sanitize_email( $_GET['email'] ?? '' );

        $lang  = self::detect_lang();
        $is_en = $lang === 'en';

        if ( empty( $ref ) ) {
            return self::verify_lookup_form( $is_en );
        }

        // Throttle por IP: máx. 20 intentos cada 10 minutos,
        // para frenar fuerza bruta de email/token contra booking_ref secuenciales.
        if ( RateLimiter::too_many_attempts( 'verify_' . RateLimiter::client_ip() ) ) {
            return self::rate_limited_message( $is_en );
        }

        global $wpdb;
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, t.name_es, t.name_en, ts.time_start, ts.time_end, ts.label_es, ts.label_en
             FROM {$wpdb->prefix}amir_bookings b
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules ts ON ts.id = b.schedule_id
             WHERE b.booking_ref = %s",
            $ref
        ) );

        if ( ! $b ) {
            return '<div style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #fecaca;text-align:center;">'
                 . '<div style="font-size:40px;margin-bottom:12px;">❌</div>'
                 . '<p style="color:#dc2626;font-weight:600;font-size:16px;">' . ( $is_en ? 'Booking not found' : 'Reserva no encontrada' ) . '</p>'
                 . '<p style="color:#5a7068;font-size:13px;">REF: ' . esc_html( $ref ) . '</p>'
                 . '</div>';
        }

        // Autorización: el link de email/QR trae el token; si el visitante
        // solo pegó el ref, se le pide el email del titular de la reserva.
        // No exponer nombre/fecha/monto a quien no aporte ninguna credencial.
        $manager = new \AmirBooking\Core\BookingManager();
        if ( ! $manager->authorize_public_access( $b, $token, $email ) ) {
            return self::verify_lookup_form( $is_en, $ref, $email !== '' );
        }

        // A partir de acá se conoce la reserva: mostrar todo en el idioma
        // con el que el cliente reservó (mismo idioma en que se le mandaron
        // los emails), no en el idioma que el sitio detecte para esta URL —
        // si no, alguien que reservó en inglés puede terminar viendo esta
        // página (y el paso de pago) en español según cómo resuelva Polylang.
        if ( in_array( $b->lang, [ 'es', 'en' ], true ) ) {
            $lang  = $b->lang;
            $is_en = $lang === 'en';
        }

        $status_map = array(
            'confirmed'              => array( 'es' => 'Confirmada',           'en' => 'Confirmed',            'color' => '#1D9E75', 'bg' => '#e8f5e9', 'icon' => '✅' ),
            'completed'              => array( 'es' => 'Completada',           'en' => 'Completed',            'color' => '#1D9E75', 'bg' => '#e8f5e9', 'icon' => '✅' ),
            'pending'                => array( 'es' => 'Pendiente de pago',    'en' => 'Pending payment',      'color' => '#BA7517', 'bg' => '#fef9ec', 'icon' => '⏳' ),
            'wishlist'               => array( 'es' => 'Lista de interés',     'en' => 'Waitlisted',           'color' => '#6366f1', 'bg' => '#eef2ff', 'icon' => '📋' ),
            'awaiting_payment'       => array( 'es' => 'Lista para pagar',     'en' => 'Ready for payment',    'color' => '#BA7517', 'bg' => '#fef9ec', 'icon' => '💳' ),
            'cancelled_client'       => array( 'es' => 'Cancelada',            'en' => 'Cancelled',            'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => '❌' ),
            'cancelled_weather'      => array( 'es' => 'Cancelada (clima)',    'en' => 'Cancelled (weather)',  'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => '🌧' ),
            'cancelled_min_pax'      => array( 'es' => 'Cancelada (cupo)',     'en' => 'Cancelled (capacity)', 'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => '❌' ),
            'rescheduled'            => array( 'es' => 'Reprogramada',         'en' => 'Rescheduled',          'color' => '#6366f1', 'bg' => '#eef2ff', 'icon' => '📅' ),
            'cancellation_requested' => array( 'es' => 'Cancelación solicitada','en' => 'Cancellation requested','color' => '#f97316','bg' => '#fff7ed','icon' => '⚠️' ),
        );

        $st = isset( $status_map[ $b->status ] ) ? $status_map[ $b->status ] : array( 'es' => $b->status, 'en' => $b->status, 'color' => '#5a7068', 'bg' => '#f3f4f6', 'icon' => 'ℹ️' );

        $months_es = array( 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre' );
        $months_en = array( 'January','February','March','April','May','June','July','August','September','October','November','December' );
        $months    = $is_en ? $months_en : $months_es;

        $date_parts = explode( '-', $b->tour_date );
        $date_fmt   = (int) $date_parts[2] . ' ' . $months[ (int) $date_parts[1] - 1 ] . ' ' . $date_parts[0];

        $tour_name = $is_en ? ( $b->name_en ?: $b->name_es ) : $b->name_es;

        $schedule_label = '';
        if ( $b->time_start ) {
            $h  = (int) substr( $b->time_start, 0, 2 );
            $mi = substr( $b->time_start, 3, 2 );
            $h_end  = (int) substr( $b->time_end, 0, 2 );
            $mi_end = substr( $b->time_end, 3, 2 );
            $fmt = function( $hh, $mm ) {
                return ( $hh > 12 ? $hh - 12 : ( $hh ?: 12 ) ) . ':' . $mm . ( $hh >= 12 ? ' PM' : ' AM' );
            };
            $schedule_label = $fmt( $h, $mi ) . ' – ' . $fmt( $h_end, $mi_end );
            $lbl = $is_en ? $b->label_en : $b->label_es;
            if ( $lbl ) {
                $schedule_label .= ' (' . esc_html( $lbl ) . ')';
            }
        }

        $pax_parts = array();
        if ( $b->adults )   $pax_parts[] = $b->adults   . ' ' . ( $is_en ? 'adults'   : 'adultos' );
        if ( $b->children ) $pax_parts[] = $b->children . ' ' . ( $is_en ? 'children' : 'niños' );
        if ( $b->babies )   $pax_parts[] = $b->babies   . ' ' . ( $is_en ? 'babies'   : 'bebés' );
        $pax = implode( ' + ', $pax_parts );

        $row = function( $label, $value ) {
            return '<div style="display:flex;justify-content:space-between;align-items:baseline;padding:8px 0;border-bottom:1px solid #f0f0f0;">'
                 . '<span style="font-size:13px;color:#5a7068;">' . esc_html( $label ) . '</span>'
                 . '<span style="font-size:14px;font-weight:600;color:#1a2e24;text-align:right;">' . $value . '</span>'
                 . '</div>';
        };

        $rows  = $row( $is_en ? 'Reference'  : 'Referencia', '<code style="font-family:monospace;background:#f3f4f6;padding:2px 6px;border-radius:4px;">' . esc_html( $b->booking_ref ) . '</code>' );
        $rows .= $row( $is_en ? 'Tour'        : 'Tour',       esc_html( $tour_name ) );
        $rows .= $row( $is_en ? 'Date'        : 'Fecha',      esc_html( $date_fmt ) );
        if ( $schedule_label ) {
            $rows .= $row( $is_en ? 'Departure' : 'Salida', esc_html( $schedule_label ) );
        }
        $rows .= $row( $is_en ? 'Passengers' : 'Pasajeros', esc_html( $pax ) );
        $rows .= $row( $is_en ? 'Passenger'  : 'Pasajero',  esc_html( $b->customer_name ) );
        $rows .= $row( $is_en ? 'Total'      : 'Total',     esc_html( \AmirBooking\Core\Currency::format( (float) $b->total_mxn ) ) );

        $verified_label = $is_en ? 'Verified booking' : 'Reserva verificada';
        $status_label   = $is_en ? $st['en'] : $st['es'];

        // Reserva esperando pago (link de "cargar reserva + pagar" o lista
        // de interés convertida al abrir el tour): montar el flujo de pago
        // real justo debajo del estado, en la misma página.
        $pay_widget = '';
        if ( in_array( $b->status, array( 'awaiting_payment', 'pending' ), true ) ) {
            self::enqueue_widget_assets();
            $pay_widget = '<div style="background:#fff;padding:0 24px 24px;">'
                . sprintf(
                    '<div data-amir-pay-booking="1" data-ref="%s" data-token="%s" data-lang="%s"></div>',
                    esc_attr( $b->booking_ref ),
                    esc_attr( $b->access_token ?? '' ),
                    esc_attr( $lang )
                )
                . '</div>';
        }

        return '<div style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:0;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">'
             . '<div style="background:' . esc_attr( $st['color'] ) . ';padding:24px 24px 20px;text-align:center;">'
             . '<div style="font-size:48px;line-height:1;">' . $st['icon'] . '</div>'
             . '<div style="color:#fff;font-size:18px;font-weight:700;margin-top:8px;">' . esc_html( $status_label ) . '</div>'
             . '<div style="color:rgba(255,255,255,.8);font-size:12px;margin-top:4px;">' . esc_html( $verified_label ) . ' · Amir Adventours</div>'
             . '</div>'
             . '<div style="background:#fff;padding:20px 24px 24px;">'
             . $rows
             . '</div>'
             . $pay_widget
             . '</div>';
    }

    /**
     * Formulario de búsqueda por referencia + email.
     * Se muestra cuando falta el ref, o cuando hay ref pero ninguna
     * credencial (token del link, o email) autoriza ver los datos.
     */
    private static function verify_lookup_form( bool $is_en, string $ref = '', bool $denied = false ): string {
        $title = $is_en ? 'Check your booking' : 'Consulta tu reserva';
        $help  = $is_en
            ? 'Enter your booking reference and the email you used to book.'
            : 'Ingresa tu referencia de reserva y el email con el que reservaste.';
        $error = $denied
            ? '<p style="color:#dc2626;font-size:13px;margin:0 0 12px;">'
              . ( $is_en ? 'We couldn\'t match that email with this booking.' : 'Ese email no coincide con esta reserva.' )
              . '</p>'
            : '';
        $action = esc_url( remove_query_arg( array( 'ref', 'token', 'email' ) ) );

        return '<div style="font-family:sans-serif;max-width:420px;margin:40px auto;padding:28px 24px;background:#fff;border-radius:12px;border:1px solid #e1f5ee;">'
             . '<h2 style="font-size:17px;margin:0 0 6px;color:#1a2e24;">' . esc_html( $title ) . '</h2>'
             . '<p style="color:#5a7068;font-size:13px;margin:0 0 16px;">' . esc_html( $help ) . '</p>'
             . $error
             . '<form method="get" action="' . $action . '" style="display:flex;flex-direction:column;gap:10px;">'
             . '<input type="text" name="ref" value="' . esc_attr( $ref ) . '" placeholder="' . esc_attr( $is_en ? 'Booking reference (e.g. AMIR-2026-00001)' : 'Referencia (ej. AMIR-2026-00001)' ) . '" required style="padding:10px 12px;border:1px solid #c3d9d0;border-radius:8px;font-size:14px;">'
             . '<input type="email" name="email" placeholder="' . esc_attr( $is_en ? 'Email used to book' : 'Email con el que reservaste' ) . '" required style="padding:10px 12px;border:1px solid #c3d9d0;border-radius:8px;font-size:14px;">'
             . '<button type="submit" style="padding:10px 12px;background:#1D9E75;color:#fff;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;">' . esc_html( $is_en ? 'View booking' : 'Ver mi reserva' ) . '</button>'
             . '</form>'
             . '</div>';
    }

    private static function rate_limited_message( bool $is_en ): string {
        $msg = $is_en
            ? 'Too many attempts. Please try again in a few minutes.'
            : 'Demasiados intentos. Intenta de nuevo en unos minutos.';
        return '<div style="font-family:sans-serif;max-width:420px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #fecaca;text-align:center;">'
             . '<p style="color:#dc2626;font-size:14px;margin:0;">' . esc_html( $msg ) . '</p>'
             . '</div>';
    }

    // ── Assets ────────────────────────────────────────────────────────────

    private static function enqueue_widget_assets(): void {
        if ( wp_script_is( 'amir-booking-widget', 'enqueued' ) ) {
            return;
        }

        wp_enqueue_style(
            'amir-booking-widget',
            AMIR_PLUGIN_URL . 'assets/css/booking-widget.css',
            [],
            AMIR_VERSION
        );

        wp_enqueue_script(
            'amir-booking-widget',
            AMIR_PLUGIN_URL . 'assets/js/booking-widget.js',
            [],
            AMIR_VERSION,
            true  // en el footer
        );

        $mode   = get_option( 'amir_stripe_mode', 'test' );
        $pk_key = get_option( "amir_stripe_pk_{$mode}", '' );

        wp_localize_script( 'amir-booking-widget', 'amirBooking', [
            'apiUrl'   => rest_url( 'amir/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'stripePk' => $pk_key,
            'siteUrl'  => get_site_url(),
            'waPhone'  => get_option( 'amir_wa_phone', '5219831649541' ),
            'lang'     => self::detect_lang(),
            'currency' => \AmirBooking\Core\Currency::code(),
            'mpMode'   => get_option( 'amir_mp_mode', 'test' ),
        ] );
    }

    // ── Detectar idioma activo (compatible con Polylang / WPML) ──────────

    private static function detect_lang(): string {
        // Polylang
        if ( function_exists( 'pll_current_language' ) ) {
            $lang = pll_current_language( 'slug' );
            return in_array( $lang, [ 'es', 'en' ], true ) ? $lang : 'es';
        }
        // WPML
        if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
            $lang = ICL_LANGUAGE_CODE;
            return in_array( $lang, [ 'es', 'en' ], true ) ? $lang : 'es';
        }
        // Fallback: lang del sitio WP
        $locale = get_locale();
        return strncmp( $locale, 'en', 2 ) === 0 ? 'en' : 'es';
    }
}
