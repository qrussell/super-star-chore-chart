<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Magic Link (Using Transients) ──────────────────────────────────────────
function sscc_ajax_sscc_get_magic_link() {
    $ctx = sscc_check_family_auth();
    $fid = $ctx['family']['id'];
    
    $token = wp_generate_password(20, false);
    set_transient('sscc_magic_' . $token, $fid, 7 * DAY_IN_SECONDS);
    
    $base_url = site_url('/super-star-chore-chart/'); 
    $pages = get_pages();
    foreach ( $pages as $p ) {
        if ( has_shortcode( $p->post_content, 'chore_chart' ) ) {
            $base_url = get_permalink( $p->ID );
            break;
        }
    }
    
    $separator = strpos($base_url, '?') !== false ? '&' : '?';
    $url = $base_url . $separator . 'magic=' . $token;
    wp_send_json_success(['url' => $url]);
}

// ── Authentication Check ──────────────────────────────────────────────────────
function sscc_check_auth() {
    if ( ! check_ajax_referer( 'sscc_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
    }
    $user = sscc_get_auth_user();
    if ( !$user ) {
        wp_send_json_error( [ 'message' => 'You must be logged in to the Chore Chart.' ], 401 );
    }
    return $user['id'];
}

function sscc_check_family_auth() {
    $uid    = sscc_check_auth();
    $family = sscc_get_user_family( $uid );
    if ( ! $family ) {
        wp_send_json_error( [ 'message' => 'You are not in a family.' ], 403 );
    }
    return [ 'uid' => $uid, 'family' => $family ];
}

// ── Register all actions for NOPRIV ──────────────────────────────────────────
$sscc_actions = [
    'sscc_create_family', 'sscc_join_family',    'sscc_leave_family',
    'sscc_get_state',     'sscc_save_chart',      'sscc_save_defaults',
    'sscc_archive_week',  'sscc_get_archives',    'sscc_poll',
    'sscc_add_kid',       'sscc_remove_kid',      'sscc_rename_kid',
    'sscc_manifest',      'sscc_get_magic_link',  'sscc_get_archive_by_id',
    'sscc_change_family_password', 'sscc_forgot_password',
    'sscc_save_new_password',
    'sscc_update_archive', 'sscc_make_current' // NEW: Editable Archives & Make Current
];
foreach ( $sscc_actions as $action ) {
    add_action( "wp_ajax_{$action}", "sscc_ajax_{$action}" );
    add_action( "wp_ajax_nopriv_{$action}", "sscc_ajax_{$action}" );
}

// ── Create Family ─────────────────────────────────────────────────────────────
function sscc_ajax_sscc_create_family() {
    global $wpdb;
    $uid  = sscc_check_auth();
    if ( sscc_get_user_family( $uid ) ) wp_send_json_error( [ 'message' => 'You are already in a family. Leave it first.' ] );
    
    $name = sanitize_text_field( wp_unslash( $_POST['family_name'] ?? '' ) );
    $pass = sanitize_text_field( wp_unslash( $_POST['family_pass'] ?? '' ) );
    if ( strlen( $name ) < 2 || strlen( $pass ) < 4 ) wp_send_json_error( [ 'message' => 'Family name ≥ 2 chars; password ≥ 4 chars.' ] );
    
    $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$wpdb->prefix}sscc_families WHERE family_name = %s", $name) );
    if ( $exists ) wp_send_json_error( [ 'message' => 'That family name is already taken.' ] );

    $wpdb->insert( "{$wpdb->prefix}sscc_families", [ 'family_name' => $name, 'pass_hash' => wp_hash_password( $pass ), 'created_by' => $uid ], [ '%s', '%s', '%d' ] );
    $fid = $wpdb->insert_id;
    
    $wpdb->insert( "{$wpdb->prefix}sscc_members", [ 'family_id' => $fid, 'user_id' => $uid, 'role' => 'admin' ], [ '%d', '%d', '%s' ] );
    
    $monday = sscc_monday_of_week();
    $kids   = [ [ 'id' => 'kid_' . substr( md5( $uid . $fid ), 0, 6 ), 'name' => 'Kid 1', 'categories' => sscc_clone_defaults(sscc_default_categories()) ] ];
    sscc_set_chart_data( $fid, 'week_of',  $monday, $uid );
    sscc_set_chart_data( $fid, 'kids',     $kids,   $uid );
    sscc_set_chart_data( $fid, 'defaults', sscc_default_categories(), $uid );
    
    wp_send_json_success( [ 'family' => [ 'id' => $fid, 'name' => $name, 'role' => 'admin' ] ] );
}

