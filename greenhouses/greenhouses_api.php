<?php
// ============================================================================
// ECOTWIN GREENHOUSES API
// Handles AJAX requests from greenhouses.php
// ============================================================================

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../config/security.php';

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

        case 'toggle_actuator':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            echo json_encode(toggleActuatorState($input));
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

/**
 * Builds the latest sensor snapshot for one greenhouse and tags each reading
 * with a threshold-based status so UI issues are easier to trace.
 */
function getLatestReadings(string $gh_code): array {
    $db = getDB();

    // Resolve the greenhouse first because every follow-up query depends on its id.
    $stmt = $db->prepare("SELECT greenhouse_id, name, role, assigned_plant_id FROM greenhouses WHERE code = ?");
    $stmt->execute([$gh_code]);
    $gh = $stmt->fetch();
    if (!$gh) return ['error' => 'Greenhouse not found'];

    // Pull the newest reading per parameter from the reporting view used by the dashboard.
    $stmt = $db->prepare("
        SELECT parameter, value, unit, quality, recorded_at, sensor_label, sensor_status
        FROM v_latest_readings
        WHERE greenhouse_id = ?
        ORDER BY parameter
    ");
    $stmt->execute([$gh['greenhouse_id']]);
    $rows = $stmt->fetchAll();

    // Index thresholds by parameter so status checks are easy to follow in the loop below.
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

    // Load the assigned plant name separately because the response shows it beside the readings.
    $plant_name = null;
    if ($gh['assigned_plant_id']) {
        $stmt = $db->prepare("SELECT name FROM plants WHERE plant_id = ?");
        $stmt->execute([$gh['assigned_plant_id']]);
        $p = $stmt->fetch();
        $plant_name = $p ? $p['name'] : null;
    }

    // Convert the raw rows into the keyed payload expected by the frontend cards.
    $readings = [];
    foreach ($rows as $row) {
        $status = 'unknown';
        $range_label = '';
        $p = $row['parameter'];

        // Compare each value against the plant profile so debugging can distinguish caution vs critical.
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

/**
 * Returns the combined greenhouse status view used by the overview widgets.
 */
function getGreenhouseOverview(): array {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM v_greenhouse_status ORDER BY code");
    return $stmt->fetchAll();
}

/**
 * Lists manual-control actuators for one greenhouse and hides system-only devices.
 */
function getActuators(string $gh_code): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT a.actuator_id, a.actuator_type, a.label, a.status, a.last_changed_at
        FROM actuators a
        JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
        WHERE g.code = ?
          AND a.actuator_type NOT IN ('ph_pump_up', 'ph_pump_down', 'water_refill_pump')
        ORDER BY a.actuator_id
    ");
    $stmt->execute([$gh_code]);
    return $stmt->fetchAll();
}

/**
 * Returns sensor hardware details so the UI can show health and firmware status.
 */
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

/**
 * Fetches unresolved alerts either globally or for a single greenhouse filter.
 */
function getOpenAlerts(string $gh_code): array {
    $db = getDB();

    // An empty greenhouse code means the dashboard wants a short global alert feed.
    if ($gh_code === '') {
        $stmt = $db->query("SELECT * FROM v_open_alerts LIMIT 20");
    } else {
        // Otherwise return only the latest open alerts for the requested greenhouse.
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

/**
 * Loads automation thresholds and the linked actuator details for one greenhouse.
 */
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
          AND a.actuator_type NOT IN ('ph_pump_up', 'ph_pump_down', 'water_refill_pump')
        ORDER BY r.parameter, r.trigger_when
    ");
    $stmt->execute([$gh_code]);
    return $stmt->fetchAll();
}

/**
 * Persists rule threshold edits coming from the automation settings form.
 */
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

    // Only update existing rules; this endpoint does not create new automation rules.
    $rules = $input['rules'] ?? [];
    $updated = 0;

    foreach ($rules as $rule) {
        $rule_id       = (int)($rule['rule_id'] ?? 0);
        $trigger_value = (float)($rule['trigger_value'] ?? 0);
        $is_enabled    = isset($rule['is_enabled']) ? (int)(bool)$rule['is_enabled'] : 1;

        // Scope the update by greenhouse_id as a safety check for mismatched rule ids.
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

/**
 * Flips the selected manual control group on or off and records every change in actuator_logs.
 */
function toggleActuatorState(array $input): array {
    $db = getDB();

    $gh_code = strtoupper((string)($input['gh'] ?? ''));
    $target = strtolower((string)($input['target'] ?? ''));
    if (!in_array($gh_code, ['A', 'B'], true)) {
        return ['success' => false, 'message' => 'Invalid greenhouse code'];
    }

    $targetMap = [
        'pump' => ['nutrient_pump'],
        'fan' => ['exhaust_fan', 'circulation_fan'],
        'shading' => ['shading_net'],
    ];
    if (!isset($targetMap[$target])) {
        return ['success' => false, 'message' => 'Invalid actuator target'];
    }

    // Block manual overrides while an experiment is running to avoid conflicting automation.
    $activeExperiment = $db->query("SELECT 1 FROM experiments WHERE status = 'active' LIMIT 1")->fetchColumn();
    if ($activeExperiment) {
        return ['success' => false, 'message' => 'Manual controls are locked while an experiment is active'];
    }

    // Load every actuator tied to the requested control so grouped toggles stay in sync.
    $placeholders = implode(',', array_fill(0, count($targetMap[$target]), '?'));
    $params = array_merge([$gh_code], $targetMap[$target]);
    $stmt = $db->prepare("
        SELECT a.actuator_id, a.greenhouse_id, a.actuator_type, a.label, a.status
        FROM actuators a
        JOIN greenhouses g ON g.greenhouse_id = a.greenhouse_id
        WHERE g.code = ?
          AND a.actuator_type IN ($placeholders)
        ORDER BY a.actuator_id
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return ['success' => false, 'message' => 'No actuator found for this control'];
    }

    // If any member of the group is already on, switch the whole group off; otherwise turn it on.
    $nextStatus = 'on';
    foreach ($rows as $row) {
        if (($row['status'] ?? 'off') === 'on') {
            $nextStatus = 'off';
            break;
        }
    }

    $userId = currentSessionUserId();
    $updated = 0;

    // Update actuator states and log entries together so the audit trail matches the final state.
    $db->beginTransaction();
    try {
        $update = $db->prepare("UPDATE actuators SET status = ?, last_changed_at = NOW() WHERE actuator_id = ?");
        $insertLog = $db->prepare("
            INSERT INTO actuator_logs (
                actuator_id, greenhouse_id, experiment_id, old_status, new_status,
                trigger_type, triggered_by, rule_id, reading_value, notes, logged_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($rows as $row) {
            $oldStatus = normalizeActuatorStatus($row['status'] ?? null);
            if ($oldStatus === $nextStatus) {
                continue;
            }

            // Skip already-correct rows so repeated clicks do not create noisy duplicate logs.
            $update->execute([$nextStatus, $row['actuator_id']]);
            $insertLog->execute([
                (int)$row['actuator_id'],
                (int)$row['greenhouse_id'],
                null,
                $oldStatus,
                $nextStatus,
                'manual',
                $userId,
                null,
                null,
                buildActuatorLogNote($gh_code, $target, $row),
            ]);
            $updated++;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // Mirror the manual toggle in the activity log when the optional helper is available.
    if ($updated > 0 && function_exists('log_activity_event')) {
        $detail = 'Greenhouse ' . $gh_code . ' ' . ucfirst($target) . ' set to ' . strtoupper($nextStatus);
        if ($updated > 1) {
            $detail .= ' (' . $updated . ' actuators updated)';
        }
        log_activity_event($userId, 'actuators', 'manual_toggle', $detail, 'greenhouse', (int)$rows[0]['greenhouse_id']);
    }

    return [
        'success' => true,
        'message' => 'Greenhouse ' . $gh_code . ' ' . ucfirst($target) . ' set to ' . strtoupper($nextStatus),
        'status' => $nextStatus,
        'updated' => $updated,
    ];
}

/**
 * Returns recent good-quality readings grouped by parameter for chart rendering.
 */
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

    // Keep the response grouped so the frontend can bind each series directly to one chart line.
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

/**
 * Returns the plant profile assigned to a greenhouse together with its threshold ranges.
 */
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

    // No rows usually means the greenhouse has no assigned plant profile yet.
    if (!$rows) return [];

    // Build the plant metadata once, then attach threshold entries by parameter.
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

/**
 * Returns the logged-in user id when the session is valid, otherwise null.
 */
function currentSessionUserId(): ?int {
    $userId = $_SESSION['user_id'] ?? null;
    return is_numeric($userId) && (int)$userId > 0 ? (int)$userId : null;
}

/**
 * Normalizes actuator status values before they are written to the audit log.
 */
function normalizeActuatorStatus($status): ?string {
    $allowed = ['on', 'off', 'auto', 'fault', 'maintenance'];
    return is_string($status) && in_array($status, $allowed, true) ? $status : null;
}

/**
 * Builds a short actuator log note that still fits inside the database column.
 */
function buildActuatorLogNote(string $gh_code, string $target, array $row): string {
    $label = trim((string)($row['label'] ?? ''));
    $type = trim((string)($row['actuator_type'] ?? 'actuator'));
    $detail = $label !== '' ? $label : $type;
    return substr(
        'Manual toggle via greenhouse controls for GH-' . $gh_code . ' ' . $target . ' (' . $detail . ')',
        0,
        255
    );
}

/**
 * Sends a JSON error response and stops execution so no extra output corrupts the payload.
 */
function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
