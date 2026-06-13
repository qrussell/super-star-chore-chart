<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handle Custom Email Registration
 */
function sscc_user_register() {
    global $wpdb;

    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    $pass  = wp_unslash( $_POST['password'] ?? '' );
    
    if ( ! is_email( $email ) || strlen( $pass ) < 6 ) {
        wp_send_json_error( [ 'message' => 'Please provide a valid email and a password of at least 6 characters.' ] );
    }
    
    $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$wpdb->prefix}sscc_users WHERE email = %s", $email) );
    if ( $exists ) {
        wp_send_json_error( [ 'message' => 'Email already registered. Please log in.' ] );
    }
    
    $inserted = $wpdb->insert( "{$wpdb->prefix}sscc_users", [
        'email'     => $email,
        'pass_hash' => wp_hash_password( $pass ),
    ], [ '%s', '%s' ] );
    
    if ( ! $inserted ) {
        wp_send_json_error( [ 'message' => 'Failed to create account. Please try again.' ] );
    }
    
    $uid = $wpdb->insert_id;
    
    // Issue Custom User Cookie with stronger hash
    $hash = hash_hmac('sha256', $uid . '|' . $email, NONCE_SALT);
    
    setcookie(
        'sscc_user_auth', 
        base64_encode($uid . '|' . $hash), 
        time() + (30 * DAY_IN_SECONDS), 
        COOKIEPATH, 
        COOKIE_DOMAIN, 
        is_ssl(), 
        true      // HttpOnly = true is safer
    );
    
    wp_send_json_success();
}
add_action('wp_ajax_nopriv_sscc_user_register', 'sscc_user_register');
add_action('wp_ajax_sscc_user_register', 'sscc_user_register');

/**
 * Handle Custom Email Login
 */
function sscc_user_login() {
    global $wpdb;
    
    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    $pass  = wp_unslash( $_POST['password'] ?? '' );
    
    $user = $wpdb->get_row( $wpdb->prepare("SELECT id, email, pass_hash FROM {$wpdb->prefix}sscc_users WHERE email = %s", $email) );
    
    if ( ! $user || ! wp_check_password( $pass, $user->pass_hash ) ) {
        wp_send_json_error( [ 'message' => 'Invalid email or password.' ] );
    }
    
    // Issue Custom User Cookie with stronger hash
    $hash = hash_hmac('sha256', $user->id . '|' . $user->email, NONCE_SALT);
    
    setcookie(
        'sscc_user_auth', 
        base64_encode($user->id . '|' . $hash), 
        time() + (30 * DAY_IN_SECONDS), 
        COOKIEPATH, 
        COOKIE_DOMAIN, 
        is_ssl(), 
        true
    );
    
    wp_send_json_success();
}
add_action('wp_ajax_nopriv_sscc_user_login', 'sscc_user_login');
add_action('wp_ajax_sscc_user_login', 'sscc_user_login');

/**
 * Handle Custom Logout
 */
function sscc_user_logout() {
    // Set the value to an empty string and expire it in the past
    setcookie('sscc_user_auth', '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    wp_send_json_success();
}
add_action('wp_ajax_nopriv_sscc_user_logout', 'sscc_user_logout');
add_action('wp_ajax_sscc_user_logout', 'sscc_user_logout');

/**
 * Magic Links & Legacy Token table schema kept for potential future use.
 */
function sscc_create_magic_token_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'ssc_magic_tokens';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, token VARCHAR(64) NOT NULL, expires DATETIME NOT NULL, used TINYINT(1) DEFAULT 0, PRIMARY KEY (id), KEY token (token) ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}