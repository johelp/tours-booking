<?php
namespace TourFlow\Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Panel de gestión sin wp-admin — fase 1 (Dashboard, Reservas, Calendario,
 * Disponibilidad, Partners, Liquidación de partners, Modo Campo). Ver
 * PROMPT-PANEL-GESTOR.md para el diseño completo (fase 2: Tours — el único
 * refactor real de fondo que queda —, Emails, Log de pagos, este último
 * excluido a propósito por decisión del cliente). Partners es de cara al
 * OPERADOR (ver/dar de alta partners, generar sus links/QR, liquidar
 * comisiones) — nunca un login para que un partner externo entre por su
 * cuenta, eso no se pidió.
 *
 * Patrón confirmado con el cliente contra su otro producto EventFlow
 * (`Organizer_Panel`): página propia en el frontend público de WP (fuera de
 * /wp-admin/), auth por cookie propia (ManagerAuth, nunca la de wp-admin),
 * y — la parte que evita duplicar toda la lógica de negocio de las
 * pantallas admin existentes — al validar la cookie se hace
 * `wp_set_current_user()` SOLO para esta request (nunca se emite cookie de
 * sesión real de WP) y desde ahí se invoca directo `BookingsPage::render()`/
 * `CalendarPage::render()` tal cual, con su propia base de URL para que sus
 * links internos (paginación, ver detalle, nueva reserva) apunten adentro
 * del panel en vez de a wp-admin — ver el nuevo parámetro `$base_url` que
 * se sumó a esas clases para esto, exactamente igual a como ya funcionaban
 * desde AdminMenu (mismo método, sin bifurcar el código).
 *
 * A diferencia de Organizer_Panel: acá los formularios de esas pantallas ya
 * postean a la URL actual (sin `action=""` explícito, WordPress estándar),
 * así que no hace falta el truco de admin-post.php con la variante
 * `_nopriv_` — el mismo `template_redirect` que atiende el GET atiende el
 * POST también, sin registrar nada más.
 *
 * **Segundo mecanismo de auth, más importante de lo que parece**: Modo
 * Campo (`FieldPage`) escanea QR vía `admin-ajax.php` con
 * `wp_ajax_amir_field_scan_lookup` — un hook que WordPress solo dispara si
 * `is_user_logged_in()` es true, y esa función mira la cookie REAL de
 * wp-admin, no la nuestra. `wp_set_current_user()` en `maybe_render()`
 * solo dura lo que dura ESTA request — la llamada `fetch()` del escaneo es
 * una request HTTP nueva y separada, donde el gestor vuelve a verse como
 * anónimo. Por eso `determine_current_user` (el filtro que WordPress usa
 * para resolver "quién sos" en CUALQUIER request — page load, admin-ajax.php,
 * REST) se engancha acá de forma global: si nadie más resolvió un usuario
 * real (prioridad 20, después del chequeo normal de cookie de wp-admin) y
 * la cookie propia del panel es válida, se usa ese `user_id`. Con esto,
 * `is_user_logged_in()`/`current_user_can()`/nonces funcionan igual en
 * CUALQUIER request que lleve la cookie del panel, sin tocar
 * `FieldPage::ajax_scan_lookup()` para nada — su `current_user_can()` de
 * siempre ya alcanza.
 */
class ManagerPanel {

	const QUERY_VAR      = 'tourflow_manager_page';
	const REWRITE_VERSION_OPTION = 'tourflow_manager_rewrite_version';
	const REWRITE_VERSION        = '1';

