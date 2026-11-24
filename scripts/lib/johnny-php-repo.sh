#!/usr/bin/env bash
# Where the panel's PHP comes from: Ondřej Surý's repository at packages.sury.org/php.
# The ondrej/php PPA on Launchpad is being retired: it carries no packages for Ubuntu 26.04
# (resolute), and on 22.04/24.04 its Label now reads "Use https://packages.sury.org/php/
# instead", which stops `apt-get update` until the change is accepted by hand.
# Sourced by install-panel.sh, switch-php.sh and migration 011.

SURY_PHP_URL="https://packages.sury.org/php"
SURY_KEYRING_DEB="https://packages.sury.org/debsuryorg-archive-keyring.deb"
SURY_KEYRING="/usr/share/keyrings/debsuryorg-archive-keyring.gpg"
SURY_LIST="/etc/apt/sources.list.d/php.list"

# php_repo_codename — the distribution codename (jammy, noble, resolute, bookworm…).
php_repo_codename() {
  local codename=""
  if [[ -r /etc/os-release ]]; then
    # shellcheck source=/dev/null
    codename="$(. /etc/os-release && echo "${VERSION_CODENAME:-${UBUNTU_CODENAME:-}}")"
  fi
  [[ -n "$codename" ]] || codename="$(lsb_release -sc 2>/dev/null || true)"
  echo "$codename"
}

# ondrej_ppa_configured — the PPA is still somewhere in the apt sources.
ondrej_ppa_configured() {
  grep -rqsE 'ppa\.launchpad(content)?\.net/ondrej/php' /etc/apt/sources.list /etc/apt/sources.list.d/
}

# remove_ondrej_ppa — the PPA's source files, as add-apt-repository wrote them.
remove_ondrej_ppa() {
  local f
  shopt -s nullglob
  for f in /etc/apt/sources.list.d/ondrej-ubuntu-php*.list /etc/apt/sources.list.d/ondrej-ubuntu-php*.sources; do
    rm -f "$f"
    echo "php repository: removed the retired ondrej/php PPA ($f)"
  done
  shopt -u nullglob
  if ondrej_ppa_configured; then
    echo "php repository: the ondrej/php PPA is still listed in a file Johnny did not write — remove it by hand, or apt-get update may fail:" >&2
    grep -rlsE 'ppa\.launchpad(content)?\.net/ondrej/php' /etc/apt/sources.list /etc/apt/sources.list.d/ >&2 || true
  fi
}

# sury_php_configured CODENAME — packages.sury.org/php is in the apt sources for this codename.
sury_php_configured() {
  local codename="$1" f
  # One-line entries (.list): deb [options] https://packages.sury.org/php/ CODENAME main
  grep -rqsE "packages\.sury\.org/php/?[[:space:]]+${codename}([[:space:]]|$)" \
    /etc/apt/sources.list /etc/apt/sources.list.d/ && return 0
  # deb822 entries (.sources): URIs and Suites on lines of their own
  shopt -s nullglob
  for f in /etc/apt/sources.list.d/*.sources; do
    if grep -qsE '^URIs:.*packages\.sury\.org/php' "$f" && grep -qsE "^Suites:(.*[[:space:]])?${codename}([[:space:]]|$)" "$f"; then
      shopt -u nullglob
      return 0
    fi
  done
  shopt -u nullglob
  return 1
}

# ensure_php_repo — packages.sury.org/php in the apt sources (keyring included), the PPA out of
# them. The caller runs apt-get update afterwards. Returns 1, with the reason on stderr, when the
# repository cannot be set up on this server. Safe to run more than once.
ensure_php_repo() {
  local codename tmp
  codename="$(php_repo_codename)"
  if [[ -z "$codename" ]]; then
    echo "php repository: cannot tell the distribution codename (/etc/os-release)" >&2
    return 1
  fi

  remove_ondrej_ppa

  if sury_php_configured "$codename"; then
    return 0
  fi

  if ! command -v curl >/dev/null 2>&1; then
    apt-get install -y -q curl ca-certificates >/dev/null || { echo "php repository: curl could not be installed" >&2; return 1; }
  fi
  if ! curl -fsSL -o /dev/null "${SURY_PHP_URL}/dists/${codename}/InRelease"; then
    echo "php repository: ${SURY_PHP_URL} has no packages for '${codename}', or cannot be reached (it serves Ubuntu 22.04, 24.04, 26.04 and Debian 11, 12, 13)" >&2
    return 1
  fi
  if [[ ! -s "$SURY_KEYRING" ]]; then
    tmp="$(mktemp)"
    if ! { curl -fsSLo "$tmp" "$SURY_KEYRING_DEB" && dpkg -i "$tmp" >/dev/null; }; then
      rm -f "$tmp"
      echo "php repository: the packages.sury.org keyring could not be installed (${SURY_KEYRING_DEB})" >&2
      return 1
    fi
    rm -f "$tmp"
  fi
  echo "deb [signed-by=${SURY_KEYRING}] ${SURY_PHP_URL}/ ${codename} main" >"$SURY_LIST"
  chmod 0644 "$SURY_LIST"
  echo "php repository: ${SURY_PHP_URL} (${codename}) added in ${SURY_LIST}"
}
