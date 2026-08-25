<?php
namespace AmirBooking\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Página de configuración general del plugin.
 */
class SettingsPage {

    /** @var array|null Resultado de la última importación de tours, para render_import_result(). */
    private ?array $import_result = null;

    /**
     * Idioma de ESTA pantalla (Importar tours) — sigue el idioma de admin del
     * usuario logueado (`get_user_locale()`, es lo mismo que ya usa WordPress
     * para decidir en qué idioma se ve su propio wp-admin, incluido cuando el
     * operador elige un idioma de admin distinto al del sitio público), no el
     * idioma del contenido que se importa. Reportado por el cliente
     * 2026-08-22: esta pantalla siempre se veía en español sin importar el
     * idioma configurado — el resto del admin tiene el mismo problema de
     * fondo (casi ningún string usa `__()`, y ni siquiera existe un
     * `amir-booking-en_US.mo`, mismo bug de raíz que ya se corrigió para los
     * emails en v5.0.0), pero corregirlo TODO es una pasada grande aparte —
     * acá se resuelve solo esta pantalla, con el mismo criterio de pares
     * es/en en código (sin depender de gettext) que ya usa `EmailTexts`.
     */
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

        // Manejar sincronización masiva de tours
        if ( isset($_POST['amir_sync_nonce']) && wp_verify_nonce($_POST['amir_sync_nonce'],'amir_sync_tours') ) {
            $count = $this->sync_all_tours();
            echo '<div class="notice notice-success is-dismissible"><p>✓ ' . esc_html( sprintf( $this->tt( '%d tour(s) sincronizados correctamente con la base de datos.', '%d tour(s) synced successfully with the database.' ), $count ) ) . '</p></div>';
        }

