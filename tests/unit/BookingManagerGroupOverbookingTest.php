<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\BookingManager;

/**
 * Tours `price_model='group'` (ej. charter/tour privado exclusivo) — UNA
 * sola reserva pending/confirmed agota el horario completo, sin importar
 * cuántas personas tenga (AvailabilityEngine::check_group_capacity()).
 *
 * Hallazgo de auditoría de seguridad, 2026-09-24: el re-chequeo transaccional
 * de create_pending() (dentro del `FOR UPDATE`) no volvía a traer
 * `price_model` y siempre aplicaba la fórmula per-cápita pensada para tours
 * normales — dos requests casi simultáneas por el mismo tour "privado"
 * podían pasar el chequeo inicial las dos (ninguna ve todavía la reserva de
 * la otra) y terminar reservando el mismo horario exclusivo dos veces.
 */
final class BookingManagerGroupOverbookingTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    private function groupTour( array $overrides = [] ): object {
        return (object) array_merge( [
            'id'                   => 2,
            'custom_quote'         => 0,
            'price_model'          => 'group',
            'max_capacity'         => 8,
            'min_passengers'       => 1,
            'status'               => 'active',
            'fixed_date'           => null,
            'provider_id'          => 0,
            'provider_charge_mode' => 'immediate',
            'request_only'         => 0,
            'deposit_enabled'      => 0,
            'deposit_pct'          => 0,
        ], $overrides );
    }

    private function validData( array $overrides = [] ): array {
        return array_merge( [
            'tour_id'         => 2,
            'schedule_id'     => 5,
            'date'            => date( 'Y-m-d', strtotime( '+10 days' ) ),
            'adults'          => 4,
            'children'        => 0,
            'babies'          => 0,
            'customer_name'   => 'Ana Pérez',
            'customer_email'  => 'ana@example.com',
            'policy_accepted' => true,
            'terms_accepted'  => true,
        ], $overrides );
    }

    /**
     * Simula la carrera: el chequeo inicial (fuera de la transacción) ve el
     * horario libre (`COUNT(*)` de check_group_capacity() = 0), pero para
     * cuando esta request toma el lock `FOR UPDATE`, otro grupo ya insertó su
     * reserva — el re-chequeo transaccional (`SUM(adults + children +
     * babies)`) ahora sí lo ve. Antes del fix, esa SUM se comparaba contra
     * max_capacity (per-cápita) y 4 &lt;= 8 dejaba pasar una segunda reserva
     * del mismo tour "privado".
     */
    public function test_rejects_a_second_concurrent_booking_of_an_exclusive_group_tour(): void {
        $fake                      = $GLOBALS['wpdb'];
        $fake->tour_row            = $this->groupTour();
        $fake->price_rows          = [
            (object) [ 'person_type' => 'group', 'group_min' => 1, 'group_max' => 8, 'price_mxn' => 5000.00 ],
        ];
        $fake->var_result_by_query = [
            'custom_quote'                    => 0,
            'min_passengers'                  => 1,
            'require_participant_names'       => 0,
            'COUNT(*)'                        => 0, // chequeo inicial: sin reservas todavía
            'SUM(adults + children + babies)' => 4, // re-chequeo transaccional: otro grupo se adelantó
        ];

        $result = ( new BookingManager() )->create_pending( $this->validData() );

        $this->assertFalse( $result->success );
        $this->assertStringContainsString( 'tour privado', $result->error );
        $this->assertContains( 'ROLLBACK', $fake->queries );
        $this->assertSame( [], $fake->inserts ); // nunca debe llegar a insertar la reserva
    }

    /** Caso normal (sin carrera): el horario sigue libre en ambos chequeos. */
    public function test_allows_the_booking_when_the_exclusive_slot_is_still_free(): void {
        $fake                      = $GLOBALS['wpdb'];
        $fake->tour_row            = $this->groupTour();
        $fake->price_rows          = [
            (object) [ 'person_type' => 'group', 'group_min' => 1, 'group_max' => 8, 'price_mxn' => 5000.00 ],
        ];
        $fake->var_result_by_query = [
            'custom_quote'                    => 0,
            'min_passengers'                  => 1,
            'require_participant_names'       => 0,
            'COUNT(*)'                        => 0,
            'SUM(adults + children + babies)' => 0, // nadie más reservó
        ];

        $result = ( new BookingManager() )->create_pending( $this->validData() );

        $this->assertTrue( $result->success );
        $this->assertNotEmpty( $fake->insert_into( 'amir_bookings' ) );
    }
}
