<?php
// ============================================================
//  FILE: admin/withdrawals.php
//  Admin — Withdrawal Request Management
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/helpers.php';
requireAdmin();

$db   = getDB();
$user = currentUser();

// ── Stats ─────────────────────────────────────────────────────
$stats = $db->query("
    SELECT
        COUNT(*)                                          AS total,
        COUNT(*) FILTER (WHERE status = 'pending')       AS pending,
        COUNT(*) FILTER (WHERE status = 'approved')      AS approved,
        COUNT(*) FILTER (WHERE status = 'rejected')      AS rejected,
        COALESCE(SUM(amount) FILTER (WHERE status = 'approved'), 0) AS total_approved_amount
    FROM falcon.withdrawal_requests
")->fetch();

// ── Filters ───────────────────────────────────────────────────
$filterStatus = trim($_GET['status'] ?? '');
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterStatus !== '' && in_array($filterStatus, ['pending','approved','rejected'], true)) {
    $where[]  = 'wr.status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[]  = "(u.username ILIKE ? OR u.full_name ILIKE ? OR wr.bank_name ILIKE ? OR wr.account_name ILIKE ?)";
    $s        = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}

$whereClause = implode(' AND ', $where);

$totalRows = (int)$db->prepare(
    "SELECT COUNT(*) FROM falcon.withdrawal_requests wr
       JOIN falcon.users u ON u.id = wr.user_id
      WHERE {$whereClause}"
)->execute($params) ? $db->prepare(
    "SELECT COUNT(*) FROM falcon.withdrawal_requests wr
       JOIN falcon.users u ON u.id = wr.user_id
      WHERE {$whereClause}"
) : null;

