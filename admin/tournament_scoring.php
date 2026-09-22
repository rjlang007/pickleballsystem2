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

$pageTitle = 'Score Entry — ' . ($tournament ? $tournament['name'] : 'Select Tournament');
require_once __DIR__ . '/../includes/header.php';
?>
<style nonce="<?= getCspNonce() ?>">
    /* ── Tournament selector ── */
    .tourney-select-form {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        flex-wrap: wrap;
    }
    .tourney-select-form label { font-weight: 600; color: var(--text); white-space: nowrap; }
    .tourney-select-form select { max-width: 360px; }

    .section-note { font-size: 13px; color: var(--muted); margin: -6px 0 var(--space-md); }

    .round-heading {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 22px;
        letter-spacing: 0.5px;
        color: var(--accent);
        margin-bottom: var(--space-md);
    }

    /* ── Match cards ── */
    .match-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: var(--space-md);
    }
    .match-card {
        background: var(--surface2);
        border: 1px solid var(--border);
        border-left: 3px solid var(--warn);
        border-radius: var(--radius);
        padding: var(--space-md);
        min-width: 0;
    }
    .match-card.match-card--done { border-left-color: var(--success); }

    .match-card__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 12px;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: var(--space-sm);
    }

    .match-result {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        font-size: 14px;
        padding: 8px 0;
    }
    .match-result .winner { font-weight: 700; color: #6ee7b7; }
    .match-result .loser  { color: var(--muted); }
    .match-result .score  {
        font-family: 'DM Mono', 'Courier New', monospace;
        font-weight: 700;
        color: var(--text);
        background: rgba(255,255,255,0.04);
        border-radius: var(--radius-sm);
        padding: 2px 10px;
        flex-shrink: 0;
    }

    .match-entry-form { margin-top: 4px; }
    .match-inputs {
        display: flex;
        align-items: flex-end;
        gap: 10px;
        margin-bottom: var(--space-sm);
    }
    .player-input { flex: 1; min-width: 0; }
    .player-input label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--muted);
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .match-inputs .vs {
        font-size: 11px;
        font-weight: 700;
        color: var(--muted);
        padding-bottom: 12px;
        flex-shrink: 0;
    }

    .winner-select {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        font-size: 13px;
        color: var(--text);
        margin-bottom: var(--space-sm);
    }
    .winner-select > label:first-child { color: var(--muted); font-weight: 600; }
    .winner-select label {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        cursor: pointer;
    }
    .winner-select input[type="radio"] { width: auto; min-height: 0; }

    .match-waiting {
        color: var(--muted);
        font-size: 13px;
        font-style: italic;
        padding: 8px 0;
    }
</style>

<!-- Page Header -->
<div class="page-header flex-between">
    <div>
        <h1>📝 Score Entry</h1>
        <?php if ($tournament): ?>
            <p><?= clean($tournament['name']) ?></p>
        <?php endif; ?>
    </div>
    <div>
        <a href="<?= appUrl('admin/tournament_admin.php') ?>" class="btn-outline btn-sm">← Admin</a>
    </div>
</div>

<!-- Tournament selector -->
<div class="card mb-3">
    <form method="GET" class="tourney-select-form">
        <label for="tourneySelect">Tournament:</label>
        <select name="id" id="tourneySelect" onchange="this.form.submit()">
            <option value="">— Select —</option>
            <?php foreach ($allTournaments as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $tid == $t['id'] ? 'selected' : '' ?>>
                <?= clean($t['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($error):   ?><div class="alert alert-error"><span class="alert-icon">⚠️</span><div class="alert-content"><?= clean($error) ?></div></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><span class="alert-icon">✅</span><div class="alert-content"><?= clean($success) ?></div></div><?php endif; ?>

<?php if ($tournament): ?>

<!-- ── Match Entry by Round ──────────────────────────── -->
<?php foreach ($byRound as $round => $roundMatches): ?>
<div class="card mb-3">
    <div class="round-heading">
        Round <?= $round <= 99 ? $round : ($round === 200 ? 'Grand Final' : 'Losers R' . ($round - 100)) ?>
    </div>
    <div class="match-grid">
    <?php foreach ($roundMatches as $m):
        $p1Name = clean($m['player1_display'] ?: $m['player1_name'] ?: 'TBD');
        $p2Name = clean($m['player2_display'] ?: $m['player2_name'] ?: 'TBD');
        $isDone = $m['status'] === 'completed';
    ?>
    <div class="match-card <?= $isDone ? 'match-card--done' : '' ?>">
        <div class="match-card__header">
            <span>Match #<?= $m['match_number'] ?></span>
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
            <button type="submit" class="btn-primary btn-sm">Save Result</button>
        </form>
        <?php else: ?>
            <p class="match-waiting">Waiting for players to advance...</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- ── Manual Score Override ─────────────────────────── -->
<div class="card mb-3">
    <div class="card-title mb-2">Manual Score Override / Dispute Resolution</div>
    <hr class="divider" style="margin: 0 0 var(--space-md);">
    <p class="section-note">Use this to correct placements or override points after the fact.</p>
    <form method="POST">
        <input type="hidden" name="action"        value="manual_score">
        <input type="hidden" name="tournament_id" value="<?= $tid ?>">
        <?= csrfField() ?>
        <div class="form-row">
            <div class="form-group">
                <label>Player</label>
                <select name="player_id" required>
                    <?php foreach ($engine->getTournamentPlayers($tid) as $p): ?>
                    <option value="<?= $p['player_id'] ?>">
                        <?= clean($p['display_name'] ?: $p['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Placement</label>
                <input type="number" name="placement" min="1" max="64" required placeholder="1">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Points</label>
                <input type="number" name="points" min="0" max="1000" required placeholder="100">
            </div>
            <div class="form-group">
                <label>Note / Reason</label>
                <input type="text" name="note" placeholder="Dispute resolution note...">
            </div>
        </div>
        <button type="submit" class="btn-warn">Override Score</button>
    </form>
</div>

<!-- ── Current Scores ────────────────────────────────── -->
<?php if (!empty($scores)): ?>
<div class="card">
    <div class="card-title mb-2">Current Scores &amp; Placements</div>
    <hr class="divider" style="margin: 0 0 var(--space-md);">
    <div class="table-wrap">
    <table>
        <thead>
            <tr><th>Placement</th><th>Player</th><th>Points</th><th>Recorded By</th><th>At</th></tr>
        </thead>
        <tbody>
        <?php foreach ($scores as $s): ?>
        <tr>
            <td>#<?= $s['placement'] ?></td>
            <td><?= clean($s['display_name'] ?: $s['full_name']) ?></td>
            <td><?= $s['points'] ?></td>
            <td><?= clean($s['recorded_by_name']) ?></td>
            <td><?= date('M d H:i', strtotime($s['recorded_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php endif; // tournament selected ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>