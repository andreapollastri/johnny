#!/usr/bin/env bash
# Wrapper for johnny-nightly-backup.py (cron-friendly).
exec /usr/bin/env python3 /usr/local/share/johnny/scripts/johnny-nightly-backup.py "$@"