// Re-run count properly
$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM falcon.withdrawal_requests wr
       JOIN falcon.users u ON u.id = wr.user_id
      WHERE {$whereClause}"
);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$listParams   = array_merge($params, [$perPage, $offset]);
$withdrawals  = $db->prepare(
    "SELECT wr.id, wr.amount, wr.bank_name, wr.account_number, wr.account_name,
            wr.status, wr.admin_note, wr.processed_at, wr.created_at,
            u.id AS user_id, u.username, u.full_name,
            pa.username AS processed_by_name
       FROM falcon.withdrawal_requests wr
       JOIN falcon.users u ON u.id = wr.user_id
  LEFT JOIN falcon.users pa ON pa.id = wr.processed_by
      WHERE {$whereClause}
      ORDER BY
            CASE wr.status WHEN 'pending' THEN 0 ELSE 1 END,
            wr.created_at DESC
      LIMIT ? OFFSET ?"
);
$withdrawals->execute($listParams);
$rows = $withdrawals->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Withdrawal Requests';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.wd-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.wd-filter-bar {
    display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    margin-bottom:16px;
}
.wd-filter-bar input, .wd-filter-bar select {
    background:var(--surface2); border:1px solid var(--border);
    color:var(--text); border-radius:8px; padding:8px 12px;
    font-size:13px; outline:none;
}
.wd-filter-bar input { flex:1; min-width:180px; }
.wd-filter-bar input:focus, .wd-filter-bar select:focus { border-color:var(--accent); }
.wd-amount { font-weight:700; color:var(--accent); white-space:nowrap; }
.wd-bank   { font-size:12px; color:var(--muted); }
.wd-actions { display:flex; gap:6px; flex-wrap:wrap; }
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.65); z-index:1000;
    align-items:center; justify-content:center; padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:var(--surface); border:1px solid var(--border);
    border-radius:16px; padding:24px; width:100%; max-width:440px;
    box-shadow:0 8px 40px rgba(0,0,0,.4);
}
.modal-title { font-family:'Bebas Neue',sans-serif; font-size:20px; letter-spacing:1px; margin-bottom:16px; }
.modal-field { margin-bottom:14px; }
.modal-field label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; font-weight:600; }
.modal-field textarea, .modal-field input {
    width:100%; background:var(--surface2); border:1px solid var(--border);
    color:var(--text); border-radius:8px; padding:10px 12px;
    font-size:13px; font-family:inherit; resize:vertical; outline:none;
    box-sizing:border-box;
}
.modal-field textarea:focus, .modal-field input:focus { border-color:var(--accent); }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
.detail-row { display:flex; gap:8px; margin-bottom:8px; font-size:13px; }
.detail-label { color:var(--muted); min-width:110px; flex-shrink:0; }
.detail-val   { font-weight:600; word-break:break-all; }
.pagination { display:flex; gap:6px; align-items:center; margin-top:16px; flex-wrap:wrap; }
.pagination a, .pagination span {
    padding:6px 12px; border-radius:8px; font-size:13px;
    background:var(--surface2); border:1px solid var(--border); color:var(--text);
    text-decoration:none;
}
.pagination a:hover { border-color:var(--accent); }
.pagination span.active { background:var(--accent); color:var(--bg); border-color:var(--accent); font-weight:700; }
.pagination span.dots { border:none; background:none; color:var(--muted); }
@media(max-width:768px){
    .wd-stats{ grid-template-columns:repeat(2,1fr); }
    .col-hide-md { display:none; }
}
@media(max-width:480px){
    .wd-stats{ grid-template-columns:1fr 1fr; gap:8px; }
    .col-hide-sm { display:none; }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>💸 Withdrawal Requests</h1>
        <p style="margin:0;font-size:14px;color:var(--muted);">
            Review and process player withdrawal requests
        </p>
    </div>
    <?php if ($stats['pending'] > 0): ?>
    <span class="badge badge-warn" style="font-size:14px;padding:6px 14px;">
        ⏳ <?= $stats['pending'] ?> Pending
    </span>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="wd-stats">
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);"><?= number_format($stats['pending']) ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);"><?= number_format($stats['approved']) ?></div>
        <div class="stat-label">Approved</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);"><?= number_format($stats['rejected']) ?></div>
        <div class="stat-label">Rejected</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--success);font-size:clamp(16px,3vw,26px);">
            ₱<?= number_format($stats['total_approved_amount'], 2) ?>
        </div>
        <div class="stat-label">Total Paid Out</div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="wd-filter-bar">
    <input type="text" name="search" placeholder="Search player, bank, account…"
           value="<?= clean($search) ?>">
    <select name="status">
        <option value="">All Statuses</option>
        <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>>⏳ Pending</option>
        <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>✅ Approved</option>
        <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>❌ Rejected</option>
    </select>
    <button type="submit" class="btn-primary btn-sm">Filter</button>
    <?php if ($filterStatus || $search): ?>
        <a href="<?= APP_URL ?>/admin/withdrawals.php" class="btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="card">
    <?php if (empty($rows)): ?>
        <div class="empty-state" style="padding:40px 16px;">
            <span style="font-size:36px;">💸</span>
            <span>No withdrawal requests found.</span>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Player</th>
                    <th>Amount</th>
                    <th class="col-hide-md">Bank / Account</th>
                    <th>Status</th>
                    <th class="col-hide-sm">Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $wr): ?>
                <tr id="wr-row-<?= $wr['id'] ?>">
                    <td style="color:var(--muted);font-size:12px;">#<?= $wr['id'] ?></td>
                    <td>
                        <strong><?= clean($wr['username']) ?></strong><br>
                        <span style="font-size:11px;color:var(--muted);"><?= clean($wr['full_name']) ?></span>
                    </td>
                    <td class="wd-amount">₱<?= number_format($wr['amount'], 2) ?></td>
                    <td class="col-hide-md">
                        <div style="font-size:13px;font-weight:600;"><?= clean($wr['bank_name']) ?></div>
                        <div class="wd-bank"><?= clean($wr['account_number']) ?> · <?= clean($wr['account_name']) ?></div>
                    </td>
                    <td>
                        <?php if ($wr['status'] === 'pending'): ?>
                            <span class="badge badge-warn">⏳ Pending</span>
                        <?php elseif ($wr['status'] === 'approved'): ?>
                            <span class="badge badge-success">✅ Approved</span>
                            <?php if ($wr['processed_by_name']): ?>
                            <div style="font-size:10px;color:var(--muted);margin-top:2px;">
                                by <?= clean($wr['processed_by_name']) ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-danger">❌ Rejected</span>
                            <?php if ($wr['admin_note']): ?>
                            <div style="font-size:10px;color:var(--muted);margin-top:2px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                 title="<?= clean($wr['admin_note']) ?>">
                                <?= clean($wr['admin_note']) ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="col-hide-sm" style="font-size:12px;color:var(--muted);white-space:nowrap;">
                        <?= date('M d, h:i A', strtotime($wr['created_at'])) ?>
                    </td>
                    <td>
                        <div class="wd-actions">
                            <button class="btn-outline btn-sm"
                                    onclick="openDetail(<?= htmlspecialchars(json_encode($wr), ENT_QUOTES) ?>)">
                                👁 View
                            </button>
                            <?php if ($wr['status'] === 'pending'): ?>
                                <button class="btn-primary btn-sm"
                                        onclick="openAction(<?= $wr['id'] ?>, 'approved', '<?= clean($wr['username']) ?>', <?= $wr['amount'] ?>)">
                                    ✅ Approve
                                </button>
                                <button class="btn-warn btn-sm"
                                        onclick="openAction(<?= $wr['id'] ?>, 'rejected', '<?= clean($wr['username']) ?>', <?= $wr['amount'] ?>)">
                                    ❌ Reject
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>">← Prev</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++):
            if ($p === 1 || $p === $totalPages || abs($p - $page) <= 1): ?>
                <?php if ($p === $page): ?>
                    <span class="active"><?= $p ?></span>
                <?php else: ?>
                    <a href="?page=<?= $p ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php elseif (abs($p - $page) === 2): ?>
                <span class="dots">…</span>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page+1 ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>">Next →</a>
        <?php endif; ?>
        <span class="dots" style="color:var(--muted);font-size:12px;">
            <?= number_format($totalRows) ?> total
        </span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ── Detail Modal ───────────────────────────────────────────── -->
