<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD de cupones de descuento (% o monto fijo, programables por fecha).
 */
class CouponsPage {

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
        }

        $this->handle_actions();

        $message = get_transient( 'amir_coupon_message' );
        if ( $message ) {
            delete_transient( 'amir_coupon_message' );
        }

        global $wpdb;
        $coupons = $wpdb->get_results(
            "SELECT c.*, t.name_es AS tour_name
             FROM {$wpdb->prefix}amir_coupons c
             LEFT JOIN {$wpdb->prefix}amir_tours t ON t.id = c.tour_id
             ORDER BY c.created_at DESC"
        ) ?? [];

        $tours = $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}amir_tours WHERE status = 'active' ORDER BY sort_order"
        ) ?? [];
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1100px;">
        <?php $this->styles(); ?>
        <h1 style="display:flex;align-items:center;justify-content:space-between;">
          <span>🏷️ Cupones</span>
          <button onclick="document.getElementById('amir-new-coupon-form').style.display='block';this.style.display='none';"
                  class="button button-primary">+ Nuevo cupón</button>
        </h1>

        <?php if ( $message ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <div id="amir-new-coupon-form" style="display:none;background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px;margin-bottom:24px;">
          <h3 style="margin:0 0 16px;color:#1D9E75;">Nuevo cupón</h3>
          <form method="post">
            <?php wp_nonce_field( 'amir_coupon_action' ); ?>
            <input type="hidden" name="amir_action" value="create_coupon" />
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="ab-label">Código *</label>
                <input type="text" name="code" required placeholder="VERANO2026" style="<?php echo $this->input_style(); ?> width:100%;text-transform:uppercase;" />
              </div>
              <div>
                <label class="ab-label">Tipo de descuento</label>
                <select name="discount_type" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="percent">Porcentaje (%)</option>
                  <option value="fixed">Monto fijo (<?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?>)</option>
                </select>
              </div>
              <div>
                <label class="ab-label">Valor *</label>
                <input type="number" name="discount_value" required min="0" step="0.01" placeholder="15" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="ab-label">Válido desde</label>
                <input type="date" name="valid_from" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label class="ab-label">Válido hasta</label>
                <input type="date" name="valid_until" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label class="ab-label">Límite de usos (vacío = ilimitado)</label>
                <input type="number" name="usage_limit" min="1" placeholder="100" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
            </div>
            <div style="margin-bottom:14px;max-width:340px;">
              <label class="ab-label">Aplica a</label>
              <select name="tour_id" style="<?php echo $this->input_style(); ?> width:100%;">
                <option value="">Todos los tours</option>
                <?php foreach ( $tours as $t ) : ?>
                  <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->name_es ); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="display:flex;gap:10px;">
              <button type="submit" class="button button-primary">Crear cupón</button>
              <button type="button" onclick="document.getElementById('amir-new-coupon-form').style.display='none';" class="button">Cancelar</button>
            </div>
          </form>
        </div>

        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#f8fdfb;">
            <th class="ab-th">Código</th>
            <th class="ab-th">Descuento</th>
            <th class="ab-th">Vigencia</th>
            <th class="ab-th">Usos</th>
            <th class="ab-th">Tour</th>
            <th class="ab-th">Estado</th>
            <th class="ab-th"></th>
          </tr></thead>
          <tbody>
          <?php if ( empty( $coupons ) ) : ?>
            <tr><td colspan="7" class="ab-td" style="text-align:center;color:#5a7068;padding:24px;">Sin cupones creados todavía.</td></tr>
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
              <td class="ab-td"><?php echo esc_html( $c->tour_name ?: 'Todos' ); ?></td>
              <td class="ab-td">
                <?php if ( (int) $c->active === 1 ) : ?>
                  <span style="color:#1D9E75;font-weight:700;font-size:12px;">✓ Activo</span>
                <?php else : ?>
                  <span style="color:#5a7068;font-size:12px;">Pausado</span>
                <?php endif; ?>
              </td>
              <td class="ab-td">
                <button type="button" class="button button-small"
                        onclick="document.getElementById('amir-edit-coupon-<?php echo (int) $c->id; ?>').style.display='block';">Editar</button>
                <form method="post" style="display:inline;">
                  <?php wp_nonce_field( 'amir_coupon_action' ); ?>
                  <input type="hidden" name="amir_action" value="toggle_coupon" />
                  <input type="hidden" name="id" value="<?php echo (int) $c->id; ?>" />
                  <button type="submit" class="button button-small"><?php echo (int) $c->active === 1 ? 'Pausar' : 'Activar'; ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este cupón?');">
                  <?php wp_nonce_field( 'amir_coupon_action' ); ?>
                  <input type="hidden" name="amir_action" value="delete_coupon" />
                  <input type="hidden" name="id" value="<?php echo (int) $c->id; ?>" />
                  <button type="submit" class="button button-small" style="color:#e24b4a;">Eliminar</button>
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
                      <label class="ab-label">Código *</label>
                      <input type="text" name="code" required value="<?php echo esc_attr( $c->code ); ?>" style="<?php echo $this->input_style(); ?> width:100%;text-transform:uppercase;" />
                    </div>
                    <div>
                      <label class="ab-label">Tipo de descuento</label>
                      <select name="discount_type" style="<?php echo $this->input_style(); ?> width:100%;">
                        <option value="percent" <?php selected( $c->discount_type, 'percent' ); ?>>Porcentaje (%)</option>
                        <option value="fixed" <?php selected( $c->discount_type, 'fixed' ); ?>>Monto fijo (<?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?>)</option>
                      </select>
                    </div>
                    <div>
                      <label class="ab-label">Valor *</label>
                      <input type="number" name="discount_value" required min="0" step="0.01" value="<?php echo esc_attr( $c->discount_value ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                      <label class="ab-label">Válido desde</label>
                      <input type="date" name="valid_from" value="<?php echo esc_attr( $c->valid_from ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                    <div>
                      <label class="ab-label">Válido hasta</label>
                      <input type="date" name="valid_until" value="<?php echo esc_attr( $c->valid_until ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                    <div>
                      <label class="ab-label">Límite de usos (vacío = ilimitado)</label>
                      <input type="number" name="usage_limit" min="<?php echo (int) $c->times_used; ?>" value="<?php echo esc_attr( $c->usage_limit ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                  </div>
                  <div style="margin-bottom:14px;max-width:340px;">
                    <label class="ab-label">Aplica a</label>
                    <select name="tour_id" style="<?php echo $this->input_style(); ?> width:100%;">
                      <option value="">Todos los tours</option>
                      <?php foreach ( $tours as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>" <?php selected( (int) $c->tour_id, (int) $t->id ); ?>><?php echo esc_html( $t->name_es ); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div style="display:flex;gap:10px;">
                    <button type="submit" class="button button-primary">Guardar cambios</button>
                    <button type="button" onclick="document.getElementById('amir-edit-coupon-<?php echo (int) $c->id; ?>').style.display='none';" class="button">Cancelar</button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
        </div>
        <?php
    }

    private function handle_actions(): void {
        if ( empty( $_POST['amir_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_coupon_action' ) ) {
            return;
        }

        global $wpdb;
        $action = sanitize_key( $_POST['amir_action'] );

        if ( $action === 'create_coupon' ) {
            $code = strtoupper( sanitize_text_field( $_POST['code'] ?? '' ) );
            if ( $code === '' ) {
                set_transient( 'amir_coupon_message', 'El código no puede estar vacío.', 30 );
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
                    'active'         => 1,
                    'created_at'     => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s' ]
            );

            $msg = $inserted
                ? "Cupón {$code} creado correctamente."
                : 'Error al crear el cupón — ¿ya existe ese código?';
            set_transient( 'amir_coupon_message', $msg, 30 );
        }

        if ( $action === 'update_coupon' && ! empty( $_POST['id'] ) ) {
            $id   = (int) $_POST['id'];
            $code = strtoupper( sanitize_text_field( $_POST['code'] ?? '' ) );
            if ( $code === '' ) {
                set_transient( 'amir_coupon_message', 'El código no puede estar vacío.', 30 );
                return;
            }

            // No permitir bajar el límite de usos por debajo de lo ya usado
            // — evitaría seguir aplicando el cupón a reservas ya cargadas
            // sin que nadie lo note, y confundiría el conteo mostrado.
            $times_used  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT times_used FROM {$wpdb->prefix}amir_coupons WHERE id = %d", $id ) );
            $usage_limit = ! empty( $_POST['usage_limit'] ) ? absint( $_POST['usage_limit'] ) : null;
            if ( $usage_limit !== null && $usage_limit < $times_used ) {
                set_transient( 'amir_coupon_message', "El límite de usos no puede ser menor a los {$times_used} ya utilizados.", 30 );
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
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%f', '%s', '%s', '%d', '%d' ],
                [ '%d' ]
            );

            $msg = $updated !== false
                ? "Cupón {$code} actualizado correctamente."
                : 'Error al actualizar el cupón — ¿ya existe ese código en otro cupón?';
            set_transient( 'amir_coupon_message', $msg, 30 );
        }

        if ( $action === 'toggle_coupon' && ! empty( $_POST['id'] ) ) {
            $id = (int) $_POST['id'];
            $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}amir_coupons WHERE id = %d", $id ) );
            $wpdb->update( "{$wpdb->prefix}amir_coupons", [ 'active' => $current ? 0 : 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
            set_transient( 'amir_coupon_message', 'Cupón actualizado.', 30 );
        }

        if ( $action === 'delete_coupon' && ! empty( $_POST['id'] ) ) {
            $wpdb->delete( "{$wpdb->prefix}amir_coupons", [ 'id' => (int) $_POST['id'] ], [ '%d' ] );
            set_transient( 'amir_coupon_message', 'Cupón eliminado.', 30 );
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
