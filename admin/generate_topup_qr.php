<?php
// ============================================================
//  FILE: admin/generate_topup_qr.php
//  Admin — In-person top-up via QR scan or player search
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

// ── AJAX: player search ──────────────────────────────────────
if (isset($_GET['search_player'])) {
    header('Content-Type: application/json');
    $q   = '%' . trim($_GET['q'] ?? '') . '%';
    $res = $db->prepare("
        SELECT u.id, u.username, u.full_name, COALESCE(w.balance,0) AS balance,
               pp.is_active AS qr_active,
               (SELECT COUNT(*) FROM falcon.topup_requests tr
                WHERE tr.user_id=u.id AND tr.status='pending') AS pending_requests
        FROM falcon.users u
        LEFT JOIN falcon.wallets w ON w.user_id = u.id
        LEFT JOIN falcon.player_passes pp ON pp.user_id = u.id
        WHERE u.role = 'player'
          AND (u.username ILIKE ? OR u.full_name ILIKE ? OR u.phone ILIKE ?)
        ORDER BY u.username LIMIT 10
    ");
    $res->execute([$q, $q, $q]);
    echo json_encode($res->fetchAll());
    exit;
}

// ── AJAX: lookup player by QR token ─────────────────────────
if (isset($_GET['lookup_token'])) {
    header('Content-Type: application/json');
    $token = trim($_GET['token'] ?? '');
    if (!$token) { echo json_encode(['error' => 'No token']); exit; }
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.full_name,
               COALESCE(w.balance,0) AS balance,
               pp.is_active AS qr_active,
               (SELECT MAX(t.created_at) FROM falcon.transactions t
                WHERE t.user_id=u.id AND t.type='topup') AS last_topup
        FROM falcon.player_passes pp
        JOIN falcon.users u ON u.id = pp.user_id
        LEFT JOIN falcon.wallets w ON w.user_id = pp.user_id
        WHERE pp.qr_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $player = $stmt->fetch();
    echo json_encode($player ?: ['error' => 'Player not found.']);
    exit;
}

// ── POST: add credits ────────────────────────────────────────
$credited = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $playerId = filter_input(INPUT_POST, 'player_id', FILTER_VALIDATE_INT);
    $amount   = filter_input(INPUT_POST, 'amount',    FILTER_VALIDATE_FLOAT);

    if (!$playerId || !$amount || $amount < 1) {
        setFlash('error', 'Select a player and enter a valid amount.');
        redirect('admin/generate_topup_qr.php');
    }

    $pStmt = $db->prepare("
        SELECT u.id, u.username, u.full_name, COALESCE(w.balance,0) AS balance
        FROM falcon.users u
        LEFT JOIN falcon.wallets w ON w.user_id = u.id
        WHERE u.id = ? AND u.role = 'player'
    ");
    $pStmt->execute([$playerId]);
    $player = $pStmt->fetch();

    if (!$player) {
        setFlash('error', 'Player not found.');
        redirect('admin/generate_topup_qr.php');
    }

    try {
        $db->beginTransaction();

        $balanceBefore = (float)$player['balance'];
        $balanceAfter  = $balanceBefore + (float)$amount;

        // Upsert wallet
        $db->prepare("
            INSERT INTO falcon.wallets (user_id, balance)
            VALUES (?, ?)
            ON CONFLICT (user_id) DO UPDATE
                SET balance    = falcon.wallets.balance + EXCLUDED.balance,
                    updated_at = NOW()
        ")->execute([$playerId, $amount]);

        // Record transaction
        $db->prepare("
            INSERT INTO falcon.transactions
                (user_id, type, method, amount, note, status,
                 balance_before, balance_after, reference_no, processed_by)
            VALUES (?, 'topup', 'admin', ?, ?, 'approved', ?, ?, ?, ?)
        ")->execute([
            $playerId,
            $amount,
            'In-person top-up by admin @' . $_SESSION['username'],
            $balanceBefore,
            $balanceAfter,
            'INPERSON-' . $playerId . '-' . time(),
            $_SESSION['user_id'],
        ]);

        // ── FIXED: single ON CONFLICT, use table DEFAULT for qr_token ──
        $db->prepare("
            INSERT INTO falcon.player_passes (user_id, expires_at, is_active)
            VALUES (?, '2099-12-31 23:59:59+00', TRUE)
            ON CONFLICT (user_id) DO UPDATE
                SET is_active  = TRUE,
                    expires_at = CASE
                        WHEN falcon.player_passes.expires_at < NOW()
                        THEN '2099-12-31 23:59:59+00'::timestamptz
                        ELSE falcon.player_passes.expires_at
                    END
        ")->execute([$playerId]);

        // Notify player
        $db->prepare("
            INSERT INTO falcon.notifications (user_id, title, message, type)
            VALUES (?, ?, ?, 'success')
        ")->execute([
            $playerId,
            '₱' . number_format($amount, 2) . ' Credits Added 💰',
            "₱{$amount} added by the court owner. Your QR code is now active — go play!",
        ]);

        $db->commit();

        $newBal = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
        $newBal->execute([$playerId]);
        $credited = [
            'player'      => $player,
            'amount'      => $amount,
            'new_balance' => $newBal->fetchColumn(),
        ];

    } catch (PDOException $e) {
        $db->rollBack();
        error_log('in-person topup error: ' . $e->getMessage());
        setFlash('error', IS_PRODUCTION
            ? 'Something went wrong while processing this top-up. Please try again.'
            : 'Database error: ' . $e->getMessage());
        redirect('admin/generate_topup_qr.php');
    }
}

$recentTopups = $db->query("
    SELECT t.id, t.amount, t.created_at, u.username, u.full_name,
           t.balance_before, t.balance_after
    FROM falcon.transactions t
    JOIN falcon.users u ON u.id = t.user_id
    WHERE t.type = 'topup' AND t.method = 'admin' AND t.note ILIKE '%in-person%'
    ORDER BY t.created_at DESC LIMIT 15
")->fetchAll();

$todayTotal = $db->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM falcon.transactions
    WHERE type = 'topup' AND method = 'admin'
      AND note ILIKE '%in-person%' AND created_at >= CURRENT_DATE
")->fetchColumn();

$todayCount = $db->query("
    SELECT COUNT(*)
    FROM falcon.transactions
    WHERE type = 'topup' AND method = 'admin'
      AND note ILIKE '%in-person%' AND created_at >= CURRENT_DATE
")->fetchColumn();

$pageTitle = 'In-Person Top-Up';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.scan-ring {
    border: 3px solid var(--accent);
    border-radius: 12px;
    animation: scanPulse 2s ease infinite;
}
@keyframes scanPulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(0,229,160,0); }
    50%      { box-shadow: 0 0 0 8px rgba(0,229,160,0.15); }
}
.amt-preset {
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--muted);
    border-radius: 8px;
    padding: 10px 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s;
    width: 100%;
    min-height: 42px;
    touch-action: manipulation;
    font-family: 'DM Sans', sans-serif;
}
.amt-preset:hover, .amt-preset.active {
    background: rgba(0,229,160,0.12);
    border-color: var(--accent);
    color: var(--accent);
}
.topup-layout {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
    margin-bottom: 32px;
}
.today-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.amt-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}
/* ── Label / input pairing ── */
.form-group { margin-bottom: 18px; }
.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 6px;
}
.form-group input[type="text"],
.form-group input[type="number"] {
    width: 100%;
}

