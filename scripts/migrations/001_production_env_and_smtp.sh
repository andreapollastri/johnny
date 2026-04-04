#!/usr/bin/env bash
# Migration 001 — Switch panel .env to production defaults and add SMTP vars.
# Runs automatically via update.sh on active servers.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
PANEL_DIR="$REPO_ROOT/panel"
ENV_FILE="$PANEL_DIR/.env"

[[ -f "$ENV_FILE" ]] || { echo "001: No panel .env found — skipping."; exit 0; }

echo "001: Updating panel .env → production defaults + SMTP variables..."

tmp="$(mktemp)"
cp "$ENV_FILE" "$tmp"

set_env_var() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$tmp"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$tmp"
  else
    echo "${key}=${val}" >> "$tmp"
  fi
}

set_env_var APP_ENV   production
set_env_var APP_DEBUG false
set_env_var LOG_LEVEL error

for var in MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_SCHEME MAIL_FROM_ADDRESS MAIL_FROM_NAME; do
  if ! grep -q "^${var}=" "$tmp"; then
    case "$var" in
      MAIL_HOST)         echo "MAIL_HOST=smtp.example.com"        >> "$tmp" ;;
      MAIL_PORT)         echo "MAIL_PORT=587"                     >> "$tmp" ;;
      MAIL_USERNAME)     echo "MAIL_USERNAME="                    >> "$tmp" ;;
      MAIL_PASSWORD)     echo "MAIL_PASSWORD="                    >> "$tmp" ;;
      MAIL_SCHEME)       echo "MAIL_SCHEME=tls"                  >> "$tmp" ;;
      MAIL_FROM_ADDRESS) echo 'MAIL_FROM_ADDRESS=noreply@example.com' >> "$tmp" ;;
      MAIL_FROM_NAME)    echo 'MAIL_FROM_NAME="${APP_NAME}"'      >> "$tmp" ;;
    esac
  fi
done

mv "$tmp" "$ENV_FILE"
chown www-data:www-data "$ENV_FILE"
chmod 600 "$ENV_FILE"

if [[ -f "$PANEL_DIR/artisan" ]]; then
  source "$(cd "$(dirname "$0")/.." && pwd)/lib/johnny-wwwdata.sh" 2>/dev/null || true
  if command -v run_wwwdata &>/dev/null; then
    run_wwwdata php "$PANEL_DIR/artisan" config:cache 2>/dev/null || true
  fi
fi

echo "001: Done. Panel is now APP_ENV=production, APP_DEBUG=false."
echo "     To enable password-reset emails, edit $ENV_FILE and set MAIL_MAILER=smtp with valid SMTP credentials."
