#!/bin/sh
set -e

ROOT_DIR=/var/www
LOG_DIR="$ROOT_DIR/storage/logs"

mkdir -p "$LOG_DIR"
touch "$LOG_DIR/php-fpm.log"
chmod 664 "$LOG_DIR/php-fpm.log" 2>/dev/null || true

php "$ROOT_DIR/bin/migrate.php"

# Backfill file sizes for existing completed jobs (idempotent, safe to run multiple times)
php "$ROOT_DIR/bin/backfill_file_sizes.php"

exec "$@"
