<?php
// ============================================================
//  FILE: admin/court_hours.php
//  Edit a court's weekly operating hours (falcon.court_hours).
//  URL: court_hours.php?court=X
//
//  admin/court_settings.php already fetches falcon.court_hours
//  and shows it read-only with a "📅 Manage Hours →" button that
//  points here -- this is the actual editor that button needs.
//  admin/court_create.php auto-seeds one row per day (10am-midnight)
//  when a court is created, so every court should already have
//  7 rows here to edit, not create from scratch.
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db      = getDB();
$courtId = filter_input(INPUT_GET, 'court', FILTER_VALIDATE_INT)
         ?: filter_input(INPUT_POST, 'court', FILTER_VALIDATE_INT);

if (!$courtId) {
    setFlash('error', 'No court specified.');
    redirect('admin/courts.php');
}

$court = $db->prepare("SELECT * FROM falcon.courts WHERE id = ?");
$court->execute([$courtId]);
$court = $court->fetch();

if (!$court) {
    setFlash('error', 'Court not found.');
    redirect('admin/courts.php');
}

$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$errors   = [];

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    try {
        $db->beginTransaction();
        $upsert = $db->prepare("
            INSERT INTO falcon.court_hours (court_id, day_of_week, open_time, close_time, is_closed)
            VALUES (:court_id, :dow, :open, :close, :closed)
            ON CONFLICT (court_id, day_of_week)
            DO UPDATE SET open_time = EXCLUDED.open_time,
                          close_time = EXCLUDED.close_time,
                          is_closed = EXCLUDED.is_closed
        ");

        for ($dow = 0; $dow <= 6; $dow++) {
            $isClosed = isset($_POST['closed'][$dow]);
            $open     = $_POST['open'][$dow]  ?? '10:00';
            $close    = $_POST['close'][$dow] ?? '00:00';

            if (!$isClosed && $open === $close) {
                $errors[] = "{$dayNames[$dow]}: open and close time can't be the same.";
                continue;
            }

            $upsert->execute([
                ':court_id' => $courtId,
                ':dow'      => $dow,
                ':open'     => $open,
                ':close'    => $close,
                ':closed'   => $isClosed ? 'true' : 'false',
            ]);
        }

        if (empty($errors)) {
            $db->commit();
            setFlash('success', 'Operating hours updated.');
            redirect('admin/court_hours.php?court=' . $courtId);
        } else {
            $db->rollBack();
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $errors[] = 'Could not save hours: ' . $e->getMessage();
    }
}

// ── Load current hours (re-fetch after any POST) ────────────────
$hoursStmt = $db->prepare(
    "SELECT day_of_week, open_time, close_time, is_closed
       FROM falcon.court_hours WHERE court_id = ? ORDER BY day_of_week"
);
$hoursStmt->execute([$courtId]);
$hoursByDay = [];
foreach ($hoursStmt->fetchAll() as $h) {
    $hoursByDay[(int)$h['day_of_week']] = $h;
}

$pageTitle = 'Operating Hours — ' . $court['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-md" style="padding:32px 0;max-width:720px;">

  <div class="card">
    <div class="card-header flex-between">
      <div>
        <h1 style="margin:0 0 6px;">🕐 Operating Hours</h1>
        <p style="color:var(--muted);margin:0;"><?= clean($court['name']) ?></p>
      </div>
      <a href="<?= APP_URL ?>/admin/court_settings.php?court=<?= $courtId ?>" class="btn-outline btn-sm">← Court Settings</a>
    </div>

    <?php foreach ($errors as $err): ?>
      <div class="alert alert-error" style="margin-top:12px;"><?= clean($err) ?></div>
    <?php endforeach; ?>

    <form method="POST" style="margin-top:16px;">
      <?= csrfField() ?>
      <input type="hidden" name="court" value="<?= $courtId ?>">

      <?php for ($dow = 0; $dow <= 6; $dow++):
        $h        = $hoursByDay[$dow] ?? null;
        $isClosed = $h ? (bool)$h['is_closed'] : false;
        $open     = $h ? substr($h['open_time'], 0, 5)  : '10:00';
        $close    = $h ? substr($h['close_time'], 0, 5) : '00:00';
      ?>
      <div style="display:grid;grid-template-columns:60px 1fr 1fr auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border,rgba(255,255,255,0.08));">
        <span style="font-weight:600;"><?= $dayNames[$dow] ?></span>
        <input type="time" name="open[<?= $dow ?>]" value="<?= $open ?>" class="form-input" style="width:100%;">
        <input type="time" name="close[<?= $dow ?>]" value="<?= $close ?>" class="form-input" style="width:100%;">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);white-space:nowrap;">
          <input type="checkbox" name="closed[<?= $dow ?>]" <?= $isClosed ? 'checked' : '' ?>> Closed
        </label>
      </div>
      <?php endfor; ?>

      <div style="margin-top:20px;">
        <button type="submit" class="btn-primary">Save Hours</button>
      </div>
    </form>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
