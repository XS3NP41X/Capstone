<?php
// ============================================================================
// ECOTWIN — EXPERIMENTS
// ============================================================================

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/preferences.php';

// ── Auth guard ────────────────────────────────────────────────────────────────
$pdo      = db();
$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'researcher';
$preferences = ecotwinLoadUserPreferences($pdo, $userId);
$profileDetails = ecotwinLoadUserProfileDetails($pdo, $userId);
$preferenceBodyClass = ecotwinPreferenceBodyClass($preferences);
$t = fn(string $key, array $replacements = []) => ecotwinT($preferences['language'], $key, $replacements);

// ============================================================================
// HANDLE FORM ACTIONS (POST)
// ============================================================================

$flashMsg  = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flashMsg  = 'Security token mismatch. Please refresh and try again.';
        $flashType = 'error';

    } else {
        $action = $_POST['form_action'] ?? '';

        // ── Create new experiment ─────────────────────────────────────────────
        if ($action === 'create_experiment' && in_array($userRole, ['admin', 'researcher'])) {

            $title       = trim($_POST['title']       ?? '');
            $objective   = trim($_POST['objective']   ?? '');
            $expDays     = (int)($_POST['exp_days']   ?? 0);
            $lightSetupA = trim($_POST['light_setup_a'] ?? '');
            $lightSetupB = trim($_POST['light_setup_b'] ?? '');
            $plantIdA    = (int)($_POST['plant_id_a'] ?? 0);
            $plantIdB    = (int)($_POST['plant_id_b'] ?? 0);

            if ($title === '') {
                $flashMsg  = 'Experiment title is required.';
                $flashType = 'error';
            } elseif ($expDays < 1) {
                $flashMsg  = 'Expected duration must be at least 1 day.';
                $flashType = 'error';
            } else {
                // Check no active experiment already running
                $activeCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM experiments WHERE status = 'active'"
                )->fetchColumn();

                if ($activeCount > 0) {
                    $flashMsg  = 'Cannot create a new experiment while one is already active.';
                    $flashType = 'error';
                } else {
                    try {
                        $pdo->beginTransaction();

                        // Generate exp_code
                        $year    = date('Y');
                        $lastNum = (int)$pdo->query(
                            "SELECT COUNT(*) FROM experiments WHERE exp_code LIKE 'Exp-{$year}-%'"
                        )->fetchColumn();
                        $expCode = 'Exp-' . $year . '-' . str_pad($lastNum + 1, 3, '0', STR_PAD_LEFT);

                        $expectedEnd = date('Y-m-d H:i:s', strtotime("+{$expDays} days"));

                        $stmt = $pdo->prepare(
                            "INSERT INTO experiments
                               (exp_code, title, objective, principal_user_id, status, started_at, expected_end_at)
                             VALUES (?, ?, ?, ?, 'active', NOW(), ?)"
                        );
                        $stmt->execute([$expCode, $title, $objective, $userId, $expectedEnd]);
                        $expId = (int)$pdo->lastInsertId();

                        // Assign greenhouses
                        $ghStmt = $pdo->prepare(
                            "INSERT INTO experiment_greenhouses
                               (experiment_id, greenhouse_id, role, plant_id, light_setup)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $ghStmt->execute([$expId, 1, 'treatment', $plantIdA ?: null, $lightSetupA]);
                        $ghStmt->execute([$expId, 2, 'control',   $plantIdB ?: null, $lightSetupB]);

                        // Update greenhouse plant assignments
                        if ($plantIdA) {
                            $pdo->prepare("UPDATE greenhouses SET assigned_plant_id = ? WHERE greenhouse_id = 1")
                                ->execute([$plantIdA]);
                        }
                        if ($plantIdB) {
                            $pdo->prepare("UPDATE greenhouses SET assigned_plant_id = ? WHERE greenhouse_id = 2")
                                ->execute([$plantIdB]);
                        }

                        $pdo->commit();
                        $flashMsg  = "✅ Experiment {$expCode} created and started successfully!";
                        $flashType = 'success';

                    } catch (PDOException $ex) {
                        $pdo->rollBack();
                        error_log('Create experiment error: ' . $ex->getMessage());
                        $flashMsg  = 'Database error while creating experiment. Please try again.';
                        $flashType = 'error';
                    }
                }
            }

        // ── End experiment ────────────────────────────────────────────────────
        } elseif ($action === 'end_experiment' && in_array($userRole, ['admin', 'researcher'])) {

            $expId  = (int)($_POST['experiment_id'] ?? 0);
            $newSt  = $_POST['end_status'] ?? 'completed'; // 'completed' or 'terminated'
            if (!in_array($newSt, ['completed', 'terminated'])) $newSt = 'completed';

            if ($expId > 0) {
                try {
                    $stmt = $pdo->prepare(
                        "SELECT principal_user_id, status
                         FROM experiments
                         WHERE experiment_id = ?
                         LIMIT 1"
                    );
                    $stmt->execute([$expId]);
                    $experimentToEnd = $stmt->fetch();

                    if (!$experimentToEnd || $experimentToEnd['status'] !== 'active') {
                        $flashMsg = 'No active experiment was found to end.';
                        $flashType = 'error';
                        $expId = 0;
                    }

                    if ($expId > 0 && $userRole !== 'admin' && (int)$experimentToEnd['principal_user_id'] !== $userId) {
                        $flashMsg = 'Only the researcher who started this experiment can end it.';
                        $flashType = 'error';
                        $expId = 0;
                    }

                    if ($expId > 0) {
                    $pdo->prepare(
                        "UPDATE experiments SET status = ?, ended_at = NOW() WHERE experiment_id = ? AND status = 'active'"
                    )->execute([$newSt, $expId]);
                    $flashMsg  = '✅ Experiment marked as ' . $newSt . '.';
                    $flashType = 'success';
                    }
                } catch (PDOException $ex) {
                    error_log('End experiment error: ' . $ex->getMessage());
                    $flashMsg  = 'Failed to end the experiment. Please try again.';
                    $flashType = 'error';
                }
            }
        }
    }

    // After POST, redirect to avoid resubmission (PRG pattern)
    $qs = $flashMsg ? '?msg=' . urlencode($flashMsg) . '&type=' . urlencode($flashType) : '';
    header("Location: experiments.php{$qs}");
    exit;
}

