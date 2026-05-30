<?php
// ============================================================================
// ECOTWIN - Greenhouse Assignment API  (admin/api/greenhouses.php)
// Handles: GET assignments, PUT assign plant to greenhouse, DELETE clear
// ============================================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../config/security.php';

header('Content-Type: application/json; charset=utf-8');

require_role('admin');

$method = $_SERVER['REQUEST_METHOD'];
$code   = strtoupper($_GET['code'] ?? ''); // 'A' or 'B'

try {
    $pdo = getDB();

    // ------------------------------------------------------------------ GET
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT g.greenhouse_id, g.code, g.name, g.role, g.description,
                   g.assigned_plant_id,
                   p.name  AS plant_name,
                   p.emoji AS plant_emoji,
                   p.scientific_name AS plant_sci,
                   pc.code AS plant_category
            FROM greenhouses g
            LEFT JOIN plants p  ON g.assigned_plant_id = p.plant_id
            LEFT JOIN plant_categories pc ON p.category_id = pc.category_id
            ORDER BY g.code
        ");
        $greenhouses = $stmt->fetchAll();

        // Attach threshold summaries for assigned plants
        foreach ($greenhouses as &$gh) {
            if ($gh['assigned_plant_id']) {
                $tStmt = $pdo->prepare("
                    SELECT parameter, val_opt_low, val_opt_high, unit
                    FROM plant_thresholds WHERE plant_id = ?
                ");
                $tStmt->execute([$gh['assigned_plant_id']]);
                $thresholds = [];
                foreach ($tStmt->fetchAll() as $t) {
                    $thresholds[$t['parameter']] = [
                        'optLow'  => (float)$t['val_opt_low'],
                        'optHigh' => (float)$t['val_opt_high'],
                        'unit'    => $t['unit'],
                    ];
                }
                $gh['thresholds'] = $thresholds;
            } else {
                $gh['thresholds'] = [];
            }
        }

        jsonResponse(['success' => true, 'data' => $greenhouses]);
    }

    // ------------------------------------------------------------------ PUT (assign plant)
    if ($method === 'PUT') {
        assertNoActiveExperimentForAssignment($pdo);

        if (!$code || !in_array($code, ['A','B'])) {
            jsonResponse(['success' => false, 'error' => 'Valid greenhouse code (A or B) required'], 400);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $plantId = isset($body['plant_id']) ? (int)$body['plant_id'] : null;

        if (!$plantId) jsonResponse(['success' => false, 'error' => 'plant_id is required'], 422);

        // Verify plant exists
        $p = $pdo->prepare("SELECT name FROM plants WHERE plant_id = ?");
        $p->execute([$plantId]);
        $plant = $p->fetch();
        if (!$plant) jsonResponse(['success' => false, 'error' => 'Plant not found'], 404);

        $pdo->prepare("UPDATE greenhouses SET assigned_plant_id = ? WHERE code = ?")
            ->execute([$plantId, $code]);
        log_activity_event((int)($_SESSION['user_id'] ?? 0), 'greenhouse', 'assign_plant', "Assigned plant #{$plantId} to greenhouse {$code}", 'greenhouse', $code);

        jsonResponse([
            'success' => true,
            'message' => "Greenhouse $code assigned to {$plant['name']}"
        ]);
    }

    // --------------------------------------------------------------- DELETE (clear assignment)
    if ($method === 'DELETE') {
        assertNoActiveExperimentForAssignment($pdo);

        if (!$code || !in_array($code, ['A','B'])) {
            jsonResponse(['success' => false, 'error' => 'Valid greenhouse code required'], 400);
        }
        $pdo->prepare("UPDATE greenhouses SET assigned_plant_id = NULL WHERE code = ?")
            ->execute([$code]);
        log_activity_event((int)($_SESSION['user_id'] ?? 0), 'greenhouse', 'clear_assignment', "Cleared greenhouse {$code} assignment", 'greenhouse', $code);
        jsonResponse(['success' => true, 'message' => "Greenhouse $code assignment cleared"]);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

function assertNoActiveExperimentForAssignment(PDO $pdo): void
{
    $active = $pdo->query("
        SELECT e.exp_code, u.full_name AS researcher_name
        FROM experiments e
        JOIN users u ON u.user_id = e.principal_user_id
        WHERE e.status = 'active'
        ORDER BY e.started_at DESC, e.experiment_id DESC
        LIMIT 1
    ")->fetch();

    if ($active) {
        jsonResponse([
            'success' => false,
            'error' => 'Greenhouse assignments are locked while ' . $active['researcher_name'] . ' is conducting ' . $active['exp_code'] . '.',
        ], 409);
    }
}
