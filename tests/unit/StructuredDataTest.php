<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\StructuredData;

final class StructuredDataTest extends TestCase {

    public function test_minimal_tour_has_core_fields_and_no_optional_ones(): void {
        $schema = StructuredData::tour_schema( [
            'name'        => 'Tour en Kayak',
            'description' => 'Un recorrido por la laguna',
            'url'         => 'https://example.com/tour/kayak/',
        ] );

        $this->assertSame( 'https://schema.org', $schema['@context'] );
        $this->assertSame( 'TouristTrip', $schema['@type'] );
        $this->assertSame( 'Tour en Kayak', $schema['name'] );
        $this->assertArrayNotHasKey( 'image', $schema );
        $this->assertArrayNotHasKey( 'offers', $schema );
        $this->assertArrayNotHasKey( 'itinerary', $schema );
    }

    public function test_full_tour_includes_offer_image_duration_and_geo(): void {
        $schema = StructuredData::tour_schema( [
            'name'             => 'Tour en Kayak',
            'description'      => 'Un recorrido por la laguna',
            'url'              => 'https://example.com/tour/kayak/',
            'images'           => [ 'https://example.com/a.jpg', '', 'https://example.com/b.jpg' ],
            'duration_minutes' => 150,
            'min_age'          => 8,
            'languages'        => [ 'Español', 'English' ],
            'lat'              => 18.6849,
            'lng'              => -87.9789,
            'meeting_point'    => 'Muelle central',
            'price_from'       => 950.0,
            'currency'         => 'MXN',
            'provider_name'    => 'TourFlow',
            'provider_url'     => 'https://example.com',
        ] );

        $this->assertSame( [ 'https://example.com/a.jpg', 'https://example.com/b.jpg' ], $schema['image'] );
        $this->assertSame( 'PT2H30M', $schema['duration'] );
        $this->assertSame( '8-', $schema['typicalAgeRange'] );
        $this->assertSame( [ 'Español', 'English' ], $schema['inLanguage'] );
        $this->assertSame( 18.6849, $schema['itinerary']['geo']['latitude'] );
        $this->assertSame( 'Muelle central', $schema['itinerary']['name'] );
        $this->assertSame( 'TravelAgency', $schema['provider']['@type'] );

        $this->assertSame( 950.0, $schema['offers']['price'] );
        $this->assertSame( 'MXN', $schema['offers']['priceCurrency'] );
        $this->assertSame( 'https://schema.org/InStock', $schema['offers']['availability'] );
    }

    public function test_sold_out_offer_uses_soldout_availability(): void {
        $schema = StructuredData::tour_schema( [
            'name'       => 'Tour lleno',
            'url'        => 'https://example.com/tour/lleno/',
            'price_from' => 500,
            'sold_out'   => true,
        ] );

        $this->assertSame( 'https://schema.org/SoldOut', $schema['offers']['availability'] );
    }

    /** @dataProvider durationProvider */
    public function test_duration_formatting( int $minutes, string $expected ): void {
        $schema = StructuredData::tour_schema( [
            'name' => 'x', 'url' => 'x', 'duration_minutes' => $minutes,
        ] );
        $this->assertSame( $expected, $schema['duration'] );
    }

    public static function durationProvider(): array {
        return [
            'exact hours'         => [ 120, 'PT2H' ],
            'hours and minutes'   => [ 150, 'PT2H30M' ],
            'minutes only'        => [ 45, 'PT45M' ],
        ];
    }

    public function test_zero_duration_omits_duration_key(): void {
        $schema = StructuredData::tour_schema( [
            'name' => 'x', 'url' => 'x', 'duration_minutes' => 0,
        ] );
        $this->assertArrayNotHasKey( 'duration', $schema );
    }

    public function test_zero_price_omits_offer(): void {
        $schema = StructuredData::tour_schema( [
            'name' => 'Gratis', 'url' => 'x', 'price_from' => 0,
        ] );
        $this->assertArrayNotHasKey( 'offers', $schema );
    }
}
