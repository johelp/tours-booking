<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Log de eventos de pago — creado, exitoso, rechazado, reembolso.
 * Pensado para responder "¿por qué se rechazó esta reserva?" sin salir
 * de WordPress a buscar en el dashboard de Stripe/Mercado Pago.
 */
class PaymentLogPage {

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private function lang(): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? 'en' : 'es';
    }

    /** Traducción es/en para esta pantalla — ver lang(). */
    private function tt( string $es, string $en ): string {
        return $this->lang() === 'en' ? $en : $es;
    }

    private function event_labels(): array {
        $en = $this->lang() === 'en';
        return [
            'created'                      => [ 'label' => $en ? 'Charge started'                        : 'Cobro iniciado',        'color' => '#5a7068', 'bg' => '#f3f4f6' ],
            'creation_failed'              => [ 'label' => $en ? 'Error starting charge'                  : 'Error al iniciar cobro','color' => '#dc2626', 'bg' => '#fef2f2' ],
            'succeeded'                    => [ 'label' => $en ? 'Payment confirmed'                      : 'Pago confirmado',       'color' => '#1D9E75', 'bg' => '#e8f5e9' ],
            'webhook_succeeded'            => [ 'label' => $en ? 'Webhook: succeeded'                     : 'Webhook: exitoso',      'color' => '#1D9E75', 'bg' => '#e8f5e9' ],
            'webhook_failed'               => [ 'label' => $en ? 'Webhook: rejected'                      : 'Webhook: rechazado',    'color' => '#dc2626', 'bg' => '#fef2f2' ],
            'webhook_refunded'             => [ 'label' => $en ? 'Webhook: refunded'                      : 'Webhook: reembolsado',  'color' => '#6366f1', 'bg' => '#eef2ff' ],
            'confirm_check_not_succeeded'  => [ 'label' => $en ? 'Verification: not successful'           : 'Verificación: no exitoso', 'color' => '#BA7517', 'bg' => '#fef9ec' ],
            'confirm_check_unverifiable'   => [ 'label' => $en ? 'Verification: no response from gateway' : 'Verificación: sin respuesta de la pasarela', 'color' => '#BA7517', 'bg' => '#fef9ec' ],
            'confirm_check_gateway_unconfigured' => [ 'label' => $en ? 'Verification: gateway not configured' : 'Verificación: pasarela sin configurar', 'color' => '#dc2626', 'bg' => '#fef2f2' ],
            'confirm_check_reference_mismatch'   => [ 'label' => $en ? '⚠ Payment reference does not match' : '⚠ Referencia de pago no coincide', 'color' => '#dc2626', 'bg' => '#fef2f2' ],
            'refund_succeeded'             => [ 'label' => $en ? 'Refund processed'                       : 'Reembolso procesado',   'color' => '#1D9E75', 'bg' => '#e8f5e9' ],
            'refund_failed'                => [ 'label' => $en ? 'Refund failed'                          : 'Reembolso falló',       'color' => '#dc2626', 'bg' => '#fef2f2' ],
            'refund_skipped'               => [ 'label' => $en ? 'Refund skipped'                         : 'Reembolso omitido',     'color' => '#BA7517', 'bg' => '#fef9ec' ],
        ];
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
            wp_die( esc_html( $this->tt( 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
        }

        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $events = $this->get_events( $search );
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1100px;">
          <h1>💳 <?php echo esc_html( $this->tt( 'Log de pagos', 'Payment log' ) ); ?></h1>
          <p style="color:#5a7068;font-size:13px;max-width:70ch;">
            <?php echo esc_html( $this->tt(
              'Cada intento de cobro, confirmación, rechazo o reembolso queda registrado acá — incluyendo el motivo de rechazo cuando la pasarela lo informa. No reemplaza el dashboard de Stripe/Mercado Pago, pero evita tener que ir a buscar ahí para saber qué pasó con una reserva puntual.',
              "Every charge attempt, confirmation, rejection, or refund is logged here — including the rejection reason when the gateway provides one. It doesn't replace the Stripe/Mercado Pago dashboard, but saves you from having to check there to find out what happened with a specific booking."
            ) ); ?>
          </p>

          <form method="get" style="margin:16px 0;display:flex;gap:8px;max-width:420px;">
            <input type="hidden" name="page" value="amir-payment-log" />
            <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr( sprintf( $this->tt( 'Buscar por referencia (ej. %s)', 'Search by reference (e.g. %s)' ), ( get_option( 'amir_booking_ref_prefix', 'BK' ) ?: 'BK' ) . '-' . date( 'Y' ) . '-00001' ) ); ?>" style="flex:1;border:1px solid #c3d9d0;border-radius:6px;padding:8px 11px;font-size:13px;" />
            <button type="submit" class="button"><?php echo esc_html( $this->tt( 'Buscar', 'Search' ) ); ?></button>
          </form>

          <table class="widefat striped">
            <thead>
              <tr>
                <th><?php echo esc_html( $this->tt( 'Fecha', 'Date' ) ); ?></th>
                <th><?php echo esc_html( $this->tt( 'Reserva', 'Booking' ) ); ?></th>
                <th><?php echo esc_html( $this->tt( 'Cliente', 'Customer' ) ); ?></th>
                <th><?php echo esc_html( $this->tt( 'Pasarela', 'Gateway' ) ); ?></th>
                <th><?php echo esc_html( $this->tt( 'Evento', 'Event' ) ); ?></th>
                <th><?php echo esc_html( $this->tt( 'Detalle', 'Detail' ) ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ( empty( $events ) ) : ?>
                <tr><td colspan="6" style="text-align:center;color:#5a7068;padding:24px;"><?php echo esc_html( $this->tt( 'Sin eventos registrados todavía.', 'No events logged yet.' ) ); ?></td></tr>
              <?php else : foreach ( $events as $e ) :
                $labels = $this->event_labels();
                $info = $labels[ $e->event_type ] ?? [ 'label' => $e->event_type, 'color' => '#5a7068', 'bg' => '#f3f4f6' ];
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
