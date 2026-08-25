<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

/**
 * Gestión de disponibilidad por temporada de habitaciones (Pro Max —
 * CONTRIBUTING.md § 16.11, 2026-08-01). Mismo patrón que
 * AmirBooking\Admin\AvailabilityPage (tours), pero sin días de la semana —
 * una reserva de habitación son noches consecutivas, no tiene el mismo
 * sentido bloquear un día suelto. Página propia en vez de extender la de
 * tours para no arriesgar un screen ya probado.
 */
class RoomAvailabilityPage {

	public function render(): void {
		$this->handle_actions();

		$rooms         = $this->get_rooms();
		$selected_room = (int) ( $_GET['room_id'] ?? ( $rooms[0]->id ?? 0 ) );
		$rules         = $selected_room ? $this->get_rules( $selected_room ) : [];
		$message       = get_transient( 'flow_room_avail_message' );
		if ( $message ) {
			delete_transient( 'flow_room_avail_message' );
		}
		?>
		<div class="wrap ab-admin-wrap">
		<style>
		.ab-admin-wrap { max-width:1200px; }
		.ab-th { font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;padding:10px 12px;text-align:left; }
		.ab-td { font-size:13px;padding:10px 12px;vertical-align:middle; }
		.ab-form-field { margin-bottom:12px; }
		.ab-form-field label { display:block;font-size:12px;font-weight:700;color:#1a2e24;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px; }
		</style>

		<h1>🌤 Disponibilidad de habitaciones</h1>

		<?php if ( $message ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>'; ?>

		<div style="display:flex;gap:12px;align-items:center;margin-bottom:24px;flex-wrap:wrap;">
		  <label style="font-weight:600;font-size:13px;">Habitación:</label>
		  <?php foreach ( $rooms as $r ) : ?>
		    <a href="<?php echo admin_url( 'admin.php?page=flow-room-availability&room_id=' . $r->id ); ?>"
		       style="padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;
		              background:<?php echo $selected_room === (int) $r->id ? '#1D9E75' : '#f0faf6'; ?>;
		              color:<?php echo $selected_room === (int) $r->id ? '#fff' : '#1D9E75'; ?>;
		              border:1.5px solid <?php echo $selected_room === (int) $r->id ? '#1D9E75' : '#c3d9d0'; ?>;">
		      <?php echo esc_html( $r->name_es ); ?>
		    </a>
		  <?php endforeach; ?>
		</div>

		<?php if ( ! $selected_room ) : ?>
		  <p style="color:#5a7068;">No hay habitaciones activas todavía — cargá una en TourFlow → 🛏 Habitaciones.</p>
		<?php else : ?>

		<div style="display:grid;grid-template-columns:1fr 380px;gap:24px;">

		  <!-- Reglas existentes -->
		  <div>
		    <div style="font-size:16px;font-weight:700;color:#1a2e24;margin-bottom:14px;">
		      Reglas de disponibilidad
		      <span style="font-size:12px;font-weight:400;color:#5a7068;margin-left:8px;">Mayor prioridad sobreescribe a menor</span>
		    </div>

		    <?php if ( empty( $rules ) ) : ?>
		      <div style="background:#f8fdfb;border:1px dashed #c3d9d0;border-radius:10px;padding:20px;text-align:center;font-size:13px;color:#5a7068;">
		        Sin reglas configuradas — la habitación está disponible en cualquier fecha (sujeto a reservas ya cargadas).
		      </div>
		    <?php else : ?>
		      <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
		      <table style="width:100%;border-collapse:collapse;">
		        <thead>
		          <tr style="background:#f8fdfb;">
		            <th class="ab-th">Tipo</th>
		            <th class="ab-th">Desde</th>
		            <th class="ab-th">Hasta</th>
		            <th class="ab-th">Prioridad</th>
		            <th class="ab-th">Motivo</th>
		            <th class="ab-th"></th>
		          </tr>
		        </thead>
		        <tbody>
		        <?php foreach ( $rules as $rule ) : ?>
		          <tr style="border-bottom:1px solid #f5f5f5;">
		            <td class="ab-td">
		              <span style="background:<?php echo $rule->rule_type === 'block' ? '#fef2f2' : '#e1f5ee'; ?>;
		                           color:<?php echo $rule->rule_type === 'block' ? '#e24b4a' : '#0F6E56'; ?>;
		                           font-size:11px;font-weight:700;padding:3px 8px;border-radius:10px;">
		                <?php echo $rule->rule_type === 'block' ? '🚫 Bloquear' : '✅ Permitir'; ?>
		              </span>
		            </td>
		            <td class="ab-td"><?php echo $rule->date_from ?: '—'; ?></td>
		            <td class="ab-td"><?php echo $rule->date_until ?: '—'; ?></td>
		            <td class="ab-td" style="font-weight:700;"><?php echo $rule->priority; ?></td>
		            <td class="ab-td" style="color:#5a7068;font-size:12px;"><?php echo esc_html( $rule->reason ?: '—' ); ?></td>
		            <td class="ab-td">
		              <form method="post" style="display:inline;">
		                <?php wp_nonce_field( 'flow_room_avail_action' ); ?>
		                <input type="hidden" name="flow_action" value="delete_rule" />
		                <input type="hidden" name="rule_id" value="<?php echo (int) $rule->id; ?>" />
		                <input type="hidden" name="room_id" value="<?php echo (int) $selected_room; ?>" />
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

		    <div style="background:#fff8e7;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-top:16px;">
		      <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:6px;">💡 Ejemplo — "solo se ofrece junio-agosto"</div>
		      <p style="font-size:12px;color:#78350f;line-height:1.6;">
		        Agregá una regla <strong>Bloquear</strong> sin fechas (aplica a todo el año, prioridad 10) + una regla <strong>Permitir</strong> del 1 de junio al 31 de agosto (prioridad 20, mayor gana). Resultado: solo se puede reservar en esos meses.
		      </p>
		    </div>
		  </div>

		  <!-- Formulario nueva regla -->
		  <div>
		    <div style="font-size:16px;font-weight:700;color:#1a2e24;margin-bottom:14px;">Nueva regla</div>
		    <form method="post" style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:18px;">
		      <?php wp_nonce_field( 'flow_room_avail_action' ); ?>
		      <input type="hidden" name="flow_action" value="add_rule" />
		      <input type="hidden" name="room_id" value="<?php echo (int) $selected_room; ?>" />

		      <div class="ab-form-field">
		        <label>Tipo de regla</label>
		        <select name="rule_type" style="border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;">
		          <option value="block">🚫 Bloquear (no disponible)</option>
		          <option value="allow">✅ Permitir (excepción)</option>
		        </select>
		      </div>

		      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
		        <div class="ab-form-field">
		          <label>Fecha desde (opcional)</label>
		          <input type="date" name="date_from" style="border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;" />
		        </div>
		        <div class="ab-form-field">
		          <label>Fecha hasta (opcional)</label>
		          <input type="date" name="date_until" style="border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;" />
		        </div>
		      </div>

		      <div class="ab-form-field">
		        <label>Prioridad <span style="font-weight:400;color:#5a7068;">(mayor = sobreescribe)</span></label>
		        <input type="number" name="priority" value="10" min="1" max="100"
		               style="border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;" />
		      </div>

		      <div class="ab-form-field">
		        <label>Motivo (opcional)</label>
		        <input type="text" name="reason" placeholder="Ej: Solo temporada alta, mantenimiento…"
		               style="border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;" />
		      </div>

		      <button type="submit" class="button button-primary" style="width:100%;padding:9px;">Agregar regla</button>
		    </form>
		  </div>

		</div><!-- grid -->
		<?php endif; ?>

		</div>
		<?php
	}

	private function handle_actions(): void {
		if ( empty( $_POST['flow_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'flow_room_avail_action' ) ) {
			return;
		}

		global $wpdb;
		$action  = sanitize_key( $_POST['flow_action'] );
		$room_id = (int) ( $_POST['room_id'] ?? 0 );

		if ( $action === 'add_rule' && $room_id ) {
			$wpdb->insert( "{$wpdb->prefix}flow_room_availability_rules", [
				'room_id'    => $room_id,
				'rule_type'  => in_array( $_POST['rule_type'] ?? '', [ 'block', 'allow' ], true ) ? $_POST['rule_type'] : 'block',
				'date_from'  => sanitize_text_field( $_POST['date_from'] ?? '' ) ?: null,
				'date_until' => sanitize_text_field( $_POST['date_until'] ?? '' ) ?: null,
				'priority'   => max( 1, min( 100, (int) ( $_POST['priority'] ?? 10 ) ) ),
				'reason'     => sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ),
			] );
			set_transient( 'flow_room_avail_message', 'Regla agregada correctamente.', 30 );
		}

		if ( $action === 'delete_rule' ) {
			$rule_id = (int) ( $_POST['rule_id'] ?? 0 );
			if ( $rule_id ) {
				$wpdb->delete( "{$wpdb->prefix}flow_room_availability_rules", [ 'id' => $rule_id, 'room_id' => $room_id ] );
				set_transient( 'flow_room_avail_message', 'Regla eliminada.', 30 );
			}
		}

		wp_redirect( admin_url( 'admin.php?page=flow-room-availability&room_id=' . $room_id ) );
		exit;
	}

	private function get_rooms(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT id, name_es FROM {$wpdb->prefix}flow_rooms WHERE status='active' ORDER BY sort_order, name_es"
		) ?: [];
	}

	private function get_rules( int $room_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}flow_room_availability_rules WHERE room_id=%d ORDER BY priority DESC, id ASC",
			$room_id
		) ) ?? [];
	}
}
