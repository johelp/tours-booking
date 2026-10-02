<?php
namespace AmirBooking\Partners;

defined( 'ABSPATH' ) || exit;

/**
 * Tracking de partners.
 *
 * Cuando un usuario llega via ?ref=TOKEN, guarda el token en una cookie
 * de 30 días. El BookingController lo lee para acreditar la venta.
 *
 * También genera tokens únicos y QRs para nuevos partners.
 */
class PartnerTracker {

    private const COOKIE_NAME   = 'amir_partner_ref';
    private const COOKIE_EXPIRY = 30 * DAY_IN_SECONDS;

    public function register(): void {
        // Capturar token al cargar la página
        add_action( 'init', [ $this, 'capture_ref_token' ], 1 );

        // API para crear/gestionar partners (admin)
        add_action( 'wp_ajax_amir_generate_partner_qr', [ $this, 'ajax_generate_qr' ] );
    }

    // ── Captura de token ──────────────────────────────────────────────────

    public function capture_ref_token(): void {
        $token = sanitize_text_field( $_GET['ref'] ?? '' );

        if ( empty( $token ) ) {
            return;
        }

        // Validar que el token existe y está activo
        if ( ! $this->is_valid_token( $token ) ) {
            return;
        }

        // Guardar en cookie (30 días)
        setcookie(
            self::COOKIE_NAME,
            $token,
            time() + self::COOKIE_EXPIRY,
            '/',
            '',
            is_ssl(),
            true  // httpOnly
        );

        // También en $_COOKIE para el resto de esta request
        $_COOKIE[ self::COOKIE_NAME ] = $token;
    }

