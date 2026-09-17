<?php
// ============================================================
//  FILE: player/schedule.php
//  FLOW:
//   • Player books → balance CHECKED → reservation inserted as
//     `pending` — NO wallet deduction yet
//   • Wallet is deducted only when admin confirms (admin/schedule.php)
// ============================================================
require_once __DIR__ . '/../config/app.php';
requireLogin();

date_default_timezone_set('Asia/Manila');

$db  = getDB();
$uid = $_SESSION['user_id'];

$balStmt = $db->prepare("SELECT balance FROM falcon.wallets WHERE user_id = ?");
$balStmt->execute([$uid]);
$balance = (float)($balStmt->fetchColumn() ?? 0);

$weekOffset = (int)($_GET['week'] ?? 0);
$weekOffset = max(-1, min(4, $weekOffset));
$today      = new DateTime();
$weekStart  = (clone $today)->modify('monday this week')->modify("$weekOffset weeks");
$weekEnd    = (clone $weekStart)->modify('+6 days');
$isThisWeek = ($weekOffset === 0);

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $weekStart)->modify("+$i days");
    $weekDays[] = $d->format('Y-m-d');
}

// ── Load payment options configured by admin ──────────────────
$paymentMethods = [];
try {
    $payOptRow = $db->prepare("SELECT value FROM falcon.site_content WHERE section = 'payment' AND key = 'options'");
    $payOptRow->execute();
    $row = $payOptRow->fetch();
    if ($row) {
        $decoded = json_decode($row['value'], true);
        if (is_array($decoded)) {
            $paymentMethods = array_values(array_filter($decoded, function($o) {
                if (!isset($o['active']) || $o['active'] === null) return true;
                if ($o['active'] === false) return false;
                return (bool)$o['active'];
            }));
        }
    }
} catch (PDOException $e) {}

$courts = [];
try {
    $courts = $db->query("SELECT * FROM falcon.v_court_status WHERE is_active = TRUE ORDER BY sort_order, id")->fetchAll();
} catch (PDOException $e) {
    error_log('[player/schedule] v_court_status query failed: ' . $e->getMessage());
    try {
        $courts = $db->query("SELECT *, 'available' AS live_status, 0 AS players_on_court, 0 AS queue_count FROM falcon.courts WHERE is_active = TRUE ORDER BY sort_order, id")->fetchAll();
    } catch (PDOException $fallback) {
        error_log('[player/schedule] fallback courts query failed: ' . $fallback->getMessage());
        $courts = [];
    }
}

$selectedCourt = (int)($_GET['court'] ?? ($courts[0]['id'] ?? 0));
$courtIds = array_column($courts, 'id');
if (!in_array($selectedCourt, $courtIds, true) && !empty($courtIds)) {
    $selectedCourt = $courtIds[0];
}

$bookableCourts = array_values(array_filter($courts, function($c) {
    return ($c['is_active'] ?? false)
        && !($c['is_maintenance'] ?? false)
        && (($c['live_status'] ?? '') === 'available');
}));
$bookableCourtIds = array_column($bookableCourts, 'id');
 $allowedCourtIds = !empty($bookableCourtIds) ? $bookableCourtIds : $courtIds;

if (!empty($bookableCourtIds) && !in_array($selectedCourt, $bookableCourtIds, true)) {
    $selectedCourt = $bookableCourtIds[0];
}

$displayCourts = !empty($bookableCourts) ? $bookableCourts : $courts;
$noAvailableCourts = empty($bookableCourts);

$court = null;
foreach ($courts as $c) {
    if ($c['id'] === $selectedCourt) { $court = $c; break; }
}
$noCourts = empty($courts);

