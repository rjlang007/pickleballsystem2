<?php
require_once __DIR__ . '/../config/app.php';
requireAdmin();

$db = getDB();

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = date('Y-m-d');

$isToday  = ($selectedDate === date('Y-m-d'));
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$dateLabel = date('l, F d, Y', strtotime($selectedDate));

$courts        = $db->query("SELECT * FROM falcon.courts ORDER BY id")->fetchAll();
$selectedCourt = (int)($_GET['court'] ?? ($courts[0]['id'] ?? 1));
$courtIds      = array_column($courts, 'id');
if (!in_array($selectedCourt, $courtIds) && !empty($courtIds)) $selectedCourt = $courtIds[0];
$court = null;
foreach ($courts as $c) { if ($c['id'] === $selectedCourt) { $court = $c; break; } }

$calYear  = (int)($_GET['cal_year']  ?? date('Y'));
$calMonth = (int)($_GET['cal_month'] ?? date('n'));
if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
if ($calMonth > 12) { $calMonth = 1;  $calYear++; }
$calPrevMonth = $calMonth - 1 ?: 12;
$calPrevYear  = $calMonth - 1 ? $calYear : $calYear - 1;
$calNextMonth = $calMonth % 12 + 1;
$calNextYear  = $calMonth < 12 ? $calYear : $calYear + 1;
$calStart = sprintf('%04d-%02d-01', $calYear, $calMonth);
$calEnd   = date('Y-m-t', strtotime($calStart));

