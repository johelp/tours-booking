<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * TourFlow → 🛒 Compradores de guías (Pro Max).
 *
 * Lista quién compró cada producto digital (guía PDF) vendido suelto con
 * [flow_product] o desde el sitio headless, con tres cosas que Reservas no
 * da para este tipo de venta (pedido del cliente 2026-10-12):
 *  - ver comprador / producto / importe / si aceptó recibir novedades,
 *  - reenviar el email con el enlace de descarga a un comprador puntual,
 *  - exportar a CSV (todos, o solo quienes dieron consentimiento de
 *    novedades — la única lista que se puede usar para promociones).
 *
 * Solo lee amir_bookings (item_type='product') + amir_booking_addons; el
 * reenvío dispara el mismo hook que la compra original (flow_cart_confirmed →
 * CartConfirmationEmail), así que el email es idéntico y el enlace de
 * descarga tokenizado el mismo. Las reservas anonimizadas (derecho al
 * olvido) no se listan.
 */
class ProductBuyersPage {

	private const PER_PAGE = 50;

	private function lang(): string {
		return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
	}

	private function tt( string $es, string $en ): string {
		return $this->lang() === 'en' ? $en : $es;
	}

	/** @return array{status:string,addon:int,optin:bool,s:string,paged:int} */
	private function filters(): array {
		$status = sanitize_key( $_GET['status'] ?? 'confirmed' );
		return [
			'status' => in_array( $status, [ 'confirmed', 'pending', 'all' ], true ) ? $status : 'confirmed',
			'addon'  => absint( $_GET['addon'] ?? 0 ),
			'optin'  => ! empty( $_GET['optin'] ),
			's'      => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
			'paged'  => max( 1, absint( $_GET['paged'] ?? 1 ) ),
		];
	}

	/** @return array{0:string,1:array} cláusula WHERE y parámetros */
	private function build_where( array $f ): array {
		global $wpdb;
		$where  = "b.item_type = 'product' AND b.anonymized_at IS NULL";
		$params = [];

		if ( $f['status'] === 'confirmed' ) {
			$where .= " AND b.status IN ('confirmed','completed')";
		} elseif ( $f['status'] === 'pending' ) {
			$where .= " AND b.status IN ('pending','awaiting_payment','payment_expired')";
		}
		if ( $f['addon'] ) {
			$where   .= ' AND ba.addon_id = %d';
			$params[] = $f['addon'];
		}
		if ( $f['optin'] ) {
			$where .= ' AND b.marketing_opt_in = 1';
		}
		if ( $f['s'] !== '' ) {
			$like     = '%' . $wpdb->esc_like( $f['s'] ) . '%';
			$where   .= ' AND ( b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.booking_ref LIKE %s )';
			array_push( $params, $like, $like, $like );
		}
		return [ $where, $params ];
	}

	private function select_sql(): string {
		global $wpdb;
		return "SELECT b.id, b.booking_ref, b.cart_group_id, b.customer_name, b.customer_email,
		               b.status, b.total_mxn, b.created_at, b.confirmed_at,
		               b.marketing_opt_in, b.marketing_opt_in_at,
		               ba.addon_id, ba.name_snapshot
		        FROM {$wpdb->prefix}amir_bookings b
		        LEFT JOIN {$wpdb->prefix}amir_booking_addons ba ON ba.booking_id = b.id";
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
		}

		global $wpdb;
		$message = $this->handle_actions();
		$f       = $this->filters();
		[ $where, $params ] = $this->build_where( $f );

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings b
		              LEFT JOIN {$wpdb->prefix}amir_booking_addons ba ON ba.booking_id = b.id
		              WHERE $where";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$offset = ( $f['paged'] - 1 ) * self::PER_PAGE;
		$sql    = $this->select_sql() . " WHERE $where ORDER BY b.created_at DESC, b.id DESC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ self::PER_PAGE, $offset ] ) ) ) ?: [];

