<?php
/**
 * Plugin Name:  Automations for TourFlow
 * Description:  Envía eventos de reservas (confirmada, cancelada, pendiente de proveedor, etc.) por webhook a Zapier, Make, n8n o cualquier URL que reciba POST. Firmado con HMAC, con reintentos y log. Plugin satélite — no toca el núcleo. Ver GUIA-AUTOMATIZACIONES-TOURFLOW.md.
 * Version:      0.1.0
 * Author:       TourFlow
 * Text Domain:  tourflow-automations
 * Requires PHP: 8.1
 * Requires WP:  6.5
 * Requires Plugins: amir-booking
 */

namespace TourFlowAutomations;

defined( 'ABSPATH' ) || exit;

define( 'TFA_VERSION',    '0.1.0' );
define( 'TFA_DB_VERSION', '1' );
define( 'TFA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once TFA_PLUGIN_DIR . 'includes/class-tfa-signer.php';
require_once TFA_PLUGIN_DIR . 'includes/class-tfa-payload.php';
require_once TFA_PLUGIN_DIR . 'includes/class-tfa-log.php';
require_once TFA_PLUGIN_DIR . 'includes/class-tfa-settings.php';
require_once TFA_PLUGIN_DIR . 'includes/class-tfa-dispatcher.php';

register_activation_hook( __FILE__, [ Log::class, 'install' ] );
register_activation_hook( __FILE__, [ Settings::class, 'ensure_secret' ] );

add_action( 'plugins_loaded', function (): void {
	// Migraciones del esquema propio (si cambia TFA_DB_VERSION). Activación ya
	// corre install(); esto cubre actualizaciones del ZIP sin reactivar.
	if ( get_option( 'tfa_db_version' ) !== TFA_DB_VERSION ) {
		Log::install();
	}
	Settings::ensure_secret();
	Settings::register_menu();
	Dispatcher::register();
} );
