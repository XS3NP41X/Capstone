<?php
// ============================================================================
// ECOTWIN - GREENHOUSES MONITORING PAGE
// PHP conversion with MySQL (ecotwin_db) data integration
// ============================================================================


session_start();
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/preferences.php';

// ── Auth guard ────────────────────────────────────────────────────────────────
// Uncomment when session-based login is wired up:
// if (empty($_SESSION['user_id'])) {
//     header('Location: login.php');
//     exit;
// }

// ── Load greenhouse overview from DB ─────────────────────────────────────────
$db = getDB();
$preferences = ecotwinLoadUserPreferences($db, (int)($_SESSION['user_id'] ?? 0));
$profileDetails = ecotwinLoadUserProfileDetails($db, (int)($_SESSION['user_id'] ?? 0));
$preferenceBodyClass = ecotwinPreferenceBodyClass($preferences);
$t = fn(string $key, array $replacements = []) => ecotwinT($preferences['language'], $key, $replacements);

// Both greenhouses with plant and alert summary
$stmt = $db->query("SELECT * FROM v_greenhouse_status ORDER BY code");
$greenhouses = $stmt->fetchAll();

// Active experiment info
$stmt = $db->query("SELECT * FROM v_active_experiment LIMIT 1");
$active_exp = $stmt->fetch();

// Build a keyed map: 'A' => [...], 'B' => [...]
$gh_map = [];
foreach ($greenhouses as $gh) {
    $gh_map[$gh['code']] = $gh;
}

// ── Load latest readings per greenhouse ───────────────────────────────────────
function loadReadings(PDO $db, int $gh_id): array {
    $stmt = $db->prepare("
        SELECT parameter, value, unit, quality, recorded_at, sensor_label, sensor_status
        FROM v_latest_readings
        WHERE greenhouse_id = ?
    ");
    $stmt->execute([$gh_id]);
    $rows = $stmt->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[$r['parameter']] = $r;
    }
    return $out;
}

// ── Load plant thresholds for a greenhouse ────────────────────────────────────
function loadThresholds(PDO $db, int $gh_id): array {
    $stmt = $db->prepare("
        SELECT pt.parameter, pt.val_min, pt.val_opt_low, pt.val_opt_high, pt.val_max, pt.unit
        FROM greenhouses g
        JOIN plants p ON g.assigned_plant_id = p.plant_id
        JOIN plant_thresholds pt ON p.plant_id = pt.plant_id
        WHERE g.greenhouse_id = ?
    ");
    $stmt->execute([$gh_id]);
    $rows = $stmt->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[$r['parameter']] = $r;
    }
    return $out;
}

