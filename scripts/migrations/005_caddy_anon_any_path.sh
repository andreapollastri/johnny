#!/usr/bin/env bash
# Migration 005 — Anonymous landing page for any path (not only GET /).
# Replaces /etc/caddy/johnny.caddy when it still has "path /" inside @friendly_anon.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
JOHNNY_CADDY="/etc/caddy/johnny.caddy"
STATIC_DIR="/var/www/johnny-static"

[[ -f "$JOHNNY_CADDY" ]] || {
  echo "005: No $JOHNNY_CADDY — skip."
  exit 0
}

if ! grep -q 'friendly_anon' "$JOHNNY_CADDY" 2>/dev/null; then
  echo "005: No friendly_anon in $JOHNNY_CADDY — skip."
  exit 0
fi

if ! grep -qE '^[[:space:]]*path /[[:space:]]*$' "$JOHNNY_CADDY"; then
  echo "005: Anonymous matcher already applies to any path — skip."
  exit 0
fi

if ! grep -q 'reverse_proxy 127.0.0.1:3900' "$JOHNNY_CADDY"; then
  echo "005: Garage reverse_proxy not found or custom port — skip. Edit $JOHNNY_CADDY by hand (see config/caddy-johnny.caddy.example)."
  exit 0
fi

blocks=$(grep -cE '^[a-zA-Z0-9._-]+\s*\{' "$JOHNNY_CADDY" 2>/dev/null || echo 0)
blocks="${blocks:-0}"
if [[ "$blocks" -gt 1 ]]; then
  echo "005: Multiple site blocks — skip auto-patch. Remove \`path /\` from @friendly_anon manually."
  exit 0
fi

DOMAIN=$(grep -m1 -E '^[a-zA-Z0-9._-]+\s*\{' "$JOHNNY_CADDY" | sed 's/[[:space:]]*{.*$//' | tr -d '\r')
[[ -n "$DOMAIN" ]] || {
  echo "005: Could not parse site domain — skip."
  exit 0
}

echo "005: Updating $JOHNNY_CADDY (anonymous HTML for all paths, backup: ${JOHNNY_CADDY}.bak.<timestamp>)..."
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
  echo "005: Caddy reloaded."
else
  echo "005: Caddy not active — reload when ready: sudo systemctl reload caddy"
fi

echo "005: Done."
