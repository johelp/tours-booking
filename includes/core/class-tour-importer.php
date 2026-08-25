<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Importa tours desde JSON ({"tours": [ {...}, {...} ]}) — CONTRIBUTING.md
 * § 15.9. Decisiones cerradas al construir (2026-07-31):
 * - Crea/actualiza un post real de amir_tour (el CPT sigue siendo la fuente
 *   de verdad, TourPostType::sync_to_db() ya copia a la tabla amir_tours).
 * - Slug ya existente = actualiza ese tour (upsert), no crea uno duplicado.
 * - Imágenes (gallery_images, URLs externas en el JSON) se descargan a la
 *   Media Library vía media_sideload_image() — quedan alojadas igual que el
 *   resto de las fotos del plugin, no dependen de que la URL externa siga
 *   viva después.
 * - Todo tour importado queda en 'draft' — el operador revisa y publica a
 *   mano, un import automático no debe dejar contenido sin revisar en vivo.
 *   Excepción: $publish=true (usado por la carga de datos de ejemplo, ver
 *   SettingsPage — un tour de prueba sin publicar no sirve para testear el
 *   flujo de reserva real).
 * - v2 (2026-08-09): horarios, precios (por persona o por grupo — bandas
 *   dinámicas según max_capacity, ver TourPostType::group_price_ranges()),
 *   fecha fija, "solo a pedido", días operativos y lista de interés ya se
 *   pueden cargar por JSON — antes quedaban fuera a propósito (§ 15.9
 *   original) porque el editor los guardaba leyendo $_POST directo, sin
 *   una función que aceptara los datos como parámetro. set_schedules_and_prices()
 *   inserta directo en las tablas en vez de tocar ese camino (que sigue
 *   siendo el mismo que usa el editor en vivo, sin cambios ni riesgo nuevo
 *   ahí) — mismas reglas de negocio (bandas de grupo, filas genéricas vs.
 *   por horario/temporada), sin duplicar lógica gracias a
 *   group_price_ranges() ya pública.
 */
class TourImporter {

	/**
	 * @param array  $data    Ya decodificado de JSON (json_decode($json, true)).
	 * @param bool   $publish true = publica cada tour importado en vez de dejarlo
	 *                        en borrador (solo para datos de ejemplo, nunca para
	 *                        import de contenido real de un cliente).
	 * @param string $lang    'es'|'en' — idioma de los mensajes de resultado
	 *                        (pantalla de admin, sigue el idioma detectado por
	 *                        el caller, no el contenido importado en sí).
	 * @return array{results: array, created: int, updated: int, errors: int}
	 */
	public function import( array $data, bool $publish = false, string $lang = 'es' ): array {
		$tours = $data['tours'] ?? null;
		if ( ! is_array( $tours ) || empty( $tours ) ) {
			return [ 'results' => [], 'created' => 0, 'updated' => 0, 'errors' => 1, 'fatal_error' => $lang === 'en'
				? 'The JSON has no "tours" key with at least one element.'
				: 'El JSON no tiene una clave "tours" con al menos un elemento.' ];
		}

		$results = [];
		$created = 0;
		$updated = 0;
		$errors  = 0;

		foreach ( $tours as $i => $tour_data ) {
			if ( ! is_array( $tour_data ) ) {
				$results[] = [ 'index' => $i, 'action' => 'error', 'error' => $lang === 'en'
					? 'Item is not a valid JSON object.'
					: 'Elemento no es un objeto JSON válido.' ];
				$errors++;
				continue;
			}
			$result = $this->import_one( $tour_data, $publish, $lang );
			$results[] = array_merge( [ 'index' => $i ], $result );
			if ( $result['action'] === 'created' ) {
				$created++;
			} elseif ( $result['action'] === 'updated' ) {
				$updated++;
			} else {
				$errors++;
			}
		}

		return compact( 'results', 'created', 'updated', 'errors' );
	}

