<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gestor de reservas.
 * Crea, confirma, cancela y reprograma reservas.
 * Orquesta disponibilidad, precios, Stripe y emails.
 */
class BookingManager {

    private AvailabilityEngine $availability;
    private PricingEngine      $pricing;

    public function __construct() {
        $this->availability = new AvailabilityEngine();
        $this->pricing      = new PricingEngine();
    }

    /**
     * Registro de consentimiento (GDPR, § 15.3 CONTRIBUTING.md, pieza 3 de
     * 4) — hasta 2026-08-04 el plugin solo guardaba un booleano
     * ("aceptó") sin cuándo ni qué texto vio, insuficiente para poder
     * demostrar consentimiento real si hiciera falta. `consent_recorded_at`
     * es el timestamp del momento exacto de la reserva (nunca se pisa
     * después — a diferencia de `created_at`, que sí se resetea al
     * transicionar una reserva de wishlist/manual a pending vía
     * init-payment, § BookingController::init_payment()). `consent_text_hash`
     * es un hash de los textos de política+términos configurados en ese
     * idioma al momento — permite detectar después si el operador cambió
     * el texto sin tener que guardar el texto completo en cada reserva.
     * Reusado tal cual por RoomBookingManager (mismo criterio, sin duplicar).
     */
    public static function consent_snapshot( string $lang ): array {
        $lang = in_array( $lang, [ 'es', 'en' ], true ) ? $lang : 'es';
        $policy_text = get_option( "amir_policy_text_{$lang}", '' );
        $terms_text  = get_option( "amir_terms_text_{$lang}", '' );
        return [
            'consent_recorded_at' => current_time( 'mysql' ),
            'consent_text_hash'   => md5( $lang . '|' . $policy_text . '|' . $terms_text ),
        ];
    }

    // ── Crear reserva (estado pending) ────────────────────────────────────

