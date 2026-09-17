#!/bin/bash
# ============================================================
#  FILE: scripts/backup.sh
#
#  Automated backup script for Padol Pickleball Court.
# ============================================================

set -e

BACKUP_DIR="/var/backups/falcon"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="falcon_backup_${DATE}"

echo "📦 Starting backup: ${BACKUP_NAME}"

# Create backup directory
mkdir -p "${BACKUP_DIR}"

# Database backup
echo "💾 Backing up database..."
pg_dump -h "${PGHOST}" -U "${PGUSER}" -d "${PGDATABASE}" \
    --no-owner --no-privileges --clean --if-exists \
    > "${BACKUP_DIR}/${BACKUP_NAME}.sql"

# Compress
echo "🗜️ Compressing backup..."
gzip "${BACKUP_DIR}/${BACKUP_NAME}.sql"

# Files backup (uploads, logs)
echo "📁 Backing up files..."
tar -czf "${BACKUP_DIR}/${BACKUP_NAME}_files.tar.gz" \
    uploads/ logs/ \
    --exclude='*.tmp' --exclude='cache/*'

# Clean old backups (keep last 30 days)
echo "🧹 Cleaning old backups..."
find "${BACKUP_DIR}" -name "falcon_backup_*.gz" -mtime +30 -delete

# Upload to cloud storage (optional)
if [ -n "${BACKUP_S3_BUCKET}" ]; then
    echo "☁️ Uploading to S3..."
    aws s3 cp "${BACKUP_DIR}/${BACKUP_NAME}.sql.gz" "s3://${BACKUP_S3_BUCKET}/db/"
    aws s3 cp "${BACKUP_DIR}/${BACKUP_NAME}_files.tar.gz" "s3://${BACKUP_S3_BUCKET}/files/"
fi

echo "✅ Backup complete: ${BACKUP_NAME}"