<?php
// ============================================================
//  FILE: config/production.php
//
//  Production-specific configuration overrides.
// ============================================================

// Production database settings
$prodDbHost = getenv('PGHOST') ?: getenv('DB_HOST');
$prodDbPort = getenv('PGPORT') ?: getenv('DB_PORT');
$prodDbName = getenv('PGDATABASE') ?: getenv('DB_NAME');
$prodDbUser = getenv('PGUSER') ?: getenv('DB_USER');
$prodDbPass = getenv('PGPASSWORD') ?: getenv('DB_PASS');
if (!$prodDbHost && getenv('DATABASE_URL')) {
    $url = parse_url(getenv('DATABASE_URL'));
    $prodDbHost = $url['host'] ?? '';
    $prodDbPort = $url['port'] ?? $prodDbPort ?: 5432;
    $prodDbName = ltrim($url['path'] ?? '', '/');
    $prodDbUser = $url['user'] ?? $prodDbUser;
    $prodDbPass = $url['pass'] ?? $prodDbPass;
}

define('DB_HOST', $prodDbHost ?: 'localhost');
define('DB_PORT', $prodDbPort ?: '5432');
define('DB_NAME', $prodDbName ?: 'railway');
define('DB_USER', $prodDbUser ?: 'postgres');
define('DB_PASS', $prodDbPass ?: '');

// Redis for production
define('REDIS_URL', getenv('REDIS_URL') ?: 'redis://localhost:6379');

// Security settings
define('SESSION_SECURE', true);
define('SESSION_HTTPONLY', true);
define('SESSION_SAMESITE', 'Strict');

// Error reporting
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/var/log/php/falcon_errors.log');

// Performance settings
ini_set('opcache.enable', '1');
ini_set('opcache.memory_consumption', '256');
ini_set('opcache.max_accelerated_files', '7963');
ini_set('opcache.revalidate_freq', '0');

// Rate limiting (stricter in production)
define('API_RATE_LIMIT', 100); // requests per minute
define('API_RATE_WINDOW', 60); // seconds