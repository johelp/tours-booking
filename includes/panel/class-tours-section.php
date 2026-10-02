<?php
namespace TourFlow\Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Tours dentro del panel de gestión sin wp-admin — la sección con más
 * trabajo real de todo el panel (ver PROMPT-PANEL-GESTOR.md). A diferencia
 * de las demás (Reservas, Calendario, etc., que son páginas admin comunes
 * reusadas tal cual), el editor de tours es un CPT integrado al post-edit
 * screen nativo de WordPress — no hay un `render()` único para reusar.
 *
 * Investigado a fondo antes de construir esto (`TourPostType`, ~2500
 * líneas): cada metabox (`meta_box_main`, `meta_box_pricing`, etc.) es un
 * método PÚBLICO auto-contenido, sin un solo `admin_url()`/AJAX propio —
 * son formularios puros. Y el guardado entero (`save_meta()` +
 * `sync_to_db()`, precios/horarios/addons incluidos) ya cuelga del hook
 * nativo `save_post_amir_tour`, que `wp_insert_post()`/`wp_update_post()`
 * disparan solos. Conclusión: NO hace falta ningún refactor de guardado
 * (la fase 2 original de PROMPT-PANEL-GESTOR.md asumía que sí, antes de
 * esta investigación) — alcanza con:
 *   1. Armar un <form> propio que invoque cada meta_box_X($post) en el
 *      mismo orden que add_meta_boxes() (mismo criterio de edición vía
 *      AMIR_EDITION que ya usa esa función).
 *   2. Al enviarse, llamar wp_insert_post()/wp_update_post() con los
 *      datos base (título) — WordPress dispara save_post_amir_tour solo,
 *      que llama a save_meta()/sync_to_db() leyendo el mismo $_POST que
 *      mandó nuestro form (los nombres de campo son idénticos, porque
 *      literalmente reusamos los mismos metabox callbacks).
 *   3. "Nuevo tour" crea un auto-draft primero (wp_insert_post con
 *      post_status='auto-draft') antes de mostrar el formulario — mismo
 *      truco que usa post-new.php de WordPress, necesario porque los
 *      metabox callbacks esperan un \WP_Post real con ID válido.
 *
 * Único cambio de fondo necesario: `TourPostType::save_meta()` chequeaba
 * `current_user_can('edit_amir_tour', $id)` — una capability que nunca
 * existió de verdad (bug real encontrado en el camino, ver el comentario
 * en esa función) — corregido a `manage_amir_booking`, el mismo criterio
 * que ya usa cada pantalla de este panel.
 */
class ToursSection {

	/** wp.media (galería, itinerario) necesita wp_enqueue_media() antes de imprimir estilos/scripts — ver ManagerPanel::render_shell(). */
	public static function needs_media(): bool {
		return true;
	}

