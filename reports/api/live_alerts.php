<?php
// ============================================================================
// ECOTWIN - Live Alerts/Events API  (reports/api/live_alerts.php)
// Returns live greenhouse issues derived from latest readings + thresholds
// ============================================================================

require_once __DIR__ . '/../../admin/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/query_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_auth();

$method    = $_SERVER['REQUEST_METHOD'];
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 10;
$offset    = ($page - 1) * $perPage;
$ghFilter  = $_GET['greenhouse'] ?? 'all';
$severity  = $_GET['severity'] ?? 'all';
$resolveId = isset($_GET['resolve']) ? (int)$_GET['resolve'] : null;

try {
    $pdo = getDB();

    if ($method === 'POST' && $resolveId) {
        $pdo->prepare("UPDATE alerts SET is_resolved = 1, resolved_at = NOW() WHERE alert_id = ?")
            ->execute([$resolveId]);
        log_activity_event(
            (int)($_SESSION['user_id'] ?? 0),
            'reports',
            'resolve_alert',
            "Resolved live alert #{$resolveId}",
            'alert',
            $resolveId
        );
        jsonResponse(['success' => true, 'message' => 'Alert resolved']);
    }

    $events = buildLiveIssueEvents($pdo, $ghFilter, $severity);
    $rows = array_slice($events, $offset, $perPage);

    jsonResponse([
        'success' => true,
        'data' => $rows,
        'total' => count($events),
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, (int)ceil(count($events) / $perPage)),
    ]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

// Builds the live issue event list used by the reporting views.
function buildLiveIssueEvents(PDO $pdo, string $ghFilter = 'all', string $severity = 'all'): array {
    if (!in_array($severity, ['all', 'critical', 'warning'], true)) {
        return [];
    }

    $sql = "SELECT greenhouse_id, code, name, assigned_plant_id FROM greenhouses";
    $params = [];
    if ($ghFilter !== 'all') {
        $sql .= " WHERE code = ?";
        $params[] = strtoupper($ghFilter);
    }
    $sql .= " ORDER BY code";

    $ghStmt = $pdo->prepare($sql);
    $ghStmt->execute($params);
    $greenhouses = $ghStmt->fetchAll();
    $events = [];

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

        foreach (ecotwinFetchLatestReadings($pdo, (int)$gh['greenhouse_id']) as $reading) {
            $parameter = $reading['parameter'];
            if (!isset($thresholds[$parameter])) {
                continue;
            }

            $threshold = $thresholds[$parameter];
            $value = (float)$reading['value'];
            $eventSeverity = null;
            $message = null;

            if ($value < (float)$threshold['val_min'] || $value > (float)$threshold['val_max']) {
                $eventSeverity = 'critical';
                $message = ucfirst(str_replace('_', ' ', $parameter)) . ' is outside the configured safe range';
            } elseif ($value < (float)$threshold['val_opt_low'] || $value > (float)$threshold['val_opt_high']) {
                $eventSeverity = 'warning';
                $message = ucfirst(str_replace('_', ' ', $parameter)) . ' is outside the optimal range';
            }

            if ($eventSeverity === null) {
                continue;
            }
            if ($severity !== 'all' && $severity !== $eventSeverity) {
                continue;
            }

            $events[] = [
                'alert_id' => 'live-' . $gh['code'] . '-' . $parameter,
                'severity' => $eventSeverity,
                'category' => $parameter,
                'message' => $message,
                'sensor_value' => $value,
                'is_resolved' => 0,
                'ts_fmt' => date('M j, g:i A', strtotime($reading['recorded_at'])),
                'created_at' => $reading['recorded_at'],
                'gh_code' => $gh['code'],
                'gh_name' => $gh['name'],
            ];
        }
    }

    usort($events, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    return $events;
}
