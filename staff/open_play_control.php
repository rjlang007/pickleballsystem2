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
            case 'create':
                $t = $engine->createEvent($_POST, (int)$user['id']);
                setFlash('success', '🎲 Open play event created.');
                redirect('staff/open_play_control.php?tournament_id=' . $t['id']);
                break;

            case 'add_player':
                $engine->joinEvent($tid, (int)$_POST['player_id'], $_POST['skill_level'] ?? 'average');
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

            case 'update_event':
                $engine->updateEvent($tid, $_POST, (int)$user['id']);
                setFlash('success', 'Event settings updated.');
                break;

            case 'cancel_event':
                $engine->cancelEvent($tid, (int)$user['id']);
                setFlash('success', 'Event cancelled.');
                redirect('staff/open_play_control.php');
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
        if (in_array($e['status'], ['registration_open', 'in_progress'], true)) { $selected = (int)$e['id']; break; }
    }
}
$event    = $selected ? $engine->getEvent($selected) : null;
$roster   = $event ? $engine->getRoster($selected) : [];
$standings= $event ? $engine->computeLeaderboard($selected) : [];
$ties     = $event ? $engine->detectPodiumTies($standings) : [];
$db       = getDB();
$searchablePlayers = $db->query(
    "SELECT id, COALESCE(display_name, full_name, username) AS name FROM falcon.users
      WHERE role = 'player' AND is_banned = FALSE ORDER BY name LIMIT 500"
)->fetchAll();

$pageTitle = 'Open Play Control';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🎲 Open Play — Staff Control</h1>
    <p>Draw skill-balanced games, run the courts, and finalize standings when the session wraps up.</p>
</div>

