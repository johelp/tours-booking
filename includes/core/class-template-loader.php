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

    /** Plantillas de detalle de tour disponibles — clave de la opción amir_tour_template. */
    private const TOUR_TEMPLATES = [
        'classic'   => 'single-amir_tour.php',
        'immersive' => 'single-amir_tour-immersive.php',
    ];

    public static function load( string $template ): string {
        // Single tour — el tema siempre gana si ya tiene single-amir_tour.php
        // (comportamiento preexistente sin cambios): un operador que copió
        // ese archivo a su tema sigue viéndolo, sin importar qué plantilla
        // esté elegida en Personalización — para pasar a la Inmersiva desde
        // ahí tendría que copiar single-amir_tour-immersive.php en su lugar.
        if ( is_singular( \AmirBooking\CPT\TourPostType::POST_TYPE ) ) {
            $theme_file = locate_template( [ 'single-amir_tour.php' ] );
            if ( $theme_file ) {
                return $theme_file;
            }
            return self::locate( self::tour_template_filename(), $template );
        }

        // Archive de tours
        if ( is_post_type_archive( \AmirBooking\CPT\TourPostType::POST_TYPE ) ) {
            return self::locate( 'archive-amir_tour.php', $template );
        }

        // Single habitación (Pro Max, § 16 CONTRIBUTING.md) — versión mínima
        // (§ 16.10): sin selector de plantilla todavía, a diferencia de
        // tours (una sola opción por ahora).
        if ( class_exists( \TourFlow\Rooms\RoomPostType::class ) && is_singular( \TourFlow\Rooms\RoomPostType::POST_TYPE ) ) {
            $theme_file = locate_template( [ 'single-flow_room.php' ] );
            if ( $theme_file ) {
                return $theme_file;
            }
            return self::locate( 'single-flow_room.php', $template );
        }

        // Archive de habitaciones (Pro Max) — gap real encontrado 2026-08-04
        // repasando shortcodes/páginas con el cliente: nunca se construyó,
        // así que /rooms/ caía al archive genérico del tema activo (mismo
        // tipo de gap que § 16.10 para la ficha individual, esta vez para
        // el listado). Mismo patrón que el archive de tours.
        if ( class_exists( \TourFlow\Rooms\RoomPostType::class ) && is_post_type_archive( \TourFlow\Rooms\RoomPostType::POST_TYPE ) ) {
            return self::locate( 'archive-flow_room.php', $template );
        }

        return $template;
    }

    /**
     * Archivo de plantilla de detalle elegido en Personalización (default:
     * Clásica). La Inmersiva es Pro y superior (Pro Max incluido) — en Lite
     * se ignora la opción guardada (ej. una instalación que bajó de Pro a
     * Lite) y el archivo ni siquiera está en el ZIP, así que tampoco
     * alcanzaría con la opción sola.
     */
    public static function tour_template_filename(): string {
        $key = get_option( 'amir_tour_template', 'classic' );
        if ( $key === 'immersive' && ! in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
            $key = 'classic';
        }
        return self::TOUR_TEMPLATES[ $key ] ?? self::TOUR_TEMPLATES['classic'];
    }

    /** Opciones disponibles para el <select> de Personalización. */
    public static function tour_template_options(): array {
        if ( ! in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
            return [ 'classic' => 'Clásica' ];
        }
        return [
            'classic'   => 'Clásica',
            'immersive' => 'Inmersiva',
        ];
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
