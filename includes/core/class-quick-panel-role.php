<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Dos roles nuevos, chicos, para el "Panel rápido" (`includes/panel/class-quick-section.php`)
 * — pedido del cliente (2026-09-15): personal de campo/anfitriones que
 * necesita ver quién llega y cuándo (participantes, fechas de actividad,
 * estado de la reserva, si hay saldo pendiente o no) SIN ver montos,
 * precios ni pagos — ese personal nunca debería tener que abrir wp-admin ni
 * el panel de gestión completo (`ManagerAuth::user_can_access()`, que ya
 * cubre `manage_amir_booking`/`manage_options`).
 *
 * Decisión de diseño (delegada por el cliente — "o si es más fácil, creá dos
 * niveles diferentes"): en vez de un solo rol + un toggle de configuración
 * para activar/desactivar el cobro, son DOS roles — más simple de auditar
 * ("¿quién puede cobrar?" = "¿quién tiene amir_quick_cashier?") y no
 * necesita ninguna pantalla nueva de configuración. `amir_quick_cashier`
 * incluye TODAS las capacidades de `amir_quick_staff` más la de cobrar, así
 * que promover a alguien de un nivel a otro es simplemente cambiarle el rol
 * — no hay una tercera combinación posible ni necesaria.
 *
 * Ninguno de los dos entra a wp-admin — a propósito, sin capacidades de post
 * ni `manage_amir_booking`: `ManagerAuth::user_can_access()` los deja entrar
 * SOLO a `/gestor/`, y ahí `ManagerPanel` los redirige siempre a la sección
 * "Panel rápido", ocultando el resto del menú (ver `is_quick_only()`).
 */
class QuickPanelRole {

	public const STAFF_SLUG   = 'amir_quick_staff';
	public const CASHIER_SLUG = 'amir_quick_cashier';

	/** Capability de acceso al panel rápido — la validan ManagerAuth y QuickSection. */
	public const CAP_VIEW     = 'view_amir_quick_panel';
	/** Capability de más — ver montos/saldo pendiente en $ y poder marcarlo cobrado. */
	public const CAP_PAYMENTS = 'manage_amir_quick_payments';

	/** Idempotente — se llama en cada carga del plugin (ver Plugin::init()), no solo en activate(), para que instalaciones ya activas reciban los roles sin necesitar desactivar/reactivar. */
	public static function register(): void {
		if ( ! get_role( self::STAFF_SLUG ) ) {
			add_role( self::STAFF_SLUG, __( 'TourFlow — Panel rápido', 'amir-booking' ), [
				'read'          => true,
				self::CAP_VIEW  => true,
			] );
		}
		if ( ! get_role( self::CASHIER_SLUG ) ) {
			add_role( self::CASHIER_SLUG, __( 'TourFlow — Panel rápido + cobros', 'amir-booking' ), [
				'read'              => true,
				self::CAP_VIEW      => true,
				self::CAP_PAYMENTS  => true,
			] );
		}
	}

	public static function remove(): void {
		remove_role( self::STAFF_SLUG );
		remove_role( self::CASHIER_SLUG );
	}

	/**
	 * Bug real reportado por el cliente (2026-11-16, book.caliafarm.com en
	 * vivo, `?tourflow_manager_page=rapido`): estos dos roles nunca tuvieron
	 * NINGÚN hook de login — a diferencia de TourManagerRole, que al menos
	 * redirigía a un wp-admin curado. Sin `manage_amir_booking`/`edit_posts`,
	 * un login normal de WP los dejaba en `profile.php` sin ningún menú útil,
	 * y si después pegaban el link del panel a mano, `ManagerPanel` los
	 * rebotaba al login del panel (sin cookie propia — ver
	 * `ManagerAuth::bridge_from_wp_login()`, el mismo puente que ya usa
	 * TourManagerRole). El docblock de esta clase ya decía que el diseño
	 * original era "entra directo a /gestor/" — nunca se construyó el
	 * mecanismo para llegar ahí desde el login estándar.
	 */
	public static function register_hooks(): void {
		add_filter( 'login_redirect', [ __CLASS__, 'redirect_after_login' ], 10, 3 );
	}

	public static function redirect_after_login( string $redirect_to, string $requested, $user ): string {
		if ( is_wp_error( $user ) || ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}
		if ( ! $user->has_cap( self::CAP_VIEW ) || $user->has_cap( 'manage_amir_booking' ) || $user->has_cap( 'manage_options' ) ) {
			// Lo segundo evita pisar a alguien con un rol más amplio (ej. un
			// Administrador al que también se le sumó este rol por error) —
			// esos ya tienen su propio redirect (TourManagerRole o ninguno).
			return $redirect_to;
		}

		if ( class_exists( '\TourFlow\Panel\ManagerAuth' ) ) {
			$bridged = \TourFlow\Panel\ManagerAuth::bridge_from_wp_login( $user );
			if ( $bridged ) {
				return $bridged;
			}
		}

		return $redirect_to;
	}
}
