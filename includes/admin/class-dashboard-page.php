<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard operativo diario.
 * Vista principal del panel: qué tours salen hoy y mañana,
 * cuántas personas confirmadas, quiénes son, cuántos cupos quedan.
 * Diseñado para funcionar en tablet desde el muelle.
 */
class DashboardPage {

    public function render(): void {
        $today    = current_time( 'Y-m-d' );
        $tomorrow = date( 'Y-m-d', strtotime( '+1 day' ) );

        $today_data    = $this->get_day_summary( $today );
        $tomorrow_data = $this->get_day_summary( $tomorrow );
        $stats         = $this->get_period_stats();
        $notifications = $this->get_unread_notifications();

        ?>
        <div class="wrap ab-admin-wrap">
        <style>
        .ab-admin-wrap { max-width:1200px; }
        .ab-admin-wrap h1 { font-size:22px; font-weight:700; color:#1a2e24; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .ab-stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:28px; }
        .ab-stat-card { background:#fff; border:1px solid #e1f5ee; border-radius:10px; padding:16px 18px; }
        .ab-stat-card .label { font-size:12px; font-weight:600; color:#5a7068; text-transform:uppercase; letter-spacing:.4px; }
        .ab-stat-card .value { font-size:28px; font-weight:800; color:#1a2e24; margin:4px 0 2px; }
        .ab-stat-card .sub   { font-size:12px; color:#5a7068; }
        .ab-stat-card.green  { border-color:#1D9E75; background:#f0faf6; }

        .ab-day-section { margin-bottom:28px; }
        .ab-day-header { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
        .ab-day-label { font-size:16px; font-weight:700; color:#1a2e24; }
        .ab-day-badge { background:#1D9E75; color:#fff; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:.4px; }
        .ab-day-badge.tomorrow { background:#0F6E56; }
        .ab-day-empty { background:#f8fdfb; border:1px dashed #c3d9d0; border-radius:10px; padding:20px; text-align:center; font-size:13px; color:#5a7068; }

        .ab-tour-block { background:#fff; border:1px solid #e1f5ee; border-radius:10px; margin-bottom:12px; overflow:hidden; }
        .ab-tour-block-header { display:flex; align-items:center; gap:14px; padding:14px 18px; background:#f0faf6; border-bottom:1px solid #e1f5ee; cursor:pointer; }
        .ab-tour-block-header:hover { background:#e1f5ee; }
        .ab-tour-thumb { width:48px; height:48px; border-radius:8px; object-fit:cover; flex-shrink:0; background:#c3d9d0; }
        .ab-tour-name { font-size:15px; font-weight:700; color:#1a2e24; flex:1; }
        .ab-tour-time { font-size:13px; color:#5a7068; }
        .ab-tour-pax-pill { display:flex; align-items:center; gap:6px; background:#1D9E75; color:#fff; padding:5px 12px; border-radius:20px; font-size:13px; font-weight:700; flex-shrink:0; }
        .ab-tour-slots-pill { background:#e1f5ee; color:#0F6E56; padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; flex-shrink:0; }
        .ab-tour-slots-pill.low { background:#fff8e7; color:#BA7517; }
        .ab-tour-slots-pill.full { background:#fef2f2; color:#e24b4a; }

        .ab-bookings-table { width:100%; border-collapse:collapse; }
        .ab-bookings-table th { font-size:11px; font-weight:700; color:#5a7068; text-transform:uppercase; letter-spacing:.4px; padding:10px 16px; text-align:left; border-bottom:1px solid #e1f5ee; background:#fafafa; }
        .ab-bookings-table td { font-size:13px; color:#1a2e24; padding:10px 16px; border-bottom:1px solid #f5f5f5; vertical-align:middle; }
        .ab-bookings-table tr:last-child td { border-bottom:none; }
        .ab-bookings-table tr:hover td { background:#f8fdfb; }

        .ab-pax-breakdown { display:flex; gap:6px; }
        .ab-pax-chip { font-size:11px; padding:2px 7px; border-radius:12px; font-weight:600; }
        .ab-pax-chip.adult   { background:#e1f5ee; color:#0F6E56; }
        .ab-pax-chip.child   { background:#e8f4ff; color:#1a6fa8; }
        .ab-pax-chip.baby    { background:#f5f0ff; color:#6a3d9a; }

        .ab-status-chip { font-size:11px; padding:3px 8px; border-radius:12px; font-weight:600; }
        .ab-status-chip.confirmed { background:#e1f5ee; color:#0F6E56; }
        .ab-status-chip.pending   { background:#fff8e7; color:#BA7517; }
        .ab-status-chip.cancelled_client,.ab-status-chip.cancellation_requested { background:#fef2f2; color:#e24b4a; }

        .ab-source-chip { font-size:10px; padding:2px 6px; border-radius:8px; background:#f3f4f6; color:#5a7068; font-weight:600; text-transform:uppercase; }
        .ab-source-chip.partner { background:#fef3c7; color:#92400e; }
        .ab-source-chip.tripadvisor { background:#e8f4ff; color:#0066cc; }
        .ab-source-chip.getyourguide { background:#fff0e6; color:#cc4400; }

        .ab-notif-bar { background:#fff8e7; border:1px solid #fde68a; border-radius:10px; padding:12px 16px; margin-bottom:20px; }
        .ab-notif-bar .notif-title { font-size:13px; font-weight:700; color:#92400e; margin-bottom:6px; }
        .ab-notif-item { font-size:13px; color:#78350f; padding:3px 0; display:flex; align-items:center; gap:8px; }

        .ab-wa-btn { display:inline-flex; align-items:center; gap:5px; background:#25D366; color:#fff; border:none; border-radius:6px; padding:5px 10px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; }

        .ab-section-title { font-size:16px; font-weight:700; color:#1D9E75; margin:24px 0 12px; border-bottom:2px solid #e1f5ee; padding-bottom:8px; }
        </style>

        <h1>📅 <?php _e('Dashboard operativo', 'amir-booking'); ?>
          <span style="font-size:14px;font-weight:400;color:#5a7068;"><?php echo date_i18n( 'l j \d\e F Y', strtotime($today) ); ?></span>
        </h1>

        <?php if ( ! empty($notifications) ) : ?>
        <div class="ab-notif-bar">
          <div class="notif-title">⚠ <?php printf( _n('%d alerta pendiente', '%d alertas pendientes', count($notifications), 'amir-booking'), count($notifications) ); ?></div>
          <?php foreach ( $notifications as $n ) : ?>
            <div class="ab-notif-item">
              <?php echo $n->type === 'min_pax_alert' ? '👥' : '🔔'; ?>
              <?php echo esc_html($n->message); ?>
              <a href="<?php echo admin_url('admin.php?page=amir-bookings-list'); ?>" style="color:#1D9E75;font-size:12px;">Ver reservas →</a>
            </div>
          <?php endforeach; ?>
          <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('amir_mark_read'); ?>
            <input type="hidden" name="amir_action" value="mark_all_read" />
            <button type="submit" style="background:transparent;border:none;color:#92400e;font-size:12px;cursor:pointer;text-decoration:underline;padding:0;">Marcar todas como leídas</button>
          </form>
        </div>
        <?php endif; ?>

        <!-- Stats rápidas -->
        <div class="ab-stats-row">
          <div class="ab-stat-card green">
            <div class="label">Reservas hoy</div>
            <div class="value"><?php echo $today_data['total_bookings']; ?></div>
            <div class="sub"><?php echo $today_data['total_pax']; ?> personas confirmadas</div>
          </div>
          <div class="ab-stat-card">
            <div class="label">Reservas mañana</div>
            <div class="value"><?php echo $tomorrow_data['total_bookings']; ?></div>
            <div class="sub"><?php echo $tomorrow_data['total_pax']; ?> personas</div>
          </div>
          <div class="ab-stat-card">
            <div class="label">Ingresos este mes</div>
            <div class="value">$<?php echo number_format($stats['month_revenue'],0,'.',','); ?></div>
            <div class="sub"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?> · <?php echo $stats['month_bookings']; ?> reservas</div>
          </div>
          <div class="ab-stat-card">
            <div class="label">Pendientes de pago</div>
            <div class="value"><?php echo $stats['pending_count']; ?></div>
            <div class="sub">reservas en estado pending</div>
          </div>
        </div>

        <!-- HOY -->
        <div class="ab-day-section">
          <div class="ab-day-header">
            <span class="ab-day-label">Hoy — <?php echo date_i18n('j \d\e F', strtotime($today)); ?></span>
            <span class="ab-day-badge">HOY</span>
          </div>
          <?php $this->render_day_tours( $today_data['tours'] ); ?>
        </div>

        <!-- MAÑANA -->
        <div class="ab-day-section">
          <div class="ab-day-header">
            <span class="ab-day-label">Mañana — <?php echo date_i18n('j \d\e F', strtotime($tomorrow)); ?></span>
            <span class="ab-day-badge tomorrow">MAÑANA</span>
          </div>
          <?php $this->render_day_tours( $tomorrow_data['tours'] ); ?>
        </div>

        <!-- Próximas reservas pendientes de confirmación -->
        <?php
        $cancellation_requests = $this->get_cancellation_requests();
        if ( ! empty($cancellation_requests) ) : ?>
        <div class="ab-section-title">🚫 Solicitudes de cancelación (<?php echo count($cancellation_requests); ?>)</div>
        <div class="ab-tour-block">
          <table class="ab-bookings-table">
            <thead><tr>
              <th>Referencia</th><th>Tour</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $cancellation_requests as $b ) : ?>
              <tr>
                <td><strong><?php echo esc_html($b->booking_ref); ?></strong></td>
                <td><?php echo esc_html($b->tour_name); ?></td>
                <td><?php echo esc_html($b->tour_date); ?></td>
                <td>
                  <?php echo esc_html($b->customer_name); ?>
                  <?php if ($b->customer_phone) : ?>
                    <br><a class="ab-wa-btn" href="https://wa.me/<?php echo esc_attr(preg_replace('/[^0-9]/','',$b->customer_phone)); ?>">WhatsApp</a>
                  <?php endif; ?>
                </td>
                <td>$<?php echo number_format($b->total_mxn,0,'.',','); ?> MXN</td>
                <td>
                  <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>"
                     style="color:#1D9E75;font-size:12px;font-weight:600;">Ver detalle →</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        </div><!-- .wrap -->
        <?php

        // Manejar acción de marcar notificaciones como leídas
        if ( isset($_POST['amir_action']) && $_POST['amir_action'] === 'mark_all_read'
             && wp_verify_nonce($_POST['_wpnonce'], 'amir_mark_read') ) {
            global $wpdb;
            $wpdb->query("UPDATE {$wpdb->prefix}amir_notifications SET is_read=1");
        }
    }

    // ── Render bloque de tours de un día ──────────────────────────────────

    private function render_day_tours( array $tours ): void {
        if ( empty($tours) ) {
            echo '<div class="ab-day-empty">Sin tours programados para este día.</div>';
            return;
        }

        foreach ( $tours as $tour ) :
            $slots_class = $tour['slots_remaining'] <= 0 ? 'full'
                : ( $tour['slots_remaining'] <= 3 ? 'low' : '' );
            ?>
            <div class="ab-tour-block">
              <div class="ab-tour-block-header" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
                <?php if ( $tour['thumb'] ) : ?>
                  <img src="<?php echo esc_url($tour['thumb']); ?>" class="ab-tour-thumb" alt="" />
                <?php else : ?>
                  <div class="ab-tour-thumb" style="display:flex;align-items:center;justify-content:center;font-size:20px;">⛵</div>
                <?php endif; ?>

                <div class="ab-tour-name"><?php echo esc_html($tour['tour_name']); ?></div>
                <div class="ab-tour-time">🕐 <?php echo esc_html($tour['time']); ?></div>

                <div class="ab-tour-pax-pill">
                  👥 <?php echo $tour['confirmed_pax']; ?> personas
                </div>
                <?php if ( $tour['max_capacity'] > 0 ) : ?>
                <div class="ab-tour-slots-pill <?php echo $slots_class; ?>">
                  <?php
                  if ( $tour['slots_remaining'] <= 0 ) echo 'LLENO';
                  elseif ( $tour['max_capacity'] > 0 ) echo $tour['slots_remaining'] . ' cupos libres';
                  ?>
                </div>
                <?php endif; ?>

                <span style="color:#5a7068;font-size:18px;margin-left:4px;">▾</span>
              </div>

              <!-- Lista de reservas del tour -->
              <div style="display:<?php echo $tour['confirmed_pax'] > 0 ? 'block' : 'none'; ?>">
                <?php if ( empty($tour['bookings']) ) : ?>
                  <p style="padding:12px 16px;font-size:13px;color:#5a7068;margin:0;">Sin reservas confirmadas.</p>
                <?php else : ?>
                <table class="ab-bookings-table">
                  <thead><tr>
                    <th>Reserva</th>
                    <th>Cliente</th>
                    <th>Personas</th>
                    <th>Idioma</th>
                    <th>Origen</th>
                    <th>Contacto</th>
                    <th>Notas</th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ( $tour['bookings'] as $b ) : ?>
                    <tr>
                      <td>
                        <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>"
                           style="font-weight:700;color:#1D9E75;"><?php echo esc_html($b->booking_ref); ?></a>
                        <span class="ab-status-chip <?php echo esc_attr($b->status); ?>"><?php echo esc_html($b->status); ?></span>
                      </td>
                      <td>
                        <strong><?php echo esc_html($b->customer_name); ?></strong><br>
                        <span style="font-size:12px;color:#5a7068;"><?php echo esc_html($b->customer_email); ?></span>
                      </td>
                      <td>
                        <div class="ab-pax-breakdown">
                          <?php if ($b->adults)   : ?><span class="ab-pax-chip adult"><?php echo $b->adults; ?> adult.</span><?php endif; ?>
                          <?php if ($b->children) : ?><span class="ab-pax-chip child"><?php echo $b->children; ?> niños</span><?php endif; ?>
                          <?php if ($b->babies)   : ?><span class="ab-pax-chip baby"><?php echo $b->babies; ?> bebés</span><?php endif; ?>
                        </div>
                      </td>
                      <td><?php echo strtoupper($b->lang); ?></td>
                      <td><span class="ab-source-chip <?php echo esc_attr($b->booking_source); ?>"><?php echo esc_html($b->booking_source); ?></span></td>
                      <td>
                        <?php if ($b->customer_phone) : ?>
                          <a class="ab-wa-btn" href="https://wa.me/<?php echo esc_attr(preg_replace('/[^0-9]/','',$b->customer_phone)); ?>?text=<?php echo urlencode('Hola '.$b->customer_name.', te recordamos tu tour ' . $b->tour_name . ' mañana. ¡Nos vemos!'); ?>" target="_blank">
                            💬 WA
                          </a>
                        <?php endif; ?>
                      </td>
                      <td style="max-width:140px;">
                        <?php if ($b->special_requests) : ?>
                          <span style="font-size:12px;color:#5a7068;" title="<?php echo esc_attr($b->special_requests); ?>">
                            📝 <?php echo esc_html(mb_substr($b->special_requests,0,30)); ?>…
                          </span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
                <?php endif; ?>
              </div>
            </div>
            <?php
        endforeach;
    }

    // ── Queries ───────────────────────────────────────────────────────────

    private function get_day_summary( string $date ): array {
        global $wpdb;

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, t.name_es as tour_name, s.time_start, s.time_end,
                    t.max_capacity, t.gallery_images
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             WHERE b.tour_date = %s
               AND b.status IN ('confirmed','cancellation_requested')
             ORDER BY s.time_start ASC, t.name_es ASC",
            $date
        ) ?? [] );

        // Agrupar por tour+horario
        $tours_map = [];
        foreach ( $bookings as $b ) {
            $key = $b->tour_id . '_' . $b->schedule_id;
            if ( ! isset($tours_map[$key]) ) {
                // Obtener imagen del CPT
                $post = get_posts(['post_type'=>\AmirBooking\CPT\TourPostType::POST_TYPE,'meta_key'=>'_amir_tour_db_id','meta_value'=>$b->tour_id,'posts_per_page'=>1]);
                $thumb = $post ? get_the_post_thumbnail_url($post[0]->ID,'thumbnail') : '';

                $confirmed_sum = (int)$wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(adults+children+babies),0) FROM {$wpdb->prefix}amir_bookings
                     WHERE tour_id=%d AND schedule_id=%d AND tour_date=%s AND status IN ('confirmed','cancellation_requested')",
                    $b->tour_id, $b->schedule_id, $date
                ) );

                $tours_map[$key] = [
                    'tour_id'         => $b->tour_id,
                    'schedule_id'     => $b->schedule_id,
                    'tour_name'       => $b->tour_name,
                    'time'            => $this->fmt_time($b->time_start) . ' – ' . $this->fmt_time($b->time_end),
                    'max_capacity'    => (int)$b->max_capacity,
                    'confirmed_pax'   => $confirmed_sum,
                    'slots_remaining' => max(0, (int)$b->max_capacity - $confirmed_sum),
                    'thumb'           => $thumb,
                    'bookings'        => [],
                ];
            }
            $tours_map[$key]['bookings'][] = $b;
        }

        $total_pax      = array_sum( array_column( $tours_map, 'confirmed_pax' ) );
        $total_bookings = count( $bookings );

        return [
            'tours'          => array_values($tours_map),
            'total_pax'      => $total_pax,
            'total_bookings' => $total_bookings,
        ];
    }

    private function get_period_stats(): array {
        global $wpdb;
        $month_start = date('Y-m-01');
        $month_end   = date('Y-m-t');

        $month_revenue = (float)$wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total_mxn),0) FROM {$wpdb->prefix}amir_bookings
             WHERE status IN ('confirmed','completed') AND tour_date BETWEEN %s AND %s",
            $month_start, $month_end
        ) );
        $month_bookings = (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings
             WHERE status IN ('confirmed','completed') AND tour_date BETWEEN %s AND %s",
            $month_start, $month_end
        ) );
        $pending_count = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings WHERE status='pending'"
        );

        return compact('month_revenue','month_bookings','pending_count');
    }

    private function get_cancellation_requests(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT b.*, t.name_es as tour_name
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             WHERE b.status = 'cancellation_requested'
             ORDER BY b.created_at DESC"
        ) ?? [];
    }

    private function get_unread_notifications(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}amir_notifications
             WHERE is_read=0 ORDER BY created_at DESC LIMIT 10"
        ) ?? [];
    }

    private function fmt_time( string $t ): string {
        [$h,$m] = explode(':',$t);
        $h = (int)$h;
        return ($h>12?$h-12:($h?:12)).':'.$m.($h>=12?' PM':' AM');
    }
}