// ── Join & Leave Family ───────────────────────────────────────────────────────
function sscc_ajax_sscc_join_family() {
    global $wpdb;
    $uid  = sscc_check_auth();
    if ( sscc_get_user_family( $uid ) ) wp_send_json_error( [ 'message' => 'Leave your current family before joining another.' ] );
    
    $name = sanitize_text_field( wp_unslash( $_POST['family_name'] ?? '' ) );
    $pass = sanitize_text_field( wp_unslash( $_POST['family_pass'] ?? '' ) );
    $row  = $wpdb->get_row( $wpdb->prepare("SELECT id, pass_hash FROM {$wpdb->prefix}sscc_families WHERE family_name = %s", $name) );
    
    if ( ! $row || ! wp_check_password( $pass, $row->pass_hash ) ) wp_send_json_error( [ 'message' => 'Family name or password is incorrect.' ] );
    
    $wpdb->insert( "{$wpdb->prefix}sscc_members", [ 'family_id' => $row->id, 'user_id' => $uid, 'role' => 'member' ], [ '%d', '%d', '%s' ] );
    wp_send_json_success( [ 'family' => [ 'id' => (int)$row->id, 'name' => $name, 'role' => 'member' ] ] );
}

function sscc_ajax_sscc_leave_family() {
    global $wpdb;
    $uid    = sscc_check_auth();
    $family = sscc_get_user_family( $uid );
    if ( ! $family ) wp_send_json_error( [ 'message' => 'Not in a family.' ] );
    $wpdb->delete( "{$wpdb->prefix}sscc_members", [ 'family_id' => $family['id'], 'user_id' => $uid ], [ '%d', '%d' ] );
    wp_send_json_success( [] );
}

// ── State Handlers ────────────────────────────────────────────────────────────
function sscc_ajax_sscc_get_state() {
    $ctx    = sscc_check_family_auth();
    $fid    = $ctx['family']['id'];
    $week   = sscc_get_chart_data( $fid, 'week_of' );
    $kids   = sscc_get_chart_data( $fid, 'kids' );
    $defs   = sscc_get_chart_data( $fid, 'defaults' );
    wp_send_json_success( [
        'weekOf'     => $week  ? $week['data']       : sscc_monday_of_week(),
        'kids'       => $kids  ? $kids['data']       : [],
        'defaults'   => $defs  ? $defs['data']       : sscc_default_categories(),
        'updatedAt'  => $kids  ? $kids['updated_at'] : null,
        'family'     => $ctx['family'],
    ] );
}

function sscc_ajax_sscc_save_chart() {
    $ctx  = sscc_check_family_auth();
    $raw  = wp_unslash( $_POST['kids'] ?? '' );
    $kids = json_decode( $raw, true );
    if ( ! is_array( $kids ) ) wp_send_json_error( [ 'message' => 'Invalid data.' ] );
    sscc_set_chart_data( $ctx['family']['id'], 'kids', $kids, $ctx['uid'] );
    wp_send_json_success( [ 'updatedAt' => current_time( 'mysql' ) ] );
}

