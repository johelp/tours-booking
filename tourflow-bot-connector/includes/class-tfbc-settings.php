<?php
namespace TourFlowBotConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración del satélite — una API key por sitio (decisión cerrada con
 * el cliente 2026-10-03: API key fija, no OAuth/sin-auth, ver
 * GUIA-PLUGINS-SATELITE-TOURFLOW.md § "TourFlow Bot Connector"). Página
 * propia de admin, no usa la tabla de opciones ni la pantalla de
 * Configuración del núcleo.
 *
 * La key se muestra en texto plano SOLO en el aviso de éxito justo después
 * de generarla — nunca se vuelve a reimprimir en un campo (mismo motivo que
 * el hallazgo de auditoría de seguridad del core sobre
 * mercadopago-split-tourflow, 2026-09-24: un secreto en un atributo `value`
 * queda visible en "ver código fuente"). Después de ese momento, la
 * pantalla solo muestra los últimos 4 caracteres para poder identificarla
 * sin volver a exponerla — igual que un token de GitHub/AWS.
 */
class Settings {

	/** Header que el bot debe mandar en cada request — ver Auth::authorize(). */
	public const HEADER_NAME = 'X-TFBC-Api-Key';

	public static function api_key(): string {
		return trim( (string) get_option( 'tfbc_api_key', '' ) );
	}

	public static function is_enabled(): bool {
		return self::api_key() !== '' && (bool) get_option( 'tfbc_enabled', false );
	}

	/** Nombre del operador/sitio, para que el bot sepa a quién le está hablando si consulta varios TourFlow. */
	public static function site_label(): string {
		$label = trim( (string) get_option( 'tfbc_site_label', '' ) );
		return $label !== '' ? $label : get_bloginfo( 'name' );
	}

