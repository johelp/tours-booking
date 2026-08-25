<?php
use PHPUnit\Framework\TestCase;
use TourFlow\Rooms\RoomAvailability;

/**
 * RoomAvailability::has_conflict() documenta el contrato de intervalo
 * semi-abierto [check_in, check_out) confirmado con el cliente 2026-07-31
 * (CONTRIBUTING.md § 16): el día de checkout de una reserva ya libera la
 * habitación para un check-in ese mismo día — no hay que esperar al día
 * siguiente. is_available() documenta cómo eso se traduce en la query real
 * contra flow_room_bookings.
 */
final class RoomAvailabilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	public function test_same_day_turnover_is_not_a_conflict(): void {
		// Reserva existente: 2026-07-05 → 2026-07-11 (checkout el 11).
		// Nueva reserva: check-in el mismo 11 → no debe chocar.
		$this->assertFalse(
			RoomAvailability::has_conflict( '2026-07-11', '2026-07-15', '2026-07-05', '2026-07-11' )
		);
	}

	public function test_overlapping_ranges_conflict(): void {
		// Existente: 2026-07-05 → 2026-07-11. Nueva: 2026-07-10 → 2026-07-14
		// (se pisan una noche, el 10).
		$this->assertTrue(
			RoomAvailability::has_conflict( '2026-07-10', '2026-07-14', '2026-07-05', '2026-07-11' )
		);
	}

	public function test_new_booking_fully_containing_existing_conflicts(): void {
		$this->assertTrue(
			RoomAvailability::has_conflict( '2026-07-01', '2026-07-20', '2026-07-05', '2026-07-11' )
		);
	}

	public function test_completely_separate_ranges_do_not_conflict(): void {
		$this->assertFalse(
			RoomAvailability::has_conflict( '2026-08-01', '2026-08-05', '2026-07-05', '2026-07-11' )
		);
	}

	public function test_is_available_returns_false_when_any_existing_booking_conflicts(): void {
		$GLOBALS['wpdb']->room_booking_rows = [
			(object) [ 'check_in_date' => '2026-07-05', 'check_out_date' => '2026-07-11' ],
		];

		$this->assertFalse( RoomAvailability::is_available( 1, '2026-07-10', '2026-07-14' ) );
	}

	public function test_is_available_returns_true_on_same_day_turnover(): void {
		$GLOBALS['wpdb']->room_booking_rows = [
			(object) [ 'check_in_date' => '2026-07-05', 'check_out_date' => '2026-07-11' ],
		];

		$this->assertTrue( RoomAvailability::is_available( 1, '2026-07-11', '2026-07-15' ) );
	}

	public function test_is_available_returns_true_when_no_existing_bookings(): void {
		$GLOBALS['wpdb']->room_booking_rows = [];

		$this->assertTrue( RoomAvailability::is_available( 1, '2026-07-11', '2026-07-15' ) );
	}

	// ── Disponibilidad por temporada (§ 16.11 CONTRIBUTING.md) ────────────

	public function test_season_allows_true_when_no_rules_configured(): void {
		$GLOBALS['wpdb']->room_availability_rule_rows = [];

		$this->assertTrue( RoomAvailability::season_allows( 1, '2026-01-10', '2026-01-15' ) );
	}

	public function test_season_allows_false_when_stay_falls_inside_a_block_rule(): void {
		// Cerrado todo enero.
		$GLOBALS['wpdb']->room_availability_rule_rows = [
			(object) [ 'rule_type' => 'block', 'date_from' => '2026-01-01', 'date_until' => '2026-01-31', 'priority' => 10 ],
		];

		$this->assertFalse( RoomAvailability::season_allows( 1, '2026-01-10', '2026-01-15' ) );
	}

	public function test_season_allows_false_when_only_one_night_of_the_stay_is_blocked(): void {
		// Cerrado desde el 14 en adelante — una reserva 10→15 incluye la
		// noche del 14, que ya está bloqueada: la reserva completa no vale.
		$GLOBALS['wpdb']->room_availability_rule_rows = [
			(object) [ 'rule_type' => 'block', 'date_from' => '2026-01-14', 'date_until' => null, 'priority' => 10 ],
		];

		$this->assertFalse( RoomAvailability::season_allows( 1, '2026-01-10', '2026-01-15' ) );
	}

	public function test_season_allows_true_when_stay_is_outside_the_block_rule(): void {
		$GLOBALS['wpdb']->room_availability_rule_rows = [
			(object) [ 'rule_type' => 'block', 'date_from' => '2026-01-01', 'date_until' => '2026-01-31', 'priority' => 10 ],
		];

		$this->assertTrue( RoomAvailability::season_allows( 1, '2026-06-01', '2026-06-05' ) );
	}

	public function test_season_allows_respects_allow_rule_restricting_to_a_window(): void {
		// Solo se ofrece junio-agosto: una regla 'allow' para esa ventana
		// implica que CUALQUIER fecha fuera de ella cae al default... pero
		// el default sin reglas que apliquen es "disponible", así que hace
		// falta también un 'block' explícito para el resto del año (mismo
		// criterio que ya usa AvailabilityEngine para tours).
		$GLOBALS['wpdb']->room_availability_rule_rows = [
			(object) [ 'rule_type' => 'allow', 'date_from' => '2026-06-01', 'date_until' => '2026-08-31', 'priority' => 20 ],
			(object) [ 'rule_type' => 'block', 'date_from' => null, 'date_until' => null, 'priority' => 10 ],
		];

		$this->assertTrue( RoomAvailability::season_allows( 1, '2026-07-01', '2026-07-05' ) );
		$this->assertFalse( RoomAvailability::season_allows( 1, '2026-12-01', '2026-12-05' ) );
	}

	public function test_is_available_false_when_season_blocks_even_without_conflicting_bookings(): void {
		$GLOBALS['wpdb']->room_booking_rows = [];
		$GLOBALS['wpdb']->room_availability_rule_rows = [
			(object) [ 'rule_type' => 'block', 'date_from' => '2026-01-01', 'date_until' => '2026-01-31', 'priority' => 10 ],
		];

		$this->assertFalse( RoomAvailability::is_available( 1, '2026-01-10', '2026-01-15' ) );
	}
}
