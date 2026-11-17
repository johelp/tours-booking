<?php
namespace TourFlowCustomEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Una sola pantalla: plantilla HTML completa por tipo de email (y por
 * idioma, opcional) + plantilla base + botón de prueba. Acciones procesadas
 * y respondidas dentro de render_page(), SIN wp_redirect() — redirigir desde
 * un callback de render ya rompió una pantalla del núcleo (v5.7.3, página en
 * blanco), mismo criterio que ya siguen los demás satélites de este repo.
 *
 * Almacenamiento (opción `tfce_templates`): la clave `tipo` es la plantilla
 * para todos los idiomas y `tipo:xx` la variante de un idioma concreto, que
 * gana sobre la general cuando el cliente reservó en ese idioma.
 */
final class Settings {

	public const OPT_TEMPLATES = 'tfce_templates';
	public const MENU_SLUG     = 'tourflow-custom-emails';

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

	private static function key( string $type, string $lang ): string {
		return $lang === '' ? $type : $type . ':' . $lang;
	}

	/** Plantilla para un tipo + idioma de la reserva: la variante del idioma si existe, si no la general. */
	public static function template_for( string $type, string $lang = '' ): string {
		$all = self::templates();
		$lang = strtolower( $lang );
		if ( $lang !== '' && trim( (string) ( $all[ self::key( $type, $lang ) ] ?? '' ) ) !== '' ) {
			return trim( (string) $all[ self::key( $type, $lang ) ] );
		}
		return trim( (string) ( $all[ $type ] ?? '' ) );
	}

	/** Plantilla guardada EXACTAMENTE bajo esa clave (sin caer a la general) — para el editor. */
	private static function stored( string $type, string $lang ): string {
		return (string) ( self::templates()[ self::key( $type, $lang ) ] ?? '' );
	}

	private static function save_template( string $type, string $lang, string $html ): void {
		$list = self::templates();
		$key  = self::key( $type, $lang );
		if ( trim( $html ) === '' ) {
			unset( $list[ $key ] );
		} else {
			$list[ $key ] = $html;
		}
		update_option( self::OPT_TEMPLATES, $list, false );
	}

	/** Idiomas en los que se puede escribir una variante (los activos del sitio). */
	private static function languages(): array {
		$codes = class_exists( '\AmirBooking\Core\Languages' ) ? \AmirBooking\Core\Languages::active() : [ 'es', 'en' ];
		return array_values( array_unique( $codes ) );
	}

	private static function lang_name( string $code ): string {
		$names = [ 'es' => 'Español', 'en' => 'English', 'it' => 'Italiano', 'fr' => 'Français', 'pt' => 'Português', 'de' => 'Deutsch' ];
		return $names[ $code ] ?? strtoupper( $code );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tenés permiso para ver esta página.', 'tourflow-custom-emails' ) );
		}

