<?php
// ============================================================================
// ECOTWIN ESP32 API HEALTH CHECK
// Visit: https://ecotwin.page.gd/esp32_api/health.php?api_key=ecotwin-esp32-key
// ============================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../admin/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-ECOTWIN-KEY');

$providedKey = $_GET['api_key'] ?? $_SERVER['HTTP_X_ECOTWIN_KEY'] ?? '';
if (!hash_equals(ECOTWIN_ESP32_API_KEY, (string)$providedKey)) {
    jsonResponse(['success' => false, 'error' => 'Invalid ESP32 API key'], 401);
}

try {
    $pdo = getDB();
    $tables = ['greenhouses', 'sensors', 'sensor_readings'];
    $status = [];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $status[$table] = (bool)$stmt->fetchColumn();
    }

    $latestReading = null;
    if ($status['sensor_readings']) {
        $latestReading = $pdo->query("SELECT MAX(recorded_at) FROM sensor_readings")->fetchColumn() ?: null;
    }

    jsonResponse([
        'success' => true,
        'domain' => ECOTWIN_ESP32_DOMAIN,
        'database_connected' => true,
        'tables' => $status,
        'latest_reading_at' => $latestReading,
    ]);
} catch (Throwable $e) {
    error_log('ESP32 health error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'database_connected' => false,
        'error' => 'Database health check failed.',
    ], 500);
}
