<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de gestión de reservas en el admin de WordPress.
 *
 * - Listado con filtros (tour, fecha, estado, origen, búsqueda)
 * - Vista de detalle completa de una reserva
 * - Acciones manuales: confirmar, marcar cancelación aprobada/rechazada,
 *   reprogramar, agregar nota interna, reenviar email de confirmación
 */
class BookingsPage {

    public function render(): void {
        $action = sanitize_key( $_GET['action'] ?? 'list' );

        // El export CSV debe correr antes de que WordPress envíe HTML
        if ( $action === 'export' && current_user_can('manage_options') ) {
            $this->export_csv( $this->get_filters() );
            // export_csv termina con exit
        }

        // Descarga del voucher PDF desde el admin
        if ( $action === 'pdf' && ! empty($_GET['id']) ) {
            $this->stream_pdf( (int)$_GET['id'] );
            // stream_pdf termina con exit
        }

        if ( $action === 'view' && ! empty($_GET['id']) ) {
            $this->render_detail( (int)$_GET['id'] );
        } else {
            $this->render_list();
        }
    }

    // ── Listado ───────────────────────────────────────────────────────────

    private function render_list(): void {
        // Procesar acciones POST
        $this->handle_bulk_action();

        $filters  = $this->get_filters();
        $bookings = $this->query_bookings( $filters );
        $total    = $this->count_bookings( $filters );
        $per_page = 25;
        $page     = max(1, (int)($_GET['paged'] ?? 1));
        $tours    = $this->get_tours_for_filter();

        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->admin_styles(); ?>
        <h1 style="display:flex;align-items:center;justify-content:space-between;">
          <span>📋 Reservas <span style="font-size:14px;font-weight:400;color:#5a7068;">(<?php echo $total; ?> total)</span></span>
          <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=export'.$this->filter_query_string($filters)); ?>"
             class="button">⬇ Exportar CSV</a>
        </h1>

        <!-- Filtros -->
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:20px;background:#f8fdfb;padding:14px 16px;border-radius:10px;border:1px solid #e1f5ee;">
          <input type="hidden" name="page" value="amir-bookings-list" />
          <input type="hidden" name="action" value="list" />

          <input type="text" name="search" value="<?php echo esc_attr($filters['search']); ?>"
                 placeholder="Nombre, email, referencia…"
                 style="<?php echo $this->input_style(); ?> width:220px;" />

          <select name="tour_id" style="<?php echo $this->input_style(); ?>">
            <option value="">— Todos los tours —</option>
            <?php foreach ($tours as $t) : ?>
              <option value="<?php echo $t->id; ?>" <?php selected($filters['tour_id'], $t->id); ?>><?php echo esc_html($t->name_es); ?></option>
            <?php endforeach; ?>
          </select>

          <select name="status" style="<?php echo $this->input_style(); ?>">
            <option value="">— Todos los estados —</option>
            <?php foreach ($this->status_labels() as $k=>$v) : ?>
              <option value="<?php echo $k; ?>" <?php selected($filters['status'],$k); ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
          </select>

          <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>"
                 style="<?php echo $this->input_style(); ?>" title="Fecha del tour desde" />
          <input type="date" name="date_until" value="<?php echo esc_attr($filters['date_until']); ?>"
                 style="<?php echo $this->input_style(); ?>" title="Fecha del tour hasta" />

          <select name="source" style="<?php echo $this->input_style(); ?>">
            <option value="">— Todos los orígenes —</option>
            <option value="direct"       <?php selected($filters['source'],'direct'); ?>>Directo</option>
            <option value="partner"      <?php selected($filters['source'],'partner'); ?>>Partner</option>
            <option value="tripadvisor"  <?php selected($filters['source'],'tripadvisor'); ?>>TripAdvisor</option>
            <option value="getyourguide" <?php selected($filters['source'],'getyourguide'); ?>>GetYourGuide</option>
          </select>

          <button type="submit" class="button button-primary">Filtrar</button>
          <a href="<?php echo admin_url('admin.php?page=amir-bookings-list'); ?>" class="button">Limpiar</a>
        </form>

        <!-- Tabla -->
        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table class="ab-bookings-table" style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <th>Referencia</th>
              <th>Tour</th>
              <th>Fecha tour</th>
              <th>Cliente</th>
              <th>Pax</th>
              <th>Total</th>
              <th>Estado</th>
              <th>Origen</th>
              <th>Reservado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if ( empty($bookings) ) : ?>
            <tr><td colspan="10" style="text-align:center;padding:32px;color:#5a7068;">No se encontraron reservas con los filtros seleccionados.</td></tr>
          <?php else : foreach ( $bookings as $b ) : ?>
            <tr>
              <td><a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>"
                    style="font-weight:700;color:#1D9E75;"><?php echo esc_html($b->booking_ref); ?></a></td>
              <td style="max-width:160px;"><?php echo esc_html($b->tour_name_es); ?></td>
              <td><?php echo esc_html($b->tour_date); ?><br><span style="font-size:11px;color:#5a7068;"><?php echo $this->fmt_time($b->time_start??'00:00'); ?></span></td>
              <td>
                <strong><?php echo esc_html($b->customer_name); ?></strong><br>
                <span style="font-size:12px;color:#5a7068;"><?php echo esc_html($b->customer_email); ?></span>
              </td>
              <td>
                <?php echo $b->adults + $b->children + $b->babies; ?> pax
                <br><span style="font-size:11px;color:#5a7068;"><?php echo $b->adults; ?>A <?php echo $b->children; ?>N <?php echo $b->babies; ?>B</span>
              </td>
              <td style="font-weight:700;">$<?php echo number_format($b->total_mxn,0,'.',','); ?><br><span style="font-size:11px;font-weight:400;color:#5a7068;">MXN</span></td>
              <td><?php echo $this->status_badge($b->status); ?></td>
              <td><?php echo $this->source_badge($b->booking_source); ?></td>
              <td style="font-size:12px;color:#5a7068;"><?php echo date('d/m/y', strtotime($b->created_at)); ?></td>
              <td>
                <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>"
                   style="color:#1D9E75;font-size:12px;font-weight:600;white-space:nowrap;">Ver →</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>

        <!-- Paginación -->
        <?php if ( $total > $per_page ) : ?>
        <div style="margin-top:16px;display:flex;gap:8px;align-items:center;justify-content:flex-end;font-size:13px;color:#5a7068;">
          <?php
          $total_pages = ceil($total/$per_page);
          for ($p=1; $p<=$total_pages; $p++) :
            $url = admin_url('admin.php?page=amir-bookings-list&paged='.$p.$this->filter_query_string($filters));
            echo '<a href="'.esc_url($url).'" style="padding:5px 10px;border-radius:6px;border:1px solid '.($p===$page?'#1D9E75':'#e1f5ee').';background:'.($p===$page?'#1D9E75':'#fff').';color:'.($p===$page?'#fff':'#1a2e24').';">'.$p.'</a>';
          endfor;
          ?>
        </div>
        <?php endif; ?>

        </div>
        <?php

        // CSV export
        if ( ($action??'') === 'export' ) {
            $this->export_csv($filters);
        }
    }

