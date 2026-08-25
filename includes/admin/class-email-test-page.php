<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * TourFlow → ✉️ Emails — dos pestañas:
 * - "Editar textos": overrides por idioma de lo que dice cada email (ver
 *   BaseEmail::text()/EmailTexts) — antes todo el contenido vivía
 *   hardcodeado en class-email-dispatcher.php sin forma de tocarlo desde el
 *   admin, y además dependía de __()/.mo para traducir — 'en' nunca tuvo
 *   amir-booking-en_US.mo, así que un mail en inglés mostraba texto en
 *   español para todo lo que no viniera de un campo dedicado (name_en,
 *   etc.). Ahora cada texto tiene defaults es/en reales en código y puede
 *   overridearse acá por idioma.
 * - "Probar envío": manda cualquiera de los emails del sistema con datos de
 *   ejemplo (reserva "TEST-0001", ficticia), sin crear una reserva real —
 *   para revisar entregabilidad o cómo queda un texto editado.
 */
class EmailTestPage {

    private const TYPES = [
        'confirmation'      => [ 'label' => [ 'Confirmación de reserva', 'Booking confirmation' ],                'class' => \AmirBooking\Emails\ConfirmationEmail::class ],
        'reminder'          => [ 'label' => [ 'Recordatorio pre-tour', 'Pre-tour reminder' ],                     'class' => \AmirBooking\Emails\ReminderEmail::class ],
        'review'            => [ 'label' => [ 'Solicitud de reseña', 'Review request' ],                         'class' => \AmirBooking\Emails\ReviewEmail::class ],
        'cancellation'      => [ 'label' => [ 'Cancelación de reserva', 'Booking cancellation' ],                 'class' => \AmirBooking\Emails\CancellationEmail::class ],
        'tour_opened'       => [ 'label' => [ 'Tour ya disponible (wishlist)', 'Tour now available (waitlist)' ], 'class' => \AmirBooking\Emails\TourOpenedEmail::class ],
        'payment_link'      => [ 'label' => [ 'Link de pago (reserva manual)', 'Payment link (manual booking)' ],'class' => \AmirBooking\Emails\PaymentLinkEmail::class ],
        'reschedule'        => [ 'label' => [ 'Reserva reprogramada', 'Booking rescheduled' ],                    'class' => \AmirBooking\Emails\RescheduleEmail::class ],
        'provider_notice'   => [ 'label' => [ 'Marketplace: aviso al proveedor', 'Marketplace: notice to provider' ], 'class' => \AmirBooking\Emails\ProviderNoticeEmail::class ],
        'provider_pending'  => [ 'label' => [ 'Marketplace: aviso interino al cliente', 'Marketplace: interim notice to customer' ], 'class' => \AmirBooking\Emails\ProviderPendingNoticeEmail::class ],
        'date_requested'    => [ 'label' => [ 'Solicitud de fecha: aviso al cliente', 'Date request: notice to customer' ], 'class' => \AmirBooking\Emails\DateRequestedEmail::class ],
        'date_request_rejected' => [ 'label' => [ 'Solicitud de fecha: rechazo al cliente', 'Date request: rejection to customer' ], 'class' => \AmirBooking\Emails\DateRequestRejectedEmail::class ],
        'balance_payment_link' => [ 'label' => [ 'Depósito: link de pago del saldo', 'Deposit: payment link for the balance' ], 'class' => \AmirBooking\Emails\BalancePaymentLinkEmail::class ],
        'balance_paid'         => [ 'label' => [ 'Depósito: saldo recibido', 'Deposit: balance received' ],       'class' => \AmirBooking\Emails\BalancePaidEmail::class ],
    ];

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    /** Label legible de un tipo de email en el idioma de esta pantalla — ver self::TYPES. */
    private function type_label( string $type_key ): string {
        $pair = self::TYPES[ $type_key ]['label'] ?? [ $type_key, $type_key ];
        return $this->lang() === 'en' ? $pair[1] : $pair[0];
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
        }

        // Forzar que todas las clases de email estén cargadas (viven juntas
        // en class-email-dispatcher.php — cargar EmailDispatcher es
        // suficiente y barato, mismo criterio que antes).
        class_exists( \AmirBooking\Emails\EmailDispatcher::class );

        $tab = in_array( $_GET['tab'] ?? '', [ 'texts', 'test' ], true ) ? $_GET['tab'] : 'texts';

