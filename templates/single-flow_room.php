<?php
/**
 * Plantilla de detalle de una habitación (CPT flow_room, Pro Max —
 * CONTRIBUTING.md § 16). Versión mínima pero funcional (2026-08-01): la
 * página completa "digna de plataformas" (amenities, video, galería tipo
 * lightbox, etc. — ver § 16 CONTRIBUTING.md) queda para una sesión
 * dedicada; esto resuelve el gap real encontrado en vivo (§ 16.10): antes
 * de este archivo, WordPress caía al template genérico del theme y no
 * mostraba precio, capacidad, ni forma de reservar.
 *
 * Cómo usar: copiar este archivo a la raíz del tema activo para
 * personalizar el layout — WordPress lo usa automáticamente si no.
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	include AMIR_PLUGIN_DIR . 'templates/parts/room-data.php';
	?>

<div class="flow-single-room">

  <!-- Hero -->
  <div class="flow-single-room__hero" <?php if ( $cover ) echo 'style="background-image:url(' . esc_url( $cover ) . ')"'; ?>>
    <div class="flow-single-room__hero-overlay">
      <div class="flow-single-room__hero-content">
        <h1 class="flow-single-room__title" style="color:<?php echo esc_attr( get_option( 'amir_detail_title_color', '#ffffff' ) ); ?> !important;"><?php echo esc_html( $title ); ?></h1>
        <div class="flow-single-room__hero-chips">
          <?php if ( $capacity_max ) : ?>
            <span class="flow-chip">👥 <?php echo $is_en ? 'Up to ' . $capacity_max . ' guests' : 'Hasta ' . $capacity_max . ' huéspedes'; ?></span>
          <?php endif; ?>
          <span class="flow-chip">🛬 <?php echo $is_en ? 'Check-in' : 'Check-in'; ?> <?php echo esc_html( $checkin_time ); ?></span>
          <span class="flow-chip">🛫 <?php echo $is_en ? 'Check-out' : 'Check-out'; ?> <?php echo esc_html( $checkout_time ); ?></span>
          <?php if ( $min_nights > 1 ) : ?>
            <span class="flow-chip">🌙 <?php echo $is_en ? 'Min. ' . $min_nights . ' nights' : 'Mín. ' . $min_nights . ' noches'; ?></span>
          <?php endif; ?>
        </div>
        <?php if ( $price_per_night > 0 ) : ?>
          <div class="flow-single-room__price">
            <span class="flow-single-room__price-from"><?php echo $is_en ? 'From' : 'Desde'; ?></span>
            <span class="flow-single-room__price-value"><?php echo \AmirBooking\Core\Currency::format( $price_per_night, 0 ); ?></span>
            <span class="flow-single-room__price-cur"><?php echo $is_en ? '/ night' : '/ noche'; ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="flow-single-room__body">

    <!-- Galería (el video, si hay, va primero — con ícono de play, § 16.11) -->
    <?php if ( $youtube_id || ! empty( $gallery_ids ) ) : ?>
    <div class="flow-gallery">
      <?php if ( $youtube_id ) : ?>
        <a href="#" class="flow-gallery__item flow-gallery__item--video" data-flow-lightbox-video="<?php echo esc_attr( $youtube_id ); ?>">
          <img src="<?php echo esc_url( "https://img.youtube.com/vi/{$youtube_id}/hqdefault.jpg" ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
          <span class="flow-gallery__play">▶</span>
        </a>
      <?php endif; ?>
      <?php foreach ( $gallery_ids as $img_id ) :
        $url = wp_get_attachment_image_url( $img_id, 'large' );
        if ( ! $url ) continue;
      ?>
        <a href="<?php echo esc_url( wp_get_attachment_url( $img_id ) ); ?>" class="flow-gallery__item" data-flow-lightbox-img>
          <img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="flow-single-room__cols">

      <!-- Columna principal -->
      <div class="flow-single-room__main">
        <div class="flow-single-room__section">
          <h2><?php echo $is_en ? 'About this room' : 'Acerca de esta habitación'; ?></h2>
          <div class="flow-single-room__description">
            <?php the_content(); ?>
          </div>
        </div>

        <!-- Amenities (§ 16.11 CONTRIBUTING.md) -->
        <?php if ( ! empty( $amenities ) ) : ?>
        <div class="flow-single-room__section">
          <h2><?php echo $is_en ? 'Amenities' : 'Amenities'; ?></h2>
          <div class="flow-amenities-grid">
            <?php foreach ( $amenities as $a ) : ?>
              <div class="flow-amenities-grid__item">
                <?php if ( $a['icon'] ) : ?><span class="flow-amenities-grid__icon"><?php echo esc_html( $a['icon'] ); ?></span><?php endif; ?>
                <span><?php echo esc_html( $a['label'] ); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Widget de reserva (sticky en desktop) — filtrado a ESTA habitación -->
      <div class="flow-single-room__booking-col">
        <div class="flow-single-room__booking-sticky">
          <?php if ( $db_id ) : ?>
            <?php // Reservar desde la ficha de una habitación continúa con el
            // flujo de upsell (otras experiencias/extras) — decisión del
            // cliente 2026-08-03 (CONTRIBUTING.md § 16.15), "upsell siempre".
            // Sin chequeo de AMIR_EDITION acá: este template solo se sirve en
            // instalaciones Pro Max (RoomPostType se auto-desactiva si no). ?>
            <?php echo do_shortcode( '[flow_discovery mode="room" room_id="' . $db_id . '" lang="' . $lang . '"]' ); ?>
          <?php else : ?>
            <div style="background:#f8fdfb;border:1px solid #e1f5ee;border-radius:12px;padding:24px;text-align:center;color:#5a7068;font-size:14px;">
              <?php echo $is_en ? 'Contact us to book this room.' : 'Contáctanos para reservar.'; ?>
              <br><br>
              <a href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', get_option( 'amir_wa_phone', '' ) ) ); ?>"
                 style="display:inline-block;background:#25D366;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;">
                💬 WhatsApp
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- cols -->
  </div><!-- body -->
</div><!-- single-room -->

<!-- Lightbox liviano en JS vanilla (§ 16.11 CONTRIBUTING.md) — sin librería
     nueva: abre la foto/video en grande sobre un overlay, clic afuera o en
     ✕ para cerrar. -->
<div id="flow-lightbox" class="flow-lightbox" hidden>
  <button type="button" class="flow-lightbox__close" aria-label="Cerrar">✕</button>
  <div class="flow-lightbox__content"></div>
</div>

<?php
$brand_color      = esc_attr( get_option( 'amir_brand_color', '#1D9E75' ) );
$brand_color_dark = esc_attr( get_option( 'amir_brand_color_dark', '#0F6E56' ) );
?>
<style>
.flow-single-room { --teal:<?php echo $brand_color; ?>; --teal-dark:<?php echo $brand_color_dark; ?>; --teal-light:#e1f5ee; }
.flow-single-room__hero { background:#1a2e24 center/cover no-repeat; min-height:340px; display:flex; align-items:flex-end; }
.flow-single-room__hero-overlay { width:100%; background:linear-gradient(to top,rgba(0,0,0,.65) 0%,transparent 100%); padding:32px 24px 28px; }
.flow-single-room__hero-content { max-width:760px; margin:0 auto; }
.flow-single-room__title { font-size:clamp(22px,4vw,36px); font-weight:800; color:#fff; margin:0 0 12px; text-shadow:0 1px 4px rgba(0,0,0,.4); }
.flow-single-room__hero-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.flow-chip { background:rgba(255,255,255,.2); color:#fff; font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); }
.flow-single-room__price { display:flex; align-items:baseline; gap:6px; }
.flow-single-room__price-from { color:rgba(255,255,255,.75); font-size:13px; }
.flow-single-room__price-value { font-size:32px; font-weight:800; color:#fff; }
.flow-single-room__price-cur { color:rgba(255,255,255,.75); font-size:14px; }

.flow-single-room__body { max-width:1140px; margin:0 auto; padding:32px 20px 48px; }

.flow-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; margin-bottom:32px; }
.flow-gallery__item { display:block; position:relative; aspect-ratio:4/3; overflow:hidden; border-radius:8px; cursor:zoom-in; }
.flow-gallery__item img { width:100%; height:100%; object-fit:cover; transition:transform .3s; }
.flow-gallery__item:hover img { transform:scale(1.05); }
.flow-gallery__item--video { cursor:pointer; }
.flow-gallery__play { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:44px; height:44px; border-radius:50%; background:rgba(0,0,0,.6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; }

.flow-amenities-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; }
.flow-amenities-grid__item { display:flex; align-items:center; gap:8px; font-size:14px; color:#1a2e24; background:var(--teal-light); border-radius:8px; padding:10px 12px; }
.flow-amenities-grid__icon { font-size:18px; line-height:1; }

.flow-single-room__cols { display:grid; grid-template-columns:1fr 420px; gap:32px; align-items:start; }
.flow-single-room__booking-sticky { position:sticky; top:80px; }
.flow-single-room__section h2 { font-size:20px; font-weight:700; color:#1a2e24; margin:0 0 14px; padding-bottom:8px; border-bottom:2px solid var(--teal-light); }
.flow-single-room__description { font-size:15px; color:#3d3d3a; line-height:1.7; }

.flow-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.9); z-index:9999; display:flex; align-items:center; justify-content:center; padding:20px; }
.flow-lightbox[hidden] { display:none; }
.flow-lightbox__content { max-width:min(960px,92vw); max-height:86vh; width:100%; }
.flow-lightbox__content img { width:100%; height:100%; max-height:86vh; object-fit:contain; display:block; margin:0 auto; }
.flow-lightbox__content iframe { width:100%; aspect-ratio:16/9; border:0; display:block; }
.flow-lightbox__close { position:absolute; top:16px; right:20px; background:none; border:none; color:#fff; font-size:28px; cursor:pointer; line-height:1; }

@media (max-width:768px) {
  .flow-single-room__cols { grid-template-columns:1fr; }
  .flow-single-room__booking-sticky { position:static; }
  .flow-single-room__booking-col { order:-1; }
  .flow-gallery { grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); }
}
</style>

<script>
(function(){
  var lightbox = document.getElementById('flow-lightbox');
  if (!lightbox) return;
  var content = lightbox.querySelector('.flow-lightbox__content');

  function open(html){
    content.innerHTML = html;
    lightbox.hidden = false;
  }
  function close(){
    lightbox.hidden = true;
    content.innerHTML = '';
  }

  document.querySelectorAll('.flow-gallery__item').forEach(function(el){
    el.addEventListener('click', function(e){
      e.preventDefault();
      if (el.dataset.flowLightboxVideo) {
        open('<iframe src="https://www.youtube.com/embed/' + el.dataset.flowLightboxVideo + '?autoplay=1" allow="autoplay; encrypted-media" allowfullscreen></iframe>');
      } else {
        open('<img src="' + el.getAttribute('href') + '" alt="">');
      }
    });
  });

  lightbox.querySelector('.flow-lightbox__close').addEventListener('click', close);
  lightbox.addEventListener('click', function(e){ if (e.target === lightbox) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
})();
</script>

<?php endwhile; ?>

<?php get_footer(); ?>
