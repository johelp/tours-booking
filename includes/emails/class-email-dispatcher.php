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
        add_action( 'amir_booking_rescheduled', [ $this, 'send_reschedule_notice' ], 10, 1 );

        // ── Marketplace de proveedores (§ 11 CONTRIBUTING.md) ──────────────
        // Al entrar a pending_provider_approval, dos emails: al proveedor
        // (con los links de aprobar/rechazar) y al cliente (aviso interino,
        // sin nombrar al proveedor — su pago ya se procesó).
        add_action( 'amir_booking_pending_provider_approval', [ $this, 'send_provider_notice' ], 10, 1 );
        add_action( 'amir_booking_pending_provider_approval', [ $this, 'send_provider_pending_notice' ], 10, 1 );
        add_action( 'amir_send_provider_reminder_email', [ $this, 'send_provider_reminder' ], 10, 1 );
    }

    // ── Marketplace de proveedores ─────────────────────────────────────────

    /**
     * @return array{success:bool,error:string}
     */
    public function send_provider_notice( int $booking_id ): array {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking || empty( $booking->provider_email ) ) {
            return [ 'success' => false, 'error' => 'El proveedor no tiene email configurado.' ];
        }
        $mailer  = new ProviderNoticeEmail( $booking, $booking->provider_email );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    /** Recordatorio a las 24h (cron) — mismo email, mismo token sin regenerar. */
    public function send_provider_reminder( int $booking_id ): void {
        $this->send_provider_notice( $booking_id );
    }

    /** Aviso al cliente de que su pago ya se procesó y se está confirmando disponibilidad — sin nombrar al proveedor. */
    public function send_provider_pending_notice( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        ( new ProviderPendingNoticeEmail( $booking ) )->send();
    }

    // ── Lista de interés: link de pago real ───────────────────────────────
    // Se llama desde el admin (Lista de interés → "Publicar y notificar")
    // para cada reserva 'wishlist' de ese tour, una vez que pasa a
    // 'awaiting_payment'. $booking ya es una reserva real (booking_ref,
    // access_token, etc.) — el link de pago reutiliza verify_url() de
    // BaseEmail tal cual, sin armar nada a mano.

    /**
     * @return array{success:bool,error:string}
     */
    public function send_tour_opened_notice( object $booking ): array {
        $mailer  = new TourOpenedEmail( $booking );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    /**
     * Reserva manual cargada por el operador sin cobrar todavía (Amir
     * Booking → Reservas → Nueva reserva manual → "El cliente todavía no
     * pagó") — mismo mecanismo de link de pago que wishlist, pero el
     * booking ya viene armado por BookingManager::create_manual() (con
     * tour_name resuelto vía get_booking_with_tour()), así que se recibe
     * el objeto directo en vez de un booking_id + action hook.
     */
    public function send_payment_link_notice( object $booking ): array {
        $mailer  = new PaymentLinkEmail( $booking );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    // ── Reprogramación ────────────────────────────────────────────────────
    // Bug real encontrado probando en vivo: BookingManager::reschedule()
    // ya disparaba amir_booking_rescheduled, pero nada estaba enganchado a
    // ese hook — la reserva se reprogramaba en la base sin avisarle nunca
    // al cliente. Mismo patrón que send_confirmation()/send_cancellation().

    public function send_reschedule_notice( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        $mailer = new RescheduleEmail( $booking );
        $mailer->send();
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

    /** Público porque BookingManager::create_manual() también lo necesita
     * para armar el email de link de pago con el nombre del tour ya resuelto. */
    public function get_booking_with_tour( int $booking_id ): ?object {
        global $wpdb;
        // LEFT JOIN para que reservas sin schedule_id (=0) también se incluyan
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*,
                        t.name_es, t.name_en,
                        t.meeting_point_es, t.meeting_point_en,
                        t.meeting_lat, t.meeting_lng,
                        t.gallery_images,
                        t.provider_id,
                        p.business_name AS provider_business_name,
                        p.contact_name  AS provider_contact_name,
                        p.email         AS provider_email,
                        s.time_start, s.time_end, s.label_es, s.label_en
                 FROM {$wpdb->prefix}amir_bookings b
                 JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                 LEFT JOIN {$wpdb->prefix}amir_providers p ON p.id = t.provider_id
                 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
                 WHERE b.id = %d",
                $booking_id
            )
        );

        if ( $booking ) {
            // El nombre del tour en el idioma de la reserva — antes se
            // resolvía en SQL con un CASE WHEN que solo conocía es/en.
            $booking->tour_name = \AmirBooking\Core\Languages::tour_field( $booking, 'name', $booking->lang ?? 'es' );
        }

        return $booking;
    }
}

