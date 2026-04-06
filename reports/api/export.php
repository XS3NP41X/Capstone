<?php
// ============================================================================
// ECOTWIN - Reports Export API  (reports/api/export.php)
// Handles real CSV / JSON data export from sensor_readings + alerts
// Records every export in data_exports table
// ============================================================================

require_once __DIR__ . '/../../admin/db.php';

// -------------------------------------------------------------------
// Parse & validate inputs
// -------------------------------------------------------------------
$ghCode    = $_GET['greenhouse'] ?? 'both';   // 'A', 'B', 'both'
$range     = $_GET['range']      ?? '24h';    // '24h','7d','30d','exp','custom'
$format    = $_GET['format']     ?? 'csv';    // 'csv','json'
$dateFrom  = $_GET['date_from']  ?? null;
$dateTo    = $_GET['date_to']    ?? null;

// Allowed values guard
if (!in_array($ghCode,  ['A','B','both'])) $ghCode = 'both';
if (!in_array($format,  ['csv','json']))    $format = 'csv';

try {
    $pdo = getDB();

    // ---- Resolve date bounds -------------------------------------------
    switch ($range) {
        case '7d':  $from = date('Y-m-d H:i:s', strtotime('-7 days'));  $to = date('Y-m-d H:i:s'); $label = 'Last 7 Days';   break;
        case '30d': $from = date('Y-m-d H:i:s', strtotime('-30 days')); $to = date('Y-m-d H:i:s'); $label = 'Last 30 Days';  break;
        case 'exp':
            $exp = $pdo->query("SELECT started_at, COALESCE(ended_at, NOW()) AS end_at FROM experiments WHERE status='active' ORDER BY created_at DESC LIMIT 1")->fetch();
            $from  = $exp ? $exp['started_at'] : date('Y-m-d H:i:s', strtotime('-30 days'));
            $to    = $exp ? $exp['end_at']     : date('Y-m-d H:i:s');
            $label = 'Current Experiment';
            break;
        case 'custom':
            $from  = $dateFrom ? date('Y-m-d H:i:s', strtotime($dateFrom)) : date('Y-m-d H:i:s', strtotime('-7 days'));
            $to    = $dateTo   ? date('Y-m-d H:i:s', strtotime($dateTo))   : date('Y-m-d H:i:s');
            $label = 'Custom Range';
            break;
        default: // 24h
            $from  = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $to    = date('Y-m-d H:i:s');
            $label = 'Last 24 Hours';
    }

    // ---- Build greenhouse filter ----------------------------------------
    $ghFilter  = '';
    $ghParams  = [$from, $to];
    if ($ghCode !== 'both') {
        $ghRow = $pdo->prepare("SELECT greenhouse_id FROM greenhouses WHERE code = ?");
        $ghRow->execute([$ghCode]);
        $ghId = $ghRow->fetchColumn();
        if ($ghId) {
            $ghFilter = ' AND r.greenhouse_id = ?';
            $ghParams[] = $ghId;
        }
    }

    // ---- Fetch sensor readings ------------------------------------------
    $sql = "
        SELECT
            DATE_FORMAT(r.recorded_at, '%Y-%m-%d %H:%i:%s') AS timestamp,
            g.code          AS greenhouse,
            r.parameter,
            r.value,
            r.unit,
            r.quality,
            s.label         AS sensor_label,
            s.sensor_type
        FROM sensor_readings r
        JOIN greenhouses g ON r.greenhouse_id = g.greenhouse_id
        JOIN sensors     s ON r.sensor_id     = s.sensor_id
        WHERE r.recorded_at BETWEEN ? AND ?
        $ghFilter
        ORDER BY r.recorded_at ASC, g.code ASC, r.parameter ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ghParams);
    $rows = $stmt->fetchAll();

    $rowCount = count($rows);

    // ---- Log the export in data_exports ---------------------------------
    $today    = date('Y-m-d');
    $filename = "ecotwin_export_{$today}.{$format}";
    $pdo->prepare("
        INSERT INTO data_exports
          (requested_by, greenhouse_code, date_from, date_to, date_range_label, format, filename, row_count, status)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, 'completed')
    ")->execute([$ghCode, $from, $to, $label, $format, $filename, $rowCount]);

    // ---- Stream the file -----------------------------------------------
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'export_info' => [
                'generated_at'  => date('Y-m-d H:i:s'),
                'greenhouse'    => $ghCode,
                'date_from'     => $from,
                'date_to'       => $to,
                'date_range'    => $label,
                'total_records' => $rowCount,
                'system'        => 'EcoTwin v1.0 – SPAMAST IASDC',
            ],
            'data' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } else {
        // CSV
        header('Content-Type: text/csv; charset=utf-8');
        $out = fopen('php://output', 'w');

        // BOM for Excel UTF-8
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        // Header comment rows
        fputcsv($out, ['# EcoTwin Export – SPAMAST IASDC']);
        fputcsv($out, ['# Generated',   date('Y-m-d H:i:s')]);
        fputcsv($out, ['# Greenhouse',  $ghCode]);
        fputcsv($out, ['# Date Range',  "$from  →  $to  ($label)"]);
        fputcsv($out, ['# Total Rows',  $rowCount]);
        fputcsv($out, []);

        // Column headers
        fputcsv($out, ['Timestamp','Greenhouse','Parameter','Value','Unit','Quality','Sensor Label','Sensor Type']);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['timestamp'], $r['greenhouse'], $r['parameter'],
                $r['value'], $r['unit'], $r['quality'],
                $r['sensor_label'], $r['sensor_type'],
            ]);
        }
        fclose($out);
    }
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
