<?php
// Custom error handler — NEVER expose stack traces to users
function customErrorHandler(int $errno, string $errstr, string $errfile, int $errline): bool {
    $logPath = APP_ROOT . '/storage/logs/php_errors.log';
    $logMsg  = sprintf(
        "[%s] ERROR %d: %s in %s on line %d\n",
        date('Y-m-d H:i:s'), $errno,
        $errstr,
        basename($errfile),  // only basename, not full path
        $errline
    );
    error_log($logMsg, 3, $logPath);
    return true; // suppress default PHP error handler
}

function customExceptionHandler(Throwable $e): void {
    $logPath = APP_ROOT . '/storage/logs/php_errors.log';
    $logMsg  = sprintf(
        "[%s] EXCEPTION: %s in %s:%d\nTrace: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        basename($e->getFile()),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($logMsg, 3, $logPath);

    // Generic response — never expose exception details
    if (http_response_code() < 400) http_response_code(500);
    if (defined('APP_ROOT') && strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'An internal error occurred.']);
    } else {
        echo '<h2>Something went wrong.</h2><p>Please try again later.</p>';
    }
}

set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');