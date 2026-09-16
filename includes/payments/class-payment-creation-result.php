<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Resultado de iniciar un cobro con una pasarela.
 *
 * client_payload es intencionalmente libre (array) porque cada pasarela le
 * da al frontend algo distinto: Stripe necesita client_secret + publishable
 * key para Elements; Mercado Pago Checkout Pro necesita una URL (init_point)
 * a la que redirigir. El controlador no interpreta este array, solo lo
 * reenvía tal cual en la respuesta REST.
 */
class PaymentCreationResult {

    public bool   $success;
    public string $reference;      // payment_intent id / preference id
    public array  $client_payload; // lo que necesita el frontend para completar el pago
    public string $error;

    private function __construct( bool $success, string $reference, array $client_payload, string $error ) {
        $this->success        = $success;
        $this->reference      = $reference;
        $this->client_payload = $client_payload;
        $this->error          = $error;
    }

    public static function success( string $reference, array $client_payload ): self {
        return new self( true, $reference, $client_payload, '' );
    }

    public static function error( string $message ): self {
        return new self( false, '', [], $message );
    }
}
