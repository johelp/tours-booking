<?php
/**
 * Datos compartidos para la plantilla de detalle de habitación
 * (templates/single-flow_room.php) — mismo criterio que
 * templates/parts/tour-data.php: se incluye siempre desde AMIR_PLUGIN_DIR,
 * nunca con ruta relativa al archivo que lo incluye, así sigue funcionando
 * si el operador copia solo la plantilla principal a su tema.
 *
 * Se ejecuta dentro del `while (have_posts()) : the_post(); ... endwhile;`
 * de la plantilla que lo incluye. Deja disponibles: $post_id, $db_id, $lang,
 * $is_en, $title, $description, $cover, $capacity_max, $min_nights,
 * $price_per_night, $checkin_time, $checkout_time, $gallery_ids, $amenities,
 * $video_url.
 */

defined( 'ABSPATH' ) || exit;

$post_id = get_the_ID();
$db_id   = (int) get_post_meta( $post_id, '_flow_room_db_id', true );

// flow_room solo existe en Pro Max (RoomPostType se auto-desactiva si no,
// § 16.5 CONTRIBUTING.md) — English-first siempre acá, decisión del
// cliente 2026-08-04, sin necesidad de chequear AMIR_EDITION.
$lang  = \AmirBooking\Core\Shortcodes::detect_lang( 'en' );
$is_en = $lang === 'en';

global $wpdb;
$room = $db_id ? $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}flow_rooms WHERE id = %d", $db_id
) ) : null;

$title           = $is_en && $room && $room->name_en ? $room->name_en : get_the_title();
$description     = get_the_content();
$cover            = get_the_post_thumbnail_url( $post_id, 'full' );
$capacity_max     = $room ? (int) $room->capacity_max : (int) get_post_meta( $post_id, '_flow_capacity_max', true );
$min_nights       = $room ? (int) $room->min_nights : (int) get_post_meta( $post_id, '_flow_min_nights', true );
$price_per_night  = $room ? (float) $room->price_per_night : (float) get_post_meta( $post_id, '_flow_price_per_night', true );
$checkin_time     = $room->default_checkin_time  ?? get_post_meta( $post_id, '_flow_default_checkin_time', true )  ?: '15:00';
$checkout_time    = $room->default_checkout_time ?? get_post_meta( $post_id, '_flow_default_checkout_time', true ) ?: '11:00';
$gallery_ids      = json_decode( get_post_meta( $post_id, '_flow_gallery_ids', true ) ?: '[]', true );

// Amenities (§ 16.11 CONTRIBUTING.md) — ícono + label ES/EN, mismo patrón
// que detail_facts de tours. video_url: se muestra como una foto más al
// principio de la galería (ver single-flow_room.php), no una sección aparte.
$amenities_raw = json_decode( $room->amenities ?? get_post_meta( $post_id, '_flow_amenities', true ) ?: '[]', true );
$amenities     = [];
foreach ( (array) $amenities_raw as $a ) {
	$label = $is_en ? ( $a['label_en'] ?: $a['label_es'] ) : ( $a['label_es'] ?: $a['label_en'] );
	if ( $label === '' ) {
		continue;
	}
	$amenities[] = [ 'icon' => $a['icon'] ?? '', 'label' => $label ];
}
$video_url = $room->video_url ?? get_post_meta( $post_id, '_flow_video_url', true ) ?: '';

/** ID de YouTube a partir de cualquier formato de URL habitual (watch?v=, youtu.be/, /embed/). */
if ( ! function_exists( 'flow_youtube_id' ) ) {
	function flow_youtube_id( string $url ): string {
		if ( preg_match( '/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
$youtube_id = $video_url ? flow_youtube_id( $video_url ) : '';
