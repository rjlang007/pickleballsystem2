<?php
// ============================================================
//  FILE: includes/availability.php
//  CENTRALIZED Court Availability Engine — Falcon Pickleball
//  Single source of truth for all availability queries.
//  Used by: index.php, player/dashboard.php, admin/schedule.php,
//           admin/reservations.php, api/availability.php
// ============================================================

// ── Constants (override in config/app.php if needed) ─────────
if (!defined('PLAYERS_PER_GAME'))  define('PLAYERS_PER_GAME', 4);
if (!defined('CREDIT_PER_GAME'))   define('CREDIT_PER_GAME', 50);
if (!defined('SLOT_DURATION_MIN')) define('SLOT_DURATION_MIN', 120); // 2-hour windows

// ── Slot status constants ────────────────────────────────────
define('SLOT_STATUS_OPEN',       'open');       // fully available
define('SLOT_STATUS_FILLING',    'filling');    // some spots taken
define('SLOT_STATUS_FULL',       'full');       // no spots left
define('SLOT_STATUS_RESERVED',   'reserved');   // admin-reserved block
define('SLOT_STATUS_CLOSED',     'closed');     // court closed / maintenance
define('SLOT_STATUS_ACTIVE',     'active');     // game currently in progress

// ─────────────────────────────────────────────────────────────
/**
 * Get ALL slot data for a given date, merging:
 *  - schedule_slots (display labels from CMS)
 *  - court_reservations (admin-created blocks)
 *  - game_sessions (live/completed games)
 *  - game_queue (waiting players)
 *
 * Returns array of enriched slot objects ready for any view.
 *
 * @param PDO    $db
 * @param string $date  'Y-m-d'
 * @param int    $courtId  0 = all courts
 * @return array
 */
