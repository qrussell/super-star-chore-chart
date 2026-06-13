<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Authentication Helper ────────────────────────────────────────────────────
function sscc_get_auth_user() {
    if ( ! isset( $_COOKIE['sscc_user_auth'] ) ) return null;
    
    $cookie_data = base64_decode( $_COOKIE['sscc_user_auth'] );
    $parts = explode( '|', $cookie_data );
    if ( count( $parts ) !== 2 ) return null;
    
    $uid  = intval($parts[0]);
    $hash = $parts[1]; // This is the SHA256 hash we created in login.php
    
    global $wpdb;
    $user = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sscc_users WHERE id = %d", 
        $uid
    ), ARRAY_A );
    
    if ( ! $user ) return null;
    
    // Verify the hash stored in the cookie matches the new SHA256 format generated at login
    $expected_hash = hash_hmac('sha256', $user['id'] . '|' . $user['email'], NONCE_SALT);
    
    if ( hash_equals($expected_hash, $hash) ) {
        return $user;
    }
    return null;
}

// ── Family Helper (Single Definition) ─────────────────────────────────────────
function sscc_get_user_family( $user_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT f.id, f.family_name, m.role,
         (SELECT COUNT(*) FROM {$wpdb->prefix}sscc_members WHERE family_id = f.id) as member_count
         FROM {$wpdb->prefix}sscc_members m
         JOIN {$wpdb->prefix}sscc_families f ON f.id = m.family_id
         WHERE m.user_id = %d LIMIT 1",
        $user_id
    ) );
    return $row ? [ 'id' => (int)$row->id, 'name' => $row->family_name, 'role' => $row->role, 'member_count' => (int)$row->member_count ] : null;
}

// ── Database Schema Setup ─────────────────────────────────────────────────────
function sscc_create_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_users (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email varchar(100) NOT NULL,
        pass_hash varchar(255) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_families (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        family_name varchar(100) NOT NULL,
        pass_hash varchar(255) NOT NULL,
        created_by bigint(20) UNSIGNED NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY family_name (family_name)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_members (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        family_id bigint(20) UNSIGNED NOT NULL,
        user_id bigint(20) UNSIGNED NOT NULL,
        role varchar(20) NOT NULL DEFAULT 'member',
        joined_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY family_user (family_id, user_id)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_chart_data (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        family_id bigint(20) UNSIGNED NOT NULL,
        data_key varchar(60) NOT NULL,
        data_json longtext NOT NULL,
        updated_by bigint(20) UNSIGNED NOT NULL,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY family_key (family_id, data_key)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_archives (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        family_id bigint(20) UNSIGNED NOT NULL,
        week_of varchar(10) NOT NULL,
        archive_json longtext NOT NULL,
        archived_by bigint(20) UNSIGNED NOT NULL,
        archived_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY family_week (family_id, week_of)
    ) $c;" );
    
    // Magic Token table for password reset/magic links
    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_magic_tokens (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        token varchar(64) NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY token (token)
    ) $c;" );
}

// ── Other Helpers ─────────────────────────────────────────────────────────────
function sscc_get_chart_data( $family_id, $key ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT data_json, updated_at FROM {$wpdb->prefix}sscc_chart_data
         WHERE family_id = %d AND data_key = %s",
        $family_id, $key
    ) );
    return $row ? [ 'data' => json_decode( $row->data_json, true ), 'updated_at' => $row->updated_at ] : null;
}

function sscc_set_chart_data( $family_id, $key, $data, $user_id ) {
    global $wpdb;
    $wpdb->replace(
        "{$wpdb->prefix}sscc_chart_data",
        [
            'family_id'  => $family_id,
            'data_key'   => $key,
            'data_json'  => wp_json_encode( $data ),
            'updated_by' => $user_id,
            'updated_at' => current_time( 'mysql' ),
        ],
        [ '%d', '%s', '%s', '%d', '%s' ]
    );
}

function sscc_default_categories() {
    $uid = function() { return 'id_' . substr( md5( uniqid( '', true ) ), 0, 8 ); };
    return [
        [ 'id' => $uid(), 'name' => 'Personal Care & Gear',   'isPaidCat' => false, 'tasks' => [
            [ 'id' => $uid(), 'name' => 'Make my bed',               'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Get dressed & ready',      'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
        ]],
        [ 'id' => $uid(), 'name' => 'Shared Spaces & Meals',  'isPaidCat' => false, 'tasks' => [
            [ 'id' => $uid(), 'name' => 'Set the dinner table',     'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
        ]],
        [ 'id' => $uid(), 'name' => 'Brain Gigs 💡',          'isPaidCat' => true,  'tasks' => [
            [ 'id' => $uid(), 'name' => 'Read for 20 minutes',      'isPaid' => true,  'amount' => 0.25, 'unit' => 'day',  'checks' => sscc_empty_checks() ],
        ]],
    ];
}

function sscc_empty_checks() { return [ 'mon'=>false,'tue'=>false,'wed'=>false,'thu'=>false,'fri'=>false,'sat'=>false,'sun'=>false ]; }

function sscc_monday_of_week( $date = null ) {
    $ts  = $date ? strtotime( $date ) : time();
    $dow = (int) date( 'N', $ts );
    return date( 'Y-m-d', $ts - ( $dow - 1 ) * 86400 );
}