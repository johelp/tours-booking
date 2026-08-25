<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de gestión de disponibilidad.
 * Permite agregar, editar y eliminar reglas de bloqueo/excepción por tour.
 */
class AvailabilityPage {

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
        }

        $message = $this->handle_actions();

        $tours        = $this->get_tours();
        $selected_tour = (int)( $_GET['tour_id'] ?? ( $tours[0]->id ?? 0 ) );
        $rules        = $selected_tour ? $this->get_rules( $selected_tour ) : [];

        // Vista previa de calendario (auditoría de UX, CONTRIBUTING.md
        // § 16.91: "quedó así desde la primera versión" — antes esta
        // pantalla era solo una tabla de reglas en abstracto, sin ninguna
        // forma de ver el resultado real sin ir a probar el widget del
        // cliente. Reusa AvailabilityEngine::evaluate_rules() tal cual
        // (recién hecha pública) — así lo que ve el admin acá es
        // GARANTIZADO lo mismo que decide el motor real, no una
        // reimplementación aparte que podría divergir.
        $preview_month = max( 1, min( 12, (int) ( $_GET['avail_month'] ?? date('n') ) ) );
        $preview_year  = max( (int) date('Y'), (int) ( $_GET['avail_year'] ?? date('Y') ) );
        $preview_days  = $selected_tour ? $this->build_preview( $selected_tour, $preview_year, $preview_month ) : [];
        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->styles(); ?>

        <h1>📅 <?php echo esc_html( $this->tt( 'Disponibilidad', 'Availability' ) ); ?></h1>

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
              <?php echo esc_html( $this->lang() === 'en' ? ( $t->name_en ?: $t->name_es ) : $t->name_es ); ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if (!$selected_tour) : ?>
          <p style="color:#5a7068;"><?php echo esc_html( $this->tt( 'Selecciona un tour para gestionar su disponibilidad.', 'Select a tour to manage its availability.' ) ); ?></p>
        <?php else : ?>

        <?php $this->render_preview_calendar( $selected_tour, $preview_year, $preview_month, $preview_days ); ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;">

          <!-- Reglas existentes -->
          <div>
            <div style="font-size:16px;font-weight:700;color:#1a2e24;margin-bottom:14px;">
              <?php echo esc_html( $this->tt( 'Reglas de disponibilidad', 'Availability rules' ) ); ?>
              <span style="font-size:12px;font-weight:400;color:#5a7068;margin-left:8px;"><?php echo esc_html( $this->tt( 'Mayor prioridad sobreescribe a menor', 'Higher priority overrides lower' ) ); ?></span>
            </div>

            <?php if (empty($rules)) : ?>
              <div style="background:#f8fdfb;border:1px dashed #c3d9d0;border-radius:10px;padding:20px;text-align:center;font-size:13px;color:#5a7068;">
                <?php echo esc_html( $this->tt( 'Sin reglas configuradas. Los días de operación se establecen en la edición del tour.', 'No rules configured. Operating days are set in the tour editor.' ) ); ?>
              </div>
            <?php else : ?>
              <?php if ( $this->has_ineffective_allow_rule( $rules ) ) : ?>
                <div style="background:#fff8e7;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:12px;color:#78350f;">
                  ⚠ <?php echo wp_kses_post( $this->tt(
                    'Tenés una regla <strong>Permitir</strong> pero ninguna regla <strong>Bloquear</strong> de base que cubra siempre — "Permitir" sola no bloquea nada, solo abre una excepción dentro de un rango ya bloqueado. Si el tour ya estaba disponible sin ninguna regla, esta "Permitir" no está haciendo nada. Agregá la plantilla "Bloquear todo el año" de abajo (prioridad menor) para que tu regla actual pase a ser la excepción real.',
                    'You have an <strong>Allow</strong> rule but no base <strong>Block</strong> rule that always applies — "Allow" alone never blocks anything, it only opens an exception inside a range some other rule already blocked. If the tour was already available without any rules, this "Allow" isn\'t doing anything. Add the "Block the whole year" template below (lower priority) so your current rule becomes a real exception.'
                  ) ); ?>
                </div>
              <?php endif; ?>
              <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
              <table style="width:100%;border-collapse:collapse;">
                <thead>
                  <tr style="background:#f8fdfb;">
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Tipo', 'Type' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Días', 'Days' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Desde', 'From' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Hasta', 'Until' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Prioridad', 'Priority' ) ); ?></th>
                    <th class="ab-th"><?php echo esc_html( $this->tt( 'Motivo', 'Reason' ) ); ?></th>
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
                        <?php echo $rule->rule_type==='block' ? '🚫 ' . esc_html( $this->tt( 'Bloquear', 'Block' ) ) : '✅ ' . esc_html( $this->tt( 'Permitir', 'Allow' ) ); ?>
                      </span>
                    </td>
                    <td class="ab-td"><?php echo $this->format_weekdays($rule->weekdays); ?></td>
                    <td class="ab-td"><?php echo $rule->date_from ?: '—'; ?></td>
                    <td class="ab-td"><?php echo $rule->date_until ?: '—'; ?></td>
                    <td class="ab-td" style="font-weight:700;"><?php echo $rule->priority; ?></td>
                    <td class="ab-td" style="color:#5a7068;font-size:12px;"><?php echo esc_html($rule->reason ?: '—'); ?></td>
                    <td class="ab-td" style="white-space:nowrap;">
                      <?php if ( $rule->date_from || $rule->date_until ) : ?>
                        <form method="post" style="display:inline;">
                          <?php wp_nonce_field('amir_avail_action'); ?>
                          <input type="hidden" name="amir_action" value="duplicate_rule_next_year" />
                          <input type="hidden" name="rule_id" value="<?php echo $rule->id; ?>" />
                          <input type="hidden" name="tour_id" value="<?php echo $selected_tour; ?>" />
                          <button type="submit" title="<?php echo esc_attr( $this->tt( 'Duplicar esta temporada al año siguiente, con las mismas fechas +1 año', 'Duplicate this season to next year, same dates +1 year' ) ); ?>"
                                  style="background:transparent;border:none;color:#1D9E75;cursor:pointer;font-size:15px;padding:0 4px;">📋+1</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" style="display:inline;">
                        <?php wp_nonce_field('amir_avail_action'); ?>
                        <input type="hidden" name="amir_action" value="delete_rule" />
                        <input type="hidden" name="rule_id" value="<?php echo $rule->id; ?>" />
                        <input type="hidden" name="tour_id" value="<?php echo $selected_tour; ?>" />
                        <button type="submit" style="background:transparent;border:none;color:#e24b4a;cursor:pointer;font-size:18px;padding:0 4px;"
                                onclick="return confirm('<?php echo esc_js( $this->tt( '¿Eliminar esta regla?', 'Delete this rule?' ) ); ?>')">✕</button>
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
              <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:6px;">💡 <?php echo esc_html( $this->tt( 'Cómo funciona el motor de disponibilidad', 'How the availability engine works' ) ); ?></div>
              <p style="font-size:12px;color:#78350f;line-height:1.6;">
                <?php echo wp_kses_post( $this->tt(
                  'Las reglas se evalúan por <strong>prioridad descendente</strong> (mayor número = mayor prioridad). La primera regla que aplica a una fecha gana.<br><strong>Ejemplo:</strong> Regla base "bloquear miércoles" (prioridad 10) + excepción "permitir miércoles en junio" (prioridad 20) → en junio los miércoles ESTÁN disponibles.<br><strong>Importante:</strong> una regla "Permitir" SOLA no bloquea nada — solo abre una excepción. Para un tour de temporada ("solo opera abril-octubre"), necesitás las DOS reglas: "Bloquear todo el año" (prioridad baja, plantilla de abajo) + tu "Permitir" con el rango de temporada (prioridad más alta).',
                  'Rules are evaluated by <strong>descending priority</strong> (higher number = higher priority). The first rule that applies to a date wins.<br><strong>Example:</strong> base rule "block Wednesdays" (priority 10) + exception "allow Wednesdays in June" (priority 20) → in June, Wednesdays ARE available.<br><strong>Important:</strong> an "Allow" rule ALONE blocks nothing — it only opens an exception. For a seasonal tour ("only operates April-October"), you need BOTH rules: "Block the whole year" (low priority, template below) + your "Allow" with the season range (higher priority).'
                ) ); ?>
              </p>
            </div>
          </div>

          <!-- Formulario nueva regla -->
          <div>
            <div style="font-size:16px;font-weight:700;color:#1a2e24;margin-bottom:14px;"><?php echo esc_html( $this->tt( 'Nueva regla', 'New rule' ) ); ?></div>
            <form method="post" style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:18px;">
              <?php wp_nonce_field('amir_avail_action'); ?>
              <input type="hidden" name="amir_action" value="add_rule" />
              <input type="hidden" name="tour_id" value="<?php echo $selected_tour; ?>" />

              <div class="ab-form-field">
                <label><?php echo esc_html( $this->tt( 'Tipo de regla', 'Rule type' ) ); ?></label>
                <select name="rule_type" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="block">🚫 <?php echo esc_html( $this->tt( 'Bloquear (no disponible)', 'Block (unavailable)' ) ); ?></option>
                  <option value="allow">✅ <?php echo esc_html( $this->tt( 'Permitir (excepción)', 'Allow (exception)' ) ); ?></option>
                </select>
              </div>

              <div class="ab-form-field">
                <label><?php echo esc_html( $this->tt( 'Días de la semana', 'Days of the week' ) ); ?></label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <?php foreach ( $this->weekday_labels() as $i=>$d) : ?>
                    <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;
                                  background:#f8fdfb;border:1px solid #c3d9d0;padding:5px 8px;border-radius:6px;">
                      <input type="checkbox" name="weekdays[]" value="<?php echo $i; ?>" style="accent-color:#1D9E75;" />
                      <?php echo $d; ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <p style="font-size:11px;color:#5a7068;margin-top:4px;"><?php echo esc_html( $this->tt( 'Sin selección = aplica a todos los días del rango', 'No selection = applies to every day in the range' ) ); ?></p>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="ab-form-field">
                  <label><?php echo esc_html( $this->tt( 'Fecha desde (opcional)', 'Date from (optional)' ) ); ?></label>
                  <input type="date" name="date_from" style="<?php echo $this->input_style(); ?> width:100%;" />
                </div>
                <div class="ab-form-field">
                  <label><?php echo esc_html( $this->tt( 'Fecha hasta (opcional)', 'Date until (optional)' ) ); ?></label>
                  <input type="date" name="date_until" style="<?php echo $this->input_style(); ?> width:100%;" />
                </div>
              </div>

              <div class="ab-form-field">
                <label><?php echo esc_html( $this->tt( 'Prioridad', 'Priority' ) ); ?> <span style="font-weight:400;color:#5a7068;">(<?php echo esc_html( $this->tt( 'mayor = sobreescribe', 'higher = overrides' ) ); ?>)</span></label>
                <input type="number" name="priority" value="20" min="1" max="100"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>

              <div class="ab-form-field">
                <label><?php echo esc_html( $this->tt( 'Motivo (opcional)', 'Reason (optional)' ) ); ?></label>
                <input type="text" name="reason" placeholder="<?php echo esc_attr( $this->tt( 'Ej: Temporada alta, mantenimiento, feriado…', 'E.g.: High season, maintenance, holiday…' ) ); ?>"
                       style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>

              <button type="submit" class="button button-primary" style="width:100%;padding:9px;"><?php echo esc_html( $this->tt( 'Agregar regla', 'Add rule' ) ); ?></button>
            </form>

            <!-- Accesos rápidos -->
            <div style="margin-top:16px;">
              <div style="font-size:13px;font-weight:700;color:#1a2e24;margin-bottom:8px;"><?php echo esc_html( $this->tt( 'Plantillas rápidas', 'Quick templates' ) ); ?></div>
              <?php
              // "Solo operar L-V" (antes acá, con los mismos días que
              // "Bloquear fin de semana") se sacó — en un motor de reglas
              // block/allow, bloquear sáb+dom Y "operar solo L-V" son
              // exactamente la misma regla; tener dos botones que insertan
              // lo idéntico solo invitaba a hacer doble click pensando que
              // eran cosas distintas (auditoría de UX, CONTRIBUTING.md § 16.91).
              $templates = [
                  [ $this->tt( 'Bloquear todos los lunes', 'Block every Monday' ),      'block', '[1]',  '', '' ],
                  [ $this->tt( 'Bloquear todos los miércoles', 'Block every Wednesday' ),'block', '[3]',  '', '' ],
                  [ $this->tt( 'Bloquear fin de semana', 'Block the weekend' ),          'block', '[0,6]','', '' ],
                  // Regla base para tours de temporada — pensada para
                  // combinarse con una regla "Permitir" propia de mayor
                  // prioridad (ver aviso/ayuda de arriba). weekdays=[]
                  // (sin restricción de día) + sin rango de fechas =
                  // bloquea absolutamente todo, siempre.
                  [ $this->tt( 'Bloquear todo el año (regla base)', 'Block the whole year (base rule)' ), 'block', '[]', '', '' ],
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

    /**
     * Bug real reportado en producción (caliafarm.com, 2026-08-21): "guardar
     * o eliminar una regla deja la página en blanco". Causa real: acá se
     * llamaba wp_redirect()+exit para evitar reenvío del form al refrescar —
     * pero esta función se ejecuta DESDE el callback de render de la página
     * de admin (registrado en AdminMenu), que WordPress invoca recién
     * DESPUÉS de haber mandado ya las cabeceras HTTP y el HTML del admin
     * (menú, header) vía admin-header.php. header() en ese punto falla en
     * silencio (sin display_errors, no se ve ningún warning) y el exit
     * corta la respuesta a mitad de camino — el navegador recibe una
     * página vacía o cortada, según cuánto haya buffereado el servidor.
     * Corregido sacando el redirect: se procesa la acción y se sigue
     * renderizando la misma página normal, mismo patrón que ya usan sin
     * problemas class-bookings-page.php/class-field-page.php en sus
     * acciones sin redirect. Pierde la protección de "reenvío al
     * refrescar" (F5 reenviaría el POST) — trade-off aceptado: es un caso
     * raro comparado con la página quedando en blanco siempre.
     */
    private function handle_actions(): string {
        if ( empty($_POST['amir_action']) || ! wp_verify_nonce($_POST['_wpnonce']??'','amir_avail_action') ) {
            return '';
        }

        global $wpdb;
        $action   = sanitize_key($_POST['amir_action']);
        $tour_id  = (int)($_POST['tour_id'] ?? 0);
        $message  = '';

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
                'reason'     => sanitize_text_field(wp_unslash($_POST['reason'] ?? '')),
            ]);

            // Invalidar caché de disponibilidad
            $this->clear_availability_cache($tour_id);
            $message = $this->tt( 'Regla agregada correctamente.', 'Rule added successfully.' );
        }

        if ( $action === 'delete_rule' ) {
            $rule_id = (int)($_POST['rule_id'] ?? 0);
            if ($rule_id) {
                $wpdb->delete("{$wpdb->prefix}amir_availability_rules", ['id'=>$rule_id,'tour_id'=>$tour_id]);
                $this->clear_availability_cache($tour_id);
                $message = $this->tt( 'Regla eliminada.', 'Rule deleted.' );
            }
        }

        // "Abrir temporada del año que viene" — pedido de usabilidad del
        // cliente 2026-08-24: reglas de temporada (con date_from/date_until)
        // se cargan a mano cada año; esto copia la regla completa con las
        // mismas fechas +1 año, sin tocar la original. Reglas sin fecha
        // (solo días de semana, "siempre") no tienen "temporada" que
        // adelantar — el botón ni se muestra para esas, ver la tabla abajo.
        if ( $action === 'duplicate_rule_next_year' ) {
            $rule_id = (int)($_POST['rule_id'] ?? 0);
            $rule = $rule_id ? $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amir_availability_rules WHERE id=%d AND tour_id=%d",
                $rule_id, $tour_id
            ) ) : null;

            if ( $rule && ( $rule->date_from || $rule->date_until ) ) {
                $wpdb->insert("{$wpdb->prefix}amir_availability_rules", [
                    'tour_id'    => $tour_id,
                    'rule_type'  => $rule->rule_type,
                    'weekdays'   => $rule->weekdays,
                    'date_from'  => $rule->date_from  ? date('Y-m-d', strtotime($rule->date_from  . ' +1 year')) : null,
                    'date_until' => $rule->date_until ? date('Y-m-d', strtotime($rule->date_until . ' +1 year')) : null,
                    'priority'   => $rule->priority,
                    'reason'     => $rule->reason,
                ]);
                $this->clear_availability_cache($tour_id);
                $message = $this->tt( 'Regla duplicada al año siguiente.', 'Rule duplicated to next year.' );
            }
        }

        return $message;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function get_tours(): array {
        global $wpdb;

        // Intentar desde la tabla propia
        $rows = $wpdb->get_results(
            "SELECT id, name_es, name_en FROM {$wpdb->prefix}amir_tours WHERE status='active' ORDER BY sort_order, name_es"
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
            "SELECT id, name_es, name_en FROM {$wpdb->prefix}amir_tours WHERE status='active' ORDER BY sort_order, name_es"
        ) ?: [];
    }

    /**
     * Corre AvailabilityEngine::evaluate_rules() día por día para un mes —
     * devuelve [ 'YYYY-MM-DD' => 'available'|'blocked'|'past' ]. No mira
     * cupo/horarios (eso es otra pregunta, "¿hay lugar?") — esta pantalla es
     * específicamente sobre qué fechas las REGLAS de acá permiten u
     * ocultan, así que evaluate_rules() alcanza sin necesitar el motor completo.
     */
    private function build_preview( int $tour_id, int $year, int $month ): array {
        $engine = new \AmirBooking\Core\AvailabilityEngine();
        $today  = current_time('Y-m-d');
        $days_in_month = (int) date('t', mktime(0,0,0,$month,1,$year));
        $result = [];
        for ( $d = 1; $d <= $days_in_month; $d++ ) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            if ( $date < $today ) {
                $result[$date] = 'past';
                continue;
            }
            $result[$date] = $engine->evaluate_rules( $tour_id, $date ) ? 'available' : 'blocked';
        }
        return $result;
    }

    private function render_preview_calendar( int $tour_id, int $year, int $month, array $days ): void {
        $month_names_es = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $month_names_en = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
        $month_label = $this->lang() === 'en' ? $month_names_en[$month] : $month_names_es[$month];

        $prev_m = $month === 1 ? 12 : $month - 1;
        $prev_y = $month === 1 ? $year - 1 : $year;
        $next_m = $month === 12 ? 1 : $month + 1;
        $next_y = $month === 12 ? $year + 1 : $year;
        // No dejar navegar a un mes ya pasado por completo — no aporta nada
        // y complica el cálculo de "hoy" en build_preview().
        $prev_disabled = ( $prev_y < (int) date('Y') ) || ( $prev_y === (int) date('Y') && $prev_m < (int) date('n') );

        $base_url = admin_url( 'admin.php?page=amir-availability&tour_id=' . $tour_id );
        $first_dow = (int) date( 'w', mktime(0,0,0,$month,1,$year) );
        $weekday_labels = $this->weekday_labels();

        $blocked_count = count( array_filter( $days, fn($s) => $s === 'blocked' ) );
        ?>
        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:18px 20px;margin-bottom:24px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;flex-wrap:wrap;gap:8px;">
            <div style="font-size:15px;font-weight:700;color:#1a2e24;">
              👁 <?php echo esc_html( $this->tt( 'Vista previa: así se ve la disponibilidad de este tour', "Preview: this tour's availability, as customers see it" ) ); ?>
            </div>
            <div style="display:flex;align-items:center;gap:10px;font-size:13px;">
              <?php if ( $prev_disabled ) : ?>
                <span style="color:#c3d9d0;">‹</span>
              <?php else : ?>
                <a href="<?php echo esc_url( $base_url . "&avail_year={$prev_y}&avail_month={$prev_m}" ); ?>" style="text-decoration:none;color:#1D9E75;font-weight:700;">‹</a>
              <?php endif; ?>
              <strong style="min-width:120px;text-align:center;display:inline-block;"><?php echo esc_html( "$month_label $year" ); ?></strong>
              <a href="<?php echo esc_url( $base_url . "&avail_year={$next_y}&avail_month={$next_m}" ); ?>" style="text-decoration:none;color:#1D9E75;font-weight:700;">›</a>
            </div>
          </div>
          <p style="font-size:12px;color:#5a7068;margin:0 0 12px;">
            <?php echo esc_html( sprintf(
                $this->tt( 'Resultado real de las reglas de abajo — %d día(s) bloqueado(s) este mes. No incluye cupo por horario, solo qué fechas están habilitadas.', "Real result of the rules below — %d day(s) blocked this month. Doesn't include per-schedule capacity, just which dates are enabled." ),
                $blocked_count
            ) ); ?>
          </p>
          <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;max-width:420px;">
            <?php foreach ( $weekday_labels as $wl ) : ?>
              <div style="text-align:center;font-size:10px;font-weight:700;color:#5a7068;text-transform:uppercase;padding:2px 0;"><?php echo esc_html( $wl ); ?></div>
            <?php endforeach; ?>
            <?php for ( $i = 0; $i < $first_dow; $i++ ) : ?>
              <div></div>
            <?php endfor; ?>
            <?php foreach ( $days as $date => $status ) :
                $day_num = (int) substr( $date, -2 );
                $bg = [ 'available' => '#e8f5e9', 'blocked' => '#fef2f2', 'past' => '#f8fdfb' ][ $status ];
                $fg = [ 'available' => '#0F6E56', 'blocked' => '#e24b4a', 'past' => '#c3d9d0' ][ $status ];
                $title = [
                    'available' => $this->tt( 'Disponible', 'Available' ),
                    'blocked'   => $this->tt( 'Bloqueada por una regla', 'Blocked by a rule' ),
                    'past'      => $this->tt( 'Fecha pasada', 'Past date' ),
                ][ $status ];
            ?>
              <div title="<?php echo esc_attr( $title ); ?>" style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:6px;font-size:12px;font-weight:600;background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;">
                <?php echo $day_num; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:16px;margin-top:10px;font-size:11px;color:#5a7068;">
            <span>🟩 <?php echo esc_html( $this->tt( 'Disponible', 'Available' ) ); ?></span>
            <span>🟥 <?php echo esc_html( $this->tt( 'Bloqueada', 'Blocked' ) ); ?></span>
          </div>
        </div>
        <?php
    }

    private function get_rules( int $tour_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_availability_rules WHERE tour_id=%d ORDER BY priority DESC, id ASC",
            $tour_id
        ) ) ?? [];
    }

    /** Do/Lu/Ma/Mi/Ju/Vi/Sá o Su/Mo/Tu/We/Th/Fr/Sa según el idioma de admin — ver lang(). */
    private function weekday_labels(): array {
        return $this->lang() === 'en'
            ? [ 'Su','Mo','Tu','We','Th','Fr','Sa' ]
            : [ 'Do','Lu','Ma','Mi','Ju','Vi','Sá' ];
    }

    /**
     * true si hay al menos una regla 'allow' Y ninguna regla 'block' que
     * aplique SIEMPRE (sin días de semana restringidos, sin rango de
     * fechas) — el caso real que confundió a un cliente probando la
     * pantalla (2026-08-24): cargó una sola regla "Permitir abril-octubre
     * 2027" esperando que el tour quedara bloqueado el resto del año, pero
     * sin una regla "Bloquear" de base, evaluate_rules() nunca tiene nada
     * que bloquear — ver AvailabilityEngine::evaluate_rules(), el
     * `return true` final ("sin reglas que apliquen, disponible por
     * defecto"). Heurística simple a propósito: no intenta resolver el
     * caso general de superposición de rangos, solo el patrón más común
     * (base + excepción) que la ayuda de esta pantalla ya explica.
     */
    private function has_ineffective_allow_rule( array $rules ): bool {
        $has_allow = false;
        $has_base_block = false;
        foreach ( $rules as $rule ) {
            if ( $rule->rule_type === 'allow' ) {
                $has_allow = true;
            }
            if ( $rule->rule_type === 'block' && empty( $rule->date_from ) && empty( $rule->date_until ) ) {
                $weekdays = json_decode( $rule->weekdays ?? '[]', true );
                if ( empty( $weekdays ) ) {
                    $has_base_block = true;
                }
            }
        }
        return $has_allow && ! $has_base_block;
    }

    private function format_weekdays( string $json ): string {
        $days   = json_decode($json, true) ?: [];
        $labels = $this->weekday_labels();
        if ( empty($days) ) return '<span style="color:#5a7068;">' . esc_html( $this->tt( 'Todos', 'All' ) ) . '</span>';
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
