<?php
// ============================================================================
// ECOTWIN - Hardware Ingest API
// Receives live Arduino/ESP32 sensor status from the ECOTwin bridge.
// ============================================================================

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

const ECOTWIN_INGEST_TOKEN = 'ecotwin-hardware-2026';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$token = $_SERVER['HTTP_X_ECOTWIN_TOKEN'] ?? ($_GET['token'] ?? '');
if (!hash_equals(ECOTWIN_INGEST_TOKEN, (string)$token)) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized hardware token'], 401);
}

try {
    $pdo = getDB();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
    }

    $greenhouseCode = strtoupper(trim((string)($body['greenhouse'] ?? 'A')));
    $greenhouse = fetchGreenhouse($pdo, $greenhouseCode);
    if (!$greenhouse) {
        jsonResponse(['success' => false, 'error' => 'Greenhouse not found'], 404);
    }

    $now = date('Y-m-d H:i:s');
    $hardwareColumns = describeTable($pdo, 'hardware_components');
    $sensorColumns = describeTable($pdo, 'sensors');
    $readingColumns = describeTable($pdo, 'sensor_readings');

    $pdo->beginTransaction();

    $hardwareUpdated = 0;
    $reportedSensorParams = [];
    foreach (($body['hardware'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $hardwareStatus = normalizeStatus((string)($item['status'] ?? 'offline'));
        upsertHardware($pdo, $hardwareColumns, [
            'label' => (string)($item['label'] ?? ''),
            'type' => normalizeHardwareType((string)($item['type'] ?? 'other'), (string)($item['label'] ?? '')),
            'status' => $hardwareStatus,
            'model' => (string)($item['model'] ?? ''),
            'firmware_version' => (string)($item['firmware_version'] ?? ''),
        ], $now);
        foreach (parametersForHardwareLabel((string)($item['label'] ?? '')) as $parameter) {
            $reportedSensorParams[$parameter] = true;
            updateSensorStatus($pdo, $sensorColumns, $greenhouse, $parameter, $hardwareStatus, $now);
        }
        $hardwareUpdated++;
    }

    $readingsInserted = 0;
    foreach (($body['readings'] ?? []) as $parameter => $value) {
        $parameter = normalizeParameter((string)$parameter);
        if ($parameter === null || !is_numeric($value)) {
            continue;
        }

        $reportedSensorParams[$parameter] = true;
        $sensor = upsertSensor($pdo, $sensorColumns, $greenhouse, $parameter, $now);
        insertReading($pdo, $readingColumns, $sensor, $greenhouse, $parameter, (float)$value, $now);
        $readingsInserted++;
    }

    markUnreportedSensorsOffline($pdo, $sensorColumns, $greenhouse, array_keys($reportedSensorParams), $now);

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'hardware_updated' => $hardwareUpdated,
        'readings_inserted' => $readingsInserted,
        'greenhouse' => $greenhouseCode,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

// Handles fetch greenhouse.
function fetchGreenhouse(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT greenhouse_id, code FROM greenhouses WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Handles describe table.
function describeTable(PDO $pdo, string $table): array {
    $stmt = $pdo->query("DESCRIBE `$table`");
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[$row['Field']] = $row;
    }
    return $columns;
}

// Handles normalize status.
function normalizeStatus(string $status): string {
    $status = strtolower(trim($status));
    return in_array($status, ['online', 'offline', 'degraded', 'maintenance'], true) ? $status : 'offline';
}

// Handles normalize hardware status.
function normalizeHardwareStatus(string $status): string {
    $status = strtolower(trim($status));
    return in_array($status, ['online', 'offline', 'degraded'], true) ? $status : 'offline';
}

// Handles normalize hardware type.
function normalizeHardwareType(string $type, string $label = ''): string {
    $type = strtolower(trim($type));
    $label = strtolower(trim($label));

    if (str_contains($label, 'arduino')) return 'arduino';
    if (str_contains($label, 'esp32')) return 'esp32';
    if (str_contains($label, 'nrf')) return 'nrf_module';
    if (str_contains($label, 'relay')) return 'relay';
    if (str_contains($label, 'power')) return 'power_supply';

    return [
        'arduino' => 'arduino',
        'controller' => 'arduino',
        'esp32' => 'esp32',
        'gateway' => 'esp32',
        'nrf' => 'nrf_module',
        'nrf_module' => 'nrf_module',
        'wireless' => 'nrf_module',
        'relay' => 'relay',
        'power_supply' => 'power_supply',
        'ups' => 'ups',
        'sensor' => 'other',
        'actuator' => 'other',
        'other' => 'other',
    ][$type] ?? 'other';
}

// Handles normalize parameter.
function normalizeParameter(string $parameter): ?string {
    $parameter = strtolower(trim($parameter));
    return [
        'temperature' => 'temperature',
        'air_temperature' => 'temperature',
        'humidity' => 'humidity',
        'water_temp' => 'water_temp',
        'water_temperature' => 'water_temp',
        'ph' => 'ph',
        'ec' => 'ec',
        'tds' => 'ec',
        'light' => 'light',
        'water_level' => 'water_level',
        'waterlevel' => 'water_level',
    ][$parameter] ?? null;
}

// Handles unit for.
function unitFor(string $parameter): string {
    return [
        'temperature' => 'C',
        'humidity' => '%',
        'water_temp' => 'C',
        'ph' => 'pH',
        'ec' => 'ppm',
        'light' => 'raw',
        'water_level' => 'raw',
    ][$parameter] ?? '';
}

// Handles sensor type for.
function sensorTypeFor(string $parameter): string {
    return [
        'temperature' => 'DHT22',
        'humidity' => 'DHT22',
        'water_temp' => 'DS18B20',
        'ph' => 'PH',
        'ec' => 'EC_TDS',
        'light' => 'LDR',
        'water_level' => 'WATER_LEVEL',
    ][$parameter] ?? 'DHT22';
}

// Handles sensor label for.
function sensorLabelFor(string $parameter): string {
    return [
        'temperature' => 'DHT22 Air Temperature',
        'humidity' => 'DHT22 Humidity',
        'water_temp' => 'DS18B20 Water Temperature',
        'ph' => 'pH Sensor',
        'ec' => 'TDS/EC Sensor',
        'light' => 'LDR Light Sensor',
        'water_level' => 'Water Level Sensor',
    ][$parameter] ?? ucfirst($parameter);
}

// Handles parameters for hardware label.
function parametersForHardwareLabel(string $label): array {
    $label = strtolower(trim($label));
    if (str_contains($label, 'dht22')) return ['temperature', 'humidity'];
    if (str_contains($label, 'ds18b20')) return ['water_temp'];
    if (str_contains($label, 'tds') || str_contains($label, 'ec/tds')) return ['ec'];
    if (str_contains($label, 'ph sensor') || str_contains($label, 'ph kit')) return ['ph'];
    if (str_contains($label, 'ldr') || str_contains($label, 'light sensor')) return ['light'];
    if (str_contains($label, 'water level')) return ['water_level'];
    return [];
}

// Handles update sensor status.
function updateSensorStatus(PDO $pdo, array $columns, array $greenhouse, string $parameter, string $status, string $now): void {
    if (!isset($columns['status'])) {
        return;
    }

    $sets = ['status = ?'];
    $params = [$status === 'maintenance' ? 'offline' : $status];
    if (isset($columns['last_seen_at']) && $status === 'online') {
        $sets[] = 'last_seen_at = ?';
        $params[] = $now;
    }
    if (isset($columns['updated_at'])) {
        $sets[] = 'updated_at = ?';
        $params[] = $now;
    }
    $params[] = (int)$greenhouse['greenhouse_id'];
    $params[] = $parameter;

    $pdo->prepare(
        "UPDATE sensors SET " . implode(', ', $sets) . " WHERE greenhouse_id = ? AND parameter = ?"
    )->execute($params);
}

// Handles mark unreported sensors offline.
function markUnreportedSensorsOffline(PDO $pdo, array $columns, array $greenhouse, array $reportedParams, string $now): void {
    if (!isset($columns['status'])) {
        return;
    }

    $knownParams = ['temperature', 'humidity', 'water_temp', 'ph', 'ec', 'light', 'water_level'];
    $missing = array_values(array_diff($knownParams, $reportedParams));
    if (!$missing) {
        return;
    }

    $sets = ['status = ?'];
    $params = ['offline'];
    if (isset($columns['updated_at'])) {
        $sets[] = 'updated_at = ?';
        $params[] = $now;
    }

    $placeholders = implode(',', array_fill(0, count($missing), '?'));
    $params[] = (int)$greenhouse['greenhouse_id'];
    array_push($params, ...$missing);

    $pdo->prepare(
        "UPDATE sensors SET " . implode(', ', $sets) . " WHERE greenhouse_id = ? AND parameter IN ($placeholders)"
    )->execute($params);
}

// Handles upsert hardware.
function upsertHardware(PDO $pdo, array $columns, array $item, string $now): void {
    $label = trim($item['label']);
    if ($label === '' || !isset($columns['label'])) {
        return;
    }

    $stmt = $pdo->prepare("SELECT component_id FROM hardware_components WHERE label = ? LIMIT 1");
    $stmt->execute([$label]);
    $id = $stmt->fetchColumn();

    $row = ['label' => $label];
    foreach (['type', 'status', 'model', 'firmware_version'] as $field) {
        if (isset($columns[$field]) && $item[$field] !== '') {
            $row[$field] = $field === 'status' ? normalizeHardwareStatus((string)$item[$field]) : $item[$field];
        }
    }
    if (isset($columns['last_seen_at'])) {
        $row['last_seen_at'] = $now;
    }
    if (isset($columns['updated_at'])) {
        $row['updated_at'] = $now;
    }

    if ($id) {
        $sets = [];
        $params = [];
        foreach ($row as $field => $value) {
            if ($field === 'label') {
                continue;
            }
            $sets[] = "`$field` = ?";
            $params[] = $value;
        }
        if ($sets) {
            $params[] = $id;
            $pdo->prepare("UPDATE hardware_components SET " . implode(', ', $sets) . " WHERE component_id = ?")->execute($params);
        }
        return;
    }

    if (isset($columns['created_at'])) {
        $row['created_at'] = $now;
    }
    insertDynamic($pdo, 'hardware_components', $row);
}

// Handles upsert sensor.
function upsertSensor(PDO $pdo, array $columns, array $greenhouse, string $parameter, string $now): array {
    $label = sensorLabelFor($parameter);
    $stmt = $pdo->prepare("SELECT * FROM sensors WHERE greenhouse_id = ? AND parameter = ? LIMIT 1");
    $stmt->execute([(int)$greenhouse['greenhouse_id'], $parameter]);
    $sensor = $stmt->fetch();

    $row = [];
    foreach ([
        'greenhouse_id' => (int)$greenhouse['greenhouse_id'],
        'sensor_type' => sensorTypeFor($parameter),
        'label' => $label,
        'parameter' => $parameter,
        'unit' => unitFor($parameter),
        'status' => 'online',
        'last_seen_at' => $now,
        'updated_at' => $now,
    ] as $field => $value) {
        if (isset($columns[$field])) {
            $row[$field] = $value;
        }
    }

    if ($sensor) {
        $sets = [];
        $params = [];
        foreach ($row as $field => $value) {
            $sets[] = "`$field` = ?";
            $params[] = $value;
        }
        $params[] = (int)$sensor['sensor_id'];
        $pdo->prepare("UPDATE sensors SET " . implode(', ', $sets) . " WHERE sensor_id = ?")->execute($params);
        $sensor['sensor_id'] = (int)$sensor['sensor_id'];
        return $sensor;
    }

    if (isset($columns['created_at'])) {
        $row['created_at'] = $now;
    }
    insertDynamic($pdo, 'sensors', $row);
    return ['sensor_id' => (int)$pdo->lastInsertId()];
}

// Handles insert reading.
function insertReading(PDO $pdo, array $columns, array $sensor, array $greenhouse, string $parameter, float $value, string $now): void {
    $row = [];
    foreach ([
        'sensor_id' => (int)$sensor['sensor_id'],
        'greenhouse_id' => (int)$greenhouse['greenhouse_id'],
        'parameter' => $parameter,
        'value' => round($value, 2),
        'unit' => unitFor($parameter),
        'quality' => 'good',
        'recorded_at' => $now,
        'created_at' => $now,
        'synced_at' => $now,
        'reading_source' => 'hardware',
        'source' => 'hardware',
    ] as $field => $value) {
        if (isset($columns[$field])) {
            $row[$field] = $value;
        }
    }
    insertDynamic($pdo, 'sensor_readings', $row);
}

// Handles insert dynamic.
function insertDynamic(PDO $pdo, string $table, array $row): void {
    if (!$row) {
        return;
    }
    $columns = array_keys($row);
    $quoted = array_map(fn($field) => "`$field`", $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $pdo->prepare("INSERT INTO `$table` (" . implode(', ', $quoted) . ") VALUES ($placeholders)")
        ->execute(array_values($row));
}
