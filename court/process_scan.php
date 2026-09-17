<?php
// ============================================================
//  FILE: court/process_scan.php  (v10 + patch v11)
//
//  PATCHES applied over v10:
//
//  PATCH 1 — Set _ticker_court_id before requiring ticker
//  Before requiring game_ticker.php, set:
//      $GLOBALS['_ticker_court_id'] = $courtId;
//  Without this, game_ticker v4 defaulted to iterating ALL
//  courts on every scan, and couldn't return the per-court
//  result that process_scan.php reads back for game_started.
//
//  PATCH 2 — Fix broken $queueTotal count
//  The original used PDO::execute()'s boolean return value as
//  a ternary guard, then ran a raw string-interpolated query
//  (SQL injection risk + always evaluated the wrong branch).
//  Replaced with a clean prepared statement that also includes
//  the court_id IS NULL fallback for pre-migration rows.
//
//  All other v10 logic preserved unchanged.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
error_log('[process_scan] RAW INPUT: ' . file_get_contents('php://input'));

// ── Auth: admin session OR kiosk token ───────────────────────
$isInternalCall = defined('GAME_TICKER_INTERNAL');

if (!$isInternalCall) {
    $kioskToken = $_COOKIE['kiosk_token'] ?? '';
    $validKiosk = ($kioskToken !== '' && hash_equals(
        hash('sha256', APP_NAME . '|kiosk|' . date('Y-m-d')),
        $kioskToken
    ));

    if (!isLoggedIn() && !$validKiosk) {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Scanner session expired. Please reload.',
            'code'    => 'unauthorized',
        ]);
        exit;
    }

    if (isLoggedIn() && !isAdmin() && !$validKiosk) {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Admin access required.',
            'code'    => 'unauthorized',
        ]);
        exit;
    }
}

$body  = json_decode(file_get_contents('php://input'), true);
$token = trim($body['token'] ?? '');

if (strlen($token) < 5) {
    echo json_encode(['status' => 'error', 'message' => 'No valid QR token received.', 'code' => 'empty_token']);
    exit;
}

// ── Rate limiting ─────────────────────────────────────────────
$rateLimitFile = sys_get_temp_dir() . '/scan_rl_' . md5($token) . '.json';
$now           = time();
$rlData        = ['attempts' => [], 'blocked_until' => 0];
if (file_exists($rateLimitFile)) {
    $rlData = json_decode(file_get_contents($rateLimitFile), true) ?? $rlData;
}
if ($rlData['blocked_until'] > $now) {
    echo json_encode(['status' => 'error', 'message' => 'Too many scan attempts. Please wait.', 'code' => 'rate_limited']);
    exit;
}
$rlData['attempts'] = array_values(array_filter($rlData['attempts'], fn($t) => $t > $now - 60));
if (count($rlData['attempts']) >= 10) {
    $rlData['blocked_until'] = $now + 60;
    file_put_contents($rateLimitFile, json_encode($rlData), LOCK_EX);
    echo json_encode(['status' => 'error', 'message' => 'Too many scan attempts. Please wait.', 'code' => 'rate_limited']);
    exit;
}
$rlData['attempts'][] = $now;
file_put_contents($rateLimitFile, json_encode($rlData), LOCK_EX);

$db = getDB();

try { $db->exec("SET TIME ZONE 'Asia/Manila'"); } catch (PDOException $e) {}

