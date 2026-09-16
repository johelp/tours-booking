<?php
/**
 * Template para el archivo de habitaciones (/rooms/, Pro Max — CONTRIBUTING.md
 * § 16). Copiar a la raíz del tema activo como: archive-flow_room.php
 * WordPress lo usa automáticamente para el archive del CPT flow_room.
 *
 * Gap real encontrado 2026-08-04: este archivo nunca se construyó — /rooms/
 * caía al archive genérico del tema activo (mismo tipo de gap que § 16.10,
 * pero para el listado en vez de la ficha individual). A diferencia de
 * archive-amir_tour.php (arma su propio grid con WP_Query), acá se reusa
 * directo [flow_room_list] — ya renderiza un grid completo (imagen,
 * capacidad, precio, link a la ficha) sin duplicar esa lógica en PHP.
 */

defined( 'ABSPATH' ) || exit;

// English-first (Pro Max, decisión del cliente 2026-08-04) — flow_room solo
// existe en Pro Max, sin necesidad de chequear AMIR_EDITION.
$lang  = \AmirBooking\Core\Shortcodes::detect_lang( 'en' );
$is_en = $lang === 'en';

get_header();
?>

<div class="flow-archive-rooms">
  <div class="flow-archive-rooms__header">
    <h1><?php echo $is_en ? 'Our rooms' : 'Nuestras habitaciones'; ?></h1>
    <p><?php echo $is_en
      ? 'Pick a room and start building your stay — add experiences and extras in the same booking.'
      : 'Elegí una habitación y armá tu estadía — sumá experiencias y extras en la misma reserva.'; ?>
    </p>
  </div>

  <?php echo do_shortcode( '[flow_room_list lang="' . esc_attr( $lang ) . '"]' ); ?>
</div>

<style>
.flow-archive-rooms { max-width:1200px; margin:0 auto; padding:40px 20px 60px; }
.flow-archive-rooms__header { text-align:center; margin-bottom:32px; }
.flow-archive-rooms__header h1 { font-size:clamp(26px,4vw,38px); font-weight:800; color:#1a2e24; margin-bottom:10px; }
.flow-archive-rooms__header p  { font-size:16px; color:#5a7068; max-width:560px; margin:0 auto; }
</style>

<?php get_footer(); ?>
