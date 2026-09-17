<?php
// ============================================================
//  FILE: api/sse.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';

if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Not logged in.', 'details' => (object)[]]]);
    exit;
}

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}

function sseSend(string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (function_exists('ob_flush')) ob_flush();
    flush();
}

function sseComment(string $text): void {
    echo ": {$text}\n\n";
    if (function_exists('ob_flush')) ob_flush();
    flush();
}

$db = getDB();
$uid = (int)$_SESSION['user_id'];
$lastId = isset($_GET['last_id']) ? max(0, (int)$_GET['last_id']) : 0;
$startTs = time();
$lastPing = 0;
$previousStatuses = [];

function loadActiveReservationStatuses(PDO $db, int $uid): array {
    $stmt = $db->prepare(
        "SELECT id, status FROM falcon.reservations
          WHERE user_id = ?
            AND status IN ('pending', 'confirmed', 'no_show', 'disputed')
          ORDER BY id ASC"
    );
    $stmt->execute([$uid]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['id']] = $row['status'];
    }
    return $map;
}

$previousStatuses = loadActiveReservationStatuses($db, $uid);

function checkNotifications(PDO $db, int $uid, int &$lastId): void {
    $stmt = $db->prepare(
        "SELECT id, title, message, type, link, reservation_id, created_at
           FROM falcon.notifications
          WHERE user_id = ? AND id > ?
          ORDER BY id ASC"
    );
    $stmt->execute([$uid, $lastId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $lastId = max($lastId, (int)$row['id']);
        sseSend('notification', $row);
    }
}

function checkBookingUpdates(PDO $db, int $uid, array &$previousStatuses): void {
    $current = loadActiveReservationStatuses($db, $uid);
    foreach ($current as $reservationId => $status) {
        if (!isset($previousStatuses[$reservationId]) || $previousStatuses[$reservationId] !== $status) {
            sseSend('booking_update', ['reservation_id' => $reservationId, 'status' => $status]);
        }
    }
    foreach ($previousStatuses as $reservationId => $status) {
        if (!isset($current[$reservationId])) {
            sseSend('booking_update', ['reservation_id' => $reservationId, 'status' => 'removed']);
        }
    }
    $previousStatuses = $current;
}

sseComment('connected');

while (time() - $startTs < 55) {
    if (connection_aborted()) {
        break;
    }

    checkNotifications($db, $uid, $lastId);
    checkBookingUpdates($db, $uid, $previousStatuses);

    if (time() - $lastPing >= 15) {
        sseComment('ping');
        $lastPing = time();
    }

    sleep(3);
}

sseComment('closed');

/*
Client-side JavaScript example:

<script nonce="<?= getCspNonce() ?>">
(function() {
    const lastId = parseInt(localStorage.getItem('sse_last_id') || '0', 10);
    let retryDelay = 1000;

    function connect() {
        const source = new EventSource('/api/sse.php?last_id=' + lastId);

        source.addEventListener('notification', function(event) {
            const payload = JSON.parse(event.data);
            localStorage.setItem('sse_last_id', payload.id);
            // Update notification bell count or badge here.
            fetchNotifications();
        });

        source.addEventListener('booking_update', function(event) {
            const payload = JSON.parse(event.data);
            console.log('Booking update', payload);
            window.location.reload();
        });

        source.onopen = function() {
            retryDelay = 1000;
            console.log('SSE connected');
        };

        source.onerror = function() {
            console.warn('SSE connection error, retrying in ' + retryDelay + 'ms');
            source.close();
            setTimeout(connect, retryDelay);
            retryDelay = Math.min(30000, retryDelay * 2);
        };
    }

    function fetchNotifications() {
        // Replace with your existing notification refresh logic.
    }

    connect();
})();
</script>
*/
