<?php
/**
 * Template para la página individual de un tour (CPT amir_tour).
 *
 * Cómo usar:
 *   1. Copiar este archivo a la carpeta raíz de tu tema activo.
 *   2. WordPress lo usará automáticamente para las URLs /tour/{slug}/
 *
 *   Alternativamente, si usas un tema hijo o un builder como Elementor,
 *   puedes crear un template con el Loop Builder y usar los Dynamic Tags
 *   del plugin (amir-price-from, amir-duration, etc.)
 *
 * Este template es el fallback funcional que funciona con cualquier tema.
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
    the_post();

    $post_id    = get_the_ID();
    $db_id = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );

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
        ] );
        if ( $inserted ) {
            $db_id = (int) $wpdb->insert_id;
            update_post_meta( $post_id, '_amir_tour_db_id', $db_id );
        }
    }
    $lang       = function_exists('pll_current_language') ? pll_current_language('slug') : 'es';
    $lang       = \AmirBooking\Core\Languages::is_active( (string) $lang ) ? $lang : \AmirBooking\Core\Languages::default_lang();
    $is_en      = $lang === 'en';

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
        'includes_es'        => get_post_meta( $post_id, '_amir_includes_es', true ) ?: '[]',
        'includes_en'        => get_post_meta( $post_id, '_amir_includes_en', true ) ?: '[]',
        'excludes_es'        => get_post_meta( $post_id, '_amir_excludes_es', true ) ?: '[]',
        'excludes_en'        => get_post_meta( $post_id, '_amir_excludes_en', true ) ?: '[]',
        'content_i18n'       => get_post_meta( $post_id, '_amir_content_i18n', true ) ?: '{}',
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
    $includes_raw   = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'includes', $lang );
    $excludes_raw   = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'excludes', $lang );
    $includes       = is_array( $includes_raw ) ? $includes_raw : ( json_decode( $includes_raw ?: '[]', true ) ?: [] );
    $excludes       = is_array( $excludes_raw ) ? $excludes_raw : ( json_decode( $excludes_raw ?: '[]', true ) ?: [] );
    $gallery_ids    = json_decode( get_post_meta( $post_id, '_amir_gallery_ids', true ) ?: '[]', true );
    $price_model    = get_post_meta( $post_id, '_amir_price_model',      true );

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

    $title        = \AmirBooking\Core\Languages::tour_field( $tour_i18n_obj, 'name', $lang ) ?: get_the_title();
    $description  = get_the_content();
    $cover        = get_the_post_thumbnail_url( $post_id, 'full' );
    $duration_fmt = $duration >= 60
        ? round($duration/60,1) . ($is_en?' h':' h')
        : $duration . ($is_en?' min':' min');
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

<div class="amir-single-tour">

  <!-- Hero -->
  <div class="amir-single-tour__hero" <?php if ($cover) echo 'style="background-image:url('.esc_url($cover).')"'; ?>>
    <div class="amir-single-tour__hero-overlay">
      <div class="amir-single-tour__hero-content">
        <h1 class="amir-single-tour__title"><?php echo esc_html($title); ?></h1>
        <div class="amir-single-tour__hero-chips">
          <?php if ($duration) : ?>
            <span class="amir-chip">⏱ <?php echo esc_html($duration_fmt); ?></span>
          <?php endif; ?>
          <?php if ($min_age) : ?>
            <span class="amir-chip">👤 <?php echo $is_en?'Min. age':'Edad mín.'; ?> <?php echo $min_age; ?>+</span>
          <?php endif; ?>
          <?php if (!empty($languages)) : ?>
            <span class="amir-chip">🌐 <?php echo esc_html(implode(', ',$languages)); ?></span>
          <?php endif; ?>
        </div>
        <?php if ($price_from > 0) : ?>
          <div class="amir-single-tour__price">
            <span class="amir-single-tour__price-from"><?php echo $is_en?'From':'Desde'; ?></span>
            <span class="amir-single-tour__price-value">$<?php echo number_format($price_from,0,'.',','); ?></span>
            <span class="amir-single-tour__price-cur"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></span>
          </div>
        <?php endif; ?>
        <?php if ($has_provider) : ?>
          <!-- TODO: copy a validar con el cliente antes de mergear (marketplace de proveedores, § 11 CONTRIBUTING.md) -->
          <span class="amir-single-tour__provider-badge">🤝 <?php echo $is_en ? 'Operated by a local partner' : 'Operado por un partner local'; ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="amir-single-tour__body">

    <!-- Galería -->
    <?php if (!empty($gallery_ids)) : ?>
    <div class="amir-gallery">
      <?php foreach ($gallery_ids as $img_id) :
        $url = wp_get_attachment_image_url($img_id,'large');
        if (!$url) continue;
      ?>
        <a href="<?php echo esc_url(wp_get_attachment_url($img_id)); ?>" class="amir-gallery__item">
          <img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="amir-single-tour__cols">

      <!-- Columna principal -->
      <div class="amir-single-tour__main">

        <!-- Descripción -->
        <div class="amir-single-tour__section">
          <h2><?php echo $is_en?'About this experience':'Acerca de esta experiencia'; ?></h2>
          <div class="amir-single-tour__description">
            <?php the_content(); ?>
          </div>
        </div>

        <!-- Qué esperar -->
        <?php if ($what_to_expect) : ?>
        <div class="amir-single-tour__section">
          <h2><?php echo $is_en?'What to expect':'Qué esperar'; ?></h2>
          <div class="amir-single-tour__description">
            <?php echo wp_kses_post(nl2br($what_to_expect)); ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Incluye / No incluye -->
        <?php if (!empty($includes) || !empty($excludes)) : ?>
        <div class="amir-single-tour__section">
          <div class="amir-incl-grid">
            <?php if (!empty($includes)) : ?>
            <div>
              <h3 class="amir-incl-title amir-incl-title--yes">✓ <?php echo $is_en?'Included':'Incluye'; ?></h3>
              <ul class="amir-incl-list amir-incl-list--yes">
                <?php foreach ($includes as $item) : ?>
                  <li><?php echo esc_html($item); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>
            <?php if (!empty($excludes)) : ?>
            <div>
              <h3 class="amir-incl-title amir-incl-title--no">✕ <?php echo $is_en?'Not included':'No incluye'; ?></h3>
              <ul class="amir-incl-list amir-incl-list--no">
                <?php foreach ($excludes as $item) : ?>
                  <li><?php echo esc_html($item); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Punto de encuentro -->
        <?php if ($meeting) : ?>
        <div class="amir-single-tour__section">
          <h2>📍 <?php echo $is_en?'Meeting point':'Punto de encuentro'; ?></h2>
          <p class="amir-single-tour__meeting-text"><?php echo esc_html($meeting); ?></p>
          <?php if ($lat && $lng) : ?>
            <div class="amir-single-tour__map">
              <iframe
                src="https://maps.google.com/maps?q=<?php echo esc_attr($lat); ?>,<?php echo esc_attr($lng); ?>&z=15&output=embed"
                width="100%" height="280" style="border:0;border-radius:10px;" allowfullscreen loading="lazy"
                referrerpolicy="no-referrer-when-downgrade">
              </iframe>
            </div>
            <a href="https://maps.google.com/?q=<?php echo esc_attr($lat); ?>,<?php echo esc_attr($lng); ?>"
               target="_blank" rel="noopener" class="amir-single-tour__maps-link">
              <?php echo $is_en?'Open in Google Maps ↗':'Ver en Google Maps ↗'; ?>
            </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div><!-- main -->

      <!-- Widget de reserva (sticky en desktop) -->
      <div class="amir-single-tour__booking-col">
        <div class="amir-single-tour__booking-sticky">
          <?php if ( $db_id ) : ?>
            <?php echo do_shortcode( '[amir_booking tour_id="' . $db_id . '" lang="' . $lang . '"]' ); ?>
          <?php else : ?>
            <div style="background:#f8fdfb;border:1px solid #e1f5ee;border-radius:12px;padding:24px;text-align:center;color:#5a7068;font-size:14px;">
              <?php echo $is_en ? 'Contact us to book this tour.' : 'Contáctanos para reservar.'; ?>
              <br><br>
              <a href="https://wa.me/<?php echo esc_attr( preg_replace('/[^0-9]/', '', get_option('amir_wa_phone','5219831649541') ) ); ?>"
                 style="display:inline-block;background:#25D366;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;">
                💬 WhatsApp
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- cols -->
  </div><!-- body -->
</div><!-- single-tour -->

<?php
$brand_color      = esc_attr( get_option( 'amir_brand_color', '#1D9E75' ) );
$brand_color_dark = esc_attr( get_option( 'amir_brand_color_dark', '#0F6E56' ) );
?>
<style>
.amir-single-tour { --teal:<?php echo $brand_color; ?>; --teal-dark:<?php echo $brand_color_dark; ?>; --teal-light:#e1f5ee; }
.amir-single-tour__hero { background:#1a2e24 center/cover no-repeat; min-height:340px; display:flex; align-items:flex-end; }
.amir-single-tour__hero-overlay { width:100%; background:linear-gradient(to top,rgba(0,0,0,.65) 0%,transparent 100%); padding:32px 24px 28px; }
.amir-single-tour__hero-content { max-width:760px; margin:0 auto; }
.amir-single-tour__title { font-size:clamp(22px,4vw,36px); font-weight:800; color:#fff; margin:0 0 12px; text-shadow:0 1px 4px rgba(0,0,0,.4); }
.amir-single-tour__hero-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.amir-chip { background:rgba(255,255,255,.2); color:#fff; font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); }
.amir-single-tour__price { display:flex; align-items:baseline; gap:6px; }
.amir-single-tour__price-from { color:rgba(255,255,255,.75); font-size:13px; }
.amir-single-tour__price-value { font-size:32px; font-weight:800; color:#fff; }
.amir-single-tour__price-cur { color:rgba(255,255,255,.75); font-size:14px; }
.amir-single-tour__provider-badge { display:inline-block; margin-top:8px; background:rgba(255,255,255,.15); color:rgba(255,255,255,.85); font-size:11px; font-weight:600; padding:4px 10px; border-radius:20px; backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); }

.amir-single-tour__body { max-width:1140px; margin:0 auto; padding:32px 20px 48px; }

/* Galería */
.amir-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; margin-bottom:32px; }
.amir-gallery__item { display:block; aspect-ratio:4/3; overflow:hidden; border-radius:8px; }
.amir-gallery__item img { width:100%; height:100%; object-fit:cover; transition:transform .3s; }
.amir-gallery__item:hover img { transform:scale(1.05); }

