<?php
/**
 * Reset or repair the superadmin account from the command line.
 *
 * Usage (PowerShell):
 *   $env:SUPERADMIN_PASSWORD = 'A-new-strong-password1'
 *   php scripts/reset_superadmin.php
 *   Remove-Item Env:SUPERADMIN_PASSWORD
 */

require_once __DIR__ . '/../config/app.php';

$password = getenv('SUPERADMIN_PASSWORD') ?: '';
if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
    fwrite(STDERR, "SUPERADMIN_PASSWORD must be at least 8 characters and include an uppercase letter and a number.\n");
    exit(1);
}

$db = getDB();
$hash = password_hash($password, PASSWORD_DEFAULT);
$db->beginTransaction();

try {
    $stmt = $db->prepare(
        "UPDATE falcon.users
            SET role = 'super_admin', is_active = TRUE, is_banned = FALSE,
                password_hash = ?, must_change_password = FALSE,
                is_verified = TRUE, email_verified = TRUE, updated_at = NOW()
          WHERE LOWER(username) = 'superadmin'
         RETURNING id"
    );
    $stmt->execute([$hash]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        $stmt = $db->prepare(
            "INSERT INTO falcon.users
                (username, full_name, email, password_hash, role, is_active,
                 is_banned, is_verified, email_verified, must_change_password,
                 created_at, updated_at)
             VALUES ('superadmin', 'Super Admin', 'superadmin@falconpickleball.local', ?,
                     'super_admin', TRUE, FALSE, TRUE, TRUE, FALSE, NOW(), NOW())
             RETURNING id"
        );
        $stmt->execute([$hash]);
        $id = $stmt->fetchColumn();
    }

    $db->prepare(
        "INSERT INTO falcon.wallets (user_id, balance, updated_at)
         VALUES (?, 0, NOW()) ON CONFLICT (user_id) DO NOTHING"
    )->execute([$id]);

    $db->commit();
    echo "Superadmin account repaired. Login as 'superadmin' with the supplied password.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Could not repair superadmin: {$e->getMessage()}\n");
    exit(1);
}
