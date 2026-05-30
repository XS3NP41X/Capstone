<?php
// ============================================================================
// ECOTWIN - System Config API  (admin/api/system.php)
// Handles: GET settings, POST save settings, POST maintenance actions
// ============================================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../config/security.php';

header('Content-Type: application/json; charset=utf-8');

require_role('admin');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $pdo = getDB();

    // ------------------------------------------------------------------ GET settings
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT setting_key, setting_value, setting_type FROM system_settings ORDER BY setting_key");
        $rows     = $stmt->fetchAll();
        $settings = [];
        foreach ($rows as $r) {
            $val = $r['setting_value'];
            if ($r['setting_type'] === 'boolean') $val = ($val === '1' || $val === 'true');
            if ($r['setting_type'] === 'integer') $val = (int)$val;
            $settings[$r['setting_key']] = $val;
        }
        jsonResponse(['success' => true, 'data' => $settings]);
    }

    // ----------------------------------------------------------------- POST
    if ($method === 'POST') {

        // ---- Maintenance actions ----------------------------------------
        if ($action === 'maintenance') {
            $body = json_decode(file_get_contents('php://input'), true);
            $task = $body['task'] ?? '';

            switch ($task) {
                case 'reset_calibration':
                    // Flag all sensors for recalibration on next cycle
                    $pdo->exec("UPDATE sensors SET status = 'calibrating' WHERE status = 'online'");
                    log_activity_event((int)($_SESSION['user_id'] ?? 0), 'system', 'reset_calibration', 'Flagged online sensors for recalibration', 'system');
                    jsonResponse(['success' => true, 'message' => 'Calibration flags reset for all online sensors']);

                case 'clear_alerts':
                    // Remove alerts older than 30 days that are resolved
                    $del = $pdo->exec("DELETE FROM alerts WHERE is_resolved = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                    log_activity_event((int)($_SESSION['user_id'] ?? 0), 'system', 'clear_alerts', "Removed {$del} resolved alerts older than 30 days", 'alert');
                    jsonResponse(['success' => true, 'message' => "$del resolved alert(s) older than 30 days removed"]);

                case 'backup':
                    // Log a manual backup entry in maintenance_log
                    $pdo->prepare("
                        INSERT INTO maintenance_log (component_type, action, description, performed_at)
                        VALUES ('system', 'Manual Backup', 'Manual database backup requested via Admin Panel', NOW())
                    ")->execute();
                    log_activity_event((int)($_SESSION['user_id'] ?? 0), 'system', 'manual_backup', 'Logged a manual backup request', 'system');
                    jsonResponse([
                        'success' => true,
                        'message' => 'Backup logged. Use mysqldump or phpMyAdmin Export to download the actual .sql file.'
                    ]);

                case 'reboot':
                    // Log reboot signal in maintenance log
                    $pdo->prepare("
                        INSERT INTO maintenance_log (component_type, action, description, performed_at)
                        VALUES ('system', 'Reboot Signal', 'Hardware reboot signal sent to Arduino & ESP32 via Admin Panel', NOW())
                    ")->execute();
                    log_activity_event((int)($_SESSION['user_id'] ?? 0), 'system', 'reboot_signal', 'Logged a reboot signal request', 'system');
                    jsonResponse(['success' => true, 'message' => 'Reboot signal logged. Hardware will restart on next polling cycle.']);

                default:
                    jsonResponse(['success' => false, 'error' => 'Unknown maintenance task'], 400);
            }
        }

        // ---- Save settings -----------------------------------------------
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body || !isset($body['settings'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid request body'], 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        $allowed_keys = [
            'sync_interval_minutes', 'alert_cooldown_minutes', 'data_retention_days',
            'auto_backup_enabled',
            'sms_critical_alerts', 'sms_warning_alerts', 'sms_weekly_reports',
            'gsm_admin_phone', 'gsm_module_port', 'gsm_baud_rate',
            'ip_whitelist_enabled', 'ip_whitelist_addresses',
            'auto_cooling_fan', 'auto_humidity_fan', 'auto_ec_dosing',
            'auto_shading_net',
        ];

        $pdo->beginTransaction();
        foreach ($body['settings'] as $key => $value) {
            if (!in_array($key, $allowed_keys)) continue;
            $stmt->execute([$key, (string)$value]);
        }
        $pdo->commit();
        log_activity_event((int)($_SESSION['user_id'] ?? 0), 'system', 'update_settings', 'Updated system settings: ' . implode(', ', array_keys($body['settings'] ?? [])), 'system');

        jsonResponse(['success' => true, 'message' => 'Settings saved successfully']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
