<?php
// ============================================================
//  FILE: admin/create_player.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$errors  = [];
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CSRF check (verifyCsrf() takes NO args — it reads $_POST itself)
    verifyCsrf();

    $username    = trim($_POST['username'] ?? '');
    $fullName    = trim($_POST['full_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $password    = $_POST['password'] ?? '';
    $role        = ($_POST['role'] ?? 'player') === 'admin' ? 'admin' : 'player';
    $initCredits = max(0, (int)($_POST['initial_credits'] ?? 0));

    // ── Validation ────────────────────────────────────────────
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

    // Phone: if provided, validate format
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone))
        $errors[] = 'Phone number format is invalid.';

    // ── Duplicate check ───────────────────────────────────────
    if (empty($errors)) {
        $exists = $db->prepare("SELECT id FROM falcon.users WHERE username = ? OR email = ?");
        $exists->execute([$username, $email]);
        if ($exists->fetch())
            $errors[] = 'Username or email already exists.';

        // Only check phone uniqueness if a phone was actually entered
        if ($phone !== '') {
            $phoneCheck = $db->prepare("SELECT id FROM falcon.users WHERE phone = ?");
            $phoneCheck->execute([$phone]);
            if ($phoneCheck->fetch())
                $errors[] = 'Phone number already in use.';
        }
    }

    // ── Insert ────────────────────────────────────────────────
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Use NULL for phone when blank (avoids unique-constraint clash on '')
            $phoneVal = $phone !== '' ? $phone : null;

            // Admin-created accounts skip the self-service email code step
            // (the admin already verified the player in person), but we
            // deliberately leave is_verified untouched here — that flag is
            // the separate "Verify Player" admin approval workflow and
            // should keep its original default/behavior.
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

            // ── Wallet (balance = 0 first, then update if needed) ──
            $db->prepare(
                "INSERT INTO falcon.wallets (user_id, balance, updated_at) VALUES (?, 0, NOW())"
            )->execute([$userId]);

            // ── Initial credits ────────────────────────────────
            if ($initCredits > 0) {
                $db->prepare(
                    "UPDATE falcon.wallets SET balance = ?, updated_at = NOW() WHERE user_id = ?"
                )->execute([$initCredits, $userId]);

                // transactions table requires balance_before, balance_after
                $db->prepare("
                    INSERT INTO falcon.transactions
                        (user_id, type, amount, balance_before, balance_after,
                         note, status, processed_by, created_at)
                    VALUES (?, 'topup', ?, 0, ?, 'Initial credit by admin', 'approved', ?, NOW())
                ")->execute([$userId, $initCredits, $initCredits, $_SESSION['user_id']]);
            }

            // ── Permanent player pass ──────────────────────────
            // Every pass needs a unique token because qr_token is NOT NULL.
            $db->prepare("
                INSERT INTO falcon.player_passes
                    (user_id, qr_token, expires_at, is_active, created_at)
                VALUES
                    (?, ?, '2099-12-31 23:59:59+00', TRUE, NOW())
            ")->execute([$userId, bin2hex(random_bytes(24))]);

            $db->commit();

            $created = compact('username', 'fullName', 'email', 'phone', 'role', 'initCredits', 'password');
            $created['id'] = $userId;

        } catch (PDOException $e) {
            $db->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Create Player Account';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.create-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 24px;
    align-items: start;
    max-width: 900px;
}
.cred-box {
    background: var(--surface2);
    border: 2px solid var(--accent);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 16px;
    font-family: 'JetBrains Mono', monospace;
}
.cred-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}
.cred-row:last-child { border-bottom: none; }
.cred-key { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.cred-val { font-size: 14px; font-weight: 700; color: var(--accent); word-break: break-all; text-align: right; }
.role-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
.role-card {
    border: 2px solid var(--border);
    border-radius: 10px;
    padding: 14px;
    cursor: pointer;
    transition: all 0.15s;
    text-align: center;
    touch-action: manipulation;
}
.role-card:hover       { border-color: var(--accent); background: rgba(0,229,160,0.05); }
.role-card input       { display: none; }
.role-card.selected    { border-color: var(--accent); background: rgba(0,229,160,0.08); }
.role-card .role-icon  { font-size: 28px; margin-bottom: 6px; }
.role-card .role-name  { font-size: 13px; font-weight: 700; }
.role-card .role-desc  { font-size: 11px; color: var(--muted); margin-top: 2px; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

@media (max-width: 800px) { .create-layout { grid-template-columns: 1fr; } }
@media (max-width: 500px) {
    .form-row-2 { grid-template-columns: 1fr; gap: 0; }
    .role-grid  { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Create Account</h1>
        <p>Add a new player or admin account to the system.</p>
    </div>
    <div class="btn-group">
        <a href="<?= APP_URL ?>/admin/players.php"   class="btn-outline btn-sm">👥 All Players</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
        <div class="flash flash-error">
            <span><?= clean($e) ?></span>
            <button onclick="this.parentElement.remove()">✕</button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($created): ?>
    <!-- ── Success panel ── -->
    <div class="card" style="border-color:var(--success);max-width:600px;margin-bottom:24px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="font-size:36px;">✅</div>
            <div>
                <div style="font-family:'Bebas Neue',sans-serif;font-size:24px;color:var(--success);">Account Created!</div>
                <div style="font-size:13px;color:var(--muted);">Share these login credentials with the player.</div>
            </div>
        </div>

        <div class="cred-box">
            <div class="cred-row"><span class="cred-key">Full Name</span> <span class="cred-val"><?= clean($created['fullName']) ?></span></div>
            <div class="cred-row"><span class="cred-key">Username</span>  <span class="cred-val"><?= clean($created['username']) ?></span></div>
            <div class="cred-row"><span class="cred-key">Password</span>  <span class="cred-val" style="color:var(--warn);"><?= clean($created['password']) ?></span></div>
            <div class="cred-row"><span class="cred-key">Email</span>     <span class="cred-val"><?= clean($created['email']) ?></span></div>
            <?php if (!empty($created['phone'])): ?>
            <div class="cred-row"><span class="cred-key">Phone</span>     <span class="cred-val"><?= clean($created['phone']) ?></span></div>
            <?php endif; ?>
            <div class="cred-row"><span class="cred-key">Role</span>      <span class="cred-val"><?= ucfirst($created['role']) ?></span></div>
            <?php if ($created['initCredits'] > 0): ?>
            <div class="cred-row"><span class="cred-key">Starting Credits</span> <span class="cred-val"><?= $created['initCredits'] ?></span></div>
            <?php endif; ?>
        </div>

        <div style="background:rgba(245,158,11,0.1);border:1px solid var(--warn);border-radius:8px;padding:12px;font-size:13px;color:#fcd34d;margin-bottom:16px;">
            ⚠️ The player <strong>must change their password</strong> on first login.
        </div>

        <div class="btn-group">
            <a href="<?= APP_URL ?>/admin/create_player.php" class="btn-primary">➕ Create Another</a>
            <a href="<?= APP_URL ?>/admin/players.php"       class="btn-outline">👥 View All Players</a>
        </div>
    </div>

<?php else: ?>

<div class="create-layout">
    <form method="POST" id="create-form">
        <?= csrfField() /* uses the correct csrfField() helper from security.php */ ?>

        <!-- Role selection -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-title" style="margin-bottom:14px;">👤 Account Type</div>
            <div class="role-grid" id="role-grid">
                <label class="role-card selected" id="card-player" onclick="selectRole('player')">
                    <input type="radio" name="role" value="player" checked/>
                    <div class="role-icon">🎾</div>
                    <div class="role-name">Player</div>
                    <div class="role-desc">Can scan in, join queue, manage credits</div>
                </label>
                <label class="role-card" id="card-admin" onclick="selectRole('admin')">
                    <input type="radio" name="role" value="admin"/>
                    <div class="role-icon">🛡️</div>
                    <div class="role-name">Admin</div>
                    <div class="role-desc">Full admin access + player features</div>
                </label>
            </div>
        </div>

        <!-- Personal Info -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-title" style="margin-bottom:16px;">📋 Personal Info</div>

            <div class="form-row-2">
                <div class="form-group" style="margin:0 0 16px;">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required placeholder="Juan Dela Cruz"
                           value="<?= clean($_POST['full_name'] ?? '') ?>"/>
                </div>
                <div class="form-group" style="margin:0 0 16px;">
                    <label>Username *</label>
                    <input type="text" name="username" required placeholder="juandc"
                           pattern="[a-zA-Z0-9_]+"
                           value="<?= clean($_POST['username'] ?? '') ?>"/>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group" style="margin:0 0 16px;">
                    <label>Email Address *</label>
                    <input type="email" name="email" required placeholder="juan@email.com"
                           value="<?= clean($_POST['email'] ?? '') ?>"/>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Phone <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
                    <input type="tel" name="phone" placeholder="09xxxxxxxxx"
                           value="<?= clean($_POST['phone'] ?? '') ?>"/>
                </div>
            </div>
        </div>

        <!-- Password -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-title" style="margin-bottom:4px;">🔑 Temporary Password</div>
            <div class="card-subtitle" style="margin-bottom:16px;">Player must change this on first login</div>

            <div class="form-group">
                <label>Password *</label>
                <div style="position:relative;">
                    <input type="password" name="password" id="pw-input" required
                           placeholder="Min 6 characters"
                           style="padding-right:48px;"/>
                    <button type="button" onclick="togglePw()"
                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:var(--muted);padding:4px;"
                            id="pw-toggle">👁</button>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn-outline btn-sm" onclick="genPassword()">🎲 Generate</button>
                <span id="pw-strength" style="font-size:12px;color:var(--muted);"></span>
            </div>
        </div>

        <!-- Initial Credits -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-title" style="margin-bottom:4px;">💳 Starting Credits</div>
            <div class="card-subtitle" style="margin-bottom:16px;">Optional — leave 0 to start with empty wallet</div>
            <div class="form-group" style="margin:0;">
                <label>Initial Credit Balance</label>
                <input type="number" name="initial_credits"
                       value="<?= (int)($_POST['initial_credits'] ?? 0) ?>"
                       min="0" step="1" placeholder="0"/>
            </div>
        </div>

        <button type="submit" class="btn-primary btn-block" style="min-height:52px;font-size:16px;">
            ✅ Create Account
        </button>
    </form>

    <!-- Sidebar info -->
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="card">
            <div class="card-title" style="margin-bottom:10px;">ℹ️ What Happens Next</div>
            <div style="font-size:13px;color:var(--muted);line-height:1.8;">
                <div>✅ Account created instantly</div>
                <div>✅ Wallet created with your balance</div>
                <div>✅ Permanent player pass issued</div>
                <div>⚠️ Player must change password on first login</div>
            </div>
        </div>
        <div class="card">
            <div class="card-title" style="margin-bottom:10px;">🎾 Player Pass</div>
            <div style="font-size:13px;color:var(--muted);line-height:1.7;">
                Every new account automatically gets a
                <strong style="color:var(--accent);">permanent pass</strong>
                that never expires, allowing them to join the queue.
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script nonce="<?= getCspNonce() ?>">
function selectRole(role) {
    ['player', 'admin'].forEach(r => {
        document.getElementById('card-' + r)?.classList.toggle('selected', r === role);
    });
}
function togglePw() {
    const inp = document.getElementById('pw-input');
    const btn = document.getElementById('pw-toggle');
    if (!inp) return;
    inp.type = inp.type === 'password' ? 'text' : 'password';
    if (btn) btn.textContent = inp.type === 'password' ? '👁' : '🙈';
}
function genPassword() {
    const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    let pw = '';
    for (let i = 0; i < 10; i++) pw += chars[Math.floor(Math.random() * chars.length)];
    const inp = document.getElementById('pw-input');
    if (inp) { inp.value = pw; inp.type = 'text'; updateStrength(pw); }
    const btn = document.getElementById('pw-toggle');
    if (btn) btn.textContent = '🙈';
}
function updateStrength(pw) {
    const el = document.getElementById('pw-strength');
    if (!el) return;
    const len = pw.length;
    if      (len < 6)  { el.textContent = '⚡ Too short'; el.style.color = 'var(--danger)'; }
    else if (len < 8)  { el.textContent = '🟡 Weak';      el.style.color = 'var(--warn)'; }
    else if (len < 12) { el.textContent = '🟢 Good';      el.style.color = 'var(--success)'; }
    else               { el.textContent = '💪 Strong';    el.style.color = 'var(--accent)'; }
}
document.getElementById('pw-input')?.addEventListener('input', e => updateStrength(e.target.value));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>