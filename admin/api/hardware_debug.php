<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $data = [];

    foreach (['greenhouses', 'hardware_components', 'sensors', 'sensor_readings'] as $table) {
        $data[$table . '_count'] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }

    $data['greenhouses'] = $pdo->query(
        "SELECT greenhouse_id, code, name FROM greenhouses ORDER BY greenhouse_id ASC LIMIT 10"
    )->fetchAll();

    $data['hardware'] = $pdo->query(
        "SELECT * FROM hardware_components ORDER BY component_id DESC LIMIT 20"
    )->fetchAll();

    $data['latest_readings'] = $pdo->query(
        "SELECT * FROM sensor_readings ORDER BY recorded_at DESC, reading_id DESC LIMIT 10"
    )->fetchAll();

    jsonResponse(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
