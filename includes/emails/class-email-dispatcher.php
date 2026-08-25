<?php
namespace AmirBooking\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Despachador de emails.
 * Escucha los WP actions del plugin y despacha el email correcto.
 */
class EmailDispatcher {

    public function register(): void {
        add_action( 'amir_booking_confirmed',   [ $this, 'send_confirmation'   ], 10, 1 );
        add_action( 'amir_send_reminder_email', [ $this, 'send_reminder'       ], 10, 1 );
        add_action( 'amir_send_review_email',   [ $this, 'send_review_request' ], 10, 1 );
        add_action( 'amir_booking_cancelled',   [ $this, 'send_cancellation'   ], 10, 2 );
        add_action( 'amir_booking_rescheduled', [ $this, 'send_reschedule_notice' ], 10, 1 );

        // ── Marketplace de proveedores (§ 11 CONTRIBUTING.md) ──────────────
        // Al entrar a pending_provider_approval, dos emails: al proveedor
        // (con los links de aprobar/rechazar) y al cliente (aviso interino,
        // sin nombrar al proveedor — su pago ya se procesó).
        add_action( 'amir_booking_pending_provider_approval', [ $this, 'send_provider_notice' ], 10, 1 );
        add_action( 'amir_booking_pending_provider_approval', [ $this, 'send_provider_pending_notice' ], 10, 1 );
        add_action( 'amir_booking_pending_provider_approval', [ $this, 'notify_admin_provider_pending' ], 10, 1 );
        add_action( 'amir_send_provider_reminder_email', [ $this, 'send_provider_reminder' ], 10, 1 );

        // Tours "solo a pedido" (amir_tours.request_only) — solo aviso al
        // admin, sin email al cliente todavía (mismo criterio que
        // "solicitar fecha" en tours de fecha fija: no hay nada urgente que
        // decirle hasta que el operador apruebe y mande el link de pago).
        add_action( 'amir_booking_date_requested', [ $this, 'notify_admin_date_requested' ], 10, 1 );
        // Bug real reportado por el cliente (2026-08-14): el widget le
        // promete al cliente "te avisamos por email en cuanto la
        // revisemos", pero nada disparaba ese email — solo se notificaba
        // al admin. Mismo patrón que send_provider_pending_notice() para
        // el flujo de proveedores externos.
        add_action( 'amir_booking_date_requested', [ $this, 'notify_client_date_requested' ], 10, 1 );

        // Cobro diferido (§ 11.0 CONTRIBUTING.md): el proveedor aprobó una
        // reserva que todavía no se cobró — recién acá se manda el link de
        // pago real al cliente.
        add_action( 'amir_provider_awaiting_payment', [ $this, 'send_provider_awaiting_payment_link' ], 10, 1 );

        // Depósito parcial ("Depósito parcial por tour") — el cliente pagó
        // el saldo restante (link enviado aparte por el operador). Recibo
        // corto, no la confirmación completa (la reserva ya estaba confirmada).
        add_action( 'amir_booking_balance_paid', [ $this, 'notify_client_balance_paid' ], 10, 1 );
    }

    public function notify_client_balance_paid( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        ( new BalancePaidEmail( $booking ) )->send();
    }

    /**
     * Reserva de una reserva CONFIRMADA con depósito activo — el operador
     * quiere que el cliente pague el saldo restante online en vez de en
     * persona. Reusa el mismo mecanismo de link de pago (init_payment()
     * acepta este caso, ver class-booking-controller.php) y el mismo email
     * base que "link de pago" para reservas manuales, solo con otro texto.
     *
     * @return array{success:bool,error:string}
     */
    public function send_balance_payment_link_notice( object $booking ): array {
        $mailer  = new BalancePaymentLinkEmail( $booking );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    public function send_provider_awaiting_payment_link( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        $this->send_payment_link_notice( $booking );
    }

    // ── Marketplace de proveedores ─────────────────────────────────────────

    /**
     * @return array{success:bool,error:string}
     */
    public function send_provider_notice( int $booking_id ): array {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking || empty( $booking->provider_email ) ) {
            return [ 'success' => false, 'error' => 'El proveedor no tiene email configurado.' ];
        }
        $mailer  = new ProviderNoticeEmail( $booking, $booking->provider_email );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    /** Recordatorio a las 24h (cron) — mismo email, mismo token sin regenerar. */
    public function send_provider_reminder( int $booking_id ): void {
        $this->send_provider_notice( $booking_id );
    }

    /** Aviso al cliente de que su pago ya se procesó y se está confirmando disponibilidad — sin nombrar al proveedor. */
    public function send_provider_pending_notice( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        ( new ProviderPendingNoticeEmail( $booking ) )->send();
    }

    // ── Solicitud de fecha (tour "solo a pedido" o "solicitar otra fecha"
    // en tour de fecha fija) — aviso al CLIENTE de que se recibió, sin
    // cobrar todavía. notify_admin_date_requested() (arriba) ya avisaba al
    // operador; esto era lo que faltaba del otro lado. ───────────────────

    public function notify_client_date_requested( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        ( new DateRequestedEmail( $booking ) )->send();
    }

    /**
     * Aviso al cliente cuando el operador rechaza su solicitud de fecha —
     * bug real reportado por el cliente (2026-08-14): rechazar no avisaba
     * nunca, el cliente se quedaba esperando sin saber que no iba a pasar
     * nada. Llamado directo desde BookingsPage::handle_detail_action()
     * (mismo criterio que send_payment_link_notice() para la aprobación),
     * no por hook — es una acción puntual del operador, no un evento del
     * ciclo de vida de la reserva. $booking->custom_email_note ya trae el
     * mensaje opcional que el operador escribió en el formulario de
     * rechazo (BookingsPage lo guarda ahí antes de llamar acá).
     *
     * @return array{success:bool,error:string}
     */
    public function send_date_request_rejected_notice( object $booking ): array {
        $mailer  = new DateRequestRejectedEmail( $booking );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    // ── Lista de interés: link de pago real ───────────────────────────────
    // Se llama desde el admin (Lista de interés → "Publicar y notificar")
    // para cada reserva 'wishlist' de ese tour, una vez que pasa a
    // 'awaiting_payment'. $booking ya es una reserva real (booking_ref,
    // access_token, etc.) — el link de pago reutiliza verify_url() de
    // BaseEmail tal cual, sin armar nada a mano.

    /**
     * @return array{success:bool,error:string}
     */
    public function send_tour_opened_notice( object $booking ): array {
        $mailer  = new TourOpenedEmail( $booking );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    /**
     * Reserva manual cargada por el operador sin cobrar todavía (Amir
     * Booking → Reservas → Nueva reserva manual → "El cliente todavía no
     * pagó") — mismo mecanismo de link de pago que wishlist, pero el
     * booking ya viene armado por BookingManager::create_manual() (con
     * tour_name resuelto vía get_booking_with_tour()), así que se recibe
     * el objeto directo en vez de un booking_id + action hook.
     */
    public function send_payment_link_notice( object $booking ): array {
        $mailer  = new PaymentLinkEmail( $booking );
        $success = $mailer->send();
        return [ 'success' => $success, 'error' => $success ? '' : $mailer->get_last_error() ];
    }

    // ── Reprogramación ────────────────────────────────────────────────────
    // Bug real encontrado probando en vivo: BookingManager::reschedule()
    // ya disparaba amir_booking_rescheduled, pero nada estaba enganchado a
    // ese hook — la reserva se reprogramaba en la base sin avisarle nunca
    // al cliente. Mismo patrón que send_confirmation()/send_cancellation().

    public function send_reschedule_notice( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        $mailer = new RescheduleEmail( $booking );
        $mailer->send();
    }

    // ── Confirmación ──────────────────────────────────────────────────────

    public function send_confirmation( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }

        // Reserva de un carrito multi-ítem (cart_group_id, § 16 CONTRIBUTING.md):
        // el cliente ya recibe el "voucher general" único con TODOS los ítems
        // del carrito (CartConfirmationEmail, disparado por flow_cart_confirmed)
        // — mandarle además esta confirmación individual por cada tour
        // duplicaba avisos para una sola compra (pedido del cliente 2026-08-04:
        // "si reservan varios tours o productos llegan varios email separados,
        // hacer que llegue la reserva toda junta"). Standalone (widget
        // individual de un tour, sin carrito) sigue mandando esta confirmación
        // como siempre — y una reserva pendiente de aprobación de proveedor
        // (§ 11) nunca pasa por acá: dispara amir_booking_pending_provider_approval
        // en su lugar, con sus propios emails, sin tocar.
        if ( empty( $booking->cart_group_id ) ) {
            $mailer = new ConfirmationEmail( $booking );
            $mailer->send();
        }

        // Notificar también al admin
        $this->notify_admin_new_booking( $booking );
    }

    // ── Recordatorio pre-tour ─────────────────────────────────────────────

    public function send_reminder( object $booking ): void {
        $mailer = new ReminderEmail( $booking );
        $mailer->send();
    }

    // ── Solicitud de reseña ───────────────────────────────────────────────

    public function send_review_request( object $booking ): void {
        $mailer = new ReviewEmail( $booking );
        $mailer->send();
    }

    // ── Cancelación ───────────────────────────────────────────────────────

    public function send_cancellation( int $booking_id, string $reason_type ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }
        $mailer = new CancellationEmail( $booking, $reason_type );
        $mailer->send();
    }

    // ── Admin: nueva reserva ──────────────────────────────────────────────

    private function notify_admin_new_booking( object $booking ): void {
        $admin_email = get_option( 'amir_admin_email', get_option('admin_email') );
        if ( ! $admin_email ) {
            return;
        }

        $subject = sprintf( '[Nueva reserva] %s — %s el %s',
            $booking->booking_ref,
            $booking->customer_name,
            $booking->tour_date
        );

        $body = sprintf(
            "Nueva reserva recibida:\n\n" .
            "Referencia: %s\n" .
            "Tour: %s\n" .
            "Fecha: %s\n" .
            "Hora: %s\n" .
            "Cliente: %s (%s)\n" .
            "Teléfono: %s\n" .
            "Adultos: %d | Niños: %d | Bebés: %d\n" .
            "Total: %s\n" .
            "Origen: %s\n\n" .
            "Ver en el panel: %s",
            $booking->booking_ref,
            $booking->tour_name,
            $booking->tour_date,
            $booking->time_start ?? '',
            $booking->customer_name,
            $booking->customer_email,
            $booking->customer_phone,
            $booking->adults,
            $booking->children,
            $booking->babies,
            \AmirBooking\Core\Currency::format( (float) $booking->total_mxn ),
            $booking->booking_source,
            admin_url( 'admin.php?page=amir-bookings-list' )
        );

        wp_mail( $admin_email, $subject, $body );
    }

    /**
     * Aviso al admin de que salió un pedido de aprobación a un proveedor
     * externo (marketplace, § 11 CONTRIBUTING.md) — pedido del cliente
     * 2026-07-30: hasta ahora solo se enteraba de estas ventas cuando el
     * proveedor terminaba de aprobar (recién ahí dispara send_confirmation()
     * → notify_admin_new_booking() de arriba). Con esto se entera apenas se
     * generó la venta, no 24-48h después.
     */
    public function notify_admin_provider_pending( int $booking_id ): void {
        $admin_email = get_option( 'amir_admin_email', get_option( 'admin_email' ) );
        if ( ! $admin_email ) {
            return;
        }

        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }

        $subject = sprintf(
            '[Proveedor: esperando aprobación] %s — %s el %s',
            $booking->booking_ref,
            $booking->customer_name,
            $booking->tour_date
        );

        $body = sprintf(
            "Nueva venta de un tour de proveedor externo — ya se cobró, esperando que %s apruebe o rechace:\n\n" .
            "Referencia: %s\n" .
            "Tour: %s\n" .
            "Proveedor: %s (%s)\n" .
            "Fecha: %s\n" .
            "Cliente: %s (%s)\n" .
            "Adultos: %d | Niños: %d | Bebés: %d\n" .
            "Total cobrado al cliente: %s\n\n" .
            "El proveedor tiene %d horas para responder antes de la cancelación automática con reembolso — ver el detalle y el estado en el panel: %s",
            $booking->provider_business_name ?: 'proveedor sin nombre cargado',
            $booking->booking_ref,
            $booking->tour_name,
            $booking->provider_business_name ?: '—',
            $booking->provider_email ?: 'sin email cargado',
            $booking->tour_date,
            $booking->customer_name,
            $booking->customer_email,
            $booking->adults,
            $booking->children,
            $booking->babies,
            \AmirBooking\Core\Currency::format( (float) $booking->total_mxn ),
            (int) get_option( 'amir_provider_response_hours', 48 ),
            admin_url( 'admin.php?page=amir-bookings-list&action=view&id=' . $booking_id )
        );

        wp_mail( $admin_email, $subject, $body );
    }

