<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Motor de disponibilidad.
 *
 * Evalúa si una fecha/horario está disponible para un tour
 * aplicando reglas en orden de prioridad y verificando cupos.
 *
 * Uso:
 *   $engine = new AvailabilityEngine();
 *   $result = $engine->check( tour_id: 3, date: '2026-06-18', schedule_id: 7 );
 *   // $result->available (bool), $result->slots_remaining (int), $result->reason (string)
 */
class AvailabilityEngine {

    // Caché en memoria para la misma request (evita queries repetidas)
    private array $rules_cache   = [];
    private array $bookings_cache = [];

    // ── API pública ───────────────────────────────────────────────────────

    /**
     * Verifica disponibilidad de una fecha + horario específico.
     */
    public function check( int $tour_id, string $date, int $schedule_id ): AvailabilityResult {
        $tour = $this->get_tour( $tour_id );
        if ( ! $tour ) {
            return AvailabilityResult::unavailable( 'Tour no encontrado', 0 );
        }

        // 1. Evaluar reglas de disponibilidad
        $rule_result = $this->evaluate_rules( $tour_id, $date );
        if ( ! $rule_result ) {
            return AvailabilityResult::unavailable( 'Fecha no disponible', 0 );
        }

        // 2. No reservar en el pasado
        if ( $date < current_time( 'Y-m-d' ) ) {
            return AvailabilityResult::unavailable( 'Fecha pasada', 0 );
        }

        // 3. Si no hay schedule_id, verificar solo capacidad máxima global
        if ( $schedule_id === 0 ) {
            $max = (int) $tour->max_capacity;
            if ( $max <= 0 ) {
                return AvailabilityResult::available( PHP_INT_MAX );
            }
            // Contar reservas del día sin importar horario
            global $wpdb;
            $booked = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(adults+children+babies),0) FROM {$wpdb->prefix}amir_bookings
                 WHERE tour_id=%d AND tour_date=%s AND status IN ('pending','confirmed')",
                $tour_id, $date
            ) );
            $remaining = $max - $booked;
            return $remaining > 0
                ? AvailabilityResult::available( $remaining )
                : AvailabilityResult::unavailable( 'Sin cupos', 0 );
        }

        // 4. Verificar capacidad según modelo de precio
        if ( $tour->price_model === 'group' ) {
            return $this->check_group_capacity( $tour_id, $schedule_id, $date );
        }

        return $this->check_percapita_capacity( $tour, $schedule_id, $date );
    }

    /**
     * Devuelve todos los días disponibles de un mes para el calendario.
     * Retorna array [ 'YYYY-MM-DD' => [ 'available' => bool, 'slots' => int ] ]
     */
    public function get_month_availability( int $tour_id, int $year, int $month ): array {
        $tour = $this->get_tour( $tour_id );
        if ( ! $tour ) {
            return [];
        }

        $schedules = $this->get_schedules( $tour_id );
        $result    = [];
        $today     = current_time( 'Y-m-d' );

        $days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );

        for ( $day = 1; $day <= $days_in_month; $day++ ) {
            $date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

            // Saltar fechas pasadas
            if ( $date < $today ) {
                $result[ $date ] = [ 'available' => false, 'slots' => 0, 'reason' => 'past' ];
                continue;
            }

            // Evaluar reglas
            if ( ! $this->evaluate_rules( $tour_id, $date ) ) {
                $result[ $date ] = [ 'available' => false, 'slots' => 0, 'reason' => 'blocked' ];
                continue;
            }

            // Si no hay horarios configurados, la fecha está disponible sin límite de cupos
            if ( empty( $schedules ) ) {
                $result[ $date ] = [ 'available' => true, 'slots' => 99, 'reason' => 'available' ];
                continue;
            }

            // Calcular cupos disponibles (sumamos de todos los horarios del día)
            $total_slots = 0;
            $available   = false;

            foreach ( $schedules as $schedule ) {
                $check = $this->check( $tour_id, $date, (int) $schedule->id );
                if ( $check->available ) {
                    $available    = true;
                    $total_slots += $check->slots_remaining;
                }
            }

            $result[ $date ] = [
                'available' => $available,
                'slots'     => $total_slots,
                'reason'    => $available ? 'available' : 'full',
            ];
        }

        return $result;
    }

    /**
     * Cupos disponibles en un horario específico (para mostrar en el selector).
     */
    public function get_schedule_slots( int $tour_id, int $schedule_id, string $date ): int {
        $result = $this->check( $tour_id, $date, $schedule_id );
        return $result->available ? $result->slots_remaining : 0;
    }

    // ── Evaluación de reglas ──────────────────────────────────────────────

    /**
     * Evalúa las reglas de disponibilidad para una fecha dada.
     * Retorna true si la fecha está permitida, false si está bloqueada.
     *
     * Algoritmo:
     *  1. Carga reglas ordenadas por priority DESC
     *  2. Primera regla que aplica (fecha dentro del rango Y día de semana coincide) = resultado
     *  3. Si ninguna aplica = comportamiento por defecto (true)
     */
    private function evaluate_rules( int $tour_id, string $date ): bool {
        $rules   = $this->get_rules( $tour_id );
        $weekday = (int) date( 'w', strtotime( $date ) ); // 0=dom, 6=sáb

        foreach ( $rules as $rule ) {
            if ( ! $this->rule_applies_to_date( $rule, $date, $weekday ) ) {
                continue;
            }
            // Primera regla que aplica gana (mayor prioridad primero)
            return $rule->rule_type === 'allow';
        }

        // Sin reglas que apliquen: disponible por defecto
        return true;
    }

    private function rule_applies_to_date( object $rule, string $date, int $weekday ): bool {
        // Verificar rango de fechas (NULL = siempre)
        if ( $rule->date_from && $date < $rule->date_from ) {
            return false;
        }
        if ( $rule->date_until && $date > $rule->date_until ) {
            return false;
        }

        // Verificar día de semana
        $weekdays = json_decode( $rule->weekdays ?? '[]', true );
        if ( empty( $weekdays ) ) {
            // Sin restricción de día → aplica a todos los días del rango
            return true;
        }

        return in_array( $weekday, $weekdays, true );
    }

    // ── Verificación de capacidad ─────────────────────────────────────────

    /**
     * Modelo per-capita: suma personas de reservas confirmadas vs. max_capacity.
     */
    private function check_percapita_capacity( object $tour, int $schedule_id, string $date ): AvailabilityResult {
        $booked = $this->get_booked_pax( (int) $tour->id, $schedule_id, $date );
        $max    = (int) $tour->max_capacity;

        if ( $max <= 0 ) {
            // Sin límite configurado = siempre disponible
            return AvailabilityResult::available( PHP_INT_MAX );
        }

        $remaining = $max - $booked;

        if ( $remaining <= 0 ) {
            return AvailabilityResult::unavailable( 'Sin cupos', 0 );
        }

        return AvailabilityResult::available( $remaining );
    }

    /**
     * Modelo grupo (catamarán): si hay UNA reserva confirmada = horario bloqueado.
     */
    private function check_group_capacity( int $tour_id, int $schedule_id, string $date ): AvailabilityResult {
        $booked_count = $this->get_confirmed_bookings_count( $tour_id, $schedule_id, $date );

        if ( $booked_count > 0 ) {
            return AvailabilityResult::unavailable( 'Horario reservado (tour privado)', 0 );
        }

        return AvailabilityResult::available( 1 ); // 1 = este slot de grupo disponible
    }

    // ── Queries con caché ────────────────────────────────────────────────

    private function get_tour( int $tour_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, price_model, max_capacity, min_passengers, status
                 FROM {$wpdb->prefix}amir_tours
                 WHERE id = %d AND status = 'active'",
                $tour_id
            )
        );
    }

    private function get_rules( int $tour_id ): array {
        if ( isset( $this->rules_cache[ $tour_id ] ) ) {
            return $this->rules_cache[ $tour_id ];
        }

        global $wpdb;
        $rules = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rule_type, weekdays, date_from, date_until, priority
                 FROM {$wpdb->prefix}amir_availability_rules
                 WHERE tour_id = %d
                 ORDER BY priority DESC",
                $tour_id
            )
        );

        $this->rules_cache[ $tour_id ] = $rules ?? [];
        return $this->rules_cache[ $tour_id ];
    }

    private function get_schedules( int $tour_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}amir_tour_schedules
                 WHERE tour_id = %d AND active = 1
                 ORDER BY sort_order ASC, time_start ASC",
                $tour_id
            )
        ) ?? [];
    }

    /**
     * Total de personas (adultos + niños + bebés) en reservas confirmadas.
     */
    private function get_booked_pax( int $tour_id, int $schedule_id, string $date ): int {
        $cache_key = "{$tour_id}_{$schedule_id}_{$date}";
        if ( isset( $this->bookings_cache[ $cache_key ] ) ) {
            return $this->bookings_cache[ $cache_key ];
        }

        global $wpdb;
        $result = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE( SUM(adults + children + babies), 0 )
                 FROM {$wpdb->prefix}amir_bookings
                 WHERE tour_id    = %d
                   AND schedule_id = %d
                   AND tour_date   = %s
                   AND status IN ('pending','confirmed')",
                $tour_id,
                $schedule_id,
                $date
            )
        );

        $this->bookings_cache[ $cache_key ] = $result;
        return $result;
    }

    private function get_confirmed_bookings_count( int $tour_id, int $schedule_id, string $date ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->prefix}amir_bookings
                 WHERE tour_id    = %d
                   AND schedule_id = %d
                   AND tour_date   = %s
                   AND status IN ('pending','confirmed')",
                $tour_id,
                $schedule_id,
                $date
            )
        );
    }
}

/**
 * Value object con el resultado de una verificación de disponibilidad.
 */
/**
 * Value object with availability check result. PHP 7.4+ compatible.
 */
class AvailabilityResult {

    /** @var bool */
    public $available;
    /** @var int */
    public $slots_remaining;
    /** @var string */
    public $reason;

    private function __construct( bool $available, int $slots_remaining, string $reason = '' ) {
        $this->available       = $available;
        $this->slots_remaining = $slots_remaining;
        $this->reason          = $reason;
    }

    public static function available( int $slots ) {
        return new self( true, $slots, 'available' );
    }

    public static function unavailable( string $reason, int $slots = 0 ) {
        return new self( false, $slots, $reason );
    }

    public function to_array() {
        return [
            'available'       => $this->available,
            'slots_remaining' => $this->slots_remaining,
            'reason'          => $this->reason,
        ];
    }
}
