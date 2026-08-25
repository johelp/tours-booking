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

    const CONSENT_COOKIE = 'amir_cookie_consent';

    public static function init(): void {
        add_action( 'wp_head', [ __CLASS__, 'print_base_scripts' ], 5 );
        add_action( 'wp_footer', [ __CLASS__, 'print_consent_banner' ] );
    }

    /** Expone los IDs que el frontend necesita para el evento de conversión de Google Ads (send_to). */
    public static function widget_config(): array {
        return [
            'gadsConversionId'    => get_option( 'amir_gads_conversion_id', '' ),
            'gadsConversionLabel' => get_option( 'amir_gads_conversion_label', '' ),
        ];
    }

    private static function has_any_pixel_configured(): bool {
        return (bool) (
            trim( (string) get_option( 'amir_meta_pixel_id', '' ) )
            || trim( (string) get_option( 'amir_gads_conversion_id', '' ) )
            || trim( (string) get_option( 'amir_ga4_id', '' ) )
        );
    }

    /**
     * GDPR: los píxeles de abajo son de terceros (Meta/Google), así que no
     * se cargan hasta que el visitante decida en el banner — el cookie de
     * consentimiento se lee server-side porque los scripts se imprimen en
     * wp_head, antes de que corra cualquier JS del visitante.
     */
    private static function has_cookie_consent(): bool {
        return isset( $_COOKIE[ self::CONSENT_COOKIE ] ) && $_COOKIE[ self::CONSENT_COOKIE ] === 'accepted';
    }

    /**
     * Banner simple Aceptar/Rechazar (sin categorías — el plugin no tiene
     * cookies propias que valga la pena separar de las de Marketing). Al
     * aceptar, recarga la página para que print_base_scripts() (arriba,
     * en wp_head) vea el cookie recién puesto y cargue los píxeles.
     */
    public static function print_consent_banner(): void {
        if ( ! self::has_any_pixel_configured() ) {
            return;
        }
        if ( isset( $_COOKIE[ self::CONSENT_COOKIE ] ) ) {
            return; // ya decidió (accepted o rejected) — no volver a preguntar
        }

        $is_en = ( Shortcodes::detect_lang() === 'en' );
        $text = trim( (string) get_option( $is_en ? 'amir_cookie_banner_text_en' : 'amir_cookie_banner_text_es', '' ) );
        if ( ! $text ) {
            $text = $is_en
                ? 'We use analytics and advertising cookies to improve your experience.'
                : 'Usamos cookies de analítica y publicidad para mejorar tu experiencia.';
        }
        $accept_label = $is_en ? 'Accept' : 'Aceptar';
        $reject_label = $is_en ? 'Reject' : 'Rechazar';
        ?>
<div id="amir-cookie-banner" style="position:fixed;left:0;right:0;bottom:0;z-index:999999;background:#1a2e24;color:#fff;padding:16px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;font-size:13px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;box-shadow:0 -2px 16px rgba(0,0,0,.15);">
  <p style="margin:0;flex:1 1 320px;line-height:1.4;"><?php echo esc_html( $text ); ?></p>
  <div style="display:flex;gap:8px;flex:0 0 auto;">
    <button type="button" id="amir-cookie-reject" style="background:transparent;color:#fff;border:1px solid #5a7068;border-radius:6px;padding:8px 16px;font-size:13px;cursor:pointer;"><?php echo esc_html( $reject_label ); ?></button>
    <button type="button" id="amir-cookie-accept" style="background:#1D9E75;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;"><?php echo esc_html( $accept_label ); ?></button>
  </div>
</div>
<script>
(function(){
  function setConsent(value){
    var d = new Date(); d.setTime(d.getTime() + 180*24*60*60*1000);
    document.cookie = '<?php echo esc_js( self::CONSENT_COOKIE ); ?>=' + value + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
  }
  var banner = document.getElementById('amir-cookie-banner');
  var acceptBtn = document.getElementById('amir-cookie-accept');
  var rejectBtn = document.getElementById('amir-cookie-reject');
  if ( acceptBtn ) acceptBtn.addEventListener('click', function(){ setConsent('accepted'); location.reload(); });
  if ( rejectBtn ) rejectBtn.addEventListener('click', function(){ setConsent('rejected'); if (banner) banner.style.display = 'none'; });
})();
</script>
        <?php
    }

    public static function print_base_scripts(): void {
        $meta_pixel = trim( (string) get_option( 'amir_meta_pixel_id', '' ) );
        $gads_id    = trim( (string) get_option( 'amir_gads_conversion_id', '' ) );
        $ga4_id     = trim( (string) get_option( 'amir_ga4_id', '' ) );

        if ( ! $meta_pixel && ! $gads_id && ! $ga4_id ) {
            return;
        }

        if ( ! self::has_cookie_consent() ) {
            return; // el banner (wp_footer) todavía no tiene un "Aceptar" del visitante
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
