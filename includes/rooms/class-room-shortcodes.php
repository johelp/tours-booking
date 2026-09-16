<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

/**
 * [flow_room_search] — buscador de habitaciones por fecha + carrito (Pro
 * Max, CONTRIBUTING.md § 16). Convive con el flujo actual de tours, no lo
 * reemplaza (§ 16.3, decisión ya cerrada).
 */
class RoomShortcodes {

	public static function room_search( array $atts ): string {
		// Pro Max es English-first (decisión del cliente 2026-08-04) — 'en'
		// como fallback en vez de Languages::default_lang() ('es', el
		// default histórico de Amir Adventours). Sigue respetando
		// Polylang/WPML/locale del sitio si está configurado.
		$atts = shortcode_atts( [
			'lang'    => \AmirBooking\Core\Shortcodes::detect_lang( 'en' ),
			// Opcional — habitación única (templates/single-flow_room.php,
			// 2026-08-01): filtra el buscador a esa habitación en vez del
			// catálogo completo, mismo componente/carrito/checkout.
			'room_id' => 0,
		], $atts, 'flow_room_search' );
		$lang = \AmirBooking\Core\Languages::is_active( $atts['lang'] ) ? $atts['lang'] : 'en';

		// Reusa el mismo bundle JS/CSS que el widget de reserva de tours —
		// RoomSearch.jsx ya está incluido ahí (react-src/src/booking-widget.jsx).
		\AmirBooking\Core\Shortcodes::enqueue_widget_assets();

		$room_id_attr = (int) $atts['room_id'] > 0 ? sprintf( ' data-room-id="%d"', (int) $atts['room_id'] ) : '';

		return sprintf( '<div data-flow-room-search="1" data-lang="%s"%s></div>', esc_attr( $lang ), $room_id_attr );
	}

	/**
	 * [flow_room_list] — grid de habitaciones sin buscador previo (pedido
	 * del cliente 2026-08-03: [flow_room_search] siempre arranca pidiendo
	 * fechas, no hay forma de mostrar "las habitaciones que tenemos" en una
	 * página de catálogo). Mismo patrón que [flow_tour_list]/TourList.jsx —
	 * cada tarjeta linkea a la ficha individual (single-flow_room.php), que
	 * es la que arranca el flujo continuo (Flujo B) para esa habitación.
	 */
	public static function room_list( array $atts ): string {
		$atts = shortcode_atts( [
			'lang'    => \AmirBooking\Core\Shortcodes::detect_lang( 'en' ),
			'columns' => 3,
		], $atts, 'flow_room_list' );
		$lang = \AmirBooking\Core\Languages::is_active( $atts['lang'] ) ? $atts['lang'] : 'en';

		\AmirBooking\Core\Shortcodes::enqueue_widget_assets();

		return sprintf(
			'<div data-flow-room-list="1" data-lang="%s" data-columns="%d"></div>',
			esc_attr( $lang ),
			max( 1, min( 3, (int) $atts['columns'] ) )
		);
	}
}
