<?php
// ============================================================
//  FILE: admin/payment_settings.php
//  Manage online payment methods (GCash, Maya, Bank, etc.)
//  Admin can add / edit / toggle / delete payment channels.
//  QR code images are uploaded and stored in uploads/payment_qr/
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];

// ── Upload directory ─────────────────────────────────────────
define('UPLOAD_PAYMENT_QR', APP_ROOT . '/uploads/payment_qr/');
if (!is_dir(UPLOAD_PAYMENT_QR)) mkdir(UPLOAD_PAYMENT_QR, 0755, true);

// ── QR image upload helper ────────────────────────────────────
// Delegates to moveUploadedImageSafe() (includes/security_helpers.php),
// which locks the extension to the sniffed MIME type, rejects
// double-extension tricks, and re-encodes through GD to strip any
// embedded payload — the previous version trusted the uploader's own
// filename for the saved extension.
function uploadQR(array $file): ?string {
    return moveUploadedImageSafe($file, UPLOAD_PAYMENT_QR, 'qr');
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Save / update a payment method ────────────────────────
    if (in_array($action, ['create','update'])) {
        $id           = filter_input(INPUT_POST, 'setting_id',    FILTER_VALIDATE_INT);
        $methodName   = trim(substr($_POST['method_name']   ?? '', 0, 100));
        $accountName  = trim(substr($_POST['account_name']  ?? '', 0, 150));
        $accountNum   = trim(substr($_POST['account_number']?? '', 0, 100));
        $instructions = trim(substr($_POST['instructions']  ?? '', 0, 1000));
        $isActive     = isset($_POST['is_active']) ? true : false;
        $sortOrder    = max(0, (int)($_POST['sort_order'] ?? 0));

        if (empty($methodName) || empty($accountName) || empty($accountNum)) {
            setFlash('error', 'Method name, account name, and account number are required.');
            redirect('admin/payment_settings.php');
        }

        // Handle QR upload
        $qrFilename = null;
        if (!empty($_FILES['qr_image']['name'])) {
            $qrFilename = uploadQR($_FILES['qr_image']);
            if (!$qrFilename) {
                setFlash('error', 'Invalid QR image. JPG/PNG/WEBP only, max ' . MAX_UPLOAD_MB . 'MB.');
                redirect('admin/payment_settings.php');
            }
        }

        if ($action === 'create') {
            $db->prepare("
                INSERT INTO falcon.payment_settings
                    (method_name, account_name, account_number, qr_image, instructions, is_active, sort_order, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$methodName, $accountName, $accountNum, $qrFilename, $instructions ?: null, $isActive, $sortOrder]);
            setFlash('success', '✅ Payment method added.');
        } else {
            // Update — only replace QR if a new file was uploaded
            if ($qrFilename) {
                // Delete old QR
                $oldQr = $db->prepare("SELECT qr_image FROM falcon.payment_settings WHERE id = ?");
                $oldQr->execute([$id]);
                $oldFile = $oldQr->fetchColumn();
                if ($oldFile && file_exists(UPLOAD_PAYMENT_QR . $oldFile)) {
                    @unlink(UPLOAD_PAYMENT_QR . $oldFile);
                }
                $db->prepare("
                    UPDATE falcon.payment_settings
                    SET method_name=?, account_name=?, account_number=?, qr_image=?,
                        instructions=?, is_active=?, sort_order=?, updated_at=NOW()
                    WHERE id=?
                ")->execute([$methodName, $accountName, $accountNum, $qrFilename,
                             $instructions ?: null, $isActive, $sortOrder, $id]);
            } else {
                $db->prepare("
                    UPDATE falcon.payment_settings
                    SET method_name=?, account_name=?, account_number=?,
                        instructions=?, is_active=?, sort_order=?, updated_at=NOW()
                    WHERE id=?
                ")->execute([$methodName, $accountName, $accountNum,
                             $instructions ?: null, $isActive, $sortOrder, $id]);
            }
            setFlash('success', '✅ Payment method updated.');
        }
        redirect('admin/payment_settings.php');
    }

    // ── Toggle active ─────────────────────────────────────────
    if ($action === 'toggle') {
        $id = filter_input(INPUT_POST, 'setting_id', FILTER_VALIDATE_INT);
        if ($id) {
            $db->prepare("UPDATE falcon.payment_settings SET is_active = NOT is_active, updated_at=NOW() WHERE id=?")->execute([$id]);
            setFlash('success', 'Payment method toggled.');
        }
        redirect('admin/payment_settings.php');
    }

    // ── Delete ────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'setting_id', FILTER_VALIDATE_INT);
        if ($id) {
            $oldQr = $db->prepare("SELECT qr_image FROM falcon.payment_settings WHERE id=?");
            $oldQr->execute([$id]);
            $oldFile = $oldQr->fetchColumn();
            if ($oldFile && file_exists(UPLOAD_PAYMENT_QR . $oldFile)) {
                @unlink(UPLOAD_PAYMENT_QR . $oldFile);
            }
            $db->prepare("DELETE FROM falcon.payment_settings WHERE id=?")->execute([$id]);
            setFlash('success', '🗑 Payment method deleted.');
        }
        redirect('admin/payment_settings.php');
    }

    // ── Remove QR image only ──────────────────────────────────
    if ($action === 'remove_qr') {
        $id = filter_input(INPUT_POST, 'setting_id', FILTER_VALIDATE_INT);
        if ($id) {
            $oldQr = $db->prepare("SELECT qr_image FROM falcon.payment_settings WHERE id=?");
            $oldQr->execute([$id]);
            $oldFile = $oldQr->fetchColumn();
            if ($oldFile && file_exists(UPLOAD_PAYMENT_QR . $oldFile)) {
                @unlink(UPLOAD_PAYMENT_QR . $oldFile);
            }
            $db->prepare("UPDATE falcon.payment_settings SET qr_image=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
            setFlash('success', 'QR image removed.');
        }
        redirect('admin/payment_settings.php');
    }
}

