<?php
namespace TourFlowBotConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Compone las respuestas del satélite llamando a las rutas REST PÚBLICAS que
 * el núcleo ya expone (`amir/v1`, `flow/v1`) vía `rest_do_request()` — nunca
 * reimplementa una query ni un cálculo de precio/disponibilidad propio
 * (GUIA-PLUGINS-SATELITE-TOURFLOW.md § 6). Llamar a la ruta pública en vez
 * de instanciar las clases del núcleo directo tiene un motivo concreto: es
 * el contrato más estable que el núcleo ofrece (una ruta REST no cambia de
 * firma tan seguido como un método privado), y garantiza que el bot ve
 * EXACTAMENTE los mismos datos que vería pegándole directo a esas rutas
 * desde afuera — sin un segundo camino interno que se pueda desincronizar.
 *
 * Deliberadamente de solo lectura — nada acá crea ni modifica una reserva.
 */
class Catalog {

	/** Llama a una ruta REST del propio sitio sin red (in-process) y devuelve el body decodificado, o null si falló. */
	private static function internal_get( string $route, array $params = [] ): ?array {
		$request = new \WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return null;
		}
		$data = $response->get_data();
		return is_array( $data ) ? $data : null;
	}

	/** Habitaciones y productos digitales son exclusivos de Pro Max (§ 16 CONTRIBUTING.md) — en Lite/Pro esas rutas no existen. */
	private static function has_rooms_and_products(): bool {
		return defined( 'AMIR_EDITION' ) && \AMIR_EDITION === 'pro_max';
	}

	private static function currency(): string {
		return class_exists( '\AmirBooking\Core\Currency' ) ? \AmirBooking\Core\Currency::code() : 'MXN';
	}

	private static function pick_lang( array $row, string $key, string $lang ): string {
		if ( $lang === 'en' && ! empty( $row[ "{$key}_en" ] ) ) {
			return (string) $row[ "{$key}_en" ];
		}
		return (string) ( $row[ "{$key}_es" ] ?? '' );
	}

	// ── GET /catalog ─────────────────────────────────────────────────────

	/** Lista liviana y uniforme de todo lo reservable — tours siempre, habitaciones/productos solo en Pro Max. */
	public static function catalog( string $lang ): array {
		$items    = [];
		$currency = self::currency();

		foreach ( (array) ( self::internal_get( '/amir/v1/tours', [ 'lang' => $lang ] ) ?? [] ) as $t ) {
			$items[] = [
				'type'              => 'tour',
				'id'                => $t['id'] ?? null,
				'name'              => $t['name'] ?? '',
				'short_description' => $t['short_description'] ?? '',
				'from_price'        => $t['from_price_mxn'] ?? null,
				'currency'          => $currency,
				'cover_image'       => $t['cover_image'] ?? '',
				'permalink'         => $t['permalink'] ?? '',
			];
		}

		if ( ! self::has_rooms_and_products() ) {
			return $items;
		}

		foreach ( (array) ( self::internal_get( '/flow/v1/rooms' ) ?? [] ) as $r ) {
			$items[] = [
				'type'              => 'room',
				'id'                => $r['id'] ?? null,
				'name'              => self::pick_lang( $r, 'name', $lang ),
				'short_description' => mb_substr( wp_strip_all_tags( self::pick_lang( $r, 'description', $lang ) ), 0, 160 ),
				'from_price'        => $r['price_per_night'] ?? null,
				'currency'          => $currency,
				'cover_image'       => $r['gallery_images'][0] ?? '',
				// El núcleo no expone todavía una URL pública de ficha para
				// habitaciones (hueco real, documentado en
				// GUIA-PLUGINS-SATELITE-TOURFLOW.md § 7) — se usa el archive
				// general como mejor esfuerzo en vez de inventar un link.
				'permalink'         => get_post_type_archive_link( 'flow_room' ) ?: '',
			];
		}

		foreach ( (array) ( self::internal_get( '/flow/v1/addons/global', [ 'lang' => $lang ] ) ?? [] ) as $a ) {
			if ( ( $a['pricing_type'] ?? '' ) !== 'digital' ) {
				continue; // catálogo de productos reservables, no servicios extra genéricos (seguro, transfer, etc.)
			}
			$items[] = [
				'type'              => 'product',
				'id'                => $a['id'] ?? null,
				'name'              => $a['name'] ?? '',
				// Primer párrafo de la descripción cargada en Extras globales
				// (núcleo >= 5.13.11; vacío en versiones anteriores).
				'short_description' => trim( (string) preg_split( '/\R\s*\R/', (string) ( $a['description'] ?? '' ) )[0] ),
				'from_price'        => $a['price_mxn'] ?? null,
				'currency'          => $currency,
				'cover_image'       => $a['image_url'] ?? '',
				'permalink'         => '', // [flow_product] vive en una página propia del operador, sin URL canónica que el núcleo conozca
			];
		}

		return $items;
	}

	// ── GET /items/{type}/{id} ───────────────────────────────────────────

	/** Detalle completo de un ítem puntual, o null si no existe/no aplica a esta edición. */
	public static function item( string $type, int $id, string $lang ): ?array {
		if ( $type === 'tour' ) {
			$data = self::internal_get( "/amir/v1/tours/{$id}", [ 'lang' => $lang ] );
			return $data && empty( $data['error'] ) ? $data : null;
		}

		if ( ! self::has_rooms_and_products() ) {
			return null;
		}

		if ( $type === 'room' ) {
			foreach ( (array) ( self::internal_get( '/flow/v1/rooms' ) ?? [] ) as $r ) {
				if ( (int) ( $r['id'] ?? 0 ) === $id ) {
					$r['name']        = self::pick_lang( $r, 'name', $lang );
					$r['description'] = self::pick_lang( $r, 'description', $lang );
					return $r;
				}
			}
			return null;
		}

		if ( $type === 'product' ) {
			foreach ( (array) ( self::internal_get( '/flow/v1/addons/global', [ 'lang' => $lang ] ) ?? [] ) as $a ) {
				if ( (int) ( $a['id'] ?? 0 ) === $id && ( $a['pricing_type'] ?? '' ) === 'digital' ) {
					return $a;
				}
			}
			return null;
		}

		return null;
	}

	// ── GET /availability ────────────────────────────────────────────────

	/**
	 * $params espera, según $type:
	 *  - tour: 'date' (YYYY-MM-DD) — devuelve disponibilidad por horario.
	 *  - room: 'check_in' y 'check_out' (YYYY-MM-DD) — devuelve {available: bool}.
	 */
	public static function availability( string $type, int $id, array $params ): ?array {
		if ( $type === 'tour' ) {
			if ( empty( $params['date'] ) ) {
				return null;
			}
			return self::internal_get( '/amir/v1/availability/day', [
				'tour_id' => $id,
				'date'    => $params['date'],
			] );
		}

		if ( $type === 'room' && self::has_rooms_and_products() ) {
			if ( empty( $params['check_in'] ) || empty( $params['check_out'] ) ) {
				return null;
			}
			return self::internal_get( "/flow/v1/rooms/{$id}/availability", [
				'check_in'  => $params['check_in'],
				'check_out' => $params['check_out'],
			] );
		}

		return null; // 'product' no tiene disponibilidad — es una descarga, no un cupo
	}

	// ── GET /booking-link ─────────────────────────────────────────────────

	/**
	 * URL real del sitio para que el usuario termine de reservar — nunca
	 * crea nada (decisión cerrada con el cliente 2026-10-03: el conector
	 * solo deep-linkea al flujo de pago ya auditado, jamás llama a
	 * create_pending()/crea una fila en amir_bookings por su cuenta).
	 *
	 * Para tours, `?date=YYYY-MM-DD` ya lo preselecciona el widget clásico y
	 * el flujo combinado (`[flow_tour_dates]`, § 16.58 CONTRIBUTING.md) — se
	 * reusa ese mismo mecanismo, cero código nuevo de reserva.
	 */
	public static function booking_link( string $type, int $id, array $params ): ?string {
		if ( $type === 'tour' ) {
			$detail = self::internal_get( "/amir/v1/tours/{$id}", [ 'lang' => $params['lang'] ?? 'es' ] );
			$link   = $detail['permalink'] ?? '';
			if ( $link === '' ) {
				return null;
			}
			return ! empty( $params['date'] ) ? add_query_arg( 'date', sanitize_text_field( $params['date'] ), $link ) : $link;
		}

		if ( $type === 'room' && self::has_rooms_and_products() ) {
			// Sin ficha propia (ver catalog() más arriba) — el archive
			// general es el mejor link disponible hoy.
			$link = get_post_type_archive_link( 'flow_room' ) ?: null;
			return $link;
		}

		return null;
	}

	// ── POST /quote ──────────────────────────────────────────────────────

	/** Cotización real del motor de precios del núcleo (misma ruta que usa el widget). Solo tours. */
	public static function quote( array $p ): array {
		$request = new \WP_REST_Request( 'POST', '/amir/v1/bookings/quote' );
		foreach ( [ 'tour_id', 'schedule_id', 'date', 'adults', 'children', 'babies', 'coupon_code', 'lang' ] as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$request->set_param( $k, $p[ $k ] );
			}
		}
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		if ( ! $response->is_error() && is_array( $data ) ) {
			$data = self::normalize_quote( $data, $p );
		}
		return [ 'ok' => ! $response->is_error(), 'status' => $response->get_status(), 'data' => $data ];
	}

	/**
	 * Agrega campos neutros de moneda (el núcleo los llama *_mxn aunque el
	 * sitio cobre en otra moneda), el monto a cobrar ahora (depósito) y si la
	 * fecha tiene cupo — el núcleo cotiza igual una fecha sin cupo.
	 */
	private static function normalize_quote( array $q, array $p ): array {
		$total   = (float) ( $q['total_mxn'] ?? 0 );
		$pct     = (int) ( $q['deposit_pct'] ?? 0 );
		$due_now = $pct > 0 ? (float) ( $q['deposit_mxn'] ?? 0 ) : $total;

		$q['currency']  = self::currency();
		$q['total']     = $total;
		$q['due_now']   = $due_now;
		$q['due_later'] = round( $total - $due_now, 2 );

		$slots = self::availability( 'tour', (int) ( $p['tour_id'] ?? 0 ), [ 'date' => $p['date'] ?? '' ] );
		$q['available'] = null; // null = no se pudo determinar
		if ( is_array( $slots ) ) {
			$schedule_id = (int) ( $p['schedule_id'] ?? 0 );
			$q['available'] = false;
			foreach ( $slots as $slot ) {
				if ( ! empty( $slot['available'] ) && ( $schedule_id === 0 || (int) ( $slot['id'] ?? 0 ) === $schedule_id ) ) {
					$q['available'] = true;
					break;
				}
			}
		}
		return $q;
	}

	// ── POST /book ───────────────────────────────────────────────────────

	/**
	 * Crea una reserva PENDIENTE de un tour con la misma validación del
	 * núcleo (cupo, mínimos, política de niños, nombres, cupón) y devuelve el
	 * link de pago. NUNCA cobra ni toca datos de tarjeta: el pago ocurre en
	 * la página del sitio (verificar-reserva) con la pasarela activa.
	 * El cupo queda retenido hasta `amir_pending_expire_mins`.
	 */
	public static function book( array $p ): array {
		$manager = new \AmirBooking\Core\BookingManager();
		$result  = $manager->create_pending( [
			'tour_id'           => (int) $p['tour_id'],
			'schedule_id'       => (int) ( $p['schedule_id'] ?? 0 ),
			'date'              => $p['date'],
			'adults'            => (int) $p['adults'],
			'children'          => (int) ( $p['children'] ?? 0 ),
			'babies'            => (int) ( $p['babies'] ?? 0 ),
			'customer_name'     => $p['customer_name'],
			'customer_email'    => $p['customer_email'],
			'customer_phone'    => $p['customer_phone'] ?? '',
			'lang'              => $p['lang'] ?? 'es',
			'special_requests'  => $p['special_requests'] ?? '',
			'coupon_code'       => $p['coupon_code'] ?? '',
			'participant_names' => is_array( $p['participant_names'] ?? null ) ? $p['participant_names'] : [],
			'policy_accepted'   => ! empty( $p['policy_accepted'] ),
			'terms_accepted'    => ! empty( $p['terms_accepted'] ),
			'source'            => 'direct',
		] );

		if ( ! $result->success ) {
			return [ 'success' => false, 'error' => $result->error ];
		}

		$booking = $manager->get_booking_by_ref( $result->booking_ref );
		return [
			'success'          => true,
			'booking_ref'      => $result->booking_ref,
			'status'           => $result->status ?? 'pending',
			'total'            => $result->total_mxn,
			// Lo que se cobra al pagar (con depósito parcial es menor que `total`).
			'due_now'          => (float) ( $result->charge_mxn ?? $result->total_mxn ),
			'currency'         => self::currency(),
			'requires_payment' => (bool) $result->requires_payment,
			// Sin pago inmediato (aprobación de proveedor, solicitud de fecha,
			// cotización a medida): el sitio avisa por email, no hay link.
			'payment_url'      => $result->requires_payment && $booking ? self::pay_url( $booking ) : null,
		];
	}

	private static function pay_url( object $booking ): string {
		$page_id = (int) get_option( 'amir_verify_page_id', 0 );
		$base    = $page_id ? get_permalink( $page_id ) : false;
		if ( trim( (string) get_option( 'amir_public_site_url', '' ) ) !== '' ) {
			$base = false;
		}
		$base = $base ?: ( \AmirBooking\Core\FrontendUrl::base() . '/verificar-reserva/' );
		return add_query_arg( [ 'ref' => $booking->booking_ref, 'token' => $booking->access_token ?? '' ], $base );
	}

	// ── GET /bookings/{ref} ──────────────────────────────────────────────

	/** Estado de una reserva; exige el email con el que se hizo (no basta el ref, es secuencial). */
	public static function booking_status( string $ref, string $email ): ?array {
		$manager = new \AmirBooking\Core\BookingManager();
		$booking = $manager->get_booking_by_ref( strtoupper( $ref ) );
		if ( ! $booking || ! $manager->authorize_public_access( $booking, '', $email ) ) {
			return null; // mismo 404 para "no existe" y "email incorrecto": no revelar cuál
		}
		return [
			'booking_ref' => $booking->booking_ref,
			'status'      => $booking->status,
			'date'        => $booking->tour_date ?? null,
			'adults'      => (int) ( $booking->adults ?? 0 ),
			'children'    => (int) ( $booking->children ?? 0 ),
			'total'       => (float) $booking->total_mxn,
			'currency'    => self::currency(),
			'payment_url' => in_array( $booking->status, [ 'pending', 'awaiting_payment' ], true ) ? self::pay_url( $booking ) : null,
		];
	}
}
