#!/bin/sh
set -e

ROOT_DIR=/var/www
LOG_DIR="$ROOT_DIR/storage/logs"

mkdir -p "$LOG_DIR"

php "$ROOT_DIR/bin/migrate.php"

exec "$@"
