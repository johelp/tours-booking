<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD de proveedores externos del marketplace (§ 11 CONTRIBUTING.md) —
 * tours de terceros que TourFlow revende con margen propio. Alta manual por
 * el equipo de TourFlow (el proveedor manda sus datos por WhatsApp/email,
 * no hay portal de autogestión en v1). Molde 1:1 de CouponsPage.
 */
class ProvidersPage {

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

        $message = get_transient( 'amir_provider_message' );
        if ( $message ) {
            delete_transient( 'amir_provider_message' );
        }

        global $wpdb;
        $providers = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}amir_providers ORDER BY business_name ASC"
        ) ?? [];

        // Cuántos tours tiene asignados cada proveedor, para no permitir
        // borrarlo mientras tenga alguno (ver handle_actions/delete_provider).
        $tour_counts = [];
        $rows = $wpdb->get_results(
            "SELECT provider_id, COUNT(*) AS n FROM {$wpdb->prefix}amir_tours WHERE provider_id IS NOT NULL GROUP BY provider_id"
        ) ?? [];
        foreach ( $rows as $r ) {
            $tour_counts[ (int) $r->provider_id ] = (int) $r->n;
        }
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1100px;">
        <?php $this->styles(); ?>
        <h1 style="display:flex;align-items:center;justify-content:space-between;">
          <span>🤝 <?php echo esc_html( $this->tt( 'Proveedores', 'Providers' ) ); ?></span>
          <button onclick="document.getElementById('amir-new-provider-form').style.display='block';this.style.display='none';"
                  class="button button-primary">+ <?php echo esc_html( $this->tt( 'Nuevo proveedor', 'New provider' ) ); ?></button>
        </h1>
        <p style="color:#5a7068;font-size:13px;max-width:700px;">
          <?php echo esc_html( $this->tt(
            'Proveedores externos cuyos tours TourFlow revende con margen propio (marketplace). El cliente paga a TourFlow — la reserva queda pendiente de que el proveedor confirme disponibilidad por email antes de darse por confirmada.',
            'External providers whose tours TourFlow resells with its own margin (marketplace). The customer pays TourFlow — the booking stays pending until the provider confirms availability by email before being considered confirmed.'
          ) ); ?>
        </p>

        <?php if ( $message ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <div id="amir-new-provider-form" style="display:none;background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px;margin-bottom:24px;">
          <h3 style="margin:0 0 16px;color:#1D9E75;"><?php echo esc_html( $this->tt( 'Nuevo proveedor', 'New provider' ) ); ?></h3>
          <form method="post">
            <?php wp_nonce_field( 'amir_provider_action' ); ?>
            <input type="hidden" name="amir_action" value="create_provider" />
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Nombre del negocio *', 'Business name *' ) ); ?></label>
                <input type="text" name="business_name" required placeholder="Kayaks del Caribe" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Persona de contacto', 'Contact person' ) ); ?></label>
                <input type="text" name="contact_name" placeholder="Juan Pérez" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="ab-label">Email *</label>
                <input type="email" name="email" required placeholder="contacto@proveedor.com" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
              <div>
                <label class="ab-label"><?php echo esc_html( $this->tt( 'Teléfono / WhatsApp', 'Phone / WhatsApp' ) ); ?></label>
                <input type="text" name="phone" placeholder="+52 983 000 0000" style="<?php echo $this->input_style(); ?> width:100%;" />
              </div>
            </div>
            <div style="margin-bottom:14px;">
              <label class="ab-label"><?php echo esc_html( $this->tt( 'Notas internas', 'Internal notes' ) ); ?></label>
              <textarea name="notes" rows="2" style="<?php echo $this->input_style(); ?> width:100%;font-family:inherit;"></textarea>
            </div>
            <div style="display:flex;gap:10px;">
              <button type="submit" class="button button-primary"><?php echo esc_html( $this->tt( 'Crear proveedor', 'Create provider' ) ); ?></button>
              <button type="button" onclick="document.getElementById('amir-new-provider-form').style.display='none';" class="button"><?php echo esc_html( $this->tt( 'Cancelar', 'Cancel' ) ); ?></button>
            </div>
          </form>
        </div>

        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#f8fdfb;">
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Negocio', 'Business' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Contacto', 'Contact' ) ); ?></th>
            <th class="ab-th">Email</th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Teléfono', 'Phone' ) ); ?></th>
            <th class="ab-th">Tours</th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Estado', 'Status' ) ); ?></th>
            <th class="ab-th"></th>
          </tr></thead>
          <tbody>
          <?php if ( empty( $providers ) ) : ?>
            <tr><td colspan="7" class="ab-td" style="text-align:center;color:#5a7068;padding:24px;"><?php echo esc_html( $this->tt( 'Sin proveedores creados todavía.', 'No providers created yet.' ) ); ?></td></tr>
          <?php else : foreach ( $providers as $p ) : $n_tours = $tour_counts[ (int) $p->id ] ?? 0; ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
              <td class="ab-td"><strong><?php echo esc_html( $p->business_name ); ?></strong></td>
              <td class="ab-td"><?php echo esc_html( $p->contact_name ?: '—' ); ?></td>
              <td class="ab-td"><?php echo esc_html( $p->email ); ?></td>
              <td class="ab-td"><?php echo esc_html( $p->phone ?: '—' ); ?></td>
              <td class="ab-td"><?php echo (int) $n_tours; ?></td>
              <td class="ab-td">
                <?php if ( (int) $p->active === 1 ) : ?>
                  <span style="color:#1D9E75;font-weight:700;font-size:12px;">✓ <?php echo esc_html( $this->tt( 'Activo', 'Active' ) ); ?></span>
                <?php else : ?>
                  <span style="color:#5a7068;font-size:12px;"><?php echo esc_html( $this->tt( 'Inactivo', 'Inactive' ) ); ?></span>
                <?php endif; ?>
              </td>
              <td class="ab-td">
                <button type="button" class="button button-small"
                        onclick="document.getElementById('amir-edit-provider-<?php echo (int) $p->id; ?>').style.display='block';"><?php echo esc_html( $this->tt( 'Editar', 'Edit' ) ); ?></button>
                <form method="post" style="display:inline;">
                  <?php wp_nonce_field( 'amir_provider_action' ); ?>
                  <input type="hidden" name="amir_action" value="toggle_provider" />
                  <input type="hidden" name="id" value="<?php echo (int) $p->id; ?>" />
                  <button type="submit" class="button button-small"><?php echo (int) $p->active === 1 ? esc_html( $this->tt( 'Desactivar', 'Deactivate' ) ) : esc_html( $this->tt( 'Activar', 'Activate' ) ); ?></button>
                </form>
                <?php if ( $n_tours === 0 ) : ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Eliminar este proveedor?', 'Delete this provider?' ) ); ?>');">
                  <?php wp_nonce_field( 'amir_provider_action' ); ?>
                  <input type="hidden" name="amir_action" value="delete_provider" />
                  <input type="hidden" name="id" value="<?php echo (int) $p->id; ?>" />
                  <button type="submit" class="button button-small" style="color:#e24b4a;"><?php echo esc_html( $this->tt( 'Eliminar', 'Delete' ) ); ?></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <tr id="amir-edit-provider-<?php echo (int) $p->id; ?>" style="display:none;">
              <td colspan="7" class="ab-td" style="background:#f8fdfb;">
                <form method="post">
                  <?php wp_nonce_field( 'amir_provider_action' ); ?>
                  <input type="hidden" name="amir_action" value="update_provider" />
                  <input type="hidden" name="id" value="<?php echo (int) $p->id; ?>" />
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Nombre del negocio *', 'Business name *' ) ); ?></label>
                      <input type="text" name="business_name" required value="<?php echo esc_attr( $p->business_name ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Persona de contacto', 'Contact person' ) ); ?></label>
                      <input type="text" name="contact_name" value="<?php echo esc_attr( $p->contact_name ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                      <label class="ab-label">Email *</label>
                      <input type="email" name="email" required value="<?php echo esc_attr( $p->email ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                    <div>
                      <label class="ab-label"><?php echo esc_html( $this->tt( 'Teléfono / WhatsApp', 'Phone / WhatsApp' ) ); ?></label>
                      <input type="text" name="phone" value="<?php echo esc_attr( $p->phone ); ?>" style="<?php echo $this->input_style(); ?> width:100%;" />
                    </div>
                  </div>
                  <div style="margin-bottom:14px;">
                    <label class="ab-label"><?php echo esc_html( $this->tt( 'Notas internas', 'Internal notes' ) ); ?></label>
                    <textarea name="notes" rows="2" style="<?php echo $this->input_style(); ?> width:100%;font-family:inherit;"><?php echo esc_textarea( $p->notes ); ?></textarea>
                  </div>
                  <div style="display:flex;gap:10px;">
                    <button type="submit" class="button button-primary"><?php echo esc_html( $this->tt( 'Guardar cambios', 'Save changes' ) ); ?></button>
                    <button type="button" onclick="document.getElementById('amir-edit-provider-<?php echo (int) $p->id; ?>').style.display='none';" class="button"><?php echo esc_html( $this->tt( 'Cancelar', 'Cancel' ) ); ?></button>
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
        if ( empty( $_POST['amir_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_provider_action' ) ) {
            return;
        }

        global $wpdb;
        $action = sanitize_key( $_POST['amir_action'] );

        if ( $action === 'create_provider' ) {
            $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
            $email         = sanitize_email( $_POST['email'] ?? '' );
            if ( $business_name === '' || ! is_email( $email ) ) {
                set_transient( 'amir_provider_message', $this->tt( 'Nombre del negocio y un email válido son obligatorios.', 'Business name and a valid email are required.' ), 30 );
                return;
            }

            $inserted = $wpdb->insert(
                "{$wpdb->prefix}amir_providers",
                [
                    'business_name' => $business_name,
                    'contact_name'  => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
                    'email'         => $email,
                    'phone'         => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
                    'notes'         => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
                    'active'        => 1,
                    'created_at'    => current_time( 'mysql' ),
                    'updated_at'    => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );

            $msg = $inserted
                ? sprintf( $this->tt( 'Proveedor "%s" creado correctamente.', 'Provider "%s" created successfully.' ), $business_name )
                : $this->tt( 'Error al crear el proveedor.', 'Error creating the provider.' );
            set_transient( 'amir_provider_message', $msg, 30 );
        }

        if ( $action === 'update_provider' && ! empty( $_POST['id'] ) ) {
            $id            = (int) $_POST['id'];
            $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
            $email         = sanitize_email( $_POST['email'] ?? '' );
            if ( $business_name === '' || ! is_email( $email ) ) {
                set_transient( 'amir_provider_message', $this->tt( 'Nombre del negocio y un email válido son obligatorios.', 'Business name and a valid email are required.' ), 30 );
                return;
            }

            $wpdb->update(
                "{$wpdb->prefix}amir_providers",
                [
                    'business_name' => $business_name,
                    'contact_name'  => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
                    'email'         => $email,
                    'phone'         => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
                    'notes'         => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
                    'updated_at'    => current_time( 'mysql' ),
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            set_transient( 'amir_provider_message', sprintf( $this->tt( 'Proveedor "%s" actualizado correctamente.', 'Provider "%s" updated successfully.' ), $business_name ), 30 );
        }

        if ( $action === 'toggle_provider' && ! empty( $_POST['id'] ) ) {
            $id      = (int) $_POST['id'];
            $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}amir_providers WHERE id = %d", $id ) );
            $wpdb->update( "{$wpdb->prefix}amir_providers", [ 'active' => $current ? 0 : 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
            set_transient( 'amir_provider_message', $this->tt( 'Proveedor actualizado.', 'Provider updated.' ), 30 );
        }

        if ( $action === 'delete_provider' && ! empty( $_POST['id'] ) ) {
            $id = (int) $_POST['id'];
            // No borrar si tiene tours asignados — evita huérfanos de FK
            // lógica (amir_tours.provider_id). El operador debe desasignar
            // los tours o desactivar el proveedor en su lugar.
            $n_tours = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}amir_tours WHERE provider_id = %d", $id ) );
            if ( $n_tours > 0 ) {
                set_transient( 'amir_provider_message', sprintf( $this->tt( 'No se puede eliminar: tiene %d tour(s) asignado(s). Desactivalo en su lugar.', "Can't delete: it has %d tour(s) assigned. Deactivate it instead." ), $n_tours ), 30 );
                return;
            }
            $wpdb->delete( "{$wpdb->prefix}amir_providers", [ 'id' => $id ], [ '%d' ] );
            set_transient( 'amir_provider_message', $this->tt( 'Proveedor eliminado.', 'Provider deleted.' ), 30 );
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
