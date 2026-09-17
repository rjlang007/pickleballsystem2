<?php
// ============================================================
//  FILE: admin/disputes.php
//  Admin — Dispute Management
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
        COUNT(*)                                           AS total,
        COUNT(*) FILTER (WHERE status = 'open')           AS open,
        COUNT(*) FILTER (WHERE status = 'resolved')       AS resolved,
        COUNT(*) FILTER (WHERE status = 'dismissed')      AS dismissed
    FROM falcon.disputes
")->fetch();

// ── Filters ───────────────────────────────────────────────────
$filterStatus = trim($_GET['status'] ?? 'open');
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterStatus !== '' && in_array($filterStatus, ['open','resolved','dismissed'], true)) {
    $where[]  = 'd.status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[]  = "(u.username ILIKE ? OR u.full_name ILIKE ? OR d.reason ILIKE ?)";
    $s        = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s;
}

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM falcon.disputes d
       JOIN falcon.users u ON u.id = d.filed_by
      WHERE {$whereClause}"
);
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$listParams = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare(
    "SELECT d.id, d.reason, d.status, d.admin_note, d.created_at, d.resolved_at,
            d.reservation_id,
            u.id AS player_id, u.username, u.full_name,
            r.slot_date, LEFT(r.slot_time::text,5) AS slot_time, r.party_size,
            c.name AS court_name,
            pa.username AS resolved_by_name
       FROM falcon.disputes d
       JOIN falcon.users u ON u.id = d.filed_by
       JOIN falcon.reservations r ON r.id = d.reservation_id
       JOIN falcon.courts c ON c.id = r.court_id
  LEFT JOIN falcon.users pa ON pa.id = d.resolved_by
      WHERE {$whereClause}
      ORDER BY
            CASE d.status WHEN 'open' THEN 0 ELSE 1 END,
            d.created_at DESC
      LIMIT ? OFFSET ?"
);
$stmt->execute($listParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Dispute Management';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.dp-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.dp-filter-bar {
    display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px;
}
.dp-filter-bar input, .dp-filter-bar select {
    background:var(--surface2); border:1px solid var(--border);
    color:var(--text); border-radius:8px; padding:8px 12px; font-size:13px; outline:none;
}
.dp-filter-bar input { flex:1; min-width:180px; }
.dp-filter-bar input:focus, .dp-filter-bar select:focus { border-color:var(--accent); }
.reason-cell {
    max-width:220px; white-space:nowrap; overflow:hidden;
    text-overflow:ellipsis; font-size:12px; color:var(--muted); cursor:pointer;
}
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.65); z-index:1000;
    align-items:center; justify-content:center; padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:var(--surface); border:1px solid var(--border);
    border-radius:16px; padding:24px; width:100%; max-width:480px;
    box-shadow:0 8px 40px rgba(0,0,0,.4); max-height:90vh; overflow-y:auto;
}
.modal-title { font-family:'Bebas Neue',sans-serif; font-size:20px; letter-spacing:1px; margin-bottom:16px; }
.modal-field { margin-bottom:14px; }
.modal-field label { display:block; font-size:12px; color:var(--muted); margin-bottom:5px; font-weight:600; }
.modal-field textarea, .modal-field select {
    width:100%; background:var(--surface2); border:1px solid var(--border);
    color:var(--text); border-radius:8px; padding:10px 12px;
    font-size:13px; font-family:inherit; resize:vertical; outline:none; box-sizing:border-box;
}
.modal-field textarea:focus, .modal-field select:focus { border-color:var(--accent); }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
.detail-row { display:flex; gap:8px; margin-bottom:10px; font-size:13px; }
.detail-label { color:var(--muted); min-width:120px; flex-shrink:0; font-weight:600; }
.detail-val   { word-break:break-word; }
.reason-box {
    background:var(--surface2); border:1px solid var(--border); border-radius:8px;
    padding:12px; font-size:13px; line-height:1.6; margin-bottom:14px; color:var(--text);
}
.pagination { display:flex; gap:6px; align-items:center; margin-top:16px; flex-wrap:wrap; }
.pagination a, .pagination span {
    padding:6px 12px; border-radius:8px; font-size:13px;
    background:var(--surface2); border:1px solid var(--border); color:var(--text); text-decoration:none;
}
.pagination a:hover { border-color:var(--accent); }
.pagination span.active { background:var(--accent); color:var(--bg); border-color:var(--accent); font-weight:700; }
.pagination span.dots { border:none; background:none; color:var(--muted); }
@media(max-width:768px){
    .dp-stats{ grid-template-columns:repeat(2,1fr); }
    .col-hide-md { display:none; }
}
@media(max-width:480px){
    .dp-stats{ grid-template-columns:1fr 1fr; gap:8px; }
    .col-hide-sm { display:none; }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>⚖️ Dispute Management</h1>
        <p style="margin:0;font-size:14px;color:var(--muted);">
            Review and resolve player disputes for no-show reservations
        </p>
    </div>
    <?php if ($stats['open'] > 0): ?>
    <span class="badge badge-danger" style="font-size:14px;padding:6px 14px;">
        🔴 <?= $stats['open'] ?> Open
    </span>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="dp-stats">
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);"><?= number_format($stats['open']) ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);"><?= number_format($stats['resolved']) ?></div>
        <div class="stat-label">Resolved</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--muted);"><?= number_format($stats['dismissed']) ?></div>
        <div class="stat-label">Dismissed</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['total']) ?></div>
        <div class="stat-label">Total</div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="dp-filter-bar">
    <input type="text" name="search" placeholder="Search player, reason…"
           value="<?= clean($search) ?>">
    <select name="status">
        <option value="">All Statuses</option>
        <option value="open"      <?= $filterStatus === 'open'      ? 'selected' : '' ?>>🔴 Open</option>
        <option value="resolved"  <?= $filterStatus === 'resolved'  ? 'selected' : '' ?>>✅ Resolved</option>
        <option value="dismissed" <?= $filterStatus === 'dismissed' ? 'selected' : '' ?>>🚫 Dismissed</option>
    </select>
    <button type="submit" class="btn-primary btn-sm">Filter</button>
    <?php if ($search || ($filterStatus && $filterStatus !== 'open')): ?>
        <a href="<?= APP_URL ?>/admin/disputes.php" class="btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="card">
    <?php if (empty($rows)): ?>
        <div class="empty-state" style="padding:40px 16px;">
            <span style="font-size:36px;">⚖️</span>
            <span>No disputes found.</span>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Player</th>
                    <th class="col-hide-md">Reservation</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th class="col-hide-sm">Filed</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $d): ?>
                <tr id="dp-row-<?= $d['id'] ?>">
                    <td style="color:var(--muted);font-size:12px;">#<?= $d['id'] ?></td>
                    <td>
                        <strong><?= clean($d['username']) ?></strong><br>
                        <span style="font-size:11px;color:var(--muted);"><?= clean($d['full_name']) ?></span>
                    </td>
                    <td class="col-hide-md">
                        <div style="font-size:13px;font-weight:600;"><?= clean($d['court_name']) ?></div>
                        <div class="wd-bank">
                            <?= date('M d, Y', strtotime($d['slot_date'])) ?>
                            · <?= date('g:i A', strtotime($d['slot_time'])) ?>
                            · <?= $d['party_size'] ?> player<?= $d['party_size'] > 1 ? 's' : '' ?>
                        </div>
                    </td>
                    <td>
                        <div class="reason-cell"
                             onclick="openDetail(<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>)"
                             title="Click to view full reason">
                            <?= clean($d['reason']) ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($d['status'] === 'open'): ?>
                            <span class="badge badge-danger">🔴 Open</span>
                        <?php elseif ($d['status'] === 'resolved'): ?>
                            <span class="badge badge-success">✅ Resolved</span>
                            <?php if ($d['resolved_by_name']): ?>
                            <div style="font-size:10px;color:var(--muted);margin-top:2px;">
                                by <?= clean($d['resolved_by_name']) ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-muted">🚫 Dismissed</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-hide-sm" style="font-size:12px;color:var(--muted);white-space:nowrap;">
                        <?= date('M d, h:i A', strtotime($d['created_at'])) ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="btn-outline btn-sm"
                                    onclick="openDetail(<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>)">
                                👁 View
                            </button>
                            <?php if ($d['status'] === 'open'): ?>
                                <button class="btn-primary btn-sm"
                                        onclick="openResolve(<?= $d['id'] ?>, '<?= clean($d['username']) ?>')">
                                    ⚖️ Resolve
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

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
        <span class="dots" style="color:var(--muted);font-size:12px;"><?= number_format($totalRows) ?> total</span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div class="modal-overlay" id="detail-modal">
    <div class="modal-box">
        <div class="modal-title">⚖️ Dispute Details</div>
        <div id="detail-body"></div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeModal('detail-modal')">Close</button>
        </div>
    </div>
