<?php
// ============================================================
//  FILE: api/_api_helpers.php
//  Shared utilities for all API endpoints.
//  Requires: config/app.php (already loaded by callers)
// ============================================================
if (defined('API_HELPERS_LOADED')) return;
define('API_HELPERS_LOADED', true);

// ── Response helpers ──────────────────────────────────────────

/**
 * Send a JSON success response and exit.
 *
 * @param  mixed       $data    Payload (array, object, or null)
 * @param  string      $message Optional human-readable message
 * @param  int         $code    HTTP status (default 200)
 */
if (!function_exists('apiSuccess')) {
    function apiSuccess(mixed $data, string $message = 'OK', int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Send a JSON error response and exit.
 *
 * @param  string  $message Human-readable error
 * @param  int     $code    HTTP status (default 400)
 * @param  array   $errors  Optional validation error map
 */
if (!function_exists('apiError')) {
    function apiError(string $message, int $code = 400, array $errors = []): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $body = ['success' => false, 'message' => $message];
        if (!empty($errors)) $body['errors'] = $errors;
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ── Auth guards ───────────────────────────────────────────────

/**
 * Assert the request is from a logged-in admin/super_admin.
 * Returns the session array so callers can read user_id etc.
 *
 * @throws never  — calls apiError() and exits on failure
 */
if (!function_exists('requireAdmin')) {
    function requireAdmin(): array
    {
        if (!isLoggedIn()) {
            apiError('Authentication required.', 401);
        }
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, ADMIN_ROLES, true)) {
            apiError('Admin access required.', 403);
        }
        return $_SESSION;
    }
}

/**
 * Assert the request is from any logged-in user.
 * Returns the session array.
 */
if (!function_exists('requireAuth')) {
    function requireAuth(): array
    {
        if (!isLoggedIn()) {
            apiError('Authentication required.', 401);
        }
        return $_SESSION;
    }
}

// ── Request parsing ───────────────────────────────────────────

/**
 * Parse the request body as JSON (for PUT/PATCH/POST with JSON body)
 * or fall back to $_POST for form submissions.
 */
if (!function_exists('getRequestBody')) {
    function getRequestBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // JSON body
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false || $raw === '') return [];
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                apiError('Invalid JSON: ' . json_last_error_msg(), 400);
            }
            return is_array($decoded) ? $decoded : [];
        }

        // Form data (POST / PUT with form encoding)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $_POST;
        }

        // PUT/PATCH with form encoding
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return [];
        parse_str($raw, $data);
        return $data;
    }
}

// ── Method guard ──────────────────────────────────────────────

/**
 * Restrict the endpoint to one or more HTTP methods.
 * Exits with 405 if the method doesn't match.
 *
 * Usage:
 *   routeMethod('GET');
 *   routeMethod(['GET', 'POST']);
 */
if (!function_exists('routeMethod')) {
    function routeMethod(string|array $allowed): void
    {
        $allowed = (array) $allowed;
        $allowed = array_map('strtoupper', $allowed);
        $method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            apiError(
                'Method not allowed. Allowed: ' . implode(', ', $allowed),
                405
            );
        }
    }
}

// ── Pagination helper ─────────────────────────────────────────

/**
 * Extract and validate ?limit and ?offset query params.
 * Returns [limit, offset].
 */
function getPagination(int $defaultLimit = 50, int $maxLimit = 200): array
{
    $limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : $defaultLimit;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

    $limit  = min(max(1, $limit),  $maxLimit);
    $offset = max(0, $offset);

    return [$limit, $offset];
}

// ── CORS (dev helper — only emitted in non-production) ────────
if (defined('IS_PRODUCTION') && !IS_PRODUCTION) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}