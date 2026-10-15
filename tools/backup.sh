#!/usr/bin/env bash
# Backup database + folder upload. Pemakaian: tools/backup.sh <folder_tujuan> [hari_retensi]
# Kredensial: env DB_HOST/DB_USER/DB_PASS/DB_NAME (default: localhost/root/-/restaurant_ordering).
set -euo pipefail
DEST="${1:?folder tujuan wajib}"; KEEP="${2:-30}"
APP="$(cd "$(dirname "$0")/.." && pwd)"; TS="$(date +%Y%m%d-%H%M)"
DB="${DB_NAME:-restaurant_ordering}"
mkdir -p "$DEST"
MYSQL_PWD="${DB_PASS:-}" mysqldump --single-transaction --routines --triggers -h "${DB_HOST:-localhost}" -u "${DB_USER:-root}" "$DB" | gzip > "$DEST/db-$TS.sql.gz"
tar -czf "$DEST/uploads-$TS.tar.gz" -C "$APP" assets/uploads assets/images/menu
find "$DEST" -name 'db-*.sql.gz' -mtime +"$KEEP" -delete
find "$DEST" -name 'uploads-*.tar.gz' -mtime +"$KEEP" -delete
echo "$(date -Iseconds) backup OK: $DEST/db-$TS.sql.gz"
