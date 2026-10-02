<?php
namespace MercadoPagoSplitForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Lista los proveedores del núcleo (amir_providers, lectura directa — no
 * hay ningún punto de extensión más limpio para esto, § 6
 * GUIA-PLUGINS-SATELITE-TOURFLOW.md) con su estado de conexión a MP. El
 * operador genera un link por proveedor y se lo manda por WhatsApp/email —
 * sin portal de autogestión, mismo criterio que ProvidersPage del núcleo
 * ("alta manual, el proveedor manda sus datos por fuera del sistema").
 */
class ProvidersPage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
		}

		self::handle_actions();

		if ( ! Settings::is_app_configured() ) {
			echo '<div class="wrap"><h1>Proveedores — Mercado Pago Split</h1>';
			echo '<div class="notice notice-error"><p>Falta configurar la app de Mercado Pago (Client ID/Secret) en <a href="' . esc_url( admin_url( 'admin.php?page=mercadopago-split-tourflow' ) ) . '">MP Split → Configuración</a> antes de poder generar links de conexión.</p></div></div>';
			return;
		}

		global $wpdb;
		$providers = $wpdb->get_results( "SELECT id, business_name, email FROM {$wpdb->prefix}amir_providers ORDER BY business_name ASC" ) ?: [];

		$link = get_transient( 'mpstf_generated_link_' . get_current_user_id() );
		if ( $link ) {
			delete_transient( 'mpstf_generated_link_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1>Proveedores — Mercado Pago Split</h1>
			<p>Comisión configurada: <strong><?php echo esc_html( Settings::marketplace_fee_pct() ); ?>%</strong>. Un proveedor sin cuenta conectada sigue el flujo manual de siempre (ledger de liquidación del núcleo) — conectar acá es 100% opcional por proveedor.</p>

			<?php if ( $link ) : ?>
				<div class="notice notice-success">
					<p><strong>Link generado</strong> — mandáselo al proveedor por WhatsApp/email (vence al generar uno nuevo):</p>
					<p><input type="text" readonly style="width:100%;max-width:700px" value="<?php echo esc_attr( $link ); ?>" onclick="this.select()"></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $providers ) ) : ?>
				<p>No hay proveedores cargados todavía — se cargan en TourFlow → 🤝 Proveedores.</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>Proveedor</th><th>Email</th><th>Estado</th><th>Acciones</th></tr></thead>
					<tbody>
					<?php foreach ( $providers as $provider ) :
						$account = ProviderAccounts::get_for_provider( (int) $provider->id );
						$status  = $account->status ?? 'none';
						?>
						<tr>
							<td><?php echo esc_html( $provider->business_name ); ?></td>
							<td><?php echo esc_html( $provider->email ); ?></td>
							<td>
								<?php if ( $status === 'connected' ) : ?>
									✅ Conectado <?php echo esc_html( $account->connected_at ? 'el ' . mysql2date( 'd/m/Y', $account->connected_at ) : '' ); ?>
								<?php elseif ( $status === 'pending' ) : ?>
									⏳ Link generado, sin usar todavía
								<?php elseif ( $status === 'disconnected' ) : ?>
									⚪ Desconectado
								<?php else : ?>
									— Sin conectar (flujo manual)
								<?php endif; ?>
							</td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'mpstf_provider_action' ); ?>
									<input type="hidden" name="provider_id" value="<?php echo esc_attr( $provider->id ); ?>">
									<button type="submit" name="mpstf_action" value="generate_link" class="button">
										<?php echo $status === 'connected' ? 'Regenerar link' : 'Generar link de conexión'; ?>
									</button>
									<?php if ( $status === 'connected' ) : ?>
										<button type="submit" name="mpstf_action" value="disconnect" class="button" onclick="return confirm('¿Desconectar la cuenta de Mercado Pago de este proveedor? Sus próximas reservas volverán al flujo manual.');">Desconectar</button>
									<?php endif; ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function handle_actions(): void {
		if ( empty( $_POST['mpstf_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'mpstf_provider_action' );

		$provider_id = (int) ( $_POST['provider_id'] ?? 0 );
		if ( ! $provider_id ) {
			return;
		}

		if ( $_POST['mpstf_action'] === 'generate_link' ) {
			$link = ProviderAccounts::generate_connect_link( $provider_id );
			set_transient( 'mpstf_generated_link_' . get_current_user_id(), $link, MINUTE_IN_SECONDS * 5 );
		} elseif ( $_POST['mpstf_action'] === 'disconnect' ) {
			ProviderAccounts::disconnect( $provider_id );
		}
	}
}
