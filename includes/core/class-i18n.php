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

        // switch_to_locale()/restore_previous_locale() (usado por
        // Languages::run_in() para traducir emails/voucher al idioma de
        // la reserva, distinto del locale del sitio) dispara 'change_locale'
        // — hay que recargar el .mo de este textdomain para el locale nuevo,
        // WordPress no lo hace solo para textdomains de plugins.
        add_action( 'change_locale', [ $this, 'load_textdomain' ] );
    }

    public function load_textdomain(): void {
        // unload primero: load_plugin_textdomain() no vuelve a leer el .mo
        // si WP ya considera el textdomain "cargado" para el locale anterior.
        unload_textdomain( 'amir-booking' );
        load_plugin_textdomain(
            'amir-booking',
            false,
            dirname( plugin_basename( AMIR_PLUGIN_FILE ) ) . '/languages'
        );
    }
}
