<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\PendingExpiry;

/**
 * Vencimiento de reservas 'pending' impagas + plazo de gracia tras un pago
 * rechazado (auditoría del flujo de pago rechazado, 2026-09-28). Un intento
 * fallido ya NO cancela la reserva: la retiene hasta hold_deadline() para que
 * el cliente actualice la tarjeta — criterio de Vrbo/Booking — sin estirarse
 * nunca hasta el día del tour.
 */
final class PendingExpiryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']                = new FakeWpdb();
        $GLOBALS['__amir_test_options'] = [];
    }

    private function row( array $o = [] ): object {
        return (object) array_merge( [
            'id'                => 1,
            'coupon_code'       => '',
            'created_at'        => '2026-06-01 10:00:00',
            'payment_failed_at' => null,
            'tour_date'         => '2026-06-20',
        ], $o );
    }

    private function ts( string $s ): int {
        return (int) strtotime( $s );
    }

    public function test_without_a_failed_payment_the_hold_is_the_normal_expiry(): void {
        update_option( 'amir_pending_expire_mins', 15 );
        $this->assertSame( $this->ts( '2026-06-01 10:15:00' ), PendingExpiry::hold_deadline( $this->row() ) );
    }

    public function test_a_failed_payment_opens_the_grace_period(): void {
        update_option( 'amir_pending_expire_mins', 15 );
        update_option( 'amir_payment_grace_mins', 360 ); // 6 h
        $row = $this->row( [ 'payment_failed_at' => '2026-06-01 10:05:00' ] );

        $this->assertSame( $this->ts( '2026-06-01 16:05:00' ), PendingExpiry::hold_deadline( $row ) );
    }

    public function test_grace_can_be_configured_below_12_hours(): void {
        update_option( 'amir_payment_grace_mins', 60 ); // 1 h
        $row = $this->row( [ 'payment_failed_at' => '2026-06-01 10:05:00' ] );

        $this->assertSame( $this->ts( '2026-06-01 11:05:00' ), PendingExpiry::hold_deadline( $row ) );
    }

    public function test_grace_is_capped_at_the_start_of_the_tour_day(): void {
        update_option( 'amir_payment_grace_mins', 1440 ); // 24 h
        // Falló la noche anterior al tour: 24 h de gracia se pasarían del día del tour.
        $row = $this->row( [ 'payment_failed_at' => '2026-06-19 22:00:00', 'tour_date' => '2026-06-20' ] );

        $this->assertSame( $this->ts( '2026-06-20 00:00:00' ), PendingExpiry::hold_deadline( $row ) );
    }

    public function test_grace_never_shortens_the_normal_expiry(): void {
        update_option( 'amir_pending_expire_mins', 15 );
        update_option( 'amir_payment_grace_mins', 60 );
        // Tour "mañana" con el fallo a las 23:50: el tope (00:00) queda antes
        // que el vencimiento normal — manda el vencimiento normal.
        $row = $this->row( [ 'created_at' => '2026-06-19 23:50:00', 'payment_failed_at' => '2026-06-19 23:55:00', 'tour_date' => '2026-06-20' ] );

        $this->assertSame( $this->ts( '2026-06-20 00:05:00' ), PendingExpiry::hold_deadline( $row ) );
    }

    public function test_grace_disabled_falls_back_to_the_normal_expiry(): void {
        update_option( 'amir_pending_expire_mins', 15 );
        update_option( 'amir_payment_grace_mins', 0 );
        $row = $this->row( [ 'payment_failed_at' => '2026-06-01 10:05:00' ] );

        $this->assertSame( $this->ts( '2026-06-01 10:15:00' ), PendingExpiry::hold_deadline( $row ) );
    }

    // ── release() ───────────────────────────────────────────────────────────

    public function test_release_expires_stale_pending_bookings_and_returns_the_coupon_use(): void {
        $GLOBALS['wpdb']->booking_rows = [
            $this->row( [ 'id' => 5, 'coupon_code' => 'PROMO1', 'created_at' => '2020-01-01 10:00:00', 'tour_date' => '2030-01-01' ] ),
        ];

        $released = PendingExpiry::release( false );

        $this->assertSame( 1, $released );
        $sql = implode( "\n", $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "status = 'payment_expired'", $sql );
        $this->assertStringContainsString( 'GREATEST( times_used - 1, 0 )', $sql );
    }

    public function test_release_keeps_a_booking_that_is_inside_its_grace_period(): void {
        update_option( 'amir_payment_grace_mins', 1440 );
        $now = current_time( 'mysql' );
        $GLOBALS['wpdb']->booking_rows = [
            $this->row( [ 'id' => 6, 'created_at' => '2020-01-01 10:00:00', 'payment_failed_at' => $now, 'tour_date' => date( 'Y-m-d', strtotime( '+10 days' ) ) ] ),
        ];

        $this->assertSame( 0, PendingExpiry::release( false ) );
        $this->assertStringNotContainsString( 'payment_expired', implode( "\n", $GLOBALS['wpdb']->queries ) );
    }
}
