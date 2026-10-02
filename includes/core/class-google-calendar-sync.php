<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Sincronización con Google Calendar — un solo sentido (TourFlow → Calendar,
 * § 15.4/§ 16 CONTRIBUTING.md). Al confirmarse una reserva (tour o
 * habitación) crea/actualiza un evento; al cancelarse, lo borra. Cada
 * instalación conecta su PROPIO proyecto de Google Cloud (Client ID/Secret
 * propios, cargados en Configuración) — nunca una credencial compartida del
 * plugin, mismo criterio que Stripe/Mercado Pago.
 *
 * Bidireccional (que un bloqueo manual en Calendar también bloquee
 * disponibilidad en TourFlow) queda fuera de alcance a propósito — es lo
 * que ya se había decidido en § 15.4.
 */
class GoogleCalendarSync {

	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const API_BASE  = 'https://www.googleapis.com/calendar/v3';
	private const SCOPE     = 'https://www.googleapis.com/auth/calendar.events';

	public static function register_hooks(): void {
		add_action( 'admin_post_amir_google_oauth_callback', [ self::class, 'handle_oauth_callback' ] );
		add_action( 'amir_booking_confirmed', [ self::class, 'push_booking' ], 20 );
		add_action( 'flow_room_booking_confirmed', [ self::class, 'push_booking' ], 20 );
		add_action( 'amir_booking_cancelled', [ self::class, 'delete_booking_event' ], 20 );
		// Reprogramar (BookingManager::reschedule()) cambia fecha/horario de una
		// reserva ya confirmada — sin esto, el evento en Calendar quedaba con
		// la fecha vieja para siempre. push_booking() ya actualiza si
		// google_calendar_event_id existe (no crea uno duplicado).
		add_action( 'amir_booking_rescheduled', [ self::class, 'push_booking' ], 20 );
		add_action( 'flow_room_booking_rescheduled', [ self::class, 'push_booking' ], 20 );
	}

	public static function is_connected(): bool {
		return get_option( 'amir_google_refresh_token', '' ) !== '';
	}

