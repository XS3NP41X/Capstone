<?php
// ============================================================================
// ECOTWIN GREENHOUSES API
// Handles AJAX requests from greenhouses.php
// ============================================================================

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // ----------------------------------------------------------------
        // GET: Latest sensor readings for a greenhouse
        // ----------------------------------------------------------------
        case 'get_readings':
            $gh_code = strtoupper($_GET['gh'] ?? 'A');
            if (!in_array($gh_code, ['A', 'B'])) {
                jsonError('Invalid greenhouse code');
            }
            echo json_encode(getLatestReadings($gh_code));
            break;

        // ----------------------------------------------------------------
        // GET: Greenhouse overview (both greenhouses)
        // ----------------------------------------------------------------
        case 'get_overview':
            echo json_encode(getGreenhouseOverview());
            break;

        // ----------------------------------------------------------------
        // GET: Actuator statuses for a greenhouse
        // ----------------------------------------------------------------
        case 'get_actuators':
            $gh_code = strtoupper($_GET['gh'] ?? 'A');
            echo json_encode(getActuators($gh_code));
            break;

        // ----------------------------------------------------------------
        // GET: Sensor hardware statuses for a greenhouse
        // ----------------------------------------------------------------
        case 'get_sensors':
            $gh_code = strtoupper($_GET['gh'] ?? 'A');
            echo json_encode(getSensorStatuses($gh_code));
            break;

        // ----------------------------------------------------------------
        // GET: Open alerts for a greenhouse
        // ----------------------------------------------------------------
        case 'get_alerts':
            $gh_code = strtoupper($_GET['gh'] ?? '');
            echo json_encode(getOpenAlerts($gh_code));
            break;

        // ----------------------------------------------------------------
        // GET: Automation rules (thresholds) for a greenhouse
        // ----------------------------------------------------------------
        case 'get_rules':
            $gh_code = strtoupper($_GET['gh'] ?? 'A');
            echo json_encode(getAutomationRules($gh_code));
            break;

        // ----------------------------------------------------------------
        // POST: Save automation rule thresholds
        // ----------------------------------------------------------------
        case 'save_rules':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            echo json_encode(saveAutomationRules($input));
            break;

        // ----------------------------------------------------------------
        // GET: Historical trend data (last N hours)
        // ----------------------------------------------------------------
        case 'get_trend':
            $gh_code = strtoupper($_GET['gh'] ?? 'A');
            $hours   = min((int)($_GET['hours'] ?? 24), 168); // max 7 days
            echo json_encode(getTrendData($gh_code, $hours));
            break;

        // ----------------------------------------------------------------
        // GET: Plant threshold profile assigned to a greenhouse
        // ----------------------------------------------------------------
        case 'get_plant_thresholds':
            $gh_code = strtoupper($_GET['gh'] ?? 'A');
            echo json_encode(getPlantThresholds($gh_code));
            break;

        default:
            jsonError('Unknown action: ' . htmlspecialchars($action));
    }
} catch (PDOException $e) {
    jsonError('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}

// ============================================================================
// FUNCTIONS
// ============================================================================

function getLatestReadings(string $gh_code): array {
    $db = getDB();

    // Get greenhouse_id
    $stmt = $db->prepare("SELECT greenhouse_id, name, role, assigned_plant_id FROM greenhouses WHERE code = ?");
    $stmt->execute([$gh_code]);
    $gh = $stmt->fetch();
    if (!$gh) return ['error' => 'Greenhouse not found'];

    // Latest reading per parameter from v_latest_readings view
    $stmt = $db->prepare("
        SELECT parameter, value, unit, quality, recorded_at, sensor_label, sensor_status
        FROM v_latest_readings
        WHERE greenhouse_id = ?
        ORDER BY parameter
    ");
    $stmt->execute([$gh['greenhouse_id']]);
    $rows = $stmt->fetchAll();

    // Also get plant thresholds for status evaluation
    $thresholds = [];
    if ($gh['assigned_plant_id']) {
        $stmt = $db->prepare("
            SELECT parameter, val_min, val_opt_low, val_opt_high, val_max, unit
            FROM plant_thresholds
            WHERE plant_id = ?
        ");
        $stmt->execute([$gh['assigned_plant_id']]);
        foreach ($stmt->fetchAll() as $t) {
            $thresholds[$t['parameter']] = $t;
        }
    }

    // Get plant name
    $plant_name = null;
    if ($gh['assigned_plant_id']) {
        $stmt = $db->prepare("SELECT name FROM plants WHERE plant_id = ?");
        $stmt->execute([$gh['assigned_plant_id']]);
        $p = $stmt->fetch();
        $plant_name = $p ? $p['name'] : null;
    }

    // Evaluate status for each reading
    $readings = [];
    foreach ($rows as $row) {
        $status = 'unknown';
        $range_label = '';
        $p = $row['parameter'];

        if (isset($thresholds[$p])) {
            $t = $thresholds[$p];
            $v = (float)$row['value'];
            if ($v >= $t['val_opt_low'] && $v <= $t['val_opt_high']) {
                $status = 'optimal';
                $range_label = $t['val_opt_low'] . '–' . $t['val_opt_high'] . ' ' . $t['unit'];
            } elseif ($v < $t['val_min'] || $v > $t['val_max']) {
                $status = 'critical';
                $range_label = $t['val_opt_low'] . '–' . $t['val_opt_high'] . ' ' . $t['unit'];
            } else {
                $status = 'caution';
                $range_label = $t['val_opt_low'] . '–' . $t['val_opt_high'] . ' ' . $t['unit'];
            }
        }

        $readings[$p] = [
            'value'        => (float)$row['value'],
            'unit'         => $row['unit'],
            'quality'      => $row['quality'],
            'recorded_at'  => $row['recorded_at'],
            'sensor_label' => $row['sensor_label'],
            'sensor_status'=> $row['sensor_status'],
            'status'       => $status,
            'range_label'  => $range_label,
        ];
    }

    return [
        'greenhouse_id'  => $gh['greenhouse_id'],
        'greenhouse_code'=> $gh_code,
        'greenhouse_name'=> $gh['name'],
        'role'           => $gh['role'],
        'plant_name'     => $plant_name,
        'readings'       => $readings,
    ];
}

function getGreenhouseOverview(): array {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM v_greenhouse_status ORDER BY code");
    return $stmt->fetchAll();
}

function getActuators(string $gh_code): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT a.actuator_id, a.actuator_type, a.label, a.status, a.last_changed_at
        FROM actuators a
        JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
        WHERE g.code = ?
        ORDER BY a.actuator_id
    ");
    $stmt->execute([$gh_code]);
    return $stmt->fetchAll();
}

function getSensorStatuses(string $gh_code): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.sensor_id, s.sensor_type, s.label, s.parameter, s.unit,
               s.status, s.last_seen_at, s.firmware_version
        FROM sensors s
        JOIN greenhouses g ON s.greenhouse_id = g.greenhouse_id
        WHERE g.code = ?
        ORDER BY s.sensor_id
    ");
    $stmt->execute([$gh_code]);
    return $stmt->fetchAll();
}

function getOpenAlerts(string $gh_code): array {
    $db = getDB();

    if ($gh_code === '') {
        $stmt = $db->query("SELECT * FROM v_open_alerts LIMIT 20");
    } else {
        $stmt = $db->prepare("
            SELECT a.alert_id, a.severity, a.category, a.message, a.sensor_value, a.created_at,
                   g.code AS greenhouse_code
            FROM alerts a
            LEFT JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
            WHERE g.code = ? AND a.is_resolved = 0
            ORDER BY FIELD(a.severity,'critical','warning','info','success'), a.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$gh_code]);
    }

    return $stmt->fetchAll();
}

function getAutomationRules(string $gh_code): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT r.rule_id, r.parameter, r.trigger_when, r.trigger_value, r.action,
               r.is_enabled, r.description,
               a.actuator_type, a.label AS actuator_label
        FROM automation_rules r
        JOIN actuators a ON r.actuator_id = a.actuator_id
        JOIN greenhouses g ON r.greenhouse_id = g.greenhouse_id
        WHERE g.code = ?
        ORDER BY r.parameter, r.trigger_when
    ");
    $stmt->execute([$gh_code]);
    return $stmt->fetchAll();
}

