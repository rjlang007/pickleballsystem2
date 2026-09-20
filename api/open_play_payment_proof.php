<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';

requireStaff();

$requestId = (int)($_GET['id'] ?? 0);
if ($requestId <= 0) {
    http_response_code(400);
    exit('Payment proof not found.');
}

$stmt = getDB()->prepare(
    "SELECT proof_path FROM falcon.open_play_payment_requests WHERE id = ? LIMIT 1"
);
$stmt->execute([$requestId]);
$relativePath = $stmt->fetchColumn();
$uploadRoot = realpath(__DIR__ . '/../Uploads');
$filePath = $relativePath ? realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\')) : false;

if (!$uploadRoot || !$filePath || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Unsupported payment proof.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="open-play-payment-proof"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);