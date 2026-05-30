<?php
require_once __DIR__ . '/auth_guard.php';
require_role('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/preferences.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'Administrator');
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole = $_SESSION['user_role'] ?? 'admin';
$userInitials = strtoupper(implode('', array_map(
    fn($w) => $w[0],
    array_slice(explode(' ', trim($_SESSION['user_name'] ?? 'A')), 0, 2)
)));

$dbError = null;
ensure_session_log_table();
ensure_activity_log_table();

// Builds the export filename for admin report downloads.
function adminExportFilename(string $type, string $format): string
{
    return sprintf('ecotwin_admin_%s_%s.%s', $type, date('Ymd_His'), $format);
}

// Streams the admin report export in the requested format.
function streamAdminExport(string $type, string $format, array $rows, array $meta = []): never
{
    $filename = adminExportFilename($type, $format);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode([
            'report_type' => $type,
            'generated_at' => date('c'),
            'meta' => $meta,
            'row_count' => count($rows),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($format === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            body{font-family:Segoe UI,Arial,sans-serif;color:#0f172a;margin:18px}
            table{border-collapse:collapse;width:100%}
            .meta,.data{margin-bottom:18px}
            .meta td,.data td,.data th{border:1px solid #dbe7e4;padding:8px 10px}
            .meta td:first-child{font-weight:700;width:220px;background:#f8fafc}
            .data th{background:#eef6f5;text-align:left;font-weight:800}
            .title{font-size:22px;font-weight:800;margin-bottom:12px}
            .empty{padding:14px;border:1px solid #dbe7e4;background:#f8fafc}
        </style></head><body>';
        echo '<div class="title">EcoTwin Admin Export</div>';

        if ($meta) {
            echo '<table class="meta"><tbody>';
            foreach ($meta as $label => $value) {
                echo '<tr><td>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</td><td>'
                    . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if (!$rows) {
            echo '<div class="empty">No data available for the selected filters.</div>';
            echo '</body></html>';
            exit;
        }

        echo '<table class="data"><thead><tr>';
        foreach (array_keys($rows[0]) as $column) {
            echo '<th>' . htmlspecialchars((string) $column, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    if ($meta) {
        fputcsv($out, ['EcoTwin Admin Export']);
        foreach ($meta as $label => $value) {
            fputcsv($out, [$label, $value]);
        }
        fputcsv($out, []);
    }

    if (!$rows) {
        fputcsv($out, ['No data available for the selected filters.']);
        fclose($out);
        exit;
    }

    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// Returns a readable label for the selected admin export type.
function adminExportLabel(string $type): string
{
    return match ($type) {
        'sensors' => 'Sensor Report',
        'logins' => 'Login Logs',
        'activity' => 'Activity Logs',
        default => 'Admin Report',
    };
}

// Normalizes export date-time inputs from browser controls.
function adminNormalizeDateTimeInput(?string $value, bool $endOfDayWhenDateOnly = false): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            if ($format === 'Y-m-d') {
                $dt->setTime(
                    $endOfDayWhenDateOnly ? 23 : 0,
                    $endOfDayWhenDateOnly ? 59 : 0,
                    $endOfDayWhenDateOnly ? 59 : 0
                );
            }
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
}

// Resolves the selected export time window into SQL-safe bounds.
function adminResolveExportWindow(string $range, ?string $dateFrom, ?string $dateTo): array
{
    $range = strtolower(trim($range));
    if (!in_array($range, ['24h', '7d', '30d', 'custom'], true)) {
        $range = '24h';
    }

    switch ($range) {
        case '7d':
            $from = date('Y-m-d H:i:s', strtotime('-7 days'));
            $to = date('Y-m-d H:i:s');
            $label = 'Last 7 Days';
            break;
        case '30d':
            $from = date('Y-m-d H:i:s', strtotime('-30 days'));
            $to = date('Y-m-d H:i:s');
            $label = 'Last 30 Days';
            break;
        case 'custom':
            $from = adminNormalizeDateTimeInput($dateFrom, false) ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
            $to = adminNormalizeDateTimeInput($dateTo, true) ?? date('Y-m-d H:i:s');
            $label = 'Custom Range';
            break;
        case '24h':
        default:
            $from = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $to = date('Y-m-d H:i:s');
            $label = 'Last 24 Hours';
            break;
    }

    if (strtotime($from) > strtotime($to)) {
        [$from, $to] = [$to, $from];
    }

    return [$from, $to, $label];
}

try {
    $pdo = db();
    $preferences = ecotwinLoadUserPreferences($pdo, (int)($_SESSION['user_id'] ?? 0));
    $profileDetails = ecotwinLoadUserProfileDetails($pdo, (int)($_SESSION['user_id'] ?? 0));
    $preferenceBodyClass = ecotwinPreferenceBodyClass($preferences);
    $t = fn(string $key, array $replacements = []) => ecotwinT($preferences['language'], $key, $replacements);

    $sensorSummary = $pdo->query("
        SELECT
            COUNT(*) AS total_sensors,
            SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) AS online_sensors,
            SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END) AS degraded_sensors,
            SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) AS offline_sensors
        FROM sensors
    ")->fetch() ?: [];

    $readingCount24h = (int)$pdo->query("
        SELECT COUNT(*) FROM sensor_readings
        WHERE recorded_at >= NOW() - INTERVAL 24 HOUR
    ")->fetchColumn();

    $loginCount7d = (int)$pdo->query("
        SELECT COUNT(*) FROM session_log
        WHERE action IN ('login', 'logout', 'remember_login')
          AND logged_at >= NOW() - INTERVAL 7 DAY
    ")->fetchColumn();

    $activityCount7d = (int)$pdo->query("
        SELECT COUNT(*) FROM activity_log
        WHERE created_at >= NOW() - INTERVAL 7 DAY
    ")->fetchColumn();

    $sensorRows = $pdo->query("
        SELECT
            s.sensor_id,
            s.label,
            s.sensor_type,
            s.parameter,
            s.status,
            s.last_seen_at,
            s.firmware_version,
            g.code AS gh_code,
            COUNT(r.reading_id) AS readings_24h,
            MAX(r.recorded_at) AS last_reading_at
        FROM sensors s
        LEFT JOIN greenhouses g ON g.greenhouse_id = s.greenhouse_id
        LEFT JOIN sensor_readings r
               ON r.sensor_id = s.sensor_id
              AND r.recorded_at >= NOW() - INTERVAL 24 HOUR
        GROUP BY s.sensor_id
        ORDER BY g.code, s.sensor_type, s.label
    ")->fetchAll();

    $loginLogs = $pdo->query("
        SELECT
            sl.action,
            sl.detail,
            sl.ip_address,
            sl.user_agent,
            sl.logged_at,
            u.full_name,
            u.email
        FROM session_log sl
        LEFT JOIN users u ON u.user_id = sl.user_id
        WHERE sl.action IN ('login', 'logout', 'remember_login')
        ORDER BY sl.logged_at DESC
        LIMIT 50
    ")->fetchAll();

    $activityLogs = $pdo->query("
        SELECT
            al.category,
            al.action,
            al.detail,
            al.target_type,
            al.target_id,
            al.ip_address,
            al.created_at,
            u.full_name
        FROM activity_log al
        LEFT JOIN users u ON u.user_id = al.user_id
        ORDER BY al.created_at DESC
        LIMIT 50
    ")->fetchAll();

    if (isset($_GET['export'])) {
        $exportType = strtolower((string)($_GET['export'] ?? 'sensors'));
        $exportFormat = strtolower((string)($_GET['format'] ?? 'csv'));
        $exportRange = strtolower((string)($_GET['date_range'] ?? '24h'));
        $dateFrom = (string)($_GET['date_from'] ?? '');
        $dateTo = (string)($_GET['date_to'] ?? '');

        if (!in_array($exportType, ['sensors', 'logins', 'activity'], true)) {
            $exportType = 'sensors';
        }
        if (!in_array($exportFormat, ['csv', 'json', 'xls'], true)) {
            $exportFormat = 'csv';
        }

        [$exportFrom, $exportTo, $exportRangeLabel] = adminResolveExportWindow($exportRange, $dateFrom, $dateTo);

        if ($exportType === 'sensors') {
            $stmt = $pdo->prepare("
                SELECT
                    s.sensor_id,
                    s.label,
                    CASE
                        WHEN g.code IS NULL OR g.code = '' THEN ''
                        ELSE CONCAT('Greenhouse ', g.code)
                    END AS greenhouse,
                    s.sensor_type,
                    s.parameter,
                    s.status,
                    COALESCE(s.firmware_version, '') AS firmware_version,
                    COUNT(r.reading_id) AS readings_in_range,
                    COALESCE(DATE_FORMAT(MAX(r.recorded_at), '%Y-%m-%d %H:%i:%s'), '') AS last_reading_at,
                    COALESCE(DATE_FORMAT(s.last_seen_at, '%Y-%m-%d %H:%i:%s'), '') AS last_seen_at
                FROM sensors s
                LEFT JOIN greenhouses g ON g.greenhouse_id = s.greenhouse_id
                LEFT JOIN sensor_readings r
                       ON r.sensor_id = s.sensor_id
                      AND r.recorded_at BETWEEN ? AND ?
                GROUP BY
                    s.sensor_id,
                    s.label,
                    g.code,
                    s.sensor_type,
                    s.parameter,
                    s.status,
                    s.firmware_version,
                    s.last_seen_at
                ORDER BY g.code ASC, s.sensor_type ASC, s.label ASC
                LIMIT 50000
            ");
            $stmt->execute([$exportFrom, $exportTo]);
            $exportRows = $stmt->fetchAll() ?: [];
        } elseif ($exportType === 'logins') {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(u.full_name, 'Unknown User') AS user_name,
                    COALESCE(u.email, '') AS email,
                    sl.action,
                    COALESCE(sl.detail, '') AS detail,
                    COALESCE(sl.ip_address, '') AS ip_address,
                    COALESCE(sl.user_agent, '') AS user_agent,
                    DATE_FORMAT(sl.logged_at, '%Y-%m-%d %H:%i:%s') AS logged_at
                FROM session_log sl
                LEFT JOIN users u ON u.user_id = sl.user_id
                WHERE sl.action IN ('login', 'logout', 'remember_login')
                  AND sl.logged_at BETWEEN ? AND ?
                ORDER BY sl.logged_at DESC
                LIMIT 50000
            ");
            $stmt->execute([$exportFrom, $exportTo]);
            $exportRows = $stmt->fetchAll() ?: [];
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(u.full_name, 'System') AS actor_name,
                    al.category,
                    al.action,
                    COALESCE(al.detail, '') AS detail,
                    COALESCE(al.target_type, '') AS target_type,
                    COALESCE(al.target_id, '') AS target_id,
                    COALESCE(al.ip_address, '') AS ip_address,
                    DATE_FORMAT(al.created_at, '%Y-%m-%d %H:%i:%s') AS created_at
                FROM activity_log al
                LEFT JOIN users u ON u.user_id = al.user_id
                WHERE al.created_at BETWEEN ? AND ?
                ORDER BY al.created_at DESC
                LIMIT 50000
            ");
            $stmt->execute([$exportFrom, $exportTo]);
            $exportRows = $stmt->fetchAll() ?: [];
        }

        $meta = [
            'Report Type' => adminExportLabel($exportType),
            'Generated At' => date('Y-m-d H:i:s'),
            'Date Range' => $exportRangeLabel,
            'Period Start' => $exportFrom,
            'Period End' => $exportTo,
            'Format' => strtoupper($exportFormat),
            'Rows' => (string) count($exportRows),
        ];

        log_activity_event(
            (int)($_SESSION['user_id'] ?? 0),
            'admin_reports',
            'export_data',
            sprintf(
                'Exported %s as %s for %s (%s to %s)',
                strtolower(adminExportLabel($exportType)),
                strtoupper($exportFormat),
                $exportRangeLabel,
                $exportFrom,
                $exportTo
            ),
            'admin_report'
        );

        streamAdminExport($exportType, $exportFormat, $exportRows, $meta);
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
    $preferences = ecotwinDefaultPreferences();
    $profileDetails = ['avatar_url' => ''];
    $preferenceBodyClass = ecotwinPreferenceBodyClass($preferences);
    $t = fn(string $key, array $replacements = []) => ecotwinT($preferences['language'], $key, $replacements);
    $sensorSummary = ['total_sensors' => 0, 'online_sensors' => 0, 'degraded_sensors' => 0, 'offline_sensors' => 0];
    $readingCount24h = $loginCount7d = $activityCount7d = 0;
    $sensorRows = $loginLogs = $activityLogs = [];
}

$sensorRowsDisplay = array_slice($sensorRows, 0, 10);
$loginLogsDisplay = array_slice($loginLogs, 0, 10);
$activityLogsDisplay = array_slice($activityLogs, 0, 10);
$adminExportDefaultFrom = date('Y-m-d\TH:i', strtotime('-24 hours'));
$adminExportDefaultTo = date('Y-m-d\TH:i');

// Returns the CSS class used for admin report status display.
function adminReportStatusClass(string $status): string {
    return match ($status) {
        'online' => 'badge-success',
        'degraded' => 'badge-warning',
        'offline' => 'badge-danger',
        default => 'badge-neutral',
    };
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($preferences['language']) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Reports - EcoTwin</title>
  <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>" />
  <link rel="stylesheet" href="css.reports.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.reports.css')) ?>" />
  <style>
    .admin-report-grid { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:16px; margin-bottom:24px; }
    .admin-report-card { background:#fff; border:1px solid #dbe7e4; border-radius:16px; padding:18px 20px; box-shadow:0 10px 24px rgba(15,23,42,.05); }
    .admin-report-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#2E8B57; margin-bottom:8px; }
    .admin-report-value { font-size:30px; font-weight:800; color:#0f172a; }
    .admin-report-meta { font-size:13px; color:#64748b; margin-top:6px; }
    .admin-report-stack { display:grid; gap:24px; }
    .admin-report-table-wrap { overflow:auto; }
    .admin-report-table th, .admin-report-table td { white-space:nowrap; }
    .admin-report-detail { max-width:360px; white-space:normal; color:#475569; }
    .admin-report-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .admin-report-actions .btn { padding:6px 12px; font-size:12px; }
    @media (max-width: 1100px) { .admin-report-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 680px) { .admin-report-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="<?= htmlspecialchars($preferenceBodyClass) ?>"
      data-language="<?= htmlspecialchars($preferences['language']) ?>"
      data-timezone="<?= htmlspecialchars($preferences['timezone']) ?>"
      data-date-format="<?= htmlspecialchars($preferences['date_format']) ?>">
<nav class="navbar">
  <div class="navbar-container">
    <a href="dashboard.php" class="navbar-logo">
      <img src="ECOTwin_Logo.png" alt="EcoTwin logo" class="logo-icon" />
      <span class="logo-text">EcoTwin</span>
    </a>
    <div class="navbar-menu" id="navbarMenu">
      <a href="index.php" class="nav-item"><?= htmlspecialchars($t('nav.index')) ?></a>
      <a href="dashboard.php" class="nav-item"><?= htmlspecialchars($t('nav.dashboard')) ?></a>
      <a href="experiments.php" class="nav-item"><?= htmlspecialchars($t('nav.experiments')) ?></a>
      <a href="greenhouses.php" class="nav-item"><?= htmlspecialchars($t('nav.greenhouses')) ?></a>
      <a href="admin_reports.php" class="nav-item active"><?= htmlspecialchars($t('nav.reports')) ?></a>
      <a href="admin.php" class="nav-item"><?= htmlspecialchars($t('nav.admin')) ?></a>
    </div>
    <div class="navbar-user">
      <div class="profile-icon <?= !empty($profileDetails['avatar_url']) ? 'has-avatar' : '' ?>" onclick="toggleProfileDropdown(event)">
        <?php if (!empty($profileDetails['avatar_url'])): ?>
        <img src="<?= htmlspecialchars($profileDetails['avatar_url']) ?>" alt="Profile avatar" />
        <?php else: ?>
        <?= $userInitials ?>
        <?php endif; ?>
      </div>
      <div class="profile-dropdown" id="profileDropdown">
        <div class="profile-dropdown-header">
          <div class="profile-user-info">
            <div class="profile-user-name"><?= $userName ?></div>
            <div class="profile-user-email"><?= $userEmail ?></div>
            <div class="profile-user-role"><?= ucfirst(htmlspecialchars($userRole)) ?></div>
          </div>
        </div>
        <div class="profile-dropdown-body">
          <a href="profile_settings.php" class="profile-menu-item"><?= htmlspecialchars($t('menu.profile_settings')) ?></a>
          <a href="preference_settings.php" class="profile-menu-item"><?= htmlspecialchars($t('menu.preferences')) ?></a>
        </div>
        <div class="profile-dropdown-footer">
          <button class="logout-btn" onclick="logout()"><?= htmlspecialchars($t('menu.logout')) ?></button>
        </div>
      </div>
    </div>
  </div>
</nav>

<main class="main-content">
  <div class="page-header">
    <h1 class="page-title">Admin Sensor &amp; Activity Reports</h1>
    <p class="page-subtitle">Operational reporting focused on sensor health, login history, and recorded system activity.</p>
  </div>

  <?php if ($dbError): ?>
  <div class="alert alert-danger mb-3">
    <span class="alert-icon">⚠️</span>
    <div><strong>Database Error:</strong> <?= htmlspecialchars($dbError) ?></div>
  </div>
  <?php endif; ?>

  <div class="admin-report-grid">
    <div class="admin-report-card">
      <div class="admin-report-label">Sensors Online</div>
      <div class="admin-report-value"><?= number_format((int)($sensorSummary['online_sensors'] ?? 0)) ?></div>
      <div class="admin-report-meta">Out of <?= number_format((int)($sensorSummary['total_sensors'] ?? 0)) ?> registered sensors</div>
    </div>
    <div class="admin-report-card">
      <div class="admin-report-label">Degraded / Offline</div>
      <div class="admin-report-value"><?= number_format((int)($sensorSummary['degraded_sensors'] ?? 0) + (int)($sensorSummary['offline_sensors'] ?? 0)) ?></div>
      <div class="admin-report-meta"><?= number_format((int)($sensorSummary['degraded_sensors'] ?? 0)) ?> degraded, <?= number_format((int)($sensorSummary['offline_sensors'] ?? 0)) ?> offline</div>
    </div>
    <div class="admin-report-card">
      <div class="admin-report-label">Readings in 24h</div>
      <div class="admin-report-value"><?= number_format($readingCount24h) ?></div>
      <div class="admin-report-meta">Sensor readings collected in the last 24 hours</div>
    </div>
    <div class="admin-report-card">
      <div class="admin-report-label">Logs in 7 Days</div>
      <div class="admin-report-value"><?= number_format($loginCount7d + $activityCount7d) ?></div>
      <div class="admin-report-meta"><?= number_format($loginCount7d) ?> login logs and <?= number_format($activityCount7d) ?> activity logs</div>
    </div>
  </div>

  <div class="admin-report-stack">
    <section class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Export Admin Data</h2>
          <p class="text-muted text-small">Choose a report, set the date and time window, then download it in the format you need.</p>
        </div>
      </div>
      <div class="export-panel">
        <div class="export-form">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="admin-export-type">Data to Export</label>
              <select class="form-select" id="admin-export-type">
                <option value="sensors">Sensor Report</option>
                <option value="logins">Login Logs</option>
                <option value="activity">Activity Logs</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="admin-export-range">Date Range</label>
              <select class="form-select" id="admin-export-range" onchange="toggleAdminCustomRange()">
                <option value="24h">Last 24 Hours</option>
                <option value="7d">Last 7 Days</option>
                <option value="30d">Last 30 Days</option>
                <option value="custom">Custom Date &amp; Time</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="admin-export-format">Format</label>
              <select class="form-select" id="admin-export-format">
                <option value="xls">Excel Styled (.xls)</option>
                <option value="csv">CSV (Excel)</option>
                <option value="json">JSON</option>
              </select>
            </div>
          </div>

          <div class="form-row" id="admin-custom-range-row" style="display:none;">
            <div class="form-group">
              <label class="form-label" for="admin-date-from">From</label>
              <input type="datetime-local" class="form-select" id="admin-date-from" value="<?= htmlspecialchars($adminExportDefaultFrom) ?>" />
            </div>
            <div class="form-group">
              <label class="form-label" for="admin-date-to">To</label>
              <input type="datetime-local" class="form-select" id="admin-date-to" value="<?= htmlspecialchars($adminExportDefaultTo) ?>" />
            </div>
            <div class="form-group"></div>
          </div>

          <button class="btn btn-primary" id="admin-export-btn" onclick="showAdminExportConfirm()">
            Export Data
          </button>
        </div>

        <div class="export-notice">
          <div class="notice-icon">Admin</div>
          <div>
            <strong>Time-filtered export:</strong> Sensor report exports include sensor status plus reading counts inside the selected window.
            Login and activity exports download only the rows recorded within the selected date and time range.
          </div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Sensor Report</h2>
        <div class="admin-report-actions">
          <span class="badge badge-info">Latest 10 Preview</span>
        </div>
      </div>
      <div class="admin-report-table-wrap">
        <table class="table admin-report-table">
          <thead>
            <tr>
              <th>Sensor</th>
              <th>Greenhouse</th>
              <th>Type</th>
              <th>Parameter</th>
              <th>Status</th>
              <th>Readings (24h)</th>
              <th>Last Reading</th>
              <th>Last Seen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sensorRowsDisplay as $sensor): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($sensor['label']) ?></strong>
                <?php if (!empty($sensor['firmware_version'])): ?>
                <div class="text-muted text-small">FW <?= htmlspecialchars($sensor['firmware_version']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= $sensor['gh_code'] ? 'Greenhouse ' . htmlspecialchars($sensor['gh_code']) : '—' ?></td>
              <td><?= htmlspecialchars($sensor['sensor_type']) ?></td>
              <td><?= htmlspecialchars((string)$sensor['parameter']) ?></td>
              <td><span class="badge <?= adminReportStatusClass((string)$sensor['status']) ?>"><?= htmlspecialchars(ucfirst((string)$sensor['status'])) ?></span></td>
              <td><?= number_format((int)$sensor['readings_24h']) ?></td>
              <td><?= $sensor['last_reading_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime((string)$sensor['last_reading_at']))) : '—' ?></td>
              <td><?= $sensor['last_seen_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime((string)$sensor['last_seen_at']))) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$sensorRowsDisplay): ?>
            <tr><td colspan="8" class="text-center text-muted" style="padding:32px;">No sensor report data available.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Login Logs</h2>
        <div class="admin-report-actions">
          <span class="badge badge-neutral">Latest 10 Preview</span>
        </div>
      </div>
      <div class="admin-report-table-wrap">
        <table class="table admin-report-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Action</th>
              <th>IP Address</th>
              <th>Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($loginLogsDisplay as $log): ?>
            <tr>
              <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$log['logged_at']))) ?></td>
              <td>
                <strong><?= htmlspecialchars($log['full_name'] ?: 'Unknown User') ?></strong>
                <div class="text-muted text-small"><?= htmlspecialchars((string)($log['email'] ?? '')) ?></div>
              </td>
              <td><?= htmlspecialchars((string)$log['action']) ?></td>
              <td><?= htmlspecialchars((string)$log['ip_address']) ?></td>
              <td class="admin-report-detail"><?= htmlspecialchars((string)($log['detail'] ?? '—')) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$loginLogsDisplay): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:32px;">No login logs available.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Activity Logs</h2>
        <div class="admin-report-actions">
          <span class="badge badge-neutral">Latest 10 Preview</span>
        </div>
      </div>
      <div class="admin-report-table-wrap">
        <table class="table admin-report-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Actor</th>
              <th>Category</th>
              <th>Action</th>
              <th>Target</th>
              <th>Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activityLogsDisplay as $log): ?>
            <tr>
              <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$log['created_at']))) ?></td>
              <td><?= htmlspecialchars((string)($log['full_name'] ?: 'System')) ?></td>
              <td><?= htmlspecialchars((string)$log['category']) ?></td>
              <td><?= htmlspecialchars((string)$log['action']) ?></td>
              <td><?= htmlspecialchars(($log['target_type'] || $log['target_id']) ? trim((string)$log['target_type'] . ' #' . (string)$log['target_id']) : '—') ?></td>
              <td class="admin-report-detail"><?= htmlspecialchars((string)($log['detail'] ?? '—')) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$activityLogsDisplay): ?>
            <tr><td colspan="6" class="text-center text-muted" style="padding:32px;">No activity logs available.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</main>

<div id="admin-export-confirm-modal" class="modal-overlay" onclick="closeOnBg(event,'admin-export-confirm-modal')">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-icon confirm">Export</div>
      <h3 class="modal-title">Confirm Admin Export</h3>
    </div>
    <div class="modal-body">
      <p>You are about to export the following admin report:</p>
      <div class="export-summary">
        <div class="summary-row">
          <span class="summary-label">Data:</span>
          <span class="summary-value" id="admin-m-type">Sensor Report</span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Date Range:</span>
          <span class="summary-value" id="admin-m-range">Last 24 Hours</span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Period:</span>
          <span class="summary-value" id="admin-m-period">Rolling 24-hour window</span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Format:</span>
          <span class="summary-value" id="admin-m-format">Excel Styled (.xls)</span>
        </div>
      </div>
      <p class="modal-note">The export will be streamed directly from the server and saved to your downloads folder.</p>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="hideModal('admin-export-confirm-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="runAdminExport()">Confirm Export</button>
    </div>
  </div>
</div>

<div class="toast" id="admin-toast" role="status" aria-live="polite"></div>

<script>
const adminExportTypeLabels = {
  sensors: 'Sensor Report',
  logins: 'Login Logs',
  activity: 'Activity Logs'
};
const adminExportRangeLabels = {
  '24h': 'Last 24 Hours',
  '7d': 'Last 7 Days',
  '30d': 'Last 30 Days',
  custom: 'Custom Date & Time'
};
const adminExportFormatLabels = {
  xls: 'Excel Styled (.xls)',
  csv: 'CSV (Excel)',
  json: 'JSON'
};
let adminToastTimer = null;

// Shows a toast message so export feedback is visible without leaving the page.
function showToast(message, type = 'success') {
  const toast = document.getElementById('admin-toast');
  toast.textContent = message;
  toast.className = 'toast show toast-' + type;
  clearTimeout(adminToastTimer);
  adminToastTimer = setTimeout(() => {
    toast.className = 'toast';
  }, 5000);
}

// Shows modal in the current interface.
function showModal(id) {
  document.getElementById(id).style.display = 'flex';
}

// Hides modal in the current interface.
function hideModal(id) {
  document.getElementById(id).style.display = 'none';
}

// Closes the active modal when the background is clicked.
function closeOnBg(event, id) {
  if (event.target === document.getElementById(id)) {
    hideModal(id);
  }
}

// Toggles the admin custom range row.
function toggleAdminCustomRange() {
  const show = document.getElementById('admin-export-range').value === 'custom';
  document.getElementById('admin-custom-range-row').style.display = show ? 'grid' : 'none';
}

// Formats the browser datetime-local value for the confirmation modal.
function formatAdminDateTime(value) {
  return value ? value.replace('T', ' ') : '';
}

// Opens the admin export confirmation modal with the current selections.
function showAdminExportConfirm() {
  const type = document.getElementById('admin-export-type').value;
  const range = document.getElementById('admin-export-range').value;
  const format = document.getElementById('admin-export-format').value;
  const from = document.getElementById('admin-date-from').value;
  const to = document.getElementById('admin-date-to').value;

  if (range === 'custom' && (!from || !to)) {
    showToast('Select both custom date-time values before exporting.', 'warning');
    return;
  }
  if (range === 'custom' && new Date(from) > new Date(to)) {
    showToast('The "From" date-time must be earlier than the "To" date-time.', 'warning');
    return;
  }

  document.getElementById('admin-m-type').textContent = adminExportTypeLabels[type] || type;
  document.getElementById('admin-m-range').textContent = adminExportRangeLabels[range] || range;
  document.getElementById('admin-m-period').textContent =
    range === 'custom'
      ? `${formatAdminDateTime(from)} to ${formatAdminDateTime(to)}`
      : adminExportRangeLabels[range] || range;
  document.getElementById('admin-m-format').textContent = adminExportFormatLabels[format] || format;
  showModal('admin-export-confirm-modal');
}

// Starts the admin export flow using the selected filters.
function runAdminExport() {
  hideModal('admin-export-confirm-modal');

  const type = document.getElementById('admin-export-type').value;
  const range = document.getElementById('admin-export-range').value;
  const format = document.getElementById('admin-export-format').value;
  const from = document.getElementById('admin-date-from').value;
  const to = document.getElementById('admin-date-to').value;

  let url = `admin_reports.php?export=${encodeURIComponent(type)}&date_range=${encodeURIComponent(range)}&format=${encodeURIComponent(format)}`;
  if (range === 'custom') {
    url += `&date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}`;
  }

  const button = document.getElementById('admin-export-btn');
  button.disabled = true;
  button.textContent = 'Preparing download...';
  showToast('Preparing export. Your download should start shortly.');

  const link = document.createElement('a');
  link.href = url;
  link.download = '';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  setTimeout(() => {
    button.disabled = false;
    button.textContent = 'Export Data';
    showToast('Export started. Check your downloads folder.', 'success');
  }, 2500);
}

// Toggles the profile dropdown menu in the page header.
function toggleProfileDropdown(event) {
  event.stopPropagation();
  document.getElementById('profileDropdown').classList.toggle('active');
}

document.addEventListener('click', function (event) {
  if (!event.target.closest('.profile-icon') && !event.target.closest('.profile-dropdown')) {
    document.getElementById('profileDropdown').classList.remove('active');
  }
});

toggleAdminCustomRange();

// Redirects the user out of the current session from this page.
// Redirects the user out of the current session from this page.
function logout() {
  fetch('auth_handler.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=logout'
  })
    .then(res => res.json())
    .then(data => {
      if (data.success && data.redirect) {
        window.location.href = data.redirect;
      }
    });
}
</script>
  <script src="js.navbar.js?v=<?= urlencode((string) @filemtime(__DIR__ . '/js.navbar.js')) ?>"></script>
</body>
</html>
