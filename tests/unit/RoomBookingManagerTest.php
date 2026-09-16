<?php
use PHPUnit\Framework\TestCase;
use TourFlow\Rooms\RoomBookingManager;

/**
 * create_pending() valida capacidad/noche mínima/disponibilidad y escribe en
 * amir_bookings con item_type='room' (§ 16 CONTRIBUTING.md — generalización
 * de la tabla existente en vez de duplicar la infraestructura de pago).
 */
final class RoomBookingManagerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	private function activeRoom( array $overrides = [] ): object {
		return (object) array_merge( [
			'id'               => 5,
			'status'           => 'active',
			'capacity_max'     => 2,
			'min_nights'       => 1,
			'price_per_night'  => 100.00,
		], $overrides );
	}

	private function validData( array $overrides = [] ): array {
		return array_merge( [
			'room_id'          => 5,
			'check_in'         => '2026-07-05',
			'check_out'        => '2026-07-08',
			'guests'           => 2,
			'customer_name'    => 'Ana Pérez',
			'customer_email'   => 'ana@example.com',
			'customer_phone'   => '+5219831649541',
			'policy_accepted'  => true,
			'terms_accepted'   => true,
		], $overrides );
	}

	public function test_creates_a_booking_with_room_item_type_and_correct_total(): void {
		$GLOBALS['wpdb']->room_row = $this->activeRoom();

		$result = ( new RoomBookingManager() )->create_pending( $this->validData() );

		$this->assertTrue( $result->success );
		$this->assertSame( 300.00, $result->total_mxn ); // 3 noches x 100

		$booking_data = $GLOBALS['wpdb']->insert_into( 'amir_bookings' );
		$this->assertSame( 'room', $booking_data['item_type'] );
		$this->assertSame( 5, $booking_data['room_id'] );
		$this->assertSame( '2026-07-05', $booking_data['tour_date'] );
		$this->assertSame( '2026-07-08', $booking_data['check_out_date'] );
		$this->assertSame( 2, $booking_data['adults'] );
		$this->assertSame( 0, $booking_data['children'] );

		$room_booking_data = $GLOBALS['wpdb']->insert_into( 'flow_room_bookings' );
		$this->assertSame( 5, $room_booking_data['room_id'] );
		$this->assertSame( 2, $room_booking_data['guests'] );
	}

	public function test_rejects_when_room_does_not_exist(): void {
		$GLOBALS['wpdb']->room_row = null;

		$result = ( new RoomBookingManager() )->create_pending( $this->validData() );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'no existe', $result->error );
	}

	public function test_rejects_when_guests_exceed_capacity(): void {
		$GLOBALS['wpdb']->room_row = $this->activeRoom( [ 'capacity_max' => 2 ] );

		$result = ( new RoomBookingManager() )->create_pending( $this->validData( [ 'guests' => 5 ] ) );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( '2 huésped', $result->error );
	}

	public function test_rejects_stay_shorter_than_min_nights(): void {
		$GLOBALS['wpdb']->room_row = $this->activeRoom( [ 'min_nights' => 3 ] );

		$result = ( new RoomBookingManager() )->create_pending( $this->validData( [
			'check_in' => '2026-07-05', 'check_out' => '2026-07-06', // 1 noche
		] ) );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'mínima', $result->error );
	}

	public function test_rejects_when_dates_overlap_an_existing_booking(): void {
		$GLOBALS['wpdb']->room_row          = $this->activeRoom();
		$GLOBALS['wpdb']->room_booking_rows = [
			(object) [ 'check_in_date' => '2026-07-06', 'check_out_date' => '2026-07-10' ],
		];

		$result = ( new RoomBookingManager() )->create_pending( $this->validData() ); // 05→08, pisa el 06-07

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'disponible', $result->error );
	}

	public function test_allows_same_day_turnover(): void {
		$GLOBALS['wpdb']->room_row          = $this->activeRoom();
		$GLOBALS['wpdb']->room_booking_rows = [
			(object) [ 'check_in_date' => '2026-07-01', 'check_out_date' => '2026-07-05' ],
		];

		$result = ( new RoomBookingManager() )->create_pending( $this->validData() ); // check-in el mismo 05

		$this->assertTrue( $result->success );
	}

	public function test_requires_policy_and_terms_acceptance(): void {
		$GLOBALS['wpdb']->room_row = $this->activeRoom();

		$result = ( new RoomBookingManager() )->create_pending( $this->validData( [ 'policy_accepted' => false ] ) );
		$this->assertFalse( $result->success );

		$result2 = ( new RoomBookingManager() )->create_pending( $this->validData( [ 'terms_accepted' => false ] ) );
		$this->assertFalse( $result2->success );
	}

	public function test_rejects_checkout_not_after_checkin(): void {
		$GLOBALS['wpdb']->room_row = $this->activeRoom();

		$result = ( new RoomBookingManager() )->create_pending( $this->validData( [
			'check_in' => '2026-07-05', 'check_out' => '2026-07-05',
		] ) );

		$this->assertFalse( $result->success );
	}

	// ── reschedule() ───────────────────────────────────────────────────────

	private function existingBooking( array $overrides = [] ): object {
		return (object) array_merge( [
			'id'          => 42,
			'item_type'   => 'room',
			'room_id'     => 5,
			'booking_ref' => 'BK-2026-00042',
			'total_mxn'   => 300.00,
		], $overrides );
	}

	public function test_reschedule_updates_both_tables_and_keeps_total(): void {
		$GLOBALS['wpdb']->booking_row = $this->existingBooking();
		$GLOBALS['wpdb']->room_row    = $this->activeRoom();

		$result = ( new RoomBookingManager() )->reschedule( 42, '2026-08-01', '2026-08-04' );

		$this->assertTrue( $result->success );
		$this->assertSame( 300.00, $result->total_mxn ); // no se recalcula solo

		$booking_update = $GLOBALS['wpdb']->update_on( 'amir_bookings' );
		$this->assertSame( '2026-08-01', $booking_update['tour_date'] );
		$this->assertSame( '2026-08-04', $booking_update['check_out_date'] );

		$room_booking_update = $GLOBALS['wpdb']->update_on( 'flow_room_bookings' );
		$this->assertSame( '2026-08-01', $room_booking_update['check_in_date'] );
		$this->assertSame( '2026-08-04', $room_booking_update['check_out_date'] );
	}

	public function test_reschedule_rejects_when_booking_not_found(): void {
		$GLOBALS['wpdb']->booking_row = null;

		$result = ( new RoomBookingManager() )->reschedule( 999, '2026-08-01', '2026-08-04' );

		$this->assertFalse( $result->success );
	}

	public function test_reschedule_rejects_stay_shorter_than_min_nights(): void {
		$GLOBALS['wpdb']->booking_row = $this->existingBooking();
		$GLOBALS['wpdb']->room_row    = $this->activeRoom( [ 'min_nights' => 3 ] );

		$result = ( new RoomBookingManager() )->reschedule( 42, '2026-08-01', '2026-08-02' ); // 1 noche

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'mínima', $result->error );
	}

	public function test_reschedule_rejects_when_new_dates_conflict_with_another_booking(): void {
		$GLOBALS['wpdb']->booking_row       = $this->existingBooking();
		$GLOBALS['wpdb']->room_row          = $this->activeRoom();
		$GLOBALS['wpdb']->room_booking_rows = [
			(object) [ 'check_in_date' => '2026-08-02', 'check_out_date' => '2026-08-06' ],
		];

		$result = ( new RoomBookingManager() )->reschedule( 42, '2026-08-01', '2026-08-04' ); // pisa el 02-03

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'disponibilidad', $result->error );
	}
}
