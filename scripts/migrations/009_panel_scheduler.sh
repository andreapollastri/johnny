#!/usr/bin/env bash
# Migration 009 — The panel's scheduler, for the 2.0.0 panel:
#   - /etc/cron.d/johnny-panel: `php artisan schedule:run` every minute as the PHP user, which
#     starts scheduled syncs (Transfers) and grants the keys that reach all buckets on buckets
#     created outside the panel
# Only where the panel is installed. Safe to run more than once.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
PANEL_DIR="${REPO_ROOT}/panel"
SUDOERS="/etc/sudoers.d/johnny-panel"

# shellcheck source=../lib/johnny-panel-cron.sh
source "${REPO_ROOT}/scripts/lib/johnny-panel-cron.sh"

if [[ ! -f "${PANEL_DIR}/.env" ]]; then
  echo "009: the panel is not installed here — nothing to schedule."
  exit 0
fi

# The PHP-FPM user the panel's sudoers file names (www-data unless it was changed by hand).
fpm_user=""
if [[ -f "$SUDOERS" ]]; then
  fpm_user="$(sed -n 's/^\([a-z_][a-z0-9_-]*\)[[:space:]]\{1,\}ALL=(johnny).*/\1/p' "$SUDOERS" | head -n1)"
fi
fpm_user="${fpm_user:-www-data}"

install_panel_cron "$PANEL_DIR" "$fpm_user"

echo "009: Done. /etc/cron.d/johnny-panel runs the panel's scheduler every minute (user $fpm_user)."
