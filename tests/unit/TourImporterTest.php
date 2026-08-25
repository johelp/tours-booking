<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\TourImporter;

/**
 * Solo cubre la validación de forma del JSON (CONTRIBUTING.md § 15.9) — el
 * resto de TourImporter (import_one()) llama wp_insert_post()/
 * media_sideload_image()/etc., que necesitan un entorno de integración real
 * de WordPress para probarse de verdad (no el bootstrap liviano de esta
 * suite). Ver CONTRIBUTING.md: sin probar en vivo todavía.
 */
final class TourImporterTest extends TestCase {

	public function test_rejects_json_without_tours_key(): void {
		$result = ( new TourImporter() )->import( [ 'notTours' => [] ] );

		$this->assertSame( 1, $result['errors'] );
		$this->assertArrayHasKey( 'fatal_error', $result );
	}

	public function test_rejects_empty_tours_array(): void {
		$result = ( new TourImporter() )->import( [ 'tours' => [] ] );

		$this->assertArrayHasKey( 'fatal_error', $result );
	}

	public function test_flags_non_object_tour_entries_without_crashing(): void {
		$result = ( new TourImporter() )->import( [ 'tours' => [ 'not an object' ] ] );

		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'error', $result['results'][0]['action'] );
	}
}
