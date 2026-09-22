<?php
// ============================================================
//  FILE: auth/register.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) {
    redirect(isAdmin() ? 'admin/dashboard.php' : 'player/dashboard.php');
}

$errors = [];
$values = ['username' => '', 'full_name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $rateLimitKey = 'register_' . getClientIp();
    if (!checkRateLimit($rateLimitKey, 3, 600)) {
        $errors['general'] = 'Too many registration attempts. Please wait a few minutes.';
    } else {
        $username  = sanitizeString($_POST['username']  ?? '', 50);
        $full_name = sanitizeString($_POST['full_name'] ?? '', 120);
        $email     = sanitizeEmail($_POST['email']      ?? '');
        $phone     = sanitizeString($_POST['phone']     ?? '', 20);
        $password  = $_POST['password']         ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        $values = compact('username', 'full_name', 'email', 'phone');

        if (strlen($username) < 3 || strlen($username) > 50)
            $errors['username'] = 'Username must be 3–50 characters.';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username))
            $errors['username'] = 'Username can only contain letters, numbers, and underscores.';
        if (strlen($full_name) < 2)
            $errors['full_name'] = 'Please enter your full name.';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors['email'] = 'A valid email address is required.';
        if (!preg_match('/^(09|\+639)\d{9}$/', $phone))
            $errors['phone'] = 'Enter a valid PH mobile number (e.g. 09171234567).';
        if (strlen($password) < 8)
            $errors['password'] = 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Z]/', $password))
            $errors['password'] = 'Password must contain at least one uppercase letter.';
        if (!preg_match('/[0-9]/', $password))
            $errors['password'] = 'Password must contain at least one number.';
        if ($password !== $confirm)
            $errors['confirm_password'] = 'Passwords do not match.';

        if (empty($errors)) {
            $db = getDB();
            $chk = $db->prepare("SELECT id FROM falcon.users WHERE username = ?");
            $chk->execute([$username]);
            if ($chk->fetch()) $errors['username'] = 'Username is already taken.';

            $chk2 = $db->prepare("SELECT id FROM falcon.users WHERE phone = ?");
            $chk2->execute([$phone]);
            if ($chk2->fetch()) $errors['phone'] = 'Phone number is already registered.';

            $chk3 = $db->prepare("SELECT id FROM falcon.users WHERE email = ?");
            $chk3->execute([$email]);
            if ($chk3->fetch()) $errors['email'] = 'Email is already registered.';
        }

        if (empty($errors)) {
            $db   = getDB();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO falcon.users
                        (username, full_name, email, phone, password_hash, role, is_verified, email_verified)
                    VALUES (?, ?, ?, ?, ?, 'player', TRUE, TRUE)
                    RETURNING id
                ");
                $stmt->execute([$username, $full_name, $email, $phone, $hash]);
                $userId = $stmt->fetch()['id'];

                $db->commit();
                clearRateLimit($rateLimitKey);

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$userId;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $full_name;
                $_SESSION['role'] = 'player';
                $_SESSION['_last_activity'] = time();
                stampSessionFingerprint();

                setFlash('success', 'Account created successfully. Welcome to Padol Pickleball Court!');
                redirect('player/dashboard.php');
} catch (Throwable $e) {
    $db->rollBack();
    error_log('Registration error: ' . $e->getMessage());
    $errors['general'] = 'Registration failed: ' . $e->getMessage();
}
        }
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/../includes/header.php';
?>
<?= breadcrumb([
    ['label' => 'Home', 'href' => APP_URL],
    ['label' => 'Create Account']
]) ?>
<?php
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Shared auth-page + auth-card styles (keep in sync with login.php) ── */
.auth-page {
    display: flex;
    align-items: flex-start;           /* top-align — form is tall on mobile */
    justify-content: center;
    min-height: calc(100dvh - var(--navbar-h, 60px));
    padding: clamp(16px, 4vw, 48px) clamp(12px, 4vw, 24px);
    padding-bottom: max(clamp(16px, 4vw, 48px), env(safe-area-inset-bottom, 16px));
    box-sizing: border-box;
}