// ── Base Email ────────────────────────────────────────────────────────────────

abstract class BaseEmail {

    protected object $booking;
    protected string $lang;
    /** Destinatario alternativo al cliente (ej. el proveedor externo del marketplace) — null = customer_email de siempre. */
    protected ?string $to_override = null;
    private string $last_error = '';

    public function __construct( object $booking, ?string $to_override = null ) {
        $this->booking     = $booking;
        $this->lang        = $booking->lang ?? 'es';
        $this->to_override = $to_override;
    }

    abstract protected function get_subject(): string;
    abstract protected function get_body_content(): string;

    public function send(): bool {
        add_filter( 'wp_mail_content_type', [ $this, 'set_html' ] );

        $to = $this->to_override ?? $this->booking->customer_email;

        // Todo el contenido se arma dentro de run_in(): __()/_e() traducen
        // al idioma de LA RESERVA (no al locale del sitio) mientras dure el callback.
        $result = \AmirBooking\Core\Languages::run_in( $this->lang, function() use ( $to ) {
            return wp_mail(
                $to,
                $this->get_subject(),
                $this->wrap_template( $this->get_body_content() )
            );
        } );

        remove_filter( 'wp_mail_content_type', [ $this, 'set_html' ] );

        // Log si falla
        if ( ! $result ) {
            $this->last_error = '';
            if ( isset( $GLOBALS['phpmailer'] ) && $GLOBALS['phpmailer']->ErrorInfo ) {
                $this->last_error = $GLOBALS['phpmailer']->ErrorInfo;
            }
            error_log( sprintf(
                'Amir Booking: wp_mail falló para %s — Asunto: %s — Error: %s',
                $to,
                $this->get_subject(),
                $this->last_error
            ) );
        }

        return $result;
    }

