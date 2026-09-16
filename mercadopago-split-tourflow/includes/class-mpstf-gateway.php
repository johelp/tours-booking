<?php
namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Split payment 1:1 de Mercado Pago — implementa PaymentGatewayInterface
 * del núcleo. id() = 'mercadopago_split', nunca pisa 'mercadopago' (esa
 * sigue siendo la cuenta del operador, y ESTE gateway la usa como fallback
 * — ver create_payment()).
 *
 * Regla central, confirmada contra la documentación real de MP 2026-09-10
 * (ver PROMPT-MERCADOPAGO-SPLIT.md § 7): el split solo aplica a una reserva
 * de UN tour de UN proveedor que conectó su cuenta. Cualquier otro caso
 * (carrito — booking_ref empieza con 'CART-', el modelo 1:N no es
 * self-service —, habitación/producto sin proveedor, tour sin proveedor,
 * proveedor sin conectar, o token vencido sin poder refrescarse) cae
 * DIRECTO al MercadoPagoGateway del núcleo — el operador cobra 100% y
 * liquida a mano, exactamente como si este satélite no existiera. Nunca se
 * bloquea un pago por esto.
 */
class Gateway implements \AmirBooking\Payments\PaymentGatewayInterface {

	private const API = 'https://api.mercadopago.com';

	public function id(): string {
		return 'mercadopago_split';
	}

	public function is_configured(): bool {
		// Este gateway no tiene cuenta propia — la "configuración" real que
		// necesita para poder cobrar ALGO es que el fallback del núcleo
		// esté configurado (si ni eso está, ninguna reserva sin split
		// conectado va a poder pagar).
		return ( new \AmirBooking\Payments\MercadoPagoGateway() )->is_configured();
	}

	// ── Crear cobro ──────────────────────────────────────────────────────

