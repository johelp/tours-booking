<?php
namespace TourFlowCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * Lógica de limpieza en sí — separada de la pantalla de admin (class-tfc-
 * page.php) para poder contar y ejecutar con el mismo código, y para que
 * quede claro en un solo lugar qué toca cada categoría.
 *
 * Herramienta de uso INTERNO (equipo, no operadores) — pensada para dejar
 * un sitio limpio de datos de prueba antes de entregarlo/lanzarlo. Por
 * diseño, NUNCA toca `wp_options` (Configuración/Personalización/branding/
 * credenciales de pasarela) — eso ya está bien cargado para el cliente real
 * al momento de limpiar, solo los DATOS de prueba (reservas, tours, etc.)
 * sobran. Tampoco toca las páginas que el instalador crea automáticamente
 * ([amir_verify_booking], aprobación de proveedor, discovery) — son
 * infraestructura, no datos de prueba.
 */
class Cleaner {

	/**
	 * Categorías disponibles. Cada una: label, las tablas propias (TRUNCATE
	 * directo, sin FKs reales que lo impidan), y opcionalmente un post_type
	 * de CPT a borrar antes de truncar sus tablas (para no dejar el CPT
	 * apuntando a filas que ya no existen). 'gate' es un callable opcional
	 * para ocultar una categoría en ediciones donde no aplica (ej. Lite/Pro
	 * sin habitaciones).
	 */
	public static function categories(): array {
		return [
			'bookings' => [
				// Bug real reportado por el cliente (2026-08-24): amir_notifications
				// vivía como categoría separada — al limpiar solo "Reservas" quedaban
				// notificaciones huérfanas apuntando a reservas que ya no existen
				// (el badge "⚠ N alertas pendientes" del Dashboard las sigue contando,
				// lee la tabla directo). Todas las notificaciones que existen hoy son
				// sobre reservas (solicitud de cancelación, wishlist, solicitud de
				// fecha — ver los 3 $wpdb->insert('amir_notifications') del código),
				// así que no tiene sentido dejarlas vivas sin la reserva que describen.
				'label'  => 'Reservas (tours y habitaciones) + extras cargados + notificaciones del Dashboard',
				'tables' => [ 'amir_booking_addons', 'amir_bookings', 'amir_notifications' ],
			],
			'tours' => [
				'label'     => 'Tours (editor + precios + reglas de disponibilidad + horarios)',
				'tables'    => [ 'amir_prices', 'amir_availability_rules', 'amir_tour_schedules', 'amir_tours' ],
				'post_type' => 'amir_tour',
			],
			'rooms' => [
				'label'     => 'Habitaciones (Pro Max)',
				'tables'    => [ 'flow_room_availability_rules', 'flow_room_bookings', 'flow_rooms' ],
				'post_type' => 'flow_room',
				'gate'      => fn() => post_type_exists( 'flow_room' ),
			],
			'coupons' => [
				'label'  => 'Cupones',
				'tables' => [ 'amir_coupons' ],
			],
			'addons' => [
				'label'  => 'Catálogo de extras/servicios adicionales',
				'tables' => [ 'amir_addons' ],
			],
			'partners' => [
				'label'  => 'Partners (links de descuento/afiliados)',
				'tables' => [ 'amir_partners' ],
			],
			'providers' => [
				'label'  => 'Proveedores externos (marketplace) + liquidaciones',
				'tables' => [ 'amir_provider_payouts', 'amir_providers' ],
			],
			'payment_log' => [
				'label'  => 'Log de eventos de pago',
				'tables' => [ 'amir_payment_events' ],
			],
		];
	}

	/** Categorías visibles en esta instalación (aplica los 'gate'). */
	public static function available_categories(): array {
		$cats = self::categories();
		foreach ( $cats as $key => $cat ) {
			if ( isset( $cat['gate'] ) && ! $cat['gate']() ) {
				unset( $cats[ $key ] );
			}
		}
		return $cats;
	}

