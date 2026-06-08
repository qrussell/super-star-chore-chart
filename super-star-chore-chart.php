<?php
/**
 * Plugin Name: Super Star Chore Chart
 * Plugin URI:  https://yourwebsite.com/chore-chart
 * Description: Family chore chart with isolated App User accounts (Email Login) and Multi-Tenant Families.
 * Version:     2.3.0
 * Author:      Quentin Russell
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. DEFINE CONSTANTS
define( 'SSCC_VERSION',    '2.3.0' );
define( 'SSCC_DIR',        plugin_dir_path( __FILE__ ) );
define( 'SSCC_URL',        plugin_dir_url( __FILE__ ) );

// 2. REQUIRE FILES IN THE CORRECT ORDER
// db.php MUST be loaded first to define authentication functions
require_once SSCC_DIR . 'includes/db.php';
require_once SSCC_DIR . 'includes/login.php';
require_once SSCC_DIR . 'includes/ajax.php';
require_once SSCC_DIR . 'includes/shortcode.php';
require_once SSCC_DIR . 'admin/settings.php';

// 3. ASSET ENQUEUEING
add_action( 'wp_enqueue_scripts', 'sscc_enqueue_assets' );
function sscc_enqueue_assets() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'chore_chart' ) ) return;

    $opts = get_option( 'sscc_settings', [] );

    // Auth Check: Manually verify the custom cookie
    $is_app_logged_in = isset($_COOKIE['sscc_user_auth']);
    
    // Ensure the function exists before calling it
    $user_data = ($is_app_logged_in && function_exists('sscc_get_auth_user')) ? sscc_get_auth_user() : null;
    $family_data = $user_data ? sscc_get_user_family($user_data['id']) : null;

    wp_enqueue_style(  'sscc-app', SSCC_URL . 'assets/app.css', [], SSCC_VERSION );
    wp_enqueue_script( 'sscc-app', SSCC_URL . 'assets/app.js',  [], SSCC_VERSION, true );

    wp_localize_script( 'sscc-app', 'SSCC', [
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'sscc_nonce' ),
        'loggedIn'     => $is_app_logged_in, 
        'user'         => $user_data,
        'family'       => $family_data,
        'pollInterval' => intval( $opts['poll_interval'] ?? 15 ) * 1000,
        'version'      => SSCC_VERSION,
        'pluginUrl'    => SSCC_URL, // <--- THIS RESTORES THE PRINT STYLES
    ] );
}

// 4. ACTIVATION HOOK
register_activation_hook( __FILE__, 'sscc_activate' );
function sscc_activate() {
    sscc_create_tables();
    sscc_create_magic_token_table();

    if ( ! get_option( 'sscc_settings' ) ) {
        add_option( 'sscc_settings', [ 'poll_interval' => 15 ] );
    }
    if ( ! get_page_by_path( 'chore-chart' ) ) {
        wp_insert_post( [
            'post_title'   => 'Chore Chart',
            'post_content' => '[chore_chart]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => 'chore-chart',
        ] );
    }
    flush_rewrite_rules();
}

// 5. TEMPLATES
add_filter( 'theme_page_templates', function( $t ) {
    $t['sscc-full-width.php'] = 'Chore Chart – Full Width';
    return $t;
} );

add_filter( 'template_include', function( $tpl ) {
    if ( is_page() && get_post_meta( get_the_ID(), '_wp_page_template', true ) === 'sscc-full-width.php' ) {
        $f = SSCC_DIR . 'templates/full-width.php';
        if ( file_exists( $f ) ) return $f;
    }
    return $tpl;
} );