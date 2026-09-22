<?php
// ============================================================
//  FILE: staff/open_play_control.php
//  Staff console for Open Play (skill-balanced random pairing)
//  events — sibling to staff/tournament_queue.php (which handles
//  bracket tournaments) but for the live, round-by-round format.
//
//  Event creation / roster management use classic form POSTs
//  (same pattern as tournament_admin.php / tournament_queue.php).
//  The live parts — drawing a round, starting/pausing/finishing
//  a match — use small fetch() calls against api/open_play.php
//  so the "spin the wheel" reveal can animate and match cards can
//  update without a full page reload.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';
requireStaff();

$engine = new OpenPlayEngine();
$user   = currentUser();

// ── Handle classic-form actions (event create + roster) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['tournament_id'] ?? 0);

    try {
        switch ($action) {
            case 'add_player':
                $playerId = (int)($_POST['player_id'] ?? 0);
                $skillLevel = $_POST['skill_level'] ?? 'average';
                if ($playerId > 0) {
                    $engine->addPlayerByStaff($tid, $playerId, $skillLevel, (int)$user['id']);
                } else {
                    $engine->addGuestByStaff($tid, (string)($_POST['player_name_lookup'] ?? ''), $skillLevel, (int)$user['id']);
                }
                setFlash('success', 'Player added to the pool.');
                break;

            case 'queue_status':
                $engine->setQueueStatus($tid, (int)$_POST['player_id'], $_POST['status'], (int)$user['id']);
                setFlash('success', 'Updated.');
                break;

            case 'approve_join':
                $engine->approveJoin($tid, (int)$_POST['player_id'], (int)$user['id']);
                setFlash('success', 'Join request approved.');
                break;

            case 'reject_join':
                $engine->rejectJoin($tid, (int)$_POST['player_id'], (int)$user['id']);
                setFlash('success', 'Join request rejected.');
                break;

            case 'remove_player':
                $engine->leaveEvent($tid, (int)$_POST['player_id']);
                setFlash('success', 'Player removed.');
                break;

            case 'cancel_event':
                $engine->cancelEvent($tid, (int)$user['id']);
                setFlash('success', 'Event cancelled.');
                redirect('staff/open_play_control.php');
                break;

            case 'close_tonight':
                $engine->closeTonight($tid, (int)$user['id']);
                setFlash('success', 'Tonight was closed and the schedule has been disabled for this date onward.');
                redirect('staff/open_play_control.php');
                break;

            case 'pause_event':
                $engine->pauseEvent($tid, (int)$user['id']);
                setFlash('success', '⏸️ Matchmaking paused — current games can finish.');
                break;

            case 'resume_event':
                $engine->resumeEvent($tid, (int)$user['id']);
                setFlash('success', '▶️ Matchmaking resumed.');
                break;

            case 'update_duration':
                $engine->updateEvent($tid, ['game_duration' => (int)$_POST['game_duration']], (int)$user['id']);
                setFlash('success', '⏱️ Game duration updated for the next round.');
                break;

            case 'finalize':
                $engine->finalizeEvent($tid, (int)$user['id']);
                setFlash('success', '🏆 Event finalized — leaderboard updated.');
                break;

            default:
                setFlash('error', 'Unknown action.');
        }
    } catch (Throwable $e) {
        setFlash('error', '⚠️ ' . $e->getMessage());
    }
    redirect('staff/open_play_control.php' . ($tid ? '?tournament_id=' . $tid : ''));
}

// ── Load data ───────────────────────────────────────────────
$events   = $engine->listEvents();
$selected = (int)($_GET['tournament_id'] ?? 0);
if (!$selected) {
    foreach ($events as $e) {
        if (in_array($e['status'], ['registration_open', 'in_progress', 'registration_closed', 'paused'], true)) { $selected = (int)$e['id']; break; }
    }
}
$event    = $selected ? $engine->getEvent($selected) : null;
$roster   = $event ? $engine->getRoster($selected) : [];
$standings= $event ? $engine->computeLeaderboard($selected) : [];
$ties     = $event ? $engine->detectPodiumTies($standings) : [];
$db       = getDB();
$searchablePlayers = $db->query(
    "SELECT id, COALESCE(display_name, full_name, username) AS name FROM falcon.users
    WHERE role = 'player' AND is_banned = FALSE AND is_guest = FALSE ORDER BY name LIMIT 500"
)->fetchAll();

// ── Extra data for the court-control layout ─────────────────
// Active courts (read-only here — enabling/disabling a court is a
// facility-wide setting that stays in admin/courts.php).
$courtRows = $db->query(
    "SELECT id, name, COALESCE(is_maintenance, FALSE) AS is_maintenance
       FROM falcon.courts WHERE is_active = TRUE ORDER BY id"
)->fetchAll();
$courtsForJs = array_map(fn($c) => [
    'id'    => (int)$c['id'],
    'name'  => (string)$c['name'],
    'maint' => in_array($c['is_maintenance'], [true, 't', '1', 1], true),
], $courtRows);

$isClosed = $event && in_array($event['status'], ['completed', 'cancelled'], true);

$recentFinished = [];
if ($event) {
    $stmt = $db->prepare(
        "SELECT m.id, m.score_team1, m.score_team2, m.finished_at, c.name AS court_name,
                COALESCE(u1.display_name, u1.full_name, u1.username) AS t1p1,
                COALESCE(u2.display_name, u2.full_name, u2.username) AS t1p2,
                COALESCE(u3.display_name, u3.full_name, u3.username) AS t2p1,
                COALESCE(u4.display_name, u4.full_name, u4.username) AS t2p2
           FROM falcon.open_play_matches m
           LEFT JOIN falcon.courts c ON c.id = m.court_id
           LEFT JOIN falcon.users u1 ON u1.id = m.team1_player1_id
           LEFT JOIN falcon.users u2 ON u2.id = m.team1_player2_id
           LEFT JOIN falcon.users u3 ON u3.id = m.team2_player1_id
           LEFT JOIN falcon.users u4 ON u4.id = m.team2_player2_id
          WHERE m.tournament_id = :tid AND m.status = 'finished'
          ORDER BY m.finished_at DESC LIMIT 10"
    );
    $stmt->execute([':tid' => $selected]);
    $recentFinished = $stmt->fetchAll();
}

// Roster-derived helpers: skill labels for the live court cards, names for
// the draw animation, and the counts shown in the header.
$skillById = [];
$allNames  = [];
$pendingCount   = 0;
$waitingInitial = 0;
foreach ($roster as $r) {
    if ($r['status'] === 'pending_approval') { $pendingCount++; continue; }
    $skillById[(int)$r['player_id']] = (string)$r['skill_level'];
    $allNames[] = (string)($r['display_name'] ?? $r['full_name'] ?? $r['username'] ?? 'Player');
    if ($r['queue_status'] === 'waiting') $waitingInitial++;
}
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

$statusLabels = [
    'registration_open'   => 'Registration open',
    'registration_closed' => 'Registration closed',
    'in_progress'         => 'In progress',
    'paused'              => 'Paused',
    'completed'           => 'Completed',
    'cancelled'           => 'Cancelled',
];
$statusPill = [
    'in_progress' => 'opc-pill-live', 'registration_open' => 'opc-pill-ball',
    'registration_closed' => 'opc-pill-ball', 'paused' => 'opc-pill-warn',
    'completed' => '', 'cancelled' => 'opc-pill-red',
];

$currentDuration = 900;
if ($event) {
    $eventSettings   = json_decode($event['settings'] ?? '{}', true) ?: [];
    $currentDuration = (int)($eventSettings['game_duration'] ?? 900);
}
$durationMinutes = [10, 15, 20, 25, 30, 45, 60];
if ($currentDuration % 60 === 0 && !in_array($currentDuration / 60, $durationMinutes, true)) {
    $durationMinutes[] = (int)($currentDuration / 60);
    sort($durationMinutes);
}

