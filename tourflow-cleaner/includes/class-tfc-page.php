<?php
namespace TourFlowCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla de admin — gateada a `manage_options` SOLO (nunca
 * `manage_amir_booking`/Tour Manager, a diferencia del resto del admin de
 * TourFlow: esto borra datos sin posibilidad de deshacer, es
 * deliberadamente más restrictivo). Confirmación de dos pasos: hay que
 * tildar categorías Y escribir el dominio exacto del sitio para habilitar
 * el botón — mismo criterio que "escribí el nombre del repo" en GitHub
 * antes de un borrado irreversible.
 */
class Page {

	private const NONCE_ACTION = 'tfc_run_cleanup';

	public static function register_menu(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	public static function add_menu(): void {
		add_menu_page(
			'TourFlow Cleaner',
			'🧹 TF Cleaner',
			'manage_options',
			'tourflow-cleaner',
			[ __CLASS__, 'render' ],
			'dashicons-trash',
			99 // al fondo del todo — herramienta interna, no algo con lo que un admin tropiece por accidente
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tenés permisos para acceder a esta página.' );
		}

		$result = null;
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			$result = self::handle_submit();
		}

		$categories    = Cleaner::available_categories();
		$counts        = Cleaner::counts();
		$expected_host = wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<div class="wrap">
			<h1>🧹 TourFlow Cleaner</h1>
			<p style="max-width:700px;">Herramienta <strong>interna</strong> — borra datos de prueba de TourFlow antes de entregar/lanzar un sitio real. No toca Configuración/Personalización ni las páginas que crea el instalador, solo los datos elegidos abajo. <strong>No hay deshacer.</strong></p>

			<?php if ( $result ) : ?>
				<div class="notice notice-success">
					<p><strong>Listo.</strong> <?php echo esc_html( self::format_result( $result ) ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" id="tfc-form" onsubmit="return TFCConfirm();">
				<?php wp_nonce_field( self::NONCE_ACTION, 'tfc_nonce' ); ?>

				<table class="widefat striped" style="max-width:700px;margin:16px 0;">
					<thead><tr><th style="width:36px;"></th><th>Categoría</th><th style="width:120px;">Filas/posts</th></tr></thead>
					<tbody>
					<?php foreach ( $categories as $key => $cat ) : ?>
						<tr>
							<td><input type="checkbox" name="tfc_categories[]" value="<?php echo esc_attr( $key ); ?>" class="tfc-cat" /></td>
							<td><?php echo esc_html( $cat['label'] ); ?></td>
							<td><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<label><input type="checkbox" id="tfc-select-all" /> Seleccionar todo</label>
				</p>

				<div style="max-width:700px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
					<label style="font-weight:600;color:#991b1b;">
						<input type="checkbox" name="tfc_delete_media" value="1" />
						También borrar las imágenes de la Media Library subidas a los tours/habitaciones seleccionados arriba
					</label>
					<p style="font-size:12px;color:#7f1d1d;margin:6px 0 0;">Más irreversible todavía que lo de arriba — borra el archivo físico, no solo la referencia. Dejalo destildado salvo que sepas que esas fotos no se usan en ningún otro lado.</p>
				</div>

				<div style="max-width:700px;background:#fff8e7;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;">
					<label for="tfc-confirm-input" style="font-weight:600;display:block;margin-bottom:6px;">
						Para confirmar, escribí el dominio de este sitio: <code><?php echo esc_html( $expected_host ); ?></code>
					</label>
					<input type="text" id="tfc-confirm-input" name="tfc_confirm_host" style="width:100%;max-width:400px;" autocomplete="off" />
				</div>

				<p style="margin-top:16px;">
					<button type="submit" id="tfc-submit" class="button button-primary" disabled style="background:#dc2626;border-color:#b91c1c;">🗑 Borrar lo seleccionado</button>
				</p>
			</form>
		</div>

		<script>
		(function () {
			var expected = <?php echo wp_json_encode( $expected_host ); ?>;
			var input    = document.getElementById('tfc-confirm-input');
			var submit   = document.getElementById('tfc-submit');
			var selectAll = document.getElementById('tfc-select-all');
			var cats     = document.querySelectorAll('.tfc-cat');

			function refresh() {
				var anyChecked = Array.prototype.some.call(cats, function (c) { return c.checked; });
				submit.disabled = ! ( anyChecked && input.value.trim() === expected );
			}
			input.addEventListener('input', refresh);
			Array.prototype.forEach.call(cats, function (c) { c.addEventListener('change', refresh); });
			selectAll.addEventListener('change', function () {
				Array.prototype.forEach.call(cats, function (c) { c.checked = selectAll.checked; });
				refresh();
			});

			window.TFCConfirm = function () {
				var checked = Array.prototype.filter.call(cats, function (c) { return c.checked; }).map(function (c) { return c.parentElement.parentElement.children[1].textContent; });
				return confirm('Vas a borrar de forma PERMANENTE:\n\n- ' + checked.join('\n- ') + '\n\n¿Seguro?');
			};
		})();
		</script>
		<?php
	}

	private static function handle_submit(): ?array {
		if ( ! isset( $_POST['tfc_nonce'] ) || ! wp_verify_nonce( $_POST['tfc_nonce'], self::NONCE_ACTION ) ) {
			return null;
		}

		// Segunda verificación server-side del dominio — el JS de arriba es
		// solo UX, esto es lo que de verdad importa (un POST armado a mano
		// sin pasar por el form no tendría el JS corriendo).
		$expected_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$typed_host    = sanitize_text_field( wp_unslash( $_POST['tfc_confirm_host'] ?? '' ) );
		if ( trim( $typed_host ) !== $expected_host ) {
			echo '<div class="notice notice-error"><p>El dominio escrito no coincide — no se borró nada.</p></div>';
			return null;
		}

		$selected = array_map( 'sanitize_key', (array) ( $_POST['tfc_categories'] ?? [] ) );
		if ( empty( $selected ) ) {
			echo '<div class="notice notice-error"><p>No se tildó ninguna categoría — no se borró nada.</p></div>';
			return null;
		}

		$delete_media = ! empty( $_POST['tfc_delete_media'] );
		return Cleaner::run( $selected, $delete_media );
	}

	private static function format_result( array $result ): string {
		$labels = Cleaner::categories();
		$parts  = [];
		foreach ( $result as $key => $n ) {
			if ( $key === 'media' ) {
				$parts[] = "$n imágenes";
				continue;
			}
			$label   = $labels[ $key ]['label'] ?? $key;
			$parts[] = "$label: $n";
		}
		return implode( ' · ', $parts );
	}
}
