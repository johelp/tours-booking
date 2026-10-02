<?php
namespace AmirBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Resultado de consultar el estado real de un pago contra la API de la
 * pasarela (nunca confiar en lo que dice el cliente).
 */
class PaymentStatusResult {

    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';
    public const PENDING   = 'pending';

    public string $status;           // succeeded | failed | pending
    public string $charge_reference; // id del cobro/charge, si la pasarela lo distingue del payment_reference

    public function __construct( string $status, string $charge_reference = '' ) {
        $this->status           = $status;
        $this->charge_reference = $charge_reference;
    }
}
