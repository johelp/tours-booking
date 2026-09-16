<?php
namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD de mpstf_provider_accounts / mpstf_split_payments — toda la lectura y
 * escritura de las tablas propias del satélite pasa por acá (Gateway, la
 * pantalla de admin y el callback OAuth no tocan $wpdb directo).
 */
class ProviderAccounts {

	/** Fila de cuenta conectada (status='connected') para un proveedor, o null. */
	public static function get_connected( int $provider_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}mpstf_provider_accounts WHERE provider_id = %d AND status = 'connected'",
			$provider_id
		) );
		return $row ?: null;
	}

	/** Todas las cuentas conectadas — usado por Gateway::parse_webhook_event() para el fallback de § 7.3. */
	public static function all_connected(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mpstf_provider_accounts WHERE status = 'connected'" ) ?: [];
	}

	/** Fila cruda (cualquier status) — para la pantalla de admin. */
	public static function get_for_provider( int $provider_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}mpstf_provider_accounts WHERE provider_id = %d",
			$provider_id
		) );
		return $row ?: null;
	}

	/**
	 * Genera (o regenera) el link de autorización para un proveedor —
	 * siempre deja la fila en 'pending' con un connect_token nuevo, así un
	 * link viejo sin usar queda inválido en cuanto se genera uno nuevo.
	 */
	public static function generate_connect_link( int $provider_id ): string {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );

		$existing = self::get_for_provider( $provider_id );
		if ( $existing ) {
			$wpdb->update(
				"{$wpdb->prefix}mpstf_provider_accounts",
				[ 'status' => 'pending', 'connect_token' => $token ],
				[ 'provider_id' => $provider_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert(
				"{$wpdb->prefix}mpstf_provider_accounts",
				[ 'provider_id' => $provider_id, 'status' => 'pending', 'connect_token' => $token ],
				[ '%d', '%s', '%s' ]
			);
		}

		return OAuth::build_authorization_url( $token );
	}

	public static function find_pending_by_connect_token( string $token ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}mpstf_provider_accounts WHERE connect_token = %s AND status = 'pending'",
			$token
		) );
		return $row ?: null;
	}

	public static function mark_connected( int $id, string $mp_user_id, string $access_token, string $refresh_token, int $expires_in ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}mpstf_provider_accounts",
			[
				'status'        => 'connected',
				'connect_token' => null,
				'mp_user_id'    => $mp_user_id,
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
				'connected_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function update_tokens( int $id, string $access_token, string $refresh_token, int $expires_in ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}mpstf_provider_accounts",
			[
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function disconnect( int $provider_id ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}mpstf_provider_accounts",
			[ 'status' => 'disconnected', 'access_token' => '', 'refresh_token' => '' ],
			[ 'provider_id' => $provider_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	// ── mpstf_split_payments ────────────────────────────────────────────────

	public static function record_split_payment( int $booking_id, string $booking_ref, int $provider_id, string $preference_id ): void {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}mpstf_split_payments",
			[
				'booking_id'    => $booking_id,
				'booking_ref'   => $booking_ref,
				'provider_id'   => $provider_id,
				'preference_id' => $preference_id,
			],
			[ '%d', '%s', '%d', '%s' ]
		);
	}

	public static function find_split_payment_by_booking_ref( string $booking_ref ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}mpstf_split_payments WHERE booking_ref = %s", $booking_ref
		) );
		return $row ?: null;
	}

	public static function find_split_payment_by_payment_id( string $payment_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}mpstf_split_payments WHERE payment_id = %s", $payment_id
		) );
		return $row ?: null;
	}

	public static function mark_payment_id( string $booking_ref, string $payment_id ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}mpstf_split_payments",
			[ 'payment_id' => $payment_id ],
			[ 'booking_ref' => $booking_ref ],
			[ '%s' ],
			[ '%s' ]
		);
	}
}
