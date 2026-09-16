<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints REST de disponibilidad.
 *
 * GET /wp-json/amir/v1/availability/month?tour_id=X&year=Y&month=M
 * GET /wp-json/amir/v1/availability/day?tour_id=X&date=YYYY-MM-DD
 * GET /wp-json/amir/v1/availability/schedules?tour_id=X&date=YYYY-MM-DD
 */
class AvailabilityController {

    private const NAMESPACE = 'amir/v1';

    public function register_routes(): void {

        register_rest_route( self::NAMESPACE, '/availability/month', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_month' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'tour_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
                'year'    => [ 'required' => true, 'type' => 'integer', 'minimum' => 2024 ],
                'month'   => [ 'required' => true, 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/availability/day', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_day' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'tour_id' => [ 'required' => true, 'type' => 'integer' ],
                'date'    => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/availability/schedules', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_schedules' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'tour_id' => [ 'required' => true, 'type' => 'integer' ],
                'date'    => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
            ],
        ] );
    }

    // ── GET /availability/month ───────────────────────────────────────────

    public function get_month( \WP_REST_Request $request ): \WP_REST_Response {
        $tour_id = (int) $request->get_param( 'tour_id' );
        $year    = (int) $request->get_param( 'year' );
        $month   = (int) $request->get_param( 'month' );

        // Caché de 5 minutos en transients
        $cache_key = "amir_avail_{$tour_id}_{$year}_{$month}";
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return rest_ensure_response( $cached );
        }

        $engine = new \AmirBooking\Core\AvailabilityEngine();
        $data   = $engine->get_month_availability( $tour_id, $year, $month );

        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

        return rest_ensure_response( $data );
    }

    // ── GET /availability/day ─────────────────────────────────────────────

    public function get_day( \WP_REST_Request $request ): \WP_REST_Response {
        $tour_id = (int) $request->get_param( 'tour_id' );
        $date    = sanitize_text_field( $request->get_param( 'date' ) );

        if ( ! $this->is_valid_date( $date ) ) {
            return new \WP_REST_Response( [ 'error' => 'Fecha inválida' ], 400 );
        }

        global $wpdb;
        $schedules = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, time_start, time_end, label_es, label_en
                 FROM {$wpdb->prefix}amir_tour_schedules
                 WHERE tour_id = %d AND active = 1
                 ORDER BY sort_order ASC, time_start ASC",
                $tour_id
            )
        );

        $engine  = new \AmirBooking\Core\AvailabilityEngine();
        $pricing = new \AmirBooking\Core\PricingEngine();
        $result  = [];

        $lang = sanitize_key( $request->get_param( 'lang' ) ?: 'es' );

        foreach ( $schedules as $schedule ) {
            $check    = $engine->check( $tour_id, $date, (int) $schedule->id );
            $result[] = [
                'id'             => (int) $schedule->id,
                'time_start'     => $schedule->time_start,
                'time_end'       => $schedule->time_end,
                'label'          => $lang === 'en' ? $schedule->label_en : $schedule->label_es,
                'label_es'       => $schedule->label_es,
                'label_en'       => $schedule->label_en,
                'available'      => $check->available,
                'slots_remaining'=> $check->slots_remaining,
                'reason'         => $check->reason,
            ];
        }

        return rest_ensure_response( $result );
    }

    // ── GET /availability/schedules ───────────────────────────────────────

    public function get_schedules( \WP_REST_Request $request ): \WP_REST_Response {
        return $this->get_day( $request );
    }

    private function is_valid_date( string $date ): bool {
        $d = \DateTime::createFromFormat( 'Y-m-d', $date );
        return $d && $d->format( 'Y-m-d' ) === $date;
    }
}
