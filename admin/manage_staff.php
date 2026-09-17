<?php
// ============================================================
//  FILE: admin/manage_staff.php
//  Admin/SuperAdmin — list & toggle staff/referee accounts
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    verifyCsrf();
    $id = (int)$_POST['toggle_id'];
    $stmt = $db->prepare("SELECT role, is_active FROM falcon.users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if ($target && in_array($target['role'], [ROLE_STAFF, ROLE_REFEREE], true)) {
        $db->prepare("UPDATE falcon.users SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?")->execute([$id]);
        setFlash('success', $target['is_active'] ? 'Account deactivated.' : 'Account reactivated.');
    }
    redirect('admin/manage_staff.php');
}

$staff = $db->query("
    SELECT id, username, full_name, email, phone, role, is_active, created_at
    FROM falcon.users
    WHERE role IN ('staff','referee')
    ORDER BY role, full_name
")->fetchAll();

$pageTitle = 'Manage Staff & Referees';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>👥 Staff &amp; Referees</h1>
        <p>Everyone who runs the floor and scores matches.</p>
    </div>
    <a href="<?= APP_URL ?>/admin/create_staff.php" class="btn-primary btn-sm">➕ Add Staff / Referee</a>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Role</th><th>Contact</th><th>Status</th><th>Joined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($staff as $s): ?>
                <tr>
                    <td><strong><?= clean($s['full_name']) ?></strong><br><span style="font-size:11px;color:var(--muted);">@<?= clean($s['username']) ?></span></td>
                    <td><span class="badge badge-<?= $s['role']==='referee' ? 'info' : 'muted' ?>"><?= $s['role']==='referee' ? '🧑‍⚖️ Referee' : '🧑‍💼 Staff' ?></span></td>
                    <td style="font-size:12px;color:var(--muted);"><?= clean($s['email']) ?><?= $s['phone'] ? '<br>' . clean($s['phone']) : '' ?></td>
                    <td><?= $s['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Deactivated</span>' ?></td>
                    <td style="font-size:12px;color:var(--muted);white-space:nowrap;"><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="toggle_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn-outline btn-sm"><?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($staff)): ?>
                <tr><td colspan="6" class="text-muted text-center" style="padding:24px;">No staff or referee accounts yet. <a href="<?= APP_URL ?>/admin/create_staff.php">Create one →</a></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