    /** Detalle del error si send() devolvió false — vacío si nunca falló. */
    public function get_last_error(): string {
        return $this->last_error;
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
        $company_name = get_option( 'amir_company_name', 'TourFlow' );
        $site         = get_site_url();
        $wa           = get_option( 'amir_wa_phone', '' );
        $year         = date( 'Y' );

        // Prefijo de URL por idioma: Polylang/WPML sirven cada idioma bajo
        // /{lang}/ salvo el base (es), que no lleva prefijo.
        $tours_path   = $this->lang === \AmirBooking\Core\Languages::default_lang() ? '/tours/' : "/{$this->lang}/";
        // Sin número configurado (instalación nueva sin amir_wa_phone cargado
        // en Configuración), no mostrar un link roto — antes esto caía en un
        // número de WhatsApp real hardcodeado (el de Amir Adventours), lo que
        // filtraba mensajes de clientes de OTRAS instalaciones a ese número.
        $footer_links = '<a href="' . $site . $tours_path . '">Tours</a>'
            . ( $wa ? ' &nbsp;·&nbsp; <a href="https://wa.me/' . esc_attr( $wa ) . '">WhatsApp</a>' : '' );

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
        // Las recomendaciones las escribe el operador a mano por idioma
        // (opción separada, no un string fijo del código) — solo existen
        // para es/en hoy. Un idioma 3+ cae al listado en español, igual
        // que el contenido de tours sin traducir todavía en content_i18n.
        $is_en = $this->lang === 'en';
        $raw   = get_option( $is_en ? $option_en : $option_es, '' );
        $items = $raw
            ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) )
            : ( $is_en ? $defaults_en : $defaults_es );

        $style = 'font-size:14px;color:#3d3d3a;line-height:1.8;padding-left:20px;';
        $title = '📋 ' . __( 'Recomendaciones', 'amir-booking' );
        $lis   = '';
        foreach ( $items as $item ) {
            $lis .= '<li>' . esc_html( $item ) . '</li>';
        }
        return '<hr class="divider"><p><strong>' . $title . '</strong></p>'
             . '<ul style="' . $style . '">' . $lis . '</ul>';
    }

    // ── Helpers compartidos ───────────────────────────────────────────────

    /**
     * Diccionario corto de UI para emails/voucher — msgid en español,
     * traducido por __() al idioma activo dentro de Languages::run_in().
     * Llamadas a __() literales (no una variable) a propósito, para que
     * `wp i18n make-pot` las extraiga solo sin tener que listarlas a mano.
     */
    protected function t( string $key ): string {
        switch ( $key ) {
            case 'booking_ref':  return __( 'Número de reserva', 'amir-booking' );
            case 'tour':         return __( 'Tour', 'amir-booking' );
            case 'date':         return __( 'Fecha', 'amir-booking' );
            case 'time':         return __( 'Hora de salida', 'amir-booking' );
            case 'meeting':      return __( 'Punto de encuentro', 'amir-booking' );
            case 'people':       return __( 'Personas', 'amir-booking' );
            case 'total':        return __( 'Total pagado', 'amir-booking' );
            case 'adults':       return __( 'adultos', 'amir-booking' );
            case 'children':     return __( 'niños', 'amir-booking' );
            case 'babies':       return __( 'bebés', 'amir-booking' );
            case 'maps_link':    return __( 'Ver en mapa', 'amir-booking' );
            case 'download_pdf': return __( 'Descargar mi voucher PDF', 'amir-booking' );
            case 'add_cal':      return __( 'Agregar al calendario', 'amir-booking' );
            case 'wa_help':      return __( '¿Necesitas ayuda? Escríbenos por WhatsApp', 'amir-booking' );
            default:             return $key;
        }
    }

    protected function pax_summary(): string {
        $b    = $this->booking;
        $parts = [];
        if ( (int)$b->adults   > 0 ) $parts[] = $b->adults   . ' ' . $this->t('adults');
        if ( (int)$b->children > 0 ) $parts[] = $b->children . ' ' . $this->t('children');
        if ( (int)$b->babies   > 0 ) $parts[] = $b->babies   . ' ' . $this->t('babies');
        return implode( ', ', $parts );
    }

    /**
     * Fila extra en la tabla de la reserva con los servicios extra elegidos
     * (si hubo). Se busca acá y no en el JOIN de get_booking_with_tour()
     * porque es una relación 1-N — un JOIN duplicaría filas de la reserva.
     */
    protected function addons_row(): string {
        $booking_id = (int) ( $this->booking->id ?? 0 );
        if ( ! $booking_id ) {
            return '';
        }
        global $wpdb;
        $addons = $wpdb->get_results( $wpdb->prepare(
            "SELECT name_snapshot, qty, total_mxn FROM {$wpdb->prefix}amir_booking_addons WHERE booking_id = %d ORDER BY id",
            $booking_id
        ) ) ?? [];
        if ( empty( $addons ) ) {
            return '';
        }

        $lines = array_map( function ( $a ) {
            $label = esc_html( $a->name_snapshot ) . ( $a->qty > 1 ? ' × ' . (int) $a->qty : '' );
            return $label . ' — ' . \AmirBooking\Core\Currency::format( (float) $a->total_mxn );
        }, $addons );

        return '
          <tr>
            <td>' . esc_html__( 'Servicios extra', 'amir-booking' ) . '</td>
            <td>' . implode( '<br>', $lines ) . '</td>
          </tr>';
    }

    protected function fmt_date( string $date ): string {
        $months = [
            __( 'Enero', 'amir-booking' ), __( 'Febrero', 'amir-booking' ), __( 'Marzo', 'amir-booking' ),
            __( 'Abril', 'amir-booking' ), __( 'Mayo', 'amir-booking' ), __( 'Junio', 'amir-booking' ),
            __( 'Julio', 'amir-booking' ), __( 'Agosto', 'amir-booking' ), __( 'Septiembre', 'amir-booking' ),
            __( 'Octubre', 'amir-booking' ), __( 'Noviembre', 'amir-booking' ), __( 'Diciembre', 'amir-booking' ),
        ];
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
        $title = rawurlencode( $b->tour_name . ' — ' . get_option( 'amir_company_name', 'TourFlow' ) );
        $loc   = rawurlencode( $b->meeting_point_es ?? 'Bacalar, México' );
        return "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$start}/{$start}&location={$loc}";
    }

    protected function booking_info_table(): string {
        $b   = $this->booking;
        $mp  = \AmirBooking\Core\Languages::tour_field( $b, 'meeting_point', $this->lang );

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
          </tr>' . $this->addons_row() . '
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
        return sprintf( __( '✅ Tu reserva está confirmada — %s', 'amir-booking' ), $this->booking->booking_ref );
    }

    protected function get_body_content(): string {
        $b       = $this->booking;
        $siteUrl = get_site_url();
        $wa      = get_option( 'amir_wa_phone', '' );

        $intro = '<h1>' . __( '¡Tu reserva está confirmada! 🎉', 'amir-booking' ) . '</h1>'
               . '<p>' . sprintf( __( 'Hola <strong>%s</strong>,<br>Todo está listo para tu aventura en Bacalar. Aquí están los detalles de tu reserva:', 'amir-booking' ), esc_html( $b->customer_name ) ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html($b->booking_ref) . '</div>
        </div>';

        $recs_block = $this->recs_html(
            'amir_email_recs_es', 'amir_email_recs_en',
            [ 'Llega 10 minutos antes al punto de encuentro.', 'Usa ropa cómoda y protector solar biodegradable.', 'Trae agua y snacks ligeros.', 'Lleva tu documento de identidad.' ],
            [ 'Arrive 10 minutes before departure.', 'Wear comfortable clothes and biodegradable sunscreen.', 'Bring water and light snacks.', 'Carry a photo ID.' ]
        );

        $policy = '<div class="policy-box"><p><strong>' . __( 'Política de cancelación:', 'amir-booking' ) . '</strong></p>'
                . '<p>✓ ' . __( '7+ días antes: reembolso completo', 'amir-booking' ) . '</p>'
                . '<p>▸ ' . __( '3–6 días antes: reembolso del 50%', 'amir-booking' ) . '</p>'
                . '<p>✕ ' . __( 'Menos de 3 días: sin reembolso', 'amir-booking' ) . '</p></div>';

        $pdf_url = rest_url( 'amir/v1/bookings/' . rawurlencode($b->booking_ref) . '/pdf' )
                   . '?token=' . rawurlencode( $b->access_token ?? '' );

        $actions = '
        <p style="text-align:center;margin-top:24px;">
          <a href="' . esc_url($pdf_url) . '" class="btn">' . $this->t('download_pdf') . '</a>
          &nbsp;&nbsp;
          <a href="' . $this->calendar_url() . '" class="btn btn-outline">' . $this->t('add_cal') . '</a>
        </p>
        <p style="text-align:center;font-size:13px;margin-top:12px;">
          <a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . esc_html__( 'Ver estado de mi reserva', 'amir-booking' ) . '</a>
        </p>' . ( $wa ? '
        <p style="text-align:center;font-size:13px;color:#5a7068;margin-top:8px;">
          ' . $this->t('wa_help') . ': <a href="https://wa.me/' . esc_attr( $wa ) . '" style="color:#1D9E75;">wa.me/' . esc_html( $wa ) . '</a>
        </p>' : '' );

        $custom_note = '';
        if ( ! empty( $b->custom_email_note ) ) {
            $custom_note = '<div style="background:#e8f5e9;border-left:4px solid #1D9E75;padding:12px 16px;'
                         . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                         . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                         . '<strong>' . esc_html__( 'Nota del operador:', 'amir-booking' ) . '</strong><br>'
                         . nl2br( esc_html( $b->custom_email_note ) )
                         . '</p></div>';
        }

        return $intro
            . $ref_box
            . $this->booking_info_table()
            . $custom_note
            . $recs_block
            . $policy
            . $actions;
    }
}