		$products = $wpdb->get_results(
			"SELECT id, name_es, name_en FROM {$wpdb->prefix}amir_addons
			 WHERE applies_to = 'global' AND pricing_type = 'digital' ORDER BY name_es"
		) ?: [];

		$optin_total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT customer_email) FROM {$wpdb->prefix}amir_bookings
			 WHERE item_type = 'product' AND marketing_opt_in = 1 AND anonymized_at IS NULL AND status IN ('confirmed','completed')"
		);

		$base    = admin_url( 'admin.php?page=amir-product-buyers' );
		$qs      = array_filter( [
			'status' => $f['status'] !== 'confirmed' ? $f['status'] : '',
			'addon'  => $f['addon'] ?: '',
			'optin'  => $f['optin'] ? 1 : '',
			's'      => $f['s'],
		] );
		$export  = add_query_arg( array_merge( $qs, [ 'export' => 'csv' ] ), $base );
		$export_optin = add_query_arg( array_merge( $qs, [ 'optin' => 1, 'export' => 'csv' ] ), $base );
		$pages   = (int) ceil( $total / self::PER_PAGE );
		?>
		<div class="wrap ab-admin-wrap" style="max-width:1100px;">
		  <h1 style="margin-bottom:6px;">🛒 <?php echo esc_html( $this->tt( 'Compradores de guías', 'Guide buyers' ) ); ?></h1>
		  <p style="color:#5a7068;margin:0 0 16px;max-width:760px;">
			<?php echo esc_html( $this->tt(
				'Quién compró cada producto digital. Podés reenviarle el email con el enlace de descarga y exportar la lista. Para enviar promociones usá solo a quienes marcaron "quiero recibir novedades".',
				'Who bought each digital product. You can resend the download-link email and export the list. For promotions, use only people who ticked "I want to receive news".'
			) ); ?>
		  </p>

		  <?php if ( $message ) : ?>
			<div class="notice notice-<?php echo esc_attr( $message[0] ); ?> is-dismissible"><p><?php echo esc_html( $message[1] ); ?></p></div>
		  <?php endif; ?>

		  <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;">
			<input type="hidden" name="page" value="amir-product-buyers" />
			<select name="status">
			  <option value="confirmed" <?php selected( $f['status'], 'confirmed' ); ?>><?php echo esc_html( $this->tt( 'Pagadas', 'Paid' ) ); ?></option>
			  <option value="pending" <?php selected( $f['status'], 'pending' ); ?>><?php echo esc_html( $this->tt( 'Sin pagar', 'Unpaid' ) ); ?></option>
			  <option value="all" <?php selected( $f['status'], 'all' ); ?>><?php echo esc_html( $this->tt( 'Todas', 'All' ) ); ?></option>
			</select>
			<select name="addon">
			  <option value="0"><?php echo esc_html( $this->tt( 'Todos los productos', 'All products' ) ); ?></option>
			  <?php foreach ( $products as $p ) : ?>
				<option value="<?php echo (int) $p->id; ?>" <?php selected( $f['addon'], (int) $p->id ); ?>><?php echo esc_html( $this->lang() === 'en' ? ( $p->name_en ?: $p->name_es ) : $p->name_es ); ?></option>
			  <?php endforeach; ?>
			</select>
			<label style="display:flex;align-items:center;gap:5px;font-size:13px;">
			  <input type="checkbox" name="optin" value="1" <?php checked( $f['optin'] ); ?> />
			  <?php echo esc_html( $this->tt( 'Solo con consentimiento de novedades', 'Only with news consent' ) ); ?>
			</label>
			<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="<?php echo esc_attr( $this->tt( 'Nombre, email o referencia', 'Name, email or reference' ) ); ?>" style="min-width:220px;" />
			<button class="button"><?php echo esc_html( $this->tt( 'Filtrar', 'Filter' ) ); ?></button>
		  </form>

		  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px;">
			<span style="font-size:13px;color:#5a7068;">
			  <?php echo esc_html( sprintf( $this->tt( '%d compras · %d personas aceptaron novedades', '%d orders · %d people accepted news' ), $total, $optin_total ) ); ?>
			</span>
			<a class="button" href="<?php echo esc_url( $export ); ?>">⬇ <?php echo esc_html( $this->tt( 'Exportar CSV (lo filtrado)', 'Export CSV (filtered)' ) ); ?></a>
			<a class="button" href="<?php echo esc_url( $export_optin ); ?>">⬇ <?php echo esc_html( $this->tt( 'Exportar solo quienes aceptaron novedades', 'Export only people who accepted news' ) ); ?></a>
		  </div>

		  <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:auto;">
		  <table style="width:100%;border-collapse:collapse;">
			<thead><tr style="background:#f8fdfb;">
			  <th class="ab-th"><?php echo esc_html( $this->tt( 'Fecha', 'Date' ) ); ?></th>
			  <th class="ab-th"><?php echo esc_html( $this->tt( 'Comprador', 'Buyer' ) ); ?></th>
			  <th class="ab-th"><?php echo esc_html( $this->tt( 'Producto', 'Product' ) ); ?></th>
			  <th class="ab-th"><?php echo esc_html( $this->tt( 'Importe', 'Amount' ) ); ?></th>
			  <th class="ab-th"><?php echo esc_html( $this->tt( 'Estado', 'Status' ) ); ?></th>
			  <th class="ab-th"><?php echo esc_html( $this->tt( 'Novedades', 'News' ) ); ?></th>
			  <th class="ab-th"></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
			  <tr><td colspan="7" class="ab-td" style="text-align:center;color:#5a7068;padding:24px;"><?php echo esc_html( $this->tt( 'Todavía no hay compras con este filtro.', 'No orders match this filter yet.' ) ); ?></td></tr>
			<?php else : foreach ( $rows as $r ) :
				$paid = in_array( $r->status, [ 'confirmed', 'completed' ], true );
				?>
			  <tr>
				<td class="ab-td" style="white-space:nowrap;"><?php echo esc_html( mysql2date( 'Y-m-d H:i', $r->created_at ) ); ?></td>
				<td class="ab-td">
				  <strong><?php echo esc_html( $r->customer_name ); ?></strong><br />
				  <a href="mailto:<?php echo esc_attr( $r->customer_email ); ?>"><?php echo esc_html( $r->customer_email ); ?></a><br />
				  <span style="color:#8a9a93;font-size:11px;"><?php echo esc_html( $r->booking_ref ); ?></span>
				</td>
				<td class="ab-td"><?php echo esc_html( $r->name_snapshot ?: '—' ); ?></td>
				<td class="ab-td" style="white-space:nowrap;"><?php echo esc_html( \AmirBooking\Core\Currency::format( (float) $r->total_mxn ) ); ?></td>
				<td class="ab-td"><?php echo $paid ? '✅ ' . esc_html( $this->tt( 'Pagada', 'Paid' ) ) : '⏳ ' . esc_html( $this->tt( 'Sin pagar', 'Unpaid' ) ); ?></td>
				<td class="ab-td">
				  <?php if ( $r->marketing_opt_in ) : ?>
					✅ <span style="font-size:11px;color:#5a7068;"><?php echo esc_html( $r->marketing_opt_in_at ? mysql2date( 'Y-m-d', $r->marketing_opt_in_at ) : '' ); ?></span>
				  <?php else : ?>
					<span style="color:#8a9a93;">—</span>
				  <?php endif; ?>
				</td>
				<td class="ab-td" style="text-align:right;">
				  <?php if ( 'confirmed' === $r->status && ! empty( $r->cart_group_id ) ) : ?>
					<form method="post" style="margin:0;">
					  <?php wp_nonce_field( 'amir_product_buyers_action' ); ?>
					  <input type="hidden" name="amir_action" value="resend_download" />
					  <input type="hidden" name="cart_group_id" value="<?php echo esc_attr( $r->cart_group_id ); ?>" />
					  <button type="submit" class="button">📧 <?php echo esc_html( $this->tt( 'Reenviar enlace', 'Resend link' ) ); ?></button>
					</form>
				  <?php endif; ?>
				</td>
			  </tr>
			<?php endforeach; endif; ?>
			</tbody>
		  </table>
		  </div>

		  <?php if ( $pages > 1 ) : ?>
			<p style="margin-top:12px;">
			  <?php for ( $i = 1; $i <= $pages; $i++ ) :
				$url = add_query_arg( array_merge( $qs, [ 'paged' => $i ] ), $base ); ?>
				<a class="button <?php echo $i === $f['paged'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo (int) $i; ?></a>
			  <?php endfor; ?>
			</p>
		  <?php endif; ?>
		</div>
		<style>.ab-th{padding:10px 12px;text-align:left;font-size:12px;color:#5a7068;border-bottom:1px solid #e1f5ee}.ab-td{padding:10px 12px;border-bottom:1px solid #f0f7f4;font-size:13px;vertical-align:top}</style>
		<?php
	}

	/** @return array{0:string,1:string}|null [tipo de aviso, texto] */
	private function handle_actions(): ?array {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( $_POST['amir_action'] ?? '' ) !== 'resend_download' ) {
			return null;
		}
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_product_buyers_action' ) ) {
			return [ 'error', $this->tt( 'La sesión venció. Recargá la página e intentá de nuevo.', 'Session expired. Reload the page and try again.' ) ];
		}

		global $wpdb;
		$cart_group_id = sanitize_text_field( wp_unslash( $_POST['cart_group_id'] ?? '' ) );
		$ok = $cart_group_id !== '' && (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings
			 WHERE cart_group_id = %s AND item_type = 'product' AND status = 'confirmed'",
			$cart_group_id
		) ) > 0;
		if ( ! $ok ) {
			return [ 'error', $this->tt( 'No se encontró una compra pagada para reenviar.', 'No paid order found to resend.' ) ];
		}

		// Mismo hook que dispara la compra original: manda el email del
		// carrito con el enlace de descarga tokenizado de ESA compra.
		do_action( 'flow_cart_confirmed', $cart_group_id );
		return [ 'success', $this->tt( 'Email con el enlace de descarga reenviado.', 'Email with the download link resent.' ) ];
	}

	/** Pública: la llama AdminMenu::maybe_export_product_buyers_csv() (admin_init, antes de cualquier HTML). */
	public function export_csv(): void {
		global $wpdb;
		$f = $this->filters();
		[ $where, $params ] = $this->build_where( $f );
		$sql  = $this->select_sql() . " WHERE $where ORDER BY b.created_at DESC, b.id DESC";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="compradores-guias-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel abre bien las tildes
		fputcsv( $out, [ 'fecha', 'referencia', 'nombre', 'email', 'producto', 'importe', 'estado', 'acepta_novedades', 'fecha_consentimiento' ] );
		foreach ( (array) $rows as $r ) {
			fputcsv( $out, [
				$r->created_at,
				$r->booking_ref,
				$this->csv_safe( $r->customer_name ),
				$this->csv_safe( $r->customer_email ),
				$this->csv_safe( $r->name_snapshot ),
				$r->total_mxn,
				$r->status,
				$r->marketing_opt_in ? 'si' : 'no',
				$r->marketing_opt_in_at ?: '',
			] );
		}
		fclose( $out );
		exit;
	}

	/** Misma protección contra inyección de fórmulas CSV que BookingsPage::csv_safe(). */
	private function csv_safe( $value ): string {
		$value = (string) $value;
		return ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) ? "'" . $value : $value;
	}
}
