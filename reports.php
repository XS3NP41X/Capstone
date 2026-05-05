
<?php
// ============================================================================
// ECOTWIN – Reports & Analytics  (reports.php)
// Server-side: initial page data from ecotwin_db via PDO
// Client-side: AJAX for filtering, pagination, live stats refresh, and export
// ============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/preferences.php';

// Session display vars (match dashboard.php)
$userName     = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail    = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole     = $_SESSION['user_role']   ?? 'researcher';
$userInitials = strtoupper(implode('', array_map(
  fn($w) => $w[0],
  array_slice(explode(' ', trim($_SESSION['user_name'] ?? 'U')), 0, 2)
)));

if ($userRole === 'admin') {
    header('Location: admin_reports.php');
    exit;
}

$dbError = null;

try {
    $pdo = db();
    $preferences = ecotwinLoadUserPreferences($pdo, (int)($_SESSION['user_id'] ?? 0));
    $profileDetails = ecotwinLoadUserProfileDetails($pdo, (int)($_SESSION['user_id'] ?? 0));
    $preferenceBodyClass = ecotwinPreferenceBodyClass($preferences);
    $t = fn(string $key, array $replacements = []) => ecotwinT($preferences['language'], $key, $replacements);

    // ── Summary stats (first-paint SSR) ──────────────────────────────────
    $dataPoints = (int)$pdo->query("
        SELECT COUNT(*) FROM sensor_readings
        WHERE recorded_at >= NOW() - INTERVAL 72 HOUR
    ")->fetchColumn();

    $sensorRow = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) AS online_cnt,
            COUNT(*) AS total_cnt
        FROM sensors
    ")->fetch();
    $uptime = $sensorRow['total_cnt'] > 0
        ? round(($sensorRow['online_cnt'] / $sensorRow['total_cnt']) * 100, 1)
        : 0;

    $critCount = 0;
    $warnCount = 0;
    $ghRows = $pdo->query("
        SELECT greenhouse_id, assigned_plant_id
        FROM greenhouses
        ORDER BY code
    ")->fetchAll();

    foreach ($ghRows as $gh) {
        if (empty($gh['assigned_plant_id'])) {
            continue;
        }

        $thresholdStmt = $pdo->prepare("
            SELECT parameter, val_min, val_opt_low, val_opt_high, val_max
            FROM plant_thresholds
            WHERE plant_id = ?
        ");
        $thresholdStmt->execute([(int)$gh['assigned_plant_id']]);
        $thresholds = [];
        foreach ($thresholdStmt->fetchAll() as $row) {
            $thresholds[$row['parameter']] = $row;
        }

        if (!$thresholds) {
            continue;
        }

        $readingStmt = $pdo->prepare("
            SELECT parameter, value
            FROM v_latest_readings
            WHERE greenhouse_id = ?
        ");
        $readingStmt->execute([(int)$gh['greenhouse_id']]);
        $readings = [];
        foreach ($readingStmt->fetchAll() as $row) {
            $readings[$row['parameter']] = (float)$row['value'];
        }

        foreach ($thresholds as $parameter => $threshold) {
            if (!array_key_exists($parameter, $readings)) {
                continue;
            }

            $value = $readings[$parameter];
            if ($value < (float)$threshold['val_min'] || $value > (float)$threshold['val_max']) {
                $critCount++;
            } elseif ($value < (float)$threshold['val_opt_low'] || $value > (float)$threshold['val_opt_high']) {
                $warnCount++;
            }
        }
    }

    // ── Active experiment ─────────────────────────────────────────────────
    $activeExp = $pdo->query("
        SELECT exp_code, title, TIMESTAMPDIFF(HOUR, started_at, NOW()) AS hours_running
        FROM experiments WHERE status = 'active' LIMIT 1
    ")->fetch();

    $analyticsRow = $pdo->query("
        SELECT
            COUNT(*) AS readings_count,
            AVG(CASE WHEN parameter = 'temperature' THEN value END) AS avg_temperature,
            AVG(CASE WHEN parameter = 'humidity' THEN value END) AS avg_humidity,
            AVG(CASE WHEN parameter = 'light' THEN value END) AS avg_light
        FROM sensor_readings
        WHERE recorded_at >= NOW() - INTERVAL 7 DAY
    ")->fetch();

    $topGreenhouse = $pdo->query("
        SELECT g.code, COUNT(sr.reading_id) AS reading_count
        FROM sensor_readings sr
        JOIN sensors s ON s.sensor_id = sr.sensor_id
        JOIN greenhouses g ON g.greenhouse_id = s.greenhouse_id
        WHERE sr.recorded_at >= NOW() - INTERVAL 7 DAY
        GROUP BY g.greenhouse_id, g.code
        ORDER BY reading_count DESC, g.code ASC
        LIMIT 1
    ")->fetch();

    // ── First page of alerts (SSR, 10 per page) ──────────────────────────
    $perPage     = 10;
    $allLiveEvents = buildLiveIssueEvents($pdo);
    $totalAlerts = count($allLiveEvents);
    $totalPages  = max(1, (int)ceil($totalAlerts / $perPage));
    $firstAlerts = array_slice($allLiveEvents, 0, $perPage);

    // ── Sensor performance report (last 7 days) ───────────────────────────
    $sensorRows = $pdo->query("
        SELECT
            s.sensor_type, s.label, s.status,
            g.code AS gh_code,
            COUNT(r.reading_id)                                   AS reading_count,
            SUM(CASE WHEN r.quality='error' THEN 1 ELSE 0 END)   AS error_count
        FROM sensors s
        LEFT JOIN greenhouses g ON s.greenhouse_id = g.greenhouse_id
        LEFT JOIN sensor_readings r
               ON r.sensor_id = s.sensor_id
              AND r.recorded_at >= NOW() - INTERVAL 7 DAY
        GROUP BY s.sensor_id
        ORDER BY s.sensor_type, g.code
    ")->fetchAll();

    // Group by type → compute uptime & grade
    $grouped = [];
    foreach ($sensorRows as $r) {
        $sensorType = $r['sensor_type'];
        if (!isset($grouped[$sensorType])) {
            $grouped[$sensorType] = ['label'=>stLabel($sensorType),'ghs'=>[],'reads'=>0,'errors'=>0,'offline'=>false,'degraded'=>false];
        }
        $grouped[$sensorType]['ghs'][]  = $r['gh_code'];
        $grouped[$sensorType]['reads'] += (int)$r['reading_count'];
        $grouped[$sensorType]['errors']+= (int)$r['error_count'];
        if ($r['status']==='offline')  $grouped[$sensorType]['offline']  = true;
        if ($r['status']==='degraded') $grouped[$sensorType]['degraded'] = true;
    }

    $perfData = [];
    foreach ($grouped as $g) {
        $cnt      = count($g['ghs']);
        $expected = 7 * 24 * 30 * $cnt;
        $reads    = $g['reads']; $errors = $g['errors'];
        if ($g['offline'] || $g['degraded']) {
            $pct   = $reads > 0 ? round($reads / max($expected,1) * 100, 1) : 0;
            $grade = 'degraded';
        } else {
            $pct   = $reads > 0 ? round((1 - $errors / max($reads,1)) * 100, 1) : 100.0;
            $grade = $pct >= 98 ? 'excellent' : ($pct >= 90 ? 'good' : 'degraded');
        }
        $perfData[] = [
            'label'  => $g['label'],
            'ghs'    => implode(' & ', array_map(fn($c)=>'GH-'.$c, array_unique($g['ghs']))),
            'pct'    => $pct,
            'reads'  => $reads,
            'errors' => $errors,
            'grade'  => $grade,
        ];
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
    $preferences = ecotwinDefaultPreferences();
    $profileDetails = ['avatar_url' => ''];
    $preferenceBodyClass = ecotwinPreferenceBodyClass($preferences);
    $t = fn(string $key, array $replacements = []) => ecotwinT($preferences['language'], $key, $replacements);
    $dataPoints = $critCount = $warnCount = 0;
    $analyticsRow = ['readings_count' => 0, 'avg_temperature' => null, 'avg_humidity' => null, 'avg_light' => null];
    $topGreenhouse = null;
    $uptime = 0;
    $totalAlerts = $totalPages = 0;
    $firstAlerts = $perfData = [];
    $activeExp = null;
}

// ── PHP helpers ───────────────────────────────────────────────────────────
// Returns the label text used for the current report type.
function stLabel(string $t): string {
    return match($t) {
        'DHT22'       => 'DHT22 (Temperature & Humidity)',
        'DS18B20'     => 'DS18B20 (Water Temperature)',
        'LDR'         => 'LDR (Light Intensity)',
        'EC_TDS'      => 'EC/TDS Sensor',
        'PH'          => 'pH Sensor',
        'WATER_LEVEL' => 'Water Level Sensor',
        default       => $t,
    };
}

// Returns the icon output for severity display.
function sevIcon(string $s): string {
    $map = ['critical'=>'🔴','warning'=>'⚠️','success'=>'✓','info'=>'ℹ️'];
    $cls = $map[$s] ?? 'ℹ️';
    return "<span class=\"event-icon $s\">$cls</span>";
}

// Returns the row class used for report severity display.
function rowCls(string $s): string {
    return match($s) { 'critical'=>'row-critical','warning'=>'row-warning', default=>'' };
}

// Builds the badge output for greenhouse display.
function ghBadge(?string $c): string {
    if (!$c) return '<span class="text-muted">—</span>';
    return "<span class=\"badge badge-greenhouse-".strtolower($c)."\">Greenhouse $c</span>";
}

// Builds the live issue event list used by the reporting views.
function buildLiveIssueEvents(PDO $pdo, string $ghFilter = 'all', string $severity = 'all'): array {
    if (!in_array($severity, ['all', 'critical', 'warning'], true)) {
        return [];
    }

    $sql = "SELECT greenhouse_id, code, name, assigned_plant_id FROM greenhouses";
    $params = [];
    if ($ghFilter !== 'all') {
        $sql .= " WHERE code = ?";
        $params[] = strtoupper($ghFilter);
    }
    $sql .= " ORDER BY code";

    $ghStmt = $pdo->prepare($sql);
    $ghStmt->execute($params);
    $greenhouses = $ghStmt->fetchAll();
    $events = [];

    foreach ($greenhouses as $gh) {
        if (empty($gh['assigned_plant_id'])) continue;

        $thresholdStmt = $pdo->prepare("
            SELECT parameter, val_min, val_opt_low, val_opt_high, val_max
            FROM plant_thresholds
            WHERE plant_id = ?
        ");
        $thresholdStmt->execute([(int)$gh['assigned_plant_id']]);
        $thresholds = [];
        foreach ($thresholdStmt->fetchAll() as $row) {
            $thresholds[$row['parameter']] = $row;
        }

        $readingStmt = $pdo->prepare("
            SELECT parameter, value, recorded_at
            FROM v_latest_readings
            WHERE greenhouse_id = ?
        ");
        $readingStmt->execute([(int)$gh['greenhouse_id']]);

        foreach ($readingStmt->fetchAll() as $reading) {
            $parameter = $reading['parameter'];
            if (!isset($thresholds[$parameter])) continue;

            $threshold = $thresholds[$parameter];
            $value = (float)$reading['value'];
            $eventSeverity = null;
            $message = null;

            if ($value < (float)$threshold['val_min'] || $value > (float)$threshold['val_max']) {
                $eventSeverity = 'critical';
                $message = ucfirst(str_replace('_', ' ', $parameter)) . ' is outside the configured safe range';
            } elseif ($value < (float)$threshold['val_opt_low'] || $value > (float)$threshold['val_opt_high']) {
                $eventSeverity = 'warning';
                $message = ucfirst(str_replace('_', ' ', $parameter)) . ' is outside the optimal range';
            }

            if ($eventSeverity === null) continue;
            if ($severity !== 'all' && $severity !== $eventSeverity) continue;

            $events[] = [
                'alert_id' => 'live-' . $gh['code'] . '-' . $parameter,
                'severity' => $eventSeverity,
                'category' => $parameter,
                'message' => $message,
                'sensor_value' => $value,
                'is_resolved' => 0,
                'ts_fmt' => date('M j, g:i A', strtotime($reading['recorded_at'])),
                'created_at' => $reading['recorded_at'],
                'gh_code' => $gh['code'],
                'gh_name' => $gh['name'],
            ];
        }
    }

    usort($events, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    return $events;
}

// Renders the pagination buttons for the current report list.
function paginationBtns(int $cur, int $total): void {
    if ($total <= 1) return;
    echo "<button class='page-btn' ".($cur<=1?'disabled':'')." onclick='loadAlerts(".($cur-1).")'>‹</button>\n";
    $pages = $total <= 7 ? range(1,$total) : buildRange($cur,$total);
    foreach ($pages as $p) {
        if ($p==='…') { echo "<button class='page-btn' disabled>…</button>\n"; continue; }
        $a = $p===$cur ? 'active' : '';
        echo "<button class='page-btn $a' onclick='loadAlerts($p)'>$p</button>\n";
    }
    echo "<button class='page-btn' ".($cur>=$total?'disabled':'')." onclick='loadAlerts(".($cur+1).")'>›</button>\n";
}

// Builds range data or markup for the current flow.
function buildRange(int $cur, int $total): array {
    $pages = [1];
    if ($cur > 3) $pages[] = '…';
    for ($p=max(2,$cur-1); $p<=min($total-1,$cur+1); $p++) $pages[]=$p;
    if ($cur < $total-2) $pages[] = '…';
    $pages[] = $total;
    return $pages;
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($preferences['language']) ?>">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($t('page.reports.title')) ?> - EcoTwin</title>
  <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>" />
  <link rel="stylesheet" href="css.reports.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.reports.css')) ?>" />
</head>
<body class="<?= htmlspecialchars($preferenceBodyClass) ?>"
      data-language="<?= htmlspecialchars($preferences['language']) ?>"
      data-timezone="<?= htmlspecialchars($preferences['timezone']) ?>"
      data-date-format="<?= htmlspecialchars($preferences['date_format']) ?>">

<!-- ====================================================== NAVBAR -->

<nav class="navbar">
  <div class="navbar-container">
    <a href="dashboard.php" class="navbar-logo">
      <img src="ECOTwin_Logo.png" alt="EcoTwin logo" class="logo-icon" />
      <span class="logo-text">EcoTwin</span>
    </a>
    <div class="navbar-menu" id="navbarMenu">
      <a href="dashboard.php"   class="nav-item"><?= htmlspecialchars($t('nav.dashboard')) ?></a>
      <a href="experiments.php" class="nav-item"><?= htmlspecialchars($t('nav.experiments')) ?></a>
      <a href="greenhouses.php" class="nav-item"><?= htmlspecialchars($t('nav.greenhouses')) ?></a>
      <a href="reports.php"     class="nav-item active"><?= htmlspecialchars($t('nav.reports')) ?></a>
      <?php if ($userRole === 'admin'): ?>
      <a href="admin.php"       class="nav-item"><?= htmlspecialchars($t('nav.admin')) ?></a>
      <?php endif; ?>
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

<!-- ====================================================== MAIN -->
<main class="main-content">

  <div class="page-header">
    <h1 class="page-title"><?= htmlspecialchars($t('page.reports.title')) ?></h1>
    <p class="page-subtitle"><?= htmlspecialchars($t('page.reports.subtitle')) ?></p>
  </div>

  <?php if ($dbError): ?>
  <div class="alert alert-danger mb-3">
    <span class="alert-icon">⚠️</span>
    <div><strong>Database Error:</strong> <?= htmlspecialchars($dbError) ?></div>
  </div>
  <?php endif; ?>

  <!-- Data source notice -->
  <div class="alert alert-info mb-3">
    <span class="alert-icon">📡</span>
    <div>
      <strong>Data Sources:</strong> All data is collected from ESP32 + Arduino via wireless nRF modules
      and stored in <code>ecotwin_db</code> for analysis and export.
      <?php if ($activeExp): ?>
        &nbsp;|&nbsp;<strong>Active Experiment:</strong>
        <?= htmlspecialchars($activeExp['exp_code']) ?> —
        <?= htmlspecialchars($activeExp['title']) ?>
        (<?= number_format((int)$activeExp['hours_running']) ?> hrs running)
      <?php endif; ?>
    </div>
  </div>

  <section class="card analytics-overview-card mb-4">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= htmlspecialchars($t('dashboard.analytics.title')) ?></h2>
        <div class="analytics-subtitle"><?= htmlspecialchars($t('dashboard.analytics.subtitle')) ?></div>
      </div>
      <span class="badge badge-info">7D</span>
    </div>
    <div class="analytics-grid">
      <div class="analytics-stat">
        <div class="analytics-label"><?= htmlspecialchars($t('dashboard.analytics.readings')) ?></div>
        <div class="analytics-value"><?= number_format((int)($analyticsRow['readings_count'] ?? 0)) ?></div>
      </div>
      <div class="analytics-stat">
        <div class="analytics-label"><?= htmlspecialchars($t('dashboard.analytics.avg_temp')) ?></div>
        <div class="analytics-value"><?= isset($analyticsRow['avg_temperature']) && $analyticsRow['avg_temperature'] !== null ? number_format((float)$analyticsRow['avg_temperature'], 1) . '°C' : '—' ?></div>
      </div>
      <div class="analytics-stat">
        <div class="analytics-label"><?= htmlspecialchars($t('dashboard.analytics.avg_humidity')) ?></div>
        <div class="analytics-value"><?= isset($analyticsRow['avg_humidity']) && $analyticsRow['avg_humidity'] !== null ? number_format((float)$analyticsRow['avg_humidity'], 1) . '%' : '—' ?></div>
      </div>
      <div class="analytics-stat">
        <div class="analytics-label"><?= htmlspecialchars($t('dashboard.analytics.avg_light')) ?></div>
        <div class="analytics-value"><?= isset($analyticsRow['avg_light']) && $analyticsRow['avg_light'] !== null ? number_format((float)$analyticsRow['avg_light'], 0) . ' lux' : '—' ?></div>
      </div>
    </div>
    <div class="analytics-footnote">
      <strong><?= htmlspecialchars($t('dashboard.analytics.top_greenhouse')) ?>:</strong>
      <?= $topGreenhouse ? 'Greenhouse ' . htmlspecialchars($topGreenhouse['code']) . ' • ' . number_format((int)$topGreenhouse['reading_count']) . ' readings' : htmlspecialchars($t('dashboard.analytics.no_data')) ?>
    </div>
  </section>

  <!-- ============================================================
       EXPORT CONTROLS
       ============================================================ -->
  <section class="card mb-4">
    <div class="card-header">
      <h2 class="card-title">Export Data</h2>
    </div>
    <div class="export-panel">
      <div class="export-form">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Greenhouse</label>
            <select class="form-select" id="greenhouse-select">
              <option value="both">Both Greenhouses</option>
              <option value="A">Greenhouse A</option>
              <option value="B">Greenhouse B</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date Range</label>
            <select class="form-select" id="date-range-select" onchange="toggleCustomDates()">
              <option value="24h">Last 24 Hours</option>
              <option value="7d">Last 7 Days</option>
              <option value="30d">Last 30 Days</option>
              <option value="experiment">Current Experiment</option>
              <option value="custom">Custom Range</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Format</label>
            <select class="form-select" id="format-select">
              <option value="xls">Excel Styled (.xls)</option>
              <option value="csv">CSV (Excel)</option>
              <option value="json">JSON</option>
            </select>
          </div>
        </div>

        <!-- Custom date inputs (hidden until "Custom Range" selected) -->
        <div class="form-row" id="custom-date-row" style="display:none;">
          <div class="form-group">
            <label class="form-label">From</label>
            <input type="date" class="form-select" id="date-from"/>
          </div>
          <div class="form-group">
            <label class="form-label">To</label>
            <input type="date" class="form-select" id="date-to"/>
          </div>
          <div class="form-group"></div>
        </div>

        <button class="btn btn-primary" id="export-btn" onclick="showConfirmModal()">
          Export Data
        </button>
      </div>

      <div class="export-notice">
        <div class="notice-icon">📊</div>
        <div>
          <strong>Real Data Export:</strong> Downloads actual sensor readings from
          <code>sensor_readings</code>. CSV includes UTF-8 BOM for Excel. JSON includes
          full export metadata. Maximum 50,000 rows per export.
        </div>
      </div>
    </div>
  </section>

  <!-- ============================================================
       RECENT EVENTS & ALERTS — filterable + paginated
       ============================================================ -->
  <section class="card mb-4">
    <div class="card-header">
      <h2 class="card-title">
        Recent Events &amp; Alerts
        <span class="badge badge-neutral" id="alert-count-badge" style="margin-left:8px;">
          <?= number_format($totalAlerts) ?> total
        </span>
      </h2>
      <div class="filter-controls">
        <select class="form-select form-select-sm" id="filter-greenhouse" onchange="loadAlerts(1)">
          <option value="all">All Greenhouses</option>
          <option value="A">Greenhouse A</option>
          <option value="B">Greenhouse B</option>
        </select>
        <select class="form-select form-select-sm" id="filter-severity" onchange="loadAlerts(1)" style="margin-left:8px;">
          <option value="all">All Events</option>
          <option value="critical">Critical Only</option>
          <option value="warning">Warnings Only</option>
          <option value="info">Info Only</option>
          <option value="success">Success Only</option>
        </select>
      </div>
    </div>

    <div id="alerts-table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Status</th><th>Timestamp</th><th>Greenhouse</th>
            <th>Parameter</th><th>Message</th><th>Action</th>
          </tr>
        </thead>
        <tbody id="alerts-tbody">
          <?php foreach ($firstAlerts as $a): ?>
          <tr class="<?= rowCls($a['severity']) ?>">
            <td><?= sevIcon($a['severity']) ?></td>
            <td><?= htmlspecialchars($a['ts_fmt']) ?></td>
            <td><?= ghBadge($a['gh_code']) ?></td>
            <td><?= htmlspecialchars(ucfirst($a['category'])) ?></td>
            <td><?= htmlspecialchars($a['message']) ?></td>
            <td>
              <?php if (in_array($a['severity'],['critical','warning'])): ?>
                <button class="btn-link" onclick='viewDetail(<?= json_encode((string)$a["alert_id"]) ?>)'>Details</button>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($firstAlerts)): ?>
          <tr><td colspan="6" class="text-center text-muted" style="padding:40px;">No events found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="pagination" id="alerts-pagination">
        <div class="pagination-info" id="pagination-info">
          Showing 1–<?= min($perPage, $totalAlerts) ?> of <?= number_format($totalAlerts) ?> events
        </div>
        <div class="pagination-controls" id="pagination-controls">
          <?php paginationBtns(1, $totalPages); ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ============================================================
       SUMMARY STATISTICS (auto-refresh every 60 s)
       ============================================================ -->
  <section class="stats-grid mb-4">
    <div class="stat-card">
      <div class="stat-header"><div class="stat-icon">📊</div><div class="stat-label">Total Data Points</div></div>
      <div class="stat-value" id="stat-dp"><?= number_format($dataPoints) ?></div>
      <div class="stat-meta">Last 72 hours</div>
    </div>
    <div class="stat-card">
      <div class="stat-header"><div class="stat-icon">🔴</div><div class="stat-label">Critical Alerts</div></div>
      <div class="stat-value" id="stat-crit"><?= $critCount ?></div>
      <div class="stat-meta">Last 24 hours</div>
    </div>
    <div class="stat-card">
      <div class="stat-header"><div class="stat-icon">⚠️</div><div class="stat-label">Warnings</div></div>
      <div class="stat-value" id="stat-warn"><?= $warnCount ?></div>
      <div class="stat-meta">Last 24 hours</div>
    </div>
    <div class="stat-card">
      <div class="stat-header"><div class="stat-icon">✓</div><div class="stat-label">Sensor Uptime</div></div>
      <div class="stat-value" id="stat-up"><?= $uptime ?>%</div>
      <div class="stat-meta"><?= $activeExp ? 'Current experiment' : 'All sensors' ?></div>
    </div>
  </section>

  <!-- ============================================================
       SENSOR PERFORMANCE REPORT
       ============================================================ -->
  <section class="card">
    <div class="card-header">
      <h2 class="card-title">Sensor Performance Report</h2>
      <span class="text-muted text-small">Last 7 Days</span>
    </div>
    <div class="sensor-performance" id="sensor-perf-wrap">
      <?php if (empty($perfData)): ?>
      <p class="text-center text-muted" style="padding:40px;">No sensor data for the last 7 days.</p>
      <?php else: ?>
        <?php foreach ($perfData as $s): ?>
        <div class="performance-item">
          <div class="performance-header">
            <div class="sensor-info">
              <div class="sensor-name-perf"><?= htmlspecialchars($s['label']) ?></div>
              <div class="sensor-location"><?= htmlspecialchars($s['ghs']) ?></div>
            </div>
            <div class="performance-status <?= $s['grade'] ?>"><?= ucfirst($s['grade']) ?></div>
          </div>
          <div class="performance-bar">
            <div class="bar-fill <?= $s['grade']==='degraded' ? 'warning' : '' ?>"
                 style="width:<?= min(100, max(0, $s['pct'])) ?>%"></div>
          </div>
          <div class="performance-stats">
            <span>Uptime: <?= $s['pct'] ?>%</span>
            <span>Readings: <?= number_format($s['reads']) ?></span>
            <span>Errors: <?= number_format($s['errors']) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

</main>

<!-- ============================================================
     MODAL: Confirm Export
     ============================================================ -->
<div id="confirm-modal" class="modal-overlay" onclick="closeOnBg(event,'confirm-modal')">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-icon confirm">📋</div>
      <h3 class="modal-title">Confirm Data Export</h3>
    </div>
    <div class="modal-body">
      <p>You are about to export the following data:</p>
      <div class="export-summary">
        <div class="summary-row">
          <span class="summary-label">Greenhouse:</span>
          <span class="summary-value" id="m-gh">Both Greenhouses</span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Date Range:</span>
          <span class="summary-value" id="m-dr">Last 24 Hours</span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Format:</span>
          <span class="summary-value" id="m-fmt">Excel Styled (.xls)</span>
        </div>
        <div class="summary-row">
          <span class="summary-label">Max Rows:</span>
          <span class="summary-value">50,000 readings</span>
        </div>
      </div>
      <p class="modal-note">The file will be streamed directly from the server and saved to your downloads folder.</p>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="hideModal('confirm-modal')">Cancel</button>
      <button class="btn btn-primary"   onclick="doExport()">Confirm Export</button>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Alert Detail
     ============================================================ -->
<div id="detail-modal" class="modal-overlay" onclick="closeOnBg(event,'detail-modal')">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-icon confirm" id="d-icon">🔴</div>
      <h3 class="modal-title" id="d-title">Alert Detail</h3>
    </div>
    <div class="modal-body" id="d-body"><p class="text-muted">Loading…</p></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="hideModal('detail-modal')">Close</button>
    </div>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
// Data injected by PHP (first-page alert store for detail modal)
let alertStore = <?= json_encode(
    array_map(fn($a) => [
        'alert_id'     => (string)$a['alert_id'],
        'severity'     => $a['severity'],
        'category'     => $a['category'],
        'message'      => $a['message'],
        'sensor_value' => $a['sensor_value'],
        'is_resolved'  => (bool)$a['is_resolved'],
        'ts_fmt'       => $a['ts_fmt'],
        'gh_code'      => $a['gh_code'],
    ], $firstAlerts),
    JSON_UNESCAPED_UNICODE
) ?>;

const API = 'reports/api/reports_api.php';
let currentAlertPage = 1;
let exportFeedbackTimer = null;

// Shows a toast message so action results are easier to notice while debugging.
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show toast-' + type;
    clearTimeout(exportFeedbackTimer);
    exportFeedbackTimer = setTimeout(() => { t.className = 'toast'; }, 5000);
}

// ============================================================
// ALERTS: load page via AJAX
// ============================================================
async function loadAlerts(page = 1) {
    currentAlertPage = page;
    const gh  = document.getElementById('filter-greenhouse').value;
    const sev = document.getElementById('filter-severity').value;
    const url = `${API}?action=alerts&page=${page}&greenhouse=${gh}&severity=${sev}`;

    try {
        const res  = await fetch(url);
        const data = await res.json();
        if (!data.success) throw new Error(data.error);

        alertStore = data.data;
        renderTable(data.data);
        renderPager(data.page, data.total_pages, data.total);
        document.getElementById('alert-count-badge').textContent =
            Number(data.total).toLocaleString() + ' total';
    } catch (e) {
        document.getElementById('alerts-tbody').innerHTML =
            `<tr><td colspan="6" class="text-center text-muted" style="padding:32px;">
             ⚠️ Failed to load: ${esc(e.message)}</td></tr>`;
        showToast('Failed to refresh alerts.', 'error');
    }
}

// Renders table in the current interface.
function renderTable(rows) {
    const tbody = document.getElementById('alerts-tbody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:40px;">No events match your filters.</td></tr>';
        return;
    }
    const icons = {critical:'🔴',warning:'⚠️',success:'✓',info:'ℹ️'};
    const rcls  = {critical:'row-critical',warning:'row-warning'};
    tbody.innerHTML = rows.map(a => {
        const icon  = `<span class="event-icon ${a.severity}">${icons[a.severity]||'ℹ️'}</span>`;
        const badge = a.gh_code
            ? `<span class="badge badge-greenhouse-${a.gh_code.toLowerCase()}">Greenhouse ${a.gh_code}</span>`
            : '<span class="text-muted">—</span>';
        const act   = ['critical','warning'].includes(a.severity)
            ? `<button class="btn-link" onclick='viewDetail(${JSON.stringify(String(a.alert_id))})'>Details</button>` : '—';
        return `<tr class="${rcls[a.severity]||''}">
            <td>${icon}</td><td>${a.ts_fmt}</td><td>${badge}</td>
            <td>${cap(a.category)}</td><td>${esc(a.message)}</td><td>${act}</td>
        </tr>`;
    }).join('');
}

// Renders pager in the current interface.
function renderPager(page, totalPages, total) {
    const from = ((page-1)*10)+1, to = Math.min(page*10, total);
    document.getElementById('pagination-info').textContent =
        `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${total.toLocaleString()} events`;

    const ctrl = document.getElementById('pagination-controls');
    let h = `<button class="page-btn" ${page<=1?'disabled':''} onclick="loadAlerts(${page-1})">‹</button>`;
    pageRange(page, totalPages).forEach(p => {
        h += p === '…'
            ? `<button class="page-btn" disabled>…</button>`
            : `<button class="page-btn ${p===page?'active':''}" onclick="loadAlerts(${p})">${p}</button>`;
    });
    h += `<button class="page-btn" ${page>=totalPages?'disabled':''} onclick="loadAlerts(${page+1})">›</button>`;
    ctrl.innerHTML = h;
}

// Builds the page range used by the report paginator.
function pageRange(cur, tot) {
    if (tot <= 7) return Array.from({length:tot},(_,i)=>i+1);
    const p=[1]; if(cur>3) p.push('…');
    for(let i=Math.max(2,cur-1);i<=Math.min(tot-1,cur+1);i++) p.push(i);
    if(cur<tot-2) p.push('…'); p.push(tot); return p;
}

// ============================================================
// ALERT DETAIL MODAL
// ============================================================
// Opens the alert detail modal for the selected report row.
function viewDetail(id) {
    const a = alertStore.find(x => String(x.alert_id) === String(id));
    if (!a) { showModal('detail-modal'); return; }

    const emj = {critical:'🔴',warning:'⚠️',success:'✓',info:'ℹ️'};
    document.getElementById('d-icon').textContent  = emj[a.severity]||'ℹ️';
    document.getElementById('d-title').textContent = `${cap(a.severity)} — ${cap(a.category)}`;

    const badgeCls = {critical:'badge-danger',warning:'badge-warning',info:'badge-info',success:'badge-success'};
    document.getElementById('d-body').innerHTML = `
        <div class="export-summary">
          <div class="summary-row"><span class="summary-label">Severity</span>
            <span class="summary-value"><span class="badge ${badgeCls[a.severity]||'badge-neutral'}">${cap(a.severity)}</span></span></div>
          <div class="summary-row"><span class="summary-label">Category</span>
            <span class="summary-value">${cap(a.category)}</span></div>
          <div class="summary-row"><span class="summary-label">Greenhouse</span>
            <span class="summary-value">${a.gh_code ? 'Greenhouse '+a.gh_code : '—'}</span></div>
          <div class="summary-row"><span class="summary-label">Timestamp</span>
            <span class="summary-value">${a.ts_fmt}</span></div>
          ${a.sensor_value!=null
            ? `<div class="summary-row"><span class="summary-label">Sensor Value</span>
               <span class="summary-value">${a.sensor_value}</span></div>` : ''}
          <div class="summary-row"><span class="summary-label">Status</span>
            <span class="summary-value">${a.is_resolved ? '✅ Resolved' : '🔴 Open / Unresolved'}</span></div>
        </div>
        <p style="margin-top:16px;font-size:15px;">${esc(a.message)}</p>`;

    showModal('detail-modal');
}

// ============================================================
// EXPORT
// ============================================================
const ghLbl  = {both:'Both Greenhouses',A:'Greenhouse A',B:'Greenhouse B'};
const drLbl  = {'24h':'Last 24 Hours','7d':'Last 7 Days','30d':'Last 30 Days',
                experiment:'Current Experiment',custom:'Custom Range'};
const fmtLbl = {xls:'Excel Styled (.xls)',csv:'CSV (Excel)',json:'JSON'};

// Toggles custom dates state in the interface.
function toggleCustomDates() {
    const show = document.getElementById('date-range-select').value === 'custom';
    document.getElementById('custom-date-row').style.display = show ? 'grid' : 'none';
}

// Shows confirm modal in the interface.
function showConfirmModal() {
    const gh  = document.getElementById('greenhouse-select').value;
    const dr  = document.getElementById('date-range-select').value;
    const fmt = document.getElementById('format-select').value;
    if (dr === 'custom') {
        const df = document.getElementById('date-from')?.value || '';
        const dt = document.getElementById('date-to')?.value || '';
        if (!df || !dt) {
            showToast('Select both custom dates before exporting.', 'warning');
            return;
        }
    }
    document.getElementById('m-gh').textContent  = ghLbl[gh]  || gh;
    document.getElementById('m-dr').textContent  = drLbl[dr]  || dr;
    document.getElementById('m-fmt').textContent = fmtLbl[fmt]|| fmt;
    showModal('confirm-modal');
}

// Starts the report export flow using the current filters.
function doExport() {
    hideModal('confirm-modal');

    const gh  = document.getElementById('greenhouse-select').value;
    const dr  = document.getElementById('date-range-select').value;
    const fmt = document.getElementById('format-select').value;
    const df  = document.getElementById('date-from')?.value || '';
    const dt  = document.getElementById('date-to')?.value   || '';

    let url = `${API}?action=export&greenhouse=${encodeURIComponent(gh)}&date_range=${encodeURIComponent(dr)}&format=${encodeURIComponent(fmt)}`;
    if (dr === 'custom' && df && dt) url += `&date_from=${encodeURIComponent(df)}&date_to=${encodeURIComponent(dt)}`;

    // Trigger browser download without leaving the page
    const btn = document.getElementById('export-btn');
    btn.disabled = true; btn.textContent = 'Preparing download…';
    showToast('Preparing export. Your download should start shortly.');

    const a = document.createElement('a');
    a.href = url; a.download = ''; document.body.appendChild(a); a.click(); document.body.removeChild(a);

    setTimeout(() => {
        btn.disabled = false;
        btn.textContent = 'Export Data';
        showToast('Export started. Check your downloads folder.', 'success');
    }, 2500);
}

// ============================================================
// LIVE STATS REFRESH (every 60 s)
// ============================================================
async function refreshStats() {
    try {
        const d = await (await fetch(`${API}?action=stats`)).json();
        if (!d.success) return;
        document.getElementById('stat-dp').textContent   = Number(d.data_points).toLocaleString();
        document.getElementById('stat-crit').textContent = d.critical;
        document.getElementById('stat-warn').textContent = d.warnings;
        document.getElementById('stat-up').textContent   = d.uptime + '%';
    } catch(_){}
}
// Refreshes the live report issues without reloading the page.
function refreshReportsLive() {
    refreshStats();
    loadAlerts(currentAlertPage);
}

window.addEventListener('DOMContentLoaded', () => {
    refreshReportsLive();
});

setInterval(refreshReportsLive, 15_000);

// ============================================================
// MODAL + PROFILE HELPERS
// ============================================================
// Shows modal in the interface.
function showModal(id)  { document.getElementById(id).style.display='flex'; }
// Hides modal in the interface.
// Hides modal in the interface.
function hideModal(id)  { document.getElementById(id).style.display='none'; }
// Closes the on bg panel or modal.
function closeOnBg(e,id){ if(e.target===document.getElementById(id)) hideModal(id); }

// Toggles the profile dropdown menu in the page header.
function toggleProfileDropdown(e) {
    e.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('active');
}
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
        } else {
            showToast('Logout failed.', 'error');
        }
    })
    .catch(() => showToast('Logout failed.', 'error'));
}
document.addEventListener('click', e => {
    if (!e.target.closest('.profile-icon') && !e.target.closest('.profile-dropdown'))
        document.getElementById('profileDropdown').classList.remove('active');
});

// Mini utils
// Capitalizes the first character of a string for display.
function cap(s){ return s ? s[0].toUpperCase()+s.slice(1) : ''; }
// Escapes text before it is inserted into the page.
function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>
</body>
</html>
