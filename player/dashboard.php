<?php
// ============================================================
//  FILE: player/dashboard.php — CSP + Responsive Fix
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$walletStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$walletStmt->execute([$uid]);
$balance = (float)($walletStmt->fetchColumn() ?? 0);

$passStmt = $db->prepare("SELECT id, qr_token, is_active FROM falcon.player_passes WHERE user_id = ? LIMIT 1");
$passStmt->execute([$uid]);
$pass = $passStmt->fetch();

if (!$pass) {
    $token = bin2hex(random_bytes(24));
    try {
        $db->prepare("
            INSERT INTO falcon.player_passes (user_id, qr_token, expires_at, is_active)
            VALUES (?, ?, '2099-12-31 23:59:59+00', FALSE)
            ON CONFLICT (user_id) DO NOTHING
        ")->execute([$uid, $token]);
    } catch (PDOException $e) {
        error_log('dashboard pass auto-create error: ' . $e->getMessage());
    }
    $passStmt->execute([$uid]);
    $pass = $passStmt->fetch();
}

$isActive   = $pass ? syncPassActive($db, $uid, $balance) : false;
$creditCost = getCourtCreditCost();

$allCourts = $db->query("SELECT id, name, short_code, color, live_status FROM falcon.v_court_status WHERE is_active = TRUE ORDER BY sort_order, id")->fetchAll();

$playerQueues = $db->prepare("
    SELECT gq.court_id, c.name, c.short_code,
           ROW_NUMBER() OVER (PARTITION BY gq.court_id ORDER BY gq.joined_at) AS position,
           COUNT(*) OVER (PARTITION BY gq.court_id) AS total_in_queue
    FROM falcon.game_queue gq
    JOIN falcon.courts c ON c.id = gq.court_id
    WHERE gq.user_id = ? AND gq.session_id IS NULL
    ORDER BY gq.court_id
");
$playerQueues->execute([$uid]);
$queuePositions = $playerQueues->fetchAll();
$queueByCourt = [];
foreach ($queuePositions as $qp) { $queueByCourt[(int)$qp['court_id']] = $qp; }

$historyStmt = $db->prepare("
    SELECT gs.id, gs.started_at, gs.ended_at, gs.status,
           c.name AS court_name, gp.credits_charged,
           (SELECT STRING_AGG(u2.username, ', ')
            FROM falcon.game_players gp2
            JOIN falcon.users u2 ON u2.id = gp2.user_id
            WHERE gp2.session_id = gs.id AND gp2.user_id != ?) AS teammates
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    JOIN falcon.courts c ON c.id = gs.court_id
    WHERE gp.user_id = ?
    ORDER BY gs.started_at DESC LIMIT 5
");
$historyStmt->execute([$uid, $uid]);
$recentGames = $historyStmt->fetchAll();

$totalGamesStmt = $db->prepare("SELECT COUNT(*) FROM falcon.game_players WHERE user_id = ?");
$totalGamesStmt->execute([$uid]);
$gamesPlayed = (int)$totalGamesStmt->fetchColumn();

$totalSpentStmt = $db->prepare("SELECT COALESCE(SUM(credits_charged), 0) FROM falcon.game_players WHERE user_id = ?");
$totalSpentStmt->execute([$uid]);
$creditsSpent = (float)$totalSpentStmt->fetchColumn();

$queueCount = (int)$db->query("SELECT COUNT(*) FROM falcon.game_queue WHERE session_id IS NULL")->fetchColumn();

$notifStmt = $db->prepare("
    SELECT id, title, message, type, created_at
    FROM falcon.notifications
    WHERE user_id = ? AND is_read = FALSE
    ORDER BY created_at DESC LIMIT 5
");
$notifStmt->execute([$uid]);
$notifications = $notifStmt->fetchAll();

$liveSession = $db->query("
    SELECT gs.id, gs.status, gs.started_at, gs.session_type, c.name AS court_name,
           COUNT(gp.id) AS player_count, c.max_queue
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE gs.status IN ('active','waiting') AND DATE(gs.started_at) = CURRENT_DATE
    GROUP BY gs.id, gs.status, gs.started_at, gs.session_type, c.name, c.max_queue
    ORDER BY gs.started_at DESC LIMIT 1
")->fetch();

$isOpenPlayTonight = true;
try {
    $openPlayMode = $db->query("
        SELECT mode FROM falcon.court_slot_modes
        WHERE court_id = (SELECT id FROM falcon.courts WHERE is_active = TRUE ORDER BY id LIMIT 1)
          AND time_from <= '20:00:00' AND time_to >= '20:00:00'
          AND ((slot_date = CURRENT_DATE) OR (slot_date IS NULL AND day_of_week = EXTRACT(DOW FROM CURRENT_DATE)::int))
        ORDER BY CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END ASC LIMIT 1
    ")->fetch();
    if ($openPlayMode) $isOpenPlayTonight = ($openPlayMode['mode'] === 'open_play');
} catch (PDOException $e) {}

$currentMode = 'reservation';
try {
    $nowTime = date('H:i:s');
    $curMode = $db->query("
        SELECT mode FROM falcon.court_slot_modes
        WHERE court_id = (SELECT id FROM falcon.courts WHERE is_active = TRUE ORDER BY id LIMIT 1)
          AND time_from <= '$nowTime' AND time_to > '$nowTime'
          AND ((slot_date = CURRENT_DATE) OR (slot_date IS NULL AND day_of_week = EXTRACT(DOW FROM CURRENT_DATE)::int))
        ORDER BY CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END ASC LIMIT 1
    ")->fetch();
    if ($curMode) $currentMode = $curMode['mode'];
} catch (PDOException $e) {}

$myResStmt = $db->prepare("
    SELECT r.id, r.slot_time, r.slot_end, r.status, c.name AS court_name
    FROM falcon.reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.user_id = ? AND r.slot_date = CURRENT_DATE AND r.status IN ('confirmed','pending')
    ORDER BY r.slot_time ASC LIMIT 3
");
$myResStmt->execute([$uid]);
$myTodayRes = $myResStmt->fetchAll();

$activeReservationNow = $db->query("
    SELECT r.slot_time, r.slot_end, r.party_size, r.status, u.full_name, u.username, c.name AS court_name
    FROM falcon.reservations r
    JOIN falcon.users u ON u.id = r.user_id
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.slot_date = CURRENT_DATE AND r.status = 'confirmed'
      AND r.slot_time <= NOW()::time AND r.slot_end > NOW()::time
    ORDER BY r.slot_time ASC LIMIT 1
")->fetch();

$reservedSlotsToday = $db->query("
    SELECT COUNT(*) FROM falcon.reservations
    WHERE slot_date = CURRENT_DATE AND status IN ('pending','confirmed')
")->fetchColumn();

$nextResStmt = $db->prepare("
    SELECT r.slot_date, r.slot_time, r.slot_end, r.status, r.payment_status, c.name AS court_name
    FROM falcon.reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.user_id = ? AND r.slot_date >= CURRENT_DATE AND r.status IN ('pending','confirmed')
    ORDER BY r.slot_date ASC, r.slot_time ASC LIMIT 1
");
$nextResStmt->execute([$uid]);
$nextReservation = $nextResStmt->fetch();

$activityBookings = [];
try {
    $actBookStmt = $db->prepare("
        SELECT ab.id, ab.booking_date, ab.start_time, ab.end_time,
               ab.status, ab.payment_status, ab.party_size,
               at2.name AS act_name, at2.icon AS act_icon
        FROM falcon.activity_bookings ab
        JOIN falcon.activity_types at2 ON at2.id = ab.activity_type_id
        WHERE ab.user_id = ? AND ab.booking_date >= CURRENT_DATE AND ab.status != 'cancelled'
        ORDER BY ab.booking_date ASC, ab.start_time ASC LIMIT 5
    ");
    $actBookStmt->execute([$uid]);
    $activityBookings = $actBookStmt->fetchAll();
} catch (PDOException $e) {}

$liveCourtData = $db->query("
    SELECT gs.id, gs.started_at, gs.duration_mins, gs.session_type,
           COUNT(gp.id) AS player_count,
           c.max_queue, c.name AS court_name, c.game_duration
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE gs.status = 'active'
    GROUP BY gs.id, gs.started_at, gs.duration_mins, gs.session_type,
             c.max_queue, c.name, c.game_duration
    ORDER BY gs.started_at DESC LIMIT 1
")->fetch();

$liveQueueCount      = (int)$db->query("SELECT COUNT(*) FROM falcon.game_queue WHERE session_id IS NULL")->fetchColumn();
$todayCompletedGames = (int)$db->query("SELECT COUNT(*) FROM falcon.game_sessions WHERE DATE(started_at) = CURRENT_DATE AND status = 'completed'")->fetchColumn();

$liveCourtEndTs = null;
if ($liveCourtData) {
    if ($liveCourtData['session_type'] === 'reservation') {
        $liveResRow = $db->query("
            SELECT r.slot_end, r.slot_date FROM falcon.reservations r
            JOIN falcon.game_sessions gs ON gs.reservation_id = r.id
            WHERE gs.status = 'active' LIMIT 1
        ")->fetch();
        if ($liveResRow) $liveCourtEndTs = strtotime($liveResRow['slot_date'] . ' ' . $liveResRow['slot_end']);
    } else {
        $liveCourtEndTs = strtotime($liveCourtData['started_at']) + ($liveCourtData['duration_mins'] * 60);
    }
}

$liveRemSecs  = $liveCourtEndTs ? max(0, $liveCourtEndTs - time()) : 0;
$maxSlots     = $liveCourtData ? (int)$liveCourtData['max_queue'] : PLAYERS_PER_GAME;
$onCourtNow   = $liveCourtData ? (int)$liveCourtData['player_count'] : 0;
$isResSession = $liveCourtData && $liveCourtData['session_type'] === 'reservation';

$courtIsVacantNow = empty($activeReservationNow);
if ($currentMode === 'open_play') {
    $modeChipClass = 'open'; $modeChipLabel = '🎮 Open Play NOW';
} elseif ($courtIsVacantNow) {
    $modeChipClass = 'open'; $modeChipLabel = '🎮 Walk-ins OK';
} else {
    $modeChipClass = 'res'; $modeChipLabel = '📅 Reserved — No Walk-ins';
}

$pageTitle = 'My Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<?= breadcrumb([
    ['label' => 'Home', 'href' => APP_URL],
    ['label' => 'Dashboard']
]) ?>
<?php
?>

<style nonce="<?= getCspNonce() ?>">
/* ══════════════════════════════════════════════════════════════
   PLAYER DASHBOARD — FULLY RESPONSIVE + CSP-SAFE
══════════════════════════════════════════════════════════════ */

.dash-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 0 32px;
}

/* ── Page Header ── */
.dash-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.dash-header-left h1 { margin: 0 0 2px; font-size: clamp(20px, 5vw, 30px); }
.dash-header-left p  { margin: 0; font-size: 14px; color: var(--muted); }
.dash-header-left-name { color: var(--text); }
.dash-header-actions   { display: flex; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }

/* ── Notifications ── */
.notif-banner { margin-bottom: 6px; border-radius: 10px; }
.notif-close  { background: none; border: none; cursor: pointer; color: var(--muted); font-size: 14px; padding: 4px; }

/* ── Today Strip ── */
.today-strip {
    background: linear-gradient(135deg, rgba(0,229,160,0.07), rgba(0,184,255,0.05));
    border: 1px solid rgba(0,229,160,0.2);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.today-strip-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 13px;
    letter-spacing: 1px;
    color: var(--accent);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}
.today-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.today-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    line-height: 1;
}
.today-chip.live    { border-color: var(--accent2); color: var(--accent2); background: rgba(0,184,255,0.08); }
.today-chip.open    { border-color: var(--accent);  color: var(--accent);  background: rgba(0,229,160,0.08); }
.today-chip.res     { border-color: var(--warn);    color: var(--warn);    background: rgba(245,158,11,0.08); }
.today-chip.pending { border-color: var(--muted);   color: var(--muted); }
.today-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; animation: tdPulse 2s infinite; }
@keyframes tdPulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

/* ── Stats Row ── */
.dash-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
    margin-bottom: 16px;
}
.dash-stat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 12px;
    text-align: center;
    transition: border-color .2s;
    min-width: 0;
}
.dash-stat:hover { border-color: rgba(0,229,160,0.3); }
.dash-stat-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(16px, 3.5vw, 26px);
    color: var(--accent);
    line-height: 1;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dash-stat-label {
    font-size: 10px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 600;
}

/* ── Court Status Card ── */
.court-status-strip {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 12px;
}
.court-status-pill {
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: opacity .2s;
}
.court-status-pill:hover { opacity: 0.85; }

/* ── Queue card ── */
.queue-alert-card {
    background: rgba(245,158,11,0.08);
    border: 1px solid rgba(245,158,11,0.25);
    margin-bottom: 16px;
}
.queue-alert-title { color: #f59e0b; }
.queue-pos-box {
    margin-top: 8px;
    padding: 10px 12px;
    background: var(--surface);
    border-radius: 8px;
    border: 1px solid rgba(245,158,11,0.2);
}
.queue-pos-title { font-weight: 700; font-size: 14px; color: #f59e0b; }
.queue-pos-meta  { font-size: 12px; color: var(--muted); margin-top: 4px; }
/* ══ COMPACT SCHEDULE STRIP — shared player + admin ══════════ */
.csc-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 20px;
}
.csc-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.csc-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.csc-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 15px;
    letter-spacing: 1.5px;
    color: var(--text);
}
.csc-head-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.csc-live-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
    animation: cscPulse 2s infinite;
}
@keyframes cscPulse {
    0%,100%{opacity:1;transform:scale(1)}
    50%{opacity:.3;transform:scale(.8)}
}
.csc-updated {
    font-size: 11px;
    color: var(--muted);
    font-family: monospace;
}
.csc-legend {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 8px;
}
.csc-leg { display: flex; align-items: center; gap: 4px; }
.csc-leg-dot {
    width: 6px; height: 6px;
    border-radius: 50%; flex-shrink: 0;
}

/* ── Horizontal scroll strip ── */
.csc-strip-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
    margin: 0 -2px;
}
.csc-strip-wrap::-webkit-scrollbar { height: 3px; }
.csc-strip-wrap::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 99px;
}
.csc-strip {
    display: flex;
    gap: 6px;
    min-width: max-content;
    padding: 2px 2px 4px;
}

/* ── Individual slot pill ── */
.csc-slot {
    flex-shrink: 0;
    width: 82px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 8px 9px;
    cursor: pointer;
    transition: border-color .15s, transform .15s;
    position: relative;
}
.csc-slot:hover { border-color: rgba(0,229,160,.4); transform: translateY(-1px); }
.csc-slot.csc-now  { border-color: var(--accent2); box-shadow: 0 0 0 1.5px var(--accent2); }
.csc-slot.csc-past { opacity: .35; pointer-events: none; }
.csc-slot.csc-full { cursor: default; }
.csc-now-badge {
    position: absolute;
    top: 5px; right: 6px;
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent2);
    animation: cscPulse 2s infinite;
}
.csc-slot-time {
    font-family: 'Space Mono', monospace;
    font-size: 11px;
    color: var(--text);
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.csc-slot-status {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 5px;
    white-space: nowrap;
}
.csc-sd { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
.csc-pips { display: flex; gap: 2px; flex-wrap: wrap; }
.csc-pip  { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; transition: background .3s; }
.csc-count { font-size: 10px; color: var(--muted); margin-top: 3px; }

.csc-footer {
    text-align: center;
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid rgba(255,255,255,0.05);
}
.csc-skeleton {
    background: var(--surface2);
    border-radius: 10px;
    width: 82px;
    height: 86px;
    opacity: .15;
    flex-shrink: 0;
}

/* ── Slots card header ── */
.slots-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}
.slots-card-header-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.slots-live-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--muted);
    font-family: monospace;
}
.slots-legend {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 14px;
}
.slots-legend-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    margin-right: 4px;
    vertical-align: middle;
}
.slots-footer { text-align: center; margin-top: 16px; }

/* ── QR + Game Status grid ── */
.dash-main-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

/* ── QR Card ── */
.qr-card-subtitle { text-align: center; /* inherits card-subtitle */ }
.qr-inner { display: flex; flex-direction: column; align-items: center; gap: 12px; }
.qr-box {
    background: #fff;
    border-radius: 16px;
    padding: 14px 12px 10px;
    width: 100%;
    max-width: 190px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.35);
    position: relative;
}
.qr-box-inactive { opacity: 0.5; }
.qr-inactive-stamp {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%,-50%) rotate(-25deg);
    font-family: 'Bebas Neue', sans-serif;
    font-size: 26px; color: rgba(239,68,68,0.6);
    letter-spacing: 3px; pointer-events: none; white-space: nowrap;
}
.qr-token-hint {
    margin-top: 6px; font-family: monospace;
    font-size: 8px; color: #bbb; letter-spacing: 1px;
    word-break: break-all; line-height: 1.4; text-align: center;
}
.qr-active-badge   { font-size: 12px; padding: 6px 16px; }
.qr-info {
    font-size: 12px; color: var(--muted);
    line-height: 1.7; text-align: center;
}
.qr-info strong      { color: var(--text); }
.qr-info-cost        { color: var(--accent); }
.qr-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
.qr-refresh-wrap { text-align: center; padding: 32px; }
.qr-refresh-icon { font-size: 44px; margin-bottom: 10px; }
.qr-refresh-text { color: var(--muted); font-size: 14px; }

