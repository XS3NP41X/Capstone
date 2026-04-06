<?php
// ============================================================================
// ECOTWIN - Alerts/Events API  (reports/api/alerts.php)
// Returns paginated, filtered alerts as JSON for the events table
// ============================================================================

require_once __DIR__ . '/../../admin/db.php';

header('Content-Type: application/json; charset=utf-8');

$method   = $_SERVER['REQUEST_METHOD'];
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = 10;
$offset   = ($page - 1) * $perPage;
$ghFilter = $_GET['greenhouse'] ?? 'all';   // 'all','A','B'
$severity = $_GET['severity']   ?? 'all';   // 'all','critical','warning','info','success'
$alertId  = isset($_GET['alert_id'])  ? (int)$_GET['alert_id']  : null;
$resolveId= isset($_GET['resolve'])   ? (int)$_GET['resolve']   : null;

try {
    $pdo = getDB();

    // ---- POST: resolve an alert -----------------------------------------
    if ($method === 'POST' && $resolveId) {
        $pdo->prepare("UPDATE alerts SET is_resolved = 1, resolved_at = NOW() WHERE alert_id = ?")
            ->execute([$resolveId]);
        jsonResponse(['success' => true, 'message' => 'Alert resolved']);
    }

    // ---- GET single alert -----------------------------------------------
    if ($alertId) {
        $stmt = $pdo->prepare("
            SELECT
                a.alert_id, a.severity, a.category, a.message, a.sensor_value,
                a.is_resolved, a.detail,
                DATE_FORMAT(a.created_at, '%b %e, %Y %l:%i %p') AS created_fmt,
                COALESCE(g.code, '—') AS gh_code
            FROM alerts a
            LEFT JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
            WHERE a.alert_id = ?
        ");
        $stmt->execute([$alertId]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    $where  = ['1=1'];
    $params = [];

    if ($ghFilter !== 'all') {
        $where[]  = 'g.code = ?';
        $params[] = strtoupper($ghFilter);
    }

    if ($severity === 'critical') {
        $where[]  = "a.severity = 'critical'";
    } elseif ($severity === 'warning') {
        $where[]  = "a.severity = 'warning'";
    } elseif (!in_array($severity, ['all', ''])) {
        $where[]  = 'a.severity = ?';
        $params[] = $severity;
    }

    $whereSQL = implode(' AND ', $where);

    // Total count
    $countSQL = "
        SELECT COUNT(*) FROM alerts a
        LEFT JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
        WHERE $whereSQL
    ";
    $total = (int)$pdo->prepare($countSQL)->execute($params) ? $pdo->prepare($countSQL)->execute($params) : 0;
    $countStmt = $pdo->prepare($countSQL);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Paginated rows
    $dataSQL = "
        SELECT
            a.alert_id,
            a.severity,
            a.category,
            a.message,
            a.sensor_value,
            a.is_resolved,
            DATE_FORMAT(a.created_at, '%b %e, %l:%i %p') AS created_fmt,
            a.created_at,
            COALESCE(g.code, '—') AS gh_code,
            COALESCE(g.name, 'System') AS gh_name
        FROM alerts a
        LEFT JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
        WHERE $whereSQL
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $dataStmt = $pdo->prepare($dataSQL);
    $dataStmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $dataStmt->fetchAll();

    jsonResponse([
        'success'    => true,
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'total_pages'=> (int)ceil($total / $perPage),
    ]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
