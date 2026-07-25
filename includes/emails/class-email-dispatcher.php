<?php
namespace AmirBooking\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Despachador de emails.
 * Escucha los WP actions del plugin y despacha el email correcto.
 */
class EmailDispatcher {

    public function register(): void {
        add_action( 'amir_booking_confirmed',   [ $this, 'send_confirmation'   ], 10, 1 );
        add_action( 'amir_send_reminder_email', [ $this, 'send_reminder'       ], 10, 1 );
        add_action( 'amir_send_review_email',   [ $this, 'send_review_request' ], 10, 1 );
        add_action( 'amir_booking_cancelled',   [ $this, 'send_cancellation'   ], 10, 2 );
    }

    // ── Lista de interés: link de pago real ───────────────────────────────
    // Se llama desde el admin (Lista de interés → "Publicar y notificar")
    // para cada reserva 'wishlist' de ese tour, una vez que pasa a
    // 'awaiting_payment'. $booking ya es una reserva real (booking_ref,
    // access_token, etc.) — el link de pago reutiliza verify_url() de
    // BaseEmail tal cual, sin armar nada a mano.

    public function send_tour_opened_notice( object $booking ): bool {
        $mailer = new TourOpenedEmail( $booking );
        return $mailer->send();
    }

    // ── Confirmación ──────────────────────────────────────────────────────

    public function send_confirmation( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }

        $mailer = new ConfirmationEmail( $booking );
        $mailer->send();

        // Notificar también al admin
        $this->notify_admin_new_booking( $booking );
    }

    // ── Recordatorio pre-tour ─────────────────────────────────────────────

    public function send_reminder( object $booking ): void {
        $mailer = new ReminderEmail( $booking );
        $mailer->send();
    }

    // ── Solicitud de reseña ───────────────────────────────────────────────

    public function send_review_request( object $booking ): void {
        $mailer = new ReviewEmail( $booking );
        $mailer->send();
    }

    // ── Cancelación ───────────────────────────────────────────────────────

    public function send_cancellation( int $booking_id, string $reason_type ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        $mailer = new CancellationEmail( $booking, $reason_type );
        $mailer->send();
    }

    // ── Admin: nueva reserva ──────────────────────────────────────────────

    private function notify_admin_new_booking( object $booking ): void {
        $admin_email = get_option( 'amir_admin_email', get_option('admin_email') );
        if ( ! $admin_email ) {
            return;
        }

        $subject = sprintf( '[Nueva reserva] %s — %s el %s',
            $booking->booking_ref,
            $booking->customer_name,
            $booking->tour_date
        );

        $body = sprintf(
            "Nueva reserva recibida:\n\n" .
            "Referencia: %s\n" .
            "Tour: %s\n" .
            "Fecha: %s\n" .
            "Hora: %s\n" .
            "Cliente: %s (%s)\n" .
            "Teléfono: %s\n" .
            "Adultos: %d | Niños: %d | Bebés: %d\n" .
            "Total: %s\n" .
            "Origen: %s\n\n" .
            "Ver en el panel: %s",
            $booking->booking_ref,
            $booking->tour_name,
            $booking->tour_date,
            $booking->time_start ?? '',
            $booking->customer_name,
            $booking->customer_email,
            $booking->customer_phone,
            $booking->adults,
            $booking->children,
            $booking->babies,
            \AmirBooking\Core\Currency::format( (float) $booking->total_mxn ),
            $booking->booking_source,
            admin_url( 'admin.php?page=amir-bookings-list' )
        );

        wp_mail( $admin_email, $subject, $body );
    }

    // ── Helper ────────────────────────────────────────────────────────────

    private function get_booking_with_tour( int $booking_id ): ?object {
        global $wpdb;
        // LEFT JOIN para que reservas sin schedule_id (=0) también se incluyan
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*,
                        t.name_es as tour_name_es, t.name_en as tour_name_en,
                        t.meeting_point_es, t.meeting_point_en,
                        t.meeting_lat, t.meeting_lng,
                        t.gallery_images,
                        s.time_start, s.time_end, s.label_es, s.label_en,
                        CASE WHEN b.lang = 'en' THEN t.name_en ELSE t.name_es END as tour_name
                 FROM {$wpdb->prefix}amir_bookings b
                 JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
                 WHERE b.id = %d",
                $booking_id
            )
        );
    }
}

