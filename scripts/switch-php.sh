#!/usr/bin/env bash
# Move the panel to another PHP version (default 8.6), side by side with the one it runs on now.
#   sudo bash scripts/switch-php.sh [/path/to/johnny] [8.6]
#
# Installs the new php-fpm with the same extensions, carries the FPM pools over, checks that the
# panel boots on it, points Caddy at the new socket and the `php` command at the new binary, and
# stops the old php-fpm (its packages stay, to go back by hand). Every step that can fail comes
# before anything is switched: if one does, the server keeps running the old version.
# Exit codes: 0 switched or nothing to do, 2 not switched (the reason is printed). Safe to rerun.
set -euo pipefail

REPO_ROOT="${1:-${REPO_ROOT:-/opt/johnny}}"
TARGET="${2:-8.6}"
PANEL_DIR="${REPO_ROOT}/panel"

say() { echo "php ${TARGET}: $*"; }
keep() { say "$* — the panel stays on PHP ${CURRENT:-its current version}."; exit 2; }

[[ "$(id -u)" -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ "$TARGET" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "Not a PHP version: $TARGET" >&2; exit 1; }

if [[ ! -f "${PANEL_DIR}/artisan" || ! -f "${PANEL_DIR}/.env" ]]; then
  say "the panel is not installed here — nothing to switch."
  exit 0
fi

# The version the panel runs on: the php-fpm socket Caddy talks to, or the newest php-fpm installed.
CURRENT="$(grep -rhoE 'php[0-9]+\.[0-9]+-fpm\.sock' /etc/caddy 2>/dev/null | head -n1 | sed -E 's/php([0-9.]+)-fpm\.sock/\1/' || true)"
if [[ -z "$CURRENT" ]]; then
  CURRENT="$(ls -d /etc/php/*/fpm 2>/dev/null | sed -E 's#/etc/php/([0-9.]+)/fpm#\1#' | sort -V | tail -n1 || true)"
fi

if [[ "$CURRENT" == "$TARGET" ]]; then
  say "already in use."
  exit 0
fi

export DEBIAN_FRONTEND=noninteractive

# 1. The packages: the same extensions the current version has, plus what the panel needs.
if ! grep -rqs 'ondrej/php' /etc/apt/sources.list /etc/apt/sources.list.d/; then
  apt-get install -y -q software-properties-common >/dev/null || keep "software-properties-common could not be installed"
  add-apt-repository -y ppa:ondrej/php >/dev/null || keep "the ondrej/php repository could not be added"
fi
apt-get update -q >/dev/null || keep "apt-get update failed"

packages=("php${TARGET}-fpm" "php${TARGET}-cli" "php${TARGET}-mbstring" "php${TARGET}-xml" "php${TARGET}-curl" \
  "php${TARGET}-zip" "php${TARGET}-sqlite3" "php${TARGET}-bcmath" "php${TARGET}-intl")
if [[ -n "$CURRENT" ]]; then
  while read -r pkg; do
    candidate="php${TARGET}-${pkg#php"${CURRENT}"-}"
    # Only what exists for the new version (some extensions arrive later, or never).
    if apt-cache show "$candidate" >/dev/null 2>&1; then packages+=("$candidate"); fi
  done < <(dpkg-query -W -f='${binary:Package} ${Status}\n' "php${CURRENT}-*" 2>/dev/null \
    | awk '$NF == "installed" {print $1}' | grep -vE -- "-(fpm|cli|common|dev|dbg)$" || true)
fi
mapfile -t packages < <(printf '%s\n' "${packages[@]}" | sort -u)

say "installing ${packages[*]}"
apt-get install -y -q "${packages[@]}" >/dev/null || keep "the packages could not be installed"
command -v "php${TARGET}" >/dev/null || keep "php${TARGET} is not on the PATH after the install"

# 2. The FPM pools: the ones of the current version, pointed at the new socket.
if [[ -n "$CURRENT" && -d "/etc/php/${CURRENT}/fpm/pool.d" ]]; then
  for pool in "/etc/php/${CURRENT}/fpm/pool.d/"*.conf; do
    [[ -f "$pool" ]] || continue
    sed "s#php${CURRENT}#php${TARGET}#g; s#/php/${CURRENT}/#/php/${TARGET}/#g" "$pool" > "/etc/php/${TARGET}/fpm/pool.d/$(basename "$pool")"
  done
fi
"php-fpm${TARGET}" -t >/dev/null 2>&1 || keep "the FPM configuration does not validate (php-fpm${TARGET} -t)"

# 3. The panel must boot on the new version before anything points at it.
# shellcheck source=lib/johnny-wwwdata.sh
source "${REPO_ROOT}/scripts/lib/johnny-wwwdata.sh"
run_wwwdata "php${TARGET}" "${PANEL_DIR}/artisan" about --only=environment >/dev/null 2>&1 \
  || keep "the panel does not boot on PHP ${TARGET} (php${TARGET} artisan about)"

systemctl enable "php${TARGET}-fpm" >/dev/null 2>&1 || true
systemctl restart "php${TARGET}-fpm" || keep "php${TARGET}-fpm does not start"
for _ in $(seq 1 20); do [[ -S "/run/php/php${TARGET}-fpm.sock" ]] && break; sleep 0.5; done
[[ -S "/run/php/php${TARGET}-fpm.sock" ]] || keep "php${TARGET}-fpm has no socket at /run/php/php${TARGET}-fpm.sock"

# 4. Caddy: every php-fpm socket of the old version becomes the new one, validated before reload.
if [[ -n "$CURRENT" ]]; then
  mapfile -t caddy_files < <(grep -rlE "php${CURRENT//./\\.}-fpm\.sock" /etc/caddy 2>/dev/null || true)
  if (( ${#caddy_files[@]} > 0 )); then
    backup="$(mktemp -d)"
    for f in "${caddy_files[@]}"; do
      cp -p "$f" "$backup/$(echo "$f" | tr '/' '_')"
      sed -i "s#php${CURRENT}-fpm\.sock#php${TARGET}-fpm.sock#g" "$f"
    done
    if ! caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
      for f in "${caddy_files[@]}"; do cp -p "$backup/$(echo "$f" | tr '/' '_')" "$f"; done
      rm -rf "$backup"
      keep "the Caddy configuration did not validate with the new socket (put back as it was)"
    fi
    rm -rf "$backup"
    systemctl reload caddy || systemctl restart caddy
    say "Caddy now talks to php${TARGET}-fpm (${caddy_files[*]})"
  fi
fi

# 5. The `php` command (cron, artisan, composer) and the panel's caches.
update-alternatives --set php "/usr/bin/php${TARGET}" >/dev/null 2>&1 || true
run_wwwdata php "${PANEL_DIR}/artisan" config:cache >/dev/null 2>&1 || true

# 6. The old php-fpm stops; its packages stay, so going back is a matter of minutes.
if [[ -n "$CURRENT" ]] && systemctl list-unit-files "php${CURRENT}-fpm.service" >/dev/null 2>&1; then
  systemctl disable --now "php${CURRENT}-fpm" >/dev/null 2>&1 || true
fi

say "done: the panel runs on PHP $(php -r 'echo PHP_VERSION;')${CURRENT:+ (was ${CURRENT}; to remove it: apt-get purge 'php${CURRENT}-*')}."