@media (max-width: 900px) {
    .topup-layout { grid-template-columns: 1fr; }
    .topup-layout > .topup-sidebar { order: 2; }
    .topup-layout > .topup-form   { order: 1; }
}
@media (max-width: 480px) {
    .amt-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>In-Person Top-Up</h1>
        <p>Scan QR or search by name — add credits instantly.</p>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="<?= APP_URL ?>/admin/topup_requests.php" class="btn-outline btn-sm">📋 GCash Requests
            <?php
            $pCount = $db->query("SELECT COUNT(*) FROM falcon.topup_requests WHERE status='pending'")->fetchColumn();
            if ($pCount > 0): ?>
                <span style="background:var(--warn);color:#000;border-radius:20px;padding:1px 7px;font-size:11px;margin-left:4px;"><?= $pCount ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php if ($credited): ?>
<div style="background:rgba(16,185,129,0.12);border:2px solid var(--success);border-radius:16px;
            padding:20px 24px;margin-bottom:28px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
    <div style="font-size:48px;flex-shrink:0;">✅</div>
    <div style="flex:1;min-width:200px;">
        <div style="font-family:'Bebas Neue',sans-serif;font-size:24px;color:var(--success);">
            ₱<?= number_format($credited['amount'],2) ?> Added — QR Activated
        </div>
        <div style="font-size:14px;margin-top:4px;">
            Credited to <strong><?= clean($credited['player']['full_name']) ?></strong>
            <span style="color:var(--muted);font-size:12px;">@<?= clean($credited['player']['username']) ?></span>
        </div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px;">
            New balance: <strong style="color:var(--accent);">₱<?= number_format($credited['new_balance'],2) ?></strong>
            &nbsp;·&nbsp; QR: <span style="color:var(--success);font-weight:700;">ACTIVE</span>
        </div>
    </div>
    <a href="<?= APP_URL ?>/admin/generate_topup_qr.php" class="btn-outline btn-sm" style="flex-shrink:0;">+ Next Player</a>
</div>
<?php endif; ?>

<div class="topup-layout">

    <!-- Left: Form -->
    <div class="topup-form card">
        <div class="card-title">⚡ Add Credits</div>
        <div class="card-subtitle">Focus the scan field and scan the player's QR, or search by name.</div>
        <hr class="divider"/>

        <!-- QR Scan Input -->
        <div class="form-group">
            <label for="qr-scan-input">📱 Scan Player's QR Code</label>
            <div style="position:relative;">
                <input type="text" id="qr-scan-input" name="qr_scan" autocomplete="off"
                       placeholder="🎯 Focus here and scan QR…"
                       class="scan-ring"
                       style="width:100%;background:var(--surface2);border:none;
                              border-radius:10px;padding:14px 48px 14px 16px;color:var(--text);
                              font-size:15px;font-family:monospace;outline:none;"/>
                <div id="scan-spinner" style="display:none;position:absolute;right:14px;top:50%;
                     transform:translateY(-50%);color:var(--accent);font-size:18px;">⏳</div>
            </div>
            <div id="scan-result" style="margin-top:8px;font-size:13px;min-height:20px;"
                 role="status" aria-live="polite"></div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin:14px 0;">
            <hr style="flex:1;border:none;border-top:1px solid var(--border);"/>
            <span style="color:var(--muted);font-size:12px;">or search manually</span>
            <hr style="flex:1;border:none;border-top:1px solid var(--border);"/>
        </div>

        <form method="POST" id="topup-form">
            <?= csrfField() ?>
            <input type="hidden" name="player_id" id="player_id_input"/>

            <!-- Search Player -->
            <div class="form-group">
                <label for="player-search">Search Player</label>
                <input type="text" id="player-search" name="player_search_text"
                       autocomplete="off"
                       placeholder="Name, username or phone…"/>
                <div id="search-results" style="position:relative;z-index:100;"
                     role="listbox" aria-label="Player search results"></div>
                <div id="selected-player" style="display:none;margin-top:8px;padding:12px;
                     background:rgba(0,229,160,0.08);border:1px solid var(--accent);
                     border-radius:10px;font-size:14px;line-height:1.7;"
                     aria-live="polite"></div>
            </div>

            <!-- Amount -->
            <div class="form-group">
                <label for="amount-input">Amount to Add (₱)</label>
                <div class="amt-grid" role="group" aria-label="Preset amounts">
                    <?php foreach ([50,100,200,300,500,1000] as $a): ?>
                        <button type="button" class="amt-preset"
                                data-amount="<?= $a ?>"
                                aria-label="Add ₱<?= number_format($a) ?>">
                            ₱<?= number_format($a) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <input type="number" name="amount" id="amount-input"
                       placeholder="Or enter custom amount"
                       min="1" step="0.01" required
                       aria-describedby="amount-desc"
                       style="font-size:18px;font-weight:700;"/>
                <div id="amount-desc" style="font-size:11px;color:var(--muted);margin-top:4px;">
                    Minimum ₱1. Credits are added instantly upon submission.
                </div>
            </div>

            <!-- Confirm summary -->
            <div id="confirm-summary" style="display:none;background:rgba(0,229,160,0.06);
                 border:1px solid rgba(0,229,160,0.25);border-radius:10px;
                 padding:14px;margin-bottom:14px;" aria-live="polite">
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px;">About to credit:</div>
                <div style="font-size:17px;font-weight:700;">
                    <span id="cs-name" style="color:var(--text);">—</span> &nbsp;
                    <span id="cs-amount" style="color:var(--accent);">₱0</span>
                </div>
                <div style="font-size:12px;color:var(--muted);margin-top:3px;">
                    Current balance: <span id="cs-current">₱0</span> →
                    New balance: <strong id="cs-new" style="color:var(--success);">₱0</strong>
                </div>
            </div>

            <button type="submit" class="btn-success btn-block" id="topup-btn"
                    style="font-size:15px;padding:14px;">
                💰 Add Credits + Activate QR
            </button>
        </form>
    </div>

    <!-- Right: Info + Stats sidebar -->
    <div class="topup-sidebar" style="display:flex;flex-direction:column;gap:20px;">

        <div class="card">
            <div class="card-title">📊 Today's Summary</div>
            <hr class="divider"/>
            <div class="today-grid">
                <div style="text-align:center;padding:14px;background:var(--surface2);border-radius:12px;">
                    <div style="font-family:'Bebas Neue',sans-serif;font-size:36px;color:var(--accent);"><?= $todayCount ?></div>
                    <div style="font-size:12px;color:var(--muted);">Top-Ups Done</div>
                </div>
                <div style="text-align:center;padding:14px;background:var(--surface2);border-radius:12px;">
                    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--accent);">₱<?= number_format($todayTotal,0) ?></div>
                    <div style="font-size:12px;color:var(--muted);">Credits Issued</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">📋 How It Works</div>
            <hr class="divider"/>
            <ol style="padding-left:18px;color:var(--muted);font-size:13px;line-height:2.3;margin:0;">
                <li>Player pays cash at the counter</li>
                <li>Open <strong style="color:var(--accent);">My QR Code</strong> on their phone</li>
                <li>Scan with USB scanner <em>or</em> search above</li>
                <li>Select amount → click <strong style="color:var(--success);">Add Credits</strong></li>
                <li>Credits added + QR activated <strong>instantly</strong></li>
            </ol>
        </div>

        <?php if (!empty($recentTopups)): ?>
        <div class="card" style="overflow:hidden;">
            <div class="card-title">🕐 Recent</div>
            <hr class="divider"/>
            <div style="max-height:280px;overflow-y:auto;">
            <?php foreach ($recentTopups as $t): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:8px 0;border-bottom:1px solid var(--border);">
                <div>
                    <div style="font-size:13px;font-weight:600;"><?= clean($t['full_name']) ?></div>
                    <div style="font-size:11px;color:var(--muted);">
                        @<?= clean($t['username']) ?> · <?= date('h:i A', strtotime($t['created_at'])) ?>
                    </div>
                </div>
                <span style="color:var(--success);font-weight:700;font-size:14px;
                             flex-shrink:0;margin-left:8px;">
                    +₱<?= number_format($t['amount'],0) ?>
                </span>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