function getAvailabilityForDate(PDO $db, string $date, int $courtId = 0): array
{
    // 1. Load CMS schedule slots (display skeleton)
    $slotsSql = "SELECT * FROM falcon.schedule_slots ORDER BY sort_order, id";
    $rawSlots = $db->query($slotsSql)->fetchAll();

    // 2. Load courts
    $courtsSql = "SELECT * FROM falcon.courts" .
                 ($courtId ? " WHERE id = $courtId" : "") .
                 " ORDER BY id";
    $courts = $db->query($courtsSql)->fetchAll();

    // 3. Load reservations for this date
    $resSql = "
        SELECT r.*, u.full_name AS reserver_name, u.username AS reserver_username
        FROM falcon.court_reservations r
        LEFT JOIN falcon.users u ON u.id = r.reserved_by
        WHERE r.reservation_date = ? AND r.status != 'cancelled'
        ORDER BY r.slot_start
    ";
    $resStmt = $db->prepare($resSql);
    $resStmt->execute([$date]);
    $reservations = $resStmt->fetchAll();

    // 4. Load active/completed games for this date
    $gamesSql = "
        SELECT gs.*, c.name AS court_name,
               COUNT(gp.user_id) AS player_count,
               COALESCE(STRING_AGG(u.username, ',' ORDER BY u.username), '') AS player_usernames,
               COALESCE(STRING_AGG(u.full_name, '||' ORDER BY u.username), '') AS player_full_names
        FROM falcon.game_sessions gs
        JOIN falcon.courts c ON c.id = gs.court_id
        LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
        LEFT JOIN falcon.users u ON u.id = gp.user_id
        WHERE DATE(gs.started_at AT TIME ZONE 'Asia/Manila') = ?
          " . ($courtId ? "AND gs.court_id = $courtId" : "") . "
        GROUP BY gs.id, c.name
        ORDER BY gs.started_at ASC
    ";
    $gamesStmt = $db->prepare($gamesSql);
    $gamesStmt->execute([$date]);
    $games = $gamesStmt->fetchAll();

    // 5. Load queue entries for today
    $queueSql = "
        SELECT gq.*, u.username, u.full_name
        FROM falcon.game_queue gq
        JOIN falcon.users u ON u.id = gq.user_id
        WHERE gq.session_id IS NULL
          AND DATE(gq.joined_at AT TIME ZONE 'Asia/Manila') = ?
        ORDER BY gq.joined_at ASC
    ";
    $queueStmt = $db->prepare($queueSql);
    $queueStmt->execute([$date]);
    $queuePlayers = $queueStmt->fetchAll();

    // ── Build enriched slot list ──────────────────────────────
    $result = [];

    foreach ($rawSlots as $slot) {
        // Parse the time label to get a comparable hour (e.g. "10:00 AM" → 10)
        $slotHour    = parseSlotHour($slot['time_label']);
        $slotMinute  = parseSlotMinute($slot['time_label']);

        foreach ($courts as $court) {
            $enriched = [
                'slot_id'         => $slot['id'],
                'court_id'        => $court['id'],
                'court_name'      => $court['name'],
                'time_label'      => $slot['time_label'],
                'slot_hour'       => $slotHour,
                'slot_minute'     => $slotMinute,
                'max_players'     => (int)$slot['max_players'],
                'sort_order'      => (int)$slot['sort_order'],
                'status'          => SLOT_STATUS_OPEN,    // will be overridden below
                'status_label'    => 'Available',
                'players_in_slot' => 0,
                'players_max'     => (int)$slot['max_players'],
                'reservation'     => null,
                'active_game'     => null,
                'upcoming_games'  => [],
                'queue_count'     => count($queuePlayers),
                'queue_players'   => $queuePlayers,
                'can_book'        => true,
                'block_reason'    => '',
                'css_class'       => 'available',
                'dot_color'       => '#00e5a0',
            ];

            // ── Check admin reservations ──────────────────────
            foreach ($reservations as $res) {
                if ((int)$res['court_id'] !== (int)$court['id']) continue;
                if (!slotMatchesReservation($slotHour, $slotMinute, $res)) continue;

                $enriched['reservation']  = $res;
                $enriched['status']       = SLOT_STATUS_RESERVED;
                $enriched['status_label'] = $res['label'] ?? 'Reserved';
                $enriched['can_book']     = false;
                $enriched['block_reason'] = $res['notes'] ?? 'Admin reserved';
                $enriched['css_class']    = 'reserved';
                $enriched['dot_color']    = '#f59e0b';
                break;
            }

            // ── Check games (active takes priority) ──────────
            foreach ($games as $game) {
                if ((int)$game['court_id'] !== (int)$court['id']) continue;
                if (!gameOverlapsSlot($game, $slotHour, $slotMinute, $date)) continue;

                if ($game['status'] === 'active') {
                    $enriched['active_game']    = $game;
                    $enriched['status']         = SLOT_STATUS_ACTIVE;
                    $enriched['status_label']   = 'In Progress';
                    $enriched['players_in_slot'] = (int)$game['player_count'];
                    $enriched['can_book']        = false;
                    $enriched['block_reason']    = 'Game in progress';
                    $enriched['css_class']       = 'active';
                    $enriched['dot_color']       = '#00b8ff';
                } elseif ($game['status'] === 'completed' || $game['status'] === 'cancelled') {
                    $enriched['upcoming_games'][] = $game;
                }
            }

            // ── If still open, compute fill level from queue ──
            if ($enriched['status'] === SLOT_STATUS_OPEN) {
                $q = count($queuePlayers);
                if ($q >= $enriched['max_players']) {
                    $enriched['status']       = SLOT_STATUS_FULL;
                    $enriched['status_label'] = 'Full';
                    $enriched['css_class']    = 'full';
                    $enriched['dot_color']    = '#ef4444';
                    $enriched['can_book']     = false;
                } elseif ($q >= ceil($enriched['max_players'] * 0.5)) {
                    $enriched['status']       = SLOT_STATUS_FILLING;
                    $enriched['status_label'] = 'Filling Up';
                    $enriched['css_class']    = 'busy';
                    $enriched['dot_color']    = '#f59e0b';
                }
                $enriched['players_in_slot'] = $q;
            }

            // ── Override with CMS forced status (closed) ─────
            if ($slot['status'] === 'unavailable') {
                $enriched['status']       = SLOT_STATUS_CLOSED;
                $enriched['status_label'] = 'Closed';
                $enriched['can_book']     = false;
                $enriched['css_class']    = 'unavailable';
                $enriched['dot_color']    = '#6b7fa3';
            }

            $result[] = $enriched;
        }
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────
/**
 * Lightweight summary for public homepage — no court split,
 * just aggregate per time slot across all courts.
 */
function getPublicSlotSummary(PDO $db, string $date = ''): array
{
    if (!$date) $date = date('Y-m-d');

    $slots   = $db->query("SELECT * FROM falcon.schedule_slots ORDER BY sort_order, id")->fetchAll();
    $courts  = $db->query("SELECT COUNT(*) FROM falcon.courts")->fetchColumn();
    $courts  = max(1, (int)$courts);

    $reserveSql = "
        SELECT slot_start, slot_end, court_id, status, label, notes
        FROM falcon.court_reservations
        WHERE reservation_date = ? AND status != 'cancelled'
    ";
    $rStmt = $db->prepare($reserveSql);
    $rStmt->execute([$date]);
    $reservations = $rStmt->fetchAll();

    $gamesSql = "
        SELECT gs.started_at, gs.ended_at, gs.duration_mins, gs.status, gs.court_id,
               COUNT(gp.user_id) AS player_count
        FROM falcon.game_sessions gs
        LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
        WHERE DATE(gs.started_at AT TIME ZONE 'Asia/Manila') = ? AND gs.status = 'active'
        GROUP BY gs.id
    ";
    $gStmt = $db->prepare($gamesSql);
    $gStmt->execute([$date]);
    $activeGames = $gStmt->fetchAll();

    $queueCount = (int)$db->query(
        "SELECT COUNT(*) FROM falcon.game_queue WHERE session_id IS NULL"
    )->fetchColumn();

    $result = [];
    foreach ($slots as $slot) {
        $h = parseSlotHour($slot['time_label']);
        $m = parseSlotMinute($slot['time_label']);

        $blocked   = 0;
        $active    = 0;
        $maxPlayers = (int)$slot['max_players'];

        foreach ($reservations as $r) {
            if (slotMatchesReservation($h, $m, $r)) $blocked++;
        }
        foreach ($activeGames as $g) {
            if (gameOverlapsSlot($g, $h, $m, $date)) $active++;
        }

        $availableCourts = $courts - $blocked - $active;

        // Determine status
        if ($slot['status'] === 'unavailable') {
            $status = 'unavailable';
            $label  = 'Closed';
        } elseif ($availableCourts <= 0) {
            $status = 'full';
            $label  = 'Full';
        } elseif ($active > 0 || $queueCount >= ceil($maxPlayers * 0.5)) {
            $status = 'busy';
            $label  = 'Filling Up';
        } else {
            $status = 'available';
            $label  = 'Available';
        }

        $result[] = [
            'id'              => (int)$slot['id'],
            'time_label'      => $slot['time_label'],
            'status'          => $status,
            'status_label'    => $label,
            'max_players'     => $maxPlayers,
            'players_current' => $queueCount + ($active * PLAYERS_PER_GAME),
            'available_courts'=> max(0, $availableCourts),
            'total_courts'    => $courts,
            'sort_order'      => (int)$slot['sort_order'],
        ];
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────
/**
 * Create or update a court reservation.
 * Called by admin/reservations.php
 *
 * @return array ['ok' => bool, 'msg' => string, 'id' => int|null]
 */
function upsertReservation(PDO $db, array $data, int $existingId = 0): array
{
    $required = ['court_id','reservation_date','slot_start','slot_end','label'];
    foreach ($required as $k) {
        if (empty($data[$k])) return ['ok' => false, 'msg' => "Missing field: $k"];
    }

    // Check for collision with active games
    $collisionSql = "
        SELECT COUNT(*) FROM falcon.game_sessions
        WHERE court_id = ?
          AND status = 'active'
          AND DATE(started_at AT TIME ZONE 'Asia/Manila') = ?
    ";
    $c = $db->prepare($collisionSql);
    $c->execute([$data['court_id'], $data['reservation_date']]);
    if ((int)$c->fetchColumn() > 0) {
        return ['ok' => false, 'msg' => 'A game is currently active on this court for this date. Cannot reserve.'];
    }

    if ($existingId > 0) {
        $stmt = $db->prepare("
            UPDATE falcon.court_reservations
            SET court_id=:court_id, reservation_date=:reservation_date,
                slot_start=:slot_start, slot_end=:slot_end,
                label=:label, notes=:notes, status=:status,
                reserved_by=:reserved_by, updated_at=NOW()
            WHERE id=:id
        ");
        $stmt->execute(array_merge($data, [':id' => $existingId]));
        return ['ok' => true, 'msg' => 'Reservation updated.', 'id' => $existingId];
    }

    $stmt = $db->prepare("
        INSERT INTO falcon.court_reservations
            (court_id, reservation_date, slot_start, slot_end, label, notes, status, reserved_by)
        VALUES
            (:court_id, :reservation_date, :slot_start, :slot_end, :label, :notes, :status, :reserved_by)
        RETURNING id
    ");
    $stmt->execute($data);
    $newId = $stmt->fetchColumn();

    // ── Sync: update schedule_slot status if it becomes reserved ──
    syncScheduleSlotStatus($db, $data['reservation_date']);

    return ['ok' => true, 'msg' => 'Reservation created.', 'id' => (int)$newId];
}

// ─────────────────────────────────────────────────────────────
/**
 * After any reservation change, recompute the schedule_slots
 * display status for the CMS (so index.php always reflects reality).
 */
function syncScheduleSlotStatus(PDO $db, string $date): void
{
    $today = date('Y-m-d');
    if ($date !== $today) return; // Only today matters for live display

    $slots = $db->query("SELECT * FROM falcon.schedule_slots")->fetchAll();
    foreach ($slots as $slot) {
        $h = parseSlotHour($slot['time_label']);
        $m = parseSlotMinute($slot['time_label']);

        // Count reservations blocking this slot today
        $countRes = $db->prepare("
            SELECT COUNT(*) FROM falcon.court_reservations
            WHERE reservation_date = ? AND status != 'cancelled'
              AND EXTRACT(HOUR FROM slot_start) = ?
        ");
        $countRes->execute([$today, $h]);
        $resCount = (int)$countRes->fetchColumn();

        // Count courts total
        $totalCourts = (int)$db->query("SELECT COUNT(*) FROM falcon.courts")->fetchColumn();

        // Count active games
        $countActive = (int)$db->query("
            SELECT COUNT(*) FROM falcon.game_sessions
            WHERE status='active' AND DATE(started_at AT TIME ZONE 'Asia/Manila')='$today'
        ")->fetchColumn();

        $newStatus = $slot['status']; // preserve CMS override

        if ($newStatus !== 'unavailable') {
            if ($resCount >= $totalCourts || $countActive >= $totalCourts) {
                $newStatus = 'busy';
            } else {
                $newStatus = 'available';
            }
        }

        if ($newStatus !== $slot['status']) {
            $db->prepare("UPDATE falcon.schedule_slots SET status=? WHERE id=?")
               ->execute([$newStatus, $slot['id']]);
        }
    }
}

// ─────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────

function parseSlotHour(string $label): int
{
    // Handles: "10:00 AM", "2:00 PM", "10AM", "14:00"
    if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $label, $m)) {
        $h = (int)$m[1];
        if (strtoupper($m[3]) === 'PM' && $h !== 12) $h += 12;
        if (strtoupper($m[3]) === 'AM' && $h === 12) $h = 0;
        return $h;
    }
    if (preg_match('/(\d{1,2})\s*(AM|PM)/i', $label, $m)) {
        $h = (int)$m[1];
        if (strtoupper($m[2]) === 'PM' && $h !== 12) $h += 12;
        if (strtoupper($m[2]) === 'AM' && $h === 12) $h = 0;
        return $h;
    }
    if (preg_match('/(\d{1,2}):(\d{2})/', $label, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function parseSlotMinute(string $label): int
{
    if (preg_match('/\d{1,2}:(\d{2})/', $label, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function slotMatchesReservation(int $slotHour, int $slotMinute, array $res): bool
{
    // slot_start / slot_end are TIME columns like '10:00:00'
    $resStartH = (int)explode(':', $res['slot_start'])[0];
    $resEndH   = (int)explode(':', $res['slot_end'])[0];
    return $slotHour >= $resStartH && $slotHour < $resEndH;
}

function gameOverlapsSlot(array $game, int $slotHour, int $slotMinute, string $date): bool
{
    $slotStart = mktime($slotHour, $slotMinute, 0, (int)date('m', strtotime($date)),
                        (int)date('d', strtotime($date)), (int)date('Y', strtotime($date)));
    $slotEnd   = $slotStart + (SLOT_DURATION_MIN * 60);
    $gameStart = strtotime($game['started_at']);
    $gameEnd   = $game['ended_at']
        ? strtotime($game['ended_at'])
        : $gameStart + ((int)($game['duration_mins'] ?? SLOT_DURATION_MIN) * 60);
    return $gameStart < $slotEnd && $gameEnd > $slotStart;
}

/**
 * Get a flat JSON-serializable snapshot for the API endpoint.
 */
function getAvailabilitySnapshot(PDO $db): array
{
    $date    = date('Y-m-d');
    $summary = getPublicSlotSummary($db, $date);
    $queue   = (int)$db->query(
        "SELECT COUNT(*) FROM falcon.game_queue WHERE session_id IS NULL"
    )->fetchColumn();
    $active  = (int)$db->query(
        "SELECT COUNT(*) FROM falcon.game_sessions WHERE status='active'"
    )->fetchColumn();

    return [
        'date'         => $date,
        'timestamp'    => time(),
        'queue_count'  => $queue,
        'active_games' => $active,
        'slots'        => $summary,
    ];
}