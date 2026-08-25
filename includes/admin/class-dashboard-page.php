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
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'amir-booking' ) );
        }

        $today    = current_time( 'Y-m-d' );
        $tomorrow = date( 'Y-m-d', strtotime( '+1 day' ) );

        $today_data    = $this->get_day_summary( $today );
        $tomorrow_data = $this->get_day_summary( $tomorrow );
        $stats         = $this->get_period_stats();
        $notifications = $this->get_unread_notifications();
        $provider_pending = $this->get_provider_pending_approvals();
        $rooms_enabled = AMIR_EDITION === 'pro_max';
        $today_rooms    = $rooms_enabled ? $this->get_room_day_summary( $today )    : [ 'arrivals' => [], 'departures' => [] ];
        $tomorrow_rooms = $rooms_enabled ? $this->get_room_day_summary( $tomorrow ) : [ 'arrivals' => [], 'departures' => [] ];
        $special_requests = $this->get_special_requests_today_tomorrow( $today, $tomorrow );

        ?>
        <div class="wrap ab-admin-wrap">
        <style>
        .ab-admin-wrap { max-width:1200px; }
        .ab-admin-wrap h1 { font-size:22px; font-weight:700; color:#1a2e24; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .ab-quicklinks { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
        .ab-quicklinks a { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #e1f5ee; color:#1a2e24; border-radius:8px; padding:9px 14px; font-size:13px; font-weight:600; text-decoration:none; }
        .ab-quicklinks a:hover { border-color:#1D9E75; color:#1D9E75; }
        .ab-provider-bar { background:#eef4ff; border:1px solid #bfdbfe; border-radius:10px; padding:12px 16px; margin-bottom:20px; }
        .ab-provider-bar .ptitle { font-size:13px; font-weight:700; color:#1a6fa8; margin-bottom:6px; }
        .ab-provider-item { font-size:13px; color:#1e40af; padding:3px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .ab-provider-item .urgent { color:#dc2626; font-weight:700; }
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
        <?php self::day_list_styles(); ?>

        .ab-notif-bar { background:#fff8e7; border:1px solid #fde68a; border-radius:10px; padding:12px 16px; margin-bottom:20px; }
        .ab-notif-bar .notif-title { font-size:13px; font-weight:700; color:#92400e; margin-bottom:6px; }
        .ab-notif-item { font-size:13px; color:#78350f; padding:3px 0; display:flex; align-items:center; gap:8px; }

        .ab-requests-bar { background:#eef4ff; border:1px solid #bfdbfe; border-radius:10px; padding:12px 16px; margin-bottom:20px; }
        .ab-requests-bar .rtitle { font-size:13px; font-weight:700; color:#1a6fa8; margin-bottom:8px; }
        .ab-request-item { padding:8px 0; border-top:1px solid #dbeafe; }
        .ab-request-item:first-of-type { border-top:none; }
        .ab-request-item .rwho { font-size:13px; font-weight:600; color:#1e3a5f; }
        .ab-request-item .rwho a { color:#1a6fa8; font-weight:700; }
        .ab-request-item .rwhen { font-weight:400; color:#5a7068; font-size:12px; margin-left:6px; }
        .ab-request-item .rtext { font-size:13px; color:#1a2e24; margin-top:3px; background:#fff; border-radius:6px; padding:8px 10px; }

        .ab-section-title { font-size:16px; font-weight:700; color:#1D9E75; margin:24px 0 12px; border-bottom:2px solid #e1f5ee; padding-bottom:8px; }

        .ab-shortcodes { background:#fff; border:1px solid #e1f5ee; border-radius:10px; margin-bottom:24px; }
        .ab-shortcodes summary { cursor:pointer; padding:14px 18px; font-size:14px; font-weight:700; color:#1a2e24; list-style:none; display:flex; align-items:center; gap:8px; }
        .ab-shortcodes summary::-webkit-details-marker { display:none; }
        .ab-shortcodes summary::before { content:'▸'; color:#1D9E75; transition:transform .15s; }
        .ab-shortcodes[open] summary::before { transform:rotate(90deg); }
        .ab-shortcodes-body { padding:0 18px 18px; }
        .ab-sc-item { border-top:1px solid #f0f5f2; padding:14px 0; }
        .ab-sc-item:first-child { border-top:none; padding-top:4px; }
        .ab-sc-code { display:inline-block; background:#f0faf6; color:#0F6E56; font-family:Consolas,Monaco,monospace; font-size:13px; padding:3px 8px; border-radius:5px; }
        .ab-sc-desc { font-size:13px; color:#5a7068; margin:6px 0 8px; }
        .ab-sc-atts { width:100%; border-collapse:collapse; font-size:12.5px; }
        .ab-sc-atts th { text-align:left; color:#5a7068; font-weight:600; padding:4px 10px 4px 0; }
        .ab-sc-atts td { padding:4px 10px 4px 0; color:#1a2e24; }
        .ab-sc-atts code { background:#f8fdfb; padding:1px 5px; border-radius:4px; color:#0F6E56; }
        </style>

        <h1>📅 <?php _e('Dashboard operativo', 'amir-booking'); ?>
          <span style="font-size:14px;font-weight:400;color:#5a7068;"><?php echo date_i18n( 'l j \d\e F Y', strtotime($today) ); ?></span>
          <span class="ab-badge ab-badge-neutral" style="margin-left:auto;font-size:11px;" title="<?php esc_attr_e( 'Versión del plugin instalada en este sitio', 'amir-booking' ); ?>">TourFlow v<?php echo esc_html( AMIR_VERSION ); ?></span>
        </h1>

        <div class="ab-quicklinks">
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-field') ); ?>">📱 <?php _e('Modo campo', 'amir-booking'); ?></a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-bookings-list&action=new') ); ?>">+ <?php _e('Nueva reserva', 'amir-booking'); ?></a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-calendar') ); ?>">📅 <?php _e('Calendario', 'amir-booking'); ?></a>
          <a href="<?php echo esc_url( admin_url('post-new.php?post_type=amir_tour') ); ?>">🏄 <?php _e('Nuevo tour', 'amir-booking'); ?></a>
          <?php if ( AMIR_EDITION === 'pro_max' ) : ?>
          <a href="<?php echo esc_url( admin_url('post-new.php?post_type=flow_room') ); ?>">🛏 <?php _e('Nueva habitación', 'amir-booking'); ?></a>
          <?php endif; ?>
          <?php if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) && get_option( 'amir_module_wishlist', '1' ) === '1' ) : ?>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-wishlist') ); ?>">📋 <?php _e('Lista de interés', 'amir-booking'); ?></a>
          <?php endif; ?>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-coupons') ); ?>">🎟 <?php _e('Cupones', 'amir-booking'); ?></a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-email-test&tab=texts') ); ?>">✉️ <?php _e('Editar emails', 'amir-booking'); ?></a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-reports') ); ?>">📊 <?php _e('Reportes', 'amir-booking'); ?></a>
        </div>

        <?php if ( ! empty( $provider_pending ) ) : ?>
        <div class="ab-provider-bar">
          <div class="ptitle">🤝 <?php printf( _n('%d reserva esperando aprobación del proveedor', '%d reservas esperando aprobación del proveedor', count($provider_pending), 'amir-booking'), count($provider_pending) ); ?></div>
          <?php foreach ( $provider_pending as $pp ) :
            $is_urgent = $pp['hours_left'] <= 6;
          ?>
            <div class="ab-provider-item">
              <a href="<?php echo esc_url( admin_url('admin.php?page=amir-bookings-list&action=view&id='.$pp['id']) ); ?>" style="color:#1a6fa8;font-weight:700;"><?php echo esc_html($pp['booking_ref']); ?></a>
              — <?php echo esc_html($pp['business_name']); ?> · <?php echo esc_html($pp['customer_name']); ?>
              <span class="<?php echo $is_urgent ? 'urgent' : ''; ?>">
                <?php echo $pp['hours_left'] > 0
                  ? ( $is_urgent ? '⚠ ' : '' ) . sprintf( __( 'vence en %dh', 'amir-booking' ), $pp['hours_left'] )
                  : __( 'vencida (esperando cron)', 'amir-booking' ); ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        // Referencia de shortcodes — texto largo con HTML/código embebido,
        // se arma en un array es/en en vez de _e() inline por legibilidad
        // (mismo criterio de fondo que EmailTexts, sin depender de gettext
        // para estas cadenas puntuales — el resto del archivo sí usa
        // __()/_e() donde ya era corto). Ver CONTRIBUTING.md § 16.86/16.87.
        $sc_en = strpos( get_user_locale(), 'en' ) === 0;
        $sct = function ( string $es, string $en ) use ( $sc_en ) { echo wp_kses_post( $sc_en ? $en : $es ); };
        ?>
        <details class="ab-shortcodes">
          <summary>🧩 <?php $sct( 'Shortcodes disponibles — cómo usar el plugin en una página', 'Available shortcodes — how to use the plugin on a page' ); ?></summary>
          <div class="ab-shortcodes-body">
            <p class="ab-sc-desc" style="margin-top:0;"><?php $sct(
              'Pegá cualquiera de estos en el contenido de una página o entrada de WordPress (editor de bloques, Elementor "Shortcode", etc.) para mostrar la parte correspondiente del plugin.',
              'Paste any of these into a WordPress page or post (block editor, Elementor "Shortcode", etc.) to show the corresponding part of the plugin.'
            ); ?></p>

            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_booking tour_id="3"]</span>
              <p class="ab-sc-desc"><?php $sct( 'Widget de reserva completo (calendario, personas, datos, pago) para un tour puntual. Usalo en la página de detalle de ese tour.', 'Full booking widget (calendar, people, details, payment) for a specific tour. Use it on that tour\'s detail page.' ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>tour_id</th><td><?php $sct( 'Obligatorio. El ID numérico del tour (columna "ID" en TourFlow → Tours).', 'Required. The tour\'s numeric ID ("ID" column in TourFlow → Tours).' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct(
                  'Opcional. Código de idioma (<code>es</code>, <code>en</code>, o cualquiera activado en Configuración → Idiomas). Por defecto detecta el idioma del sitio (Polylang/WPML) o español.',
                  'Optional. Language code (<code>es</code>, <code>en</code>, or any enabled in Settings → Languages). Defaults to detecting the site\'s language (Polylang/WPML) or Spanish.'
                ); ?></td></tr>
              </table>
            </div>

            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_tour_list]</span>
              <p class="ab-sc-desc"><?php $sct( 'Grilla o lista con todos los tours publicados (<code>status = activo</code>). Pensado para una página tipo "Nuestros tours".', 'Grid or list with all published tours (<code>status = active</code>). Meant for a "Our tours" style page.' ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>columns</th><td><?php $sct( 'Opcional. Columnas de la grilla, 1 a 4. Por defecto <code>3</code>.', 'Optional. Grid columns, 1 to 4. Defaults to <code>3</code>.' ); ?></td></tr>
                <tr><th>layout</th><td><?php $sct( 'Opcional. <code>grid</code> o <code>list</code>. Por defecto <code>grid</code>.', 'Optional. <code>grid</code> or <code>list</code>. Defaults to <code>grid</code>.' ); ?></td></tr>
                <tr><th>limit</th><td><?php $sct( 'Opcional. Cantidad máxima de tours a mostrar. <code>0</code> = todos. Por defecto <code>0</code>.', 'Optional. Maximum number of tours to show. <code>0</code> = all. Defaults to <code>0</code>.' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en', 'Optional, same as in' ); ?> <code>[flow_booking]</code>.</td></tr>
              </table>
            </div>

            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_spots_left tour_id="3"]</span>
              <p class="ab-sc-desc"><?php $sct(
                'Chip de urgencia real ("⚡ Solo 2 lugares para el 20 de agosto") — muestra el cupo restante de una fecha puntual, o de la próxima fecha disponible si no se indica una. Pensado para insertar dentro de una página de contenido/landing junto a la descripción del tour, no reemplaza al widget de reserva. Usa la disponibilidad real (mismo motor que el calendario), no un número inventado.',
                'Real urgency chip ("⚡ Only 2 spots left for August 20") — shows the remaining spots for a specific date, or the next available date if none is given. Meant to be inserted inside a content page/landing next to the tour description, it doesn\'t replace the booking widget. Uses real availability (same engine as the calendar), not a made-up number.'
              ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>tour_id</th><td><?php $sct( 'Obligatorio. Igual que en', 'Required. Same as in' ); ?> <code>[flow_booking]</code>.</td></tr>
                <tr><th>date</th><td><?php $sct( 'Opcional, formato <code>AAAA-MM-DD</code>. Si se omite, busca la próxima fecha disponible del tour.', 'Optional, <code>YYYY-MM-DD</code> format. If omitted, looks for the tour\'s next available date.' ); ?></td></tr>
                <tr><th>threshold</th><td><?php $sct( 'Opcional. Cupo a partir del cual se considera "bajo" y se muestra en tono urgente (⚡). Por defecto <code>5</code>.', 'Optional. Spot count below which it\'s considered "low" and shown with an urgent tone (⚡). Defaults to <code>5</code>.' ); ?></td></tr>
                <tr><th>only_if_low</th><td><?php $sct( 'Opcional. <code>yes</code> = no mostrar nada si el cupo no es bajo (solo aparece cuando conviene crear urgencia). Por defecto <code>no</code>.', 'Optional. <code>yes</code> = show nothing if the spot count isn\'t low (only appears when it helps create urgency). Defaults to <code>no</code>.' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en', 'Optional, same as in' ); ?> <code>[flow_booking]</code>.</td></tr>
              </table>
            </div>

            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_tour_dates tour_id="3" title="Últimas fechas de agosto"]</span>
              <p class="ab-sc-desc"><?php $sct(
                'Tira de próximas fechas disponibles de un tour, cada una como botón "Reservar" que linkea directo a la ficha del tour con esa fecha precargada (el calendario del widget la selecciona solo). Pensado para landings de promoción puntual (ej. "Últimas fechas de agosto" enlazada desde una campaña o newsletter) — no es un buscador, muestra fechas fijas de un único tour.',
                'Strip of upcoming available dates for a tour, each as a "Book" button linking straight to the tour page with that date preloaded (the widget\'s calendar selects it on its own). Meant for one-off promotional landings (e.g. "Last August dates" linked from a campaign or newsletter) — it\'s not a search tool, it shows fixed dates for a single tour.'
              ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>tour_id</th><td><?php $sct( 'Obligatorio. Igual que en', 'Required. Same as in' ); ?> <code>[flow_booking]</code>.</td></tr>
                <tr><th>month</th><td><?php $sct( 'Opcional, formato <code>AAAA-MM</code>. Si se omite, muestra las próximas fechas disponibles sin importar el mes.', 'Optional, <code>YYYY-MM</code> format. If omitted, shows the next available dates regardless of month.' ); ?></td></tr>
                <tr><th>limit</th><td><?php $sct( 'Opcional. Cantidad máxima de fechas a mostrar. Por defecto <code>6</code>.', 'Optional. Maximum number of dates to show. Defaults to <code>6</code>.' ); ?></td></tr>
                <tr><th>title</th><td><?php $sct( 'Opcional. Título arriba de la tira (ej. "Últimas fechas de agosto"). Vacío por defecto.', 'Optional. Title above the strip (e.g. "Last August dates"). Empty by default.' ); ?></td></tr>
                <tr><th>accent</th><td><?php $sct( 'Opcional. Color de acento en hex. Por defecto el verde de la marca.', 'Optional. Accent color in hex. Defaults to the brand green.' ); ?></td></tr>
                <tr><th>cta_text_es</th> / <th>cta_text_en</th><td><?php $sct( 'Opcional. Texto del botón por idioma. Por defecto "Reservar" / "Book".', 'Optional. Button text per language. Defaults to "Reservar" / "Book".' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en', 'Optional, same as in' ); ?> <code>[flow_booking]</code>.</td></tr>
              </table>
            </div>

            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_wishlist]</span>
              <p class="ab-sc-desc"><?php $sct( 'Grilla "Próximamente" con los tours en borrador que tienen la lista de interés activada (ver TourFlow → Lista de interés). Deja anotarse sin cobrar todavía.', '"Coming soon" grid with draft tours that have the waitlist enabled (see TourFlow → Waitlist). Lets people sign up without charging yet.' ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>columns</th><td><?php $sct( 'Opcional. Igual que en', 'Optional. Same as in' ); ?> <code>[flow_tour_list]</code>.</td></tr>
                <tr><th>accent</th><td><?php $sct( 'Opcional. Color de acento en hex (ej. <code>#1D9E75</code>). Por defecto el verde de la marca.', 'Optional. Accent color in hex (e.g. <code>#1D9E75</code>). Defaults to the brand green.' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en', 'Optional, same as in' ); ?> <code>[flow_booking]</code>.</td></tr>
              </table>
            </div>

            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_verify_booking]</span>
              <p class="ab-sc-desc"><?php $sct(
                'Página de verificación/pago de una reserva puntual — a la que llegan los links de los emails (<code>?ref=...&amp;token=...</code>). No lleva atributos. El plugin ya crea y configura esta página automáticamente al instalarse; normalmente no hace falta agregarla a mano.',
                'Verification/payment page for a specific booking — where the links in emails point to (<code>?ref=...&amp;token=...</code>). Takes no attributes. The plugin already creates and sets up this page automatically on install; you normally don\'t need to add it by hand.'
              ); ?></p>
            </div>

            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_booking_variants tour_ids="12,13,14,15"]</span>
              <p class="ab-sc-desc"><?php $sct(
                'Un mismo producto vendido como N tours separados (ej. las 4 habitaciones de un retiro semanal, cada una su propio precio/capacidad) — muestra tarjetas para elegir entre las variantes y, al elegir una, monta el widget de reserva de ESE tour, sin salir de la página. Los tours listados suelen tener "🚫 Ocultar de listados" activado en su editor (no aparecen en catálogos normales, solo alcanzables desde este selector).',
                'A single product sold as N separate tours (e.g. the 4 rooms of a weekly retreat, each with its own price/capacity) — shows cards to pick between the variants, and mounts THAT tour\'s booking widget on pick, without leaving the page. The listed tours usually have "🚫 Hide from lists" enabled in their editor (they won\'t show up in normal catalogs, only reachable from this selector).'
              ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>tour_ids</th><td><?php $sct( 'Obligatorio. IDs de los tours-variante separados por coma (el ID interno del tour, no el de WordPress — se ve en TourFlow → Tours).', 'Required. Comma-separated variant tour IDs (the tour\'s internal ID, not the WordPress one — shown in TourFlow → Tours).' ); ?></td></tr>
                <tr><th>title_es / title_en</th><td><?php $sct( 'Opcional. Título arriba de las tarjetas. Por defecto "Elegí tu opción" / "Choose your option".', 'Optional. Title above the cards. Defaults to "Elegí tu opción" / "Choose your option".' ); ?></td></tr>
                <tr><th>columns</th><td><?php $sct( 'Opcional. Columnas de la grilla, 1 a 4. Por defecto <code>2</code>.', 'Optional. Grid columns, 1 to 4. Defaults to <code>2</code>.' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en', 'Optional, same as in' ); ?> <code>[flow_booking]</code>.</td></tr>
              </table>
            </div>

            <?php if ( AMIR_EDITION === 'pro_max' ) : ?>
            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_discovery mode="experience"]</span>
              <p class="ab-sc-desc"><?php $sct(
                '<strong>Flujo continuo de descubrimiento (Pro Max)</strong> — el que arma la reserva completa con upsell: <code>mode="experience"</code> (Flujo A: elegir tour → habitaciones sugeridas → extras → pago único) o <code>mode="room"</code> (Flujo B: elegir habitación → catálogo de experiencias cercanas → extras → pago único). El plugin ya crea y publica la página <strong>"Book Your Stay"</strong> (<code>/book/</code>) con este shortcode al activarse — normalmente no hace falta agregarlo a mano. Las fichas de tour y de habitación (Pro Max) también lo montan solas, con <code>tour_id</code>/<code>room_id</code> precargado.',
                '<strong>Continuous discovery flow (Pro Max)</strong> — the one that builds the full booking with upsell: <code>mode="experience"</code> (Flow A: pick a tour → suggested rooms → extras → single payment) or <code>mode="room"</code> (Flow B: pick a room → nearby experiences catalog → extras → single payment). The plugin already creates and publishes the <strong>"Book Your Stay"</strong> page (<code>/book/</code>) with this shortcode on activation — you normally don\'t need to add it by hand. Tour and room pages (Pro Max) also mount it on their own, with <code>tour_id</code>/<code>room_id</code> preloaded.'
              ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>mode</th><td><?php $sct( 'Opcional. <code>experience</code> (por defecto) o <code>room</code> — cuál de los dos flujos arranca.', 'Optional. <code>experience</code> (default) or <code>room</code> — which of the two flows starts.' ); ?></td></tr>
                <tr><th>tour_id</th><td><?php $sct( 'Opcional. Precarga un tour puntual y salta el paso de descubrimiento inicial (así lo usa la ficha de cada tour).', 'Optional. Preloads a specific tour and skips the initial discovery step (this is how each tour\'s page uses it).' ); ?></td></tr>
                <tr><th>room_id</th><td><?php $sct( 'Opcional. Igual que <code>tour_id</code> pero para una habitación puntual (así lo usa la ficha de cada habitación).', 'Optional. Same as <code>tour_id</code> but for a specific room (this is how each room\'s page uses it).' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional. Por defecto, inglés si no se detecta otro idioma (Pro Max es English-first) — a diferencia de <code>[flow_booking]</code>, que por defecto cae a español.', 'Optional. Defaults to English if no other language is detected (Pro Max is English-first) — unlike <code>[flow_booking]</code>, which defaults to Spanish.' ); ?></td></tr>
              </table>
            </div>
            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_room_list]</span>
              <p class="ab-sc-desc"><?php $sct( 'Grilla simple de habitaciones (Pro Max), sin pedir fechas primero — mismo rol que <code>[flow_tour_list]</code> pero para habitaciones. Cada tarjeta linkea a la ficha de esa habitación, que ya arranca el flujo continuo.', 'Simple room grid (Pro Max), without asking for dates first — same role as <code>[flow_tour_list]</code> but for rooms. Each card links to that room\'s page, which starts the continuous flow.' ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>columns</th><td><?php $sct( 'Opcional. Columnas de la grilla, 1 a 3. Por defecto <code>3</code>.', 'Optional. Grid columns, 1 to 3. Defaults to <code>3</code>.' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en <code>[flow_discovery]</code> (English-first por defecto).', 'Optional, same as in <code>[flow_discovery]</code> (English-first by default).' ); ?></td></tr>
              </table>
            </div>
            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_room_search]</span>
              <p class="ab-sc-desc"><?php $sct( 'Buscador de habitaciones por fecha (Pro Max) — solo habitaciones, sin upsell de tours ni extras. Fecha de check-in/check-out + huéspedes → tarjetas de habitaciones disponibles → carrito → pago. Para "arma tu propia reserva" con upsell, usá <code>[flow_discovery mode="room"]</code> en su lugar.', 'Room search by date (Pro Max) — rooms only, no tour or extras upsell. Check-in/check-out date + guests → available room cards → cart → payment. For a "build your own booking" with upsell, use <code>[flow_discovery mode="room"]</code> instead.' ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en', 'Optional, same as in' ); ?> <code>[flow_discovery]</code>.</td></tr>
              </table>
            </div>
            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_explore]</span>
              <p class="ab-sc-desc"><?php $sct(
                '<strong>Flujo "Explorar" (Pro Max)</strong> — segundo flujo de reserva, búsqueda-primero al estilo Booking.com: barra de búsqueda persistente (fechas + huéspedes), sin pasos fijos, filtros client-side sobre tours y habitaciones a la vez. Standalone (no recibe <code>tour_id</code>/<code>room_id</code>) y convive con <code>[flow_discovery]</code> sin reemplazarlo — pensado para un home o una landing donde el visitante todavía no eligió qué reservar. Puede recibir <code>?checkin=&amp;checkout=&amp;guests=</code> por URL (así lo usa <code>[flow_search_bar]</code>) para llegar con la búsqueda ya precargada.',
                '<strong>"Explore" flow (Pro Max)</strong> — second booking flow, search-first Booking.com style: persistent search bar (dates + guests), no fixed steps, client-side filters over tours and rooms at once. Standalone (doesn\'t receive <code>tour_id</code>/<code>room_id</code>) and coexists with <code>[flow_discovery]</code> without replacing it — meant for a home or landing page where the visitor hasn\'t chosen what to book yet. Can receive <code>?checkin=&amp;checkout=&amp;guests=</code> via URL (this is how <code>[flow_search_bar]</code> uses it) to arrive with the search already preloaded.'
              ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en <code>[flow_discovery]</code> (English-first por defecto).', 'Optional, same as in <code>[flow_discovery]</code> (English-first by default).' ); ?></td></tr>
              </table>
            </div>
            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_search_bar redirect_url="/explorar/"]</span>
              <p class="ab-sc-desc"><?php $sct(
                'Barra de búsqueda standalone (fechas + huéspedes) para insertar en el hero de un home o cualquier página — al enviarla, redirige a la página indicada agregando <code>?checkin=&amp;checkout=&amp;guests=</code> en la URL. Pensada para usarse junto a <code>[flow_explore]</code>: la barra vive en el home, <code>redirect_url</code> apunta a la página que tiene <code>[flow_explore]</code>, y ese flujo arranca ya con la búsqueda cargada.',
                'Standalone search bar (dates + guests) to insert in a home\'s hero or any page — submitting it redirects to the given page adding <code>?checkin=&amp;checkout=&amp;guests=</code> to the URL. Meant to be used together with <code>[flow_explore]</code>: the bar lives on the home, <code>redirect_url</code> points to the page with <code>[flow_explore]</code>, and that flow starts with the search already loaded.'
              ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>redirect_url</th><td><?php $sct( 'Obligatorio. La URL de la página que tiene <code>[flow_explore]</code> (relativa o absoluta).', 'Required. The URL of the page with <code>[flow_explore]</code> (relative or absolute).' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en <code>[flow_discovery]</code> (English-first por defecto).', 'Optional, same as in <code>[flow_discovery]</code> (English-first by default).' ); ?></td></tr>
              </table>
            </div>
            <div class="ab-sc-item">
              <span class="ab-sc-code">[flow_product addon_id="55"]</span>
              <p class="ab-sc-desc"><?php $sct(
                'Venta suelta de un producto digital (Pro Max, ej. una guía PDF) sin reservar ningún tour — pensado para incrustarse en una página de venta propia. El producto tiene que existir antes como Extra global de tipo "Digital" en TourFlow → 🎁 Extras globales. Al comprar, el cliente recibe el link de descarga por email.',
                'Standalone sale of a digital product (Pro Max, e.g. a PDF guide) without booking any tour — meant to be embedded in your own sales page. The product must already exist as a "Digital" global extra in TourFlow → 🎁 Global extras. On purchase, the customer gets the download link by email.'
              ); ?></p>
              <table class="ab-sc-atts">
                <tr><th>addon_id</th><td><?php $sct( 'Obligatorio. El ID del extra global digital (se ve en TourFlow → 🎁 Extras globales).', 'Required. The ID of the digital global extra (shown in TourFlow → 🎁 Global extras).' ); ?></td></tr>
                <tr><th>lang</th><td><?php $sct( 'Opcional, igual que en <code>[flow_discovery]</code> (English-first por defecto).', 'Optional, same as in <code>[flow_discovery]</code> (English-first by default).' ); ?></td></tr>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </details>

        <?php if ( ! empty( $special_requests ) ) : ?>
        <div class="ab-requests-bar">
          <div class="rtitle">📝 <?php printf( _n( '%d requerimiento especial a coordinar (hoy/mañana)', '%d requerimientos especiales a coordinar (hoy/mañana)', count( $special_requests ), 'amir-booking' ), count( $special_requests ) ); ?></div>
          <?php foreach ( $special_requests as $sr ) : ?>
            <div class="ab-request-item">
              <span class="rwho">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-bookings-list&action=view&id=' . $sr['id'] ) ); ?>"><?php echo esc_html( $sr['booking_ref'] ); ?></a>
                — <?php echo esc_html( $sr['customer_name'] ); ?>
                <span class="rwhen"><?php echo $sr['item_type'] === 'room' ? '🛏' : '⛵'; ?> <?php echo esc_html( $sr['label'] ); ?> · <?php echo esc_html( $sr['date'] ); ?></span>
              </span>
              <div class="rtext"><?php echo nl2br( esc_html( $sr['special_requests'] ) ); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ( ! empty($notifications) ) : ?>
        <div class="ab-notif-bar">
          <div class="notif-title">⚠ <?php printf( _n('%d alerta pendiente', '%d alertas pendientes', count($notifications), 'amir-booking'), count($notifications) ); ?></div>
          <?php foreach ( $notifications as $n ) :
            // Deep-link directo a la reserva/tour puntual en vez de mandar
            // siempre a la lista general — antes el operador tenía que
            // buscar a mano cuál reserva disparó la alerta (bug real
            // reportado 2026-08-09, mismo criterio que ya usaba la barra de
            // proveedores pendientes de acá arriba). $n->data es JSON con
            // booking_id (solicitud de fecha, cancelación) o tour_id+date
            // (alerta de mínimo de pasajeros, sin una reserva puntual).
            $ndata = json_decode( $n->data ?: '{}', true ) ?: [];
            if ( ! empty( $ndata['booking_id'] ) ) {
                $notif_url = admin_url( 'admin.php?page=amir-bookings-list&action=view&id=' . (int) $ndata['booking_id'] );
                $notif_cta = __( 'Ver reserva →', 'amir-booking' );
            } elseif ( ! empty( $ndata['tour_id'] ) ) {
                $notif_url = admin_url( 'admin.php?page=amir-bookings-list&tour_id=' . (int) $ndata['tour_id']
                    . ( ! empty( $ndata['date'] ) ? '&date_from=' . rawurlencode( $ndata['date'] ) . '&date_until=' . rawurlencode( $ndata['date'] ) : '' ) );
                $notif_cta = __( 'Ver reservas de ese tour →', 'amir-booking' );
            } else {
                $notif_url = admin_url( 'admin.php?page=amir-bookings-list' );
                $notif_cta = __( 'Ver reservas →', 'amir-booking' );
            }
            $notif_icon = $n->type === 'min_pax_alert' ? '👥' : ( $n->type === 'date_request' ? '📅' : '🔔' );
          ?>
            <div class="ab-notif-item">
              <?php echo $notif_icon; ?>
              <?php echo esc_html($n->message); ?>
              <a href="<?php echo esc_url( $notif_url ); ?>" style="color:#1D9E75;font-size:12px;"><?php echo esc_html( $notif_cta ); ?></a>
            </div>
          <?php endforeach; ?>
          <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('amir_mark_read'); ?>
            <input type="hidden" name="amir_action" value="mark_all_read" />
            <button type="submit" style="background:transparent;border:none;color:#92400e;font-size:12px;cursor:pointer;text-decoration:underline;padding:0;"><?php _e('Marcar todas como leídas', 'amir-booking'); ?></button>
          </form>
        </div>
        <?php endif; ?>

        <!-- Stats rápidas -->
        <div class="ab-stats-row">
          <div class="ab-stat-card green">
            <div class="label"><?php _e('Reservas hoy', 'amir-booking'); ?></div>
            <div class="value"><?php echo $today_data['total_bookings']; ?></div>
            <div class="sub"><?php echo esc_html( sprintf( __( '%d personas confirmadas', 'amir-booking' ), $today_data['total_pax'] ) ); ?></div>
          </div>
          <div class="ab-stat-card">
            <div class="label"><?php _e('Reservas mañana', 'amir-booking'); ?></div>
            <div class="value"><?php echo $tomorrow_data['total_bookings']; ?></div>
            <div class="sub"><?php echo esc_html( sprintf( __( '%d personas', 'amir-booking' ), $tomorrow_data['total_pax'] ) ); ?></div>
          </div>
          <div class="ab-stat-card">
            <div class="label"><?php _e('Ingresos este mes', 'amir-booking'); ?></div>
            <div class="value">$<?php echo number_format($stats['month_revenue'],0,'.',','); ?></div>
            <div class="sub"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?> · <?php echo esc_html( sprintf( __( '%d reservas', 'amir-booking' ), $stats['month_bookings'] ) ); ?></div>
          </div>
          <div class="ab-stat-card">
            <div class="label"><?php _e('Pendientes de pago', 'amir-booking'); ?></div>
            <div class="value"><?php echo $stats['pending_count']; ?></div>
            <div class="sub"><?php _e('reservas en estado pending', 'amir-booking'); ?></div>
          </div>
        </div>

        <!-- HOY -->
        <div class="ab-day-section">
          <div class="ab-day-header">
            <span class="ab-day-label"><?php _e('Hoy', 'amir-booking'); ?> — <?php echo date_i18n( _x( 'j \d\e F', 'formato de fecha corto, día+mes', 'amir-booking' ), strtotime($today) ); ?></span>
            <span class="ab-day-badge"><?php _e('HOY', 'amir-booking'); ?></span>
          </div>
          <?php $this->render_day_tours( $today_data['tours'] ); ?>
          <?php if ( $rooms_enabled ) : $this->render_day_rooms( $today_rooms ); endif; ?>
        </div>

        <!-- MAÑANA -->
        <div class="ab-day-section">
          <div class="ab-day-header">
            <span class="ab-day-label"><?php _e('Mañana', 'amir-booking'); ?> — <?php echo date_i18n( _x( 'j \d\e F', 'formato de fecha corto, día+mes', 'amir-booking' ), strtotime($tomorrow) ); ?></span>
            <span class="ab-day-badge tomorrow"><?php _e('MAÑANA', 'amir-booking'); ?></span>
          </div>
          <?php $this->render_day_tours( $tomorrow_data['tours'] ); ?>
          <?php if ( $rooms_enabled ) : $this->render_day_rooms( $tomorrow_rooms ); endif; ?>
        </div>

        <!-- Próximas reservas pendientes de confirmación -->
        <?php
        $cancellation_requests = $this->get_cancellation_requests();
        if ( ! empty($cancellation_requests) ) : ?>
        <div class="ab-section-title">🚫 <?php echo esc_html( sprintf( __( 'Solicitudes de cancelación (%d)', 'amir-booking' ), count($cancellation_requests) ) ); ?></div>
        <div class="ab-tour-block">
          <table class="ab-bookings-table">
            <thead><tr>
              <th><?php _e('Referencia', 'amir-booking'); ?></th><th>Tour</th><th><?php _e('Fecha', 'amir-booking'); ?></th><th><?php _e('Cliente', 'amir-booking'); ?></th><th><?php _e('Total', 'amir-booking'); ?></th><th><?php _e('Acciones', 'amir-booking'); ?></th>
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
                <td><?php echo \AmirBooking\Core\Currency::format((float)$b->total_mxn, 0); ?></td>
                <td>
                  <a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>"
                     style="color:#1D9E75;font-size:12px;font-weight:600;"><?php _e('Ver detalle →', 'amir-booking'); ?></a>
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

    /**
     * CSS de render_day_tours()/render_day_rooms() — separado del resto del
     * <style> de render() porque CalendarPage::render() reusa esos dos
     * métodos directamente (sin pasar por render()) y antes se quedaba sin
     * este bloque, mostrando la lista de reservas del día sin ningún estilo.
     */
    public static function day_list_styles(): void {
        ?>
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

        .ab-room-table th { font-size:11px; font-weight:700; color:#5a7068; text-transform:uppercase; letter-spacing:.4px; padding:8px 12px; text-align:left; border-bottom:1px solid #e1f5ee; }
        .ab-room-table td { font-size:13px; color:#1a2e24; padding:8px 12px; border-bottom:1px solid #f5f5f5; }
        .ab-room-subtitle { font-size:13px; font-weight:700; color:#1a2e24; padding:10px 16px 4px; }

        .ab-wa-btn { display:inline-flex; align-items:center; gap:5px; background:#25D366; color:#fff; border:none; border-radius:6px; padding:5px 10px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; }
        <?php
    }

    public function render_day_tours( array $tours ): void {
        if ( empty($tours) ) {
            echo '<div class="ab-day-empty">' . esc_html__( 'Sin tours programados para este día.', 'amir-booking' ) . '</div>';
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

                <div class="ab-tour-name">
                  <?php echo esc_html($tour['tour_name']); ?>
                  <?php if ( ! empty( $tour['is_provider'] ) ) : ?>
                    <span title="<?php esc_attr_e('Tour de proveedor externo (marketplace)', 'amir-booking'); ?>" style="display:inline-block;margin-left:6px;font-size:11px;font-weight:700;color:#BA7517;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1px 8px;vertical-align:middle;">🤝 <?php _e('Proveedor', 'amir-booking'); ?></span>
                  <?php endif; ?>
                </div>
                <div class="ab-tour-time">🕐 <?php echo esc_html($tour['time']); ?></div>

                <div class="ab-tour-pax-pill">
                  👥 <?php echo esc_html( sprintf( __( '%d personas', 'amir-booking' ), $tour['confirmed_pax'] ) ); ?>
                </div>
                <?php if ( $tour['max_capacity'] > 0 ) : ?>
                <div class="ab-tour-slots-pill <?php echo $slots_class; ?>">
                  <?php
                  if ( $tour['slots_remaining'] <= 0 ) esc_html_e( 'LLENO', 'amir-booking' );
                  elseif ( $tour['max_capacity'] > 0 ) echo esc_html( sprintf( __( '%d cupos libres', 'amir-booking' ), $tour['slots_remaining'] ) );
                  ?>
                </div>
                <?php endif; ?>

                <span style="color:#5a7068;font-size:18px;margin-left:4px;">▾</span>
              </div>

              <!-- Lista de reservas del tour -->
              <div style="display:<?php echo $tour['confirmed_pax'] > 0 ? 'block' : 'none'; ?>">
                <?php if ( empty($tour['bookings']) ) : ?>
                  <p style="padding:12px 16px;font-size:13px;color:#5a7068;margin:0;"><?php _e('Sin reservas confirmadas.', 'amir-booking'); ?></p>
                <?php else : ?>
                <table class="ab-bookings-table">
                  <thead><tr>
                    <th><?php _e('Reserva', 'amir-booking'); ?></th>
                    <th><?php _e('Cliente', 'amir-booking'); ?></th>
                    <th><?php _e('Personas', 'amir-booking'); ?></th>
                    <th><?php _e('Idioma', 'amir-booking'); ?></th>
                    <th><?php _e('Origen', 'amir-booking'); ?></th>
                    <th><?php _e('Contacto', 'amir-booking'); ?></th>
                    <th><?php _e('Notas', 'amir-booking'); ?></th>
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
                          <?php if ($b->adults)   : ?><span class="ab-pax-chip adult"><?php echo esc_html( sprintf( __( '%d adultos', 'amir-booking' ), $b->adults ) ); ?></span><?php endif; ?>
                          <?php if ($b->children) : ?><span class="ab-pax-chip child"><?php echo esc_html( sprintf( __( '%d niños', 'amir-booking' ), $b->children ) ); ?></span><?php endif; ?>
                          <?php if ($b->babies)   : ?><span class="ab-pax-chip baby"><?php echo esc_html( sprintf( __( '%d bebés', 'amir-booking' ), $b->babies ) ); ?></span><?php endif; ?>
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
                      <td style="max-width:220px;">
                        <?php if ($b->special_requests) : ?>
                          <div style="font-size:12px;color:#1a2e24;background:#eef4ff;border-radius:6px;padding:6px 8px;">
                            📝 <?php echo nl2br( esc_html( $b->special_requests ) ); ?>
                          </div>
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

    /**
     * Check-ins y check-outs de habitaciones del día (Pro Max, § 16
     * CONTRIBUTING.md) — a diferencia de los tours (un solo bloque por
     * horario), acá importan los dos eventos por separado: quién llega hoy
     * Y quién se va hoy, porque son coordinaciones operativas distintas
     * (preparar la habitación vs. liberarla).
     */
    public function render_day_rooms( array $rooms ): void {
        if ( empty( $rooms['arrivals'] ) && empty( $rooms['departures'] ) ) {
            return;
        }
        ?>
        <div class="ab-tour-block">
          <?php if ( ! empty( $rooms['arrivals'] ) ) : ?>
            <div class="ab-room-subtitle">🛬 Check-ins</div>
            <table class="ab-room-table">
              <thead><tr><th><?php _e('Reserva', 'amir-booking'); ?></th><th><?php _e('Habitación', 'amir-booking'); ?></th><th><?php _e('Cliente', 'amir-booking'); ?></th><th><?php _e('Huéspedes', 'amir-booking'); ?></th><th><?php _e('Contacto', 'amir-booking'); ?></th></tr></thead>
              <tbody>
              <?php foreach ( $rooms['arrivals'] as $b ) : ?>
                <tr>
                  <td><a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>" style="font-weight:700;color:#1D9E75;"><?php echo esc_html($b->booking_ref); ?></a></td>
                  <td><?php echo esc_html($b->room_name_es); ?></td>
                  <td><?php echo esc_html($b->customer_name); ?></td>
                  <td><?php echo (int) $b->adults; ?></td>
                  <td><?php if ($b->customer_phone) : ?><a class="ab-wa-btn" href="https://wa.me/<?php echo esc_attr(preg_replace('/[^0-9]/','',$b->customer_phone)); ?>">💬 WA</a><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
          <?php if ( ! empty( $rooms['departures'] ) ) : ?>
            <div class="ab-room-subtitle">🛫 Check-outs</div>
            <table class="ab-room-table">
              <thead><tr><th><?php _e('Reserva', 'amir-booking'); ?></th><th><?php _e('Habitación', 'amir-booking'); ?></th><th><?php _e('Cliente', 'amir-booking'); ?></th></tr></thead>
              <tbody>
              <?php foreach ( $rooms['departures'] as $b ) : ?>
                <tr>
                  <td><a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>" style="font-weight:700;color:#1D9E75;"><?php echo esc_html($b->booking_ref); ?></a></td>
                  <td><?php echo esc_html($b->room_name_es); ?></td>
                  <td><?php echo esc_html($b->customer_name); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <?php
    }

    // ── Queries ───────────────────────────────────────────────────────────

    public function get_day_summary( string $date ): array {
        global $wpdb;

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, t.name_es as tour_name, s.time_start, s.time_end,
                    t.max_capacity, t.gallery_images, t.provider_id
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             WHERE b.tour_date = %s
               AND b.status IN ('confirmed','cancellation_requested')
             ORDER BY s.time_start ASC, t.name_es ASC",
            $date
        ) ?? [] );

        // Agrupar por tour+horario. $bookings ya trae exactamente las filas
        // confirmadas de este día (mismo filtro de status) — sumar acá en
        // vez de repetir un SUM() por grupo (antes: 1 query extra por cada
        // combinación distinta de tour+horario del día).
        $tours_map = [];
        foreach ( $bookings as $b ) {
            $key = $b->tour_id . '_' . $b->schedule_id;
            if ( ! isset($tours_map[$key]) ) {
                // Obtener imagen del CPT
                $post = get_posts(['post_type'=>\AmirBooking\CPT\TourPostType::POST_TYPE,'meta_key'=>'_amir_tour_db_id','meta_value'=>$b->tour_id,'posts_per_page'=>1]);
                $thumb = $post ? get_the_post_thumbnail_url($post[0]->ID,'thumbnail') : '';

                $tours_map[$key] = [
                    'tour_id'         => $b->tour_id,
                    'schedule_id'     => $b->schedule_id,
                    'tour_name'       => $b->tour_name,
                    'time'            => $this->fmt_time($b->time_start) . ' – ' . $this->fmt_time($b->time_end),
                    'max_capacity'    => (int)$b->max_capacity,
                    'confirmed_pax'   => 0,
                    'slots_remaining' => 0,
                    'thumb'           => $thumb,
                    'is_provider'     => ! empty( $b->provider_id ),
                    'bookings'        => [],
                ];
            }
            $tours_map[$key]['confirmed_pax'] += (int)$b->adults + (int)$b->children + (int)$b->babies;
            $tours_map[$key]['bookings'][]     = $b;
        }
        foreach ( $tours_map as $key => $t ) {
            $tours_map[$key]['slots_remaining'] = max(0, $t['max_capacity'] - $t['confirmed_pax']);
        }

        $total_pax      = array_sum( array_column( $tours_map, 'confirmed_pax' ) );
        $total_bookings = count( $bookings );

        return [
            'tours'          => array_values($tours_map),
            'total_pax'      => $total_pax,
            'total_bookings' => $total_bookings,
        ];
    }

    /**
     * Público porque CalendarPage también lo necesita para el detalle de un
     * día cualquiera del mes, no solo hoy/mañana (mismo criterio que
     * get_day_summary()/render_day_tours() de arriba).
     * @return array{arrivals: object[], departures: object[]}
     */
    public function get_room_day_summary( string $date ): array {
        global $wpdb;

        $arrivals = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, r.name_es AS room_name_es
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
             WHERE b.item_type = 'room' AND b.tour_date = %s
               AND b.status IN ('confirmed','cancellation_requested')
             ORDER BY b.customer_name ASC",
            $date
        ) ) ?? [];

        $departures = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, r.name_es AS room_name_es
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
             WHERE b.item_type = 'room' AND b.check_out_date = %s
               AND b.status IN ('confirmed','cancellation_requested')
             ORDER BY b.customer_name ASC",
            $date
        ) ) ?? [];

        return [ 'arrivals' => $arrivals, 'departures' => $departures ];
    }

    /**
     * Requerimientos especiales de reservas (tours y habitaciones) de hoy y
     * mañana, para que el operador los vea de un vistazo sin entrar a cada
     * reserva — pedido explícito del cliente 2026-07-31. Solo confirmed
     * (pending todavía puede no llegar a pagarse, no vale la pena
     * coordinarlo todavía).
     */
    private function get_special_requests_today_tomorrow( string $today, string $tomorrow ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, booking_ref, customer_name, item_type, tour_date, check_out_date, special_requests
             FROM {$wpdb->prefix}amir_bookings
             WHERE status = 'confirmed'
               AND special_requests IS NOT NULL AND special_requests != ''
               AND ( tour_date IN (%s,%s) OR ( item_type = 'room' AND check_out_date IN (%s,%s) ) )
             ORDER BY tour_date ASC",
            $today, $tomorrow, $today, $tomorrow
        ) ) ?? [];

        return array_map( function ( $r ) use ( $today ) {
            $is_room = $r->item_type === 'room';
            return [
                'id'                => (int) $r->id,
                'booking_ref'       => $r->booking_ref,
                'customer_name'     => $r->customer_name,
                'item_type'         => $r->item_type,
                'label'             => $is_room ? __( 'Habitación', 'amir-booking' ) : 'Tour',
                'date'              => $r->tour_date === $today || ( $is_room && $r->check_out_date === $today ) ? __( 'hoy', 'amir-booking' ) : __( 'mañana', 'amir-booking' ),
                'special_requests'  => $r->special_requests,
            ];
        }, $rows );
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

    /**
     * Reservas de tours con proveedor externo (marketplace, § 11
     * CONTRIBUTING.md) esperando que el proveedor apruebe/rechace — sin esto
     * en el Dashboard quedaban "invisibles" salvo que alguien entrara a
     * Reservas y filtrara a mano por ese estado puntual. hours_left usa el
     * mismo amir_provider_response_hours que ya lee el cron de vencimiento
     * (class-cron-manager.php) para que el número coincida con cuándo se
     * cancela solo de verdad.
     */
    private function get_provider_pending_approvals(): array {
        if ( ! in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) || get_option( 'amir_module_marketplace', '1' ) !== '1' ) {
            return [];
        }
        global $wpdb;
        $response_hours = (int) get_option( 'amir_provider_response_hours', 48 );

        $rows = $wpdb->get_results(
            "SELECT b.id, b.booking_ref, b.customer_name, b.provider_notified_at,
                    p.business_name
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_providers p ON p.id = t.provider_id
             WHERE b.status = 'pending_provider_approval'
             ORDER BY b.provider_notified_at ASC"
        ) ?? [];

        $now = current_time( 'timestamp' );
        return array_map( function ( $r ) use ( $now, $response_hours ) {
            $notified_ts = $r->provider_notified_at ? strtotime( $r->provider_notified_at ) : $now;
            $deadline_ts = $notified_ts + ( $response_hours * HOUR_IN_SECONDS );
            return [
                'id'             => (int) $r->id,
                'booking_ref'    => $r->booking_ref,
                'customer_name'  => $r->customer_name,
                'business_name'  => $r->business_name ?: '—',
                'hours_left'     => max( 0, (int) ceil( ( $deadline_ts - $now ) / HOUR_IN_SECONDS ) ),
            ];
        }, $rows );
    }

    private function get_unread_notifications(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}amir_notifications
             WHERE is_read=0 ORDER BY created_at DESC LIMIT 10"
        ) ?? [];
    }

    /**
     * Nullable a propósito — bug real en producción (caliafarm.com,
     * 2026-08-04): una reserva con schedule_id sin fila correspondiente en
     * amir_tour_schedules (LEFT JOIN de get_day_summary()) trae time_start/
     * time_end NULL — con el tipo `string` estricto de antes, PHP 8
     * fataleaba el Dashboard entero con un TypeError en vez de solo
     * mostrar el horario vacío.
     */
    private function fmt_time( ?string $t ): string {
        if ( ! $t ) {
            return '—';
        }
        [$h,$m] = explode(':',$t);
        $h = (int)$h;
        return ($h>12?$h-12:($h?:12)).':'.$m.($h>=12?' PM':' AM');
    }
}