    /**
     * Tours "solo a pedido" (amir_tours.request_only) — aviso al admin de
     * que hay una solicitud nueva para revisar en Reservas. Notificación
     * en campana + email, mismo criterio que
     * WishlistController::maybe_notify_threshold_reached().
     */
    public function notify_admin_date_requested( int $booking_id ): void {
        $booking = $this->get_booking_with_tour( $booking_id );
        if ( ! $booking ) {
            return;
        }

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}amir_notifications", [
            'type'    => 'date_request',
            'title'   => 'Solicitud de fecha',
            'message' => sprintf(
                '%s solicitó "%s" para el %s. Revisa Reservas para aprobar o rechazar.',
                $booking->customer_name, $booking->tour_name, $booking->tour_date
            ),
            'data'    => json_encode( [ 'booking_id' => $booking_id ] ),
            'is_read' => 0,
        ], [ '%s', '%s', '%s', '%s', '%d' ] );

        $admin_email = get_option( 'amir_admin_email', get_option( 'admin_email' ) );
        if ( ! $admin_email ) {
            return;
        }

        wp_mail(
            $admin_email,
            '[TourFlow] Solicitud de fecha — ' . $booking->booking_ref,
            sprintf(
                "%s (%s) solicitó \"%s\" para el %s — sin cobrar todavía.\n\n" .
                "Adultos: %d | Niños: %d | Bebés: %d\nTotal a cobrar si aprobás: %s\n\n" .
                "Revisar y aprobar/rechazar: %s",
                $booking->customer_name, $booking->customer_email, $booking->tour_name, $booking->tour_date,
                $booking->adults, $booking->children, $booking->babies,
                \AmirBooking\Core\Currency::format( (float) $booking->total_mxn ),
                admin_url( 'admin.php?page=amir-bookings-list&action=view&id=' . $booking_id )
            )
        );
    }

    // ── Helper ────────────────────────────────────────────────────────────

    /** Público porque BookingManager::create_manual() también lo necesita
     * para armar el email de link de pago con el nombre del tour ya resuelto. */
    public function get_booking_with_tour( int $booking_id ): ?object {
        global $wpdb;
        // LEFT JOIN para que reservas sin schedule_id (=0) también se incluyan
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*,
                        t.name_es, t.name_en,
                        t.meeting_point_es, t.meeting_point_en,
                        t.meeting_lat, t.meeting_lng,
                        t.gallery_images,
                        t.provider_id,
                        p.business_name AS provider_business_name,
                        p.contact_name  AS provider_contact_name,
                        p.email         AS provider_email,
                        s.time_start, s.time_end, s.label_es, s.label_en
                 FROM {$wpdb->prefix}amir_bookings b
                 JOIN {$wpdb->prefix}amir_tours t ON t.id = b.tour_id
                 LEFT JOIN {$wpdb->prefix}amir_providers p ON p.id = t.provider_id
                 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = b.schedule_id
                 WHERE b.id = %d",
                $booking_id
            )
        );

        if ( $booking ) {
            // El nombre del tour en el idioma de la reserva — antes se
            // resolvía en SQL con un CASE WHEN que solo conocía es/en.
            $booking->tour_name = \AmirBooking\Core\Languages::tour_field( $booking, 'name', $booking->lang ?? 'es' );
        }

        return $booking;
    }
}

// ── Base Email ────────────────────────────────────────────────────────────────

abstract class BaseEmail {

    protected object $booking;
    protected string $lang;
    /** Destinatario alternativo al cliente (ej. el proveedor externo del marketplace) — null = customer_email de siempre. */
    protected ?string $to_override = null;
    private string $last_error = '';

    public function __construct( object $booking, ?string $to_override = null ) {
        $this->booking     = $booking;
        $this->lang        = $booking->lang ?? 'es';
        $this->to_override = $to_override;
    }

    abstract protected function get_subject(): string;
    abstract protected function get_body_content(): string;

    public function send(): bool {
        add_filter( 'wp_mail_content_type', [ $this, 'set_html' ] );

        $to = $this->to_override ?? $this->booking->customer_email;

        // Todo el contenido se arma dentro de run_in(): __()/_e() traducen
        // al idioma de LA RESERVA (no al locale del sitio) mientras dure el callback.
        $result = \AmirBooking\Core\Languages::run_in( $this->lang, function() use ( $to ) {
            return wp_mail(
                $to,
                $this->get_subject(),
                $this->wrap_template( $this->get_body_content() )
            );
        } );

        remove_filter( 'wp_mail_content_type', [ $this, 'set_html' ] );

        // Log si falla
        if ( ! $result ) {
            $this->last_error = '';
            if ( isset( $GLOBALS['phpmailer'] ) && $GLOBALS['phpmailer']->ErrorInfo ) {
                $this->last_error = $GLOBALS['phpmailer']->ErrorInfo;
            }
            error_log( sprintf(
                'Amir Booking: wp_mail falló para %s — Asunto: %s — Error: %s',
                $to,
                $this->get_subject(),
                $this->last_error
            ) );
        }

        return $result;
    }

    /** Detalle del error si send() devolvió false — vacío si nunca falló. */
    public function get_last_error(): string {
        return $this->last_error;
    }

    public function set_html(): string {
        return 'text/html';
    }

    // ── Textos editables (TourFlow → ✉️ Emails → Editar textos) ────────────
    // Reemplaza la dependencia de __()/.mo para el contenido de cada email:
    // 'en' nunca tuvo amir-booking-en_US.mo compilado, así que todo lo que
    // pasaba por __() con msgid en español se mostraba en español incluso
    // en mails con lang='en' — bug real reportado por el cliente. Ahora cada
    // texto tiene un default es/en hardcodeado en la propia clase (sin
    // depender de gettext) y puede overridearse desde el admin, guardado por
    // EmailTexts en wp_options.

    /** Clave de tipo para EmailTexts — cada subclase la redefine. */
    protected static function type_key(): string {
        return '';
    }

    /**
     * Traduce el 'label' de un campo de text_fields() según el idioma de
     * ADMIN del usuario logueado (no el de la reserva/email en sí — eso lo
     * gobierna $this->lang, algo completamente aparte). Solo afecta el
     * nombre del campo que ve el operador en TourFlow → ✉️ Emails → Editar
     * textos, nunca el contenido real que recibe el cliente.
     */
    protected static function field_label( string $es, string $en ): string {
        return strpos( get_user_locale(), 'en' ) === 0 ? $en : $es;
    }

