<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gestión de licencias — stub para modo SaaS gestionado.
 *
 * En modo SaaS gestionado (Multisite propio) is_active() siempre retorna true.
 * Cuando el plugin se distribuya como producto independiente, reemplazar el
 * cuerpo de is_active() con una llamada a EDD Software Licensing, LemonSqueezy,
 * Paddle, etc. El resto del plugin no necesita cambios.
 *
 * Opciones de red (wp_sitemeta):
 *   amir_license_key   → clave de licencia (futuro)
 *   amir_license_plan  → 'starter' | 'pro' | 'enterprise'
 *   amir_license_mode  → 'saas' | 'standalone'
 */
class LicenseManager {

    // ── Verificación ──────────────────────────────────────────────────────

    /**
     * ¿El plugin está activo y con licencia válida?
     * En modo SaaS gestionado siempre true — el control es por Multisite.
     */
    public static function is_active(): bool {
        return true;
    }

    /**
     * Plan activo: 'starter' | 'pro' | 'enterprise'.
     * Se guarda en wp_sitemeta para ser network-wide.
     */
    public static function get_plan(): string {
        if ( is_multisite() ) {
            return (string) get_site_option( 'amir_license_plan', 'pro' );
        }
        return (string) get_option( 'amir_license_plan', 'pro' );
    }

    /**
     * Clave de licencia. 'saas-managed' en modo SaaS.
     */
    public static function get_site_key(): string {
        if ( is_multisite() ) {
            return (string) get_site_option( 'amir_license_key', 'saas-managed' );
        }
        return (string) get_option( 'amir_license_key', 'saas-managed' );
    }

    /**
     * ¿Está corriendo en un Multisite gestionado centralmente?
     */
    public static function is_network_managed(): bool {
        return is_multisite();
    }

    // ── Feature flags por plan ────────────────────────────────────────────
    // Reservados para uso futuro. Actualmente 'pro' tiene todo habilitado.

    public static function can( string $feature ): bool {
        $plan = self::get_plan();

        $matrix = array(
            'mercadopago'      => array( 'pro', 'enterprise' ),
            'partners'         => array( 'pro', 'enterprise' ),
            'reports'          => array( 'pro', 'enterprise' ),
            'white_label'      => array( 'enterprise' ),
            'api_access'       => array( 'enterprise' ),
            'coupons'          => array( 'pro', 'enterprise' ),
            'dynamic_pricing'  => array( 'pro', 'enterprise' ),
            'abandonment_email'=> array( 'pro', 'enterprise' ),
        );

        if ( ! isset( $matrix[ $feature ] ) ) {
            return true; // feature desconocida: permitir por defecto
        }

        return in_array( $plan, $matrix[ $feature ], true );
    }

    // ── Actualización de plan (Network Admin) ─────────────────────────────

    public static function set_plan( string $plan ): void {
        $valid = array( 'starter', 'pro', 'enterprise' );
        if ( ! in_array( $plan, $valid, true ) ) {
            return;
        }
        if ( is_multisite() ) {
            update_site_option( 'amir_license_plan', $plan );
        } else {
            update_option( 'amir_license_plan', $plan );
        }
    }

    public static function set_site_key( string $key ): void {
        if ( is_multisite() ) {
            update_site_option( 'amir_license_key', sanitize_text_field( $key ) );
        } else {
            update_option( 'amir_license_key', sanitize_text_field( $key ) );
        }
    }
}
