<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints REST de habitaciones (Pro Max, CONTRIBUTING.md § 16). Namespace
 * REST nuevo flow/v1 (no amir/v1, § 15.13). API-first: público, sin sesión
 * de WordPress, mismo criterio que amir/v1 (SPEC-HEADLESS.md).
 *
 * GET  /wp-json/flow/v1/rooms                        → Catálogo de habitaciones activas
 * GET  /wp-json/flow/v1/rooms/{id}/availability       → Disponibilidad para un rango de fechas
 * POST /wp-json/flow/v1/room-bookings                 → Crear reserva + iniciar pago
 *
 * confirm-payment, cancel, webhooks: NO se duplican acá — amir_bookings ya
 * generalizada (item_type) hace que /wp-json/amir/v1/bookings/{id}/confirm-payment
 * y el resto del ciclo de vida de BookingManager funcionen tal cual para una
 * reserva de habitación, sin código nuevo (ver CONTRIBUTING.md § 16.5).
 */
class RoomBookingController {

	private const NAMESPACE = 'flow/v1';

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/rooms', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_rooms' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/rooms/(?P<id>\d+)/availability', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'check_availability' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id'        => [ 'required' => true, 'type' => 'integer' ],
				'check_in'  => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
				'check_out' => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
			],
		] );

		// Batch — evita 1 request HTTP por habitación en el flujo combinado
		// (DiscoveryFlow.jsx/RoomSearch.jsx, hallazgo real de rendimiento,
		// "perfeccionamiento del flujo combinado" 2026-08-08).
		register_rest_route( self::NAMESPACE, '/rooms/availability-batch', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'check_availability_batch' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'room_ids'  => [ 'required' => true, 'type' => 'array' ],
				'check_in'  => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
				'check_out' => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/room-bookings', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_booking' ],
			'permission_callback' => '__return_true',
			'args'                => $this->create_args(),
		] );
	}

	// ── GET /rooms ────────────────────────────────────────────────────────

	public function list_rooms( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, slug, name_es, name_en, description_es, description_en,
			        capacity_max, min_nights, price_per_night,
			        default_checkin_time, default_checkout_time, gallery_images,
			        amenities, video_url, sort_order
			 FROM {$wpdb->prefix}flow_rooms
			 WHERE status = 'active'
			 ORDER BY sort_order ASC, id ASC"
		) ?? [];

		$rooms = array_map( function ( $r ) {
			return [
				'id'                    => (int) $r->id,
				'slug'                  => $r->slug,
				'name_es'               => $r->name_es,
				'name_en'               => $r->name_en,
				'description_es'        => $r->description_es,
				'description_en'        => $r->description_en,
				'capacity_max'          => (int) $r->capacity_max,
				'min_nights'            => (int) $r->min_nights,
				'price_per_night'       => (float) $r->price_per_night,
				'default_checkin_time'  => $r->default_checkin_time,
				'default_checkout_time' => $r->default_checkout_time,
				'gallery_images'        => json_decode( $r->gallery_images ?: '[]', true ) ?: [],
				// Amenities/video (§ 16.11 CONTRIBUTING.md) — expuestos acá
				// también para que un frontend headless (SPEC-HEADLESS.md)
				// pueda armar la misma ficha sin tocar WordPress.
				'amenities'             => json_decode( $r->amenities ?: '[]', true ) ?: [],
				'video_url'             => $r->video_url ?: '',
			];
		}, $rows );

		return new \WP_REST_Response( $rooms, 200 );
	}

	// ── GET /rooms/{id}/availability ─────────────────────────────────────

	public function check_availability( \WP_REST_Request $request ): \WP_REST_Response {
		$room_id   = (int) $request->get_param( 'id' );
		$check_in  = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out = sanitize_text_field( $request->get_param( 'check_out' ) );

		if ( $check_out <= $check_in ) {
			return new \WP_REST_Response( [ 'error' => 'check_out debe ser posterior a check_in' ], 400 );
		}

		$available = RoomAvailability::is_available( $room_id, $check_in, $check_out );

		return new \WP_REST_Response( [ 'available' => $available ], 200 );
	}

	// ── POST /rooms/availability-batch ──────────────────────────────────────

	public function check_availability_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$room_ids  = array_map( 'intval', (array) $request->get_param( 'room_ids' ) );
		$check_in  = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out = sanitize_text_field( $request->get_param( 'check_out' ) );

		if ( $check_out <= $check_in ) {
			return new \WP_REST_Response( [ 'error' => 'check_out debe ser posterior a check_in' ], 400 );
		}
		if ( empty( $room_ids ) ) {
			return new \WP_REST_Response( [ 'availability' => [] ], 200 );
		}

		return new \WP_REST_Response( [
			'availability' => RoomAvailability::check_many( $room_ids, $check_in, $check_out ),
		], 200 );
	}

	// ── POST /room-bookings ───────────────────────────────────────────────

	public function create_booking( \WP_REST_Request $request ): \WP_REST_Response {
		if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'create_room_booking_' . \AmirBooking\Core\RateLimiter::client_ip() ) ) {
			return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
		}

		$result = ( new RoomBookingManager() )->create_pending( [
			'room_id'          => $request->get_param( 'room_id' ),
			'check_in'         => $request->get_param( 'check_in' ),
			'check_out'        => $request->get_param( 'check_out' ),
			'guests'           => $request->get_param( 'guests' ),
			'customer_name'    => $request->get_param( 'customer_name' ),
			'customer_email'   => $request->get_param( 'customer_email' ),
			'customer_phone'   => $request->get_param( 'customer_phone' ) ?? '',
			'lang'             => $request->get_param( 'lang' ) ?? 'es',
			'special_requests' => $request->get_param( 'special_requests' ) ?? '',
			'policy_accepted'  => (bool) $request->get_param( 'policy_accepted' ),
			'terms_accepted'   => (bool) $request->get_param( 'terms_accepted' ),
		] );

		if ( ! $result->success ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => $result->error ], 422 );
		}

		// Iniciar el cobro con la pasarela activa — mismo patrón que
		// BookingController::create_booking() para tours (reusa
		// PaymentGatewayFactory tal cual, RoomBookingResult extiende
		// BookingResult así que el type hint de create_payment() lo acepta).
		$gateway = \AmirBooking\Payments\PaymentGatewayFactory::default_gateway();
		try {
			$payment = $gateway->create_payment( $result );
		} catch ( \Throwable $e ) {
			$this->cleanup_failed_booking( $result->booking_id );
			\AmirBooking\Payments\PaymentEventLogger::log(
				$result->booking_id, $gateway->id(), 'creation_failed', $e->getMessage()
			);
			error_log( sprintf( 'TourFlow Rooms: excepción al crear el cobro (%s) — %s', $gateway->id(), $e->getMessage() ) );
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'Error al inicializar el pago. Intenta de nuevo.' ], 500 );
		}

		if ( ! $payment->success ) {
			$this->cleanup_failed_booking( $result->booking_id );
			\AmirBooking\Payments\PaymentEventLogger::log(
				$result->booking_id, $gateway->id(), 'creation_failed', $payment->error
			);
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'Error al inicializar el pago. Intenta de nuevo.' ], 500 );
		}

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}amir_bookings",
			[
				'stripe_payment_intent' => $payment->reference,
				'payment_gateway'       => $gateway->id(),
				'gateway_reference'     => $payment->reference,
			],
			[ 'id' => $result->booking_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		\AmirBooking\Payments\PaymentEventLogger::log(
			$result->booking_id, $gateway->id(), 'created', '', [ 'reference' => $payment->reference ]
		);

		return new \WP_REST_Response( array_merge( [
			'success'     => true,
			'booking_ref' => $result->booking_ref,
			'booking_id'  => $result->booking_id,
			'total_mxn'   => $result->total_mxn,
			'gateway'     => $gateway->id(),
		], $payment->client_payload ), 201 );
	}

	/**
	 * A diferencia de BookingController::cleanup_failed_booking() (que solo
	 * borra de amir_bookings), acá hay que borrar TAMBIÉN la fila de
	 * flow_room_bookings — si no queda huérfana bloqueando esas fechas para
	 * siempre sin ninguna reserva real detrás.
	 */
	private function cleanup_failed_booking( int $booking_id ): void {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}flow_room_bookings", [ 'booking_id' => $booking_id ], [ '%d' ] );
		$wpdb->delete( "{$wpdb->prefix}amir_bookings", [ 'id' => $booking_id ], [ '%d' ] );
	}

	private function create_args(): array {
		return [
			'room_id'          => [ 'required' => true,  'type' => 'integer', 'minimum' => 1 ],
			'check_in'         => [ 'required' => true,  'type' => 'string',  'format' => 'date' ],
			'check_out'        => [ 'required' => true,  'type' => 'string',  'format' => 'date' ],
			'guests'           => [ 'required' => true,  'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
			'customer_name'    => [ 'required' => true,  'type' => 'string',  'minLength' => 2 ],
			'customer_email'   => [ 'required' => true,  'type' => 'string',  'format' => 'email' ],
			'customer_phone'   => [ 'required' => false, 'type' => 'string' ],
			'lang'             => [ 'required' => false, 'type' => 'string', 'enum' => \AmirBooking\Core\Languages::active() ],
			'special_requests' => [ 'required' => false, 'type' => 'string' ],
			'policy_accepted'  => [ 'required' => false, 'type' => 'boolean' ],
			'terms_accepted'   => [ 'required' => false, 'type' => 'boolean' ],
		];
	}
}
