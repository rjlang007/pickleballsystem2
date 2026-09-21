<?php
$moduleTitle = $moduleTitle ?? 'Open Play';
$moduleDescription = $moduleDescription ?? '';
$moduleEventId = (int)($selected ?? 0);
?>
<style nonce="<?= getCspNonce() ?>">
.op-module { max-width:72rem; margin:0 auto; color:#fff; font-family:'DM Sans',system-ui,sans-serif; }
.op-module-nav { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:24px; }
.op-module-nav a { color:rgba(255,255,255,.72); border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04); border-radius:8px; padding:8px 12px; font-size:12px; text-decoration:none; }
.op-module-nav a:hover, .op-module-nav a.active { color:#171717; background:#f2c94c; border-color:#f2c94c; }
.op-module-nav .op-module-brand { margin-right:auto; border:0; background:none; color:#f2c94c; font-family:'Rajdhani','DM Sans',sans-serif; font-size:18px; font-weight:700; }
.op-module-kicker { color:#f2c94c; font-size:10px; font-weight:700; letter-spacing:.18em; text-transform:uppercase; }
.op-module h1 { color:#fff; font-family:'Rajdhani','DM Sans',sans-serif; font-size:32px; margin:0 0 6px; }
.op-module-lead { color:rgba(255,255,255,.55); margin:0 0 22px; font-size:14px; }
.op-module-panel { border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04); border-radius:16px; padding:20px; margin-bottom:20px; }
.op-module .opc-btn, .op-module .opc-btn-action, .op-module .opc-btn-danger, .op-module .opc-mini { text-decoration:none; }
.op-module .opc-table { width:100%; border-collapse:collapse; }
.op-module .opc-table th { color:rgba(255,255,255,.45); font-size:10px; letter-spacing:.16em; text-align:left; text-transform:uppercase; padding:10px; }
.op-module .opc-table td { border-top:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,.8); padding:12px 10px; font-size:13px; }
.op-module .opc-field { max-width:100%; }
.op-module .opc-cell-actions { display:flex; flex-wrap:wrap; gap:5px; align-items:center; }
.op-module .opc-add { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.op-module .opc-add .opc-field { flex:1 1 200px; }
.op-module .opc-meta { color:rgba(255,255,255,.45); font-size:11px; letter-spacing:.14em; text-transform:uppercase; }
.op-module .opc-help { color:rgba(255,255,255,.55); font-size:13px; }
@media (max-width:640px) { .op-module-nav .op-module-brand { width:100%; } .op-module-panel { padding:14px; overflow-x:auto; } }
</style>
<div class="op-module">
  <nav class="op-module-nav" aria-label="Open Play modules">
    <a class="op-module-brand" href="<?= APP_URL ?>/staff/open_play_control.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Dink Board · Open Play</a>
    <a href="<?= APP_URL ?>/staff/open_play_control.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Court Control</a>
    <a class="<?= $moduleTitle === 'Registration & Approvals' ? 'active' : '' ?>" href="<?= APP_URL ?>/staff/open_play_registration.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Registration</a>
    <a class="<?= $moduleTitle === 'Leaderboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/staff/open_play_leaderboard.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Leaderboard</a>
    <a class="<?= $moduleTitle === 'Raffles' ? 'active' : '' ?>" href="<?= APP_URL ?>/staff/open_play_raffles.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Raffles</a>
    <a href="<?= APP_URL ?>/staff/open_play_settings.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Settings</a>
  </nav>
  <div class="op-module-kicker">Open Play Module</div>
  <h1><?= clean($moduleTitle) ?></h1>
  <p class="op-module-lead"><?= clean($moduleDescription) ?></p>
