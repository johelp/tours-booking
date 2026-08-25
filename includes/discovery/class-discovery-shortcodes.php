<?php
namespace TourFlow\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * [flow_discovery] — flujo continuo de descubrimiento (Pro Max,
 * CONTRIBUTING.md § 16.15). Un solo shortcode para los dos flujos:
 *
 *   [flow_discovery mode="experience"]              → Flujo A desde cero
 *   [flow_discovery mode="experience" tour_id="12"]  → Flujo A arrancando
 *     en un tour puntual (usado por single-amir_tour.php/immersive)
 *   [flow_discovery mode="room"]                     → Flujo B desde cero
 *   [flow_discovery mode="room" room_id="3"]         → Flujo B arrancando
 *     en una habitación puntual (usado por single-flow_room.php)
 */
class DiscoveryShortcodes {

	public static function discovery( array $atts ): string {
		// Pro Max es English-first (decisión del cliente 2026-08-04) — 'en'
		// como fallback en vez de Languages::default_lang() ('es'). Sigue
		// respetando Polylang/WPML/locale del sitio si está configurado.
		$atts = shortcode_atts( [
			'lang'         => \AmirBooking\Core\Shortcodes::detect_lang( 'en' ),
			'mode'         => 'experience',
			'tour_id'      => 0,
			'room_id'      => 0,
			// Pedido explícito del cliente (2026-08-20): que el catálogo se
			// vea de entrada en vez de solo el formulario suelto. Default
			// "yes" — "no" restaura el comportamiento anterior.
			'show_catalog' => 'yes',
		], $atts, 'flow_discovery' );
		$lang = \AmirBooking\Core\Languages::is_active( $atts['lang'] ) ? $atts['lang'] : 'en';
		$mode = $atts['mode'] === 'room' ? 'room' : 'experience';

		// Mismo bundle JS/CSS que el resto del widget de reserva —
		// DiscoveryFlow.jsx ya está incluido ahí (react-src/src/booking-widget.jsx).
		\AmirBooking\Core\Shortcodes::enqueue_widget_assets();

		$attrs = sprintf( ' data-lang="%s" data-mode="%s"', esc_attr( $lang ), esc_attr( $mode ) );
		if ( (int) $atts['tour_id'] > 0 ) {
			$attrs .= sprintf( ' data-tour-id="%d"', (int) $atts['tour_id'] );
		}
		if ( (int) $atts['room_id'] > 0 ) {
			$attrs .= sprintf( ' data-room-id="%d"', (int) $atts['room_id'] );
		}
		if ( strtolower( (string) $atts['show_catalog'] ) === 'no' ) {
			$attrs .= ' data-show-catalog="no"';
		}

		return sprintf( '<div data-flow-discovery="1"%s></div>', $attrs );
	}
}
