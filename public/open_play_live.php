<?php
// ============================================================
//  FILE: public/open_play_live.php
//  Read-only live board for players — same data as the staff
//  TV kiosk (api/open_play_kiosk.php) but wrapped in the normal
//  site chrome so a player can check it from their phone while
//  waiting their turn.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';

requireLogin();

$tid    = (int)($_GET['tournament_id'] ?? 0);
$engine = new OpenPlayEngine();
$event  = $tid ? $engine->getEvent($tid) : null;
if (!$event) { redirect('public/open_play.php'); }

$pageTitle = $event['name'] . ' — Live Board';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <div>
        <h1 style="margin:0 0 4px;">🎲 <?= clean($event['name']) ?></h1>
        <p style="color:var(--muted);margin:0;">Live board — refreshes automatically.</p>
      </div>
      <a class="btn" href="<?= APP_URL ?>/public/open_play.php">← Back</a>
    </div>

    <div id="myStatus" style="margin:16px 0;"></div>

    <div class="card-title mb-1" style="margin-top:10px;">🏟️ Now Playing</div>
    <hr class="divider"/>
    <div id="nowPlaying" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:20px;"></div>

    <div class="card-title mb-1">⏭️ Up Next</div>
    <hr class="divider"/>
    <div id="upNext" style="margin-bottom:20px;"></div>

    <div class="card-title mb-1">⏳ Waiting Pool (<span id="poolCount">0</span>)</div>
    <hr class="divider"/>
    <div id="pool" style="color:var(--muted);font-size:14px;"></div>
  </div>
</div>

<script nonce="<?= getCspNonce() ?>">
const APP_URL = '<?= APP_URL ?>';
const TID = <?= (int)$tid ?>;
const MY_ID = <?= (int)$_SESSION['user_id'] ?>;

function esc(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function escapeHtml(s){ return esc(String(s ?? '')) }
function fmtTime(sec){ sec = Math.max(0, sec|0); return Math.floor(sec/60) + ':' + String(sec%60).padStart(2,'0'); }
function teamName(m, side){
    const a = m[side+'p1_name'] ? esc(m[side+'p1_name']) : '';
    const b = m[side+'p2_name'] ? ' & ' + esc(m[side+'p2_name']) : '';
    return (a || 'Player') + b;
}
function involvesMe(m){
    return [m.team1_player1_id, m.team1_player2_id, m.team2_player1_id, m.team2_player2_id]
        .some(id => Number(id) === MY_ID);
}

let wasMyTurn = false;
function chimeForMe(){
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator(), gain = ctx.createGain();
        osc.type = 'sine'; osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
        osc.connect(gain); gain.connect(ctx.destination);
        osc.start(); osc.stop(ctx.currentTime + 0.5);
        if (navigator.vibrate) navigator.vibrate([120, 60, 120]);
    } catch (e) {}
}

function courtCard(m){
    const highlight = involvesMe(m);
    return `<div class="card" style="padding:14px;${highlight ? 'border-color:var(--accent);box-shadow:0 0 0 1px var(--accent);' : ''}">
        <div style="display:flex;justify-content:space-between;color:var(--muted);font-size:12px;text-transform:uppercase;">
            <span>${esc(m.court_name || 'Court')}</span><span>${fmtTime(m.time_left)}</span>
        </div>
        <div style="margin-top:8px;font-weight:700;">${teamName(m,'t1')} <span style="color:var(--muted);font-weight:400;">vs</span> ${teamName(m,'t2')}</div>
        ${highlight ? '<div style="color:var(--accent);font-size:12px;margin-top:6px;">🏓 That\'s you!</div>' : ''}
    </div>`;
}

async function load(){
    try {
        const res = await fetch(`${APP_URL}/api/open_play_kiosk.php?tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' });
        const data = await res.json();
        if (!data.success) return;
        const d = data.data;

        document.getElementById('nowPlaying').innerHTML = d.now_playing.length
            ? d.now_playing.map(courtCard).join('')
            : '<p class="text-muted">No games in progress yet.</p>';

        document.getElementById('upNext').innerHTML = d.up_next.length
            ? d.up_next.map(m => `<div style="padding:8px 0;border-bottom:1px solid var(--border);">${teamName(m,'t1')} <span style="color:var(--muted);">vs</span> ${teamName(m,'t2')} ${involvesMe(m) ? `<button class="btn btn-sm btn-primary" onclick="confirmMatch(${Number(m.id)})">I'm here</button>` : ''}</div>`).join('')
            : '<p class="text-muted">Nothing queued.</p>';

        document.getElementById('poolCount').textContent = d.waiting_count;
        const poolHtml = (d.waiting_pool || []).map(p => escapeHtml(p.display_name || p.full_name || 'Player')).join(' · ') || '—';
        document.getElementById('pool').innerHTML = poolHtml;

        const mine = (d.waiting_pool || []).find(p => Number(p.player_id) === MY_ID);
        const playing = d.now_playing.some(involvesMe);
        const upNextMine = d.up_next.some(involvesMe);

        const isMyTurnNow = playing || upNextMine;
        if (isMyTurnNow && !wasMyTurn) chimeForMe();
        wasMyTurn = isMyTurnNow;

        let msg = '';
        if (playing) msg = '🏓 You\'re on court right now — good luck!';
        else if (upNextMine) msg = '⏭️ You\'re drawn for the next available court — get ready!';
        else if (mine) msg = '⏳ You\'re in the waiting pool. Hang tight!';
        document.getElementById('myStatus').innerHTML = msg ? `<div class="alert alert-info">${msg}</div>` : '';
    } catch (e) {}
}

async function confirmMatch(matchId){
    const res = await fetch(`${APP_URL}/api/open_play.php?action=confirm_match`, {
        method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({match_id: matchId})
    });
    const data = await res.json();
    if (data.success) load();
    else alert(data.message || 'Unable to confirm check-in.');
}

load();
setInterval(load, 5000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
