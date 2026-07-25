<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Mercado Pago — Checkout Pro (redirección a un checkout hospedado por MP).
 *
 * Detalle importante que no es obvio leyendo la interfaz: el "reference"
 * que create_payment() devuelve (y que BookingController persiste tal cual
 * en amir_bookings.gateway_reference, sin saber qué pasarela es) acá NO es
 * el id de la preferencia de MP — es el booking_ref. El webhook de MP solo
 * trae el id del PAGO (distinto del id de la preferencia, y generado recién
 * cuando el cliente paga), así que la única forma de volver a encontrar la
 * reserva desde el webhook es a través del external_reference que se manda
 * al crear la preferencia. Usando el booking_ref como external_reference Y
 * como "reference" genérico, el lookup que ya existe en
 * BookingController::handle_gateway_webhook() (`WHERE gateway_reference = %s`)
 * funciona sin tocar una línea de código gateway-agnóstico.
 */
class MercadoPagoGateway implements PaymentGatewayInterface {

    private const API = 'https://api.mercadopago.com';

    public function id(): string {
        return 'mercadopago';
    }

    public function is_configured(): bool {
        return (bool) $this->access_token();
    }

    // ── Crear cobro (preferencia de Checkout Pro) ──────────────────────────

    public function create_payment( \AmirBooking\Core\BookingResult $booking ): PaymentCreationResult {
        $token = $this->access_token();
        if ( empty( $token ) ) {
            return PaymentCreationResult::error( 'Mercado Pago no configurado' );
        }

        $currency = strtoupper( get_option( 'amir_currency', 'MXN' ) );
        $company  = get_option( 'amir_company_name', 'TourFlow' );

        $body = [
            'items' => [ [
                'title'       => $company . ' — ' . $booking->booking_ref,
                'quantity'    => 1,
                'unit_price'  => round( (float) $booking->total_mxn, 2 ),
                'currency_id' => $currency,
            ] ],
            'external_reference' => $booking->booking_ref,
            'back_urls'          => [
                'success' => home_url( '/' ),
                'pending' => home_url( '/' ),
                'failure' => home_url( '/' ),
            ],
            'auto_return'       => 'approved',
            'notification_url'  => rest_url( 'amir/v1/bookings/mercadopago-webhook' ),
            'metadata'          => [
                'booking_id'  => $booking->booking_id,
                'booking_ref' => $booking->booking_ref,
            ],
        ];

        $response = wp_remote_post( self::API . '/checkout/preferences', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return PaymentCreationResult::error( $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['id'] ) ) {
            return PaymentCreationResult::error( $data['message'] ?? 'Error de Mercado Pago' );
        }

        return PaymentCreationResult::success( $booking->booking_ref, [
            'preference_id'      => $data['id'],
            'init_point'         => $data['init_point'] ?? '',
            'sandbox_init_point' => $data['sandbox_init_point'] ?? '',
        ] );
    }

    // ── Webhook ───────────────────────────────────────────────────────────
    // MP manda dos formatos históricamente (IPN viejo y "webhooks" nuevo);
    // acá se soporta el nuevo: { action, data: { id } } con header x-signature.
    // Doc: https://www.mercadopago.com/developers/en/docs/checkout-api/additional-content/notifications/webhooks

    public function verify_webhook_signature( string $payload, array $headers ): bool {
        $secret     = get_option( 'amir_mp_webhook_secret', '' );
        $sig_header = $headers['x-signature'] ?? '';
        $request_id = $headers['x-request-id'] ?? '';

        if ( empty( $secret ) || empty( $sig_header ) ) {
            return false;
        }

        $parts = [];
        foreach ( explode( ',', $sig_header ) as $pair ) {
            $kv = explode( '=', $pair, 2 );
            if ( count( $kv ) === 2 ) {
                $parts[ trim( $kv[0] ) ] = trim( $kv[1] );
            }
        }
        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ( $ts === '' || $v1 === '' ) {
            return false;
        }

        $data    = json_decode( $payload, true );
        $data_id = $data['data']['id'] ?? '';
        if ( $data_id === '' ) {
            return false;
        }

        $manifest = "id:{$data_id};request-id:{$request_id};ts:{$ts};";
        $expected = hash_hmac( 'sha256', $manifest, $secret );

        return hash_equals( $expected, $v1 );
    }

