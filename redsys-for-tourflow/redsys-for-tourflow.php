<?php
/**
 * Plugin Name:  Redsys for TourFlow
 * Plugin URI:   https://amiradventours.com
 * Description:  Pasarela de pago Redsys (TPV Virtual, conexión por redirección) para TourFlow — plugin satélite, no toca el núcleo. Ver GUIA-PLUGINS-SATELITE-TOURFLOW.md.
 * Version:      0.1.0
 * Author:       TourFlow
 * Text Domain:  redsys-for-tourflow
 * Requires PHP: 8.1
 * Requires WP:  6.5
 * Requires Plugins: amir-booking
 */

namespace RedsysForTourFlow;

defined( 'ABSPATH' ) || exit;

define( 'RFTF_VERSION',     '0.1.0' );
define( 'RFTF_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );

require_once RFTF_PLUGIN_DIR . 'includes/class-rftf-signature.php';
require_once RFTF_PLUGIN_DIR . 'includes/class-rftf-currency.php';
require_once RFTF_PLUGIN_DIR . 'includes/class-rftf-settings.php';
require_once RFTF_PLUGIN_DIR . 'includes/class-rftf-gateway.php';
require_once RFTF_PLUGIN_DIR . 'includes/class-rftf-rest.php';

// 1. Resolver este gateway cuando el núcleo pida 'redsys'.
add_filter( 'amir_payment_gateway_resolve', function ( $gateway, string $id ) {
	if ( $id === 'redsys' ) {
		return new Gateway();
	}
	return $gateway;
}, 10, 2 );

// 2. Sumar la opción al dropdown de Configuración → Pasarela de pago activa.
add_filter( 'amir_payment_gateway_options', function ( array $options ): array {
	$options['redsys'] = 'Redsys';
	return $options;
} );

Settings::register_menu();
Rest::register_routes();
