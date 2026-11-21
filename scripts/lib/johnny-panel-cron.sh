#!/usr/bin/env bash
# The panel's scheduler: /etc/cron.d/johnny-panel runs `php artisan schedule:run` every minute as
# the PHP user. Scheduled syncs (Transfers) and the keys that reach all buckets depend on it.
# Sourced by install-panel.sh and by migration 009.

# install_panel_cron /path/to/panel [user]
install_panel_cron() {
  local panel_dir="$1" user="${2:-www-data}" cron_file="/etc/cron.d/johnny-panel" tmp
  tmp="$(mktemp)"
  cat >"$tmp" <<CRON
# Johnny panel scheduler: scheduled syncs (Transfers) and access keys for all buckets.
# Written by Johnny (scripts/lib/johnny-panel-cron.sh): changes are overwritten on update.
* * * * * ${user} php ${panel_dir}/artisan schedule:run >/dev/null 2>&1
CRON
  install -m 0644 "$tmp" "$cron_file"
  rm -f "$tmp"
}
