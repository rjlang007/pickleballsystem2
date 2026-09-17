<?php
// ============================================================
//  FILE: admin/content_manager.php
//  ALL-IN-ONE Homepage Content Manager
//  — UPDATED: Court Gallery (court_photos) panel added
//  — UPDATED: Event type selector + tournament linking (Phase 6)
//  NOTE: Uses actual schema columns:
//        events.event_type CHECK ('event','tournament')
//        events.linked_tournament_id → FK to tournaments.id
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$db = getDB();

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════════════════════
if (!empty($_POST['action'])) {
    header('Content-Type: application/json');

    if (($_POST['action'] ?? '') === 'ping_csrf') {
        $submitted = trim($_POST['csrf_token'] ?? '');
        $stored    = $_SESSION['csrf_token'] ?? '';
        if (empty($stored) || empty($submitted) || !hash_equals($stored, $submitted)) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please reload the page.', 'csrf_token' => '']);
            exit;
        }
        unset($_SESSION['csrf_token']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        echo json_encode(['success' => true, 'message' => 'Token refreshed.', 'csrf_token' => $_SESSION['csrf_token']]);
        exit;
    }

    verifyCsrf();

    function jOk(string $msg, array $extra = []): never {
        echo json_encode(array_merge(['success' => true, 'message' => $msg, 'csrf_token' => csrfToken()], $extra)); exit;
    }
    function jErr(string $msg): never {
        echo json_encode(['success' => false, 'message' => $msg, 'csrf_token' => csrfToken()]); exit;
    }
    function sx(mixed $v, int $max = 1000): string {
        return substr(trim((string)($v ?? '')), 0, $max);
    }

    global $db;
    $action = sx($_POST['action'], 50);

    try {
        switch ($action) {

            // ── site_content ──────────────────────────────────────────
            case 'save_site_content':
                $section = sx($_POST['section'] ?? '', 50);
                $fields  = $_POST['fields'] ?? [];
                $allowed = ['hero','about','location','social','ticker','navbar','footer','activities_section','gallery_section'];
                if (!in_array($section, $allowed, true)) jErr('Invalid section.');
                if (!is_array($fields) || empty($fields)) jErr('No fields provided.');
                $stmt = $db->prepare("
                    INSERT INTO falcon.site_content (section, key, value)
                    VALUES (:s, :k, :v)
                    ON CONFLICT (section, key)
                    DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()
                ");
                $db->beginTransaction();
                foreach ($fields as $k => $v) {
                    $k = sx($k, 80); $v = sx($v, 2000);
                    if ($k) $stmt->execute([':s' => $section, ':k' => $k, ':v' => $v]);
                }
                $db->commit();
                jOk("Section '{$section}' saved.");

            // ── COURT GALLERY — Upload ────────────────────────────────
            case 'upload_court_photo':
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
                    jErr('No file uploaded.');
                }
                $fileErr = $_FILES['photo']['error'];
                if ($fileErr !== UPLOAD_ERR_OK) {
                    $errMap = [
                        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                        UPLOAD_ERR_CANT_WRITE => 'Server failed to write file.',
                        UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
                    ];
                    jErr($errMap[$fileErr] ?? 'Upload error (code ' . $fileErr . ').');
                }
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','jfif','png','webp','gif'], true)) {
                    jErr('Invalid file type. Allowed: JPG, JFIF, PNG, WEBP, GIF.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($_FILES['photo']['tmp_name']);
                if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
                    jErr('File content does not match an allowed image type.');
                }
                $maxBytes = (int)(MAX_UPLOAD_MB * 1024 * 1024);
                if ($_FILES['photo']['size'] > $maxBytes) {
                    jErr('Image too large. Max: ' . MAX_UPLOAD_MB . 'MB.');
                }
                $caption   = sx($_POST['caption']    ?? '', 500);
                $sortOrder = (int)($_POST['sort_order'] ?? 0);
                $uploadDir = APP_ROOT . '/uploads/court_photos/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fname = uniqid('court_', true) . '.' . $ext;
                $dest  = $uploadDir . $fname;
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                    jErr('Failed to save file. Check folder permissions.');
                }
                $s = $db->prepare("
                    INSERT INTO falcon.court_photos (filename, caption, sort_order)
                    VALUES (:fn, :cap, :so)
                    RETURNING id
                ");
                $s->execute([':fn' => $fname, ':cap' => $caption ?: null, ':so' => $sortOrder]);
                $newId = (int)$s->fetchColumn();
                jOk('Photo uploaded successfully.', [
                    'id'       => $newId,
                    'filename' => $fname,
                    'url'      => APP_URL . '/uploads/court_photos/' . $fname,
                    'caption'  => $caption,
                ]);

            // ── COURT GALLERY — Update ────────────────────────────────
            case 'update_court_photo':
                $id      = (int)($_POST['id'] ?? 0);
                if (!$id) jErr('Invalid ID.');
                $caption = sx($_POST['caption'] ?? '', 500);
                $sort    = (int)($_POST['sort_order'] ?? 0);
                $active  = isset($_POST['is_active']) ? true : false;
                $db->prepare("
                    UPDATE falcon.court_photos
                    SET caption = :cap, sort_order = :so, is_active = :a
                    WHERE id = :id
                ")->execute([':cap' => $caption ?: null, ':so' => $sort, ':a' => $active, ':id' => $id]);
                jOk('Photo updated.');

            // ── COURT GALLERY — Delete ────────────────────────────────
            case 'delete_court_photo':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) jErr('Invalid ID.');
                $row = $db->prepare("SELECT filename FROM falcon.court_photos WHERE id=:id");
                $row->execute([':id' => $id]);
                $photo = $row->fetch();
                if ($photo && !empty($photo['filename'])) {
                    $f = APP_ROOT . '/uploads/court_photos/' . $photo['filename'];
                    if (file_exists($f)) @unlink($f);
                }
                $db->prepare("DELETE FROM falcon.court_photos WHERE id=:id")->execute([':id' => $id]);
                jOk('Photo deleted.');

            // ── COURT GALLERY — Bulk reorder ──────────────────────────
            case 'reorder_court_photos':
                $orders = $_POST['orders'] ?? [];
                if (!is_array($orders)) jErr('Invalid data.');
                $stmt = $db->prepare("UPDATE falcon.court_photos SET sort_order=:so WHERE id=:id");
                $db->beginTransaction();
                foreach ($orders as $id => $so) {
                    $stmt->execute([':so' => (int)$so, ':id' => (int)$id]);
                }
                $db->commit();
                jOk('Order saved.');

            // ── Membership plans ──────────────────────────────────────
            case 'save_plan':
                $id       = (int)($_POST['id'] ?? 0);
                $name     = sx($_POST['name'] ?? '', 100);
                $tagline  = sx($_POST['tagline'] ?? '', 255);
                $price    = (float)($_POST['price'] ?? 0);
                $period   = sx($_POST['period'] ?? '', 50);
                $per_game = sx($_POST['per_game'] ?? '', 100);
                $features = sx($_POST['features'] ?? '', 3000);
                $featured = isset($_POST['is_featured']) ? 1 : 0;
                $sort     = (int)($_POST['sort_order'] ?? 0);
                if (!$name) jErr('Plan name required.');
                if ($price < 0) jErr('Price cannot be negative.');
                if ($id > 0) {
                    $db->prepare("UPDATE falcon.membership_plans SET name=:n,tagline=:t,price=:p,period=:pe,per_game=:pg,features=:f,is_featured=:feat,sort_order=:so WHERE id=:id")
                       ->execute([':n'=>$name,':t'=>$tagline,':p'=>$price,':pe'=>$period,':pg'=>$per_game,':f'=>$features,':feat'=>$featured,':so'=>$sort,':id'=>$id]);
                    jOk('Plan updated.', ['id' => $id]);
                } else {
                    $s = $db->prepare("INSERT INTO falcon.membership_plans (name,tagline,price,period,per_game,features,is_featured,sort_order) VALUES (:n,:t,:p,:pe,:pg,:f,:feat,:so) RETURNING id");
                    $s->execute([':n'=>$name,':t'=>$tagline,':p'=>$price,':pe'=>$period,':pg'=>$per_game,':f'=>$features,':feat'=>$featured,':so'=>$sort]);
                    jOk('Plan created.', ['id' => (int)$s->fetchColumn()]);
                }

            case 'delete_plan':
                $id = (int)($_POST['id'] ?? 0); if (!$id) jErr('Invalid ID.');
                $db->prepare("DELETE FROM falcon.membership_plans WHERE id=:id")->execute([':id' => $id]);
                jOk('Plan deleted.');

            // ── Schedule slots ────────────────────────────────────────
            case 'save_slot':
                $id = (int)($_POST['id'] ?? 0);
                $tl = sx($_POST['time_label'] ?? '', 30);
                $st = sx($_POST['status'] ?? 'available', 20);
                $mp = max(1, min(50, (int)($_POST['max_players'] ?? 4)));
                $so = (int)($_POST['sort_order'] ?? 0);
                if (!$tl) jErr('Time label required.');
                if (!in_array($st, ['available','busy','unavailable'], true)) $st = 'available';
                if ($id > 0) {
                    $db->prepare("UPDATE falcon.schedule_slots SET time_label=:tl,status=:st,max_players=:mp,sort_order=:so WHERE id=:id")
                       ->execute([':tl'=>$tl,':st'=>$st,':mp'=>$mp,':so'=>$so,':id'=>$id]);
                    jOk('Slot updated.', ['id' => $id]);
                } else {
                    $s = $db->prepare("INSERT INTO falcon.schedule_slots (time_label,status,max_players,sort_order) VALUES (:tl,:st,:mp,:so) RETURNING id");
                    $s->execute([':tl'=>$tl,':st'=>$st,':mp'=>$mp,':so'=>$so]);
                    jOk('Slot created.', ['id' => (int)$s->fetchColumn()]);
                }

            case 'delete_slot':
                $id = (int)($_POST['id'] ?? 0); if (!$id) jErr('Invalid ID.');
                $db->prepare("DELETE FROM falcon.schedule_slots WHERE id=:id")->execute([':id' => $id]);
                jOk('Slot deleted.');

            // ── Events (Phase 6A — with event_type + linked_tournament_id)
            case 'save_event':
                $id     = (int)($_POST['id'] ?? 0);
                $title  = sx($_POST['title'] ?? '', 200);
                $desc   = sx($_POST['description'] ?? '', 2000);
                $tag    = sx($_POST['tag'] ?? '', 80);
                $edate  = sx($_POST['event_date'] ?? '', 20);
                $ti     = sx($_POST['time_info'] ?? '', 100);
                $si     = sx($_POST['slots_info'] ?? '', 100);
                $pi     = sx($_POST['price_info'] ?? '', 100);
                $feat   = isset($_POST['is_featured']) ? 1 : 0;
                $active = isset($_POST['is_active']) ? 1 : 0;

                // Phase 6A — event_type and linked_tournament_id
                // Schema CHECK: event_type IN ('event', 'tournament')
                $eventType = in_array($_POST['event_type'] ?? '', ['tournament', 'event'], true)
                    ? $_POST['event_type']
                    : 'event';
                $linkedTournamentId = ($eventType === 'tournament' && !empty($_POST['linked_tournament_id']))
                    ? (int)$_POST['linked_tournament_id']
                    : null;

                if (!$title) jErr('Title required.');
                if (!$edate) jErr('Date required.');
                $dp = date_create($edate); if (!$dp) jErr('Invalid date.');
                $edate = date_format($dp, 'Y-m-d');

                if ($id > 0) {
                    $db->prepare(
                        "UPDATE falcon.events
                            SET title=:t, description=:d, tag=:tag, event_date=:ed,
                                time_info=:ti, slots_info=:si, price_info=:pi,
                                is_featured=:feat, is_active=:active,
                                event_type=:et, linked_tournament_id=:ltid
                          WHERE id=:id"
                    )->execute([
                        ':t'=>$title, ':d'=>$desc, ':tag'=>$tag, ':ed'=>$edate,
                        ':ti'=>$ti, ':si'=>$si, ':pi'=>$pi,
                        ':feat'=>$feat, ':active'=>$active,
                        ':et'=>$eventType, ':ltid'=>$linkedTournamentId,
                        ':id'=>$id,
                    ]);
                    jOk('Event updated.', ['id' => $id]);
                } else {
                    $s = $db->prepare(
                        "INSERT INTO falcon.events
                             (title, description, tag, event_date, time_info, slots_info, price_info,
                              is_featured, is_active, event_type, linked_tournament_id)
                         VALUES (:t,:d,:tag,:ed,:ti,:si,:pi,:feat,:active,:et,:ltid)
                         RETURNING id"
                    );
                    $s->execute([
                        ':t'=>$title, ':d'=>$desc, ':tag'=>$tag, ':ed'=>$edate,
                        ':ti'=>$ti, ':si'=>$si, ':pi'=>$pi,
                        ':feat'=>$feat, ':active'=>$active,
                        ':et'=>$eventType, ':ltid'=>$linkedTournamentId,
                    ]);
                    jOk('Event created.', ['id' => (int)$s->fetchColumn()]);
                }

            case 'delete_event':
                $id = (int)($_POST['id'] ?? 0); if (!$id) jErr('Invalid ID.');
                $db->prepare("DELETE FROM falcon.events WHERE id=:id")->execute([':id' => $id]);
                jOk('Event deleted.');

            case 'toggle_event_field':
                $id    = (int)($_POST['id'] ?? 0);
                $field = $_POST['field'] ?? '';
                if (!$id || !in_array($field, ['is_featured','is_active'], true)) jErr('Invalid request.');
                $db->prepare("UPDATE falcon.events SET {$field}=:v WHERE id=:id")
                   ->execute([':v' => (int)($_POST['value'] ?? 0), ':id' => $id]);
                jOk('Updated.');

            // ── Training programs ─────────────────────────────────────
            case 'save_training':
                $id     = (int)($_POST['id'] ?? 0);
                $title  = sx($_POST['title'] ?? '', 150);
                $desc   = sx($_POST['description'] ?? '', 2000);
                $price  = sx($_POST['price_label'] ?? '', 100);
                $color  = sx($_POST['color'] ?? 'green', 20);
                $so     = (int)($_POST['sort_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!$title) jErr('Title required.');
                if (!in_array($color, ['green','blue','orange'], true)) $color = 'green';
                if ($id > 0) {
                    $db->prepare("UPDATE falcon.training_programs SET title=:t,description=:d,price_label=:p,color=:c,sort_order=:so,is_active=:a WHERE id=:id")
                       ->execute([':t'=>$title,':d'=>$desc,':p'=>$price,':c'=>$color,':so'=>$so,':a'=>$active,':id'=>$id]);
                    jOk('Training updated.', ['id' => $id]);
                } else {
                    $s = $db->prepare("INSERT INTO falcon.training_programs (title,description,price_label,color,sort_order,is_active) VALUES (:t,:d,:p,:c,:so,:a) RETURNING id");
                    $s->execute([':t'=>$title,':d'=>$desc,':p'=>$price,':c'=>$color,':so'=>$so,':a'=>$active]);
                    jOk('Training created.', ['id' => (int)$s->fetchColumn()]);
                }

            case 'delete_training':
                $id = (int)($_POST['id'] ?? 0); if (!$id) jErr('Invalid ID.');
                $db->prepare("DELETE FROM falcon.training_programs WHERE id=:id")->execute([':id' => $id]);
                jOk('Training deleted.');

            // ── Shop items ────────────────────────────────────────────
            case 'save_shop_item':
                $id     = (int)($_POST['id'] ?? 0);
                $name   = sx($_POST['name'] ?? '', 200);
                $cat    = sx($_POST['category'] ?? '', 100);
                $price  = (float)($_POST['price'] ?? 0);
                $badge  = sx($_POST['badge'] ?? '', 30);
                $so     = (int)($_POST['sort_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!$name) jErr('Item name required.');
                if ($price < 0) jErr('Price cannot be negative.');
                $img = sx($_POST['existing_image'] ?? '', 255);
                if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $fileErr = $_FILES['image']['error'];
                    if ($fileErr !== UPLOAD_ERR_OK) {
                        $errMap = [UPLOAD_ERR_INI_SIZE=>'File exceeds server upload limit.',UPLOAD_ERR_FORM_SIZE=>'File exceeds form size limit.',UPLOAD_ERR_PARTIAL=>'File only partially uploaded.',UPLOAD_ERR_NO_TMP_DIR=>'Server missing temporary folder.',UPLOAD_ERR_CANT_WRITE=>'Server failed to write file.',UPLOAD_ERR_EXTENSION=>'Upload blocked by PHP extension.'];
                        jErr($errMap[$fileErr] ?? 'Upload error (code '.$fileErr.').');
                    }
                    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) jErr('Invalid file type.');
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($_FILES['image']['tmp_name']);
                    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) jErr('File content does not match an allowed image type (detected: '.$mime.').');
                    $maxBytes = (int)(MAX_UPLOAD_MB * 1024 * 1024);
                    if ($_FILES['image']['size'] > $maxBytes) jErr('Image too large. Max: '.MAX_UPLOAD_MB.'MB.');
                    require_once __DIR__ . '/../config/cloudinary.php';
                    try {
                        $result = uploadToCloudinary($_FILES['image']['tmp_name'], 'falcon/shop');
                        if ($img && str_contains($img, '/')) deleteFromCloudinary($img);
                        $img = $result['public_id'];
                    } catch (RuntimeException $e) {
                        jErr('Image upload failed: ' . $e->getMessage());
                    }
                }
                if ($id > 0) {
                    $db->prepare("UPDATE falcon.shop_items SET name=:n,category=:c,price=:p,badge=:b,image_path=:img,sort_order=:so,is_active=:a WHERE id=:id")
                       ->execute([':n'=>$name,':c'=>$cat,':p'=>$price,':b'=>$badge,':img'=>$img,':so'=>$so,':a'=>$active,':id'=>$id]);
                    jOk('Item updated.', ['id' => $id, 'image_path' => $img]);
                } else {
                    $s = $db->prepare("INSERT INTO falcon.shop_items (name,category,price,badge,image_path,sort_order,is_active) VALUES (:n,:c,:p,:b,:img,:so,:a) RETURNING id");
                    $s->execute([':n'=>$name,':c'=>$cat,':p'=>$price,':b'=>$badge,':img'=>$img,':so'=>$so,':a'=>$active]);
                    jOk('Item created.', ['id' => (int)$s->fetchColumn(), 'image_path' => $img]);
                }

            case 'delete_shop_item':
                $id = (int)($_POST['id'] ?? 0); if (!$id) jErr('Invalid ID.');
                $row = $db->prepare("SELECT image_path FROM falcon.shop_items WHERE id=:id");
                $row->execute([':id' => $id]);
                $item = $row->fetch();
                if ($item && !empty($item['image_path'])) {
                    require_once __DIR__ . '/../config/cloudinary.php';
                    if (str_contains($item['image_path'], '/')) {
                        deleteFromCloudinary($item['image_path']);
                    } else {
                        $f = APP_ROOT.'/uploads/shop/'.$item['image_path'];
                        if (file_exists($f)) @unlink($f);
                    }
                }
                $db->prepare("DELETE FROM falcon.shop_items WHERE id=:id")->execute([':id' => $id]);
                jOk('Item deleted.');

            // ── Activity Types ────────────────────────────────────────
            case 'save_activity':
                $id          = (int)($_POST['id'] ?? 0);
                $name        = sx($_POST['name'] ?? '', 150);
                $description = sx($_POST['description'] ?? '', 1000);
                $icon        = sx($_POST['icon'] ?? '', 20);
                $pricingNote = sx($_POST['pricing_note'] ?? '', 200);
                $priceHour   = filter_var($_POST['price_per_hour'] ?? '', FILTER_VALIDATE_FLOAT);
                $flatPrice   = filter_var($_POST['flat_price'] ?? '',     FILTER_VALIDATE_FLOAT);
                $so          = (int)($_POST['sort_order'] ?? 0);
                $active      = isset($_POST['is_active']) ? true : false;
                if (!$name) jErr('Activity name required.');
                $priceHour = $priceHour === false ? null : $priceHour;
                $flatPrice = $flatPrice === false ? null : $flatPrice;
                $photo = sx($_POST['existing_photo'] ?? '', 255);
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) jErr('Upload error code: '.$_FILES['photo']['error']);
                    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) jErr('Invalid file type.');
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($_FILES['photo']['tmp_name']);
                    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) jErr('Invalid image.');
                    if ($_FILES['photo']['size'] > MAX_UPLOAD_MB * 1024 * 1024) jErr('File too large. Max '.MAX_UPLOAD_MB.'MB.');
                    require_once __DIR__ . '/../config/cloudinary.php';
                    try {
                        $result = uploadToCloudinary($_FILES['photo']['tmp_name'], 'falcon/activities');
                        if ($photo && str_contains($photo, '/')) deleteFromCloudinary($photo);
                        $photo = $result['public_id'];
                    } catch (RuntimeException $e) {
                        jErr('Image upload failed: ' . $e->getMessage());
                    }
                }
                if ($id > 0) {
                    $db->prepare("UPDATE falcon.activity_types SET name=:n,description=:d,icon=:i,pricing_note=:pn,price_per_hour=:ph,flat_price=:fp,sort_order=:so,is_active=:a,photo=:photo WHERE id=:id")
                       ->execute([':n'=>$name,':d'=>$description,':i'=>$icon,':pn'=>$pricingNote,':ph'=>$priceHour,':fp'=>$flatPrice,':so'=>$so,':a'=>$active,':photo'=>$photo?:null,':id'=>$id]);
                    jOk('Activity updated.', ['id' => $id]);
                } else {
                    $s = $db->prepare("INSERT INTO falcon.activity_types (name,description,icon,pricing_note,price_per_hour,flat_price,sort_order,is_active,photo) VALUES (:n,:d,:i,:pn,:ph,:fp,:so,:a,:photo) RETURNING id");
                    $s->execute([':n'=>$name,':d'=>$description,':i'=>$icon,':pn'=>$pricingNote,':ph'=>$priceHour,':fp'=>$flatPrice,':so'=>$so,':a'=>$active,':photo'=>$photo?:null]);
                    jOk('Activity created.', ['id' => (int)$s->fetchColumn()]);
                }

            case 'delete_activity':
                $id = (int)($_POST['id'] ?? 0); if (!$id) jErr('Invalid ID.');
                $row = $db->prepare("SELECT photo FROM falcon.activity_types WHERE id=:id");
                $row->execute([':id' => $id]);
                $act = $row->fetch();
                if ($act && !empty($act['photo'])) {
                    require_once __DIR__ . '/../config/cloudinary.php';
                    if (str_contains($act['photo'], '/')) {
                        deleteFromCloudinary($act['photo']);
                    } else {
                        $f = APP_ROOT.'/uploads/activity_photos/'.$act['photo'];
                        if (file_exists($f)) @unlink($f);
                    }
                }
                $db->prepare("DELETE FROM falcon.activity_types WHERE id=:id")->execute([':id' => $id]);
                jOk('Activity deleted.');

            default: jErr('Unknown action.');
        }
    } catch (PDOException $e) {
        error_log('[ContentManager] DB: '.$e->getMessage());
        if ($db->inTransaction()) $db->rollBack();
        jErr('Database error. Please try again.');
    } catch (Throwable $e) {
        error_log('[ContentManager] '.$e->getMessage());
        jErr('Server error: '.$e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════
//  LOAD ALL DATA FOR PAGE RENDER
// ════════════════════════════════════════════════════════════
function sc(PDO $db, string $sec): array {
    $s = $db->prepare("SELECT key, value FROM falcon.site_content WHERE section=:s");
    $s->execute([':s' => $sec]);
    $out = [];
    foreach ($s->fetchAll() as $r) $out[$r['key']] = $r['value'];
    return $out;
}
$hero        = sc($db, 'hero');
$about       = sc($db, 'about');
$location    = sc($db, 'location');
$social      = sc($db, 'social');
$ticker      = sc($db, 'ticker');
$navbarCont  = sc($db, 'navbar');
$footerCont  = sc($db, 'footer');
$actSection  = sc($db, 'activities_section');

$activities = [];
try {
    $activities = $db->query("SELECT * FROM falcon.activity_types ORDER BY sort_order, id")->fetchAll();
} catch (PDOException $e) { $activities = []; }

$plans       = $db->query("SELECT * FROM falcon.membership_plans ORDER BY sort_order,id")->fetchAll();
$slots       = $db->query("SELECT * FROM falcon.schedule_slots ORDER BY sort_order,id")->fetchAll();
$events      = $db->query("SELECT * FROM falcon.events ORDER BY event_date,id")->fetchAll();
$training    = $db->query("SELECT * FROM falcon.training_programs ORDER BY sort_order,id")->fetchAll();
$shop        = $db->query("SELECT * FROM falcon.shop_items ORDER BY sort_order,id")->fetchAll();
$courtPhotos = $db->query("SELECT * FROM falcon.court_photos ORDER BY sort_order, id")->fetchAll();
$galSection  = sc($db, 'gallery_section');
$activePhotos = array_values(array_filter($courtPhotos, fn($p) => $p['is_active']));

// Phase 6C — Load tournaments for the event type selector
$tournamentsForSelect = [];
try {
    $tournamentsForSelect = $db->query(
        "SELECT id, name, status FROM falcon.tournaments
          WHERE status NOT IN ('cancelled','completed')
          ORDER BY created_at DESC"
    )->fetchAll();
} catch (PDOException $e) { $tournamentsForSelect = []; }

function ev(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function ej(mixed $v): string { return htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8'); }

function assetUrl(string $path, string $folder): string {
    if (empty($path)) return '';
    return APP_URL . '/uploads/' . $folder . '/' . urlencode($path);
}

$pageTitle = 'Content Manager';
require_once __DIR__ . '/../includes/header.php';
?>
<style nonce="<?= getCspNonce() ?>">
/* ─── Layout ─────────────────────────────────────────────── */
.cm-wrap {
    display: grid;
    grid-template-columns: 210px 1fr;
    gap: 0;
    margin: -24px;
    min-height: calc(100vh - 80px);
}
.cm-sidebar {
    background: var(--bg2, #080d18);
    border-right: 1px solid var(--border);
    padding: 0;
    position: sticky;
    top: 70px;
    height: calc(100vh - 70px);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.cm-sidebar-head { padding: 20px 18px 16px; border-bottom: 1px solid var(--border); }
.cm-sidebar-head h3 { font-family: 'Bebas Neue', sans-serif; font-size: 17px; letter-spacing: 0.1em; color: var(--text); margin-bottom: 2px; }
.cm-sidebar-head p { font-size: 11px; color: var(--muted); }
.cm-nav { flex: 1; padding: 10px 0; }
.cm-nav-section { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; color: var(--muted); padding: 14px 18px 5px; opacity: 0.6; }
.cm-nav-item {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 18px; font-size: 13px; font-weight: 600;
    color: var(--muted); cursor: pointer;
    border-left: 3px solid transparent;
    transition: all 0.15s; user-select: none;
}
.cm-nav-item:hover { color: var(--text); background: rgba(255,255,255,0.03); }
.cm-nav-item.active { color: var(--accent); border-left-color: var(--accent); background: rgba(0,229,160,0.05); }
.cm-nav-item .ni { font-size: 14px; width: 18px; text-align: center; flex-shrink: 0; }
.cm-nav-item .nc { margin-left: auto; font-size: 10px; font-family: 'JetBrains Mono',monospace; background: var(--surface2); color: var(--muted); padding: 1px 7px; border-radius: 99px; }
.cm-nav-item.active .nc { background: rgba(0,229,160,0.12); color: var(--accent); }
.cm-sidebar-foot { padding: 14px 16px; border-top: 1px solid var(--border); }
.cm-main { padding: 28px 32px; min-width: 0; overflow-x: hidden; }

/* ─── Panels ─────────────────────────────────────────────── */
.cm-panel { display: none; animation: pIn .2s ease both; }
.cm-panel.active { display: block; }
@keyframes pIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.ph { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 26px; padding-bottom: 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.ph h2 { font-family: 'Bebas Neue', sans-serif; font-size: 26px; letter-spacing: 0.05em; margin-bottom: 3px; }
.ph p  { font-size: 13px; color: var(--muted); }
.ph-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; }

/* ─── Sub-tabs ───────────────────────────────────────────── */
.stabs { display: flex; gap: 4px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 4px; margin-bottom: 22px; flex-wrap: wrap; }
.stab  { flex: 1; text-align: center; padding: 7px 10px; font-size: 12px; font-weight: 700; cursor: pointer; border-radius: 7px; color: var(--muted); border: none; background: none; font-family: inherit; transition: all .15s; white-space: nowrap; min-width: 80px; }
.stab:hover { color: var(--text); }
.stab.active { background: var(--surface2); color: var(--accent); box-shadow: 0 2px 8px rgba(0,0,0,.3); }
.spanel { display: none; }
.spanel.active { display: block; }

/* ─── Form fields ────────────────────────────────────────── */
.fg { margin-bottom: 16px; }
.fg label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 6px; }
.fg input[type=text],.fg input[type=number],.fg input[type=date],
.fg input[type=url],.fg textarea,.fg select {
    width: 100%; background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: 10px; color: var(--text); font-family: inherit;
    font-size: 14px; padding: 10px 13px; transition: border-color .2s, box-shadow .2s;
}
.fg input:focus,.fg textarea:focus,.fg select:focus {
    outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(0,229,160,.08);
}
.fg textarea { resize: vertical; min-height: 88px; }
.fg .hint { font-size: 11px; color: var(--muted); margin-top: 4px; line-height: 1.5; }
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
.g4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px; }
.full { grid-column: 1/-1; }
.chk { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.chk input { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; }
.sdiv { border: none; border-top: 1px solid var(--border); margin: 22px 0; }
.slbl { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin: 18px 0 12px; }
.save-bar { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 16px 0 0; border-top: 1px solid var(--border); margin-top: 22px; }

/* ─── Cards grid ─────────────────────────────────────────── */
.cgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px,1fr)); gap: 15px; margin-bottom: 20px; }
.ccard { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; transition: border-color .2s; }
.ccard.inactive { opacity: .5; }
.ccard.featured { border-color: rgba(0,229,160,.38); }
.ccard:hover { border-color: rgba(0,229,160,.28); }
.ccard-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.ccard-name { font-family: 'Bebas Neue', sans-serif; font-size: 20px; letter-spacing: .04em; line-height: 1.1; }
.ccard-sub  { font-size: 12px; color: var(--muted); margin-top: 2px; }
.ccard-price{ font-family: 'JetBrains Mono',monospace; font-size: 12px; color: var(--accent); margin: 8px 0; }
.ccard-desc { font-size: 12px; color: var(--muted); line-height: 1.6; max-height: 50px; overflow: hidden; margin-bottom: 12px; }
.ccard-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }

/* ─── Pill badges ────────────────────────────────────────── */
.pill { padding: 3px 10px; border-radius: 99px; font-size: 10px; font-weight: 700; white-space: nowrap; }
.pill-g { background: rgba(0,229,160,.1); color: var(--accent); border: 1px solid rgba(0,229,160,.25); }
.pill-m { background: transparent; color: var(--muted); border: 1px solid var(--border); }
.pill-y { background: rgba(245,197,0,.1); color: #f5c500; border: 1px solid rgba(245,197,0,.25); }
.pill-o { background: rgba(255,107,53,.1); color: #ff6b35; border: 1px solid rgba(255,107,53,.25); }

/* ─── Table ──────────────────────────────────────────────── */
.twrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border); }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { padding: 10px 16px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); font-weight: 700; background: var(--surface); border-bottom: 1px solid var(--border); white-space: nowrap; }
.tbl td { padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,.04); vertical-align: middle; }
.tbl tr:last-child td { border-bottom: none; }
.tbl tr:hover td { background: rgba(0,229,160,.018); }
.tbl .acts { display: flex; gap: 6px; justify-content: flex-end; }

