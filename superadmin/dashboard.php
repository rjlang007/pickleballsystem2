<?php
// ============================================================
//  FILE: superadmin/dashboard.php
//  Super Admin — Command center overview
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireSuperAdmin();

$db = getDB();

$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM falcon.users WHERE role = 'player')       AS total_players,
        (SELECT COUNT(*) FROM falcon.users WHERE role = 'admin')        AS total_admins,
        (SELECT COUNT(*) FROM falcon.users WHERE role = 'staff')        AS total_staff,
        (SELECT COUNT(*) FROM falcon.users WHERE role = 'referee')      AS total_referees,
        (SELECT COUNT(*) FROM falcon.users WHERE is_banned = true)      AS banned_users,
        (SELECT COUNT(*) FROM falcon.users
         WHERE created_at >= NOW() - INTERVAL '24 hours')               AS new_today,
        (SELECT COALESCE(SUM(amount),0) FROM falcon.transactions
         WHERE type = 'topup')                                           AS total_loaded,
        (SELECT COALESCE(SUM(amount),0) FROM falcon.transactions
         WHERE type = 'deduction')                                       AS total_spent,
        (SELECT COUNT(*) FROM falcon.topup_requests WHERE status='pending') AS pending_topups,
        (SELECT COUNT(*) FROM falcon.topup_requests)                    AS total_topups
")->fetch();

$recentUsers = $db->query("
    SELECT id, username, full_name, email, role, is_banned, created_at
    FROM falcon.users ORDER BY created_at DESC LIMIT 8
")->fetchAll();

$recentTx = $db->query("
    SELECT t.type, t.amount, t.created_at, u.username
    FROM falcon.transactions t
    JOIN falcon.users u ON u.id = t.user_id
    ORDER BY t.created_at DESC LIMIT 10
")->fetchAll();

$recentTopups = $db->query("
    SELECT tr.id, tr.amount, tr.status, tr.created_at, u.username
    FROM falcon.topup_requests tr
    JOIN falcon.users u ON u.id = tr.user_id
    ORDER BY tr.created_at DESC LIMIT 6
")->fetchAll();

$impLogs = $db->query("
    SELECT il.started_at, il.ended_at, il.ip_address,
           sa.username AS super_username,
           tu.username AS target_username,
           tu.role     AS target_role
    FROM falcon.impersonation_logs il
    JOIN falcon.users sa ON sa.id = il.super_admin_id
    JOIN falcon.users tu ON tu.id = il.target_user_id
    ORDER BY il.started_at DESC LIMIT 8
")->fetchAll();

$pageTitle = 'Super Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Superadmin Dashboard responsive ── */
.sa-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}
.sa-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
.sa-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

@media (max-width: 1024px) {
    .sa-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .sa-stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    .sa-two-col {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .sa-header-actions a {
        flex: 1 1 calc(50% - 4px);
        text-align: center;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        touch-action: manipulation;
    }
    /* Hide joined date on small screens */
    .sa-users-table th:nth-child(4),
    .sa-users-table td:nth-child(4) {
        display: none;
    }
    /* Hide impersonation ended column */
    .sa-imp-table th:nth-child(4),
    .sa-imp-table td:nth-child(4) {
        display: none;
    }
}

@media (max-width: 480px) {
    .sa-stats-grid {
        gap: 6px;
    }
    .sa-header-actions a {
        font-size: 11px;
        padding: 0 8px;
    }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>⚡ Super Admin Dashboard</h1>
        <p>Full system overview — you have unrestricted access.</p>
    </div>
    <div class="sa-header-actions">
        <a href="<?= APP_URL ?>/superadmin/system_health.php"    class="btn-primary btn-sm">🩺 System Health</a>
        <a href="<?= APP_URL ?>/superadmin/users.php"            class="btn-outline btn-sm">👥 All Users</a>
        <a href="<?= APP_URL ?>/superadmin/impersonate.php"      class="btn-outline btn-sm">👁 Impersonate</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php"             class="btn-outline btn-sm">🛡 Admin View</a>
        <a href="<?= APP_URL ?>/staff/dashboard.php"             class="btn-outline btn-sm">🧑‍💼 Staff View</a>
        <a href="<?= APP_URL ?>/referee/dashboard.php"           class="btn-outline btn-sm">🧑‍⚖️ Referee View</a>
        <a href="<?= APP_URL ?>/player/dashboard.php"            class="btn-outline btn-sm">🎮 Player View</a>
        <a href="<?= APP_URL ?>/superadmin/activity_monitor.php" class="btn-outline btn-sm">🔍 Activity Log</a>
    </div>
</div>

<!-- System Stats Row 1 -->
<div class="sa-stats-grid">
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['total_players']) ?></div>
        <div class="stat-label">Total Players</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);"><?= number_format($stats['total_admins']) ?></div>
        <div class="stat-label">Admins</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);"><?= number_format($stats['banned_users']) ?></div>
        <div class="stat-label">Banned Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);"><?= number_format($stats['new_today']) ?></div>
        <div class="stat-label">New Today</div>
    </div>
</div>

<div class="sa-stats-grid">
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['total_staff']) ?></div>
        <div class="stat-label">🧑‍💼 Staff</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['total_referees']) ?></div>
        <div class="stat-label">🧑‍⚖️ Referees</div>
    </div>
    <div class="stat-card">
        <a href="<?= APP_URL ?>/admin/manage_staff.php" style="text-decoration:none;color:inherit;display:block;">
            <div class="stat-val" style="font-size:20px;">➕</div>
            <div class="stat-label">Manage Staff</div>
        </a>
    </div>
    <div class="stat-card">
        <a href="<?= APP_URL ?>/superadmin/system_health.php" style="text-decoration:none;color:inherit;display:block;">
            <div class="stat-val" style="font-size:20px;">🩺</div>
            <div class="stat-label">System Health</div>
        </a>
    </div>
