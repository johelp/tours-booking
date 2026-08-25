<?php
namespace RedsysForTourFlow;

defined( 'ABSPATH' ) || exit;

use AmirBooking\Payments\PaymentGatewayInterface;
use AmirBooking\Payments\PaymentCreationResult;
use AmirBooking\Payments\PaymentEvent;
use AmirBooking\Payments\PaymentStatusResult;

/**
 * Redsys — TPV Virtual por redirección (conexión clásica, no InSite).
 *
 * El flujo es distinto a Stripe/Mercado Pago: no hay una URL de checkout
 * hospedada para redirigir con un simple `window.location.href`. Redsys
 * exige un POST con 3 campos firmados (Ds_SignatureVersion,
 * Ds_MerchantParameters, Ds_Signature) directo a su endpoint — por eso
 * create_payment() no devuelve una URL, devuelve los 3 campos + la URL
 * destino, y el frontend arma y envía el form (ver StepPaymentRedsys en
 * BookingWidget.jsx). Todo lo demás (verificación de firma, notificación,
 * reembolso) sigue el mismo contrato que Stripe/MP.
 */
class Gateway implements PaymentGatewayInterface {

	private const SIGNATURE_VERSION = 'HMAC_SHA512_V2';
	private const TRANSACTION_AUTHORISATION = '0';
	private const TRANSACTION_REFUND        = '3';

	public function id(): string {
		return 'redsys';
	}

	public function is_configured(): bool {
		return Settings::is_configured();
	}

	// ── Crear cobro (form de redirección firmado) ──────────────────────────

	public function create_payment( \AmirBooking\Core\BookingResult $booking ): PaymentCreationResult {
		if ( ! Settings::is_configured() ) {
			return PaymentCreationResult::error( 'Redsys no configurado' );
		}

		$currency = \AmirBooking\Core\Currency::code();
		if ( ! Currency::is_supported( $currency ) ) {
			return PaymentCreationResult::error( "Redsys no soporta la moneda configurada ({$currency})" );
		}

		$company = (string) get_option( 'amir_company_name', 'TourFlow' );
		$order   = $this->generate_order_number( (int) $booking->booking_id );

		$params = [
			'DS_MERCHANT_AMOUNT'             => (string) Currency::to_minor_units( (float) $booking->charge_mxn ),
			'DS_MERCHANT_ORDER'              => $order,
			'DS_MERCHANT_MERCHANTCODE'       => Settings::fuc(),
			'DS_MERCHANT_CURRENCY'           => (string) Currency::numeric_code( $currency ),
			'DS_MERCHANT_TRANSACTIONTYPE'    => self::TRANSACTION_AUTHORISATION,
			'DS_MERCHANT_TERMINAL'           => Settings::terminal(),
			'DS_MERCHANT_MERCHANTURL'        => rest_url( 'redsys-for-tourflow/v1/notify' ),
			'DS_MERCHANT_URLOK'              => home_url( '/' ),
			'DS_MERCHANT_URLKO'              => home_url( '/' ),
			'DS_MERCHANT_PRODUCTDESCRIPTION' => mb_substr( $company . ' — ' . $booking->booking_ref, 0, 125 ),
			'DS_MERCHANT_MERCHANTNAME'       => mb_substr( $company, 0, 50 ),
			// Redundancia intencional con gateway_reference (que ya guarda el
			// núcleo vía $payment->reference): si algún día cambia el formato
			// del número de pedido, el webhook puede seguir encontrando la
			// reserva por acá sin depender de un solo campo.
			'DS_MERCHANT_MERCHANTDATA'       => wp_json_encode( [
				'booking_id'  => $booking->booking_id,
				'booking_ref' => $booking->booking_ref,
			] ),
		];

		$params_b64 = Signature::encode_params( $params );
		$signature  = Signature::sign( Settings::secret_key(), $params_b64, $order );

		return PaymentCreationResult::success( $order, [
			'redsys_action_url'          => Settings::redirect_endpoint(),
			'redsys_signature_version'   => self::SIGNATURE_VERSION,
			'redsys_merchant_parameters' => $params_b64,
			'redsys_signature'           => $signature,
		] );
	}

	// ── Notificación (equivalente al webhook de Stripe/MP) ─────────────────
	// Redsys manda la notificación como application/x-www-form-urlencoded,
	// no JSON — $payload es el body crudo, se parsea con parse_str().

	public function verify_webhook_signature( string $payload, array $headers ): bool {
		$fields = $this->parse_notification( $payload );
		if ( ! $fields ) {
			return false;
		}

		[ $params_b64, $signature, $params ] = $fields;
		$order = (string) ( $params['DS_ORDER'] ?? '' );
		if ( $order === '' ) {
			return false;
		}

		return Signature::verify( Settings::secret_key(), $params_b64, $order, $signature );
	}

