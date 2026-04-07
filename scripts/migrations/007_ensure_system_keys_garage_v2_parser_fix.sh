#!/usr/bin/env bash
# Migration 007 — Re-run system key grants after fixing Garage 2.x `bucket list` parsing
# (global aliases are column 3, not the last column). Safe to run multiple times.
set -euo pipefail

REPO_ROOT="${REPO_ROOT:?REPO_ROOT must be set}"
exec bash "$REPO_ROOT/scripts/migrations/006_ensure_system_keys_on_garage_buckets.sh"
