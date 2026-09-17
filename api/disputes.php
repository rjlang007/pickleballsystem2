<?php
// ============================================================
//  FILE: api/disputes.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/booking_state_machine.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$rlKey = 'api_disputes_' . getClientIp();
if (!checkRateLimit($rlKey, 30, 60)) {
    header('Retry-After: 60');
    apiError('RATE_LIMITED', 'Too many requests. Please wait.', [], 429);
}

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─────────────────────────────────────────────────────────────
//  GET
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // GET ?id=X  — single dispute (owner or admin)
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($id <= 0) apiError('VALIDATION_ERROR', 'Invalid dispute ID.');

        $stmt = $db->prepare(
            "SELECT d.*, u.username AS filed_by_username, u.full_name AS filed_by_name
               FROM falcon.disputes d
               JOIN falcon.users u ON u.id = d.filed_by
              WHERE d.id = ?"
        );
        $stmt->execute([$id]);
        $dispute = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dispute) apiError('NOT_FOUND', 'Dispute not found.', [], 404);

        if (!isLoggedIn()) apiError('UNAUTHORIZED', 'Not logged in.', [], 401);

        $uid = (int)$_SESSION['user_id'];
        if ((int)$dispute['filed_by'] !== $uid && !isAdmin()) {
            apiError('FORBIDDEN', 'Access denied.', [], 403);
        }

        apiSuccess(['data' => $dispute]);
    }

    // List disputes (admin only)
    if (!isLoggedIn() || !isAdmin()) {
        apiError('FORBIDDEN', 'Admin access required.', [], 403);
    }

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $limit        = min(100, max(1, (int)($_GET['limit']  ?? 20)));
    $offset       = max(0,          (int)($_GET['offset'] ?? 0));

    $where  = ['1=1'];
    $params = [];

    if ($statusFilter !== '') {
        $where[]  = 'd.status = ?';
        $params[] = $statusFilter;
    }

    $whereClause = implode(' AND ', $where);
    $params[]    = $limit;
    $params[]    = $offset;

    $stmt = $db->prepare(
        "SELECT d.*, u.username AS filed_by_username, u.full_name AS filed_by_name
           FROM falcon.disputes d
           JOIN falcon.users u ON u.id = d.filed_by
          WHERE {$whereClause}
          ORDER BY d.created_at DESC
          LIMIT ? OFFSET ?"
    );
    $stmt->execute($params);
    apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─────────────────────────────────────────────────────────────
//  POST — player files a dispute
// ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!isLoggedIn()) apiError('UNAUTHORIZED', 'Not logged in.', [], 401);

    $uid  = (int)$_SESSION['user_id'];
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $errors = validateDispute($body);
    if (!empty($errors)) apiError('VALIDATION_ERROR', 'Validation failed.', $errors, 422);

    $reservationId = (int)$body['reservation_id'];
    $reason        = trim($body['reason']);

    $reservationStmt = $db->prepare(
        "SELECT r.id, r.user_id, r.status, r.slot_date, r.slot_time
           FROM falcon.reservations r
          WHERE r.id = ?
          LIMIT 1"
    );
    $reservationStmt->execute([$reservationId]);
    $reservation = $reservationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation || (int)$reservation['user_id'] !== $uid) {
        apiError('NOT_FOUND', 'Reservation not found.', [], 404);
    }
    if ($reservation['status'] !== 'no_show') {
        apiError('VALIDATION_ERROR', 'Only no-show reservations can be disputed.', [], 422);
    }

    $slotDateTime = DateTime::createFromFormat('Y-m-d H:i', "{$reservation['slot_date']} {$reservation['slot_time']}");
    if (!$slotDateTime) apiError('SERVER_ERROR', 'Reservation slot data is invalid.');

    if ((time() - $slotDateTime->getTimestamp()) > 48 * 3600) {
        apiError('VALIDATION_ERROR', 'Dispute must be filed within 48 hours of the reservation slot.', [], 422);
    }

    $existsStmt = $db->prepare(
        "SELECT 1 FROM falcon.disputes WHERE reservation_id = ? LIMIT 1"
    );
    $existsStmt->execute([$reservationId]);
    if ($existsStmt->fetch()) {
        apiError('CONFLICT', 'A dispute already exists for this reservation.', [], 409);
    }

    $result = BookingStateMachine::transition(
        $reservationId, 'disputed', ['actor_id' => $uid, 'reason' => $reason]
    );
    if (!$result['ok']) {
        $httpCode = match ($result['error']) {
            'FORBIDDEN'        => 403,
            'NOT_FOUND'        => 404,
            'CONFLICT'         => 409,
            'VALIDATION_ERROR' => 422,
            default            => 400,
        };
        apiError($result['error'], $result['message'], [], $httpCode);
    }

    // Notify admins that a dispute was filed
    notifyAdmins(
        $db,
        'New Dispute Filed',
        "A player has filed a dispute for reservation #{$reservationId}.",
        $reservationId
    );

    apiSuccess(['data' => ['reservation_id' => $reservationId, 'status' => 'disputed']]);
}

