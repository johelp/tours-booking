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

        register_rest_route( self::NAMESPACE, '/tour-categories', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_tour_categories' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── GET /tours ────────────────────────────────────────────────────────

    public function get_tours( \WP_REST_Request $req ): \WP_REST_Response {
        $lang        = sanitize_key( $req->get_param( 'lang' ) ?: 'es' );
        $category    = sanitize_title( (string) ( $req->get_param( 'category' ) ?: '' ) );
        $provider_id = (int) ( $req->get_param( 'provider_id' ) ?: 0 );
        $source      = (string) ( $req->get_param( 'source' ) ?: 'all' );
        if ( ! in_array( $source, [ 'all', 'own', 'provider' ], true ) ) {
            $source = 'all';
        }
        if ( $provider_id ) {
            $source = 'provider'; // un provider_id puntual implica que se está filtrando a proveedores
        }
        $has_filters = ( $category !== '' || $source !== 'all' || $provider_id );

        // Filtrar cambia el resultado, así que no puede compartir la misma
        // clave de cache que el listado sin filtrar (§ 15.7/15.8 CONTRIBUTING.md)
        // — solo el caso sin filtros (el más común, grillas normales) usa la
        // clave de siempre; las combinaciones filtradas cachean aparte.
        $cache_key = $has_filters
            ? 'amir_tours_list_' . md5( "{$lang}|{$category}|{$source}|{$provider_id}" )
            : "amir_tours_list_{$lang}";

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return rest_ensure_response( $cached );
        }

        // content_i18n a propósito NO está en este SELECT: el resumen solo
        // necesita name/description, que para es/en (el 99% de las vistas)
        // ni siquiera lo tocan — y así esta lista no depende de que esa
        // columna exista para funcionar (ver Languages::tour_field(), cae
        // a español si content_i18n falta).
        global $wpdb;
        // hide_from_lists = 0 (§ 16.21 CONTRIBUTING.md, 2026-08-04) — tours
        // de "venta separada" (ej. un traslado) que solo se reservan desde
        // su propia ficha, nunca listados acá.
        $select_sql = "SELECT id, slug, price_model, sort_order,
                    name_es, name_en,
                    description_es, description_en,
                    duration_minutes, min_age, max_capacity,
                    gallery_images, languages, category_slugs, provider_id
             FROM {$wpdb->prefix}amir_tours
             WHERE status = 'active' AND hide_from_lists = 0
             ORDER BY sort_order ASC, id ASC";
        $rows = $wpdb->get_results( $select_sql );

        // Si la tabla está vacía, sincronizar desde el CPT y volver a intentar
        if ( empty( $rows ) ) {
            $this->sync_all_from_cpt();
            $rows = $wpdb->get_results( $select_sql );
        }

        $rows = $rows ?? [];

        // Filtros en PHP, no SQL — el catálogo típico es de pocas decenas de
        // tours, y category_slugs/provider_id no están indexados para esto
        // (JSON el primero); no vale la pena la complejidad de un LIKE/JSON_CONTAINS
        // para este volumen.
        if ( $category !== '' ) {
            $rows = array_values( array_filter( $rows, function ( $r ) use ( $category ) {
                $slugs = json_decode( $r->category_slugs ?: '[]', true );
                return is_array( $slugs ) && in_array( $category, $slugs, true );
            } ) );
        }
        if ( $source === 'own' ) {
            $rows = array_values( array_filter( $rows, fn( $r ) => empty( $r->provider_id ) ) );
        } elseif ( $source === 'provider' ) {
            $rows = array_values( array_filter( $rows, fn( $r ) => ! empty( $r->provider_id ) ) );
            if ( $provider_id ) {
                $rows = array_values( array_filter( $rows, fn( $r ) => (int) $r->provider_id === $provider_id ) );
            }
        }

        $tours = array_map( fn( $r ) => $this->format_tour_summary( $r, $lang ), $rows );

        if ( ! empty( $tours ) ) {
            set_transient( $cache_key, $tours, 10 * MINUTE_IN_SECONDS );
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
                'category_slugs' => json_encode( wp_get_post_terms( $post->ID, \AmirBooking\CPT\TourPostType::TAXONOMY, [ 'fields' => 'slugs' ] ) ?: [] ),
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

    /**
     * Público porque TourFlow\Discovery\DiscoveryController también lo
     * necesita (endpoints de destacados/catálogo por rango de fechas, § 16.15
     * CONTRIBUTING.md) — mismo shape de tour resumido, no hay que duplicarlo.
     */
    public function format_tour_summary( object $r, string $lang ): array {
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
            'max_capacity'      => (int) $r->max_capacity,
            'cover_image'       => $gallery[0] ?? '',
            'gallery_images'    => $gallery,
            'languages'         => json_decode( $r->languages ?: '[]', true ),
            'categories'        => \AmirBooking\Core\Languages::categories( $r ),
            'provider_id'       => ! empty( $r->provider_id ) ? (int) $r->provider_id : null,
            'permalink'         => $this->tour_permalink( (int) $r->id ),
            // "Desde $X" para las tarjetas de catálogo (flujo Explorar,
            // § 16.46 CONTRIBUTING.md) — null en tours de precio por grupo
            // (person_type='group', no 'adult') o sin ningún precio cargado.
            'from_price_mxn'    => ( new \AmirBooking\Core\PricingEngine() )->min_adult_price( (int) $r->id ),
        ];
    }

    /**
     * URL pública de la ficha del tour — amir_tours.id no es el post ID de
     * WordPress (son tablas separadas, ver _amir_tour_db_id en
     * TourPostType::sync_to_db()), así que se resuelve igual que
     * PartnerTracker::get_partner_tracking_url()/los widgets de Elementor:
     * buscar el post por su postmeta _amir_tour_db_id. '' si no se
     * encuentra (tour eliminado del CPT pero la fila de DB sigue viva, caso
     * borde) — el frontend decide qué hacer con eso (ocultar el link).
     */
    private function tour_permalink( int $tour_db_id ): string {
        $post = get_posts( [
            'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
            'meta_key'       => '_amir_tour_db_id',
            'meta_value'     => $tour_db_id,
            'posts_per_page' => 1,
        ] );
        return $post ? (string) get_permalink( $post[0]->ID ) : '';
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
            // Bug real reportado por el cliente (2026-08-14): [flow_tour_dates]
            // mostraba las fechas pero los pills no eran clickeables — TourDates.jsx
            // arma el link con tour.permalink, que format_tour_summary() ya
            // resuelve (tour_permalink() de acá abajo) pero acá, en el detalle
            // completo que consume GET /tours/{id}, nunca se incluía.
            'permalink'        => $this->tour_permalink( (int) $r->id ),
            'price_model'      => $r->price_model,
            'name'             => $L::tour_field( $r, 'name', $lang ),
            'description'      => $L::tour_field( $r, 'description', $lang ),
            'what_to_expect'   => $L::tour_field( $r, 'what_to_expect', $lang ),
            'highlights'       => $this->as_array( $L::tour_field( $r, 'highlights', $lang ) ),
            'itinerary'        => $L::tour_field( $r, 'itinerary', $lang ),
            'itinerary_stops'  => $L::itinerary_stops( $r, $lang ),
            'detail_facts'     => $L::detail_facts( $r, $lang ),
            // FAQ opcional por tour (§ 16.93 CONTRIBUTING.md, pedido 2026-08-25).
            'faq_items'        => $L::faq_items( $r, $lang ),
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
            // Tour de fecha fija (evento único, pedido 2026-08-06) — el
            // widget clásico saltea el calendario cuando viene seteado
            // (BookingWidget.jsx, needsDateStep()).
            'fixed_date'       => $r->fixed_date ?: null,
            // Tour "solo a pedido" — el calendario se muestra normal, pero
            // el widget avisa que la reserva queda sujeta a aprobación y
            // reemplaza el paso de pago por "enviar solicitud" (needsPaymentStep()).
            'request_only'     => (bool) $r->request_only,
            // "Armá tu tour" — variante de request_only sin precio/horario
            // fijo (2026-08-16): el widget saltea fecha+horario+precio
            // (needsDateStep()) y muestra "A cotizar" en vez de un total.
            'custom_quote'     => (bool) $r->custom_quote,
            // "Requiere nombre de cada integrante" (pedido 2026-08-24) — el
            // widget pide un input de nombre por adulto+niño cuando viene
            // en true (StepDetails). Ver BookingManager::participant_names_error().
            'require_participant_names' => (bool) $r->require_participant_names,
            'max_capacity'     => (int) $r->max_capacity,
            'min_passengers'   => (int) $r->min_passengers,
            'languages'        => json_decode( $r->languages ?: '[]', true ),
            'gallery_images'   => json_decode( $r->gallery_images ?: '[]', true ),
            'categories'       => \AmirBooking\Core\Languages::categories( $r ),
            'provider_id'      => ! empty( $r->provider_id ) ? (int) $r->provider_id : null,
            // Video de la ficha (§ 16.46 CONTRIBUTING.md) — URL cruda, sin
            // parsear: los clientes REST (React/headless) resuelven
            // proveedor+id como necesiten, mismo criterio que
            // RoomBookingController ya usa para flow_rooms.video_url.
            'video_url'        => $r->video_url ?: null,
            // Mismo helper que ya usa format_tour_summary() para el "desde
            // $X" de las tarjetas de catálogo — sumado acá para el selector
            // de variantes ([flow_booking_variants], § 16.93 CONTRIBUTING.md)
            // que necesita precio por tour SIN pasar por el listado (las
            // variantes de un mismo producto suelen vivir con
            // hide_from_lists=1, así que GET /tours no las devuelve).
            'from_price_mxn'   => ( new \AmirBooking\Core\PricingEngine() )->min_adult_price( (int) $r->id ),
        ];
    }

    // ── GET /tour-categories ──────────────────────────────────────────────
    // Categorías con al menos un tour activo asignado — pensado para que un
    // filtro interactivo (§ 15.7 CONTRIBUTING.md, todavía sin construir) las
    // liste sin hardcodear nada; sirve igual ya para el generador de
    // shortcode y para cualquier cliente headless.

    public function get_tour_categories( \WP_REST_Request $req ): \WP_REST_Response {
        $cached = get_transient( 'amir_tour_categories' );
        if ( $cached !== false ) {
            return rest_ensure_response( $cached );
        }

        global $wpdb;
        $rows = $wpdb->get_col( "SELECT category_slugs FROM {$wpdb->prefix}amir_tours WHERE status = 'active' AND category_slugs IS NOT NULL AND category_slugs <> ''" );

        $slugs = [];
        foreach ( $rows as $json ) {
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) ) {
                $slugs = array_merge( $slugs, $decoded );
            }
        }
        $slugs = array_unique( $slugs );

        $taxonomy = \AmirBooking\CPT\TourPostType::TAXONOMY;
        $categories = [];
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $categories[] = [
                'slug'    => $term->slug,
                'name_es' => $term->name,
                'name_en' => get_term_meta( $term->term_id, 'amir_category_name_en', true ) ?: $term->name,
            ];
        }

        set_transient( 'amir_tour_categories', $categories, 10 * MINUTE_IN_SECONDS );
        return rest_ensure_response( $categories );
    }
}