</div>

<!-- Resolve Modal -->
<div class="modal-overlay" id="resolve-modal">
    <div class="modal-box">
        <div class="modal-title" id="resolve-modal-title">Resolve Dispute</div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;" id="resolve-modal-sub"></p>
        <div class="modal-field">
            <label>Decision</label>
            <select id="resolve-status">
                <option value="resolved">✅ Resolve — rule in player's favour</option>
                <option value="dismissed">🚫 Dismiss — no-show stands</option>
            </select>
        </div>
        <div class="modal-field">
            <label>Admin Note (shown to player)</label>
            <textarea id="resolve-note" rows="3"
                      placeholder="Explain your decision…"></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeModal('resolve-modal')">Cancel</button>
            <button class="btn-primary" id="resolve-confirm-btn" onclick="submitResolve()">Submit Decision</button>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
let _resolveId = 0;

function openDetail(d) {
    const statusBadge = d.status === 'open'
        ? '<span class="badge badge-danger">🔴 Open</span>'
        : d.status === 'resolved'
            ? '<span class="badge badge-success">✅ Resolved</span>'
            : '<span class="badge badge-muted">🚫 Dismissed</span>';

    document.getElementById('detail-body').innerHTML = `
        <div class="detail-row"><span class="detail-label">Player</span><span class="detail-val">${esc(d.full_name)} (@${esc(d.username)})</span></div>
        <div class="detail-row"><span class="detail-label">Court</span><span class="detail-val">${esc(d.court_name)}</span></div>
        <div class="detail-row"><span class="detail-label">Slot</span><span class="detail-val">${esc(d.slot_date)} at ${esc(d.slot_time)}</span></div>
        <div class="detail-row"><span class="detail-label">Status</span><span class="detail-val">${statusBadge}</span></div>
        <div class="detail-row"><span class="detail-label">Filed</span><span class="detail-val">${esc(d.created_at)}</span></div>
        <div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600;">Player's Reason</div>
        <div class="reason-box">${esc(d.reason)}</div>
        ${d.admin_note ? `<div class="detail-row"><span class="detail-label">Admin Note</span><span class="detail-val">${esc(d.admin_note)}</span></div>` : ''}
        ${d.resolved_at ? `<div class="detail-row"><span class="detail-label">Resolved</span><span class="detail-val">${esc(d.resolved_at)}</span></div>` : ''}
    `;
    document.getElementById('detail-modal').classList.add('open');
}

