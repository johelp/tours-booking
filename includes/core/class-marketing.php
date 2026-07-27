<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Meta Pixel + Google Ads/GA4 — configurables en Configuración → Marketing.
 * Nada de esto carga si no hay al menos un ID cargado (ver print_base_scripts()).
 *
 * Los scripts base (fbq/gtag) van en wp_head, sitio entero, como cualquier
 * instalación estándar de estos píxeles (remarketing/audiencias necesitan
 * verlos en todas las páginas, no solo en la del tour). Los eventos del
 * embudo específicos del booking (ViewContent, InitiateCheckout, Purchase)
 * se disparan aparte: ViewContent desde templates/single-amir_tour.php
 * (server-side, ya tiene los datos del tour a mano), InitiateCheckout y
 * Purchase desde el widget de React (ver react-src/src/marketing.js) porque
 * dependen de la interacción del cliente.
 */
class Marketing {

    public static function init(): void {
        add_action( 'wp_head', [ __CLASS__, 'print_base_scripts' ], 5 );
    }

    /** Expone los IDs que el frontend necesita para el evento de conversión de Google Ads (send_to). */
    public static function widget_config(): array {
        return [
            'gadsConversionId'    => get_option( 'amir_gads_conversion_id', '' ),
            'gadsConversionLabel' => get_option( 'amir_gads_conversion_label', '' ),
        ];
    }

    public static function print_base_scripts(): void {
        $meta_pixel = trim( (string) get_option( 'amir_meta_pixel_id', '' ) );
        $gads_id    = trim( (string) get_option( 'amir_gads_conversion_id', '' ) );
        $ga4_id     = trim( (string) get_option( 'amir_ga4_id', '' ) );

        if ( ! $meta_pixel && ! $gads_id && ! $ga4_id ) {
            return;
        }

        if ( $gads_id || $ga4_id ) {
            // Cualquiera de los dos sirve como `id` del <script src>: gtag.js
            // despacha a todos los `gtag('config', ...)` que se llamen abajo,
            // no solo al de la URL de carga.
            $loader_id = $ga4_id ?: $gads_id;
            ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $loader_id ); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){ dataLayer.push(arguments); }
gtag('js', new Date());
<?php if ( $ga4_id ) : ?>
gtag('config', '<?php echo esc_js( $ga4_id ); ?>');
<?php endif; ?>
<?php if ( $gads_id ) : ?>
gtag('config', '<?php echo esc_js( $gads_id ); ?>');
<?php endif; ?>
</script>
            <?php
        }

        if ( $meta_pixel ) :
            ?>
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo esc_js( $meta_pixel ); ?>');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=<?php echo esc_attr( $meta_pixel ); ?>&ev=PageView&noscript=1"
/></noscript>
            <?php
        endif;
    }
}
