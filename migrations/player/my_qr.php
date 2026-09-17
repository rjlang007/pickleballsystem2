<?php
// ============================================================
//  FILE: player/my_qr.php  (FIXED — v2)
//
//  FIXES vs v1:
//
//  1. CRITICAL: $isActive now uses LIVE wallet balance, not the
//     stale player_passes.is_active DB column. A player with
//     ₱1,100 will always see their QR as ACTIVE and will never
//     see the "Load Credits to Activate" overlay blocking their
//     QR code, even if the DB column was never synced.
//
//  2. The ON CONFLICT for the player_passes UPSERT now always
//     writes the correct is_active value derived from balance,
//     keeping the DB in sync for admin views.
//
//  3. clean() guard added — falls back to htmlspecialchars if
//     the helper is not available via included files.
//
//  All v1 features preserved:
//   - Permanent QR token (never changes)
//   - Wake lock, brightness boost, barcode shortcut
//   - Activation polling (only active when balance = 0)
//   - Pass timer countdown
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../config/security.php';

requireLogin();

$db  = getDB();
$uid = $_SESSION['user_id'];

// ── Live wallet balance ───────────────────────────────────────
$walletStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$walletStmt->execute([$uid]);
$balance = (float)($walletStmt->fetchColumn() ?? 0);

