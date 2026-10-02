<?php
namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Flujo OAuth authorization_code de Mercado Pago (confirmado contra
 * developers.mercadopago.com/.../security/oauth, 2026-09-10 — ver
 * PROMPT-MERCADOPAGO-SPLIT.md § 7): el PROVEEDOR autoriza entrando a SU
 * propia sesión de Mercado Pago, nunca ingresa nada acá — TourFlow solo
 * arma la URL de autorización y recibe el code en el callback.
 */
class OAuth {

	private const AUTH_URL  = 'https://auth.mercadopago.com/authorization';
	private const TOKEN_URL = 'https://api.mercadopago.com/oauth/token';

	/**
	 * $state = connect_token de la fila 'pending' en mpstf_provider_accounts
	 * — es lo único que nos permite, al volver del callback, saber a qué
	 * proveedor corresponde este code (MP no lo sabe ni le importa).
	 */
	public static function build_authorization_url( string $state ): string {
		return self::AUTH_URL . '?' . http_build_query( [
			'client_id'     => Settings::client_id(),
			'response_type' => 'code',
			'platform_id'   => 'mp',
			'state'         => $state,
			'redirect_uri'  => Settings::redirect_uri(),
		] );
	}

	/**
	 * Devuelve ['access_token','refresh_token','user_id','expires_in'] o
	 * null. expires_in confirmado en 180 días (flujo authorization_code) —
	 * por eso hace falta refresh_token(), un token de un solo login no
	 * alcanza para una integración que va a seguir cobrando meses después.
	 */
	public static function exchange_code_for_token( string $code ): ?array {
		$response = wp_remote_post( self::TOKEN_URL, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'client_id'     => Settings::client_id(),
				'client_secret' => Settings::client_secret(),
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => Settings::redirect_uri(),
			] ),
			'timeout' => 30,
		] );

		return self::parse_token_response( $response );
	}

	public static function refresh_token( string $refresh_token ): ?array {
		$response = wp_remote_post( self::TOKEN_URL, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'client_id'     => Settings::client_id(),
				'client_secret' => Settings::client_secret(),
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			] ),
			'timeout' => 30,
		] );

		return self::parse_token_response( $response );
	}

	private static function parse_token_response( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
			return null;
		}
		return [
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'],
			'user_id'       => (string) ( $data['user_id'] ?? '' ),
			'expires_in'    => (int) ( $data['expires_in'] ?? 15552000 ), // 180 días, fallback si MP no lo manda
		];
	}
}
