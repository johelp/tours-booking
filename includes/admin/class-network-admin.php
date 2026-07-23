<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Panel de administración de red (Network Admin).
 * Solo visible para Super Admins en instalaciones Multisite.
 *
 * Permite:
 *  - Ver todos los sites con el plugin activo
 *  - Ver plan asignado por site (stub, listo para licenciamiento)
 *  - Ejecutar actualizaciones de DB en toda la red
 *  - Acceder directamente al panel de cada site
 */
class NetworkAdmin {

    public function register(): void {
        add_action( 'network_admin_menu',    [ $this, 'add_network_menu' ] );
        add_action( 'network_admin_notices', [ $this, 'maybe_show_update_notice' ] );
        add_action( 'admin_post_amir_network_update_db', [ $this, 'handle_network_db_update' ] );
        add_action( 'admin_post_amir_network_set_plan',  [ $this, 'handle_set_plan' ] );
    }

    private function brand_name(): string {
        $name = get_site_option( 'amir_brand_name', '' );
        return $name ?: 'TourFlow';
    }

    public function add_network_menu(): void {
        $brand = $this->brand_name();

        add_menu_page(
            $brand . ' — Red',
            $brand,
            'manage_network_options',
            'amir-network',
            [ $this, 'render_dashboard' ],
            'dashicons-calendar-alt',
            30
        );

        add_submenu_page(
            'amir-network',
            __( 'Sites', 'amir-booking' ),
            __( 'Sites', 'amir-booking' ),
            'manage_network_options',
            'amir-network',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'amir-network',
            __( 'Configuración de Red', 'amir-booking' ),
            __( 'Configuración', 'amir-booking' ),
            'manage_network_options',
            'amir-network-settings',
            [ $this, 'render_settings' ]
        );
    }

