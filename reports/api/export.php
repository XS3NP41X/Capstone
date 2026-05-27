<?php
// ============================================================================
// ECOTWIN - Reports Export API  (reports/api/export.php)
// Handles real CSV / JSON data export from sensor_readings + alerts
// Records every export in data_exports table
// ============================================================================

require_once __DIR__ . '/../../admin/db.php';
require_once __DIR__ . '/../../config/security.php';
require_auth();

// Ensures data exports table exists before it is used.
function ensureDataExportsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS data_exports (
            export_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            requested_by INT UNSIGNED NULL,
            greenhouse_code VARCHAR(20) NOT NULL DEFAULT 'both',
            date_from DATETIME NOT NULL,
            date_to DATETIME NOT NULL,
            date_range_label VARCHAR(100) NOT NULL,
            format VARCHAR(20) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            row_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'completed',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (export_id),
            KEY idx_requested_by (requested_by),
            CONSTRAINT fk_data_exports_user
                FOREIGN KEY (requested_by) REFERENCES users(user_id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// Builds export query data or markup for the current flow.
function buildExportQuery(string $ghFilter): string
{
    return "
        SELECT
            DATE_FORMAT(r.recorded_at, '%Y-%m-%d %H:%i:%s') AS timestamp,
            g.code AS greenhouse,
            r.parameter,
            r.value,
            r.unit,
            r.quality,
            s.label AS sensor_label,
            s.sensor_type
        FROM sensor_readings r
        JOIN greenhouses g ON r.greenhouse_id = g.greenhouse_id
        JOIN sensors s ON r.sensor_id = s.sensor_id
        WHERE r.recorded_at BETWEEN ? AND ?
        $ghFilter
        ORDER BY r.recorded_at ASC, g.code ASC, r.parameter ASC
        LIMIT 50000
    ";
}

// Builds export analytics data or markup for the current flow.
function buildExportAnalytics(array $rows, string $from, string $to, string $ghCode): array
{
    $parameterStats = [];
    $greenhouseStats = [];
    $sensorStats = [];
    $qualityStats = [];
    $timestamps = [];

    foreach ($rows as $row) {
        $parameter = (string) ($row['parameter'] ?? '');
        $greenhouse = (string) ($row['greenhouse'] ?? '');
        $sensorLabel = (string) ($row['sensor_label'] ?? '');
        $quality = (string) ($row['quality'] ?? '');
        $value = isset($row['value']) ? (float) $row['value'] : null;
        $timestamp = (string) ($row['timestamp'] ?? '');

        if ($timestamp !== '') {
            $timestamps[] = $timestamp;
        }

        if ($parameter !== '' && $value !== null) {
            if (!isset($parameterStats[$parameter])) {
                $parameterStats[$parameter] = [
                    'parameter' => $parameter,
                    'unit' => (string) ($row['unit'] ?? ''),
                    'count' => 0,
                    'min' => $value,
                    'max' => $value,
                    'sum' => 0.0,
                ];
            }

            $parameterStats[$parameter]['count']++;
            $parameterStats[$parameter]['sum'] += $value;
            $parameterStats[$parameter]['min'] = min($parameterStats[$parameter]['min'], $value);
            $parameterStats[$parameter]['max'] = max($parameterStats[$parameter]['max'], $value);
        }

        if ($greenhouse !== '') {
            $greenhouseStats[$greenhouse] = ($greenhouseStats[$greenhouse] ?? 0) + 1;
        }

        if ($sensorLabel !== '') {
            $sensorStats[$sensorLabel] = ($sensorStats[$sensorLabel] ?? 0) + 1;
        }

        if ($quality !== '') {
            $qualityStats[$quality] = ($qualityStats[$quality] ?? 0) + 1;
        }
    }

    ksort($parameterStats);
    ksort($greenhouseStats);
    arsort($sensorStats);
    ksort($qualityStats);

    $parameterSummary = [];
    foreach ($parameterStats as $stat) {
        $count = max(1, (int) $stat['count']);
        $parameterSummary[] = [
            'parameter' => $stat['parameter'],
            'unit' => $stat['unit'],
            'count' => (int) $stat['count'],
            'min' => round((float) $stat['min'], 3),
            'max' => round((float) $stat['max'], 3),
            'avg' => round($stat['sum'] / $count, 3),
        ];
    }

    $greenhouseSummary = [];
    foreach ($greenhouseStats as $greenhouse => $count) {
        $greenhouseSummary[] = [
            'greenhouse' => $greenhouse,
            'count' => (int) $count,
        ];
    }

    $sensorSummary = [];
    foreach ($sensorStats as $sensorLabel => $count) {
        $sensorSummary[] = [
            'sensor_label' => $sensorLabel,
            'count' => (int) $count,
        ];
    }

    $qualitySummary = [];
    foreach ($qualityStats as $quality => $count) {
        $qualitySummary[] = [
            'quality' => $quality,
            'count' => (int) $count,
        ];
    }

    return [
        'overview' => [
            'greenhouse_scope' => $ghCode,
            'from' => $from,
            'to' => $to,
            'total_rows' => count($rows),
            'unique_greenhouses' => count($greenhouseSummary),
            'unique_sensors' => count($sensorSummary),
            'first_timestamp' => $timestamps ? min($timestamps) : null,
            'last_timestamp' => $timestamps ? max($timestamps) : null,
        ],
        'by_parameter' => $parameterSummary,
        'by_greenhouse' => $greenhouseSummary,
        'by_sensor' => array_slice($sensorSummary, 0, 10),
        'by_quality' => $qualitySummary,
    ];
}

// Writes a section title row into the CSV export stream.
function writeCsvSectionTitle($out, string $title): void
{
    fputcsv($out, [$title]);
}

// Writes a spacer row into the CSV export stream.
function writeCsvSpacer($out): void
{
    fputcsv($out, []);
}

// Escapes export values before they are rendered in HTML output.
function exportHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Builds sparkline data or markup for the current flow.
function buildSparkline(array $values): string
{
    if (!$values) {
        return '';
    }

    $bars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    $spark = '';

    foreach ($values as $value) {
        $index = $range > 0 ? (int) round((($value - $min) / $range) * (count($bars) - 1)) : count($bars) - 1;
        $spark .= $bars[max(0, min(count($bars) - 1, $index))];
    }

    return $spark;
}

// -------------------------------------------------------------------
// Parse & validate inputs
// -------------------------------------------------------------------
$ghCode    = $_GET['greenhouse'] ?? 'both';   // 'A', 'B', 'both'
$range     = $_GET['date_range'] ?? ($_GET['range'] ?? '24h');    // '24h','7d','30d','experiment','custom'
$format    = $_GET['format']     ?? 'csv';    // 'csv','json'
$dateFrom  = $_GET['date_from']  ?? null;
$dateTo    = $_GET['date_to']    ?? null;

// Allowed values guard
if (!in_array($ghCode,  ['A','B','both'])) $ghCode = 'both';
if (!in_array($format,  ['csv','json','xls']))    $format = 'csv';
if ($range === 'experiment') $range = 'exp';
if (!in_array($range, ['24h', '7d', '30d', 'exp', 'custom'], true)) $range = '24h';

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
    $ghId = null;
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
    $sql = buildExportQuery($ghFilter);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ghParams);
    $rows = $stmt->fetchAll();

    // If the selected time window has no data, fall back to the latest available
    // dataset for the same greenhouse scope so exports are not blank.
    if (!$rows) {
        if ($ghId) {
            $latestStmt = $pdo->prepare("SELECT MAX(recorded_at) FROM sensor_readings WHERE greenhouse_id = ?");
            $latestStmt->execute([$ghId]);
            $latestRecordedAt = $latestStmt->fetchColumn();
        } else {
            $latestRecordedAt = $pdo->query("SELECT MAX(recorded_at) FROM sensor_readings")->fetchColumn();
        }

        if ($latestRecordedAt) {
            $fallbackTo = (string) $latestRecordedAt;
            $fallbackFrom = date('Y-m-d H:i:s', strtotime($fallbackTo . ' -7 days'));
            $fallbackParams = [$fallbackFrom, $fallbackTo];
            if ($ghId) {
                $fallbackParams[] = $ghId;
            }

            $fallbackStmt = $pdo->prepare(buildExportQuery($ghFilter));
            $fallbackStmt->execute($fallbackParams);
            $rows = $fallbackStmt->fetchAll();

            if ($rows) {
                $from = $fallbackFrom;
                $to = $fallbackTo;
                $label .= ' (Latest Available Data)';
            }
        }
    }

    $rowCount = count($rows);
    $analytics = buildExportAnalytics($rows, $from, $to, $ghCode);

    // ---- Log the export in data_exports ---------------------------------
    $today    = date('Y-m-d');
    $filename = "ecotwin_export_{$today}.{$format}";
    try {
        ensureDataExportsTable($pdo);
        $requestedBy = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $pdo->prepare("
            INSERT INTO data_exports
              (requested_by, greenhouse_code, date_from, date_to, date_range_label, format, filename, row_count, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed')
        ")->execute([$requestedBy, $ghCode, $from, $to, $label, $format, $filename, $rowCount]);
    } catch (PDOException $logError) {
        error_log('Export log failed: ' . $logError->getMessage());
    }
    log_activity_event(
        (int)($_SESSION['user_id'] ?? 0),
        'reports',
        'export_data',
        "Exported {$rowCount} readings as {$format} for greenhouse scope {$ghCode} ({$label})",
        'data_export'
    );

    // ---- Stream the file -----------------------------------------------
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'meta' => [
                'report' => 'EcoTwin Data Export',
                'generated_at' => date('Y-m-d H:i:s'),
                'greenhouse' => $ghCode,
                'date_from' => $from,
                'date_to' => $to,
                'date_range' => $label,
                'total_records' => $rowCount,
                'system'        => 'EcoTwin v1.0 – SPAMAST IASDC',
            ],
            'analytics' => $analytics,
            'readings' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } elseif ($format === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');

        $title = 'EcoTwin Data Analytics';
        $summaryRows = [
            ['Generated At', date('Y-m-d H:i:s')],
            ['Greenhouse Scope', $analytics['overview']['greenhouse_scope']],
            ['Date Range', $label],
            ['Period Start', $from],
            ['Period End', $to],
            ['Total Readings', (string) $rowCount],
            ['Unique Greenhouses', (string) $analytics['overview']['unique_greenhouses']],
            ['Unique Sensors', (string) $analytics['overview']['unique_sensors']],
            ['First Timestamp', (string) ($analytics['overview']['first_timestamp'] ?? '')],
            ['Last Timestamp', (string) ($analytics['overview']['last_timestamp'] ?? '')],
        ];
        $barRows = $analytics['by_greenhouse'];
        $barMax = 1;
        foreach ($barRows as $barRow) {
            $barMax = max($barMax, (int) ($barRow['count'] ?? 0));
        }

        $lineRows = array_slice($analytics['by_parameter'], 0, 6);
        $lineValues = array_map(fn($row) => (float) ($row['avg'] ?? 0), $lineRows);
        $sparkline = buildSparkline($lineValues);
        $trendBars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $trendMin = $lineValues ? min($lineValues) : 0.0;
        $trendMax = $lineValues ? max($lineValues) : 0.0;
        $trendRange = $trendMax - $trendMin;
        $trendCells = [];
        foreach ($lineRows as $index => $lineRow) {
            $avgValue = (float) ($lineRow['avg'] ?? 0);
            $barIndex = $trendRange > 0 ? (int) round((($avgValue - $trendMin) / $trendRange) * (count($trendBars) - 1)) : count($trendBars) - 1;
            $trendCells[] = [
                'label' => strtoupper(substr((string) ($lineRow['parameter'] ?? ''), 0, 4)),
                'bar' => $trendBars[max(0, min(count($trendBars) - 1, $barIndex))],
                'avg' => (string) ($lineRow['avg'] ?? 0),
                'parameter' => (string) ($lineRow['parameter'] ?? ''),
            ];
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            body{font-family:Segoe UI,Arial,sans-serif;color:#111827;margin:18px}
            table{border-collapse:collapse}
            .sheet{width:1180px}
            .hero{width:1180px;border:1px solid #d7e7e4;background:#eef6f5;margin-bottom:16px}
            .hero td{padding:10px 14px;border:none}
            .eyebrow{font-size:14pt;font-weight:700;color:#1f2937}
            .title{font-size:24pt;font-weight:800;color:#111827}
            .subtitle{font-size:10pt;color:#4b5563}
            .layout{width:1180px;margin-bottom:16px}
            .layout td{vertical-align:top}
            .panel{width:100%;border:1px solid #dbe5e3;background:#ffffff}
            .panel-title{background:#eef6f5;font-size:12pt;font-weight:800;color:#111827;padding:8px 10px}
            .summary-table,.data-table{width:100%}
            .summary-table td,.data-table td,.data-table th{border:1px solid #e5ecea;padding:7px 10px}
            .summary-table td{font-size:10pt}
            .data-table th{background:#f7fbfa;color:#374151;font-size:12pt;font-weight:700;text-align:left}
            .data-table td{font-size:10pt}
            .metric{font-weight:700;width:42%}
            .value{color:#0f766e;font-weight:700}
            .mini td,.mini th{border:1px solid #e5ecea;padding:6px 8px}
            .mini th{background:#f7fbfa;font-size:12pt;font-weight:700;text-align:left}
            .mini td{font-size:10pt}
            .bars{font-family:Consolas,monospace;color:#0f766e;font-weight:700;letter-spacing:1px}
            .spark{font-family:Consolas,monospace;color:#0f766e;font-size:16pt;font-weight:700}
            .muted{color:#6b7280}
            .spacer{height:12px}
            .trend-table{width:100%;table-layout:fixed;border-collapse:collapse}
            .trend-table td{border:1px solid #e5ecea;padding:6px 4px;text-align:center;vertical-align:middle}
            .trend-label{font-size:10pt;font-weight:700;color:#334155}
            .trend-bar{font-family:Consolas,monospace;font-size:20pt;line-height:1;color:#0f766e;font-weight:700}
            .trend-avg{font-size:9pt;color:#475569}
        </style></head><body><div class="sheet">';
        echo '<table class="hero"><tr><td>';
        echo '<div class="eyebrow">EcoTwin Report</div>';
        echo '<div class="title">' . exportHtml($title) . '</div>';
        echo '<div class="subtitle"> </div>';
        echo '</td></tr></table>';

        echo '<table class="layout"><tr>';
        echo '<td style="width:50%;padding-right:10px">';
        echo '<table class="panel"><tr><td class="panel-title">Summary</td></tr><tr><td style="padding:0"><table class="summary-table">';
        foreach ($summaryRows as [$metric, $value]) {
            echo '<tr><td class="metric">' . exportHtml($metric) . '</td><td class="value">' . exportHtml((string) $value) . '</td></tr>';
        }
        echo '</table></td></tr></table></td>';

        echo '<td style="width:50%;padding-left:10px">';
        echo '<table class="panel" style="margin-bottom:12px"><tr><td class="panel-title">Reading Volume</td></tr><tr><td style="padding:0"><table class="mini">';
        echo '<tr><th>Greenhouse</th><th>Bar</th><th>Count</th></tr>';
        foreach ($barRows as $barRow) {
            $barWidth = $barMax > 0 ? (((int) $barRow['count'] / $barMax) * 100) : 0;
            $barLength = max(1, (int) round(($barWidth / 100) * 18));
            echo '<tr>';
            echo '<td>Greenhouse ' . exportHtml((string) $barRow['greenhouse']) . '</td>';
            echo '<td class="bars">' . str_repeat('█', $barLength) . '</td>';
            echo '<td>' . exportHtml((string) $barRow['count']) . '</td>';
            echo '</tr>';
        }
        echo '</table></td></tr></table>';

        echo '<table class="panel"><tr><td class="panel-title">Average Trend</td></tr><tr><td style="padding:10px 12px">';
        if ($trendCells) {
            echo '<table class="trend-table"><tr>';
            foreach ($trendCells as $cell) {
                echo '<td class="trend-label">' . exportHtml($cell['label']) . '</td>';
            }
            echo '</tr><tr>';
            foreach ($trendCells as $cell) {
                echo '<td class="trend-bar">' . exportHtml($cell['bar']) . '</td>';
            }
            echo '</tr><tr>';
            foreach ($trendCells as $cell) {
                echo '<td class="trend-avg">' . exportHtml($cell['avg']) . '</td>';
            }
            echo '</tr></table>';
        }
        echo '<div class="muted" style="font-size:10pt;margin-top:8px;text-align:center">Average by parameter</div>';
        echo '</td></tr></table>';
        echo '</td>';
        echo '</tr></table>';

        echo '<table class="panel" style="width:1180px;margin-bottom:16px"><tr><td class="panel-title">Parameter Analytics</td></tr><tr><td style="padding:0"><table class="data-table"><tr><th>Parameter</th><th>Unit</th><th>Count</th><th>Min</th><th>Max</th><th>Average</th></tr>';
        foreach ($analytics['by_parameter'] as $stat) {
            echo '<tr><td>' . exportHtml((string) $stat['parameter']) . '</td><td>' . exportHtml((string) $stat['unit']) . '</td><td>' . exportHtml((string) $stat['count']) . '</td><td>' . exportHtml((string) $stat['min']) . '</td><td>' . exportHtml((string) $stat['max']) . '</td><td>' . exportHtml((string) $stat['avg']) . '</td></tr>';
        }
        echo '</table></td></tr></table>';

        echo '<table class="panel" style="width:1180px;margin-bottom:16px"><tr><td class="panel-title">Greenhouse Breakdown</td></tr><tr><td style="padding:0"><table class="data-table"><tr><th>Greenhouse</th><th>Reading Count</th></tr>';
        foreach ($analytics['by_greenhouse'] as $stat) {
            echo '<tr><td>GH ' . exportHtml((string) $stat['greenhouse']) . '</td><td>' . exportHtml((string) $stat['count']) . '</td></tr>';
        }
        echo '</table></td></tr></table>';

        echo '<table class="panel" style="width:1180px;margin-bottom:16px"><tr><td class="panel-title">Top Sensors</td></tr><tr><td style="padding:0"><table class="data-table"><tr><th>Sensor Label</th><th>Reading Count</th></tr>';
        foreach ($analytics['by_sensor'] as $stat) {
            echo '<tr><td>' . exportHtml((string) $stat['sensor_label']) . '</td><td>' . exportHtml((string) $stat['count']) . '</td></tr>';
        }
        echo '</table></td></tr></table>';

        echo '<table class="panel" style="width:1180px;margin-bottom:16px"><tr><td class="panel-title">Quality Breakdown</td></tr><tr><td style="padding:0"><table class="data-table"><tr><th>Quality</th><th>Count</th></tr>';
        foreach ($analytics['by_quality'] as $stat) {
            echo '<tr><td>' . exportHtml((string) $stat['quality']) . '</td><td>' . exportHtml((string) $stat['count']) . '</td></tr>';
        }
        echo '</table></td></tr></table>';

        echo '<table class="panel" style="width:1180px"><tr><td class="panel-title">Readings</td></tr><tr><td style="padding:0"><table class="data-table"><tr><th>Timestamp</th><th>Greenhouse</th><th>Parameter</th><th>Value</th><th>Unit</th><th>Quality</th><th>Sensor Label</th><th>Sensor Type</th></tr>';
        foreach ($rows as $row) {
            echo '<tr>'
                . '<td>' . exportHtml((string) $row['timestamp']) . '</td>'
                . '<td>' . exportHtml((string) $row['greenhouse']) . '</td>'
                . '<td>' . exportHtml((string) $row['parameter']) . '</td>'
                . '<td>' . exportHtml((string) $row['value']) . '</td>'
                . '<td>' . exportHtml((string) $row['unit']) . '</td>'
                . '<td>' . exportHtml((string) $row['quality']) . '</td>'
                . '<td>' . exportHtml((string) $row['sensor_label']) . '</td>'
                . '<td>' . exportHtml((string) $row['sensor_type']) . '</td>'
                . '</tr>';
        }
        echo '</table></td></tr></table></div></body></html>';

    } else {
        // CSV
        header('Content-Type: text/csv; charset=utf-8');
        $out = fopen('php://output', 'w');

        // BOM for Excel UTF-8
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        writeCsvSectionTitle($out, 'EcoTwin Data Export');
        writeCsvSpacer($out);
        if (false) {
        fputcsv($out, ['# EcoTwin Export – SPAMAST IASDC']);
        fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Greenhouse Scope', $analytics['overview']['greenhouse_scope']]);
        fputcsv($out, ['# Date Range',  "$from  →  $to  ($label)"]);
        fputcsv($out, ['Total Readings', $rowCount]);
        fputcsv($out, []);
        }

        writeCsvSectionTitle($out, 'Summary');
        fputcsv($out, ['Metric', 'Value']);
        fputcsv($out, ['Greenhouse Scope', $analytics['overview']['greenhouse_scope']]);
        fputcsv($out, ['Date Range', $label]);
        fputcsv($out, ['Period Start', $from]);
        fputcsv($out, ['Period End', $to]);
        fputcsv($out, ['Total Readings', $rowCount]);
        fputcsv($out, ['Unique Greenhouses', $analytics['overview']['unique_greenhouses']]);
        fputcsv($out, ['Unique Sensors', $analytics['overview']['unique_sensors']]);
        fputcsv($out, ['First Timestamp', $analytics['overview']['first_timestamp'] ?? '']);
        fputcsv($out, ['Last Timestamp', $analytics['overview']['last_timestamp'] ?? '']);
        fputcsv($out, []);

        writeCsvSectionTitle($out, 'Parameter Analytics');
        fputcsv($out, ['Parameter','Unit','Count','Min','Max','Average']);
        foreach ($analytics['by_parameter'] as $stat) {
            fputcsv($out, [$stat['parameter'], $stat['unit'], $stat['count'], $stat['min'], $stat['max'], $stat['avg']]);
        }
        fputcsv($out, []);

        writeCsvSectionTitle($out, 'Greenhouse Breakdown');
        fputcsv($out, ['Greenhouse','Reading Count']);
        foreach ($analytics['by_greenhouse'] as $stat) {
            fputcsv($out, [$stat['greenhouse'], $stat['count']]);
        }
        fputcsv($out, []);

        writeCsvSectionTitle($out, 'Top Sensors');
        fputcsv($out, ['Sensor Label','Reading Count']);
        foreach ($analytics['by_sensor'] as $stat) {
            fputcsv($out, [$stat['sensor_label'], $stat['count']]);
        }
        fputcsv($out, []);

        writeCsvSectionTitle($out, 'Quality Breakdown');
        fputcsv($out, ['Quality','Count']);
        foreach ($analytics['by_quality'] as $stat) {
            fputcsv($out, [$stat['quality'], $stat['count']]);
        }
        fputcsv($out, []);

        // Column headers
        writeCsvSectionTitle($out, 'Readings');
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