    /**
     * Obtiene el token de partner de la cookie actual.
     * Llamado desde el frontend (localizado en el widget de reserva).
     */
    public function get_current_token(): string {
        return sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ?? '' );
    }

    // ── Generación de partner ─────────────────────────────────────────────

    public static function generate_token(): string {
        return strtoupper( bin2hex( random_bytes( 8 ) ) ); // 16 chars hex
    }

    public static function create_partner( array $data ) {
        global $wpdb;

        $token = self::generate_token();

        // Asegurarse de que el token es único (muy improbable que colisione, pero seguro)
        $attempts = 0;
        while ( $attempts < 10 ) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}amir_partners WHERE tracking_token = %s",
                    $token
                )
            );
            if ( ! $exists ) {
                break;
            }
            $token = self::generate_token();
            $attempts++;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}amir_partners",
            [
                'name'             => sanitize_text_field( $data['name'] ),
                'email'            => sanitize_email( $data['email'] ),
                'phone'            => sanitize_text_field( $data['phone'] ?? '' ),
                'tracking_token'   => $token,
                'commission_type'  => $data['commission_type'] === 'fixed' ? 'fixed' : 'percentage',
                'commission_value' => (float) $data['commission_value'],
                'active'           => 1,
                'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
            ],
            [ '%s','%s','%s','%s','%s','%f','%d','%s' ]
        );

        if ( ! $inserted ) {
            return new \WP_Error( 'db_error', 'Error al crear el partner' );
        }

        $partner_id = $wpdb->insert_id;

        // Generar QR
        $qr_url = self::generate_partner_qr( $partner_id, $token );
        if ( $qr_url ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_partners",
                [ 'qr_code_url' => $qr_url ],
                [ 'id' => $partner_id ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        return $partner_id;
    }

    // ── Generación de QR ──────────────────────────────────────────────────

    /**
     * Genera el QR del partner.
     * Descarga la imagen de Google Charts y la guarda localmente para
     * que el botón "Descargar" funcione correctamente.
     * Si la descarga falla, retorna la URL de Google Charts directamente.
     */
    public static function generate_partner_qr( int $partner_id, string $token, int $tour_id = 0 ): string {
        $partner_url = self::get_partner_tracking_url( $partner_id, $token, $tour_id );

        $upload_dir  = wp_upload_dir();
        $qr_dir      = $upload_dir['basedir'] . '/amir-booking/partners/';
        $qr_url_base = $upload_dir['baseurl'] . '/amir-booking/partners/';

        wp_mkdir_p( $qr_dir );

        $suffix   = $tour_id > 0 ? "-tour{$tour_id}" : '-all-tours';
        $filename = "partner-{$partner_id}{$suffix}-{$token}.png";
        $filepath = $qr_dir . $filename;

        // Si ya existe y tiene contenido, retornar directo
        if ( file_exists( $filepath ) && filesize( $filepath ) > 100 ) {
            return $qr_url_base . $filename;
        }

        // 1. endroid/qr-code (instalado vía Composer — igual que en VoucherGenerator)
        if ( class_exists( '\Endroid\QrCode\QrCode' ) ) {
            try {
                $qr     = \Endroid\QrCode\QrCode::create( $partner_url )
                    ->setSize( 300 )
                    ->setMargin( 10 );
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $result = $writer->write( $qr );
                $result->saveToFile( $filepath );
                if ( file_exists( $filepath ) && filesize( $filepath ) > 100 ) {
                    return $qr_url_base . $filename;
                }
            } catch ( \Throwable $e ) {
                error_log( 'Amir partner QR endroid error: ' . $e->getMessage() );
            }
        }

        // 2. api.qrserver.com — gratuito, sin dependencias, sin deprecaciones
        $api_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='
                  . rawurlencode( $partner_url ) . '&format=png&margin=10';
        $response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $body = wp_remote_retrieve_body( $response );
            if ( strlen( $body ) > 100 ) {
                file_put_contents( $filepath, $body );
                if ( file_exists( $filepath ) && filesize( $filepath ) > 100 ) {
                    return $qr_url_base . $filename;
                }
            }
        }

        // Sin imagen disponible — devolver cadena vacía para que la UI lo maneje
        return '';
    }

    /**
     * Construye la URL de tracking del partner.
     */
    private static function get_partner_tracking_url( int $partner_id, string $token, int $tour_id = 0 ): string {
        if ( $tour_id > 0 ) {
            $post = get_posts( [
                'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
                'meta_key'       => '_amir_tour_db_id',
                'meta_value'     => $tour_id,
                'posts_per_page' => 1,
            ] );
            $base = $post ? get_permalink( $post[0]->ID ) : ( get_post_type_archive_link( \AmirBooking\CPT\TourPostType::POST_TYPE ) ?: get_site_url() . '/nuestros-tours/' );
        } else {
            $base = get_post_type_archive_link( \AmirBooking\CPT\TourPostType::POST_TYPE ) ?: get_site_url() . '/nuestros-tours/';
        }
        return add_query_arg( 'ref', $token, $base );
    }

    /**
     * Devuelve las URLs de tracking del partner.
     */
    public static function get_partner_urls( int $partner_id, array $tour_ids = [] ): array {
        global $wpdb;
        $token = $wpdb->get_var( $wpdb->prepare(
            "SELECT tracking_token FROM {$wpdb->prefix}amir_partners WHERE id=%d", $partner_id
        ) );

        if ( ! $token ) {
            return [];
        }

        $archive_url = get_post_type_archive_link( \AmirBooking\CPT\TourPostType::POST_TYPE )
            ?: get_site_url() . '/nuestros-tours/';

        $urls = [
            'url_all_tours' => add_query_arg( 'ref', $token, $archive_url ),
            'qr_all_tours'  => self::generate_partner_qr( $partner_id, $token, 0 ),
            'tours'         => [],
        ];

        foreach ( $tour_ids as $tour_db_id ) {
            $post = get_posts( [
                'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
                'meta_key'       => '_amir_tour_db_id',
                'meta_value'     => $tour_db_id,
                'posts_per_page' => 1,
            ] );
            if ( $post ) {
                $tour_url = add_query_arg( 'ref', $token, get_permalink( $post[0]->ID ) );
                $urls['tours'][] = [
                    'tour_id' => $tour_db_id,
                    'name'    => get_the_title( $post[0]->ID ),
                    'url'     => $tour_url,
                    'qr'      => self::generate_partner_qr( $partner_id, $token, $tour_db_id ),
                ];
            }
        }

        return $urls;
    }

    // ── Cálculo de comisión ───────────────────────────────────────────────

    public static function calculate_commission( int $partner_id, float $booking_total ): float {
        global $wpdb;

        $partner = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT commission_type, commission_value FROM {$wpdb->prefix}amir_partners WHERE id = %d",
                $partner_id
            )
        );

        if ( ! $partner ) {
            return 0.0;
        }

        if ( $partner->commission_type === 'fixed' ) {
            return round( (float) $partner->commission_value, 2 );
        }

        // Porcentaje
        return round( $booking_total * ( (float) $partner->commission_value / 100 ), 2 );
    }

    // ── Stats del partner ─────────────────────────────────────────────────

    public static function get_partner_stats( int $partner_id, ?string $from = null, ?string $until = null ): array {
        global $wpdb;

        $where_date = '';
        $params     = [ $partner_id ];

        if ( $from ) {
            $where_date .= ' AND tour_date >= %s';
            $params[]    = $from;
        }
        if ( $until ) {
            $where_date .= ' AND tour_date <= %s';
            $params[]    = $until;
        }

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total_bookings,
                    COALESCE(SUM(total_mxn), 0) as total_revenue,
                    COALESCE(SUM(adults + children + babies), 0) as total_pax
                 FROM {$wpdb->prefix}amir_bookings
                 WHERE partner_id = %d
                   AND status IN ('confirmed', 'completed')
                   {$where_date}",
                ...$params
            )
        );

        // Calcular comisión total
        $partner = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT commission_type, commission_value FROM {$wpdb->prefix}amir_partners WHERE id = %d",
                $partner_id
            )
        );

        $commission = 0.0;
        if ( $partner && $stats ) {
            if ( $partner->commission_type === 'fixed' ) {
                $commission = (float) $partner->commission_value * (int) $stats->total_bookings;
            } else {
                $commission = (float) $stats->total_revenue * ( (float) $partner->commission_value / 100 );
            }
        }

        return [
            'total_bookings' => (int)   ( $stats->total_bookings ?? 0 ),
            'total_revenue'  => (float) ( $stats->total_revenue  ?? 0 ),
            'total_pax'      => (int)   ( $stats->total_pax      ?? 0 ),
            'commission_mxn' => round( $commission, 2 ),
        ];
    }

    // ── Ajax: Generar QR ──────────────────────────────────────────────────

    public function ajax_generate_qr(): void {
        check_ajax_referer( 'amir_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sin permisos' );
        }

        $partner_id = (int) ( $_POST['partner_id'] ?? 0 );
        if ( ! $partner_id ) {
            wp_send_json_error( 'ID inválido' );
        }

        global $wpdb;
        $partner = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, tracking_token FROM {$wpdb->prefix}amir_partners WHERE id = %d",
                $partner_id
            )
        );

        if ( ! $partner ) {
            wp_send_json_error( 'Partner no encontrado' );
        }

        $qr_url = self::generate_partner_qr( $partner_id, $partner->tracking_token );
        $wpdb->update(
            "{$wpdb->prefix}amir_partners",
            [ 'qr_code_url' => $qr_url ],
            [ 'id' => $partner_id ],
            [ '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'qr_url' => $qr_url ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function is_valid_token( string $token ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}amir_partners WHERE tracking_token = %s AND active = 1",
                $token
            )
        );
    }
}
