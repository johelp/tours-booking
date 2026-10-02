<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

// BaseEmail vive DENTRO de class-email-dispatcher.php (varias clases por
// archivo, patrón ya existente en el plugin) — el autoloader por convención
// kebab-case de amir-booking.php no lo encuentra solo (buscaría
// class-base-email.php, que no existe). require_once explícito para no
// depender de que algo más haya cargado ese archivo antes por casualidad
// (hoy pasa porque Plugin::init() instancia EmailDispatcher antes de que
// pueda dispararse cualquier confirmación, pero eso es orden implícito, no
// una garantía — más frágil que declararlo acá).
require_once __DIR__ . '/../emails/class-email-dispatcher.php';

/**
 * Email de confirmación de una reserva de habitación (§ 16 CONTRIBUTING.md).
 * Extiende AmirBooking\Emails\BaseEmail (branding/wrapper/idioma ya
 * resueltos ahí) en vez de duplicarlo — solo cambia qué se muestra en el
 * cuerpo, porque una habitación no tiene tour_name/time_start/meeting_point.
 * Los requerimientos especiales (special_requests) se muestran de forma
 * prominente a propósito: es lo que el operador necesita coordinar antes
 * del check-in (ver también el Dashboard, § 16 CONTRIBUTING.md).
 */
final class RoomConfirmationEmail extends \AmirBooking\Emails\BaseEmail {

	/** true = "tu reserva fue reprogramada" en vez de "tu reserva está confirmada" — mismo cuerpo, mismos datos ya actualizados. */
	private bool $is_reschedule = false;

	public static function send_for( int $booking_id ): void {
		$booking = self::get_booking_with_room( $booking_id );
		if ( ! $booking ) {
			return;
		}

		// Reserva de un carrito multi-ítem (cart_group_id, § 16 CONTRIBUTING.md):
		// el cliente ya recibe el "voucher general" único con TODOS los ítems
		// del carrito (CartConfirmationEmail, disparado por flow_cart_confirmed)
		// — mandarle además esta confirmación individual por cada habitación
		// duplicaba avisos para una sola compra (pedido del cliente 2026-08-04:
		// "si reservan varios tours o productos llegan varios email separados,
		// hacer que llegue la reserva toda junta"). Standalone (sin carrito,
		// ej. reserva manual de una habitación desde el admin) sigue mandando
		// esta confirmación como siempre.
		if ( empty( $booking->cart_group_id ) ) {
			( new self( $booking ) )->send();
		}

		$admin_email = get_option( 'amir_admin_email', get_option( 'admin_email' ) );
		if ( $admin_email ) {
			self::notify_admin( $booking, $admin_email );
		}
	}

	/**
	 * RoomBookingManager::reschedule() ya actualizó tour_date/check_out_date
	 * antes de disparar este hook, así que get_booking_with_room() trae las
	 * fechas nuevas — el email avisa el cambio con los datos ya correctos.
	 */
	public static function send_reschedule_for( int $booking_id ): void {
		$booking = self::get_booking_with_room( $booking_id );
		if ( ! $booking ) {
			return;
		}
		$mailer = new self( $booking );
		$mailer->is_reschedule = true;
		$mailer->send();
	}

