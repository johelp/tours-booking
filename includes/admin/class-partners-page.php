<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de Partners — CRUD + stats + generación de QR y links.
 */
class PartnersPage {

    public function render(): void {
        $this->handle_actions();

        $action  = sanitize_key($_GET['action'] ?? 'list');
        $message = get_transient('amir_partner_message');
        if ($message) delete_transient('amir_partner_message');

        if ( $action === 'view' && !empty($_GET['id']) ) {
            $this->render_detail((int)$_GET['id'], $message);
        } else {
            $this->render_list($message);
        }
    }

    private function render_list( string $message = '' ): void {
        global $wpdb;
        $partners = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}amir_partners ORDER BY created_at DESC"
        ) ?? [];
        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->styles(); ?>
        <h1 style="display:flex;align-items:center;justify-content:space-between;">
          <span>🤝 Partners</span>
          <button onclick="document.getElementById('amir-new-partner-form').style.display='block';this.style.display='none';"
                  class="button button-primary">+ Nuevo partner</button>
        </h1>

        <?php if ($message) echo '<div class="notice notice-success is-dismissible"><p>'.$message.'</p></div>'; ?>

        <!-- Formulario nuevo partner -->
        <div id="amir-new-partner-form" style="display:none;background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px;margin-bottom:24px;">
          <h3 style="margin:0 0 16px;color:#1D9E75;">Nuevo partner</h3>
          <form method="post">
            <?php wp_nonce_field('amir_partner_action'); ?>
            <input type="hidden" name="amir_action" value="create_partner" />
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
              <div><?php $this->field('Nombre / Empresa','name','text','Hotel Laguna…',true); ?></div>
              <div><?php $this->field('Email','email','email','contacto@hotel.com',true); ?></div>
              <div><?php $this->field('Teléfono','phone','tel','+52 983…'); ?></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
              <div>
                <label class="ab-label">Tipo de comisión</label>
                <select name="commission_type" style="<?php echo $this->input_style(); ?> width:100%;">
                  <option value="percentage">Porcentaje (%)</option>
                  <option value="fixed">Monto fijo (MXN)</option>
                </select>
              </div>
              <div><?php $this->field('Valor','commission_value','number','15',true,'min="0" step="0.01"'); ?></div>
              <div><?php $this->field('Notas internas','notes','text'); ?></div>
            </div>
            <div style="display:flex;gap:10px;">
              <button type="submit" class="button button-primary">Crear partner y generar QR</button>
              <button type="button" onclick="document.getElementById('amir-new-partner-form').style.display='none';" class="button">Cancelar</button>
            </div>
          </form>
        </div>

        <!-- Tabla de partners -->
        <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#f8fdfb;">
            <th class="ab-th">Partner</th>
            <th class="ab-th">Comisión</th>
            <th class="ab-th">Ventas</th>
            <th class="ab-th">Comisión ganada</th>
            <th class="ab-th">Estado</th>
            <th class="ab-th">Links / QR</th>
            <th class="ab-th"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($partners as $p) :
            $stats = \AmirBooking\Partners\PartnerTracker::get_partner_stats((int)$p->id);
            ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
              <td class="ab-td">
                <strong><?php echo esc_html($p->name); ?></strong><br>
                <span style="font-size:12px;color:#5a7068;"><?php echo esc_html($p->email); ?></span>
              </td>
              <td class="ab-td">
                <?php if ($p->commission_type==='percentage') : ?>
                  <strong><?php echo number_format($p->commission_value,1); ?>%</strong>
                <?php else : ?>
                  <strong>$<?php echo number_format($p->commission_value,2); ?></strong> fijo/reserva
                <?php endif; ?>
              </td>
              <td class="ab-td">
                <?php echo $stats['total_bookings']; ?> reservas<br>
                <span style="font-size:12px;color:#5a7068;">$<?php echo number_format($stats['total_revenue'],0,'.',','); ?> MXN</span>
              </td>
              <td class="ab-td" style="font-weight:700;color:#1D9E75;">
                $<?php echo number_format($stats['commission_mxn'],2); ?> MXN
              </td>
              <td class="ab-td">
                <span style="background:<?php echo $p->active?'#e1f5ee':'#fef2f2'; ?>;
                             color:<?php echo $p->active?'#0F6E56':'#e24b4a'; ?>;
                             font-size:11px;font-weight:700;padding:3px 8px;border-radius:10px;">
                  <?php echo $p->active?'Activo':'Inactivo'; ?>
                </span>
              </td>
              <td class="ab-td">
                <a href="<?php echo admin_url('admin.php?page=amir-partners&action=view&id='.$p->id); ?>"
                   style="color:#1D9E75;font-size:12px;font-weight:600;">Ver links y QR →</a>
              </td>
              <td class="ab-td">
                <a href="<?php echo admin_url('admin.php?page=amir-partners&action=view&id='.$p->id); ?>"
                   style="color:#1D9E75;font-size:12px;">Detalle</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        </div>
        <?php
    }

    private function render_detail( int $partner_id, string $message = '' ): void {
        global $wpdb;
        $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}amir_partners WHERE id=%d",$partner_id));
        if (!$p) { echo '<div class="wrap"><p>Partner no encontrado.</p></div>'; return; }

        $stats    = \AmirBooking\Partners\PartnerTracker::get_partner_stats($partner_id);
        $urls     = \AmirBooking\Partners\PartnerTracker::get_partner_urls($partner_id);
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.name_es as tour_name FROM {$wpdb->prefix}amir_bookings b
             JOIN {$wpdb->prefix}amir_tours t ON t.id=b.tour_id
             WHERE b.partner_id=%d AND b.status IN ('confirmed','completed')
             ORDER BY b.tour_date DESC LIMIT 30",
            $partner_id
        )) ?? [];
        ?>
        <div class="wrap ab-admin-wrap">
        <?php $this->styles(); ?>

        <h1>
          <a href="<?php echo admin_url('admin.php?page=amir-partners'); ?>" style="color:#5a7068;font-weight:400;font-size:16px;">← Partners</a>
          &nbsp; <?php echo esc_html($p->name); ?>
        </h1>

        <?php if ($message) echo '<div class="notice notice-success is-dismissible"><p>'.$message.'</p></div>'; ?>

        <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;">
          <div>
            <!-- Stats -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
              <?php foreach ([
                ['Total ventas','$'.number_format($stats['total_revenue'],0,'.',',').' MXN'],
                ['Reservas',$stats['total_bookings'].' confirmadas'],
                ['Comisión','$'.number_format($stats['commission_mxn'],2).' MXN'],
              ] as [$label,$val]) : ?>
                <div style="background:#f0faf6;border:1px solid #e1f5ee;border-radius:10px;padding:14px 16px;">
                  <div style="font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;"><?php echo $label; ?></div>
                  <div style="font-size:20px;font-weight:800;color:#1a2e24;margin-top:4px;"><?php echo $val; ?></div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- Últimas reservas -->
            <div style="font-size:14px;font-weight:700;color:#1a2e24;margin-bottom:10px;">Últimas reservas referidas</div>
            <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;">
              <thead><tr style="background:#f8fdfb;">
                <th class="ab-th">Referencia</th><th class="ab-th">Tour</th><th class="ab-th">Fecha</th><th class="ab-th">Total</th><th class="ab-th">Comisión</th>
              </tr></thead>
              <tbody>
              <?php foreach ($bookings as $b) :
                $comm = \AmirBooking\Partners\PartnerTracker::calculate_commission($partner_id, (float)$b->total_mxn);
              ?>
                <tr style="border-bottom:1px solid #f5f5f5;">
                  <td class="ab-td"><a href="<?php echo admin_url('admin.php?page=amir-bookings-list&action=view&id='.$b->id); ?>" style="color:#1D9E75;"><?php echo esc_html($b->booking_ref); ?></a></td>
                  <td class="ab-td"><?php echo esc_html($b->tour_name); ?></td>
                  <td class="ab-td"><?php echo $b->tour_date; ?></td>
                  <td class="ab-td">$<?php echo number_format($b->total_mxn,0,'.',','); ?></td>
                  <td class="ab-td" style="color:#1D9E75;font-weight:700;">$<?php echo number_format($comm,2); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          </div>

          <!-- Links y QR -->
          <div>
            <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:16px;">
              <div style="font-size:14px;font-weight:700;color:#1D9E75;margin-bottom:14px;">🔗 Links de tracking</div>

              <div style="margin-bottom:16px;">
                <div style="font-size:12px;font-weight:700;color:#5a7068;margin-bottom:6px;text-transform:uppercase;">Link al catálogo completo</div>
                <div style="background:#f8fdfb;border:1px solid #e1f5ee;border-radius:6px;padding:8px 10px;font-size:11px;word-break:break-all;color:#1a2e24;margin-bottom:8px;">
                  <?php echo esc_html($urls['url_all_tours'] ?? ''); ?>
                </div>
                <?php if (!empty($urls['qr_all_tours'])) : ?>
                  <div style="text-align:center;margin-bottom:8px;">
                    <img src="<?php echo esc_url($urls['qr_all_tours']); ?>" alt="QR Catálogo" style="width:120px;height:120px;border:1px solid #e1f5ee;padding:6px;border-radius:6px;" />
                    <div style="font-size:11px;color:#5a7068;margin-top:4px;">QR → Catálogo de tours</div>
                  </div>
                  <a href="<?php echo esc_url($urls['qr_all_tours']); ?>" download
                     class="button" style="width:100%;text-align:center;box-sizing:border-box;display:block;">⬇ Descargar QR (catálogo)</a>
                <?php endif; ?>
              </div>

              <?php if (!empty($urls['tours'])) :
                foreach ($urls['tours'] as $t) : ?>
                  <div style="border-top:1px solid #e1f5ee;padding-top:12px;margin-top:12px;">
                    <div style="font-size:12px;font-weight:700;color:#5a7068;margin-bottom:4px;"><?php echo esc_html($t['name']); ?></div>
                    <div style="font-size:11px;word-break:break-all;color:#1a2e24;margin-bottom:6px;"><?php echo esc_html($t['url']); ?></div>
                    <?php if ($t['qr']) : ?>
                      <a href="<?php echo esc_url($t['qr']); ?>" download class="button" style="font-size:12px;">⬇ QR tour</a>
                    <?php endif; ?>
                  </div>
                <?php endforeach;
              endif; ?>

              <!-- Regenerar QR -->
              <form method="post" style="margin-top:14px;border-top:1px solid #e1f5ee;padding-top:14px;">
                <?php wp_nonce_field('amir_partner_action'); ?>
                <input type="hidden" name="amir_action" value="regen_qr" />
                <input type="hidden" name="partner_id" value="<?php echo $p->id; ?>" />
                <button type="submit" class="button" style="width:100%;">🔄 Regenerar QR</button>
              </form>
            </div>

            <!-- Datos del partner -->
            <div style="background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:16px;margin-top:14px;">
              <div style="font-size:14px;font-weight:700;color:#1D9E75;margin-bottom:10px;">Datos del partner</div>
              <div style="font-size:12px;color:#5a7068;line-height:2;">
                <div>Email: <strong style="color:#1a2e24;"><?php echo esc_html($p->email); ?></strong></div>
                <div>Tel: <strong style="color:#1a2e24;"><?php echo esc_html($p->phone??'—'); ?></strong></div>
                <div>Token: <code style="font-size:11px;"><?php echo esc_html($p->tracking_token); ?></code></div>
                <div>Comisión: <strong style="color:#1D9E75;">
                  <?php echo $p->commission_type==='percentage'
                    ? number_format($p->commission_value,1).'%'
                    : '$'.number_format($p->commission_value,2).' MXN fijo'; ?>
                </strong></div>
              </div>
            </div>
          </div>
        </div>
        </div>
        <?php
    }

    private function handle_actions(): void {
        if ( empty($_POST['amir_action']) || !wp_verify_nonce($_POST['_wpnonce']??'','amir_partner_action') ) return;

        $action = sanitize_key($_POST['amir_action']);

        if ( $action === 'create_partner' ) {
            $result = \AmirBooking\Partners\PartnerTracker::create_partner([
                'name'             => sanitize_text_field($_POST['name'] ?? ''),
                'email'            => sanitize_email($_POST['email'] ?? ''),
                'phone'            => sanitize_text_field($_POST['phone'] ?? ''),
                'commission_type'  => sanitize_key($_POST['commission_type'] ?? 'percentage'),
                'commission_value' => (float)($_POST['commission_value'] ?? 0),
                'notes'            => sanitize_text_field($_POST['notes'] ?? ''),
            ]);
            $msg = is_wp_error($result) ? 'Error: '.$result->get_error_message() : 'Partner creado correctamente.';
            set_transient('amir_partner_message', $msg, 30);
            wp_redirect(admin_url('admin.php?page=amir-partners'));
            exit;
        }

        if ( $action === 'regen_qr' ) {
            $partner_id = (int)($_POST['partner_id'] ?? 0);
            global $wpdb;
            $token = $wpdb->get_var($wpdb->prepare("SELECT tracking_token FROM {$wpdb->prefix}amir_partners WHERE id=%d",$partner_id));
            if ($token) {
                // Borrar archivos cacheados para forzar regeneración con api.qrserver.com
                $upload_dir = wp_upload_dir();
                $qr_dir     = $upload_dir['basedir'] . '/amir-booking/partners/';
                foreach ( glob( $qr_dir . 'partner-' . $partner_id . '-*.png' ) as $old_file ) {
                    @unlink( $old_file );
                }
                \AmirBooking\Partners\PartnerTracker::generate_partner_qr($partner_id, $token, 0);
                set_transient('amir_partner_message','QR regenerado.',30);
            }
            wp_redirect(admin_url('admin.php?page=amir-partners&action=view&id='.$partner_id));
            exit;
        }
    }

    private function field(string $label, string $name, string $type='text', string $ph='', bool $req=false, string $extra=''): void {
        echo '<label class="ab-label">'.$label.($req?' <span style="color:#e24b4a;">*</span>':'').'</label>';
        echo '<input type="'.$type.'" name="'.$name.'" placeholder="'.esc_attr($ph).'" '.($req?'required':'').' '.$extra
            .' style="'.$this->input_style().' width:100%;" />';
    }

    private function input_style(): string {
        return 'border:1px solid #c3d9d0;border-radius:6px;padding:7px 10px;font-size:13px;';
    }

    private function styles(): void {
        echo '<style>
        .ab-admin-wrap{max-width:1200px}
        .ab-th{font-size:11px;font-weight:700;color:#5a7068;text-transform:uppercase;letter-spacing:.4px;padding:10px 12px;text-align:left}
        .ab-td{font-size:13px;padding:10px 12px;vertical-align:middle}
        .ab-label{display:block;font-size:12px;font-weight:700;color:#1a2e24;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
        </style>';
    }
}

// ─────────────────────────────────────────────────────────────────────────────