// ── Email recordatorio ────────────────────────────────────────────────────────

class ReminderEmail extends BaseEmail {

    protected function get_subject(): string {
        return sprintf( __( '⏰ ¡Tu tour es mañana! — %s', 'amir-booking' ), $this->booking->tour_name );
    }

    protected function get_body_content(): string {
        $b  = $this->booking;
        $wa = get_option( 'amir_wa_phone', '' );

        $intro = '<h1>' . __( '¡Tu aventura es mañana! ⛵', 'amir-booking' ) . '</h1>'
               . '<p>' . sprintf( __( 'Hola <strong>%s</strong>,<br>Un recordatorio de tu reserva para mañana:', 'amir-booking' ), esc_html( $b->customer_name ) ) . '</p>';

        $recs_block = $this->recs_html(
            'amir_email_recs_es', 'amir_email_recs_en',
            [ 'Ropa cómoda y traje de baño', 'Protector solar biodegradable (obligatorio en la laguna)', 'Agua y snacks ligeros', 'Documento de identidad', 'Cámara o celular en bolsa impermeable' ],
            [ 'Comfortable clothes and swimsuit', 'Biodegradable sunscreen (required on the lagoon)', 'Water and light snacks', 'Photo ID', 'Camera or phone in a waterproof bag' ]
        );

        $footer_wa = $wa ? '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">' . $this->t('wa_help') . ': <a href="https://wa.me/' . esc_attr( $wa ) . '" style="color:#1D9E75;">wa.me/' . esc_html( $wa ) . '</a></p>' : '';

        return $intro
            . $this->booking_info_table()
            . $recs_block
            . $footer_wa;
    }
}

