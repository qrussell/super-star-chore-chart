<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function sscc_render_shortcode() {
    ob_start();
    // Check if the user has our custom app cookie
    $user = function_exists('sscc_get_auth_user') ? sscc_get_auth_user() : null;
    ?>
    <div class="sscc-app-container" style="
        width: 100%; 
        max-width: fit-content; 
        margin: 40px auto; 
        border-radius: 16px; 
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2); 
        background: #ffffff; 
        overflow: hidden;">
        
        <div id="ssc-reset-ui" style="display:none; max-width:400px; margin: 40px auto; padding: 30px; border: 1px solid #ddd; border-radius: 10px; background:#fff; box-shadow:0 4px 15px rgba(0,0,0,0.05);">
            <h2 style="text-align:center; margin-top:0;">Set New Password</h2>
            <p style="text-align:center; color:#555; margin-bottom:20px;">Enter a new password for your account.</p>
            
            <div id="ssc-reset-msg" style="color:#dc2626; margin-bottom:10px; font-weight:600; text-align:center; font-size:13px;"></div>
            
            <label style="display:block; margin-bottom:20px; font-weight:600; font-size:13px;">New Password
                <input type="password" id="ssc-new-password" placeholder="Min 6 characters" style="width:100%; padding:10px; margin-top:6px; border:1px solid #ccc; border-radius:5px;" />
            </label>
            
            <button id="ssc-save-pw-btn" class="sscc-btn" style="width:100%; cursor:pointer; background:#006d77; color:#fff; border:none; padding:10px; border-radius:5px; font-weight:bold;">Save & Log In</button>
        </div>

        <div id="ssc-standard-ui">
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
                        <button id="ssc-login-btn" class="sscc-btn" style="flex:1; cursor:pointer;">Log In</button>
                        <button id="ssc-register-btn" class="sscc-btn sscc-btn-outline" style="flex:1; cursor:pointer;">Create Account</button>
                    </div>
                    
                    <a href="#" id="ssc-forgot-btn" style="display:block; text-align:center; font-size:12px; margin-top:15px; color:#2ea3f2; text-decoration:none;">Forgot Password?</a>
                </div>
            <?php else: ?>
                <div style="text-align:right; padding: 10px 20px; font-size: 13px; color:#555; background:#f9f9f9; border-bottom:1px solid #eee;">
                    Logged in as <strong><?php echo esc_html($user['email']); ?></strong>. 
                    <a href="#" id="ssc-logout-btn" style="color:#d97706; text-decoration:underline; margin-left:10px;">Sign Out</a>
                </div>
                
                <div id="sscc-app"></div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var ajaxUrl = "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>";
            
            // --- Cache-Proof URL Interceptor for Reset Links ---
            var urlParams = new URLSearchParams(window.location.search);
            var resetToken = urlParams.get('reset');
            
            if (resetToken) {
                // Hide the standard app/login and show the reset form
                document.getElementById('ssc-standard-ui').style.display = 'none';
                document.getElementById('ssc-reset-ui').style.display = 'block';
                
                var saveBtn = document.getElementById('ssc-save-pw-btn');
                if (saveBtn) {
                    saveBtn.onclick = function(e) {
                        e.preventDefault();
                        var pass = document.getElementById('ssc-new-password').value;
                        if(pass.length < 6) {
                            document.getElementById('ssc-reset-msg').textContent = "Password must be at least 6 characters.";
                            return;
                        }
                        
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', ajaxUrl, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                        xhr.onload = function() {
                            try {
                                var res = JSON.parse(xhr.responseText);
                                if(res.success) { 
                                    // Strip the token from URL and reload main page to log them into the app
                                    window.location.href = window.location.pathname;
                                } else { 
                                    document.getElementById('ssc-reset-msg').textContent = res.data.message; 
                                }
                            } catch(e) { document.getElementById('ssc-reset-msg').textContent = "Server error."; }
                        };
                        xhr.send('action=sscc_save_new_password&token=' + encodeURIComponent(resetToken) + '&password=' + encodeURIComponent(pass));
                    };
                }
            } else {
                // --- Standard Login/Forgot Logic ---
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
                            else { 
                                document.getElementById('ssc-auth-msg').style.color = '#dc2626';
                                document.getElementById('ssc-auth-msg').textContent = res.data.message; 
                            }
                        } catch(e) { document.getElementById('ssc-auth-msg').textContent = "Server error."; }
                    };
                    xhr.send('action=' + action + '&email=' + encodeURIComponent(email) + '&password=' + encodeURIComponent(pass));
                }

                var loginBtn = document.getElementById('ssc-login-btn');
                if (loginBtn) loginBtn.onclick = function() { postAuth('sscc_user_login'); };

                var regBtn = document.getElementById('ssc-register-btn');
                if (regBtn) regBtn.onclick = function() { postAuth('sscc_user_register'); };

                var forgotBtn = document.getElementById('ssc-forgot-btn');
                if (forgotBtn) {
                    forgotBtn.onclick = function(e) {
                        e.preventDefault();
                        var email = prompt("Enter your email address to receive a password reset link:");
                        if (!email) return;
                        
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', ajaxUrl, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                        xhr.onload = function() {
                            try {
                                var res = JSON.parse(xhr.responseText);
                                if(res.success) { 
                                    document.getElementById('ssc-auth-msg').style.color = '#16a34a'; 
                                    document.getElementById('ssc-auth-msg').textContent = "Reset link sent! Check your email."; 
                                }
                                else { 
                                    document.getElementById('ssc-auth-msg').style.color = '#dc2626';
                                    document.getElementById('ssc-auth-msg').textContent = res.data.message; 
                                }
                            } catch(e) { 
                                document.getElementById('ssc-auth-msg').style.color = '#dc2626';
                                document.getElementById('ssc-auth-msg').textContent = "Server error."; 
                            }
                        };
                        xhr.send('action=sscc_forgot_password&email=' + encodeURIComponent(email));
                    };
                }

                var logoutBtn = document.getElementById('ssc-logout-btn');
                if (logoutBtn) logoutBtn.onclick = function(e) {
                    e.preventDefault();
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                    xhr.onload = function() { window.location.reload(); };
                    xhr.send('action=sscc_user_logout');
                };
            }
        })();
        </script>
        
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('chore_chart', 'sscc_render_shortcode');