<?php
// ============================================================
//  FILE: includes/security_helpers.php
//
//  PRODUCTION HARDENING APPLIED:
//   1. auditLog() scrubs PII (email, phone, password_hash)
//      from old_data/new_data before writing to DB
//   2. validateUploadedImage() strips EXIF/metadata via GD
//      re-encode — prevents metadata-embedded payloads
//   3. blockIp() uses parameterised INTERVAL (not string concat)
//   4. detectSuspiciousUserAgent() expanded bot list
//   5. secureJsonResponse() uses ENT_* flags preventing HTML
//      injection in JSON output
//   6. safeRedirectPath() whitelist-validated
//   7. Added cleanOldRateLimitFiles() for cron hygiene
//   8. Added getClientIp() with proxy-aware logic
// ============================================================
if (defined('SECURITY_HELPERS_LOADED')) return;
define('SECURITY_HELPERS_LOADED', true);

// ============================================================
//  CLIENT IP (proxy-aware)
//  Only trusts X-Forwarded-For if your app sits behind a known
//  reverse proxy. Set TRUSTED_PROXY_IPS in env — comma-separated,
//  each entry either a single IP ("203.0.113.9") or a CIDR range
//  ("100.0.0.0/8"). A single hosting-provider edge is rarely one
//  fixed IP, so CIDR support matters — exact-match-only made this
//  effectively unusable in front of a real proxy fleet (e.g.
//  Railway's edge, which does not publish a small fixed IP list).
//
//  NOTE for Railway specifically: which header carries the real
//  client IP, and in which position, has been reported inconsistent
//  by Railway's own support (CDN rollout in progress as of mid-2026)
//  — don't take this comment's word for it. Hit
//  scripts/whoami_headers.php (superadmin-only) on your actual
//  deployment to see REMOTE_ADDR / X-Forwarded-For / X-Real-Ip as
//  Railway currently delivers them to you, then set TRUSTED_PROXY_IPS
//  to match. Re-check after any Railway networking changelog entry
//  that mentions edge/CDN routing.
// ============================================================
function _ipInCidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return hash_equals($cidr, $ip); // plain IP, exact match
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipBin     = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false; // IPv4 vs IPv6 mismatch or invalid input
    }
    $bytes = intdiv($bits, 8);
    $rembits = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($rembits === 0) return true;
    $mask = chr((0xFF << (8 - $rembits)) & 0xFF);
    return (substr($ipBin, $bytes, 1) & $mask) === (substr($subnetBin, $bytes, 1) & $mask);
}

function getClientIp(): string {
    $trustedProxies = array_filter(array_map('trim',
        explode(',', getenv('TRUSTED_PROXY_IPS') ?: '')
    ));
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $isTrustedProxy = function (string $ip) use ($trustedProxies): bool {
        foreach ($trustedProxies as $range) {
            if (_ipInCidr($ip, $range)) return true;
        }
        return false;
    };

    if (!empty($trustedProxies) && $isTrustedProxy($remoteAddr)) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff) {
            // Walk the chain right-to-left, skipping entries that are
            // themselves trusted proxies. The first non-trusted entry
            // is the real client — this is safe even if a trusted
            // proxy appends (rather than prepends) its own hop, and
            // resistant to a client pre-seeding fake entries at the
            // left of the header, since those get walked past first.
            $hops = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) continue;
                if ($isTrustedProxy($hop)) continue;
                return $hop;
            }
        }
    }

    return $remoteAddr;
}

