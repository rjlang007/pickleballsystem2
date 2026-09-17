<?php
// ============================================================
//  FILE: admin/community.php
//  Admin — Community Feed Moderation
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
        COUNT(*)                                           AS total_posts,
        COUNT(*) FILTER (WHERE is_hidden = FALSE)         AS visible,
        COUNT(*) FILTER (WHERE is_hidden = TRUE)          AS hidden,
        COUNT(*) FILTER (WHERE is_pinned = TRUE)          AS pinned,
        (SELECT COUNT(*) FROM falcon.post_comments)       AS total_comments
    FROM falcon.community_posts
")->fetch();

// ── Filters ───────────────────────────────────────────────────
$filterVis  = trim($_GET['visibility'] ?? '');
$filterPin  = trim($_GET['pinned']     ?? '');
$search     = trim($_GET['search']     ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterVis === 'hidden') {
    $where[] = 'p.is_hidden = TRUE';
} elseif ($filterVis === 'visible') {
    $where[] = 'p.is_hidden = FALSE';
}
if ($filterPin === '1') {
    $where[] = 'p.is_pinned = TRUE';
}
if ($search !== '') {
    $where[]  = "(u.username ILIKE ? OR u.full_name ILIKE ? OR p.content ILIKE ?)";
    $s        = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s;
}

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM falcon.community_posts p
       JOIN falcon.users u ON u.id = p.user_id
      WHERE {$whereClause}"
);
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$listParams = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare(
    "SELECT p.id, p.content, p.media_url, p.is_pinned, p.is_hidden,
            p.created_at, p.updated_at,
            u.id AS user_id, u.username, u.full_name,
            COALESCE(c.cnt, 0) AS comment_count
       FROM falcon.community_posts p
       JOIN falcon.users u ON u.id = p.user_id
  LEFT JOIN (SELECT post_id, COUNT(*) AS cnt FROM falcon.post_comments GROUP BY post_id) c
         ON c.post_id = p.id
      WHERE {$whereClause}
      ORDER BY p.is_pinned DESC, p.created_at DESC
      LIMIT ? OFFSET ?"
);
$stmt->execute($listParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Community Moderation';
require_once __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= getCspNonce() ?>">
.cm-stats { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
.cm-filter-bar {
    display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px;
}
.cm-filter-bar input, .cm-filter-bar select {
    background:var(--surface2); border:1px solid var(--border);
    color:var(--text); border-radius:8px; padding:8px 12px; font-size:13px; outline:none;
}
.cm-filter-bar input { flex:1; min-width:180px; }
.cm-filter-bar input:focus, .cm-filter-bar select:focus { border-color:var(--accent); }

/* Post card grid */
.cm-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));
    gap:16px;
    margin-bottom:20px;
}
.cm-post-card {
    background:var(--surface); border:1px solid var(--border);
    border-radius:14px; padding:16px; display:flex; flex-direction:column;
    gap:10px; transition:border-color .2s;
    position:relative;
}
.cm-post-card:hover { border-color:rgba(0,229,160,.2); }
.cm-post-card.hidden-post {
    opacity:.65; border-color:rgba(255,100,100,.25);
    background:rgba(255,80,80,.03);
}
.cm-post-card.pinned-post { border-color:rgba(0,229,160,.35); }
.cm-post-header {
    display:flex; align-items:flex-start; gap:10px;
}
.cm-avatar {
    width:38px; height:38px; border-radius:50%;
    background:var(--accent); color:var(--bg);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:15px; flex-shrink:0;
    font-family:'Bebas Neue',sans-serif;
}
.cm-author { flex:1; min-width:0; }
.cm-author-name { font-weight:700; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cm-author-meta { font-size:11px; color:var(--muted); margin-top:2px; }
.cm-badges { display:flex; gap:5px; flex-shrink:0; }
.cm-content {
    font-size:13px; line-height:1.65; color:var(--text);
    overflow:hidden; display:-webkit-box;
    -webkit-line-clamp:4; -webkit-box-orient:vertical;
}
.cm-content.expanded { -webkit-line-clamp:unset; }
.cm-footer {
    display:flex; align-items:center; justify-content:space-between;
    gap:8px; flex-wrap:wrap; margin-top:4px;
}
.cm-meta-chips { display:flex; gap:8px; font-size:11px; color:var(--muted); flex-wrap:wrap; }
.cm-actions { display:flex; gap:6px; flex-wrap:wrap; }
.cm-pin-indicator {
    position:absolute; top:10px; right:10px;
    font-size:16px;
}
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.65); z-index:1000;
    align-items:center; justify-content:center; padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:var(--surface); border:1px solid var(--border);
    border-radius:16px; padding:24px; width:100%; max-width:520px;
    box-shadow:0 8px 40px rgba(0,0,0,.4); max-height:90vh; overflow-y:auto;
}
.modal-title { font-family:'Bebas Neue',sans-serif; font-size:20px; letter-spacing:1px; margin-bottom:16px; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; flex-wrap:wrap; }
.full-content-box {
    background:var(--surface2); border:1px solid var(--border); border-radius:8px;
    padding:14px; font-size:13px; line-height:1.7; white-space:pre-wrap;
    word-break:break-word; margin-bottom:14px; max-height:300px; overflow-y:auto;
}
.comments-list { display:flex; flex-direction:column; gap:10px; }
.comment-item {
    background:var(--surface2); border-radius:8px; padding:10px 12px;
    font-size:13px;
}
.comment-meta { font-size:11px; color:var(--muted); margin-top:4px; }
.pagination { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.pagination a, .pagination span {
    padding:6px 12px; border-radius:8px; font-size:13px;
    background:var(--surface2); border:1px solid var(--border); color:var(--text); text-decoration:none;
}
.pagination a:hover { border-color:var(--accent); }
.pagination span.active { background:var(--accent); color:var(--bg); border-color:var(--accent); font-weight:700; }
.pagination span.dots { border:none; background:none; color:var(--muted); }
@media(max-width:900px){ .cm-stats{ grid-template-columns:repeat(3,1fr); } }
@media(max-width:600px){
    .cm-stats{ grid-template-columns:repeat(2,1fr); gap:8px; }
    .cm-grid{ grid-template-columns:1fr; }
}
</style>

<div class="page-header flex-between" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1>📣 Community Moderation</h1>
        <p style="margin:0;font-size:14px;color:var(--muted);">
            Pin, hide, delete and review community posts
        </p>
    </div>
    <?php if ($stats['hidden'] > 0): ?>
    <span class="badge badge-warn" style="font-size:14px;padding:6px 14px;">
        🙈 <?= $stats['hidden'] ?> Hidden
    </span>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="cm-stats">
    <div class="stat-card">
        <div class="stat-val"><?= number_format($stats['total_posts']) ?></div>
        <div class="stat-label">Total Posts</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--accent);"><?= number_format($stats['visible']) ?></div>
        <div class="stat-label">Visible</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--danger);"><?= number_format($stats['hidden']) ?></div>
        <div class="stat-label">Hidden</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--warn);"><?= number_format($stats['pinned']) ?></div>
        <div class="stat-label">Pinned</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:var(--muted);"><?= number_format($stats['total_comments']) ?></div>
        <div class="stat-label">Comments</div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="cm-filter-bar">
    <input type="text" name="search" placeholder="Search player or content…"
           value="<?= clean($search) ?>">
    <select name="visibility">
        <option value="">All Visibility</option>
        <option value="visible" <?= $filterVis === 'visible' ? 'selected' : '' ?>>👁 Visible</option>
        <option value="hidden"  <?= $filterVis === 'hidden'  ? 'selected' : '' ?>>🙈 Hidden</option>
    </select>
    <select name="pinned">
        <option value="">All Posts</option>
        <option value="1" <?= $filterPin === '1' ? 'selected' : '' ?>>📌 Pinned Only</option>
    </select>
    <button type="submit" class="btn-primary btn-sm">Filter</button>
    <?php if ($search || $filterVis || $filterPin): ?>
        <a href="<?= APP_URL ?>/admin/community.php" class="btn-outline btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!-- Post Cards -->
