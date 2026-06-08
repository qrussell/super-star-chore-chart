<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sscc_chore_chart_shortcode() {
    ob_start();
    $user = function_exists('sscc_get_auth_user') ? sscc_get_auth_user() : null;
    ?>
    <div id="sscc-login-wrapper">
        <?php if ( ! $user ): ?>
            <div style="max-width:400px; margin: 40px auto; padding: 30px; border: 1px solid #ddd; border-radius: 10px; background:#fff; box-shadow:0 4px 15px rgba(0,0,0,0.05);">
                <h2 style="text-align:center; margin-top:0;">⭐ Chore Chart</h2>
                <p style="text-align:center; color:#555; margin-bottom:20px;">Log in or create a free account with your email to access your family chart.</p>
                
                <div id="ssc-auth-msg" style="color:#dc2626; margin-bottom:10px; font-weight:600; text-align:center; font-size:13px;"></div>
                
                <label style="display:block; margin-bottom:12px; font-weight:600; font-size:13px;">Email Address
                    <input type="email" id="ssc-email" placeholder="you@email.com" style="width:100%; padding:10px; margin-top:6px; border:1px solid #ccc; border-radius:5px;" />
                </label>
                <label style="display:block; margin-bottom:20px; font-weight:600; font-size:13px;">Password
                    <input type="password" id="ssc-password" placeholder="Min 6 characters" style="width:100%; padding:10px; margin-top:6px; border:1px solid #ccc; border-radius:5px;" />
                </label>
                
                <div style="display:flex; gap:10px;">
                    <button id="ssc-login-btn" class="sscc-btn" style="flex:1;">Log In</button>
                    <button id="ssc-register-btn" class="sscc-btn sscc-btn-outline" style="flex:1;">Create Account</button>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align:right; margin-bottom: 10px; font-size: 13px; color:#555;">
                Logged in as <strong><?php echo esc_html($user['email']); ?></strong>. 
                <a href="#" id="ssc-logout-btn" style="color:#d97706; text-decoration:underline;">Sign Out</a>
            </div>
            
            <div id="sscc-app">
                </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        var ajaxUrl = "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>";
        
        function postAuth(action) {
            var email = document.getElementById('ssc-email').value;
            var pass  = document.getElementById('ssc-password').value;
            if(!email || !pass) return alert("Email and password required.");
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onload = function() {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if(res.success) { window.location.reload(); }
                    else { document.getElementById('ssc-auth-msg').textContent = res.data.message; }
                } catch(e) { document.getElementById('ssc-auth-msg').textContent = "Server error."; }
            };
            xhr.send('action=' + action + '&email=' + encodeURIComponent(email) + '&password=' + encodeURIComponent(pass));
        }

        var loginBtn = document.getElementById('ssc-login-btn');
        if (loginBtn) loginBtn.onclick = function() { postAuth('sscc_user_login'); };

        var regBtn = document.getElementById('ssc-register-btn');
        if (regBtn) regBtn.onclick = function() { postAuth('sscc_user_register'); };

        var logoutBtn = document.getElementById('ssc-logout-btn');
        if (logoutBtn) logoutBtn.onclick = function(e) {
            e.preventDefault();
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onload = function() { window.location.reload(); };
            xhr.send('action=sscc_user_logout');
        };
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'chore_chart', 'sscc_chore_chart_shortcode' );