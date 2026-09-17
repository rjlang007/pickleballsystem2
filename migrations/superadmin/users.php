<?php
// ============================================================
//  FILE: superadmin/users.php
//  Super Admin — Full user management (all roles)
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireSuperAdmin();

$db = getDB();

// ── Filters ──────────────────────────────────────────────────
$search     = trim($_GET['q']      ?? '');
$filterRole = $_GET['role']        ?? '';
$filterBan  = $_GET['banned']      ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(username ILIKE ? OR full_name ILIKE ? OR email ILIKE ? OR phone ILIKE ?)';
    $like     = "%$search%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($filterRole !== '') {
    $where[]  = 'role = ?';
    $params[] = $filterRole;
}
if ($filterBan !== '') {
    $where[]  = 'is_banned = ?';
    $params[] = $filterBan === '1' ? 'true' : 'false';
}

$whereSQL = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM falcon.users WHERE $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

$userStmt = $db->prepare("
    SELECT id, username, full_name, email, phone, role,
           is_verified, is_banned, ban_reason, created_at
    FROM falcon.users
    WHERE $whereSQL
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
");
$userStmt->execute($params);
$users = $userStmt->fetchAll();

$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Users page responsive ── */
.um-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.um-filter-form input[type="text"],
.um-filter-form select {
    font-size: 16px; /* prevents iOS zoom */
}
.um-filter-form input[type="text"] {
    min-width: 200px;
}
.um-filter-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

/* Hide phone + verified columns on tablet */
@media (max-width: 1024px) {
    .um-table th:nth-child(3),
    .um-table td:nth-child(3) {
        display: none; /* phone/email sub-cell still shown via full name cell */
    }
}

/* Hide contact + joined columns on mobile */
@media (max-width: 768px) {
    .um-table th:nth-child(3),
    .um-table td:nth-child(3),
    .um-table th:nth-child(5),
    .um-table td:nth-child(5),
    .um-table th:nth-child(7),
    .um-table td:nth-child(7) {
        display: none;
    }
    .um-filter-form > div {
        flex: 1 1 calc(50% - 5px);
    }
    .um-filter-form input[type="text"] {
        width: 100%;
        min-width: 0;
    }
    .um-filter-form select {
        width: 100%;
    }
    .um-filter-actions {
        width: 100%;
    }
    .um-filter-actions .btn-primary,
    .um-filter-actions .btn-outline {
        flex: 1;
        text-align: center;
        min-height: 44px;
        touch-action: manipulation;
    }
}

@media (max-width: 480px) {
    /* Also hide ID column */
    .um-table th:nth-child(1),
    .um-table td:nth-child(1) {
        display: none;
    }
    .um-filter-form > div {
        flex: 1 1 100%;
    }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>👥 User Management</h1>
        <p>All accounts — players, admins, and super admins.</p>
    </div>
    <a href="<?= APP_URL ?>/superadmin/dashboard.php"
       class="btn-outline btn-sm"
       style="min-height:44px;touch-action:manipulation;">← Dashboard</a>
</div>

<!-- Filters -->
<div class="card mb-3">
    <form method="GET" class="um-filter-form">
        <div>
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-input"
                   placeholder="Username, name, email, phone…"
                   value="<?= clean($search) ?>"/>
        </div>
        <div>
            <label class="form-label">Role</label>
            <select name="role" class="form-input">
                <option value="">All Roles</option>
                <option value="player"      <?= $filterRole==='player'      ? 'selected':'' ?>>Player</option>
                <option value="admin"       <?= $filterRole==='admin'       ? 'selected':'' ?>>Admin</option>
                <option value="super_admin" <?= $filterRole==='super_admin' ? 'selected':'' ?>>Super Admin</option>
            </select>
        </div>
        <div>
            <label class="form-label">Status</label>
            <select name="banned" class="form-input">
                <option value="">All</option>
                <option value="0" <?= $filterBan==='0' ? 'selected':'' ?>>Active</option>
                <option value="1" <?= $filterBan==='1' ? 'selected':'' ?>>Banned</option>
            </select>
        </div>
        <div class="um-filter-actions">
            <button type="submit" class="btn-primary btn-sm">Filter</button>
            <a href="?" class="btn-outline btn-sm">Reset</a>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-title mb-1">
        All Users
        <span class="badge badge-muted" style="font-size:13px;margin-left:8px;">
            <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
        </span>
    </div>
    <hr class="divider"/>

    <div class="table-wrap">
        <table class="um-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Contact</th>
                    <th>Role</th>
                    <th>Verified</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td style="color:var(--muted);font-size:12px;"><?= $u['id'] ?></td>
                    <td>
                        <strong><?= clean($u['username']) ?></strong><br>
                        <span style="font-size:12px;color:var(--muted);"><?= clean($u['full_name']) ?></span>
                    </td>
                    <td style="font-size:12px;">
                        <?= clean($u['email']) ?><br>
                        <span style="color:var(--muted);"><?= clean($u['phone'] ?? '—') ?></span>
                    </td>
                    <td>
                        <span class="badge badge-<?= match($u['role']) {
                            'super_admin' => 'info',
                            'admin'       => 'danger',
                            default       => 'muted'
                        } ?>">
                            <?= clean($u['role']) ?>
                        </span>
                    </td>
                    <td>
                        <?= $u['is_verified']
                            ? '<span class="badge badge-success">✓</span>'
                            : '<span class="badge badge-muted">—</span>' ?>
                    </td>
                    <td>
                        <?= $u['is_banned']
                            ? '<span class="badge badge-danger">Banned</span>'
                            : '<span class="badge badge-success">Active</span>' ?>
                    </td>
                    <td style="font-size:12px;color:var(--muted);white-space:nowrap;">
                        <?= date('M d, Y', strtotime($u['created_at'])) ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <?php if ($u['role'] !== 'super_admin'): ?>
                                <a href="<?= APP_URL ?>/superadmin/impersonate.php?target=<?= $u['id'] ?>"
                                   class="btn-outline btn-sm"
                                   style="min-height:36px;touch-action:manipulation;"
                                   title="View as this user">👁</a>
                                <a href="<?= APP_URL ?>/superadmin/edit_user.php?id=<?= $u['id'] ?>"
                                   class="btn-outline btn-sm"
                                   style="min-height:36px;touch-action:manipulation;">Edit</a>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:12px;">—</span>
                            <?php endif; ?>
                        </div>
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
        $qBase = http_build_query(array_filter(['q'=>$search,'role'=>$filterRole,'banned'=>$filterBan]));
        for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?<?= $qBase ?>&page=<?= $p ?>"
               class="btn-<?= $p===$page ? 'primary' : 'outline' ?> btn-sm"
               style="min-height:44px;touch-action:manipulation;">
                <?= $p ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>