function sscc_ajax_sscc_save_defaults() {
    $ctx  = sscc_check_family_auth();
    $fid  = $ctx['family']['id'];
    $uid  = $ctx['uid'];
    $raw  = wp_unslash( $_POST['defaults'] ?? '' );
    $defs = json_decode( $raw, true );
    
    if ( ! is_array( $defs ) ) wp_send_json_error( [ 'message' => 'Invalid data.' ] );
    
    // 1. Save the new defaults
    sscc_set_chart_data( $fid, 'defaults', $defs, $uid );

    // 2. LIVE MERGE: Update the current kids chart immediately
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    if ( $kids_row && !empty($kids_row['data']) ) {
        $kids = $kids_row['data'];

        foreach ( $kids as &$kid ) {
            // A. Map existing shared tasks by ID so we don't lose checkmarks mid-week
            $existing_shared_checks = [];
            foreach ( $kid['categories'] as $old_cat ) {
                foreach ( $old_cat['tasks'] as $old_task ) {
                    if ( isset($old_task['scope']) && $old_task['scope'] === 'shared' && isset($old_task['id']) ) {
                        $existing_shared_checks[$old_task['id']] = $old_task['checks'] ?? sscc_empty_checks();
                    }
                }
            }

            // B. Clone the fresh defaults for this kid
            $new_categories = sscc_clone_defaults( $defs );

            // C. Restore checkmarks for the shared tasks if they already existed
            foreach ( $new_categories as &$new_cat ) {
                foreach ( $new_cat['tasks'] as &$new_task ) {
                    if ( isset($existing_shared_checks[$new_task['id']]) ) {
                        $new_task['checks'] = $existing_shared_checks[$new_task['id']];
                    }
                }
            }

            // D. Re-inject Personal Tasks from the kid's old categories
            $cat_map = [];
            foreach ( $new_categories as $idx => $cat ) {
                if ( isset($cat['id']) ) $cat_map[$cat['id']] = $idx;
            }

            foreach ( $kid['categories'] as $old_cat ) {
                if ( !isset($old_cat['id']) || !isset($cat_map[$old_cat['id']]) ) continue;
                
                $target_idx = $cat_map[$old_cat['id']];
                foreach ( $old_cat['tasks'] as $task ) {
                    if ( isset($task['scope']) && $task['scope'] === 'personal' ) {
                        $new_categories[$target_idx]['tasks'][] = $task;
                    }
                }
            }

            // E. Apply the newly merged categories back to the kid
            $kid['categories'] = $new_categories;
        }

        // 3. Save the updated kids array back to the live chart
        sscc_set_chart_data( $fid, 'kids', $kids, $uid );
    }

    wp_send_json_success( [] );
}

// ── SMART MERGE: Archive Week ───────────────────────────────────────────────
function sscc_ajax_sscc_archive_week() {
    global $wpdb;
    $ctx = sscc_check_family_auth();
    $fid = $ctx['family']['id'];
    $uid = $ctx['uid'];

    $week_row = sscc_get_chart_data( $fid, 'week_of' );
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    $week_of  = $week_row ? $week_row['data'] : sscc_monday_of_week();
    $kids     = $kids_row ? $kids_row['data'] : [];

    // 1. Save Snapshot to Archive Table
    $wpdb->insert( "{$wpdb->prefix}sscc_archives", [ 
        'family_id' => $fid, 
        'week_of' => $week_of, 
        'archive_json' => wp_json_encode( $kids ), 
        'archived_by' => $uid 
    ], [ '%d', '%s', '%s', '%d' ] );

    // 2. Set Up New Week Date
    $new_week = sscc_monday_of_week( date( 'Y-m-d', strtotime( $week_of ) + 7 * 86400 ) );
    sscc_set_chart_data( $fid, 'week_of', $new_week, $uid );

    // 3. Smart Merge: Base Template + Personal Tasks
    $defs_row = sscc_get_chart_data( $fid, 'defaults' );
    $defs     = $defs_row ? $defs_row['data'] : sscc_default_categories();
    
    $new_kids = [];
    foreach ( $kids as $kid ) {
        // Clone fresh defaults
        $merged_categories = sscc_clone_defaults($defs);
        
        // Map Categories by ID to safely place personal tasks
        $cat_map = [];
        foreach ($merged_categories as $idx => $cat) {
            if (isset($cat['id'])) $cat_map[$cat['id']] = $idx;
        }

        // Re-inject "Personal" tasks from this specific kid
        foreach ( $kid['categories'] as $old_cat ) {
            if (!isset($old_cat['id']) || !isset($cat_map[$old_cat['id']])) continue;
            
            $target_idx = $cat_map[$old_cat['id']];
            foreach ( $old_cat['tasks'] as $task ) {
                if (isset($task['scope']) && $task['scope'] === 'personal') {
                    $task['checks'] = sscc_empty_checks(); // Clear checks
                    $merged_categories[$target_idx]['tasks'][] = $task;
                }
            }
        }

        $new_kids[] = [ 
            'id' => $kid['id'] ?? ('kid_' . uniqid()), 
            'name' => $kid['name'], 
            'categories' => $merged_categories 
        ];
    }
    
    sscc_set_chart_data( $fid, 'kids', $new_kids, $uid );
    wp_send_json_success( [ 'newWeekOf' => $new_week, 'kids' => $new_kids ] );
}

function sscc_clone_defaults( $defs ) { 
    foreach ( $defs as &$cat ) { 
        foreach ( $cat['tasks'] as &$task ) { 
            $task['checks'] = sscc_empty_checks(); 
            $task['scope'] = 'shared'; // Force default tasks to 'shared'
        } 
    } 
    return $defs; 
}

