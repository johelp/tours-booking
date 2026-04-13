<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de gestión de disponibilidad.
 * Permite agregar, editar y eliminar reglas de bloqueo/excepción por tour.
 */
class AvailabilityPage {

    public function render(): void {
        $this->handle_actions();

        $tours        = $this->get_tours();
        $selected_tour = (int)( $_GET['tour_id'] ?? ( $tours[0]->id ?? 0 ) );
        $rules        = $selected_tour ? $this->get_rules( $selected_tour ) : [];
        $message      = get_transient('amir_avail_message');
        if ($message) delete_transient('amir_avail_message');
        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->styles(); ?>

        <h1>📅 Disponibilidad</h1>

        <?php if ($message) echo '<div class="notice notice-success is-dismissible"><p>'.$message.'</p></div>'; ?>

        <!-- Selector de tour -->
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:24px;flex-wrap:wrap;">
          <label style="font-weight:600;font-size:13px;">Tour:</label>
          <?php foreach ($tours as $t) : ?>
            <a href="<?php echo admin_url('admin.php?page=amir-availability&tour_id='.$t->id); ?>"
               style="padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;
                      background:<?php echo $selected_tour===$t->id?'#1D9E75':'#f0faf6'; ?>;
                      color:<?php echo $selected_tour===$t->id?'#fff':'#1D9E75'; ?>;
                      border:1.5px solid <?php echo $selected_tour===$t->id?'#1D9E75':'#c3d9d0'; ?>;">
              <?php echo esc_html($t->name_es); ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if (!$selected_tour) : ?>
          <p style="color:#5a7068;">Selecciona un tour para gestionar su disponibilidad.</p>
        <?php else : ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;">

          <!-- Reglas existentes -->
          <div>
            <div style="font-size:16px;font-weight:700;color:#1a2e24;margin-bottom:14px;">
              Reglas de disponibilidad
              <span style="font-size:12px;font-weight:400;color:#5a7068;margin-left:8px;">Mayor prioridad sobreescribe a menor</span>
            </div>

            <?php if (empty($rules)) : ?>
              <div style="background:#f8fdfb;border:1px dashed #c3d9d0;border-radius:10px;padding:20px;text-align:center;font-size:13px;color:#5a7068;">
                Sin reglas configuradas. Los días de operación se establecen en la edición del tour.
              </div>
            <?php else : ?>
              <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
              <table style="width:100%;border-collapse:collapse;">
                <thead>
                  <tr style="background:#f8fdfb;">
                    <th class="ab-th">Tipo</th>
                    <th class="ab-th">Días</th>
                    <th class="ab-th">Desde</th>
                    <th class="ab-th">Hasta</th>
                    <th class="ab-th">Prioridad</th>
                    <th class="ab-th">Motivo</th>
                    <th class="ab-th"></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule) : ?>
                  <tr style="border-bottom:1px solid #f5f5f5;">
                    <td class="ab-td">
                      <span style="background:<?php echo $rule->rule_type==='block'?'#fef2f2':'#e1f5ee'; ?>;
                                   color:<?php echo $rule->rule_type==='block'?'#e24b4a':'#0F6E56'; ?>;
                                   font-size:11px;font-weight:700;padding:3px 8px;border-radius:10px;">
                        <?php echo $rule->rule_type==='block'?'🚫 Bloquear':'✅ Permitir'; ?>
                      </span>
                    </td>
                    <td class="ab-td"><?php echo $this->format_weekdays($rule->weekdays); ?></td>
                    <td class="ab-td"><?php echo $rule->date_from ?: '—'; ?></td>
                    <td class="ab-td"><?php echo $rule->date_until ?: '—'; ?></td>
                    <td class="ab-td" style="font-weight:700;"><?php echo $rule->priority; ?></td>
                    <td class="ab-td" style="color:#5a7068;font-size:12px;"><?php echo esc_html($rule->reason ?: '—'); ?></td>
                    <td class="ab-td">
                      <form method="post" style="display:inline;">
                        <?php wp_nonce_field('amir_avail_action'); ?>
                        <input type="hidden" name="amir_action" value="delete_rule" />
                        <input type="hidden" name="rule_id" value="<?php echo $rule->id; ?>" />
                        <input type="hidden" name="tour_id" value="<?php echo $selected_tour; ?>" />
                        <button type="submit" style="background:transparent;border:none;color:#e24b4a;cursor:pointer;font-size:18px;padding:0 4px;"
                                onclick="return confirm('¿Eliminar esta regla?')">✕</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            <?php endif; ?>

            <!-- Leyenda de cómo funciona -->
            <div style="background:#fff8e7;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-top:16px;">
              <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:6px;">💡 Cómo funciona el motor de disponibilidad</div>
              <p style="font-size:12px;color:#78350f;line-height:1.6;">
                Las reglas se evalúan por <strong>prioridad descendente</strong> (mayor número = mayor prioridad).
                La primera regla que aplica a una fecha gana.<br>
                <strong>Ejemplo:</strong> Regla base "bloquear miércoles" (prioridad 10) + excepción "permitir miércoles en junio" (prioridad 20) → en junio los miércoles ESTÁN disponibles.
              </p>
            </div>
          </div>

