<?php
// ============================================================
//  FILE: admin/active_game.php  — Priority 4 (multi-court)
//  Admin — Live game monitor, all 6 courts, fully automatic
//  + Manual player add + Pause / Resume / Reset per court
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$db = getDB();

// ── All courts with live status ───────────────────────────────
$courts = $db->query("
    SELECT id, name, short_code, color, court_type,
           is_active, is_maintenance,
           live_status, players_on_court, queue_count,
           max_queue, credit_cost, game_duration, warmup_mins,
           active_session_id, game_started_at, game_duration_mins
    FROM falcon.v_court_status
    ORDER BY sort_order, id
")->fetchAll();

// ── Active games (all courts) ─────────────────────────────────
$activeGames = $db->query("
    SELECT gs.id, gs.status, gs.started_at, gs.ended_at,
           gs.duration_mins, gs.court_id, gs.session_type,
           c.name AS court_name, c.credit_cost, c.short_code, c.color
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    WHERE gs.status = 'active'
    ORDER BY gs.started_at DESC
")->fetchAll();

// Index active games by court_id for quick lookup
$activeGamesByCourt = [];
foreach ($activeGames as $ag) {
    $activeGamesByCourt[(int)$ag['court_id']] = $ag;
}

// ── Active players per session ────────────────────────────────
$activePlayers = [];
if (!empty($activeGames)) {
    $sessionIds = array_column($activeGames, 'id');
    $in = implode(',', array_map('intval', $sessionIds));
    $rows = $db->query("
        SELECT gp.session_id, gp.user_id, gp.credits_charged,
               u.username, u.full_name,
               COALESCE(w.balance, 0) AS balance
        FROM falcon.game_players gp
        JOIN falcon.users u ON u.id = gp.user_id
        LEFT JOIN falcon.wallets w ON w.user_id = gp.user_id
        WHERE gp.session_id IN ({$in})
    ")->fetchAll();
    foreach ($rows as $row) {
        $activePlayers[(int)$row['session_id']][] = $row;
    }
}

// ── Queue per court ───────────────────────────────────────────
$allQueue = $db->query("
    SELECT gq.id, gq.user_id, gq.joined_at, gq.court_id,
           u.username, u.full_name,
           COALESCE(w.balance, 0) AS balance
    FROM falcon.game_queue gq
    JOIN falcon.users u ON u.id = gq.user_id
    LEFT JOIN falcon.wallets w ON w.user_id = gq.user_id
    WHERE gq.session_id IS NULL
    ORDER BY gq.joined_at ASC
")->fetchAll();

$queueByCourt = [];
foreach ($allQueue as $q) {
    $cid = (int)($q['court_id'] ?? 0);
    $queueByCourt[$cid][] = $q;
}

// ── Pause state per session ───────────────────────────────────
$pauseData = [];
try {
    $rows = $db->query("SELECT key, value FROM falcon.site_content WHERE section = 'game_pause'")->fetchAll();
    foreach ($rows as $row) $pauseData[$row['key']] = $row['value'];
} catch (PDOException $e) {}

// ── Warmup state per court ────────────────────────────────────
$warmupByCourt = [];
foreach ($courts as $c) {
    $cid       = (int)$c['id'];
    $cQueue    = $queueByCourt[$cid] ?? [];
    $hasActive = isset($activeGamesByCourt[$cid]);

    if (!$hasActive && count($cQueue) >= PLAYERS_PER_GAME) {
        $warmupTotalSecs = (int)($c['warmup_mins'] ?? 2) * 60;

        // READ ONLY — game_ticker.php owns warmup state.
        // If the timestamp isn't there yet, game_ticker will
        // write it on the next scan. We show nothing until then.
        try {
            $storedTsStmt = $db->prepare("
                SELECT value FROM falcon.site_content
                WHERE section = ? AND key = 'started_at'
            ");
            $storedTsStmt->execute(['warmup_court_' . $cid]);
            $storedTs = $storedTsStmt->fetchColumn();
        } catch (PDOException $e) {
            $storedTs = null;
        }

        if ($storedTs) {
            $elapsed = time() - (int)$storedTs;
            $warmupByCourt[$cid] = max(0, $warmupTotalSecs - $elapsed);
        }
        // If $storedTs is null, we leave $warmupByCourt[$cid] unset.
        // The court card will render as "has queue, no game" with
        // the Force Start button instead of a warmup countdown.
        // game_ticker will write the warmup_started_at on the next
        // scan and the next page load/poll will pick it up.
    }
    // No cleanup here — game_ticker.php handles warmup deletion
    // when a game starts or queue drops below PLAYERS_PER_GAME.
}

// ── Recent sessions (all courts) ─────────────────────────────
$recentGames = $db->query("
    SELECT gs.id, gs.status, gs.started_at, gs.ended_at,
           gs.duration_mins, c.name AS court_name, c.short_code, c.color,
           COUNT(gp.id) AS player_count,
           COALESCE(SUM(gp.credits_charged), 0) AS total_charged
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE gs.status IN ('completed','cancelled')
      AND gs.ended_at >= NOW() - INTERVAL '3 hours'
    GROUP BY gs.id, gs.started_at, gs.ended_at, gs.duration_mins,
             c.name, c.short_code, c.color
    ORDER BY gs.ended_at DESC
    LIMIT 12
")->fetchAll();

// ── Today stats per court ─────────────────────────────────────
$todayStats = $db->query("
    SELECT c.id AS court_id, c.name, c.short_code, c.color,
           COUNT(DISTINCT gs.id) AS games_today,
           COUNT(DISTINCT gp.user_id) AS players_today,
           COALESCE(SUM(gp.credits_charged), 0) AS credits_today
    FROM falcon.courts c
    LEFT JOIN falcon.game_sessions gs
        ON gs.court_id = c.id AND DATE(gs.started_at) = CURRENT_DATE
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    GROUP BY c.id, c.name, c.short_code, c.color
    ORDER BY c.sort_order, c.id
")->fetchAll();

$typeIcons = ['covered'=>'🏠','uncovered'=>'☀️','indoor'=>'🏢','outdoor'=>'🌿'];
$statusColors = [
    'available'   => ['color' => 'var(--accent)',  'icon' => '●',  'label' => 'Available'],
    'active'      => ['color' => '#00aaff',         'icon' => '🎮', 'label' => 'In Game'],
    'queuing'     => ['color' => 'var(--warn)',     'icon' => '⏳', 'label' => 'Queuing'],
    'maintenance' => ['color' => 'var(--muted)',    'icon' => '🔧', 'label' => 'Maintenance'],
    'closed'      => ['color' => 'var(--danger)',   'icon' => '✗',  'label' => 'Closed'],
    'reserved'    => ['color' => '#a855f7',         'icon' => '📅', 'label' => 'Reserved'],
];

$pageTitle = 'Game Monitor';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Page header ─────────────────────────────────────────────── */
.monitor-header-actions {
    display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
}
@media (max-width: 600px) {
    .monitor-header-actions { width: 100%; }
    .monitor-header-actions a,
    .monitor-header-actions button { flex: 1 1 auto; text-align: center; font-size: 12px; }
}

/* ── Courts grid ─────────────────────────────────────────────── */
.courts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
@media (max-width: 640px) { .courts-grid { grid-template-columns: 1fr; } }

/* ── Court card ──────────────────────────────────────────────── */
.court-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color .2s, box-shadow .2s;
}
.court-card:hover { box-shadow: 0 4px 24px rgba(0,0,0,0.3); }
.court-card.state-active      { border-color: #00aaff; }
.court-card.state-queuing     { border-color: var(--warn); }
.court-card.state-available   { border-color: rgba(0,229,160,0.2); }
.court-card.state-maintenance { border-color: var(--muted); opacity: .7; }
.court-card.state-closed      { border-color: var(--danger); opacity: .6; }

/* Color accent bar at top */
.court-card-bar {
    height: 4px;
    flex-shrink: 0;
}

/* Card header */
.court-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 10px;
    gap: 8px;
    flex-wrap: wrap;
}
.court-card-name {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 20px;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.court-short-badge {
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 20px;
    letter-spacing: .06em;
    flex-shrink: 0;
}
.court-type-icon { font-size: 14px; opacity: .7; }
.court-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    flex-shrink: 0;
}

/* Card body */
.court-card-body {
    padding: 0 16px 14px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Player pips */
.court-pips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.court-pip {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    flex-shrink: 0;
    transition: background .3s, border-color .3s;
}
.court-pip.filled { background: rgba(0,170,255,0.15); border: 2px solid #00aaff; color: #00aaff; }
.court-pip.empty  { background: var(--surface2); border: 2px dashed var(--border); color: var(--muted); }

/* Game timer */
.court-game-timer {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(36px, 6vw, 52px);
    line-height: 1;
    letter-spacing: 3px;
    text-align: center;
    padding: 10px 0 4px;
    transition: color .3s;
}
.timer-bar-wrap {
    height: 4px; background: var(--border); border-radius: 4px; overflow: hidden;
}
.timer-bar-fill {
    height: 100%; border-radius: 4px;
    transition: width 1s linear;
}

/* Warmup banner */
.court-warmup {
    text-align: center;
    padding: 10px 0;
    border: 1px solid rgba(245,158,11,0.3);
    border-radius: 10px;
    background: rgba(245,158,11,0.07);
}
.warmup-timer-sm {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(28px, 4vw, 40px);
    color: var(--warn);
    line-height: 1;
    letter-spacing: 2px;
}

/* Queue pills row */
.queue-mini {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    align-items: center;
}
.queue-mini-pip {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: rgba(245,158,11,0.12);
    border: 1.5px solid var(--warn);
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; color: var(--warn);
    flex-shrink: 0;
}
.queue-mini-pip.empty {
    background: var(--surface2);
    border-color: var(--border);
    color: var(--muted);
}

/* Action buttons */
.court-card-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: auto;
}
.court-card-actions button,
.court-card-actions a {
    flex: 1 1 auto;
    font-size: 12px;
    font-weight: 700;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    transition: border-color .15s, background .15s, color .15s;
    white-space: nowrap;
}
.court-card-actions .btn-end     { border-color: var(--success); color: var(--success); }
.court-card-actions .btn-end:hover { background: rgba(16,185,129,0.12); }
.court-card-actions .btn-cancel  { border-color: var(--danger); color: var(--danger); }
.court-card-actions .btn-cancel:hover { background: rgba(239,68,68,0.12); }
.court-card-actions .btn-start   { border-color: var(--accent); color: var(--accent); }
.court-card-actions .btn-start:hover { background: rgba(0,229,160,0.12); }
.court-card-actions .btn-pause   { border-color: var(--warn); color: var(--warn); }
.court-card-actions .btn-pause:hover { background: rgba(245,158,11,0.12); }
.court-card-actions .btn-resume  { border-color: #00aaff; color: #00aaff; }
.court-card-actions .btn-resume:hover { background: rgba(0,170,255,0.12); }
.court-card-actions .btn-maint   { border-color: var(--muted); color: var(--muted); }
.court-card-actions .btn-maint:hover { border-color: var(--text); color: var(--text); }

/* Pause badge */
.pause-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(245,158,11,0.15);
    border: 1px solid var(--warn);
    color: var(--warn);
    border-radius: 20px; padding: 3px 10px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    animation: pausePulse 1.5s infinite;
}
@keyframes pausePulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Today stats table ───────────────────────────────────────── */
.today-stats-card { margin-bottom: 24px; }
.today-stats-table { width: 100%; border-collapse: collapse; }
.today-stats-table th {
    text-align: left; font-size: 11px; text-transform: uppercase;
    letter-spacing: .08em; color: var(--muted); font-weight: 700;
    padding: 8px 12px; border-bottom: 1px solid var(--border);
}
.today-stats-table td {
    padding: 10px 12px; font-size: 13px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.today-stats-table tr:last-child td { border-bottom: none; }
.court-color-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; vertical-align: middle; }

/* ── Recent sessions ─────────────────────────────────────────── */
.recent-sessions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
}
.recent-sess-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 12px;
}
.recent-sess-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.recent-sess-court { font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 6px; }

/* ── Manual Add Modal ────────────────────────────────────────── */
.ma-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.82); z-index: 9999;
    align-items: center; justify-content: center;
    padding: 16px;
}
.ma-overlay.open { display: flex; }
.ma-box {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 16px; padding: 28px;
    width: 100%; max-width: 480px; max-height: 90dvh;
    overflow-y: auto;
}
.ma-title { font-family:'Bebas Neue',sans-serif; font-size:26px; margin-bottom:4px; color:var(--accent); }
.ma-sub { font-size:13px; color:var(--muted); margin-bottom:20px; line-height:1.5; }
.ma-court-select { margin-bottom: 14px; }
.ma-court-select label { font-size: 12px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: 6px; }
.ma-court-select select { width: 100%; padding: 10px 14px; background: var(--surface2); border: 1px solid var(--border); border-radius: 10px; color: var(--text); font-size: 14px; outline: none; }
.ma-court-select select:focus { border-color: var(--accent); }
.ma-search-wrap { position:relative; margin-bottom:10px; }
.ma-search-inp {
    width:100%; box-sizing:border-box; padding:11px 14px 11px 38px;
    background:var(--surface2); border:1px solid var(--border);
    border-radius:10px; color:var(--text); font-size:14px; outline:none;
}
.ma-search-inp:focus { border-color:var(--accent); }
.ma-search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:16px; pointer-events:none; }
.ma-results { display:flex; flex-direction:column; gap:7px; max-height:260px; overflow-y:auto; margin-bottom:16px; min-height:40px; }
.ma-player-row { display:flex; align-items:center; gap:12px; padding:11px 14px; background:var(--surface2); border:1px solid var(--border); border-radius:10px; cursor:pointer; transition:border-color .15s; }
.ma-player-row:hover,.ma-player-row.selected { border-color:var(--accent); background:rgba(0,229,160,0.06); }
.ma-player-row.disabled { opacity:.45; cursor:not-allowed; pointer-events:none; }
.ma-avatar { width:38px; height:38px; border-radius:50%; background:rgba(0,229,160,0.15); border:2px solid var(--accent); display:flex; align-items:center; justify-content:center; font-size:17px; font-weight:700; color:var(--accent); flex-shrink:0; text-transform:uppercase; }
.ma-player-info { flex:1; min-width:0; }
.ma-player-name { font-weight:700; font-size:14px; }
.ma-player-meta { font-size:11px; color:var(--muted); margin-top:2px; }
.ma-id-confirm { display:flex; align-items:flex-start; gap:10px; background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.35); border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:13px; color:var(--warn); line-height:1.5; }
.ma-id-confirm input[type="checkbox"] { margin-top:2px; accent-color:var(--warn); width:16px; height:16px; flex-shrink:0; cursor:pointer; }
.ma-footer { display:flex; gap:10px; }
.ma-footer button { flex:1; }
.ma-empty { text-align:center; padding:24px 0; color:var(--muted); font-size:13px; }

/* ── Flash messages ──────────────────────────────────────────── */
#flash-container { position:fixed; top:80px; right:24px; z-index:10000; display:flex; flex-direction:column; gap:8px; max-width:min(360px,calc(100vw - 48px)); pointer-events:none; }

/* ── Auto-refresh indicator ──────────────────────────────────── */
.auto-refresh-pill {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; color: var(--muted);
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 20px; padding: 4px 12px;
}
.ar-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); animation: arPulse 2s infinite; }
@keyframes arPulse { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Maintenance overlay on card ─────────────────────────────── */
.maint-overlay {
    text-align: center; padding: 16px 0;
    color: var(--muted); font-size: 13px; line-height: 1.6;
}
.maint-overlay .maint-icon { font-size: 36px; margin-bottom: 8px; }

/* ── Empty queue hint ────────────────────────────────────────── */
.no-queue-hint { font-size: 12px; color: var(--muted); text-align: center; padding: 8px 0; }
</style>

<!-- PAGE HEADER -->
<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
        <h1>Game Monitor</h1>
        <p style="margin:0;font-size:14px;color:var(--muted);">Auto-cycle enabled — all courts tracked live</p>
    </div>
    <div class="monitor-header-actions">
        <button type="button" id="open-manual-add-btn"
                style="background:rgba(0,229,160,0.12);border:1px solid var(--accent);color:var(--accent);border-radius:8px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
            👤+ Manual Add
        </button>
        <a href="<?= APP_URL ?>/staff/open_play_kiosk.php" target="_blank" rel="noopener" class="btn-primary btn-sm">📺 Open Play TV Kiosk</a>
        <a href="<?= APP_URL ?>/staff/open_play_control.php" class="btn-outline btn-sm">🎲 Open Play Queue</a>
        <a href="<?= APP_URL ?>/admin/court_settings.php"class="btn-outline btn-sm">⚙️ Settings</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php"     class="btn-outline btn-sm">← Dashboard</a>
        <div class="auto-refresh-pill" id="ar-pill">
            <span class="ar-dot"></span>
            <span id="ar-label">Live — refreshes every 15s</span>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     COURTS GRID
══════════════════════════════════════════════════════════ -->
<div class="courts-grid" id="courts-grid">
<?php foreach ($courts as $court):
    $cid      = (int)$court['id'];
    $st       = $court['live_status'] ?? 'available';
    $sc       = $statusColors[$st] ?? $statusColors['available'];
    $color    = $court['color'] ?: '#00e5a0';
    $typeIcon = $typeIcons[$court['court_type']] ?? '🏟️';
    $ag       = $activeGamesByCourt[$cid] ?? null;
    $players  = $activePlayers[$ag['id'] ?? 0] ?? [];
    $queue    = $queueByCourt[$cid] ?? [];
    $maxQ     = (int)$court['max_queue'];

    // Pause state
    $isPaused  = false; $pausedRem = 0;
    if ($ag && !empty($pauseData['session_id']) && (int)$pauseData['session_id'] === (int)$ag['id'] && isset($pauseData['paused_rem'])) {
        $isPaused = true; $pausedRem = (int)$pauseData['paused_rem'];
    }

    // Timer calc
    $remSecs = 0; $pct = 0; $endTs = 0;
    if ($ag) {
        $startTs = strtotime($ag['started_at']);
        $endTs   = $startTs + ($ag['duration_mins'] * 60);
        $remSecs = $isPaused ? $pausedRem : max(0, $endTs - time());
        $pct     = min(100, (time() - $startTs) / ($ag['duration_mins'] * 60) * 100);
    }

    // Warmup
    $warmupRem = $warmupByCourt[$cid] ?? null;
?>
<div class="court-card state-<?= $st ?>" id="court-card-<?= $cid ?>">
    <!-- Color bar -->
    <div class="court-card-bar" style="background:<?= htmlspecialchars($color) ?>;"></div>

    <!-- Header -->
    <div class="court-card-head">
        <div class="court-card-name">
            <span class="court-type-icon"><?= $typeIcon ?></span>
            <?= clean($court['name']) ?>
            <span class="court-short-badge" style="background:<?= htmlspecialchars($color) ?>22;color:<?= htmlspecialchars($color) ?>;border:1px solid <?= htmlspecialchars($color) ?>44;">
                <?= clean($court['short_code']) ?>
            </span>
        </div>
        <div class="court-status-pill" style="background:<?= $sc['color'] ?>18;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['color'] ?>44;">
            <?= $sc['icon'] ?> <?= $sc['label'] ?>
            <?php if ($isPaused): ?> <span class="pause-badge" style="font-size:9px;">⏸ PAUSED</span><?php endif; ?>
        </div>
    </div>

    <!-- Body -->
    <div class="court-card-body">

    <?php if ($court['is_maintenance']): ?>
        <!-- MAINTENANCE -->
        <div class="maint-overlay">
            <div class="maint-icon">🔧</div>
            <div style="font-weight:700;color:var(--muted);margin-bottom:4px;">Under Maintenance</div>
            <div style="font-size:11px;">Court is temporarily unavailable</div>
        </div>
        <div class="court-card-actions">
            <button class="btn-maint" onclick="clearMaintenance(<?= $cid ?>)">✅ Clear Maintenance</button>
        </div>

    <?php elseif ($ag): ?>
        <!-- ACTIVE GAME -->
        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">
            <?= $isPaused ? '⏸ PAUSED — Time Remaining' : 'Time Remaining' ?>
        </div>
        <div class="court-game-timer"
             id="timer-<?= $cid ?>"
             data-end="<?= (int)$endTs ?>"
             data-paused="<?= $isPaused ? '1' : '0' ?>"
             data-rem="<?= (int)$remSecs ?>"
             data-session-id="<?= (int)$ag['id'] ?>"
             style="color:<?= $isPaused ? 'var(--warn)' : ($remSecs<=60 ? 'var(--danger)' : ($remSecs<=300 ? 'var(--warn)' : htmlspecialchars($color))) ?>;">
            <?= sprintf('%02d:%02d', intdiv($remSecs, 60), $remSecs % 60) ?>
        </div>
        <div class="timer-bar-wrap">
            <div class="timer-bar-fill" id="bar-<?= $cid ?>"
                 style="width:<?= round($pct,1) ?>%;background:<?= htmlspecialchars($color) ?>;"></div>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Session #<?= $ag['id'] ?> · <?= $ag['duration_mins'] ?> min</div>

        <!-- Player pips -->
        <div class="court-pips">
            <?php $fc = count($players); for ($i=0;$i<$maxQ;$i++): $p=$players[$i]??null; ?>
            <div class="court-pip <?= $p ? 'filled' : 'empty' ?>" title="<?= $p ? clean($p['full_name']) : 'Empty' ?>">
                <?= $p ? '👤' : '—' ?>
            </div>
            <?php endfor; ?>
            <span style="font-size:11px;color:var(--muted);margin-left:4px;"><?= count($players) ?>/<?= $maxQ ?></span>
        </div>

        <?php if (!empty($queue)): ?>
        <div style="font-size:11px;color:var(--muted);">⏳ <?= count($queue) ?> in queue next</div>
        <?php endif; ?>

        <!-- Game controls -->
        <div class="court-card-actions">
            <?php if ($isPaused): ?>
                <button class="btn-resume" onclick="gameControl(<?= $cid ?>, <?= $ag['id'] ?>, 'resume', 0)">▶ Resume</button>
            <?php else: ?>
                <button class="btn-pause"  onclick="gameControl(<?= $cid ?>, <?= $ag['id'] ?>, 'pause', <?= $remSecs ?>)">⏸ Pause</button>
            <?php endif; ?>
            <button class="btn-maint" onclick="gameControl(<?= $cid ?>, <?= $ag['id'] ?>, 'reset', 0)" title="Reset timer">🔄</button>
            <button class="btn-end"    onclick="endGame(<?= $ag['id'] ?>, 'complete')">🏁 End</button>
            <button class="btn-cancel" onclick="endGame(<?= $ag['id'] ?>, 'cancel')">❌ Refund</button>
        </div>

    <?php elseif ($warmupRem !== null): ?>
        <!-- WARMUP COUNTDOWN -->
        <div class="court-warmup">
            <div style="font-size:11px;color:var(--warn);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">🏓 Warmup — Game Starting</div>
            <div class="warmup-timer-sm"
                 id="warmup-<?= $cid ?>"
                 data-rem="<?= (int)$warmupRem ?>">
                <?= sprintf('%02d:%02d', intdiv((int)$warmupRem, 60), (int)$warmupRem % 60) ?>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px;">Auto-launches when countdown ends</div>
        </div>
        <!-- Queue preview -->
        <div class="queue-mini">
            <?php for ($i=0;$i<$maxQ;$i++): $p=$queue[$i]??null; ?>
            <div class="queue-mini-pip <?= $p ? '' : 'empty' ?>" title="<?= $p ? clean($p['full_name']) : 'Waiting' ?>">
                <?= $i+1 ?>
            </div>
            <?php endfor; ?>
        </div>
        <div class="court-card-actions">
            <button class="btn-start" onclick="skipWarmup(<?= $cid ?>)">⚡ Skip — Start Now</button>
        </div>

    <?php elseif (!empty($queue)): ?>
        <!-- HAS QUEUE, NO GAME -->
        <div class="queue-mini">
            <?php for ($i=0;$i<$maxQ;$i++): $p=$queue[$i]??null; ?>
            <div class="queue-mini-pip <?= $p ? '' : 'empty' ?>" title="<?= $p ? clean($p['full_name']) : 'Waiting' ?>">
                <?= $i+1 ?>
            </div>
            <?php endfor; ?>
        </div>
        <div style="font-size:12px;color:var(--muted);">
            <?= count($queue) ?>/<?= $maxQ ?> players queued
            <?= count($queue) >= PLAYERS_PER_GAME ? ' · ✅ Ready!' : ' · filling…' ?>
        </div>
        <?php if (count($queue) >= PLAYERS_PER_GAME): ?>
        <div class="court-card-actions">
            <button class="btn-start" onclick="forceStartGame(<?= $cid ?>)">🚀 Force Start</button>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- IDLE -->
        <div class="no-queue-hint">
            <div style="font-size:32px;margin-bottom:8px;">🏓</div>
            <div>Court is idle — no players queued</div>
            <?= $court['is_active'] ? '<div style="margin-top:4px;">Players can scan QR to enter</div>' : '<div style="color:var(--danger);margin-top:4px;">Court is inactive</div>' ?>
        </div>
        <div class="court-card-actions">
            <button class="btn-start" onclick="forceStartGame(<?= $cid ?>)">🎮 Manual Start</button>
            <?php if ($court['is_active']): ?>
                <button class="btn-maint" onclick="setMaintenance(<?= $cid ?>)">🔧 Maintenance</button>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    </div><!-- /.court-card-body -->
</div><!-- /.court-card -->
<?php endforeach; ?>
</div><!-- /.courts-grid -->

<!-- ══════════════════════════════════════════════════════════
     TODAY STATS TABLE
══════════════════════════════════════════════════════════ -->
<div class="card today-stats-card">
    <div class="card-title mb-1">📊 Today's Performance</div>
    <hr class="divider"/>
    <div class="table-wrap">
        <table class="today-stats-table">
            <thead>
                <tr>
                    <th>Court</th>
                    <th style="text-align:center;">Games</th>
                    <th style="text-align:center;">Players</th>
                    <th style="text-align:right;">Credits Earned</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalGames = 0; $totalPlayers = 0; $totalCredits = 0;
                foreach ($todayStats as $ts):
                    $totalGames   += (int)$ts['games_today'];
                    $totalPlayers += (int)$ts['players_today'];
                    $totalCredits += (float)$ts['credits_today'];
                ?>
                <tr>
                    <td>
                        <span class="court-color-dot" style="background:<?= htmlspecialchars($ts['color']) ?>;"></span>
                        <strong><?= clean($ts['name']) ?></strong>
                        <span style="font-size:11px;color:var(--muted);margin-left:4px;"><?= clean($ts['short_code']) ?></span>
                    </td>
                    <td style="text-align:center;"><?= number_format($ts['games_today']) ?></td>
                    <td style="text-align:center;"><?= number_format($ts['players_today']) ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--success);">
                        <?= number_format($ts['credits_today'], 0) ?> cr
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid var(--border);">
                    <td><strong>Total</strong></td>
                    <td style="text-align:center;font-weight:700;"><?= number_format($totalGames) ?></td>
                    <td style="text-align:center;font-weight:700;"><?= number_format($totalPlayers) ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--success);"><?= number_format($totalCredits, 0) ?> cr</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     RECENT SESSIONS