// Pick up flash message from redirect
if (empty($flashMsg) && isset($_GET['msg'])) {
    $flashMsg  = $_GET['msg'];
    $flashType = $_GET['type'] ?? 'success';
}

// ============================================================================
// DATA QUERIES
// ============================================================================

// ── Active experiment ─────────────────────────────────────────────────────────
try {
    $activeExp = $pdo->query(
        "SELECT e.experiment_id, e.exp_code, e.title, e.objective,
                e.principal_user_id,
                e.started_at, e.expected_end_at,
                u.full_name  AS researcher_name,
                TIMESTAMPDIFF(HOUR, e.started_at, NOW())  AS hours_running,
                TIMESTAMPDIFF(DAY,  e.started_at, NOW())  AS days_running,
                DATEDIFF(e.expected_end_at, e.started_at) AS total_days
           FROM experiments e
           JOIN users u ON e.principal_user_id = u.user_id
          WHERE e.status = 'active'
          LIMIT 1"
    )->fetch();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $activeExp = null;
}

// ── Stats for active experiment ───────────────────────────────────────────────
$expStats = ['data_points' => 0, 'critical_alerts' => 0, 'uptime_pct' => 'N/A'];

if ($activeExp) {
    $expId = $activeExp['experiment_id'];

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sensor_readings WHERE experiment_id = ?");
        $stmt->execute([$expId]);
        $expStats['data_points'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM alerts WHERE experiment_id = ? AND severity = 'critical'");
        $stmt->execute([$expId]);
        $expStats['critical_alerts'] = (int)$stmt->fetchColumn();

        // Uptime: (total sensors online) / total sensors × 100
        $sensTotal  = (int)$pdo->query("SELECT COUNT(*) FROM sensors")->fetchColumn();
        $sensOnline = (int)$pdo->query("SELECT COUNT(*) FROM sensors WHERE status = 'online'")->fetchColumn();
        $expStats['uptime_pct'] = $sensTotal > 0
            ? number_format(($sensOnline / $sensTotal) * 100, 1) . '%'
            : 'N/A';

    } catch (PDOException $ex) {
        error_log('Exp stats error: ' . $ex->getMessage());
    }

    // Greenhouse assignments for active experiment
    try {
        $ghAssignments = $pdo->prepare(
            "SELECT eg.role, eg.light_setup,
                    g.code AS gh_code, g.name AS gh_name,
                    p.name AS plant_name, p.emoji AS plant_emoji
               FROM experiment_greenhouses eg
               JOIN greenhouses g ON eg.greenhouse_id = g.greenhouse_id
               LEFT JOIN plants p ON eg.plant_id = p.plant_id
              WHERE eg.experiment_id = ?
              ORDER BY g.code ASC"
        );
        $ghAssignments->execute([$expId]);
        $ghAssignments = $ghAssignments->fetchAll();
    } catch (PDOException $ex) {
        error_log('GH assignments error: ' . $ex->getMessage());
        $ghAssignments = [];
    }
} else {
    $ghAssignments = [];
}