/* ─── Event rows ─────────────────────────────────────────── */
.erow { display: grid; grid-template-columns: 50px 1fr auto; gap: 14px; align-items: center; padding: 15px 18px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 10px; transition: border-color .2s; }
.erow.inactive { opacity: .5; }
.erow.featured { border-color: rgba(0,229,160,.35); }
.erow:hover { border-color: rgba(0,229,160,.22); }
.edate { background: var(--surface2); border-radius: 9px; padding: 7px 4px; text-align: center; }
.edate .day { font-family: 'Bebas Neue',sans-serif; font-size: 22px; color: var(--accent); line-height: 1; }
.edate .mon { font-size: 9px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
.einfo-title { font-weight: 700; font-size: 14px; margin-bottom: 4px; }
.einfo-meta  { display: flex; flex-wrap: wrap; gap: 8px; font-size: 11px; color: var(--muted); align-items: center; }
.eacts { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; }
.tog { padding: 4px 10px; border-radius: 99px; font-size: 10px; font-weight: 700; cursor: pointer; border: 1px solid; transition: all .15s; background: none; font-family: inherit; white-space: nowrap; }

/* ─── Shop image card ────────────────────────────────────── */
.simg { aspect-ratio: 1; background: var(--surface2); border-radius: 10px; overflow: hidden; position: relative; display: flex; align-items: center; justify-content: center; margin-bottom: 11px; }
.simg img { width: 100%; height: 100%; object-fit: cover; }
.simg .noimg { font-size: 34px; opacity: .22; }
.sbadge { position: absolute; top: 7px; right: 7px; background: var(--accent); color: var(--bg); font-size: 9px; font-weight: 800; padding: 2px 7px; border-radius: 99px; }

/* ─── Color swatches ─────────────────────────────────────── */
.color-row { display: flex; gap: 14px; flex-wrap: wrap; }
.color-opt  { display: flex; align-items: center; gap: 7px; font-size: 13px; cursor: pointer; }
.color-opt input { accent-color: var(--accent); }
.swatch { width: 13px; height: 13px; border-radius: 50%; border: 2px solid rgba(255,255,255,.2); }
.sw-g { background: #00e5a0; } .sw-b { background: #00b8ff; } .sw-o { background: #ff6b35; }

/* ─── Drop zone ──────────────────────────────────────────── */
.dropzone { border: 2px dashed var(--border); border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: border-color .2s, background .2s; }
.dropzone:hover,.dropzone.drag { border-color: var(--accent); background: rgba(0,229,160,.03); }
.dropzone input[type=file] { display: none; }
.dz-lbl { font-size: 12px; color: var(--muted); margin-top: 7px; }
.imgprev { display: none; align-items: center; justify-content: center; background: var(--surface2); border: 1px solid var(--border); border-radius: 10px; height: 110px; margin-top: 10px; overflow: hidden; }
.imgprev img { max-height: 100%; max-width: 100%; object-fit: contain; }

/* Gallery styles (carried from original) */
.gal-dz { border: 2px dashed var(--border); border-radius: 16px; padding: 36px 20px; text-align: center; cursor: pointer; transition: border-color .2s, background .2s; position: relative; }
.gal-dz:hover, .gal-dz.drag { border-color: var(--accent); background: rgba(0,229,160,.04); }
.gal-dz input[type=file] { display: none; }
.gal-dz-icon { font-size: 40px; margin-bottom: 10px; opacity: .6; }
.gal-dz-title { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
.gal-dz-hint  { font-size: 12px; color: var(--muted); }
.gal-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-top: 20px; }
.gal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; transition: border-color .2s, box-shadow .2s; position: relative; }
.gal-card:hover { border-color: rgba(0,229,160,.35); box-shadow: 0 8px 30px rgba(0,0,0,.3); }
.gal-card.inactive { opacity: .45; }
.gal-thumb { aspect-ratio: 16/9; background: var(--surface2); overflow: hidden; position: relative; display: flex; align-items: center; justify-content: center; }
.gal-thumb img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s ease; }
.gal-card:hover .gal-thumb img { transform: scale(1.05); }
.gal-order-badge { position: absolute; top: 8px; left: 8px; background: rgba(5,8,15,.75); backdrop-filter: blur(6px); color: var(--accent); font-family: 'Space Mono', monospace; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(0,229,160,.25); z-index: 2; user-select: none; }
.gal-active-toggle { position: absolute; top: 8px; right: 8px; z-index: 2; }
.gal-active-btn { width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid; cursor: pointer; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; transition: all .2s; background: rgba(5,8,15,.75); backdrop-filter: blur(6px); }
.gal-active-btn.on  { border-color: var(--accent); color: var(--accent); }
.gal-active-btn.off { border-color: var(--muted);  color: var(--muted);  }
.gal-body { padding: 12px 14px 14px; }
.gal-caption-input { width: 100%; background: var(--surface2); border: 1.5px solid var(--border); border-radius: 8px; color: var(--text); font-family: inherit; font-size: 12px; padding: 7px 10px; margin-bottom: 10px; transition: border-color .2s; resize: none; }
.gal-caption-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(0,229,160,.06); }
.gal-sort-input { width: 56px; background: var(--surface2); border: 1.5px solid var(--border); border-radius: 8px; color: var(--text); font-family: 'Space Mono', monospace; font-size: 12px; padding: 5px 8px; text-align: center; transition: border-color .2s; }
.gal-sort-input:focus { outline: none; border-color: var(--accent); }
.gal-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.gal-foot-left { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--muted); }
.gal-save-btn { padding: 5px 10px; border-radius: 7px; border: 1px solid rgba(0,229,160,.35); background: rgba(0,229,160,.08); color: var(--accent); font-size: 11px; font-weight: 700; cursor: pointer; font-family: inherit; transition: all .15s; white-space: nowrap; }
.gal-save-btn:hover { background: rgba(0,229,160,.18); }
.gal-save-btn:disabled { opacity: .45; cursor: not-allowed; }
.gal-upload-bar { width: 100%; height: 4px; background: var(--surface2); border-radius: 99px; overflow: hidden; margin-top: 10px; display: none; }
.gal-upload-bar.show { display: block; }
.gal-upload-fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--accent2, #00b8ff)); border-radius: 99px; width: 0; transition: width .3s ease; }
.gal-stats { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
.gal-stat { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 18px; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 120px; }
.gal-stat-icon { font-size: 22px; opacity: .75; }
.gal-stat-val { font-family: 'Bebas Neue', sans-serif; font-size: 26px; color: var(--accent); line-height: 1; }
.gal-stat-lbl { font-size: 11px; color: var(--muted); margin-top: 2px; }
.gal-section-fields { padding: 0; }
.gal-empty { text-align: center; padding: 60px 20px 40px; color: var(--muted); }
.gal-empty-icon { font-size: 52px; margin-bottom: 12px; opacity: .35; }
.gal-empty h4 { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.gal-empty p  { font-size: 13px; margin-bottom: 20px; }

/* ─── Modal ──────────────────────────────────────────────── */
.mbg { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(5,8,15,.9); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); align-items: flex-start; justify-content: center; padding: 40px 20px; overflow-y: auto; }
.mbg.open { display: flex; }
.mbox { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 26px; width: 100%; max-width: 580px; box-shadow: 0 24px 80px rgba(0,0,0,.7); animation: mIn .24s cubic-bezier(.34,1.56,.64,1); margin: auto; }
@keyframes mIn { from{opacity:0;transform:scale(.9) translateY(18px)} to{opacity:1;transform:scale(1) translateY(0)} }
.mhead { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.mhead h3 { font-family: 'Bebas Neue',sans-serif; font-size: 22px; letter-spacing: .06em; }
.mclose { background: none; border: none; color: var(--muted); font-size: 22px; cursor: pointer; line-height: 1; padding: 0; }
.mclose:hover { color: #ef4444; }
.mfoot { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); }

/* ─── Misc ───────────────────────────────────────────────── */
.btn-del { background: rgba(239,68,68,.1); color: #ef4444; border: 1px solid rgba(239,68,68,.22); border-radius: 8px; cursor: pointer; padding: 7px 12px; font-size: 12px; font-family: inherit; font-weight: 600; transition: all .15s; }
.btn-del:hover { background: rgba(239,68,68,.2); }
.empty { text-align: center; padding: 50px 20px; }
.empty .ej { font-size: 42px; margin-bottom: 10px; }
.empty h4  { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.empty p   { font-size: 13px; color: var(--muted); margin-bottom: 18px; }
.callout { background: rgba(0,184,255,.06); border: 1px solid rgba(0,184,255,.2); border-radius: 10px; padding: 12px 16px; font-size: 12px; color: var(--muted); display: flex; gap: 10px; margin-bottom: 20px; }

/* ─── Toast ──────────────────────────────────────────────── */
#cm-toast {
    position: fixed; top: 82px; right: 22px; z-index: 99999;
    background: var(--surface); border-radius: 12px; padding: 13px 18px;
    font-size: 14px; font-weight: 600; min-width: 240px;
    box-shadow: 0 8px 40px rgba(0,0,0,.5); border: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
    transform: translateX(130%); transition: transform .32s cubic-bezier(.34,1.56,.64,1);
}
#cm-toast.show { transform: translateX(0); }
#cm-toast.success { border-color: rgba(0,229,160,.4); }
#cm-toast.success .ti { color: var(--accent); }
#cm-toast.error   { border-color: rgba(239,68,68,.4); }
#cm-toast.error   .ti { color: #ef4444; }
.ti { font-size: 17px; flex-shrink: 0; }

/* ─── Responsive ─────────────────────────────────────────── */
@media (max-width: 960px) { .cm-wrap { grid-template-columns: 170px 1fr; } .cm-main { padding: 22px 20px; } .gal-grid { grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); } }
@media (max-width: 768px) {
    .cm-wrap { grid-template-columns: 1fr; margin: -16px; }
    .cm-sidebar { position: static; height: auto; flex-direction: row; flex-wrap: wrap; padding: 8px 6px; border-right: none; border-bottom: 1px solid var(--border); }
    .cm-sidebar-head,.cm-nav-section,.cm-sidebar-foot { display: none; }
    .cm-nav { display: flex; flex-wrap: wrap; padding: 0; }
    .cm-nav-item { padding: 8px 11px; border-left: none; border-bottom: 3px solid transparent; border-radius: 8px; flex-direction: column; gap: 2px; font-size: 11px; text-align: center; flex: 0 0 auto; }
    .cm-nav-item.active { border-bottom-color: var(--accent); }
    .nc { display: none; }
    .cm-main { padding: 18px 14px; }
    .g2,.g3,.g4 { grid-template-columns: 1fr 1fr; }
    .erow { grid-template-columns: 44px 1fr; }
    .eacts { grid-column: 1/-1; flex-direction: row; }
    .cgrid { grid-template-columns: 1fr 1fr; }
    .gal-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
}
@media (max-width: 480px) { .g2,.g3,.g4 { grid-template-columns: 1fr; } .cgrid { grid-template-columns: 1fr; } .stabs { flex-wrap: wrap; } .gal-grid { grid-template-columns: 1fr 1fr; gap: 8px; } }
@media (max-width: 360px) { .gal-grid { grid-template-columns: 1fr; } }
</style>

<!-- Toast -->
<div id="cm-toast"><span class="ti" id="t-icon">✓</span><span id="t-msg"></span></div>

<div class="cm-wrap">

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<aside class="cm-sidebar">
    <div class="cm-sidebar-head">
        <h3>✏️ Content Manager</h3>
        <p>Homepage editor · all sections</p>
    </div>
    <nav class="cm-nav">
        <div class="cm-nav-section">Text Content</div>
        <div class="cm-nav-item active" id="nav-hero"       onclick="gotoPanel('hero')">      <span class="ni">🦅</span> Hero &amp; About</div>
        <div class="cm-nav-item"        id="nav-location"   onclick="gotoPanel('location')">  <span class="ni">📍</span> Location<span class="nc"><?= count($location) ?></span></div>
        <div class="cm-nav-section">Dynamic Content</div>
        <div class="cm-nav-item"        id="nav-plans"      onclick="gotoPanel('plans')">     <span class="ni">💳</span> Membership<span class="nc"><?= count($plans) ?></span></div>
        <div class="cm-nav-item"        id="nav-slots"      onclick="gotoPanel('slots')">     <span class="ni">🕐</span> Schedule<span class="nc"><?= count($slots) ?></span></div>
        <div class="cm-nav-item"        id="nav-events"     onclick="gotoPanel('events')">    <span class="ni">🏆</span> Events<span class="nc"><?= count($events) ?></span></div>
        <div class="cm-nav-item"        id="nav-training"   onclick="gotoPanel('training')">  <span class="ni">🎯</span> Training<span class="nc"><?= count($training) ?></span></div>
        <div class="cm-nav-item"        id="nav-activities" onclick="gotoPanel('activities')"><span class="ni">🎯</span> Activities<span class="nc"><?= count($activities) ?></span></div>
        <div class="cm-nav-item"        id="nav-ticker"     onclick="gotoPanel('ticker')">    <span class="ni">📢</span> Ticker &amp; Text</div>
        <div class="cm-nav-item"        id="nav-shop"       onclick="gotoPanel('shop')">      <span class="ni">🛒</span> Shop<span class="nc"><?= count($shop) ?></span></div>
    </nav>
    <div class="cm-sidebar-foot">
        <a href="<?= APP_URL ?>" target="_blank" class="btn-outline btn-sm" style="display:flex;align-items:center;justify-content:center;gap:6px;width:100%;">🔗 View Live Site</a>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn-outline btn-sm" style="display:flex;align-items:center;justify-content:center;gap:6px;width:100%;margin-top:8px;">← Dashboard</a>
    </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════ -->
<div class="cm-main">

<!-- ▓▓▓▓▓▓ PANEL: HERO / ABOUT / SOCIAL ▓▓▓▓▓▓ -->
<div id="panel-hero" class="cm-panel active">
    <div class="ph"><div><h2>🦅 Hero, About &amp; Social</h2><p>Top homepage sections and social media links</p></div></div>
    <div class="stabs">
        <button class="stab active" onclick="stab('hero','ht',this)">🦅 Hero</button>
        <button class="stab"        onclick="stab('hero','ab',this)">🏓 About</button>
        <button class="stab"        onclick="stab('hero','soc',this)">📲 Social</button>
    </div>
    <div class="spanel active" id="sp-hero-ht">
        <div class="g2">
            <div class="fg"><label>Badge Text</label><input type="text" data-sec="hero" data-key="badge" value="<?= ev($hero['badge'] ?? 'Now Open · Polomolok, South Cotabato') ?>"></div>
            <div class="fg"><label>Title Line 1 (outline)</label><input type="text" data-sec="hero" data-key="title_line1" value="<?= ev($hero['title_line1'] ?? 'Play Like') ?>"></div>
            <div class="fg"><label>Title Line 2 (accent)</label><input type="text" data-sec="hero" data-key="title_line2" value="<?= ev($hero['title_line2'] ?? 'A Falcon') ?>"></div>
            <div class="fg full"><label>Hero Description</label><textarea data-sec="hero" data-key="description"><?= ev($hero['description'] ?? '') ?></textarea></div>
        </div>
        <div class="slbl">Stats</div>
        <div class="g3">
            <div class="fg"><label>Stat 1 Number</label><input type="text" data-sec="hero" data-key="stat1_num"   value="<?= ev($hero['stat1_num']   ?? '1') ?>"></div>
            <div class="fg"><label>Stat 1 Label</label> <input type="text" data-sec="hero" data-key="stat1_label" value="<?= ev($hero['stat1_label'] ?? 'Courts') ?>"></div>
            <div></div>
            <div class="fg"><label>Stat 2 Number</label><input type="text" data-sec="hero" data-key="stat2_num"   value="<?= ev($hero['stat2_num']   ?? '14H') ?>"></div>
            <div class="fg"><label>Stat 2 Label</label> <input type="text" data-sec="hero" data-key="stat2_label" value="<?= ev($hero['stat2_label'] ?? 'Daily Play') ?>"></div>
            <div></div>
            <div class="fg"><label>Stat 3 Number</label><input type="text" data-sec="hero" data-key="stat3_num"   value="<?= ev($hero['stat3_num']   ?? '100+') ?>"></div>
            <div class="fg"><label>Stat 3 Label</label> <input type="text" data-sec="hero" data-key="stat3_label" value="<?= ev($hero['stat3_label'] ?? 'Members') ?>"></div>
        </div>
        <div class="save-bar">
            <button class="btn-outline btn-sm" onclick="if(confirm('Reload to reset?'))location.reload()">↺ Reset</button>
            <button class="btn-primary btn-sm" onclick="saveSC('hero','sp-hero-ht')">💾 Save Hero</button>
        </div>
    </div>
    <div class="spanel" id="sp-hero-ab">
        <div class="g2">
            <div class="fg"><label>Section Tagline</label><input type="text" data-sec="about" data-key="tagline" value="<?= ev($about['tagline'] ?? 'About Falcon') ?>"></div>
            <div class="fg"><label>Section Heading</label><input type="text" data-sec="about" data-key="heading" value="<?= ev($about['heading'] ?? 'Where Champions Are Made') ?>"></div>
            <div class="fg full"><label>First Paragraph</label><textarea data-sec="about" data-key="description"><?= ev($about['description'] ?? '') ?></textarea></div>
            <div class="fg full"><label>Second Paragraph</label><textarea data-sec="about" data-key="description2"><?= ev($about['description2'] ?? '') ?></textarea></div>
        </div>
        <div class="save-bar"><button class="btn-primary btn-sm" onclick="saveSC('about','sp-hero-ab')">💾 Save About</button></div>
    </div>
    <div class="spanel" id="sp-hero-soc">
        <div class="g3">
            <div class="fg"><label>Facebook URL</label><input type="url" data-sec="social" data-key="facebook"  value="<?= ev($social['facebook']  ?? '') ?>"></div>
            <div class="fg"><label>Instagram URL</label><input type="url" data-sec="social" data-key="instagram" value="<?= ev($social['instagram'] ?? '') ?>"></div>
            <div class="fg"><label>TikTok URL</label>   <input type="url" data-sec="social" data-key="tiktok"    value="<?= ev($social['tiktok']    ?? '') ?>"></div>
        </div>
        <div class="save-bar"><button class="btn-primary btn-sm" onclick="saveSC('social','sp-hero-soc')">💾 Save Social</button></div>
    </div>
</div>

<!-- ▓▓▓▓▓▓ PANEL: LOCATION ▓▓▓▓▓▓ -->
<div id="panel-location" class="cm-panel">
    <div class="ph"><div><h2>📍 Location &amp; Contact</h2></div></div>
    <div class="g2">
        <div class="fg full"><label>Full Address</label><textarea data-sec="location" data-key="address" rows="2"><?= ev($location['address'] ?? '') ?></textarea></div>
        <div class="fg"><label>Operating Hours</label><input type="text" data-sec="location" data-key="hours" value="<?= ev($location['hours'] ?? '10:00 AM – 12:00 Midnight') ?>"></div>
        <div class="fg"><label>Number of Courts</label><input type="text" data-sec="location" data-key="courts" value="<?= ev($location['courts'] ?? '1 Professional Court') ?>"></div>
    </div>
    <hr class="sdiv">
    <div class="g2">
        <div class="fg"><label>Phone / Mobile</label><input type="text" data-sec="location" data-key="phone" value="<?= ev($location['phone'] ?? '') ?>"></div>
        <div class="fg"><label>Email Address</label> <input type="text" data-sec="location" data-key="email" value="<?= ev($location['email'] ?? '') ?>"></div>
    </div>
    <hr class="sdiv">
    <div class="g3">
        <div class="fg"><label>Latitude</label>        <input type="text" data-sec="location" data-key="lat"      value="<?= ev($location['lat']      ?? '6.2167') ?>"></div>
        <div class="fg"><label>Longitude</label>       <input type="text" data-sec="location" data-key="lng"      value="<?= ev($location['lng']      ?? '125.0750') ?>"></div>
        <div class="fg"><label>Google Maps URL</label> <input type="text" data-sec="location" data-key="maps_url" value="<?= ev($location['maps_url'] ?? '') ?>"></div>
    </div>
    <div class="save-bar"><button class="btn-primary btn-sm" onclick="saveLocation()">💾 Save Location</button></div>
</div>

<!-- ▓▓▓▓▓▓ PANEL: MEMBERSHIP PLANS ▓▓▓▓▓▓ -->
<div id="panel-plans" class="cm-panel">
    <div class="ph">
        <div><h2>💳 Membership Plans</h2><p><?= count($plans) ?> plan<?= count($plans) !== 1 ? 's' : '' ?></p></div>
        <div class="ph-actions"><button class="btn-primary btn-sm" onclick="openM('plan')">＋ Add Plan</button></div>
    </div>
    <?php if (empty($plans)): ?>
        <div class="empty"><div class="ej">💳</div><h4>No plans yet</h4><p>Add your first membership plan.</p></div>
    <?php else: ?>
    <div class="cgrid">
        <?php foreach ($plans as $p):
            $feats = [];
            if (!empty($p['features'])) { $j = json_decode($p['features'], true); $feats = is_array($j) ? $j : array_filter(array_map('trim', explode("\n", $p['features']))); }
        ?>
        <div class="ccard <?= $p['is_featured'] ? 'featured' : '' ?>">
            <div class="ccard-top">
                <div><div class="ccard-name"><?= ev($p['name']) ?></div><div class="ccard-sub"><?= ev($p['tagline']) ?></div></div>
                <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;"><?php if ($p['is_featured']): ?><span class="pill pill-y">★ Featured</span><?php endif; ?></div>
            </div>
            <div class="ccard-price">₱<?= number_format((float)$p['price']) ?> <?= ev($p['period']) ?></div>
            <div class="ccard-foot">
                <button class="btn-outline btn-sm" onclick="openM('plan',<?= ej($p) ?>)">✏️ Edit</button>
                <button class="btn-del" onclick="delItem('delete_plan',<?= (int)$p['id'] ?>,'<?= ev($p['name']) ?>')">🗑 Del</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ▓▓▓▓▓▓ PANEL: SCHEDULE SLOTS ▓▓▓▓▓▓ -->
<div id="panel-slots" class="cm-panel">
    <div class="ph">
        <div><h2>🕐 Schedule Slots</h2><p><?= count($slots) ?> slot<?= count($slots) !== 1 ? 's' : '' ?></p></div>
        <div class="ph-actions"><button class="btn-primary btn-sm" onclick="openM('slot')">＋ Add Slot</button></div>
    </div>
    <?php if (empty($slots)): ?>
        <div class="empty"><div class="ej">🕐</div><h4>No slots yet</h4></div>
    <?php else: ?>
    <div class="twrap">
        <table class="tbl">
            <thead><tr><th>Time Label</th><th>Status</th><th>Max Players</th><th>Sort</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($slots as $s):
                $sp = ['available'=>['pill-g','● Available'],'busy'=>['pill-o','● Filling Up'],'unavailable'=>['pill-m','● Closed']];
                [$pc,$pl] = $sp[$s['status']] ?? ['pill-m', $s['status']];
            ?>
            <tr>
                <td style="font-family:'JetBrains Mono',monospace;font-weight:700;"><?= ev($s['time_label']) ?></td>
                <td><span class="pill <?= $pc ?>"><?= $pl ?></span></td>
                <td><?= (int)$s['max_players'] ?> players</td>
                <td style="color:var(--muted)"><?= (int)$s['sort_order'] ?></td>
                <td><div class="acts">
                    <button class="btn-outline btn-sm" onclick="openM('slot',<?= ej($s) ?>)">✏️</button>
                    <button class="btn-del" onclick="delItem('delete_slot',<?= (int)$s['id'] ?>,'<?= ev($s['time_label']) ?>')">🗑</button>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ▓▓▓▓▓▓ PANEL: EVENTS (Phase 6 — event type + tournament link) ▓▓▓▓▓▓ -->
<div id="panel-events" class="cm-panel">
    <div class="ph">
        <div><h2>🏆 Events</h2><p><?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?> total</p></div>
        <div class="ph-actions"><button class="btn-primary btn-sm" onclick="openM('event')">＋ Add Event</button></div>
    </div>
    <?php if (empty($events)): ?>
        <div class="empty"><div class="ej">🏆</div><h4>No events yet</h4><button class="btn-primary btn-sm" onclick="openM('event')">＋ Add Event</button></div>
    <?php else: ?>
    <?php foreach ($events as $evRow): ?>
    <div class="erow <?= !$evRow['is_active'] ? 'inactive' : '' ?> <?= $evRow['is_featured'] ? 'featured' : '' ?>">
        <div class="edate">
            <div class="day"><?= date('d', strtotime($evRow['event_date'])) ?></div>
            <div class="mon"><?= date('M', strtotime($evRow['event_date'])) ?></div>
        </div>
        <div>
            <div class="einfo-title"><?= ev($evRow['title']) ?></div>
            <div class="einfo-meta">
                <span class="pill pill-g" style="font-size:9px"><?= ev($evRow['tag']) ?></span>
                <?php if ($evRow['event_type'] === 'tournament'): ?>
                    <span class="pill pill-y" style="font-size:9px">🏆 Tournament</span>
                <?php endif; ?>
                <?php if ($evRow['time_info']): ?><span>🕐 <?= ev($evRow['time_info']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="eacts">
            <div style="display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end;">
                <button class="tog <?= $evRow['is_active'] ? 'pill-g' : 'pill-m' ?>"   onclick="togEv(<?= (int)$evRow['id'] ?>,'is_active',<?=  $evRow['is_active']  ? 0 : 1 ?>)"><?= $evRow['is_active']  ? '✓ Active' : '○ Hidden' ?></button>
                <button class="tog <?= $evRow['is_featured'] ? 'pill-y' : 'pill-m' ?>" onclick="togEv(<?= (int)$evRow['id'] ?>,'is_featured',<?= $evRow['is_featured'] ? 0 : 1 ?>)"><?= $evRow['is_featured'] ? '★ Featured' : '☆ Feature' ?></button>
            </div>
            <div style="display:flex;gap:6px;margin-top:4px;">
                <button class="btn-outline btn-sm" onclick="openM('event',<?= ej($evRow) ?>)">✏️ Edit</button>
                <button class="btn-del" onclick="delItem('delete_event',<?= (int)$evRow['id'] ?>,'<?= ev($evRow['title']) ?>')">🗑</button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ▓▓▓▓▓▓ PANEL: TRAINING ▓▓▓▓▓▓ -->
<div id="panel-training" class="cm-panel">
    <div class="ph">
        <div><h2>🎯 Training Programs</h2><p><?= count($training) ?> program<?= count($training) !== 1 ? 's' : '' ?></p></div>
        <div class="ph-actions"><button class="btn-primary btn-sm" onclick="openM('training')">＋ Add Program</button></div>
    </div>
    <?php if (empty($training)): ?>
        <div class="empty"><div class="ej">🎯</div><h4>No programs yet</h4></div>
    <?php else: ?>
    <div class="cgrid">
        <?php $imap = ['green'=>'📈','blue'=>'🎬','orange'=>'⭐']; foreach ($training as $t): ?>
        <div class="ccard <?= !$t['is_active'] ? 'inactive' : '' ?>">
            <div class="ccard-top">
                <div style="display:flex;align-items:flex-start;gap:9px;">
                    <span style="font-size:24px;line-height:1"><?= $imap[$t['color'] ?? 'green'] ?? '🎯' ?></span>
                    <div><div class="ccard-name"><?= ev($t['title']) ?></div></div>
                </div>
                <span class="pill <?= $t['is_active'] ? 'pill-g' : 'pill-m' ?>"><?= $t['is_active'] ? 'Active' : 'Hidden' ?></span>
            </div>
            <div class="ccard-foot">
                <button class="btn-outline btn-sm" onclick="openM('training',<?= ej($t) ?>)">✏️ Edit</button>
                <button class="btn-del" onclick="delItem('delete_training',<?= (int)$t['id'] ?>,'<?= ev($t['title']) ?>')">🗑 Del</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ▓▓▓▓▓▓ PANEL: ACTIVITIES ▓▓▓▓▓▓ -->
<div id="panel-activities" class="cm-panel">
    <div class="ph">
        <div><h2>🎯 Activity Center</h2><p><?= count($activities) ?> activities</p></div>
        <div class="ph-actions"><button class="btn-primary btn-sm" onclick="openM('activity')">＋ Add Activity</button></div>
    </div>
    <?php if (empty($activities)): ?>
        <div class="empty"><div class="ej">🎯</div><h4>No activities yet</h4></div>
    <?php else: ?>
    <div class="cgrid">
        <?php foreach ($activities as $act): ?>
        <div class="ccard <?= !$act['is_active'] ? 'inactive' : '' ?>">
            <div class="ccard-top">
                <div><div class="ccard-name"><?= ev($act['icon']) ?> <?= ev($act['name']) ?></div></div>
                <span class="pill <?= $act['is_active'] ? 'pill-g' : 'pill-m' ?>"><?= $act['is_active'] ? 'Active' : 'Hidden' ?></span>
            </div>
            <div class="ccard-foot">
                <button class="btn-outline btn-sm" onclick="openM('activity',<?= ej($act) ?>)">✏️ Edit</button>
                <button class="btn-del" onclick="delItem('delete_activity',<?= (int)$act['id'] ?>,'<?= ev($act['name']) ?>')">🗑 Del</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ▓▓▓▓▓▓ PANEL: TICKER ▓▓▓▓▓▓ -->
<div id="panel-ticker" class="cm-panel">
    <div class="ph"><div><h2>📢 Ticker &amp; Page Text</h2></div></div>
    <div class="stabs">
        <button class="stab active" onclick="stab('ticker','tk',this)">📢 Ticker</button>
        <button class="stab"        onclick="stab('ticker','sec',this)">📝 Sections</button>
        <button class="stab"        onclick="stab('ticker','ft',this)">🦶 Footer</button>
    </div>
    <div class="spanel active" id="sp-ticker-tk">
        <div class="g2">
            <div class="fg"><label>Item 1</label><input type="text" data-sec="ticker" data-key="item1" value="<?= ev($ticker['item1'] ?? 'Falcon Pickleball Court') ?>"></div>
            <div class="fg"><label>Item 2</label><input type="text" data-sec="ticker" data-key="item2" value="<?= ev($ticker['item2'] ?? 'Polomolok · South Cotabato') ?>"></div>
            <div class="fg"><label>Item 3</label><input type="text" data-sec="ticker" data-key="item3" value="<?= ev($ticker['item3'] ?? 'Tournament Registration Open') ?>"></div>
            <div class="fg"><label>Item 4</label><input type="text" data-sec="ticker" data-key="item4" value="<?= ev($ticker['item4'] ?? 'Professional Coaching Available') ?>"></div>
            <div class="fg"><label>Item 5</label><input type="text" data-sec="ticker" data-key="item5" value="<?= ev($ticker['item5'] ?? 'Join Now · Limited Slots') ?>"></div>
            <div class="fg"><label>Item 6</label><input type="text" data-sec="ticker" data-key="item6" value="<?= ev($ticker['item6'] ?? '') ?>"></div>
        </div>
        <div class="save-bar"><button class="btn-primary btn-sm" onclick="saveSC('ticker','sp-ticker-tk')">💾 Save Ticker</button></div>
    </div>
    <div class="spanel" id="sp-ticker-sec">
        <div class="g2">
            <div class="fg"><label>Activity Section Title</label><input type="text" data-sec="activities_section" data-key="title" value="<?= ev($actSection['title'] ?? 'More Than Just Pickleball') ?>"></div>
            <div class="fg full"><label>Activity Section Subtitle</label><textarea data-sec="activities_section" data-key="subtitle" rows="2"><?= ev($actSection['subtitle'] ?? '') ?></textarea></div>
        </div>
        <div class="save-bar"><button class="btn-primary btn-sm" onclick="saveSC('activities_section','sp-ticker-sec')">💾 Save</button></div>
    </div>
    <div class="spanel" id="sp-ticker-ft">
        <div class="fg"><label>Footer Tagline</label><textarea data-sec="footer" data-key="tagline" rows="2"><?= ev($footerCont['tagline'] ?? '') ?></textarea></div>
        <div class="g3">
            <div class="fg"><label>Privacy Policy URL</label><input type="text" data-sec="footer" data-key="privacy_url" value="<?= ev($footerCont['privacy_url'] ?? '#') ?>"></div>
            <div class="fg"><label>Terms of Use URL</label><input type="text" data-sec="footer" data-key="terms_url"   value="<?= ev($footerCont['terms_url']   ?? '#') ?>"></div>
            <div class="fg"><label>Refund Policy URL</label><input type="text" data-sec="footer" data-key="refund_url"  value="<?= ev($footerCont['refund_url']  ?? '#') ?>"></div>
        </div>
        <div class="save-bar"><button class="btn-primary btn-sm" onclick="saveSC('footer','sp-ticker-ft')">💾 Save Footer</button></div>
    </div>
</div>

<!-- ▓▓▓▓▓▓ PANEL: SHOP ▓▓▓▓▓▓ -->
<div id="panel-shop" class="cm-panel">
    <div class="ph">
        <div><h2>🛒 Shop Items</h2><p><?= count($shop) ?> item<?= count($shop) !== 1 ? 's' : '' ?></p></div>
        <div class="ph-actions"><button class="btn-primary btn-sm" onclick="openM('shop')">＋ Add Item</button></div>
    </div>
    <?php if (empty($shop)): ?>
        <div class="empty"><div class="ej">🛒</div><h4>No items yet</h4></div>
    <?php else: ?>
    <div class="cgrid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
        <?php foreach ($shop as $item): ?>
        <div class="ccard <?= !$item['is_active'] ? 'inactive' : '' ?>">
            <div class="simg">
                <?php if (!empty($item['image_path'])): ?><img src="<?= ev(assetUrl($item['image_path'], 'shop')) ?>" alt="<?= ev($item['name']) ?>" onerror="this.style.display='none'">
                <?php else: ?><div class="noimg">🏓</div><?php endif; ?>
                <?php if (!empty($item['badge'])): ?><span class="sbadge"><?= ev($item['badge']) ?></span><?php endif; ?>
            </div>
            <div class="ccard-name" style="font-size:15px"><?= ev($item['name']) ?></div>
            <div class="ccard-price">₱<?= number_format((float)$item['price'], 2) ?></div>
            <div class="ccard-foot">
                <button class="btn-outline btn-sm" style="flex:1" onclick="openM('shop',<?= ej($item) ?>)">✏️ Edit</button>
                <button class="btn-del" onclick="delItem('delete_shop_item',<?= (int)$item['id'] ?>,'<?= ev($item['name']) ?>')">🗑</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /cm-main -->
</div><!-- /cm-wrap -->

<!-- ══ MODALS ══════════════════════════════════════════════ -->

<!-- Plan Modal -->
<div class="mbg" id="m-plan"><div class="mbox">
    <div class="mhead"><h3 id="m-plan-title">Add Plan</h3><button class="mclose" onclick="closeM('plan')">✕</button></div>
    <input type="hidden" id="p-id" value="0">
    <div class="g3">
        <div class="fg"><label>Name *</label><input type="text" id="p-name" placeholder="Pro"></div>
        <div class="fg"><label>Price (₱) *</label><input type="number" id="p-price" min="0" step="0.01" placeholder="350"></div>
        <div class="fg"><label>Period</label><input type="text" id="p-period" placeholder="/load"></div>
    </div>
    <div class="g2">
        <div class="fg"><label>Tagline</label><input type="text" id="p-tagline" placeholder="For competitive players"></div>
        <div class="fg"><label>Per-Game Label</label><input type="text" id="p-pergame" placeholder="~₱87 per game"></div>
    </div>
    <div class="fg"><label>Features (one per line)</label><textarea id="p-features" rows="5" placeholder="✓ Court access&#10;✗ Priority booking"></textarea></div>
    <div class="g2">
        <div class="fg"><label>Sort Order</label><input type="number" id="p-sort" min="0" value="0"></div>
        <div class="fg" style="display:flex;align-items:flex-end;padding-bottom:6px"><label class="chk"><input type="checkbox" id="p-feat"> ★ Featured</label></div>
    </div>
    <div class="mfoot">
        <button class="btn-outline btn-sm" onclick="closeM('plan')">Cancel</button>
        <button class="btn-primary btn-sm" id="btn-plan" onclick="savePlan()">💾 Save Plan</button>
    </div>
</div></div>

<!-- Slot Modal -->
<div class="mbg" id="m-slot"><div class="mbox">
    <div class="mhead"><h3 id="m-slot-title">Add Slot</h3><button class="mclose" onclick="closeM('slot')">✕</button></div>
    <input type="hidden" id="sl-id" value="0">
    <div class="fg"><label>Time Label *</label><input type="text" id="sl-time" placeholder="e.g. 10:00 AM"></div>
    <div class="g3">
        <div class="fg"><label>Status</label><select id="sl-status"><option value="available">● Available</option><option value="busy">● Filling Up</option><option value="unavailable">● Closed</option></select></div>
        <div class="fg"><label>Max Players</label><input type="number" id="sl-max" min="1" max="50" value="4"></div>
        <div class="fg"><label>Sort Order</label><input type="number" id="sl-sort" min="0" value="0"></div>
    </div>
    <div class="mfoot">
        <button class="btn-outline btn-sm" onclick="closeM('slot')">Cancel</button>
        <button class="btn-primary btn-sm" id="btn-slot" onclick="saveSlot()">💾 Save Slot</button>
    </div>
</div></div>

<!-- Event Modal — Phase 6B: event type selector + tournament link -->
<div class="mbg" id="m-event"><div class="mbox">
    <div class="mhead"><h3 id="m-event-title">Add Event</h3><button class="mclose" onclick="closeM('event')">✕</button></div>
    <input type="hidden" id="ev-id" value="0">
    <div class="g2">
        <div class="fg full"><label>Title *</label><input type="text" id="ev-title" placeholder="Falcon Grand Slam 2026"></div>
        <div class="fg"><label>Date *</label><input type="date" id="ev-date"></div>
        <div class="fg"><label>Tag / Category</label><input type="text" id="ev-tag" placeholder="Tournament · Open to All"></div>
    </div>
    <div class="fg"><label>Description</label><textarea id="ev-desc"></textarea></div>
    <div class="g3">
        <div class="fg"><label>Time Info</label><input type="text" id="ev-time" placeholder="8:00 AM – 6:00 PM"></div>
        <div class="fg"><label>Slots Info</label><input type="text" id="ev-slots" placeholder="32 slots remaining"></div>
        <div class="fg"><label>Price Info</label><input type="text" id="ev-price" placeholder="₱200 entry fee"></div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap">
        <label class="chk"><input type="checkbox" id="ev-active" checked> ✓ Active</label>
        <label class="chk"><input type="checkbox" id="ev-feat"> ★ Featured</label>
    </div>

    <!-- Phase 6B — Event Type Selector -->
    <div class="fg" style="margin-top:14px">
        <label>Event Type</label>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:6px">
            <label class="chk">
                <input type="radio" name="ev-type" id="ev-type-event" value="event" checked>
                🎉 Party / General Event
            </label>
            <label class="chk">
                <input type="radio" name="ev-type" id="ev-type-tournament" value="tournament">
                🏆 Tournament
            </label>
        </div>
    </div>
    <div class="fg" id="ev-tournament-link-wrap" style="display:none">
        <label>Link to Tournament</label>
        <select id="ev-tournament-id">
            <option value="">— Select Tournament —</option>
            <?php foreach ($tournamentsForSelect as $ts): ?>
            <option value="<?= (int)$ts['id'] ?>">[<?= ev($ts['status']) ?>] <?= ev($ts['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="hint">Selecting a tournament auto-links this event to it.</div>
    </div>

    <div class="mfoot">
        <button class="btn-outline btn-sm" onclick="closeM('event')">Cancel</button>
        <button class="btn-primary btn-sm" id="btn-event" onclick="saveEvent()">💾 Save Event</button>
    </div>
</div></div>

<!-- Training Modal -->
<div class="mbg" id="m-training"><div class="mbox">
    <div class="mhead"><h3 id="m-tr-title">Add Program</h3><button class="mclose" onclick="closeM('training')">✕</button></div>
    <input type="hidden" id="tr-id" value="0">
    <div class="fg"><label>Title *</label><input type="text" id="tr-title" placeholder="Beginner Bootcamp"></div>
    <div class="fg"><label>Description</label><textarea id="tr-desc"></textarea></div>
    <div class="g2">
        <div class="fg"><label>Price Label</label><input type="text" id="tr-price" placeholder="₱500 / 4-session pack"></div>
        <div class="fg"><label>Sort Order</label><input type="number" id="tr-sort" min="0" value="0"></div>
    </div>
    <div class="fg"><label>Color Theme</label>
        <div class="color-row">
            <label class="color-opt"><input type="radio" name="tr-color" value="green" checked><span class="swatch sw-g"></span> Green</label>
            <label class="color-opt"><input type="radio" name="tr-color" value="blue"> <span class="swatch sw-b"></span> Blue</label>
            <label class="color-opt"><input type="radio" name="tr-color" value="orange"><span class="swatch sw-o"></span> Orange</label>
        </div>
    </div>
    <label class="chk"><input type="checkbox" id="tr-active" checked> ✓ Active</label>
    <div class="mfoot">
        <button class="btn-outline btn-sm" onclick="closeM('training')">Cancel</button>
        <button class="btn-primary btn-sm" id="btn-training" onclick="saveTraining()">💾 Save</button>
    </div>
</div></div>

<!-- Activity Modal -->
<div class="mbg" id="m-activity"><div class="mbox">
    <div class="mhead"><h3 id="m-act-title">Add Activity</h3><button class="mclose" onclick="closeM('activity')">✕</button></div>
    <input type="hidden" id="act-id" value="0">
    <input type="hidden" id="act-ephoto" value="">
    <div class="g2">
        <div class="fg"><label>Name *</label><input type="text" id="act-name" placeholder="Billiards"></div>
        <div class="fg"><label>Icon (emoji)</label><input type="text" id="act-icon" placeholder="🎱" maxlength="10"></div>
        <div class="fg full"><label>Description</label><textarea id="act-desc" rows="3"></textarea></div>
    </div>
    <div class="g3">
        <div class="fg"><label>Price per Hour (₱)</label><input type="number" id="act-pph" min="0" step="0.01"></div>
        <div class="fg"><label>Flat Price (₱)</label><input type="number" id="act-fp" min="0" step="0.01"></div>
        <div class="fg"><label>Pricing Note</label><input type="text" id="act-pnote" placeholder="Contact us for pricing"></div>
    </div>
    <div class="fg"><label>Sort Order</label><input type="number" id="act-sort" min="0" value="0"></div>
    <div class="fg"><label>Photo (optional)</label>
        <div class="dropzone" id="dz-act" onclick="document.getElementById('act-photo-input').click()">
            <div style="font-size:26px">📷</div>
            <div class="dz-lbl" id="dz-act-lbl">Click to upload photo</div>
            <input type="file" id="act-photo-input" accept="image/jpeg,image/png,image/webp,image/gif" onchange="prevActImg(this)">
        </div>
        <div class="imgprev" id="act-imgprev"><img id="act-previmg" src="" alt=""></div>
        <div id="act-ephoto-wrap" style="display:none;margin-top:10px">
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px">Current photo:</div>
            <img id="act-ephoto-thumb" src="" alt="" style="max-width:80px;max-height:80px;border-radius:8px;border:1px solid var(--border)">
            <button type="button" style="display:block;margin-top:5px;font-size:11px;color:#ef4444;background:none;border:none;cursor:pointer" onclick="actClearPhoto()">✕ Remove photo</button>
        </div>
    </div>
    <label class="chk"><input type="checkbox" id="act-active" checked> ✓ Active</label>
    <div class="mfoot">
        <button class="btn-outline btn-sm" onclick="closeM('activity')">Cancel</button>
        <button class="btn-primary btn-sm" id="btn-activity" onclick="saveActivity()">💾 Save Activity</button>
    </div>
</div></div>

<!-- Shop Modal -->
<div class="mbg" id="m-shop"><div class="mbox">
    <div class="mhead"><h3 id="m-shop-title">Add Item</h3><button class="mclose" onclick="closeM('shop')">✕</button></div>
    <input type="hidden" id="sh-id" value="0">
    <input type="hidden" id="sh-eimg" value="">
    <div class="g2">
        <div class="fg full"><label>Item Name *</label><input type="text" id="sh-name" placeholder="Falcon Pro Paddle"></div>
        <div class="fg"><label>Category</label><input type="text" id="sh-cat" placeholder="Paddles"></div>
        <div class="fg"><label>Price (₱) *</label><input type="number" id="sh-price" min="0" step="0.01"></div>
    </div>
    <div class="g2">
        <div class="fg"><label>Badge Label</label><input type="text" id="sh-badge" placeholder="NEW"></div>
        <div class="fg"><label>Sort Order</label><input type="number" id="sh-sort" min="0" value="0"></div>
    </div>
    <div class="fg"><label>Product Image</label>
        <div class="dropzone" id="dz" onclick="document.getElementById('sh-img-input').click()">
            <div style="font-size:26px">📷</div>
            <div class="dz-lbl" id="dz-lbl">Click or drag &amp; drop (JPG, PNG, WEBP)</div>
            <input type="file" id="sh-img-input" accept="image/jpeg,image/png,image/webp,image/gif" onchange="prevShopImg(this)">
        </div>
        <div class="imgprev" id="sh-imgprev"><img id="sh-previmg" src="" alt=""></div>
        <div id="sh-eimg-wrap" style="display:none;margin-top:10px">
            <img id="sh-eimg-thumb" src="" alt="" style="max-width:80px;max-height:80px;border-radius:8px;border:1px solid var(--border)">
            <button type="button" style="display:block;margin-top:5px;font-size:11px;color:#ef4444;background:none;border:none;cursor:pointer" onclick="shClearImg()">✕ Remove</button>
        </div>
    </div>
    <label class="chk"><input type="checkbox" id="sh-active" checked> ✓ Active</label>
    <div class="mfoot">
        <button class="btn-outline btn-sm" onclick="closeM('shop')">Cancel</button>
        <button class="btn-primary btn-sm" id="btn-shop" onclick="saveShopItem()">💾 Save Item</button>
    </div>
</div></div>

<!-- ══ JAVASCRIPT ══════════════════════════════════════════ -->
<script nonce="<?= getCspNonce() ?>">
const SELF = '<?= APP_URL ?>/admin/content_manager.php';
let CSRF = '<?= csrfToken() ?>';
const AURL = '<?= APP_URL ?>';

/* ─── Toast ── */
let _tt;
function toast(msg, type = 'success') {
    clearTimeout(_tt);
    const el = document.getElementById('cm-toast');
    document.getElementById('t-icon').textContent = type === 'success' ? '✓' : '✗';
    document.getElementById('t-msg').textContent  = msg;
    el.className = 'show ' + type;
    _tt = setTimeout(() => el.className = '', 3400);
}

/* ─── Panel nav ── */
function gotoPanel(id) {
    document.querySelectorAll('.cm-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.cm-nav-item').forEach(n => n.classList.remove('active'));
    document.getElementById('panel-' + id).classList.add('active');
    document.getElementById('nav-' + id).classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ─── Sub-tabs ── */
function stab(panel, sub, btn) {
    const p = document.getElementById('panel-' + panel);
    p.querySelectorAll('.stab').forEach(t => t.classList.remove('active'));
    p.querySelectorAll('.spanel').forEach(s => s.classList.remove('active'));
    if (btn && typeof btn !== 'string') btn.classList.add('active');
    const sp = document.getElementById('sp-' + panel + '-' + sub);
    if (sp) sp.classList.add('active');
}

/* ─── Generic POST ── */
function doPost(fd, btnId, done) {
    fd.append('csrf_token', CSRF);
    const btn = document.getElementById(btnId);
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Saving…';
    fetch(SELF, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.csrf_token) CSRF = data.csrf_token;
            toast(data.message, data.success ? 'success' : 'error');
            if (data.success) { if (done) done(data); else setTimeout(() => location.reload(), 700); }
        })
        .catch(() => toast('Network error. Please try again.', 'error'))
        .finally(() => { btn.disabled = false; btn.textContent = orig; });
}

/* ─── Save site_content ── */
function saveSC(section, panelId) {
    const fd = new FormData();
    fd.append('action', 'save_site_content');
    fd.append('section', section);
    document.querySelectorAll('#' + panelId + ' [data-sec="' + section + '"]').forEach(el => {
        fd.append('fields[' + el.dataset.key + ']', el.value);
    });
    const btn = document.querySelector('#' + panelId + ' .btn-primary');
    if (!btn) { toast('Button not found', 'error'); return; }
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Saving…';
    fd.append('csrf_token', CSRF);
    fetch(SELF, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { if (d.csrf_token) CSRF = d.csrf_token; toast(d.message, d.success ? 'success' : 'error'); })
        .catch(() => toast('Network error.', 'error'))
        .finally(() => { btn.disabled = false; btn.textContent = orig; });
}

function saveLocation() {
    const lat = parseFloat(document.querySelector('[data-key="lat"]').value);
    const lng = parseFloat(document.querySelector('[data-key="lng"]').value);
    if (isNaN(lat)||lat<-90||lat>90)   { toast('Invalid latitude (-90 to 90).','error'); return; }
    if (isNaN(lng)||lng<-180||lng>180) { toast('Invalid longitude (-180 to 180).','error'); return; }
    const fd = new FormData();
    fd.append('action', 'save_site_content');
    fd.append('section', 'location');
    document.querySelectorAll('[data-sec="location"]').forEach(el => fd.append('fields[' + el.dataset.key + ']', el.value));
    const btn = document.querySelector('#panel-location .btn-primary');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Saving…';
    fd.append('csrf_token', CSRF);
    fetch(SELF, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { if (d.csrf_token) CSRF = d.csrf_token; toast(d.message, d.success ? 'success' : 'error'); })
        .catch(() => toast('Network error.', 'error'))
        .finally(() => { btn.disabled = false; btn.textContent = orig; });
}

/* ─── Modals ── */
function openM(type, data = null) {
    switch (type) {
        case 'plan':
            document.getElementById('m-plan-title').textContent = data ? 'Edit Plan' : 'Add Plan';
            document.getElementById('p-id').value      = data?.id || 0;
            document.getElementById('p-name').value    = data?.name || '';
            document.getElementById('p-price').value   = data?.price || '';
            document.getElementById('p-period').value  = data?.period || '';
            document.getElementById('p-tagline').value = data?.tagline || '';
            document.getElementById('p-pergame').value = data?.per_game || '';
            document.getElementById('p-sort').value    = data?.sort_order || 0;
            document.getElementById('p-feat').checked  = data?.is_featured == 1 || data?.is_featured === true;
            let f = data?.features || '';
            if (f && f.trim().startsWith('[')) { try { f = JSON.parse(f).join('\n'); } catch(e) {} }
            document.getElementById('p-features').value = f;
            break;

        case 'slot':
            document.getElementById('m-slot-title').textContent = data ? 'Edit Slot' : 'Add Slot';
            document.getElementById('sl-id').value     = data?.id || 0;
            document.getElementById('sl-time').value   = data?.time_label || '';
            document.getElementById('sl-status').value = data?.status || 'available';
            document.getElementById('sl-max').value    = data?.max_players || 4;
            document.getElementById('sl-sort').value   = data?.sort_order || 0;
            break;

        case 'event':
            document.getElementById('m-event-title').textContent = data ? 'Edit Event' : 'Add Event';
            document.getElementById('ev-id').value     = data?.id || 0;
            document.getElementById('ev-title').value  = data?.title || '';
            document.getElementById('ev-date').value   = data?.event_date ? data.event_date.substring(0,10) : '';
            document.getElementById('ev-tag').value    = data?.tag || '';
            document.getElementById('ev-desc').value   = data?.description || '';
            document.getElementById('ev-time').value   = data?.time_info || '';
            document.getElementById('ev-slots').value  = data?.slots_info || '';
            document.getElementById('ev-price').value  = data?.price_info || '';
            document.getElementById('ev-active').checked = data ? (data.is_active==1||data.is_active===true) : true;
            document.getElementById('ev-feat').checked   = data?.is_featured==1||data?.is_featured===true;
            // Phase 6D — populate event type and linked tournament
            var evType = data?.event_type || 'event';
            var typeRadio = document.querySelector('input[name="ev-type"][value="'+evType+'"]');
            if (typeRadio) typeRadio.checked = true;
            document.getElementById('ev-tournament-id').value = data?.linked_tournament_id || '';
            document.getElementById('ev-tournament-link-wrap').style.display =
                evType === 'tournament' ? 'block' : 'none';
            break;

        case 'training':
            document.getElementById('m-tr-title').textContent = data ? 'Edit Program' : 'Add Program';
            document.getElementById('tr-id').value    = data?.id || 0;
            document.getElementById('tr-title').value = data?.title || '';
            document.getElementById('tr-desc').value  = data?.description || '';
            document.getElementById('tr-price').value = data?.price_label || '';
            document.getElementById('tr-sort').value  = data?.sort_order || 0;
            document.getElementById('tr-active').checked = data ? (data.is_active==1||data.is_active===true) : true;
            const cr = document.querySelector('input[name="tr-color"][value="'+(data?.color||'green')+'"]');
            if (cr) cr.checked = true;
            break;

        case 'activity':
            document.getElementById('m-act-title').textContent = data ? 'Edit Activity' : 'Add Activity';
            document.getElementById('act-id').value    = data?.id || 0;
            document.getElementById('act-name').value  = data?.name || '';
            document.getElementById('act-icon').value  = data?.icon || '';
            document.getElementById('act-desc').value  = data?.description || '';
            document.getElementById('act-pph').value   = data?.price_per_hour || '';
            document.getElementById('act-fp').value    = data?.flat_price || '';
            document.getElementById('act-pnote').value = data?.pricing_note || '';
            document.getElementById('act-sort').value  = data?.sort_order || 0;
            document.getElementById('act-active').checked = data ? (data.is_active==1||data.is_active===true) : true;
            document.getElementById('act-photo-input').value = '';
            document.getElementById('act-imgprev').style.display = 'none';
            document.getElementById('dz-act-lbl').textContent = 'Click to upload photo';
            const aw = document.getElementById('act-ephoto-wrap');
            if (data?.photo) {
                document.getElementById('act-ephoto').value = data.photo;
                document.getElementById('act-ephoto-thumb').src = AURL+'/uploads/activity_photos/'+encodeURIComponent(data.photo);
                aw.style.display = 'block';
            } else { document.getElementById('act-ephoto').value = ''; aw.style.display = 'none'; }
            break;

        case 'shop':
            document.getElementById('m-shop-title').textContent = data ? 'Edit Item' : 'Add Item';
            document.getElementById('sh-id').value    = data?.id || 0;
            document.getElementById('sh-name').value  = data?.name || '';
            document.getElementById('sh-cat').value   = data?.category || '';
            document.getElementById('sh-price').value = data?.price || '';
            document.getElementById('sh-badge').value = data?.badge || '';
            document.getElementById('sh-sort').value  = data?.sort_order || 0;
            document.getElementById('sh-active').checked = data ? (data.is_active==1||data.is_active===true) : true;
            document.getElementById('sh-img-input').value = '';
            document.getElementById('sh-imgprev').style.display = 'none';
            document.getElementById('dz-lbl').textContent = 'Click or drag & drop (JPG, PNG, WEBP)';
            const ew = document.getElementById('sh-eimg-wrap');
            if (data?.image_path) {
                document.getElementById('sh-eimg').value = data.image_path;
                document.getElementById('sh-eimg-thumb').src = AURL+'/uploads/shop/'+encodeURIComponent(data.image_path);
                ew.style.display = 'block';
            } else { document.getElementById('sh-eimg').value = ''; ew.style.display = 'none'; }
            break;
    }
    document.getElementById('m-' + type).classList.add('open');
    setTimeout(() => {
        const fi = document.querySelector('#m-'+type+' input[type=text],#m-'+type+' input[type=number]');
        if (fi) fi.focus();
    }, 180);
}

function closeM(type) { document.getElementById('m-' + type).classList.remove('open'); }

document.querySelectorAll('.mbg').forEach(bg =>
    bg.addEventListener('click', e => { if (e.target === bg) bg.classList.remove('open'); })
);
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.mbg.open').forEach(m => m.classList.remove('open'));
});

// Phase 6E — Event type toggle listener
document.querySelectorAll('input[name="ev-type"]').forEach(function(r) {
    r.addEventListener('change', function() {
        document.getElementById('ev-tournament-link-wrap').style.display =
            this.value === 'tournament' ? 'block' : 'none';
    });
});

/* ─── Save functions ── */
function savePlan() {
    const name = document.getElementById('p-name').value.trim();
    const price = parseFloat(document.getElementById('p-price').value);
    if (!name)                    { toast('Plan name required.','error'); return; }
    if (isNaN(price)||price < 0) { toast('Enter a valid price.','error'); return; }
    const fd = new FormData();
    fd.append('action','save_plan'); fd.append('id',document.getElementById('p-id').value);
    fd.append('name',name); fd.append('price',price); fd.append('period',document.getElementById('p-period').value.trim());
    fd.append('tagline',document.getElementById('p-tagline').value.trim()); fd.append('per_game',document.getElementById('p-pergame').value.trim());
    fd.append('features',document.getElementById('p-features').value.trim()); fd.append('sort_order',document.getElementById('p-sort').value);
    if (document.getElementById('p-feat').checked) fd.append('is_featured','1');
    doPost(fd,'btn-plan');
}

function saveSlot() {
    const tl = document.getElementById('sl-time').value.trim();
    if (!tl) { toast('Time label required.','error'); return; }
    const fd = new FormData();
    fd.append('action','save_slot'); fd.append('id',document.getElementById('sl-id').value);
    fd.append('time_label',tl); fd.append('status',document.getElementById('sl-status').value);
    fd.append('max_players',document.getElementById('sl-max').value); fd.append('sort_order',document.getElementById('sl-sort').value);
    doPost(fd,'btn-slot');
}

// Phase 6F — saveEvent also sends event_type and linked_tournament_id
function saveEvent() {
    const title = document.getElementById('ev-title').value.trim();
    const date  = document.getElementById('ev-date').value.trim();
    if (!title) { toast('Title required.','error'); return; }
    if (!date)  { toast('Date required.','error');  return; }
    const fd = new FormData();
    fd.append('action','save_event'); fd.append('id',document.getElementById('ev-id').value);
    fd.append('title',title); fd.append('event_date',date); fd.append('tag',document.getElementById('ev-tag').value.trim());
    fd.append('description',document.getElementById('ev-desc').value.trim()); fd.append('time_info',document.getElementById('ev-time').value.trim());
    fd.append('slots_info',document.getElementById('ev-slots').value.trim()); fd.append('price_info',document.getElementById('ev-price').value.trim());
    if (document.getElementById('ev-active').checked) fd.append('is_active','1');
    if (document.getElementById('ev-feat').checked)   fd.append('is_featured','1');
    // Phase 6F
    fd.append('event_type', document.querySelector('input[name="ev-type"]:checked')?.value || 'event');
    fd.append('linked_tournament_id', document.getElementById('ev-tournament-id').value || '');
    doPost(fd,'btn-event');
}

function togEv(id,field,val) {
    const fd = new FormData();
    fd.append('action','toggle_event_field'); fd.append('csrf_token',CSRF);
    fd.append('id',id); fd.append('field',field); fd.append('value',val);
    fetch(SELF,{method:'POST',body:fd,credentials:'same-origin'})
        .then(r=>r.json()).then(d=>{ if(d.csrf_token) CSRF=d.csrf_token; toast(d.message,d.success?'success':'error');if(d.success)setTimeout(()=>location.reload(),600);})
        .catch(()=>toast('Network error.','error'));
}

function saveTraining() {
    const title = document.getElementById('tr-title').value.trim();
    if (!title) { toast('Title required.','error'); return; }
    const fd = new FormData();
    fd.append('action','save_training'); fd.append('id',document.getElementById('tr-id').value);
    fd.append('title',title); fd.append('description',document.getElementById('tr-desc').value.trim());
    fd.append('price_label',document.getElementById('tr-price').value.trim()); fd.append('sort_order',document.getElementById('tr-sort').value);
    fd.append('color',document.querySelector('input[name="tr-color"]:checked')?.value||'green');
    if (document.getElementById('tr-active').checked) fd.append('is_active','1');
    doPost(fd,'btn-training');
}

function prevShopImg(input) {
    if (input.files&&input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { document.getElementById('sh-previmg').src=e.target.result; document.getElementById('sh-imgprev').style.display='flex'; document.getElementById('dz-lbl').textContent=input.files[0].name; document.getElementById('sh-eimg-wrap').style.display='none'; document.getElementById('sh-eimg').value=''; };
        reader.readAsDataURL(input.files[0]);
    }
}
function shClearImg() { document.getElementById('sh-eimg').value=''; document.getElementById('sh-eimg-wrap').style.display='none'; }

function saveShopItem() {
    const name  = document.getElementById('sh-name').value.trim();
    const price = parseFloat(document.getElementById('sh-price').value);
    if (!name)                   { toast('Item name required.','error'); return; }
    if (isNaN(price)||price < 0) { toast('Enter a valid price.','error'); return; }
    const fd = new FormData();
    fd.append('action','save_shop_item'); fd.append('csrf_token',CSRF);
    fd.append('id',document.getElementById('sh-id').value); fd.append('name',name); fd.append('price',price);
    fd.append('category',document.getElementById('sh-cat').value.trim()); fd.append('badge',document.getElementById('sh-badge').value.trim());
    fd.append('sort_order',document.getElementById('sh-sort').value); fd.append('existing_image',document.getElementById('sh-eimg').value);
    if (document.getElementById('sh-active').checked) fd.append('is_active','1');
    const imgFile = document.getElementById('sh-img-input').files[0];
    if (imgFile) fd.append('image',imgFile);
    const btn=document.getElementById('btn-shop'); const orig=btn.textContent;
    btn.disabled=true; btn.textContent='⏳ Saving…';
    fetch(SELF,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(data=>{ if(data.csrf_token) CSRF=data.csrf_token; toast(data.message,data.success?'success':'error');if(data.success)setTimeout(()=>location.reload(),700);}).catch(err=>toast('Upload failed: '+err.message,'error')).finally(()=>{btn.disabled=false;btn.textContent=orig;});
}

function prevActImg(input) {
    if (input.files&&input.files[0]) {
        const reader=new FileReader();
        reader.onload=e=>{document.getElementById('act-previmg').src=e.target.result;document.getElementById('act-imgprev').style.display='flex';document.getElementById('dz-act-lbl').textContent=input.files[0].name;document.getElementById('act-ephoto-wrap').style.display='none';document.getElementById('act-ephoto').value='';};
        reader.readAsDataURL(input.files[0]);
    }
}
function actClearPhoto() { document.getElementById('act-ephoto').value=''; document.getElementById('act-ephoto-wrap').style.display='none'; }

function saveActivity() {
    const name=document.getElementById('act-name').value.trim();
    if (!name){toast('Activity name required.','error');return;}
    const fd=new FormData();
    fd.append('action','save_activity'); fd.append('csrf_token',CSRF);
    fd.append('id',document.getElementById('act-id').value); fd.append('name',name);
    fd.append('icon',document.getElementById('act-icon').value.trim()); fd.append('description',document.getElementById('act-desc').value.trim());
    fd.append('price_per_hour',document.getElementById('act-pph').value); fd.append('flat_price',document.getElementById('act-fp').value);
    fd.append('pricing_note',document.getElementById('act-pnote').value.trim()); fd.append('sort_order',document.getElementById('act-sort').value);
    fd.append('existing_photo',document.getElementById('act-ephoto').value);
    if (document.getElementById('act-active').checked) fd.append('is_active','1');
    const photoFile=document.getElementById('act-photo-input').files[0];
    if (photoFile) fd.append('photo',photoFile);
    const btn=document.getElementById('btn-activity'); const orig=btn.textContent;
    btn.disabled=true;btn.textContent='⏳ Saving…';
    fetch(SELF,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(data=>{ if(data.csrf_token) CSRF=data.csrf_token; toast(data.message,data.success?'success':'error');if(data.success)setTimeout(()=>location.reload(),700);}).catch(err=>toast('Upload failed: '+err.message,'error')).finally(()=>{btn.disabled=false;btn.textContent=orig;});
}

function delItem(action, id, name) {
    if (!confirm('Delete "'+name+'"?\nThis cannot be undone.')) return;
    const fd=new FormData();
    fd.append('action',action); fd.append('id',id); fd.append('csrf_token',CSRF);
    fetch(SELF,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(d=>{ if(d.csrf_token) CSRF=d.csrf_token; toast(d.message,d.success?'success':'error');if(d.success)setTimeout(()=>location.reload(),700);}).catch(()=>toast('Network error.','error'));
}

/* Shop drag & drop */
(function(){
    const dz=document.getElementById('dz'); if(!dz) return;
    dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('drag');});
    dz.addEventListener('dragleave',()=>dz.classList.remove('drag'));
    dz.addEventListener('drop',e=>{e.preventDefault();dz.classList.remove('drag');const f=e.dataTransfer.files[0];if(f){const inp=document.getElementById('sh-img-input');const dt=new DataTransfer();dt.items.add(f);inp.files=dt.files;prevShopImg(inp);}});
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>