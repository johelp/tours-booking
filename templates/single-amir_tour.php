<?php
/**
 * Template "Clásica" para la página individual de un tour (CPT amir_tour).
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
 * Hay una segunda plantilla ("Inmersiva", single-amir_tour-immersive.php)
 * seleccionable en Personalización → Plantilla de detalle de tour — ambas
 * comparten la misma carga de datos (templates/parts/tour-data.php), solo
 * cambia el layout visual.
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
    the_post();
    include AMIR_PLUGIN_DIR . 'templates/parts/tour-data.php';
?>

<div class="amir-single-tour">

  <!-- Hero -->
  <div class="amir-single-tour__hero" <?php if ($cover) echo 'style="background-image:url('.esc_url($cover).')"'; ?>>
    <div class="amir-single-tour__hero-overlay">
      <div class="amir-single-tour__hero-content">
        <h1 class="amir-single-tour__title" style="color:<?php echo esc_attr( get_option( 'amir_detail_title_color', '#ffffff' ) ); ?> !important;"><?php echo esc_html($title); ?></h1>
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

    <!-- Datos destacados — ícono+título+detalle configurables por tour (§ 13.2 CONTRIBUTING.md) -->
    <?php
    $visible_facts = array_values( array_filter( $detail_facts, fn( $f ) => $f['label'] !== '' ) );
    if ( ! empty( $visible_facts ) ) :
    ?>
    <div class="amir-facts-row">
      <?php foreach ( $visible_facts as $fact ) : ?>
        <div class="amir-facts-row__item">
          <?php if ( $fact['icon'] ) : ?><span class="amir-facts-row__icon"><?php echo esc_html( $fact['icon'] ); ?></span><?php endif; ?>
          <div class="amir-facts-row__text">
            <span class="amir-facts-row__label"><?php echo esc_html( $fact['label'] ); ?></span>
            <?php if ( $fact['value'] ) : ?><span class="amir-facts-row__value"><?php echo esc_html( $fact['value'] ); ?></span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Galería (+ video, si el tour tiene uno cargado — § 16.46 CONTRIBUTING.md) -->
    <?php if (!empty($gallery_ids) || $video_info) : ?>
    <div class="amir-gallery">
      <?php if ($video_info) : ?>
        <a href="#" class="amir-gallery__item amir-gallery__item--video"
           data-amir-lightbox-video="<?php echo esc_attr( $video_info['provider'] . '|' . $video_info['id'] . '|' . ( $video_info['hash'] ?? '' ) ); ?>">
          <?php if ($video_info['provider'] === 'youtube') : ?>
            <img src="<?php echo esc_url( "https://img.youtube.com/vi/{$video_info['id']}/hqdefault.jpg" ); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
          <?php endif; ?>
          <span class="amir-gallery__play" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </span>
        </a>
      <?php endif; ?>
      <?php foreach ($gallery_ids as $img_id) :
        $url = wp_get_attachment_image_url($img_id,'large');
        if (!$url) continue;
      ?>
        <a href="<?php echo esc_url(wp_get_attachment_url($img_id)); ?>" class="amir-gallery__item" data-amir-lightbox-img>
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
          <?php if ( ! empty( $highlights ) ) : ?>
            <ul class="amir-highlights">
              <?php foreach ( $highlights as $item ) : ?>
                <li><?php echo esc_html( $item ); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
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

        <!-- Itinerario tipo timeline — opcional por tour, ver § 13.1 CONTRIBUTING.md -->
        <?php if ( ! empty( $itinerary_stops ) ) : ?>
        <div class="amir-single-tour__section">
          <div class="amir-itinerary-header">
            <h2><?php echo $is_en ? 'Itinerary' : 'Itinerario'; ?></h2>
            <button type="button" class="amir-itinerary-collapse-all"
                    onclick="this.closest('.amir-single-tour__section').querySelector('.amir-itinerary-wrap').querySelectorAll('details').forEach(function(d){ d.open = false; })">
              <?php echo $is_en ? 'Collapse all' : 'Colapsar todo'; ?>
            </button>
          </div>
          <div class="amir-itinerary-wrap">
            <div class="amir-itinerary">
              <?php foreach ( $itinerary_stops as $stop ) :
                if ( $stop['title'] === '' ) continue;
              ?>
                <details class="amir-itinerary__stop<?php echo $stop['is_start'] ? ' amir-itinerary__stop--start' : ''; ?>" open>
                  <summary>
                    <span class="amir-itinerary__marker"><?php echo $stop['is_start'] ? '📍' : ''; ?></span>
                    <span class="amir-itinerary__title"><?php echo esc_html( $stop['title'] ); ?></span>
                    <span class="amir-itinerary__chevron">▾</span>
                  </summary>
                  <?php if ( $stop['desc'] || $stop['image_url'] ) : ?>
                    <div class="amir-itinerary__body">
                      <?php if ( $stop['image_url'] ) : ?>
                        <img src="<?php echo esc_url( $stop['image_url'] ); ?>" alt="<?php echo esc_attr( $stop['title'] ); ?>" class="amir-itinerary__img" loading="lazy" />
                      <?php endif; ?>
                      <?php if ( $stop['desc'] ) : ?>
                        <p><?php echo esc_html( $stop['desc'] ); ?></p>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </details>
              <?php endforeach; ?>
            </div>
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

        <!-- FAQ opcional por tour, ver § 16.93 CONTRIBUTING.md -->
        <?php if ( ! empty( $faq_items ) ) : ?>
        <div class="amir-single-tour__section">
          <h2>❓ <?php echo $is_en ? 'Frequently asked questions' : 'Preguntas frecuentes'; ?></h2>
          <div class="amir-faq">
            <?php foreach ( $faq_items as $faq ) :
              if ( $faq['question'] === '' ) continue;
            ?>
              <details class="amir-faq__item">
                <summary>
                  <span class="amir-faq__q"><?php echo esc_html( $faq['question'] ); ?></span>
                  <span class="amir-faq__chevron">▾</span>
                </summary>
                <?php if ( $faq['answer'] ) : ?>
                  <div class="amir-faq__a"><?php echo wp_kses_post( nl2br( esc_html( $faq['answer'] ) ) ); ?></div>
                <?php endif; ?>
              </details>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div><!-- main -->

      <!-- Widget de reserva (sticky en desktop) -->
      <div class="amir-single-tour__booking-col">
        <div class="amir-single-tour__booking-sticky">
          <?php if ( $db_id ) : ?>
            <?php if ( defined( 'AMIR_EDITION' ) && AMIR_EDITION === 'pro_max' && ! $skip_upsell ) : ?>
              <?php // Pro Max: reservar desde la ficha del tour continúa con el
              // flujo de upsell (habitaciones/extras), no termina en una
              // confirmación aislada — decisión del cliente 2026-08-03
              // (CONTRIBUTING.md § 16.15), "upsell siempre". Excepción:
              // skip_upsell (checkbox "Reserva directa" del editor, § 16.76)
              // — un tour que no combina con nada más cae al widget clásico
              // de abajo aunque el sitio sea Pro Max. ?>
              <?php echo do_shortcode( '[flow_discovery mode="experience" tour_id="' . $db_id . '" lang="' . $lang . '"]' ); ?>
            <?php else : ?>
              <?php echo do_shortcode( '[flow_booking tour_id="' . $db_id . '" lang="' . $lang . '"]' ); ?>
            <?php endif; ?>
          <?php else : ?>
            <div style="background:#f8fdfb;border:1px solid #e1f5ee;border-radius:12px;padding:24px;text-align:center;color:#5a7068;font-size:14px;">
              <?php echo $is_en ? 'Contact us to book this tour.' : 'Contáctanos para reservar.'; ?>
              <br><br>
              <a href="https://wa.me/<?php echo esc_attr( preg_replace('/[^0-9]/', '', get_option('amir_wa_phone','') ) ); ?>"
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

<!-- Lightbox liviano en JS vanilla (mismo patrón que single-flow_room.php,
     § 16.12 CONTRIBUTING.md) — bug real corregido 2026-08-03: la galería
     antes linkeaba directo al archivo de imagen (sin target ni lightbox),
     así que un clic navegaba la pestaña actual fuera del sitio sin forma de
     volver. -->
<div id="amir-lightbox" class="amir-lightbox" hidden>
  <button type="button" class="amir-lightbox__close" aria-label="Cerrar">✕</button>
  <div class="amir-lightbox__content"></div>
</div>

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

/* Datos destacados */
.amir-facts-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:28px; }
.amir-facts-row__item { display:flex; align-items:center; gap:10px; background:var(--teal-light); border-radius:10px; padding:12px 14px; }
.amir-facts-row__icon { font-size:22px; line-height:1; flex-shrink:0; }
.amir-facts-row__text { display:flex; flex-direction:column; min-width:0; }
.amir-facts-row__label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:var(--teal-dark); }
.amir-facts-row__value { font-size:13px; color:#1a2e24; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* Highlights */
.amir-highlights { list-style:none; margin:0 0 16px; padding:0; display:grid; grid-template-columns:1fr 1fr; gap:6px 16px; }
.amir-highlights li { font-size:13px; color:#1a2e24; font-weight:600; padding-left:22px; position:relative; }
.amir-highlights li::before { content:"✓"; position:absolute; left:0; color:var(--teal); font-weight:700; }

/* Galería */
.amir-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; margin-bottom:32px; }
.amir-gallery__item { display:block; aspect-ratio:4/3; overflow:hidden; border-radius:8px; cursor:zoom-in; }
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

/* Itinerario tipo timeline */
.amir-itinerary-header { display:flex; align-items:center; justify-content:space-between; margin:0 0 14px; padding-bottom:8px; border-bottom:2px solid var(--teal-light); }
.amir-itinerary-header h2 { margin:0; padding:0; border:none; font-size:20px; font-weight:700; color:#1a2e24; }
.amir-itinerary-collapse-all { background:none; border:none; color:var(--teal); font-size:12px; font-weight:600; cursor:pointer; padding:0; }
.amir-itinerary-collapse-all:hover { text-decoration:underline; }
.amir-itinerary__stop { position:relative; padding-left:28px; margin-left:10px; }
.amir-itinerary__stop:not(:last-child) { border-left:2px dotted #c3d9d0; }
.amir-itinerary__marker { position:absolute; left:-11px; top:12px; width:20px; height:20px; border-radius:50%; background:#fff; border:2px solid var(--teal); box-sizing:border-box; display:flex; align-items:center; justify-content:center; font-size:10px; line-height:1; }
.amir-itinerary__stop--start .amir-itinerary__marker { background:var(--teal); border-color:var(--teal); }
.amir-itinerary__stop summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:8px; padding:8px 0; }
.amir-itinerary__stop summary::-webkit-details-marker { display:none; }
.amir-itinerary__title { font-weight:700; color:#1a2e24; font-size:14px; }
.amir-itinerary__chevron { margin-left:auto; color:#5a7068; font-size:12px; transition:transform .15s; }
.amir-itinerary__stop[open] .amir-itinerary__chevron { transform:rotate(180deg); }
.amir-itinerary__body { padding:0 0 16px 4px; }
.amir-itinerary__img { width:100%; max-width:320px; border-radius:8px; margin:0 0 10px; display:block; }
.amir-itinerary__body p { font-size:13px; color:#3d3d3a; line-height:1.6; margin:0; }

/* Incluye / No incluye */
.amir-incl-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.amir-incl-title { display:flex; align-items:center; gap:6px; font-size:14px; }
.amir-incl-title--yes { color:var(--teal-dark); }
.amir-incl-title--no  { color:#c53030; }
.amir-incl-list { list-style:none; padding:0; margin:0; }
.amir-incl-list li { font-size:13px; color:#3d3d3a; padding:5px 0; border-bottom:1px solid #f5f5f5; display:flex; gap:8px; }
.amir-incl-list--yes li::before { content:"✓"; color:var(--teal); font-weight:700; flex-shrink:0; }
.amir-incl-list--no  li::before { content:"✕"; color:#c53030; font-weight:700; flex-shrink:0; }

/* FAQ opcional por tour */
.amir-faq { display:flex; flex-direction:column; gap:8px; }
.amir-faq__item { border:1px solid #e1f5ee; border-radius:10px; padding:4px 16px; }
.amir-faq__item summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:10px; padding:12px 0; }
.amir-faq__item summary::-webkit-details-marker { display:none; }
.amir-faq__q { font-weight:700; color:#1a2e24; font-size:14px; }
.amir-faq__chevron { margin-left:auto; color:#5a7068; font-size:12px; transition:transform .15s; }
.amir-faq__item[open] .amir-faq__chevron { transform:rotate(180deg); }
.amir-faq__a { font-size:13.5px; color:#3d3d3a; line-height:1.6; padding:0 0 14px; }

@media (max-width:768px) {
  .amir-single-tour__cols { grid-template-columns:1fr; }
  .amir-single-tour__booking-sticky { position:static; }
  .amir-incl-grid { grid-template-columns:1fr; }
  .amir-highlights { grid-template-columns:1fr; }
  .amir-single-tour__booking-col { order:-1; }
}

.amir-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.9); z-index:9999; display:flex; align-items:center; justify-content:center; padding:20px; }
.amir-lightbox[hidden] { display:none; }
.amir-lightbox__content { max-width:min(960px,92vw); max-height:86vh; width:100%; }
.amir-lightbox__content img { width:100%; height:100%; max-height:86vh; object-fit:contain; display:block; margin:0 auto; }
.amir-lightbox__close { position:absolute; top:16px; right:20px; background:none; border:none; color:#fff; font-size:28px; cursor:pointer; line-height:1; }
/* Video en la galería — miniatura estática + botón de play, el iframe
   real recién se inyecta al hacer click (§ 16.46 CONTRIBUTING.md, no
   cargar YouTube/Vimeo de entrada). */
.amir-gallery__item--video { position:relative; background:#0e2b30; }
.amir-gallery__play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; background:rgba(0,0,0,.28); transition:background .15s; }
.amir-gallery__item--video:hover .amir-gallery__play { background:rgba(0,0,0,.4); }
.amir-gallery__play svg { filter:drop-shadow(0 1px 3px rgba(0,0,0,.5)); }
.amir-lightbox__video { position:relative; width:100%; padding-top:56.25%; }
.amir-lightbox__video iframe { position:absolute; inset:0; width:100%; height:100%; border:0; }
</style>

<script>
(function(){
  var lightbox = document.getElementById('amir-lightbox');
  if (!lightbox) return;
  var content = lightbox.querySelector('.amir-lightbox__content');

  function open(html){
    content.innerHTML = html;
    lightbox.hidden = false;
  }
  function close(){
    lightbox.hidden = true;
    content.innerHTML = '';
  }

  document.querySelectorAll('[data-amir-lightbox-img]').forEach(function(el){
    el.addEventListener('click', function(e){
      e.preventDefault();
      open('<img src="' + el.getAttribute('href') + '" alt="">');
    });
  });

  document.querySelectorAll('[data-amir-lightbox-video]').forEach(function(el){
    el.addEventListener('click', function(e){
      e.preventDefault();
      var parts = el.getAttribute('data-amir-lightbox-video').split('|');
      var provider = parts[0], id = parts[1], hash = parts[2] || '';
      var src = provider === 'youtube'
        ? 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0'
        : 'https://player.vimeo.com/video/' + id + '?autoplay=1' + (hash ? '&h=' + hash : '');
      var allow = provider === 'youtube' ? 'autoplay; encrypted-media' : 'autoplay; fullscreen; picture-in-picture';
      open('<div class="amir-lightbox__video"><iframe src="' + src + '" allow="' + allow + '" allowfullscreen></iframe></div>');
    });
  });

  lightbox.querySelector('.amir-lightbox__close').addEventListener('click', close);
  lightbox.addEventListener('click', function(e){ if (e.target === lightbox) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
})();
</script>

<?php include AMIR_PLUGIN_DIR . 'templates/parts/tour-suggested.php'; ?>

<?php endwhile; ?>

<?php get_footer(); ?>
