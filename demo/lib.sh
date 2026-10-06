# shellcheck shell=bash disable=SC2034  # variables are consumed by the scripts that source this file
# Shared settings for the demo scripts (sourced, not executed).
# Ports stay away from a real Johnny install (3900–3903) so both can run side by side.

STATE="${JOHNNY_DEMO_STATE:-$DEMO_DIR/.state}"
GARAGE_BIN="${GARAGE_BIN:-garage}"
S3_PORT="${JOHNNY_DEMO_S3_PORT:-13900}"
RPC_PORT="${JOHNNY_DEMO_RPC_PORT:-13901}"
ADMIN_PORT="${JOHNNY_DEMO_ADMIN_PORT:-13903}"
PANEL_PORT="${JOHNNY_DEMO_PANEL_PORT:-8099}"
S3_URL="http://127.0.0.1:$S3_PORT"
PANEL_URL="http://127.0.0.1:$PANEL_PORT"
JOHNNY="$STATE/bin/johnny"
DEMO_EMAIL="${JOHNNY_DEMO_EMAIL:-demo@johnny.test}"
DEMO_PASSWORD="${JOHNNY_DEMO_PASSWORD:-johnny-demo}"

log() { printf '\033[1;33m==>\033[0m %s\n' "$*"; }
die() { printf 'Error: %s\n' "$*" >&2; exit 1; }

is_running() {
  [[ -f "$1" ]] && kill -0 "$(cat "$1")" 2>/dev/null
}

artisan() {
  (cd "$REPO_ROOT/panel" && APP_ENV=demo php artisan "$@")
}
