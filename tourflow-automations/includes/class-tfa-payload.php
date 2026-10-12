<?php
namespace TourFlowAutomations;

defined( 'ABSPATH' ) || exit;

/**
 * Arma el JSON de cada evento. Forma FIJA: mismas claves en todos los eventos,
 * los campos que no aplican van null — así un Zap / escenario de Make / flujo
 * de n8n se arma una sola vez y sirve para todos los eventos.
 *
 * Lista blanca de campos a propósito: `amir_bookings` tiene tokens y IDs de
 * pasarela (access_token, stripe_*, gateway_*, provider_response_token…) que
 * nunca salen de este plugin hacia un tercero.
 */
final class Payload {

	/**
	 * @return array<string,mixed>|null null si la reserva no existe.
	 */
	public static function build( int $booking_id, string $event, array $extra = [] ): ?array {
		$b = ( new \AmirBooking\Core\BookingManager() )->get_booking( $booking_id );
		if ( ! $b ) {
			return null;
		}

		// Reservas anonimizadas (derecho al olvido): los datos personales ya no existen.
		$anon = ! empty( $b->anonymized_at );
		$lang = $b->lang === 'en' ? 'en' : 'es';

		return self::envelope( $event, [
			'id'             => (int) $b->id,
			'booking_ref'    => $b->booking_ref,
			'item_type'      => $b->item_type,
			'status'         => $b->status,
			'tour_id'        => $b->tour_id !== null ? (int) $b->tour_id : null,
			'tour_name'      => self::tour_name( $b->tour_id !== null ? (int) $b->tour_id : 0, $lang ),
			'room_id'        => $b->room_id !== null ? (int) $b->room_id : null,
			'room_name'      => self::room_name( $b->room_id !== null ? (int) $b->room_id : 0 ),
			'tour_date'      => $b->tour_date,
			'check_out_date' => $b->check_out_date ?: null,
			'customer_name'  => $anon ? null : $b->customer_name,
			'customer_email' => $anon ? null : $b->customer_email,
			'customer_phone' => $anon ? null : $b->customer_phone,
			'adults'         => (int) $b->adults,
			'children'       => (int) $b->children,
			'babies'         => (int) $b->babies,
			'total'          => (float) $b->total_mxn,
			'currency'       => \AmirBooking\Core\Currency::code(),
			'coupon_code'    => $b->coupon_code ?: null,
			'discount'       => (float) $b->discount_mxn,
			'deposit_pct'    => $b->deposit_pct !== null ? (int) $b->deposit_pct : null,
			'lang'           => $lang,
			'confirmed_at'   => $b->confirmed_at ?: null,
		], $extra );
	}

	/**
	 * Ejemplo con datos ficticios para el botón "Enviar prueba" del admin.
	 */
	public static function sample( string $event ): array {
		return self::envelope( $event, [
			'id'             => 0,
			'booking_ref'    => 'TF-PRUEBA-00000',
			'item_type'      => 'tour',
			'status'         => 'confirmed',
			'tour_id'        => 1,
			'tour_name'      => 'Tour de prueba',
			'room_id'        => null,
			'room_name'      => null,
			'tour_date'      => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			'check_out_date' => null,
			'customer_name'  => 'Cliente de prueba',
			'customer_email' => 'prueba@example.com',
			'customer_phone' => '+10000000000',
			'adults'         => 2,
			'children'       => 0,
			'babies'         => 0,
			'total'          => 1500.0,
			'currency'       => \AmirBooking\Core\Currency::code(),
			'coupon_code'    => null,
			'discount'       => 0.0,
			'deposit_pct'    => null,
			'lang'           => 'es',
			'confirmed_at'   => null,
		], [ 'test' => true ] );
	}

	/** Eventos del núcleo que este plugin sabe traducir a payload. */
	public static function events(): array {
		return [
			'amir_booking_confirmed'                => 'Reserva confirmada',
			'flow_room_booking_confirmed'           => 'Habitación confirmada (Pro Max)',
			'amir_booking_pending_provider_approval' => 'Esperando aprobación del proveedor',
			'amir_booking_cancelled'                => 'Reserva cancelada',
			'amir_booking_date_requested'           => 'Solicitud de fecha',
			'amir_booking_rescheduled'              => 'Reserva reprogramada',
			'flow_room_booking_rescheduled'         => 'Habitación reprogramada (Pro Max)',
			'amir_booking_payment_failed'           => 'Pago rechazado (queda en plazo de gracia)',
			'amir_booking_balance_paid'             => 'Saldo cobrado',
		];
	}

	private static function envelope( string $event, array $booking, array $extra ): array {
		return [
			'event'     => $event,
			'site_url'  => home_url(),
			'site_name' => get_bloginfo( 'name' ),
			'timestamp' => gmdate( 'c' ),
			'test'      => ! empty( $extra['test'] ),
			'extra'     => array_diff_key( $extra, [ 'test' => true ] ) ?: null,
			'booking'   => $booking,
		];
	}

	private static function tour_name( int $tour_id, string $lang ): ?string {
		if ( ! $tour_id ) {
			return null;
		}
		global $wpdb;
		$col  = $lang === 'en' ? 'name_en' : 'name_es';
		$name = $wpdb->get_var( $wpdb->prepare(
			"SELECT {$col} FROM {$wpdb->prefix}amir_tours WHERE id = %d",
			$tour_id
		) );
		return $name !== null ? (string) $name : null;
	}

	private static function room_name( int $room_id ): ?string {
		if ( ! $room_id ) {
			return null;
		}
		// Las habitaciones son CPT flow_room; su ID de tabla vive en postmeta.
		global $wpdb;
		$title = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.post_title FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_flow_room_db_id'
			 WHERE m.meta_value = %d AND p.post_type = 'flow_room' LIMIT 1",
			$room_id
		) );
		return $title !== null ? (string) $title : null;
	}
}
