<?php
// ============================================================
//  FILE: player/profile.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM falcon.users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$errors  = [];
$success = false;

$formValues = [
    'full_name'         => $user['full_name']         ?? '',
    'email'             => $user['email']              ?? '',
    'phone'             => $user['phone']              ?? '',
    'display_name'      => $user['display_name']       ?? '',
    'show_display_name' => $user['show_display_name']  ?? true,
];
$currentAvatar = $user['avatar_path'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $fullName        = trim($_POST['full_name']    ?? '');
    $email           = trim($_POST['email']        ?? '');
    $phone           = trim($_POST['phone']        ?? '');
    $displayName     = trim($_POST['display_name'] ?? '');
    $showDisplayName = isset($_POST['show_display_name']);

    $formValues = compact('fullName','email','phone','displayName','showDisplayName');
    $formValues = [
        'full_name'         => $fullName,
        'email'             => $email,
        'phone'             => $phone,
        'display_name'      => $displayName,
        'show_display_name' => $showDisplayName,
    ];

    if (strlen($fullName) < 2)              $errors['full_name']    = 'Full name must be at least 2 characters.';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email address.';
    if (!preg_match('/^(09|\+639)\d{9}$/', $phone)) $errors['phone'] = 'Enter a valid PH mobile number (e.g. 09171234567).';
    if (!empty($displayName) && strlen($displayName) > 80) $errors['display_name'] = 'Display name must be 80 characters or less.';

    if (empty($errors['phone']) && !empty($phone)) {
        $chk = $db->prepare("SELECT id FROM falcon.users WHERE phone = ? AND id != ?");
        $chk->execute([$phone, $uid]);
        if ($chk->fetch()) $errors['phone'] = 'Phone number already used by another account.';
    }
    if (empty($errors['email']) && !empty($email)) {
        $chk2 = $db->prepare("SELECT id FROM falcon.users WHERE email = ? AND id != ?");
        $chk2->execute([$email, $uid]);
        if ($chk2->fetch()) $errors['email'] = 'Email already used by another account.';
    }

    $avatarPath = $currentAvatar;
    if (!empty($_FILES['avatar']['name'])) {
        if (!is_dir(UPLOAD_AVATARS)) mkdir(UPLOAD_AVATARS, 0755, true);
        // Server-side MIME sniff + extension lock + double-extension check +
        // GD re-encode (strips EXIF/metadata/polyglot payloads) — never trust
        // the browser-supplied Content-Type or the original filename alone.
        $filename = moveUploadedImageSafe($_FILES['avatar'], UPLOAD_AVATARS, 'avatar_' . $uid);
        if ($filename !== null) {
            if ($currentAvatar && file_exists(UPLOAD_AVATARS . $currentAvatar)) unlink(UPLOAD_AVATARS . $currentAvatar);
            $avatarPath = $filename;
        } else {
            $errors['avatar'] = 'Upload failed. Please use a JPG, PNG, WebP, or GIF under ' . MAX_UPLOAD_MB . 'MB.';
        }
    }

    if (empty($errors)) {
        $db->prepare("
            UPDATE falcon.users
            SET full_name=?, email=?, phone=?, avatar_path=?,
                display_name=?, show_display_name=?, updated_at=NOW()
            WHERE id=?
        ")->execute([
            $fullName,
            !empty($email)       ? $email       : null,
            $phone,
            $avatarPath,
            !empty($displayName) ? $displayName : null,
            $showDisplayName     ? 'TRUE'        : 'FALSE',
            $uid,
        ]);
        $_SESSION['full_name']       = $fullName;
        $_SESSION['avatar']          = $avatarPath;
        $_SESSION['force_pw_change'] = false;
        $stmt->execute([$uid]);
        $user          = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentAvatar = $user['avatar_path'] ?? null;
        $formValues    = [
            'full_name'         => $user['full_name']         ?? '',
            'email'             => $user['email']             ?? '',
            'phone'             => $user['phone']             ?? '',
            'display_name'      => $user['display_name']      ?? '',
            'show_display_name' => $user['show_display_name'] ?? true,
        ];
        $success = true;
    }
}

$showFirstLoginAlert = !empty($_SESSION['force_pw_change']);
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($showFirstLoginAlert): ?>
<script nonce="<?= getCspNonce() ?>">
document.addEventListener('DOMContentLoaded', function () {
    if (window.confirm('Your account was created by an admin. Please change your password now.')) {
        window.location.href = '<?= APP_URL ?>/player/change_password.php';
    }
});
</script>
<?php endif; ?>

<style nonce="<?= getCspNonce() ?>">
/* ── Profile page ─────────────────────────────────────────── */
.profile-wrap {
    max-width: 560px;
    margin: 0 auto;
}

.profile-avatar-wrap {
    text-align: center;
    margin-bottom: 24px;
}
.profile-avatar-img {
    width: 88px; height: 88px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--accent);
    display: block;
    margin: 0 auto 10px;
    box-shadow: 0 0 0 5px rgba(0,229,160,0.12);
}
.profile-avatar-placeholder {
    width: 88px; height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, #059669 0%, #00e5a0 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: 36px; color: #022c22; font-weight: 800;
    margin: 0 auto 10px;
    box-shadow: 0 0 0 5px rgba(0,229,160,0.12);
}