// ── Base Email ────────────────────────────────────────────────────────────────

abstract class BaseEmail {

    protected object $booking;
    protected string $lang;

    public function __construct( object $booking ) {
        $this->booking = $booking;
        $this->lang    = $booking->lang ?? 'es';
    }

    abstract protected function get_subject(): string;
    abstract protected function get_body_content(): string;

    public function send(): bool {
        add_filter( 'wp_mail_content_type', [ $this, 'set_html' ] );

        $result = wp_mail(
            $this->booking->customer_email,
            $this->get_subject(),
            $this->wrap_template( $this->get_body_content() )
        );

        remove_filter( 'wp_mail_content_type', [ $this, 'set_html' ] );

        // Log si falla
        if ( ! $result ) {
            $last_error = '';
            if ( isset( $GLOBALS['phpmailer'] ) && $GLOBALS['phpmailer']->ErrorInfo ) {
                $last_error = $GLOBALS['phpmailer']->ErrorInfo;
            }
            error_log( sprintf(
                'Amir Booking: wp_mail falló para %s — Asunto: %s — Error: %s',
                $this->booking->customer_email,
                $this->get_subject(),
                $last_error
            ) );
        }

        return $result;
    }

    public function set_html(): string {
        return 'text/html';
    }

    // ── HTML wrapper ──────────────────────────────────────────────────────