	public static function register(): void {
		add_action( 'init', [ self::class, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
		add_action( 'template_redirect', [ self::class, 'maybe_render' ] );
		add_action( 'init', [ self::class, 'maybe_flush_rewrite_rules' ], 20 );
		add_filter( 'determine_current_user', [ self::class, 'maybe_authenticate_from_cookie' ], 20 );
	}

	/**
	 * Nunca pisa una resolución real (prioridad 20, corre después de que
	 * WordPress ya intentó su propia cookie de auth) — si `$user_id` ya
	 * viene con algo, se devuelve tal cual. Alcance real: cualquier request
	 * al sitio con la cookie del panel puesta queda "logueada" para
	 * capabilities/nonces/AJAX/REST — nunca abre wp-admin (no se emite
	 * ninguna cookie de sesión real de WP acá).
	 */
	public static function maybe_authenticate_from_cookie( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		return ManagerAuth::current_user_id() ?: $user_id;
	}

	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^gestor/?$', 'index.php?' . self::QUERY_VAR . '=dashboard', 'top' );
		add_rewrite_rule( '^gestor/([a-z-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * `/gestor/` es solo la entrada "linda" — igual que Ticket_Web_View de
	 * EventFlow, la URL canónica interna es por query string, inmune a que
	 * el sitio tenga permalinks "simples" (sin mod_rewrite) activados.
	 */
	public static function url( string $page, array $extra = [] ): string {
		return add_query_arg( array_merge( [ self::QUERY_VAR => $page ], $extra ), home_url( '/' ) );
	}

	/**
	 * Las reglas de rewrite recién funcionan después de un flush — no hay
	 * evento de "activar plugin" para instalaciones que ya lo tenían
	 * instalado y solo actualizan de versión, así que se autocorrige acá
	 * (mismo criterio que Installer::ensure_booking_status_enum()).
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::REWRITE_VERSION_OPTION ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules();
			update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
		}
	}

	public static function maybe_render(): void {
		$page = get_query_var( self::QUERY_VAR );
		if ( ! $page ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		if ( $page === 'logout' ) {
			ManagerAuth::clear_cookie();
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}

		if ( $page === 'login' ) {
			self::render_login();
			exit;
		}

		$user_id = ManagerAuth::current_user_id();
		if ( ! $user_id ) {
			wp_safe_redirect( self::url( 'login' ) );
			exit;
		}

		// Truco central (ver docstring de la clase): a partir de acá,
		// current_user_can()/wp_nonce_field()/check_admin_referer() de
		// cualquier clase admin ya existente funcionan sin cambios — nunca
		// se emitió una cookie de sesión real de WP, esto vive solo en el
		// contexto de PHP de esta request puntual.
		wp_set_current_user( $user_id );

		// "Panel rápido" (2026-09-15, ver QuickSection) — un usuario con
		// SOLO alguno de los dos roles chicos (sin manage_amir_booking/
		// manage_options) queda encerrado en esa única sección, sin importar
		// qué URL del panel haya pedido — mismo criterio de "no mostrar lo
		// que no puede usar" que ya aplica is_ssl()/capabilities en el resto
		// del panel, pero acá hace falta un gate explícito porque el switch
		// de abajo no valida capability por caso (cada sub-render() sí lo
		// hace, pero eso solo evita el wp_die, no lo manda al lugar correcto).
		if ( self::is_quick_only() && $page !== 'rapido' ) {
			wp_safe_redirect( self::url( 'rapido' ) );
			exit;
		}

		switch ( $page ) {
			case 'rapido':
				self::render_shell(
					self::tt( 'Panel rápido', 'Quick panel' ),
					self::nav( 'rapido' ) . self::capture( function () {
						QuickSection::render( self::url( 'rapido' ), self::lang() );
					} )
				);
				break;

			case 'tours':
				self::render_shell(
					self::tt( 'Tours', 'Tours' ),
					self::nav( 'tours' ) . self::capture( function () {
						ToursSection::render( self::url( 'tours' ), self::lang() );
					} ),
					true,
					ToursSection::needs_media()
				);
				break;

			case 'habitaciones':
				self::render_shell(
					self::tt( 'Habitaciones', 'Rooms' ),
					self::nav( 'habitaciones' ) . self::capture( function () {
						RoomsSection::render( self::url( 'habitaciones' ), self::lang() );
					} ),
					true,
					RoomsSection::needs_media()
				);
				break;

			case 'disponibilidad-habitaciones':
				self::render_shell(
					self::tt( 'Disponibilidad de habitaciones', 'Room availability' ),
					self::nav( 'disponibilidad-habitaciones' ) . self::capture( function () {
						if ( AMIR_EDITION !== 'pro_max' ) {
							wp_die( esc_html( self::tt( 'Esta sección no está disponible en tu edición de TourFlow.', 'This section is not available in your TourFlow edition.' ) ) );
						}
						if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
							wp_die( esc_html( self::tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
						}
						( new \TourFlow\Rooms\RoomAvailabilityPage() )->render( self::url( 'disponibilidad-habitaciones' ) );
					} )
				);
				break;

			case 'reservas':
				self::render_shell(
					self::tt( 'Reservas', 'Bookings' ),
					self::nav( 'reservas' ) . self::capture( function () {
						$p = new \AmirBooking\Admin\BookingsPage();
						$p->set_lang( self::lang() );
						$p->render( self::url( 'reservas' ) );
					} )
				);
				break;

			case 'calendario':
				self::render_shell(
					self::tt( 'Calendario', 'Calendar' ),
					self::nav( 'calendario' ) . self::capture( function () {
						$p = new \AmirBooking\Admin\CalendarPage();
						$p->set_lang( self::lang() );
						$p->render( self::url( 'calendario' ), self::url( 'reservas' ) );
					} )
				);
				break;

			case 'modo-campo':
				// Sin self::nav() en el body a propósito: FieldPage ya trae su
				// propio header compacto pensado para celular ("← Volver" /
				// "Panel completo →") — la sidebar de escritorio del panel
				// solo restaría espacio en una pantalla angosta.
				self::render_shell(
					self::tt( 'Modo campo', 'Field mode' ),
					self::capture( function () {
						$p = new \AmirBooking\Admin\FieldPage();
						$p->set_lang( self::lang() );
						$p->render( self::url( 'modo-campo' ) );
					} )
				);
				break;

			case 'disponibilidad':
				self::render_shell(
					self::tt( 'Disponibilidad', 'Availability' ),
					self::nav( 'disponibilidad' ) . self::capture( function () {
						$p = new \AmirBooking\Admin\AvailabilityPage();
						$p->set_lang( self::lang() );
						$p->render( self::url( 'disponibilidad' ) );
					} )
				);
				break;

			case 'partners':
				self::render_shell(
					self::tt( 'Partners', 'Partners' ),
					self::nav( 'partners' ) . self::capture( function () {
						$p = new \AmirBooking\Admin\PartnersPage();
						$p->set_lang( self::lang() );
						$p->render( self::url( 'partners' ), self::url( 'reservas' ) );
					} )
				);
				break;

			case 'liquidacion-partners':
				self::render_shell(
					self::tt( 'Liquidación de partners', 'Partner payouts' ),
					self::nav( 'liquidacion-partners' ) . self::capture( function () {
						$p = new \AmirBooking\Admin\PartnerPayoutsPage();
						$p->set_lang( self::lang() );
						$p->render();
					} )
				);
				break;

			case 'dashboard':
			default:
				self::render_dashboard();
				break;
		}
		exit;
	}

	// ==================== LOGIN ====================

	private static function render_login(): void {
		$error = '';

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
			if ( \AmirBooking\Core\RateLimiter::too_many_attempts(
				'tourflow_manager_login_' . \AmirBooking\Core\RateLimiter::client_ip(), 10, 5 * MINUTE_IN_SECONDS
			) ) {
				$error = self::tt( 'Demasiados intentos — probá de nuevo en unos minutos.', 'Too many attempts — try again in a few minutes.' );
			} else {
				$token = ManagerAuth::login(
					sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
					(string) ( $_POST['password'] ?? '' )
				);

				if ( $token ) {
					ManagerAuth::set_cookie( $token );
					wp_safe_redirect( self::url( 'dashboard' ) );
					exit;
				}
				$error = self::tt( 'Usuario o contraseña incorrectos.', 'Incorrect username or password.' );
			}
		}

		$company = get_option( 'amir_company_name', 'TourFlow' );

		self::render_shell(
			self::tt( 'Panel de gestión', 'Management panel' ),
			'<div class="tfp-login-wrap">
				<div class="tfp-login-card">
					<div class="tfp-login-brand">' . esc_html( $company ) . '</div>
					<h1>' . esc_html( self::tt( 'Panel de gestión', 'Management panel' ) ) . '</h1>
					' . ( $error ? '<p class="tfp-error">' . esc_html( $error ) . '</p>' : '' ) . '
					<form method="post">
						<label>' . esc_html( self::tt( 'Usuario', 'Username' ) ) . '<input type="text" name="username" required autofocus autocomplete="username"></label>
						<label>' . esc_html( self::tt( 'Contraseña', 'Password' ) ) . '<input type="password" name="password" required autocomplete="current-password"></label>
						<button type="submit" class="tfp-btn tfp-btn--primary">' . esc_html( self::tt( 'Entrar', 'Sign in' ) ) . '</button>
					</form>
				</div>
			</div>',
			false
		);
	}

	// ==================== DASHBOARD ====================

	private static function render_dashboard(): void {
		$dashboard = new \AmirBooking\Admin\DashboardPage();
		$dashboard->set_bookings_base_url( self::url( 'reservas' ) );

		$today    = current_time( 'Y-m-d' );
		$tomorrow = date( 'Y-m-d', strtotime( '+1 day' ) );

		$today_data    = $dashboard->get_day_summary( $today );
		$tomorrow_data = $dashboard->get_day_summary( $tomorrow );

		$rooms_enabled  = AMIR_EDITION === 'pro_max';
		$today_rooms    = $rooms_enabled ? $dashboard->get_room_day_summary( $today ) : [ 'arrivals' => [], 'departures' => [] ];
		$tomorrow_rooms = $rooms_enabled ? $dashboard->get_room_day_summary( $tomorrow ) : [ 'arrivals' => [], 'departures' => [] ];

		ob_start();
		\AmirBooking\Admin\DashboardPage::day_list_styles();
		$day_list_css = ob_get_clean();

		$google_card = '';
		if ( $rooms_enabled ) {
			$connected = \AmirBooking\Core\GoogleCalendarSync::is_connected();
			$google_card = '<div class="tfp-card tfp-google-card">
				<div class="tfp-card-title">📆 ' . esc_html( self::tt( 'Google Calendar', 'Google Calendar' ) ) . '</div>'
				. ( $connected
					? '<p class="tfp-ok">✅ ' . esc_html( self::tt( 'Conectado — las reservas confirmadas se sincronizan solas.', 'Connected — confirmed bookings sync automatically.' ) ) . '</p>'
					: '<p class="tfp-warn">⚠️ ' . esc_html( self::tt( 'No conectado — pedile al administrador que lo conecte desde TourFlow → Configuración.', 'Not connected — ask the administrator to connect it from TourFlow → Settings.' ) ) . '</p>' )
				. '</div>';
		}

		// Accesos rápidos — mismo criterio que el resto del panel: reusar URLs
		// ya existentes de las pantallas reales (Tours/Reservas ya soportan
		// ?action=new) en vez de duplicar formularios. Pedido implícito del
		// cliente al pedir "óptimo, práctico" para la operación diaria — la
		// acción más común (cargar algo nuevo) queda a un clic desde la
		// pantalla de entrada del panel, no a 2-3 navegaciones.
		$quick_actions = '<a class="tfp-quick-action" href="' . esc_url( add_query_arg( 'action', 'new', self::url( 'reservas' ) ) ) . '"><span class="tfp-quick-icon">📋</span>' . esc_html( self::tt( 'Nueva reserva', 'New booking' ) ) . '</a>'
			. '<a class="tfp-quick-action" href="' . esc_url( add_query_arg( 'action', 'new', self::url( 'tours' ) ) ) . '"><span class="tfp-quick-icon">🏄</span>' . esc_html( self::tt( 'Nuevo tour', 'New tour' ) ) . '</a>'
			. '<a class="tfp-quick-action" href="' . esc_url( self::url( 'modo-campo' ) ) . '"><span class="tfp-quick-icon">📱</span>' . esc_html( self::tt( 'Escanear voucher', 'Scan voucher' ) ) . '</a>'
			. '<a class="tfp-quick-action" href="' . esc_url( self::url( 'partners' ) ) . '"><span class="tfp-quick-icon">🤝</span>' . esc_html( self::tt( 'Nuevo partner', 'New partner' ) ) . '</a>';

		self::render_shell(
			self::tt( 'Dashboard', 'Dashboard' ),
			self::nav( 'dashboard' ) . '
			<main class="tfp-main">
				<div class="tfp-title-row">
					<h1>' . esc_html( self::tt( 'Dashboard', 'Dashboard' ) ) . '</h1>
					<span class="tfp-version">TourFlow v' . esc_html( AMIR_VERSION ) . '</span>
				</div>

				<div class="tfp-quick-actions">' . $quick_actions . '</div>

				<div class="tfp-stats">
					<div class="tfp-stat"><span>' . esc_html( self::tt( 'Reservas hoy', 'Bookings today' ) ) . '</span><strong>' . esc_html( $today_data['total_bookings'] ) . '</strong></div>
					<div class="tfp-stat"><span>' . esc_html( self::tt( 'Personas hoy', 'People today' ) ) . '</span><strong>' . esc_html( $today_data['total_pax'] ) . '</strong></div>
					<div class="tfp-stat"><span>' . esc_html( self::tt( 'Reservas mañana', 'Bookings tomorrow' ) ) . '</span><strong>' . esc_html( $tomorrow_data['total_bookings'] ) . '</strong></div>
					<div class="tfp-stat"><span>' . esc_html( self::tt( 'Personas mañana', 'People tomorrow' ) ) . '</span><strong>' . esc_html( $tomorrow_data['total_pax'] ) . '</strong></div>
				</div>

				' . $google_card . '

				<style>' . $day_list_css . '</style>

				<div class="tfp-card">
					<h2>' . esc_html( self::tt( 'Hoy', 'Today' ) ) . '</h2>
					' . self::capture( function () use ( $dashboard, $today_data ) { $dashboard->render_day_tours( $today_data['tours'] ); } ) . '
					' . ( $rooms_enabled ? self::capture( function () use ( $dashboard, $today_rooms ) { $dashboard->render_day_rooms( $today_rooms ); } ) : '' ) . '
				</div>

				<div class="tfp-card">
					<h2>' . esc_html( self::tt( 'Mañana', 'Tomorrow' ) ) . '</h2>
					' . self::capture( function () use ( $dashboard, $tomorrow_data ) { $dashboard->render_day_tours( $tomorrow_data['tours'] ); } ) . '
					' . ( $rooms_enabled ? self::capture( function () use ( $dashboard, $tomorrow_rooms ) { $dashboard->render_day_rooms( $tomorrow_rooms ); } ) : '' ) . '
				</div>
			</main>'
		);
	}

	// ==================== SHELL / NAV ====================

	/**
	 * Sin `wp_head()`/`wp_footer()` — HTML propio completo. Carga el CSS de
	 * admin de WordPress (common/forms/buttons/dashicons) + admin.css/
	 * panel.css propios, para poder reusar `.button`/`.notice`/`.wrap`/
	 * `.ab-*` de BookingsPage/CalendarPage sin reescribir un solo estilo de
	 * esas clases — mismo truco ya validado en producción por
	 * Organizer_Panel::render_shell() de EventFlow (`wp_print_styles()`
	 * fuera de wp-admin, reskineado encima con un CSS propio).
	 */
	private static function render_shell( string $title, string $body, bool $with_nav = true, bool $with_media = false ): void {
		wp_enqueue_style( 'common' );
		wp_enqueue_style( 'forms' );
		wp_enqueue_style( 'buttons' );
		wp_enqueue_style( 'dashicons' );
		// Tours (galería/itinerario) usa wp.media — mismo truco que
		// Organizer_Panel::render_shell() de EventFlow: encolar ANTES de
		// imprimir estilos, e imprimir scripts + plantillas de media
		// DESPUÉS del body (wp_print_media_templates() necesita que el
		// DOM del formulario ya exista).
		if ( $with_media ) {
			wp_enqueue_media();
		}
		$company = esc_html( get_option( 'amir_company_name', 'TourFlow' ) );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' — ' . $company ); ?></title>
<?php wp_print_styles(); ?>
<link rel="stylesheet" href="<?php echo esc_url( AMIR_PLUGIN_URL . 'assets/css/admin.css?v=' . AMIR_VERSION ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( AMIR_PLUGIN_URL . 'assets/css/panel.css?v=' . AMIR_VERSION ); ?>">
<style><?php echo \AmirBooking\Core\WidgetTheme::render_inline_css(); ?></style>
<script>
/* `ajaxurl`/`amirAdminData` son globales que WordPress y AdminMenu::enqueue_assets()
   solo definen dentro de wp-admin (admin_enqueue_scripts nunca corre acá,
   somos frontend) — varias pantallas reusadas (BookingsPage, FieldPage) los
   dan por puestos en su JS embebido (reprogramar por AJAX/REST, escaneo de
   voucher). Se replican tal cual en vez de tocar esas pantallas. */
var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
var amirAdminData = <?php echo wp_json_encode( [
	'apiUrl'   => rest_url( 'amir/v1/' ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'currency' => get_option( 'amir_currency', 'MXN' ),
	'siteUrl'  => get_site_url(),
	'version'  => AMIR_VERSION,
] ); ?>;
</script>
</head>
<body class="tfp-body<?php echo $with_nav ? '' : ' tfp-body--login'; ?>">
<?php
echo $body;
if ( $with_media ) {
	wp_print_scripts();
	wp_print_media_templates();
}
?>
</body>
</html>
		<?php
	}

	/**
	 * true si el usuario logueado SOLO tiene alguno de los dos roles chicos
	 * del "Panel rápido" (`QuickPanelRole`) — sin `manage_amir_booking` ni
	 * `manage_options`, que dan acceso al panel completo. Se usa para
	 * encerrarlo en esa única sección (`maybe_render()`) y recortar el menú
	 * (`nav()`) — nunca para decidir QUÉ ve adentro de esa sección, eso lo
	 * resuelve QuickSection con sus propias dos capabilities.
	 */
	private static function is_quick_only(): bool {
		return ! current_user_can( 'manage_amir_booking' ) && ! current_user_can( 'manage_options' )
			&& current_user_can( \AmirBooking\Core\QuickPanelRole::CAP_VIEW );
	}

	private static function nav( string $active ): string {
		// Panel rápido: menú de un solo ítem — nunca debe poder navegar a
		// Tours/Reservas/Disponibilidad/etc., ni siquiera tecleando la URL
		// (maybe_render() ya lo redirige, esto es además para no mostrarle
		// links a lugares a los que no puede entrar).
		if ( self::is_quick_only() ) {
			return '<header class="tfp-topbar"><span class="tfp-topbar-title">' . esc_html( self::tt( 'Panel rápido', 'Quick panel' ) ) . '</span></header>
			<nav class="tfp-sidebar">
				<div class="tfp-sidebar-brand">' . esc_html( get_option( 'amir_company_name', 'TourFlow' ) ) . '</div>
				<div class="tfp-sidebar-links">
					<a class="tfp-nav-link is-active" href="' . esc_url( self::url( 'rapido' ) ) . '">📋 ' . esc_html( self::tt( 'Panel rápido', 'Quick panel' ) ) . '</a>
				</div>
				<a class="tfp-nav-link tfp-nav-logout" href="' . esc_url( self::url( 'logout' ) ) . '">🚪 ' . esc_html( self::tt( 'Cerrar sesión', 'Log out' ) ) . '</a>
			</nav>';
		}

		$items = [
			'dashboard'   => [ '🏠', self::tt( 'Dashboard', 'Dashboard' ) ],
			'tours'       => [ '🏄', self::tt( 'Tours', 'Tours' ) ],
		];
		// Habitaciones — Pro Max únicamente, el CPT flow_room ni se registra
		// en otras ediciones (RoomPostType::register()).
		if ( AMIR_EDITION === 'pro_max' ) {
			$items['habitaciones'] = [ '🛏', self::tt( 'Habitaciones', 'Rooms' ) ];
		}
		$items += [
			'reservas'    => [ '📋', self::tt( 'Reservas', 'Bookings' ) ],
			'calendario'  => [ '📅', self::tt( 'Calendario', 'Calendar' ) ],
			'modo-campo'  => [ '📱', self::tt( 'Modo campo', 'Field mode' ) ],
			'disponibilidad' => [ '🗓', self::tt( 'Disponibilidad', 'Availability' ) ],
			'partners'    => [ '🤝', self::tt( 'Partners', 'Partners' ) ],
			'liquidacion-partners' => [ '💸', self::tt( 'Liquidación', 'Payouts' ) ],
		];

		$links = '';
		foreach ( $items as $slug => [ $icon, $label ] ) {
			$is_active = $slug === $active ? ' is-active' : '';
			$links .= '<a class="tfp-nav-link' . $is_active . '" href="' . esc_url( self::url( $slug ) ) . '">' . $icon . ' ' . esc_html( $label ) . '</a>';
		}

		$company     = esc_html( get_option( 'amir_company_name', 'TourFlow' ) );
		$active_label = isset( $items[ $active ] ) ? $items[ $active ][1] : self::tt( 'Dashboard', 'Dashboard' );

		// Checkbox oculto + <label for> = drawer mobile sin una línea de JS
		// (mismo truco que el resto del panel usa para no depender de
		// admin-ajax.php fuera de contexto). En desktop (>900px) el checkbox
		// y la topbar quedan ocultos por CSS y el sidebar vuelve a ser fijo,
		// como siempre. Reemplaza al nav horizontal con scroll lateral de
		// v5.11.1 — usable pero no se sentía a la altura de un panel de
		// gestión real (pedido explícito del cliente, 2026-09-12: "100%
		// adaptable a móviles").
		return '<input type="checkbox" id="tfp-nav-toggle" class="tfp-nav-toggle" hidden>
		<header class="tfp-topbar">
			<label for="tfp-nav-toggle" class="tfp-hamburger" aria-label="' . esc_attr( self::tt( 'Abrir menú', 'Open menu' ) ) . '"><span></span><span></span><span></span></label>
			<span class="tfp-topbar-title">' . esc_html( $active_label ) . '</span>
		</header>
		<label for="tfp-nav-toggle" class="tfp-nav-overlay" aria-hidden="true"></label>
		<nav class="tfp-sidebar">
			<div class="tfp-sidebar-brand">' . $company . '</div>
			<div class="tfp-sidebar-links">' . $links . '</div>
			<a class="tfp-nav-link tfp-nav-logout" href="' . esc_url( self::url( 'logout' ) ) . '">🚪 ' . esc_html( self::tt( 'Cerrar sesión', 'Log out' ) ) . '</a>
		</nav>';
	}

	// ==================== HELPERS ====================

	/**
	 * Idioma del panel — sigue el idioma GENERAL del sitio (`get_locale()`),
	 * no el perfil del usuario logueado. Decisión explícita del cliente
	 * (2026-09-12): "el idioma del dashboard debería ser el mismo que el
	 * del sitio WordPress" — a propósito distinto de `get_user_locale()`,
	 * que es lo que siguen usando estas mismas pantallas dentro de
	 * wp-admin (sin cambios ahí). Se lo pasamos a cada pantalla reusada vía
	 * `set_lang()` para que TODO el contenido del panel — no solo el
	 * sidebar/dashboard propios — quede en el idioma del sitio.
	 */
	private static function lang(): string {
		return strpos( get_locale(), 'en' ) === 0 ? 'en' : 'es';
	}

	private static function tt( string $es, string $en ): string {
		return self::lang() === 'en' ? $en : $es;
	}

	/** Ejecuta $callback capturando su salida — usado para insertar el HTML `echo`-eado de clases admin reusadas dentro de nuestro propio layout. */
	private static function capture( callable $callback ): string {
		ob_start();
		$callback();
		return ob_get_clean();
	}
}
