<?php
// ============================================================
//  FILE: admin/dashboard.php  — CSP + Responsive Fix
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db   = getDB();
$user = currentUser();

$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM falcon.users WHERE role = 'player')                                                        AS total_players,
        (SELECT COUNT(*) FROM falcon.users WHERE role = 'player' AND created_at >= NOW() - INTERVAL '7 days')           AS new_this_week,
        (SELECT COUNT(*) FROM falcon.courts)                                                                             AS total_courts,
        (SELECT COUNT(*) FROM falcon.courts WHERE is_active = TRUE)                                                      AS active_courts,
        (SELECT COUNT(*) FROM falcon.game_sessions WHERE status IN ('waiting','active'))                                 AS live_sessions,
        (SELECT COUNT(*) FROM falcon.game_sessions WHERE status = 'completed' AND ended_at >= CURRENT_DATE)             AS games_today,
        (SELECT COUNT(*) FROM falcon.topup_requests  WHERE status = 'pending')                                          AS pending_topups,
        (SELECT COUNT(*) FROM falcon.reservations    WHERE status = 'pending')                                          AS pending_reservations,
        (SELECT COALESCE(SUM(amount), 0) FROM falcon.transactions WHERE type = 'deduction' AND created_at >= CURRENT_DATE) AS revenue_today,
        (SELECT COALESCE(SUM(amount), 0) FROM falcon.transactions WHERE type = 'deduction' AND created_at >= date_trunc('month', CURRENT_DATE)) AS revenue_month
")->fetch();