// ── Archive Endpoints (Get, Edit, Make Current) ───────────────────────────
function sscc_ajax_sscc_get_archives() {
    global $wpdb;
    $ctx  = sscc_check_family_auth();
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, week_of, archived_at, archive_json FROM {$wpdb->prefix}sscc_archives WHERE family_id = %d ORDER BY week_of DESC LIMIT 52", $ctx['family']['id'] ) );
    $archives = array_map( function( $r ) { return [ 'id' => (int) $r->id, 'week_of' => $r->week_of, 'archived_at' => $r->archived_at, 'kids' => json_decode( $r->archive_json, true ) ]; }, $rows );
    wp_send_json_success( [ 'archives' => $archives ] );
}

function sscc_ajax_sscc_get_archive_by_id() {
    global $wpdb;
    $ctx = sscc_check_family_auth();
    $archive_id = intval($_POST['archive_id']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT archive_json FROM {$wpdb->prefix}sscc_archives WHERE id = %d AND family_id = %d", $archive_id, $ctx['family']['id']));
    if (!$row) wp_send_json_error(['message' => 'Archive not found.']);
    wp_send_json_success(['kids' => json_decode($row->archive_json, true)]);
}

function sscc_ajax_sscc_update_archive() {
    global $wpdb;
    $ctx = sscc_check_family_auth();
    $archive_id = intval($_POST['archive_id']);
    $raw = wp_unslash($_POST['kids'] ?? '');
    $kids = json_decode($raw, true);

    if (!is_array($kids) || !$archive_id) wp_send_json_error(['message' => 'Invalid data.']);

    $wpdb->update( "{$wpdb->prefix}sscc_archives", ['archive_json' => wp_json_encode($kids)], ['id' => $archive_id, 'family_id' => $ctx['family']['id']], ['%s'], ['%d', '%d'] );
    wp_send_json_success();
}

function sscc_ajax_sscc_make_current() {
    global $wpdb;
    $ctx = sscc_check_family_auth();
    $fid = $ctx['family']['id'];
    $uid = $ctx['uid'];
    $archive_id = intval($_POST['archive_id']);

    $row = $wpdb->get_row($wpdb->prepare("SELECT week_of, archive_json FROM {$wpdb->prefix}sscc_archives WHERE id = %d AND family_id = %d", $archive_id, $fid));
    if (!$row) wp_send_json_error(['message' => 'Archive not found.']);

    $kids = json_decode($row->archive_json, true);

    // Overwrite Live Chart with Archive
    sscc_set_chart_data($fid, 'week_of', $row->week_of, $uid);
    sscc_set_chart_data($fid, 'kids', $kids, $uid);

    // Delete archive so it isn't duplicated
    $wpdb->delete("{$wpdb->prefix}sscc_archives", ['id' => $archive_id, 'family_id' => $fid]);
    wp_send_json_success();
}

// ── Polling & Kids ────────────────────────────────────────────────────────
function sscc_ajax_sscc_poll() {
    global $wpdb;
    $ctx  = sscc_check_family_auth();
    $last = sanitize_text_field( $_POST['last_updated'] ?? '' );
    $row  = $wpdb->get_row( $wpdb->prepare( "SELECT updated_at FROM {$wpdb->prefix}sscc_chart_data WHERE family_id = %d AND data_key = 'kids'", $ctx['family']['id'] ) );
    $changed = $row && ( ! $last || $row->updated_at > $last );
    wp_send_json_success( [ 'changed' => $changed, 'updatedAt' => $row ? $row->updated_at : null ] );
}

function sscc_ajax_sscc_add_kid() {
    $ctx  = sscc_check_family_auth();
    $fid  = $ctx['family']['id'];
    $uid  = $ctx['uid'];
    $name = sanitize_text_field( wp_unslash( $_POST['kid_name'] ?? 'New Kid' ) );
    $defs_row = sscc_get_chart_data( $fid, 'defaults' );
    $defs     = $defs_row ? $defs_row['data'] : sscc_default_categories();
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    $kids     = $kids_row ? $kids_row['data'] : [];
    $kids[]   = [ 'id' => 'kid_' . substr( md5( uniqid() ), 0, 6 ), 'name' => $name, 'categories' => sscc_clone_defaults( $defs ) ];
    sscc_set_chart_data( $fid, 'kids', $kids, $uid );
    wp_send_json_success( [ 'kids' => $kids ] );
}

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

function sscc_ajax_sscc_rename_kid() {
    $ctx    = sscc_check_family_auth();
    $fid    = $ctx['family']['id'];
    $kid_id = sanitize_text_field( $_POST['kid_id'] ?? '' );
    $name   = sanitize_text_field( wp_unslash( $_POST['kid_name'] ?? '' ) );
    $kids_row = sscc_get_chart_data( $fid, 'kids' );
    $kids   = $kids_row ? $kids_row['data'] : [];
    foreach ( $kids as &$k ) { if ( $k['id'] === $kid_id ) { $k['name'] = $name; break; } }
    sscc_set_chart_data( $fid, 'kids', $kids, $ctx['uid'] );
    wp_send_json_success( [ 'kids' => $kids ] );
}

// ── Web App Manifest & Passwords ──────────────────────────────────────────
function sscc_ajax_sscc_manifest() {
    header( 'Content-Type: application/manifest+json' );
    $page = get_page_by_path('chore-chart');
    $start_url = site_url('?sscc_view=app'); 
    echo wp_json_encode([
        "name" => "Super Star Chore Chart", "short_name" => "Chores", "display" => "standalone",
        "start_url" => $start_url, "background_color" => "#111111", "theme_color" => "#111111",
        "icons" => [
            [ "src" => SSCC_URL . "assets/icon.png", "sizes" => "192x192", "type" => "image/png", "purpose" => "any maskable" ],
            [ "src" => SSCC_URL . "assets/icon.png", "sizes" => "512x512", "type" => "image/png", "purpose" => "any maskable" ]
        ]
    ]);
    exit;
}

function sscc_ajax_sscc_change_family_password() {
    global $wpdb;
    $ctx = sscc_check_family_auth();
    $fid = $ctx['family']['id'];
    $new_pass = sanitize_text_field( wp_unslash( $_POST['new_password'] ?? '' ) );
    if ( strlen($new_pass) < 4 ) wp_send_json_error([ 'message' => 'Password must be at least 4 characters.' ]);
    $wpdb->update( "{$wpdb->prefix}sscc_families", [ 'pass_hash' => wp_hash_password($new_pass) ], [ 'id' => $fid ], [ '%s' ], [ '%d' ] );
    wp_send_json_success();
}

function sscc_ajax_sscc_forgot_password() {
    global $wpdb;
    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( ! is_email($email) ) wp_send_json_error([ 'message' => 'Invalid email address.' ]);
    $user = $wpdb->get_row( $wpdb->prepare("SELECT id FROM {$wpdb->prefix}sscc_users WHERE email = %s", $email) );
    if ( $user ) {
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}sscc_users LIKE 'reset_token'");
        if (empty($col)) $wpdb->query("ALTER TABLE {$wpdb->prefix}sscc_users ADD reset_token VARCHAR(64) NULL DEFAULT NULL");
        $token = wp_generate_password(30, false);
        $wpdb->update("{$wpdb->prefix}sscc_users", ['reset_token' => $token], ['id' => $user->id]);
        $base_url = site_url('/super-star-chore-chart/');
        $pages = get_pages();
        foreach ( $pages as $p ) { if ( has_shortcode( $p->post_content, 'chore_chart' ) ) { $base_url = get_permalink( $p->ID ); break; } }
        $separator = strpos($base_url, '?') !== false ? '&' : '?';
        $reset_link = $base_url . $separator . 'reset=' . $token;
        wp_mail($email, "Chore Chart Password Reset", "You requested a password reset for your Chore Chart account.\n\nClick here to securely access your account and set a new password:\n" . $reset_link);
    }
    wp_send_json_success();
}

function sscc_ajax_sscc_save_new_password() {
    global $wpdb;
    $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
    $pass  = sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) );
    if (strlen($pass) < 6) wp_send_json_error(['message' => 'Password must be at least 6 characters.']);
    $user = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sscc_users WHERE reset_token = %s", $token));
    if (!$user) wp_send_json_error(['message' => 'Invalid or expired token. Please request a new password reset.']);
    $wpdb->update("{$wpdb->prefix}sscc_users", ['pass_hash' => wp_hash_password($pass), 'reset_token' => null], ['id' => $user->id]);
    setcookie('sscc_user_auth', $user->id, time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN);
    wp_send_json_success();
}