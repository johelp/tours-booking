<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Personalización visual y de contenido — separada de Configuración general
 * (pedido del cliente 2026-07-28): pasarelas de pago, moneda, idiomas, etc.
 * son configuración operativa; esto de acá es "cómo se ve y qué dice" de
 * cara al cliente (widget, marca, textos de email/voucher/política).
 * Solo-admin (ver AdminMenu::add_menus()) — un gestor de tienda no la ve.
 */
class PersonalizationPage {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
        }

        if ( isset($_POST['amir_personalization_nonce']) && wp_verify_nonce($_POST['amir_personalization_nonce'],'amir_personalization') ) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>Personalización guardada.</p></div>';
        }
        ?>
        <div class="wrap ab-admin-wrap">
        <style>
        .ab-admin-wrap{max-width:860px}
        .ab-settings-section{background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 22px;margin-bottom:20px}
        .ab-settings-section h3{font-size:15px;font-weight:700;color:#1D9E75;margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid #e1f5ee}
        .ab-field{margin-bottom:14px}
        .ab-field label{display:block;font-size:13px;font-weight:600;color:#1a2e24;margin-bottom:5px}
        .ab-field input,.ab-field select{border:1px solid #c3d9d0;border-radius:6px;padding:8px 11px;font-size:13px;width:100%;max-width:420px;box-sizing:border-box}
        .ab-field input:focus,.ab-field select:focus{outline:none;border-color:#1D9E75;box-shadow:0 0 0 2px rgba(29,158,117,.15)}
        .ab-hint{font-size:11px;color:#5a7068;margin-top:3px}
        .ab-field-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        </style>

        <h1>🎨 Personalización</h1>
        <p style="color:#5a7068;font-size:13px;max-width:70ch;margin-top:-8px;">
          Cómo se ve y qué dice el plugin de cara al cliente — colores del widget, marca, y los textos de email/voucher/política. La configuración operativa (pasarelas de pago, moneda, idiomas) vive en <a href="<?php echo esc_url( admin_url('admin.php?page=amir-settings') ); ?>">Configuración</a>.
        </p>
        <form method="post">
          <?php wp_nonce_field('amir_personalization','amir_personalization_nonce'); ?>

          <!-- Widget de reserva -->
          <div class="ab-settings-section">
            <h3>🎨 Widget de reserva</h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              Personalización visual de <code>[amir_booking]</code> — se aplica al instante, sin recompilar nada.
            </p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Color principal</label>
                <div style="display:flex;align-items:center;gap:10px;">
                  <input type="color" name="amir_widget_color" id="amir-widget-color"
                         value="<?php echo esc_attr( get_option( 'amir_widget_color', '#1D9E75' ) ); ?>"
                         style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                  <input type="text" name="amir_widget_color_hex" id="amir-widget-color-hex"
                         value="<?php echo esc_attr( get_option( 'amir_widget_color', '#1D9E75' ) ); ?>"
                         style="width:90px;font-family:monospace;" placeholder="#1D9E75" />
                </div>
                <p class="ab-hint">Los tonos oscuro/claro/medio (botones, fondos, acentos) se calculan automáticamente a partir de este color.</p>
              </div>
              <div class="ab-field">
                <label>Tipografía</label>
                <select name="amir_widget_font">
                  <?php foreach ( \AmirBooking\Core\WidgetTheme::fonts() as $key => $f ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( \AmirBooking\Core\WidgetTheme::font_key(), $key ); ?>><?php echo esc_html( $f['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Tamaño de texto</label>
                <select name="amir_widget_font_scale">
                  <?php foreach ( \AmirBooking\Core\WidgetTheme::scales() as $key => $s ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( \AmirBooking\Core\WidgetTheme::scale_key(), $key ); ?>><?php echo esc_html( $s['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="ab-hint">Los campos de formulario y los botones nunca bajan de su tamaño mínimo táctil/legible, aunque elijas "Compacto".</p>
              </div>
              <div class="ab-field">
                <label>Radio de esquinas</label>
                <select name="amir_widget_radius">
                  <?php foreach ( \AmirBooking\Core\WidgetTheme::radii() as $key => $r ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( \AmirBooking\Core\WidgetTheme::radius_key(), $key ); ?>><?php echo esc_html( $r['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Texto de los pasos</label>
                <select name="amir_widget_progress_labels">
                  <option value="1" <?php selected( \AmirBooking\Core\WidgetTheme::progress_labels(), true ); ?>>Mostrar (ícono + nombre del paso)</option>
                  <option value="0" <?php selected( \AmirBooking\Core\WidgetTheme::progress_labels(), false ); ?>>Ocultar (solo íconos)</option>
                </select>
                <p class="ab-hint">La barra de progreso siempre muestra un ícono por paso — esto solo agrega o quita el nombre debajo.</p>
              </div>
            </div>
          </div>

          <!-- ── Identidad de marca ── -->
          <div class="ab-settings-section">
            <h3>🎨 Identidad de marca</h3>

            <!-- Logo -->
            <div class="ab-field">
              <label>Logo (cabecera del email y voucher)</label>
              <?php
              $logo_url = get_option('amir_brand_logo_url', '');
              $logo_id  = (int) get_option('amir_brand_logo_id', 0);
              ?>
              <div style="display:flex;align-items:center;gap:14px;margin-top:6px;">
                <div id="amir-logo-preview" style="width:120px;height:48px;border:1px solid #c3d9d0;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f9fafb;">
                  <?php if ($logo_url): ?>
                    <img src="<?php echo esc_url($logo_url); ?>" style="max-width:118px;max-height:46px;object-fit:contain;" />
                  <?php else: ?>
                    <span style="font-size:11px;color:#5a7068;">Sin logo</span>
                  <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                  <button type="button" id="amir-logo-upload-btn" class="button">
                    📁 Seleccionar imagen
                  </button>
                  <?php if ($logo_url): ?>
                  <button type="button" id="amir-logo-remove-btn" class="button" style="color:#e24b4a;border-color:#fecaca;">
                    ✕ Eliminar logo
                  </button>
                  <?php endif; ?>
                </div>
              </div>
              <input type="hidden" name="amir_brand_logo_id"  id="amir-logo-id"  value="<?php echo esc_attr($logo_id); ?>" />
              <input type="hidden" name="amir_brand_logo_url" id="amir-logo-url" value="<?php echo esc_attr($logo_url); ?>" />
              <p class="ab-hint">Recomendado: PNG o SVG con fondo transparente, altura mínima 100px. Se usa en el header del email y el voucher PDF.</p>
            </div>

            <!-- Color y nombre -->
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Color principal</label>
                <div style="display:flex;align-items:center;gap:10px;">
                  <input type="color" name="amir_brand_color" id="amir-brand-color"
                         value="<?php echo esc_attr(get_option('amir_brand_color','#1D9E75')); ?>"
                         style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                  <input type="text" name="amir_brand_color_hex" id="amir-brand-color-hex"
                         value="<?php echo esc_attr(get_option('amir_brand_color','#1D9E75')); ?>"
                         style="width:90px;font-family:monospace;" placeholder="#1D9E75" />
                </div>
                <p class="ab-hint">Color del header del email y acentos del voucher PDF.</p>
              </div>
              <div class="ab-field">
                <label>Nombre de la empresa</label>
                <input type="text" name="amir_company_name"
                       value="<?php echo esc_attr(get_option('amir_company_name','')); ?>"
                       placeholder="TourFlow" />
                <p class="ab-hint">Aparece en el pie del email y del voucher.</p>
              </div>
            </div>

            <!-- Eslogan -->
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Eslogan (Español)</label>
                <input type="text" name="amir_company_tagline_es"
                       value="<?php echo esc_attr(get_option('amir_company_tagline_es','')); ?>"
                       placeholder="Ej: Experiencias en Bacalar · Quintana Roo, México" />
              </div>
              <div class="ab-field">
                <label>Tagline (English)</label>
                <input type="text" name="amir_company_tagline_en"
                       value="<?php echo esc_attr(get_option('amir_company_tagline_en','')); ?>"
                       placeholder="Ex: Experiences in Bacalar · Quintana Roo, Mexico" />
              </div>
            </div>

            <!-- Prefijo de reserva -->
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Prefijo de número de reserva</label>
                <input type="text" name="amir_booking_ref_prefix" maxlength="10"
                       value="<?php echo esc_attr(get_option('amir_booking_ref_prefix','BK')); ?>"
                       placeholder="BK" style="width:120px;text-transform:uppercase;" />
                <p class="ab-hint">Ejemplo: <strong>BK-<?php echo date('Y'); ?>-00001</strong>. Solo letras y números, sin espacios. Las reservas ya creadas conservan su número actual — esto solo aplica a las nuevas.</p>
              </div>
            </div>
          </div>

          <!-- ── Contenido del email ── -->
          <div class="ab-settings-section">
            <h3>📧 Contenido del email de confirmación</h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">Una recomendación por línea. Se muestran como lista en el email de confirmación.</p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Recomendaciones (Español)</label>
                <textarea name="amir_email_recs_es" rows="7" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="Llega 10 minutos antes al punto de encuentro.&#10;Usa ropa cómoda y protector solar biodegradable.&#10;Trae agua y snacks ligeros.&#10;Lleva tu documento de identidad."><?php
                  echo esc_textarea(get_option('amir_email_recs_es',
                    "Llega 10 minutos antes al punto de encuentro.\nUsa ropa cómoda y protector solar biodegradable.\nTrae agua y snacks ligeros.\nLleva tu documento de identidad."));
                ?></textarea>
              </div>
              <div class="ab-field">
                <label>Recommendations (English)</label>
                <textarea name="amir_email_recs_en" rows="7" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="Arrive 10 minutes before departure.&#10;Wear comfortable clothes and biodegradable sunscreen.&#10;Bring water and light snacks.&#10;Carry a photo ID."><?php
                  echo esc_textarea(get_option('amir_email_recs_en',
                    "Arrive 10 minutes before departure.\nWear comfortable clothes and biodegradable sunscreen.\nBring water and light snacks.\nCarry a photo ID."));
                ?></textarea>
              </div>
            </div>
          </div>

          <!-- ── Contenido del voucher PDF ── -->
          <div class="ab-settings-section">
            <h3>📄 Contenido del voucher PDF</h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">Una recomendación por línea. Se muestran en la sección "Recuerda llevar" del PDF.</p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Recomendaciones voucher (Español)</label>
                <textarea name="amir_voucher_recs_es" rows="7" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="Ropa cómoda y traje de baño&#10;Protector solar biodegradable (obligatorio)&#10;Agua y snacks ligeros"><?php
                  echo esc_textarea(get_option('amir_voucher_recs_es',
                    "Ropa cómoda y traje de baño\nProtector solar biodegradable (obligatorio)\nAgua y snacks ligeros\nDocumento de identidad\nCámara en bolsa impermeable\nLlega 10 min antes a tu hora de salida"));
                ?></textarea>
              </div>
              <div class="ab-field">
                <label>Voucher recommendations (English)</label>
                <textarea name="amir_voucher_recs_en" rows="7" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="Comfortable clothes and swimsuit&#10;Biodegradable sunscreen (required)&#10;Water and light snacks"><?php
                  echo esc_textarea(get_option('amir_voucher_recs_en',
                    "Comfortable clothes and swimsuit\nBiodegradable sunscreen (required)\nWater and light snacks\nPhoto ID\nCamera in waterproof bag\nArrive 10 min before departure"));
                ?></textarea>
              </div>
            </div>
          </div>

          <!-- ── Política de cancelación en el widget ── -->
          <div class="ab-settings-section">
            <h3>📋 Política de cancelación (widget de reserva)</h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              Una línea por renglón. Se muestra en el paso de resumen antes de pagar, junto al checkbox de aceptación.
              Vacío = se muestran las 3 líneas por defecto ("7+ días: reembolso completo", etc.).
            </p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Política (Español)</label>
                <textarea name="amir_policy_text_es" rows="4" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="✓ 7+ días antes: reembolso completo&#10;▸ 3–6 días antes: reembolso del 50 %&#10;✕ Menos de 3 días: sin reembolso"><?php
                  echo esc_textarea( get_option( 'amir_policy_text_es', '' ) );
                ?></textarea>
              </div>
              <div class="ab-field">
                <label>Policy (English)</label>
                <textarea name="amir_policy_text_en" rows="4" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="✓ 7+ days before: full refund&#10;▸ 3–6 days before: 50% refund&#10;✕ Less than 3 days: no refund"><?php
                  echo esc_textarea( get_option( 'amir_policy_text_en', '' ) );
                ?></textarea>
              </div>
            </div>
          </div>

          <button type="submit" class="button button-primary" style="padding:10px 28px;font-size:14px;">Guardar personalización</button>
        </form>
        </div>

        <script>
        (function(){
            // ── Color picker sync ──────────────────────────────────────────
            var colorInput = document.getElementById('amir-brand-color');
            var hexInput   = document.getElementById('amir-brand-color-hex');
            if ( colorInput && hexInput ) {
                colorInput.addEventListener('input', function(){ hexInput.value = this.value; });
                hexInput.addEventListener('input', function(){
                    var v = this.value.trim();
                    if ( /^#[0-9A-Fa-f]{6}$/.test(v) ) { colorInput.value = v; }
                });
            }

            var widgetColorInput = document.getElementById('amir-widget-color');
            var widgetHexInput   = document.getElementById('amir-widget-color-hex');
            if ( widgetColorInput && widgetHexInput ) {
                widgetColorInput.addEventListener('input', function(){ widgetHexInput.value = this.value; });
                widgetHexInput.addEventListener('input', function(){
                    var v = this.value.trim();
                    if ( /^#[0-9A-Fa-f]{6}$/.test(v) ) { widgetColorInput.value = v; }
                });
            }

            // ── Logo media upload ─────────────────────────────────────────
            window.addEventListener('load', function() {
                var uploadBtn  = document.getElementById('amir-logo-upload-btn');
                var removeBtn  = document.getElementById('amir-logo-remove-btn');
                var logoId     = document.getElementById('amir-logo-id');
                var logoUrl    = document.getElementById('amir-logo-url');
                var preview    = document.getElementById('amir-logo-preview');
                var mediaFrame;

                if ( uploadBtn ) {
                    uploadBtn.addEventListener('click', function(e) {
                        e.preventDefault();

                        if ( typeof wp === 'undefined' || ! wp.media ) {
                            alert('El Media Library de WordPress no está disponible. Asegúrate de que wp_enqueue_media() esté activo.');
                            return;
                        }

                        if ( mediaFrame ) { mediaFrame.open(); return; }

                        mediaFrame = wp.media({
                            title:    'Seleccionar logo',
                            button:   { text: 'Usar como logo' },
                            multiple: false,
                            library:  { type: 'image' }
                        });

                        mediaFrame.on('select', function() {
                            var attachment = mediaFrame.state().get('selection').first().toJSON();
                            var url = attachment.sizes && attachment.sizes.medium
                                ? attachment.sizes.medium.url : attachment.url;
                            logoId.value  = attachment.id;
                            logoUrl.value = attachment.url;
                            preview.innerHTML = '<img src="' + url + '" style="max-width:118px;max-height:46px;object-fit:contain;" />';
                            if ( removeBtn ) { removeBtn.style.display = ''; }
                        });

                        mediaFrame.open();
                    });
                }

                if ( removeBtn ) {
                    removeBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        logoId.value  = '0';
                        logoUrl.value = '';
                        preview.innerHTML = '<span style="font-size:11px;color:#5a7068;">Sin logo</span>';
                        removeBtn.style.display = 'none';
                    });
                }
            });
        })();
        </script>
        <?php
    }

    private function input_style_ta(): string {
        return 'width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:8px 11px;font-size:13px;box-sizing:border-box;resize:vertical;';
    }

    private function save_settings(): void {
        $options = [
            'amir_company_name'            => 'sanitize_text_field',
            'amir_company_tagline_es'      => 'sanitize_text_field',
            'amir_company_tagline_en'      => 'sanitize_text_field',
            'amir_email_recs_es'           => 'sanitize_textarea_field',
            'amir_email_recs_en'           => 'sanitize_textarea_field',
            'amir_voucher_recs_es'         => 'sanitize_textarea_field',
            'amir_voucher_recs_en'         => 'sanitize_textarea_field',
            'amir_policy_text_es'          => 'sanitize_textarea_field',
            'amir_policy_text_en'          => 'sanitize_textarea_field',
            'amir_brand_logo_id'           => 'absint',
            'amir_brand_logo_url'          => 'esc_url_raw',
        ];

        foreach ($options as $key => $sanitizer) {
            if (isset($_POST[$key])) {
                update_option($key, call_user_func($sanitizer, $_POST[$key]));
            }
        }

        // Brand color: validar formato hex
        if ( isset($_POST['amir_brand_color_hex']) ) {
            $color = sanitize_text_field($_POST['amir_brand_color_hex']);
            if ( preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ) {
                update_option('amir_brand_color', $color);
            }
        }

        // Widget de reserva: color validado como hex, el resto son selects
        // con valores fijos conocidos (WidgetTheme::*_key() ya valida contra
        // la lista curada y cae al default si viene algo raro).
        if ( isset( $_POST['amir_widget_color_hex'] ) ) {
            $widget_color = sanitize_text_field( $_POST['amir_widget_color_hex'] );
            if ( preg_match( '/^#[0-9A-Fa-f]{6}$/', $widget_color ) ) {
                update_option( 'amir_widget_color', $widget_color );
            }
        }
        if ( isset( $_POST['amir_widget_font'] ) ) {
            update_option( 'amir_widget_font', sanitize_key( $_POST['amir_widget_font'] ) );
        }
        if ( isset( $_POST['amir_widget_font_scale'] ) ) {
            update_option( 'amir_widget_font_scale', sanitize_key( $_POST['amir_widget_font_scale'] ) );
        }
        if ( isset( $_POST['amir_widget_radius'] ) ) {
            update_option( 'amir_widget_radius', sanitize_key( $_POST['amir_widget_radius'] ) );
        }
        if ( isset( $_POST['amir_widget_progress_labels'] ) ) {
            update_option( 'amir_widget_progress_labels', $_POST['amir_widget_progress_labels'] === '0' ? '0' : '1' );
        }

        // Prefijo de reserva: solo letras/números en mayúscula, sin espacios.
        if ( isset( $_POST['amir_booking_ref_prefix'] ) ) {
            $prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $_POST['amir_booking_ref_prefix'] ) );
            update_option( 'amir_booking_ref_prefix', $prefix !== '' ? substr( $prefix, 0, 10 ) : 'BK' );
        }
    }
}
