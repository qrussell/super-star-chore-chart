<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Security helper ───────────────────────────────────────────────────────────
function sscc_check_auth() {
    if ( ! check_ajax_referer( 'sscc_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
    }
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'You must be logged in.' ], 401 );
    }
    return get_current_user_id();
}

function sscc_check_family_auth() {
    $uid    = sscc_check_auth();
    $family = sscc_get_user_family( $uid );
    if ( ! $family ) {
        wp_send_json_error( [ 'message' => 'You are not in a family.' ], 403 );
    }
    return [ 'uid' => $uid, 'family' => $family ];
}

// ── Register AJAX actions ─────────────────────────────────────────────────────
$sscc_actions = [
    'sscc_create_family', 'sscc_join_family',    'sscc_leave_family',
    'sscc_get_state',     'sscc_save_chart',      'sscc_save_defaults',
    'sscc_archive_week',  'sscc_get_archives',    'sscc_poll',
    'sscc_add_kid',       'sscc_remove_kid',      'sscc_rename_kid',
];
foreach ( $sscc_actions as $action ) {
    add_action( "wp_ajax_{$action}", "sscc_ajax_{$action}" );
}

// ── Create Family ─────────────────────────────────────────────────────────────
function sscc_ajax_sscc_create_family() {
    global $wpdb;
    $uid  = sscc_check_auth();
    if ( sscc_get_user_family( $uid ) ) {
        wp_send_json_error( [ 'message' => 'You are already in a family. Leave it first.' ] );
    }
    $name = sanitize_text_field( wp_unslash( $_POST['family_name'] ?? '' ) );
    $pass = sanitize_text_field( wp_unslash( $_POST['family_pass'] ?? '' ) );
    if ( strlen( $name ) < 2 || strlen( $pass ) < 4 ) {
        wp_send_json_error( [ 'message' => 'Family name must be ≥ 2 chars; password ≥ 4 chars.' ] );
    }
    // Check uniqueness
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}sscc_families WHERE family_name = %s", $name
    ) );
    if ( $exists ) {
        wp_send_json_error( [ 'message' => 'That family name is already taken.' ] );
    }
    $wpdb->insert( "{$wpdb->prefix}sscc_families", [
        'family_name' => $name,
        'pass_hash'   => wp_hash_password( $pass ),
        'created_by'  => $uid,
    ], [ '%s', '%s', '%d' ] );
    $fid = $wpdb->insert_id;
    $wpdb->insert( "{$wpdb->prefix}sscc_members", [
        'family_id' => $fid,
        'user_id'   => $uid,
        'role'      => 'admin',
    ], [ '%d', '%d', '%s' ] );
    // Seed default chart data
    $monday = sscc_monday_of_week();
    $kids   = [ [ 'id' => 'kid_' . substr( md5( $uid . $fid ), 0, 6 ), 'name' => 'Kid 1', 'categories' => sscc_default_categories() ] ];
    sscc_set_chart_data( $fid, 'week_of',  $monday,                     $uid );
    sscc_set_chart_data( $fid, 'kids',     $kids,                       $uid );
    sscc_set_chart_data( $fid, 'defaults', sscc_default_categories(),   $uid );
    wp_send_json_success( [ 'family' => [ 'id' => $fid, 'name' => $name, 'role' => 'admin' ] ] );
}

// ── Join Family ───────────────────────────────────────────────────────────────
function sscc_ajax_sscc_join_family() {
    global $wpdb;
    $uid  = sscc_check_auth();
    if ( sscc_get_user_family( $uid ) ) {
        wp_send_json_error( [ 'message' => 'Leave your current family before joining another.' ] );
    }
    $name = sanitize_text_field( wp_unslash( $_POST['family_name'] ?? '' ) );
    $pass = sanitize_text_field( wp_unslash( $_POST['family_pass'] ?? '' ) );
    $row  = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, pass_hash FROM {$wpdb->prefix}sscc_families WHERE family_name = %s", $name
    ) );
    if ( ! $row || ! wp_check_password( $pass, $row->pass_hash ) ) {
        wp_send_json_error( [ 'message' => 'Family name or password is incorrect.' ] );
    }
    $wpdb->insert( "{$wpdb->prefix}sscc_members", [
        'family_id' => $row->id,
        'user_id'   => $uid,
        'role'      => 'member',
    ], [ '%d', '%d', '%s' ] );
    wp_send_json_success( [ 'family' => [ 'id' => (int)$row->id, 'name' => $name, 'role' => 'member' ] ] );
}

