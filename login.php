<?php
// ============================================================================
// ECOTWIN — LOGIN PAGE
// ============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';

// Already logged in → go straight to the right page
if (!empty($_SESSION['user_id'])) {
    $dest = ($_SESSION['user_role'] === 'admin') ? 'admin.php' : 'dashboard.php';
    header("Location: {$dest}");
    exit;
}

$error   = '';
$email   = '';

// ── Handle POST (direct form submit — no AJAX) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please refresh the page and try again.';

    } else {
        $email    = trim($_POST['email']    ?? '');
        $password =      $_POST['password'] ?? '';

        // 2. Basic validation
        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';

        } elseif (is_locked_out($email)) {
            $mins  = (int) ceil(LOCKOUT_SECONDS / 60);
            $error = "Too many failed attempts. Try again in {$mins} minutes.";

        } else {
            // 3. Look up user
            try {
                $stmt = db()->prepare(
                    "SELECT user_id, full_name, email, password_hash, role, status
                       FROM users WHERE email = :email LIMIT 1"
                );
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                error_log('Login DB error: ' . $e->getMessage());
                $error = 'A server error occurred. Please try again.';
                $user  = false;
            }

            if (empty($error)) {
                // 4. Constant-time password check
                $dummy   = '$2y$12$dummyhashusedtopreventimenumeration00000000000000000000';
                $hash    = $user ? $user['password_hash'] : $dummy;
                $passOk  = password_verify($password, $hash);

                if (!$user || !$passOk) {
                    record_failed_attempt($email);
                    $left  = max(0, MAX_LOGIN_ATTEMPTS - get_failed_attempts($email));
                    $error = $left > 0
                        ? "Invalid email or password. {$left} attempt(s) remaining."
                        : 'Account temporarily locked. Try again in 15 minutes.';

                } elseif ($user['status'] !== 'active') {
                    $error = 'This account is inactive or suspended. Contact your administrator.';

                } else {
                    // 5. SUCCESS — rehash if needed
                    if (needs_rehash($user['password_hash'])) {
                        try {
                            db()->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                               ->execute([hash_password($password), $user['user_id']]);
                        } catch (PDOException $e) {
                            error_log('Rehash error: ' . $e->getMessage());
                        }
                    }

                    // 6. Build session
                    session_regenerate_id(true);
                    $_SESSION['user_id']    = $user['user_id'];
                    $_SESSION['user_name']  = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];
                    $_SESSION['last_regen'] = time();

                    // 7. Update last login
                    try {
                        db()->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?")
                           ->execute([$user['user_id']]);
                    } catch (PDOException $e) {
                        error_log('last_login_at error: ' . $e->getMessage());
                    }

                    // 8. Audit log + clear failed attempts
                    log_session_event($user['user_id'], 'login', $email);
                    clear_failed_attempts($email);

                    // 9. Redirect — admin goes to admin.php, everyone else to dashboard.php
                    $dest = ($user['role'] === 'admin') ? 'admin.php' : 'dashboard.php';
                    header("Location: {$dest}");
                    exit;
                }
            }
        }
    }

    // Regenerate CSRF after a failed attempt
    unset($_SESSION['csrf_token']);
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login — EcoTwin</title>
    <link rel="stylesheet" href="css.main.css" />
    <link rel="stylesheet" href="css.auth.css" />
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <img src="ECOTwin_Logo.png" alt="EcoTwin logo" class="login-logo" />
        <h1 class="login-title">EcoTwin: Dual-Greenhouse Research Framework</h1>
        <p class="login-subtitle">Web-based monitoring and control for research greenhouses</p>

        <form class="login-form" id="loginForm" method="POST" action="login.php">
            <input type="hidden" name="action"     value="login" />
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>" />

            <div class="form-group">
                <label class="form-label" for="loginEmail">Email Address</label>
                <input
                    type="email"
                    id="loginEmail"
                    name="email"
                    class="form-input"
                    placeholder="your@spamast.edu"
                    value="<?= e($email) ?>"
                    required
                    autocomplete="email"
                    maxlength="150"
                />
            </div>

            <div class="form-group">
                <label class="form-label" for="loginPassword">Password</label>
                <div class="password-wrap">
                    <input
                        type="password"
                        id="loginPassword"
                        name="password"
                        class="form-input"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                        maxlength="128"
                    />
                    <button type="button" class="password-toggle" id="togglePw" title="Show/hide password">👁</button>
                </div>
            </div>

            <div class="remember-row">
                <label class="checkbox-label">
                    <input type="checkbox" id="rememberMe" />
                    <span>Remember me</span>
                </label>
                <a href="#" class="forgot-link" id="openForgot">Forgot password?</a>
            </div>

            <?php if ($error !== ''): ?>
            <div class="login-error" style="display:flex;" role="alert">
                ⚠️ <?= e($error) ?>
            </div>
            <?php else: ?>
            <div class="login-error" id="loginError" role="alert"></div>
            <?php endif; ?>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="login-footer">
            SPAMAST – IASDC · EcoTwin v1.0
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- FORGOT PASSWORD MODAL                                             -->
<!-- ================================================================ -->
<div class="fp-overlay" id="forgotModal" role="dialog" aria-modal="true">
    <div class="fp-card">

        <div id="fpStep1">
            <div class="fp-icon">🔑</div>
            <h2 class="fp-title">Reset Password</h2>
            <p class="fp-subtitle">Enter your registered phone number and we'll send you a reset code via SMS.</p>

            <div class="form-group">
                <label class="form-label" for="fpPhone">Phone Number</label>
                <input type="tel" id="fpPhone" class="form-input" placeholder="09XXXXXXXXX" maxlength="15" />
            </div>
            <div class="fp-error" id="fpError" role="alert"></div>

            <div class="fp-actions">
                <button class="btn-fp-secondary" id="fpCancel">Cancel</button>
                <button class="btn-fp-primary"   id="fpSubmit">Send Reset Code</button>
            </div>
        </div>

        <div id="fpStep2" style="display:none;">
            <div class="fp-icon fp-icon-success">✅</div>
            <h2 class="fp-title">Check Your Phone</h2>
            <p class="fp-subtitle">A password reset code has been sent to:</p>
            <div class="fp-email-highlight" id="fpPhoneDisplay"></div>
            <p class="fp-note">
                Didn't receive it? Wait a moment or
                <a href="#" id="fpRetry" class="fp-resend-link">try a different number</a>.
            </p>
            <div class="fp-actions" style="justify-content:center;">
                <button class="btn-fp-primary" id="fpDone">Done</button>
            </div>
        </div>

    </div>
