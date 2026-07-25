<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de reportes.
 * Ingresos por periodo, por tour y por origen.
 * Exportable a CSV.
 */
class ReportsPage {

    public function render(): void {
        if ( ( $_GET['export'] ?? '' ) === 'csv' ) {
            $this->export_csv();
        }

        $from  = sanitize_text_field( $_GET['from']  ?? date('Y-m-01') );
        $until = sanitize_text_field( $_GET['until'] ?? date('Y-m-t')  );

        $summary = $this->get_summary( $from, $until );
        $by_tour = $this->get_by_tour( $from, $until );
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
          <span>📊 Reportes</span>
          <a href="<?php echo esc_url(add_query_arg(['export'=>'csv','from'=>$from,'until'=>$until])); ?>"
             class="button">⬇ Exportar CSV</a>
        </h1>

        <!-- Filtro de fechas -->
        <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:24px;background:#f8fdfb;padding:14px 16px;border-radius:10px;border:1px solid #e1f5ee;">
          <input type="hidden" name="page" value="amir-reports" />
          <label style="font-size:13px;font-weight:600;">Período:</label>
          <input type="date" name="from"  value="<?php echo esc_attr($from); ?>"  style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 10px;font-size:13px;" />
          <span style="color:#5a7068;">—</span>
          <input type="date" name="until" value="<?php echo esc_attr($until); ?>" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 10px;font-size:13px;" />
          <button type="submit" class="button button-primary">Ver</button>
          <?php foreach ([
            ['Este mes',       date('Y-m-01'), date('Y-m-t')],
            ['Mes anterior',   date('Y-m-01',strtotime('first day of last month')), date('Y-m-t',strtotime('last day of last month'))],
            ['Últimos 3 meses',date('Y-m-01',strtotime('-2 months')), date('Y-m-t')],
            ['Este año',       date('Y-01-01'), date('Y-12-31')],
          ] as [$label,$f,$u]) : ?>
            <a href="<?php echo esc_url(admin_url("admin.php?page=amir-reports&from={$f}&until={$u}")); ?>"
               style="font-size:12px;color:#1D9E75;text-decoration:none;padding:6px 10px;border-radius:6px;
                      background:<?php echo ($from===$f&&$until===$u)?'#e1f5ee':'transparent'; ?>;">
              <?php echo $label; ?>
            </a>
          <?php endforeach; ?>
        </form>

        <!-- KPIs -->
        <div class="ab-rep-grid">
          <div class="ab-rep-card green">
            <div class="label">Ingresos</div>
            <div class="value">$<?php echo number_format($summary['revenue'],0,'.',','); ?></div>
            <div class="sub"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?> en el período</div>
          </div>
          <div class="ab-rep-card">
            <div class="label">Reservas confirmadas</div>
            <div class="value"><?php echo $summary['bookings']; ?></div>
            <div class="sub">Completadas + activas</div>
          </div>
          <div class="ab-rep-card">
            <div class="label">Personas totales</div>
            <div class="value"><?php echo $summary['pax']; ?></div>
            <div class="sub">Adultos + niños + bebés</div>
          </div>
          <div class="ab-rep-card">
            <div class="label">Ticket promedio</div>
            <div class="value">$<?php echo $summary['bookings'] > 0 ? number_format($summary['revenue']/$summary['bookings'],0,'.',',') : '0'; ?></div>
            <div class="sub"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?> por reserva</div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

          <!-- Por tour -->
          <div class="ab-section">
            <h3>Ingresos por tour</h3>
            <?php if (empty($by_tour)) : ?>
              <p style="color:#5a7068;font-size:13px;">Sin datos para el período.</p>
            <?php else :
              $max_rev = max(array_column($by_tour,'revenue')) ?: 1;
              foreach ($by_tour as $row) :
                $pct = round(($row['revenue']/$max_rev)*100);
            ?>
              <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                  <span style="font-weight:600;color:#1a2e24;"><?php echo esc_html($row['tour_name']); ?></span>
                  <span style="color:#1D9E75;font-weight:700;">$<?php echo number_format($row['revenue'],0,'.',','); ?> <span style="font-weight:400;color:#5a7068;">(<?php echo $row['bookings']; ?> res.)</span></span>
                </div>
                <div class="ab-bar"><div class="ab-bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <!-- Por origen -->
          <div class="ab-section">
            <h3>Ingresos por origen</h3>
            <table class="ab-table">
              <thead><tr>
                <th>Origen</th><th>Reservas</th><th>Ingresos <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></th><th>%</th>
              </tr></thead>
              <tbody>
              <?php
              $total_rev = $summary['revenue'] ?: 1;
              foreach ($by_source as $row) :
                $pct = round(($row['revenue']/$total_rev)*100);
              ?>
                <tr>
                  <td><?php
                    $badges = ['direct'=>'🌐 Directo','partner'=>'🤝 Partner','tripadvisor'=>'⭐ TripAdvisor','getyourguide'=>'🔖 GetYourGuide'];
                    echo $badges[$row['source']] ?? esc_html($row['source']);
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
          <h3>Evolución mensual — últimos 12 meses</h3>
          <table class="ab-table">
            <thead><tr>
              <th>Mes</th><th>Reservas</th><th>Personas</th><th>Ingresos <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></th><th>Ticket promedio</th>
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
        $months_es = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
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

    private function export_csv(): void {
        $from  = sanitize_text_field( $_GET['from']  ?? date('Y-m-01') );
        $until = sanitize_text_field( $_GET['until'] ?? date('Y-m-t') );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.booking_ref, t.name_es as tour_name, b.tour_date, s.time_start,
                    b.status, b.booking_source, b.customer_name, b.customer_email,
                    b.adults, b.children, b.babies, b.total_mxn, b.usd_reference,
                    b.created_at, p.name as partner_name
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
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

        fputcsv($out, ['Referencia','Tour','Fecha','Hora','Estado','Origen','Partner',
                       'Cliente','Email','Adultos','Niños','Bebés','Total ' . \AmirBooking\Core\Currency::code(), 'Ref. USD','Reservado el']);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r->booking_ref, $r->tour_name, $r->tour_date, $r->time_start,
                $r->status, $r->booking_source, $r->partner_name ?? '',
                $r->customer_name, $r->customer_email,
                $r->adults, $r->children, $r->babies,
                $r->total_mxn, $r->usd_reference ?? '',
                $r->created_at,
            ]);
        }
        fclose($out);
        exit;
    }
}