// ── Load automation rules ─────────────────────────────────────────────────────
function loadRules(PDO $db, int $gh_id): array {
    $stmt = $db->prepare("
        SELECT r.rule_id, r.parameter, r.trigger_when, r.trigger_value, r.action,
               r.is_enabled, r.description, a.actuator_type, a.label AS actuator_label
        FROM automation_rules r
        JOIN actuators a ON r.actuator_id = a.actuator_id
        WHERE r.greenhouse_id = ?
        ORDER BY r.parameter, r.trigger_when
    ");
    $stmt->execute([$gh_id]);
    return $stmt->fetchAll();
}

// ── Load sensors ──────────────────────────────────────────────────────────────
function loadSensors(PDO $db, int $gh_id): array {
    $stmt = $db->prepare("
        SELECT sensor_id, sensor_type, label, parameter, status, last_seen_at
        FROM sensors WHERE greenhouse_id = ? ORDER BY sensor_id
    ");
    $stmt->execute([$gh_id]);
    return $stmt->fetchAll();
}

// ── Load actuators ────────────────────────────────────────────────────────────
function loadActuators(PDO $db, int $gh_id): array {
    $stmt = $db->prepare("
        SELECT actuator_id, actuator_type, label, status, last_changed_at
        FROM actuators WHERE greenhouse_id = ? ORDER BY actuator_id
    ");
    $stmt->execute([$gh_id]);
    return $stmt->fetchAll();
}

// ── Load open alerts ──────────────────────────────────────────────────────────
function loadAlerts(PDO $db, int $gh_id): array {
    $stmt = $db->prepare("
        SELECT alert_id, severity, category, message, sensor_value, created_at
        FROM alerts
        WHERE greenhouse_id = ? AND is_resolved = 0
        ORDER BY FIELD(severity,'critical','warning','info','success'), created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$gh_id]);
    return $stmt->fetchAll();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function evalStatus(float $value, array $threshold): string {
    if ($value >= $threshold['val_opt_low'] && $value <= $threshold['val_opt_high']) return 'optimal';
    if ($value < $threshold['val_min'] || $value > $threshold['val_max']) return 'critical';
    return 'caution';
}

function badgeFromActualReadings(array $readings, array $thresholds): string {
    $hasCritical = false;
    $hasCaution = false;

    foreach ($thresholds as $parameter => $threshold) {
        if (!isset($readings[$parameter]['value'])) continue;
        $status = evalStatus((float)$readings[$parameter]['value'], $threshold);
        if ($status === 'critical') $hasCritical = true;
        if ($status === 'caution') $hasCaution = true;
    }

    if ($hasCritical) return '<span class="badge badge-danger">Critical Alert</span>';
    if ($hasCaution)  return '<span class="badge badge-warning">Caution</span>';
    return '<span class="badge badge-success">All Systems Optimal</span>';
}

function statusBadge(string $status): string {
    return match($status) {
        'optimal'  => '<div class="param-status status-optimal">✓ Optimal</div>',
        'critical' => '<div class="param-status status-critical">✕ Out of Range</div>',
        'caution'  => '<div class="param-status status-caution">⚠ Caution</div>',
        default    => '<div class="param-status">— No Data</div>',
    };
}

function fmtValue(array $readings, string $param, string $default = '—'): string {
    return isset($readings[$param]) ? number_format((float)$readings[$param]['value'], 1) : $default;
}

function fmtUnit(array $readings, string $param): string {
    return $readings[$param]['unit'] ?? '';
}

function rulePairs(array $rules, string $param): array {
    $out = ['above' => null, 'below' => null];
    foreach ($rules as $r) {
        if ($r['parameter'] === $param) {
            $out[$r['trigger_when']] = $r;
        }
    }
    return $out;
}

function actuatorBadge(string $status): string {
    return match($status) {
        'on'    => '<span class="badge badge-success">ON</span>',
        'off'   => '<span class="badge badge-neutral">OFF</span>',
        'auto'  => '<span class="badge badge-info">AUTO</span>',
        'fault' => '<span class="badge badge-danger">FAULT</span>',
        default => '<span class="badge badge-neutral">' . strtoupper(htmlspecialchars($status)) . '</span>',
    };
}

function sensorStatusDot(string $status): string {
    $dot   = match($status) { 'online' => 'status-online', 'offline' => 'status-offline', default => 'status-warning' };
    $label = ucfirst(htmlspecialchars($status));
    return "<span class=\"status-dot {$dot}\"></span><span>{$label}</span>";
}

// ── Actuator type → icon map ──────────────────────────────────────────────────
function actuatorIcon(string $type): string {
    return match($type) {
        'nutrient_pump', 'ph_pump_up', 'ph_pump_down', 'water_refill_pump' => '💧',
        'exhaust_fan', 'circulation_fan' => '🌀',
        'shading_net'    => '☀️',
        'misting_system' => '💦',
        default          => '⚙️',
    };
}

function actuatorLabel(string $type): string {
    return match($type) {
        'nutrient_pump'     => 'Nutrient Pump',
        'exhaust_fan'       => 'Exhaust Fan',
        'circulation_fan'   => 'Circulation Fan',
        'shading_net'       => 'Shading Net',
        'misting_system'    => 'Misting System',
        'ph_pump_up'        => 'pH Up Pump',
        'ph_pump_down'      => 'pH Down Pump',
        'water_refill_pump' => 'Water Refill Pump',
        default             => ucfirst(str_replace('_', ' ', $type)),
    };
}

// ── Sensor type → description ─────────────────────────────────────────────────
function sensorDesc(string $type): string {
    return match($type) {
        'DHT22'       => 'Temperature & Humidity',
        'DS18B20'     => 'Water Temperature',
        'LDR'         => 'Light Intensity',
        'EC_TDS'      => 'Electrical Conductivity',
        'PH'          => 'pH Level',
        'WATER_LEVEL' => 'Reservoir Level',
        default       => $type,
    };
}

// ── Pre-load data for both greenhouses ────────────────────────────────────────
$data = [];
foreach (['A', 'B'] as $code) {
    if (!isset($gh_map[$code])) continue;
    $id = (int)$gh_map[$code]['greenhouse_id'];
    $data[$code] = [
        'info'      => $gh_map[$code],
        'readings'  => loadReadings($db, $id),
        'thresholds'=> loadThresholds($db, $id),
        'rules'     => loadRules($db, $id),
        'sensors'   => loadSensors($db, $id),
        'actuators' => loadActuators($db, $id),
        'alerts'    => loadAlerts($db, $id),
    ];
}

// ── Logged-in user (demo fallback) ────────────────────────────────────────────
$current_user = [
    'name'  => $_SESSION['user_name']  ?? 'Dr. Jane Smith',
    'email' => $_SESSION['user_email'] ?? 'jane.smith@spamast.edu',
    'role'  => $_SESSION['user_role']  ?? 'Researcher',
    'initials' => isset($_SESSION['user_name'])
        ? strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(explode(' ', trim($_SESSION['user_name'])), 0, 2))) )
        : 'JS',
];

$userRole = strtolower($_SESSION['user_role'] ?? 'researcher');

