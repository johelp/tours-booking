<?php
namespace TourFlowCustomEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Se engancha al único punto de extensión que necesita (amir_email_render_html,
 * agregado al núcleo el 2026-11-16 específicamente para este satélite — ver
 * BaseEmail::send() en includes/emails/class-email-dispatcher.php). Si no
 * hay plantilla propia cargada para ese tipo de email, devuelve el HTML
 * original sin tocar — un sitio sin configurar acá no nota la diferencia.
 */
final class Template {

	public static function register(): void {
		add_filter( 'amir_email_render_html', [ __CLASS__, 'maybe_render' ], 10, 4 );
	}

	/**
	 * @param string $html    HTML que el núcleo ya armó (el default si no reemplazamos nada).
	 * @param object $booking Fila de amir_bookings (+ columnas de tour/horario ya unidas).
	 * @param string $type    'confirmation', 'reminder', etc. — ver Types::all() para los que tienen UI.
	 * @param object $email   Instancia de la clase de email en curso (BaseEmail).
	 */
	public static function maybe_render( string $html, object $booking, string $type, object $email ): string {
		$template = Settings::template_for( $type, (string) ( $booking->lang ?? '' ) );
		if ( $template === '' ) {
			return $html;
		}

		$vars = Vars::build( $booking, $email );
		return Renderer::render( $template, $vars );
	}
}
