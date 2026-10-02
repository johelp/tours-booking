<?php
/**
 * Datos compartidos entre las plantillas de detalle de tour (Clásica e
 * Inmersiva) — carga y computa todo lo que ambas necesitan, para no
 * duplicar esta lógica en cada archivo de plantilla. Se incluye siempre
 * desde AMIR_PLUGIN_DIR (no con una ruta relativa al archivo que lo
 * incluye) — así sigue funcionando aunque el operador copie solo el
 * archivo de la plantilla principal a la carpeta de su tema, sin la
 * subcarpeta parts/.
 *
 * Se ejecuta dentro del `while (have_posts()) : the_post(); ... endwhile;`
 * de la plantilla que lo incluye. Deja disponibles, entre otras:
 * $post_id, $db_id, $lang, $is_en, $title, $description, $cover,
 * $duration, $duration_fmt, $min_age, $max_capacity, $languages,
 * $meeting, $lat, $lng, $what_to_expect, $highlights, $includes,
 * $excludes, $itinerary_stops, $detail_facts, $faq_items, $gallery_ids,
 * $price_model, $price_from, $has_provider, $skip_upsell, $video_info (null, o
 * ['provider'=>'youtube'|'vimeo','id'=>string,'hash'=>string] — este
 * último solo relevante para Vimeo).
 */

defined( 'ABSPATH' ) || exit;

$post_id = get_the_ID();
$db_id   = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );

if ( ! $db_id ) {
    global $wpdb;
    $inserted = $wpdb->insert( "{$wpdb->prefix}amir_tours", [
        'slug'           => get_post_field( 'post_name', $post_id ) ?: sanitize_title( get_the_title( $post_id ) ),
        'status'         => 'active',
        'price_model'    => get_post_meta( $post_id, '_amir_price_model', true ) ?: 'percapita',
        'name_es'        => get_the_title( $post_id ),
        'name_en'        => get_post_meta( $post_id, '_amir_name_en', true ) ?: get_the_title( $post_id ),
        'description_es' => wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ),
        'duration_minutes' => (int) get_post_meta( $post_id, '_amir_duration_minutes', true ),
        'min_age'        => (int) get_post_meta( $post_id, '_amir_min_age', true ),
        'max_capacity'   => (int) get_post_meta( $post_id, '_amir_max_capacity', true ) ?: 10,
        'min_passengers' => (int) get_post_meta( $post_id, '_amir_min_passengers', true ) ?: 1,
        'languages'      => '["Español"]',
        'gallery_images' => '[]',
        'sort_order'     => (int) get_post_meta( $post_id, '_amir_sort_order', true ),
        'category_slugs' => json_encode( wp_get_post_terms( $post_id, \AmirBooking\CPT\TourPostType::TAXONOMY, [ 'fields' => 'slugs' ] ) ?: [] ),
    ] );
    if ( $inserted ) {
        $db_id = (int) $wpdb->insert_id;
        update_post_meta( $post_id, '_amir_tour_db_id', $db_id );
    }
}

// Bug real corregido 2026-07-30: acá solo se chequeaba Polylang y si no
// estaba activo caía HARDCODEADO a español — ignoraba WPML y el idioma
// del sitio de WordPress (get_locale()), que es el fallback real que usa
// el resto del plugin. En cualquier instalación en inglés sin Polylang,
// la ficha de detalle (título, descripción, highlights, itinerario — todo
// lo que sale de este partial) quedaba siempre en español pese a que el
// resto del sitio sí mostraba inglés. Shortcodes::detect_lang() ya resuelve
// Polylang → WPML → locale de WP correctamente, con el mismo fallback a
// default_lang() adentro — se reusa ese, en vez de duplicar la lógica acá.
// Pro Max es English-first (decisión del cliente 2026-08-04): si ninguna
// señal de idioma resuelve nada, cae a 'en' en vez de 'es' — Lite/Pro
// (Amir Adventours) siguen exactamente igual que siempre.
$lang  = \AmirBooking\Core\Shortcodes::detect_lang( AMIR_EDITION === 'pro_max' ? 'en' : null );
$is_en = $lang === 'en';

