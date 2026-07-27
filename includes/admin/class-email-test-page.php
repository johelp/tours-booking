<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Manda cualquiera de los 7 emails del sistema con datos de ejemplo, sin
 * crear una reserva real — para diagnosticar entregabilidad (spam, SMTP)
 * o revisar cómo se ve una plantilla sin esperar a que un cliente real
 * dispare ese paso puntual del flujo (ej. "tour ya disponible" solo se
 * dispara desde Lista de interés → Publicar y notificar).
 */
class EmailTestPage {

    private const TYPES = [
        'confirmation' => [ 'label' => 'Confirmación de reserva',         'class' => \AmirBooking\Emails\ConfirmationEmail::class ],
        'reminder'     => [ 'label' => 'Recordatorio pre-tour',           'class' => \AmirBooking\Emails\ReminderEmail::class ],
        'review'       => [ 'label' => 'Solicitud de reseña',             'class' => \AmirBooking\Emails\ReviewEmail::class ],
        'cancellation' => [ 'label' => 'Cancelación de reserva',          'class' => \AmirBooking\Emails\CancellationEmail::class ],
        'tour_opened'  => [ 'label' => 'Tour ya disponible (wishlist)',   'class' => \AmirBooking\Emails\TourOpenedEmail::class ],
        'payment_link' => [ 'label' => 'Link de pago (reserva manual)',  'class' => \AmirBooking\Emails\PaymentLinkEmail::class ],
        'reschedule'   => [ 'label' => 'Reserva reprogramada',           'class' => \AmirBooking\Emails\RescheduleEmail::class ],
    ];

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
        }

        $result = $this->handle_submit();
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:640px;">
        <h1>✉️ Probar emails</h1>
        <p style="color:#5a7068;font-size:13px;">
          Manda cualquiera de los emails del sistema con datos de ejemplo (reserva "TEST-0001", ficticia) —
          útil para revisar entregabilidad (spam, SMTP) o cómo se ve cada plantilla, sin crear una reserva real.
        </p>

        <?php if ( $result ) : ?>
          <div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?>" style="padding:10px 14px;">
            <p><?php echo esc_html( $result['message'] ); ?></p>
          </div>
        <?php endif; ?>

        <form method="post" style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 24px;">
          <?php wp_nonce_field( 'amir_email_test' ); ?>
          <table class="form-table">
            <tr>
              <th style="width:140px;"><label for="amir-email-type">Tipo de email</label></th>
              <td>
                <select name="email_type" id="amir-email-type">
                  <?php foreach ( self::TYPES as $key => $t ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr>
              <th><label for="amir-email-to">Enviar a</label></th>
              <td>
                <input type="email" name="to_email" id="amir-email-to" class="regular-text" required
                       value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
              </td>
            </tr>
            <tr>
              <th><label for="amir-email-lang">Idioma</label></th>
              <td>
                <select name="lang" id="amir-email-lang">
                  <?php foreach ( \AmirBooking\Core\Languages::active() as $l ) : ?>
                    <option value="<?php echo esc_attr( $l ); ?>"><?php echo esc_html( strtoupper( $l ) ); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          </table>
          <button type="submit" name="amir_send_test" class="button button-primary">Mandar email de prueba</button>
        </form>
        </div>
        <?php
    }

    private function handle_submit(): ?array {
        if ( empty( $_POST['amir_send_test'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_email_test' ) ) {
            return null;
        }

        $type = sanitize_key( $_POST['email_type'] ?? '' );
        $to   = sanitize_email( $_POST['to_email'] ?? '' );
        $lang = sanitize_key( $_POST['lang'] ?? 'es' );

        if ( ! isset( self::TYPES[ $type ] ) || ! is_email( $to ) ) {
            return [ 'success' => false, 'message' => 'Tipo de email o dirección inválida.' ];
        }

        $booking = $this->fake_booking( $to, $lang );
        $class   = self::TYPES[ $type ]['class'];
        $mailer  = new $class( $booking );
        $success = $mailer->send();

        return [
            'success' => $success,
            'message' => $success
                ? sprintf( 'Email de prueba ("%s") enviado a %s.', self::TYPES[ $type ]['label'], $to )
                : 'wp_mail() devolvió error: ' . ( $mailer->get_last_error() ?: 'sin detalle (revisá error_log del servidor)' ),
        ];
    }

    /**
     * Objeto con todos los campos que alguna plantilla de email pueda leer
     * (ver BaseEmail::booking_info_table()/maps_url()/calendar_url() y cada
     * clase Xxx­Email::get_body_content()) — con id=0 para que addons_row()
     * (que sí pega contra la base) devuelva vacío en vez de romper.
     */
    private function fake_booking( string $to_email, string $lang ): object {
        return (object) [
            'id'                      => 0,
            'booking_ref'             => 'TEST-0001',
            'access_token'            => 'test-token-0000',
            'customer_name'           => 'Cliente de Prueba',
            'customer_email'          => $to_email,
            'customer_phone'          => '+52 983 000 0000',
            'lang'                    => $lang,
            'tour_name'               => 'Tour de Prueba',
            'tour_date'               => date( 'Y-m-d', strtotime( '+7 days' ) ),
            'time_start'              => '09:00:00',
            'adults'                  => 2,
            'children'                => 1,
            'babies'                  => 0,
            'total_mxn'               => 1500,
            'meeting_point_es'        => 'Muelle principal (dato de prueba)',
            'meeting_point_en'        => 'Main dock (test data)',
            'content_i18n'            => '{}',
            'meeting_lat'             => 18.6849,
            'meeting_lng'             => -87.9789,
            'cancellation_policy_pct' => 50,
            'refund_amount_mxn'       => 750,
            'custom_email_note'       => '',
        ];
    }
}
