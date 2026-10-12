<?php
namespace TourFlowAutomations;

defined( 'ABSPATH' ) || exit;

/**
 * Destinos (URLs) configurados, secreto de firma y pantalla de admin.
 *
 * Las acciones de la pantalla se procesan dentro de render_page() y se
 * responden en la misma página, SIN wp_redirect(): redirigir desde un callback
 * de render ya tuvo que corregirse en el núcleo (v5.7.3, página en blanco).
 */
final class Settings {

	public const OPT_SECRET    = 'tfa_secret';
	public const OPT_ENDPOINTS = 'tfa_endpoints';
	public const MENU_SLUG     = 'tourflow-automations';
	public const MAX_ENDPOINTS = 10;

	public static function register_menu(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	public static function add_menu(): void {
		add_menu_page(
			'Automatizaciones (TourFlow)',
			'Automatizaciones',
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-controls-repeat',
			61
		);
	}

	/** Se crea una vez. El secreto nunca se reimprime completo salvo al regenerarlo. */
	public static function ensure_secret(): void {
		if ( get_option( self::OPT_SECRET ) === false || get_option( self::OPT_SECRET ) === '' ) {
			update_option( self::OPT_SECRET, bin2hex( random_bytes( 32 ) ), false );
		}
	}

	public static function secret(): string {
		return (string) get_option( self::OPT_SECRET, '' );
	}

	/** @return array<int,array{id:string,name:string,url:string,events:string[],active:bool}> */
	public static function endpoints(): array {
		$list = get_option( self::OPT_ENDPOINTS, [] );
		return is_array( $list ) ? array_values( $list ) : [];
	}

	public static function find( string $id ): ?array {
		foreach ( self::endpoints() as $ep ) {
			if ( $ep['id'] === $id ) {
				return $ep;
			}
		}
		return null;
	}

	public static function save_endpoints( array $list ): void {
		update_option( self::OPT_ENDPOINTS, array_values( $list ), false );
	}

	/**
	 * Solo https. http se acepta únicamente para hosts de desarrollo locales
	 * (localhost, *.localhost, *.test) — mismo criterio que el resto del
	 * ecosistema: un secreto de firma no viaja en claro hacia internet.
	 */
	public static function validate_url( string $url ): ?string {
		$url = esc_url_raw( trim( $url ) );
		$parts = $url !== '' ? wp_parse_url( $url ) : false;
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		$dev  = $host === 'localhost' || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.test' );
		if ( $parts['scheme'] === 'https' || ( $parts['scheme'] === 'http' && $dev ) ) {
			return $url;
		}
		return null;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tenés permiso para ver esta página.', 'tourflow-automations' ) );
		}

		$notice      = '';
		$new_secret  = '';
		$test_result = null;

		if ( isset( $_POST['tfa_action'] ) && isset( $_POST['tfa_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tfa_nonce'] ) ), 'tfa_admin' ) ) {
			$action = sanitize_key( wp_unslash( $_POST['tfa_action'] ) );
			$list   = self::endpoints();

			if ( $action === 'add_endpoint' ) {
				$name   = sanitize_text_field( wp_unslash( $_POST['tfa_name'] ?? '' ) );
				$url    = self::validate_url( (string) wp_unslash( $_POST['tfa_url'] ?? '' ) );
				$events = array_values( array_intersect(
					array_map( 'sanitize_key', (array) ( $_POST['tfa_events'] ?? [] ) ),
					array_keys( Payload::events() )
				) );
				if ( count( $list ) >= self::MAX_ENDPOINTS ) {
					$notice = [ 'error', 'Llegaste al máximo de ' . self::MAX_ENDPOINTS . ' destinos.' ];
				} elseif ( $url === null ) {
					$notice = [ 'error', 'La URL tiene que ser https:// (http:// solo se acepta en localhost, *.localhost o *.test).' ];
				} elseif ( empty( $events ) ) {
					$notice = [ 'error', 'Elegí al menos un evento.' ];
				} else {
					$list[]  = [
						'id'     => substr( bin2hex( random_bytes( 6 ) ), 0, 12 ),
						'name'   => $name !== '' ? $name : 'Destino ' . ( count( $list ) + 1 ),
						'url'    => $url,
						'events' => $events,
						'active' => true,
					];
					self::save_endpoints( $list );
					$notice = [ 'ok', 'Destino agregado.' ];
				}
			} elseif ( in_array( $action, [ 'delete_endpoint', 'toggle_endpoint', 'test_endpoint' ], true ) ) {
				$id = sanitize_text_field( wp_unslash( $_POST['tfa_id'] ?? '' ) );
				foreach ( $list as $i => $ep ) {
					if ( $ep['id'] !== $id ) {
						continue;
					}
					if ( $action === 'delete_endpoint' ) {
						unset( $list[ $i ] );
						self::save_endpoints( $list );
						$notice = [ 'ok', 'Destino eliminado.' ];
					} elseif ( $action === 'toggle_endpoint' ) {
						$list[ $i ]['active'] = ! $ep['active'];
						self::save_endpoints( $list );
						$notice = [ 'ok', $list[ $i ]['active'] ? 'Destino activado.' : 'Destino pausado.' ];
					} else {
						$test_result = Dispatcher::send_test( $ep );
					}
					break;
				}
			} elseif ( $action === 'regen_secret' ) {
				$new_secret = bin2hex( random_bytes( 32 ) );
				update_option( self::OPT_SECRET, $new_secret, false );
				$notice = [ 'ok', 'Secreto regenerado. Copialo ahora: los destinos tienen que actualizarlo, y no se vuelve a mostrar completo.' ];
			}
		}

		self::render( $notice, $new_secret, $test_result );
	}

	private static function render( $notice, string $new_secret, ?array $test_result ): void {
		$endpoints = self::endpoints();
		$secret    = self::secret();
		$masked    = $secret !== '' ? substr( $secret, 0, 6 ) . str_repeat( '•', 20 ) . substr( $secret, -4 ) : '—';
		$events    = Payload::events();
		?>
		<div class="wrap">
			<h1>Automatizaciones (TourFlow)</h1>
			<p>Envía eventos de reservas por webhook a <strong>Zapier, Make, n8n</strong> o cualquier URL que reciba POST. Cada envío va firmado con el secreto de abajo. Cómo armarlo paso a paso: <code>GUIA-AUTOMATIZACIONES-TOURFLOW.md</code>.</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo $notice[0] === 'ok' ? 'success' : 'error'; ?>"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $test_result ) : ?>
				<div class="notice notice-<?php echo $test_result['ok'] ? 'success' : 'error'; ?>">
					<p><strong>Prueba enviada:</strong> <?php echo esc_html( $test_result['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<h2>Secreto de firma</h2>
			<?php if ( $new_secret !== '' ) : ?>
				<p><strong>Nuevo secreto — copialo ahora:</strong></p>
				<p><code style="user-select:all;word-break:break-all;"><?php echo esc_html( $new_secret ); ?></code></p>
			<?php else : ?>
				<p>Actual: <code><?php echo esc_html( $masked ); ?></code></p>
			<?php endif; ?>
			<form method="post" onsubmit="return confirm('¿Regenerar el secreto? Todos los destinos dejan de validar hasta que lo actualices.');">
				<?php wp_nonce_field( 'tfa_admin', 'tfa_nonce' ); ?>
				<input type="hidden" name="tfa_action" value="regen_secret">
				<?php submit_button( 'Regenerar secreto', 'secondary', 'submit', false ); ?>
			</form>

			<h2 style="margin-top:28px">Destinos</h2>
			<?php if ( empty( $endpoints ) ) : ?>
				<p>Todavía no hay destinos. Agregá uno abajo.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr><th>Nombre</th><th>URL</th><th>Eventos</th><th>Estado</th><th>Acciones</th></tr></thead>
					<tbody>
					<?php foreach ( $endpoints as $ep ) : ?>
						<tr>
							<td><?php echo esc_html( $ep['name'] ); ?></td>
							<td><code style="word-break:break-all;"><?php echo esc_html( $ep['url'] ); ?></code></td>
							<td><?php echo esc_html( implode( ', ', array_map( fn( $e ) => $events[ $e ] ?? $e, $ep['events'] ) ) ); ?></td>
							<td><?php echo $ep['active'] ? '<span style="color:#1d7f2a">Activo</span>' : '<span style="color:#8a6d00">Pausado</span>'; ?></td>
							<td style="white-space:nowrap">
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'tfa_admin', 'tfa_nonce' ); ?>
									<input type="hidden" name="tfa_id" value="<?php echo esc_attr( $ep['id'] ); ?>">
									<button class="button" name="tfa_action" value="test_endpoint">Enviar prueba</button>
									<button class="button" name="tfa_action" value="toggle_endpoint"><?php echo $ep['active'] ? 'Pausar' : 'Activar'; ?></button>
									<button class="button button-link-delete" name="tfa_action" value="delete_endpoint" onclick="return confirm('¿Eliminar este destino?');">Eliminar</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( count( $endpoints ) < self::MAX_ENDPOINTS ) : ?>
			<h2 style="margin-top:28px">Agregar destino</h2>
			<form method="post" style="max-width:720px;background:#fff;padding:16px 20px;border:1px solid #dcdcde;border-radius:6px">
				<?php wp_nonce_field( 'tfa_admin', 'tfa_nonce' ); ?>
				<input type="hidden" name="tfa_action" value="add_endpoint">
				<p><label><strong>Nombre</strong><br><input type="text" name="tfa_name" class="regular-text" placeholder="Ej.: Planilla de reservas (Zapier)"></label></p>
				<p><label><strong>URL del webhook</strong><br><input type="url" name="tfa_url" class="large-text" placeholder="https://hooks.zapier.com/hooks/catch/..." required></label></p>
				<p><strong>Eventos</strong></p>
				<?php foreach ( $events as $key => $label ) : ?>
					<p style="margin:4px 0"><label><input type="checkbox" name="tfa_events[]" value="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $label ); ?> <code style="color:#787c82"><?php echo esc_html( $key ); ?></code></label></p>
				<?php endforeach; ?>
				<p style="margin-top:12px"><button class="button button-primary" type="submit">Agregar destino</button></p>
			</form>
			<?php endif; ?>

			<h2 style="margin-top:28px">Últimos envíos</h2>
			<?php $log = Log::recent( 50 ); ?>
			<?php if ( empty( $log ) ) : ?>
				<p>Todavía no hubo envíos.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr><th>Cuándo</th><th>Evento</th><th>Reserva</th><th>Intento</th><th>Resultado</th><th>HTTP</th><th>Detalle</th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><code><?php echo esc_html( $row->event ); ?></code></td>
							<td><?php echo $row->booking_id ? '#' . (int) $row->booking_id : '—'; ?></td>
							<td><?php echo (int) $row->attempt; ?></td>
							<td><?php echo esc_html( $row->status ); ?></td>
							<td><?php echo $row->http_code ?: '—'; ?></td>
							<td><?php echo esc_html( $row->message ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
