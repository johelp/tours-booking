<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\Languages;

final class LanguagesTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__amir_test_options'] = [];
    }

    public function test_active_defaults_to_es_en_when_option_missing(): void {
        $this->assertSame( [ 'es', 'en' ], Languages::active() );
    }

    public function test_active_always_includes_es_first(): void {
        update_option( 'amir_active_languages', wp_json_encode( [ 'it', 'fr' ] ) );
        $this->assertSame( [ 'es', 'it', 'fr' ], Languages::active() );
    }

    public function test_active_filters_invalid_codes(): void {
        update_option( 'amir_active_languages', wp_json_encode( [ 'es', 'en', 'xxx', '1', '', 'IT' ] ) );
        // 'IT' se normaliza a minúscula, 'xxx'/'1'/'' se descartan por no ser 2 letras.
        $this->assertSame( [ 'es', 'en', 'it' ], Languages::active() );
    }

    public function test_is_active(): void {
        update_option( 'amir_active_languages', wp_json_encode( [ 'es', 'en', 'it' ] ) );
        $this->assertTrue( Languages::is_active( 'it' ) );
        $this->assertTrue( Languages::is_active( 'EN' ) );
        $this->assertFalse( Languages::is_active( 'fr' ) );
    }

    public function test_tour_field_reads_dedicated_columns_for_es_en(): void {
        $tour = (object) [ 'name_es' => 'Tour en Bacalar', 'name_en' => 'Bacalar Tour', 'content_i18n' => '{}' ];
        $this->assertSame( 'Tour en Bacalar', Languages::tour_field( $tour, 'name', 'es' ) );
        $this->assertSame( 'Bacalar Tour', Languages::tour_field( $tour, 'name', 'en' ) );
    }

    public function test_tour_field_reads_json_for_other_languages(): void {
        $tour = (object) [
            'name_es'      => 'Tour en Bacalar',
            'content_i18n' => wp_json_encode( [ 'it' => [ 'name' => 'Tour a Bacalar' ] ] ),
        ];
        $this->assertSame( 'Tour a Bacalar', Languages::tour_field( $tour, 'name', 'it' ) );
    }

    public function test_tour_field_falls_back_to_spanish_when_translation_missing(): void {
        $tour = (object) [ 'name_es' => 'Tour en Bacalar', 'content_i18n' => '{}' ];
        $this->assertSame( 'Tour en Bacalar', Languages::tour_field( $tour, 'name', 'it' ) );
    }

    public function test_tour_field_accepts_array_content_i18n(): void {
        $tour = (object) [
            'name_es'      => 'Tour en Bacalar',
            'content_i18n' => [ 'fr' => [ 'name' => 'Tour à Bacalar' ] ],
        ];
        $this->assertSame( 'Tour à Bacalar', Languages::tour_field( $tour, 'name', 'fr' ) );
    }
}
