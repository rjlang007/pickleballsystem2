<?php
// ============================================================
//  FILE: admin/leaderboard_admin.php
//  Admin controls for managing the Open Play season leaderboard.
//  Open to both 'admin' and 'super_admin' (requireAdmin() checks
//  ADMIN_ROLES, which includes both) — no other role can view or
//  edit standings here.
//
//  SCORING RULE: each player's score is the SUM of the points
//  they earned across every finalized Open Play session this
//  season — 1st place = 3 pts, 2nd = 2 pts, 3rd = 1 pt, everyone
//  else = 0 pts (see config/tournament_config.php ->
//  open_play_point_distribution, applied in
//  OpenPlayEngine::finalizeEvent()). A player who wins repeatedly
//  across multiple sessions keeps accumulating points — there's
//  no cap and no reset between sessions within a season. The #1
//  rank always goes to whoever has the highest accumulated total.
//
//  This is computed live from falcon.tournament_scores joined to
//  falcon.tournaments (bracket_type = 'open_play', status =
//  'completed') rather than from falcon.leaderboard, because that
//  table also aggregates regular bracket-style tournaments (a
//  different, unrelated point scale) and would mix the two.
//
//  Manual point adjustments (dispute resolution, penalties, etc.)
//  are recorded separately in
//  falcon.open_play_leaderboard_adjustments and added on top of
//  the live Open Play total, so they survive even though the base
//  total itself is recalculated fresh on every page load.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../leaderboard/leaderboard_engine.php';

requireAdmin();

$db      = getDB();
$engine  = new LeaderboardEngine();
$adminId = (int) $_SESSION['user_id'];

$message      = '';
$message_type = '';

$currentYear   = (int) date('Y');
$season        = (int) ($_GET['season'] ?? $currentYear);
if ($season < 2000 || $season > $currentYear + 1) {
    $season = $currentYear;
}
$seasonOptions = range($currentYear, max(2024, $currentYear - 4));
$search        = trim($_GET['search'] ?? '');

// ── Handle manual point adjustment ──────────────────────────
// Recorded as a delta in its own table (not falcon.leaderboard,
// which also holds unrelated bracket-tournament points) so it
// layers cleanly on top of the live Open Play total below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_points') {
    verifyCsrf();

    if (!checkRateLimit('leaderboard_admin_' . $adminId, 30, 60)) {
        $message      = 'Too many requests. Please slow down.';
        $message_type = 'error';
    } else {
        $playerId     = (int) ($_POST['player_id'] ?? 0);
        $pointsChange = $_POST['points_change'] ?? '';
        $reason       = trim($_POST['reason'] ?? '');
        $adjSeason    = (int) ($_POST['season'] ?? $season);

        if ($playerId <= 0 || !is_numeric($pointsChange) || (int)$pointsChange === 0 || $reason === '') {
            $message      = 'Please choose a player, a non-zero points change, and a reason.';
            $message_type = 'error';
        } else {
            $playerCheck = $db->prepare("SELECT id, COALESCE(full_name, username) AS name FROM falcon.users WHERE id = ?");
            $playerCheck->execute([$playerId]);
            $player = $playerCheck->fetch();

            if (!$player) {
                $message      = 'Player not found.';
                $message_type = 'error';
            } else {
                $db->prepare(
                    "INSERT INTO falcon.open_play_leaderboard_adjustments
                         (player_id, season, points_delta, reason, adjusted_by, created_at)
                     VALUES (:pid, :season, :delta, :reason, :admin, NOW())"
                )->execute([
                    ':pid'    => $playerId,
                    ':season' => $adjSeason,
                    ':delta'  => (int) $pointsChange,
                    ':reason' => $reason,
                    ':admin'  => $adminId,
                ]);

                logActivity(
                    'Open Play Leaderboard Points Adjusted',
                    'admin',
                    'normal',
                    sprintf(
                        '%s adjusted %s by %+d pts (Open Play season %d): %s',
                        $_SESSION['username'] ?? 'admin',
                        $player['name'],
                        (int) $pointsChange,
                        $adjSeason,
                        $reason
                    )
                );

                notifyUser(
                    $db,
                    $playerId,
                    'Open Play leaderboard points updated',
                    sprintf('An admin adjusted your Open Play season %d points by %+d: %s', $adjSeason, (int) $pointsChange, $reason),
                    null,
                    APP_URL . '/public/leaderboard.php?season=' . $adjSeason
                );

                $message      = "Points updated for {$player['name']}.";
                $message_type = 'success';
            }
        }
    }
}

