<?php
// ============================================================
//  FILE: staff/open_play_kiosk.php
//  Open Play TV/kiosk display — sibling to
//  admin/kiosk_tournament.php but for random-pairing open play
//  events: Now Playing tiles per court (with live countdown),
//  an Up Next strip, and a scrolling Waiting Pool ticker.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';
requireStaff();

$tid = (int)($_GET['tournament_id'] ?? 0);
if (!$tid) { redirect('staff/open_play_control.php'); }

$engine = new OpenPlayEngine();
$event  = $engine->getEvent($tid);
if (!$event) { redirect('staff/open_play_control.php'); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>🎲 <?= clean($event['name']) ?> — Open Play — Falcon Pickleball</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;700;800&display=swap" rel="stylesheet"/>
<style nonce="<?= clean(getCspNonce()) ?>">
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#050d12; --surface:#0a1520; --surface2:#0f1e2b; --border:#1a2a35; --text:#f0fdf4;
  --muted:#64748b; --accent:#00e5a0; --accent2:#00aaff; --warn:#f59e0b; --p1:#00aaff; --p2:#f59e0b;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',system-ui,sans-serif;overflow:hidden;}
body{display:flex;flex-direction:column;min-height:100vh;}

#topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 32px;
  border-bottom:1px solid var(--border);background:var(--surface);flex-shrink:0;}
#topbar h1{font-family:'Bebas Neue',sans-serif;font-size:28px;letter-spacing:1px;color:var(--accent);}
#topbar .sub{color:var(--muted);font-size:13px;margin-top:2px;}
#topbar a{color:var(--muted);text-decoration:none;font-size:13px;border:1px solid var(--border);
  padding:8px 14px;border-radius:8px;}
#topbar a:hover{color:var(--text);}

#main{flex:1;display:flex;overflow:hidden;}
#courts{flex:1;display:grid;gap:14px;padding:16px;overflow:auto;align-content:start;}
#side{width:300px;border-left:1px solid var(--border);background:var(--surface);display:flex;flex-direction:column;overflow:hidden;flex-shrink:0;}
#side h2{padding:16px 18px 8px;font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:1px;color:var(--muted);}
#upnext{padding:0 12px;display:flex;flex-direction:column;gap:8px;overflow:auto;}
#pool{border-top:1px solid var(--border);flex:1;display:flex;flex-direction:column;overflow:hidden;}
#poolcount{padding:14px 18px 4px;font-family:'Bebas Neue',sans-serif;font-size:40px;color:var(--accent);}
#poollist{padding:0 18px 16px;overflow:auto;color:var(--muted);font-size:13px;line-height:1.9;}

.tile{background:var(--surface2);border:1px solid var(--border);border-radius:16px;padding:18px 20px;
  display:flex;flex-direction:column;justify-content:space-between;min-height:200px;}
.tile .court{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;display:flex;justify-content:space-between;}
.tile .timer{font-family:'Bebas Neue',sans-serif;color:var(--accent);}
.tile .timer.low{color:var(--warn);}
.tile .matchup{display:flex;justify-content:space-between;align-items:center;margin-top:10px;}
.tile .team{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;}
.tile .team .nm{font-size:15px;font-weight:700;text-align:center;line-height:1.3;}
.tile .team .sc{font-family:'Bebas Neue',sans-serif;font-size:44px;line-height:1;}
.tile .team.a .sc{color:var(--p1);} .tile .team.b .sc{color:var(--p2);}
.tile .vs{color:var(--muted);font-size:12px;padding:0 8px;}
.tile.paused{opacity:.6;}
.tile .paused-badge{font-size:11px;color:var(--warn);text-transform:uppercase;letter-spacing:1px;margin-top:6px;text-align:center;}

.upcard{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:13px;}
.upcard .lbl{color:var(--muted);font-size:11px;text-transform:uppercase;}
.upcard .names{margin-top:4px;line-height:1.5;}

#empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  color:var(--muted);text-align:center;gap:10px;}
#empty-state .big{font-size:60px;}
</style>
</head>
<body>

<div id="topbar">
  <div>
    <h1>🎲 <?= clean($event['name']) ?></h1>
    <div class="sub">Open Play — Skill-Balanced Random Pairing</div>
  </div>
  <a href="<?= APP_URL ?>/staff/open_play_control.php?tournament_id=<?= (int)$tid ?>">← Staff Controls</a>
</div>

<div id="main">
  <div id="empty-state">
    <div class="big">🏓</div>
    <div>No games are live right now.</div>
  </div>
  <div id="courts" style="display:none;"></div>
  <div id="side">
    <h2>Up Next</h2>
    <div id="upnext"></div>
    <div id="pool">
      <div id="poolcount">0</div>
      <div style="padding:0 18px;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:1px;">Waiting</div>
      <div id="poollist"></div>
    </div>
  </div>
