<?php
namespace TourFlowBotConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Autoriza cada request del bot por API key (header X-TFBC-Api-Key, ver
 * Settings::HEADER_NAME) — decisión cerrada con el cliente 2026-10-03.
 * `hash_equals()` para evitar timing attacks, mismo criterio que
 * `BookingManager::authorize_public_access()` del núcleo.
 */
class Auth {

	public static function permission_callback( \WP_REST_Request $request ): bool {
		if ( ! Settings::is_enabled() ) {
			return false;
		}

		// Reusa el throttle del núcleo (no es lógica de negocio propia, es un
		// utility — GUIA-PLUGINS-SATELITE-TOURFLOW.md § 6 pide no duplicar
		// lógica, no evitar llamar a clases públicas del núcleo).
		if ( class_exists( '\AmirBooking\Core\RateLimiter' )
			&& \AmirBooking\Core\RateLimiter::too_many_attempts(
				'tfbc_' . \AmirBooking\Core\RateLimiter::client_ip(), 120, 300
			)
		) {
			return false;
		}

		$sent = (string) $request->get_header( Settings::HEADER_NAME );
		if ( $sent === '' ) {
			return false;
		}

		return hash_equals( Settings::api_key(), $sent );
	}
}
