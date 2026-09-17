<?php
// ============================================================
//  FILE: api/offline_sync.php
//
//  Offline sync endpoint for mobile apps.
//
//  Allows mobile apps to sync data when coming back online.
//
//  POST /api/offline_sync.php
//  Body: { scans: [...], timestamp: ... }
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=UTF-8');

// Auth required
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db = getDB();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$scans = $body['scans'] ?? [];
$clientTimestamp = $body['timestamp'] ?? time();

if (!is_array($scans)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

$processed = 0;
$errors = [];

$db->beginTransaction();

try {
    foreach ($scans as $scan) {
        $qrData = $scan['qr_data'] ?? '';
        $scanTime = $scan['timestamp'] ?? time();

        if (!$qrData) continue;

        // Process offline scan (similar to process_scan.php logic)
        // This is simplified - in real implementation, validate QR and process

        // Log the offline scan
        $stmt = $db->prepare("
            INSERT INTO falcon.scan_logs
                (user_id, qr_data, scanned_at, source, status)
            VALUES (?, ?, to_timestamp(?), 'mobile_offline', 'processed')
        ");
        $stmt->execute([$userId, $qrData, $scanTime]);

        $processed++;
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'errors' => $errors,
        'server_timestamp' => time(),
    ]);

} catch (Exception $e) {
    $db->rollBack();
    error_log('[offline_sync] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Sync failed',
        'message' => $e->getMessage(),
    ]);
}