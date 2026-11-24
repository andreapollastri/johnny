#!/usr/bin/env bash
# Migration 011 — PHP comes from packages.sury.org/php, no longer from the ondrej/php PPA.
# The PPA is being retired: it has no packages for Ubuntu 26.04, so migration 010 could not
# install PHP 8.6 there, and on 22.04/24.04 its new Label stops `apt-get update` — the nightly
# server update included. Where the panel is installed, the PHP 8.6 switch of migration 010 is
# tried again from the new repository (nothing to do where it already runs on 8.6).
# Never stops the update: on any problem the server keeps what it has. Safe to run more than once.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
PANEL_DIR="${REPO_ROOT}/panel"

# shellcheck source=../lib/johnny-php-repo.sh
source "${REPO_ROOT}/scripts/lib/johnny-php-repo.sh"

export DEBIAN_FRONTEND=noninteractive

panel=0
[[ -f "${PANEL_DIR}/artisan" && -f "${PANEL_DIR}/.env" ]] && panel=1

if (( panel == 0 )) && ! ondrej_ppa_configured; then
  echo "011: no panel and no ondrej/php PPA here — nothing to move."
  exit 0
fi

if ! ensure_php_repo; then
  echo "011: the PHP repository could not be moved to packages.sury.org this time; run the update again later." >&2
  exit 0
fi

if (( panel == 1 )); then
  status=0
  bash "${REPO_ROOT}/scripts/switch-php.sh" "$REPO_ROOT" 8.6 || status=$?
  if (( status == 2 )); then
    echo "011: PHP 8.6 not installed this time. Try again with: sudo bash ${REPO_ROOT}/scripts/switch-php.sh ${REPO_ROOT} 8.6"
  elif (( status != 0 )); then
    echo "011: the PHP switch stopped with exit code ${status}; the panel keeps its current PHP." >&2
  fi
fi

echo "011: Done. PHP packages come from packages.sury.org/php."
exit 0
