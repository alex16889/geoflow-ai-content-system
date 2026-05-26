#!/bin/sh
set -eu

BIN_GIT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$BIN_GIT_DIR/../.." && pwd)
STATE_DIR="$BIN_GIT_DIR/state"

mkdir -p "$STATE_DIR"

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

log_info() {
  printf '[git-autopush] %s %s\n' "$(timestamp)" "$*"
}

log_error() {
  printf '[git-autopush] %s %s\n' "$(timestamp)" "$*" >&2
}

die() {
  log_error "$*"
  exit 1
}

ensure_repo() {
  git -C "$PROJECT_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "project root is not a git repository: $PROJECT_ROOT"
}

current_branch() {
  git -C "$PROJECT_ROOT" symbolic-ref --quiet --short HEAD 2>/dev/null || printf 'HEAD\n'
}

state_file() {
  printf '%s/%s\n' "$STATE_DIR" "$1"
}

write_state() {
  printf '%s\n' "$2" > "$(state_file "$1")"
}

remote_exists() {
  git -C "$PROJECT_ROOT" remote get-url "$1" >/dev/null 2>&1
}

acquire_lock() {
  LOCK_NAME=${1:-code}
  LOCK_DIR="$STATE_DIR/${LOCK_NAME}.lock"

  if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    die "another git autopush process is already running for lock=$LOCK_NAME"
  fi

  trap 'rmdir "$LOCK_DIR" >/dev/null 2>&1 || true' EXIT INT TERM HUP
}
