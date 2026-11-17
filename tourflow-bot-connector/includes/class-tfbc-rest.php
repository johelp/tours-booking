<?php
namespace TourFlowBotConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Las 4 rutas públicas (protegidas por API key, ver Auth) que un bot externo
 * consume. Namespace `tfbc/v1` — nunca `amir/v1`/`flow/v1`, son del núcleo
 * (GUIA-PLUGINS-SATELITE-TOURFLOW.md § 6).
 */
class Rest {

	private const NAMESPACE = 'tfbc/v1';
	private const ITEM_TYPES = [ 'tour', 'room', 'product' ];

	public static function register_routes(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NAMESPACE, '/catalog', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_catalog' ],
			'permission_callback' => [ Auth::class, 'permission_callback' ],
			'args'                => [
				'lang' => [ 'required' => false, 'type' => 'string', 'default' => 'es' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/items/(?P<type>tour|room|product)/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_item' ],
			'permission_callback' => [ Auth::class, 'permission_callback' ],
			'args'                => [
				'type' => [ 'required' => true, 'type' => 'string', 'enum' => self::ITEM_TYPES ],
				'id'   => [ 'required' => true, 'type' => 'integer' ],
				'lang' => [ 'required' => false, 'type' => 'string', 'default' => 'es' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/availability', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_availability' ],
			'permission_callback' => [ Auth::class, 'permission_callback' ],
			'args'                => [
				'type'      => [ 'required' => true, 'type' => 'string', 'enum' => self::ITEM_TYPES ],
				'id'        => [ 'required' => true, 'type' => 'integer' ],
				'date'      => [ 'required' => false, 'type' => 'string', 'format' => 'date' ],
				'check_in'  => [ 'required' => false, 'type' => 'string', 'format' => 'date' ],
				'check_out' => [ 'required' => false, 'type' => 'string', 'format' => 'date' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/booking-link', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_booking_link' ],
			'permission_callback' => [ Auth::class, 'permission_callback' ],
			'args'                => [
				'type' => [ 'required' => true, 'type' => 'string', 'enum' => self::ITEM_TYPES ],
				'id'   => [ 'required' => true, 'type' => 'integer' ],
				'date' => [ 'required' => false, 'type' => 'string', 'format' => 'date' ],
				'lang' => [ 'required' => false, 'type' => 'string', 'default' => 'es' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/quote', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'post_quote' ],
			'permission_callback' => [ Auth::class, 'permission_callback' ],
			'args'                => [
				'tour_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
				'date'    => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
				'adults'  => [ 'required' => true, 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/book', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'post_book' ],
			'permission_callback' => [ Auth::class, 'permission_callback' ],
			'args'                => [
				'tour_id'         => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
				'date'            => [ 'required' => true, 'type' => 'string', 'format' => 'date' ],
				'adults'          => [ 'required' => true, 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
				'customer_name'   => [ 'required' => true, 'type' => 'string', 'minLength' => 2 ],
				'customer_email'  => [ 'required' => true, 'type' => 'string', 'format' => 'email' ],
				'policy_accepted' => [ 'required' => true, 'type' => 'boolean' ],
				'terms_accepted'  => [ 'required' => true, 'type' => 'boolean' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/bookings/(?P<ref>[A-Za-z0-9\-]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_booking_status' ],
			'permission_callback' => [ Auth::class, 'permission_callback' ],
			'args'                => [
				'email' => [ 'required' => true, 'type' => 'string', 'format' => 'email' ],
			],
		] );
	}

	/** Parámetros de reserva/cotización sanitizados — whitelist explícita, nada del body pasa sin tocar. */
	private static function clean_params( \WP_REST_Request $r ): array {
		$out = [];
		foreach ( [ 'tour_id', 'schedule_id', 'adults', 'children', 'babies' ] as $k ) {
			if ( $r->get_param( $k ) !== null ) {
				$out[ $k ] = (int) $r->get_param( $k );
			}
		}
		foreach ( [ 'date', 'customer_name', 'customer_phone', 'coupon_code' ] as $k ) {
			if ( $r->get_param( $k ) !== null ) {
				$out[ $k ] = sanitize_text_field( (string) $r->get_param( $k ) );
			}
		}
		$out['customer_email']   = sanitize_email( (string) $r->get_param( 'customer_email' ) );
		$out['special_requests'] = sanitize_textarea_field( (string) $r->get_param( 'special_requests' ) );
		$out['lang']             = sanitize_key( $r->get_param( 'lang' ) ?: 'es' );
		$names                   = $r->get_param( 'participant_names' );
		$out['participant_names'] = is_array( $names ) ? array_map( 'sanitize_text_field', array_map( 'strval', $names ) ) : [];
		$out['policy_accepted']  = $r->get_param( 'policy_accepted' ) === true;
		$out['terms_accepted']   = $r->get_param( 'terms_accepted' ) === true;
		return $out;
	}

	public static function post_quote( \WP_REST_Request $request ): \WP_REST_Response {
		$res = Catalog::quote( self::clean_params( $request ) );
		return new \WP_REST_Response( self::envelope( $res['ok'] ? [ 'quote' => $res['data'] ] : [ 'error' => $res['data']['error'] ?? 'quote_failed' ] ), $res['ok'] ? 200 : 422 );
	}

	public static function post_book( \WP_REST_Request $request ): \WP_REST_Response {
		$res = Catalog::book( self::clean_params( $request ) );
		return new \WP_REST_Response( self::envelope( $res ), $res['success'] ? 201 : 422 );
	}

	public static function get_booking_status( \WP_REST_Request $request ): \WP_REST_Response {
		$data = Catalog::booking_status( sanitize_text_field( (string) $request->get_param( 'ref' ) ), sanitize_email( (string) $request->get_param( 'email' ) ) );
		if ( $data === null ) {
			return new \WP_REST_Response( self::envelope( [ 'error' => 'not_found' ] ), 404 );
		}
		return rest_ensure_response( self::envelope( [ 'booking' => $data ] ) );
	}

	/** Envoltorio común — `site` ayuda al bot a distinguir de cuál instalación viene cada respuesta si consulta varias. */
	private static function envelope( array $data ): array {
		return array_merge( [ 'site' => Settings::site_label() ], $data );
	}

	public static function get_catalog( \WP_REST_Request $request ): \WP_REST_Response {
		$lang = sanitize_key( $request->get_param( 'lang' ) ?: 'es' );
		return rest_ensure_response( self::envelope( [
			'items' => Catalog::catalog( $lang ),
		] ) );
	}

	public static function get_item( \WP_REST_Request $request ): \WP_REST_Response {
		$type = sanitize_key( $request->get_param( 'type' ) );
		$id   = (int) $request->get_param( 'id' );
		$lang = sanitize_key( $request->get_param( 'lang' ) ?: 'es' );

		$item = Catalog::item( $type, $id, $lang );
		if ( $item === null ) {
			return new \WP_REST_Response( self::envelope( [ 'error' => 'not_found' ] ), 404 );
		}
		return rest_ensure_response( self::envelope( [ 'item' => $item ] ) );
	}

	public static function get_availability( \WP_REST_Request $request ): \WP_REST_Response {
		$type = sanitize_key( $request->get_param( 'type' ) );
		$id   = (int) $request->get_param( 'id' );

		$result = Catalog::availability( $type, $id, [
			'date'      => sanitize_text_field( (string) $request->get_param( 'date' ) ),
			'check_in'  => sanitize_text_field( (string) $request->get_param( 'check_in' ) ),
			'check_out' => sanitize_text_field( (string) $request->get_param( 'check_out' ) ),
		] );

		if ( $result === null ) {
			return new \WP_REST_Response( self::envelope( [
				'error' => 'missing_params',
				'hint'  => $type === 'room' ? 'Se necesitan check_in y check_out.' : 'Se necesita date.',
			] ), 400 );
		}
		return rest_ensure_response( self::envelope( [ 'availability' => $result ] ) );
	}

	public static function get_booking_link( \WP_REST_Request $request ): \WP_REST_Response {
		$type = sanitize_key( $request->get_param( 'type' ) );
		$id   = (int) $request->get_param( 'id' );

		$link = Catalog::booking_link( $type, $id, [
			'date' => sanitize_text_field( (string) $request->get_param( 'date' ) ),
			'lang' => sanitize_key( $request->get_param( 'lang' ) ?: 'es' ),
		] );

		if ( $link === null ) {
			return new \WP_REST_Response( self::envelope( [ 'error' => 'not_found' ] ), 404 );
		}
		return rest_ensure_response( self::envelope( [ 'booking_link' => $link ] ) );
	}
}
