<?php
namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Credenciales de la APP de Mercado Pago que actúa como marketplace — se
 * crean una sola vez en el panel de desarrolladores de MP (Tus integraciones
 * → crear aplicación), client_id/client_secret propios de esa app, NO son
 * las credenciales de ningún proveedor individual (esas viven por-proveedor
 * en mpstf_provider_accounts vía el flujo OAuth). No confundir con
 * Configuración → Mercado Pago del núcleo (esas son las credenciales de la
 * cuenta del OPERADOR, usadas por MercadoPagoGateway para el flujo normal
 * sin split, y son las que este satélite usa como fallback — ver Gateway).
 *
 * Nota real (ver PROMPT-MERCADOPAGO-SPLIT.md § 5): no quedó confirmado si
 * hace falta una aprobación comercial de MP para poder usar `marketplace_fee`
 * de verdad aunque la app y el OAuth ya estén andando — confirmar con
 * soporte/ventas de MP antes de conectar un proveedor real.
 */
class Settings {

	public static function client_id(): string {
		return trim( (string) get_option( 'mpstf_client_id', '' ) );
	}

	public static function client_secret(): string {
		return trim( (string) get_option( 'mpstf_client_secret', '' ) );
	}

	public static function webhook_secret(): string {
		return trim( (string) get_option( 'mpstf_webhook_secret', '' ) );
	}

	/** % que se queda TourFlow de cada cobro con split — mismo campo que ya define la comisión hoy, a confirmar con el cliente si difiere por proveedor (ver checklist del PROMPT). */
	public static function marketplace_fee_pct(): float {
		return (float) get_option( 'mpstf_marketplace_fee_pct', 0 );
	}

	public static function is_app_configured(): bool {
		return self::client_id() !== '' && self::client_secret() !== '';
	}

	public static function redirect_uri(): string {
		return rest_url( 'mpstf/v1/oauth-callback' );
	}

	public static function register_menu(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_mpstf_save_settings', [ __CLASS__, 'handle_save' ] );
	}

	public static function add_menu(): void {
		add_menu_page(
			'Mercado Pago Split (TourFlow)',
			'MP Split',
			'manage_options',
			'mercadopago-split-tourflow',
			[ __CLASS__, 'render_page' ],
			'dashicons-networking'
		);
		add_submenu_page(
			'mercadopago-split-tourflow',
			'Proveedores conectados',
			'Proveedores',
			'manage_options',
			'mercadopago-split-tourflow-providers',
			[ ProvidersPage::class, 'render' ]
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpstf_save_settings' ) ) {
			wp_die( 'No autorizado.' );
		}

		update_option( 'mpstf_client_id', sanitize_text_field( $_POST['client_id'] ?? '' ) );
		update_option( 'mpstf_client_secret', sanitize_text_field( $_POST['client_secret'] ?? '' ) );
		update_option( 'mpstf_webhook_secret', sanitize_text_field( $_POST['webhook_secret'] ?? '' ) );
		update_option( 'mpstf_marketplace_fee_pct', (float) ( $_POST['marketplace_fee_pct'] ?? 0 ) );

		wp_safe_redirect( admin_url( 'admin.php?page=mercadopago-split-tourflow&saved=1' ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
		}
		$core_mp_configured = ( new \AmirBooking\Payments\MercadoPagoGateway() )->is_configured();
		?>
		<div class="wrap">
			<h1>Mercado Pago Split — TourFlow</h1>
			<p>Plugin satélite: split payment 1:1 de Mercado Pago para proveedores externos que conecten su propia cuenta. Ver <code>PROMPT-MERCADOPAGO-SPLIT.md</code> del núcleo para el diseño completo.</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>Configuración guardada.</p></div>
			<?php endif; ?>

			<?php if ( ! $core_mp_configured ) : ?>
				<div class="notice notice-warning"><p><strong>Ojo:</strong> Mercado Pago no está configurado en TourFlow → Configuración → Mercado Pago. Ese es el fallback que este satélite usa para cualquier reserva sin proveedor conectado (carritos, tours propios, proveedores que no conectaron) — sin eso, esas reservas no van a poder pagar.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mpstf_save_settings">
				<?php wp_nonce_field( 'mpstf_save_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="client_id">Client ID de la app</label></th>
						<td><input type="text" id="client_id" name="client_id" class="regular-text" value="<?php echo esc_attr( self::client_id() ); ?>"></td>
					</tr>
					<tr>
						<th><label for="client_secret">Client Secret de la app</label></th>
						<td><input type="password" id="client_secret" name="client_secret" class="regular-text" value="<?php echo esc_attr( self::client_secret() ); ?>"></td>
					</tr>
					<tr>
						<th><label for="webhook_secret">Webhook secret</label></th>
						<td><input type="password" id="webhook_secret" name="webhook_secret" class="regular-text" value="<?php echo esc_attr( self::webhook_secret() ); ?>">
							<p class="description">El "secret" que Mercado Pago muestra al configurar la notificación webhook de esta APP (Tus integraciones → Webhooks) — no es el de la cuenta de ningún proveedor.</p></td>
					</tr>
					<tr>
						<th><label for="marketplace_fee_pct">Comisión de TourFlow (%)</label></th>
						<td><input type="number" step="0.01" min="0" max="100" id="marketplace_fee_pct" name="marketplace_fee_pct" value="<?php echo esc_attr( self::marketplace_fee_pct() ); ?>">
							<p class="description">Se aplica sobre el monto cobrado en CUALQUIER reserva con split activo, todos los proveedores por igual (v1 — sin comisión por proveedor todavía).</p></td>
					</tr>
					<tr>
						<th>Redirect URI de la app</th>
						<td><code><?php echo esc_html( self::redirect_uri() ); ?></code>
							<p class="description">Cargar EXACTO este valor en la configuración de la app en Mercado Pago (Tus integraciones → esta app → URLs de redirect), si no el OAuth de cada proveedor va a fallar.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Guardar' ); ?>
			</form>
		</div>
		<?php
	}
}