		$notice = '';
		$posted = isset( $_POST['tfce_action'], $_POST['tfce_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tfce_nonce'] ) ), 'tfce_admin' );

		$src  = $posted ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- solo selecciona qué se muestra; todo lo que escribe pasa por el nonce de arriba.
		$type = sanitize_key( wp_unslash( $src['tfce_type'] ?? 'confirmation' ) );
		$type = Types::exists( $type ) ? $type : 'confirmation';
		$lang = sanitize_key( wp_unslash( $src['tfce_lang'] ?? '' ) );
		$lang = in_array( $lang, self::languages(), true ) ? $lang : '';

		if ( $posted ) {
			$action = sanitize_key( wp_unslash( $_POST['tfce_action'] ) );

			if ( $action === 'save' || $action === 'reset' ) {
				// wp_kses_post() no alcanza acá a propósito: un template de
				// email completo necesita <html>/<head>/<style> propios
				// (igual que cualquier newsletter), que wp_kses_post()
				// descarta. Es contenido que el propio operador escribe y
				// pega — mismo nivel de confianza que custom_html_block ya
				// tiene en el núcleo (admin-only, capability manage_options),
				// no una entrada de un tercero.
				$html = $action === 'reset' ? '' : (string) wp_unslash( $_POST['tfce_html'] ?? '' );
				self::save_template( $type, $lang, $html );
				$notice = $action === 'reset'
					? self::tt( '✅ Listo: este email vuelve al diseño por defecto de TourFlow.', '✅ Done: this email is back to TourFlow\'s default design.' )
					: self::tt( '✅ Plantilla guardada.', '✅ Template saved.' );
			} elseif ( $action === 'send_test' ) {
				$sent   = self::send_test( $type, $lang );
				$notice = $sent
					? self::tt( '✅ Email de prueba enviado a tu correo de administrador.', '✅ Test email sent to your admin email.' )
					: self::tt( '⚠ No se pudo enviar — guardá primero una plantilla para este email y revisá la configuración de SMTP del sitio.', '⚠ Could not send — save a template for this email first and check the site\'s SMTP configuration.' );
			}
		}

		self::render_html( $type, $lang, $notice );
	}

	private static function send_test( string $type, string $lang ): bool {
		$template = self::template_for( $type, $lang );
		if ( $template === '' ) {
			return false;
		}

		$sample_lang = $lang !== '' ? $lang : 'es';
		$booking     = Vars::sample_booking( $sample_lang );
		$vars        = Vars::build( $booking, null );
		$html        = Renderer::render( $template, $vars );

		$set_html = static fn() => 'text/html';
		add_filter( 'wp_mail_content_type', $set_html );
		$to      = wp_get_current_user()->user_email;
		$subject = self::tt( '[Prueba] ', '[Test] ' ) . Types::label( $type, false );
		$sent    = wp_mail( $to, $subject, $html );
		remove_filter( 'wp_mail_content_type', $set_html );

		return $sent;
	}

	private static function tt( string $es, string $en ): string {
		return strpos( get_user_locale(), 'en' ) === 0 ? $en : $es;
	}

	private static function url( string $type, string $lang = '' ): string {
		return add_query_arg(
			array_filter( [ 'page' => self::MENU_SLUG, 'tfce_type' => $type, 'tfce_lang' => $lang ] ),
			admin_url( 'admin.php' )
		);
	}

	private static function render_html( string $type, string $lang, string $notice ): void {
		$en        = strpos( get_user_locale(), 'en' ) === 0;
		$current   = self::stored( $type, $lang );
		$all_types = Types::all();
		$stored    = self::templates();
		$edit_lang = $lang !== '' ? $lang : 'es';
		$starter   = Types::starter( $type, $edit_lang );
		?>
		<div class="wrap">
			<h1>✉️ <?php echo esc_html( self::tt( 'Emails personalizados — TourFlow', 'Custom Emails — TourFlow' ) ); ?></h1>
			<p style="max-width:900px;"><?php echo esc_html( self::tt(
				'Reemplazá el diseño COMPLETO de cada email que recibe el cliente por tu propio HTML. Elegí el email en las pestañas; si lo dejás vacío se usa el diseño por defecto de TourFlow.',
				'Replace the COMPLETE design of each email your customer receives with your own HTML. Pick the email in the tabs; if left empty, TourFlow\'s default design is used.'
			) ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper" style="margin-bottom:14px;">
				<?php foreach ( $all_types as $key => $def ) :
					$has = false;
					foreach ( $stored as $k => $v ) {
						if ( ( $k === $key || strpos( $k, $key . ':' ) === 0 ) && trim( (string) $v ) !== '' ) { $has = true; break; }
					}
					?>
					<a class="nav-tab <?php echo $key === $type ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $key ) ); ?>">
						<?php echo esc_html( Types::label( $key, $en ) ); ?><?php echo $has ? ' <span title="' . esc_attr( self::tt( 'Personalizado', 'Customized' ) ) . '" style="color:#1d9e75;">●</span>' : ''; ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<p style="max-width:900px;color:#5a7068;">
				<strong><?php echo esc_html( self::tt( 'Cuándo se envía:', 'When it is sent:' ) ); ?></strong>
				<?php echo esc_html( $all_types[ $type ]['when'][ $en ? 'en' : 'es' ] ); ?>
			</p>

			<p>
				<strong><?php echo esc_html( self::tt( 'Idioma de la plantilla:', 'Template language:' ) ); ?></strong>
				<a class="button <?php echo $lang === '' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( self::url( $type ) ); ?>">
					<?php echo esc_html( self::tt( 'Todos los idiomas', 'All languages' ) ); ?><?php echo trim( (string) ( $stored[ $type ] ?? '' ) ) !== '' ? ' ✓' : ''; ?>
				</a>
				<?php foreach ( self::languages() as $code ) : ?>
					<a class="button <?php echo $lang === $code ? 'button-primary' : ''; ?>" href="<?php echo esc_url( self::url( $type, $code ) ); ?>">
						<?php echo esc_html( self::lang_name( $code ) ); ?><?php echo trim( (string) ( $stored[ $type . ':' . $code ] ?? '' ) ) !== '' ? ' ✓' : ''; ?>
					</a>
				<?php endforeach; ?>
			</p>
			<p style="max-width:900px;color:#5a7068;font-size:13px;">
				<?php echo esc_html( self::tt(
					'Una plantilla de un idioma concreto se usa solo para clientes que reservaron en ese idioma; "Todos los idiomas" es la de respaldo para el resto. El ✓ marca lo que ya tenés cargado.',
					'A single-language template is used only for customers who booked in that language; "All languages" is the fallback for everyone else. The ✓ marks what you have saved.'
				) ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'tfce_admin', 'tfce_nonce' ); ?>
				<input type="hidden" name="tfce_action" value="save">
				<input type="hidden" name="tfce_type" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="tfce_lang" value="<?php echo esc_attr( $lang ); ?>">
				<textarea id="tfce-html" name="tfce_html" rows="22" style="width:100%;max-width:900px;font-family:Consolas,Monaco,monospace;font-size:13px;" placeholder="<!DOCTYPE html>&#10;<html>&#10;  ...&#10;</html>" data-starter="<?php echo esc_attr( $starter ); ?>"><?php echo esc_textarea( $current ); ?></textarea>
				<p>
					<?php submit_button( self::tt( 'Guardar plantilla', 'Save template' ), 'primary', 'submit', false ); ?>
					<?php if ( $starter !== '' ) : ?>
						<button type="button" class="button" id="tfce-load-starter"><?php echo esc_html( self::tt( '📄 Cargar plantilla base', '📄 Load starter template' ) ); ?></button>
					<?php endif; ?>
				</p>
			</form>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;">
				<form method="post">
					<?php wp_nonce_field( 'tfce_admin', 'tfce_nonce' ); ?>
					<input type="hidden" name="tfce_action" value="send_test">
					<input type="hidden" name="tfce_type" value="<?php echo esc_attr( $type ); ?>">
					<input type="hidden" name="tfce_lang" value="<?php echo esc_attr( $lang ); ?>">
					<?php submit_button( self::tt( '✉️ Enviar email de prueba a mi correo', '✉️ Send test email to my address' ), 'secondary', 'submit', false ); ?>
					<span style="margin-left:8px;color:#5a7068;"><?php echo esc_html( self::tt(
						'Usa la plantilla ya GUARDADA y datos ficticios — no hace falta ninguna reserva real.',
						'Uses the already SAVED template and fake sample data — no real booking needed.'
					) ); ?></span>
				</form>
				<?php if ( $current !== '' ) : ?>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( self::tt( '¿Borrar esta plantilla y volver al diseño por defecto?', 'Delete this template and go back to the default design?' ) ); ?>');">
						<?php wp_nonce_field( 'tfce_admin', 'tfce_nonce' ); ?>
						<input type="hidden" name="tfce_action" value="reset">
						<input type="hidden" name="tfce_type" value="<?php echo esc_attr( $type ); ?>">
						<input type="hidden" name="tfce_lang" value="<?php echo esc_attr( $lang ); ?>">
						<?php submit_button( self::tt( 'Volver al diseño por defecto', 'Back to default design' ), 'delete', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

			<p style="max-width:900px;color:#5a7068;margin-top:14px;">
				<?php echo esc_html( self::tt(
					'El ASUNTO de cada email no se cambia acá: se edita en TourFlow → ✉️ Emails → Editar textos (campo "Asunto"). Este editor reemplaza solo el cuerpo.',
					'The SUBJECT of each email is not changed here: edit it in TourFlow → ✉️ Emails → Edit texts ("Subject" field). This editor replaces the body only.'
				) ); ?>
			</p>

			<h2 style="margin-top:32px;"><?php echo esc_html( self::tt( 'Variables disponibles', 'Available variables' ) ); ?></h2>
			<?php
			$descs  = self::variable_descriptions( $en );
			$extra  = $all_types[ $type ]['extra'];
			$common = array_diff_key( $descs, array_flip( [ 'pay_url', 'browse_url', 'review_google_url', 'review_tripadvisor_url', 'balance_amount', 'payment_deadline', 'refund_amount', 'cancellation_reason', 'custom_note', 'total_booking' ] ) );
			$own    = array_intersect_key( $descs, array_flip( $extra ) );
			$others = array_diff_key( $descs, $common, $own );
			?>
			<table class="wp-list-table widefat fixed striped" style="max-width:900px;">
				<thead><tr>
					<th style="width:260px;"><?php echo esc_html( self::tt( 'Variable', 'Variable' ) ); ?></th>
					<th><?php echo esc_html( self::tt( 'Qué muestra', 'What it shows' ) ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( [ $own, $common, $others ] as $group_i => $group ) :
					if ( empty( $group ) ) { continue; }
					if ( $group_i === 0 ) { $heading = self::tt( 'Propias de este email', 'Specific to this email' ); }
					elseif ( $group_i === 1 ) { $heading = self::tt( 'Disponibles en todos los emails', 'Available in every email' ); }
					else { $heading = self::tt( 'Solo tienen valor en algunos emails (en el resto salen vacías)', 'Only filled in some emails (empty in the rest)' ); }
					?>
					<tr><td colspan="2" style="background:#f0f6f3;font-weight:600;"><?php echo esc_html( $heading ); ?></td></tr>
					<?php foreach ( $group as $key => $desc ) : ?>
						<tr>
							<td><code>{{<?php echo esc_html( $key ); ?>}}</code></td>
							<td><?php echo esc_html( $desc ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="color:#5a7068;max-width:900px;">
				<?php echo esc_html( self::tt(
					'Las variables marcadas [link] van sin escapar — están pensadas para href="{{pdf_url}}" o src="{{logo_url}}", nunca como texto suelto. Para mostrar un fragmento solo si una variable tiene valor, envolvelo así: {{#if custom_note}} … {{/if}}.',
					'Variables marked [link] are left unescaped — they are meant for href="{{pdf_url}}" or src="{{logo_url}}", never as plain text. To show a fragment only when a variable has a value, wrap it like this: {{#if custom_note}} … {{/if}}.'
				) ); ?>
			</p>
			<p style="color:#5a7068;max-width:900px;">
				<?php echo esc_html( self::tt(
					'Fuera de este editor, a propósito: los avisos al proveedor externo (llevan sus botones de aprobar/rechazar), los avisos internos al operador, y el email de una compra de VARIOS ítems en un solo carrito (lista cada ítem con su estado). Esos siguen con el diseño de TourFlow.',
					'Deliberately outside this editor: external-provider emails (they carry the approve/reject buttons), internal operator alerts, and the email of a MULTI-item single-cart purchase (it lists each item with its status). Those keep TourFlow\'s design.'
				) ); ?>
			</p>
		</div>
		<script>
		(function () {
			var btn = document.getElementById('tfce-load-starter');
			var ta  = document.getElementById('tfce-html');
			if (!btn || !ta) { return; }
			btn.addEventListener('click', function () {
				if (ta.value.trim() !== '' && !window.confirm(<?php echo wp_json_encode( self::tt( 'El editor ya tiene contenido. ¿Reemplazarlo por la plantilla base?', 'The editor already has content. Replace it with the starter template?' ) ); ?>)) { return; }
				ta.value = ta.getAttribute('data-starter');
				ta.focus();
			});
		})();
		</script>
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
			'tour_time'        => 'Departure time (empty if the tour has no schedule — wrap text in {{#if tour_time}}…{{/if}} to show it only when set)',
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
			'pay_url'          => '[link] Page where the customer pays / completes the booking (same page as verify_url)',
			'browse_url'       => '[link] Your website\'s home / tours page',
			'review_google_url'     => '[link] Google review link (empty if not configured)',
			'review_tripadvisor_url' => '[link] TripAdvisor review link (empty if not configured)',
			'balance_amount'   => 'Remaining balance of a partial deposit (empty if none)',
			'payment_deadline' => 'Date until which the spot is held after a failed payment',
			'refund_amount'    => 'Amount refunded (empty if none)',
			'cancellation_reason' => 'Reason written by a provider when rejecting (empty otherwise)',
			'custom_note'      => 'Free message the operator wrote when sending the email (empty if none)',
			'total_booking'    => 'Full booking total (not just what was charged now)',
		] : [
			'customer_name'    => 'Nombre completo del cliente',
			'customer_email'   => 'Email del cliente',
			'customer_phone'   => 'Teléfono del cliente',
			'booking_ref'      => 'Referencia de la reserva (ej. TF-2026-00042)',
			'tour_name'        => 'Nombre del tour, en el idioma de la reserva',
			'tour_date'        => 'Fecha del tour, formateada',
			'tour_time'        => 'Hora de salida (vacía si el tour no tiene horario — envolvé el texto en {{#if tour_time}}…{{/if}} para mostrarlo solo si existe)',
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
			'pay_url'          => '[link] Página donde el cliente paga / completa la reserva (la misma que verify_url)',
			'browse_url'       => '[link] Inicio / tours de tu sitio web',
			'review_google_url'     => '[link] Link de reseña en Google — vacío si no está configurado',
			'review_tripadvisor_url' => '[link] Link de reseña en TripAdvisor — vacío si no está configurado',
			'balance_amount'   => 'Saldo restante de un depósito parcial (vacío si no hay)',
			'payment_deadline' => 'Fecha hasta la que se guarda el lugar tras un pago rechazado',
			'refund_amount'    => 'Monto reembolsado (vacío si no hay)',
			'cancellation_reason' => 'Motivo escrito por un proveedor al rechazar (vacío en el resto)',
			'custom_note'      => 'Mensaje libre que escribió el operador al enviar el email (vacío si no hay)',
			'total_booking'    => 'Total completo de la reserva (no solo lo cobrado ahora)',
		];
	}
}
