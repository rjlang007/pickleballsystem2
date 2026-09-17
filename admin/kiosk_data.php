<?php
// ============================================================
//  FILE: admin/kiosk_data.php  — FIXED v2
//  Live data polling endpoint — called every 5s by active_game.php
//  and every 10s by kiosk.php.
//  Returns full game/warmup/queue state as JSON.
//
//  FIXES:
//   - end_ts / rem_secs are always present (0 when idle)
//   - warmup_total always included
//   - paused_rem always present (0 when not paused)
//   - server_time always present for drift correction
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$db = getDB();

// ── 1. Active game? ──────────────────────────────────────────
$gameRow = $db->query("
    SELECT gs.id, gs.status, gs.started_at, gs.duration_mins, gs.court_id,
           c.name AS court_name, c.credit_cost, c.warmup_mins
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    WHERE gs.status = 'active'
    ORDER BY gs.started_at DESC
    LIMIT 1
")->fetch();

// ── 2. Is the game paused? ───────────────────────────────────
$isPaused  = false;
$pausedRem = 0;
if ($gameRow) {
    $pauseRows = $db->query("
        SELECT key, value FROM falcon.site_content WHERE section = 'game_pause'
    ")->fetchAll();
    $pd = [];
    foreach ($pauseRows as $r) $pd[$r['key']] = $r['value'];
    if (!empty($pd['session_id']) && (int)$pd['session_id'] === (int)$gameRow['id']
        && isset($pd['paused_rem'])) {
        $isPaused  = true;
        $pausedRem = (int)$pd['paused_rem'];
    }
}

// ── 3. Queue (unassigned players only) ───────────────────────
$queueRows = $db->query("
    SELECT gq.user_id, gq.joined_at,
           COALESCE(u.display_name, u.full_name) AS screen_name,
           COALESCE(u.show_display_name, TRUE)   AS show_name,
           u.full_name, u.username
    FROM falcon.game_queue gq
    JOIN falcon.users u ON u.id = gq.user_id
    WHERE gq.session_id IS NULL
    ORDER BY gq.joined_at ASC
")->fetchAll();

$queue = [];
foreach ($queueRows as $p) {
    $queue[] = [
        'user_id'     => (int)$p['user_id'],
        'screen_name' => $p['show_name'] ? ($p['screen_name'] ?: $p['full_name']) : null,
        'full_name'   => $p['full_name'],
        'username'    => $p['username'],
    ];
}
$queueCount = count($queue);

// ── 4. Active players ────────────────────────────────────────
$players = [];
if ($gameRow) {
    $pRows = $db->prepare("
        SELECT gp.user_id,
               COALESCE(u.display_name, u.full_name) AS screen_name,
               COALESCE(u.show_display_name, TRUE)   AS show_name,
               u.full_name, u.username
        FROM falcon.game_players gp
        JOIN falcon.users u ON u.id = gp.user_id
        WHERE gp.session_id = ?
    ");
    $pRows->execute([$gameRow['id']]);
    foreach ($pRows->fetchAll() as $p) {
        $players[] = [
            'user_id'     => (int)$p['user_id'],
            'screen_name' => $p['show_name'] ? ($p['screen_name'] ?: $p['full_name']) : null,
            'full_name'   => $p['full_name'],
            'username'    => $p['username'],
        ];
    }
}

// ── 5. Warmup state ──────────────────────────────────────────
$hasWarmup   = false;
$warmupRem   = 0;
$warmupTotal = 0;

if (!$gameRow && $queueCount >= PLAYERS_PER_GAME) {
    $warmupMins = (int)($db->query("
        SELECT warmup_mins FROM falcon.courts ORDER BY id LIMIT 1
    ")->fetchColumn() ?: 2);

    $warmupTotal = $warmupMins * 60;

    $storedTs = $db->query("
        SELECT value FROM falcon.site_content
        WHERE section = 'warmup' AND key = 'started_at'
    ")->fetchColumn();

    if (!$storedTs) {
        $now = time();
        $db->prepare("
            INSERT INTO falcon.site_content (section, key, value, updated_at)
            VALUES ('warmup', 'started_at', :v, NOW())
            ON CONFLICT (section, key) DO UPDATE
                SET value = EXCLUDED.value, updated_at = NOW()
        ")->execute([':v' => (string)$now]);
        $storedTs = $now;
    }

    $elapsed   = time() - (int)$storedTs;
    $warmupRem = max(0, $warmupTotal - $elapsed);
    $hasWarmup = true;
} elseif ($gameRow) {
    try {
        $db->exec("DELETE FROM falcon.site_content WHERE section = 'warmup'");
    } catch (PDOException $e) { /* ignore */ }
}

// ── 6. Compute game timer values ─────────────────────────────
$endTs        = 0;
$remSecs      = 0;
$durationMins = 0;
$endTimeFmt   = '';

if ($gameRow) {
    $durationMins = (int)$gameRow['duration_mins'];
    $startTs      = strtotime($gameRow['started_at']);
    $endTs        = $startTs + ($durationMins * 60);
    // FIX: when paused, rem comes from stored value; otherwise compute from wall clock
    $remSecs    = $isPaused ? $pausedRem : max(0, $endTs - time());
    $endTimeFmt = date('g:i A', $endTs);
}

// ── 7. Output ────────────────────────────────────────────────
echo json_encode([
    // Game state
    'has_game'      => (bool)$gameRow,
    'session_id'    => $gameRow ? (int)$gameRow['id']  : null,
    'duration_mins' => $durationMins,
    'end_ts'        => $endTs,          // always present (0 when idle)
    'end_time_fmt'  => $endTimeFmt,
    'rem_secs'      => $remSecs,        // always present (0 when idle)

    // Pause state
    'is_paused'     => $isPaused,
    'paused_rem'    => $pausedRem,      // always present (0 when not paused)

    // Warmup state
    'has_warmup'    => $hasWarmup,
    'warmup_rem'    => $warmupRem,      // always present (0 when no warmup)
    'warmup_total'  => $warmupTotal,    // always present (0 when no warmup)

    // People
    'players'       => $players,
    'queue'         => $queue,
    'queue_count'   => $queueCount,
    'players_needed'=> PLAYERS_PER_GAME,

    // Meta — always send server time for drift correction
    'server_time'   => time(),
]);