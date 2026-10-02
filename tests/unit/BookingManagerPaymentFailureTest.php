<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\BookingManager;

/**
 * Flujo de un pago rechazado (auditoría 2026-09-28):
 *  - record_payment_failure(): un intento fallido NO cancela la reserva; abre
 *    un plazo de gracia (payment_failed_at) salvo salida del mismo día.
 *  - confirm() sobre una reserva 'payment_expired' (pago que llega tarde):
 *    se recupera si el cupo sigue libre, se reembolsa si no.
 */
final class BookingManagerPaymentFailureTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']                = new FakeWpdb();
        $GLOBALS['__amir_test_options'] = [];
    }

    private function booking( array $o = [] ): object {
        return (object) array_merge( [
            'id'                      => 1,
            'booking_ref'             => 'BK-2026-00001',
            'status'                  => 'pending',
            'item_type'               => 'tour',
            'tour_id'                 => 10,
            'schedule_id'             => 3,
            'room_id'                 => null,
            'partner_id'              => null,
            'total_mxn'               => 1000.0,
            'deposit_pct'             => 0,
            'tour_date'               => date( 'Y-m-d', strtotime( '+10 days' ) ),
            'check_out_date'          => null,
            'customer_name'           => 'Ana Pérez',
            'customer_email'          => 'ana@example.com',
            'adults'                  => 2,
            'children'                => 0,
            'babies'                  => 0,
            'coupon_code'             => '',
            'payment_gateway'         => 'stripe',
            'stripe_charge_id'        => '',
            'gateway_charge_id'       => '',
            'provider_responded_at'   => null,
            'internal_notes'          => '',
        ], $o );
    }

    private function sql(): string {
        return implode( "\n", $GLOBALS['wpdb']->queries );
    }

    // ── record_payment_failure() ────────────────────────────────────────────

    public function test_a_failed_attempt_never_cancels_the_booking(): void {
        $GLOBALS['wpdb']->booking_row = $this->booking();

        ( new BookingManager() )->record_payment_failure( 1, 'card_declined' );

        $this->assertStringNotContainsString( 'cancelled_client', $this->sql() );
        $this->assertSame( [], $GLOBALS['wpdb']->updates ); // ni un update() de estado
    }

    public function test_first_failure_opens_the_grace_period(): void {
        $GLOBALS['wpdb']->booking_row = $this->booking();

        ( new BookingManager() )->record_payment_failure( 1, 'card_declined' );

        $this->assertStringContainsString( 'internal_notes', $this->sql() );
        $this->assertStringContainsString( 'payment_failed_at IS NULL', $this->sql() );
    }

    public function test_no_grace_period_when_disabled(): void {
        update_option( 'amir_payment_grace_mins', 0 );
        $GLOBALS['wpdb']->booking_row = $this->booking();

        ( new BookingManager() )->record_payment_failure( 1, 'card_declined' );

        $this->assertStringContainsString( 'internal_notes', $this->sql() ); // igual queda anotado el intento
        $this->assertStringNotContainsString( 'payment_failed_at', $this->sql() );
    }

    public function test_no_grace_period_for_a_same_day_departure(): void {
        $GLOBALS['wpdb']->booking_row = $this->booking( [ 'tour_date' => date( 'Y-m-d' ) ] );

        ( new BookingManager() )->record_payment_failure( 1, 'card_declined' );

        $this->assertStringNotContainsString( 'payment_failed_at', $this->sql() );
    }

    public function test_ignores_a_booking_that_is_not_pending(): void {
        $GLOBALS['wpdb']->booking_row = $this->booking( [ 'status' => 'confirmed' ] );

        ( new BookingManager() )->record_payment_failure( 1, 'card_declined' );

        $this->assertSame( [], $GLOBALS['wpdb']->queries );
    }

    // ── confirm() sobre una reserva vencida (pago tardío) ───────────────────

    public function test_late_payment_recovers_the_booking_when_the_spot_is_still_free(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking( [ 'status' => 'payment_expired' ] );
        $fake->tour_row    = (object) [ 'max_capacity' => 8, 'price_model' => 'per_person', 'provider_id' => 0 ];
        $fake->var_result  = 0; // ningún otro cliente ocupa el cupo, sin proveedor

        $ok = ( new BookingManager() )->confirm( 1, 'ch_late' );

        $this->assertTrue( $ok );
        $statuses = array_column( array_column( $fake->updates, 'data' ), 'status' );
        $this->assertContains( 'pending', $statuses );   // recuperada
        $this->assertContains( 'confirmed', $statuses ); // y confirmada
        $this->assertNotContains( 'cancelled_client', $statuses );
    }

    public function test_late_payment_is_refunded_when_the_spot_is_gone(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking( [ 'status' => 'payment_expired' ] );
        $fake->tour_row    = (object) [ 'max_capacity' => 8, 'price_model' => 'per_person', 'provider_id' => 0 ];
        $fake->var_result_by_query = [ 'id != %d' => 8 ]; // otros clientes ya llenaron el cupo

        $ok = ( new BookingManager() )->confirm( 1, 'ch_late' );

        $this->assertFalse( $ok );
        $refund = null;
        foreach ( $fake->updates as $u ) {
            if ( ( $u['data']['status'] ?? '' ) === 'cancelled_client' ) {
                $refund = $u['data'];
            }
        }
        $this->assertNotNull( $refund );
        $this->assertSame( 1000.0, $refund['refund_amount_mxn'] ); // devuelve todo lo cobrado
        $this->assertSame( 'ch_late', $refund['gateway_charge_id'] ); // referencia para poder reembolsar
    }

    public function test_late_payment_refund_uses_only_the_deposit_when_the_tour_has_one(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking( [ 'status' => 'payment_expired', 'deposit_pct' => 20 ] );
        $fake->tour_row    = (object) [ 'max_capacity' => 8, 'price_model' => 'per_person', 'provider_id' => 0 ];
        $fake->var_result_by_query = [ 'id != %d' => 8 ];

        ( new BookingManager() )->confirm( 1, 'ch_late' );

        $refund = null;
        foreach ( $fake->updates as $u ) {
            if ( ( $u['data']['status'] ?? '' ) === 'cancelled_client' ) {
                $refund = $u['data'];
            }
        }
        $this->assertSame( 200.0, $refund['refund_amount_mxn'] ); // 20% de 1000: lo que de verdad se cobró
    }

    public function test_late_payment_does_not_double_process_when_another_call_already_recovered_it(): void {
        $fake                            = $GLOBALS['wpdb'];
        $fake->booking_row               = $this->booking( [ 'status' => 'payment_expired' ] );
        $fake->tour_row                  = (object) [ 'max_capacity' => 8, 'price_model' => 'per_person', 'provider_id' => 0 ];
        $fake->var_result                = 0;
        $fake->next_update_rows_affected = 0; // otra llamada ganó el reclamo

        $ok = ( new BookingManager() )->confirm( 1, 'ch_late' );

        $this->assertFalse( $ok );
        $statuses = array_column( array_column( $fake->updates, 'data' ), 'status' );
        $this->assertNotContains( 'confirmed', $statuses );
    }

    public function test_late_payment_on_an_exclusive_group_tour_needs_the_slot_completely_free(): void {
        $fake              = $GLOBALS['wpdb'];
        $fake->booking_row = $this->booking( [ 'status' => 'payment_expired' ] );
        $fake->tour_row    = (object) [ 'max_capacity' => 8, 'price_model' => 'group', 'provider_id' => 0 ];
        $fake->var_result_by_query = [ 'id != %d' => 3 ]; // otro grupo ya reservó ese horario

        $this->assertFalse( ( new BookingManager() )->confirm( 1, 'ch_late' ) );
    }
}
