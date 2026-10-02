<?php
namespace AmirBooking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/amir/v1/config
 *
 * Config de arranque para un cliente externo (app móvil, web headless) que
 * no tiene la página de WordPress que hoy inyecta esto vía wp_localize_script()
 * (ver Shortcodes::enqueue_widget_assets()) — mismo payload, mismos valores,
 * ya son todos seguros para exponer públicamente (stripePk es la publishable
 * key, nunca la secreta). Sin `nonce`: los endpoints públicos de reservas no
 * lo verifican (ver GUIA-INTEGRACION-API.md), así que no tiene sentido acá.
 */
class ConfigController {

    private const NAMESPACE = 'amir/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/config', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_config' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function get_config( \WP_REST_Request $request ): \WP_REST_Response {
        $stripe_mode = get_option( 'amir_stripe_mode', 'test' );
        $theme       = \AmirBooking\Core\WidgetTheme::resolved_values();

        return rest_ensure_response( [
            // 'lite'/'pro'/'pro_max' — para que un cliente externo (app,
            // web headless) sepa si /wp-json/flow/v1/* (habitaciones +
            // carrito, solo Pro Max) va a existir antes de pegarle y
            // recibir un 404 confuso. Ver GUIA-INTEGRACION-API.md § 7.
            'edition'         => AMIR_EDITION,
            'stripePk'        => get_option( "amir_stripe_pk_{$stripe_mode}", '' ),
            'stripeMode'      => $stripe_mode,
            'mpMode'          => get_option( 'amir_mp_mode', 'test' ),
            'currency'        => \AmirBooking\Core\Currency::code(),
            'activeLanguages' => \AmirBooking\Core\Languages::active(),
            'siteUrl'         => get_site_url(),
            'companyName'     => get_option( 'amir_company_name', 'TourFlow' ),
            'waPhone'         => get_option( 'amir_wa_phone', '' ),
            'policyTextEs'    => get_option( 'amir_policy_text_es', '' ),
            'policyTextEn'    => get_option( 'amir_policy_text_en', '' ),
            'termsTextEs'     => get_option( 'amir_terms_text_es', '' ),
            'termsTextEn'     => get_option( 'amir_terms_text_en', '' ),
            'progressLabels'  => \AmirBooking\Core\WidgetTheme::progress_labels(),
            'theme'           => [
                'color'      => $theme['color'],
                'colorDark'  => $theme['color_dark'],
                'colorLight' => $theme['color_light'],
                'colorMid'   => $theme['color_mid'],
                'fontKey'    => $theme['font_key'],
                'fontStack'  => $theme['font_stack'],
                'fontScale'  => $theme['font_scale'],
                'radius'     => $theme['radius'],
                'radiusSm'   => $theme['radius_sm'],
            ],
            'marketing'       => \AmirBooking\Core\Marketing::widget_config(),
        ] );
    }
}
