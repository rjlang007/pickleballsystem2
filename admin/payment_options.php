<?php
// ============================================================
//  FILE: admin/payment_options.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

// ── Helpers ──────────────────────────────────────────────────
function getPaymentOptions(PDO $db): array {
    try {
        $stmt = $db->prepare("SELECT value FROM falcon.site_content WHERE section = 'payment' AND key = 'options'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) return [];
        $data = json_decode($row['value'], true);
        return is_array($data) ? $data : [];
    } catch (PDOException $e) {
        error_log('[Payment Options] Failed to load payment options: ' . $e->getMessage());
        return [];
    }
}

function savePaymentOptions(PDO $db, array $options): bool {
    $encoded = json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
    try {
        $db->prepare("
            INSERT INTO falcon.site_content (section, key, value, updated_at)
            VALUES ('payment', 'options', :v, NOW())
            ON CONFLICT (section, key)
            DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
        ")->execute([':v' => $encoded]);
        return true;
    } catch (PDOException $e) {
        error_log('[Payment Options] Failed to save payment options: ' . $e->getMessage());
        setFlash('error', IS_PRODUCTION
            ? 'Something went wrong while saving payment options. Please try again.'
            : 'Database error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Handles QR upload — stores image as a base64 data URI in the DB.
 * This avoids filesystem/ephemeral storage issues on Railway.
 * Returns the base64 data URI string, or the existing value if no new file.
 */
function handleQrUpload(string $fileKey, ?string $existing = null): ?string {
    if (empty($_FILES[$fileKey]['name']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return $existing;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime    = mime_content_type($_FILES[$fileKey]['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        return $existing;
    }
    if ($_FILES[$fileKey]['size'] > 3 * 1024 * 1024) {
        return $existing;
    }

    $data = file_get_contents($_FILES[$fileKey]['tmp_name']);
    if ($data === false) return $existing;

    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

/**
 * Returns true if the stored qr_image value is a base64 data URI.
 * Legacy: old records may still hold a filename string.
 */
function qrIsDataUri(?string $val): bool {
    return $val !== null && str_starts_with($val, 'data:image/');
}

/**
 * For legacy filename-based QR images, build the URL.
 * For new base64 ones, return the data URI directly.
 */
function qrSrc(?string $val): ?string {
    if (empty($val)) return null;
    if (qrIsDataUri($val)) return $val;
    // Legacy: try filesystem
    if (file_exists(APP_ROOT . '/uploads/payment/' . $val)) {
        return APP_URL . '/uploads/payment/' . urlencode($val);
    }
    return null; // File gone (ephemeral), treat as missing
}

define('MAX_PAYMENT_OPTIONS', 3);

// ── POST handling ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $options = getPaymentOptions($db);

    if ($action === 'add_option' && count($options) < MAX_PAYMENT_OPTIONS) {
        $qr       = handleQrUpload('qr_image');
        $isActive = ($_POST['opt_active_val'] ?? '1') === '1';
        $options[] = [
            'id'           => uniqid('pay_'),
            'name'         => trim($_POST['opt_name'] ?? ''),
            'account_name' => trim($_POST['opt_account_name'] ?? ''),
            'account_no'   => trim($_POST['opt_account_no'] ?? ''),
            'instructions' => trim($_POST['opt_instructions'] ?? ''),
            'qr_image'     => $qr,
            'active'       => $isActive,
        ];
        if (savePaymentOptions($db, $options)) {
            setFlash('success', '✅ Payment option added.');
        }

    } elseif ($action === 'edit_option') {
        $idx = (int)($_POST['opt_idx'] ?? -1);
        if (isset($options[$idx])) {
            $qr       = handleQrUpload('qr_image', $options[$idx]['qr_image'] ?? null);
            $isActive = ($_POST['opt_active_val'] ?? '1') === '1';
            $options[$idx] = [
                'id'           => $options[$idx]['id'] ?? uniqid('pay_'),
                'name'         => trim($_POST['opt_name'] ?? ''),
                'account_name' => trim($_POST['opt_account_name'] ?? ''),
                'account_no'   => trim($_POST['opt_account_no'] ?? ''),
                'instructions' => trim($_POST['opt_instructions'] ?? ''),
                'qr_image'     => $qr,
                'active'       => $isActive,
            ];
            if (savePaymentOptions($db, $options)) {
                setFlash('success', '✅ Payment option updated.');
            }
        }

    } elseif ($action === 'delete_option') {
        $idx = (int)($_POST['opt_idx'] ?? -1);
        if (isset($options[$idx])) {
            // Legacy: clean up old filesystem file if present
            if (!empty($options[$idx]['qr_image']) && !qrIsDataUri($options[$idx]['qr_image'])) {
                @unlink(APP_ROOT . '/uploads/payment/' . $options[$idx]['qr_image']);
            }
            array_splice($options, $idx, 1);
            if (savePaymentOptions($db, array_values($options))) {
                setFlash('warn', '⚠️ Payment option removed.');
            }
        }

    } elseif ($action === 'toggle_option') {
        $idx = (int)($_POST['opt_idx'] ?? -1);
        if (isset($options[$idx])) {
            $currentlyActive         = isset($options[$idx]['active']) ? (bool)$options[$idx]['active'] : true;
            $options[$idx]['active'] = !$currentlyActive;
            if (savePaymentOptions($db, $options)) {
                $state = $options[$idx]['active'] ? 'enabled' : 'disabled';
                setFlash('success', "Payment option {$state}.");
            }
        }
    }

    header('Location: ' . APP_URL . '/admin/payment_options.php');
    exit;
}

$options = getPaymentOptions($db);

function optIsActive(array $opt): bool {
    if (!array_key_exists('active', $opt)) return true;
    return $opt['active'] === true || $opt['active'] === 1;
}

$pageTitle = 'Payment Options';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= htmlspecialchars(getCspNonce(), ENT_QUOTES, 'UTF-8') ?>">
.pay-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}
.pay-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.pay-card.inactive {
    opacity: 0.55;
    border-style: dashed;
}
.pay-card-header {
    background: var(--surface2);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border-bottom: 1px solid var(--border);
}
.pay-card-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.pay-card-name {
    font-weight: 700;
    font-size: 15px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pay-card-body {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.pay-qr-wrap {
    display: flex;
    justify-content: center;
    background: #fff;
    border-radius: 10px;
    padding: 12px;
    border: 1px solid var(--border);
}
.pay-qr-wrap img {
    width: 140px;
    height: 140px;
    object-fit: contain;
    display: block;
}
.pay-qr-placeholder {
    width: 140px;
    height: 140px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    color: var(--muted);
    font-size: 12px;
    text-align: center;
    border: 2px dashed var(--border);
    border-radius: 10px;
}
.pay-detail-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.pay-detail-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--muted);
    font-weight: 700;
}
.pay-detail-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
}
.pay-card-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.pay-instructions {
    font-size: 12px;
    color: var(--muted);
    line-height: 1.6;
    background: var(--surface2);
    border-radius: 8px;
    padding: 8px 10px;
    border: 1px solid var(--border);
}
.add-pay-panel {
    background: var(--surface);
    border: 1px solid var(--accent);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 24px;
    display: none;
}
.add-pay-panel.open { display: block; }
.pay-edit-panel {
    background: var(--surface);
    border: 1px solid var(--accent);
    border-radius: 14px;
    padding: 20px;
    margin-top: 12px;
    display: none;
}
.pay-edit-panel.open { display: block; }
.qr-drop-zone {
    border: 2px dashed var(--border);
    border-radius: 10px;
    padding: 20px 16px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s;
    background: var(--surface2);
    position: relative;
}
.qr-drop-zone:hover { border-color: var(--accent); }
.qr-drop-zone.has-file { border-color: var(--accent); background: rgba(0,229,160,0.05); }
.add-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.add-form-grid .span-2 { grid-column: 1 / -1; }
@media (max-width: 640px) {
    .add-form-grid { grid-template-columns: 1fr; }
    .add-form-grid .span-2 { grid-column: 1; }
    .pay-card-footer { flex-direction: column; }
    .pay-card-footer form, .pay-card-footer button { width: 100%; }
    .pay-card-footer form button { width: 100%; }
}
.status-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.status-dot.active   { background: var(--accent); box-shadow: 0 0 0 3px rgba(0,229,160,.2); }
.status-dot.inactive { background: var(--muted); }
.qr-selected-name {
    font-size: 12px;
    color: var(--accent);
    margin-top: 6px;
    font-weight: 600;
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>💳 Payment Options</h1>
        <p>Manage payment methods shown to players on the top-up page. Max <?= MAX_PAYMENT_OPTIONS ?> options.</p>
    </div>
    <div class="btn-group">
        <a href="<?= APP_URL ?>/player/topup.php" target="_blank" class="btn-outline btn-sm">👁 Preview Top-Up Page</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<?php if (count($options) < MAX_PAYMENT_OPTIONS): ?>
<div style="margin-bottom:16px;">
    <button type="button" class="btn-primary" id="btn-open-add">
        ➕ Add Payment Option
        <span style="opacity:0.7;font-size:12px;">(<?= count($options) ?>/<?= MAX_PAYMENT_OPTIONS ?>)</span>
    </button>
</div>

<!-- ── Add Panel ── -->
<div class="add-pay-panel" id="add-panel">
    <div style="font-size:15px;font-weight:700;color:var(--accent);margin-bottom:16px;">
        ➕ New Payment Option
    </div>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
        <input type="hidden" name="action" value="add_option"/>
        <div class="add-form-grid">
            <div class="form-group span-2" style="margin:0;">
                <label>Payment Method Name</label>
                <input type="text" name="opt_name"
                       placeholder="e.g. GCash, Maya, BDO Bank" required/>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Account Name</label>
                <input type="text" name="opt_account_name"
                       placeholder="Padol Pickleball Court"/>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Account Number / Phone</label>
                <input type="text" name="opt_account_no"
                       placeholder="0917 123 4567"/>
            </div>
            <div class="form-group span-2" style="margin:0;">
                <label>Instructions
                    <span style="color:var(--muted);font-weight:400;">(optional)</span>
                </label>
                <textarea name="opt_instructions" rows="3"
                          placeholder="Send payment then submit your reference number and a screenshot."></textarea>
            </div>
            <div class="form-group span-2" style="margin:0;">
                <label>QR Code Image
                    <span style="color:var(--muted);font-weight:400;">(JPG/PNG/WebP, max 3MB)</span>
                </label>
                <!-- Hidden real file input OUTSIDE the drop zone so clicks don't bubble -->
                <input type="file" id="add-qr-input" name="qr_image"
                       accept="image/jpeg,image/png,image/webp" style="display:none;"/>
                <div class="qr-drop-zone" id="add-drop-zone">
                    <div id="add-dz-default">
                        <span style="font-size:32px;">📷</span><br/>
                        <span style="color:var(--muted);font-size:13px;">Tap or drag to upload QR image</span>
                    </div>
                    <div id="add-qr-preview" style="display:none;">
                        <img id="add-qr-img" src="" alt=""
                             style="max-width:140px;max-height:140px;object-fit:contain;
                                    border-radius:8px;display:block;margin:0 auto;
                                    background:#fff;padding:6px;"/>
                        <div class="qr-selected-name" id="add-qr-name"></div>
                    </div>
                </div>
            </div>
            <div class="form-group" style="margin:0;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="hidden" name="opt_active_val" value="0"/>
                    <input type="checkbox" name="opt_active_val" value="1" checked style="width:auto;"/>
                    Show on top-up page immediately
                </label>
            </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="btn-primary">💾 Save Payment Option</button>
            <button type="button" class="btn-outline" id="btn-cancel-add">Cancel</button>
        </div>
    </form>
</div>
<?php else: ?>
<div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);
            border-radius:10px;padding:12px 16px;margin-bottom:20px;
            font-size:13px;color:#fcd34d;">
    ⚠️ Maximum of <?= MAX_PAYMENT_OPTIONS ?> payment options reached.
    Remove one to add another.
</div>
<?php endif; ?>

<!-- ── Current Options ── -->
<?php if (empty($options)): ?>
<div class="card" style="text-align:center;padding:40px 24px;">
    <div style="font-size:52px;margin-bottom:12px;">💳</div>
    <div style="font-size:18px;font-weight:700;margin-bottom:6px;">No Payment Options Yet</div>
    <div style="color:var(--muted);font-size:14px;">
        Add your first payment method above — it will appear on the player top-up page.
    </div>
</div>
<?php else: ?>
<div class="pay-grid">
    <?php foreach ($options as $i => $opt):
        $src = qrSrc($opt['qr_image'] ?? null);
    ?>
    <div>
        <!-- View Card -->
        <div class="pay-card <?= optIsActive($opt) ? '' : 'inactive' ?>">
            <div class="pay-card-header">
                <div class="pay-card-header-left">
                    <div class="status-dot <?= optIsActive($opt) ? 'active' : 'inactive' ?>"></div>
                    <div class="pay-card-name"><?= clean($opt['name']) ?></div>
                </div>
                <span class="badge <?= optIsActive($opt) ? 'badge-success' : 'badge-danger' ?>">
                    <?= optIsActive($opt) ? 'Active' : 'Hidden' ?>
                </span>
            </div>
            <div class="pay-card-body">
                <div class="pay-qr-wrap">
                    <?php if ($src): ?>
                        <img src="<?= htmlspecialchars($src) ?>"
                             alt="<?= clean($opt['name']) ?> QR Code"/>
                    <?php else: ?>
                        <div class="pay-qr-placeholder">
                            <span style="font-size:32px;">📷</span>
                            <span>No QR uploaded</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="pay-detail-row">
                    <div class="pay-detail-label">Account Name</div>
                    <div class="pay-detail-value"><?= clean($opt['account_name']) ?: '—' ?></div>
                </div>
                <div class="pay-detail-row">
                    <div class="pay-detail-label">Account Number</div>
                    <div class="pay-detail-value"
                         style="font-family:monospace;color:var(--accent);font-size:16px;">
                        <?= clean($opt['account_no']) ?: '—' ?>
                    </div>
                </div>
                <?php if (!empty($opt['instructions'])): ?>
                <div class="pay-instructions">
                    <?= nl2br(clean($opt['instructions'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="pay-card-footer">
                <button type="button" class="btn-outline btn-sm"
                        data-edit-idx="<?= $i ?>">✏️ Edit</button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                    <input type="hidden" name="action" value="toggle_option"/>
                    <input type="hidden" name="opt_idx" value="<?= $i ?>"/>
                    <button type="submit" class="btn-outline btn-sm"
                            style="<?= optIsActive($opt)
                                ? 'color:var(--warn);border-color:var(--warn);'
                                : 'color:var(--accent);border-color:var(--accent);' ?>">
                        <?= optIsActive($opt) ? '🙈 Hide' : '👁 Show' ?>
                    </button>
                </form>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Remove this payment option?')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                    <input type="hidden" name="action" value="delete_option"/>
                    <input type="hidden" name="opt_idx" value="<?= $i ?>"/>
                    <button type="submit" class="btn-danger btn-sm">🗑</button>
                </form>
            </div>
        </div>

        <!-- Inline Edit Panel -->
        <div class="pay-edit-panel" id="edit-panel-<?= $i ?>">
            <div style="font-size:15px;font-weight:700;color:var(--accent);margin-bottom:16px;">
                ✏️ Editing: <?= clean($opt['name']) ?>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                <input type="hidden" name="action" value="edit_option"/>
                <input type="hidden" name="opt_idx" value="<?= $i ?>"/>
                <div class="add-form-grid">
                    <div class="form-group span-2" style="margin:0;">
                        <label>Payment Method Name</label>
                        <input type="text" name="opt_name"
                               value="<?= clean($opt['name']) ?>"
                               placeholder="e.g. GCash, Maya, BDO" required/>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Account Name</label>
                        <input type="text" name="opt_account_name"
                               value="<?= clean($opt['account_name']) ?>"
                               placeholder="Padol Pickleball Court"/>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Account Number / Phone</label>
                        <input type="text" name="opt_account_no"
                               value="<?= clean($opt['account_no']) ?>"
                               placeholder="0917 123 4567"/>
                    </div>
                    <div class="form-group span-2" style="margin:0;">
                        <label>Instructions (optional)</label>
                        <textarea name="opt_instructions"
                                  rows="3"><?= clean($opt['instructions']) ?></textarea>
                    </div>
                    <div class="form-group span-2" style="margin:0;">
                        <label>QR Code Image
                            <span style="color:var(--muted);font-weight:400;">(leave blank to keep current)</span>
                        </label>

                        <?php if ($src): ?>
                        <!-- Current QR preview -->
                        <div style="margin-bottom:10px;display:flex;align-items:center;gap:12px;">
                            <div style="background:#fff;border-radius:8px;padding:8px;
                                        border:1px solid var(--border);">
                                <img src="<?= htmlspecialchars($src) ?>"
                                     alt="" style="width:80px;height:80px;
                                                  object-fit:contain;display:block;"/>
                            </div>
                            <span style="font-size:12px;color:var(--muted);">
                                Current QR — upload a new one to replace it
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- Hidden real file input OUTSIDE drop zone -->
                        <input type="file" id="edit-qr-input-<?= $i ?>" name="qr_image"
                               accept="image/jpeg,image/png,image/webp" style="display:none;"/>
                        <div class="qr-drop-zone" id="edit-drop-zone-<?= $i ?>">
                            <div id="edit-dz-default-<?= $i ?>">
                                <span style="font-size:28px;">📷</span><br/>
                                <span style="color:var(--muted);font-size:13px;">
                                    Tap or drag to upload new QR image
                                </span>
                            </div>
                            <div id="edit-qr-preview-<?= $i ?>" style="display:none;">
                                <img id="edit-qr-img-<?= $i ?>" src="" alt=""
                                     style="max-width:120px;max-height:120px;border-radius:8px;
                                            display:block;margin:0 auto;
                                            background:#fff;padding:6px;object-fit:contain;"/>
                                <div class="qr-selected-name" id="edit-qr-name-<?= $i ?>"></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="hidden" name="opt_active_val" value="0"/>
                            <input type="checkbox" name="opt_active_val" value="1"
                                   style="width:auto;"
                                   <?= optIsActive($opt) ? 'checked' : '' ?>/>
                            Show on top-up page
                        </label>
                    </div>
                </div>
                <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn-primary">💾 Save Changes</button>
                    <button type="button" class="btn-outline"
                            data-cancel-idx="<?= $i ?>">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Info box -->
<div class="card" style="border-color:rgba(0,229,160,0.2);background:rgba(0,229,160,0.03);">
    <div class="card-title" style="margin-bottom:10px;">ℹ️ How Payment Options Work</div>
    <div style="font-size:13px;color:var(--muted);line-height:1.9;">
        • Up to <strong style="color:var(--text);">3 payment options</strong> can be configured.<br/>
        • Each option shows a <strong style="color:var(--text);">QR code</strong>, account name,
          account number, and optional instructions on the player top-up page.<br/>
        • Use the <strong style="color:var(--text);">Show/Hide toggle</strong> to temporarily disable
          an option without deleting it.<br/>
        • Players see only <strong style="color:var(--text);">active</strong> options on the top-up page.<br/>
        • QR images are stored directly in the database as base64 — no filesystem required.
    </div>
</div>

<script nonce="<?= htmlspecialchars(getCspNonce(), ENT_QUOTES, 'UTF-8') ?>">
(function () {
    'use strict';

    /**
     * Wire a drop-zone + file input pair.
     * The file input lives OUTSIDE the drop zone div to avoid click conflicts.
     * Safe to call multiple times — guarded by dataset.wired.
     */
    function wireDropZone(dzId, inputId, defaultId, previewId, imgId, nameId) {
        var dz      = document.getElementById(dzId);
        var input   = document.getElementById(inputId);
        var dflt    = document.getElementById(defaultId);
        var preview = document.getElementById(previewId);
        var img     = document.getElementById(imgId);
        var nameEl  = document.getElementById(nameId);

        if (!dz || !input) return;
        if (dz.dataset.wired) return;
        dz.dataset.wired = '1';

        function showPreview(file) {
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                if (img)     { img.src = e.target.result; }
                if (preview) { preview.style.display = 'block'; }
                if (dflt)    { dflt.style.display = 'none'; }
                if (nameEl)  { nameEl.textContent = '✅ ' + file.name; }
                dz.classList.add('has-file');
            };
            reader.readAsDataURL(file);
        }

        // Click on drop zone → trigger file input
        dz.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            input.click();
        });

        // File selected via dialog
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                showPreview(input.files[0]);
            }
        });

        // Drag and drop
        dz.addEventListener('dragover', function (e) {
            e.preventDefault();
            dz.style.borderColor = 'var(--accent)';
        });
        dz.addEventListener('dragleave', function () {
            dz.style.borderColor = '';
        });
        dz.addEventListener('drop', function (e) {
            e.preventDefault();
            dz.style.borderColor = '';
            var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) {
                // Assign to the real file input
                try {
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                } catch (ex) { /* Safari fallback — preview only, upload may skip */ }
                showPreview(file);
            }
        });
    }

    // ── Add panel ────────────────────────────────────────────
    var btnOpen   = document.getElementById('btn-open-add');
    var btnCancel = document.getElementById('btn-cancel-add');
    var addPanel  = document.getElementById('add-panel');

    // Wire the add drop-zone immediately (it's always in the DOM if panel exists)
    wireDropZone('add-drop-zone', 'add-qr-input', 'add-dz-default', 'add-qr-preview', 'add-qr-img', 'add-qr-name');

    if (btnOpen) {
        btnOpen.addEventListener('click', function () {
            if (!addPanel) return;
            addPanel.classList.add('open');
            addPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
    if (btnCancel) {
        btnCancel.addEventListener('click', function () {
            if (addPanel) addPanel.classList.remove('open');
        });
    }

    // ── Edit panels ──────────────────────────────────────────
    // Wire ALL edit drop-zones immediately on page load — panels are in the DOM, just hidden.
    document.querySelectorAll('.pay-edit-panel').forEach(function (panel) {
        var idx = panel.id.replace('edit-panel-', '');
        wireDropZone(
            'edit-drop-zone-'  + idx,
            'edit-qr-input-'   + idx,
            'edit-dz-default-' + idx,
            'edit-qr-preview-' + idx,
            'edit-qr-img-'     + idx,
            'edit-qr-name-'    + idx
        );
    });

    function openEditPanel(idx) {
        if (addPanel) addPanel.classList.remove('open');
        document.querySelectorAll('.pay-edit-panel.open').forEach(function (p) {
            if (p.id !== 'edit-panel-' + idx) p.classList.remove('open');
        });
        var panel = document.getElementById('edit-panel-' + idx);
        if (!panel) return;
        panel.classList.add('open');
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function closeEditPanel(idx) {
        var panel = document.getElementById('edit-panel-' + idx);
        if (panel) panel.classList.remove('open');
    }

    document.addEventListener('click', function (e) {
        var editBtn   = e.target.closest('[data-edit-idx]');
        var cancelBtn = e.target.closest('[data-cancel-idx]');
        if (editBtn)   openEditPanel(editBtn.dataset.editIdx);
        if (cancelBtn) closeEditPanel(cancelBtn.dataset.cancelIdx);
    });

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>