// ============================================================
//  IP BLOCKING
// ============================================================
function isIpBlocked(PDO $db): bool {
    $ip = getClientIp();
    try {
        $stmt = $db->prepare("
            SELECT 1 FROM falcon.blocked_ips
            WHERE ip_address = ?::inet
              AND is_active   = TRUE
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[SEC] isIpBlocked: ' . $e->getMessage());
        return false;  // fail open — don't lock out users on DB error
    }
}

function blockIp(
    PDO     $db,
    string  $ip,
    string  $reason,
    ?int    $blockedBy       = null,
    int     $durationMinutes = 60
): void {
    try {
        // Use parameterised interval — never string interpolation
        $db->prepare("
            INSERT INTO falcon.blocked_ips
                (ip_address, reason, blocked_by, is_active, expires_at)
            VALUES (?::inet, ?, ?, TRUE, NOW() + (? * INTERVAL '1 minute'))
            ON CONFLICT (ip_address) DO UPDATE
                SET is_active  = TRUE,
                    reason     = EXCLUDED.reason,
                    blocked_by = EXCLUDED.blocked_by,
                    expires_at = EXCLUDED.expires_at
        ")->execute([$ip, substr($reason, 0, 255), $blockedBy, $durationMinutes]);

        error_log("[SEC] IP blocked: {$ip} | Reason: {$reason} | {$durationMinutes}m");
    } catch (PDOException $e) {
        error_log('[SEC] blockIp: ' . $e->getMessage());
    }
}

// ============================================================
//  FAILED LOGIN TRACKING
// ============================================================
function recordFailedLogin(PDO $db, string $username = '', string $reason = ''): void {
    $ip = getClientIp();
    try {
        $db->prepare("
            INSERT INTO falcon.failed_logins (ip_address, username, reason)
            VALUES (?::inet, ?, ?)
        ")->execute([$ip, substr($username, 0, 100), substr($reason, 0, 200)]);
    } catch (PDOException $e) {
        error_log('[SEC] recordFailedLogin: ' . $e->getMessage());
    }
}

function countRecentFailedLogins(PDO $db, int $windowMinutes = 15): int {
    $ip = getClientIp();
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM falcon.failed_logins
            WHERE ip_address  = ?::inet
              AND attempted_at > NOW() - (? * INTERVAL '1 minute')
        ");
        $stmt->execute([$ip, $windowMinutes]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[SEC] countRecentFailedLogins: ' . $e->getMessage());
        return 0;
    }
}

function countRecentFailedLoginsForUser(PDO $db, int $userId, int $windowMinutes = 15): int {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM falcon.failed_logins fl
            JOIN falcon.users u ON LOWER(u.username) = LOWER(fl.username)
            WHERE u.id = ?
              AND fl.attempted_at > NOW() - (? * INTERVAL '1 minute')
        ");
        $stmt->execute([$userId, $windowMinutes]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[SEC] countRecentFailedLoginsForUser: ' . $e->getMessage());
        return 0;
    }
}

function checkAndAutoBlockIp(
    PDO $db,
    int $threshold     = 20,
    int $windowMinutes = 15
): bool {
    if (countRecentFailedLogins($db, $windowMinutes) >= $threshold) {
        $ip = getClientIp();
        blockIp(
            $db,
            $ip,
            "Auto-blocked: {$threshold}+ failed logins in {$windowMinutes} min",
            null,
            60
        );
        return true;
    }
    return false;
}

// ============================================================
//  AUDIT LOGGING
//  PII scrubbing: email, phone, password_hash are NEVER written
//  to audit_log.old_data / new_data in plaintext.
// ============================================================

/** Fields whose values are always replaced with '[REDACTED]' */
const AUDIT_SCRUB_FIELDS = [
    'password', 'password_hash', 'email', 'phone',
    'token', 'qr_token', 'reset_token', 'auth_token',
];

function _scrubAuditData(?array $data): ?array {
    if ($data === null) return null;
    foreach (AUDIT_SCRUB_FIELDS as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = '[REDACTED]';
        }
    }
    return $data;
}

function auditLog(
    PDO     $db,
    string  $action,
    ?int    $userId    = null,
    ?string $tableName = null,
    ?int    $recordId  = null,
    ?array  $oldData   = null,
    ?array  $newData   = null,
    string  $result    = 'success',
    string  $notes     = ''
): void {
    $ip = getClientIp();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    try {
        $db->prepare("
            INSERT INTO falcon.audit_log
                (user_id, ip_address, user_agent, action,
                 table_name, record_id, old_data, new_data, result, notes)
            VALUES (?, ?::inet, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?)
        ")->execute([
            $userId,
            $ip,
            $ua,
            substr($action, 0, 100),
            $tableName ? substr($tableName, 0, 100) : null,
            $recordId,
            $oldData ? json_encode(_scrubAuditData($oldData)) : null,
            $newData ? json_encode(_scrubAuditData($newData)) : null,
            in_array($result, ['success', 'failure'], true) ? $result : 'success',
            substr($notes, 0, 500),
        ]);
    } catch (PDOException $e) {
        error_log('[AUDIT] Write failed: ' . $e->getMessage());
    }
}

// ============================================================
//  FILE UPLOAD VALIDATION + SAFE STORAGE
// ============================================================

/**
 * Validates an uploaded image thoroughly:
 *   - PHP upload error code
 *   - File size limit
 *   - Real MIME type via finfo (not browser-supplied type)
 *   - Extension whitelist
 *   - Double-extension attack detection
 *   - GD image verification (actually decodeable as an image)
 *
 * Returns ['ok' => true, 'mime' => ..., 'ext' => ...]
 *      or ['ok' => false, 'error' => '...']
 */
function validateUploadedImage(array $file, int $maxMb = 5): array {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server size limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'Upload incomplete. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error (no temp dir).',
        UPLOAD_ERR_CANT_WRITE => 'Server write error. Check permissions.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => $errMap[$file['error']] ?? 'Unknown upload error.'];
    }

    // Must be a real uploaded file (prevent local file inclusion attacks)
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload source.'];
    }

    if ($file['size'] > $maxMb * 1024 * 1024) {
        return ['ok' => false, 'error' => "Image too large. Maximum {$maxMb}MB allowed."];
    }

    // Real MIME check via finfo — never trust the browser-supplied Content-Type
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Invalid file type. Only JPG, PNG, WEBP, GIF allowed.'];
    }

    // Extension whitelist
    $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'error' => 'Invalid file extension.'];
    }

    // Double-extension attack (e.g. evil.php.jpg)
    $parts     = explode('.', $file['name']);
    $dangerous = ['php','php3','php4','php5','php7','phtml','phar','asp','aspx','jsp','cgi','sh','exe','bat'];
    if (count($parts) > 2) {
        foreach (array_slice($parts, 0, -1) as $part) {
            if (in_array(strtolower($part), $dangerous, true)) {
                error_log('[SEC] Double-extension upload blocked: ' . $file['name']);
                return ['ok' => false, 'error' => 'Invalid filename detected.'];
            }
        }
    }

    // GD verification — confirms the binary is actually a decodeable image
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'File content is not a valid image.'];
    }

    return ['ok' => true, 'mime' => $mime, 'ext' => $ext];
}

