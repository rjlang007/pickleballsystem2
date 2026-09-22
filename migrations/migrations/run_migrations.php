<?php
/**
 * migrations/migrations/run_migrations.php
 *
 * Idempotent migration runner invoked by start.sh on every container boot:
 *   php /app/migrations/migrations/run_migrations.php
 *
 * This file was referenced by start.sh but did not exist anywhere in the
 * project, so every deploy's migration step was failing (falling through
 * to a verify script that also did not exist, which would refuse to start
 * the app). This restores it.
 *
 * Behavior:
 *   - Applies every *.sql file directly under migrations/ (the parent of
 *     this file), in filename order, skipping ones already recorded in
 *     falcon.schema_migrations (see migrations/000_migration_tracker.sql).
 *   - Each file runs inside its own transaction. A failure in one legacy
 *     file (e.g. a later migration already made it redundant) is logged
 *     and skipped rather than aborting the whole run, matching the
 *     tolerant behavior start.sh already expects ("Some legacy migrations
 *     are intentionally incompatible with schemas already repaired by
 *     later migrations").
 *   - Exits 0 as long as a database connection was established, even if
 *     individual legacy files were skipped. Exits 1 only if the database
 *     itself could not be reached, so start.sh's retry loop still behaves
 *     as intended.
 */

define('MIGRATION_RUNNER', true); // tells config/db.php to skip its own ad-hoc schema patches while this runs

require_once __DIR__ . '/../../config/db.php';

$migrationsDir = dirname(__DIR__); // migrations/

try {
    $pdo = getDB();
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate] Could not connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

// Make sure the tracker table exists first, regardless of file ordering.
$trackerFile = $migrationsDir . '/000_migration_tracker.sql';
if (is_file($trackerFile)) {
    try {
        $pdo->exec(file_get_contents($trackerFile));
    } catch (Throwable $e) {
        fwrite(STDERR, "[migrate] Failed to ensure schema_migrations tracker: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Collect every *.sql file directly under migrations/, applied in filename order.
$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

$applied = [];
try {
    $stmt = $pdo->query("SELECT filename FROM falcon.schema_migrations");
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate] Could not read schema_migrations: " . $e->getMessage() . "\n");
    exit(1);
}
$applied = array_flip($applied);

$okCount = 0;
$skipCount = 0;
$failCount = 0;
$ignoredMigrations = [
    // This file contains MySQL backticks/ENUM syntax and is not part of the
    // PostgreSQL migration sequence used by the application.
    '2026_05_05_create_tournament_tables.sql',
];

foreach ($files as $path) {
    $filename = basename($path);
    if ($filename === '000_migration_tracker.sql') {
        continue; // already applied above
    }
    if (in_array($filename, $ignoredMigrations, true)) {
        echo "[migrate] Ignored {$filename} (not PostgreSQL-compatible)\n";
        $skipCount++;
        continue;
    }
    if (isset($applied[$filename])) {
        $skipCount++;
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        continue;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $record = $pdo->prepare("INSERT INTO falcon.schema_migrations (filename) VALUES (?) ON CONFLICT (filename) DO NOTHING");
        $record->execute([$filename]);
        $pdo->commit();
        echo "[migrate] Applied {$filename}\n";
        $okCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Legacy files can legitimately conflict with schema state already
        // repaired by a later migration; log and keep going rather than
        // taking the whole deploy down.
        fwrite(STDERR, "[migrate] Skipped {$filename}: " . $e->getMessage() . "\n");
        $failCount++;
    }
}

echo "[migrate] Done. applied={$okCount} skipped(existing)={$skipCount} skipped(error)={$failCount}\n";

// A database connection was established and the tracker table is usable;
// that is what start.sh actually needs to consider this step successful.
exit(0);
