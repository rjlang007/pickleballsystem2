<?php
// ============================================================
//  FILE: court/queue_status.php  (MODIFIED — multi-court v2)
//
//  CHANGES vs previous (single-court fixed) version:
//
//  NEW BEHAVIOUR:
//   • GET with no params    → returns ALL courts summary
//   • GET ?court_id=X       → returns single court (same
//     structure as before — fully backward compatible with
//     scanner.php which calls with no params and now gets
//     the all-courts shape it needs)
//
//  BACKWARD COMPAT:
//   • scanner.php calls with no params — it now receives the
//     all-courts shape which the new scanner.php will consume.
//   • Any caller that used ?court_id=1 still gets the flat
//     single-court structure unchanged.
//
//  PRESERVED from previous version:
//   • Reservation-aware remaining time (slot_end, not duration)
//   • session_type returned for UI styling
//   • server_time / server_ts for client clock sync
//   • No-cache headers
//   • Auto-expire orphaned queue entries older than 30 min
//   • Two-query today_games / today_players fix (no LEFT JOIN
//     FILTER confusion)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

date_default_timezone_set('Asia/Manila');

$db = getDB();
$cache = getCache();

// ── Auto-expire queue entries older than 30 minutes ──────────
$db->exec("
    DELETE FROM falcon.game_queue
    WHERE session_id IS NULL
      AND joined_at < NOW() - INTERVAL '30 minutes'
");

// ── Determine mode: single court or all courts ────────────────
$courtIdParam = filter_input(INPUT_GET, 'court_id', FILTER_VALIDATE_INT,
                             ['options' => ['min_range' => 1]]);

// ════════════════════════════════════════════════════════════
//  HELPER: build per-court data block
// ════════════════════════════════════════════════════════════
function buildCourtBlock(PDO $db, int $courtId): array {
    // ── Court meta ────────────────────────────────────────────
    $courtStmt = $db->prepare("
        SELECT id, name, short_code, color, live_status,
               max_queue, game_duration
        FROM falcon.v_court_status
        WHERE id = ?
        LIMIT 1
    ");
    $courtStmt->execute([$courtId]);
    $court = $courtStmt->fetch(PDO::FETCH_ASSOC);

    if (!$court) return [];

    // ── Queue (up to PLAYERS_PER_GAME entries for this court) ─
    $queueStmt = $db->prepare("
        SELECT u.username, u.full_name, gq.joined_at
        FROM falcon.game_queue gq
        JOIN falcon.users u ON u.id = gq.user_id
        WHERE gq.court_id = ?
          AND gq.session_id IS NULL
        ORDER BY gq.joined_at ASC
        LIMIT " . (int)PLAYERS_PER_GAME . "
    ");
    $queueStmt->execute([$courtId]);
    $queue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Active game (reservation-aware remaining time) ─────────
    $activeStmt = $db->prepare("
        SELECT gs.id, gs.started_at, gs.duration_mins,
               gs.status, gs.session_type, gs.reservation_id
        FROM falcon.game_sessions gs
        WHERE gs.court_id = ?
          AND gs.status = 'active'
        ORDER BY gs.started_at DESC
        LIMIT 1
    ");
    $activeStmt->execute([$courtId]);
    $activeGame = $activeStmt->fetch(PDO::FETCH_ASSOC);

    $remaining   = null;
    $sessionType = null;

    if ($activeGame) {
        $sessionType = $activeGame['session_type'];

        if ($sessionType === 'reservation' && $activeGame['reservation_id']) {
            // Use fixed slot_end time (not started_at + duration)
            $resRow = $db->prepare("
                SELECT slot_end, slot_date FROM falcon.reservations WHERE id = ?
            ");
            $resRow->execute([$activeGame['reservation_id']]);
            $resData = $resRow->fetch();
            if ($resData) {
                $reservEndTs = strtotime($resData['slot_date'] . ' ' . $resData['slot_end']);
                $remaining   = max(0, $reservEndTs - time());
            }
        }

        if ($remaining === null) {
            $endTs     = strtotime($activeGame['started_at']) + ($activeGame['duration_mins'] * 60);
            $remaining = max(0, $endTs - time());
        }
    }

    // ── Today stats — two unambiguous scalar queries ───────────
    $todayGamesStmt = $db->prepare("
        SELECT COUNT(*)
        FROM falcon.game_sessions
        WHERE court_id = ?
          AND DATE(started_at) = CURRENT_DATE
    ");
    $todayGamesStmt->execute([$courtId]);
    $todayGames = (int)$todayGamesStmt->fetchColumn();

    $todayPlayersStmt = $db->prepare("
        SELECT COUNT(DISTINCT gp.user_id)
        FROM falcon.game_players gp
        JOIN falcon.game_sessions gs ON gs.id = gp.session_id
        WHERE gs.court_id = ?
          AND DATE(gs.started_at) = CURRENT_DATE
    ");
    $todayPlayersStmt->execute([$courtId]);
    $todayPlayers = (int)$todayPlayersStmt->fetchColumn();

    return [
        'court_id'     => (int)$court['id'],
        'court_name'   => $court['name'],
        'short_code'   => $court['short_code'],
        'color'        => $court['color'],
        'live_status'  => $court['live_status'],
        'queue'        => $queue,
        'queue_count'  => count($queue),
        'active_game'  => $activeGame ? [
            'id'             => (int)$activeGame['id'],
            'remaining_secs' => $remaining,
            'session_type'   => $sessionType,
            'is_reservation' => ($sessionType === 'reservation'),
        ] : null,
        'today_games'  => $todayGames,
        'today_players'=> $todayPlayers,
    ];
}

// ════════════════════════════════════════════════════════════
//  SINGLE COURT — backward-compatible response
// ════════════════════════════════════════════════════════════
if ($courtIdParam) {
    // Check cache first
    $cached = $cache->isAvailable() ? $cache->getCachedQueueStatus($courtIdParam) : null;
    if ($cached) {
        echo json_encode($cached);
        exit;
    }

    $block = buildCourtBlock($db, $courtIdParam);

    // Flat structure identical to what the old single-court
    // version returned — scanner.php uses this shape.
    $response = [
        'queue'         => $block['queue']        ?? [],
        'queue_count'   => $block['queue_count']  ?? 0,
        'active_game'   => $block['active_game']  ?? null,
        'today_games'   => $block['today_games']  ?? 0,
        'today_players' => $block['today_players']?? 0,
        'server_time'   => date('Y-m-d H:i:s'),
        'server_ts'     => time(),
    ];

    // Cache for 30 seconds
    if ($cache->isAvailable()) {
        $cache->cacheQueueStatus($courtIdParam, $response, 30);
    }

    echo json_encode($response);
    exit;
}

// ════════════════════════════════════════════════════════════
//  ALL COURTS — new multi-court response
// ════════════════════════════════════════════════════════════
$allQueuesKey = 'all_queues';
$cachedAllQueues = $cache->isAvailable() ? $cache->get($allQueuesKey) : null;
if ($cachedAllQueues) {
    echo json_encode($cachedAllQueues);
    exit;
}

$courtIds = $db->query("
    SELECT id FROM falcon.courts
    WHERE is_active = TRUE
    ORDER BY sort_order, id
")->fetchAll(PDO::FETCH_COLUMN);

$courtsOut      = [];
$totalQueued    = 0;
$totalActiveCts = 0;
$grandGames     = 0;
$grandPlayers   = 0;

foreach ($courtIds as $cid) {
    $block = buildCourtBlock($db, (int)$cid);
    if (!$block) continue;

    $courtsOut[]    = $block;
    $totalQueued   += $block['queue_count'];
    $grandGames    += $block['today_games'];
    $grandPlayers  += $block['today_players'];
    if ($block['active_game'] !== null) $totalActiveCts++;
}

$allQueuesResponse = [
    'courts'              => $courtsOut,
    'total_queued'        => $totalQueued,
    'total_active_courts' => $totalActiveCts,
    'today_games'         => $grandGames,
    'today_players'       => $grandPlayers,
    'server_time'         => date('Y-m-d H:i:s'),
    'server_ts'           => time(),
];

// Cache for 30 seconds
if ($cache->isAvailable()) {
    $cache->set($allQueuesKey, $allQueuesResponse, 30);
}

echo json_encode($allQueuesResponse);