<?php
// ============================================================================
// ECOTWIN - Reports API Router  (reports/api/reports_api.php)
// Handles: alerts, export, stats
// ============================================================================

require_once __DIR__ . '/../../admin/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'alerts') {
    require __DIR__ . '/live_alerts.php';
    exit;
}

if ($action === 'export') {
    require __DIR__ . '/export.php';
    exit;
}

if ($action !== 'stats') {
    jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}

try {
    $pdo = getDB();

    $dataPoints = (int)$pdo->query("
        SELECT COUNT(*) FROM sensor_readings
        WHERE recorded_at >= NOW() - INTERVAL 72 HOUR
    ")->fetchColumn();

    $sensorRow = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) AS online_cnt,
            COUNT(*) AS total_cnt
        FROM sensors
    ")->fetch();

    $uptime = !empty($sensorRow['total_cnt'])
        ? round(((int)$sensorRow['online_cnt'] / (int)$sensorRow['total_cnt']) * 100, 1)
        : 0;

    $greenhouses = $pdo->query("
        SELECT greenhouse_id, code, assigned_plant_id
        FROM greenhouses
        ORDER BY code
    ")->fetchAll();

    $critical = 0;
    $warnings = 0;

    foreach ($greenhouses as $gh) {
        if (empty($gh['assigned_plant_id'])) {
            continue;
        }

        $thresholdStmt = $pdo->prepare("
            SELECT parameter, val_min, val_opt_low, val_opt_high, val_max
            FROM plant_thresholds
            WHERE plant_id = ?
        ");
        $thresholdStmt->execute([(int)$gh['assigned_plant_id']]);
        $thresholds = [];
        foreach ($thresholdStmt->fetchAll() as $row) {
            $thresholds[$row['parameter']] = $row;
        }

        if (!$thresholds) {
            continue;
        }

        $readingStmt = $pdo->prepare("
            SELECT parameter, value
            FROM v_latest_readings
            WHERE greenhouse_id = ?
        ");
        $readingStmt->execute([(int)$gh['greenhouse_id']]);
        $readings = [];
        foreach ($readingStmt->fetchAll() as $row) {
            $readings[$row['parameter']] = (float)$row['value'];
        }

        foreach ($thresholds as $parameter => $threshold) {
            if (!array_key_exists($parameter, $readings)) {
                continue;
            }

            $value = $readings[$parameter];
            if ($value < (float)$threshold['val_min'] || $value > (float)$threshold['val_max']) {
                $critical++;
            } elseif ($value < (float)$threshold['val_opt_low'] || $value > (float)$threshold['val_opt_high']) {
                $warnings++;
            }
        }
    }

    jsonResponse([
        'success' => true,
        'data_points' => $dataPoints,
        'critical' => $critical,
        'warnings' => $warnings,
        'uptime' => $uptime,
    ]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
