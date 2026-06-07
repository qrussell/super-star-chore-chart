<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Create magic token table
 */
function sscc_create_magic_token_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ssc_magic_tokens';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        PRIMARY KEY (id),
        KEY token (token)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Extend auth cookie to 1 year
 */
add_filter( 'auth_cookie_expiration', function( $seconds ) {
    return YEAR_IN_SECONDS;
});

/**
 * Generate magic link for a user
 */
function sscc_generate_magic_link( $user_id ) {
    global $wpdb;
    $table   = $wpdb->prefix . 'ssc_magic_tokens';
    $token   = wp_generate_password( 32, false );
    $expires = gmdate( 'Y-m-d H:i:s', time() + 3600 ); // 1 hour

    $wpdb->insert( $table, [
        'user_id' => $user_id,
        'token'   => $token,
        'expires' => $expires,
    ] );

    return add_query_arg(
        [ 'ssc_token' => $token ],
        site_url( '/chore-chart/' )
    );
}

/**
 * Handle magic-link auto-login
 */
add_action( 'init', function() {
    if ( ! isset( $_GET['ssc_token'] ) ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'ssc_magic_tokens';
    $token = sanitize_text_field( $_GET['ssc_token'] );

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table WHERE token = %s AND used = 0 AND expires > NOW()",
        $token
    ) );

    if ( ! $row ) return;

    // Mark token used
    $wpdb->update( $table, [ 'used' => 1 ], [ 'id' => $row->id ] );

    // Log user in with persistent cookie
    wp_set_auth_cookie( $row->user_id, true );
    wp_redirect( site_url( '/chore-chart/' ) );
    exit;
});

/**
 * AJAX: send magic link to current user
 */
function sscc_send_magic_link() {
    $user = wp_get_current_user();
    if ( ! $user || ! $user->ID ) {
        wp_send_json_error( [ 'message' => 'Not logged in as a parent.' ] );
    }

    $link = sscc_generate_magic_link( $user->ID );

    wp_mail(
        $user->user_email,
        'Your Chore Chart Login Link',
        "Tap this link to log in:\n\n{$link}\n\nThis link expires in 1 hour."
    );

    wp_send_json_success( [ 'message' => 'Magic link sent to your email.' ] );
}
add_action( 'wp_ajax_sscc_send_magic_link', 'sscc_send_magic_link' );

/**
 * AJAX: password login (optional)
 */
function sscc_password_login() {
    $email    = isset( $_POST['email'] ) ? sanitize_text_field( $_POST['email'] ) : '';
    $password = isset( $_POST['password'] ) ? $_POST['password'] : '';

    if ( ! $email || ! $password ) {
        wp_send_json_error( [ 'message' => 'Email and password are required.' ] );
    }

    $creds = [
        'user_login'    => $email,
        'user_password' => $password,
        'remember'      => true,
    ];

    $user = wp_signon( $creds );

    if ( is_wp_error( $user ) ) {
        wp_send_json_error( [ 'message' => 'Invalid login.' ] );
    }

    wp_send_json_success( [ 'message' => 'Logged in.' ] );
}
add_action( 'wp_ajax_nopriv_sscc_password_login', 'sscc_password_login' );
