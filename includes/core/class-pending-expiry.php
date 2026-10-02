<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Vencimiento de reservas 'pending' que nunca se pagaron (tarjeta rechazada
 * y abandonada, pestaña cerrada en el paso de pago, etc.).
 *
 * Antes esto vivía solo en el cron horario (`BookingManager::
 * release_expired_pending()`), con tres consecuencias reales:
 *  - Una reserva impaga retenía el cupo hasta ~1 hora más de lo configurado
 *    (`amir_pending_expire_mins`, 15 por defecto) — los queries de
 *    disponibilidad cuentan todo `pending` sin mirar su antigüedad.
 *  - Pasaba a 'cancelled_client', indistinguible de una cancelación real
 *    (con reembolso) — imposible distinguir después una reserva vencida por
 *    falta de pago de una cancelada por el cliente, así que un pago que
 *    llegaba tarde no se podía recuperar sin riesgo de "resucitar" una
 *    reserva ya reembolsada. Ahora tiene su propio estado, 'payment_expired'.
 *  - El uso del cupón consumido al crearla nunca se devolvía.
 *
 * Además de correr desde el cron, se ejecuta de forma perezosa (limitada a
 * una vez por minuto) desde los caminos que deciden disponibilidad, así el
 * cupo se libera apenas vence el plazo sin depender de que el cron corra.
 */
final class PendingExpiry {

	private const THROTTLE_KEY = 'amir_pending_expiry_ran';

	/** Minutos que una reserva 'pending' retiene el cupo sin pago (default 15). */
	public static function expire_mins(): int {
		return max( 1, (int) get_option( 'amir_pending_expire_mins', 15 ) );
	}

	/** Plazo de gracia (minutos) tras un pago rechazado; 0 = desactivado. */
	public static function grace_mins(): int {
		return max( 0, (int) get_option( 'amir_payment_grace_mins', 360 ) );
	}

	/**
	 * Timestamp (mismo huso que current_time('timestamp')) hasta el que una
	 * reserva 'pending' retiene el cupo.
	 *
	 * Sin pago rechazado: created_at + amir_pending_expire_mins. Con un pago
	 * rechazado (`payment_failed_at`, ver BookingManager::
	 * record_payment_failure()): se le da al cliente un plazo de gracia para
	 * actualizar la tarjeta — como hacen Vrbo/Booking con una tarjeta
	 * inválida — que nunca baja del vencimiento normal y nunca se estira
	 * hasta el día del tour (el cupo de una salida inminente no se congela).
	 *
	 * @param object $row Necesita created_at, payment_failed_at y tour_date.
	 */
	public static function hold_deadline( object $row ): int {
		$normal = (int) strtotime( (string) $row->created_at ) + ( self::expire_mins() * 60 );

		$failed_at = (string) ( $row->payment_failed_at ?? '' );
		$grace     = self::grace_mins();
		if ( $failed_at === '' || $grace <= 0 ) {
			return $normal;
		}

		$grace_end = (int) strtotime( $failed_at ) + ( $grace * 60 );
		$tour_day  = (int) strtotime( (string) $row->tour_date . ' 00:00:00' );

		return max( $normal, min( $grace_end, $tour_day ) );
	}

	/**
	 * @return int Cantidad de reservas vencidas en esta corrida.
	 */
	public static function release( bool $throttle = false ): int {
		global $wpdb;

		if ( $throttle ) {
			if ( get_transient( self::THROTTLE_KEY ) ) {
				return 0;
			}
			set_transient( self::THROTTLE_KEY, 1, 60 );
		}

		$now = (int) current_time( 'timestamp' );
		// current_time() para respetar la zona horaria configurada en WordPress
		$cutoff = date( 'Y-m-d H:i:s', $now - ( self::expire_mins() * 60 ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, coupon_code, created_at, payment_failed_at, tour_date FROM {$wpdb->prefix}amir_bookings
			 WHERE status = 'pending' AND created_at < %s
			 ORDER BY created_at ASC
			 LIMIT 500",
			$cutoff
		) );

		$released = 0;
		foreach ( (array) $rows as $row ) {
			// Reserva con un pago rechazado dentro de su plazo de gracia: sigue
			// reteniendo el cupo hasta hold_deadline().
			if ( $now < self::hold_deadline( $row ) ) {
				continue;
			}

			// UPDATE condicionado al status — si un webhook/confirm-payment
			// confirmó esta reserva justo ahora, no se le pisa el estado.
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}amir_bookings
				 SET status = 'payment_expired',
				     internal_notes = CONCAT( COALESCE( internal_notes, '' ), %s )
				 WHERE id = %d AND status = 'pending'",
				"\n[" . current_time( 'mysql' ) . '] Pago no completado a tiempo — reserva vencida.',
				(int) $row->id
			) );

			if ( ! $claimed ) {
				continue;
			}
			$released++;

			// Devolver el uso del cupón consumido al crear la reserva.
			$code = strtoupper( trim( (string) ( $row->coupon_code ?? '' ) ) );
			if ( $code !== '' ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}amir_coupons
					 SET times_used = GREATEST( times_used - 1, 0 )
					 WHERE code = %s",
					$code
				) );
			}

			do_action( 'amir_booking_payment_expired', (int) $row->id );
		}

		return $released;
	}
}
