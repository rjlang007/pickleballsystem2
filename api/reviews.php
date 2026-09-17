<?php
// ============================================================
//  FILE: api/reviews.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$rlKey = 'api_reviews_' . getClientIp();
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

    // GET ?court_id=X  — public: reviews for a court
    if (isset($_GET['court_id'])) {
        $courtId = (int)$_GET['court_id'];
        if ($courtId <= 0) apiError('VALIDATION_ERROR', 'Invalid court_id.');

        $stmt = $db->prepare(
            "SELECT rev.id, rev.rating, rev.comment, rev.created_at,
                    u.full_name AS reviewer_name, u.username
               FROM falcon.reviews rev
               JOIN falcon.reservations r ON r.id = rev.reservation_id
               JOIN falcon.users u ON u.id = rev.user_id
              WHERE r.court_id = ?
                AND r.status   = 'completed'
              ORDER BY rev.created_at DESC"
        );
        $stmt->execute([$courtId]);
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // GET ?my=1  — authenticated: player's own reviews
    if (isset($_GET['my'])) {
        if (!isLoggedIn()) apiError('UNAUTHORIZED', 'Not logged in.', [], 401);
        $uid = (int)$_SESSION['user_id'];

        $stmt = $db->prepare(
            "SELECT rev.id, rev.rating, rev.comment, rev.created_at,
                    r.court_id, r.slot_date, LEFT(r.slot_time::text, 5) AS slot_time
               FROM falcon.reviews rev
               JOIN falcon.reservations r ON r.id = rev.reservation_id
              WHERE rev.user_id = ?
              ORDER BY rev.created_at DESC"
        );
        $stmt->execute([$uid]);
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Admin: all reviews
    if (isLoggedIn() && isAdmin()) {
        $limit  = min(100, max(1, (int)($_GET['limit']  ?? 20)));
        $offset = max(0,          (int)($_GET['offset'] ?? 0));
        $stmt   = $db->prepare(
            "SELECT rev.id, rev.rating, rev.comment, rev.created_at,
                    u.username, u.full_name AS reviewer_name,
                    r.court_id, r.slot_date
               FROM falcon.reviews rev
               JOIN falcon.reservations r ON r.id = rev.reservation_id
               JOIN falcon.users u ON u.id = rev.user_id
              ORDER BY rev.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    apiError('INVALID_REQUEST', 'Invalid GET request.');
}

// ─────────────────────────────────────────────────────────────
//  POST — submit a review (completed reservations only)
// ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!isLoggedIn()) apiError('UNAUTHORIZED', 'Not logged in.', [], 401);

    $uid  = (int)$_SESSION['user_id'];
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $errors = validateReview($body);
    if (!empty($errors)) apiError('VALIDATION_ERROR', 'Validation failed.', $errors, 422);

    $reservationId = (int)$body['reservation_id'];
    $rating        = (int)$body['rating'];
    $comment       = trim((string)$body['comment']);

    // Confirm the reservation belongs to this player and is completed
    $reservationStmt = $db->prepare(
        "SELECT id, user_id, court_id, status
           FROM falcon.reservations
          WHERE id      = ?
            AND user_id = ?
          LIMIT 1"
    );
    $reservationStmt->execute([$reservationId, $uid]);
    $reservation = $reservationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        apiError('NOT_FOUND', 'Reservation not found.', [], 404);
    }
    if ($reservation['status'] !== 'completed') {
        apiError('VALIDATION_ERROR', 'Only completed reservations may be reviewed.', [], 422);
    }

    // Prevent duplicate reviews
    $existsStmt = $db->prepare(
        "SELECT 1 FROM falcon.reviews
          WHERE reservation_id = ? AND user_id = ? LIMIT 1"
    );
    $existsStmt->execute([$reservationId, $uid]);
    if ($existsStmt->fetch()) {
        apiError('CONFLICT', 'You have already reviewed this reservation.', [], 409);
    }

    $db->prepare(
        "INSERT INTO falcon.reviews (reservation_id, user_id, rating, comment, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    )->execute([$reservationId, $uid, $rating, $comment]);

    // Notify admins of the new review
    notifyAdmins(
        $db,
        'New Review Submitted',
        "A player left a {$rating}-star review for reservation #{$reservationId}.",
        $reservationId
    );

    apiSuccess(['data' => ['reservation_id' => $reservationId, 'rating' => $rating]]);
}

apiError('INVALID_REQUEST', 'Invalid request method.');