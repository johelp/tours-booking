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

    // ── Verificación de reserva ───────────────────────────────────────────

    public static function verify_booking( array $atts ): string {
        $ref = strtoupper( sanitize_text_field( $_GET['ref'] ?? '' ) );

        if ( empty( $ref ) ) {
            return '<div style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #e1f5ee;text-align:center;">'
                 . '<p style="color:#5a7068;font-size:15px;">Ingresa una referencia de reserva válida.</p>'
                 . '</div>';
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

        $lang    = self::detect_lang();
        $is_en   = $lang === 'en';

        if ( ! $b ) {
            return '<div style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #fecaca;text-align:center;">'
                 . '<div style="font-size:40px;margin-bottom:12px;">❌</div>'
                 . '<p style="color:#dc2626;font-weight:600;font-size:16px;">' . ( $is_en ? 'Booking not found' : 'Reserva no encontrada' ) . '</p>'
                 . '<p style="color:#5a7068;font-size:13px;">REF: ' . esc_html( $ref ) . '</p>'
                 . '</div>';
        }

        $status_map = array(
            'confirmed'              => array( 'es' => 'Confirmada',           'en' => 'Confirmed',            'color' => '#1D9E75', 'bg' => '#e8f5e9', 'icon' => '✅' ),
            'completed'              => array( 'es' => 'Completada',           'en' => 'Completed',            'color' => '#1D9E75', 'bg' => '#e8f5e9', 'icon' => '✅' ),
            'pending'                => array( 'es' => 'Pendiente de pago',    'en' => 'Pending payment',      'color' => '#BA7517', 'bg' => '#fef9ec', 'icon' => '⏳' ),
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
        $rows .= $row( $is_en ? 'Total'      : 'Total',     '$' . number_format( (float) $b->total_mxn, 2 ) . ' MXN' );

        $verified_label = $is_en ? 'Verified booking' : 'Reserva verificada';
        $status_label   = $is_en ? $st['en'] : $st['es'];

        return '<div style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:0;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">'
             . '<div style="background:' . esc_attr( $st['color'] ) . ';padding:24px 24px 20px;text-align:center;">'
             . '<div style="font-size:48px;line-height:1;">' . $st['icon'] . '</div>'
             . '<div style="color:#fff;font-size:18px;font-weight:700;margin-top:8px;">' . esc_html( $status_label ) . '</div>'
             . '<div style="color:rgba(255,255,255,.8);font-size:12px;margin-top:4px;">' . esc_html( $verified_label ) . ' · Amir Adventours</div>'
             . '</div>'
             . '<div style="background:#fff;padding:20px 24px 24px;">'
             . $rows
             . '</div>'
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