    /**
     * Catálogo de textos editables de esta clase: clave => ['label'=>..,
     * 'es'=>default, 'en'=>default]. Lo usan tanto text() (fallback) como la
     * pantalla de edición de TourFlow → ✉️ Emails.
     */
    public static function text_fields(): array {
        return [];
    }

    /**
     * Texto editable — busca un override guardado para $this->lang; si no
     * hay, usa el default de text_fields() en español o inglés (nunca cae al
     * español para un idioma que no sea 'es'). $vars reemplaza {placeholder}
     * en el resultado, sin volver a escapar — el caller ya debe pasar
     * valores dinámicos escapados si van dentro de HTML.
     */
    protected function text( string $key, array $vars = [] ): string {
        $fields = static::text_fields();
        $def_es = $fields[ $key ]['es'] ?? '';
        $def_en = $fields[ $key ]['en'] ?? $def_es;

        $override = static::type_key() !== ''
            ? EmailTexts::get_override( static::type_key(), $key, $this->lang )
            : null;
        $tpl = $override ?? ( $this->lang === 'es' ? $def_es : $def_en );

        foreach ( $vars as $k => $v ) {
            $tpl = str_replace( '{' . $k . '}', (string) $v, $tpl );
        }
        return $tpl;
    }

    // ── HTML wrapper ──────────────────────────────────────────────────────

