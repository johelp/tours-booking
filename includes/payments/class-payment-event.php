<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Evento de pago normalizado, independiente de qué pasarela lo generó.
 * Es lo que BookingController recibe de parse_webhook_event() y lo que
 * PaymentEventLogger persiste en wp_amir_payment_events.
 */
class PaymentEvent {

    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';
    public const REFUNDED  = 'refunded';

    public string $type;             // succeeded | failed | refunded
    public string $gateway_reference; // payment_intent id / payment id de MP
    public string $charge_reference;  // charge id / el mismo id si el gateway no distingue
    public string $reason;            // motivo de rechazo si type=failed (decline_code, status_detail, etc.)
    public array  $raw;               // payload completo, para poder investigar después

    public function __construct( string $type, string $gateway_reference, string $charge_reference = '', string $reason = '', array $raw = [] ) {
        $this->type              = $type;
        $this->gateway_reference = $gateway_reference;
        $this->charge_reference  = $charge_reference;
        $this->reason            = $reason;
        $this->raw                = $raw;
    }
}
