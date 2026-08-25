<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD de cupones de descuento (% o monto fijo, programables por fecha).
 */
class CouponsPage {

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

        $message = get_transient( 'amir_coupon_message' );
        if ( $message ) {
            delete_transient( 'amir_coupon_message' );
        }

        global $wpdb;
        // Cupones también para habitaciones desde 2026-08-04 (§ 16.21
        // CONTRIBUTING.md) — room_name solo tiene sentido en Pro Max, pero
        // el LEFT JOIN es inofensivo en Lite/Pro (room_id siempre NULL ahí).
        $coupons = $wpdb->get_results(
            "SELECT c.*, t.name_es AS tour_name, r.name_es AS room_name
             FROM {$wpdb->prefix}amir_coupons c
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = c.tour_id
             LEFT JOIN {$wpdb->prefix}flow_rooms r ON r.id = c.room_id
             ORDER BY c.created_at DESC"
        ) ?? [];

        $tours = $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}amir_tours WHERE status = 'active' ORDER BY sort_order"
        ) ?? [];

        $is_pro_max = AMIR_EDITION === 'pro_max';
        $rooms = $is_pro_max ? ( $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}flow_rooms WHERE status = 'active' ORDER BY sort_order"
        ) ?? [] ) : [];
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1100px;">
        <?php $this->styles(); ?>
        <h1 style="display:flex;align-items:center;justify-content:space-between;">
          <span>🏷️ <?php echo esc_html( $this->tt( 'Cupones', 'Coupons' ) ); ?></span>
          <button onclick="document.getElementById('amir-new-coupon-form').style.display='block';this.style.display='none';"
                  class="button button-primary">+ <?php echo esc_html( $this->tt( 'Nuevo cupón', 'New coupon' ) ); ?></button>
        </h1>

        <?php if ( $message ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <div id="amir-new-coupon-form" style="display:none;background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px;margin-bottom:24px;">
          <h3 style="margin:0 0 16px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Nuevo cupón', 'New coupon' ) ); ?></h3>
          <form method="post">
            <?php wp_nonce_field( 'amir_coupon_action' ); ?>
            <input type="hidden" name="amir_action" value="create_coupon" />
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Código *', 'Code *' ) ); ?></label>
                <input type="text" name="code" required placeholder="VERANO2026" style="<?php echo $this->input_style(); ?> width:100%;text-transform:uppercase;" />
              </div>
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Tipo de descuento', 'Discount type' ) ); ?></label>
                <select name="discount_type" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="percent"><?php echo esc_html( $this->tt( 'Porcentaje (%)', 'Percentage (%)' ) ); ?></option>
                  <option value="fixed"><?php echo esc_html( sprintf( $this->tt( 'Monto fijo (%s)', 'Fixed amount (%s)' ), \AmirBooking\Core\Currency::code() ) ); ?></option>
                </select>
              </div>
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Valor *', 'Value *' ) ); ?></label>
                <input type="number" name="discount_value" required min="0" step="0.01" placeholder="15" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Válido desde', 'Valid from' ) ); ?></label>
                <input type="date" name="valid_from" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Válido hasta', 'Valid until' ) ); ?></label>
                <input type="date" name="valid_until" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Límite de usos (vacío = ilimitado)', 'Usage limit (empty = unlimited)' ) ); ?></label>
                <input type="number" name="usage_limit" min="1" placeholder="100" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
            </div>
            <div style="display:grid;grid-template-columns:<?php echo $is_pro_max ? '1fr 1fr' : '1fr'; ?>;gap:14px;margin-bottom:14px;max-width:<?php echo $is_pro_max ? '700px' : '340px'; ?>;">
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Aplica al tour', 'Applies to tour' ) ); ?></label>
                <select name="tour_id" class="amir-coupon-tour-select" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value=""><?php echo esc_html( $this->tt( 'Todos los tours', 'All tours' ) ); ?></option>
                  <?php foreach ( $tours as $t ) : ?>
                    <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->name_es ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php if ( $is_pro_max ) : ?>
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Aplica a la habitación', 'Applies to room' ) ); ?></label>
                <select name="room_id" class="amir-coupon-room-select" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value=""><?php echo esc_html( $this->tt( '— Ninguna —', '— None —' ) ); ?></option>
                  <?php foreach ( $rooms as $r ) : ?>
                    <option value="<?php echo (int) $r->id; ?>"><?php echo esc_html( $r->name_es ); ?></option>
                  <?php endforeach; ?>
                </select>
                <p style="font-size:11px;color:#888;margin:4px 0 0;"><?php echo wp_kses_post( $this->tt(
                  'Un cupón nunca aplica a tour Y habitación a la vez — elegir uno desactiva el otro. Ambos vacíos = aplica a cualquier tour <em>o</em> habitación.',
                  'A coupon never applies to a tour AND a room at once — choosing one clears the other. Both empty = applies to any tour <em>or</em> room.'
                ) ); ?></p>
              </div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:10px;">
              <button type="submit" class="button button-primary"><?php echo esc_html( $this->tt( 'Crear cupón', 'Create coupon' ) ); ?></button>
              <button type="button" onclick="document.getElementById('amir-new-coupon-form').style.display='none';" class="button"><?php echo esc_html( $this->tt( 'Cancelar', 'Cancel' ) ); ?></button>
            </div>
          </form>
        </div>

        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#f8fdfb;">
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Código', 'Code' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Descuento', 'Discount' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Vigencia', 'Validity' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Usos', 'Uses' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Aplica a', 'Applies to' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Estado', 'Status' ) ); ?></th>
            <th class="ab-th"></th>
          </tr></thead>
          <tbody>
          <?php if ( empty( $coupons ) ) : ?>
            <tr><td colspan="7" class="ab-td" style="text-align:center;color:#5a7068;padding:24px;"><?php echo esc_html( $this->tt( 'Sin cupones creados todavía.', 'No coupons created yet.' ) ); ?></td></tr>
          <?php else : foreach ( $coupons as $c ) : ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
              <td class="ab-td"><code><?php echo esc_html( $c->code ); ?></code></td>
              <td class="ab-td">
                <?php echo $c->discount_type === 'percent'
                    ? esc_html( rtrim( rtrim( number_format( (float) $c->discount_value, 2 ), '0' ), '.' ) . '%' )
                    : esc_html( \AmirBooking\Core\Currency::format( (float) $c->discount_value ) ); ?>
              </td>
              <td class="ab-td" style="font-size:12px;color:#5a7068;">
                <?php echo esc_html( ( $c->valid_from ?: '—' ) . ' → ' . ( $c->valid_until ?: '—' ) ); ?>
              </td>
              <td class="ab-td"><?php echo (int) $c->times_used; ?><?php echo $c->usage_limit !== null ? ' / ' . (int) $c->usage_limit : ''; ?></td>
              <td class="ab-td"><?php echo esc_html( $c->tour_name ? $c->tour_name : ( $c->room_name ? '🛏 ' . $c->room_name : $this->tt('Todos','All') ) ); ?></td>
              <td class="ab-td">
                <?php if ( (int) $c->active === 1 ) : ?>
                  <span style="color:#1D9E75;font-weight:700;font-size:12px;">✓ <?php echo esc_html( $this->tt( 'Activo', 'Active' ) ); ?></span>
                <?php else : ?>
                  <span style="color:#5a7068;font-size:12px;"><?php echo esc_html( $this->tt( 'Pausado', 'Paused' ) ); ?></span>
                <?php endif; ?>
              </td>
              <td class="ab-td">
                <button type="button" class="button button-small"
                        onclick="document.getElementById('amir-edit-coupon-<?php echo (int) $c->id; ?>').style.display='block';"><?php echo esc_html( $this->tt( 'Editar', 'Edit' ) ); ?></button>
                <form method="post" style="display:inline;">
                  <?php wp_nonce_field( 'amir_coupon_action' ); ?>
                  <input type="hidden" name="amir_action" value="toggle_coupon" />
                  <input type="hidden" name="id" value="<?php echo (int) $c->id; ?>" />
                  <button type="submit" class="button button-small"><?php echo (int) $c->active === 1 ? esc_html( $this->tt('Pausar','Pause') ) : esc_html( $this->tt('Activar','Activate') ); ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Eliminar este cupón?', 'Delete this coupon?' ) ); ?>');">
                  <?php wp_nonce_field( 'amir_coupon_action' ); ?>
                  <input type="hidden" name="amir_action" value="delete_coupon" />
                  <input type="hidden" name="id" value="<?php echo (int) $c->id; ?>" />
                  <button type="submit" class="button button-small" style="color:#e24b4a;"><?php echo esc_html( $this->tt( 'Eliminar', 'Delete' ) ); ?></button>
                </form>
              </td>
            </tr>
            <tr id="amir-edit-coupon-<?php echo (int) $c->id; ?>" style="display:none;">
              <td colspan="7" class="ab-td" style="background:#f8fdfb;">
                <form method="post">
                  <?php wp_nonce_field( 'amir_coupon_action' ); ?>
                  <input type="hidden" name="amir_action" value="update_coupon" />
                  <input type="hidden" name="id" value="<?php echo (int) $c->id; ?>" />
                  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Código *', 'Code *' ) ); ?></label>
                      <input type="text" name="code" required value="<?php echo esc_attr( $c->code ); ?>" style="<?php echo $this->input_style(); ?> width:100%;text-transform:uppercase;" />
                    </div>
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Tipo de descuento', 'Discount type' ) ); ?></label>
                      <select name="discount_type" style="<?php echo $this->input_style(); ?> width:100%;">
                        <option value="percent" <?php selected( $c->discount_type, 'percent' ); ?>><?php echo esc_html( $this->tt( 'Porcentaje (%)', 'Percentage (%)' ) ); ?></option>
                        <option value="fixed" <?php selected( $c->discount_type, 'fixed' ); ?>><?php echo esc_html( sprintf( $this->tt( 'Monto fijo (%s)', 'Fixed amount (%s)' ), \AmirBooking\Core\Currency::code() ) ); ?></option>
                      </select>
                    </div>
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Valor *', 'Value *' ) ); ?></label>
                      <input type="number" name="discount_value" required min="0" step="0.01" value="<?php echo esc_attr( $c->discount_value ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Válido desde', 'Valid from' ) ); ?></label>
                      <input type="date" name="valid_from" value="<?php echo esc_attr( $c->valid_from ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Válido hasta', 'Valid until' ) ); ?></label>
                      <input type="date" name="valid_until" value="<?php echo esc_attr( $c->valid_until ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Límite de usos (vacío = ilimitado)', 'Usage limit (empty = unlimited)' ) ); ?></label>
                      <input type="number" name="usage_limit" min="<?php echo (int) $c->times_used; ?>" value="<?php echo esc_attr( $c->usage_limit ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:<?php echo $is_pro_max ? '1fr 1fr' : '1fr'; ?>;gap:14px;margin-bottom:14px;max-width:<?php echo $is_pro_max ? '700px' : '340px'; ?>;">
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Aplica al tour', 'Applies to tour' ) ); ?></label>
                      <select name="tour_id" class="amir-coupon-tour-select" style="<?php echo $this->input_style(); ?> width:100%;">
                        <option value=""><?php echo esc_html( $this->tt( 'Todos los tours', 'All tours' ) ); ?></option>
                        <?php foreach ( $tours as $t ) : ?>
                          <option value="<?php echo (int) $t->id; ?>" <?php selected( (int) $c->tour_id, (int) $t->id ); ?>><?php echo esc_html( $t->name_es ); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <?php if ( $is_pro_max ) : ?>
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Aplica a la habitación', 'Applies to room' ) ); ?></label>
                      <select name="room_id" class="amir-coupon-room-select" style="<?php echo $this->input_style(); ?> width:100%;">
                        <option value=""><?php echo esc_html( $this->tt( '— Ninguna —', '— None —' ) ); ?></option>
                        <?php foreach ( $rooms as $r ) : ?>
                          <option value="<?php echo (int) $r->id; ?>" <?php selected( (int) ( $c->room_id ?? 0 ), (int) $r->id ); ?>><?php echo esc_html( $r->name_es ); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div style="display:flex;gap:10px;">
                    <button type="submit" class="button button-primary"><?php echo esc_html( $this->tt( 'Guardar cambios', 'Save changes' ) ); ?></button>
                    <button type="button" onclick="document.getElementById('amir-edit-coupon-<?php echo (int) $c->id; ?>').style.display='none';" class="button"><?php echo esc_html( $this->tt( 'Cancelar', 'Cancel' ) ); ?></button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
        </div>
        <?php if ( $is_pro_max ) : ?>
        <script>
        // Un cupón nunca aplica a tour Y habitación a la vez — elegir uno
        // limpia el otro, en cada form (nuevo + una fila de edición por cupón).
        document.querySelectorAll( '.amir-coupon-tour-select' ).forEach( function ( sel ) {
          sel.addEventListener( 'change', function () {
            if ( this.value !== '' ) {
              var room = this.closest( 'form' ).querySelector( '.amir-coupon-room-select' );
              if ( room ) room.value = '';
            }
          } );
        } );
        document.querySelectorAll( '.amir-coupon-room-select' ).forEach( function ( sel ) {
          sel.addEventListener( 'change', function () {
            if ( this.value !== '' ) {
              var tour = this.closest( 'form' ).querySelector( '.amir-coupon-tour-select' );
              if ( tour ) tour.value = '';
            }
          } );
        } );
        </script>
        <?php endif; ?>
        <?php
    }

    private function handle_actions(): void {
        if ( empty( $_POST['amir_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_coupon_action' ) ) {
            return;
        }

        global $wpdb;
        $action = sanitize_key( $_POST['amir_action'] );

        if ( $action === 'create_coupon' ) {
            $code = strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
            if ( $code === '' ) {
                set_transient( 'amir_coupon_message', $this->tt( 'El código no puede estar vacío.', 'The code cannot be empty.' ), 30 );
                return;
            }

            $inserted = $wpdb->insert(
                "{$wpdb->prefix}amir_coupons",
                [
                    'code'           => $code,
                    'discount_type'  => in_array( $_POST['discount_type'] ?? '', [ 'percent', 'fixed' ], true ) ? $_POST['discount_type'] : 'percent',
                    'discount_value' => (float) ( $_POST['discount_value'] ?? 0 ),
                    'valid_from'     => ! empty( $_POST['valid_from'] ) ? sanitize_text_field( $_POST['valid_from'] ) : null,
                    'valid_until'    => ! empty( $_POST['valid_until'] ) ? sanitize_text_field( $_POST['valid_until'] ) : null,
                    'usage_limit'    => ! empty( $_POST['usage_limit'] ) ? absint( $_POST['usage_limit'] ) : null,
                    'tour_id'        => ! empty( $_POST['tour_id'] ) ? absint( $_POST['tour_id'] ) : null,
                    // room_id (§ 16.21 CONTRIBUTING.md) — solo visible en Pro
                    // Max, pero guardarlo siempre es inofensivo (columna
                    // NULL en Lite/Pro, nunca llega poblada del form ahí).
                    'room_id'        => ! empty( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : null,
                    'active'         => 1,
                    'created_at'     => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ]
            );

            $msg = $inserted
                ? sprintf( $this->tt( 'Cupón %s creado correctamente.', 'Coupon %s created successfully.' ), $code )
                : $this->tt( 'Error al crear el cupón — ¿ya existe ese código?', 'Error creating the coupon — does that code already exist?' );
            set_transient( 'amir_coupon_message', $msg, 30 );
        }

        if ( $action === 'update_coupon' && ! empty( $_POST['id'] ) ) {
            $id   = (int) $_POST['id'];
            $code = strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
            if ( $code === '' ) {
                set_transient( 'amir_coupon_message', $this->tt( 'El código no puede estar vacío.', 'The code cannot be empty.' ), 30 );
                return;
            }

            // No permitir bajar el límite de usos por debajo de lo ya usado
            // — evitaría seguir aplicando el cupón a reservas ya cargadas
            // sin que nadie lo note, y confundiría el conteo mostrado.
            $times_used  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT times_used FROM {$wpdb->prefix}amir_coupons WHERE id = %d", $id ) );
            $usage_limit = ! empty( $_POST['usage_limit'] ) ? absint( $_POST['usage_limit'] ) : null;
            if ( $usage_limit !== null && $usage_limit < $times_used ) {
                set_transient( 'amir_coupon_message', sprintf( $this->tt( 'El límite de usos no puede ser menor a los %d ya utilizados.', "The usage limit can't be lower than the %d already used." ), $times_used ), 30 );
                return;
            }

            $updated = $wpdb->update(
                "{$wpdb->prefix}amir_coupons",
                [
                    'code'           => $code,
                    'discount_type'  => in_array( $_POST['discount_type'] ?? '', [ 'percent', 'fixed' ], true ) ? $_POST['discount_type'] : 'percent',
                    'discount_value' => (float) ( $_POST['discount_value'] ?? 0 ),
                    'valid_from'     => ! empty( $_POST['valid_from'] ) ? sanitize_text_field( $_POST['valid_from'] ) : null,
                    'valid_until'    => ! empty( $_POST['valid_until'] ) ? sanitize_text_field( $_POST['valid_until'] ) : null,
                    'usage_limit'    => $usage_limit,
                    'tour_id'        => ! empty( $_POST['tour_id'] ) ? absint( $_POST['tour_id'] ) : null,
                    'room_id'        => ! empty( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : null,
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d' ],
                [ '%d' ]
            );

            $msg = $updated !== false
                ? sprintf( $this->tt( 'Cupón %s actualizado correctamente.', 'Coupon %s updated successfully.' ), $code )
                : $this->tt( 'Error al actualizar el cupón — ¿ya existe ese código en otro cupón?', 'Error updating the coupon — does that code already exist on another coupon?' );
            set_transient( 'amir_coupon_message', $msg, 30 );
        }

        if ( $action === 'toggle_coupon' && ! empty( $_POST['id'] ) ) {
            $id = (int) $_POST['id'];
            $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}amir_coupons WHERE id = %d", $id ) );
            $wpdb->update( "{$wpdb->prefix}amir_coupons", [ 'active' => $current ? 0 : 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
            set_transient( 'amir_coupon_message', $this->tt( 'Cupón actualizado.', 'Coupon updated.' ), 30 );
        }

        if ( $action === 'delete_coupon' && ! empty( $_POST['id'] ) ) {
            $wpdb->delete( "{$wpdb->prefix}amir_coupons", [ 'id' => (int) $_POST['id'] ], [ '%d' ] );
            set_transient( 'amir_coupon_message', $this->tt( 'Cupón eliminado.', 'Coupon deleted.' ), 30 );
        }
    }

    private function input_style(): string {
        return 'border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;';
    }

    private function styles(): void {
        echo '<style>
        .ab-admin-wrap{max-width:1100px}
        .ab-th{font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;padding:10px 12px;text-align:left}
        .ab-td{font-size:13px;padding:10px 12px;vertical-align:middle}
        .ab-label{display:block;font-size:12px;font-weight:700;color:#1a2e24;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
        </style>';
    }
}
