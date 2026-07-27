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
}