// ── Log scan attempt ──────────────────────────────────────────
$logId = null;
try {
    $logStmt = $db->prepare("
        INSERT INTO falcon.scan_logs (qr_token, user_id, status, message, scanned_at)
        VALUES (?, NULL, 'fail', 'pending', NOW())
        RETURNING id
    ");
    $logStmt->execute([$token]);
    $logId = (int)$logStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('scan_log insert: ' . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════

function finalizeLog(PDO $db, ?int $logId, ?int $uid, string $status, string $msg): void {
    if (!$logId) return;
    try {
        $db->prepare("UPDATE falcon.scan_logs SET user_id=?, status=?, message=? WHERE id=?")
           ->execute([$uid, $status, $msg, $logId]);
    } catch (Throwable $e) {
        error_log('scan_log update: ' . $e->getMessage());
    }
}

function sendNotifications(PDO $db, array $userIds, string $title, string $message, string $type, ?int $reservationId = null): void {
    $stmt = $db->prepare("
        INSERT INTO falcon.notifications (user_id, title, message, type, reservation_id, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    foreach ($userIds as $uid) {
        try { $stmt->execute([$uid, $title, $message, $type, $reservationId]); }
        catch (Throwable $e) { error_log('notification insert: ' . $e->getMessage()); }
    }
}

function sendChatToAdmins(PDO $db, string $message, int $fromUserId): void {
    try {
        $userExists = $db->prepare("SELECT 1 FROM falcon.users WHERE id = ? LIMIT 1");
        $userExists->execute([$fromUserId]);
        if (!$userExists->fetchColumn()) {
            error_log('[process_scan] sendChatToAdmins: user_id=' . $fromUserId . ' not in falcon.users — skipping');
            return;
        }

        $admins = $db->query(
            "SELECT id FROM falcon.users WHERE role IN ('admin','super_admin') AND is_banned = FALSE"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($admins as $adminId) {
            $convStmt = $db->prepare("
                INSERT INTO falcon.chat_conversations (user_id)
                VALUES (?)
                ON CONFLICT (user_id) DO UPDATE SET updated_at = NOW()
                RETURNING id
            ");
            $convStmt->execute([$fromUserId]);
            $convId = (int)$convStmt->fetchColumn();

            $db->prepare("
                INSERT INTO falcon.chat_messages (conversation_id, sender_id, message, created_at)
                VALUES (?, ?, ?, NOW())
            ")->execute([$convId, $fromUserId, $message]);

            $db->prepare("UPDATE falcon.chat_conversations SET updated_at = NOW() WHERE id = ?")
               ->execute([$convId]);
        }
    } catch (Throwable $e) {
        error_log('[process_scan] chat: ' . $e->getMessage());
    }
}

function getAdminIds(PDO $db): array {
    return array_column(
        $db->query("SELECT id FROM falcon.users WHERE role IN ('admin','super_admin') AND is_banned = FALSE")->fetchAll(),
        'id'
    );
}

function checkNextSlot(PDO $db, int $courtId, string $slotDate, string $currentSlotEnd, int $gameDurationMins): array {
    $nextStartTs = strtotime($slotDate . ' ' . $currentSlotEnd);
    $nextEndTs   = $nextStartTs + ($gameDurationMins * 60);
    $nextStart   = date('H:i', $nextStartTs);
    $nextEnd     = date('H:i', $nextEndTs);

    $hourStmt = $db->prepare("
        SELECT open_time, close_time, is_closed
        FROM falcon.court_hours
        WHERE court_id = ? AND day_of_week = ?
        LIMIT 1
    ");
    $hourStmt->execute([$courtId, (int)date('w', strtotime($slotDate))]);
    $hours = $hourStmt->fetch();

    if ($hours && $hours['is_closed']) {
        return ['available' => false, 'start' => $nextStart, 'end' => $nextEnd, 'spots_left' => 0, 'booked_by' => 'Court is closed'];
    }
    if ($hours) {
        $closeTs = strtotime($slotDate . ' ' . $hours['close_time']);
        if (in_array($hours['close_time'], ['00:00:00', '24:00:00'])) {
            $closeTs = strtotime(date('Y-m-d', strtotime($slotDate . ' +1 day')) . ' 00:00:00');
        }
        if ($nextEndTs > $closeTs) {
            return ['available' => false, 'start' => $nextStart, 'end' => $nextEnd, 'spots_left' => 0, 'booked_by' => 'Outside court hours'];
        }
    }

    $bookStmt = $db->prepare("
        SELECT
            COALESCE(SUM(r.party_size), 0) AS total_booked,
            (SELECT u.full_name FROM falcon.reservations r2
             JOIN falcon.users u ON u.id = r2.user_id
             WHERE r2.court_id = ? AND r2.slot_date = ?
               AND r2.slot_time = ? AND r2.status IN ('pending','confirmed')
             ORDER BY r2.created_at ASC LIMIT 1) AS first_booker_name
        FROM falcon.reservations r
        WHERE r.court_id = ? AND r.slot_date = ? AND r.slot_time = ?
          AND r.status IN ('pending','confirmed')
    ");
    $bookStmt->execute([
        $courtId, $slotDate, $nextStart,
        $courtId, $slotDate, $nextStart,
    ]);
    $row         = $bookStmt->fetch(PDO::FETCH_ASSOC);
    $totalBooked = (int)($row['total_booked'] ?? 0);
    $spotsLeft   = PLAYERS_PER_GAME - $totalBooked;
    $available   = ($spotsLeft > 0);

    return [
        'available'  => $available,
        'start'      => $nextStart,
        'end'        => $nextEnd,
        'spots_left' => max(0, $spotsLeft),
        'booked_by'  => (!$available && ($row['first_booker_name'] ?? null)) ? $row['first_booker_name'] : null,
    ];
}

// ══════════════════════════════════════════════════════════════
//  findBestAvailableCourt
// ══════════════════════════════════════════════════════════════
function findBestAvailableCourt(PDO $db): ?array {
    // ── Court-activation-aware court selection ─────────────────
    // Uses falcon.v_court_status.is_queueable, which is FALSE for
    // deactivated, maintenance, admin-reserved, admin-occupied,
    // tournament-mode, and courts with a real game in progress.
    // Only open-play / genuinely available courts are eligible to
    // receive new walk-in queue joins.
    $stmt = $db->query("
        SELECT vcs.id, vcs.name, vcs.short_code, vcs.color, vcs.court_type,
               vcs.game_duration, vcs.warmup_mins, vcs.pass_hours,
               vcs.is_active, vcs.is_maintenance, vcs.manual_status,
               vcs.credit_cost, vcs.max_queue, vcs.live_status,
               COALESCE(vcs.queue_count, 0) AS queue_count,
               0 AS has_active_game
        FROM falcon.v_court_status vcs
        WHERE vcs.is_queueable = TRUE
          AND COALESCE(vcs.queue_count, 0) < vcs.max_queue
        ORDER BY COALESCE(vcs.queue_count, 0) ASC, vcs.sort_order ASC, vcs.id ASC
        LIMIT 1
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Duplicate scan check ──────────────────────────────────────
try {
    $recentScan = $db->prepare("
        SELECT id FROM falcon.scan_logs
        WHERE qr_token = ?
          AND status   = 'success'
          AND scanned_at > NOW() - INTERVAL '30 seconds'
        LIMIT 1
    ");
    $recentScan->execute([$token]);
    if ($recentScan->fetch()) {
        finalizeLog($db, $logId, null, 'fail', 'duplicate_scan');
        echo json_encode([
            'status'  => 'error',
            'message' => 'QR already scanned. Please wait 30 seconds before scanning again.',
            'code'    => 'duplicate_scan',
        ]);
        exit;
    }
} catch (Throwable $e) {
    error_log('[process_scan] duplicate check: ' . $e->getMessage());
}

// ── CHECK 1: Token exists ─────────────────────────────────────
$passStmt = $db->prepare("
    SELECT pp.id AS pass_id, pp.user_id, pp.is_active, pp.expires_at,
           u.username, u.full_name, u.is_banned
    FROM falcon.player_passes pp
    JOIN falcon.users u ON u.id = pp.user_id
    WHERE pp.qr_token = ?
    LIMIT 1
");
$passStmt->execute([$token]);
$pass = $passStmt->fetch();

if (!$pass) {
    finalizeLog($db, $logId, null, 'fail', 'invalid_token');
    echo json_encode(['status' => 'error', 'message' => 'Invalid QR code. Not recognised.', 'code' => 'invalid_token']);
    exit;
}
$uid = (int)$pass['user_id'];

// ── CHECK 1.5: One-time use enforcement ───────────────────────
if ($pass['last_scanned_at']) {
    $secondsAgo = time() - strtotime($pass['last_scanned_at']);
    if ($secondsAgo < 60) {
        finalizeLog($db, $logId, $uid, 'fail', 'qr_recently_used');
        echo json_encode([
            'status'  => 'error',
            'message' => 'QR code was just used. Please wait 60 seconds.',
            'code'    => 'qr_recently_used',
        ]);
        exit;
    }
}

// ── CHECK 2: Not banned ───────────────────────────────────────
if ($pass['is_banned']) {
    finalizeLog($db, $logId, $uid, 'fail', 'player_banned');
    echo json_encode(['status' => 'error', 'message' => 'Account suspended. Speak to the court owner.', 'code' => 'player_banned']);
    exit;
}

// ── CHECK 3: Not already in active game ───────────────────────
$chkG = $db->prepare("
    SELECT gs.id FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE gp.user_id = ? AND gs.status = 'active'
    LIMIT 1
");
$chkG->execute([$uid]);
if ($chkG->fetch()) {
    finalizeLog($db, $logId, $uid, 'warn', 'already_in_game');
    echo json_encode([
        'status'  => 'warn',
        'message' => $pass['full_name'] . ' is already playing!',
        'code'    => 'already_in_game',
        'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
    ]);
    exit;
}

// ── Live wallet balance + pass sync ──────────────────────────
$walletStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$walletStmt->execute([$uid]);
$walletBalance = (float)($walletStmt->fetchColumn() ?? 0);

syncPassActive($db, $uid, $walletBalance);

$passRefresh = $db->prepare("
    SELECT expires_at, is_active FROM falcon.player_passes WHERE user_id = ? LIMIT 1
");
$passRefresh->execute([$uid]);
if ($freshRow = $passRefresh->fetch()) {
    $pass['expires_at'] = $freshRow['expires_at'];
    $pass['is_active']  = $freshRow['is_active'];
}

// ── Reservation scan path ─────────────────────────────────────
$resStmt = $db->prepare("
    SELECT r.id, r.court_id, r.slot_date, r.slot_time, r.slot_end,
           r.party_size, r.booking_group_id, r.arrived_at,
           r.payment_method, r.payment_status,
           c.name AS court_name, c.short_code, c.color AS court_color,
           c.game_duration, c.credit_cost
    FROM falcon.reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.user_id  = ?
      AND r.slot_date = CURRENT_DATE
      AND r.status    IN ('confirmed', 'pending')
      AND r.arrived_at IS NULL
      AND r.slot_time <= (NOW() + INTERVAL '15 minutes')::time
      AND r.slot_end  >  NOW()::time
    ORDER BY r.slot_time ASC
    LIMIT 1
");
$resStmt->execute([$uid]);
$reservation = $resStmt->fetch();

// ── Wrong time scan ───────────────────────────────────────────
if (!$reservation) {
    $otherTimeStmt = $db->prepare("
        SELECT r.slot_date, r.slot_time, r.slot_end, r.status
        FROM falcon.reservations r
        WHERE r.user_id  = ?
          AND r.slot_date = CURRENT_DATE
          AND r.status    IN ('confirmed', 'pending')
          AND r.arrived_at IS NULL
        ORDER BY r.slot_time ASC
        LIMIT 1
    ");
    $otherTimeStmt->execute([$uid]);
    $otherTimeRes = $otherTimeStmt->fetch();

    if ($otherTimeRes) {
        $slotStartFmt = date('g:i A', strtotime($otherTimeRes['slot_time']));
        $slotEndFmt   = date('g:i A', strtotime($otherTimeRes['slot_end']));
        $statusNote   = $otherTimeRes['status'] === 'confirmed' ? 'confirmed' : 'pending confirmation';

        sendNotifications($db, [$uid],
            '⏰ Reservation Not Active Yet',
            "Your reservation is {$statusNote} for {$slotStartFmt}–{$slotEndFmt} today. "
            . "Please scan your QR code during that time window.",
            'warn'
        );

        finalizeLog($db, $logId, $uid, 'fail', 'scanned_outside_reservation_window');
        echo json_encode([
            'status'  => 'error',
            'code'    => 'wrong_scan_time',
            'message' => "⏰ Your reservation is {$statusNote} for {$slotStartFmt}–{$slotEndFmt}. "
                       . "Please come back and scan during that time window.",
            'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
        ]);
        exit;
    }
}

// ── Reservation session path ──────────────────────────────────
if ($reservation) {
    $resId        = (int)$reservation['id'];
    $courtId      = (int)$reservation['court_id'];
    $slotTime     = $reservation['slot_time'];
    $slotEnd      = $reservation['slot_end'];
    $courtName    = $reservation['court_name'];
    $courtShort   = $reservation['short_code'];
    $courtColor   = $reservation['court_color'];
    $payMethod    = $reservation['payment_method'] ?? 'in_person';
    $payStatus    = $reservation['payment_status'] ?? 'unpaid';
    $slotDate     = $reservation['slot_date'];
    $gameDuration = (int)$reservation['game_duration'];
    $creditCost   = (float)$reservation['credit_cost'];

    $slotStartTs  = strtotime($slotDate . ' ' . $slotTime);
    $slotEndTs    = strtotime($slotDate . ' ' . $slotEnd);
    $durationMins = (int)ceil(($slotEndTs - $slotStartTs) / 60);
    $nowTs        = time();
    $secsLeft     = max(0, $slotEndTs - $nowTs);
    $minsLeft     = (int)ceil($secsLeft / 60);
    $lateMinutes  = 0;
    $isLate       = false;

    if ($nowTs > $slotStartTs + 60) {
        $lateMinutes = (int)floor(($nowTs - $slotStartTs) / 60);
        $isLate      = true;
    }

    if ($payMethod === 'online' && $payStatus === 'pending_verification') {
        finalizeLog($db, $logId, $uid, 'warn', 'payment_pending_verification');
        $urgentMsg = "{$pass['full_name']} (@{$pass['username']}) is at the door for their "
                   . date('g:i A', $slotStartTs) . " reservation at {$courtName} ({$courtShort}) "
                   . "but payment is still pending verification. Please verify now.";
        sendNotifications($db, getAdminIds($db),
            "⚠️ Urgent: Verify Payment — {$pass['full_name']}", $urgentMsg, 'warn', $resId);
        sendChatToAdmins($db, "⚠️ URGENT: {$urgentMsg}", $uid);
        echo json_encode([
            'status'  => 'warn',
            'code'    => 'payment_pending_verification',
            'message' => "Welcome {$pass['full_name']}! Your online payment is still being reviewed. "
                       . "Please wait — the admin has been notified.",
            'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
            'court_id'         => $courtId,
            'court_name'       => $courtName,
            'court_short_code' => $courtShort,
            'court_color'      => $courtColor,
        ]);
        exit;
    }

    $isOnlinePaid = ($payMethod === 'online' && $payStatus === 'paid');
    if (!$isOnlinePaid && $walletBalance < $creditCost) {
        finalizeLog($db, $logId, $uid, 'fail', 'insufficient_balance_reservation');
        echo json_encode([
            'status'  => 'error',
            'code'    => 'insufficient_balance',
            'message' => "Insufficient balance. You need ₱" . number_format($creditCost, 2)
                       . " to pay in person. Current balance: ₱" . number_format($walletBalance, 2) . ".",
            'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
        ]);
        exit;
    }

    $chargedAmount   = $isOnlinePaid ? 0.0 : $creditCost;
    $gpPaymentStatus = 'paid';
    $nextSlot        = checkNextSlot($db, $courtId, $slotDate, $slotEnd, $gameDuration);

    try {
        $db->beginTransaction();

        $db->prepare("
            UPDATE falcon.reservations
            SET arrived_at = NOW(), late_minutes = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$lateMinutes, $resId]);

        $existSess = $db->prepare("
            SELECT id FROM falcon.game_sessions
            WHERE reservation_id = ? AND status IN ('active','waiting')
            LIMIT 1
        ");
        $existSess->execute([$resId]);
        $existingSession = $existSess->fetch();

        if ($existingSession) {
            $sessionId = (int)$existingSession['id'];
            $db->prepare("
                INSERT INTO falcon.game_players
                    (session_id, user_id, credits_charged, payment_status, joined_at)
                VALUES (?, ?, ?, ?, NOW())
                ON CONFLICT (session_id, user_id) DO NOTHING
            ")->execute([$sessionId, $uid, $chargedAmount, $gpPaymentStatus]);
        } else {
            $ins = $db->prepare("
                INSERT INTO falcon.game_sessions
                    (court_id, status, started_at, duration_mins, session_type, reservation_id)
                VALUES (?, 'active', NOW(), ?, 'reservation', ?)
                RETURNING id
            ");
            $ins->execute([$courtId, $durationMins, $resId]);
            $sessionId = (int)$ins->fetchColumn();

            $db->prepare("UPDATE falcon.reservations SET session_id = ? WHERE id = ?")
               ->execute([$sessionId, $resId]);

            $db->prepare("
                INSERT INTO falcon.game_players
                    (session_id, user_id, credits_charged, payment_status, joined_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$sessionId, $uid, $chargedAmount, $gpPaymentStatus]);
        }

        if (!$isOnlinePaid && $creditCost > 0) {
            $db->prepare("
                UPDATE falcon.wallets SET balance = balance - ?
                WHERE user_id = ? AND balance >= ?
            ")->execute([$creditCost, $uid, $creditCost]);
            $newBalance = $walletBalance - $creditCost;
            syncPassActive($db, $uid, $newBalance, $courtId);
        }

        $endFmt        = date('g:i A', $slotEndTs);
        $startFmt      = date('g:i A', $slotStartTs);
        $warnExtension = ($secsLeft <= 900 && $secsLeft > 0);

        if ($isOnlinePaid) {
            $playerTitle = $isLate ? '⚠️ Late Arrival — No Charge (Paid Online)' : '🏓 Reservation Active — No Charge';
            $playerMsg   = $isLate
                ? "You arrived {$lateMinutes} min late. Session ends at {$endFmt}. Online payment approved. {$minsLeft} min left."
                : "Welcome! Reserved session on {$courtName} ({$courtShort}) active. Ends {$endFmt}. Online payment approved. {$minsLeft} min remaining.";
        } else {
            $playerTitle = $isLate ? '⚠️ Late Arrival — Session Started' : '🏓 Your Reservation Session Has Started';
            $playerMsg   = $isLate
                ? "You arrived {$lateMinutes} min late. Session on {$courtName} ({$courtShort}) ends at {$endFmt}. {$minsLeft} min remaining."
                : "Welcome! Session at {$courtName} ({$courtShort}) has started. Ends at {$endFmt} ({$minsLeft} min remaining).";
        }
        sendNotifications($db, [$uid], $playerTitle, $playerMsg, $isLate ? 'warn' : 'success', $resId);

        $payNote  = $isOnlinePaid ? ' [Pre-paid online]' : ' [Paid in person]';
        $adminMsg = $isLate
            ? "{$pass['full_name']} (@{$pass['username']}) arrived {$lateMinutes} min late for {$startFmt} reservation at {$courtName} ({$courtShort}). Ends {$endFmt}.{$payNote}"
            : "{$pass['full_name']} (@{$pass['username']}) scanned in for {$startFmt}–{$endFmt} at {$courtName} ({$courtShort}).{$payNote}";
        sendNotifications($db, getAdminIds($db),
            $isLate ? "⚠️ Late Arrival — {$pass['full_name']}" : "🏓 {$pass['full_name']} Arrived for Reservation",
            $adminMsg, $isLate ? 'warn' : 'info', $resId);
        sendChatToAdmins($db, ($isLate ? '🚨 LATE: ' : '✅ ARRIVED: ') . $adminMsg, $uid);

        $extensionBlocked       = false;
        $extensionBlockedReason = null;
        $nextStartFmt = date('g:i A', strtotime($slotDate . ' ' . $nextSlot['start']));
        $nextEndFmt   = date('g:i A', strtotime($slotDate . ' ' . $nextSlot['end']));
        if (!$nextSlot['available']) {
            $extensionBlocked       = true;
            $extensionBlockedReason = $nextSlot['booked_by']
                ? "The next slot ({$nextStartFmt}–{$nextEndFmt}) is already reserved."
                : "The next slot ({$nextStartFmt}–{$nextEndFmt}) is not available (outside court hours).";
        }

        $db->commit();

        // Update last scanned timestamp for one-time use enforcement
        $db->prepare("UPDATE falcon.player_passes SET last_scanned_at = NOW() WHERE user_id = ?")->execute([$uid]);

        $hLeft       = floor($secsLeft / 3600);
        $mLeftFmt    = floor(($secsLeft % 3600) / 60);
        $timeLeftStr = $hLeft > 0 ? "{$hLeft}h {$mLeftFmt}m" : "{$mLeftFmt}m";

        finalizeLog($db, $logId, $uid, 'success',
            'reservation_scan,session=' . $sessionId
            . ($isLate ? ',late=' . $lateMinutes . 'min' : '')
            . ($isOnlinePaid ? ',paid_online' : ',in_person')
        );

        echo json_encode([
            'status'                   => $isLate ? 'warn' : 'success',
            'code'                     => 'reservation_scan',
            'message'                  => $isLate
                ? "Welcome {$pass['full_name']}! You are {$lateMinutes} min late. Session ends at {$endFmt}."
                : "Welcome {$pass['full_name']}! Your reserved session at {$courtName} ({$courtShort}) is now active. Ends at {$endFmt}.",
            'is_reservation'           => true,
            'is_late'                  => $isLate,
            'late_minutes'             => $lateMinutes,
            'slot_start'               => $startFmt,
            'slot_end'                 => $endFmt,
            'slot_end_ts'              => $slotEndTs,
            'slot_date'                => $slotDate,
            'court_id'                 => $courtId,
            'court_name'               => $courtName,
            'court_short_code'         => $courtShort,
            'court_color'              => $courtColor,
            'secs_remaining'           => $secsLeft,
            'time_remaining'           => $timeLeftStr,
            'session_id'               => $sessionId,
            'is_online_paid'           => $isOnlinePaid,
            'warn_extension'           => $warnExtension,
            'extension_url'            => APP_URL . '/player/schedule.php',
            'next_slot_available'      => $nextSlot['available'],
            'next_slot_start'          => $nextSlot['start'],
            'next_slot_end'            => $nextSlot['end'],
            'next_slot_start_fmt'      => $nextStartFmt,
            'next_slot_end_fmt'        => $nextEndFmt,
            'next_slot_spots_left'     => $nextSlot['spots_left'],
            'next_slot_booked_by'      => $nextSlot['booked_by'],
            'extension_blocked'        => $extensionBlocked,
            'extension_blocked_reason' => $extensionBlockedReason,
            'player'                   => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
        ]);

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[process_scan] reservation: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        finalizeLog($db, $logId, $uid, 'fail', 'db_error: ' . substr($e->getMessage(), 0, 120));
        echo json_encode(['status' => 'error', 'message' => 'System error. Please try again.', 'code' => 'db_error']);
    }
    exit;
}

// ── Reserved by someone else? ─────────────────────────────────
$reservedSlotStmt = $db->prepare("
    SELECT r.court_id, r.slot_time, r.slot_end,
           c.name AS court_name, u.full_name AS reserved_by
    FROM falcon.reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    JOIN falcon.users u ON u.id = r.user_id
    WHERE r.slot_date = CURRENT_DATE
      AND r.status    IN ('confirmed', 'pending')
      AND r.slot_time <= NOW()::time
      AND r.slot_end  >= NOW()::time
      AND r.user_id  != ?
      AND c.is_active = TRUE
      AND c.is_maintenance = FALSE
    ORDER BY r.slot_time ASC
    LIMIT 1
");
$reservedSlotStmt->execute([$uid]);
$reservedByOther = $reservedSlotStmt->fetch();

$openCourtCheck = $db->query("
    SELECT COUNT(*) FROM falcon.courts c
    LEFT JOIN falcon.game_sessions gs ON gs.court_id = c.id AND gs.status = 'active'
    WHERE c.is_active = TRUE AND c.is_maintenance = FALSE AND gs.id IS NULL
")->fetchColumn();

if ($reservedByOther && $openCourtCheck == 0) {
    finalizeLog($db, $logId, $uid, 'fail', 'court_reserved_by_other');
    sendNotifications($db, [$uid],
        '🚫 Courts Reserved',
        "All courts are currently reserved. Open play is not available right now. Check the schedule for open slots.",
        'warn'
    );
    echo json_encode([
        'status'  => 'error',
        'code'    => 'court_reserved',
        'message' => "⛔ All courts are currently reserved. Open play is unavailable right now. Check the schedule for open slots.",
        'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
    ]);
    exit;
}

// ── Open-play path ────────────────────────────────────────────
$court = findBestAvailableCourt($db);

if (!$court) {
    finalizeLog($db, $logId, $uid, 'fail', 'no_court_available');
    echo json_encode([
        'status'  => 'error',
        'code'    => 'no_court_available',
        'message' => 'All courts are currently full or unavailable. Please wait and try again.',
        'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
    ]);
    exit;
}

$courtId      = (int)$court['id'];
$creditCost   = (float)$court['credit_cost'];
$gameDuration = (int)$court['game_duration'];
$passHours    = max(1, (int)($court['pass_hours'] ?? 8));

if ($walletBalance < $creditCost) {
    finalizeLog($db, $logId, $uid, 'fail', 'insufficient_balance');
    echo json_encode([
        'status'  => 'error',
        'message' => "Insufficient balance. You need ₱" . number_format($creditCost, 2)
                   . " to play. Current balance: ₱" . number_format($walletBalance, 2)
                   . ". Please top up to continue.",
        'code'    => 'insufficient_balance',
        'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
    ]);
    exit;
}

$isFirstScan = (strtotime($pass['expires_at']) > strtotime('+1 year'));
$isExpired   = !$isFirstScan && (strtotime($pass['expires_at']) < time());

if ($isExpired) {
    $db->prepare("
        UPDATE falcon.player_passes
        SET is_active = FALSE, expires_at = '2099-12-31 23:59:59+00'
        WHERE user_id = ?
    ")->execute([$uid]);
    finalizeLog($db, $logId, $uid, 'fail', 'pass_expired');
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your day pass has expired. Please top up to play again.',
        'code'    => 'pass_expired',
        'player'  => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
    ]);
    exit;
}

$expiresAt = $pass['expires_at'];
if ($isFirstScan) {
    $db->prepare("
        UPDATE falcon.player_passes
        SET expires_at = NOW() + (? || ' hours')::INTERVAL
        WHERE user_id = ?
    ")->execute([$passHours, $uid]);
    $expiresAt = date('Y-m-d H:i:sP', strtotime("+{$passHours} hours"));
}

// ── Already queued? ───────────────────────────────────────────
$chkQ = $db->prepare("SELECT id, court_id FROM falcon.game_queue WHERE user_id = ? AND session_id IS NULL LIMIT 1");
$chkQ->execute([$uid]);
$existingQueue = $chkQ->fetch();

if ($existingQueue) {
    $posStmt = $db->prepare("
        SELECT position FROM (
            SELECT user_id, ROW_NUMBER() OVER (ORDER BY joined_at) AS position
            FROM falcon.game_queue
            WHERE session_id IS NULL AND court_id = ?
        ) r WHERE user_id = ?
    ");
    $posStmt->execute([(int)$existingQueue['court_id'], $uid]);
    $pos = (int)($posStmt->fetchColumn() ?: 1);

    $qCourtStmt = $db->prepare("SELECT name, short_code FROM falcon.courts WHERE id = ? LIMIT 1");
    $qCourtStmt->execute([(int)$existingQueue['court_id']]);
    $qCourt = $qCourtStmt->fetch();

    finalizeLog($db, $logId, $uid, 'warn', 'already_queued at #' . $pos);
    echo json_encode([
        'status'           => 'warn',
        'message'          => $pass['full_name'] . ' is already in the queue for ' . ($qCourt['name'] ?? 'a court') . ' at #' . $pos . '.',
        'code'             => 'already_queued',
        'queue_position'   => $pos,
        'court_id'         => (int)$existingQueue['court_id'],
        'court_name'       => $qCourt['name'] ?? null,
        'court_short_code' => $qCourt['short_code'] ?? null,
        'player'           => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
    ]);
    exit;
}

try {
    $db->beginTransaction();

    $db->prepare("
        INSERT INTO falcon.game_queue (user_id, pass_id, session_id, court_id, joined_at)
        VALUES (?, ?, NULL, ?, NOW())
    ")->execute([$uid, $pass['pass_id'], $courtId]);

    $db->prepare("
        UPDATE falcon.wallets SET balance = balance - ?
        WHERE user_id = ? AND balance >= ?
    ")->execute([$creditCost, $uid, $creditCost]);

    $newBalance = $walletBalance - $creditCost;
    syncPassActive($db, $uid, $newBalance, $courtId);

    // Queue position within this court
    $posStmt = $db->prepare("
        SELECT position FROM (
            SELECT user_id, ROW_NUMBER() OVER (ORDER BY joined_at) AS position
            FROM falcon.game_queue
            WHERE session_id IS NULL AND (court_id = ? OR court_id IS NULL)
        ) r WHERE user_id = ?
    ");
    $posStmt->execute([$courtId, $uid]);
    $queuePos = (int)($posStmt->fetchColumn() ?: 1);

    // PATCH 2: Fixed queueTotal count — clean prepared statement,
    // includes court_id IS NULL fallback for pre-migration rows.
    $qtStmt = $db->prepare("
        SELECT COUNT(*) FROM falcon.game_queue
        WHERE session_id IS NULL
          AND (court_id = ? OR court_id IS NULL)
    ");
    $qtStmt->execute([$courtId]);
    $queueTotal = (int)$qtStmt->fetchColumn();
    if ($queueTotal < 1) $queueTotal = $queuePos; // safety floor

    $secsLeft = max(0, strtotime($expiresAt) - time());
    $hLeft    = floor($secsLeft / 3600);
    $mLeft    = floor(($secsLeft % 3600) / 60);
    $timeLeft = $hLeft > 0 ? "{$hLeft}h {$mLeft}m" : "{$mLeft}m";

    sendNotifications($db, [$uid],
        '⏳ Queued for ' . $court['name'] . ' (' . $court['short_code'] . ')',
        "You're #{$queuePos} in queue for {$court['name']} ({$court['short_code']}). ₱" . number_format($creditCost, 2) . " deducted. Balance: ₱" . number_format($newBalance, 2) . ".",
        'info'
    );

    $adminArrivalMsg = "{$pass['full_name']} (@{$pass['username']}) scanned in — open play on {$court['name']} ({$court['short_code']}), #{$queuePos} in queue. ₱" . number_format($creditCost, 2) . " deducted.";
    sendNotifications($db, getAdminIds($db), "🏓 {$pass['full_name']} Arrived at Court", $adminArrivalMsg, 'info');
    sendChatToAdmins($db, "🏓 OPEN PLAY: {$adminArrivalMsg}", $uid);

    $db->commit();

    // Update last scanned timestamp for one-time use enforcement
    $db->prepare("UPDATE falcon.player_passes SET last_scanned_at = NOW() WHERE user_id = ?")->execute([$uid]);

    finalizeLog($db, $logId, $uid, 'success', 'queued at #' . $queuePos . ' on court ' . $courtId);

    // PATCH 1: Set _ticker_court_id so game_ticker v4 only checks
    // this court rather than iterating all courts.
    $tickerResult = null;
    try {
        $GLOBALS['_ticker_result']   = null;
        $GLOBALS['_ticker_court_id'] = $courtId;
        define('GAME_TICKER_INTERNAL', 1);
        require __DIR__ . '/game_ticker.php';
        $tickerResult = $GLOBALS['_ticker_result'] ?? null;
    } catch (Throwable $e) {
        error_log('[process_scan] ticker error: ' . $e->getMessage());
    }

    $gameStarted  = isset($tickerResult['status']) && $tickerResult['status'] === 'started';
    $game_message = $gameStarted ? ($tickerResult['message'] ?? null) : null;

    echo json_encode([
        'status'           => 'success',
        'message'          => "Welcome {$pass['full_name']}! You are #{$queuePos} in queue for {$court['name']} ({$court['short_code']}). ₱" . number_format($creditCost, 2) . " deducted.",
        'code'             => 'queued',
        'is_reservation'   => false,
        'queue_position'   => $queuePos,
        'queue_total'      => $queueTotal,
        'court_id'         => $courtId,
        'court_name'       => $court['name'],
        'court_short_code' => $court['short_code'],
        'court_color'      => $court['color'],
        'game_started'     => $gameStarted,
        'game_message'     => $game_message,
        'ready_to_warmup'  => ($queueTotal % PLAYERS_PER_GAME === 0),
        'warmup_mins'      => (int)$court['warmup_mins'],
        'game_duration'    => $gameDuration,
        'pass_expires_at'  => $expiresAt,
        'pass_time_left'   => $timeLeft,
        'credit_deducted'  => $creditCost,
        'balance_after'    => $newBalance,
        'player'           => ['full_name' => $pass['full_name'], 'username' => $pass['username']],
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[process_scan] open_play: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    finalizeLog($db, $logId, $uid, 'fail', 'db_error: ' . substr($e->getMessage(), 0, 120));
    echo json_encode(['status' => 'error', 'message' => 'System error. Please try again.', 'code' => 'db_error']);
}