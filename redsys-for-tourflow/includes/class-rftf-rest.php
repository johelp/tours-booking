<?php
namespace RedsysForTourFlow;

defined( 'ABSPATH' ) || exit;

use AmirBooking\Payments\PaymentEvent;
use AmirBooking\Payments\PaymentEventLogger;

/**
 * Ruta REST que recibe la notificación server-to-server de Redsys.
 * Equivalente al webhook de Stripe/MP — mismo patrón de
 * BookingController::handle_gateway_webhook() (ver GUIA-PLUGINS-SATELITE-
 * TOURFLOW.md § 3.3, ese método no es reusable directo porque asume headers
 * de Stripe/MP, así que este plugin replica la misma lógica de negocio).
 */
class Rest {

	public static function register_routes(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( 'redsys-for-tourflow/v1', '/notify', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_notification' ],
			'permission_callback' => '__return_true', // la autenticación real es la firma HMAC, no un nonce de WP
		] );
	}

	public static function handle_notification( \WP_REST_Request $request ): \WP_REST_Response {
		$gateway = new Gateway();
		$payload = $request->get_body();

		if ( ! $gateway->verify_webhook_signature( $payload, [] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 400 );
		}

		$event = $gateway->parse_webhook_event( $payload );
		if ( ! $event ) {
			return new \WP_REST_Response( [ 'received' => true ], 200 ); // notificación que no nos interesa procesar
		}

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}amir_bookings WHERE gateway_reference = %s",
			$event->gateway_reference
		) );

		if ( ! $booking ) {
			return new \WP_REST_Response( [ 'received' => true ], 200 );
		}

		PaymentEventLogger::log(
			(int) $booking->id, $gateway->id(), 'webhook_' . $event->type, $event->reason, $event->raw
		);

		switch ( $event->type ) {
			case PaymentEvent::SUCCEEDED:
				if ( $booking->status === 'pending' ) {
					( new \AmirBooking\Core\BookingManager() )->confirm( (int) $booking->id, $event->charge_reference );
				}
				break;

			case PaymentEvent::FAILED:
				if ( $booking->status === 'pending' ) {
					$wpdb->update(
						"{$wpdb->prefix}amir_bookings",
						[ 'status' => 'cancelled_client', 'internal_notes' => 'Pago fallido (Redsys): ' . $event->reason ],
						[ 'id' => $booking->id ],
						[ '%s', '%s' ],
						[ '%d' ]
					);
				}
				break;

			case PaymentEvent::REFUNDED:
				do_action( 'amir_gateway_refund_completed', $gateway->id(), $event->raw, (int) $booking->id );
				break;
		}

		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}
}
