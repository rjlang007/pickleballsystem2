<?php
// ============================================================
//  FILE: admin/announcements.php
//  Admin/super_admin CRUD for club-wide announcements
//  (falcon.announcements, see migrations/015_announcements.sql).
//  Posted announcements render for every logged-in account via
//  the banner in includes/header.php, and optionally push a
//  notification to every matching-audience user.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAdmin();

$db      = getDB();
$adminId = (int) $_SESSION['user_id'];
$message = '';
$message_type = '';

$audiences = ['all' => 'Everyone', 'player' => 'Players only', 'staff' => 'Staff only', 'referee' => 'Referees only', 'admin' => 'Admins only'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!checkRateLimit('announcements_' . $adminId, 30, 60)) {
        $message = 'Too many requests. Please slow down.';
        $message_type = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id        = (int) ($_POST['id'] ?? 0);
                $title     = trim($_POST['title'] ?? '');
                $body      = trim($_POST['body'] ?? '');
                $audience  = $_POST['audience'] ?? 'all';
                $isPinned  = isset($_POST['is_pinned']) ? 1 : 0;
                $expiresAt = trim($_POST['expires_at'] ?? '');
                $notifyAll = isset($_POST['notify_all']);

                if (!array_key_exists($audience, $audiences)) $audience = 'all';

                if ($title === '' || $body === '') {
                    $message = 'Title and message are required.';
                    $message_type = 'error';
                } else {
                    $expiresAtVal = $expiresAt !== '' ? $expiresAt : null;

                    if ($id > 0) {
                        $db->prepare(
                            "UPDATE falcon.announcements
                                SET title = ?, body = ?, audience = ?, is_pinned = ?, expires_at = ?, updated_at = NOW()
                              WHERE id = ?"
                        )->execute([$title, $body, $audience, $isPinned, $expiresAtVal, $id]);
                        logActivity('Announcement Updated', 'admin', 'normal', "#{$id}: {$title}");
                        $message = 'Announcement updated.';
                    } else {
                        $ins = $db->prepare(
                            "INSERT INTO falcon.announcements (title, body, audience, is_pinned, created_by, expires_at)
                             VALUES (?, ?, ?, ?, ?, ?)
                             RETURNING id"
                        );
                        $ins->execute([$title, $body, $audience, $isPinned, $adminId, $expiresAtVal]);
                        $id = (int) $ins->fetchColumn();

                        logActivity('Announcement Posted', 'admin', 'normal', "#{$id}: {$title} (audience: {$audience})");
                        $message = 'Announcement posted.';

                        if ($notifyAll) {
                            $roleFilter = $audience === 'all' ? null : $audience;
                            $sql = "SELECT id FROM falcon.users WHERE is_active = TRUE" . ($roleFilter ? " AND role = ?" : "");
                            $uStmt = $db->prepare($sql);
                            $uStmt->execute($roleFilter ? [$roleFilter] : []);
                            $userIds = $uStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                            foreach ($userIds as $uid) {
                                notifyUser($db, (int) $uid, "📢 {$title}", mb_strimwidth($body, 0, 180, '…'), null, APP_URL . '/public/index.php');
                            }
                            $message .= ' Notified ' . count($userIds) . ' user(s).';
                        }
                    }
                    $message_type = 'success';
                }
            } elseif ($action === 'toggle_active') {
                $id = (int) ($_POST['id'] ?? 0);
                $db->prepare("UPDATE falcon.announcements SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?")->execute([$id]);
                logActivity('Announcement Visibility Toggled', 'admin', 'normal', "#{$id}");
                $message = 'Visibility updated.';
                $message_type = 'success';
            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $db->prepare("DELETE FROM falcon.announcements WHERE id = ?")->execute([$id]);
                logActivity('Announcement Deleted', 'admin', 'normal', "#{$id}");
                $message = 'Announcement deleted.';
                $message_type = 'success';
            }
        } catch (PDOException $e) {
            error_log('announcements.php error: ' . $e->getMessage());
            $message = 'Database error while saving the announcement.';
            $message_type = 'error';
        }
    }
}

$list = $db->query(
    "SELECT a.*, COALESCE(u.full_name, u.username) AS author
       FROM falcon.announcements a
       LEFT JOIN falcon.users u ON u.id = a.created_by
      ORDER BY a.is_pinned DESC, a.created_at DESC
      LIMIT 100"
)->fetchAll();

