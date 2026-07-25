<?php
namespace AmirBooking\CPT;

defined( 'ABSPATH' ) || exit;

/**
 * Registra el Custom Post Type 'amir_tour'.
 *
 * - Aparece en Elementor Loop Builder / Query Loop nativo
 * - Expuesto en REST API para Elementor y bloques de Gutenberg
 * - Imágenes: usa la imagen destacada de WP como foto principal
 *   y una galería custom como meta field
 * - Soporta: title, editor, thumbnail, revisions, custom-fields
 */
class TourPostType {

    public const POST_TYPE = 'amir_tour';
    public const TAXONOMY  = 'amir_tour_category';

    public function register(): void {
        add_action( 'init',                    [ $this, 'register_post_type' ] );
        add_action( 'init',                    [ $this, 'register_taxonomy'  ] );
        add_action( 'add_meta_boxes',          [ $this, 'add_meta_boxes'     ] );
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta'   ], 10, 2 );
        add_action( 'rest_api_init',           [ $this, 'register_rest_fields' ] );
        add_filter( 'enter_title_here',        [ $this, 'title_placeholder' ] );

        // Sincronizar con tabla amir_tours al guardar/eliminar
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'sync_to_db'   ], 20, 2 );
        add_action( 'before_delete_post',      [ $this, 'delete_from_db'    ] );
    }

    // ── CPT ───────────────────────────────────────────────────────────────

    public function register_post_type(): void {
        $labels = [
            'name'               => __( 'Tours',              'amir-booking' ),
            'singular_name'      => __( 'Tour',               'amir-booking' ),
            'add_new'            => __( 'Agregar tour',        'amir-booking' ),
            'add_new_item'       => __( 'Nuevo tour',          'amir-booking' ),
            'edit_item'          => __( 'Editar tour',         'amir-booking' ),
            'view_item'          => __( 'Ver tour',            'amir-booking' ),
            'search_items'       => __( 'Buscar tours',        'amir-booking' ),
            'not_found'          => __( 'No se encontraron tours', 'amir-booking' ),
            'menu_name'          => __( 'Tours',               'amir-booking' ),
        ];

        register_post_type( self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false,   // Lo ponemos en nuestro menú admin
            'show_in_nav_menus'   => true,
            'show_in_rest'        => true,    // ← Elementor + Gutenberg + REST
            'rest_base'           => 'amir-tours',
            'has_archive'         => 'nuestros-tours', // /nuestros-tours/ — evita conflicto con WC shop /tours/
            'rewrite'             => [ 'slug' => 'tour', 'with_front' => false ],
            'menu_icon'           => 'dashicons-palmtree',
            'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields', 'page-attributes' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'query_var'           => true,
            'taxonomies'          => [ self::TAXONOMY ],
        ] );
    }

    // ── Taxonomía ─────────────────────────────────────────────────────────

    public function register_taxonomy(): void {
        register_taxonomy( self::TAXONOMY, self::POST_TYPE, [
            'labels'            => [
                'name'          => __( 'Categorías de tour', 'amir-booking' ),
                'singular_name' => __( 'Categoría',          'amir-booking' ),
            ],
            'public'            => true,
            'show_in_rest'      => true,
            'hierarchical'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'tipo-de-tour' ],
        ] );
    }

    // ── Meta Boxes ────────────────────────────────────────────────────────

    public function add_meta_boxes(): void {
        $boxes = [
            [ 'amir_tour_main',    __( '⚙ Configuración del tour',    'amir-booking' ), [ $this, 'meta_box_main'      ] ],
            [ 'amir_tour_content', __( '📝 Contenido bilingüe (EN)',   'amir-booking' ), [ $this, 'meta_box_content'   ] ],
            [ 'amir_tour_gallery', __( '🖼 Galería de fotos',          'amir-booking' ), [ $this, 'meta_box_gallery'   ] ],
            [ 'amir_tour_pricing', __( '💰 Precios y horarios',        'amir-booking' ), [ $this, 'meta_box_pricing'   ] ],
            [ 'amir_tour_avail',   __( '📅 Disponibilidad',            'amir-booking' ), [ $this, 'meta_box_avail'     ] ],
            [ 'amir_tour_meeting', __( '📍 Punto de encuentro',        'amir-booking' ), [ $this, 'meta_box_meeting'   ] ],
            [ 'amir_tour_integr',  __( '🔗 Integraciones externas',    'amir-booking' ), [ $this, 'meta_box_integr'    ] ],
        ];

        foreach ( $boxes as [ $id, $title, $cb ] ) {
            add_meta_box( $id, $title, $cb, self::POST_TYPE, 'normal', 'high' );
        }
    }

    // ── Meta Box: Configuración principal ────────────────────────────────

    public function meta_box_main( \WP_Post $post ): void {
        wp_nonce_field( 'amir_tour_meta', 'amir_tour_nonce' );
        $m = $this->get_meta( $post->ID );
        ?>
        <style>
          .amir-meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:8px; }
          .amir-meta-grid-3 { grid-template-columns:1fr 1fr 1fr; }
          .amir-field label { display:block; font-weight:600; font-size:12px; color:#1a2e24; margin-bottom:4px; text-transform:uppercase; letter-spacing:.3px; }
          .amir-field input, .amir-field select, .amir-field textarea { width:100%; border:1px solid #c3d9d0; border-radius:6px; padding:7px 10px; font-size:13px; box-sizing:border-box; }
          .amir-field input:focus, .amir-field select:focus { outline:none; border-color:#1D9E75; box-shadow:0 0 0 2px rgba(29,158,117,.15); }
          .amir-section-title { font-size:13px; font-weight:700; color:#1D9E75; margin:16px 0 10px; border-bottom:1px solid #e1f5ee; padding-bottom:6px; }
          .amir-hint { font-size:11px; color:#888; margin-top:3px; }
        </style>

        <div class="amir-meta-grid">
          <div class="amir-field">
            <label><?php _e('Nombre en inglés (EN)', 'amir-booking'); ?></label>
            <input type="text" name="amir_name_en" value="<?php echo esc_attr($m['name_en']); ?>" placeholder="Tour name in English" />
          </div>
          <div class="amir-field">
            <label><?php _e('Modelo de precio', 'amir-booking'); ?></label>
            <select name="amir_price_model">
              <option value="percapita" <?php selected($m['price_model'],'percapita'); ?>>Por persona (adulto / niño / bebé)</option>
              <option value="group"     <?php selected($m['price_model'],'group');     ?>>Precio fijo por grupo (privado)</option>
            </select>
          </div>
        </div>

        <div class="amir-meta-grid amir-meta-grid-3">
          <div class="amir-field">
            <label><?php _e('Duración (minutos)', 'amir-booking'); ?></label>
            <input type="number" name="amir_duration_minutes" value="<?php echo esc_attr($m['duration_minutes']); ?>" min="0" placeholder="120" />
          </div>
          <div class="amir-field">
            <label><?php _e('Edad mínima', 'amir-booking'); ?></label>
            <input type="number" name="amir_min_age" value="<?php echo esc_attr($m['min_age']); ?>" min="0" placeholder="0" />
          </div>
          <div class="amir-field">
            <label><?php _e('Capacidad máxima', 'amir-booking'); ?></label>
            <input type="number" name="amir_max_capacity" value="<?php echo esc_attr($m['max_capacity']); ?>" min="1" placeholder="10" />
          </div>
        </div>

        <div class="amir-meta-grid amir-meta-grid-3">
          <div class="amir-field">
            <label><?php _e('Mín. pasajeros p/operar', 'amir-booking'); ?></label>
            <input type="number" name="amir_min_passengers" value="<?php echo esc_attr($m['min_passengers']); ?>" min="1" placeholder="2" />
          </div>
          <div class="amir-field">
            <label><?php _e('Idiomas disponibles', 'amir-booking'); ?></label>
            <input type="text" name="amir_languages" value="<?php echo esc_attr($m['languages_str']); ?>" placeholder="Español, English" />
            <p class="amir-hint">Separados por coma</p>
          </div>
          <div class="amir-field">
            <label><?php _e('Orden de listado', 'amir-booking'); ?></label>
            <input type="number" name="amir_sort_order" value="<?php echo esc_attr($m['sort_order']); ?>" min="0" placeholder="0" />
          </div>
        </div>

        <div class="amir-section-title">📋 Lista de interés ("Próximamente")</div>
        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          Mientras este tour esté en <strong>borrador</strong>, se puede mostrar en la sección "Próximamente" del sitio
          para que la gente se anote — completando fecha, personas y datos como una reserva normal, pero sin pagar todavía.
          Al publicar el tour (o usar "Amir Booking → Lista de interés"), cada anotado recibe un email con el link para pagar.
        </p>
        <div class="amir-meta-grid">
          <div class="amir-field">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
              <input type="checkbox" name="amir_wishlist_enabled" value="1" <?php checked( $m['wishlist_enabled'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
              <?php _e('Activar lista de interés para este tour', 'amir-booking'); ?>
            </label>
          </div>
          <div class="amir-field">
            <label><?php _e('Umbral para avisar al admin', 'amir-booking'); ?></label>
            <input type="number" name="amir_wishlist_threshold" value="<?php echo esc_attr($m['wishlist_threshold']); ?>" min="0" placeholder="Ej: 10" />
            <p class="amir-hint">0 = sin umbral, solo acumula interesados</p>
          </div>
          <div class="amir-field">
            <label><?php _e('Fecha del tour/retiro', 'amir-booking'); ?></label>
            <input type="date" name="amir_wishlist_date" value="<?php echo esc_attr($m['wishlist_date']); ?>" />
            <p class="amir-hint">La fecha ya definida a la que la gente muestra interés — no hay calendario de disponibilidad mientras el tour está en borrador</p>
          </div>
        </div>
        <?php
    }

    // ── Meta Box: Contenido EN ────────────────────────────────────────────

    public function meta_box_content( \WP_Post $post ): void {
        $m = $this->get_meta( $post->ID );
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          El <strong>título y editor principal</strong> de WordPress son el nombre y descripción en <strong>Español</strong>.<br>
          Aquí completa los campos adicionales bilingües.
        </p>

        <!-- Qué esperar: ES + EN -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Qué esperar (ES)</label>
            <textarea name="amir_what_to_expect_es" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['what_to_expect_es']); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">What to expect (EN)</label>
            <textarea name="amir_what_to_expect_en" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['what_to_expect_en']); ?></textarea>
          </div>
        </div>

        <!-- Nombre EN (también en meta_box_main, repetido aquí como referencia) -->
        <div class="amir-field" style="margin-bottom:14px;">
          <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Tour name (EN)</label>
          <input type="text" name="amir_name_en" value="<?php echo esc_attr($m['name_en']); ?>" placeholder="Tour name in English"
                 style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;" />
        </div>

        <!-- Incluye / No incluye -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Incluye (ES) — una por línea</label>
            <textarea name="amir_includes_es" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['includes_es']??'[]',true)) ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Includes (EN) — one per line</label>
            <textarea name="amir_includes_en" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['includes_en']??'[]',true)) ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">No incluye (ES)</label>
            <textarea name="amir_excludes_es" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['excludes_es']??'[]',true)) ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Not included (EN)</label>
            <textarea name="amir_excludes_en" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['excludes_en']??'[]',true)) ); ?></textarea>
          </div>
        </div>

        <!-- Itinerario -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Itinerario (ES) — opcional</label>
            <textarea name="amir_itinerary_es" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['itinerary_es']??''); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Itinerary (EN) — optional</label>
            <textarea name="amir_itinerary_en" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['itinerary_en']??''); ?></textarea>
          </div>
        </div>
        <?php
    }

    // ── Meta Box: Galería ─────────────────────────────────────────────────

    public function meta_box_gallery( \WP_Post $post ): void {
        $gallery_ids = get_post_meta( $post->ID, '_amir_gallery_ids', true ) ?: [];
        if ( is_string($gallery_ids) ) {
            $gallery_ids = json_decode( $gallery_ids, true ) ?: [];
        }
        wp_enqueue_media();
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 10px;">
          La <strong>imagen destacada</strong> (panel derecho) es la foto principal del tour.<br>
          Aquí agrega las fotos de la galería.
        </p>
        <input type="hidden" name="amir_gallery_ids" id="amir_gallery_ids_input"
               value="<?php echo esc_attr( json_encode($gallery_ids) ); ?>" />

        <div id="amir-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
          <?php foreach ( $gallery_ids as $img_id ) :
            $url = wp_get_attachment_image_url( $img_id, 'thumbnail' );
            if ( $url ) : ?>
              <div class="amir-gallery-item" data-id="<?php echo intval($img_id); ?>"
                   style="position:relative;width:80px;height:80px;cursor:move;">
                <img src="<?php echo esc_url($url); ?>"
                     style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #c3d9d0;" />
                <button type="button" class="amir-gallery-remove"
                        style="position:absolute;top:-6px;right:-6px;background:#e24b4a;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;padding:0;"
                        onclick="amirRemoveGalleryItem(this)">✕</button>
              </div>
            <?php endif; endforeach; ?>
        </div>

        <button type="button" id="amir-gallery-btn"
                style="background:#1D9E75;color:#fff;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;font-size:13px;font-weight:600;">
          + <?php _e('Agregar fotos a la galería', 'amir-booking'); ?>
        </button>

        <script>
        (function(){
          var frame;
          document.getElementById('amir-gallery-btn').addEventListener('click', function(){
            if (frame) { frame.open(); return; }
            frame = wp.media({ title: 'Galería del tour', multiple: true, library: { type: 'image' } });
            frame.on('select', function(){
              var selection = frame.state().get('selection');
              selection.each(function(attachment){
                amirAddGalleryItem(attachment.id, attachment.attributes.sizes.thumbnail?.url || attachment.attributes.url);
              });
              amirUpdateInput();
            });
            frame.open();
          });

          window.amirRemoveGalleryItem = function(btn){
            btn.parentElement.remove();
            amirUpdateInput();
          };

          window.amirAddGalleryItem = function(id, url){
            var preview = document.getElementById('amir-gallery-preview');
            // Evitar duplicados
            if (preview.querySelector('[data-id="'+id+'"]')) return;
            var div = document.createElement('div');
            div.className = 'amir-gallery-item';
            div.dataset.id = id;
            div.style.cssText = 'position:relative;width:80px;height:80px;cursor:move;';
            div.innerHTML = '<img src="'+url+'" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #c3d9d0;">'
              + '<button type="button" class="amir-gallery-remove" onclick="amirRemoveGalleryItem(this)" style="position:absolute;top:-6px;right:-6px;background:#e24b4a;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;padding:0;">✕</button>';
            preview.appendChild(div);
          };

          window.amirUpdateInput = function(){
            var ids = Array.from(document.querySelectorAll('#amir-gallery-preview .amir-gallery-item'))
                           .map(function(el){ return parseInt(el.dataset.id); });
            document.getElementById('amir_gallery_ids_input').value = JSON.stringify(ids);
          };
        })();
        </script>
        <?php
    }

    // ── Meta Box: Precios ─────────────────────────────────────────────────

    public function meta_box_pricing( \WP_Post $post ): void {
        global $wpdb;
        $tour_db_id = (int) get_post_meta( $post->ID, '_amir_tour_db_id', true );

        $schedules = $tour_db_id
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_tour_schedules WHERE tour_id=%d ORDER BY sort_order,time_start",
                $tour_db_id ) )
            : [];

        $prices = $tour_db_id
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_prices WHERE tour_id=%d",
                $tour_db_id ) )
            : [];

        $price_model = get_post_meta( $post->ID, '_amir_price_model', true ) ?: 'percapita';
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 14px;">
          Configura los horarios y precios. Se guardan al publicar/actualizar el tour.
        </p>

        <!-- Horarios -->
        <div style="font-weight:700;font-size:13px;color:#1D9E75;margin-bottom:8px;">🕐 Horarios</div>
        <div id="amir-schedules-wrap">
          <?php if ( empty($schedules) ) : ?>
            <div class="amir-schedule-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
              <input type="time" name="amir_schedule_start[]" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <span style="font-size:12px;color:#666;">a</span>
              <input type="time" name="amir_schedule_end[]"   style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_es[]" placeholder="Ej: Salida amanecer" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_en[]" placeholder="Ex: Sunrise departure" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
            </div>
          <?php else : foreach ( $schedules as $s ) : ?>
            <div class="amir-schedule-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
              <input type="hidden" name="amir_schedule_db_id[]" value="<?php echo $s->id; ?>" />
              <input type="time" name="amir_schedule_start[]"    value="<?php echo esc_attr($s->time_start); ?>" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <span style="font-size:12px;color:#666;">a</span>
              <input type="time" name="amir_schedule_end[]"      value="<?php echo esc_attr($s->time_end); ?>"   style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_es[]" value="<?php echo esc_attr($s->label_es); ?>" placeholder="Salida amanecer" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_en[]" value="<?php echo esc_attr($s->label_en); ?>" placeholder="Sunrise departure" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <button type="button" onclick="this.closest('.amir-schedule-row').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <button type="button" id="amir-add-schedule-btn"
                style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;margin-bottom:18px;">
          + <?php _e('Agregar horario', 'amir-booking'); ?>
        </button>

        <!-- Precios per-capita -->
        <div id="amir-prices-percapita" style="<?php echo $price_model === 'group' ? 'display:none' : ''; ?>">
          <div style="font-weight:700;font-size:13px;color:#1D9E75;margin-bottom:8px;">💲 Precios por persona (<?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?>)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            <?php
            $types = [ 'adult' => 'Adulto (13+)', 'child' => 'Niño (4–12)', 'baby' => 'Bebé (0–3) — 0 = gratis' ];
            foreach ( $types as $type => $label ) :
              $price_row = array_filter( $prices, fn($p) => $p->person_type === $type && ! $p->schedule_id );
              $price_val = $price_row ? (float)reset($price_row)->price_mxn : '';
            ?>
              <div class="amir-field" style="margin-bottom:0;">
                <label style="font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;color:#444;">
                  <?php echo esc_html($label); ?>
                </label>
                <div style="position:relative;">
                  <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:13px;color:#888;">$</span>
                  <input type="number" name="amir_price_<?php echo $type; ?>" value="<?php echo esc_attr($price_val); ?>"
                         min="0" step="0.01" placeholder="0.00"
                         style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 8px 7px 20px;font-size:13px;box-sizing:border-box;" />
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Precios grupo -->
        <div id="amir-prices-group" style="<?php echo $price_model === 'percapita' ? 'display:none' : ''; ?>">
          <div style="font-weight:700;font-size:13px;color:#1D9E75;margin-bottom:8px;">💲 Precios por grupo (<?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?>)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            <?php
            $group_ranges = [ [1,2,'1–2 personas'], [3,3,'3 personas'], [4,4,'4 personas (máx.)'] ];
            foreach ( $group_ranges as [ $gmin, $gmax, $glabel ] ) :
              $gp = array_filter( $prices, fn($p) => $p->person_type === 'group' && (int)$p->group_min === $gmin );
              $gv = $gp ? (float)reset($gp)->price_mxn : '';
            ?>
              <div class="amir-field" style="margin-bottom:0;">
                <label style="font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;color:#444;">
                  <?php echo esc_html($glabel); ?>
                </label>
                <div style="position:relative;">
                  <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:13px;color:#888;">$</span>
                  <input type="number" name="amir_price_group_<?php echo $gmin; ?>_<?php echo $gmax; ?>" value="<?php echo esc_attr($gv); ?>"
                         min="0" step="0.01" placeholder="0.00"
                         style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 8px 7px 20px;font-size:13px;box-sizing:border-box;" />
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <script>
        document.querySelector('[name="amir_price_model"]')?.addEventListener('change', function(){
          document.getElementById('amir-prices-percapita').style.display = this.value === 'percapita' ? '' : 'none';
          document.getElementById('amir-prices-group').style.display     = this.value === 'group'     ? '' : 'none';
        });

        document.getElementById('amir-add-schedule-btn').addEventListener('click', function(){
          var row = '<div class="amir-schedule-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">'
            + '<input type="time" name="amir_schedule_start[]" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />'
            + '<span style="font-size:12px;color:#666;">a</span>'
            + '<input type="time" name="amir_schedule_end[]" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />'
            + '<input type="text" name="amir_schedule_label_es[]" placeholder="Etiqueta ES" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />'
            + '<input type="text" name="amir_schedule_label_en[]" placeholder="Label EN" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />'
            + '<button type="button" onclick="this.closest(\'.amir-schedule-row\').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>'
            + '</div>';
          document.getElementById('amir-schedules-wrap').insertAdjacentHTML('beforeend', row);
        });
        </script>
        <?php
    }

    // ── Meta Box: Disponibilidad ──────────────────────────────────────────

    public function meta_box_avail( \WP_Post $post ): void {
        $m = $this->get_meta( $post->ID );
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          Define qué días opera este tour. Las <strong>excepciones</strong> sobreescriben la regla base según su prioridad.
        </p>
        <div class="amir-field" style="margin-bottom:14px;">
          <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:6px;display:block;">
            <?php _e('Días operativos por defecto', 'amir-booking'); ?>
          </label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php
            $days = ['Do','Lu','Ma','Mi','Ju','Vi','Sá'];
            $active_days = json_decode( $m['active_weekdays'] ?? '[1,2,3,4,5,6]', true );
            foreach ( $days as $i => $d ) : ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="amir_active_weekdays[]" value="<?php echo $i; ?>"
                       <?php checked( in_array($i, $active_days, false) ); ?>
                       style="accent-color:#1D9E75;" />
                <?php echo $d; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <p style="font-size:11px;color:#888;margin-top:6px;">Las reglas de excepción se gestionan desde <strong>Amir Booking → Disponibilidad</strong></p>
        </div>
        <?php
    }

    // ── Meta Box: Punto de encuentro ──────────────────────────────────────

    public function meta_box_meeting( \WP_Post $post ): void {
        $m = $this->get_meta( $post->ID );
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Punto de encuentro (ES)</label>
            <textarea name="amir_meeting_point_es" rows="2" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['meeting_point_es']); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Meeting point (EN)</label>
            <textarea name="amir_meeting_point_en" rows="2" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['meeting_point_en']); ?></textarea>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Latitud</label>
            <input type="text" name="amir_meeting_lat" value="<?php echo esc_attr($m['meeting_lat']); ?>" placeholder="18.6849" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;" />
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">Longitud</label>
            <input type="text" name="amir_meeting_lng" value="<?php echo esc_attr($m['meeting_lng']); ?>" placeholder="-87.9789" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;" />
          </div>
        </div>
        <p style="font-size:11px;color:#888;margin-top:8px;">💡 Para obtener coordenadas: abre Google Maps → clic derecho en la ubicación → copiar lat,lng</p>
        <?php
    }

    // ── Meta Box: Integraciones ───────────────────────────────────────────

    public function meta_box_integr( \WP_Post $post ): void {
        $m = $this->get_meta( $post->ID );
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">TripAdvisor ID</label>
            <input type="text" name="amir_tripadvisor_id" value="<?php echo esc_attr($m['tripadvisor_id']); ?>" placeholder="d12345678" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;" />
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;">GetYourGuide ID</label>
            <input type="text" name="amir_gyg_id" value="<?php echo esc_attr($m['gyg_id']); ?>" placeholder="activity-123456" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;" />
          </div>
        </div>
        <?php
    }

    // ── Save meta ─────────────────────────────────────────────────────────

    public function save_meta( int $post_id, \WP_Post $post ): void {
        if (
            ! isset( $_POST['amir_tour_nonce'] ) ||
            ! wp_verify_nonce( $_POST['amir_tour_nonce'], 'amir_tour_meta' ) ||
            defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
            ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_amir_tour', $post_id ) )
        ) {
            return;
        }

        $fields = [
            '_amir_name_en'          => 'sanitize_text_field',
            '_amir_price_model'      => 'sanitize_key',
            '_amir_duration_minutes' => 'absint',
            '_amir_min_age'          => 'absint',
            '_amir_max_capacity'     => 'absint',
            '_amir_min_passengers'   => 'absint',
            '_amir_sort_order'       => 'absint',
            '_amir_languages'        => 'sanitize_text_field',
            '_amir_description_en'   => 'wp_kses_post',
            '_amir_what_to_expect_es'=> 'wp_kses_post',
            '_amir_what_to_expect_en'=> 'wp_kses_post',
            '_amir_itinerary_es'     => 'wp_kses_post',
            '_amir_itinerary_en'     => 'wp_kses_post',
            '_amir_meeting_point_es' => 'sanitize_textarea_field',
            '_amir_meeting_point_en' => 'sanitize_textarea_field',
            '_amir_meeting_lat'      => 'sanitize_text_field',
            '_amir_meeting_lng'      => 'sanitize_text_field',
            '_amir_tripadvisor_id'   => 'sanitize_text_field',
            '_amir_gyg_id'           => 'sanitize_text_field',
            '_amir_wishlist_threshold' => 'absint',
            '_amir_wishlist_date'      => 'sanitize_text_field',
        ];

        $map = [
            '_amir_name_en'           => 'amir_name_en',
            '_amir_price_model'       => 'amir_price_model',
            '_amir_duration_minutes'  => 'amir_duration_minutes',
            '_amir_min_age'           => 'amir_min_age',
            '_amir_max_capacity'      => 'amir_max_capacity',
            '_amir_min_passengers'    => 'amir_min_passengers',
            '_amir_sort_order'        => 'amir_sort_order',
            '_amir_languages'         => 'amir_languages',
            '_amir_description_en'    => 'amir_description_en',
            '_amir_what_to_expect_es' => 'amir_what_to_expect_es',
            '_amir_what_to_expect_en' => 'amir_what_to_expect_en',
            '_amir_itinerary_es'      => 'amir_itinerary_es',
            '_amir_itinerary_en'      => 'amir_itinerary_en',
            '_amir_meeting_point_es'  => 'amir_meeting_point_es',
            '_amir_meeting_point_en'  => 'amir_meeting_point_en',
            '_amir_meeting_lat'       => 'amir_meeting_lat',
            '_amir_meeting_lng'       => 'amir_meeting_lng',
            '_amir_tripadvisor_id'    => 'amir_tripadvisor_id',
            '_amir_gyg_id'            => 'amir_gyg_id',
            '_amir_wishlist_threshold' => 'amir_wishlist_threshold',
            '_amir_wishlist_date'      => 'amir_wishlist_date',
        ];

        foreach ( $map as $meta_key => $post_key ) {
            $sanitizer = $fields[ $meta_key ] ?? 'sanitize_text_field';
            $value     = call_user_func( $sanitizer, $_POST[ $post_key ] ?? '' );
            update_post_meta( $post_id, $meta_key, $value );
        }

        // Checkbox: ausente en $_POST cuando está destildado
        update_post_meta( $post_id, '_amir_wishlist_enabled', ! empty( $_POST['amir_wishlist_enabled'] ) ? '1' : '0' );

        // Activos/days de la semana
        $weekdays = array_map( 'intval', $_POST['amir_active_weekdays'] ?? [] );
        update_post_meta( $post_id, '_amir_active_weekdays', json_encode( $weekdays ) );

        // Galería
        $gallery_ids = json_decode( sanitize_text_field( $_POST['amir_gallery_ids'] ?? '[]' ), true );
        update_post_meta( $post_id, '_amir_gallery_ids', json_encode( array_map('intval', $gallery_ids ?: []) ) );

        // Includes / excludes (textarea → JSON array)
        foreach ( [ 'includes_es','includes_en','excludes_es','excludes_en' ] as $field ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $_POST["amir_{$field}"] ?? '' ) ) );
            update_post_meta( $post_id, "_amir_{$field}", json_encode( array_values($lines) ) );
        }
    }

    // ── Sincronización CPT → tabla amir_tours ────────────────────────────

    public function sync_to_db( int $post_id, \WP_Post $post ): void {
        if ( $post->post_status === 'auto-draft' ) {
            return;
        }

        global $wpdb;

        $db_id = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );

        $langs_str  = get_post_meta( $post_id, '_amir_languages', true ) ?: '';
        $langs_arr  = array_filter( array_map( 'trim', explode( ',', $langs_str ) ) );
        $gallery_ids= json_decode( get_post_meta( $post_id, '_amir_gallery_ids', true ) ?: '[]', true );
        $gallery_urls = array_filter( array_map( fn($id) => wp_get_attachment_url($id), $gallery_ids ) );

        // Foto principal (featured image) al inicio de la galería
        $thumb_id  = get_post_thumbnail_id( $post_id );
        $thumb_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
        if ( $thumb_url ) {
            array_unshift( $gallery_urls, $thumb_url );
        }

        $data = [
            'slug'               => $post->post_name,
            'status'             => $post->post_status === 'publish' ? 'active' : 'draft',
            'price_model'        => get_post_meta( $post_id, '_amir_price_model', true ) ?: 'percapita',
            'name_es'            => $post->post_title,
            'name_en'            => get_post_meta( $post_id, '_amir_name_en', true ) ?: $post->post_title,
            'description_es'     => wp_strip_all_tags( $post->post_content ),
            'description_en'     => wp_strip_all_tags( get_post_meta( $post_id, '_amir_description_en', true ) ?: '' ),
            'what_to_expect_es'  => get_post_meta( $post_id, '_amir_what_to_expect_es', true ) ?: '',
            'what_to_expect_en'  => get_post_meta( $post_id, '_amir_what_to_expect_en', true ) ?: '',
            'duration_minutes'   => (int) get_post_meta( $post_id, '_amir_duration_minutes', true ),
            'min_age'            => (int) get_post_meta( $post_id, '_amir_min_age', true ),
            'max_capacity'       => (int) get_post_meta( $post_id, '_amir_max_capacity', true ),
            'min_passengers'     => (int) get_post_meta( $post_id, '_amir_min_passengers', true ) ?: 1,
            'languages'          => json_encode( array_values($langs_arr) ),
            'meeting_point_es'   => get_post_meta( $post_id, '_amir_meeting_point_es', true ) ?: '',
            'meeting_point_en'   => get_post_meta( $post_id, '_amir_meeting_point_en', true ) ?: '',
            'meeting_lat'        => get_post_meta( $post_id, '_amir_meeting_lat', true ) ?: null,
            'meeting_lng'        => get_post_meta( $post_id, '_amir_meeting_lng', true ) ?: null,
            'includes_es'        => get_post_meta( $post_id, '_amir_includes_es', true ) ?: '[]',
            'includes_en'        => get_post_meta( $post_id, '_amir_includes_en', true ) ?: '[]',
            'excludes_es'        => get_post_meta( $post_id, '_amir_excludes_es', true ) ?: '[]',
            'excludes_en'        => get_post_meta( $post_id, '_amir_excludes_en', true ) ?: '[]',
            'gallery_images'     => json_encode( array_values($gallery_urls) ),
            'tripadvisor_id'     => get_post_meta( $post_id, '_amir_tripadvisor_id', true ) ?: '',
            'gyg_id'             => get_post_meta( $post_id, '_amir_gyg_id', true ) ?: '',
            'sort_order'         => (int) get_post_meta( $post_id, '_amir_sort_order', true ),
            'wishlist_enabled'   => (int) get_post_meta( $post_id, '_amir_wishlist_enabled', true ),
            'wishlist_threshold' => (int) get_post_meta( $post_id, '_amir_wishlist_threshold', true ),
            'wishlist_date'      => get_post_meta( $post_id, '_amir_wishlist_date', true ) ?: null,
        ];

        if ( $db_id ) {
            $wpdb->update( "{$wpdb->prefix}amir_tours", $data, [ 'id' => $db_id ] );
        } else {
            $wpdb->insert( "{$wpdb->prefix}amir_tours", $data );
            $db_id = $wpdb->insert_id;
            update_post_meta( $post_id, '_amir_tour_db_id', $db_id );
        }

        // Sincronizar horarios y precios desde los meta boxes
        $this->sync_schedules_prices( $post_id, $db_id );

        // Sincronizar regla base de disponibilidad (días activos)
        $this->sync_base_availability_rule( $post_id, $db_id );

        // Invalidar caches
        delete_transient( "amir_tour_{$db_id}_es" );
        delete_transient( "amir_tour_{$db_id}_en" );
        delete_transient( 'amir_tours_list_es' );
        delete_transient( 'amir_tours_list_en' );
    }

    private function sync_schedules_prices( int $post_id, int $tour_db_id ): void {
        global $wpdb;

        $starts      = $_POST['amir_schedule_start']    ?? [];
        $ends        = $_POST['amir_schedule_end']      ?? [];
        $labels_es   = $_POST['amir_schedule_label_es'] ?? [];
        $labels_en   = $_POST['amir_schedule_label_en'] ?? [];
        $db_ids      = $_POST['amir_schedule_db_id']    ?? [];

        // Mantener los IDs procesados para no eliminarlos
        $processed_ids = [];

        foreach ( $starts as $i => $start ) {
            if ( empty($start) || empty($ends[$i]) ) {
                continue;
            }
            $schedule_data = [
                'tour_id'    => $tour_db_id,
                'time_start' => sanitize_text_field( $start ),
                'time_end'   => sanitize_text_field( $ends[$i] ),
                'label_es'   => sanitize_text_field( $labels_es[$i] ?? '' ),
                'label_en'   => sanitize_text_field( $labels_en[$i] ?? '' ),
                'sort_order' => $i,
                'active'     => 1,
            ];

            $existing_id = isset($db_ids[$i]) ? (int)$db_ids[$i] : 0;
            if ( $existing_id ) {
                $wpdb->update( "{$wpdb->prefix}amir_tour_schedules", $schedule_data, ['id' => $existing_id] );
                $processed_ids[] = $existing_id;
            } else {
                $wpdb->insert( "{$wpdb->prefix}amir_tour_schedules", $schedule_data );
                $processed_ids[] = $wpdb->insert_id;
            }
        }

        // Eliminar horarios que ya no están en el form
        if ( ! empty($processed_ids) ) {
            $placeholders = implode(',', array_fill(0, count($processed_ids), '%d'));
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}amir_tour_schedules WHERE tour_id=%d AND id NOT IN ($placeholders)",
                array_merge([$tour_db_id], $processed_ids)
            ) );
        }

        // Sincronizar precios
        $price_model = get_post_meta( $post_id, '_amir_price_model', true );

        // Limpiar precios existentes y reinsertar
        $wpdb->delete( "{$wpdb->prefix}amir_prices", [ 'tour_id' => $tour_db_id ] );

        if ( $price_model === 'percapita' ) {
            foreach ( ['adult','child','baby'] as $type ) {
                $price_val = (float)( $_POST["amir_price_{$type}"] ?? 0 );
                if ( $price_val >= 0 ) {
                    $wpdb->insert( "{$wpdb->prefix}amir_prices", [
                        'tour_id'     => $tour_db_id,
                        'person_type' => $type,
                        'price_mxn'   => $price_val,
                    ] );
                }
            }
        } else {
            // Group pricing
            $ranges = [ [1,2], [3,3], [4,4] ];
            foreach ( $ranges as [ $gmin, $gmax ] ) {
                $price_val = (float)( $_POST["amir_price_group_{$gmin}_{$gmax}"] ?? 0 );
                if ( $price_val > 0 ) {
                    $wpdb->insert( "{$wpdb->prefix}amir_prices", [
                        'tour_id'     => $tour_db_id,
                        'person_type' => 'group',
                        'group_min'   => $gmin,
                        'group_max'   => $gmax,
                        'price_mxn'   => $price_val,
                    ] );
                }
            }
        }
    }

    private function sync_base_availability_rule( int $post_id, int $tour_db_id ): void {
        global $wpdb;
        $weekdays = json_decode( get_post_meta( $post_id, '_amir_active_weekdays', true ) ?: '[]', true );

        // Actualizar o crear la regla base (prioridad 10)
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amir_availability_rules WHERE tour_id=%d AND priority=10 AND date_from IS NULL",
            $tour_db_id
        ) );

        // La regla base BLOQUEA los días que NO están en la lista activa
        $blocked = array_values( array_diff( [0,1,2,3,4,5,6], $weekdays ) );

        if ( $existing ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_availability_rules",
                [ 'weekdays' => json_encode($blocked), 'rule_type' => 'block' ],
                [ 'id' => (int)$existing ]
            );
        } elseif ( ! empty($blocked) ) {
            $wpdb->insert( "{$wpdb->prefix}amir_availability_rules", [
                'tour_id'   => $tour_db_id,
                'rule_type' => 'block',
                'weekdays'  => json_encode($blocked),
                'priority'  => 10,
            ] );
        }
    }

    public function delete_from_db( int $post_id ): void {
        if ( get_post_type($post_id) !== self::POST_TYPE ) {
            return;
        }
        $db_id = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );
        if ( $db_id ) {
            global $wpdb;
            $wpdb->update( "{$wpdb->prefix}amir_tours", ['status' => 'archived'], ['id' => $db_id] );
        }
    }

    // ── REST fields para Elementor Dynamic Tags ───────────────────────────

    public function register_rest_fields(): void {
        $fields = [
            'amir_price_from'      => fn($p) => $this->get_price_from( (int)get_post_meta($p['id'],'_amir_tour_db_id',true) ),
            'amir_duration'        => fn($p) => (int)get_post_meta($p['id'],'_amir_duration_minutes',true),
            'amir_min_age'         => fn($p) => (int)get_post_meta($p['id'],'_amir_min_age',true),
            'amir_name_en'         => fn($p) => get_post_meta($p['id'],'_amir_name_en',true),
            'amir_price_model'     => fn($p) => get_post_meta($p['id'],'_amir_price_model',true),
            'amir_gallery_urls'    => fn($p) => $this->get_gallery_urls( $p['id'] ),
            'amir_db_id'           => fn($p) => (int)get_post_meta($p['id'],'_amir_tour_db_id',true),
            'amir_meeting_lat'     => fn($p) => get_post_meta($p['id'],'_amir_meeting_lat',true),
            'amir_meeting_lng'     => fn($p) => get_post_meta($p['id'],'_amir_meeting_lng',true),
        ];

        foreach ( $fields as $key => $callback ) {
            register_rest_field( self::POST_TYPE, $key, [
                'get_callback'    => $callback,
                'schema'          => null,
            ] );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function get_gallery_urls( int $post_id ): array {
        $ids  = json_decode( get_post_meta($post_id,'_amir_gallery_ids',true) ?: '[]', true );
        $urls = array_filter( array_map( fn($id) => wp_get_attachment_url($id), $ids ) );
        $thumb = get_the_post_thumbnail_url( $post_id, 'large' );
        if ( $thumb ) {
            array_unshift( $urls, $thumb );
        }
        return array_values( $urls );
    }

    private function get_price_from( int $tour_db_id ): float {
        if ( ! $tour_db_id ) {
            return 0;
        }
        global $wpdb;
        $min = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices WHERE tour_id=%d AND price_mxn > 0",
            $tour_db_id
        ) );
        return (float) $min;
    }

    public function title_placeholder( string $title ): string {
        $screen = get_current_screen();
        if ( $screen && $screen->post_type === self::POST_TYPE ) {
            return __( 'Nombre del tour (ES)', 'amir-booking' );
        }
        return $title;
    }

    private function get_meta( int $post_id ): array {
        $get = fn($k) => get_post_meta( $post_id, "_amir_{$k}", true ) ?: '';
        $langs_str = $get('languages');
        return [
            'name_en'          => $get('name_en'),
            'price_model'      => $get('price_model') ?: 'percapita',
            'duration_minutes' => $get('duration_minutes') ?: '',
            'min_age'          => $get('min_age') ?: '',
            'max_capacity'     => $get('max_capacity') ?: '',
            'min_passengers'   => $get('min_passengers') ?: '1',
            'sort_order'       => $get('sort_order') ?: '0',
            'languages_str'    => $langs_str,
            'description_en'   => $get('description_en'),
            'what_to_expect_es'=> $get('what_to_expect_es'),
            'what_to_expect_en'=> $get('what_to_expect_en'),
            'itinerary_es'     => $get('itinerary_es'),
            'itinerary_en'     => $get('itinerary_en'),
            'includes_es'      => $get('includes_es') ?: '[]',
            'includes_en'      => $get('includes_en') ?: '[]',
            'excludes_es'      => $get('excludes_es') ?: '[]',
            'excludes_en'      => $get('excludes_en') ?: '[]',
            'meeting_point_es' => $get('meeting_point_es'),
            'meeting_point_en' => $get('meeting_point_en'),
            'meeting_lat'      => $get('meeting_lat'),
            'meeting_lng'      => $get('meeting_lng'),
            'tripadvisor_id'   => $get('tripadvisor_id'),
            'gyg_id'           => $get('gyg_id'),
            'active_weekdays'  => $get('active_weekdays') ?: '[1,2,3,4,5,6]',
            'wishlist_enabled'   => $get('wishlist_enabled') ?: '0',
            'wishlist_threshold' => $get('wishlist_threshold') ?: '0',
            'wishlist_date'      => $get('wishlist_date'),
        ];
    }
}
