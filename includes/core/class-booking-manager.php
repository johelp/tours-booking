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
                sprintf( 'Solo quedan %d cupos disponibles', $avail->slots_remaining )
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

        $max_capacity = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT max_capacity FROM {$wpdb->prefix}amir_tours WHERE id = %d",
            (int) $data['tour_id']
        ) );

        if ( $pax > ( $max_capacity - $booked_pax ) ) {
            $wpdb->query( 'ROLLBACK' );
            return BookingResult::error(
                sprintf( 'Solo quedan %d cupos disponibles', max( 0, $max_capacity - $booked_pax ) )
            );
        }

        // Insertar en DB
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}amir_bookings",
            [
                'booking_ref'     => $booking_ref,
                'access_token'    => $access_token,
                'tour_id'         => (int) $data['tour_id'],
                'schedule_id'     => (int) $data['schedule_id'],
                'partner_id'      => $partner_id,
                'tour_date'       => $data['date'],
                'status'          => 'pending',
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
                'special_requests'=> sanitize_textarea_field( $data['special_requests'] ?? '' ),
                'created_at'      => current_time( 'mysql' ),
            ],
            [ '%s','%s','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%f','%f','%f','%s','%f','%s','%s' ]
        );

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

        return new BookingResult( true, $booking_id, $booking_ref, $quote->total_mxn, $quote );
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

    // ── Crear reserva manual (confirmada directamente) ────────────────────

    /**
     * Crea una reserva manual directamente como 'confirmed' (sin pago online).
     * Usada desde el panel de administración para reservas por teléfono, WhatsApp, etc.
     */
    public function create_manual( array $data ): BookingResult {
        global $wpdb;

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
                sprintf( 'Solo quedan %d cupos disponibles para esa fecha.', $avail->slots_remaining )
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
                'booking_source'    => 'manual',
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
        if ( ! $booking || $booking->status !== 'pending' ) {
            return false;
        }

        // Actualizar estado
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

        return true;
    }

    // ── Cancelar reserva ──────────────────────────────────────────────────

    public function cancel(
        int    $booking_id,
        string $reason_type = 'client',  // client | weather | min_pax
        string $internal_note = ''
    ): BookingResult {
        global $wpdb;

        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            return BookingResult::error( 'Reserva no encontrada' );
        }
        if ( ! in_array( $booking->status, [ 'pending', 'confirmed' ], true ) ) {
            return BookingResult::error( 'Esta reserva ya fue cancelada o completada' );
        }

        // Calcular política de reembolso
        $refund = $this->calculate_refund( $booking, $reason_type );

        $status_map = [
            'weather' => 'cancelled_weather',
            'min_pax' => 'cancelled_min_pax',
        ];
        $new_status = isset( $status_map[ $reason_type ] ) ? $status_map[ $reason_type ] : 'cancelled_client';

        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [
                'status'                  => $new_status,
                'cancellation_policy_pct' => $refund['charge_pct'],
                'refund_amount_mxn'       => $refund['refund_mxn'],
                'internal_notes'          => $booking->internal_notes . "\n[" . current_time( 'mysql' ) . "] " . $internal_note,
            ],
            [ 'id' => $booking_id ],
            [ '%s', '%d', '%f', '%s' ],
            [ '%d' ]
        );

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
        // Cancelación por el operador = siempre reembolso total
        if ( in_array( $reason_type, [ 'weather', 'min_pax' ], true ) ) {
            return [
                'charge_pct' => 0,
                'refund_mxn' => (float) $booking->total_mxn,
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
                'refund_mxn' => (float) $booking->total_mxn,
                'message'    => 'Reembolso total (cancelación con 7+ días de anticipación).',
            ];
        }

        if ( $days_until >= 3 ) {
            $refund = round( (float) $booking->total_mxn * 0.50, 2 );
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
                sprintf( 'Solo quedan %d cupos disponibles para esa fecha', $avail->slots_remaining )
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

    private function generate_ref(): string {
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
    /** @var PriceQuote|null */
    public $quote;
    /** @var string */
    public $error;
    /** @var string */
    public $message;

    public function __construct( bool $success, int $booking_id = 0, string $booking_ref = '', float $total_mxn = 0.0, $quote = null, string $error = '', string $message = '' ) {
        $this->success     = $success;
        $this->booking_id  = $booking_id;
        $this->booking_ref = $booking_ref;
        $this->total_mxn   = $total_mxn;
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