$pageTitle = 'Open Play Control';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&amp;display=swap"/>
<style nonce="<?= getCspNonce() ?>">
/* ============================================================
   Open Play — Staff Control
   Layout + look mirror the tournament app's Court Control page.
   Everything is scoped under .opc so nothing leaks into the shell.
   To re-theme, change the tokens on the next rule.
   ============================================================ */
.opc {
    --ball: #f2c94c; --ball-ink: #171717;
    --line: rgba(255,255,255,.10);
    --panel: rgba(255,255,255,.05); --panel-soft: rgba(255,255,255,.03);
    --t80: rgba(255,255,255,.80); --t60: rgba(255,255,255,.60);
    --t45: rgba(255,255,255,.45); --t30: rgba(255,255,255,.30);
    --display: 'Rajdhani', 'Bebas Neue', 'DM Sans', sans-serif;
    max-width: 72rem; margin: 0 auto; color: #fff;
    font-family: 'DM Sans', system-ui, sans-serif; color-scheme: dark;
}
.opc *, .opc *::before, .opc *::after { box-sizing: border-box; }
.opc [hidden] { display: none !important; }
.opc :focus-visible { outline: 2px solid var(--ball); outline-offset: 3px; }
.opc button:disabled { opacity: .5; cursor: not-allowed; }

