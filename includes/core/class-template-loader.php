<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Carga los templates del plugin si el tema activo no tiene los suyos.
 *
 * WordPress busca primero en el tema (single-amir_tour.php,
 * archive-amir_tour.php). Si no los encuentra, este loader
 * sirve los del plugin desde /templates/.
 *
 * Registro en Plugin::init():
 *   add_filter('template_include', [\AmirBooking\Core\TemplateLoader::class, 'load']);
 */
class TemplateLoader {

    public static function load( string $template ): string {
        // Single tour
        if ( is_singular( \AmirBooking\CPT\TourPostType::POST_TYPE ) ) {
            return self::locate( 'single-amir_tour.php', $template );
        }

        // Archive de tours
        if ( is_post_type_archive( \AmirBooking\CPT\TourPostType::POST_TYPE ) ) {
            return self::locate( 'archive-amir_tour.php', $template );
        }

        return $template;
    }

    /**
     * Busca el template en el tema → tema hijo → plugin (en ese orden).
     */
    private static function locate( string $filename, string $fallback ): string {
        $theme_file = locate_template( [ $filename ] );
        if ( $theme_file ) {
            return $theme_file;
        }

        $plugin_file = AMIR_PLUGIN_DIR . 'templates/' . $filename;
        return file_exists( $plugin_file ) ? $plugin_file : $fallback;
    }
}
