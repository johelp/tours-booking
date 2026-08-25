<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\BookingManager;

/**
 * Mínimo de personas POR RESERVA (amir_tours.min_passengers), pedido del
 * cliente 2026-08-21 — ver CONTRIBUTING.md § 16.81. Distinto del mínimo
 * AGREGADO que ya usa class-cron-manager.php (suma todas las reservas
 * confirmadas de una salida) — esto es el piso de UNA reserva puntual,
 * validado server-side en BookingManager::min_passengers_error() sin
 * importar qué tan al frente lo bloquee ya el widget/flujo.
 */
final class BookingManagerMinPassengersTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    private function min_passengers_error( int $tour_id, int $pax, string $lang = 'es' ): ?string {
        $manager = new BookingManager();
        $method  = new ReflectionMethod( BookingManager::class, 'min_passengers_error' );
        $method->setAccessible( true );
        return $method->invoke( $manager, $tour_id, $pax, $lang );
    }

    public function test_no_error_when_tour_has_default_minimum_of_one(): void {
        $GLOBALS['wpdb']->var_result = 1; // DEFAULT de la columna
        $this->assertNull( $this->min_passengers_error( 10, 1 ) );
    }

    public function test_no_error_when_pax_meets_the_minimum(): void {
        $GLOBALS['wpdb']->var_result = 2;
        $this->assertNull( $this->min_passengers_error( 10, 2 ) );
    }

    public function test_no_error_when_pax_exceeds_the_minimum(): void {
        $GLOBALS['wpdb']->var_result = 2;
        $this->assertNull( $this->min_passengers_error( 10, 5 ) );
    }

    public function test_error_in_spanish_when_pax_is_below_the_minimum(): void {
        $GLOBALS['wpdb']->var_result = 2;
        $error = $this->min_passengers_error( 10, 1, 'es' );

        $this->assertNotNull( $error );
        $this->assertStringContainsString( 'mínimo de 2 personas', $error );
    }

    public function test_error_in_english_when_pax_is_below_the_minimum(): void {
        $GLOBALS['wpdb']->var_result = 3;
        $error = $this->min_passengers_error( 10, 2, 'en' );

        $this->assertNotNull( $error );
        $this->assertStringContainsString( 'minimum of 3 people', $error );
    }
}
