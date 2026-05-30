<?php
// ============================================================================
// ECOTWIN — DASHBOARD
// ============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/query_helpers.php';
require_once __DIR__ . '/preferences.php';

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['last_regen']) || time() - $_SESSION['last_regen'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}

$pdo = db();
$preferences = ecotwinLoadUserPreferences($pdo, (int)($_SESSION['user_id'] ?? 0));
$profileDetails = ecotwinLoadUserProfileDetails($pdo, (int)($_SESSION['user_id'] ?? 0));
$preferenceBodyClass = ecotwinPreferenceBodyClass($preferences);
$t = fn(string $key, array $replacements = []) => ecotwinT($preferences['language'], $key, $replacements);

// ============================================================================
// DATA QUERIES
// ============================================================================

// ── 1. Active experiment ──────────────────────────────────────────────────────
try {
    $activeExp = $pdo->query(
        "SELECT e.experiment_id, e.exp_code, e.title, e.started_at, e.expected_end_at,
                e.principal_user_id,
                u.full_name AS researcher_name,
                TIMESTAMPDIFF(DAY, e.started_at, NOW())  AS days_running,
                TIMESTAMPDIFF(HOUR, e.started_at, NOW()) AS hours_running
           FROM experiments e
           JOIN users u ON e.principal_user_id = u.user_id
          WHERE e.status = 'active'
          LIMIT 1"
    )->fetch();
} catch (PDOException $e) {
    error_log('Dashboard activeExp: ' . $e->getMessage());
    $activeExp = null;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$dashboardOwnsActiveExperiment = $activeExp
    && (int)($activeExp['principal_user_id'] ?? 0) === $currentUserId;
$dashboardLockedByOtherExperiment = $activeExp && !$dashboardOwnsActiveExperiment;

if (!$activeExp && ($_SESSION['user_role'] ?? 'researcher') !== 'admin') {
    header('Location: experiments.php');
    exit;
}

// ── 2. Sensor counts ──────────────────────────────────────────────────────────
try {
    $sensorRow = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(status = 'online')  AS online_count,
                SUM(status = 'offline') AS offline_count
           FROM sensors"
    )->fetch();
} catch (PDOException $e) {
    error_log('Dashboard sensorRow: ' . $e->getMessage());
    $sensorRow = ['total' => 0, 'online_count' => 0, 'offline_count' => 0];
}

$sensorsTotal   = (int)($sensorRow['total']         ?? 0);
$sensorsOnline  = (int)($sensorRow['online_count']  ?? 0);
$sensorsOffline = (int)($sensorRow['offline_count'] ?? 0);

// ── 3. Last data sync time ────────────────────────────────────────────────────
try {
    $lastSyncRaw = $pdo->query(
        "SELECT MAX(synced_at) AS last_sync FROM sensor_readings"
    )->fetchColumn();
} catch (PDOException $e) {
    error_log('Dashboard lastSync: ' . $e->getMessage());
    $lastSyncRaw = null;
}

if ($lastSyncRaw) {
    $diff          = time() - strtotime($lastSyncRaw);
    $mins          = (int)floor($diff / 60);
    $lastSyncLabel = $mins < 1 ? 'Just now'
                   : ($mins === 1 ? '1 min ago' : "{$mins} min ago");
} else {
    $lastSyncLabel = 'No data yet';
}

// ── 4. System / hardware status ───────────────────────────────────────────────
try {
    $hwOffline = (int)$pdo->query(
        "SELECT COUNT(*) FROM hardware_components WHERE status != 'online'"
    )->fetchColumn();
} catch (PDOException $e) {
    error_log('Dashboard hwOffline: ' . $e->getMessage());
    $hwOffline = 0;
}

$systemStatus = $hwOffline === 0 ? 'Operational' : 'Degraded';
$systemClass  = $hwOffline === 0 ? 'status-operational' : '';

// ── 5. Open critical alerts (banner) ─────────────────────────────────────────
$criticalAlerts = [];