        if ( isset($_POST['amir_settings_nonce']) && wp_verify_nonce($_POST['amir_settings_nonce'],'amir_settings') ) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $this->tt( 'Configuración guardada.', 'Settings saved.' ) ) . '</p></div>';
        }

        // Desconectar Google Calendar
        if ( isset($_POST['amir_google_disconnect_nonce']) && wp_verify_nonce($_POST['amir_google_disconnect_nonce'],'amir_google_disconnect') ) {
            \AmirBooking\Core\GoogleCalendarSync::disconnect();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $this->tt( 'Google Calendar desconectado.', 'Google Calendar disconnected.' ) ) . '</p></div>';
        }

        // Resultado del intento de conexión (viene del redirect del callback OAuth)
        if ( isset( $_GET['google_connected'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✓ ' . esc_html( $this->tt( 'Google Calendar conectado correctamente.', 'Google Calendar connected successfully.' ) ) . '</p></div>';
        }
        if ( isset( $_GET['google_error'] ) ) {
            $err = get_transient( 'amir_google_last_error' ) ?: $this->tt( 'Error desconocido al conectar con Google.', 'Unknown error connecting to Google.' );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $this->tt( 'No se pudo conectar Google Calendar: ', 'Could not connect Google Calendar: ' ) ) . esc_html( $err ) . '</p></div>';
        }

        if ( isset($_POST['amir_import_tours_nonce']) && wp_verify_nonce($_POST['amir_import_tours_nonce'],'amir_import_tours') ) {
            $this->import_result = $this->handle_import_tours();
        }

        if ( isset($_POST['amir_import_demo_nonce']) && wp_verify_nonce($_POST['amir_import_demo_nonce'],'amir_import_demo') ) {
            $this->import_result = $this->handle_import_demo_tours();
        }
        ?>
        <div class="wrap ab-admin-wrap">
        <style>
        .ab-admin-wrap{max-width:860px}
        .ab-settings-section{background:#fff;border:1px solid #e1f5ee;border-radius:10px;padding:20px 22px;margin-bottom:20px}
        .ab-settings-section h3{font-size:15px;font-weight:700;color:#1D9E75;margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid #e1f5ee}
        .ab-field{margin-bottom:14px}
        .ab-field label{display:block;font-size:13px;font-weight:600;color:#1a2e24;margin-bottom:5px}
        .ab-field input,.ab-field select,.ab-field textarea{border:1px solid #c3d9d0;border-radius:6px;padding:8px 11px;font-size:13px;width:100%;max-width:420px;box-sizing:border-box;font-family:inherit}
        .ab-field input:focus,.ab-field select:focus,.ab-field textarea:focus{outline:none;border-color:#1D9E75;box-shadow:0 0 0 2px rgba(29,158,117,.15)}
        .ab-hint{font-size:11px;color:#5a7068;margin-top:3px}
        .ab-field-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        </style>

        <h1>⚙ <?php echo esc_html( $this->tt( 'Configuración', 'Settings' ) ); ?></h1>
        <p style="color:#5a7068;font-size:13px;max-width:70ch;margin-top:-8px;">
          <?php echo wp_kses_post( sprintf(
            $this->tt( 'Configuración operativa — pasarelas de pago, moneda, idiomas, módulos. Colores, marca y textos de cara al cliente viven en %s.', 'Operational settings — payment gateways, currency, languages, modules. Colors, branding, and customer-facing texts live in %s.' ),
            '<a href="' . esc_url( admin_url('admin.php?page=amir-personalization') ) . '">🎨 ' . esc_html( $this->tt('Personalización','Personalization') ) . '</a>'
          ) ); ?>
        </p>
        <form method="post">
          <?php wp_nonce_field('amir_settings','amir_settings_nonce'); ?>

          <!-- Pasarela de pago activa -->
          <?php
          $stripe_mode      = get_option( 'amir_stripe_mode', 'test' );
          $stripe_key       = get_option( "amir_stripe_sk_{$stripe_mode}", '' );
          $stripe_configured = $stripe_key !== '';

          $mp_mode      = get_option( 'amir_mp_mode', 'test' );
          $mp_token     = get_option( "amir_mp_access_token_{$mp_mode}", '' );
          $mp_configured = $mp_token !== '';

          $default_gateway = get_option( 'amir_default_gateway', 'stripe' );
          $status_pill = fn( bool $ok ) => $ok
              ? '<span style="color:#1D9E75;font-weight:700;">✓ ' . esc_html( $this->tt('Configurado','Configured') ) . '</span>'
              : '<span style="color:#BA7517;font-weight:700;">⚠ ' . esc_html( $this->tt('Sin credenciales cargadas','No credentials loaded') ) . '</span>';
          ?>
          <?php
          // Punto de extensión para plugins satélite de pasarela (ej.
          // redsys-for-tourflow) — cada uno agrega su propia opción acá vía
          // add_filter('amir_payment_gateway_options', ...) sin tocar este
          // archivo. Formato: ['id' => 'Nombre a mostrar'].
          $gateway_options = apply_filters( 'amir_payment_gateway_options', [
              'stripe'      => 'Stripe',
              'mercadopago' => 'Mercado Pago',
          ] );
          ?>
          <div class="ab-settings-section">
            <h3>🔀 <?php echo esc_html( $this->tt( 'Pasarela de pago', 'Payment gateway' ) ); ?></h3>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Pasarela activa', 'Active gateway' ) ); ?></label>
              <select name="amir_default_gateway">
                <?php foreach ( $gateway_options as $gw_id => $gw_label ) : ?>
                <option value="<?php echo esc_attr( $gw_id ); ?>" <?php selected( $default_gateway, $gw_id ); ?>><?php echo esc_html( $gw_label ); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="ab-hint"><?php echo esc_html( $this->tt(
                'Con qué pasarela se cobran las reservas nuevas. La moneda configurada más abajo (sección Moneda) determina cuál conviene: ⚠️ para Argentina (ARS) usar Mercado Pago — Stripe no liquida bien en pesos argentinos.',
                "Which gateway charges new bookings. The currency configured below (Currency section) determines which one suits you: ⚠️ for Argentina (ARS) use Mercado Pago — Stripe doesn't settle Argentine pesos well."
              ) ); ?></p>
            </div>
            <div class="ab-field-row" style="margin-top:10px;">
              <div class="ab-field" style="margin-bottom:0;">
                <label style="text-transform:none;font-weight:400;color:#5a7068;">💳 <?php echo esc_html( sprintf( $this->tt( 'Stripe (modo %s)', 'Stripe (%s mode)' ), $stripe_mode ) ); ?></label>
                <div style="font-size:13px;"><?php echo $status_pill( $stripe_configured ); ?></div>
              </div>
              <div class="ab-field" style="margin-bottom:0;">
                <label style="text-transform:none;font-weight:400;color:#5a7068;">💙 <?php echo esc_html( sprintf( $this->tt( 'Mercado Pago (modo %s)', 'Mercado Pago (%s mode)' ), $mp_mode ) ); ?></label>
                <div style="font-size:13px;"><?php echo $status_pill( $mp_configured ); ?></div>
              </div>
            </div>
            <?php if ( $default_gateway === 'stripe' && ! $stripe_configured ) : ?>
              <p class="ab-hint" style="color:#BA7517;margin-top:10px;">⚠ <?php echo esc_html( sprintf( $this->tt( 'La pasarela activa es Stripe pero no tiene credenciales cargadas para el modo %s — las reservas nuevas van a fallar al cobrar.', 'The active gateway is Stripe but it has no credentials loaded for %s mode — new bookings will fail to charge.' ), $stripe_mode ) ); ?></p>
            <?php elseif ( $default_gateway === 'mercadopago' && ! $mp_configured ) : ?>
              <p class="ab-hint" style="color:#BA7517;margin-top:10px;">⚠ <?php echo esc_html( sprintf( $this->tt( 'La pasarela activa es Mercado Pago pero no tiene credenciales cargadas para el modo %s — las reservas nuevas van a fallar al cobrar.', 'The active gateway is Mercado Pago but it has no credentials loaded for %s mode — new bookings will fail to charge.' ), $mp_mode ) ); ?></p>
            <?php endif; ?>
          </div>

          <!-- Stripe -->
          <div class="ab-settings-section">
            <h3>💳 Stripe</h3>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Modo', 'Mode' ) ); ?></label>
              <select name="amir_stripe_mode">
                <option value="test" <?php selected(get_option('amir_stripe_mode','test'),'test'); ?>><?php echo esc_html( $this->tt( 'Test (pruebas)', 'Test' ) ); ?></option>
                <option value="live" <?php selected(get_option('amir_stripe_mode','test'),'live'); ?>><?php echo esc_html( $this->tt( 'Live (producción)', 'Live (production)' ) ); ?></option>
              </select>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Publishable Key TEST</label>
                <input type="text" name="amir_stripe_pk_test" value="<?php echo esc_attr(get_option('amir_stripe_pk_test','')); ?>" placeholder="pk_test_…" />
              </div>
              <div class="ab-field">
                <label>Secret Key TEST</label>
                <input type="password" name="amir_stripe_sk_test" value="<?php echo esc_attr(get_option('amir_stripe_sk_test','')); ?>" placeholder="sk_test_…" />
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Publishable Key LIVE</label>
                <input type="text" name="amir_stripe_pk_live" value="<?php echo esc_attr(get_option('amir_stripe_pk_live','')); ?>" placeholder="pk_live_…" />
              </div>
              <div class="ab-field">
                <label>Secret Key LIVE</label>
                <input type="password" name="amir_stripe_sk_live" value="<?php echo esc_attr(get_option('amir_stripe_sk_live','')); ?>" placeholder="sk_live_…" />
              </div>
            </div>
            <div class="ab-field">
              <label>Webhook Secret</label>
              <input type="password" name="amir_stripe_webhook_secret" value="<?php echo esc_attr(get_option('amir_stripe_webhook_secret','')); ?>" placeholder="whsec_…" />
              <p class="ab-hint"><?php echo esc_html( $this->tt( 'URL del webhook en Stripe:', 'Webhook URL in Stripe:' ) ); ?> <code><?php echo rest_url('amir/v1/bookings/stripe-webhook'); ?></code></p>
            </div>
          </div>

          <!-- Mercado Pago -->
          <div class="ab-settings-section">
            <h3>💙 Mercado Pago</h3>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Modo', 'Mode' ) ); ?></label>
              <select name="amir_mp_mode">
                <option value="test" <?php selected(get_option('amir_mp_mode','test'),'test'); ?>><?php echo esc_html( $this->tt( 'Test (pruebas)', 'Test' ) ); ?></option>
                <option value="live" <?php selected(get_option('amir_mp_mode','test'),'live'); ?>><?php echo esc_html( $this->tt( 'Live (producción)', 'Live (production)' ) ); ?></option>
              </select>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Access Token TEST</label>
                <input type="password" name="amir_mp_access_token_test" value="<?php echo esc_attr(get_option('amir_mp_access_token_test','')); ?>" placeholder="TEST-…" />
              </div>
              <div class="ab-field">
                <label>Access Token LIVE</label>
                <input type="password" name="amir_mp_access_token_live" value="<?php echo esc_attr(get_option('amir_mp_access_token_live','')); ?>" placeholder="APP_USR-…" />
              </div>
            </div>
            <div class="ab-field">
              <label>Webhook Secret</label>
              <input type="password" name="amir_mp_webhook_secret" value="<?php echo esc_attr(get_option('amir_mp_webhook_secret','')); ?>" placeholder="<?php echo esc_attr( $this->tt( 'Clave secreta de la integración…', 'Integration secret key…' ) ); ?>" />
              <p class="ab-hint"><?php echo esc_html( $this->tt( 'URL del webhook en Mercado Pago:', 'Webhook URL in Mercado Pago:' ) ); ?> <code><?php echo rest_url('amir/v1/bookings/mercadopago-webhook'); ?></code> — <?php echo esc_html( $this->tt( 'la clave secreta la genera MP en Tus integraciones → Webhooks.', 'the secret key is generated by MP in Your integrations → Webhooks.' ) ); ?></p>
            </div>
          </div>

          <!-- General -->
          <div class="ab-settings-section">
            <h3>⚙ <?php echo esc_html( $this->tt( 'General', 'General' ) ); ?></h3>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Email del operador (alertas)', 'Operator email (alerts)' ) ); ?></label>
                <input type="email" name="amir_admin_email" value="<?php echo esc_attr(get_option('amir_admin_email',get_option('admin_email'))); ?>" />
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'WhatsApp de contacto (solo números)', 'Contact WhatsApp (numbers only)' ) ); ?></label>
                <input type="text" name="amir_wa_phone" value="<?php echo esc_attr(get_option('amir_wa_phone','')); ?>" placeholder="5219831649541" />
              </div>
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Minutos para expirar reserva pending', 'Minutes to expire a pending booking' ) ); ?></label>
                <input type="number" name="amir_pending_expire_mins" value="<?php echo esc_attr(get_option('amir_pending_expire_mins','15')); ?>" min="5" max="60" />
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Días post-tour para email de reseña', 'Days after tour for review email' ) ); ?></label>
                <input type="number" name="amir_review_delay_days" value="<?php echo esc_attr(get_option('amir_review_delay_days','1')); ?>" min="1" max="7" />
              </div>
            </div>
          </div>

          <!-- Moneda -->
          <div class="ab-settings-section">
            <h3>💰 <?php echo esc_html( $this->tt( 'Moneda', 'Currency' ) ); ?></h3>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              <?php echo esc_html( $this->tt(
                'Moneda en la que se cobran los tours (Stripe cobra en esta moneda directamente). Depende del país donde opera el negocio.',
                'The currency tours are charged in (Stripe charges in this currency directly). Depends on the country the business operates in.'
              ) ); ?>
            </p>
            <?php
            $currency     = get_option('amir_currency','MXN');
            $currency_std = in_array($currency, \AmirBooking\Core\Currency::SUPPORTED, true) ? $currency : '';
            ?>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Moneda', 'Currency' ) ); ?></label>
                <select name="amir_currency" id="amir-currency-select">
                  <option value="MXN" <?php selected($currency_std,'MXN'); ?>><?php echo esc_html( sprintf( $this->tt( 'MXN — Peso mexicano', 'MXN — Mexican peso' ) ) ); ?></option>
                  <option value="ARS" <?php selected($currency_std,'ARS'); ?>><?php echo esc_html( $this->tt( 'ARS — Peso argentino', 'ARS — Argentine peso' ) ); ?></option>
                  <option value="USD" <?php selected($currency_std,'USD'); ?>><?php echo esc_html( $this->tt( 'USD — Dólar estadounidense', 'USD — US dollar' ) ); ?></option>
                  <option value="EUR" <?php selected($currency_std,'EUR'); ?>>EUR — Euro</option>
                  <option value="" <?php selected($currency_std,''); ?>><?php echo esc_html( $this->tt( 'Otra (código ISO 4217)…', 'Other (ISO 4217 code)…' ) ); ?></option>
                </select>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Código ISO (si elegiste "Otra")', 'ISO code (if you chose "Other")' ) ); ?></label>
                <input type="text" name="amir_currency_custom" id="amir-currency-custom"
                       value="<?php echo $currency_std === '' ? esc_attr($currency) : ''; ?>"
                       maxlength="3" placeholder="<?php echo esc_attr( $this->tt( 'Ej: COP, CLP, GBP…', 'E.g.: COP, CLP, GBP…' ) ); ?>"
                       style="text-transform:uppercase;max-width:120px;" />
                <p class="ab-hint"><?php echo esc_html( $this->tt( 'Cualquier código de 3 letras es válido — solo Stripe determina si realmente puede cobrar en esa moneda.', 'Any 3-letter code is valid — only Stripe determines whether it can actually charge in that currency.' ) ); ?></p>
              </div>
            </div>
          </div>

          <!-- Idiomas -->
          <div class="ab-settings-section">
            <h3>🌐 <?php echo esc_html( $this->tt( 'Idiomas', 'Languages' ) ); ?></h3>
            <?php if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) : ?>
            <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
              <?php echo wp_kses_post( $this->tt(
                'Idiomas activos para el contenido de los tours y los textos del sitio (emails, voucher, widget de reserva). "Español" es el idioma base y no se puede quitar. Para agregar uno nuevo, escribí su código de 2 letras (ISO 639-1) — aparece como pestaña nueva en el editor de cada tour. Si existe traducción (<code>.po</code>/<code>.mo</code>) para ese código en <code>/languages</code>, los textos fijos también salen traducidos; si no, se muestran en español como respaldo.',
                'Active languages for tour content and site texts (emails, voucher, booking widget). "Spanish" is the base language and can\'t be removed. To add a new one, type its 2-letter code (ISO 639-1) — it appears as a new tab in each tour\'s editor. If a translation (<code>.po</code>/<code>.mo</code>) exists for that code in <code>/languages</code>, the fixed texts are also translated; if not, they show in Spanish as a fallback.'
              ) ); ?>
            </p>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Códigos activos (separados por coma)', 'Active codes (comma-separated)' ) ); ?></label>
              <input type="text" name="amir_active_languages_csv"
                     value="<?php echo esc_attr( implode( ', ', array_map('strtoupper', \AmirBooking\Core\Languages::active()) ) ); ?>"
                     placeholder="ES, EN, IT, FR" style="max-width:320px;" />
              <p class="ab-hint"><?php echo esc_html( $this->tt(
                'Ejemplos ya traducidos por el plugin: EN (inglés), IT (italiano), FR (francés), PT (portugués). Cualquier otro código de 2 letras queda activo y disponible en el editor, aunque sin traducción de los textos fijos hasta que se agregue el .po/.mo correspondiente.',
                'Examples already translated by the plugin: EN (English), IT (Italian), FR (French), PT (Portuguese). Any other 2-letter code becomes active and available in the editor, though without fixed-text translation until the matching .po/.mo is added.'
              ) ); ?></p>
            </div>
            <?php else : ?>
            <p style="font-size:12px;color:#5a7068;margin:0;">
              <?php echo esc_html( $this->tt( 'Esta instalación incluye Español e Inglés. Activar idiomas adicionales es parte de TourFlow Pro.', 'This installation includes Spanish and English. Enabling additional languages is part of TourFlow Pro.' ) ); ?>
            </p>
            <?php endif; ?>
          </div>

          <!-- Tipo de cambio de referencia -->
          <div class="ab-settings-section">
            <h3>💱 <?php echo esc_html( $this->tt( 'Tipo de cambio de referencia (USD)', 'Reference exchange rate (USD)' ) ); ?></h3>
            <?php if ( $currency === 'USD' ) : ?>
              <p class="ab-hint" style="margin:0;"><?php echo esc_html( $this->tt( 'Tu moneda ya es USD — no aplica conversión de referencia.', 'Your currency is already USD — no reference conversion applies.' ) ); ?></p>
            <?php else : ?>
              <p style="font-size:12px;color:#5a7068;margin:0 0 12px;">
                <?php echo esc_html( sprintf( $this->tt( 'Se usa para mostrar también el precio en USD como referencia a turistas extranjeros (no afecta el cobro real, que siempre es en %s).', 'Used to also show the price in USD as a reference for foreign tourists (does not affect the real charge, which is always in %s).' ), $currency ) ); ?>
              </p>
              <div class="ab-field">
                <label><?php echo esc_html( $this->tt( 'Modo', 'Mode' ) ); ?></label>
                <select name="amir_usd_rate_mode">
                  <option value="auto" <?php selected(get_option('amir_usd_rate_mode','auto'),'auto'); ?>><?php echo esc_html( $this->tt( 'Automático (API ExchangeRate, actualiza c/4h)', 'Automatic (ExchangeRate API, updates every 4h)' ) ); ?></option>
                  <option value="manual" <?php selected(get_option('amir_usd_rate_mode','auto'),'manual'); ?>><?php echo esc_html( $this->tt( 'Manual (valor fijo)', 'Manual (fixed value)' ) ); ?></option>
                </select>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Valor manual USD → %s', 'Manual value USD → %s' ), $currency ) ); ?></label>
                <input type="number" name="amir_usd_rate_manual" value="<?php echo esc_attr(get_option('amir_usd_rate_manual','17')); ?>" min="0" step="0.0001" style="max-width:140px;" />
                <p class="ab-hint"><?php echo esc_html( sprintf( $this->tt( 'Ej: 17 = $1 USD = %s17 %s', 'E.g.: 17 = $1 USD = %s17 %s' ), \AmirBooking\Core\Currency::symbol($currency), $currency ) ); ?></p>
              </div>
            <?php endif; ?>
          </div>

          <!-- Integraciones -->
          <div class="ab-settings-section">
            <h3>🔗 <?php echo esc_html( $this->tt( 'URLs de reseñas', 'Review URLs' ) ); ?></h3>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'URL de reseñas en Google (Google Place Review Link)', 'Google review URL (Google Place Review Link)' ) ); ?></label>
              <input type="url" name="amir_google_review_url" value="<?php echo esc_attr(get_option('amir_google_review_url','')); ?>" placeholder="https://g.page/r/…/review" />
            </div>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'URL de reseñas en TripAdvisor', 'TripAdvisor review URL' ) ); ?></label>
              <input type="url" name="amir_tripadvisor_review_url" value="<?php echo esc_attr(get_option('amir_tripadvisor_review_url','')); ?>" placeholder="https://www.tripadvisor.com/…" />
            </div>
          </div>

          <?php if ( AMIR_EDITION === 'pro_max' ) : $this->render_google_calendar_section(); endif; ?>

          <?php if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) : ?>
          <!-- Marketing: píxeles — Pro y superior -->
          <div class="ab-settings-section">
            <h3>📣 <?php echo esc_html( $this->tt( 'Marketing (píxeles)', 'Marketing (pixels)' ) ); ?></h3>
            <p class="ab-hint" style="margin:0 0 14px;">
              <?php echo wp_kses_post( $this->tt(
                'Se cargan solo si completás al menos un ID acá abajo — sin nada configurado, no se agrega ningún script de terceros al sitio. Eventos que dispara el widget de reserva: <strong>ver tour</strong> (ViewContent/view_item), <strong>iniciar reserva</strong> (InitiateCheckout/begin_checkout) y <strong>reserva confirmada</strong> (Purchase/purchase, con el monto real cobrado).',
                "Only load if you fill in at least one ID below — with nothing configured, no third-party script is added to the site. Events triggered by the booking widget: <strong>view tour</strong> (ViewContent/view_item), <strong>start booking</strong> (InitiateCheckout/begin_checkout), and <strong>booking confirmed</strong> (Purchase/purchase, with the real amount charged)."
              ) ); ?>
            </p>
            <div class="ab-field">
              <label>Meta Pixel ID</label>
              <input type="text" name="amir_meta_pixel_id" value="<?php echo esc_attr( get_option( 'amir_meta_pixel_id', '' ) ); ?>" placeholder="123456789012345" />
            </div>
            <div class="ab-field-row">
              <div class="ab-field">
                <label>Google Ads — Conversion ID</label>
                <input type="text" name="amir_gads_conversion_id" value="<?php echo esc_attr( get_option( 'amir_gads_conversion_id', '' ) ); ?>" placeholder="AW-123456789" />
              </div>
              <div class="ab-field">
                <label>Google Ads — Conversion Label</label>
                <input type="text" name="amir_gads_conversion_label" value="<?php echo esc_attr( get_option( 'amir_gads_conversion_label', '' ) ); ?>" placeholder="AbCdEfGhIjKlMnOp" />
              </div>
            </div>
            <div class="ab-field">
              <label>GA4 — Measurement ID</label>
              <input type="text" name="amir_ga4_id" value="<?php echo esc_attr( get_option( 'amir_ga4_id', '' ) ); ?>" placeholder="G-XXXXXXXXXX" />
              <p class="ab-hint"><?php echo esc_html( $this->tt( 'Opcional, aparte de Google Ads — si solo querés medir conversión de campañas, alcanza con el Conversion ID/Label de arriba.', 'Optional, separate from Google Ads — if you only want to measure campaign conversion, the Conversion ID/Label above is enough.' ) ); ?></p>
            </div>
          </div>

          <!-- Consentimiento de cookies — gatea la carga de los píxeles de arriba -->
          <div class="ab-settings-section">
            <h3>🍪 <?php echo esc_html( $this->tt( 'Consentimiento de cookies', 'Cookie consent' ) ); ?></h3>
            <p class="ab-hint" style="margin:0 0 14px;">
              <?php echo esc_html( $this->tt(
                'Banner simple Aceptar/Rechazar que se muestra antes de cargar Meta Pixel/Google Ads/GA4 — sin categorías (el plugin no tiene cookies propias que valga la pena separar). Solo aparece si hay al menos un ID de Marketing configurado arriba; si el visitante ya decidió, no vuelve a preguntar (180 días).',
                "Simple Accept/Reject banner shown before loading Meta Pixel/Google Ads/GA4 — no categories (the plugin has no cookies of its own worth splitting out). Only appears if at least one Marketing ID is configured above; if the visitor already decided, it won't ask again (180 days)."
              ) ); ?>
            </p>
            <div class="ab-field-row">
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Texto del banner (%s)', 'Banner text (%s)' ), 'Español' ) ); ?></label>
                <textarea name="amir_cookie_banner_text_es" rows="2"
                          placeholder="Usamos cookies de analítica y publicidad para mejorar tu experiencia."><?php
                  echo esc_textarea( get_option( 'amir_cookie_banner_text_es', '' ) );
                ?></textarea>
              </div>
              <div class="ab-field">
                <label><?php echo esc_html( sprintf( $this->tt( 'Texto del banner (%s)', 'Banner text (%s)' ), 'English' ) ); ?></label>
                <textarea name="amir_cookie_banner_text_en" rows="2"
                          placeholder="We use analytics and advertising cookies to improve your experience."><?php
                  echo esc_textarea( get_option( 'amir_cookie_banner_text_en', '' ) );
                ?></textarea>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- CORS: frontend headless externo -->
          <div class="ab-settings-section">
            <h3>🌐 CORS (<?php echo esc_html( $this->tt( 'frontend externo', 'external frontend' ) ); ?>)</h3>
            <p class="ab-hint" style="margin:0 0 14px;">
              <?php echo wp_kses_post( $this->tt(
                'Si un frontend propio (ej. una app React en otro dominio, no WordPress) va a consumir la API pública del plugin para armar su propio flujo de reserva y cobro, su dominio tiene que estar cargado acá — si no, el navegador bloquea las llamadas que crean/cotizan/pagan una reserva. Solo afecta a las rutas <code>/wp-json/amir/v1/*</code>, nunca a <code>wp-admin</code>.',
                "If your own frontend (e.g. a React app on another domain, not WordPress) is going to consume the plugin's public API to build its own booking/payment flow, its domain needs to be loaded here — otherwise the browser blocks the calls that create/quote/pay a booking. Only affects the <code>/wp-json/amir/v1/*</code> routes, never <code>wp-admin</code>."
              ) ); ?>
            </p>
            <div class="ab-field">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;font-weight:400;">
                <input type="checkbox" name="amir_cors_enabled" value="1"
                       <?php checked( get_option( 'amir_cors_enabled', '1' ), '1' ); ?>
                       style="accent-color:#1D9E75;width:auto;" />
                <?php echo esc_html( $this->tt( 'Habilitar CORS para los dominios de abajo', 'Enable CORS for the domains below' ) ); ?>
              </label>
            </div>
            <div class="ab-field">
              <label><?php echo esc_html( $this->tt( 'Dominios permitidos (uno por línea)', 'Allowed domains (one per line)' ) ); ?></label>
              <textarea name="amir_cors_allowed_origins" rows="3"
                        placeholder="https://mi-app.vercel.app"><?php
                echo esc_textarea( implode( "\n", \AmirBooking\Core\Cors::allowed_origins() ) );
              ?></textarea>
              <p class="ab-hint"><?php echo wp_kses_post( $this->tt(
                'Dominio exacto, con <code>https://</code> y sin barra final ni ruta — un subdominio distinto (ej. <code>staging.</code> vs <code>www.</code>) cuenta como otro origen y necesita su propia línea.',
                'Exact domain, with <code>https://</code> and no trailing slash or path — a different subdomain (e.g. <code>staging.</code> vs <code>www.</code>) counts as another origin and needs its own line.'
              ) ); ?></p>
            </div>
          </div>

          <!-- Módulos -->
          <div class="ab-settings-section">
            <h3>🧩 <?php echo esc_html( $this->tt( 'Módulos', 'Modules' ) ); ?></h3>
            <p class="ab-hint" style="margin-top:-8px;margin-bottom:14px;"><?php echo esc_html( $this->tt( 'Apagá lo que esta instalación no use — se oculta del menú (los datos que ya existan no se borran, por si lo volvés a activar después).', "Turn off what this installation doesn't use — it hides from the menu (any existing data isn't deleted, in case you turn it back on later)." ) ); ?></p>
            <?php
            $modules = [
                'amir_module_partners' => [ 'label' => '🎯 ' . $this->tt('Partners (afiliados)','Partners (affiliates)'), 'hint' => $this->tt('Oculta "Partners" del menú.','Hides "Partners" from the menu.') ],
            ];
            if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
                $modules = [
                    'amir_module_marketplace' => [ 'label' => '🤝 ' . $this->tt('Marketplace de proveedores','Provider marketplace'), 'hint' => $this->tt('Oculta "Proveedores" y "Liquidación" del menú.','Hides "Providers" and "Payouts" from the menu.') ],
                    'amir_module_wishlist'    => [ 'label' => '📋 ' . $this->tt('Lista de interés','Waitlist'),            'hint' => $this->tt('Oculta "Lista de interés" del menú y el checkbox correspondiente en el editor de tours.','Hides "Waitlist" from the menu and the matching checkbox in the tour editor.') ],
                ] + $modules;
            }
            foreach ( $modules as $opt => $m ) : ?>
              <div class="ab-field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;font-weight:400;">
                  <input type="checkbox" name="<?php echo esc_attr($opt); ?>" value="1"
                         <?php checked( get_option($opt, '1'), '1' ); ?>
                         style="accent-color:#1D9E75;width:auto;" />
                  <?php echo esc_html($m['label']); ?>
                </label>
                <p class="ab-hint"><?php echo esc_html($m['hint']); ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Peligro -->
          <div class="ab-settings-section" style="border-color:#fecaca;">
            <h3 style="color:#e24b4a;">⚠ <?php echo esc_html( $this->tt( 'Zona de peligro', 'Danger zone' ) ); ?></h3>
            <div class="ab-field">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="amir_delete_data_on_uninstall" value="1"
                       <?php checked(get_option('amir_delete_data_on_uninstall','0'),'1'); ?>
                       style="accent-color:#e24b4a;width:auto;" />
                <?php echo esc_html( $this->tt( 'Eliminar todas las tablas y datos al desinstalar el plugin', 'Delete all tables and data when uninstalling the plugin' ) ); ?>
              </label>
              <p class="ab-hint" style="color:#e24b4a;">⚠ <?php echo esc_html( $this->tt( 'Activar solo si deseas eliminar permanentemente todos los datos del plugin al desinstalarlo.', 'Enable only if you want to permanently delete all of the plugin\'s data when uninstalling it.' ) ); ?></p>
            </div>
          </div>

          <button type="submit" class="button button-primary" style="padding:10px 28px;font-size:14px;"><?php echo esc_html( $this->tt( 'Guardar configuración', 'Save settings' ) ); ?></button>
        </form>

        <!-- Sincronización de tours — form independiente, fuera del form de -->
        <!-- configuración (un <form> anidado dentro de otro es HTML inválido: -->
        <!-- el navegador cierra el form exterior antes de tiempo y el botón -->
        <!-- "Guardar configuración" queda fuera de cualquier form, sin poder -->
        <!-- enviarse nunca). -->
        <div class="ab-settings-section" style="border-color:#9FE1CB;">
          <h3>🔄 <?php echo esc_html( $this->tt( 'Sincronización de tours', 'Tour sync' ) ); ?></h3>
          <p style="font-size:13px;color:#5a7068;margin:0 0 14px;">
            <?php echo esc_html( $this->tt( 'Si los tours no aparecen en Disponibilidad, Reservas o Reportes, usa este botón para sincronizar todos los tours publicados con la base de datos del plugin.', "If tours don't show up in Availability, Bookings, or Reports, use this button to sync all published tours with the plugin's database." ) ); ?>
          </p>
          <?php
          global $wpdb;
          $db_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}amir_tours");
          $cpt_count = (int) wp_count_posts('amir_tour')->publish;
          ?>
          <p style="font-size:13px;margin:0 0 14px;">
            <?php echo esc_html( $this->tt( 'Tours en base de datos:', 'Tours in database:' ) ); ?> <strong><?php echo $db_count; ?></strong> &nbsp;|&nbsp;
            <?php echo esc_html( $this->tt( 'Tours publicados (CPT):', 'Published tours (CPT):' ) ); ?> <strong><?php echo $cpt_count; ?></strong>
            <?php if ($db_count < $cpt_count) : ?>
              &nbsp;<span style="color:#e24b4a;font-weight:700;">⚠ <?php echo esc_html( $this->tt( 'Hay tours sin sincronizar', 'There are unsynced tours' ) ); ?></span>
            <?php elseif ($db_count > 0) : ?>
              &nbsp;<span style="color:#1D9E75;font-weight:700;">✓ <?php echo esc_html( $this->tt( 'Sincronizados', 'Synced' ) ); ?></span>
            <?php endif; ?>
          </p>
          <form method="post">
            <?php wp_nonce_field('amir_sync_tours','amir_sync_nonce'); ?>
            <button type="submit" class="button button-primary">🔄 <?php echo esc_html( $this->tt( 'Sincronizar todos los tours ahora', 'Sync all tours now' ) ); ?></button>
          </form>
        </div>

        <!-- Importar tours desde JSON — CONTRIBUTING.md § 15.9 -->
        <div class="ab-settings-section" style="border-color:#9FE1CB;">
          <h3>📥 <?php echo esc_html( $this->tt( 'Importar tours desde JSON', 'Import tours from JSON' ) ); ?></h3>
          <p style="font-size:13px;color:#5a7068;margin:0 0 14px;">
            <?php echo wp_kses_post( $this->tt(
              'Pegá acá el JSON generado con <a href="' . esc_url( plugins_url( 'PROMPT-IMPORTAR-TOUR.md', AMIR_PLUGIN_FILE ) ) . '" target="_blank">este prompt para IA</a> (formato <code>{"tours": [...]}</code>, uno o varios tours). Cada tour queda como <strong>borrador</strong> para que lo revises antes de publicar — si el <code>slug</code> ya existe, actualiza ese tour en vez de duplicarlo. Las imágenes (URLs externas) se descargan a la Media Library de WordPress.',
              'Paste here the JSON generated with <a href="' . esc_url( plugins_url( 'PROMPT-IMPORTAR-TOUR.md', AMIR_PLUGIN_FILE ) ) . '" target="_blank">this AI prompt</a> (format <code>{"tours": [...]}</code>, one or more tours). Each tour is saved as a <strong>draft</strong> so you can review it before publishing — if the <code>slug</code> already exists, it updates that tour instead of duplicating it. Images (external URLs) are downloaded to the WordPress Media Library.'
            ) ); ?>
          </p>
          <?php $this->render_import_result(); ?>
          <form method="post">
            <?php wp_nonce_field('amir_import_tours','amir_import_tours_nonce'); ?>
            <textarea name="amir_import_json" rows="8" placeholder='{"tours": [{"name_es": "...", "description_es": "...", ...}]}'
                      style="width:100%;border:1px solid #c3d9d0;border-radius:6px;padding:10px;font-size:12px;font-family:Consolas,Monaco,monospace;box-sizing:border-box;margin-bottom:10px;"></textarea>
            <button type="submit" class="button button-primary">📥 <?php echo esc_html( $this->tt( 'Importar', 'Import' ) ); ?></button>
          </form>

          <div style="margin-top:20px;padding-top:18px;border-top:1px dashed #c3d9d0;">
            <h4 style="margin:0 0 8px;font-size:13px;color:#1a2e24;">🧪 <?php echo esc_html( $this->tt( 'Datos de ejemplo para probar el plugin', 'Sample data to test the plugin' ) ); ?></h4>
            <p style="font-size:13px;color:#5a7068;margin:0 0 12px;">
              <?php echo wp_kses_post( $this->tt(
                'Carga <strong>6 tours de ejemplo</strong> con fotos de stock, cubriendo las combinaciones más comunes: calendario normal, fecha fija, "solo a pedido", precio por persona y por grupo, y un tour en Lista de interés. A diferencia del import de arriba, estos <strong>quedan publicados directamente</strong> (menos el de Lista de interés, que queda en borrador a propósito) — pensado para una instalación de pruebas, no para contenido real. Si un tour de ejemplo ya existe (mismo slug), lo actualiza en vez de duplicarlo.',
                'Loads <strong>6 sample tours</strong> with stock photos, covering the most common combinations: normal calendar, fixed date, "on request", per-person and per-group pricing, and one tour on the waitlist. Unlike the import above, these are <strong>published right away</strong> (except the waitlist one, left as a draft on purpose) — meant for a test installation, not real content. If a sample tour already exists (same slug), it updates it instead of duplicating it.'
              ) ); ?>
            </p>
            <form method="post" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Cargar 6 tours de ejemplo? Quedan publicados de inmediato, pensados solo para una instalación de pruebas.', 'Load 6 sample tours? They are published immediately, meant only for a test installation.' ) ); ?>');">
              <?php wp_nonce_field('amir_import_demo','amir_import_demo_nonce'); ?>
              <button type="submit" class="button">🧪 <?php echo esc_html( $this->tt( 'Cargar tours de ejemplo', 'Load sample tours' ) ); ?></button>
            </form>
          </div>
        </div>
        </div>
        <?php
    }

    /**
     * Importar tours desde JSON — CONTRIBUTING.md § 15.9. Reserva el JSON
     * crudo tal cual llega, sin sanitizar acá — TourImporter sanitiza campo
     * por campo antes de guardar nada.
     */
    private function handle_import_tours(): array {
        $raw = wp_unslash( $_POST['amir_import_json'] ?? '' );
        if ( trim( $raw ) === '' ) {
            return [ 'fatal_error' => $this->tt( 'Pegá un JSON antes de importar.', 'Paste a JSON before importing.' ) ];
        }

        $data = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            return [ 'fatal_error' => $this->tt( 'JSON inválido: ', 'Invalid JSON: ' ) . json_last_error_msg() ];
        }

        return ( new \AmirBooking\Core\TourImporter() )->import( $data, false, $this->lang() );
    }

    /**
     * Carga el set de tours de ejemplo bundleado con el plugin
     * (includes/data/demo-tours.json) — pensado para una instalación de
     * pruebas, no para contenido real de un cliente. A diferencia del
     * import manual de arriba, publica los tours directamente ($publish=true)
     * para poder probar el flujo de reserva de punta a punta sin pasos
     * extra; el tour de ejemplo de Lista de interés fuerza su propio
     * 'draft' en el JSON (ver TourImporter::import_one()) porque necesita
     * quedar sin publicar para demostrar esa función.
     */
    private function handle_import_demo_tours(): array {
        $path = AMIR_PLUGIN_DIR . 'includes/data/demo-tours.json';
        if ( ! file_exists( $path ) ) {
            return [ 'fatal_error' => $this->tt(
                'No se encontró includes/data/demo-tours.json en esta instalación.',
                'includes/data/demo-tours.json was not found in this installation.'
            ) ];
        }

        $data = json_decode( file_get_contents( $path ), true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            return [ 'fatal_error' => $this->tt( 'El archivo de datos de ejemplo está corrupto: ', 'The sample data file is corrupted: ' ) . json_last_error_msg() ];
        }

        return ( new \AmirBooking\Core\TourImporter() )->import( $data, true, $this->lang() );
    }

    private function render_import_result(): void {
        if ( $this->import_result === null ) {
            return;
        }
        $r = $this->import_result;

        if ( ! empty( $r['fatal_error'] ) ) {
            echo '<div class="notice notice-error" style="margin:0 0 14px;"><p>' . esc_html( $r['fatal_error'] ) . '</p></div>';
            return;
        }

        echo '<div class="notice notice-success" style="margin:0 0 14px;"><p>';
        printf(
            '✓ ' . esc_html( $this->tt( '%d tour(s) creado(s), %d actualizado(s), %d con error.', '%d tour(s) created, %d updated, %d with errors.' ) ),
            (int) $r['created'], (int) $r['updated'], (int) $r['errors']
        );
        echo '</p></div>';

        if ( ! empty( $r['results'] ) ) {
            echo '<ul style="font-size:12px;color:#5a7068;margin:0 0 14px;">';
            foreach ( $r['results'] as $row ) {
                $icon = $row['action'] === 'error' ? '❌' : ( $row['action'] === 'created' ? '🆕' : '🔄' );
                if ( $row['action'] === 'error' ) {
                    printf( '<li>%s %s — %s</li>', $icon, esc_html( $row['name'] ?? ( sprintf( $this->tt( 'Tour #%d', 'Tour #%d' ), $row['index'] + 1 ) ) ), esc_html( $row['error'] ) );
                } else {
                    printf(
                        '<li>%s %s — <a href="%s">' . esc_html( $this->tt( 'editar →', 'edit →' ) ) . '</a></li>',
                        $icon, esc_html( $row['name'] ),
                        esc_url( admin_url( 'post.php?post=' . (int) $row['tour_id'] . '&action=edit' ) )
                    );
                }
            }
            echo '</ul>';
        }
    }

    /**
     * Google Calendar, un solo sentido (Pro Max, § 15.4/§ 16 CONTRIBUTING.md)
     * — cada instalación conecta su PROPIO proyecto de Google Cloud (Client
     * ID/Secret propios), nunca una credencial compartida del plugin.
     */
    private function render_google_calendar_section(): void {
        $client_id     = get_option( 'amir_google_client_id', '' );
        $client_secret = get_option( 'amir_google_client_secret', '' );
        $connected     = \AmirBooking\Core\GoogleCalendarSync::is_connected();
        ?>
        <div class="ab-settings-section">
          <h3>📅 Google Calendar</h3>
          <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
            <?php echo esc_html( $this->tt(
              'Al confirmarse una reserva (tour o habitación) se crea/actualiza un evento en tu Google Calendar — check-in/check-out para habitaciones, requerimientos especiales en la descripción. Solo en este sentido (TourFlow → Calendar); un bloqueo manual en Calendar no afecta la disponibilidad acá.',
              "When a booking (tour or room) is confirmed, an event is created/updated in your Google Calendar — check-in/check-out for rooms, special requests in the description. One-way only (TourFlow → Calendar); a manual block in Calendar doesn't affect availability here."
            ) ); ?>
          </p>
          <p style="font-size:12px;color:#5a7068;margin:0 0 14px;">
            <?php echo wp_kses_post( sprintf(
              $this->tt(
                'Necesitás un proyecto propio en %s con la Calendar API habilitada, y un Client ID/Secret de tipo "Web application" con este redirect URI autorizado:',
                'You need your own project in %s with the Calendar API enabled, and a "Web application" type Client ID/Secret with this redirect URI authorized:'
              ),
              '<a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>'
            ) ); ?><br>
            <code style="font-size:11px;"><?php echo esc_html( \AmirBooking\Core\GoogleCalendarSync::redirect_uri() ); ?></code>
          </p>

          <div class="ab-field-row">
            <div class="ab-field">
              <label>Client ID</label>
              <input type="text" name="amir_google_client_id" value="<?php echo esc_attr( $client_id ); ?>" placeholder="123456789-xxxx.apps.googleusercontent.com" />
            </div>
            <div class="ab-field">
              <label>Client Secret</label>
              <input type="password" name="amir_google_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" placeholder="GOCSPX-…" />
            </div>
          </div>
          <div class="ab-field">
            <label><?php echo esc_html( $this->tt( 'ID de calendario', 'Calendar ID' ) ); ?></label>
            <input type="text" name="amir_google_calendar_id" value="<?php echo esc_attr( get_option( 'amir_google_calendar_id', 'primary' ) ); ?>" placeholder="primary" style="max-width:280px;" />
            <p class="ab-hint"><?php echo esc_html( $this->tt(
              'Dejalo en "primary" para tu calendario principal, o pegá el ID de un calendario específico (Configuración del calendario → Integrar calendario, en Google Calendar).',
              'Leave it as "primary" for your main calendar, or paste the ID of a specific calendar (Settings → Integrate calendar, in Google Calendar).'
            ) ); ?></p>
          </div>

          <?php if ( $connected ) : ?>
            <p style="font-size:13px;font-weight:700;color:#1D9E75;margin:14px 0;">✓ <?php echo esc_html( $this->tt( 'Conectado', 'Connected' ) ); ?></p>
            <form method="post" onsubmit="return confirm('<?php echo esc_js( $this->tt( '¿Desconectar Google Calendar? Las reservas van a dejar de sincronizarse hasta que reconectes.', 'Disconnect Google Calendar? Bookings will stop syncing until you reconnect.' ) ); ?>');">
              <?php wp_nonce_field( 'amir_google_disconnect', 'amir_google_disconnect_nonce' ); ?>
              <button type="submit" class="button"><?php echo esc_html( $this->tt( 'Desconectar', 'Disconnect' ) ); ?></button>
            </form>
          <?php elseif ( $client_id && $client_secret ) : ?>
            <p class="ab-hint" style="margin:0 0 10px;"><?php echo esc_html( $this->tt( 'Guardá el Client ID/Secret (botón "Guardar configuración" más abajo) antes de conectar — si los cambiás ahora, conectá después de guardar.', 'Save the Client ID/Secret ("Save settings" button below) before connecting — if you change them now, connect after saving.' ) ); ?></p>
            <a href="<?php echo esc_url( \AmirBooking\Core\GoogleCalendarSync::authorize_url() ); ?>" class="button button-primary"><?php echo esc_html( $this->tt( 'Conectar con Google Calendar', 'Connect with Google Calendar' ) ); ?></a>
          <?php else : ?>
            <p class="ab-hint"><?php echo esc_html( $this->tt( 'Cargá el Client ID y Client Secret arriba, guardá, y va a aparecer el botón para conectar.', 'Fill in the Client ID and Client Secret above, save, and the connect button will appear.' ) ); ?></p>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Bug real encontrado 2026-08-11 (reportado por el cliente: una ficha
     * con foto real no la mostraba en ningún grid/flujo — "solo un
     * ícono/emoji en el centro de la tarjeta"): esta función era una
     * reimplementación DUPLICADA de TourPostType::sync_to_db() con un
     * subconjunto de columnas — y `gallery_images` quedaba hardcodeado a
     * `'[]'` sin importar la imagen destacada o la galería reales del tour.
     * Resultado: el botón "🔄 Sincronizar todos los tours ahora" —
     * anunciado en esta misma pantalla como el arreglo para "si los tours
     * no aparecen" — en realidad BORRABA la galería de todos los tours en
     * cada click, sin ningún aviso. Corregido reusando la función real
     * (idéntica a la que corre en cada guardado normal del editor) en vez
     * de mantener una copia que inevitablemente se desincroniza con el
     * tiempo — ya pasó una vez, no debería volver a pasar.
     */
    private function sync_all_tours(): int {
        $posts = get_posts( [
            'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );

        $tour_post_type = new \AmirBooking\CPT\TourPostType();
        $count = 0;
        foreach ( $posts as $post ) {
            $tour_post_type->sync_to_db( $post->ID, $post );
            $count++;
        }

        return $count;
    }

    private function save_settings(): void {
        $options = [
            'amir_stripe_mode'             => 'sanitize_key',
            'amir_stripe_pk_test'          => 'sanitize_text_field',
            'amir_stripe_sk_test'          => 'sanitize_text_field',
            'amir_stripe_pk_live'          => 'sanitize_text_field',
            'amir_stripe_sk_live'          => 'sanitize_text_field',
            'amir_stripe_webhook_secret'   => 'sanitize_text_field',
            'amir_default_gateway'         => 'sanitize_key',
            'amir_mp_mode'                 => 'sanitize_key',
            'amir_mp_access_token_test'    => 'sanitize_text_field',
            'amir_mp_access_token_live'    => 'sanitize_text_field',
            'amir_mp_webhook_secret'       => 'sanitize_text_field',
            'amir_admin_email'             => 'sanitize_email',
            'amir_wa_phone'                => 'sanitize_text_field',
            'amir_pending_expire_mins'     => 'absint',
            'amir_review_delay_days'       => 'absint',
            'amir_usd_rate_mode'           => 'sanitize_key',
            'amir_usd_rate_manual'         => 'floatval',
            'amir_google_review_url'       => 'esc_url_raw',
            'amir_tripadvisor_review_url'  => 'esc_url_raw',
            // Google Calendar (Pro Max, § 16 CONTRIBUTING.md)
            'amir_google_client_id'        => 'sanitize_text_field',
            'amir_google_client_secret'    => 'sanitize_text_field',
            'amir_google_calendar_id'      => 'sanitize_text_field',
            // Marketing (píxeles)
            'amir_meta_pixel_id'           => 'sanitize_text_field',
            'amir_gads_conversion_id'      => 'sanitize_text_field',
            'amir_gads_conversion_label'   => 'sanitize_text_field',
            'amir_ga4_id'                  => 'sanitize_text_field',
            'amir_cookie_banner_text_es'   => 'sanitize_textarea_field',
            'amir_cookie_banner_text_en'   => 'sanitize_textarea_field',
        ];

        // wp_unslash() antes de sanitizar — ver la misma nota en
        // PersonalizationPage::save_settings() (bug real reportado por el
        // cliente 2026-08-05, mismo patrón repetido en 4 archivos).
        foreach ($options as $key => $sanitizer) {
            if (isset($_POST[$key])) {
                update_option($key, call_user_func($sanitizer, wp_unslash($_POST[$key])));
            }
        }

        $delete = isset($_POST['amir_delete_data_on_uninstall']) ? '1' : '0';
        update_option('amir_delete_data_on_uninstall', $delete);

        // Módulos — checkbox ausente en $_POST cuando está destildado
        foreach ( [ 'amir_module_marketplace', 'amir_module_wishlist', 'amir_module_partners' ] as $module_opt ) {
            update_option( $module_opt, isset( $_POST[ $module_opt ] ) ? '1' : '0' );
        }

        // CORS — checkbox ausente cuando está destildado; orígenes: uno por línea/coma, validados como URL exacta.
        update_option( 'amir_cors_enabled', isset( $_POST['amir_cors_enabled'] ) ? '1' : '0' );
        if ( isset( $_POST['amir_cors_allowed_origins'] ) ) {
            $lines   = preg_split( '/[\r\n,]+/', (string) $_POST['amir_cors_allowed_origins'] );
            $origins = array_map( fn( $line ) => rtrim( trim( $line ), '/' ), $lines );
            $origins = array_values( array_unique( array_filter( $origins, fn( $o ) => $o !== '' && filter_var( $o, FILTER_VALIDATE_URL ) ) ) );
            update_option( 'amir_cors_allowed_origins', wp_json_encode( $origins ) );
        }

        // Idiomas activos: códigos de 2 letras separados por coma, 'es' siempre presente.
        if ( isset( $_POST['amir_active_languages_csv'] ) ) {
            $codes = array_map( 'trim', explode( ',', strtolower( $_POST['amir_active_languages_csv'] ) ) );
            $codes = array_values( array_unique( array_filter( $codes, fn( $c ) => (bool) preg_match( '/^[a-z]{2}$/', $c ) ) ) );
            if ( ! in_array( 'es', $codes, true ) ) {
                array_unshift( $codes, 'es' );
            }
            update_option( 'amir_active_languages', wp_json_encode( $codes ) );
        }

        // Moneda: si eligió "Otra", usar el código ISO libre; si no, la opción estándar.
        $selected = sanitize_text_field( $_POST['amir_currency'] ?? '' );
        $custom   = strtoupper( sanitize_text_field( $_POST['amir_currency_custom'] ?? '' ) );
        $currency = $selected !== '' ? $selected : $custom;
        if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
            $old_currency = get_option( 'amir_currency', 'MXN' );
            update_option( 'amir_currency', $currency );
            if ( $currency !== $old_currency ) {
                delete_transient( 'amir_usd_rate_' . strtolower( $old_currency ) );
            }
        }

        // Invalidar caché de tipo de cambio de referencia
        delete_transient( 'amir_usd_rate_' . strtolower( get_option( 'amir_currency', 'MXN' ) ) );
    }
}
