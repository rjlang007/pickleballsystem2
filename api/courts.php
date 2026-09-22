<?php
// ============================================================
//  FILE: api/courts.php
//
//  Public endpoint for court list and individual court detail.
//
//  GET /api/courts.php         → all active courts + summary
//  GET /api/courts.php?id=X    → single court with queue & hours
//
//  Auth: none required
//  Rate limit: 30 req/min per IP
//  Cache-Control: max-age=10
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=10');

date_default_timezone_set('Asia/Manila');

// ── Rate limiting — 30 req/min per IP ────────────────────────
$ip    = getClientIp();
$rlKey = sys_get_temp_dir() . '/rl_api_courts_' . md5($ip) . '.json';
$now   = time();
$rlData = ['attempts' => [], 'blocked_until' => 0];

if (file_exists($rlKey)) {
    $rlData = json_decode(file_get_contents($rlKey), true) ?? $rlData;
}
if (($rlData['blocked_until'] ?? 0) > $now) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'rate_limited', 'message' => 'Too many requests. Please wait.']);
    exit;
}
$rlData['attempts'] = array_values(array_filter($rlData['attempts'], fn($t) => $t > $now - 60));
if (count($rlData['attempts']) >= 30) {
    $rlData['blocked_until'] = $now + 60;
    file_put_contents($rlKey, json_encode($rlData), LOCK_EX);
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'rate_limited', 'message' => 'Too many requests. Please wait.']);
    exit;
}
$rlData['attempts'][] = $now;
file_put_contents($rlKey, json_encode($rlData), LOCK_EX);

$db = getDB();
$cache = getCache();

// ── Helper: get current mode for a court ─────────────────────
function getCourtModeNow(PDO $db, int $courtId): string {
    $stmt = $db->prepare("
        SELECT mode FROM falcon.court_slot_modes
        WHERE court_id = ?
          AND time_from <= NOW()::time
          AND time_to   >  NOW()::time
          AND (
              slot_date = CURRENT_DATE
              OR (slot_date IS NULL AND day_of_week = EXTRACT(DOW FROM NOW())::int)
          )
        ORDER BY CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END
        LIMIT 1
    ");
    $stmt->execute([$courtId]);
    return $stmt->fetchColumn() ?: 'open_play';
}

// ── Single court request (?id=X) ─────────────────────────────
$singleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($singleId) {
    // Check cache first
    $cached = $cache->isAvailable() ? $cache->getCachedCourtStatus($singleId) : null;
    if ($cached) {
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare("
        SELECT id, name, short_code, court_type, color,
               is_active, is_maintenance, live_status,
               players_on_court, queue_count, max_queue,
               credit_cost, game_duration, warmup_mins,
               pass_hours, sort_order, active_session_id,
               game_started_at, game_duration_mins
        FROM falcon.v_court_status
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$singleId]);
    $court = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$court) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found', 'message' => 'Court not found.']);
        exit;
    }

    // Hours today
    $dow      = (int)(new DateTime())->format('w');
    $hoursStmt = $db->prepare("
        SELECT open_time, close_time, is_closed
        FROM falcon.court_hours
        WHERE court_id = ? AND day_of_week = ?
        LIMIT 1
    ");
    $hoursStmt->execute([$singleId, $dow]);
    $hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);

    // Queue (anonymised — usernames only, no user_ids)
    $queueStmt = $db->prepare("
        SELECT u.username, u.full_name, gq.joined_at
        FROM falcon.game_queue gq
        JOIN falcon.users u ON u.id = gq.user_id
        WHERE gq.court_id = ? AND gq.session_id IS NULL
        ORDER BY gq.joined_at ASC
    ");
    $queueStmt->execute([$singleId]);
    $queue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'court' => [
            'id'              => (int)$court['id'],
            'name'            => $court['name'],
            'short_code'      => $court['short_code'],
            'court_type'      => $court['court_type'],
            'color'           => $court['color'],
            'is_active'       => (bool)$court['is_active'],
            'is_maintenance'  => (bool)$court['is_maintenance'],
            'live_status'     => $court['live_status'],
            'players_on_court'=> (int)$court['players_on_court'],
            'queue_count'     => (int)$court['queue_count'],
            'max_queue'       => (int)$court['max_queue'],
            'credit_cost'     => (float)$court['credit_cost'],
            'game_duration'   => (int)$court['game_duration'],
            'warmup_mins'     => (int)$court['warmup_mins'],
            'mode_now'        => getCourtModeNow($db, (int)$court['id']),
        ],
        'hours_today' => $hours ? [
            'open_time'  => $hours['open_time'],
            'close_time' => $hours['close_time'],
            'is_closed'  => (bool)$hours['is_closed'],
        ] : null,
        'queue'      => $queue,
        'updated_at' => date('H:i:s'),
    ];

    // Cache for 60 seconds
    if ($cache->isAvailable()) {
        $cache->cacheCourtStatus($singleId, $response, 60);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── All courts response ───────────────────────────────────────
$allCourtsKey = 'all_courts';
$cachedAll = $cache->isAvailable() ? $cache->get($allCourtsKey) : null;
if ($cachedAll) {
    echo json_encode($cachedAll, JSON_UNESCAPED_UNICODE);
    exit;
}

$courts = $db->query("
    SELECT id, name, short_code, court_type, color,
           is_active, is_maintenance, live_status,
           players_on_court, queue_count, max_queue,
           credit_cost, game_duration, warmup_mins, sort_order
    FROM falcon.v_court_status
    ORDER BY sort_order, id
")->fetchAll(PDO::FETCH_ASSOC);

$courtsOut = [];
$totalAvailable = 0;
$totalActive    = 0;

foreach ($courts as $c) {
    if ($c['live_status'] === 'available') $totalAvailable++;
    if ($c['live_status'] === 'active')    $totalActive++;

    $courtsOut[] = [
        'id'              => (int)$c['id'],
        'name'            => $c['name'],
        'short_code'      => $c['short_code'],
        'court_type'      => $c['court_type'],
        'color'           => $c['color'],
        'is_active'       => (bool)$c['is_active'],
        'is_maintenance'  => (bool)$c['is_maintenance'],
        'live_status'     => $c['live_status'],
        'players_on_court'=> (int)$c['players_on_court'],
        'queue_count'     => (int)$c['queue_count'],
        'max_queue'       => (int)$c['max_queue'],
        'credit_cost'     => (float)$c['credit_cost'],
        'game_duration'   => (int)$c['game_duration'],
        'warmup_mins'     => (int)$c['warmup_mins'],
        'mode_now'        => getCourtModeNow($db, (int)$c['id']),
    ];
}

$allCourtsResponse = [
    'courts'    => $courtsOut,
    'total'     => count($courtsOut),
    'available' => $totalAvailable,
    'active'    => $totalActive,
    'updated_at'=> date('H:i:s'),
];

// Cache for 30 seconds
if ($cache->isAvailable()) {
    $cache->set($allCourtsKey, $allCourtsResponse, 30);
}

echo json_encode($allCourtsResponse, JSON_UNESCAPED_UNICODE);