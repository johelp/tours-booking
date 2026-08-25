<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * TourFlow → 🎁 Extras globales (Pro Max, § 16.23 CONTRIBUTING.md).
 *
 * CRUD de servicios extra que NO pertenecen a ningún tour/habitación puntual
 * (transfer, seguro de viaje, alquiler de equipo) ni productos digitales
 * (guía PDF, etc.) — se ofrecen en el paso de extras del flujo continuo sin
 * importar qué tours/habitaciones haya en el carrito. Reusa la tabla
 * amir_addons con tour_id/room_id NULL y applies_to='global' — separado del
 * editor de addons de cada tour puntual (TourPostType::meta_box_addons()),
 * que sigue intacto y sin tocar.
 */
class GlobalAddonsPage {

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
        }

        $this->handle_actions();

        $message = get_transient( 'amir_global_addon_message' );
        if ( $message ) {
            delete_transient( 'amir_global_addon_message' );
        }

        global $wpdb;
        $addons = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}amir_addons WHERE applies_to = 'global' ORDER BY sort_order ASC, id DESC"
        ) ?? [];
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1000px;">
        <?php $this->styles(); ?>
        <h1 style="display:flex;align-items:center;justify-content:space-between;">
          <span>🎁 <?php echo esc_html( $this->tt( 'Extras globales', 'Global extras' ) ); ?></span>
          <button onclick="document.getElementById('amir-new-addon-form').style.display='block';this.style.display='none';"
                  class="button button-primary">+ <?php echo esc_html( $this->tt( 'Nuevo extra', 'New extra' ) ); ?></button>
        </h1>
        <p style="color:#5a7068;font-size:13px;max-width:70ch;margin-top:-8px;">
          <?php echo esc_html( $this->tt(
            'Servicios extra (ej. transfer, seguro de viaje) o productos digitales (ej. guía PDF) que se ofrecen en el paso de extras del flujo continuo — sin importar qué tours u habitaciones haya en el carrito. Los addons ligados a UN tour puntual se siguen cargando desde el editor de ese tour.',
            "Extra services (e.g. transfer, travel insurance) or digital products (e.g. a PDF guide) offered in the extras step of the continuous flow — regardless of which tours or rooms are in the cart. Add-ons tied to ONE specific tour are still set up from that tour's editor."
          ) ); ?>
        </p>

        <?php if ( $message ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <div id="amir-new-addon-form" style="display:none;background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px;margin-bottom:24px;">
          <h3 style="margin:0 0 16px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Nuevo extra global', 'New global extra' ) ); ?></h3>
          <?php $this->render_form( 'create_addon', null ); ?>
          <button type="button" onclick="document.getElementById('amir-new-addon-form').style.display='none';document.querySelector('.wrap h1 button').style.display='inline-flex';" class="button" style="margin-top:10px;"><?php echo esc_html( $this->tt( 'Cancelar', 'Cancel' ) ); ?></button>
        </div>

        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#f8fdfb;">
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Nombre', 'Name' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Tipo', 'Type' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Precio', 'Price' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Estado', 'Status' ) ); ?></th>
            <th class="ab-th"></th>
          </tr></thead>
          <tbody>
          <?php if ( empty( $addons ) ) : ?>
            <tr><td colspan="5" class="ab-td" style="text-align:center;color:#5a7068;padding:24px;"><?php echo esc_html( $this->tt( 'Sin extras globales creados todavía.', 'No global extras created yet.' ) ); ?></td></tr>
          <?php else : foreach ( $addons as $a ) : ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
              <td class="ab-td"><strong><?php echo esc_html( $a->name_es ); ?></strong><br><span style="font-size:11px;color:#5a7068;"><?php echo esc_html( $a->name_en ); ?></span></td>
              <td class="ab-td"><?php echo esc_html( $this->pricing_type_label( $a->pricing_type ) ); ?></td>
              <td class="ab-td"><?php echo esc_html( \AmirBooking\Core\Currency::format( (float) $a->price_mxn ) ); ?></td>
              <td class="ab-td">
                <?php if ( (int) $a->active === 1 ) : ?>
                  <span style="color:#1D9E75;font-weight:700;font-size:12px;">✓ <?php echo esc_html( $this->tt( 'Activo', 'Active' ) ); ?></span>
                <?php else : ?>
                  <span style="color:#5a7068;font-size:12px;"><?php echo esc_html( $this->tt( 'Pausado', 'Paused' ) ); ?></span>
                <?php endif; ?>
              </td>
              <td class="ab-td">
                <button type="button" class="button button-small"
                        onclick="document.getElementById('amir-edit-addon-<?php echo (int) $a->id; ?>').style.display='table-row';"><?php echo esc_html( $this->tt( 'Editar', 'Edit' ) ); ?></button>
                <form method="post" style="display:inline;">
                  <?php wp_nonce_field( 'amir_global_addon_action' ); ?>
                  <input type="hidden" name="amir_action" value="toggle_addon" />
                  <input type="hidden" name="id" value="<?php echo (int) $a->id; ?>" />
                  <button type="submit" class="button button-small"><?php echo (int) $a->active === 1 ? esc_html( $this->tt('Pausar','Pause') ) : esc_html( $this->tt('Activar','Activate') ); ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Eliminar este extra?', 'Delete this extra?' ) ); ?>');">
                  <?php wp_nonce_field( 'amir_global_addon_action' ); ?>
                  <input type="hidden" name="amir_action" value="delete_addon" />
                  <input type="hidden" name="id" value="<?php echo (int) $a->id; ?>" />
                  <button type="submit" class="button button-small" style="color:#e24b4a;"><?php echo esc_html( $this->tt( 'Eliminar', 'Delete' ) ); ?></button>
                </form>
              </td>
            </tr>
            <tr id="amir-edit-addon-<?php echo (int) $a->id; ?>" style="display:none;">
              <td colspan="5" class="ab-td" style="background:#f8fdfb;">
                <?php $this->render_form( 'update_addon', $a ); ?>
                <button type="button" onclick="document.getElementById('amir-edit-addon-<?php echo (int) $a->id; ?>').style.display='none';" class="button" style="margin-top:10px;"><?php echo esc_html( $this->tt( 'Cancelar', 'Cancel' ) ); ?></button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
        </div>

        <script>
        // Campo de archivo solo tiene sentido para pricing_type='digital' —
        // se muestra/oculta en cada form (nuevo + una fila de edición por extra).
        document.querySelectorAll( '.amir-addon-pricing-select' ).forEach( function ( sel ) {
          function toggle() {
            var wrap = sel.closest( 'form' ).querySelector( '.amir-addon-file-field' );
            if ( wrap ) wrap.style.display = sel.value === 'digital' ? '' : 'none';
          }
          sel.addEventListener( 'change', toggle );
          toggle();
        } );
        document.querySelectorAll( '.amir-addon-file-select-btn' ).forEach( function ( btn ) {
          btn.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            var form  = btn.closest( 'form' );
            var input = form.querySelector( '.amir-addon-file-url' );
            var preview = form.querySelector( '.amir-addon-file-preview' );
            var frame = wp.media( { title: '<?php echo esc_js( $this->tt( 'Seleccionar archivo digital', 'Select digital file' ) ); ?>', button: { text: '<?php echo esc_js( $this->tt( 'Usar este archivo', 'Use this file' ) ); ?>' }, multiple: false } );
            frame.on( 'select', function () {
              var att = frame.state().get( 'selection' ).first().toJSON();
              input.value = att.url;
              preview.textContent = att.filename || att.url;
            } );
            frame.open();
          } );
        } );
        </script>
        <?php
    }

    private function render_form( string $action, ?object $a ): void {
        $id = $a->id ?? 0;
        ?>
        <form method="post">
          <?php wp_nonce_field( 'amir_global_addon_action' ); ?>
          <input type="hidden" name="amir_action" value="<?php echo esc_attr( $action ); ?>" />
          <?php if ( $id ) : ?><input type="hidden" name="id" value="<?php echo (int) $id; ?>" /><?php endif; ?>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
              <label class="ab-label"><?php echo esc_html( sprintf( $this->tt( 'Nombre (%s) *', 'Name (%s) *' ), 'Español' ) ); ?></label>
              <input type="text" name="name_es" required value="<?php echo esc_attr( $a->name_es ?? '' ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
            </div>
            <div>
              <label class="ab-label"><?php echo esc_html( sprintf( $this->tt( 'Nombre (%s) *', 'Name (%s) *' ), 'English' ) ); ?></label>
              <input type="text" name="name_en" required value="<?php echo esc_attr( $a->name_en ?? '' ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
              <label class="ab-label"><?php echo esc_html( $this->tt( 'Tipo', 'Type' ) ); ?></label>
              <select name="pricing_type" class="amir-addon-pricing-select" style="<?php echo $this->input_style(); ?> width:100%;">
                <option value="flat" <?php selected( $a->pricing_type ?? 'flat', 'flat' ); ?>><?php echo esc_html( $this->tt( 'Precio fijo (ej. transfer, seguro)', 'Flat price (e.g. transfer, insurance)' ) ); ?></option>
                <option value="per_unit" <?php selected( $a->pricing_type ?? '', 'per_unit' ); ?>><?php echo esc_html( $this->tt( 'Por cantidad (ej. alquiler de equipo por persona)', 'Per quantity (e.g. equipment rental per person)' ) ); ?></option>
                <option value="digital" <?php selected( $a->pricing_type ?? '', 'digital' ); ?>><?php echo esc_html( $this->tt( 'Producto digital (archivo descargable)', 'Digital product (downloadable file)' ) ); ?></option>
              </select>
            </div>
            <div>
              <label class="ab-label"><?php echo esc_html( sprintf( $this->tt( 'Precio (%s) *', 'Price (%s) *' ), \AmirBooking\Core\Currency::code() ) ); ?></label>
              <input type="number" name="price_mxn" required min="0" step="0.01" value="<?php echo esc_attr( $a->price_mxn ?? '' ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
            </div>
          </div>
          <div class="amir-addon-file-field" style="margin-bottom:14px;">
            <label class="ab-label"><?php echo esc_html( $this->tt( 'Archivo digital (PDF, etc.)', 'Digital file (PDF, etc.)' ) ); ?></label>
            <input type="hidden" name="digital_file_url" class="amir-addon-file-url" value="<?php echo esc_attr( $a->digital_file_url ?? '' ); ?>" />
            <button type="button" class="button amir-addon-file-select-btn"><?php echo esc_html( $this->tt( 'Elegir archivo de la Media Library', 'Choose file from the Media Library' ) ); ?></button>
            <div class="amir-addon-file-preview" style="font-size:12px;color:#5a7068;margin-top:6px;">
              <?php echo $a->digital_file_url ? '✓ ' . esc_html( sprintf( $this->tt( 'Archivo cargado (%s)', 'File loaded (%s)' ), basename( $a->digital_file_url ) ) ) : ''; ?>
            </div>
            <p style="font-size:11px;color:#8a9a93;margin:4px 0 0;"><?php echo esc_html( $this->tt(
              'El link de descarga se agrega automáticamente al email de confirmación de quien lo compre, tokenizado con su propia reserva. El archivo se copia a una zona protegida al guardar — no queda expuesto en la Media Library pública.',
              "The download link is automatically added to the confirmation email of whoever buys it, tokenized with their own booking. The file is copied to a protected area when saved — it's never exposed in the public Media Library."
            ) ); ?></p>
          </div>
          <button type="submit" class="button button-primary"><?php echo $id ? esc_html( $this->tt('Guardar cambios','Save changes') ) : esc_html( $this->tt('Crear extra','Create extra') ); ?></button>
        </form>
        <?php
    }

    private function handle_actions(): void {
        if ( empty( $_POST['amir_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_global_addon_action' ) ) {
            return;
        }

        global $wpdb;
        $action = sanitize_key( $_POST['amir_action'] );

        if ( in_array( $action, [ 'create_addon', 'update_addon' ], true ) ) {
            $name_es = sanitize_text_field( wp_unslash( $_POST['name_es'] ?? '' ) );
            $name_en = sanitize_text_field( wp_unslash( $_POST['name_en'] ?? '' ) );
            if ( $name_es === '' || $name_en === '' ) {
                set_transient( 'amir_global_addon_message', $this->tt( 'El nombre en español y en inglés son obligatorios.', 'The name in Spanish and English are required.' ), 30 );
                return;
            }

            $pricing_type = in_array( $_POST['pricing_type'] ?? '', [ 'per_unit', 'flat', 'digital' ], true ) ? $_POST['pricing_type'] : 'flat';

            // El archivo se copia a una zona protegida (fuera de la Media
            // Library pública) al guardar — antes se guardaba la URL pública
            // del adjunto tal cual, y el endpoint de descarga solo hacía un
            // redirect ahí: el token no protegía nada, cualquiera podía
            // encontrar el archivo vía /wp-json/wp/v2/media sin comprar
            // (auditoría de seguridad 2026-08-04, ver CONTRIBUTING.md § 16.28).
            $digital_path = null;
            if ( $pricing_type === 'digital' ) {
                $posted_file = trim( (string) ( $_POST['digital_file_url'] ?? '' ) );
                if ( $posted_file !== '' ) {
                    $protected_dir = $this->protected_digital_dir();
                    if ( strpos( $posted_file, $protected_dir ) === 0 && file_exists( $posted_file ) ) {
                        // Edición sin cambiar el archivo — ya es una copia protegida.
                        $digital_path = $posted_file;
                    } else {
                        $digital_path = $this->store_digital_file( esc_url_raw( $posted_file ) );
                        if ( ! $digital_path ) {
                            set_transient( 'amir_global_addon_message', $this->tt( 'No se pudo procesar el archivo digital seleccionado — probá elegirlo de nuevo.', 'The selected digital file could not be processed — try choosing it again.' ), 30 );
                            return;
                        }
                    }
                }
            }

            $data = [
                'applies_to'       => 'global',
                'pricing_type'     => $pricing_type,
                'name_es'          => $name_es,
                'name_en'          => $name_en,
                'price_mxn'        => (float) ( $_POST['price_mxn'] ?? 0 ),
                // Solo se guarda cuando el tipo es 'digital' — evita que un
                // extra físico quede con un archivo "fantasma" adjunto de
                // cuando alguien lo probó como digital y cambió de idea.
                'digital_file_url' => $digital_path,
            ];
            $formats = [ '%s', '%s', '%s', '%s', '%f', '%s' ];

            if ( $action === 'create_addon' ) {
                // Bug real corregido 2026-08-04: el %format% tiene que
                // matchear el ORDEN real de las keys de $data (wpdb aplica
                // cada formato posicionalmente, no por nombre de columna) —
                // esto iba antepuesto (array_merge(['%d','%d','%d'], $formats))
                // en vez de al final, así que %d le pegaba a applies_to/
                // pricing_type/name_es (texto) en vez de a tour_id/room_id/
                // active — 'global' se guardaba corrupto (MySQL castea el
                // ENUM inválido a ''), y el listado (WHERE applies_to='global')
                // nunca encontraba la fila recién creada.
                $data['tour_id']   = null;
                $data['room_id']   = null;
                $data['active']    = 1;
                $inserted = $wpdb->insert( "{$wpdb->prefix}amir_addons", $data, array_merge( $formats, [ '%d', '%d', '%d' ] ) );
                set_transient( 'amir_global_addon_message', $inserted ? sprintf( $this->tt( 'Extra "%s" creado correctamente.', 'Extra "%s" created successfully.' ), $name_es ) : $this->tt( 'Error al crear el extra.', 'Error creating the extra.' ), 30 );
            } else {
                $id = (int) ( $_POST['id'] ?? 0 );
                if ( ! $id ) {
                    return;
                }
                $wpdb->update( "{$wpdb->prefix}amir_addons", $data, [ 'id' => $id, 'applies_to' => 'global' ], $formats, [ '%d', '%s' ] );
                set_transient( 'amir_global_addon_message', sprintf( $this->tt( 'Extra "%s" actualizado correctamente.', 'Extra "%s" updated successfully.' ), $name_es ), 30 );
            }
        }

        if ( $action === 'toggle_addon' && ! empty( $_POST['id'] ) ) {
            $id = (int) $_POST['id'];
            $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}amir_addons WHERE id = %d AND applies_to = 'global'", $id ) );
            $wpdb->update( "{$wpdb->prefix}amir_addons", [ 'active' => $current ? 0 : 1 ], [ 'id' => $id, 'applies_to' => 'global' ], [ '%d' ], [ '%d', '%s' ] );
            set_transient( 'amir_global_addon_message', $this->tt( 'Extra actualizado.', 'Extra updated.' ), 30 );
        }

        if ( $action === 'delete_addon' && ! empty( $_POST['id'] ) ) {
            // Nunca borra si ya se vendió (amir_booking_addons.name_snapshot
            // ya guarda una copia congelada del nombre/precio en ese momento
            // — el historial de reservas pasadas queda intacto igual) — pero
            // sí impide seguir ofreciéndolo: mismo criterio que "Pausar", así
            // que acá directamente se borra la fila del catálogo.
            $wpdb->delete( "{$wpdb->prefix}amir_addons", [ 'id' => (int) $_POST['id'], 'applies_to' => 'global' ], [ '%d', '%s' ] );
            set_transient( 'amir_global_addon_message', $this->tt( 'Extra eliminado.', 'Extra deleted.' ), 30 );
        }
    }

    /**
     * Directorio protegido para archivos de productos digitales — mismo
     * criterio que VoucherGenerator::protect_directory() (.htaccess +
     * index.php; el .htaccess no alcanza solo en nginx, por eso los nombres
     * de archivo son aleatorios, nunca derivados del nombre original).
     */
    private function protected_digital_dir(): string {
        $dirs = wp_upload_dir();
        $dir  = $dirs['basedir'] . '/amir-booking/digital/';
        wp_mkdir_p( $dir );
        if ( ! file_exists( $dir . '.htaccess' ) ) {
            file_put_contents( $dir . '.htaccess', "deny from all\n" );
        }
        if ( ! file_exists( $dir . 'index.php' ) ) {
            file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
        }
        return $dir;
    }

    /**
     * Resuelve la URL pública de Media Library elegida en el picker a su
     * archivo real en disco y hace una copia con nombre aleatorio en la
     * zona protegida. Devuelve la ruta de la copia, o '' si la URL no
     * corresponde a un adjunto real de este sitio.
     */
    private function store_digital_file( string $media_url ): string {
        $attachment_id = attachment_url_to_postid( $media_url );
        $source_path   = $attachment_id ? get_attached_file( $attachment_id ) : false;
        if ( ! $source_path || ! file_exists( $source_path ) ) {
            return '';
        }

        $ext  = pathinfo( $source_path, PATHINFO_EXTENSION );
        $name = bin2hex( random_bytes( 20 ) ) . ( $ext ? '.' . $ext : '' );
        $dest = $this->protected_digital_dir() . $name;

        return copy( $source_path, $dest ) ? $dest : '';
    }

    private function pricing_type_label( string $type ): string {
        switch ( $type ) {
            case 'per_unit': return $this->tt( 'Por cantidad', 'Per quantity' );
            case 'digital':  return '📄 ' . $this->tt( 'Digital', 'Digital' );
            default:         return $this->tt( 'Precio fijo', 'Flat price' );
        }
    }

    private function input_style(): string {
        return 'border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;box-sizing:border-box;';
    }

    private function styles(): void {
        echo '<style>
        .ab-admin-wrap{max-width:1000px}
        .ab-th{font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;padding:10px 12px;text-align:left}
        .ab-td{font-size:13px;padding:10px 12px;vertical-align:middle}
        .ab-label{display:block;font-size:12px;font-weight:700;color:#1a2e24;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
        </style>';
    }
}