</div>

<script nonce="<?= getCspNonce() ?>">
const APP_URL = '<?= APP_URL ?>';
const TID = <?= (int)$tid ?>;
let serverOffset = 0;

function esc(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function fmtTime(sec){
  sec = Math.max(0, sec|0);
  const m = Math.floor(sec/60), s = sec%60;
  return m + ':' + String(s).padStart(2,'0');
}

// ── Chime + flash when a new game posts, so staff/players at ─
// the venue notice without staring at the screen. Silent no-op
// on browsers that block autoplay audio until first interaction.
let knownMatchIds = new Set();
let audioCtx = null;
function chime(){
  try {
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    [0, 0.14].forEach((delay, i) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.value = i === 0 ? 880 : 1175;
      gain.gain.setValueAtTime(0.001, audioCtx.currentTime + delay);
      gain.gain.exponentialRampToValueAtTime(0.2, audioCtx.currentTime + delay + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + delay + 0.35);
      osc.connect(gain); gain.connect(audioCtx.destination);
      osc.start(audioCtx.currentTime + delay);
      osc.stop(audioCtx.currentTime + delay + 0.4);
    });
  } catch (e) {}
}
function flashScreen(){
  document.body.style.transition = 'none';
  document.body.style.boxShadow = 'inset 0 0 0 8px var(--accent)';
  requestAnimationFrame(() => {
    document.body.style.transition = 'box-shadow 1.2s ease-out';
    document.body.style.boxShadow = 'inset 0 0 0 0px var(--accent)';
  });
}
function teamName(m, side){
  const a = m[side+'p1_name'] ? esc(m[side+'p1_name']) : '';
  const b = m[side+'p2_name'] ? ' & ' + esc(m[side+'p2_name']) : '';
  return (a || 'Player') + b;
}

function courtTile(m){
  const paused = m.status === 'paused';
  const left = Math.max(0, (m.time_left|0) - (paused ? 0 : 0));
  const low = left <= 60;
  return `
    <div class="tile ${paused ? 'paused' : ''}">
      <div class="court"><span>${esc(m.court_name || 'Court')}</span><span class="timer ${low?'low':''}">${fmtTime(m.time_left)}</span></div>
      <div class="matchup">
        <div class="team a"><div class="nm">${teamName(m,'t1')}</div></div>
        <div class="vs">VS</div>
        <div class="team b"><div class="nm">${teamName(m,'t2')}</div></div>
      </div>
      ${paused ? '<div class="paused-badge">⏸ Paused</div>' : ''}
    </div>`;
}

function upnextCard(m){
  return `<div class="upcard">
    <div class="lbl">Round ${m.round_number}</div>
    <div class="names">${teamName(m,'t1')} <span style="color:var(--muted)">vs</span> ${teamName(m,'t2')}</div>
  </div>`;
}

function render(d){
  const courts = document.getElementById('courts');
  const empty  = document.getElementById('empty-state');

  const currentIds = new Set([...(d.now_playing||[]), ...(d.up_next||[])].map(m => m.id));
  const isFirstLoad = knownMatchIds.size === 0;
  let hasNew = false;
  currentIds.forEach(id => { if (!knownMatchIds.has(id)) hasNew = true; });
  if (hasNew && !isFirstLoad) { chime(); flashScreen(); }
  knownMatchIds = currentIds;

  if (!d.now_playing || d.now_playing.length === 0) {
    courts.style.display = 'none';
    empty.style.display = 'flex';
  } else {
    empty.style.display = 'none';
    courts.style.display = 'grid';
    const n = d.now_playing.length;
    const cols = n <= 1 ? 1 : (n <= 4 ? 2 : (n <= 6 ? 3 : 4));
    courts.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
    courts.innerHTML = d.now_playing.map(courtTile).join('');
  }

  document.getElementById('upnext').innerHTML =
    (d.up_next && d.up_next.length) ? d.up_next.map(upnextCard).join('') : '<div style="color:var(--muted);font-size:12px;padding:8px 12px;">Nothing queued yet.</div>';

  document.getElementById('poolcount').textContent = d.waiting_count ?? 0;
  document.getElementById('poollist').innerHTML =
    (d.waiting_pool || []).map(p => esc(p.display_name || p.full_name)).join(' · ') || '—';
}

function fetchData(){
  fetch(`${APP_URL}/api/open_play_kiosk.php?tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.json())
    .then(res => { if (res.success) render(res.data); })
    .catch(() => {});
}

fetchData();
setInterval(fetchData, 4000); // slightly faster than the bracket kiosk since timers are running
</script>
</body>
</html>
