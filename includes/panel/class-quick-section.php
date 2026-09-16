<?php
namespace TourFlow\Panel;

defined( 'ABSPATH' ) || exit;

/**
 * "Panel rápido" — pedido del cliente (2026-09-15) para un nivel de acceso
 * chico (`AmirBooking\Core\QuickPanelRole`): quién llega, cuándo, con
 * quiénes, y si la reserva tiene saldo pendiente o no — SIN montos, precios
 * ni pagos, salvo para el rol `amir_quick_cashier`, que además puede ver el
 * saldo en $ y marcarlo cobrado (mismo criterio y misma acción que ya usa
 * `BookingsPage::handle_detail_action()` case 'mark_balance_paid' — se
 * replica acá en vez de reusar ese método porque es privado y esta vista es
 * deliberadamente angosta: nunca pasa por el resto de esa pantalla, donde
 * el total/depósito/reembolso aparecen en casi cada fila).
 *
 * A diferencia de Reservas/Calendario/etc. (páginas admin existentes
 * reusadas tal cual, ver ManagerPanel), esta sección es nueva y propia del
 * panel — mismo patrón estático que ToursSection/RoomsSection.
 */
class QuickSection {

	public static function render( string $base_url, string $lang ): void {
		$can_view = current_user_can( \AmirBooking\Core\QuickPanelRole::CAP_VIEW )
			|| current_user_can( 'manage_amir_booking' ) || current_user_can( 'manage_options' );
		if ( ! $can_view ) {
			wp_die( esc_html( self::tt( $lang, 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
		}

		$can_payments = current_user_can( \AmirBooking\Core\QuickPanelRole::CAP_PAYMENTS )
			|| current_user_can( 'manage_amir_booking' ) || current_user_can( 'manage_options' );

		$message = $can_payments ? self::handle_mark_paid( $lang ) : '';

		$days  = max( 1, min( 90, (int) ( $_GET['days'] ?? 14 ) ) );
		$from  = current_time( 'Y-m-d' );
		$until = date( 'Y-m-d', strtotime( "+{$days} days" ) );
		$rows  = self::query_upcoming( $from, $until );

		self::render_list( $rows, $base_url, $lang, $days, $can_payments, $message );
	}

	// ==================== ACCIÓN: marcar saldo cobrado ====================

	/** Misma validación/efecto que BookingsPage::handle_detail_action() case 'mark_balance_paid' — ver docstring de la clase. */
	private static function handle_mark_paid( string $lang ): string {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ( $_POST['tfp_quick_action'] ?? '' ) !== 'mark_balance_paid' ) {
			return '';
		}
		if ( ! check_admin_referer( 'tfp_quick_mark_paid', 'tfp_quick_nonce', false ) ) {
			return '<div class="notice notice-error"><p>' . esc_html( self::tt( $lang, 'Sesión expirada, intentá de nuevo.', 'Session expired, try again.' ) ) . '</p></div>';
		}

		global $wpdb;
		$booking_id = (int) ( $_POST['booking_id'] ?? 0 );
		$b = ( new \AmirBooking\Core\BookingManager() )->get_booking( $booking_id );
		if ( ! $b || $b->status !== 'confirmed' || ( $b->item_type ?? 'tour' ) !== 'tour'
			|| (int) ( $b->deposit_pct ?? 0 ) <= 0 || ! empty( $b->balance_paid_at ) ) {
			return '<div class="notice notice-error"><p>' . esc_html( self::tt( $lang, 'Esta reserva no tiene un saldo de depósito pendiente.', 'This booking has no pending deposit balance.' ) ) . '</p></div>';
		}
		$wpdb->update( "{$wpdb->prefix}amir_bookings", [ 'balance_paid_at' => current_time( 'mysql' ) ], [ 'id' => $booking_id ], [ '%s' ], [ '%d' ] );
		return '<div class="notice notice-success"><p>✅ ' . esc_html( self::tt( $lang, 'Saldo marcado como cobrado.', 'Balance marked as collected.' ) ) . '</p></div>';
	}

	// ==================== CONSULTA ====================

	/**
	 * Solo columnas necesarias para esta vista — deliberadamente NO trae
	 * total_mxn/charge_mxn/refund_amount_mxn (nunca deben poder mostrarse acá
	 * por accidente, ni siquiera detrás de un `if ($can_payments)` que
	 * alguien podría romper después). deposit_pct/balance_paid_at sí se
	 * traen siempre — son necesarios para el badge "saldo pendiente: sí/no"
	 * incluso para el rol sin $, que ve el badge pero no el monto.
	 */
	private static function query_upcoming( string $from, string $until ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.booking_ref, b.item_type, b.tour_date, b.time_start,
			        b.customer_name, b.adults, b.children, b.babies, b.participant_names,
			        b.status, b.deposit_pct, b.balance_paid_at,
			        t.name_es as tour_name_es, t.name_en as tour_name_en,
			        r.name_es as room_name_es, r.name_en as room_name_en
			 FROM {$wpdb->prefix}amir_bookings b
			 LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
			 LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
			 WHERE b.tour_date BETWEEN %s AND %s
			   AND b.status IN ('confirmed','pending','awaiting_payment')
			 ORDER BY b.tour_date ASC, b.time_start ASC",
			$from, $until
		) ) ?? [];
	}

