<?php
// ============================================================
//  FILE: auth/verify_email.php
//  Player enters the 6-digit code emailed to them at registration
//  (or after login, if their account was never verified) to activate
//  their account. On success, they're logged straight in.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/email_helpers.php';
require_once __DIR__ . '/../includes/verification_helpers.php';

if (isLoggedIn()) { redirect(roleDashboard()); }

$userId = $_SESSION['verify_user_id'] ?? null;
$email  = $_SESSION['verify_email']   ?? null;

if (!$userId || !$email) {
    setFlash('error', 'Please register or log in first to verify your email.');
    redirect('auth/login.php');
}

$db = getDB();
$errors  = [];
$success = false;
$resent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $rlKey = 'resend_verify_' . $userId;
        if (!checkRateLimit($rlKey, 3, 300)) {
            $errors['general'] = 'Please wait a few minutes before requesting another code.';
        } else {
            $stmt = $db->prepare("SELECT full_name FROM falcon.users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            if ($u) {
                issueEmailVerificationCode($db, (int)$userId, $email, $u['full_name']);
                $resent = true;
            }
        }
    } else {
        $rlKey = 'verify_code_' . $userId;
        if (!checkRateLimit($rlKey, 10, 300)) {
            $errors['general'] = 'Too many attempts. Please wait a few minutes and try again.';
        } else {
            $code   = $_POST['code'] ?? '';
            $result = checkEmailVerificationCode($db, (int)$userId, $code);

            if (!$result['ok']) {
                $errors['general'] = $result['error'];
            } else {
                // Verified! Log the player straight in.
                $stmt = $db->prepare("
                    SELECT id, username, full_name, role, avatar_path
                    FROM falcon.users WHERE id = ? LIMIT 1
                ");
                $stmt->execute([$userId]);
                $u = $stmt->fetch();

                clearRateLimit($rlKey);
                unset($_SESSION['verify_user_id'], $_SESSION['verify_email'], $_SESSION['verify_purpose']);

                session_regenerate_id(true);
                $_SESSION['user_id']   = (int)$u['id'];
                $_SESSION['username']  = $u['username'];
                $_SESSION['full_name'] = $u['full_name'];
                $_SESSION['role']      = $u['role'];
                $_SESSION['avatar']    = $u['avatar_path'] ?? null;
                stampSessionFingerprint();
                $_SESSION['_last_activity'] = time();

                auditLog($db, 'email_verified', (int)$u['id'], 'users', (int)$u['id'],
                         null, null, 'success', 'Email verified at registration');

                setFlash('success', '🎉 Email verified! Welcome to ' . APP_NAME . '.');
                redirect(roleDashboard());
            }
        }
    }
}

$pageTitle = 'Verify Your Email';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <h1>Verify Your Email</h1>
        <p class="subtitle">
            We sent a 6-digit code to <strong><?= clean($email) ?></strong>.
            Enter it below to activate your account.
        </p>

        <?php if (!empty($errors['general'])): ?>
            <div class="flash flash-error auth-alert"><?= clean($errors['general']) ?></div>
        <?php endif; ?>

        <?php if ($resent): ?>
            <div class="flash flash-success auth-alert">✅ A new code has been sent to your email.</div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="verify">
            <div class="form-group">
                <label for="code">Verification Code</label>
                <input type="text" id="code" name="code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       autocomplete="one-time-code"
                       placeholder="123456"
                       style="text-align:center;font-size:28px;letter-spacing:10px;font-weight:700;"
                       autofocus required/>
            </div>
            <button type="submit" class="btn-primary" style="width:100%;padding:13px;">
                Verify &amp; Continue
            </button>
        </form>

        <form method="POST" action="" style="margin-top:14px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn-outline" style="width:100%;padding:11px;">
                Resend Code
            </button>
        </form>

        <div class="auth-footer" style="margin-top:16px;">
            Wrong account? <a href="<?= APP_URL ?>/auth/logout.php">Start over</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
