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
