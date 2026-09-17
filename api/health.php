<?php
// ============================================================
//  FILE: api/health.php
//
//  Health check endpoint for monitoring.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json; charset=UTF-8');

$checks = [];
$overallStatus = 'healthy';

// Database check
try {
    $db = getDB();
    $db->query('SELECT 1');
    $checks['database'] = ['status' => 'ok', 'message' => 'Database connection successful'];
} catch (Exception $e) {
    error_log('[health] Database check failed: ' . $e->getMessage());
    $checks['database'] = ['status' => 'error', 'message' => 'Database connection failed'];
    $overallStatus = 'unhealthy';
}

// Cache check
$cache = getCache();
if ($cache->isAvailable()) {
    try {
        $cache->set('health_check', 'ok', 10);
        $value = $cache->get('health_check');
        if ($value === 'ok') {
            $checks['cache'] = ['status' => 'ok', 'message' => 'Redis cache operational'];
        } else {
            $checks['cache'] = ['status' => 'error', 'message' => 'Cache read/write failed'];
            $overallStatus = 'degraded';
        }
    } catch (Exception $e) {
        error_log('[health] Cache check failed: ' . $e->getMessage());
        $checks['cache'] = ['status' => 'error', 'message' => 'Cache connection failed'];
        $overallStatus = 'degraded';
    }
} else {
    $checks['cache'] = ['status' => 'warning', 'message' => 'Cache not available (optional)'];
}

// File system check
$writableDirs = ['uploads', 'logs', 'storage'];
foreach ($writableDirs as $dir) {
    $path = __DIR__ . '/../' . $dir;
    if (is_writable($path)) {
        $checks['filesystem_' . $dir] = ['status' => 'ok', 'message' => "{$dir} directory writable"];
    } else {
        $checks['filesystem_' . $dir] = ['status' => 'error', 'message' => "{$dir} directory not writable"];
        $overallStatus = 'unhealthy';
    }
}

// System info
$checks['system'] = [
    'status' => 'ok',
    'message' => 'System info retrieved',
    'php_version' => PHP_VERSION,
    'memory_usage' => memory_get_peak_usage(true),
    'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
];

$response = [
    'status' => $overallStatus,
    'timestamp' => date('c'),
    'checks' => $checks,
    'version' => '2.0.0', // Update as needed
];

http_response_code($overallStatus === 'healthy' ? 200 : ($overallStatus === 'degraded' ? 200 : 503));
echo json_encode($response, JSON_PRETTY_PRINT);