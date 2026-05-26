#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

cd "$PROJECT_ROOT"

FAILURES=0
WARNINGS=0

fail() {
  printf 'FAIL %s\n' "$1"
  FAILURES=$((FAILURES + 1))
}

warn() {
  printf 'WARN %s\n' "$1"
  WARNINGS=$((WARNINGS + 1))
}

is_git_repo() {
  git rev-parse --is-inside-work-tree >/dev/null 2>&1
}

print_matches() {
  if [ -n "$1" ]; then
    printf '%s\n' "$1" | sed 's/^/  - /'
  fi
}

check_tracked_path() {
  label=$1
  pattern=$2
  matches=$(git ls-files "$pattern")
  if [ -n "$matches" ]; then
    fail "$label"
    print_matches "$matches"
  fi
}

check_tracked_path_except() {
  label=$1
  pattern=$2
  exclude_regex=$3
  matches=$(git ls-files "$pattern" | grep -Ev "$exclude_regex" || true)
  if [ -n "$matches" ]; then
    fail "$label"
    print_matches "$matches"
  fi
}

check_fs_path() {
  label=$1
  path=$2
  if [ -e "$path" ]; then
    fail "$label"
    printf '  - %s\n' "$path"
  fi
}

check_fs_non_placeholder_files() {
  label=$1
  path=$2
  if [ ! -d "$path" ]; then
    return
  fi

  matches=$(find "$path" -type f ! -name '.htaccess' -print | sed 's#^\./##' || true)
  if [ -n "$matches" ]; then
    fail "$label"
    print_matches "$matches"
  fi
}

check_secret_scan() {
  if is_git_repo; then
    matches=$(
      git grep -nE '(sk-[A-Za-z0-9_-]{20,}|sk-proj-[A-Za-z0-9_-]+|ghp_[A-Za-z0-9_]{20,}|BEGIN (RSA|OPENSSH|PRIVATE) KEY|admin[0-9]{3}|DATAFORSEO_PASSWORD=[^[:space:]]+|BING_API_KEY=[^[:space:]]+|BAIDU_[A-Z_]*TOKEN=[^[:space:]]+)' -- \
        ':!node_modules/**' \
        ':!bin/git/check-open-source-release.sh' \
        ':!package-lock.json' || true
    )
  else
    matches=$(
      grep -RInE '(sk-[A-Za-z0-9_-]{20,}|sk-proj-[A-Za-z0-9_-]+|ghp_[A-Za-z0-9_]{20,}|BEGIN (RSA|OPENSSH|PRIVATE) KEY|admin[0-9]{3}|DATAFORSEO_PASSWORD=[^[:space:]]+|BING_API_KEY=[^[:space:]]+|BAIDU_[A-Z_]*TOKEN=[^[:space:]]+)' . \
        --exclude-dir=.git \
        --exclude-dir=node_modules \
        --exclude-dir=uploads \
        --exclude-dir=logs \
        --exclude-dir=output \
        --exclude=check-open-source-release.sh \
        --exclude=package-lock.json || true
    )
  fi

  if [ -n "$matches" ]; then
    fail "tracked or release files appear to contain default passwords, real API keys, or private keys"
    print_matches "$matches"
  fi
}

if is_git_repo; then
  check_tracked_path "tracked environment files must not be published" ".env"
  check_tracked_path_except "tracked environment override files must not be published" ".env.*" '^\.env\.example$'
  check_tracked_path "tracked local agent config must not be published" ".codex/**"
  check_tracked_path "tracked runtime uploads must not be published" "uploads/**"
  check_tracked_path "tracked runtime databases must not be published" "data/db/**"
  check_tracked_path_except "tracked database backups must not be published" "data/backups/**" '/\.htaccess$'
  check_tracked_path_except "tracked runtime logs must not be published" "logs/**" '/\.htaccess$'
  check_tracked_path "tracked scheduler logs must not be published" "bin/logs/**"
  check_tracked_path "tracked docs snapshot repo must not be published" "docs/git/repo/**"
  check_tracked_path "tracked local git state must not be published" "bin/git/state/**"
  check_tracked_path "tracked local docs git state must not be published" "docs/git/state/**"
  check_tracked_path "tracked login runtime state must not be published" "data/login_attempts.json"
  check_tracked_path "tracked node_modules must not be published" "node_modules/**"
  check_tracked_path "tracked browser output must not be published" "output/**"
  check_tracked_path "tracked Playwright cache must not be published" ".playwright-cli/**"
  check_tracked_path "tracked macOS junk files must not be published" ".DS_Store"
  check_tracked_path "tracked macOS junk files must not be published" "**/.DS_Store"
  check_tracked_path "tracked temporary export files must not be published" "tmp-*"
  check_tracked_path "tracked archived secret backups must not be published" "docs/archived/backup_old/**"

  warning_patterns=$(
    git ls-files \
      "admin/legacy/**" \
      "docs/archived/**" \
      "docs/backups/**" \
      "*backup*" \
      "*.bak" \
      "*-backup.php" \
      "tmp-*" || true
  )
else
  check_fs_path "environment file must not be published" ".env"
  check_fs_path "local agent config must not be published" ".codex"
  env_override_matches=$(find . -maxdepth 1 -name '.env.*' ! -name '.env.example' -print | sed 's#^\./##' || true)
  if [ -n "$env_override_matches" ]; then
    fail "environment override files must not be published"
    print_matches "$env_override_matches"
  fi
  check_fs_non_placeholder_files "runtime uploads must not be published" "uploads"
  check_fs_non_placeholder_files "runtime databases must not be published" "data/db"
  check_fs_non_placeholder_files "database backups must not be published" "data/backups"
  check_fs_non_placeholder_files "runtime logs must not be published" "logs"
  check_fs_non_placeholder_files "scheduler logs must not be published" "bin/logs"
  check_fs_path "docs snapshot repo must not be published" "docs/git/repo"
  check_fs_path "local git state must not be published" "bin/git/state"
  check_fs_path "local docs git state must not be published" "docs/git/state"
  check_fs_path "login runtime state must not be published" "data/login_attempts.json"
  check_fs_path "node_modules must not be published" "node_modules"
  check_fs_path "browser output must not be published" "output"
  check_fs_path "Playwright cache must not be published" ".playwright-cli"

  warning_patterns=$(
    find . \( -path './admin/legacy/*' -o -path './docs/archived/*' -o -path './docs/backups/*' -o -name '*backup*' -o -name '*.bak' -o -name '*-backup.php' -o -name 'tmp-*' \) -print | sed 's#^\./##' || true
  )
fi

check_secret_scan

if [ -n "$warning_patterns" ]; then
  warn "legacy/backups are present; review whether they should stay in the first open-source release"
  print_matches "$warning_patterns"
fi

if [ "$FAILURES" -gt 0 ]; then
  printf '\nOpen-source release check failed: %s blocking issue(s), %s warning(s).\n' "$FAILURES" "$WARNINGS" >&2
  exit 1
fi

printf 'Open-source release check passed with %s warning(s).\n' "$WARNINGS"
