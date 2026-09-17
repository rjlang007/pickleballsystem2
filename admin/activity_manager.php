<?php
// ============================================================
//  FILE: admin/activity_manager.php
//  Manage activity types (billiards, cooking, events, etc.)
//  and view all activity bookings
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];

define('UPLOAD_ACTIVITY_PHOTOS', APP_ROOT . '/uploads/activity_photos/');
if (!is_dir(UPLOAD_ACTIVITY_PHOTOS)) {
    set_error_handler(fn() => true);
    @mkdir(UPLOAD_ACTIVITY_PHOTOS, 0755, true);
    restore_error_handler();
    if (!is_dir(UPLOAD_ACTIVITY_PHOTOS)) {
        error_log('Activity photos upload dir could not be created: ' . UPLOAD_ACTIVITY_PHOTOS);
    }
}

function uploadActivityPhoto(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($mime, $allowed)) return null;
    if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) return null;
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = 'act_' . uniqid('',true) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], UPLOAD_ACTIVITY_PHOTOS . $name) ? $name : null;
}

// ── POST handlers ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Save / create activity type ──────────────────────
    if (in_array($action, ['create_type','update_type'])) {
        $id             = filter_input(INPUT_POST, 'type_id',       FILTER_VALIDATE_INT);
        $name           = trim(substr($_POST['name']         ?? '', 0, 100));
        $description    = trim(substr($_POST['description']  ?? '', 0, 2000));
        $icon           = trim(substr($_POST['icon']         ?? '🎯', 0, 10));
        $pricePerHour   = filter_input(INPUT_POST, 'price_per_hour', FILTER_VALIDATE_FLOAT);
        $flatPrice      = filter_input(INPUT_POST, 'flat_price',     FILTER_VALIDATE_FLOAT);
        $pricingNote    = trim(substr($_POST['pricing_note'] ?? '', 0, 300));
        $requiresApproval = isset($_POST['requires_approval']);
        $isActive       = isset($_POST['is_active']);
        $sortOrder      = max(0,(int)($_POST['sort_order'] ?? 0));

        if (empty($name)) { setFlash('error','Activity name is required.'); redirect('admin/activity_manager.php'); }

        $photo = null;
        if (!empty($_FILES['photo']['name'])) {
            $photo = uploadActivityPhoto($_FILES['photo']);
            if (!$photo) { setFlash('error','Invalid photo. JPG/PNG/WEBP only, max '.MAX_UPLOAD_MB.'MB.'); redirect('admin/activity_manager.php'); }
        }

        if ($action === 'create_type') {
            $db->prepare("
                INSERT INTO falcon.activity_types
                    (name, description, icon, price_per_hour, flat_price, pricing_note, photo, is_active, requires_approval, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$name,$description?:null,$icon,$pricePerHour?:null,$flatPrice?:null,$pricingNote?:null,$photo,$isActive,$requiresApproval,$sortOrder]);
            setFlash('success','✅ Activity type created.');
        } else {
            if ($photo) {
                $old = $db->prepare("SELECT photo FROM falcon.activity_types WHERE id=?"); $old->execute([$id]);
                $oldPhoto = $old->fetchColumn();
                if ($oldPhoto && file_exists(UPLOAD_ACTIVITY_PHOTOS.$oldPhoto)) @unlink(UPLOAD_ACTIVITY_PHOTOS.$oldPhoto);
                $db->prepare("UPDATE falcon.activity_types SET name=?,description=?,icon=?,price_per_hour=?,flat_price=?,pricing_note=?,photo=?,is_active=?,requires_approval=?,sort_order=?,updated_at=NOW() WHERE id=?")
                   ->execute([$name,$description?:null,$icon,$pricePerHour?:null,$flatPrice?:null,$pricingNote?:null,$photo,$isActive,$requiresApproval,$sortOrder,$id]);
            } else {
                $db->prepare("UPDATE falcon.activity_types SET name=?,description=?,icon=?,price_per_hour=?,flat_price=?,pricing_note=?,is_active=?,requires_approval=?,sort_order=?,updated_at=NOW() WHERE id=?")
                   ->execute([$name,$description?:null,$icon,$pricePerHour?:null,$flatPrice?:null,$pricingNote?:null,$isActive,$requiresApproval,$sortOrder,$id]);
            }
            setFlash('success','✅ Activity type updated.');
        }
        redirect('admin/activity_manager.php');
    }

    // ── Toggle activity type ─────────────────────────────
    if ($action === 'toggle_type') {
        $id = filter_input(INPUT_POST, 'type_id', FILTER_VALIDATE_INT);
        if ($id) { $db->prepare("UPDATE falcon.activity_types SET is_active=NOT is_active,updated_at=NOW() WHERE id=?")->execute([$id]); }
        setFlash('success','Activity type toggled.');
        redirect('admin/activity_manager.php');
    }

    // ── Confirm / reject activity booking ─────────────────
    if (in_array($action, ['confirm_booking','reject_booking'])) {
        $bid  = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
        $note = trim(substr($_POST['admin_note'] ?? '', 0, 300));
        if ($bid) {
            $bStmt = $db->prepare("SELECT ab.*, u.full_name, u.id AS uid, at2.name AS act_name FROM falcon.activity_bookings ab JOIN falcon.users u ON u.id=ab.user_id JOIN falcon.activity_types at2 ON at2.id=ab.activity_type_id WHERE ab.id=?");
            $bStmt->execute([$bid]);
            $booking = $bStmt->fetch();
            if ($booking) {
                $newStatus = $action === 'confirm_booking' ? 'confirmed' : 'cancelled';
                $db->prepare("UPDATE falcon.activity_bookings SET status=?,admin_note=?,updated_at=NOW() WHERE id=?")
                   ->execute([$newStatus, $note?:null, $bid]);
                $db->prepare("INSERT INTO falcon.notifications(user_id,title,message,type,created_at) VALUES(?,?,?,?,NOW())")
                   ->execute([
                       $booking['uid'],
                       $action==='confirm_booking' ? "✅ {$booking['act_name']} Booking Confirmed!" : "❌ {$booking['act_name']} Booking Rejected",
                       $action==='confirm_booking'
                           ? "Your {$booking['act_name']} booking for ".date('M d',strtotime($booking['booking_date']))." has been confirmed."
                           : "Your {$booking['act_name']} booking was not approved.".($note?" Reason: $note":''),
                       $action==='confirm_booking' ? 'success' : 'error',
                   ]);
            }
        }
        setFlash('success', $action==='confirm_booking' ? '✅ Booking confirmed.' : '❌ Booking rejected.');
        redirect('admin/activity_manager.php?tab=bookings');
    }
}