// ── Leave Family ──────────────────────────────────────────────────────────────
function sscc_ajax_sscc_leave_family() {
    global $wpdb;
    $uid    = sscc_check_auth();
    $family = sscc_get_user_family( $uid );
    if ( ! $family ) {
        wp_send_json_error( [ 'message' => 'Not in a family.' ] );
    }
    $wpdb->delete( "{$wpdb->prefix}sscc_members", [ 'family_id' => $family['id'], 'user_id' => $uid ], [ '%d', '%d' ] );
    wp_send_json_success( [] );
}

// ── Get Full State ────────────────────────────────────────────────────────────
function sscc_ajax_sscc_get_state() {
    $ctx    = sscc_check_family_auth();
    $fid    = $ctx['family']['id'];
    $week   = sscc_get_chart_data( $fid, 'week_of' );
    $kids   = sscc_get_chart_data( $fid, 'kids' );
    $defs   = sscc_get_chart_data( $fid, 'defaults' );
    wp_send_json_success( [
        'weekOf'     => $week  ? $week['data']       : sscc_monday_of_week(),
        'kids'       => $kids  ? $kids['data']        : [],
        'defaults'   => $defs  ? $defs['data']        : sscc_default_categories(),
        'updatedAt'  => $kids  ? $kids['updated_at']  : null,
        'family'     => $ctx['family'],
    ] );
}

// ── Save Chart ────────────────────────────────────────────────────────────────
function sscc_ajax_sscc_save_chart() {
    $ctx  = sscc_check_family_auth();
    $fid  = $ctx['family']['id'];
    $raw  = wp_unslash( $_POST['kids'] ?? '' );
    $kids = json_decode( $raw, true );
    if ( ! is_array( $kids ) ) {
        wp_send_json_error( [ 'message' => 'Invalid data.' ] );
    }
    sscc_set_chart_data( $fid, 'kids', $kids, $ctx['uid'] );
    wp_send_json_success( [ 'updatedAt' => current_time( 'mysql' ) ] );
}

// ── Save Defaults ─────────────────────────────────────────────────────────────
function sscc_ajax_sscc_save_defaults() {
    $ctx  = sscc_check_family_auth();
    $fid  = $ctx['family']['id'];
    $raw  = wp_unslash( $_POST['defaults'] ?? '' );
    $defs = json_decode( $raw, true );
    if ( ! is_array( $defs ) ) {
        wp_send_json_error( [ 'message' => 'Invalid data.' ] );
    }
    sscc_set_chart_data( $fid, 'defaults', $defs, $ctx['uid'] );
    wp_send_json_success( [] );
}

// ── Archive Week ──────────────────────────────────────────────────────────────
function sscc_ajax_sscc_archive_week() {
    global $wpdb;
    $ctx    = sscc_check_family_auth();
    $fid    = $ctx['family']['id'];
    $uid    = $ctx['uid'];
    $useDefaults = (bool) ( $_POST['use_defaults'] ?? false );

    $week_row = sscc_get_chart_data( $fid, 'week_of' );
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    $week_of  = $week_row ? $week_row['data'] : sscc_monday_of_week();
    $kids     = $kids_row ? $kids_row['data'] : [];

    // Save archive snapshot
    $wpdb->insert( "{$wpdb->prefix}sscc_archives", [
        'family_id'    => $fid,
        'week_of'      => $week_of,
        'archive_json' => wp_json_encode( $kids ),
        'archived_by'  => $uid,
    ], [ '%d', '%s', '%s', '%d' ] );

    // Advance week
    $new_week = sscc_monday_of_week( date( 'Y-m-d', strtotime( $week_of ) + 7 * 86400 ) );
    sscc_set_chart_data( $fid, 'week_of', $new_week, $uid );

    // Reset kids — either apply defaults or just clear checkboxes
    if ( $useDefaults ) {
        $defs_row  = sscc_get_chart_data( $fid, 'defaults' );
        $defs      = $defs_row ? $defs_row['data'] : sscc_default_categories();
        $kid_names = array_column( $kids, 'name' );
        $new_kids  = [];
        foreach ( $kid_names as $i => $kname ) {
            $new_kids[] = [ 'id' => $kids[$i]['id'] ?? ( 'kid_' . $i ), 'name' => $kname, 'categories' => sscc_clone_defaults( $defs ) ];
        }
    } else {
        $new_kids = sscc_clear_all_checks( $kids );
    }
    sscc_set_chart_data( $fid, 'kids', $new_kids, $uid );
    wp_send_json_success( [ 'newWeekOf' => $new_week, 'kids' => $new_kids ] );
}