══════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:24px;">
    <div class="flex-between mb-1" style="flex-wrap:wrap;gap:8px;">
        <div class="card-title">📋 Recent Sessions</div>
        <a href="<?= APP_URL ?>/admin/game_history.php" class="btn-outline btn-sm">Full History →</a>
    </div>
    <hr class="divider"/>
    <?php if (empty($recentGames)): ?>
        <p style="color:var(--muted);font-size:13px;padding:8px 0;">No sessions in the last 3 hours.</p>
    <?php else: ?>
    <div class="recent-sessions-grid">
        <?php foreach ($recentGames as $g): ?>
        <div class="recent-sess-card">
            <div class="recent-sess-head">
                <div class="recent-sess-court">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($g['color']) ?>;display:inline-block;flex-shrink:0;"></span>
                    <?= clean($g['court_name']) ?>
                    <span style="font-size:10px;color:var(--muted);"><?= clean($g['short_code']) ?></span>
                </div>
                <span class="badge badge-<?= $g['status']==='completed'?'success':'danger' ?>" style="font-size:10px;">
                    <?= ucfirst($g['status']) ?>
                </span>
            </div>
            <div style="color:var(--muted);">
                <?= $g['ended_at'] ? date('h:i A', strtotime($g['ended_at'])) : '—' ?> ·
                <?= $g['duration_mins'] ?>min ·
                <?= $g['player_count'] ?> players
            </div>
            <div style="font-weight:700;color:var(--success);margin-top:3px;">
                <?= number_format($g['total_charged'], 0) ?> credits
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Manual Add Modal -->
<div id="manual-add-overlay" class="ma-overlay" role="dialog" aria-modal="true">
    <div class="ma-box">
        <div class="ma-title">👤+ Add Player Without QR</div>
        <div class="ma-sub">For players who forgot their phone. Search, confirm ID in person, then add to the selected court's queue.</div>
        <div class="ma-court-select">
            <label>Target Court</label>
            <select id="ma-court-sel">
                <?php foreach ($courts as $c): ?>
                    <?php if ($c['is_active'] && !$c['is_maintenance']): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= clean($c['name']) ?> (<?= clean($c['short_code']) ?>)</option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ma-search-wrap">
            <span class="ma-search-icon">🔍</span>
            <input type="text" id="ma-search-inp" class="ma-search-inp" placeholder="Type name or username…" autocomplete="off" spellcheck="false"/>
        </div>
        <div id="ma-results" class="ma-results">
            <div class="ma-empty">Start typing to search for a player.</div>
        </div>
        <div class="ma-id-confirm" id="ma-id-section" style="display:none;">
            <input type="checkbox" id="ma-id-checkbox"/>
            <label for="ma-id-checkbox" style="cursor:pointer;">
                <strong>I have physically verified this player's valid ID.</strong><br/>
                By checking this I confirm the player is present and their identity confirmed in person.
            </label>
        </div>
        <div class="ma-footer">
            <button type="button" id="ma-confirm-btn" class="btn-primary" disabled>✅ Add to Queue</button>
            <button type="button" id="ma-cancel-btn"  class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<div id="flash-container"></div>

