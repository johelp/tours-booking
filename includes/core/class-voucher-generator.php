<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Generador de voucher PDF con código QR.
 *
 * Usa TCPDF (más común en hosting compartido) con fallback a HTML estático.
 * El QR se genera con php-qrcode (bundleado en el plugin) o Google Charts API.
 *
 * Uso:
 *   $gen = new VoucherGenerator();
 *   $path = $gen->generate( $booking_id );   // devuelve la ruta del archivo
 *   $gen->stream( $booking_id );             // envía el PDF al navegador
 */
class VoucherGenerator {

    private string $upload_dir;
    private string $upload_url;

    public function __construct() {
        $dirs             = wp_upload_dir();
        $this->upload_dir = $dirs['basedir'] . '/amir-booking/vouchers/';
        $this->upload_url = $dirs['baseurl'] . '/amir-booking/vouchers/';
        wp_mkdir_p( $this->upload_dir );
        $this->protect_directory();
    }

    /**
     * Crea un .htaccess que bloquea el acceso HTTP directo al directorio de vouchers.
     * Los archivos solo son accesibles a través del endpoint REST autenticado.
     */
    private function protect_directory(): void {
        $htaccess = $this->upload_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "deny from all\n" );
        }
    }

    // ── API pública ───────────────────────────────────────────────────────

    /**
     * Genera el PDF del voucher y lo guarda en disco.
     * Retorna la ruta al archivo generado.
     */
    public function generate( int $booking_id ): string {
        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            return '';
        }

        $filename = 'voucher-' . sanitize_file_name($booking->booking_ref) . '.pdf';
        $filepath = $this->upload_dir . $filename;

        // Si ya existe y la reserva sigue confirmada, devolver el existente
        if ( file_exists($filepath) && $booking->status === 'confirmed' ) {
            return $filepath;
        }

        // Intentar generar con TCPDF
        if ( $this->tcpdf_available() ) {
            return $this->generate_with_tcpdf( $booking, $filepath );
        }

        // Fallback: generar HTML y convertir (si DOMPDF está disponible)
        if ( $this->dompdf_available() ) {
            return $this->generate_with_dompdf( $booking, $filepath );
        }

        // Último fallback: guardar como HTML descargable
        return $this->generate_html_fallback( $booking, $filepath );
    }

    /**
     * Transmite el PDF directamente al navegador (para descarga).
     */
    public function stream( int $booking_id ): void {
        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            wp_die('Reserva no encontrada.');
        }

        $filepath = $this->generate( $booking_id );

        if ( ! $filepath || ! file_exists($filepath) ) {
            // Fallback: enviar HTML como PDF
            $this->stream_html_voucher( $booking );
            return;
        }

        $mime = ( substr( $filepath, -4 ) === '.pdf' ) ? 'application/pdf' : 'text/html';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="voucher-' . sanitize_file_name($booking->booking_ref) . '.pdf"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }

    /**
     * Genera el QR de verificación y guarda en disco.
     * Retorna la ruta del archivo PNG.
     */
    public function generate_qr( int $booking_id, string $booking_ref ): string {
        $verify_url = add_query_arg(
            [ 'ref' => $booking_ref, 'token' => $this->get_access_token( $booking_id ) ],
            $this->verify_page_url()
        );
        $qr_path    = $this->upload_dir . 'qr-' . sanitize_file_name($booking_ref) . '.png';

        if ( file_exists($qr_path) ) {
            return $qr_path;
        }

        // 1. endroid/qr-code (instalado vía Composer)
        if ( class_exists( '\Endroid\QrCode\QrCode' ) ) {
            try {
                $qr     = \Endroid\QrCode\QrCode::create( $verify_url )
                    ->setSize( 300 )
                    ->setMargin( 10 );
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $result = $writer->write( $qr );
                $result->saveToFile( $qr_path );
                if ( file_exists( $qr_path ) ) {
                    return $qr_path;
                }
            } catch ( \Throwable $e ) {
                error_log( 'Amir QR endroid error: ' . $e->getMessage() );
            }
        }

        // 2. api.qrserver.com — fallback gratuito, sin dependencias
        $api_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode( $verify_url ) . '&format=png&margin=10';
        $response = wp_remote_get( $api_url, [ 'timeout' => 15 ] );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $body = wp_remote_retrieve_body( $response );
            if ( strlen( $body ) > 500 ) {
                file_put_contents( $qr_path, $body );
                if ( file_exists( $qr_path ) ) {
                    return $qr_path;
                }
            }
        }

        return '';
    }

    // ── Generación con TCPDF ──────────────────────────────────────────────

    private function generate_with_tcpdf( object $booking, string $filepath ): string {
        // TCPDF se carga vía Composer autoloader (vendor/autoload.php).
        // Si por algún motivo no está en memoria, lo cargamos explícitamente.
        if ( ! class_exists( '\TCPDF' ) ) {
            $lib = AMIR_PLUGIN_DIR . 'vendor/tecnickcom/tcpdf/tcpdf.php';
            if ( file_exists( $lib ) ) {
                require_once $lib;
            } else {
                return '';
            }
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator( get_option( 'amir_company_name', 'TourFlow' ) );
        $pdf->SetAuthor( get_option( 'amir_company_name', 'TourFlow' ) );
        $pdf->SetTitle('Voucher ' . $booking->booking_ref);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // QR ya embebido como data URI dentro del HTML; no se usa $pdf->Image() por separado
        $html = $this->build_voucher_html($booking, true);
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Output($filepath, 'F');
        return file_exists($filepath) ? $filepath : '';
    }

    // ── Generación con DOMPDF ─────────────────────────────────────────────

    private function generate_with_dompdf( object $booking, string $filepath ): string {
        $autoload = AMIR_PLUGIN_DIR . 'vendor/dompdf/autoload.inc.php';
        require_once $autoload;

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml( $this->get_full_html_document($booking) );
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        file_put_contents($filepath, $dompdf->output());
        return file_exists($filepath) ? $filepath : '';
    }

    // ── Fallback HTML ─────────────────────────────────────────────────────

    /**
     * Devuelve el HTML del voucher para un booking_id dado.
     * Método público para usar desde el controller.
     */
    public function get_voucher_html( int $booking_id ): string {
        $booking = $this->get_booking( $booking_id );
        if ( ! $booking ) {
            return '<p>Reserva no encontrada.</p>';
        }
        return $this->get_full_html_document( $booking );
    }

    private function generate_html_fallback( object $booking, string $filepath ): string {
        $html_path = str_replace('.pdf', '.html', $filepath);
        file_put_contents($html_path, $this->get_full_html_document($booking));
        return $html_path;
    }

    private function stream_html_voucher( object $booking ): void {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="voucher-'.$booking->booking_ref.'.html"');
        echo $this->get_full_html_document($booking);
        exit;
    }

    // ── Template del voucher ──────────────────────────────────────────────

    /**
     * HTML del voucher. Usado tanto para DOMPDF como para el fallback.
     * Diseñado para imprimirse bien en A4.
     */
    private function get_full_html_document( object $b ): string {
        $lang = isset( $b->lang ) ? $b->lang : 'es';
        return Languages::run_in( $lang, fn() => $this->render_html_document( $b, $lang ) );
    }

    private function render_html_document( object $b, string $lang ): string {
        $qr_path      = $this->generate_qr((int)$b->id, $b->booking_ref);
        $qr_src       = $qr_path ? $this->path_to_data_uri($qr_path) : '';
        $logo_url_opt = get_option( 'amir_brand_logo_url', '' );
        if ( $logo_url_opt ) {
            $logo_src = $logo_url_opt; // use URL directly for HTML voucher
        } else {
            $logo_path = AMIR_PLUGIN_DIR . 'assets/images/logo-email.png';
            $logo_src  = file_exists($logo_path) ? $this->path_to_data_uri($logo_path) : '';
        }
        $brand_color  = get_option( 'amir_brand_color', '#1D9E75' );
        $company_name = get_option( 'amir_company_name', 'TourFlow' );

        $is_en       = $lang === 'en';
        $tagline_opt = get_option( $is_en ? 'amir_company_tagline_en' : 'amir_company_tagline_es', '' );
        $tagline     = $tagline_opt ?: __( 'Experiencias en Bacalar · Quintana Roo, México', 'amir-booking' );
        $months      = [
            __( 'Enero', 'amir-booking' ), __( 'Febrero', 'amir-booking' ), __( 'Marzo', 'amir-booking' ),
            __( 'Abril', 'amir-booking' ), __( 'Mayo', 'amir-booking' ), __( 'Junio', 'amir-booking' ),
            __( 'Julio', 'amir-booking' ), __( 'Agosto', 'amir-booking' ), __( 'Septiembre', 'amir-booking' ),
            __( 'Octubre', 'amir-booking' ), __( 'Noviembre', 'amir-booking' ), __( 'Diciembre', 'amir-booking' ),
        ];
        [$y,$m,$d]  = explode('-', $b->tour_date);
        $date_fmt   = (int)$d . ' ' . $months[(int)$m-1] . ' ' . $y;

        $fmtTime = function(string $t): string {
            [$h,$mi] = explode(':', $t);
            $h=(int)$h;
            return ($h>12?$h-12:($h?:12)).':'.$mi.($h>=12?' PM':' AM');
        };

        $pax_es  = [];
        if ($b->adults)   $pax_es[] = $b->adults.' '.__( 'adultos', 'amir-booking' );
        if ($b->children) $pax_es[] = $b->children.' '.__( 'niños', 'amir-booking' );
        if ($b->babies)   $pax_es[] = $b->babies.' '.__( 'bebés', 'amir-booking' );
        $pax_str = implode(', ', $pax_es);

        $meeting  = Languages::tour_field( $b, 'meeting_point', $lang );
        $tour_name = Languages::tour_field( $b, 'name', $lang );
        $maps_url = $b->meeting_lat
            ? "https://maps.google.com/?q={$b->meeting_lat},{$b->meeting_lng}"
            : 'https://maps.google.com/?q=Bacalar,Quintana+Roo,Mexico';

        $wa    = get_option('amir_wa_phone','');
        $site  = get_site_url();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:Arial,Helvetica,sans-serif; color:#1a2e24; font-size:13px; line-height:1.5; background:#fff; }
  .page { max-width:595px; margin:0 auto; padding:30px 32px; }

  .header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:16px; border-bottom:3px solid <?php echo esc_attr($brand_color); ?>; margin-bottom:20px; }
  .header-left h1 { font-size:22px; font-weight:800; color:<?php echo esc_attr($brand_color); ?>; }
  .header-left p  { font-size:11px; color:#5a7068; }
  .qr-block { text-align:right; }
  .qr-block img { width:80px; height:80px; border:1px solid #e1f5ee; padding:4px; border-radius:4px; }
  .qr-block .qr-label { font-size:9px; color:#5a7068; margin-top:3px; }

  .ref-box { background:<?php echo esc_attr($brand_color); ?>; color:#fff; border-radius:8px; padding:14px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; }
  .ref-box .ref-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; opacity:.85; }
  .ref-box .ref-value { font-size:24px; font-weight:800; letter-spacing:2px; }
  .ref-box .ref-date  { font-size:12px; opacity:.85; text-align:right; }

  .section { margin-bottom:18px; }
  .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:<?php echo esc_attr($brand_color); ?>; border-bottom:1px solid #e1f5ee; padding-bottom:5px; margin-bottom:10px; }
  .info-row { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f5f5f5; font-size:12px; }
  .info-row:last-child { border-bottom:none; }
  .info-row .label { color:#5a7068; }
  .info-row .value { font-weight:600; text-align:right; max-width:60%; }

  .recs-list { list-style:none; }
  .recs-list li { padding:4px 0; font-size:12px; color:#3d3d3a; }
  .recs-list li::before { content:"✓ "; color:<?php echo esc_attr($brand_color); ?>; font-weight:700; }

  .policy-box { background:#fffbeb; border-left:3px solid #BA7517; padding:10px 14px; border-radius:0 6px 6px 0; }
  .policy-box p { font-size:11px; color:#78350f; padding:2px 0; }

  .footer { border-top:2px solid <?php echo esc_attr($brand_color); ?>; margin-top:24px; padding-top:14px; display:flex; justify-content:space-between; align-items:center; }
  .footer p { font-size:10px; color:#5a7068; }
  .footer .wa { font-size:11px; font-weight:700; color:<?php echo esc_attr($brand_color); ?>; }

  .status-ok { display:inline-block; background:#e1f5ee; color:#0F6E56; font-size:10px; font-weight:700; padding:2px 8px; border-radius:10px; }

  @media print {
    body { background:white; }
    .page { padding:20px; max-width:100%; }
    a { color:inherit !important; }
    .no-print { display:none !important; }
  }
</style>
</head>
<body>
<!-- Barra de impresión (no se imprime) -->
<div class="no-print" style="background:<?php echo esc_attr($brand_color); ?>;color:#fff;text-align:center;padding:10px 16px;font-family:Arial,sans-serif;font-size:13px;position:sticky;top:0;z-index:99;">
  📄 <?php esc_html_e( 'Para guardar como PDF, haz clic en', 'amir-booking' ); ?>
  <button onclick="window.print()" style="background:#fff;color:<?php echo esc_attr($brand_color); ?>;border:none;border-radius:5px;padding:5px 14px;font-weight:700;cursor:pointer;margin:0 8px;">
    🖨 <?php esc_html_e( 'Imprimir / Guardar PDF', 'amir-booking' ); ?>
  </button>
  <?php esc_html_e( 'y selecciona "Guardar como PDF"', 'amir-booking' ); ?>
</div>
<div class="page">

  <!-- Header -->
  <div class="header">
    <div class="header-left">
      <?php if ($logo_src) : ?>
        <img src="<?php echo esc_attr($logo_src); ?>" alt="<?php echo esc_attr($company_name); ?>" style="height:40px;margin-bottom:6px;" />
      <?php else : ?>
        <h1><?php echo esc_html($company_name); ?></h1>
      <?php endif; ?>
      <p><?php echo esc_html($tagline); ?></p>
      <span class="status-ok">✓ <?php esc_html_e( 'RESERVA CONFIRMADA', 'amir-booking' ); ?></span>
    </div>
    <div class="qr-block">
      <?php if ($qr_src) : ?>
        <img src="<?php echo $qr_src; ?>" alt="QR" />
        <div class="qr-label"><?php esc_html_e( 'Escanea para verificar', 'amir-booking' ); ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Número de reserva -->
  <div class="ref-box">
    <div>
      <div class="ref-label"><?php esc_html_e( 'Número de reserva', 'amir-booking' ); ?></div>
      <div class="ref-value"><?php echo esc_html($b->booking_ref); ?></div>
    </div>
    <div class="ref-date">
      <?php esc_html_e( 'Reservado el', 'amir-booking' ); ?><br>
      <?php echo date('d/m/Y', strtotime($b->created_at)); ?>
    </div>
  </div>

  <!-- Detalle del tour -->
  <div class="section">
    <div class="section-title"><?php esc_html_e( 'Detalle del tour', 'amir-booking' ); ?></div>
    <div class="info-row"><span class="label"><?php esc_html_e( 'Tour', 'amir-booking' ); ?></span><span class="value"><?php echo esc_html($tour_name); ?></span></div>
    <div class="info-row"><span class="label"><?php esc_html_e( 'Fecha', 'amir-booking' ); ?></span><span class="value"><?php echo $date_fmt; ?></span></div>
    <div class="info-row"><span class="label"><?php esc_html_e( 'Hora de salida', 'amir-booking' ); ?></span><span class="value"><?php echo $fmtTime($b->time_start??'00:00'); ?></span></div>
    <div class="info-row"><span class="label"><?php esc_html_e( 'Personas', 'amir-booking' ); ?></span><span class="value"><?php echo esc_html($pax_str); ?></span></div>
    <div class="info-row"><span class="label"><?php esc_html_e( 'Total pagado', 'amir-booking' ); ?></span><span class="value" style="font-size:14px;color:<?php echo esc_attr($brand_color); ?>;"><?php echo Currency::format((float)$b->total_mxn); ?></span></div>
  </div>

  <!-- Punto de encuentro -->
  <div class="section">
    <div class="section-title"><?php esc_html_e( 'Punto de encuentro', 'amir-booking' ); ?></div>
    <p style="font-size:12px;color:#3d3d3a;margin-bottom:6px;"><?php echo esc_html($meeting ?? ''); ?></p>
    <p style="font-size:11px;color:<?php echo esc_attr($brand_color); ?>;">📍 <a href="<?php echo esc_url($maps_url); ?>" style="color:<?php echo esc_attr($brand_color); ?>;"><?php esc_html_e( 'Ver en Google Maps', 'amir-booking' ); ?> → <?php echo $maps_url; ?></a></p>
  </div>

  <!-- Datos del pasajero -->
  <div class="section">
    <div class="section-title"><?php esc_html_e( 'Pasajero', 'amir-booking' ); ?></div>
    <div class="info-row"><span class="label"><?php esc_html_e( 'Nombre', 'amir-booking' ); ?></span><span class="value"><?php echo esc_html($b->customer_name); ?></span></div>
    <div class="info-row"><span class="label">Email</span><span class="value"><?php echo esc_html($b->customer_email); ?></span></div>
    <?php if ($b->customer_phone) : ?>
    <div class="info-row"><span class="label">WhatsApp</span><span class="value"><?php echo esc_html($b->customer_phone); ?></span></div>
    <?php endif; ?>
  </div>

  <!-- Recomendaciones -->
  <div class="section">
    <div class="section-title"><?php esc_html_e( 'Recuerda llevar', 'amir-booking' ); ?></div>
    <ul class="recs-list">
      <?php
      // Las recomendaciones las escribe el operador por idioma (opción,
      // no string fijo) — solo existen es/en hoy, un idioma 3+ cae al
      // listado en español (mismo criterio que el resto del contenido
      // de operador sin traducir todavía).
      $recs_raw_v = get_option( $is_en ? 'amir_voucher_recs_en' : 'amir_voucher_recs_es', '' );
      $recs_items_v = $recs_raw_v
          ? array_filter( array_map( 'trim', explode( "\n", $recs_raw_v ) ) )
          : ( $is_en
              ? array( 'Comfortable clothes and swimsuit', 'Biodegradable sunscreen (required on the lagoon)', 'Water and light snacks', 'Photo ID', 'Camera or phone in a waterproof bag', 'Arrive 10 minutes before departure' )
              : array( 'Ropa cómoda y traje de baño', 'Protector solar biodegradable (obligatorio en la laguna)', 'Agua y snacks ligeros', 'Documento de identidad', 'Cámara o celular en bolsa impermeable', 'Llega 10 minutos antes a tu hora de salida' )
            );
      foreach ( $recs_items_v as $rec_item ) {
          echo '<li>' . esc_html( $rec_item ) . '</li>';
      }
      ?>
    </ul>
  </div>

  <!-- Política de cancelación -->
  <div class="section">
    <div class="policy-box">
      <p><strong><?php esc_html_e( 'Política de cancelación', 'amir-booking' ); ?></strong></p>
      <p>✓ <?php esc_html_e( '7+ días antes: reembolso completo', 'amir-booking' ); ?></p>
      <p>▸ <?php esc_html_e( '3–6 días antes: reembolso del 50%', 'amir-booking' ); ?></p>
      <p>✕ <?php esc_html_e( 'Menos de 3 días: sin reembolso', 'amir-booking' ); ?></p>
      <p style="margin-top:4px;font-style:italic;"><?php esc_html_e( 'Cancelaciones por clima o mínimo de pasajeros: reembolso completo.', 'amir-booking' ); ?></p>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <div>
      <p><strong><?php echo esc_html($company_name); ?></strong></p>
      <p><?php echo $site; ?></p>
    </div>
    <div>
      <p class="wa">💬 WhatsApp: +<?php echo esc_html($wa); ?></p>
      <p style="font-size:10px;color:#5a7068;"><?php esc_html_e( '¿Dudas? Escríbenos cuando quieras.', 'amir-booking' ); ?></p>
    </div>
  </div>

</div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function get_booking( int $booking_id ): ?object {
        global $wpdb;
        // LEFT JOIN para que reservas sin schedule_id (=0) también puedan generar voucher
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    t.name_es, t.name_en,
                    t.meeting_point_es, t.meeting_point_en, t.meeting_lat, t.meeting_lng,
                    s.time_start, s.time_end
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             WHERE b.id = %d",
            $booking_id
        ) );

        if ( $booking ) {
            // Antes se resolvía en SQL con un CASE WHEN que solo conocía es/en.
            $booking->tour_name = Languages::tour_field( $booking, 'name', $booking->lang ?? 'es' );
        }

        return $booking;
    }

    /**
     * URL de la página pública de verificación de reserva.
     * Usa la página real creada por el Installer (amir_verify_page_id) si
     * existe, con fallback al slug fijo /verificar-reserva/ para instalaciones
     * antiguas donde la opción todavía no se haya guardado.
     */
    private function verify_page_url(): string {
        $page_id = (int) get_option( 'amir_verify_page_id', 0 );
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return $url;
            }
        }
        return get_site_url() . '/verificar-reserva/';
    }

    /**
     * access_token de la reserva, para autorizar la lectura pública del
     * link de verificación sin depender solo del booking_ref (adivinable).
     */
    private function get_access_token( int $booking_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT access_token FROM {$wpdb->prefix}amir_bookings WHERE id = %d",
            $booking_id
        ) );
    }

    private function path_to_data_uri( string $path ): string {
        if ( ! file_exists($path) ) return '';
        $mime = mime_content_type($path) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode( file_get_contents($path) );
    }

    private function tcpdf_available(): bool {
        // Composer instala TCPDF en vendor/tecnickcom/tcpdf/ y registra el autoloader
        return class_exists( '\TCPDF' )
            || file_exists( AMIR_PLUGIN_DIR . 'vendor/tecnickcom/tcpdf/tcpdf.php' );
    }

    private function dompdf_available(): bool {
        return file_exists( AMIR_PLUGIN_DIR . 'vendor/dompdf/autoload.inc.php' );
    }

    /**
     * HTML optimizado para TCPDF: tablas en lugar de flexbox, sin barra de impresión.
     * TCPDF no soporta display:flex — solo layout de tabla.
     * Construido con concatenación de strings (sin ob_start, sin arrow functions).
     */
    private function build_voucher_html( object $b, bool $for_pdf = false ): string {
        $lang = $b->lang ? $b->lang : 'es';
        return Languages::run_in( $lang, fn() => $this->render_pdf_html( $b, $lang ) );
    }

    private function render_pdf_html( object $b, string $lang ): string {
        $is_en  = ( $lang === 'en' );
        $months = [
            __( 'Enero', 'amir-booking' ), __( 'Febrero', 'amir-booking' ), __( 'Marzo', 'amir-booking' ),
            __( 'Abril', 'amir-booking' ), __( 'Mayo', 'amir-booking' ), __( 'Junio', 'amir-booking' ),
            __( 'Julio', 'amir-booking' ), __( 'Agosto', 'amir-booking' ), __( 'Septiembre', 'amir-booking' ),
            __( 'Octubre', 'amir-booking' ), __( 'Noviembre', 'amir-booking' ), __( 'Diciembre', 'amir-booking' ),
        ];

        $date_parts = explode('-', $b->tour_date);
        $date_fmt   = (int)$date_parts[2] . ' ' . $months[ (int)$date_parts[1] - 1 ] . ' ' . $date_parts[0];

        $time_raw = isset($b->time_start) ? $b->time_start : '00:00';
        $t_parts  = explode(':', $time_raw);
        $t_h      = (int)$t_parts[0];
        $t_disp   = ( $t_h > 12 ? $t_h - 12 : ( $t_h ? $t_h : 12 ) ) . ':' . $t_parts[1] . ( $t_h >= 12 ? ' PM' : ' AM' );

        $pax_parts = array();
        if ( $b->adults )   { $pax_parts[] = $b->adults   . ' ' . __( 'adultos', 'amir-booking' ); }
        if ( $b->children ) { $pax_parts[] = $b->children . ' ' . __( 'niños', 'amir-booking' ); }
        if ( $b->babies )   { $pax_parts[] = $b->babies   . ' ' . __( 'bebés', 'amir-booking' ); }
        $pax_str = implode(', ', $pax_parts);

        $meeting   = Languages::tour_field( $b, 'meeting_point', $lang );
        $tour_name = Languages::tour_field( $b, 'name', $lang );
        $maps_url = $b->meeting_lat
            ? 'https://maps.google.com/?q=' . $b->meeting_lat . ',' . $b->meeting_lng
            : 'https://maps.google.com/?q=Bacalar,Quintana+Roo,Mexico';

        $wa   = get_option( 'amir_wa_phone', '' );
        $site = get_site_url();

        $logo_url_opt = get_option( 'amir_brand_logo_url', '' );
        if ( $logo_url_opt ) {
            // For TCPDF inline we need a data URI; fetch remote logo
            $logo_body = wp_remote_retrieve_body( wp_remote_get( $logo_url_opt, array( 'timeout' => 8 ) ) );
            $logo_uri  = ( $logo_body && strlen($logo_body) > 100 )
                ? ( 'data:image/png;base64,' . base64_encode( $logo_body ) )
                : '';
        } else {
            $logo_path = AMIR_PLUGIN_DIR . 'assets/images/logo-email.png';
            $logo_uri  = file_exists( $logo_path ) ? $this->path_to_data_uri( $logo_path ) : '';
        }
        $qr_path   = $this->generate_qr( (int)$b->id, $b->booking_ref );
        $qr_uri    = $qr_path ? $this->path_to_data_uri( $qr_path ) : '';

        $green        = get_option( 'amir_brand_color', '#1D9E75' );
        $company_name = get_option( 'amir_company_name', 'TourFlow' );
        $amber = '#BA7517';
        $gray  = '#5a7068';

        // ── Helper: fila de info (tabla zebra) ────────────────────────────
        $info_rows = function( array $rows ) {
            $out = '';
            $i   = 0;
            foreach ( $rows as $row ) {
                $bg   = ( $i % 2 === 0 ) ? '#ffffff' : '#f5f9f7';
                $out .= '<tr style="background:' . $bg . ';">'
                      . '<td style="padding:5px 8px;color:#5a7068;font-size:9pt;width:40%;">' . $row[0] . '</td>'
                      . '<td style="padding:5px 8px;font-weight:bold;font-size:9pt;text-align:right;">' . $row[1] . '</td>'
                      . '</tr>';
                $i++;
            }
            return $out;
        };

        // ── Helper: título de sección ──────────────────────────────────────
        $sec = function( $title ) use ( $green ) {
            return '<div style="font-size:8pt;font-weight:bold;text-transform:uppercase;'
                 . 'letter-spacing:0.5px;color:' . $green . ';border-bottom:1px solid #c8ead9;'
                 . 'padding-bottom:3px;margin-bottom:6px;">' . $title . '</div>';
        };

        // ── Datos de tour ──────────────────────────────────────────────────
        $tour_rows = array(
            array( __( 'Tour', 'amir-booking' ),          esc_html( $tour_name ) ),
            array( __( 'Fecha', 'amir-booking' ),         $date_fmt ),
            array( __( 'Hora de salida', 'amir-booking' ),$t_disp ),
            array( __( 'Personas', 'amir-booking' ),      esc_html( $pax_str ) ),
            array(
                __( 'Total pagado', 'amir-booking' ),
                '<b style="color:' . $green . ';font-size:13pt;">' . Currency::format( (float)$b->total_mxn ) . '</b>'
            ),
        );

        $pax_rows = array(
            array( __( 'Nombre', 'amir-booking' ), esc_html( $b->customer_name ) ),
            array( 'Email',                        esc_html( $b->customer_email ) ),
        );
        if ( $b->customer_phone ) {
            $pax_rows[] = array( 'WhatsApp', esc_html( $b->customer_phone ) );
        }

        // ── Recomendaciones ────────────────────────────────────────────────
        // Las escribe el operador por idioma (opción, no string fijo) — solo
        // existen es/en hoy, un idioma 3+ cae al listado en español.
        $recs_raw_b = get_option( $is_en ? 'amir_voucher_recs_en' : 'amir_voucher_recs_es', '' );
        if ( $recs_raw_b ) {
            $recs_plain = array_filter( array_map( 'trim', explode( "\n", $recs_raw_b ) ) );
            $recs = array();
            foreach ( $recs_plain as $r ) {
                $recs[] = esc_html( $r );
            }
        } else {
            $recs = $is_en
                ? array(
                    'Comfortable clothes and swimsuit',
                    'Biodegradable sunscreen (required)',
                    'Water and light snacks',
                    'Photo ID',
                    'Camera in waterproof bag',
                    'Arrive 10 min before departure',
                  )
                : array(
                    'Ropa cómoda y traje de baño',
                    'Protector solar biodegradable (obligatorio)',
                    'Agua y snacks ligeros',
                    'Documento de identidad',
                    'Cámara en bolsa impermeable',
                    'Llega 10 min antes a tu hora de salida',
                  );
        }
        $recs_html = '';
        foreach ( $recs as $rec ) {
            $recs_html .= '<li style="font-size:9pt;padding:2px 0;color:#3d3d3a;">'
                        . '<span style="color:' . $green . ';font-weight:bold;">&#10003;</span> ' . $rec
                        . '</li>';
        }

        // ── Política de cancelación ────────────────────────────────────────
        $pol = array(
            sprintf( __( '7+ días antes: %s', 'amir-booking' ), '<b>' . __( 'reembolso completo', 'amir-booking' ) . '</b>' ),
            sprintf( __( '3–6 días antes: %s', 'amir-booking' ), '<b>' . __( 'reembolso del 50%', 'amir-booking' ) . '</b>' ),
            sprintf( __( 'Menos de 3 días: %s', 'amir-booking' ), '<b>' . __( 'sin reembolso', 'amir-booking' ) . '</b>' ),
            '<i>' . __( 'Por clima o mínimo de pasajeros: reembolso completo.', 'amir-booking' ) . '</i>',
        );
        $pol_html = '';
        foreach ( $pol as $p ) {
            $pol_html .= '<div style="font-size:8.5pt;color:#78350f;padding:2px 0;">' . $p . '</div>';
        }

        // ── Logo ───────────────────────────────────────────────────────────
        $logo_html = $logo_uri
            ? '<img src="' . $logo_uri . '" height="36" alt="' . esc_attr($company_name) . '" /><br/>'
            : '<b style="font-size:14pt;color:' . $green . ';">' . esc_html(strtoupper($company_name)) . '</b><br/>';

        // ── QR ────────────────────────────────────────────────────────────
        $qr_html = $qr_uri
            ? '<img src="' . $qr_uri . '" width="72" height="72" alt="QR" style="border:1px solid #c8ead9;padding:3px;" /><br/>'
              . '<span style="font-size:7.5pt;color:' . $gray . ';">'
              . esc_html__( 'Escanea para verificar', 'amir-booking' )
              . '</span>'
            : '';

        $confirmed_label = esc_html__( 'RESERVA CONFIRMADA', 'amir-booking' );
        $booked_label    = esc_html__( 'Número de reserva', 'amir-booking' );
        $booked_on       = esc_html__( 'Reservado el', 'amir-booking' );
        $loc_label       = esc_html__( 'Punto de encuentro', 'amir-booking' );
        $passenger_label = esc_html__( 'Pasajero', 'amir-booking' );
        $bring_label     = esc_html__( 'Recuerda llevar', 'amir-booking' );
        $policy_label    = esc_html__( 'Política de cancelación', 'amir-booking' );
        $wa_label        = esc_html__( '¿Dudas? Escríbenos cuando quieras.', 'amir-booking' );
        $tagline_opt_v    = get_option( $is_en ? 'amir_company_tagline_en' : 'amir_company_tagline_es', '' );
        $experience_label = $tagline_opt_v
            ? esc_html( $tagline_opt_v )
            : esc_html__( 'Experiencias en Bacalar · Quintana Roo, México', 'amir-booking' );

        $html  = '<html><head><meta charset="UTF-8"></head>';
        $html .= '<body style="font-family:Helvetica,Arial,sans-serif;color:#1a2e24;font-size:10pt;margin:0;padding:0;">';

        // Header
        $html .= '<table width="100%" cellpadding="0" cellspacing="0"'
               . ' style="border-bottom:3px solid ' . $green . ';margin-bottom:10px;padding-bottom:8px;">'
               . '<tr>'
               . '<td width="70%" valign="middle">'
               . $logo_html
               . '<span style="font-size:8pt;color:' . $gray . ';">' . $experience_label . '</span><br/>'
               . '<span style="background:#e1f5ee;color:#0F6E56;font-size:8pt;font-weight:bold;padding:1px 6px;">'
               . '&#10003; ' . $confirmed_label . '</span>'
               . '</td>'
               . '<td width="30%" align="right" valign="top">' . $qr_html . '</td>'
               . '</tr></table>';

        // Referencia
        $html .= '<table width="100%" cellpadding="0" cellspacing="0"'
               . ' style="background:' . $green . ';margin-bottom:10px;">'
               . '<tr>'
               . '<td style="padding:10px 14px;" valign="middle">'
               . '<div style="font-size:7.5pt;font-weight:bold;color:#fff;text-transform:uppercase;">' . $booked_label . '</div>'
               . '<div style="font-size:20pt;font-weight:bold;color:#fff;letter-spacing:2px;">' . esc_html( $b->booking_ref ) . '</div>'
               . '</td>'
               . '<td style="padding:10px 14px;text-align:right;" valign="middle">'
               . '<div style="font-size:8pt;color:#fff;">' . $booked_on . '</div>'
               . '<div style="font-size:10pt;font-weight:bold;color:#fff;">' . date( 'd/m/Y', strtotime( $b->created_at ) ) . '</div>'
               . '</td>'
               . '</tr></table>';

        // Columnas: tour + pasajero
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">'
               . '<tr valign="top">'
               . '<td width="49%" style="padding-right:8px;">'
               . $sec( esc_html__( 'Detalle del tour', 'amir-booking' ) )
               . '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e1f5ee;">'
               . $info_rows( $tour_rows )
               . '</table>'
               . '</td>'
               . '<td width="2%"></td>'
               . '<td width="49%">'
               . $sec( $passenger_label )
               . '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e1f5ee;margin-bottom:8px;">'
               . $info_rows( $pax_rows )
               . '</table>'
               . $sec( $loc_label )
               . '<div style="font-size:9pt;color:#3d3d3a;margin-bottom:3px;">' . esc_html( $meeting ? $meeting : '' ) . '</div>'
               . '<div style="font-size:8.5pt;color:' . $green . ';">&#128205; ' . esc_url( $maps_url ) . '</div>'
               . '</td>'
               . '</tr></table>';

        // Columnas: recomendaciones + política
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">'
               . '<tr valign="top">'
               . '<td width="49%" style="padding-right:8px;">'
               . $sec( $bring_label )
               . '<ul style="margin:0;padding-left:0;list-style:none;">' . $recs_html . '</ul>'
               . '</td>'
               . '<td width="2%"></td>'
               . '<td width="49%">'
               . $sec( $policy_label )
               . '<div style="background:#fffbeb;border-left:3px solid ' . $amber . ';padding:8px 10px;">'
               . $pol_html
               . '</div>'
               . '</td>'
               . '</tr></table>';

        // Footer
        $html .= '<table width="100%" cellpadding="0" cellspacing="0"'
               . ' style="border-top:2px solid ' . $green . ';padding-top:8px;margin-top:4px;">'
               . '<tr>'
               . '<td valign="middle">'
               . '<b style="font-size:9pt;">' . esc_html($company_name) . '</b><br/>'
               . '<span style="font-size:8pt;color:' . $gray . ';">' . esc_url( $site ) . '</span>'
               . '</td>'
               . '<td align="right" valign="middle">'
               . '<b style="font-size:9pt;color:' . $green . ';">WhatsApp: +' . esc_html( $wa ) . '</b><br/>'
               . '<span style="font-size:8pt;color:' . $gray . ';">' . $wa_label . '</span>'
               . '</td>'
               . '</tr></table>';

        $html .= '</body></html>';

        return $html;
    }
}
