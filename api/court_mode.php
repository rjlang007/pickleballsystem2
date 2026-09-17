<?php
// ============================================================
//  FILE: api/court_mode.php
//  Returns slot mode (open_play / reservation) for a given
//  court, date, and time range. Used by the player booking
//  calendar to colour-code slots before render.
//
//  GET  /api/court_mode.php?court_id=1&date=2026-05-01
//
//  Response:
//  {
//    "ok": true,
//    "date": "2026-05-01",
//    "court_id": 1,
//    "court": { "id":1, "name":"Court 1", "short_code":"C1", "color":"#00e5a0" },
//    "modes": [ { "time_from","time_to","mode","note","is_whole_day","priority" } ],
//    "current_mode": "open_play",          // mode that applies RIGHT NOW
//    "active_sessions": [ ... ]
//  }
//
//  No auth required — public read-only endpoint.
//  Cache-Control: max-age=30  (client may cache 30 s)
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=30');
header('Access-Control-Allow-Origin: *');

$db = getDB();

// ── Input validation ─────────────────────────────────────────
$courtId = filter_input(INPUT_GET, 'court_id', FILTER_VALIDATE_INT);
if (!$courtId || $courtId < 1) {
    // Default to first active court
    $firstCourt = $db->query(
        "SELECT id FROM falcon.courts WHERE is_active = TRUE ORDER BY sort_order, id LIMIT 1"
    )->fetchColumn();
    $courtId = $firstCourt ?: 1;
}

$date = trim($_GET['date'] ?? '');
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
// Reject clearly bogus dates
try {
    $dateObj = new DateTimeImmutable($date);
    $date    = $dateObj->format('Y-m-d');
    $dow     = (int)$dateObj->format('w'); // 0=Sun … 6=Sat
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid date.']);
    exit;
}

// ── Verify court exists ───────────────────────────────────────
$courtStmt = $db->prepare(
    "SELECT id, name, short_code, color, is_active, is_maintenance
     FROM falcon.courts WHERE id = ? LIMIT 1"
);
$courtStmt->execute([$courtId]);
$court = $courtStmt->fetch();

if (!$court) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Court not found.']);
    exit;
}

// ── Mode rules for this court + date ─────────────────────────
// Priority: specific date overrides recurring day-of-week rules.
$modeStmt = $db->prepare("
    SELECT time_from, time_to, mode, note, is_whole_day,
           CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END AS priority
    FROM   falcon.court_slot_modes
    WHERE  court_id = ?
      AND  (
               slot_date   = ?
            OR (slot_date IS NULL AND day_of_week = ?)
           )
    ORDER  BY priority ASC, time_from ASC
");
$modeStmt->execute([$courtId, $date, $dow]);
$modes = $modeStmt->fetchAll();

// ── Determine mode that applies RIGHT NOW (for convenience) ──
$nowTime    = date('H:i:s');
$currentMode = 'reservation'; // default when no rule matches
foreach ($modes as $m) {
    if ($m['is_whole_day'] || ($m['time_from'] <= $nowTime && $m['time_to'] > $nowTime)) {
        $currentMode = $m['mode'];
        break; // first priority-1 match wins
    }
}

// ── Active game sessions on this court for this date ─────────
$sessStmt = $db->prepare("
    SELECT gs.id, gs.status, gs.started_at, gs.session_type,
           COUNT(gp.id)  AS player_count,
           r.slot_time, r.slot_end, r.id AS reservation_id
    FROM   falcon.game_sessions gs
    LEFT   JOIN falcon.game_players gp ON gp.session_id = gs.id
    LEFT   JOIN falcon.reservations r  ON r.session_id  = gs.id
    WHERE  gs.court_id = ?
      AND  gs.status   IN ('active', 'waiting')
      AND  DATE(gs.started_at) = ?
    GROUP  BY gs.id, gs.status, gs.started_at, gs.session_type,
              r.slot_time, r.slot_end, r.id
    ORDER  BY gs.started_at ASC
");
$sessStmt->execute([$courtId, $date]);
$activeSessions = $sessStmt->fetchAll();

// ── Response ─────────────────────────────────────────────────
echo json_encode([
    'ok'             => true,
    'date'           => $date,
    'day_of_week'    => $dow,
    'court_id'       => (int)$courtId,
    'court'          => [
        'id'            => (int)$court['id'],
        'name'          => $court['name'],
        'short_code'    => $court['short_code'],
        'color'         => $court['color'],
        'is_active'     => (bool)$court['is_active'],
        'is_maintenance'=> (bool)$court['is_maintenance'],
    ],
    'modes'          => $modes,
    'current_mode'   => $currentMode,
    'active_sessions'=> $activeSessions,
    'server_time'    => date('H:i:s'),
], JSON_UNESCAPED_UNICODE);