// ── Helper: render one greenhouse panel ───────────────────────────────────────
function renderGreenhousePanel(string $code, array $d, array $exp): string {
    $info       = $d['info'];
    $readings   = $d['readings'];
    $thresholds = $d['thresholds'];
    $rules      = $d['rules'];
    $sensors    = $d['sensors'];
    $actuators  = $d['actuators'];
    $alerts     = $d['alerts'];

    $role_label   = $code === 'A' ? 'Treatment Group' : 'Control Group';
    $exp_subtitle = $exp
        ? htmlspecialchars($exp['title'] ?? '') . ' • ' . htmlspecialchars($exp['exp_code'] ?? '')
        : 'No active experiment';

    // Determine overall status badge
    $has_critical = false;
    $has_caution  = false;
    foreach ($alerts as $alert) {
        if ($alert['severity'] === 'critical') $has_critical = true;
        if ($alert['severity'] === 'warning')  $has_caution  = true;
    }

    $overall_badge = $has_critical
        ? '<span class="badge badge-danger">Critical Alert</span>'
        : ($has_caution
            ? '<span class="badge badge-warning">Caution</span>'
            : '<span class="badge badge-success">All Systems Optimal</span>');

    // Parameter definitions
    $params = [
        ['param' => 'temperature', 'icon' => '🌡️', 'label' => 'Air Temperature', 'decimals' => 1],
        ['param' => 'humidity',    'icon' => '💧',  'label' => 'Humidity',         'decimals' => 0],
        ['param' => 'light',       'icon' => '☀️',  'label' => 'Light Intensity',  'decimals' => 0, 'format' => 'number'],
        ['param' => 'ec',          'icon' => '🧪',  'label' => 'EC / TDS',         'decimals' => 1],
        ['param' => 'ph',          'icon' => '🧪',  'label' => 'pH Level',         'decimals' => 1],
        ['param' => 'water_level', 'icon' => '🌊',  'label' => 'Water Level',      'decimals' => 0],
    ];

    // ── Build parameter tiles HTML ────────────────────────────────────────────
    $tiles_html = '';
    foreach ($params as $p) {
        $reading = $readings[$p['param']] ?? null;
        $thresh  = $thresholds[$p['param']] ?? null;
        $value   = $reading ? (float)$reading['value'] : null;
        $unit    = $reading ? $reading['unit'] : '';
        $status  = ($value !== null && $thresh) ? evalStatus($value, $thresh) : 'unknown';

        $display_value = $value !== null
            ? (isset($p['format']) && $p['format'] === 'number'
                ? number_format($value, 0)
                : number_format($value, $p['decimals']))
            : '—';

        $tile_class = $status === 'critical' ? 'parameter-tile critical' : 'parameter-tile';
        $val_class  = $status === 'critical' ? 'param-value critical-value' : 'param-value';
        $range_text = ($thresh && $value !== null)
            ? 'Range: ' . $thresh['val_opt_low'] . '–' . $thresh['val_opt_high'] . ' ' . $thresh['unit']
            : 'No threshold configured';

        // Custom status badge for 'unknown' status
        $status_html = $status === 'unknown'
            ? '<div class="param-status status-unknown">❔ Unknown</div>'
            : statusBadge($status);

        $tiles_html .= <<<HTML
        <div class="{$tile_class}">
            <div class="param-header">
                <div class="param-icon teal">{$p['icon']}</div>
                <div class="param-label">{$p['label']}</div>
            </div>
            <div class="{$val_class}">{$display_value}<span class="param-unit">{$unit}</span></div>
            {$status_html}
            <div class="param-range">{$range_text}</div>
        </div>
        HTML;
    }

    // ── Build automation rules form ────────────────────────────────────────────
    $threshold_groups = [
        ['param' => 'temperature', 'icon' => '🌡️', 'label' => 'Temperature Control (°C)',
         'above_label' => 'Activate Fan When Above:', 'below_label' => 'Stop Fan When Below:',
         'above_default' => 40, 'below_default' => 35, 'step' => '0.1', 'unit' => '°C'],
        ['param' => 'humidity',    'icon' => '💧',  'label' => 'Humidity Control (%)',
         'above_label' => 'Activate Ventilation Above:', 'below_label' => 'Stop Ventilation Below:',
         'above_default' => 80, 'below_default' => 70, 'step' => '1', 'unit' => '%'],
        ['param' => 'light',       'icon' => '☀️',  'label' => 'Light Intensity Control (lux)',
         'above_label' => 'Activate Shading Above:', 'below_label' => 'Retract Shading Below:',
         'above_default' => 20000, 'below_default' => 15000, 'step' => '100', 'unit' => 'lux'],
        ['param' => 'ec',          'icon' => '🧪',  'label' => 'EC/TDS Control (mS/cm)',
         'above_label' => 'Add Water When Above:', 'below_label' => 'Add Nutrients When Below:',
         'above_default' => 2.5, 'below_default' => 1.2, 'step' => '0.1', 'unit' => 'mS/cm'],
        ['param' => 'ph',          'icon' => '🧪',  'label' => 'pH Level Control',
         'above_label' => 'Add pH Down When Above:', 'below_label' => 'Add pH Up When Below:',
         'above_default' => 7.0, 'below_default' => 5.0, 'step' => '0.1', 'unit' => 'pH'],
        ['param' => 'water_level', 'icon' => '🌊',  'label' => 'Water Level Control (%)',
         'above_label' => 'Stop Refill When Above:', 'below_label' => 'Activate Refill When Below:',
         'above_default' => 90, 'below_default' => 60, 'step' => '1', 'unit' => '%'],
    ];

    $rules_html = '';
    foreach ($threshold_groups as $tg) {
        $pairs = rulePairs($rules, $tg['param']);
        $above = $pairs['above'];
        $below = $pairs['below'];

        $above_id    = $above['rule_id'] ?? 0;
        $below_id    = $below['rule_id'] ?? 0;
        $above_val   = $above ? number_format((float)$above['trigger_value'], strlen(substr(strrchr((string)$tg['step'], '.'), 1))) : $tg['above_default'];
        $below_val   = $below ? number_format((float)$below['trigger_value'], strlen(substr(strrchr((string)$tg['step'], '.'), 1))) : $tg['below_default'];

        $lc_code = strtolower($code);
        $param   = htmlspecialchars($tg['param']);
        $step    = $tg['step'];
        $unit    = htmlspecialchars($tg['unit']);

        $rules_html .= <<<HTML
        <div class="threshold-group">
            <div class="threshold-header">
                <div class="param-icon teal">{$tg['icon']}</div>
                <strong>{$tg['label']}</strong>
            </div>
            <div class="threshold-inputs">
                <div class="input-group">
                    <label for="rule-{$lc_code}-{$param}-above">{$tg['above_label']}</label>
                    <input type="number" id="rule-{$lc_code}-{$param}-above"
                           name="rule[{$above_id}][trigger_value]"
                           data-rule-id="{$above_id}" data-direction="above" data-param="{$param}"
                           value="{$above_val}" step="{$step}" class="rule-input gh-{$lc_code}-rule" />
                    <span class="input-unit">{$unit}</span>
                </div>
                <div class="input-group">
                    <label for="rule-{$lc_code}-{$param}-below">{$tg['below_label']}</label>
                    <input type="number" id="rule-{$lc_code}-{$param}-below"
                           name="rule[{$below_id}][trigger_value]"
                           data-rule-id="{$below_id}" data-direction="below" data-param="{$param}"
                           value="{$below_val}" step="{$step}" class="rule-input gh-{$lc_code}-rule" />
                    <span class="input-unit">{$unit}</span>
                </div>
            </div>
        </div>
        HTML;
    }

    // ── Actuator rows ─────────────────────────────────────────────────────────
    $actuators_html = '';
    foreach ($actuators as $act) {
        $icon  = actuatorIcon($act['actuator_type']);
        $lbl   = htmlspecialchars($act['label']);
        $desc  = htmlspecialchars(actuatorLabel($act['actuator_type']));
        $badge = actuatorBadge($act['status']);
        $actuators_html .= <<<HTML
        <div class="actuator-item">
            <div class="actuator-info">
                <div class="actuator-icon">{$icon}</div>
                <div>
                    <div class="actuator-name">{$lbl}</div>
                    <div class="actuator-desc">{$desc}</div>
                </div>
            </div>
            {$badge}
        </div>
        HTML;
    }

    // ── Sensor rows ───────────────────────────────────────────────────────────
    $sensors_html = '';
    foreach ($sensors as $sen) {
        $offline_cls = $sen['status'] === 'offline' ? ' offline' : '';
        $lbl         = htmlspecialchars($sen['label']);
        $desc        = htmlspecialchars(sensorDesc($sen['sensor_type']));
        $status_html = sensorStatusDot($sen['status']);
        $sensors_html .= <<<HTML
        <div class="sensor-item online{$offline_cls}">
            <div class="sensor-info">
                <div class="sensor-name">{$lbl}</div>
                <div class="sensor-desc">{$desc}</div>
            </div>
            <div class="sensor-status">{$status_html}</div>
        </div>
        HTML;
    }

    // ── Alerts block ──────────────────────────────────────────────────────────
    $alerts_html = '';
    foreach ($alerts as $alert) {
        $icon = match($alert['severity']) {
            'critical' => '🔴', 'warning' => '⚠️', 'success' => '✅', default => 'ℹ️'
        };
        $msg  = htmlspecialchars($alert['message']);
        $ts   = htmlspecialchars($alert['created_at']);
        $alerts_html .= <<<HTML
        <div class="alert alert-{$alert['severity']} mb-2" style="margin-bottom:8px;">
            <span class="alert-icon">{$icon}</span>
            <div><strong>{$ts}:</strong> {$msg}</div>
        </div>
        HTML;
    }

    $alerts_section = $alerts_html
        ? "<div class=\"gh-alerts mb-3\">{$alerts_html}</div>"
        : '';

    $lc_code = strtolower($code);

    return <<<HTML
    <div id="greenhouse-{$lc_code}" class="greenhouse-content">
        <!-- Status Banner -->
        <div class="greenhouse-banner gh-{$lc_code} mb-3">
            <div class="banner-info">
                <div class="banner-icon">🏠</div>
                <div>
                    <div class="banner-title">Greenhouse {$code} – {$role_label}</div>
                    <div class="banner-subtitle">{$exp_subtitle}</div>
                </div>
            </div>
            <div class="banner-status">{$overall_badge}</div>
        </div>

        {$alerts_section}

        <div class="greenhouse-grid">
            <!-- LEFT: Environmental Parameters + Thresholds + Actuators -->
            <div>
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">Environmental Parameters</h3>
                        <span class="update-time" id="last-update-{$lc_code}">Live from database</span>
                    </div>
                    <div class="parameters-grid" id="params-grid-{$lc_code}">
                        {$tiles_html}
                    </div>
                </div>

                <!-- Automation Threshold Settings -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">Automation Threshold Settings</h3>
                        <span class="update-time">Configure automatic actuator triggers</span>
                    </div>
                    <div class="threshold-form">
                        {$rules_html}
                        <div class="threshold-actions">
                            <button type="button" class="btn btn-primary"
                                    onclick="saveRules('{$code}')">
                                Save Threshold Settings
                            </button>
                            <button type="button" class="btn btn-secondary"
                                    onclick="reloadRules('{$code}')">
                                Reset to DB Values
                            </button>
                        </div>
                        <div id="save-msg-{$lc_code}" class="save-message" style="display:none;"></div>
                    </div>
                </div>

                <!-- Actuator Controls -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Actuator Status &amp; Controls</h3>
                    </div>
                    <div class="actuator-list" id="actuators-{$lc_code}">
                        {$actuators_html}
                    </div>
                    <div class="control-panel">
                        <div class="control-notice">
                            <div class="notice-icon">🔒</div>
                            <div>
                                <strong>Manual Control:</strong> Controls are locked during
                                active experiments to maintain experimental integrity.
                                Automatic control is managing all actuators.
                            </div>
                        </div>
                        <div class="control-buttons">
                            <button class="btn btn-secondary" disabled>Toggle Pump</button>
                            <button class="btn btn-secondary" disabled>Toggle Fan</button>
                            <button class="btn btn-secondary" disabled>Toggle Shading</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Chart + Sensors -->
            <div>
                <!-- Environmental Trends Chart -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">Environmental Trends</h3>
                        <div class="time-selector">
                            <button class="time-btn" onclick="loadTrend('{$code}',1)">1H</button>
                            <button class="time-btn" onclick="loadTrend('{$code}',6)">6H</button>
                            <button class="time-btn active" onclick="loadTrend('{$code}',24)">24H</button>
                            <button class="time-btn" onclick="loadTrend('{$code}',168)">7D</button>
                        </div>
                    </div>
                    <div class="chart-legend">
                        <div class="legend-item"><div class="legend-dot red"></div><span>Temperature (°C)</span></div>
                        <div class="legend-item"><div class="legend-dot blue"></div><span>Humidity (%)</span></div>
                        <div class="legend-item"><div class="legend-dot purple"></div><span>EC (mS/cm)</span></div>
                    </div>
                    <div class="chart-container">
                        <canvas id="chart-{$lc_code}" width="100%" height="280"></canvas>
                        <div class="chart-placeholder" id="chart-placeholder-{$lc_code}">
                            <div class="placeholder-icon">📊</div>
                            <div class="placeholder-text">Loading trend data…</div>
                            <div class="placeholder-subtext">Fetching from ecotwin_db</div>
                        </div>
                    </div>
                </div>

                <!-- Sensor Hardware Status -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Sensor Hardware Status</h3>
                        <button class="btn btn-secondary btn-sm"
                                onclick="refreshSensors('{$code}')">↻ Refresh</button>
                    </div>
                    <div class="sensor-list" id="sensors-{$lc_code}">
                        {$sensors_html}
                    </div>
                </div>
            </div>
        </div>
    </div>
    HTML;
}