$tab = $_GET['tab'] ?? 'types';

$activityTypes = $db->query("SELECT * FROM falcon.activity_types ORDER BY sort_order, id")->fetchAll();

// Bookings with pagination
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;
$statusFilter = $_GET['status'] ?? '';
$whereClause  = $statusFilter ? "WHERE ab.status = '$statusFilter'" : '';
$totalBookings = $db->query("SELECT COUNT(*) FROM falcon.activity_bookings ab $whereClause")->fetchColumn();

$bookingsStmt = $db->prepare("
    SELECT ab.*, u.full_name, u.username, at2.name AS act_name, at2.icon AS act_icon
    FROM falcon.activity_bookings ab
    JOIN falcon.users u ON u.id = ab.user_id
    JOIN falcon.activity_types at2 ON at2.id = ab.activity_type_id
    " . ($statusFilter ? "WHERE ab.status = ?" : "") . "
    ORDER BY ab.created_at DESC
    LIMIT $limit OFFSET $offset
");
$statusFilter ? $bookingsStmt->execute([$statusFilter]) : $bookingsStmt->execute();
$bookings = $bookingsStmt->fetchAll();

$editTypeId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editType   = null;
if ($editTypeId) {
    foreach ($activityTypes as $at) {
        if ($at['id'] == $editTypeId) { $editType = $at; break; }
    }
}

$pendingCount = (int)$db->query("SELECT COUNT(*) FROM falcon.activity_bookings WHERE status='pending'")->fetchColumn();

$pageTitle = 'Activity Manager';
require_once __DIR__ . '/../includes/header.php';
$flash = getFlash();
?>

<style nonce="<?= getCspNonce() ?>">
.am-layout { display:grid; grid-template-columns:1fr 360px; gap:24px; }
@media(max-width:960px){ .am-layout{grid-template-columns:1fr;} }
.act-card { background:var(--surface2); border:1px solid var(--border); border-radius:14px; overflow:hidden; transition:border-color .2s; margin-bottom:12px; }
.act-card:hover { border-color:rgba(0,229,160,.3); }
.act-header { display:flex; align-items:center; gap:14px; padding:16px 18px; }
.act-icon-box { width:52px;height:52px;border-radius:14px;background:rgba(0,229,160,.1);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0; }
.act-name { font-family:'Bebas Neue',sans-serif; font-size:20px; letter-spacing:1px; color:var(--text); }
.act-price { font-size:13px; color:var(--accent); font-weight:700; margin-top:2px; }
.act-body { padding:0 18px 16px; font-size:13px; color:var(--muted); line-height:1.6; }
.act-photo { width:100%;height:140px;object-fit:cover;border-bottom:1px solid var(--border); }
.act-actions { display:flex;gap:8px;margin-top:10px;flex-wrap:wrap; }
.form-group { margin-bottom:14px; }
.form-group label { display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px; }
.form-group input,.form-group select,.form-group textarea { width:100%;background:var(--surface);border:1.5px solid var(--border);border-radius:9px;padding:10px 13px;color:var(--text);font-size:15px;font-family:inherit;transition:border-color .15s;-webkit-appearance:none; }
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent);outline:none;}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:480px){.form-row-2{grid-template-columns:1fr;}}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Activity Center</h1>
        <p>Manage activities, bookings &amp; pricing</p>
    </div>
    <div>
        <a href="?tab=types" class="btn-outline btn-sm <?= $tab==='types'?'active':'' ?>">🎯 Activity Types</a>
        <a href="?tab=bookings" class="btn-outline btn-sm <?= $tab==='bookings'?'active':'' ?>">
            📋 Bookings <?php if ($pendingCount): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
        </a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type']==='success'?'success':'error' ?>" style="margin-bottom:16px;border-radius:10px;"><?= clean($flash['message']) ?></div>
