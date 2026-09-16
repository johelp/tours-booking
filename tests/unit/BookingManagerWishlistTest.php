<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\BookingManager;

/**
 * create_wishlist() crea una reserva REAL (misma tabla amir_bookings) para
 * un tour todavía en borrador — sin cobrar y sin bloquear por cupo. Estos
 * tests documentan ese contrato: status/booking_source = 'wishlist', el
 * precio queda congelado con el cotizador, y una segunda anotación de la
 * misma persona para el mismo tour/horario/fecha actualiza en vez de
 * duplicar.
 */
final class BookingManagerWishlistTest extends TestCase {

    protected function setUp(): void {
        update_option( 'amir_usd_rate_mode', 'manual' );
        update_option( 'amir_usd_rate_manual', 17.0 );
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    private function validData( array $overrides = [] ): array {
        return array_merge( [
            'tour_id'        => 3,
            'schedule_id'    => 7,
            'date'           => '2026-12-15',
            'adults'         => 2,
            'children'       => 1,
            'babies'         => 0,
            'customer_name'  => 'Ana Pérez',
            'customer_email' => 'ana@example.com',
            'customer_phone' => '+5219831649541',
        ], $overrides );
    }

    public function test_creates_a_real_booking_with_wishlist_status_and_source(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 3, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
            (object) [ 'person_type' => 'child', 'group_min' => null, 'group_max' => null, 'price_mxn' => 250.00 ],
        ];
        $GLOBALS['wpdb']->var_result = null; // no hay anotación previa con ese email

        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData() );

        $this->assertTrue( $result->success );
        $this->assertNotSame( '', $result->booking_ref );
        $this->assertEqualsWithDelta( 1250.0, $result->total_mxn, 0.001 ); // 2*500 + 1*250

        $inserted = $GLOBALS['wpdb']->last_insert_data;
        $this->assertSame( 'wishlist', $inserted['status'] );
        $this->assertSame( 'wishlist', $inserted['booking_source'] );
        $this->assertSame( 'ana@example.com', $inserted['customer_email'] );
        $this->assertSame( 7, $inserted['schedule_id'] );
    }

    public function test_does_not_check_availability_or_capacity(): void {
        // A propósito: ni tour_row trae max_capacity relevante, ni se
        // configura ningún mock de disponibilidad — si create_wishlist()
        // intentara bloquear por cupo, no tendría de dónde leerlo y
        // fallaría o devolvería error. Que devuelva éxito confirma que
        // no pasa por AvailabilityEngine.
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 3, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
        ];
        $GLOBALS['wpdb']->var_result = null;

        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'adults' => 50, 'children' => 0 ] ) );

        $this->assertTrue( $result->success );
    }

    public function test_missing_tour_or_date_is_an_error(): void {
        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'date' => '' ] ) );

        $this->assertFalse( $result->success );
        $this->assertNotSame( '', $result->error );
    }

    public function test_invalid_email_is_an_error(): void {
        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'customer_email' => 'no-es-un-email' ] ) );

        $this->assertFalse( $result->success );
    }

    public function test_rejects_children_when_tour_does_not_allow_them(): void {
        $GLOBALS['wpdb']->tour_row = (object) [ 'id' => 3, 'price_model' => 'percapita', 'allow_children' => 0, 'allow_babies' => 1 ];

        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'children' => 1, 'babies' => 0 ] ) );

        $this->assertFalse( $result->success );
        $this->assertStringContainsString( 'niños', $result->error );
    }

    public function test_rejects_babies_when_tour_does_not_allow_them(): void {
        $GLOBALS['wpdb']->tour_row = (object) [ 'id' => 3, 'price_model' => 'percapita', 'allow_children' => 1, 'allow_babies' => 0 ];

        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'children' => 0, 'babies' => 1 ] ) );

        $this->assertFalse( $result->success );
        $this->assertStringContainsString( 'bebés', $result->error );
    }

    public function test_allows_children_when_tour_does_not_restrict_them(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 3, 'price_model' => 'percapita', 'allow_children' => 1, 'allow_babies' => 1 ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
            (object) [ 'person_type' => 'child', 'group_min' => null, 'group_max' => null, 'price_mxn' => 250.00 ],
        ];
        $GLOBALS['wpdb']->var_result = null;

        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'children' => 1, 'babies' => 0 ] ) );

        $this->assertTrue( $result->success );
    }

    public function test_adults_only_is_never_blocked_regardless_of_tour_policy(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 3, 'price_model' => 'percapita', 'allow_children' => 0, 'allow_babies' => 0 ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
        ];
        $GLOBALS['wpdb']->var_result = null;

        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'children' => 0, 'babies' => 0 ] ) );

        $this->assertTrue( $result->success );
    }

    public function test_repeat_signup_updates_instead_of_duplicating(): void {
        $GLOBALS['wpdb']->tour_row    = (object) [ 'id' => 3, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows  = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
        ];
        $GLOBALS['wpdb']->var_result  = 42; // ya existía una anotación previa (id=42)
        $GLOBALS['wpdb']->booking_row = (object) [ 'id' => 42, 'booking_ref' => 'AMIR-2026-00042' ];

        $manager = new BookingManager();
        $result  = $manager->create_wishlist( $this->validData( [ 'adults' => 4, 'children' => 0 ] ) );

        $this->assertTrue( $result->success );
        $this->assertSame( 'AMIR-2026-00042', $result->booking_ref );
        $this->assertSame( [ 'id' => 42 ], $GLOBALS['wpdb']->last_update_where );
        $this->assertSame( 4, $GLOBALS['wpdb']->last_update_data['adults'] );
        // No debe haber insertado una fila nueva
        $this->assertSame( '', $GLOBALS['wpdb']->last_insert_table );
    }
}