/* ── Game Status Card ── */
.game-status-title    { text-align: center; }
.game-status-subtitle { text-align: center; }
.game-timer {
    font-size: 40px;
    font-family: 'Bebas Neue', sans-serif;
    color: var(--accent);
    margin-top: 12px;
}
.res-session-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(0,184,255,0.12); border: 1px solid rgba(0,184,255,0.3);
    border-radius: 20px; padding: 4px 12px;
    font-size: 11px; font-weight: 700; color: var(--accent2); margin-top: 8px;
}

/* ── Next Reservation in game status ── */
.next-res-court { font-size: inherit; /* inherits from scanner-sub */ }
.next-res-datetime {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 26px;
    color: var(--warn);
    margin-top: 8px;
    line-height: 1.2;
}
.next-res-until { font-size: 12px; color: var(--muted); margin-top: 4px; }
.next-res-actions { margin-top: 12px; }

/* ── Activity bookings ── */
.act-booking-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    flex-wrap: wrap;
}
.act-booking-row:last-child { border-bottom: none; }
.act-icon-sm {
    width: 36px; height: 36px; border-radius: 10px;
    background: rgba(0,229,160,0.1);
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
.act-booking-name { font-weight: 700; font-size: 13px; }
.act-booking-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
.act-booking-right {
    margin-left: auto; text-align: right; flex-shrink: 0;
    display: flex; flex-direction: column; align-items: flex-end; gap: 3px;
}
.act-booking-header {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}
.act-booking-footer { text-align: center; margin-top: 12px; }

/* ── Recent games ── */
.recent-games-header {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}
.td-game-date { white-space: nowrap; font-size: 12px; }
.td-game-court { font-size: 13px; }
.td-game-mates { font-size: 12px; color: var(--muted); }
.td-game-credits { color: var(--danger); white-space: nowrap; font-weight: 600; }

/* ── Low balance / welcome banner ── */
.alert-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 16px;
    margin-bottom: 12px;
    border-radius: 12px;
    flex-wrap: wrap;
}
.alert-banner-warn {
    background: rgba(245,158,11,0.07);
    border: 1px solid rgba(245,158,11,0.25);
}
.alert-banner-info {
    background: rgba(0,229,160,0.06);
    border: 1px solid rgba(0,229,160,0.2);
}
.alert-banner-emoji   { font-size: 22px; flex-shrink: 0; }
.alert-banner-body    { flex: 1; min-width: 0; }
.alert-banner-title   { font-weight: 700; font-size: 13px; }
.alert-banner-title-warn { color: #f59e0b; }
.alert-banner-title-info { color: var(--accent); }
.alert-banner-sub     { font-size: 12px; color: var(--muted); margin-top: 2px; }
.alert-banner-sub a   { color: var(--accent); text-decoration: none; font-weight: 600; }
.alert-banner-action  { font-size: 11px; flex-shrink: 0; }

/* ── Quick links ── */
.dash-quick-links {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 10px;
    margin-top: 16px;
}
.dash-quick-links a {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 5px; padding: 14px 8px;
    border-radius: 12px; background: var(--surface);
    border: 1px solid var(--border);
    font-size: 12px; font-weight: 600;
    color: var(--text); text-decoration: none;
    transition: border-color .2s, background .2s;
    text-align: center; min-height: 72px; min-width: 0;
}
.dash-quick-links a span { font-size: 20px; }
.dash-quick-links a:hover { border-color: var(--accent); background: rgba(0,229,160,0.06); }

/* ══════════════════════════════════════════════════════════════
   RESPONSIVE BREAKPOINTS
══════════════════════════════════════════════════════════════ */

@media (max-width: 900px) {
    .dash-stats       { grid-template-columns: repeat(3, 1fr); }
    .dash-quick-links { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 600px) {
    .dash-header-actions .btn-outline { display: none; }
    .dash-stats   { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .dash-stat    { padding: 10px 8px; }
    .dash-main-grid   { grid-template-columns: 1fr; gap: 12px; }
    .dash-quick-links { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    #dash-slots-grid  { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
}

@media (max-width: 480px) {
    .dash-header { margin-bottom: 14px; }
    .dash-header-left h1 { font-size: 20px; }
    .dash-header-left p  { font-size: 13px; }
    .dash-header-actions { gap: 6px; }
    .dash-header-actions a { padding: 8px 14px; font-size: 13px; }
    .today-strip { padding: 12px 14px; }

    .dash-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .dash-stat:first-child {
        grid-column: 1 / -1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        text-align: left;
        padding: 12px 16px;
    }
    .dash-stat:first-child .dash-stat-val   { font-size: 22px; margin-bottom: 0; }
    .dash-stat:first-child .dash-stat-label { font-size: 11px; }

    .dash-main-grid   { grid-template-columns: 1fr; gap: 12px; }
    .dash-quick-links { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .dash-quick-links a { padding: 12px 6px; font-size: 11px; min-height: 64px; }
    .dash-quick-links a span { font-size: 18px; }

    .game-timer { font-size: 34px !important; }
}

@media (max-width: 380px) {
    #dash-slots-grid  { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .dash-stats       { grid-template-columns: repeat(2, 1fr); gap: 6px; }
    .dash-stat:first-child { grid-column: 1 / -1; }
    .dash-quick-links { grid-template-columns: repeat(2, 1fr); gap: 6px; }
    .today-chip       { font-size: 11px; padding: 4px 10px; }
}

@media (max-width: 360px) {
    .dash-stats { grid-template-columns: 1fr 1fr; gap: 6px; }
    .dash-quick-links a { font-size: 10px; }
    #dash-slots-grid { grid-template-columns: repeat(2, 1fr); }
}
/* ── Leaderboard widget ──────────────────────────────────────
   Previously unstyled — .lb-widget etc. had zero CSS rules
   anywhere in the app, so it rendered as bare browser-default
   text/links instead of matching the rest of the dashboard. */
.lb-widget { display: flex; flex-direction: column; gap: 14px; }

.lb-widget__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.lb-widget__title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(17px, 2.5vw, 20px);
    letter-spacing: 1px;
}
.lb-widget__viewall {
    font-size: 13px;
    font-weight: 600;
    color: var(--accent);
    text-decoration: none;
    white-space: nowrap;
}
.lb-widget__viewall:hover { text-decoration: underline; }

.lb-widget__myrank {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
.lb-widget__mylabel { font-size: 12px; color: var(--muted); flex: 1; }
.lb-widget__mybadge {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 15px;
    letter-spacing: 0.5px;
    color: var(--accent);
    background: rgba(0,229,160,0.1);
    border-radius: var(--radius-pill);
    padding: 2px 10px;
}
.lb-widget__mypoints { font-size: 13px; color: var(--text); font-weight: 600; }

.lb-widget__empty {
    text-align: center;
    padding: 28px 10px;
    color: var(--muted);
    font-size: 13px;
    background: var(--surface2);
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
}

.lb-widget__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.lb-widget__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    transition: background var(--t-fast) var(--ease);
}
.lb-widget__item:hover { background: var(--surface2); }
.lb-widget__item.is-me { background: rgba(0,229,160,0.08); border: 1px solid rgba(0,229,160,0.25); }
.lb-widget__item.gold   .lb-widget__pos { color: #ffd166; }
.lb-widget__item.silver .lb-widget__pos { color: #cbd5e1; }
.lb-widget__item.bronze .lb-widget__pos { color: #f4a261; }

.lb-widget__pos {
    width: 26px;
    flex-shrink: 0;
    text-align: center;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 14px;
    color: var(--muted);
}
.lb-widget__avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}
.lb-widget__avatar--initials {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--surface3);
    color: var(--text);
    font-size: 12px;
    font-weight: 700;
}
.lb-widget__name {
    flex: 1;
    min-width: 0;
    font-size: 13px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.lb-widget__pts { font-size: 13px; font-weight: 700; color: var(--accent); flex-shrink: 0; }

.lb-widget__cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 18px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    color: var(--bg);
    background: var(--grad-accent);
    box-shadow: var(--glow-accent);
    transition: transform var(--t-fast) var(--ease), box-shadow var(--t-fast) var(--ease);
}
.lb-widget__cta:hover { transform: translateY(-1px); box-shadow: var(--shadow); }
</style>

<!-- ── Notifications ── -->
<?php foreach ($notifications as $n):
    $nClass = match($n['type']) {
        'success' => 'flash-success', 'error', 'danger' => 'flash-error',
        'warn'    => 'flash-warn', default => 'flash-info'
    };
?>
<div class="flash <?= $nClass ?> notif-banner">
    <span><strong><?= clean($n['title']) ?></strong> — <?= clean($n['message']) ?></span>
    <button class="notif-close" onclick="
        this.parentElement.remove();
        fetch('<?= APP_URL ?>/api/notifications.php',{
            method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',
            body:JSON.stringify({action:'mark_read',id:<?= (int)$n['id'] ?>})
        });">✕</button>
</div>
<?php endforeach; ?>

<div class="dash-wrap">

<!-- ══ 1. PAGE HEADER ══════════════════════════════════════════ -->
<div class="dash-header">
    <div class="dash-header-left">
        <h1>My Dashboard</h1>
        <p>Welcome back, <strong class="dash-header-left-name"><?= clean($_SESSION['full_name']) ?></strong> 👋</p>
    </div>
    <div class="dash-header-actions">
        <a href="<?= APP_URL ?>/public/open_play.php" class="btn-outline btn-sm">🎲 Open Play</a>
        <a href="<?= APP_URL ?>/player/topup.php"  class="btn-primary btn-sm">+ Load Credits</a>
    </div>
</div>

<!-- ══ 2. WHAT'S ON TODAY ══════════════════════════════════════ -->
<div class="today-strip">
    <div class="today-strip-title">
        <span>📅 What's On Today — <?= date('l, M j') ?></span>
        <?php if (!$liveSession && empty($myTodayRes)): ?>
            <a href="<?= APP_URL ?>/player/schedule.php" class="btn-primary btn-sm">📅 Book Slot</a>
        <?php endif; ?>
    </div>
    <div class="today-chips">
        <?php if ($liveSession): ?>
            <span class="today-chip live">
                <span class="today-dot"></span>
                🔴 <?= clean($liveSession['court_name']) ?> — <?= (int)$liveSession['player_count'] ?>/<?= (int)$liveSession['max_queue'] ?> playing
            </span>
        <?php elseif ($activeReservationNow): ?>
            <span class="today-chip res">
                <span class="today-dot"></span>
                📅 Reserved until <?= date('g:i A', strtotime($activeReservationNow['slot_end'])) ?>
            </span>
        <?php else: ?>
            <span class="today-chip open">✅ Court available now</span>
        <?php endif; ?>

        <span class="today-chip <?= $modeChipClass ?>"><?= $modeChipLabel ?></span>

        <?php if ($isOpenPlayTonight): ?>
            <span class="today-chip open">🎮 Open Play 8 PM</span>
        <?php else: ?>
            <span class="today-chip res">📅 Reservations tonight</span>
        <?php endif; ?>

        <?php foreach ($myTodayRes as $tr): ?>
            <span class="today-chip <?= $tr['status'] === 'confirmed' ? 'open' : 'pending' ?>">
                <?= $tr['status'] === 'confirmed' ? '✅' : '⏳' ?>
                <?= date('g:i', strtotime($tr['slot_time'])) ?>–<?= date('g:i A', strtotime($tr['slot_end'])) ?>
            </span>
        <?php endforeach; ?>

        <?php if ($reservedSlotsToday > 0): ?>
            <span class="today-chip res">📅 <?= (int)$reservedSlotsToday ?> slot<?= $reservedSlotsToday != 1 ? 's' : '' ?> booked today</span>
        <?php endif; ?>
    </div>
</div>

<!-- ══ 3. STATS ROW ════════════════════════════════════════════ -->
<div class="dash-stats">
    <div class="dash-stat">
        <div class="dash-stat-val">₱<?= number_format($balance, 2) ?></div>
        <div class="dash-stat-label">Balance</div>
    </div>
    <div class="dash-stat">
        <div class="dash-stat-val"><?= $gamesPlayed ?></div>
        <div class="dash-stat-label">Games Played</div>
    </div>
    <div class="dash-stat">
        <div class="dash-stat-val">₱<?= number_format($creditsSpent, 0) ?></div>
        <div class="dash-stat-label">Spent</div>
    </div>
    <div class="dash-stat">
        <div class="dash-stat-val"><?= $queueCount ?>/<?= PLAYERS_PER_GAME ?></div>
        <div class="dash-stat-label">Queue</div>
    </div>
    <div class="dash-stat">
        <div class="dash-stat-val"><?= (int)$reservedSlotsToday ?></div>
        <div class="dash-stat-label">Slots Today</div>
    </div>
</div>

<!-- ══ 4. COURT STATUS ═════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-title">📍 Court Status Right Now</div>
    <div class="court-status-strip">
        <?php foreach ($allCourts as $court): ?>
            <a href="<?= APP_URL ?>/player/schedule.php?court=<?= $court['id'] ?>"
               class="court-status-pill"
               style="background:<?= $court['color'] ?>20;border:1px solid <?= $court['color'] ?>40;color:<?= $court['color'] ?>;">
                <span class="court-code"><?= clean($court['short_code']) ?></span>
                <span class="court-status">
                    <?php switch ($court['live_status']) {
                        case 'available':   echo '● Open'; break;
                        case 'active':      echo '🎮 Game'; break;
                        case 'queuing':     echo '⏳ Queuing'; break;
                        case 'maintenance': echo '🔧 Maint'; break;
                        case 'closed':      echo '✗ Closed'; break;
                        case 'reserved':    echo '📅 Reserved'; break;
                        default:            echo '○ Unknown'; break;
                    } ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ 4b. QUEUE POSITION ═════════════════════════════════════ -->
<?php if (!empty($queueByCourt)): ?>
<div class="card mb-3 queue-alert-card">
    <div class="card-title queue-alert-title">⏳ You are in queue</div>
    <?php foreach ($queueByCourt as $courtId => $qp): ?>
        <div class="queue-pos-box">
            <div class="queue-pos-title">
                Position: #<?= $qp['position'] ?> of <?= $qp['total_in_queue'] ?> for <?= clean($qp['name']) ?> (<?= clean($qp['short_code']) ?>)
            </div>
            <div class="queue-pos-meta">
                Expected wait: ~<?= ceil($qp['position'] * 15) ?> min (based on 15-min games)
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<!-- ══ COMPACT COURT SCHEDULE ═══════════════════════════════ -->
<div class="csc-card" id="player-csc">
    <div class="csc-head">
        <div class="csc-title-row">
            <span class="csc-live-dot"></span>
            <span class="csc-title">📅 Court Schedule — Today</span>
        </div>
        <div class="csc-head-right">
            <span class="csc-updated" id="player-csc-label">Loading…</span>
            <a href="<?= APP_URL ?>/player/schedule.php" class="btn-primary btn-sm">+ Book</a>
        </div>
    </div>
    <div class="csc-legend">
        <span class="csc-leg"><span class="csc-leg-dot" style="background:var(--accent)"></span>Open</span>
        <span class="csc-leg"><span class="csc-leg-dot" style="background:#f59e0b"></span>Filling</span>
        <span class="csc-leg"><span class="csc-leg-dot" style="background:#00b8ff"></span>Reserved</span>
        <span class="csc-leg"><span class="csc-leg-dot" style="background:#ef4444"></span>Full</span>
        <span class="csc-leg"><span class="csc-leg-dot" style="background:var(--muted);opacity:.5"></span>Past</span>
    </div>
    <div class="csc-strip-wrap">
        <div class="csc-strip" id="player-csc-strip">
            <?php for ($i = 0; $i < 8; $i++): ?><div class="csc-skeleton"></div><?php endfor; ?>
        </div>
    </div>
    <div class="csc-footer">
        <a href="<?= APP_URL ?>/player/schedule.php" class="btn-outline btn-sm">Full Schedule →</a>
    </div>
</div>
<!-- ══ 5. OPEN PLAY QUEUE + GAME STATUS ════════════════════════════════ -->
<div class="dash-main-grid">

    <!-- Open Play Queue -->
    <div class="card">
        <div class="card-title">🎲 Open Play Queue</div>
        <div class="card-subtitle">Your current place in the live queue</div>
        <hr class="divider"/>
        <?php if ($queuePos ?? false): ?>
            <div class="scanner-screen" style="border-color:var(--accent2);margin-bottom:12px;">
                <div class="scanner-icon">⏳</div>
                <div class="scanner-text">In Queue</div>
                <div style="font-size:52px;font-family:'Bebas Neue',sans-serif;color:var(--accent2);">#<?= (int)$queuePos['position'] ?></div>
                <div class="scanner-sub">Waiting for <?= max(0, PLAYERS_PER_GAME - $queueCount) ?> more player(s)</div>
            </div>
            <div class="qr-info">
                Queue status is managed from the Open Play board.<br>
                Watch your position and join the next available match from the queue screen.
            </div>
            <div class="qr-actions">
                <a href="<?= APP_URL ?>/public/open_play.php" class="btn-primary btn-sm">🎲 View Queue</a>
            </div>
        <?php else: ?>
            <div class="scanner-screen success" style="margin-bottom:12px;">
                <div class="scanner-icon">✅</div>
                <div class="scanner-text">Queue is clear</div>
                <div class="scanner-sub">You are not currently waiting for a game.</div>
            </div>
            <div class="qr-info">
                Join the live Open Play queue when you want to play.
            </div>
            <div class="qr-actions">
                <a href="<?= APP_URL ?>/public/open_play.php" class="btn-primary btn-sm">🎲 Join Open Play</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Game Status -->
    <div class="card">
        <div class="card-title game-status-title">🏓 Game Status</div>
        <div class="card-subtitle game-status-subtitle">Your current court activity</div>
        <hr class="divider"/>

        <?php if ($activeGame ?? false): ?>
            <div class="scanner-screen success">
                <div class="scanner-icon"><?= $activeGame['session_type'] === 'reservation' ? '📅' : '🎮' ?></div>
                <div class="scanner-text"><?= $activeGame['session_type'] === 'reservation' ? 'Reserved Session' : 'Game In Progress' ?></div>
                <div class="scanner-sub"><?= clean($activeGame['court_name']) ?></div>
                <div id="game-timer" class="game-timer">--:--</div>
                <div class="scanner-sub">Time remaining</div>
                <?php if ($activeGame['session_type'] === 'reservation'): ?>
                    <div class="res-session-badge">📅 Reservation Session</div>
                <?php endif; ?>
            </div>
            <script nonce="<?= getCspNonce() ?>">
            (function(){
                const endTs = <?= (int)($activeGameEndTs ?? 0) ?>;
                const el    = document.getElementById('game-timer');
                function tick(){
                    const secs = Math.max(0, endTs - Math.floor(Date.now()/1000));
                    const h = Math.floor(secs/3600), m = Math.floor((secs%3600)/60), s = secs%60;
                    el.textContent = h > 0
                        ? h+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')
                        : String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
                    if(secs<=300) el.style.color='var(--warn)';
                    if(secs<=60)  el.style.color='var(--danger)';
                    if(secs>0) setTimeout(tick,1000); else el.textContent='00:00';
                }
                tick();
            })();
            </script>

        <?php elseif ($queuePos ?? false): ?>
            <div class="scanner-screen" style="border-color:var(--accent2);">
                <div class="scanner-icon">⏳</div>
                <div class="scanner-text">In Queue</div>
                <div style="font-size:52px;font-family:'Bebas Neue',sans-serif;color:var(--accent2);">#<?= (int)$queuePos['position'] ?></div>
                <div class="scanner-sub">Waiting for <?= max(0, PLAYERS_PER_GAME - $queueCount) ?> more player(s)</div>
            </div>

        <?php elseif ($nextReservation): ?>
            <div class="scanner-screen" style="border-color:var(--warn);">
                <div class="scanner-icon">📅</div>
                <div class="scanner-text">Upcoming Reservation</div>
                <div class="scanner-sub"><?= clean($nextReservation['court_name']) ?></div>
                <div class="next-res-datetime">
                    <?= date('M d', strtotime($nextReservation['slot_date'])) ?> · <?= date('g:i A', strtotime($nextReservation['slot_time'])) ?>
                </div>
                <div class="next-res-until">until <?= date('g:i A', strtotime($nextReservation['slot_end'])) ?></div>
                <div class="next-res-actions">
                    <?php $isConfirmed = $nextReservation['status'] === 'confirmed'; ?>
                    <span class="badge badge-<?= $isConfirmed ? 'success' : 'warn' ?>"><?= $isConfirmed ? '✅ Confirmed' : '⏳ Pending Confirmation' ?></span>
                </div>
                <div class="next-res-actions">
                    <a href="<?= APP_URL ?>/player/schedule.php" class="btn-outline btn-sm">View Schedule →</a>
                </div>
            </div>

        <?php else: ?>
            <div class="scanner-screen">
                <div class="scanner-icon">📷</div>
                <div class="scanner-text">Not In Queue</div>
                <div class="scanner-sub mt-1">
                    <?= $isActive
                        ? 'Scan your QR at the court entrance to join the queue.'
                        : 'Load credits to activate your QR and start playing.' ?>
                </div>
            </div>
            <?php
            try { $liveQueue = $db->query("SELECT username FROM falcon.v_current_queue LIMIT 4")->fetchAll(); }
            catch (PDOException $e) { $liveQueue = []; }
            ?>
            <div class="queue-slots mt-2">
                <?php for ($i = 0; $i < PLAYERS_PER_GAME; $i++): ?>
                    <div class="queue-slot <?= isset($liveQueue[$i]) ? 'filled' : '' ?>">
                        <?php if (isset($liveQueue[$i])): ?>
                            👤<br/><?= clean($liveQueue[$i]['username']) ?>
                        <?php else: ?>
                            Slot <?= $i + 1 ?><br/>Empty
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ 6. ACTIVITY BOOKINGS ════════════════════════════════════ -->
<?php if (!empty($activityBookings)): ?>
<div class="card mb-3">
    <div class="act-booking-header">
        <div>
            <div class="card-title">🎯 My Activity Bookings</div>
            <div class="card-subtitle">Upcoming activity reservations</div>
        </div>
        <a href="<?= APP_URL ?>/player/schedule.php" class="btn-outline btn-sm">+ Book Activity</a>
    </div>
    <hr class="divider"/>
    <?php foreach ($activityBookings as $ab):
        $statusColor = match($ab['status']) { 'confirmed' => 'var(--success)', 'cancelled' => 'var(--danger)', default => 'var(--warn)' };
        $payColor    = match($ab['payment_status']) { 'paid' => 'var(--success)', 'pending_verification' => 'var(--warn)', default => 'var(--muted)' };
        $payLabel    = match($ab['payment_status']) { 'paid' => '✅ Paid', 'pending_verification' => '⏳ Verifying', default => '💳 Unpaid' };
    ?>
    <div class="act-booking-row">
        <div class="act-icon-sm"><?= clean($ab['act_icon'] ?: '🎯') ?></div>
        <div style="flex:1;min-width:0;">
            <div class="act-booking-name"><?= clean($ab['act_name']) ?></div>
            <div class="act-booking-meta">
                <?= date('D, M j', strtotime($ab['booking_date'])) ?> ·
                <?= date('g:i A', strtotime($ab['start_time'])) ?>–<?= date('g:i A', strtotime($ab['end_time'])) ?> ·
                <?= (int)$ab['party_size'] ?> person<?= $ab['party_size'] !== 1 ? 's' : '' ?>
            </div>
        </div>
        <div class="act-booking-right">
            <span class="badge" style="background:rgba(0,0,0,0.2);color:<?= $statusColor ?>;"><?= ucfirst($ab['status']) ?></span>
            <span style="font-size:11px;color:<?= $payColor ?>;"><?= $payLabel ?></span>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="act-booking-footer">
        <a href="<?= APP_URL ?>/player/activity_bookings.php" class="btn-outline btn-sm">View All →</a>
    </div>
</div>
<?php endif; ?>

<!-- ══ 7. RECENT GAMES ══════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="recent-games-header">
        <div>
            <div class="card-title">📋 Recent Games</div>
            <div class="card-subtitle">Your last 5 games</div>
        </div>
        <a href="<?= APP_URL ?>/player/history.php" class="btn-outline btn-sm">View All</a>
    </div>

    <?php if (empty($recentGames)): ?>
        <div style="text-align:center;padding:28px 20px;">
            <div style="font-size:40px;margin-bottom:8px;">🎯</div>
            <p style="color:var(--muted);font-size:14px;">
                <?= $isActive ? 'No games yet. Scan your QR at the court entrance to start!' : 'No games yet. Load credits and scan your QR to start playing.' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Date</th><th>Court</th><th class="col-hide-sm">Played With</th><th>Credits</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentGames as $g): ?>
                    <tr>
                        <td class="td-game-date"><?= $g['started_at'] ? date('M d, h:i A', strtotime($g['started_at'])) : '—' ?></td>
                        <td class="td-game-court"><?= clean($g['court_name']) ?></td>
                        <td class="col-hide-sm td-game-mates"><?= clean($g['teammates'] ?? '—') ?></td>
                        <td class="td-game-credits">-₱<?= number_format($g['credits_charged'], 2) ?></td>
                        <td>
                            <?php $bt = match($g['status']) { 'completed' => 'success', 'active' => 'info', 'cancelled' => 'danger', default => 'muted' }; ?>
                            <span class="badge badge-<?= $bt ?>"><?= ucfirst($g['status']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ── Low Balance / Welcome Banner ── -->
<?php if ($balance < $creditCost): ?>
<div class="alert-banner alert-banner-warn">
    <span class="alert-banner-emoji">⚠️</span>
    <div class="alert-banner-body">
        <div class="alert-banner-title alert-banner-title-warn">Low balance — can't play yet</div>
        <div class="alert-banner-sub">
            You need at least <strong>₱<?= number_format($creditCost, 0) ?></strong> to join a game.
            <a href="<?= APP_URL ?>/player/topup.php">Load credits now →</a>
        </div>
    </div>
    <a href="<?= APP_URL ?>/public/help.php#top-up" class="btn-outline btn-sm alert-banner-action">How to top up ❓</a>
</div>
<?php elseif ($gamesPlayed === 0): ?>
<div class="alert-banner alert-banner-info">
    <span class="alert-banner-emoji">👋</span>
    <div class="alert-banner-body">
        <div class="alert-banner-title alert-banner-title-info">First time? Welcome!</div>
        <div class="alert-banner-sub">Check the Help Center to learn how the court system works before your first game.</div>
    </div>
    <a href="<?= APP_URL ?>/public/help.php" class="btn-outline btn-sm alert-banner-action">View Guide ❓</a>
</div>
<?php endif; ?>

<!-- ══ 8. QUICK LINKS ══════════════════════════════════════════ -->
<div class="dash-quick-links">
    <a href="<?= APP_URL ?>/public/open_play.php">     <span>🎲</span>Open Play</a>
    <a href="<?= APP_URL ?>/player/topup.php">         <span>💳</span>Load Credits</a>
    <a href="<?= APP_URL ?>/public/leaderboard.php">   <span>🏆</span>Leaderboard</a>
    <a href="<?= APP_URL ?>/public/tournaments.php">   <span>🎯</span>Tournaments</a>
    <a href="<?= APP_URL ?>/player/topup_history.php"> <span>📋</span>Credit History</a>
    <a href="<?= APP_URL ?>/public/help.php">          <span>❓</span>Help Center</a>
</div>

<!-- ══ 9. LEADERBOARD WIDGET ═══════════════════════════════════ -->
<?php include __DIR__ . '/../leaderboard/leaderboard_widget.php'; ?>

</div><!-- /.dash-wrap -->
<script nonce="<?= getCspNonce() ?>">
(function () {
    const API     = '<?= APP_URL ?>/api/slots.php';
    const strip   = document.getElementById('player-csc-strip');
    const labelEl = document.getElementById('player-csc-label');
    const bookUrl = '<?= APP_URL ?>/player/schedule.php';
    let timer = null;

    const META = {
        now:       { dot: 'var(--accent2)', label: 'Now',     cls: 'csc-now'  },
        available: { dot: 'var(--accent)',  label: 'Open',    cls: ''         },
        partial:   { dot: '#f59e0b',        label: 'Filling', cls: ''         },
        busy:      { dot: '#f59e0b',        label: 'Almost',  cls: ''         },
        reserved:  { dot: '#00b8ff',        label: 'Booked',  cls: ''         },
        full:      { dot: '#ef4444',        label: 'Full',    cls: 'csc-full' },
        past:      { dot: 'var(--muted)',   label: 'Past',    cls: 'csc-past' },
    };

    function fmtTime(t) {
        const [h, m] = t.split(':').map(Number);
        return (h % 12 || 12) + ':' + String(m).padStart(2, '0') + (h >= 12 ? ' PM' : ' AM');
    }

    function render(slots) {
        if (!slots || !slots.length) {
            strip.innerHTML = '<span style="font-size:12px;color:var(--muted);padding:8px 0;">No slots today.</span>';
            return;
        }
        strip.innerHTML = slots.map(s => {
            const key = s.isCurrent ? 'now' : (s.status || 'available');
            const m   = META[key] || META.available;
            const isPast  = s.status === 'past';
            const isBook  = !isPast && !s.isCurrent && s.status !== 'full';
            const pips    = !isPast
                ? '<div class="csc-pips">' +
                    Array.from({length: s.max}, (_, i) =>
                        `<span class="csc-pip" style="background:${i < s.booked ? m.dot : 'var(--surface)'}"></span>`
                    ).join('') +
                  `</div><div class="csc-count">${s.booked}/${s.max}</div>`
                : '';
            const click = isBook ? `onclick="location.href='${bookUrl}'"` : '';
            return `<div class="csc-slot ${m.cls}" ${click}>
                ${s.isCurrent ? '<span class="csc-now-badge"></span>' : ''}
                <div class="csc-slot-time">${fmtTime(s.start)}</div>
                <div class="csc-slot-status"><span class="csc-sd" style="background:${m.dot}"></span>${m.label}</div>
                ${pips}
            </div>`;
        }).join('');
        /* Auto-scroll current slot into view */
        const nowEl = strip.querySelector('.csc-now');
        if (nowEl) nowEl.scrollIntoView({ inline: 'center', behavior: 'smooth', block: 'nearest' });
    }

    function fetchSlots() {
        fetch(API + '?_=' + Date.now())
            .then(r => r.json())
            .then(data => {
                render(data.slots || []);
                labelEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
            })
            .catch(() => { labelEl.textContent = 'Retrying…'; });
    }

    fetchSlots();
    timer = setInterval(fetchSlots, 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { clearInterval(timer); } else { fetchSlots(); timer = setInterval(fetchSlots, 30000); }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>