#!/usr/bin/env bash
# Load a replication env file and run backup-replicate.sh (for cron on VPS1).
# Usage: replicate-run.sh /etc/johnny/replication/media-to-eu.env
set -euo pipefail

ENV_FILE="${1:?Usage: $0 /path/to/envfile}"

[[ -f "$ENV_FILE" ]] || { echo "Env file not found: $ENV_FILE" >&2; exit 1; }

# shellcheck source=/dev/null
set -a
source "$ENV_FILE"
set +a

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec "${SCRIPT_DIR}/backup-replicate.sh"
