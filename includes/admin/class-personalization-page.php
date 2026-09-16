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

        if ( isset($_POST['amir_personalization_nonce']) && wp_verify_nonce($_POST['amir_personalization_nonce'],'amir_personalization') ) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $this->tt( 'Personalización guardada.', 'Personalization saved.' ) ) . '</p></div>';
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

        <h1>🎨 <?php echo esc_html( $this->tt( 'Personalización', 'Personalization' ) ); ?></h1>
        <p style="color:#5a7068;font-size:13px;max-width:70ch;margin-top:-8px;">
          <?php echo wp_kses_post( sprintf(
            $this->tt(
              'Cómo se ve y qué dice el plugin de cara al cliente — colores del widget, marca, y los textos de email/voucher/política. La configuración operativa (pasarelas de pago, moneda, idiomas) vive en %s.',
              'How the plugin looks and what it says to the customer — widget colors, branding, and the email/voucher/policy texts. Operational settings (payment gateways, currency, languages) live in %s.'
            ),
            '<a href="' . esc_url( admin_url('admin.php?page=amir-settings') ) . '">' . esc_html( $this->tt('Configuración','Settings') ) . '</a>'
          ) ); ?>
        </p>
        <form method="post">
          <?php wp_nonce_field('amir_personalization','amir_personalization_nonce'); ?>

          <!-- Widget de reserva -->
          <div class="ab-settings-section">
            <h3>🎨 <?php echo esc_html( $this->tt( 'Widget de reserva', 'Booking widget' ) ); ?></h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              <?php echo esc_html( $this->tt( 'Personalización visual de', 'Visual customization of' ) ); ?> <code>[flow_booking]</code> — <?php echo esc_html( $this->tt( 'se aplica al instante, sin recompilar nada.', 'applies instantly, nothing to rebuild.' ) ); ?>
            </p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Color principal', 'Main color' ) ); ?></label>
                <div style="display:flex;align-items:center;gap:10px;">
                  <input type="color" name="amir_widget_color" id="amir-widget-color"
                         value="<?php echo esc_attr( get_option( 'amir_widget_color', '#1D9E75' ) ); ?>"
                         style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                  <input type="text" name="amir_widget_color_hex" id="amir-widget-color-hex"
                         value="<?php echo esc_attr( get_option( 'amir_widget_color', '#1D9E75' ) ); ?>"
                         style="width:90px;font-family:monospace;" placeholder="#1D9E75" />
                </div>
                <p class="ab-hint"><?php echo esc_html( $this->tt( 'Los tonos oscuro/claro/medio (botones, fondos, acentos) se calculan automáticamente a partir de este color.', 'The dark/light/medium shades (buttons, backgrounds, accents) are calculated automatically from this color.' ) ); ?></p>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Tipografía', 'Typography' ) ); ?></label>
                <select name="amir_widget_font">
                  <?php foreach ( \AmirBooking\Core\WidgetTheme::fonts() as $key => $f ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( \AmirBooking\Core\WidgetTheme::font_key(), $key ); ?>><?php echo esc_html( $f['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Tamaño de texto', 'Text size' ) ); ?></label>
                <select name="amir_widget_font_scale">
                  <?php foreach ( \AmirBooking\Core\WidgetTheme::scales() as $key => $s ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( \AmirBooking\Core\WidgetTheme::scale_key(), $key ); ?>><?php echo esc_html( $s['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="ab-hint"><?php echo esc_html( $this->tt( 'Los campos de formulario y los botones nunca bajan de su tamaño mínimo táctil/legible, aunque elijas "Compacto".', 'Form fields and buttons never go below their minimum tap-friendly/legible size, even if you choose "Compact".' ) ); ?></p>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Radio de esquinas', 'Corner radius' ) ); ?></label>
                <select name="amir_widget_radius">
                  <?php foreach ( \AmirBooking\Core\WidgetTheme::radii() as $key => $r ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( \AmirBooking\Core\WidgetTheme::radius_key(), $key ); ?>><?php echo esc_html( $r['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Texto de los pasos', 'Step text' ) ); ?></label>
                <select name="amir_widget_progress_labels">
                  <option value="1" <?php selected( \AmirBooking\Core\WidgetTheme::progress_labels(), true ); ?>><?php echo esc_html( $this->tt( 'Mostrar (ícono + nombre del paso)', 'Show (icon + step name)' ) ); ?></option>
                  <option value="0" <?php selected( \AmirBooking\Core\WidgetTheme::progress_labels(), false ); ?>><?php echo esc_html( $this->tt( 'Ocultar (solo íconos)', 'Hide (icons only)' ) ); ?></option>
                </select>
                <p class="ab-hint"><?php echo esc_html( $this->tt( 'La barra de progreso siempre muestra un ícono por paso — esto solo agrega o quita el nombre debajo.', 'The progress bar always shows an icon per step — this only adds or removes the name below it.' ) ); ?></p>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Estilo del widget', 'Widget style' ) ); ?></label>
                <select name="amir_widget_style">
                  <?php foreach ( \AmirBooking\Core\WidgetTheme::styles() as $key => $s ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( \AmirBooking\Core\WidgetTheme::style_key(), $key ); ?>><?php echo esc_html( $s['label'] ); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="ab-hint"><?php echo esc_html( $this->tt( 'Mismos 7 pasos y la misma lógica de reserva — Fullwidth solo cambia el ancho y la barra de progreso a un estilo más grande, pensado para páginas sin sidebar.', 'Same 7 steps and the same booking logic — Fullwidth only changes the width and the progress bar to a bigger style, meant for pages without a sidebar.' ) ); ?></p>
              </div>
            </div>
          </div>

          <?php if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) : ?>
          <!-- Plantilla de detalle de tour — Pro y superior -->
          <div class="ab-settings-section">
            <h3>🖼 <?php echo esc_html( $this->tt( 'Plantilla de detalle de tour', 'Tour detail template' ) ); ?></h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              <?php echo wp_kses_post( $this->tt(
                'Cómo se ve la ficha de cada tour (<code>/tour/nombre-del-tour/</code>). Aplica a toda la instalación, no por tour. Si copiaste <code>single-amir_tour.php</code> directo a tu tema, ese archivo sigue ganando — para usar la Inmersiva desde el tema, copiá <code>single-amir_tour-immersive.php</code> en su lugar.',
                "How each tour's page looks (<code>/tour/tour-name/</code>). Applies to the whole install, not per tour. If you copied <code>single-amir_tour.php</code> straight into your theme, that file still wins — to use the Immersive one from the theme, copy <code>single-amir_tour-immersive.php</code> instead."
              ) ); ?>
            </p>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Plantilla activa', 'Active template' ) ); ?></label>
              <select name="amir_tour_template" style="max-width:280px;">
                <?php foreach ( \AmirBooking\Core\TemplateLoader::tour_template_options() as $key => $label ) : ?>
                  <option value="<?php echo esc_attr( $key ); ?>" <?php selected( get_option( 'amir_tour_template', 'classic' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="ab-hint">
                <?php echo wp_kses_post( $this->tt(
                  '<strong>Clásica</strong>: hero con overlay + dos columnas (contenido y widget de reserva sticky al costado) — la de siempre.<br><strong>Inmersiva</strong>: hero a pantalla completa, contenido en una sola columna centrada, galería tipo filmstrip horizontal, y una barra flotante de "Reservar" al hacer scroll.',
                  '<strong>Classic</strong>: hero with overlay + two columns (content and a sticky booking widget on the side) — the usual one.<br><strong>Immersive</strong>: full-screen hero, single centered content column, horizontal filmstrip-style gallery, and a floating "Book" bar on scroll.'
                ) ); ?>
              </p>
            </div>
            <div class="ab-field" style="margin-top:14px;">
              <label><?php echo esc_html( $this->tt( 'Color del título (ficha de tour y de habitación)', 'Title color (tour and room pages)' ) ); ?></label>
              <div style="display:flex;align-items:center;gap:10px;">
                <input type="color" name="amir_detail_title_color" id="amir-detail-title-color"
                       value="<?php echo esc_attr( get_option( 'amir_detail_title_color', '#ffffff' ) ); ?>"
                       style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                <input type="text" name="amir_detail_title_color_hex" id="amir-detail-title-color-hex"
                       value="<?php echo esc_attr( get_option( 'amir_detail_title_color', '#ffffff' ) ); ?>"
                       style="width:90px;font-family:monospace;" placeholder="#ffffff" />
              </div>
              <p class="ab-hint">
                <?php echo esc_html( $this->tt(
                  'Por defecto blanco (el título va sobre la foto de portada). Si tu tema fuerza otro color en los títulos (ej. azul) y el título deja de verse contra la foto, cambialo acá — se aplica con prioridad sobre el estilo del tema.',
                  "Defaults to white (the title sits over the cover photo). If your theme forces another color on titles (e.g. blue) and the title becomes hard to read against the photo, change it here — it applies with priority over the theme's style."
                ) ); ?>
              </p>
            </div>
          </div>
          <?php endif; ?>

          <!-- ── Identidad de marca ── -->
          <div class="ab-settings-section">
            <h3>🎨 <?php echo esc_html( $this->tt( 'Identidad de marca', 'Brand identity' ) ); ?></h3>

            <!-- Logo -->
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Logo (cabecera del email y voucher)', 'Logo (email and voucher header)' ) ); ?></label>
              <?php
              $logo_url = get_option('amir_brand_logo_url', '');
              $logo_id  = (int) get_option('amir_brand_logo_id', 0);
              ?>
              <div style="display:flex;align-items:center;gap:14px;margin-top:6px;">
                <div id="amir-logo-preview" style="width:120px;height:48px;border:1px solid #c3d9d0;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f9fafb;">
                  <?php if ($logo_url): ?>
                    <img src="<?php echo esc_url($logo_url); ?>" style="max-width:118px;max-height:46px;object-fit:contain;" />
                  <?php else: ?>
                    <span style="font-size:11px;color:#5a7068;"><?php echo esc_html( $this->tt( 'Sin logo', 'No logo' ) ); ?></span>
                  <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                  <button type="button" id="amir-logo-upload-btn" class="button">
                    📁 <?php echo esc_html( $this->tt( 'Seleccionar imagen', 'Select image' ) ); ?>
                  </button>
                  <?php if ($logo_url): ?>
                  <button type="button" id="amir-logo-remove-btn" class="button" style="color:#e24b4a;border-color:#fecaca;">
                    ✕ <?php echo esc_html( $this->tt( 'Eliminar logo', 'Remove logo' ) ); ?>
                  </button>
                  <?php endif; ?>
                </div>
              </div>
              <input type="hidden" name="amir_brand_logo_id"  id="amir-logo-id"  value="<?php echo esc_attr($logo_id); ?>" />
              <input type="hidden" name="amir_brand_logo_url" id="amir-logo-url" value="<?php echo esc_attr($logo_url); ?>" />
              <p class="ab-hint"><?php echo esc_html( $this->tt( 'Recomendado: PNG o SVG con fondo transparente, altura mínima 100px. Se usa en el header del email y el voucher PDF.', 'Recommended: PNG or SVG with a transparent background, minimum height 100px. Used in the email header and the PDF voucher.' ) ); ?></p>
            </div>

            <!-- Color y nombre -->
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Color principal', 'Main color' ) ); ?></label>
                <div style="display:flex;align-items:center;gap:10px;">
                  <input type="color" name="amir_brand_color" id="amir-brand-color"
                         value="<?php echo esc_attr(get_option('amir_brand_color','#1D9E75')); ?>"
                         style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                  <input type="text" name="amir_brand_color_hex" id="amir-brand-color-hex"
                         value="<?php echo esc_attr(get_option('amir_brand_color','#1D9E75')); ?>"
                         style="width:90px;font-family:monospace;" placeholder="#1D9E75" />
                </div>
                <p class="ab-hint"><?php echo esc_html( $this->tt( 'Color del header del email y acentos del voucher PDF.', 'Email header color and PDF voucher accents.' ) ); ?></p>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Nombre de la empresa', 'Company name' ) ); ?></label>
                <input type="text" name="amir_company_name"
                       value="<?php echo esc_attr(get_option('amir_company_name','')); ?>"
                       placeholder="TourFlow" />
                <p class="ab-hint"><?php echo esc_html( $this->tt( 'Aparece en el pie del email y del voucher.', 'Shows in the footer of the email and the voucher.' ) ); ?></p>
              </div>
            </div>

            <!-- Eslogan -->
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Eslogan (%s)', 'Tagline (%s)' ), 'Español' ) ); ?></label>
                <input type="text" name="amir_company_tagline_es"
                       value="<?php echo esc_attr(get_option('amir_company_tagline_es','')); ?>"
                       placeholder="Ej: Experiencias en Bacalar · Quintana Roo, México" />
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Eslogan (%s)', 'Tagline (%s)' ), 'English' ) ); ?></label>
                <input type="text" name="amir_company_tagline_en"
                       value="<?php echo esc_attr(get_option('amir_company_tagline_en','')); ?>"
                       placeholder="Ex: Experiences in Bacalar · Quintana Roo, Mexico" />
              </div>
            </div>

            <!-- Prefijo de reserva -->
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Prefijo de número de reserva', 'Booking number prefix' ) ); ?></label>
                <input type="text" name="amir_booking_ref_prefix" maxlength="10"
                       value="<?php echo esc_attr(get_option('amir_booking_ref_prefix','BK')); ?>"
                       placeholder="BK" style="width:120px;text-transform:uppercase;" />
                <p class="ab-hint"><?php echo wp_kses_post( sprintf(
                  $this->tt( 'Ejemplo: %s. Solo letras y números, sin espacios. Las reservas ya creadas conservan su número actual — esto solo aplica a las nuevas.', 'Example: %s. Letters and numbers only, no spaces. Existing bookings keep their current number — this only applies to new ones.' ),
                  '<strong>BK-' . date('Y') . '-00001</strong>'
                ) ); ?></p>
              </div>
            </div>
          </div>

          <!-- ── Contenido del email ── -->
          <div class="ab-settings-section">
            <h3>📧 <?php echo esc_html( $this->tt( 'Contenido del email de confirmación', 'Confirmation email content' ) ); ?></h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;"><?php echo esc_html( $this->tt( 'Una recomendación por línea. Se muestran como lista en el email de confirmación.', 'One recommendation per line. Shown as a list in the confirmation email.' ) ); ?></p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Recomendaciones (%s)', 'Recommendations (%s)' ), 'Español' ) ); ?></label>
                <textarea name="amir_email_recs_es" rows="7" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="Llega 10 minutos antes al punto de encuentro.&#10;Usa ropa cómoda y protector solar biodegradable.&#10;Trae agua y snacks ligeros.&#10;Lleva tu documento de identidad."><?php
                  echo esc_textarea(get_option('amir_email_recs_es',
                    "Llega 10 minutos antes al punto de encuentro.\nUsa ropa cómoda y protector solar biodegradable.\nTrae agua y snacks ligeros.\nLleva tu documento de identidad."));
                ?></textarea>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Recomendaciones (%s)', 'Recommendations (%s)' ), 'English' ) ); ?></label>
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
            <h3>📄 <?php echo esc_html( $this->tt( 'Contenido del voucher PDF', 'PDF voucher content' ) ); ?></h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;"><?php echo esc_html( $this->tt( 'Una recomendación por línea. Se muestran en la sección "Recuerda llevar" del PDF.', 'One recommendation per line. Shown in the "Remember to bring" section of the PDF.' ) ); ?></p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Recomendaciones voucher (%s)', 'Voucher recommendations (%s)' ), 'Español' ) ); ?></label>
                <textarea name="amir_voucher_recs_es" rows="7" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="Ropa cómoda y traje de baño&#10;Protector solar biodegradable (obligatorio)&#10;Agua y snacks ligeros"><?php
                  echo esc_textarea(get_option('amir_voucher_recs_es',
                    "Ropa cómoda y traje de baño\nProtector solar biodegradable (obligatorio)\nAgua y snacks ligeros\nDocumento de identidad\nCámara en bolsa impermeable\nLlega 10 min antes a tu hora de salida"));
                ?></textarea>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Recomendaciones voucher (%s)', 'Voucher recommendations (%s)' ), 'English' ) ); ?></label>
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
            <h3>📋 <?php echo esc_html( $this->tt( 'Política de cancelación (widget de reserva)', 'Cancellation policy (booking widget)' ) ); ?></h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              <?php echo esc_html( $this->tt(
                'Una línea por renglón. Se muestra en el paso de resumen antes de pagar, junto al checkbox de aceptación. Vacío = se muestran las 3 líneas por defecto ("7+ días: reembolso completo", etc.).',
                'One line per row. Shown in the summary step before paying, next to the acceptance checkbox. Empty = the 3 default lines are shown ("7+ days: full refund", etc.).'
              ) ); ?>
            </p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Política (%s)', 'Policy (%s)' ), 'Español' ) ); ?></label>
                <textarea name="amir_policy_text_es" rows="4" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="✓ 7+ días antes: reembolso completo&#10;▸ 3–6 días antes: reembolso del 50 %&#10;✕ Menos de 3 días: sin reembolso"><?php
                  echo esc_textarea( get_option( 'amir_policy_text_es', '' ) );
                ?></textarea>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Política (%s)', 'Policy (%s)' ), 'English' ) ); ?></label>
                <textarea name="amir_policy_text_en" rows="4" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="✓ 7+ days before: full refund&#10;▸ 3–6 days before: 50% refund&#10;✕ Less than 3 days: no refund"><?php
                  echo esc_textarea( get_option( 'amir_policy_text_en', '' ) );
                ?></textarea>
              </div>
            </div>
          </div>

          <!-- ── Términos y condiciones en el widget (GDPR: checkbox propio, separado de la cancelación) ── -->
          <div class="ab-settings-section">
            <h3>📜 <?php echo esc_html( $this->tt( 'Términos y condiciones (widget de reserva)', 'Terms and conditions (booking widget)' ) ); ?></h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              <?php echo esc_html( $this->tt(
                'Checkbox separado del de la política de cancelación — para consentimientos GDPR específicos, no empaquetados. Una línea por renglón. Vacío = el checkbox se muestra igual, sin caja de texto arriba.',
                "Checkbox separate from the cancellation policy one — for specific GDPR consents, not bundled together. One line per row. Empty = the checkbox still shows, without a text box above it."
              ) ); ?>
            </p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Términos (%s)', 'Terms (%s)' ), 'Español' ) ); ?></label>
                <textarea name="amir_terms_text_es" rows="4" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="Al reservar aceptas nuestros términos de servicio y el tratamiento de tus datos personales según nuestra política de privacidad."><?php
                  echo esc_textarea( get_option( 'amir_terms_text_es', '' ) );
                ?></textarea>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Términos (%s)', 'Terms (%s)' ), 'English' ) ); ?></label>
                <textarea name="amir_terms_text_en" rows="4" style="<?php echo $this->input_style_ta(); ?>"
                          placeholder="By booking you accept our terms of service and the processing of your personal data per our privacy policy."><?php
                  echo esc_textarea( get_option( 'amir_terms_text_en', '' ) );
                ?></textarea>
              </div>
            </div>
          </div>

          <button type="submit" class="button button-primary" style="padding:10px 28px;font-size:14px;"><?php echo esc_html( $this->tt( 'Guardar personalización', 'Save personalization' ) ); ?></button>
        </form>

        <?php if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) : ?>
          <?php $this->render_shortcode_generator(); ?>
        <?php endif; ?>

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

            var titleColorInput = document.getElementById('amir-detail-title-color');
            var titleHexInput   = document.getElementById('amir-detail-title-color-hex');
            if ( titleColorInput && titleHexInput ) {
                titleColorInput.addEventListener('input', function(){ titleHexInput.value = this.value; });
                titleHexInput.addEventListener('input', function(){
                    var v = this.value.trim();
                    if ( /^#[0-9A-Fa-f]{6}$/.test(v) ) { titleColorInput.value = v; }
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
                            alert('<?php echo esc_js( $this->tt( 'El Media Library de WordPress no está disponible. Asegúrate de que wp_enqueue_media() esté activo.', 'The WordPress Media Library is not available. Make sure wp_enqueue_media() is active.' ) ); ?>');
                            return;
                        }

                        if ( mediaFrame ) { mediaFrame.open(); return; }

                        mediaFrame = wp.media({
                            title:    '<?php echo esc_js( $this->tt( 'Seleccionar logo', 'Select logo' ) ); ?>',
                            button:   { text: '<?php echo esc_js( $this->tt( 'Usar como logo', 'Use as logo' ) ); ?>' },
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
                        preview.innerHTML = '<span style="font-size:11px;color:#5a7068;"><?php echo esc_js( $this->tt( 'Sin logo', 'No logo' ) ); ?></span>';
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

    /**
     * Generador de shortcode para [flow_tour_list] (§ 13.3 CONTRIBUTING.md).
     * TourList.jsx ya lee todos estos atributos — este bloque solo arma el
     * texto para copiar, no guarda nada (no vive dentro del <form> de
     * arriba, ni tiene su propio nonce/submit, es 100% client-side).
     * Los atributos y sus defaults tienen que reflejar exactamente
     * Shortcodes::tour_list() — si ese método cambia, actualizar acá también.
     */
    private function render_shortcode_generator(): void {
        $tours = get_posts( [
            'post_type'      => 'amir_tour',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );
        $categories = get_terms( [
            'taxonomy'   => \AmirBooking\CPT\TourPostType::TAXONOMY,
            'hide_empty' => false,
        ] );
        if ( is_wp_error( $categories ) ) {
            $categories = [];
        }
        global $wpdb;
        $providers = $wpdb->get_results( "SELECT id, business_name FROM {$wpdb->prefix}amir_providers WHERE active = 1 ORDER BY business_name ASC" );
        ?>
        <div class="ab-settings-section">
          <h3>🧩 <?php echo esc_html( $this->tt( 'Generador de shortcode — grilla de tours', 'Shortcode generator — tour grid' ) ); ?></h3>
          <p style="font-size:12px;color:#5a7068;margin:0 0 16px;">
            <?php echo wp_kses_post( $this->tt(
              'Armá el <code>[flow_tour_list]</code> con los atributos que quieras y copialo — no hace falta escribirlos a mano. Se actualiza solo mientras cambiás las opciones.',
              "Build the <code>[flow_tour_list]</code> shortcode with whatever attributes you want and copy it — no need to write them by hand. It updates on its own as you change the options."
            ) ); ?>
          </p>

          <div class="ab-field-row">
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Columnas', 'Columns' ) ); ?></label>
              <select id="amir-sc-columns">
                <option value="2">2</option>
                <option value="3" selected>3</option>
                <option value="4">4</option>
              </select>
            </div>
            <div class="ab-field">
              <label>Layout</label>
              <select id="amir-sc-layout">
                <option value="grid" selected><?php echo esc_html( $this->tt( 'Grilla', 'Grid' ) ); ?></option>
                <option value="list"><?php echo esc_html( $this->tt( 'Lista', 'List' ) ); ?></option>
              </select>
            </div>
          </div>

          <div class="ab-field-row">
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Cantidad máxima a mostrar', 'Maximum amount to show' ) ); ?></label>
              <input type="number" id="amir-sc-limit" value="0" min="0" style="max-width:120px;" />
              <p class="ab-hint"><?php echo esc_html( $this->tt( '0 = sin límite (todos los tours activos, salvo que elijas tours específicos abajo).', '0 = no limit (all active tours, unless you choose specific tours below).' ) ); ?></p>
            </div>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Relación de aspecto de la imagen', 'Image aspect ratio' ) ); ?></label>
              <select id="amir-sc-image-ratio">
                <option value="4/3" selected><?php echo esc_html( $this->tt( '4:3 (por defecto)', '4:3 (default)' ) ); ?></option>
                <option value="16/9">16:9 (<?php echo esc_html( $this->tt( 'panorámica', 'wide' ) ); ?>)</option>
                <option value="1/1">1:1 (<?php echo esc_html( $this->tt( 'cuadrada', 'square' ) ); ?>)</option>
                <option value="3/4">3:4 (<?php echo esc_html( $this->tt( 'vertical', 'vertical' ) ); ?>)</option>
              </select>
            </div>
          </div>

          <div class="ab-field">
            <label><?php echo esc_html( $this->tt( 'Tours específicos (opcional)', 'Specific tours (optional)' ) ); ?></label>
            <div style="max-height:160px;overflow-y:auto;border:1px solid #c3d9d0;border-radius:6px;padding:10px 12px;background:#fafffe;">
              <?php if ( empty( $tours ) ) : ?>
                <p style="font-size:12px;color:#5a7068;margin:0;"><?php echo esc_html( $this->tt( 'No hay tours publicados todavía.', 'No tours published yet.' ) ); ?></p>
              <?php else : foreach ( $tours as $t ) :
                $db_id = (int) get_post_meta( $t->ID, '_amir_tour_db_id', true );
                if ( ! $db_id ) continue;
              ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:400;padding:4px 0;cursor:pointer;">
                  <input type="checkbox" class="amir-sc-tour-cb" value="<?php echo esc_attr( $db_id ); ?>" />
                  <?php echo esc_html( $t->post_title ); ?>
                </label>
              <?php endforeach; endif; ?>
            </div>
            <p class="ab-hint"><?php echo esc_html( $this->tt( 'Sin marcar ninguno = se usan todos los tours activos (respetando el orden y el límite de arriba).', "Not checking any = all active tours are used (respecting the order and limit above)." ) ); ?></p>
          </div>

          <div class="ab-field-row">
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Categoría (opcional)', 'Category (optional)' ) ); ?></label>
              <select id="amir-sc-category">
                <option value=""><?php echo esc_html( $this->tt( '— Todas —', '— All —' ) ); ?></option>
                <?php foreach ( $categories as $cat ) : ?>
                  <option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="ab-hint"><?php echo esc_html( $this->tt(
                'Muestra solo tours de esa categoría — hoy es un filtro fijo (para armar una página "Tours en barco", por ejemplo), no interactivo para el visitante todavía.',
                'Shows only tours from that category — today it\'s a fixed filter (to build a "Boat tours" page, for example), not yet interactive for the visitor.'
              ) ); ?></p>
            </div>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Origen de los tours', 'Tour source' ) ); ?></label>
              <select id="amir-sc-source">
                <option value="all" selected><?php echo esc_html( $this->tt( 'Todos (propios + proveedores)', 'All (own + providers)' ) ); ?></option>
                <option value="own"><?php echo esc_html( $this->tt( 'Solo tours propios', 'Only own tours' ) ); ?></option>
                <option value="provider"><?php echo esc_html( $this->tt( 'Solo tours de proveedores externos', 'Only external provider tours' ) ); ?></option>
              </select>
            </div>
          </div>

          <?php if ( ! empty( $providers ) ) : ?>
          <div class="ab-field" id="amir-sc-provider-wrap" style="display:none;">
            <label><?php echo esc_html( $this->tt( 'Proveedor específico (opcional)', 'Specific provider (optional)' ) ); ?></label>
            <select id="amir-sc-provider">
              <option value=""><?php echo esc_html( $this->tt( '— Todos los proveedores —', '— All providers —' ) ); ?></option>
              <?php foreach ( $providers as $p ) : ?>
                <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html( $p->business_name ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="ab-field-row">
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Color de acento', 'Accent color' ) ); ?></label>
              <div style="display:flex;align-items:center;gap:10px;">
                <input type="color" id="amir-sc-accent" value="<?php echo esc_attr( get_option( 'amir_widget_color', '#1D9E75' ) ); ?>" style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                <input type="text" id="amir-sc-accent-hex" value="<?php echo esc_attr( get_option( 'amir_widget_color', '#1D9E75' ) ); ?>" style="width:90px;font-family:monospace;" />
              </div>
            </div>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Radio de esquinas (px)', 'Corner radius (px)' ) ); ?></label>
              <input type="number" id="amir-sc-radius" value="16" min="0" max="40" style="max-width:120px;" />
            </div>
          </div>

          <div class="ab-field-row">
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Fondo de la tarjeta', 'Card background' ) ); ?></label>
              <div style="display:flex;align-items:center;gap:10px;">
                <input type="color" id="amir-sc-bg" value="#ffffff" style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                <input type="text" id="amir-sc-bg-hex" value="#ffffff" style="width:90px;font-family:monospace;" />
              </div>
            </div>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Color de texto', 'Text color' ) ); ?></label>
              <div style="display:flex;align-items:center;gap:10px;">
                <input type="color" id="amir-sc-text" value="#1a2e24" style="width:48px;height:36px;border:1px solid #c3d9d0;border-radius:6px;padding:2px;cursor:pointer;" />
                <input type="text" id="amir-sc-text-hex" value="#1a2e24" style="width:90px;font-family:monospace;" />
              </div>
            </div>
          </div>

          <div class="ab-field">
            <label><?php echo esc_html( $this->tt( 'Mostrar en la tarjeta', 'Show on the card' ) ); ?></label>
            <div style="display:flex;flex-wrap:wrap;gap:14px;">
              <?php
              $toggles = [
                  'excerpt'           => $this->tt( 'Descripción corta', 'Short description' ),
                  'price'             => $this->tt( 'Precio desde', 'Price from' ),
                  'age'               => $this->tt( 'Edad mínima', 'Minimum age' ),
                  'duration'          => $this->tt( 'Duración', 'Duration' ),
                  'languages'         => $this->tt( 'Idiomas', 'Languages' ),
                  'capacity'          => $this->tt( 'Capacidad', 'Capacity' ),
                  'free_cancellation' => $this->tt( 'Badge "Cancelación gratis"', '"Free cancellation" badge' ),
              ];
              foreach ( $toggles as $key => $label ) : ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;cursor:pointer;">
                  <input type="checkbox" class="amir-sc-show" data-key="<?php echo esc_attr( $key ); ?>" checked />
                  <?php echo esc_html( $label ); ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="ab-field-row">
            <div class="ab-field">
              <label><?php echo esc_html( sprintf( $this->tt( 'Texto del botón (%s)', 'Button text (%s)' ), 'Español' ) ); ?></label>
              <input type="text" id="amir-sc-cta-es" value="Reservar ahora" />
            </div>
            <div class="ab-field">
              <label><?php echo esc_html( sprintf( $this->tt( 'Texto del botón (%s)', 'Button text (%s)' ), 'English' ) ); ?></label>
              <input type="text" id="amir-sc-cta-en" value="Book now" />
            </div>
          </div>

          <div class="ab-field" style="margin-top:18px;">
            <label><?php echo esc_html( $this->tt( 'Shortcode generado', 'Generated shortcode' ) ); ?></label>
            <textarea id="amir-sc-output" readonly rows="3" style="width:100%;font-family:monospace;font-size:12px;border:1px solid #c3d9d0;border-radius:6px;padding:10px 12px;box-sizing:border-box;background:#f9fafb;resize:vertical;"></textarea>
            <button type="button" id="amir-sc-copy-btn" class="button" style="margin-top:8px;">📋 <?php echo esc_html( $this->tt( 'Copiar shortcode', 'Copy shortcode' ) ); ?></button>
            <span id="amir-sc-copied" style="display:none;margin-left:8px;font-size:12px;color:#1D9E75;font-weight:600;"><?php echo esc_html( $this->tt( '¡Copiado!', 'Copied!' ) ); ?></span>
          </div>
        </div>

        <script>
        (function(){
          function syncColorHex(colorId, hexId){
            var colorEl = document.getElementById(colorId);
            var hexEl   = document.getElementById(hexId);
            if (!colorEl || !hexEl) return;
            colorEl.addEventListener('input', function(){ hexEl.value = this.value; buildShortcode(); });
            hexEl.addEventListener('input', function(){
              var v = this.value.trim();
              if (/^#[0-9A-Fa-f]{6}$/.test(v)) { colorEl.value = v; }
              buildShortcode();
            });
          }
          syncColorHex('amir-sc-accent', 'amir-sc-accent-hex');
          syncColorHex('amir-sc-bg', 'amir-sc-bg-hex');
          syncColorHex('amir-sc-text', 'amir-sc-text-hex');

          function esc(v){
            return String(v).replace(/"/g, '&quot;');
          }

          function buildShortcode(){
            var parts = ['[flow_tour_list'];

            parts.push('columns="' + document.getElementById('amir-sc-columns').value + '"');
            parts.push('layout="' + document.getElementById('amir-sc-layout').value + '"');

            var limit = parseInt(document.getElementById('amir-sc-limit').value, 10) || 0;
            if (limit > 0) parts.push('limit="' + limit + '"');

            var ids = Array.from(document.querySelectorAll('.amir-sc-tour-cb:checked')).map(function(cb){ return cb.value; });
            if (ids.length) parts.push('ids="' + ids.join(',') + '"');

            var categoryEl = document.getElementById('amir-sc-category');
            if (categoryEl && categoryEl.value) parts.push('category="' + esc(categoryEl.value) + '"');

            var sourceEl = document.getElementById('amir-sc-source');
            var source   = sourceEl ? sourceEl.value : 'all';
            if (source !== 'all') parts.push('source="' + source + '"');

            var providerEl = document.getElementById('amir-sc-provider');
            if (providerEl && source === 'provider' && providerEl.value) {
              parts.push('provider_id="' + parseInt(providerEl.value, 10) + '"');
            }

            parts.push('accent="' + esc(document.getElementById('amir-sc-accent-hex').value) + '"');
            parts.push('bg_color="' + esc(document.getElementById('amir-sc-bg-hex').value) + '"');
            parts.push('text_color="' + esc(document.getElementById('amir-sc-text-hex').value) + '"');
            parts.push('radius="' + (parseInt(document.getElementById('amir-sc-radius').value, 10) || 0) + '"');
            parts.push('image_ratio="' + document.getElementById('amir-sc-image-ratio').value + '"');

            document.querySelectorAll('.amir-sc-show').forEach(function(cb){
              parts.push('show_' + cb.dataset.key + '="' + (cb.checked ? 'yes' : 'no') + '"');
            });

            parts.push('cta_text_es="' + esc(document.getElementById('amir-sc-cta-es').value) + '"');
            parts.push('cta_text_en="' + esc(document.getElementById('amir-sc-cta-en').value) + '"');

            document.getElementById('amir-sc-output').value = parts.join(' ') + ']';
          }

          document.querySelectorAll(
            '#amir-sc-columns, #amir-sc-layout, #amir-sc-limit, #amir-sc-image-ratio, #amir-sc-radius, #amir-sc-cta-es, #amir-sc-cta-en, #amir-sc-category, #amir-sc-source, #amir-sc-provider'
          ).forEach(function(el){ el.addEventListener('input', buildShortcode); el.addEventListener('change', buildShortcode); });

          document.querySelectorAll('.amir-sc-tour-cb, .amir-sc-show').forEach(function(el){
            el.addEventListener('change', buildShortcode);
          });

          // El selector de proveedor puntual solo tiene sentido con
          // source="provider" — se muestra/oculta según esa elección.
          var sourceSelect  = document.getElementById('amir-sc-source');
          var providerWrap  = document.getElementById('amir-sc-provider-wrap');
          if (sourceSelect && providerWrap) {
            var toggleProviderWrap = function(){
              providerWrap.style.display = (sourceSelect.value === 'provider') ? '' : 'none';
            };
            sourceSelect.addEventListener('change', toggleProviderWrap);
            toggleProviderWrap();
          }

          var copyBtn = document.getElementById('amir-sc-copy-btn');
          if (copyBtn) {
            copyBtn.addEventListener('click', function(){
              var output = document.getElementById('amir-sc-output');
              output.select();
              var copied = function(){
                document.getElementById('amir-sc-copied').style.display = '';
                setTimeout(function(){ document.getElementById('amir-sc-copied').style.display = 'none'; }, 1800);
              };
              if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(output.value).then(copied);
              } else {
                document.execCommand('copy');
                copied();
              }
            });
          }

          buildShortcode();
        })();
        </script>
        <?php
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
            'amir_terms_text_es'           => 'sanitize_textarea_field',
            'amir_terms_text_en'           => 'sanitize_textarea_field',
            'amir_brand_logo_id'           => 'absint',
            'amir_brand_logo_url'          => 'esc_url_raw',
        ];

        // wp_unslash() antes de sanitizar — WordPress le agrega backslashes a
        // TODO $_POST (wp_magic_quotes(), emulación histórica de magic_quotes_gpc)
        // antes de que el código del plugin lo vea. Sin esto, cualquier campo
        // con comilla/backslash (ej. "México's..." o simplemente una comilla
        // tipográfica) se guarda corrupto — bug real reportado por el
        // cliente en Eslogan/Tagline (2026-08-05).
        foreach ($options as $key => $sanitizer) {
            if (isset($_POST[$key])) {
                update_option($key, call_user_func($sanitizer, wp_unslash($_POST[$key])));
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
        if ( isset( $_POST['amir_widget_style'] ) ) {
            update_option( 'amir_widget_style', sanitize_key( $_POST['amir_widget_style'] ) );
        }

        // Color del título de la ficha (tour/habitación) — pedido real del
        // cliente 2026-08-04: el blanco fijo de siempre quedaba invisible en
        // instalaciones donde el tema fuerza otro color (ej. azul) en los
        // encabezados, sobre la foto de portada del hero.
        if ( isset( $_POST['amir_detail_title_color_hex'] ) ) {
            $title_color = sanitize_text_field( $_POST['amir_detail_title_color_hex'] );
            if ( preg_match( '/^#[0-9A-Fa-f]{6}$/', $title_color ) ) {
                update_option( 'amir_detail_title_color', $title_color );
            }
        }

        // Plantilla de detalle de tour: validar contra las claves conocidas.
        if ( isset( $_POST['amir_tour_template'] ) ) {
            $tpl = sanitize_key( $_POST['amir_tour_template'] );
            if ( array_key_exists( $tpl, \AmirBooking\Core\TemplateLoader::tour_template_options() ) ) {
                update_option( 'amir_tour_template', $tpl );
            }
        }

        // Prefijo de reserva: solo letras/números en mayúscula, sin espacios.
        if ( isset( $_POST['amir_booking_ref_prefix'] ) ) {
            $prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $_POST['amir_booking_ref_prefix'] ) );
            update_option( 'amir_booking_ref_prefix', $prefix !== '' ? substr( $prefix, 0, 10 ) : 'BK' );
        }
    }
}
