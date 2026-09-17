<?php
// ============================================================
//  FILE: admin/player_view.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db  = getDB();
$pid = (int)($_GET['id'] ?? 0);
if (!$pid) redirect('admin/players.php');

$pStmt = $db->prepare("
    SELECT u.*, COALESCE(w.balance, 0) AS balance,
           pp.is_active AS qr_active, pp.qr_token
    FROM falcon.users u
    LEFT JOIN falcon.wallets w ON w.user_id = u.id
    LEFT JOIN falcon.player_passes pp ON pp.user_id = u.id
    WHERE u.id = ? AND u.role = 'player'
");
$pStmt->execute([$pid]);
$player = $pStmt->fetch();
if (!$player) redirect('admin/players.php');

$statsStmt = $db->prepare("
    SELECT
        COUNT(*)                                           AS total_games,
        COUNT(*) FILTER (WHERE gs.status='completed')     AS completed,
        COUNT(*) FILTER (WHERE gs.status='cancelled')     AS cancelled,
        COALESCE(SUM(gp.credits_charged) FILTER (WHERE gs.status='completed'), 0) AS total_spent,
        MAX(gs.started_at) FILTER (WHERE gs.status='completed') AS last_game
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    WHERE gp.user_id = ?
");
$statsStmt->execute([$pid]);
$stats = $statsStmt->fetch();

$gamesStmt = $db->prepare("
    SELECT gs.id, gs.status, gs.started_at, gs.duration_mins,
           c.name AS court_name, gp.credits_charged
    FROM falcon.game_players gp
    JOIN falcon.game_sessions gs ON gs.id = gp.session_id
    JOIN falcon.courts c ON c.id = gs.court_id
    WHERE gp.user_id = ?
    ORDER BY gs.started_at DESC LIMIT 10
");
$gamesStmt->execute([$pid]);
$games = $gamesStmt->fetchAll();

$txStmt = $db->prepare("
    SELECT id, type, amount, note, status, balance_before, balance_after, created_at
    FROM falcon.transactions
    WHERE user_id = ?
    ORDER BY created_at DESC LIMIT 15
");
$txStmt->execute([$pid]);
$transactions = $txStmt->fetchAll();

$pendingReqs = $db->prepare("
    SELECT id, amount, gcash_ref_no, status, created_at
    FROM falcon.topup_requests
    WHERE user_id = ? AND status = 'pending'
    ORDER BY created_at DESC
");
$pendingReqs->execute([$pid]);
$pendingRequests = $pendingReqs->fetchAll();

// Pre-generate one CSRF token for all JS-triggered forms
$csrf = csrfToken();

$pageTitle = 'Player: ' . $player['username'];
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.pv-layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 24px;
}
.pv-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}
.pv-action-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 4px;
}

/* Modal (same pattern as players.php) */
.pm-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.78);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
    padding-bottom: max(16px, env(safe-area-inset-bottom));
    box-sizing: border-box;
}
.pm-overlay.open { display: flex; }
.pm-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 28px;
    width: 100%;
    max-width: 420px;
    max-height: 90dvh;
    overflow-y: auto;
    box-sizing: border-box;
}
.pm-title { font-family: 'Bebas Neue', sans-serif; font-size: 24px; margin-bottom: 4px; }
.pm-sub   { font-size: 13px; color: var(--muted); margin-bottom: 18px; }
.pm-group { margin-bottom: 14px; }
.pm-group label {
    display: block; font-size: 12px; font-weight: 600;
    color: var(--muted); text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 5px;
}
.pm-footer { display: flex; gap: 10px; margin-top: 16px; }
.pm-footer button { flex: 1; }

