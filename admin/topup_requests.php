<?php
// ============================================================
//  FILE: admin/topup_requests.php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_approve') {
    verifyCsrf();
    $ids = array_map('intval', (array)($_POST['req_ids'] ?? []));
    $approved = 0;
    foreach ($ids as $rid) {
        try {
            // ✅ FIX: Begin transaction FIRST, then fetch with FOR UPDATE.
            //    This prevents two concurrent admin sessions from approving
            //    the same request, and ensures the sequence is used cleanly
            //    without any external ID being passed to INSERT.
            $db->beginTransaction();

            $r = $db->prepare("
                SELECT tr.*, u.id AS uid
                FROM falcon.topup_requests tr
                JOIN falcon.users u ON u.id = tr.user_id
                WHERE tr.id = ? AND tr.status = 'pending'
                FOR UPDATE OF tr
            ");
            $r->execute([$rid]);
            $req = $r->fetch();

            // Already processed by another session or doesn't exist — skip.
            if (!$req) {
                $db->rollBack();
                continue;
            }

            $walStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE");
            $walStmt->execute([$req['user_id']]);
            $before = (float)($walStmt->fetchColumn() ?? 0);
            $after  = $before + (float)$req['amount'];

            $db->prepare("
                INSERT INTO falcon.wallets(user_id, balance)
                VALUES(?, ?)
                ON CONFLICT(user_id) DO UPDATE
                    SET balance    = EXCLUDED.balance,
                        updated_at = NOW()
            ")->execute([$req['user_id'], $after]);

            // ✅ FIX: Do NOT pass an explicit id column — let the sequence
            //    (transactions_id_seq) assign it via DEFAULT. Passing an id
            //    manually is what causes "duplicate key value violates unique
            //    constraint transactions_pkey" when the sequence is behind.
            $db->prepare("
                INSERT INTO falcon.transactions
                    (user_id, type, method, amount, note, status,
                     balance_before, balance_after, reference_no, processed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $req['user_id'],
                'topup',
                $req['method'] ?? 'gcash',
                $req['amount'],
                'GCash ref: ' . ($req['gcash_ref_no'] ?? ''),
                'approved',
                $before,
                $after,
                $req['gcash_ref_no'] ?? null,
                $_SESSION['user_id'],
            ]);

            $db->prepare("
                INSERT INTO falcon.player_passes(user_id, qr_token, expires_at, is_active)
                VALUES(?, encode(gen_random_bytes(24), 'hex'), '2099-12-31 23:59:59+00', TRUE)
                ON CONFLICT (user_id) DO UPDATE
                    SET is_active  = TRUE,
                        expires_at = CASE
                            WHEN falcon.player_passes.expires_at < NOW()
                            THEN '2099-12-31 23:59:59+00'::timestamptz
                            ELSE falcon.player_passes.expires_at
                        END
            ")->execute([$req['user_id']]);

            $db->prepare("
                UPDATE falcon.topup_requests
                SET status      = 'approved',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_note = ?
                WHERE id = ?
            ")->execute([$_SESSION['user_id'], 'Bulk approved', $rid]);

            $db->prepare("
                INSERT INTO falcon.notifications(user_id, title, message, type)
                VALUES(?, ?, ?, 'success')
            ")->execute([
                $req['user_id'],
                '✅ Top-Up Approved!',
                '₱' . number_format($req['amount'], 2) . ' added to your account. Your QR is now active!',
            ]);

            $db->commit();
            $approved++;

        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[bulk_approve_topup] ' . $e->getMessage());
            // Removed setFlash with raw DB error — never expose DB internals to UI
            setFlash('error', 'Failed to approve request #' . $rid . '. Please try again.');
        }
    }
    setFlash('success', "Bulk approved {$approved} request(s).");
    redirect('admin/topup_requests.php?status=pending');
}

$statusFilter  = $_GET['status'] ?? 'pending';
$searchRef     = trim($_GET['ref'] ?? '');
$validStatuses = ['pending','approved','rejected','all'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'pending';

$where  = $statusFilter !== 'all' ? "WHERE tr.status = ?" : "WHERE 1=1";
$params = $statusFilter !== 'all' ? [$statusFilter] : [];

if ($searchRef !== '') {
    $where  .= " AND (tr.gcash_ref_no ILIKE ? OR u.username ILIKE ? OR u.full_name ILIKE ?)";
    $like = "%$searchRef%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$stmt = $db->prepare("
    SELECT tr.id, tr.user_id, tr.amount, tr.method,
           tr.gcash_ref_no, tr.screenshot_path,
           tr.status, tr.created_at, tr.reviewed_at, tr.review_note,
           u.username, u.full_name,
           w.balance AS current_balance,
           (SELECT COUNT(*) FROM falcon.topup_requests t2
            WHERE t2.user_id=tr.user_id AND t2.status='approved') AS prev_approved,
           (SELECT COUNT(*) FROM falcon.topup_requests t3
            WHERE t3.gcash_ref_no=tr.gcash_ref_no AND t3.id<>tr.id AND t3.status='approved') AS dup_ref_count
    FROM falcon.topup_requests tr
    JOIN falcon.users u  ON u.id  = tr.user_id
    LEFT JOIN falcon.wallets w ON w.user_id = tr.user_id
    $where
    ORDER BY CASE WHEN tr.status='pending' THEN 0 ELSE 1 END, tr.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$countRows = $db->query("SELECT status, COUNT(*) AS cnt FROM falcon.topup_requests GROUP BY status")->fetchAll();
$counts = [];
foreach ($countRows as $row) $counts[$row['status']] = $row['cnt'];

$pendingValue = $db->query("SELECT COALESCE(SUM(amount),0) FROM falcon.topup_requests WHERE status='pending'")->fetchColumn();

$approvedValue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM falcon.topup_requests WHERE status='approved'")->fetchColumn();

$pageTitle = 'Top-Up Requests';
require_once __DIR__ . '/../includes/header.php';
?>
<?= breadcrumb([
    ['label' => 'Home', 'href' => APP_URL],
    ['label' => 'Top-Up Requests']
]) ?>
<?php
?>

<style nonce="<?= getCspNonce() ?>">
.req-inner {
    display: grid;
    grid-template-columns: 1fr 120px;
    gap: 16px;
    align-items: start;
    min-width: 0;
}
@media (max-width: 640px) {
    .req-inner { grid-template-columns: 1fr; }
    .req-screenshot {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-direction: row-reverse;
        justify-content: flex-end;
    }
}
.req-detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 8px;
    margin-bottom: 12px;
}
.req-detail-item {
    background: var(--surface2);
    border-radius: 10px;
    padding: 10px 12px;
    min-width: 0;
    overflow: hidden;
}
.req-detail-label {
    font-size: 10px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 4px;
}
.req-header-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
    flex-wrap: wrap;
    min-width: 0;
}
.req-header-info { flex: 1; min-width: 0; word-break: break-word; }
.req-header-badges {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.req-review-form {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-top: 12px;
}
.req-review-note { flex: 1; min-width: 180px; }
.req-review-note label {
    font-size: 12px;
    color: var(--muted);
    display: block;
    margin-bottom: 4px;
}
.req-review-btns {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.req-top-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
    align-items: flex-start;
}
.req-top-bar .tab-row { margin: 0; flex: 1; min-width: 200px; }
.req-search-form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.req-search-form input[type="text"] {
    width: 180px;
    min-width: 120px;
}
.fraud-flag {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 6px;
    background: rgba(239,68,68,0.12);
    border: 1px solid rgba(239,68,68,0.3);
    color: var(--danger);
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.bulk-bar {
    background: rgba(0,229,160,0.06);
    border: 1px solid rgba(0,229,160,0.2);
    border-radius: 10px;
    padding: 10px 16px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
@media (max-width: 480px) {
    .req-review-form { flex-direction: column; align-items: stretch; }
    .req-review-note { min-width: 100%; }
    .req-review-btns { width: 100%; }
    .req-review-btns button { flex: 1; }
    .req-top-bar { flex-direction: column; }
    .req-search-form input[type="text"] { width: 100%; }
    .req-search-form { width: 100%; }
    .req-detail-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Top-Up Requests</h1>
        <p>Review and approve GCash payment submissions.</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/admin/topup_history.php" class="btn-outline btn-sm">📋 Full History</a>
        <a href="<?= APP_URL ?>/admin/generate_topup_qr.php" class="btn-primary btn-sm">⚡ In-Person Top-Up</a>
    </div>
</div>

<div class="dashboard-grid cols-4" style="margin-bottom: 20px;">
    <div class="stat-card stat-accent-warn">
        <div class="stat-icon">⏳</div>
        <div class="stat-val" style="color:var(--warn);"><?= $counts['pending'] ?? 0 ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div class="stat-val">₱<?= number_format($pendingValue, 0) ?></div>
        <div class="stat-label">Awaiting Approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">✅</div>
        <div class="stat-val" style="color:var(--success);"><?= $counts['approved'] ?? 0 ?></div>
        <div class="stat-label">Approved · ₱<?= number_format($approvedValue, 0) ?></div>
    </div>
    <div class="stat-card stat-accent-danger">
        <div class="stat-icon">❌</div>
        <div class="stat-val" style="color:var(--danger);"><?= $counts['rejected'] ?? 0 ?></div>
        <div class="stat-label">Rejected</div>
    </div>
</div>

<?php if ($pendingValue > 0): ?>
<div class="alert alert-warning" style="align-items:center;">
    <div style="font-size:24px;flex-shrink:0;">💳</div>
    <div class="alert-content">
        <div style="font-weight:700;">
            <?= $counts['pending'] ?? 0 ?> pending request<?= ($counts['pending']??0)!==1?'s':'' ?> —
            ₱<?= number_format($pendingValue,2) ?> awaiting approval
        </div>
        <div style="font-size:13px;opacity:0.85;">Players can't play until their top-up is approved.</div>
    </div>
</div>
<?php endif; ?>

<!-- Filter Tabs + Search -->
<div class="req-top-bar">
    <div class="tab-row">
        <?php
        $tabs = ['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected','all'=>'📋 All'];
        foreach ($tabs as $key => $label):
            $active = $statusFilter === $key;
            $cnt    = $counts[$key] ?? 0;
        ?>
            <a href="?status=<?= $key ?>" class="tab-link <?= $active?'active':'' ?>">
                <?= $label ?>
                <?php if ($key !== 'all' && $cnt > 0): ?>
                    <span style="background:<?= $key==='pending'?'var(--warn)':($key==='approved'?'var(--success)':'var(--danger)') ?>;
                                 color:#000;border-radius:20px;padding:1px 8px;font-size:11px;margin-left:4px;">
                        <?= $cnt ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="req-search-form">
        <input type="hidden" name="status" value="<?= $statusFilter ?>"/>
        <input type="text" name="ref" value="<?= clean($searchRef) ?>" placeholder="Search ref / username…"/>
        <button type="submit" class="btn-primary btn-sm">🔍</button>
        <?php if ($searchRef): ?>
            <a href="?status=<?= $statusFilter ?>" class="btn-outline btn-sm">✕</a>
        <?php endif; ?>
    </form>
</div>

<?php
$pendingRequests = array_filter($requests, fn($r) => $r['status']==='pending');
$showBulk = ($statusFilter === 'pending' && count($pendingRequests) > 1);
?>

<?php if ($showBulk): ?>
<form method="POST" id="bulk-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_approve"/>
    <div class="bulk-bar">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;flex-shrink:0;">
            <input type="checkbox" id="check-all" onchange="toggleAll(this)" style="width:18px;height:18px;accent-color:var(--accent);"/> Select All
        </label>
        <button type="submit" class="btn-success btn-sm" id="bulk-btn" style="display:none;"
                onclick="return confirm('Bulk approve all selected requests?')">
            ✅ Approve Selected
        </button>
        <span id="bulk-count" style="font-size:12px;color:var(--muted);"></span>
    </div>
<?php endif; ?>

<?php if (empty($requests)): ?>
    <div class="card" style="text-align:center;padding:48px;">
        <div style="font-size:48px;margin-bottom:12px;">✅</div>
        <p class="text-muted">No <?= $statusFilter==='all'?'':$statusFilter ?> requests found.</p>
    </div>
<?php else: ?>
    <?php foreach ($requests as $r):
        $borderColor = $r['status']==='pending'?'var(--warn)':($r['status']==='approved'?'var(--success)':'var(--border)');
        $badge       = $r['status']==='approved'?'success':($r['status']==='rejected'?'danger':'warn');
        $isDuplicate = (int)$r['dup_ref_count'] > 0;
        $isHighValue = (float)$r['amount'] >= 1000;
        $isFirstTime = (int)$r['prev_approved'] === 0;
        $waitMins    = round((time() - strtotime($r['created_at'])) / 60);
    ?>
        <div class="card mb-2" id="req-<?= $r['id'] ?>" style="border-color:<?= $borderColor ?>;">
            <div class="req-inner">
                <div style="min-width:0;">

                    <!-- Header -->
                    <div class="req-header-row">
                        <?php if ($showBulk && $r['status']==='pending'): ?>
                            <input type="checkbox" name="req_ids[]" value="<?= $r['id'] ?>"
                                   class="bulk-cb" onchange="updateBulkCount()"
                                   style="width:18px;height:18px;accent-color:var(--accent);cursor:pointer;flex-shrink:0;margin-top:3px;"/>
                        <?php endif; ?>
                        <div class="req-header-info">
                            <div style="font-weight:700;font-size:15px;"><?= clean($r['full_name']) ?></div>
                            <div style="color:var(--muted);font-size:13px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:2px;">
                                <span>@<?= clean($r['username']) ?></span>
                                <span>·</span>
                                <span>Balance: <span style="color:var(--accent);">₱<?= number_format($r['current_balance'],2) ?></span></span>
                                <?php if ($isFirstTime): ?>
                                    <span style="color:var(--accent2);font-size:11px;">🆕 First top-up</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="req-header-badges">
                            <span class="badge badge-<?= $badge ?>"><?= ucfirst($r['status']) ?></span>
                            <?php if ($r['status']==='pending'): ?>
                                <span style="font-size:11px;color:<?= $waitMins>60?'var(--danger)':'var(--muted)' ?>;">
                                    ⏱ <?= $waitMins>=60?floor($waitMins/60).'h '.($waitMins%60).'m':$waitMins.'m' ?> ago
                                </span>
                            <?php endif; ?>
                            <?php if ($isDuplicate): ?><span class="fraud-flag">⚠️ Dup Ref#</span><?php endif; ?>
                            <?php if ($isHighValue): ?><span class="fraud-flag" style="background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.3);color:var(--warn);">💰 High</span><?php endif; ?>
                        </div>
                    </div>

                    <!-- Detail grid -->
                    <div class="req-detail-grid">
                        <div class="req-detail-item">
                            <div class="req-detail-label">Amount</div>
                            <div style="font-family:'Bebas Neue',sans-serif;font-size:clamp(18px,4vw,26px);color:var(--accent);">
                                ₱<?= number_format($r['amount'],2) ?>
                            </div>
                        </div>
                        <div class="req-detail-item">
                            <div class="req-detail-label">Method</div>
                            <div style="font-weight:700;margin-top:4px;"><?= ucfirst($r['method']??'GCash') ?></div>
                        </div>
                        <div class="req-detail-item" style="<?= $isDuplicate?'border:1px solid var(--danger);':'' ?>">
                            <div class="req-detail-label" style="color:<?= $isDuplicate?'var(--danger)':'var(--muted)' ?>;">
                                GCash Ref <?= $isDuplicate?'⚠️':'' ?>
                            </div>
                            <div style="font-family:monospace;font-weight:700;font-size:12px;margin-top:4px;word-break:break-all;">
                                <?= $r['gcash_ref_no'] ? clean($r['gcash_ref_no']) : '—' ?>
                            </div>
                        </div>
                        <div class="req-detail-item">
                            <div class="req-detail-label">Submitted</div>
                            <div style="font-size:12px;margin-top:4px;">
                                <?= date('M d, Y', strtotime($r['created_at'])) ?><br>
                                <span style="color:var(--accent);"><?= date('h:i A', strtotime($r['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($r['review_note']): ?>
                        <div style="padding:10px 14px;background:rgba(100,116,139,0.1);border-radius:8px;
                                    font-size:13px;color:var(--muted);margin-top:8px;word-break:break-word;">
                            💬 <?= clean($r['review_note']) ?>
                            <?php if ($r['reviewed_at']): ?>
                                <span style="font-size:11px;"> · <?= date('M d h:i A',strtotime($r['reviewed_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Screenshot -->
                <div class="req-screenshot" style="text-align:center;flex-shrink:0;">
                    <?php if ($r['screenshot_path']): ?>
                        <?php if (str_starts_with($r['screenshot_path'], 'data:image/')): ?>
                            <a href="#" onclick="showImg('<?= htmlspecialchars($r['screenshot_path'], ENT_QUOTES) ?>')"
                               class="btn-outline btn-sm">View</a>
                        <?php else: ?>
                            <a href="<?= APP_URL ?>/uploads/screenshots/<?= urlencode($r['screenshot_path']) ?>"
                               target="_blank" class="btn-outline btn-sm">View</a>
                        <?php endif; ?>
                            <img src="<?= str_starts_with($r['screenshot_path'], 'data:image/') ? htmlspecialchars($r['screenshot_path']) : APP_URL . '/uploads/screenshots/' . urlencode($r['screenshot_path']) ?>"
                                 style="width:110px;height:110px;object-fit:cover;border-radius:10px;
                                        border:2px solid var(--border);cursor:zoom-in;
                                        transition:transform .2s;display:block;"
                                 onmouseover="this.style.transform='scale(1.05)'"
                                 onmouseout="this.style.transform='scale(1)'"
                                 alt="Payment screenshot"/>
                        </a>
                        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Tap to enlarge</div>
                    <?php else: ?>
                        <div style="width:110px;height:110px;border-radius:10px;border:2px dashed var(--border);
                                    display:flex;align-items:center;justify-content:center;
                                    color:var(--muted);font-size:11px;text-align:center;flex-direction:column;gap:4px;">
                            <div style="font-size:22px;">📵</div>No screenshot
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($r['status'] === 'pending'): ?>
                <hr class="divider"/>
                <form method="POST" action="<?= APP_URL ?>/admin/process_topup.php"
                      class="req-review-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                    <div class="req-review-note">
                        <label>Review Note <span style="opacity:.6;">(required if rejecting)</span></label>
                        <input type="text" name="review_note" placeholder="Leave blank to approve…"/>
                    </div>
                    <div class="req-review-btns">
                        <button type="submit" name="action" value="approve" class="btn-success btn-sm"
                                onclick="return confirm('Approve ₱<?= number_format($r['amount'],2) ?> for @<?= clean($r['username']) ?>?')">
                            ✅ Approve
                        </button>
                        <button type="submit" name="action" value="reject" class="btn-danger btn-sm"
                                onclick="return confirm('Reject this top-up request?')">
                            ❌ Reject
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($showBulk): ?>
</form>
<?php endif; ?>

<script nonce="<?= getCspNonce() ?>">
function toggleAll(master) {
    document.querySelectorAll('.bulk-cb').forEach(cb => cb.checked = master.checked);
    updateBulkCount();
}
function updateBulkCount() {
    const checked = document.querySelectorAll('.bulk-cb:checked').length;
    const btn  = document.getElementById('bulk-btn');
    const info = document.getElementById('bulk-count');
    if (btn)  btn.style.display  = checked > 0 ? 'inline-flex' : 'none';
    if (info) info.textContent   = checked > 0 ? checked + ' selected' : '';
}
</script>
<script nonce="<?= getCspNonce() ?>">
function showImg(src) {
    const w = window.open('');
    w.document.write('<img src="' + src + '" style="max-width:100%;"/>');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>