<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Menú de administración en el panel de WordPress.
 * Registra todos los submenús y pages del plugin.
 */
class AdminMenu {

    public function register(): void {
        add_action( 'admin_menu',   [ $this, 'add_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        // La descarga del PDF debe ocurrir antes de que WordPress envíe cualquier HTML
        add_action( 'admin_init',   [ $this, 'maybe_stream_pdf' ] );
    }

    /**
     * Detecta ?page=amir-bookings-list&action=pdf&id=X en admin_init
     * (antes de que WordPress renderice el admin header) y hace stream del PDF.
     */
    public function maybe_stream_pdf(): void {
        if (
            ( $_GET['page']   ?? '' ) !== 'amir-bookings-list' ||
            ( $_GET['action'] ?? '' ) !== 'pdf'                ||
            empty( $_GET['id'] )                               ||
            ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' )
        ) {
            return;
        }

        ( new \AmirBooking\Core\VoucherGenerator() )->stream( (int) $_GET['id'] );
        exit;
    }

    /**
     * Nombre de marca del plugin — configurable desde Network Admin.
     * Default: 'TourFlow' (genérico, sin referencia a "Amir").
     */
    private function brand_name(): string {
        $name = is_multisite()
            ? get_site_option( 'amir_brand_name', '' )
            : get_option( 'amir_brand_name', '' );
        return $name ?: 'TourFlow';
    }

    public function add_menus(): void {
        $unread = $this->get_unread_count();
        $badge  = $unread > 0 ? " <span class='amir-badge'>{$unread}</span>" : '';
        $brand  = $this->brand_name();

        // Capacidad base: manage_options para admins, manage_amir_booking para Tour Managers
        $cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'manage_amir_booking';

        // Menú principal
        add_menu_page(
            $brand,
            $brand . $badge,
            $cap,
            'amir-booking',
            [ $this, 'page_dashboard' ],
            'dashicons-calendar-alt',
            30
        );

        // Submenús — todos visibles para admin y tour manager
        $submenus = [
            [ 'amir-booking',        __( 'Dashboard',      'amir-booking' ), [ $this, 'page_dashboard'   ] ],
            [ 'amir-bookings-list',  __( 'Reservas',       'amir-booking' ), [ $this, 'page_bookings'    ] ],
            [ 'amir-availability',   __( 'Disponibilidad', 'amir-booking' ), [ $this, 'page_availability'] ],
            [ 'amir-partners',       __( 'Partners',       'amir-booking' ), [ $this, 'page_partners'    ] ],
            [ 'amir-reports',        __( 'Reportes',       'amir-booking' ), [ $this, 'page_reports'     ] ],
            [ 'amir-settings',       __( 'Configuración',  'amir-booking' ), [ $this, 'page_settings'    ] ],
        ];

        foreach ( $submenus as [ $slug, $title, $callback ] ) {
            add_submenu_page(
                'amir-booking',
                $title,
                $title,
                $cap,
                $slug,
                $callback
            );
        }

        // Tours: apunta directo al listado del CPT
        add_submenu_page(
            'amir-booking',
            __( 'Tours', 'amir-booking' ),
            __( '🏄 Tours', 'amir-booking' ),
            $cap,
            'edit.php?post_type=amir_tour'
        );

        // Nuevo tour: atajo rápido
        add_submenu_page(
            'amir-booking',
            __( 'Nuevo tour', 'amir-booking' ),
            __( '+ Nuevo tour', 'amir-booking' ),
            $cap,
            'post-new.php?post_type=amir_tour'
        );
    }

    // ── Páginas del admin (PHP nativo, sin SPA) ────────────────────────────

    public function page_dashboard(): void {
        ( new \AmirBooking\Admin\DashboardPage() )->render();
    }
    public function page_bookings(): void {
        ( new \AmirBooking\Admin\BookingsPage() )->render();
    }
    public function page_availability(): void {
        ( new \AmirBooking\Admin\AvailabilityPage() )->render();
    }
    public function page_partners(): void {
        ( new \AmirBooking\Admin\PartnersPage() )->render();
    }
    public function page_reports(): void {
        ( new \AmirBooking\Admin\ReportsPage() )->render();
    }
    public function page_settings(): void {
        ( new \AmirBooking\Admin\SettingsPage() )->render();
    }

    // ── Assets ─────────────────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Solo en páginas del plugin
        if ( strpos( $hook, 'amir' ) === false ) {
            return;
        }

        $is_settings_page = strpos( $hook, 'amir-settings' ) !== false;

        // Media library: necesaria para el selector de logo en Settings.
        // Debe cargarse aquí (admin_enqueue_scripts) y NO dentro del callback
        // de la página — ese se ejecuta después del <head> y los scripts
        // de wp.media quedarían fuera del contexto correcto.
        if ( $is_settings_page ) {
            wp_enqueue_media();
        }

        wp_enqueue_style(
            'amir-admin',
            AMIR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AMIR_VERSION
        );

        // Configuración es una página 100% PHP (sin React) — su único JS es
        // el selector de color y el uploader de logo, ambos inline en
        // class-settings-page.php. El bundle admin.js está pensado para las
        // demás pantallas del admin (Dashboard, Reservas, etc.) y, al no
        // encontrar lo que espera en el DOM de Configuración, interfiere con
        // el envío nativo del formulario de guardado. No se carga acá hasta
        // que exista el código fuente (react-src) para investigar y corregir
        // esa interacción de raíz.
        if ( $is_settings_page ) {
            return;
        }

        wp_enqueue_script(
            'amir-admin',
            AMIR_PLUGIN_URL . 'assets/js/admin.js',
            [],
            AMIR_VERSION,
            true
        );

        // Pasar datos del backend al React admin
        wp_localize_script( 'amir-admin', 'amirAdminData', [
            'apiUrl'   => rest_url( 'amir/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'currency' => get_option( 'amir_currency', 'MXN' ),
            'siteUrl'  => get_site_url(),
            'version'  => AMIR_VERSION,
        ] );
    }

    // ── Badge de notificaciones ────────────────────────────────────────────

    private function get_unread_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amir_notifications WHERE is_read = 0"
        );
    }
}
