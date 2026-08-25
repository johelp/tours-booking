<?php
namespace TourFlow\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Backend compartido por los dos flujos del "flujo continuo" (Pro Max,
 * CONTRIBUTING.md § 16.15, crítico para un cliente real):
 *
 * GET /wp-json/flow/v1/tours/featured        → Flujo A paso 1: tours PROPIOS
 *   destacados (sort_order) con sus fechas disponibles alrededor de una fecha.
 * GET /wp-json/flow/v1/tours/catalog-window  → Flujo B paso 2: TODO el
 *   catálogo activo (propios + proveedores) con disponibilidad dentro de un
 *   rango de fechas explícito (±N días se calcula del lado del cliente/quien
 *   llama — este endpoint solo sabe de from/until, no hardcodea la ventana).
 *
 * Namespace REST flow/v1 (no amir/v1, § 15.13) — API-first, público, sin
 * sesión de WordPress, mismo criterio que el resto del plugin.
 */
class DiscoveryController {

	private const NAMESPACE = 'flow/v1';

	/** Tope de fechas evaluadas por tour en una sola llamada — evita que un rango enorme (from muy lejos de until) dispare cientos de queries de disponibilidad. */
	private const MAX_WINDOW_DAYS = 60;

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/tours/featured', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'featured' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'date'        => [ 'required' => false, 'type' => 'string', 'format' => 'date' ],
				'limit'       => [ 'required' => false, 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
				'window_days' => [ 'required' => false, 'type' => 'integer', 'minimum' => 1, 'maximum' => 30 ],
				'lang'        => [ 'required' => false, 'type' => 'string' ],
			],
		] );

		// Extras globales (§ 16.23 CONTRIBUTING.md) — servicios extra y
		// productos digitales que no pertenecen a ningún tour/habitación
		// puntual, para el paso de extras del flujo continuo (funciona aunque
		// el carrito no tenga ningún tour, ej. Flujo B con solo una
		// habitación — antes era literalmente imposible ofrecer un extra ahí).
		register_rest_route( self::NAMESPACE, '/addons/global', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'global_addons' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'lang' => [ 'required' => false, 'type' => 'string' ],
			],
		] );

		// Descarga tokenizada de un producto digital ya comprado — el link
		// que viaja en el email de confirmación (ver BaseEmail::addons_row()).
		// No es un link público adivinable: exige el access_token de LA
		// reserva a la que quedó adjunto ese ítem del carrito, mismo criterio
		// que verify_url() para el resto de los links de reserva del plugin.
		register_rest_route( self::NAMESPACE, '/addons/download/(?P<booking_addon_id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'download_digital_addon' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'booking_addon_id' => [ 'required' => true, 'type' => 'integer' ],
				'token'            => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/tours/catalog-window', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'catalog_window' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'from'    => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
				'until'   => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
				'lang'    => [ 'required' => false, 'type' => 'string' ],
				// 'list' (default, Flujo B paso 2 — catálogo completo) vs
				// 'suggestion' (paso de extras, "otros tours sugeridos") —
				// cada uno respeta un flag de visibilidad distinto en el
				// tour (§ 16.21 CONTRIBUTING.md, 2026-08-04): un tour puede
				// estar oculto de listas pero seguir sugerido, o viceversa.
				'context' => [ 'required' => false, 'type' => 'string', 'enum' => [ 'list', 'suggestion' ] ],
			],
		] );
	}

	// ── GET /tours/featured ──────────────────────────────────────────────

	public function featured( \WP_REST_Request $request ): \WP_REST_Response {
		$lang        = sanitize_key( $request->get_param( 'lang' ) ?: \AmirBooking\Core\Shortcodes::detect_lang() );
		$date        = sanitize_text_field( $request->get_param( 'date' ) ?: current_time( 'Y-m-d' ) );
		$limit       = (int) ( $request->get_param( 'limit' ) ?: 6 );
		$window_days = (int) ( $request->get_param( 'window_days' ) ?: 4 );

		global $wpdb;
		// Solo tours PROPIOS (provider_id NULL) y marcados "featured" a mano
		// en el editor — decisión del cliente 2026-08-03: sort_order solo no
		// alcanza (un operador con muchos tours propios quiere elegir a mano
		// cuáles aparecen acá, no "los primeros N"). Los de proveedor
		// aparecen en catalog-window (Flujo B), no acá. hide_from_lists=0
		// (§ 16.21, 2026-08-04) gana sobre featured — esto ES una lista,
		// así que un tour de venta separada nunca aparece acá aunque esté
		// marcado destacado.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}amir_tours
			 WHERE status = 'active' AND provider_id IS NULL AND featured = 1 AND hide_from_lists = 0
			 ORDER BY sort_order ASC, id ASC
			 LIMIT " . $limit
		) ?? [];

		$tours_controller = new \AmirBooking\Api\ToursController();
		$engine           = new \AmirBooking\Core\AvailabilityEngine();
		$from  = date( 'Y-m-d', strtotime( $date . " -{$window_days} days" ) );
		$until = date( 'Y-m-d', strtotime( $date . " +{$window_days} days" ) );

		$results = array_map( function ( $r ) use ( $tours_controller, $engine, $lang, $from, $until ) {
			$summary = $tours_controller->format_tour_summary( $r, $lang );
			$summary['available_dates'] = $this->dates_in_range( $engine, (int) $r->id, $from, $until );
			return $summary;
		}, $rows );

		return new \WP_REST_Response( $results, 200 );
	}

	// ── GET /addons/global ────────────────────────────────────────────────

	public function global_addons( \WP_REST_Request $request ): \WP_REST_Response {
		$lang = sanitize_key( $request->get_param( 'lang' ) ?: \AmirBooking\Core\Shortcodes::detect_lang() );

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, pricing_type, name_es, name_en, content_i18n, price_mxn
			 FROM {$wpdb->prefix}amir_addons
			 WHERE applies_to = 'global' AND active = 1
			 ORDER BY sort_order ASC, id ASC"
		) ?? [];

		$results = array_map( fn( $a ) => [
			'id'           => (int) $a->id,
			'name'         => \AmirBooking\Core\Languages::tour_field( $a, 'name', $lang ),
			'price_mxn'    => (float) $a->price_mxn,
			'pricing_type' => $a->pricing_type,
		], $rows );

		return new \WP_REST_Response( $results, 200 );
	}

	// ── GET /addons/download/{booking_addon_id} ───────────────────────────
	// Transmite el archivo directo (no devuelve JSON, no redirige a una URL
	// pública — auditoría de seguridad 2026-08-04, § 16.28): es el link que
	// se pega tal cual en un <a href> del email de confirmación, tiene que
	// abrir/descargar el archivo con un solo click — mismo criterio que
	// CartController::download_pdf()/VoucherGenerator::stream().

	public function download_digital_addon( \WP_REST_Request $request ): void {
		$id    = (int) $request->get_param( 'booking_addon_id' );
		$token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ba.id, a.digital_file_url, a.pricing_type, a.name_es, a.name_en, b.access_token, b.status
			 FROM {$wpdb->prefix}amir_booking_addons ba
			 JOIN {$wpdb->prefix}amir_addons a   ON a.id = ba.addon_id
			 JOIN {$wpdb->prefix}amir_bookings b ON b.id = ba.booking_id
			 WHERE ba.id = %d",
			$id
		) );

		if ( ! $row || $row->pricing_type !== 'digital' || empty( $row->digital_file_url ) ) {
			status_header( 404 );
			echo 'Archivo no encontrado.';
			exit;
		}
		if ( empty( $row->access_token ) || ! hash_equals( (string) $row->access_token, $token ) ) {
			status_header( 403 );
			echo 'Acceso no autorizado.';
			exit;
		}
		// Los addons se adjuntan a la reserva en el checkout, antes de
		// cualquier confirmación de pago (CartController::attach_global_addons())
		// — sin este chequeo, cualquiera con un carrito abandonado (nunca
		// pagado) podía descargar el archivo digital igual (auditoría de
		// seguridad 2026-08-04, ver CONTRIBUTING.md § 16.28).
		if ( ! in_array( $row->status, [ 'confirmed', 'completed' ], true ) ) {
			status_header( 403 );
			echo 'La reserva todavía no está confirmada.';
			exit;
		}

		// digital_file_url guarda la ruta de la copia protegida (zona no
		// pública, GlobalAddonsPage::store_digital_file()) desde la
		// auditoría de seguridad 2026-08-04 — nunca la URL pública de Media
		// Library, así que se transmite el archivo en vez de redirigir:
		// un redirect a una URL pública hacía que el token no protegiera
		// nada en la práctica (ver CONTRIBUTING.md § 16.28).
		if ( ! file_exists( $row->digital_file_url ) ) {
			status_header( 404 );
			echo 'Archivo no encontrado.';
			exit;
		}

		$mime      = mime_content_type( $row->digital_file_url ) ?: 'application/octet-stream';
		$ext       = pathinfo( $row->digital_file_url, PATHINFO_EXTENSION );
		$nice_name = sanitize_file_name( $row->name_es ?: $row->name_en ?: 'archivo' ) . ( $ext ? '.' . $ext : '' );

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $nice_name . '"' );
		header( 'Content-Length: ' . filesize( $row->digital_file_url ) );
		readfile( $row->digital_file_url );
		exit;
	}

	// ── GET /tours/catalog-window ─────────────────────────────────────────

	public function catalog_window( \WP_REST_Request $request ): \WP_REST_Response {
		$lang    = sanitize_key( $request->get_param( 'lang' ) ?: \AmirBooking\Core\Shortcodes::detect_lang() );
		$from    = sanitize_text_field( $request->get_param( 'from' ) );
		$until   = sanitize_text_field( $request->get_param( 'until' ) );
		$context = $request->get_param( 'context' ) === 'suggestion' ? 'suggestion' : 'list';

		if ( $until < $from ) {
			return new \WP_REST_Response( [ 'error' => 'until debe ser posterior o igual a from' ], 400 );
		}
		$span_days = (int) round( ( strtotime( $until ) - strtotime( $from ) ) / DAY_IN_SECONDS );
		if ( $span_days > self::MAX_WINDOW_DAYS ) {
			return new \WP_REST_Response( [ 'error' => 'El rango no puede superar ' . self::MAX_WINDOW_DAYS . ' días.' ], 400 );
		}

		global $wpdb;
		// Todo el catálogo activo, propios Y de proveedores — sin distinción
		// para el usuario (§ 16.15, Flujo B paso 2). El flag de visibilidad
		// que se respeta depende del contexto (§ 16.21, 2026-08-04): 'list'
		// (Flujo B paso 2, catálogo completo) filtra hide_from_lists;
		// 'suggestion' (paso de extras, "otros tours sugeridos") filtra
		// hide_from_suggestions — un mismo tour puede estar oculto de uno y
		// visible en el otro.
		$hide_column = $context === 'suggestion' ? 'hide_from_suggestions' : 'hide_from_lists';
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}amir_tours WHERE status = 'active' AND {$hide_column} = 0 ORDER BY sort_order ASC, id ASC"
		) ?? [];

		$tours_controller = new \AmirBooking\Api\ToursController();
		$engine           = new \AmirBooking\Core\AvailabilityEngine();

		$results = [];
		foreach ( $rows as $r ) {
			$available_dates = $this->dates_in_range( $engine, (int) $r->id, $from, $until );
			if ( empty( $available_dates ) ) {
				continue; // Flujo B solo muestra lo que efectivamente tiene cupo en el rango.
			}
			$summary = $tours_controller->format_tour_summary( $r, $lang );
			$summary['available_dates'] = $available_dates;
			$results[] = $summary;
		}

		return new \WP_REST_Response( $results, 200 );
	}

	/**
	 * Fechas con disponibilidad de un tour dentro de [from, until] — llama
	 * AvailabilityEngine::get_month_availability() una vez por cada mes que
	 * el rango atraviesa (normalmente 1, a veces 2 si cruza fin de mes).
	 * @return string[] Fechas 'Y-m-d' disponibles, ordenadas.
	 */
	private function dates_in_range( \AmirBooking\Core\AvailabilityEngine $engine, int $tour_id, string $from, string $until ): array {
		$dates  = [];
		$cursor = strtotime( date( 'Y-m-01', strtotime( $from ) ) );
		$end_ts = strtotime( $until );

		while ( $cursor <= $end_ts ) {
			$year  = (int) date( 'Y', $cursor );
			$month = (int) date( 'n', $cursor );
			$month_data = $engine->get_month_availability( $tour_id, $year, $month );
			foreach ( $month_data as $date => $info ) {
				if ( $date >= $from && $date <= $until && ! empty( $info['available'] ) ) {
					$dates[] = $date;
				}
			}
			$cursor = strtotime( '+1 month', $cursor );
		}

		sort( $dates );
		return $dates;
	}
}