    /**
     * Crea una reserva en estado 'pending' y devuelve el booking_ref.
     * El cupo queda temporalmente reservado. Si el pago no se completa
     * en amir_pending_expire_mins minutos, se libera automáticamente.
     */
    public function create_pending( array $data ): BookingResult {
        global $wpdb;

        // Si no hay schedule_id, tomar el primero disponible del tour
        $schedule_id = (int) ( $data['schedule_id'] ?? 0 );
        if ( $schedule_id === 0 ) {
            $first = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}amir_tour_schedules WHERE tour_id = %d AND active = 1 ORDER BY sort_order ASC, time_start ASC LIMIT 1",
                (int) $data['tour_id']
            ) );
            $schedule_id = (int) $first;
            // Si aún no hay horarios, usar 0 (reserva sin horario asignado)
        }
        $data['schedule_id'] = $schedule_id;

        // El widget ya oculta los contadores de niños/bebés cuando el tour
        // no los admite (ver StepPeople en BookingWidget.jsx) — esto es la
        // validación real, por si alguien llama la API directo.
        $policy_error = $this->child_baby_policy_error(
            (int) $data['tour_id'], (int) ( $data['children'] ?? 0 ), (int) ( $data['babies'] ?? 0 )
        );
        if ( $policy_error ) {
            return BookingResult::error( $policy_error );
        }

        // El widget ya deshabilita el botón de confirmar hasta tildar el
        // checkbox de política de cancelación — esto es la validación real,
        // por si alguien llama la API directo sin pasar por el widget.
        if ( empty( $data['policy_accepted'] ) ) {
            return BookingResult::error( 'Debes aceptar la política de cancelación para continuar.' );
        }

        // Mismo criterio que arriba: el widget ya deshabilita el botón hasta
        // tildar ambos checkboxes, esto es la validación real del lado server.
        // Checkbox separado del de cancelación a propósito (GDPR pide
        // consentimientos específicos, no empaquetados en uno solo).
        if ( empty( $data['terms_accepted'] ) ) {
            return BookingResult::error( 'Debes aceptar los términos y condiciones para continuar.' );
        }

        // Mínimo de personas POR RESERVA (amir_tours.min_passengers) —
        // pedido del cliente 2026-08-21, ver CONTRIBUTING.md § 16.81. El
        // widget/flujos ya bloquean el botón de continuar en este caso —
        // esto es la validación real, por si alguien llama la API directo.
        // Distinto del mínimo AGREGADO que ya usa class-cron-manager.php
        // (suma TODAS las reservas confirmadas de una salida para avisar si
        // conviene cancelar) — este es el piso de UNA reserva puntual, y se
        // aplica sin importar el tipo de tour (fecha fija, "solo a pedido",
        // proveedor externo, etc. — todos pasan por acá antes de bifurcar).
        $min_pax_error = $this->min_passengers_error(
            (int) $data['tour_id'],
            (int) $data['adults'] + (int) ( $data['children'] ?? 0 ) + (int) ( $data['babies'] ?? 0 ),
            $data['lang'] ?? 'es'
        );
        if ( $min_pax_error ) {
            return BookingResult::error( $min_pax_error );
        }

        // "Requiere nombre de cada integrante" (amir_tours.require_participant_
        // names, pedido del cliente 2026-08-24) — opt-in por tour, la mayoría
        // no lo necesita. El widget ya pide un input por persona cuando el
        // tour lo requiere; esto es la validación real del lado server.
        // Bebés quedan afuera a propósito (no se identifican individualmente
        // en un manifiesto de pasajeros) — ver participant_names_error().
        $names_error = $this->participant_names_error(
            (int) $data['tour_id'],
            (int) $data['adults'] + (int) ( $data['children'] ?? 0 ),
            is_array( $data['participant_names'] ?? null ) ? $data['participant_names'] : [],
            $data['lang'] ?? 'es'
        );
        if ( $names_error ) {
            return BookingResult::error( $names_error );
        }

        // "Armá tu tour" (amir_tours.custom_quote, variante de request_only,
        // pedido 2026-08-16) — el tour no tiene precio ni horario cargado a
        // propósito, así que nada de lo que sigue (disponibilidad, cupos,
        // PricingEngine) aplica: se delega a un camino sin calendario ni
        // cotización, el operador carga el precio real al aprobar.
        $is_custom_quote = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT custom_quote FROM {$wpdb->prefix}amir_tours WHERE id = %d", (int) $data['tour_id']
        ) );
        if ( $is_custom_quote ) {
            return $this->create_custom_quote_request( $data );
        }

        // Validar disponibilidad
        $avail = $this->availability->check(
            (int) $data['tour_id'],
            $data['date'],
            (int) $data['schedule_id']
        );

        if ( ! $avail->available ) {
            return BookingResult::error( 'No hay disponibilidad: ' . $avail->reason );
        }

        // Validar que los cupos alcanzan para los pax solicitados
        $pax = (int)$data['adults'] + (int)$data['children'] + (int)$data['babies'];
        if ( $pax > $avail->slots_remaining ) {
            return BookingResult::error(
                $this->slots_left_error( $avail->slots_remaining, $data['lang'] ?? 'es' )
            );
        }

        // Calcular precio (con cupón y add-ons si se enviaron)
        $quote = $this->pricing->quote(
            (int) $data['tour_id'],
            (int) $data['schedule_id'],
            $data['date'],
            (int) $data['adults'],
            (int) $data['children'],
            (int) $data['babies'],
            sanitize_text_field( $data['coupon_code'] ?? '' ),
            is_array( $data['addons'] ?? null ) ? $data['addons'] : [],
            $data['lang'] ?? 'es'
        );

        if ( ! $quote->is_valid() ) {
            return BookingResult::error( 'Error de precio: ' . $quote->error );
        }

        // Determinar origen y partner
        $partner_id     = $this->resolve_partner_id( $data['partner_token'] ?? '' );
        $booking_source = $this->resolve_source( $data['source'] ?? 'direct', $partner_id );

        // Generar número de reserva único + token de acceso público
        $booking_ref  = $this->generate_ref();
        $access_token = self::generate_access_token();

        // ── Transacción con bloqueo para prevenir overbooking concurrente ──────
        $wpdb->query( 'START TRANSACTION' );

        // Re-leer cupos ocupados con bloqueo exclusivo (FOR UPDATE)
        // Esto garantiza que dos peticiones simultáneas no pasen la misma validación
        $booked_pax = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(adults + children + babies), 0)
             FROM {$wpdb->prefix}amir_bookings
             WHERE tour_id = %d AND schedule_id = %d AND tour_date = %s
               AND status IN ('pending', 'confirmed')
             FOR UPDATE",
            (int) $data['tour_id'],
            (int) $data['schedule_id'],
            $data['date']
        ) );

        $tour_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT max_capacity, provider_id, provider_charge_mode, request_only, deposit_enabled, deposit_pct FROM {$wpdb->prefix}amir_tours WHERE id = %d",
            (int) $data['tour_id']
        ) );
        $max_capacity = (int) ( $tour_row->max_capacity ?? 0 );

        if ( $pax > ( $max_capacity - $booked_pax ) ) {
            $wpdb->query( 'ROLLBACK' );
            return BookingResult::error(
                $this->slots_left_error( max( 0, $max_capacity - $booked_pax ), $data['lang'] ?? 'es' )
            );
        }

        // Cobro diferido a la confirmación del proveedor (§ 11.0
        // CONTRIBUTING.md) — si el tour es de un proveedor activo Y está en
        // modo 'on_approval', la reserva arranca directo en
        // pending_provider_approval SIN cobrar nada; recién cuando el
        // proveedor aprueba se manda el link de pago real (provider_approve()).
        // Así, si rechaza o vence el plazo, no hay nada que reembolsar.
        $provider_id_for_tour = (int) ( $tour_row->provider_id ?? 0 );
        $provider_active = $provider_id_for_tour > 0 && (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT active FROM {$wpdb->prefix}amir_providers WHERE id = %d", $provider_id_for_tour
        ) );
        $is_deferred_charge = $provider_active && ( $tour_row->provider_charge_mode ?? 'immediate' ) === 'on_approval';

        // Tours "solo a pedido" (amir_tours.request_only, § CLAUDE.md) —
        // conservan su calendario normal (el cliente elige fecha/horario
        // como siempre), pero CADA reserva nace en 'date_requested' en vez
        // de confirmarse/cobrarse: mismo mecanismo que "solicitar fecha" en
        // tours de fecha fija (§ 16.37 CONTRIBUTING.md), reusado acá como
        // el camino principal en vez del fallback. Mutuamente excluyente
        // con el cobro diferido de proveedor (ese ya tiene su propio flujo
        // de aprobación con cobro de entrada).
        $is_request_only = ! $is_deferred_charge && (bool) ( $tour_row->request_only ?? 0 );

        $booking_status = $is_deferred_charge
            ? 'pending_provider_approval'
            : ( $is_request_only ? 'date_requested' : 'pending' );

        // Depósito parcial por tour (Pro Max, "Depósito parcial por tour" en
        // CONTRIBUTING.md) — mutuamente excluyente con cobro diferido de
        // proveedor y "solo a pedido" (esos ya tienen su propio "cuánto y
        // cuándo cobrar", un tour no puede ser las dos cosas a la vez). El %
        // se snapshotea en la reserva — si el operador lo cambia después en
        // el tour, no afecta reservas ya hechas (mismo criterio que
        // cancellation_policy_pct).
        $deposit_pct_applied = 0;
        if ( ! $is_deferred_charge && ! $is_request_only && (bool) ( $tour_row->deposit_enabled ?? 0 ) ) {
            $cfg_pct = (int) ( $tour_row->deposit_pct ?? 0 );
            if ( $cfg_pct >= 1 && $cfg_pct <= 99 ) {
                $deposit_pct_applied = $cfg_pct;
            }
        }
        $charge_mxn = $deposit_pct_applied > 0
            ? round( $quote->total_mxn * $deposit_pct_applied / 100, 2 )
            : $quote->total_mxn;

        // Cupón que cubre el 100% del total (Dudas de producto, CLAUDE.md:
        // "un cupón de 100% deja el cobro en $0, que Stripe no puede
        // procesar"). No es un modo de tour aparte como cobro diferido/solo
        // a pedido — puede pasarle a cualquier tour normal si el cupón
        // alcanza. Nace 'pending' igual que siempre y se confirma con
        // confirm() apenas se inserta (mismo camino que dispara un webhook
        // real: email de confirmación, voucher, Google Calendar) — así no
        // hay que duplicar esa lógica ni pasar por ninguna pasarela.
        $is_free_booking = ! $is_deferred_charge && ! $is_request_only && $charge_mxn <= 0;

        // Insertar en DB
        $insert_data = [
            'booking_ref'     => $booking_ref,
            'access_token'    => $access_token,
            'tour_id'         => (int) $data['tour_id'],
            'schedule_id'     => (int) $data['schedule_id'],
            'partner_id'      => $partner_id,
            'tour_date'       => $data['date'],
            'status'          => $booking_status,
            'booking_source'  => $booking_source,
            'lang'            => $data['lang'] ?? 'es',
            'customer_name'   => sanitize_text_field( $data['customer_name'] ),
            'customer_email'  => sanitize_email( $data['customer_email'] ),
            'customer_phone'  => sanitize_text_field( $data['customer_phone'] ?? '' ),
            'adults'          => (int) $data['adults'],
            'children'        => (int) $data['children'],
            'babies'          => (int) $data['babies'],
            'total_mxn'       => $quote->total_mxn,
            'usd_reference'   => $quote->usd_reference,
            'exchange_rate'   => $this->pricing->get_exchange_rate(),
            'coupon_code'     => $quote->coupon_code,
            'discount_mxn'    => $quote->discount_mxn,
            'deposit_pct'     => $deposit_pct_applied,
            'special_requests'=> sanitize_textarea_field( $data['special_requests'] ?? '' ),
            'participant_names' => $this->encode_participant_names( $data['participant_names'] ?? [] ),
            'created_at'      => current_time( 'mysql' ),
        ];
        $insert_data     += self::consent_snapshot( $data['lang'] ?? 'es' );
        $insert_formats = [ '%s','%s','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%f','%f','%f','%s','%f','%d','%s','%s','%s','%s' ];

        if ( $is_deferred_charge ) {
            $insert_data['provider_response_token'] = self::generate_access_token();
            $insert_data['provider_notified_at']    = current_time( 'mysql' );
            $insert_formats[] = '%s';
            $insert_formats[] = '%s';
        }

        $inserted = $wpdb->insert( "{$wpdb->prefix}amir_bookings", $insert_data, $insert_formats );

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return BookingResult::error( 'Error al guardar la reserva. Por favor intenta de nuevo.' );
        }

        $booking_id = $wpdb->insert_id;

        // Persistir los add-ons ya validados/tarifados por el quote — a
        // diferencia del cupón (que se marca usado fuera de la transacción
        // a propósito, ver abajo), esto SÍ va dentro: es parte de lo
        // cobrado, si falla debe revertirse la reserva completa.
        foreach ( $quote->breakdown as $item ) {
            if ( ( $item['type'] ?? '' ) !== 'addon' ) {
                continue;
            }
            $addon_inserted = $wpdb->insert(
                "{$wpdb->prefix}amir_booking_addons",
                [
                    'booking_id'     => $booking_id,
                    'addon_id'       => (int) $item['addon_id'],
                    'qty'            => (int) $item['qty'],
                    'unit_price_mxn' => (float) $item['unit_mxn'],
                    'total_mxn'      => (float) $item['total_mxn'],
                    'name_snapshot'  => (string) $item['name'],
                ],
                [ '%d', '%d', '%d', '%f', '%f', '%s' ]
            );
            if ( ! $addon_inserted ) {
                $wpdb->query( 'ROLLBACK' );
                return BookingResult::error( 'Error al guardar los servicios extra. Por favor intenta de nuevo.' );
            }
        }

        $wpdb->query( 'COMMIT' );

        // Marcar el cupón como usado (fuera de la transacción de cupos —
        // si esto falla no debe tirar abajo una reserva ya confirmada)
        if ( $quote->coupon_id > 0 ) {
            ( new CouponEngine() )->mark_used( $quote->coupon_id );
        }

        // Invalidar caché de disponibilidad para este tour/mes
        $date_parts = explode( '-', $data['date'] );
        if ( count( $date_parts ) === 3 ) {
            delete_transient( "amir_avail_{$data['tour_id']}_{$date_parts[0]}_{$date_parts[1]}" );
        }

        if ( $is_deferred_charge ) {
            // Mismos tres emails que dispara confirm() para el modo normal
            // (proveedor + aviso interino al cliente + admin) — acá arrancan
            // en el momento de crear la reserva, no después de cobrar, porque
            // en este modo no hay cobro previo.
            do_action( 'amir_booking_pending_provider_approval', $booking_id );
        } elseif ( $is_request_only ) {
            do_action( 'amir_booking_date_requested', $booking_id );
        } elseif ( $is_free_booking ) {
            // Reusa confirm() tal cual — mismo efecto que si un webhook real
            // hubiera avisado un cobro exitoso (email de confirmación,
            // voucher, Google Calendar), sin duplicar esa lógica. charge_id
            // sintético para que quede trazable en Log de pagos que esto no
            // pasó por ninguna pasarela real.
            $this->confirm( $booking_id, 'coupon-100pct' );
        }

        $result = new BookingResult( true, $booking_id, $booking_ref, $quote->total_mxn, $quote );
        $result->charge_mxn       = $charge_mxn;
        $result->requires_payment = ! $is_deferred_charge && ! $is_request_only && ! $is_free_booking;
        $result->status           = $is_free_booking ? 'confirmed' : $booking_status;
        return $result;
    }

    // ── "Armá tu tour" — solicitud sin fecha ni precio ──────────────────────

    /**
     * Variante de create_pending() para tours `custom_quote` (§ CLAUDE.md,
     * "armá tu tour" — pedido 2026-08-16): a diferencia del resto de los
     * modos, acá NO hay calendario que validar ni cupo que bloquear (el tour
     * no tiene horarios cargados), así que no tiene sentido reusar la
     * transacción con FOR UPDATE de create_pending() — esta reserva no
     * compite por un cupo de una fecha real. `tour_date` guarda la fecha en
     * que se hizo la SOLICITUD (la columna es NOT NULL, no se puede dejar
     * vacía) — el pedido real del cliente vive en `special_requests`, el
     * admin ve esto reflejado en el panel (BookingsPage::render_detail()).
     * El precio nace en $0 — el operador lo carga a mano al aprobar
     * (BookingsPage::handle_detail_action(), case 'approve_date_request').
     */
    private function create_custom_quote_request( array $data ): BookingResult {
        global $wpdb;

        if ( empty( $data['customer_name'] ) || empty( $data['customer_email'] ) ) {
            return BookingResult::error( 'Nombre y email del cliente son obligatorios.' );
        }
        if ( ! is_email( $data['customer_email'] ) ) {
            return BookingResult::error( 'Email del cliente no válido.' );
        }

        $adults   = max( 1, (int) ( $data['adults']   ?? 1 ) );
        $children = max( 0, (int) ( $data['children'] ?? 0 ) );
        $babies   = max( 0, (int) ( $data['babies']   ?? 0 ) );

        $policy_error = $this->child_baby_policy_error( (int) $data['tour_id'], $children, $babies );
        if ( $policy_error ) {
            return BookingResult::error( $policy_error );
        }

        $booking_ref  = $this->generate_ref();
        $access_token = self::generate_access_token();

        $insert_data = [
            'booking_ref'      => $booking_ref,
            'access_token'     => $access_token,
            'tour_id'          => (int) $data['tour_id'],
            'schedule_id'      => 0,
            'tour_date'        => current_time( 'Y-m-d' ),
            'status'           => 'date_requested',
            'booking_source'   => 'date_request',
            'lang'             => $data['lang'] ?? 'es',
            'customer_name'    => sanitize_text_field( $data['customer_name'] ),
            'customer_email'   => sanitize_email( $data['customer_email'] ),
            'customer_phone'   => sanitize_text_field( $data['customer_phone'] ?? '' ),
            'adults'           => $adults,
            'children'         => $children,
            'babies'           => $babies,
            'total_mxn'        => 0.0,
            'usd_reference'    => 0.0,
            'exchange_rate'    => $this->pricing->get_exchange_rate(),
            'special_requests' => sanitize_textarea_field( $data['special_requests'] ?? '' ),
            'created_at'       => current_time( 'mysql' ),
        ];
        $insert_data   += self::consent_snapshot( $data['lang'] ?? 'es' );
        $insert_formats = [
            '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s',
        ];

        $inserted = $wpdb->insert( "{$wpdb->prefix}amir_bookings", $insert_data, $insert_formats );

        if ( ! $inserted ) {
            return BookingResult::error( 'Error al guardar tu solicitud. Por favor intenta de nuevo.' );
        }

        $booking_id = (int) $wpdb->insert_id;
        do_action( 'amir_booking_date_requested', $booking_id );

        $result = new BookingResult( true, $booking_id, $booking_ref, 0.0, null );
        $result->requires_payment = false;
        return $result;
    }

    // ── Crear reserva de lista de interés (estado 'wishlist') ──────────────

    /**
     * Crea una reserva real para un tour todavía en borrador ("avísame
     * cuando abra"): misma fecha/horario/personas/datos que una reserva
     * normal, pero SIN cobrar y SIN bloquear cupo — el tour ni siquiera
     * está abierto para reservar todavía. El precio se congela con el
     * cotizador actual para no depender de que el operador no cambie
     * tarifas entre el interés y la apertura real.
     *
     * No pasa por AvailabilityEngine a propósito: mientras el tour está en
     * borrador queremos poder medir demanda incluso por encima del cupo
     * configurado (para decidir, por ejemplo, si conviene abrir una segunda
     * fecha), no bloquear anotados.
     */
    public function create_wishlist( array $data ): BookingResult {
        global $wpdb;

        $tour_id     = (int) ( $data['tour_id'] ?? 0 );
        $schedule_id = (int) ( $data['schedule_id'] ?? 0 );
        $date        = sanitize_text_field( $data['date'] ?? '' );
        $adults      = max( 1, (int) ( $data['adults'] ?? 1 ) );
        $children    = max( 0, (int) ( $data['children'] ?? 0 ) );
        $babies      = max( 0, (int) ( $data['babies'] ?? 0 ) );

        if ( ! $tour_id || ! $date || ! strtotime( $date ) ) {
            return BookingResult::error( 'Tour y fecha son obligatorios.' );
        }
        if ( empty( $data['customer_name'] ) || empty( $data['customer_email'] ) ) {
            return BookingResult::error( 'Nombre y email son obligatorios.' );
        }
        if ( ! is_email( $data['customer_email'] ) ) {
            return BookingResult::error( 'Email no válido.' );
        }

        $policy_error = $this->child_baby_policy_error( $tour_id, $children, $babies );
        if ( $policy_error ) {
            return BookingResult::error( $policy_error );
        }

        // Sin schedule_id explícito, tomar el primero configurado del tour
        if ( $schedule_id === 0 ) {
            $first = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}amir_tour_schedules WHERE tour_id = %d AND active = 1 ORDER BY sort_order ASC, time_start ASC LIMIT 1",
                $tour_id
            ) );
            $schedule_id = (int) $first;
        }

        $lang  = in_array( $data['lang'] ?? '', [ 'es', 'en' ], true ) ? $data['lang'] : 'es';
        $quote = $this->pricing->quote( $tour_id, $schedule_id, $date, $adults, $children, $babies );

        // Evitar duplicar si la misma persona ya se había anotado para el
        // mismo tour/horario/fecha — actualiza sus datos en vez de sumar
        // una fila nueva (por ejemplo, si reintenta el formulario).
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amir_bookings
             WHERE tour_id = %d AND schedule_id = %d AND tour_date = %s
               AND customer_email = %s AND status = 'wishlist'",
            $tour_id, $schedule_id, $date, sanitize_email( $data['customer_email'] )
        ) );

        $row = [
            'customer_name'    => sanitize_text_field( $data['customer_name'] ),
            'customer_phone'   => sanitize_text_field( $data['customer_phone'] ?? '' ),
            'adults'           => $adults,
            'children'         => $children,
            'babies'           => $babies,
            'total_mxn'        => $quote->is_valid() ? $quote->total_mxn : 0.0,
            'usd_reference'    => $quote->is_valid() ? $quote->usd_reference : 0.0,
            'exchange_rate'    => $this->pricing->get_exchange_rate(),
            'special_requests' => sanitize_textarea_field( $data['special_requests'] ?? '' ),
        ];

        if ( $existing_id ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings", $row,
                [ 'id' => (int) $existing_id ],
                [ '%s', '%s', '%d', '%d', '%d', '%f', '%f', '%f', '%s' ], [ '%d' ]
            );
            $booking = $this->get_booking( (int) $existing_id );
            return new BookingResult( true, (int) $existing_id, $booking->booking_ref, $row['total_mxn'], $quote->is_valid() ? $quote : null );
        }

        $booking_ref  = $this->generate_ref();
        $access_token = self::generate_access_token();

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}amir_bookings",
            array_merge( $row, [
                'booking_ref'    => $booking_ref,
                'access_token'   => $access_token,
                'tour_id'        => $tour_id,
                'schedule_id'    => $schedule_id,
                'tour_date'      => $date,
                'status'         => 'wishlist',
                'booking_source' => 'wishlist',
                'lang'           => $lang,
                'customer_email' => sanitize_email( $data['customer_email'] ),
                'created_at'     => current_time( 'mysql' ),
            ] ),
            [
                '%s', '%s', '%d', '%d', '%d', '%f', '%f', '%f', '%s', // $row
                '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s',
            ]
        );

        if ( ! $inserted ) {
            return BookingResult::error( 'Error al guardar tu registro. Por favor intenta de nuevo.' );
        }

        $booking_id = (int) $wpdb->insert_id;

        return new BookingResult( true, $booking_id, $booking_ref, $row['total_mxn'], $quote->is_valid() ? $quote : null );
    }

    // ── Crear solicitud de fecha (estado 'date_requested') ─────────────────

    /**
     * Un tour de fecha fija (amir_tours.fixed_date) solo se reserva en ESA
     * fecha — esto crea el pedido de una fecha distinta, para que el
     * operador la evalúe manualmente. Mismo patrón que create_wishlist():
     * reserva real con precio ya congelado, SIN cobrar y SIN pasar por
     * AvailabilityEngine (la fecha pedida no es una fecha abierta todavía,
     * no hay cupo que verificar contra ella). A diferencia de wishlist, acá
     * $date es la fecha que el CLIENTE propone, no la fecha fija del tour.
     *
     * Decisión cerrada con el cliente 2026-08-08: NO es el patrón de
     * proveedores externos (que cobra de entrada y aprueba/rechaza lo ya
     * pagado) — es "aprobar → mandar link de pago", igual que Lista de
     * interés. Ver WishlistPage::notify_interested() para el mecanismo de
     * aprobación (reusado tal cual para una sola reserva en BookingsPage).
     */
    public function create_date_request( array $data ): BookingResult {
        global $wpdb;

        $tour_id     = (int) ( $data['tour_id'] ?? 0 );
        $schedule_id = (int) ( $data['schedule_id'] ?? 0 );
        $date        = sanitize_text_field( $data['date'] ?? '' );
        $adults      = max( 1, (int) ( $data['adults'] ?? 1 ) );
        $children    = max( 0, (int) ( $data['children'] ?? 0 ) );
        $babies      = max( 0, (int) ( $data['babies'] ?? 0 ) );

        if ( ! $tour_id || ! $date || ! strtotime( $date ) ) {
            return BookingResult::error( 'Tour y fecha son obligatorios.' );
        }
        if ( empty( $data['customer_name'] ) || empty( $data['customer_email'] ) ) {
            return BookingResult::error( 'Nombre y email son obligatorios.' );
        }
        if ( ! is_email( $data['customer_email'] ) ) {
            return BookingResult::error( 'Email no válido.' );
        }

        $policy_error = $this->child_baby_policy_error( $tour_id, $children, $babies );
        if ( $policy_error ) {
            return BookingResult::error( $policy_error );
        }

        $min_pax_error = $this->min_passengers_error(
            $tour_id, $adults + $children + $babies, $data['lang'] ?? 'es'
        );
        if ( $min_pax_error ) {
            return BookingResult::error( $min_pax_error );
        }

        // Sin schedule_id explícito, tomar el primero configurado del tour
        // — los horarios no dependen de la fecha, mismo criterio que
        // create_wishlist().
        if ( $schedule_id === 0 ) {
            $first = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}amir_tour_schedules WHERE tour_id = %d AND active = 1 ORDER BY sort_order ASC, time_start ASC LIMIT 1",
                $tour_id
            ) );
            $schedule_id = (int) $first;
        }

        $lang  = in_array( $data['lang'] ?? '', [ 'es', 'en' ], true ) ? $data['lang'] : 'es';
        $quote = $this->pricing->quote( $tour_id, $schedule_id, $date, $adults, $children, $babies );

        $booking_ref  = $this->generate_ref();
        $access_token = self::generate_access_token();

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}amir_bookings",
            [
                'booking_ref'      => $booking_ref,
                'access_token'     => $access_token,
                'tour_id'          => $tour_id,
                'schedule_id'      => $schedule_id,
                'tour_date'        => $date,
                'status'           => 'date_requested',
                'booking_source'   => 'date_request',
                'lang'             => $lang,
                'customer_name'    => sanitize_text_field( $data['customer_name'] ),
                'customer_email'   => sanitize_email( $data['customer_email'] ),
                'customer_phone'   => sanitize_text_field( $data['customer_phone'] ?? '' ),
                'adults'           => $adults,
                'children'         => $children,
                'babies'           => $babies,
                'total_mxn'        => $quote->is_valid() ? $quote->total_mxn : 0.0,
                'usd_reference'    => $quote->is_valid() ? $quote->usd_reference : 0.0,
                'exchange_rate'    => $this->pricing->get_exchange_rate(),
                'special_requests' => sanitize_textarea_field( $data['special_requests'] ?? '' ),
                'created_at'       => current_time( 'mysql' ),
            ],
            [
                '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s',
            ]
        );

        if ( ! $inserted ) {
            return BookingResult::error( 'Error al guardar tu solicitud. Por favor intenta de nuevo.' );
        }

        $booking_id = (int) $wpdb->insert_id;

        // Mismo hook que dispara el camino "solo a pedido" de create_pending()
        // — antes este camino ("solicitar otra fecha" en tour de fecha fija)
        // no disparaba nada acá, y BookingController::request_date() armaba
        // a mano solo el registro interno para el admin (sin email a nadie,
        // ni admin ni cliente). Bug real reportado por el cliente
        // (2026-08-14): el widget le prometía al cliente un email que nunca
        // llegaba. Unificado para que ambos orígenes de 'date_requested'
        // avisen igual (admin + cliente).
        do_action( 'amir_booking_date_requested', $booking_id );

        return new BookingResult( true, $booking_id, $booking_ref, $quote->is_valid() ? $quote->total_mxn : 0.0, $quote->is_valid() ? $quote : null );
    }

    // ── Crear reserva manual (confirmada directamente) ────────────────────

    /**
     * Crea una reserva manual directamente como 'confirmed' (sin pago online).
     * Usada desde el panel de administración para reservas por teléfono, WhatsApp, etc.
     */
    public function create_manual( array $data ): BookingResult {
        global $wpdb;

        // wp_unslash() acá, no en cada caller — ambos call sites
        // (BookingsPage/FieldPage) pasan $_POST tal cual, y WordPress le
        // agrega backslashes a todo $_POST (wp_magic_quotes()) antes de que
        // el código del plugin lo vea. Sin esto, un nombre de cliente con
        // comilla se guarda corrupto (bug real reportado 2026-08-05).
        $data = wp_unslash( $data );

        $tour_id     = (int) ( $data['tour_id'] ?? 0 );
        $schedule_id = (int) ( $data['schedule_id'] ?? 0 );
        $date        = sanitize_text_field( $data['date'] ?? '' );
        $adults      = max( 1, (int) ( $data['adults'] ?? 1 ) );
        $children    = max( 0, (int) ( $data['children'] ?? 0 ) );
        $babies      = max( 0, (int) ( $data['babies'] ?? 0 ) );
        $total_mxn   = (float) ( $data['total_mxn'] ?? 0 );

        if ( ! $tour_id || ! $date || ! strtotime( $date ) ) {
            return BookingResult::error( 'Tour y fecha son obligatorios.' );
        }
        if ( empty( $data['customer_name'] ) || empty( $data['customer_email'] ) ) {
            return BookingResult::error( 'Nombre y email del cliente son obligatorios.' );
        }
        if ( ! is_email( $data['customer_email'] ) ) {
            return BookingResult::error( 'Email del cliente no válido.' );
        }

        $pax = $adults + $children + $babies;

        // Bloquear fila y verificar capacidad dentro de una transacción
        $wpdb->query( 'START TRANSACTION' );

        $booked_pax = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(adults + children + babies), 0)
             FROM {$wpdb->prefix}amir_bookings
             WHERE tour_id = %d AND schedule_id = %d AND tour_date = %s
               AND status IN ('pending','confirmed')
             FOR UPDATE",
            $tour_id, $schedule_id, $date
        ) );

        $avail = $this->availability->check( $tour_id, $date, $schedule_id );
        if ( $pax > $avail->slots_remaining ) {
            $wpdb->query( 'ROLLBACK' );
            return BookingResult::error(
                $this->slots_left_error( $avail->slots_remaining, $data['lang'] ?? 'es' )
            );
        }

        // Calcular precio automático si no se especifica
        if ( $total_mxn <= 0 ) {
            $quote     = $this->pricing->quote( $tour_id, $schedule_id, $date, $adults, $children, $babies );
            $total_mxn = $quote->is_valid() ? $quote->total_mxn : 0.0;
        }

        $booking_ref     = $this->generate_ref();
        $access_token    = self::generate_access_token();
        $partner_id      = ! empty( $data['partner_id'] ) ? (int) $data['partner_id'] : null;
        $now             = current_time( 'mysql' );
        $awaiting_payment = ! empty( $data['awaiting_payment'] );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'amir_bookings',
            array(
                'booking_ref'       => $booking_ref,
                'access_token'      => $access_token,
                'tour_id'           => $tour_id,
                'schedule_id'       => $schedule_id,
                'partner_id'        => $partner_id,
                'tour_date'         => $date,
                'status'            => $awaiting_payment ? 'awaiting_payment' : 'confirmed',
                // Selector de plataforma agregado 2026-08-04 — antes quedaba
                // hardcodeado a 'manual' sin importar lo que el admin eligiera
                // (cable suelto documentado, CONTRIBUTING.md Tarea 15).
                'booking_source'    => in_array( $data['booking_source'] ?? '', [ 'manual', 'tripadvisor', 'getyourguide' ], true )
                    ? $data['booking_source'] : 'manual',
                'lang'              => in_array( $data['lang'] ?? '', array('es','en'), true ) ? $data['lang'] : 'es',
                'customer_name'     => sanitize_text_field( $data['customer_name'] ),
                'customer_email'    => sanitize_email( $data['customer_email'] ),
                'customer_phone'    => sanitize_text_field( $data['customer_phone'] ?? '' ),
                'adults'            => $adults,
                'children'          => $children,
                'babies'            => $babies,
                'total_mxn'         => $total_mxn,
                'special_requests'  => sanitize_textarea_field( $data['special_requests'] ?? '' ),
                'internal_notes'    => sanitize_textarea_field(
                    ( $awaiting_payment ? '' : ( ! empty( $data['payment_method_note'] ) ? 'Pago: ' . $data['payment_method_note'] . "\n" : '' ) )
                    . ( $data['internal_notes'] ?? '' )
                ),
                'custom_email_note' => sanitize_textarea_field( $data['custom_email_note'] ?? '' ),
                'confirmed_at'      => $awaiting_payment ? null : $now,
            ),
            array( '%s','%s','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%f','%s','%s','%s','%s' )
        );

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return BookingResult::error( 'Error de base de datos al crear la reserva.' );
        }

        $booking_id = (int) $wpdb->insert_id;
        $wpdb->query( 'COMMIT' );

        // Invalidar caché de disponibilidad
        $date_parts = explode( '-', $date );
        if ( count( $date_parts ) === 3 ) {
            delete_transient( "amir_avail_{$tour_id}_{$date_parts[0]}_{$date_parts[1]}" );
        }

        if ( $awaiting_payment ) {
            // Sin voucher/QR todavía — recién existen una vez que el pago
            // se confirma de verdad (mismo criterio que wishlist/confirm()).
            $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
            $booking    = $dispatcher->get_booking_with_tour( $booking_id );
            if ( $booking ) {
                $dispatcher->send_payment_link_notice( $booking );
            }

            return new BookingResult( true, $booking_id, $booking_ref, $total_mxn );
        }

        // Generar PDF y QR en shutdown (no bloquea la respuesta)
        add_action( 'shutdown', function() use ( $booking_id ) {
            try {
                $gen  = new VoucherGenerator();
                $bk   = $this->get_booking( $booking_id );
                $path = $gen->generate( $booking_id );
                $qr   = $bk ? $gen->generate_qr( $booking_id, $bk->booking_ref ) : '';
                if ( $path || $qr ) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'amir_bookings',
                        array( 'pdf_voucher_path' => $path, 'qr_code_path' => $qr ),
                        array( 'id' => $booking_id ),
                        array( '%s', '%s' ),
                        array( '%d' )
                    );
                }
            } catch ( \Throwable $e ) {
                error_log( 'Amir manual booking PDF error: ' . $e->getMessage() );
            }
        } );

        // Enviar email de confirmación si se solicitó
        if ( ! empty( $data['send_email'] ) ) {
            do_action( 'amir_booking_confirmed', $booking_id );
        }

        return new BookingResult( true, $booking_id, $booking_ref, $total_mxn );
    }

    // ── Confirmar reserva post-pago ───────────────────────────────────────

    /**
     * Llamado desde el webhook de la pasarela activa (Stripe o Mercado
     * Pago, ambos pasan por acá — ver PaymentGatewayInterface).
     * Cambia estado a 'confirmed', genera QR y PDF, envía email.
     *
     * `stripe_charge_id` se sigue completando por compatibilidad hacia
     * atrás (reportes/código viejo que todavía lo lee directo), pero
     * `gateway_charge_id` es el campo genérico a usar de acá en más —
     * ver CONTRIBUTING.md § 5.1 (higiene de nombres heredados de Stripe).
     */
    public function confirm( int $booking_id, string $charge_id ): bool {
        global $wpdb;

        $booking = $this->get_booking( $booking_id );

        // Depósito parcial ("Depósito parcial por tour") — un cobro que
        // llega sobre una reserva YA `confirmed`, con depósito cobrado y
        // saldo pendiente, es el pago del SALDO restante (link de "saldo
        // restante" mandado aparte vía init_payment()), no una confirmación
        // nueva: no hay que reenviar el email/voucher de "reserva
        // confirmada" completo — la reserva ya estaba confirmada — solo
        // registrar que el saldo se cobró.
        if ( $booking && $booking->status === 'confirmed'
             && ( $booking->item_type ?? 'tour' ) === 'tour'
             && (int) ( $booking->deposit_pct ?? 0 ) > 0
             && empty( $booking->balance_paid_at )
        ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'balance_paid_at' => current_time( 'mysql' ) ],
                [ 'id' => $booking_id ],
                [ '%s' ],
                [ '%d' ]
            );
            do_action( 'amir_booking_balance_paid', $booking_id );
            return true;
        }

        // 'awaiting_payment' (lista de interés ya publicada, o solicitud de
        // fecha ya aprobada — en ambos casos el cliente todavía no pagó, solo
        // tiene el link) se suma acá a propósito: antes esta función SOLO
        // aceptaba 'pending', así que no había forma de que el admin marcara
        // como pagada a mano una reserva en ese estado (ej. pago recibido
        // por transferencia en vez de por el link) — quedaba atascada sin
        // ninguna acción posible. finalize_confirmation() de abajo no
        // depende de cuál era el estado previo, así que no hay riesgo.
        if ( ! $booking || ! in_array( $booking->status, [ 'pending', 'awaiting_payment' ], true ) ) {
            return false;
        }

        // El cobro ya ocurrió sin importar si el tour tiene proveedor — eso
        // solo decide si la reserva queda 'confirmed' directo o pasa antes
        // por la aprobación del proveedor (marketplace, § 11 CONTRIBUTING.md).
        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [
                'stripe_charge_id'  => $charge_id,
                'gateway_charge_id' => $charge_id,
            ],
            [ 'id' => $booking_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // provider_responded_at ya seteado = este booking pasó por
        // provider_approve() en modo 'on_approval' (§ 11.0 CONTRIBUTING.md):
        // el proveedor YA aprobó antes, sin cobrar nada — este confirm() es
        // el pago que llegó recién ahora, por el link. No corresponde
        // volver a pedirle aprobación al proveedor una segunda vez.
        if ( $this->tour_has_active_provider( (int) $booking->tour_id ) && empty( $booking->provider_responded_at ) ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [
                    'status'                  => 'pending_provider_approval',
                    'provider_response_token' => self::generate_access_token(),
                    'provider_notified_at'    => current_time( 'mysql' ),
                ],
                [ 'id' => $booking_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );

            // Dispara el email al proveedor (con los links de aprobar/rechazar)
            // y el aviso interino al cliente — ver EmailDispatcher.
            do_action( 'amir_booking_pending_provider_approval', $booking_id );
            return true;
        }

        $this->finalize_confirmation( $booking_id, $charge_id );

        // Cobro diferido (§ 11.0): el proveedor ya había aprobado sin cobrar
        // nada — recién ahora, con el pago del cliente ya confirmado de
        // verdad, corresponde generar la fila de liquidación (antes de esto
        // hubiera sido prematuro: la aprobación no garantizaba que el
        // cliente fuera a completar el pago del link).
        if ( ! empty( $booking->provider_responded_at ) ) {
            do_action( 'amir_provider_booking_approved', $booking_id );
        }

        return true;
    }

    /**
     * Segunda mitad de confirm() para el caso normal (sin proveedor):
     * marca 'confirmed', dispara el email/voucher de siempre. También la
     * usa provider_approve() cuando el proveedor aprueba una reserva que
     * ya estaba cobrada — el cliente no nota ninguna diferencia.
     */
    private function finalize_confirmation( int $booking_id, string $charge_id ): void {
        global $wpdb;

        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [
                'status'             => 'confirmed',
                'stripe_charge_id'   => $charge_id,
                'gateway_charge_id'  => $charge_id,
                'confirmed_at'       => current_time( 'mysql' ),
            ],
            [ 'id' => $booking_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        // Reserva de habitación (§ 16 CONTRIBUTING.md, item_type='room'):
        // amir_booking_confirmed no hace nada acá — get_booking_with_tour()
        // hace INNER JOIN con amir_tours, que no existe para esta reserva
        // (tour_id NULL), así que el email de siempre nunca se dispara. Hook
        // propio en vez de dejarlo fallar en silencio sin avisarle al
        // cliente. Voucher/QR quedan sin construir todavía para habitaciones
        // (VoucherGenerator también asume tour vía el mismo tipo de JOIN).
        $booking = $this->get_booking( $booking_id );
        if ( ( $booking->item_type ?? 'tour' ) === 'room' ) {
            do_action( 'flow_room_booking_confirmed', $booking_id );
            $this->create_admin_notification( 'new_booking', $booking_id );
            return;
        }

        // Venta suelta de un producto digital (§ 16.9x CONTRIBUTING.md,
        // item_type='product') — mismo motivo que 'room' arriba: sin tour,
        // el email/voucher de siempre no aplican. Hook propio, escuchado en
        // EmailDispatcher, que manda el link tokenizado de descarga
        // (GET /flow/v1/addons/download/{booking_addon_id}?token=...) — el
        // mismo endpoint que ya usa cualquier extra digital comprado dentro
        // de un carrito con tour, sin cambios.
        if ( ( $booking->item_type ?? 'tour' ) === 'product' ) {
            do_action( 'flow_product_order_confirmed', $booking_id );
            $this->create_admin_notification( 'new_booking', $booking_id );
            return;
        }

        // Email de confirmación — disparado por el hook amir_booking_confirmed
        // (no usar cron + do_action simultáneamente: causaría doble envío)
        do_action( 'amir_booking_confirmed', $booking_id );

        // Generar QR y voucher PDF en background
        add_action( 'shutdown', function() use ( $booking_id ) {
            try {
                $gen  = new VoucherGenerator();
                $path = $gen->generate( $booking_id );
                $bk   = $this->get_booking( $booking_id );
                $qr   = $bk ? $gen->generate_qr( $booking_id, $bk->booking_ref ) : '';

                if ( $path || $qr ) {
                    global $wpdb;
                    $wpdb->update(
                        "{$wpdb->prefix}amir_bookings",
                        [
                            'pdf_voucher_path' => $path,
                            'qr_code_path'     => $qr,
                        ],
                        [ 'id' => $booking_id ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                }
            } catch ( \Throwable $e ) {
                // No bloquear el flujo si el PDF falla
                error_log( 'Amir Booking PDF error: ' . $e->getMessage() );
            }
        } );

        // Notificar al admin
        $this->create_admin_notification( 'new_booking', $booking_id );
    }

    /**
     * El proveedor aprobó la reserva (vía el link tokenizado del email) —
     * invalida el token (un solo uso) y termina de confirmarla igual que
     * cualquier otra reserva pagada.
     */
    public function provider_approve( int $booking_id ): bool {
        global $wpdb;

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking || $booking->status !== 'pending_provider_approval' ) {
            return false;
        }

        // UPDATE condicionado a que siga en pending_provider_approval —
        // atómico, mismo motivo que en cancel(): dos clicks casi simultáneos
        // en el link de aprobar (doble tap, reintento de red) no deben
        // disparar finalize_confirmation()/el hook de liquidación dos veces
        // para la misma reserva (duplicaría la fila en amir_provider_payouts).
        $updated = $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [
                'provider_responded_at'   => current_time( 'mysql' ),
                'provider_response_token' => null,
            ],
            [ 'id' => $booking_id, 'status' => 'pending_provider_approval' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        if ( ! $updated ) {
            return false;
        }

        $charge_id = $booking->gateway_charge_id ?: $booking->stripe_charge_id;

        // Modo 'on_approval' (§ 11.0 CONTRIBUTING.md): esta reserva nunca se
        // cobró (no hay charge_id) — recién ahora, con la aprobación en
        // mano, se manda el link de pago real (mismo mecanismo que wishlist/
        // reserva manual). amir_provider_booking_approved (ledger de
        // liquidación) se dispara recién en confirm(), cuando el cliente
        // efectivamente pague — acá todavía no hay nada que liquidar.
        if ( empty( $charge_id ) ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'status' => 'awaiting_payment', 'created_at' => current_time( 'mysql' ) ],
                [ 'id' => $booking_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            // Desacoplado vía hook (igual que el resto de esta clase, ver
            // amir_process_gateway_refund/amir_provider_booking_approved) en
            // vez de instanciar EmailDispatcher acá directo — BookingManager
            // no conoce la capa de emails.
            do_action( 'amir_provider_awaiting_payment', $booking_id );

            return true;
        }

        $this->finalize_confirmation( $booking_id, $charge_id );

        // Desacoplado igual que amir_process_gateway_refund — el ledger de
        // liquidación (amir_provider_payouts) escucha este hook, BookingManager
        // no conoce esa tabla directamente.
        do_action( 'amir_provider_booking_approved', $booking_id );

        return true;
    }

    /**
     * El proveedor rechazó la reserva, o venció el plazo de respuesta sin
     * contestar (cron, ver class-cron-manager.php). En ambos casos se
     * cancela con reembolso 100% (no es responsabilidad del cliente) —
     * se distinguen por $reason_type, no por status separado.
     *
     * $reason_type: 'provider_rejected' (click explícito) | 'provider_expired' (cron 48h)
     */
    public function provider_reject( int $booking_id, string $reason_type, string $reject_reason = '' ): BookingResult {
        global $wpdb;

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            return BookingResult::error( 'Reserva no encontrada' );
        }
        if ( $booking->status !== 'pending_provider_approval' ) {
            return BookingResult::error( 'Esta reserva ya fue procesada.' );
        }

        $update  = [ 'provider_response_token' => null ];
        $formats = [ '%s' ];

        // Vencimiento del plazo no es una "respuesta" real del proveedor —
        // se deja provider_responded_at vacío para poder distinguir después
        // "rechazó" de "nunca contestó" en reportes.
        if ( $reason_type === 'provider_rejected' ) {
            $update['provider_responded_at'] = current_time( 'mysql' );
            $formats[] = '%s';
            if ( $reject_reason !== '' ) {
                $update['provider_reject_reason'] = $reject_reason;
                $formats[] = '%s';
            }
        }

        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            $update,
            [ 'id' => $booking_id ],
            $formats,
            [ '%d' ]
        );

        return $this->cancel(
            $booking_id,
            $reason_type,
            $reject_reason !== '' ? "Motivo del proveedor: {$reject_reason}" : ''
        );
    }

    /**
     * Autoriza al proveedor externo a aprobar/rechazar una reserva vía el
     * link tokenizado del email — a propósito NO reusa authorize_public_access()
     * (esa tiene un fallback débil por email pensado para el cliente). El
     * proveedor solo autoriza por token fuerte; si ya se usó/venció
     * (provider_response_token es NULL), no autoriza nunca.
     *
     * Importante: provider_response_token es un secreto DISTINTO del
     * access_token del cliente — si se compartiera, el cliente podría usar
     * su propio link de "verificar mi reserva" para forzar un rechazo con
     * reembolso 100%, saltándose la política de cancelación escalonada.
     */
    public function authorize_provider_access( object $booking, string $token ): bool {
        if ( $token === '' || empty( $booking->provider_response_token ) ) {
            return false;
        }
        return hash_equals( (string) $booking->provider_response_token, $token );
    }

    /**
     * Resuelve si el tour de una reserva tiene un proveedor externo activo
     * asignado — determina si confirm() bifurca a pending_provider_approval.
     * Un proveedor inactivo se trata igual que "sin proveedor" (tour propio).
     */
    private function tour_has_active_provider( int $tour_id ): bool {
        global $wpdb;
        $provider_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT provider_id FROM {$wpdb->prefix}amir_tours WHERE id = %d",
            $tour_id
        ) );
        if ( $provider_id <= 0 ) {
            return false;
        }
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT active FROM {$wpdb->prefix}amir_providers WHERE id = %d",
            $provider_id
        ) );
    }

    // ── Cancelar reserva ──────────────────────────────────────────────────

    public function cancel(
        int    $booking_id,
        string $reason_type = 'client',  // client | weather | min_pax | provider_rejected | provider_expired
        string $internal_note = ''
    ): BookingResult {
        global $wpdb;

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            return BookingResult::error( 'Reserva no encontrada' );
        }
        if ( ! in_array( $booking->status, [ 'pending', 'confirmed', 'pending_provider_approval' ], true ) ) {
            return BookingResult::error( 'Esta reserva ya fue cancelada o completada' );
        }

        // Calcular política de reembolso
        $refund = $this->calculate_refund( $booking, $reason_type );

        $status_map = [
            'weather'           => 'cancelled_weather',
            'min_pax'           => 'cancelled_min_pax',
            'provider_rejected' => 'cancelled_provider',
            'provider_expired'  => 'cancelled_provider',
        ];
        $new_status = isset( $status_map[ $reason_type ] ) ? $status_map[ $reason_type ] : 'cancelled_client';

        // UPDATE condicionado al status leído arriba — atómico: si otro
        // proceso ya canceló/confirmó esta misma reserva entre el SELECT y
        // acá (el cron de vencimiento de 48h del proveedor y un click de
        // "rechazar" casi simultáneos, un doble click, un reintento de red),
        // esto da 0 filas afectadas y cortamos antes de reembolsar dos veces
        // la misma reserva. Sin este guard, wpdb->update() con solo
        // ['id' => $booking_id] en el WHERE no distingue esos casos.
        $updated = $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [
                'status'                  => $new_status,
                'cancellation_policy_pct' => $refund['charge_pct'],
                'refund_amount_mxn'       => $refund['refund_mxn'],
                'internal_notes'          => $booking->internal_notes . "\n[" . current_time( 'mysql' ) . "] " . $internal_note,
            ],
            [ 'id' => $booking_id, 'status' => $booking->status ],
            [ '%s', '%d', '%f', '%s' ],
            [ '%d', '%s' ]
        );

        if ( ! $updated ) {
            return BookingResult::error( 'Esta reserva ya fue cancelada o completada' );
        }

        // Procesar reembolso con la pasarela que corresponda (Stripe o MP)
        $charge_ref = $booking->gateway_charge_id ?: $booking->stripe_charge_id;
        if ( $refund['refund_mxn'] > 0 && $charge_ref ) {
            do_action( 'amir_process_gateway_refund', $booking_id, $refund['refund_mxn'] );
        }

        do_action( 'amir_booking_cancelled', $booking_id, $reason_type );

        return new BookingResult( true, $booking_id, $booking->booking_ref, (float)$refund['refund_mxn'], null, '', $refund['message'] );
    }

    // ── Política de reembolso ─────────────────────────────────────────────

    /**
     * Calcula el reembolso según la política de cancelación.
     *
     * Fuerza mayor (clima, mínimo pax): siempre 100%
     * Cliente:
     *   7+ días antes  → 100% reembolso (0% cargo)
     *   3–6 días antes → 50% reembolso (50% cargo)
     *   0–2 días antes → 0% reembolso (100% cargo)
     */
    private function calculate_refund( object $booking, string $reason_type ): array {
        // Depósito parcial por tour ("Depósito parcial por tour",
        // CONTRIBUTING.md) — la política de cancelación (100/50/0% según
        // antelación) se aplica sobre el monto REALMENTE cobrado (el
        // depósito), nunca sobre total_mxn completo: si solo se cobró un
        // 20% de depósito, reembolsar total_mxn sería devolver plata que
        // nunca se cobró. deposit_pct=0 (default, sin depósito) deja
        // $charged_mxn === total_mxn, comportamiento idéntico al de antes.
        $charged_mxn = ( $booking->deposit_pct ?? 0 ) > 0
            ? round( (float) $booking->total_mxn * (int) $booking->deposit_pct / 100, 2 )
            : (float) $booking->total_mxn;

        // Cancelación por el operador, o por el proveedor externo (rechazo o
        // vencimiento del plazo de aprobación) = siempre reembolso total, no
        // es responsabilidad del cliente.
        if ( in_array( $reason_type, [ 'weather', 'min_pax', 'provider_rejected', 'provider_expired' ], true ) ) {
            return [
                'charge_pct' => 0,
                'refund_mxn' => $charged_mxn,
                'message'    => 'Reembolso total procesado.',
            ];
        }

        // Reservas de plataformas externas: el cargo lo gestiona la plataforma
        if ( in_array( $booking->booking_source, [ 'tripadvisor', 'getyourguide' ], true ) ) {
            return [
                'charge_pct' => 0,
                'refund_mxn' => 0.0,
                'message'    => 'Reserva externa. La política de reembolso aplica en la plataforma de origen.',
            ];
        }

        $today      = new \DateTime( current_time( 'Y-m-d' ) );
        $tour_date  = new \DateTime( $booking->tour_date );
        $days_until = (int) $today->diff( $tour_date )->days;

        // Si ya pasó la fecha, no hay reembolso
        if ( $tour_date < $today ) {
            return [
                'charge_pct' => 100,
                'refund_mxn' => 0.0,
                'message'    => 'El tour ya se realizó. No aplica reembolso.',
            ];
        }

        if ( $days_until >= 7 ) {
            return [
                'charge_pct' => 0,
                'refund_mxn' => $charged_mxn,
                'message'    => 'Reembolso total (cancelación con 7+ días de anticipación).',
            ];
        }

        if ( $days_until >= 3 ) {
            $refund = round( $charged_mxn * 0.50, 2 );
            return [
                'charge_pct' => 50,
                'refund_mxn' => $refund,
                'message'    => sprintf( 'Reembolso del 50%% (cancelación con %d días de anticipación).', $days_until ),
            ];
        }

        // 0–2 días: sin reembolso
        return [
            'charge_pct' => 100,
            'refund_mxn' => 0.0,
            'message'    => 'Sin reembolso (cancelación dentro de los 2 días previos al tour).',
        ];
    }

    // ── Reprogramar reserva ───────────────────────────────────────────────

    public function reschedule( int $booking_id, string $new_date, int $new_schedule_id ): BookingResult {
        global $wpdb;

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            return BookingResult::error( 'Reserva no encontrada' );
        }

        // Verificar disponibilidad en nueva fecha
        $avail = $this->availability->check(
            (int) $booking->tour_id,
            $new_date,
            $new_schedule_id
        );

        if ( ! $avail->available ) {
            return BookingResult::error( 'La nueva fecha no tiene disponibilidad.' );
        }

        // Verificar que el grupo completo cabe en los cupos disponibles
        $pax = (int)$booking->adults + (int)$booking->children + (int)$booking->babies;
        if ( $pax > $avail->slots_remaining ) {
            return BookingResult::error(
                $this->slots_left_error( $avail->slots_remaining, $booking->lang ?? 'es' )
            );
        }

        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [
                'tour_date'   => $new_date,
                'schedule_id' => $new_schedule_id,
                'status'      => 'confirmed',
            ],
            [ 'id' => $booking_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        do_action( 'amir_booking_rescheduled', $booking_id );

        return new BookingResult( true, $booking_id, $booking->booking_ref );
    }

    // ── Marcar completadas ────────────────────────────────────────────────

    /**
     * Cron job diario: marca como completadas las reservas cuya fecha ya pasó.
     */
    public function mark_completed_bookings(): void {
        global $wpdb;

        $yesterday = date( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}amir_bookings
                 SET status = 'completed'
                 WHERE status = 'confirmed'
                   AND tour_date <= %s",
                $yesterday
            )
        );
    }

    /**
     * Cron job: liberar reservas pending expiradas.
     */
    public function release_expired_pending(): void {
        global $wpdb;

        $expire_mins = (int) get_option( 'amir_pending_expire_mins', 15 );
        // Usar current_time para respetar la zona horaria configurada en WordPress
        $cutoff      = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $expire_mins * 60 ) );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}amir_bookings
                 SET status = 'cancelled_client'
                 WHERE status = 'pending'
                   AND created_at < %s",
                $cutoff
            )
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function get_booking( int $booking_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d",
                $booking_id
            )
        );
    }

    public function get_booking_by_ref( string $ref ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE booking_ref = %s",
                strtoupper( $ref )
            )
        );
    }

    /**
     * Autoriza el acceso público a una reserva (endpoints REST, página de
     * verificación, descarga de PDF) sin exponerla a cualquiera que adivine
     * el booking_ref, que es secuencial (AMIR-2026-00001, -00002, …).
     *
     * Acepta DOS credenciales posibles, cualquiera de las dos autoriza:
     *  - access_token: el valor largo y aleatorio que viaja en los links
     *    de email/QR/PDF generados por el propio plugin (credencial fuerte).
     *  - email: el correo del cliente, para mantener compatible el flujo
     *    del widget de reservas mientras no reciba el token en su UI
     *    (credencial débil — solo mientras se actualiza el frontend).
     *
     * Usa hash_equals() para evitar timing attacks al comparar el token.
     */
    public function authorize_public_access( object $booking, string $token = '', string $email = '' ): bool {
        if ( $token !== '' && ! empty( $booking->access_token ) ) {
            if ( hash_equals( (string) $booking->access_token, $token ) ) {
                return true;
            }
        }
        if ( $email !== '' && strtolower( $email ) === strtolower( (string) $booking->customer_email ) ) {
            return true;
        }
        return false;
    }

    /**
     * Genera un access_token aleatorio de 32 bytes (64 caracteres hex).
     * Suficiente entropía para que no sea practicable de adivinar por fuerza bruta.
     */
    public static function generate_access_token(): string {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * Público (no solo private) a propósito: TourFlow\Rooms\RoomBookingManager
     * reusa esta misma secuencia — una reserva de habitación vive en la
     * misma tabla amir_bookings (§ 16 CONTRIBUTING.md, decisión de
     * generalizar en vez de duplicar toda la infraestructura de pago), así
     * que comparte el mismo correlativo de booking_ref que las de tours.
     */
    public function generate_ref(): string {
        global $wpdb;
        $year   = date( 'Y' );
        $prefix = get_option( 'amir_booking_ref_prefix', 'BK' ) ?: 'BK';

        // Usar MAX en lugar de COUNT para evitar colisiones tras cancelaciones/borrados.
        // Cuenta solo dentro del prefijo actual — si el operador lo cambia, arranca de
        // nuevo desde 00001 con el prefijo nuevo (las referencias viejas no se tocan).
        $last = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(booking_ref, '-', -1) AS UNSIGNED)), 0)
                 FROM {$wpdb->prefix}amir_bookings
                 WHERE booking_ref LIKE %s",
                "{$prefix}-{$year}-%"
            )
        );

        return sprintf( '%s-%s-%05d', $prefix, $year, $last + 1 );
    }

    /**
     * Mensaje de "no alcanza el cupo" en el idioma de la reserva — bug real
     * reportado por el cliente: salía siempre en español sin importar el
     * idioma del widget, porque `sprintf()` no pasa por ningún mecanismo de
     * traducción. Mismo patrón ya usado para el bug idéntico en emails
     * (`EmailTexts`, CONTRIBUTING.md § 16.34): `amir-booking-en_US.mo`
     * nunca existió, así que un `__()` acá caería igual al msgid en
     * español para 'en' — por eso es un diccionario chico en código, no
     * gettext. Mismo criterio que el resto de esta clase para idiomas no
     * es/en (columnas `lang` solo validan es/en, cualquier otro cae a es).
     */
    private function slots_left_error( int $remaining, string $lang ): string {
        return $lang === 'en'
            ? sprintf( 'Only %d spot(s) left', $remaining )
            : sprintf( 'Solo quedan %d cupos disponibles', $remaining );
    }

    /**
     * Mínimo de personas POR RESERVA (amir_tours.min_passengers) — un tour
     * puede exigir, por ejemplo, mínimo 2 personas por reserva individual.
     * Devuelve el mensaje de error bilingüe, o null si no hay problema.
     */
    private function min_passengers_error( int $tour_id, int $pax, string $lang ): ?string {
        global $wpdb;
        $min_passengers = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT min_passengers FROM {$wpdb->prefix}amir_tours WHERE id = %d",
            $tour_id
        ) );
        if ( $min_passengers <= 1 || $pax >= $min_passengers ) {
            return null;
        }
        return $lang === 'en'
            ? sprintf( 'This tour requires a minimum of %d people per booking.', $min_passengers )
            : sprintf( 'Este tour requiere un mínimo de %d personas por reserva.', $min_passengers );
    }

    /** Sanitiza y arma el JSON que se guarda en amir_bookings.participant_names — '' (no NULL, la columna no distingue) si no vino ninguno. */
    private function encode_participant_names( array $names ): string {
        $clean = array_values( array_filter( array_map(
            fn( $n ) => trim( sanitize_text_field( (string) $n ) ),
            $names
        ) ) );
        return $clean ? wp_json_encode( $clean ) : '';
    }

    /**
     * $required_count es adultos+niños (bebés excluidos, ver comentario en
     * create_pending()). Devuelve el mensaje de error, o null si el tour no
     * requiere nombres o si vinieron los suficientes nombres no vacíos.
     */
    private function participant_names_error( int $tour_id, int $required_count, array $names, string $lang ): ?string {
        global $wpdb;
        $requires = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT require_participant_names FROM {$wpdb->prefix}amir_tours WHERE id = %d",
            $tour_id
        ) );
        if ( ! $requires ) {
            return null;
        }
        $valid_names = array_values( array_filter( array_map(
            fn( $n ) => trim( sanitize_text_field( (string) $n ) ),
            $names
        ) ) );
        if ( count( $valid_names ) >= $required_count ) {
            return null;
        }
        return $lang === 'en'
            ? sprintf( 'This tour requires the full name of each participant (%d needed).', $required_count )
            : sprintf( 'Este tour requiere el nombre completo de cada integrante (%d necesarios).', $required_count );
    }

    /**
     * Rechaza niños/bebés en un tour que no los admite (`amir_tours.allow_children`/
     * `allow_babies`) — el widget ya oculta esos contadores en ese caso, esto
     * cubre el caso de alguien llamando la API directo con esos valores igual.
     * Devuelve el mensaje de error, o null si no hay problema.
     */
    private function child_baby_policy_error( int $tour_id, int $children, int $babies ): ?string {
        if ( $children <= 0 && $babies <= 0 ) {
            return null;
        }
        global $wpdb;
        $tour = $wpdb->get_row( $wpdb->prepare(
            "SELECT allow_children, allow_babies FROM {$wpdb->prefix}amir_tours WHERE id = %d",
            $tour_id
        ) );
        if ( ! $tour ) {
            return null; // Tour inexistente: lo va a rechazar la validación de disponibilidad/precio de todas formas.
        }
        // `?? 1`, no solo un fallback de fila ausente: si por lo que sea la
        // columna no viene en el resultado, admite por default — igual que
        // el DEFAULT real de la columna en la base — para nunca bloquear
        // una reserva real por un dato faltante.
        if ( $children > 0 && ! (int) ( $tour->allow_children ?? 1 ) ) {
            return 'Este tour no admite niños.';
        }
        if ( $babies > 0 && ! (int) ( $tour->allow_babies ?? 1 ) ) {
            return 'Este tour no admite bebés.';
        }
        return null;
    }

    private function resolve_partner_id( string $token ): ?int {
        if ( empty( $token ) ) {
            return null;
        }
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}amir_partners WHERE tracking_token = %s AND active = 1",
                $token
            )
        );
        return $id ? (int) $id : null;
    }

    private function resolve_source( string $source, ?int $partner_id ): string {
        if ( $partner_id ) {
            return 'partner';
        }
        return in_array( $source, [ 'direct', 'tripadvisor', 'getyourguide', 'partner' ], true )
            ? $source
            : 'direct';
    }

    private function create_admin_notification( string $type, int $booking_id ): void {
        global $wpdb;

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            return;
        }

        $messages = [
            'new_booking' => sprintf(
                'Nueva reserva %s — %s (%d pax) el %s',
                $booking->booking_ref,
                $booking->customer_name,
                $booking->adults + $booking->children + $booking->babies,
                $booking->tour_date
            ),
        ];

        $wpdb->insert(
            "{$wpdb->prefix}amir_notifications",
            [
                'type'    => $type,
                'title'   => 'Nueva reserva',
                'message' => $messages[ $type ] ?? '',
                'data'    => json_encode( [ 'booking_id' => $booking_id ] ),
                'is_read' => 0,
            ],
            [ '%s', '%s', '%s', '%s', '%d' ]
        );
    }
}

