#!/usr/bin/env bash
# ==============================================================================
# COMMUNITY HUB - AUTOMATED DATABASE BACKUP SCRIPT
# Supports both PostgreSQL (pg_dump) and MySQL (mysqldump)
# Performs GZIP compression, SHA256 checksumming, S3 offsite sync, and retention pruning.
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Source environment variables if .env.production exists
if [ -f "${APP_DIR}/.env.production" ]; then
    set -a
    source "${APP_DIR}/.env.production"
    set +a
elif [ -f "${APP_DIR}/.env" ]; then
    set -a
    source "${APP_DIR}/.env"
    set +a
fi

BACKUP_DIR="${APP_DIR}/storage/app/backups"
TIMESTAMP="$(date +"%Y%m%d_%H%M%S")"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
DB_CONNECTION="${DB_CONNECTION:-pgsql}"

mkdir -p "${BACKUP_DIR}"

echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Starting automated database backup (${DB_CONNECTION})..."

BACKUP_FILENAME=""

if [ "${DB_CONNECTION}" = "pgsql" ]; then
    BACKUP_FILENAME="db_backup_${DB_DATABASE:-community_hub}_${TIMESTAMP}.sql.gz"
    BACKUP_PATH="${BACKUP_DIR}/${BACKUP_FILENAME}"

    export PGPASSWORD="${DB_PASSWORD:-}"
    pg_dump \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME:-postgres}" \
        --dbname="${DB_DATABASE:-community_hub}" \
        --clean \
        --if-exists \
        --no-owner \
        --no-privileges | gzip -9 > "${BACKUP_PATH}"
    unset PGPASSWORD

elif [ "${DB_CONNECTION}" = "mysql" ]; then
    BACKUP_FILENAME="db_backup_${DB_DATABASE:-community_hub}_${TIMESTAMP}.sql.gz"
    BACKUP_PATH="${BACKUP_DIR}/${BACKUP_FILENAME}"

    mysqldump \
        --host="${DB_HOST:-127.0.0.1}" \
        --port="${DB_PORT:-3306}" \
        --user="${DB_USERNAME:-root}" \
        --password="${DB_PASSWORD:-}" \
        --single-transaction \
        --quick \
        --triggers \
        --routines \
        "${DB_DATABASE:-community_hub}" | gzip -9 > "${BACKUP_PATH}"
else
    echo "Unsupported DB_CONNECTION: ${DB_CONNECTION}" >&2
    exit 1
fi

# Generate SHA256 Checksum
sha256sum "${BACKUP_PATH}" > "${BACKUP_PATH}.sha256"
BACKUP_SIZE="$(du -h "${BACKUP_PATH}" | cut -f1)"

echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Backup completed: ${BACKUP_FILENAME} (${BACKUP_SIZE})"

# Optional: Upload to AWS S3 or Cloudflare R2 if configured
if [ -n "${BACKUP_S3_BUCKET:-}" ] && command -v aws >/dev/null 2>&1; then
    echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Uploading backup to S3 bucket: s3://${BACKUP_S3_BUCKET}/backups/${BACKUP_FILENAME}..."
    aws s3 cp "${BACKUP_PATH}" "s3://${BACKUP_S3_BUCKET}/backups/${BACKUP_FILENAME}" --sse AES256
    aws s3 cp "${BACKUP_PATH}.sha256" "s3://${BACKUP_S3_BUCKET}/backups/${BACKUP_FILENAME}.sha256"
fi

# Prune local backups older than RETENTION_DAYS
echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Pruning local backups older than ${RETENTION_DAYS} days..."
find "${BACKUP_DIR}" -type f -name "db_backup_*.sql.gz*" -mtime +"${RETENTION_DAYS}" -delete

echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Backup and rotation cycle finished successfully."
