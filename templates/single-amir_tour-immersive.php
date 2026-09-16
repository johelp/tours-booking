<?php
/**
 * Template "Inmersiva" para la página individual de un tour (CPT amir_tour).
 *
 * Segunda plantilla de detalle (§ 13.4 CONTRIBUTING.md) — layout realmente
 * distinto a la Clásica (single-amir_tour.php), no solo colores: hero a
 * pantalla completa, contenido en columna única centrada en vez de dos
 * columnas, galería como filmstrip horizontal, y el widget de reserva como
 * su propia sección (no sticky en una barra lateral) con una barra flotante
 * de "Reservar" que aparece al hacer scroll.
 *
 * Se activa desde Personalización → Plantilla de detalle de tour (opción
 * `amir_tour_template`, ver TemplateLoader::load()) — o copiando este
 * archivo directo a la carpeta raíz del tema activo, igual que la Clásica.
 * Comparte la misma carga de datos que la Clásica (templates/parts/tour-data.php)
 * — si cambia algo ahí, ambas plantillas lo reciben sin tocar nada acá.
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
    the_post();
    include AMIR_PLUGIN_DIR . 'templates/parts/tour-data.php';
?>

<div class="amir-imm">

  <!-- Hero a pantalla completa -->
  <div class="amir-imm__hero" <?php if ($cover) echo 'style="background-image:url('.esc_url($cover).')"'; ?>>
    <div class="amir-imm__hero-scrim"></div>
    <div class="amir-imm__hero-content">
      <?php if ($has_provider) : ?>
        <span class="amir-imm__provider-badge">🤝 <?php echo $is_en ? 'Operated by a local partner' : 'Operado por un partner local'; ?></span>
      <?php endif; ?>
      <h1 class="amir-imm__title" style="color:<?php echo esc_attr( get_option( 'amir_detail_title_color', '#ffffff' ) ); ?> !important;"><?php echo esc_html($title); ?></h1>
      <div class="amir-imm__chips">
        <?php if ($duration) : ?><span class="amir-imm__chip">⏱ <?php echo esc_html($duration_fmt); ?></span><?php endif; ?>
        <?php if ($min_age) : ?><span class="amir-imm__chip">👤 <?php echo $is_en?'Min. age':'Edad mín.'; ?> <?php echo $min_age; ?>+</span><?php endif; ?>
        <?php if (!empty($languages)) : ?><span class="amir-imm__chip">🌐 <?php echo esc_html(implode(', ',$languages)); ?></span><?php endif; ?>
      </div>
      <a href="#amir-imm-book" class="amir-imm__hero-cta">
        <?php echo $is_en ? 'Check availability ↓' : 'Ver disponibilidad ↓'; ?>
      </a>
    </div>
    <div class="amir-imm__hero-scroll-hint">⌄</div>
  </div>

  <div class="amir-imm__body">

    <!-- Highlights — resumen rápido arriba de todo -->
    <?php if ( ! empty( $highlights ) ) : ?>
    <ul class="amir-imm__highlights">
      <?php foreach ( $highlights as $item ) : ?>
        <li><?php echo esc_html( $item ); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <!-- Datos destacados -->
    <?php
    $visible_facts = array_values( array_filter( $detail_facts, fn( $f ) => $f['label'] !== '' ) );
    if ( ! empty( $visible_facts ) ) :
    ?>
    <div class="amir-imm__facts">
      <?php foreach ( $visible_facts as $fact ) : ?>
        <div class="amir-imm__fact">
          <?php if ( $fact['icon'] ) : ?><span class="amir-imm__fact-icon"><?php echo esc_html( $fact['icon'] ); ?></span><?php endif; ?>
          <div>
            <span class="amir-imm__fact-label"><?php echo esc_html( $fact['label'] ); ?></span>
            <?php if ( $fact['value'] ) : ?><span class="amir-imm__fact-value"><?php echo esc_html( $fact['value'] ); ?></span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Descripción -->
    <div class="amir-imm__section">
      <h2><?php echo $is_en?'About this experience':'Acerca de esta experiencia'; ?></h2>
      <div class="amir-imm__prose"><?php the_content(); ?></div>
    </div>

    <!-- Galería como filmstrip horizontal (+ video, si el tour tiene uno cargado — § 16.46 CONTRIBUTING.md) -->
    <?php if (!empty($gallery_ids) || $video_info) : ?>
    <div class="amir-imm__filmstrip">
      <?php if ($video_info) : ?>
        <a href="#" class="amir-imm__filmstrip-item amir-imm__filmstrip-item--video"
           data-amir-lightbox-video="<?php echo esc_attr( $video_info['provider'] . '|' . $video_info['id'] . '|' . ( $video_info['hash'] ?? '' ) ); ?>">
          <?php if ($video_info['provider'] === 'youtube') : ?>
            <img src="<?php echo esc_url( "https://img.youtube.com/vi/{$video_info['id']}/hqdefault.jpg" ); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
          <?php endif; ?>
          <span class="amir-imm__filmstrip-play" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </span>
        </a>
      <?php endif; ?>
      <?php foreach ($gallery_ids as $img_id) :
        $url = wp_get_attachment_image_url($img_id,'large');
        if (!$url) continue;
      ?>
        <a href="<?php echo esc_url(wp_get_attachment_url($img_id)); ?>" class="amir-imm__filmstrip-item" data-amir-lightbox-img>
          <img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Widget de reserva — sección propia, no sidebar -->
    <div id="amir-imm-book" class="amir-imm__book">
      <div class="amir-imm__book-inner">
        <h2><?php echo $is_en ? 'Reserve your spot' : 'Reservá tu lugar'; ?></h2>
        <?php if ( $db_id ) : ?>
          <?php if ( defined( 'AMIR_EDITION' ) && AMIR_EDITION === 'pro_max' && ! $skip_upsell ) : ?>
            <?php // Pro Max: mismo criterio que single-amir_tour.php — el
            // flujo de reserva continúa con el upsell (§ 16.15 CONTRIBUTING.md),
            // salvo skip_upsell ("Reserva directa", § 16.76). ?>
            <?php echo do_shortcode( '[flow_discovery mode="experience" tour_id="' . $db_id . '" lang="' . $lang . '"]' ); ?>
          <?php else : ?>
            <?php echo do_shortcode( '[flow_booking tour_id="' . $db_id . '" lang="' . $lang . '"]' ); ?>
          <?php endif; ?>
        <?php else : ?>
          <div class="amir-imm__book-fallback">
            <?php echo $is_en ? 'Contact us to book this tour.' : 'Contáctanos para reservar.'; ?>
            <br><br>
            <a href="https://wa.me/<?php echo esc_attr( preg_replace('/[^0-9]/', '', get_option('amir_wa_phone','') ) ); ?>" class="amir-imm__whatsapp">💬 WhatsApp</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Qué esperar -->
    <?php if ($what_to_expect) : ?>
    <div class="amir-imm__section">
      <h2><?php echo $is_en?'What to expect':'Qué esperar'; ?></h2>
      <div class="amir-imm__prose"><?php echo wp_kses_post(nl2br($what_to_expect)); ?></div>
    </div>
    <?php endif; ?>

    <!-- Itinerario tipo timeline -->
    <?php if ( ! empty( $itinerary_stops ) ) : ?>
    <div class="amir-imm__section">
      <div class="amir-imm__section-header">
        <h2><?php echo $is_en ? 'Itinerary' : 'Itinerario'; ?></h2>
        <button type="button" class="amir-imm__collapse-all"
                onclick="this.closest('.amir-imm__section').querySelector('.amir-imm__itinerary-wrap').querySelectorAll('details').forEach(function(d){ d.open = false; })">
          <?php echo $is_en ? 'Collapse all' : 'Colapsar todo'; ?>
        </button>
      </div>
      <div class="amir-imm__itinerary-wrap">
        <div class="amir-imm__itinerary">
          <?php foreach ( $itinerary_stops as $stop ) :
            if ( $stop['title'] === '' ) continue;
          ?>
            <details class="amir-imm__stop<?php echo $stop['is_start'] ? ' amir-imm__stop--start' : ''; ?>" open>
              <summary>
                <span class="amir-imm__stop-marker"><?php echo $stop['is_start'] ? '📍' : ''; ?></span>
                <span class="amir-imm__stop-title"><?php echo esc_html( $stop['title'] ); ?></span>
                <span class="amir-imm__stop-chevron">▾</span>
              </summary>
              <?php if ( $stop['desc'] || $stop['image_url'] ) : ?>
                <div class="amir-imm__stop-body">
                  <?php if ( $stop['image_url'] ) : ?>
                    <img src="<?php echo esc_url( $stop['image_url'] ); ?>" alt="<?php echo esc_attr( $stop['title'] ); ?>" loading="lazy" />
                  <?php endif; ?>
                  <?php if ( $stop['desc'] ) : ?><p><?php echo esc_html( $stop['desc'] ); ?></p><?php endif; ?>
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
    <div class="amir-imm__section">
      <div class="amir-imm__incl-grid">
        <?php if (!empty($includes)) : ?>
        <div>
          <h3 class="amir-imm__incl-title amir-imm__incl-title--yes">✓ <?php echo $is_en?'Included':'Incluye'; ?></h3>
          <ul class="amir-imm__incl-list amir-imm__incl-list--yes">
            <?php foreach ($includes as $item) : ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <?php if (!empty($excludes)) : ?>
        <div>
          <h3 class="amir-imm__incl-title amir-imm__incl-title--no">✕ <?php echo $is_en?'Not included':'No incluye'; ?></h3>
          <ul class="amir-imm__incl-list amir-imm__incl-list--no">
            <?php foreach ($excludes as $item) : ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Punto de encuentro -->
    <?php if ($meeting) : ?>
    <div class="amir-imm__section">
      <h2>📍 <?php echo $is_en?'Meeting point':'Punto de encuentro'; ?></h2>
      <p class="amir-imm__meeting-text"><?php echo esc_html($meeting); ?></p>
      <?php if ($lat && $lng) : ?>
        <div class="amir-imm__map">
          <iframe
            src="https://maps.google.com/maps?q=<?php echo esc_attr($lat); ?>,<?php echo esc_attr($lng); ?>&z=15&output=embed"
            width="100%" height="280" style="border:0;border-radius:10px;" allowfullscreen loading="lazy"
            referrerpolicy="no-referrer-when-downgrade">
          </iframe>
        </div>
        <a href="https://maps.google.com/?q=<?php echo esc_attr($lat); ?>,<?php echo esc_attr($lng); ?>"
           target="_blank" rel="noopener" class="amir-imm__maps-link">
          <?php echo $is_en?'Open in Google Maps ↗':'Ver en Google Maps ↗'; ?>
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- FAQ opcional por tour, ver § 16.93 CONTRIBUTING.md -->
    <?php if ( ! empty( $faq_items ) ) : ?>
    <div class="amir-imm__section">
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

  </div><!-- body -->

  <!-- Barra flotante de reserva — aparece al pasar el hero, oculta cuando el widget real ya está a la vista -->
  <?php if ( $price_from > 0 || $db_id ) : ?>
  <div class="amir-imm__floating-bar" id="amir-imm-floating-bar">
    <div class="amir-imm__floating-bar-info">
      <?php if ( $price_from > 0 ) : ?>
        <span class="amir-imm__floating-bar-from"><?php echo $is_en?'From':'Desde'; ?></span>
        <span class="amir-imm__floating-bar-price">$<?php echo number_format($price_from,0,'.',','); ?> <?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></span>
      <?php else : ?>
        <span class="amir-imm__floating-bar-price"><?php echo esc_html( $title ); ?></span>
      <?php endif; ?>
    </div>
    <a href="#amir-imm-book" class="amir-imm__floating-bar-cta"><?php echo $is_en ? 'Reserve' : 'Reservar'; ?></a>
  </div>
  <?php endif; ?>

</div><!-- amir-imm -->

<!-- Lightbox liviano en JS vanilla (mismo patrón que single-flow_room.php,
     § 16.12 CONTRIBUTING.md) — bug real corregido 2026-08-03: el filmstrip
     antes linkeaba directo al archivo de imagen (sin target ni lightbox). -->
<div id="amir-lightbox" class="amir-lightbox" hidden>
  <button type="button" class="amir-lightbox__close" aria-label="Cerrar">✕</button>
  <div class="amir-lightbox__content"></div>
</div>

<?php
$brand_color      = esc_attr( get_option( 'amir_brand_color', '#1D9E75' ) );
$brand_color_dark = esc_attr( get_option( 'amir_brand_color_dark', '#0F6E56' ) );
?>
<style>
.amir-imm { --teal:<?php echo $brand_color; ?>; --teal-dark:<?php echo $brand_color_dark; ?>; --teal-light:#e1f5ee; position:relative; }

/* Hero a pantalla completa */
.amir-imm__hero { position:relative; min-height:100vh; background:#1a2e24 center/cover no-repeat; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:32px 20px; }
.amir-imm__hero-scrim { position:absolute; inset:0; background:linear-gradient(180deg,rgba(0,0,0,.35) 0%,rgba(0,0,0,.25) 40%,rgba(0,0,0,.6) 100%); }
.amir-imm__hero-content { position:relative; max-width:640px; }
.amir-imm__provider-badge { display:inline-block; margin-bottom:14px; background:rgba(255,255,255,.15); color:rgba(255,255,255,.9); font-size:11px; font-weight:600; padding:5px 12px; border-radius:20px; backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); }
.amir-imm__title { font-size:clamp(28px,6vw,52px); font-weight:800; color:#fff; margin:0 0 18px; line-height:1.1; text-shadow:0 2px 8px rgba(0,0,0,.35); }
.amir-imm__chips { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; margin-bottom:26px; }
.amir-imm__chip { background:rgba(255,255,255,.18); color:#fff; font-size:13px; font-weight:700; padding:6px 14px; border-radius:20px; backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); }
.amir-imm__hero-cta { display:inline-block; background:var(--teal); color:#fff; font-size:15px; font-weight:700; padding:14px 32px; border-radius:999px; text-decoration:none; box-shadow:0 8px 24px rgba(0,0,0,.25); transition:transform .15s; }
.amir-imm__hero-cta:hover { transform:translateY(-2px); }
.amir-imm__hero-scroll-hint { position:absolute; bottom:24px; left:50%; transform:translateX(-50%); color:rgba(255,255,255,.8); font-size:26px; animation:amir-imm-bounce 1.8s infinite; }
@keyframes amir-imm-bounce { 0%,100%{ transform:translate(-50%,0); } 50%{ transform:translate(-50%,8px); } }

/* Cuerpo — columna única centrada */
.amir-imm__body { max-width:720px; margin:0 auto; padding:48px 20px 40px; }

.amir-imm__highlights { list-style:none; margin:0 0 36px; padding:0; display:grid; grid-template-columns:1fr 1fr; gap:10px 20px; }
.amir-imm__highlights li { font-size:14px; color:#1a2e24; font-weight:600; padding-left:26px; position:relative; }
.amir-imm__highlights li::before { content:"✓"; position:absolute; left:0; color:var(--teal); font-weight:800; font-size:15px; }

.amir-imm__facts { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:36px; }
.amir-imm__fact { display:flex; align-items:center; gap:10px; background:var(--teal-light); border-radius:10px; padding:12px 14px; }
.amir-imm__fact-icon { font-size:22px; line-height:1; flex-shrink:0; }
.amir-imm__fact div { display:flex; flex-direction:column; min-width:0; }
.amir-imm__fact-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:var(--teal-dark); }
.amir-imm__fact-value { font-size:13px; color:#1a2e24; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.amir-imm__section { margin-bottom:44px; }
.amir-imm__section h2 { font-size:24px; font-weight:800; color:#1a2e24; margin:0 0 18px; letter-spacing:-.3px; }
.amir-imm__section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.amir-imm__section-header h2 { margin:0; }
.amir-imm__collapse-all { background:none; border:none; color:var(--teal); font-size:12px; font-weight:600; cursor:pointer; padding:0; }
.amir-imm__collapse-all:hover { text-decoration:underline; }
.amir-imm__prose { font-size:16px; color:#3d3d3a; line-height:1.8; }

/* Galería filmstrip */
.amir-imm__filmstrip { display:flex; gap:10px; overflow-x:auto; scroll-snap-type:x mandatory; padding-bottom:8px; margin-bottom:44px; -webkit-overflow-scrolling:touch; }
.amir-imm__filmstrip-item { flex:0 0 auto; scroll-snap-align:start; width:min(78vw,340px); aspect-ratio:4/3; border-radius:12px; overflow:hidden; display:block; cursor:zoom-in; }
.amir-imm__filmstrip-item img { width:100%; height:100%; object-fit:cover; }

/* Widget de reserva — sección propia */
.amir-imm__book { background:var(--teal-light); border-radius:20px; padding:32px 24px; margin-bottom:44px; scroll-margin-top:24px; }
.amir-imm__book h2 { font-size:22px; font-weight:800; color:#1a2e24; margin:0 0 20px; text-align:center; }
.amir-imm__book-fallback { text-align:center; color:#3d3d3a; font-size:14px; }
.amir-imm__whatsapp { display:inline-block; background:#25D366; color:#fff; padding:10px 20px; border-radius:8px; text-decoration:none; font-weight:700; margin-top:10px; }

/* Itinerario (mismo comportamiento que la Clásica, clases propias) */
.amir-imm__stop { position:relative; padding-left:28px; margin-left:10px; }
.amir-imm__stop:not(:last-child) { border-left:2px dotted #c3d9d0; }
.amir-imm__stop-marker { position:absolute; left:-11px; top:12px; width:20px; height:20px; border-radius:50%; background:#fff; border:2px solid var(--teal); box-sizing:border-box; display:flex; align-items:center; justify-content:center; font-size:10px; line-height:1; }
.amir-imm__stop--start .amir-imm__stop-marker { background:var(--teal); border-color:var(--teal); }
.amir-imm__stop summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:8px; padding:8px 0; }
.amir-imm__stop summary::-webkit-details-marker { display:none; }
.amir-imm__stop-title { font-weight:700; color:#1a2e24; font-size:15px; }
.amir-imm__stop-chevron { margin-left:auto; color:#5a7068; font-size:12px; transition:transform .15s; }
.amir-imm__stop[open] .amir-imm__stop-chevron { transform:rotate(180deg); }
.amir-imm__stop-body { padding:0 0 16px 4px; }
.amir-imm__stop-body img { width:100%; max-width:360px; border-radius:8px; margin:0 0 10px; display:block; }
.amir-imm__stop-body p { font-size:14px; color:#3d3d3a; line-height:1.6; margin:0; }

/* Incluye / No incluye */
.amir-imm__incl-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
.amir-imm__incl-title { display:flex; align-items:center; gap:6px; font-size:15px; }
.amir-imm__incl-title--yes { color:var(--teal-dark); }
.amir-imm__incl-title--no { color:#c53030; }
.amir-imm__incl-list { list-style:none; padding:0; margin:0; }
.amir-imm__incl-list li { font-size:14px; color:#3d3d3a; padding:6px 0; border-bottom:1px solid #f5f5f5; display:flex; gap:8px; }
.amir-imm__incl-list--yes li::before { content:"✓"; color:var(--teal); font-weight:700; flex-shrink:0; }
.amir-imm__incl-list--no li::before { content:"✕"; color:#c53030; font-weight:700; flex-shrink:0; }

.amir-imm__meeting-text { font-size:15px; color:#3d3d3a; margin:0 0 14px; }
.amir-imm__map { margin-bottom:10px; }
.amir-imm__maps-link { color:var(--teal); font-size:13px; font-weight:600; text-decoration:none; }
.amir-imm__maps-link:hover { text-decoration:underline; }

/* FAQ opcional por tour */
.amir-faq { display:flex; flex-direction:column; gap:10px; }
.amir-faq__item { border:1px solid #e1f5ee; border-radius:10px; padding:4px 18px; }
.amir-faq__item summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:10px; padding:14px 0; }
.amir-faq__item summary::-webkit-details-marker { display:none; }
.amir-faq__q { font-weight:700; color:#1a2e24; font-size:15px; }
.amir-faq__chevron { margin-left:auto; color:#5a7068; font-size:12px; transition:transform .15s; }
.amir-faq__item[open] .amir-faq__chevron { transform:rotate(180deg); }
.amir-faq__a { font-size:14px; color:#3d3d3a; line-height:1.7; padding:0 0 16px; }

/* Barra flotante de reserva */
.amir-imm__floating-bar { position:fixed; left:0; right:0; bottom:0; z-index:100; background:#fff; border-top:1px solid #e1f5ee; box-shadow:0 -4px 16px rgba(0,0,0,.08); padding:12px 20px; display:flex; align-items:center; justify-content:space-between; gap:16px; transform:translateY(100%); transition:transform .25s ease; }
.amir-imm__floating-bar--visible { transform:translateY(0); }
.amir-imm__floating-bar-info { display:flex; flex-direction:column; min-width:0; }
.amir-imm__floating-bar-from { font-size:11px; color:#5a7068; }
.amir-imm__floating-bar-price { font-size:16px; font-weight:800; color:#1a2e24; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.amir-imm__floating-bar-cta { flex-shrink:0; background:var(--teal); color:#fff; font-size:14px; font-weight:700; padding:11px 26px; border-radius:999px; text-decoration:none; }

@media (max-width:640px) {
  .amir-imm__highlights { grid-template-columns:1fr; }
  .amir-imm__incl-grid { grid-template-columns:1fr; }
  .amir-imm__book { padding:24px 16px; border-radius:16px; }
}

.amir-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.9); z-index:9999; display:flex; align-items:center; justify-content:center; padding:20px; }
.amir-lightbox[hidden] { display:none; }
.amir-lightbox__content { max-width:min(960px,92vw); max-height:86vh; width:100%; }
.amir-lightbox__content img { width:100%; height:100%; max-height:86vh; object-fit:contain; display:block; margin:0 auto; }
.amir-lightbox__close { position:absolute; top:16px; right:20px; background:none; border:none; color:#fff; font-size:28px; cursor:pointer; line-height:1; }
/* Video en el filmstrip — miniatura estática + botón de play, el iframe
   real recién se inyecta al hacer click (§ 16.46 CONTRIBUTING.md). */
.amir-imm__filmstrip-item--video { position:relative; background:#0e2b30; }
.amir-imm__filmstrip-play { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#fff; background:rgba(0,0,0,.28); transition:background .15s; }
.amir-imm__filmstrip-item--video:hover .amir-imm__filmstrip-play { background:rgba(0,0,0,.4); }
.amir-imm__filmstrip-play svg { filter:drop-shadow(0 1px 3px rgba(0,0,0,.5)); }
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

<script>
(function(){
  var bar   = document.getElementById('amir-imm-floating-bar');
  var book  = document.getElementById('amir-imm-book');
  var hero  = document.querySelector('.amir-imm__hero');
  if (!bar || !book || !hero) return;

  var pastHero = false;
  var bookVisible = false;

  function sync(){
    if (pastHero && !bookVisible) {
      bar.classList.add('amir-imm__floating-bar--visible');
    } else {
      bar.classList.remove('amir-imm__floating-bar--visible');
    }
  }

  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function(entries){
      pastHero = entries[0].intersectionRatio === 0 && entries[0].boundingClientRect.top < 0;
      sync();
    }, { threshold: [0] }).observe(hero);

    new IntersectionObserver(function(entries){
      bookVisible = entries[0].isIntersecting;
      sync();
    }, { threshold: 0.15 }).observe(book);
  }
})();
</script>

<?php include AMIR_PLUGIN_DIR . 'templates/parts/tour-suggested.php'; ?>

<?php endwhile; ?>

<?php get_footer(); ?>
