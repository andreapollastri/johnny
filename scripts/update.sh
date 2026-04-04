#!/usr/bin/env bash
# Update Johnny: optional git pull, sync scripts to /usr/local/share/johnny, refresh panel/ if present,
# then run any pending numbered migrations from scripts/migrations/.
# Usage: sudo bash scripts/update.sh [/path/to/johnny/repo] [--pull]
# If no path is given, reads from /etc/johnny/repo.path (written by install.sh).
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

VER="unknown"
if [[ -f "$REPO_ROOT/VERSION" ]]; then
  VER="$(tr -d '[:space:]' <"$REPO_ROOT/VERSION")"
fi
echo "Johnny $VER — update"

if [[ "$GIT_PULL" == "1" ]]; then
  git config --global --add safe.directory "$REPO_ROOT"
  echo "Git: pulling latest..."
  git -C "$REPO_ROOT" pull --ff-only
  # Re-read version after pull
  if [[ -f "$REPO_ROOT/VERSION" ]]; then
    VER="$(tr -d '[:space:]' <"$REPO_ROOT/VERSION")"
  fi
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
    git config --global --add safe.directory "$REPO_ROOT"
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

echo "Update finished — Johnny $VER."
