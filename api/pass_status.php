<?php
// ============================================================
//  FILE: api/pass_status.php  (NEW)
//  Lightweight endpoint polled by player/my_qr.php every 5s
//  Returns whether the current player's pass is active.
//  Used so the QR page can update without a full page reload
//  when a player tops up via another device/tab.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    echo json_encode(['is_active' => false, 'reason' => 'not_logged_in']);
    exit;
}

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$stmt = $db->prepare("
    SELECT is_active, expires_at,
           COALESCE(w.balance, 0) AS balance
    FROM falcon.player_passes pp
    LEFT JOIN falcon.wallets w ON w.user_id = pp.user_id
    WHERE pp.user_id = ?
    LIMIT 1
");
$stmt->execute([$uid]);
$pass = $stmt->fetch();

if (!$pass) {
    echo json_encode(['is_active' => false, 'reason' => 'no_pass']);
    exit;
}

$isPlaceholder = strtotime($pass['expires_at']) > strtotime('+50 years');
$isExpired     = !$isPlaceholder && strtotime($pass['expires_at']) < time();

echo json_encode([
    'is_active'  => (bool)$pass['is_active'] && !$isExpired,
    'balance'    => (float)$pass['balance'],
    'expires_at' => $pass['expires_at'],
    'is_expired' => $isExpired,
]);