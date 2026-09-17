<?php
// ============================================================
//  FILE: player/activity_bookings.php
//  Full (paginated) list of the current player's activity
//  bookings (billiards, cooking classes, events, etc.) -- the
//  dashboard only shows the next 5 upcoming ones and links here
//  with "View All →" for the complete history.
//
//  Query mirrors the one in player/dashboard.php that builds
//  $activityBookings, just without the "upcoming only, limit 5"
//  restriction, and paginated the same way player/history.php
//  paginates game history.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$activityBookings = [];
$totalRows         = 0;
try {
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM falcon.activity_bookings WHERE user_id = ?
    ");
    $countStmt->execute([$uid]);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT ab.id, ab.booking_date, ab.start_time, ab.end_time,
               ab.status, ab.payment_status, ab.party_size, ab.created_at,
               at2.name AS act_name, at2.icon AS act_icon
        FROM falcon.activity_bookings ab
        JOIN falcon.activity_types at2 ON at2.id = ab.activity_type_id
        WHERE ab.user_id = ?
        ORDER BY ab.booking_date DESC, ab.start_time DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([$uid]);
    $activityBookings = $stmt->fetchAll();
} catch (PDOException $e) {
    // Activity module tables may not exist on every install.
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$pageTitle = 'My Activity Bookings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;">
  <div class="card">
    <div class="card-header flex-between">
      <div>
        <h1 style="margin:0 0 6px;">🎯 My Activity Bookings</h1>
        <p style="color:var(--muted);margin:0;">All your billiards, classes, and event bookings.</p>
      </div>
      <a href="<?= APP_URL ?>/player/schedule.php" class="btn-outline btn-sm">+ Book Activity</a>
    </div>

    <?php if (empty($activityBookings)): ?>
      <div style="padding:40px 0;text-align:center;color:var(--muted);">
        You haven't booked any activities yet.
        <div style="margin-top:12px;">
          <a href="<?= APP_URL ?>/player/schedule.php" class="btn-outline btn-sm">Browse Activities</a>
        </div>
      </div>
    <?php else: ?>
      <div style="padding:8px 0;">
        <?php foreach ($activityBookings as $ab):
          $statusColor = match($ab['status']) { 'confirmed' => 'var(--success)', 'cancelled' => 'var(--danger)', default => 'var(--warn)' };
          $payColor    = match($ab['payment_status']) { 'paid' => 'var(--success)', 'pending_verification' => 'var(--warn)', default => 'var(--muted)' };
          $payLabel    = match($ab['payment_status']) { 'paid' => '✅ Paid', 'pending_verification' => '⏳ Verifying', default => '💳 Unpaid' };
        ?>
        <div class="act-booking-row" style="border-bottom:1px solid var(--border,rgba(255,255,255,0.08));padding:12px 0;">
          <div class="act-icon-sm"><?= clean($ab['act_icon'] ?: '🎯') ?></div>
          <div style="flex:1;min-width:0;">
            <div class="act-booking-name"><?= clean($ab['act_name']) ?></div>
            <div class="act-booking-meta">
              <?= date('D, M j, Y', strtotime($ab['booking_date'])) ?> ·
              <?= date('g:i A', strtotime($ab['start_time'])) ?>–<?= date('g:i A', strtotime($ab['end_time'])) ?> ·
              <?= (int)$ab['party_size'] ?> person<?= $ab['party_size'] != 1 ? 's' : '' ?>
            </div>
          </div>
          <div class="act-booking-right">
            <span class="badge" style="background:rgba(0,0,0,0.2);color:<?= $statusColor ?>;"><?= ucfirst($ab['status']) ?></span>
            <span style="font-size:11px;color:<?= $payColor ?>;"><?= $payLabel ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div style="display:flex;justify-content:center;gap:8px;padding-top:16px;">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=<?= $p ?>"
             class="btn-outline btn-sm<?= $p === $page ? ' active' : '' ?>"
             style="<?= $p === $page ? 'background:var(--accent);color:#003d2a;' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