// ── Email solicitud de reseña ─────────────────────────────────────────────────

class ReviewEmail extends BaseEmail {

    protected function get_subject(): string {
        return sprintf( __( '⭐ ¿Cómo fue tu experiencia? — %s', 'amir-booking' ), get_option( 'amir_company_name', 'TourFlow' ) );
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        $tripadvisor_url = get_option('amir_tripadvisor_review_url',
            'https://www.tripadvisor.com/Attraction_Review-g2369583-d23441870-Reviews-Amir_AdvenTours-Bacalar_Yucatan_Peninsula.html');
        $google_url = get_option('amir_google_review_url', '#');

        return '<h1>' . __( '¿Disfrutaste tu aventura en Bacalar? 🌊', 'amir-booking' ) . '</h1>
        <p>' . sprintf( __( 'Hola <strong>%s</strong>,<br>Esperamos que hayas tenido una experiencia increíble con nosotros en <em>%s</em>.', 'amir-booking' ), esc_html( $b->customer_name ), esc_html( $b->tour_name ) ) . '</p>
        <p>' . __( 'Tu opinión nos ayuda a seguir mejorando y a que más viajeros descubran la magia de Bacalar. Si tienes un minuto, nos encantaría que compartieras tu experiencia:', 'amir-booking' ) . '</p>
        <p style="text-align:center;margin:24px 0;">
          <a href="' . $tripadvisor_url . '" class="btn">⭐ ' . __( 'Reseña en TripAdvisor', 'amir-booking' ) . '</a>
          &nbsp;&nbsp;
          <a href="' . $google_url . '" class="btn btn-outline">⭐ ' . __( 'Reseña en Google', 'amir-booking' ) . '</a>
        </p>
        <p style="font-size:13px;color:#5a7068;text-align:center;">' . __( '¡Gracias por elegirnos! Esperamos verte de nuevo pronto. 🐊', 'amir-booking' ) . '</p>';
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
        return sprintf( __( 'Cancelación de reserva — %s', 'amir-booking' ), $this->booking->booking_ref );
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        $is_operator = in_array( $this->reason_type, [ 'weather', 'min_pax' ], true );
        $is_provider = in_array( $this->reason_type, [ 'provider_rejected', 'provider_expired' ], true );

        if ( $is_operator ) {
            $title = '<h1>' . ( $this->reason_type === 'weather'
                ? __( 'Tour cancelado por condiciones climáticas ⛈', 'amir-booking' )
                : __( 'Tour cancelado — mínimo de pasajeros no alcanzado', 'amir-booking' ) ) . '</h1>';
            $msg = '<p>' . sprintf(
                __( 'Hola <strong>%s</strong>,<br>Lamentamos informarte que tu reserva <strong>%s</strong> ha sido cancelada. Se ha procesado un <strong>reembolso completo</strong> que aparecerá en tu cuenta en 3–5 días hábiles.', 'amir-booking' ),
                esc_html( $b->customer_name ), esc_html( $b->booking_ref )
            ) . '</p>';
        } elseif ( $is_provider ) {
            // Reserva de un tour operado por un proveedor externo (marketplace,
            // § 11 CONTRIBUTING.md) — el proveedor rechazó, o venció el plazo de
            // respuesta sin contestar. En ambos casos, reembolso 100% (no es
            // responsabilidad del cliente). No se nombra al proveedor.
            $title = '<h1>' . __( 'No pudimos confirmar tu reserva', 'amir-booking' ) . '</h1>';
            if ( $this->reason_type === 'provider_expired' ) {
                $msg = '<p>' . sprintf(
                    __( 'Hola <strong>%1$s</strong>,<br>El operador local no respondió a tiempo para confirmar tu reserva <strong>%2$s</strong>. Se ha procesado un <strong>reembolso completo</strong> que aparecerá en tu cuenta en 3–5 días hábiles.', 'amir-booking' ),
                    esc_html( $b->customer_name ), esc_html( $b->booking_ref )
                ) . '</p>';
            } else {
                $reason_note = ! empty( $b->provider_reject_reason )
                    ? ' ' . sprintf( __( 'El operador indicó: "%s".', 'amir-booking' ), esc_html( $b->provider_reject_reason ) )
                    : '';
                $msg = '<p>' . sprintf(
                    __( 'Hola <strong>%1$s</strong>,<br>El operador local no pudo confirmar tu reserva <strong>%2$s</strong>.', 'amir-booking' ),
                    esc_html( $b->customer_name ), esc_html( $b->booking_ref )
                ) . $reason_note . ' ' . __( 'Se ha procesado un <strong>reembolso completo</strong> que aparecerá en tu cuenta en 3–5 días hábiles.', 'amir-booking' ) . '</p>';
            }
        } else {
            $title = '<h1>' . __( 'Reserva cancelada', 'amir-booking' ) . '</h1>';
            $policy = (int) $b->cancellation_policy_pct;
            $refund_msg = $policy === 0
                ? __( 'Se ha procesado un reembolso completo que aparecerá en tu cuenta en 3–5 días hábiles.', 'amir-booking' )
                : ( $policy === 50
                    ? sprintf( __( 'Se ha procesado un reembolso del 50%% por %s.', 'amir-booking' ), \AmirBooking\Core\Currency::format( (float) $b->refund_amount_mxn ) )
                    : __( 'De acuerdo con nuestra política, no aplica reembolso para cancelaciones dentro de los 2 días previos al tour.', 'amir-booking' ) );
            $msg = '<p>' . sprintf(
                __( 'Hola <strong>%s</strong>,<br>Tu reserva <strong>%s</strong> ha sido cancelada. %s', 'amir-booking' ),
                esc_html( $b->customer_name ), esc_html( $b->booking_ref ), $refund_msg
            ) . '</p>';
        }

        $wa     = get_option( 'amir_wa_phone', '' );
        $footer = $wa ? '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">' . $this->t('wa_help') . ': <a href="https://wa.me/' . esc_attr( $wa ) . '" style="color:#1D9E75;">wa.me/' . esc_html( $wa ) . '</a></p>' : '';

        return $title . $msg . $footer;
    }
}