<script src="<?= APP_URL ?>/assets/js/game_alarm.js"></script>
<script nonce="<?= getCspNonce() ?>">
const APP_URL        = '<?= APP_URL ?>';
const COURT_ID_DEF   = <?= (int)(($courts[0]['id']) ?? 1) ?>;
const GAME_DURATION  = <?= (int)(($courts[0]['game_duration']) ?? 15) ?>;
const PLAYERS_NEEDED = <?= PLAYERS_PER_GAME ?>;

// Per-court timer intervals
const courtTimers   = {};
const warmupTimers  = {};
const alerted       = {};
let   reloadQueued  = false;
let   pollTimer     = null;

// ── UTILITIES ─────────────────────────────────────────────────
function fmt(s) {
    s = Math.max(0, Math.floor(s));
    return String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0');
}
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showFlash(msg, type) {
    const c = {
        success: ['rgba(16,185,129,0.15)','var(--success)','#6ee7b7'],
        error:   ['rgba(239,68,68,0.15)', 'var(--danger)', '#fca5a5'],
        warn:    ['rgba(245,158,11,0.15)','var(--warn)',   '#fcd34d'],
    }[type] || ['rgba(100,116,139,0.15)','var(--muted)','var(--muted)'];
    const div = document.createElement('div');
    div.style.cssText = `background:${c[0]};border:1px solid ${c[1]};color:${c[2]};padding:14px 18px;border-radius:12px;font-size:14px;font-weight:600;pointer-events:all;box-shadow:0 4px 20px rgba(0,0,0,0.4);`;
    div.textContent = msg;
    document.getElementById('flash-container').appendChild(div);
    setTimeout(() => div.remove(), 6000);
}
function safeReload() {
    if (reloadQueued) return;
    reloadQueued = true;
    sessionStorage.setItem('agm_scrollY', String(window.scrollY));
    clearInterval(pollTimer);
    setTimeout(() => location.reload(), 400);
}
(function restoreScroll() {
    const y = sessionStorage.getItem('agm_scrollY');
    if (y !== null) { sessionStorage.removeItem('agm_scrollY'); requestAnimationFrame(() => window.scrollTo({top:parseInt(y),behavior:'instant'})); }
}());

