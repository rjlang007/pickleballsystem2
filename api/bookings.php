<?php
// ============================================================
//  FILE: api/bookings.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/booking_state_machine.php';
require_once __DIR__ . '/../includes/helpers.php';   // getAdminUserIds, notifyAdmins, notifyUser

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$rlKey = 'api_bookings_' . getClientIp();
if (!checkRateLimit($rlKey, 30, 60)) {
    header('Retry-After: 60');
    apiError('RATE_LIMITED', 'Too many requests. Please wait.', [], 429);
}

if (!isLoggedIn()) {
    apiError('UNAUTHORIZED', 'Not logged in.', [], 401);
}

$db     = getDB();
$uid    = (int)$_SESSION['user_id'];
$role   = currentUserRole();
$method = $_SERVER['REQUEST_METHOD'];

// ── Helpers ───────────────────────────────────────────────────

function fetchReservation(PDO $db, int $id): ?array {
    $stmt = $db->prepare(
        "SELECT r.*, c.name AS court_name, c.credit_cost, c.max_queue
           FROM falcon.reservations r
           JOIN falcon.courts c ON c.id = r.court_id
          WHERE r.id = ?
          LIMIT 1"
    );
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ─────────────────────────────────────────────────────────────
//  GET
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // GET ?my=1  — player's own reservations
    if (isset($_GET['my'])) {
        $limit  = min(50, max(1, (int)($_GET['limit']  ?? 15)));
        $offset = max(0,          (int)($_GET['offset'] ?? 0));

        $stmt = $db->prepare(
            "SELECT r.id, r.court_id, c.name AS court_name,
                    r.slot_date, LEFT(r.slot_time::text, 5) AS slot_time,
                    r.slot_end, r.party_size, r.status, r.note,
                    r.created_at, r.updated_at
               FROM falcon.reservations r
               JOIN falcon.courts c ON c.id = r.court_id
              WHERE r.user_id = ?
              ORDER BY r.slot_date DESC, r.slot_time DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([$uid, $limit, $offset]);
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // GET ?id=X  — single reservation (own or admin)
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($id <= 0) apiError('VALIDATION_ERROR', 'Invalid reservation ID.');

        $reservation = fetchReservation($db, $id);
        if (!$reservation) apiError('NOT_FOUND', 'Reservation not found.', [], 404);

        if ((int)$reservation['user_id'] !== $uid && !in_array($role, ADMIN_ROLES, true)) {
            apiError('FORBIDDEN', 'Access denied.', [], 403);
        }

        apiSuccess(['data' => $reservation]);
    }

    // Admin: list all reservations with optional filters
    if (in_array($role, ADMIN_ROLES, true)) {
        $limit      = min(100, max(1, (int)($_GET['limit']  ?? 20)));
        $offset     = max(0,          (int)($_GET['offset'] ?? 0));
        $status     = trim((string)($_GET['status']   ?? ''));
        $courtId    = (int)($_GET['court_id'] ?? 0);

        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }
        if ($courtId > 0) {
            $where[]  = 'r.court_id = ?';
            $params[] = $courtId;
        }

        $whereClause = implode(' AND ', $where);
        $params[]    = $limit;
        $params[]    = $offset;

        $stmt = $db->prepare(
            "SELECT r.id, r.user_id, u.username, u.full_name,
                    r.court_id, c.name AS court_name,
                    r.slot_date, LEFT(r.slot_time::text, 5) AS slot_time,
                    r.slot_end, r.party_size, r.status,
                    r.payment_status, r.payment_amount,
                    r.note, r.created_at, r.updated_at
               FROM falcon.reservations r
               JOIN falcon.courts c ON c.id = r.court_id
               JOIN falcon.users  u ON u.id = r.user_id
              WHERE {$whereClause}
              ORDER BY r.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    apiError('INVALID_REQUEST', 'Invalid GET request.');
}

// ─────────────────────────────────────────────────────────────
//  POST — create a new booking
// ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $courtId = isset($body['court_id']) ? (int)$body['court_id'] : 0;
    if ($courtId <= 0) apiError('VALIDATION_ERROR', 'court_id is required.');

    $courtStmt = $db->prepare(
        "SELECT * FROM falcon.v_court_status WHERE id = ? AND is_active = TRUE AND is_maintenance = FALSE AND live_status = 'available' LIMIT 1"
    );
    $courtStmt->execute([$courtId]);
    $court = $courtStmt->fetch(PDO::FETCH_ASSOC);
    if (!$court) apiError('NOT_FOUND', 'Court not found or unavailable.');

    $errors = validateBookingCreate($body, $court);
    if (!empty($errors)) apiError('VALIDATION_ERROR', 'Validation failed.', $errors, 422);

    $slotDate  = $body['slot_date'];
    $slotTime  = $body['slot_time'];
    $partySize = (int)$body['party_size'];
    $note      = trim((string)($body['note'] ?? ''));

    // Court must be open on that day
    $dayOfWeek = (int)(new DateTime($slotDate))->format('w');
    $hoursStmt = $db->prepare(
        "SELECT open_time, close_time, is_closed
           FROM falcon.court_hours
          WHERE court_id = ? AND day_of_week = ?
          LIMIT 1"
    );
    $hoursStmt->execute([$courtId, $dayOfWeek]);
    $hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);
    if (!$hours || !empty($hours['is_closed'])) {
        apiError('VALIDATION_ERROR', 'Court is closed on the selected date.',
            ['slot_date' => 'Court is closed on that day.'], 422);
    }

    $openTime  = DateTime::createFromFormat('H:i:s', $hours['open_time']);
    $closeTime = DateTime::createFromFormat('H:i:s', $hours['close_time']);
    $reqTime   = DateTime::createFromFormat('H:i',   $slotTime);

    if (!$openTime || !$closeTime || !$reqTime) {
        apiError('VALIDATION_ERROR', 'Unable to validate court schedule.', [], 422);
    }
    if ($reqTime < $openTime || $reqTime >= $closeTime) {
        apiError('VALIDATION_ERROR', 'Selected slot time is outside court hours.',
            ['slot_time' => 'Time is outside operating hours.'], 422);
    }

    $slotEnd = (clone $reqTime)->add(new DateInterval('PT' . (int)$court['game_duration'] . 'M'));
    if ($slotEnd > $closeTime) {
        apiError('VALIDATION_ERROR', 'Selected slot cannot fit before closing time.',
            ['slot_time' => 'Slot extends beyond court hours.'], 422);
    }

    // Check capacity
    $existingStmt = $db->prepare(
        "SELECT COALESCE(SUM(party_size), 0) AS total_booked
           FROM falcon.reservations
          WHERE court_id = ?
            AND slot_date = ?
            AND LEFT(slot_time::text, 5) = ?
            AND status IN ('pending', 'confirmed')"
    );
    $existingStmt->execute([$courtId, $slotDate, $slotTime]);
    $booked = (int)$existingStmt->fetchColumn();
    if ($booked + $partySize > (int)$court['max_queue']) {
        apiError('BOOKING_CONFLICT', 'The selected slot is full.', [], 409);
    }

    // Check wallet balance
    $balanceStmt = $db->prepare(
        "SELECT COALESCE(balance, 0) AS balance FROM falcon.wallets WHERE user_id = ? LIMIT 1"
    );
    $balanceStmt->execute([$uid]);
    $walletBalance  = (float)$balanceStmt->fetchColumn();
    $requiredCredits = (float)$court['credit_cost'] * $partySize;
    if ($walletBalance < $requiredCredits) {
        apiError('INSUFFICIENT_FUNDS', 'Your wallet balance is too low for this booking.', [], 402);
    }

    $slotEndValue = $slotEnd->format('H:i:s');

    $insert = $db->prepare(
        "INSERT INTO falcon.reservations
            (court_id, user_id, slot_date, slot_time, slot_end,
             party_size, status, payment_status, payment_amount,
             note, created_at, updated_at)
         VALUES
            (?, ?, ?, ?::time, ?::time,
             ?, 'pending', 'unpaid', ?,
             ?, NOW(), NOW())
         RETURNING id"
    );
    $insert->execute([
        $courtId, $uid, $slotDate, $slotTime, $slotEndValue,
        $partySize, $requiredCredits, $note,
    ]);
    $reservationId = (int)$insert->fetchColumn();

    // Notify all admins
    notifyAdmins(
        $db,
        'New Booking Request',
        "A new booking request has been created for court {$court['name']}.",
        $reservationId
    );

    apiSuccess(['data' => ['reservation_id' => $reservationId]]);
}

