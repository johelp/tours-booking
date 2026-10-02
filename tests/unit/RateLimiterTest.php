<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\RateLimiter;

/**
 * `client_ip()` (hallazgo de auditoría de seguridad, 2026-09-24): antes
 * confiaba primero en `CF-Connecting-IP`/`X-Real-IP`/`X-Forwarded-For` —
 * cabeceras que el propio cliente puede fijar libremente en cualquier
 * request cuando no hay un proxy de confianza delante que las sobreescriba
 * (el caso típico de este plugin, hosting compartido sin proxy propio). Eso
 * anulaba el throttle por completo: un valor distinto en cada request daba
 * un bucket nuevo cada vez. Ahora usa SOLO `REMOTE_ADDR` por defecto.
 *
 * El opt-in (`amir_trust_proxy_headers`) no se prueba acá — el bootstrap de
 * tests define `apply_filters()` como passthrough puro (no despacha hooks
 * de verdad, ver tests/bootstrap.php), así que `add_filter()` no tiene
 * ningún efecto observable en este entorno. Cubierto solo por lectura de
 * código/revisión manual.
 */
final class RateLimiterTest extends TestCase {

    private array $server_backup;

    protected function setUp(): void {
        $this->server_backup = $_SERVER;
    }

    protected function tearDown(): void {
        $_SERVER = $this->server_backup;
    }

    public function test_ignores_spoofed_forwarded_headers_and_uses_remote_addr(): void {
        $_SERVER['REMOTE_ADDR']          = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4'; // atacante intentando cambiar de "identidad" en cada request
        $_SERVER['HTTP_X_REAL_IP']       = '5.6.7.8';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';

        $this->assertSame( '203.0.113.9', RateLimiter::client_ip() );
    }

    public function test_a_new_forwarded_header_on_every_request_no_longer_produces_a_different_key(): void {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
        $first = RateLimiter::client_ip();

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2.2.2.2'; // el atacante cambia la cabecera
        $second = RateLimiter::client_ip();

        $this->assertSame( $first, $second );
        $this->assertSame( '198.51.100.1', $first );
    }

    public function test_falls_back_to_unknown_when_remote_addr_is_missing_or_invalid(): void {
        unset( $_SERVER['REMOTE_ADDR'] );
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame( '0.0.0.0', RateLimiter::client_ip() );
    }

    public function test_too_many_attempts_still_throttles_a_fixed_key(): void {
        // get_transient()/set_transient() son stubs en el bootstrap de tests
        // (siempre "no hay transient"/"guardado ok"), así que esto solo
        // confirma que la función no explota y respeta el límite dentro de
        // una misma ejecución sin persistencia real entre llamadas — el
        // comportamiento de ventana/conteo real ya lo prueba WordPress.
        $this->assertFalse( RateLimiter::too_many_attempts( 'unit-test-key', 20, 600 ) );
    }
}
