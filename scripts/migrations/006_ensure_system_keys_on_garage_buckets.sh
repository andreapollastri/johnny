#!/usr/bin/env bash
# Migration 006 — Grant johnny-default (read+write+owner) and johnny-backup (read) on every Garage bucket.
# Runs automatically via update.sh after panel migrate; uses `garage bucket list` so all buckets are included.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
PY="${REPO_ROOT}/scripts/ensure-system-keys-on-buckets.py"

if [[ ! -f "$PY" ]]; then
  echo "006: ${PY} not found — skipping."
  exit 0
fi

if [[ ! -f /etc/johnny/garage.toml ]] || ! command -v python3 >/dev/null; then
  echo "006: Garage config or python3 missing — skipping."
  exit 0
fi

if [[ ! -x /usr/local/bin/garage ]]; then
  echo "006: /usr/local/bin/garage not found — skipping."
  exit 0
fi

echo "006: Ensuring system keys on all Garage buckets..."
python3 "$PY"
echo "006: Done."
