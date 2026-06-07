<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_options_page( 'Chore Chart Settings', 'Chore Chart', 'manage_options', 'sscc-settings', 'sscc_settings_page' );
} );

add_action( 'admin_init', function() {
    register_setting( 'sscc_group', 'sscc_settings', 'sscc_sanitize_opts' );
    add_settings_section( 's1', 'General', '__return_false', 'sscc-settings' );
    add_settings_field( 'poll_interval',      'Poll interval (seconds)', 'sscc_f_poll',   'sscc-settings', 's1' );
    add_settings_field( 'allow_registration', 'Show Register link',      'sscc_f_reg',    'sscc-settings', 's1' );
    add_settings_field( 'max_family_members', 'Max members per family',  'sscc_f_max',    'sscc-settings', 's1' );
} );

function sscc_sanitize_opts( $i ) {
    return [
        'poll_interval'      => max( 5, intval( $i['poll_interval']      ?? 15 ) ),
        'allow_registration' => (int) ! empty( $i['allow_registration'] ),
        'max_family_members' => max( 2, intval( $i['max_family_members'] ?? 20 ) ),
    ];
}

function sscc_opt( $k, $d = null ) { $o = get_option( 'sscc_settings', [] ); return $o[$k] ?? $d; }

function sscc_f_poll() {
    echo '<input type="number" name="sscc_settings[poll_interval]" value="' . esc_attr( sscc_opt('poll_interval', 15) ) . '" min="5" max="120" class="small-text"> seconds';
    echo '<p class="description">How often browsers check for updates from other family members.</p>';
}
function sscc_f_reg() {
    echo '<label><input type="checkbox" name="sscc_settings[allow_registration]" value="1"' . checked( 1, sscc_opt('allow_registration', 1), false ) . '> Show "Create Account" link on login prompt</label>';
}
function sscc_f_max() {
    echo '<input type="number" name="sscc_settings[max_family_members]" value="' . esc_attr( sscc_opt('max_family_members', 20) ) . '" min="2" max="100" class="small-text"> members';
}

function sscc_settings_page() {
    if ( ! current_user_can('manage_options') ) return;
    global $wpdb;
    $page = get_page_by_path('chore-chart');
    $families = $wpdb->get_results( "SELECT f.family_name, COUNT(m.id) as members FROM {$wpdb->prefix}sscc_families f LEFT JOIN {$wpdb->prefix}sscc_members m ON m.family_id=f.id GROUP BY f.id ORDER BY f.family_name" );
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
        <?php settings_fields('sscc_group'); do_settings_sections('sscc-settings'); submit_button(); ?>
      </form>

      <h2>Active Families (<?php echo count($families); ?>)</h2>
      <?php if ($families): ?>
      <table class="widefat" style="max-width:500px;">
        <thead><tr><th>Family Name</th><th>Members</th></tr></thead>
        <tbody>
        <?php foreach ($families as $f): ?>
          <tr><td><?php echo esc_html($f->family_name); ?></td><td><?php echo (int)$f->members; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <p>No families created yet.</p>
      <?php endif; ?>
    </div>
    <?php
}
