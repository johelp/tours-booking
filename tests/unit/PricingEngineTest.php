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

    // ── Add-ons ──────────────────────────────────────────────────────────

    public function test_per_unit_addon_is_capped_to_people_in_the_booking(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 1, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
        ];
        $GLOBALS['wpdb']->addon_rows = [
            (object) [ 'id' => 10, 'pricing_type' => 'per_unit', 'name_es' => 'Snorkel', 'name_en' => 'Snorkel', 'content_i18n' => '{}', 'price_mxn' => 200.00 ],
        ];

        $engine = new PricingEngine();
        // 2 adultos, 0 niños -> tope de "por unidad" = 2, aunque se pidan 5
        $quote = $engine->quote(
            tour_id: 1, schedule_id: 0, date: '2026-08-01', adults: 2, children: 0, babies: 0,
            coupon_code: '', addons: [ [ 'id' => 10, 'qty' => 5 ] ]
        );

        $this->assertTrue( $quote->is_valid() );
        // 1000 (2 adultos x 500) + 400 (2 x 200, capado de 5 a 2) = 1400
        $this->assertEqualsWithDelta( 1400.0, $quote->total_mxn, 0.001 );
        $this->assertEqualsWithDelta( 400.0, $quote->addons_mxn, 0.001 );

        $addon_item = array_values( array_filter( $quote->breakdown, fn( $b ) => $b['type'] === 'addon' ) )[0];
        $this->assertSame( 2, $addon_item['qty'] ); // capado, no los 5 pedidos
    }

    public function test_flat_addon_never_multiplies_by_quantity(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 1, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 500.00 ],
        ];
        $GLOBALS['wpdb']->addon_rows = [
            (object) [ 'id' => 11, 'pricing_type' => 'flat', 'name_es' => 'Extra general', 'name_en' => 'General extra', 'content_i18n' => '{}', 'price_mxn' => 150.00 ],
        ];

        $engine = new PricingEngine();
        // pide qty=4 de un addon "flat" -> debe cobrarse una sola vez
        $quote = $engine->quote(
            tour_id: 1, schedule_id: 0, date: '2026-08-01', adults: 2, children: 0, babies: 0,
            coupon_code: '', addons: [ [ 'id' => 11, 'qty' => 4 ] ]
        );

        $this->assertTrue( $quote->is_valid() );
        // 1000 (2 adultos x 500) + 150 (flat, una sola vez) = 1150
        $this->assertEqualsWithDelta( 1150.0, $quote->total_mxn, 0.001 );

        $addon_item = array_values( array_filter( $quote->breakdown, fn( $b ) => $b['type'] === 'addon' ) )[0];
        $this->assertSame( 1, $addon_item['qty'] );
    }

    public function test_addon_total_is_unaffected_by_percentage_coupon_on_tour_price(): void {
        $GLOBALS['wpdb']->tour_row   = (object) [ 'id' => 1, 'price_model' => 'percapita' ];
        $GLOBALS['wpdb']->price_rows = [
            (object) [ 'person_type' => 'adult', 'group_min' => null, 'group_max' => null, 'price_mxn' => 1000.00 ],
        ];
        $GLOBALS['wpdb']->addon_rows = [
            (object) [ 'id' => 12, 'pricing_type' => 'flat', 'name_es' => 'Cena', 'name_en' => 'Dinner', 'content_i18n' => '{}', 'price_mxn' => 300.00 ],
        ];

        // Sin cupón: 1000 (tour) + 300 (addon) = 1300
        $engine    = new PricingEngine();
        $no_coupon = $engine->quote(
            tour_id: 1, schedule_id: 0, date: '2026-08-01', adults: 1, children: 0, babies: 0,
            coupon_code: '', addons: [ [ 'id' => 12, 'qty' => 1 ] ]
        );
        $this->assertEqualsWithDelta( 1300.0, $no_coupon->total_mxn, 0.001 );
        $this->assertEqualsWithDelta( 300.0, $no_coupon->addons_mxn, 0.001 );
    }
}
