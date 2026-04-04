#!/usr/bin/env bash
# Sync johnny.py, scripts, migrations, lib, and config examples to /usr/local/share/johnny.
# Requires: SCRIPT_DIR (repo scripts/), REPO_ROOT, optional INSTALL_PREFIX (default /usr/local).

sync_johnny_share() {
  local PREFIX="${INSTALL_PREFIX:-/usr/local}"
  local SHARE="${PREFIX}/share/johnny"
  install -m 0755 "${SCRIPT_DIR}/johnny.py" "${PREFIX}/bin/johnny"
  install -d -m 0755 "${SHARE}/scripts/migrations" "${SHARE}/scripts/lib" "${SHARE}/config"
  if [[ -f "${REPO_ROOT}/VERSION" ]]; then
    install -m 0644 "${REPO_ROOT}/VERSION" "${SHARE}/VERSION"
  fi
  local f
  for f in bootstrap-single-node.sh install-rclone.sh backup-replicate.sh replicate-run.sh autoinstall.sh install-panel.sh update.sh johnny-nightly-backup.sh johnny-nightly-backup.py; do
    if [[ -f "${SCRIPT_DIR}/${f}" ]]; then
      install -m 0755 "${SCRIPT_DIR}/${f}" "${SHARE}/scripts/${f}"
    fi
  done
  shopt -s nullglob
  for f in "${SCRIPT_DIR}/lib"/*.sh; do
    install -m 0755 "$f" "${SHARE}/scripts/lib/$(basename "$f")"
  done
  for f in "${SCRIPT_DIR}/migrations"/[0-9][0-9][0-9]_*.sh; do
    install -m 0755 "$f" "${SHARE}/scripts/migrations/$(basename "$f")"
  done
  shopt -u nullglob
  if [[ -d "${REPO_ROOT}/config" ]]; then
    shopt -s nullglob
    local examples=("${REPO_ROOT}/config/"*.example)
    shopt -u nullglob
    if ((${#examples[@]})); then
      install -m 0644 "${examples[@]}" "${SHARE}/config/"
    fi
    if [[ -d "${REPO_ROOT}/config/replication" ]]; then
      install -d -m 0755 "${SHARE}/config/replication"
      shopt -s nullglob
      local repl=("${REPO_ROOT}/config/replication/"*.example)
      shopt -u nullglob
      if ((${#repl[@]})); then
        install -m 0644 "${repl[@]}" "${SHARE}/config/replication/"
      fi
    fi
  fi
}
