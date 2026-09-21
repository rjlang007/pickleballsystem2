<?php
// ============================================================
//  FILE: court/scanner.php  — Priority 4 (multi-court tabs)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/logo.php';
requireStaff();

$kioskToken = hash('sha256', APP_NAME . '|kiosk|' . date('Y-m-d'));
$cookiePath = appBasePath() ? appBasePath() . '/court/' : '/court/';
setcookie('kiosk_token', $kioskToken, [
    'expires'  => strtotime('tomorrow'),
    'path'     => $cookiePath,
    'httponly' => true,
    'samesite' => 'Strict',
]);

$db = getDB();

// Auto-expire stale queue entries on page load
$db->exec("
    DELETE FROM falcon.game_queue
    WHERE session_id IS NULL
    AND joined_at < NOW() - INTERVAL '30 minutes'
");

// Fetch all active courts with live status
$allCourts = $db->query("
    SELECT id, name, short_code, color, court_type, is_active, is_maintenance,
           live_status, players_on_court, queue_count, max_queue,
           credit_cost, game_duration, warmup_mins, pass_hours,
           active_session_id, game_started_at, game_duration_mins
    FROM falcon.v_court_status
    WHERE is_active = TRUE
    ORDER BY sort_order, id
")->fetchAll();

// Default to first court for initial server-side render
$defaultCourt = $allCourts[0] ?? null;
$defaultCourtId = $defaultCourt ? (int)$defaultCourt['id'] : 1;

// Today stats (all courts)
$todayGames = (int)$db->query(
    "SELECT COUNT(*) FROM falcon.game_sessions WHERE DATE(started_at) = CURRENT_DATE"
)->fetchColumn();

$todayPlayers = (int)$db->query("
    SELECT COUNT(DISTINCT gp.user_id)
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE DATE(gs.started_at) = CURRENT_DATE
")->fetchColumn();

// Current mode for default court
$courtModeNow = 'open_play';
try {
    $db->exec("SET TIME ZONE 'Asia/Manila'");
    $nowTime = date('H:i:s');
    $modeRow = $db->query("
        SELECT mode FROM falcon.court_slot_modes
        WHERE court_id = {$defaultCourtId}
          AND time_from <= '{$nowTime}' AND time_to > '{$nowTime}'
          AND (
                (slot_date = CURRENT_DATE)
                OR (slot_date IS NULL
                    AND day_of_week = EXTRACT(DOW FROM CURRENT_DATE)::int)
              )
        ORDER BY CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END ASC
        LIMIT 1
    ")->fetch();
    if ($modeRow) $courtModeNow = $modeRow['mode'];
} catch (PDOException $e) {
    error_log('[scanner] court mode check: ' . $e->getMessage());
}

$logoSm = pickleballLogo(20);
$logoMd = pickleballLogo(26);

$typeIcons = ['covered'=>'🏠','uncovered'=>'☀️','indoor'=>'🏢','outdoor'=>'🌿'];
$statusColors = [
    'available'   => ['color' => 'var(--accent)',  'icon' => '●',  'label' => 'Available'],
    'active'      => ['color' => '#00aaff',         'icon' => '🎮', 'label' => 'In Game'],
    'queuing'     => ['color' => 'var(--warn)',     'icon' => '⏳', 'label' => 'Queuing'],
    'maintenance' => ['color' => 'var(--muted)',    'icon' => '🔧', 'label' => 'Maintenance'],
    'closed'      => ['color' => 'var(--danger)',   'icon' => '✗',  'label' => 'Closed'],
    'reserved'    => ['color' => '#a855f7',         'icon' => '📅', 'label' => 'Reserved'],
];

$pageTitle = 'Court Entrance Scanner';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
        :root {
            --bg:      #090d18;
            --surface: #111827;
            --surface2:#1a2332;
            --surface3:#212d42;
            --border:  #1e2d45;
            --accent:  #00e5a0;
            --accent2: #00aaff;
            --danger:  #ef4444;
            --warn:    #f59e0b;
            --success: #10b981;
            --text:    #e9eef7;
            --muted:   #8695ad;
            --grad-accent: linear-gradient(135deg, #00e5a0 0%, #00c2b8 55%, #00aaff 100%);
            --ease: cubic-bezier(0.22, 1, 0.36, 1);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { max-width: 100vw; overflow-x: hidden; }
        body {
            background: var(--bg); color: var(--text);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh; overflow-x: hidden; user-select: none;
        }
        body::before {
            content: ''; position: fixed; inset: 0;
            background-image:
                linear-gradient(rgba(0,229,160,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,229,160,0.03) 1px, transparent 1px);
            background-size: 40px 40px; pointer-events: none; z-index: 0;
        }

        #scanner-input {
            position: fixed; left: -9999px; top: 0;
            width: 1px; height: 1px; opacity: 0;
            pointer-events: none; font-size: 16px;
            border: none; outline: none;
        }

        #focus-indicator {
            position: fixed;
            bottom: max(16px, env(safe-area-inset-bottom));
            left: 50%; transform: translateX(-50%);
            z-index: 200;
            display: flex; align-items: center; gap: 8px;
            padding: 8px 18px; border-radius: 20px;
            font-size: 12px; font-weight: 700; letter-spacing: 0.5px;
            transition: all 0.3s; pointer-events: none;
        }
        #focus-indicator.focused {
            background: rgba(0,229,160,0.15); border: 1px solid rgba(0,229,160,0.4); color: var(--accent);
        }
        #focus-indicator.unfocused {
            background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.4);
            color: var(--danger); pointer-events: all; cursor: pointer;
        }
        .focus-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .focused .focus-dot   { background: var(--accent); animation: focusPulse 2s infinite; }
        .unfocused .focus-dot { background: var(--danger); }
        @keyframes focusPulse { 0%,100%{opacity:1} 50%{opacity:0.3} }



        /* ── Main layout ── */
        .kiosk-body { position: relative; z-index: 1; display: grid; grid-template-columns: 1fr 400px; align-items: start; }
        @media (max-width: 960px) { .kiosk-body { grid-template-columns: 1fr; } }

        /* ── Scan panel (left) ── */
        .scan-panel {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: clamp(32px,6vw,72px) clamp(16px,4vw,48px);
            min-height: clamp(320px,55vh,640px); transition: background 0.4s var(--ease, ease);
        }
        .scan-panel.state-idle            { background: transparent; }
        .scan-panel.state-success         { background: radial-gradient(circle at 50% 30%, rgba(16,185,129,0.14), rgba(16,185,129,0.03) 70%); }
        .scan-panel.state-error           { background: radial-gradient(circle at 50% 30%, rgba(239,68,68,0.14), rgba(239,68,68,0.03) 70%); }
        .scan-panel.state-warn            { background: radial-gradient(circle at 50% 30%, rgba(245,158,11,0.12), rgba(245,158,11,0.02) 70%); }
        .scan-panel.state-reservation     { background: radial-gradient(circle at 50% 30%, rgba(0,184,255,0.12), rgba(0,184,255,0.02) 70%); }
        .scan-panel.state-late            { background: radial-gradient(circle at 50% 30%, rgba(245,158,11,0.16), rgba(245,158,11,0.03) 70%); }
        .scan-panel.state-payment-pending { background: radial-gradient(circle at 50% 30%, rgba(245,158,11,0.16), rgba(245,158,11,0.03) 70%); }

        .scan-icon     { font-size: clamp(56px,10vw,110px); margin-bottom: 20px; line-height: 1; }
        .scan-title    { font-family: 'Bebas Neue', sans-serif; font-size: clamp(32px,7vw,72px); letter-spacing: 2px; text-align: center; line-height: 1; margin-bottom: 14px; transition: color 0.3s; }
        .scan-subtitle { font-size: clamp(13px,2vw,20px); color: var(--muted); text-align: center; max-width: 520px; line-height: 1.5; padding: 0 8px; }

        .scan-panel.state-idle .scan-title            { color: var(--text); }
        .scan-panel.state-success .scan-title         { color: var(--success); }
        .scan-panel.state-error .scan-title           { color: var(--danger); }
        .scan-panel.state-warn .scan-title            { color: var(--warn); }
        .scan-panel.state-reservation .scan-title     { color: var(--accent2); }
        .scan-panel.state-late .scan-title            { color: var(--warn); }
        .scan-panel.state-payment-pending .scan-title { color: var(--warn); }

        .pulse-ring { position: relative; display: inline-block; }
        .pulse-ring::after { content:''; position:absolute; inset:-20px; border-radius:50%; opacity:0; pointer-events:none; }
        .state-success .pulse-ring::after     { border:4px solid var(--success); animation:pulseOut .6s ease-out; }
        .state-error .pulse-ring::after       { border:4px solid var(--danger);  animation:pulseOut .6s ease-out; }
        .state-reservation .pulse-ring::after { border:4px solid var(--accent2); animation:pulseOut .6s ease-out; }
        .state-payment-pending .pulse-ring::after { border:4px solid var(--warn); animation:pulseOut .6s ease-out; }
        @keyframes pulseOut { 0%{transform:scale(.8);opacity:.8} 100%{transform:scale(1.6);opacity:0} }

        .scan-icon-logo {
            margin-bottom: 20px;
            filter: drop-shadow(0 0 24px rgba(0,229,160,0.4));
            animation: logoPulse 3s ease-in-out infinite;
        }
        @keyframes logoPulse {
            0%,100% { filter: drop-shadow(0 0 16px rgba(0,229,160,0.35)); }
            50%      { filter: drop-shadow(0 0 40px rgba(0,229,160,0.75)); }
        }

        /* ── Court destination box (shown after successful scan) ── */
        .court-dest-box {
            display: none; margin-top: 16px;
            border-radius: 14px; padding: 14px 20px;
            text-align: center; width: min(380px,88vw);
            animation: slideUp .3s ease-out;
        }
        .court-dest-box.visible { display: block; }
        .court-dest-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; opacity: .7; margin-bottom: 4px; }
        .court-dest-name  { font-family: 'Bebas Neue', sans-serif; font-size: clamp(22px,5vw,36px); letter-spacing: 2px; }
        .court-dest-code  { font-family: 'Bebas Neue', sans-serif; font-size: 14px; opacity: .7; letter-spacing: 3px; }
        .court-dest-type  { font-size: 12px; opacity: .6; margin-top: 3px; }

        .player-card { display:none; margin-top:16px; background:var(--surface); border:2px solid var(--success); border-radius:20px; padding:20px 28px; text-align:center; width:min(380px,88vw); animation:slideUp .3s ease-out; }
        .player-card.visible { display:block; }
        .player-card.paid-online-border { border-color: var(--accent2); }
        .player-card .player-name    { font-family:'Bebas Neue',sans-serif; font-size:clamp(22px,5vw,36px); color:var(--accent); letter-spacing:1px; }
        .player-card .player-meta    { color:var(--muted); font-size:14px; margin-top:4px; }
        .player-card .player-balance { margin-top:12px; font-size:18px; font-weight:700; }
        .player-card .queue-pos      { font-family:'Bebas Neue',sans-serif; font-size:clamp(36px,7vw,56px); color:var(--accent2); line-height:1; }
        .player-card .queue-label    { font-size:13px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }
        .paid-online-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(0,184,255,0.14); border:1px solid rgba(0,184,255,0.4); border-radius:20px; padding:5px 14px; font-size:12px; font-weight:700; color:var(--accent2); margin-top:10px; }

        .res-timer-card { display:none; margin-top:20px; background:rgba(0,184,255,0.08); border:2px solid var(--accent2); border-radius:18px; padding:20px 24px; text-align:center; width:min(380px,88vw); animation:slideUp .3s ease-out; }
        .res-timer-card.visible { display:block; }
        .res-timer-card.late    { border-color:var(--warn); background:rgba(245,158,11,0.10); }
        .res-end-time   { font-family:'Bebas Neue',sans-serif; font-size:18px; color:var(--muted); letter-spacing:1px; margin-bottom:4px; }
        .res-countdown  { font-family:'Bebas Neue',sans-serif; font-size:clamp(48px,10vw,80px); color:var(--accent2); line-height:1; letter-spacing:3px; }
        .res-countdown.late-color { color:var(--warn); }
        .res-label      { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:2px; margin-top:6px; }
        .late-warning-box { background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.4); border-radius:10px; padding:10px 14px; margin-top:12px; font-size:13px; color:var(--warn); line-height:1.5; }
        .extension-strip { margin-top:12px; padding:10px 14px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); border-radius:10px; font-size:13px; color:var(--warn); line-height:1.5; }
        .extend-btn { margin-top:8px; padding:8px 20px; border-radius:8px; background:var(--warn); color:#000; font-weight:700; font-size:13px; border:none; cursor:pointer; touch-action:manipulation; transition:background 0.15s; }
        .extend-btn:hover { background:#fbbf24; }
        .rt-paid-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(0,184,255,0.14); border:1px solid rgba(0,184,255,0.4); border-radius:20px; padding:5px 14px; font-size:12px; font-weight:700; color:var(--accent2); margin-top:10px; }

        .payment-pending-card { display:none; margin-top:20px; background:rgba(245,158,11,0.10); border:2px solid var(--warn); border-radius:18px; padding:20px 24px; text-align:center; width:min(380px,88vw); animation:slideUp .3s ease-out; }
        .payment-pending-card.visible { display:block; }
        .pending-spinner { width:48px; height:48px; border:4px solid rgba(245,158,11,0.2); border-top-color:var(--warn); border-radius:50%; animation:spin 1s linear infinite; margin:0 auto 12px; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }

        .scan-instruction { display:flex; align-items:center; gap:12px; margin-top:32px; padding:12px 22px; background:var(--surface); border:1px solid var(--border); border-radius:50px; font-size:clamp(12px,1.6vw,15px); color:var(--muted); max-width:88vw; }
        .scan-dot { width:12px; height:12px; border-radius:50%; background:var(--accent); animation:blink 1.5s infinite; flex-shrink:0; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }

        .scan-border { position:fixed; inset:0; pointer-events:none; z-index:9999; border:8px solid transparent; transition:border-color .15s,opacity .4s; opacity:0; }
        .scan-border.flash-success     { border-color:var(--success); opacity:1; }
        .scan-border.flash-error       { border-color:var(--danger);  opacity:1; }
        .scan-border.flash-warn        { border-color:var(--warn);    opacity:1; }
        .scan-border.flash-reservation { border-color:var(--accent2); opacity:1; }

        /* ══════════════════════════════════════════════════
           QUEUE PANEL — multi-court tabs
        ══════════════════════════════════════════════════ */
        .queue-panel { background:var(--surface); border-left:2px solid var(--border); display:flex; flex-direction:column; }
        @media (max-width: 960px) { .queue-panel { border-left:none; border-top:2px solid var(--border); } }

        /* Court tab strip */
        .court-tab-strip {
            display: flex;
            gap: 0;
            border-bottom: 2px solid var(--border);
            overflow-x: auto;
            scrollbar-width: none;
            flex-shrink: 0;
        }
        .court-tab-strip::-webkit-scrollbar { display: none; }

        .court-tab {
            flex: 1;
            min-width: 56px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            padding: 10px 8px;
            cursor: pointer;
            border: none;
            border-bottom: 3px solid transparent;
            background: transparent;
            color: var(--muted);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 15px;
            letter-spacing: 1px;
            transition: background 0.2s, color 0.2s, border-color 0.2s;
            position: relative;
            touch-action: manipulation;
        }
        .court-tab:hover { background: rgba(255,255,255,0.04); color: var(--text); }
        .court-tab.active { color: var(--text); }
        .court-tab .tab-dot {
            width: 7px; height: 7px; border-radius: 50%;
            transition: background 0.2s;
        }
        .court-tab .tab-code { font-size: 13px; }
        .court-tab .tab-status-icon { font-size: 9px; opacity: .7; line-height: 1; }

        /* All-courts summary strip */
        .courts-summary-strip {
            display: flex;
            gap: 6px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            overflow-x: auto;
            scrollbar-width: none;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .courts-summary-strip::-webkit-scrollbar { display: none; }
        .summary-pill {
            display: flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            border: 1px solid; white-space: nowrap;
            font-family: 'Bebas Neue', sans-serif;
            letter-spacing: .5px;
        }
        .summary-pip-row { display: flex; gap: 3px; align-items: center; }
        .summary-pip { width: 8px; height: 8px; border-radius: 50%; }

        /* Queue header */
        .queue-header { padding: 16px 18px 12px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
        .queue-header h2 { font-family:'Bebas Neue',sans-serif; font-size:22px; letter-spacing:1px; color:var(--accent); display:flex; align-items:center; gap:8px; }
        .queue-header .court-name-label { font-size:13px; color:var(--muted); margin-top:2px; }

        /* Queue slots */
        .queue-slots-list { padding:14px; display:flex; flex-direction:column; gap:9px; flex:1; overflow-y:auto; }
        @media (max-width: 960px) { .queue-slots-list { display:grid; grid-template-columns:1fr 1fr; } }
        @media (max-width: 480px)  { .queue-slots-list { grid-template-columns:1fr; } }

        .queue-slot { background:var(--surface2); border:2px dashed var(--border); border-radius:14px; padding:12px 16px; display:flex; align-items:center; gap:12px; min-height:64px; transition:border-color .3s var(--ease),background .3s var(--ease),transform .2s var(--ease); }
        .queue-slot.filled { border-style:solid; border-color:var(--accent); background:rgba(0,229,160,.09); box-shadow:0 4px 16px rgba(0,229,160,0.08); }
        .queue-slot.filled:hover { transform: translateY(-1px); }
        .queue-slot .slot-num  { font-family:'Bebas Neue',sans-serif; font-size:28px; color:var(--muted); line-height:1; min-width:26px; flex-shrink:0; }
        .queue-slot.filled .slot-num { color:var(--accent); }
        .queue-slot .slot-info { flex:1; min-width:0; }
        .queue-slot .slot-name  { font-weight:700; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .queue-slot .slot-time  { font-size:11px; color:var(--muted); margin-top:2px; }
        .queue-slot .slot-empty { color:var(--muted); font-size:13px; }

        .queue-progress { margin:2px 14px 0; height:5px; background:var(--border); border-radius:6px; overflow:hidden; }
        .queue-progress-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--accent2)); border-radius:6px; transition:width .5s ease; }
        .queue-count-label { margin:7px 14px 0; font-size:12px; color:var(--muted); }

        /* Active game strip per-tab */
        .active-game-strip {
            margin: 10px 14px 0;
            padding: 13px 16px;
            border-radius: 12px;
            border: 2px solid;
            flex-shrink: 0;
        }
        .active-game-strip.open-play   { background:rgba(16,185,129,.08); border-color:var(--success); }
        .active-game-strip.reservation { background:rgba(0,184,255,.08);  border-color:var(--accent2); }
        .ag-label { font-size:10px; text-transform:uppercase; letter-spacing:1px; font-weight:700; }
        .ag-timer { font-family:'Bebas Neue',sans-serif; font-size:clamp(28px,4vw,40px); line-height:1; margin-top:2px; }
        .ag-sub   { font-size:11px; color:var(--muted); }

        .stats-strip { padding:12px 14px; border-top:1px solid var(--border); display:grid; grid-template-columns:1fr 1fr; gap:8px; flex-shrink:0; }
        .stat-mini     { background:var(--surface2); border-radius:10px; padding:10px; text-align:center; }
        .stat-mini .val { font-family:'Bebas Neue',sans-serif; font-size:clamp(20px,4vw,28px); color:var(--accent); }
        .stat-mini .lbl { font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; }




        /* ── Extension modal ── */
        .ext-modal-overlay { display:none; position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,0.85); backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px; }
        .ext-modal-overlay.open { display:flex; }
        .ext-modal-box { background:var(--surface); border-radius:22px; padding:32px 28px; max-width:440px; width:100%; text-align:center; animation:extIn 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        @keyframes extIn { from{opacity:0;transform:scale(0.88)} to{opacity:1;transform:scale(1)} }
        .ext-modal-box.state-available   { border:2px solid var(--warn);   box-shadow:0 0 60px rgba(245,158,11,0.25); }
        .ext-modal-box.state-unavailable { border:2px solid var(--border); box-shadow:0 0 40px rgba(0,0,0,0.4); }
        .ext-modal-icon  { font-size:54px; line-height:1; margin-bottom:14px; }
        .ext-modal-title { font-family:'Bebas Neue',sans-serif; font-size:clamp(24px,5vw,34px); letter-spacing:1px; margin-bottom:8px; }
        .ext-modal-time-left { font-family:'Bebas Neue',sans-serif; font-size:52px; line-height:1; letter-spacing:3px; margin:10px 0 4px; }
        .ext-modal-time-label { font-size:12px; color:var(--muted); margin-bottom:18px; }
        .ext-next-slot-box { border-radius:12px; padding:14px 16px; margin-bottom:18px; text-align:left; font-size:13px; line-height:1.7; }
        .ext-next-slot-box.available   { background:rgba(0,229,160,0.07);  border:1px solid rgba(0,229,160,0.25);  color:var(--text); }
        .ext-next-slot-box.unavailable { background:rgba(239,68,68,0.06);  border:1px solid rgba(239,68,68,0.25);  color:var(--text); }
        .ext-next-slot-label { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); margin-bottom:6px; display:block; }
        .ext-next-slot-time  { font-family:'Bebas Neue',sans-serif; font-size:22px; letter-spacing:1px; }
        .ext-next-slot-time.available   { color:var(--accent); }
        .ext-next-slot-time.unavailable { color:var(--danger); }
        .ext-booked-by  { font-size:12px; color:var(--muted); margin-top:4px; }
        .ext-spots-left { font-size:12px; color:var(--accent); font-weight:700; margin-top:2px; }
        .ext-poll-indicator { display:inline-flex; align-items:center; gap:5px; font-size:10px; color:var(--muted); margin-bottom:14px; }
        .ext-poll-dot { width:6px; height:6px; border-radius:50%; background:var(--accent); flex-shrink:0; }
        .ext-poll-dot.active { animation:blink 1.5s infinite; }
        .ext-modal-btns { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
        .ext-modal-btns .btn-extend  { flex:1; min-width:130px; padding:13px 20px; border-radius:12px; background:var(--warn); color:#000; font-weight:800; font-size:15px; border:none; cursor:pointer; touch-action:manipulation; transition:background 0.15s; }
        .ext-modal-btns .btn-extend:hover { background:#fbbf24; }
        .ext-modal-btns .btn-dismiss { flex:1; min-width:130px; padding:13px 20px; border-radius:12px; background:transparent; color:var(--muted); font-weight:600; font-size:14px; border:1.5px solid var(--border); cursor:pointer; touch-action:manipulation; transition:border-color 0.15s,color 0.15s; }
        .ext-modal-btns .btn-dismiss:hover { border-color:var(--muted); color:var(--text); }
        .ext-modal-btns .btn-ok { flex:1; min-width:160px; padding:13px 20px; border-radius:12px; background:rgba(0,229,160,0.1); color:var(--accent); font-weight:700; font-size:15px; border:1.5px solid rgba(0,229,160,0.3); cursor:pointer; touch-action:manipulation; transition:background 0.15s; }
        .ext-modal-btns .btn-ok:hover { background:rgba(0,229,160,0.2); }
</style>
</head>
<body>

<div class="scan-border" id="scan-border"></div>
<input type="text" id="scanner-input" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" tabindex="-1" inputmode="none"/>
<div id="focus-indicator" class="focused" onclick="forceFocus()">
    <div class="focus-dot"></div>
    <span id="focus-label">Scanner Ready</span>
</div>

<!-- EXTENSION MODAL -->
<div id="ext-modal" class="ext-modal-overlay" role="dialog" aria-modal="true">
    <div class="ext-modal-box state-available" id="ext-modal-box">
        <div class="ext-modal-icon" id="ext-icon">⏰</div>
        <div class="ext-modal-title" id="ext-title" style="color:var(--warn);">Time Almost Up!</div>
        <div class="ext-modal-time-left" id="ext-modal-time" style="color:var(--warn);">—</div>
        <div class="ext-modal-time-label">remaining in your current slot</div>
        <div class="ext-poll-indicator">
            <span class="ext-poll-dot active" id="ext-poll-dot"></span>
            <span id="ext-poll-label">Checking next slot availability…</span>
        </div>
        <div class="ext-next-slot-box available" id="ext-slot-box">
            <span class="ext-next-slot-label">Next Slot</span>
            <div class="ext-next-slot-time available" id="ext-slot-time">—</div>
            <div class="ext-spots-left" id="ext-spots-left"></div>
            <div class="ext-booked-by" id="ext-booked-by" style="display:none;"></div>
        </div>
        <div style="font-size:14px;color:var(--muted);line-height:1.7;margin-bottom:18px;" id="ext-desc">
            Would you like to book the next slot and continue playing?
        </div>
        <div class="ext-modal-btns" id="ext-btns"></div>
    </div>
</div>



<div class="kiosk-body">

    <!-- LEFT: scan panel -->
    <div class="scan-panel state-idle" id="scan-panel">
        <div class="pulse-ring" id="pulse-ring">
            <div class="scan-icon-logo" id="scan-icon-logo"><?= pickleballLogo(100) ?></div>
            <div class="scan-icon" id="scan-icon" style="display:none;"></div>
        </div>
        <div class="scan-title"    id="scan-title">SCAN YOUR QR CODE</div>
        <div class="scan-subtitle" id="scan-subtitle">
            Open your app and hold your QR or barcode up to the scanner
        </div>

        <!-- Court destination box — shown after successful scan -->
        <div class="court-dest-box" id="court-dest-box">
            <div class="court-dest-label">Proceed To</div>
            <div class="court-dest-name" id="cdb-name">—</div>
            <div class="court-dest-code" id="cdb-code"></div>
            <div class="court-dest-type" id="cdb-type"></div>
        </div>

        <div class="player-card" id="player-card">
            <div class="player-name"    id="pc-name">—</div>
            <div class="player-meta"    id="pc-meta">—</div>
            <div class="player-balance" id="pc-balance">—</div>
            <div class="queue-pos"      id="pc-qpos">—</div>
            <div class="queue-label">Queue Position</div>
        </div>

        <div class="res-timer-card" id="res-timer-card">
            <div class="player-name" id="rt-name" style="font-family:'Bebas Neue',sans-serif;font-size:clamp(20px,4vw,30px);color:var(--accent2);margin-bottom:8px;">—</div>
            <div class="res-end-time"  id="rt-end-time">Session ends at —</div>
            <div class="res-countdown" id="rt-countdown">0:00:00</div>
            <div class="res-label">Time Remaining</div>
            <div class="late-warning-box"  id="rt-late-box"  style="display:none;"></div>
            <div class="extension-strip"   id="rt-ext-strip" style="display:none;">
                ⚠️ Less than 15 minutes left!
                <br><button class="extend-btn" onclick="openExtModal()">View Extension Options</button>
            </div>
            <div class="rt-paid-badge" id="rt-paid-badge" style="display:none;">
                💳 Pre-paid Online — No Balance Deduction
            </div>
        </div>

        <div class="payment-pending-card" id="payment-pending-card">
            <div class="pending-spinner"></div>
            <div style="font-family:'Bebas Neue',sans-serif;font-size:clamp(20px,4vw,30px);color:var(--warn);margin-bottom:8px;" id="pp-name">—</div>
            <div style="font-size:14px;color:var(--muted);line-height:1.6;">
                ⏳ Your online payment is being verified by the admin.<br>
                Please wait — they have been notified.
            </div>
        </div>

        <div class="scan-instruction" id="scan-instruction">
            <div class="scan-dot"></div>
            <span>Scanner ready — waiting for QR code or barcode</span>
        </div>
    </div>

    <!-- RIGHT: queue panel with multi-court tabs -->
    <aside class="queue-panel" id="queue-panel">

        <!-- Court tab strip -->
        <div class="court-tab-strip" id="court-tab-strip">
            <?php foreach ($allCourts as $idx => $c):
                $st = $c['live_status'] ?? 'available';
                $sc = $statusColors[$st] ?? $statusColors['available'];
            ?>
<button class="court-tab <?= $idx === 0 ? 'active' : '' ?>"
        data-court-id="<?= (int)$c['id'] ?>"
        data-court-color="<?= htmlspecialchars($c['color'] ?? '#00e5a0') ?>"
        data-court-name="<?= htmlspecialchars($c['name'] ?? 'Court') ?>"
        id="tab-<?= (int)$c['id'] ?>">
                <span class="tab-dot" style="background:<?= $sc['color'] ?>;"></span>
                <span class="tab-code"><?= htmlspecialchars($c['short_code'] ?? '') ?></span>
                <span class="tab-status-icon"><?= $sc['icon'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- All-courts summary strip -->
        <div class="courts-summary-strip" id="courts-summary-strip">
            <?php foreach ($allCourts as $c):
                $st  = $c['live_status'] ?? 'available';
                $sc  = $statusColors[$st] ?? $statusColors['available'];
                $bg  = 'rgba(0,0,0,0.3)';
                $players = (int)($c['players_on_court'] ?? 0);
                $queue   = (int)($c['queue_count'] ?? 0);
                $max     = (int)($c['max_queue'] ?? PLAYERS_PER_GAME);
            ?>
            <div class="summary-pill"
                 style="background:<?= $bg ?>;border-color:<?= $sc['color'] ?>22;color:<?= $sc['color'] ?>;">
                <span><?= htmlspecialchars($c['short_code'] ?? '') ?></span>
                <?php if ($st === 'active'): ?>
                    <span class="summary-pip-row">
                        <?php for ($pi=0; $pi<$max; $pi++): ?>
                            <span class="summary-pip" style="background:<?= $pi < $players ? $sc['color'] : 'rgba(255,255,255,0.12)' ?>;"></span>
                        <?php endfor; ?>
                    </span>
                    <span>🎮</span>
                <?php elseif ($st === 'queuing'): ?>
                    <span class="summary-pip-row">
                        <?php for ($pi=0; $pi<$max; $pi++): ?>
                            <span class="summary-pip" style="background:<?= $pi < $queue ? $sc['color'] : 'rgba(255,255,255,0.12)' ?>;"></span>
                        <?php endfor; ?>
                    </span>
                <?php elseif ($st === 'maintenance'): ?>
                    <span>🔧</span>
                <?php elseif ($st === 'closed'): ?>
                    <span>✗</span>
                <?php else: ?>
                    <span><?= $sc['icon'] ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Queue header (per-court) -->
        <div class="queue-header">
            <h2><?= $logoMd ?> <span id="queue-header-title">Queue</span></h2>
            <div class="court-name-label" id="queue-court-label">Loading…</div>
        </div>

        <!-- Active game strip (per-court) -->
        <div id="active-game-strip-wrap"></div>

        <!-- Queue slots -->
        <div class="queue-slots-list" id="queue-slots-list">
            <?php for ($i = 0; $i < PLAYERS_PER_GAME; $i++): ?>
                <div class="queue-slot" id="slot-<?= $i ?>">
                    <div class="slot-num"><?= $i + 1 ?></div>
                    <div class="slot-info"><div class="slot-empty">Loading…</div></div>
                </div>
            <?php endfor; ?>
        </div>

        <div class="queue-progress">
            <div class="queue-progress-fill" id="queue-bar" style="width:0%"></div>
        </div>
        <div class="queue-count-label" id="queue-count-label">— / <?= PLAYERS_PER_GAME ?> players in queue</div>

        <div class="stats-strip">
            <div class="stat-mini">
                <div class="val" id="stat-games"><?= $todayGames ?></div>
                <div class="lbl">Games Today</div>
            </div>
            <div class="stat-mini">
                <div class="val" id="stat-players"><?= $todayPlayers ?></div>
                <div class="lbl">Players Today</div>
            </div>
        </div>
    </aside>
</div>

<script nonce="<?= getCspNonce() ?>">
if (typeof APP_URL === 'undefined') window.APP_URL = '<?= APP_URL ?>';
const PLAYERS_PER_GAME = <?= PLAYERS_PER_GAME ?>;

// ── Courts data from PHP ──────────────────────────────────────
const ALL_COURTS = <?= json_encode(array_map(fn($c) => [
    'id'         => (int)$c['id'],
    'name'       => $c['name']       ?? 'Court',
    'short_code' => $c['short_code'] ?? '',
    'color'      => $c['color']      ?? '#00e5a0',
    'court_type' => $c['court_type'] ?? '',
    'live_status'=> $c['live_status'] ?? 'available',
    'max_queue'  => (int)($c['max_queue'] ?? PLAYERS_PER_GAME),
], $allCourts)) ?>;

const STATUS_COLORS = <?= json_encode($statusColors) ?>;
const TYPE_ICONS    = <?= json_encode($typeIcons) ?>;

// ── State ─────────────────────────────────────────────────────
let activeCourtId      = <?= $defaultCourtId ?>;
let lastScanCourtId    = null;
let allCourtsData      = {};    // populated by refreshQueue()
let courtTimerIntervals= {};   // per-court game countdown

// ── Logo / icon switching ─────────────────────────────────────
const scanIconLogo = document.getElementById('scan-icon-logo');
const scanIconEl   = document.getElementById('scan-icon');
function setIconEmoji(emoji) { scanIconLogo.style.display='none'; scanIconEl.style.display='block'; scanIconEl.textContent=emoji; }
function setIconLogo()       { scanIconEl.style.display='none'; scanIconLogo.style.display='block'; }

// ── FOCUS MANAGEMENT ──────────────────────────────────────────
const scannerInput = document.getElementById('scanner-input');
const focusEl      = document.getElementById('focus-indicator');
const focusLabel   = document.getElementById('focus-label');
let   navIsOpen    = false;

function forceFocus() {
    if (navIsOpen) return;
    scannerInput.removeAttribute('disabled');
    scannerInput.value = '';
    try { scannerInput.focus({ preventScroll: true }); } catch(e) {}
}
function updateFocusIndicator() {
    const active = document.activeElement === scannerInput;
    focusEl.className = active ? 'focused' : 'unfocused';
    focusLabel.textContent = active ? 'Scanner Ready' : '⚠️ Click here to re-arm scanner';
}
setInterval(() => {
    updateFocusIndicator();
    if (!navIsOpen && document.activeElement !== scannerInput) forceFocus();
}, 500);
window.addEventListener('load', forceFocus);
window.addEventListener('focus', forceFocus);
document.addEventListener('visibilitychange', () => { if (!document.hidden) forceFocus(); });
document.addEventListener('keydown', e => { if (e.key === 'F2') { e.preventDefault(); forceFocus(); } });

// ── COURT TABS ────────────────────────────────────────────────
document.getElementById('court-tab-strip').addEventListener('click', e => {
    const tab = e.target.closest('.court-tab');
    if (!tab) return;
    const cid = parseInt(tab.dataset.courtId, 10);
    switchCourtTab(cid);
});

function switchCourtTab(courtId) {
    activeCourtId = courtId;
    document.querySelectorAll('.court-tab').forEach(t => {
        const isActive = parseInt(t.dataset.courtId, 10) === courtId;
        t.classList.toggle('active', isActive);
        if (isActive) {
            const color = t.dataset.courtColor || 'var(--accent)';
            t.style.borderBottomColor = color;
            t.style.color = color;
            t.style.background = hexToRgba(color, 0.07);
        } else {
            t.style.borderBottomColor = 'transparent';
            t.style.color = '';
            t.style.background = '';
        }
    });
    renderCourtQueue(courtId);
}

function hexToRgba(hex, alpha) {
    if (!hex || !hex.startsWith('#')) return `rgba(0,229,160,${alpha})`;
    const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${alpha})`;
}

// ── SCANNER BUFFER ────────────────────────────────────────────
let buffer       = '';
let bufTimer     = null;
let lastToken    = '';
let lastScanTime = 0;
const DEDUP_MS   = 500;

scannerInput.addEventListener('keydown', function(e) {
    if (navIsOpen) return;
    if (e.key === 'Enter') {
        e.preventDefault();
        const token = buffer.trim(); buffer = ''; clearTimeout(bufTimer); this.value = '';
        if (token.length >= 5) fireToken(token); return;
    }
    if (e.key.length === 1) {
        buffer += e.key; clearTimeout(bufTimer);
        bufTimer = setTimeout(() => { const t=buffer.trim(); buffer=''; scannerInput.value=''; if(t.length>=5) fireToken(t); }, 500);
    }
});
scannerInput.addEventListener('input', function() {
    const v = this.value.trim();
    if (v.length >= 5) {
        clearTimeout(bufTimer);
        bufTimer = setTimeout(() => { const t=this.value.trim(); this.value=''; buffer=''; if(t.length>=5) fireToken(t); }, 300);
    }
});
function fireToken(token) {
    const now = Date.now();
    if (token === lastToken && (now - lastScanTime) < DEDUP_MS) return;
    lastToken=token; lastScanTime=now; processScan(token);
}

// ── CLOCK ─────────────────────────────────────────────────────
function updateClock() {
    const now = new Date();
    const clockEl = document.getElementById('clock');
    const dateEl  = document.getElementById('dateline');
    if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-PH',{hour12:true,hour:'2-digit',minute:'2-digit',second:'2-digit'});
    if (dateEl)  dateEl.textContent  = now.toLocaleDateString('en-PH',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
}
setInterval(updateClock, 1000); updateClock();

// ── RESERVATION COUNTDOWN ─────────────────────────────────────
let resCountdownTimer   = null;
let extWarningFired     = false;
let extPollInterval     = null;
let currentSlotDate     = null;
let currentSlotEnd24    = null;
let currentCourtId      = null;
let extensionUrl        = APP_URL + '/player/schedule.php';
let nextSlotState       = null;

function startResCountdown(secsRemaining) {
    clearInterval(resCountdownTimer);
    let secs = secsRemaining;
    const el = document.getElementById('rt-countdown');
    function tick() {
        if (!el) return;
        const h=Math.floor(secs/3600),m=Math.floor((secs%3600)/60),s=secs%60;
        el.textContent = h>0?`${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`:`${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        if(secs<=300) el.style.color='var(--warn)';
        if(secs<=60)  el.style.color='var(--danger)';
        if(secs<=900&&secs>0&&!extWarningFired){extWarningFired=true;startNextSlotPolling();document.getElementById('rt-ext-strip').style.display='block';}
        const mEl=document.getElementById('ext-modal-time');
        if(mEl&&document.getElementById('ext-modal').classList.contains('open')){mEl.textContent=fmtCountdown(secs);mEl.style.color=secs<=300?'var(--danger)':'var(--warn)';}
        if(secs>0){secs--;resCountdownTimer=setTimeout(tick,1000);}else{el.textContent='00:00';stopNextSlotPolling();}
    }
    tick();
}
function fmtCountdown(secs){const h=Math.floor(secs/3600),m=Math.floor((secs%3600)/60),s=secs%60;return h>0?`${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`:`${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;}
function startNextSlotPolling(){stopNextSlotPolling();pollNextSlot();extPollInterval=setInterval(pollNextSlot,30000);}
function stopNextSlotPolling(){if(extPollInterval){clearInterval(extPollInterval);extPollInterval=null;}}
function pollNextSlot(){
    if(!currentCourtId||!currentSlotDate||!currentSlotEnd24) return;
    const url=`${APP_URL}/court/api/check_next_slot.php?court_id=${encodeURIComponent(currentCourtId)}&slot_date=${encodeURIComponent(currentSlotDate)}&slot_end=${encodeURIComponent(currentSlotEnd24)}`;
    const dot=document.getElementById('ext-poll-dot'),label=document.getElementById('ext-poll-label');
    if(dot) dot.classList.add('active');
    if(label) label.textContent='Checking next slot…';
    fetch(url,{credentials:'same-origin'}).then(r=>r.json()).then(data=>{nextSlotState=data;if(dot) dot.classList.remove('active');if(label) label.textContent='Next slot status updated just now';if(document.getElementById('ext-modal').classList.contains('open')) renderExtModal(nextSlotState);}).catch(()=>{if(label) label.textContent='Could not reach server — using last known state';});
}
function renderExtModal(slot){
    if(!slot) return;
    const box=document.getElementById('ext-modal-box'),icon=document.getElementById('ext-icon'),title=document.getElementById('ext-title'),slotBox=document.getElementById('ext-slot-box'),slotTime=document.getElementById('ext-slot-time'),spotsEl=document.getElementById('ext-spots-left'),bookedEl=document.getElementById('ext-booked-by'),descEl=document.getElementById('ext-desc'),btnsEl=document.getElementById('ext-btns');
    if(slot.available){box.className='ext-modal-box state-available';icon.textContent='⏰';title.textContent='Time Almost Up!';title.style.color='var(--warn)';slotBox.className='ext-next-slot-box available';slotTime.className='ext-next-slot-time available';slotTime.textContent=slot.start_fmt+' – '+slot.end_fmt;spotsEl.style.display='block';spotsEl.textContent=slot.spots_left+' spot'+(slot.spots_left!==1?'s':'')+' left in next slot';bookedEl.style.display='none';descEl.innerHTML='The next slot is <strong style="color:var(--accent);">open</strong>. Head to the schedule page to book it and keep playing!';btnsEl.innerHTML=`<button class="btn-extend" onclick="goExtend()">Book Next Slot</button><button class="btn-dismiss" onclick="dismissExtModal()">Maybe Later</button>`;}else{box.className='ext-modal-box state-unavailable';icon.textContent='🚫';title.textContent='Next Slot Taken';title.style.color='var(--danger)';slotBox.className='ext-next-slot-box unavailable';slotTime.className='ext-next-slot-time unavailable';slotTime.textContent=slot.start_fmt+' – '+slot.end_fmt;spotsEl.style.display='none';if(slot.blocked_reason){bookedEl.style.display='block';bookedEl.innerHTML='<span style="color:var(--danger);">⛔</span> '+escHtml(slot.blocked_reason);}else{bookedEl.style.display='none';}descEl.innerHTML='The next slot is <strong style="color:var(--danger);">not available</strong>. You can check other open slots on the schedule page, or finish your current session.';btnsEl.innerHTML=`<button class="btn-extend" style="background:var(--accent2);" onclick="goExtend()">📅 View Other Slots</button><button class="btn-ok" onclick="dismissExtModal()">OK, Got It</button>`;}
}
function openExtModal(){document.getElementById('rt-ext-strip').style.display='block';if(nextSlotState){renderExtModal(nextSlotState);}else{document.getElementById('ext-poll-label').textContent='Checking next slot availability…';document.getElementById('ext-btns').innerHTML='<button class="btn-dismiss" onclick="dismissExtModal()">Close</button>';}document.getElementById('ext-modal').classList.add('open');}
function dismissExtModal(){document.getElementById('ext-modal').classList.remove('open');}
function goExtend(){document.getElementById('ext-modal').classList.remove('open');window.location.href=extensionUrl;}

// ── SCAN PROCESSOR ────────────────────────────────────────────
function processScan(token) {
    showState('idle');
    fetch(`${APP_URL}/court/process_scan.php`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ token })
    })
    .then(r => r.text().then(text => {
        try { return JSON.parse(text); }
        catch(e) { console.error('[scanner] JSON parse failed:', text); throw new Error('bad_json'); }
    }))
    .then(data => {
        if (data.code === 'unauthorized') {
            showError({ message: 'Scanner session expired. Please log in again.' });
            flashBorder('error'); setTimeout(() => { window.location.href = APP_URL + '/auth/login.php'; }, 3000); return;
        }
        if (data.code === 'payment_pending_verification') {
            handlePaymentPending(data); flashBorder('warn'); playBeep('warn'); setTimeout(resetIdle, 8000); return;
        }
        if (data.is_reservation) {
            handleReservationScan(data);
        } else if (data.status === 'success') {
            if (data.game_started) {
                showGameStarted(data); flashBorder('success'); playBeep('success');
                setTimeout(() => playBeep('success'), 320); setTimeout(resetIdle, 6000);
            } else {
                showSuccess(data); flashBorder('success'); playBeep('success');
                // Auto-switch tab to the court this player was assigned to
                if (data.court_id) {
                    lastScanCourtId = data.court_id;
                    switchCourtTab(data.court_id);
                }
                setTimeout(resetIdle, 4000);
            }
        } else if (data.status === 'warn') {
            showWarn(data); flashBorder('warn'); playBeep('warn'); setTimeout(resetIdle, 4000);
        } else {
            showError(data); flashBorder('error'); playBeep('error'); setTimeout(resetIdle, 4000);
        }
        refreshQueue();
    })
    .catch(err => {
        console.error('[scanner] fetch/parse error:', err);
        showError({ message: 'Connection error. Please try again.' });
        flashBorder('error'); setTimeout(resetIdle, 3000);
    });
}

function handlePaymentPending(data) {
    clearInterval(resCountdownTimer); stopNextSlotPolling();
    showState('payment-pending'); setIconEmoji('⏳');
    document.getElementById('scan-title').textContent    = 'PAYMENT PENDING';
    document.getElementById('scan-subtitle').textContent = data.message || 'Please wait while admin verifies your payment.';
    document.getElementById('scan-instruction').style.display = 'none';
    document.getElementById('player-card').classList.remove('visible');
    document.getElementById('res-timer-card').classList.remove('visible');
    document.getElementById('payment-pending-card').classList.add('visible');
    document.getElementById('pp-name').textContent = data.player?.full_name || '';
}

function handleReservationScan(data) {
    clearInterval(resCountdownTimer); stopNextSlotPolling();
    extWarningFired=false; nextSlotState=null;
    currentCourtId=data.court_id||null; currentSlotDate=data.slot_date||null; currentSlotEnd24=data.next_slot_start||null;
    if(data.next_slot_available!==undefined){nextSlotState={available:data.next_slot_available,start:data.next_slot_start||'',end:data.next_slot_end||'',start_fmt:data.next_slot_start_fmt||'',end_fmt:data.next_slot_end_fmt||'',spots_left:data.next_slot_spots_left||0,booked_by:data.next_slot_booked_by||null,blocked_reason:data.extension_blocked_reason||null};}
    extensionUrl=data.extension_url||(APP_URL+'/player/schedule.php');
    const isLate=data.is_late, isPaidOnline=data.is_online_paid, warnExt=data.warn_extension;
    showState(isLate?'late':'reservation'); setIconEmoji(isLate?'⚠️':(isPaidOnline?'💳':'📅'));
    document.getElementById('scan-title').textContent    = isLate?'LATE ARRIVAL':'RESERVATION ACTIVE';
    document.getElementById('scan-subtitle').textContent = isLate?`Session ends at ${data.slot_end} — ${data.time_remaining} remaining`:`Reserved session started — ends at ${data.slot_end}`;
    document.getElementById('scan-instruction').style.display='none';
    document.getElementById('player-card').classList.remove('visible');
    document.getElementById('payment-pending-card').classList.remove('visible');
    const rtCard=document.getElementById('res-timer-card');
    rtCard.className='res-timer-card visible'+(isLate?' late':'');
    document.getElementById('rt-name').textContent=data.player?.full_name||'';
    document.getElementById('rt-end-time').textContent=`Session ends at ${data.slot_end}`;
    document.getElementById('rt-countdown').className='res-countdown'+(isLate?' late-color':'');
    if(isLate){const lb=document.getElementById('rt-late-box');lb.style.display='block';lb.innerHTML=`⚠️ You arrived <strong>${data.late_minutes} minute${data.late_minutes!==1?'s':''}</strong> late. Time remaining: <strong>${data.time_remaining}</strong>.`;}
    document.getElementById('rt-paid-badge').style.display=isPaidOnline?'inline-flex':'none';
    if(warnExt){extWarningFired=true;startNextSlotPolling();if(nextSlotState)renderExtModal(nextSlotState);document.getElementById('rt-ext-strip').style.display='block';setTimeout(()=>openExtModal(),1500);}else{document.getElementById('rt-ext-strip').style.display='none';}
    startResCountdown(data.secs_remaining||0);
    flashBorder(isLate?'warn':'reservation'); playBeep(isLate?'warn':'success');
    setTimeout(resetIdle, 60000);
}

// ── UI STATE HELPERS ──────────────────────────────────────────
const panel    = document.getElementById('scan-panel');
const titleEl  = document.getElementById('scan-title');
const subtitle = document.getElementById('scan-subtitle');
const pCard    = document.getElementById('player-card');
const instrBox = document.getElementById('scan-instruction');
const rtCard   = document.getElementById('res-timer-card');
const ppCard   = document.getElementById('payment-pending-card');
const cdbBox   = document.getElementById('court-dest-box');

function showState(s) { panel.className = 'scan-panel state-' + s; }

function showCourtDest(data) {
    if (!data.court_id) { cdbBox.classList.remove('visible'); return; }
    const court = ALL_COURTS.find(c => c.id === data.court_id);
    const color = data.court_color || (court ? court.color : '#00e5a0');
    const name  = data.court_name  || (court ? court.name  : 'Court');
    const code  = data.court_short_code || (court ? court.short_code : '');
    const type  = court ? (TYPE_ICONS[court.court_type] || '') + ' ' + (court.court_type || '') : '';
    cdbBox.style.background   = hexToRgba(color, 0.15);
    cdbBox.style.border       = `2px solid ${color}`;
    cdbBox.style.color        = color;
    document.getElementById('cdb-name').textContent = '→ PROCEED TO ' + name.toUpperCase();
    document.getElementById('cdb-code').textContent = code;
    document.getElementById('cdb-type').textContent = type;
    cdbBox.classList.add('visible');
}

function showSuccess(data) {
    showState('success'); setIconEmoji('✅');
    titleEl.textContent    = 'ACCESS GRANTED';
    subtitle.textContent   = data.message || 'Player added to queue!';
    instrBox.style.display = 'none'; rtCard.classList.remove('visible'); ppCard.classList.remove('visible');
    showCourtDest(data);
    if (data.player) {
        document.getElementById('pc-name').textContent  = data.player.full_name;
        document.getElementById('pc-meta').textContent  = '@' + data.player.username;
        document.getElementById('pc-balance').innerHTML = 'Pass: <span style="color:var(--accent)">' + (data.pass_time_left || '—') + '</span>';
        document.getElementById('pc-qpos').textContent  = '#' + data.queue_position;
        pCard.classList.add('visible');
    }
}

function showError(data) {
    showState('error'); setIconEmoji('❌');
    titleEl.textContent    = 'ACCESS DENIED';
    subtitle.textContent   = data.message || 'Scan failed.';
    instrBox.style.display = 'none'; cdbBox.classList.remove('visible');
    pCard.classList.remove('visible'); rtCard.classList.remove('visible'); ppCard.classList.remove('visible');
}

function showWarn(data) {
    showState('warn'); setIconEmoji('⚠️');
    titleEl.textContent    = 'ALREADY QUEUED';
    subtitle.textContent   = data.message || 'Already in queue.';
    instrBox.style.display = 'none'; cdbBox.classList.remove('visible');
    pCard.classList.remove('visible'); rtCard.classList.remove('visible'); ppCard.classList.remove('visible');
}

function showGameStarted(data) {
    showState('success'); setIconEmoji('🚀');
    titleEl.textContent    = 'GAME STARTED!';
    subtitle.textContent   = data.game_message || 'All players locked in!';
    instrBox.style.display = 'none'; rtCard.classList.remove('visible'); ppCard.classList.remove('visible');
    showCourtDest(data);
    if (data.player) {
        document.getElementById('pc-name').textContent  = data.player.full_name;
        document.getElementById('pc-meta').textContent  = '@' + data.player.username + ' — last player!';
        document.getElementById('pc-balance').innerHTML = '🎮 Game started!';
        document.getElementById('pc-qpos').textContent  = '🚀';
        pCard.classList.add('visible');
    }
}

function resetIdle() {
    showState('idle'); setIconLogo();
    titleEl.textContent    = 'SCAN YOUR QR CODE';
    subtitle.textContent   = 'Open your app and hold your QR or barcode up to the scanner';
    instrBox.style.display = 'flex';
    cdbBox.classList.remove('visible');
    pCard.classList.remove('visible'); rtCard.classList.remove('visible'); ppCard.classList.remove('visible');
    clearInterval(resCountdownTimer); stopNextSlotPolling();
    extWarningFired=false; nextSlotState=null; currentSlotDate=null; currentSlotEnd24=null; currentCourtId=null;
    lastToken=''; lastScanTime=0; buffer='';
    document.getElementById('rt-late-box').style.display='none';
    document.getElementById('rt-ext-strip').style.display='none';
    document.getElementById('rt-paid-badge').style.display='none';
    document.getElementById('ext-modal').classList.remove('open');
    scannerInput.value=''; forceFocus();
}

// ── BORDER FLASH + AUDIO ──────────────────────────────────────
const borderEl = document.getElementById('scan-border');
function flashBorder(type) { borderEl.className='scan-border flash-'+type; setTimeout(()=>{borderEl.className='scan-border';},700); }
let audioCtx=null;
function getAudio(){if(!audioCtx)audioCtx=new(window.AudioContext||window.webkitAudioContext)();return audioCtx;}
function playBeep(type){try{const ctx=getAudio();const osc=ctx.createOscillator();const gain=ctx.createGain();osc.connect(gain);gain.connect(ctx.destination);if(type==='success'){osc.frequency.setValueAtTime(880,ctx.currentTime);osc.frequency.setValueAtTime(1100,ctx.currentTime+0.1);gain.gain.setValueAtTime(0.3,ctx.currentTime);gain.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.35);osc.start();osc.stop(ctx.currentTime+0.35);}else if(type==='error'){osc.type='sawtooth';osc.frequency.setValueAtTime(220,ctx.currentTime);gain.gain.setValueAtTime(0.3,ctx.currentTime);gain.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.4);osc.start();osc.stop(ctx.currentTime+0.4);}else{osc.frequency.setValueAtTime(660,ctx.currentTime);gain.gain.setValueAtTime(0.2,ctx.currentTime);gain.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.25);osc.start();osc.stop(ctx.currentTime+0.25);}}catch(e){}}

// ── QUEUE REFRESH — multi-court ───────────────────────────────
function renderCourtQueue(courtId) {
    const data = allCourtsData[courtId];
    const court = ALL_COURTS.find(c => c.id === courtId);
    const color = court ? court.color : 'var(--accent)';
    const typeIcon = court ? (TYPE_ICONS[court.court_type] || '') : '';

    // Header
    document.getElementById('queue-header-title').textContent = 'Queue — ' + (court ? court.short_code : '');
    document.getElementById('queue-court-label').textContent  = court ? (typeIcon + ' ' + court.name) : 'Loading…';

    if (!data) {
        // Show skeleton while loading
        const list = document.getElementById('queue-slots-list');
        list.innerHTML = Array.from({length: PLAYERS_PER_GAME}, (_,i) =>
            `<div class="queue-slot" id="slot-${i}"><div class="slot-num">${i+1}</div><div class="slot-info"><div class="slot-empty">Loading…</div></div></div>`
        ).join('');
        return;
    }

    const queue = data.queue || [];
    const max   = PLAYERS_PER_GAME;

    // Active game strip
    const agWrap = document.getElementById('active-game-strip-wrap');
    if (data.active_game) {
        const ag       = data.active_game;
        const isRes    = ag.is_reservation;
        const agClass  = isRes ? 'reservation' : 'open-play';
        const agColor  = isRes ? 'var(--accent2)' : 'var(--success)';
        const agLabel  = isRes ? '📅 Reserved Session' : '🎮 Game In Progress';
        const timerKey = 'timer-court-' + courtId;
        // Clear old interval
        if (courtTimerIntervals[courtId]) clearInterval(courtTimerIntervals[courtId]);
        let remSecs = ag.remaining_secs || 0;
        const timerId = 'ag-timer-' + courtId;
        agWrap.innerHTML = `
            <div class="active-game-strip ${agClass}" style="margin:10px 14px 0;">
                <div class="ag-label" style="color:${agColor};">${agLabel}</div>
                <div class="ag-timer" id="${timerId}" style="color:${agColor};">${fmtSecs(remSecs)}</div>
                <div class="ag-sub">Time remaining</div>
            </div>`;
        courtTimerIntervals[courtId] = setInterval(() => {
            if (remSecs <= 0) { clearInterval(courtTimerIntervals[courtId]); return; }
            remSecs--;
            const el = document.getElementById(timerId);
            if (el) {
                el.textContent = fmtSecs(remSecs);
                el.style.color = remSecs<=60 ? 'var(--danger)' : remSecs<=300 ? 'var(--warn)' : agColor;
            }
        }, 1000);
    } else {
        if (courtTimerIntervals[courtId]) { clearInterval(courtTimerIntervals[courtId]); delete courtTimerIntervals[courtId]; }
        agWrap.innerHTML = '';
    }

    // Slots
    const list = document.getElementById('queue-slots-list');
    let html = '';
    for (let i = 0; i < max; i++) {
        const p = queue[i] || null;
        html += `<div class="queue-slot ${p?'filled':''}" id="slot-${i}">
            <div class="slot-num" style="${p?`color:${color};`:''}\">${i+1}</div>
            <div class="slot-info">
                ${p ? `<div class="slot-name">${escHtml(p.full_name)}</div><div class="slot-time">@${escHtml(p.username)}</div>`
                    : `<div class="slot-empty">Waiting for player…</div>`}
            </div>${p?'<div>✅</div>':''}</div>`;
    }
    list.innerHTML = html;

    const count = queue.length;
    document.getElementById('queue-bar').style.width = (count / max * 100) + '%';
    document.getElementById('queue-count-label').textContent = `${count} / ${max} players in queue`;

    // Stats (totals across all courts)
    let totalGames=0, totalPlayers=0;
    Object.values(allCourtsData).forEach(d => {
        totalGames   += (d.today_games   || 0);
        totalPlayers += (d.today_players || 0);
    });
    document.getElementById('stat-games').textContent   = totalGames;
    document.getElementById('stat-players').textContent = totalPlayers;
}

function fmtSecs(s) {
    s = Math.max(0, s);
    const h=Math.floor(s/3600), m=Math.floor((s%3600)/60), sec=s%60;
    return h>0 ? `${h}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}` : `${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
}

function refreshQueue() {
    // Fetch all courts (no param) from the updated queue_status.php
    fetch(`${APP_URL}/court/queue_status.php`)
        .then(r => r.json())
        .then(data => {
            // Handle both old (single) and new (multi-court) response formats
            if (data.courts) {
                // New multi-court format
                data.courts.forEach(c => {
                    allCourtsData[c.court_id] = c;
                });
                updateSummaryStrip(data.courts);
                updateTabDots(data.courts);
            } else if (data.queue !== undefined) {
                // Fallback: old single-court format — shim into first court slot
                const fid = ALL_COURTS[0]?.id || 1;
                allCourtsData[fid] = {
                    court_id:     fid,
                    queue:        data.queue        || [],
                    active_game:  data.active_game  || null,
                    today_games:  data.today_games  || 0,
                    today_players:data.today_players|| 0,
                };
            }
            renderCourtQueue(activeCourtId);
        })
        .catch(() => {});
}

function updateTabDots(courts) {
    courts.forEach(c => {
        const tab = document.getElementById('tab-' + c.court_id);
        if (!tab) return;
        const st  = c.live_status || 'available';
        const sc  = STATUS_COLORS[st] || STATUS_COLORS['available'];
        const dot = tab.querySelector('.tab-dot');
        const ico = tab.querySelector('.tab-status-icon');
        if (dot) dot.style.background = sc.color;
        if (ico) ico.textContent      = sc.icon;
    });
}

function updateSummaryStrip(courts) {
    const strip = document.getElementById('courts-summary-strip');
    let html = '';
    courts.forEach(c => {
        const st   = c.live_status || 'available';
        const sc   = STATUS_COLORS[st] || STATUS_COLORS['available'];
        const max  = PLAYERS_PER_GAME;
        const fill = st==='active' ? (c.players_on_court||0) : (c.queue_count||0);
        let pips   = '';
        if (['active','queuing'].includes(st)) {
            pips = `<span style="display:flex;gap:3px;align-items:center;">`;
            for (let p=0;p<max;p++) pips += `<span style="width:7px;height:7px;border-radius:50%;background:${p<fill?sc.color:'rgba(255,255,255,0.12)'};display:inline-block;"></span>`;
            pips += `</span>`;
        }
        html += `<div class="summary-pill" style="background:rgba(0,0,0,0.3);border-color:${sc.color}33;color:${sc.color};">
            <span>${escHtml(c.short_code || '')}</span>
            ${pips}
            <span>${sc.icon}</span>
        </div>`;
    });
    strip.innerHTML = html;
}

// Initial tab styling and load
switchCourtTab(activeCourtId);
setInterval(refreshQueue, 5000);
refreshQueue();

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
document.addEventListener('contextmenu', e => e.preventDefault());
</script>
</body>
</html>