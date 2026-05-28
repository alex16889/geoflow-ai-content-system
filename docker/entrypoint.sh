#!/bin/sh
set -eu

cd /var/www/html

if [ "${REQUIRE_STRONG_APP_SECRET:-false}" = "true" ]; then
  if [ -z "${APP_SECRET_KEY:-}" ]; then
    echo "[entrypoint] APP_SECRET_KEY is required but missing" >&2
    exit 1
  fi

  if [ "${APP_SECRET_KEY}" = "your-secret-key-change-this-in-production" ]; then
    echo "[entrypoint] APP_SECRET_KEY is using the insecure default value" >&2
    exit 1
  fi
fi

mkdir -p \
  data/db \
  data/backups \
  logs \
  uploads/images \
  uploads/knowledge

touch logs/.docker-ready

if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data data logs uploads
  chmod -R u+rwX,g+rwX data logs uploads
fi

exec "$@"