        $save_result = null;
        if ( $tab === 'texts' ) {
            $save_result = $this->handle_save_texts();
        }
        $test_result = null;
        if ( $tab === 'test' ) {
            $test_result = $this->handle_submit();
        }
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:760px;">
        <style>
        .ab-email-tabs { display:flex; gap:4px; margin:14px 0 18px; border-bottom:1px solid #e1f5ee; }
        .ab-email-tabs a { padding:9px 16px; font-size:13px; font-weight:600; text-decoration:none; color:#5a7068; border-bottom:2px solid transparent; }
        .ab-email-tabs a.active { color:#1D9E75; border-bottom-color:#1D9E75; }
        .ab-lang-pills { display:flex; gap:6px; margin-bottom:16px; }
        .ab-lang-pills a { padding:5px 13px; border-radius:20px; font-size:12px; font-weight:700; text-decoration:none; background:#f0faf6; color:#0F6E56; }
        .ab-lang-pills a.active { background:#1D9E75; color:#fff; }
        .ab-email-type { background:#fff; border:1px solid #e1f5ee; border-radius:10px; margin-bottom:12px; }
        .ab-email-type summary { cursor:pointer; padding:14px 18px; font-size:14px; font-weight:700; color:#1a2e24; list-style:none; }
        .ab-email-type summary::-webkit-details-marker { display:none; }
        .ab-email-type summary::before { content:'▸'; color:#1D9E75; margin-right:8px; display:inline-block; transition:transform .15s; }
        .ab-email-type[open] summary::before { transform:rotate(90deg); }
        .ab-email-type-body { padding:0 18px 18px; }
        .ab-text-field { margin-bottom:14px; }
        .ab-text-field label { display:block; font-size:12.5px; font-weight:600; color:#1a2e24; margin-bottom:5px; }
        .ab-text-field textarea { width:100%; box-sizing:border-box; border:1px solid #c3d9d0; border-radius:6px; padding:8px 10px; font-size:13px; font-family:Consolas,Monaco,monospace; }
        .ab-text-field .ab-reset { font-size:11px; color:#5a7068; background:none; border:none; cursor:pointer; text-decoration:underline; padding:3px 0 0; }
        .ab-hint { font-size:11px; color:#5a7068; margin:0 0 14px; }
        .ab-placeholders { font-size:11px; color:#8a9a93; margin-top:3px; }
        .ab-placeholders code { background:#f0faf6; color:#0F6E56; padding:1px 5px; border-radius:4px; }
        </style>

        <h1>✉️ <?php echo esc_html( $this->tt( 'Emails', 'Emails' ) ); ?></h1>

        <div class="ab-email-tabs">
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-email-test&tab=texts') ); ?>" class="<?php echo $tab === 'texts' ? 'active' : ''; ?>"><?php echo esc_html( $this->tt( 'Editar textos', 'Edit texts' ) ); ?></a>
          <a href="<?php echo esc_url( admin_url('admin.php?page=amir-email-test&tab=test') ); ?>" class="<?php echo $tab === 'test' ? 'active' : ''; ?>"><?php echo esc_html( $this->tt( 'Probar envío', 'Test sending' ) ); ?></a>
        </div>

        <?php if ( $tab === 'texts' ) : ?>
          <?php $this->render_texts_tab( $save_result ); ?>
        <?php else : ?>
          <?php $this->render_test_tab( $test_result ); ?>
        <?php endif; ?>
        </div>
        <?php
    }

    // ── Pestaña: editar textos ──────────────────────────────────────────────

    private function render_texts_tab( ?array $save_result ): void {
        $active_langs = \AmirBooking\Core\Languages::active();
        $lang = sanitize_key( $_GET['lang'] ?? '' );
        if ( ! in_array( $lang, $active_langs, true ) ) {
            $lang = $active_langs[0] ?? 'es';
        }

        ?>
        <p class="ab-hint" style="margin-top:-8px;">
          <?php echo wp_kses_post( $this->tt(
            'Lo que dice cada email — dejá un campo en blanco y guardá para volver al texto por defecto. Los textos con <code>{ref}</code>, <code>{name}</code>, etc. entre llaves reemplazan datos reales de la reserva al enviarse — no los borres.',
            "What each email says — leave a field blank and save to go back to the default text. Text with <code>{ref}</code>, <code>{name}</code>, etc. in curly braces gets replaced with real booking data when sent — don't delete those."
          ) ); ?>
        </p>

        <?php if ( $save_result ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( $this->tt( 'Textos guardados para %s.', 'Texts saved for %s.' ), strtoupper( $save_result['lang'] ) ) ); ?></p></div>
        <?php endif; ?>

        <?php if ( count( $active_langs ) > 1 ) : ?>
        <div class="ab-lang-pills">
          <?php foreach ( $active_langs as $l ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-email-test&tab=texts&lang=' . $l ) ); ?>"
               class="<?php echo $l === $lang ? 'active' : ''; ?>"><?php echo esc_html( strtoupper( $l ) ); ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php foreach ( self::TYPES as $type_key => $t ) :
            /** @var \AmirBooking\Emails\BaseEmail $class */
            $class    = $t['class'];
            $fields   = $class::text_fields();
            if ( empty( $fields ) ) {
                continue;
            }
            $overrides = \AmirBooking\Emails\EmailTexts::overrides_for( $type_key, $lang );
        ?>
        <details class="ab-email-type">
          <summary><?php echo esc_html( $this->type_label( $type_key ) ); ?></summary>
          <div class="ab-email-type-body">
            <form method="post">
              <?php wp_nonce_field( 'amir_save_email_texts_' . $type_key ); ?>
              <input type="hidden" name="email_type" value="<?php echo esc_attr( $type_key ); ?>" />
              <input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />

              <?php foreach ( $fields as $key => $field ) :
                  $default = $lang === 'es' ? ( $field['es'] ?? '' ) : ( $field['en'] ?? ( $field['es'] ?? '' ) );
                  $current = $overrides[ $key ] ?? $default;
                  $field_id = 'ab-txt-' . $type_key . '-' . $lang . '-' . $key;
                  preg_match_all( '/\{([a-z_]+)\}/', $default, $m );
              ?>
                <div class="ab-text-field">
                  <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field['label'] ?? $key ); ?></label>
                  <textarea id="<?php echo esc_attr( $field_id ); ?>" name="texts[<?php echo esc_attr( $key ); ?>]"
                            rows="<?php echo strlen( $default ) > 90 ? 3 : 1; ?>"><?php echo esc_textarea( $current ); ?></textarea>
                  <button type="button" class="ab-reset" data-default="<?php echo esc_attr( $default ); ?>"
                          onclick="this.previousElementSibling.value=this.dataset.default"><?php echo esc_html( $this->tt( 'Restaurar texto por defecto', 'Restore default text' ) ); ?></button>
                  <?php if ( ! empty( $m[1] ) ) : ?>
                    <div class="ab-placeholders"><?php echo esc_html( $this->tt( 'Variables:', 'Variables:' ) ); ?> <?php foreach ( $m[1] as $ph ) : ?><code>{<?php echo esc_html( $ph ); ?>}</code> <?php endforeach; ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>

              <button type="submit" name="amir_save_email_texts" value="1" class="button button-primary"><?php echo esc_html( $this->tt( 'Guardar', 'Save' ) ); ?></button>
            </form>
          </div>
        </details>
        <?php endforeach; ?>
        <?php
    }

    private function handle_save_texts(): ?array {
        if ( ! isset( $_POST['amir_save_email_texts'] ) ) {
            return null;
        }
        $type = sanitize_key( $_POST['email_type'] ?? '' );
        $lang = sanitize_key( $_POST['lang'] ?? '' );

        if ( ! isset( self::TYPES[ $type ] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_save_email_texts_' . $type ) ) {
            return null;
        }

        $class  = self::TYPES[ $type ]['class'];
        $fields = $class::text_fields();
        $texts  = [];
        // wp_unslash() antes de sanitizar — mismo bug real que el resto del
        // plugin (2026-08-05): sin esto, cualquier texto de email con una
        // comilla se guardaba corrupto.
        foreach ( array_keys( $fields ) as $key ) {
            $texts[ $key ] = wp_kses_post( wp_unslash( (string) ( $_POST['texts'][ $key ] ?? '' ) ) );
        }

        \AmirBooking\Emails\EmailTexts::save( $type, $lang, $texts );

        return [ 'lang' => $lang ];
    }

    // ── Pestaña: probar envío ───────────────────────────────────────────────

    private function render_test_tab( ?array $result ): void {
        ?>
        <p class="ab-hint" style="margin-top:-8px;">
          <?php echo esc_html( $this->tt(
            'Manda cualquiera de los emails del sistema con datos de ejemplo (reserva "TEST-0001", ficticia) — útil para revisar entregabilidad (spam, SMTP) o cómo se ve cada plantilla, sin crear una reserva real.',
            'Sends any of the system\'s emails with sample data (a fake "TEST-0001" booking) — useful for checking deliverability (spam, SMTP) or how each template looks, without creating a real booking.'
          ) ); ?>
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
              <th style="width:140px;"><label for="amir-email-type"><?php echo esc_html( $this->tt( 'Tipo de email', 'Email type' ) ); ?></label></th>
              <td>
                <select name="email_type" id="amir-email-type">
                  <?php foreach ( self::TYPES as $key => $t ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->type_label( $key ) ); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr>
              <th><label for="amir-email-to"><?php echo esc_html( $this->tt( 'Enviar a', 'Send to' ) ); ?></label></th>
              <td>
                <input type="email" name="to_email" id="amir-email-to" class="regular-text" required
                       value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
              </td>
            </tr>
            <tr>
              <th><label for="amir-email-lang"><?php echo esc_html( $this->tt( 'Idioma', 'Language' ) ); ?></label></th>
              <td>
                <select name="lang" id="amir-email-lang">
                  <?php foreach ( \AmirBooking\Core\Languages::active() as $l ) : ?>
                    <option value="<?php echo esc_attr( $l ); ?>"><?php echo esc_html( strtoupper( $l ) ); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          </table>
          <button type="submit" name="amir_send_test" value="1" class="button button-primary"><?php echo esc_html( $this->tt( 'Mandar email de prueba', 'Send test email' ) ); ?></button>
        </form>
        <?php
    }

    private function handle_submit(): ?array {
        // isset(), no empty(): el botón de submit no llevaba `value` antes,
        // así que el navegador lo mandaba como '' — y empty('') es true en
        // PHP, así que este chequeo fallaba SIEMPRE sin importar el click.
        // Ese era el bug real detrás de "el test de emails no manda nada":
        // nunca llegaba a intentar wp_mail(), para ningún tipo de email.
        if ( ! isset( $_POST['amir_send_test'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_email_test' ) ) {
            return null;
        }

        $type = sanitize_key( $_POST['email_type'] ?? '' );
        $to   = sanitize_email( $_POST['to_email'] ?? '' );
        $lang = sanitize_key( $_POST['lang'] ?? 'es' );

        if ( ! isset( self::TYPES[ $type ] ) || ! is_email( $to ) ) {
            return [ 'success' => false, 'message' => $this->tt( 'Tipo de email o dirección inválida.', 'Invalid email type or address.' ) ];
        }

        $booking = $this->fake_booking( $to, $lang );
        $class   = self::TYPES[ $type ]['class'];

        // CancellationEmail es la única clase con un constructor distinto al
        // resto (BaseEmail::__construct(booking, ?to_override)) — exige un
        // $reason_type obligatorio aparte. Instanciarla igual que las demás,
        // con un solo argumento, tira "Too few arguments" — error fatal, no
        // un fallo silencioso de wp_mail(). 'client' como default de prueba.
        $mailer  = $class === \AmirBooking\Emails\CancellationEmail::class
            ? new $class( $booking, 'client' )
            : new $class( $booking );
        $success = $mailer->send();

        return [
            'success' => $success,
            'message' => $success
                ? sprintf( $this->tt( 'Email de prueba ("%s") enviado a %s.', 'Test email ("%s") sent to %s.' ), $this->type_label( $type ), $to )
                : $this->tt( 'wp_mail() devolvió error: ', 'wp_mail() returned an error: ' ) . ( $mailer->get_last_error() ?: $this->tt( 'sin detalle (revisá error_log del servidor)', 'no detail (check the server error_log)' ) ),
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
            // Marketplace de proveedores (§ 11 CONTRIBUTING.md) — solo lo
            // usan ProviderNoticeEmail/ProviderPendingNoticeEmail/CancellationEmail
            // con reason_type provider_*, pero se completa siempre por si
            // se agrega otro tipo de email que también los lea.
            'provider_business_name'  => 'Proveedor de Prueba S.A.',
            'provider_contact_name'   => 'Contacto de Prueba',
            'provider_response_token' => 'test-provider-token-0000',
            'provider_reject_reason'  => '',
            'special_requests'        => '',
        ];
    }
}