// Closure workaround for status badge
$statusBadge_fn = [
    'optimal'  => '<div class="param-status status-optimal">✓ Optimal</div>',
    'critical' => '<div class="param-status status-critical">✕ Out of Range</div>',
    'caution'  => '<div class="param-status status-caution">⚠ Caution</div>',
    'unknown'  => '<div class="param-status">— No Data</div>',
];

// Render panels (pass by reference to workaround closure limitation)
// We inline statusBadge directly in renderGreenhousePanel body below by string substitution.
// Since PHP closures can't be called as plain functions referenced in heredoc,
// we use a simple function approach.

$panel_a_html = '';
$panel_b_html = '';

if (isset($data['A'])) {
    $panel_a_html = renderGreenhousePanel('A', $data['A'], $active_exp ?: []);
}
if (isset($data['B'])) {
    $panel_b_html = renderGreenhousePanel('B', $data['B'], $active_exp ?: []);
    $panel_b_badge = badgeFromActualReadings($data['B']['readings'] ?? [], $data['B']['thresholds'] ?? []);
    $panel_b_html = preg_replace('/<div class="banner-status">.*?<\/div>/s', '<div class="banner-status">' . $panel_b_badge . '</div>', $panel_b_html, 1);
    $panel_b_html = preg_replace('/<div class="gh-alerts mb-3">.*?<\/div>\s*(<div class="greenhouse-grid">)/s', '$1', $panel_b_html, 1);
}