// ── Load the Open Play leaderboard for the selected season ──
// Every participant of a finalized Open Play event has a row in
// tournament_scores (even 0-point ones), so this naturally lists
// every player who has taken part in Open Play this season, with
// their points summed across every session they played. Shared
// with public/leaderboard.php and the dashboard widget via
// LeaderboardEngine::getOpenPlayLeaderboard() so every surface
// always shows the exact same numbers.
$lbResult    = $engine->getOpenPlayLeaderboard($season, 200, 0, $search);
$leaderboard = $lbResult['players'];

$pageTitle = 'Leaderboard Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<style nonce="<?= getCspNonce() ?>">
    /* Theme-matched: dark surfaces + light text from the app tokens. */
    .lb-admin-wrap{max-width:1100px;margin:0 auto;}
    .lb-filter-card{margin-bottom:var(--space-lg);}
    .lb-filters{display:flex;gap:var(--space-sm);flex-wrap:wrap;align-items:flex-end;margin-top:var(--space-md);}
    .lb-filters > div{flex:1;min-width:180px;max-width:320px;}
    .lb-filters label{display:block;font-size:12px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text);opacity:.85;margin-bottom:6px;}
    .lb-filters select,.lb-filters input[type=text]{color-scheme:dark;}
    .lb-message{display:flex;gap:10px;padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:16px;font-weight:600;font-size:14px;border:1px solid;border-left-width:4px;}
    .lb-message.success{background:rgba(16,185,129,.14);color:#b7f3da;border-color:var(--success);}
    .lb-message.error{background:rgba(239,68,68,.14);color:#fecaca;border-color:var(--danger);}
    .lb-table-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;}
    .lb-table-card .table-wrap{border:0;border-radius:0;}
    table.lb-table{min-width:640px;}
    table.lb-table th{color:var(--text);opacity:.8;}
    table.lb-table td{color:var(--text);}
    .lb-rank{font-weight:700;color:var(--accent2);width:80px;font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:.5px;}
    .lb-rank.top1{color:#fbbf24;}
    .lb-rank.top2{color:#cbd5e1;}
    .lb-rank.top3{color:#f0a05a;}
    .lb-tie{font-family:'DM Sans',sans-serif;font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
    .lb-player{display:flex;align-items:center;gap:10px;font-weight:600;}
    .lb-avatar{width:32px;height:32px;border-radius:50%;object-fit:cover;background:var(--surface3);border:1px solid var(--border);flex-shrink:0;}
    .lb-points{color:var(--accent);font-weight:700;font-size:16px;}
    .lb-empty{padding:40px 16px;text-align:center;color:var(--muted);font-size:15px;}
    .lb-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(3,7,18,.75);backdrop-filter:blur(3px);z-index:999;align-items:center;justify-content:center;padding:16px;}
    .lb-modal-backdrop.open{display:flex;}
    .lb-modal{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:var(--space-lg);border-radius:var(--radius-lg);max-width:420px;width:100%;box-shadow:var(--shadow-lg);}
    .lb-modal h3{margin:0 0 var(--space-md);font-family:'Bebas Neue',sans-serif;font-size:24px;letter-spacing:1px;color:var(--text);}
    .lb-modal input,.lb-modal textarea{color-scheme:dark;}
    .lb-modal-player{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;font-weight:700;margin-bottom:var(--space-md);}
    .lb-modal-player span.k{display:block;font-size:11px;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);font-weight:700;}
    .lb-modal-actions{display:flex;gap:10px;margin-top:var(--space-md);}
    .lb-modal-actions button{flex:1;}
</style>

<div class="lb-admin-wrap">
    <div class="page-header">
        <h1>🏆 Leaderboard Admin</h1>
        <p>Accumulated from Open Play sessions only — 1st = 3 pts · 2nd = 2 pts · 3rd = 1 pt. Points keep stacking across every session this season.</p>
    </div>

    <div class="card lb-filter-card">
        <form method="GET" class="lb-filters">
            <div>
                <label for="lb-season">Season</label>
                <select id="lb-season" name="season" onchange="this.form.submit()">
                    <?php foreach ($seasonOptions as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $season ? 'selected' : '' ?>>Season <?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="lb-search">Player</label>
                <input type="text" id="lb-search" name="search" placeholder="Search player…" value="<?= clean($search) ?>">
            </div>
            <button type="submit" class="btn-primary">🔍 Filter</button>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="lb-message <?= $message_type === 'success' ? 'success' : 'error' ?>" role="status">
            <span aria-hidden="true"><?= $message_type === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= clean($message) ?></span>
        </div>
    <?php endif; ?>

    <div class="lb-table-card">
        <div class="table-wrap">
            <table class="lb-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Player</th>
                        <th>Points</th>
                        <th>Wins</th>
                        <th>Open Plays</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaderboard)): ?>
                        <tr><td colspan="6" class="lb-empty">No Open Play leaderboard entries for season <?= $season ?> yet.</td></tr>
                    <?php else: foreach ($leaderboard as $entry):
                        $rankNum = (int) $entry['rank'];
                        $rankClass = $rankNum === 1 ? 'top1' : ($rankNum === 2 ? 'top2' : ($rankNum === 3 ? 'top3' : ''));
                    ?>
                        <tr>
                            <td class="lb-rank <?= $rankClass ?>">#<?= $rankNum ?><?= !empty($entry['tied']) ? ' <span class="lb-tie">(tie)</span>' : '' ?></td>
                            <td>
                                <div class="lb-player">
                                    <?php if (!empty($entry['avatar_url'])): ?>
                                        <img class="lb-avatar" src="<?= clean($entry['avatar_url']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="lb-avatar" aria-hidden="true"></div>
                                    <?php endif; ?>
                                    <span><?= clean($entry['name']) ?></span>
                                </div>
                            </td>
                            <td class="lb-points"><?= (int)$entry['total_points'] ?></td>
                            <td><?= (int)$entry['total_wins'] ?></td>
                            <td><?= (int)$entry['total_events'] ?></td>
                            <td>
                                <button type="button" class="btn-secondary btn-sm lb-adjust-btn"
                                    data-player-id="<?= (int)$entry['player_id'] ?>"
                                    data-player-name="<?= clean($entry['name']) ?>">
                                    ⚙️ Adjust
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="lb-modal-backdrop" id="lbAdjustModal">
    <div class="lb-modal" role="dialog" aria-modal="true" aria-labelledby="lbModalTitle">
        <h3 id="lbModalTitle">Adjust Player Points</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="adjust_points">
            <input type="hidden" name="player_id" id="lbPlayerId">
            <input type="hidden" name="season" value="<?= $season ?>">

            <div class="lb-modal-player"><span class="k">Player</span><span id="lbPlayerName"></span></div>
            <div class="form-group">
                <label for="lbPointsChange">Points change (+ or −)</label>
                <input type="number" name="points_change" id="lbPointsChange" required>
            </div>
            <div class="form-group">
                <label for="lbReason">Reason</label>
                <textarea name="reason" id="lbReason" rows="3" placeholder="e.g. Manual correction, dispute resolution" required></textarea>
            </div>

            <div class="lb-modal-actions">
                <button type="submit" class="btn-primary">💾 Save</button>
                <button type="button" class="btn-secondary" id="lbCancelBtn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
    function lbOpenAdjust(playerId, playerName) {
        document.getElementById('lbPlayerId').value = playerId;
        document.getElementById('lbPlayerName').textContent = playerName;
        document.getElementById('lbPointsChange').value = '';
        document.getElementById('lbAdjustModal').classList.add('open');
        document.getElementById('lbPointsChange').focus();
    }
    function lbCloseAdjust() {
        document.getElementById('lbAdjustModal').classList.remove('open');
    }
    document.getElementById('lbAdjustModal').addEventListener('click', function (e) {
        if (e.target.id === 'lbAdjustModal') lbCloseAdjust();
    });
    document.getElementById('lbCancelBtn').addEventListener('click', lbCloseAdjust);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') lbCloseAdjust();
    });
    // data-attributes (not inline JS strings) so names containing ' or " can't break the handler
    document.querySelectorAll('.lb-adjust-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            lbOpenAdjust(btn.dataset.playerId, btn.dataset.playerName);
        });
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
