<?php
// ============================================================
//  FILE: staff/open_play_kiosk.php
//  THE official Open Play TV kiosk. This is the only kiosk in
//  the system — admin/kiosk.php now redirects here so there is a
//  single queue board fed by a single source of truth
//  (api/open_play_kiosk.php → OpenPlayEngine::getKioskData()).
//
//  Shows: live games per court with match timers, the players on
//  each court, paused/unavailable courts, who is up next, the
//  waiting queue with its count and estimated waits, and clean
//  empty states when the floor is quiet.
//
//  tournament_id is optional — with none supplied the kiosk locks
//  onto tonight's active event, so a TV can be pointed at this URL
//  once and left alone.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';
requireStaff();

$engine = new OpenPlayEngine();

$tid = (int)($_GET['tournament_id'] ?? 0);
if (!$tid) {
    $tid = $engine->getActiveEventId();   // 0 = nothing running; render the idle board
}

$event = $tid ? $engine->getEvent($tid) : null;
if ($tid && !$event) { redirect('staff/open_play_control.php'); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>🎲 Open Play Live — <?= $event ? clean($event['name']) : 'Padol Pickleball' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;700;800&display=swap" rel="stylesheet"/>
<style nonce="<?= clean(getCspNonce()) ?>">
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  /* Same palette as the rest of the app (assets/css/app.css). --muted is lifted from #8695ad
     so secondary text stays readable on a TV from across the courts. */
  --bg:#090d18; --surface:#111827; --surface2:#1a2332; --border:#26364f; --text:#e9eef7;
  --muted:#a3b1c6; --accent:#00e5a0; --accent2:#00aaff; --warn:#f59e0b; --danger:#ef4444;
  --p1:#00aaff; --p2:#f59e0b;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',system-ui,sans-serif;overflow:hidden;}
body{display:flex;flex-direction:column;min-height:100vh;}

/* ── Open Play branding bar ── */
#topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:14px 32px;
  border-bottom:1px solid var(--border);background:var(--surface);flex-shrink:0;}
#brand{display:flex;align-items:center;gap:14px;min-width:0;}
#brand .mark{font-family:'Bebas Neue',sans-serif;font-size:26px;letter-spacing:2px;color:#041014;
  background:var(--accent);padding:4px 14px;border-radius:8px;white-space:nowrap;}
#brand h1{font-family:'Bebas Neue',sans-serif;font-size:30px;letter-spacing:1px;color:var(--text);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
#brand .sub{color:var(--muted);font-size:12px;letter-spacing:1px;text-transform:uppercase;margin-top:1px;}
#topmeta{display:flex;align-items:center;gap:22px;flex-shrink:0;}
.metric{text-align:center;}
.metric .n{font-family:'Bebas Neue',sans-serif;font-size:34px;line-height:1;color:var(--accent);}
.metric .l{color:var(--muted);font-size:10px;letter-spacing:1px;text-transform:uppercase;margin-top:2px;}
#clockbox{text-align:right;min-width:150px;}
#clock{font-family:'Bebas Neue',sans-serif;font-size:38px;color:var(--text);line-height:1;letter-spacing:1px;
  font-variant-numeric:tabular-nums;white-space:nowrap;}
#clock .ampm{font-size:18px;color:var(--accent);margin-left:5px;letter-spacing:1px;}
#clock .sec{color:var(--accent);}
#clockdate{color:var(--muted);font-size:12px;letter-spacing:1px;text-transform:uppercase;margin-top:3px;white-space:nowrap;}
#topbar a.back{color:var(--muted);text-decoration:none;font-size:12px;border:1px solid var(--border);
  padding:8px 13px;border-radius:8px;white-space:nowrap;}
#topbar a.back:hover{color:var(--text);}
#pausebanner{display:none;background:var(--warn);color:#2a1a00;font-weight:800;text-align:center;
  padding:7px;font-size:14px;letter-spacing:1px;text-transform:uppercase;flex-shrink:0;}

#main{flex:1;display:flex;overflow:hidden;}
#courts{flex:1;display:grid;gap:14px;padding:16px;overflow:auto;align-content:start;}
#side{width:320px;border-left:1px solid var(--border);background:var(--surface);display:flex;
  flex-direction:column;overflow:hidden;flex-shrink:0;}
