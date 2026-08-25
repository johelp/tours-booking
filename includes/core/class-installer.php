<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la instalación, actualización y desinstalación
 * de las tablas de base de datos del plugin.
 *
 * Multisite: cada site tiene sus propias tablas (wp_N_amir_*).
 * $wpdb->prefix ya incluye el blog ID cuando se llama desde el contexto
 * correcto o después de switch_to_blog(). No se necesita lógica extra
 * en create_tables() — el prefijo se resuelve automáticamente.
 */
class Installer {

    // Tablas en orden de creación (respeta foreign keys lógicas)
    private const TABLES = [
        'tours',
        'tour_schedules',
        'prices',
        'addons',
        'availability_rules',
        'partners',
        'providers',
        'bookings',
        'booking_addons',
        'notifications',
        'payment_events',
        'coupons',
        'provider_payouts',
    ];

    // ── Activación ────────────────────────────────────────────────────────

    /**
     * Hook de activación. WordPress pasa $network_wide = true cuando
     * el plugin se activa desde el Network Admin para toda la red.
     */
    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            self::network_activate();
        } else {
            self::activate_for_blog( get_current_blog_id() );
        }

        // El rol se registra una sola vez (es de red en Multisite)
        TourManagerRole::register();
        flush_rewrite_rules();
    }

    /**
     * Activa el plugin en todos los sites existentes de la red.
     * Se llama solo cuando el Super Admin activa desde Network Admin.
     */
    public static function network_activate(): void {
        $sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
        foreach ( $sites as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            self::activate_for_blog( (int) $blog_id );
            restore_current_blog();
        }
    }

    /**
     * Crea tablas y datos iniciales para un site específico.
     * Reutilizado en activación individual, de red y en alta de nuevo site.
     */
    public static function activate_for_blog( int $blog_id ): void {
        if ( is_multisite() ) {
            switch_to_blog( $blog_id );
        }

        self::create_tables();
        self::insert_default_data();
        self::schedule_cron_jobs();

        // Bug real confirmado en producción: reactivar el plugin (ej.
        // desactivar/activar de nuevo, o un update que lo reinicia) NO
        // corría ninguna migración — create_tables() usa dbDelta(), que en
        // una tabla YA existente no altera columnas/ENUMs que cambiaron de
        // definición (ej. amir_bookings.status sin 'date_requested'), y acá
        // abajo se pisaba amir_db_version al valor actual de todos modos,
        // como si la migración sí hubiera corrido. Resultado: un sitio que
        // reactiva el plugin puede quedar con el schema viejo para
        // siempre, porque maybe_update() nunca vuelve a intentarlo (ve
        // amir_db_version == AMIR_DB_VERSION y no hace nada). Corrección:
        // correr maybe_update() ACÁ, antes de pisar la versión — lee la
        // versión vieja todavía guardada y aplica toda la cadena de
        // ALTER TABLE pendiente de verdad, no solo el CREATE TABLE inicial.
        self::maybe_update();

        update_option( 'amir_db_version',   AMIR_DB_VERSION );
        update_option( 'amir_installed_at', current_time( 'mysql' ) );

        if ( is_multisite() ) {
            restore_current_blog();
        }
    }

    // ── Hook: nuevo site añadido a la red ─────────────────────────────────

    /**
     * WP 5.1+ usa la acción wp_initialize_site.
     * Versiones anteriores usan wpmu_new_blog.
     * Plugin.php registra ambas para compatibilidad.
     */
    public static function on_new_site( $new_site ): void {
        // $new_site puede ser un objeto WP_Site (WP 5.1+)
        $blog_id = is_object( $new_site ) ? (int) $new_site->blog_id : (int) $new_site;

        if ( ! is_plugin_active_for_network( plugin_basename( AMIR_PLUGIN_FILE ) ) ) {
            return; // El plugin no está activado en red — no hacer nada
        }

        self::activate_for_blog( $blog_id );
    }

    /**
     * Fallback para WordPress < 5.1 (acción wpmu_new_blog).
     */
    public static function on_wpmu_new_blog( int $blog_id ): void {
        self::on_new_site( $blog_id );
    }

    // ── Desactivación ─────────────────────────────────────────────────────

    public static function deactivate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            $sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
            foreach ( $sites as $blog_id ) {
                switch_to_blog( (int) $blog_id );
                self::unschedule_cron_jobs();
                restore_current_blog();
            }
        } else {
            self::unschedule_cron_jobs();
        }
        flush_rewrite_rules();
    }

    // ── Desinstalación ────────────────────────────────────────────────────

    public static function uninstall(): void {
        if ( is_multisite() ) {
            $sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
            foreach ( $sites as $blog_id ) {
                switch_to_blog( (int) $blog_id );
                self::uninstall_for_blog();
                restore_current_blog();
            }
            // Opciones de red
            delete_site_option( 'amir_license_key' );
            delete_site_option( 'amir_license_plan' );
        } else {
            self::uninstall_for_blog();
        }

        TourManagerRole::remove();
    }

    private static function uninstall_for_blog(): void {
        if ( get_option( 'amir_delete_data_on_uninstall', false ) ) {
            self::drop_tables();
        }
        self::delete_options();
    }

    // ── Creación de tablas ────────────────────────────────────────────────
    // $wpdb->prefix se resuelve automáticamente al contexto del blog actual.

    private static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── amir_tours ────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_tours (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug            VARCHAR(100) NOT NULL,
            status          ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
            price_model     ENUM('percapita','group') NOT NULL DEFAULT 'percapita',
            name_es         VARCHAR(255) NOT NULL DEFAULT '',
            name_en         VARCHAR(255) NOT NULL DEFAULT '',
            description_es  LONGTEXT,
            description_en  LONGTEXT,
            what_to_expect_es  TEXT,
            what_to_expect_en  TEXT,
            email_extra_note_es TEXT,
            email_extra_note_en TEXT,
            highlights_es      TEXT,
            highlights_en      TEXT,
            duration_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            min_age            TINYINT UNSIGNED NOT NULL DEFAULT 0,
            allow_children     TINYINT(1) NOT NULL DEFAULT 1,
            allow_babies       TINYINT(1) NOT NULL DEFAULT 1,
            min_age_child      TINYINT UNSIGNED NOT NULL DEFAULT 4,
            max_capacity       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            min_passengers     TINYINT UNSIGNED NOT NULL DEFAULT 1,
            languages          VARCHAR(255) DEFAULT '[]',
            meeting_point_es   TEXT,
            meeting_point_en   TEXT,
            meeting_lat        DECIMAL(10,8),
            meeting_lng        DECIMAL(11,8),
            includes_es        TEXT DEFAULT '[]',
            includes_en        TEXT DEFAULT '[]',
            excludes_es        TEXT DEFAULT '[]',
            excludes_en        TEXT DEFAULT '[]',
            gallery_images     TEXT DEFAULT '[]',
            itinerary_es       LONGTEXT,
            itinerary_en       LONGTEXT,
            itinerary_stops    LONGTEXT,
            detail_facts       LONGTEXT,
            faq_items          LONGTEXT,
            content_i18n       LONGTEXT,
            tripadvisor_id     VARCHAR(100) DEFAULT '',
            gyg_id             VARCHAR(100) DEFAULT '',
            sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            featured           TINYINT(1) NOT NULL DEFAULT 0,
            hide_from_lists       TINYINT(1) NOT NULL DEFAULT 0,
            hide_from_suggestions TINYINT(1) NOT NULL DEFAULT 0,
            wishlist_enabled     TINYINT(1) NOT NULL DEFAULT 0,
            wishlist_threshold   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            wishlist_date        DATE DEFAULT NULL,
            wishlist_notified_at DATETIME DEFAULT NULL,
            fixed_date         DATE DEFAULT NULL,
            request_only       TINYINT(1) NOT NULL DEFAULT 0,
            custom_quote       TINYINT(1) NOT NULL DEFAULT 0,
            deposit_enabled    TINYINT(1) NOT NULL DEFAULT 0,
            deposit_pct        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            skip_upsell        TINYINT(1) NOT NULL DEFAULT 0,
            require_participant_names TINYINT(1) NOT NULL DEFAULT 0,
            provider_id        INT UNSIGNED DEFAULT NULL,
            provider_charge_mode ENUM('immediate','on_approval') NOT NULL DEFAULT 'immediate',
            category_slugs     LONGTEXT,
            video_url           VARCHAR(500) DEFAULT NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY sort_order (sort_order),
            KEY provider_id (provider_id),
            KEY featured (featured),
            KEY hide_from_lists (hide_from_lists),
            KEY hide_from_suggestions (hide_from_suggestions)
        ) $charset;" );

        // ── amir_tour_schedules ───────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_tour_schedules (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id     INT UNSIGNED NOT NULL,
            time_start  TIME NOT NULL,
            time_end    TIME NOT NULL,
            label_es    VARCHAR(100) NOT NULL DEFAULT '',
            label_en    VARCHAR(100) NOT NULL DEFAULT '',
            active      TINYINT(1) NOT NULL DEFAULT 1,
            sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY active (active)
        ) $charset;" );

        // ── amir_prices ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_prices (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id      INT UNSIGNED NOT NULL,
            schedule_id  INT UNSIGNED,
            person_type  ENUM('adult','child','baby','group') NOT NULL DEFAULT 'adult',
            group_min    TINYINT UNSIGNED,
            group_max    TINYINT UNSIGNED,
            price_mxn    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            provider_cost_mxn DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            valid_from   DATE,
            valid_until  DATE,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY schedule_id (schedule_id),
            KEY person_type (person_type),
            KEY validity (valid_from, valid_until)
        ) $charset;" );

        // ── amir_addons ───────────────────────────────────────────────────
        // Servicios extra opcionales por tour (alquiler de equipo, cena,
        // etc.). 'per_unit': el cliente elige cantidad, precio × cantidad
        // (tope = adultos+niños de la reserva). 'flat': precio fijo, se
        // agrega o no, sin cantidad. name_es/name_en + content_i18n siguen
        // el mismo patrón multi-idioma que amir_tours (Languages::tour_field()).
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_addons (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id       INT UNSIGNED DEFAULT NULL,
            room_id       INT UNSIGNED DEFAULT NULL,
            applies_to    ENUM('tour','room','both','global') NOT NULL DEFAULT 'tour',
            pricing_type  ENUM('per_unit','flat','digital') NOT NULL DEFAULT 'per_unit',
            name_es       VARCHAR(255) NOT NULL DEFAULT '',
            name_en       VARCHAR(255) NOT NULL DEFAULT '',
            content_i18n  LONGTEXT,
            price_mxn     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            digital_file_url VARCHAR(500) DEFAULT NULL,
            active        TINYINT(1) NOT NULL DEFAULT 1,
            sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY room_id (room_id),
            KEY active (active)
        ) $charset;" );

        // ── amir_availability_rules ───────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_availability_rules (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id     INT UNSIGNED NOT NULL,
            rule_type   ENUM('block','allow') NOT NULL DEFAULT 'block',
            weekdays    VARCHAR(20) NOT NULL DEFAULT '[]',
            date_from   DATE,
            date_until  DATE,
            priority    SMALLINT NOT NULL DEFAULT 10,
            reason      VARCHAR(255) DEFAULT '',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY priority (priority),
            KEY date_range (date_from, date_until)
        ) $charset;" );

        // ── amir_partners ─────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_partners (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name             VARCHAR(255) NOT NULL DEFAULT '',
            email            VARCHAR(255) NOT NULL DEFAULT '',
            phone            VARCHAR(50) DEFAULT '',
            tracking_token   VARCHAR(64) NOT NULL,
            commission_type  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
            commission_value DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            qr_code_url      VARCHAR(500) DEFAULT '',
            active           TINYINT(1) NOT NULL DEFAULT 1,
            notes            TEXT,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tracking_token (tracking_token),
            KEY active (active),
            KEY email (email)
        ) $charset;" );

        // ── amir_providers ────────────────────────────────────────────────
        // Proveedores externos del marketplace (tours de terceros que
        // TourFlow revende con margen propio) — no confundir con
        // amir_partners (afiliados que refieren clientes y cobran comisión).
        // Ver CONTRIBUTING.md § 11 para el spec completo.
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_providers (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            business_name  VARCHAR(255) NOT NULL DEFAULT '',
            contact_name   VARCHAR(255) NOT NULL DEFAULT '',
            email          VARCHAR(255) NOT NULL DEFAULT '',
            phone          VARCHAR(50) DEFAULT '',
            notes          TEXT,
            active         TINYINT(1) NOT NULL DEFAULT 1,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY active (active),
            KEY email (email)
        ) $charset;" );

        // ── amir_bookings ─────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_bookings (
            id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_ref              VARCHAR(20) NOT NULL,
            access_token             VARCHAR(64) DEFAULT NULL,
            item_type                ENUM('tour','room','product') NOT NULL DEFAULT 'tour',
            tour_id                  INT UNSIGNED DEFAULT NULL,
            schedule_id              INT UNSIGNED DEFAULT NULL,
            room_id                  INT UNSIGNED DEFAULT NULL,
            cart_group_id            VARCHAR(36) DEFAULT NULL,
            google_calendar_event_id VARCHAR(255) DEFAULT NULL,
            partner_id               INT UNSIGNED,
            tour_date                DATE NOT NULL,
            check_out_date           DATE DEFAULT NULL,
            status                   ENUM(
                                        'wishlist',
                                        'date_requested',
                                        'date_request_rejected',
                                        'awaiting_payment',
                                        'pending',
                                        'pending_provider_approval',
                                        'confirmed',
                                        'cancellation_requested',
                                        'cancelled_client',
                                        'cancelled_weather',
                                        'cancelled_min_pax',
                                        'cancelled_provider',
                                        'rescheduled',
                                        'completed'
                                     ) NOT NULL DEFAULT 'pending',
            booking_source           ENUM('direct','tripadvisor','getyourguide','partner','manual','wishlist','date_request') NOT NULL DEFAULT 'direct',
            lang                     VARCHAR(5) NOT NULL DEFAULT 'es',
            customer_name            VARCHAR(255) NOT NULL DEFAULT '',
            customer_email           VARCHAR(255) NOT NULL DEFAULT '',
            customer_phone           VARCHAR(50) DEFAULT '',
            adults                   TINYINT UNSIGNED NOT NULL DEFAULT 1,
            children                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
            babies                   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            total_mxn                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            usd_reference            DECIMAL(10,2),
            exchange_rate            DECIMAL(8,4),
            stripe_payment_intent    VARCHAR(255) DEFAULT '',
            stripe_charge_id         VARCHAR(255) DEFAULT '',
            payment_gateway          VARCHAR(20) DEFAULT 'stripe',
            gateway_reference        VARCHAR(255) DEFAULT '',
            gateway_charge_id        VARCHAR(255) DEFAULT '',
            coupon_code              VARCHAR(50) DEFAULT '',
            discount_mxn             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            cancellation_policy_pct  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            refund_amount_mxn        DECIMAL(10,2) DEFAULT 0.00,
            deposit_pct              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            balance_paid_at          DATETIME DEFAULT NULL,
            qr_code_path             VARCHAR(500) DEFAULT '',
            pdf_voucher_path         VARCHAR(500) DEFAULT '',
            special_requests         TEXT,
            participant_names        TEXT,
            internal_notes           TEXT,
            custom_email_note        TEXT,
            review_email_sent_at     DATETIME,
            reminder_sent_at         DATETIME,
            wishlist_notice_sent_at  DATETIME,
            wishlist_notice_error    VARCHAR(255) DEFAULT '',
            provider_response_token   VARCHAR(64) DEFAULT NULL,
            provider_notified_at      DATETIME DEFAULT NULL,
            provider_reminder_sent_at DATETIME DEFAULT NULL,
            provider_responded_at     DATETIME DEFAULT NULL,
            provider_reject_reason    VARCHAR(500) DEFAULT NULL,
            confirmed_at             DATETIME,
            checked_in_at            DATETIME DEFAULT NULL,
            consent_recorded_at      DATETIME DEFAULT NULL,
            consent_text_hash        VARCHAR(64) DEFAULT NULL,
            anonymized_at            DATETIME DEFAULT NULL,
            created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_ref (booking_ref),
            UNIQUE KEY access_token (access_token),
            UNIQUE KEY provider_response_token (provider_response_token),
            KEY tour_id (tour_id),
            KEY schedule_id (schedule_id),
            KEY room_id (room_id),
            KEY item_type (item_type),
            KEY cart_group_id (cart_group_id),
            KEY partner_id (partner_id),
            KEY tour_date (tour_date),
            KEY status (status),
            KEY customer_email (customer_email),
            KEY stripe_payment_intent (stripe_payment_intent),
            KEY gateway_reference (gateway_reference),
            KEY created_at (created_at),
            KEY idx_avail_lookup (tour_id, schedule_id, tour_date, status)
        ) $charset;" );

        // ── amir_booking_addons ───────────────────────────────────────────
        // Servicios extra elegidos en una reserva puntual. Precio y nombre
        // quedan "congelados" al momento de reservar (mismo criterio que
        // amir_bookings.total_mxn) — si el operador después cambia el precio
        // o borra el addon, esta fila no se ve afectada.
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_booking_addons (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id      INT UNSIGNED NOT NULL,
            addon_id        INT UNSIGNED NOT NULL,
            qty             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            unit_price_mxn  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_mxn       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            name_snapshot   VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY addon_id (addon_id)
        ) $charset;" );

        // ── amir_payment_events ───────────────────────────────────────────
        // Log de cada intento de pago: creado, exitoso, rechazado, reembolso.
        // No sustituye el dashboard de la pasarela — es para responder rápido
        // "¿por qué se rechazó esta reserva?" sin salir de WordPress.
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_payment_events (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id   INT UNSIGNED NOT NULL,
            gateway      VARCHAR(20) NOT NULL DEFAULT '',
            event_type   VARCHAR(30) NOT NULL DEFAULT '',
            message      VARCHAR(500) DEFAULT '',
            raw_payload  LONGTEXT,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY gateway (gateway),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset;" );

        // ── amir_coupons ──────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_coupons (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code            VARCHAR(50) NOT NULL,
            discount_type   ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
            discount_value  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            valid_from      DATE,
            valid_until     DATE,
            usage_limit     INT UNSIGNED DEFAULT NULL,
            times_used      INT UNSIGNED NOT NULL DEFAULT 0,
            tour_id         INT UNSIGNED DEFAULT NULL,
            room_id         INT UNSIGNED DEFAULT NULL,
            active          TINYINT(1) NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY active (active),
            KEY tour_id (tour_id),
            KEY room_id (room_id),
            KEY validity (valid_from, valid_until)
        ) $charset;" );

        // ── amir_provider_payouts ─────────────────────────────────────────
        // Ledger manual de liquidación a proveedores del marketplace (§ 11
        // CONTRIBUTING.md). Una fila 'pending' se genera automáticamente al
        // aprobarse cada reserva con proveedor (ver amir_provider_booking_approved
        // en class-plugin.php) — el equipo la marca 'paid' a mano desde
        // Amir Booking → Liquidación cuando efectivamente le paga al proveedor.
        // Sin payout automático vía pasarela — descartado explícitamente por
        // el cliente como "lujo, no necesario ahora".
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_provider_payouts (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id  INT UNSIGNED NOT NULL,
            booking_id   INT UNSIGNED DEFAULT NULL,
            amount_mxn   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status       ENUM('pending','paid') NOT NULL DEFAULT 'pending',
            note         TEXT,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at      DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY provider_id (provider_id),
            KEY booking_id (booking_id),
            KEY status (status)
        ) $charset;" );

        // ── amir_notifications ────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_notifications (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            type        VARCHAR(50) NOT NULL,
            title       VARCHAR(255) NOT NULL DEFAULT '',
            message     TEXT,
            data        TEXT DEFAULT '{}',
            is_read     TINYINT(1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_read (is_read),
            KEY type (type),
            KEY created_at (created_at)
        ) $charset;" );

        // ── flow_rooms — Pro Max, § 16 CONTRIBUTING.md ───────────────────────
        // Primer módulo que usa el prefijo de tabla nuevo (flow_ en vez de
        // amir_, decisión del cliente 2026-07-31 de no seguir usando "amir"
        // en desarrollo nuevo — ver § 15.13). Sin deposit_enabled/percentage
        // acá: la referencia real (Caliafarm, § 16.2) cobra la habitación
        // siempre 100% online, el depósito opcional es solo para
        // tours/experiencias (vive en amir_tours, se suma en la fase de
        // checkout/carrito, § 16.4). ical_import_url: columna preparada
        // desde el día uno (2026-07-31) para sincronizar disponibilidad con
        // Booking.com/Airbnb vía iCal, sentido "importar" (pull) — mismo
        // criterio que provider_id, nullable y sin costo real hoy. El
        // importador en sí NO está construido todavía (prioridad: motor de
        // reservas + proceso de checkout óptimo primero, ver § 16).
        dbDelta( "CREATE TABLE {$wpdb->prefix}flow_rooms (
            id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug                   VARCHAR(100) NOT NULL,
            status                 ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
            name_es                VARCHAR(255) NOT NULL DEFAULT '',
            name_en                VARCHAR(255) NOT NULL DEFAULT '',
            description_es         LONGTEXT,
            description_en         LONGTEXT,
            content_i18n           LONGTEXT,
            capacity_max           SMALLINT UNSIGNED NOT NULL DEFAULT 2,
            min_nights             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            price_per_night        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            default_checkin_time   TIME NOT NULL DEFAULT '15:00:00',
            default_checkout_time  TIME NOT NULL DEFAULT '11:00:00',
            gallery_images         TEXT DEFAULT '[]',
            amenities              LONGTEXT,
            video_url              VARCHAR(500) DEFAULT NULL,
            sort_order             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            provider_id            INT UNSIGNED DEFAULT NULL,
            ical_import_url        VARCHAR(500) DEFAULT NULL,
            created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY sort_order (sort_order),
            KEY provider_id (provider_id)
        ) $charset;" );

        // ── flow_room_bookings — motor de disponibilidad propio (§ 16.5) ─────
        // Intervalo semi-abierto [check_in_date, check_out_date): el choque
        // de disponibilidad se calcula como
        // nueva.check_in < existente.check_out AND nueva.check_out > existente.check_in
        // (ver RoomAvailability::has_conflict()) — el día de checkout ya
        // libera la habitación para un check-in ese mismo día, confirmado
        // con el cliente 2026-07-31.
        dbDelta( "CREATE TABLE {$wpdb->prefix}flow_room_bookings (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id         INT UNSIGNED NOT NULL,
            booking_id      INT UNSIGNED DEFAULT NULL,
            check_in_date   DATE NOT NULL,
            check_out_date  DATE NOT NULL,
            guests          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            status          ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY room_id (room_id),
            KEY booking_id (booking_id),
            KEY dates (check_in_date, check_out_date),
            KEY status (status)
        ) $charset;" );

        // ── flow_room_availability_rules — disponibilidad por temporada ──────
        // (§ 16.11 CONTRIBUTING.md, 2026-08-01) — mismo esquema exacto que
        // amir_availability_rules (tours), room_id en vez de tour_id. Permite
        // "esta habitación solo se ofrece junio-agosto": una regla 'allow'
        // con date_from/date_until, o 'block' para las temporadas cerradas.
        // Primera regla que aplica gana (mayor prioridad primero); sin
        // ninguna regla que aplique, disponible por default — ver
        // RoomAvailability::evaluate_rules(). Sin columna weekdays a
        // propósito (a diferencia de amir_availability_rules): una reserva
        // de habitación son noches consecutivas, bloquear por día de semana
        // suelto no tiene el mismo sentido que en un tour de un solo día.
        dbDelta( "CREATE TABLE {$wpdb->prefix}flow_room_availability_rules (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id     INT UNSIGNED NOT NULL,
            rule_type   ENUM('block','allow') NOT NULL DEFAULT 'block',
            date_from   DATE,
            date_until  DATE,
            priority    SMALLINT NOT NULL DEFAULT 10,
            reason      VARCHAR(255) DEFAULT '',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY room_id (room_id),
            KEY priority (priority),
            KEY date_range (date_from, date_until)
        ) $charset;" );
    }

    // ── Datos por defecto ─────────────────────────────────────────────────

    private static function insert_default_data(): void {
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}amir_tours" );
        if ( $count > 0 ) {
            self::create_verify_page();
            self::create_provider_action_page();
            self::create_discovery_page();
            return;
        }

        add_option( 'amir_currency',             'MXN' );
        add_option( 'amir_booking_ref_prefix',   'BK' );
        add_option( 'amir_active_languages',     wp_json_encode( [ 'es', 'en' ] ) );
        add_option( 'amir_usd_rate_mode',         'auto' );
        add_option( 'amir_usd_rate_manual',       '17.00' );
        add_option( 'amir_stripe_mode',           'test' );
        add_option( 'amir_pending_expire_mins',   '15' );
        add_option( 'amir_review_delay_days',     '1' );
        add_option( 'amir_admin_email',           get_option( 'admin_email' ) );
        add_option( 'amir_delete_data_on_uninstall', '0' );
        add_option( 'amir_provider_reminder_hours',  '24' );
        add_option( 'amir_provider_response_hours',  '48' );

        self::create_verify_page();
        self::create_provider_action_page();
        self::create_discovery_page();
    }

    /**
     * Crea la página /verificar-reserva/ con el shortcode [flow_verify_booking].
     * Seguro de llamar en cada activación — no duplica si ya existe (así que
     * instalaciones viejas con [amir_verify_booking] ya publicado no se
     * tocan, siguen andando vía el alias — ver Plugin::init()).
     */
    private static function create_verify_page(): void {
        $existing_id = (int) get_option( 'amir_verify_page_id', 0 );
        if ( $existing_id && get_post( $existing_id ) ) {
            return;
        }

        $existing = get_posts( array(
            'name'           => 'verificar-reserva',
            'post_type'      => 'page',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 1,
        ) );

        if ( $existing ) {
            update_option( 'amir_verify_page_id', $existing[0]->ID );
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'Verificar Reserva',
            'post_name'    => 'verificar-reserva',
            'post_content' => '[flow_verify_booking]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'amir_verify_page_id', $page_id );
        }
    }

    /**
     * Crea la página /proveedor-reserva/ con el shortcode [flow_provider_action]
     * — donde el proveedor externo aprueba/rechaza una reserva sin login
     * (ver Shortcodes::provider_action(), § 11 CONTRIBUTING.md). Mismo
     * patrón que create_verify_page(), seguro de llamar en cada activación
     * (instalaciones viejas con [amir_provider_action] ya publicado no se
     * tocan, siguen andando vía el alias).
     */
    private static function create_provider_action_page(): void {
        $existing_id = (int) get_option( 'amir_provider_page_id', 0 );
        if ( $existing_id && get_post( $existing_id ) ) {
            return;
        }

        $existing = get_posts( array(
            'name'           => 'proveedor-reserva',
            'post_type'      => 'page',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 1,
        ) );

        if ( $existing ) {
            update_option( 'amir_provider_page_id', $existing[0]->ID );
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'Reserva de Proveedor',
            'post_name'    => 'proveedor-reserva',
            'post_content' => '[flow_provider_action]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'amir_provider_page_id', $page_id );
        }
    }

    /**
     * Crea la página /book/ con [flow_discovery] — punto de entrada del
     * flujo continuo de descubrimiento (Pro Max, § 16.15/16.18 CONTRIBUTING.md).
     * Pedido del cliente 2026-08-04, mismo patrón que create_verify_page()/
     * create_provider_action_page() — seguro de llamar en cada activación,
     * no duplica si ya existe. Solo Pro Max: [flow_discovery] no se registra
     * en otras ediciones (ver Plugin::init()), así que la página quedaría
     * con un shortcode inerte en Lite/Pro.
     */
    private static function create_discovery_page(): void {
        if ( AMIR_EDITION !== 'pro_max' ) {
            return;
        }

        $existing_id = (int) get_option( 'amir_discovery_page_id', 0 );
        if ( $existing_id && get_post( $existing_id ) ) {
            return;
        }

        $existing = get_posts( array(
            'name'           => 'book',
            'post_type'      => 'page',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 1,
        ) );

        if ( $existing ) {
            update_option( 'amir_discovery_page_id', $existing[0]->ID );
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'Book Your Stay',
            'post_name'    => 'book',
            'post_content' => '[flow_discovery mode="experience"]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'amir_discovery_page_id', $page_id );
        }
    }

    // ── Cron jobs ─────────────────────────────────────────────────────────

    private static function schedule_cron_jobs(): void {
        if ( ! wp_next_scheduled( 'amir_hourly_tasks' ) ) {
            wp_schedule_event( time(), 'hourly', 'amir_hourly_tasks' );
        }
        if ( ! wp_next_scheduled( 'amir_daily_tasks' ) ) {
            $next_7am = strtotime( 'today 07:00:00' );
            if ( $next_7am < time() ) {
                $next_7am = strtotime( 'tomorrow 07:00:00' );
            }
            wp_schedule_event( $next_7am, 'daily', 'amir_daily_tasks' );
        }
    }

    private static function unschedule_cron_jobs(): void {
        wp_clear_scheduled_hook( 'amir_hourly_tasks' );
        wp_clear_scheduled_hook( 'amir_daily_tasks' );
    }

    // ── Eliminar tablas ───────────────────────────────────────────────────

    private static function drop_tables(): void {
        global $wpdb;
        foreach ( array_reverse( self::TABLES ) as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}amir_{$table}" );
        }
        // Tablas con el prefijo nuevo (flow_, § 15.13) — no encajan en el
        // patrón amir_{$table} de arriba.
        foreach ( [ 'flow_room_bookings', 'flow_rooms' ] as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
        }
    }

    private static function delete_options(): void {
        $options = array(
            'amir_db_version', 'amir_installed_at', 'amir_currency', 'amir_booking_ref_prefix', 'amir_active_languages',
            'amir_usd_rate_mode', 'amir_usd_rate_manual', 'amir_stripe_mode',
            'amir_stripe_pk_test', 'amir_stripe_sk_test',
            'amir_stripe_pk_live', 'amir_stripe_sk_live',
            'amir_stripe_webhook_secret', 'amir_pending_expire_mins',
            'amir_default_gateway', 'amir_mp_mode',
            'amir_mp_access_token_test', 'amir_mp_access_token_live', 'amir_mp_webhook_secret',
            'amir_review_delay_days', 'amir_admin_email',
            'amir_delete_data_on_uninstall', 'amir_verify_page_id', 'amir_provider_page_id',
            'amir_provider_reminder_hours', 'amir_provider_response_hours',
            'amir_brand_logo_id', 'amir_brand_logo_url', 'amir_brand_color',
            'amir_company_name', 'amir_company_tagline_es', 'amir_company_tagline_en',
            'amir_email_recs_es', 'amir_email_recs_en',
            'amir_voucher_recs_es', 'amir_voucher_recs_en',
            'amir_license_plan', 'amir_license_key',
        );
        foreach ( $options as $option ) {
            delete_option( $option );
        }
    }

    // ── Actualización de DB ───────────────────────────────────────────────

    /**
     * Red de seguridad independiente del gate de versión — ver el comentario
     * en Plugin::init(). Se cachea en un transient de 1h para no pegarle un
     * DESCRIBE a la base en cada request — pero SOLO si las tres columnas
     * quedaron realmente confirmadas, nunca si algún ALTER falló. Antes esto
     * cacheaba "ok" pasara lo que pasara, así que un solo intento fallido
     * (ej. content_i18n con `AFTER itinerary_en` cuando itinerary_en tampoco
     * existía todavía) dejaba el tour_sync roto en silencio para siempre —
     * es la causa real de "Unknown column 'content_i18n'" visto en el sandbox.
     */
    public static function ensure_tour_columns(): void {
        if ( get_transient( 'amir_tour_columns_ok' ) ) {
            return;
        }
        global $wpdb;
        $cols     = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        $missing  = array_diff( [ 'itinerary_es', 'itinerary_en', 'itinerary_stops', 'detail_facts', 'faq_items', 'highlights_es', 'highlights_en', 'content_i18n', 'category_slugs' ], $cols );
        $all_ok   = true;

        foreach ( $missing as $col ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN {$col} LONGTEXT" );
            if ( $wpdb->last_error ) {
                $all_ok = false;
                error_log( "Amir Booking: no se pudo agregar la columna {$col} a amir_tours — " . $wpdb->last_error );
            }
        }

        if ( $all_ok ) {
            set_transient( 'amir_tour_columns_ok', 1, HOUR_IN_SECONDS );
        }
    }

    /**
     * Misma red de seguridad que ensure_tour_columns(), para el ENUM de
     * status/booking_source de amir_bookings — bug real confirmado en
     * producción (visitsicilyexperiences.com, 2026-08-14): un sitio que
     * reactiva el plugin (activate_for_blog() → create_tables() vía
     * dbDelta(), que NO altera ENUMs de columnas ya existentes) quedaba con
     * 'date_requested' faltante en el ENUM para siempre, aunque
     * amir_db_version ya diga estar al día — así que maybe_update() ya no
     * vuelve a intentarlo. Sin 'date_requested' en el ENUM, MySQL guarda la
     * fila con el status inválido convertido a '' (modo no estricto) o
     * rechaza el INSERT (modo estricto) — en cualquier caso, la reserva
     * nunca queda realmente en 'date_requested': no aparece el badge de
     * estado, y la tarjeta "Acciones" del admin no encuentra ningún
     * `if ($b->status === 'date_requested')` que coincida, así que no
     * muestra ni Aprobar ni Rechazar. Chequeo barato (una vez por hora)
     * fuera del gate de versión — corre incluso si activate_for_blog()
     * nunca llegó a correr maybe_update() en este sitio.
     */
    public static function ensure_booking_status_enum(): void {
        if ( get_transient( 'amir_booking_status_enum_ok' ) ) {
            return;
        }
        global $wpdb;
        $all_ok = true;

        $status_col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}amir_bookings LIKE 'status'" );
        // Chequea solo el valor agregado más reciente (date_request_rejected)
        // — si ese falta, date_requested (agregado antes) seguro también
        // puede faltar, así que un solo strpos() cubre ambos casos sin
        // tener que ir sumando un check por cada valor nuevo del ENUM.
        if ( $status_col && strpos( $status_col->Type, 'date_request_rejected' ) === false ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN status ENUM(
                'wishlist','date_requested','date_request_rejected','awaiting_payment','pending',
                'pending_provider_approval','confirmed','cancellation_requested',
                'cancelled_client','cancelled_weather','cancelled_min_pax',
                'cancelled_provider','rescheduled','completed'
            ) NOT NULL DEFAULT 'pending'" );
            if ( $wpdb->last_error ) {
                $all_ok = false;
                error_log( 'Amir Booking: no se pudo ampliar el ENUM de amir_bookings.status — ' . $wpdb->last_error );
            }
        }

        $source_col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}amir_bookings LIKE 'booking_source'" );
        if ( $source_col && strpos( $source_col->Type, 'date_request' ) === false ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN booking_source
                ENUM('direct','tripadvisor','getyourguide','partner','manual','wishlist','date_request')
                NOT NULL DEFAULT 'direct'" );
            if ( $wpdb->last_error ) {
                $all_ok = false;
                error_log( 'Amir Booking: no se pudo ampliar el ENUM de amir_bookings.booking_source — ' . $wpdb->last_error );
            }
        }

        if ( $all_ok ) {
            set_transient( 'amir_booking_status_enum_ok', 1, HOUR_IN_SECONDS );
        }
    }

    /**
     * Ejecuta migraciones de schema si la versión instalada es anterior.
     * En Multisite, cada site tiene su propia amir_db_version.
     */
    public static function maybe_update(): void {
        global $wpdb;
        $installed = get_option( 'amir_db_version', '0.0.0' );
        if ( version_compare( $installed, AMIR_DB_VERSION, '>=' ) ) {
            return;
        }

        // 1.1.0: custom_email_note column + booking_source manual
        $cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_bookings" );
        if ( ! in_array( 'custom_email_note', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN custom_email_note TEXT AFTER internal_notes" );
        }
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN booking_source ENUM('direct','tripadvisor','getyourguide','partner','manual') NOT NULL DEFAULT 'direct'" );

        // 1.2.0: access_token — autoriza lectura pública de una reserva sin
        // depender solo del booking_ref (secuencial y por tanto adivinable).
        if ( ! in_array( 'access_token', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN access_token VARCHAR(64) DEFAULT NULL AFTER booking_ref" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD UNIQUE KEY access_token (access_token)" );
        }
        self::backfill_access_tokens();

        // 1.3.0: columnas genéricas de pasarela de pago (payment_gateway,
        // gateway_reference, gateway_charge_id) para no seguir acoplado a
        // Stripe cuando se sume Mercado Pago. Las reservas existentes
        // quedan con payment_gateway='stripe' (su único gateway posible
        // hasta ahora) y se rellenan desde las columnas viejas.
        if ( ! in_array( 'payment_gateway', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN payment_gateway VARCHAR(20) DEFAULT 'stripe' AFTER stripe_charge_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN gateway_reference VARCHAR(255) DEFAULT '' AFTER payment_gateway" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN gateway_charge_id VARCHAR(255) DEFAULT '' AFTER gateway_reference" );
            $wpdb->query( "UPDATE {$wpdb->prefix}amir_bookings SET payment_gateway = 'stripe' WHERE payment_gateway IS NULL OR payment_gateway = ''" );
            $wpdb->query( "UPDATE {$wpdb->prefix}amir_bookings SET gateway_reference = stripe_payment_intent WHERE (gateway_reference = '' OR gateway_reference IS NULL) AND stripe_payment_intent <> ''" );
            $wpdb->query( "UPDATE {$wpdb->prefix}amir_bookings SET gateway_charge_id = stripe_charge_id WHERE (gateway_charge_id = '' OR gateway_charge_id IS NULL) AND stripe_charge_id <> ''" );
        }

        // 1.4.0: cupones — coupon_code/discount_mxn en la reserva + tabla
        // amir_coupons (creada más abajo por create_tables()).
        if ( ! in_array( 'coupon_code', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN coupon_code VARCHAR(50) DEFAULT '' AFTER gateway_charge_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN discount_mxn DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER coupon_code" );
        }

        // 1.5.0: lista de interés ("avísame cuando abra") para tours en
        // borrador — columnas en amir_tours + tabla amir_tour_interest
        // (esta última la crea create_tables() más abajo, dbDelta no
        // duplica si ya existe).
        $tour_cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'wishlist_enabled', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN wishlist_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN wishlist_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER wishlist_enabled" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN wishlist_notified_at DATETIME DEFAULT NULL AFTER wishlist_threshold" );
        }

        // 1.6.0: la lista de interés pasó a crear reservas reales (no solo
        // registros de contacto) — necesita dos estados nuevos que el cron
        // de expiración de 'pending' (BookingManager::release_expired_pending())
        // debe ignorar: 'wishlist' (interés registrado, tour todavía en
        // borrador, sin cobrar) y 'awaiting_payment' (tour ya abierto, el
        // cliente tiene el link de pago pero todavía no hizo click — recién
        // ahí pasa a 'pending' y arranca el cronómetro de expiración real).
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN status ENUM(
            'wishlist','awaiting_payment','pending','confirmed','cancellation_requested',
            'cancelled_client','cancelled_weather','cancelled_min_pax','rescheduled','completed'
        ) NOT NULL DEFAULT 'pending'" );
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN booking_source ENUM('direct','tripadvisor','getyourguide','partner','manual','wishlist') NOT NULL DEFAULT 'direct'" );

        // wishlist_date: la fecha fija del tour/retiro al que la gente
        // muestra interés (no hay calendario de disponibilidad — el motor
        // de disponibilidad exige status='active', y estos tours siguen en
        // borrador a propósito). La carga el operador al activar la lista
        // de interés.
        if ( ! in_array( 'wishlist_date', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN wishlist_date DATE DEFAULT NULL AFTER wishlist_threshold" );
        }

        // 1.6.1: la lista de interés pasó a usar reservas reales
        // (amir_bookings, status='wishlist') en vez de esta tabla aparte —
        // quedó huérfana, nunca llegó a una versión estable.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}amir_tour_interest" );

        // 1.6.2: el panel "Emails enviados" del admin no tenía forma de saber
        // si el email de "tu tour ya abrió" (wishlist → awaiting_payment) se
        // había mandado — solo miraba confirmed_at/reminder_sent_at/review_email_sent_at.
        // Se guarda también el error puntual si wp_mail() falla, para no
        // depender del error_log del servidor para diagnosticarlo.
        if ( ! in_array( 'wishlist_notice_sent_at', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN wishlist_notice_sent_at DATETIME DEFAULT NULL AFTER reminder_sent_at" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN wishlist_notice_error VARCHAR(255) DEFAULT '' AFTER wishlist_notice_sent_at" );
        }

        // 1.7.0: soporte real de N idiomas (antes solo es/en hardcodeado).
        // `lang` deja de ser un ENUM de 2 valores — el operador activa los
        // idiomas que quiera desde Configuración (amir_active_languages) sin
        // que un desarrollador toque el esquema. `content_i18n` guarda el
        // contenido de tours para cualquier idioma más allá de es/en (esos
        // dos siguen usando las columnas name_es/en, description_es/en, etc.
        // de siempre — no se migran, cero riesgo sobre lo ya probado).
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN lang VARCHAR(5) NOT NULL DEFAULT 'es'" );
        // itinerary_es/en y content_i18n: dbDelta() de create_tables() no las
        // venía agregando de forma confiable a instalaciones existentes (visto
        // en vivo — quedaban faltando en el sandbox, rompiendo en silencio
        // TODO guardado de tour con "Unknown column"). Ninguna lleva `AFTER`
        // (esa cláusula fallaba si la columna de referencia tampoco existía
        // todavía — justo lo que pasaba acá con itinerary_en). Ver también
        // ensure_tour_columns(), la misma corrección corre en cada request
        // como red de seguridad fuera del gate de versión.
        if ( ! in_array( 'itinerary_es', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN itinerary_es LONGTEXT" );
        }
        if ( ! in_array( 'itinerary_en', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN itinerary_en LONGTEXT" );
        }
        if ( ! in_array( 'content_i18n', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN content_i18n LONGTEXT" );
        }
        add_option( 'amir_active_languages', wp_json_encode( [ 'es', 'en' ] ) );

        // 1.8.8: permitir marcar tours que no admiten niños/bebés (ej. tours
        // solo para adultos) — el widget de reserva (StepPeople en
        // BookingWidget.jsx) ya tenía la lógica lista para ocultar esos
        // contadores, esperando estos tres campos desde la API. Default 1/1
        // (admite ambos) para no cambiar el comportamiento de ningún tour
        // existente. `min_age_child` es el piso de la franja "niño" que se
        // muestra junto al contador (ej. "4–12 años") — por debajo de eso
        // cuenta como bebé.
        if ( ! in_array( 'allow_children', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN allow_children TINYINT(1) NOT NULL DEFAULT 1" );
        }
        if ( ! in_array( 'allow_babies', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN allow_babies TINYINT(1) NOT NULL DEFAULT 1" );
        }
        if ( ! in_array( 'min_age_child', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN min_age_child TINYINT UNSIGNED NOT NULL DEFAULT 4" );
        }

        // 1.9.0: marketplace de proveedores externos (§ 11 CONTRIBUTING.md).
        // amir_providers/amir_provider_payouts las crea create_tables() más
        // abajo (dbDelta no duplica). provider_id en amir_tours (NULL = tour
        // propio, como siempre) y provider_cost_mxn en amir_prices resuelven
        // "quién opera el tour" y "cuánto le cuesta a TourFlow", separado del
        // precio de venta al cliente (price_mxn, sin cambios).
        if ( ! in_array( 'provider_id', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN provider_id INT UNSIGNED DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD KEY provider_id (provider_id)" );
        }
        $price_cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_prices" );
        if ( ! in_array( 'provider_cost_mxn', $price_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_prices ADD COLUMN provider_cost_mxn DECIMAL(10,2) NOT NULL DEFAULT 0.00" );
        }
        // Reserva con proveedor: no pasa a 'confirmed' directo tras el cobro,
        // sino a 'pending_provider_approval' hasta que el proveedor aprueba
        // por email (o vence el plazo configurable, ver class-cron-manager.php).
        // 'cancelled_provider' cubre tanto el rechazo explícito como el
        // vencimiento sin respuesta — se distinguen por provider_reject_reason
        // e internal_notes, no por status separado (nadie pidió filtrarlos
        // aparte en Reservas todavía).
        if ( ! in_array( 'provider_response_token', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN provider_response_token VARCHAR(64) DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD UNIQUE KEY provider_response_token (provider_response_token)" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN provider_notified_at DATETIME DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN provider_reminder_sent_at DATETIME DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN provider_responded_at DATETIME DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN provider_reject_reason VARCHAR(500) DEFAULT NULL" );
        }
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN status ENUM(
            'wishlist','awaiting_payment','pending','pending_provider_approval','confirmed',
            'cancellation_requested','cancelled_client','cancelled_weather','cancelled_min_pax',
            'cancelled_provider','rescheduled','completed'
        ) NOT NULL DEFAULT 'pending'" );
        add_option( 'amir_provider_reminder_hours', '24' );
        add_option( 'amir_provider_response_hours', '48' );

        // 1.9.1: check-in por escaneo de voucher (Modo campo → 📷 Escanear
        // voucher) — el QR ya existente del voucher codifica verify_url()
        // (ref+token), se reusa tal cual, no hace falta un QR nuevo.
        if ( ! in_array( 'checked_in_at', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN checked_in_at DATETIME DEFAULT NULL" );
        }

        // 1.10.0: itinerario tipo timeline (§ 13.1 CONTRIBUTING.md) — array
        // JSON de paradas { title_es, title_en, desc_es, desc_en, image_id,
        // image_url, is_start }, opcional por tour. Sin DEFAULT literal a
        // propósito (mismo motivo que itinerary_es/en/content_i18n arriba:
        // MySQL/MariaDB puede rechazar DEFAULT en columnas LONGTEXT según el
        // host — ver el bug real de content_i18n más arriba). El '[]' por
        // defecto se resuelve en PHP (sync_to_db(), ToursController), no acá.
        if ( ! in_array( 'itinerary_stops', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN itinerary_stops LONGTEXT" );
        }

        // 1.11.0: "datos destacados" — 2-4 bloques de ícono + título + detalle
        // configurables por tour (ej. 🗣️ Idioma: Español/Inglés, 👥 Personas:
        // 2–8, 🎂 Edad mínima: 12+), pedido del cliente para hacer más visibles
        // datos que hoy solo aparecen como chips chicos en el hero. Freeform
        // a propósito (no atado a los campos estructurados existentes, mismo
        // criterio que includes/excludes) — el operador escribe lo que
        // considera más relevante mostrar para ESE tour en particular.
        if ( ! in_array( 'detail_facts', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN detail_facts LONGTEXT" );
        }

        // 1.12.0: "Highlights" — 4-5 bullets cortos arriba de la descripción
        // larga (§ 13.2 CONTRIBUTING.md), mismo patrón que includes/excludes
        // (una línea por bullet → JSON array vía Languages::tour_field()).
        if ( ! in_array( 'highlights_es', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN highlights_es TEXT" );
        }
        if ( ! in_array( 'highlights_en', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN highlights_en TEXT" );
        }

        // 1.13.0: categorías de tour (§ 15.7 CONTRIBUTING.md) — JSON array de
        // slugs de la taxonomía amir_tour_category (que ya existía en WP pero
        // estaba desconectada de amir_tours). Sin DEFAULT literal, mismo
        // motivo que itinerary_stops/detail_facts arriba.
        if ( ! in_array( 'category_slugs', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN category_slugs LONGTEXT" );
        }

        // 1.14.0: cobro diferido a la confirmación del proveedor (§ 11.0
        // CONTRIBUTING.md) — 'immediate' (default, comportamiento de siempre:
        // se cobra al reservar) o 'on_approval' (no se cobra nada hasta que
        // el proveedor aprueba; recién ahí se manda el link de pago real).
        if ( ! in_array( 'provider_charge_mode', $tour_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN provider_charge_mode ENUM('immediate','on_approval') NOT NULL DEFAULT 'immediate'" );
        }

        // 1.16.0: amir_bookings generalizada para aceptar reservas de
        // habitación (§ 16 CONTRIBUTING.md) sin duplicar toda la
        // infraestructura de pago/emails/reembolsos — decisión del cliente
        // 2026-07-31 de reusar esta tabla en vez de una tabla de reservas
        // separada. tour_id/schedule_id pasan a admitir NULL (una reserva
        // de habitación no tiene ninguno de los dos); tour_date SIGUE
        // NOT NULL — para una reserva de habitación se le carga el
        // check_in_date, así ninguna pantalla existente que ya asume
        // tour_date poblado (Reservas, Reportes, emails, voucher) se rompe.
        $booking_cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_bookings" );
        if ( ! in_array( 'item_type', $booking_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN tour_id INT UNSIGNED DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN schedule_id INT UNSIGNED DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN item_type ENUM('tour','room') NOT NULL DEFAULT 'tour' AFTER access_token" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN room_id INT UNSIGNED DEFAULT NULL AFTER schedule_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN check_out_date DATE DEFAULT NULL AFTER tour_date" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD KEY room_id (room_id)" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD KEY item_type (item_type)" );
        }

        // 1.17.0: amir_addons reusable para habitaciones (§ 16.4
        // CONTRIBUTING.md, decisión cerrada 2026-07-31: reusar el catálogo
        // existente en vez de crear uno nuevo). tour_id pasa a admitir NULL
        // (un addon 'room' no pertenece a ningún tour); room_id y
        // applies_to son columnas nuevas. Default 'tour' en applies_to deja
        // el comportamiento de todos los addons existentes exactamente
        // igual que antes.
        $addon_cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_addons" );
        if ( ! in_array( 'applies_to', $addon_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_addons MODIFY COLUMN tour_id INT UNSIGNED DEFAULT NULL" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_addons ADD COLUMN room_id INT UNSIGNED DEFAULT NULL AFTER tour_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_addons ADD COLUMN applies_to ENUM('tour','room','both') NOT NULL DEFAULT 'tour' AFTER room_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_addons ADD KEY room_id (room_id)" );
        }

        // 1.18.0: carrito multi-ítem (§ 16 CONTRIBUTING.md, decisión
        // 2026-07-31: carrito armado en el cliente/React, no persistido
        // fila por fila — el checkout final crea de una todas las reservas
        // reales). cart_group_id enlaza las N reservas (tour+habitación+
        // extras) creadas en un mismo checkout, para poder confirmarlas
        // todas juntas cuando llega UN solo pago que las cubre a todas.
        $booking_cols2 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_bookings" );
        if ( ! in_array( 'cart_group_id', $booking_cols2, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN cart_group_id VARCHAR(36) DEFAULT NULL AFTER room_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD KEY cart_group_id (cart_group_id)" );
        }

        // 1.19.0: Google Calendar, un sentido (§ 15.4/§ 16 CONTRIBUTING.md)
        // — guarda el event_id devuelto por la Calendar API para poder
        // actualizar/borrar el evento correcto después (reprogramación,
        // cancelación), en vez de crear uno nuevo cada vez.
        $booking_cols3 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_bookings" );
        if ( ! in_array( 'google_calendar_event_id', $booking_cols3, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN google_calendar_event_id VARCHAR(255) DEFAULT NULL AFTER cart_group_id" );
        }

        // 1.20.0: amenities + video por habitación (§ 16.11 CONTRIBUTING.md,
        // 2026-08-01) — versión mejorada de la página de detalle.
        // flow_room_availability_rules la crea create_tables() más abajo
        // (tabla nueva, dbDelta alcanza sin ALTER explícito).
        $room_cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}flow_rooms" );
        if ( ! in_array( 'amenities', $room_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}flow_rooms ADD COLUMN amenities LONGTEXT AFTER gallery_images" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}flow_rooms ADD COLUMN video_url VARCHAR(500) DEFAULT NULL AFTER amenities" );
        }

        // 1.21.0: tours "destacados" para el paso 1 del Flujo A del flujo
        // continuo (§ 16.15 CONTRIBUTING.md) — checkbox explícito además de
        // sort_order (decisión del cliente 2026-08-03: sort_order solo no
        // alcanza, un operador con muchos tours propios quiere elegir a mano
        // cuáles aparecen ahí, no "los primeros N").
        $tour_cols_featured = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'featured', $tour_cols_featured, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD KEY featured (featured)" );
        }

        // 1.22.0 (2026-08-04): dos flags de visibilidad para tours de "venta
        // separada" (§ 16.20/16.21 CONTRIBUTING.md, ej. un traslado que se
        // reserva solo desde su propia ficha) — deliberadamente dos columnas
        // independientes, no una sola: hide_from_lists saca el tour de
        // [flow_tour_list] y de los listados del flujo continuo (Flujo A
        // destacados, Flujo B catálogo); hide_from_suggestions lo saca solo
        // de "otros tours sugeridos" (paso de extras). hide_from_lists tiene
        // prioridad sobre featured — un tour oculto de listas nunca aparece
        // ahí aunque esté marcado destacado.
        $tour_cols_hide = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'hide_from_lists', $tour_cols_hide, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN hide_from_lists TINYINT(1) NOT NULL DEFAULT 0 AFTER featured" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN hide_from_suggestions TINYINT(1) NOT NULL DEFAULT 0 AFTER hide_from_lists" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD KEY hide_from_lists (hide_from_lists)" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD KEY hide_from_suggestions (hide_from_suggestions)" );
        }

        // 1.22.0: cupones también para habitaciones (§ 16.21 CONTRIBUTING.md)
        // — mismo criterio que tour_id (NULL = global, o acotado a un
        // recurso puntual). tour_id y room_id nunca conviven en el mismo
        // cupón — CouponEngine::validate() lo rechaza si se mezclan.
        $coupon_cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_coupons" );
        if ( ! in_array( 'room_id', $coupon_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_coupons ADD COLUMN room_id INT UNSIGNED DEFAULT NULL AFTER tour_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_coupons ADD KEY room_id (room_id)" );
        }

        // 1.23.0 (2026-08-04): registro de consentimiento GDPR (§ 15.3,
        // pieza 3 de 4) — antes el plugin solo validaba que los checkboxes
        // de política/términos vinieran tildados, sin guardar cuándo ni qué
        // texto vio el cliente. Ver BookingManager::consent_snapshot().
        $booking_cols_consent = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_bookings" );
        if ( ! in_array( 'consent_recorded_at', $booking_cols_consent, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN consent_recorded_at DATETIME DEFAULT NULL AFTER checked_in_at" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN consent_text_hash VARCHAR(64) DEFAULT NULL AFTER consent_recorded_at" );
        }

        // 1.23.0: "derecho al olvido" (§ 15.3, pieza 4 de 4) — manual desde
        // el admin (decisión del cliente 2026-08-04). anonymized_at deja
        // registro de que la anonimización ya se hizo (evita repetirla y
        // pisar el texto de "Cliente eliminado" con otro igual).
        if ( ! in_array( 'anonymized_at', $booking_cols_consent, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN anonymized_at DATETIME DEFAULT NULL AFTER consent_text_hash" );
        }

        // 1.24.0 (2026-08-04): extras globales + productos digitales (§ 16.23
        // CONTRIBUTING.md, decisión cerrada con el cliente) — reusa
        // amir_addons en vez de una tabla nueva: 'global' en applies_to
        // (tour_id y room_id ambos NULL) es un addon que no pertenece a
        // ningún tour/habitación puntual y se ofrece en el paso de extras
        // del flujo continuo sin importar qué haya en el carrito; 'digital'
        // en pricing_type es un producto de entrega por archivo (guía PDF,
        // etc.) en vez de una experiencia física — digital_file_url guarda
        // el adjunto de la Media Library.
        $addon_cols_v2 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_addons" );
        if ( ! in_array( 'digital_file_url', $addon_cols_v2, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_addons MODIFY COLUMN applies_to ENUM('tour','room','both','global') NOT NULL DEFAULT 'tour'" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_addons MODIFY COLUMN pricing_type ENUM('per_unit','flat','digital') NOT NULL DEFAULT 'per_unit'" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_addons ADD COLUMN digital_file_url VARCHAR(500) DEFAULT NULL AFTER price_mxn" );
        }

        // 1.25.0 (2026-08-06): tours de fecha fija (pedido explícito del
        // cliente) — un tour puntual (evento único) que se reserva SOLO ese
        // día, sin calendario. Ver AvailabilityEngine::evaluate_rules() y
        // BookingWidget.jsx (needsDateStep).
        $tour_cols_v3 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'fixed_date', $tour_cols_v3, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN fixed_date DATE DEFAULT NULL AFTER wishlist_notified_at" );
        }

        // 1.26.0 (2026-08-08): "solicitar fecha" para tours de fecha fija
        // (pedido del cliente en la misma sesión de 1.25.0, cerrado recién
        // acá) — un cliente puede pedir una fecha distinta a la fija para
        // que el operador la evalúe. Sin cobro al solicitar: mismo patrón
        // que Lista de interés (BookingManager::create_wishlist()), NO el
        // de proveedores externos (que cobra de entrada). create_tables()
        // ya tenía 'date_requested'/'date_request' en los ENUM desde
        // 1.25.0 (agregados a medio construir, ver CONTRIBUTING.md § 16.35)
        // — acá recién se completa el ALTER TABLE para instalaciones
        // existentes, que create_tables()/dbDelta no actualiza solo.
        $booking_status_col = $wpdb->get_row(
            "SHOW COLUMNS FROM {$wpdb->prefix}amir_bookings LIKE 'status'"
        );
        if ( $booking_status_col && strpos( $booking_status_col->Type, 'date_requested' ) === false ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN status ENUM(
                'wishlist','date_requested','awaiting_payment','pending',
                'pending_provider_approval','confirmed','cancellation_requested',
                'cancelled_client','cancelled_weather','cancelled_min_pax',
                'cancelled_provider','rescheduled','completed'
            ) NOT NULL DEFAULT 'pending'" );
        }
        $booking_source_col = $wpdb->get_row(
            "SHOW COLUMNS FROM {$wpdb->prefix}amir_bookings LIKE 'booking_source'"
        );
        if ( $booking_source_col && strpos( $booking_source_col->Type, 'date_request' ) === false ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN booking_source
                ENUM('direct','tripadvisor','getyourguide','partner','manual','wishlist','date_request')
                NOT NULL DEFAULT 'direct'" );
        }

        // 1.27.0 (2026-08-08): tours "solo a pedido" (§ CLAUDE.md, tercer
        // modo de disponibilidad junto a calendario normal y fecha fija) —
        // el tour conserva su calendario normal (o abre siempre si no tiene
        // reglas), pero CADA reserva nace en 'date_requested' en vez de
        // 'pending'/confirmarse — nunca se cobra hasta que el operador
        // aprueba y manda el link de pago (mismo mecanismo que "solicitar
        // fecha" en tours de fecha fija, § 16.37, reusado tal cual acá).
        // Ver BookingManager::create_pending().
        $tour_cols_v4 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'request_only', $tour_cols_v4, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN request_only TINYINT(1) NOT NULL DEFAULT 0 AFTER fixed_date" );
        }

        // 1.28.0 (2026-08-10): video en la ficha de cada tour (YouTube/Vimeo)
        // — pedido del cliente, disponible en las TRES ediciones (decisión
        // explícita: no es un diferenciador de Pro, es barato de dar y no
        // compite con los diferenciadores reales de Pro/Pro Max). Solo la
        // URL se persiste — proveedor + id se derivan en caliente al
        // renderizar (Core\VideoEmbed::parse()), nunca se guardan aparte.
        $tour_cols_v5 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'video_url', $tour_cols_v5, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN video_url VARCHAR(500) DEFAULT NULL AFTER category_slugs" );
        }

        // 1.30.0 (2026-08-16): "armá tu tour" — variante de "Solo a pedido"
        // (request_only) para tours sin precio ni horario cargado, donde el
        // cliente describe lo que quiere y el operador cotiza manualmente al
        // aprobar. Pedido explícito del cliente. Reusa TODO el mecanismo de
        // request_only/date_requested — custom_quote solo le dice al widget
        // que saltee fecha/horario/precio, y a la pantalla de aprobación que
        // deje cargar el monto a mano. Ver BookingManager::create_pending().
        $tour_cols_v6 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'custom_quote', $tour_cols_v6, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN custom_quote TINYINT(1) NOT NULL DEFAULT 0 AFTER request_only" );
        }

        // 1.31.0 (2026-08-20): depósito parcial por tour — Pro Max primero
        // (checkbox gateado por AMIR_EDITION en el editor, ver
        // TourPostType::meta_box_main()). El cliente cobra solo deposit_pct%
        // online al reservar; el resto se cobra después (efectivo o link de
        // pago). amir_bookings.deposit_pct es un SNAPSHOT del % al momento
        // de la reserva (si el operador cambia el % del tour después, no
        // afecta reservas ya hechas — mismo criterio que
        // cancellation_policy_pct). Ver BookingManager::create_pending()/
        // calculate_refund(), BookingResult::$charge_mxn.
        $tour_cols_v7 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'deposit_enabled', $tour_cols_v7, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN deposit_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_quote" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN deposit_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER deposit_enabled" );
        }
        $booking_cols_v7 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_bookings" );
        if ( ! in_array( 'deposit_pct', $booking_cols_v7, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN deposit_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_amount_mxn" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN balance_paid_at DATETIME DEFAULT NULL AFTER deposit_pct" );
        }

        // 1.32.0 (2026-08-20): "Reserva directa" (Pro Max) — pedido del
        // cliente repasando por qué ningún tipo de tour muestra el widget
        // clásico (calendario visible de entrada) por defecto en Pro Max:
        // hoy `single-amir_tour.php`/`-immersive.php` SIEMPRE meten el tour
        // en [flow_discovery] ahí ("upsell siempre", decisión 2026-08-03,
        // § 16.15) — sin excepción, aunque el tour no tenga sentido
        // combinarlo con nada (ej. un traslado puntual). skip_upsell deja
        // optar por tour: si está activo, la ficha usa [flow_booking] (el
        // widget clásico) en vez de [flow_discovery], incluso en Pro Max.
        $tour_cols_v8 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'skip_upsell', $tour_cols_v8, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN skip_upsell TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_pct" );
        }

        // 1.33.0 (auditoría de rendimiento pre-empaquetado v5.7.14): las
        // queries calientes de AvailabilityEngine (get_month_availability,
        // llamado en cada navegación de mes del calendario del widget)
        // filtran por tour_id+schedule_id+tour_date+status a la vez — solo
        // había índices de una columna, así que MySQL elegía uno solo y
        // filtraba el resto en memoria. Índice compuesto cubre exactamente
        // ese patrón de WHERE.
        $existing_indexes = $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}amir_bookings WHERE Key_name = 'idx_avail_lookup'", 2 );
        if ( empty( $existing_indexes ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD INDEX idx_avail_lookup (tour_id, schedule_id, tour_date, status)" );
        }

        // 1.34.0 — Tarea 24 del roadmap: "contenido extra por tour" en el
        // email de confirmación (ej. "este tour requiere pasaporte"), sin
        // tocar la plantilla general. Mismo patrón simple es/en que
        // what_to_expect_es/en — no content_i18n, porque TODO el sistema de
        // emails de este plugin ya es es/en-only por diseño (ver
        // BaseEmail::text()), no solo este campo.
        $tour_cols_v9 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'email_extra_note_es', $tour_cols_v9, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN email_extra_note_es TEXT AFTER what_to_expect_en" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN email_extra_note_en TEXT AFTER email_extra_note_es" );
        }

        // 1.35.0 (v5.8.0) — "Requiere nombre de cada integrante" (pedido del
        // cliente 2026-08-24): algunos tours (ej. actividades con seguro/
        // briefing de seguridad) necesitan el nombre de cada pasajero, no
        // solo la cantidad — opt-in por tour, la mayoría no lo necesita.
        // participant_names guarda un JSON de nombres (adultos+niños, ver
        // BookingManager::create_pending()) — se usa en el manifiesto PDF
        // nuevo de TourFlow → Reportes.
        $tour_cols_v10 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'require_participant_names', $tour_cols_v10, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN require_participant_names TINYINT(1) NOT NULL DEFAULT 0 AFTER skip_upsell" );
        }
        $booking_cols_v10 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_bookings" );
        if ( ! in_array( 'participant_names', $booking_cols_v10, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings ADD COLUMN participant_names TEXT AFTER special_requests" );
        }

        // 1.36.0 (v5.9.0) — FAQ opcional por tour (pedido del cliente
        // 2026-08-25, a partir del bloque "Quick Questions" de la landing de
        // Sicilia Mia — "por tour", no global). Mismo patrón de filas
        // repetibles + JSON único que itinerary_stops/detail_facts (§ 13.1/
        // 13.2) — question_es/en + answer_es/en por fila.
        $tour_cols_v11 = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}amir_tours" );
        if ( ! in_array( 'faq_items', $tour_cols_v11, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_tours ADD COLUMN faq_items LONGTEXT AFTER detail_facts" );
        }

        // 1.37.0 (v5.9.0, mismo pedido 2026-08-25) — venta suelta de un
        // producto digital (ej. guía PDF), sin reservar ningún tour. Tercer
        // valor de item_type ('product'), mismo criterio que cuando se
        // sumó 'room' (§ 16 CONTRIBUTING.md) — una "reserva" de tipo
        // producto vive en la misma amir_bookings (tour_id/room_id NULL,
        // reusa pasarela/access_token/email tal cual). Ver
        // CartController::create_product_order().
        $item_type_col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}amir_bookings LIKE 'item_type'" );
        if ( $item_type_col && strpos( $item_type_col->Type, "'product'" ) === false ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}amir_bookings MODIFY COLUMN item_type ENUM('tour','room','product') NOT NULL DEFAULT 'tour'" );
        }

        self::create_tables();
        self::create_verify_page();
        self::create_provider_action_page();
        self::create_discovery_page();
        update_option( 'amir_db_version', AMIR_DB_VERSION );
    }

    /**
     * Genera un access_token único para reservas creadas antes de la 1.2.0.
     * Se hace en un loop en PHP (no en SQL) porque cada fila necesita un
     * valor distinto — no es una migración masiva, corre una sola vez.
     */
    private static function backfill_access_tokens(): void {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}amir_bookings WHERE access_token IS NULL OR access_token = ''"
        );
        foreach ( $ids as $id ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_bookings",
                [ 'access_token' => bin2hex( random_bytes( 32 ) ) ],
                [ 'id' => (int) $id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    /**
     * Ejecuta maybe_update() en todos los sites de la red.
     * Se llama desde el Network Admin cuando se detecta una versión nueva.
     */
    public static function network_maybe_update(): void {
        if ( ! is_multisite() ) {
            self::maybe_update();
            return;
        }

        $sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
        foreach ( $sites as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            self::maybe_update();
            restore_current_blog();
        }
    }
}
