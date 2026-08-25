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

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    public function register(): void {
        add_action( 'wp_ajax_amir_field_scan_lookup', [ $this, 'ajax_scan_lookup' ] );
    }

    /**
     * Versión JSON de la rama 'scan_lookup' de handle_actions() (misma
     * lógica de autorización, ver más abajo) — antes cada escaneo inválido
     * hacía un POST de página completa: recarga dura + la cámara tenía que
     * re-negociar getUserMedia() de cero antes de poder reintentar. Con un
     * operador escaneando una fila de pasajeros, cada voucher vencido/
     * inválido costaba un viaje de ida y vuelta completo. Ahora la vista de
     * escaneo (render_scan()) usa esto por fetch() y solo navega si el
     * voucher es válido — en un rechazo, sigue escaneando sin recargar.
     * Auditoría de UX pre-empaquetado v5.7.14, CONTRIBUTING.md § 16.91.
     */
    public function ajax_scan_lookup(): void {
        if ( ! check_ajax_referer( 'wp_rest', 'nonce', false ) ) {
            wp_send_json_error( [ 'text' => $this->tt( 'Solicitud inválida — recargá la página.', 'Invalid request — reload the page.' ) ] );
        }
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_send_json_error( [ 'text' => $this->tt( 'Sin permisos.', 'No permission.' ) ] );
        }

        $ref     = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
        $cart    = sanitize_text_field( $_POST['cart'] ?? '' );
        $token   = sanitize_text_field( $_POST['token'] ?? '' );
        $manager = new \AmirBooking\Core\BookingManager();

        if ( $cart ) {
            global $wpdb;
            $primary = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE cart_group_id = %s ORDER BY id ASC LIMIT 1",
                $cart
            ) );
            if ( $primary && $manager->authorize_public_access( $primary, $token ) ) {
                wp_send_json_success( [ 'redirect' => admin_url( 'admin.php?page=amir-field&view=cart&cart_group_id=' . rawurlencode( $cart ) ) ] );
            }
            wp_send_json_error( [ 'text' => $this->tt( 'Voucher inválido — el código QR no corresponde a ningún carrito, o el link venció.', 'Invalid voucher — the QR code does not match any cart, or the link expired.' ) ] );
        }

        $booking = $ref ? $manager->get_booking_by_ref( $ref ) : null;
        if ( $booking && $manager->authorize_public_access( $booking, $token ) ) {
            wp_send_json_success( [ 'redirect' => admin_url( 'admin.php?page=amir-field&view=detail&id=' . $booking->id ) ] );
        }
        wp_send_json_error( [ 'text' => $this->tt( 'Voucher inválido — el código QR no corresponde a ninguna reserva, o el link venció.', 'Invalid voucher — the QR code does not match any booking, or the link expired.' ) ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
        }

        $message        = $this->handle_actions();
        $view           = sanitize_key( $_GET['view'] ?? 'list' );
        $id             = absint( $_GET['id'] ?? 0 );
        $cart_group_id  = sanitize_text_field( $_GET['cart_group_id'] ?? '' );
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
              <span class="amir-field-title">📱 <?php echo esc_html( $this->tt( 'Modo campo', 'Field mode' ) ); ?></span>
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-booking' ) ); ?>"><?php echo esc_html( $this->tt( 'Panel completo →', 'Full panel →' ) ); ?></a>
            <?php else : ?>
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-field' ) ); ?>">← <?php echo esc_html( $this->tt( 'Volver', 'Back' ) ); ?></a>
              <span class="amir-field-title"><?php echo esc_html( [ 'new' => $this->tt('Nueva reserva','New booking'), 'scan' => $this->tt('Escanear voucher','Scan voucher') ][ $view ] ?? $this->tt('Reserva','Booking') ); ?></span>
            <?php endif; ?>
          </div>

          <?php if ( $message ) : ?>
            <div class="amir-<?php echo $message['ok'] ? 'field-msg' : 'field-err'; ?>"><?php echo esc_html( $message['text'] ); ?></div>
          <?php endif; ?>

          <?php
          if ( $view === 'new' ) {
              $this->render_new_form();
          } elseif ( $view === 'scan' ) {
              $this->render_scan();
          } elseif ( $view === 'detail' && $id ) {
              $this->render_detail( $id );
          } elseif ( $view === 'cart' && $cart_group_id ) {
              $this->render_cart_detail( $cart_group_id );
          } else {
              $this->render_list();
          }
          ?>
        </div>
        <script>
        // Blindaje contra doble-tap en las ~10 acciones de Modo campo
        // (confirmar, check-in, reprogramar, marcar saldo cobrado, etc.) —
        // pensado justamente para usarse con conexión inestable "en el
        // muelle", sin esto un segundo tap mientras la primera respuesta
        // todavía viaja podía disparar la acción dos veces. Un solo
        // listener global (cubre las 10 formas sin tocarlas una por una).
        // e.defaultPrevented ya refleja si un onsubmit anterior (ej. el
        // confirm() de "Cancelar reserva") canceló el envío — en ese caso
        // no hay nada que bloquear.
        document.addEventListener('submit', function (e) {
          if ( e.defaultPrevented ) return;
          var form = e.target;
          if ( ! ( form instanceof HTMLFormElement ) ) return;
          var btn = form.querySelector('button[type="submit"]');
          if ( ! btn || btn.disabled ) return;
          btn.disabled = true;
          btn.dataset.amirOriginalHtml = btn.innerHTML;
          btn.innerHTML = '⏳ <?php echo esc_js( $this->tt( 'Enviando…', 'Sending…' ) ); ?>';
        });
        </script>
        <?php
    }

    // ── Lista ────────────────────────────────────────────────────────────

    private function render_list(): void {
        $q      = sanitize_text_field( $_GET['q'] ?? '' );
        $filter = sanitize_key( $_GET['filter'] ?? 'upcoming' );
        ?>
        <form method="get" class="amir-field-search">
          <input type="hidden" name="page" value="amir-field" />
          <input type="text" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( $this->tt( 'Buscar por nombre, teléfono, email o referencia...', 'Search by name, phone, email, or reference...' ) ); ?>" />
          <button type="submit"><?php echo esc_html( $this->tt( 'Buscar', 'Search' ) ); ?></button>
        </form>

        <?php if ( ! $q ) : ?>
        <div class="amir-field-tabs">
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-field&filter=today') ); ?>" class="<?php echo $filter==='today' ? 'is-active' : ''; ?>"><?php echo esc_html( $this->tt( 'Hoy', 'Today' ) ); ?></a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-field&filter=upcoming') ); ?>" class="<?php echo $filter==='upcoming' ? 'is-active' : ''; ?>"><?php echo esc_html( $this->tt( 'Próximas', 'Upcoming' ) ); ?></a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-field&filter=all') ); ?>" class="<?php echo $filter==='all' ? 'is-active' : ''; ?>"><?php echo esc_html( $this->tt( 'Todas', 'All' ) ); ?></a>
        </div>
        <?php endif; ?>

        <?php
        $bookings = $this->query_bookings( $q, $filter );
        if ( empty( $bookings ) ) : ?>
          <div class="amir-field-empty"><?php echo esc_html( $this->tt( 'No hay reservas para mostrar acá.', 'No bookings to show here.' ) ); ?></div>
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

        <a class="amir-field-fab" href="<?php echo esc_url( admin_url( 'admin.php?page=amir-field&view=scan' ) ); ?>" style="right:96px;background:#1a2e24;" title="<?php echo esc_attr( $this->tt( 'Escanear voucher', 'Scan voucher' ) ); ?>">📷</a>
        <a class="amir-field-fab" href="<?php echo esc_url( admin_url( 'admin.php?page=amir-field&view=new' ) ); ?>">+</a>
        <?php
    }

    // ── Escanear voucher (check-in) ──────────────────────────────────────

    /**
     * El voucher ya trae un QR con verify_url() (ref+token) — se reusa tal
     * cual, no hace falta generar uno nuevo. BarcodeDetector es nativo del
     * navegador (Chrome/Edge/Safari ya lo soportan), así que no hace falta
     * bundlear ninguna librería de terceros — si no está disponible, se
     * avisa y se cae al buscador manual de la lista.
     */
    private function render_scan(): void {
        ?>
        <div id="amir-scan-unsupported" class="amir-field-err" style="display:none;">
          <?php echo esc_html( $this->tt( 'Tu navegador no soporta escaneo de QR (BarcodeDetector). Usá el buscador de la lista en su lugar.', "Your browser doesn't support QR scanning (BarcodeDetector). Use the list search instead." ) ); ?>
        </div>
        <div id="amir-scan-err" class="amir-field-err" style="display:none;"></div>
        <div id="amir-scan-wrap" style="border-radius:12px;overflow:hidden;background:#000;position:relative;">
          <video id="amir-scan-video" style="width:100%;display:block;" playsinline autoplay muted></video>
          <div style="position:absolute;inset:0;border:3px solid rgba(255,255,255,.5);border-radius:12px;pointer-events:none;margin:15%;"></div>
        </div>
        <p style="text-align:center;color:#5a7068;font-size:13px;margin-top:12px;"><?php echo esc_html( $this->tt( 'Apuntá al código QR del voucher del cliente.', "Point at the customer's voucher QR code." ) ); ?></p>

        <script>
        (function(){
          if (!('BarcodeDetector' in window)) {
            document.getElementById('amir-scan-unsupported').style.display = 'block';
            document.getElementById('amir-scan-wrap').style.display = 'none';
            return;
          }
          var video    = document.getElementById('amir-scan-video');
          var detector = new BarcodeDetector({ formats: ['qr_code'] });
          var errBox   = document.getElementById('amir-scan-err');
          var busy     = false; // true mientras se valida un código contra el servidor

          navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function(stream){
              video.srcObject = stream;
              var tick = function(){
                if (busy) { requestAnimationFrame(tick); return; }
                detector.detect(video).then(function(codes){
                  if (codes.length > 0) {
                    handleCode(codes[0].rawValue);
                  }
                  requestAnimationFrame(tick);
                }).catch(function(){ requestAnimationFrame(tick); });
              };
              requestAnimationFrame(tick);
            })
            .catch(function(){
              document.getElementById('amir-scan-unsupported').textContent = '<?php echo esc_js( $this->tt( 'No se pudo acceder a la cámara — revisá los permisos del navegador.', "Couldn't access the camera — check the browser permissions." ) ); ?>';
              document.getElementById('amir-scan-unsupported').style.display = 'block';
            });

          // Antes esto armaba un <form> oculto y hacía un submit real —
          // cada escaneo inválido/vencido era una recarga de página
          // completa (la cámara tenía que re-negociar getUserMedia() de
          // cero para poder reintentar). Con una fila de pasajeros para
          // hacer check-in, cada voucher rechazado costaba un viaje de ida
          // y vuelta entero. Ahora valida por fetch() contra
          // ajax_scan_lookup() (class-field-page.php) — en éxito navega
          // igual que antes, en rechazo sigue escaneando sin recargar.
          // Auditoría de UX pre-empaquetado v5.7.14, CONTRIBUTING.md § 16.91.
          function handleCode(raw) {
            // El voucher general del carrito (§ 16.15 CONTRIBUTING.md) usa
            // ?cart=X&token=Y en vez de ?ref=X&token=Y — mismo QR scanner,
            // el servidor decide qué es en ajax_scan_lookup().
            var ref, cart, token;
            try {
              var url = new URL(raw);
              ref   = url.searchParams.get('ref');
              cart  = url.searchParams.get('cart');
              token = url.searchParams.get('token');
            } catch (e) { /* no era una URL válida */ }
            if ((!ref && !cart) || !token) return; // seguir escaneando, no era un voucher nuestro

            busy = true;
            errBox.style.display = 'none';
            var body = new URLSearchParams();
            body.set('action', 'amir_field_scan_lookup');
            body.set('nonce', window.amirAdminData ? amirAdminData.nonce : '');
            body.set('ref', ref || '');
            body.set('cart', cart || '');
            body.set('token', token);

            fetch(ajaxurl, { method: 'POST', body: body })
              .then(function(r){ return r.json(); })
              .then(function(json){
                if (json && json.success && json.data && json.data.redirect) {
                  window.location.href = json.data.redirect;
                  return; // se navega, no hace falta reanudar el escaneo
                }
                errBox.textContent = ( json && json.data && json.data.text )
                  || '<?php echo esc_js( $this->tt( 'No se pudo validar el voucher.', "Couldn't validate the voucher." ) ); ?>';
                errBox.style.display = 'block';
                busy = false;
              })
              .catch(function(){
                errBox.textContent = '<?php echo esc_js( $this->tt( 'Error de conexión — reintentá.', 'Connection error — try again.' ) ); ?>';
                errBox.style.display = 'block';
                busy = false;
              });
          }
        })();
        </script>
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
        // LEFT JOIN (no JOIN) en amir_tours/flow_rooms — antes esto era un
        // INNER JOIN con amir_tours, así que una reserva de habitación
        // (item_type='room', tour_id NULL) mostraba "Reserva no encontrada"
        // acá — bug real encontrado al construir el escaneo de carrito
        // (§ 16.15 CONTRIBUTING.md), que sí puede linkear a un ítem room.
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, t.name_es AS tour_name, t.id AS tour_id, s.time_start, s.label_es AS schedule_label,
                    r.name_es AS room_name
             FROM {$wpdb->prefix}amir_bookings b
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
             WHERE b.id = %d",
            $id
        ) );

        if ( ! $b ) {
            echo '<div class="amir-field-err">' . esc_html( $this->tt( 'Reserva no encontrada.', 'Booking not found.' ) ) . '</div>';
            return;
        }

        $is_room = $b->item_type === 'room';
        $pax   = (int) $b->adults + (int) $b->children + (int) $b->babies;
        $phone = preg_replace( '/[^0-9]/', '', $b->customer_phone ?? '' );
        ?>
        <div class="amir-field-section">
          <h3><?php echo esc_html( $this->tt( 'Cliente', 'Customer' ) ); ?></h3>
          <div class="amir-field-detail-row"><span><?php echo esc_html( $this->tt( 'Nombre', 'Name' ) ); ?></span><?php echo esc_html( $b->customer_name ); ?></div>
          <div class="amir-field-detail-row"><span>Email</span><?php echo esc_html( $b->customer_email ); ?></div>
          <?php if ( $phone ) : ?>
          <div class="amir-field-contact-row">
            <a class="amir-field-call" href="tel:<?php echo esc_attr( $phone ); ?>">📞 <?php echo esc_html( $this->tt( 'Llamar', 'Call' ) ); ?></a>
            <a class="amir-field-wa" href="https://wa.me/<?php echo esc_attr( $phone ); ?>" target="_blank">💬 WhatsApp</a>
          </div>
          <?php endif; ?>
        </div>

        <div class="amir-field-section">
          <h3><?php echo esc_html( $this->tt( 'Reserva', 'Booking' ) ); ?> <?php echo esc_html( $b->booking_ref ); ?></h3>
          <?php if ( $is_room ) : ?>
          <div class="amir-field-detail-row"><span><?php echo esc_html( $this->tt( 'Habitación', 'Room' ) ); ?></span><?php echo esc_html( $b->room_name ); ?></div>
          <div class="amir-field-detail-row"><span>Check-in → Check-out</span><?php echo esc_html( mysql2date( 'd/m/Y', $b->tour_date ) ); ?> → <?php echo esc_html( mysql2date( 'd/m/Y', $b->check_out_date ) ); ?></div>
          <div class="amir-field-detail-row"><span><?php echo esc_html( $this->tt( 'Huéspedes', 'Guests' ) ); ?></span><?php echo (int) $b->adults; ?></div>
          <?php else : ?>
          <div class="amir-field-detail-row"><span>Tour</span><?php echo esc_html( $b->tour_name ); ?></div>
          <div class="amir-field-detail-row"><span><?php echo esc_html( $this->tt( 'Fecha y horario', 'Date and schedule' ) ); ?></span><?php echo esc_html( mysql2date( 'd/m/Y', $b->tour_date ) ); ?><?php echo $b->time_start ? ' · ' . esc_html( substr( $b->time_start, 0, 5 ) ) : ''; ?></div>
          <div class="amir-field-detail-row"><span><?php echo esc_html( $this->tt( 'Personas', 'People' ) ); ?></span><?php echo $pax; ?> (<?php echo (int) $b->adults; ?>A <?php echo (int) $b->children; ?>N <?php echo (int) $b->babies; ?>B)</div>
          <?php endif; ?>
          <div class="amir-field-detail-row"><span><?php echo esc_html( $this->tt( 'Estado', 'Status' ) ); ?></span>
            <span class="amir-field-badge" style="<?php echo $this->status_style( $b->status ); ?>"><?php echo esc_html( $this->status_label( $b->status ) ); ?></span>
          </div>
        </div>

        <?php if ( $b->checked_in_at ) : ?>
        <div class="amir-field-msg">✓ <?php echo esc_html( sprintf( $this->tt( 'Check-in registrado — %s', 'Check-in recorded — %s' ), mysql2date( 'd/m/Y H:i', $b->checked_in_at ) ) ); ?></div>
        <?php elseif ( in_array( $b->status, [ 'confirmed', 'completed' ], true ) ) : ?>
        <form method="post">
          <?php wp_nonce_field( 'amir_field_action' ); ?>
          <input type="hidden" name="amir_action" value="checkin" />
          <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
          <button type="submit" class="amir-field-btn amir-field-btn-primary">📷 <?php echo esc_html( $this->tt( 'Marcar check-in', 'Mark check-in' ) ); ?></button>
        </form>
        <?php endif; ?>

        <?php if ( $b->status === 'pending' ) : ?>
        <form method="post">
          <?php wp_nonce_field( 'amir_field_action' ); ?>
          <input type="hidden" name="amir_action" value="confirm" />
          <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
          <button type="submit" class="amir-field-btn amir-field-btn-primary">✓ <?php echo esc_html( $this->tt( 'Confirmar reserva', 'Confirm booking' ) ); ?></button>
        </form>
        <?php endif; ?>

        <?php
        $has_pending_balance = $b->status === 'confirmed' && ! $is_room
            && (int) ( $b->deposit_pct ?? 0 ) > 0 && empty( $b->balance_paid_at );
        ?>
        <?php if ( $has_pending_balance ) :
          $deposit_charged = round( (float) $b->total_mxn * (int) $b->deposit_pct / 100, 2 );
          $balance_owed     = round( (float) $b->total_mxn - $deposit_charged, 2 );
        ?>
        <div class="amir-field-section">
          <h3>💰 <?php echo esc_html( sprintf( $this->tt( 'Depósito del %d%% — saldo %s', '%d%% deposit — balance %s' ), (int) $b->deposit_pct, \AmirBooking\Core\Currency::format( $balance_owed ) ) ); ?></h3>
          <form method="post">
            <?php wp_nonce_field( 'amir_field_action' ); ?>
            <input type="hidden" name="amir_action" value="mark_balance_paid" />
            <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
            <button type="submit" class="amir-field-btn amir-field-btn-primary"
              onclick="return confirm('<?php echo esc_js( $this->tt( '¿Marcar el saldo como cobrado en persona? No se manda ningún email.', 'Mark the balance as collected in person? No email will be sent.' ) ); ?>')">💰 <?php echo esc_html( $this->tt( 'Marcar saldo cobrado', 'Mark balance as collected' ) ); ?></button>
          </form>
          <form method="post">
            <?php wp_nonce_field( 'amir_field_action' ); ?>
            <input type="hidden" name="amir_action" value="send_balance_link" />
            <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
            <button type="submit" class="amir-field-btn">📧 <?php echo esc_html( $this->tt( 'Enviar link de pago del saldo', 'Send payment link for the balance' ) ); ?></button>
          </form>
        </div>
        <?php endif; ?>

        <?php if ( $b->status === 'awaiting_payment' ) : ?>
        <!-- El cliente todavía no pagó — no hay "confirmar" válido para este
             estado (BookingManager::confirm() exige status='pending'), la
             única acción real es reenviarle el link de pago. -->
        <form method="post">
          <?php wp_nonce_field( 'amir_field_action' ); ?>
          <input type="hidden" name="amir_action" value="resend_payment_link" />
          <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
          <button type="submit" class="amir-field-btn amir-field-btn-primary">💳 <?php echo esc_html( $this->tt( 'Reenviar link de pago', 'Resend payment link' ) ); ?></button>
        </form>
        <?php endif; ?>

        <?php if ( in_array( $b->status, [ 'pending', 'confirmed' ], true ) ) : ?>
        <?php if ( ! $is_room ) : ?>
        <div class="amir-field-section">
          <h3><?php echo esc_html( $this->tt( 'Reprogramar', 'Reschedule' ) ); ?></h3>
          <form method="post">
            <?php wp_nonce_field( 'amir_field_action' ); ?>
            <input type="hidden" name="amir_action" value="reschedule" />
            <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Nueva fecha', 'New date' ) ); ?></label>
            <input type="date" name="new_date" id="amir-field-new-date" required min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" class="amir-field-input"
                   onchange="amirFieldLoadSchedules(<?php echo (int) $b->tour_id; ?>, this.value)" />
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Horario', 'Schedule' ) ); ?></label>
            <select name="new_schedule_id" id="amir-field-schedule" class="amir-field-input"
                    onfocus="amirFieldLoadSchedules(<?php echo (int) $b->tour_id; ?>, document.getElementById('amir-field-new-date').value)">
              <option value="<?php echo (int) $b->schedule_id; ?>"><?php echo esc_html( $this->tt( '— Mantener el actual —', '— Keep the current one —' ) ); ?></option>
            </select>
            <p id="amir-field-schedule-hint" style="font-size:12px;color:#5a7068;margin:-4px 0 8px;"></p>
            <button type="submit" class="amir-field-btn amir-field-btn-primary">🔄 <?php echo esc_html( $this->tt( 'Reprogramar', 'Reschedule' ) ); ?></button>
          </form>
        </div>
        <?php else : ?>
        <p style="font-size:12px;color:#5a7068;text-align:center;margin:8px 0;"><?php echo esc_html( $this->tt( 'Para reprogramar el check-in/check-out de esta habitación, usá el panel completo → Reservas.', 'To reschedule this room\'s check-in/check-out, use the full panel → Bookings.' ) ); ?></p>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Cancelar esta reserva?', 'Cancel this booking?' ) ); ?>');">
          <?php wp_nonce_field( 'amir_field_action' ); ?>
          <input type="hidden" name="amir_action" value="cancel" />
          <input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
          <button type="submit" class="amir-field-btn amir-field-btn-danger">✕ <?php echo esc_html( $this->tt( 'Cancelar reserva', 'Cancel booking' ) ); ?></button>
        </form>
        <?php endif; ?>

        <script>
        // Antes solo listaba los horarios del tour, ciego a cupo — un
        // operador apurado podía reprogramar a una fecha ya llena y
        // enterarse recién al enviar el formulario (auditoría de UX
        // pre-empaquetado v5.7.14, CONTRIBUTING.md § 16.91). Con fecha
        // elegida, usa /availability/day (mismo endpoint que ya consulta
        // el calendario del cliente) para mostrar cupos reales y deshabilitar
        // los horarios llenos; sin fecha, sigue listando los horarios solos
        // como antes.
        function amirFieldLoadSchedules(tourId, dateStr) {
          var sel  = document.getElementById('amir-field-schedule');
          var hint = document.getElementById('amir-field-schedule-hint');
          if (!tourId || !window.amirAdminData) return;

          var url = dateStr
            ? amirAdminData.apiUrl + 'availability/day?tour_id=' + tourId + '&date=' + dateStr
            : amirAdminData.apiUrl + 'tours/' + tourId + '/schedules';

          fetch(url, { headers: { 'X-WP-Nonce': amirAdminData.nonce } })
            .then(function(r){ return r.json(); })
            .then(function(list){
              var current = sel.value;
              sel.innerHTML = '<option value="0"><?php echo esc_js( $this->tt( '— Sin horario específico —', '— No specific schedule —' ) ); ?></option>';
              var anyFull = false;
              (list || []).forEach(function(s){
                var opt = document.createElement('option');
                opt.value = s.id;
                var label = (s.label_es || '') + ' ' + (s.time_start || '').slice(0,5);
                if (dateStr && typeof s.available !== 'undefined') {
                  label += s.available
                    ? ' (' + s.slots_remaining + ' <?php echo esc_js( $this->tt( 'cupos', 'spots' ) ); ?>)'
                    : ' (<?php echo esc_js( $this->tt( 'sin cupo', 'full' ) ); ?>)';
                  if (!s.available) { opt.disabled = true; anyFull = true; }
                }
                opt.textContent = label;
                if (String(s.id) === String(current)) opt.selected = true;
                sel.appendChild(opt);
              });
              hint.textContent = (dateStr && anyFull)
                ? '<?php echo esc_js( $this->tt( '⚠ Algunos horarios están sin cupo para esa fecha.', '⚠ Some schedules are full for that date.' ) ); ?>'
                : '';
            });
        }
        </script>
        <?php
    }

    // ── Detalle de carrito (voucher general, § 16.15 CONTRIBUTING.md) ────

    /**
     * Vista de índice para un cart_group_id escaneado — lista cada ítem
     * (tour u habitación) con su estado, y linkea al detalle individual de
     * cada uno (render_detail() de siempre, sin duplicar sus acciones de
     * check-in/confirmar/cancelar).
     */
    private function render_cart_detail( string $cart_group_id ): void {
        global $wpdb;
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, t.name_es AS tour_name_es, r.name_es AS room_name_es
             FROM {$wpdb->prefix}amir_bookings b
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
             WHERE b.cart_group_id = %s
             ORDER BY b.item_type ASC, b.tour_date ASC",
            $cart_group_id
        ) );

        if ( empty( $bookings ) ) {
            echo '<div class="amir-field-err">' . esc_html( $this->tt( 'Carrito no encontrado.', 'Cart not found.' ) ) . '</div>';
            return;
        }

        $primary = $bookings[0];
        ?>
        <div class="amir-field-section">
          <h3><?php echo esc_html( $this->tt( 'Cliente', 'Customer' ) ); ?></h3>
          <div class="amir-field-detail-row"><span><?php echo esc_html( $this->tt( 'Nombre', 'Name' ) ); ?></span><?php echo esc_html( $primary->customer_name ); ?></div>
          <div class="amir-field-detail-row"><span>Email</span><?php echo esc_html( $primary->customer_email ); ?></div>
          <?php $phone = preg_replace( '/[^0-9]/', '', $primary->customer_phone ?? '' ); if ( $phone ) : ?>
          <div class="amir-field-contact-row">
            <a class="amir-field-call" href="tel:<?php echo esc_attr( $phone ); ?>">📞 <?php echo esc_html( $this->tt( 'Llamar', 'Call' ) ); ?></a>
            <a class="amir-field-wa" href="https://wa.me/<?php echo esc_attr( $phone ); ?>" target="_blank">💬 WhatsApp</a>
          </div>
          <?php endif; ?>
        </div>

        <div class="amir-field-section">
          <h3><?php echo esc_html( sprintf( $this->tt( 'Ítems de esta reserva (%d)', 'Items in this booking (%d)' ), count( $bookings ) ) ); ?></h3>
          <?php foreach ( $bookings as $b ) :
              $icon  = $b->item_type === 'room' ? '🛏' : '🏄';
              $name  = $b->item_type === 'room' ? $b->room_name_es : $b->tour_name_es;
              $dates = $b->item_type === 'room'
                  ? mysql2date( 'd/m/Y', $b->tour_date ) . ' → ' . mysql2date( 'd/m/Y', $b->check_out_date )
                  : mysql2date( 'd/m/Y', $b->tour_date );
          ?>
            <a class="amir-field-card" href="<?php echo esc_url( admin_url( 'admin.php?page=amir-field&view=detail&id=' . $b->id ) ); ?>">
              <div class="amir-field-card-top">
                <div>
                  <div class="amir-field-name"><?php echo $icon; ?> <?php echo esc_html( $name ); ?></div>
                  <div class="amir-field-tour"><?php echo esc_html( $b->booking_ref ); ?></div>
                </div>
                <span class="amir-field-badge" style="<?php echo $this->status_style( $b->status ); ?>"><?php echo esc_html( $this->status_label( $b->status ) ); ?></span>
              </div>
              <div class="amir-field-date">📅 <?php echo esc_html( $dates ); ?><?php echo $b->checked_in_at ? ' · ✓ check-in' : ''; ?></div>
            </a>
          <?php endforeach; ?>
        </div>
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
            <select name="tour_id" id="amir-field-new-tour" required class="amir-field-input"
                    onchange="amirFieldLoadSchedules(this.value, document.getElementById('amir-field-new-date').value)">
              <option value=""><?php echo esc_html( $this->tt( '— Selecciona un tour —', '— Select a tour —' ) ); ?></option>
              <?php foreach ( $tours as $t ) : ?>
                <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->name_es ); ?></option>
              <?php endforeach; ?>
            </select>
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Horario', 'Schedule' ) ); ?></label>
            <select name="schedule_id" id="amir-field-schedule" class="amir-field-input">
              <option value="0"><?php echo esc_html( $this->tt( '— Sin horario específico —', '— No specific schedule —' ) ); ?></option>
            </select>
            <p id="amir-field-schedule-hint" style="font-size:12px;color:#5a7068;margin:-4px 0 8px;"></p>
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Fecha *', 'Date *' ) ); ?></label>
            <input type="date" name="date" id="amir-field-new-date" required min="<?php echo esc_attr( date('Y-m-d') ); ?>" class="amir-field-input"
                   onchange="amirFieldLoadSchedules(document.getElementById('amir-field-new-tour').value, this.value)" />
          </div>

          <div class="amir-field-section">
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Adultos *', 'Adults *' ) ); ?></label>
            <input type="number" name="adults" value="1" min="1" max="50" class="amir-field-input" />
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Niños', 'Children' ) ); ?></label>
            <input type="number" name="children" value="0" min="0" max="50" class="amir-field-input" />
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Bebés', 'Babies' ) ); ?></label>
            <input type="number" name="babies" value="0" min="0" max="20" class="amir-field-input" />
          </div>

          <div class="amir-field-section">
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Nombre del cliente *', 'Customer name *' ) ); ?></label>
            <input type="text" name="customer_name" required class="amir-field-input" />
            <label class="amir-field-label">Email *</label>
            <input type="email" name="customer_email" required class="amir-field-input" />
            <label class="amir-field-label">WhatsApp / <?php echo esc_html( $this->tt( 'Teléfono', 'Phone' ) ); ?></label>
            <input type="text" name="customer_phone" placeholder="+52 983 123 4567" class="amir-field-input" />
          </div>

          <div class="amir-field-section">
            <label class="amir-field-label"><?php echo esc_html( $this->tt( 'Total (0 = calcular automático)', 'Total (0 = calculate automatically)' ) ); ?></label>
            <input type="number" name="total_mxn" value="0" min="0" step="0.01" class="amir-field-input" />
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;">
              <input type="checkbox" name="awaiting_payment" value="1" style="width:20px;height:20px;" />
              <?php echo esc_html( $this->tt( 'El cliente todavía no pagó — enviarle link de pago', 'The customer has not paid yet — send them a payment link' ) ); ?>
            </label>
          </div>

          <button type="submit" class="amir-field-btn amir-field-btn-primary"><?php echo esc_html( $this->tt( 'Crear reserva', 'Create booking' ) ); ?></button>
        </form>

        <script>
        // Misma lógica que la copia de render_detail() (cada vista tiene la
        // suya, no hay una sola instancia de página que las comparta) — con
        // fecha elegida, muestra cupo real vía /availability/day en vez de
        // listar horarios a ciegas. Ver CONTRIBUTING.md § 16.91.
        function amirFieldLoadSchedules(tourId, dateStr) {
          var sel  = document.getElementById('amir-field-schedule');
          var hint = document.getElementById('amir-field-schedule-hint');
          sel.innerHTML = '<option value="0"><?php echo esc_js( $this->tt( '— Sin horario específico —', '— No specific schedule —' ) ); ?></option>';
          if (hint) hint.textContent = '';
          if (!tourId || !window.amirAdminData) return;

          var url = dateStr
            ? amirAdminData.apiUrl + 'availability/day?tour_id=' + tourId + '&date=' + dateStr
            : amirAdminData.apiUrl + 'tours/' + tourId + '/schedules';

          fetch(url, { headers: { 'X-WP-Nonce': amirAdminData.nonce } })
            .then(function(r){ return r.json(); })
            .then(function(list){
              var anyFull = false;
              (list || []).forEach(function(s){
                var opt = document.createElement('option');
                opt.value = s.id;
                var label = (s.label_es || '') + ' ' + (s.time_start || '').slice(0,5);
                if (dateStr && typeof s.available !== 'undefined') {
                  label += s.available
                    ? ' (' + s.slots_remaining + ' <?php echo esc_js( $this->tt( 'cupos', 'spots' ) ); ?>)'
                    : ' (<?php echo esc_js( $this->tt( 'sin cupo', 'full' ) ); ?>)';
                  if (!s.available) { opt.disabled = true; anyFull = true; }
                }
                opt.textContent = label;
                sel.appendChild(opt);
              });
              if (hint) hint.textContent = (dateStr && anyFull)
                ? '<?php echo esc_js( $this->tt( '⚠ Algunos horarios están sin cupo para esa fecha.', '⚠ Some schedules are full for that date.' ) ); ?>'
                : '';
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

        if ( $action === 'scan_lookup' ) {
            $ref   = strtoupper( sanitize_text_field( $_POST['ref'] ?? '' ) );
            $cart  = sanitize_text_field( $_POST['cart'] ?? '' );
            $token = sanitize_text_field( $_POST['token'] ?? '' );

            // Voucher general de carrito (§ 16.15 CONTRIBUTING.md) — la
            // PRIMERA reserva del grupo autoriza el carrito entero, mismo
            // criterio que un ref+token individual.
            if ( $cart ) {
                global $wpdb;
                $primary = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE cart_group_id = %s ORDER BY id ASC LIMIT 1",
                    $cart
                ) );
                if ( $primary && $manager->authorize_public_access( $primary, $token ) ) {
                    wp_redirect( admin_url( 'admin.php?page=amir-field&view=cart&cart_group_id=' . rawurlencode( $cart ) ) );
                    exit;
                }
                return [ 'ok' => false, 'text' => $this->tt( 'Voucher inválido — el código QR no corresponde a ningún carrito, o el link venció.', 'Invalid voucher — the QR code does not match any cart, or the link expired.' ) ];
            }

            $booking = $ref ? $manager->get_booking_by_ref( $ref ) : null;

            // Mismo token fuerte que usa el cliente para "ver mi reserva" —
            // corroborar el voucher es justo lo que hace esta autorización.
            if ( $booking && $manager->authorize_public_access( $booking, $token ) ) {
                wp_redirect( admin_url( 'admin.php?page=amir-field&view=detail&id=' . $booking->id ) );
                exit;
            }
            return [ 'ok' => false, 'text' => $this->tt( 'Voucher inválido — el código QR no corresponde a ninguna reserva, o el link venció.', 'Invalid voucher — the QR code does not match any booking, or the link expired.' ) ];
        }

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            return [ 'ok' => false, 'text' => $this->tt( 'Reserva no válida.', 'Invalid booking.' ) ];
        }

        if ( $action === 'confirm' ) {
            // confirm() exige status='pending' — devuelve false sin avisar si
            // no lo está (ya confirmada, cancelada, etc.). Antes esto se
            // ignoraba y siempre se mostraba éxito, aunque no pasara nada.
            $ok = $manager->confirm( $id, '' );
            return [
                'ok'   => $ok,
                'text' => $ok
                    ? $this->tt( 'Reserva confirmada. Email y voucher en camino.', 'Booking confirmed. Email and voucher on the way.' )
                    : $this->tt( 'No se pudo confirmar — la reserva ya no está en estado "pendiente" (puede que ya se haya confirmado, cancelado, o esté esperando pago/aprobación del proveedor).', 'Could not confirm — the booking is no longer in "pending" status (it may already be confirmed, cancelled, or awaiting payment/provider approval).' ),
            ];
        }

        // Depósito parcial ("Depósito parcial por tour") — mismas dos
        // acciones que class-bookings-page.php, para poder cobrar el saldo
        // desde el mismo escaneo/búsqueda de Modo Campo (ej. al hacer
        // check-in) sin tener que ir al panel completo de Reservas.
        if ( $action === 'mark_balance_paid' ) {
            global $wpdb;
            $b = $manager->get_booking( $id );
            if ( ! $b || $b->status !== 'confirmed' || ( $b->item_type ?? 'tour' ) !== 'tour'
                 || (int) ( $b->deposit_pct ?? 0 ) <= 0 || ! empty( $b->balance_paid_at ) ) {
                return [ 'ok' => false, 'text' => $this->tt( 'Esta reserva no tiene un saldo de depósito pendiente.', 'This booking has no pending deposit balance.' ) ];
            }
            $wpdb->update( "{$wpdb->prefix}amir_bookings", [ 'balance_paid_at' => current_time('mysql') ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
            return [ 'ok' => true, 'text' => '💰 ' . $this->tt( 'Saldo marcado como cobrado.', 'Balance marked as collected.' ) ];
        }

        if ( $action === 'send_balance_link' ) {
            $b = $manager->get_booking( $id );
            if ( ! $b || $b->status !== 'confirmed' || ( $b->item_type ?? 'tour' ) !== 'tour'
                 || (int) ( $b->deposit_pct ?? 0 ) <= 0 || ! empty( $b->balance_paid_at ) ) {
                return [ 'ok' => false, 'text' => $this->tt( 'Esta reserva no tiene un saldo de depósito pendiente.', 'This booking has no pending deposit balance.' ) ];
            }
            $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
            $b_full     = $dispatcher->get_booking_with_tour( $id );
            $result     = $dispatcher->send_balance_payment_link_notice( $b_full ?: $b );
            return [
                'ok'   => $result['success'],
                'text' => $result['success'] ? '📧 ' . $this->tt( 'Link de pago del saldo enviado.', 'Payment link for the balance sent.' ) : $this->tt( 'No se pudo enviar el email.', 'The email could not be sent.' ),
            ];
        }

        if ( $action === 'resend_payment_link' ) {
            $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
            $booking    = $dispatcher->get_booking_with_tour( $id );
            if ( ! $booking || $booking->status !== 'awaiting_payment' ) {
                return [ 'ok' => false, 'text' => $this->tt( 'La reserva no está en estado "esperando pago".', 'The booking is not in "awaiting payment" status.' ) ];
            }
            $result = $dispatcher->send_payment_link_notice( $booking );
            return [
                'ok'   => $result['success'],
                'text' => $result['success'] ? $this->tt( 'Link de pago reenviado al cliente.', 'Payment link resent to the customer.' ) : $this->tt( 'No se pudo enviar el email — revisá el log de errores del servidor.', 'The email could not be sent — check the server error log.' ),
            ];
        }

        if ( $action === 'checkin' ) {
            global $wpdb;
            // $wpdb->update() no puede expresar "WHERE checked_in_at IS NULL"
            // (un null en el array de condiciones compara contra '', nunca
            // contra NULL real) — query cruda para el guard atómico.
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}amir_bookings SET checked_in_at = %s WHERE id = %d AND checked_in_at IS NULL",
                current_time( 'mysql' ), $id
            ) );
            return [
                'ok'   => (bool) $updated,
                'text' => $updated ? $this->tt( 'Check-in registrado.', 'Check-in recorded.' ) : $this->tt( 'Ya tenía un check-in registrado.', 'It already had a check-in recorded.' ),
            ];
        }

        if ( $action === 'cancel' ) {
            $result = $manager->cancel( $id, 'client' );
            return [ 'ok' => $result->success, 'text' => $result->success ? $this->tt( 'Reserva cancelada.', 'Booking cancelled.' ) : $result->error ];
        }

        if ( $action === 'reschedule' ) {
            $new_date = sanitize_text_field( $_POST['new_date'] ?? '' );
            $new_sch  = absint( $_POST['new_schedule_id'] ?? 0 );
            $result   = $manager->reschedule( $id, $new_date, $new_sch );
            return [ 'ok' => $result->success, 'text' => $result->success ? $this->tt( 'Reserva reprogramada.', 'Booking rescheduled.' ) : $result->error ];
        }

        return null;
    }

    // ── Helpers de presentación ──────────────────────────────────────────

    private function status_label( string $status ): string {
        $map = [
            'pending'                 => '⏳ ' . $this->tt( 'Pendiente', 'Pending' ),
            'confirmed'               => '✓ ' . $this->tt( 'Confirmada', 'Confirmed' ),
            'awaiting_payment'        => '💳 ' . $this->tt( 'Espera pago', 'Awaiting payment' ),
            'cancellation_requested'  => '⚠ ' . $this->tt( 'Solicita cancelación', 'Cancellation requested' ),
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