@media (max-width: 900px) {
    .pv-layout { grid-template-columns: 1fr; }
    .pv-layout > .pv-sidebar { order: 2; }
    .pv-layout > .pv-main   { order: 1; }
    .pv-stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 600px) {
    .pv-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
}
@media (max-width: 420px) {
    .pv-stats { grid-template-columns: repeat(2, 1fr); gap: 6px; }
    .pm-box { padding: 20px; border-radius: 14px; }
    .pm-footer { flex-direction: column; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1><?= clean($player['full_name']) ?></h1>
        <p>@<?= clean($player['username']) ?> · Player Profile</p>
    </div>
    <a href="<?= APP_URL ?>/admin/players.php" class="btn-outline btn-sm">← All Players</a>
</div>

<!-- Stats -->
<div class="pv-stats">
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);">₱<?= number_format($player['balance'],2) ?></div>
        <div class="stat-label">Balance</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= $stats['total_games'] ?></div>
        <div class="stat-label">Total Games</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);"><?= $stats['completed'] ?></div>
        <div class="stat-label">Completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);"><?= $stats['cancelled'] ?></div>
        <div class="stat-label">Cancelled</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);">₱<?= number_format($stats['total_spent'],2) ?></div>
        <div class="stat-label">Credits Spent</div>
    </div>
</div>

<?php if (!empty($pendingRequests)): ?>
<div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:12px;
            padding:12px 20px;margin-bottom:20px;font-size:14px;color:var(--warn);">
    ⏳ This player has <strong><?= count($pendingRequests) ?></strong> pending GCash top-up request(s).
    <a href="<?= APP_URL ?>/admin/topup_requests.php?status=pending" style="color:var(--accent);margin-left:8px;">Review →</a>
</div>
<?php endif; ?>

<!-- ── Single shared form — all sidebar actions POST through here ── -->
<form id="pv-action-form" method="POST" action="<?= APP_URL ?>/admin/players.php" style="display:none;">
    <input type="hidden" name="csrf_token"    value="<?= clean($csrf) ?>"/>
    <input type="hidden" name="action"        id="pv-action"/>
    <input type="hidden" name="user_id"       value="<?= $pid ?>"/>
    <input type="hidden" name="new_password"  id="pv-new-password"/>
    <input type="hidden" name="ban_reason"    id="pv-ban-reason"/>
</form>

