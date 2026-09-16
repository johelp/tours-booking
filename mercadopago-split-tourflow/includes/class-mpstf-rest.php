<?php
namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Dos rutas propias — nunca las del núcleo:
 * - /mpstf/v1/oauth-callback: adonde MP redirige al proveedor después de
 *   autorizar. Público (el proveedor nunca tiene una sesión de WP).
 * - /mpstf/v1/webhook: notificación de pago de MP para cobros de split.
 *   Deliberadamente NO se reusa /amir/v1/bookings/mercadopago-webhook del
 *   núcleo — ese endpoint instancia MercadoPagoGateway del núcleo
 *   directo (class-booking-controller.php::mercadopago_webhook()), no pasa
 *   por PaymentGatewayFactory, así que nunca resolvería a este gateway (§
 *   3.3 GUIA-PLUGINS-SATELITE-TOURFLOW.md: "el webhook es tuyo").
 */
class Rest {

	public static function register_routes(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( 'mpstf/v1', '/oauth-callback', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'oauth_callback' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'mpstf/v1', '/webhook', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'webhook' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function oauth_callback( \WP_REST_Request $request ): \WP_HTTP_Response {
		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		if ( $code === '' || $state === '' ) {
			return self::html_response( '❌ Faltan parámetros en la respuesta de Mercado Pago. Pedí un link nuevo.', 400 );
		}

		$pending = ProviderAccounts::find_pending_by_connect_token( $state );
		if ( ! $pending ) {
			return self::html_response( '❌ Este link ya no es válido (venció o se generó uno nuevo). Pedí un link nuevo al operador.', 400 );
		}

		$tokens = OAuth::exchange_code_for_token( $code );
		if ( ! $tokens ) {
			return self::html_response( '❌ Mercado Pago no pudo confirmar la autorización. Intentá de nuevo con un link nuevo.', 502 );
		}

		ProviderAccounts::mark_connected(
			(int) $pending->id, $tokens['user_id'], $tokens['access_token'], $tokens['refresh_token'], $tokens['expires_in']
		);

		return self::html_response( '✅ Cuenta de Mercado Pago conectada correctamente. Ya podés cerrar esta ventana.', 200 );
	}

	private static function html_response( string $message, int $status ): \WP_HTTP_Response {
		$response = new \WP_HTTP_Response(
			'<!doctype html><html><body style="font-family:sans-serif;text-align:center;padding:60px 20px;">' . esc_html( $message ) . '</body></html>',
			$status
		);
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		return $response;
	}

	public static function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_body();
		$headers = [
			'x-signature'  => $_SERVER['HTTP_X_SIGNATURE'] ?? '',
			'x-request-id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
		];

		$gateway = new Gateway();
		if ( ! $gateway->verify_webhook_signature( $payload, $headers ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 400 );
		}

		$event = $gateway->parse_webhook_event( $payload );
		if ( ! $event ) {
			return new \WP_REST_Response( [ 'received' => true ], 200 );
		}

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}amir_bookings WHERE gateway_reference = %s",
			$event->gateway_reference
		) );
		if ( ! $booking ) {
			return new \WP_REST_Response( [ 'received' => true ], 200 );
		}

		\AmirBooking\Payments\PaymentEventLogger::log(
			(int) $booking->id, $gateway->id(), 'webhook_' . $event->type, $event->reason, $event->raw
		);

		// Mismo patrón exacto que BookingController::handle_gateway_webhook()
		// del núcleo (class-booking-controller.php) — reusando BookingManager
		// en vez de tocar amir_bookings con SQL propio, así los hooks de
		// ciclo de vida (email, voucher, Google Calendar) siguen andando
		// igual que con Stripe/MP normal (§ 5 GUIA-PLUGINS-SATELITE-TOURFLOW.md).
		if ( $event->type === \AmirBooking\Payments\PaymentEvent::SUCCEEDED && $booking->status === 'pending' ) {
			( new \AmirBooking\Core\BookingManager() )->confirm( (int) $booking->id, $event->charge_reference );
		} elseif ( $event->type === \AmirBooking\Payments\PaymentEvent::FAILED && $booking->status === 'pending' ) {
			$wpdb->update(
				"{$wpdb->prefix}amir_bookings",
				[ 'status' => 'cancelled_client', 'internal_notes' => 'Pago fallido (split): ' . $event->reason ],
				[ 'id' => $booking->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}
}