// ── Email: tour de la lista de interés abrió ────────────────────────────────

class TourOpenedEmail extends BaseEmail {

    /**
     * Sin emoji ni "completá tu pago" en el asunto — la combinación
     * entusiasmo + pedido de pago + link es un patrón clásico que Gmail
     * suele filtrar como spam en plantillas nuevas sin historial de envío,
     * aunque wp_mail() devuelva éxito (confirmado: el mismo mecanismo que
     * confirmación/reprogramación, que sí llegan, con este único cambio
     * de contenido). El CTA de pago queda solo en el cuerpo, como en el
     * resto de los emails transaccionales del sistema.
     */
    protected function get_subject(): string {
        return sprintf( __( '%s ya tiene fecha confirmada', 'amir-booking' ), $this->booking->tour_name );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . __( '¡Buenas noticias!', 'amir-booking' ) . '</h1>'
               . '<p>' . sprintf(
                   __( 'Hola <strong>%1$s</strong>,<br>Nos pediste que te avisáramos cuando <strong>%2$s</strong> abriera — y ya está disponible. Tu lugar para el %3$s está reservado — completá el pago para confirmarlo.', 'amir-booking' ),
                   esc_html( $b->customer_name ), esc_html( $b->tour_name ), esc_html( $this->fmt_date( $b->tour_date ) )
               ) . '</p>';
        $cta = __( 'Pagar ahora', 'amir-booking' );

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        return $intro . $ref_box . '<p style="text-align:center;margin-top:24px;">'
             . '<a href="' . esc_url( $this->verify_url() ) . '" class="btn">' . esc_html( $cta ) . '</a>'
             . '</p>';
    }
}

