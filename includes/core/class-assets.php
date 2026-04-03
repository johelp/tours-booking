<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registro y carga de assets del plugin.
 * El widget React se carga SOLO en páginas que usen el shortcode
 * (gracias al enqueue diferido en class-shortcodes.php).
 * Este archivo maneja los assets globales de admin y el CSS de tarjetas Elementor.
 */
class Assets {

    public function register(): void {
        add_action( 'wp_enqueue_scripts',    [ $this, 'frontend_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_global_assets' ] );
    }

    // ── Frontend público ──────────────────────────────────────────────────

    public function frontend_assets(): void {
        // CSS de tarjetas de tour (para Elementor Loop y shortcode [amir_tour_list])
        wp_register_style(
            'amir-tour-cards',
            AMIR_PLUGIN_URL . 'assets/css/tour-cards.css',
            [],
            AMIR_VERSION
        );

        // Solo encolar si el CPT archive o single está activo
        if ( is_post_type_archive( \AmirBooking\CPT\TourPostType::POST_TYPE )
             || is_singular( \AmirBooking\CPT\TourPostType::POST_TYPE ) ) {
            wp_enqueue_style( 'amir-tour-cards' );
        }
    }

    // ── Admin global ──────────────────────────────────────────────────────

    public function admin_global_assets( string $hook ): void {
        // Pequeño CSS global para badges del menú admin
        wp_add_inline_style( 'wp-admin', '
            #adminmenu .amir-badge {
                display: inline-block;
                background: #e24b4a;
                color: #fff;
                font-size: 10px;
                font-weight: 700;
                line-height: 1;
                padding: 2px 5px;
                border-radius: 10px;
                margin-left: 4px;
                vertical-align: middle;
            }
        ' );
    }
}
