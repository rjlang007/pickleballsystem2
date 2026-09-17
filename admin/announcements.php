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
    .an-wrap{max-width:1000px;margin:24px auto;padding:0 16px;}
    .an-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
    .an-header h1{margin:0;}
    .an-message{padding:12px;border-radius:6px;margin-bottom:14px;font-weight:500;}
    .an-message.success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
    .an-message.error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
    .an-form-card,.an-item{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:18px;margin-bottom:16px;}
    .an-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
    .an-form-row > div{flex:1;min-width:160px;}
    .an-form-card label{display:block;font-weight:600;font-size:13px;margin-bottom:5px;}
    .an-form-card input[type=text],.an-form-card select,.an-form-card textarea,.an-form-card input[type=datetime-local]{
        width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box;}
    .an-checkline{display:flex;align-items:center;gap:8px;font-weight:500;font-size:13px;}
    .an-submit{background:#3498db;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:600;cursor:pointer;}
    .an-item{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;}
    .an-item.inactive{opacity:.55;}
    .an-item h3{margin:0 0 4px;}
    .an-meta{font-size:12px;color:#888;margin-bottom:8px;}
    .an-pin{color:#e67e22;font-weight:700;}
    .an-actions{display:flex;flex-direction:column;gap:6px;min-width:110px;}
    .an-actions button{padding:6px 10px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;}
    .an-btn-toggle{background:#95a5a6;color:#fff;}
    .an-btn-delete{background:#e74c3c;color:#fff;}
    .an-audience-badge{display:inline-block;background:#eef4fb;color:#2c6fbb;border-radius:12px;padding:2px 10px;font-size:11px;font-weight:600;}
</style>

<div class="an-wrap">
    <div class="an-header">
        <h1>📢 Announcements</h1>
    </div>

    <?php if ($message): ?>
        <div class="an-message <?= $message_type === 'success' ? 'success' : 'error' ?>"><?= clean($message) ?></div>
    <?php endif; ?>

    <div class="an-form-card">
        <h3 style="margin-top:0;">Post a new announcement</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <div class="an-form-row">
                <div style="flex:2;">
                    <label>Title</label>
                    <input type="text" name="title" maxlength="200" required>
                </div>
                <div>
                    <label>Audience</label>
                    <select name="audience">
                        <?php foreach ($audiences as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Expires (optional)</label>
                    <input type="datetime-local" name="expires_at">
                </div>
            </div>
            <div class="an-form-row">
                <div style="flex:1 1 100%;">
                    <label>Message</label>
                    <textarea name="body" rows="4" required></textarea>
                </div>
            </div>
            <div class="an-form-row" style="align-items:center;">
                <label class="an-checkline"><input type="checkbox" name="is_pinned"> 📌 Pin to top</label>
                <label class="an-checkline"><input type="checkbox" name="notify_all"> 🔔 Also send as a notification to every matching account</label>
            </div>
            <button type="submit" class="an-submit">Post announcement</button>
        </form>
    </div>

    <?php foreach ($list as $a): ?>
        <div class="an-item <?= $a['is_active'] ? '' : 'inactive' ?>">
            <div>
                <h3><?= $a['is_pinned'] ? '<span class="an-pin">📌</span> ' : '' ?><?= clean($a['title']) ?></h3>
                <div class="an-meta">
                    <span class="an-audience-badge"><?= clean($audiences[$a['audience']] ?? $a['audience']) ?></span>
                    · by <?= clean($a['author'] ?? 'Unknown') ?>
                    · <?= date('M j, Y g:ia', strtotime($a['created_at'])) ?>
                    <?php if ($a['expires_at']): ?> · expires <?= date('M j, Y g:ia', strtotime($a['expires_at'])) ?><?php endif; ?>
                    <?php if (!$a['is_active']): ?> · <strong>hidden</strong><?php endif; ?>
                </div>
                <div><?= nl2br(clean($a['body'])) ?></div>
            </div>
            <div class="an-actions">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="an-btn-toggle"><?= $a['is_active'] ? 'Hide' : 'Show' ?></button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this announcement?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="an-btn-delete">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($list)): ?>
        <div class="an-item">No announcements yet — post the first one above.</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
