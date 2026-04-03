<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la instalación, actualización y desinstalación
 * de las tablas de base de datos del plugin.
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
    public static function activate(): void {
        self::create_tables();
        self::insert_default_data();
        self::schedule_cron_jobs();
        \AmirBooking\Core\TourManagerRole::register();   // Crear el rol

        update_option( 'amir_db_version', AMIR_DB_VERSION );
        update_option( 'amir_installed_at', current_time( 'mysql' ) );

        // Flush rewrite rules para los endpoints
        flush_rewrite_rules();
    }

    // ── Desactivación ─────────────────────────────────────────────────────
    public static function deactivate(): void {
        self::unschedule_cron_jobs();
        flush_rewrite_rules();
    }

    // ── Desinstalación ────────────────────────────────────────────────────
    public static function uninstall(): void {
        // Solo eliminar tablas si el admin lo confirmó en settings
        if ( get_option( 'amir_delete_data_on_uninstall', false ) ) {
            self::drop_tables();
        }
        \AmirBooking\Core\TourManagerRole::remove();
        self::delete_options();
    }

    // ── Creación de tablas ────────────────────────────────────────────────
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
            booking_source           ENUM('direct','tripadvisor','getyourguide','partner') NOT NULL DEFAULT 'direct',
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
            review_email_sent_at     DATETIME,
            reminder_sent_at         DATETIME,
            confirmed_at             DATETIME,
            created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_ref (booking_ref),
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
        // Solo inserta si no existen datos previos (re-activación segura)
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}amir_tours" );
        if ( $count > 0 ) {
            return;
        }

        // Configuración por defecto
        add_option( 'amir_currency',            'MXN' );
        add_option( 'amir_usd_rate_mode',        'auto' );   // auto | manual
        add_option( 'amir_usd_rate_manual',      '17.00' );
        add_option( 'amir_stripe_mode',          'test' );   // test | live
        add_option( 'amir_pending_expire_mins',  '15' );
        add_option( 'amir_review_delay_days',    '1' );
        add_option( 'amir_admin_email',          get_option( 'admin_email' ) );
        add_option( 'amir_delete_data_on_uninstall', '0' );
    }

    // ── Cron jobs ─────────────────────────────────────────────────────────
    private static function schedule_cron_jobs(): void {
        if ( ! wp_next_scheduled( 'amir_hourly_tasks' ) ) {
            wp_schedule_event( time(), 'hourly', 'amir_hourly_tasks' );
        }
        if ( ! wp_next_scheduled( 'amir_daily_tasks' ) ) {
            // Ejecutar a las 7 AM hora del servidor
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
        // Invertir el orden para respetar dependencias
        foreach ( array_reverse( self::TABLES ) as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}amir_{$table}" );
        }
    }

    private static function delete_options(): void {
        $options = [
            'amir_db_version', 'amir_installed_at', 'amir_currency',
            'amir_usd_rate_mode', 'amir_usd_rate_manual', 'amir_stripe_mode',
            'amir_stripe_pk_test', 'amir_stripe_sk_test',
            'amir_stripe_pk_live', 'amir_stripe_sk_live',
            'amir_stripe_webhook_secret', 'amir_pending_expire_mins',
            'amir_review_delay_days', 'amir_admin_email',
            'amir_delete_data_on_uninstall',
        ];
        foreach ( $options as $option ) {
            delete_option( $option );
        }
    }

    // ── Actualización de DB ───────────────────────────────────────────────
    public static function maybe_update(): void {
        $installed = get_option( 'amir_db_version', '0.0.0' );
        if ( version_compare( $installed, AMIR_DB_VERSION, '<' ) ) {
            self::create_tables();
            update_option( 'amir_db_version', AMIR_DB_VERSION );
        }
    }
}
