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
$pageTitle = 'Open Play Standing';
require_once __DIR__ . '/../includes/header.php';
$moduleTitle = 'Standing';
$moduleDescription = 'Track wins, losses, win rate, and point difference for the selected Open Play event.';
$moduleEventName = $event['name'] ?? '';
require __DIR__ . '/open_play_module_header.php';
?>
<div class="op-module-panel"><form method="GET" class="opc-add"><label class="opc-help" for="leaderboardEvent">Event</label><select id="leaderboardEvent" name="tournament_id" class="opc-field" data-autosubmit><option value="">Select an event</option><?php foreach ($events as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $selected === (int)$item['id'] ? 'selected' : '' ?>><?= clean($item['name']) ?> · <?= clean(str_replace('_',' ',$item['status'])) ?></option><?php endforeach; ?></select></form></div>
<?php if (!$event): ?><div class="op-module-panel"><p class="opc-help">Select an Open Play event to view its standing.</p></div><?php else: ?>
<div class="op-module-panel"><div class="opc-section-head"><h2 class="opc-h3"><?= clean($event['name']) ?></h2><span class="op-module-meta" id="rankedCount"><?= count($standings) ?> ranked</span></div><div id="tiesBox"><?php foreach ($ties as $tie): ?><div class="opc-notice opc-notice-warn">Tie for <?= $tie['rank'] == 1 ? '1st' : ($tie['rank'] == 2 ? '2nd' : '3rd') ?> place.</div><?php endforeach; ?></div><div class="opc-table-wrap"><table class="opc-table"><thead><tr><th>#</th><th>Player</th><th>Games</th><th>W</th><th>L</th><th>Win %</th><th>Point Diff</th></tr></thead><tbody id="standingsBody"><?php foreach ($standings as $index => $row): ?><tr><td><?= $index + 1 ?></td><td><?= clean($row['display_name'] ?? $row['full_name']) ?></td><td><?= (int)$row['games_played'] ?></td><td><?= (int)$row['wins'] ?></td><td><?= (int)$row['losses'] ?></td><td><?= clean((string)$row['win_pct']) ?>%</td><td><?= ((int)$row['point_diff'] > 0 ? '+' : '') . (int)$row['point_diff'] ?></td></tr><?php endforeach; if (!$standings): ?><tr><td colspan="7">Standings appear after the first finished game.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?></div>
<?php if ($event): ?>
<script nonce="<?= getCspNonce() ?>">
(function () {
    const APP_URL = window.APP_URL || '<?= APP_URL ?>';
    const TID = <?= (int)$selected ?>;
    const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const ORDINAL = { 1: '1st', 2: '2nd', 3: '3rd' };

    function render(standings, ties) {
        document.getElementById('rankedCount').textContent = `${standings.length} ranked`;
        document.getElementById('tiesBox').innerHTML = (ties || [])
            .map(t => `<div class="opc-notice opc-notice-warn">Tie for ${ORDINAL[t.rank] || t.rank + 'th'} place.</div>`).join('');
        const body = document.getElementById('standingsBody');
        if (!standings.length) {
            body.innerHTML = '<tr><td colspan="7">Standings appear after the first finished game.</td></tr>';
            return;
        }
        body.innerHTML = standings.map((row, i) => {
            const name = escapeHtml(row.display_name || row.full_name);
            const diff = (row.point_diff > 0 ? '+' : '') + row.point_diff;
            return `<tr><td>${i + 1}</td><td>${name}</td><td>${row.games_played|0}</td><td>${row.wins|0}</td><td>${row.losses|0}</td><td>${escapeHtml(String(row.win_pct))}%</td><td>${diff}</td></tr>`;
        }).join('');
    }

    // Standings change every time a game finishes elsewhere (Court Control),
    // so this view polls quietly rather than needing a manual refresh.
    let busy = false;
    async function refresh() {
        if (busy) return;
        busy = true;
        try {
            const [lbRes, tiesRes] = await Promise.all([
                fetch(`${APP_URL}/api/open_play.php?action=leaderboard&tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' }),
                fetch(`${APP_URL}/api/open_play.php?action=ties&tournament_id=${TID}`, { credentials: 'same-origin', cache: 'no-store' }),
            ]);
            const lb = await lbRes.json();
            const ties = await tiesRes.json();
            if (lb.success) render(lb.data, ties.success ? ties.data : []);
        } catch (_) { /* next tick retries */ }
        busy = false;
    }
    setInterval(refresh, 8000);
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
