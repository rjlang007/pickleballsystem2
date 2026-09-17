<?php
// ============================================================
//  FILE: includes/api_response.php
//  Standard JSON API error and success helpers
// ============================================================

function apiJsonHeaders(): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }
}

function apiError(string $code, string $message, array $details = [], int $httpStatus = 400): never {
    http_response_code($httpStatus);
    apiJsonHeaders();

    echo json_encode([
        'ok' => false,
        'error'   => [
            'code'    => $code,
            'message' => $message,
            'details' => (object)$details,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiSuccess(array $payload = [], int $status = 200): never {
    http_response_code($status);
    apiJsonHeaders();
    echo json_encode(array_merge(['ok' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}
