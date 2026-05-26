#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

if [ "${1:-}" = "" ]; then
  echo "Usage: sh bin/git/prepare-open-source-release.sh /absolute/path/to/public-repo" >&2
  exit 1
fi

TARGET_ROOT=$1

case "$TARGET_ROOT" in
  "$PROJECT_ROOT"|"$PROJECT_ROOT"/*)
    echo "Refusing to sync into the private source repository." >&2
    exit 1
    ;;
esac

mkdir -p "$TARGET_ROOT"

rm -rf \
  "$TARGET_ROOT/.claude" \
  "$TARGET_ROOT/.agents" \
  "$TARGET_ROOT/.codex" \
  "$TARGET_ROOT/uploads" \
  "$TARGET_ROOT/data/db" \
  "$TARGET_ROOT/data/backups" \
  "$TARGET_ROOT/logs" \
  "$TARGET_ROOT/bin/logs" \
  "$TARGET_ROOT/docs/git/repo" \
  "$TARGET_ROOT/bin/git/state" \
  "$TARGET_ROOT/docs/git/state" \
  "$TARGET_ROOT/docs/archived" \
  "$TARGET_ROOT/docs/backups" \
  "$TARGET_ROOT/admin/legacy" \
  "$TARGET_ROOT/node_modules" \
  "$TARGET_ROOT/.playwright-cli" \
  "$TARGET_ROOT/output"

find "$TARGET_ROOT" -name '.DS_Store' -delete 2>/dev/null || true
find "$TARGET_ROOT" -name '*.bak' -delete 2>/dev/null || true
find "$TARGET_ROOT" -name '*-backup.php' -delete 2>/dev/null || true
find "$TARGET_ROOT" -maxdepth 1 -name 'tmp-*' -delete 2>/dev/null || true

rsync -a --delete \
  --exclude='.git/' \
  --exclude='.claude/***' \
  --exclude='.agents/***' \
  --exclude='.codex/***' \
  --exclude='.env' \
  --include='.env.example' \
  --exclude='.env.*' \
  --exclude='.DS_Store' \
  --exclude='uploads/***' \
  --exclude='data/db/***' \
  --exclude='data/backups/***' \
  --exclude='logs/***' \
  --exclude='bin/logs/***' \
  --exclude='docs/git/repo/***' \
  --exclude='bin/git/state/***' \
  --exclude='docs/git/state/***' \
  --exclude='data/login_attempts.json' \
  --exclude='docs/archived/***' \
  --exclude='docs/backups/***' \
  --exclude='admin/legacy/***' \
  --exclude='node_modules/***' \
  --exclude='.playwright-cli/***' \
  --exclude='output/***' \
  --exclude='*.bak' \
  --exclude='*-backup.php' \
  --exclude='tmp-*' \
  "$PROJECT_ROOT/" "$TARGET_ROOT/"

echo "Open-source release workspace refreshed:"
echo "  source: $PROJECT_ROOT"
echo "  target: $TARGET_ROOT"
echo
echo "Next steps:"
echo "  1. cd \"$TARGET_ROOT\""
echo "  2. git status"
echo "  3. git add -A && git commit -m 'sync(open-source): YYYY-MM-DD release sync'"
echo "  4. git push origin main"
