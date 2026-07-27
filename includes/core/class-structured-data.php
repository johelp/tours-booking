<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Arma el bloque JSON-LD (schema.org) de un tour a partir de los mismos
 * datos estructurados que ya tenemos en amir_tours/amir_prices (precio,
 * duración, imágenes, ubicación) — no hace falta cargar nada nuevo, solo
 * mapear lo que el template de single-amir_tour.php ya calcula.
 *
 * @type TouristTrip (más específico que TouristAttraction para algo
 * reservable, ver https://schema.org/TouristTrip) — lo leen tanto los rich
 * results de Google como los motores de búsqueda con IA (ChatGPT,
 * Perplexity, Google AI Overview), que priorizan datos estructurados sobre
 * texto libre.
 *
 * Función pura a propósito (sin llamadas a $wpdb ni funciones de WP): el
 * template resuelve todos los datos y se los pasa ya armados, así se puede
 * testear con PHPUnit sin bootstrap especial.
 */
class StructuredData {

    /**
     * @param array{
     *   name: string, description: string, url: string, images?: string[],
     *   duration_minutes?: int, min_age?: int, languages?: string[],
     *   lat?: float|string, lng?: float|string, meeting_point?: string,
     *   price_from?: float, currency?: string, sold_out?: bool,
     *   provider_name?: string, provider_url?: string
     * } $tour
     */
    public static function tour_schema( array $tour ): array {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'TouristTrip',
            'name'        => $tour['name'] ?? '',
            'description' => $tour['description'] ?? '',
            'url'         => $tour['url'] ?? '',
            'touristType' => 'Adventure',
        ];

        $images = array_values( array_filter( (array) ( $tour['images'] ?? [] ) ) );
        if ( $images ) {
            $schema['image'] = $images;
        }

        if ( ! empty( $tour['duration_minutes'] ) ) {
            $schema['duration'] = self::iso8601_duration( (int) $tour['duration_minutes'] );
        }

        $languages = array_values( array_filter( (array) ( $tour['languages'] ?? [] ) ) );
        if ( $languages ) {
            $schema['inLanguage'] = $languages;
        }

        if ( ! empty( $tour['min_age'] ) ) {
            $schema['typicalAgeRange'] = $tour['min_age'] . '-';
        }

        if ( ! empty( $tour['lat'] ) && ! empty( $tour['lng'] ) ) {
            $place = [
                '@type' => 'Place',
                'geo'   => [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => (float) $tour['lat'],
                    'longitude' => (float) $tour['lng'],
                ],
            ];
            if ( ! empty( $tour['meeting_point'] ) ) {
                $place['name'] = $tour['meeting_point'];
            }
            $schema['itinerary'] = $place;
        }

        if ( ! empty( $tour['provider_name'] ) ) {
            $provider = [ '@type' => 'TravelAgency', 'name' => $tour['provider_name'] ];
            if ( ! empty( $tour['provider_url'] ) ) {
                $provider['url'] = $tour['provider_url'];
            }
            $schema['provider'] = $provider;
        }

        if ( ! empty( $tour['price_from'] ) && (float) $tour['price_from'] > 0 ) {
            $schema['offers'] = [
                '@type'         => 'Offer',
                'price'         => (float) $tour['price_from'],
                'priceCurrency' => $tour['currency'] ?? 'USD',
                'availability'  => ! empty( $tour['sold_out'] )
                    ? 'https://schema.org/SoldOut'
                    : 'https://schema.org/InStock',
                'url'           => $tour['url'] ?? '',
            ];
        }

        return $schema;
    }

    /**
     * Minutos → duración ISO 8601 (ej. 150 → "PT2H30M"), formato que exige
     * schema.org para la propiedad `duration`.
     */
    private static function iso8601_duration( int $minutes ): string {
        $hours    = intdiv( $minutes, 60 );
        $remain   = $minutes % 60;
        $duration = 'PT';
        if ( $hours > 0 ) {
            $duration .= $hours . 'H';
        }
        if ( $remain > 0 || $hours === 0 ) {
            $duration .= $remain . 'M';
        }
        return $duration;
    }
}
