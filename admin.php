<?php
// ============================================================================
// ECOTWIN - Admin Control Panel  (admin.php)
// Server-side data loading from ecotwin_db via PDO
// ============================================================================

require_once __DIR__ . '/auth_guard.php';
require_role('admin');
require_once __DIR__ . '/admin/db.php';

$error = null;
$currentUser = [
    'full_name' => $_SESSION['user_name'] ?? 'Administrator',
    'email'     => $_SESSION['user_email'] ?? '',
    'role'      => $_SESSION['user_role'] ?? 'admin',
];

try {
    $pdo = getDB();

    // ---- Plant categories ------------------------------------------------
    $cats   = $pdo->query("SELECT code, label, emoji FROM plant_categories ORDER BY category_id")->fetchAll();

    // ---- All plants with thresholds (for JS initialisation) ---------------
    $plantRows = $pdo->query("
        SELECT p.plant_id, p.name, p.scientific_name, p.emoji, p.photoperiod_hrs, p.notes,
               pc.code AS category_code
        FROM plants p
        JOIN plant_categories pc ON p.category_id = pc.category_id
        ORDER BY p.name
    ")->fetchAll();

    // Batch-load thresholds
    $plantIds = array_column($plantRows, 'plant_id');
    $plantsJS = [];
    if ($plantIds) {
        $in    = implode(',', array_fill(0, count($plantIds), '?'));
        $tStmt = $pdo->prepare("SELECT plant_id, parameter, val_min, val_opt_low, val_opt_high, val_max FROM plant_thresholds WHERE plant_id IN ($in)");
        $tStmt->execute($plantIds);
        $allThresh = [];
        foreach ($tStmt->fetchAll() as $t) {
            $allThresh[$t['plant_id']][$t['parameter']] = [
                (float)$t['val_min'], (float)$t['val_opt_low'],
                (float)$t['val_opt_high'], (float)$t['val_max']
            ];
        }
        foreach ($plantRows as $p) {
            $th = $allThresh[$p['plant_id']] ?? [];
            $plantsJS[] = [
                'id'       => (int)$p['plant_id'],
                'name'     => $p['name'],
                'sci'      => $p['scientific_name'],
                'category' => $p['category_code'],
                'icon'     => $p['emoji'],
                'photo'    => (int)$p['photoperiod_hrs'],
                'notes'    => $p['notes'],
                'temp'  => isset($th['temperature'])  ? ['min'=>$th['temperature'][0],  'optLow'=>$th['temperature'][1],  'optHigh'=>$th['temperature'][2],  'max'=>$th['temperature'][3]]  : null,
                'hum'   => isset($th['humidity'])     ? ['min'=>$th['humidity'][0],     'optLow'=>$th['humidity'][1],     'optHigh'=>$th['humidity'][2],     'max'=>$th['humidity'][3]]     : null,
                'ph'    => isset($th['ph'])            ? ['min'=>$th['ph'][0],           'optLow'=>$th['ph'][1],           'optHigh'=>$th['ph'][2],           'max'=>$th['ph'][3]]           : null,
                'ec'    => isset($th['ec'])            ? ['min'=>$th['ec'][0],           'optLow'=>$th['ec'][1],           'optHigh'=>$th['ec'][2],           'max'=>$th['ec'][3]]           : null,
                'lux'   => isset($th['light'])        ? ['min'=>$th['light'][0],        'optLow'=>$th['light'][1],        'optHigh'=>$th['light'][2],        'max'=>$th['light'][3]]        : null,
                'water' => isset($th['water_level'])  ? ['min'=>$th['water_level'][0],  'opt'=>$th['water_level'][1],     'max'=>$th['water_level'][3]]                                    : null,
            ];
        }
    }

    // ---- Users -----------------------------------------------------------
    $userRows = $pdo->query("
        SELECT user_id, full_name, email, role, status,
               COALESCE(
                 CASE
                   WHEN last_login_at >= NOW() - INTERVAL 1 HOUR  THEN 'Just now'
                   WHEN last_login_at >= NOW() - INTERVAL 24 HOUR THEN CONCAT(TIMESTAMPDIFF(HOUR, last_login_at, NOW()), ' hrs ago')
                   WHEN last_login_at >= NOW() - INTERVAL 7 DAY   THEN CONCAT(TIMESTAMPDIFF(DAY,  last_login_at, NOW()), ' days ago')
                   ELSE DATE_FORMAT(last_login_at, '%b %d, %Y')
                 END,
                 'Never'
               ) AS last_active
        FROM users
        ORDER BY FIELD(role,'admin','researcher','student'), full_name
    ")->fetchAll();

    // ---- Greenhouse assignments -------------------------------------------
    $ghRows = $pdo->query("
        SELECT g.code, g.name AS gh_name, g.role AS gh_role,
               g.assigned_plant_id,
               p.name AS plant_name, p.emoji AS plant_emoji, p.scientific_name AS plant_sci
        FROM greenhouses g
        LEFT JOIN plants p ON g.assigned_plant_id = p.plant_id
        ORDER BY g.code
    ")->fetchAll();
    $ghAssignments = [];
    foreach ($ghRows as $g) {
        $ghAssignments[$g['code']] = (int)($g['assigned_plant_id'] ?? 0);
    }

    // ---- System settings -------------------------------------------------
    $settingsRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
    $settings = [];
    foreach ($settingsRows as $r) $settings[$r['setting_key']] = $r['setting_value'];

    // ---- Maintenance log (last 4) -----------------------------------------
    $maint = $pdo->query("
        SELECT action, description, DATE_FORMAT(performed_at,'%b %d, %Y') AS date_fmt,
               DATE_FORMAT(next_due_at,'%b %d, %Y') AS next_fmt
        FROM maintenance_log
        ORDER BY performed_at DESC
        LIMIT 4
    ")->fetchAll();

} catch (PDOException $e) {
    $error = $e->getMessage();
    $plantsJS     = [];
    $userRows     = [];
    $cats         = [];
    $ghAssignments= ['A'=>0,'B'=>0];
    $settings     = [];
    $maint        = [];
    $ghRows       = [];
}

// Helper to get setting value
function setting(array $s, string $key, $default = '') {
    return $s[$key] ?? $default;
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, fn($part) => $part !== ''));
    if (!$parts) return 'AD';
    $first = strtoupper(substr($parts[0], 0, 1));
    $last = count($parts) > 1 ? strtoupper(substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Panel - EcoTwin</title>
  <link rel="stylesheet" href="css.main.css" />
  <link rel="stylesheet" href="css.admin.css" />
</head>
<body>

<!-- ============================================================ NAVBAR -->
<nav class="navbar">
  <div class="navbar-container">
    <a href="dashboard.php" class="navbar-logo">
      <div class="logo-icon">🧪</div>
      <span class="logo-text">EcoTwin</span>
    </a>
    <div class="navbar-menu">
      <a href="dashboard.php"    class="nav-item">Dashboard</a>
      <a href="experiments.php"  class="nav-item">Experiments</a>
      <a href="greenhouses.php"  class="nav-item">Greenhouses</a>
      <a href="reports.php"      class="nav-item">Reports</a>
      <a href="settings.php"     class="nav-item">Settings</a>
      <a href="admin.php"        class="nav-item active">Admin</a>
    </div>
    <div class="navbar-user">
      <div class="admin-badge-nav">⚙️ Admin</div>
      <div class="profile-icon" onclick="toggleProfileDropdown(event)"><?= htmlspecialchars(initials($currentUser['full_name'])) ?></div>
      <div class="profile-dropdown" id="profileDropdown">
        <div class="profile-dropdown-header">
          <div class="profile-user-info">
            <div class="profile-user-name"><?= htmlspecialchars($currentUser['full_name']) ?></div>
            <div class="profile-user-email"><?= htmlspecialchars($currentUser['email']) ?></div>
            <div class="profile-user-role" style="color:#F59E0B;">⚙️ Administrator</div>
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

<!-- ========================================================= MAIN -->
<main class="main-content">

  <?php if ($error): ?>
  <div class="alert alert-danger mb-3">
    <span class="alert-icon">⚠️</span>
    <div><strong>Database Error:</strong> <?= htmlspecialchars($error) ?></div>
  </div>
  <?php endif; ?>

  <!-- Page Header -->
  <div class="admin-page-header">
    <div class="admin-header-left">
      <div class="admin-title-row">
        <div class="admin-crown">⚙️</div>
        <div>
          <h1 class="page-title">Admin Control Panel</h1>
          <p class="page-subtitle">Plant configuration, greenhouse thresholds &amp; system management</p>
        </div>
      </div>
    </div>
    <div class="admin-header-right">
      <span class="admin-role-badge">Administrator Access</span>
    </div>
  </div>

  <!-- Admin Tabs -->
  <div class="admin-tabs mb-3">
    <button class="admin-tab active" onclick="switchTab('plants',    this)">🌱 Plant Library</button>
    <button class="admin-tab"        onclick="switchTab('greenhouse', this)">🏠 Greenhouse Assignment</button>
    <button class="admin-tab"        onclick="switchTab('users',      this)">👥 User Management</button>
    <button class="admin-tab"        onclick="switchTab('system',     this)">🖥️ System Config</button>
  </div>

  <!-- ==================================================================
       TAB 1: PLANT LIBRARY
       ================================================================== -->
  <div id="tab-plants" class="tab-content active">
    <div class="tab-layout">

      <!-- Left: Plant Selector -->
      <div class="plant-selector-panel">
        <div class="panel-header">
          <h2 class="panel-title">🌿 Plant Library</h2>
          <button class="btn btn-primary btn-sm" onclick="openAddPlantModal()">+ Add Plant</button>
        </div>
        <div class="plant-search-wrap">
          <input type="text" class="plant-search" id="plantSearch" placeholder="🔍  Search plants..." oninput="filterPlants()" />
        </div>
        <div class="plant-category-filters">
          <button class="cat-btn active" onclick="filterByCategory('all', this)">All</button>
          <?php foreach ($cats as $cat): ?>
          <button class="cat-btn" onclick="filterByCategory('<?= htmlspecialchars($cat['code']) ?>', this)">
            <?= $cat['emoji'] ?> <?= htmlspecialchars($cat['label']) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <div class="plant-list" id="plantList"><!-- JS renders --></div>
      </div>

      <!-- Right: Plant Detail -->
      <div class="plant-detail-panel" id="plantDetailPanel">
        <div class="detail-placeholder" id="detailPlaceholder">
          <div class="placeholder-icon">🌱</div>
          <div class="placeholder-text">Select a plant to view &amp; edit its thresholds</div>
          <div class="placeholder-subtext">Or add a new plant to the library</div>
        </div>
        <div class="plant-detail-content" id="plantDetailContent" style="display:none;"></div>
      </div>
    </div>
  </div>

  <!-- ==================================================================
       TAB 2: GREENHOUSE ASSIGNMENT
       ================================================================== -->
  <div id="tab-greenhouse" class="tab-content">
    <div class="alert alert-info mb-3">
      <span class="alert-icon">ℹ️</span>
      <div>Assign a plant profile to each greenhouse. Thresholds will be automatically loaded from the plant library and applied to automation rules.</div>
    </div>

    <div class="greenhouse-assign-grid">

      <?php foreach ($ghRows as $gh):
            $ghCode = $gh['code'];
            $isAssigned = !empty($gh['assigned_plant_id']);
      ?>
      <div class="gh-assign-card gh-<?= strtolower($ghCode) ?>-card">
        <div class="gh-assign-header">
          <div class="gh-assign-icon">🏠</div>
          <div>
            <div class="gh-assign-title">Greenhouse <?= $ghCode ?></div>
            <div class="gh-assign-role"><?= ucfirst($gh['gh_role']) ?> Group</div>
          </div>
          <span class="badge <?= $isAssigned ? 'badge-success' : 'badge-neutral' ?>" id="gh<?= $ghCode ?>-status">
            <?= $isAssigned ? 'Assigned' : 'Unassigned' ?>
          </span>
        </div>
        <div class="gh-assign-body">
          <div class="form-group mb-2">
            <label class="form-label-admin">Assigned Plant</label>
            <select class="form-select-admin" id="gh<?= $ghCode ?>-plant" onchange="onGhPlantChange('<?= $ghCode ?>')">
              <option value="">— Select a plant —</option>
            </select>
          </div>
          <div class="assigned-plant-preview" id="gh<?= $ghCode ?>-preview"></div>
          <div class="gh-threshold-summary"    id="gh<?= $ghCode ?>-thresholds"></div>
        </div>
        <div class="gh-assign-footer">
          <button class="btn btn-primary"   onclick="applyGhAssignment('<?= $ghCode ?>')">Apply to Greenhouse <?= $ghCode ?></button>
          <button class="btn btn-secondary" onclick="clearGhAssignment('<?= $ghCode ?>')">Clear</button>
        </div>
      </div>
      <?php endforeach; ?>

    </div>

    <!-- Active Profiles Summary -->
    <section class="card mt-4">
      <div class="card-header">
        <h2 class="card-title">Active Threshold Profiles</h2>
        <span class="badge badge-info">Live</span>
      </div>
      <table class="table">
        <thead>
          <tr>
            <th>Greenhouse</th><th>Plant</th><th>Temp Range (°C)</th>
            <th>Humidity (%)</th><th>pH Range</th><th>EC (mS/cm)</th>
            <th>Light (lux)</th><th>Status</th>
          </tr>
        </thead>
        <tbody id="activeProfilesBody"><!-- JS renders --></tbody>
      </table>
    </section>

    <section class="card mt-4 simulator-card">
      <div class="card-header">
        <h2 class="card-title">Sensor Reading Simulator</h2>
        <span class="badge badge-warning">Admin Tool</span>
      </div>
      <div class="simulator-layout">
        <div class="simulator-controls">
          <div class="simulator-intro">
            Push simulated greenhouse readings into the live sensor stream for demos, UI checks, and threshold validation.
          </div>

          <div class="simulator-form-grid">
            <div class="form-group">
              <label class="form-label-admin" for="sim-gh">Greenhouse</label>
              <select class="form-select-admin" id="sim-gh" onchange="onSimulatorGreenhouseChange()">
                <option value="A">Greenhouse A</option>
                <option value="B">Greenhouse B</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label-admin" for="sim-scenario">Scenario</label>
              <select class="form-select-admin" id="sim-scenario">
                <option value="optimal">Optimal Range</option>
                <option value="warning">Warning Drift</option>
                <option value="critical">Critical Alert</option>
                <option value="custom">Custom Values</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label-admin" for="sim-count">Samples</label>
              <select class="form-select-admin" id="sim-count">
                <option value="1">1 sample</option>
                <option value="3">3 samples</option>
                <option value="6">6 samples</option>
                <option value="12">12 samples</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label-admin" for="sim-step">Spacing</label>
              <select class="form-select-admin" id="sim-step">
                <option value="1">1 minute</option>
                <option value="5" selected>5 minutes</option>
                <option value="10">10 minutes</option>
                <option value="15">15 minutes</option>
              </select>
            </div>
          </div>

          <div class="simulator-actions">
            <button class="btn btn-secondary" onclick="fillSimulatorFromPlant()">Load Plant Targets</button>
            <button class="btn btn-secondary" onclick="generateSimulatorScenario()">Generate Scenario</button>
            <button class="btn btn-primary" onclick="pushSimulatorReadings()">Push Simulated Readings</button>
          </div>
        </div>

        <div class="simulator-panel">
          <div class="simulator-panel-header">
            <div>
              <div class="simulator-panel-title">Simulated Values</div>
              <div class="simulator-panel-subtitle" id="sim-assignment-note">No plant assignment loaded yet.</div>
            </div>
          </div>

          <div class="simulator-value-grid">
            <div class="sim-field">
              <label class="form-label-admin" for="sim-temperature">Temperature (°C)</label>
              <input type="number" step="0.1" class="form-select-admin" id="sim-temperature" />
            </div>
            <div class="sim-field">
              <label class="form-label-admin" for="sim-humidity">Humidity (%)</label>
              <input type="number" step="0.1" class="form-select-admin" id="sim-humidity" />
            </div>
            <div class="sim-field">
              <label class="form-label-admin" for="sim-ph">pH</label>
              <input type="number" step="0.1" class="form-select-admin" id="sim-ph" />
            </div>
            <div class="sim-field">
              <label class="form-label-admin" for="sim-ec">EC (mS/cm)</label>
              <input type="number" step="0.1" class="form-select-admin" id="sim-ec" />
            </div>
            <div class="sim-field">
              <label class="form-label-admin" for="sim-light">Light (lux)</label>
              <input type="number" step="100" class="form-select-admin" id="sim-light" />
            </div>
            <div class="sim-field">
              <label class="form-label-admin" for="sim-water_level">Water Level (%)</label>
              <input type="number" step="1" class="form-select-admin" id="sim-water_level" />
            </div>
          </div>

          <div class="simulator-hint" id="sim-hint">
            Values will be based on the assigned plant profile when one exists, then lightly varied per sample.
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- ==================================================================
       TAB 3: USER MANAGEMENT
       ================================================================== -->
  <div id="tab-users" class="tab-content">
    <div class="users-layout">
      <section class="card">
        <div class="card-header">
          <h2 class="card-title">System Users
            <span class="badge badge-neutral" style="margin-left:8px;"><?= count($userRows) ?> total</span>
          </h2>
          <button class="btn btn-primary btn-sm" onclick="openAddUserModal()">+ Add User</button>
        </div>
        <table class="table" id="usersTable">
          <thead>
            <tr>
              <th>User</th><th>Email</th><th>Role</th>
              <th>Status</th><th>Last Active</th><th>Actions</th>
            </tr>
          </thead>
          <tbody id="usersTableBody">
            <?php
            $roleColors = ['admin'=>'badge-success','researcher'=>'badge-info','student'=>'badge-neutral'];
            foreach ($userRows as $u):
              $initials = implode('', array_map(fn($w)=>$w[0], array_slice(explode(' ', $u['full_name']), 0, 2)));
              $badgeClass = $roleColors[$u['role']] ?? 'badge-neutral';
            ?>
            <tr id="user-row-<?= $u['user_id'] ?>">
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div class="user-avatar-small"><?= strtoupper(mb_substr($initials, 0, 2)) ?></div>
                  <span><?= htmlspecialchars($u['full_name']) ?></span>
                </div>
              </td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($u['role']) ?></span></td>
              <td>
                <span class="status-dot <?= $u['status']==='active' ? 'status-online' : 'status-offline' ?>"></span>
                <?= ucfirst($u['status']) ?>
              </td>
              <td><?= htmlspecialchars($u['last_active']) ?></td>
              <td>
                <div style="display:flex;gap:6px;">
                  <select class="btn btn-secondary btn-sm role-dropdown" data-user-id="<?= $u['user_id'] ?>" onchange="changeUserRoleDropdown(this)">
                    <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
                    <option value="researcher" <?= $u['role']==='researcher'?'selected':'' ?>>Researcher</option>
                    <option value="student" <?= $u['role']==='student'?'selected':'' ?>>Student</option>
                  </select>
                  <button class="btn btn-danger    btn-sm" onclick="deleteUser(<?= $u['user_id'] ?>)">Remove</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    </div>
  </div>

  <!-- ==================================================================
       TAB 4: SYSTEM CONFIG
       ================================================================== -->
  <div id="tab-system" class="tab-content">
    <div class="settings-grid">

      <!-- Data Sync -->
      <section class="card">
        <div class="card-header"><h2 class="card-title">🔄 Data Sync Settings</h2></div>
        <div class="settings-content">
          <div class="setting-item">
            <div class="setting-label">Sync Interval</div>
            <div class="setting-value">
              <select class="form-select-admin" id="cfg-sync_interval_minutes" style="width:140px;">
                <?php foreach ([1,2,5,10] as $v): ?>
                <option value="<?= $v ?>" <?= setting($settings,'sync_interval_minutes','2')==$v?'selected':'' ?>>
                  <?= $v ?> minute<?= $v>1?'s':'' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="setting-item">
            <div class="setting-label">Alert Cooldown</div>
            <div class="setting-value">
              <select class="form-select-admin" id="cfg-alert_cooldown_minutes" style="width:140px;">
                <?php foreach ([5=>5,15=>15,30=>30] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= setting($settings,'alert_cooldown_minutes','15')==$v?'selected':'' ?>>
                  <?= $l ?> minutes
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="setting-item">
            <div class="setting-label">Data Retention</div>
            <div class="setting-value">
              <select class="form-select-admin" id="cfg-data_retention_days" style="width:140px;">
                <?php foreach ([30=>'30 days',90=>'90 days',365=>'1 year',0=>'Forever'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= setting($settings,'data_retention_days','90')==$v?'selected':'' ?>>
                  <?= $l ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="setting-item">
            <div class="setting-label">Auto Backup</div>
            <div class="setting-value">
              <label class="toggle-switch">
                <input type="checkbox" id="cfg-auto_backup_enabled"
                  <?= setting($settings,'auto_backup_enabled','1')?'checked':'' ?> />
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          <div class="mt-3">
            <button class="btn btn-primary" onclick="saveSyncSettings()">Save Sync Settings</button>
          </div>
        </div>
      </section>

      <!-- Notification Config -->
      <section class="card">
        <div class="card-header"><h2 class="card-title">🔔 Notification Settings</h2></div>
        <div class="settings-content">
          <?php
          $notifToggles = [
            'email_critical_alerts' => 'Critical Alerts (Email)',
            'email_warning_alerts'  => 'Warning Alerts (Email)',
            'email_weekly_reports'  => 'Weekly Reports',
          ];
          foreach ($notifToggles as $key => $label):
          ?>
          <div class="setting-item">
            <div class="setting-label"><?= $label ?></div>
            <div class="setting-value">
              <label class="toggle-switch">
                <input type="checkbox" id="cfg-<?= $key ?>"
                  <?= setting($settings, $key, '1') ? 'checked' : '' ?> />
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="setting-item">
            <div class="setting-label">Notify Admin Email</div>
            <div class="setting-value">
              <input type="email" id="cfg-admin_notify_email" class="form-select-admin"
                     value="<?= htmlspecialchars(setting($settings,'admin_notify_email','m.chen@spamast.edu')) ?>"
                     style="width:220px;" />
            </div>
          </div>
          <div class="mt-3">
            <button class="btn btn-primary" onclick="saveNotificationSettings()">Save Notification Settings</button>
          </div>
        </div>
      </section>

      <!-- Automation Rules -->
      <section class="card">
        <div class="card-header"><h2 class="card-title">⚙️ Automation Rules</h2></div>
        <div class="settings-content">
          <?php
          $automationRules = [
            'auto_cooling_fan'      => 'Auto-activate cooling fan on high temp',
            'auto_ph_correction'    => 'Auto pH correction pump',
            'auto_ec_dosing'        => 'Auto EC nutrient dosing',
            'auto_shading_net'      => 'Auto shading net on excess light',
            'auto_humidity_misting' => 'Auto humidity misting',
          ];
          foreach ($automationRules as $key => $label):
          ?>
          <div class="setting-item">
            <div class="setting-label"><?= $label ?></div>
            <div class="setting-value">
              <label class="toggle-switch">
                <input type="checkbox" id="cfg-<?= $key ?>"
                  <?= setting($settings, $key, '1') ? 'checked' : '' ?> />
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="mt-3">
            <button class="btn btn-primary" onclick="saveAutomationSettings()">Save Automation Rules</button>
          </div>
        </div>
      </section>

      <!-- Maintenance Log -->
      <section class="card">
        <div class="card-header"><h2 class="card-title">🔧 Maintenance Actions</h2></div>
        <div class="settings-content">

          <?php if ($maint): ?>
          <div class="alert alert-info mb-2" style="font-size:13px;">
            <span>📋</span>
            <span><strong>Last action:</strong>
              <?= htmlspecialchars($maint[0]['action']) ?> on <?= $maint[0]['date_fmt'] ?>
            </span>
          </div>
          <?php endif; ?>

          <div class="maintenance-item mb-2">
            <div class="maintenance-info">
              <div class="maintenance-title">Reset Sensor Calibration Flags</div>
              <div class="maintenance-date">Force recalibration on next cycle</div>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="runMaintenance('reset_calibration')">Run</button>
          </div>
          <div class="maintenance-item mb-2">
            <div class="maintenance-info">
              <div class="maintenance-title">Clear Alert History</div>
              <div class="maintenance-date">Removes resolved alerts older than 30 days</div>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="runMaintenance('clear_alerts')">Run</button>
          </div>
          <div class="maintenance-item mb-2">
            <div class="maintenance-info">
              <div class="maintenance-title">Export Full Database Backup</div>
              <div class="maintenance-date">Downloads .sql backup file</div>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="runMaintenance('backup')">Export</button>
          </div>
          <div class="maintenance-item">
            <div class="maintenance-info">
              <div class="maintenance-title">Reboot Hardware Modules</div>
              <div class="maintenance-date">Sends reboot signal to Arduino &amp; ESP32</div>
            </div>
            <button class="btn btn-danger btn-sm" onclick="runMaintenance('reboot')">Reboot</button>
          </div>
        </div>
      </section>

    </div><!-- /settings-grid -->
  </div>

</main><!-- /main-content -->

<!-- ==================================================================
     MODAL: Add / Edit Plant
     ================================================================== -->
<div class="modal-overlay" id="plantModal" onclick="closePlantModal(event)">
  <div class="modal-container modal-wide">
    <div class="modal-header">
      <div class="modal-icon confirm">🌱</div>
      <h2 class="modal-title" id="plantModalTitle">Add New Plant</h2>
    </div>
    <div class="modal-body">
      <div class="modal-form-grid">
        <div class="form-group-modal">
          <label>Plant Name *</label>
          <input type="text" id="m-name" class="form-input-modal" placeholder="e.g. Cherry Tomato" />
        </div>
        <div class="form-group-modal">
          <label>Scientific Name</label>
          <input type="text" id="m-sci" class="form-input-modal" placeholder="e.g. Solanum lycopersicum" />
        </div>
        <div class="form-group-modal">
          <label>Category *</label>
          <select id="m-cat" class="form-select-modal">
            <?php foreach ($cats as $cat): ?>
            <option value="<?= htmlspecialchars($cat['code']) ?>">
              <?= $cat['emoji'] ?> <?= htmlspecialchars($cat['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group-modal">
          <label>Emoji Icon</label>
          <input type="text" id="m-icon" class="form-input-modal" placeholder="🌱" maxlength="4" />
        </div>
      </div>

      <div class="threshold-section-label">🌡️ Temperature (°C)</div>
      <div class="threshold-row">
        <div class="form-group-modal"><label>Min Warning</label><input type="number" id="m-temp-min"      class="form-input-modal" step="0.5" /></div>
        <div class="form-group-modal"><label>Optimal Low</label><input type="number" id="m-temp-opt-low"  class="form-input-modal" step="0.5" /></div>
        <div class="form-group-modal"><label>Optimal High</label><input type="number" id="m-temp-opt-high" class="form-input-modal" step="0.5" /></div>
        <div class="form-group-modal"><label>Max Critical</label><input type="number" id="m-temp-max"     class="form-input-modal" step="0.5" /></div>
      </div>

      <div class="threshold-section-label">💧 Humidity (%)</div>
      <div class="threshold-row">
        <div class="form-group-modal"><label>Min Warning</label><input type="number" id="m-hum-min"      class="form-input-modal" /></div>
        <div class="form-group-modal"><label>Optimal Low</label><input type="number" id="m-hum-opt-low"  class="form-input-modal" /></div>
        <div class="form-group-modal"><label>Optimal High</label><input type="number" id="m-hum-opt-high" class="form-input-modal" /></div>
        <div class="form-group-modal"><label>Max Warning</label><input type="number" id="m-hum-max"      class="form-input-modal" /></div>
      </div>

      <div class="threshold-section-label">🧪 pH Level</div>
      <div class="threshold-row">
        <div class="form-group-modal"><label>Min Critical</label><input type="number" id="m-ph-min"      class="form-input-modal" step="0.1" /></div>
        <div class="form-group-modal"><label>Optimal Low</label><input type="number" id="m-ph-opt-low"   class="form-input-modal" step="0.1" /></div>
        <div class="form-group-modal"><label>Optimal High</label><input type="number" id="m-ph-opt-high"  class="form-input-modal" step="0.1" /></div>
        <div class="form-group-modal"><label>Max Critical</label><input type="number" id="m-ph-max"      class="form-input-modal" step="0.1" /></div>
      </div>

      <div class="threshold-section-label">⚡ EC / TDS (mS/cm)</div>
      <div class="threshold-row">
        <div class="form-group-modal"><label>Min Warning</label><input type="number" id="m-ec-min"      class="form-input-modal" step="0.1" /></div>
        <div class="form-group-modal"><label>Optimal Low</label><input type="number" id="m-ec-opt-low"  class="form-input-modal" step="0.1" /></div>
        <div class="form-group-modal"><label>Optimal High</label><input type="number" id="m-ec-opt-high" class="form-input-modal" step="0.1" /></div>
        <div class="form-group-modal"><label>Max Critical</label><input type="number" id="m-ec-max"     class="form-input-modal" step="0.1" /></div>
      </div>

      <div class="threshold-section-label">☀️ Light Intensity (lux)</div>
      <div class="threshold-row">
        <div class="form-group-modal"><label>Min</label><input type="number" id="m-lux-min"      class="form-input-modal" step="500" /></div>
        <div class="form-group-modal"><label>Optimal Low</label><input type="number" id="m-lux-opt-low"  class="form-input-modal" step="500" /></div>
        <div class="form-group-modal"><label>Optimal High</label><input type="number" id="m-lux-opt-high" class="form-input-modal" step="500" /></div>
        <div class="form-group-modal"><label>Max</label><input type="number" id="m-lux-max"      class="form-input-modal" step="500" /></div>
      </div>

      <div class="threshold-section-label">💦 Water Level</div>
      <div class="threshold-row">
        <div class="form-group-modal"><label>Min (%)</label><input type="number" id="m-water-min" class="form-input-modal" /></div>
        <div class="form-group-modal"><label>Optimal (%)</label><input type="number" id="m-water-opt" class="form-input-modal" /></div>
        <div class="form-group-modal"><label>Max (%)</label><input type="number" id="m-water-max" class="form-input-modal" /></div>
        <div class="form-group-modal"><label>Photoperiod (hrs/day)</label><input type="number" id="m-photo" class="form-input-modal" min="0" max="24" /></div>
      </div>

      <div class="form-group-modal mt-2">
        <label>Notes / Growing Tips</label>
        <textarea id="m-notes" class="form-textarea-modal" rows="3" placeholder="Optional notes for researchers..."></textarea>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closePlantModalDirect()">Cancel</button>
      <button class="btn btn-primary"   onclick="savePlant()">Save Plant Profile</button>
    </div>
  </div>
</div>

<!-- ==================================================================
     MODAL: Add User
     ================================================================== -->
<div class="modal-overlay" id="userModal" onclick="closeUserModal(event)">
  <div class="modal-container">
    <div class="modal-header">
      <div class="modal-icon confirm">👤</div>
      <h2 class="modal-title">Add New User</h2>
    </div>
    <div class="modal-body">
      <div class="form-group-modal">
        <label>Full Name *</label>
        <input type="text" id="u-name" class="form-input-modal" placeholder="Dr. Jane Smith" />
      </div>
      <div class="form-group-modal">
        <label>Email *</label>
        <input type="email" id="u-email" class="form-input-modal" placeholder="user@spamast.edu" />
      </div>
      <div class="form-group-modal">
        <label>Role *</label>
        <select id="u-role" class="form-select-modal">
          <option value="admin">Administrator</option>
          <option value="researcher" selected>Researcher</option>
          <option value="student">Student</option>
        </select>
      </div>
      <div class="form-group-modal">
        <label>Temporary Password *</label>
        <input type="password" id="u-pass" class="form-input-modal" placeholder="Min. 8 characters" />
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeUserModalDirect()">Cancel</button>
      <button class="btn btn-primary"   onclick="saveUser()">Add User</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- ============================================================ SCRIPTS -->
<script>
// ============================================================================
// DATA FROM PHP (server-side JSON injection)
// ============================================================================
let plants       = <?= json_encode($plantsJS, JSON_UNESCAPED_UNICODE) ?>;
const ghInit     = <?= json_encode($ghAssignments) ?>;
const ghAssignments = { A: ghInit['A'] || null, B: ghInit['B'] || null };
const simulatorFieldMap = {
  temperature: 'sim-temperature',
  humidity: 'sim-humidity',
  ph: 'sim-ph',
  ec: 'sim-ec',
  light: 'sim-light',
  water_level: 'sim-water_level'
};

let editingPlantId  = null;
let selectedPlantId = null;
let currentCategory = 'all';

// ============================================================================
// API HELPERS
// ============================================================================
async function api(url, method = 'GET', body = null) {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  const res  = await fetch(url, opts);
  const data = await res.json();
  if (!data.success) throw new Error(data.error || 'Request failed');
  return data;
}

// ============================================================================
// TABS
// ============================================================================
function switchTab(name, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
  if (name === 'greenhouse') {
    renderGhSelects();
    onSimulatorGreenhouseChange();
  }
}

// ============================================================================
// PLANT LIBRARY
// ============================================================================
function catLabel(c) {
  return { leafy:'🥬 Leafy', fruiting:'🍅 Fruiting', herb:'🌿 Herb', root:'🥕 Root' }[c] || c;
}

function filterPlants() {
  const q = document.getElementById('plantSearch').value.toLowerCase();
  renderPlantList(plants.filter(p =>
    (currentCategory === 'all' || p.category === currentCategory) &&
    (p.name.toLowerCase().includes(q) || (p.sci||'').toLowerCase().includes(q))
  ));
}

function filterByCategory(cat, btn) {
  currentCategory = cat;
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  filterPlants();
}

function renderPlantList(list) {
  const el = document.getElementById('plantList');
  if (!list.length) { el.innerHTML = '<div class="no-plants">No plants found</div>'; return; }
  el.innerHTML = list.map(p => `
    <div class="plant-list-item ${selectedPlantId === p.id ? 'selected' : ''}" onclick="selectPlant(${p.id})">
      <div class="plant-item-icon">${p.icon}</div>
      <div class="plant-item-info">
        <div class="plant-item-name">${p.name}</div>
        <div class="plant-item-sci">${p.sci || ''}</div>
      </div>
      <span class="plant-cat-tag cat-${p.category}">${catLabel(p.category)}</span>
    </div>
  `).join('');
}

function selectPlant(id) {
  selectedPlantId = id;
  filterPlants();
  renderPlantDetail(plants.find(p => p.id === id));
}

function renderPlantDetail(p) {
  document.getElementById('detailPlaceholder').style.display = 'none';
  const content = document.getElementById('plantDetailContent');
  content.style.display = 'block';
  const th = p;
  content.innerHTML = `
    <div class="detail-header">
      <div class="detail-icon-large">${p.icon}</div>
      <div class="detail-title-block">
        <h2 class="detail-plant-name">${p.name}</h2>
        <div class="detail-plant-sci">${p.sci || ''}</div>
        <span class="plant-cat-tag cat-${p.category}">${catLabel(p.category)}</span>
      </div>
      <div class="detail-actions">
        <button class="btn btn-secondary btn-sm" onclick="openEditPlantModal(${p.id})">✏️ Edit</button>
        <button class="btn btn-danger    btn-sm" onclick="deletePlant(${p.id})">🗑 Delete</button>
      </div>
    </div>
    <div class="threshold-cards-grid">
      ${p.temp  ? thresholdCard('🌡️','Temperature',p.temp.min, p.temp.optLow,  p.temp.optHigh,  p.temp.max,  '°C')    : ''}
      ${p.hum   ? thresholdCard('💧','Humidity',   p.hum.min,  p.hum.optLow,   p.hum.optHigh,   p.hum.max,   '%')     : ''}
      ${p.ph    ? thresholdCard('🧪','pH Level',   p.ph.min,   p.ph.optLow,    p.ph.optHigh,    p.ph.max,    '')      : ''}
      ${p.ec    ? thresholdCard('⚡','EC / TDS',   p.ec.min,   p.ec.optLow,    p.ec.optHigh,    p.ec.max,    'mS/cm') : ''}
      ${p.lux   ? thresholdCard('☀️','Light',      p.lux.min,  p.lux.optLow,   p.lux.optHigh,   p.lux.max,   'lux')   : ''}
      <div class="th-card">
        <div class="th-card-header"><span>💦</span> Water / Lighting</div>
        ${p.water ? `
          <div class="th-row"><span class="th-label">Min Water</span><span class="th-val">${p.water.min}%</span></div>
          <div class="th-row"><span class="th-label">Optimal Water</span><span class="th-val">${p.water.opt}%</span></div>
          <div class="th-row"><span class="th-label">Max Water</span><span class="th-val">${p.water.max}%</span></div>
        ` : ''}
        <div class="th-row"><span class="th-label">Photoperiod</span><span class="th-val">${p.photo} hrs/day</span></div>
      </div>
    </div>
    ${p.notes ? `<div class="plant-notes"><strong>📝 Notes:</strong> ${p.notes}</div>` : ''}
  `;
}

function thresholdCard(icon, label, min, optLow, optHigh, max, unit) {
  return `
    <div class="th-card">
      <div class="th-card-header"><span>${icon}</span> ${label}</div>
      <div class="th-row"><span class="th-label">Min Warning</span><span class="th-val">${min} ${unit}</span></div>
      <div class="th-row th-optimal"><span class="th-label">✅ Optimal Range</span><span class="th-val">${optLow}–${optHigh} ${unit}</span></div>
      <div class="th-row"><span class="th-label">Max Critical</span><span class="th-val">${max} ${unit}</span></div>
    </div>`;
}

// ---- Plant Modal --------------------------------------------------------
function openAddPlantModal() {
  editingPlantId = null;
  document.getElementById('plantModalTitle').textContent = 'Add New Plant';
  clearPlantForm();
  document.getElementById('plantModal').style.display = 'flex';
}

function openEditPlantModal(id) {
  editingPlantId = id;
  const p = plants.find(x => x.id === id);
  document.getElementById('plantModalTitle').textContent = 'Edit Plant: ' + p.name;
  document.getElementById('m-name').value = p.name;
  document.getElementById('m-sci').value  = p.sci || '';
  document.getElementById('m-cat').value  = p.category;
  document.getElementById('m-icon').value = p.icon;
  if (p.temp)  { setVal('m-temp-min',p.temp.min); setVal('m-temp-opt-low',p.temp.optLow); setVal('m-temp-opt-high',p.temp.optHigh); setVal('m-temp-max',p.temp.max); }
  if (p.hum)   { setVal('m-hum-min', p.hum.min);  setVal('m-hum-opt-low', p.hum.optLow);  setVal('m-hum-opt-high', p.hum.optHigh);  setVal('m-hum-max', p.hum.max);  }
  if (p.ph)    { setVal('m-ph-min',  p.ph.min);   setVal('m-ph-opt-low',  p.ph.optLow);   setVal('m-ph-opt-high',  p.ph.optHigh);   setVal('m-ph-max',  p.ph.max);   }
  if (p.ec)    { setVal('m-ec-min',  p.ec.min);   setVal('m-ec-opt-low',  p.ec.optLow);   setVal('m-ec-opt-high',  p.ec.optHigh);   setVal('m-ec-max',  p.ec.max);   }
  if (p.lux)   { setVal('m-lux-min', p.lux.min);  setVal('m-lux-opt-low', p.lux.optLow);  setVal('m-lux-opt-high', p.lux.optHigh);  setVal('m-lux-max', p.lux.max);  }
  if (p.water) { setVal('m-water-min',p.water.min); setVal('m-water-opt',p.water.opt); setVal('m-water-max',p.water.max); }
  setVal('m-photo', p.photo);
  document.getElementById('m-notes').value = p.notes || '';
  document.getElementById('plantModal').style.display = 'flex';
}

function setVal(id, val) { document.getElementById(id).value = val ?? ''; }

function clearPlantForm() {
  ['m-name','m-sci','m-icon','m-notes'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('m-icon').value = '🌱';
  ['m-temp-min','m-temp-opt-low','m-temp-opt-high','m-temp-max',
   'm-hum-min', 'm-hum-opt-low', 'm-hum-opt-high', 'm-hum-max',
   'm-ph-min',  'm-ph-opt-low',  'm-ph-opt-high',  'm-ph-max',
   'm-ec-min',  'm-ec-opt-low',  'm-ec-opt-high',  'm-ec-max',
   'm-lux-min', 'm-lux-opt-low', 'm-lux-opt-high', 'm-lux-max',
   'm-water-min','m-water-opt',  'm-water-max',    'm-photo'].forEach(id => document.getElementById(id).value = '');
}

async function savePlant() {
  const name = document.getElementById('m-name').value.trim();
  if (!name) { showToast('❌ Plant name is required', 'error'); return; }

  const body = {
    name,
    sci:           document.getElementById('m-sci').value,
    category:      document.getElementById('m-cat').value,
    icon:          document.getElementById('m-icon').value || '🌱',
    photo:         document.getElementById('m-photo').value,
    notes:         document.getElementById('m-notes').value,
    temp_min:      document.getElementById('m-temp-min').value,
    temp_opt_low:  document.getElementById('m-temp-opt-low').value,
    temp_opt_high: document.getElementById('m-temp-opt-high').value,
    temp_max:      document.getElementById('m-temp-max').value,
    hum_min:       document.getElementById('m-hum-min').value,
    hum_opt_low:   document.getElementById('m-hum-opt-low').value,
    hum_opt_high:  document.getElementById('m-hum-opt-high').value,
    hum_max:       document.getElementById('m-hum-max').value,
    ph_min:        document.getElementById('m-ph-min').value,
    ph_opt_low:    document.getElementById('m-ph-opt-low').value,
    ph_opt_high:   document.getElementById('m-ph-opt-high').value,
    ph_max:        document.getElementById('m-ph-max').value,
    ec_min:        document.getElementById('m-ec-min').value,
    ec_opt_low:    document.getElementById('m-ec-opt-low').value,
    ec_opt_high:   document.getElementById('m-ec-opt-high').value,
    ec_max:        document.getElementById('m-ec-max').value,
    lux_min:       document.getElementById('m-lux-min').value,
    lux_opt_low:   document.getElementById('m-lux-opt-low').value,
    lux_opt_high:  document.getElementById('m-lux-opt-high').value,
    lux_max:       document.getElementById('m-lux-max').value,
    water_min:     document.getElementById('m-water-min').value,
    water_opt:     document.getElementById('m-water-opt').value,
    water_max:     document.getElementById('m-water-max').value,
  };

  try {
    let res;
    if (editingPlantId) {
      res = await api(`admin/api/plants.php?id=${editingPlantId}`, 'PUT', body);
      // Update local cache
      const idx = plants.findIndex(x => x.id === editingPlantId);
      if (idx > -1) {
        plants[idx] = buildLocalPlant(editingPlantId, body);
        if (selectedPlantId === editingPlantId) renderPlantDetail(plants[idx]);
      }
      showToast('✅ Plant profile updated!');
    } else {
      res = await api('admin/api/plants.php', 'POST', body);
      const newPlant = buildLocalPlant(res.plant_id, body);
      plants.push(newPlant);
      showToast('✅ Plant added to library!');
    }
    closePlantModalDirect();
    filterPlants();
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}

function buildLocalPlant(id, b) {
  return {
    id: +id, name: b.name, sci: b.sci, category: b.category, icon: b.icon || '🌱',
    photo: +b.photo, notes: b.notes,
    temp:  { min: +b.temp_min,  optLow: +b.temp_opt_low,  optHigh: +b.temp_opt_high,  max: +b.temp_max  },
    hum:   { min: +b.hum_min,   optLow: +b.hum_opt_low,   optHigh: +b.hum_opt_high,   max: +b.hum_max   },
    ph:    { min: +b.ph_min,    optLow: +b.ph_opt_low,    optHigh: +b.ph_opt_high,    max: +b.ph_max    },
    ec:    { min: +b.ec_min,    optLow: +b.ec_opt_low,    optHigh: +b.ec_opt_high,    max: +b.ec_max    },
    lux:   { min: +b.lux_min,   optLow: +b.lux_opt_low,   optHigh: +b.lux_opt_high,   max: +b.lux_max   },
    water: { min: +b.water_min, opt:    +b.water_opt,                                  max: +b.water_max },
  };
}

async function deletePlant(id) {
  if (!confirm('Delete this plant profile? This cannot be undone.')) return;
  try {
    await api(`admin/api/plants.php?id=${id}`, 'DELETE');
    plants = plants.filter(p => p.id !== id);
    selectedPlantId = null;
    document.getElementById('detailPlaceholder').style.display = 'flex';
    document.getElementById('plantDetailContent').style.display = 'none';
    filterPlants();
    showToast('🗑 Plant deleted');
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}

function closePlantModal(e)     { if (e.target === document.getElementById('plantModal')) closePlantModalDirect(); }
function closePlantModalDirect(){ document.getElementById('plantModal').style.display = 'none'; }

// ============================================================================
// GREENHOUSE ASSIGNMENT
// ============================================================================
function renderGhSelects() {
  ['A','B'].forEach(gh => {
    const sel = document.getElementById('gh' + gh + '-plant');
    sel.innerHTML = '<option value="">— Select a plant —</option>' +
      plants.map(p => `<option value="${p.id}" ${ghAssignments[gh] === p.id ? 'selected' : ''}>${p.icon} ${p.name}</option>`).join('');
    if (ghAssignments[gh]) onGhPlantChange(gh);
  });
  renderActiveProfiles();
}

function onGhPlantChange(gh) {
  const val     = document.getElementById('gh' + gh + '-plant').value;
  const preview = document.getElementById('gh' + gh + '-preview');
  const thresh  = document.getElementById('gh' + gh + '-thresholds');
  if (!val) { preview.innerHTML = ''; thresh.innerHTML = ''; return; }
  const p = plants.find(x => x.id === +val);
  preview.innerHTML = `
    <div class="gh-plant-preview">
      <span class="preview-icon">${p.icon}</span>
      <div><div class="preview-name">${p.name}</div><div class="preview-sci">${p.sci||''}</div></div>
    </div>`;
  thresh.innerHTML = `
    <div class="threshold-mini-grid">
      ${p.temp  ? miniThreshCard('🌡️','Temp',    p.temp.optLow  + '–' + p.temp.optHigh  + '°C')    : ''}
      ${p.hum   ? miniThreshCard('💧','Humidity', p.hum.optLow   + '–' + p.hum.optHigh   + '%')     : ''}
      ${p.ph    ? miniThreshCard('🧪','pH',       p.ph.optLow    + '–' + p.ph.optHigh)              : ''}
      ${p.ec    ? miniThreshCard('⚡','EC',       p.ec.optLow    + '–' + p.ec.optHigh    + ' mS/cm'): ''}
      ${p.lux   ? miniThreshCard('☀️','Light',   (p.lux.optLow/1000).toFixed(0)+'–'+(p.lux.optHigh/1000).toFixed(0)+'k lux') : ''}
      ${miniThreshCard('⏱️','Photo', p.photo + ' hrs/day')}
    </div>`;
}

function miniThreshCard(icon, label, val) {
  return `<div class="mini-thresh"><div class="mini-thresh-icon">${icon}</div><div class="mini-thresh-label">${label}</div><div class="mini-thresh-val">${val}</div></div>`;
}

async function applyGhAssignment(gh) {
  const val = document.getElementById('gh' + gh + '-plant').value;
  if (!val) { showToast('⚠️ Please select a plant first', 'warning'); return; }
  try {
    await api(`admin/api/greenhouses.php?code=${gh}`, 'PUT', { plant_id: +val });
    ghAssignments[gh] = +val;
    const p = plants.find(x => x.id === +val);
    document.getElementById('gh' + gh + '-status').textContent = 'Assigned';
    document.getElementById('gh' + gh + '-status').className   = 'badge badge-success';
    renderActiveProfiles();
    showToast(`✅ Greenhouse ${gh} assigned to ${p.name}`);
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}

async function clearGhAssignment(gh) {
  try {
    await api(`admin/api/greenhouses.php?code=${gh}`, 'DELETE');
    ghAssignments[gh] = null;
    document.getElementById('gh' + gh + '-plant').value     = '';
    document.getElementById('gh' + gh + '-preview').innerHTML   = '';
    document.getElementById('gh' + gh + '-thresholds').innerHTML = '';
    document.getElementById('gh' + gh + '-status').textContent  = 'Unassigned';
    document.getElementById('gh' + gh + '-status').className    = 'badge badge-neutral';
    renderActiveProfiles();
    showToast(`Greenhouse ${gh} cleared`);
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}

function renderActiveProfiles() {
  const body = document.getElementById('activeProfilesBody');
  let rows = '';
  ['A','B'].forEach(gh => {
    const p = ghAssignments[gh] ? plants.find(x => x.id === ghAssignments[gh]) : null;
    rows += `
      <tr>
        <td><span class="badge badge-greenhouse-${gh.toLowerCase()}">Greenhouse ${gh}</span></td>
        <td>${p ? p.icon + ' ' + p.name : '<span class="text-muted">—</span>'}</td>
        <td>${p && p.temp  ? p.temp.optLow  + '–' + p.temp.optHigh  + '°C'    : '—'}</td>
        <td>${p && p.hum   ? p.hum.optLow   + '–' + p.hum.optHigh   + '%'     : '—'}</td>
        <td>${p && p.ph    ? p.ph.optLow    + '–' + p.ph.optHigh               : '—'}</td>
        <td>${p && p.ec    ? p.ec.optLow    + '–' + p.ec.optHigh    + ' mS/cm': '—'}</td>
        <td>${p && p.lux   ? (p.lux.optLow/1000).toFixed(0)+'–'+(p.lux.optHigh/1000).toFixed(0)+'k lux' : '—'}</td>
        <td>${p ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-neutral">None</span>'}</td>
      </tr>`;
  });
  body.innerHTML = rows;
}

function getSimulatorGreenhouse() {
  return document.getElementById('sim-gh').value;
}

function getAssignedPlantForGh(gh) {
  return ghAssignments[gh] ? plants.find(x => x.id === ghAssignments[gh]) : null;
}

function setSimulatorField(param, value) {
  const id = simulatorFieldMap[param];
  if (!id) return;
  const el = document.getElementById(id);
  if (el) el.value = value ?? '';
}

function getSimulatorField(param) {
  const id = simulatorFieldMap[param];
  const el = id ? document.getElementById(id) : null;
  if (!el) return null;
  const val = el.value.trim();
  return val === '' ? null : Number(val);
}

function getPlantThreshold(plant, param) {
  if (!plant) return null;
  if (param === 'temperature') return plant.temp || null;
  if (param === 'humidity') return plant.hum || null;
  if (param === 'ph') return plant.ph || null;
  if (param === 'ec') return plant.ec || null;
  if (param === 'light') return plant.lux || null;
  if (param === 'water_level') return plant.water || null;
  return null;
}

function midpoint(low, high) {
  return ((Number(low) + Number(high)) / 2);
}

function roundForParam(param, value) {
  const precision = ['light', 'water_level'].includes(param) ? 0 : 1;
  return Number(value.toFixed(precision));
}

function pickScenarioValue(param, threshold, scenario) {
  if (!threshold) {
    const fallback = {
      temperature: 24.5,
      humidity: 68,
      ph: 6.2,
      ec: 1.8,
      light: 32000,
      water_level: 76
    };
    const drift = { optimal: 0, warning: 1, critical: 2, custom: 0 }[scenario] || 0;
    return fallback[param] + drift;
  }

  if (param === 'water_level') {
    const optimal = threshold.opt ?? midpoint(threshold.min ?? 55, threshold.max ?? 90);
    if (scenario === 'critical') return roundForParam(param, Math.max((threshold.min ?? 40) - 12, 5));
    if (scenario === 'warning') return roundForParam(param, Math.max((threshold.min ?? 40) - 4, 10));
    return roundForParam(param, optimal);
  }

  const optLow = threshold.optLow ?? threshold.min ?? 0;
  const optHigh = threshold.optHigh ?? threshold.max ?? optLow;
  const min = threshold.min ?? optLow;
  const max = threshold.max ?? optHigh;

  if (scenario === 'critical') {
    return roundForParam(param, max + Math.max((max - optHigh) || 1, 1));
  }
  if (scenario === 'warning') {
    return roundForParam(param, optHigh + Math.max(((max - optHigh) || (optHigh - optLow) || 1) * 0.45, 0.6));
  }
  return roundForParam(param, midpoint(optLow, optHigh));
}

function fillSimulatorFromPlant() {
  const gh = getSimulatorGreenhouse();
  const plant = getAssignedPlantForGh(gh);
  const note = document.getElementById('sim-assignment-note');
  const hint = document.getElementById('sim-hint');

  if (!plant) {
    note.textContent = `Greenhouse ${gh} has no assigned plant profile. Using fallback greenhouse-safe defaults.`;
    hint.textContent = 'Assign a plant to this greenhouse to auto-fill simulator values from its threshold profile.';
  } else {
    note.textContent = `Greenhouse ${gh} is assigned to ${plant.icon} ${plant.name}.`;
    hint.textContent = 'These values come from the midpoint of the assigned plant thresholds and can be edited before sending.';
  }

  Object.keys(simulatorFieldMap).forEach(param => {
    const threshold = getPlantThreshold(plant, param);
    setSimulatorField(param, pickScenarioValue(param, threshold, 'optimal'));
  });
}

function generateSimulatorScenario() {
  const gh = getSimulatorGreenhouse();
  const plant = getAssignedPlantForGh(gh);
  const scenario = document.getElementById('sim-scenario').value;

  Object.keys(simulatorFieldMap).forEach(param => {
    const threshold = getPlantThreshold(plant, param);
    setSimulatorField(param, pickScenarioValue(param, threshold, scenario));
  });

  const hintText = {
    optimal: 'Optimal scenario loaded. Values sit inside the assigned target range.',
    warning: 'Warning scenario loaded. Values drift just outside the optimal band to test caution states.',
    critical: 'Critical scenario loaded. Values move beyond safe limits to trigger alert-level behavior.',
    custom: 'Custom mode selected. Edit any field before pushing readings.'
  };
  document.getElementById('sim-hint').textContent = hintText[scenario] || 'Simulator values updated.';
  document.getElementById('sim-assignment-note').textContent = plant
    ? `Greenhouse ${gh} is assigned to ${plant.icon} ${plant.name}.`
    : `Greenhouse ${gh} has no assigned plant profile.`;
}

function onSimulatorGreenhouseChange() {
  fillSimulatorFromPlant();
}

async function pushSimulatorReadings() {
  const greenhouse = getSimulatorGreenhouse();
  const values = {};
  Object.keys(simulatorFieldMap).forEach(param => {
    const val = getSimulatorField(param);
    if (val !== null && !Number.isNaN(val)) values[param] = val;
  });

  if (!Object.keys(values).length) {
    showToast('Please enter at least one simulated reading', 'warning');
    return;
  }

  try {
    const res = await api('admin/api/simulator.php', 'POST', {
      greenhouse,
      scenario: document.getElementById('sim-scenario').value,
      samples: Number(document.getElementById('sim-count').value || 1),
      interval_minutes: Number(document.getElementById('sim-step').value || 5),
      values
    });

    const skipped = res.skipped_parameters?.length ? ` Skipped: ${res.skipped_parameters.join(', ')}.` : '';
    showToast(`Simulated ${res.inserted_rows} reading row(s) for Greenhouse ${greenhouse}.${skipped}`);
    document.getElementById('sim-hint').textContent =
      `Last push: ${res.inserted_rows} reading row(s) generated for Greenhouse ${greenhouse}.`;
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}

// ============================================================================
// USER MANAGEMENT
// ============================================================================
function openAddUserModal()  { document.getElementById('userModal').style.display = 'flex'; }
function closeUserModal(e)   { if (e.target === document.getElementById('userModal')) closeUserModalDirect(); }
function closeUserModalDirect() { document.getElementById('userModal').style.display = 'none'; }

async function saveUser() {
  const name = document.getElementById('u-name').value.trim();
  const email= document.getElementById('u-email').value.trim();
  const pass = document.getElementById('u-pass').value;
  if (!name || !email) { showToast('❌ Name and email required', 'error'); return; }
  if (pass.length < 8) { showToast('❌ Password must be at least 8 characters', 'error'); return; }

  try {
    const res = await api('admin/api/users.php', 'POST', {
      name, email, password: pass, role: document.getElementById('u-role').value
    });
    closeUserModalDirect();
    showToast('✅ User added! Reload to see updated list.');
    // Reload page to refresh server-rendered user table
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}


async function changeUserRoleDropdown(sel) {
  const id = sel.getAttribute('data-user-id');
  const newRole = sel.value;
  const row = document.getElementById('user-row-' + id);
  const badgeEl = row.querySelector('.badge');
  try {
    await api(`admin/api/users.php?id=${id}`, 'PUT', { role: newRole });
    const colors = { admin:'badge-success', researcher:'badge-info', student:'badge-neutral' };
    badgeEl.textContent = newRole.charAt(0).toUpperCase() + newRole.slice(1);
    badgeEl.className   = 'badge ' + colors[newRole];
    showToast(`Updated role to ${newRole}`);
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}

async function deleteUser(id) {
  if (!confirm('Remove this user from the system?')) return;
  try {
    await api(`admin/api/users.php?id=${id}`, 'DELETE');
    document.getElementById('user-row-' + id)?.remove();
    showToast('🗑 User removed');
  } catch (e) {
    showToast('❌ ' + e.message, 'error');
  }
}

// ============================================================================
// SYSTEM SETTINGS
// ============================================================================
function collectSettings(keys) {
  const out = {};
  keys.forEach(k => {
    const el = document.getElementById('cfg-' + k);
    if (!el) return;
    out[k] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
  });
  return out;
}

async function saveSyncSettings() {
  try {
    await api('admin/api/system.php', 'POST', { settings: collectSettings([
      'sync_interval_minutes','alert_cooldown_minutes','data_retention_days','auto_backup_enabled'
    ])});
    showToast('✅ Sync settings saved');
  } catch (e) { showToast('❌ ' + e.message, 'error'); }
}

async function saveNotificationSettings() {
  try {
    await api('admin/api/system.php', 'POST', { settings: collectSettings([
      'email_critical_alerts','email_warning_alerts','email_weekly_reports','admin_notify_email'
    ])});
    showToast('✅ Notification settings saved');
  } catch (e) { showToast('❌ ' + e.message, 'error'); }
}

async function saveAutomationSettings() {
  try {
    await api('admin/api/system.php', 'POST', { settings: collectSettings([
      'auto_cooling_fan','auto_ph_correction','auto_ec_dosing','auto_shading_net','auto_humidity_misting'
    ])});
    showToast('✅ Automation rules saved');
  } catch (e) { showToast('❌ ' + e.message, 'error'); }
}

async function runMaintenance(task) {
  const labels = {
    reset_calibration: 'Reset calibration flags?',
    clear_alerts:      'Clear resolved alerts older than 30 days?',
    backup:            'Log a manual backup entry?',
    reboot:            'Send reboot signal to hardware?'
  };
  if (!confirm(labels[task] || 'Run this maintenance task?')) return;
  try {
    const res = await api('admin/api/system.php?action=maintenance', 'POST', { task });
    showToast('✅ ' + res.message);
  } catch (e) { showToast('❌ ' + e.message, 'error'); }
}

// ============================================================================
// TOAST
// ============================================================================
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show toast-' + type;
  setTimeout(() => t.className = 'toast', 3500);
}

// ============================================================================
// PROFILE DROPDOWN & LOGOUT
// ============================================================================
function toggleProfileDropdown(event) {
  event.stopPropagation();
  document.getElementById('profileDropdown').classList.toggle('active');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.profile-icon') && !e.target.closest('.profile-dropdown'))
    document.getElementById('profileDropdown').classList.remove('active');
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

// ============================================================================
// INIT
// ============================================================================
document.addEventListener('DOMContentLoaded', () => {
  filterPlants();
  renderActiveProfiles();
  onSimulatorGreenhouseChange();
});
</script>
</body>
</html>
