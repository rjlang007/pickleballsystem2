<?php
// ============================================================
//  FILE: api/slots.php  (MODIFIED — multi-court v2)
//
//  CHANGES vs previous (production-hardened single-court):
//
//  1. Court validation — must be is_active=TRUE AND
//     is_maintenance=FALSE. Returns court_unavailable error
//     if either flag fails. Previously only checked is_active.
//
//  2. Court object returned in response (was hardcoded null).
//     Includes: id, name, short_code, color, court_type.
//     Public-safe — no queue data or player info.
//
//  3. mode_now added to response — current slot mode
//     (open_play / reservation) for the selected court at
//     the current time. (Previously shared logic with the retired
//     court/scanner.php.)
//
//  4. court_unavailable error shape:
//     { "slots": [], "court": null, "error": "court_unavailable",
//       "message": "This court is currently unavailable." }
//
//  PRESERVED from previous version:
//   • Rate limiting (60 req/min per IP via checkRateLimit)
//   • Suspicious UA blocking
//   • Cache-Control: max-age=15
//   • On-the-hour-only slots for public endpoint
//   • Anonymised output (no player names / user IDs)
//   • booked / confirmed / left / status / isCurrent fields
//   • court_hours lookup, closed-day handling
//   • PHP time zone set to Asia/Manila
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';

// ── Public endpoint — set JSON headers ───────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=15');

// ── Block scanner bots ────────────────────────────────────────
if (detectSuspiciousUserAgent()) {
    apiError('FORBIDDEN', 'Forbidden', [], 403);
}

// ── Rate limiting — 100 req/min per IP ───────────────────────
if (shouldRateLimit(getClientIp(), 'api_' . basename(__FILE__), 100, 60)) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Too many requests. Try again in 1 minute.']));
}

// ── Philippine Time ───────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

$db = getDB();

// ── Helper: current mode for a court ─────────────────────────
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

// ── Court selection & validation ──────────────────────────────
// NEW: fetch name, short_code, color, court_type for response object
// NEW: also check is_maintenance (previously only checked is_active)
$courtIdParam = filter_input(INPUT_GET, 'court_id', FILTER_VALIDATE_INT,
                             ['options' => ['min_range' => 1]]);

