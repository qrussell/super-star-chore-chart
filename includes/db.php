<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Create / upgrade the four SSCC database tables.
 *
 * Rules that dbDelta() requires (and silently breaks if violated):
 *  - NO "IF NOT EXISTS" — dbDelta handles that itself
 *  - One dbDelta() call per table (or one call with properly separated statements)
 *  - PRIMARY KEY must be followed by EXACTLY TWO spaces before "("
 *  - Every column on its own line
 *  - Use KEY, not INDEX
 *  - Each statement must end with the charset/collate string — no trailing semicolon
 *    inside the string (dbDelta adds its own)
 */
function sscc_create_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // ── families ────────────────────────────────────────────────────────────
    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_families (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  family_name varchar(100) NOT NULL,
  pass_hash varchar(255) NOT NULL,
  created_by bigint(20) UNSIGNED NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY family_name (family_name)
) $c;" );

    // ── members ─────────────────────────────────────────────────────────────
    dbDelta( "CREATE TABLE {$wpdb->prefix}sscc_members (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  family_id bigint(20) UNSIGNED NOT NULL,
  user_id bigint(20) UNSIGNED NOT NULL,
  role varchar(20) NOT NULL DEFAULT 'member',
  joined_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY family_user (family_id, user_id)
) $c;" );

    // ── chart_data ──────────────────────────────────────────────────────────
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

    // ── archives ─────────────────────────────────────────────────────────────
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

    update_option( 'sscc_db_version', SSCC_VERSION );
}

// ── Helpers used by ajax.php ──────────────────────────────────────────────────

function sscc_get_user_family( $user_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT f.id, f.family_name, m.role
           FROM {$wpdb->prefix}sscc_members m
           JOIN {$wpdb->prefix}sscc_families f ON f.id = m.family_id
          WHERE m.user_id = %d LIMIT 1",
        $user_id
    ) );
    return $row ? [ 'id' => (int)$row->id, 'name' => $row->family_name, 'role' => $row->role ] : null;
}

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

function sscc_get_current_user_data() {
    if ( ! is_user_logged_in() ) return null;
    $u = wp_get_current_user();
    return [ 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email ];
}

function sscc_default_categories() {
    $uid = function() { return 'id_' . substr( md5( uniqid( '', true ) ), 0, 8 ); };
    return [
        [ 'id' => $uid(), 'name' => 'Personal Care & Gear',   'isPaidCat' => false, 'tasks' => [
            [ 'id' => $uid(), 'name' => 'Make my bed',              'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Get dressed & ready',      'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Brush teeth (AM & PM)',    'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Put dirty clothes in hamper','isPaid' => false,'amount' => 0,   'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Pack/unpack backpack',     'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
        ]],
        [ 'id' => $uid(), 'name' => 'Shared Spaces & Meals',  'isPaidCat' => false, 'tasks' => [
            [ 'id' => $uid(), 'name' => 'Set the dinner table',     'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Clear & wipe table',       'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Tidy the living room',     'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Vacuum or sweep a room',   'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
        ]],
        [ 'id' => $uid(), 'name' => 'Kitchen Crew',           'isPaidCat' => false, 'tasks' => [
            [ 'id' => $uid(), 'name' => 'Load/unload dishwasher',   'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Wipe kitchen counters',    'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Take out recycling',       'isPaid' => false, 'amount' => 0,    'unit' => 'day',  'checks' => sscc_empty_checks() ],
        ]],
        [ 'id' => $uid(), 'name' => 'Brain Gigs 💡',          'isPaidCat' => true,  'tasks' => [
            [ 'id' => $uid(), 'name' => 'Read for 20 minutes',      'isPaid' => true,  'amount' => 0.25, 'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Practice spelling words',  'isPaid' => true,  'amount' => 0.25, 'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Math practice sheet',      'isPaid' => true,  'amount' => 0.25, 'unit' => 'day',  'checks' => sscc_empty_checks() ],
        ]],
        [ 'id' => $uid(), 'name' => 'Community & Bonus Gigs 💰','isPaidCat' => true, 'tasks' => [
            [ 'id' => $uid(), 'name' => 'Pick up yard litter',      'isPaid' => true,  'amount' => 0.50, 'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Help deep-clean a room',   'isPaid' => true,  'amount' => 1.00, 'unit' => 'day',  'checks' => sscc_empty_checks() ],
            [ 'id' => $uid(), 'name' => 'Wash the car',             'isPaid' => true,  'amount' => 2.00, 'unit' => 'flat', 'checks' => sscc_empty_checks() ],
        ]],
    ];
}

function sscc_empty_checks() {
    return [ 'mon'=>false,'tue'=>false,'wed'=>false,'thu'=>false,'fri'=>false,'sat'=>false,'sun'=>false ];
}

function sscc_monday_of_week( $date = null ) {
    $ts  = $date ? strtotime( $date ) : time();
    $dow = (int) date( 'N', $ts );
    return date( 'Y-m-d', $ts - ( $dow - 1 ) * 86400 );
}
