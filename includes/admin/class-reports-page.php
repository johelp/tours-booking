<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de reportes.
 * Ingresos por periodo, por tour y por origen.
 * Exportable a CSV.
 */
class ReportsPage {

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    public function render(): void {
        // Defensa en profundidad — el menú ya gatea el acceso, pero esta
        // pantalla exporta PII (nombre/email/teléfono) y no debe depender
        // solo de eso.
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
        }

        // Bug real reportado por el cliente (2026-08-24): el CSV se mostraba
        // en pantalla en vez de descargarse — llamar a export_csv() ACÁ es
        // tarde, WordPress ya mandó las cabeceras HTTP + el HTML del admin
        // (menú, header) antes de invocar el callback de render() de esta
        // página, así que header('Content-Type: text/csv...') fallaba en
        // silencio y el navegador mostraba el texto como si fuera HTML.
        // Corregido moviendo el disparo a AdminMenu::maybe_export_reports_csv()
        // (hook admin_init, corre ANTES de cualquier HTML) — mismo patrón
        // que ya usa maybe_stream_pdf() para el voucher. export_csv() pasa
        // a public para que AdminMenu pueda llamarlo desde ahí.

        $from  = sanitize_text_field( $_GET['from']  ?? date('Y-m-01') );
        $until = sanitize_text_field( $_GET['until'] ?? date('Y-m-t')  );

