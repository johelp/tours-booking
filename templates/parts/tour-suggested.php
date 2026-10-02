<?php
/**
 * Cross-sell "También te puede interesar" — compartido entre las plantillas
 * de detalle de tour (Clásica e Inmersiva). Reusa las tarjetas de
 * tour-cards.css (mismo estilo que la grilla [amir_tour_list]).
 *
 * Bug real reportado por el cliente (2026-08-21): estas tarjetas quedaban
 * siempre en el teal de fábrica, aunque el operador hubiera configurado
 * otro color de marca en Personalización — tour-cards.css tenía el color
 * hardcodeado a propósito ("no depende de las variables de tema"), decisión
 * de un momento en que --ab-teal ni existía como variable global. Corregido
 * en tour-cards.css: ahora usa var(--ab-teal, #1D9E75) con el mismo
 * fallback de siempre, así que en una página SIN el widget de reserva
 * (--ab-teal sin inyectar) se ve exactamente igual que antes.
 *
 * Espera $post_id e $is_en ya definidos (ver tour-data.php).
 */

defined( 'ABSPATH' ) || exit;

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
