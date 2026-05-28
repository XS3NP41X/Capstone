<?php
// ============================================================================
// ECOTWIN — DATABASE CONFIGURATION
// ============================================================================

defined('DB_HOST') || define('DB_HOST',    'localhost');
defined('DB_PORT') || define('DB_PORT',    3306);
defined('DB_NAME') || define('DB_NAME',    'ecotwin_db');
defined('DB_USER') || define('DB_USER',    'root');        // Change to your MySQL username
defined('DB_PASS') || define('DB_PASS',    '');            // Change to your MySQL password
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');

date_default_timezone_set(APP_TIMEZONE);

/**
 * Returns a singleton PDO connection.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            // Do NOT expose raw PDO errors in production
            error_log('Database connection error: ' . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed.']));
        }
    }

    return $pdo;
}
