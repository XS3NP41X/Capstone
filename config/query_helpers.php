<?php
// ============================================================================
// ECOTWIN - QUERY HELPERS
// Plain SQL replacements for optional database views.
// ============================================================================

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

function ecotwinFetchLatestReadingsMap(PDO $db, int $greenhouseId, array $parameters = []): array
{
    $mapped = [];
    foreach (ecotwinFetchLatestReadings($db, $greenhouseId, $parameters) as $row) {
        $mapped[$row['parameter']] = $row;
    }
    return $mapped;
}

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
