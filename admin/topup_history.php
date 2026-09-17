<?php
// ============================================================
//  FILE: admin/topup_history.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

$fStatus  = $_GET['status']    ?? 'all';
$fMethod  = $_GET['method']    ?? 'all';
$fFrom    = $_GET['date_from'] ?? date('Y-m-01');
$fTo      = $_GET['date_to']   ?? date('Y-m-d');
$fSearch  = trim($_GET['search'] ?? '');
$fPage    = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($fPage - 1) * $perPage;

if (isset($_GET['export'])) {
    $expStmt = $db->prepare("
        SELECT tr.id, u.username, u.full_name, u.phone,
               tr.amount, tr.method, tr.gcash_ref_no,
               tr.status, tr.review_note,
               tr.created_at, tr.reviewed_at,
               rev.username AS reviewed_by_username
        FROM falcon.topup_requests tr
        JOIN falcon.users u ON u.id = tr.user_id
        LEFT JOIN falcon.users rev ON rev.id = tr.reviewed_by
        WHERE tr.created_at::date BETWEEN ? AND ?
        ORDER BY tr.created_at DESC
    ");
    $expStmt->execute([$fFrom, $fTo]);
    $rows = $expStmt->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="topup_history_' . $fFrom . '_to_' . $fTo . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Username','Full Name','Phone','Amount','Method','GCash Ref','Status','Review Note','Submitted','Reviewed','Reviewed By']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'], $row['username'], $row['full_name'], $row['phone'],
            $row['amount'], $row['method'], $row['gcash_ref_no'] ?? '',
            $row['status'], $row['review_note'] ?? '',
            $row['created_at'], $row['reviewed_at'] ?? '',
            $row['reviewed_by_username'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$where  = "WHERE tr.created_at::date BETWEEN ? AND ?";
$params = [$fFrom, $fTo];

if ($fStatus !== 'all') { $where .= " AND tr.status = ?"; $params[] = $fStatus; }
if ($fMethod !== 'all') { $where .= " AND tr.method = ?"; $params[] = $fMethod; }
if ($fSearch !== '') {
    $where   .= " AND (u.username ILIKE ? OR u.full_name ILIKE ? OR tr.gcash_ref_no ILIKE ?)";
    $like     = "%$fSearch%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$countStmt = $db->prepare("
    SELECT COUNT(*) FROM falcon.topup_requests tr
    JOIN falcon.users u ON u.id = tr.user_id $where
");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("
    SELECT tr.id, tr.amount, tr.method, tr.gcash_ref_no,
           tr.screenshot_path, tr.status, tr.review_note,
           tr.created_at, tr.reviewed_at,
           u.username, u.full_name,
           rev.username AS reviewed_by_name
    FROM falcon.topup_requests tr
    JOIN falcon.users u ON u.id = tr.user_id
    LEFT JOIN falcon.users rev ON rev.id = tr.reviewed_by
    $where
    ORDER BY tr.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$sumStmt = $db->prepare("
    SELECT
        COUNT(*)                                               AS total,
        COUNT(*) FILTER (WHERE tr.status='approved')          AS approved,
        COUNT(*) FILTER (WHERE tr.status='rejected')          AS rejected,
        COUNT(*) FILTER (WHERE tr.status='pending')           AS pending,
        COALESCE(SUM(tr.amount) FILTER (WHERE tr.status='approved'), 0) AS total_approved
    FROM falcon.topup_requests tr
    JOIN falcon.users u ON u.id = tr.user_id
    $where
");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

$pageTitle = 'Top-Up History';
require_once __DIR__ . '/../includes/header.php';
?>
<?= breadcrumb([
    ['label' => 'Home', 'href' => APP_URL],
    ['label' => 'Top-Up Requests', 'href' => APP_URL . '/admin/topup_requests.php'],
    ['label' => 'History']
]) ?>

<div class="page-header flex-between">
    <div>
        <h1>Top-Up History</h1>
        <p>Full log of all GCash and in-person top-up requests</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/admin/topup_requests.php" class="btn-primary btn-sm">⏳ Pending Review</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- Summary Stats -->
<div class="dashboard-grid stats-5col mb-3">
    <div class="stat-card">
        <div class="stat-val"><?= $summary['total'] ?></div>
        <div class="stat-label">Total Requests</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);"><?= $summary['approved'] ?></div>
        <div class="stat-label">Approved</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);"><?= $summary['pending'] ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);"><?= $summary['rejected'] ?></div>
        <div class="stat-label">Rejected</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);">₱<?= number_format($summary['total_approved'], 2) ?></div>
        <div class="stat-label">Credits Loaded</div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <form method="GET" class="filter-form">
        <div class="form-group wide">
            <label>Search</label>
            <input type="text" name="search" value="<?= clean($fSearch) ?>"
                   placeholder="Username, name, GCash ref…"/>
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status">
                <?php foreach (['all'=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $fStatus===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Method</label>
            <select name="method">
                <option value="all" <?= $fMethod==='all'?'selected':'' ?>>All</option>
                <option value="gcash" <?= $fMethod==='gcash'?'selected':'' ?>>GCash</option>
                <option value="cash" <?= $fMethod==='cash'?'selected':'' ?>>Cash</option>
            </select>
        </div>
        <div class="form-group">
            <label>From</label>
            <input type="date" name="date_from" value="<?= $fFrom ?>"/>
        </div>
        <div class="form-group">
            <label>To</label>
            <input type="date" name="date_to" value="<?= $fTo ?>"/>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <button type="submit" class="btn-primary btn-sm">Filter</button>
            <a href="?" class="btn-outline btn-sm">Reset</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>1])) ?>"
               class="btn-outline btn-sm">⬇ CSV</a>
        </div>
    </form>
</div>

<!-- Results Table -->
<div class="card">
    <div class="flex-between mb-2">
        <div class="card-title">📋 All Requests</div>
        <div class="card-subtitle"><?= $totalRows ?> results · Page <?= $fPage ?>/<?= $totalPages ?></div>
    </div>
    <hr class="divider"/>

    <?php if (empty($requests)): ?>
        <div style="text-align:center;padding:40px;">
            <div style="font-size:48px;">📭</div>
            <p class="text-muted mt-1">No requests found for the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Player</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th class="col-hide-sm">GCash Ref</th>
                        <th class="col-hide-sm">Screenshot</th>
                        <th>Status</th>
                        <th class="col-hide-sm">Reviewed By</th>
                        <th class="col-hide-xs">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r):
                        $badge = match($r['status']) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default    => 'warn'
                        };
                    ?>
                        <tr>
                            <td style="font-size:12px;white-space:nowrap;color:var(--muted);">
                                <?= date('M d, Y', strtotime($r['created_at'])) ?><br/>
                                <span style="color:var(--accent);"><?= date('h:i A', strtotime($r['created_at'])) ?></span>
                            </td>
                            <td>
                                <strong><?= clean($r['full_name']) ?></strong><br/>
                                <span style="font-size:12px;color:var(--muted);">@<?= clean($r['username']) ?></span>
                            </td>
                            <td style="color:var(--accent);font-weight:700;white-space:nowrap;">
                                ₱<?= number_format($r['amount'], 2) ?>
                            </td>
                            <td>
                                <span class="badge badge-muted"><?= ucfirst($r['method'] ?? 'gcash') ?></span>
                            </td>
                            <td class="col-hide-sm" style="font-family:monospace;font-size:12px;">
                                <?= $r['gcash_ref_no'] ? clean($r['gcash_ref_no']) : '—' ?>
                            </td>
                            <td class="col-hide-sm">
                                <?php if ($r['screenshot_path']): ?>
                                    <a href="<?= APP_URL ?>/uploads/screenshots/<?= urlencode($r['screenshot_path']) ?>"
                                       target="_blank" class="btn-outline btn-sm">View</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $badge ?>"><?= ucfirst($r['status']) ?></span>
                            </td>
                            <td class="col-hide-sm" style="font-size:12px;color:var(--muted);">
                                <?= $r['reviewed_by_name'] ? '@'.clean($r['reviewed_by_name']) : '—' ?>
                                <?php if ($r['reviewed_at']): ?>
                                    <br/><?= date('M d, h:i A', strtotime($r['reviewed_at'])) ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-xs" style="font-size:12px;color:var(--muted);max-width:140px;overflow:hidden;text-overflow:ellipsis;">
                                <?= $r['review_note'] ? clean($r['review_note']) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
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