	// ==================== VISTA ====================

	private static function render_list( array $rows, string $base_url, string $lang, int $days, bool $can_payments, string $message ): void {
		?>
		<main class="tfp-main">
			<div class="tfp-title-row">
				<h1>📋 <?php echo esc_html( self::tt( $lang, 'Panel rápido', 'Quick panel' ) ); ?></h1>
			</div>

			<?php echo $message; // ya viene escapado en handle_mark_paid() ?>

			<p style="color:#5a7068;font-size:13px;margin:-4px 0 14px;">
				<?php echo esc_html( self::tt( $lang,
					'Quién llega, cuándo y con quiénes — sin montos ni precios.',
					'Who\'s arriving, when, and with whom — no amounts or prices.'
				) ); ?>
			</p>

			<div class="tfp-quick-days">
				<?php foreach ( [ 7, 14, 30, 60 ] as $d ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'days', $d, $base_url ) ); ?>"
					   class="button <?php echo $d === $days ? 'button-primary' : ''; ?>"><?php echo esc_html( sprintf( self::tt( $lang, '%d días', '%d days' ), $d ) ); ?></a>
				<?php endforeach; ?>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php echo esc_html( self::tt( $lang, 'No hay reservas en este rango de fechas.', 'No bookings in this date range.' ) ); ?></p>
			<?php else : ?>
			<div class="tfp-quick-list">
				<?php foreach ( $rows as $b ) : self::render_item( $b, $lang, $can_payments ); endforeach; ?>
			</div>
			<?php endif; ?>
		</main>
		<?php
	}

	/**
	 * Tarjeta, no fila de tabla — pedido explícito del cliente (2026-09-15):
	 * "100% óptimo en móviles", y este panel es justo el caso de uso más
	 * mobile-first de todo el plugin (personal de campo mirando el celular,
	 * no un escritorio). El resto del panel resuelve tablas reusadas de
	 * wp-admin con scroll horizontal (`.tfp-main table`, panel.css) — acá,
	 * al ser una vista nueva sin nada que reusar, se evita esa muleta desde
	 * el diseño: una columna siempre, en cualquier ancho, sin media query.
	 */
	private static function render_item( object $b, string $lang, bool $can_payments ): void {
		$activity = $lang === 'en'
			? ( $b->tour_name_en ?: $b->room_name_en ?: $b->tour_name_es ?: $b->room_name_es ?: $b->booking_ref )
			: ( $b->tour_name_es ?: $b->room_name_es ?: $b->booking_ref );

		$pending_balance = $b->status === 'confirmed' && ( $b->item_type ?? 'tour' ) === 'tour'
			&& (int) ( $b->deposit_pct ?? 0 ) > 0 && empty( $b->balance_paid_at );
		$has_deposit = (int) ( $b->deposit_pct ?? 0 ) > 0;

		?>
		<div class="tfp-quick-item">
			<div class="tfp-quick-item-top">
				<div class="tfp-quick-item-date">
					<strong><?php echo esc_html( self::fmt_date( $b->tour_date, $lang ) ); ?></strong>
					<?php if ( ! empty( $b->time_start ) ) : ?><span><?php echo esc_html( self::fmt_time( $b->time_start ) ); ?></span><?php endif; ?>
				</div>
				<?php echo self::status_badge( $b->status, $lang ); ?>
			</div>
			<div class="tfp-quick-item-activity"><?php echo esc_html( $activity ); ?></div>
			<div class="tfp-quick-item-ref"><?php echo esc_html( $b->booking_ref ); ?></div>
			<div class="tfp-quick-item-people"><?php echo self::participants_cell( $b, $lang ); // ya escapado adentro ?></div>
			<div class="tfp-quick-item-bottom">
				<?php if ( ! $has_deposit ) : ?>
					<span style="color:#5a7068;font-size:12.5px;">— <?php echo esc_html( self::tt( $lang, 'sin depósito', 'no deposit' ) ); ?></span>
				<?php elseif ( $pending_balance ) : ?>
					<span style="background:#fff8e7;color:#BA7517;padding:3px 10px;border-radius:10px;font-size:12.5px;font-weight:700;white-space:nowrap;">⏳ <?php echo esc_html( self::tt( $lang, 'Saldo pendiente', 'Balance pending' ) ); ?></span>
				<?php else : ?>
					<span style="background:#e1f5ee;color:var(--ab-teal-dark, #0F6E56);padding:3px 10px;border-radius:10px;font-size:12.5px;font-weight:700;white-space:nowrap;">✓ <?php echo esc_html( self::tt( $lang, 'Al día', 'Paid up' ) ); ?></span>
				<?php endif; ?>

				<?php if ( $can_payments && $pending_balance ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'tfp_quick_mark_paid', 'tfp_quick_nonce' ); ?>
					<input type="hidden" name="tfp_quick_action" value="mark_balance_paid" />
					<input type="hidden" name="booking_id" value="<?php echo (int) $b->id; ?>" />
					<button type="submit" class="button button-primary tfp-quick-btn">💰 <?php echo esc_html( self::tt( $lang, 'Marcar cobrado', 'Mark collected' ) ); ?></button>
				</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Nombres de cada integrante si el tour los pidió (amir_tours.require_participant_names) — si no, el desglose de personas nada más. Nunca un monto. */
	private static function participants_cell( object $b, string $lang ): string {
		$names = json_decode( $b->participant_names ?: '[]', true );
		if ( is_array( $names ) && ! empty( $names ) ) {
			return esc_html( implode( ', ', $names ) );
		}

		$parts = [];
		if ( (int) $b->adults   > 0 ) $parts[] = $b->adults   . ' ' . self::tt( $lang, 'adultos', 'adults' );
		if ( (int) $b->children > 0 ) $parts[] = $b->children . ' ' . self::tt( $lang, 'niños',   'children' );
		if ( (int) $b->babies   > 0 ) $parts[] = $b->babies   . ' ' . self::tt( $lang, 'bebés',   'infants' );

		return esc_html( $b->customer_name ) . '<br><span style="color:#5a7068;font-size:12px;">' . esc_html( implode( ', ', $parts ) ) . '</span>';
	}

	/** Subconjunto chico de status_labels() de BookingsPage — esta vista solo muestra 3 estados (ver query_upcoming()), no hace falta el diccionario completo. */
	private static function status_badge( string $status, string $lang ): string {
		$map = [
			'confirmed'        => [ self::tt( $lang, '✓ Confirmada', '✓ Confirmed' ),        '#e1f5ee', 'var(--ab-teal-dark, #0F6E56)' ],
			'pending'          => [ self::tt( $lang, '⏳ Pendiente de pago', '⏳ Awaiting payment' ), '#fff8e7', '#BA7517' ],
			'awaiting_payment' => [ self::tt( $lang, '⏳ Pendiente de pago', '⏳ Awaiting payment' ), '#fff8e7', '#BA7517' ],
		];
		[ $label, $bg, $fg ] = $map[ $status ] ?? [ esc_html( $status ), '#f0faf6', '#5a7068' ];
		return '<span style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;white-space:nowrap;">' . esc_html( $label ) . '</span>';
	}

	private static function fmt_date( string $date, string $lang ): string {
		static $months_es = [ 'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic' ];
		static $months_en = [ 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec' ];
		$months = $lang === 'en' ? $months_en : $months_es;
		[ $y, $m, $d ] = explode( '-', $date );
		return (int) $d . ' ' . $months[ (int) $m - 1 ];
	}

	private static function fmt_time( string $time ): string {
		[ $h, $m ] = explode( ':', $time );
		$hnum = (int) $h;
		$ampm = $hnum >= 12 ? 'PM' : 'AM';
		$h12  = $hnum > 12 ? $hnum - 12 : ( $hnum ?: 12 );
		return "{$h12}:{$m} {$ampm}";
	}

	private static function tt( string $lang, string $es, string $en ): string {
		return $lang === 'en' ? $en : $es;
	}
}
