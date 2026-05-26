#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
# shellcheck source=./lib.sh
. "$SCRIPT_DIR/lib.sh"

ensure_repo
acquire_lock "code"

REMOTE_NAME=${AUTO_PUSH_REMOTE:-origin}
TARGET_BRANCH=${AUTO_PUSH_BRANCH:-$(current_branch)}
FORCE_MODE=${AUTO_PUSH_FORCE_WITH_LEASE:-false}
COMMIT_MESSAGE=${1:-auto sync(code): $(date '+%Y-%m-%d %H:%M:%S %Z')}

[ "$TARGET_BRANCH" != "HEAD" ] || die "detached HEAD is not supported for autopush"
remote_exists "$REMOTE_NAME" || die "remote does not exist: $REMOTE_NAME"

log_info "fetching remote=$REMOTE_NAME"
git -C "$PROJECT_ROOT" fetch "$REMOTE_NAME"

log_info "staging repository changes"
git -C "$PROJECT_ROOT" add -A -- .

if git -C "$PROJECT_ROOT" diff --cached --quiet; then
  log_info "no staged changes detected; nothing to commit"
  exit 0
fi

PHP_FILES=$(git -C "$PROJECT_ROOT" diff --cached --name-only --diff-filter=ACMR -- '*.php')
if [ -n "$PHP_FILES" ]; then
  log_info "running php syntax checks for staged PHP files"
  printf '%s\n' "$PHP_FILES" | while IFS= read -r file; do
    [ -n "$file" ] || continue
    php -l "$PROJECT_ROOT/$file" >/dev/null
  done
fi

log_info "creating commit"
git -C "$PROJECT_ROOT" commit -m "$COMMIT_MESSAGE"

log_info "pushing HEAD to $REMOTE_NAME/$TARGET_BRANCH"
if [ "$FORCE_MODE" = "true" ]; then
  git -C "$PROJECT_ROOT" push --force-with-lease "$REMOTE_NAME" "HEAD:$TARGET_BRANCH"
else
  git -C "$PROJECT_ROOT" push "$REMOTE_NAME" "HEAD:$TARGET_BRANCH"
fi

HEAD_SHA=$(git -C "$PROJECT_ROOT" rev-parse HEAD)
write_state "last_branch.txt" "$TARGET_BRANCH"
write_state "last_commit.txt" "$HEAD_SHA"
write_state "last_push_at.txt" "$(timestamp)"
write_state "last_remote.txt" "$REMOTE_NAME"

log_info "push complete commit=$HEAD_SHA branch=$TARGET_BRANCH remote=$REMOTE_NAME"
