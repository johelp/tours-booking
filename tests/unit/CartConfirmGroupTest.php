<?php
use PHPUnit\Framework\TestCase;
use TourFlow\Cart\CartController;

require_once __DIR__ . '/../../includes/cart/class-cart-controller.php';

/**
 * CartController::confirm_group() — el webhook de la pasarela y el POST
 * /confirm-payment del cliente son caminos redundantes a propósito; los dos
 * confirman el carrito completo y no deben pisarse (revisión 2026-10-08).
 */
final class CartConfirmGroupTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	private function row( int $id, string $status ): object {
		return (object) [ 'id' => $id, 'status' => $status ];
	}

	/** Fila completa de amir_bookings que lee confirm() — mismo molde que BookingManagerProviderApprovalTest. */
	private function booking(): object {
		return (object) [
			'id' => 1, 'booking_ref' => 'BK-2026-00001', 'status' => 'pending', 'tour_id' => 10, 'schedule_id' => 3,
			'partner_id' => null, 'total_mxn' => 1000.0, 'tour_date' => date( 'Y-m-d', strtotime( '+10 days' ) ),
			'booking_source' => 'direct', 'customer_name' => 'Ana', 'customer_email' => 'ana@example.com',
			'adults' => 2, 'children' => 0, 'babies' => 0, 'stripe_charge_id' => '', 'gateway_charge_id' => '',
			'provider_response_token' => null, 'provider_responded_at' => null, 'provider_reject_reason' => null,
			'internal_notes' => '',
		];
	}

	public function test_confirms_only_the_pending_bookings_of_the_cart(): void {
		$fake                = $GLOBALS['wpdb'];
		$fake->booking_rows  = [ $this->row( 1, 'pending' ), $this->row( 2, 'confirmed' ), $this->row( 3, 'payment_expired' ) ];
		$fake->booking_row   = $this->booking();
		$fake->var_result    = 0; // sin proveedor: confirm() termina en 'confirmed'

		$transitioned = CartController::confirm_group( 'cart-uuid', 'ch_123' );

		$this->assertSame( 2, $transitioned );

		$touched = array_values( array_unique( array_map( fn( $u ) => $u['where']['id'], $fake->updates ) ) );
		sort( $touched );
		$this->assertSame( [ 1, 3 ], $touched, 'la reserva 2 (ya confirmada) no se vuelve a tocar' );
	}

	public function test_second_call_is_a_no_op_once_everything_is_confirmed(): void {
		$fake               = $GLOBALS['wpdb'];
		$fake->booking_rows = [ $this->row( 1, 'confirmed' ), $this->row( 2, 'pending_provider_approval' ) ];

		$this->assertSame( 0, CartController::confirm_group( 'cart-uuid', 'ch_123' ) );
		$this->assertSame( [], $fake->updates, 'el otro camino (webhook o POST) no debe reprocesar nada' );
	}

	public function test_unknown_cart_returns_zero(): void {
		$GLOBALS['wpdb']->booking_rows = [];
		$this->assertSame( 0, CartController::confirm_group( 'no-existe', 'ch_123' ) );
	}
}
