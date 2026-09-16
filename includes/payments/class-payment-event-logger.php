<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Registra cada intento de pago (creado, exitoso, rechazado, reembolso)
 * en wp_amir_payment_events. Pensado para responder rápido "¿por qué se
 * rechazó esta reserva?" sin tener que ir a buscar en el dashboard de
 * Stripe/Mercado Pago.
 */
class PaymentEventLogger {

    public static function log(
        int    $booking_id,
        string $gateway,
        string $event_type,
        string $message = '',
        array  $raw = []
    ): void {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}amir_payment_events",
            [
                'booking_id'  => $booking_id,
                'gateway'     => $gateway,
                'event_type'  => $event_type,
                'message'     => $message,
                'raw_payload' => wp_json_encode( self::redact( $raw ) ),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public static function for_booking( int $booking_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_payment_events WHERE booking_id = %d ORDER BY created_at DESC",
            $booking_id
        ) ) ?? [];
    }

    public static function recent( int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, b.booking_ref, b.customer_name
             FROM {$wpdb->prefix}amir_payment_events e
             LEFT JOIN {$wpdb->prefix}amir_bookings b ON b.id = e.booking_id
             ORDER BY e.created_at DESC
             LIMIT %d",
            $limit
        ) ) ?? [];
    }

    /**
     * Nunca guardar claves secretas o datos de tarjeta si algún día un raw
     * payload las trajera (no debería, pero es la última línea de defensa
     * antes de escribir a la base de datos).
     */
    private static function redact( array $raw ): array {
        $sensitive = [ 'client_secret', 'card', 'cvc', 'number', 'sk_test', 'sk_live', 'access_token' ];
        array_walk_recursive( $raw, function ( &$value, $key ) use ( $sensitive ) {
            if ( is_string( $key ) && in_array( strtolower( $key ), $sensitive, true ) ) {
                $value = '[redacted]';
            }
        } );
        return $raw;
    }
}