<div class="modal-overlay" id="detail-modal">
    <div class="modal-box">
        <div class="modal-title">📋 Withdrawal Details</div>
        <div id="detail-body"></div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeModal('detail-modal')">Close</button>
        </div>
    </div>
</div>

<!-- ── Action Modal (approve / reject) ───────────────────────── -->
<div class="modal-overlay" id="action-modal">
    <div class="modal-box">
        <div class="modal-title" id="action-modal-title">Process Withdrawal</div>
        <div id="action-modal-body"></div>
        <div class="modal-field">
            <label>Admin Note (optional)</label>
            <textarea id="action-note" rows="3"
                      placeholder="e.g. Processed via GCash · Ref# 12345678"></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeModal('action-modal')">Cancel</button>
            <button class="btn-primary" id="action-confirm-btn" onclick="submitAction()">Confirm</button>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
let _actionId = 0, _actionStatus = '';

function openDetail(wr) {
    const body = document.getElementById('detail-body');
    const statusBadge = wr.status === 'pending'
        ? '<span class="badge badge-warn">⏳ Pending</span>'
        : wr.status === 'approved'
            ? '<span class="badge badge-success">✅ Approved</span>'
            : '<span class="badge badge-danger">❌ Rejected</span>';

    body.innerHTML = `
        <div class="detail-row"><span class="detail-label">Player</span><span class="detail-val">${esc(wr.full_name)} (@${esc(wr.username)})</span></div>
        <div class="detail-row"><span class="detail-label">Amount</span><span class="detail-val" style="color:var(--accent)">₱${parseFloat(wr.amount).toFixed(2)}</span></div>
        <div class="detail-row"><span class="detail-label">Bank</span><span class="detail-val">${esc(wr.bank_name)}</span></div>
        <div class="detail-row"><span class="detail-label">Account #</span><span class="detail-val">${esc(wr.account_number)}</span></div>
        <div class="detail-row"><span class="detail-label">Account Name</span><span class="detail-val">${esc(wr.account_name)}</span></div>
        <div class="detail-row"><span class="detail-label">Status</span><span class="detail-val">${statusBadge}</span></div>
        <div class="detail-row"><span class="detail-label">Submitted</span><span class="detail-val">${esc(wr.created_at)}</span></div>
        ${wr.admin_note ? `<div class="detail-row"><span class="detail-label">Admin Note</span><span class="detail-val">${esc(wr.admin_note)}</span></div>` : ''}
        ${wr.processed_at ? `<div class="detail-row"><span class="detail-label">Processed</span><span class="detail-val">${esc(wr.processed_at)}</span></div>` : ''}
    `;
    document.getElementById('detail-modal').classList.add('open');
}

function openAction(id, status, username, amount) {
    _actionId     = id;
    _actionStatus = status;
    const isApprove = status === 'approved';
    document.getElementById('action-modal-title').textContent =
        isApprove ? '✅ Approve Withdrawal' : '❌ Reject Withdrawal';
    document.getElementById('action-modal-body').innerHTML = `
        <p style="font-size:14px;margin-bottom:14px;line-height:1.6;">
            ${isApprove
                ? `You are about to <strong style="color:var(--accent)">approve</strong> a withdrawal of <strong>₱${parseFloat(amount).toFixed(2)}</strong> for <strong>${esc(username)}</strong>.<br><br>This will <strong>deduct ₱${parseFloat(amount).toFixed(2)}</strong> from their wallet immediately.`
                : `You are about to <strong style="color:var(--danger)">reject</strong> a withdrawal of <strong>₱${parseFloat(amount).toFixed(2)}</strong> for <strong>${esc(username)}</strong>.<br><br>No wallet change will be made. The player will be notified.`
            }
        </p>
    `;
    const btn = document.getElementById('action-confirm-btn');
    btn.textContent  = isApprove ? '✅ Confirm Approval' : '❌ Confirm Rejection';
    btn.className    = isApprove ? 'btn-primary' : 'btn-warn';
    document.getElementById('action-note').value = '';
    document.getElementById('action-modal').classList.add('open');
}

function submitAction() {
    const note = document.getElementById('action-note').value.trim();
    const btn  = document.getElementById('action-confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Processing…';

    fetch('<?= APP_URL ?>/api/wallet.php', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: _actionId, status: _actionStatus, admin_note: note })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            closeModal('action-modal');
            const row = document.getElementById('wr-row-' + _actionId);
            if (row) {
                const badge = _actionStatus === 'approved'
                    ? '<span class="badge badge-success">✅ Approved</span>'
                    : '<span class="badge badge-danger">❌ Rejected</span>';
                row.querySelector('td:nth-child(5)').innerHTML = badge;
                row.querySelector('.wd-actions').innerHTML = '<span style="font-size:12px;color:var(--muted);">Done</span>';
            }
        } else {
            alert('Error: ' + (data.error?.message || 'Unknown error'));
            btn.disabled    = false;
            btn.textContent = 'Confirm';
        }
    })
    .catch(() => {
        alert('Network error. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Confirm';
    });
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>