<?php
// ============================================================================
// ECOTWIN — Settings Actions Handler (AJAX endpoint)
// Handles: update system_config, add/update/delete users (admin only)
// ============================================================================

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/preferences.php';

require_auth();
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$user   = [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'role'    => (string)($_SESSION['user_role'] ?? ''),
];
$db     = db();

// ── Helper ───────────────────────────────────────────────────────────────────
// Sends a JSON success response and stops the current request.
function jsonSuccess(array $data = []): never {
    echo json_encode(['success' => true] + $data);
    exit;
}
// Sends a JSON error response and stops the current request.
// Sends a JSON error response and stops the current request.
function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
// Stops the request unless the current user has admin access.
function adminOnly(): void {
    if (($_SESSION['user_role'] ?? '') !== 'admin') jsonError('Admin privileges required.', 403);
}
// Ensures user preferences table exists before it is used.
function ensureUserPreferencesTable(PDO $db): void {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_preferences (
            user_id INT UNSIGNED NOT NULL,
            preference_key VARCHAR(100) NOT NULL,
            preference_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, preference_key),
            KEY idx_user_preferences_user_id (user_id),
            CONSTRAINT fk_user_preferences_user
                FOREIGN KEY (user_id) REFERENCES users(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
// Ensures user profile details table exists before it is used.
function ensureUserProfileDetailsTable(PDO $db): void {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_profile_details (
            user_id INT UNSIGNED NOT NULL,
            display_name VARCHAR(100) NULL,
            avatar_url VARCHAR(255) NULL,
            bio TEXT NULL,
            phone_number VARCHAR(30) NULL,
            address_line TEXT NULL,
            birthday DATE NULL,
            gender VARCHAR(40) NULL,
            pronouns VARCHAR(40) NULL,
            location_label VARCHAR(120) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_user_profile_details_user
                FOREIGN KEY (user_id) REFERENCES users(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
// Saves avatar upload changes for the current request.
// Saves avatar upload changes for the current request.
function saveAvatarUpload(int $userId, array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError('Avatar upload failed.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        jsonError('Avatar file must be 5 MB or smaller.');
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        jsonError('Invalid uploaded avatar file.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        jsonError('Avatar must be a JPG, PNG, GIF, or WEBP image.');
    }

    $uploadDir = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        jsonError('Unable to create avatar upload directory.');
    }

    $fileName = sprintf('avatar_u%d_%s.%s', $userId, bin2hex(random_bytes(8)), $allowed[$mime]);
    $targetPath = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        jsonError('Unable to store uploaded avatar.');
    }

    return 'uploads/avatars/' . $fileName;
}

// ── Dispatch ─────────────────────────────────────────────────────────────────
switch ($action) {

    case 'save_profile':
        ensureUserProfileDetailsTable($db);
        $incoming = $_POST['profile'] ?? null;
        if (!is_array($incoming)) jsonError('profile payload is required.');

        $fullName = trim((string)($incoming['full_name'] ?? ''));
        $email = trim((string)($incoming['email'] ?? ''));
        $username = trim((string)($incoming['username'] ?? ''));
        $displayName = trim((string)($incoming['display_name'] ?? ''));
        $bio = trim((string)($incoming['bio'] ?? ''));
        $phoneNumber = trim((string)($incoming['phone_number'] ?? ''));
        $addressLine = trim((string)($incoming['address_line'] ?? ''));
        $birthday = trim((string)($incoming['birthday'] ?? ''));
        $gender = trim((string)($incoming['gender'] ?? ''));
        $pronouns = trim((string)($incoming['pronouns'] ?? ''));
        $locationLabel = trim((string)($incoming['location_label'] ?? ''));

        if ($fullName === '' || mb_strlen($fullName) > 100) jsonError('Full name is required and must be 100 characters or fewer.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) jsonError('A valid primary email address is required.');
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) jsonError('Username must be 3-60 characters using letters, numbers, dot, underscore, or dash.');
        if ($birthday !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) jsonError('Birthday must use YYYY-MM-DD format.');
        if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Non-binary', 'Other'], true)) jsonError('Invalid gender selection.');

        $dupStmt = $db->prepare(
            "SELECT user_id FROM users WHERE (email = ? OR username = ?) AND user_id <> ? LIMIT 1"
        );
        $dupStmt->execute([$email, $username, $user['user_id']]);
        if ($dupStmt->fetch()) {
            jsonError('Email or username is already in use by another account.');
        }

        $existingProfileStmt = $db->prepare("SELECT avatar_url FROM user_profile_details WHERE user_id = ? LIMIT 1");
        $existingProfileStmt->execute([$user['user_id']]);
        $existingProfile = $existingProfileStmt->fetch();
        $avatarUrl = (string)($existingProfile['avatar_url'] ?? '');
        if (isset($_FILES['avatar_file']) && (int)($_FILES['avatar_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $avatarUrl = saveAvatarUpload($user['user_id'], $_FILES['avatar_file']);
        }

        $db->beginTransaction();
        try {
            $userStmt = $db->prepare(
                "UPDATE users SET full_name = ?, email = ?, username = ?, updated_at = NOW() WHERE user_id = ?"
            );
            $userStmt->execute([$fullName, $email, $username, $user['user_id']]);

            $profileStmt = $db->prepare(
                "INSERT INTO user_profile_details
                 (user_id, display_name, avatar_url, bio, phone_number, address_line, birthday, gender, pronouns, location_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name),
                    avatar_url = VALUES(avatar_url),
                    bio = VALUES(bio),
                    phone_number = VALUES(phone_number),
                    address_line = VALUES(address_line),
                    birthday = VALUES(birthday),
                    gender = VALUES(gender),
                    pronouns = VALUES(pronouns),
                    location_label = VALUES(location_label),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $profileStmt->execute([
                $user['user_id'],
                $displayName !== '' ? $displayName : null,
                $avatarUrl !== '' ? $avatarUrl : null,
                $bio !== '' ? $bio : null,
                $phoneNumber !== '' ? $phoneNumber : null,
                $addressLine !== '' ? $addressLine : null,
                $birthday !== '' ? $birthday : null,
                $gender !== '' ? $gender : null,
                $pronouns !== '' ? $pronouns : null,
                $locationLabel !== '' ? $locationLabel : null,
            ]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('Unable to save profile settings.');
        }

        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_email'] = $email;
        log_activity_event(
            (int)$user['user_id'],
            'account',
            'save_profile',
            'Updated profile settings',
            'user',
            (int)$user['user_id']
        );

        jsonSuccess([
            'message' => 'Profile settings saved successfully.',
            'data' => [
                'full_name' => $fullName,
                'email' => $email,
                'username' => $username,
                'display_name' => $displayName !== '' ? $displayName : $fullName,
                'phone_number' => $phoneNumber,
                'location_label' => $locationLabel,
                'avatar_url' => $avatarUrl,
            ],
        ]);

    case 'change_password':
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            jsonError('Current password, new password, and confirmation are required.');
        }
        if ($newPassword !== $confirmPassword) {
            jsonError('New password and confirmation do not match.');
        }
        if (strlen($newPassword) < 8) {
            jsonError('New password must be at least 8 characters long.');
        }

        $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user['user_id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($currentPassword, (string)$row['password_hash'])) {
            jsonError('Current password is incorrect.', 403);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
        $updateStmt->execute([$newHash, $user['user_id']]);
        log_activity_event(
            (int)$user['user_id'],
            'account',
            'change_password',
            'Changed account password',
            'user',
            (int)$user['user_id']
        );

        jsonSuccess(['message' => 'Password updated successfully.']);

    case 'save_preferences':
        ensureUserPreferencesTable($db);
        $incoming = $_POST['preferences'] ?? null;
        if (!is_array($incoming)) jsonError('preferences payload is required.');

        $allowed = [
            'theme_mode'     => ['light', 'dark', 'high-contrast'],
            'content_layout' => ['grid', 'list'],
            'font_size'      => ['small', 'medium', 'large'],
            'font_style'     => ['sans', 'serif', 'monospace'],
            'language'       => ecotwinAllowedLanguages(),
            'date_format'    => ['M j, Y g:i A', 'd/m/Y H:i', 'Y-m-d H:i'],
            'timezone'       => ['Asia/Manila', 'Asia/Taipei', 'UTC'],
            'notify_sms'     => ['0', '1'],
            'notify_push'    => ['0', '1'],
            'notify_web'     => ['0', '1'],
        ];

        $stmt = $db->prepare(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = CURRENT_TIMESTAMP"
        );

        foreach ($allowed as $key => $choices) {
            $value = isset($incoming[$key]) ? trim((string)$incoming[$key]) : null;
            if ($value === null || !in_array($value, $choices, true)) {
                jsonError("Invalid preference '{$key}'.");
            }
            $stmt->execute([$user['user_id'], $key, $value]);
        }
        log_activity_event(
            (int)$user['user_id'],
            'account',
            'save_preferences',
            'Updated interface and notification preferences',
            'user',
            (int)$user['user_id']
        );

        jsonSuccess(['message' => 'Preferences saved successfully.']);

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
        log_activity_event((int)$user['user_id'], 'system', 'update_config', "Updated config {$key}", 'system');
        jsonSuccess(['message' => "Config '{$key}' updated."]);

    // ── Add a new user (admin only) ──────────────────────────────────────────
    case 'add_user':
        adminOnly();
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $username  = trim($_POST['username']  ?? '');
        $password  = $_POST['password']       ?? '';
        $role      = $_POST['role']           ?? 'researcher';

        if (!$full_name || !$email || !$username || !$password)
            jsonError('All fields are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonError('Invalid email address.');
        if (!in_array($role, ['admin','researcher'], true))
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
        log_activity_event((int)$user['user_id'], 'users', 'create_user', "Created {$role} account for {$email}", 'user', (int)$db->lastInsertId());
        jsonSuccess(['user_id' => $db->lastInsertId(), 'message' => 'User created successfully.']);

    // ── Update user role/status (admin only) ─────────────────────────────────
    case 'update_user':
        adminOnly();
        $target_id = (int)($_POST['user_id'] ?? 0);
        $role      = $_POST['role']   ?? '';
        $status    = $_POST['status'] ?? '';

        if ($target_id <= 0) jsonError('Invalid user_id.');
        if (!in_array($role,   ['admin','researcher'], true)) jsonError('Invalid role.');
        if (!in_array($status, ['active','inactive','suspended'], true)) jsonError('Invalid status.');

        // Prevent self-demotion
        if ($target_id === (int)$user['user_id'] && $role !== 'admin')
            jsonError('You cannot demote your own admin account.');

        $stmt = $db->prepare(
            "UPDATE users SET role = ?, status = ?, updated_at = NOW() WHERE user_id = ?"
        );
        $stmt->execute([$role, $status, $target_id]);
        log_activity_event((int)$user['user_id'], 'users', 'update_user', "Updated user #{$target_id} to role {$role} with status {$status}", 'user', $target_id);
        jsonSuccess(['message' => 'User updated successfully.']);

    // ── Delete a user (admin only) ───────────────────────────────────────────
    case 'delete_user':
        adminOnly();
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id <= 0) jsonError('Invalid user_id.');
        if ($target_id === (int)$user['user_id']) jsonError('You cannot delete your own account.');
        try {
            $checkStmt = $db->prepare("SELECT role FROM users WHERE user_id = ? LIMIT 1");
            $checkStmt->execute([$target_id]);
            $targetUser = $checkStmt->fetch();
            if (!$targetUser) jsonError('User not found.', 404);
            if (($targetUser['role'] ?? '') === 'admin') {
                $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) jsonError('Cannot remove the only admin user.', 409);
            }

            $startedTransaction = !$db->inTransaction();
            if ($startedTransaction) {
                $db->beginTransaction();
            }
            detach_user_references($db, $target_id);
            $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$target_id]);
            log_activity_event((int)$user['user_id'], 'users', 'delete_user', "Deleted user #{$target_id}", 'user', $target_id);
            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                try {
                    $db->rollBack();
                } catch (Throwable $rollbackError) {
                    error_log('settings_actions delete_user rollback: ' . $rollbackError->getMessage());
                }
            }
            jsonError($e->getMessage(), 500);
        }
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
        log_activity_event((int)$user['user_id'], 'maintenance', 'log_maintenance', $act . ($desc !== '' ? ' - ' . $desc : ''), $comp_type, $comp_id);
        jsonSuccess(['log_id' => $db->lastInsertId(), 'message' => 'Maintenance logged.']);

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