	public static function get_booking_with_room( int $booking_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT b.*, r.name_es AS room_name_es, r.name_en AS room_name_en
			 FROM {$wpdb->prefix}amir_bookings b
			 JOIN {$wpdb->prefix}flow_rooms r ON r.id = b.room_id
			 WHERE b.id = %d",
			$booking_id
		) );
	}

	private static function notify_admin( object $booking, string $admin_email ): void {
		$room_name = $booking->room_name_es ?: $booking->room_name_en;
		$subject   = sprintf( '[Nueva reserva de habitación] %s — %s', $booking->booking_ref, $booking->customer_name );
		$body      = sprintf(
			"Nueva reserva de habitación confirmada:\n\n" .
			"Referencia: %s\nHabitación: %s\nCheck-in: %s\nCheck-out: %s\nHuéspedes: %d\n" .
			"Cliente: %s (%s)\nTeléfono: %s\nTotal: %s\n\nRequerimientos especiales:\n%s\n",
			$booking->booking_ref,
			$room_name,
			$booking->tour_date,
			$booking->check_out_date,
			(int) $booking->adults,
			$booking->customer_name,
			$booking->customer_email,
			$booking->customer_phone,
			\AmirBooking\Core\Currency::format( (float) $booking->total_mxn ),
			$booking->special_requests ?: '(ninguno)'
		);
		wp_mail( $admin_email, $subject, $body );
	}

	protected function get_subject(): string {
		return $this->is_reschedule
			? sprintf( __( '🔄 Tu reserva de habitación fue reprogramada — %s', 'amir-booking' ), $this->booking->booking_ref )
			: sprintf( __( '✅ Tu reserva de habitación está confirmada — %s', 'amir-booking' ), $this->booking->booking_ref );
	}

	protected function get_body_content(): string {
		$b         = $this->booking;
		$room_name = $this->lang === 'en' ? ( $b->room_name_en ?: $b->room_name_es ) : ( $b->room_name_es ?: $b->room_name_en );

		$intro = $this->is_reschedule
			? '<h1>' . __( 'Tu reserva fue reprogramada 🔄', 'amir-booking' ) . '</h1>'
			  . '<p>' . sprintf( __( 'Hola <strong>%s</strong>,<br>Actualizamos las fechas de tu estadía. Estos son los detalles vigentes de tu reserva:', 'amir-booking' ), esc_html( $b->customer_name ) ) . '</p>'
			: '<h1>' . __( '¡Tu reserva está confirmada! 🎉', 'amir-booking' ) . '</h1>'
			  . '<p>' . sprintf( __( 'Hola <strong>%s</strong>,<br>Todo está listo para tu estadía. Aquí están los detalles de tu reserva:', 'amir-booking' ), esc_html( $b->customer_name ) ) . '</p>';

		$ref_box = '<div class="ref-box">
		  <div class="ref-label">' . $this->t( 'booking_ref' ) . '</div>
		  <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
		</div>';

		$table = '
		<table class="info-table">
		  <tr><td>' . esc_html__( 'Habitación', 'amir-booking' ) . '</td><td>' . esc_html( $room_name ) . '</td></tr>
		  <tr><td>' . esc_html__( 'Check-in', 'amir-booking' ) . '</td><td>' . $this->fmt_date( $b->tour_date ) . '</td></tr>
		  <tr><td>' . esc_html__( 'Check-out', 'amir-booking' ) . '</td><td>' . $this->fmt_date( $b->check_out_date ) . '</td></tr>
		  <tr><td>' . esc_html__( 'Huéspedes', 'amir-booking' ) . '</td><td>' . (int) $b->adults . '</td></tr>
		  <tr><td>' . $this->t( 'total' ) . '</td><td>' . \AmirBooking\Core\Currency::format( (float) $b->total_mxn ) . '</td></tr>
		</table>';

		$requests = '';
		if ( ! empty( $b->special_requests ) ) {
			$requests = '<div style="background:#fffbeb;border-left:4px solid #BA7517;padding:12px 16px;margin:16px 0;border-radius:0 8px 8px 0;">'
				. '<p style="font-size:13px;color:#78350f;margin:0;"><strong>' . esc_html__( 'Tus requerimientos especiales:', 'amir-booking' ) . '</strong><br>'
				. nl2br( esc_html( $b->special_requests ) ) . '</p></div>';
		}

		$actions = '<p style="text-align:center;font-size:13px;margin-top:20px;">
		  <a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . esc_html__( 'Ver estado de mi reserva', 'amir-booking' ) . '</a>
		</p>';

		return $intro . $ref_box . $table . $requests . $actions;
	}
}
