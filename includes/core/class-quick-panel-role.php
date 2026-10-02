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
}
