<?php
// ============================================================================
// ECOTWIN - QUERY HELPERS
// Plain SQL replacements for optional database views.
// ============================================================================

// Handles ecotwin fetch active experiment.
function ecotwinFetchActiveExperiment(PDO $db): ?array
{
    $stmt = $db->query("
        SELECT e.experiment_id,
               e.exp_code,
               e.title,
               e.status,
               e.principal_user_id,
               e.started_at,
               e.expected_end_at,
               TIMESTAMPDIFF(HOUR, e.started_at, CURRENT_TIMESTAMP()) AS hours_running,
               u.full_name AS principal_researcher,
               u.email AS researcher_email
        FROM experiments e
        JOIN users u ON e.principal_user_id = u.user_id
        WHERE e.status = 'active'
        ORDER BY e.started_at DESC, e.experiment_id DESC
        LIMIT 1
    ");

    $row = $stmt->fetch();
    return $row ?: null;
}

// Handles ecotwin fetch latest readings.
function ecotwinFetchLatestReadings(PDO $db, int $greenhouseId, array $parameters = []): array
{
    $sql = "
        SELECT sr.greenhouse_id,
               sr.parameter,
               sr.value,
               sr.unit,
               sr.quality,
               sr.recorded_at,
               s.label AS sensor_label,
               s.status AS sensor_status
        FROM sensor_readings sr
        JOIN sensors s ON s.sensor_id = sr.sensor_id
        WHERE sr.greenhouse_id = ?
    ";

    $bind = [$greenhouseId];
    if ($parameters) {
        $sql .= " AND sr.parameter IN (" . implode(',', array_fill(0, count($parameters), '?')) . ")";
        array_push($bind, ...$parameters);
    }

    $sql .= "
        AND NOT EXISTS (
            SELECT 1
            FROM sensor_readings newer
            WHERE newer.greenhouse_id = sr.greenhouse_id
              AND newer.parameter = sr.parameter
              AND (
                  newer.recorded_at > sr.recorded_at
                  OR (newer.recorded_at = sr.recorded_at AND newer.reading_id > sr.reading_id)
              )
        )
        ORDER BY sr.parameter
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetchAll();
}

// Handles ecotwin fetch latest readings map.
function ecotwinFetchLatestReadingsMap(PDO $db, int $greenhouseId, array $parameters = []): array
{
    $mapped = [];
    foreach (ecotwinFetchLatestReadings($db, $greenhouseId, $parameters) as $row) {
        $mapped[$row['parameter']] = $row;
    }
    return $mapped;
}

// Handles ecotwin fetch greenhouse overview.
function ecotwinFetchGreenhouseOverview(PDO $db): array
{
    $stmt = $db->query("
        SELECT g.greenhouse_id,
               g.code,
               g.name,
               g.role,
               g.assigned_plant_id,
               p.name AS plant_name,
               p.name AS assigned_plant,
               p.emoji AS plant_emoji,
               (SELECT COUNT(*) FROM sensors s WHERE s.greenhouse_id = g.greenhouse_id) AS sensors_total,
               (SELECT COUNT(*) FROM sensors s WHERE s.greenhouse_id = g.greenhouse_id AND s.status = 'online') AS sensors_online,
               (SELECT COUNT(*) FROM alerts a WHERE a.greenhouse_id = g.greenhouse_id AND a.is_resolved = 0 AND a.severity = 'critical') AS open_critical_alerts
        FROM greenhouses g
        LEFT JOIN plants p ON p.plant_id = g.assigned_plant_id
        ORDER BY g.code
    ");

    return $stmt->fetchAll();
}

// Handles ecotwin fetch open alerts.
function ecotwinFetchOpenAlerts(PDO $db, string $greenhouseCode = '', int $limit = 20): array
{
    $sql = "
        SELECT a.alert_id,
               a.severity,
               a.category,
               a.message,
               a.sensor_value,
               a.created_at,
               g.code AS greenhouse_code,
               g.name AS greenhouse_name
        FROM alerts a
        LEFT JOIN greenhouses g ON g.greenhouse_id = a.greenhouse_id
        WHERE a.is_resolved = 0
    ";
    $params = [];

    if ($greenhouseCode !== '') {
        $sql .= " AND g.code = ?";
        $params[] = strtoupper($greenhouseCode);
    }

    $sql .= "
        ORDER BY FIELD(a.severity, 'critical', 'warning', 'info', 'success'), a.created_at DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Builds the same threshold-based live event feed used by the Reports page. */
function ecotwinBuildLiveIssueEvents(PDO $db, string $greenhouseCode = 'all', string $severity = 'all'): array
{
    if (!in_array($severity, ['all', 'critical', 'warning'], true)) return [];

    $sql = 'SELECT greenhouse_id, code, name, assigned_plant_id FROM greenhouses';
    $params = [];
    if ($greenhouseCode !== 'all') {
        $sql .= ' WHERE code = ?';
        $params[] = strtoupper($greenhouseCode);
    }
    $stmt = $db->prepare($sql . ' ORDER BY code');
    $stmt->execute($params);
    $events = [];

    foreach ($stmt->fetchAll() as $greenhouse) {
        if (empty($greenhouse['assigned_plant_id'])) continue;
        $thresholdStmt = $db->prepare('SELECT parameter, val_min, val_opt_low, val_opt_high, val_max FROM plant_thresholds WHERE plant_id = ?');
        $thresholdStmt->execute([(int)$greenhouse['assigned_plant_id']]);
        $thresholds = [];
        foreach ($thresholdStmt->fetchAll() as $threshold) $thresholds[$threshold['parameter']] = $threshold;

        foreach (ecotwinFetchLatestReadings($db, (int)$greenhouse['greenhouse_id']) as $reading) {
            $parameter = (string)$reading['parameter'];
            if (!isset($thresholds[$parameter])) continue;
            $limit = $thresholds[$parameter];
            $value = (float)$reading['value'];
            $eventSeverity = null;
            if ($value < (float)$limit['val_min'] || $value > (float)$limit['val_max']) $eventSeverity = 'critical';
            elseif ($value < (float)$limit['val_opt_low'] || $value > (float)$limit['val_opt_high']) $eventSeverity = 'warning';
            if ($eventSeverity === null || ($severity !== 'all' && $severity !== $eventSeverity)) continue;

            $events[] = [
                'alert_id' => 'live-' . $greenhouse['code'] . '-' . $parameter,
                'severity' => $eventSeverity,
                'category' => $parameter,
                'message' => ucfirst(str_replace('_', ' ', $parameter)) . ' is outside ' . ($eventSeverity === 'critical' ? 'the configured safe' : 'the optimal') . ' range',
                'sensor_value' => $value,
                'is_resolved' => 0,
                'created_at' => $reading['recorded_at'],
                'ts_fmt' => date('M j, g:i A', strtotime((string)$reading['recorded_at'])),
                'gh_code' => $greenhouse['code'],
                'gh_name' => $greenhouse['name'],
            ];
        }
    }
    usort($events, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    return $events;
}
