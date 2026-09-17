<?php
// ============================================================
//  FILE: admin/kiosk_tournament.php
//  Kiosk — Tournament Mode tab.
//  Separate view from the walk-in queue kiosk (kiosk.php). Only
//  shows tiles for courts actually running a tournament match —
//  court count is never hardcoded, it's read live from
//  kiosk_tournament_data.php. Semifinal/Championship matches get
//  a distinct full-screen "big moment" layout.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$pageTitle = 'Tournament Kiosk';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>🏆 Tournament Mode — Falcon Pickleball</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;700;800&display=swap" rel="stylesheet"/>
<style nonce="<?= clean(getCspNonce()) ?>">
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#050d12; --surface:#0a1520; --border:#1a2a35; --text:#f0fdf4;
  --muted:#64748b; --accent:#00e5a0; --p1:#00aaff; --p2:#f59e0b;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',system-ui,sans-serif;overflow:hidden;}
body{display:flex;flex-direction:column;min-height:100vh;}

#topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 32px;
  border-bottom:1px solid var(--border);background:var(--surface);flex-shrink:0;}
#topbar h1{font-family:'Bebas Neue',sans-serif;font-size:28px;letter-spacing:1px;color:var(--accent);}
#topbar a{color:var(--muted);text-decoration:none;font-size:13px;border:1px solid var(--border);
  padding:8px 14px;border-radius:8px;}
#topbar a:hover{color:var(--text);}

#empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  color:var(--muted);text-align:center;gap:10px;}
#empty-state .big{font-size:60px;}

#grid{flex:1;display:grid;gap:12px;padding:16px;overflow:auto;}

.tile{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:18px 20px;
  display:flex;flex-direction:column;justify-content:space-between;min-height:220px;}
.tile .court{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
.tile .round{font-size:13px;color:var(--accent);font-weight:700;margin-top:2px;}
.tile .matchup{display:flex;justify-content:space-between;align-items:center;margin-top:10px;}
.tile .team{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;}
.tile .team .nm{font-size:16px;font-weight:700;text-align:center;display:flex;align-items:center;gap:6px;}
.tile .team .sc{font-family:'Bebas Neue',sans-serif;font-size:48px;line-height:1;}
.tile .team.p1 .sc{color:var(--p1);} .tile .team.p2 .sc{color:var(--p2);}
.tile .vs{color:var(--muted);font-size:12px;padding:0 8px;}
.serve-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 6px var(--accent);}
.tile .games{margin-top:10px;font-size:12px;color:var(--muted);text-align:center;}

/* Big-moment full-screen layout for semifinal/championship */
.tile.big{grid-column:1 / -1;grid-row:span 2;justify-content:center;align-items:center;
  background:radial-gradient(circle at 50% 0%, rgba(0,229,160,0.12), var(--surface));
  border-color:rgba(0,229,160,0.5);}
.tile.big .round{font-size:22px;letter-spacing:3px;text-transform:uppercase;}
.tile.big .matchup{width:100%;max-width:900px;margin-top:20px;}
.tile.big .team .nm{font-size:clamp(20px,3vw,34px);}
.tile.big .team .sc{font-size:clamp(90px,14vw,180px);}
.tile.big .games{font-size:16px;margin-top:20px;}
</style>
</head>
<body>

<div id="topbar">
  <h1>🏆 Tournament Mode</h1>
  <a href="<?= APP_URL ?>/admin/kiosk.php">← Back to Court Kiosk</a>
</div>

<div id="empty-state">
  <div class="big">🏓</div>
  <div>No tournament matches are live right now.</div>
</div>

<div id="grid" style="display:none;"></div>

<script nonce="<?= getCspNonce() ?>">
const APP_URL = '<?= APP_URL ?>';
let poll = null;

function esc(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function tileHTML(c){
  const big = c.is_big_moment;
  const s1serving = c.current_server === 1;
  const s2serving = c.current_server === 2;
  const gamesLine = `Game ${c.current_game} · Best of ${(c.games_to_win*2)-1} · Games ${c.games_won_p1}–${c.games_won_p2}`;
  return `
    <div class="tile ${big ? 'big' : ''}">
      <div class="court">${esc(c.court_name)}${c.referee_name ? ' · Ref: ' + esc(c.referee_name) : ''}</div>
      <div class="round">${esc(c.round_name)}${big ? ' 🔥' : ''}</div>
      <div class="matchup">
        <div class="team p1">
          <div class="nm">${s1serving ? '<span class="serve-dot"></span>' : ''}${esc(c.p1_name)}</div>
          <div class="sc">${c.score_player1}</div>
        </div>
        <div class="vs">VS</div>
        <div class="team p2">
          <div class="nm">${s2serving ? '<span class="serve-dot"></span>' : ''}${esc(c.p2_name)}</div>
          <div class="sc">${c.score_player2}</div>
        </div>
      </div>
      <div class="games">${gamesLine}</div>
    </div>`;
}

function render(data){
  const grid  = document.getElementById('grid');
  const empty = document.getElementById('empty-state');

  if (!data.courts || data.courts.length === 0) {
    grid.style.display = 'none';
    empty.style.display = 'flex';
    return;
  }
  empty.style.display = 'none';
  grid.style.display = 'grid';

  const n = data.courts.length;
  const cols = n <= 1 ? 1 : (n <= 4 ? 2 : (n <= 6 ? 3 : 4));
  grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;

  grid.innerHTML = data.courts.map(tileHTML).join('');
}

function fetchData(){
  fetch(APP_URL + '/admin/kiosk_tournament_data.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.json())
    .then(render)
    .catch(() => {});
}

fetchData();
poll = setInterval(fetchData, 5000); // same polling cadence as the walk-in kiosk
</script>
</body>
</html>