/* Kiosk preview */
.kiosk-preview {
    background: #050d12;
    border: 1px solid rgba(0,229,160,0.2);
    border-radius: 12px;
    padding: 14px 16px;
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.kiosk-preview-badge {
    background: rgba(0,229,160,0.12);
    border: 1px solid rgba(0,229,160,0.3);
    border-radius: 10px;
    padding: 10px 14px;
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(16px, 4vw, 22px);
    color: var(--accent);
    letter-spacing: 1px;
    min-width: 100px;
    max-width: 100%;
    word-break: break-word;
    text-align: center;
}

/* Toggle switch */
.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    background: var(--surface2);
    border-radius: 10px;
    margin-top: 6px;
    gap: 12px;
    flex-wrap: wrap;
}
.toggle-row-text { flex: 1; min-width: 160px; }
.toggle-pill { position:relative; display:inline-block; width:52px; height:28px; flex-shrink:0; }
.toggle-pill input { opacity:0; width:0; height:0; }
.toggle-track { position:absolute; cursor:pointer; inset:0; background:var(--border); border-radius:28px; transition:.3s; }
.toggle-thumb { position:absolute; height:20px; width:20px; left:4px; bottom:4px; background:#fff; border-radius:50%; transition:.3s; }

/* File input */
.file-input-styled {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px;
    width: 100%;
    color: var(--text);
    font-size: 14px;
    box-sizing: border-box;
}

/* Page header actions */
.profile-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
</style>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:10px;">
        <?= pickleballLogo(32) ?>
        <div>
            <h1 style="margin:0;">My Profile</h1>
            <p style="margin:0;">Update your personal information</p>
        </div>
    </div>
    <div class="profile-header-actions">
        <a href="<?= APP_URL ?>/player/change_password.php" class="btn-outline btn-sm">🔑 Password</a>
        <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<div class="profile-wrap">
    <div class="card">

        <?php if ($success): ?>
            <div class="flash flash-success" style="border-radius:8px;margin-bottom:20px;">
                ✅ Profile updated successfully!
            </div>
        <?php endif; ?>

        <!-- Avatar -->
        <div class="profile-avatar-wrap">
            <?php if ($currentAvatar && file_exists(UPLOAD_AVATARS . $currentAvatar)): ?>
                <img src="<?= APP_URL ?>/uploads/avatars/<?= urlencode($currentAvatar) ?>"
                     alt="Profile photo" class="profile-avatar-img"/>
            <?php else: ?>
                <div class="profile-avatar-placeholder">
                    <?= strtoupper(substr($user['username'] ?? '?', 0, 1)) ?>
                </div>
            <?php endif; ?>
            <div style="font-size:13px;color:var(--muted);">@<?= clean($user['username'] ?? '') ?></div>
            <?php if (!empty($user['role']) && $user['role'] !== 'player'): ?>
                <span class="badge badge-warn" style="margin-top:6px;display:inline-block;">
                    <?= ucfirst(clean($user['role'])) ?>
                </span>
            <?php endif; ?>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>

            <!-- Avatar upload -->
            <div class="form-group">
                <label>Profile Photo <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
                <input type="file" name="avatar" accept="image/*" class="file-input-styled"/>
                <?php if (isset($errors['avatar'])): ?>
                    <div class="form-error"><?= clean($errors['avatar']) ?></div>
                <?php endif; ?>
                <div style="font-size:12px;color:var(--muted);margin-top:4px;">JPG, PNG or WebP · Max <?= MAX_UPLOAD_MB ?>MB</div>
            </div>

            <!-- Full Name -->
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name"
                       value="<?= clean($formValues['full_name']) ?>"
                       placeholder="Juan dela Cruz"
                       class="<?= isset($errors['full_name']) ? 'error' : '' ?>"
                       style="font-size:16px;" required/>
                <?php if (isset($errors['full_name'])): ?>
                    <div class="form-error"><?= clean($errors['full_name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Phone -->
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone"
                       value="<?= clean($formValues['phone']) ?>"
                       placeholder="09171234567"
                       class="<?= isset($errors['phone']) ? 'error' : '' ?>"
                       style="font-size:16px;" required/>
                <?php if (isset($errors['phone'])): ?>
                    <div class="form-error"><?= clean($errors['phone']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Email -->
            <div class="form-group">
                <label>Email <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
                <input type="email" name="email"
                       value="<?= clean($formValues['email']) ?>"
                       placeholder="juan@email.com"
                       class="<?= isset($errors['email']) ? 'error' : '' ?>"
                       style="font-size:16px;"/>
                <?php if (isset($errors['email'])): ?>
                    <div class="form-error"><?= clean($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Username (read-only) -->
            <div class="form-group">
                <label>Username <span style="color:var(--muted);font-weight:400;">(cannot be changed)</span></label>
                <input type="text" value="<?= clean($user['username'] ?? '') ?>" disabled
                       style="opacity:0.45;cursor:not-allowed;"/>
            </div>

            <!-- Kiosk Display Name -->
            <div style="border:1px solid rgba(0,229,160,0.2);border-radius:12px;padding:16px;margin:8px 0 16px;">
                <div style="font-size:12px;font-weight:700;color:var(--accent);
                             text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">
                    📺 Court Screen Display
                </div>
                <div style="font-size:13px;color:var(--muted);margin-bottom:14px;">
                    How your name appears on the big TV screen at the court when you're in the queue.
                </div>

                <div class="form-group" style="margin-bottom:10px;">
                    <label>Display Name <span style="color:var(--muted);font-weight:400;">(optional — leave blank to use full name)</span></label>
                    <input type="text" name="display_name" id="inp-display-name"
                           value="<?= clean($formValues['display_name']) ?>"
                           placeholder="e.g. Juan D. or JuanPB"
                           maxlength="80"
                           oninput="updateKioskPreview()"
                           class="<?= isset($errors['display_name']) ? 'error' : '' ?>"
                           style="font-size:16px;"/>
                    <?php if (isset($errors['display_name'])): ?>
                        <div class="form-error"><?= clean($errors['display_name']) ?></div>
                    <?php endif; ?>
                    <div style="font-size:11px;color:var(--muted);margin-top:3px;">Nickname, first name, or anything public. Max 80 chars.</div>
                </div>

                <!-- Toggle -->
                <div class="toggle-row">
                    <div class="toggle-row-text">
                        <div style="font-weight:600;font-size:14px;">Show my name on the court screen</div>
                        <div style="font-size:12px;color:var(--muted);">Turn off to appear as "Player #"</div>
                    </div>
                    <label class="toggle-pill">
                        <input type="checkbox" name="show_display_name" id="show-dn-cb"
                               <?= $formValues['show_display_name'] ? 'checked' : '' ?>
                               onchange="updateKioskPreview()">
                        <span class="toggle-track" id="dn-toggle-track">
                            <span class="toggle-thumb" id="dn-toggle-thumb"></span>
                        </span>
                    </label>
                </div>

                <!-- Live preview -->
                <div style="margin-top:14px;">
                    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;
                                letter-spacing:.5px;margin-bottom:8px;font-weight:700;">
                        Preview — how you'll appear on screen:
                    </div>
                    <div class="kiosk-preview">
                        <div style="font-size:24px;flex-shrink:0;">📺</div>
                        <div style="flex:1;min-width:0;">
                            <div class="kiosk-preview-badge" id="kiosk-preview-name">
                                <?php
                                if ($formValues['show_display_name'] || ($user['show_display_name'] ?? true)) {
                                    echo !empty($formValues['display_name'])
                                        ? clean($formValues['display_name'])
                                        : clean($user['full_name'] ?? 'Your Name');
                                } else { echo 'Player #'; }
                                ?>
                            </div>
                            <div style="font-size:11px;color:var(--muted);margin-top:4px;" id="kiosk-preview-sub">
                                Shown on the court TV screen
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary"
                    style="width:100%;padding:14px;font-size:15px;
                           background:linear-gradient(135deg,#059669,#00e5a0);
                           color:#022c22;font-weight:700;letter-spacing:.5px;
                           touch-action:manipulation;">
                💾 Save Changes
            </button>
        </form>

        <div style="text-align:center;margin-top:18px;">
            <a href="<?= APP_URL ?>/player/change_password.php"
               style="font-size:13px;color:var(--accent);opacity:0.75;text-decoration:none;">
                🔑 Change Password
            </a>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
const dnCb    = document.getElementById('show-dn-cb');
const dnTrack = document.getElementById('dn-toggle-track');
const dnThumb = document.getElementById('dn-toggle-thumb');

function syncToggle() {
    dnTrack.style.background = dnCb.checked ? 'var(--accent)' : 'var(--border)';
    dnThumb.style.transform  = dnCb.checked ? 'translateX(24px)' : 'translateX(0)';
}
syncToggle();
dnCb.addEventListener('change', syncToggle);

const fullNameInput    = document.querySelector('input[name="full_name"]');
const displayNameInput = document.getElementById('inp-display-name');
const previewName      = document.getElementById('kiosk-preview-name');
const previewSub       = document.getElementById('kiosk-preview-sub');

function updateKioskPreview() {
    const show       = dnCb.checked;
    const displayVal = displayNameInput.value.trim();
    const fullVal    = fullNameInput.value.trim();
    if (!show) {
        previewName.textContent = 'Player #';
        previewName.style.color = 'var(--muted)';
        previewSub.textContent  = 'Your name will be hidden on screen';
    } else if (displayVal) {
        previewName.textContent = displayVal;
        previewName.style.color = 'var(--accent)';
        previewSub.textContent  = 'Using your custom display name';
    } else if (fullVal) {
        previewName.textContent = fullVal;
        previewName.style.color = 'var(--accent)';
        previewSub.textContent  = 'Using your full name';
    } else {
        previewName.textContent = 'Your Name';
        previewName.style.color = 'var(--muted)';
        previewSub.textContent  = 'Enter your full name above';
    }
}
fullNameInput.addEventListener('input', updateKioskPreview);
updateKioskPreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>