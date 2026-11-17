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
 *
 * **Ampliado 2026-11-16** (pedido explícito del cliente, book.caliafarm.com
 * en vivo — "necesitamos que [los paneles rápidos] queden funcionales"):
 * contacto (teléfono/email) y requerimientos (special_requests) del cliente,
 * resumen de llegadas/salidas de hoy/mañana/pasado mañana, y — SOLO para
 * `amir_quick_cashier` — el monto pagado/pendiente por reserva (nunca el
 * detalle completo de pagos: sin IDs de transacción, sin historial de
 * reembolsos, eso sigue siendo exclusivo de Reservas/Log de pagos). Mismo
 * criterio de antes para `amir_quick_staff`: esos montos ni siquiera se
 * TRAEN de la base para ese rol (ver `query_bookings()`), no solo se ocultan
 * en el render — así un bug futuro en la vista no puede filtrarlos.
 *
 * **Revisión 2026-11-17** (pedido explícito del cliente: "no se asemejan al
 * tourmanager... deben compartir estética"): el resumen de días y las
 * estadísticas nuevas de este archivo reusan `.tfp-card`/`.tfp-stats`/
 * `.tfp-stat` — el MISMO vocabulario visual que ya usan el Dashboard del
 * panel (`ManagerPanel::dashboard()`) y Tours/Rooms — en vez de clases
 * propias inventadas (`.tfp-quick-summary*`, ahora eliminadas). También se
 * agregó: ver reservas PASADAS, no solo próximas (`mode=past`), y un rango
 * de fechas libre (`from`/`until`) más allá de los botones de 7/14/30/60
 * días — con estadísticas de personas que ya hicieron un tour y personas
 * que ya se alojaron, para el rango que se esté mirando.
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

		$message  = $can_payments ? self::handle_mark_paid( $lang ) : '';
		$forecast = self::query_day_summary();

		[ $mode, $from, $until, $days, $is_custom ] = self::resolve_range();

		$rows  = self::query_bookings( $from, $until, $mode, $can_payments );
		$stats = ( $mode === 'past' || $is_custom ) ? self::query_period_stats( $from, $until ) : null;

		self::render_list( compact(
			'rows', 'base_url', 'lang', 'days', 'mode', 'from', 'until', 'is_custom',
			'can_payments', 'message', 'forecast', 'stats'
		) );
	}

	/**
	 * Qué rango de fechas mirar, a partir de 3 controles independientes en
	 * el GET: `mode` (próximas/pasadas, default próximas), los botones de
	 * días (7/14/30/60, relativos a hoy en la dirección de `mode`), y un
	 * rango libre (`from`/`until`) que pisa a los otros dos cuando viene
	 * completo y válido — pedido explícito del cliente: "permitir ver
	 * reservas anteriores/próximas más allá del umbral de días que proponen
	 * los botones".
	 */
	private static function resolve_range(): array {
		$today = current_time( 'Y-m-d' );
		$mode  = ( $_GET['mode'] ?? 'upcoming' ) === 'past' ? 'past' : 'upcoming';
		$days  = max( 1, min( 365, (int) ( $_GET['days'] ?? 14 ) ) );

		$custom_from  = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) );
		$custom_until = sanitize_text_field( wp_unslash( $_GET['until'] ?? '' ) );
		$is_custom    = self::is_valid_date( $custom_from ) && self::is_valid_date( $custom_until );

		if ( $is_custom ) {
			$from  = min( $custom_from, $custom_until );
			$until = max( $custom_from, $custom_until );
		} elseif ( $mode === 'past' ) {
			$until = date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );
			$from  = date( 'Y-m-d', strtotime( "-{$days} days", strtotime( $today ) ) );
		} else {
			$from  = $today;
			$until = date( 'Y-m-d', strtotime( "+{$days} days", strtotime( $today ) ) );
		}

		return [ $mode, $from, $until, $days, $is_custom ];
	}

	private static function is_valid_date( string $value ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && strtotime( $value ) !== false;
	}

	// ==================== RESUMEN HOY / MAÑANA / PASADO MAÑANA ====================

	/**
	 * Conteos y personas, no montos — seguro para ambos roles del Panel
	 * rápido. "Llegan" cuenta cualquier reserva (tour u habitación) con esa
	 * fecha; "se van" solo aplica a habitaciones (un tour no tiene checkout,
	 * es un solo día).
	 */
	private static function query_day_summary(): array {
		global $wpdb;
		$out = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$date     = date( 'Y-m-d', strtotime( "+{$i} days" ) );
			$arrivals = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(adults + children + babies), 0) AS guests
				 FROM {$wpdb->prefix}amir_bookings
				 WHERE tour_date = %s AND status IN ('confirmed','pending','awaiting_payment')",
				$date
			) );
			$departures = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(adults + children + babies), 0) AS guests
				 FROM {$wpdb->prefix}amir_bookings
				 WHERE check_out_date = %s AND item_type = 'room' AND status = 'confirmed'",
				$date
			) );
			$out[] = [
				'date'           => $date,
				'checkins'       => (int) ( $arrivals->cnt ?? 0 ),
				'checkin_guests' => (int) ( $arrivals->guests ?? 0 ),
				'checkouts'      => (int) ( $departures->cnt ?? 0 ),
				'checkout_guests' => (int) ( $departures->guests ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * "Personas que pasaron" (tours) / "personas que se alojaron"
	 * (habitaciones) para el rango que se esté mirando — pedido explícito
	 * del cliente. Nunca monto: son conteos de personas, calculados aparte
	 * de `query_bookings()` para no acoplar esta estadística a qué columnas
	 * trae esa consulta para cada rol.
	 */
	private static function query_period_stats( string $from, string $until ): array {
		global $wpdb;
		$tour_pax = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(adults + children + babies), 0) FROM {$wpdb->prefix}amir_bookings
			 WHERE tour_date BETWEEN %s AND %s
			   AND ( item_type = 'tour' OR item_type IS NULL )
			   AND status IN ('confirmed','completed')",
			$from, $until
		) );
		$room_pax = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(adults + children + babies), 0) FROM {$wpdb->prefix}amir_bookings
			 WHERE check_out_date BETWEEN %s AND %s
			   AND item_type = 'room'
			   AND status IN ('confirmed','completed')",
			$from, $until
		) );
		return [ 'tour_pax' => $tour_pax, 'room_pax' => $room_pax ];
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
	 * Columnas necesarias para esta vista. `total_mxn`/`charge_mxn` —
	 * deliberadamente NO se agregan al SELECT salvo que `$can_payments` sea
	 * true (nunca deben poder mostrarse por accidente para `amir_quick_staff`,
	 * ni siquiera detrás de un `if()` en el render que alguien podría romper
	 * después — ver docstring de la clase). `refund_amount_mxn`/IDs de
	 * transacción siguen sin traerse para NINGÚN rol de este panel: "lo que
	 * falta/ya pagó" (money_line()) es un resumen, no el detalle completo de
	 * pagos. deposit_pct/balance_paid_at sí se traen siempre — necesarios
	 * para el badge "saldo pendiente: sí/no" incluso para el rol sin $.
	 *
	 * `$mode='past'` suma `'completed'` al filtro de estado (una reserva
	 * pasada puede haber quedado marcada así por el cron/reportes) y ordena
	 * de más reciente a más antigua — lo más útil al revisar historial.
	 */
	private static function query_bookings( string $from, string $until, string $mode, bool $can_payments ): array {
		global $wpdb;
		$money_cols = $can_payments ? ', b.total_mxn, b.charge_mxn' : '';
		$statuses   = $mode === 'past'
			? [ 'confirmed', 'pending', 'awaiting_payment', 'completed' ]
			: [ 'confirmed', 'pending', 'awaiting_payment' ];
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$order        = $mode === 'past' ? 'DESC' : 'ASC';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.booking_ref, b.item_type, b.tour_date, b.check_out_date, b.time_start,
			        b.customer_name, b.customer_phone, b.customer_email, b.special_requests,
			        b.adults, b.children, b.babies, b.participant_names,
			        b.status, b.deposit_pct, b.balance_paid_at{$money_cols},
			        t.name_es as tour_name_es, t.name_en as tour_name_en,
			        r.name_es as room_name_es, r.name_en as room_name_en
			 FROM {$wpdb->prefix}amir_bookings b
			 LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
			 LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
			 WHERE b.tour_date BETWEEN %s AND %s
			   AND b.status IN ({$placeholders})
			 ORDER BY b.tour_date {$order}, b.time_start {$order}",
			array_merge( [ $from, $until ], $statuses )
		) ) ?? [];
	}

	// ==================== VISTA ====================

	/**
	 * @param array{rows:array,base_url:string,lang:string,days:int,mode:string,
	 *   from:string,until:string,is_custom:bool,can_payments:bool,message:string,
	 *   forecast:array,stats:?array} $a
	 */
	private static function render_list( array $a ): void {
		[ 'rows' => $rows, 'base_url' => $base_url, 'lang' => $lang, 'days' => $days, 'mode' => $mode,
		  'from' => $from, 'until' => $until, 'is_custom' => $is_custom, 'can_payments' => $can_payments,
		  'message' => $message, 'forecast' => $forecast, 'stats' => $stats ] = $a;
		?>
		<main class="tfp-main">
			<div class="tfp-title-row">
				<h1>📋 <?php echo esc_html( self::tt( $lang, 'Panel rápido', 'Quick panel' ) ); ?></h1>
			</div>

			<?php echo $message; // ya viene escapado en handle_mark_paid() ?>

			<p style="color:#5a7068;font-size:13px;margin:-4px 0 14px;">
				<?php echo esc_html( $can_payments
					? self::tt( $lang,
						'Quién llega, cuándo, con quiénes, y lo que cada reserva ya pagó o le falta pagar.',
						'Who\'s arriving, when, with whom, and what each booking has paid or still owes.'
					)
					: self::tt( $lang,
						'Quién llega, cuándo y con quiénes — sin montos ni precios.',
						'Who\'s arriving, when, and with whom — no amounts or prices.'
					)
				); ?>
			</p>

			<?php self::render_day_summary( $forecast, $lang ); ?>

			<?php $clean_url = remove_query_arg( [ 'from', 'until' ], $base_url ); ?>
			<div class="tfp-card">
				<h2><?php echo esc_html( self::tt( $lang, 'Ver reservas', 'View bookings' ) ); ?></h2>

				<div class="tfp-quick-days">
					<a href="<?php echo esc_url( add_query_arg( [ 'mode' => 'upcoming' ], $clean_url ) ); ?>"
					   class="button <?php echo ( $mode === 'upcoming' && ! $is_custom ) ? 'button-primary' : ''; ?>">🔜 <?php echo esc_html( self::tt( $lang, 'Próximas', 'Upcoming' ) ); ?></a>
					<a href="<?php echo esc_url( add_query_arg( [ 'mode' => 'past' ], $clean_url ) ); ?>"
					   class="button <?php echo ( $mode === 'past' && ! $is_custom ) ? 'button-primary' : ''; ?>">🕑 <?php echo esc_html( self::tt( $lang, 'Pasadas', 'Past' ) ); ?></a>
				</div>

				<div class="tfp-quick-days">
					<?php foreach ( [ 7, 14, 30, 60 ] as $d ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'days' => $d, 'mode' => $mode ], $clean_url ) ); ?>"
						   class="button <?php echo ( $d === $days && ! $is_custom ) ? 'button-primary' : ''; ?>"><?php echo esc_html( sprintf(
							$mode === 'past' ? self::tt( $lang, 'Últimos %d días', 'Last %d days' ) : self::tt( $lang, 'Próximos %d días', 'Next %d days' ),
							$d
						) ); ?></a>
					<?php endforeach; ?>
				</div>

				<form method="get" class="tfp-quick-range" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<input type="hidden" name="<?php echo esc_attr( \TourFlow\Panel\ManagerPanel::QUERY_VAR ); ?>" value="rapido" />
					<label><?php echo esc_html( self::tt( $lang, 'Desde', 'From' ) ); ?>
						<input type="date" name="from" value="<?php echo esc_attr( $is_custom ? $from : '' ); ?>" />
					</label>
					<label><?php echo esc_html( self::tt( $lang, 'Hasta', 'Until' ) ); ?>
						<input type="date" name="until" value="<?php echo esc_attr( $is_custom ? $until : '' ); ?>" />
					</label>
					<button type="submit" class="button"><?php echo esc_html( self::tt( $lang, 'Ver rango', 'View range' ) ); ?></button>
				</form>

				<p class="tfp-quick-range-hint">
					<?php echo esc_html( sprintf(
						self::tt( $lang, 'Mostrando del %s al %s.', 'Showing %s through %s.' ),
						self::fmt_date( $from, $lang ), self::fmt_date( $until, $lang )
					) ); ?>
				</p>
			</div>

			<?php if ( $stats ) : ?>
				<div class="tfp-card">
					<h2><?php echo esc_html( self::tt( $lang, 'Estadísticas del período', 'Period statistics' ) ); ?></h2>
					<div class="tfp-stats">
						<div class="tfp-stat"><span><?php echo esc_html( self::tt( $lang, 'Personas en tours', 'People on tours' ) ); ?></span><strong><?php echo (int) $stats['tour_pax']; ?></strong></div>
						<div class="tfp-stat"><span><?php echo esc_html( self::tt( $lang, 'Personas alojadas', 'People lodged' ) ); ?></span><strong><?php echo (int) $stats['room_pax']; ?></strong></div>
					</div>
				</div>
			<?php endif; ?>

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
	 * Tres tarjetas chicas (hoy/mañana/pasado mañana) — pedido explícito del
	 * cliente, "dashboard para hoy mañana pasado, cantidades, checkin y
	 * checkout por día". Reusa `.tfp-card`/`.tfp-stats`/`.tfp-stat` — el
	 * mismo vocabulario visual que ya usa el Dashboard del panel (pedido
	 * explícito del cliente 2026-11-17: "deben compartir estética"), en vez
	 * de clases propias.
	 */
	private static function render_day_summary( array $summary, string $lang ): void {
		$labels = [
			self::tt( $lang, 'Hoy', 'Today' ),
			self::tt( $lang, 'Mañana', 'Tomorrow' ),
			self::tt( $lang, 'Pasado mañana', 'Day after tomorrow' ),
		];
		?>
		<div class="tfp-card">
			<h2><?php echo esc_html( self::tt( $lang, 'Próximos días', 'Coming up' ) ); ?></h2>
			<div class="tfp-stats">
				<?php foreach ( $summary as $i => $day ) : ?>
					<div class="tfp-stat">
						<span><?php echo esc_html( $labels[ $i ] ?? self::fmt_date( $day['date'], $lang ) ); ?> — <?php echo esc_html( self::tt( $lang, 'llegan', 'arriving' ) ); ?></span>
						<strong><?php echo (int) $day['checkin_guests']; ?></strong>
					</div>
					<div class="tfp-stat">
						<span><?php echo esc_html( $labels[ $i ] ?? self::fmt_date( $day['date'], $lang ) ); ?> — <?php echo esc_html( self::tt( $lang, 'se van', 'departing' ) ); ?></span>
						<strong><?php echo (int) $day['checkout_guests']; ?></strong>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
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
					<strong><?php echo esc_html( self::fmt_date( $b->tour_date, $lang ) ); ?><?php if ( ( $b->item_type ?? 'tour' ) === 'room' && ! empty( $b->check_out_date ) ) : ?> → <?php echo esc_html( self::fmt_date( $b->check_out_date, $lang ) ); ?><?php endif; ?></strong>
					<?php if ( ! empty( $b->time_start ) ) : ?><span><?php echo esc_html( self::fmt_time( $b->time_start ) ); ?></span><?php endif; ?>
				</div>
				<?php echo self::status_badge( $b->status, $lang ); ?>
			</div>
			<div class="tfp-quick-item-activity"><?php echo esc_html( $activity ); ?></div>
			<div class="tfp-quick-item-ref"><?php echo esc_html( $b->booking_ref ); ?></div>
			<div class="tfp-quick-item-people"><?php echo self::participants_cell( $b, $lang ); // ya escapado adentro ?></div>
			<?php $contact = self::contact_line( $b ); ?>
			<?php if ( $contact !== '' ) : ?>
				<div class="tfp-quick-item-contact"><?php echo $contact; // ya escapado adentro ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $b->special_requests ) ) : ?>
				<div class="tfp-quick-item-notes">📝 <?php echo esc_html( $b->special_requests ); ?></div>
			<?php endif; ?>
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
			<?php if ( $can_payments ) : ?>
				<div class="tfp-quick-item-money"><?php echo self::money_line( $b, $lang ); // ya escapado adentro ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** tel:/mailto: — sin esto el personal de campo no tenía forma de contactar al cliente desde acá (pedido explícito del cliente, 2026-11-16). */
	private static function contact_line( object $b ): string {
		$parts = [];
		if ( ! empty( $b->customer_phone ) ) {
			$tel = preg_replace( '/[^0-9+]/', '', $b->customer_phone );
			$parts[] = '<a href="tel:' . esc_attr( $tel ) . '">📞 ' . esc_html( $b->customer_phone ) . '</a>';
		}
		if ( ! empty( $b->customer_email ) ) {
			$parts[] = '<a href="mailto:' . esc_attr( $b->customer_email ) . '">✉️ ' . esc_html( $b->customer_email ) . '</a>';
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Resumen de pagado/falta — NUNCA el detalle completo (sin IDs de
	 * transacción ni reembolsos, eso queda en Reservas/Log de pagos). Solo se
	 * llama cuando $can_payments es true, así que total_mxn/charge_mxn
	 * siempre están presentes acá (ver query_bookings()).
	 */
	private static function money_line( object $b, string $lang ): string {
		$total   = (float) ( $b->total_mxn ?? 0 );
		$charge  = (float) ( $b->charge_mxn ?? 0 );
		$paid    = empty( $b->balance_paid_at ) ? $charge : $total;
		$pending = empty( $b->balance_paid_at ) ? max( 0.0, $total - $charge ) : 0.0;

		$out = self::tt( $lang, 'Pagado', 'Paid' ) . ': ' . esc_html( \AmirBooking\Core\Currency::format( $paid ) );
		if ( $pending > 0.009 ) {
			$out .= ' · ' . self::tt( $lang, 'Falta', 'Due' ) . ': ' . esc_html( \AmirBooking\Core\Currency::format( $pending ) );
		}
		return $out;
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

	/** Subconjunto chico de status_labels() de BookingsPage — esta vista solo muestra los estados de query_bookings() (3 en "Próximas", +'completed' en "Pasadas"), no hace falta el diccionario completo. */
	private static function status_badge( string $status, string $lang ): string {
		$map = [
			'confirmed'        => [ self::tt( $lang, '✓ Confirmada', '✓ Confirmed' ),        '#e1f5ee', 'var(--ab-teal-dark, #0F6E56)' ],
			'pending'          => [ self::tt( $lang, '⏳ Pendiente de pago', '⏳ Awaiting payment' ), '#fff8e7', '#BA7517' ],
			'awaiting_payment' => [ self::tt( $lang, '⏳ Pendiente de pago', '⏳ Awaiting payment' ), '#fff8e7', '#BA7517' ],
			'completed'        => [ self::tt( $lang, '✓ Completada', '✓ Completed' ),        '#e1f5ee', 'var(--ab-teal-dark, #0F6E56)' ],
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