          <!-- Formulario nueva regla -->
          <div>
            <div style="font-size:16px;font-weight:700;color:#1a2e24;margin-bottom:14px;">Nueva regla</div>
            <form method="post" style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:18px;">
              <?php wp_nonce_field('amir_avail_action'); ?>
              <input type="hidden" name="amir_action" value="add_rule" />
              <input type="hidden" name="tour_id" value="<?php echo $selected_tour; ?>" />

              <div class="ab-form-field">
                <label>Tipo de regla</label>
                <select name="rule_type" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="block">🚫 Bloquear (no disponible)</option>
                  <option value="allow">✅ Permitir (excepción)</option>
                </select>
              </div>

              <div class="ab-form-field">
                <label>Días de la semana</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <?php foreach (['Do','Lu','Ma','Mi','Ju','Vi','Sá'] as $i=>$d) : ?>
                    <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;
                                  background:#f8fdfb;border:1px solid #c3d9d0;padding:5px 8px;border-radius:6px;">
                      <input type="checkbox" name="weekdays[]" value="<?php echo $i; ?>" style="accent-color:#1D9E75;" />
                      <?php echo $d; ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <p style="font-size:11px;color:#5a7068;margin-top:4px;">Sin selección = aplica a todos los días del rango</p>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="ab-form-field">
                  <label>Fecha desde (opcional)</label>
                  <input type="date" name="date_from" style="<?php echo $this->input_style(); ?> width:100%;" />
                </div>
                <div class="ab-form-field">
                  <label>Fecha hasta (opcional)</label>
                  <input type="date" name="date_until" style="<?php echo $this->input_style(); ?> width:100%;" />
                </div>
              </div>

              <div class="ab-form-field">
                <label>Prioridad <span style="font-weight:400;color:#5a7068;">(mayor = sobreescribe)</span></label>
                <input type="number" name="priority" value="20" min="1" max="100"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>

              <div class="ab-form-field">
                <label>Motivo (opcional)</label>
                <input type="text" name="reason" placeholder="Ej: Temporada alta, mantenimiento, feriado…"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>

              <button type="submit" class="button button-primary" style="width:100%;padding:9px;">Agregar regla</button>
            </form>

            <!-- Accesos rápidos -->
            <div style="margin-top:16px;">
              <div style="font-size:13px;font-weight:700;color:#1a2e24;margin-bottom:8px;">Plantillas rápidas</div>
              <?php
              $templates = [
                  [ 'Bloquear todos los lunes',   'block', '[1]',  '', '' ],
                  [ 'Bloquear todos los miércoles','block', '[3]',  '', '' ],
                  [ 'Bloquear fin de semana',      'block', '[0,6]','', '' ],
                  [ 'Solo operar L-V',             'block', '[0,6]','', '' ],
              ];
              foreach ($templates as [$label,$type,$days,$from,$until]) : ?>
                <form method="post" style="display:inline-block;margin:0 6px 6px 0;">
                  <?php wp_nonce_field('amir_avail_action'); ?>
                  <input type="hidden" name="amir_action"  value="add_rule" />
                  <input type="hidden" name="tour_id"      value="<?php echo $selected_tour; ?>" />
                  <input type="hidden" name="rule_type"    value="<?php echo $type; ?>" />
                  <input type="hidden" name="weekdays_raw" value="<?php echo esc_attr($days); ?>" />
                  <input type="hidden" name="date_from"    value="<?php echo $from; ?>" />
                  <input type="hidden" name="date_until"   value="<?php echo $until; ?>" />
                  <input type="hidden" name="priority"     value="10" />
                  <input type="hidden" name="reason"       value="<?php echo esc_attr($label); ?>" />
                  <button type="submit" class="button" style="font-size:12px;padding:4px 10px;"><?php echo esc_html($label); ?></button>
                </form>
              <?php endforeach; ?>
            </div>
          </div>

        </div><!-- grid -->
        <?php endif; ?>

