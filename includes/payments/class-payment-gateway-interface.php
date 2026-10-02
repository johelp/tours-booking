<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Contrato común para pasarelas de pago (Stripe, Mercado Pago, futuras).
 *
 * BookingController solo conoce esta interfaz — nunca llama directo a la
 * API de Stripe o Mercado Pago. Agregar una pasarela nueva es implementar
 * esta interfaz + registrarla en PaymentGatewayFactory, sin tocar el
 * controlador.
 */
interface PaymentGatewayInterface {

    /** Identificador corto: 'stripe' | 'mercadopago'. Se guarda en amir_bookings.payment_gateway. */
    public function id(): string;

    /** ¿Tiene credenciales cargadas en Configuración? */
    public function is_configured(): bool;

    /**
     * Inicia el cobro de una reserva ya creada (estado pending).
     * Devuelve la referencia de la pasarela + lo que el frontend necesita
     * para completar el pago (client_secret de Stripe, init_point de MP, etc.).
     */
    public function create_payment( \AmirBooking\Core\BookingResult $booking ): PaymentCreationResult;

    /**
     * Verifica la firma de un webhook entrante. $headers son los headers
     * HTTP crudos de la request (ya en minúsculas), para que cada gateway
     * lea el suyo (Stripe-Signature / x-signature) sin que el controlador
     * necesite saber cuál usa cada uno.
     */
    public function verify_webhook_signature( string $payload, array $headers ): bool;

    /**
     * Traduce el payload ya verificado de un webhook a un PaymentEvent
     * genérico. Devuelve null si el tipo de evento no es relevante
     * (el controlador simplemente lo ignora).
     */
    public function parse_webhook_event( string $payload ): ?PaymentEvent;

    /**
     * Consulta el estado real del pago directamente contra la API de la
     * pasarela (nunca confiar en lo que dice el cliente). Devuelve null si
     * no se pudo verificar (API caída, credenciales faltantes, etc.) — en
     * ese caso el llamador NO debe confirmar la reserva.
     */
    public function fetch_payment_status( string $reference ): ?PaymentStatusResult;

    /** Reembolsa (total o parcial) un cobro ya confirmado. */
    public function refund( string $charge_reference, float $amount_mxn ): bool;
}
