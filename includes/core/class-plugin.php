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

        // Habitaciones (Pro Max, CONTRIBUTING.md § 16) — namespace nuevo
        // TourFlow\, no-op si AMIR_EDITION !== 'pro_max' (ver
        // RoomPostType::register()).
        if ( class_exists( \TourFlow\Rooms\RoomPostType::class ) ) {
            ( new \TourFlow\Rooms\RoomPostType() )->register();
        }
        if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Rooms\RoomConfirmationEmail::class ) ) {
            add_action( 'flow_room_booking_confirmed', [ \TourFlow\Rooms\RoomConfirmationEmail::class, 'send_for' ] );
            add_action( 'flow_room_booking_rescheduled', [ \TourFlow\Rooms\RoomConfirmationEmail::class, 'send_reschedule_for' ] );
        }
        if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Cart\CartConfirmationEmail::class ) ) {
            add_action( 'flow_cart_confirmed', [ \TourFlow\Cart\CartConfirmationEmail::class, 'send_for' ] );
        }

        // Google Calendar, un solo sentido (Pro Max, § 15.4/§ 16 CONTRIBUTING.md).
        if ( AMIR_EDITION === 'pro_max' ) {
            GoogleCalendarSync::register_hooks();
        }

        // ── Rol Tour Manager ──────────────────────────────────────────────
        TourManagerRole::register_hooks();

        // ── Elementor (se inicializa solo si Elementor está activo) ───────
        ( new \AmirBooking\Elementor\ElementorIntegration() )->register();

        // ── Assets y traducciones ─────────────────────────────────────────
        ( new Assets() )->register();
        ( new I18n() )->register();

        // ── Personalización del widget también vía Customizer nativo ──────
        // Pedido del cliente 2026-08-03 — mismas opciones que Configuración
        // → 🎨 Widget de reserva (WidgetTheme), disponible en todas las
        // ediciones (no es una feature Pro, ya no lo era en Configuración).
        ( new Customizer() )->register();

        // ── Templates del plugin (fallback si el tema no los tiene) ───────
        add_filter( 'template_include', [ TemplateLoader::class, 'load' ] );

        // ── REST API ──────────────────────────────────────────────────────
        add_action( 'rest_api_init', function () {
            Cors::apply();

            ( new \AmirBooking\Api\ToursController() )->register_routes();
            ( new \AmirBooking\Api\AvailabilityController() )->register_routes();
            ( new \AmirBooking\Api\BookingController() )->register_routes();
            ( new \AmirBooking\Api\PricesController() )->register_routes();
            ( new \AmirBooking\Api\NotificationsController() )->register_routes();

            // Pro y superior (Pro Max hereda todo lo de Pro, decisión del
            // cliente 2026-08-03 — nunca === 'pro' estricto): lista de
            // interés y API pública (/config) para integraciones externas
            // (app móvil / web headless). El ZIP Lite no incluye estos
            // archivos — class_exists() evita un fatal si el autoloader no
            // los encuentra.
            if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
                if ( class_exists( \AmirBooking\Api\WishlistController::class ) ) {
                    ( new \AmirBooking\Api\WishlistController() )->register_routes();
                }
                if ( class_exists( \AmirBooking\Api\ConfigController::class ) ) {
                    ( new \AmirBooking\Api\ConfigController() )->register_routes();
                }
            }

            // Habitaciones (Pro Max, CONTRIBUTING.md § 16) — namespace REST
            // nuevo flow/v1, no-op si AMIR_EDITION !== 'pro_max'.
            if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Rooms\RoomBookingController::class ) ) {
                ( new \TourFlow\Rooms\RoomBookingController() )->register_routes();
            }
            if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Cart\CartController::class ) ) {
                ( new \TourFlow\Cart\CartController() )->register_routes();
            }
            // Flujo continuo (§ 16.15 CONTRIBUTING.md) — destacados/catálogo por rango de fechas.
            if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Discovery\DiscoveryController::class ) ) {
                ( new \TourFlow\Discovery\DiscoveryController() )->register_routes();
            }
        } );

        // ── Admin (site-level) ────────────────────────────────────────────
        if ( is_admin() ) {
            ( new \AmirBooking\Admin\AdminMenu() )->register();
            ( new \AmirBooking\Admin\NotificationBadge() )->register();
            ( new \AmirBooking\Admin\FieldPage() )->register();
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
        // `flow_*` es el nombre oficial desde v3.1.0 — coincide con el
        // nombre del producto (TourFlow). `amir_*` queda registrado para
        // siempre como alias silencioso: son los mismos shortcodes de antes
        // (mismo callback), así que cualquier página ya publicada con
        // [amir_booking] (ej. Amir Adventours) sigue funcionando exactamente
        // igual, sin que nadie tenga que migrar contenido. Nunca remover el
        // alias — es la única razón de que exista.
        add_shortcode( 'flow_booking',        [ Shortcodes::class, 'booking_widget' ] );
        add_shortcode( 'flow_tour_list',      [ Shortcodes::class, 'tour_list'      ] );
        add_shortcode( 'flow_verify_booking', [ Shortcodes::class, 'verify_booking' ] );

        // Universal a las tres ediciones (§ 16.54 CONTRIBUTING.md, pedido
        // explícito del cliente 2026-08-12) — sin alias amir_*, son features
        // nuevas, no hay contenido viejo que migrar.
        add_shortcode( 'flow_spots_left', [ Shortcodes::class, 'spots_left' ] );
        add_shortcode( 'flow_tour_dates', [ Shortcodes::class, 'tour_dates' ] );
        // Selector de variantes (§ 16.93 CONTRIBUTING.md, pedido explícito
        // del cliente 2026-08-25 a partir de un caso real — un mismo
        // producto vendido como N tours separados, ej. 4 habitaciones de un
        // retiro a precio fijo cada una) — universal, no depende de nada de
        // Pro Max, es solo el widget clásico detrás de un paso de elección.
        add_shortcode( 'flow_booking_variants', [ Shortcodes::class, 'booking_variants' ] );

        add_shortcode( 'amir_booking',        [ Shortcodes::class, 'booking_widget' ] );
        add_shortcode( 'amir_tour_list',      [ Shortcodes::class, 'tour_list'      ] );
        add_shortcode( 'amir_verify_booking', [ Shortcodes::class, 'verify_booking' ] );

        // Pro y superior: lista de interés y marketplace de proveedores
        // externos — Pro Max hereda todo lo de Pro (decisión del cliente
        // 2026-08-03), nunca `=== 'pro'` estricto.
        if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
            add_shortcode( 'flow_wishlist',        [ Shortcodes::class, 'wishlist_list'  ] );
            add_shortcode( 'flow_provider_action', [ Shortcodes::class, 'provider_action' ] );
            add_shortcode( 'amir_wishlist',        [ Shortcodes::class, 'wishlist_list'  ] );
            add_shortcode( 'amir_provider_action', [ Shortcodes::class, 'provider_action' ] );
        }

        // Pro Max: buscador de habitaciones (§ 16 CONTRIBUTING.md) — sin
        // alias amir_*, es feature nueva, no hay contenido viejo que migrar.
        if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Rooms\RoomShortcodes::class ) ) {
            add_shortcode( 'flow_room_search', [ \TourFlow\Rooms\RoomShortcodes::class, 'room_search' ] );
            add_shortcode( 'flow_room_list',   [ \TourFlow\Rooms\RoomShortcodes::class, 'room_list'   ] );
        }

        // Pro Max: flujo continuo de descubrimiento (§ 16.15 CONTRIBUTING.md).
        if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Discovery\DiscoveryShortcodes::class ) ) {
            add_shortcode( 'flow_discovery', [ \TourFlow\Discovery\DiscoveryShortcodes::class, 'discovery' ] );
        }

        // Pro Max: flujo Explorar, búsqueda-primero (§ 16.46 CONTRIBUTING.md)
        // — convive con flow_discovery, no lo reemplaza.
        if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Discovery\ExploreShortcodes::class ) ) {
            add_shortcode( 'flow_explore', [ \TourFlow\Discovery\ExploreShortcodes::class, 'explore' ] );

            // Barra de búsqueda standalone para el hero de un home, redirige
            // a la página con [flow_explore] (§ 16.54 CONTRIBUTING.md) —
            // gateado igual que flow_explore, no tiene sentido sin él.
            add_shortcode( 'flow_search_bar', [ Shortcodes::class, 'search_bar' ] );
        }

        // Pro Max: venta suelta de un producto digital, sin reservar ningún
        // tour (§ 16.9x CONTRIBUTING.md, pedido 2026-08-25) — reusa el
        // catálogo de Extras globales (TourFlow → 🎁 Extras globales) y el
        // checkout de carrito existente, no tiene sentido en Lite/Pro sin
        // esa pantalla admin.
        if ( AMIR_EDITION === 'pro_max' && class_exists( \TourFlow\Cart\ProductShortcode::class ) ) {
            add_shortcode( 'flow_product', [ \TourFlow\Cart\ProductShortcode::class, 'product' ] );
        }

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

        // ── Marketing (Meta Pixel / Google Ads / GA4) ─────────────────────
        // Pro y superior (Pro Max incluido). El ZIP Lite no incluye class-marketing.php.
        if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) && class_exists( Marketing::class ) ) {
            Marketing::init();
        }

        // ── Reembolsos ────────────────────────────────────────────────────
        // BookingManager::cancel() dispara esta acción cuando la política de
        // cancelación indica reembolso, pero hasta ahora nadie la escuchaba
        // — el monto quedaba calculado en la base sin avisarle nunca a la
        // pasarela. Se resuelve acá contra el gateway con el que se cobró
        // esa reserva específica (no necesariamente el gateway "default").
        add_action( 'amir_process_gateway_refund', function ( int $booking_id, float $refund_mxn ) {
            global $wpdb;
            $booking = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d", $booking_id
            ) );
            if ( ! $booking ) {
                return;
            }

            $gateway          = \AmirBooking\Payments\PaymentGatewayFactory::for_booking( $booking );
            $charge_reference = $booking->gateway_charge_id ?: $booking->stripe_charge_id;

            if ( ! $gateway || ! $gateway->is_configured() || empty( $charge_reference ) ) {
                \AmirBooking\Payments\PaymentEventLogger::log(
                    $booking_id,
                    $booking->payment_gateway ?: 'stripe',
                    'refund_skipped',
                    'Sin charge de referencia o pasarela no configurada — reembolsar manualmente'
                );
                return;
            }

            $success = $gateway->refund( $charge_reference, $refund_mxn );
            \AmirBooking\Payments\PaymentEventLogger::log(
                $booking_id,
                $gateway->id(),
                $success ? 'refund_succeeded' : 'refund_failed',
                '',
                [ 'amount_mxn' => $refund_mxn, 'charge_reference' => $charge_reference ]
            );
        }, 10, 2 );

        // ── Marketplace de proveedores: ledger de liquidación ─────────────
        // BookingManager::provider_approve() dispara esta acción — genera
        // automáticamente la fila 'pending' del ledger (amir_provider_payouts)
        // con el costo del proveedor para esa reserva, calculado desde
        // amir_prices.provider_cost_mxn (mismo criterio de vigencia/horario
        // que el precio de venta, ver PricingEngine::calculate_provider_cost()).
        add_action( 'amir_provider_booking_approved', function ( int $booking_id ) {
            global $wpdb;
            $booking = $wpdb->get_row( $wpdb->prepare(
                "SELECT b.*, t.provider_id
                 FROM {$wpdb->prefix}amir_bookings b
                 JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                 WHERE b.id = %d", $booking_id
            ) );
            if ( ! $booking || ! $booking->provider_id ) {
                return;
            }

            $cost = ( new \AmirBooking\Core\PricingEngine() )->calculate_provider_cost(
                (int) $booking->tour_id,
                (int) $booking->schedule_id,
                $booking->tour_date,
                (int) $booking->adults,
                (int) $booking->children,
                (int) $booking->babies
            );

            if ( $cost <= 0 ) {
                return;
            }

            $wpdb->insert(
                "{$wpdb->prefix}amir_provider_payouts",
                [
                    'provider_id' => (int) $booking->provider_id,
                    'booking_id'  => $booking_id,
                    'amount_mxn'  => $cost,
                    'status'      => 'pending',
                    'created_at'  => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%f', '%s', '%s' ]
            );
        }, 10, 1 );

        // ── Actualización de DB cuando la versión del esquema cambia ──────
        if ( get_option( 'amir_db_version', '0' ) !== AMIR_DB_VERSION ) {
            Installer::maybe_update();
        }

        // Red de seguridad: maybe_update() marca la migración como hecha
        // (amir_db_version) aunque algún ALTER TABLE puntual haya fallado
        // en el host (permisos, versión de MySQL, etc.) — sin esto, una
        // columna que no llegó a crearse queda faltante para siempre. Chequeo
        // barato (una sola vez por request) fuera del gate de versión.
        Installer::ensure_tour_columns();

        // Mismo criterio, para el ENUM de amir_bookings.status/booking_source
        // — bug real confirmado en producción: reactivar el plugin corría
        // create_tables() (dbDelta, no altera ENUMs existentes) y pisaba
        // amir_db_version igual, dejando 'date_requested' faltante para
        // siempre sin que maybe_update() lo volviera a intentar. Ver el
        // docblock de Installer::ensure_booking_status_enum().
        Installer::ensure_booking_status_enum();
    }
}