    // ── Dashboard de red ──────────────────────────────────────────────────

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( __( 'No tienes permisos para acceder a esta página.', 'amir-booking' ) );
        }

        $sites        = get_sites( array( 'number' => 0 ) );
        $network_plan = \AmirBooking\Core\LicenseManager::get_plan();
        $network_key  = \AmirBooking\Core\LicenseManager::get_site_key();
        $brand        = $this->brand_name();

        // Recopilar stats por site
        $site_data = array();
        foreach ( $sites as $site ) {
            switch_to_blog( $site->blog_id );

            $db_version    = get_option( 'amir_db_version', '—' );
            $installed_at  = get_option( 'amir_installed_at', '' );
            $company       = get_option( 'amir_company_name', get_bloginfo( 'name' ) );
            $brand_color   = get_option( 'amir_brand_color', '#1D9E75' );
            $plugin_active = $db_version !== '—';

            $booking_count = 0;
            if ( $plugin_active ) {
                global $wpdb;
                $booking_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings WHERE status = 'confirmed'" );
            }

            $site_data[] = array(
                'blog_id'       => $site->blog_id,
                'domain'        => $site->domain . $site->path,
                'admin_url'     => get_admin_url( $site->blog_id, 'admin.php?page=amir-booking' ),
                'site_url'      => get_home_url( $site->blog_id ),
                'company'       => $company,
                'brand_color'   => $brand_color,
                'db_version'    => $db_version,
                'installed_at'  => $installed_at,
                'active'        => $plugin_active,
                'bookings'      => $booking_count,
                'needs_update'  => $plugin_active && version_compare( $db_version, AMIR_DB_VERSION, '<' ),
            );

            restore_current_blog();
        }

        $update_url = wp_nonce_url(
            network_admin_url( 'admin-post.php?action=amir_network_update_db' ),
            'amir_network_update_db'
        );

        ?>
        <div class="wrap">
          <h1><?php echo esc_html( $brand ); ?> — Panel de Red</h1>
          <p style="color:#5a7068;">
            <?php printf(
                esc_html__( 'Modo: %s · Plan: %s · Clave: %s', 'amir-booking' ),
                '<strong>' . esc_html( is_multisite() ? 'SaaS Multisite' : 'Sitio Simple' ) . '</strong>',
                '<strong>' . esc_html( $network_plan ) . '</strong>',
                '<code>' . esc_html( $network_key ) . '</code>'
            ); ?>
          </p>

          <!-- Acciones globales -->
          <div style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="<?php echo esc_url( $update_url ); ?>"
               class="button button-primary"
               onclick="return confirm('<?php esc_attr_e( '¿Ejecutar migraciones de DB en todos los sites?', 'amir-booking' ); ?>')">
              <?php esc_html_e( 'Actualizar DB en toda la red', 'amir-booking' ); ?>
            </a>
            <a href="<?php echo esc_url( network_admin_url( 'admin.php?page=amir-network-settings' ) ); ?>" class="button">
              <?php esc_html_e( 'Configuración de red', 'amir-booking' ); ?>
            </a>
          </div>

          <!-- Tabla de sites -->
          <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
            <thead>
              <tr>
                <th style="width:30px;">#</th>
                <th><?php esc_html_e( 'Operadora', 'amir-booking' ); ?></th>
                <th><?php esc_html_e( 'Dominio', 'amir-booking' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'DB Ver.', 'amir-booking' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Reservas', 'amir-booking' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( 'Estado', 'amir-booking' ); ?></th>
                <th style="width:160px;"><?php esc_html_e( 'Acciones', 'amir-booking' ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $site_data as $sd ) : ?>
              <tr>
                <td><?php echo (int) $sd['blog_id']; ?></td>
                <td>
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;
                        background:<?php echo esc_attr( $sd['brand_color'] ); ?>;margin-right:6px;"></span>
                  <strong><?php echo esc_html( $sd['company'] ); ?></strong>
                </td>
                <td>
                  <a href="<?php echo esc_url( $sd['site_url'] ); ?>" target="_blank" style="color:#1D9E75;">
                    <?php echo esc_html( $sd['domain'] ); ?>
                  </a>
                </td>
                <td>
                  <?php if ( $sd['active'] ) : ?>
                    <code><?php echo esc_html( $sd['db_version'] ); ?></code>
                  <?php else : ?>
                    <span style="color:#999;">—</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center;">
                  <?php echo $sd['active'] ? (int) $sd['bookings'] : '—'; ?>
                </td>
                <td>
                  <?php if ( ! $sd['active'] ) : ?>
                    <span style="color:#999;"><?php esc_html_e( 'Sin activar', 'amir-booking' ); ?></span>
                  <?php elseif ( $sd['needs_update'] ) : ?>
                    <span style="color:#BA7517;font-weight:600;">
                      &#9888; <?php esc_html_e( 'Necesita update', 'amir-booking' ); ?>
                    </span>
                  <?php else : ?>
                    <span style="color:#1D9E75;font-weight:600;">
                      &#10003; <?php esc_html_e( 'OK', 'amir-booking' ); ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ( $sd['active'] ) : ?>
                    <a href="<?php echo esc_url( $sd['admin_url'] ); ?>" class="button button-small">
                      <?php esc_html_e( 'Panel', 'amir-booking' ); ?>
                    </a>
                  <?php endif; ?>
                  <a href="<?php echo esc_url( get_admin_url( $sd['blog_id'] ) ); ?>" class="button button-small">
                    WP Admin
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <p style="color:#999;font-size:12px;margin-top:12px;">
            <?php printf(
                esc_html__( 'Amir Booking v%s · DB schema v%s', 'amir-booking' ),
                AMIR_VERSION,
                AMIR_DB_VERSION
            ); ?>
          </p>
        </div>
        <?php
    }

    // ── Configuración de red ──────────────────────────────────────────────

    public function render_settings(): void {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( __( 'Sin permisos.', 'amir-booking' ) );
        }

        $saved = isset( $_GET['updated'] ) && $_GET['updated'] === '1';
        $plan  = \AmirBooking\Core\LicenseManager::get_plan();
        $key   = \AmirBooking\Core\LicenseManager::get_site_key();
        $brand = $this->brand_name();

        ?>
        <div class="wrap">
          <h1><?php esc_html_e( 'Amir Booking — Configuración de Red', 'amir-booking' ); ?></h1>

          <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible">
              <p><?php esc_html_e( 'Configuración de red guardada.', 'amir-booking' ); ?></p>
            </div>
          <?php endif; ?>

          <form method="post" action="<?php echo esc_url( network_admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'amir_network_set_plan', 'amir_nonce' ); ?>
            <input type="hidden" name="action" value="amir_network_set_plan">

            <table class="form-table">
              <tr>
                <th scope="row"><?php esc_html_e( 'Nombre de marca', 'amir-booking' ); ?></th>
                <td>
                  <input type="text" name="amir_brand_name" value="<?php echo esc_attr( $brand ); ?>"
                         class="regular-text" placeholder="TourFlow">
                  <p class="description">
                    <?php esc_html_e( 'Nombre que aparece en el menú de administración de cada site. Default: TourFlow.', 'amir-booking' ); ?>
                  </p>
                </td>
              </tr>
              <tr>
                <th scope="row"><?php esc_html_e( 'Plan de la red', 'amir-booking' ); ?></th>
                <td>
                  <select name="amir_license_plan">
                    <?php foreach ( array( 'starter', 'pro', 'enterprise' ) as $p ) : ?>
                      <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $plan, $p ); ?>>
                        <?php echo esc_html( ucfirst( $p ) ); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p class="description">
                    <?php esc_html_e( 'En modo SaaS gestionado, el plan aplica a toda la red. En el futuro se podrá asignar por site.', 'amir-booking' ); ?>
                  </p>
                </td>
              </tr>
              <tr>
                <th scope="row"><?php esc_html_e( 'Clave de licencia', 'amir-booking' ); ?></th>
                <td>
                  <input type="text" name="amir_license_key" value="<?php echo esc_attr( $key ); ?>"
                         class="regular-text" placeholder="saas-managed">
                  <p class="description">
                    <?php esc_html_e( 'En modo SaaS gestionado usar "saas-managed". Para distribución futura: clave EDD/LemonSqueezy.', 'amir-booking' ); ?>
                  </p>
                </td>
              </tr>
            </table>

            <?php submit_button( __( 'Guardar configuración de red', 'amir-booking' ) ); ?>
          </form>
        </div>
        <?php
    }

    // ── Handlers de formularios ───────────────────────────────────────────

    public function handle_network_db_update(): void {
        check_admin_referer( 'amir_network_update_db' );
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Sin permisos.' );
        }

        \AmirBooking\Core\Installer::network_maybe_update();

        wp_redirect( add_query_arg(
            array( 'page' => 'amir-network', 'updated' => '1' ),
            network_admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function handle_set_plan(): void {
        check_admin_referer( 'amir_network_set_plan', 'amir_nonce' );
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Sin permisos.' );
        }

        $plan  = sanitize_text_field( isset( $_POST['amir_license_plan'] ) ? $_POST['amir_license_plan'] : 'pro' );
        $key   = sanitize_text_field( isset( $_POST['amir_license_key'] )  ? $_POST['amir_license_key']  : 'saas-managed' );
        $brand = sanitize_text_field( isset( $_POST['amir_brand_name'] )   ? $_POST['amir_brand_name']   : '' );

        \AmirBooking\Core\LicenseManager::set_plan( $plan );
        \AmirBooking\Core\LicenseManager::set_site_key( $key );
        update_site_option( 'amir_brand_name', $brand );

        wp_redirect( add_query_arg(
            array( 'page' => 'amir-network-settings', 'updated' => '1' ),
            network_admin_url( 'admin.php' )
        ) );
        exit;
    }

    // ── Aviso si hay sites que necesitan update de DB ─────────────────────

    public function maybe_show_update_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'amir' ) === false ) {
            return;
        }

        $sites       = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
        $need_update = 0;
        foreach ( $sites as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            $v = get_option( 'amir_db_version', '0' );
            if ( $v !== '—' && version_compare( $v, AMIR_DB_VERSION, '<' ) ) {
                $need_update++;
            }
            restore_current_blog();
        }

        if ( $need_update > 0 ) {
            $url = wp_nonce_url(
                network_admin_url( 'admin-post.php?action=amir_network_update_db' ),
                'amir_network_update_db'
            );
            printf(
                '<div class="notice notice-warning"><p>%s <a href="%s" class="button button-small">%s</a></p></div>',
                esc_html( sprintf(
                    _n( '%d site necesita actualización de base de datos.', '%d sites necesitan actualización de base de datos.', $need_update, 'amir-booking' ),
                    $need_update
                ) ),
                esc_url( $url ),
                esc_html__( 'Actualizar ahora', 'amir-booking' )
            );
        }
    }
}
