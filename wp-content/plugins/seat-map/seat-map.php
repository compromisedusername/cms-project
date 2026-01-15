<?php
/**
 * Plugin Name: Seat Map Selector
 * Description: Interactive seat map for Restaurant Reservations.
 * Version: 0.1.0
 * Author: s28076
 * Text Domain: seat-map-selector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'SEAT_MAP_PLUGIN_FILE' ) ) {
    define( 'SEAT_MAP_PLUGIN_FILE', __FILE__ );
}

require_once plugin_dir_path( SEAT_MAP_PLUGIN_FILE ) . 'includes/class-seat-map-plugin.php';

add_action( 'plugins_loaded', array( 'Seat_Map_Plugin', 'instance' ) );
