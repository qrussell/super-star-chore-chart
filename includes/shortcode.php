<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sscc_chore_chart_shortcode() {
    ob_start();

    $user = wp_get_current_user();
    ?>
    <div id="sscc-login-wrapper">
        <?php if ( ! $user || ! $user->ID ): ?>
            <h2>Super Star Chore Chart</h2>
            <p>Please log in to access your family chore chart.</p>

            <div id="ssc-login-form">
                <h3>Login with Password</h3>
                <label>Email<br>
                    <input type="email" id="ssc-email" />
                </label><br>
                <label>Password<br>
                    <input type="password" id="ssc-password" />
                </label><br>
                <button id="ssc-password-login-btn">Log In</button>

                <p style="margin-top:1em;">
                    After a parent logs in, they can send a magic link from inside the chart
                    for easy access on other devices.
                </p>
            </div>
        <?php else: ?>
            <h2>Welcome back, <?php echo esc_html( $user->display_name ); ?></h2>
            <p>You’re logged in. You can send a magic link to yourself for easy access on other devices.</p>
            <button id="ssc-send-magic-link-btn">Send Magic Link to My Email</button>

            <div id="ssc-login-message" style="margin-top:0.5em;"></div>

            <hr>

            <div id="sscc-app">
                <!-- Your SPA chore chart app.js renders into this container -->
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        function ajax(url, data, callback) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onload = function() {
                var res;
                try { res = JSON.parse(xhr.responseText); }
                catch (e) { res = { success: false, data: { message: 'Unexpected response' } }; }
                callback(res);
            };
            var params = [];
            for (var key in data) {
                params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
            }
            xhr.send(params.join('&'));
        }

        var ajaxUrl = "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>";

        var loginBtn = document.getElementById('ssc-password-login-btn');
        if (loginBtn) {
            loginBtn.addEventListener('click', function() {
                var email = document.getElementById('ssc-email').value;
                var password = document.getElementById('ssc-password').value;

                ajax(ajaxUrl, {
                    action: 'ssc_password_login',
                    email: email,
                    password: password
                }, function(res) {
                    alert(res.data && res.data.message ? res.data.message : 'Done');
                    if (res.success) {
                        window.location.reload();
                    }
                });
            });
        }

        var magicBtn = document.getElementById('ssc-send-magic-link-btn');
        if (magicBtn) {
            magicBtn.addEventListener('click', function() {
                ajax(ajaxUrl, {
                    action: 'sscc_send_magic_link'
                }, function(res) {
                    var msgEl = document.getElementById('ssc-login-message');
                    if (msgEl) {
                        msgEl.textContent = res.data && res.data.message ? res.data.message : 'Done';
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Done');
                    }
                });
            });
        }
    })();
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode( 'chore_chart', 'sscc_chore_chart_shortcode' );
