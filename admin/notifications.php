<?php
// ============================================================
//  FILE: admin/notifications.php
//  Full notification history page for admins
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// Mark all as read on page load
$db->prepare("UPDATE falcon.notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE")->execute([$uid]);

$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 40;
$offset = ($page - 1) * $limit;
$typeFilter = $_GET['type'] ?? '';
$whereExtra = $typeFilter ? "AND type = " . $db->quote($typeFilter) : '';

$total = $db->query("SELECT COUNT(*) FROM falcon.notifications WHERE user_id = $uid $whereExtra")->fetchColumn();

$stmt = $db->prepare("
    SELECT id, title, message, type, is_read, link, reservation_id, created_at
    FROM falcon.notifications
    WHERE user_id = ? $whereExtra
    ORDER BY created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$uid]);
$notifs = $stmt->fetchAll();

$typeIcons = ['success'=>'✅','error'=>'❌','warn'=>'⚠️','info'=>'📢','danger'=>'🚨'];
$typeBg    = ['success'=>'rgba(0,229,160,.08)','error'=>'rgba(239,68,68,.08)','warn'=>'rgba(245,158,11,.08)','info'=>'rgba(0,184,255,.08)'];

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header flex-between">
    <div>
        <h1>Notifications</h1>
        <p>All court alerts, arrivals, and system events</p>
    </div>
    <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<!-- Summary stats -->
<?php
$counts = $db->prepare("SELECT type, COUNT(*) as cnt FROM falcon.notifications WHERE user_id = ? AND created_at >= NOW() - INTERVAL '24 hours' GROUP BY type");
$counts->execute([$uid]);
$countMap = []; foreach ($counts->fetchAll() as $c) $countMap[$c['type']] = $c['cnt'];
?>
<div class="dashboard-grid stats-4col mb-3">
    <div class="stat-card"><div class="stat-val" style="color:var(--accent);"><?= $countMap['info']??0 ?></div><div class="stat-label">Info (24h)</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--success);"><?= $countMap['success']??0 ?></div><div class="stat-label">Success (24h)</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--warn);"><?= $countMap['warn']??0 ?></div><div class="stat-label">Warnings (24h)</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--danger);"><?= $countMap['error']??0 ?></div><div class="stat-label">Errors (24h)</div></div>
</div>

<!-- Filter tabs -->
<div class="tab-row" style="margin-bottom:20px;">
    <a href="?p=1" class="tab-link <?= !$typeFilter?'active':'' ?>">All</a>
    <a href="?type=info&p=1"    class="tab-link <?= $typeFilter==='info'?'active':'' ?>">📢 Info</a>
    <a href="?type=success&p=1" class="tab-link <?= $typeFilter==='success'?'active':'' ?>">✅ Success</a>
    <a href="?type=warn&p=1"    class="tab-link <?= $typeFilter==='warn'?'active':'' ?>">⚠️ Warnings</a>
    <a href="?type=error&p=1"   class="tab-link <?= $typeFilter==='error'?'active':'' ?>">❌ Errors</a>
</div>

<?php if (empty($notifs)): ?>
<div style="text-align:center;padding:64px 20px;">
    <div style="font-size:52px;margin-bottom:14px;">🔔</div>
    <div style="font-family:'Bebas Neue',sans-serif;font-size:26px;color:var(--muted);">ALL CAUGHT UP</div>
    <p style="color:var(--muted);margin-top:8px;">No notifications yet.</p>
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
        <div style="display:flex;align-items:center;gap:10px;padding:12px 4px 6px;font-size:11px;color:var(--muted);font-weight:700;letter-spacing:.08em;text-transform:uppercase;">
            <span><?= $dateLabel ?></span>
            <hr style="flex:1;border:none;border-top:1px solid var(--border);">
        </div>
    <?php endif; ?>

        <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 8px;border-bottom:1px solid rgba(255,255,255,0.04);">
            <div style="width:42px;height:42px;border-radius:12px;background:<?= $typeBg[$n['type']] ?? 'var(--surface2)' ?>;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">
                <?= $typeIcons[$n['type']] ?? '📢' ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:14px;color:var(--text);margin-bottom:3px;"><?= clean($n['title']) ?></div>
                <div style="font-size:13px;color:var(--muted);line-height:1.5;"><?= clean($n['message']) ?></div>
                <div style="font-size:11px;color:var(--muted);margin-top:5px;font-family:monospace;">
                    <?= date('g:i A', strtotime($n['created_at'])) ?>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                <?php if ($n['reservation_id']): ?>
                    <a href="<?= APP_URL ?>/admin/schedule.php" class="btn-outline btn-sm" style="font-size:11px;">📅 View</a>
                <?php elseif ($n['link']): ?>
                    <a href="<?= clean($n['link']) ?>" class="btn-outline btn-sm" style="font-size:11px;">View →</a>
                <?php endif; ?>
            </div>
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