<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gestión de tareas programadas (cron jobs de WordPress).
 *
 * Tareas horarias:
 *   - Liberar reservas pending expiradas
 *   - Actualizar tipo de cambio USD/MXN
 *
 * Tareas diarias (7 AM):
 *   - Verificar mínimo de pasajeros para tours de los próximos 2 días
 *   - Enviar recordatorios pre-tour (24h antes)
 *   - Enviar solicitudes de reseña (1-2 días post-tour)
 *   - Marcar tours completados
 */
class CronManager {

    public function register(): void {
        add_action( 'amir_hourly_tasks', [ $this, 'run_hourly_tasks'  ] );
        add_action( 'amir_daily_tasks',  [ $this, 'run_daily_tasks'   ] );
    }

    // ── Tareas horarias ───────────────────────────────────────────────────

    public function run_hourly_tasks(): void {
        $this->release_expired_pending();
        $this->refresh_exchange_rate();
    }

    // ── Tareas diarias ────────────────────────────────────────────────────

    public function run_daily_tasks(): void {
        $this->check_minimum_passengers();
        $this->send_tour_reminders();
        $this->send_review_requests();
        $this->mark_completed_bookings();
    }

    // ── Liberar reservas expiradas ────────────────────────────────────────

    private function release_expired_pending(): void {
        $manager = new BookingManager();
        $manager->release_expired_pending();
    }

    // ── Actualizar tipo de cambio ─────────────────────────────────────────

    private function refresh_exchange_rate(): void {
        if ( get_option( 'amir_usd_rate_mode', 'auto' ) !== 'auto' ) {
            return;
        }
        // Borra el transient para forzar re-fetch en la próxima consulta
        delete_transient( 'amir_usd_mxn_rate' );
    }

    // ── Verificar mínimo de pasajeros ─────────────────────────────────────

    /**
     * Revisa los tours de los próximos 2 días.
     * Si alguno no alcanza el mínimo, notifica al admin y opcionalmente cancela.
     */
    private function check_minimum_passengers(): void {
        global $wpdb;

        $check_dates = [
            date( 'Y-m-d', strtotime( '+1 day' ) ),
            date( 'Y-m-d', strtotime( '+2 days' ) ),
        ];

        foreach ( $check_dates as $date ) {
            $tours_that_day = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT
                        b.tour_id,
                        b.schedule_id,
                        t.name_es,
                        t.name_en,
                        t.min_passengers,
                        SUM(b.adults + b.children + b.babies) as confirmed_pax
                     FROM {$wpdb->prefix}amir_bookings b
                     JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                     WHERE b.tour_date = %s
                       AND b.status = 'confirmed'
                     GROUP BY b.tour_id, b.schedule_id",
                    $date
                )
            );

            foreach ( $tours_that_day as $tour ) {
                if ( (int) $tour->confirmed_pax < (int) $tour->min_passengers ) {
                    $this->notify_min_pax_not_reached( $tour, $date );
                }
            }
        }
    }

    private function notify_min_pax_not_reached( object $tour, string $date ): void {
        global $wpdb;

        // Crear notificación en panel admin
        $wpdb->insert(
            "{$wpdb->prefix}amir_notifications",
            [
                'type'    => 'min_pax_alert',
                'title'   => 'Alerta: mínimo de pasajeros',
                'message' => sprintf(
                    '%s el %s — %d/%d pasajeros confirmados',
                    $tour->name_es,
                    $date,
                    $tour->confirmed_pax,
                    $tour->min_passengers
                ),
                'data'    => json_encode( [
                    'tour_id'     => $tour->tour_id,
                    'schedule_id' => $tour->schedule_id,
                    'date'        => $date,
                ] ),
                'is_read' => 0,
            ],
            [ '%s', '%s', '%s', '%s', '%d' ]
        );

        // Email al admin
        $admin_email = get_option( 'amir_admin_email' );
        if ( $admin_email ) {
            wp_mail(
                $admin_email,
                sprintf( '[Amir Booking] Alerta mínimo pax: %s el %s', $tour->name_es, $date ),
                sprintf(
                    "El tour '%s' el %s tiene %d de %d pasajeros mínimos.\n\nRevisar en el panel: %s",
                    $tour->name_es,
                    $date,
                    $tour->confirmed_pax,
                    $tour->min_passengers,
                    admin_url( 'admin.php?page=amir-booking' )
                )
            );
        }
    }

    // ── Recordatorios pre-tour ────────────────────────────────────────────

    private function send_tour_reminders(): void {
        global $wpdb;

        $tomorrow = date( 'Y-m-d', strtotime( '+1 day' ) );

        // Reservas confirmadas para mañana sin recordatorio enviado
        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, t.name_es, t.name_en, s.time_start,
                        t.meeting_point_es, t.meeting_point_en, t.meeting_lat, t.meeting_lng
                 FROM {$wpdb->prefix}amir_bookings b
                 JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
                 WHERE b.tour_date = %s
                   AND b.status = 'confirmed'
                   AND b.reminder_sent_at IS NULL",
                $tomorrow
            )
        );

        foreach ( $bookings as $booking ) {
            do_action( 'amir_send_reminder_email', $booking );

            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'reminder_sent_at' => current_time( 'mysql' ) ],
                [ 'id' => $booking->id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    // ── Solicitudes de reseña ─────────────────────────────────────────────

    private function send_review_requests(): void {
        global $wpdb;

        $delay_days = (int) get_option( 'amir_review_delay_days', 1 );
        $target_date = date( 'Y-m-d', strtotime( "-{$delay_days} days" ) );

        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, t.name_es, t.name_en
                 FROM {$wpdb->prefix}amir_bookings b
                 JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                 WHERE b.tour_date = %s
                   AND b.status IN ('confirmed', 'completed')
                   AND b.review_email_sent_at IS NULL
                   AND b.booking_source = 'direct'",
                $target_date
            )
        );

        foreach ( $bookings as $booking ) {
            do_action( 'amir_send_review_email', $booking );

            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'review_email_sent_at' => current_time( 'mysql' ) ],
                [ 'id' => $booking->id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    // ── Marcar tours completados ──────────────────────────────────────────

    private function mark_completed_bookings(): void {
        $manager = new BookingManager();
        $manager->mark_completed_bookings();
    }
}