        $summary = $this->get_summary( $from, $until );
        $by_tour = $this->get_by_tour( $from, $until );
        $by_room = AMIR_EDITION === 'pro_max' ? $this->get_by_room( $from, $until ) : [];
        $by_source = $this->get_by_source( $from, $until );
        $by_month  = $this->get_by_month();
        ?>
        <div class="wrap ab-admin-wrap">
        <style>
        .ab-admin-wrap{max-width:1100px}
        .ab-rep-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
        .ab-rep-card{background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:16px 18px}
        .ab-rep-card .label{font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px}
        .ab-rep-card .value{font-size:26px;font-weight:800;color:#1a2e24;margin:4px 0 2px}
        .ab-rep-card .sub{font-size:12px;color:#5a7068}
        .ab-rep-card.green{border-top:3px solid #1D9E75;background:#f0faf6}
        .ab-section{background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:18px 20px;margin-bottom:20px}
        .ab-section h3{font-size:14px;font-weight:700;color:#1D9E75;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #e1f5ee}
        .ab-table{width:100%;border-collapse:collapse}
        .ab-table th{font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;padding:8px 10px;text-align:left;border-bottom:1px solid #e1f5ee;background:#f8fdfb}
        .ab-table td{font-size:13px;padding:9px 10px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
        .ab-table tr:last-child td{border-bottom:none}
        .ab-table tr:hover td{background:#f8fdfb}
        .ab-bar{height:8px;background:#e1f5ee;border-radius:4px;overflow:hidden}
        .ab-bar-fill{height:100%;background:#1D9E75;border-radius:4px}
        </style>

        <h1 style="display:flex;align-items:center;justify-content:space-between;">
          <span>📊 <?php echo esc_html( $this->tt( 'Reportes', 'Reports' ) ); ?></span>
          <a href="<?php echo esc_url(add_query_arg(['export'=>'csv','from'=>$from,'until'=>$until])); ?>"
             class="button">⬇ <?php echo esc_html( $this->tt( 'Exportar CSV', 'Export CSV' ) ); ?></a>
        </h1>

        <!-- Filtro de fechas -->
        <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:24px;background:#f8fdfb;padding:14px 16px;border-radius:10px;border:1px solid #e1f5ee;">
          <input type="hidden" name="page" value="amir-reports" />
          <label style="font-size:13px;font-weight:600;"><?php echo esc_html( $this->tt( 'Período:', 'Period:' ) ); ?></label>
          <input type="date" name="from"  value="<?php echo esc_attr($from); ?>"  style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 10px;font-size:13px;" />
          <span style="color:#5a7068;">—</span>
          <input type="date" name="until" value="<?php echo esc_attr($until); ?>" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 10px;font-size:13px;" />
          <button type="submit" class="button button-primary"><?php echo esc_html( $this->tt( 'Ver', 'View' ) ); ?></button>
          <?php foreach ([
            [ $this->tt('Este mes','This month'), date('Y-m-01'), date('Y-m-t') ],
            [ $this->tt('Mes anterior','Last month'), date('Y-m-01',strtotime('first day of last month')), date('Y-m-t',strtotime('last day of last month')) ],
            [ $this->tt('Últimos 3 meses','Last 3 months'), date('Y-m-01',strtotime('-2 months')), date('Y-m-t') ],
            [ $this->tt('Este año','This year'), date('Y-01-01'), date('Y-12-31') ],
          ] as [$label,$f,$u]) : ?>
            <a href="<?php echo esc_url(admin_url("admin.php?page=amir-reports&from={$f}&until={$u}")); ?>"
               style="font-size:12px;color:#1D9E75;text-decoration:none;padding:6px 10px;border-radius:6px;
                      background:<?php echo ($from===$f&&$until===$u)?'#e1f5ee':'transparent'; ?>;">
              <?php echo esc_html( $label ); ?>
            </a>
          <?php endforeach; ?>
        </form>

        <!-- Manifiesto de pasajeros en PDF (pedido del cliente 2026-08-24) —
             mismo período que arriba, opcionalmente filtrado a un solo tour.
             El link dispara AdminMenu::maybe_export_manifest_pdf() (hook
             admin_init, corre antes de cualquier HTML) — mismo criterio que
             el CSV de arriba, para no repetir el bug de "se muestra en
             pantalla en vez de descargar". -->
        <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:24px;background:#fff8e7;padding:14px 16px;border-radius:10px;border:1px solid #fde68a;">
          <input type="hidden" name="page" value="amir-reports" />
          <input type="hidden" name="export" value="manifest" />
          <input type="hidden" name="from" value="<?php echo esc_attr($from); ?>" />
          <input type="hidden" name="until" value="<?php echo esc_attr($until); ?>" />
          <label style="font-size:13px;font-weight:600;">🪪 <?php echo esc_html( $this->tt( 'Manifiesto de pasajeros:', 'Passenger manifest:' ) ); ?></label>
          <select name="tour_id" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 10px;font-size:13px;">
            <option value="0"><?php echo esc_html( $this->tt( '— Todos los tours del período —', '— All tours in the period —' ) ); ?></option>
            <?php foreach ( $this->get_tours_for_manifest() as $tour_opt ) : ?>
              <option value="<?php echo (int) $tour_opt->id; ?>"><?php echo esc_html( $tour_opt->name_es ); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="button">⬇ PDF</button>
          <span style="font-size:12px;color:#78350f;"><?php echo esc_html( $this->tt( 'Usa el período de arriba — cambialo y volvé a hacer clic si necesitás otro rango.', 'Uses the period above — change it and click again for a different range.' ) ); ?></span>
        </form>

        <!-- KPIs -->
        <div class="ab-rep-grid">
          <div class="ab-rep-card green">
            <div class="label"><?php echo esc_html( $this->tt( 'Ingresos', 'Revenue' ) ); ?></div>
            <div class="value">$<?php echo number_format($summary['revenue'],0,'.',','); ?></div>
            <div class="sub"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?> <?php echo esc_html( $this->tt( 'en el período', 'in the period' ) ); ?></div>
          </div>
          <div class="ab-rep-card">
            <div class="label"><?php echo esc_html( $this->tt( 'Reservas confirmadas', 'Confirmed bookings' ) ); ?></div>
            <div class="value"><?php echo $summary['bookings']; ?></div>
            <div class="sub"><?php echo esc_html( $this->tt( 'Completadas + activas', 'Completed + active' ) ); ?></div>
          </div>
          <div class="ab-rep-card">
            <div class="label"><?php echo esc_html( $this->tt( 'Personas totales', 'Total people' ) ); ?></div>
            <div class="value"><?php echo $summary['pax']; ?></div>
            <div class="sub"><?php echo esc_html( $this->tt( 'Adultos + niños + bebés', 'Adults + children + babies' ) ); ?></div>
          </div>
          <div class="ab-rep-card">
            <div class="label"><?php echo esc_html( $this->tt( 'Ticket promedio', 'Average ticket' ) ); ?></div>
            <div class="value">$<?php echo $summary['bookings'] > 0 ? number_format($summary['revenue']/$summary['bookings'],0,'.',',') : '0'; ?></div>
            <div class="sub"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?> <?php echo esc_html( $this->tt( 'por reserva', 'per booking' ) ); ?></div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

          <!-- Por tour -->
          <div class="ab-section">
            <h3><?php echo esc_html( $this->tt( 'Ingresos por tour', 'Revenue by tour' ) ); ?></h3>
            <?php if (empty($by_tour)) : ?>
              <p style="color:#5a7068;font-size:13px;"><?php echo esc_html( $this->tt( 'Sin datos para el período.', 'No data for this period.' ) ); ?></p>
            <?php else :
              $max_rev = max(array_column($by_tour,'revenue')) ?: 1;
              foreach ($by_tour as $row) :
                $pct = round(($row['revenue']/$max_rev)*100);
            ?>
              <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                  <span style="font-weight:600;color:#1a2e24;"><?php echo esc_html($row['tour_name']); ?></span>
                  <span style="color:#1D9E75;font-weight:700;">$<?php echo number_format($row['revenue'],0,'.',','); ?> <span style="font-weight:400;color:#5a7068;">(<?php echo esc_html( sprintf( $this->tt( '%d res.', '%d bkg.' ), $row['bookings'] ) ); ?>)</span></span>
                </div>
                <div class="ab-bar"><div class="ab-bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <!-- Por habitación (Pro Max, § 16 CONTRIBUTING.md) — get_by_tour()
               de arriba hace INNER JOIN con amir_tours a propósito (es un
               reporte específico de tours), así que el ingreso de
               habitaciones no aparece ahí. Esta tabla lo muestra aparte para
               que "por tour" + "por habitación" reconcilien con el KPI
               "Ingresos" de arriba (que sí incluye todo, sin JOIN). -->
          <?php if ( AMIR_EDITION === 'pro_max' ) : ?>
          <div class="ab-section">
            <h3>🛏 <?php echo esc_html( $this->tt( 'Ingresos por habitación', 'Revenue by room' ) ); ?></h3>
            <?php if (empty($by_room)) : ?>
              <p style="color:#5a7068;font-size:13px;"><?php echo esc_html( $this->tt( 'Sin datos para el período.', 'No data for this period.' ) ); ?></p>
            <?php else :
              $max_rev_room = max(array_column($by_room,'revenue')) ?: 1;
              foreach ($by_room as $row) :
                $pct = round(($row['revenue']/$max_rev_room)*100);
            ?>
              <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                  <span style="font-weight:600;color:#1a2e24;"><?php echo esc_html($row['room_name']); ?></span>
                  <span style="color:#1D9E75;font-weight:700;">$<?php echo number_format($row['revenue'],0,'.',','); ?> <span style="font-weight:400;color:#5a7068;">(<?php echo esc_html( sprintf( $this->tt( '%d res.', '%d bkg.' ), $row['bookings'] ) ); ?>)</span></span>
                </div>
                <div class="ab-bar"><div class="ab-bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <?php endif; ?>

          <!-- Por origen -->
          <div class="ab-section">
            <h3><?php echo esc_html( $this->tt( 'Ingresos por origen', 'Revenue by source' ) ); ?></h3>
            <table class="ab-table">
              <thead><tr>
                <th><?php echo esc_html( $this->tt( 'Origen', 'Source' ) ); ?></th><th><?php echo esc_html( $this->tt( 'Reservas', 'Bookings' ) ); ?></th><th><?php echo esc_html( $this->tt( 'Ingresos', 'Revenue' ) ); ?> <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></th><th>%</th>
              </tr></thead>
              <tbody>
              <?php
              $total_rev = $summary['revenue'] ?: 1;
              foreach ($by_source as $row) :
                $pct = round(($row['revenue']/$total_rev)*100);
              ?>
                <tr>
                  <td><?php
                    $badges = [
                      'direct'=>'🌐 ' . $this->tt('Directo','Direct'),
                      'partner'=>'🤝 Partner',
                      'tripadvisor'=>'⭐ TripAdvisor',
                      'getyourguide'=>'🔖 GetYourGuide',
                    ];
                    echo esc_html( $badges[$row['source']] ?? $row['source'] );
                  ?></td>
                  <td><?php echo $row['bookings']; ?></td>
                  <td style="font-weight:700;">$<?php echo number_format($row['revenue'],0,'.',','); ?></td>
                  <td><?php echo $pct; ?>%</td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>

        <!-- Por mes (últimos 12 meses) -->
        <div class="ab-section">
          <h3><?php echo esc_html( $this->tt( 'Evolución mensual — últimos 12 meses', 'Monthly trend — last 12 months' ) ); ?></h3>
          <table class="ab-table">
            <thead><tr>
              <th><?php echo esc_html( $this->tt( 'Mes', 'Month' ) ); ?></th><th><?php echo esc_html( $this->tt( 'Reservas', 'Bookings' ) ); ?></th><th><?php echo esc_html( $this->tt( 'Personas', 'People' ) ); ?></th><th><?php echo esc_html( $this->tt( 'Ingresos', 'Revenue' ) ); ?> <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></th><th><?php echo esc_html( $this->tt( 'Ticket promedio', 'Average ticket' ) ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($by_month as $row) : ?>
              <tr>
                <td style="font-weight:600;"><?php echo esc_html($row['month_label']); ?></td>
                <td><?php echo $row['bookings']; ?></td>
                <td><?php echo $row['pax']; ?></td>
                <td style="font-weight:700;color:#1D9E75;">$<?php echo number_format($row['revenue'],0,'.',','); ?></td>
                <td style="color:#5a7068;">$<?php echo $row['bookings']>0?number_format($row['revenue']/$row['bookings'],0,'.',','):'—'; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        </div><!-- .wrap -->
        <?php
    }

    // ── Queries ───────────────────────────────────────────────────────────

    /** Lista simple para el <select> del manifiesto — solo id+nombre, no hace falta más. */
    private function get_tours_for_manifest(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}amir_tours WHERE status = 'active' ORDER BY sort_order, name_es"
        ) ?? [];
    }

    private function get_summary( string $from, string $until ): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
               COALESCE(SUM(total_mxn),0)                    AS revenue,
               COUNT(*)                                       AS bookings,
               COALESCE(SUM(adults+children+babies),0)       AS pax
             FROM {$wpdb->prefix}amir_bookings
             WHERE status IN ('confirmed','completed')
               AND tour_date BETWEEN %s AND %s",
            $from, $until
        ) );
        return [
            'revenue'  => (float) ($row->revenue  ?? 0),
            'bookings' => (int)   ($row->bookings  ?? 0),
            'pax'      => (int)   ($row->pax       ?? 0),
        ];
    }

    private function get_by_tour( string $from, string $until ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.name_es AS tour_name,
                    COUNT(*) AS bookings,
                    COALESCE(SUM(b.total_mxn),0) AS revenue,
                    COALESCE(SUM(b.adults+b.children+b.babies),0) AS pax
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             WHERE b.status IN ('confirmed','completed')
               AND b.tour_date BETWEEN %s AND %s
             GROUP BY b.tour_id
             ORDER BY revenue DESC",
            $from, $until
        ) ) ?? [];
        return array_map( fn($r) => [
            'tour_name' => $r->tour_name,
            'bookings'  => (int)$r->bookings,
            'revenue'   => (float)$r->revenue,
            'pax'       => (int)$r->pax,
        ], $rows );
    }

    /** Espejo de get_by_tour() para habitaciones (Pro Max) — ver comentario en render(). */
    private function get_by_room( string $from, string $until ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.name_es AS room_name,
                    COUNT(*) AS bookings,
                    COALESCE(SUM(b.total_mxn),0) AS revenue
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
             WHERE b.item_type = 'room'
               AND b.status IN ('confirmed','completed')
               AND b.tour_date BETWEEN %s AND %s
             GROUP BY b.room_id
             ORDER BY revenue DESC",
            $from, $until
        ) ) ?? [];
        return array_map( fn($r) => [
            'room_name' => $r->room_name,
            'bookings'  => (int)$r->bookings,
            'revenue'   => (float)$r->revenue,
        ], $rows );
    }