if ($courtIdParam) {
    $courtStmt = $db->prepare("
        SELECT id, name, short_code, color, court_type,
               game_duration, max_queue, is_active, is_maintenance
        FROM falcon.courts
        WHERE id = ?
        LIMIT 1
    ");
    $courtStmt->execute([$courtIdParam]);
    $court = $courtStmt->fetch(PDO::FETCH_ASSOC);

    // NEW: explicit unavailable check with court_unavailable error code
    if (!$court || !$court['is_active'] || $court['is_maintenance']) {
        echo json_encode([
            'slots'   => [],
            'court'   => null,
            'error'   => 'court_unavailable',
            'message' => 'This court is currently unavailable.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // Fallback: first active, non-maintenance court by sort order
    // NEW: also checks is_maintenance=FALSE
    $court = $db->query("
        SELECT id, name, short_code, color, court_type,
               game_duration, max_queue, is_active, is_maintenance
        FROM falcon.courts
        WHERE is_active = TRUE AND is_maintenance = FALSE
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$court) {
        echo json_encode([
            'slots'   => [],
            'court'   => null,
            'error'   => 'court_unavailable',
            'message' => 'No courts are currently available.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$courtId      = (int)$court['id'];
$gameDuration = (int)$court['game_duration'];
$maxPlayers   = (int)$court['max_queue'];
$today        = date('Y-m-d');
$now          = date('H:i');

// NEW: court object for response (public-safe, no queue/player data)
$courtOut = [
    'id'         => $courtId,
    'name'       => $court['name'],
    'short_code' => $court['short_code'],
    'color'      => $court['color'],
    'court_type' => $court['court_type'],
];

// NEW: current mode for this court
$modeNow = getCourtModeNow($db, $courtId);

// ── Court hours for today ─────────────────────────────────────
$dow      = (int)(new DateTime())->format('w');
$hoursRow = $db->prepare("
    SELECT open_time, close_time, is_closed
    FROM falcon.court_hours
    WHERE court_id = ? AND day_of_week = ?
");
$hoursRow->execute([$courtId, $dow]);
$hours = $hoursRow->fetch();

$open     = $hours['open_time']  ?? '10:00:00';
$close    = $hours['close_time'] ?? '00:00:00';
$isClosed = !empty($hours['is_closed']);

if ($isClosed) {
    echo json_encode([
        'slots'    => [],
        'court'    => $courtOut,   // NEW: include court even when closed
        'mode_now' => $modeNow,   // NEW
        'closed'   => true,
        'updatedAt'=> date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Build on-the-hour slot times ─────────────────────────────
$openTs  = strtotime("$today $open");
$closeTs = in_array($close, ['00:00:00', '24:00:00'])
    ? strtotime(date('Y-m-d', strtotime('+1 day')) . ' 00:00:00')
    : strtotime("$today $close");

$slotTimes = [];
$interval  = 30 * 60;
$cur       = $openTs;

while ($cur < $closeTs) {
    $endTs = $cur + $gameDuration * 60;
    if ($endTs > $closeTs) { $cur += $interval; continue; }

    // Public API: only expose on-the-hour slots
    if ((int)date('i', $cur) === 0) {
        $slotTimes[] = [
            'start'   => date('H:i', $cur),
            'end'     => date('H:i', $endTs),
            'startTs' => $cur,
            'endTs'   => $endTs,
        ];
    }
    $cur += $interval;
}

if (empty($slotTimes)) {
    echo json_encode([
        'slots'    => [],
        'court'    => $courtOut,  // NEW
        'mode_now' => $modeNow,  // NEW
        'updatedAt'=> date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Fetch booked counts (pending + confirmed) ─────────────────
$startTimes = array_column($slotTimes, 'start');
$ph         = implode(',', array_fill(0, count($startTimes), '?'));
$params     = array_merge([$courtId, $today], $startTimes);

$bookedStmt = $db->prepare("
    SELECT LEFT(slot_time::text, 5) AS slot_start,
           COALESCE(SUM(party_size), 0) AS booked
    FROM falcon.reservations
    WHERE court_id = ?
      AND slot_date = ?
      AND LEFT(slot_time::text, 5) IN ($ph)
      AND status IN ('pending', 'confirmed')
    GROUP BY slot_start
");
$bookedStmt->execute($params);
$bookedMap = [];
foreach ($bookedStmt->fetchAll() as $row) {
    $bookedMap[$row['slot_start']] = (int)$row['booked'];
}

// ── Fetch confirmed-only counts ───────────────────────────────
$confirmedStmt = $db->prepare("
    SELECT LEFT(slot_time::text, 5) AS slot_start,
           COALESCE(SUM(party_size), 0) AS confirmed
    FROM falcon.reservations
    WHERE court_id = ?
      AND slot_date = ?
      AND LEFT(slot_time::text, 5) IN ($ph)
      AND status = 'confirmed'
    GROUP BY slot_start
");
$confirmedStmt->execute($params);
$confirmedMap = [];
foreach ($confirmedStmt->fetchAll() as $row) {
    $confirmedMap[$row['slot_start']] = (int)$row['confirmed'];
}

// ── Build response — skip past slots ─────────────────────────
$nowTs = strtotime("$today $now");
$slots = [];

foreach ($slotTimes as $s) {
    $slotTs    = $s['startTs'];
    $slotEndTs = $s['endTs'];
    $isCurrent = ($slotTs <= $nowTs && $nowTs < $slotEndTs);
    $isPast    = ($slotEndTs <= $nowTs);

    if ($isPast && !$isCurrent) continue;

    $booked    = $bookedMap[$s['start']]    ?? 0;
    $confirmed = $confirmedMap[$s['start']] ?? 0;
    $left      = max(0, $maxPlayers - $booked);

    if ($booked > 0 && $left <= 0)   $status = 'reserved';
    elseif ($left <= 0)               $status = 'full';
    elseif ($confirmed > 0)           $status = 'reserved';
    elseif ($booked > 0)              $status = 'partial';
    elseif ($left <= 1)               $status = 'busy';
    else                              $status = 'available';

    $slots[] = [
        'start'     => $s['start'],
        'end'       => $s['end'],
        'booked'    => $booked,
        'confirmed' => $confirmed,
        'left'      => $left,
        'max'       => $maxPlayers,
        'status'    => $status,
        'isCurrent' => $isCurrent,
    ];
}

echo json_encode([
    'slots'     => $slots,
    'court'     => $courtOut,  // NEW: populated court object
    'mode_now'  => $modeNow,  // NEW: current slot mode
    'updatedAt' => date('H:i:s'),
], JSON_UNESCAPED_UNICODE);