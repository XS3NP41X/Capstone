<?php
// ============================================================================
// ECOTWIN — Settings Page
// Pulls live data from ecotwin_db for all six settings panels.
// ============================================================================

require_once __DIR__ . '/auth_guard.php';

$db = db();
$userId      = (int)($_SESSION['user_id'] ?? 0);
$userName    = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userEmail   = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole    = $_SESSION['user_role'] ?? 'student';
$userInitials = strtoupper(implode('', array_map(
    fn($w) => $w[0],
    array_slice(explode(' ', trim($_SESSION['user_name'] ?? 'U')), 0, 2)
)));
$isAdmin = ($userRole === 'admin');

// ── 1. Network / System Config ───────────────────────────────────────────────
$configRows = $db->query(
    "SELECT config_key, config_value, data_type, description FROM system_config ORDER BY config_key"
)->fetchAll();
$config = [];
foreach ($configRows as $row) {
    $config[$row['config_key']] = $row;
}

// ── 2. Sensor Thresholds (plant_thresholds for active experiment plant) ──────
$activeExp = $db->query(
    "SELECT e.experiment_id, e.exp_code, e.title, p.name AS plant_name, p.plant_id
     FROM experiments e
     JOIN experiment_greenhouses eg ON eg.experiment_id = e.experiment_id
     JOIN plants p ON p.plant_id = eg.plant_id
     WHERE e.status = 'active'
     LIMIT 1"
)->fetch();