        </div>
        <?php
    }

    // ── Procesar acciones ─────────────────────────────────────────────────

    private function handle_actions(): void {
        if ( empty($_POST['amir_action']) || ! wp_verify_nonce($_POST['_wpnonce']??'','amir_avail_action') ) {
            return;
        }

        global $wpdb;
        $action   = sanitize_key($_POST['amir_action']);
        $tour_id  = (int)($_POST['tour_id'] ?? 0);

        if ( $action === 'add_rule' && $tour_id ) {
            // Días de la semana: puede venir de checkboxes o de weekdays_raw (templates)
            if ( isset($_POST['weekdays_raw']) ) {
                $weekdays = json_decode(sanitize_text_field($_POST['weekdays_raw']), true) ?: [];
            } else {
                $weekdays = array_map('intval', $_POST['weekdays'] ?? []);
            }

            $wpdb->insert("{$wpdb->prefix}amir_availability_rules", [
                'tour_id'    => $tour_id,
                'rule_type'  => in_array($_POST['rule_type']??'',['block','allow'],true) ? $_POST['rule_type'] : 'block',
                'weekdays'   => json_encode($weekdays),
                'date_from'  => sanitize_text_field($_POST['date_from'] ?? '') ?: null,
                'date_until' => sanitize_text_field($_POST['date_until'] ?? '') ?: null,
                'priority'   => max(1, min(100, (int)($_POST['priority'] ?? 10))),
                'reason'     => sanitize_text_field($_POST['reason'] ?? ''),
            ]);

            // Invalidar caché de disponibilidad
            global $wpdb;
            $this->clear_availability_cache($tour_id);
            set_transient('amir_avail_message', 'Regla agregada correctamente.', 30);
        }

        if ( $action === 'delete_rule' ) {
            $rule_id = (int)($_POST['rule_id'] ?? 0);
            if ($rule_id) {
                $wpdb->delete("{$wpdb->prefix}amir_availability_rules", ['id'=>$rule_id,'tour_id'=>$tour_id]);
                $this->clear_availability_cache($tour_id);
                set_transient('amir_avail_message', 'Regla eliminada.', 30);
            }
        }

        // Redirigir para evitar reenvío del form
        wp_redirect( admin_url('admin.php?page=amir-availability&tour_id='.$tour_id) );
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function get_tours(): array {
        global $wpdb;

        // Intentar desde la tabla propia
        $rows = $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}amir_tours WHERE status='active' ORDER BY sort_order, name_es"
        );

        if ( ! empty( $rows ) ) {
            return $rows;
        }

        // Tabla vacía — sincronizar forzosamente via INSERT directo
        $posts = get_posts( [
            'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );

        foreach ( $posts as $post ) {
            $existing_id = (int) get_post_meta( $post->ID, '_amir_tour_db_id', true );
            if ( $existing_id ) {
                continue; // Ya sincronizado
            }

            // INSERT directo garantizado
            $inserted = $wpdb->insert( "{$wpdb->prefix}amir_tours", [
                'slug'        => $post->post_name ?: sanitize_title( $post->post_title ),
                'status'      => 'active',
                'price_model' => get_post_meta( $post->ID, '_amir_price_model', true ) ?: 'percapita',
                'name_es'     => $post->post_title,
                'name_en'     => get_post_meta( $post->ID, '_amir_name_en', true ) ?: $post->post_title,
                'description_es' => wp_strip_all_tags( $post->post_content ),
                'duration_minutes' => (int) get_post_meta( $post->ID, '_amir_duration_minutes', true ),
                'min_age'     => (int) get_post_meta( $post->ID, '_amir_min_age', true ),
                'max_capacity'=> (int) get_post_meta( $post->ID, '_amir_max_capacity', true ) ?: 10,
                'min_passengers' => (int) get_post_meta( $post->ID, '_amir_min_passengers', true ) ?: 1,
                'languages'   => '["Español"]',
                'gallery_images' => '[]',
                'sort_order'  => (int) get_post_meta( $post->ID, '_amir_sort_order', true ),
            ] );

            if ( $inserted ) {
                $new_id = (int) $wpdb->insert_id;
                update_post_meta( $post->ID, '_amir_tour_db_id', $new_id );
            }
        }

        // Leer la tabla ahora que tiene datos
        return $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}amir_tours WHERE status='active' ORDER BY sort_order, name_es"
        ) ?: [];
    }

    private function get_rules( int $tour_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_availability_rules WHERE tour_id=%d ORDER BY priority DESC, id ASC",
            $tour_id
        ) ) ?? [];
    }

    private function format_weekdays( string $json ): string {
        $days   = json_decode($json, true) ?: [];
        $labels = ['Do','Lu','Ma','Mi','Ju','Vi','Sá'];
        if ( empty($days) ) return '<span style="color:#5a7068;">Todos</span>';
        return implode(', ', array_map(fn($d)=>$labels[$d]??$d, $days));
    }

    private function clear_availability_cache( int $tour_id ): void {
        for ($m=1;$m<=12;$m++) {
            for ($y=date('Y');$y<=date('Y')+1;$y++) {
                delete_transient("amir_avail_{$tour_id}_{$y}_{$m}");
            }
        }
    }

    private function input_style(): string {
        return 'border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;';
    }

    private function styles(): void {
        echo '<style>
        .ab-admin-wrap { max-width:1200px; }
        .ab-th { font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;padding:10px 12px;text-align:left; }
        .ab-td { font-size:13px;padding:10px 12px;vertical-align:middle; }
        .ab-form-field { margin-bottom:12px; }
        .ab-form-field label { display:block;font-size:12px;font-weight:700;color:#1a2e24;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px; }
        </style>';
    }
}
