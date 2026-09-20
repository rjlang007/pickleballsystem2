<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/totp.php';
requireAdmin();
$db = getDB();
$userId = (int)$_SESSION['user_id'];
$stmt = $db->prepare('SELECT username, email, totp_secret, totp_enabled FROM falcon.users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (($_POST['action'] ?? '') === 'disable') {
        $db->prepare('UPDATE falcon.users SET totp_enabled = FALSE, totp_secret = NULL, totp_recovery_codes = \'[]\'::jsonb WHERE id = ?')->execute([$userId]);
        $user['totp_enabled'] = false; $user['totp_secret'] = null; $message = 'Two-factor authentication disabled.';
    } elseif (!$user['totp_secret']) {
        $secret = totpGenerateSecret();
        $db->prepare('UPDATE falcon.users SET totp_secret = ?, totp_enabled = FALSE WHERE id = ?')->execute([$secret, $userId]);
        $user['totp_secret'] = $secret; $message = 'Scan or enter the secret, then submit the code to enable protection.';
    } elseif (totpVerify($user['totp_secret'], (string)($_POST['code'] ?? ''))) {
        $db->prepare('UPDATE falcon.users SET totp_enabled = TRUE WHERE id = ?')->execute([$userId]);
        $user['totp_enabled'] = true; $message = 'Two-factor authentication enabled.';
    } else { $error = 'That authenticator code is invalid.'; }
}
$uri = $user['totp_secret'] ? 'otpauth://totp/' . rawurlencode(APP_NAME . ':' . $user['email']) . '?secret=' . $user['totp_secret'] . '&issuer=' . rawurlencode(APP_NAME) : '';
$pageTitle = 'Two-factor authentication';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-sm" style="padding:32px 0;"><div class="card"><h1>Two-factor authentication</h1><?php if ($message): ?><div class="alert alert-success"><?= clean($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-error"><?= clean($error) ?></div><?php endif; ?><?php if ($user['totp_enabled']): ?><p>Two-factor authentication is enabled for this administrator account.</p><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="disable"><button class="btn btn-danger">Disable 2FA</button></form><?php else: ?><?php if (!$user['totp_secret']): ?><p>Generate a secret to begin enrollment.</p><form method="POST"><?= csrfField() ?><button class="btn btn-primary">Generate setup secret</button></form><?php else: ?><p>Enter this URI in your authenticator app:</p><textarea class="form-input" rows="3" readonly><?= clean($uri) ?></textarea><p class="text-muted">Secret: <strong><?= clean($user['totp_secret']) ?></strong></p><form method="POST"><input type="hidden" name="csrf_token" value="<?= clean($_SESSION['csrf_token'] ?? '') ?>"><label>Authenticator code<input class="form-input" name="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required></label><button class="btn btn-primary" style="margin-top:12px;">Enable 2FA</button></form><?php endif; ?><?php endif; ?></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