// Active tab
$active_tab = $_GET['tab'] ?? 'a';
$tab_a_class = $active_tab === 'a' ? 'tab-button active' : 'tab-button';
$tab_b_class = $active_tab === 'b' ? 'tab-button active' : 'tab-button';

$panel_a_active = $active_tab === 'a' ? 'greenhouse-content active' : 'greenhouse-content';
$panel_b_active = $active_tab === 'b' ? 'greenhouse-content active' : 'greenhouse-content';

// Inline the class into rendered HTML (simple string replace of the outer div)
$panel_a_html = preg_replace('/<div id="greenhouse-a" class="greenhouse-content">/', "<div id=\"greenhouse-a\" class=\"{$panel_a_active}\">", $panel_a_html, 1);
$panel_b_html = preg_replace('/<div id="greenhouse-b" class="greenhouse-content">/', "<div id=\"greenhouse-b\" class=\"{$panel_b_active}\">", $panel_b_html, 1);

// Encode rules for JS
$rules_a_json = json_encode(array_values($data['A']['rules'] ?? []), JSON_HEX_TAG);
$rules_b_json = json_encode(array_values($data['B']['rules'] ?? []), JSON_HEX_TAG);

?>
<!doctype html>
<html lang="<?= htmlspecialchars($preferences['language']) ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($t('page.greenhouses.title')) ?> – EcoTwin</title>
    <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>" />
    <link rel="stylesheet" href="css.greenhouses.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.greenhouses.css')) ?>" />
    <style>
        /* Extra styles for PHP-specific UI elements */
        .save-message {
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .save-message.success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; }
        .save-message.error   { background:#FEE2E2; color:#991B1B; border:1px solid #FECACA; }
        .refresh-btn-sm { padding:6px 12px; font-size:12px; }
        .btn-sm { padding:6px 14px; font-size:13px; }
        canvas { display:none; }
        .chart-placeholder { min-height:280px; }
        .alert-critical { background:#FEE2E2; border-left:4px solid #EF4444; color:#991B1B; }
        .alert-warning  { background:#FEF3C7; border-left:4px solid #F59E0B; color:#92400E; }
        .alert-info     { background:#DBEAFE; border-left:4px solid #3B82F6; color:#1E40AF; }
        .alert-success  { background:#D1FAE5; border-left:4px solid #10B981; color:#065F46; }
        .db-live-badge  {
            display:inline-flex; align-items:center; gap:6px;
            background:#E0F2F1; color:#0F766E;
            font-size:11px; font-weight:600;
            padding:3px 10px; border-radius:12px;
            border:1px solid #99F6E4;
        }
        .db-live-badge::before {
            content:''; width:7px; height:7px;
            background:#0D9488; border-radius:50%;
            animation:pulse 2s infinite;
        }
    </style>
</head>
<body class="<?= htmlspecialchars($preferenceBodyClass) ?>"
      data-language="<?= htmlspecialchars($preferences['language']) ?>"
      data-timezone="<?= htmlspecialchars($preferences['timezone']) ?>"
      data-date-format="<?= htmlspecialchars($preferences['date_format']) ?>">

<!-- Navigation Bar -->
<nav class="navbar">
    <div class="navbar-container">
        <a href="dashboard.php" class="navbar-logo">
            <img src="ECOTwin_Logo.png" alt="EcoTwin logo" class="logo-icon" />
            <span class="logo-text">EcoTwin</span>
        </a>
        <div class="navbar-menu" id="navbarMenu">
            <a href="dashboard.php"   class="nav-item"><?= htmlspecialchars($t('nav.dashboard')) ?></a>
            <a href="experiments.php" class="nav-item"><?= htmlspecialchars($t('nav.experiments')) ?></a>
            <a href="greenhouses.php" class="nav-item active"><?= htmlspecialchars($t('nav.greenhouses')) ?></a>
            <a href="reports.php"     class="nav-item"><?= htmlspecialchars($t('nav.reports')) ?></a>
            <a href="settings.php"    class="nav-item"><?= htmlspecialchars($t('nav.settings')) ?></a>
            <?php if ($userRole === 'admin'): ?>
            <a href="admin.php"       class="nav-item"><?= htmlspecialchars($t('nav.admin')) ?></a>
            <?php endif; ?>
        </div>
        <div class="navbar-user">
            <span class="db-live-badge">Live DB</span>
            <div class="profile-icon <?= !empty($profileDetails['avatar_url']) ? 'has-avatar' : '' ?>" onclick="toggleProfileDropdown(event)">
                <?php if (!empty($profileDetails['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($profileDetails['avatar_url']) ?>" alt="Profile avatar" />
                <?php else: ?>
                <?= htmlspecialchars($current_user['initials']) ?>
                <?php endif; ?>
            </div>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-dropdown-header">
                    <div class="profile-user-info">
                        <div class="profile-user-name"><?= htmlspecialchars($current_user['name']) ?></div>
                        <div class="profile-user-email"><?= htmlspecialchars($current_user['email']) ?></div>
                        <div class="profile-user-role"><?= htmlspecialchars($current_user['role']) ?></div>
                    </div>
                </div>
                <div class="profile-dropdown-body">
                    <a href="settings.php#profileSection" class="profile-menu-item"><?= htmlspecialchars($t('menu.profile_settings')) ?></a>
                    <a href="settings.php#preferencesSettings" class="profile-menu-item"><?= htmlspecialchars($t('menu.preferences')) ?></a>
                </div>
                <div class="profile-dropdown-footer">
                    <button class="logout-btn" onclick="logout()"><?= htmlspecialchars($t('menu.logout')) ?></button>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Main Content -->
<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><?= htmlspecialchars($t('page.greenhouses.title')) ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($t('page.greenhouses.subtitle')) ?></p>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation mb-3">
        <a href="?tab=a" class="<?= $tab_a_class ?>" data-tab="a"
           onclick="switchTab(event,'a')">Greenhouse A</a>
        <a href="?tab=b" class="<?= $tab_b_class ?>" data-tab="b"
           onclick="switchTab(event,'b')">Greenhouse B</a>
    </div>

    <!-- Greenhouse Panels (PHP-rendered from DB) -->
    <?= $panel_a_html ?>
    <?= $panel_b_html ?>
</main>

<!-- ============================================================ -->
<!-- Toast -->
<!-- ============================================================ -->
<div class="toast" id="toast"></div>

<!-- ============================================================ -->
<!-- Scripts -->
<!-- ============================================================ -->
<script>
// ── Initial rule data from PHP ─────────────────────────────────────────────
const dbRules = {
    A: <?= $rules_a_json ?>,
    B: <?= $rules_b_json ?>
};

// ── Tab switching (client-side, no reload) ─────────────────────────────────
function switchTab(e, code) {
    e.preventDefault();
    document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.greenhouse-content').forEach(p => p.classList.remove('active'));
    document.querySelector(`[data-tab="${code}"]`).classList.add('active');
    document.getElementById(`greenhouse-${code}`).classList.add('active');
    history.replaceState(null, '', `?tab=${code}`);
    // Load trend on first show
    if (!window._trendLoaded) window._trendLoaded = {};
    if (!window._trendLoaded[code]) {
        loadTrend(code.toUpperCase(), 24);
        window._trendLoaded[code] = true;
    }
}

// ── Profile dropdown ───────────────────────────────────────────────────────
function toggleProfileDropdown(event) {
    event.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('active');
}
function logout() { window.location.href = 'login.php'; }
document.addEventListener('click', function(e) {
    if (!e.target.closest('.profile-icon') && !e.target.closest('.profile-dropdown')) {
        document.getElementById('profileDropdown').classList.remove('active');
    }
});

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show toast-' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

// ── Save automation rules via AJAX ─────────────────────────────────────────
async function saveRules(ghCode) {
    const inputs = document.querySelectorAll(`.gh-${ghCode.toLowerCase()}-rule`);
    const rules  = [];

    inputs.forEach(input => {
        const ruleId = parseInt(input.dataset.ruleId);
        if (ruleId > 0) {
            rules.push({
                rule_id:       ruleId,
                trigger_value: parseFloat(input.value),
                is_enabled:    1,
            });
        }
    });

    const msgEl = document.getElementById(`save-msg-${ghCode.toLowerCase()}`);
    msgEl.style.display = 'none';

    try {
        const res  = await fetch('greenhouses/greenhouses_api.php?action=save_rules', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ gh_code: ghCode, rules }),
        });
        const data = await res.json();

        if (data.success) {
            msgEl.textContent    = '✅ ' + data.message;
            msgEl.className      = 'save-message success';
            msgEl.style.display  = 'block';
            showToast(`✅ Greenhouse ${ghCode} thresholds saved!`);
        } else {
            msgEl.textContent    = '❌ ' + (data.message || 'Save failed');
            msgEl.className      = 'save-message error';
            msgEl.style.display  = 'block';
        }
    } catch (err) {
        msgEl.textContent    = '❌ Network error: ' + err.message;
        msgEl.className      = 'save-message error';
        msgEl.style.display  = 'block';
    }

    setTimeout(() => { msgEl.style.display = 'none'; }, 4000);
}

// ── Reload rules from DB (reset form) ─────────────────────────────────────
async function reloadRules(ghCode) {
    try {
        const res   = await fetch(`greenhouses/greenhouses_api.php?action=get_rules&gh=${ghCode}`);
        const rules = await res.json();
        rules.forEach(r => {
            const inputs = document.querySelectorAll(
                `.gh-${ghCode.toLowerCase()}-rule[data-rule-id="${r.rule_id}"]`
            );
            inputs.forEach(inp => { inp.value = r.trigger_value; });
        });
        showToast(`↻ Greenhouse ${ghCode} rules reloaded from database`);
    } catch (err) {
        showToast('❌ Failed to reload rules', 'error');
    }
}

// ── Refresh sensor statuses ────────────────────────────────────────────────
async function refreshSensors(ghCode) {
    try {
        const res     = await fetch(`greenhouses/greenhouses_api.php?action=get_sensors&gh=${ghCode}`);
        const sensors = await res.json();
        const el      = document.getElementById(`sensors-${ghCode.toLowerCase()}`);
        if (!el) return;

        const sensorDescs = {
            DHT22: 'Temperature & Humidity', DS18B20: 'Water Temperature',
            LDR: 'Light Intensity', EC_TDS: 'Electrical Conductivity',
            PH: 'pH Level', WATER_LEVEL: 'Reservoir Level',
        };

        el.innerHTML = sensors.map(s => {
            const offlineClass = s.status === 'offline' ? ' offline' : '';
            const dotClass     = s.status === 'online'  ? 'status-online' :
                                 s.status === 'offline' ? 'status-offline' : 'status-warning';
            return `
            <div class="sensor-item online${offlineClass}">
                <div class="sensor-info">
                    <div class="sensor-name">${escHtml(s.label)}</div>
                    <div class="sensor-desc">${sensorDescs[s.sensor_type] ?? s.sensor_type}</div>
                </div>
                <div class="sensor-status">
                    <span class="status-dot ${dotClass}"></span>
                    <span>${ucFirst(s.status)}</span>
                </div>
            </div>`;
        }).join('');

        showToast(`↻ Greenhouse ${ghCode} sensor status refreshed`);
    } catch (err) {
        showToast('❌ Failed to refresh sensors', 'error');
    }
}

// ── Live readings refresh (polls API) ─────────────────────────────────────
async function refreshReadings(ghCode) {
    try {
        const res  = await fetch(`greenhouses/greenhouses_api.php?action=get_readings&gh=${ghCode}`);
        const data = await res.json();
        if (data.error) return;

        const paramMap = {
            temperature: { icon: '🌡️', label: 'Air Temperature', decimals: 1 },
            humidity:    { icon: '💧',  label: 'Humidity',         decimals: 0 },
            light:       { icon: '☀️',  label: 'Light Intensity',  decimals: 0, format: 'number' },
            ec:          { icon: '🧪',  label: 'EC / TDS',         decimals: 1 },
            ph:          { icon: '🧪',  label: 'pH Level',         decimals: 1 },
            water_level: { icon: '🌊',  label: 'Water Level',      decimals: 0 },
        };

        const grid = document.getElementById(`params-grid-${ghCode.toLowerCase()}`);
        if (!grid) return;

        let html = '';
        for (const [param, cfg] of Object.entries(paramMap)) {
            const r = data.readings[param];
            const value = r ? (cfg.format === 'number'
                ? parseInt(r.value).toLocaleString()
                : parseFloat(r.value).toFixed(cfg.decimals))
                : '—';
            const unit   = r ? r.unit : '';
            const status = r ? r.status : 'unknown';
            const tileClass = status === 'critical' ? 'parameter-tile critical' : 'parameter-tile';
            const valClass  = status === 'critical' ? 'param-value critical-value' : 'param-value';
            const statusHtml = { optimal:'<div class="param-status status-optimal">✓ Optimal</div>',
                                 critical:'<div class="param-status status-critical">✕ Out of Range</div>',
                                 caution:'<div class="param-status status-caution">⚠ Caution</div>' }[status]
                              ?? '<div class="param-status">— No Data</div>';
            const range  = r?.range_label ? `Range: ${r.range_label}` : 'No threshold configured';

            html += `
            <div class="${tileClass}">
                <div class="param-header">
                    <div class="param-icon teal">${cfg.icon}</div>
                    <div class="param-label">${cfg.label}</div>
                </div>
                <div class="${valClass}">${value}<span class="param-unit">${unit}</span></div>
                ${statusHtml}
                <div class="param-range">${range}</div>
            </div>`;
        }
        grid.innerHTML = html;

        const upd = document.getElementById(`last-update-${ghCode.toLowerCase()}`);
        if (upd) upd.textContent = 'Updated ' + new Date().toLocaleTimeString();
    } catch (e) { /* silent fail on auto-refresh */ }
}

// ── Trend chart (uses Chart.js CDN) ───────────────────────────────────────
const _charts = {};
async function loadTrend(ghCode, hours) {
    // Update active time button
    const lc = ghCode.toLowerCase();
    document.querySelectorAll(`#greenhouse-${lc} .time-btn`).forEach(b => b.classList.remove('active'));
    event?.target?.classList.add('active');

    const placeholder = document.getElementById(`chart-placeholder-${lc}`);
    const canvas      = document.getElementById(`chart-${lc}`);

    if (!window.Chart) {
        if (placeholder) placeholder.querySelector('.placeholder-text').textContent = 'Chart.js not loaded';
        return;
    }

    try {
        const res  = await fetch(`greenhouses/greenhouses_api.php?action=get_trend&gh=${ghCode}&hours=${hours}`);
        const data = await res.json();

        if (!data.series || Object.keys(data.series).length === 0) {
            if (placeholder) {
                placeholder.querySelector('.placeholder-text').textContent = 'No trend data available';
                placeholder.querySelector('.placeholder-subtext').textContent = 'Add sensor readings to ecotwin_db to see charts';
            }
            return;
        }

        // Hide placeholder, show canvas
        if (placeholder) placeholder.style.display = 'none';
        if (canvas)      canvas.style.display       = 'block';

        // Destroy previous chart instance
        if (_charts[ghCode]) _charts[ghCode].destroy();

        const colours = { temperature:'#EF4444', humidity:'#3B82F6', ec:'#8B5CF6', ph:'#10B981', light:'#F59E0B', water_level:'#06B6D4' };
        const datasets = Object.entries(data.series).map(([param, points]) => ({
            label:           param.replace('_', ' '),
            data:            points.map(p => ({ x: p.ts, y: p.value })),
            borderColor:     colours[param] ?? '#9CA3AF',
            backgroundColor: (colours[param] ?? '#9CA3AF') + '22',
            borderWidth:     2,
            pointRadius:     2,
            tension:         0.4,
            fill:            false,
        }));

        _charts[ghCode] = new window.Chart(canvas, {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { type: 'category', ticks: { maxTicksLimit: 8, font:{ size:11 } } },
                    y: { ticks: { font:{ size:11 } } },
                },
                plugins: { legend: { labels: { font:{ size:12 } } } },
            }
        });
    } catch (err) {
        if (placeholder) placeholder.querySelector('.placeholder-text').textContent = 'Failed to load trend data';
    }
}

// ── Utility ────────────────────────────────────────────────────────────────
function escHtml(str) {
    return str?.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') ?? '';
}
function ucFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

// ── Auto-refresh readings every 60 seconds ─────────────────────────────────
setInterval(() => {
    const activeTab = document.querySelector('.greenhouse-content.active');
    if (activeTab) {
        const code = activeTab.id === 'greenhouse-a' ? 'A' : 'B';
        refreshReadings(code);
    }
}, 60000);

// ── On load: trigger initial trend load for visible tab ───────────────────
window.addEventListener('DOMContentLoaded', () => {
    const activeTab = document.querySelector('.greenhouse-content.active');
    if (activeTab) {
        const code = activeTab.id === 'greenhouse-a' ? 'A' : 'B';
        window._trendLoaded = { [code.toLowerCase()]: true };
        loadTrend(code, 24);
    }
});
</script>

<!-- Chart.js for trend visualization -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>

</body>
</html>