<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty-state" style="padding:40px 16px;">
            <span style="font-size:36px;">📣</span>
            <span>No posts found.</span>
        </div>
    </div>
<?php else: ?>
<div class="cm-grid">
    <?php foreach ($rows as $p):
        $initials = strtoupper(substr($p['full_name'] ?: $p['username'], 0, 1));
    ?>
    <div class="cm-post-card <?= $p['is_hidden'] ? 'hidden-post' : '' ?> <?= $p['is_pinned'] ? 'pinned-post' : '' ?>"
         id="cm-card-<?= $p['id'] ?>">

        <?php if ($p['is_pinned']): ?>
            <div class="cm-pin-indicator" title="Pinned post">📌</div>
        <?php endif; ?>

        <div class="cm-post-header">
            <div class="cm-avatar"><?= $initials ?></div>
            <div class="cm-author">
                <div class="cm-author-name"><?= clean($p['full_name']) ?: clean($p['username']) ?></div>
                <div class="cm-author-meta">
                    @<?= clean($p['username']) ?>
                    · <?= date('M d, Y · g:i A', strtotime($p['created_at'])) ?>
                </div>
            </div>
            <div class="cm-badges">
                <?php if ($p['is_hidden']): ?>
                    <span class="badge badge-danger" style="font-size:10px;">Hidden</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="cm-content" id="cm-content-<?= $p['id'] ?>">
            <?= clean($p['content']) ?>
        </div>
        <?php if (mb_strlen($p['content']) > 200): ?>
            <button class="btn-outline btn-sm" style="align-self:flex-start;font-size:11px;"
                    onclick="toggleExpand(<?= $p['id'] ?>)">
                Show more
            </button>
        <?php endif; ?>

        <?php if ($p['media_url']): ?>
            <div style="font-size:11px;color:var(--accent2);">
                🖼 <a href="<?= clean($p['media_url']) ?>" target="_blank"
                      style="color:var(--accent2);">View Media</a>
            </div>
        <?php endif; ?>

        <div class="cm-footer">
            <div class="cm-meta-chips">
                <span>💬 <?= $p['comment_count'] ?> comment<?= $p['comment_count'] != 1 ? 's' : '' ?></span>
                <?php if ($p['is_pinned']): ?><span style="color:var(--warn);">📌 Pinned</span><?php endif; ?>
            </div>
            <div class="cm-actions">
                <button class="btn-outline btn-sm"
                        onclick="viewPost(<?= $p['id'] ?>)">
                    💬 View
                </button>
                <button class="btn-outline btn-sm"
                        id="pin-btn-<?= $p['id'] ?>"
                        onclick="togglePin(<?= $p['id'] ?>, <?= $p['is_pinned'] ? 'true' : 'false' ?>)">
                    <?= $p['is_pinned'] ? '📌 Unpin' : '📌 Pin' ?>
                </button>
                <button class="btn-outline btn-sm"
                        id="hide-btn-<?= $p['id'] ?>"
                        onclick="toggleHide(<?= $p['id'] ?>, <?= $p['is_hidden'] ? 'true' : 'false' ?>)">
                    <?= $p['is_hidden'] ? '👁 Unhide' : '🙈 Hide' ?>
                </button>
                <button class="btn-warn btn-sm"
                        onclick="confirmDelete(<?= $p['id'] ?>, '<?= clean($p['username']) ?>')">
                    🗑 Delete
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page-1 ?>&visibility=<?= urlencode($filterVis) ?>&pinned=<?= urlencode($filterPin) ?>&search=<?= urlencode($search) ?>">← Prev</a>
    <?php endif; ?>
    <?php for ($p2 = 1; $p2 <= $totalPages; $p2++):
        if ($p2 === 1 || $p2 === $totalPages || abs($p2 - $page) <= 1): ?>
            <?php if ($p2 === $page): ?>
                <span class="active"><?= $p2 ?></span>
            <?php else: ?>
                <a href="?page=<?= $p2 ?>&visibility=<?= urlencode($filterVis) ?>&pinned=<?= urlencode($filterPin) ?>&search=<?= urlencode($search) ?>"><?= $p2 ?></a>
            <?php endif; ?>
        <?php elseif (abs($p2 - $page) === 2): ?>
            <span class="dots">…</span>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page+1 ?>&visibility=<?= urlencode($filterVis) ?>&pinned=<?= urlencode($filterPin) ?>&search=<?= urlencode($search) ?>">Next →</a>
    <?php endif; ?>
    <span class="dots" style="color:var(--muted);font-size:12px;"><?= number_format($totalRows) ?> total</span>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- View Post Modal -->
