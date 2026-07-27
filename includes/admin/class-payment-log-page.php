<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Log de eventos de pago — creado, exitoso, rechazado, reembolso.
 * Pensado para responder "¿por qué se rechazó esta reserva?" sin salir
 * de WordPress a buscar en el dashboard de Stripe/Mercado Pago.
 */
class PaymentLogPage {

    private const EVENT_LABELS = [
        'created'                      => [ 'label' => 'Cobro iniciado',        'color' => '#5a7068', 'bg' => '#f3f4f6' ],
        'creation_failed'              => [ 'label' => 'Error al iniciar cobro','color' => '#dc2626', 'bg' => '#fef2f2' ],
        'succeeded'                    => [ 'label' => 'Pago confirmado',       'color' => '#1D9E75', 'bg' => '#e8f5e9' ],
        'webhook_succeeded'            => [ 'label' => 'Webhook: exitoso',      'color' => '#1D9E75', 'bg' => '#e8f5e9' ],
        'webhook_failed'               => [ 'label' => 'Webhook: rechazado',    'color' => '#dc2626', 'bg' => '#fef2f2' ],
        'webhook_refunded'             => [ 'label' => 'Webhook: reembolsado',  'color' => '#6366f1', 'bg' => '#eef2ff' ],
        'confirm_check_not_succeeded'  => [ 'label' => 'Verificación: no exitoso', 'color' => '#BA7517', 'bg' => '#fef9ec' ],
        'refund_succeeded'             => [ 'label' => 'Reembolso procesado',   'color' => '#1D9E75', 'bg' => '#e8f5e9' ],
        'refund_failed'                => [ 'label' => 'Reembolso falló',       'color' => '#dc2626', 'bg' => '#fef2f2' ],
        'refund_skipped'               => [ 'label' => 'Reembolso omitido',     'color' => '#BA7517', 'bg' => '#fef9ec' ],
    ];

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
        }

        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $events = $this->get_events( $search );
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1100px;">
          <h1>💳 Log de pagos</h1>
          <p style="color:#5a7068;font-size:13px;max-width:70ch;">
            Cada intento de cobro, confirmación, rechazo o reembolso queda registrado acá — incluyendo el motivo de rechazo cuando la pasarela lo informa. No reemplaza el dashboard de Stripe/Mercado Pago, pero evita tener que ir a buscar ahí para saber qué pasó con una reserva puntual.
          </p>

          <form method="get" style="margin:16px 0;display:flex;gap:8px;max-width:420px;">
            <input type="hidden" name="page" value="amir-payment-log" />
            <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar por referencia (ej. <?php echo esc_attr( ( get_option( 'amir_booking_ref_prefix', 'BK' ) ?: 'BK' ) . '-' . date( 'Y' ) . '-00001' ); ?>)" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:8px 11px;font-size:13px;" />
            <button type="submit" class="button">Buscar</button>
          </form>

          <table class="widefat striped">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Reserva</th>
                <th>Cliente</th>
                <th>Pasarela</th>
                <th>Evento</th>
                <th>Detalle</th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $events ) ) : ?>
                <tr><td colspan="6" style="text-align:center;color:#5a7068;padding:24px;">Sin eventos registrados todavía.</td></tr>
              <?php else : foreach ( $events as $e ) :
                $info = self::EVENT_LABELS[ $e->event_type ] ?? [ 'label' => $e->event_type, 'color' => '#5a7068', 'bg' => '#f3f4f6' ];
              ?>
                <tr>
                  <td><?php echo esc_html( $e->created_at ); ?></td>
                  <td>
                    <?php if ( $e->booking_ref ) : ?>
                      <a href="<?php echo esc_url( admin_url( 'admin.php?page=amir-bookings-list&action=view&id=' . $e->booking_id ) ); ?>">
                        <code><?php echo esc_html( $e->booking_ref ); ?></code>
                      </a>
                    <?php else : ?>
                      <code>#<?php echo (int) $e->booking_id; ?></code>
                    <?php endif; ?>
                  </td>
                  <td><?php echo esc_html( $e->customer_name ?? '' ); ?></td>
                  <td><?php echo esc_html( ucfirst( $e->gateway ) ); ?></td>
                  <td>
                    <span style="background:<?php echo esc_attr( $info['bg'] ); ?>;color:<?php echo esc_attr( $info['color'] ); ?>;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:700;white-space:nowrap;">
                      <?php echo esc_html( $info['label'] ); ?>
                    </span>
                  </td>
                  <td style="font-size:12px;color:#5a7068;max-width:320px;"><?php echo esc_html( $e->message ); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

    private function get_events( string $search ): array {
        global $wpdb;

        if ( $search !== '' ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT e.*, b.booking_ref, b.customer_name
                 FROM {$wpdb->prefix}amir_payment_events e
                 LEFT JOIN {$wpdb->prefix}amir_bookings b ON b.id = e.booking_id
                 WHERE b.booking_ref LIKE %s
                 ORDER BY e.created_at DESC
                 LIMIT 100",
                '%' . $wpdb->esc_like( $search ) . '%'
            ) ) ?? [];
        }

        return \AmirBooking\Payments\PaymentEventLogger::recent( 100 );
    }
}