    protected function wrap_template( string $content ): string {
        $logo_url     = get_option( 'amir_brand_logo_url', '' );
        $logo         = $logo_url ?: ( AMIR_PLUGIN_URL . 'assets/images/logo-email.png' );
        $color        = get_option( 'amir_brand_color', '#1D9E75' );
        $color_dark   = $this->darken_color( $color );
        $color_light  = $this->lighten_color( $color );
        $company_name = get_option( 'amir_company_name', 'TourFlow' );
        $wa           = get_option( 'amir_wa_phone', '' );
        $year         = date( 'Y' );
        // Eslogan configurable (Personalización → Eslogan) en vez de la
        // ubicación hardcodeada de Amir Adventours que había acá antes —
        // aparecía en el footer de TODOS los emails de CUALQUIER
        // instalación, sin importar dónde opere el cliente (bug real
        // reportado 2026-08-05). Sin tagline configurado, el footer no
        // inventa una ubicación — solo muestra el nombre de la empresa.
        $tagline_opt  = get_option( $this->lang === 'en' ? 'amir_company_tagline_en' : 'amir_company_tagline_es', '' );
        $footer_tag   = $tagline_opt ? ' &nbsp;·&nbsp; ' . esc_html( $tagline_opt ) : '';

        // Bug real reportado por el cliente (2026-08-14): el link "Tours" del
        // footer, en cualquier idioma que no fuera el base, apuntaba solo a
        // "/{lang}/" (ej. "/en") en vez de "/{lang}/tours/" — asumía que
        // Polylang/WPML sirven cada idioma bajo ese prefijo, lo cual no es
        // necesariamente cierto para todas las instalaciones (no todas usan
        // Polylang/WPML), y ni siquiera agregaba "tours/" al final. En vez de
        // adivinar la URL correcta por instalación, se quita el link — el
        // footer se queda solo con WhatsApp (si está configurado).
        // Sin número configurado (instalación nueva sin amir_wa_phone cargado
        // en Configuración), no mostrar un link roto — antes esto caía en un
        // número de WhatsApp real hardcodeado (el de Amir Adventours), lo que
        // filtraba mensajes de clientes de OTRAS instalaciones a ese número.
        $footer_links = $wa ? '<a href="https://wa.me/' . esc_attr( $wa ) . '">WhatsApp</a>' : '';

        return '<!DOCTYPE html><html lang="' . $this->lang . '">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . esc_html( $this->get_subject() ) . '</title>
  <style>
    body { margin:0; padding:0; background:#f0f9f5; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#1a2e24; }
    .wrap { max-width:560px; margin:0 auto; }
    .header { background:' . esc_attr($color) . '; padding:24px 32px; text-align:center; }
    .header img { height:48px; }
    .body { background:#ffffff; padding:32px 32px 24px; }
    .footer { background:' . esc_attr($color_light) . '; padding:20px 32px; text-align:center; font-size:12px; color:#5a7068; }
    .footer a { color:' . esc_attr($color_dark) . '; text-decoration:none; }
    h1 { font-size:22px; font-weight:800; margin:0 0 8px; }
    p  { font-size:15px; line-height:1.6; color:#3d3d3a; margin:0 0 14px; }
    .ref-box { background:' . esc_attr($color_light) . '; border-radius:10px; padding:16px 20px; text-align:center; margin:20px 0; }
    .ref-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:' . esc_attr($color_dark) . '; }
    .ref-value { font-size:28px; font-weight:800; color:' . esc_attr($color_dark) . '; letter-spacing:2px; margin-top:4px; }
    .info-table { width:100%; border-collapse:collapse; margin:16px 0; }
    .info-table td { padding:10px 0; border-bottom:1px solid ' . esc_attr($color_light) . '; font-size:14px; vertical-align:top; }
    .info-table td:first-child { color:#5a7068; width:40%; padding-right:12px; }
    .info-table td:last-child { font-weight:600; }
    .btn { display:inline-block; padding:13px 28px; background:' . esc_attr($color) . '; color:#ffffff !important; border-radius:8px; text-decoration:none; font-size:15px; font-weight:700; margin:16px 0 8px; }
    .btn-outline { background:transparent; color:' . esc_attr($color) . ' !important; border:2px solid ' . esc_attr($color) . '; }
    .policy-box { background:#fffbeb; border-left:4px solid #BA7517; padding:12px 16px; border-radius:0 8px 8px 0; margin:16px 0; }
    .policy-box p { font-size:13px; color:#78350f; margin:3px 0; }
    .divider { border:none; border-top:1px solid ' . esc_attr($color_light) . '; margin:20px 0; }
    @media (max-width:600px) {
      .body { padding:24px 20px 20px; }
      h1 { font-size:20px; }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <img src="' . esc_url($logo) . '" alt="' . esc_attr($company_name) . '" style="height:48px;max-width:200px;" />
  </div>
  <div class="body">' . $content . '</div>
  <div class="footer">
    ' . $footer_links . '<br><br>
    &copy; ' . $year . ' ' . esc_html($company_name) . $footer_tag . '
  </div>
</div>
</body></html>';
    }

    /**
     * URL de la página pública de verificación de reserva, con el
     * access_token de la reserva para que el link autorice la lectura
     * sin pedirle el email de nuevo al cliente.
     */
    protected function verify_url(): string {
        $page_id = (int) get_option( 'amir_verify_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : false;
        $base    = $base ?: ( get_site_url() . '/verificar-reserva/' );

        return add_query_arg(
            [ 'ref' => $this->booking->booking_ref, 'token' => $this->booking->access_token ?? '' ],
            $base
        );
    }

    /**
     * Color de marca configurable — mismo default/opción que usa
     * wrap_template() para el header y los botones. Varias subclases tenían
     * el teal `#1D9E75` hardcodeado en vez de llamar a esto (link "Ver en
     * mapa", ayuda de WhatsApp, nota del operador) — con un color de marca
     * distinto configurado (ej. Caliafarm, azul), esos elementos puntuales
     * se quedaban en teal mientras el resto del email sí cambiaba (auditoría
     * de UI 2026-08-05, mismo patrón ya encontrado y corregido en el voucher
     * combinado del carrito).
     */
    protected function brand_color(): string {
        return get_option( 'amir_brand_color', '#1D9E75' );
    }

    /**
     * Oscurece un color hex ~20% para textos sobre fondos claros.
     */
    protected function darken_color( string $hex, float $factor = 0.7 ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen($hex) !== 6 ) return $hex;
        $r = (int) ( hexdec( substr($hex,0,2) ) * $factor );
        $g = (int) ( hexdec( substr($hex,2,2) ) * $factor );
        $b = (int) ( hexdec( substr($hex,4,2) ) * $factor );
        return sprintf( '#%02x%02x%02x', max(0,$r), max(0,$g), max(0,$b) );
    }

    /**
     * Aclara un color hex para fondos (~90% blanco).
     */
    protected function lighten_color( string $hex, float $factor = 0.15 ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen($hex) !== 6 ) return '#e1f5ee';
        $r = hexdec( substr($hex,0,2) );
        $g = hexdec( substr($hex,2,2) );
        $b = hexdec( substr($hex,4,2) );
        $r = (int) ( $r + ( 255 - $r ) * ( 1 - $factor ) );
        $g = (int) ( $g + ( 255 - $g ) * ( 1 - $factor ) );
        $b = (int) ( $b + ( 255 - $b ) * ( 1 - $factor ) );
        return sprintf( '#%02x%02x%02x', min(255,$r), min(255,$g), min(255,$b) );
    }

    // ── Recomendaciones desde opciones ───────────────────────────────────

    /**
     * Devuelve el bloque HTML de recomendaciones leído desde las opciones del plugin.
     * Si la opción está vacía usa el array de $defaults.
     */
    protected function recs_html( string $option_es, string $option_en, array $defaults_es, array $defaults_en ): string {
        // Las recomendaciones las escribe el operador a mano por idioma
        // (opción separada, no un string fijo del código) — solo existen
        // para es/en hoy. Bug real corregido (auditoría pre-empaquetado
        // v5.7.14, CONTRIBUTING.md § 5.5/16.91): esto comparaba contra
        // 'en' en vez de 'es' como único caso especial — un idioma 3+
        // (it/fr/pt) caía al listado en ESPAÑOL en vez de en inglés,
        // inconsistente con BaseEmail::text() (línea ~533 de este archivo),
        // que sí trata 'es' como el único idioma que no cae a EN.
        $is_es = $this->lang === 'es';
        $raw   = get_option( $is_es ? $option_es : $option_en, '' );
        $items = $raw
            ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) )
            : ( $is_es ? $defaults_es : $defaults_en );

        $style = 'font-size:14px;color:#3d3d3a;line-height:1.8;padding-left:20px;';
        $title = '📋 ' . ( $is_es ? 'Recomendaciones' : 'Recommendations' );
        $lis   = '';
        foreach ( $items as $item ) {
            $lis .= '<li>' . esc_html( $item ) . '</li>';
        }
        return '<hr class="divider"><p><strong>' . $title . '</strong></p>'
             . '<ul style="' . $style . '">' . $lis . '</ul>';
    }

    // ── Helpers compartidos ───────────────────────────────────────────────

    /**
     * Diccionario corto de UI para emails/voucher — bilingüe hardcodeado
     * (no __()/.mo, ver nota arriba de text()). No es editable desde el
     * admin a propósito: son etiquetas cortas de estructura (nombres de
     * columna, botones), no el "mensaje" del email — la superficie editable
     * vive en text_fields() de cada clase.
     */
    protected function t( string $key ): string {
        static $dict = [
            'booking_ref'     => [ 'es' => 'Número de reserva',                        'en' => 'Booking reference' ],
            'tour'            => [ 'es' => 'Tour',                                     'en' => 'Tour' ],
            'date'            => [ 'es' => 'Fecha',                                    'en' => 'Date' ],
            'time'            => [ 'es' => 'Hora de salida',                           'en' => 'Departure time' ],
            'meeting'         => [ 'es' => 'Punto de encuentro',                       'en' => 'Meeting point' ],
            'people'          => [ 'es' => 'Personas',                                 'en' => 'People' ],
            'total'           => [ 'es' => 'Total pagado',                             'en' => 'Total paid' ],
            'adults'          => [ 'es' => 'adultos',                                  'en' => 'adults' ],
            'children'        => [ 'es' => 'niños',                                    'en' => 'children' ],
            'babies'          => [ 'es' => 'bebés',                                    'en' => 'infants' ],
            'maps_link'       => [ 'es' => 'Ver en mapa',                              'en' => 'View on map' ],
            'download_pdf'    => [ 'es' => 'Descargar mi voucher PDF',                 'en' => 'Download my PDF voucher' ],
            'add_cal'         => [ 'es' => 'Agregar al calendario',                    'en' => 'Add to calendar' ],
            'wa_help'         => [ 'es' => '¿Necesitas ayuda? Escríbenos por WhatsApp', 'en' => 'Need help? Message us on WhatsApp' ],
            'view_status'     => [ 'es' => 'Ver estado de mi reserva',                 'en' => 'View my booking status' ],
            'operator_note'   => [ 'es' => 'Nota del operador:',                       'en' => 'Note from the operator:' ],
            'extra_services'  => [ 'es' => 'Servicios extra',                          'en' => 'Extra services' ],
            'download_file'   => [ 'es' => 'Descargar archivo',                        'en' => 'Download file' ],
            'pay_now'         => [ 'es' => 'Pagar ahora',                              'en' => 'Pay now' ],
            'special_requests'=> [ 'es' => 'Pedidos especiales',                       'en' => 'Special requests' ],
            'reference'       => [ 'es' => 'Referencia',                               'en' => 'Reference' ],
            'client'          => [ 'es' => 'Cliente',                                  'en' => 'Client' ],
            'contact'         => [ 'es' => 'Contacto',                                 'en' => 'Contact' ],
            'approve'         => [ 'es' => 'Aprobar',                                  'en' => 'Approve' ],
            'reject'          => [ 'es' => 'Rechazar',                                 'en' => 'Reject' ],
        ];
        $entry = $dict[ $key ] ?? null;
        if ( ! $entry ) {
            return $key;
        }
        return $this->lang === 'es' ? $entry['es'] : $entry['en'];
    }

    protected function pax_summary(): string {
        $b    = $this->booking;
        $parts = [];
        if ( (int)$b->adults   > 0 ) $parts[] = $b->adults   . ' ' . $this->t('adults');
        if ( (int)$b->children > 0 ) $parts[] = $b->children . ' ' . $this->t('children');
        if ( (int)$b->babies   > 0 ) $parts[] = $b->babies   . ' ' . $this->t('babies');
        return implode( ', ', $parts );
    }

    /**
     * Fila extra en la tabla de la reserva con los servicios extra elegidos
     * (si hubo). Se busca acá y no en el JOIN de get_booking_with_tour()
     * porque es una relación 1-N — un JOIN duplicaría filas de la reserva.
     */
    protected function addons_row(): string {
        $booking_id = (int) ( $this->booking->id ?? 0 );
        if ( ! $booking_id ) {
            return '';
        }
        global $wpdb;
        // JOIN a amir_addons (no solo amir_booking_addons) para saber cuáles
        // son productos digitales — el link de descarga tokenizado (§ 16.23
        // CONTRIBUTING.md) va directo en esta misma línea, no en una pantalla
        // aparte: es lo único que distingue "entrega física" de "acá está tu
        // archivo" para el cliente.
        $addons = $wpdb->get_results( $wpdb->prepare(
            "SELECT ba.id, ba.name_snapshot, ba.qty, ba.total_mxn, a.pricing_type, a.digital_file_url
             FROM {$wpdb->prefix}amir_booking_addons ba
             LEFT JOIN {$wpdb->prefix}amir_addons a ON a.id = ba.addon_id
             WHERE ba.booking_id = %d ORDER BY ba.id",
            $booking_id
        ) ) ?? [];
        if ( empty( $addons ) ) {
            return '';
        }

        $access_token = $this->booking->access_token ?? '';
        $lines = array_map( function ( $a ) use ( $access_token ) {
            $label = esc_html( $a->name_snapshot ) . ( $a->qty > 1 ? ' × ' . (int) $a->qty : '' );
            $line  = $label . ' — ' . \AmirBooking\Core\Currency::format( (float) $a->total_mxn );
            if ( $a->pricing_type === 'digital' && ! empty( $a->digital_file_url ) && $access_token ) {
                $download_url = add_query_arg( [ 'token' => $access_token ], rest_url( 'flow/v1/addons/download/' . (int) $a->id ) );
                $line .= ' — <a href="' . esc_url( $download_url ) . '">' . esc_html( $this->t( 'download_file' ) ) . '</a>';
            }
            return $line;
        }, $addons );

        return '
          <tr>
            <td>' . $this->t( 'extra_services' ) . '</td>
            <td>' . implode( '<br>', $lines ) . '</td>
          </tr>';
    }

    protected function fmt_date( string $date ): string {
        static $months_es = [ 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre' ];
        static $months_en = [ 'January','February','March','April','May','June','July','August','September','October','November','December' ];
        $months = $this->lang === 'es' ? $months_es : $months_en;
        [ $y, $m, $d ] = explode( '-', $date );
        return (int)$d . ' ' . $months[ (int)$m - 1 ] . ' ' . $y;
    }

    /** Nullable a propósito — mismo fix defensivo que DashboardPage/BookingsPage::fmt_time() (bug real en producción, caliafarm.com 2026-08-04): una reserva sin horario (schedule_id sin fila correspondiente) no debe fatalear el email. */
    protected function fmt_time( ?string $time ): string {
        if ( ! $time ) {
            return '—';
        }
        [ $h, $m ] = explode( ':', $time );
        $hnum = (int) $h;
        $ampm = $hnum >= 12 ? 'PM' : 'AM';
        $h12  = $hnum > 12 ? $hnum - 12 : ( $hnum ?: 12 );
        return "{$h12}:{$m} {$ampm}";
    }

    /**
     * Sin lat/lng cargados en el tour, buscar el nombre de la empresa en vez
     * de un punto fijo de Bacalar — antes cualquier instalación sin
     * coordenadas mandaba el link "Ver en mapa" a Quintana Roo sin importar
     * dónde opere el negocio (bug real reportado 2026-08-05).
     */
    protected function maps_url(): string {
        $b = $this->booking;
        if ( $b->meeting_lat && $b->meeting_lng ) {
            return "https://maps.google.com/?q={$b->meeting_lat},{$b->meeting_lng}";
        }
        return 'https://maps.google.com/?q=' . rawurlencode( get_option( 'amir_company_name', 'TourFlow' ) );
    }

    protected function calendar_url(): string {
        $b     = $this->booking;
        $start = str_replace('-','',$b->tour_date) . 'T' . str_replace(':','',$b->time_start??'') . '00';
        $company = get_option( 'amir_company_name', 'TourFlow' );
        $title = rawurlencode( $b->tour_name . ' — ' . $company );
        $loc   = rawurlencode( $b->meeting_point_es ?? $company );
        return "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$start}/{$start}&location={$loc}";
    }

    protected function booking_info_table(): string {
        $b   = $this->booking;
        $mp  = \AmirBooking\Core\Languages::tour_field( $b, 'meeting_point', $this->lang );

        return '
        <table class="info-table">
          <tr>
            <td>' . $this->t('booking_ref') . '</td>
            <td><strong>' . esc_html( $b->booking_ref ) . '</strong></td>
          </tr>
          <tr>
            <td>' . $this->t('tour') . '</td>
            <td>' . esc_html( $b->tour_name ) . '</td>
          </tr>
          <tr>
            <td>' . $this->t('date') . '</td>
            <td>' . $this->fmt_date( $b->tour_date ) . '</td>
          </tr>
          <tr>
            <td>' . $this->t('time') . '</td>
            <td>' . $this->fmt_time( $b->time_start ?? '00:00' ) . '</td>
          </tr>
          <tr>
            <td>' . $this->t('people') . '</td>
            <td>' . $this->pax_summary() . '</td>
          </tr>
          <tr>
            <td>' . $this->t('total') . '</td>
            <td>' . \AmirBooking\Core\Currency::format( (float) $b->total_mxn ) . '</td>
          </tr>' . $this->addons_row() . '
          <tr>
            <td>' . $this->t('meeting') . '</td>
            <td>' . esc_html( $mp ?? '' ) . '<br>
              <a href="' . $this->maps_url() . '" style="color:' . esc_attr( $this->brand_color() ) . ';font-size:13px;">' . $this->t('maps_link') . ' ↗</a>
            </td>
          </tr>
        </table>';
    }
}

// ── Email de confirmación ─────────────────────────────────────────────────────

class ConfirmationEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'confirmation';
    }

    public static function text_fields(): array {
        return [
            'subject'       => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => '✅ Tu reserva está confirmada — {ref}', 'en' => '✅ Your booking is confirmed — {ref}' ],
            'intro_title'   => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => '¡Tu reserva está confirmada! 🎉', 'en' => 'Your booking is confirmed! 🎉' ],
            'intro_body'    => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Todo está listo para tu aventura. Aquí están los detalles de tu reserva:', 'en' => 'Hello <strong>{name}</strong>,<br>Everything is ready for your adventure. Here are your booking details:' ],
            'policy_title'  => [ 'label' => self::field_label( 'Título de la política de cancelación', 'Cancellation policy title' ), 'es' => 'Política de cancelación:', 'en' => 'Cancellation policy:' ],
            'policy_full'   => [ 'label' => self::field_label( 'Política — reembolso completo', 'Policy — full refund' ), 'es' => '7+ días antes: reembolso completo', 'en' => '7+ days before: full refund' ],
            'policy_partial'=> [ 'label' => self::field_label( 'Política — reembolso parcial', 'Policy — partial refund' ), 'es' => '3–6 días antes: reembolso del 50%', 'en' => '3–6 days before: 50% refund' ],
            'policy_none'   => [ 'label' => self::field_label( 'Política — sin reembolso', 'Policy — no refund' ), 'es' => 'Menos de 3 días: sin reembolso', 'en' => 'Less than 3 days before: no refund' ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b  = $this->booking;
        $wa = get_option( 'amir_wa_phone', '' );

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [ 'name' => esc_html( $b->customer_name ) ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html($b->booking_ref) . '</div>
        </div>';

        $recs_block = $this->recs_html(
            'amir_email_recs_es', 'amir_email_recs_en',
            [ 'Llega 10 minutos antes al punto de encuentro.', 'Usa ropa cómoda y protector solar biodegradable.', 'Trae agua y snacks ligeros.', 'Lleva tu documento de identidad.' ],
            [ 'Arrive 10 minutes before departure.', 'Wear comfortable clothes and biodegradable sunscreen.', 'Bring water and light snacks.', 'Carry a photo ID.' ]
        );

        $policy = '<div class="policy-box"><p><strong>' . $this->text( 'policy_title' ) . '</strong></p>'
                . '<p>✓ ' . $this->text( 'policy_full' ) . '</p>'
                . '<p>▸ ' . $this->text( 'policy_partial' ) . '</p>'
                . '<p>✕ ' . $this->text( 'policy_none' ) . '</p></div>';

        $pdf_url = rest_url( 'amir/v1/bookings/' . rawurlencode($b->booking_ref) . '/pdf' )
                   . '?token=' . rawurlencode( $b->access_token ?? '' );

        $actions = '
        <p style="text-align:center;margin-top:24px;">
          <a href="' . esc_url($pdf_url) . '" class="btn">' . $this->t('download_pdf') . '</a>
          &nbsp;&nbsp;
          <a href="' . $this->calendar_url() . '" class="btn btn-outline">' . $this->t('add_cal') . '</a>
        </p>
        <p style="text-align:center;font-size:13px;margin-top:12px;">
          <a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . esc_html( $this->t('view_status') ) . '</a>
        </p>' . ( $wa ? '
        <p style="text-align:center;font-size:13px;color:#5a7068;margin-top:8px;">
          ' . $this->t('wa_help') . ': <a href="https://wa.me/' . esc_attr( $wa ) . '" style="color:' . esc_attr( $this->brand_color() ) . ';">wa.me/' . esc_html( $wa ) . '</a>
        </p>' : '' );

        $custom_note = '';
        if ( ! empty( $b->custom_email_note ) ) {
            $custom_note = '<div style="background:#e8f5e9;border-left:4px solid ' . esc_attr( $this->brand_color() ) . ';padding:12px 16px;'
                         . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                         . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                         . '<strong>' . esc_html( $this->t('operator_note') ) . '</strong><br>'
                         . nl2br( esc_html( $b->custom_email_note ) )
                         . '</p></div>';
        }

        // Contenido extra por TOUR (Tarea 24 del roadmap, CONTRIBUTING.md
        // § 5.5/16.91) — ej. "este tour requiere pasaporte". Distinto de
        // $custom_note de arriba (nota puntual de ESTA reserva, cargada por
        // el operador al aprobar/confirmar) — esto lo carga el operador UNA
        // vez en el editor del tour y aplica a TODAS sus confirmaciones.
        // Solo aplica a reservas de tour (una de habitación no tiene tour_id).
        $tour_note = '';
        if ( ( $b->item_type ?? 'tour' ) === 'tour' && ! empty( $b->tour_id ) ) {
            global $wpdb;
            $col  = $this->lang === 'es' ? 'email_extra_note_es' : 'email_extra_note_en';
            $note = $wpdb->get_var( $wpdb->prepare(
                "SELECT {$col} FROM {$wpdb->prefix}amir_tours WHERE id = %d", (int) $b->tour_id
            ) );
            if ( ! empty( $note ) ) {
                $tour_note = '<div style="background:#fff7ed;border-left:4px solid #f97316;padding:12px 16px;'
                           . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                           . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                           . nl2br( esc_html( $note ) )
                           . '</p></div>';
            }
        }

        return $intro
            . $ref_box
            . $this->booking_info_table()
            . $custom_note
            . $tour_note
            . $recs_block
            . $policy
            . $actions;
    }
}

// ── Email recordatorio ────────────────────────────────────────────────────────

class ReminderEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'reminder';
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => '⏰ ¡Tu tour es mañana! — {tour}', 'en' => '⏰ Your tour is tomorrow! — {tour}' ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => '¡Tu aventura es mañana! ⛵', 'en' => 'Your adventure is tomorrow! ⛵' ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Un recordatorio de tu reserva para mañana:', 'en' => 'Hello <strong>{name}</strong>,<br>A reminder about your booking for tomorrow:' ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'tour' => $this->booking->tour_name ] );
    }

    protected function get_body_content(): string {
        $b  = $this->booking;
        $wa = get_option( 'amir_wa_phone', '' );

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [ 'name' => esc_html( $b->customer_name ) ] ) . '</p>';

        $recs_block = $this->recs_html(
            'amir_email_recs_es', 'amir_email_recs_en',
            [ 'Ropa cómoda y traje de baño', 'Protector solar biodegradable (obligatorio en la laguna)', 'Agua y snacks ligeros', 'Documento de identidad', 'Cámara o celular en bolsa impermeable' ],
            [ 'Comfortable clothes and swimsuit', 'Biodegradable sunscreen (required on the lagoon)', 'Water and light snacks', 'Photo ID', 'Camera or phone in a waterproof bag' ]
        );

        $footer_wa = $wa ? '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">' . $this->t('wa_help') . ': <a href="https://wa.me/' . esc_attr( $wa ) . '" style="color:' . esc_attr( $this->brand_color() ) . ';">wa.me/' . esc_html( $wa ) . '</a></p>' : '';

        return $intro
            . $this->booking_info_table()
            . $recs_block
            . $footer_wa;
    }
}

// ── Email solicitud de reseña ─────────────────────────────────────────────────

class ReviewEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'review';
    }

