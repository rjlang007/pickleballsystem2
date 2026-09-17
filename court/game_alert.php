<?php
// ============================================================
//  FILE: court/game_engine.php
//  Starts a game session for the next PLAYERS_PER_GAME queued.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required.', 'code' => 'method_not_allowed']);
    exit;
}

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.', 'code' => 'unauthorized']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true);
$courtId = isset($body['court_id']) ? (int)$body['court_id'] : 1;

$db = getDB();

// Idempotency: already active?
$existing = $db->prepare("
    SELECT id, started_at, duration_mins FROM falcon.game_sessions
    WHERE court_id=? AND status='active' LIMIT 1
");
$existing->execute([$courtId]);
$active = $existing->fetch();
if ($active) {
    $endTs   = strtotime($active['started_at']) + ($active['duration_mins'] * 60);
    $remSecs = max(0, $endTs - time());
    echo json_encode([
        'status'      => 'already_active',
        'message'     => 'A game is already running on this court.',
        'session_id'  => (int)$active['id'],
        'rem_seconds' => $remSecs,
        'code'        => 'already_active',
    ]);
    exit;
}

$courtRow = $db->prepare("SELECT id, game_duration, warmup_mins, credit_cost, is_active FROM falcon.courts WHERE id=? LIMIT 1");
$courtRow->execute([$courtId]);
$court = $courtRow->fetch();

if (!$court) {
    echo json_encode(['status' => 'error', 'message' => 'Court not found.', 'code' => 'court_not_found']);
    exit;
}
if (!$court['is_active']) {
    echo json_encode(['status' => 'error', 'message' => 'Court is currently closed.', 'code' => 'court_closed']);
    exit;
}

$duration = (int)$court['game_duration'];

try {
    $db->beginTransaction();

    // Lock & fetch next PLAYERS_PER_GAME from queue
    $queueStmt = $db->query("
        SELECT gq.id AS queue_id, gq.user_id,
               u.username, u.full_name,
               COALESCE(u.display_name, u.full_name) AS screen_name,
               COALESCE(u.show_display_name, TRUE) AS show_name,
               pp.is_active AS pass_active, pp.expires_at
        FROM falcon.game_queue gq
        JOIN falcon.users u  ON u.id = gq.user_id
        JOIN falcon.player_passes pp ON pp.user_id = gq.user_id
        WHERE gq.session_id IS NULL
        ORDER BY gq.joined_at ASC
        LIMIT " . PLAYERS_PER_GAME . "
        FOR UPDATE OF gq
    ");
    $players = $queueStmt->fetchAll();

    if (count($players) < PLAYERS_PER_GAME) {
        $db->rollBack();
        $needed = PLAYERS_PER_GAME - count($players);
        echo json_encode([
            'status'  => 'waiting',
            'message' => count($players) . '/' . PLAYERS_PER_GAME . ' players. Need ' . $needed . ' more.',
            'count'   => count($players),
            'needed'  => $needed,
            'code'    => 'insufficient_players',
        ]);
        exit;
    }

    // Verify passes still valid
    $invalid = [];
    foreach ($players as $p) {
        $isPlaceholder = strtotime($p['expires_at']) > strtotime('+50 years');
        $isExpired     = !$isPlaceholder && strtotime($p['expires_at']) < time();
        if (!$p['pass_active'] || $isExpired) {
            $invalid[] = $p['username'];
        }
    }
    if (!empty($invalid)) {
        $db->rollBack();
        echo json_encode([
            'status'  => 'error',
            'message' => 'Pass expired/inactive for: ' . implode(', ', $invalid) . '. They must top up first.',
            'invalid' => $invalid,
            'code'    => 'invalid_passes',
        ]);
        exit;
    }

    // Create game session
    $sesStmt = $db->prepare("
        INSERT INTO falcon.game_sessions (court_id, status, duration_mins, started_at, created_at)
        VALUES (?, 'active', ?, NOW(), NOW())
        RETURNING id, started_at
    ");
    $sesStmt->execute([$courtId, $duration]);
    $sesRow    = $sesStmt->fetch();
    $sessionId = (int)$sesRow['id'];
    $startedAt = $sesRow['started_at'];
    $endTime   = date('Y-m-d H:i:s', strtotime($startedAt) + $duration * 60);

    // Insert game_players — no joined_at column in this table
    $insPlayer = $db->prepare("
        INSERT INTO falcon.game_players (session_id, user_id, credits_charged, payment_status)
        VALUES (?, ?, 0, 'paid')
    ");
    $linkQueue = $db->prepare("UPDATE falcon.game_queue SET session_id=? WHERE id=?");
    $notify    = $db->prepare("
        INSERT INTO falcon.notifications (user_id, type, title, message, created_at)
        VALUES (?, 'success', ?, ?, NOW())
    ");

    $names     = array_column($players, 'full_name');
    $playerOut = [];

    foreach ($players as $p) {
        $insPlayer->execute([$sessionId, $p['user_id']]);
        $linkQueue->execute([$sessionId, $p['queue_id']]);
        $others = implode(', ', array_filter($names, fn($n) => $n !== $p['full_name']));
        $notify->execute([
            $p['user_id'],
            '🎮 Game Started!',
            "Your {$duration}-min game has begun! Court #{$courtId}. Playing with: {$others}.",
        ]);
        $playerOut[] = [
            'user_id'      => (int)$p['user_id'],
            'username'     => $p['username'],
            'full_name'    => $p['full_name'],
            'display_name' => ($p['show_name'] ? ($p['screen_name'] ?: $p['full_name']) : null),
        ];
    }

    // Clear warmup timestamp now that game has started
    try {
        $db->exec("DELETE FROM falcon.site_content WHERE section = 'warmup'");
    } catch (PDOException $ignored) {}

    $db->commit();

    echo json_encode([
        'status'     => 'started',
        'message'    => 'Game started! ' . PLAYERS_PER_GAME . ' players — ' . $duration . ' min session.',
        'session_id' => $sessionId,
        'duration'   => $duration,
        'started_at' => $startedAt,
        'end_time'   => $endTime,
        'end_ts'     => strtotime($endTime),
        'players'    => $playerOut,
        'court_id'   => $courtId,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('game_engine: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error starting game.',
        'detail'  => $e->getMessage(),   // visible in Network tab during dev
        'code'    => 'db_error',
    ]);
}