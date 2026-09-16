<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * CORS configurable para la REST API pública (amir/v1) — necesario para que
 * un frontend headless en otro dominio (SPEC-HEADLESS.md) pueda crear
 * reservas y cobrar sin que el navegador bloquee el preflight. A diferencia
 * del handler por defecto de WordPress (que refleja cualquier origen), acá
 * solo se permiten los orígenes cargados explícitamente en Configuración.
 */
final class Cors {

	public static function apply(): void {
		if ( '1' !== get_option( 'amir_cors_enabled', '1' ) ) {
			return;
		}

		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', [ self::class, 'send_headers' ], 10, 3 );
	}

	public static function send_headers( $served, $result, $request ) {
		$origin = get_http_origin();
		$route  = $request->get_route();

		// /amir/v1 (histórico) y /flow/v1 (habitaciones, § 16 CONTRIBUTING.md,
		// § 15.13) — mismo criterio de origen permitido para ambos namespaces.
		$is_public_api = 0 === strpos( $route, '/amir/v1' ) || 0 === strpos( $route, '/flow/v1' );

		if ( $origin && $is_public_api && self::is_allowed_origin( $origin ) ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type' );
			header( 'Access-Control-Allow-Credentials: false' );
			header( 'Vary: Origin', false );
		}

		return $served;
	}

	public static function is_allowed_origin( string $origin ): bool {
		return in_array( rtrim( $origin, '/' ), self::allowed_origins(), true );
	}

	/** @return string[] */
	public static function allowed_origins(): array {
		// Auditoría de ediciones (2026-08-16): el default acá era el dominio
		// real de un cliente puntual (casacalia.vercel.app), filtrado a
		// TODA instalación nueva del plugin — mismo patrón de bug ya
		// corregido antes para WhatsApp/ubicación/TripAdvisor hardcodeados
		// (§ 16.34 CONTRIBUTING.md). El propio docblock de esta clase dice
		// "solo se permiten los orígenes cargados explícitamente en
		// Configuración" — el default real tiene que ser vacío para que
		// eso sea cierto en una instalación nueva, no un dominio ajeno.
		$raw = json_decode( (string) get_option( 'amir_cors_allowed_origins', '[]' ), true );
		return is_array( $raw ) ? $raw : [];
	}
}
