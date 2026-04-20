<?php
// ============================================================================
// ECOTWIN - Users API  (admin/api/users.php)
// Handles: GET list, POST create, PUT update role/status, DELETE
// ============================================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../config/security.php';

require_role('admin');

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
    $pdo = getDB();

    // ------------------------------------------------------------------ GET
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT user_id, full_name, email, username, role, status,
                   DATE_FORMAT(last_login_at, '%b %d, %Y %H:%i') AS last_login_fmt,
                   DATE_FORMAT(created_at,    '%b %d, %Y')        AS created_fmt
            FROM users
            ORDER BY
                FIELD(role,'admin','researcher'),
                full_name ASC
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ----------------------------------------------------------------- POST (create)
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);

        $name  = sanitize($body['name']  ?? '');
        $email = filter_var(trim($body['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $pass  = $body['password'] ?? '';

        if (!$name)  jsonResponse(['success' => false, 'error' => 'Full name is required'], 422);
        if (!$email) jsonResponse(['success' => false, 'error' => 'Valid email is required'], 422);
        if (strlen($pass) < 8) jsonResponse(['success' => false, 'error' => 'Password must be at least 8 characters'], 422);

        $role = in_array($body['role'] ?? '', ['admin','researcher']) ? $body['role'] : 'researcher';

        // Auto-generate username from email prefix
        $username = strtolower(explode('@', $email)[0]);

        // Check uniqueness
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
        $chk->execute([$email, $username]);
        if ($chk->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'error' => 'Email or username already exists'], 409);
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $ins  = $pdo->prepare("
            INSERT INTO users (full_name, email, username, password_hash, role)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([$name, $email, $username, $hash, $role]);
        $userId = (int)$pdo->lastInsertId();
        log_activity_event((int)($_SESSION['user_id'] ?? 0), 'users', 'create_user', "Created {$role} account for {$email}", 'user', $userId);

        jsonResponse(['success' => true, 'user_id' => $userId, 'message' => 'User created successfully']);
    }

    // ------------------------------------------------------------------ PUT (update role / status)
    if ($method === 'PUT') {
        if (!$id) jsonResponse(['success' => false, 'error' => 'User ID required'], 400);
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);

        $allowed = [];
        $params  = [];

        if (isset($body['role']) && in_array($body['role'], ['admin','researcher'])) {
            $allowed[] = 'role = ?';
            $params[]  = $body['role'];
        }
        if (isset($body['status']) && in_array($body['status'], ['active','inactive','suspended'])) {
            $allowed[] = 'status = ?';
            $params[]  = $body['status'];
        }

        if (empty($allowed)) jsonResponse(['success' => false, 'error' => 'Nothing to update'], 422);

        $params[] = $id;
        $pdo->prepare("UPDATE users SET " . implode(', ', $allowed) . " WHERE user_id = ?")->execute($params);
        log_activity_event((int)($_SESSION['user_id'] ?? 0), 'users', 'update_user', 'Updated user #' . $id, 'user', $id);
        jsonResponse(['success' => true, 'message' => 'User updated']);
    }

    // --------------------------------------------------------------- DELETE
    if ($method === 'DELETE') {
        if (!$id) jsonResponse(['success' => false, 'error' => 'User ID required'], 400);

        // Prevent self-delete (hardcoded admin guard — extend with session check)
        $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) jsonResponse(['success' => false, 'error' => 'User not found'], 404);

        // Prevent deleting if user is the only admin
        if ($user['role'] === 'admin') {
            $adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                jsonResponse(['success' => false, 'error' => 'Cannot remove the only admin user'], 409);
            }
        }

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        detach_user_references($pdo, $id);
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$id]);
        log_activity_event((int)($_SESSION['user_id'] ?? 0), 'users', 'delete_user', 'Deleted user #' . $id, 'user', $id);
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        jsonResponse(['success' => true, 'message' => 'User removed']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackError) {
            error_log('admin/api/users rollback: ' . $rollbackError->getMessage());
        }
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackError) {
            error_log('admin/api/users rollback: ' . $rollbackError->getMessage());
        }
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
