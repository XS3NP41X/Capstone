<?php
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);
// ============================================================================
// ECOTWIN — SECURITY HELPERS
// ============================================================================

// ── Session hardening ────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly',  1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode',  1);
    ini_set('session.gc_maxlifetime',   3600);
    session_start();
}

// ── Constants ─────────────────────────────────────────────────────────────────
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS',    900);   // 15 minutes
define('BCRYPT_COST',        12);
define('RESET_TOKEN_BYTES',  32);
define('RESET_TOKEN_TTL',    3600);  // 1 hour
define('REMEMBER_ME_COOKIE', 'ecotwin_remember');
define('REMEMBER_ME_DAYS',   30);

// ── CSRF ─────────────────────────────────────────────────────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Rate limiting via login_attempts table ────────────────────────────────────
// Uses its own lightweight table to avoid the FK constraint on session_log.
function ensure_attempts_table(): void
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `email`        VARCHAR(150) NOT NULL,
                `ip_address`   VARCHAR(45)  NOT NULL,
                `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_email_time` (`email`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        error_log('ensure_attempts_table: ' . $e->getMessage());
    }
}

function get_failed_attempts(string $email): int
{
    try {
        ensure_attempts_table();
        $cutoff = date('Y-m-d H:i:s', time() - LOCKOUT_SECONDS);
        $stmt   = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE email = :email AND attempted_at >= :cutoff"
        );
        $stmt->execute([':email' => $email, ':cutoff' => $cutoff]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('get_failed_attempts: ' . $e->getMessage());
        return 0;
    }
}

function is_locked_out(string $email): bool
{
    return get_failed_attempts($email) >= MAX_LOGIN_ATTEMPTS;
}

function record_failed_attempt(string $email): void
{
    try {
        ensure_attempts_table();
        db()->prepare(
            "INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)"
        )->execute([
            ':email' => $email,
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } catch (PDOException $e) {
        error_log('record_failed_attempt: ' . $e->getMessage());
    }
}

function clear_failed_attempts(string $email): void
{
    try {
        ensure_attempts_table();
        db()->prepare("DELETE FROM login_attempts WHERE email = :email")
            ->execute([':email' => $email]);
    } catch (PDOException $e) {
        error_log('clear_failed_attempts: ' . $e->getMessage());
    }
}

function ensure_remember_tokens_table(): void
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `remember_tokens` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `selector` CHAR(24) NOT NULL,
                `validator_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_selector` (`selector`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_expires_at` (`expires_at`),
                CONSTRAINT `fk_remember_tokens_user`
                    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        error_log('ensure_remember_tokens_table: ' . $e->getMessage());
    }
}

function build_auth_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['user_id'];
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['last_regen'] = time();
}

function remember_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clear_remember_me_cookie(): void
{
    setcookie(REMEMBER_ME_COOKIE, '', remember_cookie_options(time() - 3600));
    unset($_COOKIE[REMEMBER_ME_COOKIE]);
}

function revoke_remember_me_token(?string $cookieValue = null): void
{
    $cookieValue ??= $_COOKIE[REMEMBER_ME_COOKIE] ?? '';
    if ($cookieValue === '' || strpos($cookieValue, ':') === false) {
        clear_remember_me_cookie();
        return;
    }

    [$selector] = explode(':', $cookieValue, 2);

    try {
        ensure_remember_tokens_table();
        db()->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$selector]);
    } catch (PDOException $e) {
        error_log('revoke_remember_me_token: ' . $e->getMessage());
    }

    clear_remember_me_cookie();
}

function create_remember_me_token(int $userId): void
{
    try {
        ensure_remember_tokens_table();
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $validator);
        $expiresAt = time() + (REMEMBER_ME_DAYS * 86400);
        $expiresDb = date('Y-m-d H:i:s', $expiresAt);

        db()->prepare("DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()")
            ->execute([$userId]);

        db()->prepare(
            "INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at)
             VALUES (?, ?, ?, ?)"
        )->execute([$userId, $selector, $hash, $expiresDb]);

        $cookieValue = $selector . ':' . $validator;
        setcookie(REMEMBER_ME_COOKIE, $cookieValue, remember_cookie_options($expiresAt));
        $_COOKIE[REMEMBER_ME_COOKIE] = $cookieValue;
    } catch (Throwable $e) {
        error_log('create_remember_me_token: ' . $e->getMessage());
        clear_remember_me_cookie();
    }
}

function restore_remembered_login(): bool
{
    if (!empty($_SESSION['user_id'])) {
        return true;
    }

    $cookie = $_COOKIE[REMEMBER_ME_COOKIE] ?? '';
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') {
        revoke_remember_me_token($cookie);
        return false;
    }

    try {
        ensure_remember_tokens_table();
        db()->prepare("DELETE FROM remember_tokens WHERE expires_at < NOW()")->execute();

        $stmt = db()->prepare(
            "SELECT rt.user_id, rt.validator_hash,
                    u.full_name, u.email, u.role, u.status
               FROM remember_tokens rt
               JOIN users u ON u.user_id = rt.user_id
              WHERE rt.selector = ?
              LIMIT 1"
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            revoke_remember_me_token($cookie);
            return false;
        }

        if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
            revoke_remember_me_token($cookie);
            return false;
        }

        build_auth_session($row);
        create_remember_me_token((int) $row['user_id']);
        log_session_event((int) $row['user_id'], 'remember_login');
        return true;
    } catch (Throwable $e) {
        error_log('restore_remembered_login: ' . $e->getMessage());
        clear_remember_me_cookie();
        return false;
    }
}

// ── Session event logging ─────────────────────────────────────────────────────
// Only called with a real user_id (> 0) to satisfy the FK on session_log.
function log_session_event(int $userId, string $action, string $detail = ''): void
{
    if ($userId <= 0) return;

    try {
        db()->prepare(
            "INSERT INTO session_log (user_id, ip_address, user_agent, action, detail, logged_at)
             VALUES (:uid, :ip, :ua, :action, :detail, NOW())"
        )->execute([
            ':uid'    => $userId,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':action' => $action,
            ':detail' => substr($detail, 0, 200),
        ]);
    } catch (PDOException $e) {
        error_log('log_session_event: ' . $e->getMessage());
    }
}

// ── Auth guard ────────────────────────────────────────────────────────────────
function require_auth(): void
{
    if (empty($_SESSION['user_id'])) {
        restore_remembered_login();
    }

    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    if (empty($_SESSION['last_regen']) || time() - $_SESSION['last_regen'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }
}

function require_role(string ...$roles): void
{
    require_auth();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        die('<h1>403 — Access Denied</h1>');
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
if (!function_exists('e')) {
    function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

function verify_password(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

function needs_rehash(string $hash): bool
{
    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}
