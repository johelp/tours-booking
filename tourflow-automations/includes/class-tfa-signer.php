<?php
namespace TourFlowAutomations;

defined( 'ABSPATH' ) || exit;

/**
 * Firma HMAC-SHA256 de cada envío saliente.
 *
 * La firma cubre "timestamp.body", no solo el body: así quien recibe puede
 * rechazar un envío capturado y reenviado más tarde (replay), comparando el
 * timestamp contra su reloj. Documentado en GUIA-AUTOMATIZACIONES-TOURFLOW.md.
 *
 * Clase pura (sin WordPress) a propósito: es la parte que el receptor también
 * tiene que reproducir exactamente igual, así que conviene que sea trivial.
 */
final class Signer {

	public const HEADER_SIGNATURE = 'X-TourFlow-Signature';
	public const HEADER_TIMESTAMP = 'X-TourFlow-Timestamp';

	public static function sign( string $timestamp, string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Verificación del lado del receptor (misma lógica que va en la guía).
	 * Se incluye acá para que el test de sandbox use exactamente lo mismo.
	 */
	public static function verify( string $timestamp, string $body, string $secret, string $signature ): bool {
		return hash_equals( self::sign( $timestamp, $body, $secret ), $signature );
	}
}
