<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Personalización visual del widget de reserva, también desde el
 * Customizer nativo de WordPress (Apariencia → Personalizar) — pedido del
 * cliente 2026-08-03: "poder personalizarlos desde el personalizador de
 * WordPress sería práctico si no es mucho trabajo".
 *
 * Deliberadamente NO reinventa nada: cada control acá lee/escribe las
 * MISMAS opciones que ya administra Configuración → 🎨 Widget de reserva
 * (`WidgetTheme`) — es una segunda puerta de entrada a la misma
 * configuración, no un sistema de theming paralelo. `transport: 'refresh'`
 * a propósito (recarga el iframe de preview al cambiar un control) en vez
 * de JS de preview en vivo (`postMessage`) — evaluado y descartado por
 * costo/beneficio: estas páginas son PHP renderizado en servidor, no
 * componentes puramente cliente, así que un preview instantáneo real
 * necesitaría duplicar la lógica de render en JS para cada control, sin
 * beneficio proporcional al esfuerzo. La pantalla de Configuración sigue
 * siendo la fuente principal (con vista previa en vivo propia); esto es un
 * atajo adicional para quien ya vive en el Customizer.
 */
class Customizer {

    public function register(): void {
        add_action( 'customize_register', [ $this, 'add_controls' ] );
    }

    public function add_controls( \WP_Customize_Manager $wp_customize ): void {
        $wp_customize->add_panel( 'amir_widget', [
            'title'    => __( 'TourFlow — Widget de reserva', 'amir-booking' ),
            'priority' => 160,
        ] );

        $wp_customize->add_section( 'amir_widget_colors', [
            'title' => __( 'Colores y tipografía', 'amir-booking' ),
            'panel' => 'amir_widget',
        ] );

        // ── Color principal ───────────────────────────────────────────────
        $wp_customize->add_setting( 'amir_widget_color', [
            'type'              => 'option',
            'default'           => '#1D9E75',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport'         => 'refresh',
        ] );
        $wp_customize->add_control( new \WP_Customize_Color_Control( $wp_customize, 'amir_widget_color', [
            'label'   => __( 'Color principal', 'amir-booking' ),
            'description' => __( 'Variantes oscuro/claro se calculan solas — mismo criterio que Configuración → 🎨 Widget de reserva.', 'amir-booking' ),
            'section' => 'amir_widget_colors',
        ] ) );

        // ── Tipografía ───────────────────────────────────────────────────
        $wp_customize->add_setting( 'amir_widget_font', [
            'type'              => 'option',
            'default'           => 'system',
            'sanitize_callback' => [ self::class, 'sanitize_font' ],
            'transport'         => 'refresh',
        ] );
        $wp_customize->add_control( 'amir_widget_font', [
            'label'   => __( 'Tipografía', 'amir-booking' ),
            'section' => 'amir_widget_colors',
            'type'    => 'select',
            'choices' => wp_list_pluck( WidgetTheme::fonts(), 'label' ),
        ] );

        // ── Radio de esquinas ────────────────────────────────────────────
        $wp_customize->add_setting( 'amir_widget_radius', [
            'type'              => 'option',
            'default'           => 'rounded',
            'sanitize_callback' => [ self::class, 'sanitize_radius' ],
            'transport'         => 'refresh',
        ] );
        $wp_customize->add_control( 'amir_widget_radius', [
            'label'   => __( 'Radio de esquinas', 'amir-booking' ),
            'section' => 'amir_widget_colors',
            'type'    => 'select',
            'choices' => wp_list_pluck( WidgetTheme::radii(), 'label' ),
        ] );

        // ── Tamaño de fuente ─────────────────────────────────────────────
        $wp_customize->add_setting( 'amir_widget_font_scale', [
            'type'              => 'option',
            'default'           => 'normal',
            'sanitize_callback' => [ self::class, 'sanitize_scale' ],
            'transport'         => 'refresh',
        ] );
        $wp_customize->add_control( 'amir_widget_font_scale', [
            'label'   => __( 'Tamaño de fuente', 'amir-booking' ),
            'section' => 'amir_widget_colors',
            'type'    => 'select',
            'choices' => wp_list_pluck( WidgetTheme::scales(), 'label' ),
        ] );
    }

    public static function sanitize_font( string $value ): string {
        return array_key_exists( $value, WidgetTheme::fonts() ) ? $value : 'system';
    }

    public static function sanitize_radius( string $value ): string {
        return array_key_exists( $value, WidgetTheme::radii() ) ? $value : 'rounded';
    }

    public static function sanitize_scale( string $value ): string {
        return array_key_exists( $value, WidgetTheme::scales() ) ? $value : 'normal';
    }
}