$activeSessions = $db->query("
    SELECT gs.id, gs.status, gs.started_at, c.name AS court_name, c.max_queue AS max_players, COUNT(gp.id) AS player_count
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE gs.status IN ('waiting','active')
    GROUP BY gs.id, gs.status, gs.started_at, c.name, c.max_queue
    ORDER BY gs.started_at DESC
")->fetchAll();

$pendingTopups = $db->query("
    SELECT tr.id, tr.amount, tr.method, tr.created_at, u.username, u.full_name
    FROM falcon.topup_requests tr
    JOIN falcon.users u ON u.id = tr.user_id
    WHERE tr.status = 'pending'
    ORDER BY tr.created_at ASC LIMIT 5
")->fetchAll();

$pendingRes = $db->query("
    SELECT r.id, r.slot_date, r.slot_time, r.party_size, r.created_at,
           u.username, u.full_name, c.name AS court_name
    FROM falcon.reservations r
    JOIN falcon.users u ON u.id = r.user_id
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.status = 'pending'
    ORDER BY r.slot_date ASC, r.slot_time ASC LIMIT 5
")->fetchAll();

$recentGames = $db->query("
    SELECT gs.id, gs.started_at, gs.ended_at, c.name AS court_name,
           COUNT(gp.id) AS player_count, COALESCE(SUM(gp.credits_charged), 0) AS total_charged
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE gs.status = 'completed'
      AND gs.ended_at >= NOW() - INTERVAL '7 days'
    GROUP BY gs.id, gs.started_at, gs.ended_at, c.name
    ORDER BY gs.ended_at DESC LIMIT 8
")->fetchAll();

$courts = $db->query("
    SELECT id, name, is_active, max_queue, credit_cost, game_duration
    FROM falcon.courts ORDER BY name
")->fetchAll();

$reservedNow = $db->query("
    SELECT r.slot_time, r.slot_end, r.party_size, u.full_name, u.username, c.name AS court_name
    FROM falcon.reservations r
    JOIN falcon.users u ON u.id = r.user_id
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.slot_date = CURRENT_DATE AND r.status = 'confirmed'
      AND r.slot_time <= NOW()::time AND r.slot_end > NOW()::time
    ORDER BY r.slot_time ASC LIMIT 3
")->fetchAll();

$currentMode = 'reservation'; $currentModeFrom = null; $currentModeTo = null;
try {
    $nowTime = date('H:i:s');
    $curMode = $db->query("
        SELECT mode, time_from, time_to FROM falcon.court_slot_modes
        WHERE court_id = (SELECT id FROM falcon.courts WHERE is_active = TRUE ORDER BY id LIMIT 1)
          AND time_from <= '$nowTime' AND time_to > '$nowTime'
          AND ((slot_date = CURRENT_DATE) OR (slot_date IS NULL AND day_of_week = EXTRACT(DOW FROM CURRENT_DATE)::int))
        ORDER BY CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END ASC LIMIT 1
    ")->fetch();
    if ($curMode) { $currentMode = $curMode['mode']; $currentModeFrom = $curMode['time_from']; $currentModeTo = $curMode['time_to']; }
} catch (PDOException $e) {}

$allCourts = $db->query("
    SELECT * FROM falcon.v_court_status ORDER BY sort_order, id
")->fetchAll();

$liveQueueCount = (int)$db->query(
    "SELECT COUNT(*) FROM falcon.game_queue WHERE session_id IS NULL"
)->fetchColumn();

$todayCompletedGames = (int)$db->query(
    "SELECT COUNT(*) FROM falcon.game_sessions WHERE DATE(started_at) = CURRENT_DATE AND status = 'completed'"
)->fetchColumn();

$totalActiveGames = 0;
$totalPlayersOnCourts = 0;
foreach ($allCourts as $c) {
    if ($c['live_status'] === 'active') $totalActiveGames++;
    $totalPlayersOnCourts += (int)($c['players_on_court'] ?? 0);
}

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<?= breadcrumb([
    ['label' => 'Home', 'href' => APP_URL],
    ['label' => 'Admin Dashboard']
]) ?>
<?php
?>

<style nonce="<?= getCspNonce() ?>">
    /* ══ COMPACT SCHEDULE STRIP ═════════════════════════════════ */
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
.csc-title-row { display: flex; align-items: center; gap: 8px; }
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
.csc-updated { font-size: 11px; color: var(--muted); font-family: monospace; }
.csc-legend {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 8px;
}
.csc-leg { display: flex; align-items: center; gap: 4px; }
.csc-leg-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.csc-strip-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
    margin: 0 -2px;
}
.csc-strip-wrap::-webkit-scrollbar { height: 3px; }
.csc-strip-wrap::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
.csc-strip {
    display: flex;
    gap: 6px;
    min-width: max-content;
    padding: 2px 2px 4px;
}
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
/* ══════════════════════════════════════════════════════════════
   ADMIN DASHBOARD — FULLY RESPONSIVE + CSP-SAFE
══════════════════════════════════════════════════════════════ */

.page-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

/* ── Page Header ── */
.dash-page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.dash-page-header h1 { margin: 0 0 4px; }
.dash-page-header-sub {
    margin: 0;
    font-size: 14px;
    color: var(--muted);
}
.dash-page-header-sub strong { color: var(--text); }

/* ── Stats grids ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

/* ── Revenue row ── */
.revenue-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 20px;
}
.revenue-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    min-width: 0;
    overflow: hidden;
}
.revenue-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin-bottom: 4px;
}
.revenue-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(22px, 4vw, 36px);
    color: var(--success);
    line-height: 1;
    word-break: break-all;
}
.revenue-val-accent { color: var(--accent); }

/* ── Stat card value colors ── */
.stat-val-accent  { color: var(--accent); }
.stat-val-success { color: var(--success); }
.stat-val-accent2 { color: var(--accent2); }
.stat-val-accent3 { color: var(--accent3); }
.stat-val-warn    { color: var(--warn); }

.stat-courts-slash { font-size: 15px; color: var(--muted); }

/* ── Session grid ── */
.session-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 12px;
    margin-top: 12px;
}
.session-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    min-width: 0;
    transition: border-color .2s;
}
.session-card.active  { border-color: var(--accent); }
.session-card.waiting { border-color: var(--warn); }
.session-court {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 16px;
    letter-spacing: 1px;
    margin-bottom: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.session-meta  { font-size: 12px; color: var(--muted); margin-bottom: 8px; }
.session-time  { font-family: 'JetBrains Mono', monospace; font-size: 10px; }
.session-pips  { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 6px; }
.session-count { font-size: 12px; color: var(--muted); }
.player-pip {
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--accent); color: var(--bg);
    font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.player-pip.empty { background: var(--surface); border: 1px dashed var(--border); color: var(--muted); }

/* ── Court mode banner ── */
.mode-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 12px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.mode-banner-open {
    background: rgba(0,229,160,0.08);
    border: 1px solid rgba(0,229,160,0.25);
}
.mode-banner-res {
    background: rgba(0,184,255,0.08);
    border: 1px solid rgba(0,184,255,0.25);
}
.mode-banner-emoji { font-size: 22px; flex-shrink: 0; }
.mode-banner-text  { flex: 1; min-width: 0; }
.mode-banner-title { font-weight: 700; font-size: 14px; }
.mode-banner-title-open { color: var(--accent); }
.mode-banner-title-res  { color: var(--accent2); }
.mode-banner-sub { font-size: 11px; color: var(--muted); margin-top: 2px; }
.mode-banner-btn { font-size: 11px; flex-shrink: 0; }

/* ── Reserved now ── */
.reserved-now-box {
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 12px;
    background: rgba(0,184,255,0.07);
    border: 1px solid rgba(0,184,255,0.25);
}
.reserved-now-heading {
    font-weight: 700;
    font-size: 13px;
    color: var(--accent2);
    margin-bottom: 8px;
}
.reserved-now-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    flex-wrap: wrap;
}
.reserved-now-row:last-child { border-bottom: none; }
.reserved-now-emoji { font-size: 16px; flex-shrink: 0; }
.reserved-now-info  { flex: 1; min-width: 0; }
.reserved-now-name  {
    font-size: 13px; font-weight: 600;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.reserved-now-handle { color: var(--muted); font-weight: 400; font-size: 11px; }
.reserved-now-meta   { font-size: 11px; color: var(--muted); }
.reserved-now-badge  {
    font-size: 10px; font-weight: 700;
    padding: 3px 9px; border-radius: 20px;
    background: rgba(0,184,255,0.12);
    color: var(--accent2);
    white-space: nowrap; flex-shrink: 0;
}

/* ── Main layout ── */
.dash-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
    margin-bottom: 24px;
    min-width: 0;
    align-items: start;
}
.dash-left  { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.dash-right { display: flex; flex-direction: column; gap: 20px; min-width: 0; }

/* ── Court pill ── */
.court-pill {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 13px;
    border-radius: 10px;
    background: var(--surface2);
    border: 1px solid var(--border);
    margin-bottom: 8px;
    font-size: 13px;
    min-width: 0;
    gap: 8px;
}
.court-pill:last-child { margin-bottom: 0; }
.court-pill-name {
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
}
.court-pill-meta      { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.court-pill-price     { font-size: 11px; color: var(--muted); }

/* ── Quick actions ── */
.quick-actions-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 12px;
}
.quick-actions-grid a {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 12px 8px;
    border-radius: 10px;
    background: var(--surface2);
    border: 1px solid var(--border);
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    text-decoration: none;
    transition: border-color .2s, background .2s;
    text-align: center;
    min-width: 0;
}
.quick-actions-grid a span { font-size: 20px; }
.quick-actions-grid a:hover { border-color: var(--accent); background: rgba(0,229,160,0.06); }

/* ── Documentation links ── */
.doc-links { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.doc-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    text-decoration: none;
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
    transition: border-color .2s;
}
.doc-link:hover { border-color: var(--accent); }
.doc-link-accent2:hover { border-color: var(--accent2); }
.doc-link-icon  { font-size: 18px; flex-shrink: 0; }
.doc-link-label { font-size: 11px; color: var(--muted); font-weight: 400; margin-top: 1px; }
.doc-link-arrow { margin-left: auto; font-size: 11px; color: var(--muted); }

/* ── Table cells ── */
.td-player-name  { font-size: 11px; color: var(--muted); }
.td-amount       { font-weight: 700; color: var(--success); white-space: nowrap; }
.td-method       { text-transform: uppercase; }
.td-date         { font-size: 12px; color: var(--muted); white-space: nowrap; }
.td-court        { font-size: 13px; }
.td-slot-date    { white-space: nowrap; }
.td-slot-time    { color: var(--accent); font-family: 'JetBrains Mono', monospace; font-size: 11px; }
.td-revenue      { font-weight: 700; color: var(--success); white-space: nowrap; }

/* ── Empty state ── */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px 16px;
    color: var(--muted);
    font-size: 13px;
    text-align: center;
    gap: 8px;
}
.empty-icon { font-size: 28px; }

/* ══ LIVE COURT MONITOR ══════════════════════════════════════ */
.adm-lcm-wrap {
    background: linear-gradient(135deg, rgba(0,229,160,0.04), rgba(0,184,255,0.03));
    border: 1px solid rgba(0,229,160,0.18);
    border-radius: 16px;
    padding: 16px 20px;
    margin-bottom: 20px;
}
.adm-lcm-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    gap: 8px;
    flex-wrap: wrap;
}
.adm-lcm-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 15px;
    letter-spacing: 1.5px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.adm-lcm-head-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.adm-lcm-live-dot {
    width: 9px; height: 9px;
    border-radius: 50%; flex-shrink: 0;
}
.adm-lcm-live-dot.active { background: var(--accent); animation: admlcmPulse 2s infinite; }
.adm-lcm-live-dot.idle   { background: var(--muted); }
@keyframes admlcmPulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%     { opacity:.3; transform:scale(.8); }
}
.adm-lcm-updated { font-size: 11px; color: var(--muted); font-family: monospace; }

