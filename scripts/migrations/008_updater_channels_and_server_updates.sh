#!/usr/bin/env bash
# Migration 008 — The 1.2.0 updater (scripts/johnny-update): release channels, the panel's
# Updates page, nightly server updates.
#   - jq, which the updater keeps its state with
#   - /etc/johnny/update.env (stable channel, automatic updates on) and /var/lib/johnny-update
#   - /etc/cron.d/johnny-nightly: `johnny update system --auto` at 02:00 and
#     `johnny update apply --auto` at 02:30, instead of `johnny update --pull`
#   - /etc/sudoers.d/johnny-panel: the panel may run the update commands as root (exact lines)
#   - a first check, so the Updates page has something to show
# Safe to run more than once.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
CRON_FILE="/etc/cron.d/johnny-nightly"
SUDOERS="/etc/sudoers.d/johnny-panel"
SUDOERS_EXAMPLE="${REPO_ROOT}/config/johnny-panel.sudoers.example"

if ! command -v jq >/dev/null; then
  echo "008: installing jq..."
  DEBIAN_FRONTEND=noninteractive apt-get update -q
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q jq
fi

install -d -m 0755 /var/lib/johnny-update

if [[ -f "$CRON_FILE" ]] && ! grep -q 'johnny update apply' "$CRON_FILE"; then
  echo "008: nightly schedule → server update at 02:00, Johnny update at 02:30 (was: johnny update --pull)"
  tmp="$(mktemp)"
  grep -v -e 'johnny update --pull' -e '^# Nightly self-update' "$CRON_FILE" >"$tmp" || true
  cat >>"$tmp" <<'CRON'
# Nightly server update: every package to its newest version (SYSTEM_UPDATE in /etc/johnny/update.env)
0 2 * * * root /usr/local/bin/johnny update system --auto >> /var/log/johnny-update.log 2>&1
# Nightly Johnny update: the newest build of the channel (AUTO_UPDATE in /etc/johnny/update.env)
30 2 * * * root /usr/local/bin/johnny update apply --auto >> /var/log/johnny-update.log 2>&1
CRON
  install -m 0644 "$tmp" "$CRON_FILE"
  rm -f "$tmp"
fi

# The panel's sudoers file, when the panel is installed: the update commands join the Garage CLI,
# for the PHP-FPM user the file already names (www-data unless it was changed by hand).
if [[ -f "$SUDOERS" && -f "$SUDOERS_EXAMPLE" ]] && ! grep -q 'johnny update status' "$SUDOERS"; then
  fpm_user="$(sed -n 's/^\([a-z_][a-z0-9_-]*\)[[:space:]]\{1,\}ALL=(johnny).*/\1/p' "$SUDOERS" | head -n1)"
  fpm_user="${fpm_user:-www-data}"
  tmp="$(mktemp)"
  sed "s/^www-data\([[:space:]]\)/${fpm_user}\1/" "$SUDOERS_EXAMPLE" >"$tmp"
  if visudo -cf "$tmp" >/dev/null; then
    install -m 0440 "$tmp" "$SUDOERS"
    echo "008: $SUDOERS → the panel may check, start and configure updates (user $fpm_user)"
  else
    echo "008: the new sudoers rules did not validate — $SUDOERS left unchanged." >&2
  fi
  rm -f "$tmp"
fi

# The first check also writes /etc/johnny/update.env with its defaults.
if [[ -x /usr/local/bin/johnny ]]; then
  /usr/local/bin/johnny update check >/dev/null 2>&1 \
    || echo "008: the first update check did not reach the repository; the nightly run checks again."
fi

echo "008: Done. Updates: sudo johnny update check | apply | channel stable|beta | auto on|off (or the panel's Updates page)."
