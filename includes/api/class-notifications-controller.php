<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint REST de notificaciones del admin.
 *
 * El bundle de admin (assets/js/admin.js) consume estas rutas vía REST
 * (usa rest_url()/wp_rest nonce, ver AdminMenu::enqueue_admin_assets()).
 * class-notification-badge.php expone la misma funcionalidad por
 * admin-ajax.php — se mantiene por compatibilidad, pero el bundle
 * compilado que está en el repo ya no la usa, solo llama a REST.
 *
 * GET  /amir/v1/notifications            → notificaciones no leídas
 * POST /amir/v1/notifications/mark-read  → marca como leídas (todas si no se pasan ids)
 */
class NotificationsController {

    private const NAMESPACE = 'amir/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/notifications', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_notifications' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/notifications/mark-read', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'mark_read' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    public function check_permission(): bool {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_amir_booking' );
    }

    public function get_notifications(): \WP_REST_Response {
        global $wpdb;

        $notifications = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}amir_notifications
             WHERE is_read = 0
             ORDER BY created_at DESC
             LIMIT 20"
        );

        return rest_ensure_response( [
            'count'         => count( $notifications ?? [] ),
            'notifications' => $notifications ?? [],
        ] );
    }

    public function mark_read( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $ids = array_map( 'intval', (array) $request->get_param( 'ids' ) ?: [] );

        if ( empty( $ids ) ) {
            $wpdb->query( "UPDATE {$wpdb->prefix}amir_notifications SET is_read = 1" );
        } else {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}amir_notifications SET is_read = 1 WHERE id IN ($placeholders)",
                    ...$ids
                )
            );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }
}
