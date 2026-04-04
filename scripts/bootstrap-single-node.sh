#!/usr/bin/env bash
# One-time Garage layout for a single Johnny node (typical single-VPS install).
# Run as root after: systemctl start johnny-garage && johnny status shows a healthy node.
set -euo pipefail

die() { echo "Error: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)."

command -v sudo >/dev/null || die "sudo is required."

id -u johnny &>/dev/null || die "User 'johnny' not found. Run scripts/install.sh first."

if ! systemctl is-active --quiet johnny-garage.service 2>/dev/null; then
  die "johnny-garage.service is not active. Start it with: systemctl start johnny-garage"
fi

STATUS="$(sudo -u johnny johnny status 2>&1)" || die "johnny status failed. Is the daemon running?"

if ! echo "$STATUS" | grep -q "NO ROLE ASSIGNED"; then
  echo "Layout already assigned or node status does not show 'NO ROLE ASSIGNED'. Nothing to do."
  echo "$STATUS"
  exit 0
fi

NODE_ID="$(echo "$STATUS" | awk '/^[0-9a-f]{8,}/ {print $1; exit}')"
[[ -n "$NODE_ID" ]] || die "Could not parse node ID from 'johnny status'."

ZONE="${JOHNNY_ZONE:-dc1}"
CAPACITY="${JOHNNY_CAPACITY:-1G}"

echo "Assigning layout: zone=$ZONE capacity=$CAPACITY node=$NODE_ID"
sudo -u johnny johnny layout assign -z "$ZONE" -c "$CAPACITY" "$NODE_ID"

echo "Applying layout version 1 (first apply on a fresh cluster)."
sudo -u johnny johnny layout apply --version 1

echo "Done. Create buckets and keys next, e.g.: johnny bucket create my-bucket && johnny key create my-key"