// ── Fetch all payment methods ─────────────────────────────────
$methods = $db->query("SELECT * FROM falcon.payment_settings ORDER BY sort_order ASC, id ASC")->fetchAll();

// ── Edit mode ─────────────────────────────────────────────────
$editId   = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editRow  = null;
if ($editId) {
    foreach ($methods as $m) {
        if ($m['id'] === $editId) { $editRow = $m; break; }
    }
}

$pageTitle = 'Payment Settings';
require_once __DIR__ . '/../includes/header.php';
$flash = getFlash();
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Payment Settings Page ───────────────────────────── */
.ps-layout {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 960px) { .ps-layout { grid-template-columns: 1fr; } }

/* Method cards */
.method-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 0;
    overflow: hidden;
    transition: border-color 0.2s, box-shadow 0.2s;
    margin-bottom: 14px;
}
.method-card:last-child { margin-bottom: 0; }
.method-card.active-card {
    border-color: rgba(0,229,160,0.35);
    box-shadow: 0 0 0 1px rgba(0,229,160,0.12);
}
.method-card.inactive-card { opacity: 0.55; }

.method-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px 14px;
    gap: 12px;
    flex-wrap: wrap;
}
.method-badge-name {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 20px;
    letter-spacing: 0.06em;
    color: var(--text);
}
.method-badge-icon {
    width: 34px;
    height: 34px;
    background: rgba(0,229,160,0.12);
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.method-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    align-items: center;
}

.method-body {
    padding: 0 18px 16px;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 14px;
    align-items: start;
}
@media (max-width: 520px) { .method-body { grid-template-columns: 1fr; } }

.method-info-row {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    font-size: 13px;
    margin-bottom: 7px;
    flex-wrap: wrap;
}
.method-info-row:last-child { margin-bottom: 0; }
.info-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted);
    min-width: 80px;
    flex-shrink: 0;
    margin-top: 2px;
}
.info-value {
    color: var(--text);
    font-weight: 500;
    word-break: break-word;
    flex: 1;
}
.info-value.mono {
    font-family: monospace;
    font-size: 14px;
    color: var(--accent);
    letter-spacing: 0.04em;
}