    public static function text_fields(): array {
        return [
            'subject'          => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => '⭐ ¿Cómo fue tu experiencia? — {company}', 'en' => '⭐ How was your experience? — {company}' ],
            'intro_title'      => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => '¿Disfrutaste tu aventura? 🌊', 'en' => 'Did you enjoy your adventure? 🌊' ],
            'intro_body'       => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Esperamos que hayas tenido una experiencia increíble con nosotros en <em>{tour}</em>.', 'en' => 'Hello <strong>{name}</strong>,<br>We hope you had an amazing experience with us at <em>{tour}</em>.' ],
            'ask_body'         => [ 'label' => self::field_label( 'Pedido de reseña', 'Review request' ), 'es' => 'Tu opinión nos ayuda a seguir mejorando y a que más viajeros nos descubran. Si tienes un minuto, nos encantaría que compartieras tu experiencia:', 'en' => "Your feedback helps us keep improving and helps more travelers find us. If you have a minute, we'd love for you to share your experience:" ],
            'cta_tripadvisor'  => [ 'label' => self::field_label( 'Botón — TripAdvisor', 'Button — TripAdvisor' ), 'es' => 'Reseña en TripAdvisor', 'en' => 'Review on TripAdvisor' ],
            'cta_google'       => [ 'label' => self::field_label( 'Botón — Google', 'Button — Google' ), 'es' => 'Reseña en Google', 'en' => 'Review on Google' ],
            'closing'          => [ 'label' => self::field_label( 'Cierre', 'Closing' ), 'es' => '¡Gracias por elegirnos! Esperamos verte de nuevo pronto. 🐊', 'en' => 'Thanks for choosing us! We hope to see you again soon. 🐊' ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'company' => get_option( 'amir_company_name', 'TourFlow' ) ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        // Default vacío, NUNCA la página real de Amir Adventours — antes
        // cualquier instalación sin su propia URL configurada mandaba a los
        // clientes a dejar reseña en el negocio de OTRO operador (bug real
        // reportado 2026-08-05, mismo patrón que el footer hardcodeado de
        // arriba). Cada botón se oculta si no hay URL configurada, en vez
        // de mostrar un link roto/ajeno.
        $tripadvisor_url = get_option( 'amir_tripadvisor_review_url', '' );
        $google_url      = get_option( 'amir_google_review_url', '' );

        $ta_btn     = $tripadvisor_url ? '<a href="' . esc_url( $tripadvisor_url ) . '" class="btn">⭐ ' . $this->text( 'cta_tripadvisor' ) . '</a>' : '';
        $google_btn = $google_url      ? '<a href="' . esc_url( $google_url )      . '" class="btn btn-outline">⭐ ' . $this->text( 'cta_google' ) . '</a>' : '';
        $buttons    = trim( $ta_btn . ( $ta_btn && $google_btn ? '&nbsp;&nbsp;' : '' ) . $google_btn );

        return '<h1>' . $this->text( 'intro_title' ) . '</h1>
        <p>' . $this->text( 'intro_body', [ 'name' => esc_html( $b->customer_name ), 'tour' => esc_html( $b->tour_name ) ] ) . '</p>
        <p>' . $this->text( 'ask_body' ) . '</p>'
        . ( $buttons ? '<p style="text-align:center;margin:24px 0;">' . $buttons . '</p>' : '' )
        . '<p style="font-size:13px;color:#5a7068;text-align:center;">' . $this->text( 'closing' ) . '</p>';
    }
}

