<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Throttling simple basado en transients de WordPress.
 * No sustituye un rate limiter a nivel de infraestructura (Cloudflare, Nginx,
 * etc.), pero frena la fuerza bruta trivial contra endpoints públicos que
 * autorizan por email/token (verificación de reserva, cancelación, PDF).
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
     * IP del cliente, considerando cabeceras de proxy/CDN comunes.
     * No es infalible (las cabeceras se pueden falsificar si no hay un
     * proxy de confianza delante), pero es suficiente para este throttle
     * de baja exigencia.
     */
    public static function client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $value = $_SERVER[ $header ];
                // X-Forwarded-For puede traer una lista "cliente, proxy1, proxy2"
                $first = trim( explode( ',', $value )[0] );
                if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
                    return $first;
                }
            }
        }
        return '0.0.0.0';
    }
}
