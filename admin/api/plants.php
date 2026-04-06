<?php
// ============================================================================
// ECOTWIN - Plants API  (admin/api/plants.php)
// Handles: GET list, GET single, POST create, PUT update, DELETE
// ============================================================================

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
    $pdo = getDB();

    // ------------------------------------------------------------------ GET
    if ($method === 'GET') {
        if ($id) {
            // Single plant with all thresholds
            $stmt = $pdo->prepare("
                SELECT p.*, pc.code AS category_code, pc.label AS category_label, pc.emoji AS category_emoji
                FROM plants p
                JOIN plant_categories pc ON p.category_id = pc.category_id
                WHERE p.plant_id = ?
            ");
            $stmt->execute([$id]);
            $plant = $stmt->fetch();
            if (!$plant) jsonResponse(['success' => false, 'error' => 'Plant not found'], 404);

            // Fetch thresholds
            $stmt2 = $pdo->prepare("SELECT parameter, unit, val_min, val_opt_low, val_opt_high, val_max FROM plant_thresholds WHERE plant_id = ?");
            $stmt2->execute([$id]);
            $thresholds = [];
            foreach ($stmt2->fetchAll() as $t) {
                $thresholds[$t['parameter']] = [
                    'min'     => (float)$t['val_min'],
                    'optLow'  => (float)$t['val_opt_low'],
                    'optHigh' => (float)$t['val_opt_high'],
                    'max'     => (float)$t['val_max'],
                    'unit'    => $t['unit'],
                ];
            }
            $plant['thresholds'] = $thresholds;
            jsonResponse(['success' => true, 'data' => $plant]);
        }

        // List all plants (with optional category filter)
        $category = $_GET['category'] ?? 'all';
        $search   = '%' . ($_GET['search'] ?? '') . '%';

        $sql = "
            SELECT p.plant_id, p.name, p.scientific_name, p.emoji, p.photoperiod_hrs, p.notes,
                   pc.code AS category_code, pc.label AS category_label, pc.emoji AS category_emoji
            FROM plants p
            JOIN plant_categories pc ON p.category_id = pc.category_id
            WHERE (p.name LIKE ? OR p.scientific_name LIKE ?)
        ";
        $params = [$search, $search];

        if ($category !== 'all') {
            $sql .= " AND pc.code = ?";
            $params[] = $category;
        }
        $sql .= " ORDER BY p.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $plants = $stmt->fetchAll();

        // Attach thresholds to each plant (batch query)
        if ($plants) {
            $plantIds = array_column($plants, 'plant_id');
            $in       = implode(',', array_fill(0, count($plantIds), '?'));
            $tStmt    = $pdo->prepare("SELECT plant_id, parameter, val_min, val_opt_low, val_opt_high, val_max, unit FROM plant_thresholds WHERE plant_id IN ($in)");
            $tStmt->execute($plantIds);
            $allThresh = [];
            foreach ($tStmt->fetchAll() as $t) {
                $allThresh[$t['plant_id']][$t['parameter']] = [
                    'min'     => (float)$t['val_min'],
                    'optLow'  => (float)$t['val_opt_low'],
                    'optHigh' => (float)$t['val_opt_high'],
                    'max'     => (float)$t['val_max'],
                    'unit'    => $t['unit'],
                ];
            }
            foreach ($plants as &$p) {
                $p['thresholds'] = $allThresh[$p['plant_id']] ?? [];
            }
        }

        jsonResponse(['success' => true, 'data' => $plants]);
    }

    // ----------------------------------------------------------------- POST (create)
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);

        $name = sanitize($body['name'] ?? '');
        if (!$name) jsonResponse(['success' => false, 'error' => 'Plant name is required'], 422);

        // Resolve category_id
        $catStmt = $pdo->prepare("SELECT category_id FROM plant_categories WHERE code = ?");
        $catStmt->execute([sanitize($body['category'] ?? 'leafy')]);
        $cat = $catStmt->fetch();
        if (!$cat) jsonResponse(['success' => false, 'error' => 'Invalid category'], 422);

        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT INTO plants (name, scientific_name, category_id, emoji, photoperiod_hrs, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $name,
            sanitize($body['sci'] ?? ''),
            $cat['category_id'],
            sanitize($body['icon'] ?? '🌱'),
            (int)($body['photo'] ?? 12),
            sanitize($body['notes'] ?? ''),
            null  // Set to session user_id when auth is integrated
        ]);
        $plantId = (int)$pdo->lastInsertId();

        _saveThresholds($pdo, $plantId, $body);

        $pdo->commit();
        jsonResponse(['success' => true, 'plant_id' => $plantId, 'message' => 'Plant added to library']);
    }

    // ------------------------------------------------------------------ PUT (update)
    if ($method === 'PUT') {
        if (!$id) jsonResponse(['success' => false, 'error' => 'Plant ID required'], 400);
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);

        $name = sanitize($body['name'] ?? '');
        if (!$name) jsonResponse(['success' => false, 'error' => 'Plant name is required'], 422);

        $catStmt = $pdo->prepare("SELECT category_id FROM plant_categories WHERE code = ?");
        $catStmt->execute([sanitize($body['category'] ?? 'leafy')]);
        $cat = $catStmt->fetch();
        if (!$cat) jsonResponse(['success' => false, 'error' => 'Invalid category'], 422);

        $pdo->beginTransaction();

        $upd = $pdo->prepare("
            UPDATE plants SET name=?, scientific_name=?, category_id=?, emoji=?, photoperiod_hrs=?, notes=?
            WHERE plant_id=?
        ");
        $upd->execute([
            $name,
            sanitize($body['sci'] ?? ''),
            $cat['category_id'],
            sanitize($body['icon'] ?? '🌱'),
            (int)($body['photo'] ?? 12),
            sanitize($body['notes'] ?? ''),
            $id
        ]);

        // Delete old thresholds and reinsert
        $pdo->prepare("DELETE FROM plant_thresholds WHERE plant_id = ?")->execute([$id]);
        _saveThresholds($pdo, $id, $body);

        $pdo->commit();
        jsonResponse(['success' => true, 'message' => 'Plant profile updated']);
    }

    // --------------------------------------------------------------- DELETE
    if ($method === 'DELETE') {
        if (!$id) jsonResponse(['success' => false, 'error' => 'Plant ID required'], 400);

        // Prevent deletion if plant is assigned to a greenhouse
        $check = $pdo->prepare("SELECT COUNT(*) AS cnt FROM greenhouses WHERE assigned_plant_id = ?");
        $check->execute([$id]);
        if ($check->fetch()['cnt'] > 0) {
            jsonResponse(['success' => false, 'error' => 'Cannot delete: plant is assigned to a greenhouse'], 409);
        }

        $pdo->prepare("DELETE FROM plants WHERE plant_id = ?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Plant deleted']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

// ============================================================================
// HELPER: Insert threshold rows for all parameters
// ============================================================================
function _saveThresholds(PDO $pdo, int $plantId, array $body): void {
    $params = [
        ['temperature', '°C',     'temp'],
        ['humidity',    '%',      'hum'],
        ['ph',          '',       'ph'],
        ['ec',          'mS/cm',  'ec'],
        ['light',       'lux',    'lux'],
        ['water_level', '%',      'water'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO plant_thresholds (plant_id, parameter, unit, val_min, val_opt_low, val_opt_high, val_max)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($params as [$param, $unit, $key]) {
        if ($param === 'water_level') {
            $min    = (float)($body[$key . '_min']  ?? 0);
            $optLow = (float)($body[$key . '_opt']  ?? 0);
            $optHigh= (float)($body[$key . '_opt']  ?? 0); // same as opt for water
            $max    = (float)($body[$key . '_max']  ?? 0);
        } else {
            $min    = (float)($body[$key . '_min']      ?? 0);
            $optLow = (float)($body[$key . '_opt_low']  ?? 0);
            $optHigh= (float)($body[$key . '_opt_high'] ?? 0);
            $max    = (float)($body[$key . '_max']      ?? 0);
        }
        $stmt->execute([$plantId, $param, $unit, $min, $optLow, $optHigh, $max]);
    }
}