(function () {
    'use strict';

    var qrInput         = document.getElementById('qr-scan-input');
    var scanResult      = document.getElementById('scan-result');
    var scanSpinner     = document.getElementById('scan-spinner');
    var selectedBalance = 0;
    var selectedName    = '';

    qrInput.focus();

    // ── QR scan (Enter key from USB scanner) ──────────────────
    qrInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var token = qrInput.value.trim();
            if (token) lookupToken(token);
            qrInput.value = '';
        }
    });

    // ── Re-focus scan input on idle clicks ───────────────────
    document.addEventListener('click', function (e) {
        var avoidIds = ['qr-scan-input', 'player-search', 'amount-input', 'topup-btn'];
        if (avoidIds.includes(e.target.id)) return;
        if (e.target.type === 'submit')     return;
        if (e.target.tagName === 'BUTTON')  return;
        if (e.target.tagName === 'A')       return;
        qrInput.focus();
    });

    // ── QR token lookup ───────────────────────────────────────
    function lookupToken(token) {
        scanSpinner.style.display = 'block';
        scanResult.innerHTML = '';
        fetch('?lookup_token=1&token=' + encodeURIComponent(token))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                scanSpinner.style.display = 'none';
                if (data.error) {
                    scanResult.innerHTML =
                        '<span style="color:var(--danger);">❌ ' + escHtml(data.error) + '</span>';
                } else {
                    selectPlayer(data.id, data.username, data.full_name,
                                 data.balance, data.qr_active);
                    scanResult.innerHTML =
                        '<span style="color:var(--success);">✅ QR matched: <strong>' +
                        escHtml(data.full_name) + '</strong></span>';
                    document.getElementById('amount-input').focus();
                }
            })
            .catch(function () {
                scanSpinner.style.display = 'none';
                scanResult.innerHTML =
                    '<span style="color:var(--danger);">❌ Lookup failed. Try again.</span>';
            });
    }

    // ── Player search ─────────────────────────────────────────
    var searchTO;
    document.getElementById('player-search').addEventListener('input', function () {
        clearTimeout(searchTO);
        var q = this.value.trim();
        if (q.length < 2) {
            document.getElementById('search-results').innerHTML = '';
            return;
        }
        searchTO = setTimeout(function () {
            fetch('?search_player=1&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (players) {
                    var container = document.getElementById('search-results');
                    if (!players.length) {
                        container.innerHTML =
                            '<div style="padding:10px;color:var(--muted);font-size:13px;">' +
                            'No players found.</div>';
                        return;
                    }
                    var html =
                        '<div style="position:absolute;top:4px;left:0;right:0;' +
                        'background:var(--surface);border:1px solid var(--border);' +
                        'border-radius:10px;overflow:hidden;' +
                        'box-shadow:0 8px 24px rgba(0,0,0,0.4);z-index:200;">';
                    players.forEach(function (p) {
                        html +=
                            '<div class="sr-item" role="option"' +
                            ' data-id="'      + p.id                    + '"' +
                            ' data-username="'+ escAttr(p.username)     + '"' +
                            ' data-name="'    + escAttr(p.full_name)    + '"' +
                            ' data-balance="' + (p.balance || 0)        + '"' +
                            ' data-qr="'      + (p.qr_active ? '1':'0') + '"' +
                            ' tabindex="0"' +
                            ' style="padding:10px 14px;cursor:pointer;' +
                            'border-bottom:1px solid var(--border);">' +
                            '<div style="font-weight:600;font-size:13px;">' +
                            escHtml(p.full_name) +
                            ' <span style="color:var(--muted);font-size:11px;">@' +
                            escHtml(p.username) + '</span></div>' +
                            '<div style="font-size:11px;color:var(--muted);">' +
                            'Balance: <span style="color:var(--accent);">₱' +
                            parseFloat(p.balance || 0).toFixed(2) + '</span>' +
                            ' &nbsp;·&nbsp; <span style="color:' +
                            (p.qr_active ? 'var(--success)' : 'var(--danger)') + ';">' +
                            (p.qr_active ? 'QR Active' : 'QR Inactive') + '</span>' +
                            (p.pending_requests > 0
                                ? ' &nbsp;·&nbsp; <span style="color:var(--warn);">⏳ ' +
                                  p.pending_requests + ' pending</span>'
                                : '') +
                            '</div></div>';
                    });
                    html += '</div>';
                    container.innerHTML = html;
                })
                .catch(function () {});
        }, 280);
    });

    // Delegate clicks on search result items
    document.getElementById('search-results').addEventListener('click', function (e) {
        var item = e.target.closest('.sr-item');
        if (!item) return;
        selectPlayer(
            item.dataset.id,
            item.dataset.username,
            item.dataset.name,
            item.dataset.balance,
            item.dataset.qr === '1'
        );
    });

    // Keyboard support for search results
    document.getElementById('search-results').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            var item = e.target.closest('.sr-item');
            if (!item) return;
            e.preventDefault();
            selectPlayer(
                item.dataset.id,
                item.dataset.username,
                item.dataset.name,
                item.dataset.balance,
                item.dataset.qr === '1'
            );
        }
    });

    // Hover effect via delegation
    document.getElementById('search-results').addEventListener('mouseover', function (e) {
        var item = e.target.closest('.sr-item');
        if (item) item.style.background = 'rgba(0,229,160,0.06)';
    });
    document.getElementById('search-results').addEventListener('mouseout', function (e) {
        var item = e.target.closest('.sr-item');
        if (item) item.style.background = '';
    });

    // ── Select a player ───────────────────────────────────────
    function selectPlayer(id, username, fullName, balance, qrActive) {
        document.getElementById('player_id_input').value = id;
        document.getElementById('player-search').value   = '';
        document.getElementById('search-results').innerHTML = '';
        selectedBalance = parseFloat(balance) || 0;
        selectedName    = fullName;

        var sel = document.getElementById('selected-player');
        sel.style.display = 'block';
        sel.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
            '<div>' +
            '<strong>' + escHtml(fullName) + '</strong>' +
            ' <span style="color:var(--muted);font-size:12px;">@' + escHtml(username) + '</span><br/>' +
            'Balance: <span style="color:var(--accent);">₱' + selectedBalance.toFixed(2) + '</span>' +
            ' &nbsp;·&nbsp; QR: <span style="color:' +
            (qrActive ? 'var(--success)' : 'var(--danger)') + ';">' +
            (qrActive ? '✅ Active' : '❌ Inactive') + '</span>' +
            '</div>' +
            '<button type="button" id="clear-player-btn" aria-label="Clear selected player"' +
            ' style="background:none;border:none;color:var(--muted);cursor:pointer;' +
            'font-size:18px;flex-shrink:0;min-width:36px;min-height:36px;">✕</button>' +
            '</div>';

        document.getElementById('clear-player-btn')
            .addEventListener('click', clearPlayer);

        updateSummary();
        document.getElementById('amount-input').focus();
    }

    function clearPlayer() {
        document.getElementById('player_id_input').value = '';
        document.getElementById('selected-player').style.display = 'none';
        document.getElementById('confirm-summary').style.display = 'none';
        selectedBalance = 0;
        selectedName    = '';
    }

    // ── Amount presets ────────────────────────────────────────
    document.querySelectorAll('.amt-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.amt-preset')
                .forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('amount-input').value = btn.dataset.amount;
            updateSummary();
        });
    });

    document.getElementById('amount-input').addEventListener('input', function () {
        document.querySelectorAll('.amt-preset')
            .forEach(function (b) { b.classList.remove('active'); });
        updateSummary();
    });

    // ── Confirm summary ───────────────────────────────────────
    function updateSummary() {
        var amount = parseFloat(document.getElementById('amount-input').value) || 0;
        var pid    = document.getElementById('player_id_input').value;
        var cs     = document.getElementById('confirm-summary');
        if (amount > 0 && pid) {
            cs.style.display = 'block';
            document.getElementById('cs-name').textContent   = selectedName;
            document.getElementById('cs-amount').textContent = '₱' + amount.toFixed(2);
            document.getElementById('cs-current').textContent= '₱' + selectedBalance.toFixed(2);
            document.getElementById('cs-new').textContent    = '₱' + (selectedBalance + amount).toFixed(2);
        } else {
            cs.style.display = 'none';
        }
    }

    // ── Form submit guard ─────────────────────────────────────
    document.getElementById('topup-form').addEventListener('submit', function (e) {
        if (!document.getElementById('player_id_input').value) {
            e.preventDefault();
            alert('Please select or scan a player first.');
            return;
        }
        var btn = document.getElementById('topup-btn');
        btn.disabled    = true;
        btn.textContent = '⏳ Processing…';
    });

    // ── XSS-safe escape helpers ───────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(str) {
        return String(str)
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>