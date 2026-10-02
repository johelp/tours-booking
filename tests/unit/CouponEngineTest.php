<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\CouponEngine;

/**
 * `mark_used()` (hallazgo de auditoría de seguridad, 2026-09-24): antes era
 * un `UPDATE times_used = times_used + 1` incondicional, llamado DESPUÉS del
 * COMMIT de la reserva — dos requests concurrentes con el mismo cupón de un
 * solo uso pasaban `validate()` las dos (el SELECT de arriba, hecho antes de
 * que cualquiera incrementara el contador) y terminaban ambas confirmadas,
 * agotando un `usage_limit=1` N veces. Ahora el `UPDATE` re-chequea
 * `usage_limit` en el propio WHERE y devuelve si afectó una fila — el caller
 * (BookingManager::create_pending()/RoomBookingManager::create_pending())
 * debe hacer ROLLBACK de toda la reserva si devuelve false.
 */
final class CouponEngineTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_mark_used_succeeds_when_limit_not_reached(): void {
        $GLOBALS['wpdb']->coupon_mark_used_rows_affected = 1;

        $ok = ( new CouponEngine() )->mark_used( 7 );

        $this->assertTrue( $ok );
    }

    public function test_mark_used_fails_when_a_concurrent_request_already_exhausted_the_limit(): void {
        $GLOBALS['wpdb']->coupon_mark_used_rows_affected = 0;

        $ok = ( new CouponEngine() )->mark_used( 7 );

        $this->assertFalse( $ok );
    }

    public function test_mark_used_query_is_conditioned_on_usage_limit_in_the_where_clause(): void {
        ( new CouponEngine() )->mark_used( 7 );

        $sql = $GLOBALS['wpdb']->queries[0] ?? '';
        $this->assertStringContainsString( 'times_used = times_used + 1', $sql );
        $this->assertStringContainsString( 'usage_limit', $sql );
        $this->assertStringContainsString( 'WHERE', $sql );
    }

    // ── validate(): sin cambios de comportamiento, cubre el chequeo previo ──

    public function test_validate_rejects_a_coupon_that_already_reached_its_usage_limit(): void {
        $GLOBALS['wpdb']->coupon_row = (object) [
            'code'         => 'PROMO1',
            'active'       => 1,
            'valid_from'   => null,
            'valid_until'  => null,
            'usage_limit'  => 1,
            'times_used'   => 1,
            'tour_id'      => null,
            'room_id'      => null,
        ];

        $result = ( new CouponEngine() )->validate( 'PROMO1', 10 );

        $this->assertFalse( $result->valid );
        $this->assertStringContainsString( 'límite', $result->error );
    }

    public function test_validate_accepts_a_coupon_still_within_its_usage_limit(): void {
        $GLOBALS['wpdb']->coupon_row = (object) [
            'code'         => 'PROMO1',
            'active'       => 1,
            'valid_from'   => null,
            'valid_until'  => null,
            'usage_limit'  => 5,
            'times_used'   => 4,
            'tour_id'      => null,
            'room_id'      => null,
        ];

        $result = ( new CouponEngine() )->validate( 'PROMO1', 10 );

        $this->assertTrue( $result->valid );
    }
}
