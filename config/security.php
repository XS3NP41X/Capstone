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
