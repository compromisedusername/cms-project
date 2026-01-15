<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seat_Map_Form {

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
     * Register integration hooks with Restaurant Reservations.
     */
    public function register_hooks() {
        add_filter( 'rtb_booking_form_fields', array( $this, 'add_seat_map_field' ), 20, 3 );
        add_action( 'rtb_booking_form_init', array( $this, 'prepare_form_assets' ), 20, 1 );
        add_action( 'rtb_validate_booking_submission', array( $this, 'validate_table_selection' ) );
        add_filter( 'rtb_insert_booking_data', array( $this, 'inject_json_into_post_content' ), 10, 2 );
        add_filter( 'rtb_insert_booking_metadata', array( $this, 'store_map_meta' ), 10, 2 );
    }

    /**
     * Inject the seat map field into the reservation fieldset.
     *
     * @param array      $fieldsets
     * @param rtbBooking $request
     * @return array
     */
    public function add_seat_map_field( $fieldsets, $request, $args = array() ) {
        if ( empty( $fieldsets['reservation']['fields'] ) ) {
            return $fieldsets;
        }

        if ( isset( $fieldsets['reservation']['fields']['table'] ) ) {
            unset( $fieldsets['reservation']['fields']['table'] );
        }

        $selected = $this->get_selection_from_request_context( $request );

        $fieldsets['reservation']['fields']['seat-map'] = array(
            'title'         => __( 'Wybór stolika', 'seat-map-selector' ),
            'callback'      => array( $this, 'render_seat_map_field' ),
            'request_input' => $selected,
            'order'         => 998,
            'required'      => false,
        );

        return $fieldsets;
    }

    /**
     * Render the seat map field markup.
     */
    public function render_seat_map_field( $slug, $title, $value, $callback_args = array() ) {
        $tables     = $this->tables->get_tables();
        $has_tables = ! empty( $tables );
        ?>
        <div class="rtb-field seat-map-field">
            <label class="seat-map-label" for="seat-map-display">
                <?php echo esc_html__( 'Wybierz stolik', 'seat-map-selector' ); ?>
            </label>

            <?php if ( ! $has_tables ) : ?>
                <p class="seat-map-placeholder">
                    <?php printf(
                        esc_html__( 'Nie zdefiniowano żadnych stolików. Skonfiguruj je w %s.', 'seat-map-selector' ),
                        sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $this->plugin->get_settings_page_url() ), esc_html__( 'ustawieniach mapy', 'seat-map-selector' ) )
                    );
                    ?>
                </p>
            <?php else : ?>
                <div id="seat-map-display" class="seat-map-grid" data-seat-map>
                    <?php foreach ( $tables as $table ) : ?>
                        <button type="button" class="seat-map-button is-available" disabled>
                            <span class="seat-map-button-id"><?php echo esc_html( $table['id'] ); ?></span>
                            <span class="seat-map-button-capacity"><?php printf( esc_html__( '%s miejsc', 'seat-map-selector' ), esc_html( $table['seats'] ) ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php $has_coords = $this->tables->has_table_coordinates(); ?>
                <?php $background_url = $this->tables->get_background_image_url(); ?>
                <?php if ( $has_coords ) : ?>
                    <div class="seat-map-floor" data-seat-map-floor<?php echo $background_url ? ' style="background-image: url(' . esc_url( $background_url ) . ');"' : ''; ?>>
                        <?php foreach ( $tables as $table ) :
                            if ( $table['x'] === null || $table['y'] === null ) {
                                continue;
                            }
                            $left = max( 0, min( 100, $table['x'] ) );
                            $top  = max( 0, min( 100, $table['y'] ) );
                            ?>
                            <span class="seat-map-marker is-available" data-seat-map-marker data-table-id="<?php echo esc_attr( $table['id'] ); ?>" style="left: <?php echo esc_attr( $left ); ?>%; top: <?php echo esc_attr( $top ); ?>%;">
                                <span><?php echo esc_html( $table['id'] ); ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="seat-map-floor-placeholder">
                        <?php printf(
                            esc_html__( 'Dodaj współrzędne stolików w %s, aby wyświetlić mapę.', 'seat-map-selector' ),
                            sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $this->plugin->get_settings_page_url() ), esc_html__( 'ustawieniach mapy', 'seat-map-selector' ) )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
            <input type="hidden" name="<?php echo esc_attr( Seat_Map_Plugin::FIELD_NAME ); ?>" value="<?php echo esc_attr( $value ); ?>" data-seat-map-input>
            <input type="hidden" name="rtb-table" value="<?php echo esc_attr( $value ); ?>" data-seat-map-rtb-table>
            <div class="seat-map-feedback" data-seat-map-feedback></div>
            <?php echo rtb_print_form_error( $slug ); ?>
        </div>
        <?php
    }

    /**
     * Load assets and localized data for the booking form.
     *
     * @param array $args Form args.
     */
    public function prepare_form_assets( $args ) {
        wp_enqueue_style( 'seat-map-frontend' );
        wp_enqueue_script( 'seat-map-frontend' );

        global $rtb_controller;
        $request = isset( $rtb_controller->request ) ? $rtb_controller->request : null;

        $data = array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( Seat_Map_Plugin::NONCE_ACTION ),
            'tables'        => $this->tables->get_tables(),
            'fieldName'     => Seat_Map_Plugin::FIELD_NAME,
            'selectedTable' => $this->get_selection_from_request_context( $request ),
            'strings'       => array(
                'unavailable' => __( 'Ten stolik jest już zajęty.', 'seat-map-selector' ),
                'tooSmall'    => __( 'Zbyt mało miejsc dla tej rezerwacji.', 'seat-map-selector' ),
                'pickTable'   => __( 'Wybierz stolik, aby wysłać prośbę.', 'seat-map-selector' ),
                'loading'     => __( 'Sprawdzam dostępność…', 'seat-map-selector' ),
                'seatsLabel'  => __( '%s miejsc', 'seat-map-selector' ),
            ),
        );

        wp_localize_script( 'seat-map-frontend', 'SeatMapData', $data );
    }

    /**
     * Validate the selected table against availability and capacity.
     *
     * @param rtbBooking $booking
     */
    public function validate_table_selection( $booking ) {
        if ( ! $this->tables->has_tables() ) {
            return;
        }

        $selected_table = $this->get_current_selection_from_request();

        if ( empty( $selected_table ) ) {
            $this->push_booking_error( $booking, __( 'Wybierz stolik przed wysłaniem rezerwacji.', 'seat-map-selector' ) );
            return;
        }

        $indexed = $this->tables->get_indexed_tables();

        if ( ! isset( $indexed[ $selected_table ] ) ) {
            $this->push_booking_error( $booking, __( 'Wybrano nieznany stolik. Odśwież stronę i spróbuj ponownie.', 'seat-map-selector' ) );
            return;
        }

        $table_details = $indexed[ $selected_table ];
        $party_size    = empty( $booking->party ) ? 0 : (int) $booking->party;

        if ( $party_size > 0 && $table_details['seats'] < $party_size ) {
            $this->push_booking_error( $booking, __( 'Ten stolik nie pomieści tylu osób.', 'seat-map-selector' ) );
            return;
        }

        if ( $this->tables->has_rr_tables_defined() && function_exists( 'rtb_get_valid_tables' ) ) {
            $location_id      = empty( $booking->location ) ? 0 : (int) $booking->location;
            $available_tables = rtb_get_valid_tables( $booking->date, $location_id );

            if ( ! empty( $available_tables ) && ! in_array( $selected_table, $available_tables, true ) ) {
                $this->push_booking_error( $booking, __( 'Ten stolik został właśnie zajęty. Wybierz inny.', 'seat-map-selector' ) );
                return;
            }
        }

        $this->remove_table_errors( $booking );
        $booking->table = array( $selected_table );
    }

    /**
     * Replace post_content with a JSON snapshot of the tables map and current selection.
     */
    public function inject_json_into_post_content( $args, $booking ) {
        $payload = array(
            'tables'   => $this->tables->flatten_for_storage(),
            'selected' => $this->get_booking_selection( $booking ),
            'message'  => $booking->message,
        );

        $args['post_content'] = wp_json_encode( $payload );

        return $args;
    }

    /**
     * Persist extra metadata for easier access inside the admin area.
     */
    public function store_map_meta( $meta, $booking ) {
        $meta['seat_map'] = array(
            'tables'   => $this->tables->flatten_for_storage(),
            'selected' => $this->get_booking_selection( $booking ),
            'message'  => $booking->message,
        );

        return $meta;
    }

    /**
     * Sanitize the current seat selection from POST data or request.
     *
     * @return string
     */
    private function get_current_selection_from_request() {
        if ( empty( $_POST[ Seat_Map_Plugin::FIELD_NAME ] ) ) {
            return '';
        }

        return sanitize_text_field( wp_unslash( $_POST[ Seat_Map_Plugin::FIELD_NAME ] ) );
    }

    /**
     * Determine selection either from POST data or the current booking request object.
     *
     * @param rtbBooking|null $request
     * @return string
     */
    private function get_selection_from_request_context( $request = null ) {
        $selected = $this->get_current_selection_from_request();

        if ( ! empty( $selected ) ) {
            return $selected;
        }

        if ( $request && ! empty( $request->table ) && is_array( $request->table ) ) {
            $first = reset( $request->table );

            return $first ? (string) $first : '';
        }

        return '';
    }

    /**
     * Build the array of selected tables stored alongside the booking.
     *
     * @param rtbBooking $booking
     * @return array<int, string>
     */
    private function get_booking_selection( $booking ) {
        if ( ! empty( $booking->table ) && is_array( $booking->table ) ) {
            return array_values( array_unique( $booking->table ) );
        }

        $selected = $this->get_current_selection_from_request();

        return empty( $selected ) ? array() : array( $selected );
    }

    /**
     * Append a validation error to the booking object.
     */
    private function push_booking_error( $booking, $message ) {
        $booking->validation_errors[] = array(
            'field'     => 'seat-map',
            'error_msg' => 'seat-map',
            'message'   => $message,
        );
    }

    /**
     * Remove any default validation errors related to the core "table" field.
     *
     * @param rtbBooking $booking
     */
    private function remove_table_errors( $booking ) {
        if ( empty( $booking->validation_errors ) ) {
            return;
        }

        $booking->validation_errors = array_values(
            array_filter(
                $booking->validation_errors,
                function ( $error ) {
                    return isset( $error['field'] ) && $error['field'] !== 'table';
                }
            )
        );
    }
}
