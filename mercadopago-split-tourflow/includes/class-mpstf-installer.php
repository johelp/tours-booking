<?php
namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Tablas propias del satélite — nunca en el prefijo amir_* (§ 6
 * GUIA-PLUGINS-SATELITE-TOURFLOW.md). Ninguna de las dos tablas se toca
 * desde el núcleo; el núcleo ni sabe que existen.
 *
 * mpstf_provider_accounts: una fila por proveedor que INTENTÓ conectar su
 * cuenta de Mercado Pago (no solo los ya conectados — así "Generar link de
 * conexión" siempre tiene un connect_token fresco para validar el callback
 * OAuth). status: 'pending' (link generado, todavía sin volver de MP),
 * 'connected' (access_token/refresh_token válidos), 'disconnected' (el
 * operador lo desconectó a mano, o MP revocó el acceso).
 *
 * mpstf_split_payments: mapea cada reserva que SÍ se cobró con split (no
 * todas — las que cayeron al flujo normal por carrito/proveedor sin
 * conectar no generan fila acá) a su proveedor y a los ids de Mercado Pago.
 * Existe porque PaymentGatewayInterface::refund()/fetch_payment_status()
 * reciben solo booking_ref/charge_reference, nunca el booking completo —
 * sin esta tabla no hay forma de saber con el token de QUÉ proveedor hay
 * que llamar a la API en esos dos métodos.
 */
class Installer {

	private const DB_VERSION_OPTION = 'mpstf_db_version';
	private const DB_VERSION        = '1.0.0';

	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}mpstf_provider_accounts (
			id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id    INT UNSIGNED NOT NULL,
			status         ENUM('pending','connected','disconnected') NOT NULL DEFAULT 'pending',
			connect_token  VARCHAR(64) DEFAULT NULL,
			mp_user_id     VARCHAR(64) NOT NULL DEFAULT '',
			access_token   TEXT,
			refresh_token  TEXT,
			expires_at     DATETIME DEFAULT NULL,
			created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			connected_at   DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY provider_id (provider_id),
			KEY connect_token (connect_token),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}mpstf_split_payments (
			id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id     INT UNSIGNED NOT NULL,
			booking_ref    VARCHAR(20) NOT NULL,
			provider_id    INT UNSIGNED NOT NULL,
			preference_id  VARCHAR(64) NOT NULL DEFAULT '',
			payment_id     VARCHAR(64) DEFAULT NULL,
			created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY booking_ref (booking_ref),
			KEY payment_id (payment_id),
			KEY provider_id (provider_id)
		) $charset;" );
	}
}
