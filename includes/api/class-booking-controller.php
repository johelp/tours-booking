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

        // Descarga del voucher PDF por referencia de reserva (requiere email del cliente)
        register_rest_route( self::NAMESPACE, '/bookings/(?P<ref>[A-Z0-9\-]+)/pdf', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'download_pdf' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'ref'   => [ 'required' => true,  'type' => 'string' ],
                'email' => [ 'required' => true,  'type' => 'string', 'format' => 'email' ],
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
            'source'           => 'direct',
        ] );

        if ( ! $result->success ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => $result->error ],
                422
            );
        }

        // Crear Stripe PaymentIntent
        $pi = $this->create_stripe_payment_intent( $result );
        if ( is_wp_error( $pi ) ) {
            // Limpiar la reserva pending si Stripe falla
            $this->cleanup_failed_booking( $result->booking_id );
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => 'Error al inicializar el pago. Intenta de nuevo.' ],
                500
            );
        }

        // Guardar el payment_intent en la reserva
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [ 'stripe_payment_intent' => $pi['id'] ],
            [ 'id' => $result->booking_id ],
            [ '%s' ],
            [ '%d' ]
        );

        return new \WP_REST_Response( [
            'success'        => true,
            'booking_ref'    => $result->booking_ref,
            'booking_id'     => $result->booking_id,
            'total_mxn'      => $result->total_mxn,
            'client_secret'  => $pi['client_secret'],  // Para Stripe.js en el frontend
        ], 201 );
    }

    // ── GET /bookings/{ref} ───────────────────────────────────────────────

    public function get_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $manager = new \AmirBooking\Core\BookingManager();
        $booking = $manager->get_booking_by_ref( $ref );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        return rest_ensure_response( $this->format_booking_public( $booking ) );
    }

    // ── POST /bookings/{ref}/request-cancel ───────────────────────────────
    // El cliente solicita cancelación → queda en estado cancellation_requested
    // El admin aprueba manualmente desde el panel

    public function request_cancellation( \WP_REST_Request $request ): \WP_REST_Response {
        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $manager = new \AmirBooking\Core\BookingManager();
        $booking = $manager->get_booking_by_ref( $ref );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        $email = sanitize_email( $request->get_param( 'email' ) ?? '' );
        if ( strtolower($email) !== strtolower($booking->customer_email) ) {
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
        $ref     = strtoupper( sanitize_text_field( $request->get_param( 'ref' ) ) );
        $manager = new \AmirBooking\Core\BookingManager();
        $booking = $manager->get_booking_by_ref( $ref );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'Reserva no encontrada' ], 404 );
        }

        // Verificar que el email coincide (seguridad básica para cancelación pública)
        $email = sanitize_email( $request->get_param( 'email' ) ?? '' );
        if ( strtolower( $email ) !== strtolower( $booking->customer_email ) ) {
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
            (int) ( $request->get_param( 'babies' ) ?? 0 )
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

        // Verificar email para proteger datos personales del cliente (PII)
        $email = sanitize_email( $request->get_param( 'email' ) ?? '' );
        if ( empty( $email ) || strtolower( $email ) !== strtolower( $booking->customer_email ) ) {
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

        // Verificar el PaymentIntent directamente con Stripe
        $mode    = get_option( 'amir_stripe_mode', 'test' );
        $sk      = get_option( "amir_stripe_sk_{$mode}", '' );

        if ( $sk ) {
            $response = wp_remote_get( "https://api.stripe.com/v1/payment_intents/{$pi_id}", [
                'headers' => [ 'Authorization' => 'Bearer ' . $sk ],
                'timeout' => 10,
            ] );

            if ( ! is_wp_error( $response ) ) {
                $pi_data = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( ( $pi_data['status'] ?? '' ) !== 'succeeded' ) {
                    return new \WP_REST_Response( [ 'error' => 'Pago no completado' ], 402 );
                }

                $charge_id = $pi_data['latest_charge'] ?? '';
            } else {
                // No se puede verificar — no confirmar; el webhook es la ruta autoritativa
                return new \WP_REST_Response( [
                    'error' => 'No se pudo verificar el pago. La confirmación llegará por email en breve.',
                ], 503 );
            }
        } else {
            // Sin SK configurada — modo desarrollo, aceptar
            $charge_id = $pi_id;
        }

        // Confirmar la reserva
        $manager = new \AmirBooking\Core\BookingManager();
        $manager->confirm( $booking_id, $charge_id );

        // Actualizar el payment_intent_id si no estaba guardado
        if ( empty( $booking->stripe_payment_intent ) ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'stripe_payment_intent' => $pi_id ],
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
        $payload    = $request->get_body();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret     = get_option( 'amir_stripe_webhook_secret', '' );

        // Verificar firma del webhook
        if ( ! $this->verify_stripe_signature( $payload, $sig_header, $secret ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 400 );
        }

        $event = json_decode( $payload, true );

        switch ( $event['type'] ?? '' ) {

            case 'payment_intent.succeeded':
                $this->handle_payment_succeeded( $event['data']['object'] );
                break;

            case 'payment_intent.payment_failed':
                $this->handle_payment_failed( $event['data']['object'] );
                break;

            case 'charge.refunded':
                // El reembolso ya fue procesado, solo log
                do_action( 'amir_stripe_refund_completed', $event['data']['object'] );
                break;
        }

        return new \WP_REST_Response( [ 'received' => true ], 200 );
    }

    // ── Handlers Stripe ───────────────────────────────────────────────────

    private function handle_payment_succeeded( array $pi ): void {
        global $wpdb;

        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_bookings
                 WHERE stripe_payment_intent = %s AND status = 'pending'",
                $pi['id']
            )
        );

        if ( ! $booking ) {
            return;
        }

        $charge_id = $pi['latest_charge'] ?? '';
        $manager   = new \AmirBooking\Core\BookingManager();
        $manager->confirm( (int) $booking->id, $charge_id );
    }

    private function handle_payment_failed( array $pi ): void {
        global $wpdb;

        $wpdb->update(
            "{$wpdb->prefix}amir_bookings",
            [ 'status' => 'cancelled_client', 'internal_notes' => 'Pago fallido en Stripe' ],
            [ 'stripe_payment_intent' => $pi['id'], 'status' => 'pending' ],
            [ '%s', '%s' ],
            [ '%s', '%s' ]
        );
    }

    // ── Stripe PaymentIntent ──────────────────────────────────────────────

    private function create_stripe_payment_intent( \AmirBooking\Core\BookingResult $booking_result ) {
        $mode   = get_option( 'amir_stripe_mode', 'test' );
        $sk_key = get_option( "amir_stripe_sk_{$mode}", '' );

        if ( empty( $sk_key ) ) {
            return new \WP_Error( 'no_stripe_key', 'Stripe no configurado' );
        }

        $amount_cents = (int) round( $booking_result->total_mxn * 100 );

        $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
            'headers' => [
                'Authorization' => 'Bearer ' . $sk_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'amount'   => $amount_cents,
                'currency' => 'mxn',
                'metadata' => [
                    'booking_ref' => $booking_result->booking_ref,
                    'booking_id'  => $booking_result->booking_id,
                ],
                'automatic_payment_methods' => [ 'enabled' => 'true' ],
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new \WP_Error( 'stripe_error', $body['error']['message'] );
        }

        return $body;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function verify_stripe_signature( string $payload, string $sig_header, string $secret ): bool {
        if ( empty( $secret ) || empty( $sig_header ) ) {
            return false;
        }

        // Parsear t= y v1= del header
        $parts = [];
        foreach ( explode( ',', $sig_header ) as $pair ) {
            [ $k, $v ] = explode( '=', $pair, 2 );
            $parts[ $k ] = $v;
        }

        $timestamp = (int) ( $parts['t'] ?? 0 );
        $signature = $parts['v1'] ?? '';

        // Rechazar si el timestamp supera 300 s (previene replay attacks)
        if ( $timestamp === 0 || abs( time() - $timestamp ) > 300 ) {
            return false;
        }

        $signed_payload = "{$timestamp}.{$payload}";
        $expected       = hash_hmac( 'sha256', $signed_payload, $secret );

        return hash_equals( $expected, $signature );
    }

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
        ];
    }
}
