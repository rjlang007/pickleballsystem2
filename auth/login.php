<?php
// ============================================================
//  FILE: auth/login.php
//  STEP 4 UPDATE: subscription enforcement added after login
// ============================================================

// Initialize variables early
$errors = [];
$blocked = false;

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/subscription_helpers.php';   // ← NEW
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/email_helpers.php';
require_once __DIR__ . '/../includes/verification_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    startDBSession(getDB());
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) { redirect(roleDashboard()); }

if (detectSuspiciousUserAgent()) {
    http_response_code(403);
    exit('Forbidden');
}

// Rate limiting for login attempts
if (shouldRateLimit($_SERVER['REMOTE_ADDR'], 'auth_' . basename(__FILE__), 100, 60)) {
    http_response_code(429);
    $errors['general'] = 'Too many requests. Try again in 1 minute.';
    $blocked = true;
}

if (!$blocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $db = getDB();

    if (isIpBlocked($db)) {
        http_response_code(403);
        $errors['general'] = '🚫 Your IP has been blocked due to suspicious activity. Contact the court owner.';
        $blocked = true;
    }

    if (!$blocked) {
        $rateLimitKey = 'login_ip_' . getClientIp();
        if (!checkRateLimit($rateLimitKey, 5, 300)) {
            $blockedSecs       = getRateLimitRemaining($rateLimitKey);
            $blocked           = true;
            $errors['general'] = "Too many failed attempts. Please wait {$blockedSecs} seconds.";
        }
    }

    if (!$blocked) {
        $username     = sanitizeString($_POST['username'] ?? '', 50);
        $password     = $_POST['password'] ?? '';
        $username_val = $username;

        if (empty($username)) $errors['username'] = 'Please enter your username or phone.';
        if (empty($password)) $errors['password'] = 'Please enter your password.';

        if (empty($errors)) {
            $avatarColumn = 'avatar_path';
            try {
                $colCheck = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'falcon' AND table_name = 'users' AND column_name = ? LIMIT 1");
                if (!$colCheck->execute(['avatar_path']) || !$colCheck->fetchColumn()) {
                    $avatarColumn = 'avatar_url';
                }
            } catch (Throwable $e) {
                error_log('[LOGIN] Column check failed: ' . $e->getMessage());
            }

            $sql = "SELECT id, username, full_name, email, phone, "
                 . "password_hash, role, " . $avatarColumn . " AS avatar_path, "
                 . "is_active, is_banned, NULL AS ban_reason, must_change_password, email_verified "
                 . "FROM falcon.users "
                 . "WHERE (username = ? OR phone = ? OR email = ?) "
                 . "LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([$username, $username, $username]);
            $user = $stmt->fetch();

            $dummyHash   = '$2y$12$invalidsaltinvalidsalt.invalidsaltinvalidhashinvalidhash';
            $hashToCheck = $user ? $user['password_hash'] : $dummyHash;
            $passwordOk  = password_verify($password, $hashToCheck);

            if (!$user || !$passwordOk) {
                $errors['general'] = 'Invalid username or password.';
                if ($user) {
                    recordFailedLogin($db, $username, 'wrong_password');
                    checkAndAutoBlockIp($db);
                    auditLog($db, 'login_failed', $user['id'], 'users', $user['id'], null, null, 'failure', 'Wrong password');

                    $failedCount = countRecentFailedLoginsForUser($db, $user['id'], 60);
                    if ($failedCount >= 3) {
                        sendLoginAlert($user['email'], getClientIp(), $failedCount);
                    }
                } else {
                    recordFailedLogin($db, $username, 'user_not_found');
                    checkAndAutoBlockIp($db);
                    auditLog($db, 'login_failed', null, 'users', null, null, null, 'failure', "Unknown username: {$username}");
                }
            } elseif (!empty($user['is_banned'])) {
                $reason = !empty($user['ban_reason']) ? $user['ban_reason'] : 'Contact the court owner for details.';
                $errors['general'] = '🚫 Your account has been suspended. ' . clean($reason);
                recordFailedLogin($db, $username, 'banned');
                auditLog($db, 'login_banned', $user['id'], 'users', $user['id'], null, null, 'failure', 'Banned user attempted login');
            } elseif (empty($user['is_active'])) {
                $errors['general'] = '⚠️ Your account is inactive. Please contact the court owner.';
                recordFailedLogin($db, $username, 'inactive');
                auditLog($db, 'login_inactive', $user['id'], 'users', $user['id'], null, null, 'failure', 'Inactive user attempted login');
            } else {
                // ── Credentials OK — set up session ──────────────────────
                clearRateLimit('login_ip_' . getClientIp());
                session_regenerate_id(true);

                $_SESSION['user_id']        = (int)$user['id'];
                $_SESSION['username']       = $user['username'];
                $_SESSION['full_name']      = $user['full_name'];
                $_SESSION['role']           = $user['role'];
                $_SESSION['avatar']         = $user['avatar_path'];
                stampSessionFingerprint();
                $_SESSION['_last_activity'] = time();

                if (!empty($user['must_change_password'])) {
                    $_SESSION['force_pw_change'] = true;
                }

                if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
                    $newHash = hashPasswordSecure($password);
                    $db->prepare("UPDATE falcon.users SET password_hash = ? WHERE id = ?")
                       ->execute([$newHash, $user['id']]);
                }

                auditLog($db, 'login', $user['id'], 'users', $user['id'], null, null, 'success', 'Login successful');
                logActivity('Login', 'auth', 'normal', 'Logged in successfully', $user['id']);

                // ── STEP 4: Subscription check ────────────────────────────
                // Superadmins bypass entirely. Everyone else must have an
                // active (or grace-period) subscription to reach their dash.
                if (!isSubscriptionExempt($user['role'])) {
                    $sub = getSubscriptionStatus($db, (int)$user['id']);

                    if ($sub['status'] === 'expired' || $sub['status'] === 'none') {
                        // Store the notice in session so subscription.php can render it
                        $_SESSION['_sub_notice'] = [
                            'type'    => 'expired',
                            'message' => $sub['status'] === 'expired'
                                ? "🔒 Your subscription has expired. Please renew to continue."
                                : "🔒 You don't have an active subscription yet. Choose a plan to get started.",
                            'sub'     => $sub,
                        ];
                        // DO NOT setFlash here — subscription.php shows its own notice UI
                        redirect('auth/subscription.php?reason=' . $sub['status']);
                    }

                    if ($sub['status'] === 'grace') {
                        // Allow through but plant a session warning banner
                        $_SESSION['_sub_notice'] = [
                            'type'    => 'warning',
                            'message' => "⚠️ Your subscription expired " . abs($sub['days_left']) . " day(s) ago. "
                                       . "You have {$sub['grace_days_left']} grace day(s) left. Please renew soon.",
                            'sub'     => $sub,
                        ];
                    }
                }
                // ─────────────────────────────────────────────────────────

                setFlash('success', '👋 Welcome back, ' . clean($user['full_name']) . '!');
                redirect(roleDashboard());
            }
        }
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Auth page base ─────────────────────────────────────────── */
.auth-page {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: calc(100dvh - var(--navbar-h, 60px));
    padding: clamp(16px, 4vw, 48px) clamp(12px, 4vw, 24px);
    padding-bottom: max(clamp(16px, 4vw, 48px), env(safe-area-inset-bottom, 16px));
    box-sizing: border-box;
}

/* ── Auth card ──────────────────────────────────────────────── */
.auth-card {
    width: 100%;
    max-width: 420px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: clamp(24px, 6vw, 44px) clamp(20px, 6vw, 40px);
    box-sizing: border-box;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
}

.auth-card h1 {
    font-size: clamp(22px, 5vw, 28px);
    margin: 0 0 6px;
    line-height: 1.2;
}

.auth-card .subtitle {
    color: var(--muted);
    font-size: 14px;
    margin: 0 0 24px;
}

/* ── Flash / error banner ───────────────────────────────────── */
.auth-alert {
    border-radius: 10px;
    margin-bottom: 18px;
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.5;
}

/* ── Form fields ────────────────────────────────────────────── */
.auth-card .form-group { margin-bottom: 16px; }

.auth-card label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text);
}