	/** @return array{action: string, tour_id?: int, name?: string, error?: string} */
	private function import_one( array $t, bool $publish = false, string $lang = 'es' ): array {
		$name_es = sanitize_text_field( $t['name_es'] ?? '' );
		if ( $name_es === '' ) {
			return [ 'action' => 'error', 'error' => $lang === 'en' ? 'Missing name_es.' : 'Falta name_es.' ];
		}

		// Un tour puntual puede forzar su propio estado (ej. el tour de
		// ejemplo de Lista de interés necesita quedar en 'draft' aunque el
		// resto del lote de datos de ejemplo se publique) — gana sobre el
		// $publish general del lote.
		if ( in_array( $t['status'] ?? '', [ 'publish', 'draft' ], true ) ) {
			$publish = $t['status'] === 'publish';
		}

		$slug = sanitize_title( $t['slug'] ?? $name_es );

		$existing = get_posts( [
			'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
		] );

		$postarr = [
			'post_type'    => \AmirBooking\CPT\TourPostType::POST_TYPE,
			'post_title'   => $name_es,
			'post_name'    => $slug,
			'post_content' => wp_kses_post( $t['description_es'] ?? '' ),
		];

		if ( $existing ) {
			$post_id = $existing[0]->ID;
			$postarr['ID'] = $post_id;
			if ( $publish ) {
				$postarr['post_status'] = 'publish';
			}
			$updated_result = wp_update_post( $postarr, true );
			$action = 'updated';
		} else {
			$postarr['post_status'] = $publish ? 'publish' : 'draft';
			$updated_result = wp_insert_post( $postarr, true );
			$action = 'created';
		}

		if ( is_wp_error( $updated_result ) ) {
			return [ 'action' => 'error', 'name' => $name_es, 'error' => $updated_result->get_error_message() ];
		}
		$post_id = $updated_result;

		$this->set_meta( $post_id, $t );
		$this->set_gallery( $post_id, $t['gallery_images'] ?? [] );

		// Dispara el mismo sync que corre al guardar desde el editor —
		// TourPostType::sync_to_db() ya no depende de $_POST, solo de la
		// meta ya guardada arriba, así que es seguro llamarlo directo. Esto
		// ya sincroniza amir_tours (incluidos fixed_date/request_only, ver
		// set_meta()) y la regla base de disponibilidad (_amir_active_weekdays).
		$tour_post = get_post( $post_id );
		( new \AmirBooking\CPT\TourPostType() )->sync_to_db( $post_id, $tour_post );

		// Horarios y precios — únicos datos que sync_to_db() NO cubre acá
		// (su sync interno lee $_POST directo, ver nota de clase arriba).
		$tour_db_id = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );
		if ( $tour_db_id ) {
			$this->set_schedules_and_prices( $tour_db_id, $t );
		}

