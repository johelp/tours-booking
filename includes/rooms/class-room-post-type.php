<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

/**
 * Registra el Custom Post Type 'flow_room' — habitaciones reservables
 * (Pro Max, CONTRIBUTING.md § 16). Primer módulo bajo el namespace nuevo
 * TourFlow\ (§ 15.13): decisión del cliente de no seguir usando "amir" en
 * desarrollo nuevo. Mismo patrón que AmirBooking\CPT\TourPostType (CPT +
 * sync a tabla propia), pero mucho más chico a propósito — sin i18n de
 * múltiples idiomas ni categorías todavía, se suman después si hace falta.
 */
class RoomPostType {

	public const POST_TYPE = 'flow_room';

	public function register(): void {
		if ( AMIR_EDITION !== 'pro_max' ) {
			return;
		}

		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta' ], 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'sync_to_db' ], 20, 2 );
		add_action( 'before_delete_post', [ $this, 'delete_from_db' ] );
		add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_block_editor' ], 10, 2 );
		add_filter( 'enter_title_here', [ $this, 'title_placeholder' ] );

		// Columna "ID" — mismo motivo y mismo fix que TourPostType::add_id_column()
		// (§ CONTRIBUTING.md): el `room_id` que pide [flow_discovery mode="room"
		// room_id="X"] es `flow_rooms.id` (meta `_flow_room_db_id`), no el ID de
		// WordPress del post — pueden ser números distintos.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'add_id_column' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_id_column' ], 10, 2 );
	}

