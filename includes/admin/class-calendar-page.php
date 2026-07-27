<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Calendario mensual — pantalla completa, sin el chrome de WordPress
 * (ver AdminMenu::maybe_hide_admin_bar_for_calendar()/maybe_print_fullscreen_css()).
 * Pensado para tablet desde el muelle, igual que DashboardPage.
 *
 * Como el chrome de WP (admin bar + menú lateral) queda oculto, esta pantalla
 * necesita su propia barra de navegación de vuelta al resto del plugin —
 * antes no la tenía y dejaba al usuario sin forma de salir salvo "atrás"
 * del navegador.
 *
 * El detalle de un día reusa DashboardPage::get_day_summary()/render_day_tours()
 * tal cual — esos métodos ya funcionan para cualquier fecha, no solo hoy/mañana.
 */
class CalendarPage {

    /**
     * Mismos destinos que el submenú de AdminMenu::add_menus(), para que la
     * navegación dentro de la pantalla fullscreen no quede huérfana.
     */
    private function nav_links(): array {
        return [
            [ 'amir-booking',       '🏠 Dashboard'       ],
            [ 'amir-calendar',      '📅 Calendario'      ],
            [ 'amir-bookings-list', '📋 Reservas'        ],
            [ 'amir-availability',  '🗓️ Disponibilidad'  ],
            [ 'amir-partners',      '🤝 Partners'         ],
            [ 'amir-wishlist',      '💌 Lista de interés' ],
            [ 'amir-settings',      '⚙️ Configuración'   ],
        ];
    }

