<?php
// ============================================================
//  FILE: superadmin/impersonate.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireSuperAdmin();

if (isImpersonating()) {
    setFlash('error', 'Stop the current impersonation session first.');
    redirect('superadmin/dashboard.php');
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Was a raw `!==` string comparison — not constant-time, and it never
    // rotated the token afterward. Use the central helper (hash_equals +
    // token rotation) for an endpoint this sensitive.
    verifyCsrf();

    if (!checkRateLimit('impersonate_' . (int)($_SESSION['user_id'] ?? 0), 10, 300)) {
        setFlash('error', 'Too many impersonation attempts. Please wait a few minutes.');
        redirect('superadmin/impersonate.php');
    }

    $targetId = (int)($_POST['target_id'] ?? 0);

    $target = $db->prepare("
        SELECT id, username, full_name, role, avatar_path, is_banned
        FROM falcon.users WHERE id = ?
    ");
    $target->execute([$targetId]);
    $targetUser = $target->fetch();

    if (!$targetUser) {
        setFlash('error', 'User not found.');
        redirect('superadmin/impersonate.php');
    }
    if ($targetUser['role'] === 'super_admin') {
        setFlash('error', 'Cannot impersonate another super admin.');
        redirect('superadmin/impersonate.php');
    }
    if ($targetUser['is_banned']) {
        setFlash('error', 'Cannot impersonate a banned account.');
        redirect('superadmin/impersonate.php');
    }

    $logStmt = $db->prepare("
        INSERT INTO falcon.impersonation_logs (super_admin_id, target_user_id, ip_address)
        VALUES (?, ?, ?)
        RETURNING id
    ");
    $logStmt->execute([
        $_SESSION['user_id'],
        $targetId,
        getClientIp(),
    ]);
    $impLogId = (int)$logStmt->fetchColumn();

    $_SESSION['_real_user_id']   = $_SESSION['user_id'];
    $_SESSION['_real_username']  = $_SESSION['username'];
    $_SESSION['_real_full_name'] = $_SESSION['full_name'];
    $_SESSION['_real_role']      = $_SESSION['role'];
    $_SESSION['_real_avatar']    = $_SESSION['avatar'] ?? null;
    $_SESSION['_imp_log_id']     = $impLogId;

    $_SESSION['user_id']   = $targetUser['id'];
    $_SESSION['username']  = $targetUser['username'];
    $_SESSION['full_name'] = $targetUser['full_name'];
    $_SESSION['role']      = $targetUser['role'];
    $_SESSION['avatar']    = $targetUser['avatar_path'] ?? null;

    setFlash('info', '👁 Now viewing as ' . $targetUser['full_name'] . ' (' . $targetUser['role'] . ')');

    redirect($targetUser['role'] === 'admin' ? 'admin/dashboard.php' : 'player/dashboard.php');
}

$users = $db->query("
    SELECT id, username, full_name, email, role, is_banned
    FROM falcon.users
    WHERE role != 'super_admin'
    ORDER BY role, username
")->fetchAll();

$pageTitle = 'Impersonate User';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Impersonate page responsive ── */
.imp-search-wrap {
    margin-bottom: 16px;
}
.imp-search-wrap input {
    width: 100%;
    max-width: 400px;
    font-size: 16px; /* prevents iOS zoom */
}

/* Hide email on small screens */
@media (max-width: 768px) {
    .imp-table th:nth-child(4),
    .imp-table td:nth-child(4) {
        display: none;
    }
}

/* Hide ID column on very small screens */
@media (max-width: 480px) {
    .imp-table th:nth-child(1),
    .imp-table td:nth-child(1) {
        display: none;
    }
    .imp-search-wrap input {
        max-width: 100%;
    }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>👁 Impersonate User</h1>
        <p>Log in as any user to see exactly what they see.</p>
    </div>
    <a href="<?= APP_URL ?>/superadmin/dashboard.php"
       class="btn-outline btn-sm"
       style="min-height:44px;touch-action:manipulation;">← Dashboard</a>
</div>

<div class="card">
    <div class="card-title mb-1">Select a User to Impersonate</div>
    <div class="card-subtitle" style="color:var(--warn);">
        ⚠️ Your actions while impersonating will affect the real account. Use with care.
    </div>
    <hr class="divider"/>

    <div class="imp-search-wrap">
        <input type="text" id="impSearch"
               placeholder="Search username, name, email…"
               oninput="filterUsers(this.value)"
               autocomplete="off" autocorrect="off" spellcheck="false"/>
    </div>

    <div class="table-wrap">
        <table id="impTable" class="imp-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="imp-row"
                    data-search="<?= strtolower(clean($u['username']) . ' ' . clean($u['full_name']) . ' ' . clean($u['email'])) ?>">
                    <td style="color:var(--muted);font-size:12px;"><?= (int)$u['id'] ?></td>
                    <td style="font-weight:600;"><?= clean($u['username']) ?></td>
                    <td><?= clean($u['full_name']) ?></td>
                    <td style="font-size:13px;color:var(--muted);"><?= clean($u['email']) ?></td>
                    <td>
                        <span class="badge badge-<?= $u['role'] === 'admin' ? 'danger' : 'muted' ?>">
                            <?= clean($u['role']) ?>
                        </span>
                    </td>
                    <td>
                        <?= $u['is_banned']
                            ? '<span class="badge badge-danger">Banned</span>'
                            : '<span class="badge badge-success">Active</span>' ?>
                    </td>
                    <td>
                        <?php if ($u['is_banned']): ?>
                            <span style="font-size:12px;color:var(--muted);">Unavailable</span>
                        <?php else: ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Impersonate <?= clean($u['username']) ?>?')">
                                <input type="hidden" name="csrf_token"
                                       value="<?= clean($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn-primary btn-sm"
                                        style="min-height:44px;touch-action:manipulation;">
                                    👁 View As
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function filterUsers(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.imp-row').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>