// ── TIMERS — init from DOM ─────────────────────────────────────
document.querySelectorAll('[id^="timer-"]').forEach(el => {
    const cid     = parseInt(el.id.replace('timer-',''), 10);
    const paused  = el.dataset.paused === '1';
    const endTs   = parseInt(el.dataset.end, 10);
    const remInit = parseInt(el.dataset.rem, 10);
    if (!paused && endTs) startCourtTimer(cid, endTs, el);
});

document.querySelectorAll('[id^="warmup-"]').forEach(el => {
    const cid    = parseInt(el.id.replace('warmup-',''), 10);
    const remInit= parseInt(el.dataset.rem, 10);
    if (remInit > 0) startWarmupTimer(cid, remInit);
});

function startCourtTimer(cid, endTs, el) {
    clearInterval(courtTimers[cid]);
    if (!alerted[cid]) alerted[cid] = {};
    const al = alerted[cid];
    courtTimers[cid] = setInterval(() => {
        const secs = Math.max(0, endTs - Math.floor(Date.now()/1000));
        if (el) {
            el.textContent = fmt(secs);
            el.style.color = secs<=60 ? 'var(--danger)' : secs<=300 ? 'var(--warn)' : '#00aaff';
        }
        const bar = document.getElementById('bar-'+cid);
        if (bar && endTs) {
            // approximate pct
        }
        if (secs===300 && !al.five) { al.five=true; showFlash(`⏳ ${document.getElementById('court-card-'+cid)?.querySelector('.court-card-name')?.textContent?.trim()}: 5 min left`, 'warn'); }
        if (secs===60  && !al.one)  { al.one=true;  try{GameAlarm.almostEnd();}catch(e){} showFlash(`🏓 Court ${cid}: 1 minute left!`, 'warn'); }
        if (secs<=0) {
            clearInterval(courtTimers[cid]);
            if (!al.over) { al.over=true; try{GameAlarm.end();}catch(e){} showFlash(`🏆 Game over on Court ${cid}!`, 'warn'); setTimeout(()=>autoEndGame(cid), 2500); }
        }
    }, 1000);
}

