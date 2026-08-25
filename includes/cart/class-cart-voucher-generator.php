<?php
namespace TourFlow\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * "Voucher general" del carrito — un solo PDF + QR cubriendo TODOS los ítems
 * de un mismo cart_group_id (tour(s) + habitación(es) + extras), § 16.15
 * CONTRIBUTING.md, pedido crítico de un cliente real. Extiende
 * AmirBooking\Core\VoucherGenerator (QR/PDF-backend-detection/data-uri ya
 * resueltos ahí, generalizados a protected) en vez de duplicar esa
 * infraestructura — solo cambia el HTML: varias secciones (una por ítem) en
 * vez de una sola reserva.
 */
final class CartVoucherGenerator extends \AmirBooking\Core\VoucherGenerator {

	public function generate_for_cart( string $cart_group_id ): string {
		$bookings = $this->get_cart_bookings( $cart_group_id );
		if ( empty( $bookings ) ) {
			return '';
		}

		$filename = 'voucher-cart-' . sanitize_file_name( $cart_group_id ) . '.pdf';
		$filepath = $this->upload_dir . $filename;

		$all_confirmed = ! array_filter( $bookings, fn( $b ) => $b->status !== 'confirmed' );
		if ( file_exists( $filepath ) && $all_confirmed ) {
			return $filepath;
		}

		if ( $this->ensure_tcpdf_loaded() ) {
			$pdf = new \TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
			$pdf->SetCreator( get_option( 'amir_company_name', 'TourFlow' ) );
			$pdf->SetTitle( 'Voucher ' . $cart_group_id );
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );
			$pdf->SetMargins( 14, 14, 14 );
			$pdf->SetAutoPageBreak( true, 14 );
			$pdf->AddPage();
			$pdf->writeHTML( $this->render_cart_html( $bookings, $cart_group_id ), true, false, true, false, '' );
			$pdf->Output( $filepath, 'F' );
			if ( file_exists( $filepath ) ) {
				return $filepath;
			}
		}