$pageTitle = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>
<style nonce="<?= getCspNonce() ?>">
    /* Theme-matched: every colour comes from the app tokens in assets/css/app.css
       (dark surfaces, light text) so nothing renders white-on-white. */
    .an-wrap{max-width:1000px;margin:0 auto;}
    .an-message{display:flex;gap:10px;align-items:flex-start;padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:16px;font-weight:600;font-size:14px;border:1px solid;border-left-width:4px;}
    .an-message.success{background:rgba(16,185,129,.14);color:#b7f3da;border-color:var(--success);}
    .an-message.error{background:rgba(239,68,68,.14);color:#fecaca;border-color:var(--danger);}
    .an-form-card{margin-bottom:var(--space-lg);}
    .an-form-card .card-title{margin-bottom:var(--space-md);color:var(--text);}
    .an-form-row{display:flex;gap:var(--space-md);flex-wrap:wrap;margin-bottom:var(--space-md);}
    .an-form-row > div{flex:1;min-width:180px;}
    .an-form-card label.an-label{display:block;font-weight:700;font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:var(--text);opacity:.85;margin-bottom:6px;}
    .an-form-card input[type=text],.an-form-card select,.an-form-card textarea,.an-form-card input[type=datetime-local]{
        width:100%;background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:10px;
        padding:11px 14px;font-size:15px;font-family:inherit;box-sizing:border-box;color-scheme:dark;}
    .an-form-card textarea{resize:vertical;min-height:110px;line-height:1.5;}
    .an-form-card input::placeholder,.an-form-card textarea::placeholder{color:var(--muted);}
    .an-checks{display:flex;gap:var(--space-lg);flex-wrap:wrap;margin-bottom:var(--space-md);}
    .an-checkline{display:flex;align-items:center;gap:10px;font-weight:500;font-size:14px;color:var(--text);cursor:pointer;}
    .an-checkline input[type=checkbox]{width:18px;height:18px;accent-color:var(--accent);cursor:pointer;flex-shrink:0;}
    .an-item{display:flex;justify-content:space-between;gap:var(--space-md);align-items:flex-start;margin-bottom:var(--space-md);}
    .an-item.inactive{border-style:dashed;}
    .an-item.inactive .an-body{opacity:.75;}
    .an-item h3{margin:0 0 6px;font-size:18px;color:var(--text);}
    .an-meta{font-size:13px;color:var(--muted);margin-bottom:10px;display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;}
    .an-body{color:var(--text);line-height:1.6;font-size:15px;overflow-wrap:anywhere;}
    .an-pin{color:var(--warn);}
    .an-actions{display:flex;flex-direction:column;gap:8px;min-width:110px;flex-shrink:0;}
    .an-actions form{margin:0;}
    .an-actions .btn-sm{width:100%;justify-content:center;}
    .an-empty{text-align:center;color:var(--muted);padding:var(--space-xl) var(--space-lg);font-size:15px;}
    @media (max-width:640px){
        .an-item{flex-direction:column;}
        .an-actions{flex-direction:row;width:100%;}
        .an-actions form{flex:1;}
    }
</style>

<div class="an-wrap">
    <div class="page-header">
        <h1>📢 Announcements</h1>
        <p>Post club-wide notices. They appear as a banner for every matching account.</p>
    </div>

    <?php if ($message): ?>
        <div class="an-message <?= $message_type === 'success' ? 'success' : 'error' ?>" role="status">
            <span aria-hidden="true"><?= $message_type === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= clean($message) ?></span>
        </div>
    <?php endif; ?>

    <div class="card an-form-card">
        <div class="card-title">Post a new announcement</div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <div class="an-form-row">
                <div style="flex:2;">
                    <label class="an-label" for="an-title">Title</label>
                    <input type="text" id="an-title" name="title" maxlength="200" required>
                </div>
                <div>
                    <label class="an-label" for="an-audience">Audience</label>
                    <select id="an-audience" name="audience">
                        <?php foreach ($audiences as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="an-label" for="an-expires">Expires (optional)</label>
                    <input type="datetime-local" id="an-expires" name="expires_at">
                </div>
            </div>
            <div class="an-form-row">
                <div style="flex:1 1 100%;">
                    <label class="an-label" for="an-body">Message</label>
                    <textarea id="an-body" name="body" rows="4" required></textarea>
                </div>
            </div>
            <div class="an-checks">
                <label class="an-checkline"><input type="checkbox" name="is_pinned"> <span>📌 Pin to top</span></label>
                <label class="an-checkline"><input type="checkbox" name="notify_all"> <span>🔔 Also send as a notification to every matching account</span></label>
            </div>
            <button type="submit" class="btn-primary">Post announcement</button>
        </form>
    </div>

    <?php foreach ($list as $a): ?>
        <div class="card an-item <?= $a['is_active'] ? '' : 'inactive' ?>">
            <div style="min-width:0;flex:1;">
                <h3><?= $a['is_pinned'] ? '<span class="an-pin" aria-label="Pinned">📌</span> ' : '' ?><?= clean($a['title']) ?></h3>
                <div class="an-meta">
                    <span class="badge badge-info"><?= clean($audiences[$a['audience']] ?? $a['audience']) ?></span>
                    <span>by <?= clean($a['author'] ?? 'Unknown') ?></span>
                    <span>· <?= date('M j, Y g:ia', strtotime($a['created_at'])) ?></span>
                    <?php if ($a['expires_at']): ?><span>· expires <?= date('M j, Y g:ia', strtotime($a['expires_at'])) ?></span><?php endif; ?>
                    <?php if (!$a['is_active']): ?><span class="badge badge-warn">Hidden</span><?php endif; ?>
                </div>
                <div class="an-body"><?= nl2br(clean($a['body'])) ?></div>
            </div>
            <div class="an-actions">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn-secondary btn-sm"><?= $a['is_active'] ? 'Hide' : 'Show' ?></button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this announcement?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($list)): ?>
        <div class="card an-empty">No announcements yet — post the first one above.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
