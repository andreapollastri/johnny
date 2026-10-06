#!/usr/bin/env bash
# Stop the local demo started by demo/up.sh. --wipe also deletes demo/.state and panel/.env.demo.
set -euo pipefail

DEMO_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$DEMO_DIR/.." && pwd)"
# shellcheck source=demo/lib.sh
source "$DEMO_DIR/lib.sh"

for name in panel garage; do
  pidfile="$STATE/$name.pid"
  if is_running "$pidfile"; then
    log "Stopping $name"
    # artisan serve forks the PHP built-in server: stop the whole process group's children too.
    pkill -P "$(cat "$pidfile")" 2>/dev/null || true
    kill "$(cat "$pidfile")" 2>/dev/null || true
  fi
  rm -f "$pidfile"
done

if [[ "${1:-}" == "--wipe" ]]; then
  log "Deleting $STATE and panel/.env.demo"
  rm -rf "$STATE" "$REPO_ROOT/panel/.env.demo"
fi
