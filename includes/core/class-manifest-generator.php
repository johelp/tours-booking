<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Manifiesto de pasajeros en PDF — pedido del cliente 2026-08-24: poder
 * exportar el listado de participantes de un día y/o un tour puntual
 * (quién sube al bote, cuántos son, y el nombre de cada uno si el tour lo
 * requiere — ver amir_tours.require_participant_names). Landscape en vez de
 * portrait (como el voucher) porque es una tabla ancha, no una tarjeta.
 *
 * Reusa TCPDF tal cual VoucherGenerator (mismo vendor/tecnickcom, ya
 * instalado por composer) — sin duplicar la detección de la librería, ver
 * VoucherGenerator::tcpdf_available() como referencia si hace falta un
 * fallback en algún momento (acá no se implementó fallback a propósito:
 * TCPDF es una dependencia dura del plugin, siempre está disponible).
 */
class ManifestGenerator {

	/**
	 * Arma y manda el PDF directo al navegador (headers de descarga +
	 * exit). Debe llamarse desde un hook `admin_init` (nunca desde el
	 * callback de render() de una página de admin) — WordPress ya manda
	 * las cabeceras HTTP + el HTML del admin antes de invocar render(), así
	 * que un header() desde ahí falla en silencio (mismo bug real que
	 * CSV export, corregido en class-admin-menu.php el mismo día que se
	 * construyó esto — ver maybe_export_reports_csv()).
	 */
	public function stream( string $from, string $until, int $tour_id, string $lang ): void {
		$rows = $this->query_rows( $from, $until, $tour_id );

		$lib = AMIR_PLUGIN_DIR . 'vendor/tecnickcom/tcpdf/tcpdf.php';
		if ( ! class_exists( '\TCPDF' ) ) {
			if ( file_exists( $lib ) ) {
				require_once $lib;
			} else {
				wp_die( 'TCPDF no disponible.' );
			}
		}

		$is_en = $lang === 'en';
		$t     = fn( string $es, string $en ) => $is_en ? $en : $es;

		$pdf = new \TCPDF( 'L', 'mm', 'A4', true, 'UTF-8', false );
		$pdf->SetCreator( get_option( 'amir_company_name', 'TourFlow' ) );
		$pdf->SetTitle( $t( 'Manifiesto de pasajeros', 'Passenger manifest' ) );
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetMargins( 10, 10, 10 );
		$pdf->SetAutoPageBreak( true, 10 );
		$pdf->AddPage();

		$title_range = $from === $until ? $from : "{$from} → {$until}";
		$tour_label  = '';
		if ( $tour_id > 0 && ! empty( $rows ) ) {
			$tour_label = ' — ' . esc_html( $rows[0]->tour_name ?: $rows[0]->room_name );
		}

		$html = '<h2 style="font-size:14pt;">' . esc_html( get_option( 'amir_company_name', 'TourFlow' ) ) . ' — ' . $t( 'Manifiesto de pasajeros', 'Passenger manifest' ) . '</h2>'
			. '<p style="font-size:10pt;color:#555;">' . esc_html( $title_range . $tour_label ) . ' — ' . count( $rows ) . ' ' . $t( 'reserva(s)', 'booking(s)' ) . '</p>'
			. '<table border="1" cellpadding="4" style="font-size:9pt;" cellspacing="0">'
			. '<thead><tr style="background-color:#e1f5ee;font-weight:bold;">'
			. '<th width="10%">' . $t( 'Referencia', 'Reference' ) . '</th>'
			. '<th width="18%">' . $t( 'Tour/Habitación', 'Tour/Room' ) . '</th>'
			. '<th width="9%">' . $t( 'Fecha', 'Date' ) . '</th>'
			. '<th width="7%">' . $t( 'Hora', 'Time' ) . '</th>'
			. '<th width="15%">' . $t( 'Cliente', 'Customer' ) . '</th>'
			. '<th width="10%">' . $t( 'Teléfono', 'Phone' ) . '</th>'
			. '<th width="9%">' . $t( 'Pax (A/N/B)', 'Pax (A/C/B)' ) . '</th>'
			. '<th width="22%">' . $t( 'Nombres de los integrantes', 'Participant names' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$item_label = $r->item_type === 'room' ? ( '🛏 ' . $r->room_name ) : $r->tour_name;
			$names_raw  = $r->participant_names ? json_decode( $r->participant_names, true ) : [];
			$names_str  = is_array( $names_raw ) && $names_raw ? esc_html( implode( ', ', $names_raw ) ) : '—';

			$html .= '<tr>'
				. '<td>' . esc_html( $r->booking_ref ) . '</td>'
				. '<td>' . esc_html( $item_label ) . '</td>'
				. '<td>' . esc_html( $r->tour_date ) . '</td>'
				. '<td>' . esc_html( $r->time_start ? substr( $r->time_start, 0, 5 ) : '—' ) . '</td>'
				. '<td>' . esc_html( $r->customer_name ) . '</td>'
				. '<td>' . esc_html( $r->customer_phone ?: '—' ) . '</td>'
				. '<td>' . (int) $r->adults . '/' . (int) $r->children . '/' . (int) $r->babies . '</td>'
				. '<td>' . $names_str . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table>';

		$pdf->writeHTML( $html, true, false, true, false, '' );

		$filename = 'manifiesto-' . sanitize_file_name( $from . '-' . $until . ( $tour_id ? "-tour{$tour_id}" : '' ) ) . '.pdf';
		$pdf->Output( $filename, 'D' ); // 'D' = force download directo al navegador, sin guardar en disco
		exit;
	}

	/**
	 * Filas del manifiesto — tours Y habitaciones (quien sale ese día), no
	 * solo tours. participant_names solo existe en la práctica para
	 * reservas de tour (ver amir_tours.require_participant_names), pero la
	 * columna es genérica de amir_bookings así que una reserva de
	 * habitación con el campo vacío simplemente muestra "—" en el PDF.
	 */
	private function query_rows( string $from, string $until, int $tour_id ): array {
		global $wpdb;

		$where  = "b.status IN ('confirmed','completed') AND b.tour_date BETWEEN %s AND %s";
		$params = [ $from, $until ];

		if ( $tour_id > 0 ) {
			$where   .= ' AND b.tour_id = %d';
			$params[] = $tour_id;
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT b.booking_ref, b.item_type, t.name_es AS tour_name, r.name_es AS room_name,
			        b.tour_date, s.time_start, b.customer_name, b.customer_phone,
			        b.adults, b.children, b.babies, b.participant_names
			 FROM {$wpdb->prefix}amir_bookings b
			 LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
			 LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
			 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
			 WHERE {$where}
			 ORDER BY b.tour_date ASC, s.time_start ASC, t.name_es ASC",
			...$params
		) ) ?? [];
	}
}
