#!/usr/bin/env bash
# Migration 001 (v1.0.1) — BucketController: create/delete via CLI instead of S3 API.
# On a server installed with 1.0.0, BucketController used the S3 SDK to create buckets,
# but the johnny-default key has no createBucket permission in Garage.
# This migration syncs the updated code and refreshes Laravel caches.
set -euo pipefail
: "${REPO_ROOT:?}"

SCRIPT_DIR="$REPO_ROOT/scripts"
PANEL_DIR="$REPO_ROOT/panel"

# 1. Sync updated johnny CLI + scripts to /usr/local/share/johnny
# shellcheck source=../lib/sync-johnny-share.sh
source "$SCRIPT_DIR/lib/sync-johnny-share.sh"
echo "001: syncing updated scripts..."
sync_johnny_share

# 2. Refresh panel (composer + caches) if present
if [[ -f "$PANEL_DIR/artisan" ]]; then
  # shellcheck source=../lib/johnny-wwwdata.sh
  source "$SCRIPT_DIR/lib/johnny-wwwdata.sh"
  if run_wwwdata bash -c 'cd "$PANEL_DIR" && pwd' >/dev/null 2>&1; then
    git config --global --add safe.directory "$REPO_ROOT"
    export COMPOSER_ALLOW_SUPERUSER=1
    echo "001: composer install (panel)..."
    composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$PANEL_DIR"
    chown -R www-data:www-data "$PANEL_DIR"
    echo "001: artisan caches..."
    run_wwwdata php "$PANEL_DIR/artisan" config:cache
    run_wwwdata php "$PANEL_DIR/artisan" route:cache
    run_wwwdata php "$PANEL_DIR/artisan" view:cache
  else
    echo "001: panel not accessible as www-data — skip." >&2
  fi
fi

echo "001: done. BucketController now uses CLI for create/delete."
