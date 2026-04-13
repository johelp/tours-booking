<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Internacionalización del plugin.
 * Las traducciones se cargan desde /languages/amir-booking-{locale}.mo
 */
class I18n {

    public function register(): void {
        // Ya se carga en plugins_loaded desde el entry point principal,
        // pero este hook asegura recarga si el locale cambia dinámicamente.
        add_action( 'init', [ $this, 'load_textdomain' ], 0 );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'amir-booking',
            false,
            dirname( plugin_basename( AMIR_PLUGIN_FILE ) ) . '/languages'
        );
    }
}
