#!/usr/bin/env bash
# Install PHP, Composer, Laravel panel, permissions, sudoers for `johnny key` and `johnny update` from the panel.
# Run as root. Optional second arg: APP_URL for the panel (https://panel.example.com).
# Usage: sudo bash scripts/install-panel.sh /path/to/johnny/repo [APP_URL]
set -euo pipefail

die() { echo "Error: $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "Run as root."

REPO_ROOT="${1:?Usage: $0 /path/to/johnny/repo [APP_URL]}"
APP_URL="${2:-}"

_SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PANEL_DIR="${REPO_ROOT}/panel"
[[ -f "${PANEL_DIR}/artisan" ]] || die "Laravel panel not found: $PANEL_DIR (expected panel/artisan)."

# shellcheck source=lib/johnny-wwwdata.sh
source "${_SCRIPT_DIR}/lib/johnny-wwwdata.sh"
# shellcheck source=lib/johnny-panel-cron.sh
source "${_SCRIPT_DIR}/lib/johnny-panel-cron.sh"
# shellcheck source=lib/johnny-php-repo.sh
source "${_SCRIPT_DIR}/lib/johnny-php-repo.sh"

# Never use: sudo -u www-data cmd >file  (redirect runs as root — see lib/johnny-wwwdata.sh).

# Composer and artisan run as www-data; /root is not traversable by other users (mode 0700).
if ! run_wwwdata bash -c 'cd "$PANEL_DIR" && pwd' >/dev/null 2>&1; then
  die "Panel path is not accessible as user www-data: $PANEL_DIR — clone outside /root (e.g. /opt/johnny) and pass that path to this script."
fi

export DEBIAN_FRONTEND=noninteractive
# The retired ondrej/php PPA goes first: where it is still listed, its new Label stops apt-get update.
remove_ondrej_ppa
apt-get update
apt-get install -y ca-certificates curl git unzip sqlite3
# PHP from packages.sury.org/php (the PPA has no packages for Ubuntu 26.04).
ensure_php_repo || die "the PHP repository (packages.sury.org/php) could not be set up — see the message above."
apt-get update
apt-get install -y \
  php8.6-fpm php8.6-cli php8.6-mbstring php8.6-xml php8.6-curl php8.6-zip \
  php8.6-sqlite3 php8.6-bcmath php8.6-intl
# `php` is PHP 8.6 even where an older version is installed too (cron, artisan, composer).
update-alternatives --set php /usr/bin/php8.6 >/dev/null 2>&1 || true

if ! command -v composer >/dev/null 2>&1; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
fi

install -d -m 0755 "$PANEL_DIR/bootstrap/cache"
install -d -m 0755 "$PANEL_DIR/storage" "$PANEL_DIR/storage/framework" "$PANEL_DIR/storage/framework/sessions" "$PANEL_DIR/storage/framework/views" "$PANEL_DIR/storage/framework/cache" "$PANEL_DIR/storage/logs"

# Git 2.35+ blocks git in repos whose owner differs from the current user (Composer may call git).
git config --global --add safe.directory "$REPO_ROOT"

# Run Composer as root so it can create panel/vendor on a root-owned clone; then hand off to www-data.
# Composer warns about root unless this is set (plugins/scripts still run; see getcomposer.org/root).
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$PANEL_DIR"
chown -R www-data:www-data "$PANEL_DIR"

if [[ ! -f "$PANEL_DIR/.env" ]]; then
  run_wwwdata cp "$PANEL_DIR/.env.example" "$PANEL_DIR/.env"
  run_wwwdata php "$PANEL_DIR/artisan" key:generate --force
fi

if [[ -n "$APP_URL" ]]; then
  run_wwwdata bash -c '
    tmp="$(mktemp -p "$PANEL_DIR/storage" johnny.env.XXXXXX 2>/dev/null || mktemp)"
    grep -v "^APP_URL=" "$PANEL_DIR/.env" > "$tmp" && mv "$tmp" "$PANEL_DIR/.env"
  '
  run_wwwdata env APP_URL="$APP_URL" bash -c 'printf "%s\n" "APP_URL=${APP_URL}" >> "$PANEL_DIR/.env"'
fi

if [[ -f /etc/johnny/credentials/default-s3.env ]]; then
  # shellcheck source=/dev/null
  set -a
  source /etc/johnny/credentials/default-s3.env
  set +a
  run_wwwdata env \
    AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-}" \
    AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-}" \
    AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-johnny}" \
    AWS_ENDPOINT_URL="${AWS_ENDPOINT_URL:-}" \
    bash -c '
    tmp="$(mktemp -p "$PANEL_DIR/storage" johnny.env.XXXXXX 2>/dev/null || mktemp)"
    grep -v "^GARAGE_" "$PANEL_DIR/.env" > "$tmp" && mv "$tmp" "$PANEL_DIR/.env"
    {
      echo "GARAGE_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID}"
      echo "GARAGE_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY}"
      echo "GARAGE_DEFAULT_REGION=${AWS_DEFAULT_REGION}"
      echo "GARAGE_ENDPOINT=${AWS_ENDPOINT_URL}"
    } >> "$PANEL_DIR/.env"
  '
fi

run_wwwdata php "$PANEL_DIR/artisan" migrate --force
run_wwwdata php "$PANEL_DIR/artisan" config:cache
run_wwwdata php "$PANEL_DIR/artisan" route:cache
run_wwwdata php "$PANEL_DIR/artisan" view:cache

# Garage CLI as johnny, and the update commands of the Updates page as root (exact command lines).
if [[ -f "${REPO_ROOT}/config/johnny-panel.sudoers.example" ]]; then
  visudo -cf "${REPO_ROOT}/config/johnny-panel.sudoers.example" || die "Invalid sudoers file"
  install -m 0440 "${REPO_ROOT}/config/johnny-panel.sudoers.example" /etc/sudoers.d/johnny-panel
fi

# Every minute: scheduled syncs and keys that reach all buckets (php artisan schedule:run).
install_panel_cron "$PANEL_DIR"

echo "Laravel panel installed at $PANEL_DIR"
echo "Next: configure Caddy/nginx (see config/caddy-panel.caddy.example) for $PANEL_DIR/public"
