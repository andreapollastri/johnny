#!/usr/bin/env bash
# The install steps of the Johnny checkout as it is, without touching git: sync scripts to
# /usr/local/share/johnny, refresh panel/ if present, run pending numbered migrations from
# scripts/migrations/, re-apply the system keys on every bucket.
#
# Run by johnny-update after it checks a build out (and again on the previous build when an
# update fails), and by `sudo johnny update ensure` (or `sudo johnny update` alone).
# Updating to a newer build is `sudo johnny update apply` (see scripts/johnny-update).
#
# Usage: sudo bash scripts/update.sh [/path/to/johnny/repo]
# If no path is given, reads from /etc/johnny/repo.path (written by install.sh).
# --pull (git pull first, before 1.2.0) now hands over to johnny-update, which updates
# to the newest build of the channel instead.
set -euo pipefail

die() { echo "Error: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)."

REPO_ROOT=""
GIT_PULL=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --git-pull|--pull) GIT_PULL=1 ;;
    -*) die "Unknown option: $1" ;;
    *) REPO_ROOT="$1" ;;
  esac
  shift
done

if [[ -z "$REPO_ROOT" ]]; then
  if [[ -f /etc/johnny/repo.path ]]; then
    REPO_ROOT="$(tr -d '[:space:]' </etc/johnny/repo.path)"
  fi
fi
[[ -n "$REPO_ROOT" && -d "$REPO_ROOT" ]] || die "Repo not found. Pass the path or run install.sh first (writes /etc/johnny/repo.path)."

REPO_ROOT="$(cd "$REPO_ROOT" && pwd)"
SCRIPT_DIR="$REPO_ROOT/scripts"
export REPO_ROOT

if [[ "$GIT_PULL" == "1" ]]; then
  JOHNNY_REPO_ROOT="$REPO_ROOT" exec bash "$SCRIPT_DIR/johnny-update" --pull
fi

VER="unknown"
if [[ -f "$REPO_ROOT/VERSION" ]]; then
  VER="$(tr -d '[:space:]' <"$REPO_ROOT/VERSION")"
fi
echo "Johnny $VER — install steps"

# Git 2.35+ blocks git in repos whose owner differs from the current user (Composer may call git).
if ! git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$REPO_ROOT"; then
  git config --global --add safe.directory "$REPO_ROOT"
fi

# 1. Sync scripts, lib, config examples, VERSION to /usr/local/share/johnny
# shellcheck source=lib/sync-johnny-share.sh
source "${SCRIPT_DIR}/lib/sync-johnny-share.sh"
echo "Syncing scripts to /usr/local/share/johnny..."
sync_johnny_share

# 2. Refresh panel (composer + Laravel migrate + caches)
PANEL_DIR="$REPO_ROOT/panel"
if [[ -f "$PANEL_DIR/artisan" ]]; then
  # shellcheck source=lib/johnny-wwwdata.sh
  source "${SCRIPT_DIR}/lib/johnny-wwwdata.sh"
  if ! run_wwwdata bash -c 'cd "$PANEL_DIR" && pwd' >/dev/null 2>&1; then
    echo "Panel: not accessible as www-data — skip (clone outside /root)." >&2
  else
    export COMPOSER_ALLOW_SUPERUSER=1
    echo "Panel: composer install..."
    composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$PANEL_DIR"
    chown -R www-data:www-data "$PANEL_DIR"
    echo "Panel: artisan migrate + caches..."
    run_wwwdata php "$PANEL_DIR/artisan" migrate --force
    run_wwwdata php "$PANEL_DIR/artisan" config:cache
    run_wwwdata php "$PANEL_DIR/artisan" route:cache
    run_wwwdata php "$PANEL_DIR/artisan" view:cache
  fi
else
  echo "No panel/ — skipping Laravel."
fi

# 3. Run pending numbered migrations (scripts/migrations/NNN_*.sh)
STATE_FILE="/etc/johnny/migrations.state"
MIGRATIONS_DIR="$REPO_ROOT/scripts/migrations"
LAST=$(tr -d '[:space:]' <"$STATE_FILE" 2>/dev/null || echo "000")
[[ "$LAST" =~ ^[0-9]{3}$ ]] || LAST="000"

shopt -s nullglob
mapfile -t mig_paths < <(find "$MIGRATIONS_DIR" -maxdepth 1 -name '[0-9][0-9][0-9]_*.sh' -type f | sort -V)
shopt -u nullglob

RAN=0
for mig_path in "${mig_paths[@]}"; do
  mig_name=$(basename "$mig_path")
  mig_num="${mig_name%%_*}"
  if (( 10#$mig_num <= 10#$LAST )); then continue; fi
  echo "=== Migration $mig_name ==="
  bash "$mig_path"
  echo "$mig_num" >"$STATE_FILE"
  RAN=1
done
if (( RAN == 0 )); then
  echo "No pending migrations."
fi

# 4. Re-apply johnny-default + johnny-backup on every bucket (idempotent; includes pre-existing buckets)
ENSURE_PY="$REPO_ROOT/scripts/ensure-system-keys-on-buckets.py"
if [[ -f "$ENSURE_PY" && -f /etc/johnny/garage.toml && -x /usr/local/bin/garage ]] && command -v python3 >/dev/null; then
  echo "Ensuring system keys (johnny-default, johnny-backup) on all Garage buckets..."
  python3 "$ENSURE_PY" || echo "Warning: ensure-system-keys-on-buckets.py failed — check output above." >&2
fi

echo "Install steps finished — Johnny $VER."
