<?php
// ============================================================
//  FILE: admin/manual_add_player.php
//  Admin override — adds a player to the queue directly.
//
//  FIX v2 (MULTI-COURT):
//
//  1. `add` action now accepts `court_id` from the request body
//     (sent by the manual-add modal in admin/active_game.php).
//     Falls back to the lowest-sort-order active court if omitted.
//
//  2. Queue INSERT now includes court_id:
//       INSERT INTO falcon.game_queue (user_id, pass_id, court_id, joined_at)
//     Without this, process_scan's findBestAvailableCourt() could
//     route the next player to a different court than the one
//     the admin intended.
//
//  3. Queue position is now counted per-court (not globally):
//       WHERE session_id IS NULL AND (court_id = ? OR court_id IS NULL)
//
//  4. Notification message names the target court.
//
//  PRESERVED from v1:
//   • SAVEPOINT pattern for upsert_pass (SQLSTATE 25P02 fix)
//   • All search logic unchanged
//   • All guard checks (banned, already_queued, in_active_game)
//   • Placeholder pass upsert for admin-added players
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required.']);
    exit;
}
verifySameOrigin();
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── SEARCH ────────────────────────────────────────────────────
if ($action === 'search') {
    $q    = trim($body['q'] ?? '');
    $term = '%' . mb_strtolower($q) . '%';

    $stmt = getDB()->prepare("
        SELECT u.id, u.username, u.full_name, u.phone,
               COALESCE(w.balance, 0) AS balance,
               u.is_banned, u.is_verified,
               EXISTS (
                   SELECT 1 FROM falcon.game_queue gq
                   WHERE gq.user_id = u.id AND gq.session_id IS NULL
               ) AS in_queue,
               EXISTS (
                   SELECT 1 FROM falcon.game_players gp
                   JOIN falcon.game_sessions gs ON gs.id = gp.session_id
                   WHERE gp.user_id = u.id AND gs.status = 'active'
               ) AS in_active_game
        FROM falcon.users u
        LEFT JOIN falcon.wallets w ON w.user_id = u.id
        WHERE u.role = 'player'
          AND u.is_banned = FALSE
          AND (LOWER(u.username)  LIKE :t
            OR LOWER(u.full_name) LIKE :t
            OR u.phone            LIKE :t)
        ORDER BY u.full_name ASC
        LIMIT 10
    ");
    $stmt->execute([':t' => $term]);
    echo json_encode(['status' => 'ok', 'players' => $stmt->fetchAll()]);
    exit;
}

// ── ADD ───────────────────────────────────────────────────────
if ($action === 'add') {
    $userId = isset($body['user_id']) ? (int)$body['user_id'] : 0;
    if (!$userId) {
        echo json_encode(['status' => 'error', 'message' => 'user_id required.']);
        exit;
    }

    $db = getDB();

    // ── Resolve target court ──────────────────────────────────
    // Use the court_id sent by the modal. If absent or invalid,
    // fall back to the first active, non-maintenance court.
    $requestedCourtId = isset($body['court_id']) ? (int)$body['court_id'] : 0;
    $courtRow = null;

    // Court-activation-aware: only courts flagged is_queueable in
    // falcon.v_court_status are eligible (excludes deactivated,
    // maintenance, admin-reserved, admin-occupied, tournament, and
    // courts with a real game already in progress).
    if ($requestedCourtId) {
        $cStmt = $db->prepare("
            SELECT id, name, short_code
            FROM falcon.v_court_status
            WHERE id = ? AND is_queueable = TRUE
            LIMIT 1
        ");
        $cStmt->execute([$requestedCourtId]);
        $courtRow = $cStmt->fetch();
    }

    if (!$courtRow) {
        // Fallback: pick best available court (smallest queue first)
        $courtRow = $db->query("
            SELECT id, name, short_code
            FROM falcon.v_court_status
            WHERE is_queueable = TRUE
            ORDER BY COALESCE(queue_count, 0) ASC, sort_order ASC
            LIMIT 1
        ")->fetch();
    }

    if (!$courtRow) {
        echo json_encode(['status' => 'error', 'message' => 'No available courts found.']);
        exit;
    }

    $courtId    = (int)$courtRow['id'];
    $courtName  = $courtRow['name'];
    $shortCode  = $courtRow['short_code'] ?? ('C' . $courtId);

    // ── Fetch player ──────────────────────────────────────────
    $ps = $db->prepare("
        SELECT id, username, full_name, is_banned
        FROM falcon.users WHERE id = ? AND role = 'player'
    ");
    $ps->execute([$userId]);
    $player = $ps->fetch();

    if (!$player) {
        echo json_encode(['status' => 'error', 'message' => 'Player not found.']);
        exit;
    }
    if ($player['is_banned']) {
        echo json_encode(['status' => 'error', 'message' => 'Player is banned.']);
        exit;
    }

    try {
        $db->beginTransaction();

        // Guard: already in queue (any court)?
        $g1 = $db->prepare("
            SELECT id FROM falcon.game_queue
            WHERE user_id = ? AND session_id IS NULL
        ");
        $g1->execute([$userId]);
        if ($g1->fetch()) {
            $db->rollBack();
            echo json_encode([
                'status'  => 'error',
                'message' => htmlspecialchars($player['full_name']) . ' is already in the queue.',
            ]);
            exit;
        }

        // Guard: already in active game?
        $g2 = $db->prepare("
            SELECT gp.id FROM falcon.game_players gp
            JOIN falcon.game_sessions gs ON gs.id = gp.session_id
            WHERE gp.user_id = ? AND gs.status = 'active'
        ");
        $g2->execute([$userId]);
        if ($g2->fetch()) {
            $db->rollBack();
            echo json_encode([
                'status'  => 'error',
                'message' => htmlspecialchars($player['full_name']) . ' is already in an active game.',
            ]);
            exit;
        }

        // ── Upsert placeholder pass ───────────────────────────
        // Ensures game_engine's pass JOIN never fails for admin-added players.
        $db->exec('SAVEPOINT upsert_pass');
        try {
            $db->prepare("
                INSERT INTO falcon.player_passes
                    (user_id, qr_token, expires_at, is_active, issued_at, created_at)
                VALUES (?, ?, '2099-12-31 23:59:59+00', TRUE, NOW(), NOW())
                ON CONFLICT (user_id) DO UPDATE
                    SET is_active  = TRUE,
                        expires_at = '2099-12-31 23:59:59+00'
            ")->execute([$userId, bin2hex(random_bytes(24))]);
            $db->exec('RELEASE SAVEPOINT upsert_pass');
        } catch (PDOException $e) {
            // ON CONFLICT syntax may vary — fall back to two-step upsert
            $db->exec('ROLLBACK TO SAVEPOINT upsert_pass');
            $exists = $db->prepare("
                SELECT id FROM falcon.player_passes WHERE user_id = ? LIMIT 1
            ");
            $exists->execute([$userId]);
            if ($exists->fetch()) {
                $db->prepare("
                    UPDATE falcon.player_passes
                    SET is_active = TRUE, expires_at = '2099-12-31 23:59:59+00'
                    WHERE user_id = ?
                ")->execute([$userId]);
            } else {
                $db->prepare("
                    INSERT INTO falcon.player_passes
                        (user_id, qr_token, expires_at, is_active, issued_at, created_at)
                    VALUES (?, ?, '2099-12-31 23:59:59+00', TRUE, NOW(), NOW())
                ")->execute([$userId, bin2hex(random_bytes(24))]);
            }
        }

        // Fetch pass id (needed for game_queue.pass_id foreign key)
        $passId = $db->prepare("
            SELECT id FROM falcon.player_passes
            WHERE user_id = ? ORDER BY id DESC LIMIT 1
        ");
        $passId->execute([$userId]);
        $passId = $passId->fetchColumn();

        // ── Insert queue row WITH court_id ────────────────────
        // Try with pass_id first, fall back if column is nullable/absent.
        $inserted = false;
        if ($passId) {
            $db->exec('SAVEPOINT queue_insert');
            try {
                $db->prepare("
                    INSERT INTO falcon.game_queue
                        (user_id, pass_id, court_id, joined_at)
                    VALUES (?, ?, ?, NOW())
                ")->execute([$userId, $passId, $courtId]);
                $db->exec('RELEASE SAVEPOINT queue_insert');
                $inserted = true;
            } catch (PDOException $e) {
                $db->exec('ROLLBACK TO SAVEPOINT queue_insert');
            }
        }

        if (!$inserted) {
            // Fallback: without pass_id
            $db->exec('SAVEPOINT queue_insert_nopid');
            try {
                $db->prepare("
                    INSERT INTO falcon.game_queue (user_id, court_id, joined_at)
                    VALUES (?, ?, NOW())
                ")->execute([$userId, $courtId]);
                $db->exec('RELEASE SAVEPOINT queue_insert_nopid');
                $inserted = true;
            } catch (PDOException $e) {
                $db->exec('ROLLBACK TO SAVEPOINT queue_insert_nopid');
            }
        }

        if (!$inserted) {
            // Last resort: no court_id (pre-migration schema)
            $db->prepare("
                INSERT INTO falcon.game_queue (user_id, joined_at)
                VALUES (?, NOW())
            ")->execute([$userId]);
        }

        // ── Notify player ─────────────────────────────────────
        $db->prepare("
            INSERT INTO falcon.notifications
                (user_id, title, message, type, created_at)
            VALUES (?, '👤 Added by Admin',
                    ?, 'info', NOW())
        ")->execute([
            $userId,
            "Admin manually added you to the queue for {$courtName} ({$shortCode}) — ID verified. Welcome! 🏓",
        ]);

        $db->commit();

        // Queue position for THIS court
        $posStmt = $db->prepare("
            SELECT COUNT(*) FROM falcon.game_queue
            WHERE session_id IS NULL
              AND (court_id = ? OR court_id IS NULL)
        ");
        $posStmt->execute([$courtId]);
        $qPos = (int)$posStmt->fetchColumn();

        echo json_encode([
            'status'     => 'ok',
            'message'    => htmlspecialchars($player['full_name'])
                          . " added to {$courtName} queue (position #{$qPos}).",
            'queue_pos'  => $qPos,
            'court_id'   => $courtId,
            'court_name' => $courtName,
        ]);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('manual_add_player: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database error. Please try again.',
        ]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);