    // ── Vista de detalle ──────────────────────────────────────────────────

    private function render_detail( int $booking_id ): void {
        // Procesar acciones POST en el detalle
        $message = $this->handle_detail_action( $booking_id );

        global $wpdb;
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, t.name_es as tour_name, t.name_en as tour_name_en,
                    t.meeting_point_es, t.meeting_lat, t.meeting_lng,
                    s.time_start, s.time_end, s.label_es as schedule_label,
                    p.name as partner_name
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             LEFT JOIN {$wpdb->prefix}amir_partners p ON p.id = b.partner_id
             WHERE b.id = %d",
            $booking_id
        ) );

        if ( ! $b ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Reserva no encontrada.</p></div></div>';
            return;
        }

        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->admin_styles(); ?>

        <h1 style="display:flex;align-items:center;gap:12px;">
          <a href="<?php echo admin_url('admin.php?page=amir-bookings-list'); ?>" style="color:#5a7068;font-weight:400;font-size:16px;">← Reservas</a>
          <span><?php echo esc_html($b->booking_ref); ?></span>
          <?php echo $this->status_badge($b->status); ?>
        </h1>

        <?php if ($message) echo '<div class="notice notice-success is-dismissible"><p>'.$message.'</p></div>'; ?>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;">

          <!-- Columna principal -->
          <div>

            <!-- Info del tour -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">🏄 Tour reservado</div>
              <div class="ab-detail-grid">
                <div class="ab-detail-row"><span>Tour</span><strong><?php echo esc_html($b->tour_name); ?></strong></div>
                <div class="ab-detail-row"><span>Fecha</span><strong><?php echo esc_html($b->tour_date); ?></strong></div>
                <div class="ab-detail-row"><span>Horario</span><strong><?php echo $this->fmt_time($b->time_start); ?> – <?php echo $this->fmt_time($b->time_end); ?> <?php echo $b->schedule_label ? '('.$b->schedule_label.')' : ''; ?></strong></div>
                <div class="ab-detail-row"><span>Personas</span>
                  <strong>
                    <?php echo $b->adults; ?> adultos
                    <?php if ($b->children) echo ' · '.$b->children.' niños'; ?>
                    <?php if ($b->babies)   echo ' · '.$b->babies.' bebés'; ?>
                    <span style="color:#5a7068;font-weight:400;"> (<?php echo $b->adults+$b->children+$b->babies; ?> total)</span>
                  </strong>
                </div>
                <?php if ($b->special_requests) : ?>
                <div class="ab-detail-row"><span>Solicitudes</span><span><?php echo esc_html($b->special_requests); ?></span></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Info del cliente -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">👤 Cliente</div>
              <div class="ab-detail-grid">
                <div class="ab-detail-row"><span>Nombre</span><strong><?php echo esc_html($b->customer_name); ?></strong></div>
                <div class="ab-detail-row"><span>Email</span><a href="mailto:<?php echo esc_attr($b->customer_email); ?>"><?php echo esc_html($b->customer_email); ?></a></div>
                <div class="ab-detail-row"><span>Teléfono</span>
                  <?php echo esc_html($b->customer_phone); ?>
                  <?php if ($b->customer_phone) : ?>
                    <a class="ab-wa-btn" href="https://wa.me/<?php echo preg_replace('/[^0-9]/','', $b->customer_phone); ?>" target="_blank" style="margin-left:8px;">💬 WhatsApp</a>
                  <?php endif; ?>
                </div>
                <div class="ab-detail-row"><span>Idioma</span><?php echo strtoupper($b->lang); ?></div>
                <?php if ($b->partner_name) : ?>
                <div class="ab-detail-row"><span>Partner</span><span class="ab-source-chip partner"><?php echo esc_html($b->partner_name); ?></span></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Pago -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">💳 Pago</div>
              <div class="ab-detail-grid">
                <div class="ab-detail-row"><span>Total pagado</span><strong style="font-size:18px;">$<?php echo number_format($b->total_mxn,2); ?> MXN</strong></div>
                <?php if ($b->usd_reference) : ?>
                <div class="ab-detail-row"><span>Referencia USD</span><span>≈ $<?php echo number_format($b->usd_reference,2); ?> USD (tipo <?php echo $b->exchange_rate; ?>)</span></div>
                <?php endif; ?>
                <div class="ab-detail-row"><span>Origen</span><?php echo $this->source_badge($b->booking_source); ?></div>
                <?php if ($b->stripe_payment_intent) : ?>
                <div class="ab-detail-row"><span>Stripe PI</span><code style="font-size:11px;"><?php echo esc_html($b->stripe_payment_intent); ?></code></div>
                <?php endif; ?>
                <?php if ($b->confirmed_at) : ?>
                <div class="ab-detail-row"><span>Confirmado el</span><?php echo esc_html(date('d/m/Y H:i', strtotime($b->confirmed_at))); ?></div>
                <?php endif; ?>
                <?php if ($b->refund_amount_mxn > 0) : ?>
                <div class="ab-detail-row"><span>Reembolso</span><strong style="color:#e24b4a;">$<?php echo number_format($b->refund_amount_mxn,2); ?> MXN (<?php echo $b->cancellation_policy_pct; ?>% cargo)</strong></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Notas internas -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">📝 Notas internas (solo visible para el equipo)</div>
              <pre style="font-family:inherit;font-size:13px;color:#5a7068;white-space:pre-wrap;margin:0 0 14px;"><?php echo esc_html($b->internal_notes ?: '—'); ?></pre>
              <form method="post">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="add_note" />
                <textarea name="note_text" rows="3" placeholder="Agregar nota interna…"
                          style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px;font-size:13px;box-sizing:border-box;margin-bottom:8px;"></textarea>
                <button type="submit" class="button">Guardar nota</button>
              </form>
            </div>

          </div><!-- fin columna principal -->

          <!-- Columna derecha: acciones -->
          <div>

            <!-- Acciones disponibles según estado -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">⚡ Acciones</div>

              <?php if ( $b->status === 'pending' ) : ?>
              <form method="post" style="margin-bottom:10px;">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="manual_confirm" />
                <button type="submit" class="button button-primary" style="width:100%;background:#1D9E75;border-color:#0F6E56;color:#fff;"
                  onclick="return confirm('¿Confirmar esta reserva manualmente? Se enviará el email de confirmación y se generará el voucher PDF.')">
                  ✅ Confirmar reserva manualmente
                </button>
              </form>
              <p style="font-size:11px;color:#5a7068;margin:-6px 0 12px;">Úsalo cuando el webhook de Stripe no procesó la confirmación automática.</p>
              <?php endif; ?>

              <?php if ( in_array($b->status, ['confirmed','pending'], true) ) : ?>
              <form method="post" style="margin-bottom:10px;">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="resend_confirmation" />
                <button type="submit" class="button" style="width:100%;">📧 Reenviar email de confirmación</button>
              </form>
              <?php endif; ?>

              <?php if ( $b->status === 'cancellation_requested' ) : ?>
              <!-- Aprobar cancelación (manual) -->
              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;color:#e24b4a;margin-bottom:8px;">Solicitud de cancelación</div>
                <p style="font-size:13px;color:#5a7068;margin:0 0 10px;">
                  El cliente solicitó cancelar esta reserva. Revisa la política y procesa el reembolso manualmente en el dashboard de Stripe si corresponde.
                </p>
                <p style="font-size:12px;color:#5a7068;margin:0 0 12px;">
                  <?php echo $this->cancellation_policy_text($b); ?>
                </p>
                <form method="post" style="display:flex;gap:8px;">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="approve_cancellation" />
                  <textarea name="cancel_note" rows="2" placeholder="Nota sobre el reembolso (opcional)…"
                            style="flex:1;border:1px solid #fecaca;border-radius:6px;padding:7px;font-size:12px;box-sizing:border-box;"></textarea>
                  <button type="submit" class="button" style="background:#e24b4a;color:#fff;border-color:#e24b4a;align-self:flex-start;flex-shrink:0;">Aprobar cancelación</button>
                </form>
                <form method="post" style="margin-top:8px;">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="reject_cancellation" />
                  <button type="submit" class="button" style="width:100%;">✗ Rechazar solicitud (mantener reserva)</button>
                </form>
              </div>
              <?php endif; ?>

              <?php if ( in_array($b->status, ['confirmed'], true) ) : ?>
              <!-- Cancelar por operador -->
              <details style="margin-bottom:10px;">
                <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#e24b4a;padding:8px 0;">Cancelar por condición climática / mínimo pax</summary>
                <div style="padding-top:10px;">
                  <form method="post">
                    <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                    <input type="hidden" name="amir_action" value="cancel_operator" />
                    <select name="cancel_reason" style="<?php echo $this->input_style(); ?> width:100%;margin-bottom:8px;">
                      <option value="weather">Condiciones climáticas</option>
                      <option value="min_pax">Mínimo de pasajeros no alcanzado</option>
                    </select>
                    <p style="font-size:12px;color:#5a7068;margin:0 0 8px;">Reembolso completo al cliente. Procesar manualmente en Stripe.</p>
                    <button type="submit" class="button" onclick="return confirm('¿Confirmas la cancelación? El cliente será notificado por email.')">Confirmar cancelación</button>
                  </form>
                </div>
              </details>

              <!-- Reprogramar -->
              <details style="margin-bottom:10px;">
                <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#1D9E75;padding:8px 0;">Reprogramar reserva</summary>
                <div style="padding-top:10px;">
                  <form method="post">
                    <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                    <input type="hidden" name="amir_action" value="reschedule" />
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Nueva fecha</label>
                    <input type="date" name="new_date" min="<?php echo date('Y-m-d',strtotime('+1 day')); ?>"
                           style="<?php echo $this->input_style(); ?> width:100%;margin-bottom:8px;" required />
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Nuevo horario</label>
                    <?php
                    global $wpdb;
                    $schedules = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}amir_tour_schedules WHERE tour_id=%d AND active=1 ORDER BY time_start",
                        $b->tour_id
                    ));
                    ?>
                    <select name="new_schedule_id" style="<?php echo $this->input_style(); ?> width:100%;margin-bottom:8px;">
                      <?php foreach ($schedules as $s) : ?>
                        <option value="<?php echo $s->id; ?>"><?php echo $this->fmt_time($s->time_start); ?> – <?php echo esc_html($s->label_es); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-primary" style="width:100%;">Confirmar reprogramación</button>
                  </form>
                </div>
              </details>
              <?php endif; ?>

              <!-- Voucher -->
              <?php if ( $b->status === 'confirmed' || $b->status === 'completed' ) : ?>
              <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=pdf&id='.$booking_id); ?>"
                 class="button" style="width:100%;text-align:center;display:block;margin-bottom:10px;box-sizing:border-box;" target="_blank">
                📄 Ver voucher PDF
              </a>
              <?php endif; ?>

            </div><!-- acciones -->

            <!-- Historial de emails -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">📨 Emails enviados</div>
              <div style="font-size:13px;color:#5a7068;">
                <?php if ($b->confirmed_at)         : ?><div style="padding:5px 0;border-bottom:1px solid #f5f5f5;">✅ Confirmación — <?php echo date('d/m/Y H:i', strtotime($b->confirmed_at)); ?></div><?php endif; ?>
                <?php if ($b->reminder_sent_at)     : ?><div style="padding:5px 0;border-bottom:1px solid #f5f5f5;">⏰ Recordatorio — <?php echo date('d/m/Y H:i', strtotime($b->reminder_sent_at)); ?></div><?php endif; ?>
                <?php if ($b->review_email_sent_at) : ?><div style="padding:5px 0;">⭐ Solicitud reseña — <?php echo date('d/m/Y H:i', strtotime($b->review_email_sent_at)); ?></div><?php endif; ?>
                <?php if (!$b->confirmed_at && !$b->reminder_sent_at && !$b->review_email_sent_at) : ?>
                  <span>No se han enviado emails aún.</span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Datos de auditoría -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">🔍 Auditoría</div>
              <div style="font-size:12px;color:#5a7068;line-height:1.8;">
                <div>Creada: <?php echo date('d/m/Y H:i', strtotime($b->created_at)); ?></div>
                <?php if ($b->updated_at) : ?><div>Actualizada: <?php echo date('d/m/Y H:i', strtotime($b->updated_at)); ?></div><?php endif; ?>
                <div>Idioma: <?php echo strtoupper($b->lang); ?></div>
                <div>Booking ID: #<?php echo $b->id; ?></div>
              </div>
            </div>

          </div><!-- fin columna derecha -->
        </div><!-- grid -->
        </div><!-- wrap -->
        <?php
    }

    // ── Procesar acciones del detalle ─────────────────────────────────────

    private function handle_detail_action( int $booking_id ): string {
        if ( empty($_POST['amir_action']) ) {
            return '';
        }
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_booking_action_'.$booking_id ) ) {
            return '';
        }

        global $wpdb;
        $action = sanitize_key( $_POST['amir_action'] );

        switch ( $action ) {

            case 'add_note':
                $note = sanitize_textarea_field( $_POST['note_text'] ?? '' );
                if ( $note ) {
                    $existing = $wpdb->get_var($wpdb->prepare("SELECT internal_notes FROM {$wpdb->prefix}amir_bookings WHERE id=%d", $booking_id));
                    $new_notes = trim($existing) . "\n[" . current_time('d/m/Y H:i') . " — " . wp_get_current_user()->display_name . "] " . $note;
                    $wpdb->update("{$wpdb->prefix}amir_bookings", ['internal_notes'=>$new_notes], ['id'=>$booking_id]);
                    return 'Nota agregada correctamente.';
                }
                break;

            case 'manual_confirm':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( $b && $b->status === 'pending' ) {
                    $manager = new \AmirBooking\Core\BookingManager();
                    $manager->confirm( $booking_id, $b->stripe_charge_id ?? '' );
                    return '✅ Reserva confirmada manualmente. Email y PDF en proceso.';
                }
                return 'La reserva no está en estado pendiente.';

            case 'resend_confirmation':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ($b) {
                    do_action('amir_booking_confirmed', $booking_id);
                    return 'Email de confirmación reenviado.';
                }
                break;

            case 'approve_cancellation':
                $note = sanitize_textarea_field($_POST['cancel_note'] ?? '');
                $wpdb->update(
                    "{$wpdb->prefix}amir_bookings",
                    [
                        'status'         => 'cancelled_client',
                        'internal_notes' => $wpdb->get_var($wpdb->prepare("SELECT internal_notes FROM {$wpdb->prefix}amir_bookings WHERE id=%d",$booking_id))
                            . "\n[" . current_time('d/m/Y H:i') . "] Cancelación aprobada por ".wp_get_current_user()->display_name.". ".$note,
                    ],
                    ['id' => $booking_id]
                );
                do_action('amir_booking_cancelled', $booking_id, 'client');
                return 'Cancelación aprobada. Procesa el reembolso en el dashboard de Stripe si corresponde.';

            case 'reject_cancellation':
                $wpdb->update("{$wpdb->prefix}amir_bookings", ['status'=>'confirmed'], ['id'=>$booking_id]);
                return 'Solicitud de cancelación rechazada. La reserva continúa confirmada.';

            case 'cancel_operator':
                $reason = in_array($_POST['cancel_reason']??'', ['weather','min_pax'], true)
                    ? sanitize_key($_POST['cancel_reason'])
                    : 'weather';
                $manager = new \AmirBooking\Core\BookingManager();
                $result  = $manager->cancel($booking_id, $reason);
                return $result->success ? 'Tour cancelado. ' . $result->message . ' Email enviado al cliente.' : $result->error;

            case 'reschedule':
                $new_date = sanitize_text_field($_POST['new_date'] ?? '');
                $new_sch  = (int)($_POST['new_schedule_id'] ?? 0);
                if ($new_date && $new_sch) {
                    $manager = new \AmirBooking\Core\BookingManager();
                    $result  = $manager->reschedule($booking_id, $new_date, $new_sch);
                    return $result->success ? 'Reserva reprogramada a '.$new_date.'.' : $result->error;
                }
                break;
        }

        return '';
    }

    // ── Exportar CSV ──────────────────────────────────────────────────────

    private function export_csv( array $filters ): void {
        $bookings = $this->query_bookings($filters, 9999);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reservas-amir-'.date('Y-m-d').'.csv"');
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

        fputcsv($out, ['Referencia','Tour','Fecha','Horario','Estado','Cliente','Email','Teléfono','Adultos','Niños','Bebés','Total MXN','Origen','Partner','Reservado en']);

        foreach ($bookings as $b) {
            fputcsv($out, [
                $b->booking_ref, $b->tour_name_es, $b->tour_date,
                ($b->time_start??''), $b->status,
                $b->customer_name, $b->customer_email, $b->customer_phone,
                $b->adults, $b->children, $b->babies, $b->total_mxn,
                $b->booking_source, $b->partner_name??'', $b->created_at,
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Acción bulk ───────────────────────────────────────────────────────

    private function handle_bulk_action(): void {
        // Placeholder para acciones masivas futuras
    }

    // ── Queries ───────────────────────────────────────────────────────────

    private function get_filters(): array {
        return [
            'search'     => sanitize_text_field( $_GET['search']     ?? '' ),
            'tour_id'    => (int)(                $_GET['tour_id']    ?? 0 ),
            'status'     => sanitize_key(         $_GET['status']     ?? '' ),
            'date_from'  => sanitize_text_field(  $_GET['date_from']  ?? '' ),
            'date_until' => sanitize_text_field(  $_GET['date_until'] ?? '' ),
            'source'     => sanitize_key(         $_GET['source']     ?? '' ),
            'paged'      => max(1,(int)(          $_GET['paged']      ?? 1 )),
        ];
    }

    private function query_bookings( array $f, int $limit = 25 ): array {
        global $wpdb;
        [ $where, $params ] = $this->build_where($f);
        $offset = ($f['paged']-1) * $limit;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, t.name_es as tour_name_es, s.time_start,
                    p.name as partner_name
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             LEFT JOIN {$wpdb->prefix}amir_partners p ON p.id = b.partner_id
             WHERE 1=1 {$where}
             ORDER BY b.created_at DESC
             LIMIT %d OFFSET %d",
            array_merge($params, [$limit, $offset])
        ) ) ?? [];
    }

    private function count_bookings( array $f ): int {
        global $wpdb;
        [ $where, $params ] = $this->build_where($f);
        return (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings b WHERE 1=1 {$where}",
            $params
        ) );
    }

    private function build_where( array $f ): array {
        $where  = '';
        $params = [];

        if ( $f['search'] ) {
            $like = '%' . $wpdb->esc_like($f['search']) . '%';
            $where  .= " AND (b.booking_ref LIKE %s OR b.customer_name LIKE %s OR b.customer_email LIKE %s)";
            $params  = array_merge($params, [$like,$like,$like]);
        }
        if ( $f['tour_id']  ) { $where .= " AND b.tour_id=%d";          $params[] = $f['tour_id'];    }
        if ( $f['status']   ) { $where .= " AND b.status=%s";           $params[] = $f['status'];     }
        if ( $f['date_from']) { $where .= " AND b.tour_date>=%s";       $params[] = $f['date_from'];  }
        if ( $f['date_until']){ $where .= " AND b.tour_date<=%s";       $params[] = $f['date_until']; }
        if ( $f['source']   ) { $where .= " AND b.booking_source=%s";   $params[] = $f['source'];     }

        return [$where, $params];
    }

    private function get_tours_for_filter(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}amir_tours ORDER BY sort_order"
        );
        if ( ! empty( $rows ) ) {
            return $rows;
        }
        // Fallback al CPT si tabla vacía
        $posts = get_posts( [
            'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );
        return array_map( fn( $p ) => (object)[
            'id'      => (int) get_post_meta( $p->ID, '_amir_tour_db_id', true ),
            'name_es' => $p->post_title,
        ], $posts );
    }

    // ── Helpers de UI ─────────────────────────────────────────────────────

    private function status_badge( string $status ): string {
        $labels = $this->status_labels();
        $colors = [
            'confirmed'               => '#e1f5ee:#0F6E56',
            'pending'                 => '#fff8e7:#BA7517',
            'cancellation_requested'  => '#fef2f2:#e24b4a',
            'cancelled_client'        => '#fef2f2:#c53030',
            'cancelled_weather'       => '#fef2f2:#c53030',
            'cancelled_min_pax'       => '#fef2f2:#c53030',
            'rescheduled'             => '#e8f4ff:#1a6fa8',
            'completed'               => '#f0faf6:#0F6E56',
        ];
        [$bg,$color] = explode(':', $colors[$status] ?? '#f3f4f6:#5a7068');
        return "<span style='background:{$bg};color:{$color};font-size:11px;font-weight:700;padding:3px 8px;border-radius:12px;white-space:nowrap;'>"
            . esc_html($labels[$status] ?? $status) . "</span>";
    }

    private function source_badge( string $source ): string {
        $colors = [
            'direct'       => '#f3f4f6:#5a7068',
            'partner'      => '#fef3c7:#92400e',
            'tripadvisor'  => '#e8f4ff:#0066cc',
            'getyourguide' => '#fff0e6:#cc4400',
        ];
        [$bg,$color] = explode(':', $colors[$source] ?? '#f3f4f6:#5a7068');
        return "<span style='background:{$bg};color:{$color};font-size:10px;font-weight:700;padding:2px 6px;border-radius:8px;text-transform:uppercase;'>"
            . esc_html($source) . "</span>";
    }

    private function status_labels(): array {
        return [
            'pending'                => '⏳ Pendiente',
            'confirmed'              => '✅ Confirmada',
            'cancellation_requested' => '🚫 Solicita cancelación',
            'cancelled_client'       => '✗ Cancelada (cliente)',
            'cancelled_weather'      => '⛈ Cancelada (clima)',
            'cancelled_min_pax'      => '👥 Cancelada (mín. pax)',
            'rescheduled'            => '🔄 Reprogramada',
            'completed'              => '🏁 Completada',
        ];
    }

    private function cancellation_policy_text( object $b ): string {
        $today     = new \DateTime(current_time('Y-m-d'));
        $tour_date = new \DateTime($b->tour_date);
        $days      = (int)$today->diff($tour_date)->days;
        if ($days >= 7) return '7+ días de anticipación → Reembolso 100%.';
        if ($days >= 3) return "3-6 días de anticipación → Reembolso 50% ($".number_format($b->total_mxn*0.5,2)." MXN).";
        return 'Menos de 3 días → Sin reembolso según política.';
    }

    private function filter_query_string( array $f ): string {
        $parts = [];
        foreach ($f as $k=>$v) {
            if ($v && $k !== 'paged') $parts[] = "&{$k}=".urlencode($v);
        }
        return implode('', $parts);
    }

    private function fmt_time( string $t ): string {
        [$h,$m] = explode(':', $t);
        $h = (int)$h;
        return ($h>12?$h-12:($h?:12)).':'.$m.($h>=12?' PM':' AM');
    }

    private function input_style(): string {
        return 'border:1px solid #c3d9d0;border-radius:6px;padding:6px 10px;font-size:13px;';
    }

    private function admin_styles(): void {
        echo '<style>
        .ab-admin-wrap { max-width:1200px; }
        .ab-bookings-table th { font-size:11px; font-weight:700; color:#5a7068; text-transform:uppercase; letter-spacing:.4px; padding:10px 12px; text-align:left; background:#f8fdfb; border-bottom:1px solid #e1f5ee; }
        .ab-bookings-table td { font-size:13px; padding:10px 12px; border-bottom:1px solid #f5f5f5; vertical-align:middle; }
        .ab-bookings-table tr:hover td { background:#f8fdfb; }
        .ab-detail-card { background:#fff; border:1px solid #e1f5ee; border-radius:10px; padding:16px 18px; margin-bottom:16px; }
        .ab-detail-card-title { font-size:14px; font-weight:700; color:#1D9E75; margin-bottom:14px; padding-bottom:8px; border-bottom:1px solid #e1f5ee; }
        .ab-detail-row { display:flex; justify-content:space-between; align-items:flex-start; padding:7px 0; border-bottom:1px solid #f5f5f5; font-size:13px; gap:12px; }
        .ab-detail-row:last-child { border-bottom:none; }
        .ab-detail-row > span:first-child { color:#5a7068; flex-shrink:0; min-width:90px; }
        .ab-wa-btn { display:inline-flex; align-items:center; gap:4px; background:#25D366; color:#fff !important; border:none; border-radius:5px; padding:4px 8px; font-size:11px; font-weight:700; text-decoration:none; }
        </style>';
    }

    // ── Descarga de voucher PDF desde el admin ────────────────────────────

    private function stream_pdf( int $booking_id ): void {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            wp_die( 'Reserva no encontrada.' );
        }

        $gen      = new \AmirBooking\Core\VoucherGenerator();
        $filepath = $gen->generate( $booking_id );

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        if ( $filepath && file_exists( $filepath ) ) {
            $ext  = pathinfo( $filepath, PATHINFO_EXTENSION );
            $mime = $ext === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8';
            $disp = $ext === 'pdf' ? 'attachment' : 'inline';
            header( 'Content-Type: ' . $mime );
            header( 'Content-Disposition: ' . $disp . '; filename="voucher-' . sanitize_file_name( $booking->booking_ref ) . '.' . $ext . '"' );
            header( 'Content-Length: ' . filesize( $filepath ) );
            header( 'Cache-Control: no-store' );
            readfile( $filepath );
        } else {
            // Fallback: HTML en línea
            $html = $gen->get_voucher_html( $booking_id );
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Content-Disposition: inline; filename="voucher-' . sanitize_file_name( $booking->booking_ref ) . '.html"' );
            echo $html;
        }

        exit;
    }
}
