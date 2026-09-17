<?php
// ============================================================
//  FILE: admin/game_history.php
//  Full filterable game session log with pagination
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireStaff();

$db = getDB();

// ── Filters ──────────────────────────────────────────────────
$fDateFrom = $_GET['date_from'] ?? date('Y-m-01');
$fDateTo   = $_GET['date_to']   ?? date('Y-m-d');
$fStatus   = $_GET['status']    ?? 'all';
$fCourt    = $_GET['court_id']  ?? 'all';
$fPage     = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 15;
$offset    = ($fPage - 1) * $perPage;

$where  = "WHERE DATE(gs.started_at) BETWEEN ? AND ?";
$params = [$fDateFrom, $fDateTo];

if ($fStatus !== 'all') { $where .= " AND gs.status = ?"; $params[] = $fStatus; }
if ($fCourt  !== 'all') { $where .= " AND gs.court_id = ?"; $params[] = $fCourt; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM falcon.game_sessions gs $where");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$sessStmt = $db->prepare("
    SELECT gs.id, gs.status, gs.started_at, gs.ended_at, gs.duration_mins,
           c.name AS court_name,
           COUNT(gp.user_id) AS player_count,
           COALESCE(SUM(gp.credits_charged), 0) AS revenue,
           STRING_AGG(u.username, ', ' ORDER BY u.username) AS players
    FROM falcon.game_sessions gs
    JOIN falcon.courts c ON c.id = gs.court_id
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    LEFT JOIN falcon.users u ON u.id = gp.user_id
    $where
    GROUP BY gs.id, gs.status, gs.started_at, gs.ended_at, gs.duration_mins, c.name
    ORDER BY gs.started_at DESC
    LIMIT $perPage OFFSET $offset
");
$sessStmt->execute($params);
$sessions = $sessStmt->fetchAll();

$statStmt = $db->prepare("
    SELECT
        COUNT(*)                                            AS total_sessions,
        COUNT(*) FILTER (WHERE gs.status='completed')      AS completed,
        COUNT(*) FILTER (WHERE gs.status='cancelled')      AS cancelled,
        COUNT(*) FILTER (WHERE gs.status='active')         AS active,
        COALESCE(SUM(gp.credits_charged),0)                AS total_revenue,
        COUNT(DISTINCT gp.user_id)                         AS unique_players
    FROM falcon.game_sessions gs
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    $where
");
$statStmt->execute($params);
$stats = $statStmt->fetch();

$courts = $db->query("SELECT id, name FROM falcon.courts ORDER BY name")->fetchAll();

$pageTitle = 'Game History';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Page-specific responsive tweaks ── */
.gh-stats {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}
.gh-filter-form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.gh-filter-form .fg {
    flex: 1;
    min-width: 140px;
}
.gh-filter-form .fg label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 5px;
}
.gh-filter-form .fg input,
.gh-filter-form .fg select {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 14px;
    color: var(--text);
    font-size: 15px;
    font-family: 'DM Sans', sans-serif;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
}
.gh-filter-form .fg select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364748b' stroke-width='2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 34px;
}
.gh-filter-form .fg input:focus,
.gh-filter-form .fg select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,229,160,0.1);
}
.gh-ranges {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.gh-ranges a {
    padding: 7px 12px;
    font-size: 12px;
    white-space: nowrap;
}
/* Pagination */
.gh-pages {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 20px;
    flex-wrap: wrap;
}

@media (max-width: 900px) {
    .gh-stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 600px) {
    .gh-stats { grid-template-columns: repeat(2, 1fr); }
    .gh-filter-form .fg { flex: 1 1 calc(50% - 10px); min-width: 120px; }
}
@media (max-width: 420px) {
    .gh-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .gh-filter-form .fg { flex: 1 1 100%; }
    .gh-ranges { overflow-x: auto; flex-wrap: nowrap; scrollbar-width: none; padding-bottom: 2px; }
    .gh-ranges::-webkit-scrollbar { display: none; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Game History</h1>
        <p>Full log of all court sessions · <?= date('M Y', strtotime($fDateFrom)) ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=games&<?= http_build_query(['date_from'=>$fDateFrom,'date_to'=>$fDateTo,'status'=>$fStatus,'court_id'=>$fCourt]) ?>"
           class="btn-outline btn-sm">⬇ Export CSV</a>
        <a href="<?= APP_URL ?>/admin/reports.php"   class="btn-outline btn-sm">📈 Reports</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- Summary Stats -->
<div class="gh-stats">
    <div class="stat-card"><div class="stat-val"><?= $stats['total_sessions'] ?></div><div class="stat-label">Total Sessions</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--success);"><?= $stats['completed'] ?></div><div class="stat-label">Completed</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--danger);"><?= $stats['cancelled'] ?></div><div class="stat-label">Cancelled</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--accent2);"><?= $stats['active'] ?></div><div class="stat-label">Active Now</div></div>
    <div class="stat-card"><div class="stat-val">₱<?= number_format($stats['total_revenue'], 2) ?></div><div class="stat-label">Revenue</div></div>
    <div class="stat-card"><div class="stat-val"><?= $stats['unique_players'] ?></div><div class="stat-label">Unique Players</div></div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <form method="GET" class="gh-filter-form">
        <div class="fg">
            <label>From</label>
            <input type="date" name="date_from" value="<?= $fDateFrom ?>" max="<?= date('Y-m-d') ?>"/>
        </div>
        <div class="fg">
            <label>To</label>
            <input type="date" name="date_to" value="<?= $fDateTo ?>" max="<?= date('Y-m-d') ?>"/>
        </div>
        <div class="fg" style="min-width:120px;flex:0 1 140px;">
            <label>Status</label>
            <select name="status">
                <?php foreach (['all'=>'All','completed'=>'Completed','cancelled'=>'Cancelled','active'=>'Active'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $fStatus===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg" style="min-width:120px;flex:0 1 160px;">
            <label>Court</label>
            <select name="court_id">
                <option value="all">All Courts</option>
                <?php foreach ($courts as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $fCourt==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:6px;align-items:flex-end;flex-shrink:0;">
            <button type="submit" class="btn-primary btn-sm" style="padding:10px 18px;">Filter</button>
            <a href="?" class="btn-outline btn-sm" style="padding:10px 18px;">Reset</a>
        </div>
    </form>
    <!-- Quick ranges -->
    <div class="gh-ranges">
        <?php
        $ranges = [
            'Today'      => [date('Y-m-d'), date('Y-m-d')],
            'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
            'This Month' => [date('Y-m-01'), date('Y-m-d')],
            'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
        ];
        foreach ($ranges as $label => [$from, $to]):
        ?>
            <a href="?date_from=<?= $from ?>&date_to=<?= $to ?>&status=<?= $fStatus ?>&court_id=<?= $fCourt ?>"
               class="btn-outline btn-sm"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="flex-between mb-2">
        <div class="card-title">🎮 Sessions</div>
        <div class="card-subtitle"><?= number_format($totalRows) ?> records · Page <?= $fPage ?>/<?= $totalPages ?></div>
    </div>
    <hr class="divider"/>

    <?php if (empty($sessions)): ?>
        <div style="text-align:center;padding:40px;">
            <div style="font-size:48px;">🏟️</div>
            <p class="text-muted mt-1">No sessions found for the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date & Time</th>
                        <th>Court</th>
                        <th class="col-hide-sm">Planned</th>
                        <th class="col-hide-sm">Actual</th>
                        <th>Players</th>
                        <th>Revenue</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $s):
                        $badge = match($s['status']) {
                            'completed' => 'success', 'cancelled' => 'danger',
                            'active'    => 'info',    default     => 'muted',
                        };
                        $actualMins = ($s['ended_at'] && $s['started_at'])
                            ? round((strtotime($s['ended_at']) - strtotime($s['started_at'])) / 60)
                            : null;
                    ?>
                        <tr>
                            <td style="font-family:monospace;font-size:11px;color:var(--muted);white-space:nowrap;">#<?= $s['id'] ?></td>
                            <td style="white-space:nowrap;">
                                <strong style="font-size:13px;"><?= date('M d, Y', strtotime($s['started_at'])) ?></strong><br/>
                                <span style="font-size:11px;color:var(--muted);">
                                    <?= date('h:i A', strtotime($s['started_at'])) ?>
                                    <?= $s['ended_at'] ? '– '.date('h:i A', strtotime($s['ended_at'])) : '' ?>
                                </span>
                            </td>
                            <td style="font-size:13px;"><?= clean($s['court_name']) ?></td>
                            <td class="col-hide-sm" style="font-family:monospace;font-size:13px;"><?= $s['duration_mins'] ?>min</td>
                            <td class="col-hide-sm" style="font-family:monospace;font-size:13px;color:var(--muted);">
                                <?= $actualMins !== null ? $actualMins.'min' : '—' ?>
                            </td>
                            <td style="font-size:12px;max-width:160px;line-height:1.5;">
                                <span style="word-break:break-word;"><?= clean($s['players'] ?? '—') ?></span>
                                <span style="color:var(--muted);"> (<?= $s['player_count'] ?>)</span>
                            </td>
                            <td style="white-space:nowrap;">
                                <strong style="color:<?= $s['status']==='cancelled'?'var(--muted)':'var(--accent)' ?>;font-size:13px;">
                                    ₱<?= number_format($s['revenue'], 2) ?>
                                </strong>
                                <?php if ($s['status']==='cancelled'): ?>
                                    <br/><span style="font-size:10px;color:var(--danger);">refunded</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?= $badge ?>"><?= ucfirst($s['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="gh-pages">
                <?php if ($fPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$fPage-1])) ?>" class="btn-outline btn-sm">← Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1,$fPage-2); $p <= min($totalPages,$fPage+2); $p++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"
                       class="btn-<?= $p===$fPage?'primary':'outline' ?> btn-sm"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($fPage < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$fPage+1])) ?>" class="btn-outline btn-sm">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>