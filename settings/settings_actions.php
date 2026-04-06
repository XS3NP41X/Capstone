<?php
// ============================================================================
// ECOTWIN — Settings Actions Handler (AJAX endpoint)
// Handles: update system_config, add/update/delete users (admin only)
// ============================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session_helper.php';

requireLogin();
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$user   = getCurrentUser();
$db     = getDB();

// ── Helper ───────────────────────────────────────────────────────────────────
function jsonSuccess(array $data = []): never {
    echo json_encode(['success' => true] + $data);
    exit;
}
function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function adminOnly(): void {
    if (!isAdmin()) jsonError('Admin privileges required.', 403);
}

// ── Dispatch ─────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Update system_config key (admin only) ────────────────────────────────
    case 'update_config':
        adminOnly();
        $key   = trim($_POST['config_key']   ?? '');
        $value = trim($_POST['config_value'] ?? '');
        if ($key === '') jsonError('config_key is required.');

        $stmt = $db->prepare(
            "UPDATE system_config SET config_value = ?, updated_by = ? WHERE config_key = ?"
        );
        $stmt->execute([$value, $user['user_id'], $key]);
        if ($stmt->rowCount() === 0) jsonError("Config key '{$key}' not found.");
        jsonSuccess(['message' => "Config '{$key}' updated."]);

    // ── Add a new user (admin only) ──────────────────────────────────────────
    case 'add_user':
        adminOnly();
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $username  = trim($_POST['username']  ?? '');
        $password  = $_POST['password']       ?? '';
        $role      = $_POST['role']           ?? 'student';

        if (!$full_name || !$email || !$username || !$password)
            jsonError('All fields are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonError('Invalid email address.');
        if (!in_array($role, ['admin','researcher','student'], true))
            jsonError('Invalid role.');

        // Check uniqueness
        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
        $check->execute([$email, $username]);
        if ($check->fetchColumn() > 0)
            jsonError('Email or username already exists.');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare(
            "INSERT INTO users (full_name, email, username, password_hash, role, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([$full_name, $email, $username, $hash, $role]);
        jsonSuccess(['user_id' => $db->lastInsertId(), 'message' => 'User created successfully.']);

    // ── Update user role/status (admin only) ─────────────────────────────────
    case 'update_user':
        adminOnly();
        $target_id = (int)($_POST['user_id'] ?? 0);
        $role      = $_POST['role']   ?? '';
        $status    = $_POST['status'] ?? '';

        if ($target_id <= 0) jsonError('Invalid user_id.');
        if (!in_array($role,   ['admin','researcher','student'], true)) jsonError('Invalid role.');
        if (!in_array($status, ['active','inactive','suspended'], true)) jsonError('Invalid status.');

        // Prevent self-demotion
        if ($target_id === (int)$user['user_id'] && $role !== 'admin')
            jsonError('You cannot demote your own admin account.');

        $stmt = $db->prepare(
            "UPDATE users SET role = ?, status = ?, updated_at = NOW() WHERE user_id = ?"
        );
        $stmt->execute([$role, $status, $target_id]);
        jsonSuccess(['message' => 'User updated successfully.']);

    // ── Delete a user (admin only) ───────────────────────────────────────────
    case 'delete_user':
        adminOnly();
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0) jsonError('Invalid user_id.');
        if ($target_id === (int)$user['user_id']) jsonError('You cannot delete your own account.');

        $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$target_id]);
        jsonSuccess(['message' => 'User deleted.']);

    // ── Log a maintenance action ─────────────────────────────────────────────
    case 'log_maintenance':
        adminOnly();
        $comp_type = $_POST['component_type'] ?? '';
        $comp_id   = $_POST['component_id']   !== '' ? (int)$_POST['component_id'] : null;
        $act       = trim($_POST['action_label'] ?? '');
        $desc      = trim($_POST['description']  ?? '');
        $next_due  = $_POST['next_due_at'] !== '' ? $_POST['next_due_at'] : null;

        if (!in_array($comp_type, ['sensor','actuator','hardware','system'], true))
            jsonError('Invalid component_type.');
        if (!$act) jsonError('Action label is required.');

        $stmt = $db->prepare(
            "INSERT INTO maintenance_log
             (component_type, component_id, action, description, performed_by, next_due_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$comp_type, $comp_id, $act, $desc, $user['user_id'], $next_due]);
        jsonSuccess(['log_id' => $db->lastInsertId(), 'message' => 'Maintenance logged.']);

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
