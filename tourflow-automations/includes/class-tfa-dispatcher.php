<?php
namespace TourFlowAutomations;

defined( 'ABSPATH' ) || exit;

/**
 * Escucha los hooks del núcleo y despacha un POST firmado a cada destino
 * suscripto. El envío real corre en WP-Cron (`tfa_deliver`): el checkout del
 * cliente nunca espera a que responda un servidor de terceros.
 *
 * Reintentos: intento 1 inmediato, intento 2 a +1 min, intento 3 a +10 min.
 * Después de eso queda `failed` en el log — nunca se pierde en silencio.
 */
final class Dispatcher {

	public const CRON_HOOK     = 'tfa_deliver';
	public const MAX_ATTEMPTS  = 3;
	public const RETRY_DELAYS  = [ 1 => 60, 2 => 600 ]; // segundos tras el intento N
	public const TIMEOUT       = 10;

	/** Parámetro extra que viaja junto al ID de reserva, según el hook. */
	private const EXTRA_KEYS = [
		'amir_booking_cancelled'        => 'reason_type',
		'amir_booking_payment_failed'   => 'reason',
	];

	public static function register(): void {
		foreach ( array_keys( Payload::events() ) as $hook ) {
			add_action( $hook, static function ( ...$args ) use ( $hook ) {
				self::on_event( $hook, $args );
			}, 10, 3 );
		}
		add_action( self::CRON_HOOK, [ __CLASS__, 'deliver' ], 10, 5 );
	}

	/** @param array<int,mixed> $args */
	private static function on_event( string $hook, array $args ): void {
		$booking_id = (int) ( $args[0] ?? 0 );
		if ( ! $booking_id ) {
			return;
		}
		$extra = [];
		if ( isset( self::EXTRA_KEYS[ $hook ] ) && isset( $args[1] ) ) {
			$extra[ self::EXTRA_KEYS[ $hook ] ] = (string) $args[1];
		}

		foreach ( Settings::endpoints() as $ep ) {
			if ( ! $ep['active'] || ! in_array( $hook, $ep['events'], true ) ) {
				continue;
			}
			wp_schedule_single_event( time(), self::CRON_HOOK, [ $ep['id'], $hook, $booking_id, 1, $extra ] );
		}
	}

	/**
	 * Callback de WP-Cron. Firma fija: el orden de los argumentos es parte del
	 * contrato con los eventos ya encolados.
	 */
	public static function deliver( string $endpoint_id, string $event, int $booking_id, int $attempt, array $extra = [] ): void {
		$ep = Settings::find( $endpoint_id );
		if ( ! $ep ) {
			Log::add( $endpoint_id, $event, $booking_id, $attempt, 'skipped', 0, 'Destino eliminado' );
			return;
		}
		if ( ! $ep['active'] ) {
			Log::add( $ep['id'], $event, $booking_id, $attempt, 'skipped', 0, 'Destino pausado' );
			return;
		}

		$payload = Payload::build( $booking_id, $event, $extra );
		if ( $payload === null ) {
			Log::add( $ep['id'], $event, $booking_id, $attempt, 'failed', 0, 'La reserva ya no existe' );
			return;
		}

		$result = self::send( $ep, $payload );
		if ( $result['ok'] ) {
			Log::add( $ep['id'], $event, $booking_id, $attempt, 'ok', $result['code'], 'Entregado' );
			return;
		}

		if ( $attempt < self::MAX_ATTEMPTS ) {
			$delay = self::RETRY_DELAYS[ $attempt ];
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, [ $ep['id'], $event, $booking_id, $attempt + 1, $extra ] );
			Log::add( $ep['id'], $event, $booking_id, $attempt, 'retry', $result['code'], $result['message'] . ' — reintento en ' . ( $delay / 60 ) . ' min' );
		} else {
			Log::add( $ep['id'], $event, $booking_id, $attempt, 'failed', $result['code'], $result['message'] . ' — sin más reintentos' );
		}
	}

	/**
	 * Envío de prueba desde el admin: síncrono (el operador ve el resultado
	 * en la misma pantalla) y con un payload ficticio.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function send_test( array $ep ): array {
		$event   = in_array( 'amir_booking_confirmed', $ep['events'], true ) ? 'amir_booking_confirmed' : $ep['events'][0];
		$payload = Payload::sample( $event );
		$result  = self::send( $ep, $payload );
		Log::add( $ep['id'], $event, 0, 1, $result['ok'] ? 'test_ok' : 'test_failed', $result['code'], $result['message'] );
		return [
			'ok'      => $result['ok'],
			'message' => ( $result['ok'] ? 'OK, HTTP ' : 'falló, HTTP ' ) . $result['code'] . ( $result['message'] !== '' ? ' — ' . $result['message'] : '' ),
		];
	}

	/**
	 * POST firmado. Usa wp_safe_remote_post (bloquea IPs privadas y
	 * redirecciones a hosts no permitidos).
	 *
	 * @return array{ok:bool,code:int,message:string}
	 */
	private static function send( array $ep, array $payload ): array {
		$secret = Settings::secret();
		if ( $secret === '' ) {
			return [ 'ok' => false, 'code' => 0, 'message' => 'Falta el secreto de firma' ];
		}

		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$ts   = (string) time();

		$response = wp_safe_remote_post( $ep['url'], [
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => [
				'Content-Type'            => 'application/json',
				'User-Agent'              => 'TourFlow-Automations/' . TFA_VERSION,
				'X-TourFlow-Event'        => (string) ( $payload['event'] ?? '' ),
				Signer::HEADER_TIMESTAMP  => $ts,
				Signer::HEADER_SIGNATURE  => Signer::sign( $ts, (string) $body, $secret ),
			],
			'body'        => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'code' => 0, 'message' => substr( $response->get_error_message(), 0, 200 ) ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return [
			'ok'      => $code >= 200 && $code < 300,
			'code'    => $code,
			'message' => $code >= 200 && $code < 300 ? '' : 'El destino respondió ' . $code,
		];
	}
}
