<?php
// config/session.php — DB-backed sessions for Railway

class DBSessionHandler implements SessionHandlerInterface {
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function open(string $path, string $name): bool {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS falcon.php_sessions (
                id         VARCHAR(128) PRIMARY KEY,
                data       TEXT,
                user_id    INTEGER,
                updated_at TIMESTAMPTZ DEFAULT NOW()
            )
        ");

        // CREATE TABLE IF NOT EXISTS above only fires when the table is
        // missing entirely — it can't add columns to a table that already
        // exists from an older version of this schema. Patch it here so
        // upgrades from pre-user_id deployments don't break write().
        $this->db->exec("
            ALTER TABLE falcon.php_sessions
                ADD COLUMN IF NOT EXISTS user_id INTEGER
        ");
        $this->db->exec("
            ALTER TABLE falcon.php_sessions
                ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT NOW()
        ");

        return true;
    }

    public function close(): bool { return true; }

    public function read(string $id): string|false {
        $s = $this->db->prepare(
            "SELECT data FROM falcon.php_sessions 
             WHERE id = :id 
             AND updated_at > NOW() - INTERVAL '2 hours'"
        );
        $s->execute([':id' => $id]);
        return $s->fetchColumn() ?: '';
    }

    public function write(string $id, string $data): bool {
        // Track which user a session belongs to (best-effort) so we can
        // force-logout all of a user's sessions on password reset. $_SESSION
        // is populated by the time PHP calls write(), even though $data is
        // the raw serialized string.
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $this->db->prepare(
            "INSERT INTO falcon.php_sessions (id, data, user_id, updated_at)
             VALUES (:id, :data, :user_id, NOW())
             ON CONFLICT (id) DO UPDATE
             SET data = EXCLUDED.data, user_id = EXCLUDED.user_id, updated_at = NOW()"
        )->execute([':id' => $id, ':data' => $data, ':user_id' => $userId]);
        return true;
    }

    public function destroy(string $id): bool {
        $this->db->prepare(
            "DELETE FROM falcon.php_sessions WHERE id = :id"
        )->execute([':id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        $s = $this->db->prepare(
            "DELETE FROM falcon.php_sessions
             WHERE updated_at < NOW() - INTERVAL '2 hours'
             RETURNING id"
        );
        $s->execute();
        return $s->rowCount();
    }
}

function startDBSession(PDO $db): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $handler = new DBSessionHandler($db);
    session_set_save_handler($handler, true);

    session_name('FALCON_SESS');

    $secureCookie = false;
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $secureCookie = true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $secureCookie = true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
        $secureCookie = true;
    }
    if (!$secureCookie && defined('APP_URL') && str_starts_with(APP_URL, 'https://')) {
        $secureCookie = true;
    }

    session_set_cookie_params([
        'lifetime' => 7200,
        'path'     => '/',
        'secure'   => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}