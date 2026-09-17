<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/security.php';
requireAdmin();

$hash = $_GET['h'] ?? '';

if (!preg_match('/^proof_[a-f0-9]{16}\.(jpg|png|gif|webp)$/', $hash)) {
    http_response_code(400); exit;
}

$ext  = pathinfo($hash, PATHINFO_EXTENSION);
$mime = match($ext) {
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    default => 'image/jpeg',
};

$file = sys_get_temp_dir() . '/' . $hash;

if (!file_exists($file)) {
    http_response_code(404); exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($file));
readfile($file);