    public function parse_webhook_event( string $payload ): ?PaymentEvent {
        $data       = json_decode( $payload, true );
        $type       = (string) ( $data['type'] ?? ( $data['action'] ?? '' ) );
        $payment_id = (string) ( $data['data']['id'] ?? '' );

        // Solo interesan notificaciones de pago (MP también manda
        // merchant_order y otros tipos que no necesitamos procesar).
        if ( $payment_id === '' || strpos( $type, 'payment' ) === false ) {
            return null;
        }

        $payment = $this->fetch_payment( $payment_id );
        if ( ! $payment ) {
            return null;
        }

        $status  = $payment['status'] ?? '';
        $ext_ref = (string) ( $payment['external_reference'] ?? '' );
        if ( $ext_ref === '' ) {
            return null;
        }

        if ( $status === 'approved' ) {
            return new PaymentEvent( PaymentEvent::SUCCEEDED, $ext_ref, $payment_id, '', $payment );
        }

        if ( in_array( $status, [ 'rejected', 'cancelled' ], true ) ) {
            return new PaymentEvent( PaymentEvent::FAILED, $ext_ref, '', $payment['status_detail'] ?? $status, $payment );
        }

        // pending / in_process / authorized: todavía no hay nada que hacer,
        // se espera el próximo webhook.
        return null;
    }

    // ── Verificación directa (fallback del polling de StepPaymentMP) ──────
    // $reference acá es el external_reference (booking_ref), no un id de MP
    // — Checkout Pro no expone un id de pago hasta que el cliente ya pagó,
    // así que hay que buscarlo por la referencia que sí conocemos de antes.

    public function fetch_payment_status( string $reference ): ?PaymentStatusResult {
        $token = $this->access_token();
        if ( empty( $token ) ) {
            return null;
        }

        $response = wp_remote_get(
            self::API . '/v1/payments/search?external_reference=' . rawurlencode( $reference ) . '&sort=date_created&criteria=desc',
            [
                'headers' => [ 'Authorization' => 'Bearer ' . $token ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $results = $data['results'] ?? [];
        if ( empty( $results ) ) {
            return null;
        }

        $payment = $results[0]; // el más reciente
        $status  = $payment['status'] ?? '';

        if ( $status === 'approved' ) {
            return new PaymentStatusResult( PaymentStatusResult::SUCCEEDED, (string) ( $payment['id'] ?? '' ) );
        }
        if ( in_array( $status, [ 'rejected', 'cancelled' ], true ) ) {
            return new PaymentStatusResult( PaymentStatusResult::FAILED );
        }
        return new PaymentStatusResult( PaymentStatusResult::PENDING );
    }

    // ── Reembolsos ────────────────────────────────────────────────────────

    public function refund( string $charge_reference, float $amount_mxn ): bool {
        $token = $this->access_token();
        if ( empty( $token ) || empty( $charge_reference ) ) {
            return false;
        }

        $response = wp_remote_post( self::API . "/v1/payments/{$charge_reference}/refunds", [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'amount' => round( $amount_mxn, 2 ) ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function fetch_payment( string $payment_id ): ?array {
        $token = $this->access_token();
        if ( empty( $token ) ) {
            return null;
        }

        $response = wp_remote_get( self::API . "/v1/payments/{$payment_id}", [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) && isset( $data['id'] ) ? $data : null;
    }

    private function access_token(): string {
        $mode = get_option( 'amir_mp_mode', 'test' );
        return (string) get_option( "amir_mp_access_token_{$mode}", '' );
    }
}
