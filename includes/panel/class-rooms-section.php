<?php
namespace TourFlow\Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Habitaciones dentro del panel de gestión sin wp-admin (Pro Max) — mismo
 * patrón exacto que `ToursSection` para `TourPostType`, aplicado a
 * `TourFlow\Rooms\RoomPostType` (`flow_room`), que tiene la misma
 * estructura (metaboxes públicos auto-contenidos + guardado colgado de
 * `save_post_flow_room`). Pedido explícito del cliente (2026-09-12): "le
 * falta la gestión de habitaciones al dashboard de la versión pro max".
 *
 * Único hallazgo real en el camino: `RoomPostType::save_meta()` exigía
 * `current_user_can('manage_options')` A SECAS, sin ningún fallback — ni
 * siquiera el bug de `edit_amir_tour` de Tours (que al menos INTENTABA un
 * fallback, aunque roto). Un Tour Manager nunca pudo guardar una
 * habitación, ni en wp-admin. Corregido al mismo criterio que el resto del
 * panel (`manage_amir_booking`).
 */
class RoomsSection {

	public static function needs_media(): bool {
		return true;
	}

	public static function render( string $base_url, string $lang ): void {
		if ( AMIR_EDITION !== 'pro_max' ) {
			wp_die( esc_html( self::tt( $lang, 'Esta sección no está disponible en tu edición de TourFlow.', 'This section is not available in your TourFlow edition.' ) ) );
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
			wp_die( esc_html( self::tt( $lang, 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
		}

		$message = self::handle_save( $base_url, $lang );

		$action = sanitize_key( $_GET['action'] ?? 'list' );

		if ( $action === 'new' ) {
			$new_id = wp_insert_post( [
				'post_type'   => \TourFlow\Rooms\RoomPostType::POST_TYPE,
				'post_status' => 'auto-draft',
				'post_title'  => self::tt( $lang, '(sin título)', '(untitled)' ),
			], true );
			if ( is_wp_error( $new_id ) ) {
				wp_die( esc_html( $new_id->get_error_message() ) );
			}
			wp_safe_redirect( add_query_arg( [ 'action' => 'edit', 'id' => $new_id ], $base_url ) );
			exit;
		}

		if ( $action === 'edit' && ! empty( $_GET['id'] ) ) {
			self::render_edit( (int) $_GET['id'], $base_url, $lang );
		} else {
			self::render_list( $base_url, $lang );
		}
	}

	// ==================== GUARDADO ====================

	private static function handle_save( string $base_url, string $lang ): string {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ( $_POST['tfp_room_action'] ?? '' ) !== 'save' ) {
			return '';
		}
		if ( ! check_admin_referer( 'tfp_room_action', '_wpnonce', false ) ) {
			wp_die( esc_html( self::tt( $lang, 'Sesión expirada — recargá la página e intentá de nuevo.', 'Session expired — reload the page and try again.' ) ) );
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
			wp_die( esc_html( self::tt( $lang, 'No tienes permisos suficientes.', 'You do not have sufficient permissions.' ) ) );
		}

		$post_id = absint( $_POST['room_post_id'] ?? 0 );
		$title   = sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) );
		if ( $title === '' ) {
			$title = self::tt( $lang, '(sin título)', '(untitled)' );
		}

		$postarr = [ 'post_title' => $title ];

		if ( $post_id ) {
			$existing = get_post( $post_id );
			if ( ! $existing || $existing->post_type !== \TourFlow\Rooms\RoomPostType::POST_TYPE ) {
				wp_die( esc_html( self::tt( $lang, 'Habitación no válida.', 'Invalid room.' ) ) );
			}
			$postarr['ID']          = $post_id;
			$postarr['post_status'] = $existing->post_status === 'auto-draft' ? 'publish' : $existing->post_status;
			// wp_update_post() dispara save_post_flow_room -> save_meta() +
			// sync_to_db() solos, leyendo este mismo $_POST.
			wp_update_post( $postarr );
		} else {
			$postarr['post_type']   = \TourFlow\Rooms\RoomPostType::POST_TYPE;
			$postarr['post_status'] = 'publish';
			$post_id                = wp_insert_post( $postarr );
		}

		wp_safe_redirect( add_query_arg( [ 'action' => 'edit', 'id' => $post_id, 'saved' => 1 ], $base_url ) );
		exit;
	}

	// ==================== LISTADO ====================