function startWarmupTimer(cid, remInit) {
    clearInterval(warmupTimers[cid]);
    let secs = remInit;
    warmupTimers[cid] = setInterval(() => {
        secs--;
        const el = document.getElementById('warmup-'+cid);
        if (el) { el.textContent = fmt(secs); el.style.color = secs<=30?'var(--danger)':'var(--warn)'; }
        if (secs<=0) { clearInterval(warmupTimers[cid]); doAutoLaunch(cid); }
    }, 1000);
}

// ── GAME LIFECYCLE ─────────────────────────────────────────────
const launchLocks = {}, endLocks = {};

function doAutoLaunch(courtId) {
    if (launchLocks[courtId]) return;
    launchLocks[courtId] = true;
    showFlash('🚀 Starting game on Court '+courtId+'…', 'warn');
    fetch(APP_URL+'/court/game_engine.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({court_id: courtId}),
    })
    .then(r=>r.json())
    .then(d => {
        if (d.status==='started') { try{GameAlarm.start();}catch(e){} showFlash('✅ Game #'+d.session_id+' started!','success'); setTimeout(safeReload,1800); }
        else if (d.status==='already_active') { safeReload(); }
        else { showFlash('⚠️ '+(d.message||'Could not start.'),'error'); launchLocks[courtId]=false; }
    })
    .catch(err=>{ showFlash('❌ '+err.message,'error'); launchLocks[courtId]=false; });
}

