<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Rol "Tour Manager" (gestor de tienda) para TourFlow.
 *
 * Permisos:
 *  - Gestión completa de amir_tour (CPT) y de posts de blog — ambos
 *    comparten capacidades (capability_type='post' en TourPostType), así
 *    que no hay forma de separarlas sin re-registrar el CPT con un
 *    capability_type propio; ver nota en get_capabilities().
 *  - Acceso al panel de TourFlow (reservas, partners, disponibilidad)
 *  - SIN acceso a Configuración/Personalización (solo-admin, ver
 *    AdminMenu::add_menus()), páginas, usuarios, plugins, opciones de WP
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

            // CPT amir_tour usa capability_type='post' → estas son las MISMAS
            // capacidades que WordPress usa para posts de blog normales, no
            // hay forma de dárselas para tours sin dárselas también para el
            // blog. Antes había una clave 'edit_posts' duplicada (true acá,
            // false más abajo en "denegados") — en un array literal de PHP la
            // última gana, así que el Tour Manager terminaba SIN poder editar
            // tours en absoluto, su función principal. Nadie lo notó porque
            // se probó siempre con la cuenta de Administrador. Ahora se
            // resuelve a `true` a propósito: el gestor de tienda puede
            // gestionar tours Y cargar posts de blog con el menú "Entradas"
            // normal de WordPress (pedido explícito del cliente).
            'edit_posts'                 => true,
            'edit_others_posts'          => true,
            'publish_posts'              => true,
            'read_private_posts'         => true,
            'delete_posts'               => true,
            'delete_published_posts'     => true,
            'edit_published_posts'       => true,

            // Taxonomías del CPT
            'manage_amir_tour_category'  => true,
            'edit_amir_tour_category'    => true,

            // Plugin específico
            'manage_amir_booking'        => true,    // Acceso al menú del plugin
            'view_amir_reports'          => true,
            'manage_amir_partners'       => true,

            // Denegados explícitamente — Configuración/Personalización quedan
            // afuera vía manage_options (AdminMenu::add_menus() ya no usa el
            // capability compartido para esos dos submenús puntuales).
            'edit_pages'                 => false,
            'manage_options'             => false,
            'install_plugins'            => false,
            'manage_plugins'             => false,
            'edit_users'                 => false,
        ];
    }

    // ── Hooks de comportamiento ───────────────────────────────────────────

    // Bug real corregido 2026-08-04 ("Sorry, you are not allowed to access
    // this page" al entrar a Tours, reportado probando en vivo en
    // caliafarm.com): esta clase registraba SU PROPIA copia del submenú
    // Tours/Nuevo tour (add_tour_submenu(), ya eliminado) apuntando al mismo
    // slug edit.php?post_type=amir_tour que AdminMenu::add_menus() ya
    // registra — pero con la capacidad 'edit_amir_tours', que nunca se le
    // otorga a NINGÚN rol (ni siquiera Administrador; get_capabilities() de
    // acá abajo usa 'edit_posts', no 'edit_amir_tours' — quedó de alguna
    // versión anterior del rol y nadie lo notó porque WordPress, al tener
    // dos registros para el mismo slug, a veces resuelve el acceso contra
    // el que tiene la capacidad rota). AdminMenu::add_menus() ya cubre
    // Tours/Nuevo tour para todos los roles (Tour Manager incluido, vía
    // 'manage_amir_booking') — este duplicado no hacía falta.
    public static function register_hooks(): void {
        // Redirigir al login exitoso
        add_filter( 'login_redirect',     [ __CLASS__, 'redirect_after_login'  ], 10, 3 );
        // Redirigir si intenta ir al dashboard de WP
        add_action( 'admin_init',         [ __CLASS__, 'redirect_from_wp_admin' ] );
        // Ocultar menús innecesarios
        add_action( 'admin_menu',         [ __CLASS__, 'hide_unrelated_menus'  ], 999 );
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

        // Permitir: páginas del plugin + CPT amir_tour + posts de blog + media.
        // 'amir-settings'/'amir-personalization' quedan afuera a propósito —
        // son solo-admin (ver AdminMenu::add_menus()), un gestor de tienda no
        // debe ni llegar a esas pantallas.
        $allowed_pages = [
            'amir-booking', 'amir-bookings-list', 'amir-availability',
            'amir-partners', 'amir-reports',
        ];

        $allowed_screens = [ 'amir_tour', 'upload', 'media' ];
        $allowed_post_types = [ 'amir_tour', 'post' ];

        if (
            ! empty($page) && in_array( $page, $allowed_pages, true ) ||
            ! empty($post_type) && in_array( $post_type, $allowed_post_types, true ) ||
            $screen && in_array( $screen->base, [ 'post', 'edit', 'media', 'upload' ], true ) && in_array( $post_type, $allowed_post_types, true ) ||
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
            // 'edit.php' (Posts) queda visible a propósito — el gestor de
            // tienda puede cargar posts de blog (pedido explícito del cliente).
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
