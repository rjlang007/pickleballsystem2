<?php
// ============================================================
//  FILE: player/history.php
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = $_SESSION['user_id'];

$fMonth  = $_GET['month'] ?? date('Y-m');
[$yr, $mo] = explode('-', $fMonth);
$monthStart = "$yr-$mo-01";
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$fPage   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($fPage - 1) * $perPage;

$totalCount = $db->prepare("
    SELECT COUNT(*) FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE gp.user_id = ? AND DATE(gs.started_at) BETWEEN ? AND ?
");
$totalCount->execute([$uid, $monthStart, $monthEnd]);
$totalRows  = (int)$totalCount->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$histStmt = $db->prepare("
    SELECT gs.id, gs.status, gs.started_at, gs.ended_at, gs.duration_mins,
           c.name AS court_name, gp.credits_charged,
           (SELECT STRING_AGG(u2.username, ', ')
            FROM falcon.game_players gp2
            JOIN falcon.users u2 ON u2.id = gp2.user_id
            WHERE gp2.session_id = gs.id AND gp2.user_id != ?) AS teammates
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    JOIN falcon.courts c ON c.id = gs.court_id
    WHERE gp.user_id = ? AND DATE(gs.started_at) BETWEEN ? AND ?
    ORDER BY gs.started_at DESC
    LIMIT $perPage OFFSET $offset
");
$histStmt->execute([$uid, $uid, $monthStart, $monthEnd]);
$games = $histStmt->fetchAll();

$allTimeStmt = $db->prepare("
    SELECT
        COUNT(*)                                              AS total_games,
        COUNT(*) FILTER (WHERE gs.status='completed')        AS completed,
        COUNT(*) FILTER (WHERE gs.status='cancelled')        AS cancelled,
        COALESCE(SUM(gp.credits_charged) FILTER (WHERE gs.status='completed'), 0) AS total_spent,
        COUNT(DISTINCT c.id)                                  AS courts_visited,
        MIN(gs.started_at)                                    AS first_game,
        MAX(gs.started_at)                                    AS last_game
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    JOIN falcon.courts c ON c.id = gs.court_id
    WHERE gp.user_id = ?
");
$allTimeStmt->execute([$uid]);
$allTime = $allTimeStmt->fetch();

$favCourtStmt = $db->prepare("
    SELECT c.name, COUNT(*) AS plays
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    JOIN falcon.courts c ON c.id = gs.court_id
    WHERE gp.user_id = ? AND gs.status = 'completed'
    GROUP BY c.id, c.name ORDER BY plays DESC LIMIT 1
");
$favCourtStmt->execute([$uid]);
$favCourt = $favCourtStmt->fetch();

$favTeammateStmt = $db->prepare("
    SELECT u2.username, u2.full_name, COUNT(*) AS shared_games
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    JOIN falcon.game_players gp2 ON gp2.session_id = gs.id AND gp2.user_id != gp.user_id
    JOIN falcon.users u2 ON u2.id = gp2.user_id
    WHERE gp.user_id = ? AND gs.status = 'completed'
    GROUP BY u2.id, u2.username, u2.full_name
    ORDER BY shared_games DESC LIMIT 1
");
$favTeammateStmt->execute([$uid]);
$favTeammate = $favTeammateStmt->fetch();

$monthlyStmt = $db->prepare("
    SELECT TO_CHAR(gs.started_at, 'Mon') AS month_label,
           DATE_TRUNC('month', gs.started_at) AS month_start,
           COUNT(*) AS games,
           COALESCE(SUM(gp.credits_charged), 0) AS spent
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE gp.user_id = ? AND gs.status = 'completed'
      AND gs.started_at >= NOW() - INTERVAL '6 months'
    GROUP BY month_label, month_start
    ORDER BY month_start ASC
");
$monthlyStmt->execute([$uid]);
$monthly = $monthlyStmt->fetchAll();
$maxMonthGames = max(array_column($monthly, 'games') ?: [1]);

$balStmt = $db->prepare("SELECT COALESCE(balance, 0) FROM falcon.wallets WHERE user_id = ?");
$balStmt->execute([$uid]);
$balance = $balStmt->fetchColumn();

$pageTitle = 'My Game History';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── History page responsive ─────────────────────────────── */
.history-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: var(--space-md, 16px);
    margin-bottom: 24px;
}
@media (max-width: 900px)  { .history-stats { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 600px)  { .history-stats { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 360px)  { .history-stats { grid-template-columns: 1fr; } }

/* chart + facts sidebar */
.history-grid {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 24px;
    margin-bottom: 24px;
}
@media (max-width: 900px)  { .history-grid { grid-template-columns: 1fr; } }

/* month filter form */
.month-filter-form {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.month-filter-form input[type="month"] {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 12px;
    color: var(--text);
    font-size: 16px; /* prevents iOS zoom */
    min-height: 38px;
    touch-action: manipulation;
}

/* bar chart */
.activity-bars {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    height: 120px;
    padding: 0 4px;
}
.activity-bar-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    height: 100%;
    justify-content: flex-end;
    min-width: 0;
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>My Game History</h1>
        <p>Your complete court activity record</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/player/topup_history.php" class="btn-outline btn-sm">💳 Credits</a>
        <a href="<?= APP_URL ?>/player/dashboard.php"     class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- All-Time Stats -->
<div class="history-stats">
    <div class="stat-card">
        <div class="stat-val"><?= $allTime['total_games'] ?></div>
        <div class="stat-label">Total Games</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);">₱<?= number_format($allTime['total_spent'], 2) ?></div>
        <div class="stat-label">Credits Spent</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);">₱<?= number_format($balance, 2) ?></div>
        <div class="stat-label">Balance</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= $allTime['courts_visited'] ?></div>
        <div class="stat-label">Courts Visited</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);"><?= $allTime['completed'] ?></div>
        <div class="stat-label">Completed</div>
    </div>
</div>

<!-- Chart + Fun Facts -->
<div class="history-grid">

    <!-- Activity Chart -->
    <div class="card">
        <div class="card-title">📊 Activity — Last 6 Months</div>
        <div class="card-subtitle">Games played per month</div>
        <hr class="divider"/>
        <?php if (empty($monthly)): ?>
            <p class="text-muted text-center" style="padding:24px;">No completed games yet.</p>
        <?php else: ?>
            <div class="activity-bars">
                <?php foreach ($monthly as $m):
                    $barH = $maxMonthGames > 0 ? round($m['games'] / $maxMonthGames * 100) : 0;
                ?>
                    <div class="activity-bar-col">
                        <div style="font-size:11px;color:var(--accent);font-weight:700;"><?= $m['games'] ?></div>
                        <div style="width:100%;background:var(--accent);border-radius:4px 4px 0 0;
                                    opacity:0.8;height:<?= max(4,$barH) ?>%;transition:height 0.5s;"></div>
                        <div style="font-size:11px;color:var(--muted);white-space:nowrap;"><?= $m['month_label'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Fun facts sidebar -->
    <div style="display:flex;flex-direction:column;gap:14px;">
        <?php if ($favCourt): ?>
            <div class="card" style="padding:16px 20px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:6px;">🏓 Favourite Court</div>
                <div style="font-weight:700;font-size:18px;color:var(--accent);"><?= clean($favCourt['name']) ?></div>
                <div style="font-size:12px;color:var(--muted);"><?= $favCourt['plays'] ?> games here</div>
            </div>
        <?php endif; ?>
        <?php if ($favTeammate): ?>
            <div class="card" style="padding:16px 20px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:6px;">👥 Most Played With</div>
                <div style="font-weight:700;font-size:18px;color:var(--accent2);"><?= clean($favTeammate['full_name']) ?></div>
                <div style="font-size:12px;color:var(--muted);">@<?= clean($favTeammate['username']) ?> · <?= $favTeammate['shared_games'] ?> games</div>
            </div>
        <?php endif; ?>
        <?php if ($allTime['first_game']): ?>
            <div class="card" style="padding:16px 20px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:6px;">📅 Member Since</div>
                <div style="font-weight:700;font-size:16px;"><?= date('M d, Y', strtotime($allTime['first_game'])) ?></div>
                <div style="font-size:12px;color:var(--muted);">First game at Padol</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Game Log -->
<div class="card">
    <div class="flex-between mb-2" style="flex-wrap:wrap;gap:12px;">
        <div class="card-title">🎮 Game Log</div>
        <form method="GET" class="month-filter-form">
            <input type="month" name="month" value="<?= $fMonth ?>" max="<?= date('Y-m') ?>"/>
            <button type="submit" class="btn-primary btn-sm" style="touch-action:manipulation;">Go</button>
            <a href="?" class="btn-outline btn-sm">All</a>
        </form>
    </div>
    <div class="card-subtitle">
        <?= $totalRows ?> game<?= $totalRows != 1 ? 's' : '' ?> · Page <?= $fPage ?>/<?= $totalPages ?>
    </div>
    <hr class="divider"/>

    <?php if (empty($games)): ?>
        <div style="text-align:center;padding:40px;">
            <div style="font-size:48px;margin-bottom:12px;">🎯</div>
            <p class="text-muted">No games found for <?= date('F Y', strtotime($monthStart)) ?>.</p>
            <a href="?" class="btn-outline btn-sm mt-2">View All Time</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Court</th>
                        <th class="col-hide-sm">Duration</th>
                        <th class="col-hide-sm">Played With</th>
                        <th>Credits</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($games as $g):
                        $badge = match($g['status']) {
                            'completed' => 'success', 'cancelled' => 'danger',
                            'active'    => 'info',    default     => 'muted'
                        };
                        $actualMins = ($g['ended_at'] && $g['started_at'])
                            ? round((strtotime($g['ended_at']) - strtotime($g['started_at'])) / 60)
                            : null;
                    ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:12px;">
                                <strong><?= date('M d, Y', strtotime($g['started_at'])) ?></strong><br/>
                                <span style="color:var(--muted);"><?= date('h:i A', strtotime($g['started_at'])) ?></span>
                            </td>
                            <td style="font-size:13px;"><?= clean($g['court_name']) ?></td>
                            <td class="col-hide-sm" style="font-family:monospace;font-size:13px;">
                                <?= $actualMins !== null ? $actualMins.'min' : $g['duration_mins'].'min' ?>
                            </td>
                            <td class="col-hide-sm" style="font-size:12px;color:var(--muted);max-width:180px;
                                overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= clean($g['teammates'] ?? '—') ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php if ($g['status'] === 'cancelled'): ?>
                                    <span style="color:var(--muted);text-decoration:line-through;font-size:12px;">
                                        -₱<?= number_format($g['credits_charged'], 2) ?>
                                    </span><br>
                                    <span style="font-size:10px;color:var(--success);">refunded</span>
                                <?php else: ?>
                                    <span style="color:var(--danger);font-weight:600;">
                                        -₱<?= number_format($g['credits_charged'], 2) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?= $badge ?>"><?= ucfirst($g['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($fPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $fPage - 1])) ?>" class="btn-outline btn-sm">← Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1, $fPage - 2); $p <= min($totalPages, $fPage + 2); $p++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                       class="btn-<?= $p === $fPage ? 'primary' : 'outline' ?> btn-sm"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($fPage < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $fPage + 1])) ?>" class="btn-outline btn-sm">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>