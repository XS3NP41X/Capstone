<?php
// ============================================================================
// ECOTWIN — AUTH GUARD
// Include this at the top of every protected page:
//   require_once __DIR__ . '/auth_guard.php';
//
// Optionally restrict to specific roles:
//   require_role('admin');
//   require_role('admin', 'researcher');
// ============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';

// Verify session is valid and user still exists/is active in DB
if (!empty($_SESSION['user_id'])) {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare(
            "SELECT user_id, full_name, email, role, status FROM users WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active') {
            // Account deleted or suspended — force logout
            session_destroy();
            header('Location: login.php?reason=suspended');
            exit;
        }

        // Sync session in case role changed server-side
        $_SESSION['user_name']  = $user['full_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];

    } catch (PDOException $e) {
        error_log('Auth guard DB error: ' . $e->getMessage());
    }
}

require_auth();