<!-- ── Event picker / create ── -->
<div class="card" style="margin-bottom:20px;">
    <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <form method="GET" style="flex:1;min-width:220px;">
            <label>Event</label>
            <select name="tournament_id" onchange="this.form.submit()">
                <option value="">— Select an event —</option>
                <?php foreach ($events as $e): ?>
                    <option value="<?= (int)$e['id'] ?>" <?= $selected === (int)$e['id'] ? 'selected' : '' ?>>
                        <?= clean($e['name']) ?> (<?= clean($e['status']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <details style="flex:1;min-width:260px;">
            <summary style="cursor:pointer;color:var(--accent);">+ New open play event</summary>
            <form method="POST" style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create"/>
                <input type="text" name="name" placeholder="Event name (e.g. Friday Night Open Play)" required/>
                <textarea name="description" placeholder="Description (optional)" rows="2"></textarea>
                <div style="display:flex;gap:8px;">
                    <select name="format" style="flex:1;">
                        <option value="doubles">Doubles (2v2)</option>
                        <option value="singles">Singles (1v1)</option>
                    </select>
                    <input type="number" name="game_duration" value="900" min="60" step="60" title="Game duration, seconds" style="flex:1;"/>
                </div>
                <button type="submit" class="btn btn-primary">Create Event</button>
            </form>
        </details>
    </div>
</div>

<?php if (!$event): ?>
    <div class="card"><p class="text-muted">Select or create an open play event to get started.</p></div>
<?php else: $isClosed = in_array($event['status'], ['completed', 'cancelled'], true); ?>

<?php if ($isClosed): ?>
<div class="alert <?= $event['status'] === 'cancelled' ? 'alert-warning' : 'alert-info' ?>" style="margin-bottom:14px;">
    <?= $event['status'] === 'cancelled' ? '🗑️ This event was cancelled — it can no longer be edited or drawn into.' : '🏁 This event has been finalized — see the results page for the final standings.' ?>
    <?php if ($event['status'] === 'completed'): ?>
        <a href="<?= APP_URL ?>/public/open_play_results.php?tournament_id=<?= $selected ?>">View Results →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
    <div style="display:flex;gap:10px;">
        <a class="btn" href="<?= APP_URL ?>/staff/open_play_kiosk.php?tournament_id=<?= $selected ?>" target="_blank">📺 Open TV Kiosk</a>
        <?php if (!$isClosed): ?>
        <button class="btn btn-primary" id="drawBtn">🎡 Draw Next Round</button>
        <?php endif; ?>
    </div>
    <?php if (!$isClosed): ?>
    <div style="display:flex;gap:10px;">
        <form method="POST" onsubmit="return confirm('Cancel this entire event? In-progress games will be stopped and this cannot be undone.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_event"/>
            <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
            <button type="submit" class="btn">🗑️ Cancel Event</button>
        </form>
        <form method="POST" onsubmit="return confirm('Finalize this event? This locks in placements and updates the season leaderboard.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="finalize"/>
            <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
            <button type="submit" class="btn btn-danger">🏁 Finalize Event</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if (!$isClosed): ?>
<div class="card" style="margin-bottom:20px;">
    <details>
        <summary style="cursor:pointer;color:var(--accent);">⚙️ Edit event settings</summary>
        <form method="POST" style="margin-top:12px;display:flex;flex-direction:column;gap:8px;max-width:420px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_event"/>
            <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
            <label>Name</label>
            <input type="text" name="name" value="<?= clean($event['name']) ?>" required/>
            <label>Description</label>
            <textarea name="description" rows="2"><?= clean($event['description'] ?? '') ?></textarea>
            <div style="display:flex;gap:8px;">
                <div style="flex:1;">
                    <label>Format</label>
                    <select name="format" style="width:100%;">
                        <?php $fmt = json_decode($event['settings'] ?? '{}', true)['format'] ?? 'doubles'; ?>
                        <option value="doubles" <?= $fmt === 'doubles' ? 'selected' : '' ?>>Doubles (2v2)</option>
                        <option value="singles" <?= $fmt === 'singles' ? 'selected' : '' ?>>Singles (1v1)</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <label>Game duration (sec)</label>
                    <input type="number" name="game_duration" min="60" step="60"
                           value="<?= (int)(json_decode($event['settings'] ?? '{}', true)['game_duration'] ?? 900) ?>" style="width:100%;"/>
                </div>
                <div style="flex:1;">
                    <label>Max players</label>
                    <input type="number" name="max_players" min="4" value="<?= (int)$event['max_players'] ?>" style="width:100%;"/>
                </div>
            </div>
            <p class="text-muted" style="font-size:12px;margin:0;">Format and duration changes only apply to the <em>next</em> round drawn — games already on a court keep running with their original timer.</p>
            <button type="submit" class="btn btn-primary" style="align-self:flex-start;">Save Changes</button>
        </form>
    </details>
</div>
<?php endif; ?>

<!-- ── Spin wheel overlay ── -->
<div id="wheelOverlay" style="display:none;position:fixed;inset:0;background:rgba(5,10,15,.92);z-index:999;
     align-items:center;justify-content:center;flex-direction:column;gap:18px;">
    <canvas id="wheelCanvas" width="360" height="360"></canvas>
    <div id="wheelStatus" style="color:var(--muted);font-size:14px;">Drawing…</div>
    <div id="wheelResults" style="max-width:520px;text-align:center;"></div>
    <button id="wheelClose" class="btn" style="display:none;">Close</button>
</div>

<!-- ── Score entry modal (replaces native prompt()) ── -->
<div id="scoreModal" style="display:none;position:fixed;inset:0;background:rgba(5,10,15,.75);z-index:1000;
     align-items:center;justify-content:center;">
    <div class="card" style="width:92%;max-width:360px;">
        <div class="card-title mb-1" id="scoreModalTitle">Enter Score</div>
        <hr class="divider"/>
        <div id="scoreModalWarning" class="alert alert-warning" style="display:none;margin-bottom:10px;font-size:13px;"></div>
        <div style="display:flex;gap:10px;margin:10px 0;">
            <div style="flex:1;">
                <label id="scoreModalTeamA" style="font-size:13px;color:var(--muted);">Team A</label>
                <input type="number" id="scoreModalInputA" min="0" style="width:100%;font-size:20px;text-align:center;"/>
            </div>
            <div style="flex:1;">
                <label id="scoreModalTeamB" style="font-size:13px;color:var(--muted);">Team B</label>
                <input type="number" id="scoreModalInputB" min="0" style="width:100%;font-size:20px;text-align:center;"/>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
            <button class="btn" onclick="closeScoreModal()">Cancel</button>
            <button class="btn btn-primary" id="scoreModalSaveBtn">Save</button>
        </div>
    </div>
</div>

<!-- ── Live courts ── -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">🏟️ Live Courts</div>
    <hr class="divider"/>
    <div id="liveCourts" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;"></div>
</div>

<?php
$recentFinished = $db->prepare(
    "SELECT m.id, m.score_team1, m.score_team2, m.finished_at, c.name AS court_name,
            u1.display_name AS t1p1, u2.display_name AS t1p2, u3.display_name AS t2p1, u4.display_name AS t2p2
       FROM falcon.open_play_matches m
       LEFT JOIN falcon.courts c ON c.id = m.court_id
       LEFT JOIN falcon.users u1 ON u1.id = m.team1_player1_id
       LEFT JOIN falcon.users u2 ON u2.id = m.team1_player2_id
       LEFT JOIN falcon.users u3 ON u3.id = m.team2_player1_id
       LEFT JOIN falcon.users u4 ON u4.id = m.team2_player2_id
      WHERE m.tournament_id = :tid AND m.status = 'finished'
      ORDER BY m.finished_at DESC LIMIT 8"
);
$recentFinished->execute([':tid' => $selected]);
$recentFinished = $recentFinished->fetchAll();
?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">🕓 Recently Finished <span style="font-weight:400;color:var(--muted);font-size:12px;">— made a scoring mistake? Fix it here.</span></div>
    <hr class="divider"/>
    <?php if (!$recentFinished): ?>
        <p class="text-muted">No finished games yet.</p>
    <?php else: foreach ($recentFinished as $r):
        $t1 = trim(($r['t1p1'] ?? '') . (!empty($r['t1p2']) ? ' & ' . $r['t1p2'] : ''));
        $t2 = trim(($r['t2p1'] ?? '') . (!empty($r['t2p2']) ? ' & ' . $r['t2p2'] : ''));
    ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;">
            <div><span style="color:var(--muted);font-size:12px;"><?= clean($r['court_name'] ?? '') ?></span> —
                 <?= clean($t1) ?> <b><?= (int)$r['score_team1'] ?></b> vs <b><?= (int)$r['score_team2'] ?></b> <?= clean($t2) ?></div>
            <button class="btn btn-sm" onclick="doCorrect(this)"
                    data-id="<?= (int)$r['id'] ?>" data-a="<?= (int)$r['score_team1'] ?>" data-b="<?= (int)$r['score_team2'] ?>"
                    data-t1="<?= clean($t1) ?>" data-t2="<?= clean($t2) ?>">✏️ Edit Score</button>
        </div>
    <?php endforeach; endif; ?>
</div>

<!-- ── Roster / waiting pool ── -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-title mb-1">👥 Roster</div>
    <hr class="divider"/>
    <form method="POST" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_player"/>
        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
        <input type="text" list="playerList" name="player_name_lookup" placeholder="Type a player's name…"
               style="flex:1;min-width:200px;" oninput="syncPlayerId(this)" autocomplete="off"/>
        <input type="hidden" name="player_id" id="player_id_field" required/>
        <datalist id="playerList">
            <?php foreach ($searchablePlayers as $p): ?>
                <option data-id="<?= (int)$p['id'] ?>" value="<?= clean($p['name']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <select name="skill_level">
            <option value="beginner">Beginner</option>
            <option value="average" selected>Average</option>
            <option value="advance">Advance</option>
        </select>
        <button type="submit" class="btn btn-primary">Add</button>
    </form>

    <table class="table">
        <thead><tr><th>Player</th><th>Skill</th><th>Status</th><th>W-L</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roster as $r): ?>
            <?php
                $queueLabels = ['waiting' => 'Waiting', 'playing' => 'Playing', 'resting' => 'Resting', 'queued' => 'Up Next', 'left' => 'Left'];
                $queueLabel = $r['status'] === 'pending_approval' ? 'Pending Approval' : ($queueLabels[$r['queue_status']] ?? ucfirst($r['queue_status']));
                $queueClass = $r['queue_status'] === 'waiting' ? 'badge-success' : ($r['queue_status'] === 'playing' ? 'badge-warning' : 'badge-secondary');
            ?>
            <tr>
                <td><?= clean($r['display_name'] ?? $r['full_name'] ?? $r['username'] ?? 'Player') ?></td>
                <td><span class="badge badge-info"><?= $r['skill_level'] === 'advance' ? 'Advanced' : ucfirst($r['skill_level']) ?></span></td>
                <td><span class="badge <?= $queueClass ?>"><?= clean($queueLabel) ?></span></td>
                <td><?= (int)$r['wins'] ?>-<?= (int)$r['losses'] ?></td>
                <td style="display:flex;gap:4px;">
                    <?php if ($r['status'] === 'pending_approval'): ?>
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="approve_join"/>
                        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                        <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                        <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                    </form>
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="reject_join"/>
                        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                        <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                        <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                    </form>
                    <?php elseif ($r['queue_status'] !== 'waiting'): ?>
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="queue_status"/>
                        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                        <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                        <input type="hidden" name="status" value="waiting"/>
                        <button type="submit" class="btn btn-sm">Return</button>
                    </form>
                    <?php else: ?>
                    <form method="POST"><?= csrfField() ?>
                        <input type="hidden" name="action" value="queue_status"/>
                        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                        <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                        <input type="hidden" name="status" value="resting"/>
                        <button type="submit" class="btn btn-sm">Rest</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Remove this player from the event?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="remove_player"/>
                        <input type="hidden" name="tournament_id" value="<?= $selected ?>"/>
                        <input type="hidden" name="player_id" value="<?= (int)$r['player_id'] ?>"/>
                        <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Standings preview ── -->
<div class="card">
    <div class="card-title mb-1">📊 Current Standings</div>
    <hr class="divider"/>

    <?php if (!empty($ties)): foreach ($ties as $tie): ?>
        <div class="alert alert-warning" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
            <span>⚠️ Tie for <?= $tie['rank'] == 1 ? '1st' : ($tie['rank'] == 2 ? '2nd' : '3rd') ?> place between
                <?= implode(', ', array_map(fn($r) => clean($r['display_name'] ?? $r['full_name']), $tie['rows'])) ?>.</span>
            <button class="btn btn-sm btn-primary" onclick="doTiebreak(<?= htmlspecialchars(json_encode(array_column($tie['rows'], 'player_id')), ENT_QUOTES) ?>)">🎲 Run Tiebreaker Game</button>
        </div>
    <?php endforeach; endif; ?>

    <table class="table">
        <thead><tr><th>#</th><th>Player</th><th>W</th><th>L</th><th>Win%</th><th>Diff</th></tr></thead>
        <tbody>
        <?php foreach ($standings as $i => $s): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= clean($s['display_name'] ?? $s['full_name']) ?></td>
                <td><?= (int)$s['wins'] ?></td>
                <td><?= (int)$s['losses'] ?></td>
                <td><?= $s['win_pct'] ?>%</td>
                <td><?= $s['point_diff'] > 0 ? '+' : '' ?><?= $s['point_diff'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script nonce="<?= getCspNonce() ?>">
const APP_URL = '<?= APP_URL ?>';
const TID = <?= (int)$selected ?>;
const CSRF = '<?= csrfToken() ?>';

function esc(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function fmtTime(sec){ sec = Math.max(0, sec|0); return Math.floor(sec/60) + ':' + String(sec%60).padStart(2,'0'); }

// ── Player-name typeahead (avoids a 500-option <select>) ────
function syncPlayerId(input){
    const list = document.getElementById('playerList');
    const field = document.getElementById('player_id_field');
    field.value = '';
    for (const opt of list.options) {
        if (opt.value === input.value) { field.value = opt.dataset.id; break; }
    }
}
document.querySelector('input[name="player_name_lookup"]')?.closest('form')?.addEventListener('submit', function(e){
    if (!document.getElementById('player_id_field').value) {
        e.preventDefault();
        alert('Pick a player from the suggestions list.');
    }
});

async function doTiebreak(playerIds){
    if (!confirm('Draw a one-off tiebreaker game between these tied players?')) return;
    try {
        await callApi('tiebreak', { tournament_id: TID, player_ids: playerIds });
        alert('Tiebreaker game drawn — check Live Courts below.');
        loadLive();
    } catch (e) { alert(e.message); }
}

async function callApi(action, body){
    const res = await fetch(`${APP_URL}/api/open_play.php?action=${action}`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
    });
    const data = await res.json();
    if (!data.success) {
        const err = new Error(data.message || 'Request failed.');
        err.needsConfirmation = !!(data.errors && data.errors.needs_confirmation);
        throw err;
    }
    return data.data;
}

// ── Live court cards ──────────────────────────────────────
let liveMatchesById = {};

function teamLabel(m, side){
    return [m[side+'p1_name'], m[side+'p2_name']].filter(Boolean).join(' & ') || 'Team';
}

function courtCard(m){
    liveMatchesById[m.id] = m;
    const t1 = [m.t1p1_name, m.t1p2_name].filter(Boolean).map(esc).join(' & ');
    const t2 = [m.t2p1_name, m.t2p2_name].filter(Boolean).map(esc).join(' & ');
    let controls = '';
    if (m.status === 'ready') {
        controls = `<button class="btn btn-sm btn-primary" onclick="doStart(${m.id})">▶ Start</button>`;
    } else if (m.status === 'in_progress') {
        controls = `
            <button class="btn btn-sm" onclick="doPause(${m.id})">⏸ Pause</button>
            <button class="btn btn-sm" onclick="doAdjust(${m.id},-60)">−1m</button>
            <button class="btn btn-sm" onclick="doAdjust(${m.id},60)">+1m</button>
            <button class="btn btn-sm btn-primary" onclick="doFinish(${m.id})">🏁 Finish</button>`;
    } else if (m.status === 'paused') {
        controls = `<button class="btn btn-sm btn-primary" onclick="doResume(${m.id})">▶ Resume</button>
            <button class="btn btn-sm btn-primary" onclick="doFinish(${m.id})">🏁 Finish</button>`;
    }
    return `<div class="card" style="padding:14px;">
        <div style="display:flex;justify-content:space-between;color:var(--muted);font-size:12px;text-transform:uppercase;">
            <span>${esc(m.court_name || 'Unassigned')}</span>
            <span>${m.status === 'ready' ? 'Ready' : fmtTime(m.time_left)}</span>
        </div>
        <div style="margin-top:8px;font-weight:700;">${t1} <span style="color:var(--muted);font-weight:400;">vs</span> ${t2}</div>
        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">${controls}</div>
    </div>`;
}

async function loadLive(){
    try {
        const res = await fetch(`${APP_URL}/api/open_play_kiosk.php?tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        if (!data.success) return;
        const all = [...data.data.now_playing, ...data.data.up_next];
        liveMatchesById = {};
        const el = document.getElementById('liveCourts');
        el.innerHTML = all.length ? all.map(courtCard).join('') : '<p class="text-muted">No games in progress. Click "Draw Next Round" to get started.</p>';
    } catch (e) {}
}

async function doStart(id){ try { await callApi('start_match', {match_id:id}); loadLive(); } catch(e){ alert(e.message); } }
async function doPause(id){ try { await callApi('pause_match', {match_id:id}); loadLive(); } catch(e){ alert(e.message); } }
async function doResume(id){ try { await callApi('resume_match', {match_id:id}); loadLive(); } catch(e){ alert(e.message); } }
async function doAdjust(id, delta){ try { await callApi('adjust_timer', {match_id:id, delta_seconds:delta}); loadLive(); } catch(e){ alert(e.message); } }

// ── Score modal (finish + correct share the same UI) ──────
let scoreModalCtx = null;

function openScoreModal(matchId, action, teamAName, teamBName, defaultA, defaultB){
    scoreModalCtx = { matchId, action, confirmed: false };
    document.getElementById('scoreModalTitle').textContent = action === 'finish_match' ? '🏁 Finish Match' : '✏️ Correct Score';
    document.getElementById('scoreModalTeamA').textContent = teamAName || 'Team A';
    document.getElementById('scoreModalTeamB').textContent = teamBName || 'Team B';
    document.getElementById('scoreModalInputA').value = defaultA ?? '';
    document.getElementById('scoreModalInputB').value = defaultB ?? '';
    document.getElementById('scoreModalWarning').style.display = 'none';
    document.getElementById('scoreModalSaveBtn').textContent = 'Save';
    document.getElementById('scoreModal').style.display = 'flex';
    document.getElementById('scoreModalInputA').focus();
}
function closeScoreModal(){
    document.getElementById('scoreModal').style.display = 'none';
    scoreModalCtx = null;
}

document.getElementById('scoreModalSaveBtn').addEventListener('click', async () => {
    if (!scoreModalCtx) return;
    const a = parseInt(document.getElementById('scoreModalInputA').value, 10);
    const b = parseInt(document.getElementById('scoreModalInputB').value, 10);
    if (isNaN(a) || isNaN(b)) { alert('Enter both scores.'); return; }

    try {
        await callApi(scoreModalCtx.action, {
            match_id: scoreModalCtx.matchId, score_a: a, score_b: b,
            confirmed: scoreModalCtx.confirmed,
        });
        closeScoreModal();
        loadLive();
        location.reload(); // refresh standings/recently-finished sections below
    } catch (e) {
        if (e.needsConfirmation) {
            const warn = document.getElementById('scoreModalWarning');
            warn.textContent = '⚠️ ' + e.message;
            warn.style.display = 'block';
            document.getElementById('scoreModalSaveBtn').textContent = 'Save Anyway';
            scoreModalCtx.confirmed = true;
        } else {
            alert(e.message);
        }
    }
});

function doFinish(id){
    const m = liveMatchesById[id];
    openScoreModal(id, 'finish_match', m ? teamLabel(m, 't1') : 'Team A', m ? teamLabel(m, 't2') : 'Team B');
}
function doCorrect(btn){
    const { id, t1, t2, a, b } = btn.dataset;
    openScoreModal(parseInt(id, 10), 'correct_score', t1, t2, parseInt(a, 10), parseInt(b, 10));
}

// ── Spin wheel draw ────────────────────────────────────────
function spinWheel(canvas, durationMs){
    return new Promise(resolve => {
        const ctx = canvas.getContext('2d');
        const cx = canvas.width/2, cy = canvas.height/2, r = 150;
        const colors = ['#00e5a0','#00aaff','#f59e0b','#ef4444','#5eead4','#a78bfa'];
        let angle = 0;
        const start = performance.now();
        function frame(now){
            const t = Math.min(1, (now-start)/durationMs);
            const ease = 1 - Math.pow(1-t, 3);
            angle = ease * Math.PI * 10;
            ctx.clearRect(0,0,canvas.width,canvas.height);
            ctx.save();
            ctx.translate(cx,cy); ctx.rotate(angle);
            const slices = 8;
            for (let i=0;i<slices;i++){
                ctx.beginPath();
                ctx.moveTo(0,0);
                ctx.arc(0,0,r, i*(2*Math.PI/slices), (i+1)*(2*Math.PI/slices));
                ctx.fillStyle = colors[i % colors.length];
                ctx.globalAlpha = 0.85;
                ctx.fill();
            }
            ctx.restore();
            ctx.fillStyle = '#e9eef7';
            ctx.beginPath(); ctx.arc(cx,cy,14,0,2*Math.PI); ctx.fill();
            if (t < 1) requestAnimationFrame(frame); else resolve();
        }
        requestAnimationFrame(frame);
    });
}

document.getElementById('drawBtn').addEventListener('click', async () => {
    const drawBtn = document.getElementById('drawBtn');
    drawBtn.disabled = true;
    const overlay = document.getElementById('wheelOverlay');
    const canvas  = document.getElementById('wheelCanvas');
    const status  = document.getElementById('wheelStatus');
    const results = document.getElementById('wheelResults');
    const closeBtn= document.getElementById('wheelClose');
    overlay.style.display = 'flex';
    status.textContent = 'Drawing…';
    results.innerHTML = '';
    closeBtn.style.display = 'none';

    let games = [];
    let error = null;
    const spin = spinWheel(canvas, 2200);
    try {
        games = await callApi('draw', {tournament_id: TID});
    } catch (e) { error = e.message; }
    await spin;

    if (error) {
        status.textContent = '⚠️ ' + error;
    } else if (!games.length) {
        status.textContent = 'Not enough players waiting (or no free courts) for a new game.';
    } else {
        status.textContent = `🎉 ${games.length} game${games.length>1?'s':''} drawn!`;
        results.innerHTML = games.map(m => {
            const t1 = [m.t1p1_name, m.t1p2_name].filter(Boolean).map(esc).join(' & ');
            const t2 = [m.t2p1_name, m.t2p2_name].filter(Boolean).map(esc).join(' & ');
            return `<div style="margin:6px 0;">🏓 <b>${esc(m.court_name || 'Court TBD')}</b>: ${t1} vs ${t2}</div>`;
        }).join('');
    }
    closeBtn.style.display = 'inline-block';
    loadLive();
    drawBtn.disabled = false;
});
document.getElementById('wheelClose').addEventListener('click', () => {
    document.getElementById('wheelOverlay').style.display = 'none';
});

loadLive();
setInterval(loadLive, 6000);
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
