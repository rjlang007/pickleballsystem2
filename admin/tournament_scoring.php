<?php
// ============================================================
//  FILE: admin/tournament_scoring.php
// ============================================================
// SECURITY FIX: same issue as tournament_admin.php — bare session_start()
// bypassed the app's DB-backed session, security headers, and CSRF
// protection entirely. Now uses the shared bootstrap + requireAdmin().
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../tournament/tournament_engine.php';
require_once __DIR__ . '/../tournament/scoring_engine.php';

requireAdmin();

$engine   = new TournamentEngine();
$scoring  = new ScoringEngine();
$adminId  = (int) $_SESSION['user_id'];
$error    = '';
$success  = '';
$tid      = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Handle match result submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!checkRateLimit('tournament_scoring_' . $adminId, 60, 60)) {
        $error = 'Too many requests. Please slow down.';
        goto render;
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'record_match') {
            $engine->recordMatchResult(
                (int) $_POST['match_id'],
                (int) $_POST['winner_id'],
                (int) $_POST['score_player1'],
                (int) $_POST['score_player2'],
                $adminId
            );
            $success = 'Match result recorded.';
        } elseif ($action === 'manual_score') {
            $scoring->manuallySetScore(
                (int) $_POST['tournament_id'],
                (int) $_POST['player_id'],
                (int) $_POST['placement'],
                (int) $_POST['points'],
                $adminId,
                $_POST['note'] ?? ''
            );
            $success = 'Score manually updated.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
render:

// Load tournament
$tournament = $tid ? $engine->getTournament($tid) : null;
$matches    = $tid ? $engine->getBracket($tid)    : [];
$scores     = $tid ? $scoring->getTournamentScores($tid) : [];

// Group matches by round
$byRound = [];
foreach ($matches as $m) {
    $byRound[$m['bracket_round']][] = $m;
}

// All tournaments for selector
$allTournaments = $engine->listTournaments(['status' => 'in_progress']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Score Entry — <?= $tournament ? htmlspecialchars($tournament['name']) : 'Select Tournament' ?></title>
    <link rel="stylesheet" href="/pickleball/assets/css/tournament.css">
    <link rel="stylesheet" href="/pickleball/assets/css/leaderboard.css">
</head>
<body class="admin-body">
<div class="admin-wrap">

    <header class="admin-header">
        <h1>📝 Score Entry</h1>
        <a href="/pickleball/admin/tournament_admin.php" class="btn btn-secondary">← Admin</a>
    </header>

    <!-- Tournament selector -->
    <section class="admin-card">
        <form method="GET" class="inline-form">
            <label><strong>Tournament:</strong></label>
            <select name="id" onchange="this.form.submit()">
                <option value="">— Select —</option>
                <?php foreach ($allTournaments as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $tid == $t['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </section>

    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if ($tournament): ?>

    <!-- ── Match Entry by Round ──────────────────────────── -->
    <?php foreach ($byRound as $round => $roundMatches): ?>
    <section class="admin-card">
        <h2>Round <?= $round <= 99 ? $round : ($round === 200 ? 'Grand Final' : 'Losers R' . ($round - 100)) ?></h2>
        <div class="match-grid">
        <?php foreach ($roundMatches as $m):
            $p1Name = htmlspecialchars($m['player1_display'] ?: $m['player1_name'] ?: 'TBD');
            $p2Name = htmlspecialchars($m['player2_display'] ?: $m['player2_name'] ?: 'TBD');
            $isDone = $m['status'] === 'completed';
        ?>
        <div class="match-card <?= $isDone ? 'match-card--done' : '' ?>">
            <div class="match-card__header">
                Match #<?= $m['match_number'] ?>
                <?php if ($isDone): ?>
                    <span class="badge badge-success">✓ Done</span>
                <?php else: ?>
                    <span class="badge badge-warning">Pending</span>
                <?php endif; ?>
            </div>

            <?php if ($isDone): ?>
            <div class="match-result">
                <span class="<?= $m['winner_id'] == $m['player1_id'] ? 'winner' : 'loser' ?>"><?= $p1Name ?></span>
                <span class="score"><?= $m['score_player1'] ?> – <?= $m['score_player2'] ?></span>
                <span class="<?= $m['winner_id'] == $m['player2_id'] ? 'winner' : 'loser' ?>"><?= $p2Name ?></span>
            </div>
            <?php elseif ($m['player1_id'] && $m['player2_id']): ?>
            <form method="POST" class="match-entry-form">
                <input type="hidden" name="action"    value="record_match">
                <input type="hidden" name="match_id"  value="<?= $m['id'] ?>">
                <?= csrfField() ?>
                <div class="match-inputs">
                    <div class="player-input">
                        <label><?= $p1Name ?></label>
                        <input type="number" name="score_player1" min="0" max="99" required placeholder="Score">
                    </div>
                    <span class="vs">VS</span>
                    <div class="player-input">
                        <label><?= $p2Name ?></label>
                        <input type="number" name="score_player2" min="0" max="99" required placeholder="Score">
                    </div>
                </div>
                <div class="winner-select">
                    <label>Winner:</label>
                    <label>
                        <input type="radio" name="winner_id" value="<?= $m['player1_id'] ?>" required>
                        <?= $p1Name ?>
                    </label>
                    <label>
                        <input type="radio" name="winner_id" value="<?= $m['player2_id'] ?>">
                        <?= $p2Name ?>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Save Result</button>
            </form>
            <?php else: ?>
                <p class="waiting">Waiting for players to advance...</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <!-- ── Manual Score Override ─────────────────────────── -->
    <section class="admin-card">
        <h2>Manual Score Override / Dispute Resolution</h2>
        <p class="hint">Use this to correct placements or override points after the fact.</p>
        <form method="POST" class="tournament-form">
            <input type="hidden" name="action"        value="manual_score">
            <input type="hidden" name="tournament_id" value="<?= $tid ?>">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Player</label>
                    <select name="player_id" required>
                        <?php foreach ($engine->getTournamentPlayers($tid) as $p): ?>
                        <option value="<?= $p['player_id'] ?>">
                            <?= htmlspecialchars($p['display_name'] ?: $p['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Placement</label>
                    <input type="number" name="placement" min="1" max="64" required placeholder="1">
                </div>
                <div class="form-group">
                    <label>Points</label>
                    <input type="number" name="points" min="0" max="1000" required placeholder="100">
                </div>
            </div>
            <div class="form-group">
                <label>Note / Reason</label>
                <input type="text" name="note" placeholder="Dispute resolution note...">
            </div>
            <button type="submit" class="btn btn-warning">Override Score</button>
        </form>
    </section>

    <!-- ── Current Scores ────────────────────────────────── -->
    <?php if (!empty($scores)): ?>
    <section class="admin-card">
        <h2>Current Scores &amp; Placements</h2>
        <table class="admin-table">
            <thead>
                <tr><th>Placement</th><th>Player</th><th>Points</th><th>Recorded By</th><th>At</th></tr>
            </thead>
            <tbody>
            <?php foreach ($scores as $s): ?>
            <tr>
                <td>#<?= $s['placement'] ?></td>
                <td><?= htmlspecialchars($s['display_name'] ?: $s['full_name']) ?></td>
                <td><?= $s['points'] ?></td>
                <td><?= htmlspecialchars($s['recorded_by_name']) ?></td>
                <td><?= date('M d H:i', strtotime($s['recorded_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php endif; // tournament selected ?>
</div>
</body>
</html>