<?php
/**
 * Plugin Name:  Custom Emails for TourFlow
 * Description:  Reemplazá la plantilla COMPLETA del email de confirmación de reserva por tu propio HTML, con variables tipo {{customer_name}}. Plugin satélite — no toca el núcleo (usa el filtro amir_email_render_html). Ver GUIA-PLUGINS-SATELITE-TOURFLOW.md.
 * Version:      0.1.0
 * Author:       TourFlow
 * Text Domain:  tourflow-custom-emails
 * Requires PHP: 8.1
 * Requires WP:  6.5
 * Requires Plugins: amir-booking
 */

namespace TourFlowCustomEmails;

defined( 'ABSPATH' ) || exit;

define( 'TFCE_VERSION',    '0.1.0' );
define( 'TFCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once TFCE_PLUGIN_DIR . 'includes/class-tfce-vars.php';
require_once TFCE_PLUGIN_DIR . 'includes/class-tfce-renderer.php';
require_once TFCE_PLUGIN_DIR . 'includes/class-tfce-settings.php';
require_once TFCE_PLUGIN_DIR . 'includes/class-tfce-template.php';

add_action( 'plugins_loaded', function (): void {
	// class_exists de cortesía — si alguna vez se instala sin el núcleo
	// activo, este satélite queda inerte en vez de fatalear (mismo criterio
	// que el resto de los satélites de este repo).
	if ( ! class_exists( '\AmirBooking\Core\Plugin' ) ) {
		return;
	}
	Settings::register_menu();
	Template::register();
} );