#side h2{padding:15px 18px 7px;font-family:'Bebas Neue',sans-serif;font-size:17px;letter-spacing:1.5px;
  color:var(--muted);display:flex;justify-content:space-between;align-items:baseline;}
#side h2 .pill{font-family:'DM Sans',sans-serif;font-size:11px;font-weight:700;color:var(--accent);
  background:rgba(0,229,160,.12);border-radius:20px;padding:2px 9px;letter-spacing:0;}
#upnext{padding:0 12px 6px;display:flex;flex-direction:column;gap:8px;overflow:auto;max-height:38%;}
#queue{border-top:1px solid var(--border);flex:1;display:flex;flex-direction:column;overflow:hidden;}
#queuehead{display:flex;align-items:flex-end;gap:12px;padding:12px 18px 2px;}
#queuecount{font-family:'Bebas Neue',sans-serif;font-size:52px;line-height:.85;color:var(--accent);}
#queuehead .lbl{color:var(--muted);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;padding-bottom:5px;}
#queuewait{padding:2px 18px 8px;color:var(--warn);font-size:12px;font-weight:700;}
#queuelist{padding:0 12px 16px;overflow:auto;display:flex;flex-direction:column;gap:5px;}
.qrow{display:flex;align-items:center;gap:9px;background:var(--surface2);border:1px solid var(--border);
  border-radius:8px;padding:7px 10px;font-size:13px;}
.qrow .pos{font-family:'Bebas Neue',sans-serif;font-size:17px;color:var(--muted);min-width:24px;}
.qrow .nm{flex:1;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.qrow .eta{color:var(--muted);font-size:11px;white-space:nowrap;}
.qrow.next{border-color:var(--accent);}
.qrow.next .pos{color:var(--accent);}

/* ── Court tiles ── */
.tile{background:var(--surface2);border:1px solid var(--border);border-radius:16px;padding:16px 18px;
  display:flex;flex-direction:column;justify-content:space-between;min-height:190px;}
.tile .court{display:flex;justify-content:space-between;align-items:center;gap:10px;}
.tile .court .cn{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tile .timer{font-family:'Bebas Neue',sans-serif;font-size:30px;line-height:1;color:var(--accent);}
.tile .timer.low{color:var(--warn);}
.tile .timer.out{color:var(--danger);}
.tile .matchup{display:flex;justify-content:space-between;align-items:center;margin-top:12px;flex:1;}
.tile .team{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;min-width:0;}
.tile .team .nm{font-size:16px;font-weight:700;text-align:center;line-height:1.35;}
.tile .vs{color:var(--muted);font-size:12px;padding:0 10px;letter-spacing:1px;}
.tile .badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;
  border-radius:20px;padding:3px 10px;white-space:nowrap;}
.tile.playing .badge{color:var(--accent);background:rgba(0,229,160,.12);}
.tile.paused{border-color:var(--warn);}
.tile.paused .badge{color:var(--warn);background:rgba(245,158,11,.14);}
.tile.paused .timer{color:var(--warn);}
.tile.open{border-style:dashed;}
.tile.open .badge{color:var(--accent2);background:rgba(0,170,255,.12);}
.tile.unavailable{opacity:.45;}
.tile.unavailable .badge{color:var(--muted);background:rgba(100,116,139,.15);}
.tile .idle{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:8px;color:var(--muted);text-align:center;}
.tile .idle .ic{font-size:36px;opacity:.65;}
.tile .idle .tx{font-size:14px;font-weight:700;}
.tile .idle .sx{font-size:12px;}

.upcard{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:9px 12px;font-size:13px;}
.upcard .lbl{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:1.2px;}
.upcard .names{margin-top:4px;line-height:1.5;font-weight:700;}
.sidemsg{color:var(--muted);font-size:12px;padding:8px 14px;line-height:1.6;}

#empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  color:var(--muted);text-align:center;gap:12px;padding:40px;}
#empty-state .big{font-size:72px;}
#empty-state .hl{font-family:'Bebas Neue',sans-serif;font-size:32px;letter-spacing:1px;color:var(--text);}
#offline{position:fixed;bottom:14px;left:14px;display:none;background:var(--danger);color:#fff;
  font-size:12px;font-weight:700;padding:6px 12px;border-radius:20px;}
</style>
</head>
<body>

