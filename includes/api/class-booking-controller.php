<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints REST de reservas.
 *
 * POST /wp-json/amir/v1/bookings           → Crear reserva pending
 * GET  /wp-json/amir/v1/bookings/{ref}     → Verificar reserva (QR, portal)
 * POST /wp-json/amir/v1/bookings/{ref}/cancel  → Cancelar reserva
 * POST /wp-json/amir/v1/bookings/stripe-webhook → Webhook Stripe (firmado)
 * POST /wp-json/amir/v1/bookings/quote     → Cotizar precio sin crear reserva
 */
class BookingController {

    private const NAMESPACE = 'amir/v1';

    public function register_routes(): void {

        register_rest_route( self::NAMESPACE, '/bookings', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_booking' ],
            'permission_callback' => '__return_true',
            'args'                => $this->create_args(),
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_booking' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)/cancel', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'cancel_booking' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)/request-cancel', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'request_cancellation' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/by-payment/(?P<pi_id>[a-zA-Z0-9_]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_booking_by_payment' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/quote', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'get_quote' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/bookings/stripe-webhook', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'stripe_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // Descarga del voucher PDF por referencia de reserva
        // (requiere el access_token del link, o el email del cliente)
        register_rest_route( self::NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)/pdf', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'download_pdf' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'ref'   => [ 'required' => true,  'type' => 'string' ],
                'token' => [ 'required' => false, 'type' => 'string' ],
                'email' => [ 'required' => false, 'type' => 'string', 'format' => 'email' ],
            ],
        ] );

        // Confirmación desde el cliente después de pago exitoso en Stripe
        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/confirm-payment', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'confirm_payment' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'                => [ 'required' => true, 'type' => 'integer' ],
                'payment_intent_id' => [ 'required' => true, 'type' => 'string'  ],
            ],
        ] );

        // Cambio de estado manual desde el admin
        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/status', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'change_status' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'id'     => [ 'required' => true, 'type' => 'integer' ],
                'status' => [ 'required' => true, 'type' => 'string'  ],
            ],
        ] );
    }

    // ── POST /bookings ────────────────────────────────────────────────────

    public function create_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $manager = new \AmirBooking\Core\BookingManager();

        $result = $manager->create_pending( [
            'tour_id'          => $request->get_param( 'tour_id' ),
            'schedule_id'      => $request->get_param( 'schedule_id' ),
            'date'             => $request->get_param( 'date' ),
            'adults'           => $request->get_param( 'adults' ),
            'children'         => $request->get_param( 'children' ) ?? 0,
            'babies'           => $request->get_param( 'babies' ) ?? 0,
            'customer_name'    => $request->get_param( 'customer_name' ),
            'customer_email'   => $request->get_param( 'customer_email' ),
            'customer_phone'   => $request->get_param( 'customer_phone' ) ?? '',
            'lang'             => $request->get_param( 'lang' ) ?? 'es',
            'partner_token'    => $request->get_param( 'partner_token' ) ?? '',
            'special_requests' => $request->get_param( 'special_requests' ) ?? '',
            'coupon_code'      => $request->get_param( 'coupon_code' ) ?? '',
            'source'           => 'direct',
        ] );

        if ( ! $result->success ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => $result->error ],
                422
            );
        }

        // Iniciar el cobro con la pasarela activa (hoy solo Stripe;
        // Mercado Pago se suma implementando la misma interfaz)
        $gateway  = \AmirBooking\Payments\PaymentGatewayFactory::default_gateway();
        $payment  = $gateway->create_payment( $result );

        if ( ! $payment->success ) {
            // Limpiar la reserva pending si la pasarela falla
            $this->cleanup_failed_booking( $result->booking_id );
            \AmirBooking\Payments\PaymentEventLogger::log(
                $result->booking_id, $gateway->id(), 'creation_failed', $payment->error
            );
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => 'Error al inicializar el pago. Intenta de nuevo.' ],
                500
            );
        }

        // Guardar la referencia de pago en la reserva
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [
                'stripe_payment_intent' => $payment->reference, // compatibilidad hacia atrás
                'payment_gateway'       => $gateway->id(),
                'gateway_reference'     => $payment->reference,
            ],
            [ 'id' => $result->booking_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        \AmirBooking\Payments\PaymentEventLogger::log(
            $result->booking_id, $gateway->id(), 'created', '', [ 'reference' => $payment->reference ]
        );

        return new \WP_REST_Response( array_merge( [
            'success'     => true,
            'booking_ref' => $result->booking_ref,
            'booking_id'  => $result->booking_id,
            'total_mxn'   => $result->total_mxn,
            'gateway'     => $gateway->id(),
        ], $payment->client_payload ), 201 ); // client_secret (Stripe) u otro campo según la pasarela
    }

    // ── GET /bookings/{ref} ───────────────────────────────────────────────

    public function get_booking( \WP_REST_Request $request ): \WP_REST_Response {
        if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'get_booking_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
            return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
        }

        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $manager = new \AmirBooking\Core\BookingManager();
        $booking = $manager->get_booking_by_ref( $ref );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        // El booking_ref es secuencial (AMIR-2026-00001, -00002…) y por lo
        // tanto adivinable — no alcanza como credencial. Se exige el
        // access_token del link de email/QR, o el email del cliente.
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        $email = sanitize_email( $request->get_param( 'email' ) ?? '' );
        if ( ! $manager->authorize_public_access( $booking, $token, $email ) ) {
            return new \WP_REST_Response( [ 'error' => 'Se requiere el token del link o el email de la reserva.' ], 403 );
        }

        return rest_ensure_response( $this->format_booking_public( $booking ) );
    }

    // ── POST /bookings/{ref}/request-cancel ───────────────────────────────
    // El cliente solicita cancelación → queda en estado cancellation_requested
    // El admin aprueba manualmente desde el panel

    public function request_cancellation( \WP_REST_Request $request ): \WP_REST_Response {
        if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'req_cancel_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
            return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
        }

        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $manager = new \AmirBooking\Core\BookingManager();
        $booking = $manager->get_booking_by_ref( $ref );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        $email = sanitize_email( $request->get_param( 'email' ) ?? '' );
        if ( ! $manager->authorize_public_access( $booking, $token, $email ) ) {
            return new \WP_REST_Response( [ 'error' => 'Datos incorrectos' ], 403 );
        }

        if ( ! in_array( $booking->status, ['pending','confirmed'], true ) ) {
            return new \WP_REST_Response( [ 'error' => 'Esta reserva no puede cancelarse' ], 422 );
        }

        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [ 'status' => 'cancellation_requested' ],
            [ 'id' => (int)$booking->id ],
            [ '%s' ], [ '%d' ]
        );

        // Notificar al admin
        $wpdb->insert( "{$wpdb->prefix}amir_notifications", [
            'type'    => 'cancellation_request',
            'title'   => 'Solicitud de cancelación',
            'message' => sprintf('%s solicita cancelar la reserva %s (tour del %s)',
                $booking->customer_name, $booking->booking_ref, $booking->tour_date),
            'data'    => json_encode(['booking_id' => $booking->id]),
            'is_read' => 0,
        ], ['%s','%s','%s','%s','%d'] );

        $admin_email = get_option('amir_admin_email', get_option('admin_email'));
        if ($admin_email) {
            wp_mail(
                $admin_email,
                '[Amir Booking] Solicitud de cancelación — ' . $booking->booking_ref,
                sprintf(
                    "%s (%s) solicita cancelar la reserva %s del %s.\n\nRevisar: %s",
                    $booking->customer_name, $booking->customer_email,
                    $booking->booking_ref, $booking->tour_date,
                    admin_url('admin.php?page=amir-bookings-list&action=view&id='.$booking->id)
                )
            );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => 'Tu solicitud de cancelación fue recibida. Te contactaremos en breve.',
        ] );
    }

    // ── GET /bookings/by-payment/{pi_id} ─────────────────────────────────
    // Usado por el widget post-pago para obtener el booking_ref

    public function get_booking_by_payment( \WP_REST_Request $request ): \WP_REST_Response {
        if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'by_payment_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
            return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
        }

        $pi_id = sanitize_text_field( $request->get_param('pi_id') );

        global $wpdb;
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT booking_ref, status, tour_date FROM {$wpdb->prefix}amir_bookings
             WHERE stripe_payment_intent = %s",
            $pi_id
        ) );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
        }

        return rest_ensure_response( [
            'booking_ref' => $booking->booking_ref,
            'status'      => $booking->status,
            'tour_date'   => $booking->tour_date,
        ] );
    }

    // ── POST /bookings/{ref}/cancel ───────────────────────────────────────

    public function cancel_booking( \WP_REST_Request $request ): \WP_REST_Response {
        if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'cancel_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
            return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
        }

        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $manager = new \AmirBooking\Core\BookingManager();
        $booking = $manager->get_booking_by_ref( $ref );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        // Autorizar por access_token (link de email/QR) o por email del cliente
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        $email = sanitize_email( $request->get_param( 'email' ) ?? '' );
        if ( ! $manager->authorize_public_access( $booking, $token, $email ) ) {
            return new \WP_REST_Response( [ 'error' => 'Datos incorrectos' ], 403 );
        }

        $result = $manager->cancel( (int) $booking->id, 'client' );

        if ( ! $result->success ) {
            return new \WP_REST_Response( [ 'error' => $result->error ], 422 );
        }

        return rest_ensure_response( [
            'success'     => true,
            'message'     => $result->message,
            'refund_mxn'  => $result->total_mxn,
        ] );
    }

    // ── POST /bookings/quote ──────────────────────────────────────────────

    public function get_quote( \WP_REST_Request $request ): \WP_REST_Response {
        $pricing = new \AmirBooking\Core\PricingEngine();

        $quote = $pricing->quote(
            (int) $request->get_param( 'tour_id' ),
            (int) $request->get_param( 'schedule_id' ),
            $request->get_param( 'date' ),
            (int) $request->get_param( 'adults' ),
            (int) ( $request->get_param( 'children' ) ?? 0 ),
            (int) ( $request->get_param( 'babies' ) ?? 0 ),
            sanitize_text_field( $request->get_param( 'coupon_code' ) ?? '' )
        );

        if ( ! $quote->is_valid() ) {
            return new \WP_REST_Response( [ 'error' => $quote->error ], 422 );
        }

        return rest_ensure_response( $quote->to_array() );
    }

    // ── POST /bookings/stripe-webhook ─────────────────────────────────────

    // ── GET /bookings/{ref}/pdf ───────────────────────────────────────────

    public function download_pdf( \WP_REST_Request $request ): void {
        global $wpdb;

        if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'pdf_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
            status_header( 429 );
            echo 'Demasiados intentos. Intenta de nuevo en unos minutos.';
            exit;
        }

        $ref = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE booking_ref = %s",
            $ref
        ) );

        if ( ! $booking ) {
            status_header( 404 );
            echo 'Reserva no encontrada.';
            exit;
        }

        // Autorizar por access_token (link de email/QR) o por email del cliente
        $token   = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        $email   = sanitize_email( $request->get_param( 'email' ) ?? '' );
        $manager = new \AmirBooking\Core\BookingManager();
        if ( ! $manager->authorize_public_access( $booking, $token, $email ) ) {
            status_header( 403 );
            echo 'Acceso no autorizado.';
            exit;
        }

        // Generar o recuperar el PDF/HTML del voucher
        $gen      = new \AmirBooking\Core\VoucherGenerator();
        $filepath = $gen->generate( (int) $booking->id );

        // Limpiar cualquier output previo de WordPress
        if ( ob_get_level() ) {
            ob_end_clean();
        }

        if ( $filepath && file_exists( $filepath ) ) {
            $ext  = pathinfo( $filepath, PATHINFO_EXTENSION );
            $mime = $ext === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8';
            $disp = $ext === 'pdf' ? 'attachment' : 'inline';

            header( 'Content-Type: ' . $mime );
            header( 'Content-Disposition: ' . $disp . '; filename="voucher-' . sanitize_file_name( $ref ) . '.' . $ext . '"' );
            header( 'Content-Length: ' . filesize( $filepath ) );
            header( 'Cache-Control: no-store' );
            readfile( $filepath );
        } else {
            // Último recurso: stream HTML inline directamente
            $html = $gen->get_voucher_html( (int) $booking->id );
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Content-Disposition: inline; filename="voucher-' . sanitize_file_name( $ref ) . '.html"' );
            echo $html;
        }

        exit;
    }

    // ── POST /bookings/{id}/confirm-payment ───────────────────────────────
    // Llamado por el cliente después de pago exitoso en Stripe.
    // Verifica el PaymentIntent directamente con Stripe y confirma la reserva.

    public function confirm_payment( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $booking_id = (int) $request->get_param( 'id' );
        $pi_id      = sanitize_text_field( $request->get_param( 'payment_intent_id' ) );

        if ( ! $pi_id ) {
            return new \WP_REST_Response( [ 'error' => 'payment_intent_id requerido' ], 400 );
        }

        // Buscar la reserva
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        // Si ya está confirmada, devolver el ref sin hacer nada
        if ( $booking->status === 'confirmed' ) {
            return new \WP_REST_Response( [
                'confirmed'   => true,
                'booking_ref' => $booking->booking_ref,
            ], 200 );
        }

        // El payment_intent_id debe ser el que se generó para ESTA reserva.
        // Sin este chequeo, un payment_intent legítimo y exitoso de OTRA
        // reserva (barata) podría reutilizarse para confirmar cualquier
        // reserva ajena sin pagarla — fetch_payment_status() solo confirma
        // que el pago existe y tuvo éxito, no de quién es. Fail-closed: si
        // la reserva todavía no tiene una referencia de pago guardada, no
        // hay forma de verificar que corresponde a esta reserva — no se
        // confirma (el webhook es la ruta autoritativa en ese caso).
        $known_reference = $booking->gateway_reference ?: $booking->stripe_payment_intent;
        if ( empty( $known_reference ) || ! hash_equals( $known_reference, $pi_id ) ) {
            return new \WP_REST_Response( [ 'error' => 'payment_intent_id no corresponde a esta reserva' ], 403 );
        }

        // Verificar el pago directamente contra la pasarela — nunca
        // confiar en que el cliente diga "ya pagué".
        $gateway = \AmirBooking\Payments\PaymentGatewayFactory::for_booking( $booking );

        if ( $gateway && $gateway->is_configured() ) {
            $status = $gateway->fetch_payment_status( $pi_id );

            if ( $status === null ) {
                // No se puede verificar — no confirmar; el webhook es la ruta autoritativa
                return new \WP_REST_Response( [
                    'error' => 'No se pudo verificar el pago. La confirmación llegará por email en breve.',
                ], 503 );
            }

            if ( $status->status !== \AmirBooking\Payments\PaymentStatusResult::SUCCEEDED ) {
                \AmirBooking\Payments\PaymentEventLogger::log( $booking_id, $gateway->id(), 'confirm_check_not_succeeded', $status->status );
                return new \WP_REST_Response( [ 'error' => 'Pago no completado' ], 402 );
            }

            $charge_id = $status->charge_reference;
        } else {
            // Sin credenciales configuradas: nunca confirmar el pago sin
            // verificarlo contra la pasarela. Un olvido de configuración en
            // producción no debe convertirse en "cualquiera confirma
            // cualquier reserva llamando este endpoint con datos inventados".
            // Solo se permite omitir la verificación con un override
            // explícito para desarrollo local, nunca por ausencia de config.
            $dev_override = defined( 'WP_DEBUG' ) && WP_DEBUG
                         && defined( 'AMIR_ALLOW_UNVERIFIED_PAYMENTS' ) && AMIR_ALLOW_UNVERIFIED_PAYMENTS;

            if ( ! $dev_override ) {
                return new \WP_REST_Response( [
                    'error' => 'La pasarela de pago no está configurada. No se puede confirmar el pago.',
                ], 503 );
            }

            $charge_id = $pi_id;
        }

        // Confirmar la reserva
        $manager = new \AmirBooking\Core\BookingManager();
        $manager->confirm( $booking_id, $charge_id );
        \AmirBooking\Payments\PaymentEventLogger::log(
            $booking_id, $gateway ? $gateway->id() : ( $booking->payment_gateway ?: 'stripe' ), 'succeeded', '', [ 'charge_reference' => $charge_id ]
        );

        // Actualizar la referencia de pago si no estaba guardada
        if ( empty( $booking->stripe_payment_intent ) ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'stripe_payment_intent' => $pi_id, 'gateway_reference' => $pi_id, 'gateway_charge_id' => $charge_id ],
                [ 'id' => $booking_id ]
            );
        }

        $updated = $wpdb->get_row( $wpdb->prepare(
            "SELECT booking_ref FROM {$wpdb->prefix}amir_bookings WHERE id = %d",
            $booking_id
        ) );

        return new \WP_REST_Response( [
            'confirmed'   => true,
            'booking_ref' => $updated->booking_ref ?? $booking->booking_ref,
        ], 200 );
    }

    // ── POST /bookings/{id}/status ────────────────────────────────────────
    // Cambio de estado manual desde el admin.

    public function change_status( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $booking_id = (int) $request->get_param( 'id' );
        $new_status = sanitize_key( $request->get_param( 'status' ) );

        $allowed = [ 'confirmed', 'pending', 'cancelled_client', 'cancelled_weather',
                     'cancelled_min_pax', 'rescheduled', 'completed' ];

        if ( ! in_array( $new_status, $allowed, true ) ) {
            return new \WP_REST_Response( [ 'error' => 'Estado no válido' ], 400 );
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        // Si se confirma manualmente, usar el flujo completo (PDF + email)
        if ( $new_status === 'confirmed' && $booking->status === 'pending' ) {
            $manager = new \AmirBooking\Core\BookingManager();
            $manager->confirm( $booking_id, $booking->stripe_charge_id ?? '' );
        } else {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $booking_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }

        return new \WP_REST_Response( [ 'updated' => true, 'status' => $new_status ], 200 );
    }

    public function stripe_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        return $this->handle_gateway_webhook( new \AmirBooking\Payments\StripeGateway(), $request );
    }

    /**
     * Maneja el webhook de cualquier pasarela que implemente
     * PaymentGatewayInterface. Cada pasarela tiene su propia ruta REST
     * (ver register_routes()) pero comparten esta lógica: verificar firma,
     * traducir a PaymentEvent, y actuar según el tipo — el controlador no
     * conoce el formato específico de Stripe ni de Mercado Pago.
     */
    private function handle_gateway_webhook( \AmirBooking\Payments\PaymentGatewayInterface $gateway, \WP_REST_Request $request ): \WP_REST_Response {
        $payload = $request->get_body();

        // Se lee directo de $_SERVER (no de $request->get_headers()) para no
        // depender de cómo WP_REST_Request normaliza los nombres de header.
        $headers = [
            'stripe-signature' => $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '',
            'x-signature'      => $_SERVER['HTTP_X_SIGNATURE']      ?? '', // Mercado Pago
            'x-request-id'     => $_SERVER['HTTP_X_REQUEST_ID']     ?? '', // Mercado Pago
        ];

        if ( ! $gateway->verify_webhook_signature( $payload, $headers ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 400 );
        }

        $event = $gateway->parse_webhook_event( $payload );
        if ( ! $event ) {
            return new \WP_REST_Response( [ 'received' => true ], 200 ); // tipo de evento que no nos interesa
        }

        global $wpdb;
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE gateway_reference = %s OR stripe_payment_intent = %s",
            $event->gateway_reference, $event->gateway_reference
        ) );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'received' => true ], 200 );
        }

        \AmirBooking\Payments\PaymentEventLogger::log(
            (int) $booking->id, $gateway->id(), 'webhook_' . $event->type, $event->reason, $event->raw
        );

        switch ( $event->type ) {
            case \AmirBooking\Payments\PaymentEvent::SUCCEEDED:
                if ( $booking->status === 'pending' ) {
                    ( new \AmirBooking\Core\BookingManager() )->confirm( (int) $booking->id, $event->charge_reference );
                }
                break;

            case \AmirBooking\Payments\PaymentEvent::FAILED:
                if ( $booking->status === 'pending' ) {
                    $wpdb->update(
                        "{$wpdb->prefix}amir_bookings",
                        [ 'status' => 'cancelled_client', 'internal_notes' => 'Pago fallido: ' . $event->reason ],
                        [ 'id' => $booking->id ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                }
                break;

            case \AmirBooking\Payments\PaymentEvent::REFUNDED:
                do_action( 'amir_stripe_refund_completed', $event->raw );
                break;
        }

        return new \WP_REST_Response( [ 'received' => true ], 200 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function format_booking_public( object $booking ): array {
        return [
            'booking_ref'   => $booking->booking_ref,
            'status'        => $booking->status,
            'tour_date'     => $booking->tour_date,
            'customer_name' => $booking->customer_name,
            'adults'        => (int) $booking->adults,
            'children'      => (int) $booking->children,
            'babies'        => (int) $booking->babies,
            'total_mxn'     => (float) $booking->total_mxn,
            'qr_code_path'  => $booking->qr_code_path,
        ];
    }

    private function cleanup_failed_booking( int $booking_id ): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}amir_bookings", [ 'id' => $booking_id ], [ '%d' ] );
    }

    private function create_args(): array {
        return [
            'tour_id'          => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
            'schedule_id'      => [ 'required' => false, 'type' => 'integer', 'minimum' => 0 ],
            'date'             => [ 'required' => true,  'type' => 'string',  'format' => 'date' ],
            'adults'           => [ 'required' => true,  'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
            'children'         => [ 'required' => false, 'type' => 'integer', 'minimum' => 0, 'maximum' => 20 ],
            'babies'           => [ 'required' => false, 'type' => 'integer', 'minimum' => 0, 'maximum' => 10 ],
            'customer_name'    => [ 'required' => true,  'type' => 'string',  'minLength' => 2 ],
            'customer_email'   => [ 'required' => true,  'type' => 'string',  'format' => 'email' ],
            'customer_phone'   => [ 'required' => false, 'type' => 'string' ],
            'lang'             => [ 'required' => false, 'type' => 'string',  'enum' => [ 'es', 'en' ] ],
            'partner_token'    => [ 'required' => false, 'type' => 'string' ],
            'special_requests' => [ 'required' => false, 'type' => 'string' ],
            'coupon_code'      => [ 'required' => false, 'type' => 'string' ],
        ];
    }
}
