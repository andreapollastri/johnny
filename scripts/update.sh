#!/usr/bin/env bash
# Update Johnny from a git checkout: optional pull, sync to /usr/local/share/johnny (same as install.sh), then refresh panel/ if present.
# Future one-off migration scripts can live under scripts/migrations/ (see .gitkeep there).
# Usage: sudo bash scripts/update.sh /path/to/johnny/repo [--pull]
set -euo pipefail

die() { echo "Error: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)."

REPO_ROOT="${1:?Usage: $0 /path/to/johnny/repo [--pull]}"
shift || true
GIT_PULL=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --git-pull|--pull) GIT_PULL=1 ;;
    *) die "Unknown option: $1" ;;
  esac
  shift
done

REPO_ROOT="$(cd "$REPO_ROOT" && pwd)"
SCRIPT_DIR="$REPO_ROOT/scripts"

VER="unknown"
if [[ -f "$REPO_ROOT/VERSION" ]]; then
  VER="$(tr -d '[:space:]' <"$REPO_ROOT/VERSION")"
fi
echo "Johnny $VER — update (sync + panel refresh; install path is scripts/install.sh + install-panel.sh)."

if [[ "$GIT_PULL" == "1" ]]; then
  echo "Git: git -C $REPO_ROOT pull --ff-only"
  git -C "$REPO_ROOT" pull --ff-only
fi

# shellcheck source=lib/sync-johnny-share.sh
source "${SCRIPT_DIR}/lib/sync-johnny-share.sh"
echo "Syncing scripts and config examples to /usr/local/share/johnny..."
sync_johnny_share

PANEL_DIR="$REPO_ROOT/panel"
if [[ -f "$PANEL_DIR/artisan" ]]; then
  # shellcheck source=lib/johnny-wwwdata.sh
  source "${SCRIPT_DIR}/lib/johnny-wwwdata.sh"
  if ! run_wwwdata bash -c 'cd "$PANEL_DIR" && pwd' >/dev/null 2>&1; then
    echo "Panel: not accessible as www-data — skip Laravel update (clone repo outside /root)." >&2
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
  echo "No panel/artisan — skipping Laravel steps."
fi

# --- Run pending numbered migrations (scripts/migrations/NNN_*.sh) ---
STATE_FILE="/etc/johnny/migrations.state"
MIGRATIONS_DIR="$REPO_ROOT/scripts/migrations"
LAST=$(tr -d '[:space:]' <"$STATE_FILE" 2>/dev/null || echo "000")
[[ "$LAST" =~ ^[0-9]{3}$ ]] || LAST="000"

shopt -s nullglob
mapfile -t mig_paths < <(find "$MIGRATIONS_DIR" -maxdepth 1 -name '[0-9][0-9][0-9]_*.sh' -type f | sort -V)
shopt -u nullglob

if (( ${#mig_paths[@]} == 0 )); then
  echo "No pending migrations."
else
  for mig_path in "${mig_paths[@]}"; do
    mig_name=$(basename "$mig_path")
    mig_num="${mig_name%%_*}"
    if (( 10#$mig_num <= 10#$LAST )); then
      continue
    fi
    echo "=== Migration $mig_name ==="
    bash "$mig_path"
    echo "$mig_num" >"$STATE_FILE"
  done
fi

echo "Update finished (version $VER, last migration: $(cat "$STATE_FILE" 2>/dev/null || echo none))."
