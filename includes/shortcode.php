<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'chore_chart', 'sscc_render_shortcode' );

function sscc_render_shortcode( $atts ) {
    ob_start();

    if ( ! is_user_logged_in() ) {
        $login_url = wp_login_url( get_permalink() );
        $reg_url   = wp_registration_url();
        $opts      = get_option( 'sscc_settings', [] );
        ?>
        <div id="sscc-app" class="sscc-gate">
          <div class="sscc-gate-card">
            <div class="sscc-gate-icon">⭐</div>
            <h2>Super Star Chore Chart</h2>
            <p>Please log in to access your family's chore chart.</p>
            <a href="<?php echo esc_url( $login_url ); ?>" class="sscc-btn">Log In</a>
            <?php if ( ! empty( $opts['allow_registration'] ) ) : ?>
            <p class="sscc-gate-or">— or —</p>
            <a href="<?php echo esc_url( $reg_url ); ?>" class="sscc-btn sscc-btn-outline">Create Account</a>
            <?php endif; ?>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Logged in — the JS takes over from here
    ?>
    <div id="sscc-app">
      <div class="sscc-loading"><span class="sscc-spinner">⭐</span> Loading your family chart…</div>
    </div>
    <?php
    return ob_get_clean();
}
