<?php
/**
 * Plugin Name:  Amir Booking
 * Plugin URI:   https://amiradventours.com
 * Description:  Sistema de reservas y gestión de tours para Amir Adventours Bacalar. Sin WooCommerce.
 * Version:      1.0.23
 * Author:       Amir Adventours
 * Text Domain:  amir-booking
 * Domain Path:  /languages
 * Requires PHP: 7.4
 * Requires WP:  6.0
 */

defined( 'ABSPATH' ) || exit;

// ── Constantes ────────────────────────────────────────────────────────────────
define( 'AMIR_VERSION',     '1.0.23' );
define( 'AMIR_PLUGIN_FILE', __FILE__ );
define( 'AMIR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'AMIR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'AMIR_DB_VERSION',  '1.1.0' );

// ── Composer autoloader (TCPDF, endroid/qr-code, etc.) ───────────────────────
if ( file_exists( AMIR_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once AMIR_PLUGIN_DIR . 'vendor/autoload.php';
}

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
    if ( strncmp( $class, 'AmirBooking\\', 12 ) !== 0 ) {
        return;
    }

    $relative   = str_replace( 'AmirBooking\\', '', $class );
    $parts      = explode( '\\', $relative );
    $class_name = array_pop( $parts );

    // CamelCase → kebab-case: TourManagerRole → tour-manager-role
    $kebab     = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) );
    $file_name = 'class-' . $kebab . '.php';
    $sub_path  = strtolower( implode( DIRECTORY_SEPARATOR, $parts ) );
    $full_path = AMIR_PLUGIN_DIR . 'includes/' . ( $sub_path ? $sub_path . '/' : '' ) . $file_name;

    if ( file_exists( $full_path ) ) {
        require_once $full_path;
    }
} );

// ── Activación ────────────────────────────────────────────────────────────────
register_activation_hook(   __FILE__, [ 'AmirBooking\\Core\\Installer', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'AmirBooking\\Core\\Installer', 'deactivate' ] );
register_uninstall_hook(    __FILE__, [ 'AmirBooking\\Core\\Installer', 'uninstall'  ] );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
    load_plugin_textdomain( 'amir-booking', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    AmirBooking\Core\Plugin::instance()->init();
} );
