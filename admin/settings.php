<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_options_page( 'Chore Chart Settings', 'Chore Chart', 'manage_options', 'sscc-settings', 'sscc_settings_page' );
} );

add_action( 'admin_init', function() {
    register_setting( 'sscc_group', 'sscc_settings', 'sscc_sanitize_opts' );
    add_settings_section( 's1', 'General Configuration', '__return_false', 'sscc-settings' );
    add_settings_field( 'poll_interval', 'Poll interval (seconds)', 'sscc_f_poll', 'sscc-settings', 's1' );
} );

function sscc_sanitize_opts( $i ) {
    return [ 'poll_interval' => max( 5, intval( $i['poll_interval'] ?? 15 ) ) ];
}

function sscc_opt( $k, $d = null ) { $o = get_option( 'sscc_settings', [] ); return $o[$k] ?? $d; }

function sscc_f_poll() {
    echo '<input type="number" name="sscc_settings[poll_interval]" value="' . esc_attr( sscc_opt('poll_interval', 15) ) . '" min="5" max="120" class="small-text"> seconds';
    echo '<p class="description">How often browsers check for updates from other family members.</p>';
}

function sscc_settings_page() {
    if ( ! current_user_can('manage_options') ) return;
    global $wpdb;

    // ── Process Admin Management Actions ──────────────────────────────────────────
    if ( isset( $_POST['sscc_admin_action'] ) && check_admin_referer( 'sscc_admin_action_nonce' ) ) {
        $action = sanitize_text_field($_POST['sscc_admin_action']);
        
        // Family Actions
        if ( $action === 'delete_family' ) {
            $fid = intval($_POST['family_id']);
            $wpdb->delete("{$wpdb->prefix}sscc_families",   ['id' => $fid]);
            $wpdb->delete("{$wpdb->prefix}sscc_members",    ['family_id' => $fid]);
            $wpdb->delete("{$wpdb->prefix}sscc_chart_data", ['family_id' => $fid]);
            $wpdb->delete("{$wpdb->prefix}sscc_archives",   ['family_id' => $fid]);
            echo '<div class="notice notice-success is-dismissible"><p>Family and all associated data deleted successfully.</p></div>';
        }
        elseif ( $action === 'add_family' ) {
            $fname = sanitize_text_field($_POST['family_name']);
            $fpass = sanitize_text_field($_POST['family_pass']);
            if ($fname && strlen($fpass) >= 4) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sscc_families WHERE family_name = %s", $fname));
                if ($exists) {
                    echo '<div class="notice notice-error is-dismissible"><p>That family name already exists.</p></div>';
                } else {
                    $wpdb->insert("{$wpdb->prefix}sscc_families", [
                        'family_name' => $fname,
                        'pass_hash'   => wp_hash_password($fpass),
                        'created_by'  => 0 // 0 indicates it was created by a WordPress Admin
                    ]);
                    $fid = $wpdb->insert_id;
                    
                    // Seed initial chart data
                    $monday = sscc_monday_of_week();
                    $kids   = [ [ 'id' => 'kid_' . substr( md5( time() . $fid ), 0, 6 ), 'name' => 'Kid 1', 'categories' => sscc_default_categories() ] ];
                    sscc_set_chart_data( $fid, 'week_of',  $monday, 0 );
                    sscc_set_chart_data( $fid, 'kids',     $kids,   0 );
                    sscc_set_chart_data( $fid, 'defaults', sscc_default_categories(), 0 );
                    echo '<div class="notice notice-success is-dismissible"><p>Family created successfully.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Family name is required and password must be at least 4 characters.</p></div>';
            }
        }
        
        // User Actions
        elseif ( $action === 'add_user' ) {
            $email = sanitize_email($_POST['user_email']);
            $pass  = wp_unslash($_POST['user_pass']);
            if ( is_email($email) && strlen($pass) >= 6 ) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sscc_users WHERE email = %s", $email));
                if ($exists) {
                    echo '<div class="notice notice-error is-dismissible"><p>That email is already registered.</p></div>';
                } else {
                    $wpdb->insert("{$wpdb->prefix}sscc_users", [
                        'email'     => $email,
                        'pass_hash' => wp_hash_password($pass)
                    ], ['%s', '%s']);
                    echo '<div class="notice notice-success is-dismissible"><p>App User created successfully.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>A valid email is required, and the password must be at least 6 characters.</p></div>';
            }
        }
        elseif ( $action === 'delete_user' ) {
            $uid = intval($_POST['user_id']);
            $wpdb->delete("{$wpdb->prefix}sscc_users", ['id' => $uid]);
            $wpdb->delete("{$wpdb->prefix}sscc_members", ['user_id' => $uid]);
            echo '<div class="notice notice-success is-dismissible"><p>User deleted successfully.</p></div>';
        }
        
        // Assignment Actions
        elseif ( $action === 'remove_user' ) {
            $fid = intval($_POST['family_id']);
            $uid = intval($_POST['user_id']);
            $wpdb->delete("{$wpdb->prefix}sscc_members", ['family_id' => $fid, 'user_id' => $uid]);
            echo '<div class="notice notice-success is-dismissible"><p>User removed from family.</p></div>';
        }
        elseif ( $action === 'assign_user' ) {
            $fid = intval($_POST['family_id']);
            $uid = intval($_POST['user_id']);
            if ($fid && $uid) {
                // Ensure the isolated app user is only assigned to one family
                $wpdb->delete("{$wpdb->prefix}sscc_members", ['user_id' => $uid]); 
                $wpdb->insert("{$wpdb->prefix}sscc_members", [
                    'family_id' => $fid,
                    'user_id' => $uid,
                    'role' => 'member'
                ]);
                echo '<div class="notice notice-success is-dismissible"><p>App User successfully assigned to family.</p></div>';
            }
        }
    }

    // ── Fetch Display Data ────────────────────────────────────────────────────────
    $page = get_page_by_path('chore-chart');
    $families = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sscc_families ORDER BY family_name" );
    
    // Group members by their family ID by querying the custom sscc_users table
    $members_by_family = [];
    $members_query = $wpdb->get_results("SELECT m.family_id, m.user_id, u.email FROM {$wpdb->prefix}sscc_members m JOIN {$wpdb->prefix}sscc_users u ON m.user_id = u.id");
    foreach ($members_query as $mq) {
        $members_by_family[$mq->family_id][] = $mq;
    }

    // Fetch Custom App Users
    $all_app_users = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sscc_users ORDER BY created_at DESC");
    ?>

    <div class="wrap">
      <h1>⭐ Super Star Chore Chart v<?php echo SSCC_VERSION; ?></h1>
      
      <?php if ($page): ?>
      <div class="notice notice-success inline"><p>
        Chore Chart page: <a href="<?php echo esc_url(get_permalink($page->ID)); ?>" target="_blank">View →</a>
        &nbsp;|&nbsp; <a href="<?php echo esc_url(get_edit_post_link($page->ID)); ?>">Edit</a>
        &nbsp;|&nbsp; Shortcode: <code>[chore_chart]</code>
      </p></div>
      <?php endif; ?>

      <form method="post" action="options.php" style="max-width:640px;margin-top:20px;">
        <?php settings_fields('sscc_group'); do_settings_sections('sscc-settings'); submit_button('Save General Settings'); ?>
      </form>

      <hr>

      <h2>Manage Families & Custom Users</h2>

      <div style="display:flex; gap: 20px; flex-wrap: wrap;">
          
          <div style="flex: 1; min-width: 300px; max-width: 400px;">
              
              <div class="card" style="max-width: 100%; margin-top:0;">
                  <h3>1. Create New Family</h3>
                  <form method="post">
                      <?php wp_nonce_field( 'sscc_admin_action_nonce' ); ?>
                      <input type="hidden" name="sscc_admin_action" value="add_family">
                      <p><input type="text" name="family_name" placeholder="Family Name" required class="regular-text" style="width:100%"></p>
                      <p><input type="password" name="family_pass" placeholder="Password (Min 4 chars)" required class="regular-text" style="width:100%"></p>
                      <p><button type="submit" class="button button-primary">Create Family</button></p>
                  </form>
              </div>

              <div class="card" style="max-width: 100%;">
                  <h3>2. Create New App User</h3>
                  <form method="post">
                      <?php wp_nonce_field( 'sscc_admin_action_nonce' ); ?>
                      <input type="hidden" name="sscc_admin_action" value="add_user">
                      <p><input type="email" name="user_email" placeholder="User Email Address" required class="regular-text" style="width:100%"></p>
                      <p><input type="password" name="user_pass" placeholder="Password (Min 6 chars)" required class="regular-text" style="width:100%"></p>
                      <p><button type="submit" class="button button-primary">Create User</button></p>
                  </form>
              </div>

              <div class="card" style="max-width: 100%;">
                  <h3>3. Assign App User to Family</h3>
                  <form method="post">
                      <?php wp_nonce_field( 'sscc_admin_action_nonce' ); ?>
                      <input type="hidden" name="sscc_admin_action" value="assign_user">
                      <p>
                          <select name="user_id" required style="width: 100%;">
                              <option value="">-- Select Registered App User --</option>
                              <?php foreach ($all_app_users as $u): ?>
                                  <option value="<?php echo esc_attr($u->id); ?>"><?php echo esc_html($u->email); ?></option>
                              <?php endforeach; ?>
                          </select>
                      </p>
                      <p>
                          <select name="family_id" required style="width: 100%;">
                              <option value="">-- Select Family --</option>
                              <?php foreach ($families as $f): ?>
                                  <option value="<?php echo esc_attr($f->id); ?>"><?php echo esc_html($f->family_name); ?></option>
                              <?php endforeach; ?>
                          </select>
                      </p>
                      <p class="description">Note: Users can only belong to one family. Reassigning them will overwrite their previous affiliation.</p>
                      <p><button type="submit" class="button">Assign User</button></p>
                  </form>
              </div>

          </div>

          <div style="flex: 2; min-width: 400px;">
              
              <h3>Active Families</h3>
              <table class="wp-list-table widefat fixed striped" style="margin-bottom: 30px;">
                  <thead>
                      <tr>
                          <th>Family Name</th>
                          <th>Registered App Users</th>
                          <th style="width: 110px;">Actions</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if ($families): ?>
                          <?php foreach ($families as $f): ?>
                              <tr>
                                  <td>
                                      <strong><?php echo esc_html($f->family_name); ?></strong><br>
                                      <span class="description">Created: <?php echo esc_html(date('M j, Y', strtotime($f->created_at))); ?></span>
                                  </td>
                                  <td>
                                      <?php if (isset($members_by_family[$f->id])): ?>
                                          <ul style="margin:0; list-style-type: disc; padding-left: 15px;">
                                              <?php foreach ($members_by_family[$f->id] as $member): ?>
                                                  <li style="margin-bottom: 4px;">
                                                      <?php echo esc_html($member->email); ?> 
                                                      <form method="post" style="display:inline;" onsubmit="return confirm('Remove user from family?');">
                                                          <?php wp_nonce_field( 'sscc_admin_action_nonce' ); ?>
                                                          <input type="hidden" name="sscc_admin_action" value="remove_user">
                                                          <input type="hidden" name="family_id" value="<?php echo esc_attr($f->id); ?>">
                                                          <input type="hidden" name="user_id" value="<?php echo esc_attr($member->user_id); ?>">
                                                          <button type="submit" class="button-link" style="color:#a00; font-size:12px;">(Remove)</button>
                                                      </form>
                                                  </li>
                                              <?php endforeach; ?>
                                          </ul>
                                      <?php else: ?>
                                          <em class="description">No members assigned yet.</em>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <form method="post" onsubmit="return confirm('WARNING: This will permanently delete the family, all chart data, and task history. Proceed?');">
                                          <?php wp_nonce_field( 'sscc_admin_action_nonce' ); ?>
                                          <input type="hidden" name="sscc_admin_action" value="delete_family">
                                          <input type="hidden" name="family_id" value="<?php echo esc_attr($f->id); ?>">
                                          <button type="submit" class="button button-link-delete" style="color: #d63638;">Delete Family</button>
                                      </form>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      <?php else: ?>
                          <tr><td colspan="3">No families created yet.</td></tr>
                      <?php endif; ?>
                  </tbody>
              </table>

              <h3>All Registered App Users</h3>
              <table class="wp-list-table widefat fixed striped">
                  <thead>
                      <tr>
                          <th>Email Address</th>
                          <th>Registration Date</th>
                          <th style="width: 110px;">Actions</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php if ($all_app_users): ?>
                          <?php foreach ($all_app_users as $u): ?>
                              <tr>
                                  <td><strong><?php echo esc_html($u->email); ?></strong></td>
                                  <td><?php echo esc_html(date('M j, Y', strtotime($u->created_at))); ?></td>
                                  <td>
                                      <form method="post" onsubmit="return confirm('WARNING: This will permanently delete this app user. Proceed?');">
                                          <?php wp_nonce_field( 'sscc_admin_action_nonce' ); ?>
                                          <input type="hidden" name="sscc_admin_action" value="delete_user">
                                          <input type="hidden" name="user_id" value="<?php echo esc_attr($u->id); ?>">
                                          <button type="submit" class="button button-link-delete" style="color: #d63638;">Delete User</button>
                                      </form>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      <?php else: ?>
                          <tr><td colspan="3">No app users registered yet.</td></tr>
                      <?php endif; ?>
                  </tbody>
              </table>

          </div>
          
      </div>
    </div>
    <?php
}