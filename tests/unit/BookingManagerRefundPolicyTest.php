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

    private function booking( string $tour_date, string $source = 'direct', float $total = 1000.0 ): object {
        return (object) [
            'total_mxn'      => $total,
            'tour_date'      => $tour_date,
            'booking_source' => $source,
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
}
