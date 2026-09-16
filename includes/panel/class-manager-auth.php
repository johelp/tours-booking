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
