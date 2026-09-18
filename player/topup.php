<?php
// ============================================================
//  FILE: player/topup.php
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = $_SESSION['user_id'];

$wallet  = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$wallet->execute([$uid]);
$balance = $wallet->fetchColumn() ?? 0;

$pending = $db->prepare("
    SELECT id, amount, method, gcash_ref_no, status, created_at, reviewed_at, review_note
    FROM falcon.topup_requests
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$pending->execute([$uid]);
$requests = $pending->fetchAll();

// ── Load payment options configured by admin ──────────────────
$payOptRow = $db->prepare("SELECT value FROM falcon.site_content WHERE section = 'payment' AND key = 'options'");
$payOptRow->execute();
$row = $payOptRow->fetch();
$paymentOptions = [];
if ($row) {
    $decoded = json_decode($row['value'], true);
    if (is_array($decoded)) {
        // Show all options EXCEPT those the admin explicitly hid via toggle.
        // active=false  → hidden (admin pressed Hide)
        // active=true   → visible
        // active missing/null → visible (legacy/default)
        // NOTE: active is only ever set to false by the toggle button,
        //       never by accident — the add/edit forms now always write true/false explicitly.
        $paymentOptions = array_values(array_filter($decoded, function($o) {
            // Not set or null → show (backwards compat with old data)
            if (!isset($o['active']) || $o['active'] === null) return true;
            // Stored as JSON boolean false → hidden
            if ($o['active'] === false) return false;
            // Stored as JSON boolean true, or integer 1 → show
            return (bool)$o['active'];
        }));
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Active tab: which payment method is selected (default to first or 0)
$activePayIdx = 0;

$pageTitle = 'Load Credits';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Topup page responsive ───────────────────────────────── */
.topup-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 32px;
}
.topup-right-col {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
@media (max-width: 768px) {
    .topup-layout { grid-template-columns: 1fr; }
}

/* Payment method tabs */
.pay-method-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.pay-method-tab {
    padding: 8px 16px;
    border-radius: 20px;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    touch-action: manipulation;
    white-space: nowrap;
}
.pay-method-tab.active,
.pay-method-tab:hover {
    border-color: var(--accent);
    background: rgba(0,229,160,0.1);
    color: var(--accent);
}

/* Payment panel */
.pay-panel { display: none; }
.pay-panel.active { display: block; }

/* Preset amount buttons */
.preset-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}
@media (max-width: 400px) {
    .preset-grid { grid-template-columns: repeat(2, 1fr); }
}

/* Drop zone */
.drop-zone {
    border: 2px dashed var(--border);
    border-radius: 10px;
    padding: 28px 16px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s;
}
.drop-zone:hover { border-color: var(--accent); }

/* Recent requests table */
@media (max-width: 640px) {
    .col-hide-topup { display: none; }
}

/* QR box */
.qr-box {
    background: #fff;
    border-radius: 12px;
    padding: 14px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    margin-bottom: 12px;
}
.qr-box img {
    width: clamp(120px, 40vw, 180px);
    height: auto;
    display: block;
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Load Credits</h1>
        <p>Top up your wallet to play on the court.</p>
    </div>
    <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-outline btn-sm">← Back</a>
</div>

<!-- Balance Banner -->
<div class="stat-card mb-3" style="text-align:center;max-width:360px;margin:0 auto 28px;">
    <div class="stat-val">₱<?= number_format($balance, 2) ?></div>
    <div class="stat-label">Current Credit Balance</div>
</div>

<div class="topup-layout">

    <!-- Payment Form Column -->
    <div>
        <?php if (empty($paymentOptions)): ?>
        <!-- Fallback: no payment options configured yet -->
        <div class="card" style="text-align:center;padding:32px 20px;">
            <div style="font-size:40px;margin-bottom:12px;">⚙️</div>
            <div style="font-weight:700;margin-bottom:6px;">Payment methods not configured</div>
            <div style="color:var(--muted);font-size:14px;">
                Please visit the court in person, or ask the admin to configure online payment options.
            </div>
        </div>
        <?php else: ?>

        <!-- Method selector tabs (if more than 1 option) -->
        <?php if (count($paymentOptions) > 1): ?>
        <div class="pay-method-tabs" id="pay-tabs">
            <?php foreach ($paymentOptions as $pi => $popt): ?>
            <button type="button"
                    class="pay-method-tab <?= $pi === 0 ? 'active' : '' ?>"
                    onclick="switchPayMethod(<?= $pi ?>)">
                <?= clean($popt['name']) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- One panel per payment option -->
        <?php foreach ($paymentOptions as $pi => $popt): ?>
        <div class="pay-panel <?= $pi === 0 ? 'active' : '' ?>" id="pay-panel-<?= $pi ?>">
            <div class="card">
                <div class="card-title">📱 <?= clean($popt['name']) ?></div>
                <div class="card-subtitle">Pay via <?= clean($popt['name']) ?>, then submit your reference number</div>
                <hr class="divider"/>

                <div style="text-align:center;margin-bottom:20px;">
                    <p style="font-size:13px;color:var(--muted);margin-bottom:12px;">Send payment to:</p>

                    <?php
$topupQrSrc = null;
if (!empty($popt['qr_image'])) {
    if (str_starts_with($popt['qr_image'], 'data:image/')) {
        $topupQrSrc = $popt['qr_image'];
    } elseif (file_exists(APP_ROOT . '/uploads/payment/' . $popt['qr_image'])) {
        $topupQrSrc = APP_URL . '/uploads/payment/' . urlencode($popt['qr_image']);
    }
}
?>
<?php if ($topupQrSrc): ?>
<div class="qr-box" style="display:inline-flex;">
    <img src="<?= htmlspecialchars($topupQrSrc) ?>"
         alt="<?= clean($popt['name']) ?> QR Code"
         onclick="openQrLightbox('<?= htmlspecialchars($topupQrSrc, ENT_QUOTES) ?>', '<?= addslashes(clean($popt['name'])) ?>')"
         style="cursor:zoom-in;"/>
</div><br/>
<?php endif; ?>

                    <div style="padding:14px;background:var(--surface2);border-radius:10px;
                                font-size:14px;display:inline-block;min-width:min(220px,90%);max-width:100%;">
                        <?php if (!empty($popt['account_name'])): ?>
                        <div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Account Name</div>
                        <div style="font-weight:700;color:var(--accent);margin-bottom:8px;"><?= clean($popt['account_name']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($popt['account_no'])): ?>
                        <div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">
                            <?= clean($popt['name']) ?> Number
                        </div>
                        <div style="font-weight:700;font-size:18px;"><?= clean($popt['account_no']) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($popt['instructions'])): ?>
                    <div style="margin-top:10px;padding:10px 14px;background:rgba(0,229,160,0.06);
                                border:1px solid rgba(0,229,160,0.15);border-radius:8px;
                                font-size:12px;color:var(--muted);text-align:left;line-height:1.7;">
                        <?= nl2br(clean($popt['instructions'])) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <form action="<?= APP_URL ?>/player/process_gcash.php" method="POST"
                      enctype="multipart/form-data" class="topup-form-<?= $pi ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="method" value="<?= clean($popt['name']) ?>">

                    <div class="form-group">
                        <label>Amount Sent (₱)</label>
                        <div class="preset-grid">
                            <?php foreach ([50,100,200,300,500,1000] as $amt): ?>
                                <button type="button" class="preset-btn btn-outline btn-sm"
                                        onclick="setAmount(this, <?= $pi ?>)" data-amount="<?= $amt ?>"
                                        style="touch-action:manipulation;">
                                    ₱<?= $amt ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="number" class="amount-input" id="amount-<?= $pi ?>"
                               name="amount" placeholder="Or type custom amount"
                               min="10" step="0.01" required style="font-size:16px;"/>
                    </div>

                    <div class="form-group">
                        <label>Reference Number</label>
                        <input type="text" name="gcash_ref_no"
                               placeholder="e.g. 1234567890123" maxlength="30"
                               pattern="[0-9A-Za-z\-]+" required style="font-size:16px;"/>
                    </div>

                    <div class="form-group">
                        <label>Screenshot of Payment <span style="color:var(--muted)">(JPG/PNG, max 5MB)</span></label>
                        <div class="drop-zone" id="drop-zone-<?= $pi ?>"
                             onclick="document.getElementById('screenshot-<?= $pi ?>').click()">
                            <div id="drop-label-<?= $pi ?>">
                                <span style="font-size:36px;">📸</span><br/>
                                <span style="color:var(--muted);font-size:14px;">Tap or drag screenshot here</span>
                            </div>
                            <img id="preview-img-<?= $pi ?>" src="" alt=""
                                 style="display:none;max-width:100%;border-radius:8px;margin-top:8px;"/>
                        </div>
                        <input type="file" name="screenshot" id="screenshot-<?= $pi ?>"
                               accept="image/jpeg,image/png,image/webp" required style="display:none;"
                               onchange="showPreview(this, <?= $pi ?>)"/>
                    </div>

                    <button type="submit" class="btn-primary submit-btn"
                            style="width:100%;padding:14px;font-size:15px;touch-action:manipulation;">
                        📤 Submit Top-Up Request
                    </button>
                    <p style="font-size:12px;color:var(--muted);text-align:center;margin-top:10px;">
                        The court owner will verify your payment and credit your account within minutes.
                    </p>
                </form>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; // end if payment options exist ?>
    </div>

    <!-- Right column -->
    <div class="topup-right-col">

        <div class="card">
            <div class="card-title">ℹ️ How It Works</div>
            <hr class="divider"/>
            <ol style="padding-left:20px;color:var(--muted);font-size:14px;line-height:2.2;">
                <li>Select your preferred payment method</li>
                <li>Send payment to the displayed number/QR</li>
                <li>Enter the exact amount you sent</li>
                <li>Enter your reference number</li>
                <li>Upload a screenshot of your confirmation</li>
                <li>Submit — owner verifies and approves shortly</li>
                <li>Credits appear + your QR code is <strong style="color:var(--accent);">activated</strong></li>
            </ol>
            <div style="margin-top:12px;padding:12px;background:rgba(245,158,11,0.1);
                        border:1px solid rgba(245,158,11,0.3);border-radius:10px;font-size:13px;color:#fcd34d;">
                ⚠️ Each reference number can only be used <strong>once</strong>.
            </div>
        </div>

        <div class="card">
            <div class="card-title">🏪 In-Person Top-Up</div>
            <div class="card-subtitle">Pay cash at the court — credits added instantly</div>
            <hr class="divider"/>
            <p style="font-size:14px;color:var(--muted);line-height:1.8;">
                Walk up to the court counter, pay cash, and the owner will scan your
                <strong style="color:var(--accent);">QR code</strong> directly.
                Credits are added immediately — no waiting, no screenshots.
            </p>
            <a href="<?= APP_URL ?>/player/my_qr.php" class="btn-outline"
               style="display:block;text-align:center;padding:12px;margin-top:12px;
                      touch-action:manipulation;">
                📱 Show My QR Code
            </a>
        </div>

    </div>
</div>

<!-- Recent Requests -->
<div class="card">
    <div class="flex-between mb-2">
        <div>
            <div class="card-title">📋 My Top-Up Requests</div>
            <div class="card-subtitle">Recent submissions and their status</div>
        </div>
        <a href="<?= APP_URL ?>/player/topup_history.php" class="btn-outline btn-sm">View All</a>
    </div>

    <?php if (empty($requests)): ?>
        <p class="text-muted text-center" style="padding:24px;">No top-up requests yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th class="col-hide-topup">Ref No.</th>
                        <th>Status</th>
                        <th class="col-hide-topup">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r):
                        $b = match($r['status']) { 'approved'=>'success','rejected'=>'danger',default=>'warn' };
                    ?>
                        <tr>
                            <td style="font-size:12px;white-space:nowrap;">
                                <?= date('M d, Y', strtotime($r['created_at'])) ?><br>
                                <span style="color:var(--muted);"><?= date('h:i A', strtotime($r['created_at'])) ?></span>
                            </td>
                            <td style="color:var(--accent);font-weight:600;white-space:nowrap;">
                                ₱<?= number_format($r['amount'], 2) ?>
                            </td>
                            <td class="col-hide-topup" style="font-family:monospace;font-size:12px;">
                                <?= $r['gcash_ref_no'] ? clean($r['gcash_ref_no']) : '—' ?>
                            </td>
                            <td><span class="badge badge-<?= $b ?>"><?= ucfirst($r['status']) ?></span></td>
                            <td class="col-hide-topup" style="font-size:12px;color:var(--muted);">
                                <?= $r['review_note'] ? clean($r['review_note']) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script nonce="<?= getCspNonce() ?>">
function switchPayMethod(idx) {
    document.querySelectorAll('.pay-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.pay-method-tab').forEach(function(t) { t.classList.remove('active'); });
    const panel = document.getElementById('pay-panel-' + idx);
    if (panel) panel.classList.add('active');
    const tabs = document.querySelectorAll('.pay-method-tab');
    if (tabs[idx]) tabs[idx].classList.add('active');
}

function setAmount(btn, panelIdx) {
    const val = btn.dataset.amount;
    const input = document.getElementById('amount-' + panelIdx);
    if (input) input.value = val;
    // Highlight within the same panel
    const panel = document.getElementById('pay-panel-' + panelIdx);
    if (panel) {
        panel.querySelectorAll('.preset-btn').forEach(function(b) { b.classList.remove('active-preset'); });
        btn.classList.add('active-preset');
    }
}

function showPreview(input, idx) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('preview-img-' + idx);
        const label   = document.getElementById('drop-label-' + idx);
        if (preview) { preview.src = e.target.result; preview.style.display = 'block'; }
        if (label) label.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

// Drag-drop for each drop zone
<?php foreach ($paymentOptions as $pi => $popt): ?>
(function() {
    var dz    = document.getElementById('drop-zone-<?= $pi ?>');
    var input = document.getElementById('screenshot-<?= $pi ?>');
    if (!dz || !input) return;
    dz.addEventListener('dragover', function(e) {
        e.preventDefault(); dz.style.borderColor = 'var(--accent)';
    });
    dz.addEventListener('dragleave', function() {
        dz.style.borderColor = 'var(--border)';
    });
    dz.addEventListener('drop', function(e) {
        e.preventDefault(); dz.style.borderColor = 'var(--border)';
        if (e.dataTransfer.files[0]) {
            // Assign to the file input
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            input.files = dt.files;
            showPreview(input, <?= $pi ?>);
        }
    });
})();
<?php endforeach; ?>

// Submit guard — disable button to prevent double-submit
document.querySelectorAll('form[class*="topup-form-"]').forEach(function(form) {
    form.addEventListener('submit', function() {
        const btn = form.querySelector('.submit-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
    });
});
</script>

<style nonce="<?= getCspNonce() ?>">
.active-preset {
    background: rgba(0,229,160,.15) !important;
    border-color: var(--accent) !important;
    color: var(--accent) !important;
}
</style>
<!-- QR Lightbox -->
<div id="qr-lightbox" onclick="closeQrLightbox()"
     style="display:none;position:fixed;inset:0;z-index:9999;
            background:rgba(0,0,0,0.88);backdrop-filter:blur(6px);
            align-items:center;justify-content:center;
            flex-direction:column;gap:16px;padding:20px;">
    <div style="position:relative;max-width:min(92vw,480px);width:100%;">
        <img id="qr-lb-img" src="" alt=""
             style="width:100%;border-radius:16px;background:#fff;
                    padding:20px;box-shadow:0 8px 40px rgba(0,0,0,0.5);
                    display:block;"/>
        <button onclick="closeQrLightbox()"
                style="position:absolute;top:-14px;right:-14px;
                       background:var(--surface);border:2px solid var(--border);
                       color:var(--text);border-radius:50%;width:36px;height:36px;
                       font-size:18px;cursor:pointer;display:flex;
                       align-items:center;justify-content:center;
                       box-shadow:0 2px 10px rgba(0,0,0,0.4);">✕</button>
    </div>
    <div id="qr-lb-label"
         style="color:#fff;font-size:14px;font-weight:700;
                letter-spacing:0.5px;opacity:0.85;text-align:center;">
    </div>
    <div style="color:rgba(255,255,255,0.5);font-size:12px;">
        Tap anywhere to close
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
function openQrLightbox(src, label) {
    var lb = document.getElementById('qr-lightbox');
    document.getElementById('qr-lb-img').src   = src;
    document.getElementById('qr-lb-label').textContent = label + ' — Scan to Pay';
    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeQrLightbox() {
    document.getElementById('qr-lightbox').style.display = 'none';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeQrLightbox();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>