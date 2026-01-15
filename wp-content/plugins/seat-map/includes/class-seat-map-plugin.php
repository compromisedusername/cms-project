<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Seat_Map_Plugin' ) ) {

    final class Seat_Map_Plugin {

        const VERSION         = '0.1.0';
        const FIELD_NAME      = 'rtb-seat-map';
        const OPTION_LAYOUT   = 'seat_map_layout';
        const OPTION_BG_IMAGE = 'seat_map_background_id';
        const NONCE_ACTION    = 'seat-map-tables';

        /**
         * Singleton instance.
         *
         * @var Seat_Map_Plugin|null
         */
        private static $instance = null;

        /**
         * Tables repository.
         *
         * @var Seat_Map_Tables
         */
        private $tables;

        /**
         * Admin settings handler.
         *
         * @var Seat_Map_Admin
         */
        private $admin;

        /**
         * Form integration handler.
         *
         * @var Seat_Map_Form
         */
        private $form;

        /**
         * Ajax endpoint handler.
         *
         * @var Seat_Map_Ajax
         */
        private $ajax;

        /**
         * Retrieve the plugin singleton.
         *
         * @return Seat_Map_Plugin
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Bootstrap dependencies, services and hooks.
         */
        private function __construct() {
            $this->load_dependencies();

            $this->tables = new Seat_Map_Tables( $this );
            $this->tables->load();

            $this->admin = new Seat_Map_Admin( $this, $this->tables );
            $this->form  = new Seat_Map_Form( $this, $this->tables );
            $this->ajax  = new Seat_Map_Ajax( $this, $this->tables );

            $this->register_hooks();
        }

        /**
         * Include the class files that compose the plugin functionality.
         */
        private function load_dependencies() {
            $base = plugin_dir_path( SEAT_MAP_PLUGIN_FILE ) . 'includes/';

            require_once $base . 'class-seat-map-tables.php';
            require_once $base . 'class-seat-map-admin.php';
            require_once $base . 'class-seat-map-form.php';
            require_once $base . 'class-seat-map-ajax.php';
        }

        /**
         * Register WordPress hooks.
         */
        private function register_hooks() {
            add_action( 'init', array( $this, 'register_shortcodes' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

            $this->admin->register_hooks();
            $this->form->register_hooks();
            $this->ajax->register_hooks();
        }

        /**
         * Placeholder for potential shortcode registration.
         */
        public function register_shortcodes() {
            // No-op for now.
        }

        /**
         * Register public styles and scripts.
         */
        public function enqueue_frontend_assets() {
            $url = $this->get_plugin_url();

            wp_register_style(
                'seat-map-frontend',
                $url . 'assets/css/seat-map.css',
                array(),
                self::VERSION
            );

            wp_register_script(
                'seat-map-frontend',
                $url . 'assets/js/seat-map.js',
                array(),
                self::VERSION,
                true
            );
        }

        /**
         * Public getter for the tables repository.
         *
         * @return Seat_Map_Tables
         */
        public function tables() {
            return $this->tables;
        }

        /**
         * Return the absolute URL to the plugin directory.
         *
         * @return string
         */
        public function get_plugin_url() {
            return plugin_dir_url( SEAT_MAP_PLUGIN_FILE );
        }

        /**
         * Return the absolute path to the plugin directory.
         *
         * @return string
         */
        public function get_plugin_path() {
            return plugin_dir_path( SEAT_MAP_PLUGIN_FILE );
        }

        /**
         * URL of the settings page.
         *
         * @return string
         */
        public function get_settings_page_url() {
            return admin_url( 'options-general.php?page=seat-map-selector' );
        }
    }
}