function autoEndGame(courtId) {
    // Find session id from page data — re-fetch to be safe
    const timerEl = document.getElementById('timer-'+courtId);
    if (!timerEl) return;
    const sessionId = timerEl.dataset.sessionId;
    if (!sessionId) { safeReload(); return; }
    if (endLocks[courtId]) return;
    endLocks[courtId] = true;
    fetch(APP_URL+'/court/end_game.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({session_id: parseInt(sessionId), action:'complete'}),
    })
    .then(r=>r.json())
    .then(d=>{ showFlash('🏆 '+(d.message||'Game ended.'),'success'); setTimeout(safeReload,1200); })
    .catch(()=>{ safeReload(); });
}

function endGame(sessionId, action) {
    if (!confirm(action==='cancel'?'Cancel and REFUND all players?':'End this game now?')) return;
    fetch(APP_URL+'/court/end_game.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({session_id:sessionId, action}),
    })
    .then(r=>r.json())
    .then(d=>{ showFlash((d.status==='success'?'✅ ':'❌ ')+(d.message||'Done.'),'success'); setTimeout(safeReload,1200); })
    .catch(()=>showFlash('❌ Connection error.','error'));
}

function forceStartGame(courtId) {
    if (!confirm('Force start a game on this court now?')) return;
    doAutoLaunch(courtId);
}

