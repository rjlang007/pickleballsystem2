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
    $requiredTables = [
        'users', 'courts', 'game_queue', 'reservations', 'notifications',
        'activity_logs', 'court_reservations', 'food_items', 'food_orders',
        'food_order_items', 'food_categories', 'tournaments', 'tournament_matches',
    ];
    $missingTables = [];
    $tableCheck = $db->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = 'falcon' AND table_name = ?"
    );
    foreach ($requiredTables as $table) {
        $tableCheck->execute([$table]);
        if (!$tableCheck->fetchColumn()) {
            $missingTables[] = $table;
        }
    }

    $requiredColumns = [
        'users' => ['show_display_name'],
        'reservations' => ['payment_verified_at', 'cancel_reason'],
        'food_orders' => ['rejection_reason', 'reviewed_by', 'reviewed_at'],
        'tournament_matches' => ['score_player1', 'score_player2'],
    ];
    $missingColumns = [];
    $columnCheck = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'falcon' AND table_name = ? AND column_name = ?"
    );
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnCheck->execute([$table, $column]);
            if (!$columnCheck->fetchColumn()) {
                $missingColumns[] = $table . '.' . $column;
            }
        }
    }

    if ($missingTables || $missingColumns) {
        $checks['database'] = [
            'status' => 'error',
            'message' => 'Database schema is not ready',
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
        ];
        $overallStatus = 'unhealthy';
    } else {
        $checks['database'] = ['status' => 'ok', 'message' => 'Database connection and schema ready'];
    }
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
    'version' => '2.1.0',
];

http_response_code($overallStatus === 'healthy' ? 200 : ($overallStatus === 'degraded' ? 200 : 503));
echo json_encode($response, JSON_PRETTY_PRINT);