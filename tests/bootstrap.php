<?php
/**
 * Bootstrap de tests unitarios.
 *
 * Esto NO es un entorno de integración de WordPress (no hay wp-phpunit ni
 * una base de datos real). Es deliberadamente liviano: define solo las
 * funciones/constantes de WordPress que las clases de dominio bajo test
 * (BookingManager, PricingEngine, AvailabilityEngine) necesitan para
 * cargar y ejecutar su lógica de negocio pura.
 *
 * Si una clase nueva necesita otra función de WP, agregarla acá — no
 * instalar un mock framework completo para esto todavía.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

// ── Opciones de WordPress (en memoria, configurables desde los tests) ──────

$GLOBALS['__amir_test_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default = false ) {
        return $GLOBALS['__amir_test_options'][ $key ] ?? $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $key, $value ): bool {
        $GLOBALS['__amir_test_options'][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'add_option' ) ) {
    function add_option( string $key, $value ): bool {
        $GLOBALS['__amir_test_options'][ $key ] ??= $value;
        return true;
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ) {
        return false;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, $value, int $expiration ): bool {
        return true;
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $key ): bool {
        return true;
    }
}

// ── Fecha/hora ──────────────────────────────────────────────────────────────

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ) {
        if ( $type === 'timestamp' ) {
            return time();
        }
        if ( $type === 'mysql' ) {
            return date( 'Y-m-d H:i:s' );
        }
        return date( $type );
    }
}

// ── HTTP (no se usan en los tests actuales, solo evitan fatales) ──────────

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = [] ) {
        return new WP_Error( 'no_http_in_tests', 'HTTP deshabilitado en tests unitarios' );
    }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( string $url, array $args = [] ) {
        return new WP_Error( 'no_http_in_tests', 'HTTP deshabilitado en tests unitarios' );
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ): string {
        return '';
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
    }
}

// ── Sanitización (no validan de verdad — solo dejan pasar el valor) ───────

foreach ( [ 'sanitize_text_field', 'sanitize_textarea_field', 'sanitize_key', 'sanitize_email', 'esc_html', 'esc_attr', 'esc_url' ] as $fn ) {
    if ( ! function_exists( $fn ) ) {
        eval( "function {$fn}( \$value ) { return is_string( \$value ) ? trim( \$value ) : \$value; }" );
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( string $email ) {
        return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

// ── i18n (sin gettext real: devuelven el msgid tal cual) ──────────────────
foreach ( [ '__', 'esc_html__', 'esc_attr__' ] as $fn ) {
    if ( ! function_exists( $fn ) ) {
        eval( "function {$fn}( \$text, \$domain = 'default' ) { return \$text; }" );
    }
}
foreach ( [ '_e', 'esc_html_e' ] as $fn ) {
    if ( ! function_exists( $fn ) ) {
        eval( "function {$fn}( \$text, \$domain = 'default' ) { echo \$text; }" );
    }
}

require_once __DIR__ . '/fakes/FakeWpdb.php';

// ── Clases de dominio bajo test (requeridas directamente, sin el bootstrap
//    completo del plugin — no necesitamos registrar hooks de WP para
//    probar lógica de negocio pura) ─────────────────────────────────────────
require_once __DIR__ . '/../includes/core/class-currency.php';
require_once __DIR__ . '/../includes/core/class-languages.php';
require_once __DIR__ . '/../includes/core/class-structured-data.php';
require_once __DIR__ . '/../includes/core/class-availability-engine.php';
require_once __DIR__ . '/../includes/core/class-pricing-engine.php';
require_once __DIR__ . '/../includes/core/class-booking-manager.php';
require_once __DIR__ . '/../includes/payments/class-payment-gateway-interface.php';
require_once __DIR__ . '/../includes/payments/class-payment-creation-result.php';
require_once __DIR__ . '/../includes/payments/class-payment-event.php';
require_once __DIR__ . '/../includes/payments/class-payment-status-result.php';
require_once __DIR__ . '/../includes/payments/class-mercado-pago-gateway.php';
