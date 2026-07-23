<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de configuración general del plugin.
 */
class SettingsPage {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
        }

        // Manejar sincronización masiva de tours
        if ( isset($_POST['amir_sync_nonce']) && wp_verify_nonce($_POST['amir_sync_nonce'],'amir_sync_tours') ) {
            $count = $this->sync_all_tours();
            echo '<div class="notice notice-success is-dismissible"><p>✓ ' . $count . ' tour(s) sincronizados correctamente con la base de datos.</p></div>';
        }

        if ( isset($_POST['amir_settings_nonce']) && wp_verify_nonce($_POST['amir_settings_nonce'],'amir_settings') ) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
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

        <h1>⚙ Configuración</h1>
        <form method="post">
          <?php wp_nonce_field('amir_settings','amir_settings_nonce'); ?>

          <!-- Stripe -->
          <div class="ab-settings-section">
            <h3>💳 Stripe</h3>
            <div class="ab-field">
              <label>Modo</label>
              <select name="amir_stripe_mode">
                <option value="test" <?php selected(get_option('amir_stripe_mode','test'),'test'); ?>>Test (pruebas)</option>
                <option value="live" <?php selected(get_option('amir_stripe_mode','test'),'live'); ?>>Live (producción)</option>
              </select>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Publishable Key TEST</label>
                <input type="text" name="amir_stripe_pk_test" value="<?php echo esc_attr(get_option('amir_stripe_pk_test','')); ?>" placeholder="pk_test_…" />
              </div>
              <div class="ab-field">
                <label>Secret Key TEST</label>
                <input type="password" name="amir_stripe_sk_test" value="<?php echo esc_attr(get_option('amir_stripe_sk_test','')); ?>" placeholder="sk_test_…" />
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Publishable Key LIVE</label>
                <input type="text" name="amir_stripe_pk_live" value="<?php echo esc_attr(get_option('amir_stripe_pk_live','')); ?>" placeholder="pk_live_…" />
              </div>
              <div class="ab-field">
                <label>Secret Key LIVE</label>
                <input type="password" name="amir_stripe_sk_live" value="<?php echo esc_attr(get_option('amir_stripe_sk_live','')); ?>" placeholder="sk_live_…" />
              </div>
            </div>
            <div class="ab-field">
              <label>Webhook Secret</label>
              <input type="password" name="amir_stripe_webhook_secret" value="<?php echo esc_attr(get_option('amir_stripe_webhook_secret','')); ?>" placeholder="whsec_…" />
              <p class="ab-hint">URL del webhook en Stripe: <code><?php echo rest_url('amir/v1/bookings/stripe-webhook'); ?></code></p>
            </div>
          </div>

          <!-- General -->
          <div class="ab-settings-section">
            <h3>⚙ General</h3>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Email del operador (alertas)</label>
                <input type="email" name="amir_admin_email" value="<?php echo esc_attr(get_option('amir_admin_email',get_option('admin_email'))); ?>" />
              </div>
              <div class="ab-field">
                <label>WhatsApp de contacto (solo números)</label>
                <input type="text" name="amir_wa_phone" value="<?php echo esc_attr(get_option('amir_wa_phone','5219831649541')); ?>" placeholder="5219831649541" />
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Minutos para expirar reserva pending</label>
                <input type="number" name="amir_pending_expire_mins" value="<?php echo esc_attr(get_option('amir_pending_expire_mins','15')); ?>" min="5" max="60" />
              </div>
              <div class="ab-field">
                <label>Días post-tour para email de reseña</label>
                <input type="number" name="amir_review_delay_days" value="<?php echo esc_attr(get_option('amir_review_delay_days','1')); ?>" min="1" max="7" />
              </div>
            </div>
          </div>

          <!-- Tipo de cambio USD -->
          <div class="ab-settings-section">
            <h3>💱 Tipo de cambio USD/MXN</h3>
            <div class="ab-field">
              <label>Modo</label>
              <select name="amir_usd_rate_mode">
                <option value="auto" <?php selected(get_option('amir_usd_rate_mode','auto'),'auto'); ?>>Automático (API ExchangeRate, actualiza c/4h)</option>
                <option value="manual" <?php selected(get_option('amir_usd_rate_mode','auto'),'manual'); ?>>Manual (valor fijo)</option>
              </select>
            </div>
            <div class="ab-field">
              <label>Valor manual USD → MXN</label>
              <input type="number" name="amir_usd_rate_manual" value="<?php echo esc_attr(get_option('amir_usd_rate_manual','17')); ?>" min="1" step="0.01" style="max-width:120px;" />
              <p class="ab-hint">Ej: 17 = $1 USD = $17 MXN</p>
            </div>
          </div>

          <!-- Integraciones -->
          <div class="ab-settings-section">
            <h3>🔗 URLs de reseñas</h3>
            <div class="ab-field">
              <label>URL de reseñas en Google (Google Place Review Link)</label>
              <input type="url" name="amir_google_review_url" value="<?php echo esc_attr(get_option('amir_google_review_url','')); ?>" placeholder="https://g.page/r/…/review" />
            </div>
            <div class="ab-field">
              <label>URL de reseñas en TripAdvisor</label>
              <input type="url" name="amir_tripadvisor_review_url" value="<?php echo esc_attr(get_option('amir_tripadvisor_review_url','')); ?>" placeholder="https://www.tripadvisor.com/…" />
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
                       value="<?php echo esc_attr(get_option('amir_company_name','Amir Adventours Bacalar')); ?>"
                       placeholder="Amir Adventours Bacalar" />
                <p class="ab-hint">Aparece en el pie del email y del voucher.</p>
              </div>
            </div>

            <!-- Eslogan -->
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Eslogan (Español)</label>
                <input type="text" name="amir_company_tagline_es"
                       value="<?php echo esc_attr(get_option('amir_company_tagline_es','Experiencias en Bacalar · Quintana Roo, México')); ?>"
                       placeholder="Experiencias en Bacalar…" />
              </div>
              <div class="ab-field">
                <label>Tagline (English)</label>
                <input type="text" name="amir_company_tagline_en"
                       value="<?php echo esc_attr(get_option('amir_company_tagline_en','Experiences in Bacalar · Quintana Roo, Mexico')); ?>"
                       placeholder="Experiences in Bacalar…" />
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

          <!-- Peligro -->
          <div class="ab-settings-section" style="border-color:#fecaca;">
            <h3 style="color:#e24b4a;">⚠ Zona de peligro</h3>
            <div class="ab-field">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="amir_delete_data_on_uninstall" value="1"
                       <?php checked(get_option('amir_delete_data_on_uninstall','0'),'1'); ?>
                       style="accent-color:#e24b4a;width:auto;" />
                Eliminar todas las tablas y datos al desinstalar el plugin
              </label>
              <p class="ab-hint" style="color:#e24b4a;">⚠ Activar solo si deseas eliminar permanentemente todos los datos del plugin al desinstalarlo.</p>
            </div>
          </div>

          <button type="submit" class="button button-primary" style="padding:10px 28px;font-size:14px;">Guardar configuración</button>
        </form>

        <!-- Sincronización de tours — form independiente, fuera del form de -->
        <!-- configuración (un <form> anidado dentro de otro es HTML inválido: -->
        <!-- el navegador cierra el form exterior antes de tiempo y el botón -->
        <!-- "Guardar configuración" queda fuera de cualquier form, sin poder -->
        <!-- enviarse nunca). -->
        <div class="ab-settings-section" style="border-color:#9FE1CB;">
          <h3>🔄 Sincronización de tours</h3>
          <p style="font-size:13px;color:#5a7068;margin:0 0 14px;">
            Si los tours no aparecen en Disponibilidad, Reservas o Reportes, usa este botón para sincronizar todos los tours publicados con la base de datos del plugin.
          </p>
          <?php
          global $wpdb;
          $db_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}amir_tours");
          $cpt_count = (int) wp_count_posts('amir_tour')->publish;
          ?>
          <p style="font-size:13px;margin:0 0 14px;">
            Tours en base de datos: <strong><?php echo $db_count; ?></strong> &nbsp;|&nbsp;
            Tours publicados (CPT): <strong><?php echo $cpt_count; ?></strong>
            <?php if ($db_count < $cpt_count) : ?>
              &nbsp;<span style="color:#e24b4a;font-weight:700;">⚠ Hay tours sin sincronizar</span>
            <?php elseif ($db_count > 0) : ?>
              &nbsp;<span style="color:#1D9E75;font-weight:700;">✓ Sincronizados</span>
            <?php endif; ?>
          </p>
          <form method="post">
            <?php wp_nonce_field('amir_sync_tours','amir_sync_nonce'); ?>
            <button type="submit" class="button button-primary">🔄 Sincronizar todos los tours ahora</button>
          </form>
        </div>
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

            // ── Logo media upload ─────────────────────────────────────────
            // Inicializar después de que todos los scripts del footer estén cargados
            // (wp.media se inyecta en el footer, este inline-script corre antes).
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

    private function sync_all_tours(): int {
        global $wpdb;

        $posts = get_posts( [
            'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );

        $count = 0;
        foreach ( $posts as $post ) {
            $existing_id = (int) get_post_meta( $post->ID, '_amir_tour_db_id', true );

            $data = [
                'slug'           => $post->post_name ?: sanitize_title( $post->post_title ),
                'status'         => 'active',
                'price_model'    => get_post_meta( $post->ID, '_amir_price_model', true ) ?: 'percapita',
                'name_es'        => $post->post_title,
                'name_en'        => get_post_meta( $post->ID, '_amir_name_en', true ) ?: $post->post_title,
                'description_es' => wp_strip_all_tags( $post->post_content ),
                'description_en' => wp_strip_all_tags( get_post_meta( $post->ID, '_amir_description_en', true ) ?: '' ),
                'what_to_expect_es' => get_post_meta( $post->ID, '_amir_what_to_expect_es', true ) ?: '',
                'what_to_expect_en' => get_post_meta( $post->ID, '_amir_what_to_expect_en', true ) ?: '',
                'duration_minutes'  => (int) get_post_meta( $post->ID, '_amir_duration_minutes', true ),
                'min_age'           => (int) get_post_meta( $post->ID, '_amir_min_age', true ),
                'max_capacity'      => (int) get_post_meta( $post->ID, '_amir_max_capacity', true ) ?: 10,
                'min_passengers'    => (int) get_post_meta( $post->ID, '_amir_min_passengers', true ) ?: 1,
                'languages'         => get_post_meta( $post->ID, '_amir_languages', true ) ?: '["Español"]',
                'meeting_point_es'  => get_post_meta( $post->ID, '_amir_meeting_point_es', true ) ?: '',
                'meeting_point_en'  => get_post_meta( $post->ID, '_amir_meeting_point_en', true ) ?: '',
                'meeting_lat'       => get_post_meta( $post->ID, '_amir_meeting_lat', true ) ?: null,
                'meeting_lng'       => get_post_meta( $post->ID, '_amir_meeting_lng', true ) ?: null,
                'gallery_images'    => '[]',
                'sort_order'        => (int) get_post_meta( $post->ID, '_amir_sort_order', true ),
            ];

            if ( $existing_id ) {
                $wpdb->update( "{$wpdb->prefix}amir_tours", $data, [ 'id' => $existing_id ] );
                $count++;
            } else {
                $inserted = $wpdb->insert( "{$wpdb->prefix}amir_tours", $data );
                if ( $inserted ) {
                    update_post_meta( $post->ID, '_amir_tour_db_id', (int) $wpdb->insert_id );
                    $count++;
                }
            }
        }

        // Limpiar caché de transients
        delete_transient( 'amir_tours_list_es' );
        delete_transient( 'amir_tours_list_en' );

        return $count;
    }

    private function save_settings(): void {
        $options = [
            'amir_stripe_mode'             => 'sanitize_key',
            'amir_stripe_pk_test'          => 'sanitize_text_field',
            'amir_stripe_sk_test'          => 'sanitize_text_field',
            'amir_stripe_pk_live'          => 'sanitize_text_field',
            'amir_stripe_sk_live'          => 'sanitize_text_field',
            'amir_stripe_webhook_secret'   => 'sanitize_text_field',
            'amir_admin_email'             => 'sanitize_email',
            'amir_wa_phone'                => 'sanitize_text_field',
            'amir_pending_expire_mins'     => 'absint',
            'amir_review_delay_days'       => 'absint',
            'amir_usd_rate_mode'           => 'sanitize_key',
            'amir_usd_rate_manual'         => 'floatval',
            'amir_google_review_url'       => 'esc_url_raw',
            'amir_tripadvisor_review_url'  => 'esc_url_raw',
            // Brand & content
            'amir_brand_logo_id'           => 'absint',
            'amir_brand_logo_url'          => 'esc_url_raw',
            'amir_company_name'            => 'sanitize_text_field',
            'amir_company_tagline_es'      => 'sanitize_text_field',
            'amir_company_tagline_en'      => 'sanitize_text_field',
            'amir_email_recs_es'           => 'sanitize_textarea_field',
            'amir_email_recs_en'           => 'sanitize_textarea_field',
            'amir_voucher_recs_es'         => 'sanitize_textarea_field',
            'amir_voucher_recs_en'         => 'sanitize_textarea_field',
        ];

        foreach ($options as $key => $sanitizer) {
            if (isset($_POST[$key])) {
                update_option($key, call_user_func($sanitizer, $_POST[$key]));
            }
        }

        // Brand color: validate hex format
        if ( isset($_POST['amir_brand_color_hex']) ) {
            $color = sanitize_text_field($_POST['amir_brand_color_hex']);
            if ( preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ) {
                update_option('amir_brand_color', $color);
            }
        }

        $delete = isset($_POST['amir_delete_data_on_uninstall']) ? '1' : '0';
        update_option('amir_delete_data_on_uninstall', $delete);

        // Invalidar caché de tipo de cambio si cambió el modo
        delete_transient('amir_usd_mxn_rate');
    }

    private function input_style(): string {
        return 'border:1px solid #c3d9d0;border-radius:6px;padding:8px 11px;font-size:13px;';
    }
}
