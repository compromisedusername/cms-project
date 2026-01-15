<?php
/**
 * Plugin Name: Custom Registration Enhancements
 * Description: Allows front-end registration without email delivery by letting users set passwords and skipping notification emails.
 * Author: Codex Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Output password fields on the default registration form.
 */
add_action( 'register_form', function () {
    ?>
    <p>
        <label for="user_pass"><?php esc_html_e( 'Hasło', 'seat-map-selector' ); ?><br/>
            <input type="password" name="user_pass" id="user_pass" class="input" required />
        </label>
    </p>
    <p>
        <label for="user_pass_confirm"><?php esc_html_e( 'Powtórz hasło', 'seat-map-selector' ); ?><br/>
            <input type="password" name="user_pass_confirm" id="user_pass_confirm" class="input" required />
        </label>
    </p>
    <?php
} );

/**
 * Validate supplied passwords during registration.
 */
add_action( 'register_post', function ( $login, $email, $errors ) {
    $password        = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';
    $password_confirm = isset( $_POST['user_pass_confirm'] ) ? (string) wp_unslash( $_POST['user_pass_confirm'] ) : '';

    if ( '' === $password || '' === $password_confirm ) {
        $errors->add( 'empty_password', __( 'Podaj hasło i jego potwierdzenie.', 'seat-map-selector' ) );
        return;
    }

    if ( $password !== $password_confirm ) {
        $errors->add( 'password_mismatch', __( 'Podane hasła muszą być identyczne.', 'seat-map-selector' ) );
    }
}, 10, 3 );

/**
 * Persist the chosen password for the newly created user.
 */
add_action( 'user_register', function ( $user_id ) {
    if ( empty( $_POST['user_pass'] ) ) {
        return;
    }

    $password = (string) wp_unslash( $_POST['user_pass'] );

    if ( '' !== $password ) {
        wp_set_password( $password, $user_id );
    }
} );

/**
 * Disable default welcome emails since users already choose their password.
 */
add_action( 'init', function () {
    remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
    remove_action( 'edit_user_created_user', 'wp_send_new_user_notifications', 10, 2 );
}, 1 );
