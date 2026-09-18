<?php
// ============================================================
//  FILE: player/change_password.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = $_SESSION['user_id'];
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $current = $_POST['current_password']  ?? '';
    $new     = $_POST['new_password']       ?? '';
    $confirm = $_POST['confirm_password']   ?? '';

    $stmt = $db->prepare("SELECT password_hash FROM falcon.users WHERE id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    if (empty($current))                                           $errors['current'] = 'Please enter your current password.';
    elseif (!password_verify($current, $row['password_hash']))     $errors['current'] = 'Current password is incorrect.';
    if (strlen($new) < 8)                                          $errors['new'] = 'New password must be at least 8 characters.';
    elseif (!preg_match('/[A-Z]/', $new))                          $errors['new'] = 'Must contain at least one uppercase letter.';
    elseif (!preg_match('/[0-9]/', $new))                          $errors['new'] = 'Must contain at least one number.';
    if ($new !== $confirm)                                         $errors['confirm'] = 'Passwords do not match.';
    if ($new === $current && empty($errors))                       $errors['new'] = 'New password must differ from current password.';

    if (empty($errors)) {
        $db->prepare("UPDATE falcon.users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
           ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
                $db->prepare("UPDATE falcon.users SET must_change_password = FALSE WHERE id = ?")
                     ->execute([$uid]);
                unset($_SESSION['force_pw_change']);
        $success = true;
    }
}

$pageTitle = 'Change Password';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Change password page ──────────────────────────────── */
.pwd-wrap {
    max-width: 460px;
    margin: 0 auto;
}

/* Password toggle button inside input */
.pwd-field-wrap {
    position: relative;
}
.pwd-field-wrap input {
    padding-right: 48px;
    font-size: 16px; /* prevents iOS zoom */
}
.pwd-toggle-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--muted);
    font-size: 18px;
    padding: 4px;
    min-height: 36px;
    min-width: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    touch-action: manipulation;
}

/* Strength bar */
.strength-track {
    background: var(--surface2);
    border-radius: 4px;
    height: 4px;
    margin-top: 8px;
    overflow: hidden;
}
.strength-fill {
    height: 100%;
    width: 0;
    border-radius: 4px;
    transition: all 0.3s;
}

/* Requirements checklist */
.pwd-requirements {
    background: var(--surface2);
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 20px;
    font-size: 13px;
}
.pwd-req-item { color: var(--muted); margin-bottom: 4px; }
.pwd-req-item:last-child { margin-bottom: 0; }
</style>

<div class="page-header flex-between">
    <div>
        <h1>Change Password</h1>
        <p>Keep your account secure with a strong password</p>
    </div>
    <a href="<?= APP_URL ?>/player/profile.php" class="btn-outline btn-sm">← My Profile</a>
</div>

<div class="pwd-wrap">
    <div class="card">

        <?php if ($success): ?>
            <div class="flash flash-success" style="border-radius:8px;margin-bottom:20px;">
                ✅ Password changed successfully! Use your new password next time you log in.
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>

            <!-- Current Password -->
            <div class="form-group">
                <label>Current Password</label>
                <div class="pwd-field-wrap">
                    <input type="password" name="current_password" id="pwd-current"
                           placeholder="Enter your current password"
                           class="<?= isset($errors['current']) ? 'error' : '' ?>" required/>
                    <button type="button" class="pwd-toggle-btn"
                            onclick="toggleField('pwd-current',this)">👁</button>
                </div>
                <?php if (isset($errors['current'])): ?>
                    <div class="form-error"><?= clean($errors['current']) ?></div>
                <?php endif; ?>
            </div>

            <!-- New Password -->
            <div class="form-group">
                <label>New Password</label>
                <div class="pwd-field-wrap">
                    <input type="password" name="new_password" id="pwd-new"
                           placeholder="Min. 8 chars, 1 uppercase, 1 number"
                           class="<?= isset($errors['new']) ? 'error' : '' ?>"
                           oninput="checkStrength(this.value)" required/>
                    <button type="button" class="pwd-toggle-btn"
                            onclick="toggleField('pwd-new',this)">👁</button>
                </div>
                <div class="strength-track">
                    <div class="strength-fill" id="strength-bar"></div>
                </div>
                <div id="strength-label" style="font-size:11px;color:var(--muted);margin-top:4px;"></div>
                <?php if (isset($errors['new'])): ?>
                    <div class="form-error"><?= clean($errors['new']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
                <label>Confirm New Password</label>
                <div class="pwd-field-wrap">
                    <input type="password" name="confirm_password" id="pwd-confirm"
                           placeholder="Re-enter new password"
                           class="<?= isset($errors['confirm']) ? 'error' : '' ?>" required/>
                    <button type="button" class="pwd-toggle-btn"
                            onclick="toggleField('pwd-confirm',this)">👁</button>
                </div>
                <?php if (isset($errors['confirm'])): ?>
                    <div class="form-error"><?= clean($errors['confirm']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Requirements -->
            <div class="pwd-requirements">
                <div style="font-weight:600;margin-bottom:8px;color:var(--muted);">Password requirements:</div>
                <div class="pwd-req-item" id="req-length">○ At least 8 characters</div>
                <div class="pwd-req-item" id="req-upper">○ At least 1 uppercase letter</div>
                <div class="pwd-req-item" id="req-number">○ At least 1 number</div>
            </div>

            <button type="submit" class="btn-primary"
                    style="width:100%;padding:14px;font-size:15px;touch-action:manipulation;">
                Update Password
            </button>
        </form>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function toggleField(inputId, btn) {
    const input = document.getElementById(inputId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

function checkStrength(val) {
    const bar    = document.getElementById('strength-bar');
    const label  = document.getElementById('strength-label');
    const reqLen = document.getElementById('req-length');
    const reqUp  = document.getElementById('req-upper');
    const reqNum = document.getElementById('req-number');

    const hasLen = val.length >= 8;
    const hasUp  = /[A-Z]/.test(val);
    const hasNum = /[0-9]/.test(val);
    const hasSym = /[^a-zA-Z0-9]/.test(val);

    reqLen.textContent = (hasLen ? '✅' : '○') + ' At least 8 characters';
    reqLen.style.color = hasLen ? 'var(--success)' : 'var(--muted)';
    reqUp.textContent  = (hasUp  ? '✅' : '○') + ' At least 1 uppercase letter';
    reqUp.style.color  = hasUp  ? 'var(--success)' : 'var(--muted)';
    reqNum.textContent = (hasNum ? '✅' : '○') + ' At least 1 number';
    reqNum.style.color = hasNum ? 'var(--success)' : 'var(--muted)';

    const score = [hasLen, hasUp, hasNum, hasSym, val.length >= 12].filter(Boolean).length;
    const levels = [
        { w:'20%', c:'var(--danger)',  t:'Very Weak' },
        { w:'40%', c:'var(--accent3)', t:'Weak' },
        { w:'60%', c:'var(--warn)',    t:'Fair' },
        { w:'80%', c:'var(--accent2)', t:'Strong' },
        { w:'100%',c:'var(--success)', t:'Very Strong' },
    ];
    const lvl = levels[Math.min(score, 4)];
    bar.style.width      = val.length ? lvl.w : '0';
    bar.style.background = lvl.c;
    label.textContent    = val.length ? lvl.t : '';
    label.style.color    = lvl.c;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>