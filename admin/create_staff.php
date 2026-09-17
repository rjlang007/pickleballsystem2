<?php
// ============================================================
//  FILE: admin/create_staff.php
//  Admin/SuperAdmin — create Staff or Referee accounts
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$errors  = [];
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleIn   = $_POST['role'] ?? ROLE_STAFF;
    $role     = in_array($roleIn, [ROLE_STAFF, ROLE_REFEREE], true) ? $roleIn : ROLE_STAFF;

    if (strlen($username) < 3)
        $errors[] = 'Username must be at least 3 characters.';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username))
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    if (empty($fullName))
        $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Valid email address is required.';
    if (strlen($password) < 6)
        $errors[] = 'Password must be at least 6 characters.';
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone))
        $errors[] = 'Phone number format is invalid.';

    if (empty($errors)) {
        $exists = $db->prepare("SELECT id FROM falcon.users WHERE username = ? OR email = ?");
        $exists->execute([$username, $email]);
        if ($exists->fetch())
            $errors[] = 'Username or email already exists.';

        if ($phone !== '') {
            $phoneCheck = $db->prepare("SELECT id FROM falcon.users WHERE phone = ?");
            $phoneCheck->execute([$phone]);
            if ($phoneCheck->fetch())
                $errors[] = 'Phone number already in use.';
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $hash     = password_hash($password, PASSWORD_DEFAULT);
            $phoneVal = $phone !== '' ? $phone : null;

            // Admin-created staff/referee accounts skip the self-service
            // email code step. is_verified is left untouched — that's the
            // separate "Verify Player" admin approval flag and unrelated
            // to whether the account owns the email address on file.
            $stmt = $db->prepare("
                INSERT INTO falcon.users
                    (username, full_name, email, phone, password_hash, role,
                     must_change_password, email_verified, created_at, updated_at)
                VALUES
                    (:username, :full_name, :email, :phone, :hash, :role,
                     TRUE, TRUE, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                ':username'  => $username,
                ':full_name' => $fullName,
                ':email'     => $email,
                ':phone'     => $phoneVal,
                ':hash'      => $hash,
                ':role'      => $role,
            ]);
            $userId = (int)$stmt->fetchColumn();

            // Staff/referee accounts still get a wallet row for consistency with the rest of the app,
            // but no credits/pass — they don't play/pay through the system.
            $db->prepare(
                "INSERT INTO falcon.wallets (user_id, balance, updated_at) VALUES (?, 0, NOW())"
            )->execute([$userId]);

            $db->commit();

            $created = compact('username', 'fullName', 'email', 'phone', 'role', 'password');
            $created['id'] = $userId;
        } catch (PDOException $e) {
            $db->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Create Staff / Referee Account';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.role-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
.role-card {
    border: 2px solid var(--border); border-radius: 10px; padding: 14px;
    cursor: pointer; transition: all 0.15s; text-align: center; touch-action: manipulation;
}
.role-card:hover    { border-color: var(--accent); background: rgba(0,229,160,0.05); }
.role-card input    { display: none; }
.role-card.selected { border-color: var(--accent); background: rgba(0,229,160,0.08); }
.role-card .role-icon { font-size: 28px; margin-bottom: 6px; }
.role-card .role-name { font-size: 13px; font-weight: 700; }
.role-card .role-desc { font-size: 11px; color: var(--muted); margin-top: 2px; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.cred-box { background: var(--surface2); border: 2px solid var(--accent); border-radius: 14px; padding: 20px; margin-bottom: 16px; font-family: 'JetBrains Mono', monospace; }
.cred-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.cred-row:last-child { border-bottom: none; }
.cred-key { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.cred-val { font-size: 14px; font-weight: 700; color: var(--accent); word-break: break-all; text-align: right; }
@media (max-width: 500px) { .form-row-2 { grid-template-columns: 1fr; gap: 0; } }
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>Create Staff / Referee Account</h1>
        <p>Give your floor staff and referees their own logins.</p>
    </div>
    <div class="btn-group">
        <a href="<?= APP_URL ?>/admin/manage_staff.php" class="btn-outline btn-sm">👥 Manage Staff</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
        <div class="flash flash-error"><span><?= clean($e) ?></span><button onclick="this.parentElement.remove()">✕</button></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($created): ?>
    <div class="card" style="border-color:var(--success);max-width:600px;margin-bottom:24px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="font-size:36px;">✅</div>
            <div>
                <div style="font-family:'Bebas Neue',sans-serif;font-size:24px;color:var(--success);">Account Created!</div>
                <div style="font-size:13px;color:var(--muted);">Share these login credentials with them directly.</div>
            </div>
        </div>
        <div class="cred-box">
            <div class="cred-row"><span class="cred-key">Full Name</span><span class="cred-val"><?= clean($created['fullName']) ?></span></div>
            <div class="cred-row"><span class="cred-key">Username</span><span class="cred-val"><?= clean($created['username']) ?></span></div>
            <div class="cred-row"><span class="cred-key">Password</span><span class="cred-val" style="color:var(--warn);"><?= clean($created['password']) ?></span></div>
            <div class="cred-row"><span class="cred-key">Role</span><span class="cred-val"><?= $created['role'] === ROLE_REFEREE ? '🧑‍⚖️ Referee' : '🧑‍💼 Staff' ?></span></div>
        </div>
        <p style="font-size:12px;color:var(--muted);">They'll be asked to change this password on first login.</p>
        <a href="<?= APP_URL ?>/admin/create_staff.php" class="btn-outline btn-sm">+ Create Another</a>
    </div>
<?php else: ?>

<form method="POST" style="max-width:600px;" class="card">
    <?= csrfField() ?>

    <label>Account Type</label>
    <div class="role-grid">
        <label class="role-card" id="role-staff-card">
            <input type="radio" name="role" value="staff" checked onchange="updateRoleCards()">
            <div class="role-icon">🧑‍💼</div>
            <div class="role-name">Staff</div>
            <div class="role-desc">Runs the queue &amp; courts</div>
        </label>
        <label class="role-card" id="role-referee-card">
            <input type="radio" name="role" value="referee" onchange="updateRoleCards()">
            <div class="role-icon">🧑‍⚖️</div>
            <div class="role-name">Referee</div>
            <div class="role-desc">Scores tournament matches</div>
        </label>
    </div>

    <div class="form-row-2">
        <div><label>Full Name</label><input type="text" name="full_name" required value="<?= clean($_POST['full_name'] ?? '') ?>"></div>
        <div><label>Username</label><input type="text" name="username" required value="<?= clean($_POST['username'] ?? '') ?>"></div>
    </div>
    <div class="form-row-2">
        <div><label>Email</label><input type="email" name="email" required value="<?= clean($_POST['email'] ?? '') ?>"></div>
        <div><label>Phone (optional)</label><input type="text" name="phone" value="<?= clean($_POST['phone'] ?? '') ?>"></div>
    </div>
    <div><label>Temporary Password</label><input type="text" name="password" required minlength="6" placeholder="At least 6 characters"></div>

    <button type="submit" class="btn-primary" style="width:100%;margin-top:16px;">Create Account</button>
</form>

<script nonce="<?= getCspNonce() ?>">
function updateRoleCards() {
    document.getElementById('role-staff-card').classList.toggle('selected', document.querySelector('input[name=role][value=staff]').checked);
    document.getElementById('role-referee-card').classList.toggle('selected', document.querySelector('input[name=role][value=referee]').checked);
}
updateRoleCards();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
