<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seat_Map_Ajax {

    /**
     * @var Seat_Map_Plugin
     */
    private $plugin;

    /**
     * @var Seat_Map_Tables
     */
    private $tables;

    /**
     * @param Seat_Map_Plugin $plugin Plugin instance.
     * @param Seat_Map_Tables $tables Tables repository.
     */
    public function __construct( Seat_Map_Plugin $plugin, Seat_Map_Tables $tables ) {
        $this->plugin = $plugin;
        $this->tables = $tables;
    }

    /**
     * Register AJAX actions for checking table availability.
     */
    public function register_hooks() {
        add_action( 'wp_ajax_seat_map_tables_status', array( $this, 'ajax_tables_status' ) );
        add_action( 'wp_ajax_nopriv_seat_map_tables_status', array( $this, 'ajax_tables_status' ) );
    }

    /**
     * AJAX callback returning current table availability for the requested datetime.
     */
    public function ajax_tables_status() {
        if ( ! check_ajax_referer( Seat_Map_Plugin::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Sesja wygasła. Odśwież stronę.', 'seat-map-selector' ) ), 403 );
        }

        $year     = isset( $_POST['year'] ) ? sanitize_text_field( $_POST['year'] ) : '';
        $month    = isset( $_POST['month'] ) ? sanitize_text_field( $_POST['month'] ) : '';
        $day      = isset( $_POST['day'] ) ? sanitize_text_field( $_POST['day'] ) : '';
        $time     = isset( $_POST['time'] ) ? sanitize_text_field( $_POST['time'] ) : '';
        $party    = isset( $_POST['party'] ) ? absint( $_POST['party'] ) : 0;
        $location = isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0;

        if ( empty( $year ) || empty( $month ) || empty( $day ) || empty( $time ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak wymaganych danych.', 'seat-map-selector' ) ) );
        }

        $datetime = sprintf( '%04d-%02d-%02d %s', $year, $month, $day, $time );

        $indexed = $this->tables->get_indexed_tables();
        if ( empty( $indexed ) ) {
            wp_send_json_success( array( 'tables' => array() ) );
        }

        $availability = array();
        foreach ( $indexed as $id => $table ) {
            $seats = isset( $table['seats'] ) ? (int) $table['seats'] : 0;
            $availability[ $id ] = array(
                'id'              => $id,
                'seats'           => $seats,
                'available_seats' => $seats,
                'status'          => 'available',
            );
        }

        $bookings = $this->get_bookings_for_datetime( $datetime, $location );

        foreach ( $bookings as $booking ) {
            $table_ids = ( ! empty( $booking->table ) && is_array( $booking->table ) ) ? $booking->table : $this->get_selected_tables_from_content( $booking->ID );
            if ( empty( $table_ids ) ) {
                continue;
            }
            foreach ( $table_ids as $table_id ) {
                $table_id = (string) $table_id;
                if ( ! isset( $availability[ $table_id ] ) ) {
                    continue;
                }
                $availability[ $table_id ]['available_seats'] = 0;
            }
        }

        foreach ( $availability as &$table_data ) {
            if ( $table_data['available_seats'] < 0 ) {
                $table_data['available_seats'] = 0;
            }
            if ( $party > 0 && $table_data['available_seats'] > 0 && $table_data['available_seats'] < $party ) {
                $table_data['status'] = 'capacity';
            } elseif ( $table_data['available_seats'] <= 0 ) {
                $table_data['status'] = 'booked';
            } else {
                $table_data['status'] = 'available';
            }
        }
        unset( $table_data );

        wp_send_json_success( array( 'tables' => array_values( $availability ) ) );
    }

    /**
     * Fetch bookings that overlap the requested datetime and location.
     *
     * @param string $datetime Requested datetime string.
     * @param int    $location_id Location term ID.
     * @return array<int, rtbBooking>
     */
    private function get_bookings_for_datetime( $datetime, $location_id ) {
        global $rtb_controller;

        $tmzn          = wp_timezone();
        $request_time  = new DateTime( $datetime, $tmzn );
        $location_slug = $location_id ? get_term_field( 'slug', $location_id ) : false;
        $timeslot      = function_exists( 'rtb_get_timeslot' ) ? rtb_get_timeslot( $datetime, $location_id ) : false;
        $dining_block  = (int) $rtb_controller->settings->get_setting( 'rtb-dining-block-length', $location_slug, $timeslot );
        if ( empty( $dining_block ) ) {
            $dining_block = 90;
        }
        $block_seconds = $dining_block * 60 - 1;
        $request_unix  = (int) $request_time->format( 'U' );
        $request_start = $request_unix - $block_seconds;
        $request_end   = $request_unix + $block_seconds;

        $args = array(
            'post_type'      => RTB_BOOKING_POST_TYPE,
            'post_status'    => array( 'pending', 'confirmed', 'paid', 'closed' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'date_query'     => array(
                array(
                    'column'    => 'post_date',
                    'after'     => date( 'Y-m-d H:i:s', $request_start ),
                    'before'    => date( 'Y-m-d H:i:s', $request_end ),
                    'inclusive' => true,
                ),
            ),
        );

        $query = new WP_Query( $args );
        if ( empty( $query->posts ) ) {
            return array();
        }

        require_once RTB_PLUGIN_DIR . '/includes/Booking.class.php';

        $bookings = array();
        foreach ( $query->posts as $booking_id ) {
            $booking = new rtbBooking();
            if ( ! $booking->load_post( $booking_id ) ) {
                continue;
            }
            if ( $booking->post_status === 'cancelled' ) {
                continue;
            }
            $bookings[] = $booking;
        }

        return $bookings;
    }

    /**
     * Extract selected table IDs from booking post content.
     *
     * @param int $booking_id Booking post ID.
     * @return array<int, string>
     */
    private function get_selected_tables_from_content( $booking_id ) {
        $content = get_post_field( 'post_content', $booking_id );
        if ( empty( $content ) ) {
            return array();
        }
        $decoded = json_decode( $content, true );
        if ( empty( $decoded['selected'] ) ) {
            return array();
        }
        return is_array( $decoded['selected'] ) ? $decoded['selected'] : array( $decoded['selected'] );
    }
}
