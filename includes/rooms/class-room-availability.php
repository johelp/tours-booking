<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

/**
 * Motor de disponibilidad de habitaciones (Pro Max, CONTRIBUTING.md § 16.5).
 * Deliberadamente separado de AmirBooking\Core\AvailabilityEngine (tours):
 * un tour es un día + horario, una habitación es un rango de noches con
 * reglas propias (choque de rango de fechas, no de horario).
 */
final class RoomAvailability {

	/**
	 * Intervalo semi-abierto [check_in, check_out): dos reservas de la misma
	 * habitación solo chocan si sus rangos de noches se superponen. El día
	 * de checkout de una reserva es el mismo día de check-in disponible
	 * para la siguiente (confirmado con el cliente 2026-07-31) — por eso la
	 * comparación es estrictamente < / > en vez de <= / >=.
	 */
	public static function has_conflict( string $check_in_a, string $check_out_a, string $check_in_b, string $check_out_b ): bool {
		return $check_in_a < $check_out_b && $check_out_a > $check_in_b;
	}

	/**
	 * Mismo chequeo que is_available() para varias habitaciones a la vez —
	 * evita que el frontend dispare un request HTTP por habitación (hallazgo
	 * real de rendimiento en el flujo combinado, "perfeccionamiento del
	 * flujo combinado" 2026-08-08): con N habitaciones eran N requests en
	 * paralelo solo para armar la grilla de resultados de una búsqueda.
	 * Server-side sigue siendo N queries (misma lógica de is_available(),
	 * sin reescribir a una sola query por ahora), pero eso es rápido en
	 * comparación con N round-trips de red — la ganancia real es pasar de
	 * N requests a 1. Devuelve [ room_id => bool ].
	 */
	public static function check_many( array $room_ids, string $check_in, string $check_out ): array {
		$result = [];
		foreach ( $room_ids as $room_id ) {
			$result[ (int) $room_id ] = self::is_available( (int) $room_id, $check_in, $check_out );
		}
		return $result;
	}

	/**
	 * true si $room_id no tiene ninguna reserva pending/confirmed cuyo rango
	 * choque con [$check_in, $check_out) Y la habitación está habilitada por
	 * temporada para TODAS las noches del rango (§ 16.11 CONTRIBUTING.md,
	 * 2026-08-01) — un cliente puede ofrecer una habitación solo en meses
	 * puntuales. $exclude_booking_id sirve para revalidar una reserva
	 * existente (ej. al reprogramar) sin que choque contra sí misma.
	 */
	public static function is_available( int $room_id, string $check_in, string $check_out, ?int $exclude_booking_id = null ): bool {
		if ( ! self::season_allows( $room_id, $check_in, $check_out ) ) {
			return false;
		}

		global $wpdb;

		$sql  = "SELECT check_in_date, check_out_date FROM {$wpdb->prefix}flow_room_bookings
		          WHERE room_id = %d AND status IN ('pending','confirmed')";
		$args = [ $room_id ];

		if ( $exclude_booking_id !== null ) {
			$sql   .= ' AND booking_id != %d';
			$args[] = $exclude_booking_id;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

		foreach ( $rows as $row ) {
			if ( self::has_conflict( $check_in, $check_out, $row->check_in_date, $row->check_out_date ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * true si CADA noche de [check_in, check_out) está permitida por las
	 * reglas de temporada de la habitación — si una sola noche del rango
	 * cae en un período cerrado, la reserva completa no es válida (no tiene
	 * sentido reservar "a medias" cruzando un cierre de temporada).
	 */
	public static function season_allows( int $room_id, string $check_in, string $check_out ): bool {
		$rules = self::get_rules( $room_id );
		if ( empty( $rules ) ) {
			return true;
		}

		$night = new \DateTime( $check_in );
		$end   = new \DateTime( $check_out );
		while ( $night < $end ) {
			if ( ! self::evaluate_rules_for_date( $rules, $night->format( 'Y-m-d' ) ) ) {
				return false;
			}
			$night->modify( '+1 day' );
		}

		return true;
	}

	/** Primera regla que aplica gana (mayor prioridad primero) — mismo criterio que AvailabilityEngine::evaluate_rules() para tours. */
	private static function evaluate_rules_for_date( array $rules, string $date ): bool {
		foreach ( $rules as $rule ) {
			if ( $rule->date_from && $date < $rule->date_from ) {
				continue;
			}
			if ( $rule->date_until && $date > $rule->date_until ) {
				continue;
			}
			return $rule->rule_type === 'allow';
		}
		return true; // sin reglas que apliquen: disponible por default
	}

	/** @return object[] Reglas ordenadas por prioridad descendente. */
	private static function get_rules( int $room_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT rule_type, date_from, date_until, priority
			 FROM {$wpdb->prefix}flow_room_availability_rules
			 WHERE room_id = %d ORDER BY priority DESC",
			$room_id
		) ) ?? [];
	}
}
