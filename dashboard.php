<?php
// ============================================================================
// ECOTWIN — DASHBOARD
// ============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';

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

// ============================================================================
// DATA QUERIES
// ============================================================================

// ── 1. Active experiment ──────────────────────────────────────────────────────
try {
    $activeExp = $pdo->query(
        "SELECT e.experiment_id, e.exp_code, e.title, e.started_at, e.expected_end_at,
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
try {
    $criticalAlerts = $pdo->query(
        "SELECT a.severity, a.category, a.message, a.sensor_value, a.created_at,
                g.code AS gh_code
           FROM alerts a
           LEFT JOIN greenhouses g ON a.greenhouse_id = g.greenhouse_id
          WHERE a.is_resolved = 0 AND a.severity = 'critical'
          ORDER BY a.created_at DESC
          LIMIT 3"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Dashboard criticalAlerts: ' . $e->getMessage());
    $criticalAlerts = [];
}

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
                  WHERE s.greenhouse_id = g.greenhouse_id) AS sensors_total,
                (SELECT COUNT(*) FROM alerts a
                  WHERE a.greenhouse_id = g.greenhouse_id
                    AND a.is_resolved = 0
                    AND a.severity = 'critical') AS open_critical
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
        $stmt = $pdo->prepare(
            "SELECT r.parameter, r.value, r.unit
               FROM sensor_readings r
               INNER JOIN (
                   SELECT parameter, MAX(recorded_at) AS max_ts
                     FROM sensor_readings
                    WHERE greenhouse_id = ?
                      AND parameter IN ('temperature','humidity','light')
                    GROUP BY parameter
               ) latest ON r.parameter = latest.parameter
                       AND r.recorded_at = latest.max_ts
              WHERE r.greenhouse_id = ?"
        );
        $stmt->execute([$ghId, $ghId]);
        $readings = [];
        foreach ($stmt->fetchAll() as $row) {
            $readings[$row['parameter']] = $row;
        }
        $ghReadings[$ghId] = $readings;
    } catch (PDOException $e) {
        error_log("Dashboard ghReadings gh{$ghId}: " . $e->getMessage());
        $ghReadings[$ghId] = [];
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

// ============================================================================
// HELPERS
// ============================================================================

function severity_icon(string $sev): string {
    return match($sev) {
        'critical' => '<span class="event-icon critical">🔴</span>',
        'warning'  => '<span class="event-icon warning">⚠️</span>',
        'success'  => '<span class="event-icon success">✓</span>',
        default    => '<span class="event-icon info">ℹ️</span>',
    };
}

function format_ts(string $ts): string {
    return date('M j, g:i A', strtotime($ts));
}

function gh_badge(string $code): string {
    $cls = strtolower($code) === 'a' ? 'badge-greenhouse-a' : 'badge-greenhouse-b';
    return '<span class="badge ' . $cls . '">Greenhouse ' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>';
}

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
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard — EcoTwin</title>
    <link rel="stylesheet" href="css.main.css" />
    <link rel="stylesheet" href="css.dashboard.css" />
</head>
<body>

<!-- ================================================================ -->
<!-- NAVBAR                                                            -->
<!-- ================================================================ -->
<nav class="navbar">
    <div class="navbar-container">
        <a href="dashboard.php" class="navbar-logo">
            <div class="logo-icon">🧪</div>
            <span class="logo-text">EcoTwin</span>
        </a>

        <div class="navbar-menu">
            <a href="dashboard.php"    class="nav-item active">Dashboard</a>
            <a href="experiments.php" class="nav-item">Experiments</a>
            <a href="greenhouses.php" class="nav-item">Greenhouses</a>
            <a href="reports.php"     class="nav-item">Reports</a>
            <a href="settings.php"    class="nav-item">Settings</a>
            <?php if ($userRole === 'admin'): ?>
            <a href="admin.php"       class="nav-item">Admin</a>
            <?php endif; ?>
        </div>

        <div class="navbar-user">
            <div class="profile-icon" onclick="toggleProfileDropdown(event)">
                <?= e($userInitials) ?>
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
                    <a href="#" class="profile-menu-item">Profile Settings</a>
                    <a href="#" class="profile-menu-item">Preferences</a>
                </div>
                <div class="profile-dropdown-footer">
                    <form id="logoutForm" method="POST" action="auth_handler.php" style="margin:0;">
                        <input type="hidden" name="action" value="logout" />
                        <button type="submit" class="logout-btn">Logout</button>
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

    <!-- ── Summary Cards ─────────────────────────────────────────────── -->
    <section class="summary-grid mb-3">

        <div class="summary-card">
            <div class="summary-icon" style="color:#0d9488">🧪</div>
            <div class="summary-value"><?= $activeExp ? 1 : 0 ?></div>
            <div class="summary-label">Active Experiment</div>
            <?php if ($activeExp): ?>
                <div class="summary-meta"><?= e($activeExp['exp_code']) ?> Running</div>
            <?php else: ?>
                <div class="summary-meta" style="color:#9CA3AF;">None running</div>
            <?php endif; ?>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="color:#10b981">📡</div>
            <div class="summary-value"><?= $sensorsOnline ?>/<?= $sensorsTotal ?></div>
            <div class="summary-label">Online / Total Sensors</div>
            <?php if ($sensorsOffline > 0): ?>
                <div class="summary-warning">⚠️ <?= $sensorsOffline ?> offline</div>
            <?php else: ?>
                <div class="summary-meta">All sensors online</div>
            <?php endif; ?>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="color:#3b82f6">🔄</div>
            <div class="summary-value"><?= e($lastSyncLabel) ?></div>
            <div class="summary-label">Last Data Sync</div>
            <div class="pulse-indicator">
                <span class="pulse-dot"></span>
                <span class="pulse-text">Live</span>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon" style="color:#10b981">🖥️</div>
            <div class="summary-value <?= e($systemClass) ?>"><?= e($systemStatus) ?></div>
            <div class="summary-label">System Status</div>
            <?php if ($hwOffline > 0): ?>
                <div class="summary-warning">⚠️ <?= $hwOffline ?> component(s) down</div>
            <?php endif; ?>
        </div>

    </section>

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
            <a href="experiments.html" class="btn btn-secondary btn-sm">View Details</a>
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

    <!-- ── Greenhouse Quick Status ───────────────────────────────────── -->
    <section class="greenhouse-status-grid mb-3">
        <?php foreach ($greenhouses as $gh):
            $ghId     = $gh['greenhouse_id'];
            $readings = $ghReadings[$ghId] ?? [];
            $hasCrit  = (int)$gh['open_critical'] > 0;
            $cardCls  = strtolower($gh['code']) === 'a' ? 'gh-a' : 'gh-b';

            $temp  = $readings['temperature'] ?? null;
            $hum   = $readings['humidity']    ?? null;
            $light = $readings['light']       ?? null;

            // Flag critical if temp reading exceeds 30 °C or open critical alert exists
            $tempCrit = $hasCrit || ($temp && (float)$temp['value'] > 30);
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
                <?php else: ?>
                    <span class="badge badge-success">Optimal</span>
                <?php endif; ?>
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

<script>
'use strict';
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
            alert('Logout failed.');
        }
    })
    .catch(() => alert('Logout failed.'));
});
</script>
</body>
</html>