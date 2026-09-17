<?php
// ============================================================
//  FILE: includes/verification_helpers.php
//  Shared logic for 6-digit email codes:
//    - Email verification (new registrations)
//    - Password reset (forgot password)
//  Codes are hashed at rest, short-lived, and attempt-limited.
// ============================================================

if (defined('VERIFICATION_HELPERS_LOADED')) return;
define('VERIFICATION_HELPERS_LOADED', true);

const OTP_CODE_LENGTH   = 6;
const OTP_EXPIRY_MINS   = 15;
const OTP_MAX_ATTEMPTS  = 5;

/**
 * Create a new email-verification code for a user, invalidating any
 * previous unconsumed codes, and email it to them.
 * Returns true if the email was sent successfully.
 */
function issueEmailVerificationCode(PDO $db, int $userId, string $email, string $name): bool {
    $code     = generateNumericCode(OTP_CODE_LENGTH);
    $codeHash = hash('sha256', $code);
    $expires  = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINS * 60);

    $db->prepare("DELETE FROM falcon.email_verifications WHERE user_id = ? AND consumed_at IS NULL")
       ->execute([$userId]);

    $db->prepare("
        INSERT INTO falcon.email_verifications (user_id, code_hash, expires_at, created_at)
        VALUES (?, ?, ?, NOW())
    ")->execute([$userId, $codeHash, $expires]);

    return sendVerificationCodeEmail($email, $name, $code);
}

/**
 * Check a submitted verification code for a user.
 * Returns ['ok' => bool, 'error' => string|null]
 */
function checkEmailVerificationCode(PDO $db, int $userId, string $submitted): array {
    $submitted = trim($submitted);
    if (!preg_match('/^\d{' . OTP_CODE_LENGTH . '}$/', $submitted)) {
        return ['ok' => false, 'error' => 'Enter the 6-digit code from your email.'];
    }

    $stmt = $db->prepare("
        SELECT id, code_hash, attempts, expires_at
        FROM falcon.email_verifications
        WHERE user_id = ? AND consumed_at IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'No pending code found. Please request a new one.'];
    }
    if ($row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'This code has expired. Please request a new one.'];
    }

    if (!hash_equals($row['code_hash'], hash('sha256', $submitted))) {
        $db->prepare("UPDATE falcon.email_verifications SET attempts = attempts + 1 WHERE id = ?")
           ->execute([$row['id']]);
        return ['ok' => false, 'error' => 'Incorrect code. Please try again.'];
    }

    $db->prepare("UPDATE falcon.email_verifications SET consumed_at = NOW() WHERE id = ?")
       ->execute([$row['id']]);
    // NOTE: email_verified, not is_verified — see migration 013 for why
    // these are kept as two separate flags.
    $db->prepare("UPDATE falcon.users SET email_verified = TRUE WHERE id = ?")
       ->execute([$userId]);

    return ['ok' => true, 'error' => null];
}

/**
 * Create a new password-reset code for a user and email it.
 */
function issuePasswordResetCode(PDO $db, int $userId, string $email, string $name): bool {
    $code     = generateNumericCode(OTP_CODE_LENGTH);
    $codeHash = hash('sha256', $code);
    $expires  = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINS * 60);

    $db->prepare("DELETE FROM falcon.password_reset_codes WHERE user_id = ? AND consumed_at IS NULL")
       ->execute([$userId]);

    $db->prepare("
        INSERT INTO falcon.password_reset_codes (user_id, code_hash, expires_at, created_at)
        VALUES (?, ?, ?, NOW())
    ")->execute([$userId, $codeHash, $expires]);

    return sendPasswordResetCodeEmail($email, $name, $code);
}

/**
 * Check a submitted password-reset code for a user.
 * Returns ['ok' => bool, 'error' => string|null]
 */
function checkPasswordResetCode(PDO $db, int $userId, string $submitted): array {
    $submitted = trim($submitted);
    if (!preg_match('/^\d{' . OTP_CODE_LENGTH . '}$/', $submitted)) {
        return ['ok' => false, 'error' => 'Enter the 6-digit code from your email.'];
    }

    $stmt = $db->prepare("
        SELECT id, code_hash, attempts, expires_at
        FROM falcon.password_reset_codes
        WHERE user_id = ? AND consumed_at IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'No pending code found. Please request a new one.'];
    }
    if ($row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'This code has expired. Please request a new one.'];
    }

    if (!hash_equals($row['code_hash'], hash('sha256', $submitted))) {
        $db->prepare("UPDATE falcon.password_reset_codes SET attempts = attempts + 1 WHERE id = ?")
           ->execute([$row['id']]);
        return ['ok' => false, 'error' => 'Incorrect code. Please try again.'];
    }

    // Mark consumed only after the caller has actually reset the password —
    // return the row id so the caller (reset_password.php) can consume it
    // atomically alongside the password update.
    return ['ok' => true, 'error' => null, 'reset_id' => $row['id']];
}

/** Mark a password-reset code row as consumed (call after the password update succeeds). */
function consumePasswordResetCode(PDO $db, int $resetId): void {
    $db->prepare("UPDATE falcon.password_reset_codes SET consumed_at = NOW() WHERE id = ?")
       ->execute([$resetId]);
}