// Campos del tour — contenido multi-idioma vía Languages::tour_field():
// es/en siguen leyendo el post meta de siempre, cualquier idioma 3+
// (agregado por el operador en Configuración → Idiomas) lee del JSON
// _amir_content_i18n guardado por el editor del CPT.
$tour_i18n_obj = (object) [
    'name_es'            => get_the_title( $post_id ),
    'name_en'            => get_post_meta( $post_id, '_amir_name_en', true ),
    'meeting_point_es'   => get_post_meta( $post_id, '_amir_meeting_point_es', true ),
    'meeting_point_en'   => get_post_meta( $post_id, '_amir_meeting_point_en', true ),
    'what_to_expect_es'  => get_post_meta( $post_id, '_amir_what_to_expect_es', true ),
    'what_to_expect_en'  => get_post_meta( $post_id, '_amir_what_to_expect_en', true ),
    'highlights_es'      => get_post_meta( $post_id, '_amir_highlights_es', true ) ?: '[]',
    'highlights_en'      => get_post_meta( $post_id, '_amir_highlights_en', true ) ?: '[]',
    'includes_es'        => get_post_meta( $post_id, '_amir_includes_es', true ) ?: '[]',
    'includes_en'        => get_post_meta( $post_id, '_amir_includes_en', true ) ?: '[]',
    'excludes_es'        => get_post_meta( $post_id, '_amir_excludes_es', true ) ?: '[]',
    'excludes_en'        => get_post_meta( $post_id, '_amir_excludes_en', true ) ?: '[]',
    'content_i18n'       => get_post_meta( $post_id, '_amir_content_i18n', true ) ?: '{}',
    'itinerary_stops'    => get_post_meta( $post_id, '_amir_itinerary_stops', true ) ?: '[]',
    'detail_facts'       => get_post_meta( $post_id, '_amir_detail_facts', true ) ?: '[]',
    'faq_items'          => get_post_meta( $post_id, '_amir_faq_items', true ) ?: '[]',
];
$name_en        = $tour_i18n_obj->name_en;
$duration       = (int) get_post_meta( $post_id, '_amir_duration_minutes', true );
$min_age        = (int) get_post_meta( $post_id, '_amir_min_age',    true );
$max_capacity   = (int) get_post_meta( $post_id, '_amir_max_capacity', true );
$languages_str  = get_post_meta( $post_id, '_amir_languages',        true );
$languages      = json_decode( $languages_str ?: '[]', true );
$meeting        = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'meeting_point', $lang );
$lat            = get_post_meta( $post_id, '_amir_meeting_lat',      true );
$lng            = get_post_meta( $post_id, '_amir_meeting_lng',      true );
$what_to_expect = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'what_to_expect', $lang );
$highlights_raw = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'highlights', $lang );
$includes_raw   = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'includes', $lang );
$excludes_raw   = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'excludes', $lang );
$highlights     = is_array( $highlights_raw ) ? $highlights_raw : ( json_decode( $highlights_raw ?: '[]', true ) ?: [] );
$includes       = is_array( $includes_raw ) ? $includes_raw : ( json_decode( $includes_raw ?: '[]', true ) ?: [] );
$excludes       = is_array( $excludes_raw ) ? $excludes_raw : ( json_decode( $excludes_raw ?: '[]', true ) ?: [] );
$itinerary_stops= \AmirBooking\Core\Languages::itinerary_stops( $tour_i18n_obj, $lang );
$detail_facts   = \AmirBooking\Core\Languages::detail_facts( $tour_i18n_obj, $lang );
$faq_items      = \AmirBooking\Core\Languages::faq_items( $tour_i18n_obj, $lang );
$gallery_ids    = json_decode( get_post_meta( $post_id, '_amir_gallery_ids', true ) ?: '[]', true );
$price_model    = get_post_meta( $post_id, '_amir_price_model',      true );

// Video en la ficha (§ 16.46 CONTRIBUTING.md, pedido del cliente 2026-08-10)
// — disponible en las tres ediciones. Mismo criterio laxo que
// flow_youtube_id() (room-data.php, § 16.11): sin validación dura en el
// editor, un video no reconocido simplemente no muestra miniatura acá.
// Extendido a Vimeo además de YouTube (rooms hoy solo soporta YouTube).
if ( ! function_exists( 'amir_parse_video_url' ) ) {
    function amir_parse_video_url( string $url ): ?array {
        if ( $url === '' ) {
            return null;
        }
        if ( preg_match( '/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
            return [ 'provider' => 'youtube', 'id' => $m[1] ];
        }
        if ( preg_match( '/vimeo\.com\/(?:video\/)?(\d+)(?:\/([a-zA-Z0-9]+))?/', $url, $m ) ) {
            return [ 'provider' => 'vimeo', 'id' => $m[1], 'hash' => $m[2] ?? '' ];
        }
        return null;
    }
}
$video_url  = get_post_meta( $post_id, '_amir_video_url', true );
$video_info = $video_url ? amir_parse_video_url( $video_url ) : null;

// Precio mínimo
$price_from = 0;
if ( $db_id ) {
    global $wpdb;
    $price_from = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices WHERE tour_id=%d AND price_mxn>0", $db_id
    ) );
}

