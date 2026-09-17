<?php
// ============================================================
//  FILE: config/backup.php
//
//  Backup configuration settings.
// ============================================================

// Backup settings
define('BACKUP_ENABLED', getenv('BACKUP_ENABLED') ?: true);
define('BACKUP_RETENTION_DAYS', 30);
define('BACKUP_SCHEDULE', '0 2 * * *'); // Daily at 2 AM

// Storage settings
define('BACKUP_LOCAL_DIR', '/var/backups/falcon');
define('BACKUP_S3_BUCKET', getenv('BACKUP_S3_BUCKET') ?: null);
define('BACKUP_S3_REGION', getenv('BACKUP_S3_REGION') ?: 'us-east-1');

// Database settings for backup
define('BACKUP_DB_HOST', DB_HOST);
define('BACKUP_DB_PORT', DB_PORT);
define('BACKUP_DB_NAME', DB_NAME);
define('BACKUP_DB_USER', DB_USER);
define('BACKUP_DB_PASS', DB_PASS);

// Files to backup
define('BACKUP_DIRS', [
    'uploads',
    'logs',
    'storage'
]);

// Exclusions
define('BACKUP_EXCLUDE_PATTERNS', [
    '*.tmp',
    '*.log',
    'cache/*',
    'node_modules/*'
]);