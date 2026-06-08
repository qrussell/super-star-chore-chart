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
// ── Mobile Web App (PWA) Meta Tags ──────────────────────────────────────────
add_action( 'wp_head', 'sscc_mobile_web_app_meta' );
function sscc_mobile_web_app_meta() {
    global $post;
    // Only inject on the chore chart page
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'chore_chart' ) ) return;
    
    $manifest_url = admin_url( 'admin-ajax.php?action=sscc_manifest' );
    $icon_url     = SSCC_URL . 'assets/icon.png';
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Chores">
    <link rel="apple-touch-icon" href="<?php echo esc_url($icon_url); ?>">
    
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
    <?php
}
// ── Pure App iFrame Endpoint ────────────────────────────────────────────────
add_action( 'template_redirect', 'sscc_render_iframe_view' );
function sscc_render_iframe_view() {
    // If the URL does not have ?sscc_view=app, let WordPress load normally
    if ( ! isset($_GET['sscc_view']) || $_GET['sscc_view'] !== 'app' ) return;

    $opts = get_option( 'sscc_settings', [] );
    $is_app_logged_in = isset($_COOKIE['sscc_user_auth']);
    $user_data = ($is_app_logged_in && function_exists('sscc_get_auth_user')) ? sscc_get_auth_user() : null;
    $family_data = $user_data && function_exists('sscc_get_user_family') ? sscc_get_user_family($user_data['id']) : null;

    // Output pure, isolated HTML
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
        <title>Chore Chart</title>
        
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="mobile-web-app-capable" content="yes">
        <link rel="manifest" href="<?php echo esc_url(admin_url('admin-ajax.php?action=sscc_manifest')); ?>">
        <link rel="apple-touch-icon" href="<?php echo esc_url(SSCC_URL . 'assets/icon.png'); ?>">
        
        <link rel="stylesheet" href="<?php echo esc_url(SSCC_URL . 'assets/app.css?ver=' . SSCC_VERSION); ?>">
        
        <script>
            var SSCC = <?php echo wp_json_encode([
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'sscc_nonce' ),
                'loggedIn'     => $is_app_logged_in,
                'user'         => $user_data,
                'family'       => $family_data,
                'pollInterval' => intval( $opts['poll_interval'] ?? 15 ) * 1000,
                'version'      => SSCC_VERSION,
                'pluginUrl'    => SSCC_URL
            ]); ?>;
        </script>
        <script src="<?php echo esc_url(SSCC_URL . 'assets/app.js?ver=' . SSCC_VERSION); ?>" defer></script>
    </head>
    <body style="margin: 0; padding: 0; background: #111; overflow-x: hidden;">
        <div id="sscc-app"></div>
    </body>
    </html>
    <?php
    exit; // Stop WordPress from loading the rest of the site
}