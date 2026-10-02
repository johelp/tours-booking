<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Throttling simple basado en transients de WordPress.
 * No sustituye un rate limiter a nivel de infraestructura (Cloudflare, Nginx,
 * etc.), pero frena la fuerza bruta trivial contra endpoints públicos que
 * autorizan por email/token (verificación de reserva, cancelación, PDF) y
 * contra el login del panel de gestión (/gestor/).
 */
class RateLimiter {

    /**
     * Registra un intento para $key y devuelve true si se superó el límite.
     * Ventana fija de $window segundos, máximo $max intentos.
     */
    public static function too_many_attempts( string $key, int $max = 20, int $window = 600 ): bool {
        $transient_key = 'amir_rl_' . md5( $key );
        $count         = (int) get_transient( $transient_key );

        if ( $count >= $max ) {
            return true;
        }

        set_transient( $transient_key, $count + 1, $window );
        return false;
    }

    /**
     * IP del cliente.
     *
     * Por defecto usa SOLO `REMOTE_ADDR` (hallazgo de auditoría de
     * seguridad, 2026-09-24). Antes confiaba primero en `CF-Connecting-IP`/
     * `X-Real-IP`/`X-Forwarded-For` — cabeceras que el propio cliente puede
     * fijar libremente en cualquier request cuando no hay un proxy de
     * confianza delante que las sobreescriba antes de llegar a PHP (el caso
     * típico de este plugin: hosting compartido sin un proxy propio). Eso
     * anulaba por completo el throttle: bastaba mandar un valor distinto en
     * cada request para obtener un bucket nuevo cada vez, incluido el freno
     * de fuerza bruta contra el login de /gestor/ y contra iterar
     * `booking_ref` con el credential débil de `authorize_public_access()`.
     *
     * Un sitio que sí está detrás de un proxy/CDN de confianza real (ej.
     * Cloudflare, que sobreescribe estas cabeceras en el origen antes de que
     * lleguen a PHP) puede optar por confiar en ellas de nuevo enganchando
     * `add_filter( 'amir_trust_proxy_headers', '__return_true' )` — nunca
     * default-on, porque desde acá no hay forma de saber si el proxy es
     * real o si el visitante le habla directo al servidor de WordPress.
     */
    public static function client_ip(): string {
        if ( apply_filters( 'amir_trust_proxy_headers', false ) ) {
            foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ] as $header ) {
                if ( ! empty( $_SERVER[ $header ] ) ) {
                    // X-Forwarded-For puede traer una lista "cliente, proxy1, proxy2"
                    $first = trim( explode( ',', $_SERVER[ $header ] )[0] );
                    if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
                        return $first;
                    }
                }
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
    }
}
