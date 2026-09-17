<?php
// ============================================================
//  FILE: superadmin/activity_monitor.php
//  Super Admin — Activity log with CSRF tracking & user drill-down
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireSuperAdmin();

$db = getDB();

// ── Filters ──────────────────────────────────────────────────
$filterUser     = $_GET['user_id']  ?? '';
$filterSeverity = $_GET['severity'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterDate     = $_GET['date']     ?? date('Y-m-d');
$filterCsrf     = $_GET['csrf']     ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 30;
$offset         = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterUser !== '') {
    $where[]  = 'user_id = ?';
    $params[] = (int)$filterUser;
}
if ($filterSeverity !== '') {
    $where[]  = 'severity = ?';
    $params[] = $filterSeverity;
}
if ($filterCategory !== '') {
    $where[]  = 'category = ?';
    $params[] = $filterCategory;
}
if ($filterCsrf !== '') {
    $where[]  = 'csrf_token = ?';
    $params[] = $filterCsrf;
}
$where[]  = 'created_at::date = ?';
$params[] = $filterDate;

$whereSQL = implode(' AND ', $where);

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM falcon.activity_logs WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

// Logs
$logStmt = $db->prepare("
    SELECT id, user_id, username, role, action, category,
           severity, csrf_token, ip_address, details, created_at
    FROM falcon.activity_logs
    WHERE $whereSQL
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logStmt->execute($params);
$logs = $logStmt->fetchAll();

// All users who have logs
$allUsers = $db->query("
    SELECT DISTINCT al.user_id, al.username, u.role
    FROM falcon.activity_logs al
    LEFT JOIN falcon.users u ON u.id = al.user_id
    WHERE al.user_id IS NOT NULL
    ORDER BY al.username
")->fetchAll();

// Distinct categories
$categories = $db->query("
    SELECT DISTINCT category FROM falcon.activity_logs
    WHERE category IS NOT NULL ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

// 24hr summary stats
$stats = $db->query("
    SELECT
        COUNT(*)                                                 AS total,
        COUNT(DISTINCT user_id)                                  AS unique_users,
        COUNT(DISTINCT csrf_token)                               AS unique_tokens,
        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END)  AS critical_count,
        SUM(CASE WHEN severity = 'warning'  THEN 1 ELSE 0 END)  AS warning_count
    FROM falcon.activity_logs
    WHERE created_at >= NOW() - INTERVAL '24 hours'
")->fetch();

// Users list for left sidebar
$userList = $db->prepare("
    SELECT
        al.user_id,
        al.username,
        u.role,
        COUNT(*)                                                    AS total_actions,
        SUM(CASE WHEN al.severity = 'critical' THEN 1 ELSE 0 END)  AS critical_count,
        SUM(CASE WHEN al.severity = 'warning'  THEN 1 ELSE 0 END)  AS warning_count,
        MAX(al.created_at)                                          AS last_action
    FROM falcon.activity_logs al
    LEFT JOIN falcon.users u ON u.id = al.user_id
    WHERE al.created_at::date = ? AND al.user_id IS NOT NULL
    GROUP BY al.user_id, al.username, u.role
    ORDER BY last_action DESC
");
$userList->execute([$filterDate]);
$activeUsers = $userList->fetchAll();

// Selected user info
$selectedUser = null;
if ($filterUser !== '') {
    $uStmt = $db->prepare("SELECT username, full_name, role FROM falcon.users WHERE id = ?");
    $uStmt->execute([$filterUser]);
    $selectedUser = $uStmt->fetch();
}

// CSV export
if (isset($_GET['export'])) {
    $expStmt = $db->prepare("
        SELECT created_at, username, role, action, category, severity,
               csrf_token, ip_address, details
        FROM falcon.activity_logs
        WHERE $whereSQL
        ORDER BY created_at DESC
    ");
    $expStmt->execute($params);
    $rows = $expStmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="activity_log_' . $filterDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time','Username','Role','Action','Category','Severity','CSRF Token','IP','Details']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'], $row['username'], $row['role'],
            $row['action'], $row['category'], $row['severity'],
            $row['csrf_token'], $row['ip_address'], $row['details']
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Activity Monitor';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Activity Monitor responsive ── */
.am-stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.am-layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 20px;
    align-items: start;
}
.am-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.am-filter-form .csrf-input {
    width: 180px;
}
.am-page-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

@media (max-width: 1024px) {
    .am-stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .am-layout {
        grid-template-columns: 220px 1fr;
    }
}

@media (max-width: 768px) {
    .am-stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    /* Sidebar goes on top, full width */
    .am-layout {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    /* Sidebar user list becomes horizontal scroll row */
    .am-user-list {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        gap: 8px;
        padding-bottom: 4px;
    }
    .am-user-list a {
        flex-shrink: 0;
        min-width: 130px;
    }
    .am-filter-form .csrf-input {
        width: 100%;
    }
    .am-page-header-actions a {
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        touch-action: manipulation;
    }
    /* Hide less critical log columns */
    .am-log-table th:nth-child(7),
    .am-log-table td:nth-child(7),
    .am-log-table th:nth-child(8),
    .am-log-table td:nth-child(8) {
        display: none;
    }
}

@media (max-width: 480px) {
    .am-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    /* On very small screens also hide category column */
    .am-log-table th:nth-child(4),
    .am-log-table td:nth-child(4) {
        display: none;
    }
    .am-filter-form > div {
        width: 100%;
    }
    .am-filter-form select,
    .am-filter-form input[type="text"] {
        width: 100%;
    }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>🔍 Activity Monitor</h1>
        <p>
            <?php if ($selectedUser): ?>
                Logs for <strong><?= clean($selectedUser['username']) ?></strong>
                (<?= clean($selectedUser['role']) ?>)
                — <a href="?date=<?= $filterDate ?>" style="color:var(--accent);">← All Users</a>
            <?php else: ?>
                All user activity tracked by CSRF token
            <?php endif; ?>
        </p>
    </div>
    <div class="am-page-header-actions">
        <a href="?<?= http_build_query(array_filter(['user_id'=>$filterUser,'severity'=>$filterSeverity,'category'=>$filterCategory,'date'=>$filterDate,'csrf'=>$filterCsrf])) ?>&export=csv"
           class="btn-outline btn-sm">⬇ Export CSV</a>
        <a href="<?= APP_URL ?>/superadmin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<!-- 24hr Stats -->
<div class="am-stats-grid">
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['total']) ?></div>
        <div class="stat-label">Actions (24h)</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);"><?= number_format($stats['unique_users']) ?></div>
        <div class="stat-label">Active Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--info);"><?= number_format($stats['unique_tokens']) ?></div>
        <div class="stat-label">CSRF Tokens</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);"><?= number_format($stats['warning_count']) ?></div>
        <div class="stat-label">Warnings</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);"><?= number_format($stats['critical_count']) ?></div>
        <div class="stat-label">Critical</div>
    </div>
</div>

<div class="am-layout">

    <!-- LEFT SIDEBAR: Active Users -->
    <div>
        <div class="card">
            <div class="card-title mb-1" style="font-size:14px;">👥 Active Users</div>
            <!-- Date picker -->
            <form method="GET" style="margin-bottom:12px;">
                <?php if ($filterUser): ?>
                    <input type="hidden" name="user_id" value="<?= (int)$filterUser ?>"/>
                <?php endif; ?>
                <input type="date" name="date" class="form-input"
                       value="<?= clean($filterDate) ?>"
                       onchange="this.form.submit()"
                       style="width:100%;font-size:16px;"/>
            </form>
            <hr class="divider"/>
            <?php if (empty($activeUsers)): ?>
                <p class="text-muted" style="font-size:13px;padding:8px 0;">No activity on this day.</p>
            <?php else: ?>
                <div class="am-user-list" style="display:flex;flex-direction:column;gap:4px;">
                    <?php foreach ($activeUsers as $au):
                        $isSelected = (string)$filterUser === (string)$au['user_id'];
                        $hasCritical = $au['critical_count'] > 0;
                        $hasWarning  = $au['warning_count']  > 0;
                    ?>
                    <a href="?user_id=<?= $au['user_id'] ?>&date=<?= $filterDate ?>"
                       style="display:block;padding:10px 12px;border-radius:8px;text-decoration:none;
                              background:<?= $isSelected ? 'var(--accent)' : 'var(--surface2)' ?>;
                              color:<?= $isSelected ? '#000' : 'var(--text)' ?>;
                              border:1px solid <?= $hasCritical ? 'var(--danger)' : ($hasWarning ? 'var(--warn)' : 'transparent') ?>;
                              min-height:44px;touch-action:manipulation;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <strong style="font-size:13px;"><?= clean($au['username']) ?></strong>
                            <span style="font-size:11px;opacity:0.7;"><?= $au['total_actions'] ?></span>
                        </div>
                        <div style="font-size:11px;margin-top:2px;opacity:0.7;">
                            <?= clean($au['role']) ?>
                            <?php if ($hasCritical): ?>
                                &nbsp;<span style="color:<?= $isSelected ? '#000' : 'var(--danger)' ?>;font-weight:700;">
                                    ⚠ <?= $au['critical_count'] ?> critical
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;opacity:0.6;margin-top:2px;">
                            Last: <?= date('h:i A', strtotime($au['last_action'])) ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Auto-refresh -->
        <div class="card" style="margin-top:16px;padding:14px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px;">⚡ Auto Refresh</div>
            <select id="refreshSel" class="form-input" style="width:100%;font-size:16px;"
                    onchange="setRefresh(this.value)">
                <option value="0">Off</option>
                <option value="15">Every 15s</option>
                <option value="30" selected>Every 30s</option>
                <option value="60">Every 60s</option>
            </select>
            <div id="refreshCountdown" style="font-size:12px;color:var(--muted);margin-top:6px;text-align:center;"></div>
        </div>
    </div>

    <!-- RIGHT: Filters + Log Table -->
    <div>
        <!-- Filters -->
        <div class="card mb-3">
            <form method="GET" class="am-filter-form">
                <?php if ($filterUser): ?>
                    <input type="hidden" name="user_id" value="<?= (int)$filterUser ?>"/>
                <?php endif; ?>
                <input type="hidden" name="date" value="<?= clean($filterDate) ?>"/>

                <div>
                    <label class="form-label">Severity</label>
                    <select name="severity" class="form-input" style="font-size:16px;">
                        <option value="">All</option>
                        <option value="normal"   <?= $filterSeverity==='normal'   ? 'selected':'' ?>>Normal</option>
                        <option value="warning"  <?= $filterSeverity==='warning'  ? 'selected':'' ?>>⚠ Warning</option>
                        <option value="critical" <?= $filterSeverity==='critical' ? 'selected':'' ?>>🚨 Critical</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Category</label>
                    <select name="category" class="form-input" style="font-size:16px;">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= clean($cat) ?>"
                                <?= $filterCategory===$cat ? 'selected':'' ?>>
                                <?= clean(ucfirst($cat)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">CSRF Token</label>
                    <input type="text" name="csrf" class="form-input csrf-input"
                           placeholder="Paste token…"
                           value="<?= clean($filterCsrf) ?>"
                           style="font-size:16px;"/>
                </div>

                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="submit" class="btn-primary btn-sm"
                            style="min-height:44px;touch-action:manipulation;">Filter</button>
                    <a href="?<?= $filterUser ? "user_id=$filterUser&" : '' ?>date=<?= $filterDate ?>"
                       class="btn-outline btn-sm"
                       style="min-height:44px;touch-action:manipulation;">Reset</a>
                </div>
            </form>
        </div>

        <!-- Log Table -->
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                <div class="card-title" style="margin:0;">
                    📋 Logs for <?= date('F d, Y', strtotime($filterDate)) ?>
                    <span class="badge badge-muted" style="font-size:12px;margin-left:6px;">
                        <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
            <hr class="divider"/>

            <?php if (empty($logs)): ?>
                <p class="text-muted text-center" style="padding:40px 0;">
                    No activity found for the selected filters.
                </p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="am-log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Category</th>
                            <th>Severity</th>
                            <th>CSRF Token</th>
                            <th>IP</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $rowBg = match($log['severity']) {
                                'critical' => 'background:rgba(239,68,68,0.07);',
                                'warning'  => 'background:rgba(245,158,11,0.07);',
                                default    => ''
                            };
                            $severityBadge = match($log['severity']) {
                                'critical' => 'danger',
                                'warning'  => 'warn',
                                default    => 'success'
                            };
                            $severityIcon = match($log['severity']) {
                                'critical' => '🚨',
                                'warning'  => '⚠️',
                                default    => '✓'
                            };
                            $shortCsrf = $log['csrf_token']
                                ? substr($log['csrf_token'], 0, 10) . '…'
                                : '—';
                        ?>
                        <tr style="<?= $rowBg ?>">
                            <td style="font-size:12px;white-space:nowrap;">
                                <strong><?= date('h:i:s A', strtotime($log['created_at'])) ?></strong>
                            </td>
                            <td>
                                <?php if ($log['user_id']): ?>
                                    <a href="?user_id=<?= $log['user_id'] ?>&date=<?= $filterDate ?>"
                                       style="font-weight:600;color:var(--accent);font-size:13px;">
                                        <?= clean($log['username']) ?>
                                    </a><br>
                                    <span style="font-size:11px;color:var(--muted);"><?= clean($log['role']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:13px;">Guest</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;font-size:13px;">
                                <?= $severityIcon ?> <?= clean($log['action']) ?>
                            </td>
                            <td>
                                <span class="badge badge-info" style="font-size:11px;">
                                    <?= clean($log['category']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $severityBadge ?>" style="font-size:11px;">
                                    <?= ucfirst($log['severity']) ?>
                                </span>
                            </td>
                            <td style="font-family:monospace;font-size:12px;">
                                <?php if ($log['csrf_token']): ?>
                                    <a href="?<?= $filterUser ? "user_id=$filterUser&" : '' ?>date=<?= $filterDate ?>&csrf=<?= urlencode($log['csrf_token']) ?>"
                                       title="Filter by this token"
                                       style="color:var(--muted);">
                                        <?= $shortCsrf ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;font-family:monospace;color:var(--muted);">
                                <?= clean($log['ip_address'] ?? '—') ?>
                            </td>
                            <td style="font-size:12px;color:var(--muted);max-width:180px;
                                       white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                title="<?= clean($log['details'] ?? '') ?>">
                                <?= clean($log['details'] ?? '—') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="display:flex;gap:6px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
                <?php
                $qBase = http_build_query(array_filter([
                    'user_id'  => $filterUser,
                    'severity' => $filterSeverity,
                    'category' => $filterCategory,
                    'date'     => $filterDate,
                    'csrf'     => $filterCsrf,
                ]));
                for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?<?= $qBase ?>&page=<?= $p ?>"
                       class="btn-<?= $p===$page ? 'primary':'outline' ?> btn-sm"
                       style="min-height:44px;touch-action:manipulation;">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ── Auto-refresh countdown ───────────────────────────────────
let refreshTimer = null;
let countdown    = 0;
let refreshSecs  = 30;

function setRefresh(secs) {
    refreshSecs = parseInt(secs);
    clearInterval(refreshTimer);
    document.getElementById('refreshCountdown').textContent = '';
    if (refreshSecs > 0) startRefresh();
}

function startRefresh() {
    countdown = refreshSecs;
    updateCountdown();
    refreshTimer = setInterval(() => {
        countdown--;
        if (countdown <= 0) {
            location.reload();
        } else {
            updateCountdown();
        }
    }, 1000);
}

function updateCountdown() {
    const el = document.getElementById('refreshCountdown');
    if (el) el.textContent = `Refreshing in ${countdown}s`;
}

startRefresh();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>