<?php
use PHPUnit\Framework\TestCase;
use TourFlow\Cart\CartConfirmationEmail;
use AmirBooking\Core\Currency;

// Stubs mínimos de WordPress que usa el render del email (solo si el bootstrap
// y otros tests todavía no los definieron).
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return (string) $s; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id = 0 ) { return false; }
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() { return 'https://site.test'; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ) { return 'https://site.test/wp-json/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( array $list, $field ) { return array_map( fn( $o ) => is_object( $o ) ? $o->$field : $o[ $field ], $list ); }
}

require_once __DIR__ . '/../../includes/core/class-frontend-url.php';
require_once __DIR__ . '/../../includes/emails/class-email-dispatcher.php';
require_once __DIR__ . '/../../includes/cart/class-cart-confirmation-email.php';

/**
 * Carrito mixto (ítem propio + ítem de proveedor externo) — revisión
 * 2026-10-08: el email del carrito tiene que listar TAMBIÉN el ítem pendiente
 * de confirmación, marcado como tal, con el total realmente cobrado.
 */
final class CartConfirmationEmailTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	private function item( array $over = [] ): object {
		return (object) array_merge( [
			'id'                => 1,
			'item_type'         => 'tour',
			'status'            => 'confirmed',
			'booking_ref'       => 'AB-2027-0001',
			'tour_date'         => '2027-06-01',
			'check_out_date'    => null,
			'total_mxn'         => 1500,
			'gateway_charge_id' => 'ch_1',
			'stripe_charge_id'  => 'ch_1',
			'tour_name_es'      => 'Snorkel en la bahía',
			'tour_name_en'      => 'Bay snorkeling',
			'room_name_es'      => null,
			'room_name_en'      => null,
			'customer_name'     => 'Ana',
			'lang'              => 'es',
			'access_token'      => 'tok123',
		], $over );
	}

	private function providerRoom( array $over = [] ): object {
		return $this->item( array_merge( [
			'id'             => 2,
			'item_type'      => 'room',
			'status'         => 'pending_provider_approval',
			'booking_ref'    => 'AB-2027-0002',
			'tour_date'      => '2027-06-01',
			'check_out_date' => '2027-06-03',
			'total_mxn'      => 4000,
			'tour_name_es'   => null,
			'tour_name_en'   => null,
			'room_name_es'   => 'Suite vista al mar',
			'room_name_en'   => 'Sea view suite',
		], $over ) );
	}

	private function mailer( array $items, string $lang = 'es' ): CartConfirmationEmail {
		foreach ( $items as $it ) {
			$it->lang = $lang;
		}
		$mailer = new CartConfirmationEmail( $items[0] );
		$ref    = new ReflectionClass( $mailer );
		foreach ( [ 'items' => $items, 'cart_group_id' => 'abcdef12-3456' ] as $prop => $value ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $mailer, $value );
		}
		return $mailer;
	}

	private function call( object $obj, string $method ): string {
		$m = new ReflectionMethod( $obj, $method );
		$m->setAccessible( true );
		return $m->invoke( $obj );
	}

	public function test_mixed_cart_lists_the_pending_item_flagged_with_the_total_charged(): void {
		$mailer = $this->mailer( [ $this->item(), $this->providerRoom() ] );
		$body   = $this->call( $mailer, 'get_body_content' );

		$this->assertStringContainsString( 'Snorkel en la bahía', $body );
		$this->assertStringContainsString( 'Suite vista al mar', $body, 'el ítem pendiente tiene que aparecer con su nombre' );
		$this->assertStringContainsString( '2027-06-01 → 2027-06-03', $body, 'y con sus fechas de estadía' );
		$this->assertSame( 1, substr_count( $body, 'background:#fff4d6' ), 'una sola marca de pendiente (la del ítem del proveedor)' );
		$this->assertStringContainsString( '¡Recibimos tu reserva!', $body );
		$this->assertStringNotContainsString( '¡Tu reserva está confirmada!', $body );
		$this->assertStringContainsString( Currency::format( 5500.0 ), $body, 'total = propio + pendiente ya cobrado' );
		$this->assertStringContainsString( '/pdf', $body, 'hay algo confirmado: se ofrece el voucher general' );
		$this->assertStringContainsString( 'Ver estado de mi reserva', $body );
	}

	public function test_subject_says_how_many_items_are_pending(): void {
		$subject = $this->call( $this->mailer( [ $this->item(), $this->providerRoom() ] ), 'get_subject' );
		$this->assertStringContainsString( 'falta confirmar 1 ítem', $subject );
		$this->assertStringContainsString( 'CART-ABCDEF12', $subject );
	}

	public function test_pending_item_in_on_approval_mode_is_not_counted_as_charged(): void {
		$deferred = $this->providerRoom( [ 'gateway_charge_id' => null, 'stripe_charge_id' => null ] );
		$body     = $this->call( $this->mailer( [ $this->item(), $deferred ] ), 'get_body_content' );

		$this->assertStringContainsString( Currency::format( 1500.0 ), $body, 'solo se cobró el ítem propio' );
		$this->assertStringNotContainsString( Currency::format( 5500.0 ), $body );
		$this->assertStringContainsString( 'no se cobra hasta que se confirme', $body );
	}

	public function test_cart_with_everything_pending_has_no_pdf_button_but_a_status_link(): void {
		$body = $this->call( $this->mailer( [ $this->providerRoom() ] ), 'get_body_content' );

		$this->assertStringNotContainsString( '/pdf', $body, 'el PDF general solo lista lo confirmado' );
		$this->assertStringContainsString( 'Ver estado de mi reserva', $body );
		$this->assertStringContainsString( 'Suite vista al mar', $body );
	}

	public function test_fully_confirmed_cart_keeps_the_original_wording(): void {
		$mailer = $this->mailer( [ $this->item() ] );
		$body   = $this->call( $mailer, 'get_body_content' );

		$this->assertStringContainsString( '¡Tu reserva está confirmada!', $body );
		$this->assertStringNotContainsString( 'background:#fff4d6', $body );
		$this->assertStringContainsString( '/pdf', $body );
		$this->assertStringStartsWith( '✅', $this->call( $mailer, 'get_subject' ) );
	}

	public function test_english_wording(): void {
		$mailer = $this->mailer( [ $this->item(), $this->providerRoom() ], 'en' );
		$body   = $this->call( $mailer, 'get_body_content' );

		$this->assertStringContainsString( 'Pending confirmation', $body );
		$this->assertStringContainsString( 'Sea view suite', $body );
		$this->assertStringContainsString( '1 item awaiting confirmation', $this->call( $mailer, 'get_subject' ) );
	}

	public function test_digital_download_link_only_appears_for_a_confirmed_owner_item(): void {
		$GLOBALS['wpdb']->addon_rows = [
			(object) [ 'id' => 10, 'name_snapshot' => 'Guía PDF', 'qty' => 1, 'total_mxn' => 100, 'pricing_type' => 'digital',
				'digital_file_url' => 'https://x.test/guia.pdf', 'access_token' => 'tokA', 'booking_status' => 'confirmed' ],
			(object) [ 'id' => 11, 'name_snapshot' => 'Mapa PDF', 'qty' => 1, 'total_mxn' => 100, 'pricing_type' => 'digital',
				'digital_file_url' => 'https://x.test/mapa.pdf', 'access_token' => 'tokB', 'booking_status' => 'pending_provider_approval' ],
		];
		$body = $this->call( $this->mailer( [ $this->item(), $this->providerRoom() ] ), 'get_body_content' );

		$this->assertStringContainsString( 'Guía PDF', $body );
		$this->assertStringContainsString( 'Mapa PDF', $body, 'el extra se lista aunque su dueño siga pendiente' );
		$this->assertSame( 1, substr_count( $body, 'Descargar archivo' ), 'pero solo el del ítem confirmado trae link de descarga' );
	}
}