/**
 * Value object con el resultado de una operación de reserva.
 */
/**
 * Value object with booking operation result. PHP 7.4+ compatible.
 */
class BookingResult {

    /** @var bool */
    public $success;
    /** @var int */
    public $booking_id;
    /** @var string */
    public $booking_ref;
    /** @var float */
    public $total_mxn;
    /**
     * @var float Monto a cobrar AHORA vía la pasarela — default = total_mxn
     * (así ningún call site existente cambia de comportamiento sin
     * declararlo explícito). Distinto de total_mxn solo cuando el tour
     * tiene depósito parcial activo (deposit_enabled, § CONTRIBUTING.md
     * "Depósito parcial por tour") — ahí es total_mxn × deposit_pct/100.
     * total_mxn NUNCA cambia de significado: sigue siendo el precio total
     * real del tour (reportes, voucher, liquidación a proveedores).
     */
    public $charge_mxn;
    /** @var PriceQuote|null */
    public $quote;
    /** @var string */
    public $error;
    /** @var string */
    public $message;
    /**
     * @var bool false solo para reservas de proveedor en modo 'on_approval'
     * (§ 11.0 CONTRIBUTING.md) — la reserva se creó sin cobrar nada todavía,
     * el controlador no debe intentar iniciar un cobro con la pasarela.
     */
    public $requires_payment = true;
    /**
     * @var string Estado real de la reserva ('pending' salvo casos
     * especiales) — el controlador REST lo usa para informar al frontend
     * sin necesidad de re-consultar la reserva. Tarea "cupón 100%" (Dudas
     * de producto, CLAUDE.md): 'confirmed' cuando la reserva queda
     * confirmada de una por no haber nada que cobrar (ver create_pending()).
     */
    public $status = 'pending';

    public function __construct( bool $success, int $booking_id = 0, string $booking_ref = '', float $total_mxn = 0.0, $quote = null, string $error = '', string $message = '' ) {
        $this->success     = $success;
        $this->booking_id  = $booking_id;
        $this->booking_ref = $booking_ref;
        $this->total_mxn   = $total_mxn;
        $this->charge_mxn  = $total_mxn;
        $this->quote       = $quote;
        $this->error       = $error;
        $this->message     = $message;
    }

    public static function error( string $message ) {
        return new self( false, 0, '', 0.0, null, $message );
    }

    public function to_array() {
        return [
            'success'     => $this->success,
            'booking_id'  => $this->booking_id,
            'booking_ref' => $this->booking_ref,
            'total_mxn'   => $this->total_mxn,
            'error'       => $this->error,
            'message'     => $this->message,
        ];
    }
}
