<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes del plugin.
 *
 * [amir_booking tour_id="3"]
 * [amir_booking tour_id="3" lang="en"]
 *
 * [amir_tour_list]
 */
class Shortcodes {

    public static function booking_widget( array $atts ): string {
        $atts = shortcode_atts(
            [ 'tour_id' => 0, 'lang' => self::detect_lang() ],
            $atts,
            'amir_booking'
        );

        $tour_id = (int) $atts['tour_id'];
        $lang    = in_array( $atts['lang'], [ 'es', 'en' ], true ) ? $atts['lang'] : 'es';

        if ( $tour_id <= 0 ) {
            return '<p style="color:red">amir_booking: falta el parámetro tour_id</p>';
        }

        // Encolar assets solo cuando se usa el shortcode
        self::enqueue_widget_assets();

        // El div donde React monta el widget
        return sprintf(
            '<div data-amir-booking="1" data-tour-id="%d" data-lang="%s" id="amir-booking-%d"></div>',
            $tour_id,
            esc_attr( $lang ),
            $tour_id
        );
    }

    public static function tour_list( array $atts ): string {
        $atts = shortcode_atts(
            [ 'lang' => self::detect_lang(), 'columns' => 3 ],
            $atts,
            'amir_tour_list'
        );

        self::enqueue_widget_assets();

        return sprintf(
            '<div data-amir-tour-list="1" data-lang="%s" data-columns="%d" id="amir-tour-list"></div>',
            esc_attr( $atts['lang'] ),
            (int) $atts['columns']
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────

    private static function enqueue_widget_assets(): void {
        if ( wp_script_is( 'amir-booking-widget', 'enqueued' ) ) {
            return;
        }

        wp_enqueue_style(
            'amir-booking-widget',
            AMIR_PLUGIN_URL . 'assets/css/booking-widget.css',
            [],
            AMIR_VERSION
        );

        wp_enqueue_script(
            'amir-booking-widget',
            AMIR_PLUGIN_URL . 'assets/js/booking-widget.js',
            [],
            AMIR_VERSION,
            true  // en el footer
        );

        $mode   = get_option( 'amir_stripe_mode', 'test' );
        $pk_key = get_option( "amir_stripe_pk_{$mode}", '' );

        wp_localize_script( 'amir-booking-widget', 'amirBooking', [
            'apiUrl'   => rest_url( 'amir/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'stripePk' => $pk_key,
            'siteUrl'  => get_site_url(),
            'waPhone'  => get_option( 'amir_wa_phone', '5219831649541' ),
            'lang'     => self::detect_lang(),
        ] );
    }

    // ── Detectar idioma activo (compatible con Polylang / WPML) ──────────

    private static function detect_lang(): string {
        // Polylang
        if ( function_exists( 'pll_current_language' ) ) {
            $lang = pll_current_language( 'slug' );
            return in_array( $lang, [ 'es', 'en' ], true ) ? $lang : 'es';
        }
        // WPML
        if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
            $lang = ICL_LANGUAGE_CODE;
            return in_array( $lang, [ 'es', 'en' ], true ) ? $lang : 'es';
        }
        // Fallback: lang del sitio WP
        $locale = get_locale();
        return strncmp( $locale, 'en', 2 ) === 0 ? 'en' : 'es';
    }
}