if ($noCourts) {
    $pageTitle = 'Court Schedule';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <?= breadcrumb([
        ['label' => 'Home', 'href' => APP_URL],
        ['label' => 'Court Schedule']
    ]) ?>
    <?php
    $flash = getFlash();
    ?>
    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warn' ? 'warn' : 'error') ?>"
         style="margin-bottom:16px;border-radius:10px;">
        <?= clean($flash['message']) ?>
    </div>
    <?php endif; ?>

    <div class="page-header flex-between">
        <div>
            <h1>Court Schedule</h1>
            <p>No active courts are available right now.</p>
        </div>
    </div>

    <div class="card" style="padding:24px;max-width:720px;margin-bottom:24px;">
        <div style="font-size:16px;font-weight:700;color:var(--text);margin-bottom:12px;">⚠️ No courts available</div>
        <div style="color:var(--muted);line-height:1.8;">
            There are currently no active courts to display. Please contact your court administrator to activate a court, then refresh this page.
        </div>
        <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="<?= APP_URL ?>/player/dashboard.php" class="btn-primary">Back to Dashboard</a>
            <a href="<?= APP_URL ?>/player/help.php" class="btn-outline">Contact Support</a>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Reservation pricing settings ─────────────────────────────
$resvPricePerHour = 150;
$resvMinHours     = 2;
$resvMaxHours     = 8;
$resvDepositPct   = 50;
$resvEnabled      = true;
$resvAdvanceDays  = 14;
try {
    $rsStmt = $db->prepare("SELECT key, value FROM falcon.court_settings WHERE court_id = ?");
    $rsStmt->execute([$selectedCourt]);
    $rsMap = [];
    foreach ($rsStmt->fetchAll() as $row) $rsMap[$row['key']] = $row['value'];
    $resvPricePerHour = (float)($rsMap['reservation_price_per_hour'] ?? 150);
    $resvMinHours     = (float)($rsMap['reservation_min_hours']      ?? 2);
    $resvMaxHours     = (float)($rsMap['reservation_max_hours']      ?? 8);
    $resvDepositPct   = (int)($rsMap['reservation_deposit_pct']      ?? 50);
    $resvEnabled      = ($rsMap['reservation_enabled']               ?? '1') === '1';
    $resvAdvanceDays  = (int)($rsMap['reservation_advance_days']     ?? 14);
} catch (PDOException $e) {}

// ── Court slot modes ──────────────────────────────────────────
$slotModes = [];
try {
    $modeStmt = $db->prepare("
        SELECT time_from, time_to, mode
        FROM falcon.court_slot_modes
        WHERE court_id = ?
          AND (
            (slot_date = ?)
            OR (slot_date IS NULL AND day_of_week = EXTRACT(DOW FROM ?::date)::int)
          )
        ORDER BY CASE WHEN slot_date IS NOT NULL THEN 1 ELSE 2 END ASC
    ");
    $modeStmt->execute([$selectedCourt, $weekDays[0], $weekDays[0]]);
    foreach ($modeStmt->fetchAll() as $m) {
        $slotModes[] = $m;
    }
} catch (PDOException $e) {}

function getSlotMode(array $slotModes, string $time): string {
    foreach ($slotModes as $m) {
        if ($time >= substr($m['time_from'], 0, 5) && $time < substr($m['time_to'], 0, 5)) {
            return $m['mode'];
        }
    }
    return 'reservation';
}

$hoursMap = [];
try {
    $hStmt = $db->prepare("SELECT day_of_week, open_time, close_time, is_closed FROM falcon.court_hours WHERE court_id = ?");
    $hStmt->execute([$selectedCourt]);
    foreach ($hStmt->fetchAll() as $h) {
        $hoursMap[$h['day_of_week']] = $h;
    }
} catch (PDOException $e) {}

$gameDuration = $court ? (int)$court['game_duration'] : 60;
if ($gameDuration <= 0) {
    error_log('[player/schedule] invalid game_duration, falling back to 60');
    $gameDuration = 60;
}

// REPLACE the $stmtTodayCount block with:
$todayResData = ['count' => 0, 'total_players' => 0];
try {
    $stmtTodayCount = $db->prepare("
        SELECT COUNT(*) AS count,
               COALESCE(SUM(party_size), 0) AS total_players
        FROM falcon.reservations
        WHERE court_id = ? AND slot_date = CURRENT_DATE
          AND status IN ('pending','confirmed')
    ");
    $stmtTodayCount->execute([$selectedCourt]);
    $todayResData = $stmtTodayCount->fetch() ?: $todayResData;
} catch (Throwable $e) {
    error_log('[schedule] todayResData query: ' . $e->getMessage());
}


// ── Build 30-min-interval slots ───────────────────────────────
function buildAllSlots(string $date, array $hoursMap, int $gameDuration): array {
    $dow      = (int)(new DateTime($date))->format('w');
    $open     = $hoursMap[$dow]['open_time']  ?? '09:00:00';
    $close    = $hoursMap[$dow]['close_time'] ?? '00:00:00';
    $isClosed = $hoursMap[$dow]['is_closed']  ?? false;
    if ($isClosed) return [];

    $openTs = strtotime("$date $open");
    if (in_array($close, ['00:00:00', '24:00:00'])) {
        $nextDate = date('Y-m-d', strtotime("$date +1 day"));
        $closeTs  = strtotime("$nextDate 00:00:00");
    } else {
        $closeTs = strtotime("$date $close");
    }

    $slots    = [];
    $interval = 30 * 60;
    $cur      = $openTs;

    while ($cur < $closeTs) {
        $endTs = $cur + $gameDuration * 60;
        if ($endTs > $closeTs) { $cur += $interval; continue; }
        $mins    = (int)date('i', $cur);
        $slots[] = [
            'start' => date('H:i', $cur),
            'end'   => date('H:i', $endTs),
            'type'  => ($mins === 0) ? 'hour' : 'half',
        ];
        $cur += $interval;
    }
    return $slots;
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($noCourts) {
        setFlash('error', 'No active courts are available. Please contact the administrator.');
        redirect('player/schedule.php');
    }
    $action = $_POST['action'] ?? '';

    // ── Cancel ───────────────────────────────────────────────
    if ($action === 'cancel') {
        $rid = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
        if ($rid) {
            $own = $db->prepare("SELECT id, status FROM falcon.reservations WHERE id = ? AND user_id = ?");
            $own->execute([$rid, $uid]);
            $resRow = $own->fetch();
            if ($resRow) {
                if ($resRow['status'] === 'confirmed') {
                    setFlash('error', '❌ Cannot self-cancel a confirmed booking. Please contact admin.');
                } else {
                    $db->prepare("
                        UPDATE falcon.reservations
                        SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$uid, $rid]);
                    setFlash('success', '✅ Reservation cancelled.');
                }
            }
        }
        redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
    }

    // ── Multi-slot book ───────────────────────────────────────
    if ($action === 'book_multi') {
        try {
            $slotsRaw  = $_POST['selected_slots'] ?? '';
            $partySize = max(1, min(4, (int)($_POST['party_size'] ?? 1)));
            $note      = trim(substr($_POST['note'] ?? '', 0, 300));
            $courtBook = filter_input(INPUT_POST, 'court_id', FILTER_VALIDATE_INT);
            if ($courtBook && in_array($courtBook, $allowedCourtIds, true)) {
                $selectedCourt = $courtBook;
                foreach ($courts as $c) {
                    if ($c['id'] === $selectedCourt) { $court = $c; break; }
                }
            } elseif ($courtBook) {
                setFlash('error', 'Selected court is not currently available. Please choose another court.');
                redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
            }
            $payMethod = in_array($_POST['payment_method'] ?? '', ['in_person','online'])
                         ? $_POST['payment_method'] : 'in_person';
            $payRef    = trim(substr($_POST['payment_ref'] ?? '', 0, 100));
            $payAmount = filter_input(INPUT_POST, 'payment_amount', FILTER_VALIDATE_FLOAT);

        $selectedSlots = json_decode($slotsRaw, true);
        if (!is_array($selectedSlots) || empty($selectedSlots)) {
            setFlash('error', 'No slots selected.');
            redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
        }

        $slotCount      = count($selectedSlots);
        $effectiveHours = (float)$slotCount;

        if ($effectiveHours < $resvMinHours) {
            setFlash('error', "❌ Minimum reservation is {$resvMinHours} hour(s). "
                . "Your selection is only " . round($effectiveHours, 1) . " hour(s). Please select more slots.");
            redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
        }
        if ($effectiveHours > $resvMaxHours) {
            setFlash('error', "❌ Maximum reservation is {$resvMaxHours} hours. "
                . "Your selection is " . round($effectiveHours, 1) . " hours. Please select fewer slots.");
            redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
        }

        $totalCost = 0.0;
        foreach ($selectedSlots as $slot) {
            $slotModeCheck = getSlotMode($slotModes, $slot['time']);
            if ($slotModeCheck === 'open_play') {
                $totalCost += (float)$court['credit_cost'] * $partySize;
            } else {
                $totalCost += $resvPricePerHour;
            }
        }
        $totalCost = round($totalCost, 2);

        if ($balance < $totalCost) {
            setFlash('error', "❌ Insufficient balance. Required: ₱" . number_format($totalCost, 2)
                . " · Your balance: ₱" . number_format($balance, 2)
                . ". Please top up before booking.");
            redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
        }

        // ── Handle payment proof upload (online only) ─────────
        $proofFilename = null;
        $paymentProofName = $_FILES['payment_proof']['name'] ?? '';
        if ($payMethod === 'online') {
            if (empty($paymentProofName)) {
                setFlash('error', 'Please attach payment proof for online payment.');
                redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
            }
            $file = $_FILES['payment_proof'];
            $fileError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($fileError !== UPLOAD_ERR_OK) {
                setFlash('error', 'Upload error. Please try again.');
                redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
            }
            $fileSize = $file['size'] ?? 0;
            if ($fileSize > 25 * 1024 * 1024) {
                setFlash('error', 'File too large. Max 25MB.');
                redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) {
                setFlash('error', 'Unable to verify payment proof. Please try again later.');
                redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
            }
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!is_string($mime) || !in_array($mime, $allowedMimes, true)) {
                setFlash('error', 'Invalid file type. JPG/PNG/WEBP only.');
                redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
            }
            $raw = file_get_contents($file['tmp_name']);
            if ($raw === false) {
                setFlash('error', 'Unable to read uploaded payment proof. Please try again.');
                redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
            }
            $proofFilename = 'data:' . $mime . ';base64,' . base64_encode($raw);
        }

        $bookingGroupId = 'grp_' . bin2hex(random_bytes(16));

        $bookErrors = [];
        $inserted   = 0;
        $skipped    = [];

        foreach ($selectedSlots as $slot) {
            $slotDateRaw = $slot['date'];
            $slotTimeRaw = $slot['time'];
            $slotEndRaw  = $slot['end'];

            $chkStmt = $db->prepare("
                SELECT COALESCE(SUM(party_size), 0) AS booked
                FROM falcon.reservations
                WHERE court_id = ? AND slot_date = ? AND slot_time = ?
                  AND status IN ('pending','confirmed')
            ");
            $chkStmt->execute([$selectedCourt, $slotDateRaw, $slotTimeRaw]);
            $alreadyBooked = (int)$chkStmt->fetchColumn();
            $spotsLeft     = PLAYERS_PER_GAME - $alreadyBooked;

            if ($spotsLeft < $partySize) {
                $skipped[] = date('g:i A', strtotime($slotTimeRaw)) . ' on ' . $slotDateRaw . ' (no spots)';
                continue;
            }

            $slotDt   = new DateTime($slotDateRaw);
            $diffDays = (int)(new DateTime('today'))->diff($slotDt)->days;
            if ($diffDays > $resvAdvanceDays) {
                $skipped[] = $slotDateRaw . ' (too far ahead)';
                continue;
            }

            try {
                $insStmt = $db->prepare("
                    INSERT INTO falcon.reservations
                        (user_id, court_id, slot_date, slot_time, slot_end,
                         party_size, status, payment_method, payment_status,
                         payment_proof, payment_ref, payment_amount,
                         booking_group_id, note, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?,
                         ?, 'pending', ?, 'unpaid',
                         ?, ?, ?,
                         ?, ?, NOW(), NOW())
                ");
                $insStmt->execute([
                    $uid, $selectedCourt, $slotDateRaw, $slotTimeRaw, $slotEndRaw,
                    $partySize, $payMethod,
                    $proofFilename,
                    $payRef  ?: null,
                    $payAmount ?: null,
                    $bookingGroupId,
                    $note ?: null,
                ]);
                $inserted++;

                $adminIds = $db->query(
                    "SELECT id FROM falcon.users WHERE role IN ('admin','super_admin') AND is_banned = FALSE"
                )->fetchAll(PDO::FETCH_COLUMN);
                $adminMsg = "{$_SESSION['full_name']} (@{$_SESSION['username']}) booked "
                          . date('M d', strtotime($slotDateRaw)) . ' '
                          . date('g:i A', strtotime($slotTimeRaw)) . '–'
                          . date('g:i A', strtotime($slotEndRaw))
                          . " ({$partySize} player" . ($partySize > 1 ? 's' : '') . ") — $payMethod"
                          . ($payMethod === 'online' && $proofFilename ? " (proof attached)" : "");
                foreach ($adminIds as $aid) {
                    $db->prepare("INSERT INTO falcon.notifications (user_id, title, message, type) VALUES (?,?,?,'info')")
                       ->execute([$aid, '📅 New Reservation — ' . $_SESSION['full_name'], $adminMsg]);
                }
            } catch (PDOException $e) {
                error_log('reservation insert: ' . $e->getMessage());
                $bookErrors[] = 'Failed to book ' . $slotTimeRaw . ' on ' . $slotDateRaw . '.';
            }
        }

        if ($inserted > 0) {
            $db->prepare("INSERT INTO falcon.notifications (user_id, title, message, type)
                          VALUES (?, '📅 Reservation Submitted — Awaiting Approval', ?, 'info')")
               ->execute([
                   $uid,
                   "$inserted slot(s) submitted for admin approval. "
                   . "₱" . number_format($totalCost, 2) . " will be deducted from your wallet once confirmed."
                   . ($payMethod === 'online' ? " Your payment proof has been attached for review." : ""),
               ]);

            $msg = "✅ $inserted reservation" . ($inserted !== 1 ? 's' : '') . " submitted!"
                 . " Awaiting admin approval. ₱" . number_format($totalCost, 2)
                 . " will be deducted when confirmed.";
            if (!empty($skipped)) $msg .= " Skipped: " . implode(', ', $skipped) . '.';
            setFlash('success', $msg);
        } elseif (!empty($bookErrors)) {
            setFlash('error', implode(' ', $bookErrors));
        } else {
            setFlash('warn', 'No new reservations were created. ' . implode(', ', $skipped));
        }

        redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
} catch (Throwable $e) {
        error_log('[player/schedule] booking error: ' . $e->getMessage());
        setFlash('error', 'Something went wrong while processing your booking. Please try again or contact support.');
        redirect('player/schedule.php?week=' . $weekOffset . '&court=' . $selectedCourt);
    }
} // end if ($action === 'book_multi')

} // end if POST

// ── Fetch reservations for the week ──────────────────────────

// ── Fetch reservations for the week ──────────────────────────
$resStmt = $db->prepare("
    SELECT r.*, u.username, u.full_name FROM falcon.reservations r
    JOIN falcon.users u ON u.id = r.user_id
    WHERE r.court_id = ? AND r.slot_date BETWEEN ? AND ?
      AND r.status IN ('pending','confirmed')
    ORDER BY r.slot_date, r.slot_time
");
$resStmt->execute([$selectedCourt, $weekDays[0], $weekDays[6]]);
$weekReservations = $resStmt->fetchAll();

$resBySlot = [];
foreach ($weekReservations as $r) {
    $key = $r['slot_date'] . '_' . substr($r['slot_time'], 0, 5);
    $resBySlot[$key][] = $r;
}

// My upcoming bookings
$myResStmt = $db->prepare("
    SELECT r.*, c.name AS court_name FROM falcon.reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.user_id = ? AND r.slot_date >= CURRENT_DATE AND r.status IN ('pending','confirmed')
    ORDER BY r.slot_date, r.slot_time LIMIT 15
");
$myResStmt->execute([$uid]);
$myUpcoming = $myResStmt->fetchAll();

$myPastStmt = $db->prepare("
    SELECT r.*, c.name AS court_name FROM falcon.reservations r
    JOIN falcon.courts c ON c.id = r.court_id
    WHERE r.user_id = ? AND (r.slot_date < CURRENT_DATE OR r.status IN ('cancelled','used'))
    ORDER BY r.slot_date DESC, r.slot_time DESC LIMIT 5
");
$myPastStmt->execute([$uid]);
$myPast = $myPastStmt->fetchAll();

// Build master slot list for all days
$allWeekSlotKeys = [];
foreach ($weekDays as $d) {
    foreach (buildAllSlots($d, $hoursMap, $gameDuration) as $s) {
        $allWeekSlotKeys[$s['start']] = $s;
    }
}
ksort($allWeekSlotKeys);

$slotGroups = [];
$prev = null;
foreach (array_values($allWeekSlotKeys) as $s) {
    if ($s['type'] === 'hour') {
        if ($prev) $slotGroups[] = $prev;
        $prev = ['hour' => $s, 'half' => null];
    } elseif ($s['type'] === 'half' && $prev) {
        $prev['half'] = $s;
    }
}
if ($prev) $slotGroups[] = $prev;

$statusBadge = [
    'pending'   => ['badge' => 'warn',    'icon' => '⏳', 'label' => 'Pending'],
    'confirmed' => ['badge' => 'success', 'icon' => '✅', 'label' => 'Confirmed'],
    'cancelled' => ['badge' => 'danger',  'icon' => '❌', 'label' => 'Cancelled'],
    'used'      => ['badge' => 'info',    'icon' => '🎮', 'label' => 'Played'],
];
$payBadge = [
    'unpaid'               => ['label' => 'Pay on Confirmation', 'color' => 'var(--muted)'],
    'pending_verification' => ['label' => 'Verifying Payment',   'color' => '#f59e0b'],
    'paid'                 => ['label' => 'Paid ✓',              'color' => 'var(--success)'],
    'refunded'             => ['label' => 'Refunded',            'color' => 'var(--danger)'],
];

$pageTitle = 'Court Schedule';
require_once __DIR__ . '/../includes/header.php';
$flash = getFlash();

if ($noCourts) {
    if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warn' ? 'warn' : 'error') ?>"
             style="margin-bottom:16px;border-radius:10px;">
            <?= clean($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="page-header flex-between">
        <div>
            <h1>Court Schedule</h1>
            <p>No active courts are available right now.</p>
        </div>
    </div>

    <div class="card" style="padding:24px;max-width:720px;margin-bottom:24px;">
        <div style="font-size:16px;font-weight:700;color:var(--text);margin-bottom:12px;">⚠️ No courts available</div>
        <div style="color:var(--muted);line-height:1.8;">
            There are currently no active courts to display. Please contact your court administrator to activate a court, then refresh this page.
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php';
    exit;
}
?>

<style nonce="<?= getCspNonce() ?>">
/* ─────────────────────────────────────────
   SCHEDULE PAGE — Mobile-First v3 (fixed)
   ───────────────────────────────────────── */
.sched-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 22px;
    margin-bottom: 30px;
    align-items: start;
}
@media (max-width: 900px) { .sched-layout { grid-template-columns: 1fr; } }

.balance-strip {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 14px;
}

.court-tab-row { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
.court-tab {
    padding: 8px 18px; border-radius: 20px; font-size: 13px; font-weight: 600;
    text-decoration: none; white-space: nowrap; border: 2px solid var(--border);
    color: var(--muted); background: transparent; transition: all 0.15s;
}
.court-tab.active { border-color: var(--accent); color: var(--accent); background: rgba(0,229,160,0.08); }

.week-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; gap: 8px; }
.week-nav-title { text-align: center; flex: 1; min-width: 0; }
.week-nav-title .wnt-text {
    font-family: 'Bebas Neue', sans-serif;
    font-size: clamp(13px, 3vw, 19px);
    letter-spacing: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;
}

.selection-banner {
    display: none; align-items: center; justify-content: space-between; gap: 10px;
    background: rgba(0,229,160,0.1); border: 1.5px solid var(--accent); border-radius: 12px;
    padding: 10px 16px; margin-bottom: 12px; flex-wrap: wrap;
}
.selection-banner.visible { display: flex; }
.selection-count { font-size: 14px; font-weight: 700; color: var(--accent); }
.selection-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.sched-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -2px; padding: 0 2px 4px; }
.sched-table {
    width: 100%; min-width: 360px; border-collapse: separate; border-spacing: 0; table-layout: fixed;
}
.sched-table .col-time { width: 62px; }

.sched-table thead th {
    padding: 7px 3px; text-align: center; font-size: 11px; font-weight: 700;
    position: sticky; top: 0; background: var(--surface); z-index: 10;
    border-bottom: 2px solid var(--border);
}
.sched-table thead th.col-time { position: sticky; left: 0; z-index: 20; background: var(--surface); }
.sched-table thead .day-name { color: var(--muted); display: block; font-size: 9px; letter-spacing: 0.05em; }
.sched-table thead .day-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%; font-size: 13px; font-weight: 700; margin-top: 2px;
}
.day-num.is-today { background: rgba(0,229,160,0.18); color: var(--accent); }
.day-num.is-past  { color: var(--muted); opacity: 0.45; }

.sched-table .time-cell {
    position: sticky; left: 0; background: var(--surface); z-index: 5;
    padding: 0 7px 0 0; text-align: right; vertical-align: middle;
    white-space: nowrap; border-right: 2px solid var(--border);
}
.time-cell .t-main { font-size: 11px; font-weight: 700; font-family: monospace; color: var(--text); display: block; line-height: 1.2; }
.time-cell .t-sub  { font-size: 8px; color: var(--muted); letter-spacing: 0.04em; display: block; }
.time-cell.half-time .t-main { color: var(--muted); font-size: 10px; font-weight: 600; }

.sched-table td.slot-td { padding: 2px; vertical-align: middle; height: 50px; }
.sched-table tr.half-row td.slot-td { height: 44px; }

.slot-btn {
    display: flex; align-items: center; justify-content: center; flex-direction: column;
    width: 100%; height: 100%; border-radius: 7px; border: none; cursor: pointer;
    font-size: 10px; font-weight: 700; line-height: 1.3; transition: all 0.12s ease;
    position: relative; padding: 3px 2px; min-height: 44px;
    -webkit-tap-highlight-color: transparent; touch-action: manipulation; user-select: none;
    font-family: inherit;
}
.slot-btn .spots-num   { font-size: 15px; font-weight: 800; line-height: 1; }
.slot-btn .spots-label { font-size: 8px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; opacity: 0.8; margin-top: 1px; }
.slot-btn .slot-icon   { font-size: 16px; line-height: 1; }
.slot-btn .chk-icon    { font-size: 14px; line-height: 1; }

.slot-btn.avail {
    background: rgba(0,229,160,0.06); border: 1.5px dashed rgba(0,229,160,0.4); color: var(--accent);
}
.slot-btn.avail:hover, .slot-btn.avail:focus {
    background: rgba(0,229,160,0.16); border-style: solid; transform: scale(1.05);
    box-shadow: 0 2px 10px rgba(0,229,160,0.2); outline: none;
}
.slot-btn.avail:active { transform: scale(0.97); }
.slot-btn.avail.selected {
    background: rgba(0,229,160,0.28); border: 2px solid var(--accent);
    box-shadow: 0 0 0 2px rgba(0,229,160,0.25); transform: scale(1.02);
}
.slot-btn.mine-confirmed { background: rgba(0,229,160,0.22); border: 2px solid var(--success); color: var(--success); }
.slot-btn.mine-pending   { background: rgba(245,158,11,0.18); border: 2px solid var(--warn);    color: var(--warn); }
.slot-btn.mine-confirmed:hover, .slot-btn.mine-pending:hover { transform: scale(1.05); filter: brightness(1.1); }
.slot-btn.full {
    background: rgba(239,68,68,0.07); border: 1.5px solid rgba(239,68,68,0.25); color: var(--danger); cursor: default;
}
.slot-btn.open-play-mode  { background: rgba(0,184,255,0.07);  border: 1.5px dashed rgba(0,184,255,0.35); }
.slot-btn.reservation-mode { background: rgba(245,158,11,0.05); border: 1.5px dashed rgba(245,158,11,0.3); }
.mode-pill-mini { font-size: 7px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75; margin-top: 1px; }
.slot-btn.past-slot { background: transparent; border: 1.5px solid rgba(255,255,255,0.04); color: var(--muted); opacity: 0.25; cursor: default; }
.slot-btn.no-slot   { background: transparent; border: none; opacity: 0; cursor: default; }

.fill-dots { display: flex; gap: 2px; position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); }
.fill-dot  { width: 4px; height: 4px; border-radius: 50%; background: currentColor; opacity: 0.5; }
.fill-dot.filled { opacity: 1; }

.half-expander-row td { padding: 0; height: 13px; }
.half-expander-inner  { display: flex; align-items: center; gap: 0; height: 13px; }
.half-expander-time {
    position: sticky; left: 0; background: var(--surface); z-index: 5;
    width: 62px; min-width: 62px; display: flex; align-items: center;
    justify-content: flex-end; padding-right: 7px; border-right: 2px solid var(--border); height: 13px;
}
.half-toggle-btn {
    display: inline-flex; align-items: center; gap: 3px; background: none; border: none;
    cursor: pointer; color: var(--muted); font-size: 9px; font-family: monospace;
    letter-spacing: 0.04em; padding: 2px 3px; border-radius: 4px; transition: all 0.15s;
    white-space: nowrap; touch-action: manipulation; -webkit-tap-highlight-color: transparent; line-height: 1;
}
.half-toggle-btn:hover { color: var(--accent); background: rgba(0,229,160,0.08); }
.half-toggle-btn .plus-icon {
    width: 11px; height: 11px; border-radius: 50%; border: 1.5px solid currentColor;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; line-height: 1; transition: transform 0.2s; flex-shrink: 0;
}
.half-toggle-btn.expanded .plus-icon { transform: rotate(45deg); border-color: var(--accent); color: var(--accent); }
.half-toggle-btn.expanded { color: var(--accent); }
.half-expander-line { flex: 1; height: 1px; background: var(--border); opacity: 0.4; margin-left: 4px; }
.half-row { display: none; }
.half-row.expanded { display: table-row; }

.cal-legend { display: flex; gap: 12px; margin-top: 12px; font-size: 11px; color: var(--muted); flex-wrap: wrap; align-items: center; }
.legend-swatch { display: inline-block; width: 13px; height: 13px; border-radius: 3px; vertical-align: middle; margin-right: 3px; flex-shrink: 0; }

.court-info-strip {
    display: flex; gap: 14px; font-size: 13px; flex-wrap: wrap;
    padding: 11px 15px; background: var(--surface); border: 1px solid var(--border);
    border-radius: 11px; margin-top: 12px;
}

.booking-item {
    background: var(--surface2); border-radius: 10px; padding: 11px 13px;
    border-left: 3px solid var(--warn); margin-bottom: 8px;
}
.booking-item.confirmed { border-left-color: var(--success); }
.booking-item:last-child { margin-bottom: 0; }

.pay-badge {
    display: inline-block; font-size: 9px; font-weight: 700; letter-spacing: 0.06em;
    padding: 2px 7px; border-radius: 8px; background: rgba(255,255,255,0.06); margin-top: 3px;
}

/* ── BOOKING MODAL ── */
.sched-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 1000;
    background: rgba(0,0,0,0.78); backdrop-filter: blur(4px);
    align-items: flex-end; justify-content: center; padding: 0;
}
.sched-modal-overlay.open { display: flex; }
.sched-modal-box {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 22px 22px 0 0; padding: 24px 20px 32px; width: 100%; max-width: 520px;
    max-height: 85vh; overflow-y: auto; -webkit-overflow-scrolling: touch;
    padding-bottom: calc(32px + env(safe-area-inset-bottom, 16px));
}
@media (min-width: 640px) {
    .sched-modal-overlay { align-items: center; padding: 16px; }
    .sched-modal-box { border-radius: 20px; padding: 28px 24px; max-height: 90vh; }
}
@media (max-width: 639px) {
    .sched-modal-overlay { align-items: flex-end; }
    .sched-modal-box { max-height: 80dvh; }
}
.modal-drag-handle { width: 40px; height: 4px; background: var(--border); border-radius: 2px; margin: 0 auto 18px; }
@media (min-width: 640px) { .modal-drag-handle { display: none; } }

.modal-title { font-family: 'Bebas Neue', sans-serif; font-size: 24px; margin-bottom: 4px; }
.modal-sub   { font-size: 13px; color: var(--muted); margin-bottom: 18px; }

.modal-slots-list {
    display: flex; flex-direction: column; gap: 5px; max-height: 140px; overflow-y: auto;
    padding: 10px 12px; background: var(--surface2); border-radius: 10px; margin-bottom: 16px;
    -webkit-overflow-scrolling: touch;
}
.modal-slot-chip {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 13px; font-weight: 600; padding: 5px 2px; border-bottom: 1px solid var(--border);
}
.modal-slot-chip:last-child { border-bottom: none; }
.modal-slot-chip .remove-slot {
    background: none; border: none; cursor: pointer; color: var(--danger); font-size: 14px;
    padding: 2px 6px; border-radius: 4px; line-height: 1; transition: background 0.12s; touch-action: manipulation;
    font-family: inherit;
}
.modal-slot-chip .remove-slot:hover { background: rgba(239,68,68,0.12); }

.payment-section { background: var(--surface2); border-radius: 12px; padding: 14px 15px; margin-bottom: 14px; border: 1px solid var(--border); }
.payment-toggle { display: flex; gap: 0; border-radius: 9px; overflow: hidden; border: 1.5px solid var(--border); margin-bottom: 14px; }
.payment-toggle-btn {
    flex: 1; padding: 10px 6px; background: transparent; border: none; cursor: pointer;
    font-size: 13px; font-weight: 600; color: var(--muted); transition: all 0.15s;
    touch-action: manipulation; -webkit-tap-highlight-color: transparent; text-align: center;
    font-family: inherit;
}
.payment-toggle-btn.active { background: rgba(0,229,160,0.14); color: var(--accent); }
.payment-online-fields { display: none; }
.payment-online-fields.visible { display: block; }

.sched-form-group { margin-bottom: 14px; }
.sched-form-group label {
    display: block; font-size: 12px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 6px;
}
.sched-form-group input,
.sched-form-group select,
.sched-form-group textarea {
    width: 100%; background: var(--surface); border: 1.5px solid var(--border);
    border-radius: 9px; padding: 10px 13px; color: var(--text); font-size: 15px;
    font-family: inherit; transition: border-color 0.15s; -webkit-appearance: none;
}
.sched-form-group input:focus,
.sched-form-group select:focus,
.sched-form-group textarea:focus {
    border-color: var(--accent); outline: none; box-shadow: 0 0 0 2px rgba(0,229,160,0.15);
}

.upload-preview { margin-top: 8px; font-size: 12px; color: var(--accent); display: none; }
.info-bubble {
    font-size: 12px; color: var(--muted); background: rgba(0,229,160,0.05);
    border: 1px solid rgba(0,229,160,0.15); border-radius: 9px; padding: 10px 12px;
    line-height: 1.6; margin-bottom: 14px;
}
.modal-btn-row { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.modal-btn-row .btn-primary,
.modal-btn-row .btn-outline,
.modal-btn-row .btn-danger {
    flex: 1; min-width: 110px; text-align: center; touch-action: manipulation; padding: 13px 16px; font-size: 14px;
}

.deduction-notice {
    background: rgba(0,229,160,0.06); border: 1px solid rgba(0,229,160,0.25);
    border-radius: 10px; padding: 12px 14px; margin-bottom: 14px;
    font-size: 13px; line-height: 1.7;
}
.deduction-notice strong { color: var(--accent); }

@media (max-width: 600px) {
    .sched-table-wrap { scroll-snap-type: x mandatory; }
    .sched-table .col-time { width: 46px; min-width: 46px; }
    .slot-btn { min-height: 38px; border-radius: 5px; }
    .slot-btn .spots-num { font-size: 12px; }
    .slot-btn .spots-label { display: none; }
    .fill-dots { bottom: 2px; }
}
@media (min-width: 1024px) {
    .sched-table td.slot-td { height: 58px; }
    .slot-btn .spots-num { font-size: 17px; }
}
@media (max-width: 520px) {
    .sched-table .col-time { width: 50px; }
    .sched-table .time-cell { padding-right: 4px; }
    .time-cell .t-main { font-size: 10px; }
    .sched-table td.slot-td { padding: 2px; height: 46px; }
    .slot-btn { border-radius: 6px; min-height: 40px; }
    .slot-btn .spots-num { font-size: 13px; }
    .half-expander-time { width: 50px; min-width: 50px; }
}
</style>

<script nonce="<?= getCspNonce() ?>">
const COURT_PRICING = {
    openPlayCost:     <?= (float)($court['credit_cost'] ?? 0) ?>,
    resvPricePerHour: <?= (float)$resvPricePerHour ?>,
    gameDurationMins: <?= (int)$gameDuration ?>,
    partyMax:         <?= (int)PLAYERS_PER_GAME ?>,
};
</script>

<?php if ($flash): ?>
<div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warn' ? 'warn' : 'error') ?>"
     style="margin-bottom:16px;border-radius:10px;">
    <?= clean($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header flex-between">
    <div>
        <h1>Court Schedule</h1>
        <p>Browse slots &amp; book your court time</p>
    </div>
    <div class="balance-strip">
        <span class="fs-13-muted">Balance:</span>
        <strong class="text-accent">₱<?= number_format($balance, 2) ?></strong>
        <a href="<?= APP_URL ?>/player/topup.php" class="btn-outline btn-sm">+ Load Credits</a>
    </div>
</div>

<!-- Billing notice -->
<div style="background:rgba(0,184,255,0.06);border:1px solid rgba(0,184,255,0.2);border-radius:12px;
            padding:12px 18px;margin-bottom:18px;font-size:13px;color:var(--muted);display:flex;
            align-items:flex-start;gap:12px;">
    <span style="font-size:20px;flex-shrink:0;">💡</span>
    <div>
        <strong style="color:var(--text);">How billing works:</strong>
        Your balance is <strong style="color:var(--accent);">NOT deducted when you book.</strong>
        It is deducted only <strong style="color:var(--accent);">after admin reviews and confirms</strong> your reservation.
    </div>
</div>

<?php if (count($displayCourts) > 1): ?>
<div class="court-tab-row">
    <?php foreach ($displayCourts as $c):
        $isMaintenance = $c['is_maintenance'];
        $liveStatus = $c['live_status'];
        $statusIcon = match($liveStatus) {
            'available'   => '● Available',
            'active'      => '🎮 In Game',
            'queuing'     => '⏳ Queuing',
            'maintenance' => '🔧 Maint',
            'closed'      => '✗ Closed',
            'reserved'    => '📅 Reserved',
            default       => '○ Unknown',
        };
        $tabColor = $c['color'];
    ?>
        <a href="?week=<?= $weekOffset ?>&court=<?= $c['id'] ?>"
           class="court-tab <?= $c['id'] === $selectedCourt ? 'active' : '' ?>"
           style="border-color:<?= $c['id'] === $selectedCourt ? $tabColor : $tabColor.'40' ?>;
                  color:<?= $isMaintenance ? 'var(--muted)' : $tabColor ?>;
                  background:<?= $c['id'] === $selectedCourt ? $tabColor.'15' : 'transparent' ?>;
                  <?= $isMaintenance ? 'pointer-events:none;opacity:0.5;' : '' ?>"
           title="<?= $isMaintenance ? 'Under Maintenance' : '' ?>">
            <?= clean($c['short_code']) ?> <?= clean($c['name']) ?> <?= $statusIcon ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($noAvailableCourts && !$noCourts): ?>
<div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:12px;padding:14px;margin-bottom:18px;">
    <strong style="color:var(--danger);">No courts are currently available for reservation.</strong>
    <div style="color:var(--muted);margin-top:6px;">Only courts with open availability are shown in the selector. Please refresh later or contact admin for help.</div>
</div>
<?php endif; ?>

<?php if ($todayResData && $todayResData['count'] > 0): ?>
<div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);
            border-radius:12px;padding:12px 18px;margin-bottom:16px;
            display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:20px;">📅</span>
    <div>
        <div class="text-warn fs-14" style="font-weight:700;">
            <?= (int)$todayResData['count'] ?> reservation<?= $todayResData['count'] != 1 ? 's' : '' ?> today
        </div>
        <div class="fs-12-muted">
            <?= (int)$todayResData['total_players'] ?> player<?= $todayResData['total_players'] != 1 ? 's' : '' ?> booked
            on <?= clean($court['name'] ?? 'this court') ?>
        </div>
    </div>
    <a href="?week=0&court=<?= $selectedCourt ?>" class="btn-outline btn-sm" style="margin-left:auto;">View Today →</a>
</div>
<?php endif; ?>

<div class="sched-layout">

    <!-- ── LEFT: Calendar ── -->
    <div>
        <div class="card">
            <!-- Week navigation -->
            <div class="week-nav">
                <a href="?week=<?= $weekOffset - 1 ?>&court=<?= $selectedCourt ?>"
                   class="btn-outline btn-sm"
                   <?= $weekOffset <= -1 ? 'style="opacity:0.3;pointer-events:none;"' : '' ?>>← Prev</a>
                <div class="week-nav-title">
                    <span class="wnt-text"><?= $weekStart->format('M d') ?> — <?= $weekEnd->format('M d, Y') ?></span>
                    <?php if ($isThisWeek): ?><span class="fs-11 text-accent" style="display:block;">This Week</span><?php endif; ?>
                </div>
                <a href="?week=<?= $weekOffset + 1 ?>&court=<?= $selectedCourt ?>"
                   class="btn-outline btn-sm"
                   <?= $weekOffset >= 4 ? 'style="opacity:0.3;pointer-events:none;"' : '' ?>>Next →</a>
            </div>

            <!-- Selection banner -->
            <div class="selection-banner" id="sel-banner">
                <div class="selection-count" id="sel-count">0 slots selected</div>
                <div class="selection-actions">
                    <button onclick="openBookModal()" class="btn-primary btn-sm" id="sel-book-btn" disabled>📅 Book Selected</button>
                    <button onclick="clearSelection()" class="btn-outline btn-sm" style="border-color:var(--danger);color:var(--danger);">✕ Clear</button>
                </div>
            </div>

            <!-- Schedule table -->
            <div class="sched-table-wrap">
            <?php if (empty($slotGroups)): ?>
                <div style="text-align:center;padding:40px;color:var(--muted);">No bookable slots this week.</div>
            <?php else: ?>
            <table class="sched-table" role="grid" aria-label="Weekly court schedule">
                <colgroup>
                    <col class="col-time">
                    <?php for ($i=0;$i<7;$i++): ?><col><?php endfor; ?>
                </colgroup>
                <thead>
                    <tr>
                        <th class="col-time" scope="col"></th>
                        <?php
                        $dayNames = ['MON','TUE','WED','THU','FRI','SAT','SUN'];
                        foreach ($weekDays as $i => $d):
                            $isPastDay = ($d < date('Y-m-d'));
                            $isToday   = ($d === date('Y-m-d'));
                        ?>
                            <th scope="col" style="text-align:center;padding:5px 3px;">
                                <span class="day-name"><?= $dayNames[$i] ?></span>
                                <div style="display:flex;justify-content:center;">
                                    <span class="day-num <?= $isToday ? 'is-today' : ($isPastDay ? 'is-past' : '') ?>">
                                        <?= date('d', strtotime($d)) ?>
                                    </span>
                                </div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($slotGroups as $grpIdx => $group):
                    $hourSlot = $group['hour'];
                    $halfSlot = $group['half'];
                    $halfId   = $halfSlot ? str_replace(':', '-', $halfSlot['start']) : null;
                    $hts      = strtotime("2000-01-01 " . $hourSlot['start']);
                ?>
                    <!-- Hour row -->
                    <tr class="hour-row">
                        <td class="time-cell" scope="row">
                            <span class="t-main"><?= date('g:i', $hts) ?></span>
                            <span class="t-sub"><?= date('A', $hts) ?></span>
                        </td>
                        <?php foreach ($weekDays as $d):
                            $isPast    = ($d < date('Y-m-d')) || ($d === date('Y-m-d') && $hourSlot['start'] <= date('H:i'));
                            $key       = $d . '_' . $hourSlot['start'];
                            $bookings  = $resBySlot[$key] ?? [];
                            $totBooked = array_sum(array_column($bookings, 'party_size'));
                            $spotsLeft = PLAYERS_PER_GAME - $totBooked;
                            $isFull    = ($spotsLeft <= 0);
                            $myBooking = null;
                            foreach ($bookings as $b) { if ((int)$b['user_id'] === $uid) { $myBooking = $b; break; } }
                            $daySlots = buildAllSlots($d, $hoursMap, $gameDuration);
                            $hasSlot  = false; $slotEnd = $hourSlot['end'];
                            foreach ($daySlots as $ds) {
                                if ($ds['start'] === $hourSlot['start']) { $hasSlot = true; $slotEnd = $ds['end']; break; }
                            }
                            $slotKey = $d . '|' . $hourSlot['start'] . '|' . $slotEnd;
                        ?>
                            <td class="slot-td">
                            <?php if (!$hasSlot): ?>
                                <button class="slot-btn no-slot" disabled aria-hidden="true"></button>
                            <?php elseif ($myBooking): ?>
                                <?php $cls = $myBooking['status']==='confirmed' ? 'mine-confirmed' : 'mine-pending'; ?>
                                <button class="slot-btn <?= $cls ?>"
                                        onclick="showCancelModal(<?= $myBooking['id'] ?>, '<?= $d ?>', '<?= $hourSlot['start'] ?>')"
                                        aria-label="Your <?= $myBooking['status'] ?> booking.">
                                    <span class="slot-icon"><?= $myBooking['status']==='confirmed' ? '✅' : '⏳' ?></span>
                                    <span class="spots-label"><?= $myBooking['status']==='confirmed' ? 'Confirmed' : 'Pending' ?></span>
                                </button>
                            <?php elseif ($isPast): ?>
                                <button class="slot-btn past-slot" disabled aria-label="Past slot"></button>
                            <?php elseif ($isFull): ?>
                                <?php $slotModeFull = getSlotMode($slotModes, $hourSlot['start']); ?>
                                <button class="slot-btn full" disabled aria-label="Full">
                                    <span class="slot-icon" style="font-size:12px;"><?= $slotModeFull === 'open_play' ? '🎮' : '📅' ?></span>
                                    <span class="spots-label"><?= $slotModeFull === 'open_play' ? 'FULL' : 'RESERVED' ?></span>
                                </button>
                            <?php else: ?>
                                <?php $slotMode = getSlotMode($slotModes, $hourSlot['start']); ?>
                                <button class="slot-btn avail <?= $slotMode === 'open_play' ? 'open-play-mode' : 'reservation-mode' ?>"
                                        id="slotbtn-<?= md5($slotKey) ?>"
                                        onclick="toggleSlot(this,'<?= $slotKey ?>','<?= $d ?>','<?= $hourSlot['start'] ?>','<?= $slotEnd ?>',<?= $spotsLeft ?>,'<?= $slotMode ?>')"
                                        aria-label="<?= $spotsLeft ?> spot<?= $spotsLeft!==1?'s':'' ?> available">
                                    <span class="spots-num">+<?= $spotsLeft ?></span>
                                    <span class="spots-label">spot<?= $spotsLeft!==1?'s':'' ?></span>
                                    <span class="mode-pill-mini"><?= $slotMode === 'open_play' ? '🎮' : '📅' ?></span>
                                    <?php if ($totBooked > 0): ?>
                                    <span class="fill-dots">
                                        <?php for($fi=0;$fi<PLAYERS_PER_GAME;$fi++): ?>
                                            <span class="fill-dot <?= $fi < $totBooked ? 'filled' : '' ?>"></span>
                                        <?php endfor; ?>
                                    </span>
                                    <?php endif; ?>
                                </button>
                            <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <?php if ($halfSlot): ?>
                    <tr class="half-expander-row">
                        <td colspan="8">
                            <div class="half-expander-inner">
                                <div class="half-expander-time">
                                    <button class="half-toggle-btn" id="half-btn-<?= $halfId ?>"
                                            onclick="toggleHalf('<?= $halfId ?>', this)"
                                            aria-expanded="false">
                                        <span class="plus-icon">+</span>
                                        <span><?= date('g:i', strtotime('2000-01-01 '.$halfSlot['start'])) ?></span>
                                    </button>
                                </div>
                                <div class="half-expander-line"></div>
                            </div>
                        </td>
                    </tr>
                    <tr class="half-row" id="half-row-<?= $halfId ?>">
                        <td class="time-cell half-time" scope="row">
                            <?php $hts2 = strtotime("2000-01-01 " . $halfSlot['start']); ?>
                            <span class="t-main"><?= date('g:i', $hts2) ?></span>
                            <span class="t-sub"><?= date('A', $hts2) ?></span>
                        </td>
                        <?php foreach ($weekDays as $d):
                            $isPast2    = ($d < date('Y-m-d')) || ($d === date('Y-m-d') && $halfSlot['start'] <= date('H:i'));
                            $key2       = $d . '_' . $halfSlot['start'];
                            $bookings2  = $resBySlot[$key2] ?? [];
                            $totBooked2 = array_sum(array_column($bookings2, 'party_size'));
                            $spotsLeft2 = PLAYERS_PER_GAME - $totBooked2;
                            $isFull2    = ($spotsLeft2 <= 0);
                            $mode2      = getSlotMode($slotModes, $halfSlot['start']);
                            $myBook2    = null;
                            foreach ($bookings2 as $b) { if ((int)$b['user_id'] === $uid) { $myBook2 = $b; break; } }
                            $daySlots2 = buildAllSlots($d, $hoursMap, $gameDuration);
                            $hasSlot2  = false; $slotEnd2 = $halfSlot['end'];
                            foreach ($daySlots2 as $ds) {
                                if ($ds['start'] === $halfSlot['start']) { $hasSlot2 = true; $slotEnd2 = $ds['end']; break; }
                            }
                            $slotKey2 = $d . '|' . $halfSlot['start'] . '|' . $slotEnd2;
                        ?>
                            <td class="slot-td">
                            <?php if (!$hasSlot2): ?>
                                <button class="slot-btn no-slot" disabled aria-hidden="true"></button>
                            <?php elseif ($myBook2): ?>
                                <?php $cls2 = $myBook2['status']==='confirmed' ? 'mine-confirmed' : 'mine-pending'; ?>
                                <button class="slot-btn <?= $cls2 ?>"
                                        onclick="showCancelModal(<?= $myBook2['id'] ?>, '<?= $d ?>', '<?= $halfSlot['start'] ?>')">
                                    <span class="slot-icon"><?= $myBook2['status']==='confirmed' ? '✅' : '⏳' ?></span>
                                    <span class="spots-label"><?= $myBook2['status']==='confirmed' ? 'OK' : 'Wait' ?></span>
                                </button>
                            <?php elseif ($isPast2): ?>
                                <button class="slot-btn past-slot" disabled></button>
                            <?php elseif ($isFull2): ?>
                                <button class="slot-btn full" disabled>
                                    <span class="spots-label"><?= $mode2 === 'open_play' ? 'Open Play' : 'Reserved' ?></span>
                                </button>
                            <?php else: ?>
                                <button class="slot-btn avail <?= $mode2 === 'open_play' ? 'open-play-mode' : 'reservation-mode' ?>"
                                        id="slotbtn-<?= md5($slotKey2) ?>"
                                        onclick="toggleSlot(this,'<?= $slotKey2 ?>','<?= $d ?>','<?= $halfSlot['start'] ?>','<?= $slotEnd2 ?>',<?= $spotsLeft2 ?>,'<?= $mode2 ?>')">
                                    <span class="spots-num">+<?= $spotsLeft2 ?></span>
                                    <span class="spots-label">spot<?= $spotsLeft2!==1?'s':'' ?></span>
                                    <span class="mode-pill-mini"><?= $mode2 === 'open_play' ? '🎮' : '📅' ?></span>
                                </button>
                            <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endif; ?>

                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="cal-legend">
                <span><span class="legend-swatch" style="background:rgba(0,229,160,0.06);border:1.5px dashed rgba(0,229,160,0.4);"></span>Available</span>
                <span><span class="legend-swatch" style="background:rgba(0,229,160,0.28);border:2px solid var(--accent);"></span>Selected</span>
                <span><span class="legend-swatch" style="background:rgba(0,229,160,0.22);border:2px solid var(--success);"></span>My booking ✅</span>
                <span><span class="legend-swatch" style="background:rgba(245,158,11,0.18);border:2px solid var(--warn);"></span>My booking ⏳</span>
                <span><span class="legend-swatch" style="background:rgba(239,68,68,0.07);border:1.5px solid rgba(239,68,68,0.25);"></span>Full</span>
                <span><span class="legend-swatch" style="background:rgba(0,184,255,0.10);border:1.5px solid rgba(0,184,255,0.35);"></span>Open Play 🎮</span>
                <span><span class="legend-swatch" style="background:rgba(245,158,11,0.10);border:1.5px solid rgba(245,158,11,0.35);"></span>Reservation 📅</span>
                <span style="font-family:monospace;font-size:10px;color:var(--muted);">Tap cells to select · <strong style="color:var(--accent);">+</strong> for :30 slots</span>
            </div>
            <?php endif; ?>
            </div>
        </div>

        <?php if ($court): ?>
        <div class="court-info-strip">
            <span>🏓 <strong><?= clean($court['name']) ?></strong></span>
            <span class="text-muted">⏱ <?= $court['game_duration'] ?>-min games</span>
            <span class="text-muted">💰 ₱<?= number_format($court['credit_cost'], 0) ?>/game</span>
            <span class="text-muted">👥 Up to <?= $court['max_queue'] ?> players/slot</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── RIGHT: Sidebar ── -->
    <div style="display:flex;flex-direction:column;gap:18px;">

        <!-- How to Book -->
        <div style="background:rgba(0,229,160,0.07);border:1.5px solid rgba(0,229,160,0.2);border-radius:14px;padding:16px 18px;">
            <div style="font-weight:700;font-size:14px;margin-bottom:6px;">📌 How to Book</div>
            <div class="fs-13-muted" style="line-height:1.8;">
                <div>1️⃣ <strong class="text-text">Tap slots</strong> to select one or more</div>
                <div>2️⃣ <strong class="text-text">Book Selected</strong> → fill the form</div>
                <div>3️⃣ Upload proof if paying online</div>
                <div>4️⃣ <strong class="text-text">Admin reviews &amp; confirms</strong> — wallet deducted then</div>
            </div>
            <div class="fs-11-muted" style="margin-top:10px;padding:8px 10px;background:var(--surface2);border-radius:7px;">
                💡 Your balance is <strong style="color:var(--accent);">only deducted at confirmation</strong>, not at booking time.
            </div>
        </div>

        <!-- Reservation Pricing -->
        <?php if ($resvEnabled): ?>
        <div style="background:rgba(0,184,255,0.06);border:1.5px solid rgba(0,184,255,0.2);border-radius:14px;padding:16px 18px;">
            <div style="font-weight:700;font-size:14px;margin-bottom:8px;color:var(--accent2);">📅 Reservation Rates</div>
            <div class="fs-13-muted" style="line-height:1.9;">
                <div>💰 <strong class="text-text">₱<?= number_format($resvPricePerHour, 0) ?></strong> per hour</div>
                <div>⏱ Min: <strong class="text-text"><?= $resvMinHours ?>h</strong> · Max: <strong class="text-text"><?= $resvMaxHours ?>h</strong></div>
                <?php if ($resvDepositPct > 0): ?>
                <div>💳 <strong class="text-text"><?= $resvDepositPct ?>%</strong> deposit required</div>
                <?php else: ?>
                <div>💳 Full payment on confirmation</div>
                <?php endif; ?>
                <div>📆 Book up to <strong class="text-text"><?= $resvAdvanceDays ?> days</strong> ahead</div>
            </div>
            <div class="fs-11-muted" style="margin-top:10px;padding:8px 10px;background:var(--surface2);border-radius:7px;">
                ⚠️ Reservations under <strong class="text-danger"><?= $resvMinHours ?>h</strong> are auto-denied.
            </div>
            <?php $minCost = $resvPricePerHour * $resvMinHours; $maxCost = $resvPricePerHour * $resvMaxHours; ?>
            <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:8px;text-align:center;">
                <div style="background:var(--surface2);border-radius:8px;padding:10px;">
                    <div class="text-accent2" style="font-weight:700;font-size:16px;">₱<?= number_format($minCost, 0) ?></div>
                    <div class="fs-10-muted" style="margin-top:2px;">Min (<?= $resvMinHours ?>h)</div>
                </div>
                <div style="background:var(--surface2);border-radius:8px;padding:10px;">
                    <div class="text-accent2" style="font-weight:700;font-size:16px;">₱<?= number_format($maxCost, 0) ?></div>
                    <div class="fs-10-muted" style="margin-top:2px;">Max (<?= $resvMaxHours ?>h)</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- My Upcoming Bookings -->
        <div class="card">
            <div class="card-title">📅 My Upcoming Bookings</div>
            <hr class="divider"/>
            <?php if (empty($myUpcoming)): ?>
                <div style="text-align:center;padding:22px 0;">
                    <div style="font-size:34px;margin-bottom:8px;">📭</div>
                    <p class="fs-13-muted">No upcoming reservations.<br>Tap any cell to book!</p>
                </div>
            <?php else: ?>
                <?php foreach ($myUpcoming as $r):
                    $sb = $statusBadge[$r['status']] ?? $statusBadge['pending'];
                    $pb = $payBadge[$r['payment_status'] ?? 'unpaid'] ?? $payBadge['unpaid'];
                ?>
                <div class="booking-item <?= $r['status']==='confirmed' ? 'confirmed' : '' ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                        <div>
                            <div style="font-weight:700;font-size:13px;"><?= date('M d, Y', strtotime($r['slot_date'])) ?></div>
                            <div class="fs-12-muted"><?= date('g:i A', strtotime($r['slot_time'])) ?> – <?= date('g:i A', strtotime($r['slot_end'])) ?></div>
                            <div class="fs-11-muted" style="margin-top:2px;"><?= clean($r['court_name']) ?> · <?= $r['party_size'] ?> player<?= $r['party_size']>1?'s':'' ?></div>
                            <span class="pay-badge" style="color:<?= $pb['color'] ?>;"><?= $pb['label'] ?></span>
                        </div>
                        <span class="badge badge-<?= $sb['badge'] ?>" style="font-size:10px;white-space:nowrap;"><?= $sb['icon'] ?> <?= $sb['label'] ?></span>
                    </div>
                    <?php if (!empty($r['admin_note'])): ?>
                        <div class="fs-11-muted" style="background:var(--surface);border-radius:6px;padding:5px 9px;margin-top:6px;">
                            Admin: <?= clean($r['admin_note']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($r['status'] === 'pending'): ?>
                    <div style="margin-top:8px;">
                        <button onclick="showCancelModal(<?= $r['id'] ?>, '<?= $r['slot_date'] ?>', '<?= substr($r['slot_time'],0,5) ?>')"
                                class="btn-outline btn-sm" style="font-size:11px;">✕ Cancel</button>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:6px;font-size:11px;color:var(--muted);">Contact admin to cancel confirmed bookings.</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Past bookings -->
        <?php if (!empty($myPast)): ?>
        <div class="card">
            <div class="card-title fs-13-muted">📋 Past Bookings</div>
            <hr class="divider"/>
            <?php foreach ($myPast as $r): $sb = $statusBadge[$r['status']] ?? $statusBadge['pending']; ?>
                <div style="padding:7px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:8px;">
                    <div>
                        <div style="font-size:12px;font-weight:600;"><?= date('M d', strtotime($r['slot_date'])) ?> · <?= date('g:i A', strtotime($r['slot_time'])) ?></div>
                        <div class="fs-11-muted"><?= clean($r['court_name']) ?></div>
                    </div>
                    <span class="badge badge-<?= $sb['badge'] ?>" style="font-size:10px;white-space:nowrap;"><?= $sb['icon'] ?> <?= $sb['label'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     BOOK MODAL
══════════════════════════════════════════════════════════ -->
<div id="book-modal" class="sched-modal-overlay" role="dialog" aria-modal="true" aria-label="Book slots">
    <div class="sched-modal-box">
        <div class="modal-drag-handle"></div>
        <div class="modal-title">📅 Book Your Slots</div>
        <div class="modal-sub" id="modal-court-name">Court &amp; slots summary</div>

        <form method="POST" enctype="multipart/form-data" id="book-form">
            <input type="hidden" name="action"         value="book_multi">
            <input type="hidden" name="court_id"       value="<?= $selectedCourt ?>">
            <input type="hidden" name="selected_slots" id="f-slots-json">

            <!-- Selected slots list -->
            <div class="modal-slots-list" id="modal-slots-list"></div>

            <!-- Cost breakdown -->
            <div id="modal-cost-breakdown" class="deduction-notice"></div>

            <!-- Party size -->
            <div class="sched-form-group">
                <label>Players in Your Group</label>
                <select name="party_size" id="f-party">
                    <option value="1">Just me (1)</option>
                    <option value="2">2 players</option>
                    <option value="3">3 players</option>
                    <option value="4" selected>Full group (4)</option>
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:4px;" id="modal-spots-info"></div>
            </div>

            <!-- Payment method -->
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;color:var(--muted);
                              text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">
                    Payment Method
                </label>
                <div class="payment-toggle">
                    <button type="button" class="payment-toggle-btn active" id="pay-btn-inperson" onclick="setPayMethod('in_person')">
                        🏢 Pay in Person
                    </button>
                    <button type="button" class="payment-toggle-btn" id="pay-btn-online" onclick="setPayMethod('online')">
                        💳 Upload Proof
                    </button>
                </div>
                <input type="hidden" name="payment_method" id="f-pay-method" value="in_person">

                <div id="inperson-info" class="info-bubble">
                    💡 You don't pay now. Your wallet will be deducted automatically when admin <strong>confirms</strong> your reservation.
                </div>

                <!-- Online payment fields -->
                <div class="payment-online-fields" id="online-fields">
                    <div class="payment-section">
                        <div style="font-size:13px;font-weight:700;margin-bottom:10px;color:var(--accent);">
                            💳 Send Payment To
                        </div>
<?php if (empty($paymentMethods)): ?>
<div class="info-bubble" style="margin-bottom:10px;">No online payment methods configured. Please pay in person.</div>
<?php elseif (count($paymentMethods) === 1):
    $pm = $paymentMethods[0];
    $pmQrSrc = null;
    if (!empty($pm['qr_image'])) {
        if (str_starts_with($pm['qr_image'], 'data:image/')) { $pmQrSrc = $pm['qr_image']; }
        elseif (file_exists(APP_ROOT . '/uploads/payment/' . $pm['qr_image'])) { $pmQrSrc = APP_URL . '/uploads/payment/' . urlencode($pm['qr_image']); }
    }
?>
<div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px;">
    <div style="display:flex;gap:14px;align-items:flex-start;">
        <?php if ($pmQrSrc): ?>
        <div style="flex-shrink:0;">
            <img src="<?= htmlspecialchars($pmQrSrc) ?>" id="modal-qr-img-0" alt="QR"
                 style="width:90px;height:90px;object-fit:contain;border-radius:10px;border:1.5px solid var(--border);background:#fff;cursor:zoom-in;display:block;"
                 onclick="openModalQR(0)"/>
            <button type="button" onclick="openModalQR(0)"
                    style="margin-top:5px;width:90px;background:rgba(0,229,160,0.1);border:1px solid rgba(0,229,160,0.3);
                           color:var(--accent);border-radius:6px;padding:3px 0;font-size:10px;font-weight:700;cursor:pointer;display:block;font-family:inherit;">
                🔍 Enlarge
            </button>
        </div>
        <?php else: ?>
        <div style="width:90px;height:90px;border-radius:10px;border:1.5px dashed var(--border);display:flex;align-items:center;justify-content:center;font-size:32px;flex-shrink:0;">💳</div>
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:15px;color:var(--accent);margin-bottom:3px;"><?= clean($pm['name']) ?></div>
            <?php if (!empty($pm['account_name'])): ?><div style="font-size:12px;color:var(--muted);margin-bottom:4px;"><?= clean($pm['account_name']) ?></div><?php endif; ?>
            <?php if (!empty($pm['account_no'])): ?>
            <div style="font-family:monospace;font-size:16px;font-weight:700;color:var(--text);">
                <?= clean($pm['account_no']) ?>
                <button type="button" onclick="copyAcct(this,'<?= addslashes(clean($pm['account_no'])) ?>')"
                        style="background:none;border:none;cursor:pointer;padding:0 4px;font-size:13px;color:var(--muted);">📋</button>
            </div>
            <?php endif; ?>
            <?php if (!empty($pm['instructions'])): ?><div style="font-size:11px;color:var(--muted);line-height:1.6;margin-top:6px;"><?= nl2br(clean($pm['instructions'])) ?></div><?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
    <?php foreach ($paymentMethods as $pi => $pm): ?>
    <button type="button" class="modal-pay-tab <?= $pi === 0 ? 'modal-pay-tab-active' : '' ?>"
            onclick="switchModalPayTab(<?= $pi ?>)"
            style="padding:7px 14px;border-radius:18px;border:1.5px solid var(--border);
                   background:<?= $pi === 0 ? 'rgba(0,229,160,0.12)' : 'transparent' ?>;
                   color:<?= $pi === 0 ? 'var(--accent)' : 'var(--muted)' ?>;
                   font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;touch-action:manipulation;font-family:inherit;">
        <?= clean($pm['name']) ?>
    </button>
    <?php endforeach; ?>
</div>
<?php foreach ($paymentMethods as $pi => $pm):
    $pmQrSrc = null;
    if (!empty($pm['qr_image'])) {
        if (str_starts_with($pm['qr_image'], 'data:image/')) { $pmQrSrc = $pm['qr_image']; }
        elseif (file_exists(APP_ROOT . '/uploads/payment/' . $pm['qr_image'])) { $pmQrSrc = APP_URL . '/uploads/payment/' . urlencode($pm['qr_image']); }
    }
?>
<div class="modal-pay-panel" id="modal-pay-panel-<?= $pi ?>" style="display:<?= $pi === 0 ? 'block' : 'none' ?>;">
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px;">
        <div style="display:flex;gap:14px;align-items:flex-start;">
            <?php if ($pmQrSrc): ?>
            <div style="flex-shrink:0;">
                <img src="<?= htmlspecialchars($pmQrSrc) ?>" id="modal-qr-img-<?= $pi ?>" alt="QR"
                     style="width:90px;height:90px;object-fit:contain;border-radius:10px;border:1.5px solid var(--border);background:#fff;cursor:zoom-in;display:block;"
                     onclick="openModalQR(<?= $pi ?>)"/>
                <button type="button" onclick="openModalQR(<?= $pi ?>)"
                        style="margin-top:5px;width:90px;background:rgba(0,229,160,0.1);border:1px solid rgba(0,229,160,0.3);
                               color:var(--accent);border-radius:6px;padding:3px 0;font-size:10px;font-weight:700;cursor:pointer;display:block;font-family:inherit;">
                    🔍 Enlarge
                </button>
            </div>
            <?php else: ?>
            <div style="width:90px;height:90px;border-radius:10px;border:1.5px dashed var(--border);display:flex;align-items:center;justify-content:center;font-size:32px;flex-shrink:0;">💳</div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:15px;color:var(--accent);margin-bottom:3px;"><?= clean($pm['name']) ?></div>
                <?php if (!empty($pm['account_name'])): ?><div style="font-size:12px;color:var(--muted);margin-bottom:4px;"><?= clean($pm['account_name']) ?></div><?php endif; ?>
                <?php if (!empty($pm['account_no'])): ?>
                <div style="font-family:monospace;font-size:16px;font-weight:700;color:var(--text);">
                    <?= clean($pm['account_no']) ?>
                    <button type="button" onclick="copyAcct(this,'<?= addslashes(clean($pm['account_no'])) ?>')"
                            style="background:none;border:none;cursor:pointer;padding:0 4px;font-size:13px;color:var(--muted);">📋</button>
                </div>
                <?php endif; ?>
                <?php if (!empty($pm['instructions'])): ?><div style="font-size:11px;color:var(--muted);line-height:1.6;margin-top:6px;"><?= nl2br(clean($pm['instructions'])) ?></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

                        <div class="info-bubble" style="margin-bottom:10px;">
                            Scan the QR or send to the number above, then upload your screenshot and enter the
                            reference number below. Admin will review and confirm your slot.
                        </div>
                        <div class="sched-form-group">
                            <label>Reference Number <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="payment_ref" id="f-pay-ref"
                                   placeholder="e.g. GCash ref 0001234567890" maxlength="100">
                        </div>
                        <div class="sched-form-group">
                            <label>Payment Screenshot <span style="color:var(--danger);">*</span></label>
                            <input type="file" name="payment_proof" id="f-pay-proof"
                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                   style="font-size:14px;padding:8px 10px;"
                                   onchange="previewProof(this)">
                            <div class="upload-preview" id="proof-preview">📎 File ready to upload</div>
                            <div style="font-size:11px;color:var(--muted);margin-top:4px;">JPG / PNG / WEBP · Max 25MB</div>
                        </div>
                        <div class="sched-form-group">
                            <label>Amount Paid (₱)</label>
                            <input type="number" name="payment_amount" id="f-pay-amount"
                                   placeholder="0.00" step="0.01" min="0">
                        </div>
                    </div>
                </div>

                <!-- QR Lightbox -->
                <div id="pay-qr-lightbox"
                     style="display:none;position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,0.93);
                            align-items:center;justify-content:center;padding:20px;cursor:zoom-out;"
                     onclick="closePayQR()">
                    <img id="pay-qr-lb-img" src="" alt="QR Code"
                         style="max-width:min(420px,90vw);max-height:88vh;border-radius:16px;object-fit:contain;background:#fff;padding:12px;">
                </div>
            </div>

            <!-- Note -->
            <div class="sched-form-group">
                <label>Note <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
                <textarea name="note" rows="2" maxlength="300"
                          placeholder="e.g. Bringing own paddles…" style="resize:none;"></textarea>
            </div>

            <div class="modal-btn-row">
                <button type="submit" class="btn-primary" id="modal-submit-btn">📅 Submit Reservation</button>
                <button type="button" onclick="closeBookModal()" class="btn-outline">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- CANCEL MODAL -->
<div id="cancel-modal" class="sched-modal-overlay" role="dialog" aria-modal="true" aria-label="Cancel reservation">
    <div class="sched-modal-box">
        <div class="modal-drag-handle"></div>
        <div class="modal-title">❌ Cancel Reservation</div>
        <div id="cancel-slot-info" style="font-size:14px;color:var(--muted);margin-bottom:14px;"></div>
        <p style="font-size:14px;margin-bottom:16px;line-height:1.6;">
            Are you sure? Since you haven't been charged yet, cancelling is free.
            Only pending (not-yet-confirmed) bookings can be self-cancelled.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="reservation_id" id="cancel-rid">
            <div class="modal-btn-row">
                <button type="submit" class="btn-danger">Yes, Cancel</button>
                <button type="button" onclick="closeCancelModal()" class="btn-outline">Keep It</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= getCspNonce() ?>">
// ═══════════════════════════════════════════════════════════
//  SCHEDULE PAGE JAVASCRIPT — Fixed v3
//  Bugs fixed:
//    1. Removed debug alert() that blocked all slot clicks
//    2. Fixed toggleSlot() — correct deselect/restore logic
//    3. Fixed openBookModal() — proper key iteration
//    4. Fixed removeSlotFromModal() — in-place re-render, no recursion
//    5. Fixed renderCostBreakdown() — null guard + correct cost model
//    6. Party change listener attached directly (no DOMContentLoaded needed)
// ═══════════════════════════════════════════════════════════

// Keyed map: slotKey → { date, time, end, spotsLeft, mode, el }
let selectedSlots = {};

// ── Payment method tabs (multi-provider) ─────────────────────
function switchModalPayTab(idx) {
    document.querySelectorAll('.modal-pay-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.modal-pay-tab').forEach(t => {
        t.style.background  = 'transparent';
        t.style.color       = 'var(--muted)';
        t.style.borderColor = 'var(--border)';
    });
    const panel = document.getElementById('modal-pay-panel-' + idx);
    if (panel) panel.style.display = 'block';
    const tabs = document.querySelectorAll('.modal-pay-tab');
    if (tabs[idx]) {
        tabs[idx].style.background  = 'rgba(0,229,160,0.12)';
        tabs[idx].style.color       = 'var(--accent)';
        tabs[idx].style.borderColor = 'var(--accent)';
    }
}

// ── QR lightbox ───────────────────────────────────────────────
function openModalQR(idx) {
    const img = document.getElementById('modal-qr-img-' + idx);
    if (!img) return;
    document.getElementById('pay-qr-lb-img').src = img.src;
    document.getElementById('pay-qr-lightbox').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closePayQR() {
    const lb = document.getElementById('pay-qr-lightbox');
    if (lb) lb.style.display = 'none';
    document.body.style.overflow = '';
}

// ── Slot toggle ───────────────────────────────────────────────
function toggleSlot(el, key, date, time, end, spotsLeft, mode) {
    if (selectedSlots[key]) {
        // Deselect — restore original appearance
        delete selectedSlots[key];
        el.classList.remove('selected');
        el.innerHTML = buildSlotInner(spotsLeft);
    } else {
        // Select
        selectedSlots[key] = { date, time, end, spotsLeft, mode, el };
        el.classList.add('selected');
        el.innerHTML = '<span class="chk-icon">✓</span><span class="spots-label">Selected</span>';
    }
    updateBanner();
}

function buildSlotInner(spots) {
    return `<span class="spots-num">+${spots}</span><span class="spots-label">spot${spots !== 1 ? 's' : ''}</span>`;
}

function updateBanner() {
    const cnt = Object.keys(selectedSlots).length;
    document.getElementById('sel-banner').classList.toggle('visible', cnt > 0);
    document.getElementById('sel-count').textContent = cnt + ' slot' + (cnt !== 1 ? 's' : '') + ' selected';
    document.getElementById('sel-book-btn').disabled = cnt === 0;
}

function clearSelection() {
    Object.values(selectedSlots).forEach(v => {
        v.el.classList.remove('selected');
        v.el.innerHTML = buildSlotInner(v.spotsLeft);
    });
    selectedSlots = {};
    updateBanner();
}

// ── Half-row toggle ───────────────────────────────────────────
function toggleHalf(id, btn) {
    const row = document.getElementById('half-row-' + id);
    if (!row) return;
    const expanded = row.classList.toggle('expanded');
    if (btn) {
        btn.classList.toggle('expanded', expanded);
        btn.setAttribute('aria-expanded', expanded);
    }
}

// ── Payment method switcher ───────────────────────────────────
function setPayMethod(method) {
    document.getElementById('f-pay-method').value = method;
    document.getElementById('pay-btn-inperson').classList.toggle('active', method === 'in_person');
    document.getElementById('pay-btn-online').classList.toggle('active', method === 'online');
    document.getElementById('inperson-info').style.display = method === 'in_person' ? 'block' : 'none';
    document.getElementById('online-fields').classList.toggle('visible', method === 'online');
    const ref   = document.getElementById('f-pay-ref');
    const proof = document.getElementById('f-pay-proof');
    if (ref)   ref.required   = (method === 'online');
    if (proof) proof.required = (method === 'online');
}

function previewProof(input) {
    const preview = document.getElementById('proof-preview');
    if (input.files && input.files[0]) {
        preview.style.display = 'block';
        preview.textContent = '📎 ' + input.files[0].name + ' (' + (input.files[0].size / 1024).toFixed(1) + ' KB)';
    }
}

// ── Cost breakdown renderer ───────────────────────────────────
function renderCostBreakdown() {
    const partyEl = document.getElementById('f-party');
    if (!partyEl) return;
    const party = parseInt(partyEl.value) || 1;
    const keys  = Object.keys(selectedSlots);
    if (keys.length === 0) return;

    let rows = '';
    let grandTotal = 0;

    keys.forEach(k => {
        const v      = selectedSlots[k];
        const isOpen = v.mode === 'open_play';
        // Reservation: 1 slot = 1 hour flat rate (no party/duration multiply)
        // Open play:   charged per person
        const unitCost = isOpen
            ? COURT_PRICING.openPlayCost * party
            : COURT_PRICING.resvPricePerHour;
        grandTotal += unitCost;

        const d    = new Date(v.date + 'T00:00:00');
        const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        const priceLine = isOpen
            ? `₱${COURT_PRICING.openPlayCost.toFixed(0)} × ${party} player${party !== 1 ? 's' : ''} = <strong style="color:var(--accent);">₱${unitCost.toFixed(2)}</strong>`
            : `₱${unitCost.toFixed(0)}/hr = <strong style="color:var(--accent);">₱${unitCost.toFixed(2)}</strong>`;

        rows += `<div style="display:flex;justify-content:space-between;gap:8px;
                              border-bottom:1px solid rgba(255,255,255,0.05);padding-bottom:4px;margin-bottom:4px;">
            <span style="color:var(--muted);">
                ${days[d.getDay()]} ${v.date}&nbsp;${fmtT(v.time)}
                <span style="font-size:10px;margin-left:4px;color:${isOpen ? 'var(--accent2)' : 'var(--warn)'};">
                    ${isOpen ? '🎮 Open Play' : '📅 Reservation'}
                </span>
            </span>
            <span style="font-weight:600;white-space:nowrap;">${priceLine}</span>
        </div>`;
    });

    rows += `<div style="display:flex;justify-content:space-between;margin-top:6px;font-size:15px;">
        <strong>Total (deducted at confirmation)</strong>
        <strong style="color:var(--accent);font-size:17px;">₱${grandTotal.toFixed(2)}</strong>
    </div>
    <div style="margin-top:6px;font-size:11px;color:var(--muted);">
        ℹ️ Deducted from your wallet only when admin confirms.
    </div>`;

    const el = document.getElementById('modal-cost-breakdown');
    if (el) el.innerHTML = rows;
}

// Party size change → re-render cost
(function () {
    const sel = document.getElementById('f-party');
    if (sel) sel.addEventListener('change', renderCostBreakdown);
})();

// ── Open booking modal ────────────────────────────────────────
function openBookModal() {
    const keys = Object.keys(selectedSlots);
    if (keys.length === 0) return;

    // Populate hidden JSON field
    document.getElementById('f-slots-json').value = JSON.stringify(
        keys.map(k => ({
            date: selectedSlots[k].date,
            time: selectedSlots[k].time,
            end:  selectedSlots[k].end,
            mode: selectedSlots[k].mode,
        }))
    );

    // Render slot chips list
    _renderModalSlotList(keys);

    // Party size options — disable those beyond available spots
    const minSpots = Math.min(...keys.map(k => selectedSlots[k].spotsLeft));
    const sel = document.getElementById('f-party');
    for (let i = 0; i < sel.options.length; i++) {
        sel.options[i].disabled = parseInt(sel.options[i].value) > minSpots;
    }
    // Auto-select highest enabled option
    for (let i = sel.options.length - 1; i >= 0; i--) {
        if (!sel.options[i].disabled) { sel.options[i].selected = true; break; }
    }

    document.getElementById('modal-spots-info').textContent =
        'Max available across all selected slots: ' + minSpots;
    document.getElementById('modal-court-name').textContent =
        keys.length + ' slot' + (keys.length !== 1 ? 's' : '') + ' selected';

    setPayMethod('in_person');
    document.getElementById('proof-preview').style.display = 'none';
    renderCostBreakdown();

    document.getElementById('book-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

// ── Re-render just the slots list inside the open modal ───────
function _renderModalSlotList(keys) {
    const listEl = document.getElementById('modal-slots-list');
    listEl.innerHTML = '';
    keys.forEach(key => {
        const v    = selectedSlots[key];
        const d    = new Date(v.date + 'T00:00:00');
        const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        const chip = document.createElement('div');
        chip.className = 'modal-slot-chip';
        chip.innerHTML =
            `<span>${days[d.getDay()]}, ${v.date}&nbsp;&nbsp;${fmtT(v.time)} – ${fmtT(v.end)}</span>
             <button type="button" class="remove-slot" onclick="removeSlotFromModal('${key.replace(/'/g,"\\'")}')">✕</button>`;
        listEl.appendChild(chip);
    });
}

// ── Remove one slot from the open modal (no recursion) ────────
function removeSlotFromModal(key) {
    const v = selectedSlots[key];
    if (v) {
        v.el.classList.remove('selected');
        v.el.innerHTML = buildSlotInner(v.spotsLeft);
        delete selectedSlots[key];
    }
    updateBanner();

    const remaining = Object.keys(selectedSlots);
    if (remaining.length === 0) {
        closeBookModal();
        return;
    }

    // Re-render in place — no close/reopen to avoid resetting form state
    _renderModalSlotList(remaining);
    document.getElementById('modal-court-name').textContent =
        remaining.length + ' slot' + (remaining.length !== 1 ? 's' : '') + ' selected';
    renderCostBreakdown();
}

function closeBookModal() {
    document.getElementById('book-modal').classList.remove('open');
    document.body.style.overflow = '';
}

// ── Cancel modal ──────────────────────────────────────────────
function showCancelModal(rid, date, time) {
    document.getElementById('cancel-rid').value = rid;
    const d    = new Date(date + 'T00:00:00');
    const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    document.getElementById('cancel-slot-info').textContent =
        days[d.getDay()] + ', ' + date + ' at ' + fmtT(time);
    document.getElementById('cancel-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCancelModal() {
    document.getElementById('cancel-modal').classList.remove('open');
    document.body.style.overflow = '';
}

// ── Time formatter ────────────────────────────────────────────
function fmtT(t) {
    if (!t) return '';
    const [h, m] = t.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    return (h % 12 || 12) + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}

// ── Close modals on backdrop click / Escape ───────────────────
['book-modal', 'cancel-modal'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function (e) {
        if (e.target === this) {
            this.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ['book-modal', 'cancel-modal'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.remove('open');
        });
        closePayQR();
        document.body.style.overflow = '';
    }
});

// ── Account number copy ───────────────────────────────────────
function copyAcct(btn, text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    const orig = btn.textContent;
    btn.textContent = '✅';
    setTimeout(() => btn.textContent = orig, 1500);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>