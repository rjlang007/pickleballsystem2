<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../tournament/open_play_engine.php';
requireStaff();
$engine = new OpenPlayEngine();
$events = $engine->listEvents();
$selected = (int)($_GET['tournament_id'] ?? 0);
if (!$selected) foreach ($events as $item) { if (in_array($item['status'], ['registration_open','in_progress','paused','completed'], true)) { $selected = (int)$item['id']; break; } }
$event = $selected ? $engine->getEvent($selected) : null;
$standings = $event ? $engine->computeLeaderboard($selected) : [];
$ties = $event ? $engine->detectPodiumTies($standings) : [];
$pageTitle = 'Open Play Leaderboard';
require_once __DIR__ . '/../includes/header.php';
$moduleTitle = 'Leaderboard';
$moduleDescription = 'Track wins, losses, win rate, and point difference for the selected Open Play event.';
$moduleEventName = $event['name'] ?? '';
require __DIR__ . '/open_play_module_header.php';
?>
<div class="op-module-panel"><form method="GET" class="opc-add"><label class="opc-help" for="leaderboardEvent">Event</label><select id="leaderboardEvent" name="tournament_id" class="opc-field" data-autosubmit><option value="">Select an event</option><?php foreach ($events as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $selected === (int)$item['id'] ? 'selected' : '' ?>><?= clean($item['name']) ?> · <?= clean(str_replace('_',' ',$item['status'])) ?></option><?php endforeach; ?></select></form></div>
<?php if (!$event): ?><div class="op-module-panel"><p class="opc-help">Select an Open Play event to view its leaderboard.</p></div><?php else: ?>
<div class="op-module-panel"><div class="opc-section-head"><h2 class="opc-h3"><?= clean($event['name']) ?></h2><span class="op-module-meta"><?= count($standings) ?> ranked</span></div><?php foreach ($ties as $tie): ?><div class="opc-notice opc-notice-warn">Tie for <?= $tie['rank'] == 1 ? '1st' : ($tie['rank'] == 2 ? '2nd' : '3rd') ?> place.</div><?php endforeach; ?><div class="opc-table-wrap"><table class="opc-table"><thead><tr><th>#</th><th>Player</th><th>Games</th><th>W</th><th>L</th><th>Win %</th><th>Point Diff</th></tr></thead><tbody><?php foreach ($standings as $index => $row): ?><tr><td><?= $index + 1 ?></td><td><?= clean($row['display_name'] ?? $row['full_name']) ?></td><td><?= (int)$row['games_played'] ?></td><td><?= (int)$row['wins'] ?></td><td><?= (int)$row['losses'] ?></td><td><?= clean((string)$row['win_pct']) ?>%</td><td><?= ((int)$row['point_diff'] > 0 ? '+' : '') . (int)$row['point_diff'] ?></td></tr><?php endforeach; if (!$standings): ?><tr><td colspan="7">Standings appear after the first finished game.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?></div><?php require_once __DIR__ . '/../includes/footer.php'; ?>