	public static function register_menu(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	public static function add_menu(): void {
		add_menu_page(
			'Bot Connector (TourFlow)',
			'Bot Connector',
			'manage_options',
			'tourflow-bot-connector',
			[ __CLASS__, 'render_page' ],
			'dashicons-format-chat',
			59 // debajo de Redsys/MP Split
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$just_generated_key = '';

		if ( isset( $_POST['tfbc_nonce'] ) && wp_verify_nonce( $_POST['tfbc_nonce'], 'tfbc_settings' ) ) {
			if ( isset( $_POST['tfbc_action'] ) && $_POST['tfbc_action'] === 'generate_key' ) {
				// 256 bits — mismo criterio que BookingManager::generate_access_token() del núcleo.
				$just_generated_key = bin2hex( random_bytes( 32 ) );
				update_option( 'tfbc_api_key', $just_generated_key );
				update_option( 'tfbc_enabled', true );
			} elseif ( isset( $_POST['tfbc_action'] ) && $_POST['tfbc_action'] === 'revoke_key' ) {
				update_option( 'tfbc_api_key', '' );
				update_option( 'tfbc_enabled', false );
			} else {
				update_option( 'tfbc_enabled', ! empty( $_POST['tfbc_enabled'] ) );
				update_option( 'tfbc_site_label', sanitize_text_field( wp_unslash( $_POST['tfbc_site_label'] ?? '' ) ) );
				echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
			}
		}

		$has_key    = self::api_key() !== '';
		$enabled    = self::is_enabled();
		$site_label = esc_attr( self::site_label() );
		$base_url   = esc_url( rest_url( 'tfbc/v1' ) );
		?>
		<div class="wrap">
			<h1>TourFlow Bot Connector</h1>
			<p>Expone el catálogo de tours (y habitaciones/productos si el sitio es Pro Max), disponibilidad, y un link directo de reserva — en un formato pensado para que un chatbot externo lo consuma. <strong>No contesta preguntas ni mantiene ninguna conversación</strong>: eso lo hace el bot, afuera de WordPress; este plugin solo le da datos limpios para trabajar.</p>

			<?php if ( $just_generated_key ) : ?>
				<div class="notice notice-success">
					<p><strong>Nueva API key generada — copiala ahora, no se vuelve a mostrar completa:</strong></p>
					<p><code style="font-size:14px;padding:6px 10px;background:#f0f0f1;display:inline-block;"><?php echo esc_html( $just_generated_key ); ?></code></p>
					<p class="description">Pegala en la configuración del bot, en el header <code><?php echo esc_html( self::HEADER_NAME ); ?></code>.</p>
				</div>
			<?php endif; ?>

			<h2>API key</h2>
			<table class="form-table">
				<tr>
					<th>Estado actual</th>
					<td>
						<?php if ( $has_key ) : ?>
							Hay una key configurada, termina en <code>…<?php echo esc_html( substr( self::api_key(), -4 ) ); ?></code>.
						<?php else : ?>
							Todavía no se generó ninguna key — el conector está inactivo.
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post" style="margin-bottom:24px;">
				<?php wp_nonce_field( 'tfbc_settings', 'tfbc_nonce' ); ?>
				<button type="submit" name="tfbc_action" value="generate_key" class="button button-primary"><?php echo $has_key ? 'Regenerar API key (invalida la anterior)' : 'Generar API key'; ?></button>
				<?php if ( $has_key ) : ?>
					<button type="submit" name="tfbc_action" value="revoke_key" class="button button-link-delete" onclick="return confirm('¿Revocar la key? El bot deja de poder consultar este sitio hasta que generes una nueva.');">Revocar</button>
				<?php endif; ?>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'tfbc_settings', 'tfbc_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="tfbc_enabled">Conector activo</label></th>
						<td>
							<label><input type="checkbox" id="tfbc_enabled" name="tfbc_enabled" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $has_key ); ?> /> El bot puede consultar este sitio</label>
							<p class="description">Desmarcá esto para pausar el acceso del bot sin perder/rotar la key — útil para probar sin exponer nada todavía.</p>
						</td>
					</tr>
					<tr>
						<th><label for="tfbc_site_label">Nombre del sitio para el bot</label></th>
						<td>
							<input type="text" id="tfbc_site_label" name="tfbc_site_label" value="<?php echo $site_label; ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<p class="description">Si el mismo bot consulta varios sitios TourFlow (ej. Caliafarm y Visit Sicily Experiences), esto lo ayuda a distinguir de cuál viene cada respuesta — viaja en el campo <code>site</code> de cada endpoint.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Guardar' ); ?>
			</form>

			<h2>Endpoints</h2>
			<p>Base: <code><?php echo $base_url; ?></code> — cada request necesita el header <code><?php echo esc_html( self::HEADER_NAME ); ?>: &lt;la API key&gt;</code>.</p>
			<table class="widefat striped" style="max-width:900px;">
				<thead><tr><th>Endpoint</th><th>Para qué</th></tr></thead>
				<tbody>
					<tr><td><code>GET /catalog</code></td><td>Lista liviana de todo lo reservable (tours, habitaciones, productos digitales) — nombre, descripción corta, "desde $X", link.</td></tr>
					<tr><td><code>GET /items/{type}/{id}</code></td><td>Detalle completo de un ítem (<code>type</code> = <code>tour</code>/<code>room</code>/<code>product</code>) — descripción larga, FAQ, política, mínimos/máximos.</td></tr>
					<tr><td><code>GET /availability?type=tour&amp;id=1&amp;date=2026-11-01</code></td><td>Disponibilidad real de una fecha puntual (tours) o un rango (<code>check_in</code>/<code>check_out</code>, habitaciones).</td></tr>
					<tr><td><code>GET /booking-link?type=tour&amp;id=1&amp;date=2026-11-01</code></td><td>Devuelve la URL real del sitio para que el usuario termine de reservar ahí — este conector nunca crea la reserva por sí mismo.</td></tr>
				</tbody>
			</table>
			<p class="description">Detalle completo de cada respuesta en <code>GUIA-PLUGINS-SATELITE-TOURFLOW.md</code> del núcleo, sección "TourFlow Bot Connector".</p>
		</div>
		<?php
	}
}
