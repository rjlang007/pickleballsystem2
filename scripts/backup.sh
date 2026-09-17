#!/bin/bash
# ============================================================
#  FILE: scripts/backup.sh
#
#  Backs up the database and uploaded files to S3-compatible
#  storage. Meant to run as a Railway Cron Job — see docs/BACKUPS.md
#  for how to wire that up.
#
#  This script refuses to run without durable off-box storage
#  configured. Railway's disk is ephemeral: anything written only to
#  local disk is gone on the next redeploy/restart, so a "backup"
#  that never leaves the box isn't disaster recovery — it's a false
#  sense of security. The previous version of this script made S3
#  upload optional and would happily "succeed" while writing only to
#  local disk that nothing was actually reading from afterward.
# ============================================================
set -uo pipefail  # NOT -e: we want to reach the alert step on failure

DATE=$(date -u +%Y%m%d_%H%M%S)
BACKUP_NAME="falcon_backup_${DATE}"
WORK_DIR=$(mktemp -d)
LOG_PREFIX="[backup ${DATE}]"

alert() {
    local msg="$1"
    echo "${LOG_PREFIX} ${msg}" >&2
    if [ -n "${ALERT_WEBHOOK_URL:-}" ]; then
        curl -fsS -m 10 -X POST -H 'Content-Type: application/json' \
            -d "{\"text\": \"🔴 Falcon backup FAILED: ${msg}\"}" \
            "${ALERT_WEBHOOK_URL}" > /dev/null 2>&1 || true
    fi
}

cleanup() { rm -rf "${WORK_DIR}"; }
trap cleanup EXIT

# ── Preconditions ────────────────────────────────────────────
if [ "${BACKUP_ENABLED:-true}" != "true" ]; then
    echo "${LOG_PREFIX} BACKUP_ENABLED is not 'true' — skipping (not a failure)."
    exit 0
fi

if [ -z "${BACKUP_S3_BUCKET:-}" ] || [ -z "${AWS_ACCESS_KEY_ID:-}" ] || [ -z "${AWS_SECRET_ACCESS_KEY:-}" ]; then
    alert "BACKUP_S3_BUCKET / AWS credentials not set. Refusing to run a local-only backup — set these in your Railway env vars. See docs/BACKUPS.md."
    exit 1
fi

if ! command -v aws >/dev/null 2>&1; then
    alert "aws CLI not found in this image. Add it to the Dockerfile (apk add aws-cli)."
    exit 1
fi

S3_ARGS=()
if [ -n "${BACKUP_S3_ENDPOINT:-}" ]; then
    S3_ARGS+=(--endpoint-url "${BACKUP_S3_ENDPOINT}")  # for R2/B2/MinIO etc.
fi
export AWS_DEFAULT_REGION="${BACKUP_S3_REGION:-us-east-1}"

echo "${LOG_PREFIX} Starting backup: ${BACKUP_NAME}"

# ── Database dump ────────────────────────────────────────────
echo "${LOG_PREFIX} Dumping database..."
if ! pg_dump -h "${DB_HOST}" -p "${DB_PORT:-5432}" -U "${DB_USER}" -d "${DB_NAME}" \
        --no-owner --no-privileges --clean --if-exists \
        > "${WORK_DIR}/${BACKUP_NAME}.sql" 2>"${WORK_DIR}/pg_dump.err"; then
    alert "pg_dump failed: $(tail -c 500 "${WORK_DIR}/pg_dump.err")"
    exit 1
fi
gzip "${WORK_DIR}/${BACKUP_NAME}.sql"

# ── Files (uploads, logs) ────────────────────────────────────
echo "${LOG_PREFIX} Archiving files..."
tar -czf "${WORK_DIR}/${BACKUP_NAME}_files.tar.gz" \
    --exclude='*.tmp' --exclude='cache/*' \
    uploads/ storage/logs/ 2>"${WORK_DIR}/tar.err" || {
        # Missing dirs shouldn't kill the whole backup — the DB dump matters most.
        echo "${LOG_PREFIX} WARNING: file archive had issues: $(tail -c 300 "${WORK_DIR}/tar.err")"
    }

# ── Upload (required) ────────────────────────────────────────
echo "${LOG_PREFIX} Uploading to s3://${BACKUP_S3_BUCKET}..."
UPLOAD_OK=true
aws s3 cp "${S3_ARGS[@]}" "${WORK_DIR}/${BACKUP_NAME}.sql.gz" \
    "s3://${BACKUP_S3_BUCKET}/db/${BACKUP_NAME}.sql.gz" || UPLOAD_OK=false
if [ -f "${WORK_DIR}/${BACKUP_NAME}_files.tar.gz" ]; then
    aws s3 cp "${S3_ARGS[@]}" "${WORK_DIR}/${BACKUP_NAME}_files.tar.gz" \
        "s3://${BACKUP_S3_BUCKET}/files/${BACKUP_NAME}_files.tar.gz" || UPLOAD_OK=false
fi

if [ "${UPLOAD_OK}" != "true" ]; then
    alert "Backup taken locally but S3 upload failed — nothing durable was saved this run."
    exit 1
fi

# ── Remote retention ─────────────────────────────────────────
echo "${LOG_PREFIX} Pruning backups older than ${BACKUP_RETENTION_DAYS:-30} days..."
CUTOFF_ISO=$(date -u -d "-${BACKUP_RETENTION_DAYS:-30} days" --iso-8601=seconds 2>/dev/null \
    || date -u -v-"${BACKUP_RETENTION_DAYS:-30}"d +"%Y-%m-%dT%H:%M:%SZ")
aws s3api list-objects-v2 "${S3_ARGS[@]}" --bucket "${BACKUP_S3_BUCKET}" --prefix "db/falcon_backup_" \
    --query "Contents[?LastModified<='${CUTOFF_ISO}'].Key" \
    --output text 2>/dev/null | tr '\t' '\n' | while read -r key; do
        [ -n "$key" ] && aws s3 rm "${S3_ARGS[@]}" "s3://${BACKUP_S3_BUCKET}/${key}" 2>/dev/null
    done

echo "${LOG_PREFIX} ✅ Backup complete and uploaded: ${BACKUP_NAME}"
