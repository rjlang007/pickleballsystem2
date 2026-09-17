<?php
// ============================================================
//  FILE: api/community.php
//
//  GET  ?post_id=X                   → single post with comments
//  GET  (list)                        → paginated feed
//  POST (body)                        → create post
//  POST ?action=comment (body)        → add comment
//  PATCH body {id, is_pinned?, is_hidden?} → admin: pin or hide post
//  DELETE ?id=X                       → delete post (owner or admin)
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$rlKey = 'api_community_' . getClientIp();
if (!checkRateLimit($rlKey, 60, 60)) {
    header('Retry-After: 60');
    apiError('RATE_LIMITED', 'Too many requests. Please wait.', [], 429);
}

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
verifySameOrigin();

// ── Helper: fetch a single post (respects visibility for non-admins) ──
function fetchPost(PDO $db, int $postId, bool $forAdmin = false): ?array {
    $sql = "SELECT p.*, u.username AS author_username, u.full_name AS author_name,
                   COALESCE(c.comment_count, 0) AS comment_count
              FROM falcon.community_posts p
              JOIN falcon.users u ON u.id = p.user_id
         LEFT JOIN (
                   SELECT post_id, COUNT(*) AS comment_count
                     FROM falcon.post_comments
                    GROUP BY post_id
                 ) c ON c.post_id = p.id
             WHERE p.id = ?";
    if (!$forAdmin) {
        $sql .= " AND p.is_hidden = FALSE";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$postId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ─────────────────────────────────────────────────────────────
//  GET
// ─────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // GET ?post_id=X  — single post + comments
    if (isset($_GET['post_id'])) {
        $postId = (int)$_GET['post_id'];
        if ($postId <= 0) apiError('VALIDATION_ERROR', 'Invalid post_id.');

        $post = fetchPost($db, $postId, isAdmin());
        if (!$post) apiError('NOT_FOUND', 'Post not found.', [], 404);

        $commentsStmt = $db->prepare(
            "SELECT c.id, c.content, c.created_at,
                    u.username AS commenter_username, u.full_name AS commenter_name
               FROM falcon.post_comments c
               JOIN falcon.users u ON u.id = c.user_id
              WHERE c.post_id = ?
              ORDER BY c.created_at ASC"
        );
        $commentsStmt->execute([$postId]);
        $post['comments'] = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);
        apiSuccess(['data' => $post]);
    }

    // List feed (pinned first, then newest)
    $limit  = min(50, max(1, (int)($_GET['limit']  ?? 20)));
    $offset = max(0,          (int)($_GET['offset'] ?? 0));

    $stmt = $db->prepare(
        "SELECT p.id, p.user_id, u.username AS author_username, u.full_name AS author_name,
                p.content, p.media_url, p.is_pinned, p.is_hidden, p.created_at, p.updated_at,
                COALESCE(c.comment_count, 0) AS comment_count
           FROM falcon.community_posts p
           JOIN falcon.users u ON u.id = p.user_id
      LEFT JOIN (
                SELECT post_id, COUNT(*) AS comment_count
                  FROM falcon.post_comments
                 GROUP BY post_id
               ) c ON c.post_id = p.id
          WHERE p.is_hidden = FALSE
          ORDER BY p.is_pinned DESC, p.created_at DESC
          LIMIT ? OFFSET ?"
    );
    $stmt->execute([$limit, $offset]);
    apiSuccess(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─────────────────────────────────────────────────────────────
//  POST — create post or add comment
// ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!isLoggedIn()) apiError('UNAUTHORIZED', 'Not logged in.', [], 401);

    $uid  = (int)$_SESSION['user_id'];
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // POST ?action=comment
    if (isset($_GET['action']) && $_GET['action'] === 'comment') {
        $errors = validateComment($body);
        if (!empty($errors)) apiError('VALIDATION_ERROR', 'Validation failed.', $errors, 422);

        $postId = (int)$body['post_id'];
        $post   = fetchPost($db, $postId, isAdmin());
        if (!$post) apiError('NOT_FOUND', 'Post not found.', [], 404);

        $insert = $db->prepare(
            "INSERT INTO falcon.post_comments (post_id, user_id, content, created_at)
             VALUES (?, ?, ?, NOW()) RETURNING id"
        );
        $insert->execute([$postId, $uid, trim($body['content'])]);
        $commentId = (int)$insert->fetchColumn();
        apiSuccess(['data' => ['post_id' => $postId, 'comment_id' => $commentId]]);
    }

    // Create post
    $errors = validateCommunityPost($body);
    if (!empty($errors)) apiError('VALIDATION_ERROR', 'Validation failed.', $errors, 422);

    // Per-user rate limit: max 5 posts per hour
    $limitStmt = $db->prepare(
        "SELECT COUNT(*) FROM falcon.community_posts
          WHERE user_id   = ?
            AND created_at > NOW() - INTERVAL '1 hour'"
    );
    $limitStmt->execute([$uid]);
    if ((int)$limitStmt->fetchColumn() >= 5) {
        apiError('RATE_LIMITED', 'You may only create 5 posts per hour.', [], 429);
    }

    $insert = $db->prepare(
        "INSERT INTO falcon.community_posts
            (user_id, content, media_url, is_pinned, is_hidden, created_at, updated_at)
         VALUES (?, ?, ?, FALSE, FALSE, NOW(), NOW()) RETURNING id"
    );
    $insert->execute([$uid, trim($body['content']), $body['media_url'] ?: null]);
    $postId = (int)$insert->fetchColumn();
    apiSuccess(['data' => ['post_id' => $postId]]);
}

// ─────────────────────────────────────────────────────────────
//  PATCH — admin pin or hide a post
//
//  Body: { "id": 123, "is_pinned": true }   — pin/unpin
//        { "id": 123, "is_hidden": true }   — hide/unhide
//        Both can be combined in one call.
// ─────────────────────────────────────────────────────────────
if ($method === 'PATCH') {
    if (!isLoggedIn() || !isAdmin()) {
        apiError('FORBIDDEN', 'Admin access required.', [], 403);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = isset($body['id']) ? (int)$body['id'] : 0;
    if ($id <= 0) apiError('VALIDATION_ERROR', 'Post ID is required.', [], 422);

    // Require at least one field to update
    $hasPinned = array_key_exists('is_pinned', $body);
    $hasHidden = array_key_exists('is_hidden', $body);
    if (!$hasPinned && !$hasHidden) {
        apiError('VALIDATION_ERROR', 'Provide at least one of: is_pinned, is_hidden.', [], 422);
    }

    // Build update dynamically so only supplied fields are changed
    $sets   = [];
    $params = [];

    if ($hasPinned) {
        $sets[]   = 'is_pinned = ?';
        $params[] = (bool)$body['is_pinned'];
    }
    if ($hasHidden) {
        $sets[]   = 'is_hidden = ?';
        $params[] = (bool)$body['is_hidden'];
    }

    $sets[]   = 'updated_at = NOW()';
    $params[] = $id;

    $db->prepare(
        "UPDATE falcon.community_posts SET " . implode(', ', $sets) . " WHERE id = ?"
    )->execute($params);

    $updated = fetchPost($db, $id, true);
    if (!$updated) apiError('NOT_FOUND', 'Post not found.', [], 404);

    apiSuccess(['data' => $updated]);
}

// ─────────────────────────────────────────────────────────────
//  DELETE — owner or admin deletes a post
// ─────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!isLoggedIn()) apiError('UNAUTHORIZED', 'Not logged in.', [], 401);

    $uid = (int)$_SESSION['user_id'];
    $id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) apiError('VALIDATION_ERROR', 'Invalid post ID.');

    $stmt = $db->prepare(
        "SELECT user_id FROM falcon.community_posts WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) apiError('NOT_FOUND', 'Post not found.', [], 404);

    if ((int)$post['user_id'] !== $uid && !isAdmin()) {
        apiError('FORBIDDEN', 'Not allowed to delete this post.', [], 403);
    }

    $db->prepare("DELETE FROM falcon.community_posts WHERE id = ?")->execute([$id]);
    apiSuccess(['data' => ['deleted_id' => $id]]);
}

apiError('INVALID_REQUEST', 'Invalid request method.');