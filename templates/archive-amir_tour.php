<?php
/**
 * Template para el archivo de tours (/nuestros-tours/).
 * Copiar a la raíz del tema activo como: archive-amir_tour.php
 * WordPress lo usa automáticamente para el archive del CPT amir_tour.
 */

defined( 'ABSPATH' ) || exit;

$lang  = function_exists('pll_current_language') ? pll_current_language('slug') : 'es';
$is_en = $lang === 'en';

// Capturar token de partner de la URL
$ref = sanitize_text_field( $_GET['ref'] ?? '' );

get_header();
?>

<div class="amir-archive-tours">
  <div class="amir-archive-tours__header">
    <h1><?php echo $is_en ? 'Experiences in Bacalar' : 'Experiencias en Bacalar'; ?></h1>
    <p><?php echo $is_en
      ? 'Discover the magic of the Lagoon of 7 Colors with our unique tours.'
      : 'Descubre la magia de la Laguna de los 7 Colores con nuestros tours únicos.'; ?>
    </p>
  </div>

  <?php wp_enqueue_style('amir-tour-cards'); ?>

  <div class="amir-archive-tours__grid amir-tours-grid">
    <?php
    $query = new WP_Query([
        'post_type'      => 'amir_tour',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_amir_sort_order',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
    ]);

    while ( $query->have_posts() ) :
        $query->the_post();
        $pid        = get_the_ID();
        $db_id      = (int) get_post_meta($pid,'_amir_tour_db_id',true);
        $name_en    = get_post_meta($pid,'_amir_name_en',true);
        $duration   = (int) get_post_meta($pid,'_amir_duration_minutes',true);
        $min_age    = (int) get_post_meta($pid,'_amir_min_age',true);
        $title      = $is_en ? ($name_en ?: get_the_title()) : get_the_title();
        $cover      = get_the_post_thumbnail_url($pid,'large');
        $link       = $ref ? add_query_arg('ref',$ref,get_permalink()) : get_permalink();
        $dur_fmt    = $duration >= 60 ? round($duration/60,1).'h' : $duration.'min';

        $price_from = 0;
        if ($db_id) {
            global $wpdb;
            $price_from = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices WHERE tour_id=%d AND price_mxn>0",$db_id
            ));
        }
    ?>
        <article class="amir-tour-card">
          <div class="amir-tour-card__img-wrap">
            <?php if ($cover) : ?>
              <a href="<?php echo esc_url($link); ?>">
                <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($title); ?>"
                     class="amir-tour-card__img" loading="lazy" />
              </a>
            <?php else : ?>
              <a href="<?php echo esc_url($link); ?>" style="display:block">
                <div style="width:100%;aspect-ratio:16/9;background:linear-gradient(135deg,#c8eedf,#9FE1CB);display:flex;align-items:center;justify-content:center;font-size:36px;">⛵</div>
              </a>
            <?php endif; ?>
            <?php if ($duration) : ?>
              <span class="amir-tour-card__duration-badge">⏱ <?php echo esc_html($dur_fmt); ?></span>
            <?php endif; ?>
            <?php if ($price_from > 0) : ?>
              <div class="amir-tour-card__price-badge">
                <span class="amir-tour-card__price-badge-label"><?php echo $is_en?'From':'Desde'; ?></span>
                <span class="amir-tour-card__price-badge-value">$<?php echo number_format($price_from,0,'.',','); ?></span>
                <span class="amir-tour-card__price-badge-cur"> <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></span>
              </div>
            <?php endif; ?>
          </div>

          <div class="amir-tour-card__body">
            <h2 class="amir-tour-card__title">
              <a href="<?php echo esc_url($link); ?>"><?php echo esc_html($title); ?></a>
            </h2>

            <p class="amir-tour-card__excerpt"><?php echo wp_trim_words(get_the_excerpt(),18,'…'); ?></p>

            <div class="amir-tour-card__meta">
              <?php if ($min_age) : ?>
                <span class="amir-tour-card__meta-chip">👤 <?php echo $is_en?'Age':'Edad'; ?> <?php echo $min_age; ?>+</span>
              <?php endif; ?>
              <?php
              $langs = json_decode(get_post_meta($pid,'_amir_languages',true)?:'[]',true);
              if (!empty($langs)) : ?>
                <span class="amir-tour-card__meta-chip">🌐 <?php echo esc_html(implode(', ',$langs)); ?></span>
              <?php endif; ?>
            </div>

            <a href="<?php echo esc_url($link); ?>" class="amir-tour-card__cta">
              <?php echo $is_en ? 'Book now' : 'Reservar ahora'; ?>
            </a>
          </div>
        </article>
    <?php endwhile; wp_reset_postdata(); ?>
  </div>
</div>

<?php $brand_color = esc_attr( get_option( 'amir_brand_color', '#1D9E75' ) ); ?>
<style>
.amir-archive-tours { max-width:1200px; margin:0 auto; padding:40px 20px 60px; }
.amir-archive-tours__header { text-align:center; margin-bottom:40px; }
.amir-archive-tours__header h1 { font-size:clamp(26px,4vw,38px); font-weight:800; color:#1a2e24; margin-bottom:10px; }
.amir-archive-tours__header p  { font-size:16px; color:#5a7068; max-width:560px; margin:0 auto; }
.amir-tour-card__cta { background:<?php echo $brand_color; ?>; }
.amir-tour-card__cta:hover { background:<?php echo $brand_color; ?>; filter:brightness(.88); }
.amir-tour-card__price-badge { background:<?php echo $brand_color; ?>cc; }
.amir-tour-card__title a:hover { color:<?php echo $brand_color; ?>; }
</style>

<?php get_footer(); ?>