// ── Email cancelación ─────────────────────────────────────────────────────────

class CancellationEmail extends BaseEmail {

    private string $reason_type;

    public function __construct( object $booking, string $reason_type ) {
        parent::__construct( $booking );
        $this->reason_type = $reason_type;
    }

    protected static function type_key(): string {
        return 'cancellation';
    }

    public static function text_fields(): array {
        return [
            'subject'               => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'Cancelación de reserva — {ref}', 'en' => 'Booking cancellation — {ref}' ],
            'weather_title'         => [ 'label' => self::field_label( 'Título — cancelado por clima', 'Title — cancelled due to weather' ), 'es' => 'Tour cancelado por condiciones climáticas ⛈', 'en' => 'Tour cancelled due to weather ⛈' ],
            'min_pax_title'         => [ 'label' => self::field_label( 'Título — mínimo de pasajeros no alcanzado', 'Title — minimum passengers not reached' ), 'es' => 'Tour cancelado — mínimo de pasajeros no alcanzado', 'en' => 'Tour cancelled — minimum passengers not reached' ],
            'operator_msg'          => [ 'label' => self::field_label( 'Mensaje — cancelado por el operador', 'Message — cancelled by the operator' ), 'es' => 'Hola <strong>{name}</strong>,<br>Lamentamos informarte que tu reserva <strong>{ref}</strong> ha sido cancelada. Se ha procesado un <strong>reembolso completo</strong> que aparecerá en tu cuenta en 3–5 días hábiles.', 'en' => "Hello <strong>{name}</strong>,<br>We're sorry to let you know that your booking <strong>{ref}</strong> has been cancelled. A <strong>full refund</strong> has been processed and will appear in your account within 3–5 business days." ],
            'provider_title'        => [ 'label' => self::field_label( 'Título — no se pudo confirmar (proveedor)', 'Title — could not be confirmed (provider)' ), 'es' => 'No pudimos confirmar tu reserva', 'en' => "We couldn't confirm your booking" ],
            'provider_expired_msg'  => [ 'label' => self::field_label( 'Mensaje — el proveedor no respondió', 'Message — the provider did not respond' ), 'es' => 'Hola <strong>{name}</strong>,<br>El operador local no respondió a tiempo para confirmar tu reserva <strong>{ref}</strong>. Se ha procesado un <strong>reembolso completo</strong> que aparecerá en tu cuenta en 3–5 días hábiles.', 'en' => "Hello <strong>{name}</strong>,<br>The local operator didn't respond in time to confirm your booking <strong>{ref}</strong>. A <strong>full refund</strong> has been processed and will appear in your account within 3–5 business days." ],
            'provider_rejected_msg' => [ 'label' => self::field_label( 'Mensaje — el proveedor rechazó', 'Message — the provider rejected it' ), 'es' => 'Hola <strong>{name}</strong>,<br>El operador local no pudo confirmar tu reserva <strong>{ref}</strong>.{reason_note} Se ha procesado un <strong>reembolso completo</strong> que aparecerá en tu cuenta en 3–5 días hábiles.', 'en' => "Hello <strong>{name}</strong>,<br>The local operator couldn't confirm your booking <strong>{ref}</strong>.{reason_note} A <strong>full refund</strong> has been processed and will appear in your account within 3–5 business days." ],
            'reason_note'           => [ 'label' => self::field_label( 'Nota con el motivo del proveedor', 'Note with the provider\'s reason' ), 'es' => ' El operador indicó: "{reason}".', 'en' => ' The operator said: "{reason}".' ],
            'client_title'          => [ 'label' => self::field_label( 'Título — cancelada por el cliente', 'Title — cancelled by the customer' ), 'es' => 'Reserva cancelada', 'en' => 'Booking cancelled' ],
            'client_msg'            => [ 'label' => self::field_label( 'Mensaje — cancelada por el cliente', 'Message — cancelled by the customer' ), 'es' => 'Hola <strong>{name}</strong>,<br>Tu reserva <strong>{ref}</strong> ha sido cancelada. {refund_msg}', 'en' => 'Hello <strong>{name}</strong>,<br>Your booking <strong>{ref}</strong> has been cancelled. {refund_msg}' ],
            'refund_full'           => [ 'label' => self::field_label( 'Reembolso — completo', 'Refund — full' ), 'es' => 'Se ha procesado un reembolso completo que aparecerá en tu cuenta en 3–5 días hábiles.', 'en' => 'A full refund has been processed and will appear in your account within 3–5 business days.' ],
            'refund_partial'        => [ 'label' => self::field_label( 'Reembolso — parcial (50%)', 'Refund — partial (50%)' ), 'es' => 'Se ha procesado un reembolso del 50% por {amount}.', 'en' => 'A 50% refund of {amount} has been processed.' ],
            'refund_none'           => [ 'label' => self::field_label( 'Reembolso — no aplica', 'Refund — not applicable' ), 'es' => 'De acuerdo con nuestra política, no aplica reembolso para cancelaciones dentro de los 2 días previos al tour.', 'en' => 'Per our policy, no refund applies for cancellations within 2 days of the tour.' ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        $is_operator = in_array( $this->reason_type, [ 'weather', 'min_pax' ], true );
        $is_provider = in_array( $this->reason_type, [ 'provider_rejected', 'provider_expired' ], true );

        if ( $is_operator ) {
            $title = '<h1>' . ( $this->reason_type === 'weather' ? $this->text('weather_title') : $this->text('min_pax_title') ) . '</h1>';
            $msg = '<p>' . $this->text( 'operator_msg', [
                'name' => esc_html( $b->customer_name ), 'ref' => esc_html( $b->booking_ref ),
            ] ) . '</p>';
        } elseif ( $is_provider ) {
            // Reserva de un tour operado por un proveedor externo (marketplace,
            // § 11 CONTRIBUTING.md) — el proveedor rechazó, o venció el plazo de
            // respuesta sin contestar. En ambos casos, reembolso 100% (no es
            // responsabilidad del cliente). No se nombra al proveedor.
            $title = '<h1>' . $this->text('provider_title') . '</h1>';
            if ( $this->reason_type === 'provider_expired' ) {
                $msg = '<p>' . $this->text( 'provider_expired_msg', [
                    'name' => esc_html( $b->customer_name ), 'ref' => esc_html( $b->booking_ref ),
                ] ) . '</p>';
            } else {
                $reason_note = ! empty( $b->provider_reject_reason )
                    ? $this->text( 'reason_note', [ 'reason' => esc_html( $b->provider_reject_reason ) ] )
                    : '';
                $msg = '<p>' . $this->text( 'provider_rejected_msg', [
                    'name' => esc_html( $b->customer_name ), 'ref' => esc_html( $b->booking_ref ), 'reason_note' => $reason_note,
                ] ) . '</p>';
            }
        } else {
            $title = '<h1>' . $this->text('client_title') . '</h1>';
            $policy = (int) $b->cancellation_policy_pct;
            $refund_msg = $policy === 0
                ? $this->text('refund_full')
                : ( $policy === 50
                    ? $this->text( 'refund_partial', [ 'amount' => \AmirBooking\Core\Currency::format( (float) $b->refund_amount_mxn ) ] )
                    : $this->text('refund_none') );
            $msg = '<p>' . $this->text( 'client_msg', [
                'name' => esc_html( $b->customer_name ), 'ref' => esc_html( $b->booking_ref ), 'refund_msg' => $refund_msg,
            ] ) . '</p>';
        }

        $wa     = get_option( 'amir_wa_phone', '' );
        $footer = $wa ? '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">' . $this->t('wa_help') . ': <a href="https://wa.me/' . esc_attr( $wa ) . '" style="color:' . esc_attr( $this->brand_color() ) . ';">wa.me/' . esc_html( $wa ) . '</a></p>' : '';

        return $title . $msg . $footer;
    }
}

// ── Email: tour de la lista de interés abrió ────────────────────────────────

class TourOpenedEmail extends BaseEmail {

