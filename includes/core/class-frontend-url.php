<?php
namespace AmirBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL pública "de cara al cliente" para links en emails/vouchers/PDFs.
 *
 * get_site_url() apunta siempre al dominio de WordPress — correcto cuando
 * WordPress también sirve el sitio público, pero no en una instalación
 * headless (ej. Caliafarm: backend en un dominio, frontend propio en otro,
 * ver CORS más abajo en Configuración) donde el cliente final nunca debe
 * aterrizar en el backend. Si amir_public_site_url está configurado, se usa
 * ese dominio; si no, cae a get_site_url() como siempre (instalaciones
 * clásicas, sin frontend propio).
 */
class FrontendUrl {

	public static function base(): string {
		$configured = trim( (string) get_option( 'amir_public_site_url', '' ) );
		return $configured !== '' ? rtrim( $configured, '/' ) : get_site_url();
	}
}
