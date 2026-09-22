<?php
// ============================================================
//  FILE: api/chat.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
ob_start();

set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    error_log('[chat.php] Uncaught: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again.']);
    exit;
});
set_error_handler(function (int $errno, string $errstr) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => "PHP error ($errno): $errstr"]);
    exit;
});

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// State-changing chat actions should never be reachable over GET, and get
// the Origin/Referer defense-in-depth check on top of the session cookie.
if (in_array($action, ['send'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    verifySameOrigin();
    if (!checkRateLimit('chat_send_' . ($_SESSION['user_id'] ?? getClientIp()), 30, 60)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many messages. Please slow down.']);
        exit;
    }
}

function jsonOut(array $data): never {
    ob_end_clean();
    echo json_encode($data);
    exit;
}

function jsonErr(string $msg, int $code = 400): never {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── Status (public — no auth required) ──────────────────────
if ($action === 'status') {
    jsonOut([
        'ok'       => true,
        'loggedIn' => isLoggedIn(),
        'isAdmin'  => isLoggedIn() && isAdmin(),
        'username' => isLoggedIn() ? ($_SESSION['username'] ?? '') : null,
    ]);
}

// ── Auth recovery: stamp fingerprint if user_id present but _fp missing ──────
if (!isLoggedIn() && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    stampSessionFingerprint();
    $_SESSION['_last_activity'] = time();
}

// NOTE: This file used to contain a "PHPSESSID fallback" block that took
// $_COOKIE['PHPSESSID'] — a value the client fully controls — and used it to
// adopt a server-side session via session_id($_COOKIE['PHPSESSID']) with
// use_strict_mode disabled, then copied whatever user_id it found into the
// real FALCON_SESS session. That's session fixation: anyone who gets a
// target to send a request carrying a chosen/known PHPSESSID (or who
// re-sends a session id captured another way) gets that user's identity
// copied into their own session, no password required. It was removed
// rather than hardened — DB-backed sessions here expire after 2 hours
// (see config/session.php), so even the original "legacy PHPSESSID →
// FALCON_SESS migration after the rename" use case closed itself out
// within hours of that deploy; there's no legitimate reason left to trust
// a client-supplied session id here. If a real "logged in but chat.php
// says unauthenticated" bug shows up, it should be root-caused (e.g. a
// cookie path/domain/SameSite mismatch) rather than patched with another
// session-adoption fallback.

if (!isLoggedIn()) {
    error_log('[chat.php] Not authenticated'
        . ' session_id=' . session_id()
        . ' user_id=' . ($_SESSION['user_id'] ?? 'MISSING')
        . ' has_fp=' . (isset($_SESSION['_fp']) ? 'yes' : 'no')
        . ' cookies=' . implode(',', array_keys($_COOKIE)));
    jsonErr('Not authenticated', 401);
}

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Helpers ──────────────────────────────────────────────────

function getConvId(PDO $db, int $userId): ?int {
    $stmt = $db->prepare(
        "SELECT id FROM falcon.chat_conversations WHERE user_id = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function ensureConversation(PDO $db, int $userId): int {
    $existing = getConvId($db, $userId);
    if ($existing !== null) {
        $db->prepare(
            "UPDATE falcon.chat_conversations SET updated_at = NOW() WHERE id = ?"
        )->execute([$existing]);
        return $existing;
    }
    $stmt = $db->prepare(
        "INSERT INTO falcon.chat_conversations (user_id, created_at, updated_at)
         VALUES (?, NOW(), NOW())
         RETURNING id"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Resolve which conversation to use.
 *
 * - Player                      → their own conversation
 * - Admin + target_user_id > 0  → target player's conversation
 * - Admin + no target_user_id   → null (admins have no self-conversation)
 *
 * $create = true  → create conversation if missing
 * $create = false → return null if missing (poll / non-creating fetch)
 */
function resolveConvId(PDO $db, int $uid, bool $isAdmin, int $targetUid, bool $create): ?int {
    // Admins without a target have no conversation of their own
    if ($isAdmin && $targetUid <= 0) return null;

    $owner = $isAdmin ? $targetUid : $uid;
    return $create ? ensureConversation($db, $owner) : getConvId($db, $owner);
}

function formatMessages(array $rows, int $myId): array {
    $out = [];
    foreach ($rows as $r) {
        if ($r['is_deleted'] ?? false) continue;
        $out[] = [
            'id'              => (int)$r['id'],
            'text'            => $r['message'] ?? '',
            'mine'            => (int)$r['sender_id'] === $myId,
            'sender'          => in_array($r['sender_role'] ?? '', ['admin', 'super_admin'])
                                    ? 'Admin'
                                    : ($r['sender_name'] ?? 'Unknown'),
            'is_admin_sender' => in_array($r['sender_role'] ?? '', ['admin', 'super_admin']),
            'time'            => date('h:i A', strtotime($r['created_at'])),
            'date'            => date('Y-m-d', strtotime($r['created_at'])),
            'date_label'      => date('F j, Y', strtotime($r['created_at'])),
            'timestamp'       => strtotime($r['created_at']),
            'is_read'         => (bool)($r['is_read'] ?? false),
        ];
    }
    return $out;
}

function countUnread(PDO $db, int $convId, int $myId): int {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM falcon.chat_messages
         WHERE conversation_id = ?
           AND sender_id != ?
           AND is_read = FALSE
           AND (is_deleted = FALSE OR is_deleted IS NULL)"
    );
    $stmt->execute([$convId, $myId]);
    return (int)$stmt->fetchColumn();
}

function markRead(PDO $db, int $convId, int $myId): void {
    $db->prepare(
        "UPDATE falcon.chat_messages
         SET is_read = TRUE
         WHERE conversation_id = ?
           AND sender_id != ?
           AND is_read = FALSE"
    )->execute([$convId, $myId]);
}

function resyncSerialSequence(PDO $db, string $table, string $column): void {
    $seqStmt = $db->prepare("SELECT pg_get_serial_sequence(?, ?)");
    $seqStmt->execute([$table, $column]);
    $seqName = $seqStmt->fetchColumn();
    if (!$seqName) {
        return;
    }
    $db->prepare(
        "SELECT setval(?, (SELECT COALESCE(MAX($column), 0) FROM $table), TRUE)"
    )->execute([$seqName]);
}

// ── Parse common params ──────────────────────────────────────
$adminFlag    = isAdmin();
$targetUserId = (int)($_GET['target_user_id'] ?? 0);

// ============================================================
//  ACTION: send
// ============================================================
if ($action === 'send') {
    try {
        $raw          = file_get_contents('php://input');
        $body         = json_decode($raw, true) ?? [];
        $text         = trim($body['message'] ?? '');
        $targetUserId = (int)($body['target_user_id'] ?? $targetUserId);

        if ($text === '') jsonErr('Empty message');
        if (mb_strlen($text) > 2000) jsonErr('Message too long (max 2000 chars)');

        // ── GUARD: admins must target a player — never themselves ──
        if ($adminFlag) {
            if ($targetUserId <= 0) {
                jsonErr('Admins must select a player to message.', 400);
            }
            if ($targetUserId === $uid) {
                jsonErr('You cannot send a message to yourself.', 400);
            }
            // Verify target is actually a player (not another admin)
            $chk = $db->prepare("SELECT role FROM falcon.users WHERE id = ? AND is_active = TRUE LIMIT 1");
            $chk->execute([$targetUserId]);
            $targetRole = $chk->fetchColumn();
            if ($targetRole === false) jsonErr('Target player not found.', 404);
            if (in_array($targetRole, ['admin', 'super_admin'])) {
                jsonErr('You cannot message another admin.', 400);
            }
        }

        if (function_exists('checkRateLimit') &&
            !checkRateLimit('chat_send_' . $uid, 20, 60)) {
            jsonErr('Slow down — too many messages!', 429);
        }

        $convId = resolveConvId($db, $uid, $adminFlag, $targetUserId, true);

        if ($convId === null) {
            jsonErr('No conversation target.', 400);
        }

        $stmt = $db->prepare(
            "INSERT INTO falcon.chat_messages
                 (conversation_id, sender_id, message, msg_type, is_read, is_deleted)
             VALUES (?, ?, ?, 'text', FALSE, FALSE)
             RETURNING id, created_at"
        );
        try {
            $stmt->execute([$convId, $uid, $text]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' && stripos($e->getMessage(), 'chat_messages_pkey') !== false) {
                resyncSerialSequence($db, 'falcon.chat_messages', 'id');
                $stmt->execute([$convId, $uid, $text]);
            } else {
                throw $e;
            }
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->prepare(
            "UPDATE falcon.chat_conversations SET updated_at = NOW() WHERE id = ?"
        )->execute([$convId]);

        jsonOut([
            'ok'      => true,
            'message' => [
                'id'              => (int)$row['id'],
                'text'            => $text,
                'mine'            => true,
                'sender'          => $adminFlag ? 'Admin' : ($_SESSION['username'] ?? ''),
                'is_admin_sender' => $adminFlag,
                'date'            => date('Y-m-d',  strtotime($row['created_at'])),
                'date_label'      => date('F j, Y', strtotime($row['created_at'])),
                'time'            => date('h:i A',  strtotime($row['created_at'])),
                'timestamp'       => strtotime($row['created_at']),
                'is_read'         => false,
            ],
        ]);
    } catch (\Throwable $e) {
        error_log('[chat.php] Send error: ' . $e->getMessage());
        jsonErr('Could not send the message. Please try again.');
    }
}

// ============================================================
//  ACTION: fetch
// ============================================================
if ($action === 'fetch') {
    try {
        $lastId = (int)($_GET['last_id'] ?? 0);

        // Admins without a target: return empty (no self-conversation)
        if ($adminFlag && $targetUserId <= 0) {
            jsonOut(['ok' => true, 'messages' => [], 'conv_id' => null]);
        }

        // Players always get/create their conversation.
        // Admins: create if targeting a player.
        $create = !$adminFlag || $targetUserId > 0;
        $convId = resolveConvId($db, $uid, $adminFlag, $targetUserId, $create);

        if ($convId === null) {
            jsonOut(['ok' => true, 'messages' => [], 'conv_id' => null]);
        }

        markRead($db, $convId, $uid);

        $stmt = $db->prepare(
            "SELECT m.id, m.message, m.sender_id, m.is_read,
                    m.is_deleted, m.created_at,
                    u.username AS sender_name, u.role AS sender_role
             FROM falcon.chat_messages m
             JOIN falcon.users u ON u.id = m.sender_id
             WHERE m.conversation_id = ?
               AND m.id > ?
               AND (m.is_deleted = FALSE OR m.is_deleted IS NULL)
             ORDER BY m.created_at ASC
             LIMIT 100"
        );
        $stmt->execute([$convId, $lastId]);

        jsonOut([
            'ok'       => true,
            'messages' => formatMessages($stmt->fetchAll(PDO::FETCH_ASSOC), $uid),
            'conv_id'  => $convId,
        ]);
    } catch (\Throwable $e) {
        error_log('[chat.php] Fetch error: ' . $e->getMessage());
        jsonErr('Could not load messages. Please try again.');
    }
}

// ============================================================
//  ACTION: poll
// ============================================================
if ($action === 'poll') {
    try {
        $lastId = (int)($_GET['last_id'] ?? 0);

        // Admins without a target: nothing to poll
        if ($adminFlag && $targetUserId <= 0) {
            jsonOut(['ok' => true, 'messages' => [], 'unread' => 0]);
        }

        // poll never creates — returns empty if no conversation yet
        $convId = resolveConvId($db, $uid, $adminFlag, $targetUserId, false);

        if ($convId === null) {
            jsonOut(['ok' => true, 'messages' => [], 'unread' => 0]);
        }

        $stmt = $db->prepare(
            "SELECT m.id, m.message, m.sender_id, m.is_read,
                    m.is_deleted, m.created_at,
                    u.username AS sender_name, u.role AS sender_role
             FROM falcon.chat_messages m
             JOIN falcon.users u ON u.id = m.sender_id
             WHERE m.conversation_id = ?
               AND m.id > ?
               AND (m.is_deleted = FALSE OR m.is_deleted IS NULL)
             ORDER BY m.created_at ASC
             LIMIT 50"
        );
        $stmt->execute([$convId, $lastId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            markRead($db, $convId, $uid);
        }

        jsonOut([
            'ok'       => true,
            'messages' => formatMessages($rows, $uid),
            'unread'   => $adminFlag ? 0 : countUnread($db, $convId, $uid),
        ]);
    } catch (\Throwable $e) {
        error_log('[chat.php] Poll error: ' . $e->getMessage());
        jsonErr('Could not check for new messages.');
    }
}

// ============================================================
//  ACTION: conversations  (admin inbox sidebar + floating widget)
//  Sorted by most recent message activity first (updated_at DESC)
// ============================================================
if ($action === 'conversations') {
    if (!$adminFlag) jsonErr('Admin only', 403);

    try {
        // ORDER BY updated_at DESC so the most recently active conversation is first
        $stmt = $db->query(
            "SELECT c.id, c.user_id, c.updated_at, u.username, u.full_name
             FROM falcon.chat_conversations c
             JOIN falcon.users u ON u.id = c.user_id
             ORDER BY c.updated_at DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $conversations = [];
        foreach ($rows as $r) {
            $convId      = (int)$r['id'];
            $unreadCount = 0;
            $lastMessage = null;
            $lastTime    = null;

            try {
                // Count unread messages FROM the player (sender = player)
                $us = $db->prepare(
                    "SELECT COUNT(*) FROM falcon.chat_messages
                     WHERE conversation_id = ?
                       AND sender_id = ?
                       AND is_read = FALSE
                       AND (is_deleted = FALSE OR is_deleted IS NULL)"
                );
                $us->execute([$convId, (int)$r['user_id']]);
                $unreadCount = (int)$us->fetchColumn();
            } catch (\Throwable $e) {}

            try {
                $ls = $db->prepare(
                    "SELECT message, created_at FROM falcon.chat_messages
                     WHERE conversation_id = ?
                       AND (is_deleted = FALSE OR is_deleted IS NULL)
                     ORDER BY created_at DESC LIMIT 1"
                );
                $ls->execute([$convId]);
                $lr = $ls->fetch(PDO::FETCH_ASSOC);
                if ($lr) {
                    $msg         = $lr['message'] ?? '';
                    $lastMessage = mb_strlen($msg) > 50 ? mb_substr($msg, 0, 50) . '…' : $msg;
                    $lastTime    = date('M d, h:i A', strtotime($lr['created_at']));
                }
            } catch (\Throwable $e) {}

            $conversations[] = [
                'id'           => $convId,
                'user_id'      => (int)$r['user_id'],
                'username'     => $r['username'] ?? '',
                'full_name'    => $r['full_name'] ?? '',
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage,
                'last_time'    => $lastTime,
            ];
        }

        jsonOut(['ok' => true, 'conversations' => $conversations]);

    } catch (\Throwable $e) {
        error_log('[chat.php] Conversations error: ' . $e->getMessage());
        jsonErr('Could not load conversations. Please try again.');
    }
}

// ============================================================
//  ACTION: unread_count
// ============================================================
if ($action === 'unread_count') {
    try {
        if ($adminFlag) {
            $count = (int)$db->query(
                "SELECT COUNT(*)
                 FROM falcon.chat_messages m
                 JOIN falcon.chat_conversations c ON c.id = m.conversation_id
                 JOIN falcon.users u ON u.id = m.sender_id
                 WHERE m.sender_id = c.user_id
                   AND u.role = 'player'
                   AND m.is_read = FALSE
                   AND (m.is_deleted = FALSE OR m.is_deleted IS NULL)"
            )->fetchColumn();
        } else {
            $convId = getConvId($db, $uid);
            if (!$convId) jsonOut(['ok' => true, 'count' => 0]);
            $count = countUnread($db, $convId, $uid);
        }
        jsonOut(['ok' => true, 'count' => $count]);
    } catch (\Throwable $e) {
        jsonOut(['ok' => true, 'count' => 0]);
    }
}

// ============================================================
//  ACTION: search_players  (admin inbox)
// ============================================================
if ($action === 'search_players') {
    if (!$adminFlag) jsonErr('Admin only', 403);

    try {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 1) jsonOut(['ok' => true, 'players' => []]);

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.full_name,
                    c.id AS conv_id, c.updated_at AS conv_updated
             FROM falcon.users u
             LEFT JOIN falcon.chat_conversations c ON c.user_id = u.id
             WHERE u.role = 'player'
               AND u.is_active = TRUE
               AND (u.username ILIKE ? OR u.full_name ILIKE ? OR u.phone ILIKE ?)
             ORDER BY
                 CASE WHEN c.id IS NOT NULL THEN 0 ELSE 1 END,
                 c.updated_at DESC NULLS LAST,
                 u.full_name ASC
             LIMIT 30"
        );
        $stmt->execute([$like, $like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $players = [];
        foreach ($rows as $r) {
            $convId      = $r['conv_id'] ? (int)$r['conv_id'] : null;
            $unreadCount = 0;
            $lastMessage = null;
            $lastTime    = null;

            if ($convId) {
                try {
                    $us = $db->prepare(
                        "SELECT COUNT(*) FROM falcon.chat_messages
                         WHERE conversation_id = ?
                           AND sender_id = ?
                           AND is_read = FALSE
                           AND (is_deleted = FALSE OR is_deleted IS NULL)"
                    );
                    $us->execute([$convId, (int)$r['id']]);
                    $unreadCount = (int)$us->fetchColumn();

                    $ls = $db->prepare(
                        "SELECT message, created_at FROM falcon.chat_messages
                         WHERE conversation_id = ?
                           AND (is_deleted = FALSE OR is_deleted IS NULL)
                         ORDER BY created_at DESC LIMIT 1"
                    );
                    $ls->execute([$convId]);
                    $lr = $ls->fetch(PDO::FETCH_ASSOC);
                    if ($lr) {
                        $msg         = $lr['message'] ?? '';
                        $lastMessage = mb_strlen($msg) > 50 ? mb_substr($msg, 0, 50) . '…' : $msg;
                        $lastTime    = date('M d, h:i A', strtotime($lr['created_at']));
                    }
                } catch (\Throwable $e) {}
            }

            $players[] = [
                'user_id'      => (int)$r['id'],
                'username'     => $r['username'] ?? '',
                'full_name'    => $r['full_name'] ?? '',
                'has_conv'     => $convId !== null,
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage,
                'last_time'    => $lastTime,
            ];
        }

        jsonOut(['ok' => true, 'players' => $players]);

    } catch (\Throwable $e) {
        error_log('[chat.php] Search error: ' . $e->getMessage());
        jsonErr('Search failed. Please try again.');
    }
}

jsonErr('Unknown action');