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
		// Un carrito mixto (tour propio + tour o habitación de un proveedor
		// externo en el mismo checkout) termina con ítems en estados
		// distintos — lo propio 'confirmed', lo del proveedor
		// 'pending_provider_approval' hasta que responda (ver
		// BookingManager::confirm() y CartController::confirm_group()).
		// Antes este email listaba solo lo confirmado y el ítem pendiente no
		// aparecía en ningún lado (ni su nombre ni su precio): el cliente
		// pagaba el total y el email mostraba menos, con apenas un aviso
		// suelto sin detalle. Ahora se listan los dos estados y cada ítem
		// pendiente se marca como tal — el cliente recibe la compra completa
		// en un solo mensaje, y después la confirmación (o el rechazo) de
		// cada pendiente por separado.
		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, t.name_es AS tour_name_es, t.name_en AS tour_name_en,
			        r.name_es AS room_name_es, r.name_en AS room_name_en
			 FROM {$wpdb->prefix}amir_bookings b
			 LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
			 LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
			 WHERE b.cart_group_id = %s AND b.status IN ('confirmed','pending_provider_approval')
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

	private function is_pending( object $b ): bool {
		return $b->status === 'pending_provider_approval';
	}

	// Un ítem pendiente "cobrado" (modo inmediato) ya tiene cargo; uno en
	// modo "al aprobar" todavía no se cobró nada — no entra al total pagado.
	private function is_charged( object $b ): bool {
		return ! empty( $b->gateway_charge_id ) || ! empty( $b->stripe_charge_id );
	}

	private function pending_count(): int {
		return count( array_filter( $this->items, fn( $b ) => $this->is_pending( $b ) ) );
	}

	protected function get_subject(): string {
		$ref     = 'CART-' . strtoupper( substr( $this->cart_group_id, 0, 8 ) );
		$pending = $this->pending_count();
		if ( $pending > 0 ) {
			return $this->lang === 'es'
				? '⏳ Recibimos tu reserva — falta confirmar ' . $pending . ( $pending > 1 ? ' ítems' : ' ítem' ) . ' — ' . $ref
				: '⏳ We received your booking — ' . $pending . ' item' . ( $pending > 1 ? 's' : '' ) . ' awaiting confirmation — ' . $ref;
		}
		if ( $this->is_product_only() ) {
			return ( $this->lang === 'es' ? '✅ Tu compra está confirmada — ' : '✅ Your purchase is confirmed — ' ) . $ref;
		}
		return ( $this->lang === 'es' ? '✅ Tu reserva está confirmada — ' : '✅ Your booking is confirmed — ' ) . $ref;
	}

	protected function get_body_content(): string {
		$is_en        = $this->lang === 'en';
		$product_only = $this->is_product_only();
		$has_pending  = $this->pending_count() > 0;
		// Total realmente cobrado: lo confirmado más lo pendiente que ya se
		// cobró; lo pendiente en modo "al aprobar" no se cobró todavía.
		$total = array_sum( array_map(
			fn( $b ) => ( $this->is_pending( $b ) && ! $this->is_charged( $b ) ) ? 0.0 : (float) $b->total_mxn,
			$this->items
		) );

		if ( $has_pending ) {
			$intro = '<h1>' . ( $is_en ? 'We received your booking! 🎉' : '¡Recibimos tu reserva! 🎉' ) . '</h1>'
				. '<p>' . ( $is_en
					? 'Hello <strong>' . esc_html( $this->booking->customer_name ) . '</strong>,<br>Here is the full detail of your booking. Items marked <strong>⏳ Pending confirmation</strong> are being confirmed with the local operator — we\'ll email you as soon as each one is confirmed. If one can\'t be confirmed, you won\'t be charged for it (or you\'ll get a 100% refund if it was already charged); the rest of your booking stays as it is.'
					: 'Hola <strong>' . esc_html( $this->booking->customer_name ) . '</strong>,<br>Este es el detalle completo de tu reserva. Los ítems marcados con <strong>⏳ Pendiente de confirmación</strong> los estamos confirmando con el operador local — te escribimos apenas se confirme cada uno. Si alguno no pudiera confirmarse, no se te cobra ese ítem (o te lo reembolsamos al 100% si ya estaba cobrado); el resto de tu reserva no cambia.'
				) . '</p>';
		} elseif ( $product_only ) {
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
			$badge = '';
			if ( $this->is_pending( $b ) ) {
				$badge = '<br><span style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:10px;background:#fff4d6;color:#8a5a00;font-size:12px;font-weight:700;">⏳ '
					. ( $is_en ? 'Pending confirmation' : 'Pendiente de confirmación' ) . '</span>'
					. ( $this->is_charged( $b ) ? '' : '<span style="font-size:12px;color:#5a7068;"> '
						. ( $is_en ? '— not charged until confirmed' : '— no se cobra hasta que se confirme' ) . '</span>' );
			}
			$rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid ' . esc_attr( $this->lighten_color( $this->brand_color() ) ) . ';">'
				. $icon . ' <strong>' . esc_html( $name ) . '</strong><br>'
				. '<span style="font-size:12px;color:#5a7068;">' . $detail . ' — ' . esc_html( $b->booking_ref ) . '</span>'
				. $badge
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

		// El PDF general solo lista lo confirmado — si todo sigue pendiente no
		// habría nada que mostrarle, así que en ese caso va solo el link de estado.
		$actions = '';
		if ( $this->pending_count() < count( $this->items ) ) {
			$actions .= '<p style="text-align:center;margin-top:24px;">
		  <a href="' . esc_url( $pdf_url ) . '" class="btn">' . ( $is_en ? 'Download general voucher (PDF)' : 'Descargar voucher general (PDF)' ) . '</a>
		</p>';
		}
		if ( $has_pending ) {
			$actions .= '<p style="text-align:center;font-size:13px;margin-top:12px;">'
				. '<a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . esc_html( $this->t( 'view_status' ) ) . '</a></p>';
		}

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
			"SELECT ba.id, ba.name_snapshot, ba.qty, ba.total_mxn, a.pricing_type, a.digital_file_url, b.access_token, b.status AS booking_status
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
			if ( $a->booking_status === 'confirmed' && $a->pricing_type === 'digital' && ! empty( $a->digital_file_url ) && ! empty( $a->access_token ) ) {
				$download_url = add_query_arg( [ 'token' => $a->access_token ], rest_url( 'flow/v1/addons/download/' . (int) $a->id ) );
				$line .= ' — <a href="' . esc_url( $download_url ) . '">' . ( $is_en ? 'Download file' : 'Descargar archivo' ) . '</a>';
			}
			return '<div style="font-size:14px;margin:4px 0;">' . $line . '</div>';
		}, $addons );

		return '<p style="margin:16px 0 4px;"><strong>' . ( $is_en ? 'Extra services' : 'Servicios extra' ) . '</strong></p>' . implode( '', $lines );
	}
}