    public function render(): void {
        $month_param = sanitize_text_field( $_GET['month'] ?? '' );
        $month_ts    = $month_param && preg_match( '/^\d{4}-\d{2}$/', $month_param )
            ? strtotime( $month_param . '-01' )
            : strtotime( 'first day of this month' );

        $day_param = sanitize_text_field( $_GET['day'] ?? '' );
        $day       = $day_param && strtotime( $day_param ) ? $day_param : '';

        $year  = (int) date( 'Y', $month_ts );
        $month = (int) date( 'n', $month_ts );

        $counts = $this->get_month_counts( $year, $month );

        ?>
        <style>
        .amir-cal-topnav { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; background: #1a2e24; padding: 10px 24px; position: sticky; top: 0; z-index: 100; }
        .amir-cal-topnav a { color: #cfe9df; text-decoration: none; font-size: 13px; font-weight: 600; padding: 6px 12px; border-radius: 7px; white-space: nowrap; }
        .amir-cal-topnav a:hover { background: rgba(255,255,255,.08); color: #fff; }
        .amir-cal-topnav a.is-current { background: #1D9E75; color: #fff; }
        .amir-cal-wrap { max-width: 1100px; margin: 0 auto; padding: 28px 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .amir-cal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
        .amir-cal-title { font-size: 22px; font-weight: 700; color: #1a2e24; text-transform: capitalize; }
        .amir-cal-nav a { display: inline-block; padding: 8px 16px; border: 1px solid #d1e8df; border-radius: 8px; color: #1D9E75; text-decoration: none; font-weight: 600; font-size: 13px; margin-left: 8px; }
        .amir-cal-nav a:hover { background: #f0faf6; }
        .amir-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
        .amir-cal-dow { text-align: center; font-size: 11px; font-weight: 700; color: #5a7068; text-transform: uppercase; letter-spacing: .4px; padding-bottom: 6px; }
        .amir-cal-cell { min-height: 76px; border-radius: 10px; padding: 8px 10px; text-decoration: none; display: block; border: 1px solid #e1f5ee; background: #fff; }
        .amir-cal-cell.empty { border: none; background: transparent; }
        .amir-cal-cell.has-bookings { background: #f0faf6; border-color: #1D9E75; }
        .amir-cal-cell.is-selected { outline: 2px solid #1D9E75; outline-offset: -2px; }
        .amir-cal-daynum { font-size: 13px; font-weight: 700; color: #1a2e24; }
        .amir-cal-cell.today .amir-cal-daynum { color: #1D9E75; }
        .amir-cal-badge { margin-top: 6px; display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px; background: #1D9E75; color: #fff; }
        .amir-cal-detail { margin-top: 28px; border-top: 1px solid #e1f5ee; padding-top: 20px; }
        .amir-cal-detail-title { font-size: 16px; font-weight: 700; color: #1a2e24; margin-bottom: 14px; text-transform: capitalize; }
        </style>

        <div class="amir-cal-topnav">
          <?php foreach ( $this->nav_links() as [ $slug, $label ] ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
               class="<?php echo $slug === 'amir-calendar' ? 'is-current' : ''; ?>"><?php echo esc_html( $label ); ?></a>
          <?php endforeach; ?>
        </div>

        <div class="amir-cal-wrap">
        <div class="amir-cal-header">
          <div class="amir-cal-title"><?php echo esc_html( date_i18n( 'F Y', $month_ts ) ); ?></div>
          <div class="amir-cal-nav">
            <a href="<?php echo esc_url( $this->month_url( $year, $month - 1 ) ); ?>">← Anterior</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-calendar' ) ); ?>">Hoy</a>
            <a href="<?php echo esc_url( $this->month_url( $year, $month + 1 ) ); ?>">Siguiente →</a>
          </div>
        </div>

        <div class="amir-cal-grid">
          <?php foreach ( [ 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom' ] as $dow ) : ?>
            <div class="amir-cal-dow"><?php echo esc_html( $dow ); ?></div>
          <?php endforeach; ?>

          <?php
          $first_dow    = (int) date( 'N', $month_ts ); // 1 (lunes) .. 7 (domingo)
          $days_in_month = (int) date( 't', $month_ts );
          $today        = current_time( 'Y-m-d' );

          for ( $i = 1; $i < $first_dow; $i++ ) {
              echo '<div class="amir-cal-cell empty"></div>';
          }

          for ( $d = 1; $d <= $days_in_month; $d++ ) {
              $date_str = sprintf( '%04d-%02d-%02d', $year, $month, $d );
              $count    = $counts[ $date_str ]['bookings_count'] ?? 0;
              $classes  = [ 'amir-cal-cell' ];
              if ( $count > 0 )        $classes[] = 'has-bookings';
              if ( $date_str === $today ) $classes[] = 'today';
              if ( $date_str === $day )   $classes[] = 'is-selected';
              ?>
              <a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
                 href="<?php echo esc_url( $this->month_url( $year, $month, $date_str ) ); ?>">
                <div class="amir-cal-daynum"><?php echo $d; ?></div>
                <?php if ( $count > 0 ) : ?>
                  <span class="amir-cal-badge"><?php echo (int) $count; ?></span>
                <?php endif; ?>
              </a>
              <?php
          }
          ?>
        </div>

        <?php if ( $day ) : ?>
          <div class="amir-cal-detail">
            <div class="amir-cal-detail-title"><?php echo esc_html( date_i18n( 'l j \d\e F', strtotime( $day ) ) ); ?></div>
            <?php
            $dashboard = new \AmirBooking\Admin\DashboardPage();
            $dashboard->render_day_tours( $dashboard->get_day_summary( $day )['tours'] );
            ?>
          </div>
        <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Conteo de reservas por día del mes en UNA sola query — evita el N+1
     * que tendría llamar get_day_summary() día por día para armar la grilla.
     * Mismo filtro de estado que get_day_summary() (class-dashboard-page.php)
     * para que el número acá coincida con lo que se ve al entrar al día.
     */
    private function get_month_counts( int $year, int $month ): array {
        global $wpdb;

        $from = sprintf( '%04d-%02d-01', $year, $month );
        $to   = date( 'Y-m-t', strtotime( $from ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tour_date, COUNT(*) AS bookings_count, SUM(adults+children+babies) AS pax_count
             FROM {$wpdb->prefix}amir_bookings
             WHERE status IN ('confirmed','cancellation_requested')
               AND tour_date BETWEEN %s AND %s
             GROUP BY tour_date",
            $from, $to
        ) ) ?? [];

        $map = [];
        foreach ( $rows as $r ) {
            $map[ $r->tour_date ] = [
                'bookings_count' => (int) $r->bookings_count,
                'pax_count'      => (int) $r->pax_count,
            ];
        }
        return $map;
    }

    private function month_url( int $year, int $month, string $day = '' ): string {
        // Normalizar mes fuera de 1-12 (navegación a mes anterior/siguiente)
        $ts = mktime( 0, 0, 0, $month, 1, $year );
        $args = [ 'page' => 'amir-calendar', 'month' => date( 'Y-m', $ts ) ];
        if ( $day ) {
            $args['day'] = $day;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }
}
