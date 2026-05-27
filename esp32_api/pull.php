<?php
// ============================================================================
// ECOTWIN ESP32 PULL API
// Returns cloud-side greenhouse control state for ESP32 polling.
// ============================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../admin/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$providedKey = $_GET['api_key'] ?? $_SERVER['HTTP_X_ECOTWIN_KEY'] ?? '';
if (!hash_equals(ECOTWIN_ESP32_API_KEY, (string)$providedKey)) {
    jsonResponse(['success' => false, 'error' => 'Invalid ESP32 API key'], 401);
}

try {
    $pdo = getDB();
    $payload = [
        'success' => true,
        'cloud_time' => date('Y-m-d H:i:s'),
        'greenhouses' => [
            'A' => buildGreenhouseState($pdo, 'A'),
            'B' => buildGreenhouseState($pdo, 'B'),
        ],
    ];

    jsonResponse($payload);
} catch (Throwable $e) {
    error_log('ESP32 pull error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Server error while loading ESP32 cloud state.'], 500);
}

function buildGreenhouseState(PDO $pdo, string $code): array
{
    $state = [
        'pump' => 'off',
        'fan' => 'off',
        'light' => 'off',
        'latest_reading_at' => null,
    ];

    $ghStmt = $pdo->prepare("SELECT greenhouse_id FROM greenhouses WHERE code = ? LIMIT 1");
    $ghStmt->execute([$code]);
    $greenhouseId = (int)($ghStmt->fetchColumn() ?: 0);
    if ($greenhouseId <= 0) {
        return $state;
    }

    $actuatorStmt = $pdo->prepare("
        SELECT actuator_type, status
        FROM actuators
        WHERE greenhouse_id = ?
    ");
    $actuatorStmt->execute([$greenhouseId]);

    foreach ($actuatorStmt->fetchAll() as $row) {
        $type = strtolower((string)($row['actuator_type'] ?? ''));
        $status = normalizeStatus((string)($row['status'] ?? 'off'));

        if ($type === 'nutrient_pump') {
            $state['pump'] = combineGroupStatus($state['pump'], $status);
        } elseif ($type === 'exhaust_fan' || $type === 'circulation_fan') {
            $state['fan'] = combineGroupStatus($state['fan'], $status);
        } elseif ($type === 'shading_net' || $type === 'grow_light') {
            $state['light'] = combineGroupStatus($state['light'], $status);
        }
    }

    $readingStmt = $pdo->prepare("SELECT MAX(recorded_at) FROM sensor_readings WHERE greenhouse_id = ?");
    $readingStmt->execute([$greenhouseId]);
    $state['latest_reading_at'] = $readingStmt->fetchColumn() ?: null;

    return $state;
}

function normalizeStatus(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['on', 'off', 'auto', 'fault', 'maintenance'], true) ? $status : 'off';
}

function combineGroupStatus(string $current, string $next): string
{
    if ($current === 'on' || $next === 'on') {
        return 'on';
    }
    if ($current === 'auto' || $next === 'auto') {
        return 'auto';
    }
    if ($current === 'fault' || $next === 'fault') {
        return 'fault';
    }
    return $next;
}