<div class="modal-overlay" id="view-modal">
    <div class="modal-box">
        <div class="modal-title">📣 Post Detail</div>
        <div id="view-modal-body"></div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeModal('view-modal')">Close</button>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="delete-modal">
    <div class="modal-box">
        <div class="modal-title">🗑 Delete Post</div>
        <p style="font-size:14px;margin-bottom:20px;" id="delete-modal-body"></p>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeModal('delete-modal')">Cancel</button>
            <button class="btn-warn" id="delete-confirm-btn" onclick="submitDelete()">Delete</button>
        </div>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
let _deleteId = 0;

function toggleExpand(id) {
    const el  = document.getElementById('cm-content-' + id);
    const btn = el.nextElementSibling;
    if (!el) return;
    el.classList.toggle('expanded');
    btn.textContent = el.classList.contains('expanded') ? 'Show less' : 'Show more';
}

function togglePin(id, isPinned) {
    const newVal = !isPinned;
    fetch('<?= APP_URL ?>/api/community.php', {
        method: 'PATCH',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id, is_pinned: newVal })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const btn  = document.getElementById('pin-btn-' + id);
            const card = document.getElementById('cm-card-' + id);
            if (newVal) {
                btn.textContent = '📌 Unpin';
                card.classList.add('pinned-post');
            } else {
                btn.textContent = '📌 Pin';
                card.classList.remove('pinned-post');
            }
            btn.onclick = () => togglePin(id, newVal);
        } else {
            alert('Error: ' + (data.error?.message || 'Unknown error'));
        }
    });
}

