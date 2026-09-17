#!/bin/bash
# ============================================================
#  FILE: scripts/restore.sh
#
#  Restores a database backup produced by scripts/backup.sh.
#  Run this against a STAGING database first — see docs/BACKUPS.md.
#  An untested backup is not a backup; run this quarterly at least,
#  even when nothing is on fire, to confirm backups are actually
#  restorable.
#
#  Usage:
#    ./scripts/restore.sh <s3-key-for-the-.sql.gz>   # e.g. db/falcon_backup_20260819_020000.sql.gz
#    ./scripts/restore.sh --latest                    # fetch and restore the newest backup
# ============================================================
set -euo pipefail

if [ -z "${1:-}" ]; then
    echo "Usage: $0 <s3-key.sql.gz | --latest>" >&2
    exit 1
fi

if [ -z "${BACKUP_S3_BUCKET:-}" ]; then
    echo "BACKUP_S3_BUCKET is not set." >&2
    exit 1
fi

S3_ARGS=()
if [ -n "${BACKUP_S3_ENDPOINT:-}" ]; then
    S3_ARGS+=(--endpoint-url "${BACKUP_S3_ENDPOINT}")
fi
export AWS_DEFAULT_REGION="${BACKUP_S3_REGION:-us-east-1}"

if [ "$1" = "--latest" ]; then
    KEY=$(aws s3api list-objects-v2 "${S3_ARGS[@]}" --bucket "${BACKUP_S3_BUCKET}" --prefix "db/falcon_backup_" \
        --query "sort_by(Contents, &LastModified)[-1].Key" --output text)
else
    KEY="$1"
fi

if [ -z "${KEY}" ] || [ "${KEY}" = "None" ]; then
    echo "No backup found." >&2
    exit 1
fi

echo "Restoring from s3://${BACKUP_S3_BUCKET}/${KEY}"
echo "Target: ${DB_HOST}:${DB_PORT:-5432}/${DB_NAME} as ${DB_USER}"
read -r -p "This will REPLACE data in the target database. Type the database name to confirm: " CONFIRM
if [ "${CONFIRM}" != "${DB_NAME}" ]; then
    echo "Confirmation did not match DB_NAME — aborting."
    exit 1
fi

TMP=$(mktemp -d)
trap 'rm -rf "${TMP}"' EXIT

aws s3 cp "${S3_ARGS[@]}" "s3://${BACKUP_S3_BUCKET}/${KEY}" "${TMP}/backup.sql.gz"
gunzip "${TMP}/backup.sql.gz"

PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" -p "${DB_PORT:-5432}" -U "${DB_USER}" -d "${DB_NAME}" \
    -v ON_ERROR_STOP=1 -f "${TMP}/backup.sql"

echo "✅ Restore complete. Sanity-check row counts on a couple of key tables before trusting it further."
