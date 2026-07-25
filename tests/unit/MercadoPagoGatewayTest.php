<?php
namespace AmirBooking\Payments {
    // Override liviano solo para tests: permite inyectar la respuesta HTTP
    // sin pegarle de verdad a la API de Mercado Pago. Como PHP resuelve
    // llamadas a funciones sin namespace buscando primero en el namespace
    // actual, esto reemplaza wp_remote_get()/wp_remote_retrieve_body() para
    // cualquier código que corra dentro de AmirBooking\Payments — nada más.
    if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_get' ) ) {
        function wp_remote_get( $url, $args = [] ) {
            return \MercadoPagoGatewayTestMock::$response;
        }
    }
    if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_retrieve_body' ) ) {
        function wp_remote_retrieve_body( $response ) {
            return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
        }
    }
}

namespace {

use PHPUnit\Framework\TestCase;
use AmirBooking\Payments\MercadoPagoGateway;
use AmirBooking\Payments\PaymentEvent;

class MercadoPagoGatewayTestMock {
    public static $response = [];
}

/**
 * verify_webhook_signature() se testea con HMACs reales (sin red).
 * parse_webhook_event() necesita el payload del pago (GET /v1/payments/{id})
 * — se mockea wp_remote_get() en el namespace del gateway, ver arriba.
 */
final class MercadoPagoGatewayTest extends TestCase {

    protected function setUp(): void {
        update_option( 'amir_mp_mode', 'test' );
        update_option( 'amir_mp_access_token_test', 'TEST-token' );
        update_option( 'amir_mp_webhook_secret', '' );
    }

    private function mockPaymentResponse( array $payment ): void {
        MercadoPagoGatewayTestMock::$response = [ 'body' => json_encode( $payment ) ];
    }

    // ── verify_webhook_signature() ──────────────────────────────────────

    public function test_verify_webhook_signature_accepts_a_valid_signature(): void {
        update_option( 'amir_mp_webhook_secret', 'test-secret' );
        $payload    = json_encode( [ 'type' => 'payment', 'data' => [ 'id' => '123456' ] ] );
        $ts         = '1700000000';
        $request_id = 'req-abc';
        $manifest   = "id:123456;request-id:{$request_id};ts:{$ts};";
        $v1         = hash_hmac( 'sha256', $manifest, 'test-secret' );

        $gateway = new MercadoPagoGateway();
        $this->assertTrue( $gateway->verify_webhook_signature( $payload, [
            'x-signature'  => "ts={$ts},v1={$v1}",
            'x-request-id' => $request_id,
        ] ) );
    }

    public function test_verify_webhook_signature_rejects_a_tampered_signature(): void {
        update_option( 'amir_mp_webhook_secret', 'test-secret' );
        $payload = json_encode( [ 'type' => 'payment', 'data' => [ 'id' => '123456' ] ] );

        $gateway = new MercadoPagoGateway();
        $this->assertFalse( $gateway->verify_webhook_signature( $payload, [
            'x-signature'  => 'ts=1700000000,v1=not-the-real-signature',
            'x-request-id' => 'req-abc',
        ] ) );
    }

    public function test_verify_webhook_signature_rejects_without_configured_secret(): void {
        $gateway = new MercadoPagoGateway();
        $this->assertFalse( $gateway->verify_webhook_signature( '{}', [
            'x-signature' => 'ts=1,v1=abc', 'x-request-id' => 'x',
        ] ) );
    }

    // ── parse_webhook_event() ────────────────────────────────────────────

    public function test_parse_webhook_event_maps_approved_payment_to_succeeded(): void {
        $this->mockPaymentResponse( [
            'id' => 999, 'status' => 'approved', 'external_reference' => 'AMIR-2026-00099',
        ] );
        $gateway = new MercadoPagoGateway();
        $event   = $gateway->parse_webhook_event( json_encode( [ 'type' => 'payment', 'data' => [ 'id' => '999' ] ] ) );

        $this->assertNotNull( $event );
        $this->assertSame( PaymentEvent::SUCCEEDED, $event->type );
        $this->assertSame( 'AMIR-2026-00099', $event->gateway_reference );
        $this->assertSame( '999', $event->charge_reference );
    }

    public function test_parse_webhook_event_maps_rejected_payment_to_failed(): void {
        $this->mockPaymentResponse( [
            'id' => 1000, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount',
            'external_reference' => 'AMIR-2026-00100',
        ] );
        $gateway = new MercadoPagoGateway();
        $event   = $gateway->parse_webhook_event( json_encode( [ 'type' => 'payment', 'data' => [ 'id' => '1000' ] ] ) );

        $this->assertNotNull( $event );
        $this->assertSame( PaymentEvent::FAILED, $event->type );
        $this->assertSame( 'cc_rejected_insufficient_amount', $event->reason );
    }

    public function test_parse_webhook_event_ignores_pending_status(): void {
        $this->mockPaymentResponse( [
            'id' => 1001, 'status' => 'pending', 'external_reference' => 'AMIR-2026-00101',
        ] );
        $gateway = new MercadoPagoGateway();
        $event   = $gateway->parse_webhook_event( json_encode( [ 'type' => 'payment', 'data' => [ 'id' => '1001' ] ] ) );

        $this->assertNull( $event );
    }

    public function test_parse_webhook_event_ignores_non_payment_notifications(): void {
        $gateway = new MercadoPagoGateway();
        $event   = $gateway->parse_webhook_event( json_encode( [ 'type' => 'merchant_order', 'data' => [ 'id' => '1' ] ] ) );

        $this->assertNull( $event );
    }
}

}
