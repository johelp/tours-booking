<?php
namespace TourFlowCustomEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo de los emails al CLIENTE que este satélite puede reemplazar, más
 * una plantilla base por tipo para no empezar de una hoja en blanco.
 *
 * Las claves son las que devuelve `type_key()` en cada clase de email del
 * núcleo (includes/emails/class-email-dispatcher.php) y llegan tal cual al
 * filtro `amir_email_render_html`. Quedan afuera a propósito:
 *  - Los 3 emails al PROVEEDOR externo (provider_notice, provider_pending,
 *    provider_client_details): llevan los botones tokenizados de
 *    aprobar/rechazar, y un diseño propio que los pierda deja a un proveedor
 *    sin forma de responder.
 *  - Los avisos internos al operador ("[Nueva reserva] …"): no son del cliente.
 *  - El email de carrito de VARIOS ítems (CartConfirmationEmail devuelve ''
 *    como type_key cuando hay más de un ítem): lista N productos y el estado
 *    de cada uno, algo que una plantilla de "una reserva" no puede expresar.
 */
final class Types {

	/**
	 * @return array<string,array{label:array{es:string,en:string},when:array{es:string,en:string},extra:string[]}>
	 *   `extra` = variables propias del tipo (además de las comunes), solo para documentarlas en pantalla.
	 */
	public static function all(): array {
		return [
			'confirmation' => [
				'label' => [ 'es' => 'Confirmación de reserva', 'en' => 'Booking confirmation' ],
				'when'  => [ 'es' => 'Cuando se confirma el pago de una reserva (también la compra de un solo ítem desde el carrito).', 'en' => 'When a booking payment is confirmed (including a single-item cart purchase).' ],
				'extra' => [ 'custom_note' ],
			],
			'reminder' => [
				'label' => [ 'es' => 'Recordatorio del tour', 'en' => 'Tour reminder' ],
				'when'  => [ 'es' => 'El día anterior al tour.', 'en' => 'The day before the tour.' ],
				'extra' => [],
			],
			'review' => [
				'label' => [ 'es' => 'Pedido de reseña', 'en' => 'Review request' ],
				'when'  => [ 'es' => 'Después del tour, para pedir una reseña.', 'en' => 'After the tour, asking for a review.' ],
				'extra' => [ 'review_google_url', 'review_tripadvisor_url' ],
			],
			'cancellation' => [
				'label' => [ 'es' => 'Cancelación de reserva', 'en' => 'Booking cancellation' ],
				'when'  => [ 'es' => 'Cuando se cancela una reserva (por el cliente, clima, mínimo de personas o proveedor).', 'en' => 'When a booking is cancelled (by the customer, weather, minimum guests or provider).' ],
				'extra' => [ 'refund_amount', 'cancellation_reason', 'custom_note' ],
			],
			'reschedule' => [
				'label' => [ 'es' => 'Reserva reprogramada', 'en' => 'Booking rescheduled' ],
				'when'  => [ 'es' => 'Cuando el operador cambia la fecha de una reserva.', 'en' => 'When the operator changes a booking\'s date.' ],
				'extra' => [],
			],
			'payment_link' => [
				'label' => [ 'es' => 'Link de pago (reserva manual / fecha aprobada)', 'en' => 'Payment link (manual booking / approved date)' ],
				'when'  => [ 'es' => 'Cuando el operador carga una reserva o aprueba una solicitud de fecha y le manda el link de pago.', 'en' => 'When the operator creates a booking or approves a date request and sends the payment link.' ],
				'extra' => [ 'pay_url', 'custom_note' ],
			],
			'payment_failed' => [
				'label' => [ 'es' => 'Pago rechazado', 'en' => 'Payment failed' ],
				'when'  => [ 'es' => 'Cuando la tarjeta es rechazada: invita a reintentar dentro del plazo de gracia.', 'en' => 'When the card is declined: invites the customer to retry within the grace period.' ],
				'extra' => [ 'pay_url', 'payment_deadline' ],
			],
			'balance_payment_link' => [
				'label' => [ 'es' => 'Link para pagar el saldo', 'en' => 'Balance payment link' ],
				'when'  => [ 'es' => 'Cuando el operador manda el link para pagar el saldo de un depósito parcial.', 'en' => 'When the operator sends the link to pay the balance of a partial deposit.' ],
				'extra' => [ 'pay_url', 'balance_amount', 'custom_note' ],
			],
			'balance_paid' => [
				'label' => [ 'es' => 'Saldo pagado (recibo)', 'en' => 'Balance paid (receipt)' ],
				'when'  => [ 'es' => 'Cuando el cliente paga el saldo restante online.', 'en' => 'When the customer pays the remaining balance online.' ],
				'extra' => [],
			],
			'date_requested' => [
				'label' => [ 'es' => 'Solicitud de fecha recibida', 'en' => 'Date request received' ],
				'when'  => [ 'es' => 'Apenas el cliente pide una fecha (tour de fecha fija o "solo a pedido").', 'en' => 'As soon as the customer requests a date (fixed-date or "on request" tour).' ],
				'extra' => [],
			],
			'date_request_rejected' => [
				'label' => [ 'es' => 'Solicitud de fecha rechazada', 'en' => 'Date request declined' ],
				'when'  => [ 'es' => 'Cuando el operador rechaza la fecha pedida; puede llevar un mensaje propio.', 'en' => 'When the operator declines the requested date; may carry a personal message.' ],
				'extra' => [ 'custom_note' ],
			],
			'tour_opened' => [
				'label' => [ 'es' => 'Tour de la lista de interés ya abrió', 'en' => 'Waitlist tour is now open' ],
				'when'  => [ 'es' => 'Cuando un tour de la lista de interés abre y el cliente ya puede pagar.', 'en' => 'When a waitlist tour opens and the customer can now pay.' ],
				'extra' => [ 'pay_url' ],
			],
		];
	}