function skipWarmup(courtId) {
    if (!confirm('Skip warmup and start the game immediately?')) return;
    clearInterval(warmupTimers[courtId]);
    doAutoLaunch(courtId);
}

function gameControl(courtId, sessionId, action, remSecs) {
    if (action==='reset' && !confirm('Reset timer to full duration?')) return;
    fetch(APP_URL+'/court/pause_game.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({action, session_id:sessionId, rem_secs:remSecs}),
    })
    .then(r=>r.json())
    .then(d=>{
        if (d.status==='paused')  { showFlash('⏸ '+d.message,'warn');    setTimeout(safeReload,800); }
        else if (d.status==='resumed') { showFlash('▶ '+d.message,'success'); setTimeout(safeReload,800); }
        else if (d.status==='reset')   { showFlash('🔄 '+d.message,'success'); setTimeout(safeReload,800); }
        else showFlash('⚠️ '+(d.message||'Error.'),'error');
    })
    .catch(()=>showFlash('❌ Connection error.','error'));
}

function setMaintenance(courtId) {
    if (!confirm('Put this court into maintenance mode?')) return;
    fetch(APP_URL+'/admin/court_edit.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({action:'set_maintenance', court_id:courtId, value:true}),
    })
    .then(r=>r.json())
    .then(d=>{ showFlash('🔧 Court set to maintenance.','warn'); setTimeout(safeReload,1200); })
    .catch(()=>showFlash('❌ Error.','error'));
}

function clearMaintenance(courtId) {
    if (!confirm('Clear maintenance and make this court available?')) return;
    fetch(APP_URL+'/admin/court_edit.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({action:'set_maintenance', court_id:courtId, value:false}),
    })
    .then(r=>r.json())
    .then(d=>{ showFlash('✅ Court is now available.','success'); setTimeout(safeReload,1200); })
    .catch(()=>showFlash('❌ Error.','error'));
}

// ── POLLING — 15s ─────────────────────────────────────────────
function poll() {
    fetch(APP_URL+'/court/queue_status.php', {credentials:'same-origin'})
        .then(r=>r.ok?r.json():null)
        .then(d=>{
            if (!d) return;
            const label = document.getElementById('ar-label');
            if (label) label.textContent = 'Updated '+new Date().toLocaleTimeString();
            // If any court changed game state, reload
            if (d.courts) {
                // Light check: if server reports game active/idle different from rendered state
                d.courts.forEach(c => {
                    const timerEl  = document.getElementById('timer-'+c.court_id);
                    const warmupEl = document.getElementById('warmup-'+c.court_id);
                    const hasGame  = !!timerEl;
                    const hasWarmup= !!warmupEl;
                    const serverHasGame  = c.active_game !== null && c.active_game !== undefined;
const serverHasWarmup = (c.queue_count >= PLAYERS_NEEDED && !serverHasGame);
if (serverHasGame !== hasGame || serverHasWarmup !== hasWarmup) { safeReload(); }
                });
            }
        })
        .catch(()=>{});
}
pollTimer = setInterval(()=>{ if(!document.hidden) poll(); }, 15000);
document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) poll(); });