// ── Ensure player has a permanent qr_token ────────────────────
// FIX: is_active in the UPSERT always reflects live balance.
//      ON CONFLICT now writes is_active unconditionally so the
//      DB column stays in sync after every page load.
$token = bin2hex(random_bytes(24));
$db->prepare("
    INSERT INTO falcon.player_passes (user_id, qr_token, expires_at, is_active)
    VALUES (?, ?, '2099-12-31 23:59:59+00', ?::boolean)
    ON CONFLICT (user_id) DO UPDATE
        SET qr_token  = COALESCE(falcon.player_passes.qr_token, EXCLUDED.qr_token),
            is_active = EXCLUDED.is_active
")->execute([$uid, $token, $balance > 0 ? 'true' : 'false']);

$passStmt = $db->prepare("SELECT qr_token, is_active, expires_at FROM falcon.player_passes WHERE user_id = ? LIMIT 1");
$passStmt->execute([$uid]);
$pass = $passStmt->fetch();

// FIX #1: Use live balance for gate decision, not DB column.
// The DB column may be stale. Balance is always fresh.
$isActive      = ($balance > 0);
$token         = $pass['qr_token'] ?? '';
$expiresAt     = $pass['expires_at'] ?? '2099-12-31 23:59:59';
$isPlaceholder = strtotime($expiresAt) > strtotime('+50 years');
$secsRemaining = $isPlaceholder ? 0 : max(0, strtotime($expiresAt) - time());

// Pre-generate QR data URI server-side
$qrDataUri = PlayerQR::dataUri($token);

// Fallback for clean() helper if not available via app.php / header.php
if (!function_exists('clean')) {
    function clean(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$pageTitle = 'My QR Code';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no"/>
    <title>My QR Code — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css"/>
    <style>
    *, *::before, *::after { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--bg);
        display: flex;
        flex-direction: column;
        align-items: center;
        min-height: 100vh;
        min-height: 100dvh;
        padding: max(12px, env(safe-area-inset-top))
                 max(12px, env(safe-area-inset-right))
                 max(12px, env(safe-area-inset-bottom))
                 max(12px, env(safe-area-inset-left));
        transition: background 0.3s;
    }
    body.brightness-boost { background: #ffffff; }

    /* ── Top nav ── */
    .qr-nav {
        width: 100%; max-width: 400px;
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 12px; gap: 8px;
    }
    .qr-nav a {
        color: var(--muted); text-decoration: none; font-size: 14px;
        min-height: 44px; display: flex; align-items: center;
        padding: 0 4px; transition: color .2s; touch-action: manipulation;
    }
    .qr-nav a:hover { color: var(--accent); }
    .qr-nav a:last-child { color: var(--accent); font-weight: 600; }
    .qr-nav-title { font-weight: 700; color: var(--text); font-size: 15px; }

    /* ── QR card ── */
    .qr-wrapper {
        background: #fff;
        border-radius: 20px;
        padding: 20px 16px 14px;
        display: flex;
        flex-direction: column;
        align-items: center;
        box-shadow: 0 8px 48px rgba(0,0,0,0.5);
        width: min(360px, calc(100vw - 24px));
        transition: box-shadow 0.3s, width 0.3s;
        position: relative;
    }
    body.brightness-boost .qr-wrapper {
        width: min(420px, calc(100vw - 16px));
        box-shadow: 0 0 0 8px #00e5a0, 0 12px 60px rgba(0,229,160,0.4);
    }
    .qr-wrapper img.qr-img {
        width: 100%;
        max-width: 340px;
        height: auto;
        display: block;
    }
    body.brightness-boost .qr-wrapper img.qr-img { max-width: 400px; }

    /* Inactive overlay — only shown when balance is truly 0 */
    .qr-inactive-overlay {
        position: absolute; inset: 0; border-radius: 20px;
        background: rgba(255,255,255,0.75);
        display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 8px; z-index: 2;
    }
    .qr-inactive-stamp {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 42px; color: rgba(239,68,68,0.75);
        letter-spacing: 4px; transform: rotate(-20deg);
        border: 4px solid rgba(239,68,68,0.5);
        padding: 4px 12px; border-radius: 6px; white-space: nowrap;
    }

    /* Status bar */
    .status-bar {
        width: 100%; padding: 10px 0; text-align: center;
        border-radius: 10px; margin-top: 12px;
        font-weight: 700; font-size: 14px; letter-spacing: 0.3px;
    }
    .status-active   { background: rgba(0,229,160,0.15); color: var(--accent); border: 2px solid rgba(0,229,160,0.5); }
    .status-inactive { background: rgba(239,68,68,0.12); color: var(--danger); border: 2px solid rgba(239,68,68,0.4); }

    .token-text {
        font-family: monospace; font-size: 9px; color: #aaa;
        margin-top: 8px; word-break: break-all; text-align: center; line-height: 1.5;
    }

    /* ── Action buttons ── */
    .qr-actions {
        display: flex; gap: 8px; margin-top: 12px;
        width: min(360px, calc(100vw - 24px)); flex-wrap: wrap;
    }
    .qr-actions a, .qr-actions button {
        flex: 1; min-width: 100px; text-align: center;
        touch-action: manipulation; font-family: inherit; cursor: pointer;
        min-height: 44px; display: inline-flex; align-items: center;
        justify-content: center; gap: 5px; font-size: 13px; font-weight: 700;
        border-radius: 10px; text-decoration: none; transition: all .2s;
    }
    .btn-boost {
        background: rgba(255,220,0,0.15); border: 1.5px solid rgba(255,220,0,0.5); color: #ffd700;
    }
    .btn-boost:hover, .btn-boost.active {
        background: rgba(255,220,0,0.3); border-color: #ffd700;
    }
    .btn-boost.active { animation: boostPulse 2s ease-in-out infinite; }
    @keyframes boostPulse {
        0%,100%{box-shadow:0 0 0 0 rgba(255,220,0,0)} 50%{box-shadow:0 0 12px 3px rgba(255,220,0,0.35)}
    }
    .btn-barcode {
        background: var(--surface2); border: 1.5px solid var(--border); color: var(--muted);
    }
    .btn-barcode:hover { border-color: var(--accent); color: var(--accent); }

    /* ── Info card ── */
    .info-card {
        background: var(--surface); border-radius: 14px; padding: 16px;
        margin-top: 12px; width: min(360px, calc(100vw - 24px));
        border: 1px solid var(--border);
    }
    .balance-big   { font-family: 'Bebas Neue', sans-serif; font-size: clamp(30px,10vw,40px); color: var(--accent); text-align: center; }
    .balance-label { text-align: center; color: var(--muted); font-size: 13px; margin-top: -4px; margin-bottom: 10px; }

    .pass-timer { text-align: center; padding: 10px; background: rgba(0,229,160,0.06); border: 1px solid rgba(0,229,160,0.18); border-radius: 10px; margin-bottom: 10px; }
    .pass-timer-val { font-family: 'Bebas Neue', sans-serif; font-size: 26px; color: var(--accent); letter-spacing: 1px; line-height: 1; }
    .pass-timer-val.warn   { color: var(--warn); }
    .pass-timer-val.danger { color: var(--danger); }
    .pass-timer-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }

    .polling-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; color: var(--muted); margin-top: 6px; }
    .polling-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--warn); animation: pollingBlink 1.5s ease-in-out infinite; }
    @keyframes pollingBlink { 0%,100%{opacity:1} 50%{opacity:.2} }

    .wake-lock-badge { display: none; font-size: 10px; color: var(--accent); margin-top: 4px; gap: 4px; align-items: center; }
    .wake-lock-badge.active { display: inline-flex; }

    /* ── Scanner tip ── */
    .scanner-tip {
        margin-top: 12px; padding: 10px 14px;
        background: rgba(0,170,255,0.07); border: 1px solid rgba(0,170,255,0.2);
        border-radius: 10px; font-size: 12px; color: var(--muted);
        text-align: center; line-height: 1.6;
        width: min(360px, calc(100vw - 24px));
    }
    </style>