</div>

<script>
'use strict';

// ── Password toggle ───────────────────────────────────────────────────────────
document.getElementById('togglePw').addEventListener('click', function () {
    const input  = document.getElementById('loginPassword');
    const hidden = input.type === 'password';
    input.type       = hidden ? 'text' : 'password';
    this.textContent = hidden ? '🙈' : '👁';
});

// ── Forgot password modal ─────────────────────────────────────────────────────
const modal   = document.getElementById('forgotModal');
const step1   = document.getElementById('fpStep1');
const step2   = document.getElementById('fpStep2');
const fpError = document.getElementById('fpError');

function openModal() {
    fpError.style.display = 'none';
    step1.style.display   = 'block';
    step2.style.display   = 'none';
    modal.classList.add('active');
    document.getElementById('fpPhone').focus();
}

function closeModal() {
    modal.classList.remove('active');
    setTimeout(() => {
        document.getElementById('fpPhone').value = '';
        fpError.style.display = 'none';
        step1.style.display   = 'block';
        step2.style.display   = 'none';
    }, 300);
}

document.getElementById('openForgot').addEventListener('click', e => { e.preventDefault(); openModal(); });
document.getElementById('fpCancel').addEventListener('click', closeModal);
document.getElementById('fpDone').addEventListener('click',   closeModal);
document.getElementById('fpRetry').addEventListener('click',  e => {
    e.preventDefault();
    step1.style.display   = 'block';
    step2.style.display   = 'none';
    fpError.style.display = 'none';
});
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── Forgot submit (now using phone number) ─────────────────────────────
document.getElementById('fpSubmit').addEventListener('click', async () => {
    const phone = document.getElementById('fpPhone').value.trim();
    fpError.style.display = 'none';

    // Basic PH phone validation: starts with 09, 11 digits
    if (!/^09\d{9}$/.test(phone)) {
        fpError.textContent   = '⚠️ Please enter a valid phone number (e.g., 09XXXXXXXXX).';
        fpError.style.display = 'flex';
        return;
    }

    const btn = document.getElementById('fpSubmit');
    btn.disabled     = true;
    btn.textContent  = '⏳ Sending…';

    try {
        const fd = new FormData();
        fd.append('action',     'forgot_phone');
        fd.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
        fd.append('phone',      phone);
        await fetch('auth_handler.php', { method: 'POST', body: fd });
    } catch (_) { /* silently ignore — always show step 2 */ }

    document.getElementById('fpPhoneDisplay').textContent = phone;
    step1.style.display = 'none';
    step2.style.display = 'block';
    btn.disabled    = false;
    btn.textContent = 'Send Reset Code';
});
</script>
</body>
</html>