	public function add_id_column( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['flow_room_id'] = __( 'ID', 'amir-booking' );
			}
		}
		return $new;
	}

	public function render_id_column( string $column, int $post_id ): void {
		if ( $column !== 'flow_room_id' ) {
			return;
		}
		$db_id = (int) get_post_meta( $post_id, '_flow_room_db_id', true );
		if ( ! $db_id ) {
			echo '<span style="color:#5a7068;">—</span>';
			return;
		}
		printf(
			'<code title="%s" onclick="navigator.clipboard.writeText(\'%d\');var t=this.nextElementSibling;t.style.opacity=1;setTimeout(function(){t.style.opacity=0;},900);" style="cursor:pointer;background:#f0faf6;color:#0F6E56;padding:2px 7px;border-radius:5px;font-size:12px;">%d</code>'
			. '<span style="color:#1D9E75;font-size:11px;opacity:0;transition:opacity .2s;margin-left:5px;">✓ copiado</span>',
			esc_attr__( 'Clic para copiar — es el room_id que va en [flow_discovery mode="room" room_id="…"]', 'amir-booking' ),
			$db_id,
			$db_id
		);
	}

	public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		return $post_type === self::POST_TYPE ? false : $use_block_editor;
	}

	public function title_placeholder( string $title ): string {
		$screen = get_current_screen();
		if ( $screen && $screen->post_type === self::POST_TYPE ) {
			return __( 'Nombre de la habitación (ES)', 'amir-booking' );
		}
		return $title;
	}

	// ── CPT ───────────────────────────────────────────────────────────────

	public function register_post_type(): void {
		$labels = [
			'name'          => __( 'Habitaciones', 'amir-booking' ),
			'singular_name' => __( 'Habitación', 'amir-booking' ),
			'add_new'       => __( 'Agregar habitación', 'amir-booking' ),
			'add_new_item'  => __( 'Nueva habitación', 'amir-booking' ),
			'edit_item'     => __( 'Editar habitación', 'amir-booking' ),
			'view_item'     => __( 'Ver habitación', 'amir-booking' ),
			'search_items'  => __( 'Buscar habitaciones', 'amir-booking' ),
			'not_found'     => __( 'No se encontraron habitaciones', 'amir-booking' ),
			'menu_name'     => __( 'Habitaciones', 'amir-booking' ),
		];

		register_post_type( self::POST_TYPE, [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false, // va en el menú admin de TourFlow
			'show_in_nav_menus'  => true,
			'show_in_rest'       => true,
			'rest_base'          => 'flow-rooms',
			// URLs en inglés a propósito (2026-08-01) — Visit Sicily Experiences
			// y el resto de los sitios objetivo de Pro Max apuntan a público
			// EN/DE, no ES (a diferencia de Amir Adventours). 'rooms' en vez de
			// 'accommodation': más corto, mismo criterio que ya usa 'flow-rooms'
			// en el rest_base.
			'has_archive'        => 'rooms',
			'rewrite'            => [ 'slug' => 'room', 'with_front' => false ],
			'menu_icon'          => 'dashicons-admin-home',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields', 'page-attributes' ],
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'query_var'          => true,
		] );
	}

	// ── Meta Boxes ────────────────────────────────────────────────────────

	public function add_meta_boxes(): void {
		add_meta_box( 'flow_room_main', __( '⚙ Configuración de la habitación', 'amir-booking' ), [ $this, 'meta_box_main' ], self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'flow_room_gallery', __( '🖼 Galería de fotos', 'amir-booking' ), [ $this, 'meta_box_gallery' ], self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'flow_room_amenities', __( '✨ Amenities', 'amir-booking' ), [ $this, 'meta_box_amenities' ], self::POST_TYPE, 'normal', 'high' );
	}

	public function meta_box_main( \WP_Post $post ): void {
		wp_nonce_field( 'flow_room_meta', 'flow_room_nonce' );
		$m = $this->get_meta( $post->ID );
		?>
		<style>
		  .flow-room-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:8px; }
		  .flow-room-grid-3 { grid-template-columns:1fr 1fr 1fr; }
		  .flow-room-field label { display:block; font-weight:600; font-size:12px; color:#1a2e24; margin-bottom:4px; text-transform:uppercase; letter-spacing:.3px; }
		  .flow-room-field input, .flow-room-field select, .flow-room-field textarea { width:100%; border:1px solid #c3d9d0; border-radius:6px; padding:7px 10px; font-size:13px; box-sizing:border-box; }
		  .flow-room-field input:focus, .flow-room-field select:focus { outline:none; border-color:#1D9E75; box-shadow:0 0 0 2px rgba(29,158,117,.15); }
		  .flow-room-hint { font-size:11px; color:#888; margin-top:3px; }
		</style>

		<div class="flow-room-grid">
		  <div class="flow-room-field">
		    <label>Nombre en inglés (EN)</label>
		    <input type="text" name="flow_name_en" value="<?php echo esc_attr( $m['name_en'] ); ?>" placeholder="Room name in English" />
		  </div>
		  <div class="flow-room-field">
		    <label>Descripción en inglés (EN)</label>
		    <input type="text" name="flow_description_en" value="<?php echo esc_attr( $m['description_en'] ); ?>" placeholder="Room description in English" />
		  </div>
		</div>

		<div class="flow-room-grid flow-room-grid-3">
		  <div class="flow-room-field">
		    <label>Capacidad máxima (huéspedes)</label>
		    <input type="number" name="flow_capacity_max" value="<?php echo esc_attr( $m['capacity_max'] ); ?>" min="1" placeholder="2" />
		  </div>
		  <div class="flow-room-field">
		    <label>Noche mínima</label>
		    <input type="number" name="flow_min_nights" value="<?php echo esc_attr( $m['min_nights'] ); ?>" min="1" placeholder="1" />
		  </div>
		  <div class="flow-room-field">
		    <label>Precio por noche</label>
		    <input type="number" name="flow_price_per_night" value="<?php echo esc_attr( $m['price_per_night'] ); ?>" min="0" step="0.01" placeholder="0.00" />
		  </div>
		</div>

		<div class="flow-room-grid flow-room-grid-3">
		  <div class="flow-room-field">
		    <label>Check-in (hora habitual)</label>
		    <input type="time" name="flow_default_checkin_time" value="<?php echo esc_attr( $m['default_checkin_time'] ); ?>" />
		    <p class="flow-room-hint">Solo informativo — la disponibilidad se calcula por fecha, no por hora.</p>
		  </div>
		  <div class="flow-room-field">
		    <label>Check-out (hora habitual)</label>
		    <input type="time" name="flow_default_checkout_time" value="<?php echo esc_attr( $m['default_checkout_time'] ); ?>" />
		    <p class="flow-room-hint">El día de checkout, la habitación ya queda disponible para un check-in ese mismo día.</p>
		  </div>
		  <div class="flow-room-field">
		    <label>Orden de listado</label>
		    <input type="number" name="flow_sort_order" value="<?php echo esc_attr( $m['sort_order'] ); ?>" min="0" placeholder="0" />
		  </div>
		</div>

		<div class="flow-room-field">
		  <label>Proveedor externo (marketplace de habitaciones)</label>
		  <select name="flow_provider_id">
		    <option value=""><?php _e( '— Habitación propia —', 'amir-booking' ); ?></option>
		    <?php foreach ( $this->get_provider_options( (int) $m['provider_id'] ) as $p ) : ?>
		      <option value="<?php echo (int) $p->id; ?>" <?php selected( (int) $m['provider_id'], (int) $p->id ); ?>>
		        <?php echo esc_html( $p->business_name . ( (int) $p->active === 0 ? ' (inactivo)' : '' ) ); ?>
		      </option>
		    <?php endforeach; ?>
		  </select>
		  <p class="flow-room-hint">Reusa el mismo catálogo de proveedores del marketplace de tours (TourFlow → 🤝 Proveedores) — no evaluado todavía si el flujo de aprobación aplica igual a habitaciones, columna preparada desde el día uno para no migrar el esquema después.</p>
		</div>

		<div class="flow-room-field">
		  <label>Video (YouTube) — opcional</label>
		  <input type="url" name="flow_video_url" value="<?php echo esc_attr( $m['video_url'] ); ?>" placeholder="https://www.youtube.com/watch?v=..." />
		  <p class="flow-room-hint">Aparece como una foto más al principio de la galería, con un ícono de play — no una sección aparte (§ 16.11 CONTRIBUTING.md).</p>
		</div>
		<?php
	}

	// ── Meta Box: Amenities ───────────────────────────────────────────────

	/**
	 * 2+ bloques de ícono + label, mismo patrón que
	 * AmirBooking\CPT\TourPostType::meta_box_facts() (detail_facts) — free
	 * form a propósito: la lista de amenities varía mucho entre alojamientos
	 * (jacuzzi, vista al mar, cocina equipada...), no tiene sentido una
	 * lista fija cerrada en código.
	 */
	public function meta_box_amenities( \WP_Post $post ): void {
		$amenities = json_decode( get_post_meta( $post->ID, '_flow_amenities', true ) ?: '[]', true );
		if ( ! is_array( $amenities ) ) {
			$amenities = [];
		}
		?>
		<p style="font-size:12px;color:#666;margin:0 0 14px;">
		  Opcional — comodidades de la habitación (wifi, aire acondicionado, jacuzzi, vista al mar...). El ícono es un emoji, se pega directo (📶 ❄️ 🛁 🌊...).
		</p>

		<div id="flow-amenities-wrap">
		  <?php
		  $render_row = function ( $a = null ) {
			  $icon     = $a['icon']     ?? '';
			  $label_es = $a['label_es'] ?? '';
			  $label_en = $a['label_en'] ?? '';
			  ?>
			  <div class="flow-amenity-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
				<input type="text" name="flow_amenity_icon[]" value="<?php echo esc_attr( $icon ); ?>" placeholder="📶" maxlength="8" style="width:52px;text-align:center;border:1px solid #c3d9d0;border-radius:6px;padding:6px 4px;font-size:16px;box-sizing:border-box;" />
				<input type="text" name="flow_amenity_label_es[]" value="<?php echo esc_attr( $label_es ); ?>" placeholder="Wifi gratis" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
				<input type="text" name="flow_amenity_label_en[]" value="<?php echo esc_attr( $label_en ); ?>" placeholder="Free wifi" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
				<button type="button" onclick="this.closest('.flow-amenity-row').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
			  </div>
			  <?php
		  };
		  foreach ( $amenities as $a ) {
			  $render_row( $a );
		  }
		  ?>
		</div>
		<button type="button" id="flow-add-amenity-btn"
		        style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">
		  + <?php _e( 'Agregar amenity', 'amir-booking' ); ?>
		</button>

		<script>
		document.getElementById('flow-add-amenity-btn').addEventListener('click', function(){
		  var row = '<div class="flow-amenity-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">'
		    + '<input type="text" name="flow_amenity_icon[]" placeholder="📶" maxlength="8" style="width:52px;text-align:center;border:1px solid #c3d9d0;border-radius:6px;padding:6px 4px;font-size:16px;box-sizing:border-box;" />'
		    + '<input type="text" name="flow_amenity_label_es[]" placeholder="Wifi gratis" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
		    + '<input type="text" name="flow_amenity_label_en[]" placeholder="Free wifi" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
		    + '<button type="button" onclick="this.closest(\'.flow-amenity-row\').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>'
		    + '</div>';
		  document.getElementById('flow-amenities-wrap').insertAdjacentHTML('beforeend', row);
		});
		</script>
		<?php
	}

	private function get_provider_options( int $current_provider_id ): array {
		global $wpdb;
		if ( $current_provider_id > 0 ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT id, business_name, active FROM {$wpdb->prefix}amir_providers
				 WHERE active = 1 OR id = %d ORDER BY business_name",
				$current_provider_id
			) ) ?? [];
		}
		return $wpdb->get_results(
			"SELECT id, business_name, active FROM {$wpdb->prefix}amir_providers WHERE active = 1 ORDER BY business_name"
		) ?? [];
	}

	public function meta_box_gallery( \WP_Post $post ): void {
		$gallery_ids = get_post_meta( $post->ID, '_flow_gallery_ids', true ) ?: [];
		if ( is_string( $gallery_ids ) ) {
			$gallery_ids = json_decode( $gallery_ids, true ) ?: [];
		}
		wp_enqueue_media();
		?>
		<p style="font-size:12px;color:#666;margin:0 0 10px;">
		  La <strong>imagen destacada</strong> (panel derecho) es la foto principal de la habitación.<br>
		  Aquí agrega las fotos de la galería.
		</p>
		<input type="hidden" name="flow_gallery_ids" id="flow_gallery_ids_input"
		       value="<?php echo esc_attr( json_encode( $gallery_ids ) ); ?>" />

		<div id="flow-room-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
		  <?php foreach ( $gallery_ids as $img_id ) :
			$url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
			if ( $url ) : ?>
			  <div class="flow-room-gallery-item" data-id="<?php echo intval( $img_id ); ?>"
			       style="position:relative;width:80px;height:80px;">
				<img src="<?php echo esc_url( $url ); ?>"
				     style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #c3d9d0;" />
				<button type="button" class="flow-room-gallery-remove"
				        style="position:absolute;top:-6px;right:-6px;background:#e24b4a;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;padding:0;"
				        onclick="flowRoomRemoveGalleryItem(this)">✕</button>
			  </div>
			<?php endif; endforeach; ?>
		</div>

		<button type="button" id="flow-room-gallery-btn"
		        style="background:#1D9E75;color:#fff;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;font-size:13px;font-weight:600;">
		  + <?php _e( 'Agregar fotos a la galería', 'amir-booking' ); ?>
		</button>

		<script>
		(function(){
		  var frame;
		  document.getElementById('flow-room-gallery-btn').addEventListener('click', function(){
		    if (frame) { frame.open(); return; }
		    frame = wp.media({ title: 'Galería de la habitación', multiple: true, library: { type: 'image' } });
		    frame.on('select', function(){
		      var selection = frame.state().get('selection');
		      selection.each(function(attachment){
		        flowRoomAddGalleryItem(attachment.id, attachment.attributes.sizes.thumbnail?.url || attachment.attributes.url);
		      });
		      flowRoomUpdateInput();
		    });
		    frame.open();
		  });

		  window.flowRoomRemoveGalleryItem = function(btn){
		    btn.parentElement.remove();
		    flowRoomUpdateInput();
		  };

		  window.flowRoomAddGalleryItem = function(id, url){
		    var preview = document.getElementById('flow-room-gallery-preview');
		    if (preview.querySelector('[data-id="'+id+'"]')) return;
		    var div = document.createElement('div');
		    div.className = 'flow-room-gallery-item';
		    div.dataset.id = id;
		    div.style.cssText = 'position:relative;width:80px;height:80px;';
		    div.innerHTML = '<img src="'+url+'" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #c3d9d0;">'
		      + '<button type="button" class="flow-room-gallery-remove" onclick="flowRoomRemoveGalleryItem(this)" style="position:absolute;top:-6px;right:-6px;background:#e24b4a;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;padding:0;">✕</button>';
		    preview.appendChild(div);
		  };

		  window.flowRoomUpdateInput = function(){
		    var ids = Array.from(document.querySelectorAll('#flow-room-gallery-preview .flow-room-gallery-item'))
		                   .map(function(el){ return parseInt(el.dataset.id); });
		    document.getElementById('flow_gallery_ids_input').value = JSON.stringify(ids);
		  };
		})();
		</script>
		<?php
	}

	// ── Guardado ──────────────────────────────────────────────────────────

	public function save_meta( int $post_id, \WP_Post $post ): void {
		if (
			! isset( $_POST['flow_room_nonce'] ) ||
			! wp_verify_nonce( $_POST['flow_room_nonce'], 'flow_room_meta' ) ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			! current_user_can( 'manage_options' )
		) {
			return;
		}

		$fields = [
			'_flow_name_en'               => 'sanitize_text_field',
			'_flow_description_en'        => 'sanitize_text_field',
			'_flow_capacity_max'          => 'absint',
			'_flow_min_nights'            => 'absint',
			'_flow_price_per_night'       => 'floatval',
			'_flow_default_checkin_time'  => 'sanitize_text_field',
			'_flow_default_checkout_time' => 'sanitize_text_field',
			'_flow_sort_order'            => 'absint',
			'_flow_provider_id'           => 'absint',
			'_flow_video_url'             => 'esc_url_raw',
		];

		$map = [
			'_flow_name_en'               => 'flow_name_en',
			'_flow_description_en'        => 'flow_description_en',
			'_flow_capacity_max'          => 'flow_capacity_max',
			'_flow_min_nights'            => 'flow_min_nights',
			'_flow_price_per_night'       => 'flow_price_per_night',
			'_flow_default_checkin_time'  => 'flow_default_checkin_time',
			'_flow_default_checkout_time' => 'flow_default_checkout_time',
			'_flow_sort_order'            => 'flow_sort_order',
			'_flow_provider_id'           => 'flow_provider_id',
			'_flow_video_url'             => 'flow_video_url',
		];

		// wp_unslash() antes de sanitizar — mismo bug real que
		// PersonalizationPage::save_settings() (2026-08-05).
		foreach ( $map as $meta_key => $post_key ) {
			$sanitizer = $fields[ $meta_key ] ?? 'sanitize_text_field';
			$value     = call_user_func( $sanitizer, wp_unslash( $_POST[ $post_key ] ?? '' ) );
			update_post_meta( $post_id, $meta_key, $value );
		}

		$gallery_ids = json_decode( sanitize_text_field( $_POST['flow_gallery_ids'] ?? '[]' ), true );
		update_post_meta( $post_id, '_flow_gallery_ids', json_encode( array_map( 'intval', $gallery_ids ?: [] ) ) );

		$this->save_amenities( $post_id );
	}

	/** Filas repetibles de amenities (ícono + label ES/EN) → JSON, mismo patrón que save_itinerary_stops()/save_detail_facts() de tours. */
	private function save_amenities( int $post_id ): void {
		// wp_unslash() antes de sanitizar cada elemento — mismo bug real
		// que el resto del plugin (2026-08-05).
		$icons     = wp_unslash( $_POST['flow_amenity_icon']     ?? [] );
		$labels_es = wp_unslash( $_POST['flow_amenity_label_es'] ?? [] );
		$labels_en = wp_unslash( $_POST['flow_amenity_label_en'] ?? [] );

		$amenities = [];
		foreach ( $labels_es as $i => $raw_label_es ) {
			$label_es = sanitize_text_field( $raw_label_es );
			$label_en = sanitize_text_field( $labels_en[ $i ] ?? '' );
			if ( $label_es === '' && $label_en === '' ) {
				continue;
			}
			$amenities[] = [
				'icon'     => sanitize_text_field( $icons[ $i ] ?? '' ),
				'label_es' => $label_es,
				'label_en' => $label_en,
			];
		}
		// JSON_UNESCAPED_UNICODE — mismo bug real que amir_tours (§ 16.51
		// CONTRIBUTING.md): un emoji/tilde sin este flag se guarda escapado
		// a \uXXXX y se corrompe más adelante en la cadena de guardado.
		update_post_meta( $post_id, '_flow_amenities', wp_json_encode( $amenities, JSON_UNESCAPED_UNICODE ) );
	}

	public function sync_to_db( int $post_id, \WP_Post $post ): void {
		if ( $post->post_status === 'auto-draft' ) {
			return;
		}

		global $wpdb;

		$db_id = (int) get_post_meta( $post_id, '_flow_room_db_id', true );

		$gallery_ids  = json_decode( get_post_meta( $post_id, '_flow_gallery_ids', true ) ?: '[]', true );
		$gallery_urls = array_filter( array_map( fn( $id ) => wp_get_attachment_url( $id ), $gallery_ids ) );

		$thumb_id  = get_post_thumbnail_id( $post_id );
		$thumb_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
		if ( $thumb_url ) {
			array_unshift( $gallery_urls, $thumb_url );
		}

		$data = [
			'slug'                  => $post->post_name ?: sanitize_title( $post->post_title ),
			'status'                => $post->post_status === 'publish' ? 'active' : 'draft',
			'name_es'               => $post->post_title,
			'name_en'               => get_post_meta( $post_id, '_flow_name_en', true ) ?: $post->post_title,
			'description_es'        => wp_strip_all_tags( $post->post_content ),
			'description_en'        => wp_strip_all_tags( get_post_meta( $post_id, '_flow_description_en', true ) ?: '' ),
			'capacity_max'          => (int) get_post_meta( $post_id, '_flow_capacity_max', true ) ?: 2,
			'min_nights'            => (int) get_post_meta( $post_id, '_flow_min_nights', true ) ?: 1,
			'price_per_night'       => (float) get_post_meta( $post_id, '_flow_price_per_night', true ),
			'default_checkin_time'  => get_post_meta( $post_id, '_flow_default_checkin_time', true ) ?: '15:00:00',
			'default_checkout_time' => get_post_meta( $post_id, '_flow_default_checkout_time', true ) ?: '11:00:00',
			'gallery_images'        => json_encode( array_values( $gallery_urls ) ),
			'amenities'             => get_post_meta( $post_id, '_flow_amenities', true ) ?: '[]',
			'video_url'             => get_post_meta( $post_id, '_flow_video_url', true ) ?: null,
			'sort_order'            => (int) get_post_meta( $post_id, '_flow_sort_order', true ),
			'provider_id'           => ( (int) get_post_meta( $post_id, '_flow_provider_id', true ) ) ?: null,
		];

		if ( $db_id ) {
			$result = $wpdb->update( "{$wpdb->prefix}flow_rooms", $data, [ 'id' => $db_id ] );
		} else {
			$result = $wpdb->insert( "{$wpdb->prefix}flow_rooms", $data );
			$db_id  = $wpdb->insert_id;
			update_post_meta( $post_id, '_flow_room_db_id', $db_id );
		}

		if ( $result === false && $wpdb->last_error ) {
			set_transient( "flow_room_sync_error_{$post_id}", $wpdb->last_error, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( "flow_room_sync_error_{$post_id}" );
		}
	}

	public function delete_from_db( int $post_id ): void {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		$db_id = (int) get_post_meta( $post_id, '_flow_room_db_id', true );
		if ( $db_id ) {
			global $wpdb;
			$wpdb->update( "{$wpdb->prefix}flow_rooms", [ 'status' => 'archived' ], [ 'id' => $db_id ] );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function get_meta( int $post_id ): array {
		$get = fn( $k ) => get_post_meta( $post_id, "_flow_{$k}", true ) ?: '';
		return [
			'name_en'               => $get( 'name_en' ),
			'description_en'       => $get( 'description_en' ),
			'capacity_max'          => $get( 'capacity_max' ) ?: '2',
			'min_nights'            => $get( 'min_nights' ) ?: '1',
			'price_per_night'       => $get( 'price_per_night' ) ?: '',
			'default_checkin_time'  => $get( 'default_checkin_time' ) ?: '15:00',
			'default_checkout_time' => $get( 'default_checkout_time' ) ?: '11:00',
			'sort_order'            => $get( 'sort_order' ) ?: '0',
			'provider_id'           => $get( 'provider_id' ),
			'video_url'             => $get( 'video_url' ),
		];
	}
}
