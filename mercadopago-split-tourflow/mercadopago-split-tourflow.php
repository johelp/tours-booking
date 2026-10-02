<?php
/**
 * Plugin Name:  Mercado Pago Split for TourFlow
 * Plugin URI:   https://amiradventours.com
 * Description:  Split payment 1:1 de Mercado Pago para proveedores externos del marketplace de TourFlow — plugin satélite, no toca el núcleo. Opcional por proveedor: sin cuenta conectada, sigue el flujo manual de siempre. Ver PROMPT-MERCADOPAGO-SPLIT.md del núcleo.
 * Version:      0.1.0
 * Author:       TourFlow
 * Text Domain:  mercadopago-split-tourflow
 * Requires PHP: 8.1
 * Requires WP:  6.5
 * Requires Plugins: amir-booking
 */

namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

define( 'MPSTF_VERSION',    '0.1.0' );
define( 'MPSTF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once MPSTF_PLUGIN_DIR . 'includes/class-mpstf-installer.php';
require_once MPSTF_PLUGIN_DIR . 'includes/class-mpstf-provider-accounts.php';
require_once MPSTF_PLUGIN_DIR . 'includes/class-mpstf-oauth.php';
require_once MPSTF_PLUGIN_DIR . 'includes/class-mpstf-settings.php';
require_once MPSTF_PLUGIN_DIR . 'includes/class-mpstf-providers-page.php';
require_once MPSTF_PLUGIN_DIR . 'includes/class-mpstf-gateway.php';
require_once MPSTF_PLUGIN_DIR . 'includes/class-mpstf-rest.php';

// dbDelta es idempotente — chequeo barato guardado por versión, mismo
// criterio de auto-sanación que Installer::ensure_booking_status_enum() del
// núcleo (evita depender solo del hook de activación en Multisite).
add_action( 'admin_init', [ Installer::class, 'maybe_install' ] );
register_activation_hook( __FILE__, [ Installer::class, 'create_tables' ] );

// 1. Resolver este gateway cuando el núcleo pida 'mercadopago_split'.
add_filter( 'amir_payment_gateway_resolve', function ( $gateway, string $id ) {
	if ( $id === 'mercadopago_split' ) {
		return new Gateway();
	}
	return $gateway;
}, 10, 2 );

// 2. Sumar la opción al dropdown de Configuración → Pasarela de pago activa.
add_filter( 'amir_payment_gateway_options', function ( array $options ): array {
	$options['mercadopago_split'] = 'Mercado Pago (con split para proveedores)';
	return $options;
} );

Settings::register_menu();
Rest::register_routes();
