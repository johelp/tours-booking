<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints públicos para los formularios de Contacto y Grupos privados del
 * frontend headless (caliafarm-web, Cloudflare Pages) — pedido del cliente
 * 2026-09-08: reusar el SMTP ya configurado en este hosting de WordPress
 * (wp_mail()) en vez de dar de alta un proveedor de email nuevo solo para
 * estos dos formularios. Mismo destinatario que ya usan las notificaciones
 * de reserva (amir_admin_email, con fallback al admin_email del sitio) —
 * no agrega ninguna opción nueva.
 *
 * POST /amir/v1/contact          → formulario de contacto general
 * POST /amir/v1/groups-inquiry   → solicitud de reserva de grupo privado
 */
class ContactController {

	private const NAMESPACE = 'amir/v1';

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/contact', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'contact' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/groups-inquiry', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'groups_inquiry' ],
			'permission_callback' => '__return_true',
		] );
	}

	private function recipient(): string {
		return (string) get_option( 'amir_admin_email', get_option( 'admin_email' ) );
	}

	private function reply_to( string $name, string $email ): array {
		return [ 'Reply-To: ' . $name . ' <' . $email . '>' ];
	}

	public function contact( \WP_REST_Request $request ): \WP_REST_Response {
		if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'contact_' . \AmirBooking\Core\RateLimiter::client_ip(), 10, 600 ) ) {
			return new \WP_REST_Response( [ 'error' => 'Too many attempts. Please try again in a few minutes.' ], 429 );
		}

		$name    = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
		$email   = sanitize_email( $request->get_param( 'email' ) ?? '' );
		$phone   = sanitize_text_field( $request->get_param( 'phone' ) ?? '' );
		$message = sanitize_textarea_field( $request->get_param( 'message' ) ?? '' );

		if ( ! $name || ! is_email( $email ) || ! $message ) {
			return new \WP_REST_Response( [ 'error' => 'missing_fields' ], 422 );
		}

		$to = $this->recipient();
		if ( ! $to ) {
			return new \WP_REST_Response( [ 'error' => 'not_configured' ], 500 );
		}

		$subject = sprintf( '[Caliafarm] New contact message from %s', $name );
		$body    = sprintf(
			"Name: %s\nEmail: %s\nPhone: %s\n\nMessage:\n%s",
			$name, $email, $phone ?: '—', $message
		);

		$sent = wp_mail( $to, $subject, $body, $this->reply_to( $name, $email ) );
		if ( ! $sent ) {
			error_log( 'Caliafarm contact form: wp_mail() failed to send to ' . $to );
			return new \WP_REST_Response( [ 'error' => 'send_failed' ], 502 );
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function groups_inquiry( \WP_REST_Request $request ): \WP_REST_Response {
		if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'groups_inquiry_' . \AmirBooking\Core\RateLimiter::client_ip(), 10, 600 ) ) {
			return new \WP_REST_Response( [ 'error' => 'Too many attempts. Please try again in a few minutes.' ], 429 );
		}

		$name             = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
		$email            = sanitize_email( $request->get_param( 'email' ) ?? '' );
		$phone            = sanitize_text_field( $request->get_param( 'phone' ) ?? '' );
		$group_size       = sanitize_text_field( $request->get_param( 'groupSize' ) ?? '' );
		$preferred_date   = sanitize_text_field( $request->get_param( 'preferredDate' ) ?? '' );
		$alternative_date = sanitize_text_field( $request->get_param( 'alternativeDate' ) ?? '' );
		$notes            = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );

		if ( ! $name || ! is_email( $email ) || ! $group_size || ! $preferred_date ) {
			return new \WP_REST_Response( [ 'error' => 'missing_fields' ], 422 );
		}

		$to = $this->recipient();
		if ( ! $to ) {
			return new \WP_REST_Response( [ 'error' => 'not_configured' ], 500 );
		}

		$subject = sprintf( '[Caliafarm] Private group inquiry from %s (%s people)', $name, $group_size );
		$body    = sprintf(
			"Name: %s\nEmail: %s\nPhone: %s\nGroup size: %s\nPreferred date: %s\nAlternative date: %s\n\nNotes:\n%s",
			$name, $email, $phone ?: '—', $group_size, $preferred_date, $alternative_date ?: '—', $notes ?: '—'
		);

		$sent = wp_mail( $to, $subject, $body, $this->reply_to( $name, $email ) );
		if ( ! $sent ) {
			error_log( 'Caliafarm groups inquiry: wp_mail() failed to send to ' . $to );
			return new \WP_REST_Response( [ 'error' => 'send_failed' ], 502 );
		}

		return rest_ensure_response( [ 'success' => true ] );
	}
}
