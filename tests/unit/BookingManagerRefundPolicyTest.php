<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\BookingManager;

/**
 * calculate_refund() es privado — se invoca por reflexión en vez de
 * cambiar su visibilidad solo para poder testearlo. Esto documenta el
 * comportamiento actual de la política de cancelación (README.md § Política
 * de cancelación) y debería fallar si alguien la cambia sin querer.
 */
final class BookingManagerRefundPolicyTest extends TestCase {

    private function calculate_refund( object $booking, string $reason_type ): array {
        $manager = new BookingManager();
        $method  = new ReflectionMethod( BookingManager::class, 'calculate_refund' );
        $method->setAccessible( true );
        return $method->invoke( $manager, $booking, $reason_type );
    }

    private function booking( string $tour_date, string $source = 'direct', float $total = 1000.0, int $deposit_pct = 0 ): object {
        return (object) [
            'total_mxn'      => $total,
            'tour_date'      => $tour_date,
            'booking_source' => $source,
            'deposit_pct'    => $deposit_pct,
        ];
    }

    public function test_seven_or_more_days_before_is_full_refund(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+8 days' ) ) );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 0, $refund['charge_pct'] );
        $this->assertSame( 1000.0, $refund['refund_mxn'] );
    }

    public function test_three_to_six_days_before_is_half_refund(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+5 days' ) ) );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 50, $refund['charge_pct'] );
        $this->assertSame( 500.0, $refund['refund_mxn'] );
    }

    public function test_less_than_three_days_before_is_no_refund(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+1 day' ) ) );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 100, $refund['charge_pct'] );
        $this->assertSame( 0.0, $refund['refund_mxn'] );
    }

    public function test_weather_cancellation_is_always_full_refund_even_last_minute(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+1 day' ) ) );
        $refund  = $this->calculate_refund( $booking, 'weather' );

        $this->assertSame( 0, $refund['charge_pct'] );
        $this->assertSame( 1000.0, $refund['refund_mxn'] );
    }

    public function test_min_pax_cancellation_is_always_full_refund(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+1 day' ) ) );
        $refund  = $this->calculate_refund( $booking, 'min_pax' );

        $this->assertSame( 1000.0, $refund['refund_mxn'] );
    }

    public function test_external_platform_bookings_are_not_refunded_by_the_plugin(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+10 days' ) ), 'tripadvisor' );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 0.0, $refund['refund_mxn'] );
    }

    public function test_past_tour_date_has_no_refund(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '-2 days' ) ) );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 100, $refund['charge_pct'] );
        $this->assertSame( 0.0, $refund['refund_mxn'] );
    }

    /**
     * Depósito parcial ("Depósito parcial por tour") — la política
     * (100/50/0% según antelación) se aplica sobre el monto REALMENTE
     * cobrado (el depósito), nunca sobre total_mxn completo. Con un
     * depósito del 20% sobre $1000 (= $200 cobrados), un reembolso "total"
     * (7+ días) debe devolver $200, no $1000 — devolver $1000 sería
     * reembolsar plata que el cliente nunca pagó.
     */
    public function test_deposit_refund_is_calculated_on_charged_amount_not_full_total(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+8 days' ) ), 'direct', 1000.0, 20 );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 0, $refund['charge_pct'] );
        $this->assertSame( 200.0, $refund['refund_mxn'] );
    }

    public function test_deposit_refund_half_policy_applies_to_deposit_amount(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+5 days' ) ), 'direct', 1000.0, 20 );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 50, $refund['charge_pct'] );
        $this->assertSame( 100.0, $refund['refund_mxn'] );
    }

    public function test_deposit_zero_percent_behaves_like_full_payment(): void {
        $booking = $this->booking( date( 'Y-m-d', strtotime( '+8 days' ) ), 'direct', 1000.0, 0 );
        $refund  = $this->calculate_refund( $booking, 'client' );

        $this->assertSame( 1000.0, $refund['refund_mxn'] );
    }
}
