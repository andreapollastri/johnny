#!/usr/bin/env bash
# Install rclone from Ubuntu repositories (for replication jobs on VPS1).
set -euo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }

apt-get update
apt-get install -y rclone ca-certificates
echo "rclone installed: $(rclone version | head -1)"