	/**
	 * Cuántas filas/posts tocaría cada categoría — para mostrar en la
	 * pantalla ANTES de que el admin confirme nada. Nunca ejecuta ningún
	 * DELETE/TRUNCATE.
	 */
	public static function counts(): array {
		global $wpdb;
		$counts = [];
		foreach ( self::available_categories() as $key => $cat ) {
			$n = 0;
			foreach ( $cat['tables'] as $table ) {
				if ( ! self::table_exists( $table ) ) {
					continue;
				}
				$n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table}" );
			}
			if ( ! empty( $cat['post_type'] ) ) {
				$post_counts = wp_count_posts( $cat['post_type'] );
				foreach ( (array) $post_counts as $status_count ) {
					$n += (int) $status_count;
				}
			}
			$counts[ $key ] = $n;
		}
		return $counts;
	}

	/**
	 * Attachments de la Media Library que quedarían huérfanos si se borran
	 * los tours/habitaciones seleccionados — solo para MOSTRAR el número
	 * antes de confirmar el checkbox de "también borrar imágenes" (nunca se
	 * ejecuta solo por llamar a este método).
	 */
	public static function media_count( array $selected_keys ): int {
		$ids = self::collect_gallery_attachment_ids( $selected_keys );
		return count( $ids );
	}

	/**
	 * Ejecuta el borrado de las categorías elegidas. $selected_keys son
	 * claves de self::categories(). $delete_media, si true, también borra
	 * (permanente, sin papelera) los attachments de galería de los tours/
	 * habitaciones que se estén borrando en esta misma pasada.
	 *
	 * Devuelve un resumen [ 'categoria' => filas_borradas, ..., 'media' => N ]
	 * para mostrar después de ejecutar — no hay tabla de log persistente a
	 * propósito, es una herramienta interna de uso puntual, no un feature de
	 * auditoría (mantenerla simple).
	 */
	public static function run( array $selected_keys, bool $delete_media = false ): array {
		global $wpdb;
		$cats    = self::available_categories();
		$summary = [];

		// Attachments a borrar se calculan ANTES de borrar los posts (una
		// vez borrado el post, su postmeta con la lista de galería ya no
		// está disponible para leer).
		$media_ids = $delete_media ? self::collect_gallery_attachment_ids( $selected_keys ) : [];

		foreach ( $selected_keys as $key ) {
			if ( ! isset( $cats[ $key ] ) ) {
				continue; // clave desconocida o no disponible en esta instalación — ignorar, no adivinar
			}
			$cat = $cats[ $key ];
			$n   = 0;

			// CPT primero (limpia postmeta/relaciones de taxonomía solo,
			// WordPress lo hace automáticamente en wp_delete_post) — así
			// las tablas del núcleo quedan como la última palabra.
			if ( ! empty( $cat['post_type'] ) ) {
				$post_ids = get_posts( [
					'post_type'      => $cat['post_type'],
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				] );
				foreach ( $post_ids as $post_id ) {
					wp_delete_post( $post_id, true ); // true = saltar la papelera, borrado real
					$n++;
				}
			}

			foreach ( $cat['tables'] as $table ) {
				if ( ! self::table_exists( $table ) ) {
					continue;
				}
				$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
			}

			$summary[ $key ] = $n;
		}

		if ( $delete_media ) {
			$deleted_media = 0;
			foreach ( $media_ids as $attachment_id ) {
				if ( wp_delete_attachment( $attachment_id, true ) ) {
					$deleted_media++;
				}
			}
			$summary['media'] = $deleted_media;
		}

		return $summary;
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$full = $wpdb->prefix . $table;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
	}

	/**
	 * IDs de attachment de galería + foto principal de los CPTs que van a
	 * borrarse en esta pasada (solo tours/habitaciones tienen galería). No
	 * deduplicar entre sí importa poco (wp_delete_attachment en un ID ya
	 * borrado simplemente no encuentra nada y sigue) pero se deduplica de
	 * todos modos para que el conteo mostrado al admin sea exacto.
	 */
	private static function collect_gallery_attachment_ids( array $selected_keys ): array {
		$cats = self::available_categories();
		$ids  = [];

		foreach ( $selected_keys as $key ) {
			if ( ! isset( $cats[ $key ]['post_type'] ) ) {
				continue;
			}
			$post_type = $cats[ $key ]['post_type'];
			$meta_key  = $post_type === 'amir_tour' ? '_amir_gallery_ids' : '_flow_gallery_ids';

			$post_ids = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
			foreach ( $post_ids as $post_id ) {
				$thumb_id = get_post_thumbnail_id( $post_id );
				if ( $thumb_id ) {
					$ids[] = (int) $thumb_id;
				}
				$gallery = get_post_meta( $post_id, $meta_key, true );
				$gallery = is_string( $gallery ) ? ( json_decode( $gallery, true ) ?: [] ) : ( is_array( $gallery ) ? $gallery : [] );
				foreach ( $gallery as $gid ) {
					if ( is_numeric( $gid ) ) {
						$ids[] = (int) $gid;
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
