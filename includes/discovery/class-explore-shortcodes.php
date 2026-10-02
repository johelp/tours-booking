<?php
namespace TourFlow\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * [flow_explore] — segundo flujo de reserva de Pro Max, búsqueda-primero
 * (§ 16.46 CONTRIBUTING.md), pedido explícito del cliente 2026-08-09.
 * Standalone: a diferencia de [flow_discovery], no admite tour_id/room_id
 * preseleccionado — decisión confirmada con el cliente para esta ronda,
 * este flujo nace como búsqueda-primero.
 */
class ExploreShortcodes {

	public static function explore( array $atts ): string {
		// Pro Max es English-first (decisión del cliente 2026-08-04) — 'en'
		// como fallback, mismo criterio que DiscoveryShortcodes.
		$atts = shortcode_atts( [
			'lang' => \AmirBooking\Core\Shortcodes::detect_lang( 'en' ),
		], $atts, 'flow_explore' );
		$lang = \AmirBooking\Core\Languages::is_active( $atts['lang'] ) ? $atts['lang'] : 'en';

		// Mismo bundle JS/CSS que el resto del widget de reserva —
		// ExploreFlow.jsx ya está incluido ahí (react-src/src/booking-widget.jsx).
		\AmirBooking\Core\Shortcodes::enqueue_widget_assets();

		return sprintf( '<div data-flow-explore="1" data-lang="%s"></div>', esc_attr( $lang ) );
	}
}
