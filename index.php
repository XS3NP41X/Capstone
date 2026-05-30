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
            background:
                linear-gradient(90deg, rgba(46, 139, 87, 0.045) 1px, transparent 1px) 0 0 / 42px 42px,
                linear-gradient(180deg, rgba(46, 139, 87, 0.035) 1px, transparent 1px) 0 0 / 42px 42px,
                radial-gradient(circle at 18% 18%, rgba(60, 179, 113, 0.18), transparent 26rem),
                radial-gradient(circle at 86% 12%, rgba(191, 188, 143, 0.2), transparent 22rem),
                linear-gradient(135deg, var(--brand-mint) 0%, var(--brand-white) 56%, rgba(191, 188, 143, 0.18) 100%);
            color: var(--brand-ink);
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
            background: rgba(255, 255, 255, 0.96);
            border-bottom: 1px solid var(--brand-line);
            box-shadow: 0 8px 26px rgba(46, 139, 87, 0.08);
            backdrop-filter: blur(12px);
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
            padding: 4px;
            border-radius: 8px;
            background: var(--brand-mint);
            border: 1px solid rgba(191, 188, 143, 0.42);
        }
        .landing-nav-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .landing-main {
            width: min(1240px, calc(100% - 32px));
            margin: 0 auto;
            padding: 40px 0;
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(340px, 0.8fr);
            gap: 28px;
            align-items: stretch;
        }
        .hero-panel, .status-panel {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid var(--brand-line);
            border-radius: 8px;
            padding: 28px;
            box-shadow: var(--brand-shadow);
        }
        .hero-panel {
            position: relative;
            display: grid;
            align-content: center;
            min-height: 600px;
            overflow: hidden;
            background:
                linear-gradient(120deg, rgba(255, 255, 255, 0.96), rgba(240, 255, 240, 0.74)),
                linear-gradient(90deg, rgba(46, 139, 87, 0.06) 1px, transparent 1px) 0 0 / 24px 24px;
        }
        .hero-panel::before {
            content: "";
            position: absolute;
            right: -12px;
            bottom: 12px;
            width: min(46%, 390px);
            height: 48%;
            background: url("assets/Greenhouse_Model.png") center / contain no-repeat;
            filter: drop-shadow(0 28px 36px rgba(46, 139, 87, 0.26));
            opacity: 0.95;
            pointer-events: none;
        }
        .hero-panel::after {
            content: "";
            position: absolute;
            right: 24px;
            bottom: 34px;
            width: min(42%, 360px);
            height: 28px;
            border-radius: 50%;
            background: rgba(46, 139, 87, 0.16);
            filter: blur(7px);
            pointer-events: none;
        }
        .hero-motion-loop {
            position: absolute;
            right: 28px;
            top: 26px;
            width: min(38%, 340px);
            height: 158px;
            overflow: hidden;
            border-radius: 12px;
            border: 1px solid rgba(46, 139, 87, 0.14);
            background:
                linear-gradient(120deg, rgba(46, 139, 87, 0.16), rgba(255, 255, 255, 0.72)),
                url("assets/Greenhouse_Model.png") center / cover no-repeat;
            box-shadow: 0 18px 38px rgba(46, 139, 87, 0.18);
            opacity: 0.88;
            pointer-events: none;
        }
        .hero-motion-loop::before {
            content: "";
            position: absolute;
            inset: -35%;
            background:
                linear-gradient(105deg, transparent 0 36%, rgba(255, 255, 255, 0.54) 46%, transparent 58%),
                repeating-linear-gradient(90deg, rgba(255, 255, 255, 0.22) 0 1px, transparent 1px 18px);
            animation: heroScan 5.8s linear infinite;
        }
        .hero-motion-loop::after {
            content: "LIVE LAN";
            position: absolute;
            right: 12px;
            bottom: 10px;
            padding: 5px 8px;
            border-radius: 999px;
            color: #FFFFFF;
            background: rgba(46, 139, 87, 0.88);
            font-size: 11px;
            font-weight: 900;
        }
        @keyframes heroScan {
            from { transform: translateX(-28%) rotate(0deg); }
            to { transform: translateX(28%) rotate(0deg); }
        }
        .eyebrow,
        .hero-title,
        .hero-copy,
        .gateway-visual,
        .action-row {
            position: relative;
            z-index: 1;
        }
        .eyebrow {
            width: fit-content;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(240, 255, 240, 0.96);
            border: 1px solid rgba(46, 139, 87, 0.14);
            color: var(--brand-deep);
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .hero-title {
            max-width: 650px;
            font-size: 52px;
            line-height: 1.05;
            margin: 0 0 16px;
            letter-spacing: 0;
        }
        .hero-copy {
            max-width: 640px;
            color: var(--brand-muted);
            font-size: 17px;
            line-height: 1.7;
            margin: 0 0 24px;
        }
        .gateway-visual {
            position: relative;
            display: grid;
            grid-template-columns: 1fr auto 1fr auto 1fr;
            gap: 10px;
            align-items: center;
            margin: 0 0 24px;
            padding: 18px;
            overflow: hidden;
            background:
                linear-gradient(135deg, rgba(240, 255, 240, 0.92), rgba(255, 255, 255, 0.94)),
                repeating-linear-gradient(90deg, rgba(46, 139, 87, 0.06) 0 1px, transparent 1px 20px);
            border: 1px solid var(--brand-line);
            border-radius: 8px;
            box-shadow: 0 16px 34px rgba(23, 51, 38, 0.1);
        }
        .gateway-node {
            position: relative;
            z-index: 1;
            min-height: 112px;
            display: grid;
            place-items: center;
            gap: 8px;
            padding: 14px 10px;
            text-align: center;
            background: var(--brand-white);
            border: 1px solid var(--brand-line);
            border-radius: 8px;
            box-shadow: 0 8px 20px rgba(46, 139, 87, 0.08);
        }
        .gateway-node strong {
            color: var(--brand-ink);
            font-size: 14px;
        }
        .gateway-node span {
            color: var(--brand-muted);
            font-size: 12px;
            line-height: 1.35;
        }
        .gateway-icon {
            position: relative;
            width: 54px;
            height: 54px;
            border-radius: 8px;
            background:
                radial-gradient(circle at 36% 22%, rgba(255, 255, 255, 0.95), transparent 2.6rem),
                linear-gradient(135deg, var(--brand-mint), #FFFFFF);
            border: 1px solid #cfe6d3;
            box-shadow: inset 0 0 0 6px rgba(60, 179, 113, 0.08), 0 10px 20px rgba(46, 139, 87, 0.11);
        }
        .phone-icon::before {
            content: "";
            position: absolute;
            left: 17px;
            top: 8px;
            width: 20px;
            height: 38px;
            border: 3px solid var(--brand-deep);
            border-radius: 7px;
            background: #FFFFFF;
        }
        .phone-icon::after {
            content: "";
            position: absolute;
            left: 24px;
            bottom: 12px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--brand-green);
        }
        .esp-icon::before,
        .esp-icon::after {
            content: "";
            position: absolute;
        }
        .esp-icon::before {
            left: 12px;
            top: 17px;
            width: 30px;
            height: 20px;
            border-radius: 5px;
            background: var(--brand-deep);
            box-shadow: inset 0 0 0 5px rgba(255, 255, 255, 0.18);
        }
        .esp-icon::after {
            left: 19px;
            top: 8px;
            width: 16px;
            height: 16px;
            border: 2px solid var(--brand-gold);
            border-left: 0;
            border-bottom: 0;
            transform: rotate(-45deg);
        }
        .data-icon::before {
            content: "";
            position: absolute;
            left: 13px;
            top: 9px;
            width: 28px;
            height: 36px;
            border-radius: 50% / 13%;
            background: linear-gradient(180deg, #FFFFFF 0 18%, var(--brand-green) 18% 100%);
            border: 2px solid var(--brand-deep);
        }
        .gateway-arrow {
            width: 28px;
            height: 2px;
            background: var(--brand-gold);
            position: relative;
        }
        .gateway-arrow::after {
            content: "";
            position: absolute;
            right: -1px;
            top: -4px;
            width: 10px;
            height: 10px;
            border-top: 2px solid var(--brand-gold);
            border-right: 2px solid var(--brand-gold);
            transform: rotate(45deg);
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
            border: 1px solid var(--brand-line);
            color: var(--brand-ink);
            background: var(--brand-white);
            transition: all 0.2s ease;
        }
        .landing-btn:hover {
            background: var(--brand-mint);
            color: var(--brand-deep);
            transform: translateY(-2px);
            box-shadow: var(--brand-shadow-sm);
        }
        .landing-btn.primary {
            background: var(--brand-deep);
            color: var(--brand-white);
            border-color: var(--brand-deep);
        }
        .landing-btn.primary:hover {
            background: var(--brand-green);
            border-color: var(--brand-green);
            color: var(--brand-white);
        }
        .status-panel {
            display: grid;
            gap: 16px;
            align-content: start;
            background:
                linear-gradient(150deg, rgba(255, 255, 255, 0.96), rgba(240, 255, 240, 0.78)),
                linear-gradient(90deg, rgba(46, 139, 87, 0.055) 1px, transparent 1px) 0 0 / 22px 22px;
        }
        .status-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--brand-line);
            border-radius: 8px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 10px 22px rgba(46, 139, 87, 0.08);
        }
        .status-card::after {
            content: "";
            position: absolute;
            right: -22px;
            top: -24px;
            width: 82px;
            height: 82px;
            border-radius: 50%;
            background: rgba(60, 179, 113, 0.12);
        }
        .status-label {
            color: var(--brand-muted);
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
            color: var(--brand-muted);
            line-height: 1.5;
        }
        .flow div {
            position: relative;
            padding: 12px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.88);
            color: var(--brand-ink);
            font-weight: 700;
            border-left: 4px solid var(--brand-gold);
            box-shadow: 0 8px 18px rgba(46, 139, 87, 0.08);
        }
        .module-showcase {
            grid-column: 1 / -1;
            display: grid;
            gap: 16px;
            padding: 24px;
            background:
                linear-gradient(145deg, rgba(255, 255, 255, 0.94), rgba(240, 255, 240, 0.78)),
                linear-gradient(90deg, rgba(46, 139, 87, 0.045) 1px, transparent 1px) 0 0 / 24px 24px;
            border: 1px solid var(--brand-line);
            border-radius: 8px;
            box-shadow: var(--brand-shadow);
        }
        .module-showcase-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: end;
            flex-wrap: wrap;
        }
        .module-showcase h2 {
            margin: 0 0 6px;
            color: var(--brand-ink);
            font-size: 26px;
        }
        .module-showcase p {
            max-width: 680px;
            margin: 0;
            color: var(--brand-muted);
            line-height: 1.6;
        }
        .module-search {
            min-width: min(320px, 100%);
            height: 46px;
            padding: 0 14px;
            border: 1px solid var(--brand-line);
            border-radius: 8px;
            font: inherit;
            color: var(--brand-ink);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
        }
        .module-filter-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .module-filter-row button {
            min-height: 38px;
            padding: 0 12px;
            border: 1px solid var(--brand-line);
            border-radius: 999px;
            background: #FFFFFF;
            color: var(--brand-muted);
            font-weight: 800;
            cursor: pointer;
        }
        .module-filter-row button.active {
            background: var(--brand-deep);
            color: #FFFFFF;
            border-color: var(--brand-deep);
            box-shadow: 0 10px 20px rgba(46, 139, 87, 0.18);
        }
        .module-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .module-card {
            position: relative;
            min-height: 190px;
            padding: 18px;
            overflow: hidden;
            border: 1px solid var(--brand-line);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 12px 24px rgba(46, 139, 87, 0.08);
        }
        .module-card::after {
            content: "";
            position: absolute;
            right: -22px;
            bottom: -28px;
            width: 92px;
            height: 92px;
            border-radius: 50%;
            background: rgba(191, 188, 143, 0.16);
        }
        .module-graphic {
            position: relative;
            width: 58px;
            height: 58px;
            margin-bottom: 14px;
            border-radius: 12px;
            background: linear-gradient(135deg, #F0FFF0, #FFFFFF);
            border: 1px solid rgba(46, 139, 87, 0.16);
            box-shadow: inset 0 0 0 6px rgba(60, 179, 113, 0.08), 0 12px 22px rgba(46, 139, 87, 0.11);
        }
        .module-graphic::before {
            content: "";
            position: absolute;
            inset: 14px;
            border-radius: 9px;
            background: var(--brand-green);
            box-shadow: 16px 0 0 var(--brand-gold), 8px 18px 0 var(--brand-deep);
        }
        .module-card strong,
        .module-card span {
            position: relative;
            z-index: 1;
            display: block;
        }
        .module-card strong {
            margin-bottom: 7px;
            color: var(--brand-ink);
            font-size: 17px;
        }
        .module-card span {
            color: var(--brand-muted);
            line-height: 1.5;
            font-size: 13px;
        }
        .module-card[hidden] {
            display: none;
        }
        @media (max-width: 880px) {
            .landing-main {
                grid-template-columns: 1fr;
                padding-top: 24px;
            }
            .hero-panel {
                min-height: auto;
            }
            .hero-panel::before,
            .hero-panel::after,
            .hero-motion-loop {
                display: none;
            }
            .hero-title {
                font-size: 34px;
            }
            .gateway-visual {
                grid-template-columns: 1fr;
            }
            .gateway-arrow {
                width: 2px;
                height: 24px;
                justify-self: center;
            }
            .gateway-arrow::after {
                right: -4px;
                top: 13px;
                transform: rotate(135deg);
            }
            .landing-nav {
                padding: 0 16px;
            }
            .module-grid {
                grid-template-columns: 1fr;
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
            <div class="hero-motion-loop" data-parallax="0.04" aria-hidden="true"></div>
            <div class="eyebrow">Dual-Greenhouse Research Framework</div>
            <h1 class="hero-title">Local greenhouse monitoring through the ESP32 LAN gateway.</h1>
            <p class="hero-copy">
                ECOTwin runs on the local XAMPP server while users connect through the ESP32 Wi-Fi network.
                Sensor records, experiments, reports, and controls stay inside the LAN.
            </p>
            <div class="gateway-visual" aria-label="ECOTwin local connection flow">
                <div class="gateway-node">
                    <div class="gateway-icon phone-icon" aria-hidden="true"></div>
                    <strong>User device</strong>
                    <span>Phone or laptop joins the ESP32 Wi-Fi</span>
                </div>
                <div class="gateway-arrow" aria-hidden="true"></div>
                <div class="gateway-node">
                    <div class="gateway-icon esp-icon" aria-hidden="true"></div>
                    <strong>ESP32 gateway</strong>
                    <span>Points users to the local EcoTwin website</span>
                </div>
                <div class="gateway-arrow" aria-hidden="true"></div>
                <div class="gateway-node">
                    <div class="gateway-icon data-icon" aria-hidden="true"></div>
                    <strong>EcoTwin data</strong>
                    <span>Experiments, sensors, and reports stay organized</span>
                </div>
            </div>
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

        <section class="module-showcase" data-filter-group>
            <div class="module-showcase-head">
                <div>
                    <div class="eyebrow">Explore the system</div>
                    <h2>Find the part of EcoTwin you need</h2>
                    <p>Search or filter the main modules so first-time users can quickly understand where to monitor, manage, or export greenhouse work.</p>
                </div>
                <input class="module-search" data-filter-search type="search" placeholder="Search modules..." aria-label="Search EcoTwin modules">
            </div>
            <div class="module-filter-row" aria-label="Module filters">
                <button type="button" class="active" data-filter-value="all">All</button>
                <button type="button" data-filter-value="monitor">Monitor</button>
                <button type="button" data-filter-value="research">Research</button>
                <button type="button" data-filter-value="manage">Manage</button>
                <button type="button" data-filter-value="export">Export</button>
            </div>
            <div class="module-grid">
                <article class="module-card" data-filter-item data-filter-category="monitor">
                    <div class="module-graphic"></div>
                    <strong>Live Dashboard</strong>
                    <span>See active experiments, greenhouse condition, alerts, and latest synchronized readings.</span>
                </article>
                <article class="module-card" data-filter-item data-filter-category="research monitor">
                    <div class="module-graphic"></div>
                    <strong>Greenhouse Sensor Map</strong>
                    <span>Use visual sensor points to understand temperature, humidity, light, pH, EC, and water level.</span>
                </article>
                <article class="module-card" data-filter-item data-filter-category="research manage">
                    <div class="module-graphic"></div>
                    <strong>Experiments</strong>
                    <span>Start, track, and protect experiment ownership so each researcher sees the right data.</span>
                </article>
                <article class="module-card" data-filter-item data-filter-category="export research">
                    <div class="module-graphic"></div>
                    <strong>Reports</strong>
                    <span>Review events, compare greenhouse readings, and export research records for analysis.</span>
                </article>
                <article class="module-card" data-filter-item data-filter-category="manage">
                    <div class="module-graphic"></div>
                    <strong>Plant Library</strong>
                    <span>Manage crops and threshold ranges so readings are judged against the correct plant profile.</span>
                </article>
                <article class="module-card" data-filter-item data-filter-category="manage monitor">
                    <div class="module-graphic"></div>
                    <strong>ESP32 LAN Gateway</strong>
                    <span>Guide connected users from the ESP32 Wi-Fi network into the local EcoTwin website.</span>
                </article>
            </div>
        </section>
    </main>
</div>
<script src="js.navbar.js?v=<?= urlencode((string) @filemtime(__DIR__ . '/js.navbar.js')) ?>"></script>
</body>
</html>
