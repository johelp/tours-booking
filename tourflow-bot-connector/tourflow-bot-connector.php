<?php
/**
 * Plugin Name:  TourFlow Bot Connector
 * Description:  Expone el catálogo, disponibilidad y link de reserva de TourFlow en un formato pensado para que un chatbot/agente externo (Claude, ChatGPT, un bot propio) lo consuma — plugin satélite, no toca el núcleo. Ver GUIA-PLUGINS-SATELITE-TOURFLOW.md § "TourFlow Bot Connector".
 * Version:      0.1.1
 * Author:       TourFlow
 * Text Domain:  tourflow-bot-connector
 * Requires PHP: 8.1
 * Requires WP:  6.5
 * Requires Plugins: amir-booking
 */

namespace TourFlowBotConnector;

defined( 'ABSPATH' ) || exit;

define( 'TFBC_VERSION',    '0.1.1' );
define( 'TFBC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once TFBC_PLUGIN_DIR . 'includes/class-tfbc-settings.php';
require_once TFBC_PLUGIN_DIR . 'includes/class-tfbc-auth.php';
require_once TFBC_PLUGIN_DIR . 'includes/class-tfbc-catalog.php';
require_once TFBC_PLUGIN_DIR . 'includes/class-tfbc-rest.php';

Settings::register_menu();
Rest::register_routes();
