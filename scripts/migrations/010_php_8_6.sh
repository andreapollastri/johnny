#!/usr/bin/env bash
# Migration 010 — Johnny 2.0 moves the panel to PHP 8.6 (scripts/switch-php.sh).
# Where it cannot (the packages are not there yet, an extension is missing, the panel does not
# boot on 8.6), the server stays on its current PHP and the update goes on: the panel supports
# PHP 8.5 and 8.6. Run the switch again later with: sudo bash scripts/switch-php.sh /opt/johnny 8.6
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"

status=0
bash "${REPO_ROOT}/scripts/switch-php.sh" "$REPO_ROOT" 8.6 || status=$?

if (( status == 2 )); then
  echo "010: PHP 8.6 not installed this time. Try again with: sudo bash ${REPO_ROOT}/scripts/switch-php.sh ${REPO_ROOT} 8.6"
elif (( status != 0 )); then
  echo "010: the PHP switch stopped with exit code ${status}; the panel keeps its current PHP." >&2
fi

exit 0
