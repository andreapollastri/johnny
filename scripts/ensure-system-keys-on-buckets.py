#!/usr/bin/env python3
"""
One-shot: grant johnny-default (read+write+owner) and johnny-backup (read) on every bucket.
Used by scripts/migrations/006_*.sh during `johnny update`. Run as root.
"""
from __future__ import annotations

import os
import subprocess
import sys

from johnny_garage_bucket_list import parse_bucket_list_stdout

GARAGE = "/usr/local/bin/garage"
GARAGE_CFG = "/etc/johnny/garage.toml"
RUN_USER = "johnny"
DEFAULT_KEY = "johnny-default"
BACKUP_KEY = "johnny-backup"


def list_buckets() -> list[str]:
    r = subprocess.run(
        ["sudo", "-u", RUN_USER, GARAGE, "-c", GARAGE_CFG, "bucket", "list"],
        capture_output=True,
        text=True,
    )
    if r.returncode != 0:
        msg = (r.stderr or r.stdout or "").strip() or f"exit {r.returncode}"
        print(f"ensure-system-keys: garage bucket list failed: {msg}", file=sys.stderr)
        return []
    buckets = parse_bucket_list_stdout(r.stdout or "")
    if not buckets:
        sample = (r.stdout or "").strip()[:800]
        print(
            "ensure-system-keys: parsed no bucket names from `garage bucket list`.",
            file=sys.stderr,
        )
        if sample:
            print(f"ensure-system-keys: stdout sample:\n{sample}", file=sys.stderr)
    return buckets


def main() -> int:
    if os.geteuid() != 0:
        print("ensure-system-keys: run as root (sudo).", file=sys.stderr)
        return 1
    buckets = list_buckets()
    if not buckets:
        print("ensure-system-keys: no buckets (or could not list).")
        return 0
    for b in buckets:
        r1 = subprocess.run(
            [
                "sudo",
                "-u",
                RUN_USER,
                GARAGE,
                "-c",
                GARAGE_CFG,
                "bucket",
                "allow",
                "--read",
                "--write",
                "--owner",
                b,
                "--key",
                DEFAULT_KEY,
            ],
            capture_output=True,
            text=True,
        )
        if r1.returncode != 0:
            err = (r1.stderr or r1.stdout or "").strip()
            print(f"ensure-system-keys: johnny-default on '{b}': {err}", file=sys.stderr)
        r2 = subprocess.run(
            [
                "sudo",
                "-u",
                RUN_USER,
                GARAGE,
                "-c",
                GARAGE_CFG,
                "bucket",
                "allow",
                "--read",
                b,
                "--key",
                BACKUP_KEY,
            ],
            capture_output=True,
            text=True,
        )
        if r2.returncode != 0:
            err = (r2.stderr or r2.stdout or "").strip()
            print(f"ensure-system-keys: johnny-backup on '{b}': {err}", file=sys.stderr)
        print(f"ensure-system-keys: {b} — system keys applied.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
