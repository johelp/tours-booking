<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Idiomas activos del operador (Configuración → Idiomas).
 *
 * `es` es siempre el idioma base — es el título/editor nativo de WordPress
 * en el CPT de tours, y el msgid de los catálogos .po de este plugin (no
 * tiene .mo propio, es el idioma "fuente"). El resto de los idiomas activos
 * son agregados por el operador sin que un desarrollador toque código: cada
 * uno habilita una pestaña más en el editor de tours y, si existe el
 * .po/.mo correspondiente, traducciones reales en emails/voucher/widget.
 */
class Languages {

    private const DEFAULT = [ 'es', 'en' ];

    /**
     * Mapeo de código de 2 letras a locale completo de WordPress, para
     * poder cargar el .mo correcto (`languages/amir-booking-{locale}.mo`).
     * Códigos activos que no estén acá caen a "{code}_{CODE}" (ej. 'de' →
     * 'de_DE') — funciona para la mayoría de los idiomas, y si el .mo no
     * existe simplemente no hay traducción (fallback a español).
     */
    private const WP_LOCALES = [
        'es' => 'es_ES',
        'en' => 'en_US',
        'it' => 'it_IT',
        'fr' => 'fr_FR',
        'pt' => 'pt_PT',
    ];

    public static function wp_locale_for( string $code ): string {
        $code = strtolower( trim( $code ) );
        return self::WP_LOCALES[ $code ] ?? ( $code . '_' . strtoupper( $code ) );
    }

    /**
     * Ejecuta $fn con el locale de WordPress cambiado a $lang (vía
     * switch_to_locale()), para que los __()/_e() dentro devuelvan la
     * traducción de ESE idioma sin importar el locale real del sitio —
     * necesario porque emails/voucher/verificación traducen según el
     * idioma de la reserva, no según la configuración del sitio.
     * Si $lang es el idioma base (es) no hace falta cambiar nada, ya
     * que es el msgid de los catálogos .po (no tiene .mo propio).
     */
    public static function run_in( string $lang, callable $fn ) {
        $lang = strtolower( trim( $lang ) ) ?: self::default_lang();

        if ( $lang === self::default_lang() || ! function_exists( 'switch_to_locale' ) ) {
            return $fn();
        }

        switch_to_locale( self::wp_locale_for( $lang ) );
        try {
            return $fn();
        } finally {
            restore_previous_locale();
        }
    }

    /** Códigos de idioma activos, siempre con 'es' incluido primero. */
    public static function active(): array {
        $raw = get_option( 'amir_active_languages', self::DEFAULT );
        $codes = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?: self::DEFAULT );

        $codes = array_values( array_unique( array_filter(
            array_map( fn( $c ) => strtolower( trim( (string) $c ) ), $codes ),
            fn( $c ) => (bool) preg_match( '/^[a-z]{2}$/', $c )
        ) ) );

        if ( ! in_array( 'es', $codes, true ) ) {
            array_unshift( $codes, 'es' );
        }

        // Idiomas 3+ son Pro y superior (Pro Max incluido) — en Lite, sin
        // importar lo que haya guardado en la opción (ej. una instalación
        // que bajó de Pro a Lite), nunca se exponen más que es/en.
        if ( ! in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
            $codes = array_values( array_intersect( $codes, self::DEFAULT ) );
        }