</head>
<body>

    <nav class="qr-nav">
        <a href="<?= APP_URL ?>/player/dashboard.php">← Dashboard</a>
        <span class="qr-nav-title">🏓 My QR Code</span>
        <a href="<?= APP_URL ?>/player/topup.php">+ Load</a>
    </nav>

    <!-- QR Card -->
    <div class="qr-wrapper" id="qr-wrapper">

        <?php
        // FIX: Overlay ONLY appears when balance is truly zero.
        // Previously this used $pass['is_active'] which could be stale
        // in the DB, blocking players who had plenty of credits.
        if (!$isActive):
        ?>
        <div class="qr-inactive-overlay" id="inactive-overlay">
            <div class="qr-inactive-stamp">INACTIVE</div>
            <a href="<?= APP_URL ?>/player/topup.php"
               style="background:var(--danger);color:#fff;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;border:none;display:inline-flex;align-items:center;gap:6px;touch-action:manipulation;">
                💰 Load Credits to Activate
            </a>
        </div>
        <?php endif; ?>

        <?php
        echo '<img src="' . htmlspecialchars($qrDataUri) . '" '
           . 'width="340" height="340" '
           . 'alt="QR Code" class="qr-img" />';
        ?>

        <div class="status-bar <?= $isActive ? 'status-active' : 'status-inactive' ?>" id="status-bar">
            <?= $isActive ? '✅ ACTIVE — Ready to Scan' : '❌ INACTIVE — Load Credits to Activate' ?>
        </div>

        <div class="token-text"><?= chunk_split(clean($token), 12, ' ') ?></div>
    </div>

    <!-- Scanner Tip -->
    <div class="scanner-tip">
        📏 Hold scanner <strong style="color:var(--text);">5–15 cm</strong> from screen &nbsp;·&nbsp;
        Tap <strong style="color:#ffd700;">☀️ Boost</strong> in dim light &nbsp;·&nbsp;
        Try <strong style="color:var(--text);">📊 Barcode</strong> if QR won't scan
    </div>

    <!-- Action Buttons -->
    <div class="qr-actions">
        <button id="brightness-btn" class="btn-boost" onclick="toggleBrightness()">☀️ Boost</button>
        <a href="<?= APP_URL ?>/player/my_barcode.php" class="btn-barcode">📊 Barcode</a>
    </div>

    <div class="wake-lock-badge" id="wake-lock-badge">
        <span style="width:5px;height:5px;border-radius:50%;background:var(--accent);display:inline-block;"></span>
        Screen stay-on active
    </div>

    <!-- Balance & Info -->
    <div class="info-card">
        <div class="balance-big">₱<?= number_format($balance, 2) ?></div>
        <div class="balance-label">Credit Balance</div>

        <?php if ($isActive && !$isPlaceholder && $secsRemaining > 0): ?>
        <div class="pass-timer">
            <div class="pass-timer-val <?= $secsRemaining < 3600 ? ($secsRemaining < 1800 ? 'danger' : 'warn') : '' ?>" id="pass-timer">
                <?php
                $h = floor($secsRemaining / 3600);
                $m = floor(($secsRemaining % 3600) / 60);
                echo $h > 0 ? "{$h}h {$m}m" : "{$m}m " . ($secsRemaining % 60) . "s";
                ?>
            </div>
            <div class="pass-timer-label">Pass Time Remaining</div>
        </div>
        <?php endif; ?>

        <?php if ($isActive): ?>
            <div style="text-align:center;font-size:13px;color:var(--muted);line-height:1.7;
                        padding:12px;background:rgba(0,229,160,0.06);border-radius:10px;
                        border:1px solid rgba(0,229,160,0.15);margin-bottom:10px;">
                Show this QR code at the<br/>
                <strong style="color:var(--accent);">court entrance scanner</strong><br/>
                to join the queue. ₱<?= number_format($balance, 2) ?> available.
            </div>
            <a href="<?= APP_URL ?>/player/topup.php"
               style="display:flex;align-items:center;justify-content:center;
                      background:var(--surface2);color:var(--muted);border:1px solid var(--border);
                      border-radius:10px;text-align:center;padding:11px;font-size:14px;
                      font-weight:700;text-decoration:none;touch-action:manipulation;min-height:44px;">
                💳 Add More Credits
            </a>
        <?php else: ?>
            <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);
                        border-radius:10px;padding:14px;text-align:center;font-size:13px;
                        color:var(--danger);line-height:1.6;margin-bottom:10px;">
                Your QR code is <strong>inactive</strong>.<br/>
                Load credits to re-activate it and start playing.
            </div>
            <a href="<?= APP_URL ?>/player/topup.php"
               style="display:flex;align-items:center;justify-content:center;background:var(--accent);
                      color:#000;border-radius:10px;padding:14px;font-size:15px;font-weight:700;
                      text-decoration:none;touch-action:manipulation;min-height:48px;">
                💰 Load Credits Now
            </a>
            <div class="polling-badge" id="polling-badge">
                <div class="polling-dot"></div>
                Checking for activation…
            </div>
        <?php endif; ?>
    </div>

