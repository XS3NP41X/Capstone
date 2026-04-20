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

function adminExportFilename(string $type, string $format): string
{
    return sprintf('ecotwin_admin_%s_%s.%s', $type, date('Ymd_His'), $format);
}

function streamAdminExport(string $type, string $format, array $rows): never
{
    $filename = adminExportFilename($type, $format);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode([
            'report_type' => $type,
            'generated_at' => date('c'),
            'row_count' => count($rows),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if (!$rows) {
        fputcsv($out, ['No data available']);
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
        $exportType = strtolower((string)($_GET['export'] ?? ''));
        $exportFormat = strtolower((string)($_GET['format'] ?? 'csv'));
        if (!in_array($exportFormat, ['csv', 'json'], true)) {
            $exportFormat = 'csv';
        }

        if ($exportType === 'sensors') {
            $exportRows = array_map(static function (array $sensor): array {
                return [
                    'sensor_id' => (int)$sensor['sensor_id'],
                    'label' => (string)$sensor['label'],
                    'greenhouse' => $sensor['gh_code'] ? 'Greenhouse ' . $sensor['gh_code'] : '',
                    'sensor_type' => (string)$sensor['sensor_type'],
                    'parameter' => (string)$sensor['parameter'],
                    'status' => (string)$sensor['status'],
                    'firmware_version' => (string)($sensor['firmware_version'] ?? ''),
                    'readings_24h' => (int)$sensor['readings_24h'],
                    'last_reading_at' => (string)($sensor['last_reading_at'] ?? ''),
                    'last_seen_at' => (string)($sensor['last_seen_at'] ?? ''),
                ];
            }, $sensorRows);
            streamAdminExport('sensors', $exportFormat, $exportRows);
        }

        if ($exportType === 'logins') {
            $allLoginLogs = $pdo->query("
                SELECT
                    COALESCE(u.full_name, 'Unknown User') AS user_name,
                    COALESCE(u.email, '') AS email,
                    sl.action,
                    sl.detail,
                    sl.ip_address,
                    sl.user_agent,
                    sl.logged_at
                FROM session_log sl
                LEFT JOIN users u ON u.user_id = sl.user_id
                WHERE sl.action IN ('login', 'logout', 'remember_login')
                ORDER BY sl.logged_at DESC
            ")->fetchAll();
            streamAdminExport('login_logs', $exportFormat, $allLoginLogs);
        }

        if ($exportType === 'activity') {
            $allActivityLogs = $pdo->query("
                SELECT
                    COALESCE(u.full_name, 'System') AS actor_name,
                    al.category,
                    al.action,
                    al.detail,
                    al.target_type,
                    al.target_id,
                    al.ip_address,
                    al.created_at
                FROM activity_log al
                LEFT JOIN users u ON u.user_id = al.user_id
                ORDER BY al.created_at DESC
            ")->fetchAll();
            streamAdminExport('activity_logs', $exportFormat, $allActivityLogs);
        }
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
    .admin-report-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#0f766e; margin-bottom:8px; }
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
        <h2 class="card-title">Sensor Report</h2>
        <div class="admin-report-actions">
          <span class="badge badge-info">Latest 10</span>
          <a class="btn btn-secondary" href="admin_reports.php?export=sensors&format=csv">Export CSV</a>
          <a class="btn btn-secondary" href="admin_reports.php?export=sensors&format=json">Export JSON</a>
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
          <span class="badge badge-neutral">Latest 10</span>
          <a class="btn btn-secondary" href="admin_reports.php?export=logins&format=csv">Export CSV</a>
          <a class="btn btn-secondary" href="admin_reports.php?export=logins&format=json">Export JSON</a>
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
          <span class="badge badge-neutral">Latest 10</span>
          <a class="btn btn-secondary" href="admin_reports.php?export=activity&format=csv">Export CSV</a>
          <a class="btn btn-secondary" href="admin_reports.php?export=activity&format=json">Export JSON</a>
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

<script>
function toggleProfileDropdown(event) {
  event.stopPropagation();
  document.getElementById('profileDropdown').classList.toggle('active');
}

document.addEventListener('click', function (event) {
  if (!event.target.closest('.profile-icon') && !event.target.closest('.profile-dropdown')) {
    document.getElementById('profileDropdown').classList.remove('active');
  }
});

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
</body>
</html>