$calDataStmt = $db->prepare("
    SELECT slot_date,
           COUNT(*) FILTER (WHERE status='pending')   AS pending,
           COUNT(*) FILTER (WHERE status='confirmed') AS confirmed,
           COUNT(*) FILTER (WHERE status IN ('pending','confirmed')) AS total
    FROM falcon.reservations
    WHERE slot_date BETWEEN ? AND ?
    GROUP BY slot_date
");
$calDataStmt->execute([$calStart, $calEnd]);
$calData = [];
foreach ($calDataStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $calData[$row['slot_date']] = $row;

$hoursMap = [];
try {
    $hStmt = $db->prepare("SELECT day_of_week, open_time, close_time, is_closed FROM falcon.court_hours WHERE court_id = ?");
    $hStmt->execute([$selectedCourt]);
    foreach ($hStmt->fetchAll() as $h) $hoursMap[$h['day_of_week']] = $h;
} catch (PDOException $e) {}

$gameDuration = $court ? (int)$court['game_duration'] : 60;

function buildAllSlots(string $date, array $hoursMap, int $gameDuration): array {
    $dow      = (int)(new DateTime($date))->format('w');
    $open     = $hoursMap[$dow]['open_time']  ?? '09:00:00';
    $close    = $hoursMap[$dow]['close_time'] ?? '00:00:00';
    $isClosed = $hoursMap[$dow]['is_closed']  ?? false;
    if ($isClosed) return [];
    $openTs = strtotime("$date $open");
    if (in_array($close, ['00:00:00', '24:00:00'])) {
        $closeTs = strtotime(date('Y-m-d', strtotime("$date +1 day")) . " 00:00:00");
    } else {
        $closeTs = strtotime("$date $close");
    }
    $slots = []; $interval = 30 * 60; $cur = $openTs;
    while ($cur < $closeTs) {
        $endTs = $cur + $gameDuration * 60;
        if ($endTs > $closeTs) { $cur += $interval; continue; }
        $mins    = (int)date('i', $cur);
        $slots[] = ['start' => date('H:i', $cur), 'end' => date('H:i', $endTs), 'type' => $mins === 0 ? 'hour' : 'half'];
        $cur += $interval;
    }
    return $slots;
}

// ── Helper: resolve proof value to a safe displayable URL ────
// payment_proof may be stored as:
//   • a base64 data-URI  ("data:image/...;base64,...") → written to temp file → URL returned
//   • a bare filename    ("gcash_19_xxx.jpg")           → URL returned
//   • empty / null                                      → '' returned
//
// CRITICAL: Never embed raw base64 blobs into JS variables.
// Large base64 strings break the page (HTTP 431, broken JS context).
function proofSrc(string $proof): string {
    if ($proof === '') return '';
    if (strpos($proof, 'data:image/') === 0) {
        if (!preg_match('/^data:(image\/[a-zA-Z+]+);base64,(.+)$/s', $proof, $m)) return '';
        $mimeType = strtolower($m[1]);
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $ext = 'jpg';
        } elseif ($mimeType === 'image/png') {
            $ext = 'png';
        } elseif ($mimeType === 'image/gif') {
            $ext = 'gif';
        } elseif ($mimeType === 'image/webp') {
            $ext = 'webp';
        } else {
            $ext = 'jpg';
        }
        $hash = 'proof_' . substr(md5($proof), 0, 16) . '.' . $ext;
        // Use /tmp — always writable on Railway
        $file = sys_get_temp_dir() . '/' . $hash;
        if (!file_exists($file)) {
            file_put_contents($file, base64_decode($m[2]));
        }
        return APP_URL . '/admin/proof_serve.php?h=' . urlencode($hash);
    }
    return APP_URL . '/uploads/payment_proofs/' . rawurlencode($proof);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $rid     = filter_input(INPUT_POST, 'reservation_id',  FILTER_VALIDATE_INT);
    $groupId = trim($_POST['booking_group_id'] ?? '');
    $note    = trim(substr($_POST['admin_note'] ?? '', 0, 300));
    $adminId = (int)$_SESSION['user_id'];

    $resolveGroup = function() use ($db, $groupId, $rid) {
        if ($groupId !== '') {
            $st = $db->prepare("
                SELECT r.id, r.user_id, r.slot_date, r.slot_time, r.slot_end,
                       r.party_size, r.payment_status, r.status,
                       r.payment_method, r.payment_proof, r.payment_ref, r.payment_amount,
                       c.name AS court_name, c.id AS court_id, c.game_duration
                FROM falcon.reservations r
                JOIN falcon.courts c ON c.id = r.court_id
                WHERE r.booking_group_id = ?
                ORDER BY r.slot_date, r.slot_time
            ");
            $st->execute([$groupId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($rid) {
            $st = $db->prepare("
                SELECT r.id, r.user_id, r.slot_date, r.slot_time, r.slot_end,
                       r.party_size, r.payment_status, r.status,
                       r.payment_method, r.payment_proof, r.payment_ref, r.payment_amount,
                       c.name AS court_name, c.id AS court_id, c.game_duration
                FROM falcon.reservations r
                JOIN falcon.courts c ON c.id = r.court_id
                WHERE r.id = ?
            ");
            $st->execute([$rid]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return [];
    };

    $slotSummary = function(array $rows): string {
        return implode(', ', array_map(function($r) {
            return date('M d', strtotime($r['slot_date'])) . ' ' . date('g:i A', strtotime($r['slot_time']));
        }, $rows));
    };

    $getResvPricePerHour = function(int $courtId) use ($db): float {
        try {
            $st = $db->prepare("SELECT value FROM falcon.court_settings WHERE court_id = ? AND key = 'reservation_price_per_hour'");
            $st->execute([$courtId]);
            $val = $st->fetchColumn();
            return $val !== false ? (float)$val : 0.0;
        } catch (PDOException $e) { return 0.0; }
    };

    $calcTotalCost = function(array $rows, float $pricePerHour): float {
        // 1 slot = 1 hour at flat rate. No party size or duration multiplication.
        return round($pricePerHour * count($rows), 2);
    };

    $deductWallet = function(int $userId, float $amount, $db): array {
        if ($amount <= 0) return ['ok' => true];
        $lockSt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ? FOR UPDATE");
        $lockSt->execute([$userId]);
        $balance = (float)($lockSt->fetchColumn() ?? 0);
        if ($balance < $amount) {
            return [
                'ok'  => false,
                'msg' => "Player's wallet balance (₱" . number_format($balance, 2)
                       . ") is less than the required amount (₱" . number_format($amount, 2) . ").",
            ];
        }
       
        $db->prepare("UPDATE falcon.wallets SET balance = balance - ?, updated_at = NOW() WHERE user_id = ?")
           ->execute([$amount, $userId]);
        return ['ok' => true];
    };  


if ($action === 'confirm') {


    $rows = $resolveGroup();
    if (empty($rows)) {
        setFlash('error', '❌ No reservations found to confirm.');
        redirect('admin/schedule.php?date=' . $selectedDate . '&court=' . $selectedCourt . '&cal_year=' . $calYear . '&cal_month=' . $calMonth);
    }

    $ids       = array_column($rows, 'id');
    $ph        = implode(',', array_fill(0, count($ids), '?'));
    $userId    = (int)$rows[0]['user_id'];
    $courtId   = (int)$rows[0]['court_id'];
    $courtName = $rows[0]['court_name'];
    $slotCount = count($rows);
    $summary   = $slotSummary($rows);

    $pricePerHour = $getResvPricePerHour($courtId);

    $totalCost = round($pricePerHour * $slotCount, 2);

    if ($totalCost > 0) {
        $preCheckStmt = $db->prepare(
            "SELECT COALESCE(balance, 0) FROM falcon.wallets WHERE user_id = ?"
        );
        $preCheckStmt->execute([$userId]);
        $currentBalance = $preCheckStmt->fetchColumn();

        if ($currentBalance === false) {
            // No wallet row at all
            setFlash('error', '❌ Cannot confirm: Player has no wallet record. Ask them to top up first.');
            redirect('admin/schedule.php?date=' . $selectedDate . '&court=' . $selectedCourt . '&cal_year=' . $calYear . '&cal_month=' . $calMonth);
        }

        $currentBalance = (float)$currentBalance;

        if ($currentBalance < $totalCost) {
            setFlash('error',
                '❌ Cannot confirm: Player\'s wallet balance (₱' . number_format($currentBalance, 2) .
                ') is less than the required amount (₱' . number_format($totalCost, 2) . ').'
            );
            redirect('admin/schedule.php?date=' . $selectedDate . '&court=' . $selectedCourt . '&cal_year=' . $calYear . '&cal_month=' . $calMonth);
        }
    }

    // ── 5. Run the transaction ────────────────────────────────
    $db->beginTransaction();
    try {

        // 5a. Mark reservations as confirmed + paid
        $db->prepare("
            UPDATE falcon.reservations
            SET status              = 'confirmed',
                payment_status      = 'paid',
                payment_verified_at = NOW(),
                payment_verified_by = ?,
                admin_note          = ?,
                updated_at          = NOW()
            WHERE id IN ($ph)
        ")->execute(array_merge([$adminId, $note ?: null], $ids));

        // 5b. Deduct wallet (only when there is a cost)
        if ($totalCost > 0) {

            // Lock the row for this transaction
            $lockStmt = $db->prepare(
                "SELECT COALESCE(balance, 0) FROM falcon.wallets WHERE user_id = ? FOR UPDATE"
            );
            $lockStmt->execute([$userId]);
            $lockedBalance = (float)($lockStmt->fetchColumn() ?? 0);

            // Double-check after lock (balance could have changed)
            if ($lockedBalance < $totalCost) {
                $db->rollBack();
                setFlash('error',
                    '❌ Cannot confirm: Balance changed concurrently. ' .
                    'Current balance (₱' . number_format($lockedBalance, 2) .
                    ') is less than ₱' . number_format($totalCost, 2) . '.'
                );
                redirect('admin/schedule.php?date=' . $selectedDate . '&court=' . $selectedCourt . '&cal_year=' . $calYear . '&cal_month=' . $calMonth);
            }

            // Perform the deduction
            // NOTE: We do NOT check rowCount() here — PDO+PostgreSQL can
            // return 0 rowCount even on a successful UPDATE in some driver
            // versions. We already confirmed the row exists via the lock query.
            $db->prepare(
                "UPDATE falcon.wallets
                 SET balance    = balance - ?,
                     updated_at = NOW()
                 WHERE user_id  = ?"
            )->execute([$totalCost, $userId]);
        }

        // ── 6. Commit everything ──────────────────────────────
        $db->commit();

        // ── 7. Notify the player ──────────────────────────────
        $notifMsg = "{$slotCount} slot" . ($slotCount > 1 ? 's' : '') .
                    " at {$courtName} confirmed: {$summary}.";
        if ($totalCost > 0) {
            $notifMsg .= " ₱" . number_format($totalCost, 2) . " has been deducted from your wallet.";
        }
        try {
            $db->prepare(
                "INSERT INTO falcon.notifications (user_id, title, message, type)
                 VALUES (?, ?, ?, ?)"
            )->execute([
                $userId,
                "✅ " . $slotCount . " Reservation" . ($slotCount > 1 ? 's' : '') . " Confirmed!",
                $notifMsg,
                'success',
            ]);
        } catch (PDOException $e) {
            error_log('[confirm] notification insert failed: ' . $e->getMessage());
        }

        // ── 8. Flash success message ──────────────────────────
        $flashMsg = "✅ {$slotCount} reservation" . ($slotCount > 1 ? 's' : '') . " confirmed.";
        if ($totalCost > 0) {
            $flashMsg .= " ₱" . number_format($totalCost, 2) . " deducted from player's wallet.";
        } else {
            $flashMsg .= " (No price configured — wallet not charged.)";
        }
        setFlash('success', $flashMsg);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[confirm] DB error: ' . $e->getMessage());
        setFlash('error', '❌ Database error during confirmation: ' . $e->getMessage());
    }
}

    // ── reject ────────────────────────────────────────────────
    if ($action === 'reject') {
        $rows = $resolveGroup();
        if (!empty($rows)) {
            $ids       = array_column($rows, 'id');
            $ph        = implode(',', array_fill(0, count($ids), '?'));
            $userId    = (int)$rows[0]['user_id'];
            $courtName = $rows[0]['court_name'];
            $slotCount = count($rows);
            $db->prepare("
                UPDATE falcon.reservations
                SET status='cancelled', admin_note=?, cancelled_by=?, cancelled_at=NOW(), cancel_reason=?, updated_at=NOW()
                WHERE id IN ($ph)
            ")->execute(array_merge([$note ?: null, $adminId, $note ?: null], $ids));
            $db->prepare("INSERT INTO falcon.notifications(user_id,title,message,type) VALUES(?,?,?,?)")
               ->execute([$userId, "❌ Reservation" . ($slotCount > 1 ? 's' : '') . " Rejected",
                          "$slotCount slot" . ($slotCount > 1 ? 's' : '') . " at $courtName not approved." . ($note ? " Reason: $note" : ''), 'error']);
            setFlash('warn', "❌ $slotCount reservation" . ($slotCount > 1 ? 's' : '') . " rejected.");
        }
    }

    // ── admin_cancel ──────────────────────────────────────────
    if ($action === 'admin_cancel') {
        $cancelReason = trim(substr($_POST['cancel_reason'] ?? '', 0, 300));
        $rows = $resolveGroup();
        if (!empty($rows)) {
            $ids       = array_column($rows, 'id');
            $ph        = implode(',', array_fill(0, count($ids), '?'));
            $userId    = (int)$rows[0]['user_id'];
            $courtName = $rows[0]['court_name'];
            $slotCount = count($rows);
            $db->prepare("
                UPDATE falcon.reservations
                SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?, admin_note=?, updated_at=NOW()
                WHERE id IN ($ph) AND status IN ('confirmed','pending')
            ")->execute(array_merge([$adminId, $cancelReason ?: null, $note ?: null], $ids));

            // Refund wallet if any confirmed rows
            $confirmedRows = array_filter($rows, function($r) {
                return $r['status'] === 'confirmed';
            });
            if (!empty($confirmedRows)) {
                $pricePerHour = $getResvPricePerHour((int)$rows[0]['court_id']);
                $refundAmount = $calcTotalCost(array_values($confirmedRows), $pricePerHour);
                if ($refundAmount > 0) {
                    try {
                        $db->prepare("UPDATE falcon.wallets SET balance = balance + ?, updated_at = NOW() WHERE user_id = ?")
                           ->execute([$refundAmount, $userId]);
                        try {
                            $db->prepare("INSERT INTO falcon.wallet_transactions (user_id,amount,type,description,created_at) VALUES(?,?,'credit',?,NOW())")
                               ->execute([$userId, $refundAmount, "Admin cancelled confirmed booking — refund: $slotCount slot(s) at $courtName"]);
                        } catch (PDOException $e) { error_log('wallet_transactions refund skipped: ' . $e->getMessage()); }
                    } catch (PDOException $e) { error_log('admin_cancel refund error: ' . $e->getMessage()); }
                }
            }

            $db->prepare("INSERT INTO falcon.notifications(user_id,title,message,type) VALUES(?,?,?,?)")
               ->execute([$userId, "❌ Reservation" . ($slotCount > 1 ? 's' : '') . " Cancelled by Admin",
                          "$slotCount slot" . ($slotCount > 1 ? 's' : '') . " at $courtName cancelled." . ($cancelReason ? " Reason: $cancelReason" : ''), 'error']);
            setFlash('warn', "❌ $slotCount reservation" . ($slotCount > 1 ? 's' : '') . " cancelled.");
        }
    }

    redirect('admin/schedule.php?date='.$selectedDate.'&court='.$selectedCourt.'&cal_year='.$calYear.'&cal_month='.$calMonth);
}

// ── Query data ────────────────────────────────────────────────
$pendingAll = $db->query("
    SELECT r.*, u.username, u.full_name, c.name AS court_name
    FROM falcon.reservations r
    JOIN falcon.users  u ON u.id = r.user_id
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.status = 'pending'
    ORDER BY r.slot_date ASC, r.slot_time ASC
")->fetchAll();

$dayResStmt = $db->prepare("
    SELECT r.*, u.username, u.full_name
    FROM falcon.reservations r
    JOIN falcon.users u ON u.id = r.user_id
    WHERE r.court_id = ? AND r.slot_date = ?
      AND r.status IN ('pending','confirmed','cancelled')
    ORDER BY r.slot_time ASC
");
$dayResStmt->execute([$selectedCourt, $selectedDate]);
$dayReservations = $dayResStmt->fetchAll();

$resBySlot = [];
foreach ($dayReservations as $r) {
    $key = substr($r['slot_time'], 0, 5);
    $resBySlot[$key][] = $r;
}

$groupSizeMap = [];
if (!empty($dayReservations)) {
    $gids = array_values(array_unique(array_filter(array_column($dayReservations, 'booking_group_id'))));
    if (!empty($gids)) {
        $ph   = implode(',', array_fill(0, count($gids), '?'));
        $gStmt = $db->prepare("SELECT booking_group_id, COUNT(*) AS cnt FROM falcon.reservations WHERE booking_group_id IN ($ph) GROUP BY booking_group_id");
        $gStmt->execute($gids);
        foreach ($gStmt->fetchAll() as $gr) $groupSizeMap[$gr['booking_group_id']] = (int)$gr['cnt'];
    }
}

// Pricing for cost preview
$courtPricePerHour = 0.0;
try {
    $cpStmt = $db->prepare("SELECT value FROM falcon.court_settings WHERE court_id = ? AND key = 'reservation_price_per_hour'");
    $cpStmt->execute([$selectedCourt]);
    $cpVal = $cpStmt->fetchColumn();
    if ($cpVal !== false) $courtPricePerHour = (float)$cpVal;
} catch (PDOException $e) {}

$daySlots = buildAllSlots($selectedDate, $hoursMap, $gameDuration);
$daySlotGroups = [];
$prev = null;
foreach ($daySlots as $s) {
    if ($s['type'] === 'hour') {
        if ($prev) $daySlotGroups[] = $prev;
        $prev = ['hour' => $s, 'half' => null];
    } elseif ($s['type'] === 'half' && $prev) {
        $prev['half'] = $s;
    }
}
if ($prev) $daySlotGroups[] = $prev;

$summaryStmt = $db->prepare("
    SELECT
        COUNT(*) FILTER (WHERE gs.status='completed') AS completed,
        COUNT(*) FILTER (WHERE gs.status='cancelled') AS cancelled,
        COUNT(*) FILTER (WHERE gs.status='active')    AS active,
        COALESCE(SUM(gp.credits_charged) FILTER (WHERE gs.status='completed'),0) AS revenue,
        COUNT(DISTINCT gp.user_id) AS unique_players
    FROM falcon.game_sessions gs
    LEFT JOIN falcon.game_players gp ON gp.session_id = gs.id
    WHERE DATE(gs.started_at) = ?
");
$summaryStmt->execute([$selectedDate]);
$day = $summaryStmt->fetch();

$statusMeta = [
    'pending'   => ['badge'=>'warn',   'icon'=>'⏳','label'=>'Pending'],
    'confirmed' => ['badge'=>'success','icon'=>'✅','label'=>'Confirmed'],
    'cancelled' => ['badge'=>'danger', 'icon'=>'❌','label'=>'Cancelled'],
];
$payMeta = [
    'unpaid'               => ['label'=>'Pay at Confirmation', 'color'=>'var(--muted)'],
    'pending_verification' => ['label'=>'Verifying Payment',   'color'=>'#f59e0b'],
    'paid'                 => ['label'=>'Paid ✓',              'color'=>'var(--success)'],
    'refunded'             => ['label'=>'Refunded',            'color'=>'var(--danger)'],
];

// ── Build safe JS data (no base64 blobs) ─────────────────────
// We pre-process proof URLs server-side so the JS only ever sees
// a plain https:// URL, never a multi-KB data-URI.
function buildResDataEntry(array $r): array {
    return [
        'id'                => $r['id'],
        'full_name'         => $r['full_name'],
        'username'          => $r['username'],
        'payment_method'    => $r['payment_method']  ?? 'in_person',
        'payment_proof_src' => proofSrc($r['payment_proof'] ?? ''),  // safe URL only
        'payment_ref'       => $r['payment_ref']     ?? '',
        'payment_amount'    => $r['payment_amount']  ?? '',
        'party_size'        => (int)$r['party_size'],
        'booking_group_id'  => $r['booking_group_id'] ?? '',
        'status'            => $r['status'],
    ];
}

$allVisibleRes = [];
foreach ($dayReservations as $r) {
    $allVisibleRes[$r['id']] = buildResDataEntry($r);
}
foreach ($pendingAll as $r) {
    if (!isset($allVisibleRes[$r['id']])) {
        $allVisibleRes[$r['id']] = buildResDataEntry($r);
    }
}

$groupDataMap = [];
foreach ($allVisibleRes as $entry) {
    $gid = $entry['booking_group_id'];
    if ($gid && !isset($groupDataMap[$gid])) $groupDataMap[$gid] = $entry;
}

$pageTitle = 'Court Schedule';
require_once __DIR__ . '/../includes/header.php';
$flash = getFlash();
?>

<style nonce="<?= getCspNonce() ?>">
.sched-layout { display:grid; grid-template-columns:300px 1fr; gap:20px; align-items:start; }
@media (max-width:900px) { .sched-layout { grid-template-columns:1fr; } }

.court-tabs { display:flex; gap:8px; margin-bottom:18px; overflow-x:auto; flex-wrap:nowrap; scrollbar-width:none; padding-bottom:2px; }
.court-tabs::-webkit-scrollbar { display:none; }
.tab-link { padding:8px 18px; border-radius:20px; font-size:13px; font-weight:600; text-decoration:none; white-space:nowrap; border:2px solid var(--border); color:var(--muted); background:transparent; transition:all 0.15s; flex-shrink:0; }
.tab-link.active { border-color:var(--accent); color:var(--accent); background:rgba(0,229,160,0.08); }

.date-nav { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.date-nav-label { flex:1; text-align:center; font-family:'Bebas Neue',sans-serif; font-size:clamp(14px,3vw,21px); letter-spacing:1px; min-width:0; word-break:break-word; }

.daily-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.daily-hour-row { display:grid; grid-template-columns:68px 1fr; border-bottom:1px solid var(--border); }
.daily-half-row { display:none; grid-template-columns:68px 1fr; border-bottom:1px solid rgba(255,255,255,0.04); background:rgba(255,255,255,0.01); }
.daily-half-row.expanded { display:grid; }
.daily-time-col { display:flex; flex-direction:column; justify-content:center; align-items:flex-end; padding:10px 9px 10px 0; border-right:2px solid var(--border); text-align:right; min-height:62px; gap:1px; }
.daily-time-col.half-time { background:rgba(255,255,255,0.01); min-height:50px; }
.time-main  { font-size:12px; font-weight:700; font-family:monospace; color:var(--text); line-height:1.2; white-space:nowrap; }
.time-sub   { font-size:9px; color:var(--muted); letter-spacing:0.06em; text-transform:uppercase; }
.time-end   { font-size:8px; color:rgba(255,255,255,0.18); font-family:monospace; }
.daily-time-col.half-time .time-main { font-size:10px; color:var(--muted); }
.daily-slot-content { padding:8px 10px; min-height:62px; display:flex; flex-direction:column; gap:5px; justify-content:center; }
.daily-slot-content.half-content { min-height:50px; background:rgba(255,255,255,0.01); }

.cap-bar { display:flex; align-items:center; gap:6px; margin-bottom:3px; }
.cap-track { flex:1; height:4px; background:var(--surface2); border-radius:2px; overflow:hidden; max-width:110px; }
.cap-fill  { height:100%; border-radius:2px; transition:width 0.3s; }
.cap-label { font-size:10px; font-weight:700; color:var(--muted); white-space:nowrap; }
.cap-tag   { font-size:9px; font-weight:700; letter-spacing:0.06em; padding:1px 5px; border-radius:8px; }
.cap-tag.full    { background:rgba(239,68,68,0.15);   color:var(--danger); }
.cap-tag.partial { background:rgba(245,158,11,0.15);  color:#f59e0b; }
.cap-tag.open    { background:rgba(0,229,160,0.10);   color:var(--accent); }

.booking-chip { padding:9px 12px; border-radius:10px; font-size:13px; gap:10px; border:1px solid transparent; margin-bottom:4px; }
.booking-chip:last-child { margin-bottom:0; }
.booking-chip-pending   { background:rgba(245,158,11,0.09); border-color:rgba(245,158,11,0.3); }
.booking-chip-confirmed { background:rgba(0,229,160,0.07);  border-color:rgba(0,229,160,0.28); }
.booking-chip-cancelled { background:rgba(239,68,68,0.06);  border-color:rgba(239,68,68,0.2);  opacity:.55; }

.chip-layout { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.chip-body   { flex:1; min-width:0; }
.chip-name   { font-weight:700; word-break:break-word; line-height:1.3; }
.chip-meta   { font-size:11px; color:var(--muted); margin-top:3px; display:flex; flex-wrap:wrap; gap:4px; }
.chip-pay    { font-size:10px; font-weight:700; margin-top:4px; }
.chip-actions { display:flex; align-items:center; gap:5px; flex-shrink:0; flex-wrap:wrap; margin-top:2px; }

.btn-review-confirm {
    border-radius:7px; padding:7px 12px; font-size:11px; font-weight:700; cursor:pointer;
    white-space:nowrap; touch-action:manipulation; min-height:34px; border:1.5px solid;
    background:rgba(0,229,160,0.14); border-color:var(--accent); color:var(--accent);
    transition:background 0.14s;
}
.btn-review-confirm:hover { background:rgba(0,229,160,0.28); }

.btn-reject, .btn-admin-cancel {
    border-radius:7px; padding:6px 11px; font-size:11px; font-weight:700; cursor:pointer;
    white-space:nowrap; touch-action:manipulation; min-height:34px; border:1.5px solid;
    transition:background 0.14s;
}
.btn-reject       { background:rgba(239,68,68,0.09); border-color:var(--danger); color:var(--danger); }
.btn-reject:hover { background:rgba(239,68,68,0.22); }
.btn-admin-cancel { background:rgba(239,68,68,0.07); border-color:rgba(239,68,68,0.4); color:var(--danger); }
.btn-admin-cancel:hover { background:rgba(239,68,68,0.18); }

.no-bookings-slot { font-size:12px; color:var(--muted); opacity:0.45; }

.admin-half-expander { display:grid; grid-template-columns:68px 1fr; height:16px; align-items:center; }
.admin-half-time-col { display:flex; justify-content:flex-end; align-items:center; padding-right:9px; border-right:2px solid var(--border); height:16px; }
.admin-half-toggle-btn {
    background:none; border:none; cursor:pointer; color:var(--muted); font-size:9px; font-family:monospace;
    letter-spacing:0.04em; padding:2px 3px; border-radius:4px; transition:all 0.15s;
    display:inline-flex; align-items:center; gap:3px; touch-action:manipulation; white-space:nowrap;
}
.admin-half-toggle-btn:hover { color:var(--accent); background:rgba(0,229,160,0.08); }
.admin-half-toggle-btn.expanded { color:var(--accent); }
.admin-half-toggle-btn .plus-icon { width:11px; height:11px; border-radius:50%; border:1.5px solid currentColor; display:inline-flex; align-items:center; justify-content:center; font-size:9px; font-weight:700; flex-shrink:0; transition:transform 0.2s; }
.admin-half-toggle-btn.expanded .plus-icon { transform:rotate(45deg); }
.admin-half-expander-line { flex:1; height:1px; background:var(--border); opacity:0.4; margin:0 8px; }
.admin-half-content { display:flex; align-items:center; height:16px; padding-left:10px; }
.is-past .daily-time-col { opacity:0.4; }
.is-past .daily-slot-content { opacity:0.45; }

.proof-thumb { width:44px; height:44px; object-fit:cover; border-radius:6px; border:1.5px solid var(--border); cursor:pointer; flex-shrink:0; transition:transform 0.15s; }
.proof-thumb:hover { transform:scale(1.08); }

.pending-item { display:block; background:var(--surface2); border-radius:10px; padding:10px 13px; border-left:3px solid var(--warn); text-decoration:none; transition:background .15s; margin-bottom:6px; }
.pending-item:hover { background:rgba(245,158,11,0.07); }

.chip-group-badge { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; color:var(--accent2); background:rgba(0,184,255,0.08); border:1px solid rgba(0,184,255,0.2); border-radius:12px; padding:2px 7px; margin-top:3px; white-space:nowrap; }

.admin-modal-overlay { display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.78); backdrop-filter:blur(4px); align-items:flex-end; justify-content:center; padding:0; }
.admin-modal-overlay.open { display:flex; }
.admin-modal-box { background:var(--surface); border:1px solid var(--border); border-radius:22px 22px 0 0; padding:22px 18px 30px; width:100%; max-width:520px; max-height:92vh; overflow-y:auto; -webkit-overflow-scrolling:touch; }
.modal-drag { width:40px; height:4px; background:var(--border); border-radius:2px; margin:0 auto 16px; }
@media (min-width:640px) { .admin-modal-overlay { align-items:center; padding:16px; } .admin-modal-box { border-radius:18px; padding:26px 22px; } .modal-drag { display:none; } }

.modal-title { font-family:'Bebas Neue',sans-serif; font-size:22px; margin-bottom:4px; }
.modal-sub   { font-size:13px; color:var(--muted); margin-bottom:16px; }

.form-group { margin-bottom:13px; }
.form-group label { display:block; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:5px; }
.form-group textarea, .form-group input { width:100%; background:var(--surface); border:1.5px solid var(--border); border-radius:8px; padding:9px 12px; color:var(--text); font-size:14px; font-family:inherit; transition:border-color 0.15s; -webkit-appearance:none; }
.form-group textarea:focus, .form-group input:focus { border-color:var(--accent); outline:none; box-shadow:0 0 0 2px rgba(0,229,160,0.14); }
.modal-btn-row { display:flex; gap:9px; margin-top:14px; flex-wrap:wrap; }
.modal-btn-row .btn-primary, .modal-btn-row .btn-danger, .modal-btn-row .btn-outline { flex:1; min-width:100px; text-align:center; touch-action:manipulation; padding:12px 14px; font-size:14px; }

.proof-review-box { background:rgba(0,184,255,0.05); border:1px solid rgba(0,184,255,0.25); border-radius:10px; padding:12px 14px; margin-bottom:14px; }
.proof-review-box .ref-val { font-family:monospace; color:var(--accent2); font-size:14px; word-break:break-all; }
.proof-review-big { width:100%; max-height:220px; object-fit:contain; border-radius:8px; border:1.5px solid var(--border); cursor:zoom-in; margin-top:8px; background:#111; }
.cost-preview { background:rgba(0,229,160,0.07); border:1px solid rgba(0,229,160,0.2); border-radius:8px; padding:10px 12px; font-size:13px; margin-bottom:14px; }
.cost-preview strong { color:var(--accent); font-size:16px; }

.proof-lightbox { display:none; position:fixed; inset:0; z-index:2000; background:rgba(0,0,0,0.92); align-items:center; justify-content:center; padding:16px; }
.proof-lightbox.open { display:flex; }
.proof-lightbox img { max-width:100%; max-height:90vh; border-radius:10px; object-fit:contain; }

@media (max-width:600px) {
    .daily-hour-row, .daily-half-row, .admin-half-expander { grid-template-columns:54px 1fr; }
    .daily-time-col { padding:8px 6px 8px 0; min-height:54px; }
    .time-main { font-size:10px; }
    .booking-chip { font-size:12px; padding:8px 10px; }
    .chip-actions { width:100%; justify-content:flex-end; }
}
</style>

<div class="page-header flex-between">
    <div>
        <h1>Court Schedule</h1>
        <p>Reservations · Confirmations · Management</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="?date=<?= date('Y-m-d') ?>&court=<?= $selectedCourt ?>&cal_year=<?= date('Y') ?>&cal_month=<?= date('n') ?>" class="btn-outline btn-sm">Today</a>
        <a href="<?= APP_URL ?>/admin/payment_settings.php" class="btn-outline btn-sm" style="border-color:rgba(0,229,160,0.4);color:var(--accent);">💳 Payment Settings</a>
        <a href="<?= APP_URL ?>/admin/export_csv.php?type=games&date_from=<?= $selectedDate ?>&date_to=<?= $selectedDate ?>" class="btn-outline btn-sm">⬇ CSV</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warn' ? 'warn' : 'error') ?>" style="margin-bottom:16px;border-radius:10px;">
    <?= clean($flash['message']) ?>
</div>
<?php endif; ?>

<div style="background:rgba(0,229,160,0.06);border:1px solid rgba(0,229,160,0.2);border-radius:10px;padding:10px 16px;margin-bottom:18px;font-size:13px;color:var(--muted);">
    💡 <strong style="color:var(--text);">Wallet deduction happens here:</strong>
    Clicking <strong style="color:var(--accent);">Review &amp; Confirm</strong> opens a preview of the payment proof.
    Submitting <strong>deducts the player's wallet</strong> and marks the reservation confirmed.
</div>

<?php if (count($pendingAll) > 0): ?>
<div style="background:rgba(245,158,11,0.10);border:1px solid var(--warn);border-radius:12px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="font-size:14px;font-weight:600;">
        ⏳ <strong><?= count($pendingAll) ?></strong> pending reservation<?= count($pendingAll)!==1?'s':'' ?> awaiting your review
    </div>
    <a href="#daily-section" class="btn-outline btn-sm" style="border-color:var(--warn);color:var(--warn);">Review ↓</a>
</div>
<?php endif; ?>

<div class="dashboard-grid stats-5col mb-3">
    <div class="stat-card"><div class="stat-val" style="color:var(--warn);"><?= count($pendingAll) ?></div><div class="stat-label">Pending</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--success);"><?= $day['completed'] ?></div><div class="stat-label">Completed</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--danger);"><?= $day['cancelled'] ?></div><div class="stat-label">Cancelled</div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--accent);">₱<?= number_format($day['revenue'],0) ?></div><div class="stat-label">Revenue</div></div>
    <div class="stat-card"><div class="stat-val"><?= $day['unique_players'] ?></div><div class="stat-label">Players</div></div>
</div>

<?php if (count($courts) > 1): ?>
<div class="court-tabs">
    <?php foreach ($courts as $c): ?>
        <a href="?date=<?= $selectedDate ?>&court=<?= $c['id'] ?>&cal_year=<?= $calYear ?>&cal_month=<?= $calMonth ?>"
           class="tab-link <?= $c['id']===$selectedCourt?'active':'' ?>">🏓 <?= clean($c['name']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sched-layout">

    <!-- LEFT SIDEBAR -->
    <div style="display:flex;flex-direction:column;gap:18px;min-width:0;">

        <!-- Calendar -->
        <div class="cal-wrap">
            <div class="cal-header">
                <a href="?date=<?= $selectedDate ?>&court=<?= $selectedCourt ?>&cal_year=<?= $calPrevYear ?>&cal_month=<?= $calPrevMonth ?>" class="cal-nav">‹</a>
                <div class="cal-title"><?= date('F Y', mktime(0,0,0,$calMonth,1,$calYear)) ?></div>
                <a href="?date=<?= $selectedDate ?>&court=<?= $selectedCourt ?>&cal_year=<?= $calNextYear ?>&cal_month=<?= $calNextMonth ?>" class="cal-nav">›</a>
            </div>
            <div class="cal-grid">
                <?php foreach (['S','M','T','W','T','F','S'] as $dow): ?>
                    <div class="cal-dow"><?= $dow ?></div>
                <?php endforeach; ?>
                <?php
                $firstDow    = (int)date('w', strtotime($calStart));
                $daysInMonth = (int)date('t', strtotime($calStart));
                $todayStr    = date('Y-m-d');
                for ($i = 0; $i < $firstDow; $i++): ?>
                    <div class="cal-cell other-month"><div class="cal-day-num"><?= (int)date('j',strtotime($calStart." -".($firstDow-$i)." days")) ?></div></div>
                <?php endfor;
                for ($d = 1; $d <= $daysInMonth; $d++):
                    $cellDate   = sprintf('%04d-%02d-%02d',$calYear,$calMonth,$d);
                    $isSelected = ($cellDate===$selectedDate);
                    $isTodayC   = ($cellDate===$todayStr);
                    $classes    = 'cal-cell'.($isSelected?' is-selected':'').($isTodayC?' is-today':'');
                ?>
                    <a href="?date=<?= $cellDate ?>&court=<?= $selectedCourt ?>&cal_year=<?= $calYear ?>&cal_month=<?= $calMonth ?>"
                       class="<?= $classes ?>" style="text-decoration:none;display:block;">
                        <div class="cal-day-num"><?= $d ?></div>
                        <div class="cal-dots">
                            <?php for($x=0;$x<min((int)($calData[$cellDate]['pending']??0),3);$x++) echo '<span class="cal-dot pending"></span>'; ?>
                            <?php for($x=0;$x<min((int)($calData[$cellDate]['confirmed']??0),2);$x++) echo '<span class="cal-dot confirmed"></span>'; ?>
                        </div>
                    </a>
                <?php endfor;
                $lastDow = (int)date('w', strtotime($calEnd));
                if ($lastDow < 6): for ($i=$lastDow+1;$i<=6;$i++): ?>
                    <div class="cal-cell other-month"><div class="cal-day-num"><?= $i-$lastDow ?></div></div>
                <?php endfor; endif; ?>
            </div>
            <div class="cal-legend">
                <div class="cal-legend-item"><span class="cal-legend-dot" style="background:#f59e0b;"></span> Pending</div>
                <div class="cal-legend-item"><span class="cal-legend-dot" style="background:#00e5a0;"></span> Confirmed</div>
            </div>
        </div>

        <!-- All Pending -->
        <div class="card" style="<?= count($pendingAll)>0?'border-color:rgba(245,158,11,0.4);':'' ?>">
            <div class="card-title">⏳ All Pending
                <?php if (count($pendingAll)>0): ?>
                    <span class="nav-badge" style="margin-left:6px;"><?= count($pendingAll) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-subtitle">All courts · all dates</div>
            <hr class="divider"/>
            <?php if (empty($pendingAll)): ?>
                <div style="text-align:center;padding:18px;color:var(--muted);font-size:13px;">🎉 All caught up!</div>
            <?php else: ?>
                <div style="max-height:320px;overflow-y:auto;display:flex;flex-direction:column;">
                    <?php foreach ($pendingAll as $p): ?>
                        <a href="?date=<?= $p['slot_date'] ?>&court=<?= $selectedCourt ?>&cal_year=<?= $calYear ?>&cal_month=<?= $calMonth ?>#daily-section" class="pending-item">
                            <div style="font-weight:700;font-size:13px;color:var(--text);">
                                <?= clean($p['full_name']) ?> <span style="font-weight:400;color:var(--muted);font-size:11px;">@<?= clean($p['username']) ?></span>
                            </div>
                            <div style="font-size:11px;color:var(--muted);margin-top:2px;">
                                <?= clean($p['court_name']) ?> · <?= date('M d', strtotime($p['slot_date'])) ?> · <?= date('g:i A', strtotime($p['slot_time'])) ?> · <?= $p['party_size'] ?> player<?= $p['party_size']>1?'s':'' ?>
                            </div>
                            <?php if (($p['payment_method'] ?? 'in_person') === 'online'): ?>
                                <div style="font-size:10px;color:#f59e0b;margin-top:2px;">💳 Online payment proof attached</div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Daily detail -->
    <div id="daily-section" style="display:flex;flex-direction:column;gap:18px;min-width:0;">

        <div class="date-nav">
            <a href="?date=<?= $prevDate ?>&court=<?= $selectedCourt ?>&cal_year=<?= $calYear ?>&cal_month=<?= $calMonth ?>" class="btn-outline btn-sm">← Prev</a>
            <div class="date-nav-label" style="color:<?= $isToday?'var(--accent)':'var(--text)' ?>;"><?= $dateLabel ?></div>
            <a href="?date=<?= $nextDate ?>&court=<?= $selectedCourt ?>&cal_year=<?= $calYear ?>&cal_month=<?= $calMonth ?>" class="btn-outline btn-sm">Next →</a>
        </div>

        <div class="card">
            <div class="flex-between mb-2">
                <div>
                    <div class="card-title">📅 Reservations</div>
                    <div class="card-subtitle">
                        <?= clean($court['name']??'Court') ?> · <?= count($dayReservations) ?> booking<?= count($dayReservations)!==1?'s':'' ?>
                        <?php if ($isToday && $day['active']>0): ?>
                            <span class="badge badge-success" style="margin-left:6px;">🔴 Live</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size:10px;color:var(--muted);font-family:monospace;text-align:right;">
                    Tap <span style="color:var(--accent);font-weight:700;">+</span> for :30 slots
                </div>
            </div>
            <hr class="divider"/>

            <?php if (empty($daySlotGroups)): ?>
                <div style="text-align:center;padding:36px;color:var(--muted);">Court is closed on this day.</div>
            <?php else: ?>
            <div class="daily-table-wrap">
            <?php foreach ($daySlotGroups as $gIdx => $grp):
                $hSlot = $grp['hour'];
                $fSlot = $grp['half'];
                $halfId = $fSlot ? 'adm-half-' . str_replace(':','-',$fSlot['start']) : null;
                $hKey   = $hSlot['start'];

                $hBookings   = $resBySlot[$hKey] ?? [];
                $hBooked     = array_sum(array_column(array_filter($hBookings,function($b) {
                    return in_array($b['status'], ['pending','confirmed']);
                }),'party_size'));
                $hSpotsLeft  = PLAYERS_PER_GAME - $hBooked;
                $hIsPast     = ($selectedDate < date('Y-m-d')) || ($selectedDate===date('Y-m-d') && $hSlot['start']<=date('H:i'));
                $hPct        = PLAYERS_PER_GAME>0 ? round($hBooked/PLAYERS_PER_GAME*100) : 0;
                $hColor      = $hPct>=100?'#ef4444':($hPct>=50?'#f59e0b':'#00e5a0');
                $hts         = strtotime("2000-01-01 " . $hSlot['start']);
            ?>
            <div class="daily-hour-row <?= $hIsPast ? 'is-past' : '' ?>">
                <div class="daily-time-col">
                    <span class="time-main"><?= date('g:i', $hts) ?></span>
                    <span class="time-sub"><?= date('A', $hts) ?></span>
                    <span class="time-end">–<?= date('g:i', strtotime("2000-01-01 ".$hSlot['end'])) ?></span>
                </div>
                <div class="daily-slot-content">
                    <?php if ($hBooked > 0): ?>
                    <div class="cap-bar">
                        <div class="cap-track"><div class="cap-fill" style="width:<?= $hPct ?>%;background:<?= $hColor ?>;"></div></div>
                        <span class="cap-label" style="color:<?= $hColor ?>;"><?= $hBooked ?>/<?= PLAYERS_PER_GAME ?></span>
                        <?php $ct = $hBooked>=PLAYERS_PER_GAME?'full':($hBooked>0?'partial':'open'); $cl = $hBooked>=PLAYERS_PER_GAME?'FULL':$hSpotsLeft.' left'; ?>
                        <span class="cap-tag <?= $ct ?>"><?= $cl ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($hBookings)): ?>
                        <div class="no-bookings-slot"><?= $hIsPast ? '— no bookings' : 'No reservations yet' ?></div>
                    <?php else:
                        foreach ($hBookings as $b):
                            $sm        = $statusMeta[$b['status']] ?? $statusMeta['pending'];
                            $pm        = $payMeta[$b['payment_status'] ?? 'unpaid'] ?? $payMeta['unpaid'];
                            $chipCls   = 'booking-chip-' . ($b['status']==='pending' ? 'pending' : ($b['status']==='confirmed' ? 'confirmed' : 'cancelled'));
                            $isOnline  = ($b['payment_method'] ?? 'in_person') === 'online';
                            $bGroupId  = $b['booking_group_id'] ?? '';
                            $bGroupSize= $bGroupId ? ($groupSizeMap[$bGroupId] ?? 1) : 1;
                            $isMulti   = $bGroupSize > 1;
                            $bGroupIdJs= addslashes($bGroupId);
                            $slotCostPreview = $courtPricePerHour; // 1 slot = 1 hour flat rate
                    ?>
                    <div class="booking-chip <?= $chipCls ?>">
                        <div class="chip-layout">
                            <div class="chip-body">
                                <div class="chip-name">
                                    <?= clean($b['full_name']) ?>
                                    <span style="font-weight:400;color:var(--muted);font-size:11px;">@<?= clean($b['username']) ?></span>
                                </div>
                                <div class="chip-meta">
                                    <span><?= $b['party_size'] ?> player<?= $b['party_size']>1?'s':'' ?></span>
                                    <?php if ($b['note']): ?><span>· <?= clean($b['note']) ?></span><?php endif; ?>
                                    <?php if ($isOnline): ?><span style="color:var(--accent2);">· 💳 Online<?= !empty($b['payment_proof']) ? ' (proof attached)' : ' (no proof)' ?></span><?php endif; ?>
                                    <?php if ($b['cancel_reason'] ?? ''): ?><span style="color:var(--danger);">· <?= clean($b['cancel_reason']) ?></span><?php endif; ?>
                                </div>
                                <?php if ($isMulti): ?>
                                <div style="margin-top:4px;"><span class="chip-group-badge">🔗 <?= $bGroupSize ?>-slot group booking</span></div>
                                <?php endif; ?>
                                <div class="chip-pay" style="color:<?= $pm['color'] ?>;"><?= $pm['label'] ?></div>
                                <?php if ($courtPricePerHour > 0 && $b['status'] === 'pending'): ?>
                                <div style="font-size:10px;color:var(--accent);margin-top:2px;">
                                    Est. deduction: ₱<?= number_format($slotCostPreview, 2) ?> (1 slot)
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="chip-actions" style="flex-direction:column;align-items:flex-end;">
                                <span class="badge badge-<?= $sm['badge'] ?>" style="font-size:10px;white-space:nowrap;"><?= $sm['icon'] ?> <?= $sm['label'] ?></span>
                                <?php if ($b['status']==='pending'): ?>
                                    <?php if ($isMulti): ?>
                                        <button class="btn-review-confirm" style="font-size:10px;"
                                                onclick="openReviewModal('','<?= $bGroupIdJs ?>','<?= addslashes(clean($b['full_name'])) ?>',<?= $bGroupSize ?>)">
                                            🔍 Review &amp; Confirm All (<?= $bGroupSize ?>)
                                        </button>
                                        <button class="btn-reject" style="font-size:10px;"
                                                onclick="openRejectModal('','<?= $bGroupIdJs ?>','<?= addslashes(clean($b['full_name'])) ?>',<?= $bGroupSize ?>)">
                                            ✕ Reject All
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-review-confirm"
                                                onclick="openReviewModal(<?= $b['id'] ?>,'','<?= addslashes(clean($b['full_name'])) ?>',1)">
                                            🔍 Review &amp; Confirm
                                        </button>
                                        <button class="btn-reject"
                                                onclick="openRejectModal(<?= $b['id'] ?>,'','<?= addslashes(clean($b['full_name'])) ?>',1)">
                                            ✕ Reject
                                        </button>
                                    <?php endif; ?>
                                <?php elseif ($b['status']==='confirmed'): ?>
                                    <?php if ($isMulti): ?>
                                        <button class="btn-admin-cancel" style="font-size:10px;"
                                                onclick="openCancelModal('','<?= $bGroupIdJs ?>','<?= addslashes(clean($b['full_name'])) ?>',<?= $bGroupSize ?>)">
                                            ✕ Cancel Group
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-admin-cancel"
                                                onclick="openCancelModal(<?= $b['id'] ?>,'','<?= addslashes(clean($b['full_name'])) ?>',1)">
                                            ✕ Cancel Booking
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <?php if ($fSlot):
                $fKey     = $fSlot['start'];
                $fBookings= $resBySlot[$fKey] ?? [];
                $fBooked  = array_sum(array_column(array_filter($fBookings,function($b) {
                    return in_array($b['status'], ['pending','confirmed']);
                }),'party_size'));
                $fIsPast  = ($selectedDate<date('Y-m-d'))||($selectedDate===date('Y-m-d')&&$fSlot['start']<=date('H:i'));
                $fts      = strtotime("2000-01-01 " . $fSlot['start']);
                $hasHalf  = !empty($fBookings);
            ?>
            <div class="admin-half-expander">
                <div class="admin-half-time-col">
                    <button class="admin-half-toggle-btn <?= $hasHalf?'expanded':'' ?>" id="adm-btn-<?= $halfId ?>"
                            onclick="toggleAdminHalf('<?= $halfId ?>',this)"
                            aria-expanded="<?= $hasHalf?'true':'false' ?>">
                        <span class="plus-icon">+</span>
                        <span><?= date('g:i', $fts) ?></span>
                    </button>
                </div>
                <div class="admin-half-content">
                    <div class="admin-half-expander-line"></div>
                    <?php if (!empty($fBookings)): ?>
                        <span style="font-size:9px;color:var(--warn);font-weight:700;white-space:nowrap;margin-right:6px;"><?= count($fBookings) ?> booking<?= count($fBookings)!==1?'s':'' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="daily-half-row <?= $hasHalf?'expanded':'' ?>" id="<?= $halfId ?>">
                <div class="daily-time-col half-time">
                    <span class="time-main"><?= date('g:i', $fts) ?></span>
                    <span class="time-sub"><?= date('A', $fts) ?></span>
                    <span class="time-end">–<?= date('g:i', strtotime("2000-01-01 ".$fSlot['end'])) ?></span>
                </div>
                <div class="daily-slot-content half-content">
                    <?php if (empty($fBookings)): ?>
                        <div class="no-bookings-slot"><?= $fIsPast?'— no bookings':'No reservations' ?></div>
                    <?php else:
                        foreach ($fBookings as $b):
                            $sm2  = $statusMeta[$b['status']] ?? $statusMeta['pending'];
                            $pm2  = $payMeta[$b['payment_status']??'unpaid'] ?? $payMeta['unpaid'];
                            $cc2  = 'booking-chip-'.($b['status']==='pending'?'pending':($b['status']==='confirmed'?'confirmed':'cancelled'));
                            $isOnline2 = ($b['payment_method']??'in_person')==='online';
                            $bGId2     = $b['booking_group_id'] ?? '';
                            $bGSize2   = $bGId2 ? ($groupSizeMap[$bGId2] ?? 1) : 1;
                            $isMulti2  = $bGSize2 > 1;
                            $bGIdJs2   = addslashes($bGId2);
                    ?>
                    <div class="booking-chip <?= $cc2 ?>">
                        <div class="chip-layout">
                            <div class="chip-body">
                                <div class="chip-name"><?= clean($b['full_name']) ?> <span style="font-weight:400;color:var(--muted);font-size:11px;">@<?= clean($b['username']) ?></span></div>
                                <div class="chip-meta">
                                    <span><?= $b['party_size'] ?> player<?= $b['party_size']>1?'s':'' ?></span>
                                    <?php if ($isOnline2): ?><span style="color:var(--accent2);">· 💳 Online</span><?php endif; ?>
                                </div>
                                <div class="chip-pay" style="color:<?= $pm2['color'] ?>;"><?= $pm2['label'] ?></div>
                            </div>
                            <div class="chip-actions" style="flex-direction:column;align-items:flex-end;">
                                <span class="badge badge-<?= $sm2['badge'] ?>" style="font-size:10px;white-space:nowrap;"><?= $sm2['icon'] ?> <?= $sm2['label'] ?></span>
                                <?php if ($b['status']==='pending'): ?>
                                    <?php if ($isMulti2): ?>
                                        <button class="btn-review-confirm" style="font-size:10px;"
                                                onclick="openReviewModal('','<?= $bGIdJs2 ?>','<?= addslashes(clean($b['full_name'])) ?>',<?= $bGSize2 ?>)">🔍</button>
                                        <button class="btn-reject" style="font-size:10px;"
                                                onclick="openRejectModal('','<?= $bGIdJs2 ?>','<?= addslashes(clean($b['full_name'])) ?>',<?= $bGSize2 ?>)">✕</button>
                                    <?php else: ?>
                                        <button class="btn-review-confirm"
                                                onclick="openReviewModal(<?= $b['id'] ?>,'','<?= addslashes(clean($b['full_name'])) ?>',1)">🔍</button>
                                        <button class="btn-reject"
                                                onclick="openRejectModal(<?= $b['id'] ?>,'','<?= addslashes(clean($b['full_name'])) ?>',1)">✕</button>
                                    <?php endif; ?>
                                <?php elseif ($b['status']==='confirmed'): ?>
                                    <?php if ($isMulti2): ?>
                                        <button class="btn-admin-cancel" style="font-size:10px;"
                                                onclick="openCancelModal('','<?= $bGIdJs2 ?>','<?= addslashes(clean($b['full_name'])) ?>',<?= $bGSize2 ?>)">✕ Cancel</button>
                                    <?php else: ?>
                                        <button class="btn-admin-cancel"
                                                onclick="openCancelModal(<?= $b['id'] ?>,'','<?= addslashes(clean($b['full_name'])) ?>',1)">✕ Cancel</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL 1: Review & Confirm -->
<div id="review-modal" class="admin-modal-overlay">
    <div class="admin-modal-box">
        <div class="modal-drag"></div>
        <div class="modal-title" id="rm-title">🔍 Review &amp; Confirm</div>
        <div class="modal-sub" id="rm-sub"></div>

        <div id="rm-proof-section" class="proof-review-box" style="display:none;">
            <div style="font-size:12px;font-weight:700;color:var(--accent2);margin-bottom:8px;">💳 Payment Proof</div>
            <div id="rm-proof-ref" style="margin-bottom:4px;"></div>
            <div id="rm-proof-amount" style="font-size:12px;color:var(--muted);margin-bottom:6px;"></div>
            <img id="rm-proof-img" src="" alt="Payment proof"
                 class="proof-review-big" onclick="openLightbox(this.src)"
                 style="display:none;" title="Click to enlarge"/>
            <div id="rm-proof-missing" style="display:none;font-size:12px;color:var(--muted);padding:10px;text-align:center;background:var(--surface2);border-radius:6px;">
                📎 No proof image uploaded.
            </div>
        </div>

        <div id="rm-inperson-notice" class="proof-review-box" style="display:none;background:rgba(0,229,160,0.05);border-color:rgba(0,229,160,0.2);">
            <div style="font-size:13px;color:var(--muted);">
                🏢 <strong style="color:var(--text);">In-person payment.</strong>
                Confirming will lock the slot and deduct the player's wallet.
            </div>
        </div>

        <div class="cost-preview" id="rm-cost-box" style="display:none;">
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Wallet Deduction</div>
            <div>₱<strong id="rm-cost-val">0.00</strong> will be deducted from <span id="rm-player-name2"></span>'s wallet.</div>
            <div style="font-size:11px;color:var(--muted);margin-top:3px;">This happens the moment you click "Confirm".</div>
        </div>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"            value="confirm">
            <input type="hidden" name="reservation_id"   id="rm-rid">
            <input type="hidden" name="booking_group_id" id="rm-gid">
            <div class="form-group">
                <label>Admin Note to Player <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <textarea name="admin_note" rows="2" maxlength="300" placeholder="e.g. Please arrive 10 mins early…" style="resize:none;"></textarea>
            </div>
            <div class="modal-btn-row">
                <button type="submit" class="btn-primary" id="rm-submit-btn">✅ Confirm &amp; Deduct Wallet</button>
                <button type="button" onclick="closeModal('review-modal')" class="btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Reject -->
<div id="reject-modal" class="admin-modal-overlay">
    <div class="admin-modal-box">
        <div class="modal-drag"></div>
        <div class="modal-title" id="rejm-title">❌ Reject Reservation</div>
        <div class="modal-sub" id="rejm-sub"></div>
        <p style="font-size:14px;margin-bottom:14px;line-height:1.6;color:var(--muted);">
            The reservation will be cancelled and the player notified. No wallet deduction will occur.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"            value="reject">
            <input type="hidden" name="reservation_id"   id="rejm-rid">
            <input type="hidden" name="booking_group_id" id="rejm-gid">
            <div class="form-group">
                <label>Reason <span style="color:var(--danger);">*</span></label>
                <textarea name="admin_note" rows="2" maxlength="300" placeholder="e.g. Slot conflict, payment could not be verified…" style="resize:none;" required></textarea>
            </div>
            <div class="modal-btn-row">
                <button type="submit" class="btn-danger" id="rejm-submit-btn">❌ Reject</button>
                <button type="button" onclick="closeModal('reject-modal')" class="btn-outline">Keep Pending</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Admin Cancel -->
<div id="cancel-modal" class="admin-modal-overlay">
    <div class="admin-modal-box">
        <div class="modal-drag"></div>
        <div class="modal-title" id="cm-title">❌ Cancel Booking</div>
        <div class="modal-sub" id="cm-sub"></div>
        <p style="font-size:14px;margin-bottom:14px;line-height:1.6;color:var(--muted);" id="cm-desc">
            Confirmed bookings will be <strong style="color:var(--accent);">automatically refunded</strong> to the player's wallet.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"            value="admin_cancel">
            <input type="hidden" name="reservation_id"   id="cm-rid">
            <input type="hidden" name="booking_group_id" id="cm-gid">
            <div class="form-group">
                <label>Reason <span style="color:var(--danger);">*</span></label>
                <textarea name="cancel_reason" rows="2" maxlength="300" placeholder="e.g. Court maintenance…" style="resize:none;" required></textarea>
            </div>
            <div class="modal-btn-row">
                <button type="submit" class="btn-danger" id="cm-submit-btn">✕ Cancel &amp; Refund</button>
                <button type="button" onclick="closeModal('cancel-modal')" class="btn-outline">Keep It</button>
            </div>
        </form>
    </div>
</div>

<!-- Proof Lightbox -->
<div id="proof-lightbox" class="proof-lightbox" onclick="closeLightbox()">
    <img id="lb-img" src="" alt="Payment proof">
</div>

<script nonce="<?= getCspNonce() ?>">
// ── Reservation data (safe URLs only — no base64 blobs) ───────
const RES_DATA   = <?= json_encode($allVisibleRes,  JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
// Compatibility aliases for any old rendered HTML
window.openLightbox = function(src) {
    document.getElementById('lb-img').src = src;
    document.getElementById('proof-lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
};
window.openGroupPayVerifyModal = function(rid, gid, name, count) {
    openReviewModal(rid, gid, name, count);
};
const GROUP_DATA = <?= json_encode($groupDataMap,   JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
const COURT_PRICE_PER_HOUR = <?= (float)$courtPricePerHour ?>;
const GAME_DURATION_MINS   = <?= (int)$gameDuration ?>;

// ── Modal helpers ─────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.admin-modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target===this){this.classList.remove('open');document.body.style.overflow='';} });
});
document.addEventListener('keydown', e => {
    if (e.key==='Escape') { document.querySelectorAll('.admin-modal-overlay.open').forEach(el=>el.classList.remove('open')); document.body.style.overflow=''; }
});

// ── Review & Confirm modal ────────────────────────────────────
function openReviewModal(rid, groupId, playerName, slotCount) {
    document.getElementById('rm-rid').value = rid || '';
    document.getElementById('rm-gid').value = groupId || '';
    document.getElementById('rm-player-name2').textContent = playerName + "'s";

    let resRow = null;
    if (groupId && GROUP_DATA[groupId]) resRow = GROUP_DATA[groupId];
    else if (rid && RES_DATA[rid])      resRow = RES_DATA[rid];

    document.getElementById('rm-title').textContent = slotCount > 1 ? `🔍 Review & Confirm ${slotCount} Slots` : '🔍 Review & Confirm';
    document.getElementById('rm-sub').textContent   = playerName + (slotCount > 1 ? ` · ${slotCount}-slot group` : '');

    const proofSection   = document.getElementById('rm-proof-section');
    const inpersonNotice = document.getElementById('rm-inperson-notice');
    const proofImg       = document.getElementById('rm-proof-img');
    const proofMissing   = document.getElementById('rm-proof-missing');
    const proofRef       = document.getElementById('rm-proof-ref');
    const proofAmount    = document.getElementById('rm-proof-amount');

    if (resRow && resRow.payment_method === 'online') {
        proofSection.style.display   = 'block';
        inpersonNotice.style.display = 'none';
        proofRef.innerHTML    = resRow.payment_ref
            ? `Ref #: <span class="ref-val">${resRow.payment_ref}</span>`
            : '<span style="color:var(--muted);">No reference number provided</span>';
        proofAmount.textContent = resRow.payment_amount
            ? `Amount: ₱${parseFloat(resRow.payment_amount).toFixed(2)}` : '';
        if (resRow.payment_proof_src) {
            proofImg.src           = resRow.payment_proof_src;
            proofImg.style.display = 'block';
            proofMissing.style.display = 'none';
        } else {
            proofImg.style.display     = 'none';
            proofMissing.style.display = 'block';
        }
    } else {
        proofSection.style.display   = 'none';
        inpersonNotice.style.display = 'block';
    }

    const costBox = document.getElementById('rm-cost-box');
    const costVal = document.getElementById('rm-cost-val');
// 1 slot = 1 hour at flat rate — no party size or duration math
    const total = COURT_PRICE_PER_HOUR > 0
        ? (COURT_PRICE_PER_HOUR * slotCount).toFixed(2) : null;
    if (total !== null && parseFloat(total) > 0) {
        costBox.style.display = 'block'; costVal.textContent = total;
    } else {
        costBox.style.display = 'none';
    }

    document.getElementById('rm-submit-btn').textContent =
        slotCount > 1 ? `✅ Confirm All ${slotCount} Slots & Deduct Wallet` : '✅ Confirm & Deduct Wallet';
    openModal('review-modal');
}

// ── Reject modal ──────────────────────────────────────────────
function openRejectModal(rid, groupId, playerName, slotCount) {
    document.getElementById('rejm-rid').value = rid || '';
    document.getElementById('rejm-gid').value = groupId || '';
    document.getElementById('rejm-title').textContent = slotCount > 1 ? `❌ Reject All ${slotCount} Slots` : '❌ Reject Reservation';
    document.getElementById('rejm-sub').textContent   = playerName + (slotCount > 1 ? ` · ${slotCount} slots` : '');
    document.getElementById('rejm-submit-btn').textContent = slotCount > 1 ? `❌ Reject All ${slotCount} Slots` : '❌ Reject';
    openModal('reject-modal');
}

// ── Admin cancel modal ────────────────────────────────────────
function openCancelModal(rid, groupId, playerName, slotCount) {
    document.getElementById('cm-rid').value = rid || '';
    document.getElementById('cm-gid').value = groupId || '';
    document.getElementById('cm-title').textContent = slotCount > 1 ? `❌ Cancel All ${slotCount} Slots` : '❌ Cancel Booking';
    document.getElementById('cm-sub').textContent   = playerName + (slotCount > 1 ? ` · ${slotCount} slots` : '');
    document.getElementById('cm-desc').innerHTML    = slotCount > 1
        ? `All ${slotCount} slots will be cancelled and the wallet amount will be <strong style="color:var(--accent);">refunded</strong>.`
        : 'This booking will be cancelled. The wallet deduction will be <strong style="color:var(--accent);">refunded</strong> to the player.';
    document.getElementById('cm-submit-btn').textContent = slotCount > 1 ? `✕ Cancel All ${slotCount} Slots & Refund` : '✕ Cancel & Refund';
    openModal('cancel-modal');
}

// ── Proof lightbox ────────────────────────────────────────────
function openLightbox(src) { document.getElementById('lb-img').src=src; document.getElementById('proof-lightbox').classList.add('open'); document.body.style.overflow='hidden'; }
function closeLightbox()   { document.getElementById('proof-lightbox').classList.remove('open'); document.body.style.overflow=''; }

// ── Half-slot toggle ──────────────────────────────────────────
function toggleAdminHalf(id, btn) {
    const row = document.getElementById(id);
    if (!row) return;
    const expanded = row.classList.toggle('expanded');
    if (btn) { btn.classList.toggle('expanded', expanded); btn.setAttribute('aria-expanded', expanded); }
}

<?php if ($isToday): ?>setTimeout(() => location.reload(), 60000);<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>