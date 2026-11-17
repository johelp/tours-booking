<?php
namespace TourFlow\Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Login para el panel de gestión sin wp-admin (ManagerPanel) — patrón
 * confirmado con el cliente contra su otro producto (EventFlow,
 * `Organizer_Auth`): sigue usando `wp_authenticate()` contra usuarios reales
 * de WordPress (reusa hashing de contraseña, "olvidé mi contraseña", etc.
 * nativos de WP — no hace falta un sistema de cuentas propio) pero emite un
 * token HMAC propio guardado en una cookie SEPARADA de la de wp-admin, así
 * el gestor nunca ve wp-admin ni queda logueado ahí.
 *
 * A diferencia de EventFlow (multi-tenant, un "organizador" es una cuenta
 * externa distinta de la del equipo de la plataforma): TourFlow es de un
 * solo operador por instalación, así que un Administrador también puede
 * loguearse acá sin restricción — no hace falta distinguir "admin de
 * plataforma" de "usuario externo".
 */
class ManagerAuth {

	const COOKIE_NAME = 'tourflow_manager_session';
	const TTL_HOURS    = 24;

	/**
	 * ¿Puede este usuario de WP usar el panel? Mismo criterio que
	 * AdminMenu/TourManagerRole para el gestor completo, más los dos roles
	 * chicos del "Panel rápido" (`AmirBooking\Core\QuickPanelRole`,
	 * 2026-09-15) — ManagerPanel::is_quick_only() decide después si el
	 * usuario ve el panel completo o queda encerrado en esa única sección.
	 */
	public static function user_can_access( \WP_User $user ): bool {
		return $user->has_cap( 'manage_options' ) || $user->has_cap( 'manage_amir_booking' )
			|| $user->has_cap( \AmirBooking\Core\QuickPanelRole::CAP_VIEW );
	}

	/**
	 * Puente entre el login NORMAL de WordPress (wp-login.php) y la sesión
	 * propia del panel. Bug real reportado por el cliente, 2026-11-16: un
	 * Tour Manager o un rol del Panel rápido que inicia sesión por el login
	 * estándar de WP nunca recibía la cookie propia del panel (solo se
	 * emitía desde el formulario de /gestor/login, al que nadie llega sin
	 * que alguien le pase el link a mano) — así que `/gestor/` siempre los
	 * mandaba de nuevo a loguearse ahí, o quedaban varados en un wp-admin
	 * sin ningún menú útil (ver TourManagerRole::redirect_after_login() y
	 * QuickPanelRole::register_hooks(), los dos puntos que llaman a esto).
	 *
	 * Deliberadamente excluye a administradores reales (`manage_options`):
	 * ellos siguen entrando a wp-admin normal, como siempre — forzarlos
	 * también al panel en cada login sería una regresión para quien
	 * necesita Configuración/Personalización/plugins, no solo operar el
	 * día a día.
	 *
	 * @return string|null URL del panel a la que redirigir, o null si este
	 *         usuario no debe puentearse (admin real, o sin ninguna
	 *         capability del panel) — quien llama debe seguir con su
	 *         redirect normal en ese caso.
	 */
	public static function bridge_from_wp_login( \WP_User $user ): ?string {
		if ( $user->has_cap( 'manage_options' ) || ! self::user_can_access( $user ) ) {
			return null;
		}

		self::set_cookie( self::issue_token( (int) $user->ID ) );

		return \TourFlow\Panel\ManagerPanel::url( 'dashboard' );
	}

	/** Devuelve el token a guardar en cookie, o null si las credenciales/capability no son válidas. */
	public static function login( string $username, string $password ): ?string {
		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) || ! self::user_can_access( $user ) ) {
			return null;
		}

		return self::issue_token( (int) $user->ID );
	}

	public static function issue_token( int $user_id ): string {
		$payload = [
			'user_id'    => $user_id,
			'expires_at' => time() + ( self::TTL_HOURS * HOUR_IN_SECONDS ),
		];

		$encoded   = base64_encode( wp_json_encode( $payload ) );
		$signature = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) . 'tourflow_manager_panel' );

		return $encoded . '.' . $signature;
	}

	public static function verify_token( string $token ): ?int {
		$parts = explode( '.', $token, 2 );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		[ $encoded, $signature ] = $parts;

		$expected = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) . 'tourflow_manager_panel' );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$payload = json_decode( base64_decode( $encoded ), true );
		if ( ! is_array( $payload ) || empty( $payload['expires_at'] ) || time() > $payload['expires_at'] ) {
			return null;
		}

		// El usuario pudo haber perdido la capability desde que se emitió el
		// token (cambio de rol) — no confiar solo en que el token sea válido.
		$user = get_userdata( (int) $payload['user_id'] );
		if ( ! $user || ! self::user_can_access( $user ) ) {
			return null;
		}

		return (int) $payload['user_id'];
	}

	/** user_id de la sesión vigente en la cookie de esta request, o null. */
	public static function current_user_id(): ?int {
		$token = $_COOKIE[ self::COOKIE_NAME ] ?? '';
		return $token ? self::verify_token( $token ) : null;
	}

	public static function set_cookie( string $token ): void {
		setcookie(
			self::COOKIE_NAME,
			$token,
			[
				'expires'  => time() + ( self::TTL_HOURS * HOUR_IN_SECONDS ),
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);
	}

	public static function clear_cookie(): void {
		setcookie( self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, '/' );
	}
}
