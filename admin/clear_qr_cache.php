<?php
// ============================================================
//  FILE: admin/clear_qr_cache.php
//
//  Deletes all cached QR PNG and barcode PNG files from /tmp
//  so the next page load regenerates them at the correct size.
//
//  Access: admin only
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/db.php';
requireAdmin();

// ── Only perform the wipe on a confirmed POST request ────────
$cleared = false;
$deleted = 0;
$failed  = 0;
$qrCount = 0;
$bcCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $tmpDir       = sys_get_temp_dir();
    $qrFiles      = glob($tmpDir . '/pqr_*.dat') ?: [];
    $barcodeFiles = glob($tmpDir . '/pbc_*.dat') ?: [];
    $allFiles     = array_merge($qrFiles, $barcodeFiles);
    $qrCount      = count($qrFiles);
    $bcCount      = count($barcodeFiles);

    foreach ($allFiles as $f) {
        if (@unlink($f)) $deleted++;
        else $failed++;
    }

    $cleared = true;
    setFlash('success', "Cache cleared — {$deleted} file(s) deleted.");
}

// ── Preview counts for the confirmation screen ───────────────
$tmpDir       = sys_get_temp_dir();
$qrFiles      = glob($tmpDir . '/pqr_*.dat') ?: [];
$barcodeFiles = glob($tmpDir . '/pbc_*.dat') ?: [];
$previewQr    = count($qrFiles);
$previewBc    = count($barcodeFiles);
$previewTotal = $previewQr + $previewBc;

$pageTitle = 'Clear QR Cache';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Clear QR Cache page ───────────────────────────────────── */
.cqc-wrap {
    max-width: 600px;
    margin: 0 auto;
}

.cqc-hero {
    text-align: center;
    padding: 40px 24px 32px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    margin-bottom: 20px;
}
.cqc-icon {
    font-size: clamp(52px, 10vw, 80px);
    line-height: 1;
    margin-bottom: 16px;
    display: block;
}
.cqc-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(28px, 5vw, 42px);
    letter-spacing: 2px;
    margin-bottom: 8px;
}
.cqc-sub {
    font-size: 14px;
    color: var(--muted);
    line-height: 1.7;
    max-width: 440px;
    margin: 0 auto 24px;
}

/* ── Stat pills ─────────────────────────────────────────────── */
.cqc-stats {
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
.cqc-stat {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 22px;
    text-align: center;
    min-width: 110px;
}
.cqc-stat-val {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 34px;
    line-height: 1;
    color: var(--accent);
}
.cqc-stat-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    margin-top: 3px;
}

/* ── Warning callout ─────────────────────────────────────────── */
.cqc-warn {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.3);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 13px;
    color: #fcd34d;
    line-height: 1.6;
}
.cqc-warn .warn-icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }

/* ── Actions ─────────────────────────────────────────────────── */
.cqc-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}
.cqc-actions a,
.cqc-actions button {
    min-width: 160px;
    text-align: center;
    padding: 12px 24px;
    font-size: 14px;
}

/* ── Success state ───────────────────────────────────────────── */
.cqc-success-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(16,185,129,.12);
    border: 2px solid rgba(16,185,129,.35);
    font-size: 40px;
    margin-bottom: 16px;
}

/* ── Result rows ─────────────────────────────────────────────── */
.cqc-result-rows {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 24px;
}
.cqc-result-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 13px;
}
.cqc-result-row .rr-label { color: var(--muted); }
.cqc-result-row .rr-val   { font-weight: 700; font-family: 'JetBrains Mono', monospace; }
.cqc-result-row.success .rr-val { color: var(--success); }
.cqc-result-row.warn    .rr-val { color: var(--warn); }

/* ── Info card ───────────────────────────────────────────────── */
.cqc-info-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px 22px;
}
.cqc-info-title {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    margin-bottom: 12px;
}
.cqc-info-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.cqc-info-list li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
    color: var(--muted);
    line-height: 1.6;
}
.cqc-info-list li .li-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent);
    margin-top: 7px;
    flex-shrink: 0;
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Clear QR Cache</h1>
        <p>Flush cached QR codes and barcodes from the server temp directory.</p>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="<?= APP_URL ?>/court/scanner_test.php" class="btn-outline btn-sm">🔌 Scanner Test</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php"    class="btn-outline btn-sm">← Dashboard</a>
    </div>
</div>