$thresholds = [];
if ($activeExp) {
    $stmt = $db->prepare(
        "SELECT parameter, unit, val_min, val_opt_low, val_opt_high, val_max
         FROM plant_thresholds WHERE plant_id = ? ORDER BY parameter"
    );
    $stmt->execute([$activeExp['plant_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $thresholds[$row['parameter']] = $row;
    }
}

// ── 3. Hardware Inventory ────────────────────────────────────────────────────
$hardware = $db->query(
    "SELECT hc.*, g.code AS gh_code
     FROM hardware_components hc
     LEFT JOIN greenhouses g ON g.greenhouse_id = hc.greenhouse_id
     ORDER BY FIELD(hc.type,'arduino','esp32','nrf_module','relay','power_supply','ups','other'),
              hc.greenhouse_id"
)->fetchAll();

// ── 4. Sensors status ────────────────────────────────────────────────────────
$sensors = $db->query(
    "SELECT s.*, g.code AS gh_code, g.name AS gh_name
     FROM sensors s
     JOIN greenhouses g ON g.greenhouse_id = s.greenhouse_id
     ORDER BY s.sensor_type, s.greenhouse_id"
)->fetchAll();

// ── 5. System Information ────────────────────────────────────────────────────
$esp32 = null;
$arduino = null;
foreach ($hardware as $h) {
    if ($h['type'] === 'esp32')   $esp32   = $h;
    if ($h['type'] === 'arduino') $arduino = $h;
}

// DB size query (informational)
$dbSizeRow = $db->query(
    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS size_mb
     FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'"
)->fetch();
$dbSizeMB = $dbSizeRow['size_mb'] ?? '—';

// System uptime: time since first experiment
$firstExp = $db->query("SELECT MIN(started_at) AS t FROM experiments")->fetchColumn();
$uptimeStr = '—';
if ($firstExp) {
    $diff = (new DateTime())->diff(new DateTime($firstExp));
    $uptimeStr = $diff->days . ' days, ' . $diff->h . ' hrs';
}

// ── 6. Users ─────────────────────────────────────────────────────────────────
// ── 7. Maintenance Log ───────────────────────────────────────────────────────
$maintenance = $db->query(
    "SELECT ml.*, u.full_name AS performed_by_name
     FROM maintenance_log ml
     LEFT JOIN users u ON u.user_id = ml.performed_by
     ORDER BY ml.performed_at DESC
     LIMIT 10"
)->fetchAll();

// ── ESP32 network info from hardware_components / system_config ───────────────
$esp32IP      = $esp32['ip_address']        ?? ($config['esp32_ip']['config_value'] ?? '192.168.1.105');
$esp32Signal  = $config['esp32_rssi']['config_value']  ?? '-52 dBm (Excellent)';
$syncInterval = $config['sync_interval_minutes']['config_value'] ?? '2';
$wifiSSID     = $config['wifi_ssid']['config_value'] ?? 'SPAMAST-Research';

// ── Helper: badge for sensor/hardware status ─────────────────────────────────
function statusDot(string $status): string {
    $map = ['online' => 'status-online', 'offline' => 'status-offline',
            'degraded' => 'status-warning', 'maintenance' => 'status-warning'];
    $cls = $map[$status] ?? 'status-offline';
    return "<span class=\"status-dot {$cls}\"></span>";
}
function statusLabel(string $status): string {
    return match($status) {
        'online'      => 'Online',
        'offline'     => 'Offline',
        'degraded'    => 'Degraded',
        'maintenance' => 'Maintenance',
        default       => ucfirst($status),
    };
}
function maintenanceStatusBadge(array $ml): string {
    if (!$ml['next_due_at']) return '<span class="badge badge-neutral">No Schedule</span>';
    $next = new DateTime($ml['next_due_at']);
    $now  = new DateTime();
    $diff = (int)$now->diff($next)->days * ($now > $next ? -1 : 1);
    if ($diff < 0)       return '<span class="badge badge-danger">Overdue</span>';
    if ($diff <= 7)      return '<span class="badge badge-warning">Due Soon</span>';
    return '<span class="badge badge-success">Up to Date</span>';
}

// Group hardware by type category
$hwGroups = [
    'Microcontrollers' => ['arduino','esp32','nrf_module'],
    'Power Supply'     => ['power_supply','ups'],
    'Relay Modules'    => ['relay'],
    'Other'            => ['other'],
];
$hwByType = [];
foreach ($hardware as $h) { $hwByType[$h['type']][] = $h; }

// Group sensors by sensor_type
$sensorGroups = [];
foreach ($sensors as $s) { $sensorGroups[$s['sensor_type']][] = $s; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Settings - EcoTwin</title>
  <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>" />
  <link rel="stylesheet" href="css.settings.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.settings.css')) ?>" />
  <style>
    /* ── Inline extras ───────────────────────────────────────────────── */
    .config-editable {
      display: flex; align-items: center; gap: 8px;
    }
    .config-value-display { font-weight: 500; }
    .btn-edit-config {
      background: none; border: 1px solid #d1d5db; border-radius: 6px;
      padding: 3px 10px; font-size: 12px; cursor: pointer; color: #0d9488;
      transition: all 0.2s;
    }
    .btn-edit-config:hover { background: #e0f2f1; }
    .inline-edit-form { display: none; flex-direction: column; gap: 6px; margin-top: 4px; }
    .inline-edit-form.open { display: flex; }
    .inline-edit-form input, .inline-edit-form select {
      padding: 6px 10px; border: 1.5px solid #0d9488; border-radius: 6px;
      font-size: 14px; outline: none;
    }
    .inline-edit-actions { display: flex; gap: 6px; }
    .btn-save-config {
      padding: 5px 14px; background: #0d9488; color: white; border: none;
      border-radius: 6px; font-size: 13px; cursor: pointer; font-weight: 600;
    }
    .btn-cancel-config {
      padding: 5px 14px; background: white; color: #5a5a5a;
      border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; cursor: pointer;
    }
    .user-action-btn {
      background: none; border: none; font-size: 13px; cursor: pointer;
      color: #0d9488; padding: 4px 8px; border-radius: 4px;
      transition: all 0.2s; font-weight: 500;
    }
    .user-action-btn:hover { background: #e0f2f1; }
    .user-action-btn.danger { color: #ef4444; }
    .user-action-btn.danger:hover { background: #fee2e2; }

    /* Modal */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.5); z-index: 9999;
      align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
      background: white; border-radius: 14px; padding: 32px;
      width: 90%; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    }
    .modal-box h3 { font-size: 20px; font-weight: 700; margin-bottom: 20px; }
    .modal-form-group { margin-bottom: 16px; }
    .modal-form-group label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; color: #5a5a5a; }
    .modal-form-group input,
    .modal-form-group select {
      width: 100%; padding: 9px 12px; border: 1.5px solid #d1d5db;
      border-radius: 8px; font-size: 14px; outline: none; transition: border 0.2s;
    }
    .modal-form-group input:focus,
    .modal-form-group select:focus { border-color: #0d9488; }
    .modal-actions { display: flex; gap: 10px; margin-top: 24px; }
    .modal-actions .btn { flex: 1; }
    .toast {
      position: fixed; bottom: 24px; right: 24px;
      padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600;
      z-index: 99999; display: none; align-items: center; gap: 10px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15); animation: slideInToast 0.3s ease;
    }
    @keyframes slideInToast {
      from { transform: translateY(20px); opacity: 0; }
      to   { transform: translateY(0);    opacity: 1; }
    }
    .toast.success { background: #d1fae5; color: #065f46; }
    .toast.error   { background: #fee2e2; color: #991b1b; }
    .section-refresh {
      font-size: 12px; color: #9ca3af; margin-top: 4px;
    }
    .user-status-dot {
      display: inline-block; width: 7px; height: 7px; border-radius: 50%;
      margin-right: 4px;
    }
    .user-status-dot.active    { background: #10b981; }
    .user-status-dot.inactive  { background: #9ca3af; }
    .user-status-dot.suspended { background: #ef4444; }
    .hw-type-group { margin-bottom: 20px; }
    .hw-type-group:last-child { margin-bottom: 0; }
    .page-header-row {
      display: flex; justify-content: space-between; align-items: flex-start;
      margin-bottom: 24px;
    }
    @media (max-width: 768px) {
      .page-header-row { flex-direction: column; gap: 12px; }
    }
  </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════ NAVBAR ══ -->
<nav class="navbar">
  <div class="navbar-container">
    <a href="dashboard.php" class="navbar-logo">
      <img src="ECOTwin_Logo.png" alt="EcoTwin logo" class="logo-icon" />
      <span class="logo-text">EcoTwin</span>
    </a>

    <div class="navbar-menu" id="navbarMenu">
      <a href="dashboard.php"   class="nav-item">Dashboard</a>
      <a href="experiments.php" class="nav-item">Experiments</a>
      <a href="greenhouses.php" class="nav-item">Greenhouses</a>
      <a href="reports.php"     class="nav-item">Reports</a>
      <a href="settings.php"    class="nav-item active">Settings</a>
      <?php if ($isAdmin): ?>
      <a href="admin.php"       class="nav-item">Admin</a>
      <?php endif; ?>
    </div>

    <div class="navbar-user">
      <div class="profile-icon" id="profileToggle">
        <?= $userInitials ?>
      </div>
      <div class="profile-dropdown" id="profileDropdown">
        <div class="profile-dropdown-header">
          <div class="profile-user-info">
            <div class="profile-user-name"><?= $userName ?></div>
            <div class="profile-user-email"><?= $userEmail ?></div>
            <div class="profile-user-role"><?= htmlspecialchars(ucfirst($userRole)) ?></div>
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

<!-- ═══════════════════════════════════════════════════════ MAIN ════ -->
<main class="main-content">

  <div class="page-header-row">
    <div class="page-header mb-0">
      <h1 class="page-title">System Settings</h1>
      <p class="page-subtitle">
        <?= $isAdmin
          ? 'System overview and configuration status'
          : 'Configuration and hardware status (Read-only for Researchers)' ?>
      </p>
      <div class="section-refresh">
        Data refreshed at <?= date('M j, g:i A') ?>
      </div>
    </div>
  </div>

  <!-- Access Notice -->
  <div class="alert <?= $isAdmin ? 'alert-success' : 'alert-info' ?> mb-3">
    <span class="alert-icon"><?= $isAdmin ? '✅' : '🔒' ?></span>
    <div>
      <?php if ($isAdmin): ?>
        <strong>Admin Access:</strong> Use the Admin panel for user management and other administrative actions.
      <?php else: ?>
        <strong>Limited Access:</strong> Configuration changes require Admin privileges. Settings shown are read-only.
      <?php endif; ?>
    </div>
  </div>

  <div class="settings-grid">

    <!-- ══════════════════════════════ 1. NETWORK CONFIGURATION ═══ -->
    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Network Configuration</h2>
        <?php
          $esp32Status = 'offline';
          foreach ($hardware as $h) { if ($h['type'] === 'esp32') { $esp32Status = $h['status']; break; } }
          $netBadge = $esp32Status === 'online' ? 'badge-success' : 'badge-danger';
          $netLabel = $esp32Status === 'online' ? 'Connected' : 'Offline';
        ?>
        <span class="badge <?= $netBadge ?>"><?= $netLabel ?></span>
      </div>

      <div class="settings-content">
        <?php
        $netItems = [
          'ESP32 Wi-Fi Status' => [
            'value' => statusDot($esp32Status === 'online' ? 'online' : 'offline') . 'Connected to ' . htmlspecialchars($wifiSSID),
            'raw'   => null,
          ],
          'IP Address' => [
            'value'     => htmlspecialchars($esp32IP),
            'key'       => null, // read-only — comes from hardware_components
            'raw'       => $esp32IP,
          ],
          'Signal Strength' => [
            'value' => htmlspecialchars($esp32Signal),
            'key'   => 'esp32_rssi',
            'raw'   => $esp32Signal,
          ],
          'nRF Wireless Module' => [
            'value' => statusDot('online') . 'Active (Channel 76)',
            'raw'   => null,
          ],
          'Data Sync Interval' => [
            'value' => htmlspecialchars($syncInterval) . ' minutes',
            'key'   => 'sync_interval_minutes',
            'raw'   => $syncInterval,
            'type'  => 'integer',
          ],
        ];
        foreach ($netItems as $label => $item):
        ?>
        <div class="setting-item">
          <div class="setting-label"><?= $label ?></div>
          <div class="setting-value">
            <?php if ($isAdmin && isset($item['key']) && $item['key']): ?>
              <div class="config-editable" id="wrap-<?= $item['key'] ?>">
                <span class="config-value-display" id="disp-<?= $item['key'] ?>">
                  <?= $item['value'] ?>
                </span>
                <button class="btn-edit-config"
                        onclick="toggleConfigEdit('<?= $item['key'] ?>', '<?= addslashes($item['raw']) ?>')">
                  ✏️ Edit
                </button>
              </div>
              <div class="inline-edit-form" id="edit-<?= $item['key'] ?>">
                <input type="<?= ($item['type'] ?? 'string') === 'integer' ? 'number' : 'text' ?>"
                       id="inp-<?= $item['key'] ?>"
                       value="<?= htmlspecialchars($item['raw']) ?>"
                       placeholder="Enter value" />
                <div class="inline-edit-actions">
                  <button class="btn-save-config"
                          onclick="saveConfig('<?= $item['key'] ?>')">Save</button>
                  <button class="btn-cancel-config"
                          onclick="cancelConfigEdit('<?= $item['key'] ?>')">Cancel</button>
                </div>
              </div>
            <?php else: ?>
              <?= $item['value'] ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- ══════════════════════════════ 2. SENSOR THRESHOLDS ════════ -->
    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Sensor Thresholds</h2>
        <span class="badge badge-neutral"><?= $isAdmin ? 'Live from DB' : 'Read-Only' ?></span>
      </div>

      <div class="settings-content">
        <?php if ($activeExp): ?>
          <div class="alert alert-info mb-2" style="padding:10px 14px;font-size:13px;">
            ℹ️ Thresholds for <strong><?= htmlspecialchars($activeExp['plant_name']) ?></strong>
            — <?= htmlspecialchars($activeExp['exp_code']) ?>
          </div>
        <?php else: ?>
          <div class="alert alert-warning mb-2" style="padding:10px 14px;font-size:13px;">
            ⚠️ No active experiment — showing default threshold table.
          </div>
        <?php endif; ?>

        <?php
        $paramMeta = [
          'temperature'  => ['label' => 'Temperature (DHT22)',  'unit' => '°C'],
          'humidity'     => ['label' => 'Humidity (DHT22)',      'unit' => '%'],
          'ph'           => ['label' => 'pH Level',              'unit' => ''],
          'ec'           => ['label' => 'EC/TDS',                'unit' => ' mS/cm'],
          'light'        => ['label' => 'Light Intensity (LDR)', 'unit' => ' lux'],
          'water_level'  => ['label' => 'Water Level',           'unit' => '%'],
          'water_temp'   => ['label' => 'Water Temperature',     'unit' => '°C'],
        ];
        $defaultThresholds = [
          'temperature' => ['val_min'=>18,'val_opt_low'=>20,'val_opt_high'=>26,'val_max'=>32,'unit'=>'°C'],
          'humidity'    => ['val_min'=>50,'val_opt_low'=>60,'val_opt_high'=>75,'val_max'=>85,'unit'=>'%'],
          'ph'          => ['val_min'=>5.0,'val_opt_low'=>5.5,'val_opt_high'=>6.5,'val_max'=>7.5,'unit'=>''],
          'ec'          => ['val_min'=>1.0,'val_opt_low'=>1.5,'val_opt_high'=>2.0,'val_max'=>2.5,'unit'=>' mS/cm'],
        ];
        $displayThresholds = $thresholds ?: $defaultThresholds;

        foreach ($displayThresholds as $param => $t):
          $meta = $paramMeta[$param] ?? ['label' => ucfirst($param), 'unit' => $t['unit'] ?? ''];
          $unit = $t['unit'] ?? $meta['unit'];
        ?>
        <div class="threshold-group">
          <div class="threshold-title"><?= $meta['label'] ?></div>
          <div class="threshold-values">
            <div class="threshold-item">
              <span class="threshold-label">Min (Warning):</span>
              <span class="threshold-value"><?= $t['val_min'] ?><?= $unit ?></span>
            </div>
            <div class="threshold-item">
              <span class="threshold-label">Optimal Range:</span>
              <span class="threshold-value"><?= $t['val_opt_low'] ?>–<?= $t['val_opt_high'] ?><?= $unit ?></span>
            </div>
            <div class="threshold-item">
              <span class="threshold-label">Max (Critical):</span>
              <span class="threshold-value"><?= $t['val_max'] ?><?= $unit ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- ══════════════════════════════ 3. HARDWARE INVENTORY ═══════ -->
    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Hardware Inventory</h2>
        <?php
          $totalHW   = count($hardware);
          $onlineHW  = count(array_filter($hardware, fn($h) => $h['status'] === 'online'));
          $badgeHW   = $onlineHW === $totalHW ? 'badge-success' : 'badge-warning';
        ?>
        <span class="badge <?= $badgeHW ?>"><?= $onlineHW ?>/<?= $totalHW ?> Online</span>
      </div>

      <div class="settings-content">

        <?php
        // Microcontrollers & nRF
        $mcTypes = ['arduino','esp32','nrf_module'];
        $mcItems = array_filter($hardware, fn($h) => in_array($h['type'], $mcTypes));
        if ($mcItems):
        ?>
        <div class="hw-type-group">
          <div class="hw-section-title">Microcontrollers</div>
          <?php foreach ($mcItems as $h): ?>
          <div class="hw-item">
            <div>
              <div class="hw-name"><?= htmlspecialchars($h['label']) ?>
                <?php if ($h['firmware_version']): ?>
                  <span style="font-size:11px;color:#9ca3af;"> v<?= htmlspecialchars($h['firmware_version']) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($h['ip_address']): ?>
                <div style="font-size:11px;color:#5a5a5a;">IP: <?= htmlspecialchars($h['ip_address']) ?></div>
              <?php endif; ?>
            </div>
            <div class="hw-status">
              <?= statusDot($h['status']) ?>
              <span><?= statusLabel($h['status']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Sensors from `sensors` table -->
        <?php
        $sensorTypeLabels = [
          'DHT22'       => 'DHT22 – Temperature & Humidity',
          'DS18B20'     => 'DS18B20 – Water Temperature',
          'LDR'         => 'LDR – Light Intensity',
          'EC_TDS'      => 'EC/TDS Sensor',
          'PH'          => 'pH Sensor',
          'WATER_LEVEL' => 'Water Level Sensor',
        ];
        if ($sensorGroups):
        ?>
        <div class="hw-type-group">
          <div class="hw-section-title">Sensors</div>
          <?php foreach ($sensorGroups as $type => $sGroup):
            $total   = count($sGroup);
            $offline = count(array_filter($sGroup, fn($s) => $s['status'] !== 'online'));
            $dot     = $offline > 0 ? 'status-warning' : 'status-online';
            $lbl     = $offline > 0 ? "{$offline} Offline" : 'Online';
          ?>
          <div class="hw-item">
            <div class="hw-name">
              <?= $sensorTypeLabels[$type] ?? $type ?> (×<?= $total ?>)
            </div>
            <div class="hw-status">
              <span class="status-dot <?= $dot ?>"></span>
              <span><?= $lbl ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Actuators -->
        <?php
        $actuatorTypes = $db->query(
            "SELECT actuator_type,
                    COUNT(*) AS total,
                    SUM(status NOT IN ('on','off','auto')) AS fault_count,
                    GROUP_CONCAT(DISTINCT status ORDER BY status) AS statuses
             FROM actuators GROUP BY actuator_type ORDER BY actuator_type"
        )->fetchAll();
        $actuatorLabels = [
          'nutrient_pump'      => 'Nutrient Pump',
          'exhaust_fan'        => 'Exhaust Fan',
          'circulation_fan'   => 'Circulation Fan',
          'shading_net'        => 'Shading Net Motor',
          'misting_system'     => 'Misting System',
          'ph_pump_up'         => 'pH Pump (Up)',
          'ph_pump_down'       => 'pH Pump (Down)',
          'water_refill_pump'  => 'Water Refill Pump',
        ];
        if ($actuatorTypes):
        ?>
        <div class="hw-type-group">
          <div class="hw-section-title">Actuators</div>
          <?php foreach ($actuatorTypes as $a):
            $dot = ($a['fault_count'] > 0) ? 'status-warning' : 'status-online';
            $lbl = ($a['fault_count'] > 0) ? 'Fault' : ucfirst(explode(',', $a['statuses'])[0]);
          ?>
          <div class="hw-item">
            <div class="hw-name"><?= $actuatorLabels[$a['actuator_type']] ?? $a['actuator_type'] ?> (×<?= $a['total'] ?>)</div>
            <div class="hw-status">
              <span class="status-dot <?= $dot ?>"></span>
              <span><?= htmlspecialchars($lbl) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Power Supply -->
        <?php
        $powerItems = array_filter($hardware, fn($h) => in_array($h['type'], ['power_supply','ups']));
        if ($powerItems):
        ?>
        <div class="hw-type-group">
          <div class="hw-section-title">Power Supply</div>
          <?php foreach ($powerItems as $h): ?>
          <div class="hw-item">
            <div class="hw-name"><?= htmlspecialchars($h['label']) ?></div>
            <div class="hw-status">
              <?= statusDot($h['status']) ?>
              <span><?= statusLabel($h['status']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </section>

    <!-- ══════════════════════════════ 4. SYSTEM INFORMATION ═══════ -->
    <section class="card">
      <div class="card-header">
        <h2 class="card-title">System Information</h2>
      </div>
      <div class="settings-content">
        <?php
        $sysItems = [
          'System Version'     => $config['system_version']['config_value'] ?? 'EcoTwin v1.0',
          'Firmware – Arduino' => $arduino ? 'v' . ($arduino['firmware_version'] ?? '—') : '—',
          'Firmware – ESP32'   => $esp32   ? 'v' . ($esp32['firmware_version']   ?? '—') : '—',
          'Database Size'      => $dbSizeMB . ' MB',
          'System Uptime'      => $uptimeStr,
          'Sync Interval'      => $syncInterval . ' minutes',
          'Institution'        => 'SPAMAST – IASDC',
          'Location'           => 'Research Greenhouse Facility',
        ];
        foreach ($sysItems as $label => $value):
        ?>
        <div class="setting-item">
          <div class="setting-label"><?= $label ?></div>
          <div class="setting-value"><?= htmlspecialchars($value) ?></div>
        </div>
        <?php endforeach; ?>

        <?php if ($isAdmin): ?>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f3f4f6;">
          <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:#5a5a5a;">Automation Toggles</div>
          <?php
          $autoKeys = [
            'auto_fan_on_high_temp' => 'Auto Fan on High Temp',
            'auto_ph_correction'    => 'Auto pH Correction',
            'auto_ec_dosing'        => 'Auto EC Dosing',
            'auto_shading_on_light' => 'Auto Shading on Excess Light',
            'auto_humidity_misting' => 'Auto Humidity Misting',
          ];
          foreach ($autoKeys as $key => $label):
            $val = $config[$key]['config_value'] ?? 'false';
            $checked = $val === 'true' ? 'checked' : '';
          ?>
          <div class="setting-item" style="padding:8px 0;">
            <div class="setting-label"><?= $label ?></div>
            <div class="setting-value">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" <?= $checked ?>
                       onchange="saveConfigToggle('<?= $key ?>', this)"
                       style="width:16px;height:16px;accent-color:#0d9488;" />
                <span id="toggle-lbl-<?= $key ?>" style="font-size:12px;color:#5a5a5a;">
                  <?= $val === 'true' ? 'Enabled' : 'Disabled' ?>
                </span>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </section>

    <!-- ══════════════════════════════ 5. USER MANAGEMENT ══════════ -->
    <!-- ══════════════════════════════ 6. MAINTENANCE & CALIBRATION  -->
    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Maintenance &amp; Calibration</h2>
        <?php if ($isAdmin): ?>
        <button class="btn btn-secondary" style="padding:6px 12px;font-size:13px;"
                onclick="openMaintenanceModal()">+ Log Action</button>
        <?php endif; ?>
      </div>
      <div class="settings-content" id="maintenanceList">
        <?php if ($maintenance): ?>
          <?php foreach ($maintenance as $ml): ?>
          <div class="maintenance-item">
            <div class="maintenance-info">
              <div class="maintenance-title"><?= htmlspecialchars($ml['action']) ?></div>
              <div class="maintenance-date">
                Last: <?= date('F j, Y', strtotime($ml['performed_at'])) ?>
                <?php if ($ml['performed_by_name']): ?>
                  · by <?= htmlspecialchars($ml['performed_by_name']) ?>
                <?php endif; ?>
              </div>
              <?php if ($ml['next_due_at']): ?>
              <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                Next due: <?= date('M j, Y', strtotime($ml['next_due_at'])) ?>
              </div>
              <?php endif; ?>
            </div>
            <div class="maintenance-status">
              <?= maintenanceStatusBadge($ml) ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="text-align:center;padding:32px;color:#9ca3af;font-size:14px;">
            No maintenance records found.
          </div>
        <?php endif; ?>
      </div>
    </section>

  </div><!-- /.settings-grid -->
</main>

<!-- ═══════════════════════════════════ MODALS ═══════════════════════ -->

<!-- Maintenance Log Modal -->
<div class="modal-overlay" id="maintenanceModal">
  <div class="modal-box">
    <h3>Log Maintenance Action</h3>
    <div class="modal-form-group">
      <label>Component Type</label>
      <select id="mCompType">
        <option value="sensor">Sensor</option>
        <option value="actuator">Actuator</option>
        <option value="hardware">Hardware</option>
        <option value="system">System</option>
      </select>
    </div>
    <div class="modal-form-group">
      <label>Action Label</label>
      <input type="text" id="mAction" placeholder="e.g. pH Sensor Calibration" />
    </div>
    <div class="modal-form-group">
      <label>Description</label>
      <input type="text" id="mDesc" placeholder="Optional details…" />
    </div>
    <div class="modal-form-group">
      <label>Next Due Date (optional)</label>
      <input type="date" id="mNextDue" />
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeMaintenanceModal()">Cancel</button>
      <button class="btn btn-primary" onclick="submitMaintenanceLog()">Log Action</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- ═══════════════════════════════════ JAVASCRIPT ═══════════════════ -->
<script>
const ACTIONS_URL = 'settings_actions.php';

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = type === 'success' ? '✅ ' + msg : '❌ ' + msg;
  t.className = `toast ${type}`;
  t.style.display = 'flex';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.style.display = 'none'; }, 3500);
}

// ── Navbar dropdown ────────────────────────────────────────────────────────
function toggleProfileDropdown(e) {
  e.stopPropagation();
  document.getElementById('profileDropdown').classList.toggle('active');
}
document.getElementById('profileToggle')?.addEventListener('click', toggleProfileDropdown);
document.getElementById('profileDropdown')?.addEventListener('click', (e) => {
  e.stopPropagation();
});
document.addEventListener('click', (e) => {
  if (!e.target.closest('.profile-icon') && !e.target.closest('.profile-dropdown')) {
    document.getElementById('profileDropdown').classList.remove('active');
  }
});
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

// ── Inline config edit ─────────────────────────────────────────────────────
function toggleConfigEdit(key, currentVal) {
  const editForm = document.getElementById('edit-' + key);
  const inp      = document.getElementById('inp-' + key);
  editForm.classList.add('open');
  inp.value = currentVal;
  inp.focus();
}
function cancelConfigEdit(key) {
  document.getElementById('edit-' + key).classList.remove('open');
}
async function saveConfig(key) {
  const value = document.getElementById('inp-' + key).value.trim();
  const fd = new FormData();
  fd.append('action', 'update_config');
  fd.append('config_key', key);
  fd.append('config_value', value);

  try {
    const res  = await fetch(ACTIONS_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      document.getElementById('disp-' + key).textContent = value;
      document.getElementById('edit-' + key).classList.remove('open');
      showToast('Configuration saved.');
    } else {
      showToast(data.error || 'Save failed.', 'error');
    }
  } catch (e) { showToast('Network error.', 'error'); }
}

// ── Boolean toggle config ──────────────────────────────────────────────────
async function saveConfigToggle(key, checkbox) {
  const value = checkbox.checked ? 'true' : 'false';
  const lbl   = document.getElementById('toggle-lbl-' + key);
  const fd = new FormData();
  fd.append('action', 'update_config');
  fd.append('config_key', key);
  fd.append('config_value', value);
  try {
    const res  = await fetch(ACTIONS_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      lbl.textContent = value === 'true' ? 'Enabled' : 'Disabled';
      showToast(key.replace(/_/g,' ') + ' ' + (value === 'true' ? 'enabled' : 'disabled') + '.');
    } else {
      checkbox.checked = !checkbox.checked; // revert
      lbl.textContent = checkbox.checked ? 'Enabled' : 'Disabled';
      showToast(data.error || 'Save failed.', 'error');
    }
  } catch (e) {
    checkbox.checked = !checkbox.checked;
    showToast('Network error.', 'error');
  }
}

// ── Maintenance modal ──────────────────────────────────────────────────────
function openMaintenanceModal() {
  ['mAction','mDesc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('mNextDue').value = '';
  document.getElementById('maintenanceModal').classList.add('open');
}
function closeMaintenanceModal() {
  document.getElementById('maintenanceModal').classList.remove('open');
}
async function submitMaintenanceLog() {
  const fd = new FormData();
  fd.append('action',         'log_maintenance');
  fd.append('component_type', document.getElementById('mCompType').value);
  fd.append('action_label',   document.getElementById('mAction').value.trim());
  fd.append('description',    document.getElementById('mDesc').value.trim());
  fd.append('component_id',   '');
  fd.append('next_due_at',    document.getElementById('mNextDue').value);
  try {
    const res  = await fetch(ACTIONS_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      showToast('Maintenance action logged.');
      closeMaintenanceModal();
      setTimeout(() => location.reload(), 900);
    } else {
      showToast(data.error || 'Log failed.', 'error');
    }
  } catch (e) { showToast('Network error.', 'error'); }
}

// Close modals on overlay click
['maintenanceModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});
</script>

</body>
</html>

