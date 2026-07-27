<?php
namespace AmirBooking\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Integración con Elementor. Completamente opcional.
 * Si Elementor no está instalado y activo, este módulo no hace nada.
 */
class ElementorIntegration {

    public function register(): void {
        // Salir silenciosamente si Elementor no está instalado
        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            return;
        }

        // elementor/loaded ya disparó (plugins_loaded ocurre después)
        if ( did_action( 'elementor/loaded' ) ) {
            $this->init();
        } else {
            add_action( 'elementor/loaded', [ $this, 'init' ] );
        }
    }

    public function init(): void {
        // Verificar que las clases base de Elementor existen antes de cargar widgets
        if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
            return;
        }

        require_once AMIR_PLUGIN_DIR . 'includes/elementor/elementor-widgets.php';

        add_action( 'elementor/elements/categories_registered', [ $this, 'add_category' ] );
        add_action( 'elementor/widgets/register',               [ $this, 'register_widgets' ] );
        add_action( 'elementor/dynamic_tags/register',          [ $this, 'register_dynamic_tags' ] );
        add_filter( 'elementor/query/query_args',               [ $this, 'loop_query_args' ], 10, 2 );
    }

    public function add_category( $elements_manager ): void {
        $elements_manager->add_category( 'amir-booking', [
            'title' => __( 'Amir Adventours', 'amir-booking' ),
            'icon'  => 'eicon-calendar',
        ] );
    }

    public function register_widgets( $widgets_manager ): void {
        $widgets_manager->register( new BookingButtonWidget() );
        $widgets_manager->register( new TourCardWidget() );
    }

    public function register_dynamic_tags( $dynamic_tags_manager ): void {
        \Elementor\Plugin::$instance->dynamic_tags->register_group( 'amir-tour', [
            'title' => __( 'Tour Data', 'amir-booking' ),
        ] );
        $dynamic_tags_manager->register( new DynamicTagPriceFrom() );
        $dynamic_tags_manager->register( new DynamicTagDuration() );
        $dynamic_tags_manager->register( new DynamicTagNameEn() );
        $dynamic_tags_manager->register( new DynamicTagMinAge() );
        $dynamic_tags_manager->register( new DynamicTagGallery() );
        $dynamic_tags_manager->register( new DynamicTagName() );
        $dynamic_tags_manager->register( new DynamicTagDescription() );
        $dynamic_tags_manager->register( new DynamicTagWhatToExpect() );
        $dynamic_tags_manager->register( new DynamicTagMeetingPoint() );
        $dynamic_tags_manager->register( new DynamicTagIncludes() );
        $dynamic_tags_manager->register( new DynamicTagExcludes() );
        $dynamic_tags_manager->register( new DynamicTagItinerary() );
    }

    public function loop_query_args( array $query_args, $widget ): array {
        $post_type = isset( $query_args['post_type'] ) ? $query_args['post_type'] : '';
        if ( $post_type !== \AmirBooking\CPT\TourPostType::POST_TYPE ) {
            return $query_args;
        }
        if ( ! isset( $query_args['orderby'] ) || $query_args['orderby'] === 'date' ) {
            $query_args['orderby']  = 'meta_value_num';
            $query_args['meta_key'] = '_amir_sort_order';
            $query_args['order']    = 'ASC';
        }
        return $query_args;
    }
}
