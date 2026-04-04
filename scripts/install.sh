#!/usr/bin/env bash
# Install Garage as the "Johnny" service on Ubuntu 24.04 LTS.
# From the repo root: sudo bash scripts/install.sh
# For interactive TLS + default keys + cron, use: sudo bash scripts/autoinstall.sh
set -euo pipefail

GARAGE_VERSION="${GARAGE_VERSION:-v2.2.0}"
INSTALL_PREFIX="${INSTALL_PREFIX:-/usr/local}"
CONFIG_DIR="/etc/johnny"
STATE_ROOT="/var/lib/johnny"
RUN_USER="johnny"

die() { echo "Error: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run this script as root (sudo)."

. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "Unsupported distribution (expected Ubuntu)."
[[ "${VERSION_ID:-}" == "24.04" ]] || echo "Warning: tested on Ubuntu 24.04; other releases may need tweaks."

command -v curl >/dev/null || { apt-get update && apt-get install -y curl ca-certificates openssl; }
command -v python3 >/dev/null || { apt-get update && apt-get install -y python3; }

ARCH="$(uname -m)"
case "$ARCH" in
  x86_64) GARCH="x86_64-unknown-linux-musl" ;;
  aarch64) GARCH="aarch64-unknown-linux-musl" ;;
  *) die "Unsupported architecture: $ARCH" ;;
esac

BASE_URL="https://garagehq.deuxfleurs.fr/_releases/${GARAGE_VERSION}/${GARCH}/garage"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

echo "Downloading Garage ${GARAGE_VERSION} (${GARCH})..."
curl -fsSL -o "$TMP" "$BASE_URL"
install -m 0755 "$TMP" "${INSTALL_PREFIX}/bin/garage"

getent group "$RUN_USER" >/dev/null || groupadd --system "$RUN_USER"
id -u "$RUN_USER" &>/dev/null || useradd --system --gid "$RUN_USER" --home "$STATE_ROOT" --shell /usr/sbin/nologin "$RUN_USER"

install -d -m 0750 -o root -g "$RUN_USER" "$CONFIG_DIR"
install -d -m 0750 -o "$RUN_USER" -g "$RUN_USER" "$STATE_ROOT/meta" "$STATE_ROOT/data"

if [[ ! -f "${CONFIG_DIR}/garage.toml" ]]; then
  RPC_SECRET="$(openssl rand -hex 32)"
  ADMIN_TOKEN="$(openssl rand -base64 32)"
  METRICS_TOKEN="$(openssl rand -base64 32)"
  cat > "${CONFIG_DIR}/garage.toml" <<EOF
metadata_dir = "${STATE_ROOT}/meta"
data_dir = "${STATE_ROOT}/data"
db_engine = "sqlite"
replication_factor = 1
rpc_bind_addr = "[::]:3901"
rpc_public_addr = "127.0.0.1:3901"
rpc_secret = "${RPC_SECRET}"

[s3_api]
s3_region = "johnny"
api_bind_addr = "127.0.0.1:3900"
root_domain = ".s3.localhost"

[s3_web]
bind_addr = "127.0.0.1:3902"
root_domain = ".web.localhost"
index = "index.html"

[k2v_api]
api_bind_addr = "127.0.0.1:3904"

[admin]
api_bind_addr = "127.0.0.1:3903"
admin_token = "${ADMIN_TOKEN}"
metrics_token = "${METRICS_TOKEN}"
EOF
  chmod 0640 "${CONFIG_DIR}/garage.toml"
  chown root:"$RUN_USER" "${CONFIG_DIR}/garage.toml"
  echo "Created ${CONFIG_DIR}/garage.toml — edit if needed."
else
  echo "Existing config left unchanged: ${CONFIG_DIR}/garage.toml"
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=lib/sync-johnny-share.sh
source "${SCRIPT_DIR}/lib/sync-johnny-share.sh"
sync_johnny_share

if [[ ! -f /etc/johnny/backup.json ]] && [[ -f "${REPO_ROOT}/config/backup.json.example" ]]; then
  install -m 0600 "${REPO_ROOT}/config/backup.json.example" /etc/johnny/backup.json
fi

if [[ -f "${REPO_ROOT}/systemd/johnny-garage.service" ]]; then
  install -m 0644 "${REPO_ROOT}/systemd/johnny-garage.service" /etc/systemd/system/johnny-garage.service
  systemctl daemon-reload
  systemctl enable johnny-garage.service
  echo "Service installed. Start with: systemctl start johnny-garage"
else
  echo "systemd unit not found at ${REPO_ROOT}/systemd/johnny-garage.service — install manually."
fi

echo
echo "Install finished. Next steps:"
echo "  A) Full setup (HTTPS, default keys, cron): sudo bash ${SHARE}/scripts/autoinstall.sh"
echo "  B) Manual: systemctl start johnny-garage && sudo bash ${SHARE}/scripts/bootstrap-single-node.sh"
