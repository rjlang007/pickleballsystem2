<?php
// ============================================================
//  FILE: referee/dashboard.php
//  Referee — assigned matches & live scoring entry point
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireReferee();

$db   = getDB();
$user = currentUser();

// Detect whether Phase 2 columns (referee_id / court_id on tournament_matches) exist yet.
$hasRefereeCol = false;
try {
    $chk = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='falcon' AND table_name='tournament_matches' AND column_name='referee_id' LIMIT 1");
    $chk->execute();
    $hasRefereeCol = (bool)$chk->fetchColumn();
} catch (Throwable $e) {
    $hasRefereeCol = false;
}

$myMatches = [];
if ($hasRefereeCol) {
    try {
        $stmt = $db->prepare("
            SELECT tm.id, tm.bracket_round AS round, tm.status,
                   tm.score_player1 AS score1, tm.score_player2 AS score2,
                   c.name AS court_name,
                   t.name AS tournament_name,
                   p1.full_name AS p1_name, p2.full_name AS p2_name
            FROM falcon.tournament_matches tm
            JOIN falcon.tournaments t ON t.id = tm.tournament_id
            LEFT JOIN falcon.users p1 ON p1.id = tm.player1_id
            LEFT JOIN falcon.users p2 ON p2.id = tm.player2_id
            LEFT JOIN falcon.courts c ON c.id = tm.court_id
            WHERE tm.referee_id = ? AND tm.status IN ('pending','in_progress')
            ORDER BY tm.bracket_round ASC
        ");
        $stmt->execute([(int)$user['id']]);
        $myMatches = $stmt->fetchAll();
    } catch (Throwable $e) {
        $myMatches = [];
    }
}

$pageTitle = 'Referee Console';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🧑‍⚖️ Referee Console</h1>
    <p>Hi <?= clean($user['full_name'] ?: $user['username']) ?> — your assigned matches appear here.</p>
</div>

<?php if (!$hasRefereeCol): ?>
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--warn);">
    <strong>⏳ Match assignment is being set up.</strong>
    <p style="color:var(--muted);margin-top:6px;">Once the tournament team finishes wiring up court &amp; referee assignment, your matches will show up here automatically with a live scoring form.</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title mb-1">Your Matches</div>
    <hr class="divider"/>
    <?php if (empty($myMatches)): ?>
        <p class="text-muted text-center" style="padding:24px;">No matches assigned to you right now.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tournament</th><th>Round</th><th>Matchup</th><th>Court</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($myMatches as $m): ?>
                <tr>
                    <td style="font-weight:600;"><?= clean($m['tournament_name']) ?></td>
                    <td>Round <?= (int)$m['round'] ?></td>
                    <td><?= clean($m['p1_name'] ?: 'TBD') ?> vs <?= clean($m['p2_name'] ?: 'TBD') ?></td>
                    <td><?= clean($m['court_name'] ?: '—') ?></td>
                    <td><span class="badge badge-<?= $m['status']==='in_progress'?'success':'warn' ?>"><?= clean(str_replace('_',' ', $m['status'])) ?></span></td>
                    <td><a href="<?= APP_URL ?>/referee/score_match.php?match_id=<?= $m['id'] ?>" class="btn-primary btn-sm">Score →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