</div>

<!-- System Stats Row 2 -->
<div class="sa-stats-grid">
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);">₱<?= number_format($stats['total_loaded'], 2) ?></div>
        <div class="stat-label">Total Loaded</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);">₱<?= number_format($stats['total_spent'], 2) ?></div>
        <div class="stat-label">Total Spent</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);"><?= number_format($stats['pending_topups']) ?></div>
        <div class="stat-label">Pending Top-ups</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['total_topups']) ?></div>
        <div class="stat-label">Total Top-up Requests</div>
    </div>
</div>

<!-- Recent Users + Recent Transactions -->
<div class="sa-two-col">

    <div class="card">
        <div class="card-title mb-1">👥 Recent Users</div>
        <hr class="divider"/>
        <div class="table-wrap">
            <table class="sa-users-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentUsers as $u): ?>
                    <tr>
                        <td>
                            <strong><?= clean($u['username']) ?></strong><br>
                            <span style="font-size:11px;color:var(--muted);"><?= clean($u['email']) ?></span>
                        </td>
                        <td>
                            <?php $roleBadge = match($u['role']) {
                                'admin'       => 'danger',
                                'super_admin' => 'info',
                                'staff'       => 'warn',
                                'referee'     => 'success',
                                default       => 'muted',
                            }; ?>
                            <span class="badge badge-<?= $roleBadge ?>">
                                <?= clean($u['role']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['is_banned']): ?>
                                <span class="badge badge-danger">Banned</span>
                            <?php else: ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                            <?= date('M d, Y', strtotime($u['created_at'])) ?>
                        </td>
                        <td>
                            <?php if ($u['role'] !== 'super_admin'): ?>
                            <a href="<?= APP_URL ?>/superadmin/impersonate.php?target=<?= $u['id'] ?>"
                               class="btn-outline btn-sm"
                               style="min-height:36px;touch-action:manipulation;"
                               title="View as this user">👁</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">
            <a href="<?= APP_URL ?>/superadmin/users.php" class="btn-outline btn-sm"
               style="touch-action:manipulation;">View All Users →</a>
        </div>
    </div>

    <div class="card">
        <div class="card-title mb-1">💳 Recent Transactions</div>
        <hr class="divider"/>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>User</th><th>Type</th><th>Amount</th><th>Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTx as $tx):
                        $isCredit = in_array($tx['type'], ['topup', 'refund']);
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?= clean($tx['username']) ?></td>
                        <td>
                            <?php if ($tx['type'] === 'topup'): ?>
                                <span class="badge badge-success">Top-Up</span>
                            <?php elseif ($tx['type'] === 'deduction'): ?>
                                <span class="badge badge-danger">Game Fee</span>
                            <?php else: ?>
                                <span class="badge badge-info"><?= clean($tx['type']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;color:<?= $isCredit ? 'var(--success)' : 'var(--danger)' ?>;white-space:nowrap;">
                            <?= $isCredit ? '+' : '-' ?>₱<?= number_format($tx['amount'], 2) ?>
                        </td>
                        <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                            <?= date('M d, h:i A', strtotime($tx['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Top-ups + Impersonation Log -->
<div class="sa-two-col">

    <div class="card">
        <div class="card-title mb-1">📱 Recent Top-Up Requests</div>
        <hr class="divider"/>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTopups as $tp):
                        $badge = match($tp['status']) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default    => 'warn'
                        };
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?= clean($tp['username']) ?></td>
                        <td style="color:var(--accent);font-weight:700;white-space:nowrap;">
                            ₱<?= number_format($tp['amount'], 2) ?>
                        </td>
                        <td><span class="badge badge-<?= $badge ?>"><?= ucfirst($tp['status']) ?></span></td>
                        <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                            <?= date('M d, h:i A', strtotime($tp['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">
            <a href="<?= APP_URL ?>/admin/topup_requests.php" class="btn-outline btn-sm"
               style="touch-action:manipulation;">Manage Top-ups →</a>
        </div>
    </div>

    <div class="card">
        <div class="card-title mb-1">👁 Impersonation Log</div>
        <hr class="divider"/>
        <?php if (empty($impLogs)): ?>
            <p class="text-muted text-center" style="padding:16px;">No impersonation sessions yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="sa-imp-table">
                <thead>
                    <tr><th>Target User</th><th>Role</th><th>Started</th><th>Ended</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($impLogs as $log): ?>
                    <tr>
                        <td style="font-weight:600;"><?= clean($log['target_username']) ?></td>
                        <td><span class="badge badge-muted"><?= clean($log['target_role']) ?></span></td>
                        <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                            <?= date('M d, h:i A', strtotime($log['started_at'])) ?>
                        </td>
                        <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                            <?= $log['ended_at']
                                ? date('h:i A', strtotime($log['ended_at']))
                                : '<span class="badge badge-warn">Active</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>