// ─────────────────────────────────────────────────────────────
//  PATCH — transition reservation status
// ─────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = isset($body['id'])     ? (int)$body['id']                : 0;
    $status = isset($body['status']) ? trim((string)$body['status'])   : '';

    if ($id <= 0 || $status === '') {
        apiError('VALIDATION_ERROR', 'Reservation ID and status are required.', [], 422);
    }

    $result = BookingStateMachine::transition($id, $status, ['actor_id' => $uid]);
    if (!$result['ok']) {
        $httpCode = match ($result['error']) {
            'FORBIDDEN'         => 403,
            'NOT_FOUND'         => 404,
            'INSUFFICIENT_FUNDS'=> 402,
            'CONFLICT'          => 409,
            'VALIDATION_ERROR'  => 422,
            default             => 400,
        };
        apiError($result['error'], $result['message'], [], $httpCode);
    }

    apiSuccess(['data' => $result['data']]);
}

// ─────────────────────────────────────────────────────────────
//  DELETE — cancel a reservation
// ─────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) apiError('VALIDATION_ERROR', 'Reservation ID is required.', [], 422);

    $result = BookingStateMachine::transition($id, 'cancelled', ['actor_id' => $uid]);
    if (!$result['ok']) {
        $httpCode = match ($result['error']) {
            'FORBIDDEN'  => 403,
            'NOT_FOUND'  => 404,
            'CONFLICT'   => 409,
            default      => 400,
        };
        apiError($result['error'], $result['message'], [], $httpCode);
    }

    apiSuccess(['data' => $result['data']]);
}

apiError('INVALID_REQUEST', 'Invalid request method.');