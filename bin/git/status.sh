#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
# shellcheck source=./lib.sh
. "$SCRIPT_DIR/lib.sh"

ensure_repo

TARGET_BRANCH=${AUTO_PUSH_BRANCH:-$(current_branch)}
REMOTE_NAME=${AUTO_PUSH_REMOTE:-origin}
FORCE_MODE=${AUTO_PUSH_FORCE_WITH_LEASE:-false}

echo "project_root=$PROJECT_ROOT"
echo "current_branch=$(current_branch)"
echo "target_branch=$TARGET_BRANCH"
echo "remote=$REMOTE_NAME"
echo "force_with_lease=$FORCE_MODE"

LAST_PUSH_AT="never"
LAST_COMMIT="never"
LAST_REMOTE="unknown"
LAST_BRANCH="unknown"

[ -f "$(state_file last_push_at.txt)" ] && LAST_PUSH_AT=$(cat "$(state_file last_push_at.txt)")
[ -f "$(state_file last_commit.txt)" ] && LAST_COMMIT=$(cat "$(state_file last_commit.txt)")
[ -f "$(state_file last_remote.txt)" ] && LAST_REMOTE=$(cat "$(state_file last_remote.txt)")
[ -f "$(state_file last_branch.txt)" ] && LAST_BRANCH=$(cat "$(state_file last_branch.txt)")

echo "last_push_at=$LAST_PUSH_AT"
echo "last_commit=$LAST_COMMIT"
echo "last_remote=$LAST_REMOTE"
echo "last_branch=$LAST_BRANCH"

echo "git_status:"
git -C "$PROJECT_ROOT" status --short
