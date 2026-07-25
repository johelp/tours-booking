<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Lista de interés ("avísame cuando abra") para tours todavía en borrador.
 *
 * A diferencia de la v1, esto ya NO es un simple registro de contacto: crea
 * una reserva real (misma tabla amir_bookings, estado 'wishlist') con fecha,
 * horario, personas y precio ya calculados — lista para convertirse en un
 * cobro real el día que el tour se publique. Ver BookingManager::create_wishlist().
 *
 * GET  /wp-json/amir/v1/tours/upcoming        → tours en borrador con wishlist activa
 * POST /wp-json/amir/v1/tours/{id}/wishlist   → registrar interés (reserva 'wishlist')
 */
class WishlistController {

    private const NAMESPACE = 'amir/v1';

    public function register_routes(): void {

        register_rest_route( self::NAMESPACE, '/tours/upcoming', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_upcoming' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'lang' => [ 'default' => 'es', 'enum' => [ 'es', 'en' ] ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/tours/(?P<id>\d+)/wishlist', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'register_interest' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'             => [ 'required' => true, 'type' => 'integer' ],
                'customer_name'  => [ 'required' => true, 'type' => 'string'  ],
                'customer_email' => [ 'required' => true, 'type' => 'string', 'format' => 'email' ],
            ],
        ] );
    }

    // ── GET /tours/upcoming ───────────────────────────────────────────────

    public function get_upcoming( \WP_REST_Request $req ): \WP_REST_Response {
        $lang = sanitize_key( $req->get_param( 'lang' ) ?: 'es' );

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, slug, name_es, name_en, description_es, description_en,
                    duration_minutes, min_age, gallery_images, price_model, max_capacity,
                    wishlist_threshold, wishlist_date
             FROM {$wpdb->prefix}amir_tours
             WHERE status = 'draft' AND wishlist_enabled = 1
             ORDER BY sort_order ASC, id ASC"
        ) ?? [];

        $tours = array_map( function ( $r ) use ( $lang ) {
            global $wpdb;
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings WHERE tour_id = %d AND status = 'wishlist'",
                $r->id
            ) );
            $gallery  = json_decode( $r->gallery_images ?: '[]', true );
            $desc_raw = $lang === 'en' ? ( $r->description_en ?: $r->description_es ) : $r->description_es;

            return [
                'id'                => (int) $r->id,
                'slug'              => $r->slug,
                'name'              => $lang === 'en' ? ( $r->name_en ?: $r->name_es ) : $r->name_es,
                'short_description' => mb_substr( wp_strip_all_tags( $desc_raw ?: '' ), 0, 140 ),
                'duration_minutes'  => (int) $r->duration_minutes,
                'min_age'           => (int) $r->min_age,
                'cover_image'       => $gallery[0] ?? '',
                'interest_count'    => $count,
                'threshold'         => (int) $r->wishlist_threshold,
                'date'              => $r->wishlist_date,
                'price_model'       => $r->price_model,
                'max_capacity'      => (int) $r->max_capacity,
            ];
        }, $rows );

        return rest_ensure_response( $tours );
    }

    // ── POST /tours/{id}/wishlist ─────────────────────────────────────────

    public function register_interest( \WP_REST_Request $req ): \WP_REST_Response {
        if ( \AmirBooking\Core\RateLimiter::too_many_attempts( 'wishlist_' . \AmirBooking\Core\RateLimiter::client_ip(), 10, 600 ) ) {
            return new \WP_REST_Response( [ 'error' => 'Demasiados intentos. Intenta de nuevo en unos minutos.' ], 429 );
        }

        $tour_id = (int) $req->get_param( 'id' );
        $lang    = in_array( $req->get_param( 'lang' ), [ 'es', 'en' ], true ) ? $req->get_param( 'lang' ) : 'es';

        global $wpdb;
        $tour = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name_es, name_en, wishlist_threshold, wishlist_notified_at, wishlist_date
             FROM {$wpdb->prefix}amir_tours
             WHERE id = %d AND status = 'draft' AND wishlist_enabled = 1",
            $tour_id
        ) );

        if ( ! $tour ) {
            return new \WP_REST_Response( [ 'error' => 'Tour no disponible para lista de interés' ], 404 );
        }

        if ( empty( $tour->wishlist_date ) ) {
            return new \WP_REST_Response( [
                'error' => 'Este tour todavía no tiene fecha configurada. Contactanos directamente mientras tanto.',
            ], 422 );
        }

        $manager = new \AmirBooking\Core\BookingManager();
        $result  = $manager->create_wishlist( [
            'tour_id'          => $tour_id,
            'schedule_id'      => (int) ( $req->get_param( 'schedule_id' ) ?? 0 ),
            'date'             => $tour->wishlist_date,
            'adults'           => $req->get_param( 'adults' ) ?? 1,
            'children'         => $req->get_param( 'children' ) ?? 0,
            'babies'           => $req->get_param( 'babies' ) ?? 0,
            'customer_name'    => $req->get_param( 'customer_name' ),
            'customer_email'   => $req->get_param( 'customer_email' ),
            'customer_phone'   => $req->get_param( 'customer_phone' ) ?? '',
            'special_requests' => $req->get_param( 'special_requests' ) ?? '',
            'lang'             => $lang,
        ] );

        if ( ! $result->success ) {
            return new \WP_REST_Response( [ 'error' => $result->error ], 422 );
        }

        $this->maybe_notify_threshold_reached( $tour );

        return rest_ensure_response( [
            'success'     => true,
            'booking_ref' => $result->booking_ref,
            'message'     => $lang === 'en'
                ? 'You\'re on the list — we\'ll email you a payment link if this tour opens.'
                : 'Listo, quedaste anotado — si este tour se abre, te mandamos el link de pago por email.',
        ] );
    }

    // ── Notificar al admin cuando se alcanza el umbral ────────────────────

    private function maybe_notify_threshold_reached( object $tour ): void {
        $threshold = (int) $tour->wishlist_threshold;
        if ( $threshold <= 0 || ! empty( $tour->wishlist_notified_at ) ) {
            return; // sin umbral configurado, o ya se avisó una vez
        }

        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amir_bookings WHERE tour_id = %d AND status = 'wishlist'",
            $tour->id
        ) );

        if ( $count < $threshold ) {
            return;
        }

        $tour_name = $tour->name_es ?: $tour->name_en;

        $wpdb->insert( "{$wpdb->prefix}amir_notifications", [
            'type'    => 'wishlist_threshold',
            'title'   => 'Umbral de interés alcanzado',
            'message' => sprintf(
                '"%s" alcanzó %d personas interesadas (umbral: %d). Revisa la Lista de interés para publicarlo.',
                $tour_name, $count, $threshold
            ),
            'data'    => json_encode( [ 'tour_id' => $tour->id ] ),
            'is_read' => 0,
        ], [ '%s', '%s', '%s', '%s', '%d' ] );

        $wpdb->update(
            "{$wpdb->prefix}amir_tours",
            [ 'wishlist_notified_at' => current_time( 'mysql' ) ],
            [ 'id' => $tour->id ],
            [ '%s' ], [ '%d' ]
        );

        $admin_email = get_option( 'amir_admin_email', get_option( 'admin_email' ) );
        if ( $admin_email ) {
            wp_mail(
                $admin_email,
                '[Lista de interés] "' . $tour_name . '" alcanzó ' . $count . ' interesados',
                sprintf(
                    "El tour \"%s\" alcanzó %d personas anotadas en la lista de interés (umbral configurado: %d).\n\n" .
                    "Revisa y publícalo desde el panel: %s",
                    $tour_name, $count, $threshold,
                    admin_url( 'admin.php?page=amir-wishlist' )
                )
            );
        }
    }
}
