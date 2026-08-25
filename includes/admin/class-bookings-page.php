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

    /**
     * Idioma de esta pantalla — sigue el idioma de admin del usuario logueado
     * (`get_user_locale()`, mismo criterio que `SettingsPage`, ver
     * CONTRIBUTING.md § 16.84/§ 16.86). Este archivo nunca usó `__()`/`_e()`
     * (a diferencia del editor de tours), así que se sigue el mismo patrón
     * es/en en código que ya usa `EmailTexts`/`SettingsPage` en vez de sumar
     * gettext acá.
     */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    /**
     * Punto de entrada público para AdminMenu::maybe_export_bookings_csv()
     * (hook admin_init) — el `if ($action==='export')` que vivía acá adentro
     * era tarde: WordPress ya manda las cabeceras HTTP + el HTML del admin
     * (menú, header) antes de invocar render(), así que header('Content-
     * Type: text/csv...') fallaba en silencio y el CSV se mostraba como
     * texto en pantalla en vez de descargarse (bug real reportado por el
     * cliente 2026-08-24). Mismo patrón que ya usa maybe_stream_pdf() para
     * el voucher — ver class-admin-menu.php.
     */
    public function export_csv_request(): void {
        $this->export_csv( $this->get_filters() );
    }

    public function render(): void {
        $action = sanitize_key( $_GET['action'] ?? 'list' );

        if ( $action === 'new' ) {
            $this->render_new_booking_form();
            return;
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
          <span>📋 <?php echo esc_html( $this->tt( 'Reservas', 'Bookings' ) ); ?> <span style="font-size:14px;font-weight:400;color:#5a7068;">(<?php echo $total; ?> <?php echo esc_html( $this->tt( 'total', 'total' ) ); ?>)</span></span>
          <div style="display:flex;gap:8px;">
            <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=new'); ?>"
               class="button button-primary">+ <?php echo esc_html( $this->tt( 'Nueva reserva', 'New booking' ) ); ?></a>
            <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=export'.$this->filter_query_string($filters)); ?>"
               class="button">⬇ <?php echo esc_html( $this->tt( 'Exportar CSV', 'Export CSV' ) ); ?></a>
          </div>
        </h1>

        <?php if ( ! empty( $_GET['created'] ) ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->tt( 'Reserva creada correctamente.', 'Booking created successfully.' ) ); ?></p></div>
        <?php endif; ?>

        <?php if ( $filters['cart_group_id'] ) : ?>
          <div class="notice notice-info" style="padding:10px 14px;display:flex;align-items:center;justify-content:space-between;">
            <p style="margin:0;">🔗 <?php echo esc_html( sprintf( $this->tt( 'Mostrando solo las %d reserva(s) de una misma compra (carrito).', 'Showing only the %d booking(s) from the same purchase (cart).' ), (int) $total ) ); ?></p>
            <a href="<?php echo esc_url( admin_url('admin.php?page=amir-bookings-list') ); ?>" style="font-weight:600;"><?php echo esc_html( $this->tt( 'Ver todas las reservas →', 'View all bookings →' ) ); ?></a>
          </div>
        <?php endif; ?>

        <!-- Filtros -->
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:20px;background:#f8fdfb;padding:14px 16px;border-radius:10px;border:1px solid #e1f5ee;">
          <input type="hidden" name="page" value="amir-bookings-list" />
          <input type="hidden" name="action" value="list" />

          <input type="text" name="search" value="<?php echo esc_attr($filters['search']); ?>"
                 placeholder="<?php echo esc_attr( $this->tt( 'Nombre, email, referencia…', 'Name, email, reference…' ) ); ?>"
                 style="<?php echo $this->input_style(); ?> width:220px;" />

          <select name="tour_id" style="<?php echo $this->input_style(); ?>">
            <option value=""><?php echo esc_html( $this->tt( '— Todos los tours —', '— All tours —' ) ); ?></option>
            <?php foreach ($tours as $t) : ?>
              <option value="<?php echo $t->id; ?>" <?php selected($filters['tour_id'], $t->id); ?>><?php echo esc_html($t->name_es); ?></option>
            <?php endforeach; ?>
          </select>

          <select name="status" style="<?php echo $this->input_style(); ?>">
            <option value=""><?php echo esc_html( $this->tt( '— Todos los estados —', '— All statuses —' ) ); ?></option>
            <?php foreach ($this->status_labels() as $k=>$v) : ?>
              <option value="<?php echo $k; ?>" <?php selected($filters['status'],$k); ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
          </select>

          <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>"
                 style="<?php echo $this->input_style(); ?>" title="<?php echo esc_attr( $this->tt( 'Fecha del tour desde', 'Tour date from' ) ); ?>" />
          <input type="date" name="date_until" value="<?php echo esc_attr($filters['date_until']); ?>"
                 style="<?php echo $this->input_style(); ?>" title="<?php echo esc_attr( $this->tt( 'Fecha del tour hasta', 'Tour date until' ) ); ?>" />

          <select name="source" style="<?php echo $this->input_style(); ?>">
            <option value=""><?php echo esc_html( $this->tt( '— Todos los orígenes —', '— All sources —' ) ); ?></option>
            <option value="direct"       <?php selected($filters['source'],'direct'); ?>><?php echo esc_html( $this->tt( 'Directo', 'Direct' ) ); ?></option>
            <option value="partner"      <?php selected($filters['source'],'partner'); ?>><?php echo esc_html( $this->tt( 'Partner', 'Partner' ) ); ?></option>
            <option value="tripadvisor"  <?php selected($filters['source'],'tripadvisor'); ?>>TripAdvisor</option>
            <option value="getyourguide" <?php selected($filters['source'],'getyourguide'); ?>>GetYourGuide</option>
          </select>

          <button type="submit" class="button button-primary"><?php echo esc_html( $this->tt( 'Filtrar', 'Filter' ) ); ?></button>
          <a href="<?php echo admin_url('admin.php?page=amir-bookings-list'); ?>" class="button"><?php echo esc_html( $this->tt( 'Limpiar', 'Clear' ) ); ?></a>
        </form>

        <!-- Tabla -->
        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table class="ab-bookings-table" style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <th><?php echo esc_html( $this->tt( 'Referencia', 'Reference' ) ); ?></th>
              <th><?php echo esc_html( $this->tt( 'Tour', 'Tour' ) ); ?></th>
              <th><?php echo esc_html( $this->tt( 'Fecha tour', 'Tour date' ) ); ?></th>
              <th><?php echo esc_html( $this->tt( 'Cliente', 'Customer' ) ); ?></th>
              <th>Pax</th>
              <th><?php echo esc_html( $this->tt( 'Total', 'Total' ) ); ?></th>
              <th><?php echo esc_html( $this->tt( 'Estado', 'Status' ) ); ?></th>
              <th><?php echo esc_html( $this->tt( 'Origen', 'Source' ) ); ?></th>
              <th><?php echo esc_html( $this->tt( 'Reservado', 'Booked' ) ); ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if ( empty($bookings) ) : ?>
            <tr><td colspan="10" style="text-align:center;padding:32px;color:#5a7068;"><?php echo esc_html( $this->tt( 'No se encontraron reservas con los filtros seleccionados.', 'No bookings found with the selected filters.' ) ); ?></td></tr>
          <?php else : foreach ( $bookings as $b ) : ?>
            <tr>
              <td><a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>"
                    style="font-weight:700;color:#1D9E75;"><?php echo esc_html($b->booking_ref); ?></a>
                <?php if ( $b->cart_group_id && (int) $b->cart_group_count > 1 && ! $filters['cart_group_id'] ) : ?>
                  <br><a href="<?php echo admin_url('admin.php?page=amir-bookings-list&cart_group_id='.urlencode($b->cart_group_id)); ?>"
                     title="<?php echo esc_attr( $this->tt( 'Estas reservas se hicieron juntas, en la misma compra', 'These bookings were made together, in the same purchase' ) ); ?>"
                     style="display:inline-block;margin-top:3px;font-size:10.5px;font-weight:700;color:#0F6E56;background:#e1f5ee;border-radius:10px;padding:1px 8px;text-decoration:none;">
                     🔗 <?php echo esc_html( sprintf( $this->tt( '+%d más de esta compra', '+%d more from this purchase' ), (int) $b->cart_group_count - 1 ) ); ?>
                  </a>
                <?php endif; ?>
              </td>
              <?php if ( $b->item_type === 'room' ) : ?>
              <td style="max-width:160px;">🛏 <?php echo esc_html($b->room_name_es); ?></td>
              <td><?php echo esc_html($b->tour_date); ?> → <?php echo esc_html($b->check_out_date); ?></td>
              <?php else : ?>
              <td style="max-width:160px;"><?php echo esc_html($b->tour_name_es); ?></td>
              <td><?php echo esc_html($b->tour_date); ?><br><span style="font-size:11px;color:#5a7068;"><?php echo $this->fmt_time($b->time_start??'00:00'); ?></span></td>
              <?php endif; ?>
              <td>
                <strong><?php echo esc_html($b->customer_name); ?></strong><br>
                <span style="font-size:12px;color:#5a7068;"><?php echo esc_html($b->customer_email); ?></span>
              </td>
              <td>
                <?php echo $b->adults + $b->children + $b->babies; ?> pax
                <br><span style="font-size:11px;color:#5a7068;"><?php echo $b->adults; ?>A <?php echo $b->children; ?>N <?php echo $b->babies; ?>B</span>
              </td>
              <td style="font-weight:700;"><?php echo \AmirBooking\Core\Currency::format((float)$b->total_mxn, 0); ?></td>
              <td>
                <?php echo $this->status_badge($b->status); ?>
                <?php if ( $b->status === 'confirmed' && (int) ( $b->deposit_pct ?? 0 ) > 0 && empty( $b->balance_paid_at ) ) :
                  $balance_owed = round( (float) $b->total_mxn - ( (float) $b->total_mxn * (int) $b->deposit_pct / 100 ), 2 );
                ?>
                  <br><span title="<?php echo esc_attr( sprintf( $this->tt( 'Depósito del %d%% cobrado — falta el resto', '%d%% deposit charged — remainder still due' ), (int) $b->deposit_pct ) ); ?>"
                        style="display:inline-block;margin-top:3px;font-size:10.5px;font-weight:700;color:#92400e;background:#fff8e7;border:1px solid #fde68a;border-radius:10px;padding:1px 8px;">
                    💰 <?php echo esc_html( $this->tt( 'Saldo: ', 'Balance: ' ) ); ?><?php echo \AmirBooking\Core\Currency::format( $balance_owed, 0 ); ?>
                  </span>
                <?php endif; ?>
              </td>
              <td><?php echo $this->source_badge($b->booking_source); ?></td>
              <td style="font-size:12px;color:#5a7068;"><?php echo date('d/m/y', strtotime($b->created_at)); ?></td>
              <td>
                <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>"
                   style="color:#1D9E75;font-size:12px;font-weight:600;white-space:nowrap;"><?php echo esc_html( $this->tt( 'Ver →', 'View →' ) ); ?></a>
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
                    t.meeting_point_es, t.meeting_lat, t.meeting_lng, t.fixed_date, t.custom_quote,
                    s.time_start, s.time_end, s.label_es as schedule_label,
                    p.name as partner_name,
                    r.name_es as room_name_es, r.name_en as room_name_en
             FROM {$wpdb->prefix}amir_bookings b
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             LEFT JOIN {$wpdb->prefix}amir_partners p ON p.id = b.partner_id
             LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
             WHERE b.id = %d",
            $booking_id
        ) );

        if ( ! $b ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $this->tt( 'Reserva no encontrada.', 'Booking not found.' ) ) . '</p></div></div>';
            return;
        }

        $addons = $wpdb->get_results( $wpdb->prepare(
            "SELECT name_snapshot, qty, unit_price_mxn, total_mxn
             FROM {$wpdb->prefix}amir_booking_addons WHERE booking_id = %d ORDER BY id",
            $booking_id
        ) ) ?? [];

        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->admin_styles(); ?>

        <h1 style="display:flex;align-items:center;gap:12px;">
          <a href="<?php echo admin_url('admin.php?page=amir-bookings-list'); ?>" style="color:#5a7068;font-weight:400;font-size:16px;">← <?php echo esc_html( $this->tt( 'Reservas', 'Bookings' ) ); ?></a>
          <span><?php echo esc_html($b->booking_ref); ?></span>
          <?php echo $this->status_badge($b->status); ?>
        </h1>

        <?php if ($message) echo '<div class="notice notice-success is-dismissible"><p>'.$message.'</p></div>'; ?>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;">

          <!-- Columna principal -->
          <div>

            <?php if ( $b->item_type === 'room' ) : ?>
            <!-- Info de la habitación (Pro Max, § 16 CONTRIBUTING.md) -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">🛏 <?php echo esc_html( $this->tt( 'Habitación reservada', 'Booked room' ) ); ?></div>
              <div class="ab-detail-grid">
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Habitación', 'Room' ) ); ?></span><strong><?php echo esc_html($b->room_name_es); ?></strong></div>
                <div class="ab-detail-row"><span>Check-in</span><strong><?php echo esc_html($b->tour_date); ?></strong></div>
                <div class="ab-detail-row"><span>Check-out</span><strong><?php echo esc_html($b->check_out_date); ?></strong></div>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Huéspedes', 'Guests' ) ); ?></span><strong><?php echo (int) $b->adults; ?></strong></div>
                <?php if ($b->special_requests) : ?>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Solicitudes', 'Requests' ) ); ?></span><span><?php echo nl2br(esc_html($b->special_requests)); ?></span></div>
                <?php endif; ?>
              </div>
            </div>
            <?php else : ?>
            <!-- Info del tour -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">🏄 <?php echo esc_html( $this->tt( 'Tour reservado', 'Booked tour' ) ); ?></div>
              <div class="ab-detail-grid">
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Tour', 'Tour' ) ); ?></span><strong><?php echo esc_html($b->tour_name); ?></strong></div>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Fecha', 'Date' ) ); ?></span><strong><?php echo esc_html($b->tour_date); ?></strong></div>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Horario', 'Schedule' ) ); ?></span><strong><?php echo $this->fmt_time($b->time_start); ?> – <?php echo $this->fmt_time($b->time_end); ?> <?php echo $b->schedule_label ? '('.$b->schedule_label.')' : ''; ?></strong></div>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Personas', 'People' ) ); ?></span>
                  <strong>
                    <?php echo $b->adults; ?> <?php echo esc_html( $this->tt( 'adultos', 'adults' ) ); ?>
                    <?php if ($b->children) echo ' · '.$b->children.' '.esc_html( $this->tt( 'niños', 'children' ) ); ?>
                    <?php if ($b->babies)   echo ' · '.$b->babies.' '.esc_html( $this->tt( 'bebés', 'babies' ) ); ?>
                    <span style="color:#5a7068;font-weight:400;"> (<?php echo $b->adults+$b->children+$b->babies; ?> <?php echo esc_html( $this->tt( 'total', 'total' ) ); ?>)</span>
                  </strong>
                </div>
                <?php if ($b->special_requests) : ?>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Solicitudes', 'Requests' ) ); ?></span><span><?php echo esc_html($b->special_requests); ?></span></div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Info del cliente -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">👤 <?php echo esc_html( $this->tt( 'Cliente', 'Customer' ) ); ?></div>
              <div class="ab-detail-grid">
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Nombre', 'Name' ) ); ?></span><strong><?php echo esc_html($b->customer_name); ?></strong></div>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Email', 'Email' ) ); ?></span><a href="mailto:<?php echo esc_attr($b->customer_email); ?>"><?php echo esc_html($b->customer_email); ?></a></div>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Teléfono', 'Phone' ) ); ?></span>
                  <?php echo esc_html($b->customer_phone); ?>
                  <?php if ($b->customer_phone) : ?>
                    <a class="ab-wa-btn" href="https://wa.me/<?php echo preg_replace('/[^0-9]/','', $b->customer_phone); ?>" target="_blank" style="margin-left:8px;">💬 WhatsApp</a>
                  <?php endif; ?>
                </div>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Idioma', 'Language' ) ); ?></span><?php echo strtoupper($b->lang); ?></div>
                <?php if ($b->partner_name) : ?>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Partner', 'Partner' ) ); ?></span><span class="ab-source-chip partner"><?php echo esc_html($b->partner_name); ?></span></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Pago -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">💳 <?php echo esc_html( $this->tt( 'Pago', 'Payment' ) ); ?></div>
              <div class="ab-detail-grid">
                <div class="ab-detail-row"><span><?php echo esc_html( $b->confirmed_at ? $this->tt( 'Total pagado', 'Total paid' ) : $this->tt( 'Total a cobrar', 'Total due' ) ); ?></span><strong style="font-size:18px;"><?php echo \AmirBooking\Core\Currency::format((float)$b->total_mxn); ?></strong></div>
                <?php if ($b->usd_reference) : ?>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Referencia USD', 'USD reference' ) ); ?></span><span>≈ $<?php echo number_format($b->usd_reference,2); ?> USD (<?php echo esc_html( $this->tt( 'tipo', 'rate' ) ); ?> <?php echo $b->exchange_rate; ?>)</span></div>
                <?php endif; ?>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Origen', 'Source' ) ); ?></span><?php echo $this->source_badge($b->booking_source); ?></div>
                <?php if ($b->stripe_payment_intent) : ?>
                <div class="ab-detail-row"><span>Stripe PI</span><code style="font-size:11px;"><?php echo esc_html($b->stripe_payment_intent); ?></code></div>
                <?php endif; ?>
                <?php if ($b->confirmed_at) : ?>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Confirmado el', 'Confirmed on' ) ); ?></span><?php echo esc_html(date('d/m/Y H:i', strtotime($b->confirmed_at))); ?></div>
                <?php endif; ?>
                <?php if ( ! empty( $b->consent_recorded_at ) ) : ?>
                <div class="ab-detail-row">
                  <span><?php echo esc_html( $this->tt( 'Consentimiento (GDPR)', 'Consent (GDPR)' ) ); ?></span>
                  <span title="<?php echo esc_attr( $this->tt( 'Hash del texto de política+términos vigente al momento — ', 'Hash of the policy+terms text in effect at that time — ' ) . ( $b->consent_text_hash ?? '' ) ); ?>">
                    ✓ <?php echo esc_html( date( 'd/m/Y H:i', strtotime( $b->consent_recorded_at ) ) ); ?>
                  </span>
                </div>
                <?php endif; ?>
                <?php if ($b->refund_amount_mxn > 0) : ?>
                <div class="ab-detail-row"><span><?php echo esc_html( $this->tt( 'Reembolso', 'Refund' ) ); ?></span><strong style="color:#e24b4a;">$<?php echo number_format($b->refund_amount_mxn,2); ?> MXN (<?php echo $b->cancellation_policy_pct; ?>% <?php echo esc_html( $this->tt( 'cargo', 'fee' ) ); ?>)</strong></div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Servicios extra -->
            <?php if ( ! empty( $addons ) ) : ?>
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">🎁 <?php echo esc_html( $this->tt( 'Servicios extra', 'Extra services' ) ); ?></div>
              <div class="ab-detail-grid">
                <?php foreach ( $addons as $ad ) : ?>
                <div class="ab-detail-row">
                  <span><?php echo esc_html( $ad->name_snapshot ); ?><?php echo $ad->qty > 1 ? ' × ' . (int) $ad->qty : ''; ?></span>
                  <strong><?php echo \AmirBooking\Core\Currency::format( (float) $ad->total_mxn ); ?></strong>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Notas internas -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">📝 <?php echo esc_html( $this->tt( 'Notas internas (solo visible para el equipo)', 'Internal notes (staff only)' ) ); ?></div>
              <pre style="font-family:inherit;font-size:13px;color:#5a7068;white-space:pre-wrap;margin:0 0 14px;"><?php echo esc_html($b->internal_notes ?: '—'); ?></pre>
              <form method="post">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="add_note" />
                <textarea name="note_text" rows="3" placeholder="<?php echo esc_attr( $this->tt( 'Agregar nota interna…', 'Add internal note…' ) ); ?>"
                          style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px;font-size:13px;box-sizing:border-box;margin-bottom:8px;"></textarea>
                <button type="submit" class="button"><?php echo esc_html( $this->tt( 'Guardar nota', 'Save note' ) ); ?></button>
              </form>
            </div>

          </div><!-- fin columna principal -->

          <!-- Columna derecha: acciones -->
          <div>

            <!-- Acciones disponibles según estado -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">⚡ <?php echo esc_html( $this->tt( 'Acciones', 'Actions' ) ); ?></div>

              <?php if ( $b->status === 'pending' ) : ?>
              <form method="post" style="margin-bottom:10px;">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="manual_confirm" />
                <button type="submit" class="button button-primary" style="width:100%;background:#1D9E75;border-color:#0F6E56;color:#fff;"
                  onclick="return confirm('<?php echo esc_js( $this->tt( '¿Confirmar esta reserva manualmente? Se enviará el email de confirmación y se generará el voucher PDF.', 'Confirm this booking manually? The confirmation email will be sent and the PDF voucher generated.' ) ); ?>')">
                  ✅ <?php echo esc_html( $this->tt( 'Confirmar reserva manualmente', 'Confirm booking manually' ) ); ?>
                </button>
              </form>
              <p style="font-size:11px;color:#5a7068;margin:-6px 0 12px;"><?php echo esc_html( $this->tt( 'Úsalo cuando el webhook de Stripe no procesó la confirmación automática.', 'Use this when the Stripe webhook did not process the automatic confirmation.' ) ); ?></p>
              <?php endif; ?>

              <?php if ( in_array($b->status, ['confirmed','pending'], true) ) : ?>
              <form method="post" style="margin-bottom:10px;">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="resend_confirmation" />
                <button type="submit" class="button" style="width:100%;">📧 <?php echo esc_html( $this->tt( 'Reenviar email de confirmación', 'Resend confirmation email' ) ); ?></button>
              </form>
              <?php endif; ?>

              <?php if ( $b->status === 'awaiting_payment' ) : ?>
              <form method="post" style="margin-bottom:10px;">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="resend_payment_link" />
                <button type="submit" class="button" style="width:100%;">💳 <?php echo esc_html( $this->tt( 'Reenviar link de pago', 'Resend payment link' ) ); ?></button>
              </form>
              <?php endif; ?>

              <?php if ( in_array( $b->status, [ 'pending', 'awaiting_payment' ], true ) ) : ?>
              <!-- Pago recibido fuera de Stripe/MP (transferencia, efectivo,
                   pasarela satélite sin webhook) — no reintenta con la
                   pasarela, registra la referencia que cargue el operador. -->
              <div style="background:#f0faf6;border:1px solid #c3e9dc;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;color:#0F6E56;margin-bottom:8px;">💵 <?php echo esc_html( $this->tt( 'Cargar pago manual', 'Record manual payment' ) ); ?></div>
                <form method="post">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="record_manual_payment" />
                  <input type="text" name="manual_payment_reference" placeholder="<?php echo esc_attr( $this->tt( 'Ej. "Transferencia — ref 4521" o "Efectivo"', 'E.g. "Bank transfer — ref 4521" or "Cash"' ) ); ?>"
                         style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px;font-size:13px;box-sizing:border-box;margin-bottom:8px;" required />
                  <button type="submit" class="button" style="width:100%;"
                    onclick="return confirm('<?php echo esc_js( $this->tt( '¿Marcar esta reserva como pagada con esa referencia? Se enviará el email de confirmación y se generará el voucher PDF.', 'Mark this booking as paid with that reference? The confirmation email will be sent and the PDF voucher generated.' ) ); ?>')">
                    <?php echo esc_html( $this->tt( 'Marcar como pagada', 'Mark as paid' ) ); ?>
                  </button>
                </form>
              </div>
              <?php endif; ?>

              <?php
              // Depósito parcial ("Depósito parcial por tour", Pro Max) —
              // reserva ya confirmada, cobrado solo el % de depósito.
              $has_pending_balance = $b->status === 'confirmed'
                  && ( $b->item_type ?? 'tour' ) === 'tour'
                  && (int) ( $b->deposit_pct ?? 0 ) > 0
                  && empty( $b->balance_paid_at );
              ?>
              <?php if ( $has_pending_balance ) :
                $deposit_charged = round( (float) $b->total_mxn * (int) $b->deposit_pct / 100, 2 );
                $balance_owed     = round( (float) $b->total_mxn - $deposit_charged, 2 );
              ?>
              <div style="background:#fff8e7;border:1px solid #fde68a;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;color:#92400e;margin-bottom:4px;">💰 <?php echo esc_html( sprintf( $this->tt( 'Depósito del %d%% — saldo pendiente', '%d%% deposit — balance pending' ), (int) $b->deposit_pct ) ); ?></div>
                <div style="font-size:13px;color:#78350f;margin-bottom:10px;">
                  <?php echo esc_html( $this->tt( 'Cobrado ahora: ', 'Charged now: ' ) ); ?><?php echo \AmirBooking\Core\Currency::format( $deposit_charged ); ?> ·
                  <?php echo esc_html( $this->tt( 'Saldo: ', 'Balance: ' ) ); ?><strong><?php echo \AmirBooking\Core\Currency::format( $balance_owed ); ?></strong>
                </div>
                <form method="post" style="margin-bottom:8px;">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="mark_balance_paid" />
                  <button type="submit" class="button" style="width:100%;"
                    onclick="return confirm('<?php echo esc_js( $this->tt( '¿Marcar el saldo como cobrado en persona (efectivo/transferencia)? No se manda ningún email.', 'Mark the balance as collected in person (cash/transfer)? No email will be sent.' ) ); ?>')">
                    💰 <?php echo esc_html( $this->tt( 'Marcar saldo cobrado (en persona)', 'Mark balance as collected (in person)' ) ); ?>
                  </button>
                </form>
                <form method="post">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="send_balance_link" />
                  <button type="submit" class="button" style="width:100%;">📧 <?php echo esc_html( $this->tt( 'Enviar link de pago del saldo', 'Send payment link for the balance' ) ); ?></button>
                </form>
              </div>
              <?php endif; ?>

              <?php if ( $b->status === 'date_requested' ) : ?>
              <!-- Solicitud de fecha en tour de fecha fija, o "armá tu tour"
                   sin precio (custom_quote, § CLAUDE.md) — sin cobro todavía,
                   aprobar manda el link de pago (mismo mecanismo que Lista de
                   interés, no el de proveedores externos). -->
              <div style="background:#e8f4ff;border:1px solid #bcdcf7;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;color:#1a6fa8;margin-bottom:8px;">
                  <?php echo $b->custom_quote ? '🧩 ' . esc_html( $this->tt( 'Armá tu tour — a cotizar', 'Build your own tour — to quote' ) ) : '📅 ' . esc_html( $this->tt( 'Solicitud de fecha', 'Date request' ) ); ?>
                </div>
                <?php if ( $b->custom_quote ) : ?>
                <p style="font-size:13px;color:#5a7068;margin:0 0 10px;">
                  <?php echo esc_html( $this->tt( 'Este tour no tiene precio fijo — lo que pidió el cliente está en "Solicitudes" arriba. Cargá el precio real antes de aprobar; se le manda el link de pago por ese monto.', 'This tour has no fixed price — what the customer asked for is under "Requests" above. Set the real price before approving; the payment link will be sent for that amount.' ) ); ?>
                </p>
                <?php else : ?>
                <p style="font-size:13px;color:#5a7068;margin:0 0 10px;">
                  <?php echo esc_html( $this->tt( 'El cliente pidió el', 'The customer requested' ) ); ?> <strong><?php echo esc_html( date( 'd/m/Y', strtotime( $b->tour_date ) ) ); ?></strong>
                  <?php if ( ! empty( $b->fixed_date ) ) : ?>
                    (<?php echo esc_html( sprintf( $this->tt( 'la fecha fija actual del tour es %s', "the tour's current fixed date is %s" ), esc_html( date( 'd/m/Y', strtotime( $b->fixed_date ) ) ) ) ); ?>).
                  <?php else : ?>
                    <?php echo esc_html( $this->tt( 'para este tour.', 'for this tour.' ) ); ?>
                  <?php endif; ?>
                  <?php echo esc_html( $this->tt( 'No se le cobró nada todavía — si aprobás, se le manda el link de pago por email.', 'Nothing has been charged yet — if you approve, the payment link will be emailed.' ) ); ?>
                </p>
                <?php endif; ?>
                <form method="post" style="margin-bottom:8px;">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="approve_date_request" />
                  <?php if ( $b->custom_quote ) : ?>
                  <label style="display:block;font-size:12px;font-weight:600;color:#1a2e24;margin-bottom:4px;"><?php echo esc_html( sprintf( $this->tt( 'Precio a cobrar (%s)', 'Price to charge (%s)' ), esc_html( \AmirBooking\Core\Currency::code() ) ) ); ?></label>
                  <input type="number" name="quoted_price" min="0" step="0.01" required
                         value="<?php echo esc_attr( $b->total_mxn > 0 ? $b->total_mxn : '' ); ?>"
                         placeholder="<?php echo esc_attr( $this->tt( 'Ej: 2500.00', 'E.g.: 2500.00' ) ); ?>"
                         style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px;font-size:13px;box-sizing:border-box;margin-bottom:8px;" />
                  <?php endif; ?>
                  <!-- Bug real reportado por el cliente (2026-08-18): el email
                       de link de pago (PaymentLinkEmail) ya sabía mostrar una
                       nota del operador, pero este formulario nunca tuvo
                       dónde cargarla — a diferencia del de rechazar, que sí.
                       Mismo campo/mecanismo que ya usan create_manual() y el
                       rechazo (BaseEmail::custom_email_note, "Nota del operador"). -->
                  <label style="display:block;font-size:12px;font-weight:600;color:#1a2e24;margin-bottom:4px;"><?php echo esc_html( $this->tt( 'Mensaje para el cliente (opcional)', 'Message for the customer (optional)' ) ); ?></label>
                  <textarea name="custom_email_note" rows="3" placeholder="<?php echo esc_attr( $this->tt( 'Ej: Armamos el recorrido con 2 paradas extra que pediste — el precio ya las incluye.', 'E.g.: We put together the route with the 2 extra stops you asked for — the price already includes them.' ) ); ?>"
                            style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px;font-size:13px;box-sizing:border-box;margin-bottom:8px;"><?php echo esc_textarea( $b->custom_email_note ?? '' ); ?></textarea>
                  <button type="submit" class="button button-primary" style="width:100%;background:#1D9E75;border-color:#0F6E56;color:#fff;"
                    onclick="return confirm('<?php echo esc_js( $this->tt( '¿Aprobar esta solicitud y mandar el link de pago al cliente?', 'Approve this request and send the payment link to the customer?' ) ); ?>')">
                    ✅ <?php echo esc_html( $this->tt( 'Aprobar y enviar link de pago', 'Approve and send payment link' ) ); ?>
                  </button>
                </form>
                <form method="post">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="reject_date_request" />
                  <textarea name="reject_message" rows="3" placeholder="<?php echo esc_attr( $this->tt( 'Mensaje opcional para el cliente — ej. por qué no se pudo confirmar, o una fecha alternativa que sugerís.', 'Optional message for the customer — e.g. why it couldn\'t be confirmed, or an alternative date you suggest.' ) ); ?>"
                            style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px;font-size:13px;box-sizing:border-box;margin-bottom:8px;"></textarea>
                  <button type="submit" class="button" style="width:100%;"
                    onclick="return confirm('<?php echo esc_js( $this->tt( '¿Rechazar esta solicitud? Se le avisará al cliente por email.', 'Reject this request? The customer will be notified by email.' ) ); ?>')">
                    ✗ <?php echo esc_html( $this->tt( 'Rechazar solicitud y avisar al cliente', 'Reject request and notify customer' ) ); ?>
                  </button>
                </form>
              </div>
              <?php endif; ?>

              <?php if ( $b->status === 'date_request_rejected' ) : ?>
              <!-- Reactivar una solicitud rechazada — ej. el operador
                   reconsidera y quiere mandar otra oferta (otra fecha, otro
                   precio). No manda ningún email al reactivar; el próximo
                   aviso real sale al aprobar de nuevo. -->
              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;color:#c53030;margin-bottom:8px;">✗ <?php echo esc_html( $this->tt( 'Solicitud rechazada', 'Request rejected' ) ); ?></div>
                <p style="font-size:13px;color:#5a7068;margin:0 0 10px;"><?php echo esc_html( $this->tt( '¿El cliente escribió de nuevo, o querés mandarle otra oferta (otra fecha, otro precio)? Reactivá la solicitud — vuelve a aparecer como pendiente, con la opción de aprobar o rechazar.', 'Did the customer write again, or do you want to send them another offer (a different date, a different price)? Reactivate the request — it goes back to pending, with the option to approve or reject.' ) ); ?></p>
                <form method="post">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="reactivate_date_request" />
                  <button type="submit" class="button button-primary" style="width:100%;background:#1D9E75;border-color:#0F6E56;color:#fff;">
                    ↩ <?php echo esc_html( $this->tt( 'Reactivar solicitud', 'Reactivate request' ) ); ?>
                  </button>
                </form>
              </div>
              <?php endif; ?>

              <?php if ( $b->status === 'pending_provider_approval' ) : ?>
              <form method="post" style="margin-bottom:10px;">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="resend_provider_notice" />
                <button type="submit" class="button" style="width:100%;">📨 <?php echo esc_html( $this->tt( 'Reenviar aviso al proveedor', 'Resend notice to provider' ) ); ?></button>
              </form>
              <?php endif; ?>

              <?php if ( $b->status === 'cancellation_requested' ) : ?>
              <!-- Aprobar cancelación (manual) -->
              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="font-size:13px;font-weight:700;color:#e24b4a;margin-bottom:8px;"><?php echo esc_html( $this->tt( 'Solicitud de cancelación', 'Cancellation request' ) ); ?></div>
                <p style="font-size:13px;color:#5a7068;margin:0 0 10px;">
                  <?php echo esc_html( $this->tt( 'El cliente solicitó cancelar esta reserva. Revisa la política y procesa el reembolso manualmente en el dashboard de Stripe si corresponde.', 'The customer requested to cancel this booking. Check the policy and process the refund manually in the Stripe dashboard if applicable.' ) ); ?>
                </p>
                <p style="font-size:12px;color:#5a7068;margin:0 0 12px;">
                  <?php echo $this->cancellation_policy_text($b); ?>
                </p>
                <form method="post" style="display:flex;gap:8px;">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="approve_cancellation" />
                  <textarea name="cancel_note" rows="2" placeholder="<?php echo esc_attr( $this->tt( 'Nota sobre el reembolso (opcional)…', 'Note about the refund (optional)…' ) ); ?>"
                            style="flex:1;border:1px solid #fecaca;border-radius:6px;padding:7px;font-size:12px;box-sizing:border-box;"></textarea>
                  <button type="submit" class="button" style="background:#e24b4a;color:#fff;border-color:#e24b4a;align-self:flex-start;flex-shrink:0;"><?php echo esc_html( $this->tt( 'Aprobar cancelación', 'Approve cancellation' ) ); ?></button>
                </form>
                <form method="post" style="margin-top:8px;">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="reject_cancellation" />
                  <button type="submit" class="button" style="width:100%;">✗ <?php echo esc_html( $this->tt( 'Rechazar solicitud (mantener reserva)', 'Reject request (keep booking)' ) ); ?></button>
                </form>
              </div>
              <?php endif; ?>

              <?php if ( in_array($b->status, ['confirmed'], true) ) : ?>
              <!-- Cancelar por operador -->
              <details style="margin-bottom:10px;">
                <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#e24b4a;padding:8px 0;"><?php echo esc_html( $this->tt( 'Cancelar por condición climática / mínimo pax', 'Cancel due to weather / minimum pax' ) ); ?></summary>
                <div style="padding-top:10px;">
                  <form method="post">
                    <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                    <input type="hidden" name="amir_action" value="cancel_operator" />
                    <select name="cancel_reason" style="<?php echo $this->input_style(); ?> width:100%;margin-bottom:8px;">
                      <option value="weather"><?php echo esc_html( $this->tt( 'Condiciones climáticas', 'Weather conditions' ) ); ?></option>
                      <option value="min_pax"><?php echo esc_html( $this->tt( 'Mínimo de pasajeros no alcanzado', 'Minimum passengers not reached' ) ); ?></option>
                    </select>
                    <p style="font-size:12px;color:#5a7068;margin:0 0 8px;"><?php echo esc_html( $this->tt( 'Reembolso completo al cliente. Procesar manualmente en Stripe.', 'Full refund to the customer. Process manually in Stripe.' ) ); ?></p>
                    <button type="submit" class="button" onclick="return confirm('<?php echo esc_js( $this->tt( '¿Confirmas la cancelación? El cliente será notificado por email.', 'Confirm the cancellation? The customer will be notified by email.' ) ); ?>')"><?php echo esc_html( $this->tt( 'Confirmar cancelación', 'Confirm cancellation' ) ); ?></button>
                  </form>
                </div>
              </details>

              <!-- Reprogramar -->
              <?php if ( $b->item_type !== 'room' ) : ?>
              <details style="margin-bottom:10px;">
                <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#1D9E75;padding:8px 0;"><?php echo esc_html( $this->tt( 'Reprogramar reserva', 'Reschedule booking' ) ); ?></summary>
                <div style="padding-top:10px;">
                  <form method="post">
                    <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                    <input type="hidden" name="amir_action" value="reschedule" />
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo esc_html( $this->tt( 'Nueva fecha', 'New date' ) ); ?></label>
                    <input type="date" name="new_date" min="<?php echo date('Y-m-d',strtotime('+1 day')); ?>"
                           style="<?php echo $this->input_style(); ?> width:100%;margin-bottom:8px;" required />
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo esc_html( $this->tt( 'Nuevo horario', 'New schedule' ) ); ?></label>
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
                    <button type="submit" class="button button-primary" style="width:100%;"><?php echo esc_html( $this->tt( 'Confirmar reprogramación', 'Confirm reschedule' ) ); ?></button>
                  </form>
                </div>
              </details>
              <?php else : ?>
              <!-- Reprogramar habitación — TourFlow\Rooms\RoomBookingManager::reschedule() -->
              <details style="margin-bottom:10px;">
                <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#1D9E75;padding:8px 0;"><?php echo esc_html( $this->tt( 'Reprogramar check-in/check-out', 'Reschedule check-in/check-out' ) ); ?></summary>
                <div style="padding-top:10px;">
                  <form method="post">
                    <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                    <input type="hidden" name="amir_action" value="reschedule_room" />
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo esc_html( $this->tt( 'Nuevo check-in', 'New check-in' ) ); ?></label>
                    <input type="date" name="new_check_in" value="<?php echo esc_attr($b->tour_date); ?>"
                           style="<?php echo $this->input_style(); ?> width:100%;margin-bottom:8px;" required />
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php echo esc_html( $this->tt( 'Nuevo check-out', 'New check-out' ) ); ?></label>
                    <input type="date" name="new_check_out" value="<?php echo esc_attr($b->check_out_date); ?>"
                           style="<?php echo $this->input_style(); ?> width:100%;margin-bottom:8px;" required />
                    <p style="font-size:11px;color:#5a7068;margin:0 0 8px;"><?php echo esc_html( $this->tt( 'El total cobrado no se ajusta solo si cambia la cantidad de noches — coordiná el ajuste manualmente si corresponde.', 'The amount charged is not adjusted automatically if the number of nights changes — coordinate the adjustment manually if needed.' ) ); ?></p>
                    <button type="submit" class="button button-primary" style="width:100%;"><?php echo esc_html( $this->tt( 'Confirmar reprogramación', 'Confirm reschedule' ) ); ?></button>
                  </form>
                </div>
              </details>
              <?php endif; ?>
              <?php endif; ?>

              <!-- Voucher — solo tours: VoucherGenerator también hace INNER JOIN con amir_tours, sin equivalente para habitaciones todavía -->
              <?php if ( $b->item_type !== 'room' && ( $b->status === 'confirmed' || $b->status === 'completed' ) ) : ?>
              <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=pdf&id='.$booking_id); ?>"
                 class="button" style="width:100%;text-align:center;display:block;margin-bottom:10px;box-sizing:border-box;" target="_blank">
                📄 <?php echo esc_html( $this->tt( 'Ver voucher PDF', 'View PDF voucher' ) ); ?>
              </a>
              <?php endif; ?>

              <!-- Cambio de estado libre — existía solo como endpoint REST
                   (POST /bookings/{id}/status) sin ningún botón en el admin.
                   Colapsado por defecto (mismo patrón que el editor de tours,
                   § 16.62 CONTRIBUTING.md) — es una acción de uso ocasional,
                   no algo que un operador necesite ver siempre. -->
              <details style="margin:10px 0;border-top:1px solid #e1f5ee;padding-top:8px;">
                <summary style="cursor:pointer;font-size:12.5px;font-weight:700;color:#5a7068;">🔧 <?php echo esc_html( $this->tt( 'Cambiar estado manualmente', 'Change status manually' ) ); ?></summary>
                <form method="post" style="margin-top:8px;">
                  <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                  <input type="hidden" name="amir_action" value="change_status" />
                  <select name="new_status" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px;font-size:13px;box-sizing:border-box;margin-bottom:8px;">
                    <option value="pending"           <?php selected($b->status,'pending'); ?>><?php echo esc_html( $this->tt( 'Pendiente', 'Pending' ) ); ?></option>
                    <option value="awaiting_payment"  <?php selected($b->status,'awaiting_payment'); ?>><?php echo esc_html( $this->tt( 'Esperando pago', 'Awaiting payment' ) ); ?></option>
                    <option value="date_requested"        <?php selected($b->status,'date_requested'); ?>><?php echo esc_html( $this->tt( 'Fecha solicitada', 'Date requested' ) ); ?></option>
                    <option value="date_request_rejected" <?php selected($b->status,'date_request_rejected'); ?>><?php echo esc_html( $this->tt( 'Solicitud rechazada', 'Request rejected' ) ); ?></option>
                    <option value="confirmed"         <?php selected($b->status,'confirmed'); ?>><?php echo esc_html( $this->tt( 'Confirmada', 'Confirmed' ) ); ?></option>
                    <option value="completed"         <?php selected($b->status,'completed'); ?>><?php echo esc_html( $this->tt( 'Completada', 'Completed' ) ); ?></option>
                    <option value="rescheduled"       <?php selected($b->status,'rescheduled'); ?>><?php echo esc_html( $this->tt( 'Reprogramada', 'Rescheduled' ) ); ?></option>
                    <option value="cancelled_client"   <?php selected($b->status,'cancelled_client'); ?>><?php echo esc_html( $this->tt( 'Cancelada (cliente)', 'Cancelled (customer)' ) ); ?></option>
                    <option value="cancelled_weather"  <?php selected($b->status,'cancelled_weather'); ?>><?php echo esc_html( $this->tt( 'Cancelada (clima)', 'Cancelled (weather)' ) ); ?></option>
                    <option value="cancelled_min_pax"  <?php selected($b->status,'cancelled_min_pax'); ?>><?php echo esc_html( $this->tt( 'Cancelada (mínimo de pax)', 'Cancelled (minimum pax)' ) ); ?></option>
                  </select>
                  <button type="submit" class="button" style="width:100%;"
                    onclick="return confirm('<?php echo esc_js( $this->tt( '¿Cambiar el estado de esta reserva? Esto NO calcula reembolsos ni manda emails de cancelación — solo cambia el estado. Para cancelar con reembolso, usá la acción de cancelación correspondiente.', 'Change the status of this booking? This does NOT calculate refunds or send cancellation emails — it only changes the status. To cancel with a refund, use the corresponding cancellation action.' ) ); ?>')">
                    <?php echo esc_html( $this->tt( 'Cambiar estado', 'Change status' ) ); ?>
                  </button>
                </form>
                <p style="font-size:11px;color:#5a7068;margin:6px 0 0;"><?php echo esc_html( $this->tt( 'Para casos que no tienen un botón dedicado arriba. No dispara reembolsos — para eso, cancelá desde la acción específica.', 'For cases without a dedicated button above. It does not trigger refunds — for that, cancel from the specific action.' ) ); ?></p>
              </details>

              <!-- "Derecho al olvido" (GDPR, § 15.3/16.21 CONTRIBUTING.md) — manual, decisión del cliente 2026-08-04 -->
              <?php if ( empty( $b->anonymized_at ) ) : ?>
              <form method="post" style="margin-top:10px;" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Anonimizar los datos personales de esta reserva? Se borra nombre, email, teléfono y notas — el total, las fechas y la referencia quedan para contabilidad. No se puede deshacer.', 'Anonymize this booking\'s personal data? Name, email, phone, and notes are erased — the total, dates, and reference remain for accounting. This cannot be undone.' ) ); ?>');">
                <?php wp_nonce_field('amir_booking_action_'.$booking_id); ?>
                <input type="hidden" name="amir_action" value="anonymize" />
                <button type="submit" class="button" style="width:100%;color:#e24b4a;border-color:#e24b4a;">🗑 <?php echo esc_html( $this->tt( 'Anonimizar datos del cliente (GDPR)', "Anonymize customer's data (GDPR)" ) ); ?></button>
              </form>
              <?php else : ?>
              <p style="font-size:11px;color:#5a7068;margin:10px 0 0;">🗑 <?php echo esc_html( sprintf( $this->tt( 'Datos anonimizados el %s.', 'Data anonymized on %s.' ), esc_html( date( 'd/m/Y H:i', strtotime( $b->anonymized_at ) ) ) ) ); ?></p>
              <?php endif; ?>

            </div><!-- acciones -->

            <!-- Historial de emails -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">📨 <?php echo esc_html( $this->tt( 'Emails enviados', 'Emails sent' ) ); ?></div>
              <div style="font-size:13px;color:#5a7068;">
                <?php if ($b->confirmed_at)          : ?><div style="padding:5px 0;border-bottom:1px solid #f5f5f5;">✅ <?php echo esc_html( $this->tt( 'Confirmación', 'Confirmation' ) ); ?> — <?php echo date('d/m/Y H:i', strtotime($b->confirmed_at)); ?></div><?php endif; ?>
                <?php if ($b->wishlist_notice_sent_at) : ?><div style="padding:5px 0;border-bottom:1px solid #f5f5f5;">🔔 <?php echo esc_html( $this->tt( 'Tour abierto (link de pago)', 'Tour open (payment link)' ) ); ?> — <?php echo date('d/m/Y H:i', strtotime($b->wishlist_notice_sent_at)); ?></div><?php endif; ?>
                <?php if ($b->reminder_sent_at)      : ?><div style="padding:5px 0;border-bottom:1px solid #f5f5f5;">⏰ <?php echo esc_html( $this->tt( 'Recordatorio', 'Reminder' ) ); ?> — <?php echo date('d/m/Y H:i', strtotime($b->reminder_sent_at)); ?></div><?php endif; ?>
                <?php if ($b->review_email_sent_at)  : ?><div style="padding:5px 0;">⭐ <?php echo esc_html( $this->tt( 'Solicitud reseña', 'Review request' ) ); ?> — <?php echo date('d/m/Y H:i', strtotime($b->review_email_sent_at)); ?></div><?php endif; ?>
                <?php if (!empty($b->wishlist_notice_error)) : ?>
                  <div style="padding:5px 0;border-bottom:1px solid #f5f5f5;color:#e24b4a;">⚠️ <?php echo esc_html( $this->tt( 'Falló el email de "tour abierto"', 'The "tour open" email failed' ) ); ?> — <?php echo esc_html($b->wishlist_notice_error); ?></div>
                <?php endif; ?>
                <?php if (!$b->confirmed_at && !$b->reminder_sent_at && !$b->review_email_sent_at && !$b->wishlist_notice_sent_at && empty($b->wishlist_notice_error)) : ?>
                  <span><?php echo esc_html( $this->tt( 'No se han enviado emails aún.', 'No emails sent yet.' ) ); ?></span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Datos de auditoría -->
            <div class="ab-detail-card">
              <div class="ab-detail-card-title">🔍 <?php echo esc_html( $this->tt( 'Auditoría', 'Audit' ) ); ?></div>
              <div style="font-size:12px;color:#5a7068;line-height:1.8;">
                <div><?php echo esc_html( $this->tt( 'Creada', 'Created' ) ); ?>: <?php echo date('d/m/Y H:i', strtotime($b->created_at)); ?></div>
                <?php if ($b->updated_at) : ?><div><?php echo esc_html( $this->tt( 'Actualizada', 'Updated' ) ); ?>: <?php echo date('d/m/Y H:i', strtotime($b->updated_at)); ?></div><?php endif; ?>
                <div><?php echo esc_html( $this->tt( 'Idioma', 'Language' ) ); ?>: <?php echo strtoupper($b->lang); ?></div>
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
                $note = sanitize_textarea_field( wp_unslash( $_POST['note_text'] ?? '' ) );
                if ( $note ) {
                    $existing = $wpdb->get_var($wpdb->prepare("SELECT internal_notes FROM {$wpdb->prefix}amir_bookings WHERE id=%d", $booking_id));
                    $new_notes = trim($existing) . "\n[" . current_time('d/m/Y H:i') . " — " . wp_get_current_user()->display_name . "] " . $note;
                    $wpdb->update("{$wpdb->prefix}amir_bookings", ['internal_notes'=>$new_notes], ['id'=>$booking_id]);
                    return $this->tt( 'Nota agregada correctamente.', 'Note added successfully.' );
                }
                break;

            case 'manual_confirm':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( $b && $b->status === 'pending' ) {
                    $manager = new \AmirBooking\Core\BookingManager();
                    $manager->confirm( $booking_id, $b->gateway_charge_id ?: ( $b->stripe_charge_id ?? '' ) );
                    return '✅ ' . $this->tt( 'Reserva confirmada manualmente. Email y PDF en proceso.', 'Booking confirmed manually. Email and PDF in progress.' );
                }
                return $this->tt( 'La reserva no está en estado pendiente.', 'The booking is not in pending status.' );

            // Pago recibido por fuera de Stripe/Mercado Pago (transferencia,
            // efectivo, o una pasarela satélite sin webhook) — a diferencia
            // de 'manual_confirm' (que reintenta con el charge de la pasarela
            // que ya estaba en la reserva), acá el operador carga su propia
            // referencia y queda registrado como payment_gateway='manual'
            // (PaymentGatewayFactory::make('manual') resuelve a null a
            // propósito — un reembolso futuro no va a intentar llamar a
            // ninguna API, ver amir_process_gateway_refund en class-plugin.php).
            // Funciona tanto para 'pending' como 'awaiting_payment' (lista de
            // interés / solicitud de fecha ya aprobada) — confirm() ahora
            // acepta los dos.
            case 'record_manual_payment':
                $reference = sanitize_text_field( wp_unslash( $_POST['manual_payment_reference'] ?? '' ) );
                if ( ! $reference ) {
                    return $this->tt( 'Ingresá una referencia del pago (ej. "Transferencia — ref 4521", "Efectivo").', 'Enter a payment reference (e.g. "Bank transfer — ref 4521", "Cash").' );
                }
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b || ! in_array( $b->status, [ 'pending', 'awaiting_payment' ], true ) ) {
                    return $this->tt( 'Esta reserva no está pendiente de pago.', 'This booking is not pending payment.' );
                }
                $wpdb->update( "{$wpdb->prefix}amir_bookings", [ 'payment_gateway' => 'manual' ], [ 'id' => $booking_id ], [ '%s' ], [ '%d' ] );
                $manager = new \AmirBooking\Core\BookingManager();
                $manager->confirm( $booking_id, $reference );
                return '✅ ' . sprintf( $this->tt( 'Pago manual registrado ("%s"). Email y PDF en proceso.', 'Manual payment recorded ("%s"). Email and PDF in progress.' ), esc_html( $reference ) );

            // Depósito parcial ("Depósito parcial por tour") — el resto del
            // tour se cobró en efectivo/transferencia en persona. Solo deja
            // constancia con fecha, sin mandar email (es un asiento interno,
            // el cliente ya sabe que pagó) y sin tocar `status` — la reserva
            // ya está `confirmed`, esto no la reconfirma de nuevo.
            case 'mark_balance_paid':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b || $b->status !== 'confirmed' || ( $b->item_type ?? 'tour' ) !== 'tour'
                     || (int) ( $b->deposit_pct ?? 0 ) <= 0 || ! empty( $b->balance_paid_at ) ) {
                    return $this->tt( 'Esta reserva no tiene un saldo de depósito pendiente.', 'This booking has no pending deposit balance.' );
                }
                $wpdb->update( "{$wpdb->prefix}amir_bookings", [ 'balance_paid_at' => current_time('mysql') ], [ 'id' => $booking_id ], [ '%s' ], [ '%d' ] );
                return '✅ ' . $this->tt( 'Saldo marcado como cobrado.', 'Balance marked as collected.' );

            // Alternativa a la de arriba: en vez de un cobro en persona, se
            // le manda al cliente un link para pagar el saldo online (mismo
            // mecanismo de siempre — init_payment() ya sabe calcular "el
            // saldo, no el total" para una reserva confirmada con depósito).
            case 'send_balance_link':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b || $b->status !== 'confirmed' || ( $b->item_type ?? 'tour' ) !== 'tour'
                     || (int) ( $b->deposit_pct ?? 0 ) <= 0 || ! empty( $b->balance_paid_at ) ) {
                    return $this->tt( 'Esta reserva no tiene un saldo de depósito pendiente.', 'This booking has no pending deposit balance.' );
                }
                $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
                $b_full     = $dispatcher->get_booking_with_tour( $booking_id );
                $result     = $dispatcher->send_balance_payment_link_notice( $b_full ?: $b );
                return $result['success']
                    ? $this->tt( 'Link de pago del saldo enviado al cliente.', 'Payment link for the balance sent to the customer.' )
                    : $this->tt( 'No se pudo enviar el email — revisa el log de errores del servidor.', 'The email could not be sent — check the server error log.' );

            // Cambio de estado libre — hasta ahora solo existía como
            // endpoint REST (POST /bookings/{id}/status), sin ningún botón
            // en el admin; el operador tenía que armar el request a mano.
            // Mismo criterio que ese endpoint (confirmed desde pending usa
            // el flujo completo con email/PDF, el resto es un update directo).
            case 'change_status':
                $new_status = sanitize_key( $_POST['new_status'] ?? '' );
                $allowed = [ 'confirmed', 'pending', 'awaiting_payment', 'date_requested',
                             'date_request_rejected', 'cancelled_client', 'cancelled_weather',
                             'cancelled_min_pax', 'rescheduled', 'completed' ];
                if ( ! in_array( $new_status, $allowed, true ) ) {
                    return $this->tt( 'Estado no válido.', 'Invalid status.' );
                }
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b ) {
                    return $this->tt( 'Reserva no encontrada.', 'Booking not found.' );
                }
                if ( $new_status === 'confirmed' && in_array( $b->status, [ 'pending', 'awaiting_payment' ], true ) ) {
                    $manager = new \AmirBooking\Core\BookingManager();
                    $manager->confirm( $booking_id, $b->gateway_charge_id ?: ( $b->stripe_charge_id ?? '' ) );
                } else {
                    $wpdb->update(
                        "{$wpdb->prefix}amir_bookings",
                        [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
                        [ 'id' => $booking_id ], [ '%s', '%s' ], [ '%d' ]
                    );
                }
                return '✅ ' . sprintf( $this->tt( 'Estado cambiado a "%s".', 'Status changed to "%s".' ), esc_html( $new_status ) );

            case 'resend_confirmation':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ($b) {
                    // Reservas de habitación (item_type='room', § 16
                    // CONTRIBUTING.md): amir_booking_confirmed no hace nada
                    // acá (get_booking_with_tour() no encuentra fila, tour_id
                    // es NULL) — hook propio, mismo criterio que
                    // BookingManager::finalize_confirmation().
                    if ( ( $b->item_type ?? 'tour' ) === 'room' ) {
                        do_action( 'flow_room_booking_confirmed', $booking_id );
                    } else {
                        do_action('amir_booking_confirmed', $booking_id);
                    }
                    return $this->tt( 'Email de confirmación reenviado.', 'Confirmation email resent.' );
                }
                break;

            case 'resend_payment_link':
                $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
                $b = $dispatcher->get_booking_with_tour($booking_id);
                if ($b && $b->status === 'awaiting_payment') {
                    $result = $dispatcher->send_payment_link_notice($b);
                    return $result['success']
                        ? $this->tt( 'Link de pago reenviado al cliente.', 'Payment link resent to the customer.' )
                        : $this->tt( 'No se pudo enviar el email — revisa el log de errores del servidor.', 'The email could not be sent — check the server error log.' );
                }
                return $this->tt( 'La reserva no está en estado "esperando pago".', 'The booking is not in "awaiting payment" status.' );

            case 'resend_provider_notice':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ($b && $b->status === 'pending_provider_approval') {
                    // Reusa el token existente (no lo regenera) y no toca
                    // provider_notified_at — un reenvío manual no debe
                    // reiniciar el plazo de 24h/48h configurado.
                    $result = (new \AmirBooking\Emails\EmailDispatcher())->send_provider_notice($booking_id);
                    return $result['success']
                        ? $this->tt( 'Aviso reenviado al proveedor.', 'Notice resent to the provider.' )
                        : $this->tt( 'No se pudo enviar el email: ', 'The email could not be sent: ' ) . $result['error'];
                }
                return $this->tt( 'La reserva no está esperando aprobación del proveedor.', 'The booking is not awaiting provider approval.' );

            // Solicitud de fecha (tour de fecha fija, § "solicitar fecha" en
            // CLAUDE.md) — aprobar transiciona a 'awaiting_payment' y manda
            // el link de pago, exactamente igual que
            // WishlistPage::notify_interested() para una sola reserva (el
            // precio ya está congelado desde que se creó la solicitud, no
            // se recalcula acá).
            case 'approve_date_request':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b || $b->status !== 'date_requested' ) {
                    return $this->tt( 'Esta reserva no es una solicitud de fecha pendiente.', 'This booking is not a pending date request.' );
                }
                $update_data   = [ 'status' => 'awaiting_payment' ];
                $update_format = [ '%s' ];
                // "Armá tu tour" (custom_quote) nace en $0 — el operador carga
                // el precio real recién acá. Si viene el campo (solo la vista
                // custom_quote lo manda) y es un número válido ≥ 0, se
                // actualiza el total antes de mandar el link de pago.
                if ( isset( $_POST['quoted_price'] ) && is_numeric( $_POST['quoted_price'] ) && (float) $_POST['quoted_price'] >= 0 ) {
                    $update_data['total_mxn']   = (float) $_POST['quoted_price'];
                    $update_format[]            = '%f';
                }
                // Bug real corregido acá: el mensaje personalizado nunca se
                // guardaba en esta acción — PaymentLinkEmail ya lo mostraba
                // si existía, pero nada lo escribía. wp_unslash() antes de
                // sanitizar (mismo criterio que create_manual(), § bug real
                // 2026-08-05 con comillas en texto libre).
                $custom_note = sanitize_textarea_field( wp_unslash( $_POST['custom_email_note'] ?? '' ) );
                $update_data['custom_email_note'] = $custom_note;
                $update_format[]                  = '%s';
                $wpdb->update(
                    "{$wpdb->prefix}amir_bookings",
                    $update_data,
                    [ 'id' => $booking_id ], $update_format, [ '%d' ]
                );
                $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
                $booking_full = $dispatcher->get_booking_with_tour( $booking_id );
                $result = $dispatcher->send_payment_link_notice( $booking_full );
                return $result['success']
                    ? '✅ ' . $this->tt( 'Fecha aprobada. Link de pago enviado al cliente.', 'Date approved. Payment link sent to the customer.' )
                    : '✅ ' . $this->tt( 'Fecha aprobada, pero no se pudo enviar el email — revisa el log de errores del servidor.', 'Date approved, but the email could not be sent — check the server error log.' );

            case 'reject_date_request':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b || $b->status !== 'date_requested' ) {
                    return $this->tt( 'Esta reserva no es una solicitud de fecha pendiente.', 'This booking is not a pending date request.' );
                }
                $reject_message = sanitize_textarea_field( wp_unslash( $_POST['reject_message'] ?? '' ) );
                $internal_note  = "\n[" . current_time('d/m/Y H:i') . "] Solicitud de fecha rechazada por " . wp_get_current_user()->display_name . "."
                    . ( $reject_message !== '' ? "\nMensaje enviado al cliente: " . $reject_message : '' );
                $wpdb->update(
                    "{$wpdb->prefix}amir_bookings",
                    [
                        // Status propio en vez de reusar 'cancelled_client' —
                        // bug de confusión real reportado por el cliente: la
                        // reserva nunca la canceló el cliente, la rechazó el
                        // operador. "Cancelada (cliente)" mentía sobre quién
                        // tomó la decisión.
                        'status'            => 'date_request_rejected',
                        // Reusa el mismo campo que ya renderizan las plantillas
                        // de email (BaseEmail::custom_email_note, § "Nota del
                        // operador") — nada nuevo que mantener, y de paso queda
                        // guardado en la reserva por si se consulta después.
                        'custom_email_note' => $reject_message,
                        'internal_notes'    => trim( $b->internal_notes ?? '' ) . $internal_note,
                    ],
                    [ 'id' => $booking_id ], [ '%s', '%s', '%s' ], [ '%d' ]
                );
                // Bug real reportado por el cliente: rechazar una solicitud de
                // fecha no avisaba nunca al cliente — se enteraba de que no
                // pasaba nada. Mismo mecanismo que approve_date_request de
                // arriba (EmailDispatcher + get_booking_with_tour()).
                $dispatcher   = new \AmirBooking\Emails\EmailDispatcher();
                $booking_full = $dispatcher->get_booking_with_tour( $booking_id );
                $result       = $dispatcher->send_date_request_rejected_notice( $booking_full );
                return $result['success']
                    ? '✗ ' . $this->tt( 'Solicitud rechazada. Se avisó al cliente por email.', 'Request rejected. The customer was notified by email.' )
                    : '✗ ' . $this->tt( 'Solicitud rechazada, pero no se pudo enviar el email — revisa el log de errores del servidor.', 'Request rejected, but the email could not be sent — check the server error log.' );

            // Pedido real del cliente 2026-08-18: una solicitud rechazada no
            // tenía forma de volver atrás — ej. el operador reconsidera y
            // quiere mandar otra oferta (otra fecha, otro precio). Reactivar
            // solo devuelve el estado a 'date_requested' — sin mandar ningún
            // email acá (el cliente ya recibió el de rechazo); el próximo
            // aviso real sale cuando el operador aprueba de nuevo con el
            // campo de precio editable (§ 16.65 CONTRIBUTING.md), que es
            // efectivamente "la nueva oferta".
            case 'reactivate_date_request':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b || $b->status !== 'date_request_rejected' ) {
                    return $this->tt( 'Esta reserva no es una solicitud rechazada.', 'This booking is not a rejected request.' );
                }
                $wpdb->update(
                    "{$wpdb->prefix}amir_bookings",
                    [
                        'status'         => 'date_requested',
                        'internal_notes' => trim( $b->internal_notes ?? '' ) . "\n[" . current_time('d/m/Y H:i') . "] Solicitud reactivada por " . wp_get_current_user()->display_name . ".",
                    ],
                    [ 'id' => $booking_id ], [ '%s', '%s' ], [ '%d' ]
                );
                return '↩ ' . $this->tt( 'Solicitud reactivada — ya podés aprobarla (con un precio nuevo si corresponde) o rechazarla de nuevo.', 'Request reactivated — you can now approve it (with a new price if needed) or reject it again.' );

            case 'approve_cancellation':
                $note = sanitize_textarea_field(wp_unslash($_POST['cancel_note'] ?? ''));
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
                return $this->tt( 'Cancelación aprobada. Procesa el reembolso en el dashboard de Stripe si corresponde.', 'Cancellation approved. Process the refund in the Stripe dashboard if applicable.' );

            case 'reject_cancellation':
                $wpdb->update("{$wpdb->prefix}amir_bookings", ['status'=>'confirmed'], ['id'=>$booking_id]);
                return $this->tt( 'Solicitud de cancelación rechazada. La reserva continúa confirmada.', 'Cancellation request rejected. The booking remains confirmed.' );

            case 'cancel_operator':
                $reason = in_array($_POST['cancel_reason']??'', ['weather','min_pax'], true)
                    ? sanitize_key($_POST['cancel_reason'])
                    : 'weather';
                $manager = new \AmirBooking\Core\BookingManager();
                $result  = $manager->cancel($booking_id, $reason);
                return $result->success ? $this->tt( 'Tour cancelado. ', 'Tour cancelled. ' ) . $result->message . ' ' . $this->tt( 'Email enviado al cliente.', 'Email sent to the customer.' ) : $result->error;

            case 'reschedule':
                $new_date = sanitize_text_field($_POST['new_date'] ?? '');
                $new_sch  = (int)($_POST['new_schedule_id'] ?? 0);
                if ($new_date && $new_sch) {
                    $manager = new \AmirBooking\Core\BookingManager();
                    $result  = $manager->reschedule($booking_id, $new_date, $new_sch);
                    return $result->success ? sprintf( $this->tt( 'Reserva reprogramada a %s.', 'Booking rescheduled to %s.' ), $new_date ) : $result->error;
                }
                break;

            case 'reschedule_room':
                // Auditoría de ediciones (2026-08-16): includes/rooms/ no
                // existe en los paquetes Lite/Pro (solo Pro Max) — sin este
                // guard, un POST a mano con amir_action=reschedule_room
                // (ej. un Tour Manager reenviando un formulario viejo, o
                // simple curiosidad en las devtools) tiraba un fatal error
                // sin capturar en vez de un mensaje de error normal.
                if ( ! class_exists( \TourFlow\Rooms\RoomBookingManager::class ) ) {
                    return $this->tt( 'Esta instalación no incluye reservas de habitaciones.', 'This installation does not include room bookings.' );
                }
                $new_check_in  = sanitize_text_field($_POST['new_check_in'] ?? '');
                $new_check_out = sanitize_text_field($_POST['new_check_out'] ?? '');
                if ($new_check_in && $new_check_out) {
                    $result = (new \TourFlow\Rooms\RoomBookingManager())->reschedule($booking_id, $new_check_in, $new_check_out);
                    return $result->success ? sprintf( $this->tt( 'Habitación reprogramada a %s → %s.', 'Room rescheduled to %s → %s.' ), $new_check_in, $new_check_out ) : $result->error;
                }
                break;

            // "Derecho al olvido" (GDPR, § 15.3 CONTRIBUTING.md, pieza 4 de
            // 4) — manual desde el admin, decisión del cliente 2026-08-04
            // (no autoservicio público: menos superficie de riesgo, un admin
            // humano siempre está en el medio). Borra/anonimiza los datos
            // PERSONALES del cliente pero conserva el registro financiero
            // (total, fechas, referencia, estado) para contabilidad — mismo
            // criterio que "no borrar reservas, solo cancelarlas". No toca
            // el PDF/voucher ya generado (queda con el nombre viejo) ni
            // borra el archivo — limitación conocida, documentada acá para
            // no perderla de vista.
            case 'anonymize':
                $b = (new \AmirBooking\Core\BookingManager())->get_booking($booking_id);
                if ( ! $b ) {
                    return $this->tt( 'Reserva no encontrada.', 'Booking not found.' );
                }
                if ( ! empty( $b->anonymized_at ) ) {
                    return sprintf( $this->tt( 'Los datos de esta reserva ya fueron anonimizados el %s.', "This booking's data was already anonymized on %s." ), date( 'd/m/Y H:i', strtotime( $b->anonymized_at ) ) );
                }
                $wpdb->update(
                    "{$wpdb->prefix}amir_bookings",
                    [
                        'customer_name'    => $this->tt( 'Cliente eliminado', 'Deleted customer' ),
                        'customer_email'   => 'deleted-' . $booking_id . '@anonymized.local',
                        'customer_phone'   => '',
                        'special_requests' => '',
                        'internal_notes'   => trim( $b->internal_notes ?? '' )
                            . "\n[" . current_time('d/m/Y H:i') . "] Datos personales anonimizados por " . wp_get_current_user()->display_name . " (solicitud GDPR).",
                        'anonymized_at'    => current_time( 'mysql' ),
                    ],
                    [ 'id' => $booking_id ]
                );
                return '✅ ' . $this->tt( 'Datos personales anonimizados. El registro financiero (total, fechas, referencia) se conserva para contabilidad.', 'Personal data anonymized. The financial record (total, dates, reference) is kept for accounting.' );
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

        fputcsv($out, [
            $this->tt('Referencia','Reference'), $this->tt('Tour','Tour'), $this->tt('Fecha','Date'),
            $this->tt('Horario','Schedule'), $this->tt('Estado','Status'), $this->tt('Cliente','Customer'),
            'Email', $this->tt('Teléfono','Phone'), $this->tt('Adultos','Adults'), $this->tt('Niños','Children'),
            $this->tt('Bebés','Babies'), $this->tt('Total','Total') . ' ' . \AmirBooking\Core\Currency::code(),
            $this->tt('Origen','Source'), 'Partner', $this->tt('Reservado en','Booked on'),
        ]);

        foreach ($bookings as $b) {
            $item_label = $b->item_type === 'room' ? ( '🛏 ' . $b->room_name_es ) : $b->tour_name_es;
            fputcsv($out, [
                $b->booking_ref, $item_label, $b->tour_date,
                ($b->item_type === 'room' ? $b->check_out_date : ($b->time_start??'')), $b->status,
                $this->csv_safe($b->customer_name), $this->csv_safe($b->customer_email), $this->csv_safe($b->customer_phone),
                $b->adults, $b->children, $b->babies, $b->total_mxn,
                $b->booking_source, $b->partner_name??'', $b->created_at,
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * Neutraliza inyección de fórmulas CSV (=, +, -, @, tab, CR al inicio):
     * un customer_name como =HYPERLINK(...) ejecuta al abrir el CSV en
     * Excel/LibreOffice — el campo solo pasa por sanitize_text_field(), que
     * no filtra estos caracteres (auditoría de seguridad 2026-08-04, ver
     * CONTRIBUTING.md § 16.28).
     */
    private function csv_safe( $value ): string {
        $value = (string) $value;
        if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
            return "'" . $value;
        }
        return $value;
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
            'cart_group_id' => sanitize_text_field( $_GET['cart_group_id'] ?? '' ),
            'paged'      => max(1,(int)(          $_GET['paged']      ?? 1 )),
        ];
    }

    private function query_bookings( array $f, int $limit = 25 ): array {
        global $wpdb;
        [ $where, $params ] = $this->build_where($f);
        $offset = ($f['paged']-1) * $limit;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, t.name_es as tour_name_es, s.time_start,
                    p.name as partner_name, r.name_es as room_name_es,
                    ( SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings b2
                      WHERE b2.cart_group_id = b.cart_group_id ) as cart_group_count
             FROM {$wpdb->prefix}amir_bookings b
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             LEFT JOIN {$wpdb->prefix}amir_partners p ON p.id = b.partner_id
             LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
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
        global $wpdb;
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
        if ( $f['cart_group_id'] ) { $where .= " AND b.cart_group_id=%s"; $params[] = $f['cart_group_id']; }

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
            'date_requested'          => '#e8f4ff:#1a6fa8',
            'date_request_rejected'   => '#fef2f2:#c53030',
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
            'date_request' => '#e8f4ff:#1a6fa8',
        ];
        $labels = [ 'date_request' => $this->tt( 'fecha solicitada', 'date requested' ) ];
        [$bg,$color] = explode(':', $colors[$source] ?? '#f3f4f6:#5a7068');
        return "<span style='background:{$bg};color:{$color};font-size:10px;font-weight:700;padding:2px 6px;border-radius:8px;text-transform:uppercase;'>"
            . esc_html($labels[$source] ?? $source) . "</span>";
    }

    private function status_labels(): array {
        return [
            'pending'                => '⏳ ' . $this->tt( 'Pendiente', 'Pending' ),
            'date_requested'         => '📅 ' . $this->tt( 'Fecha solicitada', 'Date requested' ),
            'date_request_rejected'  => '✗ ' . $this->tt( 'Solicitud rechazada', 'Request rejected' ),
            'confirmed'              => '✅ ' . $this->tt( 'Confirmada', 'Confirmed' ),
            'cancellation_requested' => '🚫 ' . $this->tt( 'Solicita cancelación', 'Cancellation requested' ),
            'cancelled_client'       => '✗ ' . $this->tt( 'Cancelada (cliente)', 'Cancelled (customer)' ),
            'cancelled_weather'      => '⛈ ' . $this->tt( 'Cancelada (clima)', 'Cancelled (weather)' ),
            'cancelled_min_pax'      => '👥 ' . $this->tt( 'Cancelada (mín. pax)', 'Cancelled (min. pax)' ),
            'rescheduled'            => '🔄 ' . $this->tt( 'Reprogramada', 'Rescheduled' ),
            'completed'              => '🏁 ' . $this->tt( 'Completada', 'Completed' ),
        ];
    }

    private function cancellation_policy_text( object $b ): string {
        $today     = new \DateTime(current_time('Y-m-d'));
        $tour_date = new \DateTime($b->tour_date);
        $days      = (int)$today->diff($tour_date)->days;
        if ($days >= 7) return $this->tt( '7+ días de anticipación → Reembolso 100%.', '7+ days in advance → 100% refund.' );
        if ($days >= 3) return $this->tt( '3-6 días de anticipación → Reembolso 50% (', '3-6 days in advance → 50% refund (' ) . \AmirBooking\Core\Currency::format($b->total_mxn*0.5) . ").";
        return $this->tt( 'Menos de 3 días → Sin reembolso según política.', 'Less than 3 days → No refund per policy.' );
    }

    private function filter_query_string( array $f ): string {
        $parts = [];
        foreach ($f as $k=>$v) {
            if ($v && $k !== 'paged') $parts[] = "&{$k}=".urlencode($v);
        }
        return implode('', $parts);
    }

    /** Nullable a propósito — ver el mismo fix en DashboardPage::fmt_time(), bug real en producción (caliafarm.com, 2026-08-04). */
    private function fmt_time( ?string $t ): string {
        if ( ! $t ) {
            return '—';
        }
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

    // ── Formulario de reserva manual ─────────────────────────────────────────

    private function render_new_booking_form(): void {
        // Procesar envío del formulario
        $error   = '';
        $success = '';
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['amir_manual_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['amir_manual_nonce'], 'amir_create_manual_booking' ) ) {
                $error = $this->tt( 'Nonce inválido. Recarga la página e inténtalo de nuevo.', 'Invalid nonce. Reload the page and try again.' );
            } elseif ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
                $error = $this->tt( 'No tienes permisos para crear reservas.', 'You do not have permission to create bookings.' );
            } else {
                $manager = new \AmirBooking\Core\BookingManager();
                $result  = $manager->create_manual( $_POST );
                if ( $result->success ) {
                    wp_redirect( admin_url(
                        'admin.php?page=amir-bookings-list&action=view&id=' . $result->booking_id . '&created=1'
                    ) );
                    exit;
                } else {
                    $error = $result->error;
                }
            }
        }

        $tours = $this->get_tours_for_filter();
        global $wpdb;
        $partners = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}amir_partners WHERE active=1 ORDER BY name ASC" );
        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->admin_styles(); ?>
        <h1 style="display:flex;align-items:center;gap:12px;">
          <a href="<?php echo admin_url('admin.php?page=amir-bookings-list'); ?>"
             style="text-decoration:none;color:#5a7068;font-size:20px;">←</a>
          <?php echo esc_html( $this->tt( 'Nueva reserva manual', 'New manual booking' ) ); ?>
        </h1>

        <?php if ( $error ) : ?>
          <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <form method="post" style="max-width:700px;">
          <?php wp_nonce_field( 'amir_create_manual_booking', 'amir_manual_nonce' ); ?>

          <!-- ── Tour y fecha ── -->
          <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 24px;margin-bottom:16px;">
            <h3 style="margin:0 0 14px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Tour y fecha', 'Tour and date' ) ); ?></h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">Tour *</label>
                <select name="tour_id" required onchange="amirLoadSchedules(this.value)"
                        style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value=""><?php echo esc_html( $this->tt( '— Selecciona un tour —', '— Select a tour —' ) ); ?></option>
                  <?php foreach ( $tours as $t ) : ?>
                    <option value="<?php echo $t->id; ?>"><?php echo esc_html( $t->name_es ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Horario', 'Schedule' ) ); ?></label>
                <select name="schedule_id" id="amir-schedule-select"
                        style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="0"><?php echo esc_html( $this->tt( '— Sin horario específico —', '— No specific schedule —' ) ); ?></option>
                </select>
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Fecha *', 'Date *' ) ); ?></label>
                <input type="date" name="date" required min="<?php echo date('Y-m-d'); ?>"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Idioma', 'Language' ) ); ?></label>
                <select name="lang" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="es"><?php echo esc_html( $this->tt( 'Español', 'Spanish' ) ); ?></option>
                  <option value="en"><?php echo esc_html( $this->tt( 'English', 'English' ) ); ?></option>
                </select>
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Plataforma de origen', 'Source platform' ) ); ?></label>
                <select name="booking_source" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="manual"><?php echo esc_html( $this->tt( 'Manual (cargada a mano)', 'Manual (entered by hand)' ) ); ?></option>
                  <option value="tripadvisor">TripAdvisor</option>
                  <option value="getyourguide">GetYourGuide</option>
                </select>
                <p style="font-size:11px;color:#888;margin:4px 0 0;"><?php echo esc_html( $this->tt( 'Solo para contabilizar de dónde vino la reserva — no gestiona pagos/mensajes con esa plataforma.', 'Only to track where the booking came from — it does not manage payments/messages with that platform.' ) ); ?></p>
              </div>
            </div>
          </div>

          <!-- ── Pasajeros ── -->
          <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 24px;margin-bottom:16px;">
            <h3 style="margin:0 0 14px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Pasajeros', 'Passengers' ) ); ?></h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Adultos *', 'Adults *' ) ); ?></label>
                <input type="number" name="adults" value="1" min="1" max="50"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Niños', 'Children' ) ); ?></label>
                <input type="number" name="children" value="0" min="0" max="50"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Bebés', 'Babies' ) ); ?></label>
                <input type="number" name="babies" value="0" min="0" max="20"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
            </div>
          </div>

          <!-- ── Datos del cliente ── -->
          <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 24px;margin-bottom:16px;">
            <h3 style="margin:0 0 14px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Datos del cliente', 'Customer details' ) ); ?></h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Nombre completo *', 'Full name *' ) ); ?></label>
                <input type="text" name="customer_name" required placeholder="Ana García"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">Email *</label>
                <input type="email" name="customer_email" required placeholder="ana@ejemplo.com"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">WhatsApp / <?php echo esc_html( $this->tt( 'Teléfono', 'Phone' ) ); ?></label>
                <input type="text" name="customer_phone" placeholder="+52 983 123 4567"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Partner (opcional)', 'Partner (optional)' ) ); ?></label>
                <select name="partner_id" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value=""><?php echo esc_html( $this->tt( '— Sin partner —', '— No partner —' ) ); ?></option>
                  <?php foreach ( $partners as $p ) : ?>
                    <option value="<?php echo $p->id; ?>"><?php echo esc_html( $p->name ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- ── Precio y pago ── -->
          <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 24px;margin-bottom:16px;">
            <h3 style="margin:0 0 14px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Precio y pago', 'Price and payment' ) ); ?></h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div>
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">
                  <?php echo esc_html( $this->tt( 'Total', 'Total' ) ); ?> <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?>
                  <span style="font-weight:400;color:#5a7068;">(<?php echo esc_html( $this->tt( '0 = calcular automático', '0 = calculate automatically' ) ); ?>)</span>
                </label>
                <input type="number" name="total_mxn" value="0" min="0" step="0.01"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div id="amir-payment-method-wrap">
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;"><?php echo esc_html( $this->tt( 'Método de pago', 'Payment method' ) ); ?></label>
                <select name="payment_method_note" id="amir-payment-method" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="Efectivo"><?php echo esc_html( $this->tt( 'Efectivo', 'Cash' ) ); ?></option>
                  <option value="Transferencia"><?php echo esc_html( $this->tt( 'Transferencia', 'Bank transfer' ) ); ?></option>
                  <option value="Tarjeta (presencial)"><?php echo esc_html( $this->tt( 'Tarjeta (presencial)', 'Card (in person)' ) ); ?></option>
                  <option value="WhatsApp / Coordinado"><?php echo esc_html( $this->tt( 'WhatsApp / Coordinado', 'WhatsApp / Arranged' ) ); ?></option>
                  <option value="Cortesía"><?php echo esc_html( $this->tt( 'Cortesía', 'Complimentary' ) ); ?></option>
                  <option value="Agencia"><?php echo esc_html( $this->tt( 'Agencia', 'Agency' ) ); ?></option>
                </select>
              </div>
            </div>

            <div style="margin-top:14px;padding:12px 16px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="awaiting_payment" id="amir-awaiting-payment" value="1"
                       style="width:16px;height:16px;accent-color:#BA7517;" />
                <span>
                  <strong><?php echo esc_html( $this->tt( 'El cliente todavía no pagó — enviarle un link de pago', 'The customer has not paid yet — send them a payment link' ) ); ?></strong>
                  <span style="display:block;font-size:12px;color:#5a7068;margin-top:1px;">
                    <?php echo esc_html( $this->tt( 'La reserva queda reservada pero sin confirmar. Se manda un email con el link para que el cliente pague online (Stripe/Mercado Pago) — mismo mecanismo que la lista de interés. El "Método de pago" de arriba no aplica en este caso.', 'The booking stays reserved but unconfirmed. An email is sent with a link for the customer to pay online (Stripe/Mercado Pago) — same mechanism as the waitlist. The "Payment method" above does not apply in this case.' ) ); ?>
                  </span>
                </span>
              </label>
            </div>
          </div>

          <script>
          (function(){
            var toggle  = document.getElementById('amir-awaiting-payment');
            var methodSelect = document.getElementById('amir-payment-method');
            var sendEmailCheckbox = document.querySelector('input[name="send_email"]');
            if (!toggle) return;
            toggle.addEventListener('change', function(){
              methodSelect.disabled = this.checked;
              if (sendEmailCheckbox) {
                sendEmailCheckbox.disabled = this.checked;
                if (this.checked) sendEmailCheckbox.checked = false;
              }
            });
          })();
          </script>

          <!-- ── Notas y correo ── -->
          <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 24px;margin-bottom:16px;">
            <h3 style="margin:0 0 14px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Correo y notas', 'Email and notes' ) ); ?></h3>

            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">
              <?php echo esc_html( $this->tt( 'Nota personalizada en el correo', 'Custom note in the email' ) ); ?>
              <span style="font-weight:400;color:#5a7068;">(<?php echo esc_html( $this->tt( 'aparece en el email del cliente, opcional', "shows in the customer's email, optional" ) ); ?>)</span>
            </label>
            <textarea name="custom_email_note" rows="3" placeholder="<?php echo esc_attr( $this->tt( 'Ej: Su guía le espera con un cartel verde en el muelle principal. Traiga efectivo para propinas.', 'E.g.: Your guide will be waiting with a green sign at the main pier. Bring cash for tips.' ) ); ?>"
                      style="<?php echo $this->input_style(); ?> width:100%;resize:vertical;"></textarea>

            <div style="margin-top:14px;">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">
                <?php echo esc_html( $this->tt( 'Solicitudes especiales del cliente', "Customer's special requests" ) ); ?>
              </label>
              <textarea name="special_requests" rows="2"
                        placeholder="<?php echo esc_attr( $this->tt( 'Silla de ruedas, alergia alimentaria, cumpleaños, etc.', 'Wheelchair, food allergy, birthday, etc.' ) ); ?>"
                        style="<?php echo $this->input_style(); ?> width:100%;resize:vertical;"></textarea>
            </div>

            <div style="margin-top:14px;">
              <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">
                <?php echo esc_html( $this->tt( 'Notas internas', 'Internal notes' ) ); ?>
                <span style="font-weight:400;color:#5a7068;">(<?php echo esc_html( $this->tt( 'solo visibles en el panel, no se envían al cliente', 'only visible in the admin, not sent to the customer' ) ); ?>)</span>
              </label>
              <textarea name="internal_notes" rows="2"
                        placeholder="<?php echo esc_attr( $this->tt( 'Reserva gestionada por teléfono el 10/04. Pagó en efectivo.', 'Booking handled by phone on 04/10. Paid in cash.' ) ); ?>"
                        style="<?php echo $this->input_style(); ?> width:100%;resize:vertical;"></textarea>
            </div>

            <div style="margin-top:18px;padding:12px 16px;background:#f8fdfb;border-radius:8px;border:1px solid #e1f5ee;">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="send_email" value="1" checked
                       style="width:16px;height:16px;accent-color:#1D9E75;" />
                <span>
                  <strong><?php echo esc_html( $this->tt( 'Enviar correo de confirmación al cliente', 'Send confirmation email to the customer' ) ); ?></strong>
                  <span style="display:block;font-size:12px;color:#5a7068;margin-top:1px;">
                    <?php echo esc_html( $this->tt( 'Incluye referencia, detalles del tour, PDF del voucher y la nota personalizada.', 'Includes reference, tour details, the PDF voucher, and the custom note.' ) ); ?>
                  </span>
                </span>
              </label>
            </div>
          </div>

          <div style="display:flex;gap:12px;align-items:center;">
            <button type="submit" class="button button-primary" style="font-size:14px;height:38px;padding:0 20px;">
              <?php echo esc_html( $this->tt( 'Crear reserva confirmada', 'Create confirmed booking' ) ); ?>
            </button>
            <a href="<?php echo admin_url('admin.php?page=amir-bookings-list'); ?>" class="button">
              <?php echo esc_html( $this->tt( 'Cancelar', 'Cancel' ) ); ?>
            </a>
          </div>
        </form>
        </div>

        <script>
        function amirLoadSchedules(tourId) {
            var sel = document.getElementById('amir-schedule-select');
            sel.innerHTML = '<option value="0"><?php echo esc_js( $this->tt( '— Sin horario específico —', '— No specific schedule —' ) ); ?></option>';
            if (!tourId) return;
            fetch(amirAdminData.apiUrl + 'tours/' + tourId + '/schedules', {
                headers: { 'X-WP-Nonce': amirAdminData.nonce }
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                (data.schedules || data || []).forEach(function(s){
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = (s.label_es || s.time_start || '<?php echo esc_js( $this->tt( 'Horario', 'Schedule' ) ); ?> ' + s.id);
                    sel.appendChild(opt);
                });
            })
            .catch(function(){});
        }
        </script>
        <?php
    }

    // ── Descarga de voucher PDF desde el admin ────────────────────────────

    private function stream_pdf( int $booking_id ): void {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d",
            $booking_id
        ) );

        if ( ! $booking ) {
            wp_die( esc_html( $this->tt( 'Reserva no encontrada.', 'Booking not found.' ) ) );
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
