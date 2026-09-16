<?php
namespace RedsysForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración del comercio Redsys — página propia de admin (este plugin es
 * satélite, no usa las tablas ni la pantalla de Configuración del núcleo).
 * Comercio de pruebas público de Redsys para desarrollo sin contrato
 * bancario: FUC 999008881, terminal 1, clave sq7HjrUOBfKmC576ILgskD5srU870gJ7
 * (documentado en múltiples integraciones públicas de Redsys, confirmado
 * también en el manual oficial de la Pasarela Unificada v2.0, p.9).
 */
class Settings {

	public static function fuc(): string {
		return trim( (string) get_option( 'rftf_fuc', '' ) );
	}

	public static function terminal(): string {
		return trim( (string) get_option( 'rftf_terminal', '' ) );
	}

	public static function secret_key(): string {
		return trim( (string) get_option( 'rftf_secret_key', '' ) );
	}

	public static function environment(): string {
		$env = get_option( 'rftf_environment', 'test' );
		return $env === 'prod' ? 'prod' : 'test';
	}

	public static function is_configured(): bool {
		return self::fuc() !== '' && self::terminal() !== '' && self::secret_key() !== '';
	}

	public static function redirect_endpoint(): string {
		return self::environment() === 'prod'
			? 'https://sis.redsys.es/sis/realizarPago'
			: 'https://sis-t.redsys.es:25443/sis/realizarPago';
	}

	public static function rest_treat_endpoint(): string {
		return self::environment() === 'prod'
			? 'https://sis.redsys.es/sis/rest/trataPeticionREST'
			: 'https://sis-t.redsys.es:25443/sis/rest/trataPeticionREST';
	}

	public static function register_menu(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	public static function add_menu(): void {
		add_menu_page(
			'Redsys (TourFlow)',
			'Redsys',
			'manage_options',
			'redsys-for-tourflow',
			[ __CLASS__, 'render_page' ],
			'dashicons-money-alt',
			58 // debajo de los menús de TourFlow
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['rftf_settings_nonce'] ) && wp_verify_nonce( $_POST['rftf_settings_nonce'], 'rftf_settings' ) ) {
			update_option( 'rftf_fuc', sanitize_text_field( wp_unslash( $_POST['rftf_fuc'] ?? '' ) ) );
			update_option( 'rftf_terminal', sanitize_text_field( wp_unslash( $_POST['rftf_terminal'] ?? '' ) ) );
			$key = wp_unslash( $_POST['rftf_secret_key'] ?? '' );
			if ( $key !== '' ) { // campo password: no pisar la clave guardada si se deja vacío al reenviar el form
				update_option( 'rftf_secret_key', sanitize_text_field( $key ) );
			}
			update_option( 'rftf_environment', ( $_POST['rftf_environment'] ?? 'test' ) === 'prod' ? 'prod' : 'test' );
			echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
		}

		$fuc         = esc_attr( self::fuc() );
		$terminal    = esc_attr( self::terminal() );
		$has_key     = self::secret_key() !== '';
		$environment = self::environment();
		$notify_url  = esc_url( rest_url( 'redsys-for-tourflow/v1/notify' ) );
		?>
		<div class="wrap">
			<h1>Redsys — Pasarela de pago para TourFlow</h1>
			<p>Credenciales provistas por tu entidad bancaria (FUC, terminal, clave de firma). Para desarrollo sin contrato bancario, Redsys publica un comercio de pruebas genérico: FUC <code>999008881</code>, terminal <code>1</code>, clave <code>sq7HjrUOBfKmC576ILgskD5srU870gJ7</code>.</p>

			<form method="post">
				<?php wp_nonce_field( 'rftf_settings', 'rftf_settings_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="rftf_environment">Entorno</label></th>
						<td>
							<select name="rftf_environment" id="rftf_environment">
								<option value="test" <?php selected( $environment, 'test' ); ?>>Pruebas (Sandbox)</option>
								<option value="prod" <?php selected( $environment, 'prod' ); ?>>Producción</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="rftf_fuc">FUC (código de comercio)</label></th>
						<td><input type="text" name="rftf_fuc" id="rftf_fuc" value="<?php echo $fuc; ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="rftf_terminal">Terminal</label></th>
						<td><input type="text" name="rftf_terminal" id="rftf_terminal" value="<?php echo $terminal; ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="rftf_secret_key">Clave de firma</label></th>
						<td>
							<input type="password" name="rftf_secret_key" id="rftf_secret_key" value="" class="regular-text" placeholder="<?php echo $has_key ? '••••••••••••••••' : ''; ?>" autocomplete="new-password" />
							<p class="description"><?php echo $has_key ? 'Ya hay una clave guardada — dejá el campo vacío para conservarla.' : 'Sin configurar todavía.'; ?></p>
						</td>
					</tr>
					<tr>
						<th>URL de notificación</th>
						<td>
							<code><?php echo $notify_url; ?></code>
							<p class="description">Esta es la URL que Redsys necesita para avisar del resultado del pago — el gateway ya la manda automáticamente en cada operación, no hace falta cargarla en el Portal de Administración del TPV Virtual salvo que tu banco lo pida explícito.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Guardar' ); ?>
			</form>

			<p>Después, activá "Redsys" en <strong>TourFlow → Configuración → Pasarela de pago activa</strong>.</p>
		</div>
		<?php
	}
}