<div id="topbar">
  <div id="brand">
    <span class="mark">OPEN PLAY</span>
    <div style="min-width:0;">
      <h1 id="event-name"><?= $event ? clean($event['name']) : 'Open Play Live' ?></h1>
      <div class="sub">Live Queue &amp; Court Board — Padol Pickleball</div>
    </div>
  </div>
  <div id="topmeta">
    <div class="metric"><div class="n" id="m-playing">0</div><div class="l">Playing</div></div>
    <div class="metric"><div class="n" id="m-open">0</div><div class="l">Courts Open</div></div>
    <div class="metric"><div class="n" id="m-waiting">0</div><div class="l">In Queue</div></div>
    <div id="clockbox" aria-label="Current time"><div id="clock">--:--:--</div><div id="clockdate">&nbsp;</div></div>
    <a class="back" href="<?= APP_URL ?>/staff/open_play_control.php<?= $tid ? '?tournament_id=' . (int)$tid : '' ?>">← Controls</a>
  </div>
</div>
<div id="pausebanner">⏸ Matchmaking paused — current games will finish</div>

<div id="main">
  <div id="empty-state">
    <div class="big">🎲</div>
    <div class="hl" id="empty-title">Open Play is quiet right now</div>
    <div id="empty-sub">No games are live. Join the queue at the front desk to get on a court.</div>
  </div>
  <div id="courts" style="display:none;"></div>
  <div id="side">
    <h2>Up Next <span class="pill" id="upnext-pill" style="display:none;"></span></h2>
    <div id="upnext"></div>
    <div id="queue">
      <div id="queuehead">
        <div id="queuecount">0</div>
        <div class="lbl">Waiting<br>In Queue</div>
      </div>
      <div id="queuewait"></div>
      <div id="queuelist"></div>
    </div>
  </div>
</div>
<div id="offline">⚠ Live board offline — retrying…</div>

<script nonce="<?= getCspNonce() ?>">
const APP_URL = '<?= APP_URL ?>';
const TID     = <?= (int)$tid ?>;

let latest      = null;   // last payload from the server
let lastSync    = 0;      // performance.now() at last successful fetch
let failures    = 0;

