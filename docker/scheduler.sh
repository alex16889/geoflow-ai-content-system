#!/bin/sh
set -eu

cd /var/www/html

INTERVAL="${CRON_INTERVAL:-60}"

mkdir -p data/db data/backups logs uploads/images uploads/knowledge

echo "[scheduler] started with interval ${INTERVAL}s"

while true; do
  php /var/www/html/bin/cron.php || true
  sleep "$INTERVAL"
done

