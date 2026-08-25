<?php
namespace TourFlow\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout de carrito multi-ítem (tour + habitación + extras bajo un solo
 * pago) — CONTRIBUTING.md § 16. Decisión del cliente (2026-07-31): el
 * carrito se arma en el cliente (React, sin persistir fila por fila) — este
 * endpoint recibe el carrito completo recién al pagar y crea todas las
 * reservas reales de una.
 *
 * POST /wp-json/flow/v1/cart/checkout                          → Crear todas las reservas + iniciar UN pago combinado
 * POST /wp-json/flow/v1/cart/(cart_group_id)/confirm-payment    → Confirmar TODAS las reservas del carrito de una
 * GET  /wp-json/flow/v1/cart/(cart_group_id)/pdf                → Voucher general (PDF+QR único, § 16.15 CONTRIBUTING.md)
 */
class CartController {

	private const NAMESPACE = 'flow/v1';

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/cart/checkout', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'checkout' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/cart/(?P<cart_group_id>[a-f0-9\-]+)/confirm-payment', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'confirm_payment' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'cart_group_id'     => [ 'required' => true, 'type' => 'string' ],
				'payment_intent_id' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/cart/(?P<cart_group_id>[a-f0-9\-]+)/pdf', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'download_pdf' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'cart_group_id' => [ 'required' => true, 'type' => 'string' ],
				'token'         => [ 'required' => false, 'type' => 'string' ],
				'email'         => [ 'required' => false, 'type' => 'string', 'format' => 'email' ],
			],
		] );
	}

	// ── GET /cart/{cart_group_id}/pdf ─────────────────────────────────────

	public function download_pdf( \WP_REST_Request $request ): void {
		if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'cart_pdf_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
			status_header( 429 );
			echo 'Demasiados intentos. Intenta de nuevo en unos minutos.';
			exit;
		}

		$cart_group_id = sanitize_text_field( $request->get_param( 'cart_group_id' ) );

		global $wpdb;
		$primary = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}amir_bookings WHERE cart_group_id = %s ORDER BY id ASC LIMIT 1",
			$cart_group_id
		) );
		if ( ! $primary ) {
			status_header( 404 );
			echo 'Carrito no encontrado.';
			exit;
		}

		// Mismo criterio de autorización que el voucher de tours — token o
		// email, nunca solo la referencia (adivinable).
		$token   = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
		$email   = sanitize_email( $request->get_param( 'email' ) ?? '' );
		$manager = new \AmirBooking\Core\BookingManager();
		if ( ! $manager->authorize_public_access( $primary, $token, $email ) ) {
			status_header( 403 );
			echo 'Acceso no autorizado.';
			exit;
		}

		( new CartVoucherGenerator() )->stream_for_cart( $cart_group_id );
	}

	// ── POST /cart/checkout ──────────────────────────────────────────────

	public function checkout( \WP_REST_Request $request ): \WP_REST_Response {
		if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'cart_checkout_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
			return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'El carrito está vacío.' ], 422 );
		}

		$customer = [
			'customer_name'    => $request->get_param( 'customer_name' ),
			'customer_email'   => $request->get_param( 'customer_email' ),
			'customer_phone'   => $request->get_param( 'customer_phone' ) ?? '',
			'lang'             => $request->get_param( 'lang' ) ?? 'es',
			'policy_accepted'  => (bool) $request->get_param( 'policy_accepted' ),
			'terms_accepted'   => (bool) $request->get_param( 'terms_accepted' ),
			'source'           => 'direct',
		];

		$created_ids   = [];   // todos los booking_id creados — para poder limpiar si algo falla después
		$payable       = [];   // [ ['result' => BookingResult, 'item_type' => ...], ... ] — items que sí requieren cobro ahora
		$grand_total   = 0.0;
		$addon_items   = [];   // type:'addon' (§ 16.23 CONTRIBUTING.md) — no crean su propia reserva, se procesan aparte una vez que se sabe cuál es la primera reserva pagable.
		// Refs de ítems que NO requieren cobro — para que el frontend sepa
		// qué mostrar cuando $payable queda vacío (antes solo existía el
		// caso de cobro diferido a proveedor; con el fix del cupón 100%,
		// acá también caen ítems ya CONFIRMADOS de una sin cobrar nada).
		$confirmed_refs = [];
		$pending_refs   = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['type'] ) ) {
				$this->cleanup( $created_ids );
				return new \WP_REST_Response( [ 'success' => false, 'error' => 'Ítem de carrito inválido.' ], 422 );
			}

			if ( $item['type'] === 'addon' ) {
				$addon_items[] = $item;
				continue;
			}

			$result = $this->create_item( $item, $customer );

			if ( ! $result->success ) {
				$this->cleanup( $created_ids );
				return new \WP_REST_Response( [ 'success' => false, 'error' => $result->error ], 422 );
			}

			$created_ids[] = $result->booking_id;

			// Cobro diferido a proveedor (§ 11.0) — la reserva ya quedó en
			// pending_provider_approval sin cobrar nada, no entra al pago combinado.
			// charge_mxn (default = total_mxn si el tour no tiene depósito
			// activo, ver BookingResult) es lo que realmente se cobra ahora —
			// así un carrito mixto (tour con depósito + habitación 100%) suma
			// bien sin lógica extra acá: cada ítem aporta solo su parte.
			if ( $result->requires_payment ) {
				$payable[]    = $result;
				$grand_total += $result->charge_mxn;
			} elseif ( $result->status === 'confirmed' ) {
				$confirmed_refs[] = $result->booking_ref;
			} else {
				$pending_refs[] = $result->booking_ref;
			}
		}

		// Extras globales (§ 16.23 CONTRIBUTING.md, decisión cerrada con el
		// cliente 2026-08-04): un addon type:'addon' no pertenece a ningún
		// tour/habitación del carrito (por eso existe — se puede ofrecer
		// aunque el carrito no tenga ningún tour) así que no tiene una
		// reserva propia a la que "pertenecer" naturalmente. Se adjunta a la
		// PRIMERA reserva pagable del carrito — más simple que crear una fila
		// de amir_bookings solo para colgar un addon. Si el carrito no tiene
		// NINGUNA reserva pagable (ej. solo un tour de proveedor con cobro
		// diferido), no hay dónde colgarlo — error explícito en vez de
		// perder el addon en silencio.
		if ( ! empty( $addon_items ) ) {
			if ( empty( $payable ) ) {
				$this->cleanup( $created_ids );
				return new \WP_REST_Response( [ 'success' => false, 'error' => 'El carrito necesita al menos una experiencia o habitación para agregar extras.' ], 422 );
			}
			$addons_result = $this->attach_global_addons( $addon_items, $payable[0]->booking_id );
			if ( $addons_result['error'] ) {
				$this->cleanup( $created_ids );
				return new \WP_REST_Response( [ 'success' => false, 'error' => $addons_result['error'] ], 422 );
			}
			$grand_total += $addons_result['total'];
		}

		global $wpdb;
		$cart_group_id = wp_generate_uuid4();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}amir_bookings SET cart_group_id = %s WHERE id IN (" . implode( ',', array_fill( 0, count( $created_ids ), '%d' ) ) . ')',
			array_merge( [ $cart_group_id ], $created_ids )
		) );

		if ( empty( $payable ) ) {
			// Todo el carrito era de cobro diferido a proveedor, y/o quedó
			// confirmado de una por un cupón que cubrió el 100% (Dudas de
			// producto, CLAUDE.md) — nada que pagar ahora en ningún caso.
			// booking_refs/pending_provider_refs para que el frontend
			// distinga "ya confirmado" de "esperando al proveedor" (antes
			// esto no venía nunca, así que ConfirmationPanel mostraba
			// siempre el texto tentativo "reserva recibida", sin referencia).
			return new \WP_REST_Response( [
				'success'        => true,
				'cart_group_id'  => $cart_group_id,
				'requires_payment' => false,
				'total_mxn'      => 0,
				'booking_refs'          => $confirmed_refs,
				'pending_provider_refs' => $pending_refs,
			], 201 );
		}

		// Un solo pago cubre todos los ítems pagables — BookingResult
		// sintético: los gateways solo leen booking_ref/booking_id/total_mxn
		// (confirmado al generalizar amir_bookings para habitaciones, § 16.5),
		// así que representar el carrito entero con uno alcanza sin tocar
		// PaymentGatewayInterface.
		$cart_result = new \AmirBooking\Core\BookingResult(
			true,
			$payable[0]->booking_id,
			'CART-' . strtoupper( substr( $cart_group_id, 0, 8 ) ),
			$grand_total
		);

		$gateway = \AmirBooking\Payments\PaymentGatewayFactory::default_gateway();
		try {
			$payment = $gateway->create_payment( $cart_result );
		} catch ( \Throwable $e ) {
			$this->cleanup( $created_ids );
			error_log( sprintf( 'TourFlow Cart: excepción al crear el cobro (%s) — %s', $gateway->id(), $e->getMessage() ) );
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'Error al inicializar el pago. Intenta de nuevo.' ], 500 );
		}

		if ( ! $payment->success ) {
			$this->cleanup( $created_ids );
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'Error al inicializar el pago. Intenta de nuevo.' ], 500 );
		}

		// La MISMA referencia de pago se guarda en TODAS las reservas
		// pagables del carrito — es lo que confirm_payment() usa después
		// para confirmarlas todas juntas con un solo webhook/callback.
		$payable_ids = array_map( fn( $r ) => $r->booking_id, $payable );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}amir_bookings SET stripe_payment_intent = %s, payment_gateway = %s, gateway_reference = %s
			 WHERE id IN (" . implode( ',', array_fill( 0, count( $payable_ids ), '%d' ) ) . ')',
			array_merge( [ $payment->reference, $gateway->id(), $payment->reference ], $payable_ids )
		) );

		foreach ( $payable_ids as $bid ) {
			\AmirBooking\Payments\PaymentEventLogger::log( $bid, $gateway->id(), 'created', '', [ 'reference' => $payment->reference, 'cart_group_id' => $cart_group_id ] );
		}

		return new \WP_REST_Response( array_merge( [
			'success'        => true,
			'cart_group_id'  => $cart_group_id,
			'total_mxn'      => $grand_total,
			'gateway'        => $gateway->id(),
			'requires_payment' => true,
		], $payment->client_payload ), 201 );
	}

	private function create_item( array $item, array $customer ): \AmirBooking\Core\BookingResult {
		if ( $item['type'] === 'room' ) {
			return ( new \TourFlow\Rooms\RoomBookingManager() )->create_pending( array_merge( $customer, [
				'room_id'          => $item['room_id']   ?? 0,
				'check_in'         => $item['check_in']  ?? '',
				'check_out'        => $item['check_out'] ?? '',
				'guests'           => $item['guests']    ?? 1,
				'special_requests' => $item['special_requests'] ?? '',
				// Cupones también para habitaciones desde 2026-08-04 (§ 16.21
				// CONTRIBUTING.md) — mismo campo que tours, RoomBookingManager
				// lo valida contra ESTA habitación puntual (o global).
				'coupon_code'      => $item['coupon_code'] ?? '',
			] ) );
		}

		if ( $item['type'] === 'tour' ) {
			return ( new \AmirBooking\Core\BookingManager() )->create_pending( array_merge( $customer, [
				'tour_id'          => $item['tour_id']     ?? 0,
				'schedule_id'      => $item['schedule_id'] ?? 0,
				'date'             => $item['date']        ?? '',
				'adults'           => $item['adults']      ?? 1,
				'children'         => $item['children']    ?? 0,
				'babies'           => $item['babies']      ?? 0,
				'addons'           => $item['addons']      ?? [],
				'special_requests' => $item['special_requests'] ?? '',
				// Bug real corregido 2026-08-04: BookingManager::create_pending()
				// ya sabía aplicar coupon_code (usado por el widget individual,
				// BookingWidget.jsx) pero el carrito nunca lo reenviaba — un
				// cupón cargado en el checkout del flujo continuo se perdía en
				// silencio, sin error ni descuento.
				'coupon_code'      => $item['coupon_code'] ?? '',
				// "Requiere nombre de cada integrante" (2026-08-24) — mismo
				// campo que ya valida create_pending() para el widget clásico,
				// acá solo hace falta reenviarlo (Discovery/Explore, § 16.93 CONTRIBUTING.md).
				'participant_names' => $item['participant_names'] ?? [],
			] ) );
		}

		if ( $item['type'] === 'product' ) {
			return $this->create_product_order( $item, $customer );
		}

		return \AmirBooking\Core\BookingResult::error( 'Tipo de ítem de carrito desconocido: ' . esc_html( $item['type'] ) );
	}

	/**
	 * Venta suelta de un producto digital (ej. guía PDF), sin reservar
	 * ningún tour — pedido del cliente 2026-08-25 ([flow_product], §
	 * 16.9x CONTRIBUTING.md). Reusa la misma tabla amir_bookings como
	 * ancla del pago/access_token (mismo criterio ya usado al sumar
	 * item_type='room', § 16 CONTRIBUTING.md — generalizar en vez de
	 * duplicar toda la infraestructura de pago/confirmación/email) con
	 * tour_id/room_id/schedule_id NULL. tour_date NOT NULL no aplica acá
	 * (no hay fecha de tour) — se guarda la fecha de la COMPRA, mismo
	 * workaround ya usado por create_custom_quote_request().
	 *
	 * El producto en sí se persiste con attach_global_addons() — la MISMA
	 * función que ya valida/inserta un extra global sobre una reserva de
	 * tour, reusada tal cual (una compra suelta es, ni más ni menos, una
	 * "reserva" de un solo extra global sin nada más adentro).
	 *
	 * Alcance v1, a propósito: solo addons `pricing_type='digital'` — no
	 * tiene sentido operativo vender un extra `per_unit`/`flat` (ej.
	 * "transfer", "alquiler de equipo") sin ningún tour al que asociarlo.
	 */
	private function create_product_order( array $item, array $customer ): \AmirBooking\Core\BookingResult {
		global $wpdb;

		$addon_id = (int) ( $item['addon_id'] ?? 0 );
		$addon    = $addon_id ? $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}amir_addons WHERE id = %d AND applies_to = 'global' AND active = 1 AND pricing_type = 'digital'",
			$addon_id
		) ) : null;

		if ( ! $addon ) {
			return \AmirBooking\Core\BookingResult::error( 'Este producto ya no está disponible.' );
		}

		$booking_manager = new \AmirBooking\Core\BookingManager();
		$ref             = $booking_manager->generate_ref();

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}amir_bookings",
			[
				'booking_ref'    => $ref,
				'access_token'   => \AmirBooking\Core\BookingManager::generate_access_token(),
				'item_type'      => 'product',
				'tour_date'      => current_time( 'Y-m-d' ), // NOT NULL, sin significado acá — fecha de la compra
				'status'         => 'pending',
				'booking_source' => 'direct',
				'lang'           => sanitize_text_field( $customer['lang'] ?? 'es' ),
				'customer_name'  => sanitize_text_field( $customer['customer_name'] ?? '' ),
				'customer_email' => sanitize_email( $customer['customer_email'] ?? '' ),
				'customer_phone' => sanitize_text_field( $customer['customer_phone'] ?? '' ),
				'total_mxn'      => 0,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f' ]
		);

		if ( ! $inserted ) {
			return \AmirBooking\Core\BookingResult::error( 'No pudimos iniciar la compra. Intenta de nuevo.' );
		}

		$booking_id = (int) $wpdb->insert_id;

		$attach = $this->attach_global_addons( [ [ 'addon_id' => $addon_id, 'qty' => 1 ] ], $booking_id );
		if ( $attach['error'] || $attach['total'] <= 0 ) {
			$wpdb->delete( "{$wpdb->prefix}amir_bookings", [ 'id' => $booking_id ] );
			return \AmirBooking\Core\BookingResult::error( $attach['error'] ?: 'Este producto no tiene un precio configurado.' );
		}

		return new \AmirBooking\Core\BookingResult( true, $booking_id, $ref, $attach['total'] );
	}

	/**
	 * Valida y persiste los ítems type:'addon' del carrito contra el
	 * catálogo de extras globales (amir_addons WHERE applies_to='global') —
	 * nunca confía en el precio/nombre que mande el cliente, los relee de la
	 * base como el resto del plugin. Todos cuelgan de $target_booking_id
	 * (la primera reserva pagable del carrito, ver checkout()) y suman a su
	 * total_mxn para que reportes/CSV y el total mostrado en el admin de esa
	 * reserva incluyan el extra.
	 *
	 * @return array{total: float, error: ?string}
	 */
	private function attach_global_addons( array $addon_items, int $target_booking_id ): array {
		global $wpdb;
		$total = 0.0;

		foreach ( $addon_items as $item ) {
			$addon_id = (int) ( $item['addon_id'] ?? 0 );
			$addon = $addon_id ? $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}amir_addons WHERE id = %d AND applies_to = 'global' AND active = 1",
				$addon_id
			) ) : null;

			if ( ! $addon ) {
				return [ 'total' => 0.0, 'error' => 'Uno de los extras elegidos ya no está disponible.' ];
			}

			// 'flat'/'digital': siempre cantidad 1 (no tiene sentido comprar
			// "2 guías PDF" ni "2 transfers" con este mismo criterio simple).
			// 'per_unit': cantidad libre, acotada a un rango razonable — no
			// hay un "cupo de personas" del que colgarse como en los addons
			// por tour (PricingEngine::apply_addons()), es un ítem suelto.
			$qty = $addon->pricing_type === 'per_unit'
				? max( 1, min( 20, (int) ( $item['qty'] ?? 1 ) ) )
				: 1;
			$line_total = (float) $addon->price_mxn * $qty;

			$inserted = $wpdb->insert(
				"{$wpdb->prefix}amir_booking_addons",
				[
					'booking_id'     => $target_booking_id,
					'addon_id'       => $addon->id,
					'qty'            => $qty,
					'unit_price_mxn' => (float) $addon->price_mxn,
					'total_mxn'      => $line_total,
					'name_snapshot'  => $addon->name_es,
				],
				[ '%d', '%d', '%d', '%f', '%f', '%s' ]
			);
			if ( ! $inserted ) {
				return [ 'total' => 0.0, 'error' => 'Error al guardar los extras del carrito.' ];
			}

			$total += $line_total;
		}

		if ( $total > 0 ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}amir_bookings SET total_mxn = total_mxn + %f WHERE id = %d",
				$total, $target_booking_id
			) );
		}

		return [ 'total' => $total, 'error' => null ];
	}

	/**
	 * Borra todas las reservas ya creadas en un intento de checkout que
	 * falló a mitad de camino — nunca dejar reservas "pending" huérfanas de
	 * un carrito que nunca se terminó de armar. Incluye flow_room_bookings
	 * (si no, esas fechas quedarían bloqueadas sin ninguna reserva real).
	 */
	private function cleanup( array $booking_ids ): void {
		if ( empty( $booking_ids ) ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}flow_room_bookings WHERE booking_id IN ({$placeholders})", $booking_ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}amir_bookings WHERE id IN ({$placeholders})", $booking_ids ) );
	}

	// ── POST /cart/{cart_group_id}/confirm-payment ───────────────────────

	public function confirm_payment( \WP_REST_Request $request ): \WP_REST_Response {
		if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'cart_confirm_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
			return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
		}

		global $wpdb;
		$cart_group_id = sanitize_text_field( $request->get_param( 'cart_group_id' ) );
		$pi_id         = sanitize_text_field( $request->get_param( 'payment_intent_id' ) );

		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}amir_bookings WHERE cart_group_id = %s", $cart_group_id
		) );

		if ( empty( $bookings ) ) {
			return new \WP_REST_Response( [ 'error' => 'Carrito no encontrado' ], 404 );
		}

		$already_confirmed = array_filter( $bookings, fn( $b ) => $b->status === 'confirmed' );
		if ( count( $already_confirmed ) === count( $bookings ) ) {
			return new \WP_REST_Response( [ 'confirmed' => true, 'booking_refs' => wp_list_pluck( $bookings, 'booking_ref' ) ], 200 );
		}

		// Mismo chequeo fail-closed que el confirm-payment de tours: el
		// payment_intent_id tiene que ser el que se generó para ESTE carrito.
		$primary = $bookings[0];
		$known_reference = $primary->gateway_reference ?: $primary->stripe_payment_intent;
		if ( empty( $known_reference ) || ! hash_equals( $known_reference, $pi_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'payment_intent_id no corresponde a este carrito' ], 403 );
		}

		$gateway = \AmirBooking\Payments\PaymentGatewayFactory::for_booking( $primary );
		if ( ! $gateway || ! $gateway->is_configured() ) {
			return new \WP_REST_Response( [ 'error' => 'La pasarela de pago no está configurada. No se puede confirmar el pago.' ], 503 );
		}

		$status = $gateway->fetch_payment_status( $pi_id );
		if ( $status === null ) {
			return new \WP_REST_Response( [ 'error' => 'No se pudo verificar el pago. La confirmación llegará por email en breve.' ], 503 );
		}
		if ( $status->status !== \AmirBooking\Payments\PaymentStatusResult::SUCCEEDED ) {
			return new \WP_REST_Response( [ 'error' => 'Pago no completado' ], 402 );
		}

		// Confirma cada reserva del carrito con el mismo charge_id — ya
		// generalizado para tour Y room (BookingManager::confirm() branchea
		// internamente, § 16.5), así que un solo loop alcanza para ambos tipos.
		// OJO: BookingManager::confirm() puede terminar una reserva 'pending'
		// en 'confirmed' O en 'pending_provider_approval' según si el tour
		// tiene proveedor externo — en un carrito mixto (un tour propio +
		// un tour de proveedor en el mismo checkout) las dos cosas pasan a
		// la vez, cada una con su propio email (ver EmailDispatcher). Por
		// eso NO alcanza con marcar todo como "confirmado" acá — hay que
		// releer el estado real después de confirm() para no mentirle al
		// cliente ("tu reserva está confirmada" para algo que en realidad
		// quedó esperando al proveedor).
		$manager = new \AmirBooking\Core\BookingManager();
		foreach ( $bookings as $b ) {
			if ( $b->status === 'pending' ) {
				$manager->confirm( (int) $b->id, $status->charge_reference );
			}
			\AmirBooking\Payments\PaymentEventLogger::log( (int) $b->id, $gateway->id(), 'succeeded', '', [ 'charge_reference' => $status->charge_reference, 'cart_group_id' => $cart_group_id ] );
		}

		$final = $wpdb->get_results( $wpdb->prepare(
			"SELECT booking_ref, status FROM {$wpdb->prefix}amir_bookings WHERE cart_group_id = %s", $cart_group_id
		) );
		$confirmed_refs       = wp_list_pluck( array_filter( $final, fn( $b ) => $b->status === 'confirmed' ), 'booking_ref' );
		$pending_provider_refs = wp_list_pluck( array_filter( $final, fn( $b ) => $b->status === 'pending_provider_approval' ), 'booking_ref' );

		// El "voucher general" (§ 16.15 CONTRIBUTING.md) es un complemento de
		// los emails por ítem, no un reemplazo — si ningún ítem terminó
		// realmente 'confirmed' (ej. el único tour del carrito era de un
		// proveedor externo), no hay nada confirmado que resumir todavía; el
		// aviso interino de ese ítem (ProviderPendingNoticeEmail) ya avisó.
		if ( ! empty( $confirmed_refs ) ) {
			do_action( 'flow_cart_confirmed', $cart_group_id );
		}

		return new \WP_REST_Response( [
			'confirmed'              => true,
			'booking_refs'           => $confirmed_refs,
			'pending_provider_refs'  => $pending_provider_refs,
		], 200 );
	}
}