.auth-card {
    width: 100%;
    max-width: 460px;
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

.auth-alert {
    border-radius: 10px;
    margin-bottom: 18px;
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.5;
}

.auth-card .form-group { margin-bottom: 14px; }

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

.auth-card input.error { border-color: var(--danger, #ef4444); }

.form-error {
    font-size: 12px;
    color: var(--danger, #ef4444);
    margin-top: 5px;
}

/* ── Optional label ─────────────────────────────────────────── */
.label-optional {
    color: var(--muted);
    font-weight: 400;
    font-size: 11px;
    margin-left: 4px;
}

/* ── Password toggle ────────────────────────────────────────── */
.pwd-field-wrap { position: relative; }
.pwd-field-wrap input { padding-right: 50px; }
.pwd-toggle-btn {
    position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: var(--muted);
    width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;
    font-size: 16px; border-radius: 8px; transition: color 0.2s;
    touch-action: manipulation; -webkit-tap-highlight-color: transparent;
    user-select: none; -webkit-user-select: none;
}
.pwd-toggle-btn:hover, .pwd-toggle-btn:focus-visible { color: var(--accent); outline: none; }

/* ── Password strength bar ──────────────────────────────────── */
.pwd-strength-wrap {
    background: var(--surface2);
    border-radius: 4px;
    height: 4px;
    margin-top: 8px;
    overflow: hidden;
}
#pwd-strength {
    height: 100%;
    width: 0;
    transition: width 0.3s, background 0.3s;
    border-radius: 4px;
}

/* ── Submit ─────────────────────────────────────────────────── */
.auth-card .btn-primary {
    width: 100%;
    padding: 13px;
    font-size: 15px;
    border-radius: 10px;
    margin-top: 6px;
}

/* ── Footer ─────────────────────────────────────────────────── */
.auth-footer {
    text-align: center;
    font-size: 13px;
    color: var(--muted);
    margin-top: 20px;
}
.auth-footer a { color: var(--accent); text-decoration: none; font-weight: 600; }
.auth-footer a:hover { text-decoration: underline; }

/* ── Mobile tweaks ──────────────────────────────────────────── */
@media (max-width: 480px) {
    .auth-card input[type="text"],
    .auth-card input[type="password"],
    .auth-card input[type="email"],
    .auth-card input[type="tel"] {
        font-size: 16px; /* prevents iOS auto-zoom */
    }
}
</style>

<div class="auth-page">
    <div class="auth-card">

        <h1>Create Account</h1>
        <p class="subtitle">Join the court — register to start playing.</p>

        <?php if (!empty($errors['general'])): ?>
            <div class="flash flash-error auth-alert">
                <?= clean($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate autocomplete="on">
            <?php echo csrfField(); ?>

            <div class="form-group">
                <label for="full_name">Full Name</label>
                  <input type="text" id="full_name" name="full_name"
                      value="<?= clean($values['full_name']) ?>"
                      placeholder="Juan dela Cruz"
                      autocomplete="name"
                      inputmode="text"
                      autofocus
                      data-validate-type="minLength" data-validate-min="2"
                      class="<?= isset($errors['full_name']) ? 'error' : '' ?>" required/>
                <?php if (isset($errors['full_name'])): ?>
                    <div class="form-error"><?= clean($errors['full_name']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                  <input type="text" id="username" name="username"
                      value="<?= clean($values['username']) ?>"
                       placeholder="juandelacruz"
                       autocomplete="username"
                       inputmode="text"
                       autocorrect="off"
                       autocapitalize="none"
                       spellcheck="false"
                      data-validate-type="username"
                      class="<?= isset($errors['username']) ? 'error' : '' ?>" required/>
                <?php if (isset($errors['username'])): ?>
                    <div class="form-error"><?= clean($errors['username']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                  <input type="tel" id="phone" name="phone"
                      value="<?= clean($values['phone']) ?>"
                       placeholder="09171234567"
                       autocomplete="tel"
                       inputmode="tel"
                      data-validate-type="phone"
                      class="<?= isset($errors['phone']) ? 'error' : '' ?>" required/>
                <?php if (isset($errors['phone'])): ?>
                    <div class="form-error"><?= clean($errors['phone']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                  <input type="email" id="email" name="email"
                      value="<?= clean($values['email']) ?>"
                       placeholder="juan@email.com"
                       autocomplete="email"
                       inputmode="email"
                      data-validate-type="email"
                      class="<?= isset($errors['email']) ? 'error' : '' ?>" required/>
                <div style="font-size:12px;color:var(--muted);margin-top:5px;">
                    We'll email you a 6-digit code to verify your account.
                </div>
                <?php if (isset($errors['email'])): ?>
                    <div class="form-error"><?= clean($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="pwd-field-wrap">
                          <input type="password" id="password" name="password"
                              placeholder="Min. 8 chars, 1 uppercase, 1 number"
                              autocomplete="new-password"
                              data-validate-type="password"
                              class="<?= isset($errors['password']) ? 'error' : '' ?>" required/>
                    <button type="button"
                            onclick="togglePwd('password','toggle-pwd1')"
                            id="toggle-pwd1"
                            class="pwd-toggle-btn"
                            aria-label="Toggle password visibility">👁</button>
                </div>
                <div class="pwd-strength-wrap"><div id="pwd-strength"></div></div>
                <?php if (isset($errors['password'])): ?>
                    <div class="form-error"><?= clean($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="pwd-field-wrap">
                          <input type="password" id="confirm_password" name="confirm_password"
                              placeholder="Re-enter password"
                              autocomplete="new-password"
                              data-validate-type="match" data-validate-match="password"
                              class="<?= isset($errors['confirm_password']) ? 'error' : '' ?>" required/>
                    <button type="button"
                            onclick="togglePwd('confirm_password','toggle-pwd2')"
                            id="toggle-pwd2"
                            class="pwd-toggle-btn"
                            aria-label="Toggle confirm password visibility">👁</button>
                </div>
                <?php if (isset($errors['confirm_password'])): ?>
                    <div class="form-error"><?= clean($errors['confirm_password']) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-primary" id="submit-btn">
                Create Account
            </button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="<?= APP_URL ?>/auth/login.php">Sign in here</a>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function togglePwd(inputId, btnId) {
    const input = document.getElementById(inputId);
    const btn   = document.getElementById(btnId);
    if (!input || !btn) return;
    input.type      = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
    btn.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
}

document.getElementById('password').addEventListener('input', function () {
    const val    = this.value;
    const bar    = document.getElementById('pwd-strength');
    const checks = [val.length >= 8, /[A-Z]/.test(val), /[0-9]/.test(val), /[^a-zA-Z0-9]/.test(val)];
    const score  = checks.filter(Boolean).length;
    const colors = ['', 'var(--danger)', 'var(--warn)', 'var(--accent2)', 'var(--success)'];
    bar.style.width      = (score * 25) + '%';
    bar.style.background = colors[score] || '';
});

// Prevent double-submit on slow connections
(function () {
    const form = document.querySelector('form');
    const btn  = document.getElementById('submit-btn');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        setTimeout(function () {
            btn.disabled    = true;
            btn.textContent = 'Creating account…';
        }, 100);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>