/* QR preview */
.qr-preview-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.qr-preview-img {
    width: 110px;
    height: 110px;
    object-fit: contain;
    border-radius: 12px;
    border: 2px solid var(--border);
    background: var(--surface2);
    cursor: zoom-in;
    transition: transform 0.15s, border-color 0.15s;
}
.qr-preview-img:hover { transform: scale(1.05); border-color: var(--accent); }
.qr-placeholder {
    width: 110px;
    height: 110px;
    border-radius: 12px;
    border: 2px dashed var(--border);
    background: var(--surface2);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    color: var(--muted);
    font-size: 11px;
    text-align: center;
}

/* Active status pill */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 4px 10px;
    border-radius: 20px;
}
.status-pill.on  { background: rgba(0,229,160,0.12); color: var(--accent); }
.status-pill.off { background: rgba(239,68,68,0.10); color: var(--danger); }
.status-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
    animation: pulse 2s infinite;
}
.status-pill.off .status-dot { animation: none; }

@keyframes pulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.4; transform:scale(1.4); }
}

/* Form card */
.form-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 24px 20px;
    position: sticky;
    top: 80px;
}
.form-card.editing {
    border-color: rgba(0,229,160,0.35);
    box-shadow: 0 0 0 1px rgba(0,229,160,0.1);
}

.form-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 22px;
    letter-spacing: 0.05em;
    margin-bottom: 4px;
}
.form-sub { font-size: 13px; color: var(--muted); margin-bottom: 18px; }

.form-group { margin-bottom: 14px; }
.form-group label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-bottom: 6px;
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    background: var(--surface2);
    border: 1.5px solid var(--border);
    border-radius: 9px;
    padding: 10px 13px;
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.15s;
    -webkit-appearance: none;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: var(--accent);
    outline: none;
    box-shadow: 0 0 0 2px rgba(0,229,160,0.14);
}

/* Toggle checkbox */
.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 13px;
    background: var(--surface2);
    border: 1.5px solid var(--border);
    border-radius: 9px;
    cursor: pointer;
    margin-bottom: 14px;
    transition: border-color 0.15s;
}
.toggle-row:hover { border-color: var(--accent); }
.toggle-label { font-size: 14px; font-weight: 500; color: var(--text); }
.toggle-switch {
    width: 44px; height: 24px;
    background: var(--border);
    border-radius: 12px;
    position: relative;
    transition: background 0.2s;
    flex-shrink: 0;
}
.toggle-switch::after {
    content: '';
    position: absolute;
    width: 18px; height: 18px;
    background: #fff;
    border-radius: 50%;
    top: 3px; left: 3px;
    transition: transform 0.2s;
}
.toggle-row.checked .toggle-switch { background: var(--accent); }
.toggle-row.checked .toggle-switch::after { transform: translateX(20px); }

/* QR upload preview */
.qr-upload-wrap { position: relative; }
.qr-upload-preview {
    display: none;
    margin-top: 8px;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--surface2);
    border-radius: 8px;
    border: 1px solid var(--border);
}
.qr-upload-preview.visible { display: flex; }
.qr-upload-preview img {
    width: 52px; height: 52px;
    object-fit: contain;
    border-radius: 7px;
    border: 1px solid var(--border);
}
.qr-upload-preview span { font-size: 12px; color: var(--accent); word-break: break-all; }

/* Action buttons */
.act-btn {
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    border: 1.5px solid;
    transition: background 0.14s;
    touch-action: manipulation;
    white-space: nowrap;
    min-height: 34px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.act-edit    { background: rgba(0,229,160,0.08);  border-color: var(--accent);  color: var(--accent); }
.act-edit:hover { background: rgba(0,229,160,0.2); }
.act-toggle-on  { background: rgba(239,68,68,0.07);  border-color: rgba(239,68,68,0.4); color: var(--danger); }
.act-toggle-on:hover  { background: rgba(239,68,68,0.18); }
.act-toggle-off { background: rgba(0,229,160,0.07);  border-color: rgba(0,229,160,0.4); color: var(--accent); }
.act-toggle-off:hover { background: rgba(0,229,160,0.18); }
.act-delete { background: transparent; border-color: rgba(239,68,68,0.3); color: var(--danger); opacity: 0.7; }
.act-delete:hover { background: rgba(239,68,68,0.14); opacity: 1; }
.act-rm-qr { background: transparent; border-color: var(--border); color: var(--muted); font-size: 11px; padding: 4px 10px; min-height: 28px; }
.act-rm-qr:hover { border-color: var(--danger); color: var(--danger); }

/* Sort order badge */
.sort-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px; height: 22px;
    border-radius: 6px;
    background: var(--surface2);
    border: 1px solid var(--border);
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    flex-shrink: 0;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 50px 20px;
    color: var(--muted);
}
.empty-state .icon { font-size: 48px; margin-bottom: 12px; }

