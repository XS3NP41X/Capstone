<?php
// ============================================================================
// ECOTWIN DATABASE CONNECTION
// ============================================================================

if (is_file(__DIR__ . '/../config/cloud_database.php')) {
    require_once __DIR__ . '/../config/cloud_database.php';
}

defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'ecotwin_db');
defined('DB_USER') || define('DB_USER', 'root');        // Change to your MySQL username
defined('DB_PASS') || define('DB_PASS', '');            // Change to your MySQL password
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');

date_default_timezone_set(APP_TIMEZONE);

// Returns the shared PDO connection for application queries.
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// Sends a JSON response and stops the current request.
// Sends a JSON response and stops the current request.
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Sanitizes a string before it is stored or displayed.
function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}