<?php endif; ?>

<?php if ($tab === 'types'): ?>
<!-- ══ ACTIVITY TYPES ══ -->
<div class="am-layout">

    <!-- Left: existing types -->
    <div>
        <div style="font-size:14px;font-weight:700;margin-bottom:16px;"><?= count($activityTypes) ?> Activity Types</div>
        <?php if (empty($activityTypes)): ?>
            <div style="text-align:center;padding:40px;color:var(--muted);">No activity types yet. Add one using the form →</div>
        <?php else: ?>
            <?php foreach ($activityTypes as $at): ?>
            <div class="act-card <?= !$at['is_active']?'opacity-50':'' ?>" style="<?= !$at['is_active']?'opacity:.55':'' ?>">
                <?php if ($at['photo']): ?>
                <img src="<?= APP_URL ?>/uploads/activity_photos/<?= urlencode($at['photo']) ?>"
                     class="act-photo" alt="<?= clean($at['name']) ?>">
                <?php endif; ?>
                <div class="act-header">
                    <div class="act-icon-box"><?= clean($at['icon'] ?: '🎯') ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="act-name"><?= clean($at['name']) ?></div>
                        <div class="act-price">
                            <?php if ($at['price_per_hour']): ?>₱<?= number_format($at['price_per_hour'],0) ?>/hr<?php endif; ?>
                            <?php if ($at['price_per_hour'] && $at['flat_price']): ?> · <?php endif; ?>
                            <?php if ($at['flat_price']): ?>Flat ₱<?= number_format($at['flat_price'],0) ?><?php endif; ?>
                            <?php if (!$at['price_per_hour'] && !$at['flat_price'] && $at['pricing_note']): ?>
                                <span style="color:var(--muted);"><?= clean($at['pricing_note']) ?></span>
                            <?php endif; ?>
                            <?php if (!$at['price_per_hour'] && !$at['flat_price'] && !$at['pricing_note']): ?>
                                <span style="color:var(--muted);font-size:11px;">No price set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge <?= $at['is_active']?'badge-success':'badge-muted' ?>">
                        <?= $at['is_active']?'Active':'Inactive' ?>
                    </span>
                </div>
                <?php if ($at['description']): ?>
                <div class="act-body"><?= clean(substr($at['description'],0,180)) ?><?= strlen($at['description'])>180?'…':'' ?></div>
                <?php endif; ?>
                <div style="padding:0 18px 16px;">
                    <div class="act-actions">
                        <a href="?edit=<?= $at['id'] ?>#form-section" class="btn-outline btn-sm">✏️ Edit</a>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="toggle_type">
                            <input type="hidden" name="type_id" value="<?= $at['id'] ?>">
                            <button type="submit" class="btn-outline btn-sm" style="<?= $at['is_active']?'color:var(--danger);border-color:rgba(239,68,68,.4)':'color:var(--accent)' ?>">
                                <?= $at['is_active']?'🔴 Deactivate':'🟢 Activate' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Right: add/edit form -->
    <div id="form-section">
        <div class="card <?= $editType?'':''; ?>" style="<?= $editType?'border-color:rgba(0,229,160,.3)':'' ?>; position:sticky;top:80px;">
            <div class="card-title"><?= $editType ? '✏️ Edit: '.clean($editType['name']) : '+ Add Activity Type' ?></div>
            <hr class="divider"/>
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="<?= $editType?'update_type':'create_type' ?>">
                <?php if ($editType): ?>
                <input type="hidden" name="type_id" value="<?= $editType['id'] ?>">
                <?php endif; ?>

                <div style="display:flex;gap:10px;margin-bottom:14px;">
                    <div class="form-group" style="flex:0 0 70px;margin-bottom:0;">
                        <label>Icon</label>
                        <input type="text" name="icon" value="<?= clean($editType['icon']??'🎯') ?>" maxlength="10" style="text-align:center;font-size:22px;">
                    </div>
                    <div class="form-group" style="flex:1;margin-bottom:0;">
                        <label>Activity Name *</label>
                        <input type="text" name="name" value="<?= clean($editType['name']??'') ?>" required maxlength="100" placeholder="e.g. Billiards">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" style="resize:vertical;" maxlength="2000"><?= clean($editType['description']??'') ?></textarea>
                </div>

                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Price/Hour (₱)</label>
                        <input type="number" name="price_per_hour" min="0" step="50"
                               value="<?= $editType&&$editType['price_per_hour']?number_format($editType['price_per_hour'],2,'.',''):'' ?>"
                               placeholder="e.g. 200">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Flat Price (₱)</label>
                        <input type="number" name="flat_price" min="0" step="100"
                               value="<?= $editType&&$editType['flat_price']?number_format($editType['flat_price'],2,'.',''):'' ?>"
                               placeholder="e.g. 5000">
                    </div>
                </div>
                <div style="font-size:11px;color:var(--muted);margin-bottom:14px;margin-top:4px;">
                    Leave both blank to show "Contact for pricing". Set hourly OR flat, not both.
                </div>

                <div class="form-group">
                    <label>Pricing Note</label>
                    <input type="text" name="pricing_note" value="<?= clean($editType['pricing_note']??'') ?>" maxlength="300" placeholder="e.g. Contact us for custom pricing">
                </div>

                <div class="form-group">
                    <label>Photo</label>
                    <?php if ($editType && $editType['photo']): ?>
                    <img src="<?= APP_URL ?>/uploads/activity_photos/<?= urlencode($editType['photo']) ?>" style="width:100%;max-height:120px;object-fit:cover;border-radius:8px;margin-bottom:8px;">
                    <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">Upload new to replace</div>
                    <?php endif; ?>
                    <input type="file" name="photo" accept="image/*" style="font-size:13px;padding:8px;">
                </div>

                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Sort Order</label>
                        <input type="number" name="sort_order" min="0" value="<?= (int)($editType['sort_order']??0) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;"><!-- spacer --></div>
                </div>

                <!-- Toggles -->
                <div style="display:flex;flex-direction:column;gap:8px;margin:14px 0;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
                        <input type="checkbox" name="is_active" <?= (!$editType||$editType['is_active'])?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--accent);">
                        Show to players (Active)
                    </label>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
                        <input type="checkbox" name="requires_approval" <?= (!$editType||$editType['requires_approval'])?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--accent);">
                        Requires admin approval
                    </label>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn-primary" style="flex:1;padding:12px;font-size:14px;">
                        <?= $editType ? '💾 Save Changes' : '+ Add Activity' ?>
                    </button>
                    <?php if ($editType): ?>
                    <a href="<?= APP_URL ?>/admin/activity_manager.php" class="btn-outline" style="flex:1;padding:12px;font-size:14px;text-align:center;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ══ BOOKINGS ══ -->
