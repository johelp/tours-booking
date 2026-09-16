<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

/**
 * Crea reservas de habitación (Pro Max, CONTRIBUTING.md § 16). Deliberadamente
 * separado de AmirBooking\Core\BookingManager::create_pending() (que es
 * ~200 líneas de lógica específica de tours: horarios, addons, cupones,
 * edad) — pero escribe en la MISMA tabla amir_bookings (item_type='room'),
 * así reusa tal cual el gateway de pago, los webhooks, los emails y la
 * pantalla admin de Reservas, sin duplicar esa infraestructura. Decisión
 * del cliente 2026-07-31.
 */
final class RoomBookingManager {

	public function create_pending( array $data ): RoomBookingResult {
		global $wpdb;

		$room_id   = (int) ( $data['room_id'] ?? 0 );
		$check_in  = (string) ( $data['check_in'] ?? '' );
		$check_out = (string) ( $data['check_out'] ?? '' );
		$guests    = max( 1, (int) ( $data['guests'] ?? 1 ) );

		if ( ! $room_id || ! $check_in || ! $check_out ) {
			return RoomBookingResult::error( 'Faltan datos de la reserva.' );
		}
		if ( $check_out <= $check_in ) {
			return RoomBookingResult::error( 'La fecha de checkout debe ser posterior al check-in.' );
		}

		if ( empty( $data['policy_accepted'] ) ) {
			return RoomBookingResult::error( 'Debes aceptar la política de cancelación para continuar.' );
		}
		if ( empty( $data['terms_accepted'] ) ) {
			return RoomBookingResult::error( 'Debes aceptar los términos y condiciones para continuar.' );
		}

		$room = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}flow_rooms WHERE id = %d AND status = 'active'", $room_id
		) );
		if ( ! $room ) {
			return RoomBookingResult::error( 'La habitación no existe o no está disponible.' );
		}

		if ( $guests > (int) $room->capacity_max ) {
			return RoomBookingResult::error( sprintf( 'Esta habitación admite hasta %d huésped(es).', $room->capacity_max ) );
		}

		$nights = (int) round( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS );
		if ( $nights < (int) $room->min_nights ) {
			return RoomBookingResult::error( sprintf( 'La estadía mínima en esta habitación es de %d noche(s).', $room->min_nights ) );
		}

		if ( ! RoomAvailability::is_available( $room_id, $check_in, $check_out ) ) {
			return RoomBookingResult::error( 'La habitación ya no está disponible para esas fechas.' );
		}

		$total = round( $nights * (float) $room->price_per_night, 2 );

		// Cupones — mismo motor que tours, generalizado 2026-08-04 (§ 16.21
		// CONTRIBUTING.md). Igual criterio que PricingEngine::apply_coupon()
		// para tours: un cupón inválido NUNCA bloquea la reserva, solo no
		// descuenta nada — el error queda accesible pero no corta el flujo.
		$coupon_code   = sanitize_text_field( $data['coupon_code'] ?? '' );
		$coupon_id     = 0;
		$discount_mxn  = 0.0;
		if ( $coupon_code !== '' ) {
			$coupons        = new \AmirBooking\Core\CouponEngine();
			$coupon_result  = $coupons->validate( $coupon_code, 0, $room_id );
			if ( $coupon_result->valid ) {
				$discount_mxn = $coupons->calculate_discount( $coupon_result->coupon, $total );
				$coupon_id    = (int) $coupon_result->coupon->id;
				$total        = round( $total - $discount_mxn, 2 );
			}
		}

		$booking_ref  = ( new \AmirBooking\Core\BookingManager() )->generate_ref();
		$access_token = \AmirBooking\Core\BookingManager::generate_access_token();

		$wpdb->query( 'START TRANSACTION' );

		// Bloquea la fila de la habitación (FOR UPDATE) — serializa
		// cualquier otro intento de reserva concurrente sobre la MISMA
		// habitación hasta el commit/rollback. Sin esto, dos requests
		// simultáneos podrían pasar el re-chequeo de abajo antes de que
		// ninguno de los dos haya insertado todavía (race condition clásica
		// de "check-then-act") — mismo problema que el FOR UPDATE sobre
		// amir_tours en BookingManager::create_pending() para tours,
		// adaptado acá a nivel de fila en vez de a nivel de SUM de cupos.
		$wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}flow_rooms WHERE id = %d FOR UPDATE", $room_id
		) );

		if ( ! RoomAvailability::is_available( $room_id, $check_in, $check_out ) ) {
			$wpdb->query( 'ROLLBACK' );
			return RoomBookingResult::error( 'La habitación ya no está disponible para esas fechas.' );
		}

		$inserted = $wpdb->insert( "{$wpdb->prefix}amir_bookings", [
			'booking_ref'       => $booking_ref,
			'access_token'      => $access_token,
			'item_type'         => 'room',
			'room_id'           => $room_id,
			'tour_date'         => $check_in,
			'check_out_date'    => $check_out,
			'status'            => 'pending',
			'booking_source'    => 'direct',
			'lang'              => $data['lang'] ?? 'es',
			'customer_name'     => sanitize_text_field( $data['customer_name'] ?? '' ),
			'customer_email'    => sanitize_email( $data['customer_email'] ?? '' ),
			'customer_phone'    => sanitize_text_field( $data['customer_phone'] ?? '' ),
			'adults'            => $guests,
			'children'          => 0,
			'babies'            => 0,
			'total_mxn'         => $total,
			'coupon_code'       => $coupon_id > 0 ? strtoupper( trim( $coupon_code ) ) : '',
			'special_requests'  => sanitize_textarea_field( $data['special_requests'] ?? '' ),
			'created_at'        => current_time( 'mysql' ),
		] + \AmirBooking\Core\BookingManager::consent_snapshot( $data['lang'] ?? 'es' ) );

		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return RoomBookingResult::error( 'Error al guardar la reserva. Por favor intenta de nuevo.' );
		}

		$booking_id = $wpdb->insert_id;

		$room_booking_inserted = $wpdb->insert( "{$wpdb->prefix}flow_room_bookings", [
			'room_id'        => $room_id,
			'booking_id'     => $booking_id,
			'check_in_date'  => $check_in,
			'check_out_date' => $check_out,
			'guests'         => $guests,
			'status'         => 'pending',
		] );

		if ( ! $room_booking_inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return RoomBookingResult::error( 'Error al guardar la reserva. Por favor intenta de nuevo.' );
		}

		$wpdb->query( 'COMMIT' );

		// mark_used() recién después del commit — mismo criterio que
		// BookingManager::create_pending() (nunca contar un uso de un
		// cupón si la reserva termina fallando).
		if ( $coupon_id > 0 ) {
			( new \AmirBooking\Core\CouponEngine() )->mark_used( $coupon_id );
		}

		$result = new RoomBookingResult( true, $booking_id, $booking_ref, $total );

		// Cupón que cubre el 100% del total (Dudas de producto, CLAUDE.md) —
		// mismo fix que BookingManager::create_pending() para tours: nace
		// 'pending' igual que siempre y se confirma con confirm() apenas se
		// inserta, en vez de intentar un cobro de $0 que Stripe rechaza.
		if ( $total <= 0 ) {
			( new \AmirBooking\Core\BookingManager() )->confirm( $booking_id, 'coupon-100pct' );
			$result->requires_payment = false;
			$result->status           = 'confirmed';
		}

		return $result;
	}

	/**
	 * Cambia el rango de fechas de una reserva de habitación ya cargada —
	 * antes de esto no existía ninguna forma de reprogramar una habitación
	 * (a diferencia de BookingManager::reschedule() para tours). Mismo
	 * criterio que ese método: no toca el monto cobrado (`total_mxn` queda
	 * igual) — si cambia la cantidad de noches, el ajuste de precio se
	 * coordina manualmente, no se recobra/reembolsa solo.
	 */
	public function reschedule( int $booking_id, string $new_check_in, string $new_check_out ): RoomBookingResult {
		global $wpdb;

		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d AND item_type = 'room'", $booking_id
		) );
		if ( ! $booking ) {
			return RoomBookingResult::error( 'Reserva no encontrada.' );
		}
		if ( $new_check_out <= $new_check_in ) {
			return RoomBookingResult::error( 'La fecha de checkout debe ser posterior al check-in.' );
		}

		$room = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}flow_rooms WHERE id = %d", (int) $booking->room_id
		) );
		if ( ! $room ) {
			return RoomBookingResult::error( 'La habitación ya no existe.' );
		}

		$nights = (int) round( ( strtotime( $new_check_out ) - strtotime( $new_check_in ) ) / DAY_IN_SECONDS );
		if ( $nights < (int) $room->min_nights ) {
			return RoomBookingResult::error( sprintf( 'La estadía mínima en esta habitación es de %d noche(s).', $room->min_nights ) );
		}

		// Excluye esta MISMA reserva del re-chequeo — si no, chocaría contra
		// su propio rango de fechas actual.
		if ( ! RoomAvailability::is_available( (int) $booking->room_id, $new_check_in, $new_check_out, $booking_id ) ) {
			return RoomBookingResult::error( 'La habitación no tiene disponibilidad para esas fechas.' );
		}

		$wpdb->update(
			"{$wpdb->prefix}amir_bookings",
			[ 'tour_date' => $new_check_in, 'check_out_date' => $new_check_out ],
			[ 'id' => $booking_id ]
		);
		$wpdb->update(
			"{$wpdb->prefix}flow_room_bookings",
			[ 'check_in_date' => $new_check_in, 'check_out_date' => $new_check_out ],
			[ 'booking_id' => $booking_id ]
		);

		do_action( 'flow_room_booking_rescheduled', $booking_id );

		return new RoomBookingResult( true, $booking_id, $booking->booking_ref, (float) $booking->total_mxn );
	}
}