	public static function render( string $base_url, string $lang ): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
			wp_die( esc_html( self::tt( $lang, 'No tienes permisos suficientes para acceder a esta página.', 'You do not have sufficient permissions to access this page.' ) ) );
		}

		$message = self::handle_save( $base_url, $lang );

		$action = sanitize_key( $_GET['action'] ?? 'list' );

		if ( $action === 'new' ) {
			// Mismo truco que wp-admin/post-new.php: crear el auto-draft
			// ANTES de mostrar el formulario — los meta_box_X() esperan un
			// \WP_Post real, y sync_to_db() ignora los auto-draft a
			// propósito (no crea la fila en amir_tours hasta el primer
			// guardado real).
			$new_id = wp_insert_post( [
				'post_type'   => \AmirBooking\CPT\TourPostType::POST_TYPE,
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
			self::render_edit( (int) $_GET['id'], $base_url, $lang, $message );
		} else {
			self::render_list( $base_url, $lang );
		}
	}

	// ==================== GUARDADO ====================

	/**
	 * Devuelve un mensaje de éxito/error para mostrar tras el redirect
	 * (PRG — evita reenviar el form al refrescar), o '' si no hubo POST.
	 */
	private static function handle_save( string $base_url, string $lang ): string {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ( $_POST['tfp_tour_action'] ?? '' ) !== 'save' ) {
			return '';
		}
		if ( ! check_admin_referer( 'tfp_tour_action', '_wpnonce', false ) ) {
			wp_die( esc_html( self::tt( $lang, 'Sesión expirada — recargá la página e intentá de nuevo.', 'Session expired — reload the page and try again.' ) ) );
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_amir_booking' ) ) {
			wp_die( esc_html( self::tt( $lang, 'No tienes permisos suficientes.', 'You do not have sufficient permissions.' ) ) );
		}

		$post_id = absint( $_POST['tour_post_id'] ?? 0 );
		$title   = sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) );
		if ( $title === '' ) {
			$title = self::tt( $lang, '(sin título)', '(untitled)' );
		}

		$postarr = [ 'post_title' => $title ];

		if ( $post_id ) {
			$existing = get_post( $post_id );
			if ( ! $existing || $existing->post_type !== \AmirBooking\CPT\TourPostType::POST_TYPE ) {
				wp_die( esc_html( self::tt( $lang, 'Tour no válido.', 'Invalid tour.' ) ) );
			}
			$postarr['ID']          = $post_id;
			// auto-draft -> publish en el primer guardado real (mismo
			// criterio que wp-admin: un tour recién creado pasa a
			// publicado apenas se guarda por primera vez).
			$postarr['post_status'] = $existing->post_status === 'auto-draft' ? 'publish' : $existing->post_status;
			// wp_update_post() dispara save_post_amir_tour -> save_meta()
			// + sync_to_db() SOLOS, leyendo este mismo $_POST — cero
			// lógica de guardado propia acá, ver docstring de la clase.
			wp_update_post( $postarr );
		} else {
			$postarr['post_type']   = \AmirBooking\CPT\TourPostType::POST_TYPE;
			$postarr['post_status'] = 'publish';
			$post_id                = wp_insert_post( $postarr );
		}

		wp_safe_redirect( add_query_arg( [ 'action' => 'edit', 'id' => $post_id, 'saved' => 1 ], $base_url ) );
		exit;
	}

	// ==================== LISTADO ====================

	private static function render_list( string $base_url, string $lang ): void {
		$posts = get_posts( [
			'post_type'      => \AmirBooking\CPT\TourPostType::POST_TYPE,
			'post_status'    => [ 'publish', 'draft', 'pending' ], // auto-draft (abandonados sin guardar) fuera de la lista a propósito
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		echo '<div class="tfp-title-row"><h1>' . esc_html( self::tt( $lang, 'Tours', 'Tours' ) ) . '</h1>'
			. '<a class="tfp-btn tfp-btn--primary" style="width:auto;" href="' . esc_url( add_query_arg( [ 'action' => 'new' ], $base_url ) ) . '">+ ' . esc_html( self::tt( $lang, 'Nuevo tour', 'New tour' ) ) . '</a></div>';

		if ( empty( $posts ) ) {
			echo '<div class="tfp-card">' . esc_html( self::tt( $lang, 'Todavía no hay tours cargados.', 'No tours yet.' ) ) . '</div>';
			return;
		}

		echo '<div class="tfp-card"><table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html( self::tt( $lang, 'Tour', 'Tour' ) ) . '</th>'
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

	/**
	 * Orden y edición-gate IDÉNTICO a TourPostType::add_meta_boxes() — se
	 * mantiene sincronizado a mano porque add_meta_boxes() está pensado
	 * para el hook de WordPress (recibe $post_type, no devuelve nada), no
	 * hay una versión "solo dame la lista" que reusar directo.
	 */
	private static function metabox_list(): array {
		$boxes = [
			[ '⚙ ' . __( 'Configuración del tour', 'amir-booking' ), 'meta_box_main' ],
			[ '📝 ' . __( 'Contenido bilingüe (EN)', 'amir-booking' ), 'meta_box_content' ],
			[ '🖼 ' . __( 'Galería de fotos', 'amir-booking' ), 'meta_box_gallery' ],
			[ '💰 ' . __( 'Precios y horarios', 'amir-booking' ), 'meta_box_pricing' ],
			[ '🎁 ' . __( 'Servicios extra', 'amir-booking' ), 'meta_box_addons' ],
			[ '📅 ' . __( 'Disponibilidad', 'amir-booking' ), 'meta_box_avail' ],
			[ '📍 ' . __( 'Punto de encuentro', 'amir-booking' ), 'meta_box_meeting' ],
			[ '🔗 ' . __( 'Integraciones externas', 'amir-booking' ), 'meta_box_integr' ],
			[ '❓ ' . __( 'Preguntas frecuentes (FAQ)', 'amir-booking' ), 'meta_box_faq' ],
		];
		if ( in_array( AMIR_EDITION, [ 'pro', 'pro_max' ], true ) ) {
			$boxes[] = [ '✨ ' . __( 'Datos destacados', 'amir-booking' ), 'meta_box_facts' ];
			$boxes[] = [ '🗺 ' . __( 'Itinerario (timeline)', 'amir-booking' ), 'meta_box_itinerary' ];
		}
		return $boxes;
	}

	private static function render_edit( int $post_id, string $base_url, string $lang, string $saved_flag ): void {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== \AmirBooking\CPT\TourPostType::POST_TYPE ) {
			echo '<div class="tfp-card">' . esc_html( self::tt( $lang, 'Tour no encontrado.', 'Tour not found.' ) ) . '</div>';
			return;
		}

		$is_new = $post->post_status === 'auto-draft';
		$tpt    = new \AmirBooking\CPT\TourPostType();

		echo '<div class="tfp-title-row">'
			. '<h1><a href="' . esc_url( $base_url ) . '" style="color:#5a7068;font-weight:400;font-size:16px;text-decoration:none;">← ' . esc_html( self::tt( $lang, 'Tours', 'Tours' ) ) . '</a> '
			. esc_html( $is_new ? self::tt( $lang, 'Nuevo tour', 'New tour' ) : $post->post_title ) . '</h1>'
			. '</div>';

		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::tt( $lang, 'Tour guardado.', 'Tour saved.' ) ) . '</p></div>';
		}

		self::render_type_help( $lang );

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'tfp_tour_action' ); // nonce propio del form del panel — el de amir_tour_meta lo trae meta_box_main() más abajo
		echo '<input type="hidden" name="tfp_tour_action" value="save">'
			. '<input type="hidden" name="tour_post_id" value="' . (int) $post_id . '">';

		echo '<div class="tfp-card">'
			. '<label style="display:block;font-size:12px;font-weight:700;color:#1a2e24;margin-bottom:5px;text-transform:uppercase;">' . esc_html( self::tt( $lang, 'Nombre del tour (ES)', 'Tour name (ES)' ) ) . '</label>'
			. '<input type="text" name="post_title" value="' . esc_attr( $is_new ? '' : $post->post_title ) . '" required style="width:100%;padding:9px 10px;border:1px solid #c3d9d0;border-radius:6px;font-size:15px;box-sizing:border-box;">'
			. '</div>';

		foreach ( self::metabox_list() as [ $title, $method ] ) {
			echo '<div class="postbox" style="margin-bottom:16px;">'
				. '<h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2>'
				. '<div class="inside">';
			$tpt->$method( $post );
			echo '</div></div>';
		}

		submit_button( self::tt( $lang, 'Guardar tour', 'Save tour' ) );
		echo '</form>';
	}

	/**
	 * Resumen de docs-manual/18-tipos-de-tour.md, pedido explícito del
	 * cliente 2026-09-12: "la carga y edición de tours debe ser óptima,
	 * práctica, entendible — saber cómo configurar cada tipo, si tiene
	 * fechas, si se repite por días". En vez de duplicar el manual entero
	 * (quedaría desactualizado), un resumen corto de los dos ejes que más
	 * confunden (Disponibilidad, Cobro) con dónde tocar cada uno — el
	 * resto de los ejes (horario, origen, precio) ya son bastante directos
	 * dentro de sus propias metaboxes.
	 */
	private static function render_type_help( string $lang ): void {
		$es = '<strong>¿Qué tipo de tour necesito?</strong> Elegí uno de estos en <em>📅 Disponibilidad</em> (dentro de <em>⚙ Configuración del tour</em>, más abajo):
			<ul style="margin:8px 0 0;padding-left:20px;">
			<li><strong>Calendario normal / días determinados</strong> — el default. El cliente reserva al instante entre los días que dejes abiertos (días de la semana acá + reglas puntuales en TourFlow → Disponibilidad).</li>
			<li><strong>📌 Fecha fija</strong> — para un evento único, una sola fecha. El widget no muestra calendario, va directo a esa fecha.</li>
			<li><strong>🙋 Solo a pedido</strong> — el cliente ve calendario normal, pero ninguna reserva se cobra al instante: nace como solicitud y vos la aprobás desde Reservas.</li>
			<li><strong>🧩 Armá tu tour</strong> — variante de "Solo a pedido", para un tour sin precio fijo (vos cargás el precio real al aprobar).</li>
			</ul>
			<strong>¿Cobro completo o depósito?</strong> Por defecto se cobra el 100% al reservar — el checkbox "💰 Depósito parcial" (si tu edición lo tiene) está en la misma sección.';
		$en = '<strong>Which tour type do I need?</strong> Pick one of these under <em>📅 Availability</em> (inside <em>⚙ Tour settings</em>, below):
			<ul style="margin:8px 0 0;padding-left:20px;">
			<li><strong>Normal calendar / set days</strong> — the default. The customer books instantly among the days you leave open (weekdays here + specific rules in TourFlow → Availability).</li>
			<li><strong>📌 Fixed date</strong> — for a one-time event, a single date. The widget skips the calendar and goes straight to that date.</li>
			<li><strong>🙋 On request only</strong> — the customer sees a normal calendar, but no booking charges instantly: it starts as a request you approve from Bookings.</li>
			<li><strong>🧩 Build your own tour</strong> — a variant of "On request only", for a tour with no fixed price (you set the real price when approving).</li>
			</ul>
			<strong>Full charge or deposit?</strong> By default the customer pays 100% when booking — the "💰 Partial deposit" checkbox (if your edition has it) lives in the same section.';

		echo '<details class="tfp-card" style="background:#eef4ff;border-color:#bfdbfe;">'
			. '<summary style="cursor:pointer;font-weight:700;color:#1a6fa8;">💡 ' . esc_html( self::tt( $lang, 'Guía rápida: tipos de tour', 'Quick guide: tour types' ) ) . '</summary>'
			. '<div style="margin-top:10px;font-size:13px;color:#1e3a5f;line-height:1.7;">' . wp_kses_post( self::tt( $lang, $es, $en ) ) . '</div>'
			. '</details>';
	}

	// ==================== HELPERS ====================

	private static function tt( string $lang, string $es, string $en ): string {
		return $lang === 'en' ? $en : $es;
	}
}
