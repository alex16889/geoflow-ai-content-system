#!/bin/sh
set -eu

cd /var/www/html

INTERVAL="${CRON_INTERVAL:-60}"

mkdir -p data/db data/backups logs uploads/images uploads/knowledge

echo "[scheduler] started with interval ${INTERVAL}s"

PUBLISHER_ROOT="${PUBLISHER_SITES_ROOT:-/var/www/html/data/publisher-sites}"

while true; do
  php /var/www/html/bin/cron.php || true
  php /var/www/html/bin/publisher run --scheduled --root "$PUBLISHER_ROOT" || true
  sleep "$INTERVAL"
done

