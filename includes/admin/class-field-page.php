<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * "Modo campo" — pantalla fullscreen pensada para el celular, para quien
 * gestiona reservas fuera del escritorio (en el muelle, en la calle, etc.).
 * No es un retrofit responsive de Reservas (esa tabla es demasiado densa
 * para un teléfono) — es una vista nueva y más chica, con las acciones que
 * más se usan en el día a día: ver reservas de hoy/próximas, buscar una
 * puntual, ver los datos del cliente y contactarlo (llamar/WhatsApp),
 * confirmar/cancelar/reprogramar, y cargar una reserva nueva rápido.
 * Reusa BookingManager tal cual (misma lógica que Reservas de escritorio) —
 * solo cambia la presentación.
 */
class FieldPage {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
        }

        $message = $this->handle_actions();
        $view    = sanitize_key( $_GET['view'] ?? 'list' );
        $id      = absint( $_GET['id'] ?? 0 );
        ?>
        <style>
        .amir-field-wrap { max-width: 480px; margin: 0 auto; padding: 16px 14px 100px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .amir-field-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
        .amir-field-header a { color: #5a7068; text-decoration: none; font-size: 14px; }
        .amir-field-title { font-size: 19px; font-weight: 700; color: #1a2e24; flex: 1; }
        .amir-field-search { display: flex; gap: 8px; margin-bottom: 12px; }
        .amir-field-search input { flex: 1; border: 1px solid #c3d9d0; border-radius: 10px; padding: 12px 14px; font-size: 16px; }
        .amir-field-search button { border: none; background: #1D9E75; color: #fff; border-radius: 10px; padding: 0 18px; font-size: 14px; font-weight: 600; }
        .amir-field-tabs { display: flex; gap: 8px; margin-bottom: 16px; overflow-x: auto; }
        .amir-field-tabs a { flex-shrink: 0; padding: 8px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; text-decoration: none; background: #f0faf6; color: #1D9E75; }
        .amir-field-tabs a.is-active { background: #1D9E75; color: #fff; }
        .amir-field-card { display: block; background: #fff; border: 1px solid #e1f5ee; border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; text-decoration: none; color: inherit; }
        .amir-field-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
        .amir-field-name { font-size: 15px; font-weight: 700; color: #1a2e24; }
        .amir-field-tour { font-size: 13px; color: #5a7068; }
        .amir-field-badge { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 12px; white-space: nowrap; }
        .amir-field-date { font-size: 13px; color: #1D9E75; font-weight: 700; margin-top: 4px; }
        .amir-field-contact-row { display: flex; gap: 8px; margin-top: 10px; }
        .amir-field-contact-row a { flex: 1; text-align: center; padding: 10px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; min-height: 44px; display: flex; align-items: center; justify-content: center; }
        .amir-field-call { background: #eef4ff; color: #1a6fa8; }
        .amir-field-wa { background: #e7f9ee; color: #1D9E75; }
        .amir-field-empty { text-align: center; color: #5a7068; padding: 40px 20px; font-size: 14px; }
        .amir-field-fab { position: fixed; bottom: 24px; right: 24px; width: 58px; height: 58px; border-radius: 50%; background: #1D9E75; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px; text-decoration: none; box-shadow: 0 4px 14px rgba(0,0,0,.25); z-index: 50; }
        .amir-field-detail-row { padding: 10px 0; border-bottom: 1px solid #f5f5f5; font-size: 14px; }
        .amir-field-detail-row span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; color: #5a7068; margin-bottom: 2px; }
        .amir-field-section { background: #fff; border: 1px solid #e1f5ee; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
        .amir-field-section h3 { margin: 0 0 10px; font-size: 13px; color: #1D9E75; text-transform: uppercase; letter-spacing: .3px; }
        .amir-field-input { width: 100%; border: 1px solid #c3d9d0; border-radius: 8px; padding: 12px 14px; font-size: 16px; box-sizing: border-box; margin-bottom: 10px; }
        .amir-field-label { display: block; font-size: 12px; font-weight: 700; color: #1a2e24; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .3px; }
        .amir-field-btn { display: block; width: 100%; border: none; border-radius: 10px; padding: 14px; font-size: 15px; font-weight: 700; text-align: center; min-height: 48px; }
        .amir-field-btn-primary { background: #1D9E75; color: #fff; }
        .amir-field-btn-danger { background: #fef2f2; color: #e24b4a; margin-top: 8px; }
        .amir-field-msg { background: #f0faf6; border: 1px solid #cdeee0; color: #146c50; border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; }
        .amir-field-err { background: #fef2f2; border: 1px solid #fecaca; color: #a3282c; border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; }
        </style>

        <div class="amir-field-wrap">
          <div class="amir-field-header">
            <?php if ( $view === 'list' ) : ?>
              <span class="amir-field-title">📱 Modo campo</span>
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-booking' ) ); ?>">Panel completo →</a>
            <?php else : ?>
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-field' ) ); ?>">← Volver</a>
              <span class="amir-field-title"><?php echo $view === 'new' ? 'Nueva reserva' : 'Reserva'; ?></span>
            <?php endif; ?>
          </div>

          <?php if ( $message ) : ?>
            <div class="amir-<?php echo $message['ok'] ? 'field-msg' : 'field-err'; ?>"><?php echo esc_html( $message['text'] ); ?></div>
          <?php endif; ?>

          <?php
          if ( $view === 'new' ) {
              $this->render_new_form();
          } elseif ( $view === 'detail' && $id ) {
              $this->render_detail( $id );
          } else {
              $this->render_list();
          }
          ?>
        </div>
        <?php
    }

    // ── Lista ────────────────────────────────────────────────────────────

    private function render_list(): void {
        $q      = sanitize_text_field( $_GET['q'] ?? '' );
        $filter = sanitize_key( $_GET['filter'] ?? 'upcoming' );
        ?>
        <form method="get" class="amir-field-search">
          <input type="hidden" name="page" value="amir-field" />
          <input type="text" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Buscar por nombre, teléfono, email o referencia..." />
          <button type="submit">Buscar</button>
        </form>

        <?php if ( ! $q ) : ?>
        <div class="amir-field-tabs">
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-field&filter=today') ); ?>" class="<?php echo $filter==='today' ? 'is-active' : ''; ?>">Hoy</a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-field&filter=upcoming') ); ?>" class="<?php echo $filter==='upcoming' ? 'is-active' : ''; ?>">Próximas</a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-field&filter=all') ); ?>" class="<?php echo $filter==='all' ? 'is-active' : ''; ?>">Todas</a>
        </div>
        <?php endif; ?>

        <?php
        $bookings = $this->query_bookings( $q, $filter );
        if ( empty( $bookings ) ) : ?>
          <div class="amir-field-empty">No hay reservas para mostrar acá.</div>
        <?php else : foreach ( $bookings as $b ) :
            $pax = (int) $b->adults + (int) $b->children + (int) $b->babies;
        ?>
          <a class="amir-field-card" href="<?php echo esc_url( admin_url( 'admin.php?page=amir-field&view=detail&id=' . $b->id ) ); ?>">
            <div class="amir-field-card-top">
              <div>
                <div class="amir-field-name"><?php echo esc_html( $b->customer_name ); ?></div>
                <div class="amir-field-tour"><?php echo esc_html( $b->tour_name ); ?> · <?php echo $pax; ?> pax</div>
              </div>
              <span class="amir-field-badge" style="<?php echo $this->status_style( $b->status ); ?>"><?php echo esc_html( $this->status_label( $b->status ) ); ?></span>
            </div>
            <div class="amir-field-date">📅 <?php echo esc_html( mysql2date( 'd/m/Y', $b->tour_date ) ); ?><?php echo $b->time_start ? ' · ' . esc_html( substr( $b->time_start, 0, 5 ) ) : ''; ?></div>
          </a>
        <?php endforeach; endif; ?>

        <a class="amir-field-fab" href="<?php echo esc_url( admin_url( 'admin.php?page=amir-field&view=new' ) ); ?>">+</a>
        <?php
    }

    private function query_bookings( string $q, string $filter ): array {
        global $wpdb;

        $where  = "b.status IN ('pending','confirmed','awaiting_payment','cancellation_requested')";
        $params = [];

        if ( $q !== '' ) {
            // Con búsqueda activa, mirar en todo (no solo próximas) — quien
            // busca una reserva puntual puede necesitar una ya pasada.
            $where   = '1=1';
            $like    = '%' . $wpdb->esc_like( $q ) . '%';
            $where  .= ' AND (b.customer_name LIKE %s OR b.customer_phone LIKE %s OR b.customer_email LIKE %s OR b.booking_ref LIKE %s)';
            $params  = [ $like, $like, $like, $like ];
        } elseif ( $filter === 'today' ) {
            $where .= ' AND b.tour_date = %s';
            $params[] = current_time( 'Y-m-d' );
        } elseif ( $filter === 'upcoming' ) {
            $where .= ' AND b.tour_date >= %s';
            $params[] = current_time( 'Y-m-d' );
        }
        // 'all' con $q vacío: sin filtro extra de fecha, solo estados activos arriba.

        $sql = "SELECT b.id, b.booking_ref, b.customer_name, b.customer_phone, b.status,
                       b.tour_date, b.adults, b.children, b.babies,
                       t.name_es AS tour_name, s.time_start
                FROM {$wpdb->prefix}amir_bookings b
                JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
                WHERE {$where}
                ORDER BY b.tour_date ASC, s.time_start ASC
                LIMIT 50";

        return $params
            ? ( $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?? [] )
            : ( $wpdb->get_results( $sql ) ?? [] );
    }

    // ── Detalle ──────────────────────────────────────────────────────────

    private function render_detail( int $id ): void {
        global $wpdb;
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, t.name_es AS tour_name, t.id AS tour_id, s.time_start, s.label_es AS schedule_label
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             WHERE b.id = %d",
            $id
        ) );

        if ( ! $b ) {
            echo '<div class="amir-field-err">Reserva no encontrada.</div>';
            return;
        }

        $pax   = (int) $b->adults + (int) $b->children + (int) $b->babies;
        $phone = preg_replace( '/[^0-9]/', '', $b->customer_phone ?? '' );
        ?>
        <div class="amir-field-section">
          <h3>Cliente</h3>
          <div class="amir-field-detail-row"><span>Nombre</span><?php echo esc_html( $b->customer_name ); ?></div>
          <div class="amir-field-detail-row"><span>Email</span><?php echo esc_html( $b->customer_email ); ?></div>
          <?php if ( $phone ) : ?>
          <div class="amir-field-contact-row">
            <a class="amir-field-call" href="tel:<?php echo esc_attr( $phone ); ?>">📞 Llamar</a>
            <a class="amir-field-wa" href="https://wa.me/<?php echo esc_attr( $phone ); ?>" target="_blank">💬 WhatsApp</a>
          </div>
          <?php endif; ?>
        </div>

        <div class="amir-field-section">
          <h3>Reserva <?php echo esc_html( $b->booking_ref ); ?></h3>
          <div class="amir-field-detail-row"><span>Tour</span><?php echo esc_html( $b->tour_name ); ?></div>
          <div class="amir-field-detail-row"><span>Fecha y horario</span><?php echo esc_html( mysql2date( 'd/m/Y', $b->tour_date ) ); ?><?php echo $b->time_start ? ' · ' . esc_html( substr( $b->time_start, 0, 5 ) ) : ''; ?></div>
          <div class="amir-field-detail-row"><span>Personas</span><?php echo $pax; ?> (<?php echo (int) $b->adults; ?>A <?php echo (int) $b->children; ?>N <?php echo (int) $b->babies; ?>B)</div>
          <div class="amir-field-detail-row"><span>Estado</span>
            <span class="amir-field-badge" style="<?php echo $this->status_style( $b->status ); ?>"><?php echo esc_html( $this->status_label( $b->status ) ); ?></span>
          </div>
        </div>

        <?php if ( in_array( $b->status, [ 'pending', 'awaiting_payment' ], true ) ) : ?>
        <form method="post">
          <?php wp_nonce_field( 'amir_field_action' ); ?>
          <input type="hidden" name="amir_action" value="confirm" />
          <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
          <button type="submit" class="amir-field-btn amir-field-btn-primary">✓ Confirmar reserva</button>
        </form>
        <?php endif; ?>

        <?php if ( in_array( $b->status, [ 'pending', 'confirmed' ], true ) ) : ?>
        <div class="amir-field-section">
          <h3>Reprogramar</h3>
          <form method="post">
            <?php wp_nonce_field( 'amir_field_action' ); ?>
            <input type="hidden" name="amir_action" value="reschedule" />
            <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
            <label class="amir-field-label">Nueva fecha</label>
            <input type="date" name="new_date" required min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" class="amir-field-input" />
            <label class="amir-field-label">Horario</label>
            <select name="new_schedule_id" id="amir-field-schedule" class="amir-field-input" onfocus="amirFieldLoadSchedules(<?php echo (int) $b->tour_id; ?>)">
              <option value="<?php echo (int) $b->schedule_id; ?>">— Mantener el actual —</option>
            </select>
            <button type="submit" class="amir-field-btn amir-field-btn-primary">🔄 Reprogramar</button>
          </form>
        </div>

        <form method="post" onsubmit="return confirm('¿Cancelar esta reserva?');">
          <?php wp_nonce_field( 'amir_field_action' ); ?>
          <input type="hidden" name="amir_action" value="cancel" />
          <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
          <button type="submit" class="amir-field-btn amir-field-btn-danger">✕ Cancelar reserva</button>
        </form>
        <?php endif; ?>

        <script>
        function amirFieldLoadSchedules(tourId) {
          var sel = document.getElementById('amir-field-schedule');
          if (!tourId || !window.amirAdminData) return;
          fetch(amirAdminData.apiUrl + 'tours/' + tourId + '/schedules', { headers: { 'X-WP-Nonce': amirAdminData.nonce } })
            .then(function(r){ return r.json(); })
            .then(function(list){
              var current = sel.value;
              sel.innerHTML = '<option value="0">— Sin horario específico —</option>';
              (list || []).forEach(function(s){
                var opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = (s.label_es || '') + ' ' + (s.time_start || '').slice(0,5);
                if (String(s.id) === String(current)) opt.selected = true;
                sel.appendChild(opt);
              });
            });
        }
        </script>
        <?php
    }

    // ── Nueva reserva ────────────────────────────────────────────────────

    private function render_new_form(): void {
        global $wpdb;
        $tours = $wpdb->get_results( "SELECT id, name_es FROM {$wpdb->prefix}amir_tours WHERE status = 'active' ORDER BY sort_order" );
        ?>
        <form method="post">
          <?php wp_nonce_field( 'amir_field_action' ); ?>
          <input type="hidden" name="amir_action" value="create" />

          <div class="amir-field-section">
            <label class="amir-field-label">Tour *</label>
            <select name="tour_id" required class="amir-field-input" onchange="amirFieldLoadSchedules(this.value)">
              <option value="">— Selecciona un tour —</option>
              <?php foreach ( $tours as $t ) : ?>
                <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->name_es ); ?></option>
              <?php endforeach; ?>
            </select>
            <label class="amir-field-label">Horario</label>
            <select name="schedule_id" id="amir-field-schedule" class="amir-field-input">
              <option value="0">— Sin horario específico —</option>
            </select>
            <label class="amir-field-label">Fecha *</label>
            <input type="date" name="date" required min="<?php echo esc_attr( date('Y-m-d') ); ?>" class="amir-field-input" />
          </div>

          <div class="amir-field-section">
            <label class="amir-field-label">Adultos *</label>
            <input type="number" name="adults" value="1" min="1" max="50" class="amir-field-input" />
            <label class="amir-field-label">Niños</label>
            <input type="number" name="children" value="0" min="0" max="50" class="amir-field-input" />
            <label class="amir-field-label">Bebés</label>
            <input type="number" name="babies" value="0" min="0" max="20" class="amir-field-input" />
          </div>

          <div class="amir-field-section">
            <label class="amir-field-label">Nombre del cliente *</label>
            <input type="text" name="customer_name" required class="amir-field-input" />
            <label class="amir-field-label">Email *</label>
            <input type="email" name="customer_email" required class="amir-field-input" />
            <label class="amir-field-label">WhatsApp / Teléfono</label>
            <input type="text" name="customer_phone" placeholder="+52 983 123 4567" class="amir-field-input" />
          </div>

          <div class="amir-field-section">
            <label class="amir-field-label">Total (0 = calcular automático)</label>
            <input type="number" name="total_mxn" value="0" min="0" step="0.01" class="amir-field-input" />
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;">
              <input type="checkbox" name="awaiting_payment" value="1" style="width:20px;height:20px;" />
              El cliente todavía no pagó — enviarle link de pago
            </label>
          </div>

          <button type="submit" class="amir-field-btn amir-field-btn-primary">Crear reserva</button>
        </form>

        <script>
        function amirFieldLoadSchedules(tourId) {
          var sel = document.getElementById('amir-field-schedule');
          sel.innerHTML = '<option value="0">— Sin horario específico —</option>';
          if (!tourId || !window.amirAdminData) return;
          fetch(amirAdminData.apiUrl + 'tours/' + tourId + '/schedules', { headers: { 'X-WP-Nonce': amirAdminData.nonce } })
            .then(function(r){ return r.json(); })
            .then(function(list){
              (list || []).forEach(function(s){
                var opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = (s.label_es || '') + ' ' + (s.time_start || '').slice(0,5);
                sel.appendChild(opt);
              });
            });
        }
        </script>
        <?php
    }

    // ── Acciones ─────────────────────────────────────────────────────────

    private function handle_actions(): ?array {
        if ( empty( $_POST['amir_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_field_action' ) ) {
            return null;
        }

        $action  = sanitize_key( $_POST['amir_action'] );
        $manager = new \AmirBooking\Core\BookingManager();

        if ( $action === 'create' ) {
            $result = $manager->create_manual( $_POST );
            if ( $result->success ) {
                wp_redirect( admin_url( 'admin.php?page=amir-field&view=detail&id=' . $result->booking_id ) );
                exit;
            }
            return [ 'ok' => false, 'text' => $result->error ];
        }

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            return [ 'ok' => false, 'text' => 'Reserva no válida.' ];
        }

        if ( $action === 'confirm' ) {
            $manager->confirm( $id, '' );
            return [ 'ok' => true, 'text' => 'Reserva confirmada. Email y voucher en camino.' ];
        }

        if ( $action === 'cancel' ) {
            $result = $manager->cancel( $id, 'client' );
            return [ 'ok' => $result->success, 'text' => $result->success ? 'Reserva cancelada.' : $result->error ];
        }

        if ( $action === 'reschedule' ) {
            $new_date = sanitize_text_field( $_POST['new_date'] ?? '' );
            $new_sch  = absint( $_POST['new_schedule_id'] ?? 0 );
            $result   = $manager->reschedule( $id, $new_date, $new_sch );
            return [ 'ok' => $result->success, 'text' => $result->success ? 'Reserva reprogramada.' : $result->error ];
        }

        return null;
    }

    // ── Helpers de presentación ──────────────────────────────────────────

    private function status_label( string $status ): string {
        $map = [
            'pending'                 => '⏳ Pendiente',
            'confirmed'               => '✓ Confirmada',
            'awaiting_payment'        => '💳 Espera pago',
            'cancellation_requested'  => '⚠ Solicita cancelación',
        ];
        return $map[ $status ] ?? $status;
    }

    private function status_style( string $status ): string {
        $map = [
            'pending'                => 'background:#fffbeb;color:#92400e;',
            'confirmed'              => 'background:#f0faf6;color:#146c50;',
            'awaiting_payment'       => 'background:#eef4ff;color:#1a6fa8;',
            'cancellation_requested' => 'background:#fef2f2;color:#a3282c;',
        ];
        return $map[ $status ] ?? '';
    }
}
