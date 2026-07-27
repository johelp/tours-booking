<?php
namespace AmirBooking\Elementor;

defined( 'ABSPATH' ) || exit;

// Este archivo solo se carga desde dentro de los hooks de Elementor,
// cuando \Elementor\Widget_Base y \Elementor\Core\DynamicTags\Tag ya existen.

// ── Widget: Botón de reserva ──────────────────────────────────────────────────

class BookingButtonWidget extends \Elementor\Widget_Base {

    public function get_name()  { return 'amir_booking_button'; }
    public function get_title() { return __( 'Botón Reservar Tour', 'amir-booking' ); }
    public function get_icon()  { return 'eicon-calendar'; }
    public function get_categories() { return [ 'amir-booking' ]; }
    public function get_keywords()   { return [ 'booking', 'reserva', 'tour', 'amir' ]; }

    protected function register_controls(): void {

        $this->start_controls_section( 'content', [
            'label' => __( 'Configuración', 'amir-booking' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'tour_source', [
            'label'   => __( 'Fuente del tour', 'amir-booking' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'current',
            'options' => [
                'current' => __( 'Tour actual (Loop Builder)', 'amir-booking' ),
                'manual'  => __( 'Tour específico', 'amir-booking' ),
            ],
        ] );

        $this->add_control( 'tour_id', [
            'label'     => __( 'Seleccionar tour', 'amir-booking' ),
            'type'      => \Elementor\Controls_Manager::SELECT,
            'options'   => $this->get_tours_options(),
            'condition' => [ 'tour_source' => 'manual' ],
        ] );

        $this->add_control( 'display_mode', [
            'label'   => __( 'Mostrar como', 'amir-booking' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'inline',
            'options' => [
                'inline' => __( 'Widget inline (en la misma página)', 'amir-booking' ),
                'modal'  => __( 'Modal / Popup', 'amir-booking' ),
                'link'   => __( 'Link a página del tour', 'amir-booking' ),
            ],
        ] );

        $this->add_control( 'button_text', [
            'label'   => __( 'Texto del botón', 'amir-booking' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => __( 'Reservar ahora', 'amir-booking' ),
        ] );

        $this->add_control( 'lang', [
            'label'   => __( 'Idioma del widget', 'amir-booking' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'auto',
            'options' => [
                'auto' => __( 'Automático (idioma del sitio)', 'amir-booking' ),
                'es'   => 'Español',
                'en'   => 'English',
            ],
        ] );

        $this->end_controls_section();

        // ── Estilo ────────────────────────────────────────────────────────
        $this->start_controls_section( 'style', [
            'label' => __( 'Estilo del botón', 'amir-booking' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'button_color', [
            'label'     => __( 'Color de fondo', 'amir-booking' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#1D9E75',
            'selectors' => [ '{{WRAPPER}} .amir-el-btn' => 'background-color: {{VALUE}}; border-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'button_text_color', [
            'label'     => __( 'Color de texto', 'amir-booking' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .amir-el-btn' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'button_typography',
            'selector' => '{{WRAPPER}} .amir-el-btn',
        ] );

        $this->add_control( 'button_radius', [
            'label'      => __( 'Radio de borde', 'amir-booking' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'default'    => [ 'unit' => 'px', 'size' => 8 ],
            'selectors'  => [ '{{WRAPPER}} .amir-el-btn' => 'border-radius: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'button_shadow',
            'selector' => '{{WRAPPER}} .amir-el-btn',
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        // Resolver tour_id: current post (loop) o manual
        $tour_id = 0;
        if ( $settings['tour_source'] === 'current' ) {
            $post_id = get_the_ID();
            $tour_id = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );
        } else {
            $tour_id = (int) $settings['tour_id'];
        }

        if ( ! $tour_id ) {
            if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                echo '<p style="color:#e24b4a;font-size:13px;">⚠ Selecciona un tour o usa este widget dentro de un Loop Builder.</p>';
            }
            return;
        }

        $lang = $settings['lang'] === 'auto'
            ? ( function_exists('pll_current_language') ? pll_current_language('slug') : 'es' )
            : $settings['lang'];
        $lang = \AmirBooking\Core\Languages::is_active( (string) $lang ) ? $lang : \AmirBooking\Core\Languages::default_lang();

        $btn_text = esc_html( $settings['button_text'] ?: __('Reservar ahora','amir-booking') );
        $mode     = $settings['display_mode'];

        if ( $mode === 'link' ) {
            $post = get_posts(['post_type'=>\AmirBooking\CPT\TourPostType::POST_TYPE,'meta_key'=>'_amir_tour_db_id','meta_value'=>$tour_id,'posts_per_page'=>1]);
            $url  = $post ? get_permalink($post[0]->ID) : '#';
            echo "<a href='" . esc_url($url) . "' class='amir-el-btn'>{$btn_text}</a>";
            return;
        }

        if ( $mode === 'modal' ) {
            $widget_id = 'amir-modal-' . $tour_id;
            echo "
            <button class='amir-el-btn' onclick=\"document.getElementById('{$widget_id}').style.display='flex'\">{$btn_text}</button>
            <div id='{$widget_id}' style='display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;align-items:center;justify-content:center;padding:16px;' onclick=\"if(event.target===this)this.style.display='none'\">
              <div style='max-width:500px;width:100%;max-height:90vh;overflow-y:auto;border-radius:16px;position:relative;'>
                <button onclick=\"document.getElementById('{$widget_id}').style.display='none'\" style='position:absolute;top:12px;right:12px;z-index:2;background:#fff;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:18px;color:#666;'>✕</button>
                <div data-amir-booking='1' data-tour-id='{$tour_id}' data-lang='{$lang}'></div>
              </div>
            </div>";
            return;
        }

        // Inline
        echo "<button class='amir-el-btn' onclick=\"document.getElementById('amir-inline-{$tour_id}').scrollIntoView({behavior:'smooth'})\">{$btn_text}</button>
              <div id='amir-inline-{$tour_id}' data-amir-booking='1' data-tour-id='{$tour_id}' data-lang='{$lang}' style='margin-top:20px;'></div>";
    }

    private function get_tours_options(): array {
        global $wpdb;
        $tours = $wpdb->get_results(
            "SELECT id, name_es FROM {$wpdb->prefix}amir_tours WHERE status='active' ORDER BY sort_order"
        ) ?? [];
        $opts = [ '' => __('— Seleccionar —','amir-booking') ];
        foreach ( $tours as $t ) {
            $opts[ $t->id ] = $t->name_es;
        }
        return $opts;
    }
}

// ── Widget: Tarjeta de tour ───────────────────────────────────────────────────

class TourCardWidget extends \Elementor\Widget_Base {

    public function get_name()  { return 'amir_tour_card'; }
    public function get_title() { return __( 'Tarjeta de Tour', 'amir-booking' ); }
    public function get_icon()  { return 'eicon-image-box'; }
    public function get_categories() { return [ 'amir-booking' ]; }
    public function get_keywords()   { return [ 'tour', 'card', 'tarjeta', 'amir' ]; }

    protected function register_controls(): void {
        $this->start_controls_section( 'content', [
            'label' => __( 'Contenido', 'amir-booking' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'show_price',    [ 'label'=>__('Mostrar precio','amir-booking'),    'type'=>\Elementor\Controls_Manager::SWITCHER, 'default'=>'yes' ] );
        $this->add_control( 'show_duration', [ 'label'=>__('Mostrar duración','amir-booking'),  'type'=>\Elementor\Controls_Manager::SWITCHER, 'default'=>'yes' ] );
        $this->add_control( 'show_min_age',  [ 'label'=>__('Mostrar edad mín.','amir-booking'), 'type'=>\Elementor\Controls_Manager::SWITCHER, 'default'=>'yes' ] );
        $this->add_control( 'cta_text',      [ 'label'=>__('Texto del CTA','amir-booking'),     'type'=>\Elementor\Controls_Manager::TEXT, 'default'=>'Reservar' ] );
        $this->end_controls_section();

        // ── Estilo ────────────────────────────────────────────────────────
        $this->start_controls_section( 'style', [
            'label' => __( 'Estilo de la tarjeta', 'amir-booking' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'card_bg_color', [
            'label'     => __( 'Color de fondo', 'amir-booking' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} .amir-tour-card' => 'background-color: {{VALUE}};' ],
        ] );

        $this->add_control( 'card_title_color', [
            'label'     => __( 'Color del título', 'amir-booking' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#1a2e24',
            'selectors' => [ '{{WRAPPER}} .amir-tour-card__title' => 'color: {{VALUE}};' ],
        ] );

        $this->add_control( 'card_accent_color', [
            'label'     => __( 'Color de acento (precio y CTA)', 'amir-booking' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#1D9E75',
            'selectors' => [
                '{{WRAPPER}} .amir-tour-card__price-value' => 'color: {{VALUE}};',
                '{{WRAPPER}} .amir-tour-card__cta'         => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
            ],
        ] );

        $this->add_control( 'card_radius', [
            'label'     => __( 'Radio de borde', 'amir-booking' ),
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'range'     => [ 'px' => [ 'min' => 0, 'max' => 50 ] ],
            'default'   => [ 'unit' => 'px', 'size' => 16 ],
            'selectors' => [ '{{WRAPPER}} .amir-tour-card' => 'border-radius: {{SIZE}}{{UNIT}}; overflow: hidden;' ],
        ] );

        $this->add_control( 'card_image_ratio', [
            'label'   => __( 'Proporción de la imagen', 'amir-booking' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '4/3',
            'options' => [
                '4/3'  => '4:3',
                '16/9' => '16:9',
                '1/1'  => __( 'Cuadrada', 'amir-booking' ) . ' (1:1)',
                '3/4'  => __( 'Vertical', 'amir-booking' ) . ' (3:4)',
            ],
            'selectors' => [ '{{WRAPPER}} .amir-tour-card__img' => 'aspect-ratio: {{VALUE}}; object-fit: cover; width: 100%; display: block;' ],
        ] );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'card_shadow',
            'selector' => '{{WRAPPER}} .amir-tour-card',
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $s       = $this->get_settings_for_display();
        $post_id = get_the_ID();
        $db_id   = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );

        if ( ! $db_id && ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
            return;
        }

        global $wpdb;
        $price_from = $db_id ? (float)$wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices WHERE tour_id=%d AND price_mxn>0", $db_id
        ) ) : 0;

        $duration = (int) get_post_meta( $post_id, '_amir_duration_minutes', true );
        $min_age  = (int) get_post_meta( $post_id, '_amir_min_age', true );
        $thumb    = get_the_post_thumbnail_url( $post_id, 'large' );
        $link     = get_permalink( $post_id );
        $name     = get_the_title();
        ?>
        <div class="amir-tour-card">
          <?php if ( $thumb ) : ?>
            <a href="<?php echo esc_url($link); ?>">
              <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($name); ?>" class="amir-tour-card__img" />
            </a>
          <?php endif; ?>
          <div class="amir-tour-card__body">
            <h3 class="amir-tour-card__title"><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($name); ?></a></h3>
            <div class="amir-tour-card__meta">
              <?php if ( $s['show_duration'] === 'yes' && $duration ) : ?>
                <span>⏱ <?php echo $duration >= 60 ? round($duration/60,1) . ' h' : $duration . ' min'; ?></span>
              <?php endif; ?>
              <?php if ( $s['show_min_age'] === 'yes' && $min_age ) : ?>
                <span>👤 <?php echo $min_age; ?>+ años</span>
              <?php endif; ?>
            </div>
            <?php if ( $s['show_price'] === 'yes' && $price_from > 0 ) : ?>
              <div class="amir-tour-card__price">
                <span class="amir-tour-card__price-from">Desde</span>
                <span class="amir-tour-card__price-value">$<?php echo number_format($price_from,0,'.',','); ?></span>
                <span class="amir-tour-card__price-currency"><?php echo esc_html( \AmirBooking\Core\Currency::code() ); ?></span>
              </div>
            <?php endif; ?>
            <a href="<?php echo esc_url($link); ?>" class="amir-tour-card__cta">
              <?php echo esc_html( $s['cta_text'] ?: 'Reservar' ); ?>
            </a>
          </div>
        </div>
        <?php
    }
}

// ── Dynamic Tags ──────────────────────────────────────────────────────────────

trait TourTagBase {
    public function get_group()      { return 'amir-tour'; }
    public function get_categories() { return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ]; }

    protected function register_controls(): void {
        // Los tags leen del post actual — sin configuración adicional
    }

    /**
     * Fila completa de amir_tours para el post actual, cacheada por post_id
     * para no repetir la query si varios Dynamic Tags renderizan en el mismo
     * request (común: varios tags del mismo tour en una tarjeta de Loop Grid).
     */
    protected function get_tour_row(): ?object {
        static $cache = [];
        $post_id = get_the_ID();
        if ( array_key_exists( $post_id, $cache ) ) {
            return $cache[ $post_id ];
        }

        $db_id = (int) get_post_meta( $post_id, '_amir_tour_db_id', true );
        if ( ! $db_id ) {
            return $cache[ $post_id ] = null;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT name_es, name_en, description_es, description_en,
                    what_to_expect_es, what_to_expect_en,
                    meeting_point_es, meeting_point_en,
                    includes_es, includes_en, excludes_es, excludes_en,
                    itinerary_es, itinerary_en, content_i18n
             FROM {$wpdb->prefix}amir_tours WHERE id = %d",
            $db_id
        ) );

        return $cache[ $post_id ] = $row;
    }

    /**
     * Mismo patrón que ya usa BookingButtonWidget::render() — Polylang si
     * está activo, si no español, validado contra los idiomas realmente
     * activos del plugin (para no devolver un idioma que el operador
     * todavía no cargó contenido).
     */
    protected function current_lang(): string {
        $lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'es';
        return \AmirBooking\Core\Languages::is_active( (string) $lang )
            ? $lang
            : \AmirBooking\Core\Languages::default_lang();
    }

    /**
     * Helper para los tags de listas (incluye/no incluye) — content_i18n
     * guarda arrays, pero las columnas es/en guardan JSON string.
     */
    protected function field_as_list( $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}

class DynamicTagPriceFrom extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-price-from'; }
    public function get_title() { return __( 'Tour: Precio desde', 'amir-booking' ) . ' (' . \AmirBooking\Core\Currency::code() . ')'; }

    public function render(): void {
        $db_id = (int) get_post_meta( get_the_ID(), '_amir_tour_db_id', true );
        if ( ! $db_id ) return;
        global $wpdb;
        $price = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices WHERE tour_id=%d AND price_mxn>0", $db_id
        ) );
        echo $price > 0 ? esc_html( \AmirBooking\Core\Currency::format( $price, 0 ) ) : '';
    }
}

class DynamicTagDuration extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-duration'; }
    public function get_title() { return __( 'Tour: Duración', 'amir-booking' ); }

    public function render(): void {
        $mins = (int) get_post_meta( get_the_ID(), '_amir_duration_minutes', true );
        if ( ! $mins ) return;
        echo $mins >= 60 ? round($mins/60,1) . ' h' : $mins . ' min';
    }
}

class DynamicTagNameEn extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-name-en'; }
    public function get_title() { return __( 'Tour: Nombre EN', 'amir-booking' ); }

    public function render(): void {
        echo esc_html( get_post_meta( get_the_ID(), '_amir_name_en', true ) );
    }
}

class DynamicTagMinAge extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-min-age'; }
    public function get_title() { return __( 'Tour: Edad mínima', 'amir-booking' ); }

    public function render(): void {
        $age = (int) get_post_meta( get_the_ID(), '_amir_min_age', true );
        echo $age ? $age . '+ años' : '';
    }
}

class DynamicTagGallery extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()       { return 'amir-gallery'; }
    public function get_title()      { return __( 'Tour: URL foto principal', 'amir-booking' ); }
    public function get_categories() { return [ \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY, \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ]; }

    public function render(): void {
        echo esc_url( get_the_post_thumbnail_url( get_the_ID(), 'large' ) );
    }
}

// ── Dynamic Tags multi-idioma (usan Languages::tour_field(), a diferencia
// de los de arriba que son numéricos/fijos y no necesitan traducción) ────────

class DynamicTagName extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-name'; }
    public function get_title() { return __( 'Tour: Nombre (idioma actual)', 'amir-booking' ); }

    public function render(): void {
        $tour = $this->get_tour_row();
        if ( ! $tour ) return;
        echo esc_html( \AmirBooking\Core\Languages::tour_field( $tour, 'name', $this->current_lang() ) );
    }
}

class DynamicTagDescription extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-description'; }
    public function get_title() { return __( 'Tour: Descripción', 'amir-booking' ); }

    public function render(): void {
        $tour = $this->get_tour_row();
        if ( ! $tour ) return;
        echo wp_kses_post( \AmirBooking\Core\Languages::tour_field( $tour, 'description', $this->current_lang() ) );
    }
}

class DynamicTagWhatToExpect extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-what-to-expect'; }
    public function get_title() { return __( 'Tour: Qué esperar', 'amir-booking' ); }

    public function render(): void {
        $tour = $this->get_tour_row();
        if ( ! $tour ) return;
        echo wp_kses_post( \AmirBooking\Core\Languages::tour_field( $tour, 'what_to_expect', $this->current_lang() ) );
    }
}

class DynamicTagMeetingPoint extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-meeting-point'; }
    public function get_title() { return __( 'Tour: Punto de encuentro', 'amir-booking' ); }

    public function render(): void {
        $tour = $this->get_tour_row();
        if ( ! $tour ) return;
        echo esc_html( \AmirBooking\Core\Languages::tour_field( $tour, 'meeting_point', $this->current_lang() ) );
    }
}

class DynamicTagIncludes extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-includes'; }
    public function get_title() { return __( 'Tour: Incluye', 'amir-booking' ); }

    public function render(): void {
        $tour = $this->get_tour_row();
        if ( ! $tour ) return;
        $items = $this->field_as_list( \AmirBooking\Core\Languages::tour_field( $tour, 'includes', $this->current_lang() ) );
        echo esc_html( implode( ' · ', $items ) );
    }
}

class DynamicTagExcludes extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-excludes'; }
    public function get_title() { return __( 'Tour: No incluye', 'amir-booking' ); }

    public function render(): void {
        $tour = $this->get_tour_row();
        if ( ! $tour ) return;
        $items = $this->field_as_list( \AmirBooking\Core\Languages::tour_field( $tour, 'excludes', $this->current_lang() ) );
        echo esc_html( implode( ' · ', $items ) );
    }
}

class DynamicTagItinerary extends \Elementor\Core\DynamicTags\Tag {
    use TourTagBase;
    public function get_name()  { return 'amir-itinerary'; }
    public function get_title() { return __( 'Tour: Itinerario', 'amir-booking' ); }

    public function render(): void {
        $tour = $this->get_tour_row();
        if ( ! $tour ) return;
        echo wp_kses_post( \AmirBooking\Core\Languages::tour_field( $tour, 'itinerary', $this->current_lang() ) );
    }
}
