<?php
// ============================================================
//  FILE: admin/leaderboard_admin.php
//  Admin controls for managing the leaderboard and player points.
//  Open to both 'admin' and 'super_admin' (requireAdmin() checks
//  ADMIN_ROLES, which includes both).
//
//  REWRITE NOTE: the previous version of this file referenced an
//  undefined $pdo, MySQL-only functions (YEAR(), CURDATE()), and
//  columns/tables (leaderboard.ranking, u.name, u.avatar_url,
//  audit_log) that don't exist in this app's Postgres schema
//  (falcon.leaderboard uses total_points/rank, falcon.users uses
//  full_name/avatar) — so the page could never actually load.
//  This version uses the real schema via LeaderboardEngine and the
//  shared app bootstrap, same as every other admin page.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/helpers.php';
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

// ── Handle point adjustment ─────────────────────────────────
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
                $engine->adjustPoints($playerId, $adjSeason, (int) $pointsChange);

                logActivity(
                    'Leaderboard Points Adjusted',
                    'admin',
                    'normal',
                    sprintf(
                        '%s adjusted %s by %+d pts (season %d): %s',
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
                    'Leaderboard points updated',
                    sprintf('An admin adjusted your season %d points by %+d: %s', $adjSeason, (int) $pointsChange, $reason),
                    null,
                    APP_URL . '/public/leaderboard.php?season=' . $adjSeason
                );

                $message      = "Points updated for {$player['name']}.";
                $message_type = 'success';
            }
        }
    }
}

// ── Load leaderboard for the selected season ────────────────
$params = [':season' => $season];
$searchSql = '';
if ($search !== '') {
    $searchSql = " AND (u.full_name ILIKE :search OR u.username ILIKE :search)";
    $params[':search'] = "%{$search}%";
}

$stmt = $db->prepare(
    "SELECT lb.player_id, lb.season, lb.total_points, lb.total_wins,
            lb.total_tournaments, lb.rank, lb.last_update,
            COALESCE(u.full_name, u.username) AS name, u.username, u.avatar
       FROM falcon.leaderboard lb
       JOIN falcon.users u ON u.id = lb.player_id
      WHERE lb.season = :season
      {$searchSql}
      ORDER BY lb.rank ASC NULLS LAST, lb.total_points DESC
      LIMIT 200"
);
$stmt->execute($params);
$leaderboard = $stmt->fetchAll();

$pageTitle = 'Leaderboard Admin';
require_once __DIR__ . '/../includes/header.php';
?>
<style nonce="<?= getCspNonce() ?>">
    .lb-admin-wrap{max-width:1100px;margin:24px auto;padding:0 16px;}
    .lb-admin-header{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
    .lb-admin-header h1{margin:0;flex:1;min-width:200px;}
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
                        <th>Tournaments</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaderboard)): ?>
                        <tr><td colspan="6" class="lb-empty">No leaderboard entries for season <?= $season ?> yet.</td></tr>
                    <?php else: foreach ($leaderboard as $entry): ?>
                        <tr>
                            <td class="lb-rank">#<?= $entry['rank'] !== null ? (int)$entry['rank'] : '—' ?></td>
                            <td>
                                <div class="lb-player">
                                    <?php if (!empty($entry['avatar'])): ?>
                                        <img class="lb-avatar" src="<?= clean($entry['avatar']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="lb-avatar"></div>
                                    <?php endif; ?>
                                    <span><?= clean($entry['name']) ?></span>
                                </div>
                            </td>
                            <td class="lb-points"><?= (int)$entry['total_points'] ?></td>
                            <td><?= (int)$entry['total_wins'] ?></td>
                            <td><?= (int)$entry['total_tournaments'] ?></td>
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
