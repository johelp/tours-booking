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
        $lang    = Languages::is_active( $atts['lang'] ) ? $atts['lang'] : Languages::default_lang();

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
                'lang'           => self::detect_lang(),
                'columns'        => 3,
                'layout'         => 'grid',
                'limit'          => 0,
                'ids'            => '',
                'accent'         => '#1D9E75',
                'bg_color'       => '#ffffff',
                'text_color'     => '#1a2e24',
                'radius'         => 16,
                'image_ratio'    => '4/3',
                'show_excerpt'   => 'yes',
                'show_price'     => 'yes',
                'show_age'       => 'yes',
                'show_duration'  => 'yes',
                'show_languages' => 'yes',
                'show_capacity'  => 'yes',
                'cta_text_es'    => 'Reservar ahora',
                'cta_text_en'    => 'Book now',
            ],
            $atts,
            'amir_tour_list'
        );

        $layout = in_array( $atts['layout'], [ 'grid', 'list' ], true ) ? $atts['layout'] : 'grid';
        $yesno  = fn( $v ) => in_array( strtolower( (string) $v ), [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no';

        self::enqueue_widget_assets();

        return sprintf(
            '<div data-amir-tour-list="1" data-lang="%s" data-columns="%d" data-layout="%s" data-limit="%d"'
            . ' data-ids="%s" data-accent="%s" data-bg-color="%s" data-text-color="%s" data-radius="%d" data-image-ratio="%s"'
            . ' data-show-excerpt="%s" data-show-price="%s" data-show-age="%s" data-show-duration="%s"'
            . ' data-show-languages="%s" data-show-capacity="%s" data-cta-es="%s" data-cta-en="%s"'
            . ' id="amir-tour-list"></div>',
            esc_attr( $atts['lang'] ),
            (int) $atts['columns'],
            esc_attr( $layout ),
            (int) $atts['limit'],
            esc_attr( preg_replace( '/[^0-9,]/', '', $atts['ids'] ) ),
            esc_attr( $atts['accent'] ),
            esc_attr( $atts['bg_color'] ),
            esc_attr( $atts['text_color'] ),
            (int) $atts['radius'],
            esc_attr( preg_replace( '/[^0-9\/]/', '', $atts['image_ratio'] ) ?: '4/3' ),
            $yesno( $atts['show_excerpt'] ),
            $yesno( $atts['show_price'] ),
            $yesno( $atts['show_age'] ),
            $yesno( $atts['show_duration'] ),
            $yesno( $atts['show_languages'] ),
            $yesno( $atts['show_capacity'] ),
            esc_attr( $atts['cta_text_es'] ),
            esc_attr( $atts['cta_text_en'] )
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

        $lang = self::detect_lang();

        if ( empty( $ref ) ) {
            return Languages::run_in( $lang, fn() => self::verify_lookup_form( $lang ) );
        }

        // Throttle por IP: máx. 20 intentos cada 10 minutos,
        // para frenar fuerza bruta de email/token contra booking_ref secuenciales.
        if ( RateLimiter::too_many_attempts( 'verify_' . RateLimiter::client_ip() ) ) {
            return Languages::run_in( $lang, fn() => self::rate_limited_message() );
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
            return Languages::run_in( $lang, fn() =>
                '<div style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #fecaca;text-align:center;">'
                 . '<div style="font-size:40px;margin-bottom:12px;">❌</div>'
                 . '<p style="color:#dc2626;font-weight:600;font-size:16px;">' . esc_html__( 'Reserva no encontrada', 'amir-booking' ) . '</p>'
                 . '<p style="color:#5a7068;font-size:13px;">REF: ' . esc_html( $ref ) . '</p>'
                 . '</div>'
            );
        }

        // Autorización: el link de email/QR trae el token; si el visitante
        // solo pegó el ref, se le pide el email del titular de la reserva.
        // No exponer nombre/fecha/monto a quien no aporte ninguna credencial.
        $manager = new \AmirBooking\Core\BookingManager();
        if ( ! $manager->authorize_public_access( $b, $token, $email ) ) {
            return Languages::run_in( $lang, fn() => self::verify_lookup_form( $lang, $ref, $email !== '' ) );
        }

        // A partir de acá se conoce la reserva: mostrar todo en el idioma
        // con el que el cliente reservó (mismo idioma en que se le mandaron
        // los emails), no en el idioma que el sitio detecte para esta URL —
        // si no, alguien que reservó en inglés puede terminar viendo esta
        // página (y el paso de pago) en español según cómo resuelva Polylang.
        if ( Languages::is_active( (string) $b->lang ) ) {
            $lang = $b->lang;
        }

        return Languages::run_in( $lang, function() use ( $b, $lang, $ref ) {
            $status_map = array(
                'confirmed'              => array( 'label' => __( 'Confirmada',            'amir-booking' ), 'color' => '#1D9E75', 'bg' => '#e8f5e9', 'icon' => '✅' ),
                'completed'              => array( 'label' => __( 'Completada',            'amir-booking' ), 'color' => '#1D9E75', 'bg' => '#e8f5e9', 'icon' => '✅' ),
                'pending'                => array( 'label' => __( 'Pendiente de pago',      'amir-booking' ), 'color' => '#BA7517', 'bg' => '#fef9ec', 'icon' => '⏳' ),
                'wishlist'               => array( 'label' => __( 'Lista de interés',       'amir-booking' ), 'color' => '#6366f1', 'bg' => '#eef2ff', 'icon' => '📋' ),
                'awaiting_payment'       => array( 'label' => __( 'Lista para pagar',        'amir-booking' ), 'color' => '#BA7517', 'bg' => '#fef9ec', 'icon' => '💳' ),
                'cancelled_client'       => array( 'label' => __( 'Cancelada',               'amir-booking' ), 'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => '❌' ),
                'cancelled_weather'      => array( 'label' => __( 'Cancelada (clima)',       'amir-booking' ), 'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => '🌧' ),
                'cancelled_min_pax'      => array( 'label' => __( 'Cancelada (cupo)',        'amir-booking' ), 'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => '❌' ),
                'rescheduled'            => array( 'label' => __( 'Reprogramada',            'amir-booking' ), 'color' => '#6366f1', 'bg' => '#eef2ff', 'icon' => '📅' ),
                'cancellation_requested' => array( 'label' => __( 'Cancelación solicitada',  'amir-booking' ), 'color' => '#f97316', 'bg' => '#fff7ed', 'icon' => '⚠️' ),
            );

            $st = $status_map[ $b->status ] ?? array( 'label' => $b->status, 'color' => '#5a7068', 'bg' => '#f3f4f6', 'icon' => 'ℹ️' );

            $months = array(
                __( 'Enero', 'amir-booking' ), __( 'Febrero', 'amir-booking' ), __( 'Marzo', 'amir-booking' ),
                __( 'Abril', 'amir-booking' ), __( 'Mayo', 'amir-booking' ), __( 'Junio', 'amir-booking' ),
                __( 'Julio', 'amir-booking' ), __( 'Agosto', 'amir-booking' ), __( 'Septiembre', 'amir-booking' ),
                __( 'Octubre', 'amir-booking' ), __( 'Noviembre', 'amir-booking' ), __( 'Diciembre', 'amir-booking' ),
            );

            $date_parts = explode( '-', $b->tour_date );
            $date_fmt   = (int) $date_parts[2] . ' ' . $months[ (int) $date_parts[1] - 1 ] . ' ' . $date_parts[0];

            $tour_name = Languages::tour_field( $b, 'name', $lang );

            // Los horarios (amir_tour_schedules) todavía no tienen contenido
            // multi-idioma más allá de es/en (content_i18n es de amir_tours) —
            // para un idioma 3+ el label del horario cae al español.
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
                $lbl = $lang === 'en' ? ( $b->label_en ?: $b->label_es ) : $b->label_es;
                if ( $lbl ) {
                    $schedule_label .= ' (' . esc_html( $lbl ) . ')';
                }
            }

            $pax_parts = array();
            if ( $b->adults )   $pax_parts[] = $b->adults   . ' ' . __( 'adultos', 'amir-booking' );
            if ( $b->children ) $pax_parts[] = $b->children . ' ' . __( 'niños', 'amir-booking' );
            if ( $b->babies )   $pax_parts[] = $b->babies   . ' ' . __( 'bebés', 'amir-booking' );
            $pax = implode( ' + ', $pax_parts );

            $row = function( $label, $value ) {
                return '<div style="display:flex;justify-content:space-between;align-items:baseline;padding:8px 0;border-bottom:1px solid #f0f0f0;">'
                     . '<span style="font-size:13px;color:#5a7068;">' . esc_html( $label ) . '</span>'
                     . '<span style="font-size:14px;font-weight:600;color:#1a2e24;text-align:right;">' . $value . '</span>'
                     . '</div>';
            };

            $rows  = $row( __( 'Referencia', 'amir-booking' ), '<code style="font-family:monospace;background:#f3f4f6;padding:2px 6px;border-radius:4px;">' . esc_html( $b->booking_ref ) . '</code>' );
            $rows .= $row( __( 'Tour', 'amir-booking' ),       esc_html( $tour_name ) );
            $rows .= $row( __( 'Fecha', 'amir-booking' ),      esc_html( $date_fmt ) );
            if ( $schedule_label ) {
                $rows .= $row( __( 'Salida', 'amir-booking' ), esc_html( $schedule_label ) );
            }
            $rows .= $row( __( 'Pasajeros', 'amir-booking' ), esc_html( $pax ) );
            $rows .= $row( __( 'Pasajero', 'amir-booking' ),  esc_html( $b->customer_name ) );
            $rows .= $row( __( 'Total', 'amir-booking' ),     esc_html( \AmirBooking\Core\Currency::format( (float) $b->total_mxn ) ) );

            $verified_label = __( 'Reserva verificada', 'amir-booking' );

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
                 . '<div style="color:#fff;font-size:18px;font-weight:700;margin-top:8px;">' . esc_html( $st['label'] ) . '</div>'
                 . '<div style="color:rgba(255,255,255,.8);font-size:12px;margin-top:4px;">' . esc_html( $verified_label ) . ' · Amir Adventours</div>'
                 . '</div>'
                 . '<div style="background:#fff;padding:20px 24px 24px;">'
                 . $rows
                 . '</div>'
                 . $pay_widget
                 . '</div>';
        } );
    }

    /**
     * Formulario de búsqueda por referencia + email.
     * Se muestra cuando falta el ref, o cuando hay ref pero ninguna
     * credencial (token del link, o email) autoriza ver los datos.
     * Debe llamarse dentro de Languages::run_in() para que __() traduzca
     * al idioma correcto.
     */
    private static function verify_lookup_form( string $lang, string $ref = '', bool $denied = false ): string {
        $ref_example = ( get_option( 'amir_booking_ref_prefix', 'BK' ) ?: 'BK' ) . '-' . date( 'Y' ) . '-00001';
        $title = __( 'Consulta tu reserva', 'amir-booking' );
        $help  = __( 'Ingresa tu referencia de reserva y el email con el que reservaste.', 'amir-booking' );
        $error = $denied
            ? '<p style="color:#dc2626;font-size:13px;margin:0 0 12px;">'
              . esc_html__( 'Ese email no coincide con esta reserva.', 'amir-booking' )
              . '</p>'
            : '';
        $action = esc_url( remove_query_arg( array( 'ref', 'token', 'email' ) ) );

        return '<div style="font-family:sans-serif;max-width:420px;margin:40px auto;padding:28px 24px;background:#fff;border-radius:12px;border:1px solid #e1f5ee;">'
             . '<h2 style="font-size:17px;margin:0 0 6px;color:#1a2e24;">' . esc_html( $title ) . '</h2>'
             . '<p style="color:#5a7068;font-size:13px;margin:0 0 16px;">' . esc_html( $help ) . '</p>'
             . $error
             . '<form method="get" action="' . $action . '" style="display:flex;flex-direction:column;gap:10px;">'
             . '<input type="text" name="ref" value="' . esc_attr( $ref ) . '" placeholder="' . esc_attr( sprintf( __( 'Referencia (ej. %s)', 'amir-booking' ), $ref_example ) ) . '" required style="padding:10px 12px;border:1px solid #c3d9d0;border-radius:8px;font-size:14px;">'
             . '<input type="email" name="email" placeholder="' . esc_attr__( 'Email con el que reservaste', 'amir-booking' ) . '" required style="padding:10px 12px;border:1px solid #c3d9d0;border-radius:8px;font-size:14px;">'
             . '<button type="submit" style="padding:10px 12px;background:#1D9E75;color:#fff;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;">' . esc_html__( 'Ver mi reserva', 'amir-booking' ) . '</button>'
             . '</form>'
             . '</div>';
    }

    /** Debe llamarse dentro de Languages::run_in() para que __() traduzca al idioma correcto. */
    private static function rate_limited_message(): string {
        return '<div style="font-family:sans-serif;max-width:420px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #fecaca;text-align:center;">'
             . '<p style="color:#dc2626;font-size:14px;margin:0;">' . esc_html__( 'Demasiados intentos. Intenta de nuevo en unos minutos.', 'amir-booking' ) . '</p>'
             . '</div>';
    }

    // ── Marketplace: aprobar/rechazar reserva por email (proveedor externo) ──
    // § 11 CONTRIBUTING.md. GET solo renderiza una pantalla de confirmación
    // sin side-effects — evita que scanners de email/antivirus corporativos
    // (Outlook Safe Links, etc.) disparen la acción por prefetch. La escritura
    // real ocurre recién en el POST de esa misma pantalla.

    public static function provider_action( array $atts ): string {
        $ref   = strtoupper( sanitize_text_field( $_REQUEST['ref'] ?? '' ) );
        $token = sanitize_text_field( $_REQUEST['token'] ?? '' );
        $do    = sanitize_key( $_REQUEST['do'] ?? '' );
        $lang  = self::detect_lang();

        if ( empty( $ref ) || ! in_array( $do, [ 'approve', 'reject' ], true ) ) {
            return Languages::run_in( $lang, fn() => self::provider_action_message( __( 'Link inválido.', 'amir-booking' ), '❌' ) );
        }

        // Throttle por IP, mismo criterio que verify_booking() — frena fuerza
        // bruta contra el token del proveedor.
        if ( RateLimiter::too_many_attempts( 'provider_action_' . RateLimiter::client_ip() ) ) {
            return Languages::run_in( $lang, fn() => self::rate_limited_message() );
        }

        $manager = new \AmirBooking\Core\BookingManager();
        $booking = $manager->get_booking_by_ref( $ref );

        if ( ! $booking ) {
            return Languages::run_in( $lang, fn() => self::provider_action_message( __( 'Reserva no encontrada.', 'amir-booking' ), '❌' ) );
        }

        // Autorización exclusiva por token fuerte del proveedor — nunca por
        // email/booking_ref (ver BookingManager::authorize_provider_access()).
        if ( ! $manager->authorize_provider_access( $booking, $token ) ) {
            return Languages::run_in( $lang, fn() => self::provider_action_message(
                __( 'Este link ya no es válido — puede que ya se haya usado o que haya vencido.', 'amir-booking' ), '⚠️'
            ) );
        }

        if ( $booking->status !== 'pending_provider_approval' ) {
            return Languages::run_in( $lang, fn() => self::provider_action_message( __( 'Esta reserva ya fue procesada.', 'amir-booking' ), 'ℹ️' ) );
        }

        $is_post = ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST';

        if ( $is_post ) {
            if ( ! wp_verify_nonce( sanitize_text_field( $_POST['amir_provider_nonce'] ?? '' ), 'amir_provider_action_' . $ref ) ) {
                return Languages::run_in( $lang, fn() => self::provider_action_message(
                    __( 'No se pudo validar la solicitud — volvé a intentarlo desde el link del email.', 'amir-booking' ), '⚠️'
                ) );
            }

            $reason = mb_substr( sanitize_textarea_field( $_POST['reject_reason'] ?? '' ), 0, 500 );

            if ( $do === 'approve' ) {
                $ok = $manager->provider_approve( (int) $booking->id );
                return Languages::run_in( $lang, fn() => self::provider_action_message(
                    $ok
                        ? __( '¡Listo! La reserva quedó confirmada. Gracias por responder.', 'amir-booking' )
                        : __( 'No se pudo procesar la reserva. Contactá a TourFlow.', 'amir-booking' ),
                    $ok ? '✅' : '❌'
                ) );
            }

            $result = $manager->provider_reject( (int) $booking->id, 'provider_rejected', $reason );
            return Languages::run_in( $lang, fn() => self::provider_action_message(
                $result->success
                    ? __( 'La reserva fue rechazada y se notificó al cliente. Gracias por responder.', 'amir-booking' )
                    : __( 'No se pudo procesar la reserva. Contactá a TourFlow.', 'amir-booking' ),
                $result->success ? '✅' : '❌'
            ) );
        }

        return Languages::run_in( $lang, fn() => self::provider_action_confirm_screen( $booking, $ref, $token, $do ) );
    }

    /** Pantalla de confirmación (GET, sin side-effects) — debe llamarse dentro de Languages::run_in(). */
    private static function provider_action_confirm_screen( object $b, string $ref, string $token, string $do ): string {
        $is_approve = $do === 'approve';
        $title      = $is_approve ? __( '¿Confirmar esta reserva?', 'amir-booking' ) : __( '¿Rechazar esta reserva?', 'amir-booking' );
        $btn_label  = $is_approve ? __( 'Sí, confirmar disponibilidad', 'amir-booking' ) : __( 'Sí, rechazar esta reserva', 'amir-booking' );
        $btn_color  = $is_approve ? '#1D9E75' : '#dc2626';

        $date_parts = explode( '-', $b->tour_date );
        $months = array(
            __( 'Enero', 'amir-booking' ), __( 'Febrero', 'amir-booking' ), __( 'Marzo', 'amir-booking' ),
            __( 'Abril', 'amir-booking' ), __( 'Mayo', 'amir-booking' ), __( 'Junio', 'amir-booking' ),
            __( 'Julio', 'amir-booking' ), __( 'Agosto', 'amir-booking' ), __( 'Septiembre', 'amir-booking' ),
            __( 'Octubre', 'amir-booking' ), __( 'Noviembre', 'amir-booking' ), __( 'Diciembre', 'amir-booking' ),
        );
        $date_fmt = (int) $date_parts[2] . ' ' . $months[ (int) $date_parts[1] - 1 ] . ' ' . $date_parts[0];

        $pax_parts = array();
        if ( $b->adults )   $pax_parts[] = $b->adults   . ' ' . __( 'adultos', 'amir-booking' );
        if ( $b->children ) $pax_parts[] = $b->children . ' ' . __( 'niños', 'amir-booking' );
        if ( $b->babies )   $pax_parts[] = $b->babies   . ' ' . __( 'bebés', 'amir-booking' );
        $pax = implode( ' + ', $pax_parts );

        $reason_field = '';
        if ( ! $is_approve ) {
            $reason_field = '<textarea name="reject_reason" maxlength="500" placeholder="'
                . esc_attr__( 'Motivo (opcional) — se le mostrará al cliente', 'amir-booking' )
                . '" style="width:100%;min-height:80px;padding:10px 12px;border:1px solid #c3d9d0;border-radius:8px;font-size:14px;margin:12px 0;box-sizing:border-box;font-family:inherit;"></textarea>';
        }

        $nonce_field = wp_nonce_field( 'amir_provider_action_' . $ref, 'amir_provider_nonce', true, false );

        return '<div style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:0;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">'
             . '<div style="background:#1a2e24;padding:24px;text-align:center;">'
             . '<div style="color:#fff;font-size:18px;font-weight:700;">' . esc_html( $title ) . '</div>'
             . '</div>'
             . '<div style="background:#fff;padding:24px;">'
             . '<div style="background:#f3f4f6;border-radius:10px;padding:16px;font-size:14px;color:#1a2e24;margin-bottom:16px;">'
             . '<strong>' . esc_html( $b->booking_ref ) . '</strong><br>'
             . esc_html( $date_fmt ) . ' · ' . esc_html( $pax ) . '<br>'
             . esc_html( $b->customer_name )
             . '</div>'
             . '<form method="post">'
             . $nonce_field
             . '<input type="hidden" name="ref" value="' . esc_attr( $ref ) . '">'
             . '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">'
             . '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
             . $reason_field
             . '<button type="submit" style="width:100%;padding:12px;background:' . esc_attr( $btn_color ) . ';color:#fff;border:none;border-radius:8px;font-weight:700;font-size:15px;cursor:pointer;">' . esc_html( $btn_label ) . '</button>'
             . '</form>'
             . '</div>'
             . '</div>';
    }

    /** Mensaje genérico de resultado/error de la acción del proveedor — debe llamarse dentro de Languages::run_in(). */
    private static function provider_action_message( string $message, string $icon = 'ℹ️' ): string {
        return '<div style="font-family:sans-serif;max-width:420px;margin:40px auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #e1f5ee;text-align:center;">'
             . '<div style="font-size:40px;margin-bottom:12px;">' . $icon . '</div>'
             . '<p style="color:#1a2e24;font-size:14px;">' . esc_html( $message ) . '</p>'
             . '</div>';
    }

    // ── Assets ────────────────────────────────────────────────────────────

    private static function enqueue_widget_assets(): void {
        if ( wp_script_is( 'amir-booking-widget', 'enqueued' ) ) {
            return;
        }

        // Google Font elegida en Configuración → Widget de reserva (si no es
        // la fuente de sistema) — antes del inline style para que el @import
        // esté disponible cuando el navegador aplique --ab-font.
        $google_font_url = \AmirBooking\Core\WidgetTheme::google_font_url();
        if ( $google_font_url ) {
            wp_enqueue_style( 'amir-widget-google-font', $google_font_url, [], null );
        }

        wp_enqueue_style(
            'amir-booking-widget',
            AMIR_PLUGIN_URL . 'assets/css/booking-widget.css',
            [],
            AMIR_VERSION
        );

        // Personalización de Configuración → Widget de reserva: color, tipografía,
        // escala de texto y radio de esquinas — sobrescribe las variables CSS
        // del build sin tocar assets/css/booking-widget.css.
        wp_add_inline_style( 'amir-booking-widget', \AmirBooking\Core\WidgetTheme::render_inline_css() );

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
            'i18n'     => self::widget_i18n_map(),
            'activeLanguages'  => Languages::active(),
            'progressLabels'   => \AmirBooking\Core\WidgetTheme::progress_labels(),
            'marketing'        => \AmirBooking\Core\Marketing::widget_config(),
            // Texto de política de cancelación editable desde Configuración
            // — si el operador no cargó nada, el widget sigue mostrando las
            // 3 líneas fijas de siempre (i18n.js: policy_line1/2/3).
            'policyTextEs'     => get_option( 'amir_policy_text_es', '' ),
            'policyTextEn'     => get_option( 'amir_policy_text_en', '' ),
        ] );
    }

    /**
     * Traducciones del widget React para cualquier idioma activo más allá
     * de es/en — react-src/src/i18n.js ya trae es/en hardcodeados (cero
     * riesgo, sin cambios), pero para un idioma nuevo que el operador
     * agregue en Configuración → Idiomas, `useT()` busca acá primero antes
     * de caer al español. Mismas claves y mismo texto en español que
     * `translations.es` de i18n.js — msgid del mismo catálogo .po que
     * traduce emails/voucher, así un traductor solo necesita un archivo.
     */
    private static function widget_i18n_map(): array {
        $extra_langs = array_diff( Languages::active(), [ 'es', 'en' ] );
        if ( empty( $extra_langs ) ) {
            return [];
        }

        // Llamadas a __() literales a propósito (no una variable) para que
        // `wp i18n make-pot` las pueda extraer solas — mismas claves y
        // mismo texto en español que `translations.es` de react-src/src/i18n.js.
        $build = function(): array {
            return [
                'step_date' => __( 'Elige tu fecha', 'amir-booking' ),
                'step_schedule' => __( 'Selecciona horario', 'amir-booking' ),
                'step_people' => __( 'Personas', 'amir-booking' ),
                'step_extras' => __( 'Extras', 'amir-booking' ),
                'extras_title' => __( '¿Querés sumar algún servicio extra?', 'amir-booking' ),
                'extras_included' => __( 'Incluir', 'amir-booking' ),
                'step_details' => __( 'Tus datos', 'amir-booking' ),
                'step_summary' => __( 'Resumen', 'amir-booking' ),
                'step_payment' => __( 'Pago', 'amir-booking' ),
                'step_confirm' => __( '¡Reservado!', 'amir-booking' ),
                'cal_prev' => __( 'Anterior', 'amir-booking' ),
                'cal_next' => __( 'Siguiente', 'amir-booking' ),
                'cal_available' => __( 'Disponible', 'amir-booking' ),
                'cal_full' => __( 'Sin cupos', 'amir-booking' ),
                'no_schedules' => __( 'Sin salidas disponibles para esta fecha.', 'amir-booking' ),
                'cal_blocked' => __( 'No disponible', 'amir-booking' ),
                'cal_past' => __( 'Fecha pasada', 'amir-booking' ),
                'cal_select_date' => __( 'Selecciona una fecha en el calendario', 'amir-booking' ),
                'adults' => __( 'Adultos', 'amir-booking' ),
                'adults_age' => __( '13+ años', 'amir-booking' ),
                'children' => __( 'Niños', 'amir-booking' ),
                'children_age' => __( '4–12 años', 'amir-booking' ),
                'babies' => __( 'Bebés', 'amir-booking' ),
                'babies_age' => __( '0–3 años', 'amir-booking' ),
                'babies_free' => __( 'Gratis', 'amir-booking' ),
                'people_label' => __( 'Total personas', 'amir-booking' ),
                'max_people' => __( 'Máximo {n} personas', 'amir-booking' ),
                'slots_left' => __( '{n} lugares disponibles', 'amir-booking' ),
                'group_people' => __( 'Personas del grupo', 'amir-booking' ),
                'full_name' => __( 'Nombre completo', 'amir-booking' ),
                'full_name_ph' => __( 'Tu nombre y apellido', 'amir-booking' ),
                'email' => __( 'Correo electrónico', 'amir-booking' ),
                'email_ph' => __( 'tu@correo.com', 'amir-booking' ),
                'phone' => __( 'WhatsApp / Teléfono', 'amir-booking' ),
                'phone_ph' => __( '+52 983 000 0000', 'amir-booking' ),
                'lang_pref' => __( 'Idioma preferido', 'amir-booking' ),
                'lang_es' => __( 'Español', 'amir-booking' ),
                'lang_en' => __( 'English', 'amir-booking' ),
                'lang_pref_hint' => __( 'Idioma para tu email de confirmación', 'amir-booking' ),
                'special_req' => __( 'Solicitudes especiales (opcional)', 'amir-booking' ),
                'special_req_ph' => __( 'Alergias, movilidad reducida, celebración especial…', 'amir-booking' ),
                'coupon_label' => __( '¿Tienes un cupón de descuento?', 'amir-booking' ),
                'coupon_ph' => __( 'Código de cupón', 'amir-booking' ),
                'coupon_apply' => __( 'Aplicar', 'amir-booking' ),
                'coupon_applied' => __( 'Cupón aplicado', 'amir-booking' ),
                'tour_date' => __( 'Fecha del tour', 'amir-booking' ),
                'departure_time' => __( 'Hora de salida', 'amir-booking' ),
                'meeting_point' => __( 'Punto de encuentro', 'amir-booking' ),
                'open_maps' => __( 'Ver en mapa', 'amir-booking' ),
                'subtotal' => __( 'Subtotal', 'amir-booking' ),
                'total' => __( 'Total', 'amir-booking' ),
                'usd_ref' => __( '≈ USD {amount} (referencia)', 'amir-booking' ),
                'policy_title' => __( 'Política de cancelación', 'amir-booking' ),
                'policy_line1' => __( '✓ 7+ días antes: reembolso completo', 'amir-booking' ),
                'policy_line2' => __( '▸ 3–6 días antes: reembolso del 50 %', 'amir-booking' ),
                'policy_line3' => __( '✕ Menos de 3 días: sin reembolso', 'amir-booking' ),
                'policy_accept' => __( 'He leído y acepto la política de cancelación', 'amir-booking' ),
                'book_now' => __( 'Confirmar y pagar', 'amir-booking' ),
                'pay_secure' => __( 'Pago seguro con Stripe', 'amir-booking' ),
                'pay_methods' => __( 'Tarjeta, Apple Pay, Google Pay', 'amir-booking' ),
                'processing' => __( 'Procesando…', 'amir-booking' ),
                'confirmed_title' => __( '¡Tu reserva está confirmada!', 'amir-booking' ),
                'confirmed_sub' => __( 'Te enviamos todos los detalles a tu correo.', 'amir-booking' ),
                'booking_ref' => __( 'Número de reserva', 'amir-booking' ),
                'download_pdf' => __( 'Descargar voucher PDF', 'amir-booking' ),
                'add_calendar' => __( 'Agregar al calendario', 'amir-booking' ),
                'share_whatsapp' => __( 'Compartir por WhatsApp', 'amir-booking' ),
                'need_help' => __( '¿Necesitas ayuda? Escríbenos por WhatsApp', 'amir-booking' ),
                'err_required' => __( 'Este campo es obligatorio', 'amir-booking' ),
                'err_email' => __( 'Ingresa un correo válido', 'amir-booking' ),
                'err_no_slots' => __( 'No hay cupos disponibles para esta fecha', 'amir-booking' ),
                'err_payment' => __( 'Error al procesar el pago. Por favor intenta de nuevo.', 'amir-booking' ),
                'err_generic' => __( 'Ocurrió un error. Por favor intenta de nuevo.', 'amir-booking' ),
                'err_max_pax' => __( 'Se excede el máximo de {n} personas', 'amir-booking' ),
                'back' => __( 'Atrás', 'amir-booking' ),
                'continue' => __( 'Continuar', 'amir-booking' ),
                'loading' => __( 'Cargando…', 'amir-booking' ),
                'free' => __( 'Gratis', 'amir-booking' ),
                'per_person' => __( 'por persona', 'amir-booking' ),
                'includes' => __( 'Incluye', 'amir-booking' ),
                'not_included' => __( 'No incluye', 'amir-booking' ),
                'duration' => __( 'Duración', 'amir-booking' ),
                'min_age_label' => __( 'Edad mínima', 'amir-booking' ),
                'languages' => __( 'Idiomas', 'amir-booking' ),
                'min' => __( 'min', 'amir-booking' ),
                'years' => __( 'años', 'amir-booking' ),
            ];
        };

        $map = [];
        foreach ( $extra_langs as $lang ) {
            $map[ $lang ] = Languages::run_in( $lang, $build );
        }
        return $map;
    }

    // ── Detectar idioma activo (compatible con Polylang / WPML) ──────────

    private static function detect_lang(): string {
        // Polylang
        if ( function_exists( 'pll_current_language' ) ) {
            $lang = pll_current_language( 'slug' );
            return \AmirBooking\Core\Languages::is_active( (string) $lang ) ? $lang : \AmirBooking\Core\Languages::default_lang();
        }
        // WPML
        if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
            $lang = ICL_LANGUAGE_CODE;
            return \AmirBooking\Core\Languages::is_active( (string) $lang ) ? $lang : \AmirBooking\Core\Languages::default_lang();
        }
        // Fallback: lang del sitio WP
        $lang = substr( get_locale(), 0, 2 );
        return \AmirBooking\Core\Languages::is_active( $lang ) ? $lang : \AmirBooking\Core\Languages::default_lang();
    }
}