/* Lightbox */
.qr-lightbox {
    display: none; position: fixed; inset: 0; z-index: 2000;
    background: rgba(0,0,0,0.92); align-items: center; justify-content: center;
    padding: 20px; cursor: zoom-out;
}
.qr-lightbox.open { display: flex; }
.qr-lightbox img { max-width: 100%; max-height: 88vh; border-radius: 14px; object-fit: contain; }

/* Confirm delete overlay */
.confirm-overlay {
    display: none; position: fixed; inset: 0; z-index: 1500;
    background: rgba(0,0,0,0.8); backdrop-filter: blur(4px);
    align-items: center; justify-content: center; padding: 20px;
}
.confirm-overlay.open { display: flex; }
.confirm-box {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 18px; padding: 28px 24px; width: 100%; max-width: 380px; text-align: center;
}
.confirm-box h3 { font-family:'Bebas Neue',sans-serif; font-size: 24px; margin-bottom: 8px; }
.confirm-box p  { font-size: 14px; color: var(--muted); margin-bottom: 22px; line-height: 1.6; }
.confirm-btns   { display: flex; gap: 10px; }
.confirm-btns button { flex: 1; padding: 12px; font-size: 14px; font-weight: 700; border-radius: 10px; cursor: pointer; touch-action: manipulation; }

@media (max-width: 480px) {
    .method-header { padding: 13px 14px 11px; }
    .method-body   { padding: 0 14px 13px; gap: 10px; }
    .form-card     { padding: 18px 15px; }
    .method-actions { gap: 5px; }
}
</style>

