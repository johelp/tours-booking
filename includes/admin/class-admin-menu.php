<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Menú de administración en el panel de WordPress.
 * Registra todos los submenús y pages del plugin.
 */
class AdminMenu {

    public function register(): void {
        add_action( 'admin_menu',   [ $this, 'add_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        // La descarga del PDF debe ocurrir antes de que WordPress envíe cualquier HTML
        add_action( 'admin_init',   [ $this, 'maybe_stream_pdf' ] );

        // Mismo motivo — export CSV de Reportes y Reservas. Bug real
        // reportado por el cliente (2026-08-24): antes se disparaban desde
        // dentro del callback de render() de cada pantalla, que WordPress
        // invoca DESPUÉS de mandar las cabeceras HTTP + el HTML del admin,
        // así que el CSV se mostraba como texto en pantalla en vez de
        // descargarse (header() falla en silencio con headers ya enviados).
        add_action( 'admin_init',   [ $this, 'maybe_export_reports_csv' ] );
        add_action( 'admin_init',   [ $this, 'maybe_export_bookings_csv' ] );
        add_action( 'admin_init',   [ $this, 'maybe_export_manifest_pdf' ] );

        // Modo campo: pantalla completa, sin nada del chrome de WP (pensada
        // para el celular). La admin bar se decide temprano (antes de que WP
        // la arme), por eso va en admin_init y no dentro del callback de la
        // página — ahí ya es tarde. Calendario NO va acá — el cliente pidió
        // que se vea como cualquier otra pantalla del plugin, con el menú de
        // WP visible.
        add_action( 'admin_init', [ $this, 'maybe_hide_admin_bar_fullscreen' ] );
        add_action( 'admin_head', [ $this, 'maybe_print_fullscreen_css' ] );

        // El menú del plugin (con sus encabezados de sección) es visible en
        // TODO el admin de WP, no solo en las páginas del plugin — por eso
        // va en admin_head sin el guard de enqueue_admin_assets() (que sí
        // filtra por $hook, ver abajo).
        add_action( 'admin_head', [ $this, 'print_menu_separator_css' ] );
    }

    /**
     * Estiliza los ítems separadores del submenú (slug '#sep-xxx', ver
     * add_menus()) como encabezado de sección en vez de link.
     *
     * Bug real corregido 2026-08-04: el CSS original apuntaba a
     * `[href*="page=%23sep-"]` (el `#` codificado) — pero WordPress arma el
     * href con el `#` LITERAL (`esc_url()` no lo codifica, es un carácter
     * válido de URL), así que ese selector nunca matcheaba nada, el
     * `pointer-events:none` nunca se aplicaba, y el navegador interpretaba
     * `admin.php?page=` + el fragmento `#sep-...` como dos cosas separadas:
     * navegaba a `admin.php?page=` (vacío) en vez de a la página fantasma
     * que se esperaba bloquear. El ítem quedaba clickeable de verdad,
     * llevando a una pantalla en blanco/rota — exactamente lo que reportó
     * el cliente. Corregido con un selector que no depende de cómo se
     * codifique el `#` (`[href*="sep-"]`, alcanza — ningún submenú real del
     * plugin usa "sep-" en su slug) + JS que saca el `href` del todo en vez
     * de confiar solo en CSS (a prueba de que algo más pise el estilo).
     */
    public function print_menu_separator_css(): void {
        echo '<style>
            #adminmenu a[href*="sep-"] {
                pointer-events: none;
                cursor: default;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .5px;
                opacity: .6;
                padding-top: 12px;
                padding-bottom: 4px;
            }
            #adminmenu a[href*="sep-"]:hover { background: transparent; }
        </style>
        <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll(\'#adminmenu a[href*="sep-"]\').forEach(function (a) {
                a.removeAttribute("href");
                a.setAttribute("tabindex", "-1");
                a.setAttribute("aria-disabled", "true");
            });
        });
        </script>';
    }

    /** Páginas que se muestran fullscreen, sin el chrome de WP admin. */
    private const FULLSCREEN_PAGES = [ 'amir-field' ];

    public function maybe_hide_admin_bar_fullscreen(): void {
        if ( in_array( $_GET['page'] ?? '', self::FULLSCREEN_PAGES, true ) ) {
            add_filter( 'show_admin_bar', '__return_false' );
        }
    }

    public function maybe_print_fullscreen_css(): void {
        if ( ! in_array( $_GET['page'] ?? '', self::FULLSCREEN_PAGES, true ) ) {
            return;
        }
        echo '<style>
            #adminmenumain, #adminmenuback, #adminmenuwrap, #wpfooter { display: none !important; }
            #wpcontent, #wpbody-content { margin-left: 0 !important; }
            #wpbody { padding-top: 0 !important; }
        </style>';
    }

    /**
     * Detecta ?page=amir-bookings-list&action=pdf&id=X en admin_init
     * (antes de que WordPress renderice el admin header) y hace stream del PDF.
     */
    public function maybe_stream_pdf(): void {
        if (
            ( $_GET['page']   ?? '' ) !== 'amir-bookings-list' ||
            ( $_GET['action'] ?? '' ) !== 'pdf'                ||
            empty( $_GET['id'] )                               ||
            ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' )
        ) {
            return;
        }

        ( new \AmirBooking\Core\VoucherGenerator() )->stream( (int) $_GET['id'] );
        exit;
    }

    public function maybe_export_reports_csv(): void {
        if (
            ( $_GET['page']   ?? '' ) !== 'amir-reports' ||
            ( $_GET['export'] ?? '' ) !== 'csv'           ||
            ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' )
        ) {
            return;
        }

        ( new \AmirBooking\Admin\ReportsPage() )->export_csv();
        exit; // export_csv() ya hace exit, este queda como red de seguridad
    }

    public function maybe_export_bookings_csv(): void {
        if (
            ( $_GET['page']   ?? '' ) !== 'amir-bookings-list' ||
            ( $_GET['action'] ?? '' ) !== 'export'               ||
            ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        ( new \AmirBooking\Admin\BookingsPage() )->export_csv_request();
        exit; // export_csv() ya hace exit, este queda como red de seguridad
    }

    /**
     * Manifiesto de pasajeros en PDF (pedido del cliente 2026-08-24) — por
     * período y opcionalmente un solo tour. Mismo motivo que los métodos de
     * arriba: tiene que correr antes de que WordPress mande HTML.
     */
    public function maybe_export_manifest_pdf(): void {
        if (
            ( $_GET['page']   ?? '' ) !== 'amir-reports' ||
            ( $_GET['export'] ?? '' ) !== 'manifest'      ||
            ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' )
        ) {
            return;
        }

        $from    = sanitize_text_field( $_GET['from']  ?? date( 'Y-m-d' ) );
        $until   = sanitize_text_field( $_GET['until'] ?? $from );
        $tour_id = (int) ( $_GET['tour_id'] ?? 0 );
        $lang    = strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';

        ( new \AmirBooking\Core\ManifestGenerator() )->stream( $from, $until, $tour_id, $lang );
        exit; // stream() ya hace exit, este queda como red de seguridad
    }

    /**
     * Nombre de marca del plugin — configurable desde Network Admin.
     * Default: 'TourFlow' (genérico, sin referencia a "Amir").
     */
    private function brand_name(): string {
        $name = is_multisite()
            ? get_site_option( 'amir_brand_name', '' )
            : get_option( 'amir_brand_name', '' );
        return $name ?: 'TourFlow';
    }

    public function add_menus(): void {
        $unread = $this->get_unread_count();
        $badge  = $unread > 0 ? " <span class='amir-badge'>{$unread}</span>" : '';
        $brand  = $this->brand_name();

        // Capacidad base: manage_options para admins, manage_amir_booking para Tour Managers
        $cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'manage_amir_booking';

        // Menú principal
        add_menu_page(
            $brand,
            $brand . $badge,
            $cap,
            'amir-booking',
            [ $this, 'page_dashboard' ],
            'dashicons-calendar-alt',
            30
        );

        // Módulos apagables por instalación (Configuración → 🧩 Módulos) —
        // por defecto todos prendidos (retrocompatible con instalaciones que
        // ya los venían usando). Apagados, el submenú ni se registra.
        // Marketplace y Lista de interés son Pro y superior (Pro Max hereda
        // todo lo de Pro, decisión del cliente 2026-08-03) — Lite los apaga
        // sin importar la opción guardada (Partners sí es Lite).
        $marketplace_on = in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) && get_option( 'amir_module_marketplace', '1' ) === '1';
        $wishlist_on    = in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) && get_option( 'amir_module_wishlist',    '1' ) === '1';
        $partners_on    = get_option( 'amir_module_partners', '1' ) === '1';

        // Submenús — agrupados en bloques lógicos (Operación diaria /
        // Contenido / Ventas y captación / Comunicación / Configuración),
        // separados por encabezados no clicables (slug '#sep-xxx' — WP los
        // registra como un submenú más, pero print_menu_separator_css()
        // los estiliza como etiqueta en vez de link, mismo truco que usan
        // otros plugins de WP). Antes era una lista plana de 12+ ítems sin
        // ningún orden temático — pedido explícito del cliente de
        // reorganizarla.
        $submenus = [];

        // ── Operación diaria ──
        // Íconos completados en todos los ítems (auditoría de UI 2026-08-05
        // — antes la mitad tenía emoji y la mitad no, sin ningún criterio,
        // se fue acumulando así sesión tras sesión). "Disponibilidad" y
        // "Disp. habitaciones" comparten el mismo ✅ a propósito: son el
        // mismo concepto (reglas de bloqueo/apertura de fechas) aplicado a
        // dos tipos de ítem — antes "Disp. habitaciones" vivía en Contenido
        // solo porque comparte Pro Max con el CPT de habitaciones, separada
        // sin motivo real de su equivalente de tours.
        $submenus[] = [ 'amir-booking',        __( '🏠 Dashboard',    'amir-booking' ), [ $this, 'page_dashboard'   ] ];
        $submenus[] = [ 'amir-calendar',       __( '📅 Calendario',   'amir-booking' ), [ $this, 'page_calendar'    ] ];
        $submenus[] = [ 'amir-field',          __( '📱 Modo campo',   'amir-booking' ), [ $this, 'page_field'       ] ];
        $submenus[] = [ 'amir-bookings-list',  __( '📖 Reservas',     'amir-booking' ), [ $this, 'page_bookings'    ] ];
        $submenus[] = [ 'amir-availability',   __( '✅ Disponibilidad', 'amir-booking' ), [ $this, 'page_availability'] ];
        if ( AMIR_EDITION === 'pro_max' ) {
            $submenus[] = [ 'flow-room-availability', __( '✅ Disp. habitaciones', 'amir-booking' ), [ $this, 'page_room_availability' ], 'manage_options' ];
        }

        // ── Contenido ── (Tours/Habitaciones apuntan directo al listado del
        // CPT — edit.php?post_type=... — WP los reconoce como página nativa
        // SOLO si el callback queda vacío. Bug real corregido 2026-08-06:
        // '__return_false' se creía "inofensivo" porque WP nunca llega a
        // EJECUTARLO — pero add_submenu_page() igual lo registra con
        // add_action() apenas el callback no está vacío (ver su código
        // fuente: `if ( ! empty( $callback ) && ! empty( $hookname ) )
        // add_action( $hookname, $callback );`). Con el hook ya registrado,
        // get_plugin_page_hook() (wp-admin/menu-header.php, vía has_action())
        // deja de devolver null, y el link deja de armarse directo a
        // "edit.php?post_type=..." — se envuelve mal como
        // "admin.php?page=edit.php%3Fpost_type%3D..." (una URL que no
        // existe), rompiendo el ítem de menú entero. Reportado en vivo por
        // el cliente: Tours Y Habitaciones no cargaban nada. Callback vacío
        // ('') es el valor default real de add_submenu_page() — ninguno de
        // estos ítems necesita uno, ni siquiera los separadores.
        $submenus[] = [ '#sep-contenido', __( '— Contenido —', 'amir-booking' ), '', $cap ];
        $submenus[] = [ 'edit.php?post_type=amir_tour',      __( '🏄 Tours',      'amir-booking' ), '', $cap ];
        $submenus[] = [ 'post-new.php?post_type=amir_tour',  __( '+ Nuevo tour',  'amir-booking' ), '', $cap ];
        // Habitaciones (Pro Max, CONTRIBUTING.md § 16) — 'manage_options'
        // explícito (no el $cap compartido con Tour Manager):
        // RoomPostType::save_meta() hoy solo acepta manage_options —
        // mostrar el menú a alguien que después no puede guardar sería un
        // fallo silencioso confuso. Habilitar Tour Manager acá es una
        // decisión de producto aparte, sin pedir todavía.
        if ( AMIR_EDITION === 'pro_max' ) {
            $submenus[] = [ 'edit.php?post_type=flow_room',     __( '🛏 Habitaciones',      'amir-booking' ), '', 'manage_options' ];
            $submenus[] = [ 'post-new.php?post_type=flow_room', __( '+ Nueva habitación',   'amir-booking' ), '', 'manage_options' ];
        }

        // ── Ventas y captación ──
        $submenus[] = [ '#sep-ventas', __( '— Ventas y captación —', 'amir-booking' ), '', $cap ];
        $submenus[] = [ 'amir-coupons', __( '🏷️ Cupones', 'amir-booking' ), [ $this, 'page_coupons' ] ];
        // Extras globales (§ 16.23 CONTRIBUTING.md) — solo Pro Max, es lo
        // único que hoy los consume (paso de extras del flujo continuo,
        // type:'addon' del carrito). 'manage_options' explícito, mismo
        // criterio que Habitaciones arriba.
        if ( AMIR_EDITION === 'pro_max' ) {
            $submenus[] = [ 'amir-global-addons', __( '🎁 Extras globales', 'amir-booking' ), [ $this, 'page_global_addons' ], 'manage_options' ];
        }
        if ( $wishlist_on ) {
            $submenus[] = [ 'amir-wishlist', __( '🔔 Lista de interés', 'amir-booking' ), [ $this, 'page_wishlist' ] ];
        }
        if ( $partners_on ) {
            $submenus[] = [ 'amir-partners', __( '🔗 Partners', 'amir-booking' ), [ $this, 'page_partners' ] ];
        }
        if ( $marketplace_on ) {
            $submenus[] = [ 'amir-providers',         __( '🤝 Proveedores', 'amir-booking' ), [ $this, 'page_providers'        ] ];
            $submenus[] = [ 'amir-provider-payouts',  __( '💸 Liquidación', 'amir-booking' ), [ $this, 'page_provider_payouts' ] ];
        }

        // ── Comunicación y reportes ──
        $submenus[] = [ '#sep-comunicacion', __( '— Comunicación y reportes —', 'amir-booking' ), '', $cap ];
        $submenus[] = [ 'amir-email-test',  __( '✉️ Emails',       'amir-booking' ), [ $this, 'page_email_test'  ] ];
        $submenus[] = [ 'amir-reports',     __( '📊 Reportes',     'amir-booking' ), [ $this, 'page_reports'     ] ];
        $submenus[] = [ 'amir-payment-log', __( '💳 Log de pagos', 'amir-booking' ), [ $this, 'page_payment_log' ] ];

        // ── Configuración: solo-admin siempre, sin excepción — 'manage_options'
        // explícito acá, no el $cap compartido de arriba, para que un Tour
        // Manager (gestor de tienda) ni vea el ítem de menú. El render() de
        // ambas páginas ya exigía manage_options por su cuenta (así que no
        // había fuga real de datos), pero mostrar un ítem de menú que
        // termina en wp_die() es mala UX — esto lo saca de raíz.
        $submenus[] = [ '#sep-config', __( '— Configuración —', 'amir-booking' ), '', $cap ];
        $submenus[] = [ 'amir-personalization', __( '🎨 Personalización', 'amir-booking' ), [ $this, 'page_personalization' ], 'manage_options' ];
        $submenus[] = [ 'amir-settings',        __( '⚙️ Configuración',   'amir-booking' ), [ $this, 'page_settings'         ], 'manage_options' ];

        foreach ( $submenus as $item ) {
            [ $slug, $title, $callback ] = $item;
            add_submenu_page(
                'amir-booking',
                $title,
                $title,
                $item[3] ?? $cap,
                $slug,
                $callback
            );
        }
    }

    public function page_room_availability(): void {
        ( new \TourFlow\Rooms\RoomAvailabilityPage() )->render();
    }

    // ── Páginas del admin (PHP nativo, sin SPA) ────────────────────────────

    public function page_dashboard(): void {
        ( new \AmirBooking\Admin\DashboardPage() )->render();
    }
    public function page_calendar(): void {
        ( new \AmirBooking\Admin\CalendarPage() )->render();
    }
    public function page_field(): void {
        ( new \AmirBooking\Admin\FieldPage() )->render();
    }
    public function page_bookings(): void {
        ( new \AmirBooking\Admin\BookingsPage() )->render();
    }
    public function page_availability(): void {
        ( new \AmirBooking\Admin\AvailabilityPage() )->render();
    }
    public function page_partners(): void {
        ( new \AmirBooking\Admin\PartnersPage() )->render();
    }
    public function page_wishlist(): void {
        ( new \AmirBooking\Admin\WishlistPage() )->render();
    }
    public function page_reports(): void {
        ( new \AmirBooking\Admin\ReportsPage() )->render();
    }
    public function page_payment_log(): void {
        ( new \AmirBooking\Admin\PaymentLogPage() )->render();
    }
    public function page_coupons(): void {
        ( new \AmirBooking\Admin\CouponsPage() )->render();
    }
    public function page_providers(): void {
        ( new \AmirBooking\Admin\ProvidersPage() )->render();
    }
    public function page_provider_payouts(): void {
        ( new \AmirBooking\Admin\ProviderPayoutsPage() )->render();
    }
    public function page_email_test(): void {
        ( new \AmirBooking\Admin\EmailTestPage() )->render();
    }
    public function page_settings(): void {
        ( new \AmirBooking\Admin\SettingsPage() )->render();
    }
    public function page_personalization(): void {
        ( new \AmirBooking\Admin\PersonalizationPage() )->render();
    }
    public function page_global_addons(): void {
        ( new \AmirBooking\Admin\GlobalAddonsPage() )->render();
    }

    // ── Assets ─────────────────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        // Solo en páginas del plugin
        if ( strpos( $hook, 'amir' ) === false ) {
            return;
        }

        // Media library: necesaria para el selector de logo en Settings.
        // Debe cargarse aquí (admin_enqueue_scripts) y NO dentro del callback
        // de la página — ese se ejecuta después del <head> y los scripts
        // de wp.media quedarían fuera del contexto correcto.
        if ( strpos( $hook, 'amir-settings' ) !== false || strpos( $hook, 'amir-personalization' ) !== false || strpos( $hook, 'amir-global-addons' ) !== false ) {
            wp_enqueue_media();
        }

        wp_enqueue_style(
            'amir-admin',
            AMIR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AMIR_VERSION
        );

        wp_enqueue_script(
            'amir-admin',
            AMIR_PLUGIN_URL . 'assets/js/admin.js',
            [],
            AMIR_VERSION,
            true
        );

        // Pasar datos al JS del admin (polling de notificaciones, etc.)
        wp_localize_script( 'amir-admin', 'amirAdminData', [
            'apiUrl'   => rest_url( 'amir/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'currency' => get_option( 'amir_currency', 'MXN' ),
            'siteUrl'  => get_site_url(),
            'version'  => AMIR_VERSION,
        ] );
    }

    // ── Badge de notificaciones ────────────────────────────────────────────

    private function get_unread_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amir_notifications WHERE is_read = 0"
        );
    }
}