function sscc_clone_defaults( $defs ) {
    foreach ( $defs as &$cat ) {
        foreach ( $cat['tasks'] as &$task ) {
            $task['checks'] = sscc_empty_checks();
        }
    }
    return $defs;
}

function sscc_clear_all_checks( $kids ) {
    foreach ( $kids as &$kid ) {
        foreach ( $kid['categories'] as &$cat ) {
            foreach ( $cat['tasks'] as &$task ) {
                $task['checks'] = sscc_empty_checks();
            }
        }
    }
    return $kids;
}

// ── Get Archives ──────────────────────────────────────────────────────────────
function sscc_ajax_sscc_get_archives() {
    global $wpdb;
    $ctx  = sscc_check_family_auth();
    $fid  = $ctx['family']['id'];
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, week_of, archived_at, archive_json FROM {$wpdb->prefix}sscc_archives
          WHERE family_id = %d ORDER BY week_of DESC LIMIT 52",
        $fid
    ) );
    $archives = array_map( function( $r ) {
        return [
            'id'          => (int) $r->id,
            'week_of'     => $r->week_of,
            'archived_at' => $r->archived_at,
            'kids'        => json_decode( $r->archive_json, true ),
        ];
    }, $rows );
    wp_send_json_success( [ 'archives' => $archives ] );
}

// ── Poll for changes ──────────────────────────────────────────────────────────
function sscc_ajax_sscc_poll() {
    global $wpdb;
    $ctx      = sscc_check_family_auth();
    $fid      = $ctx['family']['id'];
    $last     = sanitize_text_field( $_POST['last_updated'] ?? '' );
    $row      = $wpdb->get_row( $wpdb->prepare(
        "SELECT updated_at FROM {$wpdb->prefix}sscc_chart_data
          WHERE family_id = %d AND data_key = 'kids'",
        $fid
    ) );
    $changed = $row && ( ! $last || $row->updated_at > $last );
    wp_send_json_success( [ 'changed' => $changed, 'updatedAt' => $row ? $row->updated_at : null ] );
}

// ── Add Kid ───────────────────────────────────────────────────────────────────
function sscc_ajax_sscc_add_kid() {
    $ctx  = sscc_check_family_auth();
    $fid  = $ctx['family']['id'];
    $name = sanitize_text_field( wp_unslash( $_POST['kid_name'] ?? 'New Kid' ) );
    $defs_row = sscc_get_chart_data( $fid, 'defaults' );
    $defs     = $defs_row ? $defs_row['data'] : sscc_default_categories();
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    $kids     = $kids_row ? $kids_row['data'] : [];
    $kids[]   = [ 'id' => 'kid_' . substr( md5( uniqid() ), 0, 6 ), 'name' => $name, 'categories' => sscc_clone_defaults( $defs ) ];
    sscc_set_chart_data( $fid, 'kids', $kids, $ctx['uid'] );
    wp_send_json_success( [ 'kids' => $kids ] );
}

// ── Remove Kid ────────────────────────────────────────────────────────────────
function sscc_ajax_sscc_remove_kid() {
    $ctx    = sscc_check_family_auth();
    $fid    = $ctx['family']['id'];
    $kid_id = sanitize_text_field( $_POST['kid_id'] ?? '' );
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    $kids   = $kids_row ? $kids_row['data'] : [];
    $kids   = array_values( array_filter( $kids, fn($k) => $k['id'] !== $kid_id ) );
    sscc_set_chart_data( $fid, 'kids', $kids, $ctx['uid'] );
    wp_send_json_success( [ 'kids' => $kids ] );
}

// ── Rename Kid ────────────────────────────────────────────────────────────────
function sscc_ajax_sscc_rename_kid() {
    $ctx    = sscc_check_family_auth();
    $fid    = $ctx['family']['id'];
    $kid_id = sanitize_text_field( $_POST['kid_id'] ?? '' );
    $name   = sanitize_text_field( wp_unslash( $_POST['kid_name'] ?? '' ) );
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    $kids   = $kids_row ? $kids_row['data'] : [];
    foreach ( $kids as &$k ) {
        if ( $k['id'] === $kid_id ) { $k['name'] = $name; break; }
    }
    sscc_set_chart_data( $fid, 'kids', $kids, $ctx['uid'] );
    wp_send_json_success( [ 'kids' => $kids ] );
}