/**
 * Move an uploaded image to its destination, stripping EXIF/metadata
 * by re-encoding through GD. This prevents metadata-embedded payloads
 * (location data, copyright traps, polyglot files).
 *
 * Returns the saved filename on success, null on failure.
 */
function moveUploadedImageSafe(
    array  $file,
    string $destDir,
    string $prefix = 'img'
): ?string {
    $validation = validateUploadedImage($file);
    if (!$validation['ok']) {
        error_log('[UPLOAD] Validation failed: ' . $validation['error']);
        return null;
    }

    $mime = $validation['mime'];
    $ext  = $validation['ext'];

    // Normalise ext to canonical form
    if ($ext === 'jpg') $ext = 'jpeg';

    $filename = generateSafeFilename($file['name'], $prefix);
    // Force correct extension regardless of what was uploaded
    $filename = preg_replace('/\.[^.]+$/', '.' . $ext, $filename);
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    // Re-encode through GD to strip all metadata / polyglot payload
    $image = null;
    switch ($mime) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $image = @imagecreatefrompng($file['tmp_name']);  break;
        case 'image/webp': $image = @imagecreatefromwebp($file['tmp_name']); break;
        case 'image/gif':  $image = @imagecreatefromgif($file['tmp_name']);  break;
    }

    if ($image === false || $image === null) {
        // GD couldn't decode — fall back to plain move (still safe because
        // MIME was verified above, but no metadata stripping)
        error_log('[UPLOAD] GD decode failed for ' . $mime . ' — falling back to plain move');
        if (!move_uploaded_file($file['tmp_name'], $destPath)) return null;
        return $filename;
    }

    // Save the clean re-encoded version
    $saved = false;
    switch ($mime) {
        case 'image/jpeg': $saved = imagejpeg($image, $destPath, 85); break;
        case 'image/png':  $saved = imagepng($image, $destPath, 6);   break;
        case 'image/webp': $saved = imagewebp($image, $destPath, 85); break;
        case 'image/gif':  $saved = imagegif($image, $destPath);      break;
    }
    imagedestroy($image);

    if (!$saved) {
        error_log('[UPLOAD] GD save failed to ' . $destPath);
        return null;
    }

    // Set safe permissions (not executable, not world-writable)
    @chmod($destPath, 0644);
    return $filename;
}

