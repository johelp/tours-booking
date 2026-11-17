<?php
namespace TourFlowCustomEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Una sola pantalla: plantilla HTML completa del email de confirmación +
 * botón de prueba. Acciones procesadas y respondidas dentro de
 * render_page(), SIN wp_redirect() — redirigir desde un callback de render
 * ya rompió una pantalla del núcleo (v5.7.3, página en blanco), mismo
 * criterio que ya siguen los demás satélites de este repo.
 */
final class Settings {

	public const OPT_TEMPLATES = 'tfce_templates';
	public const MENU_SLUG     = 'tourflow-custom-emails';

	/** Único tipo con UI por ahora — alcance acordado 2026-11-16. El filtro del núcleo ya es agnóstico de tipo si se suma otro más adelante. */
	public const SUPPORTED_TYPES = [
		'confirmation' => [ 'es' => 'Confirmación de reserva', 'en' => 'Booking confirmation' ],
	];

	public static function register_menu(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	public static function add_menu(): void {
		add_menu_page(
			'Custom Emails (TourFlow)',
			'Emails personalizados',
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-media-code',
			62
		);
	}

	/** @return array<string,string> */
	private static function templates(): array {
		$list = get_option( self::OPT_TEMPLATES, [] );
		return is_array( $list ) ? $list : [];
	}

	public static function template_for( string $type ): string {
		return trim( (string) ( self::templates()[ $type ] ?? '' ) );
	}

	private static function save_template( string $type, string $html ): void {
		$list         = self::templates();
		$list[ $type ] = $html;
		update_option( self::OPT_TEMPLATES, $list, false );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tenés permiso para ver esta página.', 'tourflow-custom-emails' ) );
		}

		$notice = '';

		if ( isset( $_POST['tfce_action'] ) && isset( $_POST['tfce_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tfce_nonce'] ) ), 'tfce_admin' )
		) {
			$action = sanitize_key( wp_unslash( $_POST['tfce_action'] ) );
			$type   = sanitize_key( wp_unslash( $_POST['tfce_type'] ?? 'confirmation' ) );

			if ( $action === 'save' && isset( self::SUPPORTED_TYPES[ $type ] ) ) {
				// wp_kses_post() no alcanza acá a propósito: un template de
				// email completo necesita <html>/<head>/<style> propios
				// (igual que cualquier newsletter), que wp_kses_post()
				// descarta. Es contenido que el propio operador escribe y
				// pega — mismo nivel de confianza que custom_html_block ya
				// tiene en el núcleo (admin-only, capability manage_options),
				// no una entrada de un tercero.
				$html = (string) wp_unslash( $_POST['tfce_html'] ?? '' );
				self::save_template( $type, $html );
				$notice = self::tt( '✅ Plantilla guardada.', '✅ Template saved.' );
			} elseif ( $action === 'send_test' && isset( self::SUPPORTED_TYPES[ $type ] ) ) {
				$sent   = self::send_test( $type );
				$notice = $sent
					? self::tt( '✅ Email de prueba enviado a tu correo de administrador.', '✅ Test email sent to your admin email.' )
					: self::tt( '⚠ No se pudo enviar — revisá la configuración de SMTP del sitio.', '⚠ Could not send — check the site\'s SMTP configuration.' );
			}
		}

		self::render_html( $notice );
	}

	private static function send_test( string $type ): bool {
		$template = self::template_for( $type );
		if ( $template === '' ) {
			return false;
		}

		$booking = Vars::sample_booking();
		$vars    = Vars::build( $booking, null );
		$html    = Renderer::render( $template, $vars );

		add_filter( 'wp_mail_content_type', fn() => 'text/html' );
		$to      = wp_get_current_user()->user_email;
		$subject = self::tt( '[Prueba] ', '[Test] ' ) . ( self::SUPPORTED_TYPES[ $type ]['es'] ?? $type );
		$sent    = wp_mail( $to, $subject, $html );
		remove_filter( 'wp_mail_content_type', fn() => 'text/html' );

		return $sent;
	}

	private static function tt( string $es, string $en ): string {
		return strpos( get_user_locale(), 'en' ) === 0 ? $en : $es;
	}

	private static function render_html( string $notice ): void {
		$type     = 'confirmation';
		$current  = self::template_for( $type );
		$en       = strpos( get_user_locale(), 'en' ) === 0;
		?>
		<div class="wrap">
			<h1>✉️ <?php echo esc_html( self::tt( 'Emails personalizados — TourFlow', 'Custom Emails — TourFlow' ) ); ?></h1>
			<p><?php echo esc_html( self::tt(
				'Pegá acá tu HTML completo para el email de confirmación de reserva — reemplaza TODO el diseño que arma TourFlow por defecto, no solo un bloque agregado. Dejá el campo vacío para volver al diseño por defecto del núcleo.',
				'Paste your complete HTML for the booking confirmation email here — it replaces the entire default TourFlow design, not just an added block. Leave it empty to go back to the core\'s default design.'
			) ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'tfce_admin', 'tfce_nonce' ); ?>
				<input type="hidden" name="tfce_action" value="save">
				<input type="hidden" name="tfce_type" value="<?php echo esc_attr( $type ); ?>">
				<textarea name="tfce_html" rows="20" style="width:100%;max-width:900px;font-family:Consolas,Monaco,monospace;font-size:13px;" placeholder="<!DOCTYPE html>&#10;<html>&#10;  ...&#10;</html>"><?php echo esc_textarea( $current ); ?></textarea>
				<p>
					<?php submit_button( self::tt( 'Guardar plantilla', 'Save template' ), 'primary', 'submit', false ); ?>
				</p>
			</form>

			<form method="post" style="margin-top:8px;">
				<?php wp_nonce_field( 'tfce_admin', 'tfce_nonce' ); ?>
				<input type="hidden" name="tfce_action" value="send_test">
				<input type="hidden" name="tfce_type" value="<?php echo esc_attr( $type ); ?>">
				<?php submit_button( self::tt( '✉️ Enviar email de prueba a mi correo', '✉️ Send test email to my address' ), 'secondary', 'submit', false ); ?>
				<span style="margin-left:8px;color:#5a7068;"><?php echo esc_html( self::tt(
					'Usa datos ficticios — no hace falta ninguna reserva real.',
					'Uses fake sample data — no real booking needed.'
				) ); ?></span>
			</form>

			<h2 style="margin-top:32px;"><?php echo esc_html( self::tt( 'Variables disponibles', 'Available variables' ) ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
				<thead><tr>
					<th><?php echo esc_html( self::tt( 'Variable', 'Variable' ) ); ?></th>
					<th><?php echo esc_html( self::tt( 'Qué muestra', 'What it shows' ) ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( self::variable_descriptions( $en ) as $key => $desc ) : ?>
					<tr>
						<td><code>{{<?php echo esc_html( $key ); ?>}}</code></td>
						<td><?php echo esc_html( $desc ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="color:#5a7068;max-width:700px;">
				<?php echo esc_html( self::tt(
					'Las variables marcadas para usar dentro de un link (pdf_url, verify_url, calendar_url, whatsapp_url, logo_url) van sin escapar — están pensadas para href="{{pdf_url}}" o src="{{logo_url}}", nunca como texto suelto.',
					'Variables meant for use inside a link (pdf_url, verify_url, calendar_url, whatsapp_url, logo_url) are left unescaped — they\'re meant for href="{{pdf_url}}" or src="{{logo_url}}", never as plain text.'
				) ); ?>
			</p>
		</div>
		<?php
	}

	/** @return array<string,string> */
	private static function variable_descriptions( bool $en ): array {
		return $en ? [
			'customer_name'    => 'Customer\'s full name',
			'customer_email'   => 'Customer\'s email',
			'customer_phone'   => 'Customer\'s phone',
			'booking_ref'      => 'Booking reference (e.g. TF-2026-00042)',
			'tour_name'        => 'Tour name, in the booking\'s own language',
			'tour_date'        => 'Tour date, formatted',
			'tour_time'        => 'Departure time (if the tour has a schedule)',
			'meeting_point'    => 'Meeting point',
			'total'            => 'Amount actually charged, formatted with currency',
			'people_count'     => 'Total people (adults + children + babies)',
			'special_requests' => 'Special requests the customer wrote',
			'company_name'     => 'Operator\'s company name (Settings)',
			'brand_color'      => 'Configured brand color (hex)',
			'pdf_url'          => '[link] Voucher PDF download link',
			'verify_url'       => '[link] "Check my booking" page link',
			'calendar_url'     => '[link] "Add to Google Calendar" link',
			'maps_url'         => '[link] "Open in Google Maps" link to the meeting point',
			'whatsapp_url'     => '[link] wa.me link (empty if no WhatsApp configured)',
			'logo_url'         => '[link] Configured brand logo URL (empty if none)',
		] : [
			'customer_name'    => 'Nombre completo del cliente',
			'customer_email'   => 'Email del cliente',
			'customer_phone'   => 'Teléfono del cliente',
			'booking_ref'      => 'Referencia de la reserva (ej. TF-2026-00042)',
			'tour_name'        => 'Nombre del tour, en el idioma de la reserva',
			'tour_date'        => 'Fecha del tour, formateada',
			'tour_time'        => 'Hora de salida (si el tour tiene horario)',
			'meeting_point'    => 'Punto de encuentro',
			'total'            => 'Monto realmente cobrado, formateado con moneda',
			'people_count'     => 'Total de personas (adultos + niños + bebés)',
			'special_requests' => 'Solicitudes especiales que escribió el cliente',
			'company_name'     => 'Nombre de la empresa configurado (Configuración)',
			'brand_color'      => 'Color de marca configurado (hex)',
			'pdf_url'          => '[link] Descarga del voucher PDF',
			'verify_url'       => '[link] Página "Ver mi reserva"',
			'calendar_url'     => '[link] "Agregar a Google Calendar"',
			'maps_url'         => '[link] "Ver en Google Maps" hacia el punto de encuentro',
			'whatsapp_url'     => '[link] wa.me — vacío si no hay WhatsApp configurado',
			'logo_url'         => '[link] URL del logo de marca configurado — vacío si no hay',
		];
	}
}
