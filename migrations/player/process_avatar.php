<?php
// ============================================================
//  FILE: player/process_avatar.php
//  Handles profile picture upload — POST only, JSON response
//  Called via fetch() from player/profile.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

verifyCsrf();

$userId = (int)$_SESSION['user_id'];

// ── Validate file was uploaded ────────────────────────────
if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];
    $code = $_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['ok' => false, 'error' => $uploadErrors[$code] ?? 'Upload failed.']);
    exit;
}

$file     = $_FILES['avatar'];
$tmpPath  = $file['tmp_name'];
$origName = $file['name'];
$fileSize = $file['size'];

// ── Size check: max 3MB ───────────────────────────────────
if ($fileSize > 3 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'Image must be under 3MB.']);
    exit;
}

// ── Validate MIME type via getimagesize (not just extension) ──
$imageInfo = @getimagesize($tmpPath);
if (!$imageInfo) {
    echo json_encode(['ok' => false, 'error' => 'File is not a valid image.']);
    exit;
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$mimeType     = $imageInfo['mime'];
if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, WebP, or GIF allowed.']);
    exit;
}

$extensions = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
$ext = $extensions[$mimeType];

// ── Build destination path ────────────────────────────────
$uploadDir = defined('UPLOAD_AVATARS') ? UPLOAD_AVATARS : (APP_ROOT . '/uploads/avatars/');
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Unique filename: avatar_<userId>_<hash>.<ext>
// Old avatar gets deleted automatically since we use the same user-scoped name
$newFilename = "avatar_{$userId}_" . bin2hex(random_bytes(6)) . ".{$ext}";
$destPath    = $uploadDir . $newFilename;

// ── Resize to max 400×400 using GD ───────────────────────
$resized = resizeAvatar($tmpPath, $mimeType, $destPath, 400);

if (!$resized) {
    // GD not available or resize failed — fall back to straight copy
    if (!move_uploaded_file($tmpPath, $destPath)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save image. Please try again.']);
        exit;
    }
}

// ── Delete old avatar file ───────────────────────────────
$db = getDB();
$old = $db->prepare("SELECT avatar_path FROM falcon.users WHERE id = ?");
$old->execute([$userId]);
$oldPath = $old->fetchColumn();
if ($oldPath) {
    $oldFull = $uploadDir . basename($oldPath);
    if (file_exists($oldFull) && $oldFull !== $destPath) {
        @unlink($oldFull);
    }
}

// ── Update DB ────────────────────────────────────────────
$relPath = 'uploads/avatars/' . $newFilename;   // relative to APP_ROOT, for URLs

$db->prepare("
    UPDATE falcon.users
    SET avatar_path = ?, updated_at = NOW()
    WHERE id = ?
")->execute([$relPath, $userId]);

// Update session so header nav reflects new avatar immediately
$_SESSION['avatar'] = $relPath;

echo json_encode([
    'ok'         => true,
    'avatar_url' => APP_URL . '/' . $relPath,
    'message'    => 'Profile picture updated!',
]);

// ── GD resize helper ─────────────────────────────────────
function resizeAvatar(string $src, string $mime, string $dest, int $maxPx): bool {
    if (!extension_loaded('gd')) return false;

    $img = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => @imagecreatefromwebp($src),
        'image/gif'  => @imagecreatefromgif($src),
        default      => false,
    };
    if (!$img) return false;

    $w = imagesx($img);
    $h = imagesy($img);

    // Already small enough — just copy
    if ($w <= $maxPx && $h <= $maxPx) {
        imagedestroy($img);
        return false;  // caller will move_uploaded_file instead
    }

    // Scale to fit within maxPx × maxPx, preserving aspect
    $ratio  = min($maxPx / $w, $maxPx / $h);
    $newW   = (int)round($w * $ratio);
    $newH   = (int)round($h * $ratio);

    $canvas = imagecreatetruecolor($newW, $newH);

    // Preserve transparency for PNG/WebP/GIF
    if (in_array($mime, ['image/png', 'image/webp', 'image/gif'])) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
    }

    imagecopyresampled($canvas, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);

    $saved = match($mime) {
        'image/jpeg' => imagejpeg($canvas, $dest, 88),
        'image/png'  => imagepng($canvas, $dest, 8),
        'image/webp' => imagewebp($canvas, $dest, 88),
        'image/gif'  => imagegif($canvas, $dest),
        default      => false,
    };

    imagedestroy($img);
    imagedestroy($canvas);

    return (bool)$saved;
}