        return $codes ?: self::DEFAULT;
    }

    public static function is_active( string $code ): bool {
        return in_array( strtolower( trim( $code ) ), self::active(), true );
    }

    /** Idioma base — nunca configurable, es el fallback de todo el sistema. */
    public static function default_lang(): string {
        return 'es';
    }

    /**
     * Valor de un campo traducible de un tour para un idioma dado.
     * Para 'es'/'en' lee las columnas dedicadas de siempre (name_es,
     * name_en, etc. — no se tocan, es el camino ya probado). Para
     * cualquier otro idioma activo, lee del JSON `content_i18n`.
     *
     * @param object $tour  Fila de amir_tours (o el post con los mismos meta keys).
     * @param string $field Nombre base del campo: name, description,
     *                      what_to_expect, meeting_point, includes, excludes, itinerary.
     * @param string $lang  Código de idioma.
     * @return string|array Cadena para la mayoría de los campos; array para includes/excludes.
     */
    public static function tour_field( object $tour, string $field, string $lang ) {
        $lang = strtolower( trim( $lang ) ) ?: self::default_lang();

        if ( in_array( $lang, self::DEFAULT, true ) ) {
            $col = "{$field}_{$lang}";
            return $tour->$col ?? '';
        }

        $i18n = $tour->content_i18n ?? '';
        $data = is_array( $i18n ) ? $i18n : ( json_decode( (string) $i18n, true ) ?: [] );

        return $data[ $lang ][ $field ] ?? ( $tour->{"{$field}_es"} ?? '' );
    }

    /**
     * Paradas del itinerario tipo timeline (§ 13.1 CONTRIBUTING.md),
     * resueltas al idioma pedido. A diferencia de tour_field(), cada parada
     * ya trae title_es/title_en/desc_es/desc_en en el mismo objeto JSON (no
     * hay tabla ni columna por idioma) — sin soporte para idiomas 3+ por
     * ahora, mismo alcance que tenía el itinerary_es/en de texto libre que
     * reemplaza (no es una regresión).
     *
     * @param object $tour Fila de amir_tours (o el post con los mismos meta keys) — usa ->itinerary_stops.
     * @param string $lang Código de idioma.
     * @return array Lista de ['title','desc','image_url','is_start'].
     */
    public static function itinerary_stops( object $tour, string $lang ): array {
        $lang = strtolower( trim( $lang ) ) ?: self::default_lang();
        $raw  = $tour->itinerary_stops ?? '';
        $stops = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?: [] );

        if ( ! is_array( $stops ) ) {
            return [];
        }

        return array_values( array_map( function ( $stop ) use ( $lang ) {
            $title = trim( (string) ( $stop["title_{$lang}"] ?? '' ) ) ?: (string) ( $stop['title_es'] ?? '' );
            $desc  = trim( (string) ( $stop["desc_{$lang}"]  ?? '' ) ) ?: (string) ( $stop['desc_es']  ?? '' );
            return [
                'title'     => $title,
                'desc'      => $desc,
                'image_url' => (string) ( $stop['image_url'] ?? '' ),
                'is_start'  => ! empty( $stop['is_start'] ),
            ];
        }, $stops ) );
    }

    /**
     * "Datos destacados" — bloques de ícono+título+detalle configurables por
     * tour (ej. 🗣️ Idioma: Español/Inglés). Mismo criterio de resolución que
     * itinerary_stops(): cada bloque ya trae label_es/label_en/value_es/value_en
     * en el mismo JSON, sin soporte de idiomas 3+ por ahora.
     *
     * @param object $tour Fila de amir_tours (o el post con los mismos meta keys) — usa ->detail_facts.
     * @param string $lang Código de idioma.
     * @return array Lista de ['icon','label','value'].
     */
    public static function detail_facts( object $tour, string $lang ): array {
        $lang  = strtolower( trim( $lang ) ) ?: self::default_lang();
        $raw   = $tour->detail_facts ?? '';
        $facts = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?: [] );

        if ( ! is_array( $facts ) ) {
            return [];
        }

        return array_values( array_map( function ( $fact ) use ( $lang ) {
            $label = trim( (string) ( $fact["label_{$lang}"] ?? '' ) ) ?: (string) ( $fact['label_es'] ?? '' );
            $value = trim( (string) ( $fact["value_{$lang}"] ?? '' ) ) ?: (string) ( $fact['value_es'] ?? '' );
            return [
                'icon'  => (string) ( $fact['icon'] ?? '' ),
                'label' => $label,
                'value' => $value,
            ];
        }, $facts ) );
    }

    /**
     * FAQ opcional por tour (pedido del cliente 2026-08-25, a partir del
     * bloque "Quick Questions" de la landing de Sicilia Mia — "por tour",
     * no global). Mismo criterio de resolución que itinerary_stops()/
     * detail_facts(): cada fila ya trae question_es/en + answer_es/en en
     * el mismo JSON, sin soporte de idiomas 3+ por ahora.
     *
     * @param object $tour Fila de amir_tours (o el post con los mismos meta keys) — usa ->faq_items.
     * @param string $lang Código de idioma.
     * @return array Lista de ['question','answer'].
     */
    public static function faq_items( object $tour, string $lang ): array {
        $lang  = strtolower( trim( $lang ) ) ?: self::default_lang();
        $raw   = $tour->faq_items ?? '';
        $items = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?: [] );

        if ( ! is_array( $items ) ) {
            return [];
        }

        return array_values( array_map( function ( $item ) use ( $lang ) {
            $question = trim( (string) ( $item["question_{$lang}"] ?? '' ) ) ?: (string) ( $item['question_es'] ?? '' );
            $answer   = trim( (string) ( $item["answer_{$lang}"]   ?? '' ) ) ?: (string) ( $item['answer_es']   ?? '' );
            return [
                'question' => $question,
                'answer'   => $answer,
            ];
        }, array_filter( $items, function ( $item ) {
            return trim( (string) ( $item['question_es'] ?? '' ) ) !== '' || trim( (string) ( $item['question_en'] ?? '' ) ) !== '';
        } ) ) );
    }

    /**
     * Categorías de tour (§ 15.7 CONTRIBUTING.md) — a diferencia de
     * itinerary_stops()/detail_facts(), el contenido bilingüe NO vive en el
     * JSON de amir_tours.category_slugs (que solo guarda los slugs) sino en
     * la taxonomía de WordPress: el nombre en español es el propio nombre
     * del término, y el inglés es un termmeta aparte (ver
     * TourPostType::save_category_en_name()) — WordPress no separa idiomas
     * de forma nativa como el resto del contenido del plugin.
     *
     * @param object $tour Fila de amir_tours — usa ->category_slugs.
     * @return array Lista de ['slug','name_es','name_en'].
     */
    public static function categories( object $tour ): array {
        $raw   = $tour->category_slugs ?? '';
        $slugs = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?: [] );

        if ( ! is_array( $slugs ) || empty( $slugs ) ) {
            return [];
        }

        $taxonomy = \AmirBooking\CPT\TourPostType::TAXONOMY;
        $out = [];
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', (string) $slug, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $name_en = get_term_meta( $term->term_id, 'amir_category_name_en', true );
            $out[] = [
                'slug'    => $term->slug,
                'name_es' => $term->name,
                'name_en' => $name_en ?: $term->name,
            ];
        }
        return $out;
    }
}
