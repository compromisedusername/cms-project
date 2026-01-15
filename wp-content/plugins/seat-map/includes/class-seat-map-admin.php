<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seat_Map_Admin {

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
     * Register WordPress hooks for the admin UI.
     */
    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Register the Seat Map settings page.
     */
    public function register_settings_page() {
        add_options_page(
            __( 'Mapa stolików', 'seat-map-selector' ),
            __( 'Mapa stolików', 'seat-map-selector' ),
            'manage_options',
            'seat-map-selector',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings and fields for the settings page.
     */
    public function register_settings() {
        register_setting( 'seat-map-selector', Seat_Map_Plugin::OPTION_LAYOUT, array( $this, 'sanitize_layout_option' ) );
        register_setting( 'seat-map-selector', Seat_Map_Plugin::OPTION_BG_IMAGE, array( $this, 'sanitize_background_option' ) );

        add_settings_section(
            'seat-map-layout-section',
            __( 'Konfiguracja mapy stolików', 'seat-map-selector' ),
            array( $this, 'render_settings_section_intro' ),
            'seat-map-selector'
        );

        add_settings_field(
            'seat-map-layout-field',
            __( 'Współrzędne', 'seat-map-selector' ),
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

    /**
     * Enqueue assets for the settings UI.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( 'settings_page_seat-map-selector' !== $hook ) {
            return;
        }

        wp_enqueue_media();

        $url = $this->plugin->get_plugin_url();

        wp_enqueue_style(
            'seat-map-admin',
            $url . 'assets/css/seat-map-admin.css',
            array(),
            Seat_Map_Plugin::VERSION
        );

        wp_enqueue_script(
            'seat-map-admin',
            $url . 'assets/js/seat-map-admin.js',
            array( 'jquery' ),
            Seat_Map_Plugin::VERSION,
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

    /**
     * Settings section help text.
     */
    public function render_settings_section_intro() {
        echo '<p>' . esc_html__( 'Wpisz współrzędne.', 'seat-map-selector' ) . '</p>';
    }

    /**
     * Render textarea used for JSON layout definition.
     */
    public function render_layout_field() {
        $value = get_option( Seat_Map_Plugin::OPTION_LAYOUT, '' );
        if ( ! is_string( $value ) ) {
            $value = wp_json_encode( $value );
        }
        $has_tables = $this->tables->has_tables();
        ?>
        <?php if ( ! $has_tables ) : ?>
            <p class="notice notice-warning"><?php esc_html_e( 'Brak stolików w głównej wtyczce rezerwacji. Możesz wprowadzić je tutaj ręcznie.', 'seat-map-selector' ); ?></p>
        <?php endif; ?>
        <div class="seat-map-layout-builder" data-seat-map-builder data-value='<?php echo esc_attr( $value ); ?>'>
            <input type="hidden" name="<?php echo esc_attr( Seat_Map_Plugin::OPTION_LAYOUT ); ?>" value="<?php echo esc_attr( $value ); ?>" data-seat-map-value>
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
                <p class="description"><?php esc_html_e( 'Siatka mapy 100 x 100. Podaj współrzędne X oraz Y.', 'seat-map-selector' ); ?></p>
            <div class="seat-map-admin-preview" data-seat-map-admin-preview></div>
        </div>
        <?php
    }

    /**
     * Render background selection control.
     */
    public function render_background_field() {
        $attachment_id = $this->tables->get_background_image_id();
        $url           = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'large' ) : '';
        ?>
        <div class="seat-map-background-field" data-seat-map-background>
            <input type="hidden" name="<?php echo esc_attr( Seat_Map_Plugin::OPTION_BG_IMAGE ); ?>" value="<?php echo esc_attr( $attachment_id ); ?>" data-seat-map-bg-input>
            <div class="seat-map-background-preview" data-seat-map-bg-preview>
                <?php if ( $url ) : ?>
                    <img src="<?php echo esc_url( $url ); ?>" alt="" />
                <?php endif; ?>
            </div>
            <p>
                <button type="button" class="button" data-seat-map-bg-select><?php esc_html_e( 'Wybierz obraz', 'seat-map-selector' ); ?></button>
                <button type="button" class="button-link" data-seat-map-bg-clear><?php esc_html_e( 'Usuń', 'seat-map-selector' ); ?></button>
            </p>
            <p class="description"><?php esc_html_e( 'Obraz będzie użyty jako tło mapki podczas składania rezerwacji .', 'seat-map-selector' ); ?></p>
        </div>
        <?php
    }

    /**
     * Validate layout JSON before saving.
     *
     * @param mixed $value Option value.
     * @return string
     */
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
            add_settings_error( Seat_Map_Plugin::OPTION_LAYOUT, 'seat-map-json', __( 'Nieprawidłowy JSON. Sprawdź składnię.', 'seat-map-selector' ) );
            return $value;
        }

        return wp_json_encode( $decoded );
    }

    /**
     * Sanitize attachment ID for the background image.
     *
     * @param mixed $value Option value.
     * @return int
     */
    public function sanitize_background_option( $value ) {
        return absint( $value );
    }

    /**
     * Render the full settings page view.
     */
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
}
