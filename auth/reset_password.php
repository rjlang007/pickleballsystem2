<?php
// ============================================================
//  FILE: auth/reset_password.php
//  Step 2 of password reset: player enters their email, the 6-digit
//  code we emailed them, and a new password — all in one form.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/email_helpers.php';
require_once __DIR__ . '/../includes/verification_helpers.php';

if (isLoggedIn()) { redirect(roleDashboard()); }

$errors = [];
$done   = false;
$email  = sanitizeEmail($_GET['email'] ?? ($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email     = sanitizeEmail($_POST['email'] ?? '');
    $code      = trim($_POST['code'] ?? '');
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    $rlKey = 'reset_pw_' . getClientIp();
    if (!checkRateLimit($rlKey, 8, 300)) {
        $errors['general'] = 'Too many attempts. Please wait a few minutes and try again.';
    }

    if (empty($errors) && (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (empty($errors) && strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (empty($errors) && $password !== $password2) {
        $errors['password2'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id FROM falcon.users WHERE email = ? AND is_active = TRUE LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Same generic error whether the email exists or the code is wrong —
        // avoids leaking which emails are registered.
        $genericError = 'Invalid email or code. Please check your email and try again.';

        if (!$user) {
            $errors['general'] = $genericError;
        } else {
            $result = checkPasswordResetCode($db, (int)$user['id'], $code);

            if (!$result['ok']) {
                $errors['general'] = $result['error'];
            } else {
                try {
                    $db->beginTransaction();

                    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $db->prepare("UPDATE falcon.users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$newHash, $user['id']]);

                    consumePasswordResetCode($db, (int)$result['reset_id']);

                    // Force logout of all existing sessions for this user.
                    $db->prepare("DELETE FROM falcon.php_sessions WHERE user_id = ?")->execute([$user['id']]);

                    $db->commit();

                    auditLog($db, 'password_reset_completed', $user['id'], 'users', $user['id'],
                             null, null, 'success', 'Password reset via emailed code');

                    clearRateLimit($rlKey);
                    $done = true;
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('[reset_password] error: ' . $e->getMessage());
                    $errors['general'] = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Reset Password';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <h1>Reset Password</h1>
        <p class="subtitle">Enter the code we emailed you and choose a new password.</p>

        <?php if ($done): ?>
            <div class="flash flash-success auth-alert">
                ✅ Password changed successfully! You can now log in with your new password.
            </div>
            <a href="<?= APP_URL ?>/auth/login.php" class="btn-primary"
               style="display:block;text-align:center;padding:12px;text-decoration:none;">
                Go to Login
            </a>
        <?php else: ?>
            <?php if (!empty($errors['general'])): ?>
                <div class="flash flash-error auth-alert"><?= clean($errors['general']) ?></div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <?= csrfField() ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email"
                           value="<?= clean($email) ?>"
                           placeholder="you@example.com"
                           class="<?= isset($errors['email']) ? 'error' : '' ?>"
                           autocomplete="email" required <?= $email ? '' : 'autofocus' ?>/>
                    <?php if (isset($errors['email'])): ?>
                        <div class="form-error"><?= clean($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="code">6-Digit Code</label>
                    <input type="text" id="code" name="code"
                           inputmode="numeric" pattern="\d{6}" maxlength="6"
                           autocomplete="one-time-code"
                           placeholder="123456"
                           style="text-align:center;font-size:24px;letter-spacing:8px;font-weight:700;"
                           <?= $email ? 'autofocus' : '' ?> required/>
                </div>

                <div class="form-group">
                    <label for="password">New Password</label>
                    <div class="pwd-field-wrap">
                        <input type="password" id="password" name="password"
                               placeholder="At least 8 characters"
                               class="<?= isset($errors['password']) ? 'error' : '' ?>"
                               autocomplete="new-password" required minlength="8"/>
                        <button type="button" onclick="togglePwd('password','t1')"
                                class="pwd-toggle-btn" id="t1" aria-label="Toggle password visibility">👁</button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <div class="form-error"><?= clean($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="password2">Confirm Password</label>
                    <div class="pwd-field-wrap">
                        <input type="password" id="password2" name="password2"
                               placeholder="Repeat new password"
                               class="<?= isset($errors['password2']) ? 'error' : '' ?>"
                               autocomplete="new-password" required minlength="8"/>
                        <button type="button" onclick="togglePwd('password2','t2')"
                                class="pwd-toggle-btn" id="t2" aria-label="Toggle password visibility">👁</button>
                    </div>
                    <?php if (isset($errors['password2'])): ?>
                        <div class="form-error"><?= clean($errors['password2']) ?></div>
                    <?php endif; ?>
                </div>

                <div id="strength-bar" style="height:4px;border-radius:2px;background:var(--border);margin:-8px 0 14px;overflow:hidden;">
                    <div id="strength-fill" style="height:100%;width:0;transition:width .3s,background .3s;border-radius:2px;"></div>
                </div>
                <div id="strength-label" style="font-size:11px;color:var(--muted);margin-bottom:12px;"></div>

                <button type="submit" class="btn-primary" style="width:100%;padding:13px;">
                    Set New Password
                </button>
            </form>

            <div class="auth-footer" style="margin-top:16px;">
                Didn't get a code? <a href="<?= APP_URL ?>/auth/forgot_password.php">Request a new one</a>
            </div>

            <script nonce="<?= getCspNonce() ?>">
            function togglePwd(inputId, btnId) {
                const input = document.getElementById(inputId);
                const btn   = document.getElementById(btnId);
                input.type = input.type === 'password' ? 'text' : 'password';
                btn.textContent = input.type === 'password' ? '👁' : '🙈';
            }

            document.getElementById('password').addEventListener('input', function() {
                const v = this.value;
                let score = 0;
                if (v.length >= 8)  score++;
                if (v.length >= 12) score++;
                if (/[A-Z]/.test(v)) score++;
                if (/[0-9]/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v)) score++;
                const fill   = document.getElementById('strength-fill');
                const label  = document.getElementById('strength-label');
                const colors = ['#ef4444','#f97316','#eab308','#22c55e','#00e5a0'];
                const labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
                fill.style.width      = (score * 20) + '%';
                fill.style.background = colors[score - 1] || '#ef4444';
                label.textContent     = v.length ? labels[score - 1] || 'Very Weak' : '';
            });
            </script>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