/* header row */
.opc-head { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.opc-title { font-family: var(--display); font-size: 1.6rem; font-weight: 700; line-height: 1.15; margin: 0; color: #fff; }
.opc-head-right { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.opc-count { font-size: 14px; color: var(--t45); }
.opc-section-nav { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 20px; border-bottom:1px solid var(--line); padding-bottom:10px; }
.opc-section-nav a { color:var(--t60); border:1px solid var(--line); background:var(--panel-soft); border-radius:8px; padding:9px 14px; font-size:12px; text-decoration:none; }
.opc-section-nav a:hover { color:var(--ball-ink); background:var(--ball); border-color:var(--ball); }

/* buttons */
.opc-btn, .opc-btn-action, .opc-btn-danger {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    min-height: 38px; padding: 8px 14px; border-radius: 12px; border: 1px solid transparent;
    font: inherit; font-size: 14px; line-height: 1.2; cursor: pointer; text-decoration: none; transition: all .2s;
}
.opc-btn { border-color: var(--line); background: var(--panel-soft); color: var(--t80); font-weight: 500; }
.opc-btn:hover { border-color: rgba(255,255,255,.2); background: rgba(255,255,255,.06); color: #fff; }
.opc-btn-action { background: var(--ball); color: var(--ball-ink); font-weight: 700; box-shadow: 0 10px 24px rgba(242,201,76,.25); }
.opc-btn-action:hover { transform: translateY(-1px); filter: brightness(1.05); }
.opc-btn-danger { background: rgba(239,68,68,.8); color: #fff; font-weight: 600; }
.opc-btn-danger:hover { background: rgba(239,68,68,.95); }
.opc .opc-sm { min-height: 34px; padding: 6px 12px; }
.opc-link-btn { background: none; border: 0; color: var(--t45); font: inherit; font-size: 12px; cursor: pointer; margin-top: 10px; }
.opc-link-btn:hover { color: var(--t80); }

/* fields */
.opc select.opc-field, .opc input.opc-field, .opc textarea.opc-field {
    width: 100%; min-height: 38px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--line);
    background: rgba(255,255,255,.03); color: #fff; font: inherit; font-size: 14px;
    -webkit-appearance: auto; appearance: auto;
}
.opc select.opc-field option { background: #111; color: #fff; }
.opc select.opc-field:focus, .opc input.opc-field:focus { border-color: rgba(242,201,76,.6); box-shadow: 0 0 0 3px rgba(242,201,76,.10); outline: none; }
.opc .opc-auto { width: auto; }
.opc-label { display: block; margin-bottom: 6px; font-size: 10px; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; color: var(--t45); }

/* panels */
.opc-panel { border: 1px solid var(--line); background: var(--panel); border-radius: 16px; padding: 16px; }
.opc-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 24px; }
.opc-kicker { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--t45); margin-bottom: 6px; }
.opc-bar-main { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; min-width: 0; }
.opc-bar-main form { margin: 0; }
.opc-bar-main .opc-field { min-width: 220px; }
.opc-eventline { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 14px; }
.opc-bar-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.opc-bar-actions form { display: inline-flex; margin: 0; }
.opc-dur { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: var(--t45); }
.opc-dur select { width: auto !important; min-height: 34px !important; padding: 5px 10px !important; }

/* pills + dot */
.opc-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; border: 1px solid var(--line); padding: 2px 10px; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--t60); white-space: nowrap; }
.opc-pill-live { color: #6ee7b7; border-color: rgba(110,231,183,.35); background: rgba(110,231,183,.08); }
.opc-pill-ball { color: var(--ball); border-color: rgba(242,201,76,.35); background: rgba(242,201,76,.08); }
.opc-pill-warn { color: #fdba74; border-color: rgba(253,186,116,.35); background: rgba(251,146,60,.08); }
.opc-pill-red  { color: #fca5a5; border-color: rgba(252,165,165,.35); background: rgba(239,68,68,.08); }
.opc-dot { width: 8px; height: 8px; border-radius: 50%; background: #6ee7b7; animation: opc-pulse 2s ease-out infinite; }
@keyframes opc-pulse { 0% { box-shadow: 0 0 0 0 rgba(110,231,183,.55); } 70% { box-shadow: 0 0 0 7px rgba(110,231,183,0); } 100% { box-shadow: 0 0 0 0 rgba(110,231,183,0); } }

/* notices */
.opc-notice { border-radius: 16px; border: 1px solid rgba(147,197,253,.3); background: rgba(96,165,250,.10); color: #bfdbfe; padding: 12px 16px; font-size: 14px; margin-bottom: 16px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
.opc-notice a { color: inherit; font-weight: 600; }
.opc-notice-warn { border-color: rgba(253,186,116,.3); background: rgba(251,146,60,.10); color: #fed7aa; }

/* draw (bunot-bunot) panel */
.opc-draw { background: #0f4c3a; border: 4px solid rgba(245,241,232,.3); border-radius: 16px; padding: 32px; text-align: center; margin-bottom: 24px; }
.opc-draw-kicker { font-family: var(--display); color: var(--ball); font-size: 14px; letter-spacing: .3em; text-transform: uppercase; margin-bottom: 16px; }
.opc-draw-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); animation: opc-fade .4s ease; }
.opc-draw-row:last-of-type { border-bottom: 0; }
.opc-draw-court { flex-basis: 100%; font-size: 10px; letter-spacing: .2em; text-transform: uppercase; color: var(--t45); }
.opc-draw .opc-team { justify-content: center; gap: 4px 14px; font-family: var(--display); font-size: 1.1rem; }
.opc-draw .opc-chip { border: 0; padding: 0; }
.opc-draw .opc-skill { color: var(--t45) !important; }
.opc-slots { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; max-width: 28rem; margin: 16px auto 0; animation: opc-blink 1.2s ease-in-out infinite; }
.opc-slot { background: rgba(0,0,0,.3); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
.opc-tiny { font-size: 12px; color: rgba(255,255,255,.4); margin-bottom: 4px; }
.opc-slot-name { font-family: var(--display); font-size: 1.1rem; min-height: 1.5em; }
.opc-draw-msg { color: var(--t80); font-size: 14px; margin: 8px 0 0; }
@keyframes opc-fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@keyframes opc-blink { 50% { opacity: .6; } }

/* court grid */
.opc-courts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.opc-court { border: 1px solid var(--line); background: var(--panel); border-radius: 16px; padding: 16px; min-width: 0; }
.opc-court.is-off { border-color: rgba(255,255,255,.05); background: rgba(255,255,255,.02); opacity: .5; }
.opc-court-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
.opc-court-name { font-family: var(--display); font-size: 1.125rem; font-weight: 700; }
.opc-empty { color: var(--t30); font-size: 14px; text-align: center; padding: 24px 0; margin: 0; }
.opc-badge-tb { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: var(--ball); font-weight: 700; margin-bottom: 4px; }
.opc-teams { display: flex; justify-content: space-between; gap: 8px; font-size: 14px; margin-bottom: 8px; }
.opc-teams .opc-team { flex: 1 1 0; }
.opc-teams .opc-team:last-child { justify-content: flex-end; }
.opc-vs { color: var(--ball); font-weight: 700; align-self: center; }
.opc-team { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; min-width: 0; }
.opc-chip { display: inline-flex; flex-direction: column; line-height: 1.2; border: 1px solid var(--line); border-radius: 6px; padding: 4px 8px; max-width: 100%; }
.opc-chip-name { overflow-wrap: anywhere; }
.opc-skill { display: block; font-size: 9px; letter-spacing: .12em; text-transform: uppercase; }
.opc-skill-advance { color: #fca5a5; } .opc-skill-average { color: #fdba74; } .opc-skill-beginner { color: #93c5fd; }
.opc-timer { font-family: var(--display); font-size: 2.25rem; font-variant-numeric: tabular-nums; line-height: 1.1; text-align: center; margin-top: 12px; }
.opc-timer.is-zero { color: #fca5a5; }
.opc-status { font-size: 12px; letter-spacing: .05em; text-transform: uppercase; color: rgba(255,255,255,.4); text-align: center; }
.opc-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-top: 12px; }
.opc-act { border: 1px solid transparent; border-radius: 8px; padding: 6px 12px; font: inherit; font-size: 14px; font-weight: 600; color: #fff; cursor: pointer; }
.opc-act-green { background: rgba(34,197,94,.8); } .opc-act-yellow { background: rgba(234,179,8,.8); } .opc-act-red { background: rgba(239,68,68,.8); }
.opc-act-green:hover, .opc-act-yellow:hover, .opc-act-red:hover { filter: brightness(1.1); }
.opc-act-ghost { background: transparent; border-color: rgba(255,255,255,.15); color: var(--t60); font-weight: 400; }
.opc-act-ghost:hover { color: #fff; }
.opc .opc-xs { padding: 5px 10px; font-size: 12px; }
.opc-skeleton { height: 210px; border-radius: 16px; background: rgba(255,255,255,.07); animation: opc-blink 1.4s ease-in-out infinite; }

/* sections + lists */
.opc-section { margin-top: 32px; }
.opc-section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.opc-h3 { font-family: var(--display); font-size: 1.125rem; font-weight: 700; margin: 0; color: #fff; }
.opc-meta { font-size: 12px; text-transform: uppercase; letter-spacing: .18em; color: var(--t45); }
.opc-list { display: flex; flex-direction: column; gap: 8px; }
.opc-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; border: 1px solid var(--line); background: var(--panel-soft); border-radius: 16px; padding: 12px; font-size: 14px; }
.opc-row-empty { border: 1px dashed var(--line); background: rgba(255,255,255,.02); border-radius: 16px; padding: 12px; text-align: center; font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--t30); margin: 0; }
.opc-lineup { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; min-width: 0; }
.opc-fname { color: var(--t80); }
.opc-score { color: var(--ball); margin: 0 8px; font-weight: 700; }
.opc-sub { margin-top: 4px; font-size: 12px; color: rgba(255,255,255,.4); }
.opc-split { display: grid; grid-template-columns: 1fr; gap: 24px; margin-top: 32px; }
.opc-q { display: flex; align-items: center; justify-content: space-between; gap: 8px; border: 1px solid var(--line); background: var(--panel-soft); border-radius: 16px; padding: 10px; }
.opc-q-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
.opc-q-name { display: flex; flex-direction: column; line-height: 1.2; font-size: 14px; color: var(--t80); min-width: 0; overflow-wrap: anywhere; }
.opc-q-actions { display: flex; gap: 4px; flex-shrink: 0; }
.opc-q-actions form { margin: 0; }
.opc-idx { flex: none; display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: rgba(242,201,76,.10); color: var(--ball); font-size: 10px; font-weight: 700; }
.opc-mini { height: 28px; padding: 0 10px; border: 0; border-radius: 6px; background: rgba(255,255,255,.05); color: var(--t80); font: inherit; font-size: 11px; cursor: pointer; }
.opc-mini:hover { background: rgba(255,255,255,.10); }
.opc-mini-red { background: rgba(239,68,68,.15); color: #fca5a5; }
.opc-mini-red:hover { background: rgba(239,68,68,.25); }
.opc-mini-green { background: rgba(34,197,94,.18); color: #86efac; }

/* tables */
.opc-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.opc table.opc-table { width: 100%; min-width: 560px; border-collapse: collapse; }
.opc .opc-table thead { background: transparent; }
.opc .opc-table th { padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; color: var(--t45); background: transparent; }
.opc .opc-table td { padding: 10px 12px; font-size: 14px; color: var(--t80); border-top: 1px solid var(--line); vertical-align: middle; }
.opc .opc-table tr:hover td { background: rgba(255,255,255,.02); }
.opc .opc-table tr.is-pending td { background: rgba(251,146,60,.07); }
.opc-cell-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.opc-cell-actions form { margin: 0; }
.opc-link { color: var(--ball); }
.opc-add { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.opc-add .opc-field { flex: 1 1 200px; width: auto !important; }
.opc-add select.opc-field { flex: 0 0 auto; }
.opc-raffle-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 12px; }
.opc-help { font-size: 13px; color: var(--t45); margin: 0 0 12px; }

/* modal + toasts (live in #opcOverlay, moved to <body> by the script) */
.opc-overlay { position: static; height: 0; max-width: none; margin: 0; }
.opc-modal-shell { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; overflow-y: auto; background: rgba(0,0,0,.6); padding: 12px; }
.opc-modal-card { width: 100%; max-width: 24rem; max-height: calc(100dvh - 1.5rem); overflow-y: auto; overscroll-behavior: contain; border-radius: 16px; border: 1px solid var(--line); background: #0a0a0a; padding: 16px; }
.opc-modal-title { font-family: var(--display); font-size: 1.125rem; font-weight: 700; margin: 0 0 4px; color: #fff; }
.opc-modal-sub { font-size: 12px; color: rgba(255,255,255,.4); margin: 0 0 16px; }
.opc-two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.opc-two label { display: block; font-size: 12px; color: var(--t45); }
.opc .opc-two input.opc-field { margin-top: 4px; font-size: 20px; text-align: center; }
.opc-winner { text-align: left; border-radius: 12px; border: 1px solid var(--line); background: rgba(255,255,255,.05); color: #fff; padding: 14px; font: inherit; font-size: 14px; cursor: pointer; }
.opc-winner:hover:not(:disabled) { background: rgba(242,201,76,.10); border-color: var(--ball); }
.opc-winner:disabled { opacity: .4; cursor: not-allowed; }
.opc-msg { border-radius: 12px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; border: 1px solid rgba(253,186,116,.3); background: rgba(251,146,60,.10); color: #fed7aa; }
.opc-msg.is-error { border-color: rgba(252,165,165,.3); background: rgba(239,68,68,.10); color: #fecaca; }
.opc-modal-foot { display: flex; justify-content: flex-end; margin-top: 12px; }
.opc-toasts { position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 1100; display: flex; flex-direction: column; gap: 8px; width: min(92vw, 28rem); pointer-events: none; }
.opc-toast { background: #171717; border: 1px solid rgba(242,201,76,.4); color: #fff; border-radius: 12px; padding: 10px 14px; font-size: 14px; box-shadow: 0 12px 32px rgba(0,0,0,.5); animation: opc-fade .25s ease; }
.opc-toast.is-error { border-color: rgba(252,165,165,.5); color: #fecaca; }

@media (min-width: 640px) { .opc-modal-card { padding: 24px; } }
@media (min-width: 1100px) { .opc-split { grid-template-columns: 1.2fr .8fr; } }
@media (max-width: 768px) { .opc-courts { grid-template-columns: 1fr; } }
@media (max-width: 640px) {
    .opc-head-right { width: 100%; } .opc-head-right .opc-btn-action { flex: 1; }
    .opc-bar-actions { width: 100%; } .opc-bar-actions > *, .opc-bar-actions form { flex: 1 1 auto; } .opc-bar-actions form .opc-btn, .opc-bar-actions form .opc-btn-danger { width: 100%; }
    .opc-bar-main, .opc-bar-main form, .opc-bar-main .opc-field { width: 100%; }
    .opc-btn, .opc-btn-action, .opc-btn-danger { min-height: 42px; }
    .opc select.opc-field, .opc input.opc-field { font-size: 16px; }
    .opc-draw { padding: 20px; }
    .opc-teams { flex-direction: column; } .opc-teams .opc-team:last-child { justify-content: flex-start; }
}
@media (prefers-reduced-motion: reduce) { .opc *, .opc *::before, .opc *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; } }
</style>

<div class="opc" id="opcRoot">

    <!-- ── Header: title · waiting count · draw ── -->
    <div class="opc-head">
        <h2 class="opc-title">Open Play — Staff Control</h2>
        <div class="opc-head-right">
            <?php if ($event): ?>
                <span class="opc-count" id="waitingCount"><?= $waitingInitial ?> player<?= $waitingInitial === 1 ? '' : 's' ?> waiting</span>
                <?php if (!$isClosed): ?>
                    <button type="button" class="opc-btn-action" id="drawBtn">Spin / Bunot-Bunot Draw</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <nav class="opc-section-nav" aria-label="Open Play modules">
        <a href="<?= APP_URL ?>/staff/open_play_registration.php<?= $selected ? '?tournament_id=' . $selected : '' ?>">Registration &amp; Approvals</a>
        <a href="<?= APP_URL ?>/staff/open_play_leaderboard.php<?= $selected ? '?tournament_id=' . $selected : '' ?>">Standing</a>
        <a href="<?= APP_URL ?>/staff/open_play_raffles.php<?= $selected ? '?tournament_id=' . $selected : '' ?>">Raffles</a>
    </nav>

    <!-- ── Event + controls panel ── -->
    <div class="opc-panel opc-bar">
        <div class="opc-bar-main">
            <div>
                <div class="opc-kicker">Event</div>
                <form method="GET">
                    <select name="tournament_id" class="opc-field" data-autosubmit aria-label="Select event">
                        <option value="">— Select an event —</option>
                        <?php foreach ($events as $e): ?>
                            <option value="<?= (int)$e['id'] ?>" <?= $selected === (int)$e['id'] ? 'selected' : '' ?>>
                                <?= clean($e['name']) ?> (<?= clean($e['status']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php if ($event): ?>
            <div class="opc-eventline">
                <?php if ($event['status'] === 'in_progress'): ?><span class="opc-dot" title="Live"></span><?php endif; ?>
                <span class="opc-pill <?= $statusPill[$event['status']] ?? '' ?>"><?= clean($statusLabels[$event['status']] ?? $event['status']) ?></span>
                <span class="opc-count"><?= (int)round($currentDuration / 60) ?> min games</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="opc-bar-actions">
            <?php if ($event): ?>
                <a class="opc-btn opc-sm" href="<?= APP_URL ?>/staff/open_play_kiosk.php?tournament_id=<?= $selected ?>" target="_blank" rel="noopener">TV Kiosk</a>
            <?php endif; ?>
            <a class="opc-btn opc-sm" href="<?= APP_URL ?>/staff/open_play_settings.php<?= $selected ? '?tournament_id=' . $selected : '' ?>">Settings</a>

            <?php if ($event && !$isClosed): ?>
                <form method="POST" class="opc-dur">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_duration"/>
                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                    <label for="opcDuration">Game length</label>
                    <select id="opcDuration" name="game_duration" class="opc-field" data-autosubmit title="Applies to the next round drawn — games already on court keep their original timer.">
                        <?php foreach ($durationMinutes as $mins): ?>
                            <option value="<?= $mins * 60 ?>" <?= $currentDuration === $mins * 60 ? 'selected' : '' ?>><?= $mins ?> min</option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($event['status'] === 'paused'): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="resume_event"/>
                        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                        <button type="submit" class="opc-btn opc-sm">Resume matchmaking</button>
                    </form>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="pause_event"/>
                        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                        <button type="submit" class="opc-btn opc-sm">Pause matchmaking</button>
                    </form>
                <?php endif; ?>
                <form method="POST" data-confirm="Close tonight and disable the nightly schedule from this date onward?">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="close_tonight"/>
                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                    <button type="submit" class="opc-btn opc-sm">Close tonight</button>
                </form>
                <form method="POST" data-confirm="Finalize this event? This locks in placements and updates the season leaderboard.">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="finalize"/>
                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                    <button type="submit" class="opc-btn-danger opc-sm">Finalize now</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<?php if (!$event): ?>
    <p class="opc-row-empty">Select an open play event to get started — or create one in Settings.</p>
<?php else: ?>

    <?php if ($isClosed): ?>
    <div class="opc-notice <?= $event['status'] === 'cancelled' ? 'opc-notice-warn' : '' ?>">
        <span><?= $event['status'] === 'cancelled' ? 'This event was cancelled — it can no longer be edited or drawn into.' : 'This event has been finalized — see the results page for the final standings.' ?></span>
        <?php if ($event['status'] === 'completed'): ?>
            <a href="<?= APP_URL ?>/public/open_play_results.php?tournament_id=<?= $selected ?>">View results →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($pendingCount > 0 && !$isClosed): ?>
    <div class="opc-notice opc-notice-warn">
        <span><?= $pendingCount ?> player<?= $pendingCount === 1 ? '' : 's' ?> waiting for join approval.</span>
        <a class="opc-link-btn" href="<?= APP_URL ?>/staff/open_play_registration.php?tournament_id=<?= $selected ?>">Review requests ↓</a>
    </div>
    <?php endif; ?>

    <div class="opc-notice" role="note">
        <span><strong>Balanced draw rules:</strong> Beginner + Beginner plays only Beginner + Beginner · Beginner + Advance plays Beginner + Advance or Average + Average · Average + Average plays Average + Average or Beginner + Advance · Average + Beginner plays only Average + Beginner · Average + Advance plays only Average + Advance · Advance + Advance plays only Advance + Advance.</span>
        <span class="opc-sub">Players rotate by waiting time, games played, and recent partner/opponent history. Unlisted matchups are blocked.</span>
    </div>

    <!-- ── Bunot-bunot draw reveal ── -->
    <div class="opc-draw" id="drawPanel" aria-live="polite" hidden>
        <div class="opc-draw-kicker">Bunot-Bunot Draw</div>
        <div id="drawRows"></div>
        <div class="opc-slots" id="drawSlots" hidden>
            <div class="opc-slot"><div class="opc-tiny">Team A</div><div class="opc-slot-name" data-slot></div><div class="opc-slot-name" data-slot></div></div>
            <div class="opc-slot"><div class="opc-tiny">Team B</div><div class="opc-slot-name" data-slot></div><div class="opc-slot-name" data-slot></div></div>
        </div>
        <p class="opc-draw-msg" id="drawMsg" hidden></p>
        <button type="button" class="opc-link-btn" id="drawClose">Close</button>
    </div>

    <!-- ── Courts ── -->
    <div class="opc-courts" id="courtGrid">
        <div class="opc-skeleton"></div><div class="opc-skeleton"></div>
    </div>

    <!-- ── Finished games · editable ── -->
    <section class="opc-section">
        <div class="opc-section-head">
            <h3 class="opc-h3">Finished Games · Editable Results</h3>
            <span class="opc-meta"><?= count($recentFinished) ?> recent</span>
        </div>
        <div class="opc-list">
            <?php if (!$recentFinished): ?>
                <p class="opc-row-empty">Finished games will appear here — made a scoring mistake? Fix it from this list.</p>
            <?php else: foreach ($recentFinished as $r):
                $t1 = trim(($r['t1p1'] ?? '') . (!empty($r['t1p2']) ? ' & ' . $r['t1p2'] : ''));
                $t2 = trim(($r['t2p1'] ?? '') . (!empty($r['t2p2']) ? ' & ' . $r['t2p2'] : ''));
                $s1 = (int)$r['score_team1']; $s2 = (int)$r['score_team2'];
                $winner = $s1 > $s2 ? $t1 : $t2;
            ?>
                <div class="opc-row">
                    <div>
                        <div><span class="opc-fname"><?= clean($t1) ?></span><span class="opc-score"><?= $s1 ?> - <?= $s2 ?></span><span class="opc-fname"><?= clean($t2) ?></span></div>
                        <div class="opc-sub">
                            <?= !empty($r['court_name']) ? clean($r['court_name']) . ' · ' : '' ?>Winner: <?= clean($winner) ?>
                            <?= !empty($r['finished_at']) ? ' · ' . clean(date('M j, g:i A', strtotime($r['finished_at']))) : '' ?>
                        </div>
                    </div>
                    <button type="button" class="opc-btn opc-sm" data-act="correct"
                            data-id="<?= (int)$r['id'] ?>" data-a="<?= $s1 ?>" data-b="<?= $s2 ?>"
                            data-t1="<?= clean($t1) ?>" data-t2="<?= clean($t2) ?>">Edit result</button>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </section>

    <!-- ── Up next | Waiting queue ── -->
    <div class="opc-split">
        <div>
            <div class="opc-section-head">
                <h3 class="opc-h3">Up Next</h3>
                <span class="opc-meta" id="upNextMeta">—</span>
            </div>
            <div class="opc-list" id="upNextList"></div>
        </div>
        <div>
            <div class="opc-section-head">
                <h3 class="opc-h3">Waiting Queue</h3>
                <span class="opc-meta" id="queueMeta">—</span>
            </div>
            <div class="opc-list" id="queueList"></div>
        </div>
    </div>

    <?php if (false): // Registration, leaderboard, and raffles are standalone modules. ?>
    <!-- ── Registration: manual players + join requests ── -->
    <section class="opc-section opc-tab-panel" id="registration" role="tabpanel" data-panel="registration">
        <div class="opc-section-head">
            <div>
                <h3 class="opc-h3">Registration &amp; Approvals</h3>
                <p class="opc-sub" style="margin:4px 0 0;">Registered players include staff-added players and players who requested to join.</p>
            </div>
            <span class="opc-meta"><?= count($roster) ?> player<?= count($roster) === 1 ? '' : 's' ?></span>
        </div>
        <div class="opc-panel">
            <form method="POST" class="opc-add">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_player"/>
                <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                <input type="text" list="playerList" name="player_name_lookup" class="opc-field" placeholder="Type a player's name…" autocomplete="off" aria-label="Player name"/>
                <input type="hidden" name="player_id" id="player_id_field"/>
                <datalist id="playerList">
                    <?php foreach ($searchablePlayers as $p): ?>
                        <option data-id="<?= (int)$p['id'] ?>" value="<?= clean($p['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <select name="skill_level" class="opc-field" aria-label="Skill level">
                    <option value="beginner">Beginner</option>
                    <option value="average" selected>Average</option>
                    <option value="advance">Advance</option>
                </select>
                <button type="submit" class="opc-btn-action opc-sm">Add player</button>
            </form>

            <div class="opc-table-wrap">
            <table class="opc-table">
                <thead><tr><th>Player</th><th>Skill</th><th>Status</th><th>W-L</th><th></th></tr></thead>
                <tbody>
                <?php if (!$roster): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--t30);">No players in this event yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($roster as $r):
                    $queueLabels = ['waiting' => 'Waiting', 'playing' => 'Playing', 'resting' => 'Resting', 'queued' => 'Up Next', 'left' => 'Left'];
                    $isPending  = $r['status'] === 'pending_approval';
                    $queueLabel = $isPending ? 'Pending approval' : ($queueLabels[$r['queue_status']] ?? ucfirst((string)$r['queue_status']));
                    $queueClass = $isPending ? 'opc-pill-warn' : ($r['queue_status'] === 'waiting' ? 'opc-pill-live' : ($r['queue_status'] === 'playing' ? 'opc-pill-ball' : ''));
                    $skill      = strtolower((string)$r['skill_level']);
                ?>
                    <tr class="<?= $isPending ? 'is-pending' : '' ?>">
                        <td><?= clean($r['display_name'] ?? $r['full_name'] ?? $r['username'] ?? 'Player') ?></td>
                        <td><span class="opc-skill opc-skill-<?= clean($skill) ?>" style="font-size:11px;"><?= clean($skill) ?></span></td>
                        <td><span class="opc-pill <?= $queueClass ?>"><?= clean($queueLabel) ?></span></td>
                        <td><?= (int)$r['wins'] ?>-<?= (int)$r['losses'] ?></td>
                        <td>
                            <div class="opc-cell-actions">
                            <?php if ($isPending): ?>
                                <span title="<?= clean(($r['payment_method'] ?? '') . ' / ' . ($r['reference_no'] ?? '')) ?>" style="font-size:12px;margin-right:6px;">
                                    ₱<?= number_format((float)($r['payment_amount'] ?? 0), 2) ?>
                                    <?php if (!empty($r['payment_request_id'])): ?><a class="opc-link" href="<?= APP_URL ?>/api/open_play_payment_proof.php?id=<?= (int)$r['payment_request_id'] ?>" target="_blank" rel="noopener">Proof</a><?php endif; ?>
                                </span>
                                <form method="POST"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="approve_join"/>
                                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                                    <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                                    <button type="submit" class="opc-mini opc-mini-green">Approve</button>
                                </form>
                                <form method="POST"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="reject_join"/>
                                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                                    <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                                    <button type="submit" class="opc-mini opc-mini-red">Reject</button>
                                </form>
                            <?php elseif ($r['queue_status'] !== 'waiting'): ?>
                                <form method="POST"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="queue_status"/>
                                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                                    <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                                    <input type="hidden" name="status" value="waiting"/>
                                    <button type="submit" class="opc-mini">Return</button>
                                </form>
                            <?php else: ?>
                                <form method="POST"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="queue_status"/>
                                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                                    <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                                    <input type="hidden" name="status" value="resting"/>
                                    <button type="submit" class="opc-mini">Rest</button>
                                </form>
                            <?php endif; ?>
                                <form method="POST" data-confirm="Remove this player from the event?"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="remove_player"/>
                                    <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                                    <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                                    <button type="submit" class="opc-mini opc-mini-red">Remove</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </section>

    <!-- ── Standings ── -->
    <section class="opc-section opc-tab-panel" id="leaderboard" role="tabpanel" data-panel="leaderboard" hidden>
        <div class="opc-section-head">
            <h3 class="opc-h3">Standing</h3>
            <span class="opc-meta"><?= count($standings) ?> ranked</span>
        </div>
        <div class="opc-panel">
            <?php foreach ($ties as $tie): ?>
                <div class="opc-notice opc-notice-warn">
                    <span>Tie for <?= $tie['rank'] == 1 ? '1st' : ($tie['rank'] == 2 ? '2nd' : '3rd') ?> place between
                        <?= implode(', ', array_map(fn($r) => clean($r['display_name'] ?? $r['full_name']), $tie['rows'])) ?>.</span>
                    <button type="button" class="opc-btn-action opc-sm" data-act="tiebreak"
                            data-ids="<?= clean(json_encode(array_map('intval', array_column($tie['rows'], 'player_id')))) ?>">Run tiebreaker game</button>
                </div>
            <?php endforeach; ?>

            <div class="opc-table-wrap">
            <table class="opc-table">
                <thead><tr><th>#</th><th>Player</th><th>W</th><th>L</th><th>Win%</th><th>Diff</th></tr></thead>
                <tbody>
                <?php if (!$standings): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--t30);">Standings appear after the first finished game.</td></tr>
                <?php endif; ?>
                <?php foreach ($standings as $i => $s): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= clean($s['display_name'] ?? $s['full_name']) ?></td>
                        <td><?= (int)$s['wins'] ?></td>
                        <td><?= (int)$s['losses'] ?></td>
                        <td><?= clean((string)$s['win_pct']) ?>%</td>
                        <td><?= $s['point_diff'] > 0 ? '+' : '' ?><?= clean((string)$s['point_diff']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </section>

    <!-- ── Raffle ── -->
    <?php if (!$isClosed): $latestRaffle = $engine->getLatestRaffleDraw($selected); ?>
    <section class="opc-section opc-tab-panel" id="raffles" role="tabpanel" data-panel="raffles" hidden>
        <div class="opc-section-head"><h3 class="opc-h3">Raffles</h3></div>
        <div class="opc-panel">
            <p class="opc-help">Spins a random winner from every player registered or approved for this event. Doesn't affect the queue or standings — it's a side prize draw.</p>
            <div class="opc-raffle-row">
                <div style="flex:1;min-width:220px;">
                    <label class="opc-label" for="rafflePrize">Prize</label>
                    <input type="text" id="rafflePrize" class="opc-field" maxlength="160" placeholder="e.g. Free entry next week"/>
                </div>
                <button type="button" class="opc-btn-action" id="raffleSpinBtn">Spin raffle</button>
            </div>
            <div id="raffleResult" style="<?= $latestRaffle ? '' : 'display:none;' ?>font-size:14px;color:var(--t80);">
                <?php if ($latestRaffle): ?>
                    🏆 Last winner: <strong><?= clean($latestRaffle['winner_name']) ?></strong>
                    — <?= clean($latestRaffle['prize_description']) ?>
                    <span style="color:var(--t45);">(<?= (int)count(json_decode($latestRaffle['participant_names'], true) ?: []) ?> entrants, drawn <?= clean(date('M j, g:i A', strtotime($latestRaffle['created_at']))) ?>)</span>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <?php endif; // standalone modules are rendered by their dedicated pages. ?>

<?php endif; /* $event */ ?>
</div><!-- /.opc -->

<!-- Score modal + toasts. Moved to <body> by the script so position:fixed is
     relative to the viewport (the page wrapper is animated with a transform). -->
<div class="opc opc-overlay" id="opcOverlay">
    <div class="opc-modal-shell" id="scoreModal" hidden>
        <div class="opc-modal-card" role="dialog" aria-modal="true" aria-labelledby="scoreTitle" tabindex="-1">
            <h3 class="opc-modal-title" id="scoreTitle">Who won?</h3>
            <p class="opc-modal-sub">Enter both final scores. The team with the higher score must be selected as the winner.</p>
            <div class="opc-msg" id="scoreMsg" hidden></div>
            <div class="opc-two">
                <label>Team A score<input type="number" min="0" inputmode="numeric" id="scoreA" class="opc-field" required/></label>
                <label>Team B score<input type="number" min="0" inputmode="numeric" id="scoreB" class="opc-field" required/></label>
            </div>
            <div class="opc-two">
                <button type="button" class="opc-winner" id="winA" disabled><div class="opc-tiny">Team A</div><div id="winANames"></div></button>
                <button type="button" class="opc-winner" id="winB" disabled><div class="opc-tiny">Team B</div><div id="winBNames"></div></button>
            </div>
            <div class="opc-modal-foot"><button type="button" class="opc-link-btn" id="scoreCancel" style="margin:0;padding:10px 12px;">Cancel</button></div>
        </div>
    </div>
    <div class="opc-toasts" id="opcToasts" aria-live="polite"></div>
</div>

<script nonce="<?= getCspNonce() ?>">
(function () {
'use strict';
const APP_URL = '<?= APP_URL ?>';
const TID     = <?= (int)$selected ?>;
const HAS_EVENT = <?= $event ? 'true' : 'false' ?>;
const CSRF    = '<?= csrfToken() ?>';
const COURTS  = <?= json_encode($courtsForJs, $jsonFlags) ?>;
const SKILL   = <?= json_encode((object)$skillById, $jsonFlags) ?>;   // player_id → beginner|average|advance
const ALL_NAMES = <?= json_encode($allNames, $jsonFlags) ?>;

const $  = (s, r) => (r || document).querySelector(s);
const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
const fmt = sec => { sec = Math.max(0, sec | 0); return String(Math.floor(sec / 60)).padStart(2, '0') + ':' + String(sec % 60).padStart(2, '0'); };
const sleep = ms => new Promise(r => setTimeout(r, ms));
const reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

// Registration, Leaderboard, and Raffles are separate views in this page.
const tabButtons = $$('.opc-section-tab, [data-tab]');
const tabPanels = $$('[data-panel]');
function activateTab(name, updateHash = true) {
    const valid = ['registration', 'leaderboard', 'raffles'];
    const active = valid.includes(name) ? name : 'registration';
    tabPanels.forEach(panel => { panel.hidden = panel.dataset.panel !== active; });
    $$('.opc-section-tab').forEach(button => {
        const selected = button.dataset.tab === active;
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
        button.tabIndex = selected ? 0 : -1;
    });
    if (updateHash) history.replaceState(null, '', `${location.pathname}${location.search}#${active}`);
}
tabButtons.forEach(button => button.addEventListener('click', () => activateTab(button.dataset.tab)));
activateTab(location.hash.slice(1), false);
window.addEventListener('hashchange', () => activateTab(location.hash.slice(1), false));

// Move the modal/toast layer out of the animated page wrapper.
const overlay = $('#opcOverlay');
if (overlay) document.body.appendChild(overlay);

// ── Small shared behaviours (work with or without a selected event) ──
document.addEventListener('change', e => {
    const el = e.target.closest && e.target.closest('[data-autosubmit]');
    if (el && el.form) el.form.submit();
});
document.addEventListener('submit', e => {
    const msg = e.target.dataset && e.target.dataset.confirm;
    if (msg && !confirm(msg)) e.preventDefault();
});

function toast(msg, kind) {
    const box = $('#opcToasts'); if (!box) return;
    const t = document.createElement('div');
    t.className = 'opc-toast' + (kind === 'error' ? ' is-error' : '');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(() => t.remove(), 4500);
}

if (!TID || !HAS_EVENT) return;   // no (valid) event selected — nothing live to run

async function callApi(action, body) {
    const res = await fetch(`${APP_URL}/api/open_play.php?action=${action}`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
    });
    let data;
    try { data = await res.json(); }
    catch (_) { throw new Error('Your session may have expired — reload the page and try again.'); }
    if (!data.success) {
        const err = new Error(data.message || 'Request failed.');
        err.needsConfirmation = !!(data.errors && data.errors.needs_confirmation);
        throw err;
    }
    return data.data;
}

// ── Player-name typeahead (avoids a 500-option <select>) ────
const lookup = $('input[name="player_name_lookup"]');
if (lookup) {
    const idField = $('#player_id_field');
    lookup.addEventListener('input', () => {
        idField.value = '';
        for (const opt of $('#playerList').options) {
            if (opt.value === lookup.value) { idField.value = opt.dataset.id; break; }
        }
    });
    lookup.closest('form').addEventListener('submit', e => {
        if (!lookup.value.trim()) { e.preventDefault(); toast('Enter a player name.', 'error'); }
    });
}

// ── Live board ─────────────────────────────────────────────
let live = null, fetchedAt = 0, lastSig = '';
const matchById = {};
const skillTried = new Set();

const SKILLS = { beginner: 1, average: 1, advance: 1 };
function chip(id, name) {
    const s = SKILL[id];
    const skill = SKILLS[s] ? s : '';
    return `<span class="opc-chip"><span class="opc-chip-name">${esc(name)}</span>${skill ? `<span class="opc-skill opc-skill-${skill}">${skill}</span>` : ''}</span>`;
}
function team(m, n) {
    const chips = [1, 2].map(i => m[`t${n}p${i}_name`] ? chip(m[`team${n}_player${i}_id`], m[`t${n}p${i}_name`]) : '').join('');
    return `<span class="opc-team">${chips || '—'}</span>`;
}
const isTb = m => m.is_tiebreaker === true || m.is_tiebreaker === 't' || m.is_tiebreaker === 1 || m.is_tiebreaker === '1';
function timeLeft(m) {
    const base = Number(m.time_left) || 0;
    return m.status === 'in_progress' ? Math.max(0, base - Math.floor((Date.now() - fetchedAt) / 1000)) : base;
}

function courtCard(name, m, maint) {
    if (!m) {
        return `<div class="opc-court${maint ? ' is-off' : ''}">
            <div class="opc-court-head"><span class="opc-court-name">${esc(name)}</span><span class="opc-pill">${maint ? 'Maintenance' : 'Free'}</span></div>
            <p class="opc-empty">No game assigned</p></div>`;
    }
    const st = m.status;
    const pill = st === 'in_progress' ? '<span class="opc-pill opc-pill-live"><span class="opc-dot"></span>Live</span>'
               : st === 'paused'      ? '<span class="opc-pill opc-pill-warn">Paused</span>'
               :                        '<span class="opc-pill opc-pill-ball">Ready</span>';
    let btns = '';
    if (st === 'ready') {
        btns = `<button class="opc-act opc-act-green" data-act="start" data-id="${m.id}">Start</button>`;
    } else if (st === 'in_progress') {
        btns = `<button class="opc-act opc-act-yellow" data-act="pause" data-id="${m.id}">Pause</button>
                <button class="opc-act opc-act-red" data-act="finish" data-id="${m.id}">Finish</button>
                <button class="opc-act opc-act-ghost" data-act="adjust" data-delta="-60" data-id="${m.id}" title="Take a minute off the clock">−1m</button>
                <button class="opc-act opc-act-ghost" data-act="adjust" data-delta="60" data-id="${m.id}" title="Add a minute to the clock">+1m</button>`;
    } else if (st === 'paused') {
        btns = `<button class="opc-act opc-act-green" data-act="resume" data-id="${m.id}">Resume</button>
                <button class="opc-act opc-act-red" data-act="finish" data-id="${m.id}">Finish</button>`;
    }
    btns += `<button class="opc-act opc-act-ghost" data-act="cancel" data-id="${m.id}">Cancel game</button>`;
    return `<div class="opc-court">
        <div class="opc-court-head"><span class="opc-court-name">${esc(name)}</span>${pill}</div>
        ${isTb(m) ? '<div class="opc-badge-tb">🏆 Tiebreaker</div>' : ''}
        <div class="opc-teams">${team(m, 1)}<span class="opc-vs">VS</span>${team(m, 2)}</div>
        <div class="opc-timer" data-timer="${m.id}">${fmt(timeLeft(m))}</div>
        <div class="opc-status">${st === 'in_progress' ? 'In progress' : esc(st)}</div>
        <div class="opc-actions">${btns}</div></div>`;
}

function miniForm(action, pid, status, label, cls, confirmMsg) {
    return `<form method="POST"${confirmMsg ? ` data-confirm="${esc(confirmMsg)}"` : ''}>
        <input type="hidden" name="csrf_token" value="${esc(CSRF)}"/>
        <input type="hidden" name="action" value="${action}"/>
        <input type="hidden" name="tournament_id" value="${TID}"/>
        <input type="hidden" name="player_id" value="${esc(pid)}"/>
        ${status ? `<input type="hidden" name="status" value="${status}"/>` : ''}
        <button type="submit" class="opc-mini ${cls || ''}">${label}</button></form>`;
}

function render() {
    // header count
    const n = live.waiting_count | 0;
    const wc = $('#waitingCount'); if (wc) wc.textContent = `${n} player${n === 1 ? '' : 's'} waiting`;

    // courts
    const byCourt = {};
    live.now_playing.forEach(m => { byCourt[m.court_id] = m; });
    const seen = new Set(), cards = [];
    COURTS.forEach(c => { seen.add(String(c.id)); cards.push(courtCard(c.name, byCourt[c.id], c.maint)); });
    live.now_playing.forEach(m => { if (!seen.has(String(m.court_id))) cards.push(courtCard(m.court_name || 'Court', m)); });
    $('#courtGrid').innerHTML = cards.length ? cards.join('')
        : '<p class="opc-row-empty" style="grid-column:1/-1">No active courts are set up yet — add courts under Admin → Courts.</p>';

    // up next (courtless lineups)
    const ups = live.up_next.map(m => `<div class="opc-row">
        <div class="opc-lineup">${isTb(m) ? '<span title="Tiebreaker">🏆</span>' : ''}${team(m, 1)}<span class="opc-vs">VS</span>${team(m, 2)}</div>
        <button class="opc-act opc-act-ghost opc-xs" data-act="cancel" data-id="${m.id}">Cancel</button></div>`);
    for (let i = ups.length; i < 3; i++) ups.push(`<p class="opc-row-empty">Lineup ${i + 1} awaiting eligible players</p>`);
    $('#upNextList').innerHTML = ups.join('');
    $('#upNextMeta').textContent = `${live.up_next.length} lineup${live.up_next.length === 1 ? '' : 's'}`;

    // waiting queue
    $('#queueList').innerHTML = live.waiting_pool.length
        ? live.waiting_pool.map((p, i) => {
            const sk = SKILLS[p.skill_level] ? p.skill_level : '';
            return `<div class="opc-q"><div class="opc-q-left"><span class="opc-idx">${i + 1}</span>
                <span class="opc-q-name"><span>${esc(p.display_name)}</span>${sk ? `<span class="opc-skill opc-skill-${sk}">${sk}</span>` : ''}</span></div>
                <div class="opc-q-actions">${miniForm('queue_status', p.player_id, 'resting', 'Rest')}${miniForm('remove_player', p.player_id, '', 'Remove', 'opc-mini-red', 'Remove this player from the event?')}</div></div>`;
        }).join('')
        : '<p class="opc-row-empty" style="text-align:left;letter-spacing:0;text-transform:none;font-size:14px;">No players are currently waiting in the queue.</p>';
    $('#queueMeta').textContent = `${live.waiting_pool.length} player${live.waiting_pool.length === 1 ? '' : 's'}`;
}

function tick() {
    $$('[data-timer]').forEach(el => {
        const m = matchById[el.dataset.timer]; if (!m) return;
        const t = timeLeft(m);
        el.textContent = fmt(t);
        el.classList.toggle('is-zero', m.status === 'in_progress' && t === 0);
    });
}
setInterval(tick, 1000);

// Skill labels for players who joined after the page loaded.
async function ensureSkills(matches, pool) {
    (pool || []).forEach(p => { if (p.skill_level) SKILL[p.player_id] = p.skill_level; });
    const missing = [];
    matches.forEach(m => {
        [1, 2].forEach(n => [1, 2].forEach(i => {
            const id = m[`team${n}_player${i}_id`];
            if (id && !SKILL[id] && !skillTried.has(String(id))) missing.push(String(id));
        }));
    });
    if (!missing.length) return;
    missing.forEach(id => skillTried.add(id));
    try {
        const res = await fetch(`${APP_URL}/api/open_play.php?action=roster&tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        if (data.success) data.data.forEach(r => { if (r.player_id && r.skill_level) SKILL[r.player_id] = r.skill_level; });
    } catch (_) { /* labels are cosmetic */ }
}

async function loadLive(force) {
    try {
        const res = await fetch(`${APP_URL}/api/open_play_kiosk.php?tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Load failed');
        live = data.data; fetchedAt = Date.now();
        Object.keys(matchById).forEach(k => delete matchById[k]);
        [...live.now_playing, ...live.up_next].forEach(m => { matchById[m.id] = m; });
        await ensureSkills([...live.now_playing, ...live.up_next], live.waiting_pool);
        // Re-render only when something other than the ticking clock changed.
        const sig = JSON.stringify([
            live.now_playing.map(m => Object.assign({}, m, { time_left: 0 })),
            live.up_next, live.waiting_pool, live.waiting_count,
        ]);
        if (force || sig !== lastSig) { lastSig = sig; render(); }
        tick();
    } catch (e) {
        if (!live) $('#courtGrid').innerHTML = '<p class="opc-row-empty" style="grid-column:1/-1">Couldn\'t load the live board — retrying…</p>';
    }
}

// ── Actions (courts, finished list, standings) ─────────────
const root = $('#opcRoot');
root.addEventListener('click', async ev => {
    const b = ev.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act, id = Number(b.dataset.id);
    try {
        switch (act) {
            case 'finish': {
                const m = matchById[id]; if (!m) return;
                openScore({ matchId: id, action: 'finish_match', htmlA: team(m, 1), htmlB: team(m, 2) });
                return;
            }
            case 'correct':
                openScore({ matchId: id, action: 'correct_score', a: b.dataset.a, b: b.dataset.b,
                            htmlA: esc(b.dataset.t1), htmlB: esc(b.dataset.t2) });
                return;
            case 'tiebreak':
                if (!confirm('Draw a one-off tiebreaker game between these tied players?')) return;
                b.disabled = true;
                await callApi('tiebreak', { tournament_id: TID, player_ids: JSON.parse(b.dataset.ids) });
                toast('Tiebreaker game drawn — check the courts above.');
                break;
            case 'cancel':
                if (!confirm("Cancel this game? Its players will return to the waiting queue.")) return;
                b.disabled = true;
                await callApi('cancel_match', { match_id: id });
                break;
            case 'start':  b.disabled = true; await callApi('start_match',  { match_id: id }); break;
            case 'pause':  b.disabled = true; await callApi('pause_match',  { match_id: id }); break;
            case 'resume': b.disabled = true; await callApi('resume_match', { match_id: id }); break;
            case 'adjust': b.disabled = true; await callApi('adjust_timer', { match_id: id, delta_seconds: Number(b.dataset.delta) }); break;
            default: return;
        }
        loadLive(true);
    } catch (e) {
        b.disabled = false;
        toast(e.message, 'error');
        loadLive(true);
    }
});

// ── Score modal ("Who won?" / "Correct game result") ───────
const modal = $('#scoreModal'), inA = $('#scoreA'), inB = $('#scoreB'), winA = $('#winA'), winB = $('#winB'), msgBox = $('#scoreMsg');
let score = null, prevFocus = null, prevOverflow = '';

function scoreMsg(text, isError) {
    msgBox.textContent = text; msgBox.hidden = !text;
    msgBox.classList.toggle('is-error', !!isError);
}
function syncWinnerButtons() {
    const ok = inA.value.trim() !== '' && inB.value.trim() !== '';
    winA.disabled = winB.disabled = !ok;
}
function openScore(ctx) {
    score = Object.assign({ confirmed: false }, ctx);
    $('#scoreTitle').textContent = ctx.action === 'correct_score' ? 'Correct game result' : 'Who won?';
    inA.value = ctx.a ?? ''; inB.value = ctx.b ?? '';
    $('#winANames').innerHTML = ctx.htmlA || 'Team A';
    $('#winBNames').innerHTML = ctx.htmlB || 'Team B';
    scoreMsg('');
    syncWinnerButtons();
    prevFocus = document.activeElement; prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    modal.hidden = false;
    setTimeout(() => inA.focus(), 0);
}
function closeScore() {
    modal.hidden = true; score = null;
    document.body.style.overflow = prevOverflow;
    if (prevFocus && prevFocus.focus) prevFocus.focus();
}
[inA, inB].forEach(i => i.addEventListener('input', () => { syncWinnerButtons(); if (score) score.confirmed = false; }));
$('#scoreCancel').addEventListener('click', closeScore);
modal.addEventListener('mousedown', e => { if (e.target === modal) closeScore(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeScore(); });

async function confirmWinner(side) {
    if (!score) return;
    if (inA.value.trim() === '' || inB.value.trim() === '') { scoreMsg('Enter both Team A and Team B scores before selecting the winner.', true); return; }
    const a = Number(inA.value), b = Number(inB.value);
    if (!Number.isInteger(a) || !Number.isInteger(b) || a < 0 || b < 0 || a === b) { scoreMsg('Enter two different final scores before recording the winner.', true); return; }
    if ((side === 'A' && a < b) || (side === 'B' && b < a)) { scoreMsg('The winning team must have the higher score.', true); return; }
    winA.disabled = winB.disabled = true;
    try {
        await callApi(score.action, { match_id: score.matchId, score_a: a, score_b: b, confirmed: score.confirmed });
        closeScore();
        location.reload();   // refresh finished games + standings below
    } catch (e) {
        syncWinnerButtons();
        if (e.needsConfirmation) {
            score.confirmed = true;
            scoreMsg('⚠️ ' + e.message + ' Tap the winning team again to save anyway.', false);
        } else {
            scoreMsg(e.message, true);
        }
    }
}
winA.addEventListener('click', () => confirmWinner('A'));
winB.addEventListener('click', () => confirmWinner('B'));

// ── Bunot-bunot draw reveal ────────────────────────────────
const drawBtn = $('#drawBtn'), drawPanel = $('#drawPanel'), drawRows = $('#drawRows'),
      drawSlots = $('#drawSlots'), drawMsg = $('#drawMsg');
let drawing = false, spinIv = null;

function startSpin() {
    drawSlots.hidden = false;
    clearInterval(spinIv);
    if (reduceMotion) return;
    const pool = ALL_NAMES.length ? ALL_NAMES : ['—'];
    const slots = $$('[data-slot]', drawSlots);
    const shuffle = () => slots.forEach(el => { el.textContent = pool[Math.floor(Math.random() * pool.length)]; });
    shuffle(); spinIv = setInterval(shuffle, 80);
}
function stopSpin() { clearInterval(spinIv); spinIv = null; drawSlots.hidden = true; }
function drawMessage(text) { drawMsg.textContent = text; drawMsg.hidden = false; }
function drawRow(g) {
    return `<div class="opc-draw-row"><span class="opc-draw-court">${g.court_name ? esc(g.court_name) : 'Up next — waiting for a court'}</span>
        ${team(g, 1)}<span class="opc-vs">VS</span>${team(g, 2)}</div>`;
}
async function doDraw() {
    if (drawing) return;
    drawing = true; drawBtn.disabled = true;
    drawPanel.hidden = false; drawRows.innerHTML = ''; drawMsg.hidden = true;
    drawPanel.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
    startSpin();
    let games = [], err = null;
    try { games = await callApi('draw', { tournament_id: TID }); } catch (e) { err = e.message; }
    if (err) {
        stopSpin(); drawMessage('⚠️ ' + err);
    } else if (!games.length) {
        stopSpin(); drawMessage('No compatible lineup is available yet. The draw needs enough waiting players, a free court, and a permitted skill combination.');
    } else {
        await ensureSkills(games, []);
        for (const g of games) { await sleep(reduceMotion ? 0 : 1500); drawRows.insertAdjacentHTML('beforeend', drawRow(g)); }
        stopSpin();
    }
    loadLive(true);
    drawing = false; drawBtn.disabled = false;
}
if (drawBtn) drawBtn.addEventListener('click', doDraw);
$('#drawClose').addEventListener('click', () => { drawPanel.hidden = true; });

// ── Raffle ─────────────────────────────────────────────────
const raffleBtn = $('#raffleSpinBtn');
if (raffleBtn) raffleBtn.addEventListener('click', async () => {
    const input = $('#rafflePrize'), prize = input.value.trim();
    if (!prize) { toast('Enter what the raffle winner gets first.', 'error'); return; }
    raffleBtn.disabled = true;
    try {
        const data = await callApi('raffle_spin', { tournament_id: TID, prize_description: prize });
        const box = $('#raffleResult'), n = data.participants.length;
        box.style.display = '';
        box.innerHTML = `🏆 Winner: <strong>${esc(data.draw.winner_name)}</strong> — ${esc(data.draw.prize_description)}
            <span style="color:var(--t45)">(${n} entrant${n === 1 ? '' : 's'})</span>`;
        input.value = '';
    } catch (e) { toast(e.message, 'error'); }
    raffleBtn.disabled = false;
});

loadLive(true);
setInterval(() => loadLive(false), 6000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>