<!-- Main layout -->
<div class="pv-layout">

    <!-- Sidebar -->
    <div class="pv-sidebar" style="display:flex;flex-direction:column;gap:16px;">

        <!-- Player Info card -->
        <div class="card">
            <div style="text-align:center;padding:8px 0 16px;">
                <?php
                $avatarUrl = ($player['avatar_path'] ?? null) && file_exists(UPLOAD_AVATARS . $player['avatar_path'])
                    ? APP_URL . '/uploads/avatars/' . urlencode($player['avatar_path']) : null;
                ?>
                <?php if ($avatarUrl): ?>
                    <img src="<?= $avatarUrl ?>"
                         style="width:68px;height:68px;border-radius:50%;object-fit:cover;border:3px solid var(--accent);margin:0 auto 12px;display:block;"/>
                <?php else: ?>
                    <div style="width:68px;height:68px;background:linear-gradient(135deg,#059669,#00e5a0);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 12px;color:#022c22;font-weight:700;">
                        <?= strtoupper(substr($player['username'],0,1)) ?>
                    </div>
                <?php endif; ?>
                <div style="font-weight:700;font-size:17px;"><?= clean($player['full_name']) ?></div>
                <div style="color:var(--muted);font-size:12px;">@<?= clean($player['username']) ?></div>
                <div style="margin-top:8px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                    <?php if ($player['is_banned']): ?>
                        <span class="badge badge-danger">🚫 Banned</span>
                    <?php elseif (!$player['is_verified']): ?>
                        <span class="badge badge-warn">⚠️ Unverified</span>
                    <?php else: ?>
                        <span class="badge badge-success">✅ Active</span>
                    <?php endif; ?>
                    <span class="badge badge-<?= $player['qr_active'] ? 'success' : 'danger' ?>">
                        <?= $player['qr_active'] ? '📱 QR Active' : '📵 QR Inactive' ?>
                    </span>
                </div>
            </div>
            <hr class="divider"/>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;">
                <div class="flex-between">
                    <span style="color:var(--muted);">Phone</span>
                    <span><?= clean($player['phone'] ?? '—') ?></span>
                </div>
                <div class="flex-between">
                    <span style="color:var(--muted);">Email</span>
                    <span style="font-size:12px;word-break:break-all;"><?= clean($player['email'] ?? '—') ?></span>
                </div>
                <div class="flex-between">
                    <span style="color:var(--muted);">Joined</span>
                    <span><?= date('M d, Y', strtotime($player['created_at'])) ?></span>
                </div>
                <?php if ($stats['last_game']): ?>
                <div class="flex-between">
                    <span style="color:var(--muted);">Last Game</span>
                    <span><?= date('M d, Y', strtotime($stats['last_game'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($player['is_banned'] && $player['ban_reason']): ?>
                    <div style="background:rgba(239,68,68,0.08);border:1px solid var(--danger);border-radius:8px;padding:8px;margin-top:4px;">
                        <div style="font-size:10px;color:var(--danger);font-weight:600;margin-bottom:3px;">BAN REASON</div>
                        <div style="color:var(--text);font-size:12px;"><?= clean($player['ban_reason']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Admin Actions card -->
        <div class="card">
            <div class="card-title">⚡ Admin Actions</div>
            <hr class="divider"/>
            <div style="display:flex;flex-direction:column;gap:10px;">

                <!-- Reset Password → opens modal -->
                <button type="button" id="open-reset-btn"
                        class="btn-outline btn-sm" style="width:100%;padding:10px;">
                    🔑 Reset Password
                </button>

                <!-- Add Credits -->
                <a href="<?= APP_URL ?>/admin/generate_topup_qr.php?player_id=<?= $pid ?>"
                   class="btn-outline btn-sm" style="text-align:center;display:block;padding:10px;">
                    💰 Add Credits
                </a>

                <!-- Verify (only shown when unverified) -->
                <?php if (!$player['is_verified']): ?>
                <button type="button" id="open-verify-btn"
                        style="width:100%;padding:10px;background:rgba(0,229,160,0.1);color:var(--accent);border:1px solid var(--accent);border-radius:8px;cursor:pointer;font-weight:600;">
                    ✓ Verify Account
                </button>
                <?php endif; ?>

                <!-- Ban / Unban -->
                <?php if ($player['is_banned']): ?>
                    <button type="button" id="open-unban-btn"
                            class="btn-success btn-sm" style="width:100%;padding:10px;">
                        ✅ Unban Player
                    </button>
                <?php else: ?>
                    <button type="button" id="open-ban-btn"
                            style="width:100%;padding:10px;background:rgba(239,68,68,0.1);color:var(--danger);border:1px solid var(--danger);border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;">
                        🚫 Ban Player
                    </button>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- Main: Games + Transactions -->
    <div class="pv-main" style="display:flex;flex-direction:column;gap:20px;">

        <div class="card">
            <div class="card-title">🎮 Recent Games</div>
            <hr class="divider"/>
            <?php if (empty($games)): ?>
                <p class="text-muted text-center" style="padding:20px;">No games yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Court</th>
                                <th class="col-hide-sm">Duration</th>
                                <th>Credits</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($games as $g):
                                $badge = match($g['status']) {
                                    'completed' => 'success',
                                    'cancelled' => 'danger',
                                    'active'    => 'info',
                                    default     => 'muted'
                                };
                            ?>
                                <tr>
                                    <td style="white-space:nowrap;font-size:12px;"><?= date('M d, Y h:i A', strtotime($g['started_at'])) ?></td>
                                    <td style="font-size:13px;"><?= clean($g['court_name']) ?></td>
                                    <td class="col-hide-sm" style="font-family:monospace;font-size:12px;"><?= $g['duration_mins'] ?>min</td>
                                    <td style="color:<?= $g['credits_charged']>0?'var(--danger)':'var(--muted)' ?>;font-size:13px;">
                                        <?= $g['credits_charged'] > 0 ? '-₱'.number_format($g['credits_charged'],2) : '₱0' ?>
                                    </td>
                                    <td><span class="badge badge-<?= $badge ?>"><?= ucfirst($g['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">💳 Recent Transactions</div>
            <hr class="divider"/>
            <?php if (empty($transactions)): ?>
                <p class="text-muted text-center" style="padding:20px;">No transactions yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th class="col-hide-sm">Balance After</th>
                                <th class="col-hide-xs">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $tx):
                                $isCredit = in_array($tx['type'], ['topup','refund']);
                            ?>
                                <tr>
                                    <td style="font-size:11px;color:var(--muted);white-space:nowrap;"><?= date('M d, h:i A', strtotime($tx['created_at'])) ?></td>
                                    <td><span class="badge badge-<?= $isCredit?'success':'muted' ?>"><?= ucfirst($tx['type']) ?></span></td>
                                    <td style="font-weight:700;color:<?= $isCredit?'var(--success)':'var(--danger)' ?>;font-size:13px;">
                                        <?= $isCredit?'+':'-' ?>₱<?= number_format(abs($tx['amount']),2) ?>
                                    </td>
                                    <td class="col-hide-sm" style="font-size:12px;color:var(--accent);">
                                        <?= $tx['balance_after'] !== null ? '₱'.number_format($tx['balance_after'],2) : '—' ?>
                                    </td>
                                    <td class="col-hide-xs"
                                        style="font-size:11px;color:var(--muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?= clean($tx['note']??'') ?>">
                                        <?= clean(substr($tx['note'] ?? '—', 0, 40)) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Reset Password Modal ── -->
<div id="reset-modal" class="pm-overlay" role="dialog" aria-modal="true">
    <div class="pm-box">
        <div class="pm-title">🔑 Reset Password</div>
        <div class="pm-sub">Player: <strong><?= clean($player['full_name']) ?></strong> (@<?= clean($player['username']) ?>)</div>
        <div class="pm-group">
            <label>New Password</label>
            <div style="position:relative;">
                <input type="text" id="reset-pw-inp"
                       placeholder="Min. 6 characters"
                       style="padding-right:90px;width:100%;box-sizing:border-box;"/>
                <button type="button" id="gen-pw-btn"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                               background:rgba(0,229,160,0.12);border:1px solid var(--accent);
                               color:var(--accent);border-radius:6px;padding:3px 10px;
                               font-size:11px;cursor:pointer;white-space:nowrap;">
                    🎲 Generate
                </button>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:5px;">
                Player will be required to change this on next login.
            </div>
        </div>
        <div class="pm-footer">
            <button type="button" id="reset-confirm-btn" class="btn-primary">Set Password</button>
            <button type="button" id="reset-cancel-btn" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Verify Modal ── -->
<div id="verify-modal" class="pm-overlay" role="dialog" aria-modal="true">
    <div class="pm-box">
        <div class="pm-title" style="color:var(--success);">✅ Verify Account</div>
        <div class="pm-sub">Player: <strong><?= clean($player['full_name']) ?></strong></div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.6;">
            This grants the player full access and sends them a notification confirming their verification.
        </p>
        <div class="pm-footer">
            <button type="button" id="verify-confirm-btn" class="btn-success">✅ Confirm Verify</button>
            <button type="button" id="verify-cancel-btn" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Ban Modal ── -->
<div id="ban-modal" class="pm-overlay" role="dialog" aria-modal="true">
    <div class="pm-box">
        <div class="pm-title" style="color:var(--danger);">🚫 Ban Player</div>
        <div class="pm-sub">Banning: <strong><?= clean($player['full_name']) ?></strong> (@<?= clean($player['username']) ?>)</div>
        <div class="pm-group">
            <label>Reason for ban</label>
            <textarea id="ban-reason-inp" rows="3" style="width:100%;box-sizing:border-box;resize:vertical;">Violation of court rules.</textarea>
        </div>
        <div class="pm-footer">
            <button type="button" id="ban-confirm-btn" class="btn-danger">Confirm Ban</button>
            <button type="button" id="ban-cancel-btn" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Unban Modal ── -->
<div id="unban-modal" class="pm-overlay" role="dialog" aria-modal="true">
    <div class="pm-box">
        <div class="pm-title" style="color:var(--success);">✅ Unban Player</div>
        <div class="pm-sub">Player: <strong><?= clean($player['full_name']) ?></strong> (@<?= clean($player['username']) ?>)</div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.6;">
            This will restore full access to this player's account and notify them.
        </p>
        <div class="pm-footer">
            <button type="button" id="unban-confirm-btn" class="btn-success">✅ Confirm Unban</button>
            <button type="button" id="unban-cancel-btn" class="btn-outline">Cancel</button>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
(function () {
    'use strict';

    var modals = ['reset-modal', 'verify-modal', 'ban-modal', 'unban-modal'];

    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    // Close on backdrop click or Escape
    modals.forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('click', function (e) { if (e.target === el) closeModal(id); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') modals.forEach(closeModal);
    });

    // ── Submit helper ─────────────────────────────────────────
    function submitAction(action, extraFields) {
        document.getElementById('pv-action').value = action;
        if (extraFields) {
            Object.keys(extraFields).forEach(function (k) {
                var el = document.getElementById(k);
                if (el) el.value = extraFields[k];
            });
        }
        document.getElementById('pv-action-form').submit();
    }

    // ── Toast (CSP-safe: setTimeout with function, not string) ─
    function showToast(msg) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--surface);' +
            'border:1px solid var(--accent);color:var(--accent);padding:12px 20px;' +
            'border-radius:10px;font-size:13px;font-weight:600;z-index:99999;';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3000);
    }

    // ── Password generator ────────────────────────────────────
    function genPassword() {
        var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
        var pw = '';
        for (var i = 0; i < 10; i++) pw += chars[Math.floor(Math.random() * chars.length)];
        return pw;
    }

    // ── Reset Password ────────────────────────────────────────
    var openResetBtn = document.getElementById('open-reset-btn');
    if (openResetBtn) {
        openResetBtn.addEventListener('click', function () {
            document.getElementById('reset-pw-inp').value = '';
            openModal('reset-modal');
        });
    }
    document.getElementById('gen-pw-btn').addEventListener('click', function () {
        var pw  = genPassword();
        var inp = document.getElementById('reset-pw-inp');
        inp.value = pw;
        inp.select();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(pw).then(function () { showToast('✅ Password copied!'); });
        } else {
            showToast('✅ Password generated!');
        }
    });
    document.getElementById('reset-confirm-btn').addEventListener('click', function () {
        var pw = document.getElementById('reset-pw-inp').value.trim();
        if (pw.length < 6) { showToast('⚠️ Password must be at least 6 characters.'); return; }
        submitAction('reset_password', { 'pv-new-password': pw });
    });
    document.getElementById('reset-cancel-btn').addEventListener('click', function () { closeModal('reset-modal'); });

    // ── Verify ────────────────────────────────────────────────
    var openVerifyBtn = document.getElementById('open-verify-btn');
    if (openVerifyBtn) {
        openVerifyBtn.addEventListener('click', function () { openModal('verify-modal'); });
        document.getElementById('verify-confirm-btn').addEventListener('click', function () {
            submitAction('verify');
        });
        document.getElementById('verify-cancel-btn').addEventListener('click', function () { closeModal('verify-modal'); });
    }

    // ── Ban ───────────────────────────────────────────────────
    var openBanBtn = document.getElementById('open-ban-btn');
    if (openBanBtn) {
        openBanBtn.addEventListener('click', function () { openModal('ban-modal'); });
        document.getElementById('ban-confirm-btn').addEventListener('click', function () {
            var reason = document.getElementById('ban-reason-inp').value.trim() || 'Violation of court rules.';
            submitAction('ban', { 'pv-ban-reason': reason });
        });
        document.getElementById('ban-cancel-btn').addEventListener('click', function () { closeModal('ban-modal'); });
    }

    // ── Unban ─────────────────────────────────────────────────
    var openUnbanBtn = document.getElementById('open-unban-btn');
    if (openUnbanBtn) {
        openUnbanBtn.addEventListener('click', function () { openModal('unban-modal'); });
        document.getElementById('unban-confirm-btn').addEventListener('click', function () {
            submitAction('unban');
        });
        document.getElementById('unban-cancel-btn').addEventListener('click', function () { closeModal('unban-modal'); });
    }

}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>