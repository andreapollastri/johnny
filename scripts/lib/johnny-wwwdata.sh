#!/usr/bin/env bash
# Helpers for commands that must run as www-data on the Laravel panel (see install-panel.sh).
# Source after REPO_ROOT is set. Sets PANEL_DIR if unset.

: "${REPO_ROOT:?REPO_ROOT must be set}"
export PANEL_DIR="${PANEL_DIR:-$REPO_ROOT/panel}"

run_wwwdata() {
  sudo -u www-data env PANEL_DIR="$PANEL_DIR" "$@"
}