function toggleHide(id, isHidden) {
    const newVal = !isHidden;
    fetch('<?= APP_URL ?>/api/community.php', {
        method: 'PATCH',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id, is_hidden: newVal })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const btn  = document.getElementById('hide-btn-' + id);
            const card = document.getElementById('cm-card-' + id);
            if (newVal) {
                btn.textContent = '👁 Unhide';
                card.classList.add('hidden-post');
            } else {
                btn.textContent = '🙈 Hide';
                card.classList.remove('hidden-post');
            }
            btn.onclick = () => toggleHide(id, newVal);
        } else {
            alert('Error: ' + (data.error?.message || 'Unknown error'));
        }
    });
}

function viewPost(id) {
    fetch('<?= APP_URL ?>/api/community.php?post_id=' + id)
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { alert('Failed to load post'); return; }
        const p = data.data;
        const comments = (p.comments || []).map(c => `
            <div class="comment-item">
                <strong>${esc(c.commenter_name)}</strong>
                <span style="color:var(--muted);font-size:11px;"> @${esc(c.commenter_username)}</span>
                <div style="margin-top:4px;">${esc(c.content)}</div>
                <div class="comment-meta">${esc(c.created_at)}</div>
            </div>
        `).join('');

        document.getElementById('view-modal-body').innerHTML = `
            <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">
                By <strong>${esc(p.author_name)}</strong> (@${esc(p.author_username)})
                · ${esc(p.created_at)}
            </div>
            <div class="full-content-box">${esc(p.content)}</div>
            ${p.comments && p.comments.length > 0
                ? `<div style="font-size:12px;color:var(--muted);margin-bottom:8px;font-weight:600;">
                       💬 ${p.comments.length} Comment${p.comments.length !== 1 ? 's' : ''}
                   </div>
                   <div class="comments-list">${comments}</div>`
                : '<div style="font-size:13px;color:var(--muted);">No comments yet.</div>'
            }
        `;
        document.getElementById('view-modal').classList.add('open');
    });
}

function confirmDelete(id, username) {
    _deleteId = id;
    document.getElementById('delete-modal-body').innerHTML =
        `Are you sure you want to permanently delete the post by <strong>${esc(username)}</strong>?<br>
         <span style="color:var(--muted);font-size:12px;">All comments on this post will also be deleted.</span>`;
    document.getElementById('delete-modal').classList.add('open');
}

function submitDelete() {
    const btn = document.getElementById('delete-confirm-btn');
    btn.disabled    = true;
    btn.textContent = 'Deleting…';

    fetch('<?= APP_URL ?>/api/community.php?id=' + _deleteId, { method: 'DELETE' })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            closeModal('delete-modal');
            const card = document.getElementById('cm-card-' + _deleteId);
            if (card) card.remove();
        } else {
            alert('Error: ' + (data.error?.message || 'Unknown error'));
            btn.disabled    = false;
            btn.textContent = 'Delete';
        }
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