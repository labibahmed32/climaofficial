<?php
/**
 * Multi-Tenant Shaver - Configuration
 */

// ================================================================
// DATABASE CREDENTIALS (Update these for your hosting)
// ================================================================
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'u284953560_shaver');
define('DB_USER', 'u284953560_shaver');
define('DB_PASS', 'Labib@5544');

// ================================================================
// TIMEZONE
// ================================================================
date_default_timezone_set('Asia/Karachi');

// ================================================================
// ERROR REPORTING
// ================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ================================================================
// CORS HEADERS
// ================================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ================================================================
// DATABASE CONNECTION (Singleton)
// ================================================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            // Set MySQL session timezone to PKT so CURRENT_TIMESTAMP and date filters align
            $pdo->exec("SET time_zone = '+05:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'Database connection failed']));
        }
    }
    return $pdo;
}