// ── Email: reserva manual cargada por el operador, pendiente de pago ───────

class PaymentLinkEmail extends BaseEmail {

    protected function get_subject(): string {
        return sprintf( __( 'Completá el pago de tu reserva — %s', 'amir-booking' ), $this->booking->booking_ref );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . __( 'Tu reserva está lista 🎉', 'amir-booking' ) . '</h1>'
               . '<p>' . sprintf(
                   __( 'Hola <strong>%1$s</strong>,<br>Ya armamos tu reserva para <strong>%2$s</strong> el %3$s — solo falta completar el pago para confirmarla.', 'amir-booking' ),
                   esc_html( $b->customer_name ), esc_html( $b->tour_name ), esc_html( $this->fmt_date( $b->tour_date ) )
               ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        $custom_note = '';
        if ( ! empty( $b->custom_email_note ) ) {
            $custom_note = '<div style="background:#e8f5e9;border-left:4px solid #1D9E75;padding:12px 16px;'
                         . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                         . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                         . '<strong>' . esc_html__( 'Nota del operador:', 'amir-booking' ) . '</strong><br>'
                         . nl2br( esc_html( $b->custom_email_note ) )
                         . '</p></div>';
        }

        return $intro . $ref_box . $custom_note . '<p style="text-align:center;margin-top:24px;">'
             . '<a href="' . esc_url( $this->verify_url() ) . '" class="btn">' . esc_html__( 'Pagar ahora', 'amir-booking' ) . '</a>'
             . '</p>';
    }
}

// ── Email: reserva reprogramada ─────────────────────────────────────────────
// Bug real encontrado probando en vivo: reprogramar desde el admin actualizaba
// la reserva pero nunca avisaba al cliente — ver send_reschedule_notice().

class RescheduleEmail extends BaseEmail {

    protected function get_subject(): string {
        return sprintf( __( '🔄 Tu reserva fue reprogramada — %s', 'amir-booking' ), $this->booking->booking_ref );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . __( 'Tu reserva cambió de fecha', 'amir-booking' ) . '</h1>'
               . '<p>' . sprintf(
                   __( 'Hola <strong>%1$s</strong>,<br>Tu reserva <strong>%2$s</strong> fue reprogramada. Estos son los nuevos detalles:', 'amir-booking' ),
                   esc_html( $b->customer_name ), esc_html( $b->booking_ref )
               ) . '</p>';

        $wa     = get_option( 'amir_wa_phone', '' );
        $footer = '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">'
                . __( '¿La nueva fecha no te sirve? Escribinos y lo resolvemos.', 'amir-booking' )
                . ' ' . $this->t('wa_help') . ': <a href="https://wa.me/' . $wa . '" style="color:#1D9E75;">wa.me/' . $wa . '</a></p>';

        return $intro . $this->booking_info_table() . $footer;
    }
}

// ── Email: proveedor externo, nueva reserva a confirmar ──────────────────────
// Marketplace de proveedores (§ 11 CONTRIBUTING.md). Se envía al proveedor
// (no al cliente — usa $to_override) con los datos de contacto completos del
// cliente y los links de Aprobar/Rechazar tokenizados. Reusado tal cual para
// el recordatorio a las 24h (mismo token, no se regenera).

class ProviderNoticeEmail extends BaseEmail {