	// ── OAuth ─────────────────────────────────────────────────────────────

	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=amir_google_oauth_callback' );
	}

	public static function authorize_url(): string {
		$client_id = get_option( 'amir_google_client_id', '' );
		if ( ! $client_id ) {
			return '';
		}
		return self::AUTH_URL . '?' . http_build_query( [
			'client_id'     => $client_id,
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			// 'consent' fuerza que Google vuelva a mandar refresh_token
			// incluso si esta instalación ya había autorizado antes — sin
			// esto, una reconexión (ej. tras "Desconectar") puede quedar
			// sin refresh_token nuevo.
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'amir_google_oauth' ),
		] );
	}

	/** Callback de admin-post.php — Google redirige acá con ?code=... tras la pantalla de consentimiento. */
	public static function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos suficientes.' );
		}
		if ( ! isset( $_GET['state'] ) || ! wp_verify_nonce( $_GET['state'], 'amir_google_oauth' ) ) {
			wp_die( 'Solicitud inválida (nonce).' );
		}

		$code = sanitize_text_field( $_GET['code'] ?? '' );
		if ( ! $code ) {
			self::redirect_to_settings( 'google_error', 'El usuario canceló o Google no envió un código de autorización.' );
			return;
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 30,
			'body'    => [
				'code'          => $code,
				'client_id'     => get_option( 'amir_google_client_id', '' ),
				'client_secret' => get_option( 'amir_google_client_secret', '' ),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );

		if ( is_wp_error( $response ) ) {
			self::redirect_to_settings( 'google_error', $response->get_error_message() );
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['refresh_token'] ) ) {
			// Sin refresh_token: probablemente ya estaba conectado y Google
			// no lo reenvía sin 'prompt=consent' — igual guardamos el
			// access_token de corta duración para no dejar la conexión rota,
			// pero avisamos del problema real.
			self::redirect_to_settings( 'google_error', $data['error_description'] ?? 'Google no devolvió un refresh_token. Intenta desconectar y volver a conectar.' );
			return;
		}

		update_option( 'amir_google_refresh_token', $data['refresh_token'] );
		delete_transient( 'amir_google_access_token' );
		self::redirect_to_settings( 'google_connected', '' );
	}

	private static function redirect_to_settings( string $flag, string $error ): void {
		$args = [ 'page' => 'amir-settings', $flag => '1' ];
		if ( $error ) {
			set_transient( 'amir_google_last_error', $error, MINUTE_IN_SECONDS * 5 );
		}
		wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $args ) ) );
		exit;
	}

	public static function disconnect(): void {
		delete_option( 'amir_google_refresh_token' );
		delete_transient( 'amir_google_access_token' );
	}

	/** Access token de corta duración, cacheado — se pide uno nuevo con el refresh_token cuando expira. */
	private static function get_access_token(): ?string {
		$cached = get_transient( 'amir_google_access_token' );
		if ( $cached ) {
			return $cached;
		}

		$refresh_token = get_option( 'amir_google_refresh_token', '' );
		if ( ! $refresh_token ) {
			return null;
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 30,
			'body'    => [
				'refresh_token' => $refresh_token,
				'client_id'     => get_option( 'amir_google_client_id', '' ),
				'client_secret' => get_option( 'amir_google_client_secret', '' ),
				'grant_type'    => 'refresh_token',
			],
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'TourFlow Google Calendar: error al refrescar el token — ' . $response->get_error_message() );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			error_log( 'TourFlow Google Calendar: refresh_token inválido o revocado — ' . wp_remote_retrieve_body( $response ) );
			return null;
		}

		// Cachea un poco menos que el 'expires_in' real (típicamente 3600s)
		// para no arriesgarse a usar un token vencido por segundos de margen.
		set_transient( 'amir_google_access_token', $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );
		return $data['access_token'];
	}

	// ── Push de eventos ───────────────────────────────────────────────────

	/**
	 * Crea o actualiza el evento de una reserva confirmada (tour u
	 * habitación). Nunca bloquea ni revierte la confirmación si Google
	 * falla — es una integración best-effort, no debe poder tirar abajo una
	 * reserva ya cobrada.
	 */
	public static function push_booking( int $booking_id ): void {
		if ( ! self::is_connected() ) {
			return;
		}

		global $wpdb;
		$b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}amir_bookings WHERE id = %d", $booking_id ) );
		if ( ! $b ) {
			return;
		}

		$event = ( $b->item_type ?? 'tour' ) === 'room' ? self::build_room_event( $b ) : self::build_tour_event( $b );
		if ( ! $event ) {
			return;
		}

		try {
			$event_id = $b->google_calendar_event_id
				? self::update_event( $b->google_calendar_event_id, $event )
				: self::create_event( $event );
		} catch ( \Throwable $e ) {
			error_log( 'TourFlow Google Calendar: excepción al sincronizar reserva #' . $booking_id . ' — ' . $e->getMessage() );
			return;
		}

		if ( $event_id && $event_id !== $b->google_calendar_event_id ) {
			$wpdb->update( "{$wpdb->prefix}amir_bookings", [ 'google_calendar_event_id' => $event_id ], [ 'id' => $booking_id ] );
		}
	}

	public static function delete_booking_event( int $booking_id ): void {
		if ( ! self::is_connected() ) {
			return;
		}
		global $wpdb;
		$event_id = $wpdb->get_var( $wpdb->prepare( "SELECT google_calendar_event_id FROM {$wpdb->prefix}amir_bookings WHERE id = %d", $booking_id ) );
		if ( ! $event_id ) {
			return;
		}

		$token = self::get_access_token();
		if ( ! $token ) {
			return;
		}
		$calendar_id = rawurlencode( get_option( 'amir_google_calendar_id', 'primary' ) );
		wp_remote_request( self::API_BASE . "/calendars/{$calendar_id}/events/{$event_id}", [
			'method'  => 'DELETE',
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $token ],
		] );
	}

	private static function build_tour_event( object $b ): ?array {
		global $wpdb;
		$tour = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.name_es, s.time_start, s.time_end FROM {$wpdb->prefix}amir_tours t
			 LEFT JOIN {$wpdb->prefix}amir_tour_schedules s ON s.id = %d
			 WHERE t.id = %d", $b->schedule_id, $b->tour_id
		) );
		if ( ! $tour ) {
			return null;
		}

		$date  = $b->tour_date;
		$start = $tour->time_start ? "{$date}T{$tour->time_start}" : $date;
		$end   = $tour->time_end   ? "{$date}T{$tour->time_end}"   : $date;
		$all_day = ! $tour->time_start;

		return [
			'summary'     => sprintf( '🏄 %s — %s', $tour->name_es, $b->customer_name ),
			'description' => self::event_description( $b ),
			'start'       => $all_day ? [ 'date' => $date ] : [ 'dateTime' => $start, 'timeZone' => wp_timezone_string() ],
			'end'         => $all_day ? [ 'date' => $date ] : [ 'dateTime' => $end, 'timeZone' => wp_timezone_string() ],
		];
	}

	private static function build_room_event( object $b ): ?array {
		global $wpdb;
		$room_name = $wpdb->get_var( $wpdb->prepare( "SELECT name_es FROM {$wpdb->prefix}flow_rooms WHERE id = %d", $b->room_id ) );
		if ( ! $room_name ) {
			return null;
		}

		return [
			'summary'     => sprintf( '🛏 %s — %s (check-in/out)', $room_name, $b->customer_name ),
			'description' => self::event_description( $b ),
			// Todo el día, mismo criterio que un evento de hotel/Airbnb —
			// 'end.date' en Google Calendar es exclusivo (mismo intervalo
			// semi-abierto que ya usa RoomAvailability), así que check_out_date
			// tal cual coincide con la convención de la API.
			'start' => [ 'date' => $b->tour_date ],
			'end'   => [ 'date' => $b->check_out_date ],
		];
	}

	private static function event_description( object $b ): string {
		$lines = [
			'Referencia: ' . $b->booking_ref,
			'Cliente: ' . $b->customer_name . ' (' . $b->customer_email . ')',
			'Teléfono: ' . ( $b->customer_phone ?: '—' ),
			'Personas: ' . ( (int) $b->adults + (int) $b->children + (int) $b->babies ),
		];
		if ( ! empty( $b->special_requests ) ) {
			$lines[] = '';
			$lines[] = 'Requerimientos especiales: ' . $b->special_requests;
		}
		return implode( "\n", $lines );
	}

	private static function create_event( array $event ): ?string {
		$token = self::get_access_token();
		if ( ! $token ) {
			return null;
		}
		$calendar_id = rawurlencode( get_option( 'amir_google_calendar_id', 'primary' ) );
		$response = wp_remote_post( self::API_BASE . "/calendars/{$calendar_id}/events", [
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $event ),
		] );
		return self::extract_event_id( $response );
	}

	private static function update_event( string $event_id, array $event ): ?string {
		$token = self::get_access_token();
		if ( ! $token ) {
			return null;
		}
		$calendar_id = rawurlencode( get_option( 'amir_google_calendar_id', 'primary' ) );
		$response = wp_remote_request( self::API_BASE . "/calendars/{$calendar_id}/events/{$event_id}", [
			'method'  => 'PATCH',
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $event ),
		] );
		return self::extract_event_id( $response ) ?: $event_id;
	}

	private static function extract_event_id( $response ): ?string {
		if ( is_wp_error( $response ) ) {
			error_log( 'TourFlow Google Calendar: ' . $response->get_error_message() );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			error_log( 'TourFlow Google Calendar: respuesta sin id de evento — ' . wp_remote_retrieve_body( $response ) );
			return null;
		}
		return $data['id'];
	}
}
