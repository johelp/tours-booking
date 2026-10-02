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
            case 'mercadopago':
                return new MercadoPagoGateway();
            default:
                // Punto de extensión para "plugins satélite" (ej.
                // redsys-for-tourflow) — un plugin aparte que implementa
                // PaymentGatewayInterface puede registrarse acá sin que este
                // archivo necesite saber que existe. $id es el valor guardado
                // en amir_bookings.payment_gateway / amir_default_gateway;
                // 'manual' (pagos cargados a mano desde el admin, ver
                // BookingsPage::handle_detail_action() case 'record_manual_payment')
                // cae acá también y resuelve a null a propósito — no hay
                // pasarela real que reembolsar automáticamente.
                $gateway = apply_filters( 'amir_payment_gateway_resolve', null, $id );
                return $gateway instanceof PaymentGatewayInterface ? $gateway : null;
        }
    }

    /**
     * Gateway a usar cuando el cliente no especifica uno — configurable
     * desde Configuración (Configuración → Pasarela de pago activa).
     * Si la opción no está seteada o apunta a algo no soportado, cae en
     * Stripe (compatibilidad hacia atrás con instalaciones ya en uso).
     */
    public static function default_gateway(): PaymentGatewayInterface {
        $id = get_option( 'amir_default_gateway', 'stripe' );
        return self::make( $id ) ?? new StripeGateway();
    }
}
