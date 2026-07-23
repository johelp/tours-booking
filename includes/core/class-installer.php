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
        'availability_rules',
        'partners',
        'bookings',
        'notifications',
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
            duration_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            min_age            TINYINT UNSIGNED NOT NULL DEFAULT 0,
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
            tripadvisor_id     VARCHAR(100) DEFAULT '',
            gyg_id             VARCHAR(100) DEFAULT '',
            sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY sort_order (sort_order)
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
            valid_from   DATE,
            valid_until  DATE,
            PRIMARY KEY (id),
            KEY tour_id (tour_id),
            KEY schedule_id (schedule_id),
            KEY person_type (person_type),
            KEY validity (valid_from, valid_until)
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

        // ── amir_bookings ─────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}amir_bookings (
            id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_ref              VARCHAR(20) NOT NULL,
            access_token             VARCHAR(64) DEFAULT NULL,
            tour_id                  INT UNSIGNED NOT NULL,
            schedule_id              INT UNSIGNED NOT NULL,
            partner_id               INT UNSIGNED,
            tour_date                DATE NOT NULL,
            status                   ENUM(
                                        'pending',
                                        'confirmed',
                                        'cancellation_requested',
                                        'cancelled_client',
                                        'cancelled_weather',
                                        'cancelled_min_pax',
                                        'rescheduled',
                                        'completed'
                                     ) NOT NULL DEFAULT 'pending',
            booking_source           ENUM('direct','tripadvisor','getyourguide','partner','manual') NOT NULL DEFAULT 'direct',
            lang                     ENUM('es','en') NOT NULL DEFAULT 'es',
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
            cancellation_policy_pct  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            refund_amount_mxn        DECIMAL(10,2) DEFAULT 0.00,
            qr_code_path             VARCHAR(500) DEFAULT '',
            pdf_voucher_path         VARCHAR(500) DEFAULT '',
            special_requests         TEXT,
            internal_notes           TEXT,
            custom_email_note        TEXT,
            review_email_sent_at     DATETIME,
            reminder_sent_at         DATETIME,
            confirmed_at             DATETIME,
            created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_ref (booking_ref),
            UNIQUE KEY access_token (access_token),
            KEY tour_id (tour_id),
            KEY schedule_id (schedule_id),
            KEY partner_id (partner_id),
            KEY tour_date (tour_date),
            KEY status (status),
            KEY customer_email (customer_email),
            KEY stripe_payment_intent (stripe_payment_intent),
            KEY created_at (created_at)
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
    }

    // ── Datos por defecto ─────────────────────────────────────────────────

    private static function insert_default_data(): void {
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}amir_tours" );
        if ( $count > 0 ) {
            self::create_verify_page();
            return;
        }

        add_option( 'amir_currency',             'MXN' );
        add_option( 'amir_usd_rate_mode',         'auto' );
        add_option( 'amir_usd_rate_manual',       '17.00' );
        add_option( 'amir_stripe_mode',           'test' );
        add_option( 'amir_pending_expire_mins',   '15' );
        add_option( 'amir_review_delay_days',     '1' );
        add_option( 'amir_admin_email',           get_option( 'admin_email' ) );
        add_option( 'amir_delete_data_on_uninstall', '0' );

        self::create_verify_page();
    }

    /**
     * Crea la página /verificar-reserva/ con el shortcode [amir_verify_booking].
     * Seguro de llamar en cada activación — no duplica si ya existe.
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
            'post_content' => '[amir_verify_booking]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'amir_verify_page_id', $page_id );
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
    }

    private static function delete_options(): void {
        $options = array(
            'amir_db_version', 'amir_installed_at', 'amir_currency',
            'amir_usd_rate_mode', 'amir_usd_rate_manual', 'amir_stripe_mode',
            'amir_stripe_pk_test', 'amir_stripe_sk_test',
            'amir_stripe_pk_live', 'amir_stripe_sk_live',
            'amir_stripe_webhook_secret', 'amir_pending_expire_mins',
            'amir_review_delay_days', 'amir_admin_email',
            'amir_delete_data_on_uninstall', 'amir_verify_page_id',
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

        self::create_tables();
        self::create_verify_page();
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
