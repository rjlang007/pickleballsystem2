<?php
$moduleTitle = $moduleTitle ?? 'Open Play';
$moduleDescription = $moduleDescription ?? '';
$moduleEventId = (int)($selected ?? 0);
$moduleEventName = $moduleEventName ?? '';
?>
<style nonce="<?= getCspNonce() ?>">
.op-module { max-width:var(--content-max, 1200px); margin:0 auto; color:var(--text); }
.op-module-nav { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-bottom:var(--space-xl, 32px); padding-bottom:12px; border-bottom:1px solid var(--border); }
.op-module-nav a { color:var(--muted); border:1px solid var(--border); background:var(--surface2); border-radius:var(--radius-sm, 8px); padding:8px 12px; font-size:12px; text-decoration:none; }
.op-module-nav a:hover, .op-module-nav a.active { color:var(--text); border-color:var(--accent); background:rgba(0,229,160,.08); }
.op-module-nav .op-module-brand { margin-right:auto; border:0; background:transparent; color:var(--accent); font-family:var(--font-display, 'Bebas Neue', sans-serif); font-size:20px; letter-spacing:.04em; }
.op-module-kicker { color:var(--accent); font-size:10px; font-weight:700; letter-spacing:.18em; text-transform:uppercase; }
.op-module h1 { color:var(--text); font-family:var(--font-display, 'Bebas Neue', sans-serif); font-size:clamp(28px, 4vw, 42px); margin:0 0 6px; }
.op-module-lead { color:var(--muted); margin:0 0 var(--space-xl, 32px); font-size:14px; }
.op-module-panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg, 16px); padding:var(--space-lg, 24px); margin-bottom:var(--space-lg, 24px); }
.op-module-panel:hover { border-color:var(--border2, rgba(255,255,255,.14)); }
.op-module .opc-btn, .op-module .opc-btn-action, .op-module .opc-btn-danger { display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:7px 14px; border:1px solid var(--border); border-radius:var(--radius-sm, 8px); background:var(--surface2); color:var(--text); cursor:pointer; text-decoration:none; font:inherit; font-size:12px; font-weight:600; }
.op-module .opc-btn-action { background:var(--accent); border-color:var(--accent); color:#04251b; }
.op-module .opc-btn-danger { background:var(--danger); border-color:var(--danger); color:#fff; }
.op-module .opc-mini { min-height:30px; padding:5px 10px; border:0; border-radius:6px; background:var(--surface2); color:var(--text); cursor:pointer; font:inherit; font-size:11px; }
.op-module .opc-mini-green { background:rgba(34,197,94,.18); color:#86efac; }
.op-module .opc-mini-red { background:rgba(239,68,68,.15); color:#fca5a5; }
.op-module .opc-table { width:100%; border-collapse:collapse; }
.op-module .opc-table th { color:var(--muted); font-size:10px; letter-spacing:.16em; text-align:left; text-transform:uppercase; padding:10px; }
.op-module .opc-table td { border-top:1px solid var(--border); color:var(--text); padding:12px 10px; font-size:13px; }
.op-module .opc-field { max-width:100%; }
.op-module .opc-cell-actions { display:flex; flex-wrap:wrap; gap:5px; align-items:center; }
.op-module .opc-add { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; align-items:end; }
.op-module .opc-add .opc-field { flex:1 1 200px; }
.op-module .opc-meta { color:var(--muted); font-size:11px; letter-spacing:.14em; text-transform:uppercase; }
.op-module .opc-help { color:var(--muted); font-size:13px; }
.op-module .opc-section-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
.op-module .opc-h3 { margin:0; color:var(--text); font-family:var(--font-display, 'Bebas Neue', sans-serif); font-size:20px; }
.op-module .opc-notice { padding:12px 14px; border:1px solid var(--border); border-radius:var(--radius-sm, 8px); background:var(--surface2); color:var(--text); margin-bottom:12px; }
@media (max-width:640px) { .op-module-nav .op-module-brand { width:100%; } .op-module-panel { padding:var(--space-md, 16px); overflow-x:auto; } .op-module .opc-section-head { align-items:flex-start; flex-direction:column; } }
</style>
<div class="op-module">
  <nav class="op-module-nav" aria-label="Open Play modules">
    <a class="op-module-brand" href="<?= APP_URL ?>/staff/open_play_control.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Dink Board · Open Play</a>
    <a href="<?= APP_URL ?>/staff/open_play_control.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Court Control</a>
    <a class="<?= $moduleTitle === 'Registration & Approvals' ? 'active' : '' ?>" href="<?= APP_URL ?>/staff/open_play_registration.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Registration</a>
    <a class="<?= $moduleTitle === 'Leaderboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/staff/open_play_leaderboard.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Leaderboard</a>
    <a class="<?= $moduleTitle === 'Raffles' ? 'active' : '' ?>" href="<?= APP_URL ?>/staff/open_play_raffles.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Raffles</a>
    <a href="<?= APP_URL ?>/staff/open_play_settings.php<?= $moduleEventId ? '?tournament_id=' . $moduleEventId : '' ?>">Settings</a>
    <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '#', ENT_QUOTES, 'UTF-8') ?>" title="Reload this module">Refresh</a>
  </nav>
  <div class="op-module-kicker">Open Play Module</div>
  <h1><?= clean($moduleTitle) ?></h1>
  <p class="op-module-lead"><?= clean($moduleDescription) ?></p>
  <?php if ($moduleEventName): ?><div class="card" style="margin-bottom:20px;padding:12px 16px;"><strong><?= clean($moduleEventName) ?></strong><span class="text-muted"> · Event <?= $moduleEventId ?></span></div><?php endif; ?>
