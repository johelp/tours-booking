<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin principal — patrón Singleton.
 * Registra todos los módulos y hooks en el orden correcto.
 */
final class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        // ── Custom Post Type ──────────────────────────────────────────────
        ( new \AmirBooking\CPT\TourPostType() )->register();

        // ── Rol Tour Manager ──────────────────────────────────────────────
        TourManagerRole::register_hooks();

        // ── Elementor (se inicializa solo si Elementor está activo) ───────
        ( new \AmirBooking\Elementor\ElementorIntegration() )->register();

        // ── Assets y traducciones ─────────────────────────────────────────
        ( new Assets() )->register();
        ( new I18n() )->register();

        // ── Templates del plugin (fallback si el tema no los tiene) ───────
        add_filter( 'template_include', [ TemplateLoader::class, 'load' ] );

        // ── REST API ──────────────────────────────────────────────────────
        add_action( 'rest_api_init', function () {
            ( new \AmirBooking\Api\ToursController() )->register_routes();
            ( new \AmirBooking\Api\AvailabilityController() )->register_routes();
            ( new \AmirBooking\Api\BookingController() )->register_routes();
            ( new \AmirBooking\Api\PricesController() )->register_routes();
            ( new \AmirBooking\Api\NotificationsController() )->register_routes();
        } );

        // ── Admin (site-level) ────────────────────────────────────────────
        if ( is_admin() ) {
            ( new \AmirBooking\Admin\AdminMenu() )->register();
            ( new \AmirBooking\Admin\NotificationBadge() )->register();
        }

        // ── Network Admin (solo en Multisite, solo para Super Admin) ──────
        if ( is_multisite() && is_network_admin() ) {
            ( new \AmirBooking\Admin\NetworkAdmin() )->register();
        }

        // ── Hooks de Multisite: nuevo site → crear tablas ─────────────────
        if ( is_multisite() ) {
            // WP 5.1+
            add_action( 'wp_initialize_site', [ Installer::class, 'on_new_site'      ], 10, 1 );
            // Fallback WP < 5.1
            add_action( 'wpmu_new_blog',      [ Installer::class, 'on_wpmu_new_blog' ], 10, 1 );
        }

        // ── Shortcodes ────────────────────────────────────────────────────
        add_shortcode( 'amir_booking',        [ Shortcodes::class, 'booking_widget' ] );
        add_shortcode( 'amir_tour_list',      [ Shortcodes::class, 'tour_list'      ] );
        add_shortcode( 'amir_verify_booking', [ Shortcodes::class, 'verify_booking' ] );

        // ── Cron jobs ─────────────────────────────────────────────────────
        ( new CronManager() )->register();

        // ── Emails ────────────────────────────────────────────────────────
        $dispatcher = new \AmirBooking\Emails\EmailDispatcher();
        $dispatcher->register();

        // Cron de email de confirmación diferido (evita bloqueos SMTP en REST)
        add_action( 'amir_send_confirmation_email', function( int $booking_id ) use ( $dispatcher ) {
            $dispatcher->send_confirmation( $booking_id );
        } );

        // ── Partners ──────────────────────────────────────────────────────
        ( new \AmirBooking\Partners\PartnerTracker() )->register();

        // ── Actualización de DB cuando la versión del esquema cambia ──────
        if ( get_option( 'amir_db_version', '0' ) !== AMIR_DB_VERSION ) {
            Installer::maybe_update();
        }
    }
}
