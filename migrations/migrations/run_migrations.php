<?php
/**
 * FILE: migrations/run_migrations.php
 *
 * One command instead of fifteen manual psql calls.
 * Applies only .sql files that AREN'T already recorded in
 * falcon.schema_migrations, in filename order, and records each
 * one immediately after it succeeds. Re-running this is always safe —
 * already-applied files are skipped automatically.
 *
 * USAGE (from the project root, in PowerShell):
 *   php migrations\run_migrations.php
 *
 * Requires the `psql` command to be on your PATH (you already have
 * this, since you've been running it manually) — this script shells
 * out to it per file so multi-statement / DO $$ ... $$ blocks run
 * exactly the same way they do when you run them by hand.
 */

// Suppress application self-healing queries while the schema is being
// bootstrapped. Those helpers assume falcon.users and other tables already
// exist, which is not true on a brand-new Railway Postgres database.
define('MIGRATION_RUNNER', true);
require_once __DIR__ . '/../../config/db.php';

// Files that should NEVER be auto-run.
$skip = [
    '2026_05_05_create_tournament_tables.sql', // MySQL syntax, unused leftover
    '000_migration_tracker.sql',               // applied manually below, first
    'seed_extra_courts_optional.sql',          // optional/manual seed, not part of the required chain
];

$pdo = getDB();

// Make sure the tracker table itself exists (idempotent).
$pdo->exec(file_get_contents(__DIR__ . '/../000_migration_tracker.sql'));

$applied = $pdo->query("SELECT filename FROM falcon.schema_migrations")
                ->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/../*.sql');
sort($files, SORT_STRING);

$pending = [];
foreach ($files as $path) {
    $name = basename($path);
    if (in_array($name, $skip, true)) continue;
    if (isset($applied[$name])) continue;
    $pending[] = $path;
}

if (empty($pending)) {
    echo "✔ Nothing to do — every migration is already applied.\n";
    exit(0);
}

echo "Found " . count($pending) . " new migration(s) to apply:\n";
foreach ($pending as $p) echo "  - " . basename($p) . "\n";
echo "\n";

$psqlArgs = sprintf(
    '-h %s -p %s -U %s -d %s',
    escapeshellarg(DB_HOST), escapeshellarg(DB_PORT),
    escapeshellarg(DB_USER), escapeshellarg(DB_NAME)
);
putenv('PGPASSWORD=' . DB_PASS);

$failures = 0;
foreach ($pending as $path) {
    $name = basename($path);
    echo "── Applying $name ──────────────────────────────\n";

    $cmd = "psql $psqlArgs -v ON_ERROR_STOP=0 -f " . escapeshellarg($path) . " 2>&1";
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    echo implode("\n", $output) . "\n";

    // Treat "GRANT to role falcon_app" style errors as harmless (matches
    // the note in GUIDE.md — that role isn't used in local dev) and don't
    // block recording the file as applied because of them.
    $realErrors = array_filter($output, function ($line) {
        return stripos($line, 'ERROR:') !== false
            && stripos($line, 'falcon_app') === false;
    });

    if (!empty($realErrors)) {
        echo "✘ $name had errors — NOT marked as applied. Fix and re-run.\n\n";
        $failures++;
        continue;
    }

    $stmt = $pdo->prepare("INSERT INTO falcon.schema_migrations (filename) VALUES (?) ON CONFLICT DO NOTHING");
    $stmt->execute([$name]);
    echo "✔ $name applied and recorded.\n\n";
}

if ($failures > 0) {
    echo "$failures migration(s) failed — see output above.\n";
    exit(1);
}

echo "All migrations applied.\n";
