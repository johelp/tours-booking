<?php
/**
 * Plugin Name:  TourFlow
 * Plugin URI:   https://amiradventours.com
 * Description:  Sistema de reservas y gestión de tours para operadoras. Sin WooCommerce. Multisite ready.
 * Version:      5.10.0
 * Author:       TourFlow
 * Text Domain:  amir-booking
 * Domain Path:  /languages
 * Requires PHP: 8.1
 * Requires WP:  6.0
 * Network:      true
 */

defined( 'ABSPATH' ) || exit;

// ── Constantes ────────────────────────────────────────────────────────────────
define( 'AMIR_VERSION',     '5.10.0' );
define( 'AMIR_PLUGIN_FILE', __FILE__ );
define( 'AMIR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'AMIR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'AMIR_DB_VERSION',  '1.37.0' );

// 'pro' (default, este repo), 'lite' o 'pro_max' — el build de empaquetado de
// cada edición pisa este valor y excluye del ZIP los archivos exclusivos de
// la(s) edición(es) superior(es). Pro Max hereda todo lo de Pro (gates que
// usan in_array( AMIR_EDITION, ['pro','pro_max'] )) y suma sus propios
// archivos aparte (gates AMIR_EDITION === 'pro_max', ej. el módulo de
// habitaciones, CONTRIBUTING.md § 16). Nunca se lee esto como verificación
// de licencia — es solo "qué build es este ZIP", ver CONTRIBUTING.md § 14.2.
if ( ! defined( 'AMIR_EDITION' ) ) {
    define( 'AMIR_EDITION', 'pro' );
}

// ── Composer autoloader (TCPDF, endroid/qr-code, etc.) ───────────────────────
if ( file_exists( AMIR_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once AMIR_PLUGIN_DIR . 'vendor/autoload.php';
}

// ── Autoloader ────────────────────────────────────────────────────────────────
// 'AmirBooking\' es el namespace histórico (código existente, sin tocar).
// 'TourFlow\' es el namespace nuevo para todo lo que se construya de acá en
// adelante (ej. TourFlow\Rooms\ — habitaciones, Pro Max) — decisión del
// cliente (2026-07-31) de no seguir usando "amir" en desarrollo nuevo, ver
// CONTRIBUTING.md § 15.13. Ambos resuelven contra el mismo directorio
// includes/ — solo cambia el namespace raíz que se le quita a la clase.
spl_autoload_register( function ( string $class ): void {
    if ( strncmp( $class, 'AmirBooking\\', 12 ) === 0 ) {
        $relative = str_replace( 'AmirBooking\\', '', $class );
    } elseif ( strncmp( $class, 'TourFlow\\', 9 ) === 0 ) {
        $relative = str_replace( 'TourFlow\\', '', $class );
    } else {
        return;
    }

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
// $network_wide = true cuando se activa desde Network Admin para toda la red.
register_activation_hook( __FILE__, function ( $network_wide ) {
    AmirBooking\Core\Installer::activate( (bool) $network_wide );
} );

register_deactivation_hook( __FILE__, function ( $network_wide ) {
    AmirBooking\Core\Installer::deactivate( (bool) $network_wide );
} );

register_uninstall_hook( __FILE__, array( 'AmirBooking\\Core\\Installer', 'uninstall' ) );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
    load_plugin_textdomain( 'amir-booking', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    AmirBooking\Core\Plugin::instance()->init();
} );