    private function get_by_source( string $from, string $until ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_source AS source,
                    COUNT(*) AS bookings,
                    COALESCE(SUM(total_mxn),0) AS revenue
             FROM {$wpdb->prefix}amir_bookings
             WHERE status IN ('confirmed','completed')
               AND tour_date BETWEEN %s AND %s
             GROUP BY booking_source
             ORDER BY revenue DESC",
            $from, $until
        ) ) ?? [];
        return array_map( fn($r) => [
            'source'   => $r->source,
            'bookings' => (int)$r->bookings,
            'revenue'  => (float)$r->revenue,
        ], $rows );
    }

    private function get_by_month(): array {
        global $wpdb;
        $months_es = $this->lang() === 'en'
            ? ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
            : ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        $rows = $wpdb->get_results(
            "SELECT DATE_FORMAT(tour_date,'%Y-%m') AS ym,
                    MONTH(tour_date)               AS month_num,
                    YEAR(tour_date)                AS year_num,
                    COUNT(*)                       AS bookings,
                    COALESCE(SUM(total_mxn),0)    AS revenue,
                    COALESCE(SUM(adults+children+babies),0) AS pax
             FROM {$wpdb->prefix}amir_bookings
             WHERE status IN ('confirmed','completed')
               AND tour_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY ym
             ORDER BY ym DESC"
        ) ?? [];
        return array_map( fn($r) => [
            'month_label' => ($months_es[(int)$r->month_num] ?? $r->month_num) . ' ' . $r->year_num,
            'bookings'    => (int)$r->bookings,
            'revenue'     => (float)$r->revenue,
            'pax'         => (int)$r->pax,
        ], $rows );
    }

    // ── CSV export ────────────────────────────────────────────────────────

    /** Pública para que AdminMenu::maybe_export_reports_csv() (admin_init) pueda llamarla — ver comentario en render(). */
    public function export_csv(): void {
        $from  = sanitize_text_field( $_GET['from']  ?? date('Y-m-01') );
        $until = sanitize_text_field( $_GET['until'] ?? date('Y-m-t') );

        global $wpdb;
        // LEFT JOIN (no JOIN) en amir_tours/flow_rooms — antes esto era un
        // INNER JOIN con amir_tours, así que el export financiero excluía en
        // silencio TODAS las reservas de habitación confirmadas (§ 16
        // CONTRIBUTING.md, cierre de gaps 2026-08-01). Un reporte de
        // ingresos que omite ingresos reales sin avisar es el peor tipo de
        // bug de reporting — se corrige acá.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.booking_ref, b.item_type, t.name_es as tour_name, r.name_es as room_name,
                    b.tour_date, b.check_out_date, s.time_start,
                    b.status, b.booking_source, b.customer_name, b.customer_email,
                    b.adults, b.children, b.babies, b.total_mxn, b.usd_reference,
                    b.created_at, p.name as partner_name
             FROM {$wpdb->prefix}amir_bookings b
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             LEFT JOIN {$wpdb->prefix}amir_partners p ON p.id = b.partner_id
             WHERE b.status IN ('confirmed','completed')
               AND b.tour_date BETWEEN %s AND %s
             ORDER BY b.tour_date ASC, s.time_start ASC",
            $from, $until
        ) ) ?? [];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reporte-amir-'.$from.'-'.$until.'.csv"');
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, [
            $this->tt('Referencia','Reference'), $this->tt('Tour/Habitación','Tour/Room'), $this->tt('Fecha','Date'),
            $this->tt('Fecha checkout (si aplica)','Checkout date (if applicable)'), $this->tt('Hora','Time'),
            $this->tt('Estado','Status'), $this->tt('Origen','Source'), 'Partner',
            $this->tt('Cliente','Customer'), 'Email', $this->tt('Adultos','Adults'), $this->tt('Niños','Children'),
            $this->tt('Bebés','Babies'), $this->tt('Total','Total') . ' ' . \AmirBooking\Core\Currency::code(),
            $this->tt('Ref. USD','USD ref.'), $this->tt('Reservado el','Booked on'),
        ]);

        foreach ($rows as $r) {
            $item_label = $r->item_type === 'room' ? ('🛏 ' . $r->room_name) : $r->tour_name;
            fputcsv($out, [
                $r->booking_ref, $item_label, $r->tour_date,
                $r->item_type === 'room' ? $r->check_out_date : '',
                $r->time_start,
                $r->status, $r->booking_source, $r->partner_name ?? '',
                $this->csv_safe($r->customer_name), $this->csv_safe($r->customer_email),
                $r->adults, $r->children, $r->babies,
                $r->total_mxn, $r->usd_reference ?? '',
                $r->created_at,
            ]);
        }
        fclose($out);
        exit;
    }

    /** Ver AmirBooking\Admin\BookingsPage::csv_safe() — misma protección contra inyección de fórmulas CSV. */
    private function csv_safe( $value ): string {
        $value = (string) $value;
        if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
            return "'" . $value;
        }
        return $value;
    }
}