// ─────────────────────────────────────────────────────────────
//  PATCH — admin resolves or dismisses a dispute
//
//  Body: { "id": 123, "status": "resolved"|"dismissed", "admin_note": "..." }
// ─────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    if (!isLoggedIn() || !isAdmin()) {
        apiError('FORBIDDEN', 'Admin access required.', [], 403);
    }

    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id        = isset($body['id'])     ? (int)$body['id']                : 0;
    $newStatus = isset($body['status']) ? trim((string)$body['status'])   : '';
    $adminNote = trim((string)($body['admin_note'] ?? ''));

    if ($id <= 0 || !in_array($newStatus, ['resolved', 'dismissed'], true)) {
        apiError('VALIDATION_ERROR',
            'Dispute ID is required and status must be "resolved" or "dismissed".', [], 422);
    }

    // Fetch dispute + player info for notification
    $disputeStmt = $db->prepare(
        "SELECT d.*, r.user_id AS player_id
           FROM falcon.disputes d
           JOIN falcon.reservations r ON r.id = d.reservation_id
          WHERE d.id = ?
          LIMIT 1"
    );
    $disputeStmt->execute([$id]);
    $dispute = $disputeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) apiError('NOT_FOUND', 'Dispute not found.', [], 404);
    if ($dispute['status'] !== 'open') {
        apiError('CONFLICT', "Dispute is already {$dispute['status']}.", [], 409);
    }

    $db->beginTransaction();
    try {
        $db->prepare(
            "UPDATE falcon.disputes
                SET status      = :status,
                    admin_note  = :note,
                    resolved_by = :admin,
                    resolved_at = NOW()
              WHERE id          = :id"
        )->execute([
            ':status' => $newStatus,
            ':note'   => $adminNote !== '' ? $adminNote : null,
            ':admin'  => (int)$_SESSION['user_id'],
            ':id'     => $id,
        ]);

        // Notify the player who filed the dispute
        $playerMessage = $newStatus === 'resolved'
            ? "Your dispute for reservation #{$dispute['reservation_id']} has been resolved." .
              ($adminNote !== '' ? " Admin note: {$adminNote}" : '')
            : "Your dispute for reservation #{$dispute['reservation_id']} was dismissed." .
              ($adminNote !== '' ? " Reason: {$adminNote}" : '');

        notifyUser(
            $db,
            (int)$dispute['player_id'],
            $newStatus === 'resolved' ? 'Dispute Resolved' : 'Dispute Dismissed',
            $playerMessage,
            (int)$dispute['reservation_id']
        );

        // Audit
        auditLog(
            $db,
            "dispute_{$newStatus}",
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            'disputes',
            $id,
            ['status' => 'open'],
            ['status' => $newStatus, 'admin_note' => $adminNote],
            'success'
        );

        $db->commit();

        apiSuccess([
            'data' => [
                'dispute_id'     => $id,
                'status'         => $newStatus,
                'reservation_id' => (int)$dispute['reservation_id'],
            ],
        ]);

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[disputes PATCH] ' . $e->getMessage());
        apiError('SERVER_ERROR', 'Failed to update dispute.', [], 500);
    }
}

apiError('INVALID_REQUEST', 'Invalid request method.');