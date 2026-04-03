<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Rol "Tour Manager" para Amir Adventours.
 *
 * Permisos:
 *  - Gestión completa de amir_tour (CPT)
 *  - Acceso al panel de Amir Booking (reservas, partners, disponibilidad)
 *  - SIN acceso a posts, páginas, usuarios, plugins, opciones de WP
 *
 * Al hacer login, redirige directo al dashboard del plugin.
 */
class TourManagerRole {

    public const ROLE_SLUG = 'amir_tour_manager';

    public static function register(): void {
        // Solo crear si no existe
        if ( get_role( self::ROLE_SLUG ) ) {
            return;
        }

        add_role( self::ROLE_SLUG, __( 'Tour Manager', 'amir-booking' ), self::get_capabilities() );
    }

    public static function remove(): void {
        remove_role( self::ROLE_SLUG );
    }

    // ── Capacidades del rol ───────────────────────────────────────────────

    public static function get_capabilities(): array {
        return [
            // WordPress core — mínimo necesario
            'read'                       => true,
            'upload_files'               => true,   // Para galería de fotos

            // CPT amir_tour usa capability_type='post' → caps estándar de WordPress
            'edit_posts'                 => true,
            'edit_others_posts'          => true,
            'publish_posts'              => true,
            'read_private_posts'         => true,
            'delete_posts'               => true,
            'delete_published_posts'     => true,
            'edit_published_posts'       => true,
            'upload_files'               => true,

            // Taxonomías del CPT
            'manage_amir_tour_category'  => true,
            'edit_amir_tour_category'    => true,

            // Plugin específico
            'manage_amir_booking'        => true,    // Acceso al menú del plugin
            'view_amir_reports'          => true,
            'manage_amir_partners'       => true,

            // Denegados explícitamente
            'edit_posts'                 => false,
            'edit_pages'                 => false,
            'manage_options'             => false,
            'install_plugins'            => false,
            'manage_plugins'             => false,
            'edit_users'                 => false,
        ];
    }

    // ── Hooks de comportamiento ───────────────────────────────────────────

    public static function register_hooks(): void {
        // Redirigir al login exitoso
        add_filter( 'login_redirect',     [ __CLASS__, 'redirect_after_login'  ], 10, 3 );
        // Redirigir si intenta ir al dashboard de WP
        add_action( 'admin_init',         [ __CLASS__, 'redirect_from_wp_admin' ] );
        // Ocultar menús innecesarios
        add_action( 'admin_menu',         [ __CLASS__, 'hide_unrelated_menus'  ], 999 );
        // Mostrar el CPT dentro de nuestro menú
        add_action( 'admin_menu',         [ __CLASS__, 'add_tour_submenu'      ], 10 );
        // Quitar "Ver el sitio" y noticias de WP del adminbar
        add_action( 'admin_bar_menu',     [ __CLASS__, 'clean_admin_bar'       ], 999 );
        // Capacidades dinámicas para el CPT
        add_filter( 'user_has_cap',       [ __CLASS__, 'grant_dynamic_caps'    ], 10, 3 );
    }

    // ── Redirección post-login ────────────────────────────────────────────

    public static function redirect_after_login( string $redirect_to, string $requested, $user ): string {
        if ( is_wp_error( $user ) || ! ( $user instanceof \WP_User ) || ! $user->has_cap( 'manage_amir_booking' ) ) {
            return $redirect_to;
        }
        return admin_url( 'admin.php?page=amir-booking' );
    }

    // ── Redirigir fuera del admin de WP ──────────────────────────────────

    public static function redirect_from_wp_admin(): void {
        if ( ! self::is_tour_manager() ) {
            return;
        }

        $screen    = get_current_screen();
        $page      = $_GET['page'] ?? '';
        $post_type = $_GET['post_type'] ?? ( $screen ? $screen->post_type : '' );

        // Permitir: páginas del plugin + CPT amir_tour + media
        $allowed_pages = [
            'amir-booking', 'amir-bookings-list', 'amir-availability',
            'amir-partners', 'amir-reports', 'amir-settings',
        ];

        $allowed_screens = [ 'amir_tour', 'upload', 'media' ];

        if (
            ! empty($page) && in_array( $page, $allowed_pages, true ) ||
            ! empty($post_type) && $post_type === 'amir_tour' ||
            $screen && in_array( $screen->base, [ 'post', 'edit', 'media', 'upload' ], true ) && $post_type === 'amir_tour' ||
            $screen && in_array( $screen->id, $allowed_screens, true )
        ) {
            return; // Está en una página permitida
        }

        // Si está en el dashboard genérico de WP → redirigir al del plugin
        if ( ! $page && ( ! $screen || $screen->id === 'dashboard' ) ) {
            wp_redirect( admin_url( 'admin.php?page=amir-booking' ) );
            exit;
        }
    }

    // ── Ocultar menús no relevantes ───────────────────────────────────────

    public static function hide_unrelated_menus(): void {
        if ( ! self::is_tour_manager() ) {
            return;
        }

        $remove = [
            'index.php',           // Dashboard WP
            'edit.php',            // Posts
            'upload.php',          // Media (lo dejamos accesible via URL, solo ocultamos del menú)
            'edit.php?post_type=page',
            'edit-comments.php',
            'themes.php',
            'plugins.php',
            'users.php',
            'tools.php',
            'options-general.php',
            'woocommerce',
        ];

        foreach ( $remove as $item ) {
            remove_menu_page( $item );
        }
    }

    // ── Agregar CPT al menú del plugin ────────────────────────────────────

    public static function add_tour_submenu(): void {
        // El CPT se registró con show_in_menu=false, lo colocamos bajo nuestro menú
        add_submenu_page(
            'amir-booking',
            __( 'Tours', 'amir-booking' ),
            __( 'Tours (editar)', 'amir-booking' ),
            'edit_amir_tours',
            'edit.php?post_type=amir_tour'
        );
        add_submenu_page(
            'amir-booking',
            __( 'Nuevo tour', 'amir-booking' ),
            __( '+ Nuevo tour', 'amir-booking' ),
            'edit_amir_tours',
            'post-new.php?post_type=amir_tour'
        );
    }

    // ── Limpiar admin bar ─────────────────────────────────────────────────

    public static function clean_admin_bar( \WP_Admin_Bar $bar ): void {
        if ( ! self::is_tour_manager() ) {
            return;
        }
        $bar->remove_node( 'wp-logo' );
        $bar->remove_node( 'about' );
        $bar->remove_node( 'wporg' );
        $bar->remove_node( 'documentation' );
        $bar->remove_node( 'support-forums' );
        $bar->remove_node( 'feedback' );
        $bar->remove_node( 'updates' );
        $bar->remove_node( 'comments' );
        $bar->remove_node( 'new-content' );
    }

    // ── Capacidades dinámicas ─────────────────────────────────────────────

    public static function grant_dynamic_caps( array $allcaps, array $caps, array $args ): array {
        // Asegurarse de que el rol puede acceder a las páginas del menú admin del plugin
        if ( isset($allcaps['manage_amir_booking']) && $allcaps['manage_amir_booking'] ) {
            $allcaps['read'] = true;
        }
        return $allcaps;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public static function is_tour_manager(): bool {
        $user = wp_get_current_user();
        return $user && in_array( self::ROLE_SLUG, (array) $user->roles, true );
    }
}
