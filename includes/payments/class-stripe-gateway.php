<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Implementación de PaymentGatewayInterface para Stripe.
 * Extraído de class-booking-controller.php sin cambiar el comportamiento
 * existente — mismo endpoint, misma firma de webhook, mismo fail-closed
 * cuando falta la Secret Key.
 */
class StripeGateway implements PaymentGatewayInterface {

    private const STRIPE_VERSION = '2024-06-20';

    public function id(): string {
        return 'stripe';
    }

    public function is_configured(): bool {
        return (bool) $this->secret_key();
    }

    // ── Crear cobro ───────────────────────────────────────────────────────

    public function create_payment( \AmirBooking\Core\BookingResult $booking ): PaymentCreationResult {
        $sk = $this->secret_key();
        if ( empty( $sk ) ) {
            return PaymentCreationResult::error( 'Stripe no configurado' );
        }

        $currency     = strtolower( get_option( 'amir_currency', 'MXN' ) );
        // charge_mxn (default = total_mxn cuando no hay depósito activo, ver
        // BookingResult) es el monto que realmente se cobra AHORA — total_mxn
        // sigue siendo el precio total del tour, sin tocar.
        $amount_cents = (int) round( $booking->charge_mxn * 100 );
        $company      = get_option( 'amir_company_name', 'TourFlow' );

        // Stripe rechaza cobros por debajo de un piso mínimo (~$0.50 USD
        // equivalente, varía por moneda). Validar antes de llamar a la API
        // evita crear una reserva 'pending' condenada a fallar, y da un
        // mensaje que apunta a la causa real: los precios del tour están en
        // una escala que no corresponde a la moneda configurada (típico al
        // cambiar de moneda — ej. a ARS — sin reajustar los precios).
        $usd_equivalent = ( new \AmirBooking\Core\PricingEngine() )->convert_to_usd( $booking->charge_mxn );
        if ( $usd_equivalent > 0 && $usd_equivalent < 0.50 ) {
            return PaymentCreationResult::error( sprintf(
                'El monto (%s %s) es demasiado bajo para procesarse — revisá que los precios de este tour estén en la escala correcta para %s.',
                number_format( $booking->charge_mxn, 2 ),
                strtoupper( $currency ),
                strtoupper( $currency )
            ) );
        }

        $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
            'headers' => [
                'Authorization'  => 'Bearer ' . $sk,
                'Content-Type'   => 'application/x-www-form-urlencoded',
                'Stripe-Version' => self::STRIPE_VERSION,
            ],
            'body' => [
                'amount'      => $amount_cents,
                'currency'    => $currency,
                'description' => $company . ' — ' . $booking->booking_ref,
                'metadata'    => [
                    'booking_ref' => $booking->booking_ref,
                    'booking_id'  => $booking->booking_id,
                ],
                'automatic_payment_methods' => [ 'enabled' => 'true' ],
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return PaymentCreationResult::error( $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return PaymentCreationResult::error( $body['error']['message'] ?? 'Error de Stripe' );
        }

        return PaymentCreationResult::success( $body['id'], [
            'client_secret' => $body['client_secret'],
        ] );
    }

    // ── Webhook ───────────────────────────────────────────────────────────

    public function verify_webhook_signature( string $payload, array $headers ): bool {
        $secret     = get_option( 'amir_stripe_webhook_secret', '' );
        $sig_header = $headers['stripe-signature'] ?? '';

        if ( empty( $secret ) || empty( $sig_header ) ) {
            return false;
        }

        $parts = [];
        foreach ( explode( ',', $sig_header ) as $pair ) {
            $kv = explode( '=', $pair, 2 );
            if ( count( $kv ) === 2 ) {
                $parts[ $kv[0] ] = $kv[1];
            }
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

    public function parse_webhook_event( string $payload ): ?PaymentEvent {
        $event = json_decode( $payload, true );
        $type  = $event['type'] ?? '';
        $obj   = $event['data']['object'] ?? [];

        switch ( $type ) {
            case 'payment_intent.succeeded':
                return new PaymentEvent(
                    PaymentEvent::SUCCEEDED,
                    $obj['id'] ?? '',
                    $obj['latest_charge'] ?? '',
                    '',
                    $obj
                );

            case 'payment_intent.payment_failed':
                $reason = $obj['last_payment_error']['decline_code']
                    ?? $obj['last_payment_error']['code']
                    ?? $obj['last_payment_error']['message']
                    ?? 'unknown';
                return new PaymentEvent(
                    PaymentEvent::FAILED,
                    $obj['id'] ?? '',
                    '',
                    (string) $reason,
                    $obj
                );

            case 'charge.refunded':
                return new PaymentEvent(
                    PaymentEvent::REFUNDED,
                    $obj['payment_intent'] ?? '',
                    $obj['id'] ?? '',
                    '',
                    $obj
                );

            default:
                return null;
        }
    }

    // ── Verificación directa (confirm-payment desde el cliente) ───────────

    public function fetch_payment_status( string $reference ): ?PaymentStatusResult {
        $sk = $this->secret_key();
        if ( empty( $sk ) ) {
            return null;
        }

        $response = wp_remote_get( "https://api.stripe.com/v1/payment_intents/{$reference}", [
            'headers' => [
                'Authorization'  => 'Bearer ' . $sk,
                'Stripe-Version' => self::STRIPE_VERSION,
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $status = $body['status'] ?? '';

        if ( $status === 'succeeded' ) {
            return new PaymentStatusResult( PaymentStatusResult::SUCCEEDED, $body['latest_charge'] ?? '' );
        }
        if ( in_array( $status, [ 'requires_payment_method', 'canceled' ], true ) ) {
            return new PaymentStatusResult( PaymentStatusResult::FAILED );
        }
        return $status ? new PaymentStatusResult( PaymentStatusResult::PENDING ) : null;
    }

    // ── Reembolsos ────────────────────────────────────────────────────────

    public function refund( string $charge_reference, float $amount_mxn ): bool {
        $sk = $this->secret_key();
        if ( empty( $sk ) || empty( $charge_reference ) ) {
            return false;
        }

        $response = wp_remote_post( 'https://api.stripe.com/v1/refunds', [
            'headers' => [
                'Authorization'  => 'Bearer ' . $sk,
                'Content-Type'   => 'application/x-www-form-urlencoded',
                'Stripe-Version' => self::STRIPE_VERSION,
            ],
            'body' => [
                'charge' => $charge_reference,
                'amount' => (int) round( $amount_mxn * 100 ),
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['id'] ) && empty( $body['error'] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function secret_key(): string {
        $mode = get_option( 'amir_stripe_mode', 'test' );
        return (string) get_option( "amir_stripe_sk_{$mode}", '' );
    }
}