/* Layout de dos columnas */
.amir-single-tour__cols { display:grid; grid-template-columns:1fr 420px; gap:32px; align-items:start; }
.amir-single-tour__booking-sticky { position:sticky; top:80px; }

.amir-single-tour__section { margin-bottom:32px; }
.amir-single-tour__section h2 { font-size:20px; font-weight:700; color:#1a2e24; margin:0 0 14px; padding-bottom:8px; border-bottom:2px solid var(--teal-light); }
.amir-single-tour__section h3 { font-size:15px; font-weight:700; margin:0 0 10px; }
.amir-single-tour__description { font-size:15px; color:#3d3d3a; line-height:1.7; }
.amir-single-tour__meeting-text { font-size:14px; color:#3d3d3a; margin:0 0 14px; }
.amir-single-tour__map { margin-bottom:10px; }
.amir-single-tour__maps-link { color:var(--teal); font-size:13px; font-weight:600; text-decoration:none; }
.amir-single-tour__maps-link:hover { text-decoration:underline; }

/* Incluye / No incluye */
.amir-incl-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.amir-incl-title { display:flex; align-items:center; gap:6px; font-size:14px; }
.amir-incl-title--yes { color:var(--teal-dark); }
.amir-incl-title--no  { color:#c53030; }
.amir-incl-list { list-style:none; padding:0; margin:0; }
.amir-incl-list li { font-size:13px; color:#3d3d3a; padding:5px 0; border-bottom:1px solid #f5f5f5; display:flex; gap:8px; }
.amir-incl-list--yes li::before { content:"✓"; color:var(--teal); font-weight:700; flex-shrink:0; }
.amir-incl-list--no  li::before { content:"✕"; color:#c53030; font-weight:700; flex-shrink:0; }

@media (max-width:768px) {
  .amir-single-tour__cols { grid-template-columns:1fr; }
  .amir-single-tour__booking-sticky { position:static; }
  .amir-incl-grid { grid-template-columns:1fr; }
  .amir-single-tour__booking-col { order:-1; }
}
</style>

<?php
// ── Tours sugeridos ──────────────────────────────────────────────────────
wp_enqueue_style( 'amir-tour-cards' );

$suggested = new WP_Query( [
    'post_type'      => 'amir_tour',
    'post_status'    => 'publish',
    'posts_per_page' => 3,
    'post__not_in'   => [ $post_id ],
    'meta_key'       => '_amir_sort_order',
    'orderby'        => 'meta_value_num',
    'order'          => 'ASC',
] );

if ( $suggested->have_posts() ) :
?>
<section class="amir-suggested">
  <div class="amir-suggested__inner">
    <h2 class="amir-suggested__title">
      <?php echo $is_en ? 'You might also like' : 'También te puede interesar'; ?>
    </h2>
    <div class="amir-suggested__scroll">
      <?php while ( $suggested->have_posts() ) : $suggested->the_post(); ?>
        <?php
        $s_pid     = get_the_ID();
        $s_db_id   = (int) get_post_meta( $s_pid, '_amir_tour_db_id', true );
        $s_name_en = get_post_meta( $s_pid, '_amir_name_en', true );
        $s_title   = $is_en ? ( $s_name_en ?: get_the_title() ) : get_the_title();
        $s_cover   = get_the_post_thumbnail_url( $s_pid, 'medium_large' );
        $s_link    = get_permalink();
        $s_dur     = (int) get_post_meta( $s_pid, '_amir_duration_minutes', true );
        $s_dur_fmt = $s_dur >= 60 ? round( $s_dur / 60, 1 ) . 'h' : $s_dur . 'min';
        $s_price   = 0;
        if ( $s_db_id ) {
            global $wpdb;
            $s_price = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices WHERE tour_id=%d AND price_mxn>0",
                $s_db_id
            ) );
        }
        ?>
        <article class="amir-tour-card amir-suggested__card">
          <div class="amir-tour-card__img-wrap">
            <?php if ( $s_cover ) : ?>
              <a href="<?php echo esc_url( $s_link ); ?>">
                <img src="<?php echo esc_url( $s_cover ); ?>"
                     alt="<?php echo esc_attr( $s_title ); ?>"
                     class="amir-tour-card__img" loading="lazy" />
              </a>
            <?php endif; ?>
            <?php if ( $s_dur ) : ?>
              <span class="amir-tour-card__duration-badge">⏱ <?php echo esc_html( $s_dur_fmt ); ?></span>
            <?php endif; ?>
            <?php if ( $s_price > 0 ) : ?>
              <div class="amir-tour-card__price-badge">
                <span class="amir-tour-card__price-badge-label"><?php echo $is_en ? 'From' : 'Desde'; ?></span>
                <span class="amir-tour-card__price-badge-value">$<?php echo number_format( $s_price, 0, '.', ',' ); ?></span>
                <span class="amir-tour-card__price-badge-cur"> <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></span>
              </div>
            <?php endif; ?>
          </div>
          <div class="amir-tour-card__body">
            <h3 class="amir-tour-card__title">
              <a href="<?php echo esc_url( $s_link ); ?>"><?php echo esc_html( $s_title ); ?></a>
            </h3>
            <p class="amir-tour-card__excerpt">
              <?php echo wp_trim_words( get_the_excerpt(), 14, '…' ); ?>
            </p>
            <a href="<?php echo esc_url( $s_link ); ?>" class="amir-tour-card__cta">
              <?php echo $is_en ? 'Book now' : 'Reservar ahora'; ?>
            </a>
          </div>
        </article>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php endwhile; ?>

<?php get_footer(); ?>
