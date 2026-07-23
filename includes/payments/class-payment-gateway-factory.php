<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve qué implementación de PaymentGatewayInterface usar.
 * Punto único para agregar pasarelas nuevas (Mercado Pago, etc.) sin tocar
 * el controlador ni el booking manager.
 */
class PaymentGatewayFactory {

    /** Gateway con el que se creó una reserva ya existente. */
    public static function for_booking( object $booking ): ?PaymentGatewayInterface {
        return self::make( $booking->payment_gateway ?? 'stripe' );
    }

    public static function make( string $id ): ?PaymentGatewayInterface {
        switch ( $id ) {
            case 'stripe':
                return new StripeGateway();
            default:
                return null;
        }
    }

    /** Gateway a usar cuando el cliente no especifica uno (compatibilidad hacia atrás). */
    public static function default_gateway(): PaymentGatewayInterface {
        return new StripeGateway();
    }
}
