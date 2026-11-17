<?php
namespace TourFlowCustomEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Arma el diccionario {{variable}} => valor a partir de una reserva — tanto
 * para el render real (desde Template::maybe_render(), con un $booking real
 * de amir_bookings ya traído con el JOIN de tour/horario por
 * EmailDispatcher::get_booking_with_tour()) como para el envío de prueba
 * (Settings::handle_send_test(), con un objeto ficticio con la misma forma).
 *
 * Dos grupos, documentados también en la pantalla de admin:
 * - "texto" (TEXT_KEYS): se insertan con esc_html() — nunca HTML crudo.
 * - "url" (URL_KEYS): ya vienen por esc_url(), pensadas para ir dentro de
 *   href="{{...}}" — insertarlas con esc_html() las rompería (el operador
 *   las usa directo en el HTML que pega).
 */
final class Vars {

	public const TEXT_KEYS = [
		'customer_name', 'customer_email', 'customer_phone', 'booking_ref',
		'tour_name', 'tour_date', 'tour_time', 'meeting_point', 'total',
		'people_count', 'special_requests', 'company_name', 'brand_color',
	];

	public const URL_KEYS = [ 'pdf_url', 'verify_url', 'calendar_url', 'maps_url', 'whatsapp_url', 'logo_url' ];

	/**
	 * @param object               $booking Fila de amir_bookings + columnas de tour/horario ya unidas.
	 * @param \AmirBooking\Emails\BaseEmail|null $email Instancia real del email en curso — null en el envío de prueba,
	 *        donde no hay BaseEmail (no hay booking real que construirlo). Provee verify_url()/calendar_url()/maps_url(),
	 *        las 3 ensanchadas a public el 2026-11-16 específicamente para este satélite.
	 */
	public static function build( object $booking, ?object $email = null ): array {
		$company = get_option( 'amir_company_name', 'TourFlow' );
		$wa      = get_option( 'amir_wa_phone', '' );

		$pax = (int) ( $booking->adults ?? 0 ) + (int) ( $booking->children ?? 0 ) + (int) ( $booking->babies ?? 0 );

		$pdf_url = rest_url( 'amir/v1/bookings/' . rawurlencode( (string) $booking->booking_ref ) . '/pdf' )
			. '?token=' . rawurlencode( (string) ( $booking->access_token ?? '' ) );

		return [
			'customer_name'     => (string) ( $booking->customer_name ?? '' ),
			'customer_email'    => (string) ( $booking->customer_email ?? '' ),
			'customer_phone'    => (string) ( $booking->customer_phone ?? '' ),
			'booking_ref'       => (string) ( $booking->booking_ref ?? '' ),
			'tour_name'         => (string) ( $booking->tour_name ?? '' ),
			'tour_date'         => ! empty( $booking->tour_date )
				? date_i18n( get_option( 'date_format', 'j \\d\\e F \\d\\e Y' ), strtotime( $booking->tour_date ) )
				: '',
			'tour_time'         => (string) ( $booking->time_start ?? '' ),
			'meeting_point'     => (string) ( $booking->meeting_point_es ?? $booking->meeting_point_en ?? '' ),
			'total'             => \AmirBooking\Core\Currency::format( (float) ( $booking->charge_mxn ?? $booking->total_mxn ?? 0 ) ),
			'people_count'      => (string) $pax,
			'special_requests'  => (string) ( $booking->special_requests ?? '' ),
			'company_name'      => (string) $company,
			'brand_color'       => (string) get_option( 'amir_brand_color', '#1D9E75' ),
			'pdf_url'           => esc_url( $pdf_url ),
			'verify_url'        => esc_url( $email ? $email->verify_url() : '#' ),
			'calendar_url'      => esc_url( $email ? $email->calendar_url() : '#' ),
			'maps_url'          => esc_url( $email ? $email->maps_url() : '#' ),
			'whatsapp_url'      => $wa ? esc_url( 'https://wa.me/' . preg_replace( '/\D/', '', $wa ) ) : '',
			'logo_url'          => esc_url( get_option( 'amir_brand_logo_url', '' ) ),
		];
	}

	/** Reserva ficticia para el botón "Enviar email de prueba" — misma forma que get_booking_with_tour(), sin tocar la DB. */
	public static function sample_booking(): object {
		return (object) [
			'customer_name'    => 'Juana Pérez',
			'customer_email'   => 'juana@example.com',
			'customer_phone'   => '+52 998 123 4567',
			'booking_ref'      => 'TF-2026-00042',
			'access_token'     => 'sample-token-para-la-prueba',
			'tour_name'        => 'Snorkel en la Laguna',
			'tour_date'        => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
			'time_start'       => '09:00',
			'meeting_point_es' => 'Muelle principal, Bacalar',
			'meeting_point_en' => 'Main pier, Bacalar',
			'charge_mxn'       => 1500.00,
			'total_mxn'        => 1500.00,
			'adults'           => 2,
			'children'         => 1,
			'babies'           => 0,
			'special_requests' => 'Uno de los adultos no sabe nadar.',
			'lang'             => 'es',
		];
	}
}
