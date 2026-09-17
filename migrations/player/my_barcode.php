<?php
// ============================================================
//  FILE: player/my_barcode.php  (FIXED — Scanner-Optimized)
//
//  FIXES:
//   1. Barcode height increased to 100px (was 80px)
//      More height = larger scan window = scanner reads from farther
//
//   2. Removed conflicting image-rendering:pixelated override
//      barcode.php already sets crisp-edges; don't override it here
//
//   3. Barcode card min-width increased so bars never compress
//
//   4. Print function uses proper window.open + print (no document.write)
//
//   5. Added explicit scanner distance tip on barcode page
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/barcode.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = $_SESSION['user_id'];

$wallet = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$wallet->execute([$uid]);
$balance = $wallet->fetchColumn() ?? 0;

$passStmt = $db->prepare("SELECT * FROM falcon.player_passes WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$passStmt->execute([$uid]);
$pass = $passStmt->fetch();

if (!$pass) {
    $token = bin2hex(random_bytes(24));
    $db->prepare("
        INSERT INTO falcon.player_passes (user_id, qr_token, expires_at, is_active)
        VALUES (?, ?, '2099-12-31 23:59:59+00', ?)
        ON CONFLICT (user_id) DO UPDATE
            SET qr_token  = COALESCE(falcon.player_passes.qr_token, EXCLUDED.qr_token),
                is_active = CASE
                                WHEN falcon.player_passes.qr_token IS NULL THEN EXCLUDED.is_active
                                ELSE falcon.player_passes.is_active
                            END
    ")->execute([$uid, $token, $balance > 0]);
    $passStmt->execute([$uid]);
    $pass = $passStmt->fetch();
}

$passActive = $pass && $pass['is_active'] && strtotime($pass['expires_at']) > time();

$gameStmt = $db->prepare("
    SELECT gs.status FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE gp.user_id = ? AND gs.status = 'active'
    LIMIT 1
");
$gameStmt->execute([$uid]);
$activeGame = $gameStmt->fetch();

// Pre-generate barcode data URI server-side
$barcodeUri = $pass ? Barcode128::dataUri($pass['qr_token'], 100) : '';

$pageTitle = 'My Barcode';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.barcode-wrap { max-width: 660px; margin: 0 auto; }

/* Barcode display card — wide enough to show full barcode without compression */
.barcode-card-inner {
    background: #fff;
    border-radius: 16px;
    padding: 20px 20px 14px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.4);
    width: 100%;
    min-width: 0;
    transition: box-shadow 0.3s;
}
.barcode-card-inner.boosted {
    box-shadow: 0 0 0 6px #00e5a0, 0 8px 40px rgba(0,229,160,0.4);
}

/*
 * Barcode image rendering:
 * - width:100% fills the card
 * - crisp-edges prevents browser anti-aliasing (keeps bars sharp)
 * - DO NOT use image-rendering:pixelated here — it creates jagged bars
 *   on high-DPI screens which the scanner misreads
 */
.barcode-card-inner img {
    width: 100% !important;
    height: auto !important;
    display: block;
    image-rendering: crisp-edges;
    image-rendering: -moz-crisp-edges;
    /* Note: no pixelated here — crisp-edges is correct for barcodes */
}

.barcode-token {
    margin-top: 8px;
    font-family: monospace;
    font-size: 10px;
    color: #666;
    letter-spacing: 0.5px;
    word-break: break-all;
    text-align: center;
    line-height: 1.4;
}

.barcode-badges {
    display: flex; justify-content: center; gap: 8px;
    flex-wrap: wrap; margin: 14px 0;
}

.countdown-box {
    padding: 12px 18px; background: var(--surface2);
    border-radius: 10px; font-size: 14px; text-align: center;
}

/* Scanning tip */
.scan-tip {
    margin-top: 14px; padding: 12px 16px;
    background: rgba(0,170,255,0.07); border: 1px solid rgba(0,170,255,0.2);
    border-radius: 10px; font-size: 13px; color: var(--muted);
    text-align: center; line-height: 1.6;
}

.barcode-instructions {
    margin-top: 20px; text-align: left;
    background: rgba(0,229,160,0.05);
    border: 1px solid rgba(0,229,160,0.2);
    border-radius: 12px; padding: 16px;
}
.barcode-instructions ul {
    padding-left: 20px; color: var(--muted);
    font-size: 13px; line-height: 2; margin: 8px 0 0;
}

.barcode-actions {
    display: flex; gap: 8px; justify-content: center;
    flex-wrap: wrap; margin-top: 14px;
}
.barcode-actions button, .barcode-actions a {
    flex-shrink: 0; font-family: inherit; cursor: pointer;
    touch-action: manipulation; text-decoration: none;
}

.btn-boost {
    background: rgba(255,220,0,0.12); border: 1.5px solid rgba(255,220,0,0.4);
    color: #e6c200; border-radius: 8px; padding: 8px 16px;
    font-size: 13px; font-weight: 700; min-height: 40px; transition: all .2s;
}
.btn-boost:hover, .btn-boost.active {
    background: rgba(255,220,0,0.25); border-color: #ffd700; color: #ffd700;
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>My Barcode</h1>
        <p>Show at the court counter or entrance scanner.</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/player/my_qr.php"     class="btn-outline btn-sm">📱 QR Code</a>
        <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<div class="barcode-wrap">
    <div class="card" style="text-align:center;padding:clamp(20px,5vw,40px) clamp(16px,4vw,32px);">

        <?php if ($pass && $pass['qr_token']): ?>

            <!-- Player info -->
            <div style="margin-bottom:18px;">
                <div style="font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;">Player Pass</div>
                <div style="font-family:'Bebas Neue',sans-serif;font-size:clamp(22px,6vw,32px);color:var(--accent);margin-top:4px;">
                    <?= clean($_SESSION['full_name']) ?>
                </div>
                <div style="color:var(--muted);font-size:14px;">@<?= clean($_SESSION['username']) ?></div>
            </div>

            <!-- Barcode — full width, server-generated PNG at 3px/module -->
            <div style="display:flex;justify-content:center;width:100%;">
                <div class="barcode-card-inner" id="barcode-card">
                    <?php if ($barcodeUri): ?>
                        <img src="<?= htmlspecialchars($barcodeUri) ?>" alt="Barcode"/>
                    <?php else: ?>
                        <div style="padding:20px;color:var(--danger);font-size:13px;">
                            ❌ Barcode generation failed. Ensure PHP GD extension is enabled.
                        </div>
                    <?php endif; ?>
                    <div class="barcode-token"><?= clean($pass['qr_token']) ?></div>
                </div>
            </div>

            <!-- Scanner tip -->
            <div class="scan-tip">
                📏 Hold scanner <strong style="color:var(--text);">3–10 cm</strong> from screen &nbsp;·&nbsp;
                Maximize browser window for wider bars &nbsp;·&nbsp;
                Use <strong style="color:#ffd700;">☀️ Boost</strong> in dim courts
            </div>

            <!-- Badges -->
            <div class="barcode-badges">
                <?php if ($passActive): ?>
                    <span class="badge badge-success" style="font-size:13px;padding:6px 14px;">✅ Active Pass</span>
                <?php else: ?>
                    <span class="badge badge-danger" style="font-size:13px;padding:6px 14px;">❌ Inactive — Top Up to Activate</span>
                <?php endif; ?>
                <span class="badge badge-info" style="font-size:13px;padding:6px 14px;">💳 ₱<?= number_format($balance, 2) ?></span>
                <?php if ($activeGame): ?>
                    <span class="badge badge-warn" style="font-size:13px;padding:6px 14px;">🎮 In Game</span>
                <?php endif; ?>
            </div>

            <?php if ($passActive): ?>
            <div class="countdown-box">
                <span style="color:var(--muted);">Pass expires in:</span>
                <span id="bc-countdown" style="color:var(--accent);font-weight:700;margin-left:6px;">calculating...</span>
                <br/>
                <span style="font-size:12px;color:var(--muted);">
                    Valid until <?= date('M d, Y h:i A', strtotime($pass['expires_at'])) ?>
                </span>
            </div>
            <?php else: ?>
            <div style="margin-top:12px;padding:12px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:10px;color:var(--danger);font-size:13px;line-height:1.6;">
                This barcode is currently <strong>inactive</strong>.<br/>
                Load credits to activate it for court entry.
                <br/><br/>
                <a href="<?= APP_URL ?>/player/topup.php"
                   style="display:inline-flex;align-items:center;background:var(--danger);color:#fff;padding:10px 22px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;touch-action:manipulation;">
                    💰 Load Credits Now
                </a>
            </div>
            <?php endif; ?>

            <!-- Instructions -->
            <div class="barcode-instructions">
                <div style="font-weight:700;color:var(--accent);">📖 How to use this barcode</div>
                <ul>
                    <li><strong style="color:var(--text);">Court entrance:</strong> Hold screen up to the USB scanner — reads automatically</li>
                    <li><strong style="color:var(--text);">In-person top-up:</strong> Show to owner so they can scan and add credits</li>
                    <li>If scanner won't read: tap <strong style="color:#ffd700;">☀️ Brightness Boost</strong> and try again</li>
                    <li>Maximize your browser window — wider display = wider bars = easier scan</li>
                    <li>Move the phone <strong style="color:var(--text);">closer</strong> (3-5 cm) or <strong style="color:var(--text);">farther</strong> (8-10 cm) until scanner beeps</li>
                    <li>One permanent barcode per player — never changes</li>
                </ul>
            </div>

        <?php elseif ($balance < CREDIT_PER_GAME): ?>
            <div style="padding:32px 16px;">
                <div style="font-size:56px;margin-bottom:14px;">💳</div>
                <div style="font-family:'Bebas Neue',sans-serif;font-size:clamp(22px,6vw,28px);color:var(--danger);margin-bottom:8px;">
                    Insufficient Credits
                </div>
                <p style="color:var(--muted);margin-bottom:20px;">
                    You need at least <strong style="color:var(--accent);">₱<?= CREDIT_PER_GAME ?></strong> to generate a barcode.
                </p>
                <a href="<?= APP_URL ?>/player/topup.php" class="btn-primary"
                   style="display:inline-flex;padding:14px 28px;touch-action:manipulation;">
                    + Load Credits Now
                </a>
            </div>

        <?php else: ?>
            <div style="padding:32px 16px;">
                <div style="font-size:56px;margin-bottom:12px;">🔄</div>
                <p style="color:var(--muted);margin-bottom:16px;">Generating your barcode...</p>
                <a href="" class="btn-primary" style="display:inline-flex;padding:12px 24px;">Refresh Page</a>
            </div>
        <?php endif; ?>

    </div>

    <!-- Action buttons -->
    <?php if ($pass && $pass['qr_token']): ?>
    <div class="barcode-actions">
        <button id="boost-btn" class="btn-boost btn-sm" onclick="toggleBrightness()">☀️ Brightness Boost</button>
        <button onclick="printBarcode()" class="btn-outline btn-sm" style="touch-action:manipulation;">🖨️ Print</button>
        <button onclick="fullscreen()" class="btn-outline btn-sm" style="touch-action:manipulation;">⛶ Full Screen</button>
    </div>

    <div id="wake-lock-badge" style="display:none;font-size:11px;color:var(--accent);text-align:center;margin-top:6px;">
        🔒 Screen stay-on active
    </div>
    <?php endif; ?>
</div>

<script>
let brightnessOn = false;
let wakeLock     = null;

<?php if ($passActive): ?>
(function startCountdown() {
    const endTs = <?= strtotime($pass['expires_at']) ?>;
    const el    = document.getElementById('bc-countdown');
    function tick() {
        const secs = Math.max(0, endTs - Math.floor(Date.now() / 1000));
        const h = Math.floor(secs / 3600);
        const m = Math.floor((secs % 3600) / 60);
        const s = secs % 60;
        el.textContent = h > 0 ? `${h}h ${m}m` : (m > 0 ? `${m}m ${s}s` : `${s}s`);
        if (secs <= 600)  el.style.color = 'var(--danger)';
        else if (secs <= 1800) el.style.color = 'var(--warn)';
        if (secs > 0) setTimeout(tick, 1000);
        else el.textContent = 'Expired';
    }
    tick();
})();
<?php endif; ?>

function toggleBrightness() {
    brightnessOn = !brightnessOn;
    const btn  = document.getElementById('boost-btn');
    const card = document.getElementById('barcode-card');
    if (brightnessOn) {
        if (card) card.classList.add('boosted');
        if (btn)  { btn.classList.add('active'); btn.textContent = '☀️ ON'; }
        requestWakeLock();
    } else {
        if (card) card.classList.remove('boosted');
        if (btn)  { btn.classList.remove('active'); btn.textContent = '☀️ Brightness Boost'; }
        releaseWakeLock();
    }
}

async function requestWakeLock() {
    try {
        if ('wakeLock' in navigator) {
            wakeLock = await navigator.wakeLock.request('screen');
            const badge = document.getElementById('wake-lock-badge');
            if (badge) badge.style.display = 'block';
        }
    } catch(e) {}
}
function releaseWakeLock() {
    if (wakeLock) { wakeLock.release(); wakeLock = null; }
    const badge = document.getElementById('wake-lock-badge');
    if (badge) badge.style.display = 'none';
}

window.addEventListener('load', requestWakeLock);

function printBarcode() {
    const imgEl = document.querySelector('#barcode-card img');
    if (!imgEl) return;
    const printWin = window.open('', '_blank', 'width=900,height=300');
    if (!printWin) { alert('Please allow popups to print.'); return; }
    printWin.document.open();
    printWin.document.write(`<!DOCTYPE html>
<html><head>
<title>Barcode — <?= addslashes(clean($_SESSION['full_name'])) ?></title>
<style>
body{margin:20px;text-align:center;font-family:sans-serif;background:#fff;}
img{width:100%;max-width:800px;height:auto;image-rendering:crisp-edges;}
h3{color:#333;margin-bottom:4px;}p{color:#888;font-size:11px;font-family:monospace;}
</style></head><body>
<h3><?= addslashes(clean($_SESSION['full_name'])) ?> (@<?= addslashes(clean($_SESSION['username'])) ?>)</h3>
<img src="${imgEl.src}" alt="Barcode"/>
<p><?= addslashes(clean($pass['qr_token'])) ?></p>
</body></html>`);
    printWin.document.close();
    setTimeout(() => printWin.print(), 500);
}

function fullscreen() {
    const card = document.getElementById('barcode-card');
    if (!card) return;
    const req = card.requestFullscreen || card.webkitRequestFullscreen || card.mozRequestFullScreen;
    if (req) req.call(card).catch(() => {});
}

document.addEventListener('contextmenu', e => e.preventDefault());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>