	public static function exists( string $type ): bool {
		return isset( self::all()[ $type ] );
	}

	/** Etiqueta en el idioma de ADMIN del usuario logueado. */
	public static function label( string $type, bool $en ): string {
		$t = self::all()[ $type ]['label'] ?? null;
		return $t ? $t[ $en ? 'en' : 'es' ] : $type;
	}

	/**
	 * Textos de la plantilla base: título, párrafo, botones. Cada botón es
	 * [ etiqueta, variable-de-url, variable-condicional|'' ]. 'es' para
	 * español y el resto de idiomas en inglés (mismo criterio que el núcleo
	 * para los textos de email que no tienen traducción propia).
	 *
	 * @return array{title:string,body:string,buttons:array<int,array{0:string,1:string,2:string}>,note:string}
	 */
	private static function copy( string $type, bool $es ): array {
		$b = [
			'verify' => $es ? 'Ver mi reserva' : 'View my booking',
			'pay'    => $es ? 'Completar el pago' : 'Complete payment',
		];

		switch ( $type ) {
			case 'confirmation':
				return [
					'title'   => $es ? '¡Tu reserva está confirmada!' : 'Your booking is confirmed!',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, ¡gracias por reservar! Te esperamos el <strong>{{tour_date}}</strong> para <strong>{{tour_name}}</strong> ({{people_count}} personas).'
						: 'Hi <strong>{{customer_name}}</strong>, thank you for booking! We look forward to welcoming you on <strong>{{tour_date}}</strong> for <strong>{{tour_name}}</strong> ({{people_count}} guests).',
					'buttons' => [ [ $b['verify'], 'verify_url', '' ], [ $es ? 'Descargar voucher' : 'Download voucher', 'pdf_url', '' ], [ $es ? 'Agregar al calendario' : 'Add to calendar', 'calendar_url', '' ] ],
					'note'    => '',
				];
			case 'reminder':
				return [
					'title'   => $es ? '¡Tu experiencia es mañana!' : 'Your experience is tomorrow!',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, te recordamos tu reserva de <strong>{{tour_name}}</strong> el <strong>{{tour_date}}</strong>{{#if tour_time}} a las <strong>{{tour_time}}</strong>{{/if}}.'
						: 'Hi <strong>{{customer_name}}</strong>, a quick reminder of your <strong>{{tour_name}}</strong> booking on <strong>{{tour_date}}</strong>{{#if tour_time}} at <strong>{{tour_time}}</strong>{{/if}}.',
					'buttons' => [ [ $es ? 'Cómo llegar' : 'Get directions', 'maps_url', '' ], [ $b['verify'], 'verify_url', '' ] ],
					'note'    => '',
				];
			case 'review':
				return [
					'title'   => $es ? '¿Cómo fue tu experiencia?' : 'How was your experience?',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, gracias por vivir <strong>{{tour_name}}</strong> con nosotros. Tu opinión nos ayuda muchísimo.'
						: 'Hi <strong>{{customer_name}}</strong>, thank you for joining us on <strong>{{tour_name}}</strong>. Your feedback means a lot to us.',
					'buttons' => [ [ $es ? 'Reseña en Google' : 'Review on Google', 'review_google_url', 'review_google_url' ], [ $es ? 'Reseña en TripAdvisor' : 'Review on TripAdvisor', 'review_tripadvisor_url', 'review_tripadvisor_url' ] ],
					'note'    => '',
				];
			case 'cancellation':
				return [
					'title'   => $es ? 'Tu reserva fue cancelada' : 'Your booking was cancelled',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, tu reserva <strong>{{booking_ref}}</strong> de <strong>{{tour_name}}</strong> fue cancelada.{{#if refund_amount}} Te reembolsamos <strong>{{refund_amount}}</strong>.{{/if}}{{#if cancellation_reason}} Motivo: {{cancellation_reason}}.{{/if}}'
						: 'Hi <strong>{{customer_name}}</strong>, your booking <strong>{{booking_ref}}</strong> for <strong>{{tour_name}}</strong> was cancelled.{{#if refund_amount}} We are refunding <strong>{{refund_amount}}</strong>.{{/if}}{{#if cancellation_reason}} Reason: {{cancellation_reason}}.{{/if}}',
					'buttons' => [],
					'note'    => 'custom_note',
				];
			case 'reschedule':
				return [
					'title'   => $es ? 'Tu reserva fue reprogramada' : 'Your booking was rescheduled',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, la nueva fecha de tu reserva <strong>{{booking_ref}}</strong> ({{tour_name}}) es el <strong>{{tour_date}}</strong>{{#if tour_time}} a las <strong>{{tour_time}}</strong>{{/if}}.'
						: 'Hi <strong>{{customer_name}}</strong>, the new date for your booking <strong>{{booking_ref}}</strong> ({{tour_name}}) is <strong>{{tour_date}}</strong>{{#if tour_time}} at <strong>{{tour_time}}</strong>{{/if}}.',
					'buttons' => [ [ $es ? 'Agregar al calendario' : 'Add to calendar', 'calendar_url', '' ], [ $b['verify'], 'verify_url', '' ] ],
					'note'    => '',
				];
			case 'payment_link':
				return [
					'title'   => $es ? 'Completá tu pago' : 'Complete your payment',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, tu reserva de <strong>{{tour_name}}</strong> para el <strong>{{tour_date}}</strong> está lista. Falta completar el pago para confirmarla.'
						: 'Hi <strong>{{customer_name}}</strong>, your booking for <strong>{{tour_name}}</strong> on <strong>{{tour_date}}</strong> is ready. Complete the payment to confirm it.',
					'buttons' => [ [ $b['pay'], 'pay_url', '' ] ],
					'note'    => 'custom_note',
				];
			case 'payment_failed':
				return [
					'title'   => $es ? 'No pudimos procesar tu pago' : 'We couldn\'t process your payment',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, el pago de tu reserva de <strong>{{tour_name}}</strong> ({{tour_date}}) fue rechazado. Guardamos tu lugar hasta el <strong>{{payment_deadline}}</strong>: actualizá tu tarjeta y volvé a intentarlo.'
						: 'Hi <strong>{{customer_name}}</strong>, the payment for your <strong>{{tour_name}}</strong> booking ({{tour_date}}) was declined. We are holding your spot until <strong>{{payment_deadline}}</strong>: update your card and try again.',
					'buttons' => [ [ $es ? 'Actualizar tarjeta y pagar' : 'Update card and pay', 'pay_url', '' ] ],
					'note'    => '',
				];
			case 'balance_payment_link':
				return [
					'title'   => $es ? 'Pagá el saldo de tu reserva' : 'Pay your remaining balance',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, tu reserva de <strong>{{tour_name}}</strong> ({{tour_date}}) está confirmada. Podés pagar el saldo restante de <strong>{{balance_amount}}</strong> online.'
						: 'Hi <strong>{{customer_name}}</strong>, your booking for <strong>{{tour_name}}</strong> ({{tour_date}}) is confirmed. You can pay the remaining balance of <strong>{{balance_amount}}</strong> online.',
					'buttons' => [ [ $es ? 'Pagar el saldo' : 'Pay balance', 'pay_url', '' ] ],
					'note'    => 'custom_note',
				];
			case 'balance_paid':
				return [
					'title'   => $es ? '¡Recibimos el pago del saldo!' : 'We received your balance payment!',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, tu reserva de <strong>{{tour_name}}</strong> ({{tour_date}}) ya está paga por completo. ¡Gracias!'
						: 'Hi <strong>{{customer_name}}</strong>, your booking for <strong>{{tour_name}}</strong> ({{tour_date}}) is now fully paid. Thank you!',
					'buttons' => [ [ $b['verify'], 'verify_url', '' ] ],
					'note'    => '',
				];
			case 'date_requested':
				return [
					'title'   => $es ? 'Recibimos tu solicitud de fecha' : 'We received your date request',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, recibimos tu pedido para <strong>{{tour_name}}</strong> el <strong>{{tour_date}}</strong>. Lo revisamos y te escribimos pronto; no se cobra nada hasta que lo confirmemos.'
						: 'Hi <strong>{{customer_name}}</strong>, we received your request for <strong>{{tour_name}}</strong> on <strong>{{tour_date}}</strong>. We will review it and get back to you soon; nothing is charged until we confirm.',
					'buttons' => [ [ $b['verify'], 'verify_url', '' ] ],
					'note'    => '',
				];
			case 'date_request_rejected':
				return [
					'title'   => $es ? 'No pudimos confirmar esa fecha' : 'We couldn\'t confirm that date',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, lamentablemente no podemos ofrecerte <strong>{{tour_name}}</strong> el <strong>{{tour_date}}</strong>. No se te cobró nada.'
						: 'Hi <strong>{{customer_name}}</strong>, unfortunately we can\'t offer <strong>{{tour_name}}</strong> on <strong>{{tour_date}}</strong>. You have not been charged.',
					'buttons' => [],
					'note'    => 'custom_note',
				];
			case 'tour_opened':
				return [
					'title'   => $es ? '¡{{tour_name}} ya tiene fecha!' : '{{tour_name}} now has a date!',
					'body'    => $es
						? 'Hola <strong>{{customer_name}}</strong>, ¡tenemos buenas noticias! Ya podés reservar <strong>{{tour_name}}</strong> para el <strong>{{tour_date}}</strong>.'
						: 'Hi <strong>{{customer_name}}</strong>, good news! You can now book <strong>{{tour_name}}</strong> for <strong>{{tour_date}}</strong>.',
					'buttons' => [ [ $b['pay'], 'pay_url', '' ] ],
					'note'    => '',
				];
		}

		return [ 'title' => '', 'body' => '', 'buttons' => [], 'note' => '' ];
	}

	/**
	 * Plantilla base lista para pegar en el editor: tarjeta de 600 px con
	 * logo, título, texto, caja de referencia, botones y WhatsApp — solo HTML
	 * y estilos en línea (lo único que respetan todos los clientes de correo).
	 * Cada bloque opcional va envuelto en {{#if}} para no dejar huecos.
	 */
	public static function starter( string $type, string $lang ): string {
		$es = ( $lang === 'es' );
		$c  = self::copy( $type, $es );
		if ( $c['title'] === '' ) {
			return '';
		}

		$ref_label = $es ? 'Referencia' : 'Reference';
		$wa_label  = $es ? '¿Dudas? Escribinos por WhatsApp' : 'Questions? Message us on WhatsApp';
		$note_lbl  = $es ? 'Mensaje del equipo' : 'Message from our team';

		$buttons = '';
		foreach ( $c['buttons'] as [ $label, $var, $cond ] ) {
			$a = '<a href="{{' . $var . '}}" style="display:inline-block;margin:4px;padding:13px 26px;background:{{brand_color}};color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:15px;">' . $label . '</a>';
			$buttons .= $cond !== '' ? '{{#if ' . $cond . '}}' . $a . '{{/if}}' : $a;
		}
		if ( $buttons !== '' ) {
			$buttons = "\n              <p style=\"text-align:center;margin:26px 0 6px;\">" . $buttons . '</p>';
		}

		$note = '';
		if ( $c['note'] !== '' ) {
			$note = "\n              {{#if custom_note}}<div style=\"margin:18px 0;padding:12px 16px;background:#f4f7f5;border-radius:8px;font-size:14px;\"><strong>" . $note_lbl . ":</strong><br>{{custom_note}}</div>{{/if}}";
		}

		return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef2f0;font-family:Helvetica,Arial,sans-serif;color:#1c2b3a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f0;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr><td style="background:{{brand_color}};padding:22px;text-align:center;">
          {{#if logo_url}}<img src="{{logo_url}}" alt="{{company_name}}" style="max-height:56px;max-width:220px;">{{/if}}
          <div style="color:#ffffff;font-size:18px;font-weight:bold;">{{company_name}}</div>
        </td></tr>
        <tr><td style="padding:30px 32px;font-size:16px;line-height:1.6;">
              <h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;">{$c['title']}</h1>
              <p style="margin:0 0 16px;">{$c['body']}</p>{$note}
              <p style="margin:18px 0;padding:12px;background:#f4f7f5;border-radius:8px;text-align:center;font-size:14px;">{$ref_label}: <strong>{{booking_ref}}</strong></p>{$buttons}
        </td></tr>
        <tr><td style="padding:18px 32px 26px;text-align:center;font-size:13px;color:#5a7068;">
          {{#if whatsapp_url}}<a href="{{whatsapp_url}}" style="color:#5a7068;">{$wa_label}</a>{{/if}}
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
	}
}