<!-- Page header -->
<div class="page-header flex-between">
    <div>
        <h1>Payment Settings</h1>
        <p>Manage online payment channels shown to players at booking</p>
    </div>
    <a href="<?= APP_URL ?>/admin/schedule.php" class="btn-outline btn-sm">← Back to Schedule</a>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type']==='success'?'success':($flash['type']==='warn'?'warn':'error') ?>"
     style="margin-bottom:16px;border-radius:10px;">
    <?= clean($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Info banner -->
<div style="background:rgba(0,229,160,0.06);border:1px solid rgba(0,229,160,0.2);border-radius:12px;
            padding:13px 18px;margin-bottom:22px;font-size:13px;color:var(--muted);line-height:1.7;">
    💡 <strong style="color:var(--text);">Active</strong> payment methods and their QR codes are displayed to players when they choose
    <em>Pay Online</em> during booking. You can have multiple methods active at the same time.
    Players will see the method name, account details, and QR code to scan.
</div>

<div class="ps-layout">

    <!-- ── LEFT: Method list ── -->
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
            <div style="font-size:14px;font-weight:700;color:var(--text);">
                <?= count($methods) ?> Payment Method<?= count($methods)!==1?'s':'' ?>
            </div>
            <a href="<?= APP_URL ?>/admin/payment_settings.php" class="btn-outline btn-sm" style="font-size:12px;">+ Add New</a>
        </div>

        <?php if (empty($methods)): ?>
            <div class="empty-state">
                <div class="icon">💳</div>
                <p>No payment methods yet.<br>Add your first one using the form →</p>
            </div>
        <?php else: ?>
            <?php foreach ($methods as $m): ?>
            <?php
                $isActive = (bool)$m['is_active'];
                $methodIcons = [
                    'gcash'        => '💙',
                    'maya'         => '💚',
                    'paypal'       => '💛',
                    'bank'         => '🏦',
                    'bank transfer'=> '🏦',
                    'bdo'          => '🏦',
                    'bpi'          => '🏦',
                    'metrobank'    => '🏦',
                    'landbank'     => '🏦',
                    'unionbank'    => '🏦',
                    'cash'         => '💵',
                ];
                $iconKey = strtolower($m['method_name']);
                $methodIcon = $methodIcons[$iconKey] ?? '💳';
            ?>
            <div class="method-card <?= $isActive ? 'active-card' : 'inactive-card' ?>">
                <div class="method-header">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <div class="sort-badge"><?= (int)$m['sort_order'] ?: '—' ?></div>
                        <div class="method-badge-name">
                            <span class="method-badge-icon"><?= $methodIcon ?></span>
                            <?= clean($m['method_name']) ?>
                        </div>
                        <span class="status-pill <?= $isActive ? 'on' : 'off' ?>">
                            <span class="status-dot"></span>
                            <?= $isActive ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                    <div class="method-actions">
                        <!-- Edit -->
                        <a href="?edit=<?= $m['id'] ?>#form-section" class="act-btn act-edit">✏️ Edit</a>

                        <!-- Toggle active -->
                        <form method="POST" style="display:inline;">
            <?= csrfField() ?>
                            <input type="hidden" name="action"     value="toggle">
                            <input type="hidden" name="setting_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="act-btn <?= $isActive ? 'act-toggle-on' : 'act-toggle-off' ?>">
                                <?= $isActive ? '🔴 Deactivate' : '🟢 Activate' ?>
                            </button>
                        </form>

                        <!-- Delete -->
                        <button class="act-btn act-delete"
                                onclick="confirmDelete(<?= $m['id'] ?>,'<?= addslashes(clean($m['method_name'])) ?>')">
                            🗑
                        </button>
                    </div>
                </div>

                <div class="method-body">
                    <div>
                        <div class="method-info-row">
                            <span class="info-label">Account</span>
                            <span class="info-value"><?= clean($m['account_name']) ?></span>
                        </div>
                        <div class="method-info-row">
                            <span class="info-label">Number</span>
                            <span class="info-value mono"><?= clean($m['account_number']) ?></span>
                        </div>
                        <?php if ($m['instructions']): ?>
                        <div class="method-info-row">
                            <span class="info-label">Note</span>
                            <span class="info-value" style="color:var(--muted);font-size:12px;"><?= clean($m['instructions']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="method-info-row" style="margin-top:6px;">
                            <span class="info-label">Updated</span>
                            <span class="info-value" style="color:var(--muted);font-size:12px;">
                                <?= date('M d, Y g:i A', strtotime($m['updated_at'])) ?>
                            </span>
                        </div>
                    </div>

                    <!-- QR -->
                    <div class="qr-preview-box">
                        <?php if ($m['qr_image']): ?>
                            <img src="<?= APP_URL ?>/uploads/payment_qr/<?= urlencode($m['qr_image']) ?>"
                                 class="qr-preview-img"
                                 onclick="openQRLightbox('<?= APP_URL ?>/uploads/payment_qr/<?= urlencode($m['qr_image']) ?>')"
                                 alt="QR Code — <?= clean($m['method_name']) ?>">
                            <span style="font-size:10px;color:var(--accent);">Tap to enlarge</span>
                            <form method="POST" style="margin-top:2px;">
            <?= csrfField() ?>
                                <input type="hidden" name="action"     value="remove_qr">
                                <input type="hidden" name="setting_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="act-btn act-rm-qr">✕ Remove QR</button>
                            </form>
                        <?php else: ?>
                            <div class="qr-placeholder">
                                <span style="font-size:28px;">📷</span>
                                <span>No QR<br>uploaded</span>
                            </div>
                            <a href="?edit=<?= $m['id'] ?>#form-section" style="font-size:11px;color:var(--accent);">Upload QR →</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── RIGHT: Add / Edit form ── -->
    <div id="form-section">
        <div class="form-card <?= $editRow ? 'editing' : '' ?>">
            <div class="form-title"><?= $editRow ? '✏️ Edit Payment Method' : '➕ Add Payment Method' ?></div>
            <div class="form-sub">
                <?= $editRow
                    ? 'Editing: <strong style="color:var(--accent);">' . clean($editRow['method_name']) . '</strong>'
                    : 'Add a new GCash, Maya, bank, or any payment channel.' ?>
            </div>

            <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
                <input type="hidden" name="action"     value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow): ?>
                <input type="hidden" name="setting_id" value="<?= $editRow['id'] ?>">
                <?php endif; ?>

                <!-- Method name -->
                <div class="form-group">
                    <label>Payment Method Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="method_name"
                           value="<?= clean($editRow['method_name'] ?? '') ?>"
                           placeholder="e.g. GCash, Maya, BDO Savings"
                           maxlength="100" required>
                </div>

                <!-- Account name -->
                <div class="form-group">
                    <label>Account / Receiver Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="account_name"
                           value="<?= clean($editRow['account_name'] ?? '') ?>"
                           placeholder="e.g. Padol Pickleball Court"
                           maxlength="150" required>
                </div>

                <!-- Account number -->
                <div class="form-group">
                    <label>Account Number / Mobile Number <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="account_number"
                           value="<?= clean($editRow['account_number'] ?? '') ?>"
                           placeholder="e.g. 0917 123 4567"
                           maxlength="100" required
                           style="font-family:monospace;letter-spacing:0.04em;">
                </div>

                <!-- QR image -->
                <div class="form-group">
                    <label>QR Code Image <?= $editRow ? '<span style="font-weight:400;color:var(--muted);">(leave blank to keep existing)</span>' : '' ?></label>
                    <?php if ($editRow && $editRow['qr_image']): ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;
                                padding:10px;background:var(--surface2);border-radius:9px;border:1px solid var(--border);">
                        <img src="<?= APP_URL ?>/uploads/payment_qr/<?= urlencode($editRow['qr_image']) ?>"
                             style="width:48px;height:48px;object-fit:contain;border-radius:6px;border:1px solid var(--border);"
                             alt="Current QR">
                        <div>
                            <div style="font-size:12px;font-weight:600;color:var(--text);">Current QR uploaded</div>
                            <div style="font-size:11px;color:var(--muted);">Upload a new file to replace it</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="qr-upload-wrap">
                        <input type="file" name="qr_image" id="qr-file-input"
                               accept="image/jpeg,image/png,image/webp,image/gif"
                               style="padding:8px 10px;font-size:13px;"
                               onchange="previewQR(this)">
                        <div class="qr-upload-preview" id="qr-preview">
                            <img id="qr-preview-img" src="" alt="QR preview">
                            <span id="qr-preview-name"></span>
                        </div>
                    </div>
                    <div style="font-size:11px;color:var(--muted);margin-top:5px;">
                        JPG / PNG / WEBP · Max <?= MAX_UPLOAD_MB ?>MB. Square images work best.
                    </div>
                </div>

                <!-- Instructions -->
                <div class="form-group">
                    <label>Instructions for Players <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                    <textarea name="instructions" rows="3" maxlength="1000"
                              placeholder="e.g. Send exact amount. Screenshot must show sender name and reference number."
                              style="resize:vertical;"><?= clean($editRow['instructions'] ?? '') ?></textarea>
                </div>

                <!-- Sort order -->
                <div class="form-group">
                    <label>Display Order <span style="font-weight:400;color:var(--muted);">(lower = first)</span></label>
                    <input type="number" name="sort_order" min="0" max="99"
                           value="<?= (int)($editRow['sort_order'] ?? 0) ?>"
                           style="max-width:100px;">
                </div>

                <!-- Active toggle -->
                <?php $isChecked = $editRow ? (bool)$editRow['is_active'] : true; ?>
                <div class="toggle-row <?= $isChecked ? 'checked' : '' ?>" id="active-toggle-row"
                     onclick="toggleActive(this)">
                    <span class="toggle-label">Show to players (Active)</span>
                    <div class="toggle-switch"></div>
                    <input type="checkbox" name="is_active" id="is-active-cb"
                           <?= $isChecked ? 'checked' : '' ?>
                           style="display:none;">
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn-primary" style="flex:1;min-width:120px;padding:13px;font-size:14px;">
                        <?= $editRow ? '💾 Save Changes' : '➕ Add Method' ?>
                    </button>
                    <?php if ($editRow): ?>
                        <a href="<?= APP_URL ?>/admin/payment_settings.php" class="btn-outline"
                           style="flex:1;min-width:100px;padding:13px;font-size:14px;text-align:center;">
                            Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Preview box — how it looks to players -->
        <div class="card" style="margin-top:18px;border-color:rgba(0,229,160,0.15);">
            <div class="card-title" style="font-size:14px;">👁 Player Preview</div>
            <div class="card-subtitle">How active methods appear during booking</div>
            <hr class="divider"/>
            <?php
            $activeMethods = array_values(array_filter($methods, fn($m) => (bool)$m['is_active']));
            ?>
            <?php if (empty($activeMethods)): ?>
                <p style="font-size:13px;color:var(--muted);text-align:center;padding:14px 0;">
                    No active methods — players will only see "Pay in Person".
                </p>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($activeMethods as $am): ?>
                    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;
                                padding:13px 14px;display:flex;gap:12px;align-items:flex-start;">
                        <?php if ($am['qr_image']): ?>
                            <img src="<?= APP_URL ?>/uploads/payment_qr/<?= urlencode($am['qr_image']) ?>"
                                 style="width:64px;height:64px;object-fit:contain;border-radius:9px;
                                        border:1px solid var(--border);flex-shrink:0;background:#fff;">
                        <?php else: ?>
                            <div style="width:64px;height:64px;border-radius:9px;border:1.5px dashed var(--border);
                                        display:flex;align-items:center;justify-content:center;
                                        font-size:24px;flex-shrink:0;">📷</div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:700;font-size:14px;color:var(--accent);"><?= clean($am['method_name']) ?></div>
                            <div style="font-size:12px;color:var(--muted);margin-top:2px;"><?= clean($am['account_name']) ?></div>
                            <div style="font-family:monospace;font-size:13px;color:var(--text);margin-top:2px;"><?= clean($am['account_number']) ?></div>
                            <?php if ($am['instructions']): ?>
                                <div style="font-size:11px;color:var(--muted);margin-top:4px;line-height:1.5;"><?= clean($am['instructions']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /ps-layout -->

<!-- ── Delete confirm overlay ── -->
<div id="delete-overlay" class="confirm-overlay">
    <div class="confirm-box">
        <h3>🗑 Delete Method</h3>
        <p id="delete-msg">Are you sure you want to delete this payment method? This cannot be undone.</p>
        <form method="POST" id="delete-form">
            <?= csrfField() ?>
            <input type="hidden" name="action"     value="delete">
            <input type="hidden" name="setting_id" id="delete-id">
            <div class="confirm-btns">
                <button type="submit"
                        style="background:var(--danger);color:#fff;border:none;">
                    Delete
                </button>
                <button type="button"
                        onclick="closeDeleteOverlay()"
                        style="background:var(--surface2);color:var(--text);border:1px solid var(--border);">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── QR Lightbox ── -->
<div id="qr-lightbox" class="qr-lightbox" onclick="closeQRLightbox()">
    <img id="qr-lb-img" src="" alt="QR Code">
</div>

<script nonce="<?= getCspNonce() ?>">
// ── Active toggle ─────────────────────────────────────────────
function toggleActive(row) {
    row.classList.toggle('checked');
    const cb = document.getElementById('is-active-cb');
    cb.checked = row.classList.contains('checked');
}

// ── QR preview ────────────────────────────────────────────────
function previewQR(input) {
    const wrap = document.getElementById('qr-preview');
    const img  = document.getElementById('qr-preview-img');
    const name = document.getElementById('qr-preview-name');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
        name.textContent = input.files[0].name + ' (' + (input.files[0].size/1024).toFixed(1) + ' KB)';
        wrap.classList.add('visible');
    }
}

// ── Delete confirm ────────────────────────────────────────────
function confirmDelete(id, name) {
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-msg').textContent =
        'Delete "' + name + '"? This also removes the QR image. This cannot be undone.';
    document.getElementById('delete-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDeleteOverlay() {
    document.getElementById('delete-overlay').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('delete-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteOverlay();
});

// ── QR lightbox ───────────────────────────────────────────────
function openQRLightbox(src) {
    document.getElementById('qr-lb-img').src = src;
    document.getElementById('qr-lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeQRLightbox() {
    document.getElementById('qr-lightbox').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeDeleteOverlay();
        closeQRLightbox();
    }
});

// ── Auto-scroll to form when editing ─────────────────────────
<?php if ($editRow): ?>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('form-section');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>