	public function create_payment( \AmirBooking\Core\BookingResult $booking ): \AmirBooking\Payments\PaymentCreationResult {
		$account = $this->resolve_connected_provider( $booking );
		if ( ! $account ) {
			return $this->fallback()->create_payment( $booking );
		}

		$token = $this->valid_access_token( $account );
		if ( ! $token ) {
			// Proveedor revocó el acceso o el refresh falló — no dejar al
			// cliente sin poder pagar, el operador se entera después por el
			// log de eventos y puede pedirle al proveedor que reconecte.
			\AmirBooking\Payments\PaymentEventLogger::log(
				$booking->booking_id, $this->id(), 'split_token_invalid',
				'Token del proveedor inválido/vencido al crear el cobro — se usó el flujo normal (cobro 100% al operador).'
			);
			return $this->fallback()->create_payment( $booking );
		}

		$currency = strtoupper( get_option( 'amir_currency', 'MXN' ) );
		$company  = get_option( 'amir_company_name', 'TourFlow' );
		$fee      = round( (float) $booking->charge_mxn * ( Settings::marketplace_fee_pct() / 100 ), 2 );

		$body = [
			'items' => [ [
				'title'       => $company . ' — ' . $booking->booking_ref,
				'quantity'    => 1,
				'unit_price'  => round( (float) $booking->charge_mxn, 2 ),
				'currency_id' => $currency,
			] ],
			// Confirmado: para Checkout Pro va en el body de /checkout/preferences,
			// autenticado con el access_token del VENDEDOR (el proveedor), no el
			// del marketplace — ver Authorization header más abajo.
			'marketplace_fee'    => $fee,
			'external_reference' => $booking->booking_ref,
			'back_urls'          => [
				'success' => home_url( '/' ),
				'pending' => home_url( '/' ),
				'failure' => home_url( '/' ),
			],
			'auto_return'      => 'approved',
			'notification_url' => rest_url( 'mpstf/v1/webhook' ), // propio, no el del núcleo — ver class-mpstf-rest.php
			'metadata'          => [
				'booking_id'  => $booking->booking_id,
				'booking_ref' => $booking->booking_ref,
				'provider_id' => (int) $account->provider_id,
			],
		];

		$response = wp_remote_post( self::API . '/checkout/preferences', [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return \AmirBooking\Payments\PaymentCreationResult::error( $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return \AmirBooking\Payments\PaymentCreationResult::error( $data['message'] ?? 'Error de Mercado Pago (split)' );
		}

		ProviderAccounts::record_split_payment(
			$booking->booking_id, $booking->booking_ref, (int) $account->provider_id, (string) $data['id']
		);

		return \AmirBooking\Payments\PaymentCreationResult::success( $booking->booking_ref, [
			'preference_id'      => $data['id'],
			'init_point'         => $data['init_point'] ?? '',
			'sandbox_init_point' => $data['sandbox_init_point'] ?? '',
		] );
	}

	// ── Webhook — firma y parseo (la ruta REST es propia, ver class-mpstf-rest.php) ──

	public function verify_webhook_signature( string $payload, array $headers ): bool {
		// Protocolo genérico de MP (HMAC del manifest id/request-id/ts), no
		// lógica de negocio — cada gateway lo implementa por su cuenta según
		// el contrato de la interfaz (MercadoPagoGateway del núcleo tiene el
		// mismo código, replicado a propósito). El secret es el de ESTA app
		// (Settings::webhook_secret()), configurado una sola vez — no varía
		// por proveedor aunque el pago se haya acreditado en su cuenta.
		$secret     = Settings::webhook_secret();
		$sig_header = $headers['x-signature'] ?? '';
		$request_id = $headers['x-request-id'] ?? '';

		if ( empty( $secret ) || empty( $sig_header ) ) {
			return false;
		}

		$parts = [];
		foreach ( explode( ',', $sig_header ) as $pair ) {
			$kv = explode( '=', $pair, 2 );
			if ( count( $kv ) === 2 ) {
				$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}
		$ts = $parts['ts'] ?? '';
		$v1 = $parts['v1'] ?? '';
		if ( $ts === '' || $v1 === '' || ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > 300 ) {
			return false;
		}

		$data    = json_decode( $payload, true );
		$data_id = $data['data']['id'] ?? '';
		if ( $data_id === '' ) {
			return false;
		}

		$manifest = "id:{$data_id};request-id:{$request_id};ts:{$ts};";
		return hash_equals( hash_hmac( 'sha256', $manifest, $secret ), $v1 );
	}

	public function parse_webhook_event( string $payload ): ?\AmirBooking\Payments\PaymentEvent {
		$data       = json_decode( $payload, true );
		$type       = (string) ( $data['type'] ?? ( $data['action'] ?? '' ) );
		$payment_id = (string) ( $data['data']['id'] ?? '' );

		if ( $payment_id === '' || strpos( $type, 'payment' ) === false ) {
			return null;
		}

		$payment = $this->fetch_payment_with_best_token( $payment_id );
		if ( ! $payment ) {
			return null;
		}

		$status  = $payment['status'] ?? '';
		$ext_ref = (string) ( $payment['external_reference'] ?? '' );
		if ( $ext_ref === '' ) {
			return null;
		}

		ProviderAccounts::mark_payment_id( $ext_ref, $payment_id );

		if ( $status === 'approved' ) {
			return new \AmirBooking\Payments\PaymentEvent( \AmirBooking\Payments\PaymentEvent::SUCCEEDED, $ext_ref, $payment_id, '', $payment );
		}
		if ( in_array( $status, [ 'rejected', 'cancelled' ], true ) ) {
			return new \AmirBooking\Payments\PaymentEvent( \AmirBooking\Payments\PaymentEvent::FAILED, $ext_ref, '', $payment['status_detail'] ?? $status, $payment );
		}
		return null;
	}

	public function fetch_payment_status( string $reference ): ?\AmirBooking\Payments\PaymentStatusResult {
		$split = ProviderAccounts::find_split_payment_by_booking_ref( $reference );
		if ( ! $split ) {
			return $this->fallback()->fetch_payment_status( $reference );
		}

		$account = ProviderAccounts::get_connected( (int) $split->provider_id );
		$token   = $account ? $this->valid_access_token( $account ) : '';
		if ( ! $token ) {
			return null;
		}

		$response = wp_remote_get(
			self::API . '/v1/payments/search?external_reference=' . rawurlencode( $reference ) . '&sort=date_created&criteria=desc',
			[ 'headers' => [ 'Authorization' => 'Bearer ' . $token ], 'timeout' => 15 ]
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$results = $data['results'] ?? [];
		if ( empty( $results ) ) {
			return null;
		}

		$payment = $results[0];
		$status  = $payment['status'] ?? '';
		ProviderAccounts::mark_payment_id( $reference, (string) ( $payment['id'] ?? '' ) );

		if ( $status === 'approved' ) {
			return new \AmirBooking\Payments\PaymentStatusResult( \AmirBooking\Payments\PaymentStatusResult::SUCCEEDED, (string) ( $payment['id'] ?? '' ) );
		}
		if ( in_array( $status, [ 'rejected', 'cancelled' ], true ) ) {
			return new \AmirBooking\Payments\PaymentStatusResult( \AmirBooking\Payments\PaymentStatusResult::FAILED );
		}
		return new \AmirBooking\Payments\PaymentStatusResult( \AmirBooking\Payments\PaymentStatusResult::PENDING );
	}

	// ── Reembolsos ───────────────────────────────────────────────────────

	public function refund( string $charge_reference, float $amount_mxn ): bool {
		$split = ProviderAccounts::find_split_payment_by_payment_id( $charge_reference );
		if ( ! $split ) {
			// No es un pago de split (se cobró por el fallback) — el núcleo
			// ya sabe reembolsar eso solo.
			return $this->fallback()->refund( $charge_reference, $amount_mxn );
		}

		$account = ProviderAccounts::get_connected( (int) $split->provider_id );
		$token   = $account ? $this->valid_access_token( $account ) : '';
		if ( ! $token ) {
			\AmirBooking\Payments\PaymentEventLogger::log(
				(int) $split->booking_id, $this->id(), 'refund_failed_split',
				'No se pudo reembolsar: el proveedor no tiene una cuenta de Mercado Pago conectada/con token válido en este momento.'
			);
			return false;
		}

		$response = wp_remote_post( self::API . "/v1/payments/{$charge_reference}/refunds", [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [ 'amount' => round( $amount_mxn, 2 ) ] ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			\AmirBooking\Payments\PaymentEventLogger::log(
				(int) $split->booking_id, $this->id(), 'refund_failed_split', $response->get_error_message()
			);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		// Confirmado en la documentación real de MP: en el modelo 1:1 el
		// marketplace NO puede forzar el reembolso si el proveedor no tiene
		// saldo suficiente en su cuenta — este es justamente ese caso más
		// probable. No fallar en silencio ni fingir éxito (decisión
		// confirmada con el cliente 2026-09-10): queda logueado con el
		// detalle real de MP para que el operador pueda coordinar el
		// reembolso con el proveedor por fuera del sistema. El núcleo
		// (amir_process_gateway_refund en class-plugin.php) ya loguea
		// 'refund_failed' genérico cuando esto devuelve false — este log
		// adicional es el que trae el motivo real.
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		\AmirBooking\Payments\PaymentEventLogger::log(
			(int) $split->booking_id, $this->id(), 'refund_failed_split',
			'Mercado Pago rechazó el reembolso (posible saldo insuficiente del proveedor): ' . ( $body['message'] ?? "HTTP {$code}" ),
			[ 'charge_reference' => $charge_reference, 'http_code' => $code, 'response' => $body ]
		);
		return false;
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private function fallback(): \AmirBooking\Payments\MercadoPagoGateway {
		return new \AmirBooking\Payments\MercadoPagoGateway();
	}

	/**
	 * null si: es un carrito (CART-, 1:N no self-service), la reserva es de
	 * habitación/producto (providers solo existen para tours), el tour no
	 * tiene proveedor, o el proveedor no conectó su cuenta.
	 */
	private function resolve_connected_provider( \AmirBooking\Core\BookingResult $booking ): ?object {
		if ( strpos( $booking->booking_ref, 'CART-' ) === 0 ) {
			return null;
		}

		global $wpdb;
		$tour_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT tour_id FROM {$wpdb->prefix}amir_bookings WHERE id = %d", $booking->booking_id
		) );
		if ( ! $tour_id ) {
			return null;
		}

		$provider_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT provider_id FROM {$wpdb->prefix}amir_tours WHERE id = %d", $tour_id
		) );
		if ( ! $provider_id ) {
			return null;
		}

		return ProviderAccounts::get_connected( (int) $provider_id );
	}

	/** Access token vigente, refrescándolo si venció — '' si el refresh falla. */
	private function valid_access_token( object $account ): string {
		if ( ! empty( $account->expires_at ) && strtotime( $account->expires_at ) > time() + 60 ) {
			return (string) $account->access_token;
		}

		$refreshed = OAuth::refresh_token( (string) $account->refresh_token );
		if ( ! $refreshed ) {
			return '';
		}

		ProviderAccounts::update_tokens( (int) $account->id, $refreshed['access_token'], $refreshed['refresh_token'], $refreshed['expires_in'] );
		return $refreshed['access_token'];
	}

	/**
	 * Sin confirmar contra un pago real todavía (ver PROMPT-MERCADOPAGO-SPLIT.md
	 * § 7.3): la documentación pública de MP no deja claro con el token de
	 * QUIÉN se puede leer /v1/payments/{id} de un pago que se acreditó en la
	 * cuenta de un vendedor secundario. Se intenta primero con el token de
	 * la app (varias integraciones de split reportan que alcanza, al ser la
	 * app "colaboradora" del pago) y si no devuelve nada, se prueba con el
	 * token de cada proveedor conectado hasta encontrar el dueño real —
	 * fanout chico en la práctica (un puñado de proveedores conectados por
	 * instalación), aceptable para un webhook de baja frecuencia.
	 */
	private function fetch_payment_with_best_token( string $payment_id ): ?array {
		$app_token = $this->app_level_token();
		if ( $app_token ) {
			$payment = $this->fetch_payment( $payment_id, $app_token );
			if ( $payment ) {
				return $payment;
			}
		}

		foreach ( ProviderAccounts::all_connected() as $account ) {
			$token = $this->valid_access_token( $account );
			if ( ! $token ) {
				continue;
			}
			$payment = $this->fetch_payment( $payment_id, $token );
			if ( $payment ) {
				return $payment;
			}
		}

		return null;
	}

	/** El token del propio operador (cuenta configurada en el núcleo) sirve como "token de la app" para el intento de lectura de § arriba. */
	private function app_level_token(): string {
		$mode = get_option( 'amir_mp_mode', 'test' );
		return (string) get_option( "amir_mp_access_token_{$mode}", '' );
	}

	private function fetch_payment( string $payment_id, string $token ): ?array {
		if ( empty( $token ) ) {
			return null;
		}
		$response = wp_remote_get( self::API . "/v1/payments/{$payment_id}", [
			'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			'timeout' => 15,
		] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && isset( $data['id'] ) ? $data : null;
	}
}
