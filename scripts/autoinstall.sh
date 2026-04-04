#!/usr/bin/env bash
# Interactive first-time setup: Garage + layout + default S3 keys + Caddy TLS + backup.json + cron.
# Run from repo root: sudo bash scripts/autoinstall.sh
# Point DNS A/AAAA for your chosen domain to this server BEFORE running (Let's Encrypt).
set -euo pipefail

die() { echo "Error: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)."

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "=== Johnny autoinstall ==="
echo "This will install Garage, Caddy (HTTPS), rclone, and the johnny CLI."
read -r -p "Continue? [y/N] " ok
[[ "${ok,,}" == "y" ]] || exit 0

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y python3 caddy rclone openssh-client curl ca-certificates openssl

bash "${SCRIPT_DIR}/install.sh"

systemctl start johnny-garage.service
systemctl enable johnny-garage.service

bash "${SCRIPT_DIR}/bootstrap-single-node.sh"

parse_key_create() {
  local name="$1"
  local out
  out="$(sudo -u johnny johnny key create "$name" 2>&1)" || die "Failed to create key $name"
  KEY_ID="$(echo "$out" | sed -n 's/^[[:space:]]*Key ID:[[:space:]]*//p' | head -1 | tr -d '\r')"
  SECRET="$(echo "$out" | sed -n 's/^[[:space:]]*Secret key:[[:space:]]*//p' | head -1 | tr -d '\r')"
  [[ -n "$KEY_ID" && -n "$SECRET" ]] || die "Could not parse key output for $name"
}

sudo -u johnny johnny bucket create default 2>/dev/null || echo "Bucket 'default' may already exist."

install -d -m 0700 -o root -g root /etc/johnny/credentials

parse_key_create johnny-default
DEFAULT_KEY_ID="$KEY_ID"
DEFAULT_SECRET="$SECRET"

parse_key_create johnny-backup
BACKUP_KEY_ID="$KEY_ID"
BACKUP_SECRET="$SECRET"

umask 077
cat > /etc/johnny/credentials/default-s3.env <<EOF
# johnny-default — use with AWS CLI / SDK (HTTPS endpoint set after domain step)
export AWS_ACCESS_KEY_ID=${DEFAULT_KEY_ID}
export AWS_SECRET_ACCESS_KEY=${DEFAULT_SECRET}
export AWS_DEFAULT_REGION=johnny
export AWS_ENDPOINT_URL=https://PLACEHOLDER_DOMAIN
EOF

cat > /etc/johnny/credentials/backup-internal-s3.env <<EOF
# johnny-backup — used by nightly SFTP sync (local S3 only)
export JOHNNY_S3_ACCESS_KEY_ID=${BACKUP_KEY_ID}
export JOHNNY_S3_SECRET_ACCESS_KEY=${BACKUP_SECRET}
EOF
umask 022
chmod 600 /etc/johnny/credentials/default-s3.env /etc/johnny/credentials/backup-internal-s3.env

sudo -u johnny johnny bucket allow --read --write --owner default --key johnny-default
sudo -u johnny johnny bucket allow --read --key johnny-backup default

echo
echo "=== TLS / domain (Caddy + Let's Encrypt) ==="
read -r -p "Public domain for S3 API (DNS must already point here), e.g. storage.example.com: " DOMAIN
[[ -n "$DOMAIN" ]] || die "Domain is required."

sed -i.bak "s|https://PLACEHOLDER_DOMAIN|https://${DOMAIN}|" /etc/johnny/credentials/default-s3.env

if [[ -f /etc/caddy/Caddyfile ]]; then
  cp -a /etc/caddy/Caddyfile "/etc/caddy/Caddyfile.bak.$(date +%s)"
fi

cat > /etc/caddy/johnny.caddy <<EOF
${DOMAIN} {
    reverse_proxy 127.0.0.1:3900
}
EOF

if [[ ! -f /etc/caddy/Caddyfile ]]; then
  echo "import /etc/caddy/johnny.caddy" > /etc/caddy/Caddyfile
elif ! grep -q 'import /etc/caddy/johnny.caddy' /etc/caddy/Caddyfile; then
  echo "" >> /etc/caddy/Caddyfile
  echo "import /etc/caddy/johnny.caddy" >> /etc/caddy/Caddyfile
fi

systemctl enable caddy
systemctl restart caddy

if [[ ! -f /etc/johnny/backup.json ]]; then
  install -m 0600 /dev/stdin /etc/johnny/backup.json <<'JSON'
{
  "version": 1,
  "retention_days": 90,
  "remote_base_path": "johnny-backups",
  "targets": []
}
JSON
fi

CRON_FILE="/etc/cron.d/johnny-nightly"
cat > "$CRON_FILE" <<'CRON'
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
SHELL=/bin/bash
# Nightly SFTP backup + retention (primary VPS only)
0 3 * * * root /usr/local/share/johnny/scripts/johnny-nightly-backup.sh >> /var/log/johnny-nightly.log 2>&1
CRON
chmod 644 "$CRON_FILE"

touch /var/log/johnny-nightly.log
chmod 640 /var/log/johnny-nightly.log

echo
echo "=== Done ==="
echo "S3 endpoint: https://${DOMAIN}"
echo "Default app credentials: /etc/johnny/credentials/default-s3.env  (source before aws s3 ...)"
echo "Internal backup key file: /etc/johnny/credentials/backup-internal-s3.env"
echo "Manage SFTP backups: sudo johnny backup list | create | delete | update | run"
echo "Retention (days): sudo johnny backup set-retention 90"
echo "Logs: /var/log/johnny-nightly.log"
