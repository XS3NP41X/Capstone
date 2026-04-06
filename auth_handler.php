<?php
// ============================================================================
// ECOTWIN — AUTH HANDLER
// ============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'login':        handle_login();        break;
    case 'logout':       handle_logout();       break;
    case 'forgot_email': handle_forgot_email(); break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}

// ─────────────────────────────────────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────────────────────────────────────
function handle_login(): void
{
    // 1. CSRF check
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page and try again.']);
        return;
    }

    $email    = trim($_POST['email']    ?? '');
    $password =      $_POST['password'] ?? '';

    // 2. Input validation
    if ($email === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        return;
    }

    // 3. Lockout check
    if (is_locked_out($email)) {
        $minutes = (int) ceil(LOCKOUT_SECONDS / 60);
        echo json_encode([
            'success' => false,
            'message' => "Too many failed attempts. Try again in {$minutes} minutes.",
        ]);
        return;
    }

    // 4. Fetch user from DB
    try {
        $pdo  = db();
        $stmt = $pdo->prepare(
            "SELECT user_id, full_name, email, password_hash, role, status
               FROM users
              WHERE email = :email
              LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Login DB error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
        return;
    }

    // 5. Verify password — constant-time even when user not found
    $dummyHash = '$2y$12$dummyhashusedtopreventimenumeration000000000000000000000';
    $hashToCheck = $user ? $user['password_hash'] : $dummyHash;
    $passwordOk  = password_verify($password, $hashToCheck);

    if (!$user || !$passwordOk) {
        record_failed_attempt($email);
        $attempts   = get_failed_attempts($email);
        $remaining  = max(0, MAX_LOGIN_ATTEMPTS - $attempts);

        if ($remaining === 0) {
            echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Account locked for 15 minutes.']);
        } else {
            echo json_encode(['success' => false, 'message' => "Invalid email or password. {$remaining} attempt(s) remaining."]);
        }
        return;
    }

    // 6. Account status
    if ($user['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'This account is inactive or suspended. Contact your administrator.']);
        return;
    }

    // 7. Rehash if cost factor changed
    if (needs_rehash($user['password_hash'])) {
        try {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                ->execute([hash_password($password), $user['user_id']]);
        } catch (PDOException $e) {
            error_log('Rehash error: ' . $e->getMessage());
        }
    }

    // 8. Build authenticated session
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['user_id'];
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['last_regen'] = time();

    // 9. Update last login timestamp
    try {
        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?")
            ->execute([$user['user_id']]);
    } catch (PDOException $e) {
        error_log('last_login_at update error: ' . $e->getMessage());
    }

    // 10. Audit log (safe — real user_id only)
    log_session_event($user['user_id'], 'login', $email);

    // 11. Clear failed attempts on success
    clear_failed_attempts($email);

    // 12. Redirect based on role
    // Admin goes to admin.html, everyone else to dashboard.html
    $redirect = ($user['role'] === 'admin') ? 'admin.html' : 'dashboard.html';

    echo json_encode(['success' => true, 'redirect' => $redirect]);
}

// ─────────────────────────────────────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────────────────────────────────────
function handle_logout(): void
{
    if (!empty($_SESSION['user_id'])) {
        log_session_event((int) $_SESSION['user_id'], 'logout');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();

    echo json_encode(['success' => true, 'redirect' => 'login.php']);
}

// ─────────────────────────────────────────────────────────────────────────────
// FORGOT PASSWORD — check email and create token
// ─────────────────────────────────────────────────────────────────────────────
function handle_forgot_email(): void
{
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        return;
    }

    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        return;
    }

    try {
        $pdo  = db();
        $stmt = $pdo->prepare(
            "SELECT user_id FROM users WHERE email = :email AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Forgot-email DB error: ' . $e->getMessage());
        echo json_encode(['success' => true]); // always succeed to prevent enumeration
        return;
    }

    if ($user) {
        $token     = bin2hex(random_bytes(RESET_TOKEN_BYTES));
        $expiresAt = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL);
        try {
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")
                ->execute([$user['user_id']]);
            $pdo->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)"
            )->execute([$user['user_id'], $token, $expiresAt]);
        } catch (PDOException $e) {
            error_log('Password reset token error: ' . $e->getMessage());
        }
        // In production: send this token by email
        error_log("Reset token for {$email}: {$token}");
    }

    // Always return success to prevent email enumeration
    echo json_encode(['success' => true]);
}
