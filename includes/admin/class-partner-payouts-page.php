<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Ledger de liquidación a partners/RRPP — mismo patrón exacto que
 * ProviderPayoutsPage (marketplace de proveedores). Cada fila se genera
 * automáticamente al confirmarse una reserva con partner_id (ver el
 * listener de amir_booking_confirmed en class-plugin.php) — esta pantalla
 * es de solo lectura salvo por la única acción real: marcar una fila como
 * pagada cuando el equipo efectivamente le transfiere al partner.
 * Liquidación manual v1, sin payout automático — ver
 * PROMPT-MERCADOPAGO-SPLIT.md § 6 para el análisis que encontró este hueco.
 */
class PartnerPayoutsPage {

    /** Idioma de esta pantalla — ver el mismo helper en SettingsPage/BookingsPage. */
    private string $lang_override = '';

    public function set_lang( string $lang ): void {
        $this->lang_override = $lang;
    }

    private function lang(): string {
        if ( $this->lang_override !== '' ) {
            return $this->lang_override;
        }
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

        $this->handle_actions();

        $message = get_transient( 'amir_partner_payout_message' );
        if ( $message ) {
            delete_transient( 'amir_partner_payout_message' );
        }

        global $wpdb;

        $partner_filter = (int) ( $_GET['partner_id'] ?? 0 );
        $status_filter  = sanitize_key( $_GET['status'] ?? '' );

        $where  = [ '1=1' ];
        $params = [];
        if ( $partner_filter > 0 ) {
            $where[]  = 'pp.partner_id = %d';
            $params[] = $partner_filter;
        }
        if ( in_array( $status_filter, [ 'pending', 'paid' ], true ) ) {
            $where[]  = 'pp.status = %s';
            $params[] = $status_filter;
        }
        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT pp.*, pt.name AS partner_name, b.booking_ref, b.tour_date, b.customer_name
                FROM {$wpdb->prefix}amir_partner_payouts pp
                LEFT JOIN {$wpdb->prefix}amir_partners pt ON pt.id = pp.partner_id
                LEFT JOIN {$wpdb->prefix}amir_bookings b ON b.id = pp.booking_id
                WHERE {$where_sql}
                ORDER BY pp.created_at DESC";
        $payouts = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
        $payouts = $payouts ?? [];

        $pending_total = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount_mxn),0) FROM {$wpdb->prefix}amir_partner_payouts WHERE status = 'pending'"
        );

        $partners = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}amir_partners ORDER BY name"
        ) ?? [];
        ?>
        <div class="wrap ab-admin-wrap" style="max-width:1100px;">
        <?php $this->styles(); ?>
        <h1>💸 <?php echo esc_html( $this->tt( 'Liquidación de partners', 'Partner payouts' ) ); ?></h1>
        <p style="color:#5a7068;font-size:13px;max-width:70ch;">
          <?php echo esc_html( $this->tt(
            'Ledger simple de lo que TourFlow le debe a cada partner/RRPP por comisión — se genera una fila automática al confirmarse cada reserva con ese partner. Liquidación manual: marcá "Pagado" cuando efectivamente le transfieras.',
            'Simple ledger of what TourFlow owes each partner/referrer in commission — a row is generated automatically when each booking with that partner is confirmed. Manual payout: mark "Paid" once you actually transfer the funds.'
          ) ); ?>
        </p>

        <div style="background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:14px 20px;margin:16px 0;display:inline-block;">
          <strong style="color:#9a3412;"><?php echo esc_html( $this->tt( 'Total pendiente de liquidar:', 'Total pending payout:' ) ); ?></strong>
          <span style="font-size:18px;font-weight:800;color:#9a3412;"><?php echo esc_html( \AmirBooking\Core\Currency::format( $pending_total ) ); ?></span>
        </div>

        <?php if ( $message ) : ?>
          <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <form method="get" style="margin:16px 0;display:flex;gap:10px;align-items:center;">
          <input type="hidden" name="page" value="amir-partner-payouts" />
          <select name="partner_id" style="<?php echo $this->input_style(); ?>">
            <option value=""><?php echo esc_html( $this->tt( 'Todos los partners', 'All partners' ) ); ?></option>
            <?php foreach ( $partners as $p ) : ?>
              <option value="<?php echo (int) $p->id; ?>" <?php selected( $partner_filter, (int) $p->id ); ?>><?php echo esc_html( $p->name ); ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" style="<?php echo $this->input_style(); ?>">
            <option value=""><?php echo esc_html( $this->tt( 'Todos los estados', 'All statuses' ) ); ?></option>
            <option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php echo esc_html( $this->tt( 'Pendiente', 'Pending' ) ); ?></option>
            <option value="paid" <?php selected( $status_filter, 'paid' ); ?>><?php echo esc_html( $this->tt( 'Pagado', 'Paid' ) ); ?></option>
          </select>
          <button type="submit" class="button"><?php echo esc_html( $this->tt( 'Filtrar', 'Filter' ) ); ?></button>
        </form>

        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#f8fdfb;">
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Partner', 'Partner' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Reserva', 'Booking' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Fecha del tour', 'Tour date' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Cliente', 'Customer' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Comisión', 'Commission' ) ); ?></th>
            <th class="ab-th"><?php echo esc_html( $this->tt( 'Estado', 'Status' ) ); ?></th>
            <th class="ab-th"></th>
          </tr></thead>
          <tbody>
          <?php if ( empty( $payouts ) ) : ?>
            <tr><td colspan="7" class="ab-td" style="text-align:center;color:#5a7068;padding:24px;"><?php echo esc_html( $this->tt( 'Sin liquidaciones registradas todavía.', 'No payouts logged yet.' ) ); ?></td></tr>
          <?php else : foreach ( $payouts as $pp ) : ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
              <td class="ab-td"><?php echo esc_html( $pp->partner_name ?: '—' ); ?></td>
              <td class="ab-td"><?php echo esc_html( $pp->booking_ref ?: '—' ); ?></td>
              <td class="ab-td"><?php echo esc_html( $pp->tour_date ?: '—' ); ?></td>
              <td class="ab-td"><?php echo esc_html( $pp->customer_name ?: '—' ); ?></td>
              <td class="ab-td"><strong><?php echo esc_html( \AmirBooking\Core\Currency::format( (float) $pp->amount_mxn ) ); ?></strong></td>
              <td class="ab-td">
                <?php if ( $pp->status === 'paid' ) : ?>
                  <span style="color:var(--ab-teal, #1D9E75);font-weight:700;font-size:12px;">✓ <?php echo esc_html( $this->tt( 'Pagado', 'Paid' ) ); ?> <?php echo $pp->paid_at ? esc_html( '(' . date( 'd/m/Y', strtotime( $pp->paid_at ) ) . ')' ) : ''; ?></span>
                <?php else : ?>
                  <span style="color:#BA7517;font-weight:700;font-size:12px;"><?php echo esc_html( $this->tt( 'Pendiente', 'Pending' ) ); ?></span>
                <?php endif; ?>
              </td>
              <td class="ab-td">
                <?php if ( $pp->status === 'pending' ) : ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Marcar esta liquidación como pagada?', 'Mark this payout as paid?' ) ); ?>');">
                  <?php wp_nonce_field( 'amir_partner_payout_action' ); ?>
                  <input type="hidden" name="amir_action" value="mark_paid" />
                  <input type="hidden" name="id" value="<?php echo (int) $pp->id; ?>" />
                  <button type="submit" class="button button-small"><?php echo esc_html( $this->tt( 'Marcar pagado', 'Mark paid' ) ); ?></button>
                </form>
                <?php endif; ?>
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
        if ( empty( $_POST['amir_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'amir_partner_payout_action' ) ) {
            return;
        }

        global $wpdb;
        $action = sanitize_key( $_POST['amir_action'] );

        if ( $action === 'mark_paid' && ! empty( $_POST['id'] ) ) {
            $wpdb->update(
                "{$wpdb->prefix}amir_partner_payouts",
                [ 'status' => 'paid', 'paid_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $_POST['id'], 'status' => 'pending' ],
                [ '%s', '%s' ],
                [ '%d', '%s' ]
            );
            set_transient( 'amir_partner_payout_message', $this->tt( 'Liquidación marcada como pagada.', 'Payout marked as paid.' ), 30 );
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
        </style>';
    }
}