		return [ 'action' => $action, 'tour_id' => $post_id, 'name' => $name_es ];
	}

	private function set_meta( int $post_id, array $t ): void {
		$str = fn( $k ) => sanitize_text_field( $t[ $k ] ?? '' );
		// JSON_UNESCAPED_UNICODE — bug real y grave encontrado en vivo
		// 2026-08-12 (caliafarm.com/stag): sin este flag, wp_json_encode()
		// escapa cualquier carácter no-ASCII (emoji, tildes, "ñ") a \uXXXX;
		// en algún punto de la cadena real de WordPress ese texto pasa por
		// un wp_unslash()/stripslashes() de más (mecanismo confirmado con
		// una prueba aislada: stripslashes('😍') → 'ud83dude0d',
		// exactamente el síntoma reportado — "Español" se veía como
		// "Espau00f1ol"). Guardar el UTF-8 real evita el problema de raíz,
		// sin depender de encontrar el wp_unslash() exacto que lo pela.
		$arr_json = fn( $k ) => wp_json_encode( array_map( 'sanitize_text_field', (array) ( $t[ $k ] ?? [] ) ), JSON_UNESCAPED_UNICODE );

		update_post_meta( $post_id, '_amir_name_en', $str( 'name_en' ) );
		update_post_meta( $post_id, '_amir_price_model', in_array( $t['price_model'] ?? '', [ 'percapita', 'group' ], true ) ? $t['price_model'] : 'percapita' );
		update_post_meta( $post_id, '_amir_description_en', wp_kses_post( $t['description_en'] ?? '' ) );
		update_post_meta( $post_id, '_amir_what_to_expect_es', wp_kses_post( $t['what_to_expect_es'] ?? '' ) );
		update_post_meta( $post_id, '_amir_what_to_expect_en', wp_kses_post( $t['what_to_expect_en'] ?? '' ) );
		update_post_meta( $post_id, '_amir_highlights_es', $arr_json( 'highlights_es' ) );
		update_post_meta( $post_id, '_amir_highlights_en', $arr_json( 'highlights_en' ) );
		update_post_meta( $post_id, '_amir_includes_es', $arr_json( 'includes_es' ) );
		update_post_meta( $post_id, '_amir_includes_en', $arr_json( 'includes_en' ) );
		update_post_meta( $post_id, '_amir_excludes_es', $arr_json( 'excludes_es' ) );
		update_post_meta( $post_id, '_amir_excludes_en', $arr_json( 'excludes_en' ) );
		update_post_meta( $post_id, '_amir_meeting_point_es', sanitize_textarea_field( $t['meeting_point_es'] ?? '' ) );
		update_post_meta( $post_id, '_amir_meeting_point_en', sanitize_textarea_field( $t['meeting_point_en'] ?? '' ) );
		if ( isset( $t['meeting_lat'] ) )  update_post_meta( $post_id, '_amir_meeting_lat', (float) $t['meeting_lat'] );
		if ( isset( $t['meeting_lng'] ) )  update_post_meta( $post_id, '_amir_meeting_lng', (float) $t['meeting_lng'] );
		update_post_meta( $post_id, '_amir_duration_minutes', absint( $t['duration_minutes'] ?? 0 ) );
		update_post_meta( $post_id, '_amir_min_age', absint( $t['min_age'] ?? 0 ) );
		update_post_meta( $post_id, '_amir_min_age_child', absint( $t['min_age_child'] ?? 4 ) );
		update_post_meta( $post_id, '_amir_max_capacity', absint( $t['max_capacity'] ?? 10 ) );
		update_post_meta( $post_id, '_amir_min_passengers', absint( $t['min_passengers'] ?? 1 ) );
		update_post_meta( $post_id, '_amir_allow_children', empty( $t['allow_children'] ) && isset( $t['allow_children'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_amir_allow_babies', empty( $t['allow_babies'] ) && isset( $t['allow_babies'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_amir_languages', implode( ', ', array_map( 'sanitize_text_field', (array) ( $t['languages'] ?? [ 'Español' ] ) ) ) );

		// Fecha fija / "solo a pedido" (§ 16.35/16.39 CONTRIBUTING.md) — ambos
		// opcionales e independientes entre sí. fixed_date vacío = comportamiento
		// normal de calendario (mismo criterio que el editor).
		if ( ! empty( $t['fixed_date'] ) ) {
			update_post_meta( $post_id, '_amir_fixed_date', sanitize_text_field( $t['fixed_date'] ) );
		}
		update_post_meta( $post_id, '_amir_request_only', ! empty( $t['request_only'] ) ? '1' : '0' );

		// Días operativos por defecto — mismo meta que llena el checkbox del
		// editor; sync_to_db() ya crea/actualiza la regla base a partir de esto
		// (sync_base_availability_rule()), no hace falta tocar amir_availability_rules acá.
		if ( isset( $t['active_weekdays'] ) && is_array( $t['active_weekdays'] ) ) {
			$weekdays = array_values( array_unique( array_map( 'intval', $t['active_weekdays'] ) ) );
			update_post_meta( $post_id, '_amir_active_weekdays', wp_json_encode( $weekdays ) );
		}

		// Lista de interés ("Próximamente", § 5.1 CONTRIBUTING.md) — solo
		// tiene efecto real si el tour además queda en borrador (import
		// normal); en la carga de datos de ejemplo con $publish=true el tour
		// se publica igual y estos campos quedan sin efecto visible, no pasa nada.
		if ( isset( $t['wishlist_enabled'] ) ) {
			update_post_meta( $post_id, '_amir_wishlist_enabled', ! empty( $t['wishlist_enabled'] ) ? '1' : '0' );
		}
		if ( ! empty( $t['wishlist_threshold'] ) ) {
			update_post_meta( $post_id, '_amir_wishlist_threshold', absint( $t['wishlist_threshold'] ) );
		}
		if ( ! empty( $t['wishlist_date'] ) ) {
			update_post_meta( $post_id, '_amir_wishlist_date', sanitize_text_field( $t['wishlist_date'] ) );
		}

		if ( ! empty( $t['itinerary_stops'] ) && is_array( $t['itinerary_stops'] ) ) {
			$stops = array_map( function ( $s ) {
				return [
					'title_es' => sanitize_text_field( $s['title_es'] ?? '' ),
					'title_en' => sanitize_text_field( $s['title_en'] ?? '' ),
					'desc_es'  => sanitize_textarea_field( $s['desc_es'] ?? '' ),
					'desc_en'  => sanitize_textarea_field( $s['desc_en'] ?? '' ),
					'image_id' => 0, // imágenes de paradas no se sideload en v1 — solo la galería principal
					'is_start' => ! empty( $s['is_start'] ),
				];
			}, $t['itinerary_stops'] );
			update_post_meta( $post_id, '_amir_itinerary_stops', wp_json_encode( $stops, JSON_UNESCAPED_UNICODE ) );
		}

		if ( ! empty( $t['detail_facts'] ) && is_array( $t['detail_facts'] ) ) {
			$facts = array_map( function ( $f ) {
				return [
					'icon'     => sanitize_text_field( $f['icon'] ?? '' ),
					'label_es' => sanitize_text_field( $f['label_es'] ?? '' ),
					'label_en' => sanitize_text_field( $f['label_en'] ?? '' ),
					'value_es' => sanitize_text_field( $f['value_es'] ?? '' ),
					'value_en' => sanitize_text_field( $f['value_en'] ?? '' ),
				];
			}, $t['detail_facts'] );
			update_post_meta( $post_id, '_amir_detail_facts', wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Horarios y precios — a diferencia del resto de set_meta(), estos no
	 * pasan por sync_to_db() (su sync interno de horarios/precios lee
	 * $_POST directo, es el mismo código que usa el editor en vivo y no se
	 * tocó para no arriesgar ese camino). Inserta directo en
	 * amir_tour_schedules/amir_prices, con la misma lógica de bandas de
	 * grupo que usa el editor (TourPostType::group_price_ranges(), ya
	 * pública para esto).
	 *
	 * Formato esperado en $t:
	 *   "schedules": [ { "time_start": "06:00", "time_end": "09:00", "label_es": "...", "label_en": "..." } ]
	 *   "prices":       { "adult": 650, "child": 450, "baby": 0 }              — si price_model = percapita
	 *   "prices_group": [ 1200, 1600, 1900 ]                                    — si price_model = group,
	 *     un valor por banda en el mismo orden que group_price_ranges($max_capacity)
	 *     (1–2 personas, 3 personas, 4–máximo) — bandas de más faltan si el
	 *     array es más corto, de más se ignoran si es más largo.
	 */
	private function set_schedules_and_prices( int $tour_db_id, array $t ): void {
		global $wpdb;

		if ( ! empty( $t['schedules'] ) && is_array( $t['schedules'] ) ) {
			$wpdb->delete( "{$wpdb->prefix}amir_tour_schedules", [ 'tour_id' => $tour_db_id ] );
			foreach ( array_values( $t['schedules'] ) as $i => $s ) {
				if ( empty( $s['time_start'] ) || empty( $s['time_end'] ) ) {
					continue;
				}
				$wpdb->insert( "{$wpdb->prefix}amir_tour_schedules", [
					'tour_id'    => $tour_db_id,
					'time_start' => sanitize_text_field( $s['time_start'] ),
					'time_end'   => sanitize_text_field( $s['time_end'] ),
					'label_es'   => sanitize_text_field( $s['label_es'] ?? '' ),
					'label_en'   => sanitize_text_field( $s['label_en'] ?? '' ),
					'sort_order' => $i,
					'active'     => 1,
				] );
			}
		}

		if ( ! isset( $t['prices'] ) && ! isset( $t['prices_group'] ) ) {
			return;
		}

		// Mismo criterio que TourPostType::sync_schedules_prices(): solo se
		// tocan las filas "genéricas" (sin horario/temporada asignados) —
		// nunca precios especiales que este importador no gestiona.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}amir_prices
			 WHERE tour_id = %d AND schedule_id IS NULL AND valid_from IS NULL AND valid_until IS NULL",
			$tour_db_id
		) );

		// Misma validación que set_meta() usa para _amir_price_model — no lee
		// la meta ya guardada para no depender del orden de llamadas, alcanza
		// con re-derivarlo del mismo JSON.
		$price_model = in_array( $t['price_model'] ?? '', [ 'percapita', 'group' ], true ) ? $t['price_model'] : 'percapita';

		if ( $price_model === 'group' && ! empty( $t['prices_group'] ) && is_array( $t['prices_group'] ) ) {
			$max_capacity = absint( $t['max_capacity'] ?? 10 ) ?: 10;
			$bands  = \AmirBooking\CPT\TourPostType::group_price_ranges( $max_capacity );
			$values = array_values( $t['prices_group'] );
			foreach ( $bands as $i => [ $gmin, $gmax ] ) {
				$price_val = (float) ( $values[ $i ] ?? 0 );
				if ( $price_val <= 0 ) {
					continue;
				}
				$wpdb->insert( "{$wpdb->prefix}amir_prices", [
					'tour_id'     => $tour_db_id,
					'person_type' => 'group',
					'group_min'   => $gmin,
					'group_max'   => $gmax,
					'price_mxn'   => $price_val,
				] );
			}
		} elseif ( ! empty( $t['prices'] ) && is_array( $t['prices'] ) ) {
			foreach ( [ 'adult', 'child', 'baby' ] as $type ) {
				if ( ! isset( $t['prices'][ $type ] ) ) {
					continue;
				}
				$wpdb->insert( "{$wpdb->prefix}amir_prices", [
					'tour_id'     => $tour_db_id,
					'person_type' => $type,
					'price_mxn'   => max( 0, (float) $t['prices'][ $type ] ),
				] );
			}
		}
	}

	/**
	 * Descarga cada URL a la Media Library. La primera queda como imagen
	 * destacada (portada); el resto, como galería (_amir_gallery_ids) — el
	 * mismo criterio que sync_to_db() ya usa al armar gallery_images
	 * (destacada primero, después la galería).
	 */
	private function set_gallery( int $post_id, array $urls ): void {
		$urls = array_filter( array_map( 'esc_url_raw', $urls ) );
		if ( empty( $urls ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = [];
		foreach ( $urls as $url ) {
			$attachment_id = $this->sideload_image( $url, $post_id );
			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		if ( empty( $attachment_ids ) ) {
			return;
		}

		$featured = array_shift( $attachment_ids );
		set_post_thumbnail( $post_id, $featured );
		update_post_meta( $post_id, '_amir_gallery_ids', wp_json_encode( $attachment_ids ) );
	}

	/**
	 * Wrapper propio sobre media_sideload_image() — bug real encontrado
	 * 2026-08-11 (reportado como "no se ven las imágenes destacadas, solo
	 * un ícono/emoji en el centro de la tarjeta" en grillas y flujos): esa
	 * función arma el nombre de archivo a subir a partir del PATH de la URL
	 * (`basename( parse_url( $url, PHP_URL_PATH ) )`) — si la URL no
	 * termina en una extensión reconocida (ej. `picsum.photos/id/1015/1200/800`,
	 * exactamente el patrón que usan los 6 tours de ejemplo de
	 * demo-tours.json) el archivo sube sin extensión válida y
	 * `wp_check_filetype_and_ext()` lo rechaza en silencio — `set_gallery()`
	 * descartaba el error y el tour quedaba sin imagen, sin avisar nada.
	 * Acá se descarga el archivo primero y la extensión se determina por el
	 * contenido real (`wp_check_filetype_and_ext()` con la ruta temporal
	 * real, que sí hace fallback a detección por contenido cuando el nombre
	 * no alcanza) en vez de confiar en el path de la URL.
	 *
	 * @return int ID del adjunto, o 0 si falló (mismo criterio silencioso
	 *   de antes — un tour sin imágenes no es un error fatal del import).
	 */
	private function sideload_image( string $url, int $post_id ): int {
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$hint     = basename( parse_url( $url, PHP_URL_PATH ) ?: '' );
		$filetype = wp_check_filetype_and_ext( $tmp, $hint );
		$ext      = $filetype['ext'] ?: 'jpg'; // fallback razonable: todo lo que llega hasta acá ya bajó como imagen real

		$file_array = [
			'name'     => 'import-' . md5( $url ) . '.' . $ext,
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return 0;
		}

		return (int) $attachment_id;
	}
}
