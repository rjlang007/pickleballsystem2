<?php
// ============================================================
//  FILE: court/api/check_next_slot.php
//
//  PURPOSE:
//   Lightweight polling endpoint called by scanner.php every
//   30 seconds once the 15-minute extension warning is active.
//   Ensures the extension modal reflects the CURRENT state of
//   the next slot — someone might book it while the player
//   is still playing.
//
//  REQUEST (GET):
//   ?court_id=1&slot_date=2025-04-01&slot_end=10:00
//
//  RESPONSE (JSON):
//   {
//     "available": true|false,
//     "start": "10:00",         // 24h H:i
//     "end":   "11:00",
//     "start_fmt": "10:00 AM",  // formatted
//     "end_fmt":   "11:00 AM",
//     "spots_left": 4,
//     "booked_by": null | "Name (10:00 AM)",
//     "blocked_reason": null | "human-readable string"
//   }
//
//  AUTH: admin-only (scanner is an admin page)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$courtId      = filter_input(INPUT_GET, 'court_id',  FILTER_VALIDATE_INT);
$slotDate     = trim($_GET['slot_date'] ?? '');
$currentSlotEnd = trim($_GET['slot_end'] ?? '');

if (!$courtId || !$slotDate || !$currentSlotEnd) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters: court_id, slot_date, slot_end required']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid slot_date format. Use YYYY-MM-DD']);
    exit;
}
// Normalise slot_end to H:i (strip seconds if present)
if (preg_match('/^(\d{2}:\d{2})/', $currentSlotEnd, $m)) {
    $currentSlotEnd = $m[1];
}

$db           = getDB();
$court        = $db->query("SELECT game_duration FROM falcon.courts WHERE id = " . (int)$courtId)->fetch();
$gameDuration = $court ? (int)$court['game_duration'] : 60;

// ── Next slot timestamps ──────────────────────────────────────
$nextStartTs = strtotime($slotDate . ' ' . $currentSlotEnd);
$nextEndTs   = $nextStartTs + ($gameDuration * 60);
$nextStart   = date('H:i', $nextStartTs);
$nextEnd     = date('H:i', $nextEndTs);
$nextStartFmt = date('g:i A', $nextStartTs);
$nextEndFmt   = date('g:i A', $nextEndTs);

// ── Court hours validation ────────────────────────────────────
$dow = (int)date('w', strtotime($slotDate));
$hourStmt = $db->prepare("
    SELECT open_time, close_time, is_closed
    FROM falcon.court_hours
    WHERE court_id = ? AND day_of_week = ?
    LIMIT 1
");
$hourStmt->execute([$courtId, $dow]);
$hours = $hourStmt->fetch();

if ($hours && $hours['is_closed']) {
    echo json_encode([
        'available'      => false,
        'start'          => $nextStart,
        'end'            => $nextEnd,
        'start_fmt'      => $nextStartFmt,
        'end_fmt'        => $nextEndFmt,
        'spots_left'     => 0,
        'booked_by'      => null,
        'blocked_reason' => 'Court is closed at that time',
    ]);
    exit;
}

if ($hours) {
    $closeTs = strtotime($slotDate . ' ' . $hours['close_time']);
    if (in_array($hours['close_time'], ['00:00:00', '24:00:00'])) {
        $closeTs = strtotime(date('Y-m-d', strtotime($slotDate . ' +1 day')) . ' 00:00:00');
    }
    if ($nextEndTs > $closeTs) {
        echo json_encode([
            'available'      => false,
            'start'          => $nextStart,
            'end'            => $nextEnd,
            'start_fmt'      => $nextStartFmt,
            'end_fmt'        => $nextEndFmt,
            'spots_left'     => 0,
            'booked_by'      => null,
            'blocked_reason' => "Next slot ({$nextStartFmt}–{$nextEndFmt}) falls outside court hours",
        ]);
        exit;
    }
}

// ── Booking count for next slot ───────────────────────────────
$bookStmt = $db->prepare("
    SELECT
        COALESCE(SUM(r.party_size), 0) AS total_booked,
        (SELECT u.full_name
         FROM falcon.reservations r2
         JOIN falcon.users u ON u.id = r2.user_id
         WHERE r2.court_id = ?
           AND r2.slot_date = ?
           AND r2.slot_time = ?
           AND r2.status IN ('pending','confirmed')
         ORDER BY r2.created_at ASC
         LIMIT 1) AS first_booker_name
    FROM falcon.reservations r
    WHERE r.court_id  = ?
      AND r.slot_date = ?
      AND r.slot_time = ?
      AND r.status IN ('pending','confirmed')
");
$bookStmt->execute([
    $courtId, $slotDate, $nextStart,   // subquery params
    $courtId, $slotDate, $nextStart,   // COUNT params
]);
$row = $bookStmt->fetch(PDO::FETCH_ASSOC);

$totalBooked = (int)($row['total_booked'] ?? 0);
$maxPlayers  = PLAYERS_PER_GAME;
$spotsLeft   = max(0, $maxPlayers - $totalBooked);
$available   = ($spotsLeft > 0);

$bookedBy       = null;
$blockedReason  = null;

if (!$available) {
    $name      = $row['first_booker_name'] ?? 'Another player';
    $bookedBy  = $name . ' (' . $nextStartFmt . ')';
    $blockedReason = "The next slot ({$nextStartFmt}–{$nextEndFmt}) is already fully reserved by {$name}.";
}

echo json_encode([
    'available'      => $available,
    'start'          => $nextStart,
    'end'            => $nextEnd,
    'start_fmt'      => $nextStartFmt,
    'end_fmt'        => $nextEndFmt,
    'spots_left'     => $spotsLeft,
    'booked_by'      => $bookedBy,
    'blocked_reason' => $blockedReason,
]);