<?php
// ============================================================
//  FILE: court/game_engine.php  (MODIFIED — multi-court v3)
//
//  CHANGES vs v2:
//
//  1. Warmup clear now targets the per-court section key
//     ('warmup_court_{id}') introduced by game_ticker v4,
//     in addition to the legacy 'warmup' section.
//     Previously only cleared 'warmup' section rows, leaving
//     stale warmup_court_{id} rows that caused the warmup
//     countdown to keep running after a force-start.
//
//  PRESERVED from v2:
//   • Court validation (is_active, is_maintenance)
//   • Queue query filtered by court_id (IS NULL fallback)
//   • Idempotency check (already_active on same court)
//   • PLAYERS_PER_GAME lock + FOR UPDATE
//   • game_sessions INSERT RETURNING
//   • game_players + game_queue linking
//   • Notification message names the court
//   • Full JSON response shape
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

// ── Validate court exists, active, not maintenance ────────────
$courtRow = $db->prepare("
    SELECT id, name, short_code, game_duration, warmup_mins,
           credit_cost, is_active, is_maintenance
    FROM falcon.courts
    WHERE id = ?
    LIMIT 1
");
$courtRow->execute([$courtId]);
$court = $courtRow->fetch();

if (!$court) {
    echo json_encode([
        'status'  => 'error',
        'code'    => 'court_not_found',
        'message' => 'Court not found.',
    ]);
    exit;
}

if (!$court['is_active'] || $court['is_maintenance']) {
    echo json_encode([
        'status'  => 'error',
        'code'    => 'court_unavailable',
        'message' => 'Court is under maintenance or inactive.',
    ]);
    exit;
}

$duration = (int)$court['game_duration'];

// ── Idempotency: is a game already active on this court? ──────
$existing = $db->prepare("
    SELECT id, started_at, duration_mins FROM falcon.game_sessions
    WHERE court_id = ? AND status = 'active'
    LIMIT 1
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

try {
    $db->beginTransaction();

    // ── Lock & fetch next PLAYERS_PER_GAME filtered by court_id ──
    // Includes court_id IS NULL for backward compat with pre-migration rows
    $queueStmt = $db->prepare("
        SELECT gq.id AS queue_id, gq.user_id,
               u.username, u.full_name,
               COALESCE(u.display_name, u.full_name) AS screen_name,
               COALESCE(u.show_display_name, TRUE)   AS show_name
        FROM falcon.game_queue gq
        JOIN falcon.users u ON u.id = gq.user_id
        WHERE gq.session_id IS NULL
          AND (gq.court_id = ? OR gq.court_id IS NULL)
        ORDER BY gq.joined_at ASC
        LIMIT " . PLAYERS_PER_GAME . "
        FOR UPDATE OF gq
    ");
    $queueStmt->execute([$courtId]);
    $players = $queueStmt->fetchAll();

    if (count($players) < PLAYERS_PER_GAME) {
        $db->rollBack();
        $needed = PLAYERS_PER_GAME - count($players);
        echo json_encode([
            'status'  => 'waiting',
            'message' => count($players) . '/' . PLAYERS_PER_GAME . ' players queued for this court. Need ' . $needed . ' more.',
            'count'   => count($players),
            'needed'  => $needed,
            'code'    => 'insufficient_players',
        ]);
        exit;
    }

    // ── Create game session ────────────────────────────────────
    $sesStmt = $db->prepare("
        INSERT INTO falcon.game_sessions
            (court_id, status, credit_cost, duration_mins, started_at, created_at)
        VALUES (?, 'active', ?, ?, NOW(), NOW())
        RETURNING id, started_at
    ");
    $sesStmt->execute([$courtId, (int)$court['credit_cost'], $duration]);
    $sesRow    = $sesStmt->fetch();
    $sessionId = (int)$sesRow['id'];
    $startedAt = $sesRow['started_at'];
    $endTime   = date('Y-m-d H:i:s', strtotime($startedAt) + $duration * 60);

    // ── Assign players ─────────────────────────────────────────
    $insPlayer = $db->prepare("
        INSERT INTO falcon.game_players (session_id, user_id, credits_charged, payment_status, joined_at)
        VALUES (?, ?, ?, 'paid', NOW())
    ");
    $linkQueue = $db->prepare("
        UPDATE falcon.game_queue SET session_id = ? WHERE id = ?
    ");
    $notify = $db->prepare("
        INSERT INTO falcon.notifications (user_id, title, message, type, created_at)
        VALUES (?, ?, ?, 'success', NOW())
    ");

    $names     = array_column($players, 'full_name');
    $playerOut = [];
    $courtName = $court['name'];
    $shortCode = $court['short_code'];

    foreach ($players as $p) {
        $insPlayer->execute([$sessionId, $p['user_id'], (float)$court['credit_cost']]);
        $linkQueue->execute([$sessionId, $p['queue_id']]);

        $others = implode(', ', array_filter($names, fn($n) => $n !== $p['full_name']));

        $notify->execute([
            $p['user_id'],
            '🎮 Game Started!',
            "Your {$duration}-min game has begun on {$courtName} ({$shortCode})! Playing with: {$others}.",
        ]);

        $displayName = ($p['show_name'] ? ($p['screen_name'] ?: $p['full_name']) : null);
        $playerOut[] = [
            'user_id'      => (int)$p['user_id'],
            'username'     => $p['username'],
            'full_name'    => $p['full_name'],
            'display_name' => $displayName,
        ];
    }

    // ── FIX v3: Clear warmup for this court (per-court + legacy) ──
    // game_ticker v4 uses 'warmup_court_{id}' section keys.
    // The old v2 code only cleared 'warmup' section rows, leaving
    // stale 'warmup_court_{id}' rows that kept the warmup timer
    // running on the admin screen after a force/manual start.
    try {
        $db->prepare("
            DELETE FROM falcon.site_content
            WHERE section IN ('warmup', :per_court)
        ")->execute([':per_court' => 'warmup_court_' . $courtId]);
    } catch (PDOException $e) {
        error_log('game_engine: warmup clear failed: ' . $e->getMessage());
    }

    $db->commit();

    echo json_encode([
        'status'     => 'started',
        'message'    => 'Game started! ' . PLAYERS_PER_GAME . ' players — ' . $duration . ' min session on ' . $courtName . '.',
        'session_id' => $sessionId,
        'duration'   => $duration,
        'started_at' => $startedAt,
        'end_time'   => $endTime,
        'end_ts'     => strtotime($startedAt) + ($duration * 60),
        'players'    => $playerOut,
        'court_id'   => $courtId,
        'court_name' => $courtName,
        'short_code' => $shortCode,
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('game_engine: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error starting game.',
        'code'    => 'db_error',
    ]);
}