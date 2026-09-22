<?php
// ============================================================
//  FILE: api/mobile.php
//
//  Mobile app API endpoints for Padol Pickleball Court.
//
//  Endpoints:
//  - GET /api/mobile.php/status  → Court status for mobile
//  - POST /api/mobile.php/scan   → RETIRED (was QR scan check-in)
//  - GET /api/mobile.php/history → Player game history
//
//  Auth: Bearer token required
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json; charset=UTF-8');

// ── Mobile Auth (Bearer token) ───────────────────────────────
// Added rate limiting: this endpoint takes an opaque token straight off
// the wire with no other check, so without a limiter it's brute-forceable
// (each guess = one more falcon.player_passes lookup).
if (!checkRateLimit('mobile_auth_' . getClientIp(), 30, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'RateLimited', 'message' => 'Too many requests. Please wait.']);
    exit;
}

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Bearer token required']);
    exit;
}

$token = $matches[1];
$db = getDB();

// Verify token.
//
// NOTE: player_passes.qr_token is used here purely as an opaque API
// bearer credential for the mobile client — it is NOT a scannable pass.
// Scanner check-in is retired, but this is authentication, so the lookup
// stays. Treat the column as "mobile API key" going forward.
$userStmt = $db->prepare("
    SELECT u.id, u.username, u.full_name, u.role
    FROM falcon.users u
    JOIN falcon.player_passes pp ON pp.user_id = u.id
    WHERE pp.qr_token = ?
");
$userStmt->execute([$token]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid token']);
    exit;
}

$userId = (int)$user['id'];

// ── Route handling ───────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? 'status';

switch ($path) {
    case 'status':
        if ($method !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        getCourtStatus();
        break;

    case 'scan':
        // RETIRED — QR scan check-in no longer exists. Old app builds
        // still posting here get a clear, parseable answer pointing at
        // the Open Play queue instead of a silent fake success.
        http_response_code(410);
        echo json_encode([
            'error'    => 'Gone',
            'message'  => 'QR scan check-in has been retired. Join the Open Play queue instead.',
            'redirect' => appUrl('public/open_play.php'),
        ]);
        break;

    case 'history':
        if ($method !== 'GET') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        getGameHistory();
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        break;
}

function getCourtStatus() {
    global $db, $cache;

    // Use cache if available
    $cached = $cache->isAvailable() ? $cache->get('mobile_court_status') : null;
    if ($cached) {
        echo json_encode($cached);
        return;
    }

    $courts = $db->query("
        SELECT id, name, short_code, live_status, queue_count, credit_cost
        FROM falcon.v_court_status
        WHERE is_active = TRUE
        ORDER BY sort_order, id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'courts' => $courts,
        'updated_at' => date('c'),
    ];

    // Cache for 30 seconds
    if ($cache->isAvailable()) {
        $cache->set('mobile_court_status', $response, 30);
    }

    echo json_encode($response);
}

function getGameHistory() {
    global $db, $userId;

    $limit = (int)($_GET['limit'] ?? 10);
    $limit = min($limit, 50); // Max 50

    $stmt = $db->prepare("
        SELECT gs.id, gs.started_at, gs.ended_at, gs.status,
               c.name AS court_name, gp.credits_charged
        FROM falcon.game_players gp
        JOIN falcon.game_sessions gs ON gs.id = gp.session_id
        JOIN falcon.courts c ON c.id = gs.court_id
        WHERE gp.user_id = ?
        ORDER BY gs.started_at DESC
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'games' => $games,
        'total' => count($games),
    ]);
}