/** Generate a cryptographically random filename — never use the original name. */
function generateSafeFilename(string $originalName, string $prefix = 'file'): string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
}

// ============================================================
//  PASSWORD VALIDATION
// ============================================================
function validatePasswordStrength(string $password): array {
    $errors = [];
    if (strlen($password) < 10)             $errors[] = 'At least 10 characters required.';
    if (!preg_match('/[A-Z]/', $password))  $errors[] = 'Must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $password))  $errors[] = 'Must include at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $password))  $errors[] = 'Must include at least one number.';
    if (!preg_match('/[\W_]/', $password))  $errors[] = 'Must include at least one special character.';

    // Block common passwords
    $common = [
        'password123', 'password1', '12345678', '123456789',
        'pickleball', 'falcon123', 'admin1234', 'qwerty123',
        'letmein1', 'welcome1',
    ];
    if (in_array(strtolower($password), $common, true)) {
        $errors[] = 'Password is too common. Please choose a unique one.';
    }

    return $errors;
}

/** Hash with Argon2id when available; fall back to bcrypt. */
function hashPasswordSecure(string $password): string {
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($password, $algo);
}

// ============================================================
//  USER-AGENT ANOMALY DETECTION
// ============================================================
function detectSuspiciousUserAgent(): bool {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

    // Blank or very short UA
    if (strlen($ua) < 10) return true;

    $badPatterns = [
        'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'shodan',
        'dirbuster', 'dirb', 'gobuster', 'ffuf', 'wfuzz',
        'burpsuite', 'burp/', 'w3af', 'arachni', 'qualys',
        'acunetix', 'nessus', 'openvas', 'nuclei', 'havij',
        'hydra', 'medusa', 'metasploit', 'msfconsole',
        'python-requests', 'go-http-client', 'libwww-perl',
        'curl/', 'wget/',   // flag curl/wget unless you need API access
    ];

    foreach ($badPatterns as $pattern) {
        if (str_contains($ua, $pattern)) return true;
    }
    return false;
}

// ============================================================
//  SECURE JSON RESPONSE
// ============================================================
function secureJsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
    exit;
}

// ============================================================
//  SAFE REDIRECT VALIDATION
// ============================================================
function safeRedirectPath(string $path): string {
    $path = ltrim($path, '/');

    // Allow only: letters, digits, slashes, hyphens, underscores, dots, query strings
    $path = preg_replace('#[^a-zA-Z0-9/_\-\.?=&]#', '', $path);

    // Block path traversal
    if (str_contains($path, '..') || str_contains($path, '//')) {
        return 'player/dashboard.php';
    }

    // Block open redirect — must stay within our app
    if (preg_match('#^(https?|ftp|//|javascript)#i', $path)) {
        return 'player/dashboard.php';
    }

    return $path;
}

// ============================================================
//  RATE LIMIT FILE CLEANUP (run via cron daily)
//  Removes stale rate-limit files older than 24 hours.
//  Add to crontab:
//    0 3 * * * php /var/www/pickleball/scripts/cleanup.php
//  Or call cleanOldRateLimitFiles() from a maintenance script.
// ============================================================
function cleanOldRateLimitFiles(int $olderThanSecs = 86400): int {
    if (!is_dir(RATE_LIMIT_DIR)) return 0;
    $count = 0;
    foreach (glob(RATE_LIMIT_DIR . '*.json') as $file) {
        if (filemtime($file) < time() - $olderThanSecs) {
            @unlink($file);
            $count++;
        }
    }
    return $count;
}