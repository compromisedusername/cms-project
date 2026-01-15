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

if ( ! class_exists( 'Seat_Map_Plugin' ) ) {

    final class Seat_Map_Plugin {

	const VERSION         = '0.1.0';
	const FIELD_NAME      = 'rtb-seat-map';
	const OPTION_LAYOUT   = 'seat_map_layout';
	const OPTION_BG_IMAGE = 'seat_map_background_id';
	const NONCE_ACTION    = 'seat-map-tables';

	/**
         * Holds the singleton instance.
         *
         * @var Seat_Map_Plugin|null
         */
        private static $instance = null;

        /**
         * In-memory cache of configured tables.
         *
         * @var array<int, array<string, mixed>>
         */
        private $tables = array();

	/**
	 * Cached map of tables indexed by ID for quick lookups.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $indexed_tables = array();

	/**
	 * Whether Restaurant Reservations has table definitions configured.
	 * @var bool
	 */
	private $rr_tables_defined = false;

        /**
         * Retrieve the singleton instance.
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Wire hooks and load the initial table configuration.
         */
        private function __construct() {
		$this->load_tables_config();
		$this->register_hooks();
        }

        /**
         * Register WordPress hooks that bootstrap the plugin.
         */
	private function register_hooks() {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'rtb_booking_form_fields', array( $this, 'add_seat_map_field' ), 20, 3 );
		add_action( 'rtb_booking_form_init', array( $this, 'prepare_form_assets' ), 20, 1 );
		add_action( 'rtb_validate_booking_submission', array( $this, 'validate_table_selection' ) );
		add_filter( 'rtb_insert_booking_data', array( $this, 'inject_json_into_post_content' ), 10, 2 );
		add_filter( 'rtb_insert_booking_metadata', array( $this, 'store_map_meta' ), 10, 2 );
	}

        /**
         * Attempt to load real table data from Restaurant Reservations settings and fall back to defaults.
         */
	private function load_tables_config() {
		$layout = $this->get_layout_override();

		if ( ! empty( $layout ) ) {
			$tables = $this->merge_tables_with_layout( $layout );
		} else {
			$tables = $this->load_tables_from_rr_settings();
		}

		$this->tables = $tables;
		$this->indexed_tables = $this->index_tables( $tables );
	}

        /**
         * Pull table definitions from the Restaurant Reservations option (rtb-settings).
         *
         * @return array
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

            return $formatted;
        }

        /**
         * Fallback table configuration used when no data exists in Restaurant Reservations.
         *
         * @return array
         */
	private function get_default_tables() {
		return array();
	}

	/**
	 * Convert the tables list into an associative array keyed by table ID.
	 *
	 * @param array $tables
	 * @return array
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

	private function normalize_table_entry( $table ) {
		$defaults = array(
			'id'    => '',
			'seats' => 0,
			'x'     => null,
			'y'     => null,
		);

		$table = array_merge( $defaults, $table );
		$table['x'] = is_numeric( $table['x'] ) ? floatval( $table['x'] ) : null;
		$table['y'] = is_numeric( $table['y'] ) ? floatval( $table['y'] ) : null;

		return $table;
	}

	/**
	 * Return a simple map of table_id => seats for storage purposes.
	 *
	 * @return array
	 */
	private function flatten_tables_for_storage() {
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
         * Placeholder for shortcode/block registration.
         */
        public function register_shortcodes() {
            // No additional shortcodes are required for this feature set.
        }

        /**
         * Enqueue frontend assets.
         */
        public function enqueue_frontend_assets() {
            $url = plugin_dir_url( __FILE__ );

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
         * Enqueue admin assets.
         *
         * @param string $hook Current admin page hook suffix.
         */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_seat-map-selector' !== $hook ) {
			return;
		}

		wp_enqueue_media();

		$url = plugin_dir_url( __FILE__ );

		wp_enqueue_style(
			'seat-map-admin',
			$url . 'assets/css/seat-map-admin.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'seat-map-admin',
			$url . 'assets/js/seat-map-admin.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'seat-map-admin',
			'SeatMapAdminData',
			array(
				'addRow'        => __( 'Dodaj stolik', 'seat-map-selector' ),
				'removeRow'     => __( 'Usuń', 'seat-map-selector' ),
				'confirmRemove' => __( 'Usunąć ten stolik?', 'seat-map-selector' ),
				'mediaTitle'    => __( 'Wybierz obraz tła', 'seat-map-selector' ),
				'mediaButton'   => __( 'Użyj obrazu', 'seat-map-selector' ),
			)
		);
	}

	public function register_settings_page() {
		add_options_page(
			__( 'Mapa stolików', 'seat-map-selector' ),
			__( 'Mapa stolików', 'seat-map-selector' ),
			'manage_options',
			'seat-map-selector',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'seat-map-selector', self::OPTION_LAYOUT, array( $this, 'sanitize_layout_option' ) );
		register_setting( 'seat-map-selector', self::OPTION_BG_IMAGE, array( $this, 'sanitize_background_option' ) );

		add_settings_section(
			'seat-map-layout-section',
			__( 'Konfiguracja mapy stolików', 'seat-map-selector' ),
			array( $this, 'render_settings_section_intro' ),
			'seat-map-selector'
		);

		add_settings_field(
			'seat-map-layout-field',
			__( 'Współrzędne JSON', 'seat-map-selector' ),
			array( $this, 'render_layout_field' ),
			'seat-map-selector',
			'seat-map-layout-section'
		);

		add_settings_field(
			'seat-map-background-field',
			__( 'Tło mapy', 'seat-map-selector' ),
			array( $this, 'render_background_field' ),
			'seat-map-selector',
			'seat-map-layout-section'
		);
	}

	public function render_settings_section_intro() {
		echo '<p>' . esc_html__( 'Podaj listę obiektów JSON (id, seats, x, y) gdzie x i y to procentowe położenie na mapie.', 'seat-map-selector' ) . '</p>';
	}

	public function render_layout_field() {
		$value = get_option( self::OPTION_LAYOUT, '' );
		if ( ! is_string( $value ) ) {
			$value = wp_json_encode( $value );
		}
		$base_tables = array_map( array( $this, 'normalize_table_entry' ), $this->tables );
		?>
		<?php if ( empty( $base_tables ) ) : ?>
			<p class="notice notice-warning"><?php esc_html_e( 'Brak stolików w głównej wtyczce rezerwacji. Możesz wprowadzić je tutaj ręcznie.', 'seat-map-selector' ); ?></p>
		<?php endif; ?>
		<div class="seat-map-layout-builder" data-seat-map-builder data-value='<?php echo esc_attr( $value ); ?>'>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION_LAYOUT ); ?>" value="<?php echo esc_attr( $value ); ?>" data-seat-map-value>
			<table class="widefat striped">
				<thead>
				<tr>
					<th><?php esc_html_e( 'Nazwa stolika', 'seat-map-selector' ); ?></th>
					<th><?php esc_html_e( 'Miejsca', 'seat-map-selector' ); ?></th>
					<th><?php esc_html_e( 'X (0-100)', 'seat-map-selector' ); ?></th>
					<th><?php esc_html_e( 'Y (0-100)', 'seat-map-selector' ); ?></th>
					<th></th>
				</tr>
				</thead>
				<tbody></tbody>
			</table>
			<p>
				<button type="button" class="button" data-seat-map-add><?php esc_html_e( 'Dodaj stolik', 'seat-map-selector' ); ?></button>
			</p>
				<p class="description"><?php esc_html_e( 'Skala mapy to 100 x 100. Podaj współrzędne w procentach (0-100).', 'seat-map-selector' ); ?></p>
			<div class="seat-map-admin-preview" data-seat-map-admin-preview></div>
		</div>
		<?php
	}

	public function render_background_field() {
		$attachment_id = get_option( self::OPTION_BG_IMAGE, 0 );
		$url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'large' ) : '';
		?>
		<div class="seat-map-background-field" data-seat-map-background>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION_BG_IMAGE ); ?>" value="<?php echo esc_attr( $attachment_id ); ?>" data-seat-map-bg-input>
			<div class="seat-map-background-preview" data-seat-map-bg-preview>
				<?php if ( $url ) : ?>
					<img src="<?php echo esc_url( $url ); ?>" alt="" />
				<?php endif; ?>
			</div>
			<p>
				<button type="button" class="button" data-seat-map-bg-select><?php esc_html_e( 'Wybierz obraz', 'seat-map-selector' ); ?></button>
				<button type="button" class="button-link" data-seat-map-bg-clear><?php esc_html_e( 'Usuń', 'seat-map-selector' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Obraz będzie użyty jako tło mapy w formularzu.', 'seat-map-selector' ); ?></p>
		</div>
		<?php
	}

	public function sanitize_layout_option( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( wp_unslash( $value ), true );
		} else {
			$decoded = $value;
		}

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
			add_settings_error( self::OPTION_LAYOUT, 'seat-map-json', __( 'Nieprawidłowy JSON. Sprawdź składnię.', 'seat-map-selector' ) );
			return $value;
		}

		return wp_json_encode( $decoded );
	}

	public function sanitize_background_option( $value ) {
		return absint( $value );
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mapa stolików', 'seat-map-selector' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'seat-map-selector' );
				do_settings_sections( 'seat-map-selector' );
				submit_button();
				?>
			</form>
		</div>
		<?php
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
		$has_tables = $this->has_tables();
		?>
		<div class="rtb-field seat-map-field">
			<label class="seat-map-label" for="seat-map-display">
				<?php echo esc_html__( 'Wybierz stolik', 'seat-map-selector' ); ?>
			</label>

			<?php if ( ! $has_tables ) : ?>
				<p class="seat-map-placeholder">
					<?php printf(
						esc_html__( 'Nie zdefiniowano żadnych stolików. Skonfiguruj je w %s.', 'seat-map-selector' ),
						sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $this->get_settings_page_url() ), esc_html__( 'ustawieniach mapy', 'seat-map-selector' ) )
					);
				?>
				</p>
			<?php else : ?>
				<div id="seat-map-display" class="seat-map-grid" data-seat-map>
					<?php foreach ( $this->tables as $table ) : ?>
						<button type="button" class="seat-map-button is-available" disabled>
							<span class="seat-map-button-id"><?php echo esc_html( $table['id'] ); ?></span>
							<span class="seat-map-button-capacity"><?php printf( esc_html__( '%s miejsc', 'seat-map-selector' ), esc_html( $table['seats'] ) ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<?php $has_coords = $this->has_table_coordinates(); ?>
				<?php $background_url = $this->get_background_image_url(); ?>
				<?php if ( $has_coords ) : ?>
					<div class="seat-map-floor" data-seat-map-floor<?php echo $background_url ? ' style="background-image: url(' . esc_url( $background_url ) . ');"' : ''; ?>>
						<?php foreach ( $this->tables as $table ) :
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
							sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $this->get_settings_page_url() ), esc_html__( 'ustawieniach mapy', 'seat-map-selector' ) )
						);
					?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
			<input type="hidden" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="<?php echo esc_attr( $value ); ?>" data-seat-map-input>
			<input type="hidden" name="rtb-table" value="<?php echo esc_attr( $value ); ?>" data-seat-map-rtb-table>
			<div class="seat-map-feedback" data-seat-map-feedback></div>
			<?php echo rtb_print_form_error( $slug ); ?>
		</div>
		<?php
	}

	/**
	 * Load assets and pass localized data once the form is being rendered.
	 *
	 * @param array $args
	 */
	public function prepare_form_assets( $args ) {
		wp_enqueue_style( 'seat-map-frontend' );
		wp_enqueue_script( 'seat-map-frontend' );

		global $rtb_controller;
		$request = isset( $rtb_controller->request ) ? $rtb_controller->request : null;

		$data = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
			'tables'        => $this->tables,
			'fieldName'     => self::FIELD_NAME,
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
	 * Helper getter for other components.
	 *
	 * @return array
	 */
	public function get_tables() {
		return $this->tables;
	}

	private function has_tables() {
		return ! empty( $this->tables );
	}

	private function get_layout_override() {
		$raw = get_option( self::OPTION_LAYOUT, '' );

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

	private function merge_tables_with_layout( $layout ) {
		$indexed = array();

		foreach ( $layout as $entry ) {
			if ( is_object( $entry ) ) {
				$entry = (array) $entry;
			}

			if ( empty( $entry['id'] ) ) {
				continue;
			}

			$table = $this->normalize_table_entry( $entry );
			$indexed[ $table['id'] ] = $table;
		}

		return array_values( $indexed );
	}

	private function has_table_coordinates() {
		foreach ( $this->tables as $table ) {
			if ( isset( $table['x'], $table['y'] ) && $table['x'] !== null && $table['y'] !== null ) {
				return true;
			}
		}

		return false;
	}

	private function get_settings_page_url() {
		return admin_url( 'options-general.php?page=seat-map-selector' );
	}

	private function get_background_image_url() {
		$attachment_id = get_option( self::OPTION_BG_IMAGE, 0 );

		if ( ! $attachment_id ) {
			return '';
		}

		$image = wp_get_attachment_image_url( $attachment_id, 'large' );

		return $image ? $image : '';
	}

	/**
	 * Validate the selected table against availability and capacity.
	 *
	 * @param rtbBooking $booking
	 */
	public function validate_table_selection( $booking ) {
		if ( ! $this->has_tables() ) {
			return;
		}
		$selected_table = $this->get_current_selection_from_request();

		if ( empty( $selected_table ) ) {
			$this->push_booking_error( $booking, __( 'Wybierz stolik przed wysłaniem rezerwacji.', 'seat-map-selector' ) );
			return;
		}

		if ( ! isset( $this->indexed_tables[ $selected_table ] ) ) {
			$this->push_booking_error( $booking, __( 'Wybrano nieznany stolik. Odśwież stronę i spróbuj ponownie.', 'seat-map-selector' ) );
			return;
		}

		$table_details = $this->indexed_tables[ $selected_table ];
		$party_size    = empty( $booking->party ) ? 0 : (int) $booking->party;

		if ( $party_size > 0 && $table_details['seats'] < $party_size ) {
			$this->push_booking_error( $booking, __( 'Ten stolik nie pomieści tylu osób.', 'seat-map-selector' ) );
			return;
		}

		if ( $this->rr_tables_defined && function_exists( 'rtb_get_valid_tables' ) ) {
			$location_id     = empty( $booking->location ) ? 0 : (int) $booking->location;
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
			'tables'   => $this->flatten_tables_for_storage(),
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
			'tables'   => $this->flatten_tables_for_storage(),
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
		if ( empty( $_POST[ self::FIELD_NAME ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) );
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
	 * @return array
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

	public function ajax_tables_status() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
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

		if ( empty( $this->indexed_tables ) ) {
			wp_send_json_success( array( 'tables' => array() ) );
		}

		$availability = array();
		foreach ( $this->indexed_tables as $id => $table ) {
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
			'fields'        => 'ids',
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
}

add_action( 'plugins_loaded', array( 'Seat_Map_Plugin', 'instance' ) );