// ── MANUAL ADD MODAL ──────────────────────────────────────────
(function(){
    'use strict';
    const overlay    = document.getElementById('manual-add-overlay');
    const searchInp  = document.getElementById('ma-search-inp');
    const resultsEl  = document.getElementById('ma-results');
    const idSection  = document.getElementById('ma-id-section');
    const idCheckbox = document.getElementById('ma-id-checkbox');
    const confirmBtn = document.getElementById('ma-confirm-btn');
    const cancelBtn  = document.getElementById('ma-cancel-btn');
    const openBtn    = document.getElementById('open-manual-add-btn');
    const courtSel   = document.getElementById('ma-court-sel');
    let selectedPlayer=null, searchTimeout=null;

    function openModal()  { overlay.classList.add('open');    document.body.style.overflow='hidden'; setTimeout(()=>searchInp.focus(),80); resetModal(); }
    function closeModal() { overlay.classList.remove('open'); document.body.style.overflow=''; resetModal(); }
    function resetModal() {
        searchInp.value=''; resultsEl.innerHTML='<div class="ma-empty">Start typing to search for a player.</div>';
        selectedPlayer=null; idSection.style.display='none'; idCheckbox.checked=false;
        confirmBtn.disabled=true; confirmBtn.textContent='✅ Add to Queue';
    }
    function updateConfirm() { confirmBtn.disabled=!(selectedPlayer && idCheckbox.checked); }
    openBtn.addEventListener('click', openModal);
    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', e=>{ if(e.target===overlay) closeModal(); });
    document.addEventListener('keydown', e=>{ if(e.key==='Escape'&&overlay.classList.contains('open')) closeModal(); });
    idCheckbox.addEventListener('change', updateConfirm);
    searchInp.addEventListener('input', ()=>{
        clearTimeout(searchTimeout);
        const q=searchInp.value.trim();
        if(q.length<2){ resultsEl.innerHTML='<div class="ma-empty">Type at least 2 characters.</div>'; return; }
        resultsEl.innerHTML='<div class="ma-empty" style="color:var(--accent);">Searching…</div>';
        searchTimeout=setTimeout(()=>doSearch(q),280);
    });
    function doSearch(q) {
        fetch(APP_URL+'/admin/manual_add_player.php', {
            method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
            body: JSON.stringify({action:'search',q}),
        }).then(r=>r.json()).then(d=>{
            if(!d.players||!d.players.length){ resultsEl.innerHTML='<div class="ma-empty">No players found.</div>'; return; }
            renderResults(d.players);
        }).catch(()=>{ resultsEl.innerHTML='<div class="ma-empty" style="color:var(--danger);">Search failed.</div>'; });
    }
    function renderResults(players) {
        let html='';
        players.forEach(p=>{
            const initial=(p.full_name||p.username||'?')[0].toUpperCase();
            const disabled=p.in_queue||p.in_active_game;
            const statusTxt=p.in_active_game?'<span style="color:var(--success);">▶ In game</span>':p.in_queue?'<span style="color:var(--warn);">⏳ In queue</span>':'<span style="color:var(--muted);">₱'+parseFloat(p.balance).toFixed(2)+'</span>';
            html+=`<div class="ma-player-row${disabled?' disabled':''}" data-id="${p.id}" data-name="${escHtml(p.full_name)}" data-username="${escHtml(p.username)}">`
                +`<div class="ma-avatar">${escHtml(initial)}</div>`
                +`<div class="ma-player-info"><div class="ma-player-name">${escHtml(p.full_name)}</div><div class="ma-player-meta">@${escHtml(p.username)}</div></div>`
                +`<div style="font-size:11px;flex-shrink:0;">${statusTxt}${disabled?'<br/><span style="font-size:10px;color:var(--muted);">already added</span>':''}</div></div>`;
        });
        resultsEl.innerHTML=html;
        resultsEl.querySelectorAll('.ma-player-row:not(.disabled)').forEach(row=>{
            row.addEventListener('click',()=>{
                resultsEl.querySelectorAll('.ma-player-row').forEach(r=>r.classList.remove('selected'));
                row.classList.add('selected');
                selectedPlayer={id:parseInt(row.dataset.id,10),name:row.dataset.name,username:row.dataset.username};
                idSection.style.display='flex'; idCheckbox.checked=false; confirmBtn.disabled=true; updateConfirm();
                idSection.scrollIntoView({behavior:'smooth',block:'nearest'});
            });
        });
    }
    confirmBtn.addEventListener('click',()=>{
        if(!selectedPlayer||!idCheckbox.checked) return;
        const courtId=parseInt(courtSel?.value||COURT_ID_DEF,10);
        confirmBtn.disabled=true; confirmBtn.textContent='⏳ Adding…';
        fetch(APP_URL+'/admin/manual_add_player.php', {
            method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
            body: JSON.stringify({action:'add', user_id:selectedPlayer.id, court_id:courtId}),
        }).then(r=>r.json()).then(d=>{
            if(d.status==='ok'){ showFlash('✅ '+d.message,'success'); closeModal(); setTimeout(safeReload,1200); }
            else { showFlash('❌ '+(d.message||'Could not add.'),'error'); confirmBtn.disabled=false; confirmBtn.textContent='✅ Add to Queue'; }
        }).catch(()=>{ showFlash('❌ Connection error.','error'); confirmBtn.disabled=false; confirmBtn.textContent='✅ Add to Queue'; });
    });
}());

document.addEventListener('click', ()=>{ try{GameAlarm.unlock();}catch(e){} }, {once:true});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>