<div class="cqc-wrap">

    <?php if ($cleared): ?>
    <!-- ── SUCCESS STATE ── -->
    <div class="cqc-hero">
        <div class="cqc-success-icon">✅</div>
        <div class="cqc-title" style="color:var(--success);">Cache Cleared!</div>
        <p class="cqc-sub">
            Old QR codes and barcodes have been removed. Fresh files will be generated
            automatically the next time a player views their pass.
        </p>

        <div class="cqc-result-rows" style="text-align:left;">
            <div class="cqc-result-row success">
                <span class="rr-label">🗑 Files deleted</span>
                <span class="rr-val"><?= $deleted ?></span>
            </div>
            <div class="cqc-result-row">
                <span class="rr-label">📱 QR cache files</span>
                <span class="rr-val"><?= $qrCount ?></span>
            </div>
            <div class="cqc-result-row">
                <span class="rr-label">📊 Barcode cache files</span>
                <span class="rr-val"><?= $bcCount ?></span>
            </div>
            <?php if ($failed > 0): ?>
            <div class="cqc-result-row warn">
                <span class="rr-label">⚠️ Could not delete (check /tmp permissions)</span>
                <span class="rr-val"><?= $failed ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="cqc-actions">
            <a href="<?= APP_URL ?>/court/scanner_test.php" class="btn-primary">🔌 Test Scanner Now</a>
            <a href="<?= APP_URL ?>/admin/clear_qr_cache.php" class="btn-outline">🔄 Clear Again</a>
            <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline">← Dashboard</a>
        </div>
    </div>

    <?php else: ?>
    <!-- ── CONFIRMATION STATE ── -->
    <div class="cqc-hero">
        <span class="cqc-icon">📱</span>
        <div class="cqc-title">QR Cache Manager</div>
        <p class="cqc-sub">
            Run this after updating <code style="background:rgba(0,229,160,.1);color:var(--accent);padding:2px 6px;border-radius:4px;font-size:12px;">includes/qr.php</code>
            or <code style="background:rgba(0,229,160,.1);color:var(--accent);padding:2px 6px;border-radius:4px;font-size:12px;">includes/barcode.php</code>
            to force all players to receive freshly generated codes at the correct size.
        </p>

        <!-- Current cache stats -->
        <div class="cqc-stats">
            <div class="cqc-stat">
                <div class="cqc-stat-val" style="color:<?= $previewTotal > 0 ? 'var(--warn)' : 'var(--muted)' ?>;">
                    <?= $previewTotal ?>
                </div>
                <div class="cqc-stat-label">Total Cached</div>
            </div>
            <div class="cqc-stat">
                <div class="cqc-stat-val"><?= $previewQr ?></div>
                <div class="cqc-stat-label">QR Files</div>
            </div>
            <div class="cqc-stat">
                <div class="cqc-stat-val"><?= $previewBc ?></div>
                <div class="cqc-stat-label">Barcode Files</div>
            </div>
        </div>

        <?php if ($previewTotal > 0): ?>
        <div class="cqc-warn" style="text-align:left;">
            <span class="warn-icon">⚠️</span>
            <div>
                <strong><?= $previewTotal ?> cached file<?= $previewTotal !== 1 ? 's' : '' ?> found.</strong>
                Clearing the cache means every player's QR code will be re-rendered on their next visit —
                this is instant but may add a brief delay on their first scan after clearing.
            </div>
        </div>
        <?php else: ?>
        <div style="background:rgba(0,229,160,.07);border:1px solid rgba(0,229,160,.2);border-radius:12px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:var(--accent);text-align:left;">
            ✅ Cache is already empty — no files to delete.
        </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <div class="cqc-actions">
                <button type="submit" class="btn-<?= $previewTotal > 0 ? 'danger' : 'outline' ?>"
                        <?= $previewTotal === 0 ? 'disabled' : '' ?>
                        onclick="return confirm('Delete all <?= $previewTotal ?> cached QR / barcode file(s)? Players will see freshly generated codes on their next visit.')">
                    🗑 Clear <?= $previewTotal ?> File<?= $previewTotal !== 1 ? 's' : '' ?>
                </button>
                <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline">← Dashboard</a>
            </div>
        </form>
    </div>

    <!-- Info card -->
    <div class="cqc-info-card">
        <div class="cqc-info-title">ℹ️ How the cache works</div>
        <ul class="cqc-info-list">
            <li><span class="li-dot"></span> QR codes and barcodes are generated once and cached in <code>/tmp</code> as <code>.dat</code> files to avoid regenerating them on every page load.</li>
            <li><span class="li-dot"></span> Cache files are keyed by player ID and expiry date, so they auto-rotate when a pass is renewed.</li>
            <li><span class="li-dot"></span> Only clear the cache if you have updated the QR/barcode libraries or changed the image size settings in <code>qr.php</code> or <code>barcode.php</code>.</li>
            <li><span class="li-dot"></span> After clearing, the first QR scan per player will take a fraction of a second longer — subsequent scans will be instant again.</li>
        </ul>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>