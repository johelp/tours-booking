<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\PricingEngine;

final class PricingEngineTest extends TestCase {

    protected function setUp(): void {
        // Modo manual y fijo para que convert_to_usd() sea determinístico
        // y no intente golpear la API de tipo de cambio (wp_remote_get
        // está deshabilitado a propósito en tests/bootstrap.php).
        update_option( 'amir_usd_rate_mode', 'manual' );
        update_option( 'amir_usd_rate_manual', 17.0 );

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_percapita_model_sums_adults_children_and_ignores_zero_babies(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 1, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
            (object) [ 'person_type' => 'child', 'group_min' => null, 'group_max' => null, 'price_mxn' => 250.00 ],
            (object) [ 'person_type' => 'baby',  'group_min' => null, 'group_max' => null, 'price_mxn' => 0.00 ],
        ];

        $engine = new PricingEngine();
        $quote  = $engine->quote( tour_id: 1, schedule_id: 0, date: '2026-08-01', adults: 2, children: 1, babies: 1 );

        $this->assertTrue( $quote->is_valid() );
        $this->assertSame( 'percapita', $quote->model );
        $this->assertEqualsWithDelta( 1250.0, $quote->total_mxn, 0.001 );
        // 1250 / 17 = 73.529... -> redondeado a 73.53
        $this->assertEqualsWithDelta( 73.53, $quote->usd_reference, 0.01 );
        $this->assertCount( 2, $quote->breakdown ); // baby no suma porque price_mxn es 0
    }

    public function test_percapita_model_fails_without_configured_adult_price(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 1, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows = [];

        $engine = new PricingEngine();
        $quote  = $engine->quote( tour_id: 1, schedule_id: 0, date: '2026-08-01', adults: 1 );

        $this->assertFalse( $quote->is_valid() );
        $this->assertNotSame( '', $quote->error );
    }

    public function test_group_model_matches_the_range_that_covers_total_pax(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 2, 'price_model' => 'group' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'group', 'group_min' => 1, 'group_max' => 2, 'price_mxn' => 3000.00 ],
            (object) [ 'person_type' => 'group', 'group_min' => 3, 'group_max' => 3, 'price_mxn' => 4000.00 ],
        ];

        $engine = new PricingEngine();
        $quote  = $engine->quote( tour_id: 2, schedule_id: 0, date: '2026-08-01', adults: 2, children: 0, babies: 0 );

        $this->assertTrue( $quote->is_valid() );
        $this->assertSame( 'group', $quote->model );
        $this->assertEqualsWithDelta( 3000.0, $quote->total_mxn, 0.001 );
    }

    public function test_group_model_fails_when_pax_count_has_no_matching_range(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 2, 'price_model' => 'group' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'group', 'group_min' => 1, 'group_max' => 2, 'price_mxn' => 3000.00 ],
        ];

        $engine = new PricingEngine();
        $quote  = $engine->quote( tour_id: 2, schedule_id: 0, date: '2026-08-01', adults: 5, children: 0, babies: 0 );

        $this->assertFalse( $quote->is_valid() );
    }
}
