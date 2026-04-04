#!/usr/bin/env bash
# Push-replicate one bucket from the primary Johnny instance to another (S3-compatible API).
# Intended to run on VPS1 (main) only. Uses rclone for remote-to-remote sync.
#
# Requires: rclone (see scripts/install-rclone.sh)
# Configure remotes in ~/.config/rclone/rclone.conf or set RCLONE_CONFIG (recommended for cron:
#   export RCLONE_CONFIG=/etc/johnny/rclone-replica.conf )
#
# Example rclone remote blocks: see config/rclone.conf.example
#
# Usage:
#   export RCLONE_SRC_REMOTE=johnny-main RCLONE_SRC_BUCKET=photos
#   export RCLONE_DST_REMOTE=johnny-eu RCLONE_DST_BUCKET=photos-replica-eu
#   ./backup-replicate.sh
#
# Or: ./replicate-run.sh /etc/johnny/replication/photos-to-eu.env
#
set -euo pipefail

: "${RCLONE_SRC_REMOTE:?Set RCLONE_SRC_REMOTE (rclone remote name)}"
: "${RCLONE_SRC_BUCKET:?Set RCLONE_SRC_BUCKET}"
: "${RCLONE_DST_REMOTE:?Set RCLONE_DST_REMOTE}"
: "${RCLONE_DST_BUCKET:?Set RCLONE_DST_BUCKET}"

RCLONE_CONFIG="${RCLONE_CONFIG:-${HOME}/.config/rclone/rclone.conf}"

if ! command -v rclone >/dev/null; then
  echo "rclone not found. Install with: sudo apt-get install -y rclone" >&2
  exit 1
fi

[[ -f "$RCLONE_CONFIG" ]] || { echo "Missing rclone config: $RCLONE_CONFIG" >&2; exit 1; }

# sync: makes destination match source (may delete extra objects on destination)
rclone sync "${RCLONE_SRC_REMOTE}:${RCLONE_SRC_BUCKET}" "${RCLONE_DST_REMOTE}:${RCLONE_DST_BUCKET}" \
  --config "$RCLONE_CONFIG" \
  --progress \
  --stats 1s
