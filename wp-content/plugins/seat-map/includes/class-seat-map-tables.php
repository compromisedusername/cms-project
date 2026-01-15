<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seat_Map_Tables {

    /**
     * Plugin instance for accessing config.
     *
     * @var Seat_Map_Plugin
     */
    private $plugin;

    /**
     * Tables list as configured for the seat map.
     *
     * @var array<int, array<string, mixed>>
     */
    private $tables = array();

    /**
     * Indexed tables keyed by identifier.
     *
     * @var array<string, array<string, mixed>>
     */
    private $indexed_tables = array();

    /**
     * Whether Restaurant Reservations has table definitions.
     *
     * @var bool
     */
    private $rr_tables_defined = false;

    /**
     * @param Seat_Map_Plugin $plugin Plugin bootstrapper.
     */
    public function __construct( Seat_Map_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Load tables from overrides or Restaurant Reservations settings.
     */
    public function load() {
        $this->rr_tables_defined = false;

        $layout    = $this->get_layout_override();
        $rr_tables = $this->load_tables_from_rr_settings();

        if ( ! empty( $layout ) ) {
            $tables = $this->merge_tables_with_layout( $layout );
        } elseif ( ! empty( $rr_tables ) ) {
            $tables = $rr_tables;
        } else {
            $tables = $this->get_default_tables();
        }

        $this->tables         = $tables;
        $this->indexed_tables = $this->index_tables( $tables );
    }

    /**
     * Get the working list of tables.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_tables() {
        return $this->tables;
    }

    /**
     * Return tables flattened to id => seats map.
     *
     * @return array<string, int>
     */
    public function flatten_for_storage() {
        $map = array();

        foreach ( $this->tables as $table ) {
            if ( empty( $table['id'] ) ) {
                continue;
            }

            $map[ $table['id'] ] = (int) $table['seats'];
        }

        return $map;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_indexed_tables() {
        return $this->indexed_tables;
    }

    /**
     * Whether any table definitions are available.
     *
     * @return bool
     */
    public function has_tables() {
        return ! empty( $this->tables );
    }

    /**
     * Whether any table has coordinates defined.
     *
     * @return bool
     */
    public function has_table_coordinates() {
        foreach ( $this->tables as $table ) {
            if ( isset( $table['x'], $table['y'] ) && $table['x'] !== null && $table['y'] !== null ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Restaurant Reservations has stock tables configured.
     *
     * @return bool
     */
    public function has_rr_tables_defined() {
        return $this->rr_tables_defined;
    }

    /**
     * Current background image attachment ID.
     *
     * @return int
     */
    public function get_background_image_id() {
        return (int) get_option( Seat_Map_Plugin::OPTION_BG_IMAGE, 0 );
    }

    /**
     * Background image URL for display purposes.
     *
     * @return string
     */
    public function get_background_image_url() {
        $attachment_id = $this->get_background_image_id();

        if ( ! $attachment_id ) {
            return '';
        }

        $image = wp_get_attachment_image_url( $attachment_id, 'large' );

        return $image ? $image : '';
    }

    /**
     * Normalize a single table entry.
     *
     * @param array<string, mixed> $table Table definition.
     * @return array<string, mixed>
     */
    public function normalize_table_entry( $table ) {
        $defaults = array(
            'id'    => '',
            'seats' => 0,
            'x'     => null,
            'y'     => null,
        );

        $table      = array_merge( $defaults, $table );
        $table['x'] = is_numeric( $table['x'] ) ? floatval( $table['x'] ) : null;
        $table['y'] = is_numeric( $table['y'] ) ? floatval( $table['y'] ) : null;

        return $table;
    }

    /**
     * Read layout override from plugin options.
     *
     * @return array<int, mixed>
     */
    private function get_layout_override() {
        $raw = get_option( Seat_Map_Plugin::OPTION_LAYOUT, '' );

        if ( empty( $raw ) ) {
            return array();
        }

        if ( is_string( $raw ) ) {
            $decoded = json_decode( wp_unslash( $raw ), true );
        } else {
            $decoded = $raw;
        }

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Merge manual layout overrides by table id.
     *
     * @param array<int, mixed> $layout Layout entries.
     * @return array<int, array<string, mixed>>
     */
    private function merge_tables_with_layout( $layout ) {
        $indexed = array();

        foreach ( $layout as $entry ) {
            if ( is_object( $entry ) ) {
                $entry = (array) $entry;
            }

            if ( empty( $entry['id'] ) ) {
                continue;
            }

            $table                 = $this->normalize_table_entry( $entry );
            $indexed[ $table['id'] ] = $table;
        }

        return array_values( $indexed );
    }

    /**
     * Pull table definitions from Restaurant Reservations.
     *
     * @return array<int, array<string, mixed>>
     */
    private function load_tables_from_rr_settings() {
        $settings = get_option( 'rtb-settings' );

        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return array();
        }

        $raw_tables = isset( $settings['rtb-tables'] ) ? $settings['rtb-tables'] : '';

        if ( empty( $raw_tables ) ) {
            return array();
        }

        if ( is_string( $raw_tables ) ) {
            $raw_tables = html_entity_decode( $raw_tables );
            $raw_tables = json_decode( $raw_tables, true );
        }

        if ( ! is_array( $raw_tables ) ) {
            return array();
        }

        $formatted = array();

        foreach ( $raw_tables as $table ) {
            $number = '';
            $seats  = 0;

            if ( is_array( $table ) ) {
                $number = isset( $table['number'] ) ? (string) $table['number'] : '';
                $seats  = isset( $table['max_people'] ) ? (int) $table['max_people'] : 0;
            } elseif ( is_object( $table ) ) {
                $number = isset( $table->number ) ? (string) $table->number : '';
                $seats  = isset( $table->max_people ) ? (int) $table->max_people : 0;
            }

            if ( '' === $number || 0 === $seats ) {
                continue;
            }

            $formatted[] = array(
                'id'    => $number,
                'seats' => $seats,
            );
        }

        $this->rr_tables_defined = ! empty( $formatted );

        return $formatted;
    }

    /**
     * Fallback table configuration when none are defined.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_default_tables() {
        return array();
    }

    /**
     * Create a map of id => table entry.
     *
     * @param array<int, array<string, mixed>> $tables Tables list.
     * @return array<string, array<string, mixed>>
     */
    private function index_tables( $tables ) {
        $indexed = array();

        foreach ( $tables as $table ) {
            if ( empty( $table['id'] ) ) {
                continue;
            }

            $indexed[ $table['id'] ] = $this->normalize_table_entry( $table );
        }

        return $indexed;
    }
}
