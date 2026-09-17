<?php
/**
 * Download QR Code as PNG file
 * Can be printed for physical scanning with T-D4
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

// Rate limiting
if (shouldRateLimit($_SERVER['REMOTE_ADDR'], 'api_' . basename(__FILE__), 100, 60)) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Too many requests. Try again in 1 minute.']));
}

try {
    requireLogin();
    $db    = getDB();
    $token = trim($_GET['token'] ?? '');

    if (!$token || strlen($token) < 10) {
        throw new Exception('Invalid token');
    }

    $uid = $_SESSION['user_id'] ?? null;
    $stmt = $db->prepare(
        "SELECT id FROM falcon.player_passes WHERE qr_token = ? AND user_id = ? LIMIT 1"
    );
    $stmt->execute([$token, $uid]);

    if (!$stmt->fetch()) {
        throw new Exception('Token not found or unauthorized');
    }

    $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=500x500&chl=' .
             urlencode($token) . '&choe=UTF-8&chld=L|1';
    $pngData = @file_get_contents($qrUrl);

    if (!$pngData || strlen($pngData) < 100) {
        $pngData = generateSimpleQRpng($token);
    }

    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="qr-' . substr($token, 0, 8) . '.png"');
    header('Content-Length: ' . strlen($pngData));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $pngData;
    exit;

} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    error_log('[download_qr.php] ' . $e->getMessage());
    echo json_encode(['error' => 'Could not generate the QR code. Please try again.']);
}

function generateSimpleQRpng($data) {
    $tempFile = sys_get_temp_dir() . '/qr_' . uniqid() . '.png';

    if (function_exists('exec') && trim(shell_exec('which qrencode'))) {
        @exec('qrencode -o ' . escapeshellarg($tempFile) . ' -s 10 -l H ' . escapeshellarg($data));
        if (file_exists($tempFile)) {
            $png = file_get_contents($tempFile);
            @unlink($tempFile);
            return $png;
        }
    }

    return file_get_contents('data://image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}