function esc(s){ const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function fmtTime(sec){
  sec = Math.max(0, sec|0);
  const m = Math.floor(sec/60), s = sec%60;
  return m + ':' + String(s).padStart(2,'0');
}
function fmtWait(mins){
  // null = no court can free up (all in maintenance/reserved), so there is
  // no honest number to show.
  if (mins === null || mins === undefined) return 'wait unknown';
  mins = Math.max(0, mins|0);
  if (mins < 1)  return 'next up';
  if (mins < 60) return '~' + mins + ' min';
  const h = Math.floor(mins/60), m = mins%60;
  return '~' + h + 'h' + (m ? ' ' + m + 'm' : '');
}

// ── Chime + flash when a new game posts, so staff and players ──
// notice without staring at the screen. Silent no-op on browsers
// that block autoplay audio until first interaction.
let knownMatchIds = new Set();
let audioCtx = null;
function chime(){
  try {
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    [0, 0.14].forEach((delay, i) => {
      const osc  = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.value = i === 0 ? 880 : 1175;
      gain.gain.setValueAtTime(0.001, audioCtx.currentTime + delay);
      gain.gain.exponentialRampToValueAtTime(0.2,   audioCtx.currentTime + delay + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + delay + 0.35);
      osc.connect(gain); gain.connect(audioCtx.destination);
      osc.start(audioCtx.currentTime + delay);
      osc.stop(audioCtx.currentTime + delay + 0.4);
    });
  } catch (e) {}
}
function flashScreen(){
  document.body.style.transition = 'none';
  document.body.style.boxShadow  = 'inset 0 0 0 8px var(--accent)';
  requestAnimationFrame(() => {
    document.body.style.transition = 'box-shadow 1.2s ease-out';
    document.body.style.boxShadow  = 'inset 0 0 0 0px var(--accent)';
  });
}

function teamName(m, side){
  const a = m[side+'p1_name'] ? esc(m[side+'p1_name']) : '';
  const b = m[side+'p2_name'] ? ' & ' + esc(m[side+'p2_name']) : '';
  return (a || 'Player') + b;
}

// Seconds remaining, ticked locally between polls so the timer
// counts down smoothly instead of jumping every 4 seconds.
function liveSecs(court){
  if (court.state !== 'playing') return court.time_left;
  const drift = (performance.now() - lastSync) / 1000;
  return Math.max(0, (court.time_left|0) - drift);
}

function courtTile(c){
  const cls = 'tile ' + c.state;

  if (c.state === 'playing' || c.state === 'paused' || c.state === 'ready') {
    const secs = liveSecs(c);
    const tcls = secs <= 0 ? 'out' : (secs <= 60 ? 'low' : '');
    const badge = c.state === 'paused' ? '⏸ Paused' : (c.state === 'ready' ? 'Ready' : '● Live');
    return `
      <div class="${cls}" data-court="${c.id}">
        <div class="court">
          <span class="cn">${esc(c.name)}</span>
          <span class="badge">${badge}</span>
        </div>
        <div class="matchup">
          <div class="team a"><div class="nm">${teamName(c.match,'t1')}</div></div>
          <div class="vs">VS</div>
          <div class="team b"><div class="nm">${teamName(c.match,'t2')}</div></div>
        </div>
        <div class="court" style="margin-top:10px;">
          <span class="cn">${c.state === 'paused' ? 'Timer held' : (c.state === 'ready' ? 'Waiting to start' : 'Time left')}</span>
          <span class="timer ${tcls}" data-timer="${c.id}">${fmtTime(secs)}</span>
        </div>
      </div>`;
  }

  if (c.state === 'open') {
    return `
      <div class="${cls}" data-court="${c.id}">
        <div class="court"><span class="cn">${esc(c.name)}</span><span class="badge">Open</span></div>
        <div class="idle">
          <div class="ic">🏓</div>
          <div class="tx">Court available</div>
          <div class="sx">Ready for the next Open Play game</div>
        </div>
      </div>`;
  }

  return `
    <div class="${cls}" data-court="${c.id}">
      <div class="court"><span class="cn">${esc(c.name)}</span><span class="badge">Unavailable</span></div>
      <div class="idle">
        <div class="ic">🚧</div>
        <div class="tx">${esc(c.reason || 'Not in Open Play')}</div>
        <div class="sx">Not accepting queue games right now</div>
      </div>
    </div>`;
}

function upnextCard(m, i){
  return `<div class="upcard">
    <div class="lbl">${i === 0 ? 'On deck' : 'Round ' + (m.round_number ?? '—')} · waiting for a court</div>
    <div class="names">${teamName(m,'t1')} <span style="color:var(--muted);font-weight:400;">vs</span> ${teamName(m,'t2')}</div>
  </div>`;
}

function queueRow(p){
  return `<div class="qrow ${p.position <= (latest?.players_per_game ?? 4) ? 'next' : ''}">
    <span class="pos">${p.position}</span>
    <span class="nm">${esc(p.display_name || p.full_name || 'Player')}</span>
    <span class="eta">${fmtWait(p.est_wait_mins)}</span>
  </div>`;
}

// Cheap per-second re-paint of just the timer text, between polls.
function tickTimers(){
  if (!latest || !latest.courts) return;
  latest.courts.forEach(c => {
    if (c.state !== 'playing') return;
    const el = document.querySelector(`[data-timer="${c.id}"]`);
    if (!el) return;
    const secs = liveSecs(c);
    el.textContent = fmtTime(secs);
    el.className = 'timer ' + (secs <= 0 ? 'out' : (secs <= 60 ? 'low' : ''));
  });
}

function render(d){
  latest = d;

  const courtsEl = document.getElementById('courts');
  const emptyEl  = document.getElementById('empty-state');
  const courts   = d.courts || [];
  const live     = courts.filter(c => c.state === 'playing' || c.state === 'paused');

  // New-game alert
  const currentIds = new Set([...(d.now_playing||[]), ...(d.up_next||[])].map(m => m.id));
  const firstLoad  = knownMatchIds.size === 0;
  let hasNew = false;
  currentIds.forEach(id => { if (!knownMatchIds.has(id)) hasNew = true; });
  if (hasNew && !firstLoad) { chime(); flashScreen(); }
  knownMatchIds = currentIds;

  // Branding / header
  if (d.event) document.getElementById('event-name').textContent = d.event.name;
  document.getElementById('pausebanner').style.display = d.event_paused ? 'block' : 'none';
  document.getElementById('m-playing').textContent = live.length;
  document.getElementById('m-open').textContent    = d.courts_available ?? 0;
  document.getElementById('m-waiting').textContent = d.waiting_count ?? 0;

  // ── Court board ──
  // Only fall back to the full-screen empty state when there is
  // genuinely nothing to show — no event, or no courts at all.
  if (!courts.length) {
    courtsEl.style.display = 'none';
    emptyEl.style.display  = 'flex';
    document.getElementById('empty-title').textContent =
      d.no_event ? 'No Open Play event running' : 'No courts set up for Open Play';
    document.getElementById('empty-sub').textContent =
      d.no_event ? 'Ask the front desk when the next session starts.'
                 : 'Staff can enable courts from Court Settings.';
  } else {
    emptyEl.style.display  = 'none';
    courtsEl.style.display = 'grid';
    const n    = courts.length;
    const cols = n <= 1 ? 1 : (n <= 4 ? 2 : (n <= 6 ? 3 : 4));
    courtsEl.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
    courtsEl.innerHTML = courts.map(courtTile).join('');
  }

  // ── Up next ──
  const up   = d.up_next || [];
  const pill = document.getElementById('upnext-pill');
  pill.style.display = up.length ? 'inline-block' : 'none';
  pill.textContent   = up.length;
  document.getElementById('upnext').innerHTML = up.length
    ? up.map(upnextCard).join('')
    : '<div class="sidemsg">No games drawn yet. The next match appears here as soon as staff draws it.</div>';

  // ── Waiting queue ──
  const pool = d.waiting_pool || [];
  document.getElementById('queuecount').textContent = d.waiting_count ?? 0;

  const waitEl = document.getElementById('queuewait');
  const ests   = pool.map(p => p.est_wait_mins).filter(v => v !== null && v !== undefined);
  if (!pool.length) {
    waitEl.textContent = '';
  } else if (!ests.length) {
    waitEl.textContent = 'No courts available for Open Play right now';
  } else {
    const soonest = Math.min(...ests);
    const longest = Math.max(...ests);
    waitEl.textContent = soonest === longest
      ? 'Estimated wait ' + fmtWait(longest)
      : 'Estimated wait ' + fmtWait(soonest) + ' – ' + fmtWait(longest);
  }

  document.getElementById('queuelist').innerHTML = pool.length
    ? pool.map(queueRow).join('')
    : '<div class="sidemsg">🎉 Queue is empty — walk on and play. Sign in at the desk to be added.</div>';
}

function fetchData(){
  const qs = TID ? ('?tournament_id=' + TID) : '';
  fetch(`${APP_URL}/api/open_play_kiosk.php${qs}`, { credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.json())
    .then(res => {
      if (!res.success) throw new Error(res.error || 'bad response');
      failures = 0;
      lastSync = performance.now();
      document.getElementById('offline').style.display = 'none';
      render(res.data);
    })
    .catch(() => {
      // Keep the last good board on screen; only warn once it's clearly stale.
      if (++failures >= 3) document.getElementById('offline').style.display = 'block';
    });
}

// Live wall clock: hours:minutes:seconds + date, re-scheduled on every real
// second boundary so it never drifts, stalls, or skips a second.
const clockEl = document.getElementById('clock');
const dateEl  = document.getElementById('clockdate');
let lastDateStr = '';
function pad2(n){ return String(n).padStart(2,'0'); }
function tickClock(){
  const d = new Date();
  const h = d.getHours();
  const h12 = (h % 12) || 12;
  clockEl.innerHTML = pad2(h12) + ':' + pad2(d.getMinutes()) +
    '<span class="sec">:' + pad2(d.getSeconds()) + '</span>' +
    '<span class="ampm">' + (h < 12 ? 'AM' : 'PM') + '</span>';
  const ds = d.toDateString();
  if (ds !== lastDateStr) {              // date text only changes at midnight
    lastDateStr = ds;
    dateEl.textContent = d.toLocaleDateString(undefined, {weekday:'short', month:'short', day:'numeric', year:'numeric'});
  }
  setTimeout(tickClock, 1000 - (Date.now() % 1000) + 5);
}

fetchData();
tickClock();
setInterval(fetchData, 4000);   // timers are running — poll faster than the bracket kiosk
setInterval(tickTimers, 1000);
</script>
</body>
</html>