#adm-court-strip {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.adm-court-pill {
    flex-shrink: 0;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.adm-lcm-totals {
    margin-top: 16px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    text-align: center;
}
.adm-lcm-totals-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(20px, 4vw, 28px);
}
.adm-lcm-totals-val-accent  { color: var(--accent); }
.adm-lcm-totals-val-accent2 { color: var(--accent2); }
.adm-lcm-totals-val-warn    { color: #f59e0b; }
.adm-lcm-totals-lbl {
    font-size: 10px;
    color: var(--muted);
    text-transform: uppercase;
}

/* ══ COURT SCHEDULE ══════════════════════════════════════════ */
.adm-slots-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 20px;
}
.adm-slots-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.adm-slots-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 15px;
    letter-spacing: 1.5px;
    color: var(--text);
}
.adm-slots-subtitle { font-size: 11px; color: var(--muted); margin-top: 2px; }
.adm-slots-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.adm-slots-live-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--muted);
    font-family: monospace;
}
.adm-slots-legend {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 12px;
}
.adm-legend-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    margin-right: 4px;
    vertical-align: middle;
}
.adm-legend-available  { background: var(--accent); }
.adm-legend-filling    { background: #f59e0b; }
.adm-legend-reserved   { background: #00b8ff; }
.adm-legend-full       { background: #ef4444; }
.adm-legend-past       { background: var(--muted); opacity: .4; }

.adm-slots-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 10px;
    min-height: 80px;
}
.adm-slot-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 11px;
    transition: border-color .2s, transform .2s;
    cursor: default;
    position: relative;
    min-width: 0;
}
.adm-slot-card:hover { border-color: rgba(0,229,160,.35); transform: translateY(-1px); }
.adm-slot-card.asc-current { box-shadow: 0 0 0 2px var(--accent2); border-color: var(--accent2); }
.adm-slot-card.asc-past    { opacity: .35; pointer-events: none; }
.adm-slot-card.asc-bookable { cursor: pointer; }
.adm-slot-card.asc-bookable:hover::after {
    content: 'View →';
    position: absolute;
    bottom: 7px; right: 9px;
    font-size: 9px;
    font-weight: 700;
    color: var(--accent);
    letter-spacing: .04em;
}
.asc-now-badge {
    position: absolute;
    top: 6px; right: 7px;
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--accent2);
    animation: admlcmPulse 2s infinite;
}
.asc-time {
    font-family: 'Space Mono', monospace;
    font-size: clamp(10px, 1.6vw, 12px);
    color: var(--text);
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.asc-status {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    white-space: nowrap;
}
.asc-dot   { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.asc-pips  { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 6px; }
.asc-pip   { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; transition: background .3s; }
.asc-count { font-size: 9px; color: var(--muted); margin-top: 3px; }

.adm-slots-footer { text-align: center; margin-top: 14px; }

/* Skeleton */
.adm-skel {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 11px;
    opacity: .15;
    pointer-events: none;
}
.adm-skel-line { background: var(--surface); border-radius: 4px; }
.adm-skel-line-lg { height: 13px; width: 65%; margin-bottom: 8px; }
.adm-skel-line-sm { height: 10px; width: 45%; }

/* ── Live sessions card ── */
.live-sessions-header {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}

/* ── Pending tables ── */
.pending-header {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}

/* ══════════════════════════════════════════════════════════════
   RESPONSIVE BREAKPOINTS
══════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .dash-layout { grid-template-columns: 1fr 260px; }
}

@media (max-width: 900px) {
    .dash-layout { grid-template-columns: 1fr; }
    .dash-right {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .stats-grid { grid-template-columns: repeat(4, 1fr); }
}

@media (max-width: 700px) {
    .stats-grid     { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .revenue-row    { grid-template-columns: 1fr 1fr; gap: 10px; }
    .session-grid   { grid-template-columns: repeat(2, 1fr); }
    .adm-slots-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
}

@media (max-width: 560px) {
    .dash-right         { grid-template-columns: 1fr; }
    .revenue-row        { grid-template-columns: 1fr; gap: 8px; }
    .stats-grid         { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .page-header-actions .btn-outline,
    .page-header-actions .btn-primary { font-size: 12px; padding: 7px 10px; }
    .adm-lcm-totals     { gap: 8px; }
}

@media (max-width: 420px) {
    .stats-grid         { grid-template-columns: repeat(2, 1fr); gap: 6px; }
    .session-grid       { grid-template-columns: 1fr; }
    .revenue-row        { grid-template-columns: 1fr; }
    .adm-slots-grid     { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .adm-lcm-totals     { grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .adm-lcm-totals-val { font-size: 18px; }
    .quick-actions-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .quick-actions-grid a { font-size: 11px; padding: 10px 4px; }
    .quick-actions-grid a span { font-size: 17px; }
}

@media (max-width: 360px) {
    .adm-slots-grid     { grid-template-columns: repeat(2, 1fr); }
    .stats-grid         { grid-template-columns: 1fr 1fr; gap: 6px; }
    .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<!-- ── Page Header ── -->
<div class="dash-page-header page-header">
    <div>
        <h1>Admin Dashboard</h1>
        <p class="dash-page-header-sub">
            Welcome back, <strong><?= clean($user['full_name'] ?? $user['username']) ?></strong>
            — <?= date('l, F j, Y') ?>
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= APP_URL ?>/court/scanner.php"        class="btn-primary btn-sm">📷 Scanner</a>
        <a href="<?= APP_URL ?>/admin/active_game.php"    class="btn-outline btn-sm">🎮 Live</a>
        <a href="<?= APP_URL ?>/admin/topup_requests.php" class="btn-outline btn-sm">
            ⏳ Top-Ups
            <?php if ($stats['pending_topups'] > 0): ?>
                <span class="nav-badge"><?= $stats['pending_topups'] ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/admin/manage_staff.php" class="btn-outline btn-sm">🧑‍💼 Staff Team</a>
    </div>
</div>

<!-- ── Stats Row 1 ── -->
<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-val stat-val-accent"><?= number_format($stats['total_players']) ?></div>
        <div class="stat-label">Total Players</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-success"><?= number_format($stats['new_this_week']) ?></div>
        <div class="stat-label">New This Week</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-accent2">
            <?= number_format($stats['active_courts']) ?><span class="stat-courts-slash">/<?= $stats['total_courts'] ?></span>
        </div>
        <div class="stat-label">Active Courts</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-accent3"><?= number_format($stats['live_sessions']) ?></div>
        <div class="stat-label">Live Sessions</div>
    </div>
</div>

<!-- ── Stats Row 2 ── -->
<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['games_today']) ?></div>
        <div class="stat-label">Games Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-warn"><?= number_format($stats['pending_topups']) ?></div>
        <div class="stat-label">Pending Top-Ups</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-warn"><?= number_format($stats['pending_reservations']) ?></div>
        <div class="stat-label">Pending Res.</div>
    </div>
    <div class="stat-card">
        <div class="stat-val stat-val-success">₱<?= number_format($stats['revenue_today'], 0) ?></div>
        <div class="stat-label">Revenue Today</div>
    </div>
</div>

<!-- ── Revenue Highlight ── -->
<div class="revenue-row">
    <div class="revenue-card">
        <div class="revenue-label">Today's Revenue</div>
        <div class="revenue-val">₱<?= number_format($stats['revenue_today'], 2) ?></div>
    </div>
    <div class="revenue-card">
        <div class="revenue-label">This Month</div>
        <div class="revenue-val revenue-val-accent">₱<?= number_format($stats['revenue_month'], 2) ?></div>
    </div>
</div>

<!-- ══ LIVE COURTS ═════════════════════════════════════════════ -->
<div class="adm-lcm-wrap" id="adm-live-court-monitor">
    <div class="adm-lcm-head">
        <div class="adm-lcm-title">
            <span class="adm-lcm-live-dot active"></span>
            🏓 LIVE COURTS
        </div>
        <div class="adm-lcm-head-right">
            <div class="adm-lcm-updated" id="adm-lcm-updated-label">Updated just now</div>
            <a href="<?= APP_URL ?>/admin/active_game.php" class="btn-outline btn-sm">Full View →</a>
        </div>
    </div>

    <div id="adm-court-strip">
        <?php foreach ($allCourts as $court): ?>
            <div class="adm-court-pill" data-court-id="<?= $court['id'] ?>"
                 data-color="<?= clean($court['color']) ?>"
                 style="background:<?= $court['color'] ?>20;border:1px solid <?= $court['color'] ?>40;color:<?= $court['color'] ?>;">
                <span class="court-code"><?= clean($court['short_code']) ?>:</span>
                <span class="court-status">
                    <?php
                    switch ($court['live_status']) {
                        case 'available': echo '● Available'; break;
                        case 'active':
                            $rem = 0;
                            if (!empty($court['game_started_at']) && !empty($court['game_duration_mins'])) {
                                $end = strtotime($court['game_started_at']) + ($court['game_duration_mins'] * 60);
                                $rem = max(0, $end - time());
                            }
                            $m = floor($rem / 60);
                            echo '🎮 ' . str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($rem % 60, 2, '0', STR_PAD_LEFT);
                            break;
                        case 'queuing':     echo '⏳ ' . $court['queue_count'] . '/' . $court['max_queue']; break;
                        case 'maintenance': echo '🔧 Maint'; break;
                        case 'closed':      echo '✗ Closed'; break;
                        case 'reserved':    echo '📅 Reserved'; break;
                        default:            echo '○ Unknown'; break;
                    }
                    ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="adm-lcm-totals">
        <div>
            <div class="adm-lcm-totals-val adm-lcm-totals-val-accent"><?= $totalActiveGames ?></div>
            <div class="adm-lcm-totals-lbl">Active Games</div>
        </div>
        <div>
            <div class="adm-lcm-totals-val adm-lcm-totals-val-accent2"><?= $totalPlayersOnCourts ?></div>
            <div class="adm-lcm-totals-lbl">Players on Courts</div>
        </div>
        <div>
            <div class="adm-lcm-totals-val adm-lcm-totals-val-warn"><?= $liveQueueCount ?></div>
            <div class="adm-lcm-totals-lbl">Total Queue</div>
        </div>
    </div>
</div>
<!-- ══ COMPACT COURT SCHEDULE ═══════════════════════════════ -->
<div class="csc-card" id="admin-csc">
    <div class="csc-head">
        <div class="csc-title-row">
            <span class="csc-live-dot"></span>
            <span class="csc-title">📅 Court Schedule — Today</span>
        </div>
        <div class="csc-head-right">
            <span class="csc-updated" id="admin-csc-label">Loading…</span>
            <a href="<?= APP_URL ?>/admin/schedule.php" class="btn-outline btn-sm">Manage →</a>
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
        <div class="csc-strip" id="admin-csc-strip">
            <?php for ($i = 0; $i < 8; $i++): ?><div class="csc-skeleton"></div><?php endfor; ?>
        </div>
    </div>
    <div class="csc-footer">
        <a href="<?= APP_URL ?>/admin/schedule.php" class="btn-outline btn-sm">View &amp; Manage Full Schedule →</a>
    </div>
</div>

<!-- ── Live Sessions ── -->
<div class="card mb-3">
    <div class="live-sessions-header">
        <div class="card-title">🎮 Live &amp; Waiting Sessions</div>
        <a href="<?= APP_URL ?>/admin/active_game.php" class="btn-outline btn-sm">View All →</a>
    </div>
    <hr class="divider"/>

    <?php $modeIsOpen = $currentMode === 'open_play'; ?>
    <div class="mode-banner <?= $modeIsOpen ? 'mode-banner-open' : 'mode-banner-res' ?>">
        <span class="mode-banner-emoji"><?= $modeIsOpen ? '🎮' : '📅' ?></span>
        <div class="mode-banner-text">
            <div class="mode-banner-title <?= $modeIsOpen ? 'mode-banner-title-open' : 'mode-banner-title-res' ?>">
                Court is currently in <strong><?= $modeIsOpen ? 'Open Play' : 'Reservation Only' ?></strong> mode
            </div>
            <div class="mode-banner-sub">
                <?php if ($currentModeFrom && $currentModeTo): ?>
                    <?= date('g:i A', strtotime('2000-01-01 '.$currentModeFrom)) ?>
                    – <?= date('g:i A', strtotime('2000-01-01 '.$currentModeTo)) ?>
                    · <?= $modeIsOpen ? 'Walk-ins welcome' : 'Bookings only' ?>
                <?php else: ?>
                    Default mode · No specific schedule configured for this time
                <?php endif; ?>
            </div>
        </div>
        <a href="<?= APP_URL ?>/admin/court_settings.php" class="btn-outline btn-sm mode-banner-btn">Edit Modes</a>
    </div>

    <?php if (!empty($reservedNow)): ?>
    <div class="reserved-now-box">
        <div class="reserved-now-heading">📅 Court Reserved Right Now</div>
        <?php foreach ($reservedNow as $rn): ?>
        <div class="reserved-now-row">
            <span class="reserved-now-emoji">👤</span>
            <div class="reserved-now-info">
                <div class="reserved-now-name">
                    <?= clean($rn['full_name']) ?>
                    <span class="reserved-now-handle">@<?= clean($rn['username']) ?></span>
                </div>
                <div class="reserved-now-meta">
                    <?= clean($rn['court_name']) ?> ·
                    <?= date('g:i A', strtotime($rn['slot_time'])) ?>–<?= date('g:i A', strtotime($rn['slot_end'])) ?> ·
                    <?= $rn['party_size'] ?> player<?= $rn['party_size'] > 1 ? 's' : '' ?>
                </div>
            </div>
            <span class="reserved-now-badge">On Court</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($activeSessions)): ?>
        <div class="empty-state"><?= pickleballLogo(32) ?><span>No active or waiting sessions right now.</span></div>
    <?php else: ?>
        <div class="session-grid">
            <?php foreach ($activeSessions as $s):
                $isActive = $s['status'] === 'active';
                $filled   = (int)$s['player_count'];
                $max      = (int)$s['max_players'];
                $empty    = max(0, $max - $filled);
            ?>
            <div class="session-card <?= $isActive ? 'active' : 'waiting' ?>">
                <div class="session-court"><?= clean($s['court_name']) ?></div>
                <div class="session-meta">
                    <span class="badge badge-<?= $isActive ? 'success' : 'warn' ?>"><?= $isActive ? '🟢 Active' : '⏳ Waiting' ?></span>
                    &nbsp;<span class="session-time"><?= date('h:i A', strtotime($s['started_at'])) ?></span>
                </div>
                <div class="session-pips">
                    <?php for ($i = 0; $i < $filled; $i++): ?><div class="player-pip">P<?= $i+1 ?></div><?php endfor; ?>
                    <?php for ($i = 0; $i < $empty; $i++): ?><div class="player-pip empty">—</div><?php endfor; ?>
                </div>
                <div class="session-count"><?= $filled ?>/<?= $max ?> players</div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ── Main Layout ── -->
<div class="dash-layout">
    <div class="dash-left">

        <!-- Pending Top-Ups -->
        <div class="card">
            <div class="pending-header">
                <div class="card-title">⏳ Pending Top-Ups</div>
                <a href="<?= APP_URL ?>/admin/topup_requests.php" class="btn-outline btn-sm">View All →</a>
            </div>
            <hr class="divider"/>
            <?php if (empty($pendingTopups)): ?>
                <div class="empty-state"><span class="empty-icon">✅</span>No pending top-up requests.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Player</th><th>Amount</th><th class="col-hide-sm">Method</th><th class="col-hide-sm">Submitted</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingTopups as $tp): ?>
                            <tr>
                                <td><strong><?= clean($tp['username']) ?></strong><br><span class="td-player-name"><?= clean($tp['full_name']) ?></span></td>
                                <td class="td-amount">₱<?= number_format($tp['amount'], 2) ?></td>
                                <td class="col-hide-sm"><span class="badge badge-info td-method"><?= clean($tp['method']) ?></span></td>
                                <td class="col-hide-sm td-date"><?= date('M d, h:i A', strtotime($tp['created_at'])) ?></td>
                                <td><a href="<?= APP_URL ?>/admin/topup_requests.php" class="btn-warn btn-sm">Review</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pending Reservations -->
        <div class="card">
            <div class="pending-header">
                <div class="card-title">📅 Pending Reservations</div>
                <a href="<?= APP_URL ?>/admin/schedule.php" class="btn-outline btn-sm">Schedule →</a>
            </div>
            <hr class="divider"/>
            <?php if (empty($pendingRes)): ?>
                <div class="empty-state"><span class="empty-icon">✅</span>No pending reservations.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Player</th><th class="col-hide-sm">Court</th><th>Date &amp; Time</th><th class="col-hide-sm">Party</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($pendingRes as $r): ?>
                            <tr>
                                <td><strong><?= clean($r['username']) ?></strong><br><span class="td-player-name"><?= clean($r['full_name']) ?></span></td>
                                <td class="col-hide-sm td-court"><?= clean($r['court_name']) ?></td>
                                <td>
                                    <strong class="td-slot-date"><?= date('M d', strtotime($r['slot_date'])) ?></strong><br>
                                    <span class="td-slot-time"><?= date('h:i A', strtotime($r['slot_time'])) ?></span>
                                </td>
                                <td class="col-hide-sm" style="text-align:center;"><?= $r['party_size'] ?></td>
                                <td><a href="<?= APP_URL ?>/admin/schedule.php?date=<?= $r['slot_date'] ?>" class="btn-warn btn-sm">Review</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Games -->
        <div class="card">
            <div class="pending-header">
                <div class="card-title">📋 Recent Games</div>
                <a href="<?= APP_URL ?>/admin/game_history.php" class="btn-outline btn-sm">Full History →</a>
            </div>
            <hr class="divider"/>
            <?php if (empty($recentGames)): ?>
                <div class="empty-state"><?= pickleballLogo(32) ?><span>No completed games yet.</span></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Court</th><th class="col-hide-sm">Started</th><th>Players</th><th>Revenue</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentGames as $g): ?>
                            <tr>
                                <td><strong><?= clean($g['court_name']) ?></strong></td>
                                <td class="col-hide-sm td-date"><?= date('M d, h:i A', strtotime($g['started_at'])) ?></td>
                                <td style="text-align:center;"><?= $g['player_count'] ?></td>
                                <td class="td-revenue">₱<?= number_format($g['total_charged'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-right">

        <!-- Courts -->
        <div class="card">
            <div class="pending-header mb-1">
                <div class="card-title">🏟️ Courts</div>
                <a href="<?= APP_URL ?>/admin/court_settings.php" class="btn-outline btn-sm">Manage</a>
            </div>
            <hr class="divider"/>
            <?php if (empty($courts)): ?>
                <p class="td-date" style="padding:8px 0;">No courts configured.</p>
            <?php else: ?>
                <?php foreach ($courts as $c): ?>
                <div class="court-pill">
                    <div class="court-pill-name"><?= clean($c['name']) ?></div>
                    <div class="court-pill-meta">
                        <span class="court-pill-price">₱<?= number_format($c['credit_cost'], 0) ?></span>
                        <span class="badge <?= $c['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $c['is_active'] ? 'Active' : 'Off' ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-title mb-1">⚡ Quick Actions</div>
            <hr class="divider"/>
            <div class="quick-actions-grid">
                <a href="<?= APP_URL ?>/court/scanner.php">          <span>📷</span>Scanner</a>
                <a href="<?= APP_URL ?>/admin/active_game.php">      <span>🎮</span>Live Games</a>
                <a href="<?= APP_URL ?>/admin/players.php">          <span>👥</span>Players</a>
                <a href="<?= APP_URL ?>/admin/create_player.php">    <span>➕</span>Add Player</a>
                <a href="<?= APP_URL ?>/admin/topup_requests.php">   <span>💳</span>Top-Ups</a>
                <a href="<?= APP_URL ?>/admin/generate_topup_qr.php"><span>⚡</span>In-Person</a>
                <a href="<?= APP_URL ?>/admin/schedule.php">         <span>📅</span>Schedule</a>
                <a href="<?= APP_URL ?>/admin/reports.php">          <span>📈</span>Reports</a>
                <a href="<?= APP_URL ?>/admin/court_settings.php">   <span>🏟️</span>Courts</a>
                <a href="<?= APP_URL ?>/admin/scan_logs.php">        <span>📊</span>Scan Logs</a>
                <a href="<?= APP_URL ?>/admin/export_csv.php">       <span>⬇️</span>Export CSV</a>
                <a href="<?= APP_URL ?>/admin/content_manager.php">  <span>✏️</span>Content</a>
            </div>
        </div>

        <!-- Documentation -->
        <div class="card">
            <div class="card-title mb-1">📚 Documentation</div>
            <hr class="divider"/>
            <div class="doc-links">
                <a href="<?= APP_URL ?>/docs/owners_manual.php" class="doc-link">
                    <span class="doc-link-icon">📘</span>
                    <div>
                        <div>Owner's Manual</div>
                        <div class="doc-link-label">Complete guide to running your court</div>
                    </div>
                    <span class="doc-link-arrow">↗</span>
                </a>
                <a href="<?= APP_URL ?>/docs/maintenance_guide.php" class="doc-link">
                    <span class="doc-link-icon">🛠️</span>
                    <div>
                        <div>Maintenance Guide</div>
                        <div class="doc-link-label">Daily, weekly &amp; monthly tasks</div>
                    </div>
                    <span class="doc-link-arrow">↗</span>
                </a>
                <a href="<?= APP_URL ?>/public/help.php" target="_blank" class="doc-link doc-link-accent2">
                    <span class="doc-link-icon">❓</span>
                    <div>
                        <div>Player Help Center</div>
                        <div class="doc-link-label">What players see when they need help</div>
                    </div>
                    <span class="doc-link-arrow">↗</span>
                </a>
            </div>
        </div>

    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
(function () {
    const stripEl = document.getElementById('adm-court-strip');
    const updEl   = document.getElementById('adm-lcm-updated-label');

    function updateCourtPills(data) {
        if (!data.courts || !stripEl) return;
        data.courts.forEach(court => {
            const pill = stripEl.querySelector(`[data-court-id="${court.court_id}"]`);
            if (!pill) return;
            const statusSpan = pill.querySelector('.court-status');
            if (!statusSpan) return;
            let statusText = '';
            switch (court.live_status) {
                case 'available': statusText = '● Available'; break;
                case 'active':
                    if (court.active_game && court.active_game.remaining_secs !== null) {
                        const m = Math.floor(court.active_game.remaining_secs / 60);
                        const s = court.active_game.remaining_secs % 60;
                        statusText = '🎮 ' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
                    } else { statusText = '🎮 Active'; }
                    break;
                case 'queuing':     statusText = '⏳ ' + court.queue_count + '/' + court.max_queue; break;
                case 'maintenance': statusText = '🔧 Maint'; break;
                case 'closed':      statusText = '✗ Closed'; break;
                case 'reserved':    statusText = '📅 Reserved'; break;
                default:            statusText = '○ Unknown'; break;
            }
            statusSpan.textContent = statusText;
        });
        if (updEl) updEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    function pollCourts() {
        if (document.hidden || !stripEl) return;
        fetch('<?= APP_URL ?>/court/queue_status.php')
            .then(r => r.json()).then(updateCourtPills)
            .catch(() => { if (updEl) updEl.textContent = 'Retrying…'; });
    }

    pollCourts();
    setInterval(pollCourts, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) pollCourts(); });
})();

(function () {
    const API     = '<?= APP_URL ?>/api/slots.php';
    const strip   = document.getElementById('admin-csc-strip');
    const labelEl = document.getElementById('admin-csc-label');
    const schedUrl = '<?= APP_URL ?>/admin/schedule.php';
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
            const isPast = s.status === 'past';
            const pips   = !isPast
                ? '<div class="csc-pips">' +
                    Array.from({length: s.max}, (_, i) =>
                        `<span class="csc-pip" style="background:${i < s.booked ? m.dot : 'var(--surface)'}"></span>`
                    ).join('') +
                  `</div><div class="csc-count">${s.booked}/${s.max}</div>`
                : '';
            return `<div class="csc-slot ${m.cls}" onclick="location.href='${schedUrl}'">
                ${s.isCurrent ? '<span class="csc-now-badge"></span>' : ''}
                <div class="csc-slot-time">${fmtTime(s.start)}</div>
                <div class="csc-slot-status"><span class="csc-sd" style="background:${m.dot}"></span>${m.label}</div>
                ${pips}
            </div>`;
        }).join('');
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