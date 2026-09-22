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
    .lb-admin-wrap{max-width:1100px;margin:24px auto;padding:0 16px;}
    .lb-admin-header{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
    .lb-admin-header h1{margin:0 0 4px;flex:1;min-width:200px;}
    .lb-admin-header .lb-subtitle{margin:0;font-size:12.5px;color:#777;font-weight:400;flex-basis:100%;}
    .lb-filters{display:flex;gap:8px;flex-wrap:wrap;}
    .lb-filters select,.lb-filters input{padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;}
    .lb-filters button{padding:8px 14px;background:#3498db;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;}
    .lb-message{padding:12px;border-radius:6px;margin-bottom:14px;font-weight:500;}
    .lb-message.success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
    .lb-message.error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
    .lb-table-card{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
    table.lb-table{width:100%;border-collapse:collapse;font-size:13px;}
    table.lb-table thead{background:#f9f9f9;border-bottom:2px solid #e5e5e5;}
    table.lb-table th{padding:12px;text-align:left;font-weight:600;color:#333;}
    table.lb-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
    .lb-rank{font-weight:700;color:#3498db;width:50px;}
    .lb-rank.top1{color:#e0a100;}
    .lb-rank.top2{color:#8a8f98;}
    .lb-rank.top3{color:#b5651d;}
    .lb-tie{font-size:10.5px;font-weight:600;color:#999;text-transform:uppercase;}
    .lb-player{display:flex;align-items:center;gap:8px;font-weight:500;}
    .lb-avatar{width:28px;height:28px;border-radius:50%;object-fit:cover;background:#eee;}
    .lb-points{color:#27ae60;font-weight:700;}
    .lb-adjust-btn{background:#3498db;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;}
    .lb-adjust-btn:hover{background:#2980b9;}
    .lb-empty{padding:40px;text-align:center;color:#888;}
    .lb-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;}
    .lb-modal-backdrop.open{display:flex;}
    .lb-modal{background:#fff;padding:20px;border-radius:10px;max-width:400px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,.3);}
    .lb-modal h3{margin:0 0 14px;}
    .lb-form-group{margin-bottom:14px;}
    .lb-form-group label{display:block;margin-bottom:5px;font-weight:600;font-size:13px;}
    .lb-form-group input,.lb-form-group textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box;}
    .lb-modal-actions{display:flex;gap:10px;margin-top:16px;}
    .lb-modal-actions button{flex:1;padding:10px;border:none;border-radius:6px;font-weight:600;cursor:pointer;}
    .lb-btn-save{background:#27ae60;color:#fff;}
    .lb-btn-cancel{background:#95a5a6;color:#fff;}
</style>

<div class="lb-admin-wrap">
    <div class="lb-admin-header">
        <h1>🏆 Leaderboard Admin</h1>
        <p class="lb-subtitle">Accumulated from Open Play sessions only — 1st = 3 pts · 2nd = 2 pts · 3rd = 1 pt. Points keep stacking across every session this season.</p>
        <form method="GET" class="lb-filters">
            <select name="season" onchange="this.form.submit()">
                <?php foreach ($seasonOptions as $y): ?>
                    <option value="<?= $y ?>" <?= $y === $season ? 'selected' : '' ?>>Season <?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="search" placeholder="Search player…" value="<?= clean($search) ?>">
            <button type="submit">🔍 Filter</button>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="lb-message <?= $message_type === 'success' ? 'success' : 'error' ?>"><?= clean($message) ?></div>
    <?php endif; ?>

    <div class="lb-table-card">
        <div class="table-responsive">
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
                                        <div class="lb-avatar"></div>
                                    <?php endif; ?>
                                    <span><?= clean($entry['name']) ?></span>
                                </div>
                            </td>
                            <td class="lb-points"><?= (int)$entry['total_points'] ?></td>
                            <td><?= (int)$entry['total_wins'] ?></td>
                            <td><?= (int)$entry['total_events'] ?></td>
                            <td>
                                <button type="button" class="lb-adjust-btn"
                                    onclick="lbOpenAdjust(<?= (int)$entry['player_id'] ?>, '<?= clean($entry['name']) ?>')">
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
    <div class="lb-modal">
        <h3>Adjust Player Points</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="adjust_points">
            <input type="hidden" name="player_id" id="lbPlayerId">
            <input type="hidden" name="season" value="<?= $season ?>">

            <div class="lb-form-group">
                <label>Player: <span id="lbPlayerName"></span></label>
            </div>
            <div class="lb-form-group">
                <label>Points change (+ or −)</label>
                <input type="number" name="points_change" id="lbPointsChange" required autofocus>
            </div>
            <div class="lb-form-group">
                <label>Reason</label>
                <textarea name="reason" rows="3" placeholder="e.g. Manual correction, dispute resolution" required></textarea>
            </div>

            <div class="lb-modal-actions">
                <button type="submit" class="lb-btn-save">💾 Save</button>
                <button type="button" class="lb-btn-cancel" onclick="lbCloseAdjust()">Cancel</button>
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
