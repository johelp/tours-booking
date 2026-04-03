<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/amir/v1/prices?tour_id=X&date=YYYY-MM-DD
 * Devuelve los precios vigentes para un tour en una fecha dada.
 * Usado por el widget para actualizar el precio en tiempo real.
 */
class PricesController {

    private const NAMESPACE = 'amir/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/prices', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_prices' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'tour_id'     => [ 'required' => true, 'type' => 'integer' ],
                'schedule_id' => [ 'required' => false, 'type' => 'integer' ],
                'date'        => [ 'required' => false, 'type' => 'string' ],
            ],
        ] );
    }

    public function get_prices( \WP_REST_Request $req ): \WP_REST_Response {
        $tour_id     = (int) $req->get_param( 'tour_id' );
        $schedule_id = (int) ( $req->get_param( 'schedule_id' ) ?? 0 );
        $date        = sanitize_text_field( $req->get_param( 'date' ) ?? current_time('Y-m-d') );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT person_type, group_min, group_max, price_mxn, schedule_id
             FROM {$wpdb->prefix}amir_prices
             WHERE tour_id = %d
               AND ( schedule_id = %d OR schedule_id IS NULL )
               AND ( valid_from  IS NULL OR valid_from  <= %s )
               AND ( valid_until IS NULL OR valid_until >= %s )
             ORDER BY
               CASE WHEN schedule_id = %d THEN 0 ELSE 1 END,
               CASE WHEN valid_from IS NOT NULL THEN 0 ELSE 1 END",
            $tour_id, $schedule_id, $date, $date, $schedule_id
        ) ) ?? [];

        $pricing = new \AmirBooking\Core\PricingEngine();

        return rest_ensure_response( [
            'prices'        => array_map( fn($p) => [
                'person_type' => $p->person_type,
                'group_min'   => $p->group_min  ? (int)$p->group_min  : null,
                'group_max'   => $p->group_max  ? (int)$p->group_max  : null,
                'price_mxn'   => (float)$p->price_mxn,
                'schedule_id' => $p->schedule_id ? (int)$p->schedule_id : null,
            ], $rows ),
            'exchange_rate' => $pricing->get_exchange_rate(),
        ] );
    }
}