	public function parse_webhook_event( string $payload ): ?PaymentEvent {
		$fields = $this->parse_notification( $payload );
		if ( ! $fields ) {
			return null;
		}

		[ , , $params ] = $fields;
		$order = (string) ( $params['DS_ORDER'] ?? '' );
		if ( $order === '' || ! isset( $params['DS_RESPONSE'] ) ) {
			return null;
		}

		// Mismo criterio que usa Redsys en todas sus integraciones oficiales:
		// un código de respuesta < 101 es una operación aprobada; >= 101 es
		// denegación/error (ver tabla de códigos del manual del TPV Virtual).
		$response_code    = (int) $params['DS_RESPONSE'];
		$transaction_type = (string) ( $params['DS_TRANSACTIONTYPE'] ?? self::TRANSACTION_AUTHORISATION );

		if ( $response_code < 101 ) {
			// Una devolución hecha desde el Portal de Administración del TPV
			// Virtual (no a través de refund() de este plugin) también llega
			// acá como notificación async — se distingue por el tipo de
			// transacción, no solo por el código de respuesta.
			if ( $transaction_type === self::TRANSACTION_REFUND ) {
				return new PaymentEvent( PaymentEvent::REFUNDED, $order, $order, '', $params );
			}
			return new PaymentEvent( PaymentEvent::SUCCEEDED, $order, $order, '', $params );
		}

		return new PaymentEvent( PaymentEvent::FAILED, $order, '', (string) $response_code, $params );
	}

	// ── Consulta de estado directa ──────────────────────────────────────────
	// A diferencia de Stripe/MP, el TPV Virtual básico (FUC+terminal+clave)
	// no expone una API de consulta de estado por número de pedido bajo
	// demanda — eso vive en una API distinta (acquirement/commerces-channel)
	// que pide credenciales adicionales (redsysClientId/redsysClientSecret)
	// que hoy no están confirmadas como parte del contrato del cliente. Se
	// devuelve null a propósito: el llamador no debe confirmar la reserva
	// sin datos reales, la notificación (arriba) sigue siendo la vía
	// autoritativa, igual que en Stripe/MP cuando su API no responde.
	public function fetch_payment_status( string $reference ): ?PaymentStatusResult {
		return null;
	}

	// ── Reembolsos (Web Service REST, server-to-server) ────────────────────

	public function refund( string $charge_reference, float $amount_mxn ): bool {
		if ( ! Settings::is_configured() || $charge_reference === '' ) {
			return false;
		}

		$currency = \AmirBooking\Core\Currency::code();
		if ( ! Currency::is_supported( $currency ) ) {
			return false;
		}

		$params = [
			'DS_MERCHANT_AMOUNT'          => (string) Currency::to_minor_units( $amount_mxn ),
			'DS_MERCHANT_ORDER'           => $charge_reference,
			'DS_MERCHANT_MERCHANTCODE'    => Settings::fuc(),
			'DS_MERCHANT_CURRENCY'        => (string) Currency::numeric_code( $currency ),
			'DS_MERCHANT_TRANSACTIONTYPE' => self::TRANSACTION_REFUND,
			'DS_MERCHANT_TERMINAL'        => Settings::terminal(),
		];

		$params_b64 = Signature::encode_params( $params );
		$signature  = Signature::sign( Settings::secret_key(), $params_b64, $charge_reference );

		$response = wp_remote_post( Settings::rest_treat_endpoint(), [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'Ds_SignatureVersion'   => self::SIGNATURE_VERSION,
				'Ds_MerchantParameters' => $params_b64,
				'Ds_Signature'          => $signature,
			] ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data          = json_decode( wp_remote_retrieve_body( $response ), true );
		$resp_params_b64 = $data['Ds_MerchantParameters'] ?? '';
		$resp_signature  = $data['Ds_Signature'] ?? '';
		if ( ! is_string( $resp_params_b64 ) || $resp_params_b64 === '' || ! is_string( $resp_signature ) ) {
			return false;
		}

		$resp_params = Signature::decode_params( $resp_params_b64 );
		if ( ! $resp_params ) {
			return false;
		}
		$resp_params = array_change_key_case( $resp_params, CASE_UPPER );
		$resp_order  = (string) ( $resp_params['DS_ORDER'] ?? $charge_reference );

		// No confiar en la respuesta sin verificar su firma — mismo criterio
		// que exige el resto del plugin para cualquier dato de una pasarela.
		if ( ! Signature::verify( Settings::secret_key(), $resp_params_b64, $resp_order, $resp_signature ) ) {
			return false;
		}

		$code = isset( $resp_params['DS_RESPONSE'] ) ? (int) $resp_params['DS_RESPONSE'] : null;
		return $code !== null && $code < 101;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/** @return array{0:string,1:string,2:array}|null [Ds_MerchantParameters cruda, firma, params decodificados con claves en mayúscula] */
	private function parse_notification( string $payload ): ?array {
		parse_str( $payload, $fields );

		$version    = $fields['Ds_SignatureVersion'] ?? '';
		$params_b64 = $fields['Ds_MerchantParameters'] ?? '';
		$signature  = $fields['Ds_Signature'] ?? '';

		if ( $version !== self::SIGNATURE_VERSION || $params_b64 === '' || $signature === '' ) {
			return null;
		}

		$params = Signature::decode_params( $params_b64 );
		if ( ! $params ) {
			return null;
		}

		// Redsys manda las claves de salida con distinta capitalización
		// según el canal (Ds_Order, DS_ORDER, etc.) — se normaliza acá una
		// sola vez en vez de repetir la duda en cada método.
		return [ $params_b64, $signature, array_change_key_case( $params, CASE_UPPER ) ];
	}

	private function generate_order_number( int $booking_id ): string {
		// Redsys exige 4-12 caracteres, los primeros 4 numéricos — se usa un
		// número de 10 dígitos (booking_id) + 2 dígitos de segundo actual
		// como sufijo, para que un reintento de pago sobre la misma reserva
		// (BookingController vuelve a llamar create_payment() si el intento
		// anterior no llegó a completarse) no repita el mismo número de
		// pedido, que Redsys podría rechazar por duplicado.
		return sprintf( '%010d%02d', $booking_id, (int) gmdate( 's' ) );
	}
}
