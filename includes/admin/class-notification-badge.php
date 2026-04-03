<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Badge de notificaciones en el menú de admin.
 * Se actualiza via AJAX para mantener el contador en tiempo real.
 */
class NotificationBadge {

    public function register(): void {
        add_action( 'wp_ajax_amir_get_notifications',      [ $this, 'get_notifications' ] );
        add_action( 'wp_ajax_amir_mark_notifications_read', [ $this, 'mark_read'         ] );
    }

    public function get_notifications(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $notifications = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}amir_notifications
             WHERE is_read = 0
             ORDER BY created_at DESC
             LIMIT 20"
        );

        wp_send_json_success( [
            'count'         => count( $notifications ?? [] ),
            'notifications' => $notifications ?? [],
        ] );
    }

    public function mark_read(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $ids = array_map( 'intval', $_POST['ids'] ?? [] );

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

        wp_send_json_success();
    }
}
