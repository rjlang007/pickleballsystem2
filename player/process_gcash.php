<?php
// ============================================================
//  FILE: player/process_gcash.php
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('player/topup.php');

// CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request. Please try again.');
    redirect('player/topup.php');
}

$db     = getDB();
$uid    = $_SESSION['user_id'];
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$ref    = trim($_POST['gcash_ref_no'] ?? '');
$method = trim($_POST['method'] ?? 'gcash');

// Validate amount
if (!$amount || $amount < 10) {
    setFlash('error', 'Amount must be at least ₱10.');
    redirect('player/topup.php');
}

// Validate reference number
if (empty($ref) || !preg_match('/^[0-9A-Za-z\-]{6,30}$/', $ref)) {
    setFlash('error', 'Invalid reference number.');
    redirect('player/topup.php');
}

// Check duplicate reference
$dupCheck = $db->prepare("
    SELECT id FROM falcon.topup_requests
    WHERE gcash_ref_no = ? AND status != 'rejected'
");
$dupCheck->execute([$ref]);
if ($dupCheck->fetch()) {
    setFlash('error', 'This reference number has already been submitted.');
    redirect('player/topup.php');
}

// ── Handle screenshot — store as base64 data URI in DB ────────
// No filesystem writes at all (Railway has no persistent disk).
$screenshotData = null;

if (isset($_FILES['screenshot'])) {
    $file      = $_FILES['screenshot'];
    $uploadErr = $file['error'];

    if ($uploadErr !== UPLOAD_ERR_OK && $uploadErr !== UPLOAD_ERR_NO_FILE) {
        $phpErrMap = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: no temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write temp file.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        setFlash('error', $phpErrMap[$uploadErr] ?? 'Screenshot upload failed.');
        redirect('player/topup.php');
    }

    if ($uploadErr === UPLOAD_ERR_OK) {
        if ($file['size'] > 5 * 1024 * 1024) {
            setFlash('error', 'Screenshot too large. Max 5MB.');
            redirect('player/topup.php');
        }

        $mime = mime_content_type($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            setFlash('error', 'Invalid file type. JPG, PNG, or WEBP only.');
            redirect('player/topup.php');
        }

        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) {
            setFlash('error', 'Could not read uploaded file. Please try again.');
            redirect('player/topup.php');
        }

        // Store as data URI — same pattern used for QR images
        $screenshotData = 'data:' . $mime . ';base64,' . base64_encode($raw);
    }
}

// ── Insert DB record ──────────────────────────────────────────
try {
    $db->prepare("
        INSERT INTO falcon.topup_requests
            (user_id, amount, method, gcash_ref_no, screenshot_path, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ")->execute([$uid, $amount, $method, $ref, $screenshotData]);

    setFlash('success', '✅ Top-up request submitted! The owner will approve it shortly.');

} catch (PDOException $e) {
    error_log('process_gcash error: ' . $e->getMessage());
    setFlash('error', 'DB Error: ' . $e->getMessage()); // ← temporary
}

redirect('player/topup.php');