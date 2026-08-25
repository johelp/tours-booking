<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Personalización visual del widget de reserva (Tarea 19 del roadmap).
 *
 * El widget (react-src/src/styles/widget.css) ya está construido casi
 * enteramente sobre variables CSS (--ab-teal, --ab-font, --ab-radius, etc.)
 * — esta clase no inventa un sistema de theming nuevo, solo lo conecta a
 * Configuración: lee las opciones y arma el bloque `:root{...}` que
 * Shortcodes::enqueue_widget_assets() inyecta vía wp_add_inline_style(),
 * sin tocar el CSS compilado por el build de React.
 *
 * Guardrails a propósito (decidido con el cliente): un solo color principal
 * con variantes oscuro/claro/medio calculadas automáticamente (nunca una
 * combinación fea/ilegible elegida sin querer), y una lista curada de
 * fuentes en vez de texto libre (nunca una fuente que no cargue o no se
 * lea bien en el ancho chico del widget).
 */
class WidgetTheme {

    private const FONTS = [
        'system'  => [
            'label'      => 'Sistema (por defecto)',
            'stack'      => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
            'google_url' => '',
        ],
        'inter'   => [
            'label'      => 'Inter',
            'stack'      => "'Inter', sans-serif",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
        ],
        'poppins' => [
            'label'      => 'Poppins',
            'stack'      => "'Poppins', sans-serif",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap',
        ],
        'nunito'  => [
            'label'      => 'Nunito',
            'stack'      => "'Nunito', sans-serif",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap',
        ],
        'roboto'  => [
            'label'      => 'Roboto',
            'stack'      => "'Roboto', sans-serif",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap',
        ],
        'lato'    => [
            'label'      => 'Lato',
            'stack'      => "'Lato', sans-serif",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap',
        ],
    ];

    private const SCALES = [
        'compact' => [ 'label' => 'Compacto', 'value' => 0.92 ],
        'normal'  => [ 'label' => 'Normal (por defecto)', 'value' => 1.0 ],
        'large'   => [ 'label' => 'Grande', 'value' => 1.08 ],
    ];

    private const RADII = [
        'square'  => [ 'label' => 'Cuadrado',        'value' => 4 ],
        'rounded' => [ 'label' => 'Redondeado (por defecto)', 'value' => 12 ],
        'pill'    => [ 'label' => 'Muy redondeado',  'value' => 20 ],
    ];

    /**
     * Estilo visual de [flow_booking] — pedido explícito del cliente
     * (2026-08-16): un segundo "look" del mismo widget de 7 pasos, misma
     * lógica/estado/validaciones, solo cambia CSS/layout — mismo criterio
     * que Clásica/Inmersiva para la ficha del tour, pero sin duplicar el
     * componente (acá no hace falta: es un re-skin, no una estructura
     * distinta). Universal a las 3 ediciones, igual que el resto de esta
     * sección de Personalización.
     */
    private const STYLES = [
        'classic'   => [ 'label' => 'Clásica (compacta)' ],
        'fullwidth' => [ 'label' => 'Fullwidth (paso ancho)' ],
    ];

    // ── Accessors para la UI de Configuración ─────────────────────────────

    public static function fonts(): array {
        return self::FONTS;
    }

    public static function scales(): array {
        return self::SCALES;
    }

    public static function radii(): array {
        return self::RADII;
    }

    public static function styles(): array {
        return self::STYLES;
    }

    public static function font_key(): string {
        $key = get_option( 'amir_widget_font', 'system' );
        return array_key_exists( $key, self::FONTS ) ? $key : 'system';
    }

    public static function scale_key(): string {
        $key = get_option( 'amir_widget_font_scale', 'normal' );
        return array_key_exists( $key, self::SCALES ) ? $key : 'normal';
    }

    public static function radius_key(): string {
        $key = get_option( 'amir_widget_radius', 'rounded' );
        return array_key_exists( $key, self::RADII ) ? $key : 'rounded';
    }

    public static function style_key(): string {
        $key = get_option( 'amir_widget_style', 'classic' );
        return array_key_exists( $key, self::STYLES ) ? $key : 'classic';
    }

    /** URL del <link> de Google Fonts a encolar, vacío si es la fuente de sistema. */
    public static function google_font_url(): string {
        return self::FONTS[ self::font_key() ]['google_url'] ?? '';
    }

    /** Mostrar el nombre del paso debajo del ícono en la barra de progreso (default sí). */
    public static function progress_labels(): bool {
        return get_option( 'amir_widget_progress_labels', '1' ) !== '0';
    }

    // ── CSS inline ─────────────────────────────────────────────────────────

    public static function render_inline_css(): string {
        $v = self::resolved_values();

        return ":root{"
            . "--ab-teal:{$v['color']};"
            . "--ab-teal-dark:{$v['color_dark']};"
            . "--ab-teal-light:{$v['color_light']};"
            . "--ab-teal-mid:{$v['color_mid']};"
            . "--ab-font:{$v['font_stack']};"
            . "--ab-font-scale:{$v['font_scale']};"
            . "--ab-radius:{$v['radius']}px;"
            . "--ab-radius-sm:{$v['radius_sm']}px;"
            . '}';
    }

    /**
     * Mismos valores resueltos que render_inline_css(), pero como datos en
     * vez de texto CSS — para un cliente que no puede consumir `:root{...}`
     * directo (app móvil nativa, web headless). Ver GET /amir/v1/config en
     * ConfigController.
     */
    public static function resolved_values(): array {
        $color = get_option( 'amir_widget_color', '#1D9E75' );
        if ( ! preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ) {
            $color = '#1D9E75';
        }

        $radius = self::RADII[ self::radius_key() ]['value'];

        return [
            'color'       => $color,
            'color_dark'  => self::darken( $color, 0.7 ),
            'color_light' => self::lighten( $color, 0.15 ),
            'color_mid'   => self::lighten( $color, 0.4 ),
            'font_key'    => self::font_key(),
            'font_stack'  => self::FONTS[ self::font_key() ]['stack'],
            'font_scale'  => self::SCALES[ self::scale_key() ]['value'],
            'radius'      => $radius,
            'radius_sm'   => max( 4, $radius - 4 ),
        ];
    }

    // ── Matemática de color ────────────────────────────────────────────────
    // Misma idea que BaseEmail::darken_color()/lighten_color() (emails) y
    // TourList.jsx::shade() (grilla de tours) — versión propia acá en vez de
    // forzar una dependencia cruzada entre esas clases y esta.

    private static function darken( string $hex, float $factor ): string {
        $hex = ltrim( $hex, '#' );
        $r = (int) ( hexdec( substr( $hex, 0, 2 ) ) * $factor );
        $g = (int) ( hexdec( substr( $hex, 2, 2 ) ) * $factor );
        $b = (int) ( hexdec( substr( $hex, 4, 2 ) ) * $factor );
        return sprintf( '#%02x%02x%02x', max( 0, $r ), max( 0, $g ), max( 0, $b ) );
    }

    private static function lighten( string $hex, float $factor ): string {
        $hex = ltrim( $hex, '#' );
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $r = (int) ( $r + ( 255 - $r ) * ( 1 - $factor ) );
        $g = (int) ( $g + ( 255 - $g ) * ( 1 - $factor ) );
        $b = (int) ( $b + ( 255 - $b ) * ( 1 - $factor ) );
        return sprintf( '#%02x%02x%02x', min( 255, $r ), min( 255, $g ), min( 255, $b ) );
    }
}
