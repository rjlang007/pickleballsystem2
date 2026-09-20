<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/totp.php';

if (empty($_SESSION['pending_2fa_user_id'])) redirect('auth/login.php');
$db = getDB();
$userId = (int)$_SESSION['pending_2fa_user_id'];
$stmt = $db->prepare("SELECT id, username, full_name, role, avatar_path, totp_secret, totp_enabled FROM falcon.users WHERE id = ? AND is_active = TRUE AND is_banned = FALSE");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || !in_array($user['role'], ADMIN_ROLES, true) || !$user['totp_enabled'] || !$user['totp_secret']) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);
    redirect('auth/login.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!totpVerify($user['totp_secret'], (string)($_POST['code'] ?? ''))) {
        $error = 'Invalid authenticator code.';
    } else {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['avatar'] = $user['avatar_path'] ?? null;
        $_SESSION['two_factor_verified_at'] = time();
        stampSessionFingerprint();
        $_SESSION['_last_activity'] = time();
        redirect(roleDashboard());
    }
}
$pageTitle = 'Two-factor verification';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-sm" style="padding:48px 0;"><div class="card"><h1>Two-factor verification</h1><p class="text-muted">Enter the six-digit code from your authenticator app.</p><?php if ($error): ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?><form method="POST"><input type="hidden" name="csrf_token" value="<?= clean($_SESSION['csrf_token'] ?? '') ?>"><input class="form-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus><button class="btn btn-primary" style="margin-top:12px;">Verify</button></form><a href="<?= APP_URL ?>/auth/login.php" class="btn" style="margin-top:12px;">Cancel</a></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
