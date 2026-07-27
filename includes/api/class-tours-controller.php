<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/amir/v1/tours
 * GET /wp-json/amir/v1/tours/{id}
 * GET /wp-json/amir/v1/tours/{id}/schedules
 * GET /wp-json/amir/v1/tours/{id}/prices
 */
class ToursController {

    private const NAMESPACE = 'amir/v1';

    public function register_routes(): void {

        register_rest_route( self::NAMESPACE, '/tours', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_tours' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/tours/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_tour' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'lang' => [ 'default' => 'es', 'enum' => \AmirBooking\Core\Languages::active() ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/tours/(?P<id>\d+)/schedules', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_schedules' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/tours/(?P<id>\d+)/prices', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_prices' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── GET /tours ────────────────────────────────────────────────────────

    public function get_tours( \WP_REST_Request $req ): \WP_REST_Response {
        $lang = sanitize_key( $req->get_param( 'lang' ) ?: 'es' );

        $cached = get_transient( "amir_tours_list_{$lang}" );
        if ( $cached !== false ) {
            return rest_ensure_response( $cached );
        }

        // content_i18n a propósito NO está en este SELECT: el resumen solo
        // necesita name/description, que para es/en (el 99% de las vistas)
        // ni siquiera lo tocan — y así esta lista no depende de que esa
        // columna exista para funcionar (ver Languages::tour_field(), cae
        // a español si content_i18n falta).
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, slug, price_model, sort_order,
                    name_es, name_en,
                    description_es, description_en,
                    duration_minutes, min_age, max_capacity,
                    gallery_images, languages
             FROM {$wpdb->prefix}amir_tours
             WHERE status = 'active'
             ORDER BY sort_order ASC, id ASC"
        );

        // Si la tabla está vacía, sincronizar desde el CPT y volver a intentar
        if ( empty( $rows ) ) {
            $this->sync_all_from_cpt();
            $rows = $wpdb->get_results(
                "SELECT id, slug, price_model, sort_order,
                        name_es, name_en,
                        description_es, description_en,
                        duration_minutes, min_age, max_capacity,
                        gallery_images, languages
                 FROM {$wpdb->prefix}amir_tours
                 WHERE status = 'active'
                 ORDER BY sort_order ASC, id ASC"
            );
        }

        $tours = array_map( fn( $r ) => $this->format_tour_summary( $r, $lang ), $rows ?? [] );

        if ( ! empty( $tours ) ) {
            set_transient( "amir_tours_list_{$lang}", $tours, 10 * MINUTE_IN_SECONDS );
        }

        return rest_ensure_response( $tours );
    }

    // ── GET /tours/{id} ───────────────────────────────────────────────────

    public function get_tour( \WP_REST_Request $req ): \WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $lang = sanitize_key( $req->get_param( 'lang' ) ?: 'es' );

        $cached = get_transient( "amir_tour_{$id}_{$lang}" );
        if ( $cached !== false ) {
            return rest_ensure_response( $cached );
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_tours WHERE id = %d AND status = 'active'",
                $id
            )
        );

        // Si no se encuentra, intentar sincronizar y buscar de nuevo
        if ( ! $row ) {
            $this->sync_all_from_cpt();
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}amir_tours WHERE id = %d AND status = 'active'",
                    $id
                )
            );
        }

        if ( ! $row ) {
            return new \WP_REST_Response( [ 'error' => 'Tour not found' ], 404 );
        }

        $data = $this->format_tour_full( $row, $lang );

        // Incluir horarios, precios y servicios extra en la respuesta completa
        $data['schedules'] = $this->fetch_schedules( $id, $lang );
        $data['prices']    = $this->fetch_prices( $id );
        $data['addons']    = $this->fetch_addons( $id, $lang );

        set_transient( "amir_tour_{$id}_{$lang}", $data, 10 * MINUTE_IN_SECONDS );

        return rest_ensure_response( $data );
    }

    // ── Auto-sincronización desde CPT ─────────────────────────────────────

    private function sync_all_from_cpt(): void {
        static $synced = false;
        if ( $synced ) {
            return;
        }
        $synced = true;

        global $wpdb;

        $posts = get_posts( [
            'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );

        foreach ( $posts as $post ) {
            $existing_id = (int) get_post_meta( $post->ID, '_amir_tour_db_id', true );
            if ( $existing_id ) {
                continue;
            }

            $inserted = $wpdb->insert( "{$wpdb->prefix}amir_tours", [
                'slug'           => $post->post_name ?: sanitize_title( $post->post_title ),
                'status'         => 'active',
                'price_model'    => get_post_meta( $post->ID, '_amir_price_model', true ) ?: 'percapita',
                'name_es'        => $post->post_title,
                'name_en'        => get_post_meta( $post->ID, '_amir_name_en', true ) ?: $post->post_title,
                'description_es' => wp_strip_all_tags( $post->post_content ),
                'duration_minutes' => (int) get_post_meta( $post->ID, '_amir_duration_minutes', true ),
                'min_age'        => (int) get_post_meta( $post->ID, '_amir_min_age', true ),
                'max_capacity'   => (int) get_post_meta( $post->ID, '_amir_max_capacity', true ) ?: 10,
                'min_passengers' => (int) get_post_meta( $post->ID, '_amir_min_passengers', true ) ?: 1,
                'languages'      => '["Español"]',
                'gallery_images' => '[]',
                'sort_order'     => (int) get_post_meta( $post->ID, '_amir_sort_order', true ),
            ] );

            if ( $inserted ) {
                update_post_meta( $post->ID, '_amir_tour_db_id', (int) $wpdb->insert_id );
            }
        }

        foreach ( \AmirBooking\Core\Languages::active() as $active_lang ) {
            delete_transient( "amir_tours_list_{$active_lang}" );
        }
    }

    // ── GET /tours/{id}/schedules ─────────────────────────────────────────

    public function get_schedules( \WP_REST_Request $req ): \WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $lang = sanitize_key( $req->get_param( 'lang' ) ?: 'es' );
        return rest_ensure_response( $this->fetch_schedules( $id, $lang ) );
    }

    // ── GET /tours/{id}/prices ────────────────────────────────────────────

    public function get_prices( \WP_REST_Request $req ): \WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        return rest_ensure_response( $this->fetch_prices( $id ) );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function fetch_schedules( int $tour_id, string $lang ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, time_start, time_end, label_es, label_en, sort_order
                 FROM {$wpdb->prefix}amir_tour_schedules
                 WHERE tour_id = %d AND active = 1
                 ORDER BY sort_order ASC, time_start ASC",
                $tour_id
            )
        ) ?? [];

        return array_map( fn( $s ) => [
            'id'         => (int) $s->id,
            'time_start' => $s->time_start,
            'time_end'   => $s->time_end,
            'label'      => $lang === 'en' ? $s->label_en : $s->label_es,
        ], $rows );
    }

    private function fetch_prices( int $tour_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT person_type, group_min, group_max, price_mxn, schedule_id, valid_from, valid_until
                 FROM {$wpdb->prefix}amir_prices
                 WHERE tour_id = %d
                   AND ( valid_from  IS NULL OR valid_from  <= CURDATE() )
                   AND ( valid_until IS NULL OR valid_until >= CURDATE() )
                 ORDER BY person_type, group_min",
                $tour_id
            )
        ) ?? [];

        return array_map( fn( $p ) => [
            'person_type' => $p->person_type,
            'group_min'   => $p->group_min  ? (int) $p->group_min  : null,
            'group_max'   => $p->group_max  ? (int) $p->group_max  : null,
            'price_mxn'   => (float) $p->price_mxn,
            'schedule_id' => $p->schedule_id ? (int) $p->schedule_id : null,
        ], $rows );
    }

    private function fetch_addons( int $tour_id, string $lang ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, pricing_type, name_es, name_en, content_i18n, price_mxn
                 FROM {$wpdb->prefix}amir_addons
                 WHERE tour_id = %d AND active = 1
                 ORDER BY sort_order ASC, id ASC",
                $tour_id
            )
        ) ?? [];

        return array_map( fn( $a ) => [
            'id'           => (int) $a->id,
            'name'         => \AmirBooking\Core\Languages::tour_field( $a, 'name', $lang ),
            'price_mxn'    => (float) $a->price_mxn,
            'pricing_type' => $a->pricing_type,
        ], $rows );
    }

    private function format_tour_summary( object $r, string $lang ): array {
        $gallery    = json_decode( $r->gallery_images ?: '[]', true );
        $desc_raw   = \AmirBooking\Core\Languages::tour_field( $r, 'description', $lang );
        $short_desc = mb_substr( wp_strip_all_tags( $desc_raw ?: '' ), 0, 120 );
        return [
            'id'                => (int) $r->id,
            'slug'              => $r->slug,
            'price_model'       => $r->price_model,
            'name'              => \AmirBooking\Core\Languages::tour_field( $r, 'name', $lang ),
            'short_description' => $short_desc,
            'duration_minutes'  => (int) $r->duration_minutes,
            'min_age'           => (int) $r->min_age,
            'cover_image'       => $gallery[0] ?? '',
            'languages'         => json_decode( $r->languages ?: '[]', true ),
        ];
    }

    /** includes/excludes vienen como JSON string (columnas es/en) o array nativo (content_i18n) — normaliza a array. */
    private function as_array( $value ): array {
        return is_array( $value ) ? $value : ( json_decode( $value ?: '[]', true ) ?: [] );
    }

    private function format_tour_full( object $r, string $lang ): array {
        $L = \AmirBooking\Core\Languages::class;
        return [
            'id'               => (int) $r->id,
            'slug'             => $r->slug,
            'price_model'      => $r->price_model,
            'name'             => $L::tour_field( $r, 'name', $lang ),
            'description'      => $L::tour_field( $r, 'description', $lang ),
            'what_to_expect'   => $L::tour_field( $r, 'what_to_expect', $lang ),
            'itinerary'        => $L::tour_field( $r, 'itinerary', $lang ),
            'meeting_point'    => $L::tour_field( $r, 'meeting_point', $lang ),
            'meeting_lat'      => $r->meeting_lat  ? (float) $r->meeting_lat  : null,
            'meeting_lng'      => $r->meeting_lng  ? (float) $r->meeting_lng  : null,
            'includes'         => $this->as_array( $L::tour_field( $r, 'includes', $lang ) ),
            'excludes'         => $this->as_array( $L::tour_field( $r, 'excludes', $lang ) ),
            'duration_minutes' => (int) $r->duration_minutes,
            'min_age'          => (int) $r->min_age,
            'allow_children'   => (bool) $r->allow_children,
            'allow_babies'     => (bool) $r->allow_babies,
            'min_age_child'    => (int) $r->min_age_child,
            'max_capacity'     => (int) $r->max_capacity,
            'min_passengers'   => (int) $r->min_passengers,
            'languages'        => json_decode( $r->languages ?: '[]', true ),
            'gallery_images'   => json_decode( $r->gallery_images ?: '[]', true ),
        ];
    }
}