    protected function wrap_template( string $content ): string {
        $logo_url     = get_option( 'amir_brand_logo_url', '' );
        $logo         = $logo_url ?: ( AMIR_PLUGIN_URL . 'assets/images/logo-email.png' );
        $color        = get_option( 'amir_brand_color', '#1D9E75' );
        $color_dark   = $this->darken_color( $color );
        $color_light  = $this->lighten_color( $color );
        $company_name = get_option( 'amir_company_name', 'Amir Adventours Bacalar' );
        $site         = get_site_url();
        $wa           = get_option( 'amir_wa_phone', '5219831649541' );
        $year         = date( 'Y' );

        $footer_links = $this->lang === 'en'
            ? '<a href="' . $site . '/en/">Tours</a> &nbsp;·&nbsp; <a href="https://wa.me/' . $wa . '">WhatsApp</a>'
            : '<a href="' . $site . '/tours/">Tours</a> &nbsp;·&nbsp; <a href="https://wa.me/' . $wa . '">WhatsApp</a>';

        return '<!DOCTYPE html><html lang="' . $this->lang . '">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . esc_html( $this->get_subject() ) . '</title>
  <style>
    body { margin:0; padding:0; background:#f0f9f5; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#1a2e24; }
    .wrap { max-width:560px; margin:0 auto; }
    .header { background:' . esc_attr($color) . '; padding:24px 32px; text-align:center; }
    .header img { height:48px; }
    .body { background:#ffffff; padding:32px 32px 24px; }
    .footer { background:' . esc_attr($color_light) . '; padding:20px 32px; text-align:center; font-size:12px; color:#5a7068; }
    .footer a { color:' . esc_attr($color_dark) . '; text-decoration:none; }
    h1 { font-size:22px; font-weight:800; margin:0 0 8px; }
    p  { font-size:15px; line-height:1.6; color:#3d3d3a; margin:0 0 14px; }
    .ref-box { background:' . esc_attr($color_light) . '; border-radius:10px; padding:16px 20px; text-align:center; margin:20px 0; }
    .ref-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:' . esc_attr($color_dark) . '; }
    .ref-value { font-size:28px; font-weight:800; color:' . esc_attr($color_dark) . '; letter-spacing:2px; margin-top:4px; }
    .info-table { width:100%; border-collapse:collapse; margin:16px 0; }
    .info-table td { padding:10px 0; border-bottom:1px solid ' . esc_attr($color_light) . '; font-size:14px; vertical-align:top; }
    .info-table td:first-child { color:#5a7068; width:40%; padding-right:12px; }
    .info-table td:last-child { font-weight:600; }
    .btn { display:inline-block; padding:13px 28px; background:' . esc_attr($color) . '; color:#ffffff !important; border-radius:8px; text-decoration:none; font-size:15px; font-weight:700; margin:16px 0 8px; }
    .btn-outline { background:transparent; color:' . esc_attr($color) . ' !important; border:2px solid ' . esc_attr($color) . '; }
    .policy-box { background:#fffbeb; border-left:4px solid #BA7517; padding:12px 16px; border-radius:0 8px 8px 0; margin:16px 0; }
    .policy-box p { font-size:13px; color:#78350f; margin:3px 0; }
    .divider { border:none; border-top:1px solid ' . esc_attr($color_light) . '; margin:20px 0; }
    @media (max-width:600px) {
      .body { padding:24px 20px 20px; }
      h1 { font-size:20px; }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <img src="' . esc_url($logo) . '" alt="' . esc_attr($company_name) . '" style="height:48px;max-width:200px;" />
  </div>
  <div class="body">' . $content . '</div>
  <div class="footer">
    ' . $footer_links . '<br><br>
    &copy; ' . $year . ' ' . esc_html($company_name) . ' &nbsp;·&nbsp; Bacalar, Quintana Roo, M&eacute;xico
  </div>
</div>
</body></html>';
    }

    /**
     * URL de la página pública de verificación de reserva, con el
     * access_token de la reserva para que el link autorice la lectura
     * sin pedirle el email de nuevo al cliente.
     */
    protected function verify_url(): string {
        $page_id = (int) get_option( 'amir_verify_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : false;
        $base    = $base ?: ( get_site_url() . '/verificar-reserva/' );

        return add_query_arg(
            [ 'ref' => $this->booking->booking_ref, 'token' => $this->booking->access_token ?? '' ],
            $base
        );
    }

    /**
     * Oscurece un color hex ~20% para textos sobre fondos claros.
     */
    private function darken_color( string $hex, float $factor = 0.7 ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen($hex) !== 6 ) return $hex;
        $r = (int) ( hexdec( substr($hex,0,2) ) * $factor );
        $g = (int) ( hexdec( substr($hex,2,2) ) * $factor );
        $b = (int) ( hexdec( substr($hex,4,2) ) * $factor );
        return sprintf( '#%02x%02x%02x', max(0,$r), max(0,$g), max(0,$b) );
    }

    /**
     * Aclara un color hex para fondos (~90% blanco).
     */
    private function lighten_color( string $hex, float $factor = 0.15 ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen($hex) !== 6 ) return '#e1f5ee';
        $r = hexdec( substr($hex,0,2) );
        $g = hexdec( substr($hex,2,2) );
        $b = hexdec( substr($hex,4,2) );
        $r = (int) ( $r + ( 255 - $r ) * ( 1 - $factor ) );
        $g = (int) ( $g + ( 255 - $g ) * ( 1 - $factor ) );
        $b = (int) ( $b + ( 255 - $b ) * ( 1 - $factor ) );
        return sprintf( '#%02x%02x%02x', min(255,$r), min(255,$g), min(255,$b) );
    }

    // ── Recomendaciones desde opciones ───────────────────────────────────

    /**
     * Devuelve el bloque HTML de recomendaciones leído desde las opciones del plugin.
     * Si la opción está vacía usa el array de $defaults.
     */
    protected function recs_html( string $option_es, string $option_en, array $defaults_es, array $defaults_en ): string {
        $raw  = get_option( $this->lang === 'en' ? $option_en : $option_es, '' );
        $items = $raw
            ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) )
            : ( $this->lang === 'en' ? $defaults_en : $defaults_es );

        $style = 'font-size:14px;color:#3d3d3a;line-height:1.8;padding-left:20px;';
        $title = $this->lang === 'en' ? '📋 Recommendations' : '📋 Recomendaciones';
        $lis   = '';
        foreach ( $items as $item ) {
            $lis .= '<li>' . esc_html( $item ) . '</li>';
        }
        return '<hr class="divider"><p><strong>' . $title . '</strong></p>'
             . '<ul style="' . $style . '">' . $lis . '</ul>';
    }

    // ── Helpers compartidos ───────────────────────────────────────────────

    protected function t( string $key ): string {
        $strings = [
            'es' => [
                'booking_ref'    => 'Número de reserva',
                'tour'           => 'Tour',
                'date'           => 'Fecha',
                'time'           => 'Hora de salida',
                'meeting'        => 'Punto de encuentro',
                'people'         => 'Personas',
                'total'          => 'Total pagado',
                'adults'         => 'adultos',
                'children'       => 'niños',
                'babies'         => 'bebés',
                'maps_link'      => 'Ver en mapa',
                'download_pdf'   => 'Descargar mi voucher PDF',
                'add_cal'        => 'Agregar al calendario',
                'wa_help'        => '¿Necesitas ayuda? Escríbenos por WhatsApp',
            ],
            'en' => [
                'booking_ref'    => 'Booking reference',
                'tour'           => 'Tour',
                'date'           => 'Date',
                'time'           => 'Departure time',
                'meeting'        => 'Meeting point',
                'people'         => 'People',
                'total'          => 'Total paid',
                'adults'         => 'adults',
                'children'       => 'children',
                'babies'         => 'babies',
                'maps_link'      => 'Open in maps',
                'download_pdf'   => 'Download my PDF voucher',
                'add_cal'        => 'Add to calendar',
                'wa_help'        => 'Need help? Message us on WhatsApp',
            ],
        ];
        return $strings[ $this->lang ][ $key ] ?? $strings['es'][ $key ] ?? $key;
    }

    protected function pax_summary(): string {
        $b    = $this->booking;
        $parts = [];
        if ( (int)$b->adults   > 0 ) $parts[] = $b->adults   . ' ' . $this->t('adults');
        if ( (int)$b->children > 0 ) $parts[] = $b->children . ' ' . $this->t('children');
        if ( (int)$b->babies   > 0 ) $parts[] = $b->babies   . ' ' . $this->t('babies');
        return implode( ', ', $parts );
    }

    protected function fmt_date( string $date ): string {
        $months = $this->lang === 'en'
            ? ['January','February','March','April','May','June','July','August','September','October','November','December']
            : ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        [ $y, $m, $d ] = explode( '-', $date );
        return (int)$d . ' ' . $months[ (int)$m - 1 ] . ' ' . $y;
    }

    protected function fmt_time( string $time ): string {
        [ $h, $m ] = explode( ':', $time );
        $hnum = (int) $h;
        $ampm = $hnum >= 12 ? 'PM' : 'AM';
        $h12  = $hnum > 12 ? $hnum - 12 : ( $hnum ?: 12 );
        return "{$h12}:{$m} {$ampm}";
    }

    protected function maps_url(): string {
        $b = $this->booking;
        if ( $b->meeting_lat && $b->meeting_lng ) {
            return "https://maps.google.com/?q={$b->meeting_lat},{$b->meeting_lng}";
        }
        return 'https://maps.google.com/?q=Bacalar,Quintana+Roo,Mexico';
    }

    protected function calendar_url(): string {
        $b     = $this->booking;
        $start = str_replace('-','',$b->tour_date) . 'T' . str_replace(':','',$b->time_start??'') . '00';
        $title = rawurlencode( $b->tour_name . ' — Amir Adventours' );
        $loc   = rawurlencode( $b->meeting_point_es ?? 'Bacalar, México' );
        return "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$start}/{$start}&location={$loc}";
    }

    protected function booking_info_table(): string {
        $b   = $this->booking;
        $mp  = $this->lang === 'en' ? $b->meeting_point_en : $b->meeting_point_es;
        $wa  = get_option( 'amir_wa_phone', '5219831649541' );

        return '
        <table class="info-table">
          <tr>
            <td>' . $this->t('booking_ref') . '</td>
            <td><strong>' . esc_html( $b->booking_ref ) . '</strong></td>
          </tr>
          <tr>
            <td>' . $this->t('tour') . '</td>
            <td>' . esc_html( $b->tour_name ) . '</td>
          </tr>
          <tr>
            <td>' . $this->t('date') . '</td>
            <td>' . $this->fmt_date( $b->tour_date ) . '</td>
          </tr>
          <tr>
            <td>' . $this->t('time') . '</td>
            <td>' . $this->fmt_time( $b->time_start ?? '00:00' ) . '</td>
          </tr>
          <tr>
            <td>' . $this->t('people') . '</td>
            <td>' . $this->pax_summary() . '</td>
          </tr>
          <tr>
            <td>' . $this->t('total') . '</td>
            <td>' . \AmirBooking\Core\Currency::format( (float) $b->total_mxn ) . '</td>
          </tr>
          <tr>
            <td>' . $this->t('meeting') . '</td>
            <td>' . esc_html( $mp ?? '' ) . '<br>
              <a href="' . $this->maps_url() . '" style="color:#1D9E75;font-size:13px;">' . $this->t('maps_link') . ' ↗</a>
            </td>
          </tr>
        </table>';
    }
}

// ── Email de confirmación ─────────────────────────────────────────────────────

class ConfirmationEmail extends BaseEmail {

    protected function get_subject(): string {
        return $this->lang === 'en'
            ? '✅ Your booking is confirmed — ' . $this->booking->booking_ref
            : '✅ Tu reserva está confirmada — ' . $this->booking->booking_ref;
    }

    protected function get_body_content(): string {
        $b       = $this->booking;
        $siteUrl = get_site_url();
        $wa      = get_option( 'amir_wa_phone', '5219831649541' );

        $intro = $this->lang === 'en'
            ? '<h1>Your booking is confirmed! 🎉</h1><p>Hi <strong>' . esc_html($b->customer_name) . '</strong>,<br>Everything is set for your adventure in Bacalar. Here are your booking details:</p>'
            : '<h1>¡Tu reserva está confirmada! 🎉</h1><p>Hola <strong>' . esc_html($b->customer_name) . '</strong>,<br>Todo está listo para tu aventura en Bacalar. Aquí están los detalles de tu reserva:</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html($b->booking_ref) . '</div>
        </div>';

        $recs_block = $this->recs_html(
            'amir_email_recs_es', 'amir_email_recs_en',
            [ 'Llega 10 minutos antes al punto de encuentro.', 'Usa ropa cómoda y protector solar biodegradable.', 'Trae agua y snacks ligeros.', 'Lleva tu documento de identidad.' ],
            [ 'Arrive 10 minutes before departure.', 'Wear comfortable clothes and biodegradable sunscreen.', 'Bring water and light snacks.', 'Carry a photo ID.' ]
        );

        $policy_es = '<div class="policy-box"><p><strong>Política de cancelación:</strong></p><p>✓ 7+ días antes: reembolso completo</p><p>▸ 3–6 días antes: reembolso del 50%</p><p>✕ Menos de 3 días: sin reembolso</p></div>';
        $policy_en = '<div class="policy-box"><p><strong>Cancellation policy:</strong></p><p>✓ 7+ days before: full refund</p><p>▸ 3–6 days before: 50% refund</p><p>✕ Less than 3 days: no refund</p></div>';

        $pdf_url = rest_url( 'amir/v1/bookings/' . rawurlencode($b->booking_ref) . '/pdf' )
                   . '?token=' . rawurlencode( $b->access_token ?? '' );

        $actions = '
        <p style="text-align:center;margin-top:24px;">
          <a href="' . esc_url($pdf_url) . '" class="btn">' . $this->t('download_pdf') . '</a>
          &nbsp;&nbsp;
          <a href="' . $this->calendar_url() . '" class="btn btn-outline">' . $this->t('add_cal') . '</a>
        </p>
        <p style="text-align:center;font-size:13px;margin-top:12px;">
          <a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . ( $this->lang === 'en' ? 'View booking status' : 'Ver estado de mi reserva' ) . '</a>
        </p>
        <p style="text-align:center;font-size:13px;color:#5a7068;margin-top:8px;">
          ' . $this->t('wa_help') . ': <a href="https://wa.me/' . $wa . '" style="color:#1D9E75;">wa.me/' . $wa . '</a>
        </p>';

        $custom_note = '';
        if ( ! empty( $b->custom_email_note ) ) {
            $note_label  = $this->lang === 'en' ? 'Note from the operator:' : 'Nota del operador:';
            $custom_note = '<div style="background:#e8f5e9;border-left:4px solid #1D9E75;padding:12px 16px;'
                         . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                         . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                         . '<strong>' . $note_label . '</strong><br>'
                         . nl2br( esc_html( $b->custom_email_note ) )
                         . '</p></div>';
        }

        return $intro
            . $ref_box
            . $this->booking_info_table()
            . $custom_note
            . $recs_block
            . ( $this->lang === 'en' ? $policy_en : $policy_es )
            . $actions;
    }
}

// ── Email recordatorio ────────────────────────────────────────────────────────

class ReminderEmail extends BaseEmail {

    protected function get_subject(): string {
        return $this->lang === 'en'
            ? '⏰ Your tour is tomorrow! — ' . $this->booking->tour_name
            : '⏰ ¡Tu tour es mañana! — ' . $this->booking->tour_name;
    }

    protected function get_body_content(): string {
        $b  = $this->booking;
        $wa = get_option( 'amir_wa_phone', '5219831649541' );

        $intro = $this->lang === 'en'
            ? '<h1>Your adventure is tomorrow! ⛵</h1><p>Hi <strong>' . esc_html($b->customer_name) . '</strong>,<br>Just a quick reminder about your booking for tomorrow:</p>'
            : '<h1>¡Tu aventura es mañana! ⛵</h1><p>Hola <strong>' . esc_html($b->customer_name) . '</strong>,<br>Un recordatorio de tu reserva para mañana:</p>';

        $recs_block = $this->recs_html(
            'amir_email_recs_es', 'amir_email_recs_en',
            [ 'Ropa cómoda y traje de baño', 'Protector solar biodegradable (obligatorio en la laguna)', 'Agua y snacks ligeros', 'Documento de identidad', 'Cámara o celular en bolsa impermeable' ],
            [ 'Comfortable clothes and swimsuit', 'Biodegradable sunscreen (required on the lagoon)', 'Water and light snacks', 'Photo ID', 'Camera or phone in a waterproof bag' ]
        );

        $footer_wa = '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">' . $this->t('wa_help') . ': <a href="https://wa.me/' . $wa . '" style="color:#1D9E75;">wa.me/' . $wa . '</a></p>';

        return $intro
            . $this->booking_info_table()
            . $recs_block
            . $footer_wa;
    }
}

// ── Email solicitud de reseña ─────────────────────────────────────────────────

class ReviewEmail extends BaseEmail {

    protected function get_subject(): string {
        return $this->lang === 'en'
            ? '⭐ How was your experience? — Amir Adventours'
            : '⭐ ¿Cómo fue tu experiencia? — Amir Adventours';
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        $tripadvisor_url = get_option('amir_tripadvisor_review_url',
            'https://www.tripadvisor.com/Attraction_Review-g2369583-d23441870-Reviews-Amir_AdvenTours-Bacalar_Yucatan_Peninsula.html');
        $google_url = get_option('amir_google_review_url', '#');

        $body_es = '<h1>¿Disfrutaste tu aventura en Bacalar? 🌊</h1>
        <p>Hola <strong>' . esc_html($b->customer_name) . '</strong>,<br>
        Esperamos que hayas tenido una experiencia increíble con nosotros en <em>' . esc_html($b->tour_name) . '</em>.</p>
        <p>Tu opinión nos ayuda a seguir mejorando y a que más viajeros descubran la magia de Bacalar. Si tienes un minuto, nos encantaría que compartieras tu experiencia:</p>
        <p style="text-align:center;margin:24px 0;">
          <a href="' . $tripadvisor_url . '" class="btn">⭐ Reseña en TripAdvisor</a>
          &nbsp;&nbsp;
          <a href="' . $google_url . '" class="btn btn-outline">⭐ Reseña en Google</a>
        </p>
        <p style="font-size:13px;color:#5a7068;text-align:center;">¡Gracias por elegirnos! Esperamos verte de nuevo pronto. 🐊</p>';

        $body_en = '<h1>How was your adventure in Bacalar? 🌊</h1>
        <p>Hi <strong>' . esc_html($b->customer_name) . '</strong>,<br>
        We hope you had an amazing experience with us on the <em>' . esc_html($b->tour_name) . '</em>.</p>
        <p>Your feedback helps us keep improving and helps other travelers discover the magic of Bacalar. If you have a minute, we\'d love to hear about your experience:</p>
        <p style="text-align:center;margin:24px 0;">
          <a href="' . $tripadvisor_url . '" class="btn">⭐ Review on TripAdvisor</a>
          &nbsp;&nbsp;
          <a href="' . $google_url . '" class="btn btn-outline">⭐ Review on Google</a>
        </p>
        <p style="font-size:13px;color:#5a7068;text-align:center;">Thank you for choosing us! We hope to see you again soon. 🐊</p>';

        return $this->lang === 'en' ? $body_en : $body_es;
    }
}

// ── Email cancelación ─────────────────────────────────────────────────────────

class CancellationEmail extends BaseEmail {

    private string $reason_type;

    public function __construct( object $booking, string $reason_type ) {
        parent::__construct( $booking );
        $this->reason_type = $reason_type;
    }

    protected function get_subject(): string {
        return $this->lang === 'en'
            ? 'Booking cancellation — ' . $this->booking->booking_ref
            : 'Cancelación de reserva — ' . $this->booking->booking_ref;
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        $is_operator = in_array( $this->reason_type, [ 'weather', 'min_pax' ], true );

        if ( $this->lang === 'en' ) {
            if ( $is_operator ) {
                $title = $this->reason_type === 'weather'
                    ? '<h1>Tour cancelled due to weather ⛈</h1>'
                    : '<h1>Tour cancelled — minimum passengers not reached</h1>';
                $msg = '<p>Hi <strong>' . esc_html($b->customer_name) . '</strong>,<br>We\'re sorry to inform you that your booking <strong>' . esc_html($b->booking_ref) . '</strong> has been cancelled. A <strong>full refund</strong> has been processed and will appear in your account within 3–5 business days.</p>';
            } else {
                $title = '<h1>Booking cancelled</h1>';
                $policy = (int) $b->cancellation_policy_pct;
                $refund_msg = $policy === 0
                    ? 'A full refund has been processed and will appear in your account within 3–5 business days.'
                    : ( $policy === 50
                        ? 'A 50% refund of ' . \AmirBooking\Core\Currency::format( (float) $b->refund_amount_mxn ) . ' has been processed.'
                        : 'Per our cancellation policy, no refund is applicable for cancellations within 2 days of the tour.' );
                $msg = '<p>Hi <strong>' . esc_html($b->customer_name) . '</strong>,<br>Your booking <strong>' . esc_html($b->booking_ref) . '</strong> has been cancelled. ' . $refund_msg . '</p>';
            }
        } else {
            if ( $is_operator ) {
                $title = $this->reason_type === 'weather'
                    ? '<h1>Tour cancelado por condiciones climáticas ⛈</h1>'
                    : '<h1>Tour cancelado — mínimo de pasajeros no alcanzado</h1>';
                $msg = '<p>Hola <strong>' . esc_html($b->customer_name) . '</strong>,<br>Lamentamos informarte que tu reserva <strong>' . esc_html($b->booking_ref) . '</strong> ha sido cancelada. Se ha procesado un <strong>reembolso completo</strong> que aparecerá en tu cuenta en 3–5 días hábiles.</p>';
            } else {
                $title = '<h1>Reserva cancelada</h1>';
                $policy = (int) $b->cancellation_policy_pct;
                $refund_msg = $policy === 0
                    ? 'Se ha procesado un reembolso completo que aparecerá en tu cuenta en 3–5 días hábiles.'
                    : ( $policy === 50
                        ? 'Se ha procesado un reembolso del 50% por ' . \AmirBooking\Core\Currency::format( (float) $b->refund_amount_mxn ) . '.'
                        : 'De acuerdo con nuestra política, no aplica reembolso para cancelaciones dentro de los 2 días previos al tour.' );
                $msg = '<p>Hola <strong>' . esc_html($b->customer_name) . '</strong>,<br>Tu reserva <strong>' . esc_html($b->booking_ref) . '</strong> ha sido cancelada. ' . $refund_msg . '</p>';
            }
        }

        $wa  = get_option( 'amir_wa_phone', '5219831649541' );
        $footer = '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">' . $this->t('wa_help') . ': <a href="https://wa.me/' . $wa . '" style="color:#1D9E75;">wa.me/' . $wa . '</a></p>';

        return $title . $msg . $footer;
    }
}

// ── Email: tour de la lista de interés abrió ────────────────────────────────

class TourOpenedEmail extends BaseEmail {

    protected function get_subject(): string {
        return $this->lang === 'en'
            ? '🎉 ' . $this->booking->tour_name . ' is open — complete your payment'
            : '🎉 ¡' . $this->booking->tour_name . ' ya está disponible — completá tu pago';
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        if ( $this->lang === 'en' ) {
            $intro = '<h1>Good news! 🎉</h1><p>Hi <strong>' . esc_html( $b->customer_name ) . '</strong>,<br>'
                   . 'You asked us to let you know when <strong>' . esc_html( $b->tour_name ) . '</strong> opened — and it just did. '
                   . 'Your spot for ' . esc_html( $this->fmt_date( $b->tour_date ) ) . ' is reserved — complete your payment to confirm it.</p>';
            $cta = 'Pay now';
        } else {
            $intro = '<h1>¡Buenas noticias! 🎉</h1><p>Hola <strong>' . esc_html( $b->customer_name ) . '</strong>,<br>'
                   . 'Nos pediste que te avisáramos cuando <strong>' . esc_html( $b->tour_name ) . '</strong> abriera — y ya está disponible. '
                   . 'Tu lugar para el ' . esc_html( $this->fmt_date( $b->tour_date ) ) . ' está reservado — completá el pago para confirmarlo.</p>';
            $cta = 'Pagar ahora';
        }

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        return $intro . $ref_box . '<p style="text-align:center;margin-top:24px;">'
             . '<a href="' . esc_url( $this->verify_url() ) . '" class="btn">' . esc_html( $cta ) . '</a>'
             . '</p>';
    }
}
