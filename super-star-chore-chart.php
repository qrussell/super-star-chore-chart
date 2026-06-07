<?php
/**
 * Plugin Name: Super Star Chore Chart
 * Plugin URI:  https://yourwebsite.com/chore-chart
 * Description: Family chore chart with user accounts, family groups, shared real-time charts, weekly archives, and printable output. Use [chore_chart] shortcode.
 * Version:     2.0.1
 * Author:      Quentin Russell
 * License:     GPL v2 or later
 * Text Domain: super-star-chore-chart
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SSCC_VERSION',    '2.0.1' );
define( 'SSCC_DIR',        plugin_dir_path( __FILE__ ) );
define( 'SSCC_URL',        plugin_dir_url( __FILE__ ) );
define( 'SSCC_FILE',       __FILE__ );

require_once SSCC_DIR . 'includes/db.php';
require_once SSCC_DIR . 'includes/ajax.php';
require_once SSCC_DIR . 'includes/shortcode.php';
require_once SSCC_DIR . 'admin/settings.php';

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'sscc_activate' );
function sscc_activate() {
    sscc_create_tables();
    if ( ! get_option( 'sscc_settings' ) ) {
        add_option( 'sscc_settings', [
            'iframe_height'      => 1000,
            'full_width'         => 1,
            'allow_registration' => 1,
            'max_family_members' => 20,
            'poll_interval'      => 15,
        ] );
    }
    // Create the Chore Chart page if it doesn't exist
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

register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );

// ── Auto-upgrade tables on plugin update ──────────────────────────────────────
// Runs on every page load (plugins_loaded) but only calls dbDelta when the
// stored DB version doesn't match the current plugin version — cheap check.
add_action( 'plugins_loaded', 'sscc_maybe_upgrade_db' );
function sscc_maybe_upgrade_db() {
    if ( get_option( 'sscc_db_version' ) !== SSCC_VERSION ) {
        sscc_create_tables(); // also calls update_option( 'sscc_db_version', ... )
    }
}

// ── Enqueue assets ────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'sscc_enqueue_assets' );
function sscc_enqueue_assets() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'chore_chart' ) ) return;

    $opts = get_option( 'sscc_settings', [] );

    wp_enqueue_style(  'sscc-app', SSCC_URL . 'assets/app.css', [], SSCC_VERSION );
    wp_enqueue_script( 'sscc-app', SSCC_URL . 'assets/app.js',  [], SSCC_VERSION, true );

    // Collect family & user state to pass into JS
    $user_data   = sscc_get_current_user_data();
    $family_data = is_user_logged_in() ? sscc_get_user_family( get_current_user_id() ) : null;

    wp_localize_script( 'sscc-app', 'SSCC', [
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'sscc_nonce' ),
        'loggedIn'     => is_user_logged_in(),
        'loginUrl'     => wp_login_url( get_permalink() ),
        'registerUrl'  => wp_registration_url(),
        'user'         => $user_data,
        'family'       => $family_data,
        'pollInterval' => intval( $opts['poll_interval'] ?? 15 ) * 1000,
        'version'      => SSCC_VERSION,
    ] );
}

// ── Full-width page template ──────────────────────────────────────────────────
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
