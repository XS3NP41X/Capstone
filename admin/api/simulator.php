<?php
// ============================================================================
// ECOTWIN - Sensor Reading Simulator API  (admin/api/simulator.php)
// Handles: POST simulated sensor readings into the live sensor stream
// ============================================================================

require_once __DIR__ . '/../../auth_guard.php';
require_role('admin');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../config/security.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $pdo = getDB();
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
    }

    $ghCode = strtoupper(trim((string)($body['greenhouse'] ?? '')));
    if (!in_array($ghCode, ['A', 'B'], true)) {
        jsonResponse(['success' => false, 'error' => 'Valid greenhouse code is required'], 422);
    }

    $values = is_array($body['values'] ?? null) ? $body['values'] : [];
    if (!$values) {
        jsonResponse(['success' => false, 'error' => 'At least one reading value is required'], 422);
    }

    $samples = max(1, min(24, (int)($body['samples'] ?? 1)));
    $stepMinutes = max(1, min(60, (int)($body['interval_minutes'] ?? 5)));
    $scenario = trim((string)($body['scenario'] ?? 'custom'));

    $ghStmt = $pdo->prepare("
        SELECT greenhouse_id, code, name, assigned_plant_id
        FROM greenhouses
        WHERE code = ?
        LIMIT 1
    ");
    $ghStmt->execute([$ghCode]);
    $greenhouse = $ghStmt->fetch();

    if (!$greenhouse) {
        jsonResponse(['success' => false, 'error' => 'Greenhouse not found'], 404);
    }

    $sensorStmt = $pdo->prepare("
        SELECT sensor_id, label, parameter, unit
        FROM sensors
        WHERE greenhouse_id = ?
        ORDER BY sensor_id
    ");
    $sensorStmt->execute([(int)$greenhouse['greenhouse_id']]);

    $sensorMap = [];
    foreach ($sensorStmt->fetchAll() as $sensor) {
        $param = normalizeParameter((string)$sensor['parameter']);
        if ($param && !isset($sensorMap[$param])) {
            $sensorMap[$param] = $sensor;
        }
    }

    if (!$sensorMap) {
        jsonResponse(['success' => false, 'error' => 'No sensors found for this greenhouse'], 409);
    }

    $readingColumns = describeTable($pdo, 'sensor_readings');
    if (!$readingColumns) {
        jsonResponse(['success' => false, 'error' => 'Unable to inspect sensor_readings table'], 500);
    }

    $sensorColumns = describeTable($pdo, 'sensors');
    $insertedRows = 0;
    $skippedParameters = [];

    $requiredMissing = detectMissingRequiredColumns($readingColumns, [
        'sensor_id', 'greenhouse_id', 'parameter', 'value', 'unit',
        'quality', 'recorded_at', 'created_at', 'synced_at', 'logged_at',
        'reading_source', 'source', 'notes', 'remark'
    ]);
    if ($requiredMissing) {
        jsonResponse([
            'success' => false,
            'error' => 'Simulator cannot write to sensor_readings because required columns are unsupported: ' . implode(', ', $requiredMissing)
        ], 500);
    }

    $pdo->beginTransaction();

    foreach ($values as $parameter => $rawValue) {
        $normalized = normalizeParameter((string)$parameter);
        if (!$normalized || !is_numeric($rawValue)) {
            continue;
        }

        $sensor = $sensorMap[$normalized] ?? null;
        if (!$sensor) {
            $skippedParameters[] = $normalized;
            continue;
        }

        for ($i = 0; $i < $samples; $i++) {
            $offset = ($samples - $i - 1) * $stepMinutes;
            $timestamp = date('Y-m-d H:i:s', time() - ($offset * 60));
            $value = jitterValue($normalized, (float)$rawValue, $i);

            $row = buildReadingRow(
                $readingColumns,
                $sensor,
                $greenhouse,
                $normalized,
                $value,
                $timestamp,
                $scenario
            );

            insertDynamicRow($pdo, 'sensor_readings', $row);
            $insertedRows++;
        }
    }

    updateSensorHeartbeat($pdo, $sensorColumns, (int)$greenhouse['greenhouse_id']);

    $pdo->commit();
    log_activity_event((int)($_SESSION['user_id'] ?? 0), 'simulator', 'push_readings', "Pushed {$insertedRows} simulated reading rows for greenhouse {$ghCode}", 'greenhouse', $ghCode);

    jsonResponse([
        'success' => true,
        'inserted_rows' => $insertedRows,
        'skipped_parameters' => array_values(array_unique($skippedParameters)),
        'greenhouse' => $ghCode,
        'scenario' => $scenario
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

function normalizeParameter(string $parameter): ?string {
    $parameter = strtolower(trim($parameter));
    $aliases = [
        'temperature' => 'temperature',
        'temp' => 'temperature',
        'humidity' => 'humidity',
        'hum' => 'humidity',
        'ph' => 'ph',
        'ec' => 'ec',
        'tds' => 'ec',
        'light' => 'light',
        'lux' => 'light',
        'water_level' => 'water_level',
        'water level' => 'water_level',
        'water' => 'water_level',
    ];

    return $aliases[$parameter] ?? null;
}

function describeTable(PDO $pdo, string $table): array {
    $stmt = $pdo->query("DESCRIBE `$table`");
    $rows = $stmt ? $stmt->fetchAll() : [];
    $out = [];
    foreach ($rows as $row) {
        $out[$row['Field']] = $row;
    }
    return $out;
}

function detectMissingRequiredColumns(array $columns, array $supported): array {
    $missing = [];
    foreach ($columns as $name => $meta) {
        $hasDefault = array_key_exists('Default', $meta) && $meta['Default'] !== null;
        $isAuto = stripos((string)($meta['Extra'] ?? ''), 'auto_increment') !== false;
        $isNullable = ($meta['Null'] ?? 'NO') === 'YES';

        if (!$hasDefault && !$isAuto && !$isNullable && !in_array($name, $supported, true)) {
            $missing[] = $name;
        }
    }
    return $missing;
}

function buildReadingRow(
    array $columns,
    array $sensor,
    array $greenhouse,
    string $parameter,
    float $value,
    string $timestamp,
    string $scenario
): array {
    $row = [];

    if (isset($columns['sensor_id'])) {
        $row['sensor_id'] = (int)$sensor['sensor_id'];
    }
    if (isset($columns['greenhouse_id'])) {
        $row['greenhouse_id'] = (int)$greenhouse['greenhouse_id'];
    }
    if (isset($columns['parameter'])) {
        $row['parameter'] = $parameter;
    }
    if (isset($columns['value'])) {
        $row['value'] = $value;
    }
    if (isset($columns['unit'])) {
        $row['unit'] = sensorUnit($sensor, $parameter);
    }
    if (isset($columns['quality'])) {
        $row['quality'] = preferredColumnValue($columns['quality'], ['simulated', 'good', 'valid', 'normal', 'ok']);
    }
    if (isset($columns['recorded_at'])) {
        $row['recorded_at'] = $timestamp;
    }
    if (isset($columns['created_at'])) {
        $row['created_at'] = $timestamp;
    }
    if (isset($columns['synced_at'])) {
        $row['synced_at'] = $timestamp;
    }
    if (isset($columns['logged_at'])) {
        $row['logged_at'] = $timestamp;
    }
    if (isset($columns['reading_source'])) {
        $row['reading_source'] = preferredColumnValue($columns['reading_source'], ['simulator', 'manual', 'admin']);
    }
    if (isset($columns['source'])) {
        $row['source'] = preferredColumnValue($columns['source'], ['simulator', 'manual', 'admin']);
    }
    if (isset($columns['notes'])) {
        $row['notes'] = "Admin simulator ($scenario)";
    }
    if (isset($columns['remark'])) {
        $row['remark'] = "Admin simulator ($scenario)";
    }

    return $row;
}

function sensorUnit(array $sensor, string $parameter): string {
    if (!empty($sensor['unit'])) {
        return (string)$sensor['unit'];
    }

    return [
        'temperature' => '°C',
        'humidity' => '%',
        'ph' => 'pH',
        'ec' => 'mS/cm',
        'light' => 'lux',
        'water_level' => '%',
    ][$parameter] ?? '';
}

function preferredColumnValue(array $columnMeta, array $preferred): ?string {
    $type = strtolower((string)($columnMeta['Type'] ?? ''));
    if (str_starts_with($type, 'enum(')) {
        preg_match_all("/'([^']+)'/", $type, $matches);
        $allowed = $matches[1] ?? [];
        foreach ($preferred as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }
        return $allowed[0] ?? null;
    }

    return $preferred[0] ?? null;
}

function jitterValue(string $parameter, float $value, int $sampleIndex): float {
    $ranges = [
        'temperature' => 0.35,
        'humidity' => 1.2,
        'ph' => 0.08,
        'ec' => 0.06,
        'light' => 350,
        'water_level' => 1.0,
    ];
    $range = $ranges[$parameter] ?? 0;
    if ($sampleIndex === 0 || $range <= 0) {
        return roundedValue($parameter, $value);
    }

    $seed = (($sampleIndex % 3) - 1) * $range;
    return roundedValue($parameter, $value + $seed);
}

function roundedValue(string $parameter, float $value): float {
    return match ($parameter) {
        'light', 'water_level' => round($value, 0),
        default => round($value, 1),
    };
}

function insertDynamicRow(PDO $pdo, string $table, array $row): void {
    if (!$row) {
        throw new RuntimeException('No writable columns resolved for simulated reading');
    }

    $columns = array_keys($row);
    $quoted = array_map(fn($col) => "`$col`", $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO `$table` (" . implode(', ', $quoted) . ") VALUES ($placeholders)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($row));
}

function updateSensorHeartbeat(PDO $pdo, array $sensorColumns, int $greenhouseId): void {
    $updates = [];

    if (isset($sensorColumns['status'])) {
        $status = preferredColumnValue($sensorColumns['status'], ['online', 'active', 'connected']);
        if ($status !== null) {
            $updates[] = ['status = ?', $status];
        }
    }
    if (isset($sensorColumns['last_seen_at'])) {
        $updates[] = ['last_seen_at = ?', date('Y-m-d H:i:s')];
    }

    if (!$updates) {
        return;
    }

    $sql = "UPDATE `sensors` SET " . implode(', ', array_column($updates, 0)) . " WHERE greenhouse_id = ?";
    $params = array_map(fn($item) => $item[1], $updates);
    $params[] = $greenhouseId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
