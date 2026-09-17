<?php
/**
 * API endpoint to get current user's QR token
 * Returns JSON with the token needed for scanning
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

// Force JSON response
header('Content-Type: application/json');

try {
    requireLogin();
    $db  = getDB();
    $uid = $_SESSION['user_id'] ?? null;

    if (!$uid) {
        throw new Exception('Not logged in');
    }

    $stmt = $db->prepare(
        "SELECT p.qr_token, COALESCE(w.balance, 0) AS balance
         FROM falcon.player_passes p
         LEFT JOIN falcon.wallets w ON w.user_id = p.user_id
         WHERE p.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$uid]);
    $player = $stmt->fetch();

    if (!$player || empty($player['qr_token'])) {
        throw new Exception('Player token not found');
    }

    echo json_encode([
        'success' => true,
        'token' => $player['qr_token'],
        'has_balance' => ((float)$player['balance']) > 0
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