// ── All experiments (history table) ──────────────────────────────────────────
try {
    $allExperiments = $pdo->query(
        "SELECT e.experiment_id, e.exp_code, e.title, e.status,
                e.started_at, e.ended_at, e.expected_end_at,
                u.full_name AS researcher_name,
                TIMESTAMPDIFF(DAY, e.started_at, IFNULL(e.ended_at, NOW())) AS days_elapsed,
                DATEDIFF(e.expected_end_at, e.started_at)                   AS total_days
           FROM experiments e
           JOIN users u ON e.principal_user_id = u.user_id
          ORDER BY e.started_at DESC
          LIMIT 20"
    )->fetchAll();
} catch (PDOException $ex) {
    error_log('All experiments error: ' . $ex->getMessage());
    $allExperiments = [];
}

// ── Plants list for create form ───────────────────────────────────────────────
try {
    $plants = $pdo->query(
        "SELECT plant_id, name, emoji, scientific_name FROM plants ORDER BY name ASC"
    )->fetchAll();
} catch (PDOException $ex) {
    error_log('Plants list error: ' . $ex->getMessage());
    $plants = [];
}

// ── Hardware integration list ─────────────────────────────────────────────────
try {
    $hwList = $pdo->query(
        "SELECT label, type, model, status FROM hardware_components ORDER BY component_id ASC"
    )->fetchAll();
} catch (PDOException $ex) {
    error_log('HW list error: ' . $ex->getMessage());
    $hwList = [];
}

$hwLabels = implode(', ', array_map(fn($h) => e($h['label']), $hwList));

// ============================================================================
// HELPERS
// ============================================================================

// Builds the badge output for status display.
function status_badge(string $status): string {
    return match ($status) {
        'active'     => '<span class="badge badge-success">Active</span>',
        'completed'  => '<span class="badge badge-neutral">Completed</span>',
        'terminated' => '<span class="badge badge-danger">Terminated</span>',
        'draft'      => '<span class="badge badge-warning">Draft</span>',
        default      => '<span class="badge badge-neutral">' . e(ucfirst($status)) . '</span>',
    };
}

// Returns the label text for duration.
function duration_label(array $exp): string {
    $elapsed = max(0, (int)$exp['days_elapsed']);
    $total   = max(0, (int)$exp['total_days']);
    return match ($exp['status']) {
        'active'     => "{$elapsed} / {$total} days",
        'completed',
        'terminated' => "{$elapsed} days",
        default      => "{$total} days planned",
    };
}

// Session display
$userName     = e($_SESSION['user_name']  ?? 'User');
$userEmail    = e($_SESSION['user_email'] ?? '');
$userRoleDisp = e(ucfirst($userRole));
$userInitials = strtoupper(implode('', array_map(
    fn($w) => $w[0],
    array_slice(explode(' ', trim($_SESSION['user_name'] ?? 'U')), 0, 2)
)));

