<?php
namespace TourFlowAutomations;

defined( 'ABSPATH' ) || exit;

/**
 * Log de envíos. Mismo espíritu que el Log de pagos del núcleo: un webhook
 * caído no puede fallar en silencio. Se recorta a las últimas N filas para no
 * crecer sin límite.
 */
final class Log {

	public const TABLE    = 'tfa_log';
	public const MAX_ROWS = 500;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::table() . " (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			endpoint_id  VARCHAR(40) NOT NULL DEFAULT '',
			event        VARCHAR(80) NOT NULL DEFAULT '',
			booking_id   INT UNSIGNED NOT NULL DEFAULT 0,
			attempt      TINYINT UNSIGNED NOT NULL DEFAULT 1,
			status       VARCHAR(20) NOT NULL DEFAULT '',
			http_code    SMALLINT NOT NULL DEFAULT 0,
			message      VARCHAR(255) NOT NULL DEFAULT '',
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY created_at (created_at)
		) $charset;" );
		update_option( 'tfa_db_version', TFA_DB_VERSION );
	}

	public static function add( string $endpoint_id, string $event, int $booking_id, int $attempt, string $status, int $http_code, string $message ): void {
		global $wpdb;
		$wpdb->insert( self::table(), [
			'endpoint_id' => substr( $endpoint_id, 0, 40 ),
			'event'       => substr( $event, 0, 80 ),
			'booking_id'  => $booking_id,
			'attempt'     => min( 255, $attempt ),
			'status'      => substr( $status, 0, 20 ),
			'http_code'   => $http_code,
			'message'     => substr( $message, 0, 255 ),
		] );

		// Recorte: conservar solo las últimas MAX_ROWS filas. Dos consultas
		// simples a propósito (un DELETE con subconsulta sobre la misma tabla
		// se comporta distinto entre MySQL 8 y MariaDB).
		$cutoff = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . self::table() . " ORDER BY id DESC LIMIT 1 OFFSET %d",
			self::MAX_ROWS
		) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM " . self::table() . " WHERE id <= %d",
				(int) $cutoff
			) );
		}
	}

	/** @return array<int,object> */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d",
			$limit
		) ) ?: [];
	}
}
