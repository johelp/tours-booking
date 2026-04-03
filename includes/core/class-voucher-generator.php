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
        $verify_url = add_query_arg( 'ref', $booking_ref, get_site_url() . '/verificar-reserva/' );
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
        $pdf->SetCreator('Amir Adventours Bacalar');
        $pdf->SetAuthor('Amir Adventours');
        $pdf->SetTitle('Voucher ' . $booking->booking_ref);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        // QR ya embebido como data URI dentro del HTML; no se usa $pdf->Image() por separado
        $html = $this->build_voucher_html($booking, for_pdf: true);
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
        $qr_path   = $this->generate_qr((int)$b->id, $b->booking_ref);
        $qr_src    = $qr_path ? $this->path_to_data_uri($qr_path) : '';
        $logo_path = AMIR_PLUGIN_DIR . 'assets/images/logo-email.png';
        $logo_src  = file_exists($logo_path) ? $this->path_to_data_uri($logo_path) : '';

        $lang       = $b->lang ?? 'es';
        $is_en      = $lang === 'en';
        $months     = $is_en
            ? ['January','February','March','April','May','June','July','August','September','October','November','December']
            : ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        [$y,$m,$d]  = explode('-', $b->tour_date);
        $date_fmt   = (int)$d . ' ' . $months[(int)$m-1] . ' ' . $y;

        $fmtTime = function(string $t): string {
            [$h,$mi] = explode(':', $t);
            $h=(int)$h;
            return ($h>12?$h-12:($h?:12)).':'.$mi.($h>=12?' PM':' AM');
        };

        $pax_es  = [];
        if ($b->adults)   $pax_es[] = $b->adults.' '.($is_en?'adults':'adultos');
        if ($b->children) $pax_es[] = $b->children.' '.($is_en?'children':'niños');
        if ($b->babies)   $pax_es[] = $b->babies.' '.($is_en?'babies':'bebés');
        $pax_str = implode(', ', $pax_es);

        $meeting = $is_en ? ($b->meeting_point_en ?: $b->meeting_point_es) : $b->meeting_point_es;
        $maps_url = $b->meeting_lat
            ? "https://maps.google.com/?q={$b->meeting_lat},{$b->meeting_lng}"
            : 'https://maps.google.com/?q=Bacalar,Quintana+Roo,Mexico';

        $wa    = get_option('amir_wa_phone','5219831649541');
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

  .header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:16px; border-bottom:3px solid #1D9E75; margin-bottom:20px; }
  .header-left h1 { font-size:22px; font-weight:800; color:#1D9E75; }
  .header-left p  { font-size:11px; color:#5a7068; }
  .qr-block { text-align:right; }
  .qr-block img { width:80px; height:80px; border:1px solid #e1f5ee; padding:4px; border-radius:4px; }
  .qr-block .qr-label { font-size:9px; color:#5a7068; margin-top:3px; }

  .ref-box { background:#1D9E75; color:#fff; border-radius:8px; padding:14px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; }
  .ref-box .ref-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; opacity:.85; }
  .ref-box .ref-value { font-size:24px; font-weight:800; letter-spacing:2px; }
  .ref-box .ref-date  { font-size:12px; opacity:.85; text-align:right; }

  .section { margin-bottom:18px; }
  .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#1D9E75; border-bottom:1px solid #e1f5ee; padding-bottom:5px; margin-bottom:10px; }
  .info-row { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f5f5f5; font-size:12px; }
  .info-row:last-child { border-bottom:none; }
  .info-row .label { color:#5a7068; }
  .info-row .value { font-weight:600; text-align:right; max-width:60%; }

  .recs-list { list-style:none; }
  .recs-list li { padding:4px 0; font-size:12px; color:#3d3d3a; }
  .recs-list li::before { content:"✓ "; color:#1D9E75; font-weight:700; }

  .policy-box { background:#fffbeb; border-left:3px solid #BA7517; padding:10px 14px; border-radius:0 6px 6px 0; }
  .policy-box p { font-size:11px; color:#78350f; padding:2px 0; }

  .footer { border-top:2px solid #1D9E75; margin-top:24px; padding-top:14px; display:flex; justify-content:space-between; align-items:center; }
  .footer p { font-size:10px; color:#5a7068; }
  .footer .wa { font-size:11px; font-weight:700; color:#1D9E75; }

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
<div class="no-print" style="background:#1D9E75;color:#fff;text-align:center;padding:10px 16px;font-family:Arial,sans-serif;font-size:13px;position:sticky;top:0;z-index:99;">
  📄 <?php echo $is_en ? 'To save as PDF, click' : 'Para guardar como PDF, haz clic en'; ?>
  <button onclick="window.print()" style="background:#fff;color:#1D9E75;border:none;border-radius:5px;padding:5px 14px;font-weight:700;cursor:pointer;margin:0 8px;">
    <?php echo $is_en ? '🖨 Print / Save PDF' : '🖨 Imprimir / Guardar PDF'; ?>
  </button>
  <?php echo $is_en ? 'and select "Save as PDF"' : 'y selecciona "Guardar como PDF"'; ?>
</div>
<div class="page">

  <!-- Header -->
  <div class="header">
    <div class="header-left">
      <?php if ($logo_src) : ?>
        <img src="<?php echo $logo_src; ?>" alt="Amir Adventours" style="height:40px;margin-bottom:6px;" />
      <?php else : ?>
        <h1>AMIR ADVENTOURS</h1>
      <?php endif; ?>
      <p><?php echo $is_en ? 'Experiences in Bacalar · Quintana Roo, Mexico' : 'Experiencias en Bacalar · Quintana Roo, México'; ?></p>
      <span class="status-ok">✓ <?php echo $is_en ? 'CONFIRMED BOOKING' : 'RESERVA CONFIRMADA'; ?></span>
    </div>
    <div class="qr-block">
      <?php if ($qr_src) : ?>
        <img src="<?php echo $qr_src; ?>" alt="QR" />
        <div class="qr-label"><?php echo $is_en ? 'Scan to verify' : 'Escanea para verificar'; ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Número de reserva -->
  <div class="ref-box">
    <div>
      <div class="ref-label"><?php echo $is_en ? 'Booking reference' : 'Número de reserva'; ?></div>
      <div class="ref-value"><?php echo esc_html($b->booking_ref); ?></div>
    </div>
    <div class="ref-date">
      <?php echo $is_en ? 'Booked on' : 'Reservado el'; ?><br>
      <?php echo date('d/m/Y', strtotime($b->created_at)); ?>
    </div>
  </div>

  <!-- Detalle del tour -->
  <div class="section">
    <div class="section-title"><?php echo $is_en ? 'Tour details' : 'Detalle del tour'; ?></div>
    <div class="info-row"><span class="label"><?php echo $is_en ? 'Tour' : 'Tour'; ?></span><span class="value"><?php echo esc_html($b->tour_name); ?></span></div>
    <div class="info-row"><span class="label"><?php echo $is_en ? 'Date' : 'Fecha'; ?></span><span class="value"><?php echo $date_fmt; ?></span></div>
    <div class="info-row"><span class="label"><?php echo $is_en ? 'Departure' : 'Hora de salida'; ?></span><span class="value"><?php echo $fmtTime($b->time_start??'00:00'); ?></span></div>
    <div class="info-row"><span class="label"><?php echo $is_en ? 'People' : 'Personas'; ?></span><span class="value"><?php echo esc_html($pax_str); ?></span></div>
    <div class="info-row"><span class="label"><?php echo $is_en ? 'Total paid' : 'Total pagado'; ?></span><span class="value" style="font-size:14px;color:#1D9E75;">$<?php echo number_format($b->total_mxn,2); ?> MXN</span></div>
  </div>

  <!-- Punto de encuentro -->
  <div class="section">
    <div class="section-title"><?php echo $is_en ? 'Meeting point' : 'Punto de encuentro'; ?></div>
    <p style="font-size:12px;color:#3d3d3a;margin-bottom:6px;"><?php echo esc_html($meeting ?? ''); ?></p>
    <p style="font-size:11px;color:#1D9E75;">📍 <a href="<?php echo esc_url($maps_url); ?>" style="color:#1D9E75;"><?php echo $is_en ? 'Open in Google Maps' : 'Ver en Google Maps'; ?> → <?php echo $maps_url; ?></a></p>
  </div>

  <!-- Datos del pasajero -->
  <div class="section">
    <div class="section-title"><?php echo $is_en ? 'Passenger' : 'Pasajero'; ?></div>
    <div class="info-row"><span class="label"><?php echo $is_en ? 'Name' : 'Nombre'; ?></span><span class="value"><?php echo esc_html($b->customer_name); ?></span></div>
    <div class="info-row"><span class="label">Email</span><span class="value"><?php echo esc_html($b->customer_email); ?></span></div>
    <?php if ($b->customer_phone) : ?>
    <div class="info-row"><span class="label">WhatsApp</span><span class="value"><?php echo esc_html($b->customer_phone); ?></span></div>
    <?php endif; ?>
  </div>

  <!-- Recomendaciones -->
  <div class="section">
    <div class="section-title"><?php echo $is_en ? 'Remember to bring' : 'Recuerda llevar'; ?></div>
    <ul class="recs-list">
      <?php if ($is_en) : ?>
        <li>Comfortable clothes and swimsuit</li>
        <li>Biodegradable sunscreen (required on the lagoon)</li>
        <li>Water and light snacks</li>
        <li>Photo ID</li>
        <li>Camera or phone in a waterproof bag</li>
        <li>Arrive 10 minutes before departure</li>
      <?php else : ?>
        <li>Ropa cómoda y traje de baño</li>
        <li>Protector solar biodegradable (obligatorio en la laguna)</li>
        <li>Agua y snacks ligeros</li>
        <li>Documento de identidad</li>
        <li>Cámara o celular en bolsa impermeable</li>
        <li>Llega 10 minutos antes a tu hora de salida</li>
      <?php endif; ?>
    </ul>
  </div>

  <!-- Política de cancelación -->
  <div class="section">
    <div class="policy-box">
      <p><strong><?php echo $is_en ? 'Cancellation policy' : 'Política de cancelación'; ?></strong></p>
      <p><?php echo $is_en ? '✓ 7+ days before: full refund' : '✓ 7+ días antes: reembolso completo'; ?></p>
      <p><?php echo $is_en ? '▸ 3–6 days before: 50% refund' : '▸ 3–6 días antes: reembolso del 50%'; ?></p>
      <p><?php echo $is_en ? '✕ Less than 3 days: no refund' : '✕ Menos de 3 días: sin reembolso'; ?></p>
      <p style="margin-top:4px;font-style:italic;"><?php echo $is_en ? 'Cancellations due to weather or minimum passengers: full refund.' : 'Cancelaciones por clima o mínimo de pasajeros: reembolso completo.'; ?></p>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <div>
      <p><strong>Amir Adventours Bacalar</strong></p>
      <p><?php echo $site; ?></p>
    </div>
    <div>
      <p class="wa">💬 WhatsApp: +<?php echo esc_html($wa); ?></p>
      <p style="font-size:10px;color:#5a7068;"><?php echo $is_en ? 'Questions? Message us anytime.' : '¿Dudas? Escríbenos cuando quieras.'; ?></p>
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
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    CASE WHEN b.lang='en' THEN t.name_en ELSE t.name_es END as tour_name,
                    t.meeting_point_es, t.meeting_point_en, t.meeting_lat, t.meeting_lng,
                    s.time_start, s.time_end
             FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
             LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
             WHERE b.id = %d",
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
     * TCPDF no soporta display:flex ni position:sticky — solo layout de tabla funciona.
     * El QR se embebe como data URI para que TCPDF lo renderice en la misma pasada.
     */
    private function build_voucher_html( object $b, bool $for_pdf = false ): string {
        $lang   = $b->lang ?? 'es';
        $is_en  = $lang === 'en';
        $months = $is_en
            ? ['January','February','March','April','May','June','July','August','September','October','November','December']
            : ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        [$y,$m,$d] = explode('-', $b->tour_date);
        $date_fmt  = (int)$d . ' ' . $months[(int)$m-1] . ' ' . $y;

        $fmtTime = function(string $t): string {
            [$h,$mi] = explode(':', $t);
            $h = (int)$h;
            return ($h > 12 ? $h - 12 : ($h ?: 12)) . ':' . $mi . ($h >= 12 ? ' PM' : ' AM');
        };

        $pax_parts = [];
        if ($b->adults)   $pax_parts[] = $b->adults   . ' ' . ($is_en ? 'adults'   : 'adultos');
        if ($b->children) $pax_parts[] = $b->children . ' ' . ($is_en ? 'children' : 'niños');
        if ($b->babies)   $pax_parts[] = $b->babies   . ' ' . ($is_en ? 'babies'   : 'bebés');
        $pax_str = implode(', ', $pax_parts);

        $meeting  = $is_en ? ($b->meeting_point_en ?: $b->meeting_point_es) : $b->meeting_point_es;
        $maps_url = $b->meeting_lat
            ? "https://maps.google.com/?q={$b->meeting_lat},{$b->meeting_lng}"
            : 'https://maps.google.com/?q=Bacalar,Quintana+Roo,Mexico';
        $wa   = get_option('amir_wa_phone', '5219831649541');
        $site = get_site_url();

        // Logo y QR como data URIs (TCPDF necesita rutas locales o data URIs)
        $logo_path = AMIR_PLUGIN_DIR . 'assets/images/logo-email.png';
        $logo_uri  = file_exists($logo_path) ? $this->path_to_data_uri($logo_path) : '';
        $qr_path   = $this->generate_qr((int)$b->id, $b->booking_ref);
        $qr_uri    = $qr_path ? $this->path_to_data_uri($qr_path) : '';

        $green  = '#1D9E75';
        $amber  = '#BA7517';
        $gray   = '#5a7068';
        $lgray  = '#f5f9f7';

        // Filas de info del tour
        $rows_tour = [
            [$is_en ? 'Tour'       : 'Tour',           esc_html($b->tour_name)],
            [$is_en ? 'Date'       : 'Fecha',           $date_fmt],
            [$is_en ? 'Departure'  : 'Hora de salida',  $fmtTime($b->time_start ?? '00:00')],
            [$is_en ? 'People'     : 'Personas',         esc_html($pax_str)],
            [$is_en ? 'Total paid' : 'Total pagado',     '<b style="color:'.$green.';font-size:13pt;">$' . number_format($b->total_mxn,2) . ' MXN</b>'],
        ];
        $rows_pax = [
            [$is_en ? 'Name'      : 'Nombre',  esc_html($b->customer_name)],
            ['Email',                            esc_html($b->customer_email)],
        ];
        if ($b->customer_phone) {
            $rows_pax[] = ['WhatsApp', esc_html($b->customer_phone)];
        }

        $info_row_html = function(array $rows): string {
            $out = '';
            foreach ($rows as $i => [$label, $value]) {
                $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f9f7';
                $out .= '<tr style="background:'.$bg.';">'
                      . '<td style="padding:5px 8px;color:#5a7068;font-size:9pt;width:40%;">'.$label.'</td>'
                      . '<td style="padding:5px 8px;font-weight:bold;font-size:9pt;text-align:right;">'.$value.'</td>'
                      . '</tr>';
            }
            return $out;
        };

        $recs = $is_en
            ? ['Comfortable clothes and swimsuit','Biodegradable sunscreen (required)','Water and light snacks','Photo ID','Camera in waterproof bag','Arrive 10 min before departure']
            : ['Ropa cómoda y traje de baño','Protector solar biodegradable (obligatorio)','Agua y snacks ligeros','Documento de identidad','Cámara en bolsa impermeable','Llega 10 min antes a tu hora de salida'];

        $recs_html = implode('', array_map(fn($r) => '<li style="font-size:9pt;padding:2px 0;color:#3d3d3a;"><span style="color:'.$green.';font-weight:bold;">&#10003;</span> '.$r.'</li>', $recs));

        $pol = $is_en
            ? ['7+ days before: <b>full refund</b>', '3–6 days before: <b>50% refund</b>', 'Less than 3 days: <b>no refund</b>', '<i>Weather/minimum passengers: full refund.</i>']
            : ['7+ días antes: <b>reembolso completo</b>', '3–6 días antes: <b>reembolso del 50%</b>', 'Menos de 3 días: <b>sin reembolso</b>', '<i>Por clima o mínimo de pasajeros: reembolso completo.</i>'];
        $pol_html = implode('', array_map(fn($p) => '<div style="font-size:8.5pt;color:#78350f;padding:2px 0;">'.$p.'</div>', $pol));

        $section_title = fn(string $t): string
            => '<div style="font-size:8pt;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;color:'.$green.';border-bottom:1px solid #c8ead9;padding-bottom:3px;margin-bottom:6px;">'.$t.'</div>';

        ob_start(); ?>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Helvetica,Arial,sans-serif;color:#1a2e24;font-size:10pt;margin:0;padding:0;">

<!-- ═══ HEADER ═══ -->
<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:3px solid <?php echo $green; ?>;margin-bottom:10px;padding-bottom:8px;">
<tr>
  <td width="70%" valign="middle">
    <?php if ($logo_uri): ?><img src="<?php echo $logo_uri; ?>" height="36" alt="Amir Adventours" /><br/><?php else: ?><b style="font-size:14pt;color:<?php echo $green; ?>;">AMIR ADVENTOURS</b><br/><?php endif; ?>
    <span style="font-size:8pt;color:<?php echo $gray; ?>;"><?php echo $is_en ? 'Experiences in Bacalar · Quintana Roo, Mexico' : 'Experiencias en Bacalar · Quintana Roo, México'; ?></span><br/>
    <span style="background:#e1f5ee;color:#0F6E56;font-size:8pt;font-weight:bold;padding:1px 6px;border-radius:4px;">&#10003; <?php echo $is_en ? 'CONFIRMED BOOKING' : 'RESERVA CONFIRMADA'; ?></span>
  </td>
  <td width="30%" align="right" valign="top">
    <?php if ($qr_uri): ?>
    <img src="<?php echo $qr_uri; ?>" width="72" height="72" alt="QR" style="border:1px solid #c8ead9;padding:3px;" /><br/>
    <span style="font-size:7.5pt;color:<?php echo $gray; ?>;"><?php echo $is_en ? 'Scan to verify' : 'Escanea para verificar'; ?></span>
    <?php endif; ?>
  </td>
</tr>
</table>

<!-- ═══ REFERENCIA ═══ -->
<table width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo $green; ?>;border-radius:6px;margin-bottom:10px;">
<tr>
  <td style="padding:10px 14px;" valign="middle">
    <div style="font-size:7.5pt;font-weight:bold;color:rgba(255,255,255,0.8);text-transform:uppercase;letter-spacing:0.5px;"><?php echo $is_en ? 'Booking reference' : 'Número de reserva'; ?></div>
    <div style="font-size:20pt;font-weight:bold;color:#ffffff;letter-spacing:2px;"><?php echo esc_html($b->booking_ref); ?></div>
  </td>
  <td style="padding:10px 14px;text-align:right;" valign="middle">
    <div style="font-size:8pt;color:rgba(255,255,255,0.85);"><?php echo $is_en ? 'Booked on' : 'Reservado el'; ?></div>
    <div style="font-size:10pt;font-weight:bold;color:#ffffff;"><?php echo date('d/m/Y', strtotime($b->created_at)); ?></div>
  </td>
</tr>
</table>

<!-- ═══ DOS COLUMNAS: tour info + pasajero ═══ -->
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
<tr valign="top">

  <!-- Columna izquierda: Tour -->
  <td width="49%" style="padding-right:8px;">
    <?php echo $section_title($is_en ? 'Tour details' : 'Detalle del tour'); ?>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e1f5ee;border-radius:4px;">
    <?php echo $info_row_html($rows_tour); ?>
    </table>
  </td>

  <td width="2%"></td>

  <!-- Columna derecha: Pasajero + Punto de encuentro -->
  <td width="49%">
    <?php echo $section_title($is_en ? 'Passenger' : 'Pasajero'); ?>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e1f5ee;border-radius:4px;margin-bottom:8px;">
    <?php echo $info_row_html($rows_pax); ?>
    </table>

    <?php echo $section_title($is_en ? 'Meeting point' : 'Punto de encuentro'); ?>
    <div style="font-size:9pt;color:#3d3d3a;margin-bottom:3px;"><?php echo esc_html($meeting ?? ''); ?></div>
    <div style="font-size:8.5pt;color:<?php echo $green; ?>;">&#128205; <?php echo $maps_url; ?></div>
  </td>

</tr>
</table>

<!-- ═══ DOS COLUMNAS: recomendaciones + política ═══ -->
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
<tr valign="top">

  <td width="49%" style="padding-right:8px;">
    <?php echo $section_title($is_en ? 'Remember to bring' : 'Recuerda llevar'); ?>
    <ul style="margin:0;padding-left:0;list-style:none;"><?php echo $recs_html; ?></ul>
  </td>

  <td width="2%"></td>

  <td width="49%">
    <?php echo $section_title($is_en ? 'Cancellation policy' : 'Política de cancelación'); ?>
    <div style="background:#fffbeb;border-left:3px solid <?php echo $amber; ?>;padding:8px 10px;">
    <?php echo $pol_html; ?>
    </div>
  </td>

</tr>
</table>

<!-- ═══ FOOTER ═══ -->
<table width="100%" cellpadding="0" cellspacing="0" style="border-top:2px solid <?php echo $green; ?>;padding-top:8px;margin-top:4px;">
<tr>
  <td valign="middle">
    <b style="font-size:9pt;">Amir Adventours Bacalar</b><br/>
    <span style="font-size:8pt;color:<?php echo $gray; ?>;"><?php echo $site; ?></span>
  </td>
  <td align="right" valign="middle">
    <b style="font-size:9pt;color:<?php echo $green; ?>;">WhatsApp: +<?php echo esc_html($wa); ?></b><br/>
    <span style="font-size:8pt;color:<?php echo $gray; ?>;"><?php echo $is_en ? 'Questions? Message us anytime.' : '¿Dudas? Escríbenos cuando quieras.'; ?></span>
  </td>
</tr>
</table>

</body>
</html>
        <?php
        return ob_get_clean();
    }
}
