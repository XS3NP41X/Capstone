<?php
// ============================================================================
// ECOTWIN ESP32 INGEST API
// Receives greenhouse readings from ESP32 and stores them in MySQL.
// Upload this file to InfinityFree at: /htdocs/esp32_api/ingest.php
// ============================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../admin/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-ECOTWIN-KEY');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['success' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $providedKey = $_SERVER['HTTP_X_ECOTWIN_KEY'] ?? ($body['api_key'] ?? ($_POST['api_key'] ?? ''));
    if (!hash_equals(ECOTWIN_ESP32_API_KEY, (string)$providedKey)) {
        jsonResponse(['success' => false, 'error' => 'Invalid ESP32 API key'], 401);
    }

    $ghCode = strtoupper(trim((string)($body['greenhouse'] ?? '')));
    if (!in_array($ghCode, ['A', 'B'], true)) {
        jsonResponse(['success' => false, 'error' => 'Valid greenhouse code is required'], 422);
    }

    $values = is_array($body['values'] ?? null) ? $body['values'] : [
        'temperature' => $body['temperature'] ?? null,
        'humidity' => $body['humidity'] ?? null,
        'light' => $body['light'] ?? null,
        'water_level' => $body['water_level'] ?? ($body['soil'] ?? null),
    ];

    $pdo = getDB();
    $greenhouse = findGreenhouse($pdo, $ghCode);
    if (!$greenhouse) {
        jsonResponse(['success' => false, 'error' => 'Greenhouse not found. Import the full ECOTwin SQL database first.'], 404);
    }

    $sensorMap = loadSensorMap($pdo, (int)$greenhouse['greenhouse_id']);
    if (!$sensorMap) {
        jsonResponse(['success' => false, 'error' => 'No sensors found for this greenhouse. Import sensor seed data first.'], 409);
    }

    $readingColumns = describeTable($pdo, 'sensor_readings');
    if (!$readingColumns) {
        jsonResponse(['success' => false, 'error' => 'sensor_readings table not found. Import the full database first.'], 500);
    }

    $sensorColumns = describeTable($pdo, 'sensors');
    $timestamp = date('Y-m-d H:i:s');
    $insertedRows = 0;
    $skippedParameters = [];

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

        insertDynamicRow($pdo, 'sensor_readings', buildReadingRow(
            $readingColumns,
            $sensor,
            $greenhouse,
            $normalized,
            roundedValue($normalized, (float)$rawValue),
            $timestamp
        ));
        $insertedRows++;
    }
    updateSensorHeartbeat($pdo, $sensorColumns, (int)$greenhouse['greenhouse_id']);
    $pdo->commit();

    jsonResponse([
        'success' => true,
        'greenhouse' => $ghCode,
        'inserted_rows' => $insertedRows,
        'skipped_parameters' => array_values(array_unique($skippedParameters)),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ESP32 ingest error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Server error while saving ESP32 data.'], 500);
}

function findGreenhouse(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT greenhouse_id, code, name, assigned_plant_id FROM greenhouses WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function loadSensorMap(PDO $pdo, int $greenhouseId): array {
    $stmt = $pdo->prepare("SELECT sensor_id, label, parameter, unit FROM sensors WHERE greenhouse_id = ? ORDER BY sensor_id");
    $stmt->execute([$greenhouseId]);

    $map = [];
    foreach ($stmt->fetchAll() as $sensor) {
        $param = normalizeParameter((string)$sensor['parameter']);
        if ($param && !isset($map[$param])) {
            $map[$param] = $sensor;
        }
    }
    return $map;
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
        'soil' => 'water_level',
        'soil_moisture' => 'water_level',
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

function buildReadingRow(array $columns, array $sensor, array $greenhouse, string $parameter, float $value, string $timestamp): array {
    $row = [];
    if (isset($columns['sensor_id'])) $row['sensor_id'] = (int)$sensor['sensor_id'];
    if (isset($columns['greenhouse_id'])) $row['greenhouse_id'] = (int)$greenhouse['greenhouse_id'];
    if (isset($columns['parameter'])) $row['parameter'] = $parameter;
    if (isset($columns['value'])) $row['value'] = $value;
    if (isset($columns['unit'])) $row['unit'] = sensorUnit($sensor, $parameter);
    if (isset($columns['quality'])) $row['quality'] = preferredColumnValue($columns['quality'], ['good', 'valid', 'normal', 'ok']);
    foreach (['recorded_at', 'created_at', 'synced_at', 'logged_at'] as $column) {
        if (isset($columns[$column])) $row[$column] = $timestamp;
    }
    if (isset($columns['reading_source'])) $row['reading_source'] = preferredColumnValue($columns['reading_source'], ['esp32', 'sensor', 'manual']);
    if (isset($columns['source'])) $row['source'] = preferredColumnValue($columns['source'], ['esp32', 'sensor', 'manual']);
    if (isset($columns['notes'])) $row['notes'] = 'ESP32 Wi-Fi portal reading';
    if (isset($columns['remark'])) $row['remark'] = 'ESP32 Wi-Fi portal reading';
    return $row;
}

function sensorUnit(array $sensor, string $parameter): string {
    if (!empty($sensor['unit'])) return (string)$sensor['unit'];
    return [
        'temperature' => 'C',
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
            if (in_array($candidate, $allowed, true)) return $candidate;
        }
        return $allowed[0] ?? null;
    }
    return $preferred[0] ?? null;
}

function roundedValue(string $parameter, float $value): float {
    return match ($parameter) {
        'light', 'water_level' => round($value, 0),
        default => round($value, 1),
    };
}

function insertDynamicRow(PDO $pdo, string $table, array $row): void {
    if (!$row) throw new RuntimeException('No writable columns resolved for ESP32 reading');
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
        if ($status !== null) $updates[] = ['status = ?', $status];
    }
    if (isset($sensorColumns['last_seen_at'])) $updates[] = ['last_seen_at = ?', date('Y-m-d H:i:s')];
    if (!$updates) return;
    $sql = "UPDATE `sensors` SET " . implode(', ', array_column($updates, 0)) . " WHERE greenhouse_id = ?";
    $params = array_map(fn($item) => $item[1], $updates);
    $params[] = $greenhouseId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
