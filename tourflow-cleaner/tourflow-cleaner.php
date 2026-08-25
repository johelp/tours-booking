<?php
/**
 * Plugin Name:  TourFlow Cleaner
 * Plugin URI:   https://amiradventours.com
 * Description:  Herramienta INTERNA para borrar datos de prueba de TourFlow (reservas, tours, cupones, etc.) antes de entregar/lanzar un sitio — plugin satélite, no toca el núcleo. No pensado para operadores/clientes finales. Ver GUIA-PLUGINS-SATELITE-TOURFLOW.md.
 * Version:      0.1.1
 * Author:       TourFlow
 * Text Domain:  tourflow-cleaner
 * Requires PHP: 8.1
 * Requires WP:  6.5
 * Requires Plugins: amir-booking
 */

namespace TourFlowCleaner;

defined( 'ABSPATH' ) || exit;

define( 'TFC_VERSION',    '0.1.1' );
define( 'TFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once TFC_PLUGIN_DIR . 'includes/class-tfc-cleaner.php';
require_once TFC_PLUGIN_DIR . 'includes/class-tfc-page.php';

Page::register_menu();
