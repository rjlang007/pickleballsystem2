<?php
// ============================================================
//  FILE: api/chat_rooms.php
//  Step 6 — Community & Group Chat Rooms API
//  Actions:
//    list          — all rooms the user is a member of
//    create        — create a private group room (player)
//    join          — join a room
//    leave         — leave a room
//    send          — send a message to a room
//    fetch         — fetch messages for a room (full load)
//    poll          — poll new messages for a room
//    members       — list members of a room
//    kick          — kick a member (room owner or admin)
//    pin_announce  — pin an announcement to community room (admin only)
//    game_invite   — send a game-invite card message to a room
//    delete_room   — soft-delete a room (owner or admin)
//    unread        — total unread count across all rooms
//    browse        — public group rooms available to join
// ============================================================
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
ob_start();

set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    error_log('[chat_rooms.php] Uncaught: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again.']);
    exit;
});

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// State-changing room actions: block GET-based triggering, add the
// Origin/Referer defense-in-depth check on top of the session cookie.
$mutatingActions = ['create', 'join', 'leave', 'send', 'kick', 'pin_announce', 'game_invite', 'delete_room'];
if (in_array($action, $mutatingActions, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    verifySameOrigin();
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

// ── Status (public) ──────────────────────────────────────────
if ($action === 'status') {
    jsonOut([
        'ok'      => true,
        'loggedIn'=> isLoggedIn(),
        'isAdmin' => isLoggedIn() && isAdmin(),
    ]);
}

if (!isLoggedIn()) {
    jsonErr('Not authenticated', 401);
}

$db       = getDB();
$uid      = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'player';
$isAdmin  = isAdmin();

// ── Helpers ──────────────────────────────────────────────────

function getRoomOrFail(PDO $db, int $roomId): array {
    $stmt = $db->prepare("SELECT * FROM falcon.chat_rooms WHERE id = ? LIMIT 1");
    $stmt->execute([$roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonErr('Room not found', 404);
    return $row;
}

function isMember(PDO $db, int $roomId, int $uid): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM falcon.chat_room_members
         WHERE room_id = ? AND user_id = ? AND left_at IS NULL LIMIT 1"
    );
    $stmt->execute([$roomId, $uid]);
    return (bool)$stmt->fetchColumn();
}

function memberCount(PDO $db, int $roomId): int {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM falcon.chat_room_members
         WHERE room_id = ? AND left_at IS NULL"
    );
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn();
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

function formatRoomMessages(array $rows, int $myId): array {
    $out = [];
    foreach ($rows as $r) {
        if ($r['is_deleted'] ?? false) continue;
        $out[] = [
            'id'          => (int)$r['id'],
            'text'        => $r['message'] ?? '',
            'msg_type'    => $r['msg_type'] ?? 'text',
            'mine'        => (int)$r['sender_id'] === $myId,
            'sender_id'   => (int)$r['sender_id'],
            'sender'      => $r['sender_name'] ?? 'Unknown',
            'sender_role' => $r['sender_role'] ?? 'player',
            'is_admin'    => in_array($r['sender_role'] ?? '', ['admin', 'super_admin']),
            'time'        => date('h:i A', strtotime($r['created_at'])),
            'date'        => date('Y-m-d', strtotime($r['created_at'])),
            'date_label'  => date('F j, Y', strtotime($r['created_at'])),
            'timestamp'   => strtotime($r['created_at']),
            'meta'        => !empty($r['meta']) ? json_decode($r['meta'], true) : null,
            'is_pinned'   => (bool)($r['is_pinned'] ?? false),
            'pinned_by'   => $r['pinned_by_name'] ?? null,
        ];
    }
    return $out;
}

function ensureCommunityRoom(PDO $db): int {
    $stmt = $db->prepare(
        "SELECT id FROM falcon.chat_rooms WHERE room_type = 'community' LIMIT 1"
    );
    $stmt->execute();
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $stmt = $db->prepare(
        "INSERT INTO falcon.chat_rooms
            (name, description, room_type, created_by, max_members, is_active)
         VALUES ('Community Court', 'Open chat for all Padol players', 'community', 0, 9999, TRUE)
         RETURNING id"
    );
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function autoJoinCommunity(PDO $db, int $uid): void {
    $roomId = ensureCommunityRoom($db);
    if (!isMember($db, $roomId, $uid)) {
        $db->prepare(
            "INSERT INTO falcon.chat_room_members (room_id, user_id, role, joined_at)
             VALUES (?, ?, 'member', NOW())
             ON CONFLICT (room_id, user_id) DO UPDATE SET left_at = NULL"
        )->execute([$roomId, $uid]);
    }
}

// ── Parse JSON body ──────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

// ============================================================
//  ACTION: list
// ============================================================
if ($action === 'list') {
    autoJoinCommunity($db, $uid);

    $stmt = $db->prepare(
        "SELECT
            r.id,
            r.name,
            r.description,
            r.room_type,
            r.max_members,
            r.is_active,
            r.created_by,
            r.pinned_announcement,
            r.created_at,
            m.role AS my_role,
            (SELECT COUNT(*) FROM falcon.chat_room_members x
             WHERE x.room_id = r.id AND x.left_at IS NULL) AS member_count,
            (SELECT message FROM falcon.chat_room_messages lm
             WHERE lm.room_id = r.id AND (lm.is_deleted = FALSE OR lm.is_deleted IS NULL)
             ORDER BY lm.created_at DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM falcon.chat_room_messages lm
             WHERE lm.room_id = r.id AND (lm.is_deleted = FALSE OR lm.is_deleted IS NULL)
             ORDER BY lm.created_at DESC LIMIT 1) AS last_activity,
            (SELECT COUNT(*) FROM falcon.chat_room_messages um
             WHERE um.room_id = r.id
               AND um.sender_id != ?
               AND um.is_read = FALSE
               AND (um.is_deleted = FALSE OR um.is_deleted IS NULL)
               AND um.created_at > COALESCE(m.joined_at, '2000-01-01')) AS unread_count
         FROM falcon.chat_rooms r
         JOIN falcon.chat_room_members m
              ON m.room_id = r.id AND m.user_id = ? AND m.left_at IS NULL
         WHERE r.is_active = TRUE
         ORDER BY last_activity DESC NULLS LAST, r.created_at DESC"
    );
    $stmt->execute([$uid, $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rooms = [];
    foreach ($rows as $r) {
        $preview = $r['last_message']
            ? (mb_strlen($r['last_message']) > 60
                ? mb_substr($r['last_message'], 0, 60) . '…'
                : $r['last_message'])
            : null;
        $rooms[] = [
            'id'                  => (int)$r['id'],
            'name'                => $r['name'],
            'description'         => $r['description'],
            'room_type'           => $r['room_type'],
            'max_members'         => (int)$r['max_members'],
            'member_count'        => (int)$r['member_count'],
            'is_active'           => (bool)$r['is_active'],
            'my_role'             => $r['my_role'],
            'pinned_announcement' => $r['pinned_announcement'],
            'last_message_preview'=> $preview,
            'last_activity'       => $r['last_activity'],
            'unread_count'        => (int)$r['unread_count'],
        ];
    }

    jsonOut(['ok' => true, 'rooms' => $rooms]);
}

// ============================================================
//  ACTION: create
// ============================================================
if ($action === 'create') {
    $name       = trim($body['name'] ?? '');
    $description= trim($body['description'] ?? '');
    $maxMembers = max(2, min(20, (int)($body['max_members'] ?? 4)));

    if (mb_strlen($name) < 2)  jsonErr('Room name must be at least 2 characters.');
    if (mb_strlen($name) > 60) jsonErr('Room name too long (max 60 chars).');

    // Rate-limit: max 3 group rooms per player
    $cntStmt = $db->prepare(
        "SELECT COUNT(*) FROM falcon.chat_rooms
         WHERE created_by = ? AND room_type = 'group' AND is_active = TRUE"
    );
    $cntStmt->execute([$uid]);
    $existing = (int)$cntStmt->fetchColumn();

    if (!$isAdmin && $existing >= 3) {
        jsonErr('You can only create up to 3 group rooms.');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO falcon.chat_rooms
                (name, description, room_type, created_by, max_members, is_active)
             VALUES (?, ?, 'group', ?, ?, TRUE)
             RETURNING id"
        );
        try {
            $stmt->execute([$name, $description, $uid, $maxMembers]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' && stripos($e->getMessage(), 'chat_rooms_pkey') !== false) {
                resyncSerialSequence($db, 'falcon.chat_rooms', 'id');
                $stmt->execute([$name, $description, $uid, $maxMembers]);
            } else {
                throw $e;
            }
        }
        $roomId = (int)$stmt->fetchColumn();

        // Creator auto-joins as owner
        $db->prepare(
            "INSERT INTO falcon.chat_room_members (room_id, user_id, role, joined_at)
             VALUES (?, ?, 'owner', NOW())"
        )->execute([$roomId, $uid]);

        $db->commit();

        jsonOut([
            'ok'      => true,
            'room_id' => $roomId,
            'message' => 'Room created successfully.',
        ]);
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('[chat_rooms.php] Create error: ' . $e->getMessage());
        jsonErr('Could not create the room. Please try again.');
    }
}

// ============================================================
//  ACTION: join
// ============================================================
if ($action === 'join') {
    $roomId = (int)($body['room_id'] ?? $_GET['room_id'] ?? 0);
    if (!$roomId) jsonErr('room_id required.');

    $room = getRoomOrFail($db, $roomId);

    if ($room['room_type'] === 'community') {
        // Open to all — no capacity check
    } elseif ($room['room_type'] === 'group') {
        $count = memberCount($db, $roomId);
        if ($count >= (int)$room['max_members']) {
            jsonErr('This room is full (' . $room['max_members'] . '/' . $room['max_members'] . ' players).', 409);
        }
    } else {
        jsonErr('Cannot join this room type directly.');
    }

    if (isMember($db, $roomId, $uid)) {
        jsonOut(['ok' => true, 'message' => 'Already a member.']);
    }

    $db->prepare(
        "INSERT INTO falcon.chat_room_members (room_id, user_id, role, joined_at)
         VALUES (?, ?, 'member', NOW())
         ON CONFLICT (room_id, user_id) DO UPDATE SET left_at = NULL, joined_at = NOW()"
    )->execute([$roomId, $uid]);

    $uname = $_SESSION['username'] ?? 'Player';
    $stmt = $db->prepare(
        "INSERT INTO falcon.chat_room_messages
            (room_id, sender_id, message, msg_type, is_read, is_deleted)
         VALUES (?, 0, ?, 'system', FALSE, FALSE)"
    );
    try {
        $stmt->execute([$roomId, "👋 {$uname} joined the room."]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' && stripos($e->getMessage(), 'chat_room_messages_pkey') !== false) {
            resyncSerialSequence($db, 'falcon.chat_room_messages', 'id');
            $stmt->execute([$roomId, "👋 {$uname} joined the room."]);
        } else {
            throw $e;
        }
    }

    $db->prepare(
        "UPDATE falcon.chat_rooms SET updated_at = NOW() WHERE id = ?"
    )->execute([$roomId]);

    $newCount = memberCount($db, $roomId);

    // Auto-lock when group hits capacity
    if ($room['room_type'] === 'group' && $newCount >= (int)$room['max_members']) {
        $db->prepare(
            "UPDATE falcon.chat_rooms SET is_locked = TRUE, updated_at = NOW() WHERE id = ?"
        )->execute([$roomId]);
    }

    jsonOut(['ok' => true, 'message' => 'Joined room.', 'member_count' => $newCount]);
}

// ============================================================
//  ACTION: leave
// ============================================================
if ($action === 'leave') {
    $roomId = (int)($body['room_id'] ?? $_GET['room_id'] ?? 0);
    if (!$roomId) jsonErr('room_id required.');

    $room = getRoomOrFail($db, $roomId);

    if ($room['room_type'] === 'community') {
        jsonErr('You cannot leave the community room.');
    }

    if (!isMember($db, $roomId, $uid)) {
        jsonOut(['ok' => true, 'message' => 'Not a member.']);
    }

    $db->prepare(
        "UPDATE falcon.chat_room_members SET left_at = NOW()
         WHERE room_id = ? AND user_id = ?"
    )->execute([$roomId, $uid]);

    $uname = $_SESSION['username'] ?? 'Player';
    $stmt = $db->prepare(
        "INSERT INTO falcon.chat_room_messages
            (room_id, sender_id, message, msg_type, is_read, is_deleted)
         VALUES (?, 0, ?, 'system', FALSE, FALSE)"
    );
    try {
        $stmt->execute([$roomId, "👋 {$uname} left the room."]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' && stripos($e->getMessage(), 'chat_room_messages_pkey') !== false) {
            resyncSerialSequence($db, 'falcon.chat_room_messages', 'id');
            $stmt->execute([$roomId, "👋 {$uname} left the room."]);
        } else {
            throw $e;
        }
    }

    // Unlock if auto-locked (a spot is now free)
    $db->prepare(
        "UPDATE falcon.chat_rooms SET is_locked = FALSE, updated_at = NOW()
         WHERE id = ? AND is_locked = TRUE"
    )->execute([$roomId]);

    jsonOut(['ok' => true, 'message' => 'Left room.']);
}

// ============================================================
//  ACTION: send
// ============================================================
if ($action === 'send') {
    $roomId  = (int)($body['room_id'] ?? 0);
    $text    = trim($body['message'] ?? '');
    $msgType = $body['msg_type'] ?? 'text';
    $meta    = $body['meta'] ?? null;

    if (!$roomId) jsonErr('room_id required.');
    if ($text === '') jsonErr('Empty message.');
    if (mb_strlen($text) > 2000) jsonErr('Message too long (max 2000 chars).');

    $room = getRoomOrFail($db, $roomId);

    if (!isMember($db, $roomId, $uid) && !$isAdmin) {
        jsonErr('You are not a member of this room.', 403);
    }

    if (function_exists('checkRateLimit') && !checkRateLimit('chatroom_send_' . $uid, 30, 60)) {
        jsonErr('Slow down — too many messages!', 429);
    }

    $metaJson = $meta ? json_encode($meta) : null;

    $stmt = $db->prepare(
        "INSERT INTO falcon.chat_room_messages
            (room_id, sender_id, message, msg_type, meta, is_read, is_deleted)
         VALUES (?, ?, ?, ?, ?, FALSE, FALSE)
         RETURNING id, created_at"
    );
    try {
        $stmt->execute([$roomId, $uid, $text, $msgType, $metaJson]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' && stripos($e->getMessage(), 'chat_room_messages_pkey') !== false) {
            resyncSerialSequence($db, 'falcon.chat_room_messages', 'id');
            $stmt->execute([$roomId, $uid, $text, $msgType, $metaJson]);
        } else {
            throw $e;
        }
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $db->prepare(
        "UPDATE falcon.chat_rooms SET updated_at = NOW() WHERE id = ?"
    )->execute([$roomId]);

    jsonOut([
        'ok'      => true,
        'message' => [
            'id'          => (int)$row['id'],
            'text'        => $text,
            'msg_type'    => $msgType,
            'mine'        => true,
            'sender_id'   => $uid,
            'sender'      => $_SESSION['username'] ?? '',
            'sender_role' => $userRole,
            'is_admin'    => $isAdmin,
            'date'        => date('Y-m-d',  strtotime($row['created_at'])),
            'date_label'  => date('F j, Y', strtotime($row['created_at'])),
            'time'        => date('h:i A',  strtotime($row['created_at'])),
            'timestamp'   => strtotime($row['created_at']),
            'meta'        => $meta,
            'is_pinned'   => false,
        ],
    ]);
}

// ============================================================
//  ACTION: fetch
// ============================================================
if ($action === 'fetch') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $lastId = (int)($_GET['last_id'] ?? 0);

    if (!$roomId) jsonErr('room_id required.');

    $room = getRoomOrFail($db, $roomId);

    if ($room['room_type'] === 'community') {
        autoJoinCommunity($db, $uid);
    } elseif (!isMember($db, $roomId, $uid) && !$isAdmin) {
        jsonErr('You are not a member of this room.', 403);
    }

    // Mark all unread messages as read
    $db->prepare(
        "UPDATE falcon.chat_room_messages
         SET is_read = TRUE
         WHERE room_id = ? AND sender_id != ? AND is_read = FALSE"
    )->execute([$roomId, $uid]);

    $stmt = $db->prepare(
        "SELECT m.id, m.message, m.sender_id, m.msg_type, m.meta,
                m.is_read, m.is_deleted, m.is_pinned, m.created_at,
                u.username AS sender_name, u.role AS sender_role,
                p.username AS pinned_by_name
         FROM falcon.chat_room_messages m
         LEFT JOIN falcon.users u ON u.id = m.sender_id
         LEFT JOIN falcon.users p ON p.id = m.pinned_by
         WHERE m.room_id = ?
           AND m.id > ?
           AND (m.is_deleted = FALSE OR m.is_deleted IS NULL)
         ORDER BY m.created_at ASC
         LIMIT 100"
    );
    $stmt->execute([$roomId, $lastId]);

    $memberCnt = memberCount($db, $roomId);

    jsonOut([
        'ok'       => true,
        'messages' => formatRoomMessages($stmt->fetchAll(PDO::FETCH_ASSOC), $uid),
        'room'     => [
            'id'                  => (int)$room['id'],
            'name'                => $room['name'],
            'room_type'           => $room['room_type'],
            'max_members'         => (int)$room['max_members'],
            'member_count'        => $memberCnt,
            'is_locked'           => (bool)($room['is_locked'] ?? false),
            'pinned_announcement' => $room['pinned_announcement'] ?: null,
        ],
    ]);
}

// ============================================================
//  ACTION: poll
// ============================================================
if ($action === 'poll') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $lastId = (int)($_GET['last_id'] ?? 0);

    if (!$roomId) jsonOut(['ok' => true, 'messages' => [], 'unread' => 0]);

    $room = getRoomOrFail($db, $roomId);

    if ($room['room_type'] !== 'community' && !isMember($db, $roomId, $uid) && !$isAdmin) {
        jsonOut(['ok' => true, 'messages' => [], 'unread' => 0]);
    }

    $stmt = $db->prepare(
        "SELECT m.id, m.message, m.sender_id, m.msg_type, m.meta,
                m.is_read, m.is_deleted, m.is_pinned, m.created_at,
                u.username AS sender_name, u.role AS sender_role,
                p.username AS pinned_by_name
         FROM falcon.chat_room_messages m
         LEFT JOIN falcon.users u ON u.id = m.sender_id
         LEFT JOIN falcon.users p ON p.id = m.pinned_by
         WHERE m.room_id = ?
           AND m.id > ?
           AND (m.is_deleted = FALSE OR m.is_deleted IS NULL)
         ORDER BY m.created_at ASC
         LIMIT 50"
    );
    $stmt->execute([$roomId, $lastId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        $db->prepare(
            "UPDATE falcon.chat_room_messages SET is_read = TRUE
             WHERE room_id = ? AND sender_id != ? AND is_read = FALSE"
        )->execute([$roomId, $uid]);
    }

    // Total unread across ALL rooms this user is in
    $unreadStmt = $db->prepare(
        "SELECT COUNT(*) FROM falcon.chat_room_messages m
         JOIN falcon.chat_room_members mb
              ON mb.room_id = m.room_id AND mb.user_id = ? AND mb.left_at IS NULL
         WHERE m.sender_id != ?
           AND m.is_read = FALSE
           AND (m.is_deleted = FALSE OR m.is_deleted IS NULL)"
    );
    $unreadStmt->execute([$uid, $uid]);
    $totalUnread = (int)$unreadStmt->fetchColumn();

    jsonOut([
        'ok'       => true,
        'messages' => formatRoomMessages($rows, $uid),
        'unread'   => $totalUnread,
    ]);
}

// ============================================================
//  ACTION: members
// ============================================================
if ($action === 'members') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    if (!$roomId) jsonErr('room_id required.');
    getRoomOrFail($db, $roomId);

    $stmt = $db->prepare(
        "SELECT m.user_id, m.role, m.joined_at,
                u.username, u.full_name, u.avatar_path
         FROM falcon.chat_room_members m
         JOIN falcon.users u ON u.id = m.user_id
         WHERE m.room_id = ? AND m.left_at IS NULL
         ORDER BY
             CASE m.role WHEN 'owner' THEN 0 WHEN 'moderator' THEN 1 ELSE 2 END,
             u.username ASC"
    );
    $stmt->execute([$roomId]);

    $members = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $members[] = [
            'user_id'   => (int)$r['user_id'],
            'username'  => $r['username'],
            'full_name' => $r['full_name'],
            'role'      => $r['role'],
            'joined_at' => $r['joined_at'],
            'is_me'     => (int)$r['user_id'] === $uid,
        ];
    }

    jsonOut(['ok' => true, 'members' => $members]);
}

// ============================================================
//  ACTION: kick
// ============================================================
if ($action === 'kick') {
    $roomId   = (int)($body['room_id'] ?? 0);
    $targetId = (int)($body['user_id'] ?? 0);
    if (!$roomId || !$targetId) jsonErr('room_id and user_id required.');

    getRoomOrFail($db, $roomId);

    $myRoleStmt = $db->prepare(
        "SELECT role FROM falcon.chat_room_members
         WHERE room_id = ? AND user_id = ? AND left_at IS NULL LIMIT 1"
    );
    $myRoleStmt->execute([$roomId, $uid]);
    $myRoomRole = $myRoleStmt->fetchColumn();

    if (!$isAdmin && $myRoomRole !== 'owner') {
        jsonErr('Only the room owner or an admin can kick members.', 403);
    }
    if ($targetId === $uid) jsonErr('You cannot kick yourself.');

    $db->prepare(
        "UPDATE falcon.chat_room_members SET left_at = NOW()
         WHERE room_id = ? AND user_id = ?"
    )->execute([$roomId, $targetId]);

    $tName = $db->prepare("SELECT username FROM falcon.users WHERE id = ? LIMIT 1");
    $tName->execute([$targetId]);
    $tUsername = $tName->fetchColumn() ?: 'Player';

    $stmt = $db->prepare(
        "INSERT INTO falcon.chat_room_messages
            (room_id, sender_id, message, msg_type, is_read, is_deleted)
         VALUES (?, 0, ?, 'system', FALSE, FALSE)"
    );
    try {
        $stmt->execute([$roomId, "🚫 {$tUsername} was removed from the room."]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' && stripos($e->getMessage(), 'chat_room_messages_pkey') !== false) {
            resyncSerialSequence($db, 'falcon.chat_room_messages', 'id');
            $stmt->execute([$roomId, "🚫 {$tUsername} was removed from the room."]);
        } else {
            throw $e;
        }
    }

    // Unlock the room so someone new can join
    $db->prepare(
        "UPDATE falcon.chat_rooms SET is_locked = FALSE, updated_at = NOW()
         WHERE id = ? AND is_locked = TRUE"
    )->execute([$roomId]);

    jsonOut(['ok' => true, 'message' => "{$tUsername} has been removed."]);
}

// ============================================================
//  ACTION: pin_announce  (admin only)
// ============================================================
if ($action === 'pin_announce') {
    if (!$isAdmin) jsonErr('Admin only.', 403);

    $roomId = (int)($body['room_id'] ?? 0);
    $text   = trim($body['announcement'] ?? '');

    if (!$roomId) jsonErr('room_id required.');
    getRoomOrFail($db, $roomId);

    $db->prepare(
        "UPDATE falcon.chat_rooms
         SET pinned_announcement = ?, updated_at = NOW()
         WHERE id = ?"
    )->execute([$text ?: null, $roomId]);

    jsonOut([
        'ok'      => true,
        'message' => $text ? 'Announcement pinned.' : 'Announcement cleared.',
    ]);
}

// ============================================================
//  ACTION: game_invite
// ============================================================
if ($action === 'game_invite') {
    $roomId    = (int)($body['room_id'] ?? 0);
    $courtName = trim($body['court_name'] ?? 'Padol Court');
    $slotTime  = trim($body['slot_time'] ?? '');
    $slotDate  = trim($body['slot_date'] ?? date('Y-m-d'));
    $spotsLeft = max(1, (int)($body['spots_left'] ?? 1));
    $note      = trim($body['note'] ?? '');

    if (!$roomId)   jsonErr('room_id required.');
    if (!$slotTime) jsonErr('slot_time required.');

    getRoomOrFail($db, $roomId);
    if (!isMember($db, $roomId, $uid) && !$isAdmin) {
        jsonErr('You are not a member of this room.', 403);
    }

    $uname = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Player';
    $text  = "🎾 Game Invite from {$uname}\n📅 {$slotDate} at {$slotTime}\n🏓 {$courtName}\n👥 {$spotsLeft} spot(s) available"
           . ($note ? "\n📝 {$note}" : '');
    $meta  = [
        'type'       => 'game_invite',
        'inviter_id' => $uid,
        'inviter'    => $uname,
        'court'      => $courtName,
        'date'       => $slotDate,
        'time'       => $slotTime,
        'spots'      => $spotsLeft,
        'note'       => $note,
    ];

    $stmt = $db->prepare(
        "INSERT INTO falcon.chat_room_messages
            (room_id, sender_id, message, msg_type, meta, is_read, is_deleted)
         VALUES (?, ?, ?, 'game_invite', ?, FALSE, FALSE)
         RETURNING id, created_at"
    );
    try {
        $stmt->execute([$roomId, $uid, $text, json_encode($meta)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' && stripos($e->getMessage(), 'chat_room_messages_pkey') !== false) {
            resyncSerialSequence($db, 'falcon.chat_room_messages', 'id');
            $stmt->execute([$roomId, $uid, $text, json_encode($meta)]);
        } else {
            throw $e;
        }
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $db->prepare(
        "UPDATE falcon.chat_rooms SET updated_at = NOW() WHERE id = ?"
    )->execute([$roomId]);

    jsonOut([
        'ok'      => true,
        'message' => [
            'id'        => (int)$row['id'],
            'text'      => $text,
            'msg_type'  => 'game_invite',
            'mine'      => true,
            'sender_id' => $uid,
            'sender'    => $_SESSION['username'] ?? '',
            'time'      => date('h:i A', strtotime($row['created_at'])),
            'timestamp' => strtotime($row['created_at']),
            'meta'      => $meta,
        ],
    ]);
}

// ============================================================
//  ACTION: delete_room
// ============================================================
if ($action === 'delete_room') {
    $roomId = (int)($body['room_id'] ?? 0);
    if (!$roomId) jsonErr('room_id required.');

    $room = getRoomOrFail($db, $roomId);

    if ($room['room_type'] === 'community') {
        jsonErr('The community room cannot be deleted.');
    }

    $myRoleStmt = $db->prepare(
        "SELECT role FROM falcon.chat_room_members
         WHERE room_id = ? AND user_id = ? AND left_at IS NULL LIMIT 1"
    );
    $myRoleStmt->execute([$roomId, $uid]);
    $myRoomRole = $myRoleStmt->fetchColumn();

    if (!$isAdmin && $myRoomRole !== 'owner') {
        jsonErr('Only the room owner or an admin can delete this room.', 403);
    }

    $db->prepare(
        "UPDATE falcon.chat_rooms SET is_active = FALSE, updated_at = NOW() WHERE id = ?"
    )->execute([$roomId]);

    jsonOut(['ok' => true, 'message' => 'Room deleted.']);
}

// ============================================================
//  ACTION: unread
// ============================================================
if ($action === 'unread') {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM falcon.chat_room_messages m
         JOIN falcon.chat_room_members mb
              ON mb.room_id = m.room_id AND mb.user_id = ? AND mb.left_at IS NULL
         WHERE m.sender_id != ?
           AND m.is_read = FALSE
           AND (m.is_deleted = FALSE OR m.is_deleted IS NULL)"
    );
    $stmt->execute([$uid, $uid]);
    jsonOut(['ok' => true, 'count' => (int)$stmt->fetchColumn()]);
}

// ============================================================
//  ACTION: browse
//  All active group rooms (with membership status)
// ============================================================
if ($action === 'browse') {
    $stmt = $db->prepare(
        "SELECT r.id, r.name, r.description, r.room_type,
                r.max_members, r.is_locked, r.created_at,
                (SELECT COUNT(*) FROM falcon.chat_room_members x
                 WHERE x.room_id = r.id AND x.left_at IS NULL) AS member_count,
                CASE WHEN m.user_id IS NOT NULL THEN TRUE ELSE FALSE END AS is_member,
                u.username AS creator
         FROM falcon.chat_rooms r
         LEFT JOIN falcon.chat_room_members m
                ON m.room_id = r.id AND m.user_id = ? AND m.left_at IS NULL
         LEFT JOIN falcon.users u ON u.id = r.created_by
         WHERE r.is_active = TRUE AND r.room_type = 'group'
         ORDER BY member_count DESC, r.created_at DESC
         LIMIT 50"
    );
    $stmt->execute([$uid]);

    $rooms = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rooms[] = [
            'id'           => (int)$r['id'],
            'name'         => $r['name'],
            'description'  => $r['description'],
            'member_count' => (int)$r['member_count'],
            'max_members'  => (int)$r['max_members'],
            'is_full'      => (int)$r['member_count'] >= (int)$r['max_members'],
            'is_locked'    => (bool)$r['is_locked'],
            'is_member'    => (bool)$r['is_member'],
            'creator'      => $r['creator'],
        ];
    }

    jsonOut(['ok' => true, 'rooms' => $rooms]);
}

jsonErr('Unknown action');