<div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
    <a href="?tab=bookings" class="tab-link <?= !$statusFilter?'active':'' ?>">All</a>
    <a href="?tab=bookings&status=pending"   class="tab-link <?= $statusFilter==='pending'?'active':'' ?>">⏳ Pending <?php if($pendingCount): ?><span class="nav-badge"><?=$pendingCount?></span><?php endif;?></a>
    <a href="?tab=bookings&status=confirmed" class="tab-link <?= $statusFilter==='confirmed'?'active':'' ?>">✅ Confirmed</a>
    <a href="?tab=bookings&status=cancelled" class="tab-link <?= $statusFilter==='cancelled'?'active':'' ?>">❌ Cancelled</a>
    <span style="margin-left:auto;font-size:13px;color:var(--muted);"><?= $totalBookings ?> total</span>
</div>

<?php if (empty($bookings)): ?>
<div style="text-align:center;padding:48px;color:var(--muted);">
    <div style="font-size:40px;margin-bottom:12px;">📋</div>
    <p>No activity bookings yet.</p>
</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr>
            <th>Activity</th><th>Player</th><th>Date &amp; Time</th>
            <th>Payment</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($bookings as $b): ?>
        <tr>
            <td>
                <strong><?= clean($b['act_icon'].' '.$b['act_name']) ?></strong><br>
                <span style="font-size:11px;color:var(--muted);"><?= $b['party_size'] ?> person<?= $b['party_size']!==1?'s':'' ?></span>
            </td>
            <td>
                <strong><?= clean($b['full_name']) ?></strong><br>
                <span style="font-size:11px;color:var(--muted);">@<?= clean($b['username']) ?></span>
            </td>
            <td>
                <strong><?= date('M d, Y', strtotime($b['booking_date'])) ?></strong><br>
                <span style="font-size:11px;color:var(--muted);"><?= date('g:i A', strtotime($b['start_time'])) ?> – <?= date('g:i A', strtotime($b['end_time'])) ?></span>
            </td>
            <td>
                <span style="font-size:12px;">
                    <?php
                    $ps = $b['payment_status'];
                    $pc = $ps==='paid'?'var(--success)':($ps==='pending_verification'?'#f59e0b':'var(--muted)');
                    echo '<span style="color:'.$pc.';font-weight:700;">'.clean(ucfirst(str_replace('_',' ',$ps))).'</span>';
                    ?>
                </span><br>
                <?php if ($b['payment_amount']): ?><span style="font-size:11px;color:var(--muted);">₱<?= number_format($b['payment_amount'],2) ?></span><?php endif; ?>
            </td>
            <td>
                <span class="badge badge-<?= $b['status']==='confirmed'?'success':($b['status']==='pending'?'warn':'danger') ?>">
                    <?= ucfirst($b['status']) ?>
                </span>
            </td>
            <td>
                <?php if ($b['status']==='pending'): ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"     value="confirm_booking">
                        <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn-outline btn-sm" style="color:var(--success);border-color:var(--success);">✅ Confirm</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"     value="reject_booking">
                        <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);">✕ Reject</button>
                    </form>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php endif; // tab ?>

<?php if ($editType): ?>
<script nonce="<?= getCspNonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('form-section');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>