function saveAutomationRules(array $input): array {
    $db = getDB();

    $gh_code = strtoupper($input['gh_code'] ?? '');
    if (!in_array($gh_code, ['A', 'B'])) {
        return ['success' => false, 'message' => 'Invalid greenhouse code'];
    }

    // Get greenhouse_id
    $stmt = $db->prepare("SELECT greenhouse_id FROM greenhouses WHERE code = ?");
    $stmt->execute([$gh_code]);
    $gh = $stmt->fetch();
    if (!$gh) return ['success' => false, 'message' => 'Greenhouse not found'];

    $rules = $input['rules'] ?? [];
    $updated = 0;

    foreach ($rules as $rule) {
        $rule_id       = (int)($rule['rule_id'] ?? 0);
        $trigger_value = (float)($rule['trigger_value'] ?? 0);
        $is_enabled    = isset($rule['is_enabled']) ? (int)(bool)$rule['is_enabled'] : 1;

        if ($rule_id > 0) {
            $stmt = $db->prepare("
                UPDATE automation_rules
                SET trigger_value = ?, is_enabled = ?, updated_at = NOW()
                WHERE rule_id = ? AND greenhouse_id = ?
            ");
            $stmt->execute([$trigger_value, $is_enabled, $rule_id, $gh['greenhouse_id']]);
            $updated += $stmt->rowCount();
        }
    }

    return ['success' => true, 'message' => "Saved $updated rule(s) for Greenhouse $gh_code"];
}

function getTrendData(string $gh_code, int $hours): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT r.parameter, r.value, r.unit, r.recorded_at, r.quality
        FROM sensor_readings r
        JOIN greenhouses g ON r.greenhouse_id = g.greenhouse_id
        WHERE g.code = ?
          AND r.recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
          AND r.parameter IN ('temperature','humidity','ec','ph','light','water_level')
          AND r.quality = 'good'
        ORDER BY r.parameter, r.recorded_at ASC
    ");
    $stmt->execute([$gh_code, $hours]);
    $rows = $stmt->fetchAll();

    // Group by parameter
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['parameter']][] = [
            'value' => (float)$row['value'],
            'ts'    => $row['recorded_at'],
        ];
    }

    return [
        'greenhouse' => $gh_code,
        'hours'      => $hours,
        'series'     => $grouped,
    ];
}

