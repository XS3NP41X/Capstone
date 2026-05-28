<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$dashboardUrl = ($_SESSION['user_role'] ?? '') === 'admin' ? 'admin.php' : 'dashboard.php';
$primaryUrl = $isLoggedIn ? $dashboardUrl : 'login.php';
$primaryLabel = $isLoggedIn ? 'Open Dashboard' : 'Sign In';

$status = [
    'database' => 'Checking',
    'greenhouses' => 0,
    'sensors' => 0,
];

try {
    $pdo = db();
    $status['database'] = 'Online';
    $status['greenhouses'] = (int)$pdo->query("SELECT COUNT(*) FROM greenhouses")->fetchColumn();
    $status['sensors'] = (int)$pdo->query("SELECT COUNT(*) FROM sensors")->fetchColumn();
} catch (Throwable $e) {
    error_log('Landing status error: ' . $e->getMessage());
    $status['database'] = 'Offline';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECOTwin LAN Gateway</title>
    <link rel="stylesheet" href="css.main.css?v=<?= urlencode((string) @filemtime(__DIR__ . '/css.main.css')) ?>">
    <style>
        body {
            margin: 0;
            background: #f6f8f7;
            color: #17211d;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }
        .landing-shell {
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr;
        }
        .landing-nav {
            height: 68px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 24px;
            background: #fff;
            border-bottom: 1px solid #e3e8e5;
        }
        .landing-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            font-size: 18px;
        }
        .landing-brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }
        .landing-nav-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .landing-main {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 40px 0;
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
            gap: 28px;
            align-items: stretch;
        }
        .hero-panel, .status-panel {
            background: #fff;
            border: 1px solid #e3e8e5;
            border-radius: 10px;
            padding: 28px;
        }
        .hero-panel {
            display: grid;
            align-content: center;
            min-height: 520px;
        }
        .eyebrow {
            color: #0d9488;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .hero-title {
            font-size: 44px;
            line-height: 1.05;
            margin: 0 0 16px;
            letter-spacing: 0;
        }
        .hero-copy {
            max-width: 640px;
            color: #526058;
            font-size: 17px;
            line-height: 1.7;
            margin: 0 0 24px;
        }
        .action-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .landing-btn {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 16px;
            border-radius: 8px;
            font-weight: 800;
            text-decoration: none;
            border: 1px solid #d8e1dc;
            color: #24312b;
            background: #fff;
        }
        .landing-btn.primary {
            background: #0d9488;
            color: #fff;
            border-color: #0d9488;
        }
        .status-panel {
            display: grid;
            gap: 16px;
            align-content: start;
        }
        .status-card {
            border: 1px solid #e3e8e5;
            border-radius: 8px;
            padding: 16px;
            background: #f9fbfa;
        }
        .status-label {
            color: #64736b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .status-value {
            margin-top: 8px;
            font-size: 28px;
            font-weight: 900;
        }
        .flow {
            display: grid;
            gap: 10px;
            color: #526058;
            line-height: 1.5;
        }
        .flow div {
            padding: 12px;
            border-radius: 8px;
            background: #eef7f4;
            color: #17564d;
            font-weight: 700;
        }
        @media (max-width: 880px) {
            .landing-main {
                grid-template-columns: 1fr;
                padding-top: 24px;
            }
            .hero-panel {
                min-height: auto;
            }
            .hero-title {
                font-size: 34px;
            }
            .landing-nav {
                padding: 0 16px;
            }
        }
    </style>
</head>
<body>
<div class="landing-shell">
    <nav class="landing-nav">
        <div class="landing-brand">
            <img src="ECOTwin_Logo.png" alt="ECOTwin logo">
            <span>ECOTwin LAN</span>
        </div>
        <div class="landing-nav-actions">
            <a class="landing-btn" href="greenhouses.php">Greenhouses</a>
            <a class="landing-btn primary" href="<?= htmlspecialchars($primaryUrl) ?>"><?= htmlspecialchars($primaryLabel) ?></a>
        </div>
    </nav>

    <main class="landing-main">
        <section class="hero-panel">
            <div class="eyebrow">Dual-Greenhouse Research Framework</div>
            <h1 class="hero-title">Local greenhouse monitoring through the ESP32 LAN gateway.</h1>
            <p class="hero-copy">
                ECOTwin runs on the local XAMPP server while users connect through the ESP32 Wi-Fi network.
                Sensor records, experiments, reports, and controls stay inside the LAN.
            </p>
            <div class="action-row">
                <a class="landing-btn primary" href="<?= htmlspecialchars($primaryUrl) ?>"><?= htmlspecialchars($primaryLabel) ?></a>
                <a class="landing-btn" href="login.php">Researcher Login</a>
            </div>
        </section>

        <aside class="status-panel">
            <div class="status-card">
                <div class="status-label">Database</div>
                <div class="status-value"><?= htmlspecialchars($status['database']) ?></div>
            </div>
            <div class="status-card">
                <div class="status-label">Greenhouses</div>
                <div class="status-value"><?= number_format($status['greenhouses']) ?></div>
            </div>
            <div class="status-card">
                <div class="status-label">Sensors</div>
                <div class="status-value"><?= number_format($status['sensors']) ?></div>
            </div>
            <div class="flow">
                <div>1. Connect to ECOTwin-LAN</div>
                <div>2. Open 192.168.4.1</div>
                <div>3. Continue to this local website</div>
            </div>
        </aside>
    </main>
</div>
</body>
</html>
