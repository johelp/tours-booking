<?php
namespace TourFlow\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * [flow_product addon_id="X"] — venta suelta de un producto digital (ej.
 * guía PDF) sin reservar ningún tour, pedido del cliente 2026-08-25 (§
 * 16.9x CONTRIBUTING.md). Pensado para incrustarse en una página de venta
 * propia del operador. Pro Max — vive en el mismo edición que el resto de
 * "Extras globales"/productos digitales (amir_addons applies_to='global',
 * TourFlow → 🎁 Extras globales), no tiene sentido sin esa pantalla para
 * cargar el producto.
 */
class ProductShortcode {

	public static function product( array $atts ): string {
		$atts = shortcode_atts( [
			'lang'     => \AmirBooking\Core\Shortcodes::detect_lang( 'en' ),
			'addon_id' => 0,
		], $atts, 'flow_product' );

		$addon_id = (int) $atts['addon_id'];
		$lang     = \AmirBooking\Core\Languages::is_active( $atts['lang'] ) ? $atts['lang'] : 'en';

		if ( $addon_id <= 0 ) {
			return '<p style="color:red">flow_product: falta el parámetro addon_id</p>';
		}

		// Reusa el mismo bundle JS/CSS que el resto de los flujos de Pro Max
		// — ProductOrder.jsx ya está incluido ahí (react-src/src/booking-widget.jsx).
		\AmirBooking\Core\Shortcodes::enqueue_widget_assets();

		return sprintf(
			'<div data-flow-product="1" data-addon-id="%d" data-lang="%s"></div>',
			$addon_id,
			esc_attr( $lang )
		);
	}
}