function getPlantThresholds(string $gh_code): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT p.plant_id, p.name, p.scientific_name, p.emoji, p.photoperiod_hrs,
               pt.parameter, pt.val_min, pt.val_opt_low, pt.val_opt_high, pt.val_max, pt.unit
        FROM greenhouses g
        JOIN plants p ON g.assigned_plant_id = p.plant_id
        JOIN plant_thresholds pt ON p.plant_id = pt.plant_id
        WHERE g.code = ?
        ORDER BY pt.parameter
    ");
    $stmt->execute([$gh_code]);
    $rows = $stmt->fetchAll();

    if (!$rows) return [];

    $plant = [
        'plant_id'        => $rows[0]['plant_id'],
        'name'            => $rows[0]['name'],
        'scientific_name' => $rows[0]['scientific_name'],
        'emoji'           => $rows[0]['emoji'],
        'photoperiod_hrs' => $rows[0]['photoperiod_hrs'],
        'thresholds'      => [],
    ];
    foreach ($rows as $r) {
        $plant['thresholds'][$r['parameter']] = [
            'val_min'      => (float)$r['val_min'],
            'val_opt_low'  => (float)$r['val_opt_low'],
            'val_opt_high' => (float)$r['val_opt_high'],
            'val_max'      => (float)$r['val_max'],
            'unit'         => $r['unit'],
        ];
    }

    return $plant;
}

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
