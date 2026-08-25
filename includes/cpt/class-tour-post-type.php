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
        // Nombre en inglés de cada categoría (§ 15.7 CONTRIBUTING.md) — la
        // taxonomía de WP es de un solo idioma nativamente, así que el
        // nombre EN se guarda aparte como termmeta, con el mismo criterio de
        // "cae al nombre tal cual si no se cargó" que el resto del contenido
        // bilingüe del plugin.
        add_action( self::TAXONOMY . '_add_form_fields',  [ $this, 'category_add_en_field'  ] );
        add_action( self::TAXONOMY . '_edit_form_fields', [ $this, 'category_edit_en_field' ], 10, 2 );
        add_action( 'created_' . self::TAXONOMY, [ $this, 'save_category_en_name' ] );
        add_action( 'edited_' . self::TAXONOMY,  [ $this, 'save_category_en_name' ] );
        add_action( 'add_meta_boxes',          [ $this, 'add_meta_boxes'     ] );
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta'   ], 10, 2 );
        add_action( 'rest_api_init',           [ $this, 'register_rest_fields' ] );
        add_filter( 'enter_title_here',        [ $this, 'title_placeholder' ] );

        // Sincronizar con tabla amir_tours al guardar/eliminar
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'sync_to_db'   ], 20, 2 );
        add_action( 'before_delete_post',      [ $this, 'delete_from_db'    ] );

        // Bug real encontrado 2026-08-11: la imagen destacada (panel nativo
        // de WP) se guarda vía su propia llamada AJAX (set_post_thumbnail())
        // en cuanto se elige, sin pasar por save_post — un operador puede
        // cambiarla y nunca volver a tocar "Actualizar", y amir_tours.gallery_images
        // queda desincronizada indefinidamente (síntoma real: la ficha del
        // tour sí muestra la foto porque lee el thumbnail de WP directo,
        // pero las tarjetas de grillas/flujos, que leen la copia en
        // amir_tours, muestran el ícono de reemplazo). set_post_thumbnail()
        // internamente hace update_post_meta( '_thumbnail_id', ... ), que sí
        // dispara estos hooks genéricos — se reusa sync_to_db() completo
        // (idempotente) en vez de solo recalcular la galería.
        add_action( 'updated_post_meta', [ $this, 'maybe_resync_on_thumbnail_change' ], 10, 4 );
        add_action( 'added_post_meta',   [ $this, 'maybe_resync_on_thumbnail_change' ], 10, 4 );

        // Forzar el editor clásico para este CPT — todo el contenido del tour
        // se guarda vía cajas meta de PHP (meta_box_main/content/pricing/
        // addons/etc.), no bloques. En el editor de bloques (Gutenberg) esas
        // cajas quedan en un panel de "compatibilidad" que se guarda con un
        // POST aparte del guardado principal (vía REST) — menos confiable, y
        // la causa real de reservas de lista de interés que no sincronizaban
        // (el estado del post sí se guardaba porque es nativo de Gutenberg,
        // pero la casilla/fecha de lista de interés no, porque dependen del
        // POST clásico de compatibilidad). show_in_rest se mantiene en true
        // igual (lo necesitan Elementor y la REST API pública), esto solo
        // cambia qué UI de edición usa wp-admin.
        add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_block_editor' ], 10, 2 );

        // Aviso visible si sync_to_db() no pudo escribir en amir_tours.
        add_action( 'admin_notices', [ $this, 'maybe_show_sync_error' ] );

        // Columna "Proveedor" en el listado de tours — para ver de un
        // vistazo cuáles son de terceros (marketplace, § 11 CONTRIBUTING.md)
        // sin entrar a cada tour. Pedido del cliente 2026-07-30.
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'add_provider_column' ] );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_provider_column' ], 10, 2 );

        // Columna "ID" — el `tour_id` que piden los shortcodes ([flow_booking
        // tour_id="X"], [flow_spots_left tour_id="X"], etc.) NO es el ID de
        // WordPress del post: es `amir_tours.id`, una secuencia propia
        // guardada en el meta `_amir_tour_db_id` (ver sync_to_db() más abajo)
        // — pueden divergir (tours importados por JSON, reordenados, etc.).
        // Antes la única forma de encontrarlo era pasar el mouse sobre el
        // título y leer `post=123` en la URL de abajo, que además es el
        // número EQUIVOCADO si diverge del db_id real. Pedido del cliente
        // 2026-08-14.
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'add_id_column' ] );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_id_column' ], 10, 2 );
    }

    public function add_id_column( array $columns ): array {
        // Justo después del título — es lo primero que se busca al armar un shortcode.
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['amir_tour_id'] = __( 'ID', 'amir-booking' );
            }
        }
        return $new;
    }

    public function render_id_column( string $column, int $post_id ): void {
        if ( $column !== 'amir_tour_id' ) {
            return;
        }
        $db_id = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );
        if ( ! $db_id ) {
            echo '<span style="color:#5a7068;">—</span>';
            return;
        }
        printf(
            '<code title="%s" onclick="navigator.clipboard.writeText(\'%d\');var t=this.nextElementSibling;t.style.opacity=1;setTimeout(function(){t.style.opacity=0;},900);" style="cursor:pointer;background:#f0faf6;color:#0F6E56;padding:2px 7px;border-radius:5px;font-size:12px;">%d</code>'
            . '<span style="color:#1D9E75;font-size:11px;opacity:0;transition:opacity .2s;margin-left:5px;">✓ copiado</span>',
            esc_attr__( 'Clic para copiar — es el tour_id que va en los shortcodes ([flow_booking tour_id="…"], etc.)', 'amir-booking' ),
            $db_id,
            $db_id
        );
    }

    public function add_provider_column( array $columns ): array {
        // Insertarla antes de la última columna (normalmente "date"), no al final —
        // ahí queda visible sin scrollear en la mayoría de las pantallas.
        $date = $columns['date'] ?? null;
        unset( $columns['date'] );
        $columns['amir_provider'] = __( 'Proveedor', 'amir-booking' );
        if ( $date !== null ) {
            $columns['date'] = $date;
        }
        return $columns;
    }

    public function render_provider_column( string $column, int $post_id ): void {
        if ( $column !== 'amir_provider' ) {
            return;
        }
        $provider_id = (int) get_post_meta( $post_id, '_amir_provider_id', true );
        if ( ! $provider_id ) {
            echo '<span style="color:#5a7068;">—</span>';
            return;
        }
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT business_name FROM {$wpdb->prefix}amir_providers WHERE id = %d", $provider_id
        ) );
        echo '<span style="display:inline-block;font-size:11px;font-weight:700;color:#BA7517;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:2px 9px;">🤝 '
            . esc_html( $name ?: 'Proveedor #' . $provider_id ) . '</span>';
    }

    public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
        return $post_type === self::POST_TYPE ? false : $use_block_editor;
    }

    public function maybe_show_sync_error(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== self::POST_TYPE || empty( $_GET['post'] ) ) {
            return;
        }
        $error = get_transient( 'amir_sync_error_' . (int) $_GET['post'] );
        if ( $error ) {
            echo '<div class="notice notice-error"><p><strong>TourFlow:</strong> este tour no se pudo sincronizar con la base de datos interna (los cambios visibles acá pueden no reflejarse en el sitio) — ' . esc_html( $error ) . '</p></div>';
        }
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

    /** Campo "Nombre en inglés" en el formulario de alta de una categoría nueva. */
    public function category_add_en_field(): void {
        ?>
        <div class="form-field">
            <label for="amir-category-name-en"><?php esc_html_e( 'Nombre en inglés', 'amir-booking' ); ?></label>
            <input type="text" name="amir_category_name_en" id="amir-category-name-en" value="" />
            <p><?php esc_html_e( 'Opcional — si se deja vacío, el sitio en inglés muestra el nombre de arriba tal cual.', 'amir-booking' ); ?></p>
        </div>
        <?php
    }

    /** Mismo campo, en el formulario de edición de una categoría existente. */
    public function category_edit_en_field( \WP_Term $term ): void {
        $value = get_term_meta( $term->term_id, 'amir_category_name_en', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="amir-category-name-en"><?php esc_html_e( 'Nombre en inglés', 'amir-booking' ); ?></label></th>
            <td>
                <input type="text" name="amir_category_name_en" id="amir-category-name-en" value="<?php echo esc_attr( $value ); ?>" />
                <p class="description"><?php esc_html_e( 'Opcional — si se deja vacío, el sitio en inglés muestra el nombre en español tal cual.', 'amir-booking' ); ?></p>
            </td>
        </tr>
        <?php
    }

    public function save_category_en_name( int $term_id ): void {
        if ( ! isset( $_POST['amir_category_name_en'] ) ) {
            return;
        }
        update_term_meta( $term_id, 'amir_category_name_en', sanitize_text_field( wp_unslash( $_POST['amir_category_name_en'] ) ) );
    }

    // ── Meta Boxes ────────────────────────────────────────────────────────

    public function add_meta_boxes(): void {
        $boxes = [
            [ 'amir_tour_main',    __( '⚙ Configuración del tour',    'amir-booking' ), [ $this, 'meta_box_main'      ] ],
            [ 'amir_tour_content', __( '📝 Contenido bilingüe (EN)',   'amir-booking' ), [ $this, 'meta_box_content'   ] ],
            [ 'amir_tour_gallery', __( '🖼 Galería de fotos',          'amir-booking' ), [ $this, 'meta_box_gallery'   ] ],
            [ 'amir_tour_pricing', __( '💰 Precios y horarios',        'amir-booking' ), [ $this, 'meta_box_pricing'   ] ],
            [ 'amir_tour_addons',  __( '🎁 Servicios extra',           'amir-booking' ), [ $this, 'meta_box_addons'    ] ],
            [ 'amir_tour_avail',   __( '📅 Disponibilidad',            'amir-booking' ), [ $this, 'meta_box_avail'     ] ],
            [ 'amir_tour_meeting', __( '📍 Punto de encuentro',        'amir-booking' ), [ $this, 'meta_box_meeting'   ] ],
            [ 'amir_tour_integr',  __( '🔗 Integraciones externas',    'amir-booking' ), [ $this, 'meta_box_integr'    ] ],
            // Universal a las 3 ediciones (pedido del cliente 2026-08-25,
            // "por tour" — no es un diferencial de Pro Max como Datos
            // destacados/Itinerario, es contenido simple de texto).
            [ 'amir_tour_faq',     __( '❓ Preguntas frecuentes (FAQ)', 'amir-booking' ), [ $this, 'meta_box_faq'       ] ],
        ];

        // Datos destacados + Itinerario timeline: Pro y superior (§ 13.1/13.2,
        // parte del diferencial visual de la versión superior — Pro Max
        // hereda todo lo de Pro, decisión del cliente 2026-08-03).
        if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
            $boxes[] = [ 'amir_tour_facts',     __( '✨ Datos destacados',        'amir-booking' ), [ $this, 'meta_box_facts'     ] ];
            $boxes[] = [ 'amir_tour_itinerary', __( '🗺 Itinerario (timeline)',   'amir-booking' ), [ $this, 'meta_box_itinerary' ] ];
        }

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
          /* Secciones de casos especiales (fecha fija, solo a pedido, venta
             separada, lista de interés) — colapsadas por defecto para no
             abrumar al cargar un tour normal, mismo patrón visual que
             TourFlow → Dashboard → Shortcodes disponibles. Se abren solas
             si el tour ya tiene esa opción activa, para que nada quede
             escondido sin querer. Pedido del cliente 2026-08-14. */
          .amir-collapsible { margin:16px 0 0; border-top:1px solid #e1f5ee; padding-top:2px; }
          .amir-collapsible summary { cursor:pointer; list-style:none; display:flex; align-items:center; gap:7px;
            font-size:13px; font-weight:700; color:#1D9E75; padding:8px 0; }
          .amir-collapsible summary::-webkit-details-marker { display:none; }
          .amir-collapsible summary::before { content:'▸'; display:inline-block; transition:transform .15s; font-size:11px; }
          .amir-collapsible[open] summary::before { transform:rotate(90deg); }
          .amir-collapsible summary .amir-active-flag { font-size:10px; font-weight:700; color:#0F6E56; background:#e1f5ee; border-radius:8px; padding:1px 8px; text-transform:none; letter-spacing:0; }
        </style>

        <div class="amir-field" style="margin-bottom:16px;">
          <label><?php _e('Nombre en inglés (EN)', 'amir-booking'); ?></label>
          <input type="text" name="amir_name_en" value="<?php echo esc_attr($m['name_en']); ?>" placeholder="Tour name in English" />
        </div>

        <?php // amir_price_model se mudó al metabox "💰 Precios y horarios" —
              // vivía acá, separado de los campos de precio que gobierna. ?>

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
            <label><?php _e('Mín. personas por reserva', 'amir-booking'); ?></label>
            <input type="number" name="amir_min_passengers" value="<?php echo esc_attr($m['min_passengers']); ?>" min="1" placeholder="2" />
            <p class="description"><?php _e('Ninguna reserva individual puede tener menos personas que este número (ej. un tour que solo tiene sentido operar en pareja o más). También se usa para avisarte si una salida no llega a este total sumando todas sus reservas confirmadas.', 'amir-booking'); ?></p>
          </div>
          <div class="amir-field">
            <label><?php _e('Idiomas disponibles', 'amir-booking'); ?></label>
            <input type="text" name="amir_languages" value="<?php echo esc_attr($m['languages_str']); ?>" placeholder="<?php esc_attr_e( 'Español, English', 'amir-booking' ); ?>" />
            <p class="amir-hint"><?php _e('Separados por coma', 'amir-booking'); ?></p>

          </div>
          <div class="amir-field">
            <label><?php _e('Orden de listado', 'amir-booking'); ?></label>
            <input type="number" name="amir_sort_order" value="<?php echo esc_attr($m['sort_order']); ?>" min="0" placeholder="0" />
          </div>
          <div class="amir-field">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
              <input type="checkbox" name="amir_featured" value="1" <?php checked( $m['featured'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
              <?php _e('⭐ Destacado (Flujo de descubrimiento)', 'amir-booking'); ?>
            </label>
            <p class="amir-hint"><?php _e('Aparece en el paso 1 del flujo continuo — solo tours propios marcados acá, ordenados por "Orden de listado".', 'amir-booking'); ?></p>
          </div>
        </div>

        <details class="amir-collapsible" <?php echo $m['fixed_date'] ? 'open' : ''; ?>>
          <summary><?php _e('📌 Fecha fija (evento único, opcional)', 'amir-booking'); ?>
            <?php if ( $m['fixed_date'] ) : ?><span class="amir-active-flag"><?php _e('Activo', 'amir-booking'); ?></span><?php endif; ?>
          </summary>
          <p style="font-size:12px;color:#666;margin:0 0 12px;">
            <?php _e( 'Para un tour que se realiza <strong>una sola vez</strong> en una fecha puntual (ej. un evento especial). Con esto cargado, el widget de reserva <strong>no muestra calendario</strong> — va directo a esa fecha (y al horario, si hay uno solo configurado abajo). Dejalo vacío para el comportamiento normal (calendario con disponibilidad por reglas).', 'amir-booking' ); ?>
          </p>
          <div class="amir-meta-grid">
            <div class="amir-field">
              <label><?php _e('Fecha fija', 'amir-booking'); ?></label>
              <input type="date" name="amir_fixed_date" value="<?php echo esc_attr($m['fixed_date']); ?>" />
              <p class="amir-hint"><?php _e('El tour solo se puede reservar ese día exacto — las reglas de Disponibilidad se ignoran mientras esto esté cargado.', 'amir-booking'); ?></p>
            </div>
          </div>
        </details>

        <details class="amir-collapsible" <?php echo ( $m['request_only'] || $m['custom_quote'] ) ? 'open' : ''; ?>>
          <summary><?php _e('🙋 Solo a pedido (sin cobro inmediato)', 'amir-booking'); ?>
            <?php if ( $m['request_only'] ) : ?><span class="amir-active-flag"><?php _e('Activo', 'amir-booking'); ?></span><?php endif; ?>
          </summary>
          <p style="font-size:12px;color:#666;margin:0 0 12px;">
            <?php _e( 'El cliente sigue viendo el calendario normal y elige fecha/horario como siempre, pero <strong>ninguna reserva se cobra al instante</strong> — queda como solicitud (en Reservas, filtro "📅 Fecha solicitada") hasta que la aprobás manualmente. Al aprobar se manda el link de pago (mismo mecanismo que "solicitar fecha" en tours de fecha fija). Pensado para tours que necesitan que confirmes disponibilidad real antes de cobrar.', 'amir-booking' ); ?>
          </p>
          <div class="amir-meta-grid">
            <div class="amir-field">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
                <input type="checkbox" name="amir_request_only" id="amir-request-only-cb" value="1" <?php checked( $m['request_only'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
                <?php _e('Todas las reservas de este tour requieren mi aprobación', 'amir-booking'); ?>
              </label>
            </div>
          </div>

          <div class="amir-section-title" style="font-size:12px;margin:16px 0 8px;"><?php _e('🧩 Armá tu tour — sin precio fijo (opcional)', 'amir-booking'); ?></div>

          <p style="font-size:12px;color:#666;margin:0 0 12px;">
            <?php _e( 'Para un tour que <strong>no tiene precio ni horario fijo</strong> porque depende de lo que pida cada cliente (ej. un itinerario a medida). Con esto activo, el cliente no ve calendario ni precio — solo indica cuántas personas son y describe qué quiere armar. Vos cargás el precio real al aprobar la solicitud, antes de mandar el link de pago.', 'amir-booking' ); ?>
          </p>
          <div class="amir-meta-grid">
            <div class="amir-field">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;" id="amir-custom-quote-label">
                <input type="checkbox" name="amir_custom_quote" id="amir-custom-quote-cb" value="1" <?php checked( $m['custom_quote'], '1' ); ?> <?php disabled( ! $m['request_only'] ); ?> style="accent-color:#1D9E75;width:auto;" />
                <?php _e('Este tour no tiene precio fijo — el cliente arma su pedido y yo cotizo', 'amir-booking'); ?>
              </label>
              <p class="amir-hint" id="amir-custom-quote-hint" style="<?php echo $m['request_only'] ? 'display:none;' : ''; ?>">
                <?php _e('Requiere tildar primero "Todas las reservas de este tour requieren mi aprobación", arriba.', 'amir-booking'); ?>
              </p>
            </div>
          </div>
          <script>
          (function(){
            var master = document.getElementById('amir-request-only-cb');
            var child  = document.getElementById('amir-custom-quote-cb');
            var hint   = document.getElementById('amir-custom-quote-hint');
            if ( ! master || ! child ) return;
            master.addEventListener('change', function(){
              child.disabled = ! master.checked;
              if ( ! master.checked ) child.checked = false;
              if ( hint ) hint.style.display = master.checked ? 'none' : '';
            });
          })();
          </script>
        </details>

        <?php if ( AMIR_EDITION === 'pro_max' ) : ?>
        <details class="amir-collapsible" <?php echo $m['deposit_enabled'] ? 'open' : ''; ?>>
          <summary><?php _e('💰 Depósito parcial (Pro Max)', 'amir-booking'); ?>
            <?php if ( $m['deposit_enabled'] ) : ?><span class="amir-active-flag"><?php _e('Activo', 'amir-booking'); ?></span><?php endif; ?>
          </summary>
          <p style="font-size:12px;color:#666;margin:0 0 12px;">
            <?php _e( 'El cliente paga solo este % online al reservar (flujo Explorar/combinado) — el resto lo cobrás después, en efectivo el día de la experiencia o mandando un link de pago desde la reserva. No aplica a habitaciones, solo a este tour.', 'amir-booking' ); ?>
          </p>
          <div class="amir-meta-grid">
            <div class="amir-field">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
                <input type="checkbox" name="amir_deposit_enabled" id="amir-deposit-enabled-cb" value="1" <?php checked( $m['deposit_enabled'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
                <?php _e('Cobrar solo un % como depósito', 'amir-booking'); ?>
              </label>
            </div>
            <div class="amir-field">
              <label><?php _e('Porcentaje del depósito', 'amir-booking'); ?></label>
              <input type="number" name="amir_deposit_pct" id="amir-deposit-pct-input" value="<?php echo esc_attr( $m['deposit_pct'] ); ?>" min="1" max="99" placeholder="<?php esc_attr_e( 'Ej: 20', 'amir-booking' ); ?>" <?php disabled( ! $m['deposit_enabled'] ); ?> />
              <p class="amir-hint"><?php _e('Entre 1 y 99. Ej: 20 = el cliente paga 20% ahora, 80% después.', 'amir-booking'); ?></p>

            </div>
          </div>
          <script>
          (function(){
            var master = document.getElementById('amir-deposit-enabled-cb');
            var pct    = document.getElementById('amir-deposit-pct-input');
            if ( ! master || ! pct ) return;
            master.addEventListener('change', function(){
              pct.disabled = ! master.checked;
            });
          })();
          </script>
        </details>

        <details class="amir-collapsible" <?php echo $m['skip_upsell'] ? 'open' : ''; ?>>
          <summary><?php _e('🎯 Reserva directa (Pro Max)', 'amir-booking'); ?>
            <?php if ( $m['skip_upsell'] ) : ?><span class="amir-active-flag"><?php _e('Activo', 'amir-booking'); ?></span><?php endif; ?>
          </summary>
          <p style="font-size:12px;color:#666;margin:0 0 12px;">
            <?php _e( 'Por defecto, reservar este tour desde su ficha entra al flujo combinado (sugiere habitaciones/extras antes de pagar — "upsell siempre"). Activá esto para tours que no tiene sentido combinar con nada más (ej. un traslado puntual): la ficha usa el widget clásico directo, con el calendario visible de entrada, sin pasos de upsell.', 'amir-booking' ); ?>
          </p>
          <div class="amir-meta-grid">
            <div class="amir-field">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
                <input type="checkbox" name="amir_skip_upsell" value="1" <?php checked( $m['skip_upsell'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
                <?php _e('Este tour no combina con otros — reservar directo', 'amir-booking'); ?>
              </label>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <details class="amir-collapsible" <?php echo ( $m['hide_from_lists'] || $m['hide_from_suggestions'] ) ? 'open' : ''; ?>>
          <summary><?php _e('🙈 Venta separada (opcional)', 'amir-booking'); ?>
            <?php if ( $m['hide_from_lists'] || $m['hide_from_suggestions'] ) : ?><span class="amir-active-flag"><?php _e('Activo', 'amir-booking'); ?></span><?php endif; ?>
          </summary>
          <p style="font-size:12px;color:#666;margin:0 0 12px;">
            <?php _e( 'Para un tour que solo tiene sentido reservar por su cuenta (ej. un traslado) — se sigue reservando normal desde su propia ficha, solo cambia dónde aparece listado.', 'amir-booking' ); ?>
          </p>
          <div class="amir-meta-grid amir-meta-grid-3">
            <div class="amir-field">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
                <input type="checkbox" name="amir_hide_from_lists" value="1" <?php checked( $m['hide_from_lists'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
                <?php _e('Ocultar de listas/grillas', 'amir-booking'); ?>
              </label>
              <p class="amir-hint"><?php _e('No aparece en [flow_tour_list], ni en destacados (Flujo A) ni en el catálogo completo (Flujo B) — gana sobre "Destacado" si los dos están tildados.', 'amir-booking'); ?></p>
            </div>
            <div class="amir-field">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
                <input type="checkbox" name="amir_hide_from_suggestions" value="1" <?php checked( $m['hide_from_suggestions'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
                <?php _e('Ocultar de sugerencias', 'amir-booking'); ?>
              </label>
              <p class="amir-hint"><?php _e('No aparece como "otro tour sugerido" en el paso de extras de otra reserva.', 'amir-booking'); ?></p>
            </div>
          </div>
        </details>

        <div class="amir-section-title"><?php _e('👶 Restricciones de edad', 'amir-booking'); ?></div>

        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          <?php _e( 'La <strong>edad mínima</strong> de arriba ya se muestra en la ficha del tour y en las tarjetas del listado. Acá además controlás si el widget de reserva deja elegir niños/bebés — por ejemplo, para un tour <strong>solo para adultos</strong>, destildá ambos y esos contadores directamente desaparecen del paso de personas.', 'amir-booking' ); ?>
        </p>
        <div class="amir-meta-grid amir-meta-grid-3">
          <div class="amir-field">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
              <input type="checkbox" name="amir_allow_children" value="1" <?php checked( $m['allow_children'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
              <?php _e('Admite niños', 'amir-booking'); ?>
            </label>
          </div>
          <div class="amir-field">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
              <input type="checkbox" name="amir_allow_babies" value="1" <?php checked( $m['allow_babies'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
              <?php _e('Admite bebés', 'amir-booking'); ?>
            </label>
          </div>
          <div class="amir-field">
            <label><?php _e('Edad mínima "niño" (vs. bebé)', 'amir-booking'); ?></label>
            <input type="number" name="amir_min_age_child" value="<?php echo esc_attr($m['min_age_child']); ?>" min="0" placeholder="4" />
            <p class="amir-hint"><?php _e('Por debajo de esta edad cuenta como bebé, no como niño', 'amir-booking'); ?></p>

          </div>
        </div>

        <div class="amir-section-title"><?php _e('🪪 Manifiesto de pasajeros', 'amir-booking'); ?></div>
        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          <?php _e( 'Para tours que necesitan el nombre de cada persona (ej. seguro, briefing de seguridad) y no solo la cantidad — la mayoría de los tours no lo necesita. Con esto activo, el widget pide un nombre por cada adulto/niño (los bebés quedan afuera) antes de confirmar, y esos nombres aparecen en el manifiesto PDF de TourFlow → Reportes.', 'amir-booking' ); ?>
        </p>
        <div class="amir-field">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;">
            <input type="checkbox" name="amir_require_participant_names" value="1" <?php checked( $m['require_participant_names'], '1' ); ?> style="accent-color:#1D9E75;width:auto;" />
            <?php _e('Requiere nombre de cada integrante', 'amir-booking'); ?>
          </label>
        </div>

        <?php if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) && get_option( 'amir_module_marketplace', '1' ) === '1' ) : ?>
        <div class="amir-section-title"><?php _e('🤝 Proveedor externo (marketplace)', 'amir-booking'); ?></div>

        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          <?php _e( 'Si este tour lo opera un <strong>proveedor externo</strong> (TourFlow lo revende con margen propio), asignalo acá. Las reservas de este tour van a quedar pendientes de que el proveedor confirme disponibilidad por email antes de darse por confirmadas. Dejalo en "— Tour propio —" para el flujo normal.', 'amir-booking' ); ?>
        </p>
        <div class="amir-meta-grid">
          <div class="amir-field">
            <label><?php _e('Proveedor', 'amir-booking'); ?></label>
            <select name="amir_provider_id" id="amir-provider-id-select">
              <option value=""><?php _e('— Tour propio —', 'amir-booking'); ?></option>
              <?php foreach ( $this->get_provider_options( (int) $m['provider_id'] ) as $p ) : ?>
                <option value="<?php echo (int) $p->id; ?>" <?php selected( (int) $m['provider_id'], (int) $p->id ); ?>>
                  <?php echo esc_html( $p->business_name . ( (int) $p->active === 0 ? ' ' . __( '(inactivo)', 'amir-booking' ) : '' ) ); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="amir-hint"><?php _e('Se gestionan en TourFlow → 🤝 Proveedores', 'amir-booking'); ?></p>

          </div>
          <div class="amir-field">
            <label><?php _e('¿Cuándo se cobra?', 'amir-booking'); ?></label>
            <select name="amir_provider_charge_mode">
              <option value="immediate" <?php selected( $m['provider_charge_mode'], 'immediate' ); ?>>
                <?php _e('Al reservar (de siempre) — si el proveedor rechaza, se reembolsa', 'amir-booking'); ?>
              </option>
              <option value="on_approval" <?php selected( $m['provider_charge_mode'], 'on_approval' ); ?>>
                <?php _e('Recién cuando el proveedor confirme — el cliente paga después, por link', 'amir-booking'); ?>
              </option>
            </select>
            <p class="amir-hint"><?php _e('"Recién cuando confirme" nunca cobra nada si el proveedor rechaza o no responde — no hace falta reembolsar.', 'amir-booking'); ?></p>

          </div>
        </div>
        <?php endif; ?>

        <?php if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) && get_option( 'amir_module_wishlist', '1' ) === '1' ) :
          // Solo importa de verdad mientras el tour está en borrador (ver
          // descripción abajo) — abierta sola si ya está activada o si el
          // tour sigue sin publicar (el único momento en que esto es
          // accionable), colapsada para un tour ya publicado sin usarla.
          $wishlist_relevant = $m['wishlist_enabled'] || $post->post_status !== 'publish';
        ?>
        <details class="amir-collapsible" <?php echo $wishlist_relevant ? 'open' : ''; ?>>
          <summary><?php _e('📋 Lista de interés ("Próximamente")', 'amir-booking'); ?>
            <?php if ( $m['wishlist_enabled'] ) : ?><span class="amir-active-flag"><?php _e('Activo', 'amir-booking'); ?></span><?php endif; ?>
          </summary>
          <p style="font-size:12px;color:#666;margin:0 0 12px;">
            <?php _e( 'Mientras este tour esté en <strong>borrador</strong>, se puede mostrar en la sección "Próximamente" del sitio para que la gente se anote — completando fecha, personas y datos como una reserva normal, pero sin pagar todavía. Al publicar el tour (o usar "TourFlow → Lista de interés"), cada anotado recibe un email con el link para pagar.', 'amir-booking' ); ?>
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
              <input type="number" name="amir_wishlist_threshold" value="<?php echo esc_attr($m['wishlist_threshold']); ?>" min="0" placeholder="<?php esc_attr_e( 'Ej: 10', 'amir-booking' ); ?>" />
              <p class="amir-hint"><?php _e('0 = sin umbral, solo acumula interesados', 'amir-booking'); ?></p>

            </div>
            <div class="amir-field">
              <label><?php _e('Fecha del tour/retiro', 'amir-booking'); ?></label>
              <input type="date" name="amir_wishlist_date" value="<?php echo esc_attr($m['wishlist_date']); ?>" />
              <p class="amir-hint"><?php _e('La fecha ya definida a la que la gente muestra interés — no hay calendario de disponibilidad mientras el tour está en borrador', 'amir-booking'); ?></p>

            </div>
          </div>
        </details>
        <?php endif; ?>
        <?php
    }

    /**
     * Proveedores para el <select> del meta box — activos, más el
     * actualmente asignado aunque se haya desactivado después (para no
     * perder la selección existente al desactivar un proveedor).
     */
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

    /**
     * Bandas de precio por grupo — antes fijas en [1–2],[3],[4] sin importar
     * la capacidad máxima configurada (bug real: un tour de grupo con
     * capacidad 10 no tenía forma de cargar precio para 5-10 personas).
     * Usado tanto para renderizar el metabox como para guardar
     * (sync_tour_prices()) — deben coincidir siempre, por eso es un solo
     * método compartido en vez de duplicar el array en los dos lugares.
     * Público porque TourImporter también lo necesita para mapear
     * "prices_group" del JSON a las mismas bandas, sin duplicar la lógica.
     */
    public static function group_price_ranges( int $max_capacity ): array {
        $max_capacity = max( 1, $max_capacity );
        $band = function ( int $min, int $max ) use ( $max_capacity ): array {
            $suffix = $max === $max_capacity ? ' ' . __( '(máx.)', 'amir-booking' ) : '';
            $label  = $min === $max
                ? sprintf( _n( '%d persona', '%d personas', $min, 'amir-booking' ), $min ) . $suffix
                /* translators: 1: mínimo de personas, 2: máximo de personas */
                : sprintf( __( '%1$d–%2$d personas', 'amir-booking' ), $min, $max ) . $suffix;
            return [ $min, $max, $label ];
        };

        if ( $max_capacity <= 2 ) {
            return [ $band( 1, $max_capacity ) ];
        }
        if ( $max_capacity === 3 ) {
            return [ $band( 1, 2 ), $band( 3, 3 ) ];
        }
        return [ $band( 1, 2 ), $band( 3, 3 ), $band( 4, $max_capacity ) ];
    }

    // ── Meta Box: Datos destacados ────────────────────────────────────────

    /**
     * 2-4 bloques de ícono + título + detalle, configurables por tour (ej.
     * 🗣️ Idioma → Español, Inglés — 👥 Personas → 2–8 — 🎂 Edad mínima → 12+).
     * Pedido del cliente para hacer más visibles datos que hoy solo aparecen
     * como chips chicos en el hero — se muestran en un bloque propio, más
     * prominente, arriba de la descripción. Freeform a propósito (mismo
     * criterio que includes/excludes): no está atado a los campos
     * estructurados existentes (duración, edad, idiomas...), el operador
     * escribe lo que le parece más relevante destacar para ESE tour.
     */
    public function meta_box_facts( \WP_Post $post ): void {
        $facts = json_decode( get_post_meta( $post->ID, '_amir_detail_facts', true ) ?: '[]', true );
        if ( ! is_array( $facts ) ) {
            $facts = [];
        }
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 14px;">
          <?php _e( 'Opcional — 2 a 4 datos que quieras resaltar arriba de la descripción (ej. idioma, cantidad de personas, edad mínima, nivel de dificultad). El ícono es un emoji, se pega directo (🗣️ 👥 🎂 ⛰️ ⏱️...).', 'amir-booking' ); ?>
        </p>

        <div id="amir-facts-wrap">
          <?php
          $render_fact_row = function ( $f = null ) {
              $icon     = $f['icon']     ?? '';
              $label_es = $f['label_es'] ?? '';
              $label_en = $f['label_en'] ?? '';
              $value_es = $f['value_es'] ?? '';
              $value_en = $f['value_en'] ?? '';
              ?>
              <div class="amir-fact-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
                <input type="text" name="amir_fact_icon[]" value="<?php echo esc_attr( $icon ); ?>" placeholder="🗣️" maxlength="8" style="width:52px;text-align:center;border:1px solid #c3d9d0;border-radius:6px;padding:6px 4px;font-size:16px;box-sizing:border-box;" />
                <input type="text" name="amir_fact_label_es[]" value="<?php echo esc_attr( $label_es ); ?>" placeholder="Idioma" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                <input type="text" name="amir_fact_label_en[]" value="<?php echo esc_attr( $label_en ); ?>" placeholder="Language" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                <input type="text" name="amir_fact_value_es[]" value="<?php echo esc_attr( $value_es ); ?>" placeholder="Español, Inglés" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                <input type="text" name="amir_fact_value_en[]" value="<?php echo esc_attr( $value_en ); ?>" placeholder="Spanish, English" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                <button type="button" onclick="this.closest('.amir-fact-row').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
              </div>
              <?php
          };

          foreach ( $facts as $f ) {
              $render_fact_row( $f );
          }
          ?>
        </div>
        <button type="button" id="amir-add-fact-btn"
                style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">
          + <?php _e( 'Agregar dato destacado', 'amir-booking' ); ?>
        </button>

        <script>
        document.getElementById('amir-add-fact-btn').addEventListener('click', function(){
          var row = '<div class="amir-fact-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">'
            + '<input type="text" name="amir_fact_icon[]" placeholder="🗣️" maxlength="8" style="width:52px;text-align:center;border:1px solid #c3d9d0;border-radius:6px;padding:6px 4px;font-size:16px;box-sizing:border-box;" />'
            + '<input type="text" name="amir_fact_label_es[]" placeholder="Idioma" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
            + '<input type="text" name="amir_fact_label_en[]" placeholder="Language" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
            + '<input type="text" name="amir_fact_value_es[]" placeholder="Español, Inglés" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
            + '<input type="text" name="amir_fact_value_en[]" placeholder="Spanish, English" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
            + '<button type="button" onclick="this.closest(\'.amir-fact-row\').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>'
            + '</div>';
          document.getElementById('amir-facts-wrap').insertAdjacentHTML('beforeend', row);
        });
        </script>
        <?php
    }

    // ── Meta Box: Contenido EN ────────────────────────────────────────────

    public function meta_box_content( \WP_Post $post ): void {
        $m = $this->get_meta( $post->ID );
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          <?php _e( 'El <strong>título y editor principal</strong> de WordPress son el nombre y descripción en <strong>Español</strong>.<br>Aquí completa los campos adicionales bilingües.', 'amir-booking' ); ?>
        </p>

        <!-- Highlights: ES + EN -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Highlights (ES) — una por línea, 4-5 recomendado', 'amir-booking'); ?></label>

            <textarea name="amir_highlights_es" rows="4" placeholder="Snorkel en cenote de agua cristalina&#10;Guía certificado incluido&#10;Grupos reducidos, máx. 8 personas" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode( "\n", json_decode( $m['highlights_es'] ?? '[]', true ) ?: [] ) ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Highlights (EN) — one per line', 'amir-booking'); ?></label>

            <textarea name="amir_highlights_en" rows="4" placeholder="Snorkeling in crystal-clear cenote&#10;Certified guide included&#10;Small groups, max. 8 people" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode( "\n", json_decode( $m['highlights_en'] ?? '[]', true ) ?: [] ) ); ?></textarea>
          </div>
        </div>
        <p style="font-size:11px;color:#888;margin:-8px 0 14px;"><?php _e( 'Se muestran como bullets cortos arriba de la descripción larga en la ficha del tour — pensado como resumen rápido, no reemplaza "Qué esperar".', 'amir-booking' ); ?></p>

        <!-- Qué esperar: ES + EN -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Qué esperar (ES)', 'amir-booking'); ?></label>

            <textarea name="amir_what_to_expect_es" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['what_to_expect_es']); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('What to expect (EN)', 'amir-booking'); ?></label>

            <textarea name="amir_what_to_expect_en" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['what_to_expect_en']); ?></textarea>
          </div>
        </div>

        <!-- Contenido extra en el email de confirmación: ES + EN (Tarea 24
             del roadmap, CONTRIBUTING.md § 5.5/16.91) — ej. "Este tour
             requiere pasaporte". Sistema de emails de todo el plugin es
             es/en-only por diseño (BaseEmail::text()), mismo criterio acá. -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Nota extra en el email de confirmación (ES)', 'amir-booking'); ?></label>

            <textarea name="amir_email_extra_note_es" rows="3" placeholder="<?php echo esc_attr__( 'Ej: Este tour requiere pasaporte vigente.', 'amir-booking' ); ?>" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $m['email_extra_note_es'] ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Extra note in the confirmation email (EN)', 'amir-booking'); ?></label>

            <textarea name="amir_email_extra_note_en" rows="3" placeholder="<?php echo esc_attr__( 'Ex: This tour requires a valid passport.', 'amir-booking' ); ?>" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $m['email_extra_note_en'] ); ?></textarea>
          </div>
        </div>
        <p style="font-size:11px;color:#888;margin:-8px 0 14px;"><?php _e( 'Se agrega solo al email de CONFIRMACIÓN de este tour puntual, sin tocar la plantilla general de todos los tours. Vacío = no se agrega nada.', 'amir-booking' ); ?></p>

        <?php
        // Bug real reportado por el cliente (2026-08-21): este campo era una
        // SEGUNDA copia editable de "amir_name_en" (la real vive en
        // meta_box_main, arriba) — mismo `name`, así que al guardar el
        // navegador solo manda el valor del que se renderiza último en el
        // HTML (esta metabox se registra después que la principal, ver
        // add_meta_boxes()), pisando en silencio lo que se haya editado en
        // la de arriba. Corregido sacándole el `name` — queda de solo
        // lectura, informativo nomás, edición real solo en Configuración.
        ?>
        <div class="amir-field" style="margin-bottom:14px;">
          <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Tour name (EN)', 'amir-booking'); ?></label>

          <input type="text" value="<?php echo esc_attr($m['name_en']); ?>" placeholder="Tour name in English" readonly
                 title="<?php esc_attr_e( 'Solo lectura — editá el nombre en inglés desde ⚙ Configuración del tour, arriba.', 'amir-booking' ); ?>"
                 style="width:100%;border:1px solid #e1e1e1;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;background:#f7f7f7;color:#777;cursor:not-allowed;" />
          <p class="amir-hint"><?php _e( 'Solo lectura — editalo en ⚙ Configuración del tour, arriba.', 'amir-booking' ); ?></p>
        </div>

        <!-- Incluye / No incluye -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Incluye (ES) — una por línea', 'amir-booking'); ?></label>

            <textarea name="amir_includes_es" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['includes_es']??'[]',true)) ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Includes (EN) — one per line', 'amir-booking'); ?></label>

            <textarea name="amir_includes_en" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['includes_en']??'[]',true)) ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('No incluye (ES)', 'amir-booking'); ?></label>

            <textarea name="amir_excludes_es" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['excludes_es']??'[]',true)) ); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Not included (EN)', 'amir-booking'); ?></label>

            <textarea name="amir_excludes_en" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( implode("\n", json_decode($m['excludes_en']??'[]',true)) ); ?></textarea>
          </div>
        </div>

        <!-- Itinerario -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Itinerario (ES) — opcional', 'amir-booking'); ?></label>

            <textarea name="amir_itinerary_es" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['itinerary_es']??''); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Itinerary (EN) — optional', 'amir-booking'); ?></label>

            <textarea name="amir_itinerary_en" rows="5" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['itinerary_en']??''); ?></textarea>
          </div>
        </div>

        <?php $this->render_extra_language_tabs( $m['content_i18n'] ?? [] ); ?>
        <?php
    }

    /**
     * Un bloque de campos por cada idioma activo más allá de es/en (que ya
     * tienen sus propios campos fijos arriba). El operador agrega el idioma
     * en Configuración → Idiomas y automáticamente aparece acá — nada de
     * esto requiere que un desarrollador toque código.
     */
    private function render_extra_language_tabs( array $content_i18n ): void {
        $extra_langs = array_diff( \AmirBooking\Core\Languages::active(), [ 'es', 'en' ] );
        if ( empty( $extra_langs ) ) {
            return;
        }
        ?>
        <div class="amir-section-title"><?php _e('🌐 Otros idiomas', 'amir-booking'); ?></div>

        <?php foreach ( $extra_langs as $lang ) :
            $data = $content_i18n[ $lang ] ?? [];
            $highlights_str = implode( "\n", (array) ( $data['highlights'] ?? [] ) );
            $includes_str   = implode( "\n", (array) ( $data['includes'] ?? [] ) );
            $excludes_str   = implode( "\n", (array) ( $data['excludes'] ?? [] ) );
        ?>
        <div class="amir-field-row" style="border:1px solid #e1f5ee;border-radius:8px;padding:14px;margin-bottom:14px;">
          <div style="grid-column:1/-1;font-weight:700;font-size:12px;color:#1D9E75;text-transform:uppercase;margin-bottom:8px;">
            <?php echo esc_html( strtoupper( $lang ) ); ?>
          </div>
          <div class="amir-field">
            <label><?php _e('Nombre del tour', 'amir-booking'); ?></label>

            <input type="text" name="amir_i18n[<?php echo esc_attr($lang); ?>][name]" value="<?php echo esc_attr( $data['name'] ?? '' ); ?>" />
          </div>
          <div class="amir-field">
            <label><?php _e('Punto de encuentro', 'amir-booking'); ?></label>

            <textarea name="amir_i18n[<?php echo esc_attr($lang); ?>][meeting_point]" rows="2" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $data['meeting_point'] ?? '' ); ?></textarea>
          </div>
          <div class="amir-field" style="grid-column:1/-1;">
            <label><?php _e('Descripción', 'amir-booking'); ?></label>

            <textarea name="amir_i18n[<?php echo esc_attr($lang); ?>][description]" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $data['description'] ?? '' ); ?></textarea>
          </div>
          <div class="amir-field" style="grid-column:1/-1;">
            <label><?php _e('Qué esperar', 'amir-booking'); ?></label>

            <textarea name="amir_i18n[<?php echo esc_attr($lang); ?>][what_to_expect]" rows="3" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $data['what_to_expect'] ?? '' ); ?></textarea>
          </div>
          <div class="amir-field" style="grid-column:1/-1;">
            <label><?php _e('Highlights — una por línea, 4-5 recomendado', 'amir-booking'); ?></label>

            <textarea name="amir_i18n[<?php echo esc_attr($lang); ?>][highlights]" rows="3" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $highlights_str ); ?></textarea>
          </div>
          <div class="amir-field">
            <label><?php _e('Incluye — una por línea', 'amir-booking'); ?></label>

            <textarea name="amir_i18n[<?php echo esc_attr($lang); ?>][includes]" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $includes_str ); ?></textarea>
          </div>
          <div class="amir-field">
            <label><?php _e('No incluye — una por línea', 'amir-booking'); ?></label>

            <textarea name="amir_i18n[<?php echo esc_attr($lang); ?>][excludes]" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $excludes_str ); ?></textarea>
          </div>
          <div class="amir-field" style="grid-column:1/-1;">
            <label><?php _e('Itinerario — opcional', 'amir-booking'); ?></label>

            <textarea name="amir_i18n[<?php echo esc_attr($lang); ?>][itinerary]" rows="4" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $data['itinerary'] ?? '' ); ?></textarea>
          </div>
        </div>
        <?php endforeach;
    }

    // ── Meta Box: Itinerario (timeline) ─────────────────────────────────────

    /**
     * Timeline de paradas (§ 13.1 CONTRIBUTING.md) — título + descripción +
     * imagen opcional por parada, opcional por tour (si no se carga
     * ninguna, el frontend no muestra la sección). Mismo patrón de filas
     * repetibles que horarios/servicios extra, pero se guarda como un único
     * JSON (`_amir_itinerary_stops` → `amir_tours.itinerary_stops`), no como
     * tabla propia — no hay nada más en el plugin que necesite consultar
     * una parada individualmente.
     */
    public function meta_box_itinerary( \WP_Post $post ): void {
        $stops = json_decode( get_post_meta( $post->ID, '_amir_itinerary_stops', true ) ?: '[]', true );
        if ( ! is_array( $stops ) ) {
            $stops = [];
        }
        wp_enqueue_media();
        // Definidas una sola vez y reusadas tanto en el render PHP (más
        // abajo) como en el <script> que arma filas nuevas dinámicamente —
        // para que las dos versiones nunca queden desincronizadas.
        $t_is_start     = __( '📍 ¿Es el punto de partida?', 'amir-booking' );
        $t_stop_title_es = __( 'Título de la parada (ES)', 'amir-booking' );
        $t_stop_title_en = __( 'Stop title (EN)', 'amir-booking' );
        $t_stop_desc_es  = __( 'Descripción (ES) — opcional', 'amir-booking' );
        $t_stop_desc_en  = __( 'Description (EN) — optional', 'amir-booking' );
        $t_add_image     = __( 'Agregar imagen (opcional)', 'amir-booking' );
        $t_change_image  = __( 'Cambiar imagen', 'amir-booking' );
        $t_remove_image  = __( 'Quitar', 'amir-booking' );
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 14px;">
          <?php _e( 'Opcional — si no cargas paradas, la sección de itinerario no se muestra en la página del tour. Marcá una parada como "punto de partida" para que se resalte distinto en el timeline (como en GetYourGuide/Viator).', 'amir-booking' ); ?>
        </p>

        <div id="amir-stops-wrap">
          <?php
          $render_stop_row = function ( $s = null ) use ( $t_is_start, $t_stop_title_es, $t_stop_title_en, $t_stop_desc_es, $t_stop_desc_en, $t_add_image, $t_change_image, $t_remove_image ) {
              $title_es  = $s['title_es']  ?? '';
              $title_en  = $s['title_en']  ?? '';
              $desc_es   = $s['desc_es']   ?? '';
              $desc_en   = $s['desc_en']   ?? '';
              $image_id  = (int) ( $s['image_id']  ?? 0 );
              $image_url = $s['image_url'] ?? '';
              $is_start  = ! empty( $s['is_start'] );
              ?>
              <div class="amir-stop-row" style="border:1px solid #e1f5ee;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;">
                  <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#444;cursor:pointer;">
                    <input type="checkbox" class="amir-stop-is-start-cb" <?php checked( $is_start ); ?>
                           onchange="this.nextElementSibling.value = this.checked ? '1' : '0'" />
                    <input type="hidden" class="amir-stop-is-start" value="<?php echo $is_start ? '1' : '0'; ?>" />
                    <?php echo esc_html( $t_is_start ); ?>
                  </label>
                  <button type="button" onclick="this.closest('.amir-stop-row').remove()"
                          style="margin-left:auto;background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
                </div>
                <div style="display:flex;gap:10px;margin-bottom:8px;">
                  <input type="text" class="amir-stop-title-es" value="<?php echo esc_attr( $title_es ); ?>" placeholder="<?php echo esc_attr( $t_stop_title_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                  <input type="text" class="amir-stop-title-en" value="<?php echo esc_attr( $title_en ); ?>" placeholder="<?php echo esc_attr( $t_stop_title_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                </div>
                <div style="display:flex;gap:10px;margin-bottom:10px;">
                  <textarea class="amir-stop-desc-es" rows="2" placeholder="<?php echo esc_attr( $t_stop_desc_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $desc_es ); ?></textarea>
                  <textarea class="amir-stop-desc-en" rows="2" placeholder="<?php echo esc_attr( $t_stop_desc_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $desc_en ); ?></textarea>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                  <input type="hidden" class="amir-stop-image-id" value="<?php echo $image_id ?: ''; ?>" />
                  <div class="amir-stop-image-preview" style="width:56px;height:56px;border-radius:6px;overflow:hidden;background:#f8fdfb;border:1px solid #e1f5ee;flex-shrink:0;<?php echo $image_url ? '' : 'display:none;'; ?>">
                    <?php if ( $image_url ) : ?><img src="<?php echo esc_url( $image_url ); ?>" style="width:100%;height:100%;object-fit:cover;" /><?php endif; ?>
                  </div>
                  <button type="button" class="amir-stop-image-btn"
                          style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">
                    🖼 <?php echo esc_html( $image_url ? $t_change_image : $t_add_image ); ?>
                  </button>
                  <button type="button" class="amir-stop-image-remove-btn" style="<?php echo $image_url ? '' : 'display:none;'; ?>background:transparent;color:#e24b4a;border:none;cursor:pointer;font-size:12px;"><?php echo esc_html( $t_remove_image ); ?></button>
                </div>
              </div>
              <?php
          };

          if ( empty( $stops ) ) {
              // Sin filas por defecto — a diferencia de horarios/addons, acá
              // una fila vacía por defecto significaría que CADA tour nuevo
              // arranca "con itinerario" (aunque vacío), rompiendo la regla
              // de "opcional por tour, si no hay paradas no se muestra".
          } else {
              foreach ( $stops as $s ) {
                  $render_stop_row( $s );
              }
          }
          ?>
        </div>
        <button type="button" id="amir-add-stop-btn"
                style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">
          + <?php _e( 'Agregar parada', 'amir-booking' ); ?>
        </button>

        <?php
        // Bug real (2026-08-20): con varios puntos cargados, el resto del
        // editor (horarios, addons, datos destacados, etc.) ya suma cientos
        // de campos de formulario — al agregar más paradas (6 inputs c/u,
        // sin `name`, se sumaban al final del form) se cruzaba el límite de
        // `max_input_vars` de PHP, que descarta variables de más EN
        // SILENCIO. Como el itinerario es la última metabox registrada
        // (línea ~266), sus campos eran los primeros en truncarse — el
        // operador cargaba 2-3 paradas y no quedaba ninguna guardada, sin
        // ningún error visible. Corregido serializando TODAS las paradas a
        // un único campo JSON (inmune a esa cuenta de variables, sin
        // importar cuántas paradas haya) justo antes de enviar el form —
        // los inputs de cada fila ya no llevan `name`, son solo UI.
        ?>
        <input type="hidden" id="amir-stops-json" name="amir_itinerary_stops_json"
               value="<?php echo esc_attr( wp_json_encode( $stops, JSON_UNESCAPED_UNICODE ) ); ?>" />

        <script>
        (function(){
          function stopRowHtml(){
            return '<div class="amir-stop-row" style="border:1px solid #e1f5ee;border-radius:8px;padding:14px;margin-bottom:10px;">'
              + '<div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;">'
              +   '<label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#444;cursor:pointer;">'
              +     '<input type="checkbox" class="amir-stop-is-start-cb" onchange="this.nextElementSibling.value = this.checked ? \'1\' : \'0\'" />'
              +     '<input type="hidden" class="amir-stop-is-start" value="0" />'
              +     '<?php echo esc_js( $t_is_start ); ?>'
              +   '</label>'
              +   '<button type="button" onclick="this.closest(\'.amir-stop-row\').remove()" style="margin-left:auto;background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>'
              + '</div>'
              + '<div style="display:flex;gap:10px;margin-bottom:8px;">'
              +   '<input type="text" class="amir-stop-title-es" placeholder="<?php echo esc_js( $t_stop_title_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
              +   '<input type="text" class="amir-stop-title-en" placeholder="<?php echo esc_js( $t_stop_title_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
              + '</div>'
              + '<div style="display:flex;gap:10px;margin-bottom:10px;">'
              +   '<textarea class="amir-stop-desc-es" rows="2" placeholder="<?php echo esc_js( $t_stop_desc_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"></textarea>'
              +   '<textarea class="amir-stop-desc-en" rows="2" placeholder="<?php echo esc_js( $t_stop_desc_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"></textarea>'
              + '</div>'
              + '<div style="display:flex;align-items:center;gap:10px;">'
              +   '<input type="hidden" class="amir-stop-image-id" value="" />'
              +   '<div class="amir-stop-image-preview" style="width:56px;height:56px;border-radius:6px;overflow:hidden;background:#f8fdfb;border:1px solid #e1f5ee;flex-shrink:0;display:none;"></div>'
              +   '<button type="button" class="amir-stop-image-btn" style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">🖼 <?php echo esc_js( $t_add_image ); ?></button>'
              +   '<button type="button" class="amir-stop-image-remove-btn" style="display:none;background:transparent;color:#e24b4a;border:none;cursor:pointer;font-size:12px;"><?php echo esc_js( $t_remove_image ); ?></button>'
              + '</div>'
              + '</div>';
          }

          document.getElementById('amir-add-stop-btn').addEventListener('click', function(){
            document.getElementById('amir-stops-wrap').insertAdjacentHTML('beforeend', stopRowHtml());
          });

          // Selector de imagen por fila — delegado, un solo frame de wp.media reusado.
          var frame;
          document.getElementById('amir-stops-wrap').addEventListener('click', function(e){
            var row = e.target.closest('.amir-stop-row');
            if (!row) return;

            if (e.target.classList.contains('amir-stop-image-btn')) {
              frame = wp.media({ title: '<?php echo esc_js( __( 'Imagen de la parada', 'amir-booking' ) ); ?>', multiple: false, library: { type: 'image' } });
              frame.on('select', function(){
                var att = frame.state().get('selection').first().attributes;
                row.querySelector('.amir-stop-image-id').value = att.id;
                var preview = row.querySelector('.amir-stop-image-preview');
                preview.style.display = '';
                preview.innerHTML = '<img src="' + (att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;">';
                row.querySelector('.amir-stop-image-btn').textContent = '🖼 Cambiar imagen';
                row.querySelector('.amir-stop-image-remove-btn').style.display = '';
              });
              frame.open();
            }

            if (e.target.classList.contains('amir-stop-image-remove-btn')) {
              row.querySelector('.amir-stop-image-id').value = '';
              var preview2 = row.querySelector('.amir-stop-image-preview');
              preview2.style.display = 'none';
              preview2.innerHTML = '';
              row.querySelector('.amir-stop-image-btn').textContent = '🖼 Agregar imagen (opcional)';
              e.target.style.display = 'none';
            }
          });

          // Serializa todas las filas a un único JSON justo antes de enviar
          // el form del post — ver comentario más arriba (bug real 2026-08-20).
          var postForm = document.getElementById('post');
          if ( postForm ) {
            postForm.addEventListener('submit', function(){
              var stops = [];
              document.querySelectorAll('#amir-stops-wrap .amir-stop-row').forEach(function(row){
                var titleEs = row.querySelector('.amir-stop-title-es').value.trim();
                var titleEn = row.querySelector('.amir-stop-title-en').value.trim();
                if ( ! titleEs && ! titleEn ) return;
                stops.push({
                  title_es:  titleEs,
                  title_en:  titleEn,
                  desc_es:   row.querySelector('.amir-stop-desc-es').value,
                  desc_en:   row.querySelector('.amir-stop-desc-en').value,
                  image_id:  row.querySelector('.amir-stop-image-id').value || '',
                  is_start:  row.querySelector('.amir-stop-is-start').value === '1',
                });
              });
              document.getElementById('amir-stops-json').value = JSON.stringify(stops);
            });
          }
        })();
        </script>
        <?php
    }

    // ── Meta Box: FAQ ────────────────────────────────────────────────────

    /**
     * Preguntas frecuentes opcionales por tour (pedido del cliente
     * 2026-08-25, a partir del bloque "Quick Questions" de la landing de
     * Sicilia Mia — "por tour", no un FAQ global de la web). Mismo patrón
     * de filas repetibles + JSON único que meta_box_itinerary() — un solo
     * campo oculto serializado justo antes de enviar el form, inmune al
     * límite de max_input_vars que ya afectó a itinerario (§ 13.1).
     */
    public function meta_box_faq( \WP_Post $post ): void {
        $items = json_decode( get_post_meta( $post->ID, '_amir_faq_items', true ) ?: '[]', true );
        if ( ! is_array( $items ) ) {
            $items = [];
        }
        $t_q_es = __( 'Pregunta (ES)', 'amir-booking' );
        $t_q_en = __( 'Question (EN)', 'amir-booking' );
        $t_a_es = __( 'Respuesta (ES)', 'amir-booking' );
        $t_a_en = __( 'Answer (EN)', 'amir-booking' );
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 14px;">
          <?php _e( 'Opcional — si no cargas preguntas, esta sección no se muestra en la página del tour. Útil para dudas puntuales de ESTE tour (ej. "¿Puedo ir solo?", "¿Qué pasa si llueve?") — para algo válido en todo el sitio, mejor una página de FAQ aparte.', 'amir-booking' ); ?>
        </p>

        <div id="amir-faq-wrap">
          <?php
          $render_faq_row = function ( $item = null ) use ( $t_q_es, $t_q_en, $t_a_es, $t_a_en ) {
              $q_es = $item['question_es'] ?? '';
              $q_en = $item['question_en'] ?? '';
              $a_es = $item['answer_es']   ?? '';
              $a_en = $item['answer_en']   ?? '';
              ?>
              <div class="amir-faq-row" style="border:1px solid #e1f5ee;border-radius:8px;padding:14px;margin-bottom:10px;">
                <div style="display:flex;justify-content:flex-end;margin-bottom:6px;">
                  <button type="button" onclick="this.closest('.amir-faq-row').remove()"
                          style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
                </div>
                <div style="display:flex;gap:10px;margin-bottom:8px;">
                  <input type="text" class="amir-faq-q-es" value="<?php echo esc_attr( $q_es ); ?>" placeholder="<?php echo esc_attr( $t_q_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                  <input type="text" class="amir-faq-q-en" value="<?php echo esc_attr( $q_en ); ?>" placeholder="<?php echo esc_attr( $t_q_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />
                </div>
                <div style="display:flex;gap:10px;">
                  <textarea class="amir-faq-a-es" rows="2" placeholder="<?php echo esc_attr( $t_a_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $a_es ); ?></textarea>
                  <textarea class="amir-faq-a-en" rows="2" placeholder="<?php echo esc_attr( $t_a_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea( $a_en ); ?></textarea>
                </div>
              </div>
              <?php
          };

          // Sin filas por defecto a propósito — mismo criterio que itinerario:
          // una fila vacía de entrada haría que cada tour nuevo "tenga FAQ"
          // (aunque vacío), rompiendo la regla de opcional-por-tour.
          foreach ( $items as $item ) {
              $render_faq_row( $item );
          }
          ?>
        </div>
        <button type="button" id="amir-add-faq-btn"
                style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">
          + <?php _e( 'Agregar pregunta', 'amir-booking' ); ?>
        </button>

        <input type="hidden" id="amir-faq-json" name="amir_faq_items_json"
               value="<?php echo esc_attr( wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ); ?>" />

        <script>
        (function(){
          function faqRowHtml(){
            return '<div class="amir-faq-row" style="border:1px solid #e1f5ee;border-radius:8px;padding:14px;margin-bottom:10px;">'
              + '<div style="display:flex;justify-content:flex-end;margin-bottom:6px;">'
              +   '<button type="button" onclick="this.closest(\'.amir-faq-row\').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>'
              + '</div>'
              + '<div style="display:flex;gap:10px;margin-bottom:8px;">'
              +   '<input type="text" class="amir-faq-q-es" placeholder="<?php echo esc_js( $t_q_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
              +   '<input type="text" class="amir-faq-q-en" placeholder="<?php echo esc_js( $t_q_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;" />'
              + '</div>'
              + '<div style="display:flex;gap:10px;">'
              +   '<textarea class="amir-faq-a-es" rows="2" placeholder="<?php echo esc_js( $t_a_es ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"></textarea>'
              +   '<textarea class="amir-faq-a-en" rows="2" placeholder="<?php echo esc_js( $t_a_en ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;box-sizing:border-box;"></textarea>'
              + '</div>'
              + '</div>';
          }

          document.getElementById('amir-add-faq-btn').addEventListener('click', function(){
            document.getElementById('amir-faq-wrap').insertAdjacentHTML('beforeend', faqRowHtml());
          });

          var postForm = document.getElementById('post');
          if ( postForm ) {
            postForm.addEventListener('submit', function(){
              var items = [];
              document.querySelectorAll('#amir-faq-wrap .amir-faq-row').forEach(function(row){
                var qEs = row.querySelector('.amir-faq-q-es').value.trim();
                var qEn = row.querySelector('.amir-faq-q-en').value.trim();
                if ( ! qEs && ! qEn ) return;
                items.push({
                  question_es: qEs,
                  question_en: qEn,
                  answer_es:   row.querySelector('.amir-faq-a-es').value,
                  answer_en:   row.querySelector('.amir-faq-a-en').value,
                });
              });
              document.getElementById('amir-faq-json').value = JSON.stringify(items);
            });
          }
        })();
        </script>
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
          <?php _e( 'La <strong>imagen destacada</strong> (panel derecho) es la foto principal del tour.<br>Aquí agrega las fotos de la galería.', 'amir-booking' ); ?>
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
            frame = wp.media({ title: '<?php echo esc_js( __( 'Galería del tour', 'amir-booking' ) ); ?>', multiple: true, library: { type: 'image' } });
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

        <hr style="margin:20px 0;border:none;border-top:1px solid #e1f5ee;">
        <div class="amir-field">
          <label><?php _e('Video (YouTube o Vimeo) — opcional', 'amir-booking'); ?></label>

          <input type="url" name="amir_video_url" value="<?php echo esc_attr( $m['video_url'] ); ?>" placeholder="https://www.youtube.com/watch?v=... o https://vimeo.com/..." />
          <p class="amir-hint"><?php _e('Aparece como una foto más al principio de la galería, con un ícono de play — el video no carga hasta que el visitante hace clic (§ 16.11/16.46 CONTRIBUTING.md, mismo patrón que el video de habitaciones en Pro Max). Disponible en las tres ediciones.', 'amir-booking'); ?></p>

        </div>
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

        $price_model  = get_post_meta( $post->ID, '_amir_price_model',  true ) ?: 'percapita';
        $has_provider = (int) get_post_meta( $post->ID, '_amir_provider_id', true ) > 0;
        $max_capacity = (int) get_post_meta( $post->ID, '_amir_max_capacity', true ) ?: 10;
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 14px;">
          <?php _e( 'Se guardan al publicar/actualizar el tour — no hace falta un botón aparte.', 'amir-booking' ); ?>
        </p>

        <div class="amir-field" style="max-width:360px;margin-bottom:18px;">
          <label><?php _e('Modelo de precio', 'amir-booking'); ?></label>
          <select name="amir_price_model" id="amir-price-model-select">
            <option value="percapita" <?php selected($price_model,'percapita'); ?>><?php _e( 'Por persona (adulto / niño / bebé)', 'amir-booking' ); ?></option>
            <option value="group"     <?php selected($price_model,'group');     ?>><?php _e( 'Precio fijo por grupo (privado)', 'amir-booking' ); ?></option>
          </select>
          <p class="amir-hint"><?php _e('Determina qué campos de precio de abajo aplican.', 'amir-booking'); ?></p>

        </div>

        <!-- Horarios -->
        <div style="font-weight:700;font-size:13px;color:#1D9E75;margin-bottom:4px;">🕐 <?php _e( 'Horarios', 'amir-booking' ); ?></div>
        <p style="font-size:12px;color:#888;margin:0 0 10px;">
          <?php _e( 'Opcional — dejá esta lista vacía si el tour <strong>no</strong> tiene un horario de salida fijo (se reserva sin horario asignado). Si cargás uno o más, el cliente elige entre ellos al reservar.', 'amir-booking' ); ?>
        </p>
        <div id="amir-schedules-wrap">
          <?php if ( empty($schedules) ) : ?>
            <div class="amir-schedule-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
              <input type="time" name="amir_schedule_start[]" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <span style="font-size:12px;color:#666;">a</span>
              <input type="time" name="amir_schedule_end[]"   style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_es[]" placeholder="<?php esc_attr_e( 'Ej: Salida amanecer', 'amir-booking' ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_en[]" placeholder="Ex: Sunrise departure" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <button type="button" onclick="this.closest('.amir-schedule-row').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
            </div>
          <?php else : foreach ( $schedules as $s ) : ?>
            <div class="amir-schedule-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
              <input type="hidden" name="amir_schedule_db_id[]" value="<?php echo $s->id; ?>" />
              <input type="time" name="amir_schedule_start[]"    value="<?php echo esc_attr($s->time_start); ?>" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <span style="font-size:12px;color:#666;"><?php echo esc_html_x( 'a', 'entre hora de inicio y fin de un horario, ej. "6:00 a 9:00"', 'amir-booking' ); ?></span>
              <input type="time" name="amir_schedule_end[]"      value="<?php echo esc_attr($s->time_end); ?>"   style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_es[]" value="<?php echo esc_attr($s->label_es); ?>" placeholder="<?php esc_attr_e( 'Salida amanecer', 'amir-booking' ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <input type="text" name="amir_schedule_label_en[]" value="<?php echo esc_attr($s->label_en); ?>" placeholder="Sunrise departure" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
              <button type="button" onclick="this.closest('.amir-schedule-row').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <button type="button" id="amir-add-schedule-btn"
                style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;margin-bottom:8px;">
          + <?php _e('Agregar horario', 'amir-booking'); ?>
        </button>

        <!-- Precios per-capita -->
        <div id="amir-prices-percapita" style="<?php echo $price_model === 'group' ? 'display:none' : ''; ?>">
          <div style="font-weight:700;font-size:13px;color:#1D9E75;margin-bottom:8px;">💲 <?php printf( __( 'Precios por persona (%s)', 'amir-booking' ), esc_html( \AmirBooking\Core\Currency::code() ) ); ?></div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            <?php
            $types = [
                'adult' => __( 'Adulto (13+)', 'amir-booking' ),
                'child' => __( 'Niño (4–12)', 'amir-booking' ),
                'baby'  => __( 'Bebé (0–3) — 0 = gratis', 'amir-booking' ),
            ];
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
                  <input type="number" name="amir_price_<?php echo $type; ?>" id="amir-price-input-<?php echo $type; ?>"
                         value="<?php echo esc_attr($price_val); ?>" class="amir-price-margin-input" data-type="<?php echo $type; ?>"
                         min="0" step="0.01" placeholder="0.00"
                         style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 8px 7px 20px;font-size:13px;box-sizing:border-box;" />
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Costo del proveedor (solo si el tour tiene proveedor asignado, ver meta_box_main) -->
        <div id="amir-provider-cost-percapita" style="<?php echo ( $price_model === 'group' || ! $has_provider ) ? 'display:none' : ''; ?>margin-top:14px;">
          <div style="font-weight:700;font-size:13px;color:#BA7517;margin-bottom:8px;">💰 <?php printf( __( 'Costo del proveedor por persona (%s)', 'amir-booking' ), esc_html( \AmirBooking\Core\Currency::code() ) ); ?></div>
          <p style="font-size:11px;color:#888;margin:0 0 8px;"><?php _e( 'Lo que TourFlow le paga al proveedor — separado del precio de venta de arriba.', 'amir-booking' ); ?></p>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
            <?php foreach ( $types as $type => $label ) :
              $cost_row = array_filter( $prices, fn($p) => $p->person_type === $type && ! $p->schedule_id );
              $cost_val = $cost_row ? (float) reset( $cost_row )->provider_cost_mxn : '';
            ?>
              <div class="amir-field" style="margin-bottom:0;">
                <label style="font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;color:#444;">
                  <?php echo esc_html($label); ?>
                </label>
                <div style="position:relative;">
                  <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:13px;color:#888;">$</span>
                  <input type="number" name="amir_cost_<?php echo $type; ?>" id="amir-cost-input-<?php echo $type; ?>"
                         value="<?php echo esc_attr($cost_val); ?>" class="amir-price-margin-input" data-type="<?php echo $type; ?>"
                         min="0" step="0.01" placeholder="0.00"
                         style="width:100%;border:1px solid #fde8c8;border-radius:6px;padding:7px 8px 7px 20px;font-size:13px;box-sizing:border-box;" />
                </div>
                <div class="amir-margin-display" id="amir-margin-<?php echo $type; ?>" style="font-size:11px;color:#5a7068;margin-top:4px;"><?php printf( __( 'Margen: %s', 'amir-booking' ), '—' ); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Precios grupo -->
        <?php $group_ranges = self::group_price_ranges( $max_capacity ); ?>
        <div id="amir-prices-group" style="<?php echo $price_model === 'percapita' ? 'display:none' : ''; ?>">
          <div style="font-weight:700;font-size:13px;color:#1D9E75;margin-bottom:8px;">💲 <?php printf( __( 'Precios por grupo (%s)', 'amir-booking' ), esc_html( \AmirBooking\Core\Currency::code() ) ); ?></div>
          <div style="display:grid;grid-template-columns:repeat(<?php echo min(4, count($group_ranges)); ?>,1fr);gap:12px;">
            <?php
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
                  <input type="number" name="amir_price_group_<?php echo $gmin; ?>_<?php echo $gmax; ?>" id="amir-price-group-input-<?php echo $gmin; ?>-<?php echo $gmax; ?>"
                         value="<?php echo esc_attr($gv); ?>" class="amir-price-margin-input" data-group="<?php echo $gmin; ?>-<?php echo $gmax; ?>"
                         min="0" step="0.01" placeholder="0.00"
                         style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 8px 7px 20px;font-size:13px;box-sizing:border-box;" />
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Costo del proveedor — modelo grupo -->
        <div id="amir-provider-cost-group" style="<?php echo ( $price_model === 'percapita' || ! $has_provider ) ? 'display:none' : ''; ?>margin-top:14px;">
          <div style="font-weight:700;font-size:13px;color:#BA7517;margin-bottom:8px;">💰 Costo del proveedor por grupo (<?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?>)</div>
          <p style="font-size:11px;color:#888;margin:0 0 8px;">Lo que TourFlow le paga al proveedor — separado del precio de venta de arriba.</p>
          <div style="display:grid;grid-template-columns:repeat(<?php echo min(4, count($group_ranges)); ?>,1fr);gap:12px;">
            <?php foreach ( $group_ranges as [ $gmin, $gmax, $glabel ] ) :
              $gcp = array_filter( $prices, fn($p) => $p->person_type === 'group' && (int)$p->group_min === $gmin );
              $gcv = $gcp ? (float) reset( $gcp )->provider_cost_mxn : '';
            ?>
              <div class="amir-field" style="margin-bottom:0;">
                <label style="font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;color:#444;">
                  <?php echo esc_html($glabel); ?>
                </label>
                <div style="position:relative;">
                  <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:13px;color:#888;">$</span>
                  <input type="number" name="amir_cost_group_<?php echo $gmin; ?>_<?php echo $gmax; ?>" id="amir-cost-group-input-<?php echo $gmin; ?>-<?php echo $gmax; ?>"
                         value="<?php echo esc_attr($gcv); ?>" class="amir-price-margin-input" data-group="<?php echo $gmin; ?>-<?php echo $gmax; ?>"
                         min="0" step="0.01" placeholder="0.00"
                         style="width:100%;border:1px solid #fde8c8;border-radius:6px;padding:7px 8px 7px 20px;font-size:13px;box-sizing:border-box;" />
                </div>
                <div class="amir-margin-display" id="amir-margin-group-<?php echo $gmin; ?>-<?php echo $gmax; ?>" style="font-size:11px;color:#5a7068;margin-top:4px;"><?php printf( __( 'Margen: %s', 'amir-booking' ), '—' ); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <script>
        document.querySelector('[name="amir_price_model"]')?.addEventListener('change', function(){
          var hasProvider = document.getElementById('amir-provider-id-select')?.value !== '';
          document.getElementById('amir-prices-percapita').style.display = this.value === 'percapita' ? '' : 'none';
          document.getElementById('amir-prices-group').style.display     = this.value === 'group'     ? '' : 'none';
          document.getElementById('amir-provider-cost-percapita').style.display = ( this.value === 'percapita' && hasProvider ) ? '' : 'none';
          document.getElementById('amir-provider-cost-group').style.display     = ( this.value === 'group'     && hasProvider ) ? '' : 'none';
        });

        document.getElementById('amir-provider-id-select')?.addEventListener('change', function(){
          var priceModel = document.querySelector('[name="amir_price_model"]')?.value || 'percapita';
          var hasProvider = this.value !== '';
          document.getElementById('amir-provider-cost-percapita').style.display = ( priceModel === 'percapita' && hasProvider ) ? '' : 'none';
          document.getElementById('amir-provider-cost-group').style.display     = ( priceModel === 'group'     && hasProvider ) ? '' : 'none';
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

        // Margen (venta − costo) calculado en vivo — antes el operador tenía
        // que restar a mano entre dos grids separados (precio arriba, costo
        // de proveedor abajo). Pares generados en PHP porque las bandas de
        // grupo son dinámicas según la capacidad máxima del tour.
        (function(){
          var pairs = <?php echo wp_json_encode( array_merge(
              array_map( fn( $type ) => [ "amir-price-input-{$type}", "amir-cost-input-{$type}", "amir-margin-{$type}" ], array_keys( $types ) ),
              array_map( fn( $r ) => [ "amir-price-group-input-{$r[0]}-{$r[1]}", "amir-cost-group-input-{$r[0]}-{$r[1]}", "amir-margin-group-{$r[0]}-{$r[1]}" ], $group_ranges )
          ) ); ?>;

          function fmtMoney( n ) {
            return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          }

          function recalc( priceId, costId, marginId ) {
            var priceEl = document.getElementById(priceId);
            var costEl  = document.getElementById(costId);
            var marginEl = document.getElementById(marginId);
            if ( ! priceEl || ! costEl || ! marginEl ) return;
            var price  = parseFloat(priceEl.value) || 0;
            var cost   = parseFloat(costEl.value)  || 0;
            var margin = price - cost;
            var pct    = price > 0 ? ' (' + Math.round(margin / price * 100) + '%)' : '';
            marginEl.textContent  = '<?php echo esc_js( sprintf( __( 'Margen: %s', 'amir-booking' ), '' ) ); ?>' + fmtMoney(margin) + pct;
            marginEl.style.color  = margin < 0 ? '#e24b4a' : '#5a7068';
          }

          pairs.forEach( function( p ) {
            var priceEl = document.getElementById(p[0]);
            var costEl  = document.getElementById(p[1]);
            if ( priceEl ) priceEl.addEventListener('input', function(){ recalc(p[0], p[1], p[2]); });
            if ( costEl  ) costEl.addEventListener('input', function(){ recalc(p[0], p[1], p[2]); });
            recalc( p[0], p[1], p[2] );
          } );
        })();
        </script>
        <?php
    }

    // ── Meta Box: Servicios extra ─────────────────────────────────────────

    public function meta_box_addons( \WP_Post $post ): void {
        global $wpdb;
        $tour_db_id = (int) get_post_meta( $post->ID, '_amir_tour_db_id', true );

        $addons = $tour_db_id
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_addons WHERE tour_id=%d ORDER BY sort_order, id",
                $tour_db_id ) )
            : [];
        $t_per_unit    = __( 'Por unidad', 'amir-booking' );
        $t_flat        = __( 'Extra general', 'amir-booking' );
        $t_name_es_ph  = __( 'Nombre (ES)', 'amir-booking' );
        $t_name_en_ph  = __( 'Name (EN)', 'amir-booking' );
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 14px;">
          <?php _e( 'Extras opcionales que el cliente puede sumar al reservar (alquiler de equipo, cena, etc.). <strong>Por unidad</strong>: el cliente elige cuántos quiere (tope = personas de la reserva). <strong>Extra general</strong>: precio fijo, se agrega o no, sin cantidad.', 'amir-booking' ); ?>
        </p>

        <div id="amir-addons-wrap">
          <?php
          // Sin checkbox de "activo": igual que horarios, la fila presente
          // en el form = activa; borrarla con ✕ la desactiva/elimina al
          // guardar (sync_addons() borra lo que ya no está en el POST).
          $render_addon_row = function ( $a = null ) use ( $t_per_unit, $t_flat ) {
              $db_id = $a->id ?? '';
              $type  = $a->pricing_type ?? 'per_unit';
              $es    = $a->name_es ?? '';
              $en    = $a->name_en ?? '';
              $price = $a->price_mxn ?? '';
              ?>
              <div class="amir-addon-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
                <input type="hidden" name="amir_addon_db_id[]" value="<?php echo esc_attr( $db_id ); ?>" />
                <select name="amir_addon_pricing_type[]" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;">
                  <option value="per_unit" <?php selected( $type, 'per_unit' ); ?>><?php echo esc_html( $t_per_unit ); ?></option>
                  <option value="flat"     <?php selected( $type, 'flat' ); ?>><?php echo esc_html( $t_flat ); ?></option>
                </select>
                <input type="text" name="amir_addon_name_es[]" value="<?php echo esc_attr( $es ); ?>" placeholder="<?php esc_attr_e( 'Ej: Equipo de snorkel', 'amir-booking' ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
                <input type="text" name="amir_addon_name_en[]" value="<?php echo esc_attr( $en ); ?>" placeholder="Ex: Snorkel gear" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />
                <div style="position:relative;">
                  <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:13px;color:#888;">$</span>
                  <input type="number" name="amir_addon_price[]" value="<?php echo esc_attr( $price ); ?>" min="0" step="0.01" placeholder="0.00"
                         style="width:100px;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px 6px 20px;font-size:13px;box-sizing:border-box;" />
                </div>
                <button type="button" onclick="this.closest('.amir-addon-row').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>
              </div>
              <?php
          };

          if ( empty( $addons ) ) {
              $render_addon_row();
          } else {
              foreach ( $addons as $a ) {
                  $render_addon_row( $a );
              }
          }
          ?>
        </div>
        <button type="button" id="amir-add-addon-btn"
                style="background:transparent;color:#1D9E75;border:1px solid #1D9E75;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">
          + <?php _e( 'Agregar servicio', 'amir-booking' ); ?>
        </button>

        <script>
        document.getElementById('amir-add-addon-btn').addEventListener('click', function(){
          var row = '<div class="amir-addon-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">'
            + '<input type="hidden" name="amir_addon_db_id[]" value="" />'
            + '<select name="amir_addon_pricing_type[]" style="border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;">'
            + '<option value="per_unit"><?php echo esc_js( $t_per_unit ); ?></option><option value="flat"><?php echo esc_js( $t_flat ); ?></option></select>'
            + '<input type="text" name="amir_addon_name_es[]" placeholder="<?php echo esc_js( $t_name_es_ph ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />'
            + '<input type="text" name="amir_addon_name_en[]" placeholder="<?php echo esc_js( $t_name_en_ph ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px;font-size:13px;" />'
            + '<div style="position:relative;"><span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:13px;color:#888;">$</span>'
            + '<input type="number" name="amir_addon_price[]" min="0" step="0.01" placeholder="0.00" style="width:100px;border:1px solid #c3d9d0;border-radius:6px;padding:6px 8px 6px 20px;font-size:13px;box-sizing:border-box;" /></div>'
            + '<button type="button" onclick="this.closest(\'.amir-addon-row\').remove()" style="background:#fef2f2;color:#e24b4a;border:1px solid #fecaca;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;">✕</button>'
            + '</div>';
          document.getElementById('amir-addons-wrap').insertAdjacentHTML('beforeend', row);
        });
        </script>
        <?php
    }

    // ── Meta Box: Disponibilidad ──────────────────────────────────────────

    public function meta_box_avail( \WP_Post $post ): void {
        $m = $this->get_meta( $post->ID );
        ?>
        <p style="font-size:12px;color:#666;margin:0 0 12px;">
          <?php _e( 'Define qué días opera este tour. Las <strong>excepciones</strong> sobreescriben la regla base según su prioridad.', 'amir-booking' ); ?>
        </p>
        <div class="amir-field" style="margin-bottom:14px;">
          <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:6px;display:block;">
            <?php _e('Días operativos por defecto', 'amir-booking'); ?>
          </label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php
            $days = [
                _x( 'Do', 'abreviatura de domingo, 2 letras', 'amir-booking' ),
                _x( 'Lu', 'abreviatura de lunes, 2 letras', 'amir-booking' ),
                _x( 'Ma', 'abreviatura de martes, 2 letras', 'amir-booking' ),
                _x( 'Mi', 'abreviatura de miércoles, 2 letras', 'amir-booking' ),
                _x( 'Ju', 'abreviatura de jueves, 2 letras', 'amir-booking' ),
                _x( 'Vi', 'abreviatura de viernes, 2 letras', 'amir-booking' ),
                _x( 'Sá', 'abreviatura de sábado, 2 letras', 'amir-booking' ),
            ];
            $active_days = json_decode( $m['active_weekdays'] ?? '[1,2,3,4,5,6]', true );
            foreach ( $days as $i => $d ) : ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="amir_active_weekdays[]" value="<?php echo $i; ?>"
                       <?php checked( in_array($i, $active_days, false) ); ?>
                       style="accent-color:#1D9E75;" />
                <?php echo esc_html( $d ); ?>
              </label>
            <?php endforeach; ?>
          </div>
          <p style="font-size:11px;color:#888;margin-top:6px;"><?php printf( __( 'Las reglas de excepción se gestionan desde <strong>%s</strong>', 'amir-booking' ), 'TourFlow → Disponibilidad' ); ?></p>
        </div>
        <?php
    }

    // ── Meta Box: Punto de encuentro ──────────────────────────────────────

    public function meta_box_meeting( \WP_Post $post ): void {
        $m = $this->get_meta( $post->ID );
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Punto de encuentro (ES)', 'amir-booking'); ?></label>

            <textarea name="amir_meeting_point_es" rows="2" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['meeting_point_es']); ?></textarea>
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Meeting point (EN)', 'amir-booking'); ?></label>

            <textarea name="amir_meeting_point_en" rows="2" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($m['meeting_point_en']); ?></textarea>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Latitud', 'amir-booking'); ?></label>

            <input type="text" name="amir_meeting_lat" value="<?php echo esc_attr($m['meeting_lat']); ?>" placeholder="18.6849" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;" />
          </div>
          <div class="amir-field">
            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;display:block;"><?php _e('Longitud', 'amir-booking'); ?></label>

            <input type="text" name="amir_meeting_lng" value="<?php echo esc_attr($m['meeting_lng']); ?>" placeholder="-87.9789" style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;" />
          </div>
        </div>
        <p style="font-size:11px;color:#888;margin-top:8px;">💡 <?php _e( 'Para obtener coordenadas: abre Google Maps → clic derecho en la ubicación → copiar lat,lng', 'amir-booking' ); ?></p>
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
            '_amir_min_age_child'    => 'absint',
            '_amir_max_capacity'     => 'absint',
            '_amir_min_passengers'   => 'absint',
            '_amir_sort_order'       => 'absint',
            '_amir_languages'        => 'sanitize_text_field',
            '_amir_description_en'   => 'wp_kses_post',
            '_amir_what_to_expect_es'=> 'wp_kses_post',
            '_amir_what_to_expect_en'=> 'wp_kses_post',
            '_amir_email_extra_note_es' => 'sanitize_textarea_field',
            '_amir_email_extra_note_en' => 'sanitize_textarea_field',
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
            '_amir_fixed_date'         => 'sanitize_text_field',
            '_amir_provider_id'        => 'absint',
            '_amir_provider_charge_mode' => 'sanitize_key',
            '_amir_video_url'          => 'esc_url_raw',
        ];

        $map = [
            '_amir_name_en'           => 'amir_name_en',
            '_amir_price_model'       => 'amir_price_model',
            '_amir_duration_minutes'  => 'amir_duration_minutes',
            '_amir_min_age'           => 'amir_min_age',
            '_amir_min_age_child'     => 'amir_min_age_child',
            '_amir_max_capacity'      => 'amir_max_capacity',
            '_amir_min_passengers'    => 'amir_min_passengers',
            '_amir_sort_order'        => 'amir_sort_order',
            '_amir_languages'         => 'amir_languages',
            '_amir_description_en'    => 'amir_description_en',
            '_amir_what_to_expect_es' => 'amir_what_to_expect_es',
            '_amir_what_to_expect_en' => 'amir_what_to_expect_en',
            '_amir_email_extra_note_es' => 'amir_email_extra_note_es',
            '_amir_email_extra_note_en' => 'amir_email_extra_note_en',
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
            '_amir_fixed_date'         => 'amir_fixed_date',
            '_amir_provider_id'        => 'amir_provider_id',
            '_amir_provider_charge_mode' => 'amir_provider_charge_mode',
            '_amir_video_url'          => 'amir_video_url',
        ];

        // wp_unslash() antes de sanitizar — mismo bug real que
        // PersonalizationPage::save_settings() (2026-08-05): sin esto, punto
        // de encuentro/itinerario con una comilla se guarda corrupto.
        foreach ( $map as $meta_key => $post_key ) {
            $sanitizer = $fields[ $meta_key ] ?? 'sanitize_text_field';
            $value     = call_user_func( $sanitizer, wp_unslash( $_POST[ $post_key ] ?? '' ) );
            update_post_meta( $post_id, $meta_key, $value );
        }

        // Checkbox: ausente en $_POST cuando está destildado
        update_post_meta( $post_id, '_amir_wishlist_enabled', ! empty( $_POST['amir_wishlist_enabled'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_amir_allow_children', ! empty( $_POST['amir_allow_children'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_amir_allow_babies', ! empty( $_POST['amir_allow_babies'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_amir_featured', ! empty( $_POST['amir_featured'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_amir_hide_from_lists', ! empty( $_POST['amir_hide_from_lists'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_amir_hide_from_suggestions', ! empty( $_POST['amir_hide_from_suggestions'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_amir_request_only', ! empty( $_POST['amir_request_only'] ) ? '1' : '0' );
        // Sin sentido sin "Solo a pedido" activo — si se destilda request_only
        // con custom_quote todavía tildado, no queda huérfano.
        update_post_meta( $post_id, '_amir_custom_quote', ( ! empty( $_POST['amir_request_only'] ) && ! empty( $_POST['amir_custom_quote'] ) ) ? '1' : '0' );

        // Depósito parcial (Pro Max — el checkbox ni se renderiza fuera de
        // esa edición, así que en Lite/Pro esto siempre cae al '0' de
        // abajo). % acotado a 1-99 al guardar, no solo en el input.
        update_post_meta( $post_id, '_amir_deposit_enabled', ! empty( $_POST['amir_deposit_enabled'] ) ? '1' : '0' );
        $deposit_pct = (int) ( $_POST['amir_deposit_pct'] ?? 20 );
        update_post_meta( $post_id, '_amir_deposit_pct', (string) max( 1, min( 99, $deposit_pct ) ) );

        // Reserva directa (Pro Max) — saltea el flujo combinado con upsell
        // desde la ficha del tour, mismo criterio de gate que el depósito.
        update_post_meta( $post_id, '_amir_skip_upsell', ! empty( $_POST['amir_skip_upsell'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_amir_require_participant_names', ! empty( $_POST['amir_require_participant_names'] ) ? '1' : '0' );

        // Activos/days de la semana
        $weekdays = array_map( 'intval', $_POST['amir_active_weekdays'] ?? [] );
        update_post_meta( $post_id, '_amir_active_weekdays', json_encode( $weekdays ) );

        // Galería
        $gallery_ids = json_decode( sanitize_text_field( $_POST['amir_gallery_ids'] ?? '[]' ), true );
        update_post_meta( $post_id, '_amir_gallery_ids', json_encode( array_map('intval', $gallery_ids ?: []) ) );

        // Highlights / Includes / excludes (textarea → JSON array). wp_unslash()
        // + sanitize_text_field() por línea — antes no pasaba por ningún
        // sanitizador y sin unslash una comilla en cualquier línea (ej.
        // "children's rates") se guardaba corrupta (bug real 2026-08-05).
        foreach ( [ 'highlights_es','highlights_en','includes_es','includes_en','excludes_es','excludes_en' ] as $field ) {
            $raw   = wp_unslash( $_POST["amir_{$field}"] ?? '' );
            $lines = array_filter( array_map( fn( $l ) => sanitize_text_field( trim( $l ) ), explode( "\n", $raw ) ) );
            // JSON_UNESCAPED_UNICODE — bug real y grave 2026-08-12: sin este
            // flag, un emoji o una tilde ("ñ") se guardaba escapado a
            // \uXXXX, y en algún punto de la cadena de guardado se pelaban
            // los backslashes (mismo mecanismo que un wp_unslash() de más),
            // corrompiendo el texto a algo como "Espau00f1ol". Guardar UTF-8
            // real evita el problema de raíz. Ver CONTRIBUTING.md § 16.51.
            update_post_meta( $post_id, "_amir_{$field}", json_encode( array_values($lines), JSON_UNESCAPED_UNICODE ) );
        }

        $this->save_itinerary_stops( $post_id );
        $this->save_detail_facts( $post_id );
        $this->save_faq_items( $post_id );

        $this->save_extra_languages( $post_id );
    }

    /**
     * Paradas del itinerario tipo timeline — un único campo JSON armado por
     * JS antes de enviar el form (ver meta_box_itinerary()), NO arrays
     * paralelos por campo como horarios/servicios extra. Bug real
     * corregido 2026-08-20: con arrays paralelos (`amir_stop_title_es[]`
     * ×N, 6 campos por parada), cargar varias paradas sumaba al límite de
     * `max_input_vars` de PHP junto con el resto del editor (horarios,
     * addons, datos destacados) — el itinerario, al ser la última metabox
     * registrada, era el primero en truncarse EN SILENCIO, dejando el
     * array vacío sin ningún error visible. Un solo campo JSON es inmune a
     * ese límite sin importar cuántas paradas tenga el tour.
     */
    private function save_itinerary_stops( int $post_id ): void {
        $raw   = wp_unslash( $_POST['amir_itinerary_stops_json'] ?? '' );
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) ) {
            $items = [];
        }

        $stops = [];
        foreach ( $items as $item ) {
            $title_es = sanitize_text_field( $item['title_es'] ?? '' );
            $title_en = sanitize_text_field( $item['title_en'] ?? '' );
            if ( $title_es === '' && $title_en === '' ) {
                continue;
            }
            $image_id = absint( $item['image_id'] ?? 0 );
            $stops[] = [
                'title_es'  => $title_es,
                'title_en'  => $title_en,
                'desc_es'   => sanitize_textarea_field( $item['desc_es'] ?? '' ),
                'desc_en'   => sanitize_textarea_field( $item['desc_en'] ?? '' ),
                'image_id'  => $image_id,
                'image_url' => $image_id ? (string) wp_get_attachment_url( $image_id ) : '',
                'is_start'  => ! empty( $item['is_start'] ),
            ];
        }

        update_post_meta( $post_id, '_amir_itinerary_stops', wp_json_encode( $stops, JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * Datos destacados (ícono+título+detalle) — mismo patrón de filas
     * repetibles con arrays paralelos que itinerary_stops. Filas sin label
     * en ningún idioma se descartan silenciosamente.
     */
    private function save_detail_facts( int $post_id ): void {
        // wp_unslash() antes de sanitizar cada elemento — mismo bug real
        // que el resto de este archivo (2026-08-05).
        $icons     = wp_unslash( $_POST['amir_fact_icon']     ?? [] );
        $labels_es = wp_unslash( $_POST['amir_fact_label_es'] ?? [] );
        $labels_en = wp_unslash( $_POST['amir_fact_label_en'] ?? [] );
        $values_es = wp_unslash( $_POST['amir_fact_value_es'] ?? [] );
        $values_en = wp_unslash( $_POST['amir_fact_value_en'] ?? [] );

        $facts = [];
        foreach ( $labels_es as $i => $raw_label_es ) {
            $label_es = sanitize_text_field( $raw_label_es );
            $label_en = sanitize_text_field( $labels_en[ $i ] ?? '' );
            if ( $label_es === '' && $label_en === '' ) {
                continue;
            }
            $facts[] = [
                'icon'     => sanitize_text_field( $icons[ $i ] ?? '' ),
                'label_es' => $label_es,
                'label_en' => $label_en,
                'value_es' => sanitize_text_field( $values_es[ $i ] ?? '' ),
                'value_en' => sanitize_text_field( $values_en[ $i ] ?? '' ),
            ];
        }

        update_post_meta( $post_id, '_amir_detail_facts', wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * FAQ opcional por tour — mismo patrón de "un solo JSON serializado por
     * JS antes de enviar el form" que itinerary_stops(), ver meta_box_faq().
     */
    private function save_faq_items( int $post_id ): void {
        $raw   = wp_unslash( $_POST['amir_faq_items_json'] ?? '' );
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) ) {
            $items = [];
        }

        $faq = [];
        foreach ( $items as $item ) {
            $q_es = sanitize_text_field( $item['question_es'] ?? '' );
            $q_en = sanitize_text_field( $item['question_en'] ?? '' );
            if ( $q_es === '' && $q_en === '' ) {
                continue;
            }
            $faq[] = [
                'question_es' => $q_es,
                'question_en' => $q_en,
                'answer_es'   => sanitize_textarea_field( $item['answer_es'] ?? '' ),
                'answer_en'   => sanitize_textarea_field( $item['answer_en'] ?? '' ),
            ];
        }

        update_post_meta( $post_id, '_amir_faq_items', wp_json_encode( $faq, JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * Contenido de idiomas activos más allá de es/en — el operador los
     * agrega en Configuración → Idiomas, sin tocar código. Se guarda como
     * un JSON indexado por idioma en un meta aparte (content_i18n en la
     * tabla amir_tours). Se hace merge con lo existente en vez de
     * sobreescribir todo: si un idioma se desactiva temporalmente, su
     * contenido no se pierde, simplemente no se muestra ni se actualiza.
     */
    private function save_extra_languages( int $post_id ): void {
        $extra_langs = array_diff( \AmirBooking\Core\Languages::active(), [ 'es', 'en' ] );
        if ( empty( $extra_langs ) ) {
            return;
        }

        $existing = json_decode( get_post_meta( $post_id, '_amir_content_i18n', true ) ?: '{}', true ) ?: [];

        foreach ( $extra_langs as $lang ) {
            // wp_unslash() antes de sanitizar — mismo bug real que el resto
            // de este archivo (2026-08-05).
            $raw = wp_unslash( $_POST['amir_i18n'][ $lang ] ?? [] );
            if ( ! is_array( $raw ) ) {
                continue;
            }
            $highlights = array_values( array_filter( array_map( 'trim', explode( "\n", $raw['highlights'] ?? '' ) ) ) );
            $includes   = array_values( array_filter( array_map( 'trim', explode( "\n", $raw['includes'] ?? '' ) ) ) );
            $excludes   = array_values( array_filter( array_map( 'trim', explode( "\n", $raw['excludes'] ?? '' ) ) ) );

            $existing[ $lang ] = [
                'name'           => sanitize_text_field( $raw['name'] ?? '' ),
                'description'    => wp_kses_post( $raw['description'] ?? '' ),
                'what_to_expect' => wp_kses_post( $raw['what_to_expect'] ?? '' ),
                'meeting_point'  => sanitize_textarea_field( $raw['meeting_point'] ?? '' ),
                'highlights'     => $highlights,
                'includes'       => $includes,
                'excludes'       => $excludes,
                'itinerary'      => wp_kses_post( $raw['itinerary'] ?? '' ),
            ];
        }

        update_post_meta( $post_id, '_amir_content_i18n', wp_json_encode( $existing, JSON_UNESCAPED_UNICODE ) );
    }

    // ── Sincronización CPT → tabla amir_tours ────────────────────────────

    /**
     * Ver el comentario en register() — set_post_thumbnail() actualiza
     * _thumbnail_id fuera del ciclo normal de guardado del post. $meta_id
     * no se usa, pero la firma de estos hooks siempre lo manda primero.
     */
    public function maybe_resync_on_thumbnail_change( $meta_id, int $post_id, string $meta_key, $meta_value ): void {
        if ( $meta_key !== '_thumbnail_id' || get_post_type( $post_id ) !== self::POST_TYPE ) {
            return;
        }
        $post = get_post( $post_id );
        if ( $post ) {
            $this->sync_to_db( $post_id, $post );
        }
    }

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
            'email_extra_note_es' => get_post_meta( $post_id, '_amir_email_extra_note_es', true ) ?: '',
            'email_extra_note_en' => get_post_meta( $post_id, '_amir_email_extra_note_en', true ) ?: '',
            'highlights_es'      => get_post_meta( $post_id, '_amir_highlights_es', true ) ?: '[]',
            'highlights_en'      => get_post_meta( $post_id, '_amir_highlights_en', true ) ?: '[]',
            'duration_minutes'   => (int) get_post_meta( $post_id, '_amir_duration_minutes', true ),
            'min_age'            => (int) get_post_meta( $post_id, '_amir_min_age', true ),
            // '' (nunca guardado, tours creados antes de esta opción) debe
            // seguir admitiendo niños/bebés como siempre — solo un '0'
            // explícito (guardado por save_meta() cuando se destilda el
            // checkbox) los deshabilita. No usar `(int) get_post_meta(...)`
            // a secas acá: '' se castea a 0 y desactivaría por default
            // cualquier tour que todavía no pasó por el editor con esta
            // sección nueva.
            'allow_children'     => ( get_post_meta( $post_id, '_amir_allow_children', true ) === '' )
                ? 1 : (int) get_post_meta( $post_id, '_amir_allow_children', true ),
            'allow_babies'       => ( get_post_meta( $post_id, '_amir_allow_babies', true ) === '' )
                ? 1 : (int) get_post_meta( $post_id, '_amir_allow_babies', true ),
            'min_age_child'      => (int) get_post_meta( $post_id, '_amir_min_age_child', true ) ?: 4,
            'max_capacity'       => (int) get_post_meta( $post_id, '_amir_max_capacity', true ),
            'min_passengers'     => (int) get_post_meta( $post_id, '_amir_min_passengers', true ) ?: 1,
            'languages'          => json_encode( array_values($langs_arr), JSON_UNESCAPED_UNICODE ),
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
            'featured'           => (int) get_post_meta( $post_id, '_amir_featured', true ),
            'hide_from_lists'       => (int) get_post_meta( $post_id, '_amir_hide_from_lists', true ),
            'hide_from_suggestions' => (int) get_post_meta( $post_id, '_amir_hide_from_suggestions', true ),
            'wishlist_enabled'   => (int) get_post_meta( $post_id, '_amir_wishlist_enabled', true ),
            'wishlist_threshold' => (int) get_post_meta( $post_id, '_amir_wishlist_threshold', true ),
            'wishlist_date'      => get_post_meta( $post_id, '_amir_wishlist_date', true ) ?: null,
            'fixed_date'         => get_post_meta( $post_id, '_amir_fixed_date', true ) ?: null,
            'request_only'       => (int) get_post_meta( $post_id, '_amir_request_only', true ),
            'custom_quote'       => (int) get_post_meta( $post_id, '_amir_custom_quote', true ),
            'deposit_enabled'    => (int) get_post_meta( $post_id, '_amir_deposit_enabled', true ),
            'deposit_pct'        => (int) get_post_meta( $post_id, '_amir_deposit_pct', true ),
            'skip_upsell'        => (int) get_post_meta( $post_id, '_amir_skip_upsell', true ),
            'require_participant_names' => (int) get_post_meta( $post_id, '_amir_require_participant_names', true ),
            'content_i18n'       => get_post_meta( $post_id, '_amir_content_i18n', true ) ?: '{}',
            'itinerary_stops'    => get_post_meta( $post_id, '_amir_itinerary_stops', true ) ?: '[]',
            'detail_facts'       => get_post_meta( $post_id, '_amir_detail_facts', true ) ?: '[]',
            'faq_items'          => get_post_meta( $post_id, '_amir_faq_items', true ) ?: '[]',
            // NULL = tour propio (default) — 0 guardado por absint() cuando
            // el <select> queda en "— Tour propio —" se convierte a NULL acá,
            // no se persiste como 0 (provider_id es una FK lógica opcional).
            'provider_id'        => ( (int) get_post_meta( $post_id, '_amir_provider_id', true ) ) ?: null,
            // Cobro diferido (§ 11.0 CONTRIBUTING.md) — 'immediate' si el
            // meta todavía no existe (tours viejos, o marketplace apagado).
            'provider_charge_mode' => in_array( get_post_meta( $post_id, '_amir_provider_charge_mode', true ), [ 'immediate', 'on_approval' ], true )
                ? get_post_meta( $post_id, '_amir_provider_charge_mode', true )
                : 'immediate',
            // Categorías (§ 15.7 CONTRIBUTING.md) — la taxonomía amir_tour_category
            // ya existía en WP pero nunca se sincronizaba a esta tabla, que es
            // lo único que lee la API/shortcode. wp_get_post_terms() ya ve los
            // términos actuales acá: WordPress procesa tax_input dentro de
            // wp_insert_post(), antes de que corra cualquier hook save_post_*.
            'category_slugs'     => json_encode( wp_get_post_terms( $post_id, self::TAXONOMY, [ 'fields' => 'slugs' ] ) ?: [] ),
            // Video en la ficha (§ 16.46 CONTRIBUTING.md, pedido del cliente
            // 2026-08-10) — disponible en las tres ediciones a propósito, no
            // gateado por AMIR_EDITION. Solo la URL se guarda; el proveedor
            // (YouTube/Vimeo) se resuelve al renderizar, ver tour-data.php.
            'video_url'          => get_post_meta( $post_id, '_amir_video_url', true ) ?: null,
        ];

        if ( $db_id ) {
            $result = $wpdb->update( "{$wpdb->prefix}amir_tours", $data, [ 'id' => $db_id ] );
        } else {
            $result = $wpdb->insert( "{$wpdb->prefix}amir_tours", $data );
            $db_id  = $wpdb->insert_id;
            update_post_meta( $post_id, '_amir_tour_db_id', $db_id );
        }

        // $wpdb->update()/insert() devuelven false solo si MySQL rechazó la
        // query (no si "no había nada que cambiar") — antes esto se perdía
        // en silencio: el post quedaba "guardado" para WordPress mientras
        // amir_tours se desincronizaba sin que nadie se enterara. Ahora
        // queda un aviso visible en el propio editor del tour.
        if ( $result === false && $wpdb->last_error ) {
            set_transient( "amir_sync_error_{$post_id}", $wpdb->last_error, 5 * MINUTE_IN_SECONDS );
            error_log( sprintf(
                'Amir Booking: fallo al sincronizar el tour (post %d, amir_tours.id %d): %s',
                $post_id, $db_id, $wpdb->last_error
            ) );
        } else {
            delete_transient( "amir_sync_error_{$post_id}" );
        }

        // Sincronizar horarios y precios desde los meta boxes
        $this->sync_schedules_prices( $post_id, $db_id );

        // Sincronizar servicios extra (add-ons)
        $this->sync_addons( $post_id, $db_id );

        // Sincronizar regla base de disponibilidad (días activos)
        $this->sync_base_availability_rule( $post_id, $db_id );

        // Invalidar caches — un idioma por cada uno activo, no solo es/en
        // (si no, un tour editado queda con datos viejos hasta 10 min para
        // cualquier idioma 3+ que el operador haya activado).
        foreach ( \AmirBooking\Core\Languages::active() as $active_lang ) {
            delete_transient( "amir_tour_{$db_id}_{$active_lang}" );
            delete_transient( "amir_tours_list_{$active_lang}" );
        }
        // Las variantes filtradas de /tours (category/source/provider_id,
        // § 15.7/15.8) cachean bajo una clave hasheada — no vale la pena
        // reconstruir todas las combinaciones posibles acá, quedan frescas
        // solas a los 10 min (mismo TTL que el resto). /tour-categories sí
        // tiene una clave fija, esa se invalida siempre.
        delete_transient( 'amir_tour_categories' );
    }

    /**
     * A diferencia de sync_schedules_prices() (que lee $_POST sin chequear
     * nonce, comportamiento preexistente que no se toca acá), este método sí
     * exige el nonce del editor del tour. Es necesario porque sync_to_db()
     * también se dispara con un wp_update_post() "a pelo" desde otros lugares
     * del plugin (ej. "Publicar y notificar" de la lista de interés,
     * class-wishlist-page.php) que NO mandan los campos de este meta box —
     * sin este guard, esas llamadas borrarían todos los add-ons del tour.
     */
    private function sync_addons( int $post_id, int $tour_db_id ): void {
        if (
            ! isset( $_POST['amir_tour_nonce'] ) ||
            ! wp_verify_nonce( $_POST['amir_tour_nonce'], 'amir_tour_meta' )
        ) {
            return;
        }

        global $wpdb;

        $types    = $_POST['amir_addon_pricing_type'] ?? [];
        $names_es = $_POST['amir_addon_name_es']       ?? [];
        $names_en = $_POST['amir_addon_name_en']       ?? [];
        $prices   = $_POST['amir_addon_price']         ?? [];
        $db_ids   = $_POST['amir_addon_db_id']         ?? [];

        $processed_ids = [];

        foreach ( $names_es as $i => $name_es ) {
            $name_en = sanitize_text_field( $names_en[ $i ] ?? '' );
            $name_es = sanitize_text_field( $name_es );
            if ( $name_es === '' && $name_en === '' ) {
                continue; // fila vacía, se ignora
            }

            $addon_data = [
                'tour_id'      => $tour_db_id,
                'pricing_type' => in_array( $types[ $i ] ?? '', [ 'per_unit', 'flat' ], true ) ? $types[ $i ] : 'per_unit',
                'name_es'      => $name_es,
                'name_en'      => $name_en,
                'price_mxn'    => max( 0, (float) ( $prices[ $i ] ?? 0 ) ),
                'active'       => 1,
                'sort_order'   => $i,
            ];

            $existing_id = isset( $db_ids[ $i ] ) ? (int) $db_ids[ $i ] : 0;
            if ( $existing_id ) {
                $wpdb->update( "{$wpdb->prefix}amir_addons", $addon_data, [ 'id' => $existing_id ] );
                $processed_ids[] = $existing_id;
            } else {
                $wpdb->insert( "{$wpdb->prefix}amir_addons", $addon_data );
                $processed_ids[] = $wpdb->insert_id;
            }
        }

        // Borrar los que ya no están en el form (se sacaron con el botón ✕)
        if ( ! empty( $processed_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $processed_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}amir_addons WHERE tour_id=%d AND id NOT IN ($placeholders)",
                array_merge( [ $tour_db_id ], $processed_ids )
            ) );
        } else {
            $wpdb->delete( "{$wpdb->prefix}amir_addons", [ 'tour_id' => $tour_db_id ] );
        }
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
        $price_model  = get_post_meta( $post_id, '_amir_price_model',  true );
        $max_capacity = (int) get_post_meta( $post_id, '_amir_max_capacity', true ) ?: 10;

        // Limpiar precios existentes y reinsertar — SOLO las filas "genéricas"
        // que este editor gestiona (sin horario ni temporada asignados).
        // PricingEngine ya soporta precios por horario específico y por
        // temporada (schedule_id / valid_from / valid_until, ver
        // PricesController), pero este editor no los expone todavía — un
        // DELETE sin filtrar los borraría en silencio en cada guardado, aun
        // sin haber sido tocados acá (bug real detectado 2026-08-08, nunca
        // llegó a morder porque hoy nada crea esas filas, pero es una trampa
        // si algún día se construye esa UI).
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}amir_prices
             WHERE tour_id = %d AND schedule_id IS NULL AND valid_from IS NULL AND valid_until IS NULL",
            $tour_db_id
        ) );

        if ( $price_model === 'percapita' ) {
            foreach ( ['adult','child','baby'] as $type ) {
                $price_val = (float)( $_POST["amir_price_{$type}"] ?? 0 );
                if ( $price_val >= 0 ) {
                    $wpdb->insert( "{$wpdb->prefix}amir_prices", [
                        'tour_id'           => $tour_db_id,
                        'person_type'       => $type,
                        'price_mxn'         => $price_val,
                        'provider_cost_mxn' => max( 0, (float) ( $_POST["amir_cost_{$type}"] ?? 0 ) ),
                    ] );
                }
            }
        } else {
            // Group pricing — bandas derivadas de max_capacity, ver
            // group_price_ranges() (antes fijas en [1,2],[3],[4]).
            foreach ( self::group_price_ranges( $max_capacity ) as [ $gmin, $gmax ] ) {
                $price_val = (float)( $_POST["amir_price_group_{$gmin}_{$gmax}"] ?? 0 );
                if ( $price_val > 0 ) {
                    $wpdb->insert( "{$wpdb->prefix}amir_prices", [
                        'tour_id'           => $tour_db_id,
                        'person_type'       => 'group',
                        'group_min'         => $gmin,
                        'group_max'         => $gmax,
                        'price_mxn'         => $price_val,
                        'provider_cost_mxn' => max( 0, (float) ( $_POST["amir_cost_group_{$gmin}_{$gmax}"] ?? 0 ) ),
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
        // Booleanos con default "activado" para tours nuevos (nunca guardados
        // todavía): a diferencia de $get() de arriba, acá NO se puede usar
        // `?: '1'` — un checkbox desmarcado guarda '0', que es falsy en PHP,
        // así que `'0' ?: '1'` volvería a mostrarlo tildado en cada carga.
        // Solo el meta realmente ausente (string vacío) cae al default.
        $bool_default_on = fn( string $k ) => ( get_post_meta( $post_id, "_amir_{$k}", true ) === '' )
            ? '1' : get_post_meta( $post_id, "_amir_{$k}", true );
        return [
            'name_en'          => $get('name_en'),
            'price_model'      => $get('price_model') ?: 'percapita',
            'duration_minutes' => $get('duration_minutes') ?: '',
            'min_age'          => $get('min_age') ?: '',
            'allow_children'   => $bool_default_on('allow_children'),
            'allow_babies'     => $bool_default_on('allow_babies'),
            'min_age_child'    => $get('min_age_child') ?: '4',
            'max_capacity'     => $get('max_capacity') ?: '',
            'min_passengers'   => $get('min_passengers') ?: '1',
            'sort_order'       => $get('sort_order') ?: '0',
            'featured'         => $get('featured'),
            'hide_from_lists'       => $get('hide_from_lists'),
            'hide_from_suggestions' => $get('hide_from_suggestions'),
            'languages_str'    => $langs_str,
            'description_en'   => $get('description_en'),
            'what_to_expect_es'=> $get('what_to_expect_es'),
            'what_to_expect_en'=> $get('what_to_expect_en'),
            'email_extra_note_es' => $get('email_extra_note_es'),
            'email_extra_note_en' => $get('email_extra_note_en'),
            'highlights_es'    => $get('highlights_es') ?: '[]',
            'highlights_en'    => $get('highlights_en') ?: '[]',
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
            'fixed_date'         => $get('fixed_date'),
            'request_only'       => $get('request_only'),
            'custom_quote'       => $get('custom_quote'),
            'deposit_enabled'    => $get('deposit_enabled'),
            'deposit_pct'        => $get('deposit_pct') ?: '20',
            'skip_upsell'        => $get('skip_upsell'),
            'require_participant_names' => $get('require_participant_names'),
            'content_i18n'       => json_decode( $get('content_i18n') ?: '{}', true ) ?: [],
            'provider_id'        => $get('provider_id') ?: '',
            'provider_charge_mode' => $get('provider_charge_mode') ?: 'immediate',
            'video_url'          => $get('video_url'),
        ];
    }
}