// Marketplace de proveedores externos (§ 11 CONTRIBUTING.md): aviso
// discreto si el tour lo opera un proveedor externo — sin nombrarlo,
// para no darle al cliente pistas para reservar directo con él la
// próxima vez, saltando TourFlow. Un proveedor desactivado se trata
// igual que "sin proveedor" (mismo criterio que BookingManager::confirm()).
$has_provider = false;
if ( $db_id ) {
    global $wpdb;
    $provider_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT provider_id FROM {$wpdb->prefix}amir_tours WHERE id=%d", $db_id
    ) );
    if ( $provider_id > 0 ) {
        $has_provider = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT active FROM {$wpdb->prefix}amir_providers WHERE id=%d", $provider_id
        ) );
    }
}

// "Reserva directa" (Pro Max, checkbox del editor) — un tour que no tiene
// sentido combinar con nada más saltea el flujo con upsell ([flow_discovery])
// y usa el widget clásico ([flow_booking]) directo, mismo criterio de
// consulta liviana que $has_provider arriba.
$skip_upsell = false;
if ( $db_id ) {
    global $wpdb;
    $skip_upsell = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT skip_upsell FROM {$wpdb->prefix}amir_tours WHERE id=%d", $db_id
    ) );
}

$title        = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'name', $lang ) ?: get_the_title();
$description  = get_the_content();
$cover        = get_the_post_thumbnail_url( $post_id, 'full' );
$duration_fmt = $duration >= 60
    ? round( $duration / 60, 1 ) . ( $is_en ? ' h' : ' h' )
    : $duration . ( $is_en ? ' min' : ' min' );
?>

<!-- Schema.org TouristTrip — datos ricos para rich results de Google y para
     motores de búsqueda con IA (ChatGPT, Perplexity, Google AI Overview),
     armados con los mismos datos estructurados que ya tiene el tour
     (precio, duración, imágenes, ubicación) — ver Core\StructuredData. -->
<script type="application/ld+json">
<?php
$gallery_urls = array_values( array_filter( array_map(
    fn( $img_id ) => wp_get_attachment_image_url( $img_id, 'large' ),
    $gallery_ids
) ) );
if ( $cover ) {
    array_unshift( $gallery_urls, $cover );
}

echo json_encode( \AmirBooking\Core\StructuredData::tour_schema( [
    'name'             => $title,
    'description'      => wp_strip_all_tags( $description ),
    'url'              => get_permalink(),
    'images'           => $gallery_urls,
    'duration_minutes' => $duration,
    'min_age'          => $min_age,
    'languages'        => $languages,
    'lat'              => $lat,
    'lng'              => $lng,
    'meeting_point'    => $meeting,
    'price_from'       => $price_from,
    'currency'         => \AmirBooking\Core\Currency::code(),
    'provider_name'    => get_bloginfo( 'name' ),
    'provider_url'     => home_url( '/' ),
] ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
?>
</script>

<?php
// ViewContent/view_item — solo si hay algún píxel configurado (los scripts
// base de fbq/gtag ya se imprimieron en wp_head vía Core\Marketing). El
// resto del embudo (InitiateCheckout, Purchase) lo dispara el widget de
// React — ver react-src/src/marketing.js.
$has_pixels = get_option( 'amir_meta_pixel_id' ) || get_option( 'amir_gads_conversion_id' ) || get_option( 'amir_ga4_id' );
if ( $has_pixels && $db_id ) :
    $view_event_data = [
        'id'       => $db_id,
        'name'     => $title,
        'price'    => $price_from,
        'currency' => \AmirBooking\Core\Currency::code(),
    ];
?>
<script>
(function(){
  var t = <?php echo wp_json_encode( $view_event_data, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
  if (window.fbq) {
    fbq('track', 'ViewContent', { content_ids: [String(t.id)], content_type: 'product', value: t.price, currency: t.currency });
  }
  if (window.gtag) {
    gtag('event', 'view_item', { currency: t.currency, value: t.price, items: [{ item_id: String(t.id), item_name: t.name, price: t.price }] });
  }
})();
</script>
<?php endif; ?>
