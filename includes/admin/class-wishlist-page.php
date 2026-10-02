<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Lista de interés ("avísame cuando abra") — tours en borrador que
 * acumulan reservas reales (estado 'wishlist') antes de tener fechas
 * confirmadas para el público. Ver BookingManager::create_wishlist().
 *
 * También cubre el caso de que el tour se publique desde el editor normal
 * de WordPress (sin pasar por el botón "Publicar y notificar" de acá): en
 * ese caso queda una sección aparte para notificar a los interesados y
 * pasarlos a 'awaiting_payment' sin volver a tocar el estado del tour.
 */
class WishlistPage {

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
        }

        $this->handle_actions();

        $message = get_transient( 'amir_wishlist_message' );
        if ( $message ) {
            delete_transient( 'amir_wishlist_message' );
        }

        global $wpdb;

        // Tours en borrador con wishlist activa
        $drafts = $wpdb->get_results(
            "SELECT id, name_es, wishlist_threshold, wishlist_date,
                    ( SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings b WHERE b.tour_id = t.id AND b.status = 'wishlist' ) AS interest_count
             FROM {$wpdb->prefix}amir_tours t
             WHERE status = 'draft' AND wishlist_enabled = 1
             ORDER BY interest_count DESC"
        ) ?? [];

        // Tours ya activos con reservas 'wishlist' que nunca pasaron a
        // 'awaiting_payment' (típicamente porque se publicaron desde el
        // editor normal de WP en vez de usar el botón de acá)
        $pending_notice = $wpdb->get_results(
            "SELECT t.id, t.name_es,
                    ( SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings b WHERE b.tour_id = t.id AND b.status = 'wishlist' ) AS pending_count
             FROM {$wpdb->prefix}amir_tours t
             WHERE t.status = 'active'
               AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}amir_bookings b WHERE b.tour_id = t.id AND b.status = 'wishlist' )
             ORDER BY t.name_es"
        ) ?? [];

        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1100px;">
        <?php $this->styles(); ?>
        <h1>📋 <?php echo esc_html( $this->tt( 'Lista de interés', 'Waitlist' ) ); ?></h1>
        <p style="color:#5a7068;font-size:13px;margin-top:-4px;">
          <?php echo wp_kses_post( $this->tt(
            'Tours en borrador con fecha ya definida donde la gente se anota — con horario, personas y datos, como una reserva real, pero sin pagar. Se activa por tour desde <strong>TourFlow → Tours → editar tour → "Lista de interés"</strong>.',
            'Draft tours with a date already set where people sign up — with schedule, people, and details, like a real booking, but without paying. It\'s enabled per tour from <strong>TourFlow → Tours → edit tour → "Waitlist"</strong>.'
          ) ); ?>
        </p>

        <?php if ( $message ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! empty( $pending_notice ) ) : ?>
          <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
            <h3 style="margin:0 0 10px;color:#92400e;font-size:14px;">⚠️ <?php echo esc_html( $this->tt( 'Tours publicados con reservas de interés sin avisar', 'Published tours with waitlist bookings not yet notified' ) ); ?></h3>
            <p style="font-size:12px;color:#78350f;margin:0 0 12px;">
              <?php echo esc_html( $this->tt(
                'Estos tours ya están activos (se publicaron desde el editor normal) pero todavía hay reservas en estado "wishlist" que no recibieron el link de pago.',
                'These tours are already active (published from the normal editor) but there are still bookings in "wishlist" status that haven\'t received the payment link.'
              ) ); ?>
            </p>
            <?php foreach ( $pending_notice as $t ) : ?>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-top:1px solid #fde68a;">
                <span style="font-size:13px;"><strong><?php echo esc_html( $t->name_es ); ?></strong> — <?php echo esc_html( sprintf( $this->tt( '%d sin avisar', '%d not notified' ), (int) $t->pending_count ) ); ?></span>
                <form method="post">
                  <?php wp_nonce_field( 'amir_wishlist_action' ); ?>
                  <input type="hidden" name="amir_action" value="notify_only" />
                  <input type="hidden" name="tour_id" value="<?php echo (int) $t->id; ?>" />
                  <button type="submit" class="button button-primary button-small"><?php echo esc_html( $this->tt( 'Notificar ahora', 'Notify now' ) ); ?></button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ( empty( $drafts ) ) : ?>
          <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:32px;text-align:center;color:#5a7068;">
            <?php echo esc_html( $this->tt( 'No hay tours en borrador con lista de interés activa todavía.', 'No draft tours with the waitlist enabled yet.' ) ); ?>
          </div>
        <?php else : foreach ( $drafts as $t ) :
            $count     = (int) $t->interest_count;
            $threshold = (int) $t->wishlist_threshold;
            $pct       = $threshold > 0 ? min( 100, round( $count / $threshold * 100 ) ) : 0;
            $contacts  = $wpdb->get_results( $wpdb->prepare(
                "SELECT b.id, b.booking_ref, b.customer_name, b.customer_email, b.customer_phone,
                        b.adults, b.children, b.babies, b.total_mxn, b.created_at,
                        s.label_es AS schedule_label, s.time_start
                 FROM {$wpdb->prefix}amir_bookings b
                 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
                 WHERE b.tour_id = %d AND b.status = 'wishlist'
                 ORDER BY b.created_at DESC",
                $t->id
            ) ) ?? [];
        ?>
          <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 24px;margin-bottom:18px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
              <div>
                <h3 style="margin:0 0 4px;color:#1a2e24;"><?php echo esc_html( $t->name_es ); ?></h3>
                <span style="font-size:13px;color:#5a7068;">
                  📅 <?php echo esc_html( $t->wishlist_date ? mysql2date( 'd/m/Y', $t->wishlist_date ) : $this->tt( '— sin fecha —', '— no date —' ) ); ?>
                  · <?php echo esc_html( $count === 1 ? $this->tt( '1 anotado', '1 signed up' ) : sprintf( $this->tt( '%d anotados', '%d signed up' ), $count ) ); ?>
                  <?php if ( $threshold > 0 ) : ?>
                    · <?php echo esc_html( sprintf( $this->tt( 'umbral: %d', 'threshold: %d' ), $threshold ) ); ?>
                  <?php endif; ?>
                </span>
              </div>
              <div style="display:flex;gap:8px;">
                <button type="button" class="button button-small" onclick="document.getElementById('amir-wl-contacts-<?php echo (int) $t->id; ?>').classList.toggle('amir-hidden');">
                  <?php echo esc_html( $this->tt( 'Ver anotados', 'View sign-ups' ) ); ?>
                </button>
                <form method="post" onsubmit="return confirm('<?php echo esc_js( sprintf( $this->tt( '¿Publicar este tour y mandar el link de pago a las %d reservas anotadas?', 'Publish this tour and send the payment link to the %d bookings signed up?' ), $count ) ); ?>');">
                  <?php wp_nonce_field( 'amir_wishlist_action' ); ?>
                  <input type="hidden" name="amir_action" value="open_and_notify" />
                  <input type="hidden" name="tour_id" value="<?php echo (int) $t->id; ?>" />
                  <button type="submit" class="button button-primary button-small" <?php disabled( $count === 0 ); ?>>
                    <?php echo esc_html( $this->tt( 'Publicar y notificar', 'Publish and notify' ) ); ?>
                  </button>
                </form>
              </div>
            </div>

            <?php if ( $threshold > 0 ) : ?>
              <div style="background:#f0f9f5;border-radius:6px;height:8px;margin-top:14px;overflow:hidden;">
                <div style="background:#1D9E75;height:100%;width:<?php echo (int) $pct; ?>%;"></div>
              </div>
            <?php endif; ?>

            <div id="amir-wl-contacts-<?php echo (int) $t->id; ?>" class="amir-hidden" style="margin-top:16px;">
              <?php if ( empty( $contacts ) ) : ?>
                <p style="font-size:13px;color:#5a7068;"><?php echo esc_html( $this->tt( 'Todavía nadie se anotó.', 'No one has signed up yet.' ) ); ?></p>
              <?php else : ?>
                <table style="width:100%;border-collapse:collapse;">
                  <thead><tr style="background:#f8fdfb;">
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Referencia', 'Reference' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Nombre', 'Name' ) ); ?></th>
                    <th class="ab-th">Email</th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Horario', 'Schedule' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Personas', 'People' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Total', 'Total' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Anotado', 'Signed up' ) ); ?></th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ( $contacts as $c ) :
                    $pax = (int) $c->adults + (int) $c->children + (int) $c->babies; ?>
                    <tr style="border-bottom:1px solid #f5f5f5;">
                      <td class="ab-td"><code style="font-size:11px;"><?php echo esc_html( $c->booking_ref ); ?></code></td>
                      <td class="ab-td"><?php echo esc_html( $c->customer_name ); ?></td>
                      <td class="ab-td"><?php echo esc_html( $c->customer_email ); ?></td>
                      <td class="ab-td" style="font-size:12px;"><?php echo esc_html( $c->schedule_label ?: ( $c->time_start ?: '—' ) ); ?></td>
                      <td class="ab-td"><?php echo $pax; ?></td>
                      <td class="ab-td"><?php echo esc_html( \AmirBooking\Core\Currency::format( (float) $c->total_mxn ) ); ?></td>
                      <td class="ab-td" style="font-size:12px;color:#5a7068;"><?php echo esc_html( mysql2date( 'd/m/Y', $c->created_at ) ); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
        </div>
        <?php
    }

    private function handle_actions(): void {
        if ( empty( $_POST['amir_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_wishlist_action' ) ) {
            return;
        }

        $action  = sanitize_key( $_POST['amir_action'] );
        $tour_id = absint( $_POST['tour_id'] ?? 0 );
        if ( ! $tour_id || ! in_array( $action, [ 'open_and_notify', 'notify_only' ], true ) ) {
            return;
        }

        global $wpdb;
        $tour = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_tours WHERE id = %d", $tour_id
        ) );
        if ( ! $tour ) {
            return;
        }

        if ( $action === 'open_and_notify' ) {
            $post = get_posts( [
                'post_type'      => 'amir_tour',
                'post_status'    => 'any',
                'numberposts'    => 1,
                'meta_key'       => '_amir_tour_db_id',
                'meta_value'     => $tour_id,
            ] );

            if ( empty( $post ) ) {
                set_transient( 'amir_wishlist_message', $this->tt( 'No se encontró el tour en WordPress — publícalo manualmente desde Tours.', 'The tour was not found in WordPress — publish it manually from Tours.' ), 30 );
                return;
            }

            // Publicar el post dispara el hook sync_to_db del CPT, que
            // actualiza amir_tours.status a 'active' automáticamente.
            wp_update_post( [ 'ID' => $post[0]->ID, 'post_status' => 'publish' ] );
        }

        $notified = $this->notify_interested( $tour );

        set_transient(
            'amir_wishlist_message',
            $action === 'open_and_notify'
                ? sprintf( $this->tt( 'Tour publicado y %d reserva(s) notificada(s) con su link de pago.', 'Tour published and %d booking(s) notified with their payment link.' ), $notified )
                : sprintf( $this->tt( '%d reserva(s) notificada(s) con su link de pago.', '%d booking(s) notified with their payment link.' ), $notified ),
            30
        );
    }

    /**
     * Pasa cada reserva 'wishlist' del tour a 'awaiting_payment' y le manda
     * el link de pago real (verify_url() con booking_ref + access_token).
     */
    private function notify_interested( object $tour ): int {
        global $wpdb;
        $pending = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*,
                    CASE WHEN b.lang = 'en' THEN t.name_en ELSE t.name_es END AS tour_name
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             WHERE b.tour_id = %d AND b.status = 'wishlist'",
            $tour->id
        ) ) ?? [];

        if ( empty( $pending ) ) {
            return 0;
        }

        $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
        $notified   = 0;

        foreach ( $pending as $booking ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'status' => 'awaiting_payment' ],
                [ 'id' => $booking->id ],
                [ '%s' ], [ '%d' ]
            );
            $booking->status = 'awaiting_payment';

            $result = $dispatcher->send_tour_opened_notice( $booking );
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                $result['success']
                    ? [ 'wishlist_notice_sent_at' => current_time( 'mysql' ), 'wishlist_notice_error' => '' ]
                    : [ 'wishlist_notice_error' => substr( $result['error'] ?: 'Error desconocido', 0, 255 ) ],
                [ 'id' => $booking->id ],
                null, [ '%d' ]
            );
            $notified++;
        }

        return $notified;
    }

    private function styles(): void {
        echo '<style>
        .ab-admin-wrap{max-width:1100px}
        .ab-th{font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;padding:8px 10px;text-align:left}
        .ab-td{font-size:13px;padding:8px 10px;vertical-align:middle}
        .amir-hidden{display:none}
        </style>';
    }
}
