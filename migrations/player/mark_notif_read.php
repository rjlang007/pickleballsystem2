<?php
// ============================================================
//  FILE: player/mark_notif_read.php
//  Marks a notification as read — called via fetch() from dashboard
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';  // ← REQUIRED: defines requireLogin()
requireLogin();

header('Content-Type: application/json');

// Accept both JSON body and POST form
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = filter_var($body['id'] ?? $_POST['id'] ?? 0, FILTER_VALIDATE_INT);

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid notification ID']);
    exit;
}

$db = getDB();

// Scope to current user — prevents reading other users' notifications
$stmt = $db->prepare("
    UPDATE falcon.notifications
    SET is_read = TRUE
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$id, $_SESSION['user_id']]);

echo json_encode(['ok' => $stmt->rowCount() > 0]);