    protected function get_subject(): string {
        return sprintf( __( 'Nueva reserva por confirmar — %s', 'amir-booking' ), $this->booking->booking_ref );
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        $greeting_name = $b->provider_contact_name ?: $b->provider_business_name;

        $intro = '<h1>' . __( 'Nueva reserva para confirmar disponibilidad', 'amir-booking' ) . '</h1>'
               . '<p>' . sprintf(
                   __( 'Hola %1$s,<br>Recibiste una nueva reserva desde TourFlow para <strong>%2$s</strong>. Por favor confirmá si tenés disponibilidad.', 'amir-booking' ),
                   esc_html( $greeting_name ), esc_html( $b->tour_name )
               ) . '</p>';

        $special = '';
        if ( ! empty( $b->special_requests ) ) {
            $special = '<tr><td>' . esc_html__( 'Pedidos especiales', 'amir-booking' ) . '</td><td>' . nl2br( esc_html( $b->special_requests ) ) . '</td></tr>';
        }

        $details = '
        <table class="info-table">
          <tr><td>' . esc_html__( 'Referencia', 'amir-booking' ) . '</td><td>' . esc_html( $b->booking_ref ) . '</td></tr>
          <tr><td>' . $this->t('date') . '</td><td>' . $this->fmt_date( $b->tour_date ) . '</td></tr>
          <tr><td>' . $this->t('time') . '</td><td>' . ( $b->time_start ? $this->fmt_time( $b->time_start ) : '—' ) . '</td></tr>
          <tr><td>' . $this->t('people') . '</td><td>' . $this->pax_summary() . '</td></tr>
          <tr><td>' . esc_html__( 'Cliente', 'amir-booking' ) . '</td><td>' . esc_html( $b->customer_name ) . '</td></tr>
          <tr><td>' . esc_html__( 'Contacto', 'amir-booking' ) . '</td><td>' . esc_html( $b->customer_email ) . ( $b->customer_phone ? ' / ' . esc_html( $b->customer_phone ) : '' ) . '</td></tr>'
          . $special . '
        </table>';

        $response_hours = (int) get_option( 'amir_provider_response_hours', 48 );
        $deadline_note = '<p style="font-size:13px;color:#5a7068;">' . sprintf(
            __( 'Si no respondés dentro de %d horas, la reserva se cancelará automáticamente y se reembolsará al cliente.', 'amir-booking' ),
            $response_hours
        ) . '</p>';

        $actions = '<p style="text-align:center;margin-top:24px;">'
            . '<a href="' . esc_url( $this->provider_action_url( 'approve' ) ) . '" class="btn">✅ ' . esc_html__( 'Aprobar', 'amir-booking' ) . '</a>'
            . '&nbsp;&nbsp;'
            . '<a href="' . esc_url( $this->provider_action_url( 'reject' ) ) . '" class="btn btn-outline">❌ ' . esc_html__( 'Rechazar', 'amir-booking' ) . '</a>'
            . '</p>';

        return $intro . $details . $deadline_note . $actions;
    }

    /**
     * Link a la pantalla de confirmación en /proveedor-reserva/ (no ejecuta
     * la acción directo — evita que scanners de email/antivirus corporativos
     * disparen la aprobación/rechazo por prefetch). Ver Shortcodes::provider_action().
     */
    private function provider_action_url( string $do ): string {
        $page_id = (int) get_option( 'amir_provider_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : false;
        $base    = $base ?: ( get_site_url() . '/proveedor-reserva/' );

        return add_query_arg(
            [
                'ref'   => $this->booking->booking_ref,
                'token' => $this->booking->provider_response_token ?? '',
                'do'    => $do,
            ],
            $base
        );
    }
}

// ── Email: aviso interino al cliente mientras se confirma con el proveedor ──
// Su pago ya se procesó — este correo evita que se pregunte por qué no
// recibió la confirmación habitual. No nombra al proveedor (aviso discreto,
// mismo criterio que el badge de la ficha del tour).

class ProviderPendingNoticeEmail extends BaseEmail {

    protected function get_subject(): string {
        return sprintf( __( 'Estamos confirmando tu reserva — %s', 'amir-booking' ), $this->booking->booking_ref );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . __( 'Tu reserva fue recibida', 'amir-booking' ) . '</h1>'
               . '<p>' . sprintf(
                   __( 'Hola <strong>%1$s</strong>,<br>Tu pago se procesó correctamente. Estamos confirmando disponibilidad con el operador local para tu tour del %2$s — te avisamos en cuanto quede confirmada.', 'amir-booking' ),
                   esc_html( $b->customer_name ), esc_html( $this->fmt_date( $b->tour_date ) )
               ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        $footer = '<p style="text-align:center;font-size:13px;margin-top:12px;">'
            . '<a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . esc_html__( 'Ver estado de mi reserva', 'amir-booking' ) . '</a>'
            . '</p>';

        return $intro . $ref_box . $footer;
    }
}