.auth-card input[type="text"],
.auth-card input[type="password"],
.auth-card input[type="email"],
.auth-card input[type="tel"] {
    width: 100%;
    box-sizing: border-box;
    font-size: 15px;
    padding: 11px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    transition: border-color 0.2s, box-shadow 0.2s;
    -webkit-appearance: none;
}

.auth-card input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,229,160,0.15);
}

.auth-card input.error  { border-color: var(--danger, #ef4444); }
.form-error { font-size: 12px; color: var(--danger, #ef4444); margin-top: 5px; }

/* ── Password toggle ────────────────────────────────────────── */
.pwd-field-wrap { position: relative; }
.pwd-field-wrap input { padding-right: 50px; }

.pwd-toggle-btn {
    position: absolute;
    right: 4px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--muted);
    width: 44px; height: 44px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; border-radius: 8px;
    transition: color 0.2s;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
    user-select: none; -webkit-user-select: none;
}
.pwd-toggle-btn:hover,
.pwd-toggle-btn:focus-visible { color: var(--accent); outline: none; }

/* ── Forgot link ────────────────────────────────────────────── */
.auth-forgot { text-align: right; margin: -6px 0 18px; }
.auth-forgot a { font-size: 13px; color: var(--accent); text-decoration: none; }
.auth-forgot a:hover { text-decoration: underline; }

/* ── Submit button ──────────────────────────────────────────── */
.auth-card .btn-primary {
    width: 100%; padding: 13px; font-size: 15px;
    border-radius: 10px; margin-top: 4px;
}

/* ── Footer link ────────────────────────────────────────────── */
.auth-footer {
    text-align: center; font-size: 13px;
    color: var(--muted); margin-top: 20px;
}
.auth-footer a { color: var(--accent); text-decoration: none; font-weight: 600; }
.auth-footer a:hover { text-decoration: underline; }

@media (max-width: 480px) {
    .auth-page { align-items: flex-start; padding-top: clamp(16px, 5vw, 28px); }
    .auth-card input[type="text"],
    .auth-card input[type="password"],
    .auth-card input[type="email"],
    .auth-card input[type="tel"] { font-size: 16px; }
}
</style>

<div class="auth-page">
    <div class="auth-card">

        <h1>Welcome Back</h1>
        <p class="subtitle">Sign in to your Falcon account.</p>

        <?php if (!empty($errors['general'])): ?>
            <div class="flash flash-error auth-alert">
                <?= clean($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off" novalidate>
            <?= csrfField() ?>

            <div class="form-group">
                <label for="username">Username or Phone</label>
                <input type="text"
                       id="username"
                       name="username"
                       value="<?= clean($username_val ?? '') ?>"
                       placeholder="juandelacruz or 09171234567"
                       class="<?= isset($errors['username']) ? 'error' : '' ?>"
                       autocomplete="username"
                       autocorrect="off"
                       autocapitalize="none"
                       spellcheck="false"
                       inputmode="text"
                       <?= ($blocked ?? false) ? 'disabled' : 'autofocus' ?>
                       required/>
                <?php if (isset($errors['username'])): ?>
                    <div class="form-error"><?= clean($errors['username']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="pwd-field-wrap">
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder="Enter your password"
                           class="<?= isset($errors['password']) ? 'error' : '' ?>"
                           autocomplete="current-password"
                           <?= ($blocked ?? false) ? 'disabled' : '' ?>
                           required/>
                    <button type="button"
                            onclick="togglePwd()"
                            class="pwd-toggle-btn"
                            id="pwd-toggle"
                            aria-label="Toggle password visibility">👁</button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="form-error"><?= clean($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <div class="auth-forgot">
                <a href="<?= APP_URL ?>/auth/forgot_password.php">Forgot password?</a>
            </div>

            <button type="submit"
                    class="btn-primary"
                    <?= ($blocked ?? false) ? 'disabled' : '' ?>>
                Sign In
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="<?= APP_URL ?>/auth/register.php">Register here</a>
        </div>
    </div>
</div>

<script nonce="<?= csrfNonce() ?>">
function togglePwd() {
    const input = document.getElementById('password');
    const btn   = document.getElementById('pwd-toggle');
    input.type      = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
    btn.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
}

<?php if (($blocked ?? false) && ($blockedSecs ?? 0) > 0): ?>
let secs = <?= (int)$blockedSecs ?>;
const msgEl = document.querySelector('.flash-error');
const timer = setInterval(function () {
    secs--;
    if (secs <= 0) { clearInterval(timer); location.reload(); }
    else if (msgEl) msgEl.textContent = 'Too many failed attempts. Please wait ' + secs + ' seconds.';
}, 1000);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>