<script>
const APP_URL       = '<?= APP_URL ?>';
const IS_ACTIVE     = <?= $isActive ? 'true' : 'false' ?>;
let brightnessOn    = false;
let wakeLock        = null;
let passTimerSecs   = <?= (int)$secsRemaining ?>;
let pollInterval    = null;

// ── Screen Wake Lock ─────────────────────────────────────────
async function requestWakeLock() {
    try {
        if ('wakeLock' in navigator) {
            wakeLock = await navigator.wakeLock.request('screen');
            document.getElementById('wake-lock-badge').classList.add('active');
            wakeLock.addEventListener('release', () => {
                document.getElementById('wake-lock-badge').classList.remove('active');
            });
        }
    } catch(e) {}
}
function releaseWakeLock() {
    if (wakeLock) { wakeLock.release(); wakeLock = null; }
    document.getElementById('wake-lock-badge').classList.remove('active');
}
window.addEventListener('load', requestWakeLock);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && brightnessOn) requestWakeLock();
    else if (document.hidden) releaseWakeLock();
});

// ── Brightness Boost ─────────────────────────────────────────
function toggleBrightness() {
    brightnessOn = !brightnessOn;
    const btn  = document.getElementById('brightness-btn');
    document.body.classList.toggle('brightness-boost', brightnessOn);
    if (brightnessOn) {
        btn.classList.add('active');
        btn.textContent = '☀️ ON';
        requestWakeLock();
    } else {
        btn.classList.remove('active');
        btn.textContent = '☀️ Boost';
        releaseWakeLock();
    }
}

// ── Pass Timer ───────────────────────────────────────────────
const timerEl = document.getElementById('pass-timer');
if (timerEl && passTimerSecs > 0) {
    function tickPassTimer() {
        if (passTimerSecs <= 0) { timerEl.textContent = 'Expired'; return; }
        passTimerSecs--;
        const h = Math.floor(passTimerSecs / 3600);
        const m = Math.floor((passTimerSecs % 3600) / 60);
        const s = passTimerSecs % 60;
        timerEl.textContent = h > 0 ? `${h}h ${m}m` : `${m}m ${s}s`;
        if (passTimerSecs < 1800) timerEl.className = 'pass-timer-val warn';
        if (passTimerSecs < 600)  timerEl.className = 'pass-timer-val danger';
        setTimeout(tickPassTimer, 1000);
    }
    setTimeout(tickPassTimer, 1000);
}

// ── Activation Polling ───────────────────────────────────────
// Only runs when balance is 0 (truly inactive).
if (!IS_ACTIVE) {
    pollInterval = setInterval(() => {
        fetch(`${APP_URL}/api/pass_status.php`, { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (data && data.is_active) {
                    clearInterval(pollInterval);
                    document.getElementById('status-bar').className  = 'status-bar status-active';
                    document.getElementById('status-bar').textContent = '✅ ACTIVE — Ready to Scan';
                    const overlay = document.getElementById('inactive-overlay');
                    if (overlay) overlay.remove();
                    const badge = document.getElementById('polling-badge');
                    if (badge) badge.innerHTML = '<span style="color:var(--success)">✅ Activated! You\'re ready to play.</span>';
                }
            })
            .catch(() => {});
    }, 5000);
}

document.addEventListener('contextmenu', e => e.preventDefault());
</script>

</body>
</html>