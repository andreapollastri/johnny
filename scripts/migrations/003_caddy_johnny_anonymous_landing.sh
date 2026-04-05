#!/usr/bin/env bash
# Migration 003 — Enable anonymous landing page in /etc/caddy/johnny.caddy (Caddy → static 404.html, else Garage).
# Skips if already present, multiple site blocks, or non-standard layout.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
JOHNNY_CADDY="/etc/caddy/johnny.caddy"
SRC="$REPO_ROOT/config/caddy-static/404.html"
STATIC_DIR="/var/www/johnny-static"

[[ -f "$JOHNNY_CADDY" ]] || {
  echo "003: No $JOHNNY_CADDY — skip (Caddy not managed by Johnny import)."
  exit 0
}

if grep -q 'friendly_anon' "$JOHNNY_CADDY" 2>/dev/null; then
  echo "003: $JOHNNY_CADDY already configures friendly_anon — skip."
  exit 0
fi

if ! grep -q 'reverse_proxy 127.0.0.1:3900' "$JOHNNY_CADDY"; then
  echo "003: Garage reverse_proxy not found or custom port — skip. Merge config/caddy-johnny.caddy.example by hand."
  exit 0
fi

blocks=$(grep -cE '^[a-zA-Z0-9._-]+\s*\{' "$JOHNNY_CADDY" 2>/dev/null || echo 0)
blocks="${blocks:-0}"
if [[ "$blocks" -gt 1 ]]; then
  echo "003: Multiple site blocks in $JOHNNY_CADDY — skip auto-patch. See config/caddy-johnny.caddy.example"
  exit 0
fi

DOMAIN=$(grep -m1 -E '^[a-zA-Z0-9._-]+\s*\{' "$JOHNNY_CADDY" | sed 's/[[:space:]]*{.*$//' | tr -d '\r')
[[ -n "$DOMAIN" ]] || {
  echo "003: Could not parse site domain — skip."
  exit 0
}

[[ -f "$SRC" ]] || {
  echo "003: Missing $SRC — skip."
  exit 0
}

echo "003: Deploying static page + updating $JOHNNY_CADDY (backup: ${JOHNNY_CADDY}.bak.<timestamp>)..."
install -d -m 0755 "$STATIC_DIR"
install -m 0644 "$SRC" "$STATIC_DIR/404.html"
cp -a "$JOHNNY_CADDY" "${JOHNNY_CADDY}.bak.$(date +%s)"

cat > "$JOHNNY_CADDY" <<EOF
${DOMAIN} {
    root * ${STATIC_DIR}

    @friendly_anon {
        not header Authorization *
        not query X-Amz-Algorithm=*
        not query X-Amz-Credential=*
        not query X-Amz-Signature=*
    }
    handle @friendly_anon {
        rewrite * /404.html
        file_server
    }

    reverse_proxy 127.0.0.1:3900
}
EOF

if systemctl is-active --quiet caddy 2>/dev/null; then
  systemctl reload caddy
  echo "003: Caddy reloaded."
else
  echo "003: Caddy not active — start it when ready: sudo systemctl reload caddy"
fi

echo "003: Done."