$canCreateExperiment = in_array($userRole, ['admin', 'researcher'], true);
$canEndActiveExperiment = $activeExp
    && ($userRole === 'admin' || (int)($activeExp['principal_user_id'] ?? 0) === $userId);
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($preferences['language']) ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($t('page.experiments.title')) ?> — EcoTwin</title>
    <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>" />
    <link rel="stylesheet" href="css.experiments.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.experiments.css')) ?>" />
    <style>
        /* ── Create experiment modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease-out;
        }
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .modal-head {
            padding: 24px 28px 16px;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .modal-head h3 { font-size: 18px; font-weight: 700; margin: 0; }
        .modal-body-inner { padding: 24px 28px; }
        .modal-foot {
            padding: 16px 28px 24px;
            border-top: 1px solid #E5E7EB;
            display: flex;
            gap: 12px;
        }
        .modal-foot .btn { flex: 1; padding: 11px; font-size: 14px; font-weight: 600; }
        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-field label { font-size: 12px; font-weight: 600; color: #5A5A5A; text-transform: uppercase; letter-spacing: 0.4px; }
        .form-field input,
        .form-field select,
        .form-field textarea {
            padding: 9px 12px;
            border: 1.5px solid #D1D5DB;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #F8FFF8;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            border-color: #2E8B57;
            background: white;
            box-shadow: 0 0 0 3px rgba(13,148,136,0.1);
        }
        .form-field textarea { resize: vertical; min-height: 80px; }
        .section-divider {
            font-size: 12px;
            font-weight: 700;
            color: #2E8B57;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #F0FFF0;
            padding-bottom: 6px;
            margin: 20px 0 14px;
        }
        .gh-form-card {
            background: #F8FFF8;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            padding: 14px 16px;
        }
        .gh-form-label {
            font-size: 13px;
            font-weight: 700;
            color: #1A1A1A;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        /* flash */
        .flash { display:flex; align-items:center; gap:12px; padding:14px 20px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .flash-success { background:#F0FFF0; border-left:4px solid #3CB371; color:#2E8B57; }
        .flash-error   { background:#FEE2E2; border-left:4px solid #EF4444; color:#991B1B; }
        /* end experiment modal */
        .confirm-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 9999;
            align-items: center; justify-content: center; padding: 16px;
        }
        .confirm-overlay.active { display: flex; }
        .confirm-box {
            background: white; border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            width: 100%; max-width: 440px; padding: 32px;
            text-align: center; animation: slideUp 0.3s ease-out;
        }
        .confirm-icon { font-size: 48px; margin-bottom: 12px; }
        .confirm-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .confirm-msg { font-size: 14px; color: #5A5A5A; margin-bottom: 24px; line-height: 1.6; }
        .confirm-actions { display: flex; gap: 12px; }
        .confirm-actions .btn { flex: 1; padding: 11px; font-size: 14px; font-weight: 600; }
        @media (max-width: 640px) {
            .form-row-2 { grid-template-columns: 1fr; }
        }
    </style>
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
            <a href="index.php"       class="nav-item"><?= htmlspecialchars($t('nav.index')) ?></a>
            <a href="dashboard.php"    class="nav-item"><?= htmlspecialchars($t('nav.dashboard')) ?></a>
            <a href="experiments.php"  class="nav-item active"><?= htmlspecialchars($t('nav.experiments')) ?></a>
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
                        <div class="profile-user-role"><?= $userRoleDisp ?></div>
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

    <div class="page-header">
        <h1 class="page-title"><?= htmlspecialchars($t('page.experiments.title')) ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($t('page.experiments.subtitle')) ?></p>
    </div>

    <!-- ── Flash message ─────────────────────────────────────────────── -->
    <?php if ($flashMsg): ?>
    <div class="flash flash-<?= $flashType === 'error' ? 'error' : 'success' ?>">
        <?= e($flashMsg) ?>
    </div>
    <?php endif; ?>

    <!-- ── System lock notice ─────────────────────────────────────────── -->
    <div class="alert alert-info mb-3">
        <span class="alert-icon">🔒</span>
        <div>
            <strong>Single-Experiment System:</strong> Only one experiment can be
            active at a time. Both greenhouses (A &amp; B) are reserved while an
            experiment is running.
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- ACTIVE EXPERIMENT CARD                                           -->
    <!-- ================================================================ -->
    <?php if ($activeExp): ?>
    <section class="experiment-detail-card active mb-4">
        <div class="experiment-header">
            <div class="exp-badge-large badge-active">
                <div class="exp-icon">🔬</div>
                <div>ACTIVE EXPERIMENT</div>
            </div>
            <div class="exp-actions">
                <button class="btn btn-secondary" disabled>View Data</button>
                <?php if ($canEndActiveExperiment): ?>
                <button class="btn btn-danger"
                        onclick="openEndModal(<?= $activeExp['experiment_id'] ?>, '<?= e(addslashes($activeExp['title'])) ?>')">
                    End Experiment
                </button>
                <?php else: ?>
                <button class="btn btn-danger" disabled title="Only the researcher who started this experiment can end it.">End Experiment</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="experiment-body">
            <div class="exp-main-info">
                <h2 class="exp-title"><?= e($activeExp['title']) ?></h2>

                <div class="exp-meta">
                    <span class="meta-item">
                        <strong>ID:</strong> <?= e($activeExp['exp_code']) ?>
                    </span>
                    <span class="meta-item">
                        <strong>Principal Researcher:</strong> <?= e($activeExp['researcher_name']) ?>
                    </span>
                    <span class="meta-item">
                        <strong>Started:</strong>
                        <?= date('F j, Y', strtotime($activeExp['started_at'])) ?>
                        (<?= (int)$activeExp['days_running'] ?> day(s) ago)
                    </span>
                    <span class="meta-item">
                        <strong>Expected Duration:</strong>
                        <?= (int)$activeExp['total_days'] ?> days
                        (ends <?= date('M j, Y', strtotime($activeExp['expected_end_at'])) ?>)
                    </span>
                </div>

                <?php if ($activeExp['objective']): ?>
                <div class="exp-description">
                    <strong>Objective:</strong> <?= e($activeExp['objective']) ?>
                </div>
                <?php endif; ?>

                <!-- Greenhouse assignments -->
                <div class="exp-greenhouse-assignment">
                    <?php foreach ($ghAssignments as $gh):
                        $cls = strtolower($gh['gh_code']) === 'a' ? 'gh-a' : 'gh-b';
                    ?>
                    <div class="gh-assignment">
                        <div class="gh-assignment-icon <?= $cls ?>">🏠</div>
                        <div>
                            <div class="gh-assignment-title">Greenhouse <?= e($gh['gh_code']) ?></div>
                            <div class="gh-assignment-role"><?= e(ucfirst($gh['role'])) ?> Group</div>
                            <?php if ($gh['light_setup']): ?>
                            <div class="gh-assignment-desc"><?= e($gh['light_setup']) ?></div>
                            <?php endif; ?>
                            <?php if ($gh['plant_name']): ?>
                            <div class="gh-assignment-desc" style="color:#2E8B57;">
                                <?= e($gh['plant_emoji'] ?? '🌱') ?> <?= e($gh['plant_name']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($ghAssignments)): ?>
                    <div class="gh-assignment">
                        <div class="gh-assignment-icon gh-a">🏠</div>
                        <div>
                            <div class="gh-assignment-title">Greenhouse A</div>
                            <div class="gh-assignment-role">Treatment Group</div>
                        </div>
                    </div>
                    <div class="gh-assignment">
                        <div class="gh-assignment-icon gh-b">🏠</div>
                        <div>
                            <div class="gh-assignment-title">Greenhouse B</div>
                            <div class="gh-assignment-role">Control Group</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats sidebar -->
            <div class="exp-stats">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format((int)$activeExp['hours_running']) ?></div>
                    <div class="stat-label">Hours Running</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($expStats['data_points']) ?></div>
                    <div class="stat-label">Data Points</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $expStats['critical_alerts'] ?></div>
                    <div class="stat-label">Critical Alerts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= e($expStats['uptime_pct']) ?></div>
                    <div class="stat-label">Sensor Uptime</div>
                </div>
            </div>
        </div>
    </section>

    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- CREATE NEW EXPERIMENT                                            -->
    <!-- ================================================================ -->
    <section class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Start New Experiment</h2>
        </div>

        <?php if ($activeExp): ?>
        <!-- Locked — experiment running -->
        <div class="lock-notice">
            <div class="lock-icon">🔒</div>
            <div class="lock-content">
                <h3 class="lock-title">Greenhouses Currently Reserved</h3>
                <p class="lock-message">
                    An experiment is already running. Both Greenhouse A and Greenhouse B
                    are occupied and unavailable until the current study is completed or ended.
                </p>
                <p class="lock-info">
                    <strong>Current Researcher:</strong> <?= e($activeExp['researcher_name']) ?> &bull;
                    <strong>Expected End:</strong> <?= date('F j, Y', strtotime($activeExp['expected_end_at'])) ?>
                </p>
            </div>
        </div>
        <button class="btn btn-primary btn-lg" disabled>Create New Experiment</button>

        <?php elseif (!$canCreateExperiment): ?>
        <!-- Not enough privileges -->
        <div class="lock-notice">
            <div class="lock-icon">🔒</div>
            <div class="lock-content">
                <h3 class="lock-title">Permission Required</h3>
                <p class="lock-message">
                    Only Researchers and Administrators can create experiments.
                    Contact your administrator if you need access.
                </p>
            </div>
        </div>
        <button class="btn btn-primary btn-lg" disabled>Create New Experiment</button>

        <?php else: ?>
        <!-- Available — show create button -->
        <div class="alert alert-success mb-3" style="margin:0 0 16px;">
            <span class="alert-icon">✅</span>
            <div>Both greenhouses are available. You can start a new experiment.</div>
        </div>
        <div style="padding: 0 20px 20px;">
            <button class="btn btn-primary btn-lg" onclick="openCreateModal()">
                + Create New Experiment
            </button>
        </div>
        <?php endif; ?>
    </section>

    <!-- ================================================================ -->
    <!-- EXPERIMENT HISTORY TABLE                                         -->
    <!-- ================================================================ -->
    <section class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Experiment History</h2>
            <span class="badge badge-info"><?= count($allExperiments) ?> total</span>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Experiment ID</th>
                    <th>Title</th>
                    <th>Researcher</th>
                    <th>Start Date</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allExperiments)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding:32px;">
                        No experiments found.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($allExperiments as $exp): ?>
                <tr class="<?= $exp['status'] === 'active' ? 'row-active' : '' ?>">
                    <td><?= status_badge($exp['status']) ?></td>
                    <td><strong><?= e($exp['exp_code']) ?></strong></td>
                    <td><?= e($exp['title']) ?></td>
                    <td><?= e($exp['researcher_name']) ?></td>
                    <td><?= date('M j, Y', strtotime($exp['started_at'])) ?></td>
                    <td><?= e(duration_label($exp)) ?></td>
                    <td>
                        <button class="btn-link" disabled>View</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <!-- ── Hardware notice ───────────────────────────────────────────── -->
    <section class="hardware-notice mt-4">
        <div class="notice-icon">⚙️</div>
        <div>
            <h3 class="notice-title">Hardware Integration</h3>
            <p class="notice-text">
                <?php if ($hwList): ?>
                    Data collected from:
                    <?= implode(', ', array_map(fn($h) => e($h['label'] . ' (' . $h['status'] . ')'), $hwList)) ?>.
                <?php else: ?>
                    No hardware components registered yet. Add them via the Admin panel.
                <?php endif; ?>
            </p>
        </div>
    </section>

</main>

<!-- ================================================================ -->
<!-- CREATE EXPERIMENT MODAL                                           -->
<!-- ================================================================ -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <div class="modal-head">
            <span style="font-size:28px;">🔬</span>
            <h3>Create New Experiment</h3>
        </div>
        <form method="POST" action="experiments.php">
            <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>" />
            <input type="hidden" name="form_action" value="create_experiment" />

            <div class="modal-body-inner">

                <div class="form-field">
                    <label for="exp_title">Experiment Title *</label>
                    <input type="text" id="exp_title" name="title"
                           placeholder="e.g. Basil Growth Under Red LED"
                           maxlength="200" required />
                </div>

                <div class="form-field">
                    <label for="exp_objective">Objective / Description</label>
                    <textarea id="exp_objective" name="objective"
                              placeholder="Describe the research objective and hypothesis..."></textarea>
                </div>

                <div class="form-field">
                    <label for="exp_days">Expected Duration (days) *</label>
                    <input type="number" id="exp_days" name="exp_days"
                           min="1" max="365" placeholder="e.g. 30" required />
                </div>

                <div class="section-divider">🏠 Greenhouse Assignment</div>

                <div class="form-row-2">
                    <!-- Greenhouse A -->
                    <div class="gh-form-card">
                        <div class="gh-form-label">
                            <span style="background:#F0FFF0;border-radius:6px;padding:4px 8px;">🏠 A</span>
                            Greenhouse A — Treatment
                        </div>
                        <div class="form-field" style="margin-bottom:10px;">
                            <label>Plant Profile</label>
                            <select name="plant_id_a">
                                <option value="">— Select plant —</option>
                                <?php foreach ($plants as $p): ?>
                                <option value="<?= (int)$p['plant_id'] ?>">
                                    <?= e($p['emoji'] . ' ' . $p['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field" style="margin-bottom:0;">
                            <label>Light Setup</label>
                            <input type="text" name="light_setup_a"
                                   placeholder="e.g. Full-spectrum LED (400-700nm)"
                                   maxlength="120" />
                        </div>
                    </div>

                    <!-- Greenhouse B -->
                    <div class="gh-form-card">
                        <div class="gh-form-label">
                            <span style="background:#F0FFF0;border-radius:6px;padding:4px 8px;">🏠 B</span>
                            Greenhouse B — Control
                        </div>
                        <div class="form-field" style="margin-bottom:10px;">
                            <label>Plant Profile</label>
                            <select name="plant_id_b">
                                <option value="">— Select plant —</option>
                                <?php foreach ($plants as $p): ?>
                                <option value="<?= (int)$p['plant_id'] ?>">
                                    <?= e($p['emoji'] . ' ' . $p['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field" style="margin-bottom:0;">
                            <label>Light Setup</label>
                            <input type="text" name="light_setup_b"
                                   placeholder="e.g. Standard white LED (5000K)"
                                   maxlength="120" />
                        </div>
                    </div>
                </div>

            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">🚀 Start Experiment</button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================ -->
<!-- END EXPERIMENT CONFIRMATION MODAL                                 -->
<!-- ================================================================ -->
<div class="confirm-overlay" id="endModal">
    <div class="confirm-box">
        <div class="confirm-icon">⚠️</div>
        <div class="confirm-title">End Experiment?</div>
        <div class="confirm-msg" id="endModalMsg">
            This will mark the experiment as ended and release both greenhouses.
            This action cannot be undone.
        </div>
        <form method="POST" action="experiments.php">
            <input type="hidden" name="csrf_token"     value="<?= e($csrfToken) ?>" />
            <input type="hidden" name="form_action"    value="end_experiment" />
            <input type="hidden" name="experiment_id"  id="endExpId" value="" />
            <input type="hidden" name="end_status"     id="endExpStatus" value="completed" />
            <div class="confirm-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEndModal()">Cancel</button>
                <button type="submit" class="btn btn-danger"
                        onclick="document.getElementById('endExpStatus').value='completed'">
                    Mark Completed
                </button>
                <button type="submit" class="btn btn-secondary" style="background:#FEF3C7;color:#92400E;border-color:#FDE68A;"
                        onclick="document.getElementById('endExpStatus').value='terminated'">
                    Terminate
                </button>
            </div>
        </form>
    </div>
</div>

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

// Profile dropdown
// Toggles the profile dropdown menu in the page header.
function toggleProfileDropdown(e) {
    e.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('active');
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('.profile-icon') && !e.target.closest('.profile-dropdown')) {
        document.getElementById('profileDropdown').classList.remove('active');
    }
});

// Create modal
// Opens the create modal panel or modal.
function openCreateModal() {
    document.getElementById('createModal').classList.add('active');
}
// Closes the create modal panel or modal.
function closeCreateModal() {
    document.getElementById('createModal').classList.remove('active');
}
document.getElementById('createModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});

// End experiment modal
// Opens the end modal panel or modal.
function openEndModal(id, title) {
    document.getElementById('endExpId').value = id;
    document.getElementById('endModalMsg').textContent =
        'You are about to end "' + title + '". Both greenhouses will be released. This cannot be undone.';
    document.getElementById('endModal').classList.add('active');
}
// Closes the end modal panel or modal.
function closeEndModal() {
    document.getElementById('endModal').classList.remove('active');
}
document.getElementById('endModal').addEventListener('click', function(e) {
    if (e.target === this) closeEndModal();
});

// Close modals on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCreateModal();
        closeEndModal();
    }
});
</script>
<script>
// Handle logout via AJAX to process JSON and redirect (copied from dashboard.php)
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