    /**
     * Sin emoji ni "completá tu pago" en el asunto — la combinación
     * entusiasmo + pedido de pago + link es un patrón clásico que Gmail
     * suele filtrar como spam en plantillas nuevas sin historial de envío,
     * aunque wp_mail() devuelva éxito (confirmado: el mismo mecanismo que
     * confirmación/reprogramación, que sí llegan, con este único cambio
     * de contenido). El CTA de pago queda solo en el cuerpo, como en el
     * resto de los emails transaccionales del sistema.
     */
    protected static function type_key(): string {
        return 'tour_opened';
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => '{tour} ya tiene fecha confirmada', 'en' => '{tour} now has a confirmed date' ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => '¡Buenas noticias!', 'en' => 'Good news!' ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Nos pediste que te avisáramos cuando <strong>{tour}</strong> abriera — y ya está disponible. Tu lugar para el {date} está reservado — completá el pago para confirmarlo.', 'en' => "Hello <strong>{name}</strong>,<br>You asked us to let you know when <strong>{tour}</strong> opened — and it's now available. Your spot for {date} is reserved — complete the payment to confirm it." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'tour' => $this->booking->tour_name ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [
                   'name' => esc_html( $b->customer_name ), 'tour' => esc_html( $b->tour_name ), 'date' => esc_html( $this->fmt_date( $b->tour_date ) ),
               ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        return $intro . $ref_box . '<p style="text-align:center;margin-top:24px;">'
             . '<a href="' . esc_url( $this->verify_url() ) . '" class="btn">' . esc_html( $this->t('pay_now') ) . '</a>'
             . '</p>';
    }
}

// ── Email: reserva manual cargada por el operador, pendiente de pago ───────

class PaymentLinkEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'payment_link';
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'Completá el pago de tu reserva — {ref}', 'en' => 'Complete payment for your booking — {ref}' ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => 'Tu reserva está lista 🎉', 'en' => 'Your booking is ready 🎉' ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Ya armamos tu reserva para <strong>{tour}</strong> el {date} — solo falta completar el pago para confirmarla.', 'en' => "Hello <strong>{name}</strong>,<br>We've set up your booking for <strong>{tour}</strong> on {date} — just complete the payment to confirm it." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [
                   'name' => esc_html( $b->customer_name ), 'tour' => esc_html( $b->tour_name ), 'date' => esc_html( $this->fmt_date( $b->tour_date ) ),
               ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        $custom_note = '';
        if ( ! empty( $b->custom_email_note ) ) {
            $custom_note = '<div style="background:#e8f5e9;border-left:4px solid ' . esc_attr( $this->brand_color() ) . ';padding:12px 16px;'
                         . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                         . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                         . '<strong>' . esc_html( $this->t('operator_note') ) . '</strong><br>'
                         . nl2br( esc_html( $b->custom_email_note ) )
                         . '</p></div>';
        }

        return $intro . $ref_box . $custom_note . '<p style="text-align:center;margin-top:24px;">'
             . '<a href="' . esc_url( $this->verify_url() ) . '" class="btn">' . esc_html( $this->t('pay_now') ) . '</a>'
             . '</p>';
    }
}

// ── Email: reserva reprogramada ─────────────────────────────────────────────
// Bug real encontrado probando en vivo: reprogramar desde el admin actualizaba
// la reserva pero nunca avisaba al cliente — ver send_reschedule_notice().

class RescheduleEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'reschedule';
    }

    public static function text_fields(): array {
        return [
            'subject'       => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => '🔄 Tu reserva fue reprogramada — {ref}', 'en' => '🔄 Your booking was rescheduled — {ref}' ],
            'intro_title'   => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => 'Tu reserva cambió de fecha', 'en' => 'Your booking date has changed' ],
            'intro_body'    => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Tu reserva <strong>{ref}</strong> fue reprogramada. Estos son los nuevos detalles:', 'en' => 'Hello <strong>{name}</strong>,<br>Your booking <strong>{ref}</strong> was rescheduled. Here are the new details:' ],
            'footer_prompt' => [ 'label' => self::field_label( 'Pie', 'Footer' ), 'es' => '¿La nueva fecha no te sirve? Escribinos y lo resolvemos.', 'en' => "Does the new date not work for you? Message us and we'll sort it out." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [ 'name' => esc_html( $b->customer_name ), 'ref' => esc_html( $b->booking_ref ) ] ) . '</p>';

        $wa     = get_option( 'amir_wa_phone', '' );
        $footer = $wa ? '<p style="text-align:center;margin-top:24px;font-size:13px;color:#5a7068;">'
                . $this->text( 'footer_prompt' )
                . ' ' . $this->t('wa_help') . ': <a href="https://wa.me/' . esc_attr( $wa ) . '" style="color:' . esc_attr( $this->brand_color() ) . ';">wa.me/' . esc_html( $wa ) . '</a></p>' : '';

        return $intro . $this->booking_info_table() . $footer;
    }
}

// ── Email: proveedor externo, nueva reserva a confirmar ──────────────────────
// Marketplace de proveedores (§ 11 CONTRIBUTING.md). Se envía al proveedor
// (no al cliente — usa $to_override) con los datos de contacto completos del
// cliente y los links de Aprobar/Rechazar tokenizados. Reusado tal cual para
// el recordatorio a las 24h (mismo token, no se regenera).

class ProviderNoticeEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'provider_notice';
    }

    public static function text_fields(): array {
        return [
            'subject'       => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'Nueva reserva por confirmar — {ref}', 'en' => 'New booking to confirm — {ref}' ],
            'intro_title'   => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => 'Nueva reserva para confirmar disponibilidad', 'en' => 'New booking to confirm availability' ],
            'intro_body'    => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola {name},<br>Recibiste una nueva reserva desde TourFlow para <strong>{tour}</strong>. Por favor confirmá si tenés disponibilidad.', 'en' => 'Hello {name},<br>You received a new booking from TourFlow for <strong>{tour}</strong>. Please confirm whether you have availability.' ],
            'deadline_note' => [ 'label' => self::field_label( 'Aviso de vencimiento', 'Expiration notice' ), 'es' => 'Si no respondés dentro de {hours} horas, la reserva se cancelará automáticamente y se reembolsará al cliente.', 'en' => "If you don't respond within {hours} hours, the booking will be automatically cancelled and refunded to the client." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;
        $greeting_name = $b->provider_contact_name ?: $b->provider_business_name;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [ 'name' => esc_html( $greeting_name ), 'tour' => esc_html( $b->tour_name ) ] ) . '</p>';

        $special = '';
        if ( ! empty( $b->special_requests ) ) {
            $special = '<tr><td>' . $this->t('special_requests') . '</td><td>' . nl2br( esc_html( $b->special_requests ) ) . '</td></tr>';
        }

        $details = '
        <table class="info-table">
          <tr><td>' . $this->t('reference') . '</td><td>' . esc_html( $b->booking_ref ) . '</td></tr>
          <tr><td>' . $this->t('date') . '</td><td>' . $this->fmt_date( $b->tour_date ) . '</td></tr>
          <tr><td>' . $this->t('time') . '</td><td>' . ( $b->time_start ? $this->fmt_time( $b->time_start ) : '—' ) . '</td></tr>
          <tr><td>' . $this->t('people') . '</td><td>' . $this->pax_summary() . '</td></tr>
          <tr><td>' . $this->t('client') . '</td><td>' . esc_html( $b->customer_name ) . '</td></tr>
          <tr><td>' . $this->t('contact') . '</td><td>' . esc_html( $b->customer_email ) . ( $b->customer_phone ? ' / ' . esc_html( $b->customer_phone ) : '' ) . '</td></tr>'
          . $special . '
        </table>';

        $response_hours = (int) get_option( 'amir_provider_response_hours', 48 );
        $deadline_note = '<p style="font-size:13px;color:#5a7068;">' . $this->text( 'deadline_note', [ 'hours' => $response_hours ] ) . '</p>';

        $actions = '<p style="text-align:center;margin-top:24px;">'
            . '<a href="' . esc_url( $this->provider_action_url( 'approve' ) ) . '" class="btn">✅ ' . $this->t('approve') . '</a>'
            . '&nbsp;&nbsp;'
            . '<a href="' . esc_url( $this->provider_action_url( 'reject' ) ) . '" class="btn btn-outline">❌ ' . $this->t('reject') . '</a>'
            . '</p>';

        return $intro . $details . $deadline_note . $actions;
    }

    /**
     * Link a la pantalla de confirmación en /proveedor-reserva/ (no ejecuta
     * la acción directo — evita que scanners de email/antivirus corporativos
     * disparen la aprobación/rechazo por prefetch). Ver Shortcodes::provider_action().
     */
    private function provider_action_url( string $do ): string {
        $page_id = (int) get_option( 'amir_provider_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : false;
        $base    = $base ?: ( get_site_url() . '/proveedor-reserva/' );

        return add_query_arg(
            [
                'ref'   => $this->booking->booking_ref,
                'token' => $this->booking->provider_response_token ?? '',
                'do'    => $do,
            ],
            $base
        );
    }
}

// ── Email: aviso interino al cliente mientras se confirma con el proveedor ──
// Su pago ya se procesó — este correo evita que se pregunte por qué no
// recibió la confirmación habitual. No nombra al proveedor (aviso discreto,
// mismo criterio que el badge de la ficha del tour).

class ProviderPendingNoticeEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'provider_pending';
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'Estamos confirmando tu reserva — {ref}', 'en' => "We're confirming your booking — {ref}" ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => 'Tu reserva fue recibida', 'en' => 'Your booking was received' ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Tu pago se procesó correctamente. Estamos confirmando disponibilidad con el operador local para tu tour del {date} — te avisamos en cuanto quede confirmada.', 'en' => "Hello <strong>{name}</strong>,<br>Your payment was processed successfully. We're confirming availability with the local operator for your tour on {date} — we'll let you know as soon as it's confirmed." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [
                   'name' => esc_html( $b->customer_name ), 'date' => esc_html( $this->fmt_date( $b->tour_date ) ),
               ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        $footer = '<p style="text-align:center;font-size:13px;margin-top:12px;">'
            . '<a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . esc_html( $this->t('view_status') ) . '</a>'
            . '</p>';

        return $intro . $ref_box . $footer;
    }
}

/**
 * Aviso al cliente de que su solicitud de fecha se recibió — sin cobrar
 * todavía. Cubre los dos orígenes que disparan amir_booking_date_requested:
 * tours "solo a pedido" (BookingManager::create_pending()) y "solicitar
 * otra fecha" en un tour de fecha fija (BookingManager::create_date_request()).
 * Mismo texto que ya usa el widget (own_request_sent_title/own_request_sent_sub
 * en i18n.js) para no prometer algo distinto por email de lo que ya vio en
 * pantalla.
 */
class DateRequestedEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'date_requested';
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'Recibimos tu solicitud — {ref}', 'en' => 'We received your request — {ref}' ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => '¡Solicitud recibida!', 'en' => 'Request received!' ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Recibimos tu solicitud para <strong>{tour}</strong> el {date}. Todavía no te cobramos nada — te confirmamos disponibilidad y te mandamos el link de pago por email en cuanto la revisemos.', 'en' => "Hello <strong>{name}</strong>,<br>We received your request for <strong>{tour}</strong> on {date}. We haven't charged you anything yet — we'll confirm availability and email you the payment link once we review it." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [
                   'name' => esc_html( $b->customer_name ),
                   'tour' => esc_html( $b->tour_name ?? '' ),
                   'date' => esc_html( $this->fmt_date( $b->tour_date ) ),
               ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        $footer = '<p style="text-align:center;font-size:13px;margin-top:12px;">'
            . '<a href="' . esc_url( $this->verify_url() ) . '" style="color:#5a7068;">' . esc_html( $this->t('view_status') ) . '</a>'
            . '</p>';

        return $intro . $ref_box . $footer;
    }
}

/**
 * Aviso al cliente cuando el operador rechaza una solicitud de fecha (tour
 * de fecha fija que pidió otra fecha, o tour "solo a pedido") — bug real
 * reportado por el cliente (2026-08-14): rechazar una solicitud no le
 * avisaba nada al cliente. Incluye el mensaje opcional del operador
 * (`custom_email_note`, mismo campo/caja que ya usan ConfirmationEmail y
 * CancellationEmail para "Nota del operador") con el motivo o una
 * sugerencia alternativa.
 */
class DateRequestRejectedEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'date_request_rejected';
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'No podemos confirmar tu solicitud — {ref}', 'en' => "We can't confirm your request — {ref}" ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => 'Tu solicitud no fue posible', 'en' => "Your request wasn't possible" ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>No pudimos confirmar tu solicitud para <strong>{tour}</strong> el {date}. No te cobramos nada.', 'en' => "Hello <strong>{name}</strong>,<br>We weren't able to confirm your request for <strong>{tour}</strong> on {date}. You haven't been charged anything." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [
                   'name' => esc_html( $b->customer_name ),
                   'tour' => esc_html( $b->tour_name ?? '' ),
                   'date' => esc_html( $this->fmt_date( $b->tour_date ) ),
               ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        // Mismo patrón exacto que ConfirmationEmail/CancellationEmail para
        // el mensaje libre del operador — no inventar una caja nueva.
        $custom_note = '';
        if ( ! empty( $b->custom_email_note ) ) {
            $custom_note = '<div style="background:#e8f5e9;border-left:4px solid ' . esc_attr( $this->brand_color() ) . ';padding:12px 16px;'
                         . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                         . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                         . '<strong>' . esc_html( $this->t('operator_note') ) . '</strong><br>'
                         . nl2br( esc_html( $b->custom_email_note ) )
                         . '</p></div>';
        }

        $footer = '<p style="text-align:center;font-size:13px;margin-top:12px;">'
            . '<a href="' . esc_url( get_site_url() . ( $this->lang === \AmirBooking\Core\Languages::default_lang() ? '/tours/' : "/{$this->lang}/" ) ) . '" style="color:#5a7068;">' . esc_html( $this->lang === 'es' ? 'Ver otros tours' : 'Browse other tours' ) . '</a>'
            . '</p>';

        return $intro . $ref_box . $custom_note . $footer;
    }
}

/**
 * Link de pago para el SALDO restante de un depósito parcial ("Depósito
 * parcial por tour") — la reserva ya está confirmada (el depósito ya se
 * cobró), esto es para cobrar el resto online en vez de en persona.
 * Wording distinto de PaymentLinkEmail a propósito: ahí la reserva todavía
 * no está confirmada, acá sí — "completá el pago" sería engañoso.
 */
class BalancePaymentLinkEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'balance_payment_link';
    }

    private function balance_mxn(): float {
        $deposit_charged = round( (float) $this->booking->total_mxn * (int) ( $this->booking->deposit_pct ?? 0 ) / 100, 2 );
        return round( (float) $this->booking->total_mxn - $deposit_charged, 2 );
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'Saldo pendiente de tu reserva — {ref}', 'en' => 'Remaining balance for your booking — {ref}' ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => 'Falta el saldo de tu reserva', 'en' => 'Your booking has a remaining balance' ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Tu reserva para <strong>{tour}</strong> el {date} ya está confirmada — solo falta el saldo restante de <strong>{balance}</strong>.', 'en' => 'Hello <strong>{name}</strong>,<br>Your booking for <strong>{tour}</strong> on {date} is already confirmed — you just have a remaining balance of <strong>{balance}</strong>.' ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [
                   'name'    => esc_html( $b->customer_name ),
                   'tour'    => esc_html( $b->tour_name ?? '' ),
                   'date'    => esc_html( $this->fmt_date( $b->tour_date ) ),
                   'balance' => esc_html( \AmirBooking\Core\Currency::format( $this->balance_mxn() ) ),
               ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        $custom_note = '';
        if ( ! empty( $b->custom_email_note ) ) {
            $custom_note = '<div style="background:#e8f5e9;border-left:4px solid ' . esc_attr( $this->brand_color() ) . ';padding:12px 16px;'
                         . 'margin:16px 0;border-radius:0 8px 8px 0;">'
                         . '<p style="font-size:14px;color:#1a2e24;margin:0;">'
                         . '<strong>' . esc_html( $this->t('operator_note') ) . '</strong><br>'
                         . nl2br( esc_html( $b->custom_email_note ) )
                         . '</p></div>';
        }

        return $intro . $ref_box . $custom_note . '<p style="text-align:center;margin-top:24px;">'
             . '<a href="' . esc_url( $this->verify_url() ) . '" class="btn">' . esc_html( $this->t('pay_now') ) . '</a>'
             . '</p>';
    }
}

/**
 * Recibo corto cuando el cliente paga el saldo restante (vía el link de
 * arriba, o registrado a mano por el operador desde "💰 Marcar saldo
 * cobrado" — en ese caso este email NO se manda, ver
 * BookingManager::confirm(), el registro manual no dispara
 * amir_booking_balance_paid con intención de avisar, es solo un asiento
 * interno). A propósito NO reusa ConfirmationEmail — la reserva ya estaba
 * confirmada, reenviar "¡reserva confirmada!" completo sería confuso.
 */
class BalancePaidEmail extends BaseEmail {

    protected static function type_key(): string {
        return 'balance_paid';
    }

    public static function text_fields(): array {
        return [
            'subject'     => [ 'label' => self::field_label( 'Asunto', 'Subject' ), 'es' => 'Recibimos tu pago — {ref}', 'en' => 'We received your payment — {ref}' ],
            'intro_title' => [ 'label' => self::field_label( 'Título', 'Title' ), 'es' => '¡Saldo recibido! ✅', 'en' => 'Balance received! ✅' ],
            'intro_body'  => [ 'label' => self::field_label( 'Saludo', 'Greeting' ), 'es' => 'Hola <strong>{name}</strong>,<br>Recibimos el saldo restante de tu reserva para <strong>{tour}</strong> el {date}. Ya está todo pago — nos vemos pronto.', 'en' => "Hello <strong>{name}</strong>,<br>We received the remaining balance for your booking for <strong>{tour}</strong> on {date}. Everything is paid — see you soon." ],
        ];
    }

    protected function get_subject(): string {
        return $this->text( 'subject', [ 'ref' => $this->booking->booking_ref ] );
    }

    protected function get_body_content(): string {
        $b = $this->booking;

        $intro = '<h1>' . $this->text( 'intro_title' ) . '</h1>'
               . '<p>' . $this->text( 'intro_body', [
                   'name' => esc_html( $b->customer_name ),
                   'tour' => esc_html( $b->tour_name ?? '' ),
                   'date' => esc_html( $this->fmt_date( $b->tour_date ) ),
               ] ) . '</p>';

        $ref_box = '<div class="ref-box">
          <div class="ref-label">' . $this->t('booking_ref') . '</div>
          <div class="ref-value">' . esc_html( $b->booking_ref ) . '</div>
        </div>';

        return $intro . $ref_box;
    }
}
