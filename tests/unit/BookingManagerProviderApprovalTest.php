<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\BookingManager;

/**
 * Marketplace de proveedores externos (§ 11 CONTRIBUTING.md).
 *
 * confirm() bifurca a 'pending_provider_approval' en vez de 'confirmed'
 * cuando el tour tiene un proveedor externo activo asignado;
 * calculate_refund() fuerza reembolso 100% para 'provider_rejected'/
 * 'provider_expired' (no es responsabilidad del cliente); y
 * provider_approve()/provider_reject() son idempotentes — no reprocesan
 * una reserva que ya cambió de estado (doble click, o carrera con el cron
 * de auto-cancelación a 48h).
 */
final class BookingManagerProviderApprovalTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    private function calculate_refund( object $booking, string $reason_type ): array {
        $manager = new BookingManager();
        $method  = new ReflectionMethod( BookingManager::class, 'calculate_refund' );
        $method->setAccessible( true );
        return $method->invoke( $manager, $booking, $reason_type );
    }

    private function refund_booking( string $tour_date, float $total = 1000.0 ): object {
        return (object) [
            'total_mxn'      => $total,
            'tour_date'      => $tour_date,
            'booking_source' => 'direct',
        ];
    }

    /** Fila completa de amir_bookings — evita warnings de propiedad indefinida en create_admin_notification()/cancel(). */
    private function booking_row( array $overrides = [] ): object {
        return (object) array_merge( [
            'id'                      => 1,
            'booking_ref'             => 'BK-2026-00001',
            'status'                  => 'pending',
            'tour_id'                 => 10,
            'schedule_id'             => 3,
            'partner_id'              => null,
            'total_mxn'               => 1000.0,
            'tour_date'               => date( 'Y-m-d', strtotime( '+10 days' ) ),
            'booking_source'          => 'direct',
            'customer_name'           => 'Ana Pérez',
            'customer_email'          => 'ana@example.com',
            'adults'                  => 2,
            'children'                => 0,
            'babies'                  => 0,
            'stripe_charge_id'        => '',
            'gateway_charge_id'       => '',
            'provider_response_token' => null,
            'provider_responded_at'   => null,
            'provider_reject_reason'  => null,
            'internal_notes'          => '',
        ], $overrides );
    }

    // ── calculate_refund(): 100% para rechazo/vencimiento del proveedor ────

    public function test_provider_rejected_is_always_full_refund(): void {
        $booking = $this->refund_booking( date( 'Y-m-d', strtotime( '+1 day' ) ) );
        $refund  = $this->calculate_refund( $booking, 'provider_rejected' );

        $this->assertSame( 0, $refund['charge_pct'] );
        $this->assertSame( 1000.0, $refund['refund_mxn'] );
    }

    public function test_provider_expired_is_always_full_refund(): void {
        $booking = $this->refund_booking( date( 'Y-m-d', strtotime( '+1 day' ) ) );
        $refund  = $this->calculate_refund( $booking, 'provider_expired' );

        $this->assertSame( 0, $refund['charge_pct'] );
        $this->assertSame( 1000.0, $refund['refund_mxn'] );
    }

    // ── confirm(): bifurca según si el tour tiene proveedor externo activo ─

    public function test_confirm_goes_straight_to_confirmed_without_provider(): void {
        $fake               = $GLOBALS['wpdb'];
        $fake->booking_row  = $this->booking_row();
        $fake->var_result   = 0; // amir_tours.provider_id = 0 → sin proveedor

        $manager = new BookingManager();
        $ok      = $manager->confirm( 1, 'ch_123' );

        $this->assertTrue( $ok );
        $this->assertSame( 'confirmed', $fake->last_update_data['status'] );
    }

    public function test_confirm_goes_to_pending_provider_approval_with_active_provider(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking_row();
        // Mismo valor sirve para las dos queries de tour_has_active_provider()
        // (provider_id y active) — cualquier entero positivo satisface ambas:
        // provider_id > 0 y (bool) active = true.
        $fake->var_result = 7;

        $manager = new BookingManager();
        $ok      = $manager->confirm( 1, 'ch_123' );

        $this->assertTrue( $ok );
        $this->assertSame( 'pending_provider_approval', $fake->last_update_data['status'] );
        $this->assertArrayHasKey( 'provider_response_token', $fake->last_update_data );
        $this->assertNotEmpty( $fake->last_update_data['provider_response_token'] );
    }

    /**
     * Depósito parcial ("Depósito parcial por tour") — un cobro que llega
     * sobre una reserva YA 'confirmed' con saldo de depósito pendiente es
     * el pago del SALDO, no una confirmación nueva: confirm() debe marcar
     * balance_paid_at y devolver true SIN tocar `status` (la reserva ya
     * estaba confirmada, no hay que reconfirmarla ni reenviar el voucher).
     */
    public function test_confirm_marks_balance_paid_instead_of_reconfirming_when_deposit_balance_pending(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking_row( [
            'status'          => 'confirmed',
            'item_type'       => 'tour',
            'deposit_pct'     => 20,
            'balance_paid_at' => null,
        ] );

        $manager = new BookingManager();
        $ok      = $manager->confirm( 1, 'ch_balance_123' );

        $this->assertTrue( $ok );
        $this->assertArrayHasKey( 'balance_paid_at', $fake->last_update_data );
        $this->assertArrayNotHasKey( 'status', $fake->last_update_data );
    }

    // ── provider_approve() / provider_reject(): idempotencia ───────────────

    public function test_provider_approve_rejects_a_booking_already_processed(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking_row( [ 'status' => 'confirmed' ] );

        $manager = new BookingManager();
        $ok      = $manager->provider_approve( 1 );

        $this->assertFalse( $ok );
    }

    public function test_provider_reject_rejects_a_booking_already_processed(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking_row( [ 'status' => 'cancelled_provider' ] );

        $manager = new BookingManager();
        $result  = $manager->provider_reject( 1, 'provider_rejected' );

        $this->assertFalse( $result->success );
    }

    public function test_provider_approve_transitions_pending_approval_to_confirmed(): void {
        $fake              = $GLOBALS['wpdb'];
        // gateway_charge_id no vacío = modo 'immediate' (default): la reserva
        // ya se cobró antes de llegar acá, provider_approve() debe confirmarla
        // directo. El caso de cobro diferido (charge_id vacío → awaiting_payment
        // en vez de confirmed) se cubre aparte, ver test de abajo.
        $fake->booking_row = $this->booking_row( [
            'status'            => 'pending_provider_approval',
            'gateway_charge_id' => 'ch_test123',
        ] );

        $manager = new BookingManager();
        $ok      = $manager->provider_approve( 1 );

        $this->assertTrue( $ok );
        $this->assertSame( 'confirmed', $fake->last_update_data['status'] );
    }

    /**
     * Cobro diferido (§ 11.0 CONTRIBUTING.md, modo 'on_approval'): la reserva
     * nunca se cobró (gateway_charge_id/stripe_charge_id vacíos, tal como los
     * deja create_pending() en este modo) — provider_approve() no debe
     * confirmarla directo, tiene que pasar a 'awaiting_payment' para recién
     * ahí mandar el link de pago real.
     */
    public function test_provider_approve_goes_to_awaiting_payment_when_never_charged(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking_row( [
            'status'            => 'pending_provider_approval',
            'gateway_charge_id' => '',
            'stripe_charge_id'  => '',
        ] );

        $manager = new BookingManager();
        $ok      = $manager->provider_approve( 1 );

        $this->assertTrue( $ok );
        $this->assertSame( 'awaiting_payment', $fake->last_update_data['status'] );
    }

    public function test_provider_reject_transitions_pending_approval_to_cancelled(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking_row( [ 'status' => 'pending_provider_approval' ] );

        $manager = new BookingManager();
        $result  = $manager->provider_reject( 1, 'provider_rejected', 'Sin cupo esa fecha' );

        $this->assertTrue( $result->success );
        $this->assertSame( 'cancelled_provider', $fake->last_update_data['status'] );
    }

    // ── authorize_provider_access() ─────────────────────────────────────────

    public function test_authorize_provider_access_rejects_empty_token(): void {
        $manager = new BookingManager();
        $booking = (object) [ 'provider_response_token' => 'secret123' ];

        $this->assertFalse( $manager->authorize_provider_access( $booking, '' ) );
    }

    public function test_authorize_provider_access_rejects_wrong_token(): void {
        $manager = new BookingManager();
        $booking = (object) [ 'provider_response_token' => 'secret123' ];

        $this->assertFalse( $manager->authorize_provider_access( $booking, 'wrong-token' ) );
    }

    public function test_authorize_provider_access_accepts_correct_token(): void {
        $manager = new BookingManager();
        $booking = (object) [ 'provider_response_token' => 'secret123' ];

        $this->assertTrue( $manager->authorize_provider_access( $booking, 'secret123' ) );
    }

    /** Token ya usado (aprobado/rechazado antes) o vencido — provider_approve()/provider_reject() lo ponen en NULL. */
    public function test_authorize_provider_access_rejects_when_token_already_used(): void {
        $manager = new BookingManager();
        $booking = (object) [ 'provider_response_token' => null ];

        $this->assertFalse( $manager->authorize_provider_access( $booking, 'anything' ) );
    }
}
