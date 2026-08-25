<?php
namespace TourFlow\Cart;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../emails/class-email-dispatcher.php';

/**
 * Email con el "voucher general" del carrito (§ 16.15 CONTRIBUTING.md) —
 * complementa (no reemplaza) los emails individuales que cada ítem ya manda
 * vía amir_booking_confirmed/flow_room_booking_confirmed. Extiende BaseEmail
 * (branding/wrapper ya resueltos ahí) en vez de duplicarlo.
 */
final class CartConfirmationEmail extends \AmirBooking\Emails\BaseEmail {

	public static function send_for( string $cart_group_id ): void {
		global $wpdb;
		// status = 'confirmed' explícito: un carrito mixto (tour propio +
		// tour de un proveedor externo en el mismo checkout) puede terminar
		// con ítems en estados distintos — el tour propio 'confirmed', el
		// del proveedor 'pending_provider_approval' hasta que responda (ver
		// BookingManager::confirm() y CartController::confirm_payment()).
		// Listar ambos acá como "confirmado" le mentía al cliente sobre el
		// ítem que en realidad seguía pendiente — ese ítem ya tiene su
		// propio aviso interino (ProviderPendingNoticeEmail), no le
		// corresponde aparecer en el voucher general todavía.
		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, t.name_es AS tour_name_es, t.name_en AS tour_name_en,
			        r.name_es AS room_name_es, r.name_en AS room_name_en
			 FROM {$wpdb->prefix}amir_bookings b
			 LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
			 LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
			 WHERE b.cart_group_id = %s AND b.status = 'confirmed'
			 ORDER BY b.item_type ASC, b.tour_date ASC",
			$cart_group_id
		) );
		if ( empty( $bookings ) ) {
			return;
		}

		$primary = $bookings[0];
		$mailer  = new self( $primary );
		$mailer->cart_group_id = $cart_group_id;
		$mailer->items         = $bookings;
		$mailer->send();
	}

	private string $cart_group_id = '';
	/** @var object[] */
	private array $items = [];

	// Carrito 100% de productos (sin ningún tour/habitación) — venta suelta
	// (§ 16.9x CONTRIBUTING.md), "reserva" no es la palabra correcta acá.
	private function is_product_only(): bool {
		foreach ( $this->items as $b ) {
			if ( $b->item_type !== 'product' ) {
				return false;
			}
		}
		return ! empty( $this->items );
	}

	protected function get_subject(): string {
		$ref = 'CART-' . strtoupper( substr( $this->cart_group_id, 0, 8 ) );
		if ( $this->is_product_only() ) {
			return ( $this->lang === 'es' ? '✅ Tu compra está confirmada — ' : '✅ Your purchase is confirmed — ' ) . $ref;
		}
		return ( $this->lang === 'es' ? '✅ Tu reserva está confirmada — ' : '✅ Your booking is confirmed — ' ) . $ref;
	}

	protected function get_body_content(): string {
		$is_en        = $this->lang === 'en';
		$product_only = $this->is_product_only();
		$total = array_sum( array_map( fn( $b ) => (float) $b->total_mxn, $this->items ) );

		if ( $product_only ) {
			$intro = '<h1>' . ( $is_en ? 'Thanks for your purchase! 🎉' : '¡Gracias por tu compra! 🎉' ) . '</h1>'
				. '<p>' . ( $is_en
					? 'Hello <strong>' . esc_html( $this->booking->customer_name ) . '</strong>,<br>Here\'s your order — the download link is below:'
					: 'Hola <strong>' . esc_html( $this->booking->customer_name ) . '</strong>,<br>Este es tu pedido — el link de descarga está más abajo:'
				) . '</p>';
		} else {
			$intro = '<h1>' . ( $is_en ? 'Your booking is confirmed! 🎉' : '¡Tu reserva está confirmada! 🎉' ) . '</h1>'
				. '<p>' . ( $is_en
					? 'Hello <strong>' . esc_html( $this->booking->customer_name ) . '</strong>,<br>Here\'s a summary of all the items in your booking:'
					: 'Hola <strong>' . esc_html( $this->booking->customer_name ) . '</strong>,<br>Te confirmamos todos los ítems de tu reserva:'
				) . '</p>';
		}

		global $wpdb;
		$rows = '';
		foreach ( $this->items as $b ) {
			if ( $b->item_type === 'room' ) {
				$name  = $is_en ? ( $b->room_name_en ?: $b->room_name_es ) : ( $b->room_name_es ?: $b->room_name_en );
				$detail = esc_html( $b->tour_date ) . ' → ' . esc_html( $b->check_out_date );
				$icon  = '🛏';
			} elseif ( $b->item_type === 'product' ) {
				// Venta suelta de un producto digital (§ 16.9x CONTRIBUTING.md)
				// — sin tour_id/room_id, name_snapshot (guardado al comprar,
				// amir_booking_addons) es la única fuente de nombre acá. El
				// link de descarga en sí lo agrega addons_block() más abajo
				// (misma query, no duplicar) — esta fila es solo el resumen.
				$name   = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT name_snapshot FROM {$wpdb->prefix}amir_booking_addons WHERE booking_id = %d LIMIT 1",
					$b->id
				) );
				$detail = $is_en ? 'Digital product' : 'Producto digital';
				$icon   = '📄';
			} else {
				$name  = $is_en ? ( $b->tour_name_en ?: $b->tour_name_es ) : ( $b->tour_name_es ?: $b->tour_name_en );
				$detail = esc_html( $b->tour_date );
				$icon  = '🏄';
			}
			$rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid ' . esc_attr( $this->lighten_color( $this->brand_color() ) ) . ';">'
				. $icon . ' <strong>' . esc_html( $name ) . '</strong><br>'
				. '<span style="font-size:12px;color:#5a7068;">' . $detail . ' — ' . esc_html( $b->booking_ref ) . '</span>'
				. '</td></tr>';
		}

		// 'ab-price-total' era una clase del CSS del widget React
		// (react-src/src/styles/widget.css) que nunca se carga en el
		// contexto de un email — copiado por error, el total se veía como
		// texto plano sin ningún resalte (auditoría de UI 2026-08-05,
		// mismo bug de fondo que el var(--teal-light) de arriba: estilos
		// del widget colados en el HTML de emails, que solo tiene el
		// <style> inline de BaseEmail::wrap_template()).
		$table = '<table class="info-table" style="width:100%;">' . $rows . '</table>'
			. '<p style="margin-top:12px;font-size:16px;font-weight:800;color:' . esc_attr( $this->brand_color() ) . ';">' . $this->t( 'total' ) . ': ' . \AmirBooking\Core\Currency::format( $total ) . '</p>';

		$addons_block = $this->addons_block( $is_en );

		$pdf_url = rest_url( 'flow/v1/cart/' . rawurlencode( $this->cart_group_id ) . '/pdf' )
			. '?token=' . rawurlencode( $this->booking->access_token ?? '' );

		$actions = '<p style="text-align:center;margin-top:24px;">
		  <a href="' . esc_url( $pdf_url ) . '" class="btn">' . ( $is_en ? 'Download general voucher (PDF)' : 'Descargar voucher general (PDF)' ) . '</a>
		</p>';

		return $intro . $table . $addons_block . $actions;
	}

	/**
	 * Extras del carrito (§ 16.23 CONTRIBUTING.md) — un addon queda colgado
	 * de CUALQUIERA de las reservas de $this->items (CartController los
	 * adjunta a la primera pagable, que no necesariamente es $this->booking
	 * — ese es solo el primero por item_type/fecha, un orden distinto). Por
	 * eso el link de descarga usa el access_token de SU PROPIA reserva
	 * dueña (b.access_token del JOIN), no el de $this->booking — si no,
	 * el link tokenizado no matchea y el endpoint lo rechaza con 403.
	 */
	private function addons_block( bool $is_en ): string {
		if ( empty( $this->items ) ) {
			return '';
		}
		global $wpdb;
		$ids          = wp_list_pluck( $this->items, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$addons = $wpdb->get_results( $wpdb->prepare(
			"SELECT ba.id, ba.name_snapshot, ba.qty, ba.total_mxn, a.pricing_type, a.digital_file_url, b.access_token
			 FROM {$wpdb->prefix}amir_booking_addons ba
			 LEFT JOIN {$wpdb->prefix}amir_addons a   ON a.id = ba.addon_id
			 JOIN      {$wpdb->prefix}amir_bookings b ON b.id = ba.booking_id
			 WHERE ba.booking_id IN ({$placeholders}) ORDER BY ba.id",
			$ids
		) ) ?? [];
		if ( empty( $addons ) ) {
			return '';
		}

		$lines = array_map( function ( $a ) use ( $is_en ) {
			$label = esc_html( $a->name_snapshot ) . ( $a->qty > 1 ? ' × ' . (int) $a->qty : '' );
			$line  = $label . ' — ' . \AmirBooking\Core\Currency::format( (float) $a->total_mxn );
			if ( $a->pricing_type === 'digital' && ! empty( $a->digital_file_url ) && ! empty( $a->access_token ) ) {
				$download_url = add_query_arg( [ 'token' => $a->access_token ], rest_url( 'flow/v1/addons/download/' . (int) $a->id ) );
				$line .= ' — <a href="' . esc_url( $download_url ) . '">' . ( $is_en ? 'Download file' : 'Descargar archivo' ) . '</a>';
			}
			return '<div style="font-size:14px;margin:4px 0;">' . $line . '</div>';
		}, $addons );

		return '<p style="margin:16px 0 4px;"><strong>' . ( $is_en ? 'Extra services' : 'Servicios extra' ) . '</strong></p>' . implode( '', $lines );
	}
}
