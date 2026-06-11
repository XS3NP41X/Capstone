<?php

// Ensures the user preferences table exists before preferences are loaded or saved.
function ecotwinEnsureUserPreferencesTable(PDO $db): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_preferences (
            user_id INT UNSIGNED NOT NULL,
            preference_key VARCHAR(100) NOT NULL,
            preference_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, preference_key),
            KEY idx_user_preferences_user_id (user_id),
            CONSTRAINT fk_user_preferences_user
                FOREIGN KEY (user_id) REFERENCES users(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

// Ensures the user profile details table exists before profile data is loaded or saved.
function ecotwinEnsureUserProfileDetailsTable(PDO $db): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_profile_details (
            user_id INT UNSIGNED NOT NULL,
            display_name VARCHAR(100) NULL,
            avatar_url VARCHAR(255) NULL,
            bio TEXT NULL,
            phone_number VARCHAR(30) NULL,
            address_line TEXT NULL,
            birthday DATE NULL,
            gender VARCHAR(40) NULL,
            pronouns VARCHAR(40) NULL,
            location_label VARCHAR(120) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_user_profile_details_user
                FOREIGN KEY (user_id) REFERENCES users(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

// Normalizes a preference value against the allowed options.
function ecotwinPreferenceOption(string $value, array $allowed, string $fallback): string
{
    return in_array($value, $allowed, true) ? $value : $fallback;
}

// Returns the default preference values for a new user.
function ecotwinDefaultPreferences(): array
{
    return [
        'theme_mode' => 'light',
        'content_layout' => 'grid',
        'font_size' => 'medium',
        'font_style' => 'sans',
        'language' => 'en-US',
        'date_format' => 'M j, Y g:i A',
        'timezone' => 'Asia/Manila',
        'notify_sms' => '0',
        'notify_push' => '1',
        'notify_web' => '1',
    ];
}

// Returns the list of supported language options.
function ecotwinAllowedLanguages(): array
{
    return ['en-US', 'en-GB', 'fil-PH', 'es-ES', 'fr-FR', 'zh-TW', 'ja-JP'];
}

// Loads the saved preferences for the requested user.
function ecotwinLoadUserPreferences(PDO $db, int $userId): array
{
    $preferences = ecotwinDefaultPreferences();

    if ($userId <= 0) {
        return $preferences;
    }

    ecotwinEnsureUserPreferencesTable($db);

    $stmt = $db->prepare(
        "SELECT preference_key, preference_value FROM user_preferences WHERE user_id = ?"
    );
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll() as $row) {
        $preferences[$row['preference_key']] = (string) $row['preference_value'];
    }

    $preferences['theme_mode'] = ecotwinPreferenceOption($preferences['theme_mode'], ['light', 'dark', 'high-contrast'], 'light');
    $preferences['content_layout'] = ecotwinPreferenceOption($preferences['content_layout'], ['grid', 'list'], 'grid');
    $preferences['font_size'] = ecotwinPreferenceOption($preferences['font_size'], ['small', 'medium', 'large'], 'medium');
    $preferences['font_style'] = ecotwinPreferenceOption($preferences['font_style'], ['sans', 'serif', 'monospace'], 'sans');
    $preferences['language'] = ecotwinPreferenceOption($preferences['language'], ecotwinAllowedLanguages(), 'en-US');
    $preferences['date_format'] = ecotwinPreferenceOption($preferences['date_format'], ['M j, Y g:i A', 'd/m/Y H:i', 'Y-m-d H:i'], 'M j, Y g:i A');
    $preferences['timezone'] = ecotwinPreferenceOption($preferences['timezone'], ['Asia/Manila', 'Asia/Taipei', 'UTC'], 'Asia/Manila');
    $preferences['notify_sms'] = $preferences['notify_sms'] === '1' ? '1' : '0';
    $preferences['notify_push'] = $preferences['notify_push'] === '1' ? '1' : '0';
    $preferences['notify_web'] = $preferences['notify_web'] === '1' ? '1' : '0';

    return $preferences;
}

// Builds the body class string for the active preference theme.
function ecotwinPreferenceBodyClass(array $preferences): string
{
    return trim(sprintf(
        'theme-%s layout-%s font-size-%s font-style-%s',
        $preferences['theme_mode'] ?? 'light',
        $preferences['content_layout'] ?? 'grid',
        $preferences['font_size'] ?? 'medium',
        $preferences['font_style'] ?? 'sans'
    ));
}

// Loads the extended profile details for the requested user.
function ecotwinLoadUserProfileDetails(PDO $db, int $userId): array
{
    $defaults = [
        'display_name' => '',
        'avatar_url' => '',
        'bio' => '',
        'phone_number' => '',
        'address_line' => '',
        'birthday' => '',
        'gender' => '',
        'pronouns' => '',
        'location_label' => '',
    ];

    if ($userId <= 0) {
        return $defaults;
    }

    ecotwinEnsureUserProfileDetailsTable($db);

    $stmt = $db->prepare("SELECT * FROM user_profile_details WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $defaults;
    }

    foreach ($defaults as $key => $value) {
        $defaults[$key] = isset($row[$key]) ? (string)$row[$key] : $value;
    }

    return $defaults;
}

// Returns the translation map used by the preference screens.
function ecotwinTranslations(): array
{
    return [
        'en-US' => [
            'nav.dashboard' => 'Dashboard',
            'nav.index' => 'About',
            'nav.experiments' => 'Experiments',
            'nav.greenhouses' => 'Greenhouses',
            'nav.reports' => 'Reports',
            'nav.settings' => 'Settings',
            'nav.admin' => 'Admin',
            'menu.profile_settings' => 'Profile Settings',
            'menu.preferences' => 'Preferences',
            'menu.logout' => 'Logout',
            'settings.title' => 'System Settings',
            'settings.subtitle.admin' => 'System overview and configuration status',
            'settings.subtitle.user' => 'Configuration and hardware status (Read-only for Researchers)',
            'settings.forms.profile' => 'Profile Settings',
            'settings.forms.preferences' => 'Preference Settings',
            'page.experiments.title' => 'Experiments',
            'page.experiments.subtitle' => 'Manage and monitor your greenhouse experiments',
            'page.greenhouses.title' => 'Greenhouse Monitoring',
            'page.greenhouses.subtitle' => 'Real-time environmental data from ecotwin_db, sensor readings, actuator control, and automation thresholds',
            'page.reports.title' => 'Reports and Analytics',
            'page.reports.subtitle' => 'View system events, alerts, and export experimental data',
            'page.admin.title' => 'Admin Control Panel',
            'page.admin.subtitle' => 'Plant configuration, greenhouse thresholds, and system management',
            'admin.badge' => 'Admin',
            'admin.access' => 'Administrator Access',
            'dashboard.title' => 'Dashboard',
            'dashboard.subtitle' => 'Live overview of greenhouse operations and experiment health',
            'dashboard.summary.active_experiment' => 'Active Experiment',
            'dashboard.summary.sensors_online' => 'Sensors Online',
            'dashboard.summary.last_data_sync' => 'Last Data Sync',
            'dashboard.summary.system_status' => 'System Status',
            'dashboard.analytics.title' => 'Data Analytics',
            'dashboard.analytics.subtitle' => 'Seven-day operational analytics based on live sensor readings.',
            'dashboard.analytics.readings' => 'Readings (7 Days)',
            'dashboard.analytics.avg_temp' => 'Avg Temperature',
            'dashboard.analytics.avg_humidity' => 'Avg Humidity',
            'dashboard.analytics.avg_light' => 'Avg Light',
            'dashboard.analytics.top_greenhouse' => 'Top Greenhouse',
            'dashboard.analytics.no_data' => 'Not enough recent data',
        ],
        'en-GB' => [
            'dashboard.analytics.readings' => 'Readings (7 Days)',
            'dashboard.analytics.avg_humidity' => 'Avg Humidity',
            'dashboard.summary.sensors_online' => 'Sensors Online',
        ],
        'fil-PH' => [
            'nav.dashboard' => 'Dashboard',
            'nav.index' => 'About',
            'nav.experiments' => 'Mga Eksperimento',
            'nav.greenhouses' => 'Mga Greenhouse',
            'nav.reports' => 'Mga Ulat',
            'nav.settings' => 'Mga Setting',
            'nav.admin' => 'Admin',
            'menu.profile_settings' => 'Mga Setting ng Profile',
            'menu.preferences' => 'Mga Preference',
            'menu.logout' => 'Mag-logout',
            'settings.title' => 'Mga Setting ng Sistema',
            'settings.subtitle.admin' => 'Pangkalahatang tanaw at katayuan ng configuration ng sistema',
            'settings.subtitle.user' => 'Katayuan ng configuration at hardware (read-only para sa researchers)',
            'settings.forms.profile' => 'Mga Setting ng Profile',
            'settings.forms.preferences' => 'Mga Preference',
            'page.experiments.title' => 'Mga Eksperimento',
            'page.experiments.subtitle' => 'Pamahalaan at subaybayan ang iyong mga greenhouse experiment',
            'page.greenhouses.title' => 'Pagsubaybay ng Greenhouse',
            'page.greenhouses.subtitle' => 'Real-time na datos mula sa ecotwin_db, sensor readings, actuator control, at automation thresholds',
            'page.reports.title' => 'Mga Ulat at Analytics',
            'page.reports.subtitle' => 'Tingnan ang system events, alerts, at mag-export ng experimental data',
            'page.admin.title' => 'Admin Control Panel',
            'page.admin.subtitle' => 'Plant configuration, greenhouse thresholds, at system management',
            'admin.badge' => 'Admin',
            'admin.access' => 'Administrator Access',
            'dashboard.title' => 'Dashboard',
            'dashboard.subtitle' => 'Live na buod ng operasyon ng greenhouse at kalagayan ng eksperimento',
            'dashboard.summary.active_experiment' => 'Aktibong Eksperimento',
            'dashboard.summary.sensors_online' => 'Mga Sensor na Online',
            'dashboard.summary.last_data_sync' => 'Huling Data Sync',
            'dashboard.summary.system_status' => 'Katayuan ng Sistema',
            'dashboard.analytics.title' => 'Pagsusuri ng Datos',
            'dashboard.analytics.subtitle' => 'Pitong-araw na analytics batay sa live sensor readings.',
            'dashboard.analytics.readings' => 'Mga Reading (7 Araw)',
            'dashboard.analytics.avg_temp' => 'Karaniwang Temperatura',
            'dashboard.analytics.avg_humidity' => 'Karaniwang Halumigmig',
            'dashboard.analytics.avg_light' => 'Karaniwang Liwanag',
            'dashboard.analytics.top_greenhouse' => 'Pinakamahusay na Greenhouse',
            'dashboard.analytics.no_data' => 'Kulang ang kamakailang datos',
        ],
        'es-ES' => [
            'nav.dashboard' => 'Panel',
            'nav.index' => 'Inicio',
            'nav.experiments' => 'Experimentos',
            'nav.greenhouses' => 'Invernaderos',
            'nav.reports' => 'Informes',
            'nav.settings' => 'Configuración',
            'nav.admin' => 'Administración',
            'menu.profile_settings' => 'Configuración del perfil',
            'menu.preferences' => 'Preferencias',
            'menu.logout' => 'Cerrar sesión',
            'settings.title' => 'Configuración del sistema',
            'settings.subtitle.admin' => 'Resumen del sistema y estado de configuración',
            'settings.subtitle.user' => 'Configuración y estado del hardware (solo lectura para investigadores)',
            'settings.forms.profile' => 'Configuración del perfil',
            'settings.forms.preferences' => 'Preferencias',
            'page.experiments.title' => 'Experimentos',
            'page.experiments.subtitle' => 'Gestiona y supervisa tus experimentos de invernadero',
            'page.greenhouses.title' => 'Monitoreo de invernaderos',
            'page.greenhouses.subtitle' => 'Datos en tiempo real de ecotwin_db, lecturas de sensores, control de actuadores y umbrales de automatizacion',
            'page.reports.title' => 'Informes y analitica',
            'page.reports.subtitle' => 'Consulta eventos del sistema, alertas y exporta datos experimentales',
            'page.admin.title' => 'Panel de administracion',
            'page.admin.subtitle' => 'Configuracion de plantas, umbrales de invernadero y gestion del sistema',
            'admin.badge' => 'Admin',
            'admin.access' => 'Acceso de administrador',
            'dashboard.title' => 'Panel',
            'dashboard.subtitle' => 'Resumen en vivo de las operaciones del invernadero y la salud del experimento',
            'dashboard.summary.active_experiment' => 'Experimento activo',
            'dashboard.summary.sensors_online' => 'Sensores en línea',
            'dashboard.summary.last_data_sync' => 'Última sincronización',
            'dashboard.summary.system_status' => 'Estado del sistema',
            'dashboard.analytics.title' => 'Analítica de datos',
            'dashboard.analytics.subtitle' => 'Analítica operativa de siete días basada en lecturas en vivo.',
            'dashboard.analytics.readings' => 'Lecturas (7 días)',
            'dashboard.analytics.avg_temp' => 'Temp. media',
            'dashboard.analytics.avg_humidity' => 'Humedad media',
            'dashboard.analytics.avg_light' => 'Luz media',
            'dashboard.analytics.top_greenhouse' => 'Mejor invernadero',
            'dashboard.analytics.no_data' => 'No hay datos recientes suficientes',
        ],
        'fr-FR' => [
            'nav.dashboard' => 'Tableau de bord',
            'nav.index' => 'Accueil',
            'nav.experiments' => 'Expériences',
            'nav.greenhouses' => 'Serres',
            'nav.reports' => 'Rapports',
            'nav.settings' => 'Paramètres',
            'nav.admin' => 'Admin',
            'menu.profile_settings' => 'Paramètres du profil',
            'menu.preferences' => 'Préférences',
            'menu.logout' => 'Se déconnecter',
            'settings.title' => 'Paramètres du système',
            'settings.forms.profile' => 'Paramètres du profil',
            'settings.forms.preferences' => 'Préférences',
            'page.experiments.title' => 'Experiences',
            'page.experiments.subtitle' => 'Gerer et surveiller vos experiences en serre',
            'page.greenhouses.title' => 'Surveillance des serres',
            'page.greenhouses.subtitle' => 'Donnees en temps reel issues de ecotwin_db, releves capteurs, controle des actionneurs et seuils automatiques',
            'page.reports.title' => 'Rapports et analyse',
            'page.reports.subtitle' => 'Consulter les evenements systeme, alertes et exporter les donnees experimentales',
            'page.admin.title' => 'Panneau d administration',
            'page.admin.subtitle' => 'Configuration des plantes, seuils des serres et gestion du systeme',
            'admin.badge' => 'Admin',
            'admin.access' => 'Acces administrateur',
            'dashboard.title' => 'Tableau de bord',
            'dashboard.summary.active_experiment' => 'Expérience active',
            'dashboard.summary.sensors_online' => 'Capteurs en ligne',
            'dashboard.summary.last_data_sync' => 'Dernière synchronisation',
            'dashboard.summary.system_status' => 'État du système',
            'dashboard.analytics.title' => 'Analyse des données',
            'dashboard.analytics.subtitle' => 'Analyse sur sept jours basée sur les relevés en direct.',
            'dashboard.analytics.readings' => 'Relevés (7 jours)',
            'dashboard.analytics.avg_temp' => 'Température moy.',
            'dashboard.analytics.avg_humidity' => 'Humidité moy.',
            'dashboard.analytics.avg_light' => 'Lumière moy.',
            'dashboard.analytics.top_greenhouse' => 'Meilleure serre',
        ],
        'zh-TW' => [
            'nav.dashboard' => '儀表板',
            'nav.index' => '首頁',
            'nav.experiments' => '實驗',
            'nav.greenhouses' => '溫室',
            'nav.reports' => '報表',
            'nav.settings' => '設定',
            'nav.admin' => '管理',
            'menu.profile_settings' => '個人設定',
            'menu.preferences' => '偏好設定',
            'menu.logout' => '登出',
            'settings.title' => '系統設定',
            'settings.forms.profile' => '個人設定',
            'settings.forms.preferences' => '偏好設定',
            'page.experiments.title' => 'Experiments',
            'page.experiments.subtitle' => 'Manage and monitor your greenhouse experiments',
            'page.greenhouses.title' => 'Greenhouse Monitoring',
            'page.greenhouses.subtitle' => 'Real-time environmental data from ecotwin_db, sensor readings, actuator control, and automation thresholds',
            'page.reports.title' => 'Reports and Analytics',
            'page.reports.subtitle' => 'View system events, alerts, and export experimental data',
            'page.admin.title' => 'Admin Control Panel',
            'page.admin.subtitle' => 'Plant configuration, greenhouse thresholds, and system management',
            'admin.badge' => 'Admin',
            'admin.access' => 'Administrator Access',
            'dashboard.title' => '儀表板',
            'dashboard.summary.active_experiment' => '進行中的實驗',
            'dashboard.summary.sensors_online' => '在線感測器',
            'dashboard.summary.last_data_sync' => '最近同步',
            'dashboard.summary.system_status' => '系統狀態',
            'dashboard.analytics.title' => '資料分析',
            'dashboard.analytics.subtitle' => '根據即時感測資料的七日營運分析。',
            'dashboard.analytics.readings' => '讀值（7天）',
            'dashboard.analytics.avg_temp' => '平均溫度',
            'dashboard.analytics.avg_humidity' => '平均濕度',
            'dashboard.analytics.avg_light' => '平均光照',
            'dashboard.analytics.top_greenhouse' => '最佳溫室',
        ],
        'ja-JP' => [
            'nav.dashboard' => 'ダッシュボード',
            'nav.index' => 'ホーム',
            'nav.experiments' => '実験',
            'nav.greenhouses' => '温室',
            'nav.reports' => 'レポート',
            'nav.settings' => '設定',
            'nav.admin' => '管理',
            'menu.profile_settings' => 'プロフィール設定',
            'menu.preferences' => '環境設定',
            'menu.logout' => 'ログアウト',
            'settings.title' => 'システム設定',
            'settings.forms.profile' => 'プロフィール設定',
            'settings.forms.preferences' => '環境設定',
            'page.experiments.title' => 'Experiments',
            'page.experiments.subtitle' => 'Manage and monitor your greenhouse experiments',
            'page.greenhouses.title' => 'Greenhouse Monitoring',
            'page.greenhouses.subtitle' => 'Real-time environmental data from ecotwin_db, sensor readings, actuator control, and automation thresholds',
            'page.reports.title' => 'Reports and Analytics',
            'page.reports.subtitle' => 'View system events, alerts, and export experimental data',
            'page.admin.title' => 'Admin Control Panel',
            'page.admin.subtitle' => 'Plant configuration, greenhouse thresholds, and system management',
            'admin.badge' => 'Admin',
            'admin.access' => 'Administrator Access',
            'dashboard.title' => 'ダッシュボード',
            'dashboard.summary.active_experiment' => '進行中の実験',
            'dashboard.summary.sensors_online' => 'オンラインセンサー',
            'dashboard.summary.last_data_sync' => '最終同期',
            'dashboard.summary.system_status' => 'システム状態',
            'dashboard.analytics.title' => 'データ分析',
            'dashboard.analytics.subtitle' => 'ライブセンサー値に基づく7日間の運用分析です。',
            'dashboard.analytics.readings' => '読み取り数（7日）',
            'dashboard.analytics.avg_temp' => '平均温度',
            'dashboard.analytics.avg_humidity' => '平均湿度',
            'dashboard.analytics.avg_light' => '平均照度',
            'dashboard.analytics.top_greenhouse' => '上位温室',
        ],
    ];
}

// Returns the translated string for the requested locale key.
function ecotwinT(string $locale, string $key, array $replacements = []): string
{
    $translations = ecotwinTranslations();
    $value = $translations[$locale][$key]
        ?? $translations['en-US'][$key]
        ?? $key;

    foreach ($replacements as $name => $replacement) {
        $value = str_replace(':' . $name, (string)$replacement, $value);
    }

    return $value;
}

// Returns a compact inline SVG icon from the shared EcoTwin icon set.
function ecotwinIcon(string $name, string $class = ''): string
{
    $paths = [
        'activity' => '<path d="M22 12h-4l-3 8-6-16-3 8H2"/>',
        'alert-circle' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'battery' => '<rect width="16" height="10" x="2" y="7" rx="2"/><path d="M22 11v2"/>',
        'check' => '<path d="m20 6-11 11-5-5"/>',
        'circle' => '<circle cx="12" cy="12" r="10"/>',
        'cpu' => '<rect width="14" height="14" x="5" y="5" rx="2"/><path d="M9 9h6v6H9z"/><path d="M9 1v4"/><path d="M15 1v4"/><path d="M9 19v4"/><path d="M15 19v4"/><path d="M1 9h4"/><path d="M1 15h4"/><path d="M19 9h4"/><path d="M19 15h4"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4.03 3 9 3s9-1.34 9-3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
        'flask' => '<path d="M9 3h6"/><path d="M10 3v6l-5 9a2 2 0 0 0 1.7 3h10.6a2 2 0 0 0 1.7-3l-5-9V3"/><path d="M8.5 14h7"/>',
        'gauge' => '<path d="M12 14l4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
        'home' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'leaf' => '<path d="M11 20A7 7 0 0 1 4 13c0-6 7-10 16-9-1 9-5 16-9 16Z"/><path d="M4 13c4 0 8-2 12-6"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M6 8h12v3a6 6 0 0 1-12 0Z"/>',
        'radio' => '<path d="M4.9 19.1a10 10 0 0 1 0-14.2"/><path d="M7.8 16.2a6 6 0 0 1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.5"/><path d="M19.1 4.9a10 10 0 0 1 0 14.2"/>',
        'refresh' => '<path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M16 8h5V3"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.73l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.09a2 2 0 0 1-1-1.73v-.51a2 2 0 0 1 1-1.72l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z"/><circle cx="12" cy="12" r="3"/>',
        'router' => '<rect width="18" height="8" x="3" y="13" rx="2"/><path d="M7 17h.01"/><path d="M11 17h.01"/><path d="M15 17h.01"/><path d="m8 13 8-8"/><path d="M16 5h4v4"/>',
        'smartphone' => '<rect width="12" height="20" x="6" y="2" rx="2"/><path d="M12 18h.01"/>',
        'sprout' => '<path d="M7 20h10"/><path d="M10 20c5.5-2.5.8-6.4 3-10"/><path d="M9.5 9.5C8.4 6.7 5.6 5.8 3 6c.2 2.9 2.3 5 5 5"/><path d="M14.5 8.5c.8-3 3.3-4.5 6.5-4.5-.3 3.3-2.2 5.4-5.5 6"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'wifi' => '<path d="M5 13a10 10 0 0 1 14 0"/><path d="M8.5 16.5a5 5 0 0 1 7 0"/><path d="M12 20h.01"/>',
        'zap' => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8Z"/>',
    ];

    $path = $paths[$name] ?? $paths['circle'];
    $classAttr = trim('ui-icon ' . $class);

    return '<svg class="' . htmlspecialchars($classAttr, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
