#!/usr/bin/env bash
# Migration 002 — Deploy minimal static page for optional Caddy handling of anonymous S3 root GETs.
# Copies config/caddy-static/404.html to /var/www/johnny-static/ (idempotent).
# Caddy must still be configured separately; see config/caddy-johnny.caddy.example.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
SRC="$REPO_ROOT/config/caddy-static/404.html"
DEST_DIR="/var/www/johnny-static"
DEST="$DEST_DIR/404.html"

[[ -f "$SRC" ]] || {
  echo "002: Source missing ($SRC) — skipping."
  exit 0
}

echo "002: Installing anonymous landing page → $DEST..."
install -d -m 0755 "$DEST_DIR"
install -m 0644 "$SRC" "$DEST"

echo "002: Done."
echo "     Migration 003 updates /etc/caddy/johnny.caddy when applicable; run scripts/update.sh to apply."