	private static function render_list( string $base_url, string $lang ): void {
		$posts = get_posts( [
			'post_type'      => \TourFlow\Rooms\RoomPostType::POST_TYPE,
			'post_status'    => [ 'publish', 'draft', 'pending' ],
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		echo '<div class="tfp-title-row"><h1>' . esc_html( self::tt( $lang, 'Habitaciones', 'Rooms' ) ) . '</h1>'
			. '<a class="tfp-btn tfp-btn--primary" style="width:auto;" href="' . esc_url( add_query_arg( [ 'action' => 'new' ], $base_url ) ) . '">+ ' . esc_html( self::tt( $lang, 'Nueva habitación', 'New room' ) ) . '</a></div>';

		if ( empty( $posts ) ) {
			echo '<div class="tfp-card">' . esc_html( self::tt( $lang, 'Todavía no hay habitaciones cargadas.', 'No rooms yet.' ) ) . '</div>';
			return;
		}

		echo '<div class="tfp-card"><table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html( self::tt( $lang, 'Habitación', 'Room' ) ) . '</th>'
			. '<th>' . esc_html( self::tt( $lang, 'Estado', 'Status' ) ) . '</th>'
			. '<th></th></tr></thead><tbody>';

		foreach ( $posts as $p ) {
			$edit_url = add_query_arg( [ 'action' => 'edit', 'id' => $p->ID ], $base_url );
			echo '<tr>'
				. '<td><a href="' . esc_url( $edit_url ) . '"><strong>' . esc_html( $p->post_title ) . '</strong></a></td>'
				. '<td>' . esc_html( self::status_label( $lang, $p->post_status ) ) . '</td>'
				. '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( self::tt( $lang, 'Editar →', 'Edit →' ) ) . '</a></td>'
				. '</tr>';
		}

		echo '</tbody></table></div>';
	}

	private static function status_label( string $lang, string $status ): string {
		$map = [
			'publish' => self::tt( $lang, '✓ Publicado', '✓ Published' ),
			'draft'   => self::tt( $lang, '📝 Borrador', '📝 Draft' ),
			'pending' => self::tt( $lang, '⏳ Pendiente de revisión', '⏳ Pending review' ),
		];
		return $map[ $status ] ?? $status;
	}

	// ==================== EDICIÓN ====================

	private static function metabox_list(): array {
		return [
			[ '⚙ ' . __( 'Configuración de la habitación', 'amir-booking' ), 'meta_box_main' ],
			[ '🖼 ' . __( 'Galería de fotos', 'amir-booking' ), 'meta_box_gallery' ],
			[ '✨ ' . __( 'Amenities', 'amir-booking' ), 'meta_box_amenities' ],
		];
	}

	private static function render_edit( int $post_id, string $base_url, string $lang ): void {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== \TourFlow\Rooms\RoomPostType::POST_TYPE ) {
			echo '<div class="tfp-card">' . esc_html( self::tt( $lang, 'Habitación no encontrada.', 'Room not found.' ) ) . '</div>';
			return;
		}

		$is_new = $post->post_status === 'auto-draft';
		$rpt    = new \TourFlow\Rooms\RoomPostType();

		echo '<div class="tfp-title-row">'
			. '<h1><a href="' . esc_url( $base_url ) . '" style="color:#5a7068;font-weight:400;font-size:16px;text-decoration:none;">← ' . esc_html( self::tt( $lang, 'Habitaciones', 'Rooms' ) ) . '</a> '
			. esc_html( $is_new ? self::tt( $lang, 'Nueva habitación', 'New room' ) : $post->post_title ) . '</h1>'
			. '</div>';

		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::tt( $lang, 'Habitación guardada.', 'Room saved.' ) ) . '</p></div>';
		}

		// Disponibilidad de ESTA habitación en particular — atajo directo
		// desde el editor, mismo criterio que la ficha de un tour linkeando
		// a Disponibilidad. RoomAvailabilityPage necesita el id de
		// amir_tours... no, de flow_rooms (_flow_room_db_id), no el post ID.
		$room_db_id = (int) get_post_meta( $post_id, '_flow_room_db_id', true );
		if ( $room_db_id ) {
			$avail_url = ManagerPanel::url( 'disponibilidad-habitaciones', [ 'room_id' => $room_db_id ] );
			echo '<p style="margin:-8px 0 16px;"><a href="' . esc_url( $avail_url ) . '">📅 ' . esc_html( self::tt( $lang, 'Ver disponibilidad de esta habitación →', 'View this room\'s availability →' ) ) . '</a></p>';
		}

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'tfp_room_action' );
		echo '<input type="hidden" name="tfp_room_action" value="save">'
			. '<input type="hidden" name="room_post_id" value="' . (int) $post_id . '">';

		echo '<div class="tfp-card">'
			. '<label style="display:block;font-size:12px;font-weight:700;color:#1a2e24;margin-bottom:5px;text-transform:uppercase;">' . esc_html( self::tt( $lang, 'Nombre de la habitación (ES)', 'Room name (ES)' ) ) . '</label>'
			. '<input type="text" name="post_title" value="' . esc_attr( $is_new ? '' : $post->post_title ) . '" required style="width:100%;padding:9px 10px;border:1px solid #c3d9d0;border-radius:6px;font-size:15px;box-sizing:border-box;">'
			. '</div>';

		foreach ( self::metabox_list() as [ $title, $method ] ) {
			echo '<div class="postbox" style="margin-bottom:16px;">'
				. '<h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2>'
				. '<div class="inside">';
			$rpt->$method( $post );
			echo '</div></div>';
		}

		submit_button( self::tt( $lang, 'Guardar habitación', 'Save room' ) );
		echo '</form>';
	}

	// ==================== HELPERS ====================

	private static function tt( string $lang, string $es, string $en ): string {
		return $lang === 'en' ? $en : $es;
	}
}
