<?php
// ============================================================
//  FILE: api/notifications.php
//  AJAX endpoint — notification bell
//
//  GET  ?unread_count=1          → {"ok":true,"count":N}
//  GET  ?list=1&limit=10         → {"ok":true,"notifications":[...],"unread_count":N}
//  POST {"action":"mark_read","id":N}
//  POST {"action":"mark_all_read"}
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once __DIR__ . '/../includes/api_response.php';

// ── Rate limiting — 100 req/min per IP ───────────────────────
if (shouldRateLimit($_SERVER['REMOTE_ADDR'], 'api_' . basename(__FILE__), 100, 60)) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Too many requests. Try again in 1 minute.']));
}

if (!isLoggedIn()) {
    apiError('UNAUTHORIZED', 'Not logged in.', [], 401);
}

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Helper: time-ago string ──────────────────────────────────
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d', strtotime($datetime));
}

// ── GET: unread count only (lightweight, called every 30s) ──
if (isset($_GET['unread_count'])) {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM falcon.notifications
        WHERE user_id = ? AND is_read = FALSE
    ");
    $stmt->execute([$uid]);
    echo json_encode(['ok' => true, 'count' => (int)$stmt->fetchColumn()]);
    exit;
}

// ── GET: full list (called on bell click) ────────────────────
if (isset($_GET['list'])) {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));

    $stmt = $db->prepare("
        SELECT id, title, message, type, is_read, link, created_at
        FROM falcon.notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$uid, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['time_ago'] = timeAgo($r['created_at']);
        $r['is_read']  = (bool)$r['is_read'];
    }
    unset($r);

    $cStmt = $db->prepare("
        SELECT COUNT(*)
        FROM falcon.notifications
        WHERE user_id = ? AND is_read = FALSE
    ");
    $cStmt->execute([$uid]);

    echo json_encode([
        'ok'            => true,
        'notifications' => $rows,
        'unread_count'  => (int)$cStmt->fetchColumn(),
    ]);
    exit;
}

// ── POST: mark read / mark all read ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? ($_POST['action'] ?? '');

    if ($action === 'mark_read') {
        $id = (int)($body['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("
                UPDATE falcon.notifications
                SET is_read = TRUE
                WHERE id = ? AND user_id = ?
            ")->execute([$id, $uid]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $db->prepare("
            UPDATE falcon.notifications
            SET is_read = TRUE
            WHERE user_id = ? AND is_read = FALSE
        ")->execute([$uid]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

apiError('VALIDATION_ERROR', 'Invalid request.');