function openResolve(id, username) {
    _resolveId = id;
    document.getElementById('resolve-modal-sub').textContent =
        `Dispute #${id} filed by ${username}`;
    document.getElementById('resolve-note').value = '';
    document.getElementById('resolve-status').value = 'resolved';
    document.getElementById('resolve-modal').classList.add('open');
}

function submitResolve() {
    const status = document.getElementById('resolve-status').value;
    const note   = document.getElementById('resolve-note').value.trim();
    const btn    = document.getElementById('resolve-confirm-btn');
    btn.disabled    = true;
    btn.textContent = 'Submitting…';

    fetch('<?= APP_URL ?>/api/disputes.php', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: _resolveId, status, admin_note: note })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            closeModal('resolve-modal');
            const row = document.getElementById('dp-row-' + _resolveId);
            if (row) {
                const badge = status === 'resolved'
                    ? '<span class="badge badge-success">✅ Resolved</span>'
                    : '<span class="badge badge-muted">🚫 Dismissed</span>';
                row.querySelector('td:nth-child(5)').innerHTML = badge;
                row.querySelector('td:last-child').innerHTML =
                    '<span style="font-size:12px;color:var(--muted);">Done</span>';
            }
        } else {
            alert('Error: ' + (data.error?.message || 'Unknown error'));
            btn.disabled    = false;
            btn.textContent = 'Submit Decision';
        }
    })
    .catch(() => {
        alert('Network error. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Submit Decision';
    });
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>