// ── 6. Greenhouses with sensor counts ────────────────────────────────────────
try {
    $greenhouses = $pdo->query(
        "SELECT g.greenhouse_id, g.code, g.name, g.role,
                p.name  AS plant_name,
                p.emoji AS plant_emoji,
                (SELECT COUNT(*) FROM sensors s
                  WHERE s.greenhouse_id = g.greenhouse_id
                    AND s.status = 'online') AS sensors_online,
                (SELECT COUNT(*) FROM sensors s
                  WHERE s.greenhouse_id = g.greenhouse_id) AS sensors_total
           FROM greenhouses g
           LEFT JOIN plants p ON g.assigned_plant_id = p.plant_id
          ORDER BY g.code ASC"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Dashboard greenhouses: ' . $e->getMessage());
    $greenhouses = [];
}

// ── 7. Latest readings per greenhouse (temp, humidity, light) ─────────────────
$ghReadings = [];
foreach ($greenhouses as $gh) {
    $ghId = $gh['greenhouse_id'];
    try {
        $ghReadings[$ghId] = ecotwinFetchLatestReadingsMap(
            $pdo,
            (int)$ghId,
            ['temperature', 'humidity', 'light', 'ph', 'ec', 'water_level', 'water_temp']
        );
    } catch (PDOException $e) {
        error_log("Dashboard ghReadings gh{$ghId}: " . $e->getMessage());
        $ghReadings[$ghId] = [];
    }
}

$ghThresholds = [];
foreach ($greenhouses as $gh) {
    $ghId = (int)$gh['greenhouse_id'];
    try {
        $stmt = $pdo->prepare(
            "SELECT pt.parameter, pt.val_min, pt.val_opt_low, pt.val_opt_high, pt.val_max, pt.unit
               FROM greenhouses g
               JOIN plant_thresholds pt ON pt.plant_id = g.assigned_plant_id
              WHERE g.greenhouse_id = ?"
        );
        $stmt->execute([$ghId]);
        $thresholds = [];
        foreach ($stmt->fetchAll() as $row) {
            $thresholds[$row['parameter']] = $row;
        }
        $ghThresholds[$ghId] = $thresholds;
    } catch (PDOException $e) {
        error_log("Dashboard ghThresholds gh{$ghId}: " . $e->getMessage());
        $ghThresholds[$ghId] = [];
    }
}

$ghLiveStatus = [];
foreach ($greenhouses as $gh) {
    $ghId = (int)$gh['greenhouse_id'];
    $readings = $ghReadings[$ghId] ?? [];
    $thresholds = $ghThresholds[$ghId] ?? [];
    $status = 'optimal';
    $criticalParam = null;
    $criticalValue = null;
    $criticalRecordedAt = null;

    foreach ($thresholds as $parameter => $threshold) {
        if (!isset($readings[$parameter]['value'])) {
            continue;
        }

        $value = (float)$readings[$parameter]['value'];
        if ($value < (float)$threshold['val_min'] || $value > (float)$threshold['val_max']) {
            $status = 'critical';
            $criticalParam = $parameter;
            $criticalValue = $value;
            $criticalRecordedAt = $readings[$parameter]['recorded_at'] ?? null;
            break;
        }

        if ($value < (float)$threshold['val_opt_low'] || $value > (float)$threshold['val_opt_high']) {
            $status = 'caution';
        }
    }

    $ghLiveStatus[$ghId] = [
        'status' => $status,
        'critical_param' => $criticalParam,
        'critical_value' => $criticalValue,
    ];

    if ($status === 'critical' && $criticalParam !== null) {
        $criticalAlerts[] = [
            'severity' => 'critical',
            'category' => $criticalParam,
            'message' => ucfirst(str_replace('_', ' ', $criticalParam)) . ' is outside the configured safe range',
            'sensor_value' => $criticalValue,
            'created_at' => $criticalRecordedAt,
            'gh_code' => $gh['code'],
        ];
    }
}

// ── 8. Recent events & alerts (latest 5) ─────────────────────────────────────
try {
    $recentAlerts = $pdo->query(
        "SELECT a.severity, a.category, a.message, a.created_at,
                g.code AS gh_code
           FROM alerts a
           LEFT JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
          ORDER BY a.created_at DESC
          LIMIT 5"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Dashboard recentAlerts: ' . $e->getMessage());
    $recentAlerts = [];
}

// ── 9. Hardware components ────────────────────────────────────────────────────
try {
    $hardware = $pdo->query(
        "SELECT label, type, status
           FROM hardware_components
          ORDER BY component_id ASC"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Dashboard hardware: ' . $e->getMessage());
    $hardware = [];
}

try {
    $analyticsRow = $pdo->query(
        "SELECT
            COUNT(*) AS readings_count,
            AVG(CASE WHEN parameter = 'temperature' THEN value END) AS avg_temperature,
            AVG(CASE WHEN parameter = 'humidity' THEN value END) AS avg_humidity,
            AVG(CASE WHEN parameter = 'light' THEN value END) AS avg_light
         FROM sensor_readings
         WHERE recorded_at >= NOW() - INTERVAL 7 DAY"
    )->fetch();
} catch (PDOException $e) {
    error_log('Dashboard analyticsRow: ' . $e->getMessage());
    $analyticsRow = ['readings_count' => 0, 'avg_temperature' => null, 'avg_humidity' => null, 'avg_light' => null];
}

try {
    $topGreenhouse = $pdo->query(
        "SELECT g.code, COUNT(sr.reading_id) AS reading_count
         FROM sensor_readings sr
         JOIN sensors s ON s.sensor_id = sr.sensor_id
         JOIN greenhouses g ON g.greenhouse_id = s.greenhouse_id
         WHERE sr.recorded_at >= NOW() - INTERVAL 7 DAY
         GROUP BY g.greenhouse_id, g.code
         ORDER BY reading_count DESC, g.code ASC
         LIMIT 1"
    )->fetch();
} catch (PDOException $e) {
    error_log('Dashboard topGreenhouse: ' . $e->getMessage());
    $topGreenhouse = null;
}

if ($dashboardLockedByOtherExperiment) {
    $sensorsTotal = 0;
    $sensorsOnline = 0;
    $sensorsOffline = 0;
    $lastSyncLabel = 'No values';
    $systemStatus = 'Restricted';
    $systemClass = '';
    $criticalAlerts = [];
    $recentAlerts = [];
    $hardware = [];
    $analyticsRow = [
        'readings_count' => 0,
        'avg_temperature' => null,
        'avg_humidity' => null,
        'avg_light' => null,
    ];
    $topGreenhouse = null;
}

$greenhousePhotoWebPath = 'assets/Greenhouse_Model.png';
$greenhousePhotoExists = is_file(__DIR__ . '/' . $greenhousePhotoWebPath);

// ============================================================================
// HELPERS
// ============================================================================

// Returns the icon output for severity display.
function severity_icon(string $sev): string {
    return match($sev) {
        'critical' => '<span class="event-icon critical">🔴</span>',
        'warning'  => '<span class="event-icon warning">⚠️</span>',
        'success'  => '<span class="event-icon success">✓</span>',
        default    => '<span class="event-icon info">ℹ️</span>',
    };
}

// Formats ts for display.
function format_ts(string $ts): string {
    return date('M j, g:i A', strtotime($ts));
}

// Builds the badge output for greenhouse display.
function gh_badge(string $code): string {
    $cls = strtolower($code) === 'a' ? 'badge-greenhouse-a' : 'badge-greenhouse-b';
    return '<span class="badge ' . $cls . '">Greenhouse ' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>';
}

// Returns the icon output for hardware display.
function hw_icon(string $type): string {
    return match($type) {
        'arduino'      => '📟',
        'esp32'        => '📡',
        'nrf_module'   => '📻',
        'relay'        => '⚡',
        'ups'          => '🔋',
        'power_supply' => '🔌',
        default        => '🖥️',
    };
}

// Returns the indicator styling for status display.
function status_dot_cls(string $status): string {
    return match($status) {
        'online'   => 'status-online',
        'offline'  => 'status-offline',
        'degraded' => 'status-warning',
        default    => 'status-offline',
    };
}

// Session display vars
$userName     = e($_SESSION['user_name']  ?? 'User');
$userEmail    = e($_SESSION['user_email'] ?? '');
$userRole     = $_SESSION['user_role']   ?? 'researcher';
$userInitials = strtoupper(implode('', array_map(
    fn($w) => $w[0],
    array_slice(explode(' ', trim($_SESSION['user_name'] ?? 'U')), 0, 2)
)));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($preferences['language']) ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($t('dashboard.title')) ?> — EcoTwin</title>
    <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>" />
    <link rel="stylesheet" href="css.dashboard.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.dashboard.css')) ?>" />
</head>
<body class="<?= htmlspecialchars($preferenceBodyClass) ?>"
      data-language="<?= htmlspecialchars($preferences['language']) ?>"
      data-timezone="<?= htmlspecialchars($preferences['timezone']) ?>"
      data-date-format="<?= htmlspecialchars($preferences['date_format']) ?>">

<!-- ================================================================ -->
<!-- NAVBAR                                                            -->
<!-- ================================================================ -->
<nav class="navbar">
    <div class="navbar-container">
        <a href="dashboard.php" class="navbar-logo">
            <img src="ECOTwin_Logo.png" alt="EcoTwin logo" class="logo-icon" />
            <span class="logo-text">EcoTwin</span>
        </a>

        <div class="navbar-menu" id="navbarMenu">
            <a href="dashboard.php"    class="nav-item active"><?= htmlspecialchars($t('nav.dashboard')) ?></a>
            <a href="experiments.php" class="nav-item"><?= htmlspecialchars($t('nav.experiments')) ?></a>
            <a href="greenhouses.php" class="nav-item"><?= htmlspecialchars($t('nav.greenhouses')) ?></a>
            <a href="reports.php"     class="nav-item"><?= htmlspecialchars($t('nav.reports')) ?></a>
            <?php if ($userRole === 'admin'): ?>
            <a href="admin.php"       class="nav-item"><?= htmlspecialchars($t('nav.admin')) ?></a>
            <?php endif; ?>
        </div>

        <div class="navbar-user">
            <div class="profile-icon <?= !empty($profileDetails['avatar_url']) ? 'has-avatar' : '' ?>" onclick="toggleProfileDropdown(event)">
                <?php if (!empty($profileDetails['avatar_url'])): ?>
                <img src="<?= e($profileDetails['avatar_url']) ?>" alt="Profile avatar" />
                <?php else: ?>
                <?= e($userInitials) ?>
                <?php endif; ?>
            </div>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-dropdown-header">
                    <div class="profile-user-info">
                        <div class="profile-user-name"><?= $userName ?></div>
                        <div class="profile-user-email"><?= $userEmail ?></div>
                        <div class="profile-user-role"><?= e(ucfirst($userRole)) ?></div>
                    </div>
                </div>
                <div class="profile-dropdown-body">
                    <a href="profile_settings.php" class="profile-menu-item"><?= htmlspecialchars($t('menu.profile_settings')) ?></a>
                    <a href="preference_settings.php" class="profile-menu-item"><?= htmlspecialchars($t('menu.preferences')) ?></a>
                </div>
                <div class="profile-dropdown-footer">
                    <form id="logoutForm" method="POST" action="auth_handler.php" style="margin:0;">
                        <input type="hidden" name="action" value="logout" />
                        <button type="submit" class="logout-btn"><?= htmlspecialchars($t('menu.logout')) ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ================================================================ -->
<!-- MAIN CONTENT                                                      -->
<!-- ================================================================ -->
<main class="main-content">

    <section class="dashboard-guide card mb-3">
        <div>
            <span class="guide-kicker">Dashboard guide</span>
            <h1 class="guide-title">Live view of both greenhouse chambers</h1>
            <p class="guide-copy">
                This page summarizes the current experiment, sensor health, latest readings, and alerts.
                Hover the sensor points on each greenhouse model to see what every reading means and when it was last recorded.
            </p>
        </div>
        <div class="guide-legend" aria-label="Status legend">
            <span><i class="legend-dot optimal"></i> Optimal</span>
            <span><i class="legend-dot caution"></i> Caution</span>
            <span><i class="legend-dot critical"></i> Critical</span>
        </div>
    </section>

    <!-- ── Summary Cards ─────────────────────────────────────────────── -->
    <section class="visual-guide-grid mb-3" aria-label="Dashboard visual guide">
        <article class="visual-guide-card">
            <div class="visual-guide-icon experiment-icon" aria-hidden="true">
                <span></span>
            </div>
            <div>
                <span class="visual-guide-kicker">Step 1</span>
                <h2>Check the experiment</h2>
                <p>
                    <?= $activeExp
                        ? 'An experiment is currently running, so the dashboard is showing live greenhouse context.'
                        : 'No experiment is running yet. Start one from the Experiments page before comparing readings.' ?>
                </p>
            </div>
        </article>

        <article class="visual-guide-card">
            <div class="visual-guide-icon sensor-icon" aria-hidden="true">
                <span></span>
                <i></i>
            </div>
            <div>
                <span class="visual-guide-kicker">Step 2</span>
                <h2>Read the sensor map</h2>
                <p>
                    Colored points on each greenhouse show sensor locations. Open a point to see the value, crop range, and latest timestamp.
                </p>
            </div>
        </article>

        <article class="visual-guide-card">
            <div class="visual-guide-icon action-icon" aria-hidden="true">
                <span></span>
            </div>
            <div>
                <span class="visual-guide-kicker">Step 3</span>
                <h2>Act on alerts</h2>
                <p>
                    Green means normal, gold means watch closely, and red means the chamber needs attention before results drift.
                </p>
            </div>
        </article>
    </section>

    <section class="summary-grid mb-3">

        <div class="summary-card">
            <div class="summary-icon" style="color:#2E8B57">🧪</div>
            <div class="summary-value"><?= $dashboardLockedByOtherExperiment ? 'No values' : ($activeExp ? 1 : 0) ?></div>
            <div class="summary-label"><?= htmlspecialchars($t('dashboard.summary.active_experiment')) ?></div>
            <?php if ($dashboardLockedByOtherExperiment): ?>
                <div class="summary-meta">There is a ongoing experiment</div>
            <?php elseif ($activeExp): ?>
                <div class="summary-meta"><?= e($activeExp['exp_code']) ?> Running</div>
            <?php else: ?>
                <div class="summary-meta" style="color:#9CA3AF;">None running</div>
            <?php endif; ?>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="color:#10b981">📡</div>
            <div class="summary-value"><?= $dashboardLockedByOtherExperiment ? 'No values' : ($sensorsOnline . '/' . $sensorsTotal) ?></div>
            <div class="summary-label"><?= htmlspecialchars($t('dashboard.summary.sensors_online')) ?></div>
            <?php if ($dashboardLockedByOtherExperiment): ?>
                <div class="summary-meta">Readings are hidden for this user</div>
            <?php elseif ($sensorsOffline > 0): ?>
                <div class="summary-warning">⚠️ <?= $sensorsOffline ?> offline</div>
            <?php else: ?>
                <div class="summary-meta">All sensors online</div>
            <?php endif; ?>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="color:#3b82f6">🔄</div>
            <div class="summary-value"><?= e($lastSyncLabel) ?></div>
            <div class="summary-label"><?= htmlspecialchars($t('dashboard.summary.last_data_sync')) ?></div>
            <?php if (!$dashboardLockedByOtherExperiment): ?>
            <div class="pulse-indicator">
                <span class="pulse-dot"></span>
                <span class="pulse-text">Live</span>
            </div>
            <?php else: ?>
                <div class="summary-meta">Waiting for your own experiment</div>
            <?php endif; ?>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="color:#10b981">🖥️</div>
            <div class="summary-value <?= e($systemClass) ?>"><?= e($systemStatus) ?></div>
            <div class="summary-label"><?= htmlspecialchars($t('dashboard.summary.system_status')) ?></div>
            <?php if ($dashboardLockedByOtherExperiment): ?>
                <div class="summary-meta">Restricted while another experiment is running</div>
            <?php elseif ($hwOffline > 0): ?>
                <div class="summary-warning">⚠️ <?= $hwOffline ?> component(s) down</div>
            <?php endif; ?>
        </div>

    </section>

    <?php if ($dashboardLockedByOtherExperiment): ?>
    <section class="card dashboard-locked-state mb-3">
        <div class="locked-state-icon">🔒</div>
        <div>
            <h2>There is a ongoing experiment</h2>
            <p>
                Another researcher started the current greenhouse experiment, so the live dashboard readings are hidden.
                Start your own experiment when the active one is completed, or ask the principal researcher for the report.
            </p>
            <a href="experiments.php" class="btn btn-primary">Go to Experiments</a>
        </div>
    </section>
    <?php else: ?>
    <section class="card analytics-overview-card mb-3">
        <div class="card-header">
            <div>
                <h2 class="card-title"><?= htmlspecialchars($t('dashboard.analytics.title')) ?></h2>
                <div class="preferences-subtitle"><?= htmlspecialchars($t('dashboard.analytics.subtitle')) ?></div>
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
            <?= $topGreenhouse ? 'Greenhouse ' . e($topGreenhouse['code']) . ' • ' . number_format((int)$topGreenhouse['reading_count']) . ' readings' : htmlspecialchars($t('dashboard.analytics.no_data')) ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── Active Experiment Banner ──────────────────────────────────── -->
    <?php if ($activeExp): ?>
    <div class="experiment-banner mb-3">
        <div class="banner-content">
            <div class="banner-icon">🔬</div>
            <div class="banner-text">
                <div class="banner-title">
                    Experiment Active: <?= e($activeExp['title']) ?>
                </div>
                <div class="banner-subtitle">
                    Greenhouse A &amp; B Reserved
                    &bull; Started <?= (int)$activeExp['days_running'] ?> day(s) ago
                    by <?= e($activeExp['researcher_name']) ?>
                </div>
            </div>
        </div>
        <div class="banner-actions">
            <a href="experiments.php" class="btn btn-secondary btn-sm">View Details</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Critical Alert Banners ─────────────────────────────────────── -->
    <?php foreach ($criticalAlerts as $alert): ?>
    <div class="alert alert-danger mb-3">
        <span class="alert-icon">🔴</span>
        <div>
            <strong>Critical Alert:</strong>
            <?= e($alert['message']) ?>
            <?php if (!empty($alert['gh_code'])): ?>
                <a href="greenhouses.php" class="alert-link">
                    View Greenhouse <?= e($alert['gh_code']) ?> →
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!$dashboardLockedByOtherExperiment): ?>
    <div class="section-explainer mb-2">
        <div>
            <h2>Greenhouse sensor map</h2>
            <p>Each chamber below is shown as a greenhouse model. The colored points are live sensor locations: green is normal, gold needs watching, red needs action, and gray means no latest reading.</p>
        </div>
    </div>

    <!-- ── Greenhouse Quick Status ───────────────────────────────────── -->
    <section class="greenhouse-status-grid mb-3">
        <?php foreach ($greenhouses as $gh):
            $ghId     = $gh['greenhouse_id'];
            $readings = $ghReadings[$ghId] ?? [];
            $liveMeta = $ghLiveStatus[$ghId] ?? ['status' => 'optimal'];
            $hasCrit  = ($liveMeta['status'] ?? 'optimal') === 'critical';
            $hasCaution = ($liveMeta['status'] ?? 'optimal') === 'caution';
            $cardCls  = strtolower($gh['code']) === 'a' ? 'gh-a' : 'gh-b';

            $temp  = $readings['temperature'] ?? null;
            $hum   = $readings['humidity']    ?? null;
            $light = $readings['light']       ?? null;

            // Flag critical if temp reading exceeds 30 °C or open critical alert exists
            $tempCrit = $hasCrit;
        ?>
        <div class="greenhouse-status-card <?= $cardCls ?>">
            <div class="gh-header">
                <div class="gh-icon">🏠</div>
                <div>
                    <div class="gh-title">Greenhouse <?= e($gh['code']) ?></div>
                    <div class="gh-subtitle"><?= e(ucfirst($gh['role'])) ?> Group</div>
                </div>
                <?php if ($hasCrit): ?>
                    <span class="badge badge-danger">Critical</span>
                <?php elseif ($hasCaution): ?>
                    <span class="badge badge-warning">Caution</span>
                <?php else: ?>
                    <span class="badge badge-success">Optimal</span>
                <?php endif; ?>
            </div>

            <?php
                $sensorPoints = [
                    ['key' => 'temperature', 'label' => 'Temperature', 'mark' => 'T', 'unit' => 'C', 'precision' => 1, 'x' => 24, 'y' => 28, 'anchorX' => 44, 'anchorY' => 42, 'line' => 'line-temp', 'note' => 'Air temperature near the plant canopy. High values can trigger cooling or ventilation.'],
                    ['key' => 'humidity', 'label' => 'Humidity', 'mark' => 'H', 'unit' => '%', 'precision' => 0, 'x' => 76, 'y' => 28, 'anchorX' => 56, 'anchorY' => 42, 'line' => 'line-humidity', 'note' => 'Air moisture level. Low humidity can stress seedlings; high humidity can invite disease.'],
                    ['key' => 'light', 'label' => 'Light', 'mark' => 'L', 'unit' => 'lux', 'precision' => 0, 'x' => 50, 'y' => 12, 'anchorX' => 50, 'anchorY' => 24, 'line' => 'line-light', 'note' => 'Light intensity reaching the chamber. This helps compare treatment and control conditions.'],
                    ['key' => 'ph', 'label' => 'pH', 'mark' => 'pH', 'unit' => 'pH', 'precision' => 1, 'x' => 28, 'y' => 76, 'anchorX' => 44, 'anchorY' => 66, 'line' => 'line-ph', 'note' => 'Nutrient solution acidity. Keep this inside the crop range for good nutrient uptake.'],
                    ['key' => 'ec', 'label' => 'EC', 'mark' => 'EC', 'unit' => 'mS/cm', 'precision' => 2, 'x' => 72, 'y' => 76, 'anchorX' => 56, 'anchorY' => 66, 'line' => 'line-ec', 'note' => 'Electrical conductivity of nutrients. It shows how concentrated the solution is.'],
                    ['key' => 'water_level', 'label' => 'Water level', 'mark' => 'W', 'unit' => '%', 'precision' => 0, 'x' => 50, 'y' => 90, 'anchorX' => 50, 'anchorY' => 84, 'line' => 'line-water', 'note' => 'Reservoir level. Low readings mean the system may need refilling soon.'],
                ];
            ?>
            <div class="greenhouse-visual-wrap">
                <div class="greenhouse-model" aria-label="Interactive greenhouse sensor map">
                    <?php if (!$greenhousePhotoExists): ?>
                    <div class="greenhouse-photo-missing">
                        <strong>Greenhouse photo missing</strong>
                        <span>Add the reference photo as assets/Greenhouse_Model.png to show it here.</span>
                    </div>
                    <?php endif; ?>
                    <svg class="sensor-link-layer" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        <?php foreach ($sensorPoints as $point):
                            $linkStatus = $readings[$point['key']] ?? null;
                            $threshold = $ghThresholds[$ghId][$point['key']] ?? null;
                            $strokeClass = 'optimal';
                            if (!$linkStatus) {
                                $strokeClass = 'missing';
                            } elseif ($threshold) {
                                $value = (float)$linkStatus['value'];
                                if ($value < (float)$threshold['val_min'] || $value > (float)$threshold['val_max']) {
                                    $strokeClass = 'critical';
                                } elseif ($value < (float)$threshold['val_opt_low'] || $value > (float)$threshold['val_opt_high']) {
                                    $strokeClass = 'caution';
                                }
                            }
                        ?>
                        <line class="sensor-link <?= e($strokeClass) ?>"
                              x1="<?= (int)$point['anchorX'] ?>"
                              y1="<?= (int)$point['anchorY'] ?>"
                              x2="<?= (int)$point['x'] ?>"
                              y2="<?= (int)$point['y'] ?>" />
                    <?php endforeach; ?>
                    </svg>
                    <?php if ($greenhousePhotoExists): ?>
                    <img class="greenhouse-photo"
                         src="<?= e($greenhousePhotoWebPath) ?>"
                         alt="Small greenhouse with vertical hydroponics system" />
                    <?php else: ?>
                    <svg class="greenhouse-art" viewBox="0 0 420 340" aria-hidden="true">
                        <defs>
                            <linearGradient id="ghGlass" x1="0" x2="1" y1="0" y2="1">
                                <stop offset="0%" stop-color="#dff1d8" stop-opacity="0.95" />
                                <stop offset="58%" stop-color="#c6e1bb" stop-opacity="0.72" />
                                <stop offset="100%" stop-color="#8ab18a" stop-opacity="0.55" />
                            </linearGradient>
                            <linearGradient id="ghFrame" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#a7d0a6" />
                                <stop offset="100%" stop-color="#5c8f5c" />
                            </linearGradient>
                            <linearGradient id="ghFloor" x1="0" x2="1" y1="0" y2="1">
                                <stop offset="0%" stop-color="#d8d1c5" />
                                <stop offset="100%" stop-color="#b9b09f" />
                            </linearGradient>
                            <pattern id="ghMesh" width="10" height="10" patternUnits="userSpaceOnUse">
                                <path d="M0 0H10M0 0V10" stroke="rgba(46,139,87,.18)" stroke-width="0.7" />
                            </pattern>
                        </defs>
                        <ellipse cx="210" cy="316" rx="154" ry="16" fill="rgba(46,139,87,.14)" />
                        <path d="M58 300H362l18 16H40z" fill="url(#ghFloor)" stroke="#8d8d80" stroke-width="1.2" />
                        <path d="M74 76L158 20h130l84 56v182H74z" fill="url(#ghGlass)" stroke="#6d996c" stroke-width="5" />
                        <path d="M58 92l100-68h104l100 68" fill="none" stroke="url(#ghFrame)" stroke-width="7" stroke-linecap="round" />
                        <path d="M74 76l136 94 146-94" fill="none" stroke="#6d996c" stroke-width="4" stroke-linecap="round" opacity=".8" />
                        <path d="M80 94h260v180H80z" fill="url(#ghMesh)" opacity=".9" />
                        <path d="M80 94h260v180H80z" fill="none" stroke="#6d996c" stroke-width="2.5" />
                        <path d="M73 300V92M347 300V92" fill="none" stroke="#6d996c" stroke-width="5" stroke-linecap="round" />
                        <path d="M210 38v92" fill="none" stroke="#6d996c" stroke-width="4" stroke-linecap="round" />
                        <rect x="292" y="110" width="58" height="46" rx="4" fill="#8f918d" stroke="#5f615c" stroke-width="2" />
                        <circle cx="321" cy="133" r="18" fill="#c6c7c2" />
                        <path d="M321 115l-6 18h12zM303 133l18-6v12zM321 151l6-18h-12zM339 133l-18 6v-12z" fill="#71736f" opacity=".9" />
                        <circle cx="321" cy="133" r="9" fill="#4d4f4b" />
                        <rect x="178" y="112" width="70" height="44" rx="5" fill="#bdb7b0" stroke="#80807a" stroke-width="2" />
                        <path d="M192 112c0 0-3 16-3 21s6 19 6 19" fill="none" stroke="#6f766d" stroke-width="4" />
                        <path d="M244 112c0 0 3 16 3 21s-6 19-6 19" fill="none" stroke="#6f766d" stroke-width="4" />
                        <rect x="166" y="74" width="112" height="20" rx="8" fill="#a6d2a0" stroke="#6d996c" stroke-width="2" />
                        <path d="M160 70c4-6 14-12 22-12h78c8 0 18 6 22 12" fill="none" stroke="#6d996c" stroke-width="5" stroke-linecap="round" />
                        <rect x="121" y="171" width="78" height="74" rx="8" fill="#7f8784" stroke="#5c615f" stroke-width="2" />
                        <rect x="131" y="161" width="58" height="18" rx="8" fill="#a0a7a5" stroke="#636866" stroke-width="1.5" />
                        <circle cx="158" cy="192" r="11" fill="#5e6461" />
                        <path d="M145 200l-18 24" stroke="#4d7d4d" stroke-width="4" fill="none" />
                        <path d="M155 200l-10 64" stroke="#2e8b57" stroke-width="5" fill="none" />
                        <path d="M170 200l12 72" stroke="#bfbc8f" stroke-width="5" fill="none" />
                        <path d="M184 200l24 74" stroke="#2f5f7d" stroke-width="5" fill="none" />
                        <path d="M198 182h66v96h-66z" fill="#ece7dd" stroke="#b9b2a3" stroke-width="2" />
                        <path d="M206 194h50v13h-50zM206 214h50v13h-50zM206 234h50v13h-50zM206 254h50v13h-50z" fill="#f8f6f0" stroke="#c7c1b3" stroke-width="1.4" />
                        <rect x="250" y="92" width="24" height="24" rx="4" fill="#67b1dd" stroke="#4d8fb8" stroke-width="1.5" />
                        <path d="M262 116v34" stroke="#8b6d44" stroke-width="2.2" />
                        <circle cx="262" cy="154" r="4" fill="#8b6d44" />
                        <circle cx="271" cy="154" r="4" fill="#8b6d44" />
                        <circle cx="253" cy="154" r="4" fill="#8b6d44" />
                        <path d="M223 146l0 58" stroke="#5a5a5a" stroke-width="1.6" />
                        <path d="M223 204l8 12" stroke="#5a5a5a" stroke-width="1.4" />
                        <path d="M223 204l-8 12" stroke="#5a5a5a" stroke-width="1.4" />
                    </svg>
                    <?php endif; ?>
                    <div class="greenhouse-roof"></div>
                    <div class="greenhouse-glass">
                        <div class="greenhouse-ridge"></div>
                        <div class="mesh-panel mesh-left"></div>
                        <div class="mesh-panel mesh-right"></div>
                        <div class="roll-curtain"></div>
                        <div class="control-box"></div>
                        <div class="side-fan side-fan-right"></div>
                        <div class="hydroponic-tower">
                            <div class="tower-pipe"></div>
                            <div class="grow-cup cup-1"></div>
                            <div class="grow-cup cup-2"></div>
                            <div class="grow-cup cup-3"></div>
                            <div class="grow-cup cup-4"></div>
                            <div class="grow-cup cup-5"></div>
                            <div class="grow-cup cup-6"></div>
                        </div>
                        <div class="hose-line hose-a"></div>
                        <div class="hose-line hose-b"></div>
                        <div class="nutrient-pump"></div>
                        <div class="water-reservoir"></div>
                    </div>
                    <?php foreach ($sensorPoints as $point):
                        $reading = $readings[$point['key']] ?? null;
                        $threshold = $ghThresholds[$ghId][$point['key']] ?? null;
                        $pointStatus = 'optimal';
                        if (!$reading) {
                            $pointStatus = 'missing';
                        } elseif ($threshold) {
                            $value = (float)$reading['value'];
                            if ($value < (float)$threshold['val_min'] || $value > (float)$threshold['val_max']) {
                                $pointStatus = 'critical';
                            } elseif ($value < (float)$threshold['val_opt_low'] || $value > (float)$threshold['val_opt_high']) {
                                $pointStatus = 'caution';
                            }
                        }
                        $valueLabel = $reading
                            ? number_format((float)$reading['value'], (int)$point['precision']) . ' ' . $point['unit']
                            : 'No reading yet';
                        $rangeLabel = $threshold
                            ? 'Optimal: ' . number_format((float)$threshold['val_opt_low'], 1) . '-' . number_format((float)$threshold['val_opt_high'], 1) . ' ' . ($threshold['unit'] ?? $point['unit'])
                            : 'No crop threshold set';
                        $recordedLabel = !empty($reading['recorded_at']) ? format_ts($reading['recorded_at']) : 'Waiting for data';
                    ?>
                    <div class="sensor-line <?= e($point['line']) ?>"></div>
                    <button class="sensor-hotspot <?= e($pointStatus) ?>"
                            type="button"
                            style="left:<?= (int)$point['x'] ?>%;top:<?= (int)$point['y'] ?>%;"
                            aria-label="<?= e($point['label'] . ': ' . $valueLabel) ?>">
                        <span class="hotspot-dot"><?= e($point['mark']) ?></span>
                        <span class="sensor-tooltip">
                            <strong><?= e($point['label']) ?></strong>
                            <em><?= e($valueLabel) ?></em>
                            <small><?= e($rangeLabel) ?></small>
                            <small><?= e($recordedLabel) ?></small>
                            <span><?= e($point['note']) ?></span>
                        </span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="greenhouse-readable-summary">
                    <p>
                        <strong>What is happening:</strong>
                        <?= $hasCrit
                            ? 'One or more readings need immediate attention.'
                            : ($hasCaution ? 'The chamber is running, but at least one reading is outside its ideal range.' : 'All available readings are inside the expected range.') ?>
                    </p>
                </div>
            </div>

            <div class="gh-parameters">
                <div class="param-item <?= $tempCrit ? 'critical' : '' ?>">
                    <div class="param-icon">🌡️</div>
                    <div>
                        <div class="param-label">Temperature</div>
                        <div class="param-value">
                            <?= $temp
                                ? e(number_format((float)$temp['value'], 1) . '°C')
                                : '<span class="text-muted">—</span>' ?>
                        </div>
                    </div>
                </div>
                <div class="param-item">
                    <div class="param-icon">💧</div>
                    <div>
                        <div class="param-label">Humidity</div>
                        <div class="param-value">
                            <?= $hum
                                ? e(number_format((float)$hum['value'], 0) . '%')
                                : '<span class="text-muted">—</span>' ?>
                        </div>
                    </div>
                </div>
                <div class="param-item">
                    <div class="param-icon">☀️</div>
                    <div>
                        <div class="param-label">Light</div>
                        <div class="param-value">
                            <?= $light
                                ? e(number_format((float)$light['value'], 0) . ' lux')
                                : '<span class="text-muted">—</span>' ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($gh['plant_name'])): ?>
            <div style="padding:8px 16px;font-size:12px;color:#5A5A5A;border-top:1px solid #F3F4F6;display:flex;justify-content:space-between;">
                <span><?= e($gh['plant_emoji'] ?? '🌱') ?> <?= e($gh['plant_name']) ?></span>
                <span class="<?= $gh['sensors_online'] < $gh['sensors_total'] ? 'summary-warning' : '' ?>">
                    <?= (int)$gh['sensors_online'] ?>/<?= (int)$gh['sensors_total'] ?> sensors
                </span>
            </div>
            <?php endif; ?>

            <div class="gh-footer">
                <a href="greenhouses.php" class="gh-link">View Details →</a>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <!-- ── Recent Events & Alerts ────────────────────────────────────── -->
    <section class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Recent Events &amp; Alerts</h2>
            <a href="reports.php" class="btn btn-secondary btn-sm">View All</a>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Timestamp</th>
                    <th>Greenhouse</th>
                    <th>Parameter</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentAlerts)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted" style="padding:32px;">
                        No recent events found.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($recentAlerts as $row): ?>
                <tr>
                    <td><?= severity_icon($row['severity']) ?></td>
                    <td><?= e(format_ts($row['created_at'])) ?></td>
                    <td>
                        <?php if (!empty($row['gh_code'])): ?>
                            <?= gh_badge($row['gh_code']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(ucfirst($row['category'])) ?></td>
                    <td><?= e($row['message']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <!-- ── Hardware Status ───────────────────────────────────────────── -->
    <section class="hardware-section mt-4">
        <h2 class="section-title mb-2">Hardware Status</h2>
        <div class="hardware-grid">
            <?php if (empty($hardware)): ?>
                <p class="text-muted">No hardware components registered.</p>
            <?php else: ?>
            <?php foreach ($hardware as $hw): ?>
            <div class="hardware-card">
                <div class="hw-icon"><?= hw_icon($hw['type']) ?></div>
                <div class="hw-name"><?= e($hw['label']) ?></div>
                <div class="hw-status">
                    <span class="status-dot <?= e(status_dot_cls($hw['status'])) ?>"></span>
                    <span><?= e(ucfirst($hw['status'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

</main>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
'use strict';
// Shows a toast message so action results are easier to notice while debugging.
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show toast-' + type;
    setTimeout(() => t.className = 'toast', 5000);
}

// Toggles the profile dropdown menu in the page header.
function toggleProfileDropdown(event) {
    event.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('active');
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('.profile-icon') && !e.target.closest('.profile-dropdown')) {
        document.getElementById('profileDropdown').classList.remove('active');
    }
});

// Handle logout via AJAX to process JSON and redirect
document.getElementById('logoutForm').addEventListener('submit', function(e) {
    e.preventDefault();
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
});
</script>
  <script src="js.navbar.js?v=<?= urlencode((string) @filemtime(__DIR__ . '/js.navbar.js')) ?>"></script>
</body>
</html>
