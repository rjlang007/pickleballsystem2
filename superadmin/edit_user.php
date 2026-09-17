<?php
// ============================================================
//  FILE: superadmin/edit_user.php
//  Super Admin — Edit any user's details and role
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireSuperAdmin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    setFlash('error', 'No user specified.');
    redirect('superadmin/users.php');
}

$userStmt = $db->prepare("SELECT * FROM falcon.users WHERE id = ?");
$userStmt->execute([$id]);
$u = $userStmt->fetch();

if (!$u || $u['role'] === 'super_admin') {
    setFlash('error', 'User not found or cannot edit super admin.');
    redirect('superadmin/users.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName   = sanitizeString($_POST['full_name']   ?? '', 100);
    $email      = sanitizeString($_POST['email']       ?? '', 150);
    $phone      = sanitizeString($_POST['phone']       ?? '', 20);
    $role       = in_array($_POST['role'] ?? '', ['player','admin']) ? $_POST['role'] : 'player';
    $isBanned   = isset($_POST['is_banned']) ? true : false;
    $banReason  = sanitizeString($_POST['ban_reason']  ?? '', 255);
    $isVerified = isset($_POST['is_verified']) ? true : false;
    $newPass    = $_POST['new_password'] ?? '';

    if (empty($fullName)) $errors['full_name'] = 'Full name is required.';
    if (empty($email))    $errors['email']     = 'Email is required.';

    if (empty($errors)) {
        if (!empty($newPass)) {
            if (strlen($newPass) < 8) {
                $errors['new_password'] = 'Password must be at least 8 characters.';
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $db->prepare("UPDATE falcon.users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
            }
        }

        if (empty($errors)) {
            $db->prepare("
                UPDATE falcon.users
                SET full_name = ?, email = ?, phone = ?, role = ?,
                    is_banned = ?, ban_reason = ?, is_verified = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $fullName, $email, $phone, $role,
                $isBanned ? 'true' : 'false',
                $isBanned ? $banReason : null,
                $isVerified ? 'true' : 'false',
                $id
            ]);

            setFlash('success', '✅ User updated successfully.');
            redirect("superadmin/edit_user.php?id=$id");
        }
    }

    $userStmt->execute([$id]);
    $u = $userStmt->fetch();
}

// Get wallet balance
$walletStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$walletStmt->execute([$id]);
$balance = $walletStmt->fetchColumn() ?? 0;

$pageTitle = 'Edit User: ' . $u['username'];
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Edit User responsive ── */
.eu-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.eu-form-card {
    max-width: 600px;
    width: 100%;
}
@media (max-width: 480px) {
    .eu-header-actions a {
        flex: 1 1 calc(50% - 4px);
        text-align: center;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .eu-form-actions {
        flex-direction: column;
    }
    .eu-form-actions .btn-primary,
    .eu-form-actions .btn-outline {
        width: 100%;
        text-align: center;
        min-height: 44px;
    }
    .eu-checkboxes {
        flex-direction: column;
        gap: 12px;
    }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>✏️ Edit User: <?= clean($u['username']) ?></h1>
        <p>
            Role: <strong><?= clean($u['role']) ?></strong>
            &nbsp;|&nbsp; ID: #<?= $u['id'] ?>
            &nbsp;|&nbsp; Wallet: <strong style="color:var(--success);">₱<?= number_format($balance, 2) ?></strong>
        </p>
    </div>
    <div class="eu-header-actions">
        <a href="<?= APP_URL ?>/superadmin/impersonate.php?target=<?= $u['id'] ?>"
           class="btn-outline btn-sm"
           style="min-height:44px;touch-action:manipulation;">👁 View As</a>
        <a href="<?= APP_URL ?>/superadmin/users.php"
           class="btn-outline btn-sm"
           style="min-height:44px;touch-action:manipulation;">← Users</a>
    </div>
</div>

<div class="card eu-form-card">
    <form method="POST">
        <?php csrfField(); ?>

        <div class="form-group">
            <label class="form-label">Username</label>
            <input type="text" class="form-input" value="<?= clean($u['username']) ?>"
                   disabled style="font-size:16px;"/>
            <small class="text-muted">Username cannot be changed.</small>
        </div>

        <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input type="text" name="full_name"
                   class="form-input <?= isset($errors['full_name']) ? 'error' : '' ?>"
                   value="<?= clean($u['full_name']) ?>"
                   style="font-size:16px;"/>
            <?php if (isset($errors['full_name'])): ?>
                <div class="form-error"><?= $errors['full_name'] ?></div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label">Email *</label>
            <input type="email" name="email"
                   class="form-input <?= isset($errors['email']) ? 'error' : '' ?>"
                   value="<?= clean($u['email']) ?>"
                   style="font-size:16px;"/>
            <?php if (isset($errors['email'])): ?>
                <div class="form-error"><?= $errors['email'] ?></div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-input"
                   value="<?= clean($u['phone'] ?? '') ?>"
                   style="font-size:16px;"/>
        </div>

        <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" class="form-input" style="font-size:16px;">
                <option value="player" <?= $u['role']==='player' ? 'selected':'' ?>>Player</option>
                <option value="admin"  <?= $u['role']==='admin'  ? 'selected':'' ?>>Admin</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">
                New Password
                <small class="text-muted">(leave blank to keep current)</small>
            </label>
            <input type="password" name="new_password"
                   class="form-input <?= isset($errors['new_password']) ? 'error' : '' ?>"
                   placeholder="Min 8 characters…"
                   style="font-size:16px;"/>
            <?php if (isset($errors['new_password'])): ?>
                <div class="form-error"><?= $errors['new_password'] ?></div>
            <?php endif; ?>
        </div>

        <hr class="divider"/>

        <div class="eu-checkboxes" style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;min-height:44px;">
                <input type="checkbox" name="is_verified" <?= $u['is_verified'] ? 'checked' : '' ?>/>
                Email Verified
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;min-height:44px;">
                <input type="checkbox" name="is_banned" id="isBannedCheck"
                       <?= $u['is_banned'] ? 'checked' : '' ?>
                       onchange="document.getElementById('banReasonGroup').style.display = this.checked ? 'block' : 'none'"/>
                Banned
            </label>
        </div>

        <div id="banReasonGroup" style="<?= $u['is_banned'] ? '' : 'display:none;' ?>">
            <div class="form-group">
                <label class="form-label">Ban Reason</label>
                <textarea name="ban_reason" class="form-input" rows="2"
                          placeholder="Reason for ban…"
                          style="font-size:16px;"><?= clean($u['ban_reason'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="eu-form-actions" style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;">
            <button type="submit" class="btn-primary"
                    style="min-height:44px;touch-action:manipulation;">Save Changes</button>
            <a href="<?= APP_URL ?>/superadmin/users.php" class="btn-outline"
               style="min-height:44px;touch-action:manipulation;display:inline-flex;align-items:center;">
               Cancel
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>