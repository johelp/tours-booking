<?php
namespace RedsysForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Firma HMAC_SHA512_V2 de Redsys — reimplementación propia a partir del
 * protocolo público (no copiada del módulo oficial de Redsys, ver diálogo de
 * la sesión que armó este plugin). Verificada contra tres fuentes
 * independientes: el código de referencia en redsyspur/lib/src/Signature.php,
 * la documentación oficial de Redsys ("Firmar una operación"), y la guía de
 * migración a HMAC SHA256/512.
 *
 * Paso a paso (importante: la clave de comercio NO se decodifica de Base64
 * antes de diversificar — se usan los primeros 16 caracteres del string tal
 * cual, no de los bytes decodificados; es una particularidad real del
 * protocolo de Redsys, no un error):
 *   1. Diversificar la clave: AES-128-CBC del número de pedido, con la clave
 *      de comercio (primeros 16 caracteres, rellenada con "0" si es más
 *      corta) y un IV de 16 bytes en cero. El resultado se codifica en
 *      Base64 estándar (con padding) — esa string es la "clave derivada".
 *   2. HMAC-SHA512 de Ds_MerchantParameters (ya en Base64URL) usando la
 *      clave derivada del paso 1.
 *   3. Codificar el HMAC resultante en Base64URL sin padding.
 */
class Signature {

	/** JSON → Base64URL sin padding, el formato que espera Ds_MerchantParameters. */
	public static function encode_params( array $params ): string {
		return self::base64_url_encode_safe( wp_json_encode( $params ) );
	}

	/** Decodifica Ds_MerchantParameters de vuelta a array. */
	public static function decode_params( string $merchant_parameters_b64 ): ?array {
		$json = self::base64_url_decode_safe( $merchant_parameters_b64 );
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	public static function sign( string $merchant_key, string $merchant_parameters_b64, string $order ): string {
		$derived_key = self::diversify_key( $merchant_key, $order );
		$hmac_raw    = hash_hmac( 'sha512', $merchant_parameters_b64, $derived_key, true );
		return self::base64_url_encode_safe( $hmac_raw );
	}

	public static function verify( string $merchant_key, string $merchant_parameters_b64, string $order, string $signature ): bool {
		$expected = self::sign( $merchant_key, $merchant_parameters_b64, $order );
		return hash_equals( $expected, $signature );
	}

	private static function diversify_key( string $merchant_key, string $diversifying_factor ): string {
		$aes_key   = str_pad( substr( $merchant_key, 0, 16 ), 16, '0' );
		$iv        = str_repeat( "\0", 16 );
		$encrypted = openssl_encrypt( $diversifying_factor, 'aes-128-cbc', $aes_key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $encrypted );
	}

	private static function base64_url_encode_safe( string $data ): string {
		return str_replace( '=', '', strtr( base64_encode( $data ), '+/', '-_' ) );
	}

	private static function base64_url_decode_safe( string $data ): string {
		$padded = str_pad( $data, strlen( $data ) + ( 4 - strlen( $data ) % 4 ) % 4, '=', STR_PAD_RIGHT );
		return base64_decode( strtr( $padded, '-_', '+/' ) );
	}
}
