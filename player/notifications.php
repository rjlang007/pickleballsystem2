<?php
// ============================================================
//  FILE: player/notifications.php
//  Full notification history page for players
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// Mark all as read on page load
$db->prepare("UPDATE falcon.notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE")->execute([$uid]);

$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 30;
$offset = ($page - 1) * $limit;
$typeFilter = $_GET['type'] ?? '';
$whereExtra = $typeFilter ? "AND type = " . $db->quote($typeFilter) : '';

$total = $db->query("SELECT COUNT(*) FROM falcon.notifications WHERE user_id = $uid $whereExtra")->fetchColumn();

$stmt = $db->prepare("
    SELECT id, title, message, type, is_read, link, created_at
    FROM falcon.notifications
    WHERE user_id = ? $whereExtra
    ORDER BY created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$uid]);
$notifs = $stmt->fetchAll();

$typeIcons = ['success'=>'✅','error'=>'❌','warn'=>'⚠️','info'=>'📢','danger'=>'🚨'];
$typeClass = ['success'=>'notif-icon-success','error'=>'notif-icon-error','warn'=>'notif-icon-warn','info'=>'notif-icon-info','danger'=>'notif-icon-danger'];

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header flex-between">
    <div>
        <h1>Notifications</h1>
        <p>All your court updates and alerts</p>
    </div>
    <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<!-- Filter tabs -->
<div class="tab-row" style="margin-bottom:20px;">
    <a href="?p=1"              class="tab-link <?= !$typeFilter?'active':'' ?>">All</a>
    <a href="?type=success&p=1" class="tab-link <?= $typeFilter==='success'?'active':'' ?>">✅ Confirmed</a>
    <a href="?type=warn&p=1"    class="tab-link <?= $typeFilter==='warn'?'active':'' ?>">⚠️ Warnings</a>
    <a href="?type=error&p=1"   class="tab-link <?= $typeFilter==='error'?'active':'' ?>">❌ Rejected</a>
    <a href="?type=info&p=1"    class="tab-link <?= $typeFilter==='info'?'active':'' ?>">📢 Info</a>
</div>

<?php if (empty($notifs)): ?>
<div class="card" style="text-align:center;padding:64px 20px;">
    <div style="font-size:52px;margin-bottom:14px;">🔔</div>
    <div class="card-title" style="color:var(--muted);">ALL CAUGHT UP</div>
    <p class="text-muted" style="margin-top:8px;">No notifications yet. We'll alert you about your reservations, payments, and court updates here.</p>
</div>
<?php else: ?>

<div class="card">
    <div style="display:flex;flex-direction:column;gap:0;">
    <?php
    $lastDate = null;
    foreach ($notifs as $n):
        $nDate = date('Y-m-d', strtotime($n['created_at']));
        if ($nDate !== $lastDate):
            $lastDate = $nDate;
            $dateLabel = $nDate === date('Y-m-d') ? 'Today' : ($nDate === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('D, M d Y', strtotime($n['created_at'])));
    ?>
        <div class="notif-date-divider">
            <span><?= $dateLabel ?></span>
            <hr>
        </div>
    <?php endif; ?>

        <div class="notif-row">
            <div class="notif-icon-wrap <?= $typeClass[$n['type']] ?? 'notif-icon-info' ?>">
                <?= $typeIcons[$n['type']] ?? '📢' ?>
            </div>
            <div class="notif-body">
                <div class="notif-title"><?= clean($n['title']) ?></div>
                <div class="notif-message"><?= clean($n['message']) ?></div>
                <div class="notif-time"><?= date('g:i A', strtotime($n['created_at'])) ?></div>
            </div>
            <?php if ($n['link']): ?>
                <a href="<?= clean($n['link']) ?>" class="btn-outline btn-sm" style="flex-shrink:0;font-size:11px;">View →</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($total > $limit): ?>
<div class="pagination" style="margin-top:20px;">
    <?php $pages = ceil($total / $limit); ?>
    <?php for ($i=1;$i<=$pages;$i++): ?>
        <a href="?p=<?= $i ?><?= $typeFilter?'&type='.$typeFilter:'' ?>"
           class="btn-outline btn-sm <?= $i==$page?'btn-primary':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>