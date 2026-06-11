<?php
// ============================================================================
// ECOTWIN — REGISTER PAGE
// ============================================================================

require_once __DIR__ . '/config/security.php';
restore_remembered_login();

// If already logged in, redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$token = csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Register — EcoTwin</title>
    <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>">
    <link rel="stylesheet" href="css.auth.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.auth.css')) ?>">
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <img src="ECOTwin_Logo.png" alt="EcoTwin logo" class="login-logo" />
            <h1 class="login-title">Request an EcoTwin Account</h1>
            <p class="login-subtitle">Create an account request — an administrator will review and approve access.</p>

            <form id="registerForm" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>" />

                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" id="r-name" class="form-input" placeholder="Your full name" required maxlength="120" />
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" id="r-email" class="form-input" placeholder="you@school.edu" required maxlength="150" />
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" id="r-pass" class="form-input" placeholder="Min. 8 characters" required maxlength="128" />
                </div>

                <div class="login-error" id="regError" role="alert" style="display:none;"></div>

                <button type="submit" class="btn-login">Request Account</button>
            </form>

            <div style="margin-top:12px;text-align:center;color:var(--brand-muted);font-size:14px;">
                Already have an account? <a href="login.php">Sign in</a>
            </div>

            <div class="login-footer">SPAMAST – IASDC · EcoTwin</div>
        </div>
    </div>

    <script>
        'use strict';
        document.getElementById('registerForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const errEl = document.getElementById('regError');
            errEl.style.display = 'none';
            const fd = new FormData(this);
            fd.append('action', 'register');

            try {
                const res = await fetch('auth_handler.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.success) {
                    alert(data.message || 'Registration submitted.');
                    window.location.href = 'login.php';
                    return;
                }
                errEl.textContent = data.message || 'Registration failed.';
                errEl.style.display = 'flex';
            } catch (err) {
                errEl.textContent = 'A server error occurred.';
                errEl.style.display = 'flex';
            }
        });
    </script>
</body>

</html>