		// Fallback: HTML descargable (mismo criterio que VoucherGenerator::generate_html_fallback()).
		$html_path = str_replace( '.pdf', '.html', $filepath );
		file_put_contents( $html_path, $this->render_cart_html( $bookings, $cart_group_id ) );
		return $html_path;
	}

	public function stream_for_cart( string $cart_group_id ): void {
		$bookings = $this->get_cart_bookings( $cart_group_id );
		if ( empty( $bookings ) ) {
			wp_die( 'Carrito no encontrado.' );
		}

		$filepath = $this->generate_for_cart( $cart_group_id );
		if ( ! $filepath || ! file_exists( $filepath ) ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			echo $this->render_cart_html( $bookings, $cart_group_id );
			exit;
		}

		$mime = ( substr( $filepath, -4 ) === '.pdf' ) ? 'application/pdf' : 'text/html';
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="voucher-' . sanitize_file_name( $cart_group_id ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $filepath ) );
		readfile( $filepath );
		exit;
	}

	/**
	 * @return object[] Filas de amir_bookings de este carrito (CUALQUIER
	 * status), con nombre/punto de encuentro/horario ya resueltos. Devuelve
	 * todo el carrito a propósito — build_cart_document() filtra a los
	 * confirmados para el contenido, pero necesita el total para detectar
	 * si hay ítems todavía pendientes de aprobación del proveedor.
	 */
	private function get_cart_bookings( string $cart_group_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, t.name_es AS tour_name_es, t.name_en AS tour_name_en,
			        t.meeting_point_es, t.meeting_point_en,
			        r.name_es AS room_name_es, r.name_en AS room_name_en,
			        s.time_start
			 FROM {$wpdb->prefix}amir_bookings b
			 LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
			 LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
			 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
			 WHERE b.cart_group_id = %s
			 ORDER BY b.item_type ASC, b.tour_date ASC",
			$cart_group_id
		) ) ?? [];
		return $rows;
	}

	/**
	 * Extras del carrito ligados a alguna de las reservas ya confirmadas —
	 * mismo criterio y misma query que CartConfirmationEmail::addons_block()
	 * (no duplicar ese análisis acá, ver los comentarios ahí): un addon
	 * cuelga de CUALQUIERA de las reservas pagables del carrito, no
	 * necesariamente la primera por fecha/tipo.
	 */
	private function get_cart_addons( array $confirmed_items ): array {
		if ( empty( $confirmed_items ) ) {
			return [];
		}
		global $wpdb;
		$ids          = wp_list_pluck( $confirmed_items, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT ba.name_snapshot, ba.qty, ba.total_mxn
			 FROM {$wpdb->prefix}amir_booking_addons ba
			 WHERE ba.booking_id IN ({$placeholders}) ORDER BY ba.id",
			$ids
		) ) ?? [];
	}

	private function render_cart_html( array $bookings, string $cart_group_id ): string {
		$primary = $bookings[0];
		$lang    = $primary->lang ?? 'es';
		return \AmirBooking\Core\Languages::run_in( $lang, fn() => $this->build_cart_document( $bookings, $cart_group_id, $lang ) );
	}

	private function build_cart_document( array $bookings, string $cart_group_id, string $lang ): string {
		$is_en   = $lang === 'en';
		$primary = $bookings[0];
		$green   = get_option( 'amir_brand_color', '#1D9E75' );
		$company = get_option( 'amir_company_name', 'TourFlow' );
		$group_ref = 'CART-' . strtoupper( substr( $cart_group_id, 0, 8 ) );

		// Solo se listan (y se cobran acá) los ítems YA confirmados — un
		// ítem con proveedor externo puede seguir en
		// 'pending_provider_approval' aunque el pago ya se haya procesado
		// (BookingManager::confirm(), CartController::confirm_payment()).
		// Mismo criterio que CartConfirmationEmail::send_for(): listarlo acá
		// como "confirmado" le mentiría al cliente — ese ítem tiene su
		// propio aviso interino (ProviderPendingNoticeEmail) y aparece en
		// este voucher recién cuando el proveedor lo confirme.
		$confirmed_items = array_values( array_filter( $bookings, fn( $b ) => in_array( $b->status, [ 'confirmed', 'completed' ], true ) ) );
		$pending_count   = count( $bookings ) - count( $confirmed_items );
		$total           = array_sum( array_map( fn( $b ) => (float) $b->total_mxn, $confirmed_items ) );
		$addons          = $this->get_cart_addons( $confirmed_items );

		// QR propio del carrito — apunta a la página de verificación con
		// ?cart=X&token=Y (el access_token de la PRIMERA reserva del grupo
		// autoriza el carrito entero, mismo criterio que un ref+token
		// individual). Modo Campo ya sabe reconocer este parámetro (ver
		// FieldPage::handle_actions()/render_scan()).
		$verify_url = add_query_arg(
			[ 'cart' => $cart_group_id, 'token' => $primary->access_token ?? '' ],
			$this->verify_page_url()
		);
		$qr_path = $this->generate_qr_for_url( $verify_url, $group_ref );
		$qr_uri  = $qr_path ? $this->path_to_data_uri( $qr_path ) : '';

		// Logo de marca configurable — mismo criterio que
		// VoucherGenerator::render_pdf_html() (single-booking), antes este
		// voucher combinado ignoraba amir_brand_logo_url y siempre usaba el
		// logo genérico del plugin sin importar lo que el operador hubiera
		// cargado en Personalización (auditoría de UI 2026-08-05).
		$logo_url_opt = get_option( 'amir_brand_logo_url', '' );
		if ( $logo_url_opt ) {
			// Bug real 2026-08-20 (caliafarm.com): esto etiquetaba cualquier
			// logo descargado como image/png a ciegas — con un logo SVG,
			// TCPDF tiraba "Unable to get the size of the image" y el
			// voucher del carrito no se generaba (el cliente pagaba y se
			// quedaba sin comprobante). remote_logo_data_uri() (heredado de
			// VoucherGenerator) detecta el tipo real y devuelve '' si TCPDF
			// no puede embeberlo, en vez de romper el documento completo.
			$logo_uri = $this->remote_logo_data_uri( $logo_url_opt );
		} else {
			$logo_path = AMIR_PLUGIN_DIR . 'assets/images/logo-email.png';
			$logo_uri  = file_exists( $logo_path ) ? $this->path_to_data_uri( $logo_path ) : '';
		}
		$logo_html = $logo_uri
			? '<img src="' . $logo_uri . '" height="36" alt="' . esc_attr( $company ) . '" /><br/>'
			: '<b style="font-size:14pt;color:' . $green . ';">' . esc_html( strtoupper( $company ) ) . '</b><br/>';

		// "RESERVA CONFIRMADA" solo si TODO el carrito quedó confirmado —
		// con ítems de proveedor todavía pendientes, el pago sí está
		// confirmado pero la reserva en sí no del todo (mismo bug real ya
		// corregido en CartController::confirm_payment()/
		// CartConfirmationEmail, que este voucher se había quedado sin
		// heredar).
		$confirmed_label = $pending_count === 0
			? ( $is_en ? 'BOOKING CONFIRMED' : 'RESERVA CONFIRMADA' )
			: ( $is_en ? 'PAYMENT CONFIRMED' : 'PAGO CONFIRMADO' );
		$ref_label       = $is_en ? 'Group reference' : 'Referencia de grupo';
		$total_label     = $is_en ? 'Total paid' : 'Total pagado';
		$passenger_label = $is_en ? 'Guest' : 'Huésped';

		// background:#fff explícito — sin esto, el fallback HTML (hosts sin
		// TCPDF) se veía negro/ilegible en un navegador con modo oscuro, ya
		// que ningún elemento fijaba fondo propio (TCPDF ignora esto, pero
		// el fallback lo sirve tal cual a un navegador real). Mismo criterio
		// que VoucherGenerator::render_html_document(), que ya lo tenía.
		$html  = '<html><head><meta charset="UTF-8"></head>';
		// dejavusans, no Helvetica — mismo bug real que VoucherGenerator (la
		// fuente core de TCPDF no tiene glyphs para los símbolos Unicode que
		// usa este template, se veían como "?"/casillas vacías en el PDF
		// real, 2026-08-22).
		$html .= '<body style="font-family:dejavusans;color:#1a2e24;font-size:10pt;background:#fff;">';

		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:3px solid ' . $green . ';margin-bottom:10px;padding-bottom:8px;">'
			. '<tr><td width="70%" valign="middle">' . $logo_html
			. '<span style="background:#e1f5ee;color:#0F6E56;font-size:8pt;font-weight:bold;padding:1px 6px;">&#10003; ' . $confirmed_label . '</span>'
			. '</td><td width="30%" align="right" valign="top">'
			. ( $qr_uri ? '<img src="' . $qr_uri . '" width="72" height="72" alt="QR" style="border:1px solid #c8ead9;padding:3px;" />' : '' )
			. '</td></tr></table>';

		// El fondo va en cada <td>, no en el <table> — mismo bug real que
		// VoucherGenerator (TCPDF no aplica `background` de forma confiable
		// sobre <table>, el texto blanco de adentro quedaba invisible sobre
		// fondo blanco, 2026-08-22).
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;">'
			. '<tr><td style="background-color:' . $green . ';padding:10px 14px;" valign="middle">'
			. '<div style="font-size:7.5pt;font-weight:bold;color:#fff;text-transform:uppercase;">' . $ref_label . '</div>'
			. '<div style="font-size:18pt;font-weight:bold;color:#fff;letter-spacing:1px;">' . esc_html( $group_ref ) . '</div>'
			. '</td><td style="background-color:' . $green . ';padding:10px 14px;text-align:right;" valign="middle">'
			. '<div style="font-size:8pt;color:#fff;">' . $total_label . '</div>'
			. '<div style="font-size:14pt;font-weight:bold;color:#fff;">' . \AmirBooking\Core\Currency::format( $total ) . '</div>'
			. '</td></tr></table>';

		$html .= '<div style="font-size:8pt;font-weight:bold;text-transform:uppercase;color:' . $green . ';border-bottom:1px solid #c8ead9;padding-bottom:3px;margin-bottom:6px;">' . $passenger_label . '</div>'
			. '<div style="font-size:10pt;margin-bottom:14px;">' . esc_html( $primary->customer_name ) . ' — ' . esc_html( $primary->customer_email ) . '</div>';

		if ( empty( $confirmed_items ) ) {
			// Todo el carrito sigue pendiente de proveedor — caso raro (lo
			// normal es que al menos el/los ítems propios ya estén
			// confirmados) pero posible si el carrito era 100% de terceros.
			$html .= '<div style="background:#fffbeb;border-left:3px solid #BA7517;padding:10px 14px;font-size:9.5pt;color:#78350f;">'
				. ( $is_en
					? 'Your payment was processed successfully. We\'re confirming availability with the operator(s) — you\'ll get the full voucher by email as soon as it\'s confirmed.'
					: 'Tu pago se procesó correctamente. Estamos confirmando disponibilidad con el/los operador(es) — te llega el voucher completo por email en cuanto quede confirmado.' )
				. '</div>';
		}

		foreach ( $confirmed_items as $i => $b ) {
			$html .= $this->render_item_section( $b, $i + 1, $is_en, $green );
		}

		if ( $pending_count > 0 ) {
			$html .= '<div style="background:#fffbeb;border-left:3px solid #BA7517;padding:10px 14px;margin-bottom:14px;font-size:9pt;color:#78350f;">'
				. ( $is_en
					? ( $pending_count === 1
						? '1 more item in this booking is still being confirmed with an external operator — you\'ll get it by email as soon as it\'s confirmed, no extra charge.'
						: $pending_count . ' more items in this booking are still being confirmed with external operators — you\'ll get them by email as soon as they\'re confirmed, no extra charge.' )
					: ( $pending_count === 1
						? '1 ítem más de esta reserva sigue en confirmación con un operador externo — te llega por email en cuanto se confirme, sin cargo adicional.'
						: $pending_count . ' ítems más de esta reserva siguen en confirmación con operadores externos — te llegan por email en cuanto se confirmen, sin cargo adicional.' ) )
				. '</div>';
		}

		if ( ! empty( $addons ) ) {
			$html .= '<div style="font-size:8pt;font-weight:bold;text-transform:uppercase;color:' . $green . ';border-bottom:1px solid #c8ead9;padding-bottom:3px;margin-bottom:6px;">'
				. ( $is_en ? 'Extras' : 'Extras' ) . '</div>';
			$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e1f5ee;margin-bottom:14px;">';
			foreach ( $addons as $i => $a ) {
				$bg    = ( $i % 2 === 0 ) ? '#ffffff' : '#f5f9f7';
				$label = esc_html( $a->name_snapshot ) . ( $a->qty > 1 ? ' × ' . (int) $a->qty : '' );
				$html .= '<tr style="background:' . $bg . ';"><td style="padding:5px 8px;font-size:9pt;">' . $label . '</td>'
					. '<td style="padding:5px 8px;font-weight:bold;font-size:9pt;text-align:right;">' . \AmirBooking\Core\Currency::format( (float) $a->total_mxn ) . '</td></tr>';
			}
			$html .= '</table>';
		}

		// Footer con datos de contacto — faltaba del todo antes (el voucher
		// combinado terminaba abrupto justo después del último ítem, sin
		// ningún dato de la empresa ni forma de contactar — pedido explícito
		// del cliente, "confuso, desalineado y carece de imagen de marca").
		$wa   = get_option( 'amir_wa_phone', '' );
		$site = get_site_url();
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-top:2px solid ' . $green . ';padding-top:8px;margin-top:4px;">'
			. '<tr>'
			. '<td valign="middle"><b style="font-size:9pt;">' . esc_html( $company ) . '</b><br/>'
			. '<span style="font-size:8pt;color:#5a7068;">' . esc_url( $site ) . '</span></td>'
			. ( $wa ? '<td align="right" valign="middle"><b style="font-size:9pt;color:' . $green . ';">WhatsApp: +' . esc_html( $wa ) . '</b></td>' : '' )
			. '</tr></table>';

		$html .= '</body></html>';
		return $html;
	}

	private function render_item_section( object $b, int $index, bool $is_en, string $green ): string {
		if ( $b->item_type === 'room' ) {
			$name  = $is_en ? ( $b->room_name_en ?: $b->room_name_es ) : ( $b->room_name_es ?: $b->room_name_en );
			$title = ( $is_en ? 'Item ' : 'Ítem ' ) . $index . ' · ' . ( $is_en ? 'Room' : 'Habitación' );
			$rows  = [
				[ $is_en ? 'Room' : 'Habitación', esc_html( $name ) ],
				[ 'Check-in', esc_html( $b->tour_date ) ],
				[ 'Check-out', esc_html( $b->check_out_date ) ],
				[ $is_en ? 'Guests' : 'Huéspedes', (int) $b->adults ],
			];
		} elseif ( $b->item_type === 'product' ) {
			// Venta suelta de un producto digital (§ 16.9x CONTRIBUTING.md) —
			// sin tour_id/room_id, name_snapshot es la única fuente de
			// nombre (mismo criterio que CartConfirmationEmail::get_body_content()).
			global $wpdb;
			$name = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT name_snapshot FROM {$wpdb->prefix}amir_booking_addons WHERE booking_id = %d LIMIT 1",
				$b->id
			) );
			$title = ( $is_en ? 'Item ' : 'Ítem ' ) . $index . ' · ' . ( $is_en ? 'Digital product' : 'Producto digital' );
			$rows  = [
				[ $is_en ? 'Product' : 'Producto', esc_html( $name ) ],
			];
		} else {
			$name  = $is_en ? ( $b->tour_name_en ?: $b->tour_name_es ) : ( $b->tour_name_es ?: $b->tour_name_en );
			$title = ( $is_en ? 'Item ' : 'Ítem ' ) . $index . ' · ' . ( $is_en ? 'Experience' : 'Experiencia' );
			$rows  = [
				[ $is_en ? 'Experience' : 'Experiencia', esc_html( $name ) ],
				[ $is_en ? 'Date' : 'Fecha', esc_html( $b->tour_date ) ],
			];
			if ( ! empty( $b->time_start ) ) {
				$rows[] = [ $is_en ? 'Departure time' : 'Hora de salida', esc_html( substr( $b->time_start, 0, 5 ) ) ];
			}
			$rows[] = [ $is_en ? 'People' : 'Personas', (int) $b->adults + (int) $b->children + (int) $b->babies ];
			$meeting = $is_en ? ( $b->meeting_point_en ?? '' ) : ( $b->meeting_point_es ?? '' );
			if ( $meeting !== '' ) {
				$rows[] = [ $is_en ? 'Meeting point' : 'Punto de encuentro', esc_html( $meeting ) ];
			}
		}
		$rows[] = [ $is_en ? 'Reference' : 'Referencia', esc_html( $b->booking_ref ) ];
		$rows[] = [ $is_en ? 'Amount' : 'Monto', \AmirBooking\Core\Currency::format( (float) $b->total_mxn ) ];

		$out = '<div style="font-size:8pt;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;color:' . $green . ';margin:10px 0 4px;">' . $title . '</div>';
		$out .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e1f5ee;margin-bottom:12px;">';
		$i = 0;
		foreach ( $rows as $row ) {
			$bg = ( $i++ % 2 === 0 ) ? '#ffffff' : '#f5f9f7';
			$out .= '<tr style="background:' . $bg . ';"><td style="padding:5px 8px;color:#5a7068;font-size:9pt;width:40%;">' . $row[0] . '</td>'
				. '<td style="padding:5px 8px;font-weight:bold;font-size:9pt;text-align:right;">' . $row[1] . '</td></tr>';
		}
